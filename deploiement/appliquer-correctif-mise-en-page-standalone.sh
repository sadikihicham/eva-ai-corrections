#!/usr/bin/env bash
# Déploie le correctif "formulaire de saisie hors écran" (branche
# correctif-mise-en-page-standalone) sur workspace4 :
#   - 1 template PHP : templates/standalone.php
# Ne touche à aucun autre fichier.
#
# Contexte : trouvé en testant en prod (01/10), bug préexistant indépendant de la
# personnalisation. #content (réutilisé par le CSS cœur Nextcloud comme conteneur applicatif
# standard, height:var(--body-height);position:fixed) se calculait plus petit que le viewport
# réel sur cette page précise (pas de nav Nextcloud standard pour établir --body-height
# correctement) — #msgs (flex:1 imbriqué) prenait alors sa taille naturelle au lieu de se
# contraindre, poussant #form hors écran, silencieusement (aucune erreur JS). Correctif : arrête
# de dépendre du calcul Nextcloud (#content en flux normal, !important nécessaire), #msgs borné
# en max-height avec défilement interne, body défilable en secours. Vérifié en direct (chat vide
# ET chat à 24 messages) avant d'écrire le correctif.
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-correctif-mise-en-page-standalone.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-correctif-mise-en-page-standalone.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - le fichier est commité, php -l passe, et contient bien ce correctif (marqueur :
#     "position: static !important") ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT ce que lit ce script (lue en direct, pas figée à l'avance) ;
#   - sauvegarde vérifiée par empreinte ; nouveau fichier déposé en temporaire, vérifié par
#     empreinte, puis mis en place.
# Retour arrière : bash deploiement/retour-arriere-correctif-mise-en-page-standalone.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

F=app/templates/standalone.php
D=$APP/templates/standalone.php

echo "0) vérifications préalables"
git diff --quiet HEAD -- "$F" || { echo "ARRET : $F modifié et non commité"; exit 1; }
php -l "$F" >/dev/null || { echo "ARRET : php -l KO sur $F"; exit 1; }
grep -qF 'position: static !important' "$F" || { echo "ARRET : correctif absent de $F"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

echo "1) contrôle de dérive (empreinte ORIGINE lue en direct sur la prod)"
NOUVEAU=$(h < "$F")
PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $D 2>/dev/null" | cut -c1-64)
if [ "$PROD" = "$NOUVEAU" ]; then echo "   déjà déployé, rien à faire"; exit 0; fi
echo "   $F : production actuelle ${PROD:0:12}… -> nouveau ${NOUVEAU:0:12}…"
echo "   ⚠️  Relis les empreintes ci-dessus avant de continuer."
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-correctif-mise-en-page-standalone-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
r "sudo docker exec nextcloud-app-1 cat $D | sudo tee $B/standalone.php >/dev/null"
[ "$(r "sudo sha256sum $B/standalone.php" | cut -c1-64)" = "$PROD" ] || { echo "ARRET : sauvegarde invalide dans $B — rien n'a été modifié"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum * > EMPREINTES'"
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-correctif-mise-en-page-standalone.sh $B"' ERR

echo "3) dépôt en temporaire + contrôle, puis mise en place"
TMP=$D.nouveau
"${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "$F"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ARRET : empreinte du fichier déposé incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $D"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ÉCHEC : empreinte finale incorrecte"; false; }
echo "   $F déployé (${NOUVEAU:0:12}…)"

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-correctif-mise-en-page-standalone.sh $B"
