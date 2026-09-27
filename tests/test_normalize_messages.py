"""Test LOCAL (Mac), rien n'est modifié sur .99 ni .39 : reproduit le défaut « HTTP 400 » observé en
production (27/09 16:51–22:01) avec le format EXACT qu'eva_ai construit (RagService::canonicalToolCalls,
lu sur workspace4), puis vérifie que le format corrigé (OpenAICompatible::normalizeMessages, branche
corrige-outils-vllm) est accepté par le vrai vLLM (.39), via un appel API normal — aucune écriture,
aucune configuration touchée.

`normaliser_avant`/`normaliser_apres` sont un portage fidèle en Python de la méthode PHP « avant » et
« après » de src/OpenAICompatible.php (mêmes noms, même logique) : la classe réelle dépend de Nextcloud
(IClientService, IConfig…) et ne peut pas être instanciée hors du serveur, donc la logique est reproduite
ici pour un test isolé, puis validée par un vrai appel réseau au modèle.

Usage : python3 test_normalize_messages.py
"""
import copy
import hashlib
import json
import os
import ssl
import tempfile
import urllib.error
import urllib.request

LITELLM = "https://192.168.1.39:8543/v1/chat/completions"
# Certificat auto-signé de .39 (CN=infinityai02), sans autorité connue du Mac. Même logique que
# Nextcloud (`occ security:certificates:import`, cf. journal-migration.md) : on épingle CE
# certificat exact — récupéré ici, son empreinte comparée à celle déjà vérifiée le 27/09/2026
# (identique lue depuis .99 et depuis .39) — plutôt que de désactiver la vérification.
_EMPREINTE_ATTENDUE = "8A:C6:53:16:FC:11:8E:04:36:F7:2B:D9:DE:F8:A1:78:45:1C:7D:D8:D2:73:4D:D7:9C:99:3D:C4:FE:A2:28:06"


def _opener_epingle():
    pem = ssl.get_server_certificate(("192.168.1.39", 8543))
    empreinte = hashlib.sha256(ssl.PEM_cert_to_DER_cert(pem)).hexdigest().upper()
    empreinte = ":".join(empreinte[i:i + 2] for i in range(0, len(empreinte), 2))
    if empreinte != _EMPREINTE_ATTENDUE:
        raise SystemExit(f"ARRET : empreinte de .39 = {empreinte} ≠ attendue — possible interception, test annulé")
    with tempfile.NamedTemporaryFile("w", suffix=".pem", delete=False) as f:
        f.write(pem)
        cafile = f.name
    ctx = ssl.create_default_context(cafile=cafile)
    return urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx))


_OPENER = _opener_epingle()
# Clé de TEST dédiée (test-eva-corrections, LiteLLM, expire 2h), jamais la clé « eva-nextcloud »
# de production que Claude n'a jamais vue et ne verra jamais.
CLE = os.environ.get("CLE_TEST_EVA_CORRECTIONS")


def normaliser_avant(messages):
    """Format qu'eva_ai envoie AUJOURD'HUI (RagService::canonicalToolCalls) : arguments en objet,
    pas de tool_call_id sur le message role=tool. Reproduit tel quel, sans correction."""
    return copy.deepcopy(messages)


def normaliser_apres(messages):
    """Portage Python de la méthode corrigée (src/OpenAICompatible.php, branche corrige-outils-vllm)."""
    out = []
    pending_id = None
    for m in copy.deepcopy(messages):
        if m.get("role") == "assistant" and m.get("tool_calls"):
            for call in m["tool_calls"]:
                call["id"] = call.get("id") or "call_test"
                call["type"] = "function"
                args = call["function"].get("arguments", {})
                call["function"]["arguments"] = args if isinstance(args, str) else json.dumps(args, ensure_ascii=False)
                pending_id = call["id"]
        elif m.get("role") == "tool":
            if pending_id and not m.get("tool_call_id"):
                m["tool_call_id"] = pending_id
            pending_id = None
        out.append(m)
    return out


# Fixture : forme RÉELLE construite par RagService::canonicalToolCalls (id + type déjà présents,
# arguments en objet décodé) pour Q3 (« Quels sont mes rendez-vous de cette semaine ? »), telle que
# lue dans le code d'eva_ai le 27/09/2026 — pas une hypothèse.
MESSAGES = [
    {"role": "system", "content": "You are EVA, a helpful assistant inside Nextcloud. Answer in the same language as the user."},
    {"role": "user", "content": "Quels sont mes rendez-vous de cette semaine ?"},
    {"role": "assistant", "content": "", "tool_calls": [
        {"id": "call_ab12cd34", "type": "function",
         "function": {"name": "list_calendar_events", "arguments": {"start": "2026-09-29", "end": "2026-10-05"}}}
    ]},
    {"role": "tool", "content": json.dumps({"events": []}, ensure_ascii=False)},  # pas de tool_call_id : défaut réel
]
TOOLS = [{"type": "function", "function": {"name": "list_calendar_events", "description": "List calendar events.",
          "parameters": {"type": "object", "properties": {"start": {"type": "string"}, "end": {"type": "string"}}}}}]


def appeler(nom, messages):
    payload = {"model": "qwen3-30b-agent", "messages": messages, "tools": TOOLS, "max_tokens": 200}
    req = urllib.request.Request(LITELLM, data=json.dumps(payload).encode(),
                                  headers={"Authorization": f"Bearer {CLE}", "Content-Type": "application/json"})
    try:
        with _OPENER.open(req, timeout=60) as r:
            d = json.load(r)
            print(f"✅ {nom} : HTTP 200 — réponse : {d['choices'][0]['message'].get('content', '')[:200]!r}")
            return True
    except urllib.error.HTTPError as e:
        corps = e.read().decode(errors="replace")[:300]
        print(f"❌ {nom} : HTTP {e.code} — {corps}")
        return False


if __name__ == "__main__":
    if not CLE:
        raise SystemExit("Définir CLE_TEST_EVA_CORRECTIONS (export CLE_TEST_EVA_CORRECTIONS=sk-…) avant de lancer ce test.")
    print("== reproduction du défaut (format actuel d'eva_ai) ==")
    avant_echoue = not appeler("avant (non corrigé)", normaliser_avant(MESSAGES))
    print("\n== vérification de la correction (branche corrige-outils-vllm) ==")
    apres_reussit = appeler("après (corrigé)", normaliser_apres(MESSAGES))
    print(f"\nRésultat : défaut reproduit = {avant_echoue} · correction validée = {apres_reussit}")
    raise SystemExit(0 if (avant_echoue and apres_reussit) else 1)
