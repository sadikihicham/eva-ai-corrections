#!/usr/bin/env python3
"""Renomme EVA/Eva en « Infinity AI » dans les TEXTES visibles de l'app eva_ai (identifiant eva_ai inchangé).
PHP/templates : seulement dans les chaînes (et le HTML hors <?php ?>), jamais dans le code ni les commentaires.
JS compilé / l10n : mots entiers, avec exclusions (déclencheur Talk « Eva », « @Eva », marqueurs internes).
Usage : renommer.py <racine> <fichier relatif>...   (réécrit en place, affiche les fichiers modifiés)"""
import re, sys, os

NOM = 'Infinity AI'
MOT = re.compile(r'(?<![\w$@.\-/])(?:(?:EVA|Eva)(?:[\s\-]AI)(?![\w$])|(?:EVA|Eva)(?![\w$]))')
# Marqueurs internes relus par le code (historique enregistré), déclencheur et nom du bot Talk : inchangés.
PROTEGES = ['[Automatic check by EVA', '[EVA:', '\\[EVA:', 'bot_trigger:"Eva"', '$t("Eva")', '"@Eva"', "'talk_bot_trigger' => 'Eva'"]
# Chaînes PHP laissées telles quelles quand elles valent exactement ceci : dossier « EVA » des utilisateurs
# (données existantes), nom et déclencheur du bot Talk (un autre nom enregistrerait un second bot).
PHP_INTACTES = ('EVA', 'Eva')

def remplace_texte(t):
    return MOT.sub(NOM, t)

def remplace_protege(s):
    # met de côté les séquences protégées, remplace, puis les remet
    gardes = {}
    for i, p in enumerate(PROTEGES):
        cle = f'\x00{i}\x00'
        if p in s:
            s = s.replace(p, cle); gardes[cle] = p
    s = remplace_texte(s)
    for cle, p in gardes.items():
        s = s.replace(cle, p)
    return s

def php(src):
    out, i, n = [], 0, len(src)
    etat = 'html'
    while i < n:
        if etat == 'html':
            j = src.find('<?php', i); k = src.find('<?=', i)
            j = min([x for x in (j, k) if x != -1], default=-1)
            if j == -1: out.append(remplace_protege(src[i:])); break
            out.append(remplace_protege(src[i:j])); w = 5 if src.startswith('<?php', j) else 3
            out.append(src[j:j+w]); i = j + w; etat = 'code'; continue
        c = src[i]
        if src.startswith('?>', i): out.append('?>'); i += 2; etat = 'html'; continue
        if src.startswith('//', i) or c == '#' and not src.startswith('#[', i):
            j = i
            while j < n and src[j] != '\n' and not src.startswith('?>', j): j += 1
            out.append(src[i:j]); i = j; continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2); j = n if j == -1 else j + 2
            out.append(src[i:j]); i = j; continue
        if c in ('"', "'"):
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == '\\' else 1
            j = min(j + 1, n)
            inner = src[i+1:j-1]
            garde = inner in PHP_INTACTES or inner.startswith('EVA/')
            out.append(c + (inner if garde else remplace_protege(inner)) + c if j - i >= 2 else src[i:j]); i = j; continue
        m = re.match(r'<<<[ \t]*(["\']?)([A-Za-z_]\w*)\1\r?\n', src[i:])
        if m:
            fin = re.compile(r'\n[ \t]*' + m.group(2) + r'\b')
            f = fin.search(src, i + m.end() - 1)
            j = n if f is None else f.start()
            out.append(src[i:i+m.end()] + remplace_protege(src[i+m.end():j])); i = j; continue
        out.append(c); i += 1
    return ''.join(out)

# Seul le nom AFFICHÉ à l'utilisateur change (admin, 28/09) : pas les commandes occ, migrations, journaux, verrous ni
# exceptions internes (dont « Invalid EVA chat data », relu par str_contains).
DOSSIERS_INTERNES = ('lib/Command/', 'lib/Migration/')
LIGNE_INTERNE = re.compile(r'logger->|->(warning|info|error|debug|writeln)\(|acquireLock|Exception\(|str_contains\(\$message|/Producer')

def filtre_lignes(avant, apres):
    a, b = avant.split('\n'), apres.split('\n')
    if len(a) != len(b):
        raise SystemExit('nombre de lignes changé : ' + rel)
    return '\n'.join(x if (x != y and LIGNE_INTERNE.search(x)) else y for x, y in zip(a, b))

racine = sys.argv[1]
for rel in sys.argv[2:]:
    if rel.startswith(DOSSIERS_INTERNES) or not rel.endswith(('.php', '.js', '.json', '.xml')):
        continue
    p = os.path.join(racine, rel)
    s = open(p, encoding='utf-8').read()
    t = filtre_lignes(s, php(s)) if rel.endswith('.php') else remplace_protege(s)
    if t != s:
        open(p, 'w', encoding='utf-8').write(t); print(rel)
