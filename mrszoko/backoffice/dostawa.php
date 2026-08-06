<?php
// ============================================================================
//  dostawa.php — UNE seule page pour décider de la livraison.
//
//  POURQUOI CET ÉCRAN EXISTE. La même décision se prenait à trois endroits :
//  les pays desservis dans « Kraje », les tarifs et le poids dans « Treści »,
//  et la marge dans « Audyt ». Or ces trois choses ne se règlent pas
//  séparément — le seuil de gratuité du port dépend du coût du colis, qui
//  dépend du transporteur, qui dépend du pays. On les regardait l'une après
//  l'autre en gardant la précédente en tête, ce qui est exactement la façon de
//  se tromper d'un facteur dix sans s'en apercevoir.
//
//  ET SURTOUT : « darmowa dostawa od 200 zł » se décidait au doigt mouillé,
//  parce que le voisin affiche 200. La question a pourtant une réponse
//  arithmétique — il ne manquait que le taux de marge, qui est mesuré depuis
//  toujours dans analytics.php. Le calcul est ici, avec sa formule écrite à
//  côté du résultat.
//
//  CE QUE CET ÉCRAN NE FAIT PAS. Il n'ouvre pas un pays à la vente : cocher un
//  pays engage la TVA, l'OSS et les mentions légales, pas seulement un colis.
//  Cette décision reste dans « Kraje », et les deux écrans se renvoient l'un à
//  l'autre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/cms.php';           // wsm_cms_grosze()
require_once $API . '/analytics.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';

/** La part de marge qu'on veut garder, retenue d'un envoi à l'autre. */
$garde = isset($_REQUEST['garde']) ? (float) str_replace(',', '.', (string) $_REQUEST['garde']) : 0.0;
$garde = max(0.0, min(90.0, $garde)) / 100;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Twoja rola nie pozwala zmieniać dostawy.'; $kind = 'err';

    } elseif (isset($_POST['metody'])) {
        // Une seule écriture pour toute la ligne : séparer « tarif » et
        // « portée » en deux formulaires obligeait à enregistrer deux fois et
        // laissait la moitié du travail en plan si l'on quittait la page.
        $up = $pdo->prepare("UPDATE wsm_shipping_methods
                                SET active = ?, kind = ?, countries = ?, price_net = ?,
                                    cost_net = ?, max_weight_g = ?, free_from = ?
                              WHERE id = ?");
        // Les codes pays sont vérifiés CONTRE LA TABLE, pas contre une forme :
        // la règle et ses raisons vivent dans wsm_ship_codes() (shop.php), pour
        // qu'une suite puisse la tenir sans passer par un formulaire.
        $rejetes = []; $fermes = [];
        $n = 0;
        foreach ((array) ($_POST['d'] ?? []) as $id => $row) {
            $v = wsm_ship_codes($pdo, (string) ($row['countries'] ?? ''));
            $codes = $v['codes'];
            foreach ($v['inconnus'] as $c) $rejetes[$c] = true;
            foreach ($v['fermes'] as $c)   $fermes[$c]  = true;
            $k = ($row['kind'] ?? '') === 'punkt' ? 'punkt' : 'adres';
            $up->execute([
                empty($row['active']) ? 0 : 1,
                $k,
                implode(',', $codes),
                wsm_cms_grosze($row['price_net'] ?? ''),
                wsm_cms_grosze($row['cost_net'] ?? ''),
                // Jamais zéro : un poids maximum à 0 refuserait TOUTES les
                // commandes, y compris celles d'un seul carré de chocolat.
                max(1, (int) ($row['max_weight_g'] ?? 25000)),
                wsm_cms_grosze($row['free_from'] ?? ''),
                (string) $id,
            ]);
            $n++;
        }
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana dostawy', $n . ' metod', 'Sieć');
        $flash = 'Zapisano ' . $n . ' metod dostawy.';
        // Ce qu'on a jeté et ce qui va coincer : on le dit MAINTENANT. Écrit
        // silencieusement, un code invalide se découvre le jour où un client
        // n'arrive pas à commander.
        if ($rejetes) {
            $flash .= ' Pominięto nieznane kody: ' . implode(', ', array_keys($rejetes)) . '.';
            $kind = 'err';
        }
        if ($fermes) {
            $flash .= ' Uwaga: ' . implode(', ', array_keys($fermes))
                    . ' — przewoźnik tam jeździ, ale kraj nie jest otwarty na sprzedaż (Kraje).';
        }

    } elseif (isset($_POST['ustaw_prog'])) {
        // On écrit le seuil CALCULÉ, pas un nombre tapé : c'est tout l'objet
        // de l'écran. Le montant voyage dans le formulaire parce qu'il est
        // affiché juste à côté — le recalculer ici pourrait donner autre chose
        // que ce qui a été lu, et on aurait cliqué sur un chiffre pour en
        // enregistrer un autre.
        $id  = (string) $_POST['ustaw_prog'];
        $val = (int) ($_POST['prog_brut'] ?? 0);
        if ($val > 0) {
            $pdo->prepare("UPDATE wsm_shipping_methods SET free_from = ? WHERE id = ?")->execute([$val, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Próg darmowej dostawy',
                      $id . ' → ' . $val . ' gr', 'Sieć');
            $flash = 'Ustawiono próg dla ' . $id . ' na ' . pln($val) . ' brutto.';
        } else { $flash = 'Nie ma czego ustawić — próg nie został policzony.'; $kind = 'err'; }
    }
}

$methods = $pdo->query("SELECT * FROM wsm_shipping_methods ORDER BY sort_order, id")->fetchAll() ?: [];
$serie   = wsm_margin_series($pdo, 12);
$marge   = wsm_marge_taux($pdo, $serie);
$vat     = wsm_vat_moyen($pdo);
$actifs  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_countries WHERE active = 1")->fetchColumn();

/** Le libellé public d'une méthode, ou son identifiant à défaut. */
$etiquette = function (string $id) use ($pdo): string {
    static $S = null;
    if ($S === null) $S = wsm_shop_strings($pdo, 'pl');
    return (string) ($S['ship.' . $id . '.label'] ?? $id);
};
$zl2 = fn($g) => number_format(((int) $g) / 100, 2, '.', '');
/**
 * Un pourcentage lisible. ON NE RONGE LES ZÉROS QU'APRÈS LA VIRGULE.
 *
 * Sans cette garde, « 100 » perd son zéro final et devient « 1 » : la formule
 * affichait « marża × 1 % » là où elle voulait dire 100 %, et un taux de TVA
 * de 20 % se serait lu « 2 % ». La chaîne est juste dans les deux cas où on
 * l'a regardée (23 % et 5 %, qui n'ont pas de zéro final) — c'est exactement
 * ce qui fait passer ce genre de faute.
 */
$pct = function (float $r, int $d = 1): string {
    $s = number_format($r * 100, $d, ',', "\u{202F}");
    if (str_contains($s, ',')) $s = rtrim(rtrim($s, '0'), ',');
    return $s . "\u{202F}%";
};

console_head('Dostawa', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .calc { font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted);
          background: var(--surface-sunken); border-radius: 8px; padding: 10px 12px;
          margin: 10px 0 0; line-height: 1.9; }
  .num input { width: 100%; box-sizing: border-box; text-align: right; }
  td.num { white-space: nowrap; }
  .src { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; white-space: nowrap;
         border: 1px solid var(--border-default); color: var(--text-muted); }
  .src.mesure { color: var(--success); border-color: var(--success); }
  .src.devine { color: var(--warning); border-color: var(--warning); }
  .prog b { font-family: var(--font-display); font-size: 19px; color: var(--text-strong); }
  .prog small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 3px; }
  .impossible { color: var(--warning); font-size: 12.5px; }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Dostawa' => null]);
console_flash($flash, $kind);
?>

<div class="kpis">
  <div class="kpi"><b><?= count(array_filter($methods, fn($m) => (int) $m['active'] === 1)) ?></b><span>Czynne metody</span></div>
  <div class="kpi"><b><?= $actifs ?></b><span>Kraje otwarte na sprzedaż</span></div>
  <div class="kpi<?= $marge['source'] === 'sprzedaz' ? '' : ' hot' ?>">
    <b><?= $marge['taux'] > 0 ? h($pct($marge['taux'])) : '—' ?></b><span>Marża</span></div>
  <div class="kpi"><b><?= h($pct($vat, 0)) ?></b><span>Średni VAT</span></div>
</div>

<div class="panel">
  <h2>Metody dostawy</h2>
  <p class="why">
    Wszystko, co decyduje o dostawie, w jednym wierszu. <b>Kraje</b> to kody po przecinku
    (<code>PL, DE, CZ</code>) — puste albo <code>*</code> znaczy „wszędzie”. Kraj otwarty na
    sprzedaż, ale bez przewoźnika, <b>nie pozwoli złożyć zamówienia</b>, i kasa powie to wprost.
    <br>
    <b>Koszt u przewoźnika</b> to Wasz rachunek za paczkę — klient go nie widzi. Bez niego
    nie da się policzyć progu darmowej dostawy niżej, ani zobaczyć w
    <a href="audyt.php">Audycie</a>, jaką część dostawy pokrywa klient.
    <br>
    O tym, <b>gdzie w ogóle sprzedajecie</b>, decyduje ekran <a href="kraje.php">Kraje</a> —
    to nie jest ta sama decyzja: otwarcie kraju pociąga VAT i OSS, nie tylko paczkę.
  </p>

  <form method="post">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="metody" value="1">
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr>
        <th>Metoda</th><th>Czynna</th><th>Odbiór</th><th>Kraje</th>
        <th class="num">Cena netto (zł)</th><th class="num">Koszt u przewoźnika (zł)</th>
        <th class="num">Maks. waga (g)</th><th class="num">Gratis od (zł brutto)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($methods as $m): $id = (string) $m['id']; ?>
        <tr>
          <td data-l="Metoda"><b><?= h($etiquette($id)) ?></b><br>
            <small style="color:var(--text-muted)"><?= h($id) ?> · <?= h((string) $m['carrier']) ?></small></td>
          <td data-l="Czynna">
            <label class="check"><input type="checkbox" name="d[<?= h($id) ?>][active]" value="1"
              <?= (int) $m['active'] === 1 ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>
              aria-label="Metoda <?= h($etiquette($id)) ?> czynna"><span></span></label></td>
          <td data-l="Odbiór">
            <?php // Point ou adresse : c'est CE champ qui décide si la caisse
                  // demande un code de paczkomat ou une rue. Se tromper ici
                  // réclame une rue pour un casier, ou l'inverse. ?>
            <select name="d[<?= h($id) ?>][kind]"<?= $isAdmin ? '' : ' disabled' ?>
                    aria-label="Rodzaj odbioru dla <?= h($etiquette($id)) ?>">
              <option value="adres"<?= wsm_ship_kind_row($m) === 'adres' ? ' selected' : '' ?>>Pod adres</option>
              <option value="punkt"<?= wsm_ship_kind_row($m) === 'punkt' ? ' selected' : '' ?>>Do punktu</option>
            </select></td>
          <td data-l="Kraje"><input name="d[<?= h($id) ?>][countries]" placeholder="PL"
              value="<?= h((string) ($m['countries'] ?? '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>
              aria-label="Kraje obsługiwane przez <?= h($etiquette($id)) ?>"></td>
          <td data-l="Cena netto (zł)" class="num"><input inputmode="decimal"
              name="d[<?= h($id) ?>][price_net]" value="<?= h($zl2($m['price_net'])) ?>"
              <?= $isAdmin ? '' : ' disabled' ?> aria-label="Cena netto dla <?= h($etiquette($id)) ?>"></td>
          <td data-l="Koszt u przewoźnika (zł)" class="num"><input inputmode="decimal"
              name="d[<?= h($id) ?>][cost_net]" value="<?= h($zl2($m['cost_net'] ?? 0)) ?>"
              <?= $isAdmin ? '' : ' disabled' ?> aria-label="Koszt u przewoźnika dla <?= h($etiquette($id)) ?>"></td>
          <td data-l="Maks. waga (g)" class="num"><input inputmode="numeric"
              name="d[<?= h($id) ?>][max_weight_g]" value="<?= (int) $m['max_weight_g'] ?>"
              <?= $isAdmin ? '' : ' disabled' ?> aria-label="Maksymalna waga dla <?= h($etiquette($id)) ?>"></td>
          <td data-l="Gratis od (zł brutto)" class="num"><input inputmode="decimal"
              name="d[<?= h($id) ?>][free_from]" value="<?= h($zl2($m['free_from'])) ?>"
              <?= $isAdmin ? '' : ' disabled' ?> aria-label="Próg darmowej dostawy dla <?= h($etiquette($id)) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="actions">
      <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz metody</button>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Od jakiej kwoty stać nas na darmową dostawę</h2>

  <?php
  // ==========================================================================
  //  LA PROVENANCE DU TAUX AVANT LE TAUX.
  //
  //  Un seuil calculé sur une marge devinée est un seuil deviné. On dit donc
  //  d'abord d'où vient le nombre — vendu, catalogue, ou rien — et on refuse
  //  de calculer quand il n'y a rien, plutôt que de combler avec 15 %.
  // ==========================================================================
  ?>
  <p class="why">
    <?php if ($marge['source'] === 'sprzedaz'): ?>
      <span class="src mesure">zmierzona</span>
      Marża <b><?= h($pct($marge['taux'])) ?></b> policzona z <b>opłaconych</b> zamówień z ostatnich
      12 miesięcy — tylko z linii, dla których znany jest koszt zakupu
      (<?= h($pct($marge['couverture'])) ?> obrotu).
    <?php elseif ($marge['source'] === 'katalog'): ?>
      <span class="src devine">z cennika</span>
      Za mało sprzedaży, żeby zmierzyć marżę, więc liczymy ją z <b>katalogu</b>:
      <b><?= h($pct($marge['taux'])) ?></b> na <?= (int) $marge['produits'] ?> produktach, które mają
      wpisany koszt zakupu. To marża <b>na papierze</b> — nie mówi, co się naprawdę sprzedaje.
    <?php else: ?>
      <span class="src devine">brak danych</span>
      <b>Nie da się policzyć progu.</b> Żaden produkt nie ma wpisanego kosztu zakupu, a sprzedaży
      jest za mało, żeby marżę zmierzyć. Uzupełnij <b>koszt zakupu</b> w
      <a href="produkty.php">Produktach</a> — bez niego każdy próg tutaj byłby zgadywaniem
      podanym jako wynik.
    <?php endif; ?>
  </p>

  <?php if ($marge['taux'] > 0): ?>
  <form method="get" class="actions" style="margin:0 0 14px">
    <label class="field" style="margin:0;max-width:320px">
      <span>Ile marży zostawiamy sobie (%)</span>
      <input name="garde" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($garde * 100, 1, '.', ''), '0'), '.')) ?>">
      <span class="hint">0 % znaczy: zamówienie przy progu <b>wychodzi na zero</b> — cała marża
        idzie na paczkę. Wpisz 30, jeśli chcesz, żeby zostawało 30 % marży.</span>
    </label>
    <button type="submit">Przelicz</button>
  </form>

  <p class="calc">
    próg netto = koszt paczki ÷ (marża × (1 − zatrzymana część))
    &nbsp;=&nbsp; koszt ÷ (<?= h($pct($marge['taux'])) ?> × <?= h($pct(1 - $garde, 0)) ?>)
    <br>
    próg brutto = próg netto × (1 + VAT <?= h($pct($vat, 0)) ?>)
    &nbsp;— bo kasa porównuje próg z kwotą <b>brutto</b> koszyka
  </p>

  <div class="tablewrap" style="margin-top:14px">
  <table class="rwd">
    <thead><tr>
      <th>Metoda</th><th class="num">Koszt paczki</th><th class="num">Próg netto</th>
      <th class="num">Próg brutto</th><th class="num">Zostaje przy progu</th>
      <th class="num">Dziś ustawione</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($methods as $m):
      $id   = (string) $m['id'];
      $cout = (int) ($m['cost_net'] ?? 0);
      $s    = wsm_franco_seuil($cout, (float) $marge['taux'], $garde, $vat);
      $reste = $s['possible'] ? wsm_franco_reste($s['net'], (float) $marge['taux'], $cout) : 0;
      $actuel = (int) $m['free_from']; ?>
      <tr>
        <td data-l="Metoda"><b><?= h($etiquette($id)) ?></b></td>
        <td data-l="Koszt paczki" class="num"><?= $cout > 0 ? h(pln($cout)) : '—' ?></td>
        <?php if (!$s['possible']): ?>
          <td data-l="Próg" class="num" colspan="4"><span class="impossible"><?= h($s['raison']) ?></span></td>
          <td></td>
        <?php else: ?>
          <td data-l="Próg netto" class="num"><?= h(pln($s['net'])) ?></td>
          <td data-l="Próg brutto" class="num prog"><b><?= h(pln($s['brut'])) ?></b>
            <small>tyle musi kosztować towar</small></td>
          <?php // Le contrôle qui empêche de lire le seuil comme un gain :
                // à part gardée nulle, ce qui reste vaut exactement zéro. ?>
          <td data-l="Zostaje przy progu" class="num"><?= h(pln($reste)) ?></td>
          <td data-l="Dziś ustawione" class="num">
            <?= $actuel > 0 ? h(pln($actuel)) : '<span style="color:var(--text-muted)">brak</span>' ?>
            <?php if ($actuel > 0 && $actuel < $s['brut']): ?>
              <br><small class="impossible">niżej niż próg — dopłacacie</small>
            <?php endif; ?>
          </td>
          <td data-l="">
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="prog_brut" value="<?= (int) $s['brut'] ?>">
              <button class="btn sm" name="ustaw_prog" value="<?= h($id) ?>"
                      <?= $isAdmin ? '' : ' disabled' ?>>Ustaw</button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p class="why" style="margin-top:14px">
    <b>Czego ten rachunek nie wie.</b> Liczy średnią — jedno zamówienie za 300 zł na produktach
    o niskiej marży nadal może dokładać do paczki. I zakłada <b>jedną paczkę na zamówienie</b>:
    przy dwóch przesyłkach próg jest dwa razy wyższy. To liczba do rozmowy, nie wyrocznia.
  </p>
  <?php endif; ?>
</div>

<?php console_foot(); ?>
