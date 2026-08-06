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

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/tpay.php';
require_once $API . '/inpost.php';
require_once $API . '/mail.php';
require_once $API . '/stock.php';

$flash = ''; $flashKind = 'ok';

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];
$payLabel = ['oczekuje' => 'Oczekuje', 'oplacone' => 'Opłacone', 'nieudane' => 'Nieudana',
             'niedostepne' => 'Niedostępna'];

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
            if (!in_array($new, WSM_ORDER_STATUSES, true)) {
                $flash = 'Nieznany status.'; $flashKind = 'err';
            } elseif ($new === (string) $order['status']) {
                $flash = 'Zamówienie już ma ten status — nic nie zmieniono.';
            } else {
                // Le point unique : passer à « wysłane » émet la facture ou
                // l'e-paragon, l'envoie et le dépose au KSeF.
                $chg = wsm_order_status_set($pdo, (int) $id, $new, (string) ($me['nom'] ?? ''));
                wsm_order_event($pdo, $id, 'status', $new, (string) ($me['nom'] ?? ''));
                $flash = $order['code'] . ' → ' . ($statusLabel[$new] ?? $new);
                // CE QUI VIENT D'ÊTRE ÉMIS, DIT TOUT DE SUITE. Un document part
                // au client et au registre national sur ce clic : le passer sous
                // silence, c'est le découvrir un mois plus tard dans Faktury.
                if (($chg['note'] ?? '') !== '') $flash .= ' · ' . $chg['note'];

                // Le client apprend le changement s'il y a un modèle pour cet
                // état ET si l'opérateur ne l'a pas décoché. Un changement de
                // rangement interne ne mérite pas toujours un e-mail.
                if (!empty($_POST['powiadom'])) {
                    $fresh = wsm_order_by_id($pdo, $id) ?: $order;
                    $mid = wsm_mail_for_status($pdo, $fresh, $new, (string) ($me['nom'] ?? ''));
                    if ($mid > 0) {
                        $flash .= ' · powiadomiono ' . $fresh['email'];
                    } elseif (in_array($new, WSM_MAIL_STATUS_EVENTS, true)) {
                        // Distinguer « déjà envoyé » de « pas de modèle » évite
                        // de chercher une panne là où il n'y en a pas.
                        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE event_key = ?");
                        $st->execute(['status:' . $id . ':' . $new]);
                        $flash .= (int) $st->fetchColumn() > 0
                            ? ' · maila o tym statusie już wcześniej wysłano'
                            : ' · bez maila (brak szablonu, adresu lub poczta wyłączona)';
                    }
                }
            }
        } elseif (isset($_POST['wz'])) {
            [$d, $err] = wsm_stock_issue_wz($pdo, $order, (string) ($me['nom'] ?? ''));
            if ($err !== null) { $flash = $err; $flashKind = 'err'; }
            else $flash = 'Wystawiono ' . $d['number'] . ' — dokument wydania.';
        } elseif (isset($_POST['ship'])) {
            [$sh, $err] = wsm_inpost_create($pdo, $order);
            if ($err !== null) { $flash = 'InPost: ' . $err; $flashKind = 'err'; }
            else { $flash = 'Utworzono przesyłkę ' . ($sh['tracking_number'] ?? ''); }
        }
    }
}

$detail = isset($_GET['id']) ? wsm_order_by_id($pdo, (int) $_GET['id']) : null;

// Vues imprimables : la feuille de préparation et l'étiquette. Sorties avant
// tout rendu de la console — ce sont des documents, pas des écrans.
if ($detail && isset($_GET['druk']))     { $o = $detail; include __DIR__ . '/zamowienie_druk.php'; exit; }
if ($detail && isset($_GET['etykieta'])) { $o = $detail; include __DIR__ . '/etykieta_druk.php'; exit; }
$orders = wsm_orders_list($pdo, 200);
$kpis   = wsm_shop_kpis($pdo);
$cfg    = ['tpay' => wsm_tpay_enabled(), 'inpost' => wsm_inpost_enabled()];


console_head('Zamówienia', $me, '', $kpis['orders_pending'] ? $kpis['orders_pending'] . ' czeka na płatność' : '');
console_flash($flash, $flashKind);
console_crumbs($detail
    ? ['Pulpit' => 'pulpit.php', 'Zamówienia' => 'zamowienia.php', $detail['code'] => null]
    : ['Pulpit' => 'pulpit.php', 'Zamówienia' => null]);
?>
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
  $blockers = wsm_inpost_blockers($o);
  // Le WZ est cherché ici, pas dans le bloc réservé à Centrala : un préparateur
  // doit pouvoir IMPRIMER le bon de sortie même s'il n'a pas le droit de le
  // créer. Lire n'est pas écrire.
  $wzq = $pdo->prepare("SELECT id, number FROM wsm_stock_docs WHERE kind='WZ' AND order_id = ?");
  $wzq->execute([(int) $o['id']]);
  $wzRow = $wzq->fetch() ?: null; ?>
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
          <small class="muted"> (szacunek z wymiarów)</small></dd>
        <?php if (($o['note'] ?? '') !== ''): ?><dt>Uwagi</dt><dd><?= h($o['note']) ?></dd><?php endif; ?>
      </dl>
      <div class="tablewrap">
        <table>
          <tr><th>Produkt</th><th class="num">Il.</th><th class="num">Brutto</th></tr>
          <?php foreach ($o['items'] as $l): ?>
          <tr><td><?= h($l['name']) ?></td><td class="num"><?= (int) $l['qty'] ?></td><td class="num"><?= h(pln($l['line_gross'])) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Dostawa</td><td class="num"></td><td class="num"><?= h(pln($o['shipping_gross'])) ?></td></tr>
          <tr><td><b>Razem</b> <small class="muted">(netto <?= h(pln($o['total_net'])) ?> + VAT <?= h(pln($o['total_vat'])) ?>)</small></td>
              <td class="num"></td><td class="num"><b><?= h(pln($o['total_gross'])) ?></b></td></tr>
        </table>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="actions">
      <form method="post" style="align-items:center">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <select name="status">
          <?php foreach (WSM_ORDER_STATUSES as $s): ?>
          <option value="<?= h($s) ?>"<?= $s === $o['status'] ? ' selected' : '' ?>><?= h($statusLabel[$s] ?? $s) ?></option>
          <?php endforeach; ?>
        </select>
        <label style="display:flex;align-items:center;gap:7px;font-size:13.5px;white-space:nowrap">
          <input type="checkbox" name="powiadom" value="1" checked>
          Powiadom klienta
        </label>
        <button type="submit">Zmień status</button>
      </form>
      <form method="post">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <?php if ($wzRow): ?>
        <a class="code" href="magazyn.php?dok=<?= (int) $wzRow['id'] ?>">WZ <?= h((string) $wzRow['number']) ?> →</a>
        <?php else: ?>
        <button type="submit" name="wz" value="1">Utwórz WZ (wydanie)</button>
        <?php endif; ?>
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

    <?php
    // Ce qu'on imprime réellement pour un colis : le bon de préparation, le WZ
    // qui part avec la marchandise et se signe à la réception, et l'étiquette.
    // L'étiquette du transporteur est celle qui fait foi dès qu'elle existe —
    // la nôtre ne sert qu'à défaut, et le dit sur la feuille.
    $shipRow = $pdo->prepare("SELECT shipment_id, tracking_number FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $shipRow->execute([(int) $o['id']]);
    $shipNow = $shipRow->fetch() ?: [];
    $maPrzesylke = trim((string) ($shipNow['shipment_id'] ?? '')) !== '';
    ?>
    <h2 style="margin-top:22px">Wydruki</h2>
    <p class="actions" style="margin-top:8px">
      <a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Bon zamówienia (A4) ↗</a>
      <?php if ($wzRow): ?>
      <a class="code" href="magazyn.php?dok=<?= (int) $wzRow['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Drukuj WZ <?= h((string) $wzRow['number']) ?> (A4) ↗</a>
      <?php endif; ?>
      <?php
      // L'ÉTIQUETTE SUIT LE TRANSPORTEUR. Le lien pointait sur InPost quel que
      // soit le colis : une commande DPD envoyait chercher son étiquette chez
      // un transporteur qui ne la connaît pas, et l'on cherchait la panne du
      // mauvais côté. Le transporteur se lit dans la table des méthodes.
      $carrier = wsm_ship_carrier($pdo, (string) $o['delivery_method']);
      $ecranEt = $carrier === 'dpd' ? 'etykieta_dpd.php' : 'etykieta_inpost.php';
      $nomEt   = $carrier === 'dpd' ? 'DPD' : 'InPost';
      ?>
      <?php if ($maPrzesylke): ?>
      <a class="code" href="<?= h($ecranEt) ?>?id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener">Etykieta <?= h($nomEt) ?> — A6 ↗</a>
      <a class="code" href="<?= h($ecranEt) ?>?id=<?= (int) $o['id'] ?>&amp;format=a4" target="_blank" rel="noopener">Etykieta <?= h($nomEt) ?> — A4 ↗</a>
      <?php endif; ?>
      <a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;etykieta=1" target="_blank" rel="noopener">Etykieta wewnętrzna ↗</a>
    </p>
    <p class="muted small" style="margin-top:4px">
      <?php if ($maPrzesylke): ?>
        Na paczkę naklejamy <b>etykietę <?= h($nomEt) ?></b> — to ona ma kod kreskowy i tylko ona jest
        listem przewozowym. A6 na drukarkę etykiet, A4 na zwykłą kartkę.
        Etykieta wewnętrzna to opis pomocniczy, nie przewozowy.
      <?php else: ?>
        Przesyłka nie została jeszcze utworzona w <?= h($nomEt) ?>, więc etykiety przewoźnika nie ma.
        Do tego czasu można wydrukować <b>etykietę wewnętrzną</b> — nie zastępuje listu przewozowego.
      <?php endif; ?>
      <?php if (!$wzRow): ?>
        <br>WZ pojawi się tu do druku po utworzeniu dokumentu wydania.
      <?php endif; ?>
    </p>

    <h2 style="margin-top:26px">Ładunek ShipX</h2>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 10px">
      Dokładnie to, co poleci do InPost. Widoczne także zanim integracja zostanie włączona — braki widać od razu.
    </p>
    <?php if ($blockers): ?><p class="flash err">Brakuje: <?= h(implode(', ', $blockers)) ?></p><?php endif; ?>
    <pre><?= h(json_encode(wsm_inpost_payload($o), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

    <h2 style="margin-top:26px">Wiadomości do klienta</h2>
    <?php $msgs = wsm_messages_list($pdo, ['order_id' => (int) $o['id'], 'limit' => 50]); ?>
    <?php if (!$msgs): ?>
    <p class="muted small">Nic jeszcze nie wysłano.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Temat</th><th>Stan</th></tr></thead>
      <tbody>
      <?php foreach ($msgs as $m): ?>
      <tr>
        <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
        <td data-l="Temat" class="wide"><a class="code" href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h($m['subject']) ?></a></td>
        <td data-l="Stan"><span class="tag <?= $m['status'] === 'wyslana' ? 'ok' : ($m['status'] === 'blad' ? 'bad' : 'wait') ?>"><?= h($m['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="actions"><a class="code" href="poczta.php?order_id=<?= (int) $o['id'] ?>&amp;tpl=kontakt">Napisz do klienta →</a></p>

    <h2 style="margin-top:26px">Historia</h2>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Zdarzenie</th><th>Szczegóły</th><th>Kto</th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
      <tr><td data-l="Kiedy" class="num"><?= h((string) $ev['created_at']) ?></td><td data-l="Zdarzenie"><?= h((string) $ev['event']) ?></td>
          <td data-l="Szczegóły" class="wide"><?= h((string) $ev['detail']) ?></td><td data-l="Kto"><?= h((string) $ev['actor']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="margin-top:18px"><a class="code" href="zamowienia.php">← Wszystkie zamówienia</a></p>
  </div>
<?php endif; ?>

  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Numer</th><th>Data</th><th>Klient</th><th>Dostawa</th><th>Status</th><th>Płatność</th><th class="num">Poz.</th><th class="num">Brutto</th></tr></thead>
    <tbody>
    <?php if (!$orders): ?>
    <tr><td class="muted">Brak zamówień.</td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $o):
      $payCls = $o['payment_status'] === 'oplacone' ? 'ok' : ($o['payment_status'] === 'oczekuje' ? 'wait' : 'bad'); ?>
    <tr>
      <td data-l="Numer"><a class="code" href="?id=<?= (int) $o['id'] ?>"><?= h($o['code']) ?></a></td>
      <td data-l="Data" class="num"><?= h(substr((string) $o['created_at'], 0, 16)) ?></td>
      <td data-l="Klient"><?= h($o['client']) ?><br><small class="muted"><?= h($o['email']) ?></small></td>
      <?php // « Kurier » tout court ne dit plus lequel : avec deux transporteurs,
            // c'est l'information qu'on cherche en premier quand un colis coince. ?>
      <td data-l="Dostawa"><?= h(wsm_ship_kind($pdo, (string) $o['delivery_method']) === 'punkt'
            ? 'Paczkomat' : 'Kurier ' . strtoupper(wsm_ship_carrier($pdo, (string) $o['delivery_method']))) ?>
        <?= $o['inpost_point'] !== '' ? '<br><small class="muted">' . h($o['inpost_point']) . '</small>' : '' ?></td>
      <td data-l="Status"><span class="tag"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
        <?php if (!empty($o['backorder'])): ?> <span class="tag no">do potwierdzenia</span><?php endif; ?>
        <?php if (($o['discount_percent'] ?? 0) > 0): ?> <span class="tag">−<?= (int) $o['discount_percent'] ?> %</span><?php endif; ?></td>
      <td data-l="Płatność"><span class="tag <?= h($payCls) ?>"><?= h($payLabel[$o['payment_status']] ?? $o['payment_status']) ?></span></td>
      <td data-l="Pozycje" class="num"><?= (int) $o['units'] ?></td>
      <td data-l="Brutto" class="num"><?= h(pln($o['total_gross'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php console_foot();
