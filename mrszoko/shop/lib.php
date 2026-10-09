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
// Les pages et blocs écrits dans la console (Strony). Fail-soft : si le
// fichier manque à l'assemblage, la boutique vit sans pages — et le contrôle
// de déploiement le dit — au lieu de tomber entière.
if (is_file($WSM_API_DIR . '/pages.php')) require_once $WSM_API_DIR . '/pages.php';
// L'ordre des sections, du menu et du pied de page (Układ strony). Même
// règle : absent, la boutique garde son ordre d'origine, tout visible.
if (is_file($WSM_API_DIR . '/layout.php')) require_once $WSM_API_DIR . '/layout.php';
// Les sections du Kreator (sections.php). Même règle : absent, la boutique
// vit sans sections — et le contrôle de déploiement le dit.
if (is_file($WSM_API_DIR . '/sections.php')) require_once $WSM_API_DIR . '/sections.php';
require_once $WSM_API_DIR . '/tpay.php';

const WSM_CART_COOKIE = 'ms_cart';
const WSM_LANG_COOKIE = 'ms_lang';
const WSM_VOUCHER_COOKIE = 'ms_kod';
const WSM_SOURCE_COOKIE = 'ms_zrodlo';

// Le paramètre du lien tracé. Le même nom que côté API (links.php), redéclaré
// ici pour que la boutique n'ait pas à charger ce fichier pour lire une URL.
const WSM_LINK_PARAM_PUB = 'l';

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

/**
 * URL d'une ressource statique, suffixée d'une empreinte de son contenu.
 *
 * Le .htaccess garde css/js/images une semaine — c'est ce qu'on veut pour la
 * vitesse. Mais un fichier au nom fixe et au cache long, c'est une correction
 * de style invisible pendant sept jours pour qui a déjà visité le site. La
 * signature change dès que le fichier change : le cache reste long ET juste.
 */
function asset(string $file): string {
    static $v = [];
    if (!isset($v[$file])) {
        $path = __DIR__ . '/' . $file;
        $v[$file] = is_file($path) ? substr(md5((string) filemtime($path) . filesize($path)), 0, 8) : '0';
    }
    return u($file) . '?v=' . $v[$file];
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

// ---------------------------------------------------------------------------
//  Le code de réduction — un cookie, comme le panier.
//
//  Il DOIT survivre au passage panier → caisse : un acheteur qui saisit son
//  code puis le voit disparaître à l'écran suivant croit l'avoir perdu, et
//  c'est le moment précis où l'on abandonne un panier. Rien de sensible n'y
//  est stocké : le code seul, revalidé en base à chaque affichage — un cookie
//  trafiqué ne peut donc rien accorder.
// ---------------------------------------------------------------------------
function voucher_read(): string {
    $raw = strtoupper(trim((string) ($_COOKIE[WSM_VOUCHER_COOKIE] ?? '')));
    if ($raw === '' || strlen($raw) > 40) return '';
    return preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
}

function voucher_write(string $code): void {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
    if (strlen($code) > 40) $code = '';
    setcookie(WSM_VOUCHER_COOKIE, $code, [
        'expires'  => $code !== '' ? time() + 7 * 86400 : time() - 3600,
        'path'     => shop_base() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => wsm_is_https(),
    ]);
    $_COOKIE[WSM_VOUCHER_COOKIE] = $code;
}

// ---------------------------------------------------------------------------
//  La source — d'où vient ce visiteur.
//
//  UN CODE DE CAMPAGNE, PAS UNE IDENTITÉ. Rien ici ne désigne une personne :
//  ni identifiant, ni adresse, ni empreinte. Le cookie porte le nom du lien
//  cliqué, il est recopié sur la commande, et c'est tout ce qu'on saura
//  jamais — l'équivalent d'un « vu en vitrine » noté sur un ticket.
//
//  Trente jours : au-delà, attribuer une vente à un lien cliqué le mois
//  dernier revient à s'attribuer un mérite qu'on ne peut pas prouver.
// ---------------------------------------------------------------------------
function source_read(): string {
    $raw = strtolower(trim((string) ($_COOKIE[WSM_SOURCE_COOKIE] ?? '')));
    if ($raw === '' || strlen($raw) > 40) return '';
    return preg_replace('/[^a-z0-9_.-]/', '', $raw) ?? '';
}

function source_write(string $code): void {
    $code = strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '', trim($code)) ?? '');
    if (strlen($code) > 40) $code = substr($code, 0, 40);
    setcookie(WSM_SOURCE_COOKIE, $code, [
        'expires'  => $code !== '' ? time() + 30 * 86400 : time() - 3600,
        'path'     => shop_base() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => wsm_is_https(),
    ]);
    $_COOKIE[WSM_SOURCE_COOKIE] = $code;
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
/**
 * Adresse d'un média stocké par la console.
 *
 * La base garde « media/xxx.webp », un chemin RELATIF à la racine de la
 * boutique. Tel quel dans une page servie sous /p/mon-produit, le navigateur
 * le résout en /p/media/xxx.webp — et l'image est cassée sur toutes les fiches
 * produit, alors qu'elle s'affiche parfaitement sur la page d'accueil. On le
 * ramène donc toujours à la racine de la boutique.
 */
function media_src(string $url): string {
    if ($url === '') return '';
    if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, '/')) return $url;
    return u($url);
}

/**
 * Le thème réglé dans Wygląd (console) : polices, couleurs, coins, et les
 * blocs de l'accueil à montrer. theme.php vit dans l'API ; s'il manque à
 * l'assemblage, la boutique garde son habit par défaut au lieu de tomber —
 * et le contrôle de déploiement, lui, le dit.
 */
function theme_values(): array {
    static $t = null;
    if ($t !== null) return $t;
    global $WSM_API_DIR;
    $f = (string) $WSM_API_DIR . '/theme.php';
    if (!function_exists('wsm_theme_get') && is_file($f)) require_once $f;
    if (function_exists('wsm_theme_get')) return $t = wsm_theme_get(wsm_pdo());
    return $t = ['hero_align' => 'lewo'];
}

/**
 * Le logo : celui déposé dans Ustawienia → Sklep, sinon celui du dépôt.
 * « xxxx », le masque d'un réglage vidé, n'est pas une adresse.
 */
function logo_src(): string {
    $l = trim((string) (wsm_config()['shop']['logo_image'] ?? ''));
    if ($l === '' || preg_match('/^x{2,}$/i', $l)) return u('assets/logo.png');
    return media_src($l);
}

/**
 * Les liens d'une surface (barre du haut ou pied de page), dans l'ordre
 * d'Układ strony : [['href', 'label'], …]. layout.php dit QUOI et dans quel
 * ordre ; ici on met les adresses et les libellés de la langue courante.
 * Sans layout.php : l'ordre d'origine, tout visible.
 */
function liens_uklad(PDO $pdo, array $S, string $lang, string $which): array {
    $def = $which === 'nav'
        ? ['sklep', 'b2b', 'zamowienie', 'kontakt']
        : ['email', 'zamowienie', 'regulamin', 'prywatnosc', 'kontakt', 'konsola'];
    $items = function_exists('wsm_layout_liens')
        ? wsm_layout_liens($pdo, $which)
        : array_map(fn($k) => ['k' => $k, 'type' => 'builtin', 'id' => 0, 'slug' => ''], $def);
    if (function_exists('wsm_page_liens')) {
        // Les pages cochées pour cette surface, quand layout.php manque.
        if (!function_exists('wsm_layout_liens')) {
            foreach (wsm_page_liens($pdo, $lang, $which) as $pg) $items[] = ['k' => 'strona:' . $pg['slug'], 'type' => 'strona', 'id' => 0, 'slug' => $pg['slug'], 'title' => $pg['title']];
        }
    }
    $out = [];
    foreach ($items as $it) {
        if ($it['type'] === 'strona') {
            $t = $it['title'] ?? (function_exists('wsm_page_textes') ? wsm_page_textes($pdo, (int) $it['id'], $lang)['title'] : '');
            if ($t === '') continue;
            $out[] = ['href' => u($it['slug']), 'label' => $t];
            continue;
        }
        switch ($it['k']) {
            case 'sklep':      $out[] = ['href' => u() . '#katalog', 'label' => (string) ($S['nav.shop'] ?? '')]; break;
            case 'b2b':        $out[] = ['href' => u() . '#pro', 'label' => (string) ($S['story.pro.eyebrow'] ?? '')]; break;
            case 'zamowienie': $out[] = ['href' => u('moje-zamowienie'), 'label' => (string) ($S['nav.order'] ?? 'Moje zamówienie')]; break;
            case 'kontakt':    $out[] = ['href' => u('kontakt'), 'label' => (string) ($S['nav.contact'] ?? 'Kontakt')]; break;
            case 'regulamin':  $out[] = ['href' => u('regulamin'), 'label' => (string) ($S['legal.terms'] ?? '')]; break;
            case 'prywatnosc': $out[] = ['href' => u('prywatnosc'), 'label' => (string) ($S['legal.privacy'] ?? '')]; break;
            case 'konsola':    $out[] = ['href' => shop_base() . '/../backoffice/', 'label' => (string) ($S['footer.console'] ?? '')]; break;
            case 'email':
                if (($S['footer.email'] ?? '') !== '') $out[] = ['href' => 'mailto:' . $S['footer.email'], 'label' => (string) $S['footer.email']];
                break;
        }
    }
    return array_values(array_filter($out, fn($l) => $l['label'] !== ''));
}

/**
 * L'adresse d'un bouton ou d'un lien écrit dans Strony : https et locale
 * telles quelles, l'adresse d'une page de la boutique passée par u().
 */
function page_href(string $url): string {
    if (str_starts_with($url, 'https://') || str_starts_with($url, '/') || str_starts_with($url, '#')) return $url;
    return u($url);
}

/**
 * UN bloc écrit dans Strony, à la place qu'Układ lui donne. Non publié ou
 * vide = rien d'imprimé : la page ne change pas quand l'équipe n'a encore
 * rien écrit. Les styles cochés (ciemny, foto_prawo, szeroki) sont des
 * classes, stylées dans shop.css.
 */
function blok_html(PDO $pdo, string $lang, int $id): void {
    if (!function_exists('wsm_page_blok')) return;
    $b = wsm_page_blok($pdo, $id, $lang);
    if (!$b) return;
    {
        $img = (string) ($b['image_url'] ?? '');
        $kl = 'wrap block blok' . ($img !== '' ? ' blok--foto' : '');
        foreach ((array) ($b['styl'] ?? []) as $t) $kl .= ' blok--' . preg_replace('/[^a-z_]/', '', (string) $t);
        ?>
  <section class="<?= e($kl) ?>" id="blok-<?= (int) $b['id'] ?>">
    <div class="blok-in">
      <?php if ($img !== ''): ?><img class="blok-img" src="<?= e(media_src($img)) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
      <div class="blok-txt">
        <?php if ($b['title'] !== ''): ?><h2><?= e($b['title']) ?></h2><?php endif; ?>
        <?php if ($b['lead'] !== ''): ?><p class="lead"><?= e($b['lead']) ?></p><?php endif; ?>
        <?php if ($b['body'] !== ''): ?><div class="prose"><?= wsm_page_render($b['body'], fn(string $x) => u($x)) ?></div><?php endif; ?>
        <?php if ($b['cta_label'] !== '' && $b['cta_url'] !== ''): ?>
        <a class="btn btn--accent" href="<?= e(page_href($b['cta_url'])) ?>"><?= e($b['cta_label']) ?></a>
        <?php endif; ?>
      </div>
    </div>
  </section>
        <?php
    }
}

/**
 * UNE SECTION DU KREATOR, rendue. $v vient de wsm_section_view() : textes
 * repliés, items décodés, images validées. Chaque type a son gabarit ; les
 * styles cochés deviennent des classes « sek--… », stylées dans shop.css.
 * Tout texte passe par e() ou par la grammaire de pages.php — jamais brut.
 *
 * $opt['h1'] : le nagłówek natif est caché, le premier nagłówek du Kreator
 * porte le h1 de la page. $opt['home_anchors'] : la section n'est pas sur
 * l'accueil, un bouton « #katalog » renvoie donc à l'accueil — l'ancre
 * n'existe pas ailleurs.
 */
function sekcja_html(array $v, array $opt = []): void {
    static $h1Done = false;
    $typ = (string) $v['type'];
    $kl = 'sek sek--' . preg_replace('/[^a-z_]/', '', $typ);
    foreach ((array) ($v['styl'] ?? []) as $t) $kl .= ' sek--' . preg_replace('/[^a-z_]/', '', (string) $t);
    foreach ((array) ($v['ustawienia'] ?? []) as $k => $val) $kl .= ' sek--' . preg_replace('/[^a-z_0-9]/', '', (string) $k) . '-' . preg_replace('/[^a-z_0-9]/', '', (string) $val);
    $id = 'sek-' . (int) $v['id'];
    $img = (string) ($v['image_url'] ?? '');
    $u = fn(string $x) => u($x);
    $cta = $v['cta_label'] !== '' && $v['cta_url'] !== '';
    $href = fn(string $url) => str_starts_with($url, '#') && !empty($opt['home_anchors']) ? u('') . $url : page_href($url);
    switch ($typ) {
        case 'hero':
            $h1 = !empty($opt['h1']) && !$h1Done; if ($h1) $h1Done = true; $tag = $h1 ? 'h1' : 'h2';
            ?>
  <section class="hero<?= $img !== '' ? ' hero--foto' : '' ?> <?= e($kl) ?>" id="<?= e($id) ?>"<?= $img !== '' ? ' style="--hero-foto:url(' . e(media_src($img)) . ')"' : '' ?>>
    <div class="wrap hero-in">
      <?php if ($v['title'] !== ''): ?><<?= $tag ?> class="sek-h1"><?= e($v['title']) ?></<?= $tag ?>><?php endif; ?>
      <?php if ($v['lead'] !== ''): ?><p class="lead"><?= e($v['lead']) ?></p><?php endif; ?>
      <?php if ($cta): ?><a class="btn btn--accent" href="<?= e($href($v['cta_url'])) ?>"><?= e($v['cta_label']) ?></a><?php endif; ?>
    </div>
  </section>
            <?php
            break;
        case 'tekst':
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
    <div class="prose<?= ($v['ustawienia']['kolumny'] ?? '1') === '2' ? ' prose--2kol' : '' ?>"><?= wsm_page_render($v['body'], $u) ?></div>
  </section>
            <?php
            break;
        case 'tekst_foto':
            ?>
  <section class="wrap block blok<?= $img !== '' ? ' blok--foto' : '' ?><?php foreach ((array) $v['styl'] as $t) echo ' blok--' . e((string) $t); ?> <?= e($kl) ?>" id="<?= e($id) ?>">
    <div class="blok-in">
      <?php if ($img !== ''): ?><img class="blok-img" src="<?= e(media_src($img)) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
      <div class="blok-txt">
        <?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
        <?php if ($v['lead'] !== ''): ?><p class="lead"><?= e($v['lead']) ?></p><?php endif; ?>
        <?php if ($v['body'] !== ''): ?><div class="prose"><?= wsm_page_render($v['body'], $u) ?></div><?php endif; ?>
        <?php if ($cta): ?><a class="btn btn--accent" href="<?= e($href($v['cta_url'])) ?>"><?= e($v['cta_label']) ?></a><?php endif; ?>
      </div>
    </div>
  </section>
            <?php
            break;
        case 'foto':
            if ($img === '') return;
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <figure class="page-fig sek-foto"><img src="<?= e(media_src($img)) ?>" alt="<?= e($v['lead']) ?>" loading="lazy" decoding="async">
      <?php if ($v['lead'] !== ''): ?><figcaption><?= e($v['lead']) ?></figcaption><?php endif; ?></figure>
  </section>
            <?php
            break;
        case 'galeria':
            $imgs = array_values(array_filter($v['items'], fn($it) => $it['img'] !== ''));
            if (!$imgs) return;
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
    <div class="galeria galeria--<?= (int) ($v['ustawienia']['kolumny'] ?? 3) ?>">
      <?php foreach ($imgs as $it): ?>
      <figure><img src="<?= e(media_src($it['img'])) ?>" alt="<?= e($it['t']) ?>" loading="lazy" decoding="async">
        <?php if ($it['t'] !== ''): ?><figcaption><?= e($it['t']) ?></figcaption><?php endif; ?></figure>
      <?php endforeach; ?>
    </div>
  </section>
            <?php
            break;
        case 'kafelki':
            if (!$v['items']) return;
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <?php if ($v['title'] !== '' || $v['lead'] !== ''): ?>
    <div class="section-head"><?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
      <?php if ($v['lead'] !== ''): ?><p class="sub"><?= e($v['lead']) ?></p><?php endif; ?></div>
    <?php endif; ?>
    <div class="promises kafelki kafelki--<?= (int) ($v['ustawienia']['kolumny'] ?? 3) ?>">
      <?php foreach ($v['items'] as $it): ?>
      <div class="promise<?= $it['img'] !== '' ? ' promise--ikona' : '' ?>">
        <?php if ($it['img'] !== ''): ?><img class="promise-ikona" src="<?= e(media_src($it['img'])) ?>" alt="" width="56" height="56" loading="lazy" decoding="async"><?php endif; ?>
        <?php if ($it['t'] !== ''): ?><h3><?= e($it['t']) ?></h3><?php endif; ?>
        <?php if ($it['d'] !== ''): ?><p><?= e($it['d']) ?></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
            <?php
            break;
        case 'baner':
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>" style="padding-top:0">
    <div class="pro">
      <div class="pro-in">
        <div>
          <?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
          <?php if ($v['lead'] !== ''): ?><p><?= e($v['lead']) ?></p><?php endif; ?>
        </div>
        <?php if ($cta): ?><a class="btn btn--accent btn--lg" href="<?= e($href($v['cta_url'])) ?>"><?= e($v['cta_label']) ?></a><?php endif; ?>
      </div>
    </div>
  </section>
            <?php
            break;
        case 'faq':
            if (!$v['items']) return;
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <?php if ($v['title'] !== ''): ?><h2><?= e($v['title']) ?></h2><?php endif; ?>
    <?php if ($v['lead'] !== ''): ?><p class="sub"><?= e($v['lead']) ?></p><?php endif; ?>
    <div class="faq">
      <?php foreach ($v['items'] as $it): if ($it['t'] === '') continue; ?>
      <details class="faq-q"><summary><?= e($it['t']) ?></summary><div class="prose"><?= wsm_page_render($it['d'], $u) ?></div></details>
      <?php endforeach; ?>
    </div>
  </section>
            <?php
            break;
        case 'cytat':
            ?>
  <section class="wrap block <?= e($kl) ?>" id="<?= e($id) ?>">
    <blockquote class="cytat">
      <?php if ($img !== ''): ?><img class="cytat-foto" src="<?= e(media_src($img)) ?>" alt="" width="72" height="72" loading="lazy" decoding="async"><?php endif; ?>
      <p>„<?= e($v['body']) ?>”</p>
      <?php if ($v['lead'] !== ''): ?><footer>— <?= e($v['lead']) ?></footer><?php endif; ?>
    </blockquote>
  </section>
            <?php
            break;
        case 'odstep':
            ?>
  <div class="wrap <?= e($kl) ?>" id="<?= e($id) ?>"><?= in_array('linia', (array) $v['styl'], true) ? '<hr>' : '' ?></div>
            <?php
            break;
        case 'ogloszenie':
            if ($v['body'] === '') return;
            ?>
<div class="ogloszenie <?= e($kl) ?>" id="<?= e($id) ?>"><div class="wrap ogloszenie-in"><span><?= wsm_page_inline($v['body'], $u) ?></span>
  <?php if ($cta): ?><a href="<?= e($href($v['cta_url'])) ?>"><?= e($v['cta_label']) ?> →</a><?php endif; ?></div></div>
            <?php
            break;
    }
}

/** Une section de l'accueil par identifiant (depuis la liste d'Układ). */
function sekcja_id_html(PDO $pdo, string $lang, int $id, array $opt = []): void {
    if (!function_exists('wsm_section_get')) return;
    $s = wsm_section_get($pdo, $id);
    if (!$s || !(int) $s['published'] || (int) $s['page_id'] !== WSM_SECTION_HOME) return;
    $v = wsm_section_view($pdo, $s, $lang);
    if ($v) sekcja_html($v, $opt);
}

/** Les sections publiées d'une cible, rendues dans l'ordre (hors accueil : les ancres visent l'accueil). */
function sekcje_html(PDO $pdo, string $lang, int $pageId): void {
    if (!function_exists('wsm_section_views')) return;
    foreach (wsm_section_views($pdo, $pageId, $lang) as $v) sekcja_html($v, ['home_anchors' => $pageId !== WSM_SECTION_HOME]);
}

/** Ce que <head> imprime après shop.css : les jetons redéfinis, ou rien. */
function theme_head(): string {
    theme_values();
    return function_exists('wsm_theme_head') ? wsm_theme_head() : '';
}

function product_visual(array $p, string $class, string $sizes = ''): string {
    if (($p['image'] ?? '') !== '') {
        return '<img class="' . e($class) . '" src="' . e(media_src((string) $p['image'])) . '" alt="' . e($p['name'])
             . '" loading="lazy" decoding="async"' . ($sizes ? ' sizes="' . e($sizes) . '"' : '') . '>';
    }
    $style = 'background:radial-gradient(120% 120% at 30% 20%, var(' . e($p['from'] ?: '--choco-500')
           . '), var(' . e($p['to'] ?: '--choco-800') . '))';
    $mark = $p['cocoa'] !== '' ? $p['cocoa'] : ($p['unit'] ?? '');
    return '<div class="' . e($class) . ' visual" style="' . $style . '" role="img" aria-label="' . e($p['name']) . '">'
         . ($mark !== '' ? '<span class="visual-mark">' . e($mark) . '</span>' : '') . '</div>';
}

/**
 * Le logo d'une marque, ou son nom si elle n'a pas de logo.
 *
 * On ne laisse jamais un emplacement vide : une marque sans image affiche son
 * nom en toutes lettres. Un cadre vide sur une carte produit se lit comme une
 * image cassée, et fait douter du reste de la page.
 *
 * Le logo n'est pas cliquable sur la carte : la carte entière mène déjà au
 * produit, et deux cibles imbriquées font rater celle qu'on visait.
 */
function brand_mark(?array $b, string $class = 'brand-mark'): string {
    if (!$b || ($b['name'] ?? '') === '') return '';
    $name = (string) $b['name'];
    if (($b['logo'] ?? '') !== '') {
        return '<span class="' . e($class) . '"><img src="' . e(media_src((string) $b['logo'])) . '" alt="' . e($name)
             . '" loading="lazy" decoding="async"></span>';
    }
    return '<span class="' . e($class) . ' ' . e($class) . '--text">' . e($name) . '</span>';
}

// ═══ LES DEUX DOCUMENTS QUE LA LOI EXIGE ═══════════════════════════════════
//
// Regulamin et Polityka Prywatności. L'opérateur de paiement a refusé
// d'activer le compte tant qu'ils n'étaient pas publiés — et il avait raison
// deux fois : la case à cocher de la caisse disait « Akceptuję regulamin » en
// ne pointant sur rien du tout. On faisait accepter un document inexistant.
//
// LE TEXTE VIT DANS LA BASE, comme le reste de la vitrine : il se corrige dans
// Treści sans passer par moi, et un texte juridique se corrige. Mais LES
// CHIFFRES viennent de la CONFIGURATION RÉELLE, jamais du texte : un règlement
// qui promet 48 h pendant que la caisse annonce 24 h ne se contente pas d'être
// faux, il est opposable. Les accolades ci-dessous sont remplies au rendu par
// ce que la boutique fait vraiment.

/** Les valeurs qui remplacent les accolades. Une seule source : la boutique. */
function legal_valeurs(PDO $pdo, array $S): array {
    $api = dirname(__DIR__) . '/backoffice/php-api';
    if (!is_dir($api)) $api = dirname(__DIR__) . '/backoffice/api';
    if (!function_exists('wsm_ship_delai_h') && is_file($api . '/invoice.php')) {
        require_once $api . '/invoice.php';
    }
    $h = function_exists('wsm_ship_delai_h') ? wsm_ship_delai_h() : 24;

    // Le tableau des livraisons, écrit depuis la table : ajoutez un
    // transporteur demain, le règlement le nommera sans qu'on y touche.
    $lignes = [];
    try {
        $st = $pdo->query("SELECT id, price_net, vat_rate, free_from FROM wsm_shipping_methods
                            WHERE active = 1 ORDER BY sort_order, id");
        foreach ($st as $m) {
            $nom = (string) ($S['ship.' . $m['id'] . '.label'] ?? $m['id']);
            $brut = (int) round((int) $m['price_net'] * (1 + (float) $m['vat_rate']));
            $l = '- **' . $nom . '** — ' . number_format($brut / 100, 2, ',', ' ') . ' zł';
            if ((int) $m['free_from'] > 0) {
                $l .= ', bezpłatnie od ' . number_format((int) $m['free_from'] / 100, 2, ',', ' ') . ' zł';
            }
            $lignes[] = $l;
        }
    } catch (Throwable $e) { /* table absente : le document reste lisible */ }

    return [
        '{sklep}'      => (string) ($S['brand'] ?? 'Mister Szoko'),
        '{sprzedawca}' => (string) ($S['seller.name'] ?? ''),
        '{adres}'      => (string) ($S['seller.address'] ?? ''),
        '{ids}'        => (string) ($S['seller.ids'] ?? ''),
        '{email}'      => (string) ($S['footer.email'] ?? ''),
        // seo_origin() vit dans seo.php, que lib.php NE CHARGE PAS : l'appeler
        // nu faisait tomber en « undefined function » tout appelant qui n'avait
        // pas charge les deux. La vitrine les charge tous les deux, donc ca ne
        // se voyait pas — jusqu'au premier autre appelant.
        '{adres_www}'  => (function_exists('seo_origin') && seo_origin() !== '')
                            ? seo_origin() . shop_base() . '/' : shop_base() . '/',
        '{wysylka_h}'  => (string) $h,
        '{zwrot_dni}'  => '14',
        '{dostawa}'    => $lignes ? implode("\n", $lignes) : '- (brak aktywnych sposobów dostawy)',
    ];
}

/**
 * Le corps d'une section, rendu SANS jamais laisser passer de HTML.
 *
 * Le texte vient d'un champ éditable en console. Y accepter du HTML ferait de
 * l'écran Treści une porte d'entrée pour du script sur la vitrine — la faille
 * la plus banale qui soit. On échappe TOUT, puis on rend une grammaire
 * minuscule et sûre : un paragraphe par ligne vide, une liste par lignes qui
 * commencent par « - », et **gras**.
 */
function legal_corps(string $txt, array $vals): string {
    $txt = strtr($txt, $vals);
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($txt)) ?: [] as $bloc) {
        $bloc = trim($bloc);
        if ($bloc === '') continue;
        $lignes = preg_split('/\n/', $bloc) ?: [];
        $liste = true;
        foreach ($lignes as $l) if (!str_starts_with(trim($l), '- ')) { $liste = false; break; }
        if ($liste) {
            $out .= '<ul>';
            foreach ($lignes as $l) $out .= '<li>' . legal_gras(substr(trim($l), 2)) . '</li>';
            $out .= '</ul>';
        } else {
            $out .= '<p>' . legal_gras(implode(' ', array_map('trim', $lignes))) . '</p>';
        }
    }
    return $out;
}

/** **gras** — sur du texte DÉJÀ échappé, donc sans risque d'injection. */
function legal_gras(string $s): string {
    $s = e($s);
    return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
}

/**
 * Les sections d'un document, dans l'ordre, telles qu'elles existent en base.
 *
 * On s'arrête à la première manquante plutôt que de deviner un nombre : une
 * section retirée en console ne doit pas couper le document en deux.
 *
 * @return list<array{0:string,1:string}> [titre, corps rendu]
 */
function legal_sections(array $pl, string $prefixe, array $vals): array {
    $out = [];
    for ($i = 1; $i <= 40; $i++) {
        $t = trim((string) ($pl["$prefixe.s$i.t"] ?? ''));
        $b = trim((string) ($pl["$prefixe.s$i.b"] ?? ''));
        if ($t === '' && $b === '') break;
        $out[] = [$t, legal_corps($b, $vals)];
    }
    return $out;
}
