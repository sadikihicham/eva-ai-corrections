<?php
declare(strict_types=1);
namespace OCA\EvaAi\Service;

use OCP\Http\Client\IClientService;

/** Adapter for any provider exposing the OpenAI chat-completions contract. */
class OpenAICompatible {
    public function __construct(private AppConfig $config, private IClientService $clients, private ProviderCredentials $credentials, private ?UsageMetrics $usage = null) {}
    private function provider(): string { return (string)$this->config->get('chat_provider'); }
    private function profile(): array {
        $profile = $this->config->providerProfile($this->provider());
        return is_array($profile) ? $profile : [];
    }
    private function baseUrl(): string {
        $profile = $this->profile();
        return rtrim((string)($profile['url'] ?? $this->config->get('custom_provider_url')), '/');
    }
    private function model(): string {
        $profile = $this->profile();
        return trim((string)($profile['model'] ?? $this->config->get('custom_provider_model')));
    }
    private function endpoint(): string { return $this->baseUrl() . '/chat/completions'; }
    public function check(): array {
        try {
            $base = $this->baseUrl();
            $response = $this->clients->newClient()->get($base . '/models', ['headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $this->provider())], 'timeout' => 15, 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['ok' => false, 'error' => 'Provider returned HTTP ' . $status . '.'];
            $data = json_decode((string)$response->getBody(), true);
            $models = array_values(array_filter(array_map(static fn($row) => (string)($row['id'] ?? ''), is_array($data['data'] ?? null) ? $data['data'] : [])));
            return ['ok' => true, 'models' => $models, 'model' => $this->model()];
        } catch (\Throwable $e) { return ['ok' => false, 'models' => [], 'error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection failed. Check endpoint and key.']; }
    }
    public function chat(array $messages, array $tools = [], int $timeout = 120): array {
        $id = $this->provider();
        try {
            $payload = ['model' => $this->model(), 'messages' => $this->normalizeMessages($messages), 'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))), 'stream' => false];
            if ($tools !== []) $payload['tools'] = $tools;
            $response = $this->clients->newClient()->post($this->endpoint(), ['headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $id), 'Content-Type' => 'application/json'], 'json' => $payload, 'timeout' => max(1, min(300, $timeout)), 'http_errors' => false]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) return ['error' => 'Provider request failed (HTTP ' . $status . ').'];
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $message = $data['choices'][0]['message'] ?? [];
            $calls = [];
            foreach (($message['tool_calls'] ?? []) as $call) { $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true); if (is_array($args)) $calls[] = ['name' => (string)($call['function']['name'] ?? ''), 'arguments' => $args]; }
            $answer = (string)($message['content'] ?? '');
            $this->usage?->recordChat($this->config->userId(), $id, $this->model(), $messages, $answer, null, null, 0);
            return ['answer' => $answer, 'model' => $this->model(), 'tool_calls' => $calls, 'raw_tool_calls' => $message['tool_calls'] ?? []];
        } catch (\Throwable $e) { return ['error' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.']; }
    }

    /**
     * Streaming variant of chat() : POST avec stream:true, lit le corps HTTP au fur et à mesure
     * (mêmes 8192 octets/lecture + read_timeout que Ollama::chatStream, voir ce fichier) et parse les
     * lignes SSE `data: {...}` du serveur amont (vLLM/LiteLLM/OpenAI), jusqu'à `data: [DONE]`. Avant
     * le 01/10/2026, chat() (bloquant, stream:false) était appelé à la place et sa réponse complète
     * rejouée comme un seul faux "chunk" — aucun token n'atteignait le navigateur avant la fin de la
     * génération côté modèle. PROBLEMES.md n'en parle pas encore, à ajouter si un autre écart de
     * comportement apparaît entre Ollama et ce provider.
     * @param array<int,array{role:string,content:string}> $messages
     * @return \Generator<string,array{type:string,delta?:string,tool_calls?:array,raw?:array,model?:string},void,void>
     */
    public function chatStream(array $messages, array $tools = [], int $timeout = 120): \Generator {
        $id = $this->provider();
        $model = $this->model();
        $payload = ['model' => $model, 'messages' => $this->normalizeMessages($messages), 'temperature' => max(0.0, min(2.0, (float)$this->config->get('temperature'))), 'stream' => true];
        if ($tools !== []) $payload['tools'] = $tools;
        $body = null;
        $startedAt = microtime(true);
        $answer = '';
        $streamCalls = [];
        $finir = function () use ($id, $model, $messages, &$answer, &$streamCalls, $startedAt): array {
            $this->usage?->recordChat($this->config->userId(), $id, $model, $messages, $answer, null, null, (int)round((microtime(true) - $startedAt) * 1000));
            if ($streamCalls === []) {
                return ['type' => 'finished', 'model' => $model];
            }
            ksort($streamCalls);
            return ['type' => 'tool_calls', 'tool_calls' => $this->normalizeStreamedToolCalls($streamCalls), 'raw' => array_values($streamCalls), 'model' => $model];
        };
        try {
            if ($this->clientDisconnected()) {
                return;
            }
            $response = $this->clients->newClient()->post($this->endpoint(), [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $id), 'Content-Type' => 'application/json', 'Accept' => 'text/event-stream'],
                'json' => $payload,
                'timeout' => max(1, min(300, $timeout)),
                // Lectures bornées pour que la détection de déconnexion se déclenche même si le
                // serveur amont reste temporairement silencieux (pas de token).
                'read_timeout' => 5,
                'stream' => true,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                yield ['type' => 'error', 'delta' => 'Provider request failed (HTTP ' . $status . ').'];
                return;
            }
            $body = $response->getBody();
            $buffer = '';
            while (is_resource($body) ? !feof($body) : !$body->eof()) {
                if ($this->clientDisconnected()) {
                    return;
                }
                $chunk = is_resource($body) ? fread($body, 8192) : $body->read(8192);
                if ($chunk === '' || $chunk === false) {
                    usleep(10000);
                    continue;
                }
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                    // Ignore les lignes vides, "event: ...", les commentaires SSE ":..." (keep-alive) —
                    // seules les lignes "data: " portent un événement du contrat OpenAI.
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        yield $finir();
                        return;
                    }
                    if ($this->clientDisconnected()) {
                        return;
                    }
                    $obj = json_decode($data, true);
                    if (!is_array($obj)) {
                        continue;
                    }
                    $delta = $obj['choices'][0]['delta'] ?? [];
                    if (!is_array($delta)) {
                        continue;
                    }
                    if (is_array($delta['tool_calls'] ?? null)) {
                        foreach ($delta['tool_calls'] as $tc) {
                            if (!is_array($tc)) continue;
                            $idx = (int)($tc['index'] ?? 0);
                            $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                            if (!isset($streamCalls[$idx])) {
                                $streamCalls[$idx] = ['id' => (string)($tc['id'] ?? ('call_' . bin2hex(random_bytes(4)))), 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                            }
                            if (!empty($tc['id'])) $streamCalls[$idx]['id'] = (string)$tc['id'];
                            if (isset($fn['name']) && $fn['name'] !== '') $streamCalls[$idx]['function']['name'] = (string)$fn['name'];
                            // OpenAI streame toujours les arguments comme fragments de chaîne à
                            // concaténer (jamais un objet déjà décodé, contrairement à Ollama).
                            if (isset($fn['arguments']) && is_string($fn['arguments'])) $streamCalls[$idx]['function']['arguments'] .= $fn['arguments'];
                        }
                    }
                    $content = (string)($delta['content'] ?? '');
                    if ($content !== '') {
                        $answer .= $content;
                        if ($this->clientDisconnected()) {
                            return;
                        }
                        yield ['type' => 'content', 'delta' => $content];
                    }
                }
            }
            // Connexion fermée sans "data: [DONE]" explicite (certains serveurs auto-hébergés ne
            // l'envoient pas) : le flux est quand même terminé normalement, on clôt pareil.
            yield $finir();
        } catch (\Throwable $e) {
            if (!$this->clientDisconnected()) {
                yield ['type' => 'error', 'delta' => $e instanceof ProviderException ? $e->getMessage() : 'Provider connection or response failed. Check endpoint, key and model.'];
            }
        } finally {
            if (is_resource($body)) {
                @fclose($body);
            } elseif (is_object($body) && method_exists($body, 'close')) {
                try {
                    $body->close();
                } catch (\Throwable $ignored) {
                    // Le nettoyage ne doit jamais masquer le résultat réel du flux.
                }
            }
        }
    }

    private function clientDisconnected(): bool {
        return function_exists('connection_aborted') && connection_aborted() > 0;
    }

    /** @param array<int,array{id:string,type:string,function:array{name:string,arguments:string}}> $raw */
    private function normalizeStreamedToolCalls(array $raw): array {
        $out = [];
        foreach ($raw as $tc) {
            $name = (string)($tc['function']['name'] ?? '');
            if ($name === '') continue;
            $argsRaw = $tc['function']['arguments'] ?? '';
            $decoded = is_string($argsRaw) ? json_decode($argsRaw, true) : null;
            $out[] = ['name' => $name, 'arguments' => is_array($decoded) ? $decoded : []];
        }
        return $out;
    }

    /**
     * Request images from an OpenAI-compatible /images/generations endpoint.
     * Returns decoded bytes so callers can keep the result inside Nextcloud.
     *
     * @return list<array{bytes:string,mime:string}>
     */
    public function generateImages(string $prompt, int $count = 1, int $timeout = 120): array {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an image-generation endpoint. Configure an OpenAI-compatible image provider first.');
        }
        $profile = $this->profile();
        $model = trim((string)($profile['image_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No image model configured for the selected provider.');
        try {
            $response = $this->clients->newClient()->post($this->baseUrl() . '/images/generations', [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider), 'Content-Type' => 'application/json'],
                'json' => ['model' => $model, 'prompt' => $prompt, 'n' => max(1, min(4, $count)), 'size' => (string)($profile['image_size'] ?? '1024x1024'), 'response_format' => 'b64_json'],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Image provider returned HTTP ' . $status . '. Check the configured model and API key.');
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $out = [];
            foreach (($data['data'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $bytes = null;
                $mime = 'image/png';
                if (is_string($item['b64_json'] ?? null)) {
                    $bytes = base64_decode($item['b64_json'], true);
                } elseif (is_string($item['url'] ?? null)) {
                    $url = $item['url'];
                    if (!preg_match('~^https://~i', $url)) throw new ProviderException('Image provider returned an unsafe image URL.');
                    $download = $this->clients->newClient()->get($url, ['timeout' => 60, 'http_errors' => false, 'allow_redirects' => false]);
                    if ($download->getStatusCode() < 200 || $download->getStatusCode() >= 300) throw new ProviderException('Generated image could not be downloaded from the provider.');
                    $bytes = (string)$download->getBody();
                    $mime = (string)($download->getHeader('content-type') ?: 'image/png');
                }
                if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 15_000_000) continue;
                if (function_exists('getimagesizefromstring') && @getimagesizefromstring($bytes) === false) continue;
                $out[] = ['bytes' => $bytes, 'mime' => str_contains($mime, '/') ? $mime : 'image/png'];
            }
            if ($out === []) throw new ProviderException('Image provider returned no usable images.');
            return $out;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Image provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /** Transcribe or translate one bounded audio/video payload. */
    public function transcribeAudio(string $bytes, string $filename, string $mime, bool $translate = false, bool $subtitles = false, int $timeout = 180): string {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') {
            throw new ProviderException('The selected provider does not expose an audio transcription endpoint. Configure an OpenAI-compatible audio provider first.');
        }
        $profile = $this->profile();
        $model = trim((string)($profile['audio_model'] ?? $this->model()));
        if ($model === '') throw new ProviderException('No audio model configured for the selected provider.');
        try {
            $responseFormat = $subtitles ? 'vtt' : 'json';
            $response = $this->clients->newClient()->post($this->baseUrl() . '/audio/' . ($translate ? 'translations' : 'transcriptions'), [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider)],
                'multipart' => [
                    ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
                    ['name' => 'model', 'contents' => $model],
                    ['name' => 'response_format', 'contents' => $responseFormat],
                    ['name' => 'prompt', 'contents' => 'Transcribe faithfully in the same language as the audio. Preserve names and timestamps where available.'],
                ],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) throw new ProviderException('Audio provider returned HTTP ' . $status . '. Check the configured model and API key.');
            $body = (string)$response->getBody();
            if ($subtitles) return trim($body);
            $data = json_decode($body, true);
            $text = is_array($data) ? (string)($data['text'] ?? '') : '';
            if ($text === '' && $body !== '' && !str_starts_with(ltrim($body), '{')) $text = $body;
            if (trim($text) === '') throw new ProviderException('Audio provider returned no transcription.');
            return trim($text);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Audio provider connection or response failed. Check endpoint, key and model.');
        }
    }

    /** Generate bounded speech bytes through an OpenAI-compatible TTS endpoint. */
    public function synthesizeSpeech(string $text, int $timeout = 180): array {
        $provider = $this->provider();
        if ($provider === 'ollama' || $provider === 'groq') throw new ProviderException('The selected provider does not expose a speech endpoint. Configure an OpenAI-compatible audio provider first.');
        $profile = $this->profile();
        $model = trim((string)($profile['tts_model'] ?? $this->model()));
        $voice = trim((string)($profile['tts_voice'] ?? 'alloy'));
        if ($model === '') throw new ProviderException('No speech model configured for the selected provider.');
        try {
            $response = $this->clients->newClient()->post($this->baseUrl() . '/audio/speech', [
                'headers' => ['Authorization' => 'Bearer ' . $this->credentials->getCustom($this->config->userId() ?? '', $provider), 'Content-Type' => 'application/json'],
                'json' => ['model' => $model, 'input' => mb_substr($text, 0, 12000), 'voice' => $voice, 'response_format' => 'mp3'],
                'timeout' => max(1, min(300, $timeout)), 'http_errors' => false,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) throw new ProviderException('Speech provider returned HTTP ' . $response->getStatusCode() . '. Check the configured model and API key.');
            $bytes = (string)$response->getBody();
            if ($bytes === '' || strlen($bytes) > 25_000_000) throw new ProviderException('Speech provider returned an empty or oversized audio file.');
            return ['bytes' => $bytes, 'mime' => 'audio/mpeg'];
        } catch (ProviderException $e) { throw $e; }
        catch (\Throwable $e) { throw new ProviderException('Speech provider connection or response failed. Check endpoint, key and model.'); }
    }

    /**
     * Convert EVA's provider-neutral messages to the strict OpenAI chat-completions
     * contract: inline images (vision) AND, since 28/09/2026, a valid tool-call
     * round trip.
     *
     * EVA keeps `tool_calls[].function.arguments` decoded (array/stdClass) and
     * never sets `tool_call_id` on the following `role:"tool"` message
     * (RagService::canonicalToolCalls, AgentInteractionProvider) — Ollama
     * tolerates that shape, but a strict OpenAI-compatible server (vLLM,
     * OpenAI itself) rejects it with HTTP 400:
     *   "arguments" : Input should be a valid string
     *   ChatCompletionMessageCustomToolCallParam.custom : Field required
     * Fixed here, only for this provider, so EVA's internal (Ollama) format
     * is untouched. See eva-corrections/PROBLEMES.md for the full diagnosis.
     */
    private function normalizeMessages(array $messages): array {
        $out = [];
        $pendingToolCallId = null;
        foreach ($messages as $message) {
            $images = is_array($message['images'] ?? null) ? $message['images'] : [];
            $mimes = is_array($message['image_mimes'] ?? null) ? $message['image_mimes'] : [];
            unset($message['images'], $message['image_mimes']);
            if ($images !== []) {
                $parts = [['type' => 'text', 'text' => (string)($message['content'] ?? '')]];
                foreach ($images as $index => $base64) {
                    if (!is_string($base64) || $base64 === '') continue;
                    $mime = is_string($mimes[$index] ?? null) ? $mimes[$index] : 'image/jpeg';
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64]];
                }
                $message['content'] = $parts;
            }

            if (($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as &$call) {
                    if (!is_array($call)) continue;
                    $call['id'] = (string)($call['id'] ?? ('call_' . bin2hex(random_bytes(4))));
                    $call['type'] = 'function';
                    $args = $call['function']['arguments'] ?? [];
                    $call['function']['arguments'] = is_string($args)
                        ? $args
                        : (json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
                    $pendingToolCallId = $call['id'];
                }
                unset($call);
            } elseif (($message['role'] ?? '') === 'tool') {
                if ($pendingToolCallId !== null && empty($message['tool_call_id'])) {
                    $message['tool_call_id'] = $pendingToolCallId;
                }
                $pendingToolCallId = null;
            }
            $out[] = $message;
        }
        return $out;
    }
}
