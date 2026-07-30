<?php
// ============================================================================
//  invoice.php — la facturation.
//
//  Une facture n'est pas un affichage de la commande : c'est un DOCUMENT
//  autonome, qui doit rester identique dix ans après, même si le produit
//  change de nom, si le vendeur déménage ou si le client disparaît. Trois
//  conséquences, appliquées ici :
//
//   1. TOUT EST FIGÉ À L'ÉMISSION. Nom et adresse du vendeur, coordonnées de
//      l'acheteur, libellés et prix des lignes : recopiés dans la facture, pas
//      lus par jointure. Corriger l'adresse du siège demain ne réécrit pas les
//      factures d'hier.
//
//   2. LA NUMÉROTATION EST CONTINUE ET SANS TROU. Le numéro est attribué DANS
//      la transaction qui écrit la facture, et l'unicité est garantie par un
//      index UNIQUE : deux émissions simultanées ne peuvent pas obtenir le
//      même numéro, et une transaction annulée ne consomme pas de numéro.
//      La série est déduite du format lui-même — un format qui contient le
//      mois se remet à 1 chaque mois, sinon chaque année.
//
//   3. UNE FACTURE ÉMISE NE SE MODIFIE PAS. On corrige par une facture de
//      correction (faktura korygująca) qui référence l'originale. C'est la
//      seule voie ; il n'existe pas de fonction de mise à jour.
//
//  Le client particulier sans NIP ne reçoit pas de facture mais un e-paragon
//  (document interne, numéroté à part) — c'est ce que prévoit le parcours de
//  vente et ce que la maquette annonçait.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/shop.php';

const WSM_INVOICE_KINDS = ['faktura', 'korekta', 'paragon'];

/** Format par défaut si personne n'en a réglé : numéro, mois, année. */
const WSM_INVOICE_FORMAT_DEFAULT = 'xxx/mm/yy';

function wsm_invoice_cfg(): array {
    $c = (array) (wsm_config()['invoice'] ?? []);
    $c += ['seller_name' => '', 'seller_nip' => '', 'seller_address' => '', 'place' => '',
           'iban' => '', 'bank' => '', 'payment_days' => '', 'number_format' => ''];
    $fmt = trim((string) $c['number_format']);
    if ($fmt === '' || !preg_match('/x{2,6}/i', $fmt)) $fmt = WSM_INVOICE_FORMAT_DEFAULT;
    $c['number_format'] = $fmt;
    $c['payment_days'] = is_numeric($c['payment_days']) ? max(0, (int) $c['payment_days']) : 0;
    return $c;
}

/** Ce qui manque pour émettre une facture opposable. */
function wsm_invoice_blockers(): array {
    $c = wsm_invoice_cfg();
    $out = [];
    if (trim((string) $c['seller_name']) === '') $out[] = 'nazwa sprzedawcy';
    if (trim((string) $c['seller_nip']) === '')  $out[] = 'NIP sprzedawcy';
    if (trim((string) $c['seller_address']) === '') $out[] = 'adres sprzedawcy';
    if (trim((string) $c['iban']) === '')        $out[] = 'numer rachunku';
    return $out;
}

// ---------------------------------------------------------------------------
//  Numérotation
// ---------------------------------------------------------------------------

/**
 * La clé de série portée par le format. Un format qui contient « mm » impose
 * une remise à zéro mensuelle : sans elle, deux mois pourraient produire le
 * même numéro. Idem pour l'année. Un format sans date ne se remet jamais à
 * zéro — la suite est alors continue depuis toujours.
 */
function wsm_invoice_series(string $format, string $date): string {
    [$y, $m] = [substr($date, 0, 4), substr($date, 5, 2)];
    if (preg_match('/mm/i', $format)) return $y . '-' . $m;
    if (preg_match('/yy/i', $format)) return $y;
    return 'all';
}

/**
 * Compose le numéro. Jetons : x… (rang, complété de zéros à la largeur écrite),
 * yyyy, yy, mm, dd. Tout le reste est repris tel quel.
 */
function wsm_invoice_format_number(string $format, int $seq, string $date): string {
    return (string) preg_replace_callback('/x{2,6}|yyyy|yy|mm|dd/i', function ($m) use ($seq, $date) {
        $t = strtolower($m[0]);
        if ($t[0] === 'x')      return str_pad((string) $seq, strlen($t), '0', STR_PAD_LEFT);
        if ($t === 'yyyy')      return substr($date, 0, 4);
        if ($t === 'yy')        return substr($date, 2, 2);
        if ($t === 'mm')        return substr($date, 5, 2);
        return substr($date, 8, 2);                      // dd
    }, $format);
}

/**
 * Le rang suivant dans la série. Appelé DANS la transaction d'écriture : c'est
 * ce qui permet à l'index UNIQUE de trancher entre deux émissions simultanées
 * plutôt que de produire deux fois le même numéro.
 */
function wsm_invoice_next_seq(PDO $pdo, string $series, string $kind): int {
    $st = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM wsm_invoices WHERE series = ? AND kind_group = ?");
    $st->execute([$series, wsm_invoice_kind_group($kind)]);
    return ((int) $st->fetchColumn()) + 1;
}

/**
 * Les e-paragons ont leur propre suite : mélanger les deux ferait des trous
 * dans la numérotation des factures, ce qu'un contrôle fiscal remarque.
 */
function wsm_invoice_kind_group(string $kind): string {
    return $kind === 'paragon' ? 'paragon' : 'faktura';
}

// ---------------------------------------------------------------------------
//  Émission
// ---------------------------------------------------------------------------

/**
 * Émet le document d'une commande. Une commande n'a qu'un document : rappeler
 * cette fonction renvoie celui qui existe déjà, jamais un second.
 *
 * @return array [facture|null, erreur|null]
 */
function wsm_invoice_issue(PDO $pdo, array $order, string $actor = ''): array {
    $existing = wsm_invoice_for_order($pdo, (int) $order['id']);
    if ($existing) return [$existing, null];

    $kind = !empty($order['invoice']) && trim((string) ($order['nip'] ?? '')) !== '' ? 'faktura' : 'paragon';

    if ($kind === 'faktura') {
        $miss = wsm_invoice_blockers();
        if ($miss) return [null, 'brakuje danych sprzedawcy: ' . implode(', ', $miss)];
    }

    $c    = wsm_invoice_cfg();
    $date = date('Y-m-d');
    $due  = date('Y-m-d', strtotime($date . ' +' . (int) $c['payment_days'] . ' days'));

    // Le vendeur et l'acheteur sont RECOPIÉS : le document doit se relire seul.
    $buyerName = trim((string) ($order['company'] ?? '')) !== ''
        ? (string) $order['company']
        : trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
    $b = $order['bill'] ?? [];
    $buyerAddr = trim(($b['street'] ?? '') . ' ' . ($b['building'] ?? '') . ', ' .
                      ($b['postcode'] ?? '') . ' ' . ($b['city'] ?? '') . ', ' . ($b['country'] ?? ''));
    if (trim($buyerAddr, ' ,') === '') {
        $s = $order['ship'] ?? [];
        $buyerAddr = trim(($s['street'] ?? '') . ' ' . ($s['building'] ?? '') . ', ' .
                          ($s['postcode'] ?? '') . ' ' . ($s['city'] ?? '') . ', ' . ($s['country'] ?? ''));
    }

    $pdo->beginTransaction();
    try {
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, $kind);
        $number = wsm_invoice_format_number($c['number_format'], $seq, $date);
        if ($kind === 'paragon') $number = 'PAR/' . $number;

        $cols = [
            'order_id'       => (int) $order['id'],
            'kind'           => $kind,
            'kind_group'     => wsm_invoice_kind_group($kind),
            'corrects_id'    => null,
            'number'         => $number,
            'series'         => $series,
            'seq'            => $seq,
            'issued_at'      => $date,
            'sold_at'        => substr((string) ($order['created_at'] ?? $date), 0, 10),
            'due_at'         => $due,
            'place'          => (string) $c['place'],
            'seller_name'    => (string) $c['seller_name'],
            'seller_nip'     => (string) $c['seller_nip'],
            'seller_address' => (string) $c['seller_address'],
            'iban'           => (string) $c['iban'],
            'bank'           => (string) $c['bank'],
            'buyer_name'     => $buyerName,
            'buyer_nip'      => (string) ($order['nip'] ?? ''),
            'buyer_vat_eu'   => (string) ($order['vat_eu'] ?? ''),
            'buyer_address'  => trim($buyerAddr, ' ,'),
            'buyer_email'    => (string) ($order['email'] ?? ''),
            'currency'       => (string) ($order['currency'] ?? 'PLN'),
            'total_net'      => (int) $order['total_net'],
            'total_vat'      => (int) $order['total_vat'],
            'total_gross'    => (int) $order['total_gross'],
            'reverse_charge' => !empty($order['reverse_charge']) ? 1 : 0,
            'paid'           => ($order['payment_status'] ?? '') === 'oplacone' ? 1 : 0,
            'created_by'     => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ((array) ($order['items'] ?? []) as $l) {
            $ins->execute([$id, (string) $l['name'], (string) ($l['sku'] ?? ''), (int) $l['qty'],
                (int) $l['unit_net'], (int) $l['unit_gross'], (float) $l['vat_rate'],
                (int) $l['line_net'], (int) $l['line_vat'], (int) $l['line_gross']]);
        }
        // La livraison est une prestation facturée : elle a sa ligne, sinon le
        // total du document ne retomberait pas sur celui de la commande.
        if ((int) ($order['shipping_gross'] ?? 0) > 0) {
            $shipRate = (int) $order['shipping_net'] > 0
                ? round((int) $order['shipping_vat'] / (int) $order['shipping_net'], 2) : 0.0;
            $ins->execute([$id, 'Dostawa — ' . (string) ($order['delivery_method'] ?? ''), '', 1,
                (int) $order['shipping_net'], (int) $order['shipping_gross'], $shipRate,
                (int) $order['shipping_net'], (int) $order['shipping_vat'], (int) $order['shipping_gross']]);
        }

        wsm_order_event($pdo, (int) $order['id'], 'faktura', $number, $actor ?: 'system');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić: ' . $e->getMessage()];
    }

    return [wsm_invoice_by_id($pdo, $id), null];
}

/**
 * Facture de correction. Elle ne modifie rien : elle porte les montants de
 * remplacement et référence l'originale, qui reste telle quelle.
 */
function wsm_invoice_correct(PDO $pdo, array $src, array $in, string $actor = ''): array {
    if (($src['kind'] ?? '') === 'korekta') return [null, 'nie koryguje się korekty'];
    $reason = trim((string) ($in['reason'] ?? ''));
    if ($reason === '') return [null, 'przyczyna korekty jest wymagana'];

    $gross = (int) round(((float) str_replace(',', '.', (string) ($in['total_gross'] ?? 0))) * 100);
    if ($gross < 0) return [null, 'kwota nie może być ujemna'];

    // La TVA de la correction suit le taux dominant de la facture d'origine :
    // une correction globale ne peut pas inventer une ventilation.
    $rate = (float) ($src['items'][0]['vat_rate'] ?? 0.23);
    if (!empty($src['reverse_charge'])) $rate = 0.0;
    [$net, $vat] = wsm_split_vat($gross, $rate);

    $c    = wsm_invoice_cfg();
    $date = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, 'korekta');
        $number = 'KOR/' . wsm_invoice_format_number($c['number_format'], $seq, $date);

        $cols = [
            'order_id' => $src['order_id'], 'kind' => 'korekta', 'kind_group' => 'faktura',
            'corrects_id' => (int) $src['id'], 'number' => $number, 'series' => $series, 'seq' => $seq,
            'issued_at' => $date, 'sold_at' => (string) $src['sold_at'], 'due_at' => $date,
            'place' => (string) $c['place'],
            'seller_name' => (string) $src['seller_name'], 'seller_nip' => (string) $src['seller_nip'],
            'seller_address' => (string) $src['seller_address'],
            'iban' => (string) $src['iban'], 'bank' => (string) $src['bank'],
            'buyer_name' => (string) $src['buyer_name'], 'buyer_nip' => (string) $src['buyer_nip'],
            'buyer_vat_eu' => (string) $src['buyer_vat_eu'], 'buyer_address' => (string) $src['buyer_address'],
            'buyer_email' => (string) $src['buyer_email'], 'currency' => (string) $src['currency'],
            'total_net' => $net, 'total_vat' => $vat, 'total_gross' => $gross,
            'reverse_charge' => (int) $src['reverse_charge'], 'paid' => 0,
            'note' => $reason, 'created_by' => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, 'Korekta do ' . $src['number'] . ' — ' . $reason, '', 1,
                       $net, $gross, $rate, $net, $vat, $gross]);
        if ($src['order_id']) {
            wsm_order_event($pdo, (int) $src['order_id'], 'korekta', $number, $actor ?: 'system');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić korekty: ' . $e->getMessage()];
    }
    return [wsm_invoice_by_id($pdo, $id), null];
}

// ---------------------------------------------------------------------------
//  Lectures
// ---------------------------------------------------------------------------

function wsm_invoice_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE id = ?");
    $st->execute([$id]);
    $inv = $st->fetch();
    return $inv ? wsm_invoice_hydrate($pdo, $inv) : null;
}

function wsm_invoice_for_order(PDO $pdo, int $orderId): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE order_id = ? AND kind <> 'korekta' ORDER BY id LIMIT 1");
    $st->execute([$orderId]);
    $inv = $st->fetch();
    return $inv ? wsm_invoice_hydrate($pdo, $inv) : null;
}

function wsm_invoice_hydrate(PDO $pdo, array $inv): array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoice_items WHERE invoice_id = ? ORDER BY id");
    $st->execute([(int) $inv['id']]);
    $inv['items'] = $st->fetchAll() ?: [];

    // Ventilation par taux : c'est ce que la loi demande d'afficher, et c'est
    // aussi ce qui permet de relire le document sans refaire le calcul.
    $by = [];
    foreach ($inv['items'] as $l) {
        $k = (string) $l['vat_rate'];
        $by[$k] ??= ['rate' => (float) $l['vat_rate'], 'net' => 0, 'vat' => 0, 'gross' => 0];
        $by[$k]['net']   += (int) $l['line_net'];
        $by[$k]['vat']   += (int) $l['line_vat'];
        $by[$k]['gross'] += (int) $l['line_gross'];
    }
    krsort($by);
    $inv['vat_breakdown'] = array_values($by);
    return $inv;
}

function wsm_invoices_list(PDO $pdo, array $f = []): array {
    $where = []; $args = [];
    if (!empty($f['q'])) {
        $where[] = '(number LIKE ? OR buyer_name LIKE ? OR buyer_nip LIKE ?)';
        array_push($args, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    if (!empty($f['kind'])) { $where[] = 'kind = ?'; $args[] = (string) $f['kind']; }
    $sql = "SELECT * FROM wsm_invoices" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC LIMIT ' . max(1, min(500, (int) ($f['limit'] ?? 200)));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function wsm_invoice_kpis(PDO $pdo): array {
    $n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        'count'     => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'faktura'"),
        'paragons'  => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'paragon'"),
        'korekty'   => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'korekta'"),
        'gross'     => $n("SELECT COALESCE(SUM(total_gross), 0) FROM wsm_invoices WHERE kind = 'faktura'"),
        'unpaid'    => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'faktura' AND paid = 0"),
        'to_issue'  => $n("SELECT COUNT(*) FROM wsm_orders o WHERE o.payment_status = 'oplacone'
                             AND NOT EXISTS (SELECT 1 FROM wsm_invoices i WHERE i.order_id = o.id)"),
    ];
}

/** Les commandes encaissées qui n'ont pas encore de document. */
function wsm_invoice_pending_orders(PDO $pdo, int $limit = 50): array {
    $rows = $pdo->query("SELECT o.id FROM wsm_orders o
                          WHERE o.payment_status = 'oplacone'
                            AND NOT EXISTS (SELECT 1 FROM wsm_invoices i WHERE i.order_id = o.id)
                          ORDER BY o.id DESC LIMIT " . max(1, min(200, $limit)))->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $o = wsm_order_by_id($pdo, (int) $r['id']);
        if ($o) $out[] = $o;
    }
    return $out;
}
