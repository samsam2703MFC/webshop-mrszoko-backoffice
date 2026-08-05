<?php
// ============================================================================
//  e2e_shop.php — preuve que la boutique encaisse juste et ne se laisse pas
//  dicter ses prix.
//
//  Ce qu'on cherche à démontrer, dans l'ordre d'importance :
//    1. un panier trafiqué ne change pas le montant facturé ;
//    2. net + TVA == brut, à la ligne comme au total ;
//    3. on ne vend pas ce qu'on n'a pas en stock ;
//    4. une notification de paiement non signée n'encaisse rien ;
//    5. une notification rejouée n'encaisse pas deux fois.
//
//  Usage :  php tests/e2e_shop.php [baseUrl] [adminToken]
// ============================================================================

$BASE  = rtrim($argv[1] ?? getenv('WSM_API_BASE') ?: 'http://localhost:8090', '/');
$TOKEN = $argv[2] ?? getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token';

$pass = 0; $fail = 0;
// La configuration n'est lue qu'une fois, au premier chargement de config.php.
// Ces deux variables doivent donc être posées AVANT le moindre require — sinon
// le code de sécurité tpay arrive trop tard et la signature ne se vérifie plus.
// (Le serveur de dev, lui, tourne dans un autre processus sans ces variables :
// le test « sans code configuré → 503 » reste donc valable.)
putenv('WSM_TPAY_SECURITY_CODE=e2e-secret-code');
putenv('WSM_TPAY_CLIENT_ID=');

function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}
function http(string $method, string $url, $body = null, ?string $token = null, bool $form = false): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : ($form ? http_build_query($body) : json_encode($body)),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($raw ?: 'null', true);
    return [$code, $j === null ? trim((string) $raw) : $j];
}
function badField(array $r, string $field): bool {
    return ($r[0] ?? 0) === 422 && isset($r[1]['fields'][$field]);
}

echo "webshop_mrszoko — end-to-end sklep (katalog · koszyk · zamówienie · tpay)\n";
echo "base: $BASE\n\n";

// ---- 1. Catalogue public ---------------------------------------------------
echo "-- katalog (publiczny, 3 języki) --\n";
[$c, $cat] = http('GET', "$BASE/shop/catalog");
ok('katalog dostępny bez logowania', $c === 200 && !empty($cat['products']), $c);
if ($c !== 200) { echo "\nAPI injoignable — lancez ./serve.sh\n"; exit(1); }

$products = $cat['products'];
ok('produkty mają cenę, wagę i stan', (bool) array_filter($products, fn($p) => $p['price'] > 0 && $p['weight_g'] > 0));
ok('ceny są w groszach (liczby całkowite)', (bool) array_reduce($products, fn($a, $p) => $a && is_int($p['price']), true));
foreach ($products as $p) {
    if ($p['price_net'] + $p['price_vat'] !== $p['price']) { ok('netto + VAT == brutto na produkcie', false, $p); break; }
}
ok('netto + VAT == brutto na każdym produkcie',
    (bool) array_reduce($products, fn($a, $p) => $a && ($p['price_net'] + $p['price_vat'] === $p['price']), true));

$langsSeen = [];
foreach (['pl', 'uk', 'en'] as $L) {
    [, $r] = http('GET', "$BASE/shop/catalog?lang=$L");
    $langsSeen[$L] = $r['strings']['cart.title'] ?? '';
}
ok('trzy języki dają trzy różne teksty', count(array_unique($langsSeen)) === 3, $langsSeen);
[, $bad] = http('GET', "$BASE/shop/catalog?lang=zz");
ok('nieznany język wraca do polskiego', ($bad['lang'] ?? '') === 'pl', $bad['lang'] ?? null);
ok('metody dostawy pochodzą z bazy', count($cat['shipping'] ?? []) >= 2, $cat['shipping'] ?? null);

// La page de marque et la boutique n'en font plus qu'une : le contenu
// éditorial doit vivre dans la même table, dans les trois langues.
foreach (['pl', 'uk', 'en'] as $L) {
    [, $r] = http('GET', "$BASE/shop/catalog?lang=$L");
    $S = $r['strings'] ?? [];
    $missing = [];
    foreach (['story.pro.title', 'story.pro.cta', 'story.strip.1', 'footer.email'] as $k) {
        if (($S[$k] ?? '') === '') $missing[] = $k;
    }
    ok("treść strony marki dostępna w « $L »", !$missing, $missing);
}
[, $rPl] = http('GET', "$BASE/shop/catalog?lang=pl");
[, $rEn] = http('GET', "$BASE/shop/catalog?lang=en");
ok('treść strony marki jest przetłumaczona, nie skopiowana',
    ($rPl['strings']['story.pro.title'] ?? 'x') !== ($rEn['strings']['story.pro.title'] ?? 'y'));
// La boutique existe : plus question d'annoncer qu'elle « ouvre bientôt ».
$proAll = strtolower(($rPl['strings']['story.pro.text'] ?? '') . ' ' . ($rEn['strings']['story.pro.text'] ?? ''));
ok('panel pro nie zapowiada już sklepu jako „wkrótce”',
    !str_contains($proAll, 'wkrótce') && !str_contains($proAll, 'opens soon') && !str_contains($proAll, 'rusza'),
    $proAll);

$prod = $products[0];
[$c2, $one] = http('GET', "$BASE/shop/product/" . rawurlencode($prod['slug']));
ok('produkt po slug', $c2 === 200 && ($one['product']['id'] ?? '') === $prod['id'], $c2);
[$c3] = http('GET', "$BASE/shop/product/nie-ma-takiego");
ok('nieznany produkt → 404', $c3 === 404, $c3);

// ---- 2. Le panier ne fixe pas les prix -------------------------------------
echo "-- wycena (serwer liczy, klient nie) --\n";
[, $q] = http('POST', "$BASE/shop/quote",
    ['items' => [['id' => $prod['id'], 'qty' => 2, 'price' => 1, 'line_gross' => 1]], 'delivery_method' => 'inpost_locker']);
ok('cena z żądania jest ignorowana', ($q['items_gross'] ?? 0) === $prod['price'] * 2, $q['items_gross'] ?? null);
ok('netto + VAT == brutto (pozycje)', ($q['items_net'] + $q['items_vat']) === $q['items_gross'], $q);
ok('netto + VAT == brutto (razem)', ($q['total_net'] + $q['total_vat']) === $q['total_gross'], $q);
ok('suma pozycji + dostawa == razem',
    ($q['items_gross'] + $q['shipping_gross']) === $q['total_gross'], $q);
ok('VAT rozbity na stawki', !empty($q['vat_breakdown']) && $q['vat_breakdown'][0]['rate'] > 0, $q['vat_breakdown'] ?? null);

$sameTwice = http('POST', "$BASE/shop/quote", ['items' => [['id' => $prod['id'], 'qty' => 1], ['id' => $prod['id'], 'qty' => 1]], 'delivery_method' => 'inpost_locker'])[1];
ok('ten sam produkt dwa razy = jedna pozycja ×2',
    count($sameTwice['lines']) === 1 && $sameTwice['lines'][0]['qty'] === 2, $sameTwice['lines'] ?? null);

[$cq] = http('POST', "$BASE/shop/quote", ['items' => [], 'delivery_method' => 'inpost_locker']);
ok('pusty koszyk → 422', $cq === 422, $cq);
[$cu] = http('POST', "$BASE/shop/quote", ['items' => [['id' => 'nie-istnieje', 'qty' => 1]]]);
ok('nieistniejący produkt → 422', $cu === 422, $cu);

// Franco de port : au-dessus du seuil, la livraison tombe à zéro.
$method = null;
foreach ($cat['shipping'] as $m) if ($m['id'] === 'inpost_locker') $method = $m;
$need = (int) ceil($method['free_from'] / $prod['price']);
[, $qFree] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $prod['id'], 'qty' => $need]], 'delivery_method' => 'inpost_locker']);
ok('powyżej progu dostawa jest darmowa',
    ($qFree['shipping_gross'] ?? -1) === 0 && !empty($qFree['shipping_free']), $qFree['shipping_gross'] ?? null);
[, $qPaid] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $prod['id'], 'qty' => 1]], 'delivery_method' => 'inpost_locker']);
ok('poniżej progu dostawa jest płatna', ($qPaid['shipping_gross'] ?? 0) > 0, $qPaid['shipping_gross'] ?? null);

// Gabarit : il grandit avec le volume, il disparaît quand rien ne convient.
$big = null;
foreach ($products as $p) if ($p['weight_g'] >= 3000) $big = $p;
if ($big) {
    // 8 sacs de 3 kg = 24,8 kg : sous la limite de poids, donc c'est bien le
    // VOLUME qui doit faire tomber le gabarit — pas un refus pour surcharge.
    [, $q1] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $big['id'], 'qty' => 1]]]);
    [, $q8] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $big['id'], 'qty' => 8]]]);
    ok('gabaryt liczony z objętości całego koszyka, nie z jednej sztuki',
        ($q1['parcel_template'] ?? '') !== '' && ($q8['parcel_template'] ?? 'x') === '',
        [$q1['parcel_template'] ?? null, $q8['parcel_template'] ?? null]);
    ok('przesyłka ponad 25 kg jest odrzucana',
        http('POST', "$BASE/shop/quote", ['items' => [['id' => $big['id'], 'qty' => 9]], 'delivery_method' => 'inpost_locker'])[0] === 422);
}

// ---- 3. Commande -----------------------------------------------------------
echo "-- zamówienie --\n";
$buyer = ['first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'e2e-' . bin2hex(random_bytes(4)) . '@example.pl',
          'phone' => '512340099', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'KRA010',
          'consent_terms' => true, 'items' => [['id' => $prod['id'], 'qty' => 2]]];

$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['email' => 'nie-mail']));
ok('błędny e-mail → 422', badField($r, 'email'), $r);
$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['phone' => '51234']));
ok('telefon spoza formatu → 422 (InPost)', badField($r, 'phone'), $r);
$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['inpost_point' => '']));
ok('paczkomat bez kodu → 422', badField($r, 'inpost_point'), $r);
$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['consent_terms' => false]));
ok('brak zgody na regulamin → 422', badField($r, 'consent_terms'), $r);
$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['delivery_method' => 'inpost_courier', 'inpost_point' => '']));
ok('kurier bez adresu → 422', badField($r, 'ship_street') && badField($r, 'ship_postcode'), $r);
$r = http('POST', "$BASE/shop/order", array_merge($buyer, ['invoice' => true, 'nip' => '5252248482', 'company' => 'X']));
ok('faktura z błędnym NIP → 422', badField($r, 'nip'), $r);

// On remet du stock avant de mesurer : à force de tourner, la suite finissait
// par commander un article épuisé — et un décrément plancherait à zéro, ce qui
// faisait échouer l'assertion sans qu'aucun code soit en cause.
(function () {
    require_once dirname(__DIR__) . '/db.php';
    $p = wsm_bootstrap();
    $p->exec("UPDATE wsm_products SET stock = 500 WHERE stock < 50 AND active = 1");
})();

$stockBefore = null;
foreach (http('GET', "$BASE/shop/catalog")[1]['products'] as $p) if ($p['id'] === $prod['id']) $stockBefore = $p['stock'];

[$co, $order] = http('POST', "$BASE/shop/order", $buyer);
ok('poprawne zamówienie → 201', $co === 201 && !empty($order['code']), [$co, $order]);
$code = $order['code'] ?? ''; $tok = $order['token'] ?? '';
ok('numer zamówienia w formacie MS-RRMMDD-NNNN', preg_match('/^MS-\d{6}-\d{4}$/', $code) === 1, $code);
// On compare à un devis de la MÊME quantité, jamais à une extrapolation.
// Multiplier le devis d'une unité par deux suppose que la livraison reste
// payante — or franchir le seuil de franco la rend gratuite, et l'assertion
// accusait alors la commande d'un écart que le client, lui, appelle un cadeau.
[, $qDeux] = http('POST', "$BASE/shop/quote",
                  ['items' => [['id' => $prod['id'], 'qty' => 2]], 'delivery_method' => 'inpost_locker']);
ok('kwota zamówienia == kwota z wyceny dla tej samej ilości',
    ($order['total_gross'] ?? 0) === (int) ($qDeux['total_gross'] ?? -1),
    [$order['total_gross'] ?? null, $qDeux['total_gross'] ?? null]);

$stockAfter = null;
foreach (http('GET', "$BASE/shop/catalog")[1]['products'] as $p) if ($p['id'] === $prod['id']) $stockAfter = $p['stock'];
ok('stan magazynowy zmniejszony o zamówioną ilość', $stockAfter === $stockBefore - 2, [$stockBefore, $stockAfter]);

[$cr, $read] = http('GET', "$BASE/shop/order/" . rawurlencode($code) . "?t=" . $tok);
ok('zamówienie czytelne z tokenem', $cr === 200 && ($read['code'] ?? '') === $code, $cr);
ok('token nie jest zwracany klientowi', !isset($read['access_token']), array_keys($read ?: []));
ok('przesyłka przygotowana przy zamówieniu', ($read['shipment']['target_point'] ?? '') === 'KRA010', $read['shipment'] ?? null);
ok('bez tokenu → 404', http('GET', "$BASE/shop/order/" . rawurlencode($code))[0] === 404);
ok('zły token → 404', http('GET', "$BASE/shop/order/" . rawurlencode($code) . '?t=' . str_repeat('0', 32))[0] === 404);

// Stock : on ne vend pas ce qu'on n'a pas.
$r = http('POST', "$BASE/shop/order", array_merge($buyer,
    ['email' => 'stock-' . bin2hex(random_bytes(3)) . '@example.pl', 'items' => [['id' => $prod['id'], 'qty' => 99]]]));
ok('zamówienie ponad stan → 422', badField($r, 'stock') || $r[0] === 422, $r);

// ---- 4. Paiement : fermé par défaut ----------------------------------------
echo "-- tpay --\n";
[$cw, $bw] = http('POST', "$BASE/shop/tpay/notify",
    ['id' => '1', 'tr_id' => 'TR-FAKE', 'tr_amount' => '1.00', 'tr_crc' => $code, 'tr_paid' => '1.00',
     'tr_status' => 'TRUE', 'md5sum' => str_repeat('a', 32)], null, true);
ok('bez skonfigurowanego kodu bezpieczeństwa notyfikacja jest odrzucana', $cw === 503, [$cw, $bw]);
[, $stillUnpaid] = http('GET', "$BASE/shop/order/" . rawurlencode($code) . "?t=" . $tok);
ok('zamówienie NIE zostało oznaczone jako opłacone', ($stillUnpaid['payment_status'] ?? '') !== 'oplacone',
    $stillUnpaid['payment_status'] ?? null);

// Signature + idempotence : testées en direct, avec un code de sécurité connu.
putenv('WSM_TPAY_SECURITY_CODE=e2e-secret-code');
putenv('WSM_TPAY_CLIENT_ID=');
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/tpay.php';
$pdo = wsm_bootstrap();

// Une requête SELECT laissée ouverte garde un verrou partagé SQLite et bloque
// le serveur HTTP au premier write suivant : on lit et on referme aussitôt.
$readOrder = function (string $code) use ($pdo): array {
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE code = ?");
    $st->execute([$code]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows[0] ?? [];
};
$row = $readOrder($code);
$amount = number_format(((int) $row['total_gross']) / 100, 2, '.', '');
$sign = fn(array $p) => md5(($p['id'] ?? '') . ($p['tr_id'] ?? '') . ($p['tr_amount'] ?? '') . ($p['tr_crc'] ?? '') . 'e2e-secret-code');

$note = ['id' => '77', 'tr_id' => 'TR-' . bin2hex(random_bytes(3)), 'tr_amount' => $amount,
         'tr_crc' => $code, 'tr_paid' => $amount, 'tr_status' => 'TRUE'];

$badSig = $note; $badSig['md5sum'] = str_repeat('b', 32);
[$b1] = wsm_tpay_notification($pdo, $badSig, '');
ok('zła sygnatura → odmowa', str_starts_with($b1, 'FALSE'), $b1);
ok('po złej sygnaturze zamówienie wciąż nieopłacone',
    $readOrder($code)['payment_status'] !== 'oplacone');

// Montant qui ne correspond pas : signature valable, encaissement refusé.
$wrongAmt = ['id' => '77', 'tr_id' => 'TR-' . bin2hex(random_bytes(3)), 'tr_amount' => '1.00',
             'tr_crc' => $code, 'tr_paid' => '1.00', 'tr_status' => 'TRUE'];
$wrongAmt['md5sum'] = $sign($wrongAmt);
[$b2] = wsm_tpay_notification($pdo, $wrongAmt, '');
ok('poprawna sygnatura, ale zła kwota → odmowa', str_starts_with($b2, 'FALSE'), $b2);
ok('częściowa wpłata nie zwalnia zamówienia',
    $readOrder($code)['payment_status'] !== 'oplacone');

$note['md5sum'] = $sign($note);
[$b3] = wsm_tpay_notification($pdo, $note, '');
ok('poprawna notyfikacja → TRUE', $b3 === 'TRUE', $b3);
$paidRow = $readOrder($code);
ok('zamówienie oznaczone jako opłacone',
    $paidRow['payment_status'] === 'oplacone' && $paidRow['paid_at'] !== null, $paidRow['payment_status']);

// La tentative non signée ne doit PAS avoir confisqué la clé d'idempotence :
// sinon connaître un tr_id suffirait à empêcher l'encaissement d'une commande.
ok('odrzucona notyfikacja nie blokuje tej prawdziwej', $paidRow['payment_status'] === 'oplacone');

$eventsBefore = (int) $pdo->query("SELECT COUNT(*) FROM wsm_order_events WHERE event = 'oplacone'")->fetchColumn();
[$b4] = wsm_tpay_notification($pdo, $note, '');
$eventsAfter = (int) $pdo->query("SELECT COUNT(*) FROM wsm_order_events WHERE event = 'oplacone'")->fetchColumn();
ok('powtórzona notyfikacja → TRUE (bez błędu)', $b4 === 'TRUE', $b4);
ok('powtórzona notyfikacja NIE księguje drugi raz', $eventsAfter === $eventsBefore, [$eventsBefore, $eventsAfter]);

// ---- 5. Cloisonnement ------------------------------------------------------
echo "-- protekcja --\n";
ok('lista zamówień bez tożsamości → 401', http('GET', "$BASE/franchisor/orders")[0] === 401);
ok('lista zamówień z tokenem serwisowym → 200', http('GET', "$BASE/franchisor/orders", null, $TOKEN)[0] === 200);
[, $list] = http('GET', "$BASE/franchisor/orders", null, $TOKEN);
ok('zamówienie widoczne w konsoli', (bool) array_filter($list ?: [], fn($o) => $o['code'] === $code));
ok('KPI sklepu dostępne dla konsoli', http('GET', "$BASE/franchisor/shop-kpis", null, $TOKEN)[0] === 200);
[, $scfg] = http('GET', "$BASE/franchisor/shop-config", null, $TOKEN);
ok('konfiguracja nie ujawnia sekretów',
    isset($scfg['tpay']['enabled']) && !isset($scfg['tpay']['client_secret']) && !isset($scfg['inpost']['token']),
    array_keys($scfg['tpay'] ?? []));

// Garde-fou anti-boucle sur la même adresse.
$spamMail = 'spam-' . bin2hex(random_bytes(4)) . '@example.pl';
$last = 0;
for ($i = 0; $i < 7; $i++) {
    $last = http('POST', "$BASE/shop/order", array_merge($buyer, ['email' => $spamMail, 'items' => [['id' => $prod['id'], 'qty' => 1]]]))[0];
    if ($last === 429) break;
}
ok('powtarzane zamówienia z tego samego adresu → 429', $last === 429, $last);

// ---- 5bis. Rabat au poids et rupture de stock -------------------------------
// Deux règles commerciales : le kilogramme baisse avec le volume, et une
// commande qui dépasse le stock passe quand même — on rappelle le client.
echo "-- rabat ilościowy i brak w magazynie --\n";
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
$pdoT = wsm_bootstrap();

$heavy = null;
foreach ($products as $p) if ($p['weight_g'] >= 3000) $heavy = $p;

if ($heavy) {
    // 1 sac de 3,1 kg franchit déjà le premier palier (3 kg).
    [$q1] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $heavy['id'], 'qty' => 1]]]) + [1 => null];
    [, $qa] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $heavy['id'], 'qty' => 1]]]);
    ok('premier palier atteint dès 3 kg', ($qa['discount_percent'] ?? 0) > 0, $qa['discount_percent'] ?? null);
    ok('le montant remisé est chiffré', ($qa['discount_amount'] ?? 0) > 0, $qa['discount_amount'] ?? null);
    ok('le prix payé est inférieur au prix plein',
        $qa['items_gross'] < $heavy['price'], [$qa['items_gross'], $heavy['price']]);
    ok('net + TVA == brut malgré la remise',
        ($qa['items_net'] + $qa['items_vat']) === $qa['items_gross'], $qa);

    [, $qb] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $heavy['id'], 'qty' => 4]]]);
    ok('le rabat augmente avec le poids (4 × 3,1 kg → palier supérieur)',
        ($qb['discount_percent'] ?? 0) > ($qa['discount_percent'] ?? 0),
        [$qa['discount_percent'] ?? null, $qb['discount_percent'] ?? null]);
    ok('les paliers ne se cumulent pas (un seul taux appliqué)',
        ($qb['discount_percent'] ?? 0) <= 100, $qb['discount_percent'] ?? null);
}

// Sous le premier palier : aucun rabat, mais on annonce le suivant.
$light = $products[0];
[, $qLow] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $light['id'], 'qty' => 1]]]);
ok('petit panier → pas de rabat', ($qLow['discount_percent'] ?? -1) == 0, $qLow['discount_percent'] ?? null);
ok('le palier suivant est annoncé au client',
    !empty($qLow['discount_next']['missing_g']), $qLow['discount_next'] ?? null);

// --- Rupture : la commande passe, elle n'est plus refusée -------------------
$st = $pdoT->prepare("SELECT stock FROM wsm_products WHERE id = ?");
$st->execute([$light['id']]);
$stockBefore = (int) $st->fetchColumn();
$st->closeCursor();

// On abaisse volontairement le stock : commander 118 sacs dépasserait la limite
// de poids d'InPost et testerait autre chose que la rupture.
$pdoT->prepare("UPDATE wsm_products SET stock = 2 WHERE id = ?")->execute([$light['id']]);
$over = 5;   // 2 en stock, 3 à produire
[, $qOver] = http('POST', "$BASE/shop/quote", ['items' => [['id' => $light['id'], 'qty' => $over]]]);
ok('commande au-delà du stock → devis accepté, pas d\'erreur', !empty($qOver['lines']), $qOver);
ok('le manque est chiffré ligne par ligne',
    ($qOver['lines'][0]['backorder'] ?? 0) === 3, $qOver['lines'][0]['backorder'] ?? null);
ok('le panier est marqué « à confirmer »', !empty($qOver['backorder']), $qOver['backorder'] ?? null);

$buyerOver = ['first_name' => 'Rupture', 'last_name' => 'Test',
    'email' => 'rupture-' . bin2hex(random_bytes(4)) . '@example.pl', 'phone' => '512340066',
    'delivery_method' => 'inpost_locker', 'inpost_point' => 'KRA010', 'consent_terms' => true,
    'items' => [['id' => $light['id'], 'qty' => $over]]];
[$co2, $ordOver] = http('POST', "$BASE/shop/order", $buyerOver);
ok('la commande hors stock est ACCEPTÉE (201), plus refusée', $co2 === 201, [$co2, $ordOver]);

$st = $pdoT->prepare("SELECT stock FROM wsm_products WHERE id = ?");
$st->execute([$light['id']]);
$stockAfter = (int) $st->fetchColumn();
$st->closeCursor();
ok('le stock tombe à zéro et jamais en négatif', $stockAfter === 0, [$stockBefore, $stockAfter]);

[, $readOver] = http('GET', "$BASE/shop/order/" . rawurlencode((string) $ordOver['code']) . '?t=' . $ordOver['token']);
ok('la commande porte la marque « à confirmer »', !empty($readOver['backorder']), $readOver['backorder'] ?? null);
ok('la ligne dit combien reste à produire',
    ($readOver['items'][0]['backorder'] ?? 0) === 3, $readOver['items'][0]['backorder'] ?? null);

[, $ordersList] = http('GET', "$BASE/franchisor/orders", null, $TOKEN);
$found = null;
foreach ($ordersList ?: [] as $o) if ($o['code'] === $ordOver['code']) $found = $o;
ok('la console voit tout de suite qui rappeler', !empty($found['backorder']), $found);

// On rend son stock au produit pour les tests suivants.
$pdoT->prepare("UPDATE wsm_products SET stock = ? WHERE id = ?")->execute([$stockBefore, $light['id']]);

// ---- 6. Photos produit -----------------------------------------------------
// Un fichier n'est pas une image parce qu'il s'appelle .jpg : le serveur le
// décode et le RÉ-ENCODE. Ce qui ressort est une image fabriquée par nous.
echo "-- zdjęcia produktów --\n";

// ---- Stawki VAT per produkt -------------------------------------------------
// Le taux vit sur le produit, pas dans le code : un chocolat à 23 % et une
// denrée à 5 % doivent être facturés chacun au sien, et le total doit rester
// exact au grosz près.
echo "\n-- stawka VAT per produkt --\n";
require_once dirname(__DIR__) . '/shop.php';
$pdoV = wsm_pdo();

ok('taux légaux polonais seulement', WSM_VAT_RATES === [0.23, 0.08, 0.05, 0.0], WSM_VAT_RATES);
[, $bad] = wsm_validate_product_shop($pdoV, ['vat_rate' => 0.17], 'x');
ok('un taux inventé est refusé', isset($bad['vat_rate']), $bad);
[$colsA] = wsm_validate_product_shop($pdoV, ['vat_rate' => '5'], 'x');
[$colsB] = wsm_validate_product_shop($pdoV, ['vat_rate' => '0,05'], 'x');
ok('« 5 » et « 0,05 » désignent le même taux',
    ($colsA['vat_rate'] ?? null) === 0.05 && ($colsB['vat_rate'] ?? null) === 0.05, [$colsA, $colsB]);
ok('affichage sans décimale inutile', wsm_vat_percent(0.23) === '23' && wsm_vat_percent(0.05) === '5');

// Un produit réel passé de 23 % à 5 % : le montant facturé doit suivre.
$vid = 'test-vat-' . bin2hex(random_bytes(3));
$pdoV->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                                          stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
                VALUES (?, (SELECT id FROM wsm_categories LIMIT 1), ?, ?, 'Opublikowany', 1, 1, ?, 50, 0.23, 250, 120, 80, 40, ?)")
     ->execute([$vid, 'Test VAT', 123.00, $vid, strtoupper($vid)]);

[$q23] = wsm_shop_quote($pdoV, [['id' => $vid, 'qty' => 1]], 'inpost_locker', 'pl');
ok('à 23 % : netto + VAT == brutto', $q23['items_net'] + $q23['items_vat'] === $q23['items_gross'], $q23['items_gross'] ?? null);
ok('à 23 % : 12300 gr → 10000 netto', $q23['items_net'] === 10000 && $q23['items_vat'] === 2300,
    [$q23['items_net'], $q23['items_vat']]);

$pdoV->prepare("UPDATE wsm_products SET vat_rate = 0.05 WHERE id = ?")->execute([$vid]);
[$q05] = wsm_shop_quote($pdoV, [['id' => $vid, 'qty' => 1]], 'inpost_locker', 'pl');
ok('le taux du produit est réellement appliqué', $q05['items_vat'] < $q23['items_vat'],
    [$q23['items_vat'], $q05['items_vat']]);
ok('à 5 % : netto + VAT == brutto', $q05['items_net'] + $q05['items_vat'] === $q05['items_gross']);
ok('le prix affiché ne bouge pas — c\'est la répartition qui change',
    $q05['items_gross'] === $q23['items_gross'], [$q23['items_gross'], $q05['items_gross']]);

// Deux taux dans le même panier : la ventilation doit les distinguer.
$other = $pdoV->query("SELECT id FROM wsm_products WHERE shop_visible = 1 AND vat_rate = 0.23 AND id <> " . $pdoV->quote($vid) . " LIMIT 1")->fetchColumn();
if ($other) {
    [$qm] = wsm_shop_quote($pdoV, [['id' => $vid, 'qty' => 1], ['id' => (string) $other, 'qty' => 1]], 'inpost_locker', 'pl');
    $rates = array_map(fn($b) => (float) $b['rate'], $qm['vat_breakdown'] ?? []);
    sort($rates);
    ok('deux taux dans un panier donnent deux lignes de TVA', count($rates) >= 2, $rates);
    ok('la somme des lignes de TVA fait le total', array_sum(array_map(fn($b) => (int) $b['vat'], $qm['vat_breakdown'])) === $qm['total_vat'],
        $qm['vat_breakdown'] ?? null);
}
$pdoV->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$vid]);


// ---- Magazyn : une vente laisse une trace ----------------------------------
// Un stock qui baisse sans mouvement est un stock qu'on ne saura pas
// expliquer. C'est le point de départ de tout inventaire qui ne tombe pas
// juste, et c'est invérifiable après coup.
echo "\n-- ruchy magazynowe --\n";
require_once dirname(__DIR__) . '/stock.php';

$sid = 'test-mag-' . bin2hex(random_bytes(3));
$pdoV->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                                          stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
                VALUES (?, (SELECT id FROM wsm_categories LIMIT 1), ?, ?, 'Opublikowany', 1, 1, ?, 3, 0.23, 200, 120, 80, 40, ?)")
     ->execute([$sid, 'Test magazynu', 50.00, $sid, strtoupper($sid)]);

$before = (int) $pdoV->query("SELECT stock FROM wsm_products WHERE id = " . $pdoV->quote($sid))->fetchColumn();
$cmd = [
    'lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A',
    'items' => [['id' => $sid, 'qty' => 2]],
    'client_type' => 'osoba', 'email' => 'mag.test@example.com', 'phone' => '600100200',
    'first_name' => 'Anna', 'last_name' => 'Nowak', 'consent_terms' => 1,
    'ship_street' => 'Leszczyńskiego', 'ship_building' => '4', 'ship_postcode' => '50-078',
    'ship_city' => 'Wrocław', 'ship_country' => 'PL',
];
[$ordM, $errM] = wsm_shop_create_order($pdoV, $cmd);
ok('commande passée', $ordM !== null, $errM);
$mv = wsm_stock_moves($pdoV, ['product_id' => $sid, 'limit' => 10]);
ok('la vente a écrit un mouvement', count($mv) >= 1, count($mv));
ok('le mouvement est une sortie de 2', (int) ($mv[0]['delta'] ?? 0) === -2, $mv[0]['delta'] ?? null);
ok('il porte le numéro de commande', ($mv[0]['doc'] ?? '') === $ordM['code'], $mv[0]['doc'] ?? null);
ok('il note le stock résultant', (int) ($mv[0]['stock_after'] ?? -1) === $before - 2, $mv[0]['stock_after'] ?? null);

// Une entrée fournisseur, puis une correction motivée.
$after = wsm_stock_apply($pdoV, $sid, 10, 'przyjecie', ['supplier' => 'Dostawca Test', 'unit_cost' => 1850]);
ok('une entrée augmente le stock', $after === $before - 2 + 10, $after);
$after = wsm_stock_apply($pdoV, $sid, -3, 'korekta', ['reason' => 'stłuczka']);
ok('une correction le diminue', $after === $before - 2 + 10 - 3, $after);
$after = wsm_stock_apply($pdoV, $sid, -9999, 'korekta', ['reason' => 'test']);
ok('le stock ne descend jamais sous zéro', $after === 0, $after);
$mv = wsm_stock_moves($pdoV, ['product_id' => $sid, 'limit' => 10]);
ok('chaque mouvement est daté et signé', ($mv[0]['created_at'] ?? '') !== '');
ok('la somme des mouvements égale le stock',
    array_sum(array_map(fn($m) => (int) $m['delta'], $mv)) + $before === 0,
    [array_sum(array_map(fn($m) => (int) $m['delta'], $mv)), $before]);

// La couverture, pas la quantité : c'est elle qui dit s'il faut commander.
$ovv = array_values(array_filter(wsm_stock_overview($pdoV), fn($r) => $r['id'] === $sid));
ok('le magasin voit ce produit', count($ovv) === 1);
ok('rupture signalée quand le stock est à zéro', ($ovv[0]['status'] ?? '') === 'brak', $ovv[0]['status'] ?? null);

// Le document : une livraison, plusieurs articles, tout ou rien.
[$pzDoc, $pzErr] = wsm_stock_receive($pdoV, ['partner' => 'Dostawca Test', 'ref' => 'FZ/1', 'actor' => 'test'], [
    ['product_id' => $sid, 'qty' => 4, 'unit_cost' => 1000],
    ['product_id' => '',   'qty' => 0],                      // ligne vide : ignorée
]);
ok('le bon de réception est enregistré', $pzDoc !== null, $pzErr);
ok('son numéro suit la série PZ', (bool) preg_match('#^PZ/\d{3}/\d{2}/\d{2}$#', (string) ($pzDoc['number'] ?? '')), $pzDoc['number'] ?? null);
ok('les lignes vides sont ignorées', count($pzDoc['lines'] ?? []) === 1, count($pzDoc['lines'] ?? []));
ok('le total du bon est juste', (int) ($pzDoc['units'] ?? 0) === 4 && (int) ($pzDoc['value'] ?? 0) === 4000,
    [$pzDoc['units'] ?? null, $pzDoc['value'] ?? null]);
ok('chaque mouvement cite son document',
    (int) (wsm_stock_moves($pdoV, ['product_id' => $sid, 'limit' => 1])[0]['doc_id'] ?? 0) === (int) $pzDoc['id']);
[$noLines, $errNo] = wsm_stock_receive($pdoV, ['partner' => 'X'], [['product_id' => '', 'qty' => 0]]);
ok('un bon sans ligne est refusé', $noLines === null && $errNo !== null, $errNo);
$stockNow = (int) $pdoV->query("SELECT stock FROM wsm_products WHERE id = " . $pdoV->quote($sid))->fetchColumn();
ok('la réception a bien crédité le stock', $stockNow === 4, $stockNow);

// Le bon de sortie : il nomme, il ne rebouge rien.
$avantWZ = (int) $pdoV->query("SELECT stock FROM wsm_products WHERE id = " . $pdoV->quote($sid))->fetchColumn();
[$wz, $wzErr] = wsm_stock_issue_wz($pdoV, $ordM, 'test');
ok('le bon de livraison est émis', $wz !== null, $wzErr);
ok('son numéro suit la série WZ', str_starts_with((string) ($wz['number'] ?? ''), 'WZ/'), $wz['number'] ?? null);
ok('il ne touche pas au stock — la marchandise est déjà sortie à la commande',
    (int) $pdoV->query("SELECT stock FROM wsm_products WHERE id = " . $pdoV->quote($sid))->fetchColumn() === $avantWZ);
ok('il rattache les sorties de la commande', count($wz['lines'] ?? []) >= 1, count($wz['lines'] ?? []));
[$wz2] = wsm_stock_issue_wz($pdoV, $ordM, 'test');
ok('réémettre renvoie le même bon, pas un second', (int) ($wz2['id'] ?? 0) === (int) $wz['id']);

$pdoV->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$sid]);

require_once dirname(__DIR__) . '/media.php';

$tmpDir = sys_get_temp_dir();
$png = $tmpDir . '/wsm-test.png';
$im = imagecreatetruecolor(2000, 1200);                 // volontairement trop grande
imagefill($im, 0, 0, imagecolorallocate($im, 65, 40, 26));
imagepng($im, $png);
imagedestroy($im);

$fake = $tmpDir . '/wsm-fake.jpg';                       // du texte déguisé en .jpg
file_put_contents($fake, "<?php echo 'nie jestem obrazem'; ?>");

function upload(string $url, string $id, string $file, ?string $token): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => ['id' => $id, 'photo' => new CURLFile($file)],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw ?: 'null', true)];
}

$PHOTO = "$BASE/franchisor/product-photo";
ok('wgranie zdjęcia bez tożsamości → 401', upload($PHOTO, $prod['id'], $png, null)[0] === 401);

$r = upload($PHOTO, $prod['id'], $fake, $TOKEN);
ok('plik, który nie jest obrazem → 422', badField($r, 'photo'), $r);

$r = upload($PHOTO, 'nie-ma-takiego', $png, $TOKEN);
ok('nieznany produkt → 404', $r[0] === 404, $r[0]);

$r = upload($PHOTO, $prod['id'], $png, $TOKEN);
ok('poprawne zdjęcie → 200', $r[0] === 200 && !empty($r[1]['image_url']), $r);
$mediaUrl = $r[1]['image_url'] ?? '';
ok('nazwa pliku jest losowa, nie pochodzi z wgrania',
    preg_match('#^media/[a-f0-9]{24}\.(webp|jpg)$#', $mediaUrl) === 1, $mediaUrl);

$onDisk = dirname(__DIR__, 3) . '/shop/' . $mediaUrl;
ok('plik istnieje na dysku', is_file($onDisk), $onDisk);
$dim = @getimagesize($onDisk);
ok('obraz został zmniejszony do 1400 px', $dim && max($dim[0], $dim[1]) <= 1400, $dim ? [$dim[0], $dim[1]] : null);
ok('obraz został przekodowany (nie jest już PNG)', $dim && $dim[2] !== IMAGETYPE_PNG, $dim[2] ?? null);
ok('plik wyjściowy jest lżejszy od wgranego', filesize($onDisk) < filesize($png),
    [filesize($png), filesize($onDisk)]);

[, $catNow] = http('GET', "$BASE/shop/catalog");
$withImg = null;
foreach ($catNow['products'] as $p) if ($p['id'] === $prod['id']) $withImg = $p;
ok('sklep podaje zdjęcie produktu', ($withImg['image'] ?? '') === $mediaUrl, $withImg['image'] ?? null);

// Remplacer la photo doit effacer l'ancienne : un dossier media/ qui ne fait
// que grossir finit par remplir le disque du serveur.
$r2 = upload($PHOTO, $prod['id'], $png, $TOKEN);
ok('podmiana zdjęcia → 200', $r2[0] === 200);
ok('stare zdjęcie zostało usunięte z dysku', !is_file($onDisk), $onDisk);

// Champs vitrine par l'API.
$r = http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'image_url' => 'http://exemple.pl/x.jpg'], $TOKEN);
ok('adres http (nie https) → 422', badField($r, 'image_url'), $r);
$r = http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'image_url' => 'media/../../api/config.local.php'], $TOKEN);
ok('próba wyjścia poza media/ → 422', badField($r, 'image_url'), $r);
$r = http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'stock' => -5], $TOKEN);
ok('ujemny stan → 422', badField($r, 'stock'), $r);

$other = null;
foreach ($products as $p) if ($p['id'] !== $prod['id']) $other = $p;
$r = http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'slug' => $other['slug']], $TOKEN);
ok('slug zajęty przez inny produkt → 422', badField($r, 'slug'), $r);

$r = http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'slug' => 'Czekolada Testowa 70%!!'], $TOKEN);
ok('slug jest normalizowany, nie odrzucany', $r[0] === 200, $r);
[, $c3] = http('GET', "$BASE/shop/catalog");
$slugged = null;
foreach ($c3['products'] as $p) if ($p['id'] === $prod['id']) $slugged = $p;
ok('slug zapisany jako czekolada-testowa-70', ($slugged['slug'] ?? '') === 'czekolada-testowa-70', $slugged['slug'] ?? null);
ok('produkt osiągalny pod nowym slugiem',
    http('GET', "$BASE/shop/product/" . rawurlencode((string) $slugged['slug']))[0] === 200);

// nettoyage : on ne laisse pas traîner le média du test
$last = $r2[1]['image_url'] ?? '';
if ($last !== '') { wsm_media_delete($last); }
http('POST', "$BASE/franchisor/product", ['id' => $prod['id'], 'image_url' => '', 'slug' => $prod['slug']], $TOKEN);
@unlink($png); @unlink($fake);


echo "\n" . ($fail === 0 ? "ALL GREEN: $pass passed, 0 failed" : "FAILURES: $pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
