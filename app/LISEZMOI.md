# app/ — fichiers de l'app eva_ai versionnés hors lib/Service

Chemins relatifs à la racine de l'app (`/var/www/html/custom_apps/eva_ai/`). `src/*.php` reste la copie de
`lib/Service/{ActionExecutor,RagService,ToolPolicy}.php`. `deploiement/appliquer-eva.sh` déploie les deux.

- Commit « état de production » : copie exacte des fichiers de production du 28/09 (eva_ai 1.16.96), sert de
  référence au contrôle de dérive et à la sauvegarde.
- Renommage « Infinity AI » : `../eva-corrections-outils/renommer.py` (textes affichés seulement).
- `js/` = bundles compilés (pas de sources Vue ici) : toute mise à jour officielle d'eva_ai les écrase.
