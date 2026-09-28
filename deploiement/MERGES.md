# Merges GitHub — PR #1 → #2 → #3 → #4

> Préparé le 28/09/2026. **Rien n'a été mergé, poussé, supprimé ni modifié.** Toutes les commandes
> ci-dessous sont à lancer **par l'admin**, depuis le Mac, dans ce dépôt, une par une.
> Dépôt réel : `github.com/sadikihicham/eva-ai-corrections` (le remote `origin` pointe là, pas vers
> `eva-corrections`).

## 0. État constaté (après `git fetch`)

| PR | head → base | Tête | Mergeable | Checks | Commits propres (`base...head`) |
|---|---|---|---|---|---|
| #1 | `corrige-outils-vllm` → `master` | `f2563b2` | MERGEABLE / CLEAN | aucun (pas de CI) | 5 (`05c7279`…`f2563b2`), 0 côté base |
| #2 | `liens-fichiers-crees` → `corrige-outils-vllm` | `f236b1a` | MERGEABLE / CLEAN | aucun | 4 (`5331dec`…`f236b1a`), 0 côté base |
| #3 | `anti-invention` → `liens-fichiers-crees` | `73c3600` | MERGEABLE / CLEAN | aucun | 20 (`eaa2288`…`73c3600`), 0 côté base | — ⚠️ depuis la simulation : + `48401b6` (PROBLEMES.md §9), tête à relire avant merge
| #4 | `outils-searxng-style` → `master` | `7b16e19` | UNKNOWN (non calculé par GitHub) | aucun | 2 (`3656ae3`, `7b16e19`), 0 côté base |

Refs interrogées : `origin/master` = `47ee308`. Branches locales = distantes pour les 5 refs.
Chaîne d'ascendance vérifiée : `master ⊂ corrige-outils-vllm ⊂ liens-fichiers-crees ⊂ anti-invention`.
Réglages du dépôt : `deleteBranchOnMerge = false`, merge commit autorisé, `master` non protégée.

## 1. Simulation (`git merge-tree --write-tree`, merge commit)

| Étape | Résultat | Arbre obtenu | Contrôle |
|---|---|---|---|
| #1 dans `master` | sans conflit | `fcb9cd7` | = arbre de `f2563b2` |
| #2 dans master+#1 (base `f2563b2`) | sans conflit | `ec63bf6` | = arbre de `f236b1a` |
| #3 dans master+#2 (base `f236b1a`) | sans conflit | `d2abf61` | = arbre de `73c3600` |
| #4 dans master+#3 (base `47ee308`) | sans conflit | `d8f65e5` | ajoute uniquement 9 fichiers `searxng/` + `style/` |

**#4 vs #1-#3 : aucun fichier commun.** #1-#3 touchent `PROBLEMES.md`, `README.md`, `.gitignore`,
`deploiement/`, `src/`, `tests/` ; #4 touche seulement `searxng/` et `style/`. #4 peut être mergée
avant, après ou entre les autres.

## 2. Prérequis (bloquants)

1. **Test admin validé** sur eva pour ce qui est en production (anti-invention / PDF / liens) — aucune
   PR ne porte de mention « test admin validé » ; c'est à l'admin de le déclarer.
2. **`PROBLEMES.md` modifié non commité** (+69 lignes) dans ce dossier : le commiter sur la bonne
   branche (ou l'écarter) **avant** de merger, sinon il n'est pas dans master.
3. `git fetch origin` puis vérifier que les têtes sont toujours celles du tableau §0
   (`git rev-parse --short origin/<branche>`). Si une tête a bougé, refaire la simulation.

## 3. Pourquoi re-cibler à la main

GitHub ne re-cible une PR empilée vers `master` **que si sa branche de base est supprimée**. On ne
supprime aucune branche (et `deleteBranchOnMerge = false`) : après le merge de #1, la PR #2 resterait
basée sur `corrige-outils-vllm` et un merge l'enverrait **dans cette branche, pas dans master**.
Il faut donc `gh pr edit … --base master` **avant** chaque merge suivant. Merge commit (pas squash,
pas rebase) : les commits d'origine restent dans master, la PR suivante ne montre que ses propres commits.

## 4. Commandes, dans l'ordre

```bash
cd "/Users/sadiki/Documents/Projects/Migration workspace/eva-corrections"
git fetch origin

# --- #1
gh pr merge 1 --merge
git fetch origin
git merge-base --is-ancestor f2563b2 origin/master && echo "OK #1 dans master"
git diff --stat origin/master origin/corrige-outils-vllm -- src/      # attendu : vide

# --- #2
gh pr edit 2 --base master
gh pr view 2 --json baseRefName,mergeable,commits --jq '.baseRefName, .mergeable, (.commits|length)'   # master, MERGEABLE, 4
gh pr merge 2 --merge
git fetch origin
git merge-base --is-ancestor f236b1a origin/master && echo "OK #2 dans master"
git diff --stat origin/master origin/liens-fichiers-crees -- src/     # attendu : vide

# --- #3
gh pr edit 3 --base master
gh pr view 3 --json baseRefName,mergeable,commits --jq '.baseRefName, .mergeable, (.commits|length)'   # master, MERGEABLE, 21 (ou plus : la branche a reçu des commits depuis la simulation)
gh pr merge 3 --merge
git fetch origin
git merge-base --is-ancestor "$(git rev-parse origin/anti-invention)" origin/master && echo "OK #3 dans master"
git diff --stat origin/master origin/anti-invention -- src/           # attendu : vide

# --- #4 (déjà basée sur master)
gh pr view 4 --json mergeable --jq .mergeable                          # attendu : MERGEABLE
gh pr merge 4 --merge
git fetch origin
git merge-base --is-ancestor 7b16e19 origin/master && echo "OK #4 dans master"
git diff --stat origin/master origin/outils-searxng-style -- searxng/ style/   # attendu : vide
git diff --stat origin/master origin/anti-invention -- src/                    # toujours vide
```

Si un contrôle échoue : **s'arrêter**, ne pas merger la suivante.
Le worktree `../eva-corrections-extras` (branche `outils-searxng-style`) n'a pas à être touché.

## 5. Retour arrière

Jamais de force-push sur master. Annuler un merge par une PR de revert :

```bash
git fetch origin
git log --merges --oneline origin/master -5          # repérer le merge commit <merge> à annuler
git switch -c revert-pr-N origin/master
git revert -m 1 <merge>                             # -m 1 = garder le côté master
git push -u origin revert-pr-N
gh pr create --base master --title "revert PR #N" --body "Annule le merge <merge>"
```

Annuler dans l'ordre inverse (#3 avant #2 avant #1) si plusieurs merges sont en cause. Ce revert ne
touche que le dépôt : le code en production sur le serveur se restaure avec les scripts
`deploiement/retour-arriere*.sh`, séparément. Piège connu : re-merger plus tard une branche dont le
merge a été reverté n'apporte rien — il faut alors « revert du revert ».

## 6. Reste à décider (admin)

- **Suppression des branches** `corrige-outils-vllm`, `liens-fichiers-crees`, `anti-invention`,
  `outils-searxng-style` : uniquement sur **ordre nommé** de l'admin, branche par branche, après les
  4 merges (supprimer `corrige-outils-vllm` avant d'avoir re-ciblé #2 fermerait/re-ciblerait #2 d'office).
- Sort de `PROBLEMES.md` non commité (§2.2).
- Mettre une CI minimale (aucun check sur les 4 PR aujourd'hui).

## 7. Merges et déploiement du 28/09 (mode autonome, GO admin « merge, pousse et déploie »)

| PR | Contenu | Merge |
|---|---|---|
| #6 | convert_file, confirmation d'écrasement, lecture des PDF (déjà en production 2f83b99) | 54df298 |
| #5 | recette fonctionnelle (42 tests) + résultats du 28/09 | fdfdaad |
| #7 | corrections de la recette : données perso, suppressions confirmées, xlsx, recherche/météo | fac9497 |

- **Production = 6d7455e** (fichiers `src/` identiques à fac9497), déployée le 28/09 à 11:24 par `appliquer-eva.sh`.
- Sauvegarde : `/srv/sauvegarde-eva_ai/avant-eva-20260928-112356` (état 2f83b99).
  Retour arrière : `bash deploiement/retour-arriere-eva.sh /srv/sauvegarde-eva_ai/avant-eva-20260928-112356`.
- Vérifié en production (vrai vLLM) : H.1 ✅ lit l'agenda · H.2 ◐ (écriture inventée refusée, agenda lu, mais le
  fichier n'est pas créé ensuite) · B.1 ✅ colonnes · F.4 ✅ recherche web · G.2 ◐ (plus de ville inventée, mais
  ne demande pas la ville) · I.1 ✅ garde vérifiée directement (web = confirmation, autonome = refus) · dépannage
  « mon email ne marche plus » ✅ sans lecture de la boîte.
- Branches `recette-eva`, `conversion-et-ecrasement`, `fix-recette-*`, `corrections-recette` : gardées (suppression
  sur ordre nommé seulement).

## 8. H.2 et G.2 (28/09, GO admin « merge et déploie après la revue »)

| PR | Contenu | Déployé |
|---|---|---|
| #9 | H.2 : écrire le fichier après lecture de l'agenda ; G.2 : relance météo tant que la ville n'est pas demandée | d79e030 à 12:01 (sauvegarde `avant-eva-20260928-120114`) |
| #10 | G.2 : une ville des extraits de fichiers du RAG ne vaut plus un lieu donné par l'utilisateur | 4fe6752 à 12:08 (sauvegarde `avant-eva-20260928-120741`) |

- **Production = 4fe6752.** Retour arrière : `bash deploiement/retour-arriere-eva.sh /srv/sauvegarde-eva_ai/avant-eva-20260928-120741`.
- Vérifié en production (vrai vLLM) : H.2 ✅ (garde → lecture de l'agenda → create_file avec les vrais rendez-vous ;
  fichier existant → dialogue d'écrasement) · G.2 ✅ (demande la ville ; « Dubaï » → météo réelle) · G.1, H.1,
  poème sur la pluie, dépannage mail : sans régression.
