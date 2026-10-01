#!/usr/bin/env bash
# Retour arrière du correctif rendu arabe/RTL : remet eva_ai_standalone.js et eva_ai-main.js depuis la sauvegarde.
#     bash deploiement/retour-arriere-rtl-arabe.sh /srv/sauvegarde-eva_ai/avant-rtl-arabe-<date>
set -euo pipefail
B=${1:?usage : $0 <dossier de sauvegarde>}
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai/js
FICHIERS=(eva_ai_standalone.js eva_ai-main.js)
r() { "${SSH[@]}" "$@" </dev/null; }

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum -c EMPREINTES'" || { echo "ARRET : sauvegarde altérée"; exit 1; }

for f in "${FICHIERS[@]}"; do
  if ! r "sudo test -f $B/$f"; then echo "   $f : pas dans cette sauvegarde (était déjà déployé au moment de l'appliquer), ignoré"; continue; fi
  ATTENDU=$(r "sudo sha256sum $B/$f" | cut -c1-64)
  r "sudo cat $B/$f" | "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $APP/$f.retour && mv $APP/$f.retour $APP/$f'"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "$ATTENDU" ] && echo "   $f restauré (${ATTENDU:0:12}…)"
done
echo "Pense à vider le cache navigateur / recharger sans cache pour voir le retour arrière."
