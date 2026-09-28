/*
 * Bouton micro (dictée) d'Infinity AI — eva_ai, fichier NEUF, sans build ni dépendance.
 * Contrat : deploiement/CONTRAT-DICTEE.md (v2.2, 28/09/2026) + décisions admin du 28/09 (détection
 * automatique seule, sans bascule de langue de l'interface). Repris du prototype ~/whisper-test/prototype/micro.js
 * (encodage WAV 16 kHz vérifié dans Chrome, gardé octet pour octet).
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
 * La zone de saisie est un <input type="text"> : insertion au curseur via selectionStart/End.
 *
 * ARCHITECTURE : l'audio ne quitte JAMAIS l'ordinateur. Il part au Whisper LOCAL dont l'URL est lue
 * UNE fois, au chargement, dans <meta name="eva-ai-dictation"> du <head> (absente ou non locale ⇒ ce
 * script ne fait rien). Hôte accepté : exactement localhost ou 127.0.0.1 (forme texte stricte, pas
 * d'IP déguisée), http/https, sans identifiants. Deux fonctions seulement parlent à Whisper :
 *   disponible() → Promise<true|null>   (null ⇒ aucun bouton : poste sans Whisper)
 *   transcrire(wavBlob, {signal}) → Promise<{text, language}>
 * Appels Whisper : credentials 'omit', aucun en-tête, redirect 'error', referrerPolicy 'no-referrer'.
 * Le texte est inséré dans la zone de saisie ; jamais d'envoi automatique du message.
 * Ce script ne fait AUCUN appel vers Nextcloud : seul Whisper local est contacté.
 *
 * LANGUE (décision admin : détection automatique seule, pas de menu) :
 *  - Enregistrement quasi muet (aucune fenêtre de 20 ms au-dessus de -40 dBFS) ⇒ « rien d'audible »
 *    SANS appeler Whisper : sur du silence, Whisper invente du texte (mesuré le 28/09 : 40 s de
 *    silence ⇒ « Thank you. Thank you. »).
 *  - 1er appel en verbose_json sans `language`. Jetons [BLANK_AUDIO]/[silence] retirés partout ;
 *    réponse vide ⇒ « rien d'audible », aucun second appel.
 *  - Langue détectée ∉ {french, arabic, english} ⇒ UN SEUL 2e appel avec `language` = dernière langue
 *    correctement détectée pendant la session (variable de module), sinon langue de l'interface si
 *    fr/ar/en, sinon fr. Aucun seuil de probabilité sur ce repli (un seuil casse l'arabe court).
 *  - Texte : `segments[].text` recollés SANS séparateur (whisper-server coupe parfois au milieu d'un
 *    mot) ; à défaut `text` sans ses « \n ».
 *  - Après chaque transcription réussie : attribut `dir` de la zone de saisie (rtl pour ar, sinon ltr).
 *
 * CONFIDENTIALITÉ : le texte dicté n'est jamais écrit dans la console ; seuls des codes d'erreur.
 */
(function () {
  'use strict';
  if (window.EvaDictee) return; // script chargé deux fois : on garde la première instance

  // ---------------------------------------------------------------- textes (fr/en/ar/de/ur)
  const TEXTES = {
    fr: { start: 'Dicter', stop: 'Arrêter et transcrire', cancel: 'Annuler', sending: 'Transcription…',
      recording: 'Enregistrement en cours. Échap pour annuler.', remaining: 'Plus que 15 secondes.',
      denied: 'Micro refusé par le navigateur. Autorisez-le dans les réglages du site.', nomic: 'Aucun micro détecté.',
      unsupported: 'Ce navigateur ne permet pas la dictée.', insecure: 'La dictée exige une connexion sécurisée (HTTPS).',
      failed: 'La transcription a échoué. Réessayez.',
      empty: 'Rien d’audible n’a été entendu.', limit: 'Durée maximale atteinte : enregistrement arrêté.',
      too_long: 'Enregistrement trop long : raccourcissez-le.',
      invalid_audio: 'Enregistrement illisible. Réessayez.',
      network: 'Service de dictée injoignable sur cet ordinateur.', nozone: 'Zone de saisie introuvable : texte non inséré.',
      switched: 'Dictée annulée : vous avez changé de conversation.', cancelled: 'Dictée annulée.' },
    en: { start: 'Dictate', stop: 'Stop and transcribe', cancel: 'Cancel', sending: 'Transcribing…',
      recording: 'Recording. Press Escape to cancel.', remaining: '15 seconds left.',
      denied: 'Microphone blocked by the browser. Allow it in the site settings.', nomic: 'No microphone found.',
      unsupported: 'This browser cannot record dictation.', insecure: 'Dictation requires a secure connection (HTTPS).',
      failed: 'Transcription failed. Please try again.',
      empty: 'Nothing audible was heard.', limit: 'Maximum length reached: recording stopped.',
      too_long: 'Recording too long: please make it shorter.',
      invalid_audio: 'The recording could not be read. Please try again.',
      network: 'Dictation service unreachable on this computer.', nozone: 'Input field not found: text not inserted.',
      switched: 'Dictation cancelled: you switched conversations.', cancelled: 'Dictation cancelled.' },
    ar: { start: 'إملاء', stop: 'إيقاف وتحويل إلى نص', cancel: 'إلغاء', sending: 'جارٍ التحويل…',
      recording: 'جارٍ التسجيل. اضغط Esc للإلغاء.', remaining: 'تبقّت 15 ثانية.',
      denied: 'المتصفح منع الميكروفون. اسمح به في إعدادات الموقع.', nomic: 'لم يُعثر على ميكروفون.',
      unsupported: 'هذا المتصفح لا يدعم الإملاء.', insecure: 'يتطلب الإملاء اتصالًا آمنًا (HTTPS).',
      failed: 'تعذّر التحويل إلى نص. حاول مرة أخرى.',
      empty: 'لم يُسمع أي كلام.', limit: 'بلغ التسجيل المدة القصوى فتوقف.',
      too_long: 'التسجيل طويل جدًا: اختصره.',
      invalid_audio: 'تعذّرت قراءة التسجيل. حاول مرة أخرى.',
      network: 'تعذّر الوصول إلى خدمة الإملاء على هذا الحاسوب.', nozone: 'لم يُعثر على خانة الكتابة: لم يُدرج النص.',
      switched: 'أُلغي الإملاء: لقد انتقلت إلى محادثة أخرى.', cancelled: 'أُلغي الإملاء.' },
    de: { start: 'Diktieren', stop: 'Beenden und transkribieren', cancel: 'Abbrechen', sending: 'Wird transkribiert…',
      recording: 'Aufnahme läuft. Esc zum Abbrechen.', remaining: 'Noch 15 Sekunden.',
      denied: 'Mikrofon vom Browser blockiert. Erlauben Sie es in den Website-Einstellungen.', nomic: 'Kein Mikrofon gefunden.',
      unsupported: 'Dieser Browser unterstützt kein Diktat.', insecure: 'Das Diktat erfordert eine sichere Verbindung (HTTPS).',
      failed: 'Transkription fehlgeschlagen. Bitte erneut versuchen.',
      empty: 'Nichts Hörbares erkannt.', limit: 'Maximale Dauer erreicht: Aufnahme beendet.',
      too_long: 'Aufnahme zu lang: bitte kürzer fassen.',
      invalid_audio: 'Aufnahme unlesbar. Bitte erneut versuchen.',
      network: 'Diktierdienst auf diesem Computer nicht erreichbar.', nozone: 'Eingabefeld nicht gefunden: Text nicht eingefügt.',
      switched: 'Diktat abgebrochen: Sie haben die Unterhaltung gewechselt.', cancelled: 'Diktat abgebrochen.' },
    ur: { start: 'املا', stop: 'روکیں اور متن میں بدلیں', cancel: 'منسوخ کریں', sending: 'متن میں بدلا جا رہا ہے…',
      recording: 'ریکارڈنگ جاری ہے۔ منسوخ کرنے کے لیے Esc دبائیں۔', remaining: '15 سیکنڈ باقی ہیں۔',
      denied: 'براؤزر نے مائیکروفون روک دیا ہے۔ سائٹ کی ترتیبات میں اجازت دیں۔', nomic: 'کوئی مائیکروفون نہیں ملا۔',
      unsupported: 'یہ براؤزر املا کی سہولت نہیں دیتا۔', insecure: 'املا کے لیے محفوظ کنکشن (HTTPS) ضروری ہے۔',
      failed: 'متن میں تبدیلی ناکام رہی۔ دوبارہ کوشش کریں۔',
      empty: 'کوئی قابلِ سماعت آواز نہیں ملی۔', limit: 'زیادہ سے زیادہ دورانیہ پورا ہو گیا: ریکارڈنگ روک دی گئی۔',
      too_long: 'ریکارڈنگ بہت طویل ہے: اسے مختصر کریں۔',
      invalid_audio: 'ریکارڈنگ پڑھی نہیں جا سکی۔ دوبارہ کوشش کریں۔',
      network: 'اس کمپیوٹر پر املا کی سروس تک رسائی نہیں ہو سکی۔', nozone: 'لکھنے کی جگہ نہیں ملی: متن شامل نہیں ہوا۔',
      switched: 'املا منسوخ: آپ نے گفتگو بدل دی۔', cancelled: 'املا منسوخ ہو گیا۔' },
  };
  const CODES_ERREUR = ['too_long', 'failed', 'invalid_audio', 'network', 'empty'];
  const langueUI = () => String(document.documentElement.lang || '').slice(0, 2).toLowerCase();
  const tx = () => TEXTES[langueUI()] || TEXTES.fr;

  class ErreurDictee extends Error {
    constructor(code) { super('dictee:' + code); this.code = code; }
  }

  // ---------------------------------------------------------------- URL du Whisper local
  /**
   * Lue une seule fois au démarrage, dans le <head> uniquement (une meta injectée plus tard dans le
   * corps de page est ignorée). Forme texte stricte AVANT l'analyse par URL(), qui normaliserait
   * « 0x7f.0.0.1 » ou « 2130706433 » en 127.0.0.1.
   */
  function lireUrlDictee() {
    const meta = document.head && document.head.querySelector('meta[name="eva-ai-dictation"]');
    const brut = meta ? String(meta.getAttribute('content') || '').trim() : '';
    if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d{1,5})?(\/[^?#@\s]*)?$/i.test(brut)) return null;
    let u;
    try { u = new URL(brut); } catch (e) { return null; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    if (u.hostname !== 'localhost' && u.hostname !== '127.0.0.1') return null;
    if (u.username || u.password || u.search || u.hash) return null;
    return { origine: u.origin, base: u.origin + u.pathname.replace(/\/+$/, '') };
  }
  let cible = null; // figée au démarrage

  const LANGUES_WHISPER = { french: 'fr', arabic: 'ar', english: 'en' };
  const NOM_WHISPER = { fr: 'french', ar: 'arabic', en: 'english' };
  const DELAI_DISPO_MS = 2000;
  const DELAI_TRANSCRIPTION_MS = 180000;
  let derniereLangue = null; // dernière langue CORRECTEMENT détectée (1er appel, ∈ fr/ar/en) de la session

  /** fetch vers Whisper local : ni cookie ni jeton, pas de redirection, pas de Referer ; délai + annulation. */
  async function appelLocal(url, init, delaiMs, signalExterne) {
    const ctrl = new AbortController();
    const minuteur = setTimeout(() => ctrl.abort(), delaiMs);
    const relais = () => ctrl.abort();
    if (signalExterne) {
      if (signalExterne.aborted) ctrl.abort();
      else signalExterne.addEventListener('abort', relais, { once: true });
    }
    try {
      return await fetch(url, Object.assign({ credentials: 'omit', mode: 'cors', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer' }, init, { signal: ctrl.signal }));
    } finally {
      clearTimeout(minuteur);
      if (signalExterne) signalExterne.removeEventListener('abort', relais);
    }
  }

  /** Segments recollés SANS séparateur (un segment qui ouvre un mot porte déjà son espace). */
  function texteDesSegments(j) {
    if (Array.isArray(j.segments) && j.segments.length) return j.segments.map((s) => String((s && s.text) || '')).join('');
    return String(j.text || '').replace(/\r?\n/g, '');
  }
  const normaliser = (t) => String(t || '').replace(/\[(BLANK_AUDIO|silence)\]/gi, ' ').replace(/\s+/g, ' ').trim();
  const estSilence = (t) => normaliser(t) === '';
  /** Au moins une fenêtre de 20 ms (320 échantillons) dont la valeur efficace dépasse 0,01 (-40 dBFS). */
  function estAudible(pcm) {
    for (let d = 0; d < pcm.length; d += 320) {
      const f = Math.min(pcm.length, d + 320);
      let s = 0;
      for (let i = d; i < f; i++) s += pcm[i] * pcm[i];
      if (Math.sqrt(s / Math.max(1, f - d)) > 0.01) return true;
    }
    return false;
  }

  const transportLocal = {
    maxSecondes: 120,
    /** true = Whisper local joignable ; null = rien sur ce poste → aucun bouton. */
    async disponible() {
      if (!cible) return null;
      try {
        const r = await appelLocal(cible.origine + '/', { method: 'GET' }, DELAI_DISPO_MS);
        return r.ok ? true : null;
      } catch (e) { return null; }
    },
    /**
     * Détection automatique + repli unique (voir en-tête). Retour :
     * { text (normalisé), language ('fr'|'ar'|'en'|null) }.
     */
    async transcrire(wav, opts) {
      const signal = opts && opts.signal;
      if (!cible) throw new ErreurDictee('network');
      const appel = async (forcee) => {
        if (signal && signal.aborted) throw new ErreurDictee('network');
        const fd = new FormData();
        fd.append('file', wav, 'dictee.wav');
        fd.append('response_format', 'verbose_json');
        if (forcee) fd.append('language', forcee);
        let r;
        try { r = await appelLocal(cible.base + '/audio/transcriptions', { method: 'POST', body: fd }, DELAI_TRANSCRIPTION_MS, signal); }
        catch (e) { throw new ErreurDictee('network'); }
        if (!r.ok) throw new ErreurDictee(r.status === 413 ? 'too_long' : r.status === 400 ? 'invalid_audio' : 'failed');
        let j;
        try { j = await r.json(); } catch (e) { throw new ErreurDictee('failed'); }
        if (!j || typeof j !== 'object') throw new ErreurDictee('failed');
        const nom = String(j.language || '').toLowerCase();
        return { text: normaliser(texteDesSegments(j)), nom };
      };
      const premier = await appel(null);
      const code = LANGUES_WHISPER[premier.nom] || null;
      // Silence, langue reconnue ou langue absente de la réponse : pas de second appel.
      if (estSilence(premier.text) || code || !premier.nom) {
        if (code && !estSilence(premier.text)) derniereLangue = code;
        return { text: premier.text, language: code };
      }
      const ui = langueUI();
      const indice = derniereLangue || (NOM_WHISPER[ui] ? ui : 'fr');
      const second = await appel(indice);
      return { text: second.text, language: indice };
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
  /** Mono 16 kHz, PCM 16 bits. Échec de décodage ⇒ ErreurDictee('invalid_audio'). */
  async function versWav16k(blob) {
    try {
      const brut = await blob.arrayBuffer();
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      let audio;
      try { audio = await ctx.decodeAudioData(brut); } finally { ctx.close(); }
      const Hors = window.OfflineAudioContext || window.webkitOfflineAudioContext;
      const hors = new Hors(1, Math.max(1, Math.ceil(audio.duration * 16000)), 16000);
      const src = hors.createBufferSource();
      src.buffer = audio; src.connect(hors.destination); src.start();
      const pcm = (await hors.startRendering()).getChannelData(0);
      if (!estAudible(pcm)) throw new ErreurDictee('empty');
      return new Blob([pcmVersWav(pcm).buffer], { type: 'audio/wav' });
    } catch (e) { throw e instanceof ErreurDictee ? e : new ErreurDictee('invalid_audio'); }
  }
  const TAILLE_MAX = 4000000; // 120 s × 32 000 o/s ≈ 3,84 Mo : garde-fou contre un enregistrement anormal

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

  // Icônes : chaînes CONSTANTES (aucune donnée externe), d'où innerHTML sans risque.
  const SVG_MICRO = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12,2A3,3 0 0,1 15,5V11A3,3 0 0,1 12,14A3,3 0 0,1 9,11V5A3,3 0 0,1 12,2M19,11C19,14.53 16.39,17.44 13,17.93V21H11V17.93C7.61,17.44 5,14.53 5,11H7A5,5 0 0,0 12,16A5,5 0 0,0 17,11H19Z"/></svg>';
  const SVG_STOP = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor"/></svg>';

  const CSS = `
.eva-dictee{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto;margin-inline:4px}
.eva-dictee button.eva-dictee__micro{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;
 inline-size:34px;block-size:34px;min-inline-size:34px;min-block-size:34px;margin:0;padding:0;border-radius:50%;
 border:1px solid var(--color-border-maxcontrast,var(--color-border,#888));background:transparent;
 color:var(--color-main-text,#222);cursor:pointer}
.eva-dictee button.eva-dictee__micro:hover{background:var(--color-background-hover,rgba(0,0,0,.06))}
.eva-dictee button:focus-visible{outline:2px solid var(--color-primary-element,#0082c9);outline-offset:2px}
.eva-dictee button.eva-dictee__micro[data-etat="enregistrement"]{background:var(--color-error,#d91812);
 border-color:var(--color-error,#d91812);color:#fff;animation:eva-dictee-pouls 1.4s ease-in-out infinite}
.eva-dictee button.eva-dictee__micro[data-etat="envoi"]{cursor:progress;opacity:.7}
.eva-dictee button.eva-dictee__micro.eva-dictee--inactif{opacity:.45;cursor:not-allowed}
.eva-dictee__compteur{font-size:12px;color:var(--color-text-maxcontrast,#666);font-variant-numeric:tabular-nums;white-space:nowrap}
.eva-dictee__compteur:empty{display:none}
.eva-dictee button.eva-dictee__annuler{box-sizing:border-box;block-size:34px;min-block-size:34px;margin:0;padding-block:0;
 padding-inline:10px;font-size:12px;border-radius:var(--border-radius-element,8px);
 border:1px solid var(--color-border-maxcontrast,var(--color-border,#888));background:transparent;
 color:var(--color-main-text,#222);cursor:pointer}
.eva-dictee button.eva-dictee__annuler:hover{background:var(--color-background-hover,rgba(0,0,0,.06))}
.eva-dictee button.eva-dictee__annuler[hidden]{display:none}
.eva-dictee-msg{font-size:12px;line-height:1.4;color:var(--color-text-maxcontrast,#666);padding-inline:8px;margin-block:4px}
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
  let ui = null;            // { racine, bouton, annuler, compteur, message, zone }
  let message = { texte: '', erreur: false };
  let compteur = '';
  let maxSecondes = 120;
  let rec = null, flux = null, morceaux = [], minuterie = null, debut = 0;
  let annule = false, limiteAtteinte = false, annonce15 = false;
  let zoneDepart = null;    // zone de saisie au démarrage de l'enregistrement (I1)
  let ctrlEnvoi = null;     // annulation de la transcription en cours (I4)

  /** Zone aria-live : début, fin, erreurs et annonce des 15 dernières secondes uniquement. */
  function afficher(texte, erreur) {
    message = { texte: texte || '', erreur: !!erreur };
    if (ui && ui.message.textContent !== message.texte) ui.message.textContent = message.texte;
    if (ui) ui.message.dataset.erreur = message.erreur ? '1' : '';
  }
  function afficherCompteur(texte) {
    compteur = texte || '';
    if (ui && ui.compteur.textContent !== compteur) ui.compteur.textContent = compteur;
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

    const cpt = document.createElement('span');
    cpt.className = 'eva-dictee__compteur';
    cpt.setAttribute('aria-hidden', 'true'); // visuel seulement : pas d'annonce chaque seconde

    const annulerBtn = document.createElement('button');
    annulerBtn.type = 'button';
    annulerBtn.className = 'eva-dictee__annuler';
    annulerBtn.textContent = t.cancel;
    annulerBtn.hidden = true;
    annulerBtn.addEventListener('click', (e) => { e.preventDefault(); annuler(); });

    racine.append(bouton, cpt, annulerBtn);

    const msg = document.createElement('div');
    msg.className = 'eva-dictee-msg';
    msg.setAttribute('role', 'status');
    msg.setAttribute('aria-live', 'polite');
    return { racine, bouton, annuler: annulerBtn, compteur: cpt, message: msg, zone };
  }

  function retirerUI() {
    document.querySelectorAll('.eva-dictee, .eva-dictee-msg').forEach((n) => n.remove());
    ui = null;
  }

  /**
   * Idempotent : ne crée jamais deux boutons, ré-attache si Vue a reconstruit le formulaire.
   * Tant qu'une dictée est en cours (etat ≠ repos), l'interface reste attachée même si Whisper
   * a été déclaré absent entre-temps : on doit toujours pouvoir arrêter ou annuler.
   */
  function attacher() {
    if (statut === null && etat === 'repos') { if (ui) retirerUI(); return; }
    const c = trouverZone();
    if (!c) return;
    if (ui && ui.racine.isConnected && ui.zone === c.zone && ui.message.isConnected) return;
    retirerUI();
    injecterStyles();
    ui = construireUI(c.zone);
    if (c.envoi) c.envoi.before(ui.racine); else c.zone.after(ui.racine);
    (c.form || c.zone).after(ui.message);
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
    // Un seul mécanisme d'état pour les lecteurs d'écran : le libellé change (pas d'aria-pressed).
    b.setAttribute('aria-label', libelle);
    b.title = libelle;
    // aria-disabled plutôt que disabled : l'info-bulle reste visible sur un bouton grisé.
    const inactif = inactifRepos || etat === 'envoi' || etat === 'demande';
    b.setAttribute('aria-disabled', inactif ? 'true' : 'false');
    b.classList.toggle('eva-dictee--inactif', inactifRepos);
    const icone = etat === 'enregistrement' ? 'stop' : 'micro';
    if (b.dataset.icone !== icone) { b.innerHTML = icone === 'stop' ? SVG_STOP : SVG_MICRO; b.dataset.icone = icone; }
    ui.annuler.hidden = etat !== 'enregistrement' && etat !== 'envoi';
    if (ui.compteur.textContent !== compteur) ui.compteur.textContent = compteur;
    if (ui.message.textContent !== message.texte) ui.message.textContent = message.texte;
    ui.message.dataset.erreur = message.erreur ? '1' : '';
  }

  function surClic() {
    if (etat === 'enregistrement') { arreter(false); return; }
    if (etat !== 'repos') return;
    const incompat = raisonIncompat();
    if (incompat) { afficher(tx()[incompat], true); return; }
    demarrer();
  }

  /** Échap ou bouton Annuler : pendant l'enregistrement OU pendant la transcription. */
  function annuler() {
    if (etat === 'enregistrement') arreter(true);
    else if (etat === 'envoi' && ctrlEnvoi) ctrlEnvoi.abort();
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
    morceaux = []; annule = false; limiteAtteinte = false; annonce15 = false;
    const c = trouverZone();
    zoneDepart = c ? c.zone : null;
    try {
      rec = new MediaRecorder(flux);
      rec.ondataavailable = (e) => { if (e.data && e.data.size) morceaux.push(e.data); };
      rec.onstop = terminer;
      rec.start(); // sans timeslice : un seul morceau à l'arrêt
    } catch (e) {
      couperMicro(); rec = null; etat = 'repos'; zoneDepart = null;
      afficher(tx().unsupported, true); maj();
      return;
    }
    debut = Date.now(); etat = 'enregistrement';
    afficher(tx().recording);
    tic(); maj();
    minuterie = setInterval(tic, 250);
  }

  function tic() {
    const s = Math.floor((Date.now() - debut) / 1000);
    afficherCompteur('● ' + mmss(Math.min(s, maxSecondes)) + ' / ' + mmss(maxSecondes));
    if (!annonce15 && maxSecondes > 15 && s >= maxSecondes - 15 && s < maxSecondes) { annonce15 = true; afficher(tx().remaining); }
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
    afficherCompteur('');
    if (annule) { morceaux = []; zoneDepart = null; etat = 'repos'; afficher(tx().cancelled); maj(); return; }
    etat = 'envoi'; afficher(tx().sending); maj();
    ctrlEnvoi = new AbortController();
    const signal = ctrlEnvoi.signal;
    const zone = zoneDepart;
    try {
      const wav = await versWav16k(new Blob(morceaux, { type: (r && r.mimeType) || 'audio/webm' }));
      morceaux = [];
      if (wav.size > TAILLE_MAX) throw new ErreurDictee('too_long');
      if (signal.aborted) throw new ErreurDictee('network');
      const rep = await transport.transcrire(wav, { signal });
      if (signal.aborted) throw new ErreurDictee('network');
      const texte = normaliser(rep && rep.text);
      if (estSilence(texte)) { afficher(tx().empty, true); return; }
      if (!zone) { afficher(tx().nozone, true); return; }
      if (!zone.isConnected) { afficher(tx().switched, true); return; }
      inserer(zone, texte);
      afficher(limiteAtteinte ? tx().limit : '', limiteAtteinte);
      const code = rep.language;
      if (NOM_WHISPER[code]) zone.setAttribute('dir', code === 'ar' ? 'rtl' : 'ltr');
    } catch (e) {
      if (signal.aborted) afficher(tx().cancelled);
      else {
        const code = e && CODES_ERREUR.includes(e.code) ? e.code : 'failed';
        afficher(tx()[code], true);
        console.warn('[eva_ai] dictée : échec (' + code + ')'); // jamais le texte ni l'audio
      }
    } finally {
      // Ne jamais retirer l'interface ici : le message de fin doit rester lisible. Le prochain
      // contrôle de disponibilité la retirera si Whisper a disparu.
      morceaux = []; zoneDepart = null; ctrlEnvoi = null;
      etat = 'repos';
      if (ui && !ui.racine.isConnected) { ui = null; }
      if (!ui && statut !== null) attacher();
      maj();
    }
  }

  function surTouche(e) {
    if (e.key === 'Escape' && (etat === 'enregistrement' || etat === 'envoi')) {
      e.preventDefault(); e.stopPropagation();
      annuler();
    }
  }

  // ---------------------------------------------------------------- disponibilité
  async function verifierStatut() {
    if (etat !== 'repos') return statut; // jamais pendant une dictée
    let v;
    try { v = await transport.disponible(); } catch (e) { v = null; }
    if (etat !== 'repos') return statut;
    statut = v === true ? true : null;
    const m = Number(transport.maxSecondes);
    maxSecondes = Number.isFinite(m) && m > 0 ? m : 120;
    if (statut === null) retirerUI();
    else { attacher(); maj(); }
    return statut;
  }

  let planifie = false;
  const observateur = new MutationObserver(() => {
    if (planifie || (statut === null && etat === 'repos')) return;
    planifie = true;
    Promise.resolve().then(() => { planifie = false; attacher(); });
  });

  let intervalle = null;
  function demarrerModule() {
    cible = lireUrlDictee();
    if (!cible) return; // meta absente ou refusée : fonction éteinte, rien d'autre
    observateur.observe(document.body || document.documentElement, { childList: true, subtree: true });
    document.addEventListener('keydown', surTouche, true);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && etat === 'repos') verifierStatut(); });
    verifierStatut();
    intervalle = setInterval(() => { if (!document.hidden) verifierStatut(); }, 60000);
  }

  // API publique minimale et gelée. Les internes ne sont exposés qu'au harnais de test, qui pose
  // window.__EVA_DICTEE_TEST__ = true AVANT le chargement du script.
  const api = { version: '2' };
  if (window.__EVA_DICTEE_TEST__ === true) {
    Object.assign(api, {
      ErreurDictee,
      transportLocal,
      /** { disponible() → Promise<true|null>, transcrire(wav, {signal}) → Promise<{text, language}>, maxSecondes? } */
      definirTransport(t) { transport = t || transportLocal; return verifierStatut(); },
      __test__: {
        ecrireEnteteWav, pcmVersWav, verifierStatut, trouverZone, lireUrlDictee,
        etat: () => etat, statut: () => statut, arreterIntervalle: () => clearInterval(intervalle),
      },
    });
  }
  window.EvaDictee = Object.freeze(api);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrerModule, { once: true });
  else demarrerModule();
})();
