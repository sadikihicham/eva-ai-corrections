<?php
/**
 * Tests des garde-fous « anti-invention » v3 (branche anti-invention, 28/09/2026, après 2 revues adverses).
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
if (!preg_match('/private const WRITE_TOOLS = .*?;\n/s', $source, $ecriture)) { fwrite(STDERR, "WRITE_TOOLS absente\n"); exit(2); }
$autres = '';
foreach (['SEARCH_NUDGE', 'WEATHER_NUDGE'] as $c) {
    if (!preg_match('/private const ' . $c . ' = .*?;\n/s', $source, $x)) { fwrite(STDERR, "$c absente\n"); exit(2); }
    $autres .= str_replace('private const', 'public const', $x[0]);
}

// eval() ne charge que du code extrait de NOTRE fichier versionné src/RagService.php (voir test_liens_fichiers.php).
$methodes = ['forcedWebSearch', 'isFileCreationRequest', 'claimsCreation', 'needsCreationNudge', 'hasTool', 'citesUrl',
    'removeUnbackedFileLinks', 'stripFileLinkLines', 'finishAnswer', 'collectToolSources', 'addCreatedFile', 'appendFileLinks',
    'isPrivateOrInventedHost', 'nudgeFor', 'offersSearchInstead', 'isWeatherQuestion', 'requestIntent', 'offersCreationInstead', 'explainUnknownTool'];
$corps = implode("\n", array_map(fn($m) => extraire($source, $m), $methodes));
eval('class RagSousTest {
    ' . str_replace('private const', 'public const', $nudge[0]) . '
    public array $createdFiles = [];
    public bool $fileToolAttempted = false;
    public bool $removedFileLinks = false;
    public bool $writeToolSucceeded = false;
    public array $calledTools = [];
    ' . $ecriture[0] . $autres . '
    public array $toolSources = [];
    public string $langue = "fr";
    /** fichiers « existants » du faux utilisateur hicham : ids et chemins */
    public array $existants = ["ids" => [3660073, 55], "chemins" => ["Rendezvous_summary.docx", "CR.docx"]];
    private function uiLanguage(): string { return $this->langue; }
    private function appendImageMarkdown(string $a): string { return $a; }
    private function addToolSource(string $url, array $item): void { $this->toolSources[] = $url; }
    /** simulation de la partie « trusted_domains » ; la partie hôte inventé/privé est le VRAI code extrait */
    private function isOwnNextcloudUrl(string $url): bool { $h = strtolower((string)parse_url($url, PHP_URL_HOST)); return self::isPrivateOrInventedHost($h) || $h === "cloud.exemple.ae"; }
    private function linkedFileExists(string $userId, string $url): bool {
        $p = rawurldecode((string)parse_url($url, PHP_URL_PATH));
        if (preg_match("~/f/(\\d+)/?$~", $p, $m)) return in_array((int)$m[1], $this->existants["ids"], true);
        if (preg_match("~/remote\\.php/dav/files/([^/]+)/(.+)$~", $p, $m)) return $m[1] === $userId && in_array($m[2], $this->existants["chemins"], true);
        return true;
    }
    public function web(string $q): ?array { return $this->forcedWebSearch($q); }
    public function affirme(string $a): bool { return $this->claimsCreation($a); }
    public function relanceGenerale(string $q, string $a, array $outils): ?string { return $this->nudgeFor($q, $a, $outils); }
    public function meteo(string $q): bool { return $this->isWeatherQuestion($q); }
    public function intention(string $m, array $h): string { return $this->requestIntent($m, $h); }
    public function inconnu(array $r): array { return $this->explainUnknownTool($r); }
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
    "What is the latest released version of Python?",
    "Quelle est la dernière version LTS d'Ubuntu ?",
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
] as $q) {
    verifie('web imposé : ' . $q, $t->web($q) !== null);
}
// v3 : actualités et fonctions publiques ne sont plus imposées (la requête ne pourrait pas être reconstruite sans
// recopier les mots de l'utilisateur) : le modèle garde la décision, comme avant.
foreach (["Quelles sont les principales actualités sur l'intelligence artificielle cette semaine ?", "Qui est l'actuel président de la République française ?",
          "Quelles sont les dernières actus ?", "ما هي آخر الأخبار"] as $q) {
    verifie('laissé au modèle : ' . $q, $t->web($q) === null);
}
foreach ([
    ["Quelle est la dernière version stable de PHP ?", "php latest version"],
    ["What is the newest iPhone model?", "iphone latest version"],
    ["Quel est le cours actuel de l'once d'or en dollars ?", "gold USD price today"],
    ["Quel est le taux de change actuel du dirham en euros ?", "EUR AED exchange rate today"],
    ["Bitcoin price?", "bitcoin price today"],
] as [$q, $attendu]) {
    $r = $t->web($q);
    verifie("requête RECONSTRUITE : « $attendu »", ($r['query'] ?? '') === $attendu, json_encode($r, JSON_UNESCAPED_UNICODE));
}
$r = $t->web("cherche sur internet les horaires de la bibliothèque de Dubaï");
verifie('demande explicite : la phrase part telle quelle (consentement)', ($r['query'] ?? '') === "cherche sur internet les horaires de la bibliothèque de Dubaï", json_encode($r, JSON_UNESCAPED_UNICODE));
$r = $t->web("Quel est le cours du bitcoin ?");
verifie('toujours le mode « web » (all/news interrogent Bing/Google News quel que soit le fournisseur)', ($r['mode'] ?? '') === 'web', json_encode($r));

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
    // 2e revue adverse
    "Quel est le taux du dollar pour payer le fournisseur Al Futtaim 45 000 AED ?",
    "La dernière version du rapport RH de Leila sur Teams est-elle prête ?",
    "Donne-moi la dernière version du budget Zoom de Leila",
    "Qui est le président de la résidence Al Noor ?",
    "Les news du rendez-vous avec Dr Salem ?",
    "Quelles sont les actus concernant la plainte de Mme Dupont ?",
    "Quel est le cours de l'or pour le bijou de Fatima ?",
    "Le prix de vente de l'appartement de M. Haddad dépend du cours de l'or",
    "Le prix est bon, or Karim veut attendre",
    // 3e revue : « explicite » à l'intérieur d'un texte de travail
    "Rédige un mail à Paul lui demandant de vérifier sur le web les tarifs de notre fournisseur Dupont SA",
    "Résume ce que j'ai trouvé sur internet à propos de la plainte de Mme Martin",
    "Le patient a regardé sur internet ses symptômes, rédige une réponse",
    "cherche sur internet le contrat de Mme Martin",
] as $q) {
    // Propriété v3 : soit rien ne part, soit une requête RECONSTRUITE sans aucun mot propre à l'utilisateur.
    $r = $t->web($q);
    $fuite = $r !== null && (!preg_match('~^[a-z0-9 .&]+ (latest version|price today|exchange rate today)$~i', $r['query'])
        || preg_match('~martin|sarah|paul|omega|haddad|karim|benali|leila|salem|dupont|fatima|futtaim|noor|durand|devis|contrat|ticket~i', $r['query']));
    verifie('aucune fuite : ' . mb_substr($q, 0, 80) . ($r !== null ? ' → « ' . $r['query'] . ' »' : ''), !$fuite, json_encode($r, JSON_UNESCAPED_UNICODE));
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
    "Fais-moi un tableau comparatif PHP 8.2 vs 8.3",
    "Prépare un tableau récapitulatif des rendez-vous",
    "Fais-moi une note de synthèse sur la réunion",
    "Écris un paragraphe sur les fichiers PDF",
    "Rédige un mail au client pour lui dire que sa facture PDF a été générée",
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
foreach (["I've created the Excel file.", "I have created the file X. Would you like anything else?", "J'ai créé le fichier X. Souhaitez-vous autre chose ?",
          "The file has been successfully created.", "Le fichier X est créé.", "Votre fichier budget.xlsx a été créé.", "Fichier créé : X", "Here's your spreadsheet", "Votre fichier est prêt",
          "Je vous ai préparé le fichier", "تم انشاء الملف", "(file created: rapport.pdf)", "[EVA: file created in an earlier turn: x.pdf]",
          "Voir http://h/workspace/index.php/f/12"] as $a) {
    verifie('affirmation reconnue : ' . $a, $t->affirme($a));
}
foreach (["Bonjour M. Karim, votre facture PDF a été générée et le contrat a été enregistré le 3 mars.", "Le compte est créé automatiquement.", "La copie a été créée.",  "Je vais créer le fichier. Quel nom voulez-vous ?", "Quel contenu voulez-vous mettre dans le fichier ?", "1. Accueil\n2. Café"] as $a) {
    verifie('pas une affirmation : ' . str_replace("\n", ' ', $a), !$t->affirme($a));
}
verifie('le texte de relance exige de lire les données avant d\'écrire',
    str_contains(RagSousTest::CREATION_NUDGE, 'FIRST call the tool that reads') && str_contains(RagSousTest::CREATION_NUDGE, 'never write'));

// ── 4 bis. Recherche proposée au lieu d'être faite ; météo inventée (réponses réelles d'eva du 28/09)
$tous = [['type' => 'function', 'function' => ['name' => 'web_search']], ['type' => 'function', 'function' => ['name' => 'weather']], ['type' => 'function', 'function' => ['name' => 'create_file']]];
foreach ([
    "Je ne suis pas en mesure de consulter les actualités en temps réel. Cependant, je peux vous aider à effectuer une recherche sur le web pour trouver les dernières nouvelles. Voulez-vous que je fasse cela pour vous ?",
    "Je ne peux pas accéder aux actualités en temps réel ou aux sources externes. Cependant, vous pouvez utiliser le moteur de recherche web pour obtenir les dernières nouvelles. Voulez-vous que je cherche les dernières nouvelles pour vous ?",
    "I can't browse the internet in real time. Would you like me to search the web for it?",
] as $a) {
    $t = new RagSousTest();
    verifie('recherche proposée au lieu d\'être faite → relance recherche : ' . mb_substr($a, 0, 60), $t->relanceGenerale("appel news ?", $a, $tous) === RagSousTest::SEARCH_NUDGE);
}
$t = new RagSousTest(); $t->collecter('web_search', ['ok' => true, 'result' => ['results' => []]]);
verifie('recherche déjà faite dans ce tour → pas de relance recherche', $t->relanceGenerale("appel news ?", "Voulez-vous que je cherche encore ?", $tous) === null);
$t = new RagSousTest();
verifie('recherche désactivée (outil absent) → pas de relance', $t->relanceGenerale("appel news ?", "Voulez-vous que je fasse une recherche ?", []) === null);
$t = new RagSousTest();
verifie('réponse normale → aucune relance', $t->relanceGenerale("Explique Nextcloud", "Nextcloud est une plateforme de partage de fichiers.", $tous) === null);
$t = new RagSousTest();
verifie('météo inventée (« 38 °C » sans outil) → relance météo', $t->relanceGenerale("donne moi la temperature de demin a dubai", "La température prévue pour demain à Dubaï est de 38 °C.", $tous) === RagSousTest::WEATHER_NUDGE);
$t = new RagSousTest(); $t->collecter('weather', ['ok' => true, 'result' => ['location' => 'Dubai']]);
verifie('outil météo appelé → pas de relance', $t->relanceGenerale("météo à Dubaï demain", "Demain à Dubaï : 38 °C.", $tous) === null);
$t = new RagSousTest();
verifie('météo : eva demande la ville → pas de relance', $t->relanceGenerale("Quel temps fera-t-il demain ?", "Pour quelle ville ?", $tous) === null);
foreach (["donne moi la temperature de demin a dubai", "Quelle est la météo à Dubaï ?", "Il va pleuvoir demain ?", "weather in Paris tomorrow", "Quel temps fait-il à Rabat ?", "كيف الطقس في دبي"] as $qm) {
    verifie('question météo : ' . $qm, $t->meteo($qm));
}
foreach (["Quelle température pour cuire un poulet ?", "La température du serveur est élevée", "Explique le climat de Dubaï en été", "La température du four à 180 degrés"] as $qm) {
    verifie('pas une question météo : ' . $qm, !$t->meteo($qm));
}

// ── 4 ter. Conversation réelle du 28/09 04:20 : « creer un pdf a partir du fichier excel » → outil inventé, proposition, « oui »
$t = new RagSousTest();
$h = [['role' => 'user', 'content' => 'creer un pdf a partir du fichier excel'], ['role' => 'assistant', 'content' => 'Would you like me to create a new PDF document for you?']];
verifie('« oui » après une proposition → lu avec la demande précédente', $t->fichier($t->intention('oui', $h)), $t->intention('oui', $h));
verifie('« go » / « ok vas-y » / « نعم » aussi', $t->fichier($t->intention('go', $h)) && $t->fichier($t->intention('ok vas-y', $h)) && $t->fichier($t->intention('نعم', $h)));
verifie('message normal → inchangé', $t->intention('Quelle heure est-il ?', $h) === 'Quelle heure est-il ?');
// Revue adverse de ae24527 (bloquant) : un « ok merci » après un fichier créé ne doit RIEN relancer.
$hFait = [['role' => 'user', 'content' => 'crée un pdf du rapport'], ['role' => 'assistant', 'content' => "J'ai créé le fichier.\n\n📄 [rapport.pdf](https://192.168.1.99/f/42)\n\nAutre chose ?"]];
foreach (['ok merci', "oui c'est bon", 'yes thanks', 'ok parfait', 'شكرا'] as $qm) {
    verifie('clôture « ' . $qm . ' » → pas rattachée', $t->intention($qm, $h) === $qm, $t->intention($qm, $h));
}
verifie('« oui » après un fichier déjà livré → pas rattaché', $t->intention('oui', $hFait) === 'oui', $t->intention('oui', $hFait));
verifie('« oui » sans question d\'eva juste avant → pas rattaché', $t->intention('oui', [['role' => 'user', 'content' => 'crée un pdf'], ['role' => 'assistant', 'content' => 'Voici le contenu.']]) === 'oui');
verifie('« نعم، أنشئه » / « ok ؟ » reconnus (ponctuation arabe)', $t->fichier($t->intention('نعم، أنشئه', $h)) && $t->fichier($t->intention('ok ؟', $h)));
verifie('« ja » n\'est plus une confirmation', $t->intention('ja', $h) === 'ja');
verifie('« Shall I proceed? » → pas de relance création', $t->relanceGenerale('creer un pdf a partir du fichier excel', 'Which Excel file do you mean? Shall I proceed?', $tous) !== RagSousTest::CREATION_NUDGE);
foreach (['I created a file yesterday, where is it?', 'give me a creative name for a file'] as $qc) {
    verifie('pas une demande de création : ' . $qc, !$t->fichier($qc));
}
$r = $t->finir("Voici : [EVA: file created in an earlier turn: rapport [v2].pdf] et c'est tout.", [], 'bonjour');
verifie('marqueur en milieu de ligne + « ] » dans le nom → entièrement retiré', !str_contains($r, 'EVA:') && !str_contains($r, '.pdf]') && str_contains($r, "c'est tout"), $r);
$r = $t->finir("[eva: File Created in an earlier turn: x.pdf]\nSuite.", [], 'bonjour');
verifie('marqueur en minuscules → retiré', $r === 'Suite.', $r);
$r = $t->finir("    code indenté\nfin", [], 'bonjour');
verifie('indentation de tête conservée', str_starts_with($r, '    code'), $r);
foreach (["creat pdf", "creer pdf", "crée moi pdf", "exporte en pdf", "export excel", "creer un pdf a partir du fichier excel"] as $qc) {
    verifie('création demandée : ' . $qc, $t->fichier($qc));
}
verifie('faute « crerr un fichier doc » (test admin 28/09 04:54) → demande de création', $t->fichier('crerr un fichier doc pour expliquer le fichier excel'));
verifie('« crerr » + affirmation sans outil → relance création', $t->relance('crerr un fichier doc pour expliquer le fichier excel', 'I have created the Word document "Explication.docx" to explain the Excel file.', $tous));
verifie('« explique comment créer un fichier excel » reste une question', !$t->fichier('explique comment créer un fichier excel'));
verifie('« crée un document pour expliquer comment faire » = demande de fichier', $t->fichier('crée un document pour expliquer comment faire'));
verifie('« Comment créer un pdf ? » reste une question, pas une demande', !$t->fichier('Comment créer un pdf ?'));
$r = $t->relanceGenerale('creer un pdf a partir du fichier excel', "It seems there is no direct tool available to convert an Excel file to a PDF. Would you like me to create a new PDF document for you? If so, I'll proceed with that.", $tous);
verifie('« Would you like me to create a new PDF…? » en réponse à une demande de PDF → relance création', $r === RagSousTest::CREATION_NUDGE, (string)$r);
$r = $t->inconnu(['ok' => false, 'error' => 'Unknown tool: convert_file']);
verifie('outil inventé (convert_file) → l\'erreur explique la vraie marche (extract_file_text puis create_file)', str_contains($r['error'], 'extract_file_text') && str_contains($r['error'], 'create_file') && str_contains($r['error'], 'never invent'));
verifie('autre erreur → inchangée', $t->inconnu(['ok' => false, 'error' => 'Folder not found'])['error'] === 'Folder not found');
$t = new RagSousTest();
$r = $t->finir("I have created a new PDF document named \"Rendez_vous.pdf\".\n\n[EVA: file created in an earlier turn: Rendez_vous.pdf]", [], "creat pdf");
verifie('marqueur interne recopié par le modèle → retiré de l\'affichage, note « aucun fichier » ajoutée', !str_contains($r, '[EVA:') && str_contains($r, 'ℹ️'), $r);

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
verifie('affirmation sans lien ni fichier → note « aucun fichier créé »', str_contains($r, 'ℹ️ Aucun fichier'), $r);
$t = new RagSousTest();
$r = $t->finir("I've created the Excel file for you. Anything else?", [], $q);
verifie('formulation non reconnue par une regex ? couverte quand même par la note factuelle', str_contains($r, 'ℹ️ Aucun fichier'), $r);
$t = new RagSousTest();
$r = $t->finir("Voici le tableau :\n| a | b |", [], "Fais-moi un tableau comparatif PHP 8.2 vs 8.3");
verifie('tableau dans le chat (pas une demande de fichier) → intact, sans note', $r === "Voici le tableau :\n| a | b |", $r);
$t = new RagSousTest();
$t->collecter('copy_file', ['ok' => true, 'result' => 'Copied to Documents/budget-copie.xlsx']);
$r = $t->finir("La copie est faite.", [], "Crée un fichier excel copie du budget");
verifie('un autre outil d\'écriture a réussi (copy_file) → pas de note « aucun fichier »', !str_contains($r, 'ℹ️'), $r);
verifie('copy_file compte comme tentative → pas de relance', !$t->relance("Crée un fichier excel copie du budget", "J'ai créé la copie.", $outils));
foreach (["https://nextcloud.example.com/index.php/f/5", "https://votre-domaine.com/index.php/f/5"] as $u) {
    $t = new RagSousTest();
    verifie('hôte d\'exemple → traité comme inventé, faux lien retiré : ' . $u, str_contains($t->finir("Ici : $u"), '⚠️'));
}
foreach (["http://localhost/index.php/f/3660074", "https://your-nextcloud/index.php/f/5", "http://nextcloud.local/remote.php/dav/files/hicham/x.xlsx"] as $u) {
    $t = new RagSousTest();
    verifie('hôte inventé traité comme le nôtre → faux lien retiré : ' . $u, str_contains($t->finir("Ici : $u"), '⚠️'));
}
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
$ext = "Doc : https://help.nextcloud.com/index.php/f/5";
verifie('lien /f/ d\'un vrai site public → intact', $t->finir($ext) === $ext, $t->finir($ext));
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
verifie('historique : ligne 📄 → marqueur EVA, sans lien ni échappement', $t->historique($h) === "J'ai créé le fichier.\n\n[EVA: file created in an earlier turn: Rendezvous_summary.docx]", $t->historique($h));
$h2 = "📄 **a&lt;b.md** — [Open](http://h/workspace/index.php/f/1) · [Download](http://h/workspace/remote.php/dav/files/hicham/a%3Cb.md)";
verifie('historique : entités &lt; décodées dans le nom', $t->historique($h2) === "[EVA: file created in an earlier turn: a<b.md]", $t->historique($h2));
verifie('historique : texte sans ligne 📄 inchangé', $t->historique("Bonjour 📄 **x**") === "Bonjour 📄 **x**");

echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
