<?php
// ============================================================================
//  zamowienia.php — écran Commandes de la console marque.
//
//  Volontairement séparé du fichier exporté par Claude Design (193 Ko générés,
//  qu'un patch à la main rendrait irrécupérables au prochain export). C'est une
//  page PHP autonome, rendue côté serveur, qui partage TOUT le reste avec la
//  console : la même session, les mêmes rôles, les mêmes jetons de marque.
//
//  Lecture : tout compte actif. Écriture (statut, étiquette InPost) : Centrala.
// ============================================================================
declare(strict_types=1);

$API = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $API . '/db.php';
require_once $API . '/auth.php';
require_once $API . '/shop.php';
require_once $API . '/tpay.php';
require_once $API . '/inpost.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$pdo = wsm_bootstrap();

// ---- Identité : mêmes règles que l'API, pas un contrôle parallèle ----------
wsm_session_start();
$me = wsm_current_user($pdo);
if (!$me) {
    // Pas de session : on renvoie vers la console, qui porte l'écran de login.
    header('Location: ./', true, 302);
    exit;
}
$isAdmin = ($me['role'] ?? '') === WSM_ROLE_ADMIN;

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pln(int $g): string { return number_format($g / 100, 2, ',', "\u{202F}") . "\u{202F}zł"; }

$flash = ''; $flashKind = 'ok';

// ---- Actions (réservées à Centrala) ---------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać zamówienia.'; $flashKind = 'err';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $order = wsm_order_by_id($pdo, $id);
        if (!$order) {
            $flash = 'Nie znaleziono zamówienia.'; $flashKind = 'err';
        } elseif (isset($_POST['status'])) {
            $new = (string) $_POST['status'];
            if (in_array($new, WSM_ORDER_STATUSES, true)) {
                $pdo->prepare("UPDATE wsm_orders SET status = ? WHERE id = ?")->execute([$new, $id]);
                wsm_order_event($pdo, $id, 'status', $new, (string) ($me['nom'] ?? ''));
                $flash = $order['code'] . ' → ' . $new;
            }
        } elseif (isset($_POST['ship'])) {
            [$sh, $err] = wsm_inpost_create($pdo, $order);
            if ($err !== null) { $flash = 'InPost: ' . $err; $flashKind = 'err'; }
            else { $flash = 'Utworzono przesyłkę ' . ($sh['tracking_number'] ?? ''); }
        }
    }
}

$detail = isset($_GET['id']) ? wsm_order_by_id($pdo, (int) $_GET['id']) : null;
$orders = wsm_orders_list($pdo, 200);
$kpis   = wsm_shop_kpis($pdo);
$cfg    = ['tpay' => wsm_tpay_enabled(), 'inpost' => wsm_inpost_enabled()];

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];
$payLabel = ['oczekuje' => 'Oczekuje', 'oplacone' => 'Opłacone', 'nieudane' => 'Nieudana',
             'niedostepne' => 'Niedostępna'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zamówienia — Mister Szoko</title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; font-family: var(--font-sans); background: var(--bg-page-alt); color: var(--text-body); }
  .wrap { max-width: 1240px; margin: 0 auto; padding: 24px; }
  header.bar { background: var(--choco-800); color: var(--cream-50); }
  .bar-in { max-width: 1240px; margin: 0 auto; padding: 14px 24px; display: flex; align-items: center; gap: 20px; }
  .bar-in img { height: 40px; width: auto; }
  .bar-in h1 { font-family: var(--font-display); font-size: 20px; margin: 0; font-weight: 600; }
  .bar-in .who { margin-left: auto; font-family: var(--font-mono); font-size: 12px; color: var(--choco-200); }
  .bar-in a { color: var(--cream-100); font-size: 13px; font-weight: 600; text-decoration: none;
              border-bottom: 1px solid var(--choco-600); }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
         padding: 16px 18px; box-shadow: var(--shadow-xs); }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 24px; color: var(--text-strong); }
  .kpi span { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }
  table { width: 100%; border-collapse: collapse; background: var(--surface-card);
          border: 1px solid var(--border-subtle); border-radius: 14px; overflow: hidden; }
  th, td { text-align: left; padding: 11px 14px; font-size: 13.5px; border-bottom: 1px solid var(--border-subtle); }
  th { background: var(--surface-raised); font-size: 11.5px; text-transform: uppercase;
       letter-spacing: .08em; color: var(--text-muted); }
  tr:last-child td { border-bottom: 0; }
  td.num, th.num { text-align: right; font-family: var(--font-mono); }
  .tag { display: inline-block; font-family: var(--font-mono); font-size: 11px; padding: 3px 9px;
         border-radius: 999px; background: var(--cream-200); color: var(--choco-700); }
  .tag.ok { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.wait { background: color-mix(in srgb, var(--warning) 20%, transparent); color: var(--caramel-600); }
  .tag.bad { background: color-mix(in srgb, var(--danger) 15%, transparent); color: var(--danger); }
  .flash { border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
  .flash.err { background: color-mix(in srgb, var(--danger) 13%, transparent); color: var(--danger); }
  .warnbox { background: color-mix(in srgb, var(--warning) 15%, transparent); color: var(--caramel-600);
             border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 13.5px; }
  .panel { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
           padding: 20px 22px; margin-bottom: 22px; box-shadow: var(--shadow-xs); }
  .panel h2 { font-family: var(--font-display); font-size: 19px; margin: 0 0 14px; color: var(--text-strong); }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  dl.kv { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0; font-size: 13.5px; }
  dl.kv dt { color: var(--text-muted); }
  dl.kv dd { margin: 0; color: var(--text-strong); }
  pre { background: var(--surface-sunken); border-radius: 10px; padding: 14px; overflow: auto;
        font-family: var(--font-mono); font-size: 12px; line-height: 1.5; }
  button, select { font-family: var(--font-sans); font-size: 13px; border-radius: 8px;
                   border: 1px solid var(--border-default); padding: 7px 12px; background: var(--surface-card);
                   color: var(--text-strong); cursor: pointer; }
  button.primary { background: var(--brand); color: var(--cream-50); border-color: var(--brand); font-weight: 600; }
  a.code { font-family: var(--font-mono); color: var(--brand); font-weight: 600; text-decoration: none; }
  a.code:hover { text-decoration: underline; }
  @media (max-width: 800px) { .cols { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <img src="img/logo.png" alt="Mister Szoko">
    <h1>Zamówienia</h1>
    <a href="./">← Konsola</a>
    <a href="produkty.php">Produkty i zdjęcia</a>
    <a href="kontrahenci.php">Kontrahenci i VAT UE</a>
    <a href="../shop/" target="_blank" rel="noopener">Sklep</a>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
  </div>
</header>

<div class="wrap">
  <?php if ($flash !== ''): ?><p class="flash <?= h($flashKind) ?>"><?= h($flash) ?></p><?php endif; ?>
  <?php if (!$cfg['tpay'] || !$cfg['inpost']): ?>
  <p class="warnbox">
    <?php if (!$cfg['tpay']): ?>tpay nie jest skonfigurowany — zamówienia powstają, ale nie da się ich opłacić online.<?php endif; ?>
    <?php if (!$cfg['tpay'] && !$cfg['inpost']): ?><br><?php endif; ?>
    <?php if (!$cfg['inpost']): ?>InPost ShipX nie jest skonfigurowany — etykiet nie można utworzyć automatycznie.<?php endif; ?>
  </p>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
    <div class="kpi"><b><?= (int) $kpis['orders_paid'] ?></b><span>Opłacone</span></div>
    <div class="kpi"><b><?= (int) $kpis['orders_pending'] ?></b><span>Oczekuje płatności</span></div>
    <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
    <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
  </div>

<?php if ($detail): $o = $detail;
  $st = $pdo->prepare("SELECT event, detail, actor, created_at FROM wsm_order_events WHERE order_id = ? ORDER BY id");
  $st->execute([(int) $o['id']]);
  $events = $st->fetchAll();
  $blockers = wsm_inpost_blockers($o); ?>
  <div class="panel">
    <h2><?= h($o['code']) ?> · <?= h(pln($o['total_gross'])) ?></h2>
    <div class="cols">
      <dl class="kv">
        <dt>Klient</dt><dd><?= h(trim($o['first_name'] . ' ' . $o['last_name'])) ?><?= $o['company'] !== '' ? ' · ' . h($o['company']) : '' ?></dd>
        <dt>E-mail</dt><dd><?= h($o['email']) ?></dd>
        <dt>Telefon</dt><dd><?= h($o['phone']) ?></dd>
        <?php if ($o['invoice']): ?><dt>Faktura</dt><dd>NIP <?= h($o['nip']) ?><br><?= h($o['bill']['street'] . ' ' . $o['bill']['building']) ?>, <?= h($o['bill']['postcode'] . ' ' . $o['bill']['city']) ?></dd><?php endif; ?>
        <dt>Dostawa</dt><dd><?= h($o['delivery_method']) ?><?= $o['inpost_point'] !== '' ? ' · ' . h($o['inpost_point']) : '' ?>
          <?php if ($o['delivery_method'] === 'inpost_courier'): ?><br><?= h($o['ship']['street'] . ' ' . $o['ship']['building']) ?>, <?= h($o['ship']['postcode'] . ' ' . $o['ship']['city']) ?><?php endif; ?></dd>
        <dt>Paczka</dt><dd><?= number_format($o['weight_g'] / 1000, 2, ',', ' ') ?> kg · gabaryt <?= h($o['parcel_template'] ?: '—') ?>
          <small style="color:var(--text-muted)"> (szacunek z wymiarów)</small></dd>
        <?php if (($o['note'] ?? '') !== ''): ?><dt>Uwagi</dt><dd><?= h($o['note']) ?></dd><?php endif; ?>
      </dl>
      <div>
        <table>
          <tr><th>Produkt</th><th class="num">Il.</th><th class="num">Brutto</th></tr>
          <?php foreach ($o['items'] as $l): ?>
          <tr><td><?= h($l['name']) ?></td><td class="num"><?= (int) $l['qty'] ?></td><td class="num"><?= h(pln($l['line_gross'])) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Dostawa</td><td class="num"></td><td class="num"><?= h(pln($o['shipping_gross'])) ?></td></tr>
          <tr><td><b>Razem</b> <small style="color:var(--text-muted)">(netto <?= h(pln($o['total_net'])) ?> + VAT <?= h(pln($o['total_vat'])) ?>)</small></td>
              <td class="num"></td><td class="num"><b><?= h(pln($o['total_gross'])) ?></b></td></tr>
        </table>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:18px">
      <form method="post" style="display:flex;gap:8px">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <select name="status">
          <?php foreach (WSM_ORDER_STATUSES as $s): ?>
          <option value="<?= h($s) ?>"<?= $s === $o['status'] ? ' selected' : '' ?>><?= h($statusLabel[$s] ?? $s) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Zmień status</button>
      </form>
      <form method="post">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <button class="primary" type="submit" name="ship" value="1"
          <?= $blockers || $o['payment_status'] !== 'oplacone' ? 'disabled title="' . h($blockers ? 'Brak danych: ' . implode(', ', $blockers) : 'Zamówienie nieopłacone') . '"' : '' ?>>
          Utwórz przesyłkę InPost
        </button>
      </form>
    </div>
    <?php endif; ?>

    <h2 style="margin-top:26px">Ładunek ShipX</h2>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 10px">
      Dokładnie to, co poleci do InPost. Widoczne także zanim integracja zostanie włączona — braki widać od razu.
    </p>
    <?php if ($blockers): ?><p class="flash err">Brakuje: <?= h(implode(', ', $blockers)) ?></p><?php endif; ?>
    <pre><?= h(json_encode(wsm_inpost_payload($o), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

    <h2 style="margin-top:26px">Historia</h2>
    <table>
      <tr><th>Kiedy</th><th>Zdarzenie</th><th>Szczegóły</th><th>Kto</th></tr>
      <?php foreach ($events as $ev): ?>
      <tr><td class="num"><?= h((string) $ev['created_at']) ?></td><td><?= h((string) $ev['event']) ?></td>
          <td><?= h((string) $ev['detail']) ?></td><td><?= h((string) $ev['actor']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p style="margin-top:18px"><a class="code" href="zamowienia.php">← Wszystkie zamówienia</a></p>
  </div>
<?php endif; ?>

  <table>
    <tr><th>Numer</th><th>Data</th><th>Klient</th><th>Dostawa</th><th>Status</th><th>Płatność</th><th class="num">Poz.</th><th class="num">Brutto</th></tr>
    <?php if (!$orders): ?>
    <tr><td colspan="8" style="color:var(--text-muted)">Brak zamówień.</td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $o):
      $payCls = $o['payment_status'] === 'oplacone' ? 'ok' : ($o['payment_status'] === 'oczekuje' ? 'wait' : 'bad'); ?>
    <tr>
      <td><a class="code" href="?id=<?= (int) $o['id'] ?>"><?= h($o['code']) ?></a></td>
      <td class="num"><?= h(substr((string) $o['created_at'], 0, 16)) ?></td>
      <td><?= h($o['client']) ?><br><small style="color:var(--text-muted)"><?= h($o['email']) ?></small></td>
      <td><?= h($o['delivery_method'] === 'inpost_locker' ? 'Paczkomat' : 'Kurier') ?>
        <?= $o['inpost_point'] !== '' ? '<br><small style="color:var(--text-muted)">' . h($o['inpost_point']) . '</small>' : '' ?></td>
      <td><span class="tag"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span></td>
      <td><span class="tag <?= h($payCls) ?>"><?= h($payLabel[$o['payment_status']] ?? $o['payment_status']) ?></span></td>
      <td class="num"><?= (int) $o['units'] ?></td>
      <td class="num"><?= h(pln($o['total_gross'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
</body>
</html>
