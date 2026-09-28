#!/usr/bin/env bash
# Retour arrière des 2 correctifs — restaure les fichiers d'origine sauvegardés le 28/09/2026 à 00:29.
# À lancer par l'admin :  bash retour-arriere.sh
set -euo pipefail
cd /home/ubuntu/docker
A="sudo docker compose --env-file .env exec -T"
B=/srv/sauvegarde-eva_ai/avant-correction-20260928-002905
sudo test -f "$B/OpenAICompatible.php" || { echo "ARRET : sauvegarde introuvable ($B)"; exit 1; }
sudo test -f "$B/ActionExecutor.php" || { echo "ARRET : sauvegarde introuvable ($B)"; exit 1; }
sudo cat "$B/OpenAICompatible.php" | $A -u www-data app sh -c "cat > /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php"
sudo cat "$B/ActionExecutor.php" | $A -u www-data app sh -c "cat > /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php"
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php
echo "Restauré depuis $B."
