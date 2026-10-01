#!/usr/bin/env bash
# Déploie le PDF arabe par le navigateur (branche pdf-arabe-navigateur, empilée sur livraison-01-10)
# sur workspace4 — 2 bundles JS : js/eva_ai-main.js et js/eva_ai_standalone.js. Rien d'autre.
#
# Contexte : capture admin 01/10 18:07 — PDF d'une réponse arabe = bandeau rouge « exported as .docx
# instead of .pdf ». Cause : le PDF arabe côté serveur passe par Collabora (richdocuments), DÉSACTIVÉ
# sur workspace4 (OnlyOffice en service) → bascule DOCX systématique. Correctif : texte arabe → le
# navigateur imprime la réponse déjà rendue (iframe, RTL, tableaux) → « Enregistrer en PDF ». Le PDF
# serveur reste utilisé pour le texte latin.
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-pdf-arabe-navigateur.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-pdf-arabe-navigateur.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 2 fichiers sont commités, node --check passent, et contiennent bien ce
#     correctif (marqueur : "imprimerPdfNavigateur") ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT ce que lit ce script pour CHAQUE fichier (lu en direct,
#     pas figé à l'avance) ;
#   - sauvegarde des 2 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire,
#     vérifiés par empreinte, puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-pdf-arabe-navigateur.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

declare -A DISTANT=(
  [app/js/eva_ai-main.js]=$APP/js/eva_ai-main.js
  [app/js/eva_ai_standalone.js]=$APP/js/eva_ai_standalone.js
)
FICHIERS=(app/js/eva_ai-main.js app/js/eva_ai_standalone.js)

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "$f" || { echo "ARRET : $f modifié et non commité"; exit 1; }
  case "$f" in
    *.php) php -l "$f" >/dev/null || { echo "ARRET : php -l KO sur $f"; exit 1; } ;;
    *.js)  node --check "$f" || { echo "ARRET : node --check KO sur $f"; exit 1; } ;;
  esac
  grep -qF 'imprimerPdfNavigateur' "$f" || { echo "ARRET : correctif absent de $f"; exit 1; }
done
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
B=/srv/sauvegarde-eva_ai/avant-pdf-arabe-navigateur-$(date +%Y%m%d-%H%M%S)
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
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-pdf-arabe-navigateur.sh $B"' ERR

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
echo "⚠️  les 2 bundles JS sont servis au navigateur (pas du PHP OPcache) : un cache navigateur peut garder"
echo "   l'ancienne version un moment. DevTools → Network → Disable cache → reload (Cmd+Maj+R seul est insuffisant)."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-pdf-arabe-navigateur.sh $B"
