# SearXNG interne pour eva_ai (workspace4)

## Ce que c'est
Un métamoteur SearXNG auto-hébergé, dans son **propre projet Compose** (`searxng`, dossier serveur `/srv/searxng`),
rattaché au réseau Docker `nextcloud_frontend` de la stack Nextcloud. **Aucun port publié sur l'hôte** : seul le
réseau Docker de Nextcloud le voit (le conteneur `app` l'appelle sur `http://searxng:8080`). Moteurs : Google,
Bing, DuckDuckGo, Brave, Wikipédia FR + EN, ainsi que Google News et Bing News (catégorie actualité).
Format JSON activé, limiter désactivé (instance privée), langue fr, safe_search modéré.
Image épinglée : `searxng/searxng:2026.9.25-12f8b6515` (Docker Hub, 25/09/2026).

## Pourquoi
Avec `web_search_provider=bing`, eva renvoyait des résultats hors sujet (Gmail pour « version de PHP »).
SearXNG fusionne plusieurs moteurs, dont Google, et bascule sur les autres si l'un d'eux échoue.

## Consommation estimée **[non vérifié]**
- RAM : ~150–300 Mo en usage (plafond fixé à 768 Mo), CPU quasi nul au repos (plafond 1 cœur).
- Disque : image ~200–300 Mo, cache négligeable.
- Réseau : chaque recherche d'eva déclenche ~6 requêtes sortantes (Google, Bing, DDG, Brave, 2 × Wikipédia).

## Risques
- **Blocage par les moteurs amont** : Google (CAPTCHA), Brave et DuckDuckGo bloquent parfois les IP de serveurs.
  SearXNG suspend alors le moteur fautif pendant un temps et répond avec les autres ; le test d'installation
  affiche les `moteurs sans réponse`. Si Google reste bloqué durablement, les résultats viennent de Bing, DDG et Brave.
- **Vie privée** : les requêtes des utilisateurs partent vers Google, Bing, etc., **depuis l'IP publique du
  serveur** (pas depuis les postes). Aucun cookie utilisateur n'est transmis.
- **Mises à jour** : l'image est figée. Les moteurs changent souvent côté amont, il faut monter de version de
  temps en temps (changer le tag dans `compose.yaml`, puis lancer `sudo docker compose -p searxng up -d`).
- **Langue** : eva envoie `language=all`, ce qui prime sur `default_lang: fr`. Wikipédia FR et EN sont
  interrogés tous les deux. L'actualité (Google/Bing News) ne sert que si une requête demande `categories=news`,
  ce qu'eva ne fait pas aujourd'hui.

## Commandes (depuis le Mac, dans ce dossier)
```bash
bash installer.sh                  # demande OUI, puis 9 étapes (0 à 8) ; affiche la commande de retour arrière
bash retour-arriere.sh /srv/sauvegarde-eva_ai/avant-searxng-AAAAMMJJ-HHMMSS   # remet eva, puis « down » sans -v
bash retour-arriere.sh --conteneur-seulement   # si l'installation s'est arrêtée avant de modifier eva
```
Le secret (`/srv/searxng/secret.env`, root 600) est généré sur le serveur par `openssl rand -hex 32`. Il n'est
jamais affiché et n'est jamais dans ce dépôt. Aucun script ne supprime `/srv/searxng`.

## Vérification
- Étape 6 de l'installateur : requête JSON « dernière version de Nextcloud » depuis le conteneur `app`, avec
  affichage des 3 premiers titres et des moteurs qui les ont fournis.
- À la main, sur le serveur :
  `cd /srv/searxng && sudo docker compose -p searxng ps` (état `healthy`) ; `sudo docker port <id>` (vide).
- Dans eva : « Quelle est la dernière version de Nextcloud ? » doit citer nextcloud.com, GitHub ou Wikipédia.
