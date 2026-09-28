# Sélecteurs eva_ai relevés dans le bundle

Source analysée (lecture seule) : `eva-js/main.js` (bundle webpack compilé d'eva_ai, 2 700 025 octets,
`//# sourceMappingURL=eva_ai-main.js.map`). Relevé du 28/09/2026. Les positions `@n` sont des
décalages en caractères dans ce fichier : elles changeront à la prochaine version d'eva.

## Comment l'interface est construite

- L'app Vue est montée sur `#eva_ai-root`. `App.vue` rend un `NcContent` avec `class:"eva-ai-app"`.
  `NcContent` produit `<div id="content-vue" class="content app-eva_ai …">`. La racine de portée
  `#content-vue.eva-ai-app` ne correspond donc qu'à la page d'eva.
- La vue de chat n'est **pas** un gabarit Vue : un simple `<div class="chatview-root">` (Vue) reçoit
  ensuite tout son DOM **en JavaScript** (`document.createElement` + `className=`). Les classes
  sont donc courtes (`rm`, `rb`, `rt`…) et fixées en dur dans le code.
- Eva injecte deux feuilles internes : `.chatview-root …` (module `7988`) et
  `src/lib/markdown.css` (module `3733`, règles `.rt …`). Sa bulle `.rb` reçoit en plus un **style en
  ligne** (`s.style.background=…`) doublé d'un `!important` dans sa feuille. C'est la seule raison
  des deux `!important` de `eva-chatgpt.css`.

## Table

| Sélecteur | Rôle | Preuve (extrait du bundle) |
|---|---|---|
| `#content-vue` | racine NcContent (portée) | `@2313837` `(0,f.CE)("div",{id:"content-vue",class:(0,f.C4)(["content",[`app-${e.appName.toLowerCase()}`…` |
| `.eva-ai-app` | classe d'eva sur NcContent (mode utilisateur) | `@2623666` `(0,a.Wv)(H,{key:1,class:"eva-ai-app","app-name":"eva_ai"}` |
| `.chatview-root` | conteneur de la vue de chat | `@2471904` `Je={ref:"root",class:"chatview-root"}` · CSS `@777287` `.chatview-root{width:100%;height:100%;…` |
| `--eva-content-width` | largeur de colonne lue par eva | `@769543` `.eva-ai-app[data-v-36902323]{width:100%;--eva-content-width: clamp(1180px, 78vw, 1680px)}` |
| `.head` | en-tête du chat | `@2579592` `className="head"` |
| `.head h1` | titre du chat | CSS `.chatview-root .head h1{margin:0;font-size:18px…` |
| `.export`, `.customize-btn` | boutons Exporter / Personnaliser | `@2579752` `className="export"` · `@2580954` `F.className="export customize-btn"` |
| `.chat-log` | liste des messages (zone défilante) | `@2584369` `className="chat-log"` |
| `.empty`, `.empty .ico`, `.empty .t`, `.empty .d` | état vide | `@2584430` `className="empty"` · `@2584488` `"ico"` · `@2584562` `"t"` · `@2584675` `"d"` |
| `.rm`, `.rm.user`, `.rm.assistant` | ligne de message + rôle | `@2587237` `o.className="rm "+i.role` ; rôles `"user"===i.role` / `"assistant"===i.role` |
| `.rb` | corps (bulle) du message | `@2587303` `s.className="rb",s.style.background="user"===i.role?"var(--color-primary-element, #00679c)":"var(--color-background-hover, #f1f2f4)"` |
| `.rt` | texte rendu en Markdown | `@2587858` `l.className="rt","assistant"===i.role&&i.text&&i.done?(l.innerHTML=oi(i.text),ri(l))…` |
| `.rt pre.md-pre`, `.rt pre code` | blocs de code | `@2578337` `ai.renderer.rules.fence=(...e)=>ii(...e).replace("<pre>",'<pre class="md-pre">')` · rendu markdown-it `<pre><code…>` `@2532723` |
| `.rt :not(pre) > code` | code en ligne | markdown-it `code_inline:…return`<code${i.renderAttrs(r)}>…` `@2532224` |
| `.rt .md-table-scroll`, `table`, `th`, `td`, `tr` | tableaux | `@2578337` `table_open=()=>'<div class="md-table-scroll" tabindex="0"><table>\n'` |
| `.rt img.md-image` | images | `@2576610` `<img class="md-image" src="…` |
| `.rt h1…h6`, `ul`, `ol`, `li`, `blockquote`, `hr`, `a`, `p` | typographie Markdown | CSS `.chatview-root .rt h1,…`, `.rt ul,.rt ol{margin:0 0 9px 22px…`, `.rt blockquote{…border-left:3px…` |
| `.racts` | rangée d'actions d'un message | `@2588080` `e.className="racts"` |
| `.rcopy` | copier la réponse | `@2588141` `t.className="rcopy",t.title=(0,Fe.Tl)("Copy answer"),t.textContent="⧉"` |
| `.ract` | régénérer / copier / modifier | `@2588327` `n.className="ract",n.title=(0,Fe.Tl)("Regenerate"),n.textContent="↻"` |
| `.rth`, `.rth summary`, `.rth-c` | bloc « Réflexion » (`<details>`) | `@2587603` `e.className="rth",e.style.display="none";…t.textContent="🧠 "+(0,Fe.Tl)("Thinking…");…n.className="rth-c"` |
| `.rtools`, `.tool`, `.tool.running/.ok/.bad` | traces d'outils | `@2589007` `e.className="rtools"` · `@2589154` `a.className="tool "+("running"===t.state?"running":"ok"===t.state?"ok":"bad")` |
| `.rtools details/summary/pre` | détails/résultat d'un outil | `const e=document.createElement("details"),i=document.createElement("summary");i.textContent=(0,Fe.Tl)("Details");const r=document.createElement("pre")` |
| `.tool-error` | erreur d'outil | `@2590129` `e.className="tool-error"` |
| `.rs`, `.rs-sum`, `.rs-list` | sources (`<details>`) | `@2600622` `const t=document.createElement("details");t.className="rs";…n.className="rs-sum",n.textContent=(0,Fe.Tl)("Sources")+" ("…` |
| `.rs-excerpt`, `.rs-badge` | extrait / badge « Web » | `@2601514` `e.className="rs-excerpt"` · `@2601260` `e.className="rs-badge",e.textContent=(0,Fe.Tl)("Web")` |
| `.rfu`, `.rfu-btn` | suggestions de relance | `@2601681` `t.className="rfu",e.followups.forEach(e=>{const n=document.createElement("button");n.type="button",n.className="rfu-btn"` |
| `.rconfirm`, `.rconfirm--danger` | demande de confirmation d'action | `@2590871` `className="rconfirm"` · `@2590955` `classList.add("rconfirm--danger")` |
| `.rtrimmed` | avertissement « historique tronqué » | `@2586960` `className="rtrimmed"` |
| `.chatform` | zone de saisie (`<form>`) | `@2584850` `const W=document.createElement("form");W.className="chatform"` |
| `#chatinput` / `.chatform input` | champ de saisie (`<input type=text>`) | `@2584850` `const V=document.createElement("input");V.id="chatinput",V.type="text"` |
| `.cbtn-files` | bouton pièces jointes 📎 | `$.className="cbtn cbtn-ghost cbtn-files",$.textContent="📎"` |
| `.cbtn:not(.cbtn-ghost)` | bouton d'envoi | `q.type="submit",q.className="cbtn",q.textContent=(0,Fe.Tl)("Send message")` |
| `.cbtn-stop` | même bouton pendant la génération | `@2603975` `ne=e=>{q.type=e?"button":"submit",q.textContent=e?(0,Fe.Tl)("Stop"):(0,Fe.Tl)("Send message"),q.classList.toggle("cbtn-stop",e)}` |
| `.cbtn-ghost:not(.cbtn-files)` | bouton « Run in background » | `G.className="cbtn cbtn-ghost",G.textContent=(0,Fe.Tl)("Run in background")` ; ordre `W.append($,V,q,G)` |
| `.err` | message d'erreur sous la saisie | `U.className="err",U.style.display="none",e.append(_,L,W,U)` |
| `.app-navigation`, `.app-navigation-entry` | barre latérale (NcAppNavigation) | `@2257675` `class:(0,f.C4)(["app-navigation",{"app-navigation--closed":…` · CSS `@137627` `.app-navigation-entry:not(.app-navigation-entry--legacy).active…` |
| `.new-chat-container`, `.new-chat-button` | bouton « New chat » | CSS `@769835` `.new-chat-container[data-v-36902323]{…}` · `@2624076` `(0,a.bF)($,{class:"new-chat-button",variant:"primary"…` |
| `.chat-list-heading` | intitulés de groupes (Chats, dossiers, archives) | `@2624471+` `(0,a.CE)("li",{key:0,class:(0,a.C4)(["chat-list-heading",{"chat-list-heading--archived":…` |
| `.chat-item--nested` | conversation dans un dossier | `@2625893` `class:(0,a.C4)({"chat-item--nested":t.nested})` |

**Total : 50 noms de classe / id distincts**, tous trouvés dans le bundle (contrôle automatique :
chaque nom utilisé dans `eva-chatgpt.css` a au moins une occurrence exacte dans `main.js`).

## Structure DOM d'un message (déduite du code)

```
.chat-log
└─ .rm.user | .rm.assistant
   ├─ .rb                    (style en ligne : fond + couleur)
   │  ├─ details.rth         (assistant seulement, caché tant qu'il n'y a pas de réflexion)
   │  │  ├─ summary          « 🧠 Thinking… »
   │  │  └─ .rth-c
   │  ├─ .rt                 (HTML Markdown)
   │  └─ .racts              (.rcopy / .ract)
   ├─ .rtools > .tool.{running|ok|bad} > span, details>summary+pre, .tool-error
   ├─ details.rs > summary.rs-sum + .rs-list > .rs-item > a|.rs-plain, .rs-badge, .rs-host, .rs-excerpt
   ├─ .rfu > button.rfu-btn
   └─ .rconfirm / .rconfirm-link
```

## Non stylé, faute de sélecteur fiable ou par choix

- **Écran d'accueil** (`.home-view`, `.prompt-card`…) et **vues Documents / Réglages / Métriques / Runs** :
  hors du chat, laissés tels quels.
- **Fenêtre « Personnaliser »** (`.customize-overlay`, `.customize-box`) : le code l'ajoute directement
  dans `<body>` (`t.append(n),document.body.appendChild(t)`), donc hors de `#content-vue`. La cibler
  demanderait un sélecteur global qui pourrait toucher le reste de Nextcloud. Non stylée.
- **Mode admin** (`.eva-ai-admin`) : exclu volontairement.
- **Vue « contexte fichier »** (`FileContextChatView`, chunk `327` chargé à part) : absente du bundle
  analysé. Si elle réutilise `.chatview-root` sous `#content-vue.eva-ai-app`, elle hérite du style,
  sinon elle reste d'origine **[non vérifié]**.
- **Coloration syntaxique** : le rendu Markdown d'eva ne colore pas le code. La seule occurrence de
  `hljs` dans le bundle vient du composant texte riche de @nextcloud/vue, pas du chat. Seul le
  conteneur des blocs de code est stylé.
