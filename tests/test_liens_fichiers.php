<?php
/**
 * Test unitaire des liens « Ouvrir / Télécharger » ajoutés sous la réponse d'eva (branche liens-fichiers-crees).
 *
 * Teste le VRAI code : les méthodes collectToolSources(), addCreatedFile() et appendFileLinks() sont extraites
 * telles quelles de src/RagService.php (pas recopiées à la main), puis chargées dans une classe de test minimale.
 * Aucune dépendance à Nextcloud, aucune écriture : calcul pur.
 *
 * Usage : php tests/test_liens_fichiers.php src/RagService.php
 *     ou (sans PHP local) : cat tests/test_liens_fichiers.php | … php -- src/RagService.php  (source passée en base64)
 */

// Toute alerte PHP (regex invalide, index absent…) compte comme un ÉCHEC : le 28/09, une regex cassée passait
// silencieusement un test « par accident ».
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

// eval() ne charge ici que du code extrait de NOTRE fichier src/RagService.php (dépôt versionné), jamais de
// données extérieures : c'est ce qui permet de tester le vrai code sans le recopier ni démarrer Nextcloud.
$corps = extraire($source, 'collectToolSources') . "\n" . extraire($source, 'addCreatedFile') . "\n" . extraire($source, 'appendFileLinks');
eval('class RagSousTest {
    public array $createdFiles = [];
    public bool $fileToolAttempted = false;   // ajoutée par la branche anti-invention
    public array $toolSources = [];
    public array $toolImages = [];
    public string $langue = "fr";
    private function uiLanguage(): string { return $this->langue; }
    private function addToolSource(string $url, array $item): void { $this->toolSources[] = $url; }
    public function collecter(string $outil, array $res): void { $this->collectToolSources($outil, $res); }
    public function ajouter(string $reponse): string { return $this->appendFileLinks($reponse); }
    ' . $corps . '
}');

$echecs = 0;
function verifie(string $nom, bool $ok, string $detail = ''): void {
    global $echecs;
    echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok || $detail === '' ? '' : "\n     → " . $detail) . "\n";
    if (!$ok) $echecs++;
}
function fichier(int $id, string $chemin): array {
    // Enveloppe réelle d'ActionExecutor : `result` reste le TEXTE d'avant, les liens sont dans `file`.
    return ['ok' => true, 'result' => 'Created ' . $chemin, 'file' => [
        'name' => basename($chemin), 'path' => $chemin, 'file_id' => $id,
        'url' => "http://nc.test/workspace/index.php/f/$id",
        'download_url' => 'http://nc.test/workspace/remote.php/dav/files/hicham/' . implode('/', array_map('rawurlencode', explode('/', $chemin)))]];
}
function lignes(string $r): int { return substr_count($r, '📄'); }

// 1. aucun fichier créé : réponse inchangée
$t = new RagSousTest();
verifie('aucun fichier créé → réponse inchangée', $t->ajouter('Bonjour.') === 'Bonjour.');

// 2. un fichier, interface en français
$t = new RagSousTest();
$t->collecter('create_file', fichier(42, 'Documents/test-recette-eva.md'));
$r = $t->ajouter('Le fichier a été créé.');
verifie('un fichier (fr) → ligne Ouvrir / Télécharger ajoutée',
    str_contains($r, '📄 **test-recette-eva.md** — [Ouvrir](http://nc.test/workspace/index.php/f/42) · [Télécharger](http://nc.test/workspace/remote.php/dav/files/hicham/Documents/test-recette-eva.md)'), $r);

// 3. interface en arabe
$t = new RagSousTest(); $t->langue = 'ar';
$t->collecter('create_file', fichier(7, 'تقرير.md'));
$r = $t->ajouter('تم.');
verifie('interface arabe → libellés فتح / تنزيل, nom arabe intact', str_contains($r, '[فتح](') && str_contains($r, '[تنزيل](') && str_contains($r, '**تقرير.md**'), $r);

// 4. les DEUX liens déjà écrits par le modèle : pas de doublon
$t = new RagSousTest();
$f = fichier(42, 'a.md'); $t->collecter('create_file', $f);
$r = $t->ajouter('Ouvrir : ' . $f['file']['url'] . ' — télécharger : ' . $f['file']['download_url']);
verifie('les deux liens déjà présents → ligne non répétée', lignes($r) === 0, $r);

// 5. seul le lien « Ouvrir » écrit par le modèle : la ligne est ajoutée (sinon le téléchargement serait perdu) — revue n°3
$t = new RagSousTest();
$f = fichier(42, 'a.md'); $t->collecter('create_file', $f);
$r = $t->ajouter('[Ouvrir](' . $f['file']['url'] . ')');
verifie('seul le lien Ouvrir présent → ligne ajoutée (Télécharger conservé)', lignes($r) === 1 && str_contains($r, $f['file']['download_url']), $r);

// 6. préfixe : la réponse cite …/f/123456 et …123456.md, le fichier 12345 garde sa ligne — revue n°2
$t = new RagSousTest();
$f = fichier(12345, 'x.md'); $t->collecter('create_file', $f);
$r = $t->ajouter('Voir ' . $f['file']['url'] . '6 et ' . $f['file']['download_url'] . '.bak');
verifie('lien 12345 préfixe de 123456 → pas pris pour cité', lignes($r) === 1, $r);

// 7. nom de fichier piégé (Markdown, |, caractère invisible U+202E) : échappé / retiré
$t = new RagSousTest();
$t->collecter('create_file', fichier(9, "x/un_[piège]*`|\u{202E}fdp.exe"));
$r = $t->ajouter('ok');
verifie('nom avec Markdown, | et U+202E → échappé, caractère invisible retiré',
    str_contains($r, '**un\_\[piège\]\*\`\|fdp.exe**') && !str_contains($r, "\u{202E}"), $r);

// 8. lien non http(s) (ex. javascript:) → ignoré
$t = new RagSousTest();
$f = fichier(5, 'b.md'); $f['file']['url'] = 'javascript:alert(1)';
$t->collecter('create_file', $f);
verifie('lien non http(s) → ignoré', $t->ajouter('ok') === 'ok');

// 9. create_files (un échec au milieu, lot marqué ok=false) + ancien format sans `file` + create_note
$t = new RagSousTest();
$t->collecter('create_files', ['ok' => false, 'result' => ['files' => [fichier(1, 'un.md'), ['ok' => false, 'error' => 'x'], fichier(2, 'deux.md')]]]);
$t->collecter('create_file', ['ok' => true, 'result' => 'Created ancien-format.md']);
$t->collecter('create_note', fichier(3, 'Notes/note.md'));
$r = $t->ajouter('ok');
verifie('create_files partiel + create_note → 3 lignes, ancien format ignoré sans erreur',
    lignes($r) === 3 && str_contains($r, 'un.md') && str_contains($r, 'deux.md') && str_contains($r, 'note.md'), $r);

// 10. plus de 20 fichiers sur plusieurs appels → 20 lignes + mention du reste — revue n°10
$t = new RagSousTest();
for ($i = 1; $i <= 23; $i++) { $t->collecter('create_file', fichier($i, "f$i.md")); }
$r = $t->ajouter('ok');
verifie('23 fichiers → 20 lignes + « … +3 autres fichiers »', lignes($r) === 20 && str_contains($r, '… +3 autres fichiers'), $r);

echo $echecs === 0 ? "\nRÉSULTAT : 10/10 réussis\n" : "\nRÉSULTAT : $echecs échec(s)\n";
exit($echecs === 0 ? 0 : 1);
