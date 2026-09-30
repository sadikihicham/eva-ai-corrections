#!/usr/bin/env bash
# Déploie UNIQUEMENT lib/Service/OpenAICompatible.php et lib/Service/Ollama.php (correctif streaming réel pour
# le provider OpenAI-compatible / vLLM-LiteLLM, branche streaming-vllm-openai-compatible, PR #26 mergée) sur
# workspace4. Ne touche à aucun autre fichier d'eva_ai (≠ appliquer-eva.sh qui déploie tout l'état de la branche).
#
# À lancer par l'admin, SUR LE MAC, depuis ce worktree :
#     ESSAI=1 bash deploiement/appliquer-streaming-vllm.sh   → contrôles seuls, n'écrit rien
#     bash deploiement/appliquer-streaming-vllm.sh           → déploie
# Garde-fous (tout écart arrête AVANT la moindre écriture) :
#   - les 2 fichiers sont commités et contiennent bien le correctif ; hôte = workspace4 ;
#   - la production doit être EXACTEMENT la version d'origine (sha256 ORIGINE, notée dans le commit d'import
#     255b4de du 30/09) pour CHAQUE fichier — sinon on n'écrase rien ;
#   - sauvegarde des 2 fichiers vérifiée par empreinte ; nouveaux fichiers déposés en temporaire, vérifiés
#     (empreinte + php -l), puis mis en place un par un.
# Retour arrière : bash deploiement/retour-arriere-streaming-vllm.sh <dossier de sauvegarde affiché>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
APP=/var/www/html/custom_apps/eva_ai/lib/Service
declare -A ORIGINE=(
  [OpenAICompatible.php]=b331984ebacc0b8309f652f475bbcc238b543d0c8915fb4cec2c2e6f7d569152
  [Ollama.php]=d18905bcdb4a85eb1ac2fdd7e06ca4e2a762a6a946490bf6b48d7a2f13d190a3
)
FICHIERS=(OpenAICompatible.php Ollama.php)
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

echo "0) vérifications préalables"
for f in "${FICHIERS[@]}"; do
  git diff --quiet HEAD -- "app/lib/Service/$f" || { echo "ARRET : app/lib/Service/$f modifié et non commité"; exit 1; }
done
grep -q "public function chatStream(array \$messages" app/lib/Service/OpenAICompatible.php || { echo "ARRET : le correctif n'est pas dans OpenAICompatible.php"; exit 1; }
grep -q "openAiCompatible()->chatStream(" app/lib/Service/Ollama.php || { echo "ARRET : le correctif n'est pas dans Ollama.php"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   version : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

echo "1) contrôle de dérive (chaque fichier)"
DEJA=0
declare -A NOUVEAU
for f in "${FICHIERS[@]}"; do
  NOUVEAU[$f]=$(h < "app/lib/Service/$f")
  PROD=$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)
  if [ "$PROD" = "${NOUVEAU[$f]}" ]; then echo "   $f : déjà déployé"; DEJA=$((DEJA + 1)); continue; fi
  [ "$PROD" = "${ORIGINE[$f]}" ] || { echo "ARRET : $f en production (${PROD:0:12}…) n'est pas la version d'origine — rien n'a été modifié"; exit 1; }
  echo "   $f : production = version d'origine, nouveau sha256 ${NOUVEAU[$f]:0:12}…"
done
[ "$DEJA" -eq "${#FICHIERS[@]}" ] && { echo "   les ${#FICHIERS[@]} fichiers sont déjà déployés, rien à faire"; exit 0; }
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde"
B=/srv/sauvegarde-eva_ai/avant-streaming-vllm-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
for f in "${FICHIERS[@]}"; do
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] && continue   # déjà déployé (étape 1), rien à sauvegarder
  r "sudo docker exec nextcloud-app-1 cat $APP/$f | sudo tee $B/$f >/dev/null"
  [ "$(r "sudo sha256sum $B/$f" | cut -c1-64)" = "${ORIGINE[$f]}" ] || { echo "ARRET : sauvegarde de $f invalide dans $B — rien n'a été modifié"; exit 1; }
done
r "sudo sh -c 'cd $B && sha256sum *.php > EMPREINTES'"
echo "   $B (vérifiée)"
trap 'echo; echo "ÉCHEC après la sauvegarde — retour arrière : bash deploiement/retour-arriere-streaming-vllm.sh $B"' ERR

echo "3) dépôt en temporaire + contrôles, puis mise en place (un fichier à la fois)"
for f in "${FICHIERS[@]}"; do
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] && { echo "   $f : déjà déployé, ignoré"; continue; }
  TMP=$APP/$f.nouveau
  "${SSH[@]}" "sudo docker exec -i -u www-data nextcloud-app-1 sh -c 'cat > $TMP'" < "app/lib/Service/$f"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $TMP" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ARRET : empreinte du fichier déposé ($f) incorrecte"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
  r "sudo docker exec -u www-data nextcloud-app-1 php -l $TMP" | grep -q "No syntax errors" || { echo "ARRET : php -l KO sur $f"; r "sudo docker exec -u www-data nextcloud-app-1 rm -f $TMP"; exit 1; }
  r "sudo docker exec -u www-data nextcloud-app-1 mv $TMP $APP/$f"
  [ "$(r "sudo docker exec nextcloud-app-1 sha256sum $APP/$f" | cut -c1-64)" = "${NOUVEAU[$f]}" ] || { echo "ÉCHEC : empreinte finale incorrecte sur $f"; false; }
  echo "   $f déployé (${NOUVEAU[$f]:0:12}…)"
done

echo "4) cohérence conteneur cron"
for f in "${FICHIERS[@]}"; do
  r "sudo docker exec nextcloud-cron-1 sha256sum $APP/$f" | cut -c1-64 | grep -qx "${NOUVEAU[$f]}" && echo "   $f vu identique par le conteneur cron"
done

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-streaming-vllm.sh $B"
