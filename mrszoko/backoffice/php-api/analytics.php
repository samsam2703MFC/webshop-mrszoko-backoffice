<?php
// ============================================================================
//  analytics.php — la marge, le port, et ce que le mois va donner.
//
//  Un chiffre d'affaires ne dit pas si l'affaire gagne de l'argent. Trois
//  questions y répondent, et ce fichier ne calcule qu'elles :
//
//   1. MARŻA BRUTTO — ce qui reste du prix de vente HT une fois la matière
//      payée. Le coût est celui FIGÉ SUR LA LIGNE au moment de la vente
//      (wsm_order_items.unit_cost) : recalculer une marge de mars avec le prix
//      d'achat de juillet donnerait un chiffre faux et flatteur.
//   2. WYNIK PO DOSTAWIE — la même marge, diminuée de ce que la livraison a
//      coûté EN PLUS de ce que le client a payé pour elle. C'est là que part
//      l'argent d'une boutique en ligne, et c'est invisible sur un compte de
//      résultat mensuel.
//   3. POKRYCIE KOSZTU DOSTAWY — quel pourcentage du coût de transport le
//      client paie. 100 % : le port est neutre. 60 % : quatre złoty sur dix
//      sortent de la marge, à chaque colis.
//
//  DEUX HONNÊTETÉS QUI COMPTENT PLUS QUE LES CHIFFRES :
//
//   • On ne calcule une marge QUE sur les lignes dont le coût est connu. Un
//      produit sans prix de revient n'est pas compté comme une marge de 100 %
//      — il serait le seul à « rapporter » et fausserait tout. Chaque série
//      rapporte donc sa COUVERTURE : la part du chiffre d'affaires sur
//      laquelle le calcul a réellement pu se faire.
//   • Une prévision est une extrapolation, pas une promesse. Elle vaut ce que
//      vaut l'hypothèse « le reste du mois ressemblera au début », et l'écran
//      doit le dire. En début de mois, deux jours de données extrapolés sur
//      trente ne prédisent rien : la fonction renvoie sa fiabilité avec le
//      chiffre, pour que l'écran puisse se taire quand elle est faible.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Ce que le transporteur nous facture pour chaque mode, en grosze HT.
 * À défaut de coût saisi, on prend le tarif vendu : le rapport vaut alors
 * 100 % par construction, ce que l'écran signale au lieu de le maquiller.
 *
 * @return array [id => ['cost' => int, 'price' => int, 'known' => bool]]
 */
function wsm_ship_costs(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT id, price_net, cost_net FROM wsm_shipping_methods")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    foreach ($rows as $r) {
        $cost = (int) ($r['cost_net'] ?? 0);
        $out[(string) $r['id']] = [
            'cost'  => $cost > 0 ? $cost : (int) $r['price_net'],
            'price' => (int) $r['price_net'],
            'known' => $cost > 0,
        ];
    }
    return $out;
}

/**
 * Marge, port et couverture, mois par mois.
 *
 * Seules les commandes ENCAISSÉES comptent : une marge sur une facture impayée
 * est une marge qu'on n'a pas. C'est la même règle que le graphique du chiffre
 * d'affaires — deux écrans qui compteraient différemment seraient pires que
 * pas d'écran du tout.
 *
 * @return array [['label','ym','revenue','cogs','margin','ship_paid','ship_cost',
 *                 'ship_gap','result','coverage_pct','cost_known_pct','orders'], …]
 */
function wsm_margin_series(PDO $pdo, int $months = 12): array {
    $ship = wsm_ship_costs($pdo);

    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[date('Y-m', $t)] = [
            'label' => date('m/y', $t), 'ym' => date('Y-m', $t),
            'revenue' => 0, 'cogs' => 0, 'margin' => 0,
            'ship_paid' => 0, 'ship_cost' => 0, 'ship_gap' => 0, 'result' => 0,
            'coverage_pct' => 0.0, 'cost_known_pct' => 0.0, 'orders' => 0,
            'revenue_costed' => 0,
        ];
    }

    $orders = $pdo->query("SELECT id, created_at, delivery_method, shipping_net, items_net
                             FROM wsm_orders
                            WHERE status <> 'anulowane' AND payment_status = 'oplacone'")->fetchAll() ?: [];
    if (!$orders) return array_values($out);

    // Les lignes en une passe : une requête par commande sur douze mois de
    // ventes rendrait cet écran inutilisable le jour où la boutique marche.
    $lines = [];
    $hasCost = in_array('unit_cost', wsm_table_columns($pdo, 'wsm_order_items'), true);
    $sql = $hasCost
        ? "SELECT order_id, product_id, qty, line_net, unit_cost FROM wsm_order_items"
        : "SELECT order_id, product_id, qty, line_net, 0 AS unit_cost FROM wsm_order_items";
    foreach ($pdo->query($sql)->fetchAll() ?: [] as $l) {
        $lines[(int) $l['order_id']][] = $l;
    }

    // Repli pour l'historique : les commandes passées AVANT que la colonne
    // existe n'ont pas de coût figé. Plutôt que de les exclure — ce qui
    // viderait le graphique — on retombe sur le prix de revient courant du
    // produit, et la couverture affichée dit sur quelle part on a pu calculer.
    $base = [];
    foreach ($pdo->query("SELECT id, base_cost FROM wsm_products")->fetchAll() ?: [] as $p) {
        $base[(string) $p['id']] = wsm_grosze($p['base_cost'] ?? 0);
    }

    foreach ($orders as $o) {
        $k = substr((string) $o['created_at'], 0, 7);
        if (!isset($out[$k])) continue;
        $out[$k]['orders']++;

        foreach ($lines[(int) $o['id']] ?? [] as $l) {
            $net = (int) $l['line_net'];
            $out[$k]['revenue'] += $net;
            $unit = (int) $l['unit_cost'];
            if ($unit <= 0) $unit = $base[(string) $l['product_id']] ?? 0;
            if ($unit <= 0) continue;                      // coût inconnu : hors du calcul de marge
            $out[$k]['cogs'] += $unit * (int) $l['qty'];
            $out[$k]['revenue_costed'] += $net;
        }

        $m = (string) $o['delivery_method'];
        $paid = (int) $o['shipping_net'];
        $cost = $ship[$m]['cost'] ?? $paid;
        $out[$k]['ship_paid'] += $paid;
        $out[$k]['ship_cost'] += $cost;
    }

    foreach ($out as $k => $r) {
        $out[$k]['margin']   = $r['revenue_costed'] - $r['cogs'];
        $out[$k]['ship_gap'] = $r['ship_cost'] - $r['ship_paid'];
        $out[$k]['result']   = $out[$k]['margin'] - $out[$k]['ship_gap'];
        $out[$k]['coverage_pct'] = $r['ship_cost'] > 0
            ? round($r['ship_paid'] / $r['ship_cost'] * 100, 1) : 0.0;
        $out[$k]['cost_known_pct'] = $r['revenue'] > 0
            ? round($r['revenue_costed'] / $r['revenue'] * 100, 1) : 0.0;
    }
    return array_values($out);
}

/**
 * La prévision du mois en cours, et le mois précédent pour la comparer.
 *
 * La méthode est délibérément simple et explicable : on prend ce qui est déjà
 * réalisé, on le divise par les jours écoulés, on multiplie par la longueur du
 * mois. Une régression sur douze points serait plus savante et pas plus juste
 * sur une boutique jeune — et personne ne pourrait vérifier le chiffre de
 * tête, ce qui est le meilleur moyen de ne jamais s'apercevoir qu'il est faux.
 *
 * La fiabilité n'est pas décorative : à deux jours écoulés, l'extrapolation
 * multiplie par quinze le hasard du week-end. L'écran s'en sert pour se taire.
 *
 * @return array ['prev'=>…, 'curr'=>…, 'forecast'=>…, 'elapsed','days','trust','delta_pct']
 */
function wsm_forecast(PDO $pdo, array $series): array {
    $ymCurr = date('Y-m');
    $ymPrev = date('Y-m', strtotime('first day of -1 month'));

    $find = function (string $ym) use ($series) {
        foreach ($series as $r) if ($r['ym'] === $ym) return $r;
        return null;
    };
    $curr = $find($ymCurr);
    $prev = $find($ymPrev);

    $days    = (int) date('t');
    $elapsed = max(1, (int) date('j'));
    $ratio   = $days / $elapsed;

    $mk = fn(?array $r, float $k) => [
        'revenue' => (int) round((int) ($r['revenue'] ?? 0) * $k),
        'margin'  => (int) round((int) ($r['margin'] ?? 0) * $k),
        'result'  => (int) round((int) ($r['result'] ?? 0) * $k),
        'orders'  => (int) round((int) ($r['orders'] ?? 0) * $k),
    ];

    $forecast = $mk($curr, $ratio);
    $realise  = $mk($curr, 1.0);
    $passe    = $mk($prev, 1.0);

    // Un mois entier extrapolé sur moins d'un quart de sa durée n'est pas une
    // prévision, c'est un pari. On le dit plutôt que d'afficher un chiffre net.
    $part = $elapsed / $days;
    $trust = $part >= 0.5 ? 'wysoka' : ($part >= 0.25 ? 'średnia' : 'niska');
    if (($curr['orders'] ?? 0) < 3) $trust = 'niska';

    $delta = ($passe['result'] ?? 0) != 0
        ? round(($forecast['result'] - $passe['result']) / abs($passe['result']) * 100, 1)
        : 0.0;

    return [
        'prev' => $passe, 'curr' => $realise, 'forecast' => $forecast,
        'elapsed' => $elapsed, 'days' => $days, 'trust' => $trust,
        'delta_pct' => $delta,
        'label_prev' => date('m/y', strtotime($ymPrev . '-01')),
        'label_curr' => date('m/y', strtotime($ymCurr . '-01')),
    ];
}

/** Les totaux des douze mois, pour les cartouches en haut d'écran. */
function wsm_margin_totals(array $series): array {
    $sum = fn(string $k) => array_sum(array_map(fn($r) => (int) $r[$k], $series));
    $rev  = $sum('revenue');
    $cost = $sum('ship_cost');
    $rc   = $sum('revenue_costed');
    return [
        'revenue'   => $rev,
        'margin'    => $sum('margin'),
        'ship_paid' => $sum('ship_paid'),
        'ship_cost' => $cost,
        'ship_gap'  => $sum('ship_gap'),
        'result'    => $sum('result'),
        'coverage_pct'   => $cost > 0 ? round($sum('ship_paid') / $cost * 100, 1) : 0.0,
        'margin_pct'     => $rc > 0 ? round($sum('margin') / $rc * 100, 1) : 0.0,
        'cost_known_pct' => $rev > 0 ? round($rc / $rev * 100, 1) : 0.0,
    ];
}
