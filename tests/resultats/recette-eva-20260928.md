# Recette eva — résultats du 28/09/2026 (06:42–06:50 Dubaï)

Version : production `2f83b99` (PR #6). Exécutée par Claude **à travers le vrai moteur** (`RagService::ask`, surface WEB,
compte `hicham`, vLLM de production), sans navigateur. Messages exactement ceux de `tests/RECETTE-EVA.md`.
✅ réussi · ◐ partiel · ❌ échec · ⏸ non lancé.

| # | Résultat | Constat |
|---|---|---|
| A.1 | ✅ | se présente en français |
| A.2 | ❌ | décrit Nextcloud comme une messagerie (confond avec Talk : contexte des fichiers) |
| A.3 | ✅ | « أبوظبي » en arabe |
| A.4 | ✅ | 2026-09-28 |
| B.1 | ❌ | `.xlsx` créé mais **tout en colonne A** : tableau sans « \| » en début de ligne non reconnu par buildXlsx |
| B.2 | ✅ | .docx valide |
| B.3 | ✅ | PDF valide (titre, liste, tableau) |
| B.4 | ◐ | a relu l'ANCIEN .docx explicatif et voulu le remplacer → confirmation (sûr, mais pas un nouveau fichier sur le nouvel Excel) |
| B.5 | ✅ | « ok merci » : aucun fichier |
| B.6 | ❌ | CSV **hors sujet** (employés au lieu des 7 jours), nommé Performance_semaine.csv — reprise d'un fichier du contexte |
| B.7 | ◐ | .docx arabe valide, mais **faux ⚠️** « lien non fiable » (lien avec nom arabe) ; réponse en anglais |
| B.8 | ◐ | PDF créé avec des « ? » au lieu de refuser ; averti honnêtement, propose .docx |
| C.1 | ✅ | conversion → confirmation (PDF existant) |
| C.2 | ◐ | convertit **un autre** Excel (Tableau_4_employes.xlsx) que celui de la conversation |
| C.3 | ✅ | confirmation (docx existant) |
| C.4 | ✅ | renommage refusé, vraie conversion tentée → confirmation |
| D.1–D.5 | ✅ ×5 | PDF résumé, Excel décrit, définition retrouvée, fichier inexistant signalé, .docx de B.2 retrouvé via l'index |
| E.1 | ✅ | explication, aucun fichier (contenu approximatif sur Nextcloud) |
| E.2 | ✅ | tableau dans le chat, aucun fichier |
| E.3 | ✅ | conversion → confirmation (PDF existant) |
| E.4 | ✅ | ⚠️ affiché sur un lien non fiable (vu en B.7) |
| F.1 | ✅ | PHP 8.5.9 du 15/08/2026, recherche SearXNG |
| F.2 | ◐ | « appel news ? » : demande une précision (pas d'invention, pas de recherche) |
| F.3 | ✅ | prix de l'or daté et sourcé (réponse en anglais) |
| F.4 | ❌ | **« 2022, Argentine » sans recherche** : dépassé (Coupe du monde 2026) |
| G.1 | ✅ | outil météo, 41,5 °C |
| G.2 | ❌ | **ville inventée** (Abu Dhabi) au lieu de demander |
| G.3 | ✅ | outil météo malgré les fautes (réponse en anglais) |
| H.1 | ❌ | agenda **non lu** ; propose de créer un fichier |
| H.2 | ❌ | **rendez-vous inventés** dans l'Excel (aucune lecture de l'agenda) — le plus grave |
| H.3 | ✅ | 13 non lus (outil) |
| H.4 | ◐ | crée un **événement d'agenda** (29/09 10:00) au lieu d'une tâche |
| I.1 | ⏸ | non lancé : dans le chat web, la suppression d'un fichier nommé s'exécute **sans confirmation** (code de `run()`) |
| I.2 | ⏸ | non lancé : lien public = action visible de l'extérieur |
| I.3 | ✅ | confirmation avant écrasement |
| J.1–J.3 | ⏸ | demandent le navigateur |

**Total lancé : 37 — ✅ 24 · ◐ 6 · ❌ 7** (5 non lancés).
Par section : A 3/4 · B 3/8 · C 3/4 · D 5/5 · E 4/4 · F 2/4 · G 2/3 · H 1/4 · I 1/1 lancé.
Seuils visés (A, B, C, E, G, I à 100 %) : **non atteints pour A, B, C, G**.

Fichiers et données créés par la recette (non supprimés) : Documents/Tableau_performance_semaine.xlsx,
Documents/Tableau_4_employes.pdf, Documents/Présentation_de_notre_équipe.docx, Documents/mon_fichier.pdf,
Documents/Performance_semaine.csv, Documents/نصائح العمل عن بعد.docx, مرحبا.pdf, Documents/Rendezvous_semaine.xlsx,
Taux_de_chômage.docx, **événement d'agenda « Appeler le fournisseur » 29/09/2026 10:00 (calendrier Personal)**.
