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

const WSM_INVOICE_KINDS = ['faktura', 'korekta', 'paragon', 'proforma'];

/** Format par défaut si personne n'en a réglé : numéro, mois, année. */
const WSM_INVOICE_FORMAT_DEFAULT = 'xxx/mm/yy';

function wsm_invoice_cfg(): array {
    $c = (array) (wsm_config()['invoice'] ?? []);
    $c += ['seller_name' => '', 'seller_nip' => '', 'seller_address' => '', 'place' => '',
           'iban' => '', 'bank' => '', 'payment_days' => '', 'number_format' => '',
           'reminder_days' => ''];
    $fmt = trim((string) $c['number_format']);
    if ($fmt === '' || !preg_match('/x{2,6}/i', $fmt)) $fmt = WSM_INVOICE_FORMAT_DEFAULT;
    $c['number_format'] = $fmt;
    $c['payment_days'] = is_numeric($c['payment_days']) ? max(0, (int) $c['payment_days']) : 0;
    $c['reminder_days'] = is_numeric($c['reminder_days'] ?? '') ? max(0, (int) $c['reminder_days']) : 0;
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
    if ($kind === 'paragon')  return 'paragon';
    // La proforma n'est pas un document fiscal : elle ne consomme aucun numéro
    // de la suite des factures. Mélanger les deux ferait des trous dans une
    // numérotation qui doit être continue.
    if ($kind === 'proforma') return 'proforma';
    return 'faktura';
}

/**
 * Facture proforma. Ce n'est PAS une facture : c'est une demande de paiement,
 * qui n'ouvre aucun droit à déduction de TVA et n'entre dans aucune
 * déclaration. Elle se refait autant de fois qu'il le faut — d'où la
 * possibilité d'en émettre une nouvelle à partir d'une facture existante,
 * ce qu'on ne s'autoriserait jamais sur un document fiscal.
 *
 * @param array $order  la commande, ou la facture dont on repart
 * @return array [proforma|null, erreur|null]
 */
function wsm_invoice_proforma(PDO $pdo, array $src, string $actor = '', string $note = ''): array {
    $fromInvoice = isset($src['number']);          // on repart d'une facture
    $orderId = (int) ($src['order_id'] ?? $src['id'] ?? 0);
    if ($fromInvoice) {
        $lines = array_map(fn($l) => [
            'name' => (string) $l['name'], 'sku' => (string) $l['sku'], 'qty' => (int) $l['qty'],
            'unit_net' => (int) $l['unit_net'], 'unit_gross' => (int) $l['unit_gross'],
            'vat_rate' => (float) $l['vat_rate'], 'line_net' => (int) $l['line_net'],
            'line_vat' => (int) $l['line_vat'], 'line_gross' => (int) $l['line_gross'],
        ], (array) ($src['items'] ?? []));
        $buyer = ['name' => (string) $src['buyer_name'], 'nip' => (string) $src['buyer_nip'],
                  'vat_eu' => (string) $src['buyer_vat_eu'], 'address' => (string) $src['buyer_address'],
                  'email' => (string) $src['buyer_email']];
        $tot = [(int) $src['total_net'], (int) $src['total_vat'], (int) $src['total_gross']];
        $rc  = (int) $src['reverse_charge'];
    } else {
        $lines = array_map(fn($l) => [
            'name' => (string) $l['name'], 'sku' => (string) ($l['sku'] ?? ''), 'qty' => (int) $l['qty'],
            'unit_net' => (int) $l['unit_net'], 'unit_gross' => (int) $l['unit_gross'],
            'vat_rate' => (float) $l['vat_rate'], 'line_net' => (int) $l['line_net'],
            'line_vat' => (int) $l['line_vat'], 'line_gross' => (int) $l['line_gross'],
        ], (array) ($src['items'] ?? []));
        if ((int) ($src['shipping_gross'] ?? 0) > 0) {
            $lines[] = ['name' => 'Dostawa — ' . (string) ($src['delivery_method'] ?? ''), 'sku' => '', 'qty' => 1,
                'unit_net' => (int) $src['shipping_net'], 'unit_gross' => (int) $src['shipping_gross'],
                'vat_rate' => (int) $src['shipping_net'] > 0 ? round((int) $src['shipping_vat'] / (int) $src['shipping_net'], 2) : 0.0,
                'line_net' => (int) $src['shipping_net'], 'line_vat' => (int) $src['shipping_vat'],
                'line_gross' => (int) $src['shipping_gross']];
        }
        $b = $src['bill'] ?? [];
        $addr = trim(trim(($b['street'] ?? '') . ' ' . ($b['building'] ?? '') . ', ' .
                          ($b['postcode'] ?? '') . ' ' . ($b['city'] ?? '') . ', ' . ($b['country'] ?? '')), ' ,');
        $buyer = [
            'name' => trim((string) ($src['company'] ?? '')) !== '' ? (string) $src['company']
                      : trim(($src['first_name'] ?? '') . ' ' . ($src['last_name'] ?? '')),
            'nip' => (string) ($src['nip'] ?? ''), 'vat_eu' => (string) ($src['vat_eu'] ?? ''),
            'address' => $addr, 'email' => (string) ($src['email'] ?? ''),
        ];
        $tot = [(int) $src['total_net'], (int) $src['total_vat'], (int) $src['total_gross']];
        $rc  = !empty($src['reverse_charge']) ? 1 : 0;
    }
    if (!$lines) return [null, 'dokument bez pozycji'];

    $c = wsm_invoice_cfg();
    $date = date('Y-m-d');
    $due  = date('Y-m-d', strtotime($date . ' +' . (int) $c['payment_days'] . ' days'));

    $pdo->beginTransaction();
    try {
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, 'proforma');
        $number = 'PRO/' . wsm_invoice_format_number($c['number_format'], $seq, $date);

        $cols = [
            'order_id' => $orderId ?: null, 'kind' => 'proforma', 'kind_group' => 'proforma',
            'corrects_id' => $fromInvoice ? (int) $src['id'] : null,
            'number' => $number, 'series' => $series, 'seq' => $seq,
            'issued_at' => $date, 'sold_at' => $date, 'due_at' => $due, 'place' => (string) $c['place'],
            'seller_name' => (string) $c['seller_name'], 'seller_nip' => (string) $c['seller_nip'],
            'seller_address' => (string) $c['seller_address'],
            'iban' => (string) $c['iban'], 'bank' => (string) $c['bank'],
            'buyer_name' => $buyer['name'], 'buyer_nip' => $buyer['nip'],
            'buyer_vat_eu' => $buyer['vat_eu'], 'buyer_address' => $buyer['address'],
            'buyer_email' => $buyer['email'], 'currency' => 'PLN',
            'total_net' => $tot[0], 'total_vat' => $tot[1], 'total_gross' => $tot[2],
            'reverse_charge' => $rc, 'paid' => 0,
            'note' => mb_substr($note, 0, 250), 'created_by' => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $l) {
            $ins->execute([$id, $l['name'], $l['sku'], $l['qty'], $l['unit_net'], $l['unit_gross'],
                           $l['vat_rate'], $l['line_net'], $l['line_vat'], $l['line_gross']]);
        }
        if ($orderId) wsm_order_event($pdo, $orderId, 'proforma', $number, $actor ?: 'system');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić proformy: ' . $e->getMessage()];
    }
    return [wsm_invoice_by_id($pdo, $id), null];
}

/**
 * Les factures impayées dont l'échéance est dépassée d'au moins $days jours.
 * Sert aux relances — et rien d'autre : on ne relance jamais un document
 * déjà payé, ni une proforma (qui EST déjà une demande de paiement).
 */
function wsm_invoices_overdue(PDO $pdo, int $days = 0): array {
    $limit = date('Y-m-d', time() - $days * 86400);
    $st = $pdo->prepare("SELECT * FROM wsm_invoices
                          WHERE kind = 'faktura' AND paid = 0 AND due_at <= ?
                          ORDER BY due_at LIMIT 100");
    $st->execute([$limit]);
    return array_map(fn($i) => wsm_invoice_hydrate($pdo, $i), $st->fetchAll() ?: []);
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

    // LA RÈGLE VIT DANS UNE SEULE FONCTION. Elle était écrite ici en une ligne
    // — « facture si la case est cochée et qu'un NIP est saisi » — ce qui
    // donnait une facture à un numéro de TVA que VIES venait de REFUSER.
    $kind = wsm_invoice_kind_for($order)['kind'];

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

/**
 * Les relances, sans tâche planifiée. Le serveur n'a pas de cron à nous : on
 * profite donc de l'ouverture d'un écran de la console pour regarder si des
 * factures ont dépassé leur échéance du nombre de jours réglé.
 *
 * C'est sans danger parce que la clé d'événement contient la date : une
 * facture ne peut recevoir qu'UNE relance par jour, quel que soit le nombre
 * de fois où quelqu'un ouvre la page.
 *
 * @return int nombre de relances mises en file
 */
function wsm_invoice_reminders_run(PDO $pdo): int {
    $days = wsm_invoice_cfg()['reminder_days'];
    if ($days <= 0) return 0;
    require_once __DIR__ . '/mail.php';
    $n = 0;
    foreach (wsm_invoices_overdue($pdo, $days) as $inv) {
        if (wsm_mail_for_invoice($pdo, $inv, 'przypomnienie', 'automat')) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
//  QUEL DOCUMENT POUR CETTE COMMANDE — ET LE PIÈGE QU'IL Y A DERRIÈRE
//
//  La règle demandée est simple à dire : facture si le numéro de TVA est
//  confirmé par VIES, e-paragon sinon. Elle l'est moins à écrire, parce que
//  « pas confirmé par VIES » recouvre deux situations qui n'ont rien à voir :
//
//   · un numéro REFUSÉ par VIES — le client n'est pas l'entreprise qu'il dit
//     être. Facturer en autoliquidation serait une erreur qui coûte la TVA ;
//   · une entreprise POLONAISE avec son NIP. VIES ne sert à rien ici : la
//     vente est domestique, la TVA polonaise s'applique, et une société qui
//     demande une facture avec son NIP y a droit. Lui rendre un e-paragon
//     parce qu'aucun appel VIES n'a eu lieu, c'est refuser un document dû.
//
//  Le repli sur l'e-paragon est donc le DÉFAUT, pas la punition : il vaut
//  pour le particulier, pour le numéro refusé, et pour tout ce qu'on ne sait
//  pas. La facture demande une preuve — VIES pour l'étranger, un NIP valide
//  pour la Pologne.
//
//  ON NE DEVINE JAMAIS DEPUIS L'ÉTAT COURANT DE VIES. Le statut est FIGÉ sur
//  la commande au moment de la vente (vat_status). Un numéro valable en mars
//  et révoqué en juin ne doit pas changer le document de mars.
// ---------------------------------------------------------------------------

/**
 * Le document dû, et pourquoi. Fonction PURE : c'est elle qu'on teste, et
 * c'est elle qui décide de ce qui part au fisc.
 *
 * @return array{kind:string, raison:string}
 */
function wsm_invoice_kind_for(array $order): array {
    // DEUX FORMES POUR LA MÊME COMMANDE, et c'est un piège qui coûte cher.
    // La ligne brute de la base porte « vat_status » ; la commande HYDRATÉE
    // par wsm_order_by_id() range la même chose sous « vat.status ». Ne lire
    // que la première faisait retomber un numéro REFUSÉ par VIES sur la règle
    // du NIP — donc sur une facture. Les tests passaient : ils lisaient des
    // tableaux bruts. C'est en faisant l'aller-retour par la base que ça se voit.
    $vat = strtolower(trim((string) ($order['vat_status'] ?? ($order['vat']['status'] ?? ''))));
    $vatEu  = trim((string) ($order['vat_eu'] ?? ''));
    $nip    = trim((string) ($order['nip'] ?? ''));
    $veut   = !empty($order['invoice']);

    // 1. La preuve la plus forte : VIES a confirmé le numéro, et il est écrit.
    if ($vat === 'valid' && $vatEu !== '') {
        return ['kind' => 'faktura', 'raison' => 'numer VAT UE potwierdzony w VIES'];
    }
    // 2. Un numéro EXPLICITEMENT refusé ne donne pas de facture, même si le
    //    client en a coché la case : le repli est là pour ça.
    if ($vat === 'invalid') {
        return ['kind' => 'paragon', 'raison' => 'numer VAT odrzucony przez VIES — paragon'];
    }
    // 3. La Pologne. VIES ne prouve rien d'utile pour une vente domestique ;
    //    le NIP se vérifie par sa clé de contrôle, ce que fait wsm_valid_nip().
    if ($veut && $nip !== '') {
        if (!function_exists('wsm_valid_nip')) require_once __DIR__ . '/commerce.php';
        if (wsm_valid_nip($nip)) {
            return ['kind' => 'faktura', 'raison' => 'NIP poprawny — faktura krajowa'];
        }
        return ['kind' => 'paragon', 'raison' => 'NIP niepoprawny — paragon'];
    }
    // 4. Tout le reste : le particulier, et ce qu'on ne sait pas.
    return ['kind' => 'paragon', 'raison' => $veut
        ? 'brak numeru do faktury — paragon'
        : 'klient nie prosił o fakturę — paragon'];
}

/**
 * LE DOCUMENT D'UNE COMMANDE, DE BOUT EN BOUT : émis, envoyé, déposé.
 *
 * Appelée quand la commande passe à « wysłane », et par le bouton de l'écran
 * Zamówienia. IDEMPOTENTE dans les trois étages — c'est la seule façon de
 * pouvoir l'appeler depuis quatre endroits sans compter les documents :
 *
 *   · l'émission : wsm_invoice_issue() rend le document existant s'il y en a ;
 *   · le courrier : la file refuse une clé d'événement déjà vue ;
 *   · KSeF : un document qui porte déjà un numéro n'y retourne pas.
 *
 * ELLE NE LÈVE JAMAIS. Une commande expédiée dont le mail échoue reste une
 * commande expédiée : faire échouer l'expédition parce que le SMTP est en
 * panne mettrait le colis en attente pour une raison sans rapport.
 *
 * @return array{doc:?array, kind:string, raison:string, mail:bool, ksef:string}
 */
function wsm_order_document(PDO $pdo, array $order, string $actor = ''): array {
    $r = wsm_invoice_kind_for($order);
    $out = ['doc' => null, 'kind' => $r['kind'], 'raison' => $r['raison'],
            'mail' => false, 'ksef' => ''];

    [$doc, $err] = wsm_invoice_issue($pdo, $order, $actor);
    if (!$doc) { $out['raison'] = (string) $err; return $out; }
    $out['doc']  = $doc;
    $out['kind'] = (string) $doc['kind'];

    // --- Le courrier ---------------------------------------------------------
    // La clé d'événement porte le NUMÉRO du document, pas l'identifiant de la
    // commande : un avoir émis plus tard doit pouvoir partir à son tour.
    $mail = trim((string) ($order['email'] ?? ''));
    if ($mail !== '') {
        try {
            $lien = wsm_invoice_lien_public($pdo, $order, $doc);
            $est  = $out['kind'] === 'faktura' ? 'Faktura' : 'Paragon';
            $id = wsm_mail_queue($pdo, [
                'order_id'      => (int) $order['id'],
                'email'         => $mail,
                'subject'       => $est . ' ' . $doc['number'] . ' — Mister Szoko',
                'body'          => $est . " do zamówienia " . (string) $order['code'] . ".\n\n"
                                 . ($lien !== '' ? "Dokument: " . $lien . "\n\n" : '')
                                 . "Dziękujemy za zakupy.\nMister Szoko",
                'template_code' => 'dokument',
                'event_key'     => 'dok:' . $doc['number'],
                'actor'         => $actor !== '' ? $actor : 'system',
            ]);
            $out['mail'] = $id > 0;
        } catch (Throwable $e) { /* règle : le colis part quand même */ }
    }

    // --- KSeF ----------------------------------------------------------------
    // Un e-paragon N'EST PAS une facture : le déposer au registre national y
    // inscrirait un document qui n'existe pas pour le fisc. wsm_ksef_blockers()
    // le refuse déjà ; on ne l'appelle même pas.
    if ($out['kind'] === 'faktura') {
        $k = __DIR__ . '/ksef.php';
        if (is_file($k)) {
            require_once $k;
            if (function_exists('wsm_ksef_enabled') && wsm_ksef_enabled()) {
                try {
                    [$num, $kerr] = wsm_ksef_wyslij($pdo, wsm_invoice_hydrate($pdo, $doc), $actor);
                    $out['ksef'] = $num !== null && $num !== '' ? (string) $num : (string) $kerr;
                } catch (Throwable $e) { $out['ksef'] = 'błąd wysyłki do KSeF'; }
            } else {
                // Pas une panne : le canal n'est pas ouvert. L'écran KSeF tient
                // la file, et le document y attendra son tour.
                $out['ksef'] = 'kanał KSeF zamknięty — dokument czeka w kolejce';
            }
        }
    }
    return $out;
}

/** Le lien public du document, s'il en existe un. Jamais de secret dedans. */
function wsm_invoice_lien_public(PDO $pdo, array $order, array $doc): string {
    $base = trim((string) (wsm_config()['shop_url'] ?? ''));
    $tok  = trim((string) ($order['access_token'] ?? ''));
    if ($base === '' || $tok === '') return '';
    return rtrim($base, '/') . '/zamowienie/' . rawurlencode((string) $order['code'])
         . '?t=' . rawurlencode($tok);
}
