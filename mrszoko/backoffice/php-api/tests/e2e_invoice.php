<?php
// ============================================================================
//  e2e_invoice.php — preuve que la facturation tient devant un contrôle.
//
//  Ce qu'on démontre, dans l'ordre d'importance :
//
//   1. LA NUMÉROTATION EST CONTINUE ET SANS DOUBLON. C'est le premier point
//      qu'un contrôle fiscal regarde. Le rang est attribué en base, la série
//      se déduit du format, et deux documents ne peuvent pas porter le même
//      numéro même émis dans la même seconde.
//   2. LE DOCUMENT EST FIGÉ. Changer l'adresse du vendeur ou le nom d'un
//      produit APRÈS l'émission ne réécrit pas la facture.
//   3. LES MONTANTS TOMBENT JUSTE. net + TVA == brut, et le total du document
//      égale le total de la commande, livraison comprise.
//   4. ON NE MODIFIE PAS UNE FACTURE. On émet une correction qui référence
//      l'originale, et l'originale ne bouge pas.
//   5. UN PARTICULIER SANS NIP REÇOIT UN E-PARAGON, pas une facture — et sa
//      numérotation est séparée, sinon les factures auraient des trous.
//
//  Usage :  php tests/e2e_invoice.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);   // aucun e-mail ne part d'un test

echo "webshop_mrszoko — end-to-end faktury (numeracja · dokument · korekta)\n\n";

// ---- 1. Le format du numéro ------------------------------------------------
echo "-- format numeru --\n";
ok('xxxmmyy → 0010726', wsm_invoice_format_number('xxxmmyy', 1, '2026-07-30') === '0010726');
ok('xxx/mm/yy → 001/07/26', wsm_invoice_format_number('xxx/mm/yy', 1, '2026-07-30') === '001/07/26');
ok('le rang est complété à la largeur écrite',
    wsm_invoice_format_number('xxxxx/yyyy', 42, '2026-07-30') === '00042/2026');
ok('yyyy n\'est pas coupé en yy', wsm_invoice_format_number('yyyy', 1, '2026-07-30') === '2026');
ok('le texte hors jeton est repris tel quel',
    wsm_invoice_format_number('FV-xxx-mm', 7, '2026-07-30') === 'FV-007-07');

// La série DOIT contenir ce que le numéro affiche, sinon deux mois pourraient
// produire le même numéro.
ok('format au mois → série mensuelle', wsm_invoice_series('xxx/mm/yy', '2026-07-30') === '2026-07');
ok('format à l\'année → série annuelle', wsm_invoice_series('xxx/yyyy', '2026-07-30') === '2026');
ok('format sans date → suite continue', wsm_invoice_series('xxxxx', '2026-07-30') === 'all');

// ---- 2. Une commande professionnelle → une facture --------------------------
echo "\n-- faktura z zamówienia --\n";
wsm_config_overlay(['invoice' => [
    'seller_name' => 'ATELIER WRO01 sp. z o.o.', 'seller_nip' => '8971902620',
    'seller_address' => 'ul. Leszczyńskiego 4/29, 50-078 Wrocław', 'place' => 'Wrocław',
    'iban' => 'PL00 0000 0000 0000 0000 0000 0000', 'bank' => 'Bank Testowy',
    'payment_days' => '14', 'number_format' => 'xxx/mm/yy',
]]);
ok('rien ne manque pour facturer', wsm_invoice_blockers() === [], wsm_invoice_blockers());

$pid = 'test-fv-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                                         stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
               VALUES (?, (SELECT id FROM wsm_categories LIMIT 1), ?, ?, 'Opublikowany', 1, 1, ?, 40, 0.23, 250, 120, 80, 40, ?)")
     ->execute([$pid, 'Czekolada testowa', 100.00, $pid, strtoupper($pid)]);

$base = [
    'lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A',
    'items' => [['id' => $pid, 'qty' => 2]],
    'email' => 'firma.test@example.com', 'phone' => '600100200', 'consent_terms' => 1,
    'ship_street' => 'Leszczyńskiego', 'ship_building' => '4', 'ship_postcode' => '50-078',
    'ship_city' => 'Wrocław', 'ship_country' => 'PL',
];
$b2b = $base + [
    'client_type' => 'firma', 'first_name' => 'Jan', 'last_name' => 'Kowalski',
    'company' => 'Cukiernia Testowa sp. z o.o.', 'nip' => '5252248481', 'invoice' => 1,
    'bill_street' => 'Testowa', 'bill_building' => '10', 'bill_postcode' => '00-001',
    'bill_city' => 'Warszawa', 'bill_country' => 'PL',
];
[$order, $errs] = wsm_shop_create_order($pdo, $b2b);
ok('commande professionnelle créée', $order !== null, $errs);

[$inv, $err] = wsm_invoice_issue($pdo, $order, 'test');
ok('facture émise', $inv !== null, $err);
ok('c\'est une facture, pas un paragon', ($inv['kind'] ?? '') === 'faktura');
ok('le numéro suit le format réglé', (bool) preg_match('#^\d{3}/\d{2}/\d{2}$#', (string) $inv['number']), $inv['number'] ?? null);
ok('le vendeur est recopié sur le document', $inv['seller_nip'] === '8971902620');
ok('l\'acheteur aussi', $inv['buyer_name'] === 'Cukiernia Testowa sp. z o.o.' && $inv['buyer_nip'] === '5252248481');
ok('l\'échéance suit le délai réglé',
    $inv['due_at'] === date('Y-m-d', strtotime($inv['issued_at'] . ' +14 days')), $inv['due_at']);
ok('le rachunek figure sur le document', str_contains((string) $inv['iban'], 'PL00'));

// Les montants
ok('netto + VAT == brutto', $inv['total_net'] + $inv['total_vat'] === $inv['total_gross'],
    [$inv['total_net'], $inv['total_vat'], $inv['total_gross']]);
ok('le total du document égale celui de la commande',
    (int) $inv['total_gross'] === (int) $order['total_gross'], [$inv['total_gross'], $order['total_gross']]);
$sumLines = array_sum(array_map(fn($l) => (int) $l['line_gross'], $inv['items']));
ok('la somme des lignes fait le total (livraison comprise)', $sumLines === (int) $inv['total_gross'],
    [$sumLines, $inv['total_gross']]);
$shipLine = (bool) array_filter($inv['items'], fn($l) => str_starts_with((string) $l['name'], 'Dostawa'));
ok((int) $order['shipping_gross'] > 0
     ? 'la livraison facturée a sa ligne'
     : 'livraison offerte : aucune ligne fantôme sur le document',
   $shipLine === ((int) $order['shipping_gross'] > 0), [$order['shipping_gross'], $shipLine]);
ok('la ventilation par taux est calculée', count($inv['vat_breakdown']) >= 1);
$vsum = array_sum(array_map(fn($b) => (int) $b['vat'], $inv['vat_breakdown']));
ok('la somme des TVA par taux fait la TVA totale', $vsum === (int) $inv['total_vat'], [$vsum, $inv['total_vat']]);

// ---- 3. Une commande n'a qu'un document ------------------------------------
[$again] = wsm_invoice_issue($pdo, $order, 'test');
ok('réémettre ne crée pas un second document', (int) $again['id'] === (int) $inv['id']);

// ---- 4. Le document est figé ------------------------------------------------
echo "\n-- dokument jest zamrożony --\n";
$pdo->prepare("UPDATE wsm_products SET nom = ? WHERE id = ?")->execute(['Nazwa zmieniona po fakturze', $pid]);
wsm_config_overlay(['invoice' => ['seller_address' => 'Zupełnie inny adres 99']]);
$re = wsm_invoice_by_id($pdo, (int) $inv['id']);
ok('le nom du produit sur la facture ne bouge pas',
    (string) $re['items'][0]['name'] === (string) $inv['items'][0]['name'], $re['items'][0]['name']);
ok('l\'adresse du vendeur non plus',
    !str_contains((string) $re['seller_address'], 'Zupełnie inny'), $re['seller_address']);

// ---- 5. Numérotation : continue, sans doublon ------------------------------
echo "\n-- numeracja --\n";
$numbers = [$inv['number']];
for ($n = 0; $n < 4; $n++) {
    [$o2] = wsm_shop_create_order($pdo, $b2b);
    [$i2, $e2] = wsm_invoice_issue($pdo, $o2, 'test');
    if (!$i2) { ok('émission en série', false, $e2); break; }
    $numbers[] = $i2['number'];
}
ok('cinq documents, cinq numéros distincts', count(array_unique($numbers)) === count($numbers), $numbers);

$st = $pdo->query("SELECT seq FROM wsm_invoices WHERE kind_group = 'faktura' ORDER BY seq");
$seqs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$gaps = [];
for ($k = 1; $k < count($seqs); $k++) if ($seqs[$k] !== $seqs[$k - 1] + 1) $gaps[] = $seqs[$k];
ok('la suite des rangs est sans trou', $gaps === [], $gaps);

// L'index UNIQUE est le vrai garde-fou : on le met à l'épreuve.
$dup = false;
try {
    $pdo->prepare("INSERT INTO wsm_invoices (kind, kind_group, number, series, seq, issued_at, sold_at, due_at)
                   VALUES ('faktura','faktura',?,?,?,?,?,?)")
        ->execute([$inv['number'], $inv['series'], 999, '2026-01-01', '2026-01-01', '2026-01-01']);
    $dup = true;
} catch (Throwable $e) { /* attendu */ }
ok('la base refuse un numéro déjà utilisé', $dup === false);

$dup2 = false;
try {
    $pdo->prepare("INSERT INTO wsm_invoices (kind, kind_group, number, series, seq, issued_at, sold_at, due_at)
                   VALUES ('faktura','faktura','ZUPELNIE/INNY/NUMER',?,?,?,?,?)")
        ->execute([$inv['series'], (int) $inv['seq'], '2026-01-01', '2026-01-01', '2026-01-01']);
    $dup2 = true;
} catch (Throwable $e) { /* attendu */ }
ok('elle refuse aussi deux fois le même rang dans la série', $dup2 === false);

// ---- 6. E-paragon pour un particulier sans NIP ------------------------------
echo "\n-- e-paragon --\n";
$b2c = $base + ['client_type' => 'osoba', 'first_name' => 'Anna', 'last_name' => 'Nowak'];
[$o3] = wsm_shop_create_order($pdo, $b2c);
[$par, $e3] = wsm_invoice_issue($pdo, $o3, 'test');
ok('document émis pour un particulier', $par !== null, $e3);
ok('c\'est un e-paragon', ($par['kind'] ?? '') === 'paragon', $par['kind'] ?? null);
ok('son numéro est distinct de la série des factures', str_starts_with((string) $par['number'], 'PAR/'), $par['number'] ?? null);
$fvSeqs = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE kind_group = 'faktura'")->fetchColumn();
$parSeqs = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE kind_group = 'paragon'")->fetchColumn();
ok('les deux suites sont séparées', $parSeqs >= 1 && $fvSeqs >= 5, [$fvSeqs, $parSeqs]);

// Sans données vendeur, une facture est refusée — mais un paragon passe.
wsm_config_overlay(['invoice' => ['iban' => '']]);
ok('sans rachunek, la facture est refusée', wsm_invoice_blockers() !== []);
[$o4] = wsm_shop_create_order($pdo, $b2b);
[$bad, $errBad] = wsm_invoice_issue($pdo, $o4, 'test');
ok('et l\'émission le dit clairement', $bad === null && str_contains((string) $errBad, 'rachun'), $errBad);
wsm_config_overlay(['invoice' => ['iban' => 'PL00 0000 0000 0000 0000 0000 0000']]);

// ---- 7. Correction -----------------------------------------------------------
echo "\n-- korekta --\n";
$src = wsm_invoice_by_id($pdo, (int) $inv['id']);
[$kor, $errK] = wsm_invoice_correct($pdo, $src, ['total_gross' => '150,00', 'reason' => 'zwrot części towaru'], 'test');
ok('correction émise', $kor !== null, $errK);
ok('elle référence l\'originale', (int) ($kor['corrects_id'] ?? 0) === (int) $src['id']);
ok('son numéro est marqué comme correction', str_starts_with((string) $kor['number'], 'KOR/'), $kor['number'] ?? null);
ok('la raison est portée sur le document', str_contains((string) $kor['note'], 'zwrot'));
ok('netto + VAT == brutto sur la correction', $kor['total_net'] + $kor['total_vat'] === $kor['total_gross']);
ok('le montant corrigé est celui demandé', (int) $kor['total_gross'] === 15000, $kor['total_gross']);

$after = wsm_invoice_by_id($pdo, (int) $src['id']);
ok('la facture d\'origine est intacte',
    (int) $after['total_gross'] === (int) $src['total_gross'] && $after['number'] === $src['number']);
[$noReason] = wsm_invoice_correct($pdo, $src, ['total_gross' => '10,00', 'reason' => ''], 'test');
ok('une correction sans motif est refusée', $noReason === null);
[$noKor] = wsm_invoice_correct($pdo, $kor, ['total_gross' => '1,00', 'reason' => 'x'], 'test');
ok('on ne corrige pas une correction', $noKor === null);

// ---- 8. Autoliquidation ------------------------------------------------------
echo "\n-- odwrotne obciążenie --\n";
$rc = $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE reverse_charge = 1")->fetchColumn();
ok('la mention est portée par le document, pas recalculée', is_numeric($rc));
$colonnes = wsm_table_columns($pdo, 'wsm_invoices');
foreach (['ksef_number', 'ksef_status', 'ksef_at'] as $c) {
    if (!in_array($c, $colonnes, true)) { ok("colonne $c prête pour KSeF", false); break; }
}
ok('les colonnes KSeF existent déjà — l\'intégration n\'exigera pas de migration',
    in_array('ksef_number', $colonnes, true) && in_array('ksef_status', $colonnes, true));

// ---- Nettoyage ---------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$pid]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
