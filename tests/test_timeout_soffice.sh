#!/usr/bin/env bash
# Test du correctif timeout soffice (Indexer.php::legacyOfficeText) dans un conteneur PHP jetable — jamais en production.
#   bash tests/test_timeout_soffice.sh
# 1) php -l du fichier ; 2) la commande est construite par les MÊMES lignes que le code (extraites du fichier),
#    avec un faux soffice qui ignore SIGTERM et dort 300 s → doit rendre la main en ~130 s (120 + kill 10), sans reste.
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm -v "$PWD/app/lib/Service/Indexer.php:/t/Indexer.php:ro" php:8.3-cli bash -c '
set -e
php -l /t/Indexer.php
printf "#!/bin/sh\ntrap \"\" TERM\nsleep 300\n" > /usr/local/bin/soffice && chmod +x /usr/local/bin/soffice
L=$(grep -n "\$cmd = .timeout -k 10 120" /t/Indexer.php | cut -d: -f1)
[ -n "$L" ] || { echo "ÉCHEC : ligne timeout absente"; exit 1; }
sed -n "${L},$((L+2))p" /t/Indexer.php > /t/cmd.inc
cat > /t/run.php <<PHP
<?php
\$bin = "/usr/local/bin/soffice"; \$profile = "/tmp/p"; \$outDir = "/tmp/o"; \$in = "/tmp/in.doc";
$(cat /t/cmd.inc)
echo "commande : \$cmd\n";
\$t = microtime(true); shell_exec(\$cmd); \$d = round(microtime(true) - \$t);
echo "durée : {\$d} s\n";
exit((\$d >= 115 && \$d <= 140) ? 0 : 1);
PHP
php /t/run.php && echo "OK : soffice bloqué arrêté par timeout" || { echo "ÉCHEC : durée hors bornes"; exit 1; }
sleep 1; RESTE=$(grep -l -a -P "(^sleep\x00300\x00|/usr/local/bin/soffice\x00)" /proc/[0-9]*/cmdline 2>/dev/null | wc -l); if [ "$RESTE" -gt 0 ]; then echo "ÉCHEC : $RESTE processus soffice/sleep restant(s)"; exit 1; else echo "OK : aucun processus restant (balayage /proc)"; fi'
