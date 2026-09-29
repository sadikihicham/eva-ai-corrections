<?php
/**
 * Test des replis de la recherche web (branche recherche-repli-bing, 30/09) :
 *   - moteur principal (SearXNG) sans résultat ⇒ repli sur le flux Bing ;
 *   - mode « actualités » sans article ⇒ recherche web (puis Bing) au lieu d'un échec ;
 *   - flux d'actualités LUS mais vides ≠ flux illisibles (message juste).
 * Teste le VRAI code : search(), webResults(), searchNews() et emptyResultError() sont extraites telles quelles de
 * app/lib/Service/WebSearchService.php ; seuls les moteurs et le classement sont simulés. Aucun réseau.
 *
 * Usage : php tests/test_recherche_repli.php app/lib/Service/WebSearchService.php
 *     ou : docker run --rm -v "$PWD":/w -w /w php:8.3-cli php tests/test_recherche_repli.php app/lib/Service/WebSearchService.php
 */
set_error_handler(function (int $n, string $m): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m\n"; $echecs = ($echecs ?? 0) + 1; return true; });
$source = file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') { fwrite(STDERR, "source introuvable\n"); exit(2); }

function extraire(string $src, string $visibilite, string $methode): string {
    $debut = strpos($src, "$visibilite function $methode(");
    if ($debut === false) throw new RuntimeException("méthode absente : $methode");
    $ouvrante = strpos($src, '{', $debut);
    $niveau = 0;
    for ($i = $ouvrante, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $niveau++;
        elseif ($src[$i] === '}' && --$niveau === 0) return substr($src, $debut, $i - $debut + 1);
    }
    throw new RuntimeException("fin introuvable : $methode");
}

class ProviderException extends RuntimeException {}
$code = extraire($source, 'public', 'search') . "\n" . extraire($source, 'private', 'webResults') . "\n"
      . extraire($source, 'private', 'searchNews') . "\n" . extraire($source, 'private', 'emptyResultError');
$code = str_replace('\\Throwable', 'Throwable', $code);

// eval sur du code du DÉPÔT (méthodes extraites du fichier testé), jamais sur une entrée extérieure : même procédé que
// les autres tests PHP de ce dossier, pour tester le vrai code sans charger Nextcloud.
eval('class Recherche {
    const MODES = ["web", "news", "auto"];
    const ABSOLUTE_MAX_RESULTS = 10;
    const ABSOLUTE_MAX_CONTENT_PAGES = 10;
    const BING_NEWS_ENDPOINT = "https://www.bing.com/news/search";
    const GOOGLE_NEWS_ENDPOINT = "https://news.google.com/rss/search";
    const FEED_ACCEPT = "application/rss+xml";
    public string $fournisseur = "searxng";
    public array $moteurs = [];          // nom => liste de résultats | "panne"
    public array $flux = [];             // hôte => liste d\'articles | "panne"
    public array $appels = [];
    public ?string $lastDuckDuckGoError = null;
    public $logger;
    public function __construct() { $this->logger = new class { function warning($m, $c = []) {} function info($m, $c = []) {} }; }
    private function provider(): string { return $this->fournisseur; }
    private function isEnabled(): bool { return true; }
    private function maxResults(): int { return 5; }
    private function candidateLimit(int $n): int { return $n * 2; }
    private function moteur(string $nom): array { $this->appels[] = $nom; $r = $this->moteurs[$nom] ?? [];
        if ($r === "panne") throw new ProviderException("$nom en panne"); return $r; }
    private function searchSearxng($q, $n) { return $this->moteur("searxng"); }
    private function searchBing($q, $n) { return $this->moteur("bing"); }
    private function searchDuckDuckGo($q, $n) { return $this->moteur("duckduckgo"); }
    private function searchBrave($q, $n) { return $this->moteur("brave"); }
    private function searchTavily($q, $n) { return $this->moteur("tavily"); }
    private function newsLocale(): array { return ["hl" => "fr", "gl" => "FR", "ceid" => "FR:fr"]; }
    private function browserHeaders($u, $a) { return []; }
    private function httpGet(string $url, array $h): string { $hote = parse_url($url, PHP_URL_HOST); $this->appels[] = $hote;
        $f = $this->flux[$hote] ?? []; if ($f === "panne") throw new RuntimeException("flux illisible"); return json_encode($f); }
    private function parseRssResults(string $body, string $mode, int $n): array { return json_decode($body, true); }
    private function deduplicate(array $r, int $n): array { return array_slice($r, 0, $n); }
    private function mergeByUrl(array $a, array $b, int $n): array { return array_slice(array_merge($a, $b), 0, $n); }
    private function rankResults(array $r, string $q, bool $c = false, string $m = "web"): array { return $r; }
    private function enrichWithPageContent(array $r, string $q): array { return $r; }
' . $code . '
}');

$echecs = 0;
function verifie(string $nom, bool $ok, string $detail = ''): void { global $echecs; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " — $detail") . "\n"; if (!$ok) $echecs++; }
$R = fn(string $u) => ['title' => $u, 'url' => "https://$u/", 'snippet' => $u];

// 1. Comportement inchangé quand SearXNG répond
$t = new Recherche(); $t->moteurs = ['searxng' => [$R('docs.nextcloud.com')]];
$r = $t->search('Nextcloud 34', 5, 'web');
verifie('web : SearXNG répond ⇒ pas de repli', $r['ok'] && !isset($r['fallback']) && $t->appels === ['searxng'], json_encode([$r, $t->appels]));

// 2. SearXNG vide ⇒ Bing
$t = new Recherche(); $t->moteurs = ['searxng' => [], 'bing' => [$R('bing.example')]];
$r = $t->search('x', 5, 'web');
verifie('web : SearXNG vide ⇒ repli Bing', $r['ok'] && ($r['fallback'] ?? null) === 'bing' && $t->appels === ['searxng', 'bing'], json_encode([$r, $t->appels]));

// 3. SearXNG en panne ⇒ Bing, et la panne n'empêche pas le succès
$t = new Recherche(); $t->moteurs = ['searxng' => 'panne', 'bing' => [$R('bing.example')]];
$r = $t->search('x', 5, 'web');
verifie('web : SearXNG en panne ⇒ repli Bing', $r['ok'] && ($r['fallback'] ?? null) === 'bing', json_encode($r));

// 4. Tout vide ⇒ échec avec message (jamais un faux succès)
$t = new Recherche(); $t->moteurs = ['searxng' => [], 'bing' => []];
$r = $t->search('x', 5, 'web');
verifie('web : tout vide ⇒ ok=false + message', !$r['ok'] && is_string($r['error']) && $r['error'] !== '', json_encode($r));

// 5. Fournisseur = bing : pas de double appel à Bing
$t = new Recherche(); $t->fournisseur = 'bing'; $t->moteurs = ['bing' => []];
$t->search('x', 5, 'web');
verifie('web : fournisseur bing vide ⇒ Bing appelé une seule fois', $t->appels === ['bing'], json_encode($t->appels));

// 6. Le cas du 30/09 : « actualités » sans article ⇒ recherche web
$t = new Recherche(); $t->moteurs = ['searxng' => [$R('techsy.io')]]; $t->flux = ['www.bing.com' => [], 'news.google.com' => []];
$r = $t->search('modèles LLM récents', 5, 'news');
verifie('news : flux vides ⇒ repli web (SearXNG)', $r['ok'] && ($r['fallback'] ?? null) === 'web' && in_array('searxng', $t->appels, true), json_encode([$r, $t->appels]));

// 7. Flux illisibles + SearXNG vide ⇒ Bing web
$t = new Recherche(); $t->moteurs = ['searxng' => [], 'bing' => [$R('bing.example')]]; $t->flux = ['www.bing.com' => 'panne', 'news.google.com' => 'panne'];
$r = $t->search('x', 5, 'news');
verifie('news : flux illisibles + SearXNG vide ⇒ repli Bing', $r['ok'] && ($r['fallback'] ?? null) === 'bing', json_encode($r));

// 8. Actualités disponibles ⇒ pas de repli, pas d'appel web
$t = new Recherche(); $t->flux = ['www.bing.com' => [$R('news.example')], 'news.google.com' => []];
$r = $t->search('x', 5, 'news');
verifie('news : articles trouvés ⇒ pas de repli web', $r['ok'] && !isset($r['fallback']) && !in_array('searxng', $t->appels, true), json_encode([$r, $t->appels]));

// 9. Message juste : flux lus mais vides, et tout le reste vide ⇒ « no articles », pas « could not be read »
$t = new Recherche(); $t->moteurs = ['searxng' => [], 'bing' => []]; $t->flux = ['www.bing.com' => [], 'news.google.com' => []];
$r = $t->search('x', 5, 'news');
verifie('news : flux lus mais vides ⇒ pas de « could not be read »', !$r['ok'] && !str_contains((string)$r['error'], 'could not be read'), json_encode($r));

// 10. Flux vraiment illisibles ⇒ le message le dit
$t = new Recherche(); $t->moteurs = ['searxng' => [], 'bing' => []]; $t->flux = ['www.bing.com' => 'panne', 'news.google.com' => 'panne'];
$r = $t->search('x', 5, 'news');
verifie('news : flux illisibles ⇒ « could not be read »', !$r['ok'] && str_contains((string)$r['error'], 'could not be read'), json_encode($r));

// 11. Mode auto : web + actualités fusionnés, pas de repli si le web répond
$t = new Recherche(); $t->moteurs = ['searxng' => [$R('a.example')]]; $t->flux = ['www.bing.com' => [$R('b.example')], 'news.google.com' => []];
$r = $t->search('x', 5, 'auto');
verifie('auto : web + actualités, pas de repli', $r['ok'] && count($r['results']) === 2 && !isset($r['fallback']), json_encode($r));

echo $echecs === 0 ? "\nTOUT VERT (11 cas)\n" : "\n$echecs ÉCHEC(S)\n";
exit($echecs === 0 ? 0 : 1);
