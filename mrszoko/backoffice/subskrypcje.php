<?php
// ============================================================================
//  subskrypcje.php — écran « Subskrypcje » de la console.
//
//  CE QUE CET ÉCRAN NE DOIT JAMAIS LAISSER CROIRE : qu'on prélève. La
//  boutique n'enregistre aucune carte. À l'échéance, la commande est
//  PRÉPARÉE et un lien de paiement part. Le bandeau le dit en toutes
//  lettres, parce que la personne qui tient la console répondra un jour à un
//  client qui demande « quand serai-je débité ».
//
//  Le passage des échéances est déclenché ICI, à la main, tant qu'aucune
//  tâche planifiée n'existe sur le serveur. Un bouton visible vaut mieux
//  qu'un automatisme qu'on croit actif et qui ne tourne pas.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/cykl.php';
require_once $API . '/mail.php';
wsm_cykl_ensure($pdo);

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
        $flash = 'Tylko rola Centrala może zmieniać subskrypcje.'; $kind = 'err';
    } elseif (isset($_POST['stan'], $_POST['id'])) {
        [$ok, $m] = wsm_cykl_statut($pdo, (int) $_POST['id'], (string) $_POST['stan'], (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['przelicz'])) {
        // Le passage du jour. On dit COMBIEN et LESQUELLES : « fait » sans
        // chiffre laisse croire que rien n'a bougé.
        $r = wsm_cykl_run($pdo);
        $flash = $r['przetworzone'] > 0
            ? 'Przygotowano ' . $r['przetworzone'] . ' zamówień: ' . implode(', ', $r['zamowienia'])
              . '. Nic nie zostało pobrane — klienci dostali linki do zapłaty.'
            : 'Żaden termin nie wypadał na dziś.';
        if ($r['bledy']) { $flash .= ' Problemy: ' . implode(' · ', $r['bledy']); $kind = 'err'; }
    }
}

$subs = wsm_cykl_list($pdo);
$dues = wsm_cykl_dues($pdo);
$aktywne = 0; $obrot = 0;
foreach ($subs as $s) { if ((string) $s['statut'] === 'aktywny') $aktywne++; $obrot += (int) $s['obrot']; }

console_head('Subskrypcje', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 80ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
         padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .etat { font-size: 12px; padding: 2px 9px; border-radius: 999px; border: 1px solid var(--border-subtle);
          white-space: nowrap; }
  .etat.aktywny { color: var(--ok, #1a7f4b); border-color: color-mix(in srgb, var(--ok, #1a7f4b) 40%, transparent); }
  .etat.wstrzymana { color: var(--warn, #9a6a00); border-color: color-mix(in srgb, var(--warn, #9a6a00) 40%, transparent); }
  .etat.zakonczona { color: var(--text-muted); }
  .dzis { color: var(--brand); font-weight: 700; }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  .akcje { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
  .akcje form { display: inline; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Subskrypcje' => null]);
?>

  <p class="hint">
    <b>Nic nie jest pobierane automatycznie.</b> Sklep nie przechowuje kart płatniczych.
    W dniu terminu przygotowujemy zamówienie i wysyłamy klientowi link do zapłaty —
    tak jak piekarz odkłada chleb: czeka, nie sięga do kieszeni.
    Cena jest <b>dzisiejsza</b>, nie sprzed trzech miesięcy. Produkt zdjęty z katalogu
    nie kasuje całego terminu — wysyłamy resztę i o tym mówimy.
    Po <b>trzech nieopłaconych terminach</b> subskrypcja sama się wstrzymuje.
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $aktywne ?></b><span>aktywnych subskrypcji</span></div>
    <div class="kpi"><b><?= count($dues) ?></b><span>terminów na dziś</span></div>
    <div class="kpi"><b><?= h(pln($obrot)) ?></b><span>obrotu z subskrypcji</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Terminy na dziś</h2>
    <?php if (!$dues): ?>
      <p class="muted">Żaden termin nie wypada na dziś.</p>
    <?php else: ?>
      <p class="hint" style="margin-bottom:12px">
        <?= count($dues) ?> subskrypcji czeka na przetworzenie. Przygotujemy zamówienia
        i wyślemy linki do zapłaty. <b>Nic nie zostanie pobrane.</b>
      </p>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <button class="primary" type="submit" name="przelicz" value="1">Przygotuj zamówienia</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Wszystkie subskrypcje</h2>
    <?php if (!$subs): ?>
      <p class="muted">Brak subskrypcji. Powstają z zamówienia klienta — ekran Zamówienia.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Klient</th><th>Rytm</th><th>Następny termin</th><th>Stan</th>
                 <th class="num">Pozycji</th><th class="num">Zamówień</th><th class="num">Obrót</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($subs as $s):
        $etat = (string) $s['statut'];
        $dzis = $etat === 'aktywny' && (string) $s['next_at'] <= date('Y-m-d'); ?>
      <tr>
        <td data-l="Klient">
          <b><?= h(trim((string) $s['first_name'] . ' ' . (string) $s['last_name']) ?: (string) $s['company']) ?></b><br>
          <small class="muted"><?= h((string) $s['email']) ?></small>
          <?php if (trim((string) $s['note']) !== ''): ?><br><small class="muted"><?= h((string) $s['note']) ?></small><?php endif; ?>
        </td>
        <td data-l="Rytm"><?= h((string) $s['rytm_label']) ?></td>
        <td data-l="Następny termin"<?= $dzis ? ' class="dzis"' : '' ?>>
          <?= h((string) $s['next_at']) ?><?= $dzis ? ' — dziś' : '' ?></td>
        <td data-l="Stan"><span class="etat <?= h($etat) ?>"><?= h($etat) ?></span>
          <?php if ((int) $s['unpaid_streak'] > 0): ?>
          <br><small class="muted"><?= (int) $s['unpaid_streak'] ?> bez zapłaty</small>
          <?php endif; ?></td>
        <td data-l="Pozycji" class="num"><?= (int) $s['pozycji'] ?></td>
        <td data-l="Zamówień" class="num"><?= (int) $s['zamowien'] ?></td>
        <td data-l="Obrót" class="num"><?= h(pln((int) $s['obrot'])) ?></td>
        <td class="num">
          <?php if ($isAdmin && $etat !== 'zakonczona'): ?>
          <div class="akcje">
            <?php if ($etat === 'aktywny'): ?>
            <form method="post">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" name="stan" value="wstrzymana">Wstrzymaj</button>
            </form>
            <?php else: ?>
            <form method="post">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="primary" type="submit" name="stan" value="aktywny">Wznów</button>
            </form>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="danger" type="submit" name="stan" value="zakonczona">Zakończ</button>
            </form>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php console_foot();
