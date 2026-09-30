<?php
/**
 * Style de présentation global (branche style-global, 30/09) : les règles de mise en page de l'admin sont dans le
 * prompt système de TOUS les comptes (et non plus seulement dans son KNOWLEDGE.md, que le prompt traite comme des
 * faits non fiables). Test statique du vrai fichier : constante présente, contenu attendu, bien injectée.
 * Usage : php tests/test_style_global.php src/RagService.php
 */
$src = file_get_contents($argv[1] ?? '');
if (!is_string($src) || $src === '') { fwrite(STDERR, "source introuvable\n"); exit(2); }
$echecs = 0;
function verifie(string $nom, bool $ok): void { global $echecs; echo ($ok ? '✅ ' : '❌ ') . "$nom\n"; if (!$ok) $echecs++; }

function constante(string $src, string $nom): ?string {
    if (preg_match("~public const $nom = (.*?);\n~s", $src, $m) !== 1) return null;
    return eval('return ' . $m[1] . ';');   // expression littérale du dépôt (concaténation de chaînes), jamais une entrée extérieure
}
$style = constante($src, 'PRESENTATION_STYLE');
$talk = constante($src, 'PRESENTATION_STYLE_TALK');
if ($style === null || $talk === null) { echo "❌ constante absente\n"; exit(1); }

verifie('constante non vide et raisonnable (< 2 000 caractères)', is_string($style) && strlen($style) > 200 && mb_strlen($style) < 2000);
verifie('règles clés présentes (tableau, étapes numérotées, blocs de code, emoji, gras)',
    str_contains($style, 'Markdown table') && str_contains($style, 'numbered steps') && str_contains($style, 'fenced code blocks')
    && str_contains($style, 'emoji') && str_contains($style, '**bold**'));
verifie('pas d\'ancien nom « EVA AI » ni de HTML imposé', stripos($style, 'EVA AI') === false && !str_contains($style, '<div'));
verifie('pas d\'invitation à inventer des images', str_contains($style, 'invented image links'));
verifie('(revue) limité à la réponse de chat, jamais au contenu des fichiers créés', str_contains($style, 'only to your chat reply') && str_contains($style, 'never to the content of files'));
verifie('(revue) n\'interdit pas un fichier HTML demandé (« decorative » seulement)', str_contains($style, 'decorative HTML') && !str_contains($style, 'Never output HTML'));
verifie('(revue) Talk : court, sans titres ni tableaux, borné au chat', str_contains($talk, '1-3 sentences') && str_contains($talk, 'without headings, tables') && str_contains($talk, 'only to your chat reply'));

// Injectée dans buildMessages, juste après la règle Markdown/langue et AVANT les outils (donc pour tous les comptes).
$bm = substr($src, strpos($src, 'private function buildMessages('));
$pos = strpos($bm, 'self::PRESENTATION_STYLE');
$md = strpos($bm, 'uiLanguage()');
$outils = strpos($bm, '($actions');
verifie('injectée dans buildMessages', $pos !== false);
verifie('(revue) après la règle de langue COMPLÈTE (uiLanguage) et avant le bloc des outils', $pos !== false && $md !== false && $outils !== false && $md < $pos && $pos < $outils);
verifie('injectée une seule fois, choisie selon la surface Talk', substr_count($src, 'self::PRESENTATION_STYLE ') + substr_count($src, 'self::PRESENTATION_STYLE)') === 1
    && str_contains($bm, '$this->onTalkSurface() ? self::PRESENTATION_STYLE_TALK : self::PRESENTATION_STYLE'));
verifie('(revue) docblock des personas de nouveau juste au-dessus de PERSONAS', preg_match('~Preset persona templates \(Issue #90\).*?\*/\s*public const PERSONAS~s', $src) === 1);

echo $echecs === 0 ? "\nTOUT VERT (11 cas)\n" : "\n$echecs ÉCHEC(S)\n";
exit($echecs === 0 ? 0 : 1);
