<?php
// ============================================================================
//  e2e_dokument.php — facture ou e-paragon, et QUI décide.
//
//  CE CHOIX PART AU FISC. Il décide de ce qui est déposé au registre national,
//  de ce que le client reçoit, et de qui doit la TVA. Trois façons de le
//  rater, et aucune ne provoque d'erreur visible :
//
//   1. FACTURER UN NUMÉRO REFUSÉ PAR VIES. Le client dit être une entreprise
//      européenne, VIES dit non, et on émet quand même une facture en
//      autoliquidation : la TVA n'est réclamée à personne, et c'est le vendeur
//      qui la paiera au contrôle. La ligne d'origine — « facture si la case
//      est cochée et qu'un NIP est saisi » — faisait exactement ça.
//   2. REFUSER UNE FACTURE DUE. Une société polonaise avec son NIP y a droit ;
//      VIES ne sert à rien pour une vente domestique. Lui rendre un e-paragon
//      « parce qu'aucun appel VIES n'a eu lieu » est un document manquant, et
//      le client le réclamera — en ayant raison.
//   3. ÉMETTRE DEUX FOIS. Le document est numéroté dans une série continue et
//      déposé au registre : un doublon ne se retire pas, il se corrige par un
//      avoir. L'émission doit donc survivre à quatre appels.
//
//  Et la règle de fond : ON NE RELIT JAMAIS VIES AU MOMENT DU DOCUMENT. Le
//  statut est FIGÉ sur la commande à la vente. Un numéro valable en mars et
//  révoqué en juin ne doit pas changer le document de mars.
//
//  Usage :  php tests/e2e_dokument.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/commerce.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);

// LE VENDEUR DOIT ÊTRE RENSEIGNÉ, sinon AUCUNE facture ne s'émet — et ce
// n'est pas un détail de test : en production, tant que le numéro de compte
// manque, une commande passée à « wysłane » repart avec « dokument nie
// powstał ». C'est le blocage réel, et la suite le montre plus bas.
wsm_config_overlay(['invoice' => [
    'seller_name'    => 'ATELIER WRO01 sp. z o.o.',
    'seller_nip'     => '8971902620',
    'seller_address' => 'ul. Polna 1, 00-002 Wrocław, PL',
    'iban'           => 'PL61 1090 1014 0000 0712 1981 2874',
]]);

echo "webshop_mrszoko — end-to-end dokument zamówienia (faktura albo paragon)\n\n";

// Un NIP polonais réellement valide (clé de contrôle juste) et un faux.
$NIP_OK  = '5252248481';
$NIP_KO  = '5252248480';
ok('le NIP de référence est bien valide', wsm_valid_nip($NIP_OK));
ok('et le faux est bien refusé', !wsm_valid_nip($NIP_KO));

// ---- 1. La règle, cas par cas ---------------------------------------------
echo "\n-- kto dostaje fakturę, a kto paragon --\n";
$cas = [
    ['VIES a confirmé le numéro',
     ['vat_status' => 'valid', 'vat_eu' => 'DE811569869', 'invoice' => 1], 'faktura'],
    // LE cas qui coûte de l'argent : VIES a dit non.
    ['VIES a REFUSÉ le numéro — repli sur le paragon',
     ['vat_status' => 'invalid', 'vat_eu' => 'DE000000000', 'nip' => $NIP_OK, 'invoice' => 1], 'paragon'],
    ['société polonaise avec un NIP valide',
     ['vat_status' => '', 'nip' => $NIP_OK, 'invoice' => 1], 'faktura'],
    ['NIP polonais invalide — repli',
     ['vat_status' => '', 'nip' => $NIP_KO, 'invoice' => 1], 'paragon'],
    ['facture demandée sans aucun numéro',
     ['vat_status' => '', 'invoice' => 1], 'paragon'],
    ['particulier qui ne demande rien',
     ['vat_status' => '', 'invoice' => 0], 'paragon'],
    // VIES injoignable : on ne devine pas, on retombe sur le NIP.
    ['VIES injoignable, NIP valide — la vente domestique reste facturable',
     ['vat_status' => 'unavailable', 'nip' => $NIP_OK, 'invoice' => 1], 'faktura'],
    // Statut « valid » mais AUCUN numéro écrit : rien à mettre sur le document.
    ['statut « valid » sans numéro écrit ne fabrique pas de facture',
     ['vat_status' => 'valid', 'vat_eu' => '', 'invoice' => 0], 'paragon'],
];
foreach ($cas as [$quoi, $cmd, $attendu]) {
    $r = wsm_invoice_kind_for($cmd);
    ok("$quoi → $attendu", $r['kind'] === $attendu, $r);
    ok("… et la raison est écrite", trim($r['raison']) !== '');
    // LA MÊME COMMANDE, SOUS SA FORME HYDRATÉE. wsm_order_by_id() range le
    // statut VIES sous « vat.status » et non « vat_status » : une règle qui ne
    // lit que la seconde forme marche dans ce test et rate en production.
    // C'est exactement ce qui est arrivé — un numéro refusé par VIES repassait
    // sur la règle du NIP, donc sur une facture.
    $hydrate = $cmd;
    unset($hydrate['vat_status']);
    $hydrate['vat'] = ['status' => $cmd['vat_status'] ?? ''];
    ok("… et pareil sur la commande hydratée", wsm_invoice_kind_for($hydrate)['kind'] === $attendu,
       wsm_invoice_kind_for($hydrate));
}

// ---- 2. La même règle vue par l'émission ----------------------------------
echo "\n-- ta sama zasada, ale przez wystawienie --\n";
$sfx = 'test-dok-' . bin2hex(random_bytes(3));
$mk = function (array $extra) use ($pdo, $sfx): array {
    static $n = 0; $n++;
    $code = strtoupper($sfx) . '-' . $n;
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, status, paid_at, email, phone,
                     first_name, last_name, company, nip, vat_eu, vat_status, invoice,
                     delivery_method, items_net, items_vat, total_net, total_vat, total_gross,
                     shipping_net, shipping_vat, currency, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$code, bin2hex(random_bytes(8)), 'oplacone', date('Y-m-d H:i:s'),
                   'dok@example.com', '600100200', 'Jan', 'Kowalski',
                   $extra['company'] ?? '', $extra['nip'] ?? '', $extra['vat_eu'] ?? '',
                   $extra['vat_status'] ?? '', $extra['invoice'] ?? 0,
                   'inpost_courier', 10000, 2300, 10000, 2300, 12300, 0, 0, 'PLN',
                   date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wsm_order_items (order_id, product_id, name, qty, unit_net,
                     unit_gross, vat_rate, line_net, line_vat, line_gross)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, 'test-dok', 'Czekolada', 1, 10000, 12300, 0.23, 10000, 2300, 12300]);
    return wsm_order_by_id($pdo, $id);
};

$o1 = $mk(['company' => 'Kowalski sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
$d1 = wsm_order_document($pdo, $o1, 'test');
ok('une société polonaise reçoit une FACTURE', ($d1['kind'] ?? '') === 'faktura', $d1['raison'] ?? '');
ok('le document porte un numéro', ($d1['doc']['number'] ?? '') !== '');

$o2 = $mk(['invoice' => 0]);
$d2 = wsm_order_document($pdo, $o2, 'test');
ok('un particulier reçoit un PARAGON', ($d2['kind'] ?? '') === 'paragon', $d2['raison'] ?? '');

$o3 = $mk(['vat_eu' => 'DE000000000', 'vat_status' => 'invalid', 'nip' => $NIP_OK, 'invoice' => 1]);
$d3 = wsm_order_document($pdo, $o3, 'test');
ok('un numéro refusé par VIES retombe sur le PARAGON',
   ($d3['kind'] ?? '') === 'paragon', $d3['raison'] ?? '');

// ---- 3. Deux fois, c'est une fois -----------------------------------------
echo "\n-- dwa razy to jeden raz --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices")->fetchColumn();
for ($i = 0; $i < 3; $i++) wsm_order_document($pdo, $o1, 'test');
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices")->fetchColumn();
ok('trois appels de plus n\'émettent RIEN de nouveau', $apres === $avant, [$avant, $apres]);
ok('et c\'est toujours le même numéro',
   (wsm_order_document($pdo, $o1, 'test')['doc']['number'] ?? '') === ($d1['doc']['number'] ?? ''));

// Le courrier non plus ne part pas deux fois : la clé d'événement porte le
// numéro du document.
$mails = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages
                            WHERE event_key = " . $pdo->quote('dok:' . ($d1['doc']['number'] ?? '')))->fetchColumn();
ok('un seul courrier pour ce document, malgré quatre appels', $mails === 1, $mails);

// ---- 4. Le passage à « wysłane » déclenche tout ----------------------------
echo "\n-- przejście na « wysłane » wystawia dokument --\n";
$o4 = $mk(['company' => 'Nowak sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
ok('avant l\'expédition, aucun document', wsm_invoice_for_order($pdo, (int) $o4['id']) === null);
$chg = wsm_order_status_set($pdo, (int) $o4['id'], 'wyslane', 'test');
ok('le changement d\'état réussit', $chg['ok'] === true, $chg);
ok('et il a émis le document', ($chg['doc']['number'] ?? '') !== '', $chg['note']);
ok('la note dit ce qui a été fait', str_contains($chg['note'], 'FAKTURA'), $chg['note']);
ok('le document est bien rattaché à la commande',
   (wsm_invoice_for_order($pdo, (int) $o4['id'])['number'] ?? '') === ($chg['doc']['number'] ?? ''));

// Repasser au même état ne réémet rien, et un second passage non plus.
$c2 = wsm_order_status_set($pdo, (int) $o4['id'], 'wyslane', 'test');
ok('repasser à « wysłane » n\'émet pas un second document',
   ($c2['doc']['number'] ?? '') === ($chg['doc']['number'] ?? '') || $c2['doc'] === null, $c2['note']);

// Un état qui n'est pas « wysłane » n'émet rien.
$o5 = $mk(['invoice' => 1, 'nip' => $NIP_OK]);
wsm_order_status_set($pdo, (int) $o5['id'], 'w_realizacji', 'test');
ok('« w realizacji » n\'émet aucun document',
   wsm_invoice_for_order($pdo, (int) $o5['id']) === null);

// ---- 5. Ce que l'état protège ---------------------------------------------
echo "\n-- czego stan pilnuje --\n";
// Une commande annulée ne repart pas — la règle que les deux transporteurs
// portaient chacun dans leur coin, et qui vit maintenant à un seul endroit.
$o6 = $mk(['invoice' => 0]);
wsm_order_status_set($pdo, (int) $o6['id'], 'anulowane', 'test');
$c6 = wsm_order_status_set($pdo, (int) $o6['id'], 'wyslane', 'test');
ok('une commande annulée ne passe pas à « wysłane »', $c6['ok'] === false, $c6);
ok('et rien n\'a été émis pour elle', wsm_invoice_for_order($pdo, (int) $o6['id']) === null);
$st = $pdo->prepare("SELECT status FROM wsm_orders WHERE id = ?");
$st->execute([(int) $o6['id']]);
ok('son état n\'a pas bougé', $st->fetchColumn() === 'anulowane');
// Une commande inexistante ne fabrique rien.
ok('un identifiant inconnu ne fait rien', wsm_order_status_set($pdo, 999999999, 'wyslane')['ok'] === false);

// ---- 6. VIES est RECONSULTÉ avant d'émettre --------------------------------
echo "\n-- VIES sprawdzany ponownie tuż przed wystawieniem --\n";
// LE CAS QUI COÛTE. Un numéro valable à la commande, révoqué avant
// l'expédition : facturer en autoliquidation laisse la TVA à la charge du
// vendeur. Le contrôle doit donc dater de la LIVRAISON, pas de la commande.
require_once dirname(__DIR__) . '/vies.php';
$o7 = $mk(['company' => 'Müller GmbH', 'vat_eu' => 'DE811569869',
           'vat_status' => 'valid', 'invoice' => 1]);
ok('à la commande, le numéro est marqué valide',
   strtolower((string) ($o7['vat']['status'] ?? '')) === 'valid', $o7['vat'] ?? []);

// VIES répond « révoqué » au moment de l'expédition.
wsm_vies_transport(fn(string $c, string $n) => [200, ['isValid' => false,
    'name' => '', 'address' => '', 'requestIdentifier' => 'WAPIAAAAX0000']]);
$chg7 = wsm_order_status_set($pdo, (int) $o7['id'], 'wyslane', 'test');
$apres7 = wsm_order_by_id($pdo, (int) $o7['id']);
ok('le statut de la commande a été RÉÉCRIT par la consultation du jour',
   strtolower((string) ($apres7['vat']['status'] ?? '')) === 'invalid', $apres7['vat'] ?? []);
ok('et le document émis est un PARAGON, pas une facture',
   (wsm_invoice_for_order($pdo, (int) $o7['id'])['kind'] ?? '') === 'paragon', $chg7['note']);
ok('la date de contrôle est celle du jour',
   str_starts_with((string) ($apres7['vat']['checked_at'] ?? ''), date('Y-m-d')),
   $apres7['vat']['checked_at'] ?? '');
ok('le numéro de consultation est gardé — c\'est LUI la preuve en contrôle',
   trim((string) ($apres7['vat']['consultation'] ?? '')) !== '', $apres7['vat'] ?? []);

// Une panne de VIES ne doit RIEN effacer ni bloquer l'expédition.
$o8 = $mk(['company' => 'Müller GmbH', 'vat_eu' => 'DE811569869',
           'vat_status' => 'valid', 'invoice' => 1]);
wsm_vies_transport(fn(string $c, string $n) => [503, null]);
$chg8 = wsm_order_status_set($pdo, (int) $o8['id'], 'wyslane', 'test');
$apres8 = wsm_order_by_id($pdo, (int) $o8['id']);
ok('VIES en panne : l\'expédition passe quand même', $chg8['ok'] === true, $chg8);
ok('et le statut d\'avant n\'est PAS effacé',
   strtolower((string) ($apres8['vat']['status'] ?? '')) === 'valid', $apres8['vat'] ?? []);
ok('le document reste une facture', (wsm_invoice_for_order($pdo, (int) $o8['id'])['kind'] ?? '') === 'faktura',
   $chg8['note']);

// Une commande sans numéro UE ne déclenche aucune consultation.
$appels = 0;
wsm_vies_transport(function (string $c, string $n) use (&$appels) { $appels++; return [503, null]; });
$o9 = $mk(['invoice' => 0]);
wsm_order_status_set($pdo, (int) $o9['id'], 'wyslane', 'test');
ok('sans numéro UE, VIES n\'est pas dérangé', $appels === 0, $appels);

// ---- 7. Un e-paragon ne va JAMAIS au registre national ---------------------
echo "\n-- paragon nie idzie do KSeF --\n";
// Déposer un e-paragon inscrirait au registre un document qui n'existe pas
// pour le fisc. On ne s'en remet pas au refus de KSeF : on n'y va pas.
$d2b = wsm_order_document($pdo, $o2, 'test');
ok('aucune tentative KSeF pour un paragon', ($d2b['ksef'] ?? '') === '', $d2b['ksef'] ?? '');
$d1b = wsm_order_document($pdo, $o1, 'test');
ok('pour une facture, le canal est nommé (ouvert ou fermé)',
   ($d1b['ksef'] ?? '') !== '', $d1b['ksef'] ?? '');

// ---- ménage ---------------------------------------------------------------
$ids = $pdo->query("SELECT id FROM wsm_orders WHERE code LIKE '" . strtoupper($sfx) . "%'")
           ->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($ids as $i) {
    $pdo->prepare("DELETE FROM wsm_invoice_items WHERE invoice_id IN
                   (SELECT id FROM wsm_invoices WHERE order_id = ?)")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_invoices WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_messages WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_order_items WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([(int) $i]);
}

echo "\n" . ($fail === 0 ? "OK" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
