#!/usr/bin/env bash
# Force les navigateurs à recharger les JS/CSS de Nextcloud après un déploiement d'eva_ai.
#
# Pourquoi : Nextcloud sert les bundles avec « Cache-Control: public, max-age=15778463, immutable »
# (6 mois) et une adresse js/eva_ai-main.js?v=<md5(version de l'app)>-<theming cachebuster>. Un
# déploiement qui ne change pas la version de l'app ne change pas l'adresse : les navigateurs
# gardent l'ancien code jusqu'à 6 mois (cause des « correctif non appliqué » de fin septembre).
# Incrémenter `theming cachebuster` change le suffixe pour TOUS les fichiers (lib/private/
# TemplateLayout.php, getVersionHashSuffix) : chaque navigateur retélécharge une fois. C'est
# exactement ce que fait Nextcloud quand on modifie le thème — sans autre effet.
#
# À lancer par l'admin, SUR LE MAC, APRÈS un appliquer-*.sh qui a touché js/ ou css :
#     ESSAI=1 bash deploiement/vider-cache-navigateurs.sh   → affiche la valeur actuelle, n'écrit rien
#     bash deploiement/vider-cache-navigateurs.sh           → cachebuster = actuel + 1
# Retour arrière : inutile (revenir à l'ancienne valeur ferait re-servir d'anciennes adresses
# encore en cache ; on ne fait qu'avancer).
set -euo pipefail
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 ubuntu@192.168.1.99)
r() { "${SSH[@]}" "$@" </dev/null; }
OCC='sudo docker exec -u www-data nextcloud-app-1 php occ'

[ "$(r hostname)" = workspace4 ] || { echo "ARRET : l'hôte distant n'est pas workspace4"; exit 1; }
AVANT=$(r "$OCC config:app:get theming cachebuster" | tr -d '[:space:]')
case "$AVANT" in ''|*[!0-9]*) echo "ARRET : cachebuster actuel inattendu (« $AVANT »)"; exit 1 ;; esac
APRES=$((AVANT + 1))
echo "theming cachebuster : $AVANT -> $APRES"
[ "${ESSAI:-0}" = 1 ] && { echo "ESSAI=1 : arrêt avant toute écriture."; exit 0; }
r "$OCC config:app:set theming cachebuster --value=$APRES" >/dev/null
LU=$(r "$OCC config:app:get theming cachebuster" | tr -d '[:space:]')
[ "$LU" = "$APRES" ] || { echo "ÉCHEC : relu « $LU » au lieu de $APRES"; exit 1; }
echo "OK : cachebuster = $LU. Les pages Nextcloud servent désormais ?v=…-$LU ; chaque navigateur"
echo "     retélécharge les JS/CSS au prochain chargement (le 1er chargement de l'app est plus lent)."
