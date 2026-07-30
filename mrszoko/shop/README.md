# shop — la boutique Mister Szoko

`https://<serveur>/mrszoko/shop/` — catalogue, fiche produit, panier, caisse,
confirmation. Cinq pages, trois langues (pl / uk / en), aucun libellé en dur.

## Pourquoi c'est du PHP et pas une application cliente

Parce qu'une boutique doit s'afficher, pas se charger.

Chaque page arrive **déjà remplie** : les produits, les prix et les textes sont
dans la première réponse HTML, lus en base par `index.php`. Il n'y a rien à
attendre — pas de fetch, pas de JSON, pas d'écran vide pendant un aller-retour.
Le déploiement le vérifie : il compte les fiches produit et les prix présents
dans le HTML initial et échoue s'il en manque.

Trois décisions vont dans le même sens :

- **`tokens.css` est assemblé, pas importé.** Une chaîne de `@import` oblige le
  navigateur à télécharger une feuille pour découvrir la suivante — quatre
  allers-retours avant le premier caractère à l'écran. Le fichier est régénéré
  au déploiement depuis `design-system/tokens/`, il ne peut donc pas diverger.
- **La police est demandée par un `<link>`**, précédé de deux `preconnect`, au
  lieu d'être cachée au fond d'une feuille de style.
- **`shop.js` est en `defer` et n'est que du confort.** Sans lui, tout marche :
  ajouter au panier, changer une quantité, commander. Ce sont de vrais
  formulaires POST. Le JavaScript évite les rechargements, il ne les remplace pas.

## Le panier

Un cookie `ms_cart`, contenant uniquement des identifiants et des quantités.
**Jamais de prix.** Un cookie est modifiable par celui qui le porte ; ce qu'on
en lit est borné (40 lignes, 99 unités) et tout le reste est relu en base à
chaque affichage. Le serveur reste seul juge du montant — voir
`../backoffice/php-api/shop.php` et la preuve dans `tests/e2e_shop.php`.

Le formulaire de commande porte un jeton anti-CSRF comparé avec `hash_equals`.

## Le contenu

`content_seed.json` est la **source unique** : il alimente `wsm_shop_i18n`,
`wsm_shipping_methods` et les colonnes vitrine de `wsm_products`. Ensuite la
base fait foi et la console peut tout éditer. Un déploiement ajoute les
libellés nouveaux (`migrate.php --sync-content`) sans écraser les retouches.

Le fichier n'est pas servi au public (`.htaccess`).

## En local

```bash
cd ../backoffice/php-api && php migrate.php --fresh   # base + seed
cd ../../shop && ./serve.sh                           # → http://localhost:8091/
```

`router.php` et `serve.sh` reproduisent ce que fait `.htaccess` en production
(le serveur intégré de PHP ignore les `.htaccess`). Ni l'un ni l'autre n'est
déployé.

## Ce qui n'y est pas encore

- **Les photos.** Faute de photographie produit, les vignettes sont des dégradés
  aux couleurs de chaque chocolat. Dès que `wsm_products.image_url` est
  renseignée en base, l'image prend la place — aucune modification de code.
- **L'appel réel à tpay et InPost** attend des identifiants. Le code est écrit
  et testé ; sans identifiants il est fermé, pas approximatif : aucune
  transaction créée, aucune notification acceptée.
- **Le sélecteur de Paczkomat sur carte** (Geowidget InPost) : le code du point
  se saisit pour l'instant à la main, avec vérification du format.
