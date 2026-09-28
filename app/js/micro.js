/*
 * Bouton micro (dictée) d'Infinity AI — eva_ai, fichier NEUF, sans build ni dépendance.
 * Contrat : deploiement/CONTRAT-DICTEE.md (v2, 28/09/2026). Repris du prototype
 * ~/whisper-test/prototype/micro.js (encodage WAV 16 kHz vérifié dans Chrome, gardé octet pour octet).
 *
 * D'OÙ VIENT LE SÉLECTEUR (lu dans les bundles compilés, aucun octet de ces bundles n'est modifié) :
 *  - app/js/eva_ai-main.js (vue « Chat », construite en JS natif par la fonction du ChatView) :
 *      const W=document.createElement("form");W.className="chatform";
 *      const V=document.createElement("input");V.id="chatinput",V.type="text", …
 *      const q=document.createElement("button");q.type="submit",q.className="cbtn", … "Send message" …
 *      W.append($,V,q,G)      // $ = 📎 (cbtn-files), V = saisie, q = Envoyer, G = « Run in background »
 *    Le ChatView vide et reconstruit ce formulaire à chaque changement de conversation
 *    (n.value.innerHTML="" puis ci(n.value,…)) et lit lui-même n.value.querySelector("#chatinput") :
 *    l'id `chatinput` est donc une accroche stable du bundle. D'où le MutationObserver.
 *  - app/templates/standalone.php + app/js/eva_ai_standalone.js (page de repli sans Vue) :
 *      <form class="form" id="form"><input id="q" type="text" …><button type="submit" id="send">
 *  - Repli : tout élément #chatinput, bouton inséré juste après lui.
 * La zone de saisie est un <input type="text"> (pas un textarea) : l'insertion au curseur utilise
 * selectionStart/selectionEnd, valables pour les deux.
 *
 * ARCHITECTURE (contrat v2) : l'audio ne quitte JAMAIS l'ordinateur. Le navigateur l'envoie au
 * Whisper LOCAL dont l'URL est posée par PageController dans <meta name="eva-ai-dictation">
 * (absente ⇒ ce script ne fait rien). Seules deux fonctions parlent à Whisper, regroupées dans un
 * « transport » remplaçable (EvaDictee.definirTransport) :
 *   disponible() → Promise<true|null>   (null ⇒ aucun bouton : poste sans Whisper)
 *   transcrire(wavBlob, langue) → Promise<{text, language}>   (langue : auto|fr|ar|en)
 * Aucun appel vers un autre hôte que celui de la meta, jamais de cookie ni de jeton Nextcloud.
 * Le texte est inséré dans la zone de saisie ; jamais d'envoi automatique du message.
 *
 * CONFIDENTIALITÉ : le texte dicté n'est jamais écrit dans la console ; seuls des codes d'erreur.
 */
(function () {
  'use strict';
  if (window.EvaDictee) return; // script chargé deux fois : on garde la première instance

  // ---------------------------------------------------------------- textes (fr/en/ar/de/ur)
  const TEXTES = {
    fr: { start: 'Dicter', stop: 'Arrêter et transcrire', cancel: 'Annuler', sending: 'Transcription…',
      denied: 'Micro refusé par le navigateur. Autorisez-le dans les réglages du site.', nomic: 'Aucun micro détecté.',
      unsupported: 'Ce navigateur ne permet pas la dictée.', insecure: 'La dictée exige une connexion sécurisée (HTTPS).',
      failed: 'La transcription a échoué. Réessayez.',
      empty: 'Rien d’audible n’a été entendu.', limit: 'Durée maximale atteinte : enregistrement arrêté.',
      too_long: 'Enregistrement trop long : raccourcissez-le.',
      invalid_audio: 'Enregistrement illisible. Réessayez.',
      network: 'Service de dictée injoignable sur cet ordinateur.', nozone: 'Zone de saisie introuvable : texte non inséré.',
      langLabel: 'Langue de la voix', auto: 'Auto' },
    en: { start: 'Dictate', stop: 'Stop and transcribe', cancel: 'Cancel', sending: 'Transcribing…',
      denied: 'Microphone blocked by the browser. Allow it in the site settings.', nomic: 'No microphone found.',
      unsupported: 'This browser cannot record dictation.', insecure: 'Dictation requires a secure connection (HTTPS).',
      failed: 'Transcription failed. Please try again.',
      empty: 'Nothing audible was heard.', limit: 'Maximum length reached: recording stopped.',
      too_long: 'Recording too long: please make it shorter.',
      invalid_audio: 'The recording could not be read. Please try again.',
      network: 'Dictation service unreachable on this computer.', nozone: 'Input field not found: text not inserted.',
      langLabel: 'Voice language', auto: 'Auto' },
    ar: { start: 'إملاء', stop: 'إيقاف وتحويل إلى نص', cancel: 'إلغاء', sending: 'جارٍ التحويل…',
      denied: 'المتصفح منع الميكروفون. اسمح به في إعدادات الموقع.', nomic: 'لم يُعثر على ميكروفون.',
      unsupported: 'هذا المتصفح لا يدعم الإملاء.', insecure: 'يتطلب الإملاء اتصالًا آمنًا (HTTPS).',
      failed: 'تعذّر التحويل إلى نص. حاول مرة أخرى.',
      empty: 'لم يُسمع أي كلام.', limit: 'بلغ التسجيل المدة القصوى فتوقف.',
      too_long: 'التسجيل طويل جدًا: اختصره.',
      invalid_audio: 'تعذّرت قراءة التسجيل. حاول مرة أخرى.',
      network: 'تعذّر الوصول إلى خدمة الإملاء على هذا الحاسوب.', nozone: 'لم يُعثر على خانة الكتابة: لم يُدرج النص.',
      langLabel: 'لغة الصوت', auto: 'تلقائي' },
    de: { start: 'Diktieren', stop: 'Beenden und transkribieren', cancel: 'Abbrechen', sending: 'Wird transkribiert…',
      denied: 'Mikrofon vom Browser blockiert. Erlauben Sie es in den Website-Einstellungen.', nomic: 'Kein Mikrofon gefunden.',
      unsupported: 'Dieser Browser unterstützt kein Diktat.', insecure: 'Das Diktat erfordert eine sichere Verbindung (HTTPS).',
      failed: 'Transkription fehlgeschlagen. Bitte erneut versuchen.',
      empty: 'Nichts Hörbares erkannt.', limit: 'Maximale Dauer erreicht: Aufnahme beendet.',
      too_long: 'Aufnahme zu lang: bitte kürzer fassen.',
      invalid_audio: 'Aufnahme unlesbar. Bitte erneut versuchen.',
      network: 'Diktierdienst auf diesem Computer nicht erreichbar.', nozone: 'Eingabefeld nicht gefunden: Text nicht eingefügt.',
      langLabel: 'Sprache der Stimme', auto: 'Auto' },
    ur: { start: 'املا', stop: 'روکیں اور متن میں بدلیں', cancel: 'منسوخ کریں', sending: 'متن میں بدلا جا رہا ہے…',
      denied: 'براؤزر نے مائیکروفون روک دیا ہے۔ سائٹ کی ترتیبات میں اجازت دیں۔', nomic: 'کوئی مائیکروفون نہیں ملا۔',
      unsupported: 'یہ براؤزر املا کی سہولت نہیں دیتا۔', insecure: 'املا کے لیے محفوظ کنکشن (HTTPS) ضروری ہے۔',
      failed: 'متن میں تبدیلی ناکام رہی۔ دوبارہ کوشش کریں۔',
      empty: 'کوئی قابلِ سماعت آواز نہیں ملی۔', limit: 'زیادہ سے زیادہ دورانیہ پورا ہو گیا: ریکارڈنگ روک دی گئی۔',
      too_long: 'ریکارڈنگ بہت طویل ہے: اسے مختصر کریں۔',
      invalid_audio: 'ریکارڈنگ پڑھی نہیں جا سکی۔ دوبارہ کوشش کریں۔',
      network: 'اس کمپیوٹر پر املا کی سروس تک رسائی نہیں ہو سکی۔', nozone: 'لکھنے کی جگہ نہیں ملی: متن شامل نہیں ہوا۔',
      langLabel: 'آواز کی زبان', auto: 'خودکار' },
  };
  const CODES_ERREUR = ['too_long', 'failed', 'invalid_audio', 'network'];
  const langueUI = () => {
    const l = String(document.documentElement.lang || '').slice(0, 2).toLowerCase();
    return TEXTES[l] ? l : 'fr';
  };
  const tx = () => TEXTES[langueUI()];

  // Choix de la langue de la voix : « auto » (défaut) ou forcée, mémorisé par navigateur.
  const CLE_LANGUE = 'eva_ai.dictee.langueVoix';
  const LANGUES_VOIX = [
    { v: 'auto', court: null, nom: null },
    { v: 'fr', court: 'FR', nom: 'Français' },
    { v: 'ar', court: 'AR', nom: 'العربية' },
    { v: 'en', court: 'EN', nom: 'English' },
  ];
  function lireLangueVoix() {
    try {
      const v = window.localStorage.getItem(CLE_LANGUE);
      return LANGUES_VOIX.some((l) => l.v === v) ? v : 'auto';
    } catch (e) { return 'auto'; }
  }
  function ecrireLangueVoix(v) {
    try { window.localStorage.setItem(CLE_LANGUE, v); } catch (e) { /* stockage bloqué : sans effet */ }
  }

  // ---------------------------------------------------------------- transport (remplaçable)
  class ErreurDictee extends Error {
    constructor(code) { super('dictee:' + code); this.code = code; }
  }

  /**
   * URL du Whisper LOCAL posée par PageController (<meta name="eva-ai-dictation">, contrat v2).
   * Garde-fou redoublé côté navigateur : hôte exactement localhost ou 127.0.0.1, http/https,
   * sans identifiants — sinon la fonction reste éteinte (null). L'audio ne part jamais ailleurs.
   */
  function lireUrlDictee() {
    const meta = document.querySelector('meta[name="eva-ai-dictation"]');
    const brut = meta && String(meta.content || '').trim();
    if (!brut) return null;
    let u;
    try { u = new URL(brut); } catch (e) { return null; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    if (u.hostname !== 'localhost' && u.hostname !== '127.0.0.1') return null;
    if (u.username || u.password || u.search || u.hash) return null;
    return { origine: u.origin, base: u.origin + u.pathname.replace(/\/+$/, '') };
  }

  const LANGUES_WHISPER = { french: 'fr', arabic: 'ar', english: 'en' };
  const DELAI_DISPO_MS = 2000;
  const DELAI_TRANSCRIPTION_MS = 180000;

  /** fetch vers Whisper local : jamais de cookie ni de jeton Nextcloud, jamais de redirection suivie. */
  async function appelLocal(url, init, delaiMs) {
    const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
    const minuteur = ctrl ? setTimeout(() => ctrl.abort(), delaiMs) : null;
    try {
      return await fetch(url, Object.assign({ credentials: 'omit', mode: 'cors', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer' }, init, ctrl ? { signal: ctrl.signal } : {}));
    } finally { if (minuteur) clearTimeout(minuteur); }
  }

  /**
   * whisper-server joint les segments de `text` par « \n », et un segment peut commencer au MILIEU
   * d'un mot (mesuré en arabe : « ال » + « ربا » → « ال\nربا »). On recolle donc `segments[].text`
   * SANS séparateur (un segment qui ouvre un nouveau mot porte déjà son espace en tête) ; repli :
   * `text` sans ses « \n ». Espaces réduits et trim faits ensuite par terminer().
   */
  function texteDesSegments(j) {
    if (Array.isArray(j.segments) && j.segments.length) return j.segments.map((s) => String((s && s.text) || '')).join('');
    return String(j.text || '').replace(/\r?\n/g, '');
  }

  const transportLocal = {
    maxSecondes: 120,
    /** true = Whisper local joignable ; null = rien sur ce poste (ou meta absente) → aucun bouton. */
    async disponible() {
      const cible = lireUrlDictee();
      if (!cible) return null;
      try {
        const r = await appelLocal(cible.origine + '/', { method: 'GET' }, DELAI_DISPO_MS);
        return r.ok ? true : null;
      } catch (e) { return null; }
    },
    /**
     * langue : 'auto' | 'fr' | 'ar' | 'en'. Règle v2 : manuel ⇒ un seul appel avec language ;
     * auto ⇒ verbose_json sans language, puis UN SEUL 2e appel si la langue détectée ∉ {fr, ar, en}.
     * Retour : { text, language (code ISO ou null) }.
     */
    async transcrire(wav, langue) {
      const cible = lireUrlDictee();
      if (!cible) throw new ErreurDictee('network');
      const appel = async (forcee) => {
        const fd = new FormData();
        fd.append('file', wav, 'dictee.wav');
        fd.append('response_format', 'verbose_json');
        if (forcee) fd.append('language', forcee);
        let r;
        try { r = await appelLocal(cible.base + '/audio/transcriptions', { method: 'POST', body: fd }, DELAI_TRANSCRIPTION_MS); }
        catch (e) { throw new ErreurDictee('network'); }
        if (!r.ok) throw new ErreurDictee(r.status === 413 ? 'too_long' : r.status === 400 ? 'invalid_audio' : 'failed');
        let j;
        try { j = await r.json(); } catch (e) { throw new ErreurDictee('failed'); }
        if (!j || typeof j !== 'object') throw new ErreurDictee('failed');
        const nom = String(j.language || '').toLowerCase();
        return { text: texteDesSegments(j), nom, language: forcee || LANGUES_WHISPER[nom] || null };
      };
      if (langue === 'fr' || langue === 'ar' || langue === 'en') {
        const rep = await appel(langue);
        return { text: rep.text, language: rep.language };
      }
      const premier = await appel(null);
      // Langue absente de la réponse : rien à comparer, on garde le résultat.
      if (!premier.nom || LANGUES_WHISPER[premier.nom]) return { text: premier.text, language: premier.language };
      const ui = langueUI();
      const second = await appel(ui === 'fr' || ui === 'ar' || ui === 'en' ? ui : 'fr');
      return { text: second.text, language: second.language };
    },
  };
  let transport = transportLocal;

  // ---------------------------------------------------------------- encodage WAV (prototype)
  /** En-tête WAV 44 octets : PCM (1), mono, 16 000 Hz, 16 bits. */
  function ecrireEnteteWav(vue, nbEchantillons) {
    const ecrire = (o, s) => { for (let i = 0; i < s.length; i++) vue.setUint8(o + i, s.charCodeAt(i)); };
    ecrire(0, 'RIFF'); vue.setUint32(4, 36 + nbEchantillons * 2, true); ecrire(8, 'WAVE'); ecrire(12, 'fmt ');
    vue.setUint32(16, 16, true); vue.setUint16(20, 1, true); vue.setUint16(22, 1, true);
    vue.setUint32(24, 16000, true); vue.setUint32(28, 32000, true); vue.setUint16(32, 2, true); vue.setUint16(34, 16, true);
    ecrire(36, 'data'); vue.setUint32(40, nbEchantillons * 2, true);
  }
  function pcmVersWav(pcm) {
    const vue = new DataView(new ArrayBuffer(44 + pcm.length * 2));
    ecrireEnteteWav(vue, pcm.length);
    for (let i = 0; i < pcm.length; i++) { const v = Math.max(-1, Math.min(1, pcm[i])); vue.setInt16(44 + i * 2, v < 0 ? v * 0x8000 : v * 0x7fff, true); }
    return vue;
  }
  /** Mono 16 kHz, PCM 16 bits : le format que Whisper attend, sans conversion côté serveur. */
  async function versWav16k(blob) {
    const brut = await blob.arrayBuffer();
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    let audio;
    try { audio = await ctx.decodeAudioData(brut); } finally { ctx.close(); }
    const Hors = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    const hors = new Hors(1, Math.max(1, Math.ceil(audio.duration * 16000)), 16000);
    const src = hors.createBufferSource();
    src.buffer = audio; src.connect(hors.destination); src.start();
    const pcm = (await hors.startRendering()).getChannelData(0);
    return { wav: new Blob([pcmVersWav(pcm).buffer], { type: 'audio/wav' }), secondes: audio.duration };
  }
  const TAILLE_MAX = 4000000; // contrat : taille ≤ 4 000 000 octets

  /** Insère au curseur, avec une espace de séparation si besoin, puis signale la saisie. */
  function inserer(zone, texte) {
    const d = zone.selectionStart ?? zone.value.length, f = zone.selectionEnd ?? d;
    const avant = zone.value.slice(0, d), apres = zone.value.slice(f);
    const ajout = (avant && !/\s$/.test(avant) ? ' ' : '') + texte + (apres && !/^\s/.test(apres) ? ' ' : '');
    zone.value = avant + ajout + apres;
    zone.focus();
    try { zone.selectionStart = zone.selectionEnd = (avant + ajout).length; } catch (e) { /* type sans sélection */ }
    zone.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ---------------------------------------------------------------- accroche dans la page
  const SELECTEURS = [
    { zone: 'form.chatform #chatinput', envoi: 'button[type="submit"]' }, // eva_ai-main.js
    { zone: 'form#form input#q', envoi: '#send' },                        // page standalone
    { zone: '#chatinput', envoi: null },                                  // repli
  ];
  function trouverZone() {
    for (const s of SELECTEURS) {
      const zone = document.querySelector(s.zone);
      if (zone) {
        const form = zone.closest('form');
        const envoi = s.envoi && form ? form.querySelector(s.envoi) : null;
        return { zone, form, envoi };
      }
    }
    return null;
  }

  function raisonIncompat() {
    if (window.isSecureContext === false) return 'insecure'; // getUserMedia exige HTTPS (ou localhost)
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) || !window.MediaRecorder
      || !(window.AudioContext || window.webkitAudioContext)
      || !(window.OfflineAudioContext || window.webkitOfflineAudioContext)) return 'unsupported';
    return null;
  }

  const SVG_MICRO = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12,2A3,3 0 0,1 15,5V11A3,3 0 0,1 12,14A3,3 0 0,1 9,11V5A3,3 0 0,1 12,2M19,11C19,14.53 16.39,17.44 13,17.93V21H11V17.93C7.61,17.44 5,14.53 5,11H7A5,5 0 0,0 12,16A5,5 0 0,0 17,11H19Z"/></svg>';
  const SVG_STOP = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor"/></svg>';

  const CSS = `
.eva-dictee{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto;margin-inline:4px}
.eva-dictee button.eva-dictee__micro{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;
 inline-size:34px;block-size:34px;min-inline-size:34px;min-block-size:34px;margin:0;padding:0;border-radius:50%;
 border:1px solid var(--color-border-maxcontrast,var(--color-border,#888));background:transparent;
 color:var(--color-main-text,#222);cursor:pointer}
.eva-dictee button.eva-dictee__micro:hover{background:var(--color-background-hover,rgba(0,0,0,.06))}
.eva-dictee button:focus-visible,.eva-dictee select:focus-visible{outline:2px solid var(--color-primary-element,#0082c9);outline-offset:2px}
.eva-dictee button.eva-dictee__micro[data-etat="enregistrement"]{background:var(--color-error,#d91812);
 border-color:var(--color-error,#d91812);color:#fff;animation:eva-dictee-pouls 1.4s ease-in-out infinite}
.eva-dictee button.eva-dictee__micro[data-etat="envoi"]{cursor:progress;opacity:.7}
.eva-dictee button.eva-dictee__micro.eva-dictee--inactif{opacity:.45;cursor:not-allowed}
.eva-dictee select.eva-dictee__langue{box-sizing:border-box;block-size:34px;min-block-size:34px;max-inline-size:7em;margin:0;
 padding-block:0;padding-inline:6px;font-size:12px;border-radius:var(--border-radius-element,8px);
 border:1px solid var(--color-border-maxcontrast,var(--color-border,#888));
 background:var(--color-main-background,#fff);color:var(--color-main-text,#222)}
.eva-dictee button.eva-dictee__annuler{box-sizing:border-box;block-size:34px;min-block-size:34px;margin:0;padding-block:0;
 padding-inline:10px;font-size:12px;border-radius:var(--border-radius-element,8px);
 border:1px solid var(--color-border-maxcontrast,var(--color-border,#888));background:transparent;
 color:var(--color-main-text,#222);cursor:pointer}
.eva-dictee button.eva-dictee__annuler:hover{background:var(--color-background-hover,rgba(0,0,0,.06))}
.eva-dictee button.eva-dictee__annuler[hidden]{display:none}
.eva-dictee-msg{font-size:12px;line-height:1.4;color:var(--color-text-maxcontrast,#666);padding-inline:8px;margin-block:4px;font-variant-numeric:tabular-nums}
.eva-dictee-msg:empty{display:none}
.eva-dictee-msg[data-erreur="1"]{color:var(--color-error-text,var(--color-error,#d91812))}
@keyframes eva-dictee-pouls{0%,100%{opacity:1}50%{opacity:.6}}
@media (prefers-reduced-motion:reduce){.eva-dictee button.eva-dictee__micro{animation:none!important}}
`;
  function injecterStyles() {
    if (document.getElementById('eva-dictee-styles')) return;
    const st = document.createElement('style');
    st.id = 'eva-dictee-styles';
    st.textContent = CSS;
    (document.head || document.documentElement).appendChild(st);
  }

  // ---------------------------------------------------------------- état
  let statut = null;        // null = pas de Whisper local joignable (pas de bouton) · true
  let etat = 'repos';       // repos | demande (autorisation micro) | enregistrement | envoi
  let ui = null;            // { racine, bouton, annuler, select, message, zone }
  let message = { texte: '', erreur: false };
  let maxSecondes = 120;
  let rec = null, flux = null, morceaux = [], minuterie = null, debut = 0, annule = false, limiteAtteinte = false;

  function afficher(texte, erreur) {
    message = { texte: texte || '', erreur: !!erreur };
    if (ui) { ui.message.textContent = message.texte; ui.message.dataset.erreur = message.erreur ? '1' : ''; }
  }
  const mmss = (s) => Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');

  function construireUI(zone) {
    const t = tx();
    const racine = document.createElement('span');
    racine.className = 'eva-dictee';

    const bouton = document.createElement('button');
    bouton.type = 'button'; // jamais submit : ne doit pas envoyer le formulaire du chat
    bouton.className = 'eva-dictee__micro';
    bouton.addEventListener('click', (e) => { e.preventDefault(); surClic(); });

    const select = document.createElement('select');
    select.className = 'eva-dictee__langue';
    select.setAttribute('aria-label', t.langLabel);
    select.title = t.langLabel;
    for (const l of LANGUES_VOIX) {
      const o = document.createElement('option');
      o.value = l.v; o.textContent = l.court || t.auto;
      if (l.nom) { o.title = l.nom; o.lang = l.v; }
      select.appendChild(o);
    }
    select.value = lireLangueVoix();
    select.addEventListener('change', () => ecrireLangueVoix(select.value));

    const annuler = document.createElement('button');
    annuler.type = 'button';
    annuler.className = 'eva-dictee__annuler';
    annuler.textContent = t.cancel;
    annuler.hidden = true;
    annuler.addEventListener('click', (e) => { e.preventDefault(); if (etat === 'enregistrement') arreter(true); });

    racine.append(bouton, select, annuler);

    const msg = document.createElement('div');
    msg.className = 'eva-dictee-msg';
    msg.setAttribute('role', 'status');
    msg.setAttribute('aria-live', 'polite');
    return { racine, bouton, annuler, select, message: msg, zone };
  }

  function retirerUI() {
    document.querySelectorAll('.eva-dictee, .eva-dictee-msg').forEach((n) => n.remove());
    ui = null;
  }

  /** Idempotent : ne crée jamais deux boutons, ré-attache si Vue a reconstruit le formulaire. */
  function attacher() {
    if (statut === null) { if (etat === 'repos' && ui) retirerUI(); return; }
    const cible = trouverZone();
    if (!cible) return;
    if (ui && ui.racine.isConnected && ui.zone === cible.zone && ui.message.isConnected) return;
    retirerUI();
    injecterStyles();
    ui = construireUI(cible.zone);
    if (cible.envoi) cible.envoi.before(ui.racine); else cible.zone.after(ui.racine);
    (cible.form || cible.zone).after(ui.message);
    maj();
  }

  function maj() {
    if (!ui) return;
    const t = tx();
    const incompat = raisonIncompat();
    const inactifRepos = etat === 'repos' && !!incompat;
    let libelle = t.start;
    if (etat === 'enregistrement') libelle = t.stop;
    else if (etat === 'envoi') libelle = t.sending;
    else if (incompat) libelle = t[incompat];
    const b = ui.bouton;
    b.dataset.etat = etat;
    b.setAttribute('aria-label', libelle);
    b.title = libelle;
    b.setAttribute('aria-pressed', etat === 'enregistrement' ? 'true' : 'false');
    // aria-disabled plutôt que disabled : l'info-bulle reste visible sur un bouton grisé.
    const inactif = inactifRepos || etat === 'envoi' || etat === 'demande';
    b.setAttribute('aria-disabled', inactif ? 'true' : 'false');
    b.classList.toggle('eva-dictee--inactif', inactifRepos);
    const icone = etat === 'enregistrement' ? 'stop' : 'micro';
    if (b.dataset.icone !== icone) { b.innerHTML = icone === 'stop' ? SVG_STOP : SVG_MICRO; b.dataset.icone = icone; }
    ui.annuler.hidden = etat !== 'enregistrement';
    ui.select.disabled = etat !== 'repos';
    ui.message.textContent = message.texte;
    ui.message.dataset.erreur = message.erreur ? '1' : '';
  }

  function surClic() {
    if (etat === 'enregistrement') { arreter(false); return; }
    if (etat !== 'repos') return;
    const incompat = raisonIncompat();
    if (incompat) { afficher(tx()[incompat], true); return; }
    demarrer();
  }

  const couperMicro = () => {
    if (flux) flux.getTracks().forEach((p) => p.stop());
    flux = null;
    clearInterval(minuterie); minuterie = null;
  };

  async function demarrer() {
    afficher('');
    etat = 'demande'; maj();
    try {
      flux = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
    } catch (e) {
      const n = e && e.name;
      etat = 'repos';
      afficher(n === 'NotAllowedError' || n === 'SecurityError' ? tx().denied : n === 'NotFoundError' ? tx().nomic : tx().unsupported, true);
      maj();
      return;
    }
    morceaux = []; annule = false; limiteAtteinte = false;
    try { rec = new MediaRecorder(flux); } catch (e) { couperMicro(); etat = 'repos'; afficher(tx().unsupported, true); maj(); return; }
    rec.ondataavailable = (e) => { if (e.data && e.data.size) morceaux.push(e.data); };
    rec.onstop = terminer;
    rec.start(1000);
    debut = Date.now(); etat = 'enregistrement';
    tic(); maj();
    minuterie = setInterval(tic, 250);
  }

  function tic() {
    const s = Math.floor((Date.now() - debut) / 1000);
    afficher('● ' + mmss(Math.min(s, maxSecondes)) + ' / ' + mmss(maxSecondes));
    if (s >= maxSecondes) { limiteAtteinte = true; arreter(false); }
  }

  function arreter(annulation) {
    if (annulation) annule = true;
    clearInterval(minuterie); minuterie = null;
    if (rec && rec.state !== 'inactive') rec.stop();
  }

  async function terminer() {
    couperMicro();
    const r = rec; rec = null;
    if (annule) { morceaux = []; etat = 'repos'; afficher(''); maj(); return; }
    etat = 'envoi'; afficher(tx().sending); maj();
    try {
      const { wav } = await versWav16k(new Blob(morceaux, { type: (r && r.mimeType) || 'audio/webm' }));
      morceaux = [];
      if (wav.size > TAILLE_MAX) throw new ErreurDictee('too_long');
      const rep = await transport.transcrire(wav, lireLangueVoix());
      const texte = String((rep && rep.text) || '').replace(/\s+/g, ' ').trim();
      if (texte === '' || /^\[(BLANK_AUDIO|silence)\]$/i.test(texte)) afficher(tx().empty, true);
      else {
        const cible = trouverZone();
        if (cible) { inserer(cible.zone, texte); afficher(limiteAtteinte ? tx().limit : '', limiteAtteinte); }
        else afficher(tx().nozone, true);
      }
    } catch (e) {
      const code = e && CODES_ERREUR.includes(e.code) ? e.code : 'failed';
      afficher(tx()[code], true);
      console.warn('[eva_ai] dictée : échec (' + code + ')'); // jamais le texte ni l'audio
    } finally {
      morceaux = [];
      etat = 'repos';
      if (statut === null) retirerUI(); else { attacher(); maj(); }
    }
  }

  function surTouche(e) {
    if (e.key === 'Escape' && etat === 'enregistrement') {
      e.preventDefault(); e.stopPropagation();
      arreter(true);
    }
  }

  // ---------------------------------------------------------------- disponibilité
  async function verifierStatut() {
    let v;
    try { v = await transport.disponible(); } catch (e) { v = null; }
    statut = v === true ? true : null;
    const m = Number(transport.maxSecondes);
    maxSecondes = Number.isFinite(m) && m > 0 ? m : 120;
    if (statut === null) { if (etat === 'repos') retirerUI(); }
    else { attacher(); maj(); }
    return statut;
  }

  let planifie = false;
  const observateur = new MutationObserver(() => {
    if (planifie || statut === null) return;
    planifie = true;
    Promise.resolve().then(() => { planifie = false; attacher(); });
  });

  let intervalle = null;
  function demarrerModule() {
    if (!lireUrlDictee()) return; // meta absente ou refusée : fonction éteinte, rien d'autre
    observateur.observe(document.body || document.documentElement, { childList: true, subtree: true });
    document.addEventListener('keydown', surTouche, true);
    verifierStatut();
    intervalle = setInterval(() => { if (!document.hidden) verifierStatut(); }, 60000);
  }

  window.EvaDictee = {
    version: '1',
    ErreurDictee,
    /** Remplace le transport : { disponible() → Promise<true|null>, transcrire(wav, langue) → Promise<{text, language}>, maxSecondes? } */
    definirTransport(t) { transport = t || transportLocal; return verifierStatut(); },
    transportLocal,
    // RÉSERVÉ AUX TESTS (tests/test_micro.mjs) : ne pas utiliser depuis l'application.
    __test__: {
      ecrireEnteteWav, pcmVersWav, verifierStatut, trouverZone, lireUrlDictee,
      etat: () => etat, statut: () => statut, arreterIntervalle: () => clearInterval(intervalle),
    },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrerModule, { once: true });
  else demarrerModule();
})();
