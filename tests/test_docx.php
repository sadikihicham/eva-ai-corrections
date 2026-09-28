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
$x = doc($g->x("Le mot اردو signifie « urdu »."));
verifie('ligne française avec un mot arabe → reste de gauche à droite (première lettre)', !str_contains($x, '<w:bidi/>'));
$x = doc($g->x("دور لي على گوگل"));
verifie('arabe du Golfe (گ) → langue ar-SA, pas ur-PK', str_contains($x, 'w:bidi="ar-SA"'));
// Markdown → vrai Word (28/09 : « # » et « - » étaient imprimés tels quels)
function parts(string $docx): array { $f = tempnam(sys_get_temp_dir(), 'dx'); file_put_contents($f, $docx); $z = new ZipArchive(); $z->open($f); $p = []; for ($i = 0; $i < $z->numFiles; $i++) { $p[$z->getNameIndex($i)] = (string)$z->getFromIndex($i); } $z->close(); @copy($f, '/tmp/eva-test-word.docx'); unlink($f); return $p; }
$md = "# Plan de formation\nIntroduction avec **gras** au milieu.\n\n## Objectifs\n- Sécuriser\n  - postes\n- Former\n\n1. Audit\n2. Formation\n\nTexte entre deux listes.\n\n1. Reprise\n\n| Poste | Montant |\n|---|---|\n| Serveurs | 12 000 |\n\n```\n# pas un titre\n```\n---\nFin.";
$p = parts($g->x($md)); $x = $p['word/document.xml'] ?? '';
foreach (['word/document.xml', 'word/numbering.xml', 'word/styles.xml', 'word/_rels/document.xml.rels', '[Content_Types].xml'] as $n) verifie("partie $n présente", isset($p[$n]));
verifie('toutes les parties XML bien formées', array_filter($p, static function ($c, $n) { if (!str_ends_with($n, '.xml') && !str_ends_with($n, '.rels')) return false; $d = new DOMDocument(); return !@$d->loadXML($c); }, ARRAY_FILTER_USE_BOTH) === []);
verifie('titres : « # » retiré, gras 18 pt / 15 pt', !str_contains($x, '># Plan') && !str_contains($x, '>## Objectifs') && str_contains($x, '>Plan de formation<') && str_contains($x, '<w:sz w:val="36"/>') && str_contains($x, '<w:sz w:val="30"/>'));
verifie('puces : vraie liste Word (numPr numId 1), niveau 2 pour l\'imbrication, « - » retiré', substr_count($x, '<w:numId w:val="1"/>') === 3 && str_contains($x, '<w:ilvl w:val="1"/>') && !preg_match('~<w:t[^>]*>- ~', $x));
verifie('listes numérotées : chacune repart à 1 (2 listes = 2 numéros distincts avec startOverride)', str_contains($x, '<w:numId w:val="3"/>') && str_contains($x, '<w:numId w:val="4"/>') && substr_count($p['word/numbering.xml'], '<w:startOverride w:val="1"/>') === 2);
verifie('**gras** → w:b, astérisques retirés', str_contains($x, '<w:b/>') && !str_contains($x, '**'));
verifie('tableau → w:tbl, en-tête ombré, séparateur retiré', str_contains($x, '<w:tbl>') && str_contains($x, 'w:fill="1F2A37"') && substr_count($x, '<w:tr>') === 2 && !str_contains($x, '---'));
verifie('bloc de code : « # » gardé en Courier New, pas un titre', str_contains($x, 'Courier New') && str_contains($x, '># pas un titre<'));
verifie('police par défaut Calibri (styles.xml)', str_contains($p['word/styles.xml'], 'w:ascii="Calibri"'));
$p = parts($g->x("| a | b |\n|---|---|\n| 1 | 2 |")); verifie('document qui finit par un tableau : paragraphe final ajouté', str_ends_with(explode('<w:sectPr>', $p['word/document.xml'])[0], '<w:p/>'));
$p = parts($g->x("")); verifie('document vide → XML valide', (new DOMDocument())->loadXML($p['word/document.xml']) === true);
$p = parts($g->x("## العنوان\n- نقطة أولى")); verifie('titre et puce arabes : bidi dans pPr', substr_count($p['word/document.xml'], '<w:bidi/>') === 2);
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
