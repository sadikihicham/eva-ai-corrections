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

## 4 bis. Q8 approfondi (28/09 01:15–01:40) — le modèle croit savoir, il ne cherche pas
Mesures reproductibles (`tests/eval_recherche_web.py`, `tests/eval_latence_reflexion.py`) : vrai payload d'eva
(prompt système + 78 outils, reconstitué en lecture seule), vrai vLLM, 15 questions dont 5 jamais vues pendant la
mise au point et 5 témoins qui ne doivent PAS déclencher de recherche.
- `web_search_enabled=1`, l'outil est bien proposé au modèle, et il fonctionne (appel direct : 5 résultats).
- Le modèle cherche pour « le cours de l'or » ou « les actualités », **jamais** pour « la dernière version de X »
  (Nextcloud, PHP, Python, Ubuntu, iPhone) : il répond avec assurance des versions périmées de ses données
  d'entraînement. Témoins jamais vus : **1/5**.
- Retouches du prompt : **inefficaces** (suppression de la consigne « questions factuelles sans outil », règle en fin
  de prompt, règle dans la description de l'outil, instructions personnalisées : 0 à 2/5 ; certaines font même
  inventer le cours de l'or). Moins d'outils (3 au lieu de 78) : pire.
- **Réflexion activée : 5/5**, mais **répond en anglais** à des questions françaises et coûte +2,5 à +5 s par réponse.
- Dès que la recherche est faite, la réponse est juste (« Nextcloud 35, 16/09/2026 ») : tout se joue dans la décision.
- Correction de ma part : `qwen3-30b-agent` n'est pas parfaitement déterministe (écarts d'un passage à l'autre).
**Pistes, par ordre recommandé** : (1) rejouer cette évaluation sur les candidats de P4 (Gemma 4, Qwen3.6), qui
décideront peut-être mieux ; (2) sinon, un petit détecteur de questions « datées » dans eva qui impose `web_search`
au premier tour (`tool_choice`), déterministe ; (3) écartées : réflexion (anglais + lenteur), retouches du prompt.

## 5. Liens « Ouvrir / Télécharger » sous chaque fichier créé (branche `liens-fichiers-crees`)
Demande de l'admin (28/09). `create_file`, `create_files` et `create_note` renvoient désormais `url` (ouvre le
fichier dans Nextcloud, `/index.php/f/<id>`) et `download_url` (WebDAV, téléchargement direct) ; eva ajoute sous sa
réponse une ligne par fichier « 📄 nom — [Ouvrir](…) · [Télécharger](…) », **par le code** (même mécanisme que les
images, `appendImageMarkdown`), libellés FR/AR/DE/EN selon la langue de l'interface. Liens privés : connexion et
droit d'accès requis, aucun partage public créé. Un échec de génération de lien ne fait jamais échouer l'écriture.
**Tests** : `tests/test_liens_fichiers.php` 7/7 sur le vrai code (a trouvé un défaut : un lot `create_files` avec un
échec ne montrait aucun lien — corrigé) ; génération réelle par Nextcloud sur un fichier existant (a trouvé un 2e
défaut : `/workspace/workspace/` en ligne de commande — corrigé) ; les deux adresses répondent 401 sans connexion.
**Reste** : le clic réel avec une session ouverte, après déploiement.

**Revue adverse (agent séparé, 28/09 ~02:10) : aucun bloquant, 3 points importants — tous traités.**
- n°1 : après une confirmation, le navigateur aurait affiché « ✅ Share created: … » (il lit `result.url` comme un
  partage). → **Conception changée** : `result` redevient la phrase d'origine (« Created … ») et les liens voyagent
  dans une clé séparée `file` (`ActionExecutor::fileLinks()`). Aucun consommateur d'eva ne voit de changement de
  format ; le bouton « Share created » ne peut plus apparaître (règle aussi n°4 et n°11).
- n°2 : …/f/12345 pris pour « déjà cité » si la réponse contient …/f/123456 → comparaison avec limite de fin de lien.
- n°3 : si le modèle recopiait le seul lien « Ouvrir », toute la ligne sautait (téléchargement perdu) → la ligne
  n'est omise que si les DEUX liens sont déjà cités.
- Mineurs corrigés : nom lu par `Node::getName()` (n°6) ; caractères invisibles (U+202E…) retirés et `|` échappé
  (n°7) ; « … +N autres fichiers » au-delà de 20 (n°10).
- Mineurs reportés (sans risque pour ce déploiement) : langue des liens en tâche de fond (n°5, les outils fichiers
  sont refusés hors web de toute façon) ; lien périmé si le fichier est renommé/supprimé dans la même réponse (n°8) ;
  pas de lien si la réponse finale est vide (n°9) ; fragile si la racine web était `/r` ou `/i` (ici `/workspace`).
- Les tests ont trouvé un défaut dans la correction elle-même (délimiteur `~` dans la regex → regex invalide, un test
  passait par accident) : corrigé ; toute alerte PHP compte désormais comme un échec. **10/10.**

**Déployé le 28/09 01:56** (f236b1a). Test réel de l'admin : docx ✅ ouvrir + télécharger. Excel et PDF ❌ — voir §6 :
ce n'était pas le code des liens, **aucun fichier n'avait été créé**.

## 6. eva invente au lieu d'agir (branche `anti-invention`, 28/09)
**Constat (conversation `87004a68` + index des fichiers, lecture seule)** : pour « crée un fichier excel / pdf », le
modèle n'a appelé AUCUN outil ; il a répondu « I have created… » et a recopié la ligne 📄 de la réponse précédente
(vue dans l'historique) avec des identifiants inventés (3660074/75 = dossiers internes de Collabora). Même le docx
contenait des rendez-vous inventés (agenda jamais lu). Et eva ne sait pas générer de PDF : du texte aurait été
écrit dans un `.pdf` illisible.

**Q8 : la vraie cause est le moteur de recherche.** Recherche imposée + vrais résultats + vrai vLLM : 4/10 réponses
toujours fausses. Les résultats du moteur configuré (`bing`) sont hors sujet (« dernière version de Nextcloud » →
hôtels ; « version de PHP » → Gmail ; « Python » → WhatsApp) : le modèle les écarte, à raison, et répond de
mémoire. DuckDuckGo : excellent à la 1re requête, vide dès la 2e (blocage anti-robot). → **Décision d'infrastructure**
(SearXNG auto-hébergé, qui peut interroger Google, ou fournisseur à clé) : aucun code ne compense un moteur qui
renvoie Gmail pour « PHP ».

**Corrections (par le code, indépendantes du modèle et du fournisseur)** :
- `forcedWebSearch()` : demande explicite de recherche web, ou question qui change avec le temps (dernière
  version, actualités, cours/taux « actuel », météo, titulaire d'une fonction) → `web_search` exécuté AVANT le
  modèle et injecté comme un appel d'outil. Exclus : questions sur les données de l'utilisateur (« mon serveur »),
  messages > 300 caractères. Mode `news` seulement pour l'actualité ; repli sur `web` si la recherche échoue.
  `tool_choice` testé aussi (fonctionne sur vLLM, ~1 s) mais les requêtes du modèle ≈ la question : pas de gain,
  et il ne marche pas avec Ollama.
- Relance (au plus 2) si un fichier est demandé et qu'aucun outil fichier n'a été tenté : **mesurée sur le vrai
  vLLM** — version courte : 6/6 `create_file` mais contenu INVENTÉ ; version retenue (« lis d'abord les données ») :
  8/9 corrects (lit l'agenda puis écrit).
- `removeUnbackedFileLinks()` : un lien de fichier Nextcloud qui ne mène à aucun fichier de l'utilisateur (et ne
  vient ni d'un outil de ce tour, ni de l'utilisateur) est retiré avec sa phrase, remplacé par un avertissement.
- Lignes 📄 retirées de l'historique envoyé au modèle (il ne peut plus les imiter).
- `createFile` refuse d'écrire du texte dans `.pdf/.doc/.xls/.ppt/.pptx/.odt/.ods/.odp/.epub` (fichier corrompu)
  et propose .docx/.xlsx/.md.
**Tests** : `tests/test_anti_invention.php` 58/58 (dont le cas réel Excel recopié tel quel) ; contre-épreuve : avec
les gardes neutralisés, 20 échecs (le test détecte bien le défaut) ; `test_liens_fichiers.php` 10/10.
**Limites connues** : le texte déjà affiché en direct est remplacé par la réponse finale à la fin (comme avant) ;
la question part telle quelle vers le moteur externe (déjà le cas quand le modèle cherche) ; pas de générateur PDF.

## Ce qui reste à faire (hors ce dépôt)
- Publier `signalement-eva-editeur.md` (dossier parent) sur GitHub, avec ces deux correctifs proposés.
- Décider si/quand appliquer 1 et 2 sur workspace4 (geste séparé, avec sauvegarde et confirmation).
- Le point 3 ne nécessite aucune action sur eva : à vérifier différemment côté utilisateur.
- Le point 4 mériterait une observation plus longue avant de conclure à un vrai défaut.
