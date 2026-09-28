<?php

declare(strict_types=1);

namespace OCA\EvaAi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

use OCA\EvaAi\Service\RagService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
        private RagService $ragService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Erste Seite: die reguläre Nextcloud-App-Shell (Vue/NC-Stil wie Files).
     * Der Chat selbst wird intern von einem Vanilla-Script aufgebaut.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function app(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function settings(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function documents(): TemplateResponse {
        return $this->appPage('index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function metrics(): TemplateResponse {
        return $this->appPage('index');
    }

    /**
     * Fallback ohne App-Shell: reine Chat-Seite (Vanilla-HTML, eigenes Layout).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function standalone(): TemplateResponse {
        Util::addTranslations('eva_ai');
        $jsDir = $this->appManager->getAppPath('eva_ai') . '/js';
        $standalone = null;
        $candidates = [];
        foreach (glob($jsDir . '/eva_ai_standalone*.js') ?: [] as $file) {
            $base = basename($file);
            if ($base !== 'eva_ai_standalone.js' || str_ends_with($base, '.map')) {
                continue;
            }
            $candidates[$file] = filemtime($file);
        }
        if ($candidates !== []) {
            arsort($candidates);
            $standalone = basename((string)array_key_first($candidates), '.js');
        }
        if ($standalone !== null) {
            \OCP\Util::addScript('eva_ai', $standalone);
        }
        \OCP\Util::addHeader('meta', [
            'name' => 'requesttoken',
            'content' => \OC::$server->get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue(),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-api',
            'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-stream',
            'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/streamChat?format=json'),
        ]);
        \OCP\Util::addHeader('meta', [
            'name' => 'eva-ai-version',
            'content' => 'standalone-1',
        ]);

        $dictation = $this->localDictationOrigin();
        $response = new TemplateResponse('eva_ai', 'standalone', [
            'version' => 'standalone-1',
        ]);
        $this->allowWebImages($response, $dictation);
        return $this->noCache($response);
    }

    private function appPage(string $template): TemplateResponse {
        Util::addTranslations('eva_ai');
        $jsDir = $this->appManager->getAppPath('eva_ai') . '/js';
        $main = null;
        $candidates = [];
        foreach (glob($jsDir . '/eva_ai-main*.js') ?: [] as $file) {
            $base = basename($file);
            // Only the webpack main bundle qualifies; leftover artifacts from
            // older build configurations (e.g. eva_ai-main-groq.*.js) must
            // never be picked as the app bundle.
            if ($base !== 'eva_ai-main.js'
                || str_ends_with($base, '.map')) {
                continue;
            }
            $candidates[$file] = filemtime($file);
        }
        if ($candidates !== []) {
            arsort($candidates);
            $main = basename((string)array_key_first($candidates), '.js');
        }
        if ($main !== null) {
            \OCP\Util::addScript('eva_ai', $main);
            \OCP\Util::addHeader('meta', [
                'name' => 'requesttoken',
                'content' => \OC::$server->get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue(),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-api',
                'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-stream',
                'content' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/streamChat?format=json'),
            ]);
            \OCP\Util::addHeader('meta', [
                'name' => 'eva-ai-version',
                'content' => 'shell-v2',
            ]);
        }
        $dictation = $this->localDictationOrigin();
        $response = new TemplateResponse('eva_ai', $template, [
            'apiBase' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/eva_ai/api/'),
        ]);
        $this->allowWebImages($response, $dictation);
        return $this->noCache($response);
    }

    /**
     * Dictation runs on the user's own computer: the page records, a local Whisper
     * (on localhost) transcribes, and only the text reaches Nextcloud when the user
     * sends it. The audio never leaves the machine.
     *
     * Enabled by the admin with `occ config:app:set eva_ai dictation_local_url
     * --value=http://localhost:8178/v1`. The URL is accepted ONLY for the hosts
     * localhost and 127.0.0.1: any other value keeps dictation off, so the page can
     * never be told to send a recording to another machine. When accepted, the
     * micro script is loaded, the URL is given to it in a meta tag, and the page's
     * CSP allows connections to that local origin only.
     *
     * @return string|null the origin (scheme://host:port) to allow, or null when off
     */
    private function localDictationOrigin(): ?string {
        $url = trim(\OCP\Server::get(\OCP\IAppConfig::class)->getValueString('eva_ai', 'dictation_local_url', ''));
        $origin = self::localOrigin($url);
        if ($origin === null) {
            return null;
        }
        \OCP\Util::addScript('eva_ai', 'micro');
        \OCP\Util::addHeader('meta', ['name' => 'eva-ai-dictation', 'content' => rtrim($url, '/')]);
        return $origin;
    }

    /** scheme://host[:port] of a localhost/127.0.0.1 http(s) URL without credentials, else null. */
    public static function localOrigin(string $url): ?string {
        if ($url === '' || preg_match('/[\s"<>\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        return $scheme . '://' . $host . ($port !== null ? ':' . (int)$port : '');
    }

    /**
     * Allow the pictures a web answer embeds to actually load.
     *
     * Nextcloud's own policy for images is `img-src 'self' data: blob:` (plus
     * map tiles), which blocks every remote picture. An answer that shows a
     * product photo or a photo of an event therefore rendered as a broken image
     * and - since the chat degrades a broken image to a link on purpose - the
     * user saw a link instead of the picture they asked for.
     *
     * The relaxation is deliberately scoped to this app's own pages and to
     * images, and the pictures themselves are already constrained on the way in:
     * they are only ever inserted as a markdown image the answer chose, they
     * carry `referrerpolicy="no-referrer"` so the host learns nothing about the
     * reader, and they load lazily, so an image the user never scrolls to is
     * never fetched.
     */
    private function allowWebImages(TemplateResponse $response, ?string $dictationOrigin = null): void {
        $policy = new ContentSecurityPolicy();
        $policy->addAllowedImageDomain('*');
        if ($dictationOrigin !== null) {
            $policy->addAllowedConnectDomain($dictationOrigin);
        }
        $response->setContentSecurityPolicy($policy);
    }

    private function noCache(TemplateResponse $response): TemplateResponse {
        foreach ([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ] as $name => $value) {
            $response->addHeader($name, $value);
        }
        return $response;
    }
}
