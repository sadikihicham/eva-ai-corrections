# eva_ai + vLLM (.39) — diagnostic complet, 27–28/09/2026

## 1. Outils cassés — HTTP 400 (CORRIGÉ, testé)
**Cause** : `RagService::canonicalToolCalls()` construit les appels d'outils au format interne d'eva
(compatible Ollama) : `arguments` en objet PHP décodé, et le message `role: tool` qui répond ne porte
jamais `tool_call_id`. Ollama tolère ce format ; la norme OpenAI, que vLLM applique strictement, exige
`arguments` en **chaîne JSON** et un `tool_call_id` qui identifie l'appel auquel on répond.
**Correction** : `src/OpenAICompatible.php`, méthode `normalizeMessages()` (branche `corrige-outils-vllm`).
**Preuve** : `tests/test_normalize_messages.py` reproduit l'erreur exacte vue en production
(21:52:56 et 22:00:43, 27/09) avec le format réel d'eva, puis démontre qu'après correction le même
échange obtient HTTP 200 et une vraie réponse du modèle. Testé contre le vrai vLLM (.39), jamais contre
un serveur simulé.

## 2. `create_file` cassé — classe introuvable (CORRIGÉ)
**Cause** : `ActionExecutor.php:2969` utilise `OCP\AppFramework\Services\IAppDataFactory`, qui n'existe
pas. Le bon espace de noms, déjà utilisé ailleurs dans le même fichier d'eva (`UserDataService.php`,
`DirtyIndexStore.php`), est `OCP\Files\AppData\IAppDataFactory`.
**Correction** : `src/ActionExecutor.marksFile.excerpt.php`.
**Preuve** : comparaison directe avec le code qui fonctionne ailleurs dans eva_ai ; correction
syntaxiquement validée (`php -l`). Pas de test d'exécution : la fonction dépend du conteneur Nextcloud
et je n'ai que l'extrait, pas le fichier complet (~3000 lignes, non rapatrié).

## 3. Arabe « déformé » (Q5) — PAS un défaut d'eva
**Cause réelle, trouvée en lisant la conversation `hicham` du 27/09 (chats `da26ccae` 17:53 et
`1b47d60f` 12:53, en lecture seule)** : le texte arabe reçu par eva est **l'exact miroir caractère par
caractère** du texte que j'avais donné dans le chat (vérifié : inverser
« اكتب فقرة قصيرة تشرح فوائد مشاركة الملفات بشكل آمن داخل الشركة. » caractère par caractère
reproduit précisément ce qu'eva a stocké). Un appel direct au même modèle avec le texte arabe correct,
même noyé dans ~14 500 jetons de consignes anglaises et 40 outils, répond correctement (2 essais, 27/09).
**Conclusion** : la déformation se produit **avant** qu'eva reçoive le texte — très probablement au
copier-coller depuis un terminal qui ne gère pas l'affichage bidirectionnel (RTL). **Ce n'est pas un
défaut d'eva ni de vLLM.** Ma conclusion du 27/09 (« défaut d'eva ») était fausse ; corrigée ici.
**Pour vérifier en 30 secondes** : taper le texte arabe directement au clavier dans eva, sans le copier
depuis un terminal.

## 4. Q3/Q8 : eva répond parfois sans appeler l'outil
Observé deux fois : Q3 (agenda) a répondu « aucun rendez-vous » sans preuve d'appel d'outil ; Q8
(recherche web) a inventé une version de Nextcloud sans lancer la recherche. Le modèle décide seul, à
chaque tour, s'il appelle un outil (`tool_choice` non forcé par eva) — ce n'est pas un bug de code
identifiable, mais un risque : sur vLLM, le modèle **invente** au lieu d'appeler l'outil, ce qu'il ne
faisait pas de façon aussi visible avec Ollama dans nos tests. **Non corrigé, à surveiller** — hors
scope d'une correction de code ponctuelle.

## Ce qui reste à faire (hors ce dépôt)
- Publier `signalement-eva-editeur.md` (dossier parent) sur GitHub, avec ces deux correctifs proposés.
- Décider si/quand appliquer 1 et 2 sur workspace4 (geste séparé, avec sauvegarde et confirmation).
- Le point 3 ne nécessite aucune action sur eva : à vérifier différemment côté utilisateur.
- Le point 4 mériterait une observation plus longue avant de conclure à un vrai défaut.
