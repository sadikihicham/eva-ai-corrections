<?php
/**
 * Garde-fou de la dictée locale (PageController::localOrigin, branche micro-serveur, 28/09/2026).
 * L'URL de Whisper n'est acceptée que pour localhost / 127.0.0.1 : sinon la page pourrait être configurée pour
 * envoyer l'audio ailleurs que sur la machine de l'utilisateur. La VRAIE méthode est extraite du fichier source.
 *
 * Usage (n'importe quel PHP 8.3, sans Nextcloud) : php tests/test_dictee_origine.php app/lib/Controller/PageController.php
 */
$source = file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') { fwrite(STDERR, "source PageController.php introuvable\n"); exit(2); }

$debut = strpos($source, 'public static function localOrigin(');
if ($debut === false) { echo "❌ méthode localOrigin absente\n"; exit(1); }
$niveau = 0; $fin = null;
for ($i = strpos($source, '{', $debut), $n = strlen($source); $i < $n; $i++) {
    if ($source[$i] === '{') $niveau++;
    elseif ($source[$i] === '}' && --$niveau === 0) { $fin = $i; break; }
}
// eval() ne charge que la méthode extraite de NOTRE fichier versionné (même technique que les autres tests).
eval('class OrigineSousTest { ' . substr($source, $debut, $fin - $debut + 1) . ' }');

$echecs = 0; $total = 0;
$cas = [
    // [URL, origine attendue ou null]
    ['http://localhost:8178/v1', 'http://localhost:8178'],
    ['http://127.0.0.1:8178/v1/', 'http://127.0.0.1:8178'],
    ['HTTP://LOCALHOST:8178/v1', 'http://localhost:8178'],
    ['https://localhost/v1', 'https://localhost'],
    ['', null],
    ['http://192.168.1.50:8178/v1', null],                    // autre machine du réseau
    ['http://localhost.evil.com:8178/v1', null],               // sous-domaine piège
    ['http://evil.com/?h=localhost', null],
    ['http://user:pass@localhost:8178/v1', null],              // identifiants dans l'URL
    ['http://localhost@evil.com/v1', null],                    // hôte réel = evil.com
    ['ftp://localhost/v1', null],
    ['javascript:alert(1)', null],
    ['//localhost:8178/v1', null],                             // sans schéma
    ['http://localhost:8178/v1?x=1', null],
    ['http://localhost:8178/v1#x', null],
    ['http://[::1]:8178/v1', null],                            // non prévu par le contrat
    ['http://localhost:8178/v1" onerror="x', null],            // caractères interdits (meta)
    ['http://0.0.0.0:8178/v1', null],
    ['http://127.0.0.2:8178/v1', null],
];
foreach ($cas as [$url, $attendu]) {
    $total++;
    $obtenu = OrigineSousTest::localOrigin($url);
    $ok = $obtenu === $attendu;
    if (!$ok) $echecs++;
    echo ($ok ? '✅ ' : '❌ ') . var_export($url, true) . ' → ' . var_export($obtenu, true) . ($ok ? '' : ' (attendu ' . var_export($attendu, true) . ')') . "\n";
}
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
