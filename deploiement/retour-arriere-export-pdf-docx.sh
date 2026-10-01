#!/usr/bin/env bash
# Retour arrière du correctif export PDF/DOCX : remet les 6 fichiers depuis la sauvegarde.
#     bash deploiement/retour-arriere-export-pdf-docx.sh /srv/sauvegarde-eva_ai/avant-export-pdf-docx-<date>
set -euo pipefail
B=${1:?usage : $0 <dossier de sauvegarde>}
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
r() { "${SSH[@]}" "$@" </dev/null; }

declare -A DISTANT=(
  [ActionExecutor.php]=$APP/lib/Service/ActionExecutor.php
  [ApiController.php]=$APP/lib/Controller/ApiController.php
  [routes.php]=$APP/appinfo/routes.php
  [standalone.php]=$APP/templates/standalone.php
  [eva_ai_standalone.js]=$APP/js/eva_ai_standalone.js
  [eva_ai-main.js]=$APP/js/eva_ai-main.js
)

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum -c EMPREINTES'" || { echo "ARRET : sauvegarde altérée"; exit 1; }

for NOM in "${!DISTANT[@]}"; do
  D="${DISTANT[$NOM]}"
  if ! r "sudo test -f $B/$NOM"; then echo "   $NOM : pas dans cette sauvegarde (était déjà déployé au moment de l'appliquer), ignoré"; continue; fi
  ATTENDU=$(r "sudo sha256sum $B/$NOM" | cut -c1-64)
  r "sudo cat $B/$NOM" | "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $D.retour && mv $D.retour $D'"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "$ATTENDU" ] && echo "   $NOM restauré (${ATTENDU:0:12}…)"
done
echo "Pense à vider le cache navigateur / recharger sans cache pour voir le retour arrière des fichiers JS."
