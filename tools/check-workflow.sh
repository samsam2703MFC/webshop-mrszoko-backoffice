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
RC=$?

# ---------------------------------------------------------------------------
#  Deuxième vérification : les scripts envoyés par SSH sont-ils du bash VALIDE ?
#
#  Ce qui s'est passé. Un commentaire PHP contenait « quelqu\'un ». Ce bloc PHP
#  vit à l'intérieur d'une chaîne shell entre quotes simples, où le backslash
#  ne protège RIEN : la première apostrophe referme la commande, et la suite
#  part au parseur bash. Résultat sur le serveur :
#
#      bash: line 70: unexpected EOF while looking for matching `)'
#
#  Le déploiement est mort à mi-course, APRÈS avoir déjà copié les fichiers.
#  Ni YAML, ni actionlint, ni la mesure de taille ci-dessus ne voient ça —
#  pour eux le bloc est du texte. Seul bash sait lire du bash.
#
#  On extrait donc chaque corps « << 'REMOTE' … REMOTE » et on le passe à
#  `bash -n` : analyse syntaxique seule, aucune commande exécutée.
# ---------------------------------------------------------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
python3 - "$F" "$TMP" <<'PY'
import io, sys, os, re
f, tmp = sys.argv[1], sys.argv[2]
L = io.open(f, encoding='utf-8').read().split('\n')
n = 0
i = 0
while i < len(L):
    if re.search(r"<<\s*'REMOTE'\s*$", L[i]):
        corps, i = [], i + 1
        while i < len(L) and L[i].strip() != 'REMOTE':
            corps.append(L[i])
            i += 1
        # Le heredoc est indenté dans le YAML ; bash reçoit le texte dédenté
        # de la même façon, donc on retire le préfixe commun.
        marge = min((len(l) - len(l.lstrip()) for l in corps if l.strip()), default=0)
        n += 1
        io.open(os.path.join(tmp, f'remote{n}.sh'), 'w', encoding='utf-8').write(
            '\n'.join(l[marge:] if len(l) >= marge else l for l in corps) + '\n')
    i += 1
print(n)
PY
NB=$(ls "$TMP" 2>/dev/null | wc -l)
BAD=0
for s in "$TMP"/remote*.sh; do
  [ -e "$s" ] || continue
  ERR=$(bash -n "$s" 2>&1) || { BAD=1; echo "  $(basename "$s") : $ERR"; }
done
if [ "$BAD" != "0" ]; then
  echo "REFUSÉ — un script envoyé par SSH ne se parse pas. Il mourrait EN COURS de déploiement."
  echo "         Cause la plus fréquente : une apostrophe dans un bloc php -r '…'."
  exit 1
fi
echo "OK — les $NB scripts distants se parsent (bash -n)."

# ── CHAQUE ÉTAPE SSH DOIT PORTER SON MOT DE PASSE ────────────────────────────
#
# Une étape ajoutée en recopiant un gabarit d'un AUTRE dépôt nommait
# DEPLOY_USER / DEPLOY_HOST / DEPLOY_PORT — qui n'existent pas ici — et
# oubliait le bloc « env: SSHPASS ». Résultat au déploiement 92 : sshpass a
# affiché son MODE D'EMPLOI, la cible valait « @ », et le déploiement est
# tombé sans qu'aucune vérification n'ait été jouée. Rien dans le fichier ne
# pouvait le voir : le YAML est valide, le script distant se parse.
#
# On vérifie donc l'appariement lui-même : autant de blocs « SSHPASS: » que
# d'appels à sshpass, et aucun nom de secret étranger au dépôt.
NBS=$(grep -c 'sshpass -e ssh' "$F")
NBE=$(grep -c 'SSHPASS: ' "$F")
if [ "${NBS:-0}" -lt 1 ]; then
  echo "REFUSÉ — aucune étape sshpass trouvée : le contrôle ne contrôle rien."
  exit 1
fi
if [ "$NBS" != "$NBE" ]; then
  echo "REFUSÉ — $NBS étapes appellent sshpass mais $NBE déclarent SSHPASS."
  echo "         Celle qui manque affichera le mode d'emploi de sshpass au lieu de vérifier."
  exit 1
fi
echo "OK — les $NBS étapes SSH déclarent toutes leur mot de passe."

ETR=$(grep -oE 'secrets\.(DEPLOY_USER|DEPLOY_HOST)|vars\.DEPLOY_PORT' "$F" | sort -u | tr '\n' ' ')
if [ -n "$ETR" ]; then
  echo "REFUSÉ — noms de secrets étrangers à ce dépôt : $ETR"
  echo "         Ici c'est secrets.SSH_USER / SSH_HOST / SSH_PASSWORD et vars.SSH_PORT."
  exit 1
fi
echo "OK — aucun nom de secret étranger."
exit $RC
