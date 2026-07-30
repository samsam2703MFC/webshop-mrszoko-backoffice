<?php
// ============================================================================
//  magazyn.php — le magasin.
//
//  Ce que l'écran répond, et que le simple champ « stan » de la fiche produit
//  ne répondait pas :
//    • combien de JOURS de vente couvre ce qui reste (un stock de 5 est
//      confortable ou critique selon le rythme — c'est la couverture qui
//      décide, pas la quantité) ;
//    • ce qu'on doit déjà aux clients (commandes acceptées au-delà du stock) ;
//    • d'où vient l'écart : chaque entrée, sortie et correction est datée,
//      motivée et signée.
//
//  Lecture : tout compte actif. Écriture (przyjęcie, korekta) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/stock.php';

$flash = ''; $kind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać magazyn.'; $kind = 'err';
    } else {
        $pid = (string) ($_POST['product_id'] ?? '');
        $st  = $pdo->prepare("SELECT nom FROM wsm_products WHERE id = ?");
        $st->execute([$pid]);
        $name = (string) $st->fetchColumn();

        if ($name === '') {
            $flash = 'Nie znaleziono produktu.'; $kind = 'err';
        } elseif (isset($_POST['przyjmij'])) {
            $qty = (int) ($_POST['qty'] ?? 0);
            if ($qty <= 0) { $flash = 'Ilość musi być dodatnia.'; $kind = 'err'; }
            else {
                $after = wsm_stock_apply($pdo, $pid, $qty, 'przyjecie', [
                    'supplier'  => (string) ($_POST['supplier'] ?? ''),
                    'unit_cost' => (int) round(((float) str_replace(',', '.', (string) ($_POST['unit_cost'] ?? 0))) * 100),
                    'doc'       => (string) ($_POST['doc'] ?? ''),
                    'note'      => (string) ($_POST['note'] ?? ''),
                    'actor'     => (string) ($me['nom'] ?? ''),
                ]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Przyjęcie', $pid . ' +' . $qty, 'Sieć');
                $flash = 'Przyjęto ' . $qty . ' szt. — ' . $name . ' (stan: ' . $after . ').';
            }
        } elseif (isset($_POST['koryguj'])) {
            $delta  = (int) ($_POST['delta'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($delta === 0)      { $flash = 'Korekta zerowa nic nie zmienia.'; $kind = 'err'; }
            elseif ($reason === '') { $flash = 'Podaj powód korekty.'; $kind = 'err'; }
            else {
                $after = wsm_stock_apply($pdo, $pid, $delta, $delta > 0 ? 'zwrot' : 'korekta', [
                    'reason' => $reason,
                    'note'   => (string) ($_POST['note'] ?? ''),
                    'actor'  => (string) ($me['nom'] ?? ''),
                ]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Korekta magazynu', $pid . ' ' . sprintf('%+d', $delta), 'Sieć');
                $flash = 'Zapisano korektę ' . sprintf('%+d', $delta) . ' — ' . $name . ' (stan: ' . $after . ').';
            }
        }
    }
}

$ov    = wsm_stock_overview($pdo);
$kpis  = wsm_stock_kpis($pdo);
$from  = (string) ($_GET['from'] ?? date('Y-m-d', time() - 30 * 86400));
$to    = (string) ($_GET['to'] ?? date('Y-m-d'));
$moves = wsm_stock_moves($pdo, [
    'from' => $from, 'to' => $to,
    'kind' => (string) ($_GET['kind'] ?? ''),
    'product_id' => (string) ($_GET['product_id'] ?? ''),
    'limit' => 200,
]);

$statusLabel = ['brak' => 'Brak', 'dlug' => 'Dług wobec klientów', 'krytyczny' => 'Krytyczny',
                'niski' => 'Niski', 'ok' => 'OK'];
$statusTag   = ['brak' => 'bad', 'dlug' => 'no', 'krytyczny' => 'bad', 'niski' => 'wait', 'ok' => 'ok'];

console_head('Magazyn', $me, <<<'CSS'
  .forms { display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 900px) { .forms { grid-template-columns: 1fr 1fr; } }
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
CSS, $kpis['out'] ? $kpis['out'] . ' bez stanu' : '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Magazyn' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['products'] ?></b><span>Produkty w sklepie</span></div>
  <div class="kpi"><b><?= (int) $kpis['units'] ?></b><span>Sztuk na stanie</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['value'])) ?></b><span>Wartość w cenach sprzedaży</span></div>
  <div class="kpi<?= $kpis['out'] ? ' hot' : '' ?>"><b><?= (int) $kpis['out'] ?></b><span>Bez stanu</span></div>
  <div class="kpi<?= $kpis['low'] ? ' hot' : '' ?>"><b><?= (int) $kpis['low'] ?></b><span>Na wyczerpaniu</span></div>
  <div class="kpi<?= $kpis['owed'] ? ' hot' : '' ?>"><b><?= (int) $kpis['owed'] ?></b><span>Sztuk do wykonania</span></div>
</div>

<div class="panel">
  <h2>Stan magazynu</h2>
  <p class="why">
    „Zapas” to liczba <b>dni sprzedaży</b>, które pokrywa obecny stan — liczona z ostatnich 30 dni.
    Pięć sztuk to komfort przy jednej sprzedaży miesięcznie i alarm przy jednej dziennie;
    sama liczba sztuk tego nie mówi. „Do wykonania” to sztuki już sprzedane ponad stan:
    są obiecane klientom, nie ma ich na półce.
  </p>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Produkt</th><th class="num">Stan</th><th class="num">Sprzedaż 30 dni</th>
               <th class="num">Zapas</th><th class="num">Do wykonania</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($ov as $r): if (!$r['visible'] && $r['stock'] === 0 && $r['sold'] === 0) continue; ?>
    <tr>
      <td data-l="Produkt"><?= h($r['name']) ?>
        <?php if (!$r['visible']): ?> <span class="tag off">ukryty</span><?php endif; ?>
        <br><small class="muted"><a class="code" href="magazyn.php?product_id=<?= h(urlencode($r['id'])) ?>">historia →</a></small></td>
      <td data-l="Stan" class="num"><b><?= (int) $r['stock'] ?></b></td>
      <td data-l="Sprzedaż 30 dni" class="num"><?= (int) $r['sold'] ?></td>
      <td data-l="Zapas" class="num"><?= $r['cover_days'] === null ? '—' : (int) $r['cover_days'] . ' dni' ?></td>
      <td data-l="Do wykonania" class="num"><?= $r['owed'] ? '<b>' . (int) $r['owed'] . '</b>' : '—' ?></td>
      <td data-l="Status"><span class="tag <?= h($statusTag[$r['status']]) ?>"><?= h($statusLabel[$r['status']]) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="forms">
  <div class="panel">
    <h2>Nowe przyjęcie</h2>
    <p class="why">Dostawa od dostawcy. Cena zakupu jest zapisywana przy ruchu — to ona pozwala
      później policzyć marżę na tym, co faktycznie leży w magazynie.</p>
    <form method="post">
      <input type="hidden" name="przyjmij" value="1">
      <label class="field"><span>Produkt</span>
        <select name="product_id" required>
          <?php foreach ($ov as $r): ?>
          <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?> — stan <?= (int) $r['stock'] ?></option>
          <?php endforeach; ?>
        </select></label>
      <div class="grid2">
        <label class="field"><span>Ilość</span>
          <input type="number" name="qty" min="1" value="1" required></label>
        <label class="field"><span>Cena zakupu / szt. (zł)</span>
          <input type="text" name="unit_cost" inputmode="decimal" placeholder="0,00"></label>
        <label class="field"><span>Dostawca</span>
          <input type="text" name="supplier" placeholder="np. Callebaut Polska"></label>
        <label class="field"><span>Dokument</span>
          <input type="text" name="doc" placeholder="nr faktury zakupu"></label>
      </div>
      <label class="field"><span>Uwaga</span><input type="text" name="note"></label>
      <div class="actions"><button class="primary" type="submit">Przyjmij</button></div>
    </form>
  </div>

  <div class="panel">
    <h2>Korekta</h2>
    <p class="why">Stłuczka, degustacja, inwentaryzacja, zwrot od klienta. Powód jest wymagany:
      korekta bez powodu to strata, której nikt później nie wyjaśni.</p>
    <form method="post">
      <input type="hidden" name="koryguj" value="1">
      <label class="field"><span>Produkt</span>
        <select name="product_id" required>
          <?php foreach ($ov as $r): ?>
          <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?> — stan <?= (int) $r['stock'] ?></option>
          <?php endforeach; ?>
        </select></label>
      <div class="grid2">
        <label class="field"><span>Zmiana (± sztuk)</span>
          <input type="number" name="delta" value="-1" required>
          <span class="hint">Ujemna = ubytek, dodatnia = zwrot na stan.</span></label>
        <label class="field"><span>Powód</span>
          <input type="text" name="reason" placeholder="np. uszkodzone opakowanie" required></label>
      </div>
      <label class="field"><span>Uwaga operatora</span><input type="text" name="note"></label>
      <div class="actions"><button class="primary" type="submit">Zapisz korektę</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Ruchy magazynowe</h2>
  <form method="get" class="actions" style="margin-bottom:12px">
    <label class="field" style="margin:0"><span>Od</span><input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="field" style="margin:0"><span>Do</span><input type="date" name="to" value="<?= h($to) ?>"></label>
    <label class="field" style="margin:0"><span>Rodzaj</span>
      <select name="kind">
        <option value="">wszystkie</option>
        <?php foreach (WSM_STOCK_KINDS as $k => $lbl): ?>
        <option value="<?= h($k) ?>"<?= ($_GET['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php if (($_GET['product_id'] ?? '') !== ''): ?>
    <input type="hidden" name="product_id" value="<?= h((string) $_GET['product_id']) ?>">
    <?php endif; ?>
    <button type="submit">Pokaż</button>
    <?php if (($_GET['product_id'] ?? '') !== ''): ?>
    <a class="code" href="magazyn.php">wszystkie produkty</a>
    <?php endif; ?>
  </form>

  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Produkt</th><th>Rodzaj</th><th class="num">±</th>
               <th class="num">Stan po</th><th>Szczegóły</th><th>Kto</th></tr></thead>
    <tbody>
    <?php if (!$moves): ?><tr><td class="muted">Brak ruchów w tym zakresie.</td></tr><?php endif; ?>
    <?php foreach ($moves as $m):
      $detail = trim(implode(' · ', array_filter([
          (string) $m['reason'], (string) $m['doc'],
          (string) $m['supplier'] !== '' ? 'dostawca: ' . $m['supplier'] : '',
          (int) $m['unit_cost'] > 0 ? 'zakup ' . pln((int) $m['unit_cost']) . '/szt.' : '',
          (string) $m['note'],
      ]))); ?>
    <tr>
      <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
      <td data-l="Produkt"><?= h((string) ($m['product_name'] ?: $m['product_id'])) ?></td>
      <td data-l="Rodzaj"><span class="tag"><?= h(WSM_STOCK_KINDS[$m['kind']] ?? $m['kind']) ?></span></td>
      <td data-l="±" class="num"><b><?= sprintf('%+d', (int) $m['delta']) ?></b></td>
      <td data-l="Stan po" class="num"><?= (int) $m['stock_after'] ?></td>
      <td data-l="Szczegóły" class="wide"><?= $detail !== '' ? h($detail) : '<span class="muted">—</span>' ?></td>
      <td data-l="Kto"><?= h((string) $m['actor']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php console_foot();
