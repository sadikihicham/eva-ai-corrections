#!/usr/bin/env bash
# Retour arrière du correctif "couleurs imposées par erreur" : remet eva_ai-main.js depuis la sauvegarde.
#     bash deploiement/retour-arriere-correctif-couleurs-forcees.sh /srv/sauvegarde-eva_ai/avant-correctif-couleurs-forcees-<date>
set -euo pipefail
B=${1:?usage : $0 <dossier de sauvegarde>}
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
D=$APP/js/eva_ai-main.js
r() { "${SSH[@]}" "$@" </dev/null; }

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum -c EMPREINTES'" || { echo "ARRET : sauvegarde altérée"; exit 1; }

ATTENDU=$(r "sudo sha256sum $B/eva_ai-main.js" | cut -c1-64)
r "sudo cat $B/eva_ai-main.js" | "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $D.retour && mv $D.retour $D'"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "$ATTENDU" ] && echo "   eva_ai-main.js restauré (${ATTENDU:0:12}…)"
echo "Pense à vider le cache navigateur / recharger sans cache pour voir le retour arrière."
