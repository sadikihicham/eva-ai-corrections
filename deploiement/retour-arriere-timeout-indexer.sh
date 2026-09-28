#!/usr/bin/env bash
# Retour arrière du correctif timeout : remet lib/Service/Indexer.php depuis la sauvegarde.
#     bash deploiement/retour-arriere-timeout-indexer.sh /srv/sauvegarde-eva_ai/avant-timeout-<date>
set -euo pipefail
B=${1:?usage : $0 <dossier de sauvegarde>}
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
CIBLE=/var/www/html/custom_apps/eva_ai/lib/Service/Indexer.php
r() { "${SSH[@]}" "$@" </dev/null; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum -c EMPREINTES'" || { echo "ARRET : sauvegarde altérée"; exit 1; }
ATTENDU=$(r "sudo sha256sum $B/Indexer.php" | cut -c1-64)
r "sudo cat $B/Indexer.php" | "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $CIBLE.retour && mv $CIBLE.retour $CIBLE'"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $CIBLE" | cut -c1-64)" = "$ATTENDU" ] && echo "Indexer.php restauré (${ATTENDU:0:12}…)"
