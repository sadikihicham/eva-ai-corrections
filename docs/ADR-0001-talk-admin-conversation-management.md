# ADR-0001 : Gestion administrative des participants Talk

**Statut :** Proposé  
**Date :** 2026-10-16

## Contexte

Nextcloud Talk réserve l’ajout d’un groupe à un salon à un modérateur ou au propriétaire. Dans `INFINITY HOLDING`, le compte `admin` peut voir le salon mais ne peut pas le gérer ; dépendre du compte personnel d’un modérateur bloque l’intervention lorsqu’il n’a pas de mot de passe disponible.

## Décision proposée

Ajouter une app Nextcloud dédiée `talk_admin`, plutôt que modifier directement Talk, avec une action « Ajouter un groupe à un salon » :

1. réserver l’action aux comptes du groupe système `admin` ; jamais aux utilisateurs ordinaires ;
2. rechercher le salon par son jeton et le groupe par son identifiant exact ;
3. afficher un aperçu avec salon, groupe, nombre de membres et conséquence ;
4. demander une confirmation explicite juste avant l’écriture ;
5. utiliser une identité technique contrôlée côté serveur, sans demander ni réinitialiser le mot de passe d’un modérateur ;
6. journaliser l’administrateur, le salon, le groupe, le nombre de membres, le résultat et l’identifiant de requête, sans message ni secret.

L’app doit vérifier la version Talk au démarrage, refuser l’opération si son service interne est indisponible, ne jamais accéder directement aux tables Talk et ne jamais écrire de jeton dans les journaux.

## Tests d’acceptation

- un utilisateur hors `admin` reçoit `403` sans écriture ;
- l’aperçu puis l’annulation ne modifient rien ;
- l’ajout de `Reception` à `INFINITY HOLDING` conserve le groupe comme source ;
- une seconde tentative est idempotente ;
- chaque succès et échec est tracé dans l’audit ;
- l’essai est d’abord réalisé sur une conversation de test.

## Conséquences

La gestion devient traçable et ne dépend plus du mot de passe d’Oumaima. L’app devra être développée, testée avec la version Talk déployée, puis installée explicitement sur le serveur.
