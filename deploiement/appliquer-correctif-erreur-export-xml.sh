#!/usr/bin/env bash
# Déploie le correctif "erreur HTTP 422 brute à l'export PDF arabe" (branche
# correctif-erreur-export-xml) sur workspace4 :
#   - 2 bundles JS : js/eva_ai_standalone.js, js/eva_ai-main.js
# Ne touche à aucun autre fichier.
#
# Contexte : signalé par l'admin (capture d'écran, bandeau "EvaAi error: HTTP 422"). Bug
# préexistant (PR #33/#35), pas introduit par les correctifs d'aujourd'hui — jamais remonté avant
# car il ne se déclenche que sans Collabora + texte arabe en PDF. Cause : la réponse d'erreur OCS
# de POST /chats/{id}/export revient en XML sur cette route (confirmé en direct, ni Accept:
# application/json ni ?format=json ne changent ce comportement), mais le JS appelait resp.json()
# dessus — échec silencieux, perte de error ET canRetryAs, bascule .docx automatique jamais
# déclenchée. Correctif : resp.text() + JSON.parse, repli sur parsing XML (DOMParser) si échec.
# Vérifié en direct avec le vrai corps XML de la prod (error + canRetryAs=docx bien extraits) et
# avec un vrai export .docx réussi (200, fichier valide) — la bascule aboutira à un téléchargement
# réel.
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-correctif-erreur-export-xml.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-correctif-erreur-export-xml.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 2 fichiers sont commités, node --check passe, et contiennent bien ce correctif
#     (marqueur : txtExport) ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT ce que lit ce script pour CHAQUE fichier (lu en direct,
#     pas figé à l'avance) ;
#   - sauvegarde des 2 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire,
#     vérifiés par empreinte, puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-correctif-erreur-export-xml.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

declare -A DISTANT=(
  [app/js/eva_ai_standalone.js]=$APP/js/eva_ai_standalone.js
  [app/js/eva_ai-main.js]=$APP/js/eva_ai-main.js
)
FICHIERS=(app/js/eva_ai_standalone.js app/js/eva_ai-main.js)

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "$f" || { echo "ARRET : $f modifié et non commité"; exit 1; }
  node --check "$f" || { echo "ARRET : node --check KO sur $f"; exit 1; }
  grep -q 'txtExport' "$f" || { echo "ARRET : correctif absent de $f"; exit 1; }
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
B=/srv/sauvegarde-eva_ai/avant-correctif-erreur-export-xml-$(date +%Y%m%d-%H%M%S)
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
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-correctif-erreur-export-xml.sh $B"' ERR

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
echo "⚠️  servis au navigateur (pas du PHP OPcache) : un cache navigateur peut garder l'ancienne"
echo "   version un moment. DevTools → Network → Disable cache → reload (Cmd+Maj+R seul est insuffisant)."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-correctif-erreur-export-xml.sh $B"
