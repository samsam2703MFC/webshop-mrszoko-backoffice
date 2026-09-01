<?php
// ============================================================================
//  e2e_tpay_kanal.php — brancher l'encaissement, et savoir qu'il est branché.
//
//  L'opérateur a validé le site : reste à coller les identifiants. Et c'est là
//  qu'était le trou, le même que pour InPost : coller un client_id et un secret
//  dans Ustawienia ne donnait AUCUN retour. On l'apprenait le jour où quelqu'un
//  cliquait « Zamawiam i płacę » et tombait sur une commande qui n'ouvre aucune
//  transaction. Le panier est perdu, et personne n'est devant l'écran.
//
//  DEUX CANAUX, DEUX PANNES, ET IL FAUT LES DISTINGUER :
//
//   · client_id + secret ouvrent la transaction. Faux : le client ne peut pas
//     payer, et il le voit tout de suite.
//   · le code de sécurité valide la NOTIFICATION. Absent : le client paie,
//     tpay confirme, et la boutique REFUSE la confirmation. La commande reste
//     « oczekuje na płatność » sur de l'argent encaissé — la panne la plus
//     chère, parce qu'elle est invisible des deux côtés.
//
//  Usage :  php tests/e2e_tpay_kanal.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/tpay.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end kanal tpay\n\n";

// Le dépôt est PUBLIC : aucun vrai identifiant n'y vit, et le test ne doit
// rien exiger du serveur. On pose une configuration en mémoire.
$pose = fn(array $c) => wsm_config_overlay(['tpay' => $c]);

echo "-- czego brakuje, powiedziane bez pytania tpay --\n";
$appels = 0;
wsm_tpay_transport(function () use (&$appels) { $appels++; return [200, ['access_token' => 'x']]; });
$pose(['client_id' => '', 'client_secret' => '', 'security_code' => '', 'sandbox' => 1]);
[$k, $m] = wsm_tpay_diag();
ok('sans identifiants, on ne dérange pas tpay', $k === 'zle' && $appels === 0, [$k, $appels]);
ok('… et les deux manques sont nommés', str_contains($m, 'Client ID') && str_contains($m, 'secret'), $m);

echo "\n-- dane odrzucone: najczestsza pomylka --\n";
wsm_tpay_transport(fn() => [401, ['error' => 'invalid_client']]);
$pose(['client_id' => 'abc', 'client_secret' => 'def', 'security_code' => 'ghi', 'sandbox' => 1]);
[$k, $m] = wsm_tpay_diag();
ok('des identifiants refusés le disent', $k === 'zle' && str_contains($m, '401'), [$k, $m]);
// LE PIÈGE : des identifiants de sandbox employés en production, ou l'inverse.
// Chacun est juste chez lui ; ensemble ils n'ouvrent rien, et le message
// d'erreur de tpay ne le dit pas.
ok('… et le message nomme l\'environnement', str_contains($m, 'sandbox'), $m);

wsm_tpay_transport(fn() => [503, null]);
ok('une panne passagère dit « réessaie », pas « c\'est faux »', wsm_tpay_diag()[0] === 'uwaga');

echo "\n-- kanal otwarty, ale bez kodu bezpieczenstwa --\n";
// LA PANNE LA PLUS CHÈRE, et la seule que rien d'autre ne montre : le client
// paie, tpay confirme, la boutique refuse la confirmation. Argent encaissé,
// commande marquée impayée, et personne ne cherche parce que rien n'a l'air
// cassé.
wsm_tpay_transport(fn() => [200, ['access_token' => 'jeton']]);
$pose(['client_id' => 'abc', 'client_secret' => 'def', 'security_code' => '', 'sandbox' => 1]);
[$k, $m] = wsm_tpay_diag();
ok('le canal ouvert SANS code de sécurité est un avertissement, pas un succès', $k === 'uwaga', [$k, $m]);
ok('… et le message dit ce qui se passerait vraiment',
   str_contains($m, 'nieopłacone') && str_contains($m, 'pobranych'), $m);
// wsm_tpay_can_verify() est la garde qui refuse la notification. Elle doit
// dire non exactement dans ce cas-là.
ok('… et la notification serait effectivement refusée', !wsm_tpay_can_verify());

echo "\n-- wszystko na miejscu --\n";
$pose(['client_id' => 'abc', 'client_secret' => 'def', 'security_code' => 'ghi', 'sandbox' => 1]);
[$k, $m] = wsm_tpay_diag();
ok('tout est là : succès', $k === 'ok', [$k, $m]);
ok('… et l\'écran rappelle que le sandbox ne prend pas d\'argent',
   str_contains($m, 'NIE są pobierane'), $m);
$pose(['client_id' => 'abc', 'client_secret' => 'def', 'security_code' => 'ghi', 'sandbox' => 0]);
ok('en production, cette phrase disparaît', !str_contains(wsm_tpay_diag()[1], 'NIE są pobierane'));

echo "\n-- adres powiadomien: ten sam, ktory wysyla kasa --\n";
// L'adresse collée dans le panneau tpay DOIT être celle que la caisse envoie.
// Recopiée de travers, la notification frappe à une porte qui n'existe pas :
// argent encaissé, commande impayée, et aucune erreur nulle part.
$_SERVER['HTTP_HOST'] = 'sklep.example.com';
$_SERVER['REQUEST_URI'] = '/mrszoko/shop/kasa';
$u = wsm_tpay_notify_url();
ok('l\'adresse finit sur la route qui traite la notification',
   str_ends_with($u, '/shop/tpay/notify'), $u);
ok('… et elle est celle que la caisse construit', $u === wsm_api_base_url() . '/shop/tpay/notify', $u);

// LA MÊME ADRESSE DEPUIS TOUTES LES SURFACES — et c'est l'assertion qui compte
// le plus de ce fichier. L'ancienne version coupait au premier « /shop/ » :
// depuis l'API elle tombait juste par accident, mais depuis la CAISSE elle
// rendait « …/mrszoko/shop/tpay/notify » — la boutique, qui ne connaît pas
// cette route. Le client paie, tpay appelle, reçoit un 404, réessaie ; la
// commande reste « oczekuje na płatność » sur de l'argent encaissé, sans une
// erreur nulle part. Jamais vu, parce que tpay n'avait jamais été branché.
$vues = [];
foreach (['/mrszoko/shop/kasa',
          '/mrszoko/backoffice/api/shop/catalog',
          '/mrszoko/backoffice/ustawienia.php',
          '/mrszoko/landing/index.php'] as $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    $vues[$uri] = wsm_tpay_notify_url();
}
ok('la même adresse depuis la caisse, l\'API, la console et la vitrine',
   count(array_unique($vues)) === 1, $vues);
ok('… et elle désigne bien l\'API, pas la boutique',
   str_contains(reset($vues), '/backoffice/api/shop/tpay/notify'), reset($vues));

// L'adresse publique saisie en console fait autorité : derrière un proxy, le
// nom vu par le serveur n'est pas celui que tpay doit appeler.
wsm_config_overlay(['shop_url' => 'https://misterszoko.com/mrszoko/shop']);
$_SERVER['REQUEST_URI'] = '/mrszoko/backoffice/ustawienia.php';
ok('l\'adresse publique configurée est respectée',
   wsm_tpay_notify_url() === 'https://misterszoko.com/mrszoko/backoffice/api/shop/tpay/notify',
   wsm_tpay_notify_url());
wsm_config_overlay(['shop_url' => '']);

echo "\n-- ekran: przycisk jest podlaczony --\n";
$u = (string) @file_get_contents(dirname(__DIR__, 2) . '/ustawienia.php');
ok('le bouton de test est posté', str_contains($u, "isset(\$_POST['test_polaczenia'])"));
ok('… et il sert aussi InPost', str_contains($u, "'inpost' => 'wsm_inpost_diag'"));
// L'adresse de notification doit être AFFICHÉE : elle ne se devine pas, et
// c'est elle qu'on colle dans le panneau tpay.
ok('l\'adresse de notification est montrée à l\'écran', str_contains($u, 'wsm_tpay_notify_url()'));
// ON TESTE CE QUI EST EN BASE, pas ce qui vient d'être tapé : dire « ça
// marche » sur des identifiants que la boutique n'a pas encore enregistrés
// serait un mensonge dans les deux sens.
ok('le test ne prétend pas enregistrer', str_contains($u, "ON N'ENREGISTRE RIEN ICI"));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
