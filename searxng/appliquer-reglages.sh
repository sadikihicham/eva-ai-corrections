#!/bin/bash
# Applique settings.yml (réglage des moteurs) sur le SearXNG DÉJÀ installé de workspace4, puis teste.
# Lancé par l'admin depuis son Mac :  bash appliquer-reglages.sh
# Sauvegarde l'ancien réglage dans /srv/searxng/config/settings.yml.avant-<date> ; retour arrière affiché à la fin.
set -euo pipefail
cd "$(dirname "$0")"
SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes ubuntu@192.168.1.99)
h() { shasum -a 256 | cut -c1-64; }
echo "0) vérifications"
[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte n'est pas workspace4"; exit 1; }
"${SSH[@]}" "sudo test -f /srv/searxng/config/settings.yml" </dev/null || { echo "ARRET : SearXNG n'est pas installé (/srv/searxng/config/settings.yml absent)"; exit 1; }
D=$(date +%Y%m%d-%H%M%S)
echo "1) sauvegarde de l'ancien réglage"
"${SSH[@]}" "sudo cp -p /srv/searxng/config/settings.yml /srv/searxng/config/settings.yml.avant-$D" </dev/null
echo "   /srv/searxng/config/settings.yml.avant-$D"
echo "2) copie du nouveau réglage + contrôle d'empreinte"
"${SSH[@]}" "sudo tee /srv/searxng/config/settings.yml.new >/dev/null" < settings.yml
[ "$("${SSH[@]}" "sudo sha256sum /srv/searxng/config/settings.yml.new" </dev/null | cut -c1-64)" = "$(h < settings.yml)" ] || { echo "ARRET : copie corrompue (rien n'a été remplacé)"; exit 1; }
"${SSH[@]}" "sudo chown 977:977 /srv/searxng/config/settings.yml.new && sudo chmod 640 /srv/searxng/config/settings.yml.new && sudo mv /srv/searxng/config/settings.yml.new /srv/searxng/config/settings.yml" </dev/null
echo "3) redémarrage de SearXNG"
"${SSH[@]}" "cd /srv/searxng && sudo docker compose -p searxng restart" </dev/null
for i in $(seq 1 30); do
  S=$("${SSH[@]}" "sudo docker inspect -f '{{.State.Health.Status}}' searxng-searxng-1" </dev/null || true)
  [ "$S" = healthy ] && break; sleep 2
done
echo "   état : $S"
echo "4) test depuis le conteneur de Nextcloud"
"${SSH[@]}" "cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T -u www-data app php -r '\$d=json_decode((string)file_get_contents(\"http://searxng:8080/search?q=php+latest+version&format=json&language=all&safesearch=1\"),true); foreach(array_slice(\$d[\"results\"]??[],0,3) as \$r) echo \"   - \",implode(\",\",\$r[\"engines\"]??[]),\" | \",\$r[\"title\"]??\"\",\"\n\";'" </dev/null
echo
echo "FAIT. Retour arrière : ssh ubuntu@192.168.1.99 'sudo cp -p /srv/searxng/config/settings.yml.avant-$D /srv/searxng/config/settings.yml && cd /srv/searxng && sudo docker compose -p searxng restart'"
