<?php
/**
 * Test unitaire du générateur PDF d'eva (ActionExecutor::buildPdf, branche anti-invention).
 *
 * Teste le VRAI code : la méthode buildPdf() est extraite telle quelle de src/ActionExecutor.php (pas recopiée
 * à la main), puis chargée dans une classe de test minimale. Aucune dépendance à Nextcloud, aucune écriture
 * (sauf si PDF_OUT est défini) : calcul pur.
 *
 * Vérifie pour chaque PDF : en-tête %PDF-1.4, table xref (chaque offset pointe sur « N 0 obj »), startxref,
 * trailer /Size /Root, /Length exact de chaque flux, décompression gzuncompress, texte attendu dans les flux,
 * et qu'aucune ligne ne dépasse la marge droite (largeurs AFM relues dans la source).
 *
 * Usage : php tests/test_pdf.php src/ActionExecutor.php
 *     ou (sans PHP local) : source passée en base64 dans ACTIONEXECUTOR_B64, script lu sur stdin.
 * Récupération des PDF produits :
 *     PDF_OUT=/un/dossier  → écrit chaque PDF dans ce dossier ;
 *     PDF_B64=1            → imprime « === nom.pdf === » puis le base64 de chaque PDF, et « === FIN === »
 *                            (à découper ensuite avec tests/verif_pdf.py --extraire).
 */

// Toute alerte PHP (regex invalide, index absent…) compte comme un ÉCHEC : le 28/09, une regex cassée passait
// silencieusement un test « par accident ».
set_error_handler(function (int $n, string $m, string $f = '', int $l = 0): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m (ligne $l)\n"; $echecs = ($echecs ?? 0) + 1; return true; });

$source = getenv('ACTIONEXECUTOR_B64') !== false ? base64_decode(getenv('ACTIONEXECUTOR_B64')) : file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') { fwrite(STDERR, "source ActionExecutor.php introuvable\n"); exit(2); }

function extraire(string $src, string $methode): string {
    $debut = strpos($src, 'private function ' . $methode . '(');
    if ($debut === false) throw new RuntimeException("méthode absente : $methode");
    $ouvrante = strpos($src, '{', $debut);
    $niveau = 0;
    for ($i = $ouvrante, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $niveau++;
        elseif ($src[$i] === '}' && --$niveau === 0) return substr($src, $debut, $i - $debut + 1);
    }
    throw new RuntimeException("fin de méthode introuvable : $methode");
}

// eval() ne charge ici que du code extrait de NOTRE fichier src/ActionExecutor.php (dépôt versionné), jamais de
// données extérieures : c'est ce qui permet de tester le vrai code sans le recopier ni démarrer Nextcloud.
// buildPdf() est autonome (closures internes uniquement) : une seule méthode à extraire.
$corps = extraire($source, 'buildPdf');
eval('class PdfSousTest {
    public function pdf(string $t): string { return $this->buildPdf($t); }
    public function remplaces(string $t): int { $n = 0; $this->buildPdf($t, $n); return (int)$n; }
    ' . $corps . '
}');

// Largeurs AFM relues dans la source (mêmes tables que le code testé) pour contrôler la marge droite.
preg_match_all("/\\\$afm\\('([0-9,]+)'\\)/", $corps, $tables);
$largeurs = [];
foreach (['F1', 'F2'] as $k => $police) {
    $valeurs = array_map('intval', explode(',', $tables[1][$k] ?? ''));
    $largeurs[$police] = array_merge(array_fill(0, 32, 0), $valeurs);
}
$largeurs['F3'] = array_fill(0, 256, 600);

$echecs = 0;
$produits = [];
function verifie(string $nom, bool $ok, string $detail = ''): void {
    global $echecs;
    echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok || $detail === '' ? '' : "\n     → " . $detail) . "\n";
    if (!$ok) $echecs++;
}

/**
 * Analyse structurelle d'un PDF produit. Retourne ['erreurs' => [...], 'pages' => int, 'flux' => string (tous
 * les flux de contenu décompressés), 'texte' => string (UTF-8, une ligne par bloc BT…ET), 'debord' => float].
 */
function analyse(string $pdf): array {
    global $largeurs;
    $err = [];
    if (!str_starts_with($pdf, "%PDF-1.4\n")) $err[] = 'en-tête %PDF-1.4 absent';
    if (!str_ends_with($pdf, "%%EOF\n")) $err[] = '%%EOF final absent';
    if (!preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m)) return ['erreurs' => array_merge($err, ['startxref illisible']), 'pages' => 0, 'flux' => '', 'texte' => '', 'debord' => 0.0];
    $xref = (int)$m[1];
    if (substr($pdf, $xref, 5) !== "xref\n") $err[] = "startxref ($xref) ne pointe pas sur « xref »";
    if (!preg_match('/\Gxref\n0 (\d+)\n/', $pdf, $m, 0, $xref)) return ['erreurs' => array_merge($err, ['en-tête xref illisible']), 'pages' => 0, 'flux' => '', 'texte' => '', 'debord' => 0.0];
    $taille = (int)$m[1];
    $pos = $xref + strlen($m[0]);
    if (substr($pdf, $pos, 20) !== "0000000000 65535 f \n") $err[] = 'entrée 0 de la xref incorrecte';
    $objets = [];
    for ($id = 1; $id < $taille; $id++) {
        $entree = substr($pdf, $pos + 20 * $id, 20);
        if (!preg_match('/^(\d{10}) 00000 n \n$/', $entree, $e)) { $err[] = "entrée xref $id mal formée"; continue; }
        $off = (int)$e[1];
        $tete = $id . " 0 obj\n";
        if (substr($pdf, $off, strlen($tete)) !== $tete) { $err[] = "offset de l'objet $id ($off) ne pointe pas sur « $id 0 obj »"; continue; }
        $fin = strpos($pdf, "\nendobj\n", $off);
        $objets[$id] = substr($pdf, $off + strlen($tete), $fin - $off - strlen($tete));
    }
    $apres = $pos + 20 * $taille;
    if (substr($pdf, $apres, 8) !== "trailer\n") $err[] = 'trailer absent juste après la xref';
    if (!preg_match('/trailer\n<< \/Size (\d+) \/Root (\d+) 0 R/', $pdf, $t) || (int)$t[1] !== $taille) $err[] = 'trailer /Size incohérent';
    elseif (!str_contains($objets[(int)$t[2]] ?? '', '/Type /Catalog')) $err[] = '/Root ne désigne pas le catalogue';
    $pages = preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $objets[2] ?? '', $c) ? (int)$c[1] : 0;
    if (substr_count($pdf, '/Type /Page ') !== $pages) $err[] = 'nombre d\'objets /Page ≠ /Count';
    $flux = ''; $texte = ''; $debord = 0.0;
    foreach ($objets as $id => $corps) {
        if (!preg_match('/^<< \/Length (\d+)( \/Filter \/FlateDecode)? >>\nstream\n/', $corps, $s)) continue;
        $donnees = substr($corps, strlen($s[0]), (int)$s[1]);
        if (substr($corps, strlen($s[0]) + (int)$s[1]) !== "\nendstream") { $err[] = "/Length faux pour l'objet $id"; continue; }
        $clair = isset($s[2]) && $s[2] !== '' ? @gzuncompress($donnees) : $donnees;
        if (!is_string($clair)) { $err[] = "flux $id non décompressable"; continue; }
        $flux .= $clair;
        // Texte + contrôle de débordement : chaque bloc « BT … Td … ET » est une ligne posée à x.
        preg_match_all('/BT (?:\/(F\d) ([\d.]+) Tf )?([\d.]+) ([\d.]+) Td(.*?) ET\n/', $clair, $blocs, PREG_SET_ORDER);
        foreach ($blocs as $b) {
            $x = (float)$b[3]; $police = $b[1]; $corpsPt = (float)$b[2]; $largeur = 0.0; $ligne = '';
            preg_match_all('/(?: \/(F\d) ([\d.]+) Tf)? \(((?:\\\\.|[^\\\\()])*)\) Tj/s', $b[5], $runs, PREG_SET_ORDER);
            foreach ($runs as $r) {
                if ($r[1] !== '') { $police = $r[1]; $corpsPt = (float)$r[2]; }
                $brut = preg_replace('/\\\\(.)/s', '$1', $r[3]);
                $ligne .= $brut;
                for ($i = 0, $n = strlen($brut); $i < $n; $i++) $largeur += $largeurs[$police][ord($brut[$i])] * $corpsPt / 1000;
            }
            $debord = max($debord, $x + $largeur - (595 - 56));
            $texte .= mb_convert_encoding($ligne, 'UTF-8', 'Windows-1252') . "\n";
        }
    }
    return ['erreurs' => $err, 'pages' => $pages, 'flux' => $flux, 'texte' => $texte, 'debord' => $debord];
}

$g = new PdfSousTest();
function produit(string $nom, string $entree): array {
    global $g, $produits;
    $debut = microtime(true);
    $pdf = $g->pdf($entree);
    $duree = microtime(true) - $debut;
    $produits[$nom] = $pdf;
    $a = analyse($pdf);
    $a['duree'] = $duree;
    $a['pdf'] = $pdf;
    verifie("$nom : structure PDF valide (xref, startxref, trailer, /Length, flux)", $a['erreurs'] === [], implode(' ; ', $a['erreurs']));
    verifie("$nom : aucune ligne ne dépasse la marge droite", $a['debord'] <= 0.01, 'débord de ' . round($a['debord'], 2) . ' pt');
    return $a;
}

// 1. texte simple
$a = produit('01-simple.pdf', "Bonjour le monde.\nDeuxième ligne.");
verifie('texte simple → 1 page, lignes et pied « 1 / 1 » présents', $a['pages'] === 1
    && str_contains($a['texte'], "Bonjour le monde.\n") && str_contains($a['texte'], "Deuxième ligne.\n") && str_contains($a['texte'], "1 / 1\n"), $a['texte']);

// 2. Markdown complet
$md = "# Titre principal\n\nIntro avec **mot important** ici.\n\n## Section deux\n- puce un\n* puce deux\n  - sous-puce\n1. premier\n2) second\n\n"
    . "### Tableau\n| Nom | Valeur |\n|---|---:|\n| Alpha | 10 |\n| **Bêta** | 2000 |\n\n```\n\$x = foo(1);\n    indenté\n```\n---\nVoir [le site](https://exemple.fr/page).\nFin.";
$a = produit('02-markdown.pdf', $md);
$f = $a['flux'];
verifie('titre # → Helvetica-Bold 18 pt, sans « # »', str_contains($f, '/F2 18.00 Tf (Titre principal) Tj') && !str_contains($a['texte'], '#'), $f);
verifie('titres ## / ### → 15 pt / 13 pt', str_contains($f, '/F2 15.00 Tf (Section deux) Tj') && str_contains($f, '/F2 13.00 Tf (Tableau) Tj'));
verifie('**gras** → vrai gras, marqueurs retirés', str_contains($f, '/F2 11.00 Tf (mot important) Tj') && !str_contains($a['texte'], '**'), $f);
verifie('puces - et * → « • » (0x95) + texte, sous-puce indentée', substr_count($f, "(\x95) Tj") === 3 && str_contains($a['texte'], "puce un\n") && str_contains($a['texte'], "sous-puce\n"), $a['texte']);
verifie('listes numérotées 1. et 2) conservent leur numéro', str_contains($f, '(1.) Tj') && str_contains($f, '(2\)) Tj') && str_contains($a['texte'], "premier\n"));
verifie('tableau → Courier aligné, ligne |---| ignorée, gras retiré', str_contains($f, '/F3 9.00 Tf (Nom   | Valeur) Tj') && str_contains($a['texte'], "Alpha | 10\n")
    && str_contains($a['texte'], "Bêta  | 2000\n") && str_contains($a['texte'], "------+-------\n") && !str_contains($a['texte'], '|---'), $a['texte']);
verifie('bloc ``` → Courier, indentation conservée, sans les ```', str_contains($a['texte'], "\$x = foo(1);\n") && str_contains($a['texte'], "    indenté\n") && !str_contains($a['texte'], '```'), $a['texte']);
verifie('--- → filet horizontal', str_contains($f, ' l S 0 G'));
verifie('lien Markdown → « texte (url) »', str_contains($a['texte'], 'Voir le site (https://exemple.fr/page).'), $a['texte']);

// 3. accents français → octets WinAnsi exacts
$a = produit('03-accents.pdf', "é è à ç œ € — « déjà » Œuvre ÿ ™ …");
verifie('accents → octets WinAnsi (é=E9 è=E8 à=E0 ç=E7 œ=9C €=80)', str_contains($a['flux'], "(\xE9 \xE8 \xE0 \xE7 \x9C \x80 \x97 \xAB d\xE9j\xE0 \xBB \x8Cuvre \xFF \x99 \x85) Tj"), bin2hex($a['flux']));
verifie('accents → relus à l\'identique', str_contains($a['texte'], "é è à ç œ € — « déjà » Œuvre ÿ ™ …\n"), $a['texte']);

// 4. texte long → au moins 3 pages, pied « 1 / N » … « N / N »
$long = '';
for ($i = 1; $i <= 150; $i++) $long .= "Paragraphe $i : le rapport mensuel décrit l'avancement des travaux, les risques identifiés et les prochaines étapes du projet.\n\n";
$a = produit('04-long.pdf', $long);
$n = $a['pages'];
verifie("texte long → $n pages (≥ 3), pieds 1 / $n et $n / $n", $n >= 3 && str_contains($a['texte'], "1 / $n\n") && str_contains($a['texte'], "$n / $n\n")
    && str_contains($a['texte'], 'Paragraphe 150 :'), "pages=$n");

// 5. parenthèses et antislash échappés
$a = produit('05-echappement.pdf', "Chemin C:\\dossier\\fichier (copie) et sourire :) fin \\");
verifie('( ) \\ échappés dans le flux', str_contains($a['flux'], '(Chemin C:\\\\dossier\\\\fichier \\(copie\\) et sourire :\\) fin \\\\) Tj'), $a['flux']);
verifie('( ) \\ relus à l\'identique', str_contains($a['texte'], "Chemin C:\\dossier\\fichier (copie) et sourire :) fin \\\n"), $a['texte']);

// 6. ligne très longue sans espace (URL) → coupée, rien perdu, rapide
$url = 'https://exemple.fr/' . str_repeat('abcdefghij', 300);
$a = produit('06-url-longue.pdf', "Lien : $url fin");
$sansPied = preg_replace('/^\d+ \/ \d+\n/m', '', $a['texte']);
// « Lien : » tient sur la 1re ligne, l'URL est coupée caractère par caractère, « fin » suit sur la dernière
// ligne (avec son espace) ou passe seul à la ligne suivante : les deux recollages sont justes.
$recolle = str_replace("\n", '', $sansPied);
verifie('URL de 3 019 caractères → coupée sur plusieurs lignes sans perte', substr_count($sansPied, "\n") > 20
    && in_array($recolle, ["Lien :{$url} fin", "Lien :{$url}fin"], true), substr($sansPied, 0, 300));
verifie('URL longue → moins d\'1 s', $a['duree'] < 1.0, round($a['duree'], 3) . ' s');

// 7. arabe → exception claire, pas de PDF de « ? »
try { $g->pdf("مرحبا بكم في التقرير الشهري"); verifie('texte arabe → exception non-Latin', false, 'aucune exception'); }
catch (\Throwable $e) { verifie('texte arabe → exception « non-Latin text »', $e->getMessage() === 'non-Latin text', $e->getMessage()); }
try { $g->pdf("Report\nОтчёт о работе за месяц"); verifie('cyrillique → exception non-Latin', false, 'aucune exception'); }
catch (\Throwable $e) { verifie('cyrillique (> 5 lettres) → exception « non-Latin text »', $e->getMessage() === 'non-Latin text', $e->getMessage()); }

// 8. quelques lettres isolées (≤ 5) → tolérées, remplacées par « ? »
$a = produit('08-isole.pdf', "Constante α = 0,05 et β = 0,2");
verifie('2 lettres grecques isolées → pas d\'exception, « ? »', str_contains($a['texte'], 'Constante ? = 0,05 et ? = 0,2'), $a['texte']);

// 9. emoji et symboles → retirés / remplacés, sans exception
$a = produit('09-emoji.pdf', "Bravo 🎉 c'est fini ✅\nÉtape 1 → étape 2 ❌\nCO₂ ≤ 5 👍🏽");
verifie('emoji retirés, ✅ → OK, ❌ → X, → → ->, ₂ → 2, ≤ → <=', str_contains($a['texte'], "Bravo c'est fini OK\n") && str_contains($a['texte'], "Étape 1 -> étape 2 X\n")
    && str_contains($a['texte'], "CO2 <= 5\n") && !str_contains($a['texte'], '?'), $a['texte']);

// 10. vide / blancs → PDF valide d'une page vide (createFile refuse déjà le contenu vide en amont)
$a = produit('10-vide.pdf', '');
verifie('entrée vide → 1 page valide (pied seul)', $a['pages'] === 1 && $a['texte'] === "1 / 1\n", $a['texte']);
$a = produit('10b-blancs.pdf', " \n\n\t \r\n ");
verifie('entrée faite de blancs → 1 page valide', $a['pages'] === 1 && $a['texte'] === "1 / 1\n", $a['texte']);

// 11. fins de ligne \r\n et \r, tabulations, UTF-8 invalide
$a = produit('11-fins-de-ligne.pdf', "ligne1\r\nligne2\rligne3\tcol\xFF\xFEfin");
// La tabulation devient 4 espaces, réduits à un seul dans le texte proportionnel ; les 2 octets invalides → « ?? ».
verifie('\r\n, \r → lignes séparées, tabulation → espace, UTF-8 invalide sans alerte', !str_contains($a['flux'], "\r") && str_contains($a['texte'], "ligne1\n")
    && str_contains($a['texte'], "ligne2\n") && preg_match('/^ligne3 col\?+fin$/m', $a['texte']) === 1, $a['texte']);

// 12. 100 000 caractères (limite de createFile) → temps raisonnable
$gros = '';
while (strlen($gros) < 100000) $gros .= "## Section\nTexte **important** avec des accents é à ç, une liste :\n- élément un\n- élément deux\n| a | b |\n|---|---|\n| 1 | 2 |\n\n";
$gros = substr($gros, 0, 100000);
$a = produit('12-100k.pdf', $gros);
verifie("100 000 caractères → {$a['pages']} pages en " . round($a['duree'], 2) . ' s (< 5 s)', $a['duree'] < 5.0 && $a['pages'] > 20);
$ligneUnique = str_repeat('mot ', 25000);
$a = produit('12b-100k-une-ligne.pdf', $ligneUnique);
verifie("100 000 caractères sur une seule ligne → {$a['pages']} pages en " . round($a['duree'], 2) . ' s (< 5 s)', $a['duree'] < 5.0 && $a['pages'] > 5);

// 13. déterminisme
verifie('même entrée → mêmes octets (pas de date)', $g->pdf($md) === $g->pdf($md));

// 14. tables AFM : 224 valeurs par police, quelques valeurs Adobe de référence
verifie('tables AFM : 224 largeurs pour Helvetica et Helvetica-Bold', count($largeurs['F1']) === 256 && count($largeurs['F2']) === 256);
verifie('AFM Helvetica : espace 278, A 667, W 944, i 222, € 556 ; Bold : A 722, i 278', $largeurs['F1'][32] === 278 && $largeurs['F1'][65] === 667
    && $largeurs['F1'][87] === 944 && $largeurs['F1'][105] === 222 && $largeurs['F1'][0x80] === 556 && $largeurs['F2'][65] === 722 && $largeurs['F2'][105] === 278);

// 15. Corrections de la revue adverse (28/09)
// 15.1 tableau à très nombreuses colonnes : refus propre au lieu d'épuiser la mémoire
try { $g->pdf(str_repeat('|', 50000) . "\n" . str_repeat("|a\n", 1000)); verifie('tableau de 50 000 colonnes → exception', false, 'aucune exception'); }
catch (\Throwable $e) { verifie('tableau de 50 000 colonnes → exception « too many columns », sans erreur mémoire', str_contains($e->getMessage(), 'too many columns'), $e->getMessage()); }
$large = "| " . str_repeat('x', 300) . " | b |\n| c | d |";
$a = produit('16-tableau-large.pdf', $large);
verifie('tableau trop large pour être aligné → lignes simples, rien de perdu', str_contains(str_replace("\n", '', $a['texte']), str_repeat('x', 100)) && str_contains($a['texte'], 'c | d'), $a['texte']);
// 15.2 ligne qui commence par ``` mais contient du texte : gardée, pas de bascule en mode code
$a = produit('17-backticks.pdf', "```ls -la``` affiche les fichiers\nSuite normale.");
verifie('« ```ls -la``` affiche… » → texte gardé, la suite n\'est pas en Courier', str_contains($a['texte'], 'affiche les fichiers') && str_contains(analyse($a['pdf'])['flux'], '/F1 11.00 Tf (Suite normale.) Tj'), $a['texte']);
$a = produit('18-clotures.pdf', "```python\nx = 1\n~~~ pas une clôture\n```\nAprès.");
verifie('bloc ``` fermé seulement par ``` (pas par ~~~), texte après repassé en Helvetica', str_contains($a['texte'], '~~~ pas une clôture') && str_contains(analyse($a['pdf'])['flux'], '(Apr' . "\xE8" . 's.) Tj'), $a['texte']);
// 15.3 exposants
$a = produit('19-exposants.pdf', "Budget 10⁶ € et x⁴ ; aire 5 m² ; volume 2 m³.");
verifie('10⁶ → 10^6, x⁴ → x^4 (le nombre ne change pas), ² ³ conservés', str_contains($a['texte'], '10^6') && str_contains($a['texte'], 'x^4') && str_contains($a['texte'], 'm²') && str_contains($a['texte'], 'm³'), $a['texte']);
// 15.4 seuil non latin en proportion + compte des remplacements
$rapport = str_repeat("Le coefficient de sécurité est calculé selon la norme en vigueur, voir annexe. ", 20) . "Paramètres : α, β, γ, Δ, λ, σ.";
$a = produit('20-grec-dans-rapport.pdf', $rapport);
verifie('rapport français avec 6 lettres grecques → PDF produit (plus de faux refus)', $a['pages'] >= 1 && str_contains($a['texte'], 'Paramètres'), $a['texte']);
verifie('… et 6 remplacements signalés (pour l\'avertissement renvoyé au modèle)', $g->remplaces($rapport) === 6, (string)$g->remplaces($rapport));
verifie('texte sans caractère étranger → 0 remplacement', $g->remplaces("Bonjour à tous.") === 0);
// 15.5 ligne de données « | - | - | » gardée ; seule la 2e ligne peut être un séparateur
$a = produit('21-tableau-tirets.pdf', "| Poste | Q1 | Q2 |\n|---|---|---|\n| Frais | 10 | 20 |\n| - | - | - |\n| Total | 10 | 20 |");
verifie('ligne « | - | - | - | » conservée, séparateur |---| retiré', preg_match_all('/^-\s*\|\s*-\s*\|\s*-\s*$/m', $a['texte']) === 1 && !str_contains($a['texte'], '---|'), $a['texte']);
// 15.6 \| dans une cellule
$a = produit('22-pipe-echappe.pdf', "| Commande | Rôle |\n|---|---|\n| a \\| b | tube |");
verifie('\\| dans une cellule → « a | b » dans la même colonne', str_contains($a['texte'], 'a | b    | tube'), $a['texte']);
// 15.7 injection : opérateurs PDF dans le texte restent du texte inerte
$a = produit('23-injection.pdf', "Texte ) Tj /JS (app.alert(1)) >> endstream endobj /OpenAction");
verifie('« ) Tj /JS ( … endstream » → chaîne échappée, relue à l\'identique, aucune action PDF', str_contains($a['texte'], 'Texte ) Tj /JS (app.alert(1)) >> endstream endobj /OpenAction')
    && !preg_match('~/(JS|JavaScript|OpenAction|AA|Launch|URI)\b~', substr($a['pdf'], 0, strpos($a['pdf'], 'stream'))), $a['texte']);

// Sortie des PDF produits pour contrôle externe (verif_pdf.py + pdftotext).
$dossier = getenv('PDF_OUT');
if (is_string($dossier) && $dossier !== '') {
    if (!is_dir($dossier)) mkdir($dossier, 0777, true);
    foreach ($produits as $nom => $pdf) file_put_contents(rtrim($dossier, '/') . '/' . $nom, $pdf);
    echo 'PDF écrits dans ' . $dossier . "\n";
}
$total = $echecs;
echo $total === 0 ? "\nRÉSULTAT : tous les contrôles réussis\n" : "\nRÉSULTAT : $total échec(s)\n";
if (getenv('PDF_B64') === '1') {
    foreach ($produits as $nom => $pdf) echo "=== $nom ===\n" . chunk_split(base64_encode($pdf), 76, "\n");
    echo "=== FIN ===\n";
}
exit($total === 0 ? 0 : 1);
