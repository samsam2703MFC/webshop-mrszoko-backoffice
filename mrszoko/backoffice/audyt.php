<?php
// ============================================================================
//  audyt.php — ce que la boutique a fait, en chiffres et en journal.
//
//  Les graphiques sont du SVG écrit à la main. Pas de bibliothèque : une page
//  de console n'a pas besoin de trois cents kilo-octets de JavaScript pour
//  tracer douze barres, et un graphique servi depuis un CDN est un graphique
//  qui disparaît le jour où le CDN tombe.
//
//  Toutes les séries sont lues en base, jamais inventées : quand il n'y a pas
//  de données, le graphique le dit au lieu d'afficher une jolie courbe fausse.
//
//  Lecture : tout compte actif.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';

// ---------------------------------------------------------------------------
//  Séries
// ---------------------------------------------------------------------------

/** Douze mois glissants : chiffre d'affaires encaissé et nombre de commandes. */
function serie_mois(PDO $pdo, int $months = 12): array {
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[date('Y-m', $t)] = ['label' => date('m/y', $t), 'gross' => 0, 'orders' => 0];
    }
    $rows = $pdo->query("SELECT created_at, total_gross, payment_status FROM wsm_orders
                          WHERE status <> 'anulowane'")->fetchAll() ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['created_at'], 0, 7);
        if (!isset($out[$k])) continue;
        $out[$k]['orders']++;
        if ((string) $r['payment_status'] === 'oplacone') $out[$k]['gross'] += (int) $r['total_gross'];
    }
    return array_values($out);
}

/** Trente jours glissants : commandes par jour. */
function serie_jours(PDO $pdo, int $days = 30): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $out[$d] = ['label' => date('d/m', strtotime($d)), 'n' => 0];
    }
    $rows = $pdo->query("SELECT created_at FROM wsm_orders WHERE status <> 'anulowane'")->fetchAll() ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['created_at'], 0, 10);
        if (isset($out[$k])) $out[$k]['n']++;
    }
    return array_values($out);
}

/** Clients cumulés : chaque adresse compte une fois, au mois de sa première commande. */
function serie_clients(PDO $pdo, int $months = 12): array {
    $first = [];
    $rows = $pdo->query("SELECT email, MIN(created_at) AS d FROM wsm_orders
                          WHERE email <> '' GROUP BY LOWER(email)")->fetchAll() ?: [];
    foreach ($rows as $r) $first[] = substr((string) $r['d'], 0, 7);

    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $k = date('Y-m', $t);
        $out[] = ['label' => date('m/y', $t),
                  'n' => count(array_filter($first, fn($m) => $m <= $k)),
                  'new' => count(array_filter($first, fn($m) => $m === $k))];
    }
    return $out;
}

function top_produits(PDO $pdo, int $limit = 6): array {
    try {
        return $pdo->query("SELECT i.name, SUM(i.qty) AS n, SUM(i.line_gross) AS v
                              FROM wsm_order_items i JOIN wsm_orders o ON o.id = i.order_id
                             WHERE o.status <> 'anulowane'
                             GROUP BY i.name ORDER BY n DESC LIMIT " . $limit)->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
//  Tracés — du SVG, rien d'autre
// ---------------------------------------------------------------------------

/** Histogramme. $vals : [['label'=>…, 'v'=>int], …]. $fmt met en forme la valeur. */
function graph_bars(array $vals, callable $fmt, string $color = 'var(--brand)'): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $fmt = fn($v) => (string) $fmt($v);
    $max = max(1, max(array_map(fn($r) => $r['v'], $vals)));
    $n = count($vals);
    $w = 100 / $n;                                     // largeur d'une colonne, en %
    $out = '<div class="chart"><div class="bars">';
    foreach ($vals as $r) {
        $h = $max > 0 ? round($r['v'] / $max * 100, 1) : 0;
        $out .= '<div class="bar" style="width:' . round($w, 3) . '%" title="' . h($r['label'] . ' — ' . $fmt($r['v'])) . '">'
              . '<span class="fill" style="height:' . $h . '%;background:' . $color . '"></span>'
              . '<span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . h($fmt($max)) . '</span><span>0</span></div></div>';
    return $out;
}

/** Courbe cumulée, en SVG : une polyligne et des points. */
function graph_line(array $vals, callable $fmt): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $fmt = fn($v) => (string) $fmt($v);
    $max = max(1, max(array_map(fn($r) => $r['v'], $vals)));
    $n = count($vals);
    $pts = [];
    foreach (array_values($vals) as $i => $r) {
        $x = $n > 1 ? round($i / ($n - 1) * 100, 2) : 50;
        $y = round(100 - ($r['v'] / $max * 100), 2);
        $pts[] = $x . ',' . $y;
    }
    $poly = implode(' ', $pts);
    $area = '0,100 ' . $poly . ' 100,100';
    $out  = '<div class="chart"><svg viewBox="0 0 100 100" preserveAspectRatio="none" class="line" aria-hidden="true">'
          . '<polygon points="' . h($area) . '" fill="var(--brand-quiet, rgba(120,70,40,.12))"></polygon>'
          . '<polyline points="' . h($poly) . '" fill="none" stroke="var(--brand)" stroke-width="1.5"'
          . ' vector-effect="non-scaling-stroke" stroke-linejoin="round"></polyline></svg>';
    $out .= '<div class="bars ticks">';
    foreach ($vals as $r) {
        $out .= '<div class="bar" style="width:' . round(100 / $n, 3) . '%" title="' . h($r['label'] . ' — ' . $fmt($r['v'])) . '">'
              . '<span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . h($fmt($max)) . '</span><span>0</span></div></div>';
    return $out;
}

$mois    = serie_mois($pdo);
$jours   = serie_jours($pdo);
$clients = serie_clients($pdo);
$tops    = top_produits($pdo);
$kpis    = wsm_shop_kpis($pdo);

$clientsTotal = (int) ($clients[count($clients) - 1]['n'] ?? 0);
$repeat = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT LOWER(email) e FROM wsm_orders
                              WHERE email <> '' GROUP BY LOWER(email) HAVING COUNT(*) > 1) t")->fetchColumn();

$audit = $pdo->query("SELECT * FROM wsm_audit ORDER BY id DESC LIMIT 150")->fetchAll() ?: [];

console_head('Audyt', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .charts { display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 900px) { .charts { grid-template-columns: 1fr 1fr; } }
  .chart { position: relative; padding-right: 54px; }
  .chart .bars { display: flex; align-items: flex-end; gap: 2px; height: 160px; }
  .chart .bar { display: flex; flex-direction: column; justify-content: flex-end;
                align-items: center; height: 100%; min-width: 0; }
  .chart .fill { display: block; width: 72%; min-height: 2px; border-radius: 3px 3px 0 0; }
  .chart .lbl { font-family: var(--font-mono); font-size: 9.5px; color: var(--text-muted);
                margin-top: 5px; white-space: nowrap; overflow: hidden; text-overflow: clip; }
  .chart .ticks { height: auto; align-items: flex-start; }
  .chart .scale { position: absolute; right: 0; top: 0; height: 160px;
                  display: flex; flex-direction: column; justify-content: space-between;
                  font-family: var(--font-mono); font-size: 10px; color: var(--text-muted); text-align: right; }
  .chart svg.line { width: 100%; height: 160px; display: block; }
  .rank { display: grid; gap: 8px; }
  .rank .row { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; font-size: 13.5px; }
  .rank .track { grid-column: 1 / -1; height: 6px; border-radius: 999px; background: var(--surface-sunken); }
  .rank .track i { display: block; height: 100%; border-radius: 999px; background: var(--brand); }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Audyt' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= $clientsTotal ?></b><span>Klienci</span></div>
  <div class="kpi"><b><?= $repeat ?></b><span>Wracający</span></div>
  <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
</div>

<div class="charts">
  <div class="panel">
    <h2>Obrót — 12 miesięcy</h2>
    <p class="why">Liczy się tylko to, co <b>zapłacone</b>. Zamówienie oczekujące na płatność
      nie jest obrotem — dopóki pieniądze nie wpłynęły, to obietnica.</p>
    <?= graph_bars(array_map(fn($m) => ['label' => $m['label'], 'v' => $m['gross']], $mois),
                   fn($v) => pln((int) $v)) ?>
  </div>

  <div class="panel">
    <h2>Zamówienia — 12 miesięcy</h2>
    <p class="why">Wszystkie zamówienia poza anulowanymi, także te czekające na płatność:
      tu mierzymy zainteresowanie, nie kasę.</p>
    <?= graph_bars(array_map(fn($m) => ['label' => $m['label'], 'v' => $m['orders']], $mois),
                   fn($v) => (int) $v . ' szt.', 'var(--caramel-400)') ?>
  </div>

  <div class="panel">
    <h2>Klienci — narastająco</h2>
    <p class="why">Każdy adres liczony raz, w miesiącu pierwszego zamówienia. Krzywa, która
      się wypłaszcza, znaczy, że nowi klienci przestali przychodzić — nawet jeśli obrót rośnie.</p>
    <?= graph_line(array_map(fn($c) => ['label' => $c['label'], 'v' => $c['n']], $clients),
                   fn($v) => (int) $v) ?>
  </div>

  <div class="panel">
    <h2>Zamówienia dzień po dniu — 30 dni</h2>
    <p class="why">Rytm bieżący. Dziura w tym wykresie to dzień, w którym nikt nie kupił.</p>
    <?= graph_bars(array_map(fn($d) => ['label' => $d['label'], 'v' => $d['n']], $jours),
                   fn($v) => (int) $v . ' szt.', 'var(--choco-500)') ?>
  </div>
</div>

<div class="panel">
  <h2>Najczęściej kupowane</h2>
  <?php if (!$tops): ?>
  <p class="muted small">Brak sprzedaży.</p>
  <?php else: $maxTop = max(array_map(fn($t) => (int) $t['n'], $tops)); ?>
  <div class="rank">
    <?php foreach ($tops as $t): ?>
    <div class="row"><span><?= h((string) $t['name']) ?></span>
      <span class="mono"><?= (int) $t['n'] ?> szt. · <?= h(pln((int) $t['v'])) ?></span>
      <span class="track"><i style="width:<?= max(2, round((int) $t['n'] / max(1, $maxTop) * 100)) ?>%"></i></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Dziennik audytu</h2>
  <p class="why">Kto, co i kiedy. Każda zmiana danych w konsoli zostawia tu ślad —
    to jedyne miejsce, gdzie widać, kto zmienił cenę albo wyłączył konto.</p>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Kto</th><th>Co</th><th>Czego dotyczy</th><th>Zakres</th></tr></thead>
    <tbody>
    <?php if (!$audit): ?><tr><td class="muted">Dziennik jest pusty.</td></tr><?php endif; ?>
    <?php foreach ($audit as $a): ?>
    <tr>
      <td data-l="Kiedy" class="num"><?= h((string) $a['ts']) ?></td>
      <td data-l="Kto"><?= h((string) $a['user']) ?></td>
      <td data-l="Co"><span class="tag"><?= h((string) $a['verb']) ?></span></td>
      <td data-l="Czego dotyczy" class="wide"><?= h((string) $a['entity']) ?></td>
      <td data-l="Zakres"><?= h((string) $a['shop']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php console_foot();
