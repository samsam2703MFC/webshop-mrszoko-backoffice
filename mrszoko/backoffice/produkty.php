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

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/media.php';

/** La table produits stocke des złotys, pas des grosze : conversion locale. */
function zl($v): string { return number_format((float) $v, 2, ',', "\u{202F}") . "\u{202F}zł"; }

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

console_head('Produkty i zdjęcia', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 78ch; line-height: 1.55; }
  .item { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          margin-bottom: 14px; box-shadow: var(--shadow-xs); overflow: hidden; }
  .item > summary { display: flex; align-items: center; gap: 14px; padding: 12px 14px; cursor: pointer; list-style: none; }
  .item > summary::-webkit-details-marker { display: none; }
  .item[open] > summary { border-bottom: 1px solid var(--border-subtle); background: var(--surface-raised); }
  .thumb { width: 56px; height: 56px; flex: none; border-radius: 10px; object-fit: cover; background: var(--cream-200); }
  .thumb.empty { display: grid; place-items: center; font-family: var(--font-mono); font-size: 10px;
                 color: var(--text-muted); text-align: center; line-height: 1.25; padding: 4px; }
  .sum-name { font-family: var(--font-display); font-size: 16px; color: var(--text-strong); font-weight: 600; }
  .sum-meta { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .sum-right { margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
  .tag.on  { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.off { background: var(--cream-300); color: var(--text-muted); }
  .edit { padding: 18px 14px; display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 820px) { .edit { grid-template-columns: 260px 1fr; gap: 24px; } }
  .preview img, .preview .ph { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 12px;
                               background: var(--cream-200); display: block; }
  .preview .ph { display: grid; place-items: center; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  label.f { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600;
            color: var(--text-strong); margin-bottom: 12px; }
  label.f input { font-weight: 400; }
  label.f small { font-weight: 400; color: var(--text-muted); font-size: 12px; }
  label.f small.err { color: var(--danger); font-weight: 600; }
  label.chk { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; cursor: pointer; margin-bottom: 10px; }
  label.chk input { accent-color: var(--brand); }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  a.view { font-size: 13px; color: var(--brand); font-weight: 600; text-decoration: none; }
  a.view:hover { text-decoration: underline; }
CSS, '');
console_flash($flash, $kind);
?>
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
        <div class="sum-meta"><?= h($id) ?> · <?= h((string) ($p['cat'] ?? '')) ?> · <?= h(zl($p['prix'])) ?></div>
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
<?php console_foot();
