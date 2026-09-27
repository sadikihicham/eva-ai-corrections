<?php
/**
 * Tests des garde-fous « anti-invention » (branche anti-invention, 28/09/2026).
 *
 * Même principe que test_liens_fichiers.php : les VRAIES méthodes sont extraites de src/RagService.php et
 * chargées dans une classe de test minimale. Seuls l'accès aux fichiers (linkedFileExists) et les images sont
 * simulés. Aucune dépendance à Nextcloud, aucune écriture.
 *
 * Usage : php tests/test_anti_invention.php src/RagService.php   (ou source en base64 dans RAGSERVICE_B64)
 */

set_error_handler(function (int $n, string $m): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m\n"; $echecs = ($echecs ?? 0) + 1; return true; });

$source = getenv('RAGSERVICE_B64') !== false ? base64_decode(getenv('RAGSERVICE_B64')) : file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') { fwrite(STDERR, "source RagService.php introuvable\n"); exit(2); }

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
if (!preg_match('/private const CREATION_NUDGE = .*?;\n/s', $source, $nudge)) { fwrite(STDERR, "CREATION_NUDGE absente\n"); exit(2); }

// eval() ne charge que du code extrait de NOTRE fichier versionné src/RagService.php (voir test_liens_fichiers.php).
$methodes = ['forcedWebSearch', 'isFileCreationRequest', 'needsCreationNudge', 'hasTool', 'removeUnbackedFileLinks',
    'stripFileLinkLines', 'finishAnswer', 'collectToolSources', 'addCreatedFile', 'appendFileLinks'];
$corps = implode("\n", array_map(fn($m) => extraire($source, $m), $methodes));
eval('class RagSousTest {
    ' . str_replace('private const', 'public const', $nudge[0]) . '
    public array $createdFiles = [];
    public bool $fileToolAttempted = false;
    public array $toolSources = [];
    public string $langue = "fr";
    /** fichiers « existants » du faux utilisateur : chemins et ids */
    public array $existants = ["ids" => [3660073], "chemins" => ["Rendezvous_summary.docx"]];
    private function uiLanguage(): string { return $this->langue; }
    private function appendImageMarkdown(string $a): string { return $a; }
    private function addToolSource(string $url, array $item): void { $this->toolSources[] = $url; }
    private function linkedFileExists(string $userId, string $url): bool {
        $p = rawurldecode((string)parse_url($url, PHP_URL_PATH));
        if (preg_match("~/f/(\\d+)/?$~", $p, $m)) return in_array((int)$m[1], $this->existants["ids"], true);
        if (preg_match("~/remote\\.php/dav/files/([^/]+)/(.+)$~", $p, $m)) return $m[1] === $userId && in_array($m[2], $this->existants["chemins"], true);
        return true;
    }
    public function web(string $q): ?array { return $this->forcedWebSearch($q); }
    public function fichier(string $q): bool { return $this->isFileCreationRequest($q); }
    public function relance(string $q, array $outils): bool { return $this->needsCreationNudge($q, $outils); }
    public function collecter(string $outil, array $res): void { $this->collectToolSources($outil, $res); }
    public function finir(string $a, array $msgs = []): string { return $this->finishAnswer("hicham", $a, $msgs); }
    public function historique(string $c): string { return $this->stripFileLinkLines($c); }
    ' . $corps . '
}');

$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $detail = ''): void {
    global $echecs, $total; $total++;
    echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok || $detail === '' ? '' : "\n     → " . $detail) . "\n";
    if (!$ok) $echecs++;
}
$t = new RagSousTest();

// ── 1. Recherche web imposée : les 10 questions de l'évaluation Q8 (5 de mise au point + 5 témoins jamais vues)
foreach ([
    "Quelle est la dernière version majeure de Nextcloud publiée, et quand ? Donne la source.",
    "Quelle est la dernière version stable de PHP ?",
    "Quel est le cours actuel de l'once d'or en dollars ?",
    "Quelles sont les principales actualités sur l'intelligence artificielle cette semaine ?",
    "What is the latest released version of Python?",
    "Quelle est la dernière version LTS d'Ubuntu ?",
    "Qui est l'actuel président de la République française ?",
    "C'est quoi la dernière version de Nextcloud ?",
    "What is the newest iPhone model?",
    "Quel est le taux de change actuel du dirham en euros ?",
    "cherche sur internet les horaires de la bibliothèque de Dubaï",
    "ابحث في الإنترنت عن أحدث إصدار من نكست كلاود",
    "ما هو أحدث إصدار من PHP؟",
    "Welche ist die neueste Version von Nextcloud?",
] as $q) {
    verifie('web imposé : ' . $q, $t->web($q) !== null);
}
$r = $t->web("Quelles sont les actualités du jour ?");
verifie('actualités → mode « news »', ($r['mode'] ?? '') === 'news', json_encode($r));
$r = $t->web("Quel est le taux de change actuel du dirham en euros ?");
verifie('taux de change → mode « all » (le mode news échouait, 28/09)', ($r['mode'] ?? '') === 'all', json_encode($r));
$r = $t->web("Quelle est la dernière version de PHP ?");
verifie('la requête envoyée = la question, intacte', ($r['query'] ?? '') === "Quelle est la dernière version de PHP ?", json_encode($r, JSON_UNESCAPED_UNICODE));

// ── 2. Ne doit PAS déclencher de recherche (latence inutile, et la question part vers un moteur externe)
foreach ([
    "Explique en deux phrases ce qu'est Nextcloud.",
    "Rédige un courriel court et poli pour reporter une réunion de mardi à jeudi, même heure.",
    "Combien font 12 fois 7 ?",
    "Traduis « bonjour, comment allez-vous ? » en arabe.",
    "Donne-moi trois conseils pour bien nommer ses documents.",
    "creer un fichier docx pour sesumer mes rondezvous de demain",
    "Quelle version de Nextcloud est installée sur mon serveur ?",
    "Quelle est la dernière version de mon fichier budget.xlsx ?",
    "Crée 3 nouvelles notes pour mes réunions",
    "Quels sont mes rendez-vous aujourd'hui ?",
    str_repeat("Voici un long texte collé avec le mot actualités et dernière version. ", 6),
] as $q) {
    verifie('pas de web : ' . mb_substr($q, 0, 70), $t->web($q) === null);
}

// ── 3. Demande de création de fichier
foreach ([
    "creer un fichier excel pour me lister mes rondevous de cette semaine",
    "creer un fichier pdf pour me lister mes rondevous de cette semaine",
    "creer un fichier docx pour sesumer mes rondezvous de demain",
    "Crée un fichier essai-liens.md avec 3 lignes de texte",
    "Fais-moi un tableau Excel des ventes par mois",
    "Génère un document Word avec le compte rendu",
    "Create a spreadsheet with my tasks",
    "أنشئ ملف يحتوي على قائمة المهام",
] as $q) {
    verifie('création demandée : ' . $q, $t->fichier($q));
}
foreach ([
    "Comment créer un fichier Excel ?",
    "Fais-moi un résumé de ce document",
    "Résume le pdf que je t'ai envoyé",
    "Quelle est la dernière version stable de PHP ?",
    "Explique-moi comment exporter en PDF",
    "Écris un mail à Paul pour annuler la réunion",
] as $q) {
    verifie('pas une création : ' . $q, !$t->fichier($q));
}

// ── 4. Relance : seulement si demande de fichier, outil disponible, et AUCUN outil fichier tenté
$outils = [['type' => 'function', 'function' => ['name' => 'create_file']]];
$q = "creer un fichier excel pour me lister mes rondevous de cette semaine";
$t = new RagSousTest();
verifie('relance : fichier demandé, aucun outil appelé → oui', $t->relance($q, $outils));
verifie('relance : outil create_file absent (actions désactivées) → non', !$t->relance($q, []));
$t->collecter('create_file', ['ok' => false, 'error' => 'EVA cannot generate .pdf files from text']);
verifie('relance : create_file déjà tenté (même en échec) → non', !$t->relance($q, $outils));
verifie('le texte de relance exige de lire les données avant d\'écrire', str_contains(RagSousTest::CREATION_NUDGE, 'FIRST call the tool that reads') && str_contains(RagSousTest::CREATION_NUDGE, 'never write'));

// ── 5. Faux liens : le cas réel du 28/09 (Excel), recopié tel quel
$t = new RagSousTest();
$faux = "I have created an Excel file named \"Rendezvous_list.xlsx\" to list your appointments for this week. You can access it here: [Download Rendezvous_list.xlsx](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_list.xlsx).\n\nIf you need any adjustments or additional information, feel free to let me know!\n\n📄 **Rendezvous\\_list.xlsx** — [Open](http://192.168.1.99/workspace/index.php/f/3660074) · [Download](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_list.xlsx)";
$r = $t->finir($faux);
verifie('cas réel Excel : faux liens et fausse affirmation retirés',
    !str_contains($r, '3660074') && !str_contains($r, 'Rendezvous_list.xlsx') && !str_contains($r, 'I have created'), $r);
verifie('cas réel Excel : avertissement ajouté, reste du texte gardé',
    str_contains($r, '⚠️ Lien retiré') && str_contains($r, 'If you need any adjustments'), $r);

// lien vers un fichier qui EXISTE (créé à un tour précédent) : gardé
$t = new RagSousTest();
$vrai = "Voici ton fichier : http://192.168.1.99/workspace/index.php/f/3660073";
verifie('lien vers un fichier existant → gardé', $t->finir($vrai) === $vrai, $t->finir($vrai));
// lien WebDAV d'un AUTRE utilisateur : retiré
$t = new RagSousTest();
verifie('WebDAV d\'un autre utilisateur → retiré', str_contains($t->finir("[x](http://h/workspace/remote.php/dav/files/paul/Rendezvous_summary.docx)"), '⚠️'));
// lien présent dans un résultat d'outil de ce tour (ex. search_files) : gardé même si la simulation dit « inexistant »
$t = new RagSousTest();
$u = "http://h/workspace/index.php/f/999";
verifie('lien fourni par un outil → gardé', $t->finir("Trouvé : $u", [['role' => 'tool', 'content' => "{\"url\":\"$u\"}"]]) === "Trouvé : $u");
// … mais PAS s'il vient seulement d'une ancienne réponse de l'assistant (qui peut être inventée)
$t = new RagSousTest();
verifie('lien venant seulement d\'une ancienne réponse → retiré', str_contains($t->finir("Revoici : $u", [['role' => 'assistant', 'content' => "Créé : $u"]]), '⚠️'));
// liens web ordinaires : jamais touchés
$t = new RagSousTest();
$web = "Source : [nextcloud.com](https://nextcloud.com/changelog/) et https://github.com/nextcloud/server/releases";
verifie('liens web ordinaires → intacts', $t->finir($web) === $web);
// fichier réellement créé dans ce tour : lien de l'outil gardé, ligne 📄 ajoutée
$t = new RagSousTest();
$t->collecter('create_file', ['ok' => true, 'result' => 'Created a.md', 'file' => ['name' => 'a.md', 'path' => 'a.md', 'file_id' => 77,
    'url' => 'http://h/workspace/index.php/f/77', 'download_url' => 'http://h/workspace/remote.php/dav/files/hicham/a.md']]);
$r = $t->finir("Créé : [a.md](http://h/workspace/index.php/f/77)");
verifie('fichier créé dans ce tour → lien gardé + ligne 📄, pas d\'avertissement', !str_contains($r, '⚠️') && substr_count($r, '📄') === 1, $r);
// ponctuation finale collée au lien
$t = new RagSousTest();
verifie('point final collé au lien → lien reconnu', str_contains($t->finir("Ici http://h/workspace/index.php/f/5."), '⚠️'));
// interface arabe
$t = new RagSousTest(); $t->langue = 'ar';
verifie('avertissement en arabe', str_contains($t->finir("http://h/workspace/index.php/f/5"), 'تمت إزالة رابط'));

// ── 6. Historique : les lignes 📄 ajoutées par eva ne sont plus renvoyées au modèle
$t = new RagSousTest();
$h = "J'ai créé le fichier.\n\n📄 **Rendezvous\\_summary.docx** — [Open](http://192.168.1.99/workspace/index.php/f/3660073) · [Download](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_summary.docx)";
verifie('historique : ligne 📄 retirée, texte gardé', $t->historique($h) === "J'ai créé le fichier.", $t->historique($h));
verifie('historique : texte sans ligne 📄 inchangé', $t->historique("Bonjour 📄 **x**") === "Bonjour 📄 **x**");

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
