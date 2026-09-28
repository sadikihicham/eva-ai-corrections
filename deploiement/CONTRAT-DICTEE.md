# Contrat de la dictée (bouton micro d'Infinity AI) — v2, 28/09/2026

Remplace la v1 (route audio côté serveur, ABANDONNÉE). Toute modification = décision de l'intégrateur.

## Décisions admin (28/09)
- **L'audio ne quitte JAMAIS l'ordinateur de l'utilisateur.** Seul le texte transcrit va à Infinity AI,
  et seulement quand l'utilisateur l'envoie lui-même.
- Transcription par Whisper installé **sur l'ordinateur de l'utilisateur** (test : le Mac de l'admin).
  Les autres postes n'ont rien : le bouton n'apparaît simplement pas chez eux.
- Langue de la voix : **« Automatique » par défaut + choix manuel** (fr / ar / en), mémorisé.
- Le texte est inséré dans la **zone de saisie** ; l'utilisateur relit et envoie. Jamais d'envoi automatique.
- Enregistrement : **120 s maximum**.

## Chemin des données
navigateur (page Infinity AI) → `http://localhost:8178/v1/audio/transcriptions` (Whisper local)
→ texte → zone de saisie → (envoi manuel par l'utilisateur) → Nextcloud.
Aucune route audio dans eva_ai. Le serveur ne reçoit que le message tapé/dicté, comme aujourd'hui.

## Whisper local (déjà en place sur le Mac, ne pas modifier)
- whisper.cpp `whisper-server` 1.9.4, large-v3-turbo, `-l auto`, écoute **127.0.0.1:8178** seulement.
- `POST /v1/audio/transcriptions` multipart : `file` (WAV PCM 16 bits mono 16 kHz), `response_format`
  (`json` → `{text}` ; `verbose_json` → `{text, language}` avec langue en NOM ANGLAIS COMPLET : "arabic"),
  `language` facultatif (code ISO : `fr`, `ar`, `en` — vérifié : impose la langue).
- CORS : répond `Access-Control-Allow-Origin: *` (vérifié). Pas d'en-tête Private-Network.
- `GET /` → 200 (sert à tester la disponibilité).

## Règle de langue (faite dans le NAVIGATEUR)
- Choix manuel fr/ar/en ⇒ envoyer `language=<code>` (un seul appel).
- « Automatique » ⇒ appel en `verbose_json` sans `language` ; si la langue détectée ∉ {french, arabic,
  english} ⇒ **UN SEUL** second appel avec `language=<langue de l'interface Nextcloud, 2 lettres>` si elle
  est fr/ar/en, sinon `fr`. (Mesuré : la détection a rendu « islandais » sur une vraie dictée courte ;
  imposer une MAUVAISE langue produit du charabia, d'où le choix manuel.)
- Réponse `[BLANK_AUDIO]` / vide ⇒ message « rien d'audible », rien inséré.

## Serveur eva_ai (PageController seulement)
- Réglage app `eva_ai` / `dictation_local_url` (ex. `http://localhost:8178/v1`), posé par
  `occ config:app:set eva_ai dictation_local_url --value=…`. Vide/absent ⇒ fonction ÉTEINTE (rien chargé).
- **Garde-fou** : l'URL n'est acceptée que si son hôte est exactement `localhost` ou `127.0.0.1`
  (schéma http/https, port facultatif, pas d'utilisateur/mot de passe). Toute autre valeur ⇒ éteinte.
  C'est ce qui garantit que la page ne peut pas envoyer l'audio ailleurs que sur la machine de l'utilisateur.
- Si valide, sur les 2 pages (app et standalone) : `Util::addScript('eva_ai', 'micro')`,
  meta `eva-ai-dictation` = l'URL, et CSP `addAllowedConnectDomain(<schéma://hôte:port>)`.

## Interface (`app/js/micro.js`, fichier NEUF)
- Lit `<meta name="eva-ai-dictation">` ; absent ⇒ ne fait rien.
- `disponible()` : `GET <origine>/` (délai 2 s) au chargement puis toutes les 60 s ; échec ⇒ **aucun bouton**
  (poste sans Whisper) ; redevenu joignable ⇒ bouton affiché.
- Bouton micro + menu langue (« Auto », FR, AR, EN) à côté du bouton d'envoi ; `MutationObserver`.
- `transcrire(wav, langue)` : seule fonction qui parle à Whisper.
- Insertion au curseur + événement `input` ; textes fr/en/ar/de/ur ; RTL ; accessibilité ; Échap = annuler ;
  compteur 2 min. Jamais de texte dicté dans la console. Jamais d'appel à un autre hôte que celui de la meta.

## Prérequis navigateur (hors code)
- Micro = contexte sécurisé : **HTTPS obligatoire** pour la page Nextcloud (phase 1 HTTPS). Avant cela,
  test possible sur le seul Mac de l'admin avec le drapeau Chrome
  `chrome://flags/#unsafely-treat-insecure-origin-as-secure` = `http://192.168.1.99`.
- **[non vérifié]** Page HTTPS → `http://localhost` : autorisé par Chrome/Firefox (localhost « de confiance ») ;
  Safari à tester. Chrome récent peut demander l'autorisation « appareils du réseau local » : à tester.
