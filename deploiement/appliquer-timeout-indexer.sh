#!/usr/bin/env bash
# Déploie UNIQUEMENT lib/Service/Indexer.php (correctif timeout soffice, branche timeout-soffice) sur workspace4.
# Ne touche à aucun autre fichier d'eva_ai (≠ appliquer-eva.sh qui déploie tout l'état de la branche).
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-timeout-indexer.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-timeout-indexer.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - fichier commité, hôte = workspace4 ;
#   - la production doit être EXACTEMENT la version d'origine (sha256 ORIGINE) — sinon on n'écrase rien ;
#   - sauvegarde vérifiée par empreinte ; nouveau fichier déposé en temporaire, vérifié (empreinte + php -l), puis mis en place.
# Retour arrière : bash deploiement/retour-arriere-timeout-indexer.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
CIBLE=/var/www/html/custom_apps/eva_ai/lib/Service/Indexer.php
LOCAL=app/lib/Service/Indexer.php
ORIGINE=bbf29f4df7dc6da9dd320abc217526a2737e1888c035ed32ee15ef33a4b8c150   # production du 29/09 avant correctif
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

echo "0) vérifications préalables"
git diff --quiet HEAD -- "$LOCAL" || { echo "ARRET : $LOCAL modifié et non commité"; exit 1; }
grep -q "timeout -k 10 120" "$LOCAL" || { echo "ARRET : le correctif n'est pas dans $LOCAL"; exit 1; }
NOUVEAU=$(h < "$LOCAL")
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD)), nouveau sha256 ${NOUVEAU:0:12}…"

echo "1) contrôle de dérive"
PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $CIBLE" | cut -c1-64)
if [ "$PROD" = "$NOUVEAU" ]; then echo "   déjà déployé, rien à faire"; exit 0; fi
[ "$PROD" = "$ORIGINE" ] || { echo "ARRET : la production (${PROD:0:12}…) n'est pas la version d'origine — rien n'a été modifié"; exit 1; }
echo "   production = version d'origine"
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-timeout-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B && sudo docker exec nextcloud-app-1 cat $CIBLE | sudo tee $B/Indexer.php >/dev/null && sudo sh -c 'cd $B && sha256sum Indexer.php > EMPREINTES'"
[ "$(r "sudo sha256sum $B/Indexer.php" | cut -c1-64)" = "$ORIGINE" ] || { echo "ARRET : sauvegarde invalide dans $B — rien n'a été modifié"; exit 1; }
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-timeout-indexer.sh $B"' ERR

echo "3) dépôt en temporaire + contrôles"
TMP=$CIBLE.nouveau
"${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "$LOCAL"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ARRET : empreinte du fichier déposé incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
r "sudo docker exec -u www-data nextcloud-app-1 php -l $TMP" | grep -q "No syntax errors" || { echo "ARRET : php -l KO"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
echo "   empreinte + php -l OK"

echo "4) mise en place"
r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $CIBLE"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $CIBLE" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ÉCHEC : empreinte finale incorrecte"; false; }
r "sudo docker exec nextcloud-cron-1 sha256sum $CIBLE" | cut -c1-64 | grep -qx "$NOUVEAU" && echo "   vu identique par le conteneur cron"
echo "   Indexer.php déployé (${NOUVEAU:0:12}…). Sauvegarde : $B"
