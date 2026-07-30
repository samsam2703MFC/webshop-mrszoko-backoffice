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
    foreach (['story.formats.title', 'story.atelier.title', 'story.pro.title',
              'story.pro.cta', 'story.strip.1', 'footer.email'] as $k) {
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

$stockBefore = null;
foreach (http('GET', "$BASE/shop/catalog")[1]['products'] as $p) if ($p['id'] === $prod['id']) $stockBefore = $p['stock'];

[$co, $order] = http('POST', "$BASE/shop/order", $buyer);
ok('poprawne zamówienie → 201', $co === 201 && !empty($order['code']), [$co, $order]);
$code = $order['code'] ?? ''; $tok = $order['token'] ?? '';
ok('numer zamówienia w formacie MS-RRMMDD-NNNN', preg_match('/^MS-\d{6}-\d{4}$/', $code) === 1, $code);
ok('kwota zamówienia == kwota z wyceny', ($order['total_gross'] ?? 0) === ($qPaid['items_gross'] * 2 + $qPaid['shipping_gross']),
    [$order['total_gross'] ?? null, $qPaid['items_gross'] * 2 + $qPaid['shipping_gross']]);

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

// ---- 6. Photos produit -----------------------------------------------------
// Un fichier n'est pas une image parce qu'il s'appelle .jpg : le serveur le
// décode et le RÉ-ENCODE. Ce qui ressort est une image fabriquée par nous.
echo "-- zdjęcia produktów --\n";
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
