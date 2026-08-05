#!/usr/bin/env bash
# ============================================================================
#  check-workflow.sh — la vérification que ni YAML ni actionlint ne font.
#
#  GitHub refuse un fichier de workflow dont UNE expression dépasse 21 000
#  caractères. Le fichier est alors rejeté À L'ANALYSE : la course est créée
#  puis tuée dans la seconde, avec ZÉRO job et la conclusion « failure », et
#  le message n'apparaît nulle part dans l'interface Actions. Il est arrivé
#  deux fois sur ce dépôt, et les deux fois le diagnostic a coûté plus cher
#  que la correction.
#
#  Un bloc « run: | » compte comme une expression. Ce script les mesure.
#
#  Usage :  tools/check-workflow.sh [fichier]
# ============================================================================
set -u
F="${1:-.github/workflows/deploy.yml}"
LIMITE=21000
ALERTE=17000

python3 - "$F" "$LIMITE" "$ALERTE" <<'PY'
import io, sys
f, limite, alerte = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
L = io.open(f, encoding='utf-8').read().split('\n')
st = [i for i, l in enumerate(L) if l.strip().startswith('- name:')] + [len(L)]
pire = 0
sortie = 0
for a, b in zip(st, st[1:]):
    n = len('\n'.join(L[a:b]))
    nom = L[a].strip()[8:60]
    pire = max(pire, n)
    if n > limite:
        print(f"  {n:>6}  {nom}   ← DÉPASSE {limite} : GitHub REJETTERA le fichier")
        sortie = 1
    elif n > alerte:
        print(f"  {n:>6}  {nom}   ← proche de la limite, scinder bientôt")
    elif n > 3000:
        print(f"  {n:>6}  {nom}")
print()
if sortie:
    print(f"REFUSÉ — scinde l'étape en deux avant de pousser.")
else:
    print(f"OK — la plus grosse expression fait {pire} caractères (limite {limite}).")
sys.exit(sortie)
PY
