#!/usr/bin/env bash
# Retour arrière de la fonctionnalité « liens Ouvrir / Télécharger » : restaure ActionExecutor.php et RagService.php
# depuis la sauvegarde faite par appliquer-liens.sh (état de référence 5331dec).
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/retour-arriere-liens.sh /srv/sauvegarde-eva_ai/avant-liens-AAAAMMJJ-HHMMSS
set -euo pipefail
cd "$(dirname "$0")/.."
B="${1:?dossier de sauvegarde manquant, affiché par appliquer-liens.sh à l’étape 2}"   # apostrophe typographique : une ' droite dans ${…:?} casse bash 3.2
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes ubuntu@192.168.1.99)
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
APP=/var/www/html/custom_apps/eva_ai/lib/Service
h() { shasum -a 256 | cut -c1-64; }

[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
for f in ActionExecutor.php RagService.php; do
  [ "$("${SSH[@]}" "sudo sha256sum $B/$f" </dev/null | cut -c1-64)" = "$(git show "5331dec:src/$f" | h)" ] \
    || { echo "ARRET : $B/$f absent ou inattendu — rien restauré"; exit 1; }
done
for f in ActionExecutor.php RagService.php; do
  # « cd » d'abord : dans « sudo cat … | cd … && docker … », le tube alimenterait cd, pas docker.
  "${SSH[@]}" "cd /home/ubuntu/docker && sudo cat $B/$f | sudo docker compose --env-file .env exec -T -u www-data app sh -c 'cat > $APP/$f'" </dev/null
  [ "$("${SSH[@]}" "$DC app sha256sum $APP/$f" </dev/null | cut -c1-64)" = "$(git show "5331dec:src/$f" | h)" ] \
    || { echo "ALERTE : $f restauré ne correspond pas à la sauvegarde"; exit 1; }
  "${SSH[@]}" "$DC app php -l $APP/$f" </dev/null
done
echo "Restauré depuis $B (état 5331dec)."
