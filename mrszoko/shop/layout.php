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

const WSM_FONT_HREF = 'https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Plus+Jakarta+Sans:wght@400..800&family=Mulish:ital,wght@0,300..800;1,400&family=DM+Mono:wght@400;500&display=swap';

function layout_head(array $S, string $lang, array $langs, string $title = '', string $desc = ''): void {
    $title = $title !== '' ? $title . ' — ' . ($S['brand'] ?? 'Mister Szoko') : ($S['meta.title'] ?? 'Mister Szoko');
    $desc  = $desc !== '' ? $desc : ($S['meta.desc'] ?? '');
    $self  = strtok((string) ($_SERVER['REQUEST_URI'] ?? u()), '?');
    ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="icon" type="image/png" href="<?= e(u('assets/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?= e(WSM_FONT_HREF) ?>">
<link rel="stylesheet" href="<?= e(u('tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(u('shop.css')) ?>">
<?php foreach ($langs as $l): ?>
<link rel="alternate" hreflang="<?= e($l) ?>" href="<?= e($self . '?lang=' . $l) ?>">
<?php endforeach; ?>
</head>
<body>
<?php
}

function layout_header(array $S, string $lang, array $langs, int $cartCount): void {
    $codeLabel = ['pl' => 'PL', 'uk' => 'UA', 'en' => 'EN'];
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
      <p class="mono"><?= e(date('Y')) ?> · <?= e($S['brand'] ?? '') ?> · <?= e($S['footer.rights'] ?? '') ?></p>
    </div>
    <nav class="foot-links mono" aria-label="<?= e($S['footer.contact'] ?? '') ?>">
      <?php if (($S['footer.email'] ?? '') !== ''): ?>
      <a href="mailto:<?= e($S['footer.email']) ?>"><?= e($S['footer.email']) ?></a>
      <?php endif; ?>
      <a href="<?= e(shop_base() . '/../backoffice/') ?>"><?= e($S['footer.console'] ?? '') ?></a>
    </nav>
  </div>
</footer>
<script src="<?= e(u('shop.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Bandeau d'erreur ou de succès, réutilisé par toutes les pages. */
function notice(string $kind, string $text): void {
    if ($text === '') return;
    echo '<p class="notice notice--' . e($kind) . '">' . e($text) . '</p>';
}
