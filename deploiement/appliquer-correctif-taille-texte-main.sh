#!/usr/bin/env bash
# Déploie le correctif "taille de texte seule invisible" (branche correctif-taille-texte-main) sur
# workspace4 :
#   - 1 bundle JS : js/eva_ai-main.js
# Ne touche à aucun autre fichier.
#
# Contexte : trouvé par la revue adverse de PR #39 (construction soeur sur eva_ai_standalone.js),
# confirmé présent à l'identique dans eva_ai-main.js (pas une régression, défaut pré-existant
# depuis PR #34/#36). La règle .chatview-root.eva-perso .rt{...} gatait font-size derrière
# .eva-perso, qui ne s'active jamais si l'admin ne change QUE la taille de texte (fontSize n'est
# pas, à raison, dans la condition d'activation — ChatStore::sanitizeAppearance() renvoie toujours
# un fontSize réel, l'y inclure rendrait .eva-perso perpétuellement actif). Correctif : font-size
# sorti dans une règle .chatview-root .rt{...} séparée, inconditionnelle. Aucun changement JS :
# --eva-size est déjà toujours posée par appliquerApparence(). node --check propre.
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-correctif-taille-texte-main.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-correctif-taille-texte-main.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - le fichier est commité, node --check passe, et contient bien ce correctif (marqueur :
#     ".chatview-root .rt{font-size:var(--eva-size, inherit) !important}") ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT ce que lit ce script (lue en direct, pas figée à l'avance) ;
#   - sauvegarde vérifiée par empreinte ; nouveau fichier déposé en temporaire, vérifié par
#     empreinte, puis mis en place.
# Retour arrière : bash deploiement/retour-arriere-correctif-taille-texte-main.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

F=app/js/eva_ai-main.js
D=$APP/js/eva_ai-main.js

echo "0) vérifications préalables"
git diff --quiet HEAD -- "$F" || { echo "ARRET : $F modifié et non commité"; exit 1; }
node --check "$F" || { echo "ARRET : node --check KO sur $F"; exit 1; }
grep -qF '.chatview-root .rt{font-size:var(--eva-size, inherit) !important}' "$F" || { echo "ARRET : correctif absent de $F"; exit 1; }
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
B=/srv/sauvegarde-eva_ai/avant-correctif-taille-texte-main-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
r "sudo docker exec nextcloud-app-1 cat $D | sudo tee $B/eva_ai-main.js >/dev/null"
[ "$(r "sudo sha256sum $B/eva_ai-main.js" | cut -c1-64)" = "$PROD" ] || { echo "ARRET : sauvegarde invalide dans $B — rien n'a été modifié"; exit 1; }
r "sudo sh -c 'cd $B && sha256sum * > EMPREINTES'"
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-correctif-taille-texte-main.sh $B"' ERR

echo "3) dépôt en temporaire + contrôle, puis mise en place"
TMP=$D.nouveau
"${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "$F"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ARRET : empreinte du fichier déposé incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $D"
[ "$(r "sudo docker exec nextcloud-app-1 sha256sum $D" | cut -c1-64)" = "$NOUVEAU" ] || { echo "ÉCHEC : empreinte finale incorrecte"; false; }
echo "   $F déployé (${NOUVEAU:0:12}…)"

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "⚠️  eva_ai-main.js est servi au navigateur (pas du PHP OPcache) : un cache navigateur peut garder"
echo "   l'ancienne version un moment. DevTools → Network → Disable cache → reload (Cmd+Maj+R seul est insuffisant)."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-correctif-taille-texte-main.sh $B"
