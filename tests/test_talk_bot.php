<?php
/**
 * Bot Talk renommé « Infinity AI » (28/09) : il répond à @Infinity AI ET à l'ancien @Eva, et retire la mention avant
 * de lire la commande. Méthodes extraites telles quelles de app/lib/Listener/TalkBotListener.php.
 * Usage sans PHP local : … php <ce fichier> php://stdin < app/lib/Listener/TalkBotListener.php
 */
$source = file_get_contents($argv[1] ?? 'php://stdin');
function extraire(string $src, string $nom): string {
    $debut = strpos($src, 'private function ' . $nom . '(');
    if ($debut === false) { fwrite(STDERR, "méthode $nom absente\n"); exit(2); }
    $ouv = strpos($src, '{', strpos($src, ')', $debut)); $niv = 0;
    for ($i = $ouv, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $niv++; elseif ($src[$i] === '}' && --$niv === 0) return substr($src, $debut, $i - $debut + 1);
    }
    exit(2);
}
eval('class Conf { public function __construct(public string $t) {} public function get(string $k): string { return $this->t; } }
class BotSousTest { public function __construct(public Conf $appConfig) {} '
    . extraire($source, 'triggerPattern') . extraire($source, 'isExplicitlyMentioned') . extraire($source, 'stripMention')
    . ' public function mention(string $c): bool { return $this->isExplicitlyMentioned($c); } public function nettoie(string $c): string { return $this->stripMention($c); } }');
$echecs = 0; $total = 0;
function verifie(string $nom, bool $ok, string $d = ''): void { global $echecs, $total; $total++; echo ($ok ? '✅ ' : '❌ ') . $nom . ($ok ? '' : " → $d") . "\n"; if (!$ok) $echecs++; }
$b = new BotSousTest(new Conf('Eva'));   // réglage actuel en production (défaut)
foreach (['@Infinity AI quelle heure est-il ?', '@infinity ai /help', '@InfinityAI résume', '@Infinity-AI salut', '@Eva /help', '@eva bonjour', 'Infinity AI, tu es là ?'] as $m) {
    verifie("répond : « $m »", $b->mention($m));
}
foreach (['Bonjour à tous', 'On se voit demain ?', 'Evaluation du projet', 'infinity loop', 'Mon AI préférée'] as $m) {
    verifie("ne répond pas : « $m »", !$b->mention($m));
}
verifie('mention retirée : « @Infinity AI /help » → « /help »', $b->nettoie('@Infinity AI /help') === '/help', $b->nettoie('@Infinity AI /help'));
verifie('mention retirée : « @Eva /status » → « /status »', $b->nettoie('@Eva /status') === '/status', $b->nettoie('@Eva /status'));
verifie('mention retirée : « @InfinityAI, résume » → « résume »', $b->nettoie('@InfinityAI, résume') === 'résume', $b->nettoie('@InfinityAI, résume'));
$c = new BotSousTest(new Conf('Jarvis'));   // déclencheur personnalisé par l'admin : gardé, en plus des deux noms
verifie('déclencheur personnalisé « Jarvis » toujours reconnu', $c->mention('@Jarvis aide-moi') && $c->mention('@Infinity AI aide-moi') && $c->mention('@Eva aide-moi'));
$d = new BotSousTest(new Conf(''));
verifie('déclencheur vide → Infinity AI et Eva quand même', $d->mention('@Infinity AI ?') && $d->mention('@Eva ?'));
verifie('aide affichée avec @Infinity AI', substr_count($source, '"- @Infinity AI /') === 5 && !str_contains($source, '"- @Eva /'));
echo $echecs === 0 ? "\nRÉSULTAT : $total/$total réussis\n" : "\nRÉSULTAT : $echecs échec(s) sur $total\n";
exit($echecs === 0 ? 0 : 1);
