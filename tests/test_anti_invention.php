<?php
/**
 * Tests des garde-fous « anti-invention » v2 (branche anti-invention, 28/09/2026, après revue adverse).
 *
 * Même principe que test_liens_fichiers.php : les VRAIES méthodes sont extraites de src/RagService.php et
 * chargées dans une classe de test minimale. Simulés : l'accès aux fichiers (linkedFileExists), l'hôte de
 * l'instance (isOwnNextcloudUrl) et les images. Aucune dépendance à Nextcloud, aucune écriture.
 * NON couverts ici (limite connue) : les boucles ask()/askStream() elles-mêmes et runForcedWebSearch().
 *
 * Usage : php tests/test_anti_invention.php src/RagService.php   (ou source en base64 dans RAGSERVICE_B64)
 */

set_error_handler(function (int $n, string $m): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m\n"; $echecs = ($echecs ?? 0) + 1; return true; });

$source = getenv('RAGSERVICE_B64') !== false ? base64_decode(getenv('RAGSERVICE_B64')) : file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') { fwrite(STDERR, "source RagService.php introuvable\n"); exit(2); }

function extraire(string $src, string $methode): string {
    $debut = strpos($src, 'private function ' . $methode . '(');
    if ($debut === false) $debut = strpos($src, 'private static function ' . $methode . '(');
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
$methodes = ['forcedWebSearch', 'isFileCreationRequest', 'claimsCreation', 'needsCreationNudge', 'hasTool', 'citesUrl',
    'removeUnbackedFileLinks', 'stripFileLinkLines', 'finishAnswer', 'collectToolSources', 'addCreatedFile', 'appendFileLinks'];
$corps = implode("\n", array_map(fn($m) => extraire($source, $m), $methodes));
eval('class RagSousTest {
    ' . str_replace('private const', 'public const', $nudge[0]) . '
    public array $createdFiles = [];
    public bool $fileToolAttempted = false;
    public bool $removedFileLinks = false;
    public array $toolSources = [];
    public string $langue = "fr";
    /** fichiers « existants » du faux utilisateur hicham : ids et chemins */
    public array $existants = ["ids" => [3660073, 55], "chemins" => ["Rendezvous_summary.docx", "CR.docx"]];
    private function uiLanguage(): string { return $this->langue; }
    private function appendImageMarkdown(string $a): string { return $a; }
    private function addToolSource(string $url, array $item): void { $this->toolSources[] = $url; }
    private function isOwnNextcloudUrl(string $url): bool { return in_array(parse_url($url, PHP_URL_HOST), ["h", "192.168.1.99"], true); }
    private function linkedFileExists(string $userId, string $url): bool {
        $p = rawurldecode((string)parse_url($url, PHP_URL_PATH));
        if (preg_match("~/f/(\\d+)/?$~", $p, $m)) return in_array((int)$m[1], $this->existants["ids"], true);
        if (preg_match("~/remote\\.php/dav/files/([^/]+)/(.+)$~", $p, $m)) return $m[1] === $userId && in_array($m[2], $this->existants["chemins"], true);
        return true;
    }
    public function web(string $q): ?array { return $this->forcedWebSearch($q); }
    public function fichier(string $q): bool { return $this->isFileCreationRequest($q); }
    public function relance(string $q, string $reponse, array $outils): bool { return $this->needsCreationNudge($q, $reponse, $outils); }
    public function collecter(string $outil, array $res): void { $this->collectToolSources($outil, $res); }
    public function finir(string $a, array $msgs = [], string $q = "bonjour"): string { return $this->finishAnswer("hicham", $q, $a, $msgs); }
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

// ── 1. Recherche web imposée : questions publiques qui changent avec le temps (Q8 : 5 + 5 témoins, puis ratés de la revue)
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
    "latest php version?",
    "Quel est le cours du bitcoin ?",
    "Bitcoin price?",
    "Quand sort Nextcloud 35 ?",
    "Est-ce que PHP 8.5 est sorti ?",
    "Quelles sont les dernières actus ?",
    "ما هي آخر الأخبار",
] as $q) {
    verifie('web imposé : ' . $q, $t->web($q) !== null);
}
$r = $t->web("Quelles sont les actualités du jour ?");
verifie('toujours le mode « web » (all/news interrogent Bing/Google News quel que soit le fournisseur)', ($r['mode'] ?? '') === 'web', json_encode($r));
$r = $t->web("Quelle est la dernière version de PHP ?");
verifie('la requête envoyée = la question, intacte', ($r['query'] ?? '') === "Quelle est la dernière version de PHP ?", json_encode($r, JSON_UNESCAPED_UNICODE));

// ── 2. Ne doit JAMAIS partir vers un moteur externe : questions de travail (phrases-pièges de la revue adverse)
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
    "Quelle version du contrat de Mme Martin dois-je envoyer ?",
    "What version of the budget did Sarah approve?",
    "Rédige un mail : Bonjour Paul, voici la dernière version du devis 2026-044 pour la société Omega, montant 45 000 AED.",
    "Montre la dernière mise à jour du ticket #4521 de M. Haddad",
    "Quel est le prix actuel du loyer de l'appartement de Karim au 12 rue Victor Hugo ?",
    "Stock actuel de l'article SKU-123 ?",
    "Résume les news du dossier client Benali",
    "Fais le point sur les actualités de l'équipe RH : licenciement de Paul Martin",
    "Qui est le CEO de notre filiale ?",
    "Qui est l'actuel président du conseil d'administration de ma société ?",
    "Quelle version de Nextcloud tourne sur le serveur ?",
    "Le patient Jean Durand a un rendez-vous, quelle est la météo pour lui ?",
    "Check in the internet folder of the shared drive",
    "Quelle est la météo à Dubaï demain ?",
    str_repeat("Voici un long texte collé avec le mot actualités et dernière version de PHP. ", 4),
] as $q) {
    verifie('pas de web : ' . mb_substr($q, 0, 80), $t->web($q) === null);
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
    "Mets mes rendez-vous dans un tableau Excel",
    "cree moi un exel des ventes",
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

// ── 4. Relance : seulement quand la réponse AFFIRME ou ANNONCE un fichier qui n'existe pas (revue n°2)
$outils = [['type' => 'function', 'function' => ['name' => 'create_file']]];
$q = "creer un fichier excel pour me lister mes rondevous de cette semaine";
$t = new RagSousTest();
verifie('relance : « I have created… » sans outil → oui', $t->relance($q, 'I have created an Excel file named "Rendezvous_list.xlsx".', $outils));
verifie('relance : « Je vais créer… » sans outil → oui (raté réel du 30B, mesure du 28/09)', $t->relance($q, "Je vais créer un fichier Excel pour lister vos rendez-vous de cette semaine.", $outils));
verifie('relance : question de clarification → non', !$t->relance("Crée un fichier", "Quel contenu voulez-vous mettre dans le fichier ?", $outils));
verifie('relance : réponse en texte sans affirmation (« pas besoin de fichier ») → non',
    !$t->relance("Génère une liste de 10 idées, pas besoin de fichier", "1. Accueil personnalisé\n2. Café offert", $outils));
verifie('relance : outil create_file absent (actions désactivées) → non', !$t->relance($q, "J'ai créé le fichier.", []));
$t->collecter('create_file', ['ok' => false, 'error' => 'EVA cannot generate .pdf files']);
verifie('relance : create_file déjà tenté (même en échec) → non', !$t->relance($q, "J'ai créé le fichier.", $outils));
verifie('le texte de relance exige de lire les données avant d\'écrire',
    str_contains(RagSousTest::CREATION_NUDGE, 'FIRST call the tool that reads') && str_contains(RagSousTest::CREATION_NUDGE, 'never write'));

// ── 5. Faux liens : le cas réel du 28/09 (Excel), recopié tel quel
$t = new RagSousTest();
$faux = "I have created an Excel file named \"Rendezvous_list.xlsx\" to list your appointments for this week. You can access it here: [Download Rendezvous_list.xlsx](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_list.xlsx).\n\nIf you need any adjustments or additional information, feel free to let me know!\n\n📄 **Rendezvous\\_list.xlsx** — [Open](http://192.168.1.99/workspace/index.php/f/3660074) · [Download](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_list.xlsx)";
$r = $t->finir($faux, [], $q);
verifie('cas réel Excel : plus aucun faux lien, ligne 📄 copiée retirée',
    !str_contains($r, '3660074') && !str_contains($r, 'remote.php') && substr_count($r, '📄') === 0, $r);
verifie('cas réel Excel : libellé barré, avertissement, reste du texte gardé',
    str_contains($r, '~~Download Rendezvous_list.xlsx~~') && str_contains($r, '⚠️ Attention') && str_contains($r, 'If you need any adjustments'), $r);
// fausse affirmation SANS lien, relances épuisées (revue n°3)
$t = new RagSousTest();
$r = $t->finir("J'ai créé Rendezvous.xlsx dans Documents.", [], $q);
verifie('affirmation sans lien ni fichier → avertissement', str_contains($r, '⚠️ Attention'), $r);
$t = new RagSousTest();
verifie('réponse normale sans demande de fichier → intacte', $t->finir("Nextcloud est une plateforme de partage.") === "Nextcloud est une plateforme de partage.");

// lien vers un fichier qui EXISTE : gardé, même avec ponctuation française/arabe collée (revue n°5)
$t = new RagSousTest();
$vrai = "Voici ton fichier : http://192.168.1.99/workspace/index.php/f/3660073";
verifie('lien vers un fichier existant → gardé', $t->finir($vrai) === $vrai, $t->finir($vrai));
foreach (['»', '،', '؟', '”', '.'] as $p) {
    $t = new RagSousTest();
    $a = "Le compte rendu « http://h/workspace/remote.php/dav/files/hicham/CR.docx$p";
    verifie("ponctuation « $p » collée à un lien existant → gardé, pas d'avertissement", $t->finir($a) === $a, $t->finir($a));
}
// comparaison du lien entier (revue n°6)
$t = new RagSousTest();
$r = $t->finir("Voir http://h/workspace/index.php/f/366007", [['role' => 'tool', 'content' => '{"url":"http://h/workspace/index.php/f/3660073"}']]);
verifie('…/f/366007 inventé n\'est pas « cité » par un outil qui renvoie …/f/3660073', str_contains($r, '⚠️') && !str_contains($r, '/f/366007'), $r);
$t = new RagSousTest();
$r = $t->finir("Faux http://h/workspace/index.php/f/5 et vrai http://h/workspace/index.php/f/55");
verifie('retirer …/f/5 ne touche pas …/f/55 sur la même ligne', str_contains($r, 'http://h/workspace/index.php/f/55') && !preg_match('~/f/5(?!5)~', $r), $r);
// autre site : jamais vérifié ni retiré (revue n°8)
$t = new RagSousTest();
$ext = "Doc : https://docs.example.org/index.php/f/5";
verifie('lien /f/ d\'un autre site → intact', $t->finir($ext) === $ext, $t->finir($ext));
// ligne de tableau : seul le lien est barré (revue n°8)
$t = new RagSousTest();
$r = $t->finir("| Janvier | [rapport](http://h/workspace/index.php/f/9) | 12 |");
verifie('ligne de tableau conservée, lien barré', str_starts_with($r, '| Janvier | ~~rapport~~ | 12 |'), $r);
// WebDAV d'un AUTRE utilisateur : retiré
$t = new RagSousTest();
verifie('WebDAV d\'un autre utilisateur → retiré', str_contains($t->finir("[x](http://h/workspace/remote.php/dav/files/paul/Rendezvous_summary.docx)"), '⚠️'));
// lien fourni par un outil de ce tour : gardé ; venant seulement d'une ancienne réponse : retiré
$t = new RagSousTest();
$u = "http://h/workspace/index.php/f/999";
verifie('lien fourni par un outil → gardé', $t->finir("Trouvé : $u", [['role' => 'tool', 'content' => "{\"url\":\"$u\"}"]]) === "Trouvé : $u");
$t = new RagSousTest();
verifie('lien venant seulement d\'une ancienne réponse → retiré', str_contains($t->finir("Revoici : $u", [['role' => 'assistant', 'content' => "Créé : $u"]]), '⚠️'));
// liens web ordinaires : jamais touchés
$t = new RagSousTest();
$web = "Source : [nextcloud.com](https://nextcloud.com/changelog/) et https://github.com/nextcloud/server/releases";
verifie('liens web ordinaires → intacts', $t->finir($web) === $web);
// fichier réellement créé dans ce tour : lien gardé, ligne 📄 ajoutée, pas d'avertissement
$t = new RagSousTest();
$t->collecter('create_file', ['ok' => true, 'result' => 'Created a.md', 'file' => ['name' => 'a.md', 'path' => 'a.md', 'file_id' => 77,
    'url' => 'http://h/workspace/index.php/f/77', 'download_url' => 'http://h/workspace/remote.php/dav/files/hicham/a.md']]);
$r = $t->finir("J'ai créé : [a.md](http://h/workspace/index.php/f/77)", [], "Crée un fichier a.md");
verifie('fichier créé dans ce tour → lien gardé + ligne 📄, pas d\'avertissement', !str_contains($r, '⚠️') && substr_count($r, '📄') === 1, $r);
// interface arabe
$t = new RagSousTest(); $t->langue = 'ar';
verifie('avertissement en arabe', str_contains($t->finir("http://h/workspace/index.php/f/5"), 'تنبيه'));

// ── 6. Historique : la ligne 📄 devient « (file created: nom) » (revue n°13)
$t = new RagSousTest();
$h = "J'ai créé le fichier.\n\n📄 **Rendezvous\\_summary.docx** — [Open](http://192.168.1.99/workspace/index.php/f/3660073) · [Download](http://192.168.1.99/workspace/remote.php/dav/files/hicham/Rendezvous_summary.docx)";
verifie('historique : ligne 📄 → « (file created: nom) », sans lien ni échappement', $t->historique($h) === "J'ai créé le fichier.\n\n(file created: Rendezvous_summary.docx)", $t->historique($h));
verifie('historique : texte sans ligne 📄 inchangé', $t->historique("Bonjour 📄 **x**") === "Bonjour 📄 **x**");

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
