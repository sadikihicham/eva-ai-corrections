#!/usr/bin/env bash
# Déploie les fichiers src/ de eva_ai (HEAD de la branche courante) sur workspace4. Remplace appliquer-pdf.sh
# (limité à 2 fichiers et à la branche anti-invention) depuis l'ajout de ToolPolicy.php (convert_file, 28/09).
#
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/appliquer-eva.sh
# Garde-fous — le moindre écart arrête tout AVANT la moindre écriture en production :
#   - rien de non commité dans src/, HEAD poussé sur GitHub, hôte = workspace4 ;
#   - la production est identique à l'état de référence REFERENCE (sinon quelqu'un l'a modifiée : on n'écrase rien) ;
#   - sauvegarde des fichiers vérifiée par empreinte + fichier EMPREINTES dans la sauvegarde (lu par le retour arrière) ;
#   - nouveaux fichiers déposés en temporaire, vérifiés (empreinte + php -l), puis mis en place.
# Retour arrière : bash deploiement/retour-arriere-eva.sh <dossier de sauvegarde affiché à l'étape 2>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15 ubuntu@192.168.1.99)
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
APP=/var/www/html/custom_apps/eva_ai/lib/Service
REFERENCE=${REFERENCE:-2f83b99}   # production depuis le 28/09 06:38 (convert_file + confirmation d'écrasement) ; surchargeable : REFERENCE=<sha> bash …
FICHIERS=(ActionExecutor.php RagService.php ToolPolicy.php)
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

echo "0) vérifications préalables"
git diff --quiet HEAD -- src/ || { echo "ARRET : modifications non commitées dans src/"; exit 1; }
git fetch -q origin
[ -n "$(git branch -r --contains HEAD)" ] || { echo "ARRET : HEAD n'est pas poussé sur GitHub (git push d'abord)"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   dépôt et hôte OK — version déployée : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD)), référence : $REFERENCE"

echo "1) contrôle de dérive : la production doit être identique à $REFERENCE"
for f in "${FICHIERS[@]}"; do
  attendu=$(git show "$REFERENCE:src/$f" | h)
  reel=$(r "$DC app sha256sum $APP/$f" | cut -c1-64)
  [ "$attendu" = "$reel" ] || { echo "ARRET : $f a changé en production depuis $REFERENCE — rien n'a été modifié"; exit 1; }
  echo "   $f identique"
done

echo "2) sauvegarde des fichiers de production"
B=/srv/sauvegarde-eva_ai/avant-eva-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
for f in "${FICHIERS[@]}"; do
  r "$DC app cat $APP/$f | sudo tee $B/$f >/dev/null"
  [ "$(r "sudo sha256sum $B/$f" | cut -c1-64)" = "$(git show "$REFERENCE:src/$f" | h)" ] \
    || { echo "ARRET : sauvegarde de $f incomplète — rien n'a été modifié"; exit 1; }
done
# Dossier en 700 root : tout se fait sous sudo, cd compris (échec du 28/09 06:38 : « cd: Permission denied »).
r "sudo sh -c 'cd $B && sha256sum ${FICHIERS[*]} > EMPREINTES && echo $REFERENCE > REFERENCE'"
[ "$(r "sudo cat $B/EMPREINTES" | wc -l | tr -d ' ')" = "${#FICHIERS[@]}" ] || { echo "ARRET : EMPREINTES incomplet dans $B — rien n'a été modifié"; exit 1; }
echo "   $B (${#FICHIERS[@]} fichiers + EMPREINTES, vérifiés)"

echo "3) dépôt des nouveaux fichiers en temporaire, puis contrôle"
for f in "${FICHIERS[@]}"; do
  "${SSH[@]}" "$DC -u www-data app sh -c 'cat > /tmp/eva-$f.new'" < "src/$f"
  [ "$(r "$DC app sha256sum /tmp/eva-$f.new" | cut -c1-64)" = "$(h < "src/$f")" ] \
    || { echo "ARRET : transfert de $f corrompu — production intacte"; exit 1; }
  r "$DC app php -l /tmp/eva-$f.new"
done

echo "4) mise en place (tous les fichiers ont passé les contrôles)"
for f in "${FICHIERS[@]}"; do
  r "$DC -u www-data app sh -c 'cat /tmp/eva-$f.new > $APP/$f && rm -f /tmp/eva-$f.new'"
  [ "$(r "$DC app sha256sum $APP/$f" | cut -c1-64)" = "$(h < "src/$f")" ] \
    || { echo "ALERTE : $f en place ne correspond pas — lancer le retour arrière : bash deploiement/retour-arriere-eva.sh $B"; exit 1; }
  r "$DC app php -l $APP/$f"
done

echo "5) opcache"
r "$DC app php -r 'echo \"validate_timestamps=\" . ini_get(\"opcache.validate_timestamps\") . PHP_EOL;'"
echo "   (1 = PHP relit les fichiers tout seul ; 0 = NE RIEN REDÉMARRER, prévenir Claude)"

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4. Prochain déploiement : REFERENCE=$(git rev-parse --short HEAD) bash deploiement/appliquer-eva.sh"
echo "Retour arrière si besoin : bash deploiement/retour-arriere-eva.sh $B"
