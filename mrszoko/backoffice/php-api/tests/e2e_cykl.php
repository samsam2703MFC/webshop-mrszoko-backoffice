<?php
// ============================================================================
//  e2e_cykl.php — preuve que la commande récurrente livre ce qu'elle promet,
//  et RIEN de plus.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. RIEN N'EST PRÉLEVÉ. La commande naît « oczekuje », comme n'importe
//      quelle autre. Cette boutique n'enregistre aucune carte ; promettre un
//      prélèvement serait un mensonge à l'écran et un litige au premier
//      renouvellement.
//   2. UNE ÉCHÉANCE NE PASSE QU'UNE FOIS. Deux passages le même jour ne font
//      pas deux colis — et deux colis coûtent deux fois.
//   3. LE PRIX EST CELUI DU JOUR. Une hausse du cacao doit se voir sur la
//      facture suivante, pas trois mois plus tard.
//   4. UN PRODUIT DISPARU NE FAIT PAS SAUTER L'ÉCHÉANCE. On envoie ce qui
//      reste et on le dit.
//   5. TROIS ÉCHÉANCES IMPAYÉES METTENT EN PAUSE — et un paiement remet le
//      compteur à zéro, sinon un bon client serait coupé pour avoir payé.
//   6. LE JETON D'ARRÊT N'OUVRE QUE SON PROPRE ABONNEMENT.
//
//  Usage :  php tests/e2e_cykl.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/cykl.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);
wsm_cykl_ensure($pdo);

echo "webshop_mrszoko — end-to-end subskrypcje\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_subscription_items WHERE subscription_id IN
                      (SELECT id FROM wsm_subscriptions WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_subscriptions WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-cykl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-cykl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-cykl-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$mkProd = function (string $s, float $prix) use ($pdo, $cat, $sfx): string {
    $id = "test-cykl-$sfx-$s";
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                        slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
                   VALUES (?,?,?,?,'Opublikowany',1,1,?,9999,0.23,250,200,150,100,?,5.00)")
         ->execute([$id, $cat, "Cykl $s $sfx", $prix, $id, strtoupper($s . $sfx)]);
    return $id;
};
$pA = $mkProd('a', 60.00);
$pB = $mkProd('b', 40.00);

$mail = "sub.$sfx@example.com";
$acheteur = [
    'items' => [['id' => $pA, 'qty' => 2], ['id' => $pB, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => $mail, 'phone' => '600100200',
    'first_name' => 'Ewa', 'last_name' => 'Nowak', 'client_type' => 'osoba',
    'ship_street' => 'Kwiatowa', 'ship_building' => '4', 'ship_postcode' => '00-002',
    'ship_city' => 'Kraków', 'ship_country' => 'PL', 'consent_terms' => true,
];
[$o0, $e0] = wsm_shop_create_order($pdo, $acheteur);
ok('la commande d\'origine passe', $o0 !== null, $e0);

// ---- 1. L'abonnement naît d'une commande réelle ---------------------------------------
echo "-- subskrypcja powstaje z prawdziwego zamówienia --\n";
[$sid, $msg] = wsm_cykl_create($pdo, (int) $o0['id'], 'co_miesiac');
ok('l\'abonnement est créé', $sid > 0, $msg);
$sub = wsm_cykl_get($pdo, $sid);
ok('il reprend les deux lignes', count($sub['items']) === 2, $sub['items']);
ok('et les quantités', (int) $sub['items'][0]['qty'] === 2, $sub['items'][0]['qty'] ?? null);
ok('l\'adresse est FIGÉE sur l\'abonnement, pas suivie ailleurs',
   (string) $sub['ship_city'] === 'Kraków' && (string) $sub['ship_postcode'] === '00-002', $sub['ship_city']);
ok('la première échéance est dans le futur', (string) $sub['next_at'] > date('Y-m-d'), $sub['next_at']);
ok('il porte un jeton d\'arrêt', strlen((string) $sub['token']) === 32, $sub['token']);

// Deux clics ne font pas deux abonnements identiques : deux colis, deux factures.
[$sid2, $msg2] = wsm_cykl_create($pdo, (int) $o0['id'], 'co_miesiac');
ok('un second clic ne crée pas un doublon', $sid2 === $sid, [$sid, $sid2]);
ok('et le dit', str_contains($msg2, 'już'), $msg2);

// ---- 2. L'échéance : préparée, JAMAIS prélevée -------------------------------------------
echo "\n-- termin: przygotowane, nigdy pobrane --\n";
$pdo->prepare("UPDATE wsm_subscriptions SET next_at = ? WHERE id = ?")
    ->execute([date('Y-m-d'), $sid]);
$dues = wsm_cykl_dues($pdo);
$vu = false; foreach ($dues as $d) if ((int) $d['id'] === $sid) $vu = true;
ok('l\'échéance du jour est vue', $vu, count($dues));

$sub = wsm_cykl_get($pdo, $sid);
$r1 = wsm_cykl_run_one($pdo, $sub);
ok('la commande est préparée', $r1['ok'] === true && $r1['order'] !== null, $r1['message']);
ok('elle attend le paiement — RIEN n\'est prélevé',
   $r1['order'] && (string) $r1['order']['payment_status'] === 'oczekuje', $r1['order']['payment_status'] ?? null);
ok('elle porte le numéro de l\'abonnement',
   (int) $pdo->query("SELECT subscription_id FROM wsm_orders WHERE id = " . (int) $r1['order']['id'])->fetchColumn() === $sid);
ok('et son montant est celui du catalogue AUJOURD\'HUI',
   (int) $r1['order']['items_gross'] === (int) $o0['items_gross'],
   [$r1['order']['items_gross'], $o0['items_gross']]);

// ---- 3. Une échéance ne passe qu'une fois --------------------------------------------------
echo "\n-- termin przechodzi tylko raz --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE subscription_id = $sid")->fetchColumn();
$r2 = wsm_cykl_run_one($pdo, wsm_cykl_get($pdo, $sid));
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE subscription_id = $sid")->fetchColumn();
ok('un second passage le même jour ne fait rien', $r2['ok'] === false, $r2['message']);
ok('et ne crée AUCUNE seconde commande', $apres === $avant, [$avant, $apres]);
$sub = wsm_cykl_get($pdo, $sid);
ok('la prochaine échéance a bien avancé de 30 jours',
   (string) $sub['next_at'] === date('Y-m-d', time() + 30 * 86400), $sub['next_at']);

// Le passage complet ne doit rien reprendre non plus.
$run = wsm_cykl_run($pdo);
$dansLot = false; foreach ($run['zamowienia'] as $c) if (str_starts_with($c, 'MS-')) $dansLot = true;
$apres2 = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE subscription_id = $sid")->fetchColumn();
ok('le passage complet ne reprend pas une échéance déjà faite', $apres2 === $avant, [$avant, $apres2]);

// ---- 4. Le prix est celui du jour -----------------------------------------------------------
echo "\n-- cena jest dzisiejsza, nie sprzed trzech miesięcy --\n";
$pdo->prepare("UPDATE wsm_products SET prix = 80.00 WHERE id = ?")->execute([$pA]);
$pdo->prepare("UPDATE wsm_subscriptions SET next_at = ? WHERE id = ?")->execute([date('Y-m-d'), $sid]);
$r3 = wsm_cykl_run_one($pdo, wsm_cykl_get($pdo, $sid));
ok('l\'échéance suivante passe', $r3['ok'] === true, $r3['message']);
// 2 × 80 + 1 × 40 = 200 zł, contre 2 × 60 + 40 = 160 avant la hausse.
ok('et facture le NOUVEAU prix, pas l\'ancien',
   $r3['order'] && (int) $r3['order']['items_gross'] === 20000, $r3['order']['items_gross'] ?? null);

// ---- 5. Un produit disparu ne fait pas sauter l'échéance ------------------------------------
echo "\n-- zniknięty produkt nie kasuje całego terminu --\n";
$pdo->prepare("UPDATE wsm_products SET shop_visible = 0 WHERE id = ?")->execute([$pB]);
$panier = wsm_cykl_panier($pdo, wsm_cykl_get($pdo, $sid));
ok('le produit retiré est signalé', $panier['manquants'] === [$pB], $panier['manquants']);
ok('mais le reste est toujours là', count($panier['items']) === 1, $panier['items']);
$pdo->prepare("UPDATE wsm_subscriptions SET next_at = ? WHERE id = ?")->execute([date('Y-m-d'), $sid]);
$r4 = wsm_cykl_run_one($pdo, wsm_cykl_get($pdo, $sid));
ok('l\'échéance passe quand même', $r4['ok'] === true, $r4['message']);
ok('et le message NOMME ce qui manque', str_contains($r4['message'], 'nie ma już'), $r4['message']);

// Tout disparaît : on met en pause plutôt que de repasser tous les jours.
$pdo->prepare("UPDATE wsm_products SET shop_visible = 0 WHERE id = ?")->execute([$pA]);
$pdo->prepare("UPDATE wsm_subscriptions SET next_at = ?, statut = 'aktywny' WHERE id = ?")
    ->execute([date('Y-m-d'), $sid]);
$r5 = wsm_cykl_run_one($pdo, wsm_cykl_get($pdo, $sid));
ok('plus rien à envoyer : l\'abonnement se met en pause', $r5['ok'] === false, $r5['message']);
ok('et l\'état le dit', (string) wsm_cykl_get($pdo, $sid)['statut'] === 'wstrzymana');
$pdo->prepare("UPDATE wsm_products SET shop_visible = 1 WHERE id IN (?, ?)")->execute([$pA, $pB]);

// ---- 6. Reprendre ne déclenche pas une commande dans la seconde -----------------------------
echo "\n-- wznowienie nie wystrzeliwuje zamówienia natychmiast --\n";
$pdo->prepare("UPDATE wsm_subscriptions SET next_at = ? WHERE id = ?")
    ->execute([date('Y-m-d', time() - 40 * 86400), $sid]);
[$okR, $msgR] = wsm_cykl_statut($pdo, $sid, 'aktywny', 'test');
ok('la reprise réussit', $okR === true, $msgR);
ok('et l\'échéance repart dans le FUTUR — pas dans le passé',
   (string) wsm_cykl_get($pdo, $sid)['next_at'] > date('Y-m-d'),
   wsm_cykl_get($pdo, $sid)['next_at']);

// ---- 7. Trois impayés mettent en pause, un paiement remet à zéro ----------------------------
echo "\n-- trzy nieopłacone terminy wstrzymują --\n";
$pdo->prepare("UPDATE wsm_subscriptions SET unpaid_streak = 0, statut = 'aktywny' WHERE id = ?")->execute([$sid]);
$dernier = null;
for ($i = 0; $i < 4; $i++) {
    $pdo->prepare("UPDATE wsm_subscriptions SET next_at = ? WHERE id = ?")->execute([date('Y-m-d'), $sid]);
    $s = wsm_cykl_get($pdo, $sid);
    if ((string) $s['statut'] !== 'aktywny') break;
    $rr = wsm_cykl_run_one($pdo, $s);
    if ($rr['ok']) $dernier = $rr['order'];
}
ok('après quatre échéances sans paiement, l\'abonnement est en pause',
   (string) wsm_cykl_get($pdo, $sid)['statut'] === 'wstrzymana',
   wsm_cykl_get($pdo, $sid)['statut']);

// Un paiement remet le compteur à zéro : sinon un client parfaitement à jour
// serait coupé au bout de trois livraisons — pour avoir payé trois fois.
$pdo->prepare("UPDATE wsm_subscriptions SET unpaid_streak = 2, statut = 'aktywny' WHERE id = ?")->execute([$sid]);
ok('le compteur est bien à 2 avant paiement',
   (int) wsm_cykl_get($pdo, $sid)['unpaid_streak'] === 2);
if ($dernier) {
    wsm_order_mark_paid($pdo, (int) $dernier['id'], 'test');
    ok('un paiement remet le compteur à zéro',
       (int) wsm_cykl_get($pdo, $sid)['unpaid_streak'] === 0,
       wsm_cykl_get($pdo, $sid)['unpaid_streak']);
} else {
    ok('un paiement remet le compteur à zéro', false, 'aucune commande à encaisser');
}

// ---- 8. Le jeton n'ouvre que son propre abonnement ------------------------------------------
echo "\n-- token otwiera TYLKO swoją subskrypcję --\n";
$sub = wsm_cykl_get($pdo, $sid);
$par = wsm_cykl_by_token($pdo, (string) $sub['token']);
ok('le bon jeton retrouve l\'abonnement', $par !== null && (int) $par['id'] === $sid);
ok('un jeton inventé ne retrouve rien', wsm_cykl_by_token($pdo, str_repeat('a', 32)) === null);
ok('un jeton mal formé non plus', wsm_cykl_by_token($pdo, 'court') === null);

// ---- 9. Les rythmes ---------------------------------------------------------------------------
echo "\n-- rytmy --\n";
ok('quatre rythmes proposés', count(WSM_CYKL_RYTMY) === 4, array_keys(WSM_CYKL_RYTMY));
ok('un rythme inconnu retombe sur le mensuel', wsm_cykl_rytm('n\'importe quoi')['dni'] === 30);
ok('et « co 2 tygodnie » vaut bien 14 jours', wsm_cykl_rytm('co_2_tygodnie')['dni'] === 14);

// ---- 10. La liste dit ce que ça rapporte -----------------------------------------------------
echo "\n-- lista mówi, ile to daje --\n";
$liste = wsm_cykl_list($pdo);
$vuL = null; foreach ($liste as $l) if ((int) $l['id'] === $sid) $vuL = $l;
ok('l\'abonnement est en liste', $vuL !== null);
ok('avec le nombre de commandes engendrées', $vuL && (int) $vuL['zamowien'] > 0, $vuL['zamowien'] ?? null);
ok('et le chiffre d\'affaires encaissé', $vuL && (int) $vuL['obrot'] > 0, $vuL['obrot'] ?? null);
ok('le rythme est écrit en clair', $vuL && (string) $vuL['rytm_label'] === 'Co miesiąc', $vuL['rytm_label'] ?? null);

// ---- 11. Le modèle de courrier ne promet pas un prélèvement ----------------------------------
echo "\n-- list nie obiecuje pobrania --\n";
$st = $pdo->prepare("SELECT subject, body FROM wsm_mail_templates WHERE code = 'subskrypcja' AND lang = 'pl'");
$st->execute();
$tpl = $st->fetch();
ok('le modèle polonais existe', $tpl !== false);
if ($tpl) {
    $corps = mb_strtolower((string) $tpl['body']);
    ok('il dit explicitement que rien n\'a été prélevé',
       str_contains($corps, 'nic nie zostało pobrane'), substr($corps, 0, 80));
    ok('et il porte un lien de paiement', str_contains((string) $tpl['body'], '{{link}}'));
    foreach (['pobraliśmy', 'obciążyliśmy', 'pobrano z karty'] as $interdit) {
        ok("il ne dit jamais « $interdit »", !str_contains($corps, $interdit));
    }
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
