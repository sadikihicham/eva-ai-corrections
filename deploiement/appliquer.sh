#!/usr/bin/env bash
# Applique les 2 correctifs sur workspace4 (192.168.1.99), APRÈS la sauvegarde déjà faite le 28/09
# à 00:29 (/srv/sauvegarde-eva_ai/avant-correction-20260928-002905/, confirmée par diff, zéro dérive).
# À lancer par l'admin, depuis eva-corrections/deploiement/ :
#   bash appliquer.sh
# Retour arrière : bash retour-arriere.sh
set -euo pipefail
cd /home/ubuntu/docker
A="sudo docker compose --env-file .env exec -T"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "1) OpenAICompatible.php — remplacement complet (fichier corrigé, vérifié php -l en amont)"
$A -u www-data app sh -c "cat > /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php" < "$DIR/OpenAICompatible.corrige.php"
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/OpenAICompatible.php

echo "2) ActionExecutor.php — remplacement d'une ligne (espace de noms), vérifié unique avant/après"
AVANT='OCP\\AppFramework\\Services\\IAppDataFactory'
APRES='OCP\\Files\\AppData\\IAppDataFactory'
N=$($A app grep -c "$AVANT" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php)
[ "$N" = "1" ] || { echo "ARRET : $N occurrence(s) au lieu de 1 — rien changé"; exit 1; }
$A -u www-data app sed -i "s|$AVANT|$APRES|" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php
$A app php -l /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php
N2=$($A app grep -c "$APRES" /var/www/html/custom_apps/eva_ai/lib/Service/ActionExecutor.php)
echo "occurrences du bon espace de noms après correction : $N2 (attendu ≥ 3 : la ligne corrigée + les 2 déjà correctes ailleurs)"

echo "3) intégrité de l'app (avertissement attendu, sans gravité — fichiers modifiés hors App Store)"
$A -u www-data app php occ integrity:check-app eva_ai || true

echo "4) opcache — vérifier si un redémarrage est nécessaire pour que PHP relise les fichiers"
$A app php -r 'echo "validate_timestamps=" . ini_get("opcache.validate_timestamps") . "\n";'
echo "Si validate_timestamps=1 (par défaut) : rien à faire, PHP relit les fichiers automatiquement."
echo "Si 0 : lancer 'docker compose restart app' (coupe brièvement les 185 utilisateurs) — DEMANDER confirmation avant."
