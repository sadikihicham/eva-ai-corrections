#!/usr/bin/env bash
# Déploie UNIQUEMENT js/eva_ai_standalone.js et js/eva_ai-main.js (correctif scintillement de l'affichage
# pendant le streaming, branche curl-multi-streaming-reel) sur workspace4. Ne touche à aucun autre fichier.
#
# Contexte : une fois le streaming réellement corrigé côté backend (PR #26-#28), l'admin a signalé que le
# texte affiché "clignote" pendant qu'il arrive token par token.
# 1er correctif (PR #29, déployé) : la fonction de rendu alternait un rendu Markdown complet (innerHTML)
# toutes les ~200ms avec un `textContent` brut entre-temps, qui écrasait le HTML formaté par du Markdown NON
# interprété. Corrigé, mais le clignotement a persisté à l'usage (confirmé : le fichier déployé contenait
# bien ce 1er correctif, relu directement sur le serveur).
# 2e correctif : le re-rendu Markdown complet toutes les 200ms lui-même (reconstruction intégrale du DOM
# du message) reste une source de scintillement, avec ou sans l'alternance du 1er correctif. Supprime ce
# re-rendu intermédiaire : pendant le streaming, texte brut uniquement (ajout fluide, aucune reconstruction
# du DOM) ; le rendu Markdown complet n'a lieu qu'une seule fois, à la toute fin (`n.done`/`t.done`).
# 3e changement (même déploiement, demande du 01/10/2026) : remplace le placeholder "…" (3 points statiques,
# capture d'écran fournie) par un indicateur de réflexion active — 3 points qui pulsent en vague, classe
# `.eva-penser`, couleur = `--color-primary-element` du thème Nextcloud (pas de couleur en dur), respecte
# `prefers-reduced-motion`. Dans eva_ai_standalone.js : CSS ajoutée au module markdown.css déjà injecté par
# webpack (confirmé actif, c'est lui qui stylise déjà `.rt`). Dans eva_ai-main.js : `style/eva-chatgpt.css`
# (le fichier externe du dépôt) N'EST PAS déployé en pratique sur le serveur (README : "Rien n'a été exécuté
# sur un serveur" — mécanisme séparé via l'app theming_customcss, jamais branché) ; le markup est donc rendu
# AUTONOME, avec sa propre balise <style> intégrée, pour fonctionner sans dépendre de ce déploiement séparé.
# Testé : `node --check` propre sur les 2 fichiers patchés.
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-flicker-js.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-flicker-js.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 2 fichiers sont commités, `node --check` passe, et contiennent bien ces correctifs (marqueur :
#     classe `eva-penser`) ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT la version ORIGINE (sha256 du 1er correctif, déjà en prod) pour CHAQUE fichier ;
#   - sauvegarde des 2 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire, vérifiés
#     par empreinte, puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-flicker-js.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai/js
declare -A ORIGINE=(
  [eva_ai_standalone.js]=1c8203981f60b3dc2600f293042c60549e5fe0f9ec023ba75d8d6ec07c72d417
  [eva_ai-main.js]=3e1663612f3757f41c7530fd2c4a0e058a60bebd8ae0f326f771a5a6f8b3e6e2
)
FICHIERS=(eva_ai_standalone.js eva_ai-main.js)
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "app/js/$f" || { echo "ARRET : app/js/$f modifié et non commité"; exit 1; }
  node --check "app/js/$f" || { echo "ARRET : node --check KO sur $f"; exit 1; }
done
grep -q 'eva-penser' app/js/eva_ai_standalone.js || { echo "ARRET : le correctif n'est pas dans eva_ai_standalone.js"; exit 1; }
grep -q 'eva-penser' app/js/eva_ai-main.js || { echo "ARRET : le correctif n'est pas dans eva_ai-main.js"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

echo "1) contrôle de dérive (chaque fichier)"
DEJA=0
declare -A NOUVEAU
for f in "${FICHIERS[@]}"; do
  NOUVEAU[$f]=$(h < "app/js/$f")
  PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)
  if [ "$PROD" = "${NOUVEAU[$f]}" ]; then echo "   $f : déjà déployé"; DEJA=$((DEJA + 1)); continue; fi
  [ "$PROD" = "${ORIGINE[$f]}" ] || { echo "ARRET : $f en production (${PROD:0:12}…) n'est pas la version d'origine — rien n'a été modifié"; exit 1; }
  echo "   $f : production = version d'origine, nouveau sha256 ${NOUVEAU[$f]:0:12}…"
done
[ "$DEJA" -eq "${#FICHIERS[@]}" ] && { echo "   les ${#FICHIERS[@]} fichiers sont déjà déployés, rien à faire"; exit 0; }
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-flicker-js-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
for f in "${FICHIERS[@]}"; do
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] && continue
  r "sudo docker exec nextcloud-app-1 cat $APP/$f | sudo tee $B/$f >/dev/null"
  [ "$(r "sudo sha256sum $B/$f" | cut -c1-64)" = "${ORIGINE[$f]}" ] || { echo "ARRET : sauvegarde de $f invalide dans $B — rien n'a été modifié"; exit 1; }
done
r "sudo sh -c 'cd $B && sha256sum *.js > EMPREINTES'"
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-flicker-js.sh $B"' ERR

echo "3) dépôt en temporaire + contrôles, puis mise en place (un fichier à la fois)"
for f in "${FICHIERS[@]}"; do
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] && { echo "   $f : déjà déployé, ignoré"; continue; }
  TMP=$APP/$f.nouveau
  "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "app/js/$f"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ARRET : empreinte du fichier déposé ($f) incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
  r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $APP/$f"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ÉCHEC : empreinte finale incorrecte sur $f"; false; }
  echo "   $f déployé (${NOUVEAU[$f]:0:12}…)"
done

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "⚠️  Ce sont des fichiers JS servis au navigateur (pas du PHP OPcache) : un cache navigateur peut garder"
echo "   l'ancienne version un moment. Vider le cache / recharger sans cache (Cmd+Maj+R) avant de retester."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-flicker-js.sh $B"
