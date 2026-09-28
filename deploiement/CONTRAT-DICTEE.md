# Contrat de la dictée (bouton micro d'Infinity AI) — v1, 28/09/2026

Figé AVANT le travail des agents. Toute modification = décision de l'intégrateur, jamais d'un agent seul.

## Décisions admin
- Whisper tourne sur le Mac de l'admin (TEST : pas pour tous les employés tant que non décidé).
- Enregistrement : **120 s maximum**. Langue : **détection automatique**.
- Mesuré le 28/09 sur les vraies dictées de l'admin : la détection se trompe sur des phrases courtes
  (« is » islandais p=0,58 ; « en » p=0,49 sur 1,5 s). Forcer une MAUVAISE langue produit du charabia
  (arabe forcé en « fr » → texte français inventé). D'où la règle de repli ci-dessous.

## Service Whisper (déjà en place, ne pas modifier)
- whisper.cpp `whisper-server` 1.9.4, modèle large-v3-turbo, `-l auto`, chemin OpenAI :
  `POST {dictation_url}/audio/transcriptions` (multipart : `file`, `response_format`, `language` facultatif).
- Test local : `dictation_url = http://127.0.0.1:8178/v1` (écoute 127.0.0.1 seulement).
- `response_format=verbose_json` renvoie `{"text": "...", "language": "arabic"|"french"|"english"|...}`
  (NOM ANGLAIS COMPLET, pas le code ISO). `json` renvoie `{"text": "..."}`.
- Pas de clé d'API.

## Réglages (app config `eva_ai`, admin seulement, posés par `occ config:app:set eva_ai …`)
| Clé | Exemple | Absent/vide ⇒ |
|---|---|---|
| `dictation_url` | `http://192.168.1.50:8178/v1` | dictée désactivée (`available:false`) |
| `dictation_enabled` | `yes` / `no` | `no` (désactivée) |
Indépendant du fournisseur de chat : ne JAMAIS réutiliser l'URL de `chat_provider` (vLLM).

## Routes (ajoutées à `appinfo/routes.php`, contrôleur NEUF `DictationController`)
Base : `/apps/eva_ai` (sous-chemin Nextcloud `/workspace` en production → `/workspace/apps/eva_ai/...`
côté navigateur ; le front lit la base dans `<meta name="eva-ai-api">` ou via `OC.generateUrl`).

### `GET /api/dictation/status`
- Utilisateur connecté (`#[NoAdminRequired]`), CSRF standard.
- Réponse 200 : `{"available": bool, "maxSeconds": 120}`.
- `available` = réglages présents ET `GET {dictation_url}/..` joignable (sonde HTTP, délai 3 s),
  résultat mis en cache **30 s** (ICacheFactory, clé par instance, pas par utilisateur).

### `POST /api/dictation`
- Utilisateur connecté, **jeton CSRF obligatoire** (en-tête `requesttoken`), limite de fréquence
  **20 requêtes / 10 min / utilisateur** (`#[UserRateLimit(limit: 20, period: 600)]`).
- Corps multipart : `audio` = fichier WAV **PCM 16 bits, mono, 16 000 Hz** ; `lang` = langue de
  l'interface (`fr|en|ar|de|ur`, facultatif, défaut `fr`).
- Contrôles serveur AVANT tout envoi : en-tête RIFF/WAVE valide, format 1 (PCM), 1 canal, 16 000 Hz,
  16 bits ; durée = octets de données / 32 000 ≤ **121 s** ; taille ≤ 4 000 000 octets.
- Envoi à Whisper : `response_format=verbose_json`, sans `language`. Si la langue détectée n'est pas
  dans {french, arabic, english, german, urdu} → **UN SEUL** nouvel essai avec `language=<lang>`.
  Délai 60 s par appel. Client HTTP Nextcloud avec `['nextcloud' => ['allow_local_address' => true]]`
  (**[non vérifié]** : à confirmer sur NC 34 ; sinon le réglage `allow_local_remote_servers`).
- Réponse 200 : `{"text": "…", "language": "fr"|"ar"|"en"|"de"|"ur"}` (code ISO 639-1).
  Texte vide ou `[BLANK_AUDIO]` → 200 avec `"text": ""`.
- Erreurs (JSON `{"error": "<code>"}`) :
  | HTTP | code | cas |
  |---|---|---|
  | 400 | `invalid_audio` | pas de fichier, pas un WAV PCM 16 k mono 16 bits |
  | 413 | `too_long` | > 121 s ou > 4 000 000 octets |
  | 429 | `rate_limited` | limite de fréquence (réponse Nextcloud standard acceptée) |
  | 503 | `unavailable` | dictée désactivée / non configurée / Whisper injoignable |
  | 502 | `failed` | Whisper a répondu une erreur ou une réponse illisible |
- Confidentialité : l'audio n'est **jamais écrit sur disque** (mémoire seulement), le **texte n'est
  jamais journalisé** ; le journal ne contient que : utilisateur, durée, langue, statut, temps.

## Interface (`app/js/micro.js`, fichier NEUF, chargé par `PageController` après le bundle principal)
- Repris du prototype `~/whisper-test/prototype/micro.js` (encodage WAV 16 kHz testé Chrome/Safari).
- S'accroche à la zone de saisie du chat du bundle compilé (sélecteur trouvé en lisant le bundle ;
  `MutationObserver` car Vue re-rend) ; aucun octet du bundle compilé n'est modifié.
- Au chargement puis toutes les 60 s : `GET /api/dictation/status` ; `available:false` → bouton grisé
  + info-bulle « Service de dictée hors ligne ». Aucune erreur visible si la route n'existe pas (404) :
  le bouton n'apparaît simplement pas.
- Envoi : `POST /api/dictation` avec `requesttoken` (meta `requesttoken` ou `OC.requestToken`),
  `lang` = `document.documentElement.lang` réduit à 2 lettres.
- Insertion au curseur + événement `input` (v-model de Vue). Textes fr/en/ar/de/ur, RTL par propriétés
  logiques, bouton accessible (aria-label, aria-pressed, Échap = annuler), 2 min max avec compteur.
- Messages pour 413/429/502/503 distincts ; jamais de texte de l'utilisateur dans la console.
