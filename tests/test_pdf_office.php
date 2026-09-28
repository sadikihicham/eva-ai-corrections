<?php
/**
 * Test d'INTÉGRATION du PDF en arabe / ourdou (ActionExecutor::pdfViaOffice, branche pdf-arabe, 28/09/2026).
 *
 * Les VRAIES méthodes pdfViaOffice() et buildDocx() sont extraites de src/ActionExecutor.php et exécutées DANS le
 * conteneur Nextcloud (lib/base.php chargé) : la conversion passe par le vrai Collabora, via le client de
 * richdocuments. Rien n'est écrit dans les fichiers des utilisateurs : les PDF produits vont dans un dossier
 * temporaire, relus avec pdftotext (poppler, image nextcloud-pdf), puis effacés.
 * Partie statique (sans serveur) : le branchement dans createFile() et les messages au modèle.
 *
 * Usage (dans le conteneur app, source sur l'entrée standard) : php tests/test_pdf_office.php php://stdin
 */

// Tout affichage est retenu jusqu'au chargement de Nextcloud : lib/Config.php refuse de démarrer (« Config file has
// leading content ») dès que headers_sent() est vrai, ce qui arrive en ligne de commande au premier echo.
ob_start();
set_error_handler(function (int $n, string $m, string $f = '', int $l = 0): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m (ligne $l)\n"; $echecs = ($echecs ?? 0) + 1; return true; });

$source = file_get_contents($argv[1] ?? '');
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

$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $detail = ''): void {
    global $echecs, $total; $total++;
    echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) $echecs++;
}

// ---- Partie statique : branchement et messages -------------------------------------------------------------
$create = extraire($source, 'createFile');
verifie('createFile : texte non latin → pdfViaOffice', str_contains($create, '$office = $this->pdfViaOffice($content);'));
verifie('createFile : quelques lettres non latines (« ? ») → aussi par Office, avertissement seulement si Office manque',
    str_contains($create, 'if ($latin !== null && $replaced === 0) {') && str_contains($create, 'were replaced by "?"'));
verifie('createFile : Office absent + texte non latin → refus clair, rien créé, .docx proposé',
    str_contains($create, 'Office did not answer. Nothing was created. Offer a .docx file instead'));
verifie('description de create_file : PDF arabe/ourdou annoncé', str_contains($source, '.pdf also works in Arabic and Urdu'));
verifie('plus d\'annonce « Latin-script text only » au modèle', !str_contains($source, 'Latin-script text only'));

// ---- Partie intégration : vrai Collabora ---------------------------------------------------------------------
if (!is_file('/var/www/html/lib/base.php')) {
    ob_end_flush();
    echo "(intégration sautée : pas dans le conteneur Nextcloud)\n";
} else {
    // Le gestionnaire d'alertes ci-dessus AFFICHE : pendant la lecture de config.php, Nextcloud prendrait ce texte pour
    // du « contenu avant <?php » et refuserait de démarrer. Il est suspendu le temps du chargement.
    restore_error_handler();
    require '/var/www/html/lib/base.php';
    \OC_App::loadApps();
    ob_end_flush();
    set_error_handler(function (int $n, string $m, string $f = '', int $l = 0): bool { global $echecs; if ($n & (E_DEPRECATED | E_USER_DEPRECATED)) return true; echo "❌ AVERTISSEMENT PHP : $m (ligne $l)\n"; $echecs++; return true; });
    // eval() ne charge que du code extrait de NOTRE fichier versionné src/ActionExecutor.php.
    $corps = extraire($source, 'pdfViaOffice') . "\n" . extraire($source, 'buildDocx');
    eval('use OCP\Server; class PdfOfficeSousTest { public bool $docxPlain = false;
        public function pdf(string $t): ?string { return $this->pdfViaOffice($t); } ' . $corps . ' }');
    $t = new PdfOfficeSousTest();
    $dir = sys_get_temp_dir() . '/test-pdf-office-' . getmypid();
    @mkdir($dir, 0700);
    $lire = static function (string $pdf) use ($dir): string {
        file_put_contents("$dir/f.pdf", $pdf);
        $txt = (string)shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg("$dir/f.pdf") . ' - 2>/dev/null');
        // Formes de présentation arabes (ﻣ…) → lettres de base, pour comparer au texte d'origine.
        return class_exists(\Normalizer::class) ? (string)\Normalizer::normalize($txt, \Normalizer::FORM_KC) : $txt;
    };
    $cas = [
        'arabe' => ["# تقرير الاجتماع\n\nناقش الفريق **الميزانية** السنوية.\n\n- النقطة الأولى\n- النقطة الثانية\n\n| البند | المبلغ |\n|---|---|\n| الرواتب | 1200 |",
            ['تقرير', 'الاجتماع', 'الميزانية', 'النقطة', 'الرواتب', '1200']],
        'ourdou' => ["# سالانہ رپورٹ\n\nیہ ایک مثال ہے۔ ٹیم نے بجٹ پر بات کی۔",
            ['سالانہ', 'رپورٹ', 'مثال', 'ٹیم']],
        'mixte (français + mots arabes)' => ["# Compte rendu\n\nLe client de Dubaï (دبي) a validé le devis. Référence : عقد رقم ٧.",
            ['Compte rendu', 'Dubaï', 'دبي', 'validé']],
    ];
    foreach ($cas as $nom => [$texte, $attendus]) {
        $t0 = microtime(true);
        $pdf = $t->pdf($texte);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        verifie("PDF $nom produit par Office ($ms ms)", is_string($pdf) && str_starts_with($pdf, '%PDF-'), var_export(is_string($pdf) ? substr($pdf, 0, 20) : $pdf, true));
        if (!is_string($pdf)) continue;
        $lu = $lire($pdf);
        // pdftotext rend la ligature lam-alif (لا, لأ) inversée (« االجتماع ») et coupe l'ourdou en lettres isolées
        // (« سالان ہ ») alors que le rendu est correct (vérifié à l'image le 28/09) : comparaison sans espaces ni
        // marques de sens, ligature lam-alif ramenée à la même forme des deux côtés.
        $norm = static fn(string $v): string => (string)preg_replace('/ل([اأإآ])/u', '$1ل', (string)preg_replace('/[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $v));
        $luN = $norm($lu);
        $manque = array_values(array_filter($attendus, static fn(string $m): bool => !str_contains($luN, $norm($m))));
        verifie("PDF $nom : texte relu intact (" . count($attendus) . ' mots)', $manque === [], 'absents : ' . implode(', ', $manque) . ' | relu : ' . mb_substr(str_replace("\n", ' ¶ ', $lu), 0, 200));
        verifie("PDF $nom : aucun « ? » de remplacement", !str_contains($lu, '?'));
    }
    // Tableau arabe : colonnes inversées dans le .docx (1re colonne à droite).
    $docx = (new ReflectionMethod($t, 'buildDocx'))->invoke($t, "| البند | المبلغ |\n|---|---|\n| الرواتب | 1200 |", true);
    $z = new ZipArchive(); $f = tempnam(sys_get_temp_dir(), 'dx'); file_put_contents($f, $docx); $z->open($f); $doc = (string)$z->getFromName('word/document.xml'); $z->close(); unlink($f);
    verifie('tableau arabe : bidiVisual (1re colonne à droite) et cellules en bidi', str_contains($doc, '<w:tblPr><w:bidiVisual/><w:tblW') && substr_count($doc, '<w:pPr><w:bidi/><w:spacing w:before="40"') === 3);   // 3 cellules en lettres arabes ; « 1200 » n'a pas de lettre
    $docx = (new ReflectionMethod($t, 'buildDocx'))->invoke($t, "| Poste | Montant |\n|---|---|\n| Salaires | 1200 |", true);
    $z = new ZipArchive(); $f = tempnam(sys_get_temp_dir(), 'dx'); file_put_contents($f, $docx); $z->open($f); $doc = (string)$z->getFromName('word/document.xml'); $z->close(); unlink($f);
    verifie('tableau français : ni bidiVisual ni bidi', !str_contains($doc, 'bidiVisual') && !str_contains($doc, '<w:bidi/>'));
    // Contrôle négatif : une conversion qui ne rend pas un PDF est rejetée (null), jamais écrite.
    verifie('pdfViaOffice rejette une réponse qui n\'est pas un PDF', str_contains(extraire($source, 'pdfViaOffice'), "str_starts_with(\$pdf, '%PDF-') ? \$pdf : null"));
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
}

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
