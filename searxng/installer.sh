#!/usr/bin/env bash
# Installe un SearXNG INTERNE sur workspace4 et y branche l'assistant eva_ai (à la place de Bing).
#
# À lancer par l'admin, SUR LE MAC, depuis ce dossier :
#     bash installer.sh
# Une connexion ssh par étape ; aucun script transmis en bloc, sauf deux petits scripts en entrée standard :
# la génération du secret (étape 3) et le test PHP (étape 6).
#
# Garde-fous : hôte = workspace4 ; /srv/searxng ne doit PAS exister (rien n'est écrasé) ; le réseau
# nextcloud_frontend doit exister et contenir le conteneur app ; eva n'est modifié qu'après un test réussi
# depuis le conteneur app ; les valeurs eva d'origine sont sauvegardées et relues avant modification.
# Le secret SearXNG est généré SUR LE SERVEUR et n'est jamais affiché.
# Retour arrière : bash retour-arriere.sh <dossier de sauvegarde affiché à l'étape 7>
set -euo pipefail
cd "$(dirname "$0")"

SSH=(ssh -o UserKnownHostsFile="$HOME/.ssh/known_hosts_workspace4" -o StrictHostKeyChecking=yes -o BatchMode=yes ubuntu@192.168.1.99)
# RAPPEL : toute commande « exec -T » reçoit </dev/null (ou une entrée explicite), sinon elle avale le script.
DC='cd /home/ubuntu/docker && sudo docker compose --env-file .env exec -T'
OCC="$DC -u www-data app php occ"
DS='cd /srv/searxng && sudo docker compose -p searxng'
DIR=/srv/searxng
RESEAU=nextcloud_frontend
URL_EVA=http://searxng:8080
IMAGE=$(sed -n 's/^ *image: *//p' compose.yaml | head -n 1)
h() { shasum -a 256 | cut -c1-64; }

echo "Ce script va : créer $DIR, lancer le conteneur SearXNG ($IMAGE) sans port publié,"
echo "le tester depuis le conteneur app, puis passer eva_ai sur web_search_provider=searxng ($URL_EVA)."
printf 'Taper OUI pour continuer : '
read -r REPONSE
[ "$REPONSE" = OUI ] || { echo "Abandon, rien n'a été fait."; exit 1; }

echo "0) vérifications préalables"
[ -f compose.yaml ] && [ -f settings.yml ] || { echo "ARRET : compose.yaml ou settings.yml absent en local"; exit 1; }
[ -n "$IMAGE" ] || { echo "ARRET : image introuvable dans compose.yaml"; exit 1; }
[ "$("${SSH[@]}" hostname </dev/null)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
"${SSH[@]}" "command -v openssl >/dev/null && sudo docker compose version >/dev/null" </dev/null \
  || { echo "ARRET : openssl ou docker compose absent sur le serveur"; exit 1; }
"${SSH[@]}" "test ! -e $DIR" </dev/null \
  || { echo "ARRET : $DIR existe déjà — rien n'a été modifié (le supprimer demande une décision séparée)"; exit 1; }
[ -z "$("${SSH[@]}" "sudo docker ps -a -q --filter label=com.docker.compose.project=searxng" </dev/null)" ] \
  || { echo "ARRET : un conteneur du projet Compose searxng existe déjà — rien n'a été modifié"; exit 1; }
"${SSH[@]}" "sudo docker network inspect $RESEAU >/dev/null" </dev/null \
  || { echo "ARRET : réseau Docker $RESEAU introuvable (lister : sudo docker network ls) — rien n'a été modifié"; exit 1; }
APPID=$("${SSH[@]}" "cd /home/ubuntu/docker && sudo docker compose --env-file .env ps -q app" </dev/null)
[ -n "$APPID" ] || { echo "ARRET : conteneur app de Nextcloud introuvable"; exit 1; }
"${SSH[@]}" "sudo docker inspect -f '{{json .NetworkSettings.Networks}}' $APPID" </dev/null | grep -q "\"$RESEAU\"" \
  || { echo "ARRET : le conteneur app n'est pas sur $RESEAU — SearXNG ne lui serait pas joignable"; exit 1; }
echo "   hôte workspace4, $DIR libre, réseau $RESEAU OK (conteneur app rattaché)"
echo "   eva_ai actuel : web_search_enabled=$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_enabled" </dev/null || echo '(absent)')"

echo "1) copie des fichiers dans $DIR"
"${SSH[@]}" "sudo mkdir -m 755 $DIR && sudo mkdir -m 750 $DIR/config $DIR/cache" </dev/null
"${SSH[@]}" "sudo tee $DIR/compose.yaml >/dev/null" < compose.yaml
"${SSH[@]}" "sudo tee $DIR/config/settings.yml >/dev/null" < settings.yml
[ "$("${SSH[@]}" "sudo sha256sum $DIR/compose.yaml" </dev/null | cut -c1-64)" = "$(h < compose.yaml)" ] \
  || { echo "ARRET : copie de compose.yaml corrompue"; exit 1; }
[ "$("${SSH[@]}" "sudo sha256sum $DIR/config/settings.yml" </dev/null | cut -c1-64)" = "$(h < settings.yml)" ] \
  || { echo "ARRET : copie de settings.yml corrompue"; exit 1; }
echo "   compose.yaml et config/settings.yml copiés, empreintes vérifiées"

echo "2) image et utilisateur du conteneur"
"${SSH[@]}" "sudo docker pull -q $IMAGE" </dev/null
echo "   empreinte : $("${SSH[@]}" "sudo docker image inspect -f '{{index .RepoDigests 0}}' $IMAGE" </dev/null)"
UG=$("${SSH[@]}" "sudo docker run --rm --network none --entrypoint id $IMAGE -u searxng && sudo docker run --rm --network none --entrypoint id $IMAGE -g searxng" </dev/null | tr '\n' ':')
[ "$UG" = "977:977:" ] || { echo "ARRET : l'utilisateur searxng de l'image n'est pas 977:977 (obtenu $UG) — corriger « user: » dans compose.yaml"; exit 1; }
"${SSH[@]}" "sudo chown -R 977:977 $DIR/config $DIR/cache && sudo chmod 640 $DIR/config/settings.yml" </dev/null
echo "   utilisateur searxng = 977:977, droits posés sur config/ et cache/"

echo "3) génération du secret SUR LE SERVEUR (jamais affiché)"
"${SSH[@]}" "sudo sh -s" <<'EOF'
set -eu
umask 077
[ ! -e /srv/searxng/secret.env ] || { echo "ARRET : secret.env existe déjà"; exit 1; }
printf 'SEARXNG_SECRET=%s\n' "$(openssl rand -hex 32)" > /srv/searxng/secret.env
chown root:root /srv/searxng/secret.env
chmod 600 /srv/searxng/secret.env
EOF
[ "$("${SSH[@]}" "sudo grep -cE '^SEARXNG_SECRET=[0-9a-f]{64}\$' $DIR/secret.env" </dev/null)" = 1 ] \
  || { echo "ARRET : secret.env mal formé"; exit 1; }
echo "   $DIR/secret.env créé (root, 600, 64 caractères hexadécimaux)"

echo "4) démarrage : docker compose -p searxng up -d"
"${SSH[@]}" "$DS config -q" </dev/null || { echo "ARRET : compose.yaml refusé par docker compose"; exit 1; }
"${SSH[@]}" "$DS up -d" </dev/null

echo "5) attente de l'état sain (jusqu'à ~3 min)"
CID=$("${SSH[@]}" "$DS ps -q searxng" </dev/null)
[ -n "$CID" ] || { echo "ARRET : conteneur searxng introuvable après up -d"; exit 1; }
ETAT=inconnu
for _ in $(seq 1 36); do
  ETAT=$("${SSH[@]}" "sudo docker inspect -f '{{.State.Health.Status}}' $CID" </dev/null || echo inconnu)
  [ "$ETAT" = healthy ] && break
  sleep 5
done
if [ "$ETAT" != healthy ]; then
  echo "ARRET : SearXNG n'est pas sain (état : $ETAT). Dernières lignes du journal :"
  "${SSH[@]}" "$DS logs --tail 40 searxng" </dev/null || true
  echo "eva n'a PAS été modifié. Arrêter le conteneur : bash retour-arriere.sh --conteneur-seulement"
  exit 1
fi
[ -z "$("${SSH[@]}" "sudo docker port $CID" </dev/null)" ] || { echo "ALERTE : un port est publié sur l'hôte — ne devrait pas"; exit 1; }
echo "   sain, aucun port publié sur l'hôte"

echo "6) test depuis le conteneur app de Nextcloud (même requête qu'eva)"
"${SSH[@]}" "$DC -u www-data app php" <<'PHP' || { echo "ARRET : le test a échoué — eva n'a PAS été modifié. Arrêter le conteneur : bash retour-arriere.sh --conteneur-seulement"; exit 1; }
<?php
$u = 'http://searxng:8080/search?' . http_build_query(['q' => 'dernière version de Nextcloud', 'format' => 'json', 'language' => 'all', 'safesearch' => 1]);
$ch = curl_init($u);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => '']);
$b = curl_exec($ch);
$c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($b === false) { fwrite(STDERR, "   SearXNG injoignable depuis app : " . curl_error($ch) . "\n"); exit(2); }
$j = json_decode($b, true);
if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) { fwrite(STDERR, "   HTTP $c, réponse non JSON (format json activé ?)\n"); exit(3); }
echo "   HTTP $c, " . count($j['results']) . " résultats\n";
foreach (array_slice($j['results'], 0, 3) as $i => $r) {
    printf("   %d. %s\n      %s  [%s]\n", $i + 1, $r['title'] ?? '', $r['url'] ?? '', implode(',', $r['engines'] ?? []));
}
if (!empty($j['unresponsive_engines'])) {
    echo "   moteurs sans réponse :";
    foreach ($j['unresponsive_engines'] as $e) { echo ' [' . implode(' : ', (array)$e) . ']'; }
    echo "\n";
}
exit(count($j['results']) > 0 ? 0 : 4);
PHP

echo "7) sauvegarde des réglages eva actuels"
B=/srv/sauvegarde-eva_ai/avant-searxng-$(date +%Y%m%d-%H%M%S)
"${SSH[@]}" "sudo mkdir -p -m 700 /srv/sauvegarde-eva_ai && sudo mkdir -m 700 $B" </dev/null
for k in web_search_provider web_search_url; do
  # occ config:app:get : code 1 = clé absente ; tout autre échec (ssh, docker) arrête le script.
  rc=0
  v=$("${SSH[@]}" "$OCC config:app:get eva_ai $k" </dev/null) || rc=$?
  case $rc in
    0) ;;
    1) v=__ABSENT__ ;;
    *) echo "ARRET : lecture de $k impossible (code $rc) — eva n'a PAS été modifié"; exit 1 ;;
  esac
  printf '%s\n' "$v" | "${SSH[@]}" "sudo tee $B/$k >/dev/null"
  [ "$("${SSH[@]}" "sudo cat $B/$k" </dev/null)" = "$v" ] || { echo "ARRET : sauvegarde de $k incomplète — eva n'a PAS été modifié"; exit 1; }
  echo "   $k = $v"
done
echo "   sauvegardé dans $B"

echo "8) réglage d'eva : web_search_url puis web_search_provider"
"${SSH[@]}" "$OCC config:app:set eva_ai web_search_url --value=$URL_EVA" </dev/null
"${SSH[@]}" "$OCC config:app:set eva_ai web_search_provider --value=searxng" </dev/null
[ "$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_url" </dev/null)" = "$URL_EVA" ] \
  && [ "$("${SSH[@]}" "$OCC config:app:get eva_ai web_search_provider" </dev/null)" = searxng ] \
  || { echo "ALERTE : relecture inattendue — lancer : bash retour-arriere.sh $B"; exit 1; }
echo "   eva_ai : web_search_provider=searxng, web_search_url=$URL_EVA"

echo
echo "INSTALLÉ : SearXNG ($IMAGE) sur workspace4, utilisé par eva_ai."
echo "Vérifier dans eva : poser « Quelle est la dernière version de Nextcloud ? » (recherche web activée)."
echo "Retour arrière si besoin : bash retour-arriere.sh $B"
