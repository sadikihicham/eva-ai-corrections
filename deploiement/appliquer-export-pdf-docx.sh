#!/usr/bin/env bash
# Déploie le correctif export PDF/DOCX (branche export-pdf-docx) sur workspace4 :
#   - 2 fichiers PHP : lib/Service/ActionExecutor.php, lib/Controller/ApiController.php
#   - 1 fichier de config : appinfo/routes.php
#   - 1 template : templates/standalone.php
#   - 2 bundles JS : js/eva_ai_standalone.js, js/eva_ai-main.js
# Ne touche à aucun autre fichier.
#
# Contexte : nouvelle fonctionnalité — téléchargement direct d'une conversation en PDF ou DOCX,
# réutilise buildPdf()/buildDocx()/pdfViaOffice() (déjà utilisées par createFile()) via une
# nouvelle méthode publique ActionExecutor::exportConversationFile(), exposée par
# ApiController::chatExport() (POST /api/chats/{id}/export). Revue adverse (security-auditor)
# passée avant commit : limite de taille sur le contenu reçu (DoS), avertissement de caractères
# non imprimables restauré (parité avec createFile()), docxPlain cohérent, validation stricte de
# l'id de chat, erreurs/repli PDF→DOCX désormais visibles côté JS (bannière A()/Bi() existante,
# plus un en-tête X-Eva-Export-Warning pour les caractères remplacés silencieusement sinon).
#
# ⚠️ DÉPENDANCE D'ORDRE : ORIGINE ci-dessous = l'état actuellement en prod (correctif RTL texte,
# PR #31). Si PR #32 (puces de liste RTL) est déployée AVANT ce script pour les 2 bundles JS, ce
# script s'arrêtera proprement à l'étape 1 (dérive détectée) — c'est le comportement normal et
# sûr, pas un bug : relancer après avoir mis à jour ORIGINE pour les 2 .js (empreintes de #32)
# une fois ce cas réel, ou déployer ce correctif-ci en premier.
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-export-pdf-docx.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-export-pdf-docx.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 6 fichiers sont commités, php -l / node --check passent, et contiennent bien ce
#     correctif (marqueur : exportConversationFile / chatExport / export-pdf) ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT la version ORIGINE pour CHAQUE fichier ;
#   - sauvegarde des 6 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire,
#     vérifiés par empreinte, puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-export-pdf-docx.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

# chemin local -> (chemin distant, empreinte ORIGINE)
declare -A DISTANT=(
  [src/ActionExecutor.php]=$APP/lib/Service/ActionExecutor.php
  [app/lib/Controller/ApiController.php]=$APP/lib/Controller/ApiController.php
  [app/appinfo/routes.php]=$APP/appinfo/routes.php
  [app/templates/standalone.php]=$APP/templates/standalone.php
  [app/js/eva_ai_standalone.js]=$APP/js/eva_ai_standalone.js
  [app/js/eva_ai-main.js]=$APP/js/eva_ai-main.js
)
declare -A ORIGINE
FICHIERS=(src/ActionExecutor.php "app/lib/Controller/ApiController.php" app/appinfo/routes.php app/templates/standalone.php app/js/eva_ai_standalone.js app/js/eva_ai-main.js)

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "$f" || { echo "ARRET : $f modifié et non commité"; exit 1; }
  case "$f" in
    *.php) php -l "$f" >/dev/null || { echo "ARRET : php -l KO sur $f"; exit 1; } ;;
    *.js)  node --check "$f" || { echo "ARRET : node --check KO sur $f"; exit 1; } ;;
  esac
done
grep -q 'exportConversationFile' src/ActionExecutor.php || { echo "ARRET : correctif absent de ActionExecutor.php"; exit 1; }
grep -q 'chatExport' "app/lib/Controller/ApiController.php" || { echo "ARRET : correctif absent de ApiController.php"; exit 1; }
grep -q 'chatExport' app/appinfo/routes.php || { echo "ARRET : route absente de routes.php"; exit 1; }
grep -q 'export-pdf' app/templates/standalone.php || { echo "ARRET : boutons absents de standalone.php"; exit 1; }
grep -q 'exporterFichier' app/js/eva_ai_standalone.js || { echo "ARRET : correctif absent de eva_ai_standalone.js"; exit 1; }
grep -q 'exporterFichierPrincipal' app/js/eva_ai-main.js || { echo "ARRET : correctif absent de eva_ai-main.js"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

# Empreintes ORIGINE = état actuel réel en prod pour chaque fichier (capturées ici, pas en dur,
# car 3 de ces fichiers — PHP/routes/template — n'ont jamais été modifiés avant ce correctif : on
# lit la prod elle-même comme référence plutôt que de deviner une empreinte jamais vérifiée).
echo "1) contrôle de dérive (chaque fichier)"
DEJA=0
declare -A NOUVEAU
for f in "${FICHIERS[@]}"; do
  NOUVEAU[$f]=$(h < "$f")
  D="${DISTANT[$f]}"
  PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $D 2>/dev/null" | cut -c1-64)
  if [ "$PROD" = "${NOUVEAU[$f]}" ]; then echo "   $f : déjà déployé"; DEJA=$((DEJA + 1)); continue; fi
  ORIGINE[$f]="$PROD"
  echo "   $f : production actuelle ${PROD:0:12}… -> nouveau ${NOUVEAU[$f]:0:12}…"
done
[ "$DEJA" -eq "${#FICHIERS[@]}" ] && { echo "   les ${#FICHIERS[@]} fichiers sont déjà déployés, rien à faire"; exit 0; }
echo "   ⚠️  Empreintes ORIGINE lues en direct sur la prod (pas figées à l'avance) : relis ce qui précède avant de continuer."
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-export-pdf-docx-$(date +%Y%m%d-%H%M%S)
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
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-export-pdf-docx.sh $B"' ERR

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
echo "⚠️  Les 2 fichiers JS sont servis au navigateur (pas du PHP OPcache) : un cache navigateur peut garder"
echo "   l'ancienne version un moment. DevTools → Network → Disable cache → reload (Cmd+Maj+R seul est insuffisant)."
echo "⚠️  routes.php : nouvelle route ajoutée, aucun occ requis (le routeur Nextcloud la lit à chaque requête)."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-export-pdf-docx.sh $B"
