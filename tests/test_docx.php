<?php
/**
 * Fabrique Word (buildDocx) : urdu et arabe écrits de droite à gauche avec leur langue (28/09), le reste inchangé.
 * Usage : php tests/test_docx.php src/ActionExecutor.php (sans PHP local : source sur stdin).
 */
$source = file_get_contents($argv[1] ?? 'php://stdin');
function extraire(string $src, string $nom): string {
    $debut = strpos($src, 'private function ' . $nom . '(');
    if ($debut === false) { fwrite(STDERR, "méthode $nom absente\n"); exit(2); }
    $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
    for ($i = $ouv, $n = strlen($src); $i < $n; $i++) { if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) return substr($src, $debut, $i - $debut + 1); }
    exit(2);
}
eval('class DocxSousTest { ' . extraire($source, 'buildDocx') . ' public function x(string $t): string { return $this->buildDocx($t); } }');
$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " → $d") . "\n"; if (!$ok) $echecs++; }
function doc(string $docx): string { $f = tempnam(sys_get_temp_dir(), 'dx'); file_put_contents($f, $docx); $z = new ZipArchive(); $z->open($f); $x = (string)$z->getFromName('word/document.xml'); $z->close(); unlink($f); return $x; }
$g = new DocxSousTest();
$x = doc($g->x("Titre en français\nیہ ایک مثال ہے\nهذا مثال\nLigne 4"));
$d = new DOMDocument(); verifie('XML bien formé', @$d->loadXML($x) === true);
preg_match_all('~<w:p>(.*?)</w:p>~s', $x, $p);
verifie('4 paragraphes', count($p[1]) === 4, (string)count($p[1]));
verifie('français : pas de bidi', !str_contains($p[1][0], '<w:bidi/>') && !str_contains($p[1][3], '<w:bidi/>'));
verifie('urdu : paragraphe bidi + rtl + langue ur-PK', str_contains($p[1][1], '<w:bidi/>') && str_contains($p[1][1], '<w:rtl/>') && str_contains($p[1][1], 'w:bidi="ur-PK"'));
verifie('arabe : bidi + langue ar-SA', str_contains($p[1][2], '<w:bidi/>') && str_contains($p[1][2], 'w:bidi="ar-SA"'));
verifie('texte urdu conservé', str_contains($x, 'یہ ایک مثال ہے'));
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
