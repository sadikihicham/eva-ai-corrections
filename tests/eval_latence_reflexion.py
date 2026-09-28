"""Coût de la réflexion (enable_thinking) sur des réponses COMPLÈTES, avec le vrai payload d'eva.

Mesure, réflexion désactivée puis activée :
  - Q8 étape 2 : réponse finale après un VRAI résultat de l'outil web_search d'eva (données publiques) ;
  - Q1, Q2, Q7 de la recette : réponses directes (aucun outil attendu).
Aucune écriture nulle part : appels API seulement (clé de test jetable).

Usage : CLE_TEST_EVA_CORRECTIONS=sk-… python3 eval_latence_reflexion.py <payload.json> <resultat-recherche.json>
"""
import copy
import json
import os
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from eval_recherche_web import LITELLM, QUESTIONS, _opener  # noqa: E402

RECETTE = {
    "Q1": "Explique en deux phrases ce qu'est Nextcloud.",
    "Q2": "Rédige un courriel court et poli pour reporter une réunion de mardi à jeudi, même heure.",
    "Q7": "Compare, dans un tableau, trois façons d'organiser les dossiers partagés d'un service de 20 personnes "
          "(par projet, par client, par année), avec avantages, inconvénients et une recommandation.",
}


def appel(opener, cle, messages, tools, reflexion):
    corps = {"model": "qwen3-30b-agent", "messages": messages, "tools": tools, "temperature": 0.1,
             "stream": False, "max_tokens": 4000}
    if reflexion:
        corps["chat_template_kwargs"] = {"enable_thinking": True}
    req = urllib.request.Request(LITELLM, data=json.dumps(corps).encode(),
                                  headers={"Authorization": f"Bearer {cle}", "Content-Type": "application/json"})
    t = time.time()
    with opener.open(req, timeout=300) as r:
        d = json.load(r)
    m = d["choices"][0]["message"]
    raisonnement = m.get("reasoning_content") or m.get("reasoning") or ""
    return {"s": round(time.time() - t, 1), "raisonnement": len(raisonnement), "texte": m.get("content") or "",
            "outils": [c["function"]["name"] for c in (m.get("tool_calls") or [])],
            "jetons": d.get("usage", {}).get("completion_tokens")}


if __name__ == "__main__":
    cle = os.environ.get("CLE_TEST_EVA_CORRECTIONS")
    if not cle or len(sys.argv) != 3:
        raise SystemExit(__doc__)
    base = json.load(open(sys.argv[1]))
    resultat = json.load(open(sys.argv[2]))
    opener = _opener()

    cas = {}
    # Q8 étape 2 : question → appel web_search → vrai résultat → réponse finale
    m8 = copy.deepcopy(base["messages"])
    m8 += [{"role": "assistant", "content": "", "tool_calls": [{"id": "call_q8", "type": "function", "function": {
               "name": "web_search", "arguments": json.dumps({"query": "Nextcloud latest major release"})}}]},
           {"role": "tool", "tool_call_id": "call_q8", "content": json.dumps(resultat, ensure_ascii=False)}]
    cas["Q8 (réponse finale)"] = m8
    for k, q in RECETTE.items():
        m = copy.deepcopy(base["messages"])
        m[1]["content"] = m[1]["content"].replace(QUESTIONS[0], q)
        cas[k] = m

    for nom, msgs in cas.items():
        for reflexion in (False, True):
            r = appel(opener, cle, msgs, base["tools"], reflexion)
            print(f"{nom:<22} réflexion={'oui' if reflexion else 'non'} : {r['s']:>5} s | {r['jetons']} jetons "
                  f"| raisonnement {r['raisonnement']} car. | outils={r['outils']} "
                  f"| « {r['texte'][:110].replace(chr(10), ' ')} »")
