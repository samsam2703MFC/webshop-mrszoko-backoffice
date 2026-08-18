#!/usr/bin/env bash
# Recopie la source unique (design-system) vers les trois surfaces qui la
# servent. Appelé par fetch-fonts.sh et par le déploiement : ainsi la boutique,
# la landing et la console ne peuvent pas diverger.
set -euo pipefail
cd "$(dirname "$0")/.."
DS="mrszoko/design-system"
# La landing n'est plus qu'une redirection vers la boutique : elle ne charge
# aucune feuille de style. Y copier 268 Ko de polices serait du poids mort dans
# le dépôt ET dans le site déployé. Sa copie de fonts.css reste synchronisée —
# c'est le miroir du design system — mais sans les binaires.
# --delete par la main : `cp -f` ajoute et écrase, il n'ENLÈVE jamais. Le jour
# où une famille quitte la marque — Plus Jakarta Sans aujourd'hui, DM Serif
# Display avant elle — ses fichiers restaient dans les trois copies, et le
# dépôt continuait de les porter alors que plus une feuille ne les nommait.
for dest in "mrszoko/shop" "mrszoko/backoffice/_ds/mister-szoko/tokens"; do
  mkdir -p "$dest/fonts"
  for f in "$dest"/fonts/*.woff2; do
    [ -e "$f" ] || continue
    [ -e "$DS/fonts/$(basename "$f")" ] || { rm -f "$f"; echo "  retiré (plus dans la marque) : $f"; }
  done
  cp -f "$DS"/fonts/*.woff2 "$dest/fonts/"
done
cp -f "$DS/tokens/fonts.css" "mrszoko/landing/tokens/fonts.css"
cp -f "$DS/tokens/fonts.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/fonts.css"

# typography.css SUIT LE MÊME CHEMIN, et ne le suivait pas. Trois copies
# identiques vivaient côte à côte sans que rien ne les tienne ensemble :
# changer la famille dans le design system laissait la console et la landing
# sur l'ancienne, et le site portait deux typographies sans que personne ne
# sache laquelle était la bonne.
cp -f "$DS/tokens/typography.css" "mrszoko/landing/tokens/typography.css"
cp -f "$DS/tokens/typography.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/typography.css"
echo "typographie et polices : trois surfaces alignées sur $DS"
