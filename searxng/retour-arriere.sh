#!/usr/bin/env bash
# Retour arrière de installer.sh : remet les réglages eva_ai sauvegardés, puis arrête SearXNG.
#
# À lancer par l'admin, SUR LE MAC, depuis ce dossier :
#     bash retour-arriere.sh /srv/sauvegarde-eva_ai/avant-searxng-AAAAMMJJ-HHMMSS
#     bash retour-arriere.sh --conteneur-seulement     (installation interrompue avant la modification d'eva)
#
# Ce script NE SUPPRIME RIEN : « docker compose -p searxng down » SANS -v retire seulement le conteneur
# (le réseau nextcloud_frontend est externe et n'est pas touché). /srv/searxng (config, secret, cache),
# l'image et la sauvegarde restent en place. Leur suppression est une décision séparée de l'admin.
set -euo pipefail

ARG=${1:?usage : bash retour-arriere.sh <dossier de sauvegarde> ou --conteneur-seulement}
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes ubuntu@192.168.1.99)
# RAPPEL : toute commande « exec -T » reçoit </dev/null.
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
OCC="$DC -u www-data app php occ"
DS='cd /srv/searxng && sudo docker compose -p searxng'

echo "0) vérifications préalables"
[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }

if [ "$ARG" != --conteneur-seulement ]; then
  B=$ARG
  case $B in /srv/sauvegarde-eva_ai/avant-searxng-*) ;; *) echo "ARRET : $B n'est pas un dossier de sauvegarde avant-searxng"; exit 1 ;; esac
  "${SSH[@]}" "sudo test -f $B/web_search_provider && sudo test -f $B/web_search_url" </dev/null \
    || { echo "ARRET : sauvegarde incomplète dans $B — rien n'a été modifié"; exit 1; }
  PROVIDER=$("${SSH[@]}" "sudo cat $B/web_search_provider" </dev/null)
  URL=$("${SSH[@]}" "sudo cat $B/web_search_url" </dev/null)
  # Clé absente avant l'installation : on remet une valeur vide, que eva traite comme « non réglé »
  # (provider inconnu → duckduckgo ; URL vide → SearXNG non configuré). Aucune clé n'est supprimée.
  [ "$PROVIDER" = __ABSENT__ ] && PROVIDER=''
  [ "$URL" = __ABSENT__ ] && URL=''
  echo "   actuel   : web_search_provider=$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_provider" </dev/null || echo '(absent)')," \
       "web_search_url=$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_url" </dev/null || echo '(absent)')"
  echo "   à remettre : web_search_provider=$PROVIDER, web_search_url=$URL"
fi
echo "   puis : cd /srv/searxng && sudo docker compose -p searxng down   (sans -v, rien n'est supprimé)"
printf 'Taper OUI pour appliquer : '
read -r REPONSE
[ "$REPONSE" = OUI ] || { echo "Abandon, rien n'a été fait."; exit 1; }

if [ "$ARG" != --conteneur-seulement ]; then
  echo "1) réglages eva (provider d'abord : eva cesse aussitôt d'appeler SearXNG)"
  "${SSH[@]}" "$OCC config:app:set eva_ai web_search_provider --value='$PROVIDER'" </dev/null
  "${SSH[@]}" "$OCC config:app:set eva_ai web_search_url --value='$URL'" </dev/null
  [ "$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_provider" </dev/null || true)" = "$PROVIDER" ] \
    || { echo "ALERTE : web_search_provider relu différent de « $PROVIDER »"; exit 1; }
  echo "   eva_ai : web_search_provider=$PROVIDER, web_search_url=$URL"
fi

echo "2) arrêt de SearXNG : docker compose -p searxng down (sans -v)"
"${SSH[@]}" "$DS down" </dev/null
[ -z "$("${SSH[@]}" "sudo docker ps -q --filter label=com.docker.compose.project=searxng" </dev/null)" ] \
  || { echo "ALERTE : un conteneur searxng tourne encore"; exit 1; }
echo "   conteneur arrêté et retiré ; /srv/searxng, l'image et les sauvegardes sont conservés"

echo
echo "RETOUR ARRIÈRE TERMINÉ."
echo "Suppression éventuelle de /srv/searxng (config + secret + cache) : à demander séparément, rien n'est fait ici."
