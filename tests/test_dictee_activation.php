<?php
/**
 * Activation de la dictée côté serveur (PageController::localDictationOrigin + allowWebImages, revue I1, 29/09/2026).
 * Invariant : sans les DEUX interrupteurs (URL locale valide au niveau de l'app ET dictation_enabled=yes pour CE
 * compte), la page ne reçoit ni le script micro, ni la meta eva-ai-dictation, ni d'origine locale dans la CSP.
 * Les VRAIES méthodes sont extraites du fichier source ; Nextcloud est remplacé par des doublures qui enregistrent
 * les appels (IConfig factice, Util, ContentSecurityPolicy).
 *
 * Usage (n'importe quel PHP 8.3, sans Nextcloud) : php tests/test_dictee_activation.php app/lib/Controller/PageController.php
 */

namespace OCP {
    interface IConfig {}
    class Server {
        public static $config = null;   // IConfig factice, ou \Throwable à lever
        public static function get(string $classe) {
            if (self::$config instanceof \Throwable) throw self::$config;
            return self::$config;
        }
    }
    class Util {
        public static array $scripts = [];
        public static array $metas = [];
        public static function addScript(string $app, string $fichier): void { self::$scripts[] = "$app/$fichier"; }
        public static function addHeader(string $balise, array $attributs): void { if ($balise === 'meta') self::$metas[$attributs['name']] = $attributs['content']; }
    }
}

namespace OCP\AppFramework\Http {
    class ContentSecurityPolicy {
        public array $connect = [];
        public array $images = [];
        public function addAllowedConnectDomain(string $d): void { $this->connect[] = $d; }
        public function addAllowedImageDomain(string $d): void { $this->images[] = $d; }
    }
    class TemplateResponse {
        public ?ContentSecurityPolicy $csp = null;
        public function setContentSecurityPolicy(ContentSecurityPolicy $p): void { $this->csp = $p; }
    }
}

namespace {
    /** IConfig factice : valeurs par compte + valeur d'app ; peut lever sur l'une ou l'autre lecture. */
    class ConfigFactice implements \OCP\IConfig {
        public function __construct(private array $parCompte, private string $url, private bool $leve = false) {}
        public function getUserValue($uid, $app, $cle, $defaut = '') {
            if ($this->leve) throw new \RuntimeException('base indisponible');
            return $this->parCompte[$uid][$app][$cle] ?? $defaut;
        }
        public function getAppValue($app, $cle, $defaut = '') { return $app === 'eva_ai' && $cle === 'dictation_local_url' ? $this->url : $defaut; }
    }

    $fichier = $argv[1] ?? '';
    $source = is_file($fichier) ? file_get_contents($fichier) : '';
    if (!is_string($source) || $source === '') { fwrite(STDERR, "source PageController.php introuvable\n"); exit(2); }

    function methode(string $source, string $signature): string {
        $debut = strpos($source, $signature);
        if ($debut === false) { echo "❌ méthode absente : $signature\n"; exit(1); }
        $niveau = 0;
        for ($i = strpos($source, '{', $debut), $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') $niveau++;
            elseif ($source[$i] === '}' && --$niveau === 0) return substr($source, $debut, $i - $debut + 1);
        }
        echo "❌ accolades non équilibrées : $signature\n"; exit(1);
    }
    // eval() ne charge que des méthodes extraites de NOTRE fichier versionné (même technique que test_dictee_origine.php),
    // dans le namespace du contrôleur et avec ses « use », pour que les noms se résolvent comme en production.
    eval('namespace OCA\EvaAi\Controller;
        use OCP\AppFramework\Http\ContentSecurityPolicy;
        use OCP\AppFramework\Http\TemplateResponse;
        class PageSousTest {
            public function __construct(private ?string $userId) {}
            public function page(): TemplateResponse { $d = $this->localDictationOrigin(); $r = new TemplateResponse(); $this->allowWebImages($r, $d); return $r; }
            ' . methode($source, 'private function localDictationOrigin(')
              . methode($source, 'public static function localOrigin(')
              . methode($source, 'private function allowWebImages(') . '
        }');

    $URL = 'http://127.0.0.1:8178/v1';
    $ADMIN = ['admin' => ['eva_ai' => ['dictation_enabled' => 'yes']]];
    $cas = [
        // [libellé, compte, config (ConfigFactice ou Throwable), origine attendue ou null, meta attendue]
        ['admin activé + URL locale', 'admin', new ConfigFactice($ADMIN, $URL), 'http://127.0.0.1:8178', $URL],
        ['URL avec espaces et / final', 'admin', new ConfigFactice($ADMIN, "  $URL/ "), 'http://127.0.0.1:8178', $URL],
        ['localhost accepté', 'admin', new ConfigFactice($ADMIN, 'http://localhost:8178/v1'), 'http://localhost:8178', 'http://localhost:8178/v1'],
        ['compte NON activé (autre compte que admin)', 'alice', new ConfigFactice($ADMIN, $URL), null, null],
        ['compte sans réglage (défaut no)', 'bob', new ConfigFactice([], $URL), null, null],
        ['réglage « no » explicite', 'admin', new ConfigFactice(['admin' => ['eva_ai' => ['dictation_enabled' => 'no']]], $URL), null, null],
        ['réglage « YES » (comparaison stricte)', 'admin', new ConfigFactice(['admin' => ['eva_ai' => ['dictation_enabled' => 'YES']]], $URL), null, null],
        ['réglage « 1 »', 'admin', new ConfigFactice(['admin' => ['eva_ai' => ['dictation_enabled' => '1']]], $URL), null, null],
        ['réglage posé pour une autre app', 'admin', new ConfigFactice(['admin' => ['autre' => ['dictation_enabled' => 'yes']]], $URL), null, null],
        ['activé mais URL d\'app absente', 'admin', new ConfigFactice($ADMIN, ''), null, null],
        ['activé mais URL vers une autre machine', 'admin', new ConfigFactice($ADMIN, 'http://192.168.1.50:8178/v1'), null, null],
        ['activé mais URL piège localhost@evil', 'admin', new ConfigFactice($ADMIN, 'http://localhost@evil.com/v1'), null, null],
        ['visiteur sans compte (null)', null, new ConfigFactice($ADMIN, $URL), null, null],
        ['identifiant vide', '', new ConfigFactice(['' => ['eva_ai' => ['dictation_enabled' => 'yes']]], $URL), null, null],
        ['lecture du réglage en erreur', 'admin', new ConfigFactice($ADMIN, $URL, true), null, null],
        ['IConfig indisponible', 'admin', new \RuntimeException('conteneur DI'), null, null],
    ];

    $echecs = 0; $total = 0;
    $verifier = function (bool $ok, string $quoi) use (&$echecs, &$total) {
        $total++;
        if (!$ok) $echecs++;
        echo '   ' . ($ok ? '✅ ' : '❌ ') . $quoi . "\n";
    };
    foreach ($cas as [$libelle, $compte, $config, $origine, $meta]) {
        \OCP\Server::$config = $config;
        \OCP\Util::$scripts = [];
        \OCP\Util::$metas = [];
        echo "$libelle\n";
        $r = (new \OCA\EvaAi\Controller\PageSousTest($compte))->page();
        $verifier($r->csp !== null && $r->csp->images === ['*'], 'CSP posée, images web autorisées');
        if ($origine === null) {
            $verifier($r->csp !== null && $r->csp->connect === [], 'CSP : AUCUNE origine locale en connect-src');
            $verifier(\OCP\Util::$scripts === [], 'script micro NON chargé');
            $verifier(!array_key_exists('eva-ai-dictation', \OCP\Util::$metas), 'meta eva-ai-dictation ABSENTE');
        } else {
            $verifier($r->csp !== null && $r->csp->connect === [$origine], "CSP : connect-src = $origine seulement");
            $verifier(\OCP\Util::$scripts === ['eva_ai/micro'], 'script micro chargé une fois');
            $verifier((\OCP\Util::$metas['eva-ai-dictation'] ?? null) === $meta, "meta eva-ai-dictation = $meta");
        }
    }

    // Câblage : les deux pages (shell et standalone) passent bien l'origine calculée à la CSP.
    echo "câblage des pages\n";
    $verifier(substr_count($source, '$dictation = $this->localDictationOrigin();') === 2, 'localDictationOrigin() appelée dans les 2 pages');
    $verifier(substr_count($source, '$this->allowWebImages($response, $dictation);') === 2, 'son résultat est passé à la CSP dans les 2 pages');
    $verifier(substr_count($source, '$this->allowWebImages(') === 2, 'aucun autre appel à allowWebImages que ces 2 pages');

    echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
    exit($echecs === 0 ? 0 : 1);
}
