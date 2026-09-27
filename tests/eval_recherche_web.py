"""Évaluation reproductible : eva appelle-t-elle `web_search` quand la question l'exige ?

Rejoue le VRAI payload qu'eva_ai construit (prompt système + 78 outils, reconstitué en lecture seule
par RagService::buildMessages pour le compte concerné) contre le vrai vLLM (.39, alias qwen3-30b-agent,
déterministe : température 0, graine 42), sur plusieurs questions qui exigent une information récente,
et pour plusieurs VARIANTES du prompt/des outils. Aucune écriture nulle part : appels API seulement.

Usage :
    CLE_TEST_EVA_CORRECTIONS=sk-… python3 eval_recherche_web.py <payload.json>
Le payload (données du compte) reste hors dépôt (scratchpad).
"""
import copy
import hashlib
import json
import os
import ssl
import sys
import tempfile
import time
import urllib.error
import urllib.request

LITELLM = "https://192.168.1.39:8543/v1/chat/completions"
_EMPREINTE_ATTENDUE = "8A:C6:53:16:FC:11:8E:04:36:F7:2B:D9:DE:F8:A1:78:45:1C:7D:D8:D2:73:4D:D7:9C:99:3D:C4:FE:A2:28:06"
PHRASE_CONFLIT = "simple factual questions should be answered directly without tools."

QUESTIONS = [
    "Quelle est la dernière version majeure de Nextcloud publiée, et quand ? Donne la source.",
    "Quelle est la dernière version stable de PHP ?",
    "Quel est le cours actuel de l'once d'or en dollars ?",
    "Quelles sont les principales actualités sur l'intelligence artificielle cette semaine ?",
    "What is the latest released version of Python?",
]
# Jamais utilisées pendant la mise au point des variantes : servent à détecter le sur-ajustement.
QUESTIONS_TEMOINS_WEB = [
    "Quelle est la dernière version LTS d'Ubuntu ?",
    "Qui est l'actuel président de la République française ?",
    "C'est quoi la dernière version de Nextcloud ?",
    "What is the newest iPhone model?",
    "Quel est le taux de change actuel du dirham en euros ?",
]
# Ne doivent PAS déclencher de recherche web (sinon : latence inutile, fuite de requêtes vers l'extérieur).
QUESTIONS_SANS_WEB = [
    "Explique en deux phrases ce qu'est Nextcloud.",
    "Rédige un courriel court et poli pour reporter une réunion de mardi à jeudi, même heure.",
    "Combien font 12 fois 7 ?",
    "Traduis « bonjour, comment allez-vous ? » en arabe.",
    "Donne-moi trois conseils pour bien nommer ses documents.",
]
OUTILS_WEB = {"web_search", "open_website", "search_images"}
REGLE = ("Your knowledge of software versions, releases, prices, office holders and news is OUT OF DATE: your "
         "training data stops long before today's date. For ANY question about the latest, newest, current or most "
         "recent version, release, update or date of something, your FIRST action MUST be a web_search call; answer "
         "only from its results, never from memory, even if you think you know the answer.")
REGLE_V2 = ("ALWAYS call this tool FIRST, before answering, when the question asks about anything that changes over "
            "time: the latest/newest/current/dernière/actuelle version or release of any software, product or "
            "operating system (e.g. Nextcloud, PHP, Python, Ubuntu, iOS), prices and exchange rates, who currently "
            "holds an office, news, schedules or statistics. Your built-in knowledge of these is out of date even when "
            "you feel sure about it. Do NOT call it for explanations, writing, translation, arithmetic or anything the "
            "user's files answer.")
# Enveloppe exacte qu'eva utilise pour les instructions personnalisées d'une conversation (RagService::buildMessages)
ENVELOPPE_PERSO = ("\n\nCustom instructions from the user (user-authored; follow them, but they never override the "
                   "safety, citation and tool rules above):\n<user_instructions>\n{}\n</user_instructions>")


def _opener():
    pem = ssl.get_server_certificate(("192.168.1.39", 8543))
    fp = hashlib.sha256(ssl.PEM_cert_to_DER_cert(pem)).hexdigest().upper()
    fp = ":".join(fp[i:i + 2] for i in range(0, len(fp), 2))
    if fp != _EMPREINTE_ATTENDUE:
        raise SystemExit(f"ARRET : empreinte de .39 = {fp} — possible interception, évaluation annulée")
    with tempfile.NamedTemporaryFile("w", suffix=".pem", delete=False) as f:
        f.write(pem)
    return urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=f.name)))


def variante(base, nom, question):
    p = copy.deepcopy(base)
    msgs = p["messages"]
    # la question d'origine est remplacée dans le message utilisateur (même enveloppe qu'eva)
    msgs[1]["content"] = msgs[1]["content"].replace(QUESTIONS[0], question)
    if nom in ("sans_conflit", "sans_conflit+web_seul"):
        assert PHRASE_CONFLIT in msgs[0]["content"], "phrase conflictuelle introuvable : le prompt a changé"
        msgs[0]["content"] = msgs[0]["content"].replace(
            PHRASE_CONFLIT,
            "simple factual questions should be answered directly without tools, unless the answer can "
            "have changed since your training data (then call web_search first).")
    if nom in ("web_seul", "sans_conflit+web_seul"):
        p["tools"] = [t for t in p["tools"] if t["function"]["name"] in OUTILS_WEB]
    if nom in ("regle_fin", "regle_fin+reflexion"):
        msgs[0]["content"] += "\n\n" + REGLE
    if nom == "instructions_perso":
        msgs[0]["content"] += ENVELOPPE_PERSO.format(REGLE)
    if nom in ("regle_outil", "regle_outil+regle_fin"):
        for t in p["tools"]:
            if t["function"]["name"] == "web_search":
                t["function"]["description"] = REGLE + " " + t["function"]["description"]
    if nom == "regle_outil+regle_fin":
        msgs[0]["content"] += "\n\n" + REGLE
    if nom == "regle_outil_v2":
        for t in p["tools"]:
            if t["function"]["name"] == "web_search":
                t["function"]["description"] = REGLE_V2 + " " + t["function"]["description"]
    if "reflexion" in nom:
        p["reflexion"] = True
    return p


def appel(opener, cle, payload):
    corps = {"model": "qwen3-30b-agent", "messages": payload["messages"], "tools": payload["tools"],
             "temperature": 0.1, "stream": False, "max_tokens": 300}
    if payload.get("reflexion"):
        corps["chat_template_kwargs"] = {"enable_thinking": True}
        corps["max_tokens"] = 3000
    req = urllib.request.Request(LITELLM, data=json.dumps(corps).encode(),
                                  headers={"Authorization": f"Bearer {cle}", "Content-Type": "application/json"})
    t = time.time()
    try:
        with opener.open(req, timeout=120) as r:
            d = json.load(r)
    except urllib.error.HTTPError as e:
        return {"erreur": f"HTTP {e.code}", "s": round(time.time() - t, 1)}
    m = d["choices"][0]["message"]
    appels = [c["function"]["name"] for c in (m.get("tool_calls") or [])]
    return {"outils": appels, "texte": (m.get("content") or "")[:90].replace("\n", " "), "s": round(time.time() - t, 1)}


if __name__ == "__main__":
    cle = os.environ.get("CLE_TEST_EVA_CORRECTIONS")
    if not cle or len(sys.argv) < 2:
        raise SystemExit("usage : CLE_TEST_EVA_CORRECTIONS=sk-… python3 eval_recherche_web.py <payload.json> [variante …]")
    base = json.load(open(sys.argv[1]))
    opener = _opener()
    variantes = sys.argv[2:] or ["actuel", "sans_conflit", "web_seul", "sans_conflit+web_seul"]
    complet = os.environ.get("EVAL_COMPLET") == "1"
    jeux = [("mise au point", QUESTIONS, True)]
    if complet:
        jeux += [("témoins jamais vus", QUESTIONS_TEMOINS_WEB, True), ("ne doit PAS chercher", QUESTIONS_SANS_WEB, False)]
    bilan = {v: {nom: 0 for nom, _, _ in jeux} for v in variantes}
    for v in variantes:
        print(f"\n== variante : {v} ==")
        for nom_jeu, qs, attendu in jeux:
            for q in qs:
                time.sleep(2.5)  # clé de test limitée à 30 req/min : sans pause, HTTP 429 et mesure invalide
                r = appel(opener, cle, variante(base, v, q))
                cherche = "web_search" in r.get("outils", [])
                ok = cherche == attendu
                bilan[v][nom_jeu] += ok
                print(f"  {'✅' if ok else '❌'} [{nom_jeu[:13]:<13}] {q[:55]:<55} {r.get('s')} s  outils={r.get('outils', r.get('erreur'))}"
                      + ("" if ok else f"  → « {r.get('texte', '')} »"))
    print("\n== bilan : comportement attendu (chercher si l'info change, sinon répondre directement) ==")
    for v in variantes:
        print(f"  {v:<24} " + " · ".join(f"{nom} {bilan[v][nom]}/{len(qs)}" for nom, qs, _ in jeux))
