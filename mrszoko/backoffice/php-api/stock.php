<?php
// ============================================================================
//  stock.php — le magasin : ce qui entre, ce qui sort, ce qui reste.
//
//  Jusqu'ici le stock était un nombre qu'on écrasait dans la fiche produit.
//  Un nombre sans histoire ne répond à aucune des questions qu'on se pose
//  vraiment : pourquoi il en manque, qui a corrigé, quand la dernière palette
//  est arrivée, à quel prix, et combien de jours il reste avant la rupture.
//
//  Donc : wsm_products.stock reste la quantité qui décide de la vente — c'est
//  elle qu'on décrémente sous transaction — mais TOUT changement passe par
//  wsm_stock_apply(), qui écrit un mouvement. Le stock devient une somme
//  vérifiable, pas une valeur posée.
//
//  Quatre natures de mouvement, et pas une de plus :
//    przyjecie  une entrée (livraison fournisseur), avec prix d'achat
//    sprzedaz   une sortie provoquée par une commande
//    zwrot      un retour client
//    korekta    tout le reste : casse, dégustation, inventaire — motif exigé
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const WSM_STOCK_KINDS = [
    'przyjecie' => 'Przyjęcie',
    'sprzedaz'  => 'Sprzedaż',
    'zwrot'     => 'Zwrot',
    'korekta'   => 'Korekta',
];

/** Seuil de vigilance, en jours de vente couverts par le stock. */
const WSM_STOCK_COVER_LOW = 14;

/**
 * Applique un mouvement : met à jour la quantité ET l'inscrit au journal.
 * Le stock ne descend jamais sous zéro — une commande au-delà du stock est
 * acceptée (voir shop.php), mais on ne prétend pas avoir −3 sacs.
 *
 * @param int   $delta  positif = entrée, négatif = sortie
 * @return int  la quantité après mouvement
 */
function wsm_stock_apply(PDO $pdo, string $productId, int $delta, string $kind, array $o = []): int {
    if (!isset(WSM_STOCK_KINDS[$kind])) $kind = 'korekta';

    $st = $pdo->prepare("SELECT stock FROM wsm_products WHERE id = ?");
    $st->execute([$productId]);
    $before = (int) $st->fetchColumn();
    $after  = max(0, $before + $delta);
    $real   = $after - $before;                 // ce qui a VRAIMENT bougé

    if ($real !== 0) {
        $pdo->prepare("UPDATE wsm_products SET stock = ? WHERE id = ?")->execute([$after, $productId]);
    }
    wsm_stock_log($pdo, $productId, $real, $kind, $after, $o);
    return $after;
}

/** Inscrit un mouvement sans toucher à la quantité (elle l'a déjà été). */
function wsm_stock_log(PDO $pdo, string $productId, int $delta, string $kind, int $after, array $o = []): void {
    try {
        $pdo->prepare("INSERT INTO wsm_stock_moves
                (product_id, delta, kind, stock_after, reason, note, doc, supplier, unit_cost, actor)
              VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $productId, $delta, $kind, $after,
                mb_substr((string) ($o['reason'] ?? ''), 0, 120),
                mb_substr((string) ($o['note'] ?? ''), 0, 250),
                mb_substr((string) ($o['doc'] ?? ''), 0, 40),
                mb_substr((string) ($o['supplier'] ?? ''), 0, 120),
                (int) ($o['unit_cost'] ?? 0),
                mb_substr((string) ($o['actor'] ?? 'system'), 0, 120),
            ]);
    } catch (Throwable $e) {
        // Le journal ne doit jamais faire échouer une vente. Un mouvement
        // manquant se voit (l'écart entre la somme et la quantité), une
        // commande perdue ne se rattrape pas.
    }
}

/**
 * Porte la quantité à une valeur absolue — c'est ce que fait la fiche produit.
 * Enregistré comme correction, avec le motif : « j'ai compté 42 » est une
 * information, « le stock vaut 42 » n'en est pas une.
 */
function wsm_stock_set(PDO $pdo, string $productId, int $target, array $o = []): int {
    $st = $pdo->prepare("SELECT stock FROM wsm_products WHERE id = ?");
    $st->execute([$productId]);
    $before = (int) $st->fetchColumn();
    if ($target === $before) return $before;
    return wsm_stock_apply($pdo, $productId, $target - $before, $o['kind'] ?? 'korekta', $o);
}

// ---------------------------------------------------------------------------
//  Lectures
// ---------------------------------------------------------------------------

function wsm_stock_moves(PDO $pdo, array $f = []): array {
    $where = []; $args = [];
    if (!empty($f['product_id'])) { $where[] = 'm.product_id = ?'; $args[] = (string) $f['product_id']; }
    if (!empty($f['kind']))       { $where[] = 'm.kind = ?';       $args[] = (string) $f['kind']; }
    if (!empty($f['from']))       { $where[] = 'm.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))         { $where[] = 'm.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
    $sql = "SELECT m.*, p.nom AS product_name FROM wsm_stock_moves m
              LEFT JOIN wsm_products p ON p.id = m.product_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY m.id DESC LIMIT ' . max(1, min(500, (int) ($f['limit'] ?? 100)));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

/**
 * L'état du magasin, produit par produit : ce qui reste, ce qui s'est vendu
 * sur 30 jours, et combien de jours cela couvre. La couverture est le seul
 * chiffre qui dise s'il faut commander — un stock de 5 est confortable pour
 * un produit qui part une fois par mois, critique pour un autre.
 */
function wsm_stock_overview(PDO $pdo, int $days = 30): array {
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $sold = [];
    try {
        $st = $pdo->prepare("SELECT i.product_id, COALESCE(SUM(i.qty), 0) AS n
                               FROM wsm_order_items i
                               JOIN wsm_orders o ON o.id = i.order_id
                              WHERE o.created_at >= ? AND o.status <> 'anulowane'
                              GROUP BY i.product_id");
        $st->execute([$since]);
        foreach ($st->fetchAll() as $r) $sold[(string) $r['product_id']] = (int) $r['n'];
    } catch (Throwable $e) { /* pas encore de commandes */ }

    // Ce que l'on doit déjà aux clients : les commandes acceptées au-delà du
    // stock. Cette quantité est à produire, elle ne se déduit pas du stock.
    $owed = [];
    try {
        $st = $pdo->query("SELECT i.product_id, COALESCE(SUM(i.backorder), 0) AS n
                             FROM wsm_order_items i
                             JOIN wsm_orders o ON o.id = i.order_id
                            WHERE i.backorder > 0 AND o.status IN ('nowe','oplacone','w_realizacji')
                            GROUP BY i.product_id");
        foreach ($st->fetchAll() as $r) $owed[(string) $r['product_id']] = (int) $r['n'];
    } catch (Throwable $e) { /* colonne absente sur une base ancienne */ }

    $rows = $pdo->query("SELECT id, nom, stock, shop_visible, prix, weight_g
                           FROM wsm_products WHERE active = 1
                          ORDER BY shop_visible DESC, nom")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $id    = (string) $r['id'];
        $n     = $sold[$id] ?? 0;
        $rate  = $n / max(1, $days);                       // ventes par jour
        $stock = (int) $r['stock'];
        $cover = $rate > 0 ? (int) floor($stock / $rate) : null;
        $out[] = [
            'id' => $id, 'name' => (string) $r['nom'], 'stock' => $stock,
            'visible' => (int) $r['shop_visible'] === 1,
            'sold' => $n, 'rate' => $rate, 'cover_days' => $cover,
            'owed' => $owed[$id] ?? 0,
            'value' => (int) round(((float) $r['prix']) * 100) * $stock,
            'status' => wsm_stock_status($stock, $cover, $owed[$id] ?? 0),
        ];
    }
    return $out;
}

function wsm_stock_status(int $stock, ?int $cover, int $owed): string {
    if ($stock <= 0)                    return 'brak';        // rupture
    if ($owed > 0)                      return 'dlug';        // on doit déjà des unités
    if ($cover !== null && $cover <= 7) return 'krytyczny';
    if ($cover !== null && $cover <= WSM_STOCK_COVER_LOW) return 'niski';
    return 'ok';
}

function wsm_stock_kpis(PDO $pdo): array {
    $ov = wsm_stock_overview($pdo);
    $vis = array_filter($ov, fn($r) => $r['visible']);
    return [
        'products'  => count($vis),
        'units'     => array_sum(array_map(fn($r) => $r['stock'], $vis)),
        'value'     => array_sum(array_map(fn($r) => $r['value'], $vis)),
        'out'       => count(array_filter($vis, fn($r) => $r['status'] === 'brak')),
        'low'       => count(array_filter($vis, fn($r) => in_array($r['status'], ['niski', 'krytyczny'], true))),
        'owed'      => array_sum(array_map(fn($r) => $r['owed'], $ov)),
    ];
}
