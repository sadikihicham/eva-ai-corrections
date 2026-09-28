<?php
/**
 * Test du générateur Excel (buildXlsx) : tableaux Markdown (28/09 : le modèle envoie « intro + | a | b | » et chaque
 * ligne tombait dans une seule cellule). Même principe que les autres tests : la VRAIE méthode est extraite de
 * src/ActionExecutor.php et chargée par eval (code de NOTRE dépôt uniquement). Aucune écriture hors fichier temporaire.
 * Usage : php tests/test_xlsx.php src/ActionExecutor.php   (ou source en base64 dans ACTIONEXECUTOR_B64)
 */
set_error_handler(function (int $n, string $m): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m\n"; $echecs = ($echecs ?? 0) + 1; return true; });
$source = getenv('ACTIONEXECUTOR_B64') !== false ? base64_decode(getenv('ACTIONEXECUTOR_B64')) : file_get_contents($argv[1] ?? '');
$debut = strpos($source, 'private function buildXlsx(');
$ouv = strpos($source, '{', $debut); $niv = 0;
for ($i = $ouv; ; $i++) { if ($source[$i] === '{') $niv++; elseif ($source[$i] === '}' && --$niv === 0) break; }
eval('class XlsxSousTest { public function x(string $t): string { return $this->buildXlsx($t); } ' . substr($source, $debut, $i - $debut + 1) . ' }');
$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : "\n     → $d") . "\n"; if (!$ok) $echecs++; }
function lignes(string $xlsx): array {
    $f = tempnam(sys_get_temp_dir(), 'tx'); file_put_contents($f, $xlsx);
    $z = new ZipArchive(); $z->open($f); $xml = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); unlink($f);
    preg_match_all('~<row r="\d+">(.*?)</row>~s', $xml, $rows);
    return array_map(static fn(string $r): array => array_map('html_entity_decode', preg_match_all('~<t>(.*?)</t>~s', $r, $c) ? $c[1] : []), $rows[1]);
}
$g = new XlsxSousTest();
// 1. cas réel : intro + tableau Markdown
$l = lignes($g->x("Les données du tableau des 4 employés peuvent être organisées comme suit dans un fichier Excel :\n\n| Nom de l'employé | Mois 1 | Mois 2 |\n|------------------|--------|--------|\n| Employé 1 | 10 | 12 |\n| Employé 2 | 8 | 9 |"));
verifie('tableau Markdown → 3 lignes (en-tête + 2), intro et séparateur écartés', count($l) === 3, json_encode($l, JSON_UNESCAPED_UNICODE));
verifie('cellules séparées : « Nom de l\'employé » | « Mois 1 » | « Mois 2 »', ($l[0] ?? []) === ["Nom de l'employé", 'Mois 1', 'Mois 2'], json_encode($l[0] ?? null, JSON_UNESCAPED_UNICODE));
verifie('données : Employé 1 | 10 | 12', ($l[1] ?? []) === ['Employé 1', '10', '12'], json_encode($l[1] ?? null, JSON_UNESCAPED_UNICODE));
// 2. CSV classique inchangé
$l = lignes($g->x("Date,Heure,Titre\n2026-09-29,06:00,hicham"));
verifie('CSV inchangé : 2 lignes × 3 cellules', count($l) === 2 && $l[1] === ['2026-09-29', '06:00', 'hicham'], json_encode($l));
// 3. tabulations inchangées
$l = lignes($g->x("a\tb\n1\t2"));
verifie('tabulations inchangées', $l === [['a', 'b'], ['1', '2']], json_encode($l));
// 4. ligne « | - | - | » gardée hors séparateur ? (ici : toute ligne 100 % tirets = séparateur, comportement voulu pour Excel)
$l = lignes($g->x("| a | b |\n|:--|--:|\n| x \\| y | z |"));
verifie('séparateur aligné :--/--: écarté, \\| dans une cellule gardé', $l === [['a', 'b'], ['x | y', 'z']], json_encode($l));
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
