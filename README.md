# Corrections eva_ai — fournisseur compatible OpenAI (vLLM)

> Dépôt local créé le 28/09/2026 pour préparer, tester et documenter deux corrections d'eva_ai 1.16.96,
> sans jamais modifier `workspace4` (192.168.1.99). Les fichiers dans `src/` sont des **copies fidèles**
> lues en lecture seule le 27–28/09/2026. `ActionExecutor.marksFile.excerpt.php` n'est qu'un **extrait**
> (31 lignes autour du défaut) : le fichier complet fait ~3000 lignes et n'a pas été rapatrié en entier.

## Ce que ce dépôt N'EST PAS
- Ce n'est pas une copie de l'app installée sur workspace4.
- Rien ici n'a été déployé sur un serveur. Toute application en production reste un geste séparé,
  avec confirmation explicite et sauvegarde des fichiers d'origine (voir « Déploiement » plus bas).

## Historique
- `main` : les fichiers tels que lus sur le serveur (« avant »).
- `corrige-outils-vllm` : les deux corrections, avec tests.

Voir `PROBLEMES.md` pour le diagnostic complet et `signalement-eva-editeur.md` (dans le dossier parent)
pour le texte à publier sur GitHub auprès de l'éditeur.
