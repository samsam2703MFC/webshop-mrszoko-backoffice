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
for dest in "mrszoko/shop" "mrszoko/backoffice/_ds/mister-szoko/tokens"; do
  mkdir -p "$dest/fonts"
  cp -f "$DS"/fonts/*.woff2 "$dest/fonts/"
done
cp -f "$DS/tokens/fonts.css" "mrszoko/landing/tokens/fonts.css"
cp -f "$DS/tokens/fonts.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/fonts.css"
