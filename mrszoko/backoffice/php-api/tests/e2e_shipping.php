<?php
// ============================================================================
//  e2e_shipping.php — la file d'expédition.
//
//  CE QU'ELLE EXISTE POUR EMPÊCHER : une commande payée dont le colis n'est
//  jamais créé. Elle ne fait AUCUN bruit — elle est payée, elle attend, et
//  personne ne la cherche. Un numéro de téléphone manquant suffit.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. ON N'EXPÉDIE PAS UNE COMMANDE IMPAYÉE. Un colis pour une commande
//      non réglée, c'est de la marchandise donnée.
//   2. ON NE CRÉE JAMAIS DEUX FOIS. Deux colis, c'est deux fois le port et
//      un retour à gérer.
//   3. CE QUI BLOQUE EST NOMMÉ EN POLONAIS, avec la raison. « telefon » ne
//      dit rien ; « Brak telefonu — kurier nie ma jak zadzwonić » fait
//      décrocher le téléphone.
//   4. UN ÉCHEC N'ARRÊTE PAS LES AUTRES.
//   5. SANS JETON INPOST, L'ÉCRAN RESTE LISIBLE : c'est un état d'attente
//      annoncé, pas une erreur silencieuse.
//
//  Usage :  php tests/e2e_shipping.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/inpost.php';
require_once dirname(__DIR__) . '/shipping.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);

echo "webshop_mrszoko — end-to-end kolejka wysyłki\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_shipments WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-sh-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-sh-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-sh-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-sh-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,90.00,'Opublikowany',1,1,?,99,0.23,300,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Ship ' . $sfx, $pid, strtoupper($sfx)]);

$base = [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'phone' => '600100200', 'first_name' => 'Jan', 'last_name' => 'Kowalski',
    'client_type' => 'osoba', 'ship_street' => 'Testowa', 'ship_building' => '1',
    'ship_postcode' => '00-001', 'ship_city' => 'Warszawa', 'ship_country' => 'PL',
    'consent_terms' => true,
];
$mk = function (string $qui) use ($pdo, $base, $sfx): array {
    [$o, $e] = wsm_shop_create_order($pdo, $base + ['email' => "$qui.$sfx@example.com"]);
    return [$o, $e];
};

// ---- 1. Seul l'encaissé entre dans la file ---------------------------------------------
echo "-- w kolejce tylko zapłacone --\n";
[$impaye, $e1] = $mk('niezaplacil');
ok('la commande impayée est créée', $impaye !== null, $e1);
[$paye, $e2] = $mk('zaplacil');
ok('la commande payée aussi', $paye !== null, $e2);
wsm_order_mark_paid($pdo, (int) $paye['id'], 'test');

$file = wsm_ship_queue($pdo, 500);
$idsFile = array_map(fn($x) => (int) $x['order']['id'], $file);
ok('la commande PAYÉE est dans la file', in_array((int) $paye['id'], $idsFile, true), count($file));
ok('la commande IMPAYÉE n\'y est PAS — un colis non réglé, c\'est du don',
   !in_array((int) $impaye['id'], $idsFile, true));

// Une commande annulée non plus.
$pdo->prepare("UPDATE wsm_orders SET status = 'anulowane' WHERE id = ?")->execute([(int) $paye['id']]);
$ids2 = array_map(fn($x) => (int) $x['order']['id'], wsm_ship_queue($pdo, 500));
ok('une commande annulée sort de la file', !in_array((int) $paye['id'], $ids2, true));
$pdo->prepare("UPDATE wsm_orders SET status = 'oplacone' WHERE id = ?")->execute([(int) $paye['id']]);

// ---- 2. Ce qui bloque est nommé, en polonais -------------------------------------------
echo "\n-- to, co blokuje, jest nazwane --\n";
$vu = null;
foreach (wsm_ship_queue($pdo, 500) as $x) if ((int) $x['order']['id'] === (int) $paye['id']) $vu = $x;
ok('la commande complète est PRÊTE', $vu !== null && $vu['pret'] === true, $vu['blockers'] ?? null);

// On lui retire le téléphone : elle doit basculer en « bloquée », avec le motif.
$pdo->prepare("UPDATE wsm_orders SET phone = '' WHERE id = ?")->execute([(int) $paye['id']]);
$vu = null;
foreach (wsm_ship_queue($pdo, 500) as $x) if ((int) $x['order']['id'] === (int) $paye['id']) $vu = $x;
ok('sans téléphone, elle est bloquée', $vu !== null && $vu['pret'] === false, $vu['blockers'] ?? null);
ok('et le motif est « telefon »', in_array('telefon', $vu['blockers'] ?? [], true), $vu['blockers'] ?? null);
$phrase = wsm_ship_blocker_label('telefon');
ok('traduit en phrase utilisable', str_contains($phrase, 'telefon') && mb_strlen($phrase) > 20, $phrase);
ok('et elle dit POURQUOI ça compte', str_contains($phrase, 'kurier'), $phrase);

foreach (['e-mail', 'waga', 'paczkomat', 'adres.postcode'] as $code) {
    ok("« $code » a une phrase, pas un code", wsm_ship_blocker_label($code) !== $code, wsm_ship_blocker_label($code));
}

// ---- 3. Le récapitulatif groupe par cause -------------------------------------------------
echo "\n-- podsumowanie grupuje po przyczynie --\n";
$causes = wsm_ship_blockers_summary($pdo);
$tel = null;
foreach ($causes as $c) if ($c['code'] === 'telefon') $tel = $c;
ok('la cause « telefon » est comptée', $tel !== null && (int) $tel['n'] >= 1, $causes);
ok('avec sa phrase', $tel && str_contains((string) $tel['label'], 'kurier'), $tel['label'] ?? null);
$n = array_map(fn($c) => (int) $c['n'], $causes);
$trie = $n; rsort($trie);
ok('les causes sont triées de la plus fréquente à la moins', $n === $trie, $n);

// ---- 4. Le lot : un échec n'arrête pas les autres -------------------------------------------
echo "\n-- partia: jedna porażka nie zatrzymuje reszty --\n";
[$bon, ] = $mk('dobry');
wsm_order_mark_paid($pdo, (int) $bon['id'], 'test');

$r = wsm_ship_batch($pdo, [(int) $paye['id'], (int) $bon['id'], 999999], 'test');
ok('le lot rend un compte rendu', isset($r['utworzone'], $r['bledy']), $r);
ok('la commande sans téléphone est signalée', count($r['bledy']) >= 1, $r['bledy']);
$txt = implode(' | ', $r['bledy']);
ok('et le message nomme la commande', str_contains($txt, (string) $paye['code']), $txt);
ok('une commande inexistante est signalée aussi', str_contains($txt, '999999'), $txt);
ok('sans faire échouer l\'appel', is_array($r['bledy']));

// L'impayée passée de force au lot doit être REFUSÉE.
$r2 = wsm_ship_batch($pdo, [(int) $impaye['id']], 'test');
ok('une commande impayée forcée dans le lot est refusée', $r2['utworzone'] === 0, $r2);
ok('et le message le dit', str_contains(implode(' ', $r2['bledy']), 'niezapłacone'), $r2['bledy']);

// ---- 5. Sans jeton InPost, c'est un ÉTAT, pas un plantage ------------------------------------
echo "\n-- bez tokenu InPost: stan oczekiwania, nie awaria --\n";
ok('InPost est bien non configuré dans ce bac à sable', wsm_inpost_enabled() === false);
$phrase = wsm_ship_erreur_humaine('inpost_nieskonfigurowany');
ok('le message dit quoi faire', str_contains($phrase, 'Ustawieni'), $phrase);
ok('et que les colis partent à la main en attendant', str_contains($phrase, 'ręcznie'), $phrase);
$m = wsm_ship_erreur_humaine('brakujace_dane: telefon, waga');
ok('un blocage multiple est traduit en entier',
   str_contains($m, 'telefon') && str_contains($m, 'Waga'), $m);
ok('une erreur inconnue passe telle quelle', wsm_ship_erreur_humaine('cokolwiek') === 'cokolwiek');
ok('une erreur vide devient une phrase', wsm_ship_erreur_humaine('') !== '');

// ---- 6. On ne crée jamais deux fois -----------------------------------------------------------
echo "\n-- nigdy dwa razy --\n";
// On simule une expédition déjà partie : elle doit être SAUTÉE, pas recréée.
$pdo->prepare("UPDATE wsm_shipments SET tracking_number = ?, status = 'utworzona' WHERE order_id = ?")
    ->execute(['6200000000' . substr($sfx, 0, 4), (int) $bon['id']]);
$r3 = wsm_ship_batch($pdo, [(int) $bon['id']], 'test');
ok('une commande déjà suivie est sautée', $r3['pominiete'] === 1, $r3);
ok('et rien n\'est créé', $r3['utworzone'] === 0, $r3);
ok('le message le dit', str_contains($r3['message'], 'Pominięto'), $r3['message']);

$ids3 = array_map(fn($x) => (int) $x['order']['id'], wsm_ship_queue($pdo, 500));
ok('elle disparaît aussi de la file', !in_array((int) $bon['id'], $ids3, true));

// ---- 7. Les compteurs DÉCRIVENT LA LISTE AFFICHÉE ------------------------------------------------
echo "\n-- liczniki opisują tę listę, nie inną --\n";
$file = wsm_ship_queue($pdo);
$k = wsm_ship_kpis($pdo, $file);
ok('les bloquées sont comptées', $k['bloquees'] >= 1, $k);
ok('prêtes + bloquées = à envoyer', $k['gotowe'] + $k['bloquees'] === $k['do_wyslania'], $k);
ok('les nadanych sont comptées à part', $k['wyslane'] >= 1, $k);

// LE PIÈGE : les compteurs se calculaient sur LEUR propre borne (500) pendant
// que l'écran affichait la sienne (200). Au-delà de deux cents commandes en
// attente, la page annonçait un nombre que la liste ne contenait pas, et le
// bouton « nadaj wszystkie gotowe (300) » promettait ce qu'il ne ferait pas.
$court = wsm_ship_queue($pdo, 3);
$kc = wsm_ship_kpis($pdo, $court);
ok('le compteur suit la borne de la liste qu\'on lui donne',
   $kc['do_wyslania'] === count($court) && count($court) <= 3, [$kc['do_wyslania'], count($court)]);
ok('et ne va PAS rechercher plus loin tout seul', $kc['do_wyslania'] <= 3, $kc);
$cc = wsm_ship_blockers_summary($pdo, $court);
$somme = array_sum(array_map(fn($c) => (int) $c['n'], $cc));
$attendu = array_sum(array_map(fn($x) => count($x['blockers']), $court));
ok('le récapitulatif des causes décrit la même liste', $somme === $attendu, [$somme, $attendu]);

// ---- 8. Ce qui est parti se relit ---------------------------------------------------------------
echo "\n-- co poszło, da się odczytać --\n";
$partis = wsm_ship_sent($pdo, 50);
$vuP = null;
foreach ($partis as $s) if ((int) $s['order_id'] === (int) $bon['id']) $vuP = $s;
ok('l\'expédition partie est listée', $vuP !== null, count($partis));
ok('avec son numéro de suivi', $vuP && trim((string) $vuP['tracking_number']) !== '');
ok('et le code de sa commande', $vuP && (string) $vuP['code'] === (string) $bon['code']);
ok('les états ont tous un libellé',
   count(WSM_SHIP_STATUSES) >= 4 && !in_array('', array_values(WSM_SHIP_STATUSES), true));

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
