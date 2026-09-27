# eva-style : mise en page « colonne de conversation » pour eva_ai

`eva-chatgpt.css` change l'apparence du chat d'eva_ai (Nextcloud 34) : colonne centrée de 48rem,
bulles utilisateur à droite, réponses sans bulle, zone de saisie flottante avec bouton d'envoi rond.
Aucun élément de marque tiers n'est repris. Toutes les règles sont limitées à
`#content-vue.eva-ai-app`, donc le reste de Nextcloud n'est pas touché. Les sélecteurs et leurs
preuves sont dans `SELECTEURS.md`.

**Rien n'a été exécuté sur un serveur.** Les commandes ci-dessous sont à lancer par l'admin, sur
`workspace4` (`192.168.1.99`, vérifier le nom d'hôte avant d'agir).

## Installation (app officielle « Custom CSS », `theming_customcss`)

Toutes les commandes `occ` passent par Compose, avec `</dev/null` :

```bash
cd /home/ubuntu/docker
OCC='sudo docker compose --env-file .env exec -T -u www-data app php occ'

# 1. L'app est-elle déjà là ? (lecture seule)
$OCC app:list </dev/null | grep -i customcss

# 2. Installer l'app (elle s'active par défaut), ou seulement l'activer si elle est déjà présente
$OCC app:install theming_customcss </dev/null
$OCC app:enable  theming_customcss </dev/null

# 3. SAUVEGARDER le CSS personnalisé déjà en place avant de l'écraser (il peut être vide)
$OCC config:app:get theming_customcss customcss </dev/null > ~/customcss.avant-eva.$(date +%F-%H%M).css
```

**L'étape 4 remplace la valeur existante** : n'y aller qu'après avoir lu la sauvegarde de l'étape 3
et confirmé le remplacement.

Voie A, par l'interface (la plus simple) : *Paramètres d'administration → Thème*, champ
« Custom CSS ». Coller le contenu de `eva-chatgpt.css`, puis enregistrer. L'emplacement exact du
champ dans NC 34 est **[non vérifié]**.

Voie B, par `occ` (copier d'abord le fichier sur le serveur, par exemple avec
`scp eva-chatgpt.css ubuntu@192.168.1.99:/home/ubuntu/eva-style/`) :

```bash
cd /home/ubuntu/docker
OCC='sudo docker compose --env-file .env exec -T -u www-data app php occ'

# 4. Écrire le CSS (clé vérifiée dans le code de l'app : getAppValue('theming_customcss', 'customcss'))
$OCC config:app:set theming_customcss customcss \
     --value="$(cat /home/ubuntu/eva-style/eva-chatgpt.css)" </dev/null

# 5. Forcer le rechargement : la feuille est servie avec un cache navigateur de 24 h, et son URL
#    porte ?v=<cachebuster>. L'interface met cette valeur à jour toute seule, occ ne le fait pas.
$OCC config:app:set theming_customcss cachebuster --value="$(date +%s)" </dev/null

# 6. Contrôle : la taille doit correspondre à celle du fichier
$OCC config:app:get theming_customcss customcss </dev/null | wc -c
```

Pour vérifier : ouvrir eva et recharger la page. En cas de doute, ouvrir les outils de
développement : une feuille servie par `theming_customcss` (URL terminée par `?v=<cachebuster>`) doit être chargée. Le chemin exact de la route est **[non vérifié]**.

## Retour arrière (sans suppression de données)

- **Le plus rapide** : `$OCC app:disable theming_customcss </dev/null`. La feuille n'est plus chargée
  et la valeur reste stockée. `app:enable` la réactive.
- **Revenir au CSS précédent** : remettre la sauvegarde de l'étape 3 avec la même commande que
  l'étape 4 (`--value="$(cat ~/customcss.avant-eva.<date>.css)"`), puis relancer l'étape 5. Ceci
  **écrase** la valeur en place, donc confirmation requise.
- Vider le champ dans l'interface revient au même, mais **efface** le CSS actuel : confirmation requise.

## Limites

- **Les classes d'eva peuvent changer** à une mise à jour. Le chat est construit en JS avec des classes
  courtes (`rm`, `rb`, `rt`…). Si l'une disparaît, la règle correspondante ne s'applique plus et
  l'élément reprend son aspect d'origine. Rien ne casse, mais il faudra refaire le relevé.
- La feuille est chargée **sur toutes les pages** de Nextcloud. Elle ne s'applique qu'à eva grâce au
  préfixe `#content-vue.eva-ai-app`.
- Deux `!important` (fond et couleur de la bulle `.rb`) sont inévitables : eva écrit ces valeurs en
  style en ligne.
- Le bouton d'envoi affiche ↑ (■ pendant la génération). Son libellé traduit reste lu par les
  lecteurs d'écran.
- Non stylés : accueil, fenêtre « Personnaliser » (insérée dans `<body>`), mode admin, vues
  Documents / Réglages. Détails dans `SELECTEURS.md`.
- **[non vérifié]** : compatibilité de la version de `theming_customcss` publiée sur l'App Store avec
  NC 34. Le `info.xml` de la branche `master` déclare `max-version="35"`, pas la version publiée.
  Même réserve pour le comportement exact de `config:app:set` sur NC 34 (valeurs typées / *lazy*).
- CSS non testé en navigateur : il a été rédigé à partir du bundle seul.

**Une capture d'écran de l'interface actuelle d'eva** (clair et sombre, bureau et mobile, avec une
réponse contenant code, tableau, sources et outils) permettrait d'ajuster les espacements et les
couleurs.
