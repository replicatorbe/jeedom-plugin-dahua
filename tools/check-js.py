#!/usr/bin/env python3
"""Signale les identifiants utilisés hors de la portée où ils sont déclarés.

Il n'y a pas de node sur la machine de développement, et un contrôle
d'équilibrage des accolades ne voit pas ce genre d'erreur : une variable
déclarée dans une fonction et utilisée dans une autre passe tous les contrôles
syntaxiques, mais interrompt le script au chargement et rend la page du plugin
inutilisable.

L'analyse est volontairement simple : elle découpe le fichier en portées de
fonction et vérifie que chaque identifiant est déclaré dans sa portée ou dans
une portée englobante. Elle ne remplace pas un vrai analyseur, mais elle
attrape la classe de bug ci-dessus.

Usage : python3 tools/check-js.py [fichier.js ...]
Sortie : code 1 s'il reste des identifiants hors portée.
"""

import re
import glob
import os
import sys

# Globales du navigateur, mots-clés, et helpers fournis par le coeur de Jeedom.
CONNUS = set("""
document window console navigator location history alert confirm
setTimeout clearTimeout setInterval clearInterval requestAnimationFrame
Object Date Math JSON Array String Number Boolean RegExp Error Promise Map Set
encodeURIComponent decodeURIComponent parseInt parseFloat isNaN isFinite
true false null undefined this arguments
function var let const if else return for while switch case break continue new
typeof delete in of try catch finally throw do instanceof void class extends
async await yield default
jeedom jeedomUtils domUtils jeeFrontEnd jeeDialog isset init is_numeric is_object
""".split())

MOT = re.compile(r'(?<![.\w$])([A-Za-z_$][\w$]*)')


def neutraliser(source):
    """Remplace commentaires et chaînes par du vide de même longueur.

    Conserver les positions est indispensable : les portées sont délimitées par
    des index dans le texte d'origine.
    """
    out = list(source)
    i, n = 0, len(source)
    while i < n:
        c = source[i]
        if c == '/' and i + 1 < n and source[i + 1] == '*':
            j = source.find('*/', i + 2)
            j = n if j == -1 else j + 2
            for k in range(i, j):
                if out[k] != '\n':
                    out[k] = ' '
            i = j
        elif c == '/' and i + 1 < n and source[i + 1] == '/':
            j = source.find('\n', i)
            j = n if j == -1 else j
            for k in range(i, j):
                out[k] = ' '
            i = j
        elif c in '"\'`':
            j = i + 1
            while j < n and source[j] != c:
                j += 2 if source[j] == '\\' else 1
            j = min(j + 1, n)
            for k in range(i, j):
                if out[k] != '\n':
                    out[k] = ' '
            i = j
        else:
            i += 1
    return ''.join(out)


class Portee:
    def __init__(self, debut, fin, parent):
        self.debut, self.fin, self.parent = debut, fin, parent
        self.noms = set()
        self.enfants = []

    def contient(self, pos):
        return self.debut <= pos < self.fin

    def declare(self, nom):
        p = self
        while p is not None:
            if nom in p.noms:
                return True
            p = p.parent
        return False


def corps(code, ouvrante):
    """Index de fin du bloc ouvert par l'accolade à `ouvrante`."""
    profondeur, i, n = 0, ouvrante, len(code)
    while i < n:
        if code[i] == '{':
            profondeur += 1
        elif code[i] == '}':
            profondeur -= 1
            if profondeur == 0:
                return i + 1
        i += 1
    return n


def construire(code):
    """Arbre des portées de fonction, du fichier vers les fonctions imbriquées."""
    racine = Portee(0, len(code), None)
    portees = [racine]

    for m in re.finditer(r'\bfunction\b\s*([A-Za-z_$][\w$]*)?\s*\(([^)]*)\)\s*\{', code):
        nom, params, ouvrante = m.group(1), m.group(2), m.end() - 1
        fin = corps(code, ouvrante)

        parent = racine
        for p in portees:
            if p.contient(m.start()) and p.fin - p.debut < parent.fin - parent.debut:
                parent = p
        if nom:
            parent.noms.add(nom)            # une fonction nommée existe dans sa portée parente

        portee = Portee(m.start(), fin, parent)
        portee.noms |= {p.strip() for p in params.split(',') if p.strip()}
        parent.enfants.append(portee)
        portees.append(portee)

    # `var` est à portée de fonction : on l'attache à la fonction englobante.
    for m in re.finditer(r'\b(?:var|let|const)\s+([A-Za-z_$][\w$]*)', code):
        cible = racine
        for p in portees:
            if p.contient(m.start()) and p.fin - p.debut < cible.fin - cible.debut:
                cible = p
        cible.noms.add(m.group(1))

    return racine, portees


def analyser(chemin):
    source = open(chemin, encoding='utf-8').read()
    code = neutraliser(source)
    racine, portees = construire(code)

    problemes = []
    for m in MOT.finditer(code):
        nom, pos = m.group(1), m.start()
        if nom in CONNUS:
            continue
        # Clé d'objet littéral, ou propriété nommée : pas une référence.
        if code[m.end():m.end() + 2].lstrip().startswith(':'):
            continue

        courante = racine
        for p in portees:
            if p.contient(pos) and p.fin - p.debut < courante.fin - courante.debut:
                courante = p
        if not courante.declare(nom):
            ligne = source.count('\n', 0, pos) + 1
            problemes.append((nom, ligne))

    if not problemes:
        print(f'{chemin} : aucun identifiant hors portée')
        return True

    vus = set()
    print(f'{chemin} : identifiant(s) hors portée')
    for nom, ligne in problemes:
        if nom not in vus:
            vus.add(nom)
            print(f'  {nom}  (première occurrence ligne {ligne})')
    return False


if __name__ == '__main__':
    # Sans argument, TOUS les fichiers JS du plugin, découverts à l'exécution.
    # Une liste écrite en dur avait laissé desktop/js/alerts.js hors contrôle le
    # jour de sa création : l'outil répondait « aucun identifiant hors portée »
    # sans avoir regardé le fichier neuf, ce qui est pire que pas d'outil du
    # tout. Le tri rend la sortie stable d'une exécution à l'autre.
    racine = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    fichiers = sys.argv[1:] or sorted(glob.glob(os.path.join(racine, 'desktop', 'js', '*.js')))
    if not fichiers:
        print('aucun fichier JS trouvé')
        sys.exit(1)
    sys.exit(0 if all([analyser(f) for f in fichiers]) else 1)
