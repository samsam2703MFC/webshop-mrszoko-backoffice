<?php
// ============================================================================
//  lib.php — socle de la boutique Mister Szoko.
//
//  Les pages sont rendues PAR LE SERVEUR, depuis la base. C'est le choix qui
//  rend la boutique rapide : la première réponse HTML contient déjà les
//  produits, les prix et les textes. Rien n'attend un aller-retour JavaScript
//  pour s'afficher, et la boutique fonctionne même sans JavaScript — le panier
//  passe par un cookie et des formulaires, pas par une application cliente.
//
//  Aucun libellé n'est écrit ici : tout vient de wsm_shop_i18n via shop.php.
// ============================================================================
declare(strict_types=1);

// L'API vit à ../backoffice/api sur le serveur et ../backoffice/php-api dans
// le dépôt. On accepte les deux pour que `php -S` marche en local sans copie.
$WSM_API_DIR = is_dir(__DIR__ . '/../backoffice/api')
    ? __DIR__ . '/../backoffice/api'
    : __DIR__ . '/../backoffice/php-api';
require_once $WSM_API_DIR . '/db.php';
require_once $WSM_API_DIR . '/auth.php';    // wsm_is_https() et la session partagée
require_once $WSM_API_DIR . '/shop.php';
require_once $WSM_API_DIR . '/tpay.php';

const WSM_CART_COOKIE = 'ms_cart';
const WSM_LANG_COOKIE = 'ms_lang';

/** Échappement HTML — appliqué à TOUTE valeur venant de la base ou du client. */
function e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Montant en grosze → « 64,90 zł ». */
function zl(int $grosze): string {
    return number_format($grosze / 100, 2, ',', "\u{202F}") . "\u{202F}zł";
}

/** Racine URL de la boutique, déduite du script — aucun chemin en dur. */
function shop_base(): string {
    static $base = null;
    if ($base !== null) return $base;
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '.' || $base === '/') $base = '';
    return $base;
}

/** Construit une URL de la boutique en conservant la langue choisie. */
function u(string $path = '', array $q = []): string {
    $url = shop_base() . '/' . ltrim($path, '/');
    $url = rtrim($url, '/');
    if ($url === '') $url = shop_base() . '/';
    return $q ? $url . '?' . http_build_query($q) : $url;
}

/** Segments de la route courante : /p/mon-produit → ['p','mon-produit']. */
function route_segments(): array {
    $p = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($p === '' && isset($_GET['r'])) $p = (string) $_GET['r'];   // repli sans mod_rewrite
    $p = trim($p, '/');
    return $p === '' ? [] : array_map('urldecode', explode('/', $p));
}

// ---------------------------------------------------------------------------
//  Panier — un cookie, pas une application cliente.
//  Le cookie ne contient que des identifiants et des quantités. Les prix
//  n'y sont jamais : ils sont relus en base à chaque affichage.
// ---------------------------------------------------------------------------
function cart_read(): array {
    $raw = (string) ($_COOKIE[WSM_CART_COOKIE] ?? '');
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) return [];
    $out = [];
    foreach ($j as $id => $qty) {
        $id = (string) $id;
        $qty = (int) $qty;
        // Un cookie est modifiable par son porteur : on borne ce qu'on en lit.
        if ($id === '' || strlen($id) > 48 || $qty <= 0) continue;
        $out[$id] = min($qty, WSM_SHOP_MAX_QTY);
        if (count($out) >= 40) break;
    }
    return $out;
}

function cart_write(array $cart): void {
    $cart = array_filter($cart, fn($q) => (int) $q > 0);
    $value = $cart ? json_encode($cart, JSON_UNESCAPED_SLASHES) : '';
    setcookie(WSM_CART_COOKIE, $value, [
        'expires'  => $cart ? time() + 30 * 86400 : time() - 3600,
        'path'     => shop_base() . '/',
        'httponly' => false,          // le JS met à jour le compteur sans recharger
        'samesite' => 'Lax',
        'secure'   => wsm_is_https(),
    ]);
    $_COOKIE[WSM_CART_COOKIE] = $value;
}

function cart_count(array $cart): int {
    return array_sum(array_map('intval', $cart));
}

/** Le panier au format attendu par wsm_shop_quote(). */
function cart_items(array $cart): array {
    $out = [];
    foreach ($cart as $id => $qty) $out[] = ['id' => $id, 'qty' => (int) $qty];
    return $out;
}

/** Langue retenue : ?lang, puis cookie, puis en-tête du navigateur, puis pl. */
function pick_lang(PDO $pdo): string {
    $have = wsm_shop_available_langs($pdo);
    foreach ([$_GET['lang'] ?? null, $_COOKIE[WSM_LANG_COOKIE] ?? null] as $c) {
        $c = strtolower(trim((string) $c));
        if ($c !== '' && in_array($c, $have, true)) return $c;
    }
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $c = strtolower(substr(trim($part), 0, 2));
        if (in_array($c, $have, true)) return $c;
    }
    return wsm_shop_lang($pdo, null);
}

function remember_lang(string $lang): void {
    setcookie(WSM_LANG_COOKIE, $lang, [
        'expires' => time() + 365 * 86400, 'path' => shop_base() . '/',
        'httponly' => false, 'samesite' => 'Lax', 'secure' => wsm_is_https(),
    ]);
}

/** Redirection interne (jamais vers une URL fournie par le client). */
function redirect(string $path, int $code = 303): void {
    header('Location: ' . $path, true, $code);
    exit;
}

/**
 * Vignette produit. Faute de photographie, un dégradé aux couleurs du produit
 * plutôt qu'un cadre gris avec le mot « PHOTO » : c'est du décor assumé, pas
 * un trou dans la page. Dès qu'image_url est renseignée en base, elle gagne.
 */
function product_visual(array $p, string $class, string $sizes = ''): string {
    if (($p['image'] ?? '') !== '') {
        return '<img class="' . e($class) . '" src="' . e($p['image']) . '" alt="' . e($p['name'])
             . '" loading="lazy" decoding="async"' . ($sizes ? ' sizes="' . e($sizes) . '"' : '') . '>';
    }
    $style = 'background:radial-gradient(120% 120% at 30% 20%, var(' . e($p['from'] ?: '--choco-500')
           . '), var(' . e($p['to'] ?: '--choco-800') . '))';
    $mark = $p['cocoa'] !== '' ? $p['cocoa'] : ($p['unit'] ?? '');
    return '<div class="' . e($class) . ' visual" style="' . $style . '" role="img" aria-label="' . e($p['name']) . '">'
         . ($mark !== '' ? '<span class="visual-mark">' . e($mark) . '</span>' : '') . '</div>';
}
