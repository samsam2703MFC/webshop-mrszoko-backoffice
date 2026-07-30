<?php
// ============================================================================
//  rabaty.php — écran « Rabaty ilościowe » de la console marque.
//
//  La remise se calcule sur le POIDS total du panier, pas sur le montant :
//  c'est le kilogramme qui baisse avec le volume, et c'est ce que la boutique
//  promet depuis le début.
//
//  Un seul palier s'applique — le plus élevé atteint. Les paliers ne se
//  cumulent jamais : deux remises de 12 % et 20 % ne font pas 32 %.
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
function kg($g): string { return number_format(((int) $g) / 1000, ((int) $g) % 1000 ? 2 : 0, ',', ' ') . ' kg'; }

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać rabaty.'; $kind = 'err';
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM wsm_discount_tiers WHERE id = ?")->execute([(int) $_POST['delete']]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie', 'wsm_discount_tiers #' . (int) $_POST['delete'], 'Sieć');
        $flash = 'Usunięto próg.';
    } else {
        // Un poids en kilogrammes côté écran, en grammes en base : l'unité de
        // saisie doit être celle du métier, pas celle du stockage.
        $kgIn = str_replace(',', '.', (string) ($_POST['weight_kg'] ?? ''));
        $pct  = str_replace(',', '.', (string) ($_POST['percent'] ?? ''));
        $g    = (int) round(((float) $kgIn) * 1000);
        $p    = round((float) $pct, 2);

        if ($g <= 0)               $errors['weight_kg'] = 'waga musi być dodatnia';
        if ($p <= 0 || $p >= 100)  $errors['percent']   = 'rabat od 0 do 100 %';

        $id = (int) ($_POST['id'] ?? 0);
        if (!$errors) {
            // Deux paliers au même poids se contrediraient : l'un serait
            // simplement ignoré, sans qu'on sache lequel.
            $st = $pdo->prepare("SELECT id FROM wsm_discount_tiers WHERE min_weight_g = ? AND id <> ?");
            $st->execute([$g, $id]);
            if ($st->fetchColumn()) $errors['weight_kg'] = 'próg o tej wadze już istnieje';
        }
        if ($errors) {
            $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
        } elseif ($id > 0) {
            $pdo->prepare("UPDATE wsm_discount_tiers SET min_weight_g = ?, percent = ?, label = ?, active = ? WHERE id = ?")
                ->execute([$g, $p, mb_substr((string) ($_POST['label'] ?? ''), 0, 80),
                           empty($_POST['active']) ? 0 : 1, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_discount_tiers #' . $id, 'Sieć');
            $flash = 'Zapisano próg ' . kg($g) . ' → ' . $p . ' %.';
        } else {
            $pdo->prepare("INSERT INTO wsm_discount_tiers (min_weight_g, percent, label, active) VALUES (?,?,?,?)")
                ->execute([$g, $p, mb_substr((string) ($_POST['label'] ?? ''), 0, 80), empty($_POST['active']) ? 0 : 1]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Utworzenie', 'wsm_discount_tiers ' . kg($g), 'Sieć');
            $flash = 'Dodano próg ' . kg($g) . ' → ' . $p . ' %.';
        }
    }
}

$tiers = $pdo->query("SELECT * FROM wsm_discount_tiers ORDER BY min_weight_g")->fetchAll();
// Un exemple vaut mieux qu'une explication : on montre ce que donnerait un
// panier réel à chaque palier, sur le produit le plus vendu.
$ref = $pdo->query("SELECT nom, prix, weight_g FROM wsm_products
                     WHERE shop_visible = 1 AND weight_g > 0 ORDER BY sort_order LIMIT 1")->fetch() ?: null;
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rabaty ilościowe — Mister Szoko</title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; font-family: var(--font-sans); background: var(--bg-page-alt); color: var(--text-body); }
  .wrap { max-width: 1080px; margin: 0 auto; padding: 24px; }
  header.bar { background: var(--choco-800); color: var(--cream-50); }
  .bar-in { max-width: 1080px; margin: 0 auto; padding: 14px 24px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
  .bar-in img.logo { height: 40px; width: auto; }
  .bar-in h1 { font-family: var(--font-display); font-size: 20px; margin: 0; font-weight: 600; }
  .bar-in a { color: var(--cream-100); font-size: 13px; font-weight: 600; text-decoration: none;
              border-bottom: 1px solid var(--choco-600); }
  .bar-in .who { margin-left: auto; font-family: var(--font-mono); font-size: 12px; color: var(--choco-200); }
  .flash { border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok  { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
  .flash.err { background: color-mix(in srgb, var(--danger) 13%, transparent); color: var(--danger); }
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 80ch; line-height: 1.6; }
  .panel { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
           padding: 18px 20px; margin-bottom: 22px; box-shadow: var(--shadow-xs); }
  .panel h2 { font-family: var(--font-display); font-size: 18px; margin: 0 0 14px; color: var(--text-strong); }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 10px 12px; font-size: 13.5px; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
  th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); }
  tr:last-child td { border-bottom: 0; }
  td.num, th.num { text-align: right; font-family: var(--font-mono); }
  input[type=text], input[type=number] { font-family: var(--font-mono); font-size: 13.5px; padding: 8px 11px;
    border: 1px solid var(--border-default); border-radius: 9px; background: var(--bg-page); color: var(--text-strong); width: 100%; }
  input.wide { font-family: var(--font-sans); }
  label.chk { display: inline-flex; gap: 8px; align-items: center; font-size: 13px; cursor: pointer; }
  label.chk input { width: 17px; height: 17px; accent-color: var(--brand); }
  button { font-family: var(--font-sans); font-size: 13px; font-weight: 600; border-radius: 9px;
           border: 1px solid var(--border-default); padding: 8px 14px; background: var(--surface-card);
           color: var(--text-strong); cursor: pointer; }
  button.primary { background: var(--brand); color: var(--cream-50); border-color: var(--brand); }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  small.err { color: var(--danger); font-weight: 600; display: block; margin-top: 4px; }
  .add { display: grid; grid-template-columns: 130px 110px 1fr auto auto; gap: 12px; align-items: end; }
  .add label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
  .ex { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  @media (max-width: 760px) { .add { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <img class="logo" src="img/logo.png" alt="Mister Szoko">
    <h1>Rabaty ilościowe</h1>
    <a href="./">← Konsola</a>
    <a href="zamowienia.php">Zamówienia</a>
    <a href="produkty.php">Produkty</a>
    <a href="kraje.php">Kraje i VAT</a>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
  </div>
</header>

<div class="wrap">
  <?php if ($flash !== ''): ?><p class="flash <?= h($kind) ?>"><?= h($flash) ?></p><?php endif; ?>

  <p class="hint">
    Rabat liczy się od <b>wagi całego koszyka</b>, nie od kwoty — to kilogram tanieje wraz z ilością.
    Obowiązuje <b>jeden próg</b>: najwyższy osiągnięty. Progi nigdy się nie sumują (12 % i 20 % to nie 32 %).
    Rabat obniża ceny produktów; dostawa i próg darmowej wysyłki liczone są już od kwoty po rabacie.
    W koszyku klient widzi, ile brakuje do następnego progu.
  </p>

  <div class="panel">
    <h2>Progi</h2>
    <table>
      <tr><th>Od wagi</th><th class="num">Rabat</th><th>Opis</th><th>Aktywny</th><th></th><th></th></tr>
      <?php if (!$tiers): ?><tr><td colspan="6" style="color:var(--text-muted)">Brak progów — rabat nie jest naliczany.</td></tr><?php endif; ?>
      <?php foreach ($tiers as $t): ?>
      <tr>
        <form method="post">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <td><input type="text" name="weight_kg" value="<?= h(number_format((int) $t['min_weight_g'] / 1000, 2, ',', '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>></td>
          <td class="num"><input type="text" name="percent" value="<?= h(number_format((float) $t['percent'], 2, ',', '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>></td>
          <td><input class="wide" type="text" name="label" value="<?= h((string) $t['label']) ?>"<?= $isAdmin ? '' : ' disabled' ?>></td>
          <td><label class="chk"><input type="checkbox" name="active" value="1"<?= (int) $t['active'] === 1 ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>></label></td>
          <td><?php if ($isAdmin): ?><button class="primary" type="submit">Zapisz</button><?php endif; ?></td>
        </form>
        <td><?php if ($isAdmin): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <button class="danger" type="submit" name="delete" value="<?= (int) $t['id'] ?>">Usuń</button>
          </form>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Dodaj próg</h2>
    <form class="add" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <label>Od wagi (kg)
        <input type="text" name="weight_kg" placeholder="10" value="<?= h((string) ($_POST['weight_kg'] ?? '')) ?>">
        <?php if (isset($errors['weight_kg'])) echo '<small class="err">' . h($errors['weight_kg']) . '</small>'; ?>
      </label>
      <label>Rabat (%)
        <input type="text" name="percent" placeholder="12" value="<?= h((string) ($_POST['percent'] ?? '')) ?>">
        <?php if (isset($errors['percent'])) echo '<small class="err">' . h($errors['percent']) . '</small>'; ?>
      </label>
      <label>Opis <input class="wide" type="text" name="label" placeholder="od 10 kg"></label>
      <label class="chk"><input type="checkbox" name="active" value="1" checked> Aktywny</label>
      <button class="primary" type="submit">Dodaj</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($ref): $unit = (float) $ref['prix']; $w = (int) $ref['weight_g']; ?>
  <div class="panel">
    <h2>Ile to daje w praktyce</h2>
    <p class="hint" style="margin-bottom:12px">Na przykładzie: <b><?= h((string) $ref['nom']) ?></b>, <?= h(kg($w)) ?>, <?= number_format($unit, 2, ',', ' ') ?> zł.</p>
    <table>
      <tr><th>Sztuk</th><th class="num">Waga</th><th class="num">Rabat</th><th class="num">Do zapłaty</th><th class="num">Cena / kg</th></tr>
      <?php foreach ([1, 3, 5, 10, 20] as $n):
        $tw = $w * $n;
        [$pc, ] = wsm_discount_for_weight($pdo, $tw);
        $tot = round($unit * $n * (1 - $pc / 100), 2); ?>
      <tr>
        <td><?= $n ?></td>
        <td class="num"><?= h(kg($tw)) ?></td>
        <td class="num"><?= $pc > 0 ? '−' . rtrim(rtrim(number_format($pc, 2, ',', ''), '0'), ',') . ' %' : '—' ?></td>
        <td class="num"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
        <td class="num ex"><?= $tw > 0 ? number_format($tot / ($tw / 1000), 2, ',', ' ') . ' zł' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
