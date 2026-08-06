<?php
// ============================================================================
//  pulpit.php — le tableau de bord DU WEBSHOP.
//
//  La console héritée affiche un « Pulpit sieci » : chiffre d'affaires réseau,
//  boutiques de Bruxelles, adoption de la whitelist. C'est le tableau de bord
//  d'une franchise, pas d'une boutique en ligne — il vient de la démonstration
//  d'origine et ne dit rien de Mister Szoko.
//
//  Cet écran-ci ne montre que ce qui existe vraiment : les commandes, l'argent
//  encaissé, ce qui attend une action humaine, et l'état des intégrations.
//  Tout est lu en base ; aucune valeur n'est écrite en dur.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/mail.php';
require_once $API . '/tpay.php';
require_once $API . '/inpost.php';

$kpis   = wsm_shop_kpis($pdo);
$mail   = wsm_mail_kpis($pdo);
$orders = wsm_orders_list($pdo, 8);

// Ce qui attend quelqu'un DU CÔTÉ DES CLIENTS. Un tableau de bord qui ne
// montre que les commandes du jour laisse partir les habitués en silence :
// personne ne remarque une absence, on ne remarque qu'une présence.
require_once $API . '/crm.php';
$alertes = wsm_crm_alerts($pdo, 5);

// Ce qui attend quelqu'un : commandes hors stock non encore traitées, et
// paiements qui n'arrivent pas.
$toConfirm = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE backorder = 1 AND status IN ('nowe','oplacone')")->fetchColumn();

$lowStock = $pdo->query("SELECT id, nom, stock FROM wsm_products
                          WHERE shop_visible = 1 AND stock <= 3 ORDER BY stock, nom LIMIT 8")->fetchAll();
$noPhoto  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1 AND (image_url IS NULL OR image_url = '')")->fetchColumn();
$visible  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1")->fetchColumn();

$missing = [];
if (!wsm_tpay_enabled())   $missing[] = 'tpay (płatności online)';
if (!wsm_inpost_enabled()) $missing[] = 'InPost ShipX (etykiety)';
if (!wsm_mail_enabled())   $missing[] = 'poczta (wiadomości do klientów)';

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];

console_head('Pulpit', $me, <<<'CSS'
  .lead { font-size: 14px; line-height: 1.6; color: var(--text-muted); max-width: 80ch; margin: 0 0 20px; }
  .kpi.hot b { color: var(--danger); }
  .quick { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
  .quick a {
    display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
    border-radius: 999px; text-decoration: none; font-size: 14px; font-weight: 600;
    background: var(--surface-card); border: 1px solid var(--border-subtle); color: var(--text-strong);
  }
CSS);
?>

<?php if ($alertes): ?>
<div class="panel">
  <h2>Klienci — co czeka na człowieka <span class="code"><?= count($alertes) ?></span></h2>
  <p class="lead" style="margin-bottom:12px">
    Nikt nie zauważa nieobecności — zauważa się tylko obecność. Dlatego stały klient,
    który przestał kupować, znika po cichu, a jest droższy od nowego.
  </p>
  <table class="rwd">
    <thead><tr><th>Klient</th><th>Co się dzieje</th><th>Co zrobić</th></tr></thead>
    <tbody>
    <?php foreach ($alertes as $a): ?>
      <tr>
        <?php // Le lien ne s'affiche que si l'écran s'ouvre : sinon on
              // envoie contre une porte fermée quelqu'un qui lisait juste
              // son tableau de bord. ?>
        <td data-l="Klient"><?= console_lien($me, (string) $a['href'], $a['nom']) ?: '<b>' . h($a['nom']) . '</b>' ?></td>
        <td data-l="Co się dzieje"><?= h($a['texte']) ?></td>
        <td data-l="Co zrobić" style="color:var(--text-muted)"><?= h($a['geste']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php $lienAnalyse = console_lien($me, 'klienci.php?widok=analiza', 'Cała analiza klientów →', 'code');
        if ($lienAnalyse !== ''): ?>
  <p style="margin-top:10px"><?= $lienAnalyse ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($missing): ?>
<p class="warnbox">
  Sklep działa, ale te integracje czekają na dane:
  <b><?= h(implode(' · ', $missing)) ?></b>.
  <?php $lienUst = console_lien($me, 'ustawienia.php', 'Ustawieniach'); ?>
  <?= $lienUst !== '' ? 'Uzupełnij je w ' . $lienUst . ' — pola są wypełnione „xxxx”.'
                      : 'Uzupełnia je administrator w Ustawieniach — pola są wypełnione „xxxx”.' ?>
</p>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
  <div class="kpi"><b><?= (int) $kpis['orders_paid'] ?></b><span>Opłacone</span></div>
  <div class="kpi<?= $kpis['orders_pending'] ? ' hot' : '' ?>"><b><?= (int) $kpis['orders_pending'] ?></b><span>Czeka na płatność</span></div>
  <div class="kpi<?= $toConfirm ? ' hot' : '' ?>"><b><?= $toConfirm ?></b><span>Do potwierdzenia</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
  <div class="kpi<?= $mail['failed'] ? ' hot' : '' ?>"><b><?= (int) $mail['queued'] + (int) $mail['failed'] ?></b><span>Poczta do wysłania</span></div>
  <div class="kpi"><b><?= $visible ?></b><span>Produkty w sklepie</span></div>
</div>

<?php // LES RACCOURCIS SUIVENT LE PROFIL. Ils étaient écrits en dur : un
      // compte « Sprzedaż » y trouvait « Poczta », « Produkty » et
      // « Ustawienia », et recevait 403 sur les trois. Le rail, lui,
      // filtrait déjà — deux endroits décidaient du même accès. ?>
<p class="quick">
  <?= console_lien($me, 'zamowienia.php', 'Zamówienia →') ?>
  <?= console_lien($me, 'poczta.php', 'Poczta →') ?>
  <?= console_lien($me, 'produkty.php', 'Produkty →') ?>
  <a href="../shop/" target="_blank" rel="noopener">Zobacz sklep ↗</a>
</p>

<div class="cols" style="margin-top:22px">
  <div class="panel">
    <h2>Ostatnie zamówienia</h2>
    <?php if (!$orders): ?>
    <p class="muted small">Jeszcze żadnego zamówienia. Pierwsze pojawi się tutaj w chwili złożenia.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Klient</th><th>Status</th><th class="num">Brutto</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td data-l="Numer"><?= console_lien($me, 'zamowienia.php?id=' . (int) $o['id'], (string) $o['code'], 'code')
                                 ?: '<span class="code">' . h((string) $o['code']) . '</span>' ?></td>
        <td data-l="Klient"><?= h($o['client']) ?></td>
        <td data-l="Status"><span class="tag"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
          <?php if (!empty($o['backorder'])): ?> <span class="tag no">do potwierdzenia</span><?php endif; ?></td>
        <td data-l="Brutto" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Magazyn i witryna</h2>
    <?php if ($noPhoto): ?>
    <p class="small"><b><?= $noPhoto ?></b> produkt(ów) w sprzedaży <b>bez zdjęcia</b> — klient widzi tylko kolorowy kafelek.
      <?= console_lien($me, 'produkty.php', 'Dodaj zdjęcia →', 'code') ?></p>
    <?php endif; ?>
    <?php if (!$lowStock): ?>
    <p class="muted small">Żaden produkt nie schodzi poniżej 3 sztuk.</p>
    <?php else: ?>
    <p class="muted small">Produkty na wyczerpaniu. Zamówienie ponad stan nadal przechodzi —
      klient dostaje wiadomość „skontaktujemy się mailowo”.</p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Produkt</th><th class="num">Stan</th></tr></thead>
      <tbody>
      <?php foreach ($lowStock as $p): ?>
      <tr>
        <td data-l="Produkt"><?= h((string) $p['nom']) ?></td>
        <td data-l="Stan" class="num"><span class="tag <?= (int) $p['stock'] <= 0 ? 'bad' : 'wait' ?>"><?= (int) $p['stock'] ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php console_foot();
