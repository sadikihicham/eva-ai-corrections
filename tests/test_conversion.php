<?php
/**
 * Tests de convert_file, de la confirmation avant écrasement et de read_file sur un PDF (28/09, demande admin :
 * « conversion pdf to doc et doc to pdf » ; « refuser d'écraser un fichier existant, ou demander confirmation »).
 * Les VRAIES méthodes sont extraites de src/ActionExecutor.php et chargées par eval (code de NOTRE dépôt) ;
 * simulés : le dossier de l'utilisateur, l'extraction de texte (indexeur) et l'écriture (createFile, qui a ses
 * propres tests : test_pdf.php, test_xlsx.php). Aucune écriture.
 * Usage : php tests/test_conversion.php src/ActionExecutor.php   (ou php://stdin)
 */
set_error_handler(function (int $n, string $m): bool { global $echecs; echo "❌ AVERTISSEMENT PHP : $m\n"; $echecs = ($echecs ?? 0) + 1; return true; });
$source = file_get_contents($argv[1] ?? 'php://stdin');
function extraire(string $src, string $nom): string {
    $debut = strpos($src, 'private function ' . $nom . '(');
    if ($debut === false) { fwrite(STDERR, "méthode $nom absente\n"); exit(2); }
    $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
    for ($i = $ouv; ; $i++) { if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) break; }
    return substr($src, $debut, $i - $debut + 1);
}
function constante(string $src, string $nom): string {
    if (!preg_match('/private const ' . $nom . ' = .*?;\n/s', $src, $m)) { fwrite(STDERR, "constante $nom absente\n"); exit(2); }
    return $m[0];
}
class File { public function __construct(public string $contenu = '', public int $taille = 10) {} public function getSize(): int { return $this->taille; } public function getContent(): string { return $this->contenu; } }
class Folder {
    /** @param array<string,File> $fichiers */
    public function __construct(public array $fichiers = []) {}
    public function nodeExists(string $p): bool { return isset($this->fichiers[$p]); }
    public function get(string $p): File { if (!isset($this->fichiers[$p])) throw new RuntimeException('absent'); return $this->fichiers[$p]; }
}
$corps = implode("\n", array_map(fn($m) => extraire($source, $m), ['convertFile', 'convertTargetPath', 'existingWriteTargets', 'readFile', 'cleanPath']));
$consts = implode('', array_map(fn($c) => constante($source, $c), ['EXTRACTED_FORMATS', 'CONVERT_TARGETS', 'MAX_READ_CHARS', 'MAX_READ_CHUNK_CHARS', 'MAX_READ_FILE_BYTES']));
eval('class ConvSousTest {
    ' . $consts . '
    public array $ecrit = []; public array $extraits = []; public string $texte = "";  public bool $encore = false;
    private function resolve(Folder $home, string $p) { return $home->get($p); }
    private function extractFileText(Folder $home, array $args): array { $this->extraits[] = $args["path"]; return ["ok" => true, "result" => ["path" => $args["path"], "content" => $this->texte, "has_more" => $this->encore]]; }
    private function createFile(Folder $home, array $args): array { $this->ecrit[] = $args; return ["ok" => true, "result" => "Created " . $args["path"]]; }
    public function conv(Folder $h, array $a): array { return $this->convertFile($h, $a); }
    public function cible(array $a): ?string { return $this->convertTargetPath($a); }
    public function existants(Folder $h, string $n, array $a): array { return $this->existingWriteTargets($h, $n, $a); }
    public function lire(Folder $h, array $a): array { return $this->readFile($h, $a); }
    ' . $corps . '
}');
$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : "\n     → $d") . "\n"; if (!$ok) $echecs++; }
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$home = new Folder(['Taux_de_chômage.pdf' => new File('%PDF-1.4 binaire'), 'Documents/Rapport.docx' => new File('PK'), 'notes.md' => new File("# Titre\nligne"), 'Taux_de_chômage.docx' => new File('PK')]);
$t = new ConvSousTest();

// 1. nom du fichier produit
verifie('pdf → docx : même dossier, nouvelle extension', $t->cible(['path' => 'Documents/Rapport.pdf', 'target_format' => 'docx']) === 'Documents/Rapport.docx');
verifie('« doc » demandé → .docx (EVA ne sait pas écrire .doc)', $t->cible(['path' => 'a.pdf', 'target_format' => 'doc']) === 'a.docx');
verifie('« word » / « excel » / « .PDF » acceptés', $t->cible(['path' => 'a.md', 'target_format' => 'word']) === 'a.docx' && $t->cible(['path' => 'a.pdf', 'target_format' => 'excel']) === 'a.xlsx' && $t->cible(['path' => 'a.md', 'target_format' => '.PDF']) === 'a.pdf');
verifie('format inconnu → null', $t->cible(['path' => 'a.pdf', 'target_format' => 'png']) === null);
verifie('target_path fourni → utilisé', $t->cible(['path' => 'a.pdf', 'target_format' => 'docx', 'target_path' => '/Documents/Nouveau.docx']) === 'Documents/Nouveau.docx');
verifie('dossier avec un point, fichier sans extension', $t->cible(['path' => 'v1.2/rapport', 'target_format' => 'pdf']) === 'v1.2/rapport.pdf');

// 2. conversion
$t->texte = "[Page 1]\nLe taux de chômage est le pourcentage.\n\n 1/1";
$r = $t->conv($home, ['path' => 'Taux_de_chômage.pdf', 'target_format' => 'docx']);
verifie('PDF → Word : texte extrait par l\'indexeur, écrit en .docx', !empty($r['ok']) && $t->extraits === ['Taux_de_chômage.pdf'] && ($t->ecrit[0]['path'] ?? '') === 'Taux_de_chômage.docx', $j([$r, $t->ecrit]));
verifie('marqueurs de page « [Page 1] » et « 1/1 » retirés', ($t->ecrit[0]['content'] ?? '') === 'Le taux de chômage est le pourcentage.', $j($t->ecrit[0]['content'] ?? null));
verifie('le résultat dit que seule le texte est conservé', str_contains((string)($r['result'] ?? ''), 'text only'));
$t = new ConvSousTest();
$r = $t->conv($home, ['path' => 'notes.md', 'target_format' => 'pdf']);
verifie('Markdown → PDF : lu directement (pas l\'indexeur)', !empty($r['ok']) && $t->extraits === [] && ($t->ecrit[0]['content'] ?? '') === "# Titre\nligne", $j($t->ecrit));
$t = new ConvSousTest(); $t->texte = "[Page 1]\n\n 1/1";
$r = $t->conv($home, ['path' => 'Taux_de_chômage.pdf', 'target_format' => 'docx']);
verifie('PDF scanné (aucun texte) → refus clair, RIEN écrit', empty($r['ok']) && str_contains((string)$r['error'], 'OCR') && $t->ecrit === [], $j($r));
$t = new ConvSousTest(); $t->texte = 'x'; $t->encore = true;
$r = $t->conv($home, ['path' => 'Taux_de_chômage.pdf', 'target_format' => 'docx']);
verifie('source trop longue → refus, rien écrit (jamais de conversion tronquée)', empty($r['ok']) && $t->ecrit === [], $j($r));
$t = new ConvSousTest();
verifie('même fichier en sortie → refus', empty($t->conv($home, ['path' => 'notes.md', 'target_format' => 'md'])['ok']) && $t->ecrit === []);
verifie('format de sortie non géré → refus', empty($t->conv($home, ['path' => 'notes.md', 'target_format' => 'pptx'])['ok']));
$home2 = new Folder(['image.bin' => new File("\x89PNG\0\0")]);
verifie('fichier binaire sans texte → refus', empty($t->conv($home2, ['path' => 'image.bin', 'target_format' => 'pdf'])['ok']) && $t->ecrit === []);

// 3. détection d'écrasement (la confirmation est posée par run() quand cette liste n'est pas vide)
verifie('create_file sur un fichier existant → détecté', $t->existants($home, 'create_file', ['path' => '/Documents/Rapport.docx']) === ['Documents/Rapport.docx']);
verifie('create_file nouveau fichier → rien', $t->existants($home, 'create_file', ['path' => 'Documents/Neuf.docx']) === []);
verifie('create_files : seul le chemin existant est listé', $t->existants($home, 'create_files', ['files' => [['path' => 'neuf.txt'], ['path' => 'notes.md']]]) === ['notes.md']);
verifie('convert_file vers un .docx qui existe déjà → détecté', $t->existants($home, 'convert_file', ['path' => 'Taux_de_chômage.pdf', 'target_format' => 'docx']) === ['Taux_de_chômage.docx']);
verifie('autres outils → jamais concernés', $t->existants($home, 'delete_file', ['path' => 'notes.md']) === []);

// 4. read_file sur un PDF → texte extrait, plus « %PDF-1.4 … »
$t = new ConvSousTest(); $t->texte = 'contenu lisible';
$r = $t->lire($home, ['path' => 'Taux_de_chômage.pdf']);
verifie('read_file sur un PDF → texte extrait (plus d\'octets bruts)', !empty($r['ok']) && ($r['result']['content'] ?? '') === 'contenu lisible' && $t->extraits === ['Taux_de_chômage.pdf'], $j($r));
$r = $t->lire($home, ['path' => 'notes.md']);
verifie('read_file sur un .md → lecture normale', ($r['result']['content'] ?? '') === "# Titre\nligne", $j($r));

// 5. branchement dans run() (contrôle de source) : la confirmation passe AVANT l'exécution et jamais sur un appel confirmé
$garde = strpos($source, 'if (!$confirmed && $home !== null && ($existing = $this->existingWriteTargets($home, $name, $args)) !== [])');
$execution = strpos($source, '$result = match ($name) {');
verifie('run() : confirmation d\'écrasement placée avant l\'exécution de l\'outil', $garde !== false && $execution !== false && $garde < $execution);
verifie('run() : la garde renvoie confirmation_required', $garde !== false && str_contains(substr($source, $garde, 400), "'confirmation_required' => true"));
verifie('convert_file est déclaré aux 4 endroits (outil, arguments requis, liste fichiers, exécution)', substr_count($source, "'convert_file'") >= 4);
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
