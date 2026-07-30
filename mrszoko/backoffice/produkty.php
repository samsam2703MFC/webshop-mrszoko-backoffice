<?php
// ============================================================================
//  produkty.php — écran « Produits et photos » de la console marque.
//
//  C'est ici qu'on envoie une photo de produit. Le fichier est décodé et
//  ré-encodé côté serveur (media.php), donc ce qui atterrit dans la boutique
//  est une image fabriquée par nous, redimensionnée et sans métadonnées.
//
//  Même logique que zamowienia.php : page PHP autonome, même session et mêmes
//  rôles que la console. Lecture pour tout compte actif, écriture pour Centrala.
// ============================================================================
declare(strict_types=1);

$API = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $API . '/db.php';
require_once $API . '/auth.php';
require_once $API . '/shop.php';
require_once $API . '/media.php';
require_once $API . '/delivery.php';   // wsm_audit() — la piste d'audit de la console

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$pdo = wsm_bootstrap();

wsm_session_start();
$me = wsm_current_user($pdo);
if (!$me) { header('Location: ./', true, 302); exit; }
$isAdmin = ($me['role'] ?? '') === WSM_ROLE_ADMIN;

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pln($v): string { return number_format((float) $v, 2, ',', "\u{202F}") . "\u{202F}zł"; }

// Le chemin enregistré en base est relatif à la boutique (« media/… ») :
// depuis /backoffice/ il faut remonter d'un cran pour l'afficher.
function img_src(string $url): string {
    return str_starts_with($url, 'media/') ? '../shop/' . $url : $url;
}

// Jeton anti-CSRF, émis avant toute sortie (un en-tête ne s'ajoute plus après).
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $fieldErrors = []; $openId = (string) ($_GET['id'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) {
        http_response_code(400); exit('Bad request.');
    }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać produkty.'; $kind = 'err';
    } else {
        $id = (string) ($_POST['id'] ?? '');
        $openId = $id;
        $st = $pdo->prepare("SELECT id, image_url FROM wsm_products WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            $flash = 'Nie znaleziono produktu.'; $kind = 'err';
        } else {
            $body = [];
            foreach (['slug', 'origin', 'cocoa', 'unit_label', 'badge'] as $k) {
                if (isset($_POST[$k])) $body[$k] = $_POST[$k];
            }
            $body['stock'] = (int) ($_POST['stock'] ?? 0);
            $body['shop_visible'] = !empty($_POST['shop_visible']) ? 1 : 0;

            $old = (string) $cur['image_url'];
            $newUrl = null;

            if (!empty($_POST['remove_image'])) {
                $body['image_url'] = '';
            } elseif (!empty($_FILES['photo']['name'] ?? '')) {
                [$url, $err] = wsm_media_store($_FILES['photo']);
                if ($err !== null) { $fieldErrors['photo'] = $err; }
                else { $body['image_url'] = $url; $newUrl = $url; }
            } elseif (isset($_POST['image_url']) && trim((string) $_POST['image_url']) !== $old) {
                $body['image_url'] = trim((string) $_POST['image_url']);
            }

            if (!$fieldErrors) {
                [$cols, $errs] = wsm_validate_product_shop($pdo, $body, $id);
                if ($errs) {
                    $fieldErrors = $errs;
                    // L'envoi a réussi mais l'enregistrement échoue : on ne
                    // laisse pas le fichier orphelin sur le disque.
                    if ($newUrl !== null) wsm_media_delete($newUrl);
                    $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
                } else {
                    $set = []; $vals = [];
                    foreach ($cols as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
                    if (isset($_POST['prix']) && $_POST['prix'] !== '') {
                        $set[] = 'prix = ?'; $vals[] = (float) str_replace(',', '.', (string) $_POST['prix']);
                    }
                    $vals[] = $id;
                    $pdo->prepare("UPDATE wsm_products SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_products ' . $id, 'Sieć');
                    // L'ancienne photo n'est effacée qu'une fois la nouvelle
                    // réellement enregistrée en base.
                    if ($newUrl !== null && $old !== '' && $old !== $newUrl) wsm_media_delete($old);
                    if (!empty($_POST['remove_image']) && $old !== '') wsm_media_delete($old);
                    $flash = 'Zapisano: ' . $id; $kind = 'ok';
                }
            } else {
                $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
            }
        }
    }
}

$rows = $pdo->query(
    "SELECT p.*, c.name AS cat FROM wsm_products p
       LEFT JOIN wsm_categories c ON c.id = p.category_id
      ORDER BY p.shop_visible DESC, p.sort_order, p.nom"
)->fetchAll();
$writable = is_dir(wsm_media_dir()) ? is_writable(wsm_media_dir()) : is_writable(dirname(wsm_media_dir()));
$nVisible = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1));
$nPhoto   = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1 && (string) $r['image_url'] !== ''));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Produkty i zdjęcia — Mister Szoko</title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; font-family: var(--font-sans); background: var(--bg-page-alt); color: var(--text-body); }
  .wrap { max-width: 1240px; margin: 0 auto; padding: 24px; }
  header.bar { background: var(--choco-800); color: var(--cream-50); }
  .bar-in { max-width: 1240px; margin: 0 auto; padding: 14px 24px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
  .bar-in img.logo { height: 40px; width: auto; }
  .bar-in h1 { font-family: var(--font-display); font-size: 20px; margin: 0; font-weight: 600; }
  .bar-in a { color: var(--cream-100); font-size: 13px; font-weight: 600; text-decoration: none;
              border-bottom: 1px solid var(--choco-600); }
  .bar-in .who { margin-left: auto; font-family: var(--font-mono); font-size: 12px; color: var(--choco-200); }
  .flash { border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok  { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
  .flash.err { background: color-mix(in srgb, var(--danger) 13%, transparent); color: var(--danger); }
  .warnbox { background: color-mix(in srgb, var(--warning) 15%, transparent); color: var(--caramel-600);
             border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 13.5px; }
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 78ch; line-height: 1.55; }
  .kpis { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
         padding: 14px 18px; box-shadow: var(--shadow-xs); }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }

  .item { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          margin-bottom: 14px; box-shadow: var(--shadow-xs); overflow: hidden; }
  .item > summary { display: flex; align-items: center; gap: 16px; padding: 12px 16px; cursor: pointer; list-style: none; }
  .item > summary::-webkit-details-marker { display: none; }
  .item[open] > summary { border-bottom: 1px solid var(--border-subtle); background: var(--surface-raised); }
  .thumb { width: 62px; height: 62px; flex: none; border-radius: 10px; object-fit: cover; background: var(--cream-200); }
  .thumb.empty { display: grid; place-items: center; font-family: var(--font-mono); font-size: 10px;
                 color: var(--text-muted); text-align: center; line-height: 1.25; padding: 4px; }
  .sum-name { font-family: var(--font-display); font-size: 17px; color: var(--text-strong); font-weight: 600; }
  .sum-meta { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .sum-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }
  .tag { font-family: var(--font-mono); font-size: 11px; padding: 3px 9px; border-radius: 999px;
         background: var(--cream-200); color: var(--choco-700); white-space: nowrap; }
  .tag.on  { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.off { background: var(--cream-300); color: var(--text-muted); }
  .tag.no  { background: color-mix(in srgb, var(--warning) 22%, transparent); color: var(--caramel-600); }

  .edit { padding: 20px 16px; display: grid; grid-template-columns: 260px 1fr; gap: 24px; }
  .preview img, .preview .ph { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 12px;
                               background: var(--cream-200); display: block; }
  .preview .ph { display: grid; place-items: center; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  label.f { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600; color: var(--text-strong); }
  label.f input[type=text], label.f input[type=number], label.f input[type=file], label.f input[type=url] {
    font-family: var(--font-sans); font-size: 14px; font-weight: 400; padding: 9px 12px;
    border: 1px solid var(--border-default); border-radius: 9px; background: var(--bg-page); color: var(--text-strong); }
  label.f small { font-weight: 400; color: var(--text-muted); font-size: 12px; }
  label.f small.err { color: var(--danger); font-weight: 600; }
  label.chk { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; cursor: pointer; }
  label.chk input { width: 18px; height: 18px; margin-top: 1px; accent-color: var(--brand); }
  .actions { display: flex; gap: 10px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
  button { font-family: var(--font-sans); font-size: 13.5px; font-weight: 600; border-radius: 9px;
           border: 1px solid var(--border-default); padding: 9px 16px; background: var(--surface-card);
           color: var(--text-strong); cursor: pointer; }
  button.primary { background: var(--brand); color: var(--cream-50); border-color: var(--brand); }
  button.danger  { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  a.view { font-size: 13px; color: var(--brand); font-weight: 600; text-decoration: none; }
  a.view:hover { text-decoration: underline; }
  @media (max-width: 780px) { .edit { grid-template-columns: 1fr; } .grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <img class="logo" src="img/logo.png" alt="Mister Szoko">
    <h1>Produkty i zdjęcia</h1>
    <a href="./">← Konsola</a>
    <a href="zamowienia.php">Zamówienia</a>
    <a href="kontrahenci.php">Kontrahenci i VAT UE</a>
    <a href="../shop/" target="_blank" rel="noopener">Sklep</a>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
  </div>
</header>

<div class="wrap">
  <?php if ($flash !== ''): ?><p class="flash <?= h($kind) ?>"><?= h($flash) ?></p><?php endif; ?>
  <?php if (!$isAdmin): ?>
    <p class="warnbox">Twoja rola pozwala tylko przeglądać. Zmiany może zapisywać rola <b>Centrala</b>.</p>
  <?php elseif (!$writable): ?>
    <p class="warnbox">Katalog <code>shop/media/</code> nie jest zapisywalny — wgrywanie zdjęć nie zadziała.
      Na serwerze: <code>chown www-data shop/media</code>.</p>
  <?php endif; ?>

  <p class="hint">
    Zdjęcie wgrywasz tutaj — plik jest po stronie serwera <b>ponownie zakodowany</b> i zmniejszony
    (maks. 1400 px, WebP), więc do sklepu trafia lekki obraz bez metadanych. Przyjmujemy JPEG, PNG,
    WebP i GIF do 8 MB. Zamiast pliku możesz też podać adres <code>https://</code>.
    Produkt pojawia się w sklepie dopiero po zaznaczeniu <b>W sprzedaży</b> i nadaniu adresu (slug).
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= $nVisible ?></b><span>W sprzedaży</span></div>
    <div class="kpi"><b><?= $nPhoto ?> / <?= $nVisible ?></b><span>Ze zdjęciem</span></div>
    <div class="kpi"><b><?= count($rows) ?></b><span>Produkty w bazie</span></div>
  </div>

  <?php foreach ($rows as $p):
    $id = (string) $p['id'];
    $img = (string) $p['image_url'];
    $vis = (int) $p['shop_visible'] === 1;
    $open = $openId === $id; ?>
  <details class="item"<?= $open ? ' open' : '' ?> id="p-<?= h($id) ?>">
    <summary>
      <?php if ($img !== ''): ?>
        <img class="thumb" src="<?= h(img_src($img)) ?>" alt="">
      <?php else: ?>
        <div class="thumb empty">brak<br>zdjęcia</div>
      <?php endif; ?>
      <div>
        <div class="sum-name"><?= h((string) $p['nom']) ?></div>
        <div class="sum-meta"><?= h($id) ?> · <?= h((string) ($p['cat'] ?? '')) ?> · <?= h(pln($p['prix'])) ?></div>
      </div>
      <div class="sum-right">
        <?php if ($vis && $img === ''): ?><span class="tag no">bez zdjęcia</span><?php endif; ?>
        <span class="tag <?= $vis ? 'on' : 'off' ?>"><?= $vis ? 'W sprzedaży' : 'Ukryty' ?></span>
        <span class="tag">stan <?= (int) $p['stock'] ?></span>
      </div>
    </summary>

    <form class="edit" method="post" enctype="multipart/form-data" action="produkty.php?id=<?= h(urlencode($id)) ?>#p-<?= h($id) ?>">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($id) ?>">

      <div class="preview">
        <?php if ($img !== ''): ?>
          <img src="<?= h(img_src($img)) ?>" alt="<?= h((string) $p['nom']) ?>">
          <?php if ($isAdmin): ?>
          <label class="chk" style="margin-top:12px">
            <input type="checkbox" name="remove_image" value="1"><span>Usuń zdjęcie przy zapisie</span>
          </label>
          <?php endif; ?>
        <?php else: ?>
          <div class="ph">Bez zdjęcia</div>
        <?php endif; ?>
        <?php if ($vis && $p['slug'] !== ''): ?>
          <p style="margin:12px 0 0"><a class="view" href="../shop/p/<?= h(urlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener">Zobacz w sklepie ↗</a></p>
        <?php endif; ?>
      </div>

      <div>
        <div class="grid2">
          <label class="f">Zdjęcie (plik)
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['photo']) && $open): ?>
              <small class="err"><?= h($fieldErrors['photo']) ?></small>
            <?php else: ?><small>JPEG · PNG · WebP · GIF, maks. 8 MB</small><?php endif; ?>
          </label>
          <label class="f">…albo adres obrazu
            <input type="url" name="image_url" value="<?= h(str_starts_with($img, 'media/') ? '' : $img) ?>"
                   placeholder="https://…"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['image_url']) && $open): ?>
              <small class="err"><?= h($fieldErrors['image_url']) ?></small>
            <?php else: ?><small>Tylko https — inaczej przeglądarka zablokuje obraz.</small><?php endif; ?>
          </label>

          <label class="f">Adres w sklepie (slug)
            <input type="text" name="slug" value="<?= h((string) $p['slug']) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['slug']) && $open): ?>
              <small class="err"><?= h($fieldErrors['slug']) ?></small>
            <?php else: ?><small>/shop/p/<b><?= h((string) $p['slug'] ?: '…') ?></b></small><?php endif; ?>
          </label>
          <label class="f">Cena brutto (zł)
            <input type="text" name="prix" value="<?= h(number_format((float) $p['prix'], 2, ',', '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Cena widoczna dla klienta, z VAT.</small>
          </label>

          <label class="f">Stan magazynowy
            <input type="number" name="stock" min="0" value="<?= (int) $p['stock'] ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['stock']) && $open): ?>
              <small class="err"><?= h($fieldErrors['stock']) ?></small>
            <?php else: ?><small>Zamówienie ponad stan jest odrzucane.</small><?php endif; ?>
          </label>
          <label class="f">Gramatura
            <input type="text" name="unit_label" value="<?= h((string) $p['unit_label']) ?>" placeholder="1 kg"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Pokazywana na karcie produktu.</small>
          </label>

          <label class="f">Pochodzenie
            <input type="text" name="origin" value="<?= h((string) $p['origin']) ?>" placeholder="Madagaskar"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>
          <label class="f">Kakao
            <input type="text" name="cocoa" value="<?= h((string) $p['cocoa']) ?>" placeholder="70 %"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>

          <label class="f">Etykieta
            <input type="text" name="badge" value="<?= h((string) $p['badge']) ?>" placeholder="bestseller"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>bestseller · nowosc · prezent — tłumaczone w wsm_shop_i18n.</small>
          </label>
          <div></div>
        </div>

        <label class="chk" style="margin-top:14px">
          <input type="checkbox" name="shop_visible" value="1"<?= $vis ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
          <span><b>W sprzedaży</b><br><small style="color:var(--text-muted)">Widoczny w sklepie i możliwy do kupienia.</small></span>
        </label>

        <?php if ($isAdmin): ?>
        <div class="actions"><button class="primary" type="submit">Zapisz</button></div>
        <?php endif; ?>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>
</body>
</html>
