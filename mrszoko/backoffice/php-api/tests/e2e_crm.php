<?php
// ============================================================================
//  e2e_crm.php — preuve que la fiche client ne raconte pas d'histoires.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. ON NE COMPTE QUE L'ENCAISSÉ. Un client dont les commandes sont
//      impayées ou annulées ne doit pas apparaître comme un bon client :
//      c'est sur cette ligne qu'on décide d'accorder un délai de paiement.
//   2. LES BADGES SE CALCULENT. Un « Wysoki obrót » rangé en base reste vrai
//      trois ans après la dernière commande ; calculé, il tombe tout seul.
//   3. LE CLIENT EST L'ADRESSE, quelle que soit la casse ou l'orthographe du
//      nom saisi à chaque commande.
//   4. UNE NOTE EST SIGNÉE ET DATÉE.
//
//  Usage :  php tests/e2e_crm.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/crm.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end CRM (klienci, plakietki, notatki)\n\n";

$sfx = bin2hex(random_bytes(3));
$mail = "crm.$sfx@example.com";

/** Une commande de test, dans l'état voulu. */
$cmd = function (string $email, string $etat, string $paiement, int $gross,
                 string $quand, string $prenom = 'Jan', string $nom = 'Testowy') use ($pdo, $sfx): int {
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, first_name, last_name, lang,
                        status, payment_status, items_net, items_gross, shipping_net, shipping_gross,
                        total_net, total_gross, delivery_method, created_at)
                   VALUES (?,?,?,?,?,'pl',?,?,?,?,0,0,?,?,'inpost_locker',?)")
         ->execute(['MS-CRM-' . strtoupper(bin2hex(random_bytes(3))), bin2hex(random_bytes(8)),
                    $email, $prenom, $nom, $etat, $paiement,
                    (int) round($gross / 1.23), $gross, (int) round($gross / 1.23), $gross, $quand]);
    return (int) $pdo->lastInsertId();
};

$hier    = date('Y-m-d H:i:s', time() - 86400);
$vieux   = date('Y-m-d H:i:s', time() - 200 * 86400);
$ancien  = date('Y-m-d H:i:s', time() - 400 * 86400);

// ---- 1. On ne compte que l'encaissé ------------------------------------------------
echo "-- liczy się tylko zapłacone --\n";
$cmd($mail, 'dostarczone', 'oplacone',  10000, $ancien);
$cmd($mail, 'dostarczone', 'oplacone',  20000, $vieux);
$cmd($mail, 'nowe',        'oczekuje',  90000, $hier);     // impayée : hors du CA
$cmd($mail, 'anulowane',   'oplacone',  50000, $hier);     // annulée : hors de tout

$liste = wsm_crm_list($pdo);
$moi = null;
foreach ($liste as $c) if (strtolower($c['email']) === $mail) $moi = $c;
ok('le client apparaît', $moi !== null);
ok('quatre commandes sont vues', (int) $moi['orders'] === 4, $moi['orders']);
ok('mais DEUX seulement comptent comme achats', (int) $moi['paid_orders'] === 2, $moi['paid_orders']);
ok('le chiffre d\'affaires exclut l\'impayée ET l\'annulée',
    (int) $moi['revenue'] === 30000, $moi['revenue']);
ok('l\'impayée est comptée à part, pour qu\'elle se voie',
    (int) $moi['unpaid'] === 1, $moi['unpaid']);
ok('une commande annulée n\'est PAS un impayé — elle n\'existe plus',
    (int) $moi['unpaid'] === 1, $moi['unpaid']);

// ---- 2. Le client, c'est l'adresse ---------------------------------------------------
echo "\n-- klientem jest adres --\n";
$cmd(strtoupper($mail), 'dostarczone', 'oplacone', 15000, $hier, 'JAN', 'TESTOWY-POPRAWIONY');
$liste2 = wsm_crm_list($pdo);
$n = 0; $moi2 = null;
foreach ($liste2 as $c) if (strtolower($c['email']) === $mail) { $n++; $moi2 = $c; }
ok('une adresse en majuscules N\'EST PAS un second client', $n === 1, $n);
ok('et son achat s\'ajoute au total', (int) $moi2['revenue'] === 45000, $moi2['revenue']);
ok('le nom retenu est celui de la commande la PLUS RÉCENTE — la correction gagne',
    str_contains(mb_strtoupper((string) $moi2['name']), 'POPRAWIONY'), $moi2['name']);

// ---- 3. Les badges se calculent ---------------------------------------------------------
echo "\n-- plakietki liczą się przy wyświetlaniu --\n";
$seuil = wsm_crm_seuil_vip($liste2);
$bd = wsm_crm_badges($moi2, $seuil);
ok('trois achats payés font un habitué', isset($bd['staly']), array_keys($bd));
ok('l\'impayé est signalé', isset($bd['nieoplacone']), array_keys($bd));
ok('il n\'est pas « nowy » — il a payé', !isset($bd['nowy']), array_keys($bd));

// Quelqu'un qui n'a jamais payé n'est PAS un client endormi : il n'a jamais
// commencé. La différence compte, parce qu'on ne relance pas les deux pareil.
$jamais = "jamais.$sfx@example.com";
$cmd($jamais, 'nowe', 'oczekuje', 12000, $ancien);
$liste3 = wsm_crm_list($pdo);
$jm = null;
foreach ($liste3 as $c) if (strtolower($c['email']) === $jamais) $jm = $c;
$bj = wsm_crm_badges($jm, wsm_crm_seuil_vip($liste3));
ok('un curieux qui n\'a jamais payé est « bez zakupu »', isset($bj['nowy']), array_keys($bj));
ok('et il n\'est PAS marqué « śpiący » — on ne perd pas ce qu\'on n\'a jamais eu',
    !isset($bj['spiacy']), array_keys($bj));

// Un vrai dormeur : a payé, puis plus rien depuis longtemps.
$dort = "dort.$sfx@example.com";
$cmd($dort, 'dostarczone', 'oplacone', 25000, $ancien);
$liste4 = wsm_crm_list($pdo);
$dm = null;
foreach ($liste4 as $c) if (strtolower($c['email']) === $dort) $dm = $c;
$bdm = wsm_crm_badges($dm, wsm_crm_seuil_vip($liste4));
ok('un client qui a payé puis disparu est « śpiący »', isset($bdm['spiacy']), array_keys($bdm));
ok('et le badge dit depuis COMBIEN de jours — un « inactif » sans durée n\'aide personne',
    preg_match('/\d+ dni/', (string) ($bdm['spiacy'] ?? '')) === 1, $bdm['spiacy'] ?? null);

// Le badge de haut de panier annonce un FAIT, pas une proportion : sur un jeu
// où beaucoup de clients partagent le même montant, « top 10 % » s'affiche sur
// bien plus de 10 % — et personne ne va vérifier.
foreach ($liste4 as $c) {
    $b = wsm_crm_badges($c, $seuil);
    if (isset($b['vip'])) {
        ok('le badge de haut de panier ne promet aucun pourcentage',
            !str_contains($b['vip'], '%'), $b['vip']);
        break;
    }
}

// ---- 4. La fiche -------------------------------------------------------------------------
echo "\n-- karta klienta --\n";
$f = wsm_crm_client($pdo, $mail);
ok('la fiche se charge', $f !== null);
ok('elle liste toutes les commandes, payées ou non',
    count($f['orders_list']) === 5, count($f['orders_list']));
ok('le panier moyen se calcule sur les payées seulement',
    (int) $f['basket'] === 15000, $f['basket']);
ok('la première commande est la plus ancienne',
    substr((string) $f['first_at'], 0, 10) === substr($ancien, 0, 10), $f['first_at']);
ok('elle porte les badges', isset($f['badges']) && $f['badges'] !== []);

$rien = wsm_crm_client($pdo, "inconnu.$sfx@example.com");
ok('une adresse inconnue ne fabrique pas de fiche vide', $rien === null);
ok('une adresse vide non plus', wsm_crm_client($pdo, '') === null);

// ---- 5. Les notes ---------------------------------------------------------------------------
echo "\n-- notatki --\n";
[$nid, $err] = wsm_crm_note_add($pdo, $mail, 'Woli odbiór w paczkomacie przy pracy.', 'Anna K.');
ok('une note s\'enregistre', $nid !== null, $err);
$notes = wsm_crm_notes($pdo, $mail);
ok('elle se relit', count($notes) === 1, count($notes));
ok('avec son auteur', ($notes[0]['actor'] ?? '') === 'Anna K.', $notes[0]['actor'] ?? null);
ok('et sa date', ($notes[0]['created_at'] ?? '') !== '');

[$n2, $e2] = wsm_crm_note_add($pdo, $mail, '   ', 'Anna K.');
ok('une note vide est refusée', $n2 === null && $e2 !== '', $e2);
[$n3, $e3] = wsm_crm_note_add($pdo, '', 'coś', 'Anna K.');
ok('une note sans destinataire est refusée', $n3 === null);
[$n4] = wsm_crm_note_add($pdo, $mail, str_repeat('a', 2100), 'Anna K.');
ok('une note trop longue est refusée', $n4 === null);

// La note suit l'adresse, pas la casse : sinon on écrirait dans le vide.
ok('la note se retrouve depuis l\'adresse en majuscules',
    count(wsm_crm_notes($pdo, strtoupper($mail))) === 1);

ok('une note se supprime', wsm_crm_note_delete($pdo, (int) $nid) === true);
ok('et elle a bien disparu', wsm_crm_notes($pdo, $mail) === []);

// ---- 6. Le filtre et les totaux ---------------------------------------------------------------
echo "\n-- filtr i sumy --\n";
$t = wsm_crm_totaux($liste4);
ok('les totaux comptent les acheteurs, pas les curieux',
    $t['acheteurs'] < $t['clients'], [$t['acheteurs'], $t['clients']]);
ok('le panier moyen est positif', $t['panier'] > 0, $t['panier']);
ok('le chiffre d\'affaires est la somme des clients',
    $t['revenue'] === array_sum(array_map(fn($c) => (int) $c['revenue'], $liste4)));

$trouve = wsm_crm_filtre($liste4, $sfx);
ok('la recherche trouve par fragment d\'adresse', count($trouve) >= 3, count($trouve));
ok('la recherche est insensible à la casse',
    count(wsm_crm_filtre($liste4, mb_strtoupper($sfx))) === count($trouve));
$seg = wsm_crm_filtre($liste4, $sfx, 'nowy', $seuil);
ok('le filtre par segment ne garde que le segment',
    count($seg) >= 1 && count($seg) < count($trouve), [count($seg), count($trouve)]);
ok('un segment inconnu ne renvoie personne', wsm_crm_filtre($liste4, '', 'inexistant') === []);

// ---- Nettoyage -----------------------------------------------------------------------------------
foreach ([$mail, strtoupper($mail), $jamais, $dort] as $m) {
    $pdo->prepare("DELETE FROM wsm_client_notes WHERE LOWER(email) = ?")->execute([strtolower($m)]);
    $pdo->prepare("DELETE FROM wsm_orders WHERE LOWER(email) = ?")->execute([strtolower($m)]);
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
