<?php
// ============================================================================
//  index.php — contrôleur de la boutique Mister Szoko.
//
//  Cinq pages, toutes rendues côté serveur depuis la base :
//    /                      catalogue
//    /p/<slug>              fiche produit
//    /koszyk                panier
//    /kasa                  caisse
//    /zamowienie/<code>     confirmation et suivi
//
//  Les mutations du panier et la commande passent par de vrais formulaires
//  POST : la boutique reste utilisable si le JavaScript ne charge pas.
// ============================================================================
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// Les pages portent le panier et l'adresse de l'acheteur : jamais de cache partagé.
header('Cache-Control: private, no-cache, must-revalidate');

try {
    $pdo = wsm_bootstrap();
} catch (Throwable $ex) {
    http_response_code(503);
    exit('Sklep chwilowo niedostępny.');
}

// ---- Langue ----------------------------------------------------------------
$langs = wsm_shop_available_langs($pdo);
sort($langs);
$pref  = ['pl', 'uk', 'en'];                       // ordre d'affichage voulu
usort($langs, fn($a, $b) => array_search($a, $pref, true) <=> array_search($b, $pref, true));
$lang = pick_lang($pdo);
if (isset($_GET['lang'])) {
    remember_lang($lang);
    redirect(strtok((string) $_SERVER['REQUEST_URI'], '?') ?: u());
}
$S = wsm_shop_strings($pdo, $lang);

// ---- Jeton anti-CSRF -------------------------------------------------------
function csrf_token(): string {
    static $t = null;
    if ($t !== null) return $t;
    $t = (string) ($_COOKIE['ms_csrf'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        setcookie('ms_csrf', $t, ['expires' => time() + 86400, 'path' => shop_base() . '/',
            'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
    }
    return $t;
}
function csrf_field(): string {
    return '<input type="hidden" name="_t" value="' . e(csrf_token()) . '">';
}
function csrf_ok(): bool {
    $sent = (string) ($_POST['_t'] ?? '');
    $have = (string) ($_COOKIE['ms_csrf'] ?? '');
    return $sent !== '' && $have !== '' && hash_equals($have, $sent);
}

// Le jeton doit être émis AVANT le moindre octet de page : setcookie() écrit
// un en-tête, et un en-tête ne s'ajoute plus une fois le corps commencé.
// Appelé ici, il vaut pour tous les formulaires rendus plus bas.
csrf_token();

$seg    = route_segments();
$page   = $seg[0] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cart   = cart_read();

// ============================ ACTIONS (POST) ================================
if ($method === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request.'); }

    if ($page === 'koszyk' || $page === '' || $page === 'p') {
        if (isset($_POST['add'])) {
            $id  = (string) $_POST['add'];
            $qty = max(1, min(WSM_SHOP_MAX_QTY, (int) ($_POST['qty'] ?? 1)));
            $cart[$id] = min(WSM_SHOP_MAX_QTY, ($cart[$id] ?? 0) + $qty);
            cart_write($cart);
            redirect(u('koszyk'));
        }
        if (isset($_POST['remove'])) {
            unset($cart[(string) $_POST['remove']]);
            cart_write($cart);
            redirect(u('koszyk'));
        }
        if (isset($_POST['set']) && is_array($_POST['set'])) {
            foreach ($_POST['set'] as $id => $q) {
                $q = (int) $q;
                if ($q <= 0) unset($cart[(string) $id]);
                else $cart[(string) $id] = min(WSM_SHOP_MAX_QTY, $q);
            }
            cart_write($cart);
            redirect(u('koszyk'));
        }
        redirect(u('koszyk'));
    }

    // ---- Passage de commande ----------------------------------------------
    if ($page === 'kasa') {
        $body = $_POST;
        $body['items'] = cart_items($cart);
        $body['lang']  = $lang;
        $body['invoice'] = !empty($_POST['invoice']);
        $body['consent_terms'] = !empty($_POST['consent_terms']);

        [$order, $errors] = wsm_shop_create_order($pdo, $body);
        if ($errors) {
            $formErrors = $errors;
            $formValues = $_POST;
            // on retombe sur l'affichage de la caisse, plus bas
        } else {
            cart_write([]);
            $base = wsm_shop_base_url();
            $pay = wsm_tpay_start($pdo, $order,
                $base . '/zamowienie/' . rawurlencode($order['code']) . '?t=' . $order['access_token'],
                wsm_api_base_url() . '/shop/tpay/notify');
            // Si tpay a rendu une URL de paiement, on y envoie l'acheteur
            // directement — sinon il atterrit sur sa confirmation.
            if (($pay['redirect_url'] ?? '') !== '') redirect($pay['redirect_url']);
            redirect(u('zamowienie/' . rawurlencode($order['code']), ['t' => $order['access_token']]));
        }
    }
}

// ============================== RENDU (GET) =================================
$cartCount = cart_count($cart);

// ---------------------------------------------------------------- CATALOGUE -
if ($page === '') {
    $products = wsm_shop_products($pdo, $lang);
    layout_head($S, $lang, $langs);
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main>
  <section class="hero">
    <div class="wrap hero-in">
      <p class="eyebrow"><?= e($S['home.eyebrow'] ?? '') ?></p>
      <h1><?= e($S['home.title'] ?? '') ?></h1>
      <p class="lead"><?= e($S['home.lead'] ?? '') ?></p>
      <a class="btn btn--accent" href="#katalog"><?= e($S['home.cta'] ?? '') ?></a>
    </div>
    <?php if (isset($S['story.strip.1'])): ?>
    <div class="hero-strip">
      <div class="wrap hero-strip-in mono">
        <span><?= e($S['story.strip.1']) ?></span>
        <span><?= e($S['story.strip.2'] ?? '') ?></span>
        <span><?= e($S['story.strip.3'] ?? '') ?></span>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <section class="wrap promises">
    <?php for ($i = 1; $i <= 3; $i++): if (!isset($S["promise.$i.t"])) continue; ?>
    <div class="promise">
      <h2><?= e($S["promise.$i.t"]) ?></h2>
      <p><?= e($S["promise.$i.d"] ?? '') ?></p>
    </div>
    <?php endfor; ?>
  </section>

  <section class="wrap block" id="katalog">
    <div class="section-head">
      <h2><?= e($S['catalog.title'] ?? '') ?></h2>
      <p class="sub"><?= e($S['catalog.sub'] ?? '') ?></p>
    </div>
    <?php if (!$products): ?>
      <p class="muted"><?= e($S['catalog.empty'] ?? '') ?></p>
    <?php else: ?>
    <div class="grid">
      <?php foreach ($products as $p): ?>
      <article class="card">
        <a class="card-media" href="<?= e(u('p/' . $p['slug'])) ?>">
          <?= product_visual($p, 'card-photo') ?>
          <?php if ($p['badge'] !== ''): ?><span class="badge"><?= e($p['badge']) ?></span><?php endif; ?>
        </a>
        <div class="card-body">
          <p class="card-meta mono"><?= e($p['subtitle']) ?></p>
          <h3><a href="<?= e(u('p/' . $p['slug'])) ?>"><?= e($p['name']) ?></a></h3>
          <div class="card-buy">
            <span class="price"><?= e(zl($p['price'])) ?><small><?= e($S['price.vat_incl'] ?? '') ?></small></span>
            <?php if ($p['stock'] > 0): ?>
            <form method="post" action="<?= e(u('koszyk')) ?>" data-add>
              <?= csrf_field() ?>
              <input type="hidden" name="add" value="<?= e($p['id']) ?>">
              <input type="hidden" name="qty" value="1">
              <button class="btn btn--brand btn--sm" type="submit"><?= e($S['product.add'] ?? '') ?></button>
            </form>
            <?php else: ?>
            <span class="mono out"><?= e($S['product.stock_out'] ?? '') ?></span>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php // ---- Formats et prix dégressifs ---------------------------------- ?>
  <?php if (isset($S['story.formats.title'])): ?>
  <section class="block band" id="formaty">
    <div class="wrap">
      <div class="section-head">
        <p class="eyebrow accent"><?= e($S['story.formats.eyebrow'] ?? '') ?></p>
        <h2><?= e($S['story.formats.title']) ?></h2>
        <p class="sub"><?= e($S['story.formats.sub'] ?? '') ?></p>
      </div>
      <div class="formats">
        <?php for ($i = 1; isset($S["story.format.$i.size"]); $i++): ?>
        <div class="format">
          <p class="mono fk"><?= e($S["story.format.$i.kind"] ?? '') ?></p>
          <p class="fs"><?= e($S["story.format.$i.size"]) ?></p>
          <p><?= e($S["story.format.$i.note"] ?? '') ?></p>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php // ---- La pracownia (ce que la landing racontait) -------------------- ?>
  <?php if (isset($S['story.atelier.title'])): ?>
  <section class="block" id="pracownia">
    <div class="wrap section-head">
      <p class="eyebrow accent"><?= e($S['story.atelier.eyebrow'] ?? '') ?></p>
      <h2><?= e($S['story.atelier.title']) ?></h2>
      <p class="sub"><?= e($S['story.atelier.sub'] ?? '') ?></p>
      <p class="mono muted tagline"><?= e($S['story.atelier.tagline'] ?? '') ?></p>
    </div>
  </section>
  <?php endif; ?>

  <?php // ---- Panneau pro : compte B2B ------------------------------------- ?>
  <?php if (isset($S['story.pro.title'])):
    $mail = (string) ($S['footer.email'] ?? ''); ?>
  <section class="wrap block" id="pro" style="padding-top:0">
    <div class="pro">
      <div class="pro-in">
        <div>
          <p class="eyebrow"><?= e($S['story.pro.eyebrow'] ?? '') ?></p>
          <h2><?= e($S['story.pro.title']) ?></h2>
          <p><?= e($S['story.pro.text'] ?? '') ?></p>
        </div>
        <?php if ($mail !== ''): ?>
        <a class="btn btn--accent btn--lg"
           href="mailto:<?= e($mail) ?>?subject=<?= e(rawurlencode((string) ($S['story.pro.mail_subject'] ?? ''))) ?>">
          <?= e($S['story.pro.cta'] ?? '') ?>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------------ FICHE PRODUIT -
if ($page === 'p') {
    $p = wsm_shop_product($pdo, (string) ($seg[1] ?? ''), $lang);
    if (!$p) {
        http_response_code(404);
        layout_head($S, $lang, $langs, $S['product.unknown'] ?? '');
        layout_header($S, $lang, $langs, $cartCount);
        echo '<main class="wrap block"><h1>' . e($S['product.unknown'] ?? '') . '</h1>'
           . '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['product.back'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
    $related = array_values(array_filter(wsm_shop_products($pdo, $lang), fn($x) => $x['id'] !== $p['id']));
    $related = array_slice($related, 0, 4);
    $stockLabel = $p['stock'] <= 0 ? ($S['product.stock_out'] ?? '')
        : ($p['stock'] <= 10 ? ($S['product.stock_low'] ?? '') : ($S['product.stock_in'] ?? ''));

    layout_head($S, $lang, $langs, $p['name'], $p['desc']);
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <p><a class="back" href="<?= e(u()) ?>">← <?= e($S['product.back'] ?? '') ?></a></p>
  <div class="product">
    <div class="product-media"><?= product_visual($p, 'product-photo') ?></div>
    <div class="product-buy">
      <p class="mono eyebrow"><?= e($p['subtitle']) ?></p>
      <h1><?= e($p['name']) ?></h1>
      <p class="lead"><?= e($p['desc']) ?></p>
      <p class="price price--lg"><?= e(zl($p['price'])) ?><small><?= e($S['price.vat_incl'] ?? '') ?></small></p>
      <p class="mono muted"><?= e(zl($p['price_net'])) ?> <?= e($S['price.net'] ?? '') ?>
        · <?= e($S['product.' . ($p['stock'] <= 0 ? 'stock_out' : 'stock_in')] ?? '') ?></p>

      <?php if ($p['stock'] > 0): ?>
      <form class="buy-form" method="post" action="<?= e(u('koszyk')) ?>" data-add>
        <?= csrf_field() ?>
        <input type="hidden" name="add" value="<?= e($p['id']) ?>">
        <label class="qty">
          <span class="sr-only"><?= e($S['product.qty'] ?? '') ?></span>
          <input type="number" name="qty" value="1" min="1" max="<?= (int) min($p['stock'], WSM_SHOP_MAX_QTY) ?>" inputmode="numeric">
        </label>
        <button class="btn btn--accent btn--lg" type="submit"><?= e($S['product.add'] ?? '') ?></button>
      </form>
      <p class="mono muted stock"><?= e($stockLabel) ?></p>
      <?php else: ?>
      <p class="notice notice--warn"><?= e($S['product.stock_out'] ?? '') ?></p>
      <?php endif; ?>

      <dl class="specs">
        <?php if ($p['origin'] !== ''): ?><dt><?= e($S['product.origin'] ?? '') ?></dt><dd><?= e($p['origin']) ?></dd><?php endif; ?>
        <?php if ($p['cocoa'] !== ''): ?><dt><?= e($S['product.cocoa'] ?? '') ?></dt><dd><?= e($p['cocoa']) ?></dd><?php endif; ?>
        <?php if ($p['weight_g'] > 0): ?><dt><?= e($S['product.weight'] ?? '') ?></dt><dd><?= e(number_format($p['weight_g'] / 1000, 3, ',', ' ')) ?> kg</dd><?php endif; ?>
        <?php if ($p['sku'] !== ''): ?><dt><?= e($S['product.sku'] ?? '') ?></dt><dd class="mono"><?= e($p['sku']) ?></dd><?php endif; ?>
        <?php if ($p['ean'] !== ''): ?><dt><?= e($S['product.ean'] ?? '') ?></dt><dd class="mono"><?= e($p['ean']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <?php if ($related): ?>
  <section class="block">
    <div class="section-head"><h2><?= e($S['product.related'] ?? '') ?></h2></div>
    <div class="grid">
      <?php foreach ($related as $r): ?>
      <article class="card">
        <a class="card-media" href="<?= e(u('p/' . $r['slug'])) ?>"><?= product_visual($r, 'card-photo') ?></a>
        <div class="card-body">
          <p class="card-meta mono"><?= e($r['subtitle']) ?></p>
          <h3><a href="<?= e(u('p/' . $r['slug'])) ?>"><?= e($r['name']) ?></a></h3>
          <div class="card-buy"><span class="price"><?= e(zl($r['price'])) ?></span></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// -------------------------------------------------------------------- PANIER -
if ($page === 'koszyk') {
    $shipId = (string) ($_GET['dostawa'] ?? 'inpost_locker');
    [$q, $qErr] = wsm_shop_quote($pdo, cart_items($cart), $shipId, $lang);

    layout_head($S, $lang, $langs, $S['cart.title'] ?? '');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <h1><?= e($S['cart.title'] ?? '') ?></h1>
  <?php if (!$q['lines']): ?>
    <p class="muted"><?= e($S['cart.empty'] ?? '') ?></p>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
  <?php if (isset($qErr['stock'])) notice('warn', ($S['product.stock_out'] ?? '') . ' — ' . $qErr['stock']); ?>
  <div class="cart">
    <form class="cart-lines" method="post" action="<?= e(u('koszyk')) ?>">
      <?= csrf_field() ?>
      <?php foreach ($q['lines'] as $l): ?>
      <div class="cart-line">
        <a class="cart-thumb" href="<?= e(u('p/' . $l['slug'])) ?>">
          <?= product_visual(['image' => $l['image'], 'name' => $l['name'], 'from' => $l['from'], 'to' => $l['to'], 'cocoa' => '', 'unit' => ''], 'thumb') ?>
        </a>
        <div class="cart-info">
          <h2><a href="<?= e(u('p/' . $l['slug'])) ?>"><?= e($l['name']) ?></a></h2>
          <p class="mono muted"><?= e($l['unit']) ?> · <?= e(zl($l['unit_gross'])) ?> <?= e($S['price.vat_incl'] ?? '') ?></p>
        </div>
        <label class="qty">
          <span class="sr-only"><?= e($S['cart.qty'] ?? '') ?></span>
          <input type="number" name="set[<?= e($l['id']) ?>]" value="<?= (int) $l['qty'] ?>" min="0" max="<?= (int) WSM_SHOP_MAX_QTY ?>" inputmode="numeric">
        </label>
        <span class="cart-sum mono"><?= e(zl($l['line_gross'])) ?></span>
        <button class="linkbtn" type="submit" name="remove" value="<?= e($l['id']) ?>"><?= e($S['cart.remove'] ?? '') ?></button>
      </div>
      <?php endforeach; ?>
      <div class="cart-actions">
        <a class="btn btn--ghost" href="<?= e(u()) ?>"><?= e($S['cart.continue'] ?? '') ?></a>
        <?php // Sans JavaScript il faut un bouton pour appliquer les quantités ;
              // avec, shop.js soumet tout seul et ce bouton n'aurait rien à dire. ?>
        <noscript><button class="btn btn--ghost" type="submit"><?= e($S['cart.qty'] ?? '') ?> ↻</button></noscript>
      </div>
    </form>

    <aside class="summary">
      <h2><?= e($S['checkout.summary'] ?? '') ?></h2>
      <?php
        $m = $q['method'] ?? null;
        $toFree = $m && $m['free_from'] > 0 ? max(0, $m['free_from'] - $q['items_gross']) : 0;
        $pct = $m && $m['free_from'] > 0 ? min(100, (int) round($q['items_gross'] * 100 / $m['free_from'])) : 100;
      ?>
      <?php if ($m && $m['free_from'] > 0): ?>
      <div class="freeship">
        <p><?= $toFree > 0
            ? e(str_replace('{x}', zl($toFree), $S['cart.free_left'] ?? ''))
            : e($S['cart.free_ok'] ?? '') ?></p>
        <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
      </div>
      <?php endif; ?>

      <form class="ship-pick" method="get" action="<?= e(u('koszyk')) ?>">
        <?php foreach ($q['methods'] as $sm): ?>
        <label class="radio">
          <input type="radio" name="dostawa" value="<?= e($sm['id']) ?>"<?= $m && $sm['id'] === $m['id'] ? ' checked' : '' ?> onchange="this.form.submit()">
          <span><strong><?= e($sm['label']) ?></strong>
            <em class="mono"><?= $q['shipping_free'] && $m && $sm['id'] === $m['id'] ? e($S['cart.free'] ?? '') : e(zl($sm['price'])) ?></em>
            <small><?= e($sm['note']) ?></small></span>
        </label>
        <?php endforeach; ?>
        <noscript><button class="btn btn--ghost btn--sm" type="submit">↻</button></noscript>
      </form>

      <dl class="totals mono">
        <dt><?= e($S['cart.subtotal'] ?? '') ?></dt><dd><?= e(zl($q['items_gross'])) ?></dd>
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= $q['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($q['shipping_gross'])) ?></dd>
        <dt class="grand"><?= e($S['cart.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($q['total_gross'])) ?></dd>
        <dt class="small"><?= e($S['cart.vat_of'] ?? '') ?></dt><dd class="small"><?= e(zl($q['total_vat'])) ?></dd>
        <dt class="small"><?= e($S['cart.weight'] ?? '') ?></dt><dd class="small"><?= e(number_format($q['weight_g'] / 1000, 2, ',', ' ')) ?> kg</dd>
      </dl>
      <?php if (isset($qErr['weight_g'])) notice('warn', $qErr['weight_g']); ?>
      <a class="btn btn--accent btn--lg btn--block" href="<?= e(u('kasa', ['dostawa' => $m['id'] ?? 'inpost_locker'])) ?>"><?= e($S['cart.checkout'] ?? '') ?></a>
    </aside>
  </div>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// -------------------------------------------------------------------- CAISSE -
if ($page === 'kasa') {
    $errors = $formErrors ?? [];
    $v = $formValues ?? [];
    $shipId = (string) ($v['delivery_method'] ?? ($_GET['dostawa'] ?? 'inpost_locker'));
    [$q, ] = wsm_shop_quote($pdo, cart_items($cart), $shipId, $lang);

    /** Champ de formulaire : valeur réaffichée, erreur montrée sous le champ. */
    $field = function (string $name, string $label, array $opt = []) use ($v, $errors, $S) {
        $type = $opt['type'] ?? 'text';
        $err  = $errors[$name] ?? '';
        $val  = (string) ($v[$name] ?? ($opt['value'] ?? ''));
        $id   = 'f-' . $name;
        echo '<p class="field' . ($err ? ' has-error' : '') . ($opt['wide'] ?? false ? ' wide' : '') . '">';
        echo '<label for="' . e($id) . '">' . e($label) . ($opt['required'] ?? true ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
        echo '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($val) . '"'
           . (($opt['required'] ?? true) ? ' required' : '')
           . (isset($opt['autocomplete']) ? ' autocomplete="' . e($opt['autocomplete']) . '"' : '')
           . (isset($opt['inputmode']) ? ' inputmode="' . e($opt['inputmode']) . '"' : '')
           . (isset($opt['placeholder']) ? ' placeholder="' . e($opt['placeholder']) . '"' : '')
           . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-e"' : '') . '>';
        if (isset($opt['hint'])) echo '<small class="hint">' . e($opt['hint']) . '</small>';
        if ($err) echo '<small class="err" id="' . e($id) . '-e">' . e($err) . '</small>';
        echo '</p>';
    };

    layout_head($S, $lang, $langs, $S['checkout.title'] ?? '');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <h1><?= e($S['checkout.title'] ?? '') ?></h1>
  <?php if (!$q['lines']): ?>
    <p class="muted"><?= e($S['checkout.empty'] ?? '') ?></p>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
  <?php if ($errors) notice('error', $S['checkout.error'] ?? ''); ?>
  <div class="checkout">
    <form class="checkout-form" method="post" action="<?= e(u('kasa')) ?>" novalidate>
      <?= csrf_field() ?>

      <fieldset>
        <legend><?= e($S['checkout.contact'] ?? '') ?></legend>
        <div class="row">
          <?php $field('first_name', $S['checkout.first_name'] ?? '', ['autocomplete' => 'given-name']); ?>
          <?php $field('last_name', $S['checkout.last_name'] ?? '', ['autocomplete' => 'family-name']); ?>
        </div>
        <div class="row">
          <?php $field('email', $S['checkout.email'] ?? '', ['type' => 'email', 'autocomplete' => 'email', 'hint' => $S['checkout.email_hint'] ?? '']); ?>
          <?php $field('phone', $S['checkout.phone'] ?? '', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'numeric', 'hint' => $S['checkout.phone_hint'] ?? '']); ?>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.delivery'] ?? '') ?></legend>
        <?php foreach ($q['methods'] as $sm): ?>
        <label class="radio">
          <input type="radio" name="delivery_method" value="<?= e($sm['id']) ?>"<?= $sm['id'] === $shipId ? ' checked' : '' ?> data-ship>
          <span><strong><?= e($sm['label']) ?></strong>
            <em class="mono"><?= $q['shipping_free'] ? e($S['cart.free'] ?? '') : e(zl($sm['price'])) ?></em>
            <small><?= e($sm['note']) ?></small></span>
        </label>
        <?php endforeach; ?>

        <div class="ship-locker"<?= $shipId === 'inpost_courier' ? ' hidden' : '' ?> data-ship-locker>
          <?php $field('inpost_point', $S['checkout.point'] ?? '', ['hint' => $S['checkout.point_hint'] ?? '', 'placeholder' => 'KRA010']); ?>
        </div>
        <div class="ship-courier"<?= $shipId === 'inpost_courier' ? '' : ' hidden' ?> data-ship-courier>
          <div class="row">
            <?php $field('ship_street', $S['checkout.street'] ?? '', ['autocomplete' => 'address-line1', 'required' => false]); ?>
            <?php $field('ship_building', $S['checkout.building'] ?? '', ['required' => false]); ?>
          </div>
          <div class="row">
            <?php $field('ship_postcode', $S['checkout.postcode'] ?? '', ['autocomplete' => 'postal-code', 'placeholder' => '00-000', 'required' => false]); ?>
            <?php $field('ship_city', $S['checkout.city'] ?? '', ['autocomplete' => 'address-level2', 'required' => false]); ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.invoice'] ?? '') ?></legend>
        <label class="check">
          <input type="checkbox" name="invoice" value="1"<?= !empty($v['invoice']) ? ' checked' : '' ?> data-invoice>
          <span><?= e($S['checkout.want_invoice'] ?? '') ?><small><?= e($S['checkout.invoice_hint'] ?? '') ?></small></span>
        </label>
        <div class="invoice-fields"<?= !empty($v['invoice']) ? '' : ' hidden' ?> data-invoice-fields>
          <div class="row">
            <?php $field('company', $S['checkout.company'] ?? '', ['required' => false]); ?>
            <?php $field('nip', $S['checkout.nip'] ?? '', ['required' => false, 'inputmode' => 'numeric', 'placeholder' => '5252248481']); ?>
          </div>
          <?php $field('vat_eu', $S['checkout.vat_eu'] ?? '', ['required' => false, 'placeholder' => 'PL5252248481']); ?>
          <p class="mono muted"><?= e($S['checkout.bill_address'] ?? '') ?></p>
          <div class="row">
            <?php $field('bill_street', $S['checkout.street'] ?? '', ['required' => false]); ?>
            <?php $field('bill_building', $S['checkout.building'] ?? '', ['required' => false]); ?>
          </div>
          <div class="row">
            <?php $field('bill_postcode', $S['checkout.postcode'] ?? '', ['required' => false, 'placeholder' => '00-000']); ?>
            <?php $field('bill_city', $S['checkout.city'] ?? '', ['required' => false]); ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.note'] ?? '') ?></legend>
        <p class="field wide">
          <label for="f-note" class="sr-only"><?= e($S['checkout.note'] ?? '') ?></label>
          <textarea id="f-note" name="note" rows="3" maxlength="500"><?= e((string) ($v['note'] ?? '')) ?></textarea>
        </p>
        <p class="field<?= isset($errors['consent_terms']) ? ' has-error' : '' ?>">
          <label class="check">
            <input type="checkbox" name="consent_terms" value="1"<?= !empty($v['consent_terms']) ? ' checked' : '' ?>>
            <span><?= e($S['checkout.terms'] ?? '') ?></span>
          </label>
          <?php if (isset($errors['consent_terms'])) echo '<small class="err">' . e($errors['consent_terms']) . '</small>'; ?>
        </p>
      </fieldset>

      <button class="btn btn--accent btn--lg btn--block" type="submit"><?= e($S['checkout.submit'] ?? '') ?></button>
      <p class="mono muted pay-info"><?= e($S['checkout.pay_info'] ?? '') ?></p>
    </form>

    <aside class="summary">
      <h2><?= e($S['checkout.summary'] ?? '') ?></h2>
      <ul class="sum-lines">
        <?php foreach ($q['lines'] as $l): ?>
        <li><span><?= e($l['name']) ?> <em class="mono">×<?= (int) $l['qty'] ?></em></span><span class="mono"><?= e(zl($l['line_gross'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <dl class="totals mono">
        <dt><?= e($S['cart.subtotal'] ?? '') ?></dt><dd><?= e(zl($q['items_gross'])) ?></dd>
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= $q['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($q['shipping_gross'])) ?></dd>
        <?php foreach ($q['vat_breakdown'] as $vb): ?>
        <dt class="small">VAT <?= (int) round($vb['rate'] * 100) ?> %</dt><dd class="small"><?= e(zl($vb['vat'])) ?></dd>
        <?php endforeach; ?>
        <dt class="grand"><?= e($S['cart.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($q['total_gross'])) ?></dd>
      </dl>
    </aside>
  </div>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------------- CONFIRMATION -
if ($page === 'zamowienie') {
    $o = wsm_order_by_code($pdo, (string) ($seg[1] ?? ''), (string) ($_GET['t'] ?? ''));
    layout_head($S, $lang, $langs, $S['order.title'] ?? '');
    layout_header($S, $lang, $langs, $cartCount);
    if (!$o) {
        http_response_code(404);
        echo '<main class="wrap block"><h1>' . e($S['order.title'] ?? '') . '</h1>'
           . '<p class="muted">' . e($S['order.not_found'] ?? '') . '</p>'
           . '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
    $payUrl = $o['payment']['redirect_url'] ?? '';
    ?>
<main class="wrap block">
  <div class="thanks">
    <h1><?= e($S['order.thanks'] ?? '') ?></h1>
    <p class="mono order-code"><?= e($S['order.number'] ?? '') ?> · <strong><?= e($o['code']) ?></strong></p>
    <p class="muted"><?= e(str_replace('{email}', $o['email'], $S['order.confirm_mail'] ?? '')) ?></p>
  </div>

  <div class="order-grid">
    <section>
      <h2><?= e($S['order.items'] ?? '') ?></h2>
      <ul class="sum-lines">
        <?php foreach ($o['items'] as $l): ?>
        <li><span><?= e($l['name']) ?> <em class="mono">×<?= (int) $l['qty'] ?></em></span><span class="mono"><?= e(zl($l['line_gross'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <dl class="totals mono">
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= $o['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($o['shipping_gross'])) ?></dd>
        <dt class="grand"><?= e($S['order.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($o['total_gross'])) ?></dd>
      </dl>
    </section>

    <aside class="summary">
      <h2><?= e($S['order.status'] ?? '') ?></h2>
      <dl class="totals">
        <dt><?= e($S['order.status'] ?? '') ?></dt><dd><?= e($S['status.' . $o['status']] ?? $o['status']) ?></dd>
        <dt><?= e($S['order.payment'] ?? '') ?></dt><dd><?= e($S['pay.' . $o['payment_status']] ?? $o['payment_status']) ?></dd>
        <dt><?= e($S['order.delivery'] ?? '') ?></dt>
        <dd><?= e($S['ship.' . $o['delivery_method'] . '.label'] ?? $o['delivery_method']) ?>
          <?php if ($o['inpost_point'] !== ''): ?><br><span class="mono"><?= e($o['inpost_point']) ?></span><?php endif; ?>
          <?php if (($o['shipment']['tracking_number'] ?? '') !== ''): ?><br><span class="mono"><?= e($o['shipment']['tracking_number']) ?></span><?php endif; ?>
        </dd>
      </dl>
      <?php if ($o['payment_status'] === 'oczekuje' && $payUrl !== ''): ?>
        <a class="btn btn--accent btn--lg btn--block" href="<?= e($payUrl) ?>"><?= e($S['order.pay_now'] ?? '') ?></a>
      <?php elseif ($o['payment_status'] === 'niedostepne' || ($o['payment']['status'] ?? '') === 'niedostepne'): ?>
        <?php notice('warn', $S['order.pay_unavailable'] ?? ''); ?>
      <?php endif; ?>
      <p class="mono muted"><?= e($S['order.keep_link'] ?? '') ?></p>
    </aside>
  </div>
</main>
<?php
    layout_footer($S);
    exit;
}

// ----------------------------------------------------------------- 404 ------
http_response_code(404);
layout_head($S, $lang, $langs, '404');
layout_header($S, $lang, $langs, $cartCount);
echo '<main class="wrap block"><h1>404</h1><p><a class="btn btn--brand" href="' . e(u()) . '">'
   . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
layout_footer($S);
