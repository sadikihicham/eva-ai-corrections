#!/usr/bin/env bash
# Déploie la personnalisation visuelle par conversation sur eva_ai_standalone.js (branche
# personnalisation-standalone) sur workspace4 :
#   - 1 template PHP (nouveau bouton Customize + CSS) : templates/standalone.php
#   - 1 bundle JS : js/eva_ai_standalone.js
# Ne touche à aucun autre fichier. Aucun changement backend : ChatStore::sanitizeAppearance() et
# ApiController::chatMeta() (déployés par PR #34/#36) sont déjà génériques — n'importe quel appelant
# de POST /chats/{id}/meta avec {appearance:{...}} en profite, main.js et standalone.js compris.
#
# Contexte : décision admin — étendre à eva_ai_standalone.js (pas de modale Customize existante
# là-bas, contrairement à eva_ai-main.js : construite à neuf ici, décision prise après la vérif
# visuelle du 01/10 qui a aussi trouvé et corrigé le bug "couleurs forcées" (PR #38) sur main.js.
# Cette construction neuve inclut le correctif dès le départ (3 drapeaux "touché" par couleur,
# bgColor/textColor/bubbleColor envoyés seulement si réellement modifiés) — jamais reproduit le bug.
# Scope volontairement réduit à la personnalisation visuelle (police/taille/3 couleurs) : pas de
# persona/instructions ici, ce volet n'existe que dans eva_ai-main.js (fonctionnalité distincte,
# pas demandée pour standalone.js).
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-personnalisation-standalone.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-personnalisation-standalone.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 2 fichiers sont commités, php -l / node --check passent, et contiennent bien ce correctif
#     (marqueur : appliquerApparence) ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT ce que lit ce script pour CHAQUE fichier (lu en direct,
#     pas figé à l'avance) ;
#   - sauvegarde des 2 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire,
#     vérifiés par empreinte, puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-personnalisation-standalone.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

declare -A DISTANT=(
  [app/templates/standalone.php]=$APP/templates/standalone.php
  [app/js/eva_ai_standalone.js]=$APP/js/eva_ai_standalone.js
)
FICHIERS=(app/templates/standalone.php app/js/eva_ai_standalone.js)

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "$f" || { echo "ARRET : $f modifié et non commité"; exit 1; }
  case "$f" in
    *.php) php -l "$f" >/dev/null || { echo "ARRET : php -l KO sur $f"; exit 1; } ;;
    *.js)  node --check "$f" || { echo "ARRET : node --check KO sur $f"; exit 1; } ;;
  esac
done
grep -q "id=\"customize\"" app/templates/standalone.php || { echo "ARRET : bouton Customize absent de standalone.php"; exit 1; }
grep -q 'appliquerApparence' app/js/eva_ai_standalone.js || { echo "ARRET : correctif absent de eva_ai_standalone.js"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

echo "1) contrôle de dérive (chaque fichier, empreintes ORIGINE lues en direct sur la prod)"
DEJA=0
declare -A NOUVEAU ORIGINE
for f in "${FICHIERS[@]}"; do
  NOUVEAU[$f]=$(h < "$f")
  D="${DISTANT[$f]}"
  PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $D 2>/dev/null" | cut -c1-64)
  if [ "$PROD" = "${NOUVEAU[$f]}" ]; then echo "   $f : déjà déployé"; DEJA=$((DEJA + 1)); continue; fi
  ORIGINE[$f]="$PROD"
  echo "   $f : production actuelle ${PROD:0:12}… -> nouveau ${NOUVEAU[$f]:0:12}…"
done
[ "$DEJA" -eq "${#FICHIERS[@]}" ] && { echo "   les ${#FICHIERS[@]} fichiers sont déjà déployés, rien à faire"; exit 0; }
echo "   ⚠️  Relis les empreintes ORIGINE ci-dessus avant de continuer."
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-personnalisation-standalone-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
for f in "${FICHIERS[@]}"; do
  [ -n "${ORIGINE[$f]:-}" ] || continue
  D="${DISTANT[$f]}"
  NOM=$(basename "$f")
  r "sudo docker exec nextcloud-app-1 cat $D | sudo tee $B/$NOM >/dev/null"
  [ "$(r "sudo sha256sum $B/$NOM" | cut -c1-64)" = "${ORIGINE[$f]}" ] || { echo "ARRET : sauvegarde de $f invalide dans $B — rien n'a été modifié"; exit 1; }
done
r "sudo sh -c 'cd $B && sha256sum * > EMPREINTES'"
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-personnalisation-standalone.sh $B"' ERR

echo "3) dépôt en temporaire + contrôles, puis mise en place (un fichier à la fois)"
for f in "${FICHIERS[@]}"; do
  [ -n "${ORIGINE[$f]:-}" ] || { echo "   $f : déjà déployé, ignoré"; continue; }
  D="${DISTANT[$f]}"
  TMP=$D.nouveau
  "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "$f"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ARRET : empreinte du fichier déposé ($f) incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
  r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $D"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ÉCHEC : empreinte finale incorrecte sur $f"; false; }
  echo "   $f déployé (${NOUVEAU[$f]:0:12}…)"
done

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "⚠️  eva_ai_standalone.js est servi au navigateur (pas du PHP OPcache) : un cache navigateur peut garder"
echo "   l'ancienne version un moment. DevTools → Network → Disable cache → reload (Cmd+Maj+R seul est insuffisant)."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-personnalisation-standalone.sh $B"
