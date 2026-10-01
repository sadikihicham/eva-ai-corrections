#!/usr/bin/env bash
# Retour arrière du correctif "formulaire de saisie hors écran" : remet standalone.php depuis la sauvegarde.
#     bash deploiement/retour-arriere-correctif-mise-en-page-standalone.sh /srv/sauvegarde-eva_ai/avant-correctif-mise-en-page-standalone-<date>
set -euo pipefail
B=${1:?usage : $0 <dossier de sauvegarde>}
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
D=$APP/templates/standalone.php
r() { "${SSH[@]}" "$@" </dev/null; }

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum -c EMPREINTES'" || { echo "ARRET : sauvegarde altérée"; exit 1; }

ATTENDU=$(r "sudo sha256sum $B/standalone.php" | cut -c1-64)
r "sudo cat $B/standalone.php" | "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $D.retour && mv $D.retour $D'"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "$ATTENDU" ] && echo "   standalone.php restauré (${ATTENDU:0:12}…)"
