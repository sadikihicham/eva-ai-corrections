#!/usr/bin/env bash
# Tests PHP de la dictée, exécutés dans le conteneur app de workspace4 (PHP 8.3 de la production) sur les fichiers
# du DÉPÔT (HEAD de la branche courante), jamais sur ceux installés. Rien n'est écrit dans Nextcloud : les fichiers
# passent par un dossier temporaire du conteneur, retiré à la fin.
#
# À lancer par l'admin, SUR LE MAC, depuis le dépôt eva-corrections :
#     bash deploiement/tests-php-dictee.sh
# Attendu : « php -l » sans erreur pour tous les fichiers PHP de app/, puis les cas de localOrigin et d'activation
# (2 interrupteurs, script micro, meta, CSP) tous ✅, code 0.
set -euo pipefail
cd "$(dirname "$0")/.."
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15 ubuntu@192.168.1.99)
DOCKER='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'

[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
git diff --quiet HEAD -- app/ tests/ || { echo "ARRET : modifications non commitées dans app/ ou tests/"; exit 1; }
PHP_APP=$(git ls-files 'app/*.php')
echo "Version testée : $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"
# Archive des fichiers versionnés (git archive : exactement HEAD, sans fichier local parasite).
git archive HEAD tests/test_dictee_origine.php tests/test_dictee_activation.php $PHP_APP | "${SSH[@]}" "$DOCKER app sh -c '
  d=\$(mktemp -d) && tar xf - -C \$d && cd \$d || exit 2
  c=0
  echo \"1) php -l\"
  for f in \$(find app -name \"*.php\"); do php -l \"\$f\" >/dev/null 2>&1 && echo \"   ✅ \$f\" || { echo \"   ❌ \$f\"; c=1; }; done
  echo \"2) localOrigin\"
  php tests/test_dictee_origine.php app/lib/Controller/PageController.php || c=1
  echo \"3) activation (compte non activé = ni script, ni meta, ni CSP locale)\"
  php tests/test_dictee_activation.php app/lib/Controller/PageController.php || c=1
  cd / && rm -rf \$d
  exit \$c'"
echo "TESTS PHP : OK"
