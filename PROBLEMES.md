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

**Revue adverse (agent séparé, 28/09 ~04:00) : 🔴 non déployable — 1 bloquant, 7 importants, 6 mineurs. v2 corrige :**
- n°1 BLOQUANT (fuite) : des phrases de travail partaient telles quelles vers un moteur externe (« Quelle version du
  contrat de Mme Martin… », « Qui est le CEO de notre filiale ? »). v2 : « dernière version » seulement avec un
  logiciel/produit NOMMÉ ; prix seulement pour un marché (or, bitcoin, devises) ; liste de mots de travail qui bloque
  (mon/notre, mail, devis, contrat, client, ticket, dossier, société, serveur, patient…) ; ≤ 200 caractères ; mode
  `web` toujours (`all`/`news` interrogeaient Bing/Google News quel que soit le fournisseur) ; plus de 2e envoi ;
  météo laissée à l'outil météo.
- n°2/3 : la relance réagit à l'AFFIRMATION inventée (« j'ai créé… », « je vais créer… », lien de fichier) et plus à
  la seule demande (une note ou un fichier non voulu était écrit sans confirmation) ; question de clarification
  respectée ; si l'affirmation persiste sans fichier → avertissement.
- n°4 : le message d'erreur ne suggère plus `content_base64` ; le binaire est vérifié par signature (`%PDF-`, `PK`…) ;
  liste étendue (docm, xlsm, pptm, zip, images).
- n°5/6/8 : ponctuation « » ، ؟ ” collée retirée ; comparaison du lien ENTIER (…/f/5 ≠ …/f/55) ; seul le lien est
  barré (la ligne, un tableau restent) ; liens d'un autre site jamais touchés.
- n°9/10/12 : recherche imposée après le délai, annulation respectée, `tool_result` toujours émis, `try/catch` ;
  texte non diffusé en direct quand un fichier est demandé (le faux texte n'apparaît plus).
- n°13 : dans l'historique, la ligne 📄 devient « (file created: nom) ».
- n°14 : tests 93/93 dont toutes les phrases-pièges ; **contre-épreuve : les mêmes tests sur la v1 → 38 échecs**.
  Non couverts (limite assumée) : boucles `ask()`/`askStream()` de bout en bout, liens de partage `/s/`.

**Revue de vérification (2e agent, 28/09 ~05:00) : 🔴 encore** — les listes de mots se contournent (« le taux du dollar
pour payer le fournisseur Al Futtaim… » partait encore), les affirmations ont trop de formulations (« I've created… »,
« …créé. Souhaitez-vous… ? »), et la v2 créait une RÉGRESSION (« fais un tableau comparatif » → relance → fichier
écrit sans le vouloir). **Conclusion : on change de principe, v3 sûre par construction :**
- Recherche imposée : la requête est **reconstruite** à partir des seuls mots reconnus (« php latest version »,
  « gold USD price today ») → les mots de l'utilisateur ne sortent jamais, même si le détecteur se trompe. Seule une
  demande explicite (« cherche sur internet… ») envoie la phrase (consentement). Actualités et fonctions publiques :
  laissées au modèle. « or » seulement sous la forme l'or / d'or.
- Au lieu de deviner toutes les façons d'affirmer un fichier : quand un fichier est demandé et qu'aucun n'a été
  écrit, eva ajoute TOUJOURS « ℹ️ Aucun fichier n'a été créé pour cette demande » (vrai dans 100 % des cas). La
  relance (qui peut écrire) ne part que sur une affirmation forte ; « tableau / note / markdown » seuls ne sont plus
  des demandes de fichier.
- Hôtes inventés (localhost, sans point, *.local, IP) traités comme le nôtre ; `trusted_domains` lu ; historique
  « [EVA: file created in an earlier turn: X] » (repéré si le modèle l'imite) ; `renameFile` refuse txt→pdf ;
  signatures .doc/.xls/.ppt/.7z/.webp ; trace de recherche toujours fermée dans `ask()`.
**Tests 133/133 ; contre-épreuve : la v2 en échoue 50, la v1 38 (sur 93).**

**Contre-revue ciblée v3 (3e agent, 28/09 ~06:00)** : principe validé (requête reconstruite : « aucun chemin où les
mots de l'utilisateur sortent » hors demande explicite). 1 bloquant : la « demande explicite » se déclenchait à
l'intérieur d'un texte de travail (« rédige un mail… lui demandant de vérifier sur le web… »). **v3.1** : demande
explicite = impératif EN TÊTE de message, et les mots de travail bloquent aussi ce chemin ; relance seulement sur
une affirmation à la 1re personne / « votre fichier… » (plus de passif : « votre facture a été générée » dans un mail
faisait écrire un fichier) ; tout outil d'écriture compte (copy/move/rename/restore/sticker) et annule la note ;
objet de la demande introduit par « un/une/en/new… » ; hôtes d'exemple (example.com, votre-…) = inventés ;
renameFile .md→.docx/.xlsx refusé. **Tests 146/146 ; contre-épreuve : la v3 échoue exactement aux 13 nouveaux cas.**
Reste (non bloquant, tranche suivante) : note fausse si `fileLinks()` échoue ; move/copy vers .pdf.

## Ce qui reste à faire (hors ce dépôt)
- Publier `signalement-eva-editeur.md` (dossier parent) sur GitHub, avec ces deux correctifs proposés.
- Décider si/quand appliquer 1 et 2 sur workspace4 (geste séparé, avec sauvegarde et confirmation).
- Le point 3 ne nécessite aucune action sur eva : à vérifier différemment côté utilisateur.
- Le point 4 mériterait une observation plus longue avant de conclure à un vrai défaut.
