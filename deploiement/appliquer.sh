#!/usr/bin/env bash
# Applique les 2 correctifs sur workspace4 (192.168.1.99), APRÈS la sauvegarde déjà faite le 28/09
# à 00:29 (/srv/sauvegarde-eva_ai/avant-correction-20260928-002905/, confirmée par diff, zéro dérive).
# Script AUTONOME : le fichier corrigé est intégré ci-dessous, rien à envoyer séparément (correction
# d'un défaut du 1er essai, qui référençait un fichier local absent du serveur).
# À lancer par l'admin :
#   ssh -o UserKnownHostsFile=~/.ssh/known_hosts_workspace4 ubuntu@192.168.1.99 'bash -s' < appliquer.sh
# Retour arrière : bash retour-arriere.sh
set -euo pipefail
cd /home/ubuntu/docker
A="sudo docker compose --env-file .env exec -T"

echo "1) OpenAICompatible.php — remplacement complet (fichier corrigé, syntaxe déjà vérifiée)"
$A -u www-data app sh -c "cat > /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php" <<'FICHIER_PHP'
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
FICHIER_PHP
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php

echo "2) ActionExecutor.php — remplacement d'une ligne (espace de noms), vérifié unique avant/après"
AVANT='OCP\\AppFramework\\Services\\IAppDataFactory'
APRES='OCP\\Files\\AppData\\IAppDataFactory'
N=$($A app grep -c "$AVANT" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php)
[ "$N" = "1" ] || { echo "ARRET : $N occurrence(s) au lieu de 1 — rien changé"; exit 1; }
$A -u www-data app sed -i "s|$AVANT|$APRES|" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php
N2=$($A app grep -c "$APRES" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php)
echo "occurrences du bon espace de noms après correction : $N2 (attendu ≥ 3 : la ligne corrigée + les 2 déjà correctes ailleurs)"

echo "3) intégrité de l'app (avertissement attendu, sans gravité — fichiers modifiés hors App Store)"
$A -u www-data app php occ integrity:check-app eva_ai || true

echo "4) opcache — vérifier si un redémarrage est nécessaire pour que PHP relise les fichiers"
$A app php -r 'echo "validate_timestamps=" . ini_get("opcache.validate_timestamps") . "\n";'
echo "Si validate_timestamps=1 (par défaut) : rien à faire, PHP relit les fichiers automatiquement."
echo "Si 0 : lancer 'docker compose restart app' (coupe brièvement les 185 utilisateurs) — DEMANDER confirmation avant."
