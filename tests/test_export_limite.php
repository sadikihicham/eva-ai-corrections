<?php
/**
 * Export de conversation (01/10) : ActionExecutor::exportConversationFile() doit refuser un
 * contenu trop volumineux AVANT d'appeler buildPdf()/buildDocx()/pdfViaOffice() — c'est le
 * correctif du bloquant « DoS » trouvé par la revue adverse (createFile() avait déjà cette borne
 * via exec_write_max_chars, exportConversationFile() ne l'avait pas au départ).
 * Usage : php tests/test_export_limite.php src/ActionExecutor.php (sans argument : source sur stdin).
 */
$source = file_get_contents($argv[1] ?? 'php://stdin');

function extraireVisibilite(string $src, string $nom): string {
    foreach (['private', 'public'] as $vis) {
        $debut = strpos($src, "$vis function $nom(");
        if ($debut === false) { continue; }
        $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
        for ($i = $ouv, $n = strlen($src); $i < $n; $i++) { if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) return substr($src, $debut, $i - $debut + 1); }
    }
    fwrite(STDERR, "méthode $nom absente\n"); exit(2);
}

// exportConversationFile() appelle buildPdf()/buildDocx() en interne ($this->) : on extrait les 3
// pour que les cas "contenu SOUS la limite" aillent au bout (pas seulement le refus au-dessus).
// pdfViaOffice() n'est volontairement PAS extraite : son chemin (texte arabe + Collabora absent
// dans ce test) n'est jamais emprunté par les cas ci-dessous (contenu latin uniquement).
$corps = extraireVisibilite($source, 'exportConversationFile')
    . extraireVisibilite($source, 'buildPdf')
    . extraireVisibilite($source, 'buildDocx');

// eval() ici est sûr : script de test local uniquement, exécuté manuellement par un développeur sur
// un fichier source du dépôt déjà sous son contrôle — même convention que tests/test_docx.php et
// tests/test_pdf.php existants dans ce dépôt. $config est une classe de test minimale (pas
// AppConfig réel) : exportConversationFile() ne lui demande qu'un ->get('exec_write_max_chars').
eval('
class ConfigDeTest { private $valeur; function __construct($v) { $this->valeur = $v; } function get(string $k): string { return $k === "exec_write_max_chars" ? (string)$this->valeur : ""; } }
class ExportSousTest {
    private $config;
    private $docxPlain = false;
    function __construct($maxChars) { $this->config = new ConfigDeTest($maxChars); }
    ' . $corps . '
    function x(string $markdown, string $format): array { return $this->exportConversationFile($markdown, $format); }
}
');

$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " → $d") . "\n"; if (!$ok) $echecs++; }

// --- Limite respectée (défaut 100000 si la config ne renvoie rien) ---
$g = new ExportSousTest(0); // AppConfig::get() renvoie '' si non réglé -> (int)'' ?: 100000 = 100000
$r = $g->x(str_repeat('a', 100001), 'pdf');
verifie('100001 caractères, limite par défaut 100000 → refusé', $r['ok'] === false && str_contains($r['error'], '100000'), json_encode($r));
$r = $g->x(str_repeat('a', 100000), 'pdf');
verifie('exactement 100000 caractères (limite incluse) → accepté, PDF généré', $r['ok'] === true && str_starts_with($r['content'], '%PDF-'), json_encode(array_diff_key($r, ['content' => 1])));

// --- Limite custom (config explicitement réglée) ---
$g = new ExportSousTest(50);
$r = $g->x(str_repeat('a', 51), 'pdf');
verifie('limite custom 50, contenu 51 → refusé AVANT buildPdf (pas de PDF renvoyé)', $r['ok'] === false && !isset($r['content']) && str_contains($r['error'], '50'), json_encode($r));
$r = $g->x(str_repeat('a', 50), 'docx');
verifie('limite custom 50, contenu 50, format docx → accepté, DOCX généré (ZIP, signature PK)', $r['ok'] === true && str_starts_with($r['content'], 'PK'), json_encode(array_diff_key($r, ['content' => 1])));

// --- La limite s'applique AVANT la validation du format (ordre réel du code, pas supposé) ---
$g = new ExportSousTest(10);
$r = $g->x(str_repeat('a', 11), 'format-inconnu');
verifie('contenu trop long même avec un format invalide → erreur de TAILLE, pas de FORMAT (prouve l\'ordre des vérifications)', $r['ok'] === false && str_contains($r['error'], '10 characters'), json_encode($r));

// --- mb_strlen, pas strlen : un caractère multi-octets ne doit pas compter plusieurs fois contre la limite ---
$g = new ExportSousTest(5);
$r = $g->x(str_repeat('é', 5), 'docx'); // 5 caractères UTF-8, 10 octets en UTF-8 (é = 2 octets)
verifie('mb_strlen (caractères) et non strlen (octets) : 5 "é" sous une limite de 5 → accepté', $r['ok'] === true, json_encode(array_diff_key($r, ['content' => 1])));

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
