"""Mesure : 4B (Ollama, .38) contre 30B (vLLM, .39) dans les conditions réelles d'eva (28/09/2026).

Question de l'admin : « Rapide 4B par défaut, Expert 30B au choix ». Avant de coder ce choix, on mesure sur les
mêmes questions, avec le vrai prompt système d'eva et ses 78 outils : durée, outil appelé, réponse correcte.

Configurations :
  30b        qwen3-30b-agent via LiteLLM (.39) — réglage actuel du compte de l'admin
  4b-rapide  qwen3.5:4b via Ollama (.38), réflexion désactivée (think=false) — le « Rapide » demandé
  4b-actuel  qwen3.5:4b via Ollama (.38), réflexion par défaut — ce qu'ont aujourd'hui les autres utilisateurs

Usage (clé LiteLLM de test dans un fichier, jamais affichée) :
    python3 tests/eval_modeles.py <payload.json> <fichier-cle> [--ctx 122880]
Le payload est le vrai payload d'eva (prompt système + outils) reconstitué en lecture seule ; il n'est pas versionné.
Lecture seule : aucune écriture, aucun outil n'est exécuté (on regarde seulement la DÉCISION du modèle).
"""
import argparse, hashlib, json, re, ssl, tempfile, time, urllib.request

LITELLM = "https://192.168.1.39:8543/v1/chat/completions"
EMPREINTE = "8AC65316FC118E0436F72BD9DEF8A178451C7DD8D2734DD79C993DC4FEA22806"
OLLAMA = "http://192.168.1.38:11434/api/chat"

# (question, outils acceptés — None = aucun outil attendu, "ar" = réponse en arabe attendue)
CAS = [
    ("Explique en deux phrases ce qu'est Nextcloud.", None),
    ("Combien font 12 fois 7 ?", None),
    ("Quels sont mes rendez-vous de demain ?", {"list_calendar_events"}),
    ("creer un fichier excel pour me lister mes rondevous de cette semaine", {"list_calendar_events", "create_file"}),
    ("Crée un fichier idees.md avec 3 idées pour améliorer l'accueil des clients", {"create_file"}),
    ("Quelle est la dernière version stable de PHP ?", {"web_search"}),
    ("اكتب فقرة قصيرة تشرح فوائد مشاركة الملفات بشكل آمن داخل الشركة.", "ar"),
    ("Combien de mails non lus ai-je ?", {"unread_mail_count", "list_mails", "search_mails"}),
]


def ouvreur_epingle():
    pem = ssl.get_server_certificate(("192.168.1.39", 8543))
    if hashlib.sha256(ssl.PEM_cert_to_DER_cert(pem)).hexdigest().upper() != EMPREINTE:
        raise SystemExit("ARRÊT : certificat de .39 inattendu")
    f = tempfile.NamedTemporaryFile("w", suffix=".pem", delete=False)
    f.write(pem); f.close()
    return ssl.create_default_context(cafile=f.name)


def post(url, corps, entetes, ctx=None, delai=300):
    req = urllib.request.Request(url, data=json.dumps(corps).encode(), headers={"Content-Type": "application/json", **entetes})
    return json.load(urllib.request.urlopen(req, context=ctx, timeout=delai))


def appel(config, systeme, outils, question, cle, ctx_tls, num_ctx):
    msgs = [systeme, {"role": "user", "content": question}]
    t = time.time()
    if config == "30b":
        m = post(LITELLM, {"model": "qwen3-30b-agent", "messages": msgs, "tools": outils}, {"Authorization": "Bearer " + cle}, ctx_tls)["choices"][0]["message"]
        noms = [c["function"]["name"] for c in (m.get("tool_calls") or [])]
        return time.time() - t, noms, m.get("content") or "", 0
    corps = {"model": "qwen3.5:4b", "messages": msgs, "tools": outils, "stream": False, "keep_alive": "5m",
             "options": {"num_ctx": num_ctx, "temperature": 0}}
    if config == "4b-rapide":
        corps["think"] = False
    r = post(OLLAMA, corps, {})
    m = r["message"]
    noms = [c["function"]["name"] for c in (m.get("tool_calls") or [])]
    return time.time() - t, noms, m.get("content") or "", len(m.get("thinking") or "")


def juge(attendu, noms, texte):
    if attendu is None:
        return not noms and len(texte.strip()) > 0
    if attendu == "ar":
        lettres = re.findall(r"\w", texte)
        return not noms and lettres and sum("؀" <= c <= "ۿ" for c in lettres) / len(lettres) > 0.6
    return bool(set(noms) & attendu)


def main():
    a = argparse.ArgumentParser()
    a.add_argument("payload"); a.add_argument("cle"); a.add_argument("--ctx", type=int, default=122880)
    a.add_argument("--configs", default="30b,4b-rapide,4b-actuel")
    args = a.parse_args()
    p = json.load(open(args.payload)); cle = open(args.cle).read().strip(); ctx_tls = ouvreur_epingle()
    systeme, outils = p["messages"][0], p["tools"]
    # Chauffe : le 1er appel au 4B charge le modèle en mémoire ; mesuré à part, exclu des résultats.
    t = time.time(); appel("4b-rapide", systeme, outils, "Bonjour", cle, ctx_tls, args.ctx)
    print(f"chauffe 4B (chargement compris) : {time.time() - t:.1f} s\n")
    bilan = {}
    for config in args.configs.split(","):
        for question, attendu in CAS:
            try:
                d, noms, texte, pensee = appel(config, systeme, outils, question, cle, ctx_tls, args.ctx)
                ok = juge(attendu, noms, texte)
            except Exception as e:  # une panne compte comme un échec, visible
                d, noms, texte, pensee, ok = 0.0, [], f"ERREUR {e}", 0, False
            bilan.setdefault(config, []).append((d, ok))
            print(f"{config:10} {'✅' if ok else '❌'} {d:5.1f} s | réflexion {pensee:5} car. | outils {noms or '-'} | "
                  f"{question[:45]} | {texte[:70].replace(chr(10), ' ')}")
            time.sleep(1.5)
        print()
    print("BILAN (médiane des durées, justes / total)")
    for config, r in bilan.items():
        durees = sorted(d for d, _ in r)
        print(f"  {config:10} médiane {durees[len(durees) // 2]:5.1f} s | max {durees[-1]:5.1f} s | justes {sum(o for _, o in r)}/{len(r)}")


if __name__ == "__main__":
    main()
