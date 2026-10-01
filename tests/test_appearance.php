<?php
/**
 * Personnalisation par conversation (01/10) : ChatStore::sanitizeAppearance() ne doit jamais
 * laisser passer une valeur non sûre, et les 3 points d'entrée (list/get/setMeta) doivent tous
 * s'appuyer dessus — en particulier get(), dont l'absence de sanitisation était le bloquant B1
 * trouvé par la revue adverse (seul chemin où le JS fait confiance au serveur sans filtrage de
 * plus côté client).
 * Usage : php tests/test_appearance.php app/lib/Service/ChatStore.php (sans argument : source sur stdin).
 */
$source = file_get_contents($argv[1] ?? 'php://stdin');

function extraire(string $src, string $nom): string {
    $debut = strpos($src, 'private function ' . $nom . '(');
    if ($debut === false) { fwrite(STDERR, "méthode $nom absente\n"); exit(2); }
    $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
    for ($i = $ouv, $n = strlen($src); $i < $n; $i++) { if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) return substr($src, $debut, $i - $debut + 1); }
    exit(2);
}

// sanitizeAppearance() s'appuie sur 3 constantes de classe (whitelist/bornes) : on les récupère
// textuellement plutôt que de les redéfinir en dur ici, pour que le test échoue si elles changent
// sans que ce fichier soit mis à jour en conséquence.
preg_match('~private const APPEARANCE_FONTS = .*?;~', $source, $mf) || exit(2);
preg_match('~private const APPEARANCE_MIN_SIZE = \d+;~', $source, $mmin) || exit(2);
preg_match('~private const APPEARANCE_MAX_SIZE = \d+;~', $source, $mmax) || exit(2);
$constantes = $mf[0] . $mmin[0] . $mmax[0];

// eval() ici est sûr : script de test local uniquement, exécuté manuellement par un développeur sur
// un fichier source du dépôt déjà sous son contrôle (jamais sur une entrée réseau/utilisateur) — même
// convention que tests/test_docx.php et tests/test_pdf.php existants dans ce dépôt.
eval('class AppearanceSousTest { ' . $constantes . extraire($source, 'sanitizeAppearance') . ' public function x(array $raw): array { return $this->sanitizeAppearance($raw); } }');

$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " → $d") . "\n"; if (!$ok) $echecs++; }

$g = new AppearanceSousTest();

// --- Couleurs : seul le hex strict 6 chiffres passe ---
$r = $g->x(['bgColor' => '#FfA500']);
verifie('hex valide (casse mixte) → en minuscules', $r['bgColor'] === '#ffa500', $r['bgColor']);

foreach (['red', '#12345', '#gggggg', 'rgb(0,0,0)', '#ffffff;background:url(x)', "#ffffff\n", '#fff', ''] as $mauvais) {
    $r = $g->x(['bgColor' => $mauvais, 'textColor' => $mauvais, 'bubbleColor' => $mauvais]);
    verifie("couleur invalide rejetée (silencieusement, pas d'exception) : " . addcslashes($mauvais, "\n"),
        $r['bgColor'] === '' && $r['textColor'] === '' && $r['bubbleColor'] === '',
        json_encode($r));
}
// \n final après un hex valide (I1 : $ de PCRE matche avant un \n final, doit être rejeté)
verifie('hex valide suivi de \n → rejeté (pas juste "$", regex en \z)', $g->x(['bgColor' => "#ffffff\n"])['bgColor'] === '');

// --- Police : liste blanche stricte ---
foreach (['', 'sans', 'serif', 'mono', 'rounded'] as $ok) {
    verifie("police autorisée '$ok' conservée", $g->x(['font' => $ok])['font'] === $ok);
}
foreach (['Comic Sans MS', 'sans-serif', '__proto__', 'constructor', "sans\"}</style><script>", 123, null, ['x']] as $mauvaise) {
    $r = $g->x(['font' => $mauvaise]);
    verifie('police non listée rejetée → ""', $r['font'] === '', json_encode($mauvaise) . ' -> ' . json_encode($r['font']));
}

// --- Taille : bornée 12-22, jamais hors plage, jamais un type inattendu ---
verifie('taille par défaut si absente', $g->x([])['fontSize'] === 14);
verifie('taille 5 → bornée à 12 (min)', $g->x(['fontSize' => 5])['fontSize'] === 12);
verifie('taille 999 → bornée à 22 (max)', $g->x(['fontSize' => 999])['fontSize'] === 22);
verifie('taille "14px" (chaîne non numérique) → repli sûr, jamais hors 12-22', (function () use ($g) { $v = $g->x(['fontSize' => '14px'])['fontSize']; return $v >= 12 && $v <= 22; })());
verifie('taille négative → bornée à 12', $g->x(['fontSize' => -50])['fontSize'] === 12);

// --- Le résultat a toujours exactement les 5 clés attendues, jamais plus (pas de passthrough accidentel) ---
$r = $g->x(['font' => 'sans', 'fontSize' => 16, 'bgColor' => '#ffffff', 'textColor' => '#000000', 'bubbleColor' => '#123456', 'injecte' => 'valeur-etrangere', '__proto__' => ['polluted' => true]]);
verifie('clés exactement {font,fontSize,bgColor,textColor,bubbleColor}, rien d\'autre ne passe', array_keys($r) === ['font', 'fontSize', 'bgColor', 'textColor', 'bubbleColor'], json_encode($r));
verifie('aucune pollution de prototype (objet PHP natif, pas de magie __proto__)', !isset($r['injecte']) && !isset($r['polluted']));

// --- Entrée vide / non conforme ne fait jamais planter ---
verifie('tableau vide → 5 défauts sûrs', $g->x([]) === ['font' => '', 'fontSize' => 14, 'bgColor' => '', 'textColor' => '', 'bubbleColor' => '']);

// --- Points structurels (B1/revue, assertions sur le code source lui-même comme pour convert_file dans test_docx.php) ---
verifie('get() appelle bien sanitizeAppearance (B1 : seul rempart pour GET /chats/{id})',
    preg_match('~function get\(string \$user, string \$id\): \?array \{.*?sanitizeAppearance\(~s', $source) === 1);
verifie('list() appelle bien sanitizeAppearance', preg_match('~sanitizeAppearance\(is_array\(\$chat\[.appearance.\]~', $source) === 1);
verifie('setMeta() appelle bien sanitizeAppearance avant écriture', str_contains($source, "\$chat['appearance'] = \$this->sanitizeAppearance("));
verifie('regex hex utilise \z (pas $) — I1', str_contains($source, "preg_match('/^#[0-9a-fA-F]{6}\\z/'"));

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
