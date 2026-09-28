#!/usr/bin/env bash
# Retour arrière d'un déploiement eva_ai : restaure les fichiers de la sauvegarde faite par appliquer-eva.sh.
# Chaque sauvegarde porte ses empreintes (fichier EMPREINTES) : le retour arrière vérifie la sauvegarde contre
# ELLE-MÊME, pas contre une version codée en dur. Chemins d'EMPREINTES relatifs à la racine de l'app depuis le
# renommage Infinity AI (28/09) ; un nom sans « / » (sauvegardes plus anciennes) désigne lib/Service/<nom>.
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/retour-arriere-eva.sh /srv/sauvegarde-eva_ai/avant-eva-AAAAMMJJ-HHMMSS
# Anciennes sauvegardes (avant-pdf-*, sans EMPREINTES) : EMPREINTES_DEPUIS=<sha de la version sauvegardée> bash …
set -euo pipefail
cd "$(dirname "$0")/.."
B="${1:?dossier de sauvegarde manquant, affiché par appliquer-eva.sh à l’étape 2}"   # apostrophe typographique (bash 3.2)
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15 ubuntu@192.168.1.99)
DOCKER='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
RACINE=/var/www/html/custom_apps/eva_ai
h() { shasum -a 256 | cut -c1-64; }
r() { "${SSH[@]}" "$@" </dev/null; }

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
if [ -n "${EMPREINTES_DEPUIS:-}" ]; then
  LISTE=$(r "sudo ls $B" | grep '\.php$' || true)
  EMP=$(for f in $LISTE; do echo "$(git show "$EMPREINTES_DEPUIS:src/$f" | h)  $f"; done)
else
  EMP=$(r "sudo cat $B/EMPREINTES") || { echo "ARRET : $B/EMPREINTES absent (ancienne sauvegarde : relancer avec EMPREINTES_DEPUIS=<sha>)"; exit 1; }
fi
[ -n "$EMP" ] || { echo "ARRET : aucun fichier à restaurer dans $B"; exit 1; }
N=$(wc -l <<< "$EMP" | tr -d ' ')

echo "1) vérification de la sauvegarde contre ses empreintes ($N fichiers)"
SAUVE=$(cut -c67- <<< "$EMP" | "${SSH[@]}" "sudo sh -c 'cd $B && xargs -d \"\\n\" sha256sum'")
[ -z "$(diff <(sort -k2 <<< "$EMP") <(sort -k2 <<< "$SAUVE") || true)" ] || { echo "ARRET : sauvegarde incomplète ou modifiée — rien restauré"; exit 1; }
echo "   sauvegarde intacte"

echo "2) restauration"
# Chemin dans la sauvegarde → chemin dans l'app (nom seul = lib/Service/<nom>).
PAIRES=$(cut -c67- <<< "$EMP" | while IFS= read -r f; do case "$f" in */*) echo "$f|$f";; *) echo "$f|lib/Service/$f";; esac; done)
T=/tmp/eva-retour-$$
cut -c67- <<< "$EMP" | "${SSH[@]}" "sudo tar cf - -C $B -T - | ($DOCKER -u www-data app sh -c 'rm -rf $T && mkdir -p $T && tar xf - -C $T')"
printf '%s\n' "$PAIRES" | "${SSH[@]}" "$DOCKER -u www-data app sh -c 'while IFS=\"|\" read -r s c; do cat \"$T/\$s\" > \"$RACINE/\$c\" || echo \"ECHEC \$c\"; done; rm -rf $T'"
ATTENDU=$(while read -r somme f; do case "$f" in */*) c=$f;; *) c=lib/Service/$f;; esac; echo "$somme  $c"; done <<< "$EMP")
EN_PLACE=$(cut -c67- <<< "$ATTENDU" | "${SSH[@]}" "$DOCKER app sh -c 'cd $RACINE && xargs -d \"\\n\" sha256sum'")
[ -z "$(diff <(sort -k2 <<< "$ATTENDU") <(sort -k2 <<< "$EN_PLACE") || true)" ] || { echo "ALERTE : fichiers restaurés différents de la sauvegarde"; exit 1; }
PHPKO=$(cut -c67- <<< "$ATTENDU" | grep '\.php$' | "${SSH[@]}" "$DOCKER app sh -c 'cd $RACINE && while read -r f; do php -l \"\$f\" >/dev/null 2>&1 || echo \"\$f\"; done'")
[ -z "$PHPKO" ] || { echo "$PHPKO"; echo "ALERTE : erreur PHP après restauration"; exit 1; }
echo "Restauré depuis $B ($N fichiers)$(r "sudo cat $B/REFERENCE 2>/dev/null" | sed 's/^/ (état /;s/$/)/')."
