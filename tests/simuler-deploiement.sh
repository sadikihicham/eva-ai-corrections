#!/usr/bin/env bash
# Simulation LOCALE d'appliquer-eva.sh / retour-arriere-eva.sh : aucune connexion à un serveur.
# Faux ssh/sudo/docker/xargs/php/hostname ; « production » = dossier temporaire rempli depuis git.
#     bash tests/simuler-deploiement.sh                  (bash courant)
#     BASH_TEST=/bin/bash bash tests/simuler-deploiement.sh   (bash 3.2 de macOS)
# PROD_REF = commit supposé en production (défaut e0608b1). Les scripts sont copiés et adaptés, jamais exécutés tels quels.
set -uo pipefail
DEPOT="$(cd "$(dirname "$0")/.." && pwd)"
SIM="$(mktemp -d "${TMPDIR:-/tmp}/simuler-deploiement.XXXXXX")"
trap 'rm -rf "$SIM"' EXIT
BASH_TEST=${BASH_TEST:-bash}
PROD_REF=${PROD_REF:-e0608b1}

mkdir -p "$SIM"/{bin,prod,sauv,tmp}
cat > "$SIM/bin/fauxssh" <<'EOF'
#!/bin/bash
exec bash -c "$*"
EOF
cat > "$SIM/bin/fauxdocker" <<'EOF'
#!/bin/bash
while [ $# -gt 0 ]; do case "$1" in -u) shift 2;; app) shift; break;; *) shift;; esac; done
exec "$@"
EOF
printf '#!/bin/bash\nexec "$@"\n' > "$SIM/bin/sudo"
printf '#!/bin/bash\necho workspace4\n' > "$SIM/bin/hostname"
cat > "$SIM/bin/xargs" <<'EOF'
#!/bin/bash
if [ "${1:-}" = -d ]; then shift 2; tr '\n' '\0' | /usr/bin/xargs -0 "$@"; else /usr/bin/xargs "$@"; fi
EOF
cat > "$SIM/bin/php" <<'EOF'
#!/bin/bash
case "${1:-}" in -l) exit 0;; -r) echo "validate_timestamps=1";; esac
EOF
chmod +x "$SIM"/bin/*

adapter() {  # $1 script source, $2 copie
  sed -e "s#^SSH=(ssh .*#SSH=(fauxssh)#" \
      -e "s#^DOCKER=.*#DOCKER='fauxdocker'#" \
      -e "s#/var/www/html/custom_apps/eva_ai#$SIM/prod#" \
      -e "s#/srv/sauvegarde-eva_ai#$SIM/sauv#" \
      -e "s#T=/tmp/eva-#T=$SIM/tmp/eva-#" \
      -e "s#^cd \"\$(dirname \"\$0\")/..\"#cd \"$DEPOT\"#" "$1" > "$2"
  grep -q 'SSH=(fauxssh)' "$2" && grep -q "DOCKER='fauxdocker'" "$2" || { echo "ADAPTATION RATÉE $2"; exit 2; }
  ! grep -q '192.168.1' "$2" || { echo "IP RESTANTE dans $2"; exit 2; }
}
adapter "$DEPOT/deploiement/appliquer-eva.sh" "$SIM/appliquer.sh"
adapter "$DEPOT/deploiement/retour-arriere-eva.sh" "$SIM/retour.sh"

# Production simulée = PROD_REF (app/ + 3 src/) ; les 5 fichiers versionnés après coup = copie fdb4562.
remplir() {
  rm -rf "$SIM/prod" && mkdir -p "$SIM/prod"
  (cd "$DEPOT" && git ls-tree -r --name-only "$PROD_REF" app) | grep -v '^app/LISEZMOI.md$' | while read -r f; do
    mkdir -p "$SIM/prod/$(dirname "${f#app/}")"; (cd "$DEPOT" && git show "$PROD_REF:$f") > "$SIM/prod/${f#app/}"; done
  for f in ActionExecutor.php RagService.php ToolPolicy.php; do mkdir -p "$SIM/prod/lib/Service"; (cd "$DEPOT" && git show "$PROD_REF:src/$f") > "$SIM/prod/lib/Service/$f"; done
  (cd "$DEPOT" && git show --name-only --format= fdb4562) | while read -r f; do
    mkdir -p "$SIM/prod/$(dirname "${f#app/}")"; (cd "$DEPOT" && git show "fdb4562:$f") > "$SIM/prod/${f#app/}"; done
  echo "bruit hors déploiement" > "$SIM/prod/fichier-non-gere.txt"
}
export PATH="$SIM/bin:$PATH"
lancer() { REFERENCE=$PROD_REF "$BASH_TEST" "$@" 2>&1; }
ok=0; ko=0
verif() { if eval "$2"; then echo "  ✅ $1"; ok=$((ok+1)); else echo "  ❌ $1"; ko=$((ko+1)); fi; }

echo "== S1 essai à blanc sur une production conforme"
remplir; cp -R "$SIM/prod" "$SIM/prod-origine"
S=$(ESSAI=1 lancer "$SIM/appliquer.sh"); echo "$S" | sed 's/^/    /'
verif "essai réussi" 'grep -q "ESSAI=1 : arrêt avant toute écriture" <<< "$S"'
verif "5 nouveaux annoncés" 'grep -q "5 nouveaux fichiers" <<< "$S"'
verif "production intacte" 'diff -r "$SIM/prod-origine" "$SIM/prod" >/dev/null'

echo "== S2 déploiement"
S=$(lancer "$SIM/appliquer.sh"); echo "$S" | tail -8 | sed 's/^/    /'
B=$(grep -o "$SIM/sauv/avant-eva-[0-9-]*" <<< "$S" | head -1)
verif "déployé" 'grep -q "^DÉPLOYÉ" <<< "$S"'
verif "micro.js en place = dépôt" 'cmp -s "$SIM/prod/js/micro.js" "$DEPOT/app/js/micro.js"'
verif "PageController en place = dépôt" 'cmp -s "$SIM/prod/lib/Controller/PageController.php" "$DEPOT/app/lib/Controller/PageController.php"'
verif "NOUVEAUX = 5 lignes" '[ "$(wc -l < "$B/NOUVEAUX" | tr -d " ")" = 5 ]'
verif "sauvegarde sans les nouveaux" '[ ! -e "$B/js/micro.js" ] && [ -e "$B/lib/Controller/PageController.php" ]'
verif "aucun .eva-tmp restant" '[ -z "$(find "$SIM/prod" -name "*.eva-tmp")" ]'

echo "== S3 retour arrière"
S=$("$BASH_TEST" "$SIM/retour.sh" "$B" 2>&1); echo "$S" | sed 's/^/    /'
verif "production = origine exacte (nouveaux retirés, fichier non géré conservé)" 'diff -r "$SIM/prod-origine" "$SIM/prod"'

echo "== S4 un « nouveau » fichier existe déjà en production"
remplir; echo "autre contenu" > "$SIM/prod/l10n/ar.json"; cp -R "$SIM/prod" "$SIM/prod-s4"
S=$(lancer "$SIM/appliquer.sh"); echo "$S" | tail -3 | sed 's/^/    /'
verif "arrêt" 'grep -q "déjà présent(s) en production" <<< "$S"'
verif "rien écrit" 'diff -r "$SIM/prod-s4" "$SIM/prod" >/dev/null && [ -z "$(ls "$SIM/sauv" | grep -v "^avant-eva" ; ls "$SIM/sauv" | wc -l | grep -v "^ *1$")" ]'

echo "== S5 dérive sur un fichier copié de production (PageController modifié)"
remplir; echo "// retouche" >> "$SIM/prod/lib/Controller/PageController.php"; cp -R "$SIM/prod" "$SIM/prod-s5"
S=$(lancer "$SIM/appliquer.sh"); echo "$S" | tail -2 | sed 's/^/    /'
verif "arrêt dérive" 'grep -q "la production a changé" <<< "$S"'
verif "rien écrit" 'diff -r "$SIM/prod-s5" "$SIM/prod" >/dev/null'

echo "== S6 retour arrière alors qu'un fichier ajouté a été modifié depuis"
remplir; rm -rf "$SIM/prod-origine"; cp -R "$SIM/prod" "$SIM/prod-origine"
S=$(lancer "$SIM/appliquer.sh"); B=$(grep -o "$SIM/sauv/avant-eva-[0-9-]*" <<< "$S" | tail -1)
echo "// modifié à la main" >> "$SIM/prod/js/micro.js"; cp -R "$SIM/prod" "$SIM/prod-s6"
S=$("$BASH_TEST" "$SIM/retour.sh" "$B" 2>&1); echo "$S" | tail -2 | sed 's/^/    /'
verif "arrêt" 'grep -q "modifié(s) depuis" <<< "$S"'
verif "rien restauré ni retiré" 'diff -r "$SIM/prod-s6" "$SIM/prod" >/dev/null'

echo "== S7 retour arrière d'une sauvegarde sans NOUVEAUX (format antérieur)"
remplir; rm -rf "$SIM/prod-origine"; cp -R "$SIM/prod" "$SIM/prod-origine"
S=$(lancer "$SIM/appliquer.sh"); B=$(grep -o "$SIM/sauv/avant-eva-[0-9-]*" <<< "$S" | tail -1)
rm -f "$B/NOUVEAUX"
S=$("$BASH_TEST" "$SIM/retour.sh" "$B" 2>&1); echo "$S" | tail -1 | sed 's/^/    /'
verif "restauré, nouveaux laissés (comportement antérieur)" 'grep -q "^Restauré" <<< "$S" && [ -e "$SIM/prod/js/micro.js" ] && cmp -s "$SIM/prod/lib/Controller/PageController.php" "$SIM/prod-origine/lib/Controller/PageController.php"'

echo; echo "RÉSULTAT ($BASH_TEST) : $ok ✅ / $ko ❌"
[ "$ko" = 0 ]
