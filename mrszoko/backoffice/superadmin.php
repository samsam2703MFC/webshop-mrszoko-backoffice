<?php
// ============================================================================
//  superadmin.php — le décompte du propriétaire de la plateforme.
//
//  Cet écran ne sert pas la boutique : il sert celui qui la loue. Il répond à
//  une seule question — combien la boutique doit-elle ce mois-ci, et pour
//  quelle raison exactement.
//
//  IL N'EST PAS PROTÉGÉ PAR UN RÔLE. Un rôle s'attribue depuis la console, et
//  la console appartient à la boutique : le locataire pourrait alors ouvrir la
//  page qui chiffre son loyer. L'autorisation vient du fichier de
//  configuration du serveur (voir platform.php, règle 1). Sans liste
//  configurée, la page n'existe pas — 404, pas 403 : un 403 confirmerait à un
//  curieux qu'il y a quelque chose derrière.
//
//  Tout montant affiché porte son calcul à côté. C'est la même exigence que
//  la valorisation dans l'Audyt : une somme réclamée sans son détail se
//  discute mal, et se conteste encore plus mal.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/platform.php';

// La porte. Rien au-delà de cette ligne ne s'exécute pour quelqu'un d'autre.
if (!wsm_is_superadmin($me)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>404</title>'
       . '<p style="font:16px system-ui;padding:2rem">Nie znaleziono strony.</p>');
}

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    $actor = (string) ($me['nom'] ?? '');

    if (isset($_POST['wystaw'])) {
        [$p, $err] = wsm_platform_issue($pdo, (string) $_POST['wystaw'], $actor);
        if ($p) {
            $flash = 'Wystawiono rozliczenie za ' . $_POST['wystaw'] . ' — '
                   . pln((int) $p['total_gross']) . ' brutto.';
            wsm_audit($pdo, $actor, 'Wystawienie rozliczenia',
                      'wsm_platform_periods ' . $_POST['wystaw'], 'Platforma');
        } else { $flash = $err; $kind = 'err'; }

    } elseif (isset($_POST['oplacone']) || isset($_POST['cofnij'])) {
        $ym  = (string) ($_POST['oplacone'] ?? $_POST['cofnij']);
        [$p, $err] = wsm_platform_mark_paid($pdo, $ym, isset($_POST['oplacone']));
        if ($p) {
            $flash = isset($_POST['oplacone'])
                ? 'Oznaczono ' . $ym . ' jako opłacone.'
                : 'Cofnięto opłacenie ' . $ym . '.';
            wsm_audit($pdo, $actor, 'Zmiana statusu rozliczenia',
                      'wsm_platform_periods ' . $ym, 'Platforma');
        } else { $flash = $err; $kind = 'err'; }

    } elseif (isset($_POST['warunki'])) {
        [$t, $errors] = wsm_platform_terms_save($pdo, $_POST, $actor);
        if ($t) {
            $flash = 'Zapisano warunki obowiązujące od ' . $t['from_ym'] . '.';
            wsm_audit($pdo, $actor, 'Zmiana warunków', 'wsm_platform_terms', 'Platforma');
        } else { $flash = 'Popraw zaznaczone pola.'; $kind = 'err'; }
    }
}

$terms   = wsm_platform_terms($pdo);
$serie   = wsm_platform_series($pdo, 12);
$tot     = wsm_platform_totals($serie);
$histo   = wsm_platform_terms_history($pdo);
$courant = $serie[0] ?? null;

/** Le pourcentage tel qu'on l'écrit en Pologne. */
function pct(float $r, int $dec = 2): string {
    return rtrim(rtrim(number_format($r * 100, $dec, ',', ' '), '0'), ',') . ' %';
}

/** Une barre pour douze mois : le décompte mensuel, du plus ancien au récent. */
function barres(array $serie): string {
    $pts = array_reverse($serie);
    $max = 0;
    foreach ($pts as $p) $max = max($max, (int) $p['total_gross']);
    if ($max <= 0) return '<p class="why">Brak rozliczeń do pokazania.</p>';

    $h = '<div class="chart"><div class="bars">';
    foreach ($pts as $p) {
        $v = (int) $p['total_gross'];
        $lbl = '<span class="lbl">' . h(substr($p['ym'], 5) . '/' . substr($p['ym'], 2, 2)) . '</span>';
        // Zéro ne dessine RIEN. Un moignon de deux pixels se lit comme « il
        // s'est passé quelque chose de très petit » alors qu'il ne s'est rien
        // passé du tout — et c'est une lecture qu'on ne rattrape pas.
        if ($v === 0) {
            $h .= '<div class="bar" title="' . h($p['ym'] . ' · brak') . '">' . $lbl . '</div>';
            continue;
        }
        $pc = max(1, (int) round($v / $max * 100));
        // La couleur dit l'état, pas la valeur : encaissé, émis, pas encore dû.
        $cls = !$p['frozen'] ? 'draft' : ($p['status'] === 'oplacone' ? 'ok' : 'wait');
        $h .= '<div class="bar" title="' . h($p['ym'] . ' · ' . pln($v)) . '">'
            . '<i class="fill ' . $cls . '" style="height:' . $pc . '%"></i>'
            . $lbl . '</div>';
    }
    $h .= '</div><div class="scale"><span>' . h(pln($max)) . '</span><span>0</span></div></div>';
    return $h;
}

console_head('Superadmin', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  /* La formule passe à la ligne au lieu de défiler. Un calcul dont on ne voit
     pas la fin ne se vérifie pas — et c'est toute la raison de l'afficher. */
  .calc { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted);
          background: var(--surface-sunken); border-radius: 8px; padding: 9px 11px;
          margin: 8px 0 0; line-height: 1.9; }
  .cards { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
  @media (min-width: 700px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (min-width: 1100px) { .cards { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  .cards .c { border: 1px solid var(--border-subtle); border-radius: 12px;
              padding: 13px 15px; min-width: 0; }
  .cards .c.hot { border-color: var(--brand); }
  .cards .c h4 { margin: 0 0 6px; font-family: var(--font-mono); font-size: 10.5px;
                 text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); }
  .cards .c b { display: block; font-family: var(--font-display); font-size: 23px;
                color: var(--text-strong); line-height: 1.15; }
  .cards .c small { display: block; margin-top: 5px; font-size: 12px; color: var(--text-muted); }
  /* Le tableau des rozliczenia a sept colonnes. Le coller à côté du
     formulaire dès 980 px coupait la colonne « Stan » et le bouton d'action :
     on voyait un décompte sans savoir s'il était payé. Les deux panneaux ne
     se partagent donc une rangée qu'à partir de 1400 px, où les sept colonnes
     tiennent vraiment. */
  .split { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1400px) { .split { grid-template-columns: minmax(0, 1.9fr) minmax(0, 1fr); } }
  .chart { position: relative; padding-right: 62px; min-width: 0; margin-top: 12px; }
  .chart .bars { display: flex; align-items: flex-end; gap: 3px; height: 150px; min-width: 0; }
  .chart .bar { display: flex; flex-direction: column; justify-content: flex-end;
                align-items: center; height: 100%; flex: 1 1 0; min-width: 0; }
  .chart .fill { display: block; width: 68%; min-height: 2px; border-radius: 3px 3px 0 0;
                 background: var(--brand); }
  .chart .fill.ok    { background: var(--success); }
  .chart .fill.wait  { background: var(--caramel-400); }
  /* Un mois pas encore facturable n'est pas un impayé : il se lit comme une
     estimation, hachuré, et non comme une somme qu'on attend. */
  .chart .fill.draft { background: repeating-linear-gradient(45deg,
                        var(--border-default) 0 4px, transparent 4px 8px); }
  .chart .lbl { font-family: var(--font-mono); font-size: 9.5px; color: var(--text-muted);
                margin-top: 5px; white-space: nowrap; min-width: 0; }
  /* Douze « 09/25 » ne tiennent pas dans un panneau étroit : ils se
     chevauchaient jusqu'à devenir une bouillie grise. Plutôt que de rogner
     les étiquettes jusqu'à l'illisible, on n'en garde qu'une sur deux —
     l'axe reste lisible et les barres gardent leur largeur. */
  @media (max-width: 1100px) {
    .chart .lbl { display: none; }
    .chart .bar:nth-child(2n+1) .lbl { display: block; }
  }
  .chart .scale { position: absolute; right: 0; top: 0; height: 150px;
                  display: flex; flex-direction: column; justify-content: space-between;
                  font-family: var(--font-mono); font-size: 10px;
                  color: var(--text-muted); text-align: right; }
  .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px;
            color: var(--text-muted); margin: 10px 0 0; }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px;
              margin-right: 5px; vertical-align: -1px; }
  .st { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; white-space: nowrap;
        border: 1px solid var(--border-default); color: var(--text-muted); }
  .st.oplacone   { color: var(--success); border-color: var(--success); }
  .st.wystawione { color: var(--warning); border-color: var(--warning); }
  .fld { display: grid; gap: 5px; margin-bottom: 12px; }
  .fld label { font-size: 12.5px; color: var(--text-muted); }
  .fld .err { font-size: 12px; color: var(--danger); }
  .fld input.bad, .fld select.bad { border-color: var(--danger); }
  .hist { font-size: 12.5px; color: var(--text-muted); margin: 14px 0 0; }
  .hist li { margin-bottom: 4px; }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Superadmin' => null]);
?>

<?php if ($flash !== '') console_flash($flash, $kind); ?>

<div class="cards">
  <div class="c hot">
    <h4>Bieżący miesiąc — na razie</h4>
    <b><?= h(pln((int) $tot['running'])) ?></b>
    <small>Miesiąc trwa: to nie jest jeszcze rachunek.</small>
  </div>
  <div class="c">
    <h4>Wystawione — 12 mies.</h4>
    <b><?= h(pln((int) $tot['due'])) ?></b>
    <small><?= h(pln((int) $tot['paid'])) ?> opłacone</small>
  </div>
  <div class="c<?= $tot['outstanding'] > 0 ? ' hot' : '' ?>">
    <h4>Do zapłaty</h4>
    <b><?= h(pln((int) $tot['outstanding'])) ?></b>
    <small>Wystawione i jeszcze nieopłacone</small>
  </div>
  <div class="c">
    <h4>Miesiące niewystawione</h4>
    <b><?= h(pln((int) $tot['pending'])) ?></b>
    <small>Zamknięte, czekają na wystawienie</small>
  </div>
</div>

<?php if ($courant): ?>
<div class="panel" style="margin-top:20px">
  <h2>Rachunek za <?= h($courant['ym']) ?> — krok po kroku</h2>
  <p class="why">
    Liczymy tylko to, co <b>naprawdę wpłynęło</b>: zamówienia opłacone i nieanulowane,
    datowane dniem zapłaty, nie dniem złożenia. Faktura wystawiona i nieopłacona nikomu
    nie przyniosła pieniędzy, a prowizja od sprzedaży, której nie było, raz się pobiera,
    a potem zwraca.
  </p>
  <p class="calc">podstawa <?= h(pln((int) $courant['base_amount'])) ?>
    × <?= h(pct((float) $courant['rate'])) ?> = prowizja <?= h(pln((int) $courant['commission_net'])) ?>
    &nbsp;+&nbsp; czynsz <?= h(pln((int) $courant['rent_net'])) ?>
    &nbsp;=&nbsp; <?= h(pln((int) $courant['total_net'])) ?> netto
    &nbsp;+&nbsp; VAT <?= h(pct((float) $courant['vat_rate'], 0)) ?> <?= h(pln((int) $courant['total_vat'])) ?>
    &nbsp;=&nbsp; <?= h(pln((int) $courant['total_gross'])) ?> brutto</p>

  <table class="rwd" style="margin-top:14px">
    <thead><tr><th>Pozycja</th><th class="num">Kwota</th><th>Skąd</th></tr></thead>
    <tbody>
      <tr>
        <td data-l="Pozycja">Obrót brutto — towar</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['goods_gross'])) ?></td>
        <td data-l="Skąd"><?= (int) $courant['orders'] ?> opłaconych zamówień</td>
      </tr>
      <tr>
        <td data-l="Pozycja">Obrót brutto — dostawa</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['shipping_gross'])) ?></td>
        <td data-l="Skąd">koszt przelotowy, pokazany osobno</td>
      </tr>
      <tr>
        <td data-l="Pozycja"><b>Podstawa prowizji</b></td>
        <td data-l="Kwota" class="num"><b><?= h(pln((int) $courant['base_amount'])) ?></b></td>
        <td data-l="Skąd"><?= h(WSM_PLAT_BASES[$courant['basis']]) ?></td>
      </tr>
      <tr>
        <td data-l="Pozycja">Prowizja <?= h(pct((float) $courant['rate'])) ?></td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['commission_net'])) ?></td>
        <td data-l="Skąd">podstawa × stawka</td>
      </tr>
      <tr>
        <td data-l="Pozycja">Czynsz</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['rent_net'])) ?></td>
        <td data-l="Skąd">stały, niezależny od sprzedaży</td>
      </tr>
      <tr>
        <td data-l="Pozycja"><b>Razem brutto</b></td>
        <td data-l="Kwota" class="num"><b><?= h(pln((int) $courant['total_gross'])) ?></b></td>
        <td data-l="Skąd">netto + VAT <?= h(pct((float) $courant['vat_rate'], 0)) ?></td>
      </tr>
    </tbody>
  </table>

  <?php if ($courant['basis'] === 'brutto' && $courant['shipping_gross'] > 0):
    $surPort = (int) round($courant['shipping_gross'] * $courant['rate']); ?>
  <p class="why" style="margin-top:14px">
    Podstawa obejmuje dostawę, więc <b><?= h(pln($surPort)) ?></b> prowizji pochodzi z kosztu,
    który tylko przez sklep przechodzi — z marży nigdy nie był. To świadomy wybór, nie
    przeoczenie: „obrót brutto” dosłownie tyle znaczy. Przełącz podstawę na
    <b>sam towar</b> poniżej, jeśli wolisz liczyć bez dostawy.
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="split" style="margin-top:20px">
  <div class="panel">
    <h2>Rozliczenia miesięczne</h2>
    <p class="why">
      Wystawić można tylko miesiąc <b>zakończony</b>: rachunek za trwający miesiąc byłby
      częściowy. Wystawione rozliczenie <b>zamraża</b> obrót, stawkę, czynsz i VAT —
      zmiana warunków w marcu nie przepisuje rachunku za luty.
    </p>
    <?= barres($serie) ?>
    <p class="legend">
      <span><i style="background:var(--success)"></i>Opłacone</span>
      <span><i style="background:var(--caramel-400)"></i>Wystawione</span>
      <span><i style="background:var(--border-default)"></i>Jeszcze niewystawione</span>
    </p>

    <table class="rwd" style="margin-top:16px">
      <thead><tr>
        <th>Miesiąc</th><th class="num">Obrót brutto</th><th class="num">Prowizja</th>
        <th class="num">Czynsz</th><th class="num">Razem brutto</th><th>Stan</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($serie as $p):
        $enCours = $p['ym'] === date('Y-m'); ?>
        <tr>
          <td data-l="Miesiąc"><b><?= h($p['ym']) ?></b>
            <?php if ($enCours): ?><br><small style="color:var(--text-muted)">trwa</small><?php endif; ?></td>
          <td data-l="Obrót brutto" class="num"><?= h(pln((int) $p['gross'])) ?></td>
          <td data-l="Prowizja" class="num"><?= h(pln((int) $p['commission_net'])) ?>
            <br><small style="color:var(--text-muted)"><?= h(pct((float) $p['rate'])) ?></small></td>
          <td data-l="Czynsz" class="num"><?= h(pln((int) $p['rent_net'])) ?></td>
          <td data-l="Razem brutto" class="num"><b><?= h(pln((int) $p['total_gross'])) ?></b></td>
          <td data-l="Stan">
            <?php if (!$p['frozen']): ?>
              <span class="st"><?= $enCours ? 'trwa' : 'niewystawione' ?></span>
            <?php else: ?>
              <span class="st <?= h($p['status']) ?>"><?= h($p['status']) ?></span>
            <?php endif; ?>
          </td>
          <td data-l="">
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <?php if (!$p['frozen'] && !$enCours && (int) $p['total_gross'] > 0): ?>
                <button class="btn sm" name="wystaw" value="<?= h($p['ym']) ?>">Wystaw</button>
              <?php elseif (!$p['frozen'] && !$enCours): ?>
                <?php /* Ni vente ni loyer : il n'y a rien à facturer. Proposer
                          « Wystaw » produirait une note à 0 zł, c'est-à-dire du
                          bruit dans une suite de documents qui doit rester lisible. */ ?>
                <span style="color:var(--text-muted);font-size:12px">—</span>
              <?php elseif ($p['frozen'] && $p['status'] === 'wystawione'): ?>
                <button class="btn sm" name="oplacone" value="<?= h($p['ym']) ?>">Opłacone</button>
              <?php elseif ($p['frozen'] && $p['status'] === 'oplacone'): ?>
                <button class="btn sm ghost" name="cofnij" value="<?= h($p['ym']) ?>">Cofnij</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h2>Warunki najmu</h2>
    <p class="why">
      Zapis nie nadpisuje poprzednich warunków — dopisuje nowe, obowiązujące od wskazanego
      miesiąca. Dzięki temu za dwa lata nadal widać, na jakich zasadach powstał każdy
      rachunek. Miesiąca już wystawionego nie da się objąć nowymi warunkami.
    </p>
    <form method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="warunki" value="1">

      <div class="fld">
        <label for="rent">Czynsz miesięczny (netto, zł)</label>
        <input id="rent" name="rent_net" inputmode="decimal"
               class="<?= isset($errors['rent_net']) ? 'bad' : '' ?>"
               value="<?= h(number_format($terms['rent_net'] / 100, 2, '.', '')) ?>">
        <?php if (isset($errors['rent_net'])): ?><span class="err"><?= h($errors['rent_net']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="rate">Prowizja (%)</label>
        <input id="rate" name="rate" inputmode="decimal"
               class="<?= isset($errors['rate']) ? 'bad' : '' ?>"
               value="<?= h(rtrim(rtrim(number_format($terms['rate'] * 100, 2, '.', ''), '0'), '.')) ?>">
        <?php if (isset($errors['rate'])): ?><span class="err"><?= h($errors['rate']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="basis">Podstawa prowizji</label>
        <select id="basis" name="basis">
          <?php foreach (WSM_PLAT_BASES as $k => $lbl): ?>
            <option value="<?= h($k) ?>"<?= $terms['basis'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld">
        <label for="vat">VAT na rozliczeniu (%)</label>
        <input id="vat" name="vat_rate" inputmode="decimal"
               class="<?= isset($errors['vat_rate']) ? 'bad' : '' ?>"
               value="<?= h(rtrim(rtrim(number_format($terms['vat_rate'] * 100, 2, '.', ''), '0'), '.')) ?>">
        <?php if (isset($errors['vat_rate'])): ?><span class="err"><?= h($errors['vat_rate']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="from">Obowiązuje od (YYYY-MM)</label>
        <input id="from" name="from_ym" placeholder="<?= h(date('Y-m')) ?>"
               class="<?= isset($errors['from_ym']) ? 'bad' : '' ?>"
               value="<?= h((string) ($_POST['from_ym'] ?? date('Y-m'))) ?>">
        <?php if (isset($errors['from_ym'])): ?><span class="err"><?= h($errors['from_ym']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="note">Notatka</label>
        <input id="note" name="note" maxlength="255" value="">
      </div>

      <button class="btn primary" type="submit">Zapisz warunki</button>
    </form>

    <?php if ($histo): ?>
    <h3 style="margin-top:20px;font-size:14px">Historia warunków</h3>
    <ul class="hist">
      <?php foreach (array_slice($histo, 0, 8) as $t): ?>
      <li><b><?= h((string) $t['from_ym']) ?></b> —
        czynsz <?= h(pln((int) $t['rent_net'])) ?>,
        prowizja <?= h(pct((float) $t['rate'])) ?>
        (<?= h(WSM_PLAT_BASES[$t['basis']] ?? (string) $t['basis']) ?>),
        VAT <?= h(pct((float) $t['vat_rate'], 0)) ?>
        <?php if (($t['note'] ?? '') !== ''): ?><br><span style="opacity:.8"><?= h((string) $t['note']) ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<?php console_foot(); ?>
