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
                (product_id, delta, kind, stock_after, reason, note, doc, supplier, unit_cost, actor, doc_id)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $productId, $delta, $kind, $after,
                mb_substr((string) ($o['reason'] ?? ''), 0, 120),
                mb_substr((string) ($o['note'] ?? ''), 0, 250),
                mb_substr((string) ($o['doc'] ?? ''), 0, 40),
                mb_substr((string) ($o['supplier'] ?? ''), 0, 120),
                (int) ($o['unit_cost'] ?? 0),
                mb_substr((string) ($o['actor'] ?? 'system'), 0, 120),
                ($o['doc_id'] ?? null) ? (int) $o['doc_id'] : null,
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

// ---------------------------------------------------------------------------
//  Documents : le bon d'entrée (PZ) et le bon de sortie (WZ)
// ---------------------------------------------------------------------------
//
//  Une réception n'est pas un ajustement ligne par ligne : c'est UNE livraison,
//  d'UN fournisseur, sur UNE facture d'achat, avec plusieurs articles. La saisir
//  article par article fait perdre exactement ce qui compte — que ces douze
//  sacs et ces trois cartons sont arrivés ensemble, ce jour-là, sur ce bon.
//
//  Symétriquement, la sortie s'accompagne d'un bon de livraison : le papier qui
//  part avec le colis et que le client signe ou vérifie.
//
//  Les mouvements restent la vérité comptable. Le document les regroupe et leur
//  donne un numéro qu'on peut citer au téléphone.

const WSM_STOCK_DOC_KINDS = ['PZ' => 'Przyjęcie zewnętrzne', 'WZ' => 'Wydanie zewnętrzne'];

/** Numéro du document : PZ/007/07/26 — même logique de série que les factures. */
function wsm_stock_doc_number(PDO $pdo, string $kind, string $date): array {
    $series = substr($date, 0, 4) . '-' . substr($date, 5, 2);
    $st = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM wsm_stock_docs WHERE kind = ? AND series = ?");
    $st->execute([$kind, $series]);
    $seq = ((int) $st->fetchColumn()) + 1;
    return [$kind . '/' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT)
              . '/' . substr($date, 5, 2) . '/' . substr($date, 2, 2), $series, $seq];
}

/**
 * Enregistre une réception à plusieurs lignes. Tout ou rien : si une ligne est
 * refusée, aucune n'entre — un bon à moitié saisi est pire qu'un bon absent,
 * parce que personne ne sait ce qui manque.
 *
 * @param array $lines  [['product_id'=>…, 'qty'=>int, 'unit_cost'=>grosze], …]
 * @return array [document|null, erreur|null]
 */
function wsm_stock_receive(PDO $pdo, array $head, array $lines): array {
    $clean = [];
    foreach ($lines as $l) {
        $pid = trim((string) ($l['product_id'] ?? ''));
        $qty = (int) ($l['qty'] ?? 0);
        if ($pid === '' || $qty === 0) continue;                 // ligne laissée vide
        if ($qty < 0) return [null, 'ilość nie może być ujemna'];
        $clean[] = ['product_id' => $pid, 'qty' => $qty, 'unit_cost' => (int) ($l['unit_cost'] ?? 0)];
    }
    if (!$clean) return [null, 'dokument bez pozycji'];

    $date = (string) ($head['issued_at'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        [$number, $series, $seq] = wsm_stock_doc_number($pdo, 'PZ', $date);
        $units = array_sum(array_map(fn($l) => $l['qty'], $clean));
        $value = array_sum(array_map(fn($l) => $l['qty'] * $l['unit_cost'], $clean));

        $pdo->prepare("INSERT INTO wsm_stock_docs (kind, number, series, seq, partner, ref, issued_at, note, units, value, actor)
                       VALUES ('PZ',?,?,?,?,?,?,?,?,?,?)")
            ->execute([$number, $series, $seq,
                mb_substr((string) ($head['partner'] ?? ''), 0, 160),
                mb_substr((string) ($head['ref'] ?? ''), 0, 60),
                $date, mb_substr((string) ($head['note'] ?? ''), 0, 250), $units, $value,
                mb_substr((string) ($head['actor'] ?? ''), 0, 120)]);
        $docId = (int) $pdo->lastInsertId();

        foreach ($clean as $l) {
            wsm_stock_apply($pdo, $l['product_id'], $l['qty'], 'przyjecie', [
                'doc' => $number, 'doc_id' => $docId,
                'supplier' => (string) ($head['partner'] ?? ''),
                'unit_cost' => $l['unit_cost'],
                'note' => (string) ($head['ref'] ?? ''),
                'actor' => (string) ($head['actor'] ?? ''),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się zapisać: ' . $e->getMessage()];
    }
    return [wsm_stock_doc_by_number($pdo, $number), null];
}

/**
 * Le bon de livraison d'une commande. La marchandise est déjà sortie du stock
 * au moment de la commande (c'est ce qui empêche de vendre deux fois le même
 * sac) : ce document ne bouge donc RIEN. Il nomme ce qui part, lui donne un
 * numéro, et rattache les mouvements déjà écrits — de sorte qu'en rouvrant le
 * bon on retrouve exactement les sorties correspondantes.
 */
function wsm_stock_issue_wz(PDO $pdo, array $order, string $actor = ''): array {
    $st = $pdo->prepare("SELECT * FROM wsm_stock_docs WHERE kind = 'WZ' AND order_id = ? LIMIT 1");
    $st->execute([(int) $order['id']]);
    if ($row = $st->fetch()) return [wsm_stock_doc_hydrate($pdo, $row), null];
    if (!($order['items'] ?? [])) return [null, 'zamówienie bez pozycji'];

    $date = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        [$number, $series, $seq] = wsm_stock_doc_number($pdo, 'WZ', $date);
        $units = array_sum(array_map(fn($l) => (int) $l['qty'], $order['items']));
        $value = array_sum(array_map(fn($l) => (int) $l['line_gross'], $order['items']));
        $who = trim((string) ($order['company'] ?? '')) !== ''
            ? (string) $order['company']
            : trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));

        $pdo->prepare("INSERT INTO wsm_stock_docs (kind, number, series, seq, order_id, partner, ref, issued_at, units, value, actor)
                       VALUES ('WZ',?,?,?,?,?,?,?,?,?,?)")
            ->execute([$number, $series, $seq, (int) $order['id'], mb_substr($who, 0, 160),
                       (string) $order['code'], $date, $units, $value, mb_substr($actor, 0, 120)]);
        $docId = (int) $pdo->lastInsertId();

        // On rattache les sorties déjà écrites par la commande.
        $pdo->prepare("UPDATE wsm_stock_moves SET doc_id = ? WHERE doc = ? AND kind = 'sprzedaz' AND doc_id IS NULL")
            ->execute([$docId, (string) $order['code']]);

        wsm_order_event($pdo, (int) $order['id'], 'wz', $number, $actor ?: 'system');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić WZ: ' . $e->getMessage()];
    }
    return [wsm_stock_doc_by_number($pdo, $number), null];
}

function wsm_stock_doc_by_number(PDO $pdo, string $number): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_stock_docs WHERE number = ?");
    $st->execute([$number]);
    $d = $st->fetch();
    return $d ? wsm_stock_doc_hydrate($pdo, $d) : null;
}

function wsm_stock_doc_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_stock_docs WHERE id = ?");
    $st->execute([$id]);
    $d = $st->fetch();
    return $d ? wsm_stock_doc_hydrate($pdo, $d) : null;
}

/** Le document et ses lignes — c'est-à-dire les mouvements qu'il a produits. */
function wsm_stock_doc_hydrate(PDO $pdo, array $d): array {
    $st = $pdo->prepare("SELECT m.*, p.nom AS product_name, p.sku
                           FROM wsm_stock_moves m
                           LEFT JOIN wsm_products p ON p.id = m.product_id
                          WHERE m.doc_id = ? ORDER BY m.id");
    $st->execute([(int) $d['id']]);
    $d['lines'] = $st->fetchAll() ?: [];

    // Un WZ rattache des sorties : pour l'affichage, on veut aussi ce que la
    // commande devait, y compris ce qui reste à produire.
    if ($d['kind'] === 'WZ' && $d['order_id']) {
        $st = $pdo->prepare("SELECT name, sku, qty, backorder, line_gross FROM wsm_order_items WHERE order_id = ? ORDER BY id");
        $st->execute([(int) $d['order_id']]);
        $d['order_items'] = $st->fetchAll() ?: [];
    }
    return $d;
}

function wsm_stock_docs_list(PDO $pdo, array $f = []): array {
    $where = []; $args = [];
    if (!empty($f['kind'])) { $where[] = 'kind = ?'; $args[] = (string) $f['kind']; }
    if (!empty($f['q']))    { $where[] = '(number LIKE ? OR partner LIKE ? OR ref LIKE ?)';
                              array_push($args, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%'); }
    $sql = "SELECT * FROM wsm_stock_docs" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC LIMIT ' . max(1, min(300, (int) ($f['limit'] ?? 60)));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}
