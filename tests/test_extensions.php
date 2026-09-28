<?php
// Garde « extension binaire » (renommer/déplacer/copier en .pdf/.xlsx…). Lancement : source sur stdin,
// ex. docker compose exec -T -e T=<ce fichier sans la 1re ligne, en base64> app php -r 'eval(base64_decode(getenv("T")));' < src/ActionExecutor.php
$src = file_get_contents('php://stdin');
preg_match('~    private function binaryExtensionChangeError.*?\n    }\n~s', $src, $m);
eval('class T { ' . $m[0] . ' public function e($a,$b){return $this->binaryExtensionChangeError($a,$b,"moving","moved");} }');
$t = new T; $ok = 0; $n = 0;
foreach ([['notes.txt','notes.pdf',true],['a.xlsx','b.xlsx',false],['a.md','a.docx',true],['a.txt','b.txt',false],['r.PDF','copie.pdf',false],['a.csv','a.XLSX',true],['dossier','dossier.zip',true]] as [$f,$to,$err]) {
  $n++; $r = $t->e($f,$to); if (($r !== null) === $err) $ok++; else echo "ECHEC $f -> $to\n";
}
echo "$ok/$n\n";
