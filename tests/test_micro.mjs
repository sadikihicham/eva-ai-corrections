// Tests du bouton micro (app/js/micro.js, contrat de dictée v2) dans un DOM simulé (jsdom).
//
// jsdom n'est PAS une dépendance du dépôt : il est installé hors dépôt.
//   mkdir -p /private/tmp/claude-501/micro-tests && cd /private/tmp/claude-501/micro-tests \
//     && npm init -y && npm install jsdom
//   node --test tests/test_micro.mjs          (JSDOM_DIR=<dossier node_modules/jsdom> pour un autre chemin)
//
// Faux navigateur : fetch (Whisper local simulé), getUserMedia, MediaRecorder, AudioContext et
// OfflineAudioContext. Les faux contextes audio laissent tourner le VRAI encodeur WAV de micro.js.
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
function chargerJsdom() {
  try { return require('jsdom'); } catch (e) { /* pas installé localement */ }
  return require(process.env.JSDOM_DIR || '/private/tmp/claude-501/micro-tests/node_modules/jsdom');
}
const { JSDOM, VirtualConsole } = chargerJsdom();

const ICI = path.dirname(fileURLToPath(import.meta.url));
const CODE = readFileSync(path.join(ICI, '..', 'app', 'js', 'micro.js'), 'utf8');
const URL_LOCALE = 'http://localhost:8178/v1';
const TEXTE_SECRET = 'phrase dictée confidentielle 4711';

const FORM_MAIN = `<div id="vue"><form class="chatform"><button type="button" class="cbtn cbtn-ghost cbtn-files">📎</button><input id="chatinput" type="text" autocomplete="off"><button type="submit" class="cbtn">Send message</button><button type="button" class="cbtn cbtn-ghost">Run in background</button></form><div class="err" style="display:none"></div></div>`;
const FORM_STANDALONE = `<div id="content"><form class="form" id="form"><input id="q" type="text" autocomplete="off"><button type="submit" id="send">Send</button></form><div class="err" id="err"></div></div>`;

const reponse = (status, corps) => ({ ok: status >= 200 && status < 300, status, json: async () => corps });
const attendre = async (cond, ms = 2000) => {
  const fin = Date.now() + ms;
  while (Date.now() < fin) { if (cond()) return; await new Promise((r) => setTimeout(r, 5)); }
  throw new Error('délai dépassé en attendant : ' + cond.toString());
};
const pause = (ms = 20) => new Promise((r) => setTimeout(r, ms));
// Toute fenêtre ouverte est refermée à la fin, même si un test échoue (sinon l'intervalle de 60 s
// de micro.js garde node en vie).
const fenetres = [];
after(() => { for (const w of fenetres) { try { w.close(); } catch (e) { /* déjà fermée */ } } });

/**
 * Monte une page. options :
 *  meta (URL ou null), corps (HTML), lang, dir, securise, micro ('ok'|'refus'),
 *  whisper(url, init, n) → réponse simulée pour les POST, dispo (bool) pour GET /, sansMediaRecorder,
 *  silencieux (PCM nul), drapeauTest (défaut true : pose window.__EVA_DICTEE_TEST__ avant chargement),
 *  startLeve (MediaRecorder.start lève), decodeEchoue (decodeAudioData rejette), duree (s, défaut 0,5),
 *  oc (objet OC simulé, défaut uid alice), nextcloud(url, init) → réponse à tout fetch hors Whisper (doit rester inutilisé)
 */
async function monter(o = {}) {
  const journalConsole = [];
  const vc = new VirtualConsole();
  for (const m of ['log', 'info', 'warn', 'error', 'debug']) vc.on(m, (...a) => journalConsole.push(a.map(String).join(' ')));
  const meta = o.meta === undefined ? URL_LOCALE : o.meta;
  const html = `<!DOCTYPE html><html lang="${o.lang || 'fr'}" dir="${o.dir || 'ltr'}"><head>
    <meta name="requesttoken" content="JETON-NEXTCLOUD">
    ${meta === null ? '' : `<meta name="eva-ai-dictation" content="${meta}">`}
    </head><body>${o.metaCorps ? `<meta name="eva-ai-dictation" content="${o.metaCorps}">` : ''}${o.corps || FORM_MAIN}</body></html>`;
  const dom = new JSDOM(html, { url: 'https://nc.test/workspace/apps/eva_ai/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc });
  const w = dom.window;
  fenetres.push(w);
  const appels = [];
  const etat = { dispo: o.dispo !== undefined ? o.dispo : true, nPost: 0 };
  const appelsNc = [];
  w.fetch = async (url, init = {}) => {
    if (!String(url).startsWith('http://localhost:8178/')) {
      appelsNc.push({ url: String(url), init });
      return o.nextcloud ? o.nextcloud(String(url), init) : reponse(200, {});
    }
    appels.push({ url: String(url), init });
    if ((init.method || 'GET') === 'GET') {
      if (etat.dispo === 'rejet' || etat.dispo === false) throw new TypeError('Failed to fetch');
      return reponse(etat.dispo === 404 ? 404 : 200, {});
    }
    etat.nPost++;
    return o.whisper ? o.whisper(String(url), init, etat.nPost) : reponse(200, { text: TEXTE_SECRET, language: 'french' });
  };
  Object.defineProperty(w, 'isSecureContext', { value: o.securise !== false, configurable: true });
  const pistesArretees = [];
  Object.defineProperty(w.navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: async () => {
        if (o.micro === 'refus') { const e = new Error('refus'); e.name = 'NotAllowedError'; throw e; }
        return { getTracks: () => [{ stop: () => pistesArretees.push(1) }] };
      },
    },
  });
  const argsStart = [];
  if (!o.sansMediaRecorder) {
    w.MediaRecorder = class {
      constructor() { this.state = 'inactive'; this.mimeType = 'audio/webm'; }
      start(...a) { argsStart.push(a); if (o.startLeve) throw new Error('NotSupportedError'); this.state = 'recording'; }
      stop() {
        if (this.state === 'inactive') throw new Error('InvalidStateError');
        this.state = 'inactive';
        setTimeout(() => { this.ondataavailable && this.ondataavailable({ data: new w.Blob([new Uint8Array(64)]) }); this.onstop && this.onstop(); }, 0);
      }
    };
  }
  w.AudioContext = class { decodeAudioData() { return o.decodeEchoue ? Promise.reject(new Error('EncodingError')) : Promise.resolve({ duration: o.duree || 0.5 }); } close() { return Promise.resolve(); } };
  w.OfflineAudioContext = class {
    constructor(canaux, longueur, frequence) { this.longueur = longueur; this.frequence = frequence; this.destination = {}; }
    createBufferSource() { return { connect() {}, start() {} }; }
    startRendering() { const pcm = new Float32Array(this.longueur); for (let i = 0; i < pcm.length; i++) pcm[i] = o.silencieux ? 0 : Math.sin(i / 7) * 0.5; return Promise.resolve({ getChannelData: () => pcm }); }
  };
  if (o.drapeauTest !== false) w.__EVA_DICTEE_TEST__ = true;
  w.OC = o.oc !== undefined ? o.oc : { webroot: '/workspace', requestToken: 'JETON-OC', getCurrentUser: () => ({ uid: 'alice' }) };
  let soumissions = 0;
  w.document.addEventListener('submit', (e) => { soumissions++; e.preventDefault(); }, true);
  w.eval(CODE);
  await pause();
  return {
    dom, w, d: w.document, appels, appelsNc, etat, journalConsole, pistesArretees, argsStart,
    soumissions: () => soumissions,
    boutons: () => w.document.querySelectorAll('.eva-dictee__micro'),
    bouton: () => w.document.querySelector('.eva-dictee__micro'),
    msg: () => (w.document.querySelector('.eva-dictee-msg') || {}).textContent || '',
    compteur: () => w.document.querySelector('.eva-dictee__compteur'),
    posts: () => appels.filter((a) => (a.init.method || 'GET') === 'POST'),
    fermer: () => w.close(),
  };
}

/** Clic micro → enregistrement → clic arrêt → attend le retour au repos. */
async function dicter(p) {
  p.bouton().click();
  await attendre(() => p.bouton() && p.bouton().dataset.etat === 'enregistrement');
  p.bouton().click();
  await attendre(() => p.bouton() && p.bouton().dataset.etat === 'repos');
}
const champs = (appel) => Object.fromEntries([...appel.init.body.entries()].map(([k, v]) => [k, v]));

// ------------------------------------------------------------------------------------------------

test('en-tête WAV : RIFF/WAVE, PCM, mono, 16 000 Hz, 16 bits, tailles cohérentes', async () => {
  const p = await monter();
  const vue = p.w.EvaDictee.__test__.pcmVersWav(new Float32Array(16000));
  const txt = (o, n) => String.fromCharCode(...new Uint8Array(vue.buffer, o, n));
  assert.equal(vue.byteLength, 44 + 32000);
  assert.equal(txt(0, 4), 'RIFF'); assert.equal(vue.getUint32(4, true), 36 + 32000);
  assert.equal(txt(8, 4), 'WAVE'); assert.equal(txt(12, 4), 'fmt ');
  assert.equal(vue.getUint32(16, true), 16);
  assert.equal(vue.getUint16(20, true), 1, 'format PCM');
  assert.equal(vue.getUint16(22, true), 1, 'mono');
  assert.equal(vue.getUint32(24, true), 16000, '16 kHz');
  assert.equal(vue.getUint32(28, true), 32000, 'octets/s');
  assert.equal(vue.getUint16(32, true), 2, 'alignement');
  assert.equal(vue.getUint16(34, true), 16, '16 bits');
  assert.equal(txt(36, 4), 'data'); assert.equal(vue.getUint32(40, true), 32000);
  const plein = p.w.EvaDictee.__test__.pcmVersWav(Float32Array.from([1, -1, 2]));
  assert.equal(plein.getInt16(44, true), 32767); assert.equal(plein.getInt16(46, true), -32768);
  assert.equal(plein.getInt16(48, true), 32767, 'écrêtage au-delà de 1');
  p.fermer();
});

test('sans meta eva-ai-dictation : aucun appel réseau, aucun bouton', async () => {
  const p = await monter({ meta: null });
  await pause(50);
  assert.equal(p.appels.length, 0);
  assert.equal(p.boutons().length, 0);
  p.fermer();
});

test('meta vers un hôte non local : fonction éteinte, aucun appel', async () => {
  for (const meta of ['http://192.168.1.50:8178/v1', 'http://localhost.evil.test/v1', 'http://user:pw@localhost:8178/v1', 'ftp://localhost/v1',
    'http://[::1]:8178/v1', 'http://0x7f.0.0.1:8178/v1', 'http://2130706433:8178/v1', '/v1', 'javascript:alert(1)',
    'http://localhost:8178@evil.test/v1', 'http://127.0.0.1.evil.test/v1']) {
    const p = await monter({ meta });
    await pause(30);
    assert.equal(p.appels.length, 0, meta);
    assert.equal(p.boutons().length, 0, meta);
    p.fermer();
  }
});

test('Whisper local joignable : GET <origine>/ sans cookie ni jeton, bouton unique avant « Envoyer »', async () => {
  const p = await monter();
  await attendre(() => p.boutons().length === 1);
  const get = p.appels[0];
  assert.equal(get.url, 'http://localhost:8178/');
  assert.equal(get.init.credentials, 'omit');
  assert.equal(get.init.redirect, 'error');
  assert.equal(get.init.referrerPolicy, 'no-referrer');
  assert.ok(!get.init.headers || !JSON.stringify(get.init.headers).includes('JETON'), 'aucun jeton Nextcloud');
  const envoi = p.d.querySelector('form.chatform button[type="submit"]');
  assert.equal(envoi.previousElementSibling.className, 'eva-dictee');
  assert.equal(p.bouton().type, 'button', 'ne doit jamais soumettre le formulaire');
  assert.equal(p.bouton().hasAttribute('aria-pressed'), false, 'un seul mécanisme : le libellé change');
  assert.equal(p.bouton().getAttribute('aria-label'), 'Dicter');
  assert.equal(p.d.querySelector('.eva-dictee select, .eva-dictee__langue'), null, 'plus de menu de langue');
  // Re-vérifications répétées : toujours un seul bouton.
  await p.w.EvaDictee.__test__.verifierStatut();
  await p.w.EvaDictee.__test__.verifierStatut();
  assert.equal(p.boutons().length, 1);
  p.fermer();
});

test('re-rendu Vue (formulaire reconstruit) : bouton ré-attaché, jamais en double', async () => {
  const p = await monter();
  await attendre(() => p.boutons().length === 1);
  for (let i = 0; i < 3; i++) {
    const vue = p.d.getElementById('vue');
    vue.innerHTML = ''; // comme le ChatView : innerHTML="" puis reconstruction
    await pause(5);
    vue.innerHTML = FORM_MAIN.replace(/^<div id="vue">|<\/div>$/g, '');
    await attendre(() => p.d.querySelector('form.chatform .eva-dictee'));
    assert.equal(p.boutons().length, 1, 'tour ' + i);
    assert.equal(p.d.querySelectorAll('.eva-dictee-msg').length, 1);
  }
  p.fermer();
});

test('Whisper absent (réseau ou 404) : aucun bouton ; redevenu joignable : bouton ; reperdu : retiré', async () => {
  const p = await monter({ dispo: 'rejet' });
  await pause(30);
  assert.equal(p.boutons().length, 0);
  p.etat.dispo = 404;
  await p.w.EvaDictee.__test__.verifierStatut();
  assert.equal(p.boutons().length, 0, '404 = pas de bouton, pas de bouton grisé');
  p.etat.dispo = true;
  await p.w.EvaDictee.__test__.verifierStatut();
  assert.equal(p.boutons().length, 1);
  p.etat.dispo = false;
  await p.w.EvaDictee.__test__.verifierStatut();
  assert.equal(p.boutons().length, 0);
  p.fermer();
});

test('dictée « Auto », langue reconnue : un seul POST local, texte au curseur + input, jamais d’envoi auto', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  const zone = p.d.getElementById('chatinput');
  zone.value = 'Bonjour monde';
  zone.selectionStart = zone.selectionEnd = 7; // après « Bonjour »
  let inputs = 0;
  zone.addEventListener('input', () => inputs++);
  await dicter(p);
  const posts = p.posts();
  assert.equal(posts.length, 1);
  assert.equal(posts[0].url, 'http://localhost:8178/v1/audio/transcriptions');
  assert.equal(posts[0].init.credentials, 'omit');
  assert.equal(posts[0].init.redirect, 'error');
  assert.equal(posts[0].init.referrerPolicy, 'no-referrer');
  assert.ok(!posts[0].init.headers, 'aucun en-tête (ni requesttoken ni OCS)');
  const f = champs(posts[0]);
  assert.equal(f.response_format, 'verbose_json');
  assert.equal(f.language, undefined, 'Auto : pas de language au 1er appel');
  // Le fichier envoyé est un vrai WAV 16 kHz mono 16 bits produit par l'encodeur de micro.js.
  const octets = new DataView(await f.file.arrayBuffer());
  assert.equal(String.fromCharCode(octets.getUint8(0), octets.getUint8(1), octets.getUint8(2), octets.getUint8(3)), 'RIFF');
  assert.equal(octets.getUint32(24, true), 16000);
  assert.equal(octets.getUint16(22, true), 1);
  assert.equal(octets.getUint16(34, true), 16);
  assert.equal(octets.byteLength, 44 + 0.5 * 16000 * 2);
  assert.equal(zone.value, 'Bonjour ' + TEXTE_SECRET + ' monde');
  assert.equal(zone.selectionStart, ('Bonjour ' + TEXTE_SECRET).length, 'curseur juste après le texte inséré');
  assert.equal(inputs, 1, 'un événement input');
  assert.equal(p.soumissions(), 0, 'le message n’est jamais envoyé automatiquement');
  assert.ok(p.pistesArretees.length >= 1, 'micro coupé');
  assert.ok(p.appels.every((a) => a.url.startsWith('http://localhost:8178/')), 'aucun autre hôte');
  assert.ok(!p.appels.some((a) => a.url.includes('/api/dictation')), 'plus de route serveur');
  p.fermer();
});

test('Auto, langue détectée hors {fr, ar, en} : UN seul 2e appel avec la langue de l’interface (ar), sinon fr', async () => {
  const whisper = (url, init, n) => reponse(200, n === 1 ? { text: 'charabia', language: 'icelandic' } : { text: 'نص', language: 'arabic' });
  const p = await monter({ lang: 'ar', dir: 'rtl', whisper });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.posts().length, 2);
  assert.equal(champs(p.posts()[1]).language, 'ar');
  assert.equal(p.d.getElementById('chatinput').value, 'نص');
  p.fermer();

  const q = await monter({ lang: 'de', whisper: (u, i, n) => reponse(200, { text: 'x', language: 'icelandic' }) });
  await attendre(() => q.bouton());
  await dicter(q);
  assert.equal(q.posts().length, 2, 'jamais plus de 2 appels');
  assert.equal(champs(q.posts()[1]).language, 'fr', 'interface de → repli fr');
  q.fermer();
});

test('messages d’erreur distincts : injoignable, 413, 400, 500, [BLANK_AUDIO]', async () => {
  const cas = [
    { o: { decodeEchoue: true }, attendu: 'Enregistrement illisible. Réessayez.' },
    { w: () => { throw new TypeError('Failed to fetch'); }, attendu: 'Service de dictée injoignable sur cet ordinateur.' },
    { w: () => reponse(413, {}), attendu: 'Enregistrement trop long : raccourcissez-le.' },
    { w: () => reponse(400, {}), attendu: 'Enregistrement illisible. Réessayez.', memeTexte: true },
    { w: () => reponse(500, {}), attendu: 'La transcription a échoué. Réessayez.' },
    { w: () => reponse(200, { text: '[BLANK_AUDIO]', language: 'french' }), attendu: 'Rien d’audible n’a été entendu.' },
  ];
  const vus = new Set();
  for (const c of cas) {
    const p = await monter(Object.assign({ whisper: c.w }, c.o || {}));
    await attendre(() => p.bouton());
    await dicter(p);
    assert.equal(p.msg(), c.attendu);
    assert.equal(p.d.querySelector('.eva-dictee-msg').dataset.erreur, '1');
    assert.equal(p.d.getElementById('chatinput').value, '', 'rien inséré');
    if (c.o) assert.equal(p.posts().length, 0, 'décodage raté : rien envoyé');
    if (!c.memeTexte) vus.add(p.msg());
    p.fermer();
  }
  assert.equal(vus.size, cas.length - 1, 'messages distincts (400 et décodage raté partagent « illisible »)');
});

test('Échap pendant l’enregistrement : annule, aucun envoi, rien inséré', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  p.bouton().click();
  await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  assert.equal(p.bouton().getAttribute('aria-label'), 'Arrêter et transcrire');
  assert.equal(p.d.querySelector('.eva-dictee__annuler').hidden, false);
  assert.equal(p.compteur().getAttribute('aria-hidden'), 'true');
  assert.match(p.compteur().textContent, /^● 0:00 \/ 2:00$/);
  assert.equal(p.msg(), 'Enregistrement en cours. Échap pour annuler.');
  p.d.dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  await attendre(() => p.bouton().dataset.etat === 'repos');
  await pause(30);
  assert.equal(p.posts().length, 0);
  assert.equal(p.d.getElementById('chatinput').value, '');
  assert.equal(p.bouton().getAttribute('aria-label'), 'Dicter');
  p.fermer();
});

test('arrêt automatique à 2:00 puis envoi', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  p.bouton().click();
  await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  const vrai = p.w.Date.now.bind(p.w.Date);
  p.w.Date.now = () => vrai() + 121000;
  await attendre(() => p.posts().length === 1);
  await attendre(() => p.bouton().dataset.etat === 'repos');
  assert.equal(p.msg(), 'Durée maximale atteinte : enregistrement arrêté.');
  assert.equal(p.d.getElementById('chatinput').value, TEXTE_SECRET);
  p.fermer();
});

test('micro refusé, contexte non sécurisé, navigateur incompatible', async () => {
  const p = await monter({ micro: 'refus' });
  await attendre(() => p.bouton());
  p.bouton().click();
  await attendre(() => p.msg() !== '');
  assert.equal(p.msg(), 'Micro refusé par le navigateur. Autorisez-le dans les réglages du site.');
  assert.equal(p.bouton().dataset.etat, 'repos');
  p.fermer();

  const q = await monter({ securise: false });
  await attendre(() => q.bouton());
  assert.equal(q.bouton().getAttribute('aria-disabled'), 'true', 'grisé');
  assert.equal(q.bouton().title, 'La dictée exige une connexion sécurisée (HTTPS).');
  q.bouton().click();
  await pause();
  assert.equal(q.posts().length, 0);
  q.fermer();

  const r = await monter({ sansMediaRecorder: true });
  await attendre(() => r.bouton());
  assert.equal(r.bouton().getAttribute('aria-disabled'), 'true');
  assert.equal(r.bouton().title, 'Ce navigateur ne permet pas la dictée.');
  r.fermer();
});

test('textes et RTL : arabe et ourdou, styles en propriétés logiques', async () => {
  const p = await monter({ lang: 'ar', dir: 'rtl' });
  await attendre(() => p.bouton());
  assert.equal(p.bouton().getAttribute('aria-label'), 'إملاء');
  const css = p.d.getElementById('eva-dictee-styles').textContent;
  assert.ok(!/(margin|padding)-(left|right)|(^|[^-])(left|right)\s*:/.test(css), 'pas de gauche/droite physiques');
  p.fermer();
  for (const [lang, libelle] of [['ur', 'املا'], ['de', 'Diktieren'], ['en', 'Dictate'], ['es', 'Dicter']]) {
    const q = await monter({ lang });
    await attendre(() => q.bouton());
    assert.equal(q.bouton().getAttribute('aria-label'), libelle, lang);
    q.fermer();
  }
});

test('page standalone : bouton inséré avant #send', async () => {
  const p = await monter({ corps: FORM_STANDALONE });
  await attendre(() => p.bouton());
  assert.equal(p.d.getElementById('send').previousElementSibling.className, 'eva-dictee');
  await dicter(p);
  assert.equal(p.d.getElementById('q').value, TEXTE_SECRET);
  p.fermer();
});

test('le texte dicté n’apparaît jamais dans la console', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  await dicter(p);
  const q = await monter({ whisper: () => reponse(500, { text: TEXTE_SECRET }) });
  await attendre(() => q.bouton());
  await dicter(q);
  const tout = [...p.journalConsole, ...q.journalConsole].join('\n');
  assert.ok(!tout.includes(TEXTE_SECRET), 'texte trouvé dans la console');
  assert.ok(q.journalConsole.some((l) => l.includes('failed')), 'le code d’erreur, lui, est journalisé');
  p.fermer(); q.fermer();
});

test('segments recollés sans séparateur (mot coupé entre deux segments), repli sur text sans « \\n »', async () => {
  const p = await monter({ whisper: () => reponse(200, { text: ' مرحبا ال\nربا', language: 'arabic', segments: [{ text: ' مرحبا ال' }, { text: 'ربا' }] }) });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.d.getElementById('chatinput').value, 'مرحبا الربا');
  p.fermer();
  const q = await monter({ lang: 'ar', whisper: (u, i, n) => reponse(200, n === 1 ? { text: 'x', language: 'icelandic' } : { text: ' مرحبا ال\nربا', language: 'arabic' }) });
  await attendre(() => q.bouton());
  await dicter(q);
  assert.equal(champs(q.posts()[1]).response_format, 'verbose_json', 'verbose_json même au 2e appel');
  assert.equal(q.d.getElementById('chatinput').value, 'مرحبا الربا');
  q.fermer();
  const r = await monter({ whisper: () => reponse(200, { text: 'x', language: 'french', segments: [{ text: ' Bonjour' }, { text: '  tout   le' }, { text: ' monde. ' }] }) });
  await attendre(() => r.bouton());
  await dicter(r);
  assert.equal(r.d.getElementById('chatinput').value, 'Bonjour tout le monde.', 'segments prioritaires, espaces réduits');
  r.fermer();
});

test('seule la saisie est remplacée (formulaire conservé) : toujours un seul bouton', async () => {
  const p = await monter();
  await attendre(() => p.boutons().length === 1);
  for (let i = 0; i < 2; i++) {
    const ancienne = p.d.getElementById('chatinput');
    const neuve = p.d.createElement('input');
    neuve.id = 'chatinput'; neuve.type = 'text';
    ancienne.replaceWith(neuve);
    await pause(10);
    assert.equal(p.boutons().length, 1, 'tour ' + i);
    assert.equal(p.d.querySelectorAll('.eva-dictee-msg').length, 1, 'tour ' + i);
  }
  await dicter(p);
  assert.equal(p.d.getElementById('chatinput').value, TEXTE_SECRET);
  p.fermer();
});

// ------------------------------------------------------------------ correctifs après revue adverse
const reconstruire = async (p) => {
  const vue = p.d.getElementById('vue');
  vue.innerHTML = ''; await pause(5);
  vue.innerHTML = FORM_MAIN.replace(/^<div id="vue">|<\/div>$/g, '');
  await pause(10);
};
const bloquant = (init) => new Promise((res, rej) => {
  const s = init.signal;
  if (!s) return; // sans signal : ne se termine jamais (le test échouera sur l'attente)
  s.addEventListener('abort', () => rej(new DOMException('abort', 'AbortError')));
});

test('B2 : re-rendu PENDANT l’enregistrement (disponibilité non revérifiée pendant la dictée) : bouton toujours là, arrêt possible, pistes coupées', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  p.bouton().click();
  await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  p.etat.dispo = false;
  await p.w.EvaDictee.__test__.verifierStatut();
  await reconstruire(p);
  assert.equal(p.boutons().length, 1, 'bouton ré-attaché pendant l’enregistrement');
  assert.equal(p.bouton().dataset.etat, 'enregistrement');
  p.bouton().click();
  await attendre(() => p.w.EvaDictee.__test__.etat() === 'repos');
  assert.ok(p.pistesArretees.length >= 1, 'pistes coupées');
  assert.equal(p.d.getElementById('chatinput').value, '', 'I1 : conversation changée, rien inséré');
  assert.equal(p.msg(), 'Dictée annulée : vous avez changé de conversation.');
  p.fermer();
});

test('I1 : re-rendu PENDANT la transcription : bouton présent, pas d’insertion dans la nouvelle conversation', async () => {
  let lib; const bloque = new Promise((r) => { lib = r; });
  const p = await monter({ whisper: async () => { await bloque; return reponse(200, { text: 'texte de A', language: 'french' }); } });
  await attendre(() => p.bouton());
  p.bouton().click(); await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  p.bouton().click(); await attendre(() => p.posts().length === 1);
  await reconstruire(p);
  assert.equal(p.boutons().length, 1);
  assert.equal(p.bouton().dataset.etat, 'envoi');
  lib();
  await attendre(() => p.w.EvaDictee.__test__.etat() === 'repos');
  assert.equal(p.d.getElementById('chatinput').value, '');
  assert.equal(p.msg(), 'Dictée annulée : vous avez changé de conversation.');
  p.fermer();
});

test('I4 : Échap pendant l’envoi interrompt fetch ; Annuler interrompt le 2e appel du repli', async () => {
  const p = await monter({ whisper: (u, init) => bloquant(init) });
  await attendre(() => p.bouton());
  p.bouton().click(); await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  p.bouton().click(); await attendre(() => p.posts().length === 1);
  assert.equal(p.d.querySelector('.eva-dictee__annuler').hidden, false, 'Annuler visible pendant l’envoi');
  p.d.dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  await attendre(() => p.w.EvaDictee.__test__.etat() === 'repos');
  assert.equal(p.posts()[0].init.signal.aborted, true, 'fetch interrompu');
  assert.equal(p.d.getElementById('chatinput').value, '');
  assert.equal(p.msg(), 'Dictée annulée.');
  p.fermer();

  const q = await monter({ whisper: (u, init, n) => (n === 1 ? reponse(200, { text: 'x', language: 'icelandic' }) : bloquant(init)) });
  await attendre(() => q.bouton());
  q.bouton().click(); await attendre(() => q.bouton().dataset.etat === 'enregistrement');
  q.bouton().click(); await attendre(() => q.posts().length === 2);
  q.d.querySelector('.eva-dictee__annuler').click();
  await attendre(() => q.w.EvaDictee.__test__.etat() === 'repos');
  assert.equal(q.posts()[1].init.signal.aborted, true, '2e appel interrompu');
  assert.equal(q.d.getElementById('chatinput').value, '');
  q.fermer();
});

test('I3/I5 : rec.start() sans timeslice ; start qui lève ⇒ pistes coupées, retour au repos', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  await dicter(p);
  assert.deepEqual(p.argsStart[0], [], 'start() sans timeslice');
  p.fermer();
  const q = await monter({ startLeve: true });
  await attendre(() => q.bouton());
  q.bouton().click();
  await attendre(() => q.msg() !== '');
  assert.equal(q.w.EvaDictee.__test__.etat(), 'repos');
  assert.ok(q.pistesArretees.length >= 1, 'pistes coupées');
  assert.equal(q.msg(), 'Ce navigateur ne permet pas la dictée.');
  q.fermer();
});

test('I2 : compteur hors zone aria-live ; annonce à 1:45 ; aucune annonce par seconde', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  p.bouton().click(); await attendre(() => p.bouton().dataset.etat === 'enregistrement');
  const zoneLive = p.d.querySelector('.eva-dictee-msg');
  assert.equal(zoneLive.getAttribute('aria-live'), 'polite');
  assert.equal(zoneLive.contains(p.compteur()), false);
  let n = 0; new p.w.MutationObserver(() => n++).observe(zoneLive, { childList: true, characterData: true, subtree: true });
  await pause(1100);
  assert.equal(n, 0, 'la zone aria-live ne bouge pas chaque seconde');
  const vrai = p.w.Date.now.bind(p.w.Date);
  p.w.Date.now = () => vrai() + 105000;
  await attendre(() => p.msg() === 'Plus que 15 secondes.');
  assert.match(p.compteur().textContent, /^● 1:4[5-7] \/ 2:00$/, '≈ 1:45 (+ ~1 s réel déjà écoulé)');
  p.d.dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  await attendre(() => p.w.EvaDictee.__test__.etat() === 'repos');
  p.fermer();
});

test('silence : [BLANK_AUDIO] avec langue exotique ⇒ un seul appel, rien inséré', async () => {
  const p = await monter({ whisper: () => reponse(200, { text: ' [BLANK_AUDIO]', language: 'icelandic' }) });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.posts().length, 1);
  assert.equal(p.msg(), 'Rien d’audible n’a été entendu.');
  p.fermer();
});

test('repli : indice = DERNIÈRE langue correctement détectée (avant la langue de l’interface)', async () => {
  const reps = [{ text: 'مرحبا', language: 'arabic' }, { text: 'x', language: 'icelandic' }, { text: 'نص', language: 'arabic' }];
  const p = await monter({ lang: 'fr', whisper: (u, i, n) => reponse(200, reps[n - 1]) });
  await attendre(() => p.bouton());
  await dicter(p);
  await dicter(p);
  assert.equal(p.posts().length, 3);
  assert.equal(champs(p.posts()[2]).language, 'ar', 'dernière langue détectée (ar) et non l’interface (fr)');
  p.fermer();
});

test('dir posé sur la zone selon la langue détectée (rtl pour ar, ltr sinon)', async () => {
  const reps = [{ text: 'مرحبا', language: 'arabic' }, { text: 'Bonjour', language: 'french' }];
  const p = await monter({ whisper: (u, i, n) => reponse(200, reps[n - 1]) });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.d.getElementById('chatinput').getAttribute('dir'), 'rtl');
  await dicter(p);
  assert.equal(p.d.getElementById('chatinput').getAttribute('dir'), 'ltr');
  p.fermer();
});

test('silence en plusieurs segments [BLANK_AUDIO] + langue exotique ⇒ un seul appel, rien inséré ; jetons retirés d’un vrai texte', async () => {
  const p = await monter({ whisper: () => reponse(200, { language: 'icelandic', segments: [{ text: ' [BLANK_AUDIO]' }, { text: '[BLANK_AUDIO]' }] }) });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.posts().length, 1);
  assert.equal(p.d.getElementById('chatinput').value, '');
  assert.equal(p.msg(), 'Rien d’audible n’a été entendu.');
  p.fermer();
  const q = await monter({ whisper: () => reponse(200, { language: 'french', segments: [{ text: ' Bonjour' }, { text: ' [BLANK_AUDIO]' }, { text: ' à tous' }] }) });
  await attendre(() => q.bouton());
  await dicter(q);
  assert.equal(q.d.getElementById('chatinput').value, 'Bonjour à tous');
  q.fermer();
});

test('enregistrement muet : Whisper n’est PAS appelé (il invente du texte sur le silence)', async () => {
  const p = await monter({ silencieux: true });
  await attendre(() => p.bouton());
  await dicter(p);
  assert.equal(p.posts().length, 0, 'aucun POST sur un silence');
  assert.equal(p.d.getElementById('chatinput').value, '');
  assert.equal(p.msg(), 'Rien d’audible n’a été entendu.');
  p.fermer();
});

test('code : un seul point d’appel réseau (fetch), ni XHR, ni sendBeacon, ni WebSocket, ni navigation', () => {
  assert.equal((CODE.match(/\bfetch\(/g) || []).length, 1);
  assert.ok(!/XMLHttpRequest|sendBeacon|WebSocket|EventSource|location\.|sessionStorage|localStorage|eva-dictee:langue/.test(CODE));
});

test('meta : seule celle du <head> au chargement compte (ajout dans le body ou modification ultérieure ignorés)', async () => {
  const p = await monter({ meta: null, metaCorps: URL_LOCALE });
  await pause(40);
  assert.equal(p.appels.length, 0, 'meta dans le body ignorée');
  p.fermer();
  const q = await monter();
  await attendre(() => q.bouton());
  q.d.querySelector('meta[name="eva-ai-dictation"]').setAttribute('content', 'http://localhost:9999/v1');
  await dicter(q);
  await q.w.EvaDictee.__test__.verifierStatut();
  assert.ok(q.appels.every((a) => a.url.startsWith('http://localhost:8178/')), 'URL figée au chargement');
  q.fermer();
});

test('M-1 : sans drapeau de test, EvaDictee n’expose ni transport ni internes, et est gelé', async () => {
  const p = await monter({ drapeauTest: false });
  await attendre(() => p.bouton());
  const api = p.w.EvaDictee;
  assert.deepEqual(Object.keys(api), ['version']);
  assert.equal(api.definirTransport, undefined);
  assert.equal(api.__test__, undefined);
  assert.equal(Object.isFrozen(api), true);
  p.fermer();
});

test('retour sur l’onglet (visibilitychange) : disponibilité revérifiée', async () => {
  const p = await monter();
  await attendre(() => p.bouton());
  p.etat.dispo = false;
  const avant = p.appels.length;
  p.d.dispatchEvent(new p.w.Event('visibilitychange'));
  await attendre(() => p.boutons().length === 0);
  assert.ok(p.appels.length > avant);
  p.fermer();
});


// ------------------------------------------------------------------ plus de bascule de langue (décision admin)
test('dictée arabe sûre (prob 0,99, 5 s) : aucun appel hors Whisper, pas de rechargement, rien en sessionStorage', async () => {
  const p = await monter({
    lang: 'fr', duree: 5,
    whisper: () => reponse(200, { text: 'مرحبا بكم', language: 'arabic', detected_language_probability: 0.99, language_probabilities: { ar: 0.99, fa: 0.01 } }),
  });
  let recharge = 0;
  // location.reload n'est pas redéfinissable dans jsdom : on espionne la navigation non implémentée
  // (jsdom émet « Not implemented: navigation » sur la console virtuelle) et on compte les écritures.
  const ecritures = [];
  const proto = Object.getPrototypeOf(p.w.sessionStorage);
  const setItem = proto.setItem;
  proto.setItem = function (k, v) { ecritures.push(k); return setItem.call(this, k, v); };
  await attendre(() => p.bouton());
  await dicter(p);
  await pause(50);
  assert.equal(p.d.getElementById('chatinput').value, 'مرحبا بكم');
  assert.equal(p.d.getElementById('chatinput').getAttribute('dir'), 'rtl');
  assert.equal(p.appelsNc.length, 0, 'aucun fetch hors Whisper');
  assert.ok(p.appels.every((a) => a.url.startsWith('http://localhost:8178/')));
  recharge = p.journalConsole.filter((l) => /navigation|reload/i.test(l)).length;
  assert.equal(recharge, 0, 'pas de location.reload');
  assert.equal(p.w.sessionStorage.length, 0, 'sessionStorage vide');
  assert.deepEqual(ecritures, [], 'aucune écriture en sessionStorage');
  assert.equal(p.d.documentElement.lang, 'fr', 'langue de l’interface inchangée');
  p.fermer();
});
