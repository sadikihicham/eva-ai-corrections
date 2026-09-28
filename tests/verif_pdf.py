#!/usr/bin/env python3
"""Vérification externe des PDF produits par ActionExecutor::buildPdf (eva_ai).

Contrôle indépendant du code PHP : structure (en-tête %PDF-1.4, table xref dont chaque offset pointe sur
« N 0 obj », startxref, trailer /Size /Root, /Length exact et décompression de chaque flux, /Count = nombre
de pages, polices standard uniquement), puis lecture par poppler (pdfinfo, pdftotext) : toute « Syntax Error »
signalée par poppler sur stderr compte comme un échec.

Usage :
  python3 tests/verif_pdf.py fichier.pdf [autre.pdf ...]          # vérifie des PDF déjà sur disque
  python3 tests/verif_pdf.py --extraire sortie.txt dossier/       # découpe la sortie de test_pdf.php lancé
                                                                   # avec PDF_B64=1, écrit les PDF, les vérifie
Options : --texte N  (lignes de pdftotext affichées par fichier, défaut 25 ; 0 = aucune, -1 = tout)
Code retour : 0 si tout est bon, 1 sinon.
"""
import base64
import os
import re
import shutil
import subprocess
import sys
import zlib

POLICES_STANDARD = {b"Helvetica", b"Helvetica-Bold", b"Courier"}


def outil(nom):
    return shutil.which(nom) or (f"/opt/homebrew/bin/{nom}" if os.path.exists(f"/opt/homebrew/bin/{nom}") else None)


def structure(data):
    """Retourne (erreurs, nb_pages, flux_décompressés)."""
    err = []
    if not data.startswith(b"%PDF-1.4\n"):
        err.append("en-tête %PDF-1.4 absent")
    m = re.search(rb"startxref\n(\d+)\n%%EOF\n?$", data)
    if not m:
        return err + ["startxref / %%EOF final introuvable"], 0, []
    xref = int(m.group(1))
    if data[xref:xref + 5] != b"xref\n":
        return err + [f"startxref={xref} ne pointe pas sur 'xref'"], 0, []
    m = re.compile(rb"xref\n0 (\d+)\n").match(data, xref)
    if not m:
        return err + ["en-tête de sous-section xref illisible"], 0, []
    taille = int(m.group(1))
    pos = m.end()
    if data[pos:pos + 20] != b"0000000000 65535 f \n":
        err.append("entrée 0 de la xref incorrecte")
    objets = {}
    for i in range(1, taille):
        e = data[pos + 20 * i:pos + 20 * i + 20]
        me = re.fullmatch(rb"(\d{10}) (\d{5}) n \n", e)
        if not me:
            err.append(f"entrée xref {i} mal formée : {e!r}")
            continue
        off = int(me.group(1))
        tete = b"%d 0 obj\n" % i
        if data[off:off + len(tete)] != tete:
            err.append(f"offset de l'objet {i} ({off}) ne pointe pas sur '{i} 0 obj' mais sur {data[off:off + 12]!r}")
            continue
        fin = data.find(b"\nendobj\n", off)
        if fin < 0:
            err.append(f"objet {i} sans endobj")
            continue
        objets[i] = data[off + len(tete):fin]
    apres = pos + 20 * taille
    if data[apres:apres + 8] != b"trailer\n":
        err.append("'trailer' absent juste après la table xref")
    mt = re.search(rb"trailer\n<<(.*?)>>\nstartxref", data[apres:], re.S)
    if not mt:
        err.append("dictionnaire trailer illisible")
    else:
        ms = re.search(rb"/Size (\d+)", mt.group(1))
        mr = re.search(rb"/Root (\d+) 0 R", mt.group(1))
        if not ms or int(ms.group(1)) != taille:
            err.append(f"/Size du trailer ≠ {taille}")
        if not mr or b"/Type /Catalog" not in objets.get(int(mr.group(1)), b""):
            err.append("/Root ne désigne pas un /Catalog")
    nb_pages = 0
    for i, corps in objets.items():
        mc = re.search(rb"/Type /Pages .*?/Count (\d+)", corps)
        if mc:
            nb_pages = int(mc.group(1))
    nb_objets_page = sum(1 for c in objets.values() if re.match(rb"<< /Type /Page ", c))
    if nb_objets_page != nb_pages:
        err.append(f"/Count={nb_pages} mais {nb_objets_page} objets /Page")
    for c in objets.values():
        for police in re.findall(rb"/BaseFont /([A-Za-z-]+)", c):
            if police not in POLICES_STANDARD:
                err.append(f"police inattendue : {police!r}")
    flux = []
    for i, corps in objets.items():
        ms = re.match(rb"<< /Length (\d+)( /Filter /FlateDecode)? >>\nstream\n", corps)
        if not ms:
            continue
        n = int(ms.group(1))
        brut = corps[ms.end():ms.end() + n]
        if corps[ms.end() + n:] != b"\nendstream":
            err.append(f"/Length faux pour l'objet {i}")
            continue
        try:
            flux.append(zlib.decompress(brut) if ms.group(2) else brut)
        except zlib.error as e:
            err.append(f"flux {i} non décompressable : {e}")
    return err, nb_pages, flux


def verifie(chemin, lignes_texte):
    with open(chemin, "rb") as f:
        data = f.read()
    err, pages, flux = structure(data)
    info = ""
    pdfinfo, pdftotext = outil("pdfinfo"), outil("pdftotext")
    if pdfinfo:
        r = subprocess.run([pdfinfo, chemin], capture_output=True)
        m = re.search(rb"Pages:\s+(\d+)", r.stdout)
        if r.returncode != 0 or r.stderr.strip():
            err.append("pdfinfo : " + r.stderr.decode("utf-8", "replace").strip())
        elif not m or int(m.group(1)) != pages:
            err.append(f"pdfinfo compte {m.group(1).decode() if m else '?'} pages, /Count={pages}")
        info = " / ".join(l.decode("utf-8", "replace") for l in r.stdout.splitlines() if l.startswith((b"Page size", b"PDF version")))
    texte = ""
    if pdftotext:
        r = subprocess.run([pdftotext, "-layout", "-enc", "UTF-8", chemin, "-"], capture_output=True)
        if r.returncode != 0 or r.stderr.strip():
            err.append("pdftotext : " + r.stderr.decode("utf-8", "replace").strip())
        texte = r.stdout.decode("utf-8", "replace")
    else:
        err.append("pdftotext introuvable (brew install poppler)")
    etat = "✅" if not err else "❌"
    print(f"{etat} {os.path.basename(chemin)} : {len(data)} octets, {pages} page(s), {len(flux)} flux{' — ' + info if info else ''}")
    for e in err:
        print(f"     → {e}")
    if lignes_texte and texte:
        lignes = [l for l in texte.splitlines() if l.strip()]
        montrees = lignes if lignes_texte < 0 else lignes[:lignes_texte]
        for l in montrees:
            print("     | " + l.rstrip())
        if len(montrees) < len(lignes):
            print(f"     | … ({len(lignes) - len(montrees)} lignes de plus)")
    return not err


def extraire(sortie, dossier):
    """Découpe les blocs « === nom.pdf === » + base64 imprimés par test_pdf.php (PDF_B64=1)."""
    os.makedirs(dossier, exist_ok=True)
    with open(sortie, encoding="utf-8", errors="replace") as f:
        contenu = f.read()
    chemins = []
    for nom, b64 in re.findall(r"^=== ([\w.-]+\.pdf) ===\n(.*?)(?=^=== )", contenu, re.S | re.M):
        chemin = os.path.join(dossier, nom)
        with open(chemin, "wb") as f:
            f.write(base64.b64decode("".join(b64.split()), validate=True))
        chemins.append(chemin)
    if "=== FIN ===" not in contenu:
        print("❌ marqueur « === FIN === » absent : sortie tronquée ?")
    return chemins


def main(args):
    lignes_texte = 25
    if "--texte" in args:
        i = args.index("--texte")
        lignes_texte = int(args[i + 1])
        del args[i:i + 2]
    if args[:1] == ["--extraire"]:
        if len(args) != 3:
            print(__doc__)
            return 2
        chemins = extraire(args[1], args[2])
        if not chemins:
            print("❌ aucun PDF trouvé dans la sortie")
            return 1
    else:
        chemins = args
    if not chemins:
        print(__doc__)
        return 2
    resultats = [verifie(c, lignes_texte) for c in chemins]
    ok = sum(resultats)
    print(f"\nRÉSULTAT : {ok}/{len(resultats)} PDF valides")
    return 0 if ok == len(resultats) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
