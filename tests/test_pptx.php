<?php
/**
 * Fabrique PowerPoint (buildPptx, 28/09) : paquet complet et XML bien formé, une diapositive par titre, diapositive de
 * titre, puces, gras, vrais tableaux, « (suite) » au-delà de 7 lignes, arabe de droite à gauche, caractères échappés.
 * Méthode extraite telle quelle de src/ActionExecutor.php. Usage : php tests/test_pptx.php src/ActionExecutor.php
 * (sans PHP local : source sur stdin). Écrit aussi /tmp/eva-test-*.pptx pour un contrôle de rendu (Collabora).
 */
$source = file_get_contents($argv[1] ?? 'php://stdin');
function extraire(string $src, string $nom): string {
    $debut = strpos($src, 'private function ' . $nom . '(');
    if ($debut === false) { fwrite(STDERR, "méthode $nom absente\n"); exit(2); }
    $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
    for ($i = $ouv, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) return substr($src, $debut, $i - $debut + 1);
    }
    exit(2);
}
eval('class ToolPolicy {} class PptxSousTest { ' . extraire($source, 'buildPptx') . ' public function x(string $t): string { return $this->buildPptx($t); } }');
$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " → $d") . "\n"; if (!$ok) $echecs++; }
/** @return array{parts: array<string,string>, slides: list<string>} */
function ouvre(string $pptx, string $nom): array {
    $f = '/tmp/eva-test-' . $nom . '.pptx'; file_put_contents($f, $pptx);
    $z = new ZipArchive(); $z->open($f); $parts = [];
    for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); $parts[$n] = (string)$z->getFromIndex($i); }
    $z->close();
    $slides = []; for ($i = 1; isset($parts["ppt/slides/slide$i.xml"]); $i++) $slides[] = $parts["ppt/slides/slide$i.xml"];
    return ['parts' => $parts, 'slides' => $slides];
}
function xmlOk(array $parts): array {
    $ko = [];
    foreach ($parts as $n => $c) { if (!str_ends_with($n, '.xml') && !str_ends_with($n, '.rels')) continue; $d = new DOMDocument(); if (!@$d->loadXML($c)) $ko[] = $n; }
    return $ko;
}
function textes(string $xml): string { preg_match_all('~<a:t>(.*?)</a:t>~s', $xml, $m); return html_entity_decode(implode(' | ', $m[1]), ENT_XML1, 'UTF-8'); }
$g = new PptxSousTest();

// 1. Présentation type demandée au modèle
$md = "# Plan de formation 2026\nÉquipe IT — Infinity\n\n## Objectifs\n- Sécuriser les postes\n- Former les **nouveaux** arrivants\n  - accueil J1\n  - parrainage\n\n## Calendrier\n| Mois | Action |\n|---|---|\n| Janvier | Audit |\n| Mars | Formation |\n\n## Conclusion\nMerci !";
$d = ouvre($g->x($md), 'type');
foreach (['[Content_Types].xml', '_rels/.rels', 'ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', 'ppt/slideMasters/slideMaster1.xml', 'ppt/slideLayouts/slideLayout1.xml', 'ppt/theme/theme1.xml', 'ppt/presProps.xml', 'ppt/viewProps.xml', 'ppt/tableStyles.xml', 'docProps/core.xml', 'docProps/app.xml'] as $p) {
    verifie("partie présente : $p", isset($d['parts'][$p]));
}
verifie('toutes les parties XML sont bien formées', ($ko = xmlOk($d['parts'])) === [], implode(', ', $ko));
verifie('4 diapositives (titre, objectifs, calendrier, conclusion)', count($d['slides']) === 4, (string)count($d['slides']));
verifie('chaque diapositive déclarée dans [Content_Types] et presentation.xml', substr_count($d['parts']['[Content_Types].xml'], 'presentationml.slide+xml') === 4 && substr_count($d['parts']['ppt/presentation.xml'], '<p:sldId ') === 4);
verifie('diapositive de titre : titre 44 pt + sous-titre', str_contains($d['slides'][0], 'sz="4400"') && str_contains(textes($d['slides'][0]), 'Plan de formation 2026') && str_contains(textes($d['slides'][0]), 'Équipe IT'));
verifie('puces : • niveau 0, – niveau 1', str_contains($d['slides'][1], 'char="•"') && str_contains($d['slides'][1], 'char="–"') && str_contains($d['slides'][1], 'lvl="1"'));
verifie('**gras** → texte en gras sans astérisques', str_contains($d['slides'][1], 'b="1" dirty="0"') && str_contains(textes($d['slides'][1]), 'nouveaux') && !str_contains(textes($d['slides'][1]), '**'));
verifie('tableau → vrai tableau (a:tbl) de 3 lignes × 2 colonnes, séparateur écarté', str_contains($d['slides'][2], '<a:tbl>') && substr_count($d['slides'][2], '<a:tr ') === 3 && substr_count($d['slides'][2], '<a:gridCol ') === 2 && !str_contains($d['slides'][2], '---'));
verifie('taille 16:9', str_contains($d['parts']['ppt/presentation.xml'], '<p:sldSz cx="12192000" cy="6858000"/>'));
// 2. Débordement → (suite)
$long = "## Liste\n" . implode("\n", array_map(static fn(int $i): string => "- point $i", range(1, 16)));
$d = ouvre($g->x($long), 'long');
verifie('16 puces → 3 diapositives, titres « (suite) »', count($d['slides']) === 3 && str_contains(textes($d['slides'][1]), 'Liste (suite)'), (string)count($d['slides']));
verifie('aucune puce perdue', substr_count(implode('', array_map('textes', $d['slides'])), 'point ') === 16);
// 3. Arabe, échappement, texte sans titre
$d = ouvre($g->x("# عرض تقديمي\n## النقاط\n- العمل عن بعد\n- A & B <test> \"ok\""), 'arabe');
verifie('arabe : paragraphe rtl="1" aligné à droite', str_contains($d['slides'][0], 'rtl="1"') && str_contains($d['slides'][1], 'algn="r" rtl="1"'));
verifie('& < > " échappés, XML valide', xmlOk($d['parts']) === [] && str_contains(textes($d['slides'][1]), 'A & B <test>'));
$d = ouvre($g->x("Juste une ligne de texte"), 'sans-titre');
verifie('texte sans titre → 1 diapositive, XML valide', count($d['slides']) === 1 && xmlOk($d['parts']) === [] && str_contains(textes($d['slides'][0]), 'Juste une ligne'));
$d = ouvre($g->x(""), 'vide');
verifie('contenu vide → 1 diapositive « Présentation », pas d\'erreur', count($d['slides']) === 1 && xmlOk($d['parts']) === []);
$d = ouvre($g->x("## Code\n```\n# pas un titre\n- pas une puce\n```"), 'code');
verifie('bloc de code : « # » dedans ne crée pas de diapositive', count($d['slides']) === 1);
verifie('ids de forme uniques par diapositive', (function () use ($g): bool { $d = ouvre($g->x("## A\n- x\n| a | b |\n|---|---|\n| 1 | 2 |\n- y"), 'ids'); preg_match_all('~cNvPr id="(\d+)"~', $d['slides'][0], $m); return count($m[1]) === count(array_unique($m[1])); })());
verifie('câblage : create_file .pptx, convert_file pptx, plus refusé', str_contains($source, "=== 'pptx') {\n            try { \$content = \$this->buildPptx(") && str_contains($source, "CONVERT_TARGETS = ['pdf', 'docx', 'xlsx', 'pptx'") && !preg_match("~noTextBuilder = \[[^\]]*'pptx'~", $source));
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
