#!/usr/bin/env bash
# Déploie le générateur PDF + les relances « outil manqué » (recherche proposée, météo inventée) — branche anti-invention — sur workspace4.
#
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/appliquer-liens.sh
# Contrairement à appliquer.sh, ce script tourne en local et ouvre une connexion ssh par étape : chaque fichier est
# envoyé par sa propre entrée standard (plus de script transmis par « bash -s », cause des 2 essais ratés du 28/09).
#
# Garde-fous, dans l'ordre — le moindre écart arrête tout AVANT la moindre écriture en production :
#   - bonne branche, rien de non commité, hôte = workspace4 ;
#   - la production est identique à l'état de référence f571cf4 (anti-invention v3.1, en production depuis le 28/09 03:31) (sinon : quelqu'un l'a modifiée, on n'écrase rien) ;
#   - sauvegarde des 2 fichiers, vérifiée par empreinte ;
#   - les 2 nouveaux fichiers sont d'abord déposés en fichiers temporaires, vérifiés (empreinte + php -l),
#     et seulement alors copiés à leur place.
# Retour arrière : bash deploiement/retour-arriere-pdf.sh <dossier de sauvegarde affiché à l'étape 2>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes ubuntu@192.168.1.99)
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
APP=/var/www/html/custom_apps/eva_ai/lib/Service
REFERENCE=${REFERENCE:-ca8db2a}   # version en production (déployée 28/09 05:38) ; surchargeable : REFERENCE=<sha> bash …
FICHIERS=(ActionExecutor.php RagService.php)
h() { shasum -a 256 | cut -c1-64; }

echo "0) vérifications préalables"
[ "$(git rev-parse --abbrev-ref HEAD)" = anti-invention ] || { echo "ARRET : pas sur la branche anti-invention"; exit 1; }
git diff --quiet HEAD -- src/ || { echo "ARRET : modifications non commitées dans src/"; exit 1; }
[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   branche, dépôt et hôte OK — version déployée : $(git rev-parse --short HEAD)"

echo "1) contrôle de dérive : la production doit être identique à $REFERENCE"
for f in "${FICHIERS[@]}"; do
  attendu=$(git show "$REFERENCE:src/$f" | h)
  reel=$("${SSH[@]}" "$DC app sha256sum $APP/$f" </dev/null | cut -c1-64)
  [ "$attendu" = "$reel" ] || { echo "ARRET : $f a changé en production depuis $REFERENCE — rien n'a été modifié"; exit 1; }
  echo "   $f identique"
done

echo "2) sauvegarde des fichiers de production"
B=/srv/sauvegarde-eva_ai/avant-pdf-$(date +%Y%m%d-%H%M%S)
"${SSH[@]}" "sudo mkdir -m 700 $B" </dev/null
for f in "${FICHIERS[@]}"; do
  "${SSH[@]}" "$DC app cat $APP/$f | sudo tee $B/$f >/dev/null" </dev/null
  [ "$("${SSH[@]}" "sudo sha256sum $B/$f" </dev/null | cut -c1-64)" = "$(git show "$REFERENCE:src/$f" | h)" ] \
    || { echo "ARRET : sauvegarde de $f incomplète — rien n'a été modifié"; exit 1; }
done
echo "   $B (2 fichiers, empreintes vérifiées)"

echo "3) dépôt des nouveaux fichiers en temporaire, puis contrôle"
for f in "${FICHIERS[@]}"; do
  "${SSH[@]}" "$DC -u www-data app sh -c 'cat > /tmp/eva-$f.new'" < "src/$f"
  [ "$("${SSH[@]}" "$DC app sha256sum /tmp/eva-$f.new" </dev/null | cut -c1-64)" = "$(h < "src/$f")" ] \
    || { echo "ARRET : transfert de $f corrompu — production intacte"; exit 1; }
  "${SSH[@]}" "$DC app php -l /tmp/eva-$f.new" </dev/null
done

echo "4) mise en place (les 2 fichiers ont passé tous les contrôles)"
for f in "${FICHIERS[@]}"; do
  "${SSH[@]}" "$DC -u www-data app sh -c 'cat /tmp/eva-$f.new > $APP/$f && rm -f /tmp/eva-$f.new'" </dev/null
  [ "$("${SSH[@]}" "$DC app sha256sum $APP/$f" </dev/null | cut -c1-64)" = "$(h < "src/$f")" ] \
    || { echo "ALERTE : $f en place ne correspond pas — lancer le retour arrière : bash deploiement/retour-arriere-pdf.sh $B"; exit 1; }
  "${SSH[@]}" "$DC app php -l $APP/$f" </dev/null
done

echo "5) opcache"
"${SSH[@]}" "$DC app php -r 'echo \"validate_timestamps=\" . ini_get(\"opcache.validate_timestamps\") . PHP_EOL;'" </dev/null
echo "   (1 = PHP relit les fichiers tout seul ; 0 = NE RIEN REDÉMARRER, prévenir Claude)"

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4."
echo "Retour arrière si besoin : bash deploiement/retour-arriere-pdf.sh $B"
