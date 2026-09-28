#!/usr/bin/env bash
# Retour arrière d'un déploiement eva_ai : restaure les fichiers de la sauvegarde faite par appliquer-eva.sh.
# Chaque sauvegarde porte ses empreintes (fichier EMPREINTES) : le retour arrière vérifie la sauvegarde contre
# ELLE-MÊME, pas contre une version codée en dur (défaut de retour-arriere-pdf.sh, figé sur f571cf4).
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/retour-arriere-eva.sh /srv/sauvegarde-eva_ai/avant-eva-AAAAMMJJ-HHMMSS
# Anciennes sauvegardes (avant-pdf-*, sans EMPREINTES) : EMPREINTES_DEPUIS=<sha de la version sauvegardée> bash …
set -euo pipefail
cd "$(dirname "$0")/.."
B="${1:?dossier de sauvegarde manquant, affiché par appliquer-eva.sh à l’étape 2}"   # apostrophe typographique (bash 3.2)
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15 ubuntu@192.168.1.99)
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
APP=/var/www/html/custom_apps/eva_ai/lib/Service
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
if [ -n "${EMPREINTES_DEPUIS:-}" ]; then
  LISTE=$(r "sudo ls $B" | grep '\.php$' || true)
  EMP=$(for f in $LISTE; do echo "$(git show "$EMPREINTES_DEPUIS:src/$f" | h)  $f"; done)
else
  EMP=$(r "sudo cat $B/EMPREINTES") || { echo "ARRET : $B/EMPREINTES absent (ancienne sauvegarde : relancer avec EMPREINTES_DEPUIS=<sha>)"; exit 1; }
fi
[ -n "$EMP" ] || { echo "ARRET : aucun fichier à restaurer dans $B"; exit 1; }
echo "1) vérification de la sauvegarde contre ses empreintes"
while read -r somme f; do
  [ "$(r "sudo sha256sum $B/$f" | cut -c1-64)" = "$somme" ] || { echo "ARRET : $B/$f absent ou modifié — rien restauré"; exit 1; }
  echo "   $f OK"
done <<< "$EMP"
echo "2) restauration"
while read -r somme f; do
  # « cd » d'abord : dans « sudo cat … | cd … && docker … », le tube alimenterait cd, pas docker.
  r "cd /home/ubuntu/docker && sudo cat $B/$f | sudo docker compose --env-file .env exec -T -u www-data app sh -c 'cat > $APP/$f'"
  [ "$(r "$DC app sha256sum $APP/$f" | cut -c1-64)" = "$somme" ] || { echo "ALERTE : $f restauré ne correspond pas à la sauvegarde"; exit 1; }
  r "$DC app php -l $APP/$f"
done <<< "$EMP"
echo "Restauré depuis $B$(r "sudo cat $B/REFERENCE 2>/dev/null" | sed 's/^/ (état /;s/$/)/')."
