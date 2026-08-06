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
// wsm_invoice_kind_for() : l'écran annonce QUEL document partira au passage à
// « wysłane ». Sans ce require, le function_exists() plus bas répondrait non et
// la ligne disparaîtrait en silence — le pire des deux mondes.
require_once $API . '/invoice.php';

$flash = ''; $flashKind = 'ok';

// ─── CSRF ────────────────────────────────────────────────────────────────
//
// CET ÉCRAN N'EN AVAIT AUCUN. Il change l'état d'une commande — et depuis
// aujourd'hui ce changement ÉMET UN DOCUMENT FISCAL, l'envoie au client et le
// dépose au registre national. Une image distante pointant sur un POST
// suffisait à faire expédier et facturer une commande à l'insu de la personne
// connectée. Les autres écrans (Ustawienia, Superadmin, Kraje) portaient déjà
// ce jeton ; celui-ci était passé au travers.
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];
$payLabel = ['oczekuje' => 'Oczekuje', 'oplacone' => 'Opłacone', 'nieudane' => 'Nieudana',
             'niedostepne' => 'Niedostępna'];

// ---- Actions (réservées à Centrala) ---------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    // Lu AVANT le contrôle de rôle : la redirection qui suit s'en sert pour
    // ramener sur la bonne ligne, y compris quand l'action a été refusée.
    $id = (int) ($_POST['id'] ?? 0);
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać zamówienia.'; $flashKind = 'err';
    } else {
        $order = wsm_order_by_id($pdo, $id);
        if (!$order) {
            $flash = 'Nie znaleziono zamówienia.'; $flashKind = 'err';
        } elseif (isset($_POST['vies'])) {
            // Reconsulter MAINTENANT. Le contrôle qui compte est celui du jour
            // de la livraison ; on veut pouvoir le refaire avant d'expédier,
            // sans attendre le passage automatique.
            $order = wsm_order_vies_refresh($pdo, $order);
            $vs = strtolower((string) ($order['vat']['status'] ?? ''));
            $flash = $order['code'] . ' · VIES: ' . ($vs !== '' ? $vs : 'brak odpowiedzi')
                   . ' — przy „Wysłane" powstanie: ' . wsm_invoice_kind_for($order)['kind'] . '.';
            $flashKind = $vs === 'invalid' ? 'err' : 'ok';

        } elseif (isset($_POST['ksef'])) {
            $doc = wsm_invoice_for_order($pdo, $id);
            if (!$doc) { $flash = 'Nie ma jeszcze dokumentu.'; $flashKind = 'err'; }
            else {
                require_once $API . '/ksef.php';
                [$num, $err] = wsm_ksef_wyslij($pdo, wsm_invoice_hydrate($pdo, $doc),
                                               (string) ($me['nom'] ?? ''));
                $flash = $num ? 'Zgłoszono do KSeF: ' . $num : 'KSeF: ' . $err;
                $flashKind = $num ? 'ok' : 'err';
            }

        } elseif (isset($_POST['status'])) {
            // UNE SEULE PORTE. « do_wysylki » et « wyslane » étaient deux
            // branches de plus, ajoutées pour les deux boutons d'expédition de
            // la liste. Ces boutons sont devenus des étapes, qui postent un
            // statut comme la fiche : les branches ne servaient plus rien, et
            // un chemin que personne n'emprunte est un chemin que personne ne
            // corrige — celui qui n'émettra pas de document au prochain
            // changement de règle.
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

    // ─── ON RÉPOND PAR UNE REDIRECTION, ET ON REVIENT SUR LA LIGNE ────────
    //
    // Cet écran répondait au POST en réaffichant la page. Deux conséquences,
    // les deux payées par la personne qui s'en sert toute la journée :
    //
    //  · Rafraîchir la page rejouait l'action. Sur un écran qui émet des
    //    documents fiscaux, « voulez-vous renvoyer le formulaire ? » est une
    //    question qu'on ne devrait jamais avoir à se poser.
    //  · On repartait EN HAUT d'une liste de deux cents commandes. Un geste
    //    au milieu de la liste, et il faut re-dérouler jusqu'à sa place —
    //    quarante fois par jour, au téléphone, une main prise par un colis.
    //
    // L'ancre ramène à la ligne touchée, et le message survit dans la
    // session le temps d'un aller-retour.
    $_SESSION['zam_flash'] = [$flash, $flashKind];
    $vers = 'zamowienia.php'
          . (isset($_GET['id']) ? '?id=' . (int) $_GET['id'] : '')
          . ($id > 0 ? '#z' . $id : '');
    header('Location: ' . $vers, true, 303);
    exit;
}
if (isset($_SESSION['zam_flash'])) {
    [$flash, $flashKind] = $_SESSION['zam_flash'];
    unset($_SESSION['zam_flash']);
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
        <?php
        // ─── VIES : L'ÉTAT DU NUMÉRO, ET CE QU'IL ENTRAÎNE ────────────────
        //
        // Sans cette ligne, on ne savait pas — avant l'expédition — si la
        // commande partirait avec une facture ou un e-paragon. On le
        // découvrait après, dans Faktury. Or c'est AVANT que ça se corrige :
        // un numéro refusé se discute avec le client pendant qu'on prépare le
        // colis, pas une fois le document déposé au registre national.
        //
        // Le numéro de CONSULTATION est affiché parce que c'est lui la preuve
        // en contrôle fiscal — pas la date, pas le nom.
        $vs = strtolower((string) ($o['vat_status'] ?? ($o['vat']['status'] ?? '')));
        $ve = trim((string) ($o['vat_eu'] ?? ''));
        if ($ve !== '' || $vs !== ''):
          $et = ['valid' => ['ok',  'VIES: potwierdzony'],
                 'invalid' => ['no', 'VIES: ODRZUCONY — będzie paragon'],
                 'unavailable' => ['', 'VIES: niedostępny'],
                 'skipped' => ['',    'VIES: niesprawdzony']][$vs] ?? ['', 'VIES: niesprawdzony'];
          $doc = function_exists('wsm_invoice_kind_for') ? wsm_invoice_kind_for($o) : null; ?>
        <dt>VIES</dt>
        <dd><span class="tag <?= h($et[0]) ?>"><?= h($et[1]) ?></span>
          <?php if ($ve !== ''): ?><br><span class="code"><?= h($ve) ?></span><?php endif; ?>
          <?php if (($o['vat']['checked_at'] ?? $o['vat_checked_at'] ?? '') !== ''): ?>
            <br><small class="muted">sprawdzono <?= h((string) ($o['vat']['checked_at'] ?? $o['vat_checked_at'])) ?></small>
          <?php endif; ?>
          <?php if (($o['vat']['consultation'] ?? $o['vat_consultation'] ?? '') !== ''): ?>
            <br><small class="muted">nr konsultacji <span class="code"><?= h((string) ($o['vat']['consultation'] ?? $o['vat_consultation'])) ?></span></small>
          <?php endif; ?>
          <?php if ($doc): ?>
            <br><small class="muted">Przy „wysłane": <b><?= h($doc['kind']) ?></b> — <?= h($doc['raison']) ?>.
              VIES zostanie sprawdzony ponownie tuż przed wystawieniem.</small>
          <?php endif; ?>
        </dd>
        <?php endif; ?>
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
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
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
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <?php if ($wzRow): ?>
        <a class="code" href="magazyn.php?dok=<?= (int) $wzRow['id'] ?>">WZ <?= h((string) $wzRow['number']) ?> →</a>
        <?php else: ?>
        <button type="submit" name="wz" value="1">Utwórz WZ (wydanie)</button>
        <?php endif; ?>
      </form>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
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
  <?php // « dense » : neuf colonnes, dont une qui porte six boutons. Avec le
        // rembourrage habituel, ce tableau-là dépasse la largeur de la page et
        // s'offre une barre de défilement horizontale PERMANENTE — sur l'écran
        // le plus consulté de la maison, et quelle que soit la taille de
        // l'écran, puisque la zone de travail est plafonnée à 1180 px. ?>
  <table class="rwd dense zam">
    <thead><tr><th>Numer</th><th>Data</th><th>Klient</th><th>Dostawa</th><th>Status</th><th>Płatność</th><th class="num">Poz.</th><th class="num">Brutto</th><th>Kontrolki</th></tr></thead>
    <tbody>
    <?php if (!$orders): ?>
    <tr><td class="muted">Brak zamówień.</td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $o):
      $payCls = $o['payment_status'] === 'oplacone' ? 'ok' : ($o['payment_status'] === 'oczekuje' ? 'wait' : 'bad'); ?>
    <tr id="z<?= (int) $o['id'] ?>">
      <td data-l="Numer"><a class="code" href="?id=<?= (int) $o['id'] ?>"><?= h($o['code']) ?></a></td>
      <?php // DATE ET HEURE, CHACUNE D'UN SEUL TENANT. En un seul morceau de
            // texte, la colonne se faisait couper par le tableau en « 2026- /
            // 08-06 / 17:01 » — trois lignes qui se lisent comme trois données.
            // Séparées, chacune reste entière : deux lignes au bureau, une
            // seule sur la fiche du téléphone, jamais d'escalier. ?>
      <td data-l="Data" class="num"><span class="dt"><?= h(substr((string) $o['created_at'], 0, 10)) ?></span>
        <span class="tm"><?= h(substr((string) $o['created_at'], 11, 5)) ?></span></td>
      <td data-l="Klient"><?= h($o['client']) ?><br><small class="muted"><?= h($o['email']) ?></small></td>
      <?php // « Kurier » tout court ne dit plus lequel : avec deux transporteurs,
            // c'est l'information qu'on cherche en premier quand un colis coince. ?>
      <td data-l="Dostawa"><?= h(wsm_ship_kind($pdo, (string) $o['delivery_method']) === 'punkt'
            ? 'Paczkomat' : 'Kurier ' . strtoupper(wsm_ship_carrier($pdo, (string) $o['delivery_method']))) ?>
        <?= $o['inpost_point'] !== '' ? '<br><small class="muted">' . h($o['inpost_point']) . '</small>' : '' ?></td>
      <?php
      // ─── LE STATUT EST LE BOUTON — MAIS UN SEUL EST GROS ────────────────
      //
      // Première version : les six étapes en pastilles sur chaque ligne. Ça
      // marchait, et c'était illisible — deux cents commandes, mille deux
      // cents boutons, et sur un téléphone UNE seule commande par écran. Le
      // geste de tous les jours (avancer d'un cran) avait exactement le même
      // poids visuel que celui qu'on fait trois fois par an (revenir à
      // « Nowe »), et que celui qu'on ne veut jamais faire par erreur.
      //
      // Donc : l'étape SUIVANTE est un grand bouton, seule en vue. Le reste du
      // chemin se replie derrière un « Inny status » — un <details>, c'est-à-
      // dire du HTML, qui s'ouvre sans une ligne de JavaScript et fonctionne
      // sur le téléphone de la réserve.
      //
      // UN SEUL formulaire pour toute la ligne, et autant de boutons de
      // soumission que d'étapes : un formulaire par bouton aurait multiplié
      // par six le poids d'une page qui en porte déjà deux cents.
      $etapy = wsm_order_etapy($pdo, $o);
      $teraz = current(array_filter($etapy, fn($e) => $e['etat'] === 'teraz')) ?: null;
      $suiv  = current(array_filter($etapy, fn($e) => $e['etat'] === 'nastepny')) ?: null;
      $autres = array_values(array_filter($etapy,
                    fn($e) => !in_array($e['etat'], ['teraz', 'nastepny'], true)));
      // Un bouton d'étape, rendu une seule fois pour les deux endroits où il
      // apparaît : le grand devant, et les petits dans le repli.
      $bouton = function (array $e, string $extra = '') use ($o) { ?>
        <button class="etap <?= h($e['etat']) ?><?= $extra !== '' ? ' ' . $extra : '' ?>"
                name="status" value="<?= h($e['code']) ?>"
                <?= $e['pyt'] !== '' ? 'data-pyt="' . h($e['pyt']) . '" onclick="return confirm(this.dataset.pyt)"' : '' ?>
                title="<?= h($o['code']) ?> → <?= h($e['txt']) ?><?= $e['doc'] ? ' · wystawi dokument' : '' ?>">
          <?= h($e['txt']) ?><?= $e['doc'] ? '<span class="doc" aria-hidden="true">•</span>' : '' ?>
        </button>
      <?php }; ?>
      <td data-l="Status" class="wide">
        <?php if ($isAdmin): ?>
        <form method="post" class="etapy" role="group"
              aria-label="Zmień status zamówienia <?= h($o['code']) ?>">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <?php // ON PRÉVIENT LE CLIENT D'ICI AUSSI.
                //
                // La fiche coche « Powiadom klienta » par défaut ; la liste,
                // elle, n'a jamais rien envoyé. Tant que la liste ne portait que
                // deux boutons de secours, la différence ne se voyait pas. Elle
                // devient le chemin principal : sans cette ligne, « votre colis
                // est parti » cesserait de partir du jour au lendemain, et
                // personne ne saurait pourquoi.
                //
                // Un seul e-mail par (commande, état) — wsm_mail_for_status()
                // le garantit par sa clé d'événement, donc revenir en arrière et
                // ré-avancer ne renvoie rien. La fiche garde sa case pour les
                // cas où l'on veut justement se taire. ?>
          <input type="hidden" name="powiadom" value="1">

          <?php // Où l'on est. Pas un bouton : appuyer dessus ne ferait rien, et
                // un bouton qui ne fait rien envoie chercher une panne. ?>
          <?php if ($teraz): ?>
          <?php // Une commande annulée porte SA couleur, pleine. Marquée avec la
                // classe du bouton « Anuluj », elle ressortait en lien rouge
                // souligné : l'état d'une commande morte se lisait comme une
                // action à faire. ?>
          <span class="etap teraz<?= $o['status'] === 'anulowane' ? ' anulowana' : '' ?>"
                aria-current="step"><?= h($teraz['txt']) ?></span>
          <?php endif; ?>

          <?php // Le geste de tous les jours, en grand. ?>
          <?php if ($suiv) $bouton($suiv, 'glowny'); ?>

          <?php // Et tout le reste du chemin, replié. Fermé, il coûte une ligne. ?>
          <?php if ($autres): ?>
          <details class="etapy-wiecej">
            <summary>Inny status</summary>
            <div class="etapy-lista">
              <?php foreach ($autres as $e): ?>
                <?php if ($e['etat'] === 'niemozliwy'): ?>
                <?php // Grisé SANS explication, on cherche la panne. Le titre dit
                      // que c'est la règle, pas un bouton cassé. ?>
                <span class="etap niemozliwy"
                      title="Zamówienie anulowane — stanu już nie zmienisz"><?= h($e['txt']) ?></span>
                <?php else: $bouton($e); endif; ?>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endif; ?>
        </form>
        <?php else: ?>
        <span class="tag"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
        <?php endif; ?>
        <?php if (!empty($o['backorder'])): ?> <span class="tag no">do potwierdzenia</span><?php endif; ?>
        <?php if (($o['discount_percent'] ?? 0) > 0): ?> <span class="tag">−<?= (int) $o['discount_percent'] ?> %</span><?php endif; ?></td>
      <td data-l="Płatność"><span class="tag <?= h($payCls) ?>"><?= h($payLabel[$o['payment_status']] ?? $o['payment_status']) ?></span></td>
      <td data-l="Pozycje" class="num"><?= (int) $o['units'] ?></td>
      <td data-l="Brutto" class="num"><?= h(pln($o['total_gross'])) ?></td>
      <?php
      // LES DEUX VOYANTS, ET LE GESTE QUI LES DÉBLOQUE. Il fallait ouvrir
      // chaque fiche pour savoir si une commande partirait avec une facture ou
      // un e-paragon, si son numéro de TVA tenait toujours, et si le document
      // était arrivé au registre. On ne le faisait donc pas.
      $vy = wsm_order_voyants($pdo, $o); ?>
      <td data-l="Kontrolki" class="wide"><div class="voyants">
        <?php foreach ($vy as $k => $v): ?>
          <?php if ($v['agir'] !== '' && $isAdmin): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="tag <?= h($v['etat']) ?>" name="<?= h($v['agir']) ?>" value="1"
                    title="<?= h($v['quoi']) ?>"><?= h($v['txt']) ?></button>
          </form>
          <?php else: ?>
          <span class="tag <?= h($v['etat']) ?>" title="<?= h($v['quoi']) ?>"><?= h($v['txt']) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php console_foot();
