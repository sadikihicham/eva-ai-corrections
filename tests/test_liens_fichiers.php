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
    return ['ok' => true, 'result' => ['message' => 'Created ' . $chemin, 'path' => $chemin, 'file_id' => $id,
        'url' => "http://nc.test/workspace/index.php/f/$id",
        'download_url' => 'http://nc.test/workspace/remote.php/dav/files/hicham/' . implode('/', array_map('rawurlencode', explode('/', $chemin)))]];
}

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
verifie('interface arabe → libellés فتح / تنزيل', str_contains($r, '[فتح](') && str_contains($r, '[تنزيل](') && str_contains($r, '%D8'), $r);

// 4. le modèle a déjà écrit le lien d'ouverture : pas de doublon
$t = new RagSousTest();
$t->collecter('create_file', fichier(42, 'a.md'));
$r = $t->ajouter('Voir http://nc.test/workspace/index.php/f/42');
verifie('lien déjà présent dans la réponse → pas répété', substr_count($r, 'index.php/f/42') === 1, $r);

// 5. nom de fichier piégé (Markdown) : échappé, ne casse pas la ligne
$t = new RagSousTest();
$t->collecter('create_file', fichier(9, 'x/un_[piège]*`.md'));
$r = $t->ajouter('ok');
verifie('nom avec caractères Markdown → échappé', str_contains($r, '**un\_\[piège\]\*\`.md**'), $r);

// 6. lien non http(s) (ex. javascript:) → ignoré
$t = new RagSousTest();
$f = fichier(5, 'b.md'); $f['result']['url'] = 'javascript:alert(1)';
$t->collecter('create_file', $f);
verifie('lien non http(s) → ignoré', $t->ajouter('ok') === 'ok');

// 7. create_files (plusieurs fichiers d'un coup, dont un en échec) + ancien format texte
$t = new RagSousTest();
$t->collecter('create_files', ['ok' => false, 'result' => ['files' => [fichier(1, 'un.md'), ['ok' => false, 'error' => 'x'], fichier(2, 'deux.md')]]]);
$t->collecter('create_file', ['ok' => true, 'result' => 'Created ancien-format.md']);
$r = $t->ajouter('ok');
verifie('create_files → 2 lignes (l\'échec et l\'ancien format texte sont ignorés sans erreur)',
    substr_count($r, '📄') === 2 && str_contains($r, 'un.md') && str_contains($r, 'deux.md'), $r);

echo $echecs === 0 ? "\nRÉSULTAT : 7/7 réussis\n" : "\nRÉSULTAT : $echecs échec(s)\n";
exit($echecs === 0 ? 0 : 1);
