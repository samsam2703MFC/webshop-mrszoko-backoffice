<?php
// ============================================================================
//  kontrahenci.php — écran « Kontrahenci i VAT UE » de la console marque.
//
//  Ce que cet écran sert à voir d'un coup d'œil : quels numéros de TVA sont
//  vérifiés, lesquels n'ont pas pu l'être, et lesquels doivent l'être à nouveau.
//
//  Le bouton « Sprawdź » relance une consultation VIES. Le numéro de
//  consultation renvoyé par la Commission est affiché : c'est lui qu'on
//  produit en cas de contrôle, pas une capture d'écran.
// ============================================================================
declare(strict_types=1);

$API = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $API . '/db.php';
require_once $API . '/auth.php';
require_once $API . '/vies.php';
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

$flash = ''; $kind = 'ok'; $checked = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może uruchamiać weryfikację.'; $kind = 'err';
    } else {
        $vat = (string) ($_POST['vat_eu'] ?? '');
        $cid = (int) ($_POST['client_id'] ?? 0);
        $checked = wsm_vies_check($pdo, $vat, true);          // toujours forcé : c'est un bouton

        if ($cid > 0) {
            $cols = wsm_vies_columns($checked);
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
            $pdo->prepare("UPDATE wsm_clients SET $set WHERE id = ?")
                ->execute([...array_values($cols), $cid]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Weryfikacja', 'VIES ' . $checked['vat_eu'], 'Sieć');
        }
        $labels = ['valid' => 'Numer prawidłowy', 'invalid' => 'Numer nieznany w VIES',
                   'unavailable' => 'VIES nie odpowiedział — spróbuj później',
                   'skipped' => 'Weryfikacja pominięta'];
        $flash = ($labels[$checked['status']] ?? $checked['status'])
               . ($checked['reason'] !== '' ? ' · ' . $checked['reason'] : '');
        $kind = $checked['status'] === 'valid' ? 'ok' : ($checked['status'] === 'invalid' ? 'err' : 'warn');
    }
}

$rows = $pdo->query(
    "SELECT id, code, raison, client_type, nip, vat_eu, vat_status, vat_checked_at, vat_name, vat_consultation
       FROM wsm_clients ORDER BY (vat_eu = '') , raison"
)->fetchAll();

$withVat  = array_values(array_filter($rows, fn($r) => (string) $r['vat_eu'] !== ''));
$nValid   = count(array_filter($withVat, fn($r) => $r['vat_status'] === 'valid'));
$nUnknown = count(array_filter($withVat, fn($r) => in_array((string) $r['vat_status'], ['unavailable', ''], true)));
$cfg = wsm_vies_cfg();
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kontrahenci i VAT UE — Mister Szoko</title>
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
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.55; }
  .flash { border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok   { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
  .flash.err  { background: color-mix(in srgb, var(--danger) 13%, transparent); color: var(--danger); }
  .flash.warn { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--caramel-600); }
  .kpis { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
         padding: 14px 18px; box-shadow: var(--shadow-xs); }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }
  .panel { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
           padding: 18px 20px; margin-bottom: 22px; box-shadow: var(--shadow-xs); }
  .panel h2 { font-family: var(--font-display); font-size: 18px; margin: 0 0 12px; color: var(--text-strong); }
  .adhoc { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
  .adhoc label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600; color: var(--text-strong); }
  .adhoc input { font-family: var(--font-mono); font-size: 14px; font-weight: 400; padding: 9px 12px; min-width: 240px;
                 border: 1px solid var(--border-default); border-radius: 9px; background: var(--bg-page); color: var(--text-strong); }
  table { width: 100%; border-collapse: collapse; background: var(--surface-card);
          border: 1px solid var(--border-subtle); border-radius: 14px; overflow: hidden; }
  th, td { text-align: left; padding: 11px 14px; font-size: 13.5px; border-bottom: 1px solid var(--border-subtle);
           vertical-align: top; }
  th { background: var(--surface-raised); font-size: 11.5px; text-transform: uppercase;
       letter-spacing: .08em; color: var(--text-muted); }
  tr:last-child td { border-bottom: 0; }
  .mono { font-family: var(--font-mono); font-size: 12.5px; }
  .tag { display: inline-block; font-family: var(--font-mono); font-size: 11px; padding: 3px 9px;
         border-radius: 999px; background: var(--cream-200); color: var(--choco-700); white-space: nowrap; }
  .tag.ok   { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.err  { background: color-mix(in srgb, var(--danger) 15%, transparent); color: var(--danger); }
  .tag.warn { background: color-mix(in srgb, var(--warning) 22%, transparent); color: var(--caramel-600); }
  .tag.rc   { background: color-mix(in srgb, var(--berry-500) 16%, transparent); color: var(--berry-600); }
  button { font-family: var(--font-sans); font-size: 13px; font-weight: 600; border-radius: 9px;
           border: 1px solid var(--border-default); padding: 8px 14px; background: var(--surface-card);
           color: var(--text-strong); cursor: pointer; }
  button.primary { background: var(--brand); color: var(--cream-50); border-color: var(--brand); }
  small.muted { color: var(--text-muted); }
</style>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <img class="logo" src="img/logo.png" alt="Mister Szoko">
    <h1>Kontrahenci i VAT UE</h1>
    <a href="./">← Konsola</a>
    <a href="zamowienia.php">Zamówienia</a>
    <a href="produkty.php">Produkty</a>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
  </div>
</header>

<div class="wrap">
  <?php if ($flash !== ''): ?><p class="flash <?= h($kind) ?>"><?= h($flash) ?></p><?php endif; ?>

  <p class="hint">
    Numery VAT UE są sprawdzane w <b>VIES</b> (Komisja Europejska) przy zapisie kontrahenta i w kasie sklepu.
    Numer, którego VIES <b>nie zna</b>, jest odrzucany. Gdy VIES <b>nie odpowiada</b> — a zdarza się to
    regularnie, bo pyta administracje krajowe na żywo — zapis przechodzi i wpis trafia tutaj do ponownego
    sprawdzenia. Blokowanie sprzedaży z powodu awarii po stronie Komisji byłoby gorsze od problemu,
    który chcemy uniknąć.
    <?php if (!wsm_vies_can_prove()): ?>
      <br><b>Uwaga:</b> nie podano naszego numeru VAT (<code>WSM_VIES_REQUESTER</code>), więc VIES nie wydaje
      numeru konsultacji — nie ma dowodu na potrzeby kontroli.
    <?php endif; ?>
    <?php if (!$cfg['enabled']): ?>
      <br><b>Weryfikacja VIES jest wyłączona</b> — numery są sprawdzane tylko pod kątem formatu.
    <?php endif; ?>
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= count($withVat) ?></b><span>Z numerem VAT UE</span></div>
    <div class="kpi"><b><?= $nValid ?></b><span>Potwierdzone</span></div>
    <div class="kpi"><b><?= $nUnknown ?></b><span>Do sprawdzenia</span></div>
    <div class="kpi"><b><?= count($rows) ?></b><span>Kontrahenci</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Sprawdź dowolny numer</h2>
    <form class="adhoc" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <label>Numer VAT UE
        <input type="text" name="vat_eu" placeholder="PL5252248481"
               value="<?= h((string) ($_POST['vat_eu'] ?? '')) ?>" required>
      </label>
      <button class="primary" type="submit">Sprawdź w VIES</button>
    </form>
    <?php if ($checked && ($checked['status'] ?? '') === 'valid'): ?>
    <p style="margin:14px 0 0;font-size:13.5px;line-height:1.6">
      <b><?= h($checked['name'] ?: '—') ?></b><br>
      <span class="muted"><?= h($checked['address'] ?? '') ?></span><br>
      <span class="mono muted">Nr konsultacji: <?= h($checked['consultation'] ?: '— (brak naszego numeru VAT)') ?></span>
      <?php if (wsm_vies_reverse_charge($checked)): ?>
        <br><span class="tag rc">Kwalifikuje się do odwrotnego obciążenia</span>
        <small class="muted"> — sklep nadal nalicza polski VAT: wysyłamy wyłącznie do Polski.</small>
      <?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <table>
    <tr><th>Kontrahent</th><th>NIP</th><th>VAT UE</th><th>Status</th><th>Sprawdzono</th><th></th></tr>
    <?php foreach ($rows as $r):
      $vat = (string) $r['vat_eu'];
      $st  = (string) $r['vat_status'];
      $cls = ['valid' => 'ok', 'invalid' => 'err', 'unavailable' => 'warn'][$st] ?? '';
      $lbl = ['valid' => 'Potwierdzony', 'invalid' => 'Nieznany', 'unavailable' => 'Brak odpowiedzi',
              'skipped' => 'Pominięty'][$st] ?? '—'; ?>
    <tr>
      <td><?= h((string) $r['raison']) ?><br><small class="muted mono"><?= h((string) $r['code']) ?> · <?= h((string) $r['client_type']) ?></small></td>
      <td class="mono"><?= h((string) $r['nip']) ?: '—' ?></td>
      <td class="mono"><?= $vat !== '' ? h($vat) : '<span class="muted">—</span>' ?>
        <?php if ($vat !== '' && wsm_vies_reverse_charge(['status' => $st, 'country' => substr($vat, 0, 2)])): ?>
          <br><span class="tag rc">odwrotne obciążenie</span>
        <?php endif; ?>
      </td>
      <td><?php if ($vat !== ''): ?><span class="tag <?= h($cls) ?>"><?= h($lbl) ?></span>
            <?php if (($r['vat_name'] ?? '') !== ''): ?><br><small class="muted"><?= h((string) $r['vat_name']) ?></small><?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td class="mono"><?= h(substr((string) ($r['vat_checked_at'] ?? ''), 0, 16)) ?: '—' ?>
        <?php if (($r['vat_consultation'] ?? '') !== ''): ?><br><small class="muted"><?= h((string) $r['vat_consultation']) ?></small><?php endif; ?>
      </td>
      <td>
        <?php if ($isAdmin && $vat !== ''): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="vat_eu" value="<?= h($vat) ?>">
          <input type="hidden" name="client_id" value="<?= (int) $r['id'] ?>">
          <button type="submit">Sprawdź</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
</body>
</html>
