<?php
// ============================================================================
//  kraje.php — écran « Kraje i VAT » de la console marque.
//
//  Deux décisions se prennent ici, et elles ne se ressemblent pas :
//    · OÙ l'on vend — cocher un pays l'ouvre à la commande ;
//    · QUI l'on livre — quels pays chaque transporteur dessert réellement.
//
//  La TVA en découle, elle ne se règle pas à la main : Pologne = TVA polonaise ;
//  autre État membre + numéro de TVA confirmé par VIES = 0 % (autoliquidation).
//  Un particulier d'un autre pays paie la TVA polonaise — voir l'avertissement
//  sur le seuil OSS en bas de page.
// ============================================================================
declare(strict_types=1);

$API = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $API . '/db.php';
require_once $API . '/auth.php';
require_once $API . '/shop.php';
require_once $API . '/delivery.php';   // wsm_audit()

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$pdo = wsm_bootstrap();
wsm_session_start();
$me = wsm_current_user($pdo);
if (!$me) { header('Location: ./', true, 302); exit; }
$isAdmin = ($me['role'] ?? '') === WSM_ROLE_ADMIN;

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać ustawienia.'; $kind = 'err';
    } elseif (isset($_POST['save_countries'])) {
        $on = array_map('strtoupper', (array) ($_POST['active'] ?? []));
        // La Pologne reste toujours ouverte : c'est le marché domestique et le
        // pays d'établissement. La décocher fermerait la boutique.
        if (!in_array('PL', $on, true)) $on[] = 'PL';
        $pdo->prepare("UPDATE wsm_countries SET active = 0")->execute();
        $up = $pdo->prepare("UPDATE wsm_countries SET active = 1 WHERE code = ?");
        foreach ($on as $c) if (preg_match('/^[A-Z]{2}$/', $c)) $up->execute([$c]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_countries (' . count($on) . ' aktywnych)', 'Sieć');
        $flash = 'Zapisano kraje sprzedaży: ' . count($on) . '.';
    } elseif (isset($_POST['save_shipping'])) {
        $up = $pdo->prepare("UPDATE wsm_shipping_methods SET countries = ? WHERE id = ?");
        foreach ((array) ($_POST['countries'] ?? []) as $id => $list) {
            $codes = array_values(array_unique(array_filter(
                array_map(fn($c) => strtoupper(trim($c)), preg_split('/[,\s]+/', (string) $list) ?: []),
                fn($c) => preg_match('/^[A-Z]{2}$/', $c) === 1
            )));
            $up->execute([implode(',', $codes), (string) $id]);
        }
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_shipping_methods (zasięg)', 'Sieć');
        $flash = 'Zapisano zasięg przewoźników.';
    }
}

$countries = $pdo->query("SELECT * FROM wsm_countries ORDER BY sort_order, name_pl")->fetchAll();
$methods   = $pdo->query("SELECT * FROM wsm_shipping_methods ORDER BY sort_order")->fetchAll();
$nActive   = count(array_filter($countries, fn($c) => (int) $c['active'] === 1));
$nOrdersRc = 0;
try { $nOrdersRc = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE reverse_charge = 1")->fetchColumn(); }
catch (Throwable $e) {}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kraje i VAT — Mister Szoko</title>
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
  .rule { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          padding: 18px 20px; margin-bottom: 22px; box-shadow: var(--shadow-xs); font-size: 13.5px; line-height: 1.6; }
  .rule h2 { font-family: var(--font-display); font-size: 18px; margin: 0 0 10px; color: var(--text-strong); }
  .rule table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .rule td, .rule th { padding: 7px 10px; border-bottom: 1px solid var(--border-subtle); text-align: left; font-size: 13px; }
  .rule th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); }
  .rule tr:last-child td { border-bottom: 0; }
  .warnbox { background: color-mix(in srgb, var(--warning) 15%, transparent); color: var(--caramel-600);
             border-radius: 10px; padding: 12px 15px; font-size: 13px; line-height: 1.6; margin-top: 14px; }
  .kpis { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
         padding: 14px 18px; box-shadow: var(--shadow-xs); }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }
  .panel { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
           padding: 18px 20px; margin-bottom: 22px; box-shadow: var(--shadow-xs); }
  .panel h2 { font-family: var(--font-display); font-size: 18px; margin: 0 0 6px; color: var(--text-strong); }
  .panel p.sub { color: var(--text-muted); font-size: 13px; margin: 0 0 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 8px 16px; }
  label.c { display: flex; gap: 10px; align-items: center; font-size: 13.5px; cursor: pointer;
            padding: 7px 10px; border-radius: 9px; border: 1px solid transparent; }
  label.c:hover { border-color: var(--border-subtle); background: var(--surface-raised); }
  label.c input { width: 17px; height: 17px; accent-color: var(--brand); }
  label.c .code { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); }
  label.c.home { background: var(--brand-quiet); border-color: var(--border-default); }
  .ship { display: grid; grid-template-columns: 220px 1fr; gap: 12px 16px; align-items: center; }
  .ship input { font-family: var(--font-mono); font-size: 13px; padding: 9px 12px; width: 100%;
                border: 1px solid var(--border-default); border-radius: 9px; background: var(--bg-page); color: var(--text-strong); }
  button { font-family: var(--font-sans); font-size: 13.5px; font-weight: 600; border-radius: 9px;
           border: 1px solid var(--border-default); padding: 9px 16px; background: var(--surface-card);
           color: var(--text-strong); cursor: pointer; margin-top: 16px; }
  button.primary { background: var(--brand); color: var(--cream-50); border-color: var(--brand); }
  @media (max-width: 700px) { .ship { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <img class="logo" src="img/logo.png" alt="Mister Szoko">
    <h1>Kraje i VAT</h1>
    <a href="./">← Konsola</a>
    <a href="zamowienia.php">Zamówienia</a>
    <a href="produkty.php">Produkty</a>
    <a href="kontrahenci.php">Kontrahenci</a>
    <a href="rabaty.php">Rabaty</a>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
  </div>
</header>

<div class="wrap">
  <?php if ($flash !== ''): ?><p class="flash <?= h($kind) ?>"><?= h($flash) ?></p><?php endif; ?>

  <div class="rule">
    <h2>Jak liczony jest VAT</h2>
    <p>Stawka nie jest ustawiana ręcznie — wynika z kraju dostawy i z numeru VAT UE potwierdzonego w VIES.</p>
    <table>
      <tr><th>Kraj dostawy</th><th>Numer VAT UE</th><th>Stawka</th></tr>
      <tr><td><b>Polska</b> — rynek krajowy</td><td>obojętne</td><td><b>polski VAT (23 %)</b></td></tr>
      <tr><td>Inny kraj UE</td><td>potwierdzony w VIES jako <b>ważny</b></td><td><b>0 % — odwrotne obciążenie</b></td></tr>
      <tr><td>Inny kraj UE</td><td>brak, błędny lub VIES nie odpowiedział</td><td>polski VAT</td></tr>
    </table>
    <div class="warnbox">
      <b>Uwaga na próg OSS.</b> Klient prywatny z innego kraju UE płaci u nas polski VAT. Jest to poprawne
      dopiero do progu 10 000 € rocznej sprzedaży wysyłkowej do UE. Po jego przekroczeniu trzeba naliczać
      stawkę kraju odbiorcy (procedura OSS) — wtedy ten ekran wymaga rozbudowy o stawki krajowe.
      <br><b>Zwolnienie 0 % wymaga też dowodu wywozu towaru</b> do innego kraju UE — sam numer VAT nie wystarczy.
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><b><?= $nActive ?></b><span>Kraje otwarte</span></div>
    <div class="kpi"><b><?= count($countries) ?></b><span>Kraje UE w bazie</span></div>
    <div class="kpi"><b><?= $nOrdersRc ?></b><span>Zamówienia 0 %</span></div>
  </div>

  <form method="post">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <div class="panel">
      <h2>Gdzie sprzedajemy</h2>
      <p class="sub">Zaznaczone kraje pojawiają się w kasie sklepu. Polska jest zawsze otwarta — to kraj siedziby.</p>
      <div class="grid">
        <?php foreach ($countries as $c): $home = $c['code'] === 'PL'; ?>
        <label class="c<?= $home ? ' home' : '' ?>">
          <input type="checkbox" name="active[]" value="<?= h((string) $c['code']) ?>"
                 <?= (int) $c['active'] === 1 ? 'checked' : '' ?>
                 <?= $home || !$isAdmin ? 'disabled' : '' ?>>
          <?php if ($home): ?><input type="hidden" name="active[]" value="PL"><?php endif; ?>
          <span><?= h((string) $c['name_pl']) ?> <span class="code"><?= h((string) $c['code']) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($isAdmin): ?><button class="primary" type="submit" name="save_countries" value="1">Zapisz kraje</button><?php endif; ?>
    </div>
  </form>

  <form method="post">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <div class="panel">
      <h2>Zasięg przewoźników</h2>
      <p class="sub">Kody krajów po przecinku. Kraj otwarty na sprzedaż, ale bez przewoźnika, nie pozwoli złożyć zamówienia — i kasa powie to wprost.</p>
      <div class="ship">
        <?php foreach ($methods as $m): ?>
        <label for="s-<?= h((string) $m['id']) ?>"><b><?= h((string) $m['id']) ?></b><br>
          <small style="color:var(--text-muted)"><?= h((string) $m['carrier']) ?></small></label>
        <input id="s-<?= h((string) $m['id']) ?>" name="countries[<?= h((string) $m['id']) ?>]"
               value="<?= h((string) ($m['countries'] ?? '')) ?>" placeholder="PL"<?= $isAdmin ? '' : ' disabled' ?>>
        <?php endforeach; ?>
      </div>
      <?php if ($isAdmin): ?><button class="primary" type="submit" name="save_shipping" value="1">Zapisz zasięg</button><?php endif; ?>
    </div>
  </form>
</div>
</body>
</html>
