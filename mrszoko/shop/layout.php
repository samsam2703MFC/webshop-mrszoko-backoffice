<?php
// ============================================================================
//  layout.php — enveloppe commune des pages de la boutique.
//
//  Sur la performance, trois décisions se voient dans ce fichier :
//   · la police est demandée par un <link> précédé de deux <link preconnect>,
//     pas par un @import enfoui dans une feuille — sinon le navigateur ne la
//     découvre qu'après avoir téléchargé toute la chaîne d'imports ;
//   · les deux feuilles de style sont sur la même origine et se chargent en
//     parallèle (aucune n'en importe une autre) ;
//   · le JavaScript est en `defer` et ne sert qu'au confort : la page est
//     déjà complète et cliquable sans lui.
// ============================================================================


/**
 * @param string $page  premier segment de route ('' = catalogue) — décide si
 *                      la page entre dans un index. Voir seo.php, règle 1 :
 *                      panier, caisse et suivi de commande n'y entrent jamais.
 * @param string $image adresse absolue d'une vignette de partage, si la page
 *                      en a une (la photo du produit sur une fiche).
 */
function layout_head(array $S, string $lang, array $langs, string $title = '',
                     string $desc = '', string $page = '', string $image = ''): void {
    $title = $title !== '' ? $title . ', ' . ($S['brand'] ?? 'Mister Szoko') : ($S['meta.title'] ?? 'Mister Szoko');
    $desc  = $desc !== '' ? $desc : ($S['meta.desc'] ?? '');
    ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="icon" type="image/png" href="<?= e(u('assets/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('shop.css')) ?>">
<?php
// Robots, canonique, grappe hreflang ABSOLUE et partage social. Les hreflang
// émis ici auparavant étaient relatifs : Google les ignore en bloc, sans un
// mot dans la Search Console. Ils n'ont donc jamais rien fait.
seo_head($page, $lang, $langs, $title, $desc, WSM_SHOP_DEFAULT_LANG, $image);
?>
</head>
<!-- La confirmation d'ajout est traduite en base (product.added) ; sans cet
     attribut, shop.js retombait sur un simple « ✓ » et la traduction ne
     servait à rien. -->
<body data-added="<?= e($S['product.added'] ?? '') ?>">
<?php
}

/**
 * Fil d'Ariane. Deux raisons d'exister, aucune décorative :
 *  • sur un téléphone, il donne le chemin du retour sans dépendre du bouton
 *    « précédent » — celui-ci renvoie à la liste de résultats d'un moteur de
 *    recherche, pas au catalogue ;
 *  • balisé en schema.org, il est repris tel quel par Google sous le titre,
 *    ce qui remplace une URL illisible par « Sklep › Katalog › Produit ».
 *
 * @param array $items  [libellé => href|null], le dernier étant la page courante
 */
function layout_crumbs(array $items): void {
    if (!$items) return;
    $last = array_key_last($items);
    $pos = 0;
    echo '<nav class="crumbs" aria-label="' . e('Ścieżka') . '" itemscope itemtype="https://schema.org/BreadcrumbList">';
    foreach ($items as $label => $href) {
        $pos++;
        if ($pos > 1) echo '<span class="sepc" aria-hidden="true">›</span>';
        echo '<span itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        if ($href !== null && $label !== $last) {
            echo '<a itemprop="item" href="' . e((string) $href) . '"><span itemprop="name">' . e((string) $label) . '</span></a>';
        } else {
            echo '<span itemprop="name" aria-current="page">' . e((string) $label) . '</span>';
        }
        echo '<meta itemprop="position" content="' . $pos . '"></span>';
    }
    echo '</nav>';
}

function layout_header(array $S, string $lang, array $langs, int $cartCount): void {
    // Les huit langues du projet. Le code court sert d'étiquette ; « UA »
    // et non « UK », parce qu'un visiteur ukrainien lit UK comme britannique.
    $codeLabel = ['pl' => 'PL', 'en' => 'EN', 'uk' => 'UA', 'de' => 'DE',
                  'fr' => 'FR', 'cs' => 'CS', 'sk' => 'SK', 'hu' => 'HU'];
    $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? u()), '?');
    ?>
<header class="site-head">
  <div class="wrap head-in">
    <a class="head-logo" href="<?= e(u()) ?>" aria-label="<?= e($S['a11y.home'] ?? '') ?>">
      <img src="<?= e(u('assets/logo.png')) ?>" alt="<?= e($S['brand'] ?? 'Mister Szoko') ?>" width="150" height="44">
    </a>
    <nav class="head-nav" aria-label="<?= e($S['a11y.nav'] ?? '') ?>">
      <a class="navlink" href="<?= e(u()) ?>#katalog"><?= e($S['nav.shop'] ?? '') ?></a>
      <a class="navlink" href="<?= e(u()) ?>#pro"><?= e($S['story.pro.eyebrow'] ?? '') ?></a>
    </nav>
    <div class="head-right">
      <div class="langs" role="group" aria-label="<?= e($S['a11y.lang'] ?? '') ?>">
        <?php foreach ($langs as $l): ?>
        <a href="<?= e($self . '?lang=' . $l) ?>" lang="<?= e($l) ?>"<?= $l === $lang ? ' aria-current="true"' : '' ?>><?= e($codeLabel[$l] ?? strtoupper($l)) ?></a>
        <?php endforeach; ?>
      </div>
      <a class="cart-btn" href="<?= e(u('koszyk')) ?>" aria-label="<?= e($S['a11y.cart'] ?? '') ?>">
        <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg>
        <span class="cart-label"><?= e($S['nav.cart'] ?? '') ?></span>
        <span class="cart-count<?= $cartCount ? '' : ' is-zero' ?>" data-cart-count><?= $cartCount ?></span>
      </a>
    </div>
  </div>
</header>
<?php
}

function layout_footer(array $S): void {
    ?>
<footer class="site-foot">
  <div class="wrap foot-in">
    <div>
      <img class="foot-logo" src="<?= e(u('assets/logo.png')) ?>" alt="<?= e($S['brand'] ?? '') ?>" width="120" height="36" loading="lazy">
      <p class="mono"><?= e($S['footer.tagline'] ?? '') ?></p>
      <?php // Vente à distance en Pologne : raison sociale, adresse du siège et
            // numéros d'immatriculation doivent figurer sur le site. ?>
      <?php if (($S['seller.name'] ?? '') !== ''): ?>
      <p class="mono seller">
        <span class="seller-label"><?= e($S['seller.legal'] ?? '') ?></span>
        <?= e($S['seller.name']) ?><br>
        <?= e($S['seller.address'] ?? '') ?><br>
        <?= e($S['seller.ids'] ?? '') ?>
      </p>
      <?php endif; ?>
      <p class="mono"><?= e(date('Y')) ?> · <?= e($S['brand'] ?? '') ?> · <?= e($S['footer.rights'] ?? '') ?></p>
    </div>
    <nav class="foot-links mono" aria-label="<?= e($S['footer.contact'] ?? '') ?>">
      <?php if (($S['footer.email'] ?? '') !== ''): ?>
      <a href="mailto:<?= e($S['footer.email']) ?>"><?= e($S['footer.email']) ?></a>
      <?php endif; ?>
      <a href="<?= e(u('kontakt')) ?>"><?= e($S['nav.contact'] ?? '') ?></a>
      <a href="<?= e(shop_base() . '/../backoffice/') ?>"><?= e($S['footer.console'] ?? '') ?></a>
    </nav>
  </div>
</footer>
<script src="<?= e(asset('shop.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Bandeau d'erreur ou de succès, réutilisé par toutes les pages. */
function notice(string $kind, string $text): void {
    if ($text === '') return;
    echo '<p class="notice notice--' . e($kind) . '">' . e($text) . '</p>';
}
