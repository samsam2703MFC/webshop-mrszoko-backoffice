<?php
// ============================================================================
//  cykl.php — les commandes récurrentes : la subvention d'un besoin régulier.
//
//  CE QU'ON NE PEUT PAS FAIRE, ET POURQUOI ON NE FAIT PAS SEMBLANT.
//
//  Un « abonnement » au sens courant prélève tout seul sur une carte
//  enregistrée. Cette boutique n'enregistre AUCUNE carte : tpay reçoit
//  l'acheteur, encaisse chez lui, et ne nous rend qu'un état de paiement.
//  Nous n'avons ni jeton de carte, ni mandat SEPA, ni le droit d'en stocker
//  un sans contrat dédié et sans le dire au client.
//
//  Un abonnement qui promettrait un prélèvement automatique serait donc un
//  mensonge à l'écran, et un litige au premier renouvellement. On ne le fait
//  pas. Ce module fait autre chose, entièrement tenable :
//
//      À L'ÉCHÉANCE, LA COMMANDE EST PRÉPARÉE ET UN LIEN DE PAIEMENT PART.
//
//  Le client reçoit exactement ce qu'il a commandé la dernière fois, au prix
//  du jour, et paie en un geste. C'est ce que fait un boulanger qui met le
//  pain de côté : il ne vide pas votre poche, il vous attend.
//
//  SIX RÈGLES :
//
//   1. RIEN N'EST PRÉLEVÉ. Jamais. La commande naît « oczekuje » comme
//      n'importe quelle autre, et meurt impayée si personne ne paie.
//
//   2. LE PRIX EST CELUI DU JOUR, pas celui d'il y a trois mois. Refacturer
//      un ancien prix serait faux dès la première hausse du cacao — et
//      l'acheteur verrait sur sa facture un montant qu'aucune page n'affiche.
//
//   3. UN PRODUIT DISPARU NE BLOQUE PAS L'ÉCHÉANCE. On prépare ce qui reste
//      et on le DIT. Annuler en silence ferait perdre la commande entière
//      pour un article retiré du catalogue.
//
//   4. UNE ÉCHÉANCE NE PASSE QU'UNE FOIS. La date de la prochaine échéance
//      avance AVANT l'envoi, et un passage rejoué le même jour ne crée pas
//      une seconde commande. Deux colis identiques coûtent deux fois.
//
//   5. LE CLIENT ARRÊTE QUAND IL VEUT, sans écrire à personne. Un lien dans
//      chaque message, un jeton qui n'ouvre que cet abonnement-là.
//
//   6. TROIS ÉCHÉANCES IMPAYÉES D'AFFILÉE METTENT EN PAUSE. Continuer à
//      préparer des colis que personne ne paie occupe l'atelier et remplit la
//      messagerie ; la pause est réversible d'un clic.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
if (!function_exists('wsm_shop_quote')) require_once __DIR__ . '/shop.php';

/** Les rythmes proposés, en jours. Rien d'autre n'est accepté. */
const WSM_CYKL_RYTMY = [
    'co_2_tygodnie' => ['label' => 'Co 2 tygodnie', 'dni' => 14],
    'co_miesiac'    => ['label' => 'Co miesiąc',    'dni' => 30],
    'co_2_miesiace' => ['label' => 'Co 2 miesiące', 'dni' => 60],
    'co_kwartal'    => ['label' => 'Co kwartał',    'dni' => 91],
];

/** Combien d'échéances impayées d'affilée avant la mise en pause. */
const WSM_CYKL_MAX_NIEOPLACONYCH = 3;

/** À partir de quand on prévient : un rappel la veille ne sert à rien. */
const WSM_CYKL_UPRZEDZENIE_DNI = 3;

/** Les tables et colonnes du module. Idempotent. */
function wsm_cykl_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_subscriptions')) wsm_apply_schema($pdo);
    if (!wsm_table_exists($pdo, 'wsm_subscription_items')) wsm_apply_schema($pdo);
    wsm_ensure_columns($pdo, 'wsm_orders', [
        // De quel abonnement vient cette commande. Sans ça, personne ne peut
        // dire pourquoi un colis est parti, ni ce qu'un abonnement a rapporté.
        'subscription_id' => ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
    ]);
}

/** Le rythme, ou celui par défaut si le code est inconnu. */
function wsm_cykl_rytm(string $code): array {
    return WSM_CYKL_RYTMY[$code] ?? WSM_CYKL_RYTMY['co_miesiac'];
}

/** Un jeton d'arrêt : il n'ouvre QUE cet abonnement, et rien d'autre. */
function wsm_cykl_token(): string { return bin2hex(random_bytes(16)); }

/**
 * Crée un abonnement à partir d'une commande existante.
 *
 * On repart d'une commande réelle plutôt que d'un panier : ce que le client a
 * déjà reçu et payé est la meilleure description de ce qu'il veut revoir.
 *
 * @return array [id|0, message]
 */
function wsm_cykl_create(PDO $pdo, int $orderId, string $rytm, string $actor = 'sklep'): array {
    $o = wsm_order_by_id($pdo, $orderId);
    if (!$o) return [0, 'Nie znaleziono zamówienia.'];
    if (!$o['items']) return [0, 'Zamówienie nie ma pozycji.'];

    $r = wsm_cykl_rytm($rytm);
    $code = array_search($r, WSM_CYKL_RYTMY, true) ?: 'co_miesiac';

    // Un même acheteur ne doit pas se retrouver avec deux abonnements
    // identiques parce qu'il a cliqué deux fois : deux colis, deux factures.
    $st = $pdo->prepare("SELECT id FROM wsm_subscriptions
                          WHERE LOWER(email) = ? AND rytm = ? AND statut = 'aktywny'");
    $st->execute([strtolower((string) $o['email']), $code]);
    if ($dejaId = (int) $st->fetchColumn()) {
        return [$dejaId, 'Ten adres ma już taką subskrypcję.'];
    }

    $prochaine = date('Y-m-d', time() + (int) $r['dni'] * 86400);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO wsm_subscriptions
                         (email, first_name, last_name, phone, company, nip,
                          lang, rytm, statut, next_at, delivery_method, inpost_point,
                          ship_street, ship_building, ship_postcode, ship_city, ship_country,
                          token, source_order_id, created_at)
                       VALUES (?,?,?,?,?,?,?,?,'aktywny',?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                strtolower((string) $o['email']), (string) $o['first_name'], (string) $o['last_name'],
                (string) $o['phone'], (string) $o['company'], (string) $o['nip'],
                (string) $o['lang'], $code, $prochaine,
                (string) $o['delivery_method'], (string) $o['inpost_point'],
                (string) $o['ship']['street'], (string) $o['ship']['building'],
                (string) $o['ship']['postcode'], (string) $o['ship']['city'],
                (string) ($o['ship']['country'] ?: 'PL'),
                wsm_cykl_token(), $orderId, date('Y-m-d H:i:s'),
            ]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO wsm_subscription_items (subscription_id, product_id, qty) VALUES (?,?,?)");
        foreach ($o['items'] as $it) {
            $ins->execute([$id, (string) $it['product_id'], max(1, (int) $it['qty'])]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [0, 'Nie udało się zapisać: ' . $e->getMessage()];
    }

    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Nowa subskrypcja', 'wsm_subscriptions #' . $id . ' ' . $o['email'], 'Sieć');
    }
    return [$id, 'Subskrypcja ' . mb_strtolower($r['label']) . ' — pierwsza dostawa ' . $prochaine . '.'];
}

/** Un abonnement avec ses lignes. */
function wsm_cykl_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_subscriptions WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return null;
    $st = $pdo->prepare("SELECT * FROM wsm_subscription_items WHERE subscription_id = ? ORDER BY id");
    $st->execute([$id]);
    $s['items'] = $st->fetchAll() ?: [];
    return $s;
}

/** Retrouve un abonnement par son jeton — le lien « arrêter » des courriers. */
function wsm_cykl_by_token(PDO $pdo, string $token): ?array {
    if (strlen($token) !== 32 || !ctype_xdigit($token)) return null;
    $st = $pdo->prepare("SELECT id, token FROM wsm_subscriptions");
    foreach ($st->execute() ? $st->fetchAll() : [] as $r) {
        // Comparaison à temps constant : un jeton ne se devine pas à la montre.
        if (hash_equals((string) $r['token'], $token)) return wsm_cykl_get($pdo, (int) $r['id']);
    }
    return null;
}

/**
 * Le panier de l'abonnement, tel qu'il se chiffrerait AUJOURD'HUI.
 *
 * @return array ['items'=>[], 'manquants'=>[], 'quote'=>array|null]
 */
function wsm_cykl_panier(PDO $pdo, array $sub): array {
    $items = []; $manquants = [];
    foreach ($sub['items'] as $it) {
        $st = $pdo->prepare("SELECT id, nom FROM wsm_products
                              WHERE id = ? AND active = 1 AND shop_visible = 1");
        $st->execute([(string) $it['product_id']]);
        $p = $st->fetch();
        // Règle 3 : un produit retiré du catalogue ne fait pas sauter le reste.
        if (!$p) { $manquants[] = (string) $it['product_id']; continue; }
        $items[] = ['id' => (string) $it['product_id'], 'qty' => max(1, (int) $it['qty'])];
    }
    if (!$items) return ['items' => [], 'manquants' => $manquants, 'quote' => null];

    // Règle 2 : le prix du jour. wsm_shop_quote relit tout en base.
    [$q] = wsm_shop_quote($pdo, $items, (string) $sub['delivery_method'], (string) $sub['lang'],
                          ['country' => (string) $sub['ship_country'], 'email' => (string) $sub['email']]);
    return ['items' => $items, 'manquants' => $manquants, 'quote' => $q];
}

/** Les abonnements dont l'échéance est arrivée. */
function wsm_cykl_dues(PDO $pdo, ?string $jour = null): array {
    $jour ??= date('Y-m-d');
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_subscriptions
                              WHERE statut = 'aktywny' AND next_at <= ? ORDER BY next_at, id");
        $st->execute([$jour]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Fait passer UNE échéance : commande préparée, lien de paiement envoyé.
 *
 * L'ÉCHÉANCE AVANCE D'ABORD (règle 4). Si l'envoi du courrier échoue ensuite,
 * on perd un message — pas grave, il y a la messagerie. Si l'ordre était
 * inverse et que la création plantait après l'envoi, le client recevrait deux
 * colis. On préfère perdre un courrier qu'expédier deux fois.
 *
 * @return array ['ok'=>bool, 'order'=>array|null, 'message'=>string]
 */
function wsm_cykl_run_one(PDO $pdo, array $sub, ?string $jour = null): array {
    $jour ??= date('Y-m-d');
    $id = (int) $sub['id'];
    $r = wsm_cykl_rytm((string) $sub['rytm']);

    $panier = wsm_cykl_panier($pdo, $sub);
    if (!$panier['items']) {
        // Plus rien à envoyer : on met en pause et on le dit, plutôt que de
        // repasser tous les jours sur un abonnement vide.
        $pdo->prepare("UPDATE wsm_subscriptions SET statut = 'wstrzymana', note = ? WHERE id = ?")
            ->execute(['Wszystkie produkty zniknęły z katalogu', $id]);
        return ['ok' => false, 'order' => null, 'message' => 'Brak dostępnych produktów — subskrypcja wstrzymana.'];
    }

    // Règle 4 : la date avance AVANT toute création.
    $suivante = date('Y-m-d', strtotime($jour) + (int) $r['dni'] * 86400);
    $up = $pdo->prepare("UPDATE wsm_subscriptions SET next_at = ?, last_run_at = ?
                          WHERE id = ? AND next_at <= ?");
    $up->execute([$suivante, date('Y-m-d H:i:s'), $id, $jour]);
    if ($up->rowCount() < 1) {
        // Quelqu'un est passé avant nous — un second passage le même jour.
        return ['ok' => false, 'order' => null, 'message' => 'Termin już przetworzony.'];
    }

    $corps = [
        'items' => $panier['items'], 'lang' => (string) $sub['lang'],
        'delivery_method' => (string) $sub['delivery_method'],
        'inpost_point' => (string) $sub['inpost_point'],
        'email' => (string) $sub['email'], 'phone' => (string) $sub['phone'],
        'first_name' => (string) $sub['first_name'], 'last_name' => (string) $sub['last_name'],
        'company' => (string) $sub['company'], 'nip' => (string) $sub['nip'],
        'client_type' => trim((string) $sub['company']) !== '' ? 'firma' : 'osoba',
        'ship_street' => (string) $sub['ship_street'], 'ship_building' => (string) $sub['ship_building'],
        'ship_postcode' => (string) $sub['ship_postcode'], 'ship_city' => (string) $sub['ship_city'],
        'ship_country' => (string) $sub['ship_country'],
        // Le client a accepté les conditions en s'abonnant ; c'est cette
        // acceptation-là qui vaut, et elle est datée sur l'abonnement.
        'consent_terms' => true,
    ];
    [$order, $err] = wsm_shop_create_order($pdo, $corps);
    if (!$order) {
        return ['ok' => false, 'order' => null,
                'message' => 'Nie udało się utworzyć zamówienia: ' . implode(', ', $err)];
    }

    $pdo->prepare("UPDATE wsm_orders SET subscription_id = ? WHERE id = ?")
        ->execute([$id, (int) $order['id']]);
    $pdo->prepare("UPDATE wsm_subscriptions SET runs = runs + 1, unpaid_streak = unpaid_streak + 1 WHERE id = ?")
        ->execute([$id]);

    // Règle 6 : trop d'échéances impayées d'affilée, on met en pause.
    $st = $pdo->prepare("SELECT unpaid_streak FROM wsm_subscriptions WHERE id = ?");
    $st->execute([$id]);
    if ((int) $st->fetchColumn() > WSM_CYKL_MAX_NIEOPLACONYCH) {
        $pdo->prepare("UPDATE wsm_subscriptions SET statut = 'wstrzymana', note = ? WHERE id = ?")
            ->execute(['Trzy kolejne terminy bez zapłaty', $id]);
    }

    wsm_order_event($pdo, (int) $order['id'], 'subskrypcja',
                    'termin ' . $jour . ' · ' . $r['label'], 'automat');

    $manque = $panier['manquants']
        ? ' Uwaga: ' . count($panier['manquants']) . ' pozycji nie ma już w katalogu.' : '';
    return ['ok' => true, 'order' => $order,
            'message' => 'Przygotowano zamówienie ' . $order['code'] . '.' . $manque];
}

/**
 * Le passage complet : toutes les échéances du jour.
 *
 * @return array ['przetworzone'=>int, 'zamowienia'=>string[], 'bledy'=>string[]]
 */
function wsm_cykl_run(PDO $pdo, ?string $jour = null): array {
    $faits = []; $bledy = [];
    foreach (wsm_cykl_dues($pdo, $jour) as $sub) {
        $r = wsm_cykl_run_one($pdo, $sub, $jour);
        if ($r['ok'] && $r['order']) {
            $faits[] = (string) $r['order']['code'];
            if (function_exists('wsm_mail_auto')) wsm_mail_auto($pdo, 'subskrypcja', $r['order']);
        } else {
            $bledy[] = '#' . (int) $sub['id'] . ' ' . $r['message'];
        }
    }
    return ['przetworzone' => count($faits), 'zamowienia' => $faits, 'bledy' => $bledy];
}

/**
 * Marque l'échéance payée : remet le compteur d'impayés à zéro.
 *
 * Appelé quand une commande d'abonnement est encaissée. Sans ce retour, un
 * client parfaitement à jour finirait mis en pause au bout de trois livraisons.
 */
function wsm_cykl_paid(PDO $pdo, int $orderId): void {
    try {
        $st = $pdo->prepare("SELECT subscription_id FROM wsm_orders WHERE id = ?");
        $st->execute([$orderId]);
        $sid = (int) $st->fetchColumn();
        if ($sid > 0) {
            $pdo->prepare("UPDATE wsm_subscriptions SET unpaid_streak = 0 WHERE id = ?")->execute([$sid]);
        }
    } catch (Throwable $e) { /* la colonne peut manquer sur une base ancienne */ }
}

/** Met en pause, reprend, ou arrête définitivement. */
function wsm_cykl_statut(PDO $pdo, int $id, string $statut, string $actor): array {
    $permis = ['aktywny', 'wstrzymana', 'zakonczona'];
    if (!in_array($statut, $permis, true)) return [false, 'Nieznany stan.'];
    $s = wsm_cykl_get($pdo, $id);
    if (!$s) return [false, 'Nie znaleziono subskrypcji.'];

    // Reprendre après une pause : la prochaine échéance repart d'aujourd'hui.
    // La laisser dans le passé déclencherait une commande dans la seconde.
    $next = (string) $s['next_at'];
    if ($statut === 'aktywny' && $next < date('Y-m-d')) {
        $next = date('Y-m-d', time() + (int) wsm_cykl_rytm((string) $s['rytm'])['dni'] * 86400);
    }
    $pdo->prepare("UPDATE wsm_subscriptions SET statut = ?, next_at = ?, unpaid_streak = 0 WHERE id = ?")
        ->execute([$statut, $next, $id]);
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Subskrypcja: ' . $statut, 'wsm_subscriptions #' . $id, 'Sieć');
    }
    $mots = ['aktywny' => 'wznowiona', 'wstrzymana' => 'wstrzymana', 'zakonczona' => 'zakończona'];
    return [true, 'Subskrypcja ' . $mots[$statut] . '.'];
}

/** Tous les abonnements, avec ce que chacun a rapporté. */
function wsm_cykl_list(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM wsm_subscriptions
                              ORDER BY CASE statut WHEN 'aktywny' THEN 0 ELSE 1 END, next_at")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $s) {
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN payment_status = 'oplacone'
                                    THEN total_gross ELSE 0 END),0) AS ca
                               FROM wsm_orders WHERE subscription_id = ?");
        $st->execute([(int) $s['id']]);
        $k = $st->fetch() ?: ['n' => 0, 'ca' => 0];
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_subscription_items WHERE subscription_id = ?");
        $st->execute([(int) $s['id']]);
        $out[] = $s + [
            'zamowien' => (int) $k['n'], 'obrot' => (int) $k['ca'],
            'pozycji' => (int) $st->fetchColumn(),
            'rytm_label' => wsm_cykl_rytm((string) $s['rytm'])['label'],
        ];
    }
    return $out;
}
