# Contrat de la dictée (bouton micro d'Infinity AI) — v2.2, 28/09/2026

Remplace la v1 (route audio côté serveur, ABANDONNÉE). Toute modification = décision de l'intégrateur.

## Décisions admin (28/09)
- **L'audio ne quitte JAMAIS l'ordinateur de l'utilisateur.** Seul le texte transcrit va à Infinity AI,
  et seulement quand l'utilisateur l'envoie lui-même.
- Transcription par Whisper installé **sur l'ordinateur de l'utilisateur** (test : le Mac de l'admin).
  Les autres postes n'ont rien : le bouton n'apparaît simplement pas chez eux.
- Langue de la voix : **détection automatique SEULE**, aucun choix manuel (décision admin, v2.1).
- **Pas de changement de la langue de l'interface** (bascule automatique essayée puis ARRÊTÉE par l'admin,
  v2.2) : seule la zone de saisie prend le sens d'écriture de la langue parlée (rtl pour l'arabe).
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
- Toujours `verbose_json` sans `language` ; texte = `segments[].text` recollés SANS séparateur (un segment
  peut commencer au milieu d'un mot arabe, mesuré).
- Si la langue détectée ∉ {french, arabic, english} ⇒ **UN SEUL** second appel avec `language=` la dernière
  langue bien détectée pendant la session, sinon la langue de l'interface (fr/ar/en), sinon `fr`.
  Pas de seuil de probabilité pour ce repli (mesuré : un seuil casse l'arabe court).
- Silence / `[BLANK_AUDIO]` testé AVANT le repli (pas de second appel sur un silence). (Mesuré : la détection a rendu « islandais » sur une vraie dictée courte ;
  imposer une MAUVAISE langue produit du charabia : on n'impose donc une langue qu'au repli.)
- Réponse `[BLANK_AUDIO]` / vide ⇒ message « rien d'audible », rien inséré.

## Serveur eva_ai (PageController seulement)
- Deux interrupteurs, tous deux requis (revue sécurité I-1 : sinon TOUS les navigateurs sondent localhost) :
  - `occ config:app:set eva_ai dictation_local_url --value=http://127.0.0.1:8178/v1` (préférer 127.0.0.1 à
    localhost : pas de résolution de nom, pas de `::1`) ;
  - `occ user:setting <uid> eva_ai dictation_enabled yes` (par utilisateur ayant Whisper sur son poste).
  Vide/absent/erreur de lecture ⇒ fonction ÉTEINTE (rien chargé, jamais d'erreur 500).
- **Garde-fou** : l'URL n'est acceptée que si son hôte est exactement `localhost` ou `127.0.0.1`
  (schéma http/https, port facultatif, pas d'utilisateur/mot de passe). Toute autre valeur ⇒ éteinte.
  C'est ce qui garantit que la page ne peut pas envoyer l'audio ailleurs que sur la machine de l'utilisateur.
- Si valide, sur les 2 pages (app et standalone) : `Util::addScript('eva_ai', 'micro')`,
  meta `eva-ai-dictation` = l'URL, et CSP `addAllowedConnectDomain(<schéma://hôte:port>)`.

## Interface (`app/js/micro.js`, fichier NEUF)
- Lit `<meta name="eva-ai-dictation">` ; absent ⇒ ne fait rien.
- `disponible()` : `GET <origine>/` (délai 2 s) au chargement puis toutes les 60 s ; échec ⇒ **aucun bouton**
  (poste sans Whisper) ; redevenu joignable ⇒ bouton affiché.
- Bouton micro à côté du bouton d'envoi (pas de menu de langue) ; `MutationObserver`.
- `transcrire(wav, {signal})` : seule fonction qui parle à Whisper.
- Enregistrement quasi muet (aucune fenêtre de 20 ms au-dessus de -40 dBFS) ⇒ « rien d'audible », Whisper
  NON appelé (mesuré : sur 40 s de silence, Whisper invente « Thank you. Thank you. »).
- Insertion au curseur + événement `input` ; textes fr/en/ar/de/ur ; RTL ; accessibilité ; Échap = annuler ;
  compteur 2 min. Jamais de texte dicté dans la console. Jamais d'appel à un autre hôte que celui de la meta.

## Limite assumée
- L'invariant « l'audio reste sur le poste » tient contre la CONFIGURATION (garde-fous serveur + navigateur +
  CSP), pas contre du code hostile déjà présent dans la page (il pourrait appeler getUserMedia lui-même).
- whisper-server répond `CORS *` : un site ouvert sur le poste peut l'utiliser (pas lire les dictées).
  Durcissement possible : proxy local qui n'accepte que l'origine Nextcloud (à décider par l'admin).

## Prérequis navigateur (hors code)
- Micro = contexte sécurisé : **HTTPS obligatoire** pour la page Nextcloud (phase 1 HTTPS). Avant cela,
  test possible sur le seul Mac de l'admin avec le drapeau Chrome
  `chrome://flags/#unsafely-treat-insecure-origin-as-secure` = `http://192.168.1.99`.
- **[non vérifié]** Page HTTPS → `http://localhost` : autorisé par Chrome/Firefox (localhost « de confiance ») ;
  Safari à tester. Chrome récent peut demander l'autorisation « appareils du réseau local » : à tester.
