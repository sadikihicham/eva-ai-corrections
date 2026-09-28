#!/usr/bin/env bash
# Déploie eva_ai (HEAD de la branche courante) sur workspace4 : src/*.php → lib/Service/, et app/<chemin> → <chemin>
# (bundles JS, l10n, templates, info.xml, icônes… versionnés depuis le renommage Infinity AI du 28/09).
#
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/appliquer-eva.sh                 (ESSAI=1 : s'arrête après les contrôles, n'écrit rien)
# Garde-fous — le moindre écart arrête tout AVANT la moindre écriture en production :
#   - rien de non commité dans src/ ni app/, HEAD poussé sur GitHub, hôte = workspace4 ;
#   - la production est identique à l'état de référence REFERENCE, fichier par fichier (sinon on n'écrase rien) ;
#   - sauvegarde des fichiers vérifiée par empreinte + fichier EMPREINTES (chemins relatifs à l'app) ;
#   - nouveaux fichiers déposés en temporaire, vérifiés (empreintes + php -l), puis mis en place.
# Retour arrière : bash deploiement/retour-arriere-eva.sh <dossier de sauvegarde affiché à l'étape 2>
set -euo pipefail
cd "$(dirname "$0")/.."

SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15 ubuntu@192.168.1.99)
DOCKER='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
RACINE=/var/www/html/custom_apps/eva_ai
REFERENCE=${REFERENCE:-4fe6752}   # production depuis le 28/09 12:08 (H.2 + G.2) ; surchargeable : REFERENCE=<sha> bash …
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

# Table des fichiers : « chemin dans le dépôt » → « chemin relatif à la racine de l'app ».
DEPOT=(); CIBLE=()
for f in src/*.php; do DEPOT+=("$f"); CIBLE+=("lib/Service/${f#src/}"); done
while IFS= read -r f; do DEPOT+=("$f"); CIBLE+=("${f#app/}"); done < <(git ls-files app | grep -v '^app/LISEZMOI.md$')
N=${#DEPOT[@]}

echo "0) vérifications préalables ($N fichiers)"
git diff --quiet HEAD -- src/ app/ || { echo "ARRET : modifications non commitées dans src/ ou app/"; exit 1; }
git fetch -q origin
[ -n "$(git branch -r --contains HEAD)" ] || { echo "ARRET : HEAD n'est pas poussé sur GitHub (git push d'abord)"; exit 1; }
[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
echo "   dépôt et hôte OK — version déployée : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD)), référence : $REFERENCE"

echo "1) contrôle de dérive : la production doit être identique à $REFERENCE"
MANQUE=$(for f in "${DEPOT[@]}"; do git cat-file -e "$REFERENCE:$f" 2>/dev/null || echo "$f"; done)
[ -z "$MANQUE" ] || { echo "$MANQUE" | head -5; echo "ARRET : fichier(s) absent(s) de $REFERENCE — rien n'a été modifié"; exit 1; }
ATTENDU=$(for i in "${!DEPOT[@]}"; do echo "$(git show "$REFERENCE:${DEPOT[$i]}" | h)  ${CIBLE[$i]}"; done)
REEL=$(printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "$DOCKER app sh -c 'cd $RACINE && xargs -d \"\\n\" sha256sum'")
ECARTS=$(diff <(sort -k2 <<< "$ATTENDU") <(sort -k2 <<< "$REEL") || true)
[ -z "$ECARTS" ] || { echo "$ECARTS" | head -10; echo "ARRET : la production a changé depuis $REFERENCE — rien n'a été modifié"; exit 1; }
echo "   $N fichiers identiques"
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }

echo "2) sauvegarde des fichiers de production"
B=/srv/sauvegarde-eva_ai/avant-eva-$(date +%Y%m%d-%H%M%S)
r "sudo mkdir -m 700 $B"
printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "$DOCKER app sh -c 'cd $RACINE && tar cf - -T -' | sudo tar xf - -C $B"
# Dossier en 700 root : tout se fait sous sudo, cd compris (échec du 28/09 06:38 : « cd: Permission denied »).
printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "sudo sh -c 'cd $B && xargs -d \"\\n\" sha256sum > EMPREINTES && echo $REFERENCE > REFERENCE'"
SAUVE=$(r "sudo cat $B/EMPREINTES")
[ -z "$(diff <(sort -k2 <<< "$ATTENDU") <(sort -k2 <<< "$SAUVE") || true)" ] || { echo "ARRET : sauvegarde incomplète dans $B — rien n'a été modifié"; exit 1; }
echo "   $B ($N fichiers + EMPREINTES, vérifiés)"

echo "3) dépôt des nouveaux fichiers en temporaire, puis contrôle"
T=/tmp/eva-deploiement-$$
ETAPE=$(mktemp -d)
for i in "${!DEPOT[@]}"; do mkdir -p "$ETAPE/$(dirname "${CIBLE[$i]}")"; cp "${DEPOT[$i]}" "$ETAPE/${CIBLE[$i]}"; done
NOUVEAU=$(for i in "${!DEPOT[@]}"; do echo "$(h < "${DEPOT[$i]}")  ${CIBLE[$i]}"; done)
COPYFILE_DISABLE=1 tar cf - -C "$ETAPE" . | "${SSH[@]}" "$DOCKER -u www-data app sh -c 'rm -rf $T && mkdir -p $T && tar xf - -C $T'"
rm -rf "$ETAPE"
RECU=$(printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "$DOCKER app sh -c 'cd $T && xargs -d \"\\n\" sha256sum'")
[ -z "$(diff <(sort -k2 <<< "$NOUVEAU") <(sort -k2 <<< "$RECU") || true)" ] || { echo "ARRET : transfert corrompu — production intacte"; exit 1; }
PHPKO=$(printf '%s\n' "${CIBLE[@]}" | grep '\.php$' | "${SSH[@]}" "$DOCKER app sh -c 'cd $T && while read -r f; do php -l \"\$f\" >/dev/null 2>&1 || echo \"\$f\"; done'")
[ -z "$PHPKO" ] || { echo "$PHPKO"; echo "ARRET : erreur de syntaxe PHP — production intacte"; exit 1; }
echo "   $N fichiers reçus intacts, PHP valide"

echo "4) mise en place (tous les fichiers ont passé les contrôles)"
# cat > : garde propriétaire et droits des fichiers existants.
printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "$DOCKER -u www-data app sh -c 'while read -r f; do cat \"$T/\$f\" > \"$RACINE/\$f\" || echo \"ECHEC \$f\"; done; rm -rf $T'"
EN_PLACE=$(printf '%s\n' "${CIBLE[@]}" | "${SSH[@]}" "$DOCKER app sh -c 'cd $RACINE && xargs -d \"\\n\" sha256sum'")
[ -z "$(diff <(sort -k2 <<< "$NOUVEAU") <(sort -k2 <<< "$EN_PLACE") || true)" ] \
  || { echo "ALERTE : fichiers en place différents — lancer le retour arrière : bash deploiement/retour-arriere-eva.sh $B"; exit 1; }
echo "   $N fichiers en place, empreintes vérifiées"

echo "5) opcache"
r "$DOCKER app php -r 'echo \"validate_timestamps=\" . ini_get(\"opcache.validate_timestamps\") . PHP_EOL;'"
echo "   (1 = PHP relit les fichiers tout seul ; 0 = NE RIEN REDÉMARRER, prévenir Claude)"

echo
echo "DÉPLOYÉ : $(git rev-parse --short HEAD) sur workspace4. Prochain déploiement : REFERENCE=$(git rev-parse --short HEAD) bash deploiement/appliquer-eva.sh"
echo "Retour arrière si besoin : bash deploiement/retour-arriere-eva.sh $B"
