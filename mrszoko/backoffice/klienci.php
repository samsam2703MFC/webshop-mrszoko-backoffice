<?php
// ============================================================================
//  klienci.php — les gens qui achètent, enfin visibles.
//
//  Jusqu'ici la console montrait six « kontrahenci » saisis à la main pour la
//  TVA intracommunautaire, et pas une seule des personnes qui ont réellement
//  passé commande. Leurs achats existaient, elles non.
//
//  Deux écrans en un :
//   • LA LISTE répond à « qui sont mes clients » : chiffre d'affaires,
//     nombre d'achats, dernière fois, et des badges qui se calculent au
//     moment de l'affichage — un « VIP » rangé en base reste VIP trois ans
//     après sa dernière commande.
//   • LA FICHE répond à « que s'est-il passé avec celui-là » : ses commandes,
//     ses factures, son courrier, ce qu'il achète, et les notes qu'on a
//     prises. Tout ce qu'il faut avant de décrocher le téléphone.
//
//  Lecture : tout compte actif. Écriture (notes) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/crm.php';
require_once $API . '/i18n.php';

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
        $flash = 'Tylko rola Centrala może pisać notatki.'; $kind = 'err';
    } elseif (isset($_POST['notatka'])) {
        [$id, $err] = wsm_crm_note_add($pdo, (string) ($_POST['email'] ?? ''),
                                       (string) $_POST['notatka'], (string) ($me['nom'] ?? ''));
        if ($id) {
            $flash = 'Zapisano notatkę.';
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Notatka o kliencie',
                      (string) ($_POST['email'] ?? ''), 'Sieć');
        } else { $flash = $err; $kind = 'err'; }
    } elseif (isset($_POST['usun_notatke'])) {
        wsm_crm_note_delete($pdo, (int) $_POST['usun_notatke']);
        $flash = 'Usunięto notatkę.';
    }
}

$liste = wsm_crm_list($pdo);
$seuil = wsm_crm_seuil_vip($liste);
$tot   = wsm_crm_totaux($liste);

$q    = trim((string) ($_GET['q'] ?? ''));
$seg  = (string) ($_GET['seg'] ?? '');
$mail = strtolower(trim((string) ($_GET['email'] ?? '')));
$fiche = $mail !== '' ? wsm_crm_client($pdo, $mail) : null;

$vus = wsm_crm_filtre($liste, $q, $seg, $seuil);

/** Les segments proposés, avec leur compte réel — un filtre vide se voit. */
$segments = ['staly' => 'Stali', 'vip' => 'Wysoki obrót', 'spiacy' => 'Śpiący',
             'b2b' => 'Firmy', 'nieoplacone' => 'Z nieopłaconymi',
             'pierwszy' => 'Po pierwszym zakupie', 'nowy' => 'Bez zakupu'];
$compte = array_fill_keys(array_keys($segments), 0);
foreach ($liste as $c) foreach (wsm_crm_badges($c, $seuil) as $b => $_) {
    if (isset($compte[$b])) $compte[$b]++;
}

/** Depuis combien de jours, en clair. */
function depuis(string $d): string {
    if ($d === '') return '—';
    $j = (int) floor((time() - strtotime($d)) / 86400);
    if ($j <= 0) return 'dziś';
    if ($j === 1) return 'wczoraj';
    if ($j < 31) return $j . ' dni temu';
    $m = (int) round($j / 30.4);
    return $m . ($m === 1 ? ' miesiąc temu' : ($m < 5 ? ' miesiące temu' : ' miesięcy temu'));
}

console_head('Klienci', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .segs { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
  .segs a { font-size: 12.5px; padding: 5px 12px; border-radius: 999px; text-decoration: none;
            border: 1px solid var(--border-default); color: var(--text-muted); }
  .segs a.on { border-color: var(--brand); color: var(--brand); font-weight: 600; }
  .segs a b { font-family: var(--font-mono); font-size: 11px; margin-left: 5px; opacity: .75; }
  /* Un badge dit un fait, pas un jugement : la couleur ne sert qu'à séparer
     ce qui appelle une action (impayé, silence) de ce qui rassure. */
  .bdg { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
         white-space: nowrap; border: 1px solid var(--border-default); color: var(--text-muted);
         margin: 0 4px 4px 0; }
  .bdg.vip         { color: var(--success);  border-color: var(--success); }
  .bdg.staly       { color: var(--brand);    border-color: var(--brand); }
  .bdg.b2b         { color: var(--info);     border-color: var(--info); }
  .bdg.spiacy      { color: var(--warning);  border-color: var(--warning); }
  .bdg.nieoplacone { color: var(--danger);   border-color: var(--danger); }
  .fiche { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1100px) { .fiche { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); } }
  .idc { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
  .idc .av { width: 62px; height: 62px; border-radius: 50%; flex: 0 0 auto;
             background: var(--surface-sunken); border: 1px solid var(--border-subtle);
             display: grid; place-items: center; font-family: var(--font-display);
             font-size: 22px; color: var(--text-muted); overflow: hidden; }
  .idc .av img { width: 100%; height: 100%; object-fit: cover; }
  .idc h2 { margin: 0 0 2px; }
  .idc .em { font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted); }
  .mini { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; margin: 14px 0; }
  @media (min-width: 720px) { .mini { grid-template-columns: repeat(4, minmax(0,1fr)); } }
  .mini .m { border: 1px solid var(--border-subtle); border-radius: 10px; padding: 10px 12px; }
  .mini .m b { display: block; font-family: var(--font-display); font-size: 19px; color: var(--text-strong); }
  .mini .m span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }
  .notes li { border-left: 2px solid var(--border-default); padding: 4px 0 8px 12px;
              margin-bottom: 10px; list-style: none; }
  .notes .meta { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); }
  .notes { padding: 0; margin: 12px 0 0; }
CSS);
console_crumbs($fiche
    ? ['Pulpit' => 'pulpit.php', 'Klienci' => 'klienci.php',
       ($fiche['name'] ?: $fiche['email']) => null]
    : ['Pulpit' => 'pulpit.php', 'Klienci' => null]);
?>

<?php if ($flash !== '') console_flash($flash, $kind); ?>

<?php if (!$fiche): ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $tot['acheteurs'] ?></b><span>Kupili choć raz</span></div>
  <div class="kpi"><b><?= (int) $tot['fideles'] ?></b><span>Stali (od <?= WSM_CRM_FIDELE_MIN ?> zakupów)</span></div>
  <div class="kpi<?= $tot['dormants'] > 0 ? ' hot' : '' ?>"><b><?= (int) $tot['dormants'] ?></b><span>Śpiący</span></div>
  <div class="kpi"><b><?= h(pln((int) $tot['panier'])) ?></b><span>Średni koszyk</span></div>
  <div class="kpi"><b><?= h(pln((int) $tot['revenue'])) ?></b><span>Obrót brutto</span></div>
</div>

<div class="panel">
  <h2>Klienci <span class="code"><?= count($vus) ?> z <?= count($liste) ?></span></h2>
  <p class="why">
    Klientem jest <b>adres e-mail</b> — to jedyne, co łączy dwa zamówienia tej samej osoby.
    Nazwisko wpisuje się za każdym razem inaczej, adres się zmienia. Liczy się tylko to,
    co <b>naprawdę zapłacone</b>: klient z trzema nieopłaconymi zamówieniami nie jest dobrym
    klientem, a pokazanie go jako takiego kazałoby podjąć decyzję handlową na pieniądzach,
    które nigdy nie przyszły.
  </p>
  <p class="why">
    Plakietki <b>liczą się przy wyświetlaniu</b>, nie leżą w bazie. „VIP" zapisany na stałe
    zostaje VIP-em trzy lata po ostatnim zakupie i nikt tego nie zauważa.
  </p>

  <?php if ($seuil > 0): ?>
  <p class="why" style="margin-bottom:8px">
    „Wysoki obrót" zaczyna się od <b><?= h(pln($seuil)) ?></b> — to próg dziesiątego percentyla.
    Klientów z tą plakietką bywa więcej niż 10 %, bo wielu ma dokładnie tę samą kwotę;
    dlatego plakietka mówi <b>fakt</b>, a nie odsetek.
  </p>
  <?php endif; ?>
  <div class="segs">
    <a href="klienci.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>"<?= $seg === '' ? ' class="on"' : '' ?>>Wszyscy <b><?= count($liste) ?></b></a>
    <?php foreach ($segments as $code => $lbl): ?>
    <a href="klienci.php?seg=<?= h($code) ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>"<?= $seg === $code ? ' class="on"' : '' ?>>
      <?= h($lbl) ?> <b><?= (int) $compte[$code] ?></b></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="filters" style="margin-bottom:12px">
    <?php if ($seg !== ''): ?><input type="hidden" name="seg" value="<?= h($seg) ?>"><?php endif; ?>
    <label class="field" style="flex:1 1 260px;margin:0"><span>Szukaj (e-mail, nazwisko, firma, NIP)</span>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="np. kowalski, example.com, 897"></label>
    <div class="actions" style="margin:0"><button type="submit">Szukaj</button>
      <?php if ($q !== '' || $seg !== ''): ?><a class="code" href="klienci.php">Wyczyść</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$vus): ?>
  <p class="why">Nikt nie pasuje do tego filtru.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr>
      <th>Klient</th><th class="num">Zakupy</th><th class="num">Obrót</th>
      <th>Ostatnio</th><th>Plakietki</th>
    </tr></thead>
    <tbody>
    <?php foreach (array_slice($vus, 0, 300) as $c): $bd = wsm_crm_badges($c, $seuil); ?>
      <tr>
        <td data-l="Klient">
          <a href="klienci.php?email=<?= rawurlencode($c['email']) ?>"><b><?= h($c['name'] ?: $c['email']) ?></b></a>
          <?php if ($c['company'] !== ''): ?><br><span style="font-size:12px;color:var(--text-muted)"><?= h($c['company']) ?></span><?php endif; ?>
          <?php if ($c['name'] !== ''): ?><br><span class="code" style="font-size:11px"><?= h($c['email']) ?></span><?php endif; ?>
        </td>
        <td data-l="Zakupy" class="num"><?= (int) $c['paid_orders'] ?>
          <?php if ((int) $c['unpaid'] > 0): ?><br><small style="color:var(--danger)">+<?= (int) $c['unpaid'] ?> nieopł.</small><?php endif; ?>
        </td>
        <td data-l="Obrót" class="num"><b><?= h(pln((int) $c['revenue'])) ?></b></td>
        <td data-l="Ostatnio"><?= h(depuis((string) $c['last_at'])) ?></td>
        <td data-l="Plakietki">
          <?php foreach ($bd as $code => $lbl): ?>
          <span class="bdg <?= h($code) ?>" title="<?= h($lbl) ?>"><?= h($lbl) ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($vus) > 300): ?>
  <p class="why" style="margin-top:10px">Pokazano 300 z <?= count($vus) ?> — zawęź wyszukiwanie.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php else: /* ---------------------------- LA FICHE ---------------------------- */ ?>

<div class="panel">
  <div class="idc">
    <div class="av">
      <?php if (($fiche['photo'] ?? '') !== ''): ?>
        <img src="<?= h((string) $fiche['photo']) ?>" alt="">
      <?php else: ?>
        <?= h(mb_strtoupper(mb_substr($fiche['name'] ?: $fiche['email'], 0, 1))) ?>
      <?php endif; ?>
    </div>
    <div style="min-width:0">
      <h2><?= h($fiche['name'] ?: $fiche['email']) ?></h2>
      <?php if ($fiche['company'] !== ''): ?><p style="margin:2px 0"><?= h($fiche['company']) ?><?= $fiche['nip'] !== '' ? ' · NIP ' . h($fiche['nip']) : '' ?></p><?php endif; ?>
      <p class="em"><a href="mailto:<?= h($fiche['email']) ?>"><?= h($fiche['email']) ?></a>
        <?php if (($fiche['lang'] ?? '') !== ''): ?> · pisze <?= h(wsm_lang_po((string) $fiche['lang'])) ?><?php endif; ?></p>
      <p style="margin:8px 0 0">
        <?php foreach ($fiche['badges'] as $code => $lbl): ?>
        <span class="bdg <?= h($code) ?>"><?= h($lbl) ?></span>
        <?php endforeach; ?>
      </p>
    </div>
  </div>

  <div class="mini">
    <div class="m"><b><?= (int) $fiche['paid_orders'] ?></b><span>Zapłacone zamówienia</span></div>
    <div class="m"><b><?= h(pln((int) $fiche['revenue'])) ?></b><span>Obrót brutto</span></div>
    <div class="m"><b><?= h(pln((int) $fiche['basket'])) ?></b><span>Średni koszyk</span></div>
    <div class="m"><b><?= h(depuis((string) $fiche['last_at'])) ?></b><span>Ostatnie zamówienie</span></div>
  </div>
  <p class="why">
    Pierwsze zamówienie: <b><?= h(substr((string) $fiche['first_at'], 0, 10) ?: '—') ?></b>.
    <?php if ((int) $fiche['unpaid'] > 0): ?>
    Ma <b style="color:var(--danger)"><?= (int) $fiche['unpaid'] ?></b> nieopłaconych zamówień —
    nie liczą się do obrotu.
    <?php endif; ?>
  </p>
</div>

<div class="fiche">
  <div class="panel">
    <h2>Zamówienia <span class="code"><?= count($fiche['orders_list']) ?></span></h2>
    <?php if (!$fiche['orders_list']): ?>
    <p class="why">Jeszcze nic nie zamówił.</p>
    <?php else: ?>
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Data</th><th>Stan</th><th class="num">Kwota</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($fiche['orders_list'], 0, 40) as $o): ?>
        <tr>
          <td data-l="Numer"><a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>"><?= h((string) $o['code']) ?></a></td>
          <td data-l="Data"><?= h(substr((string) $o['created_at'], 0, 10)) ?></td>
          <td data-l="Stan"><span class="bdg<?= $o['payment_status'] === 'oplacone' ? ' vip' : ($o['status'] === 'anulowane' ? '' : ' nieoplacone') ?>"><?= h((string) $o['status']) ?></span></td>
          <td data-l="Kwota" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($fiche['top']): ?>
    <h3 style="margin-top:20px;font-size:15px">Co kupuje</h3>
    <p class="why">
      Ilości, nie linie: dziesięć razy kilogram to nie to samo co raz dziesięć kilogramów.
      Liczone tylko z zapłaconych zamówień.
    </p>
    <table class="rwd">
      <thead><tr><th>Produkt</th><th class="num">Sztuk</th><th class="num">Wartość</th></tr></thead>
      <tbody>
      <?php foreach ($fiche['top'] as $t): ?>
        <tr>
          <td data-l="Produkt"><?= h((string) $t['name']) ?></td>
          <td data-l="Sztuk" class="num"><?= (int) $t['q'] ?></td>
          <td data-l="Wartość" class="num"><?= h(pln((int) $t['v'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($fiche['invoices']): ?>
    <h3 style="margin-top:20px;font-size:15px">Faktury</h3>
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Rodzaj</th><th>Data</th><th class="num">Kwota</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($fiche['invoices'], 0, 20) as $f): ?>
        <tr>
          <td data-l="Numer"><a class="code" href="faktury.php?id=<?= (int) $f['id'] ?>"><?= h((string) $f['number']) ?></a></td>
          <td data-l="Rodzaj"><?= h((string) $f['kind']) ?></td>
          <td data-l="Data"><?= h(substr((string) $f['issued_at'], 0, 10)) ?></td>
          <td data-l="Kwota" class="num"><?= h(pln((int) $f['total_gross'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <h2>Notatki</h2>
      <p class="why">
        Podpisane i datowane. „Trudny klient" bez autora jest plotką; z autorem
        i datą jest informacją, za którą ktoś odpowiada.
      </p>
      <?php if ($isAdmin): ?>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="email" value="<?= h($fiche['email']) ?>">
        <label class="field"><span>Nowa notatka</span>
          <textarea name="notatka" rows="4" maxlength="2000"
                    placeholder="np. woli odbiór w paczkomacie przy pracy; dzwonić po 16:00"></textarea></label>
        <div class="actions"><button class="primary" type="submit">Zapisz notatkę</button></div>
      </form>
      <?php endif; ?>
      <?php if ($fiche['notes']): ?>
      <ul class="notes">
        <?php foreach ($fiche['notes'] as $n): ?>
        <li>
          <div><?= nl2br(h((string) $n['note'])) ?></div>
          <div class="meta"><?= h(substr((string) $n['created_at'], 0, 16)) ?> · <?= h((string) $n['actor']) ?>
            <?php if ($isAdmin): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <button class="btn sm ghost" name="usun_notatke" value="<?= (int) $n['id'] ?>">Usuń</button>
            </form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="why">Brak notatek.</p>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Korespondencja</h2>
      <?php if (!$fiche['messages']): ?>
      <p class="why">Nic nie wysłano ani nie otrzymano.</p>
      <?php else: ?>
      <table class="rwd">
        <thead><tr><th>Kiedy</th><th>Temat</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($fiche['messages'], 0, 15) as $m): ?>
          <tr>
            <td data-l="Kiedy"><span class="code"><?= h(substr((string) $m['created_at'], 0, 10)) ?></span><br>
              <small style="color:var(--text-muted)"><?= $m['direction'] === 'wejscie' ? 'od klienta' : 'do klienta' ?></small></td>
            <td data-l="Temat"><a href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h((string) $m['subject']) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <p style="margin-top:12px">
        <a class="btn sm" href="poczta.php?email=<?= rawurlencode($fiche['email']) ?>">Napisz do klienta</a>
      </p>
    </div>
  </div>
</div>

<p style="margin-top:16px"><a class="code" href="klienci.php">← Wszyscy klienci</a></p>

<?php endif; ?>

<?php console_foot(); ?>
