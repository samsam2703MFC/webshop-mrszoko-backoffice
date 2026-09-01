<?php
// ============================================================================
//  tpay.php — encaissement des commandes par tpay.com.
//
//  Deux moitiés, et la seconde est la seule qui compte vraiment :
//    1. créer la transaction et rediriger l'acheteur vers tpay ;
//    2. RECEVOIR la notification serveur-à-serveur et n'encaisser que si elle
//       est authentique. C'est elle qui fait foi, jamais le retour navigateur :
//       l'URL de retour est sous le contrôle de l'acheteur.
//
//  Trois protections, non négociables :
//    · signature md5sum vérifiée avec hash_equals (le code de sécurité tpay
//      n'est jamais en dépôt — il vient de la configuration serveur) ;
//    · montant recomparé à la commande : une notification « payé 1 zł » sur
//      une commande à 129 zł est rejetée ;
//    · idempotence par event_key UNIQUE en base — tpay réémet sa notification
//      jusqu'à recevoir « TRUE », et une commande ne doit être encaissée qu'une fois.
//
//  Sans identifiants configurés, tout est FERMÉ : aucune transaction n'est
//  créée et aucune notification n'est acceptée. Pas de mode « on verra ».
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/shop.php';

function wsm_tpay_cfg(): array {
    $c = wsm_config()['tpay'] ?? [];
    return [
        'merchant_id'   => (string) ($c['merchant_id'] ?? ''),
        'client_id'     => (string) ($c['client_id'] ?? ''),
        'client_secret' => (string) ($c['client_secret'] ?? ''),
        'security_code' => (string) ($c['security_code'] ?? ''),
        'sandbox'       => !empty($c['sandbox']),
    ];
}

/** Peut-on créer une transaction ? (identifiants API présents) */
function wsm_tpay_enabled(): bool {
    $c = wsm_tpay_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** Peut-on accepter une notification ? (code de sécurité présent) */
function wsm_tpay_can_verify(): bool {
    return wsm_tpay_cfg()['security_code'] !== '';
}

function wsm_tpay_base(): string {
    return wsm_tpay_cfg()['sandbox'] ? 'https://openapi.sandbox.tpay.com' : 'https://openapi.tpay.com';
}

/** Appel HTTP JSON vers l'API tpay. Renvoie [code, corps décodé]. */
function wsm_tpay_http(string $method, string $path, array $body = [], string $bearer = ''): array {
    $ch = curl_init(wsm_tpay_base() . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/**
 * LE TRANSPORT, REMPLACABLE — comme pour InPost, VIES et le registre MF.
 *
 * Sans lui, aucun test ne peut eprouver ce qui compte vraiment : un secret
 * copie a moitie, des identifiants de sandbox employes en production. Ce sont
 * les deux pannes qui arrivent, et aucune ne se provoque a volonte.
 */
function wsm_tpay_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_tpay_http';
}

function wsm_tpay_token(): ?string {
    $c = wsm_tpay_cfg();
    [$code, $res] = (wsm_tpay_transport())('POST', '/oauth/auth', [
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
    ], '');
    return ($code === 200 && !empty($res['access_token'])) ? (string) $res['access_token'] : null;
}

/**
 * « EST-CE QUE L'ENCAISSEMENT MARCHE ? », repondu AVANT le premier client.
 *
 * C'est la piece qui manquait pour brancher le paiement. Coller un client_id
 * et un secret dans Ustawienia ne donnait aucun retour : on l'apprenait le jour
 * ou quelqu'un cliquait « Zamawiam i płacę » et tombait sur une commande qui
 * n'ouvre aucune transaction. Le panier est perdu, et personne n'est devant
 * l'ecran a ce moment-la.
 *
 * DEUX CANAUX, DEUX PANNES DIFFERENTES, et il faut les distinguer :
 *
 *  · client_id + client_secret ouvrent la transaction. Faux : le client ne
 *    peut pas payer, et il le voit tout de suite.
 *  · le code de securite valide la NOTIFICATION. Absent : le client paie
 *    normalement, tpay confirme, et la boutique refuse la confirmation. La
 *    commande reste « oczekuje na płatność » sur de l'argent encaisse — la
 *    panne la plus chere, parce qu'elle est invisible des deux cotes.
 *
 * @return array{0:string,1:string} [etat 'ok'|'uwaga'|'zle', phrase polonaise]
 */
function wsm_tpay_diag(): array {
    $c = wsm_tpay_cfg();
    $ou = $c['sandbox'] ? 'sandbox' : 'produkcja';

    $brak = [];
    if ($c['client_id'] === '')     $brak[] = 'Client ID';
    if ($c['client_secret'] === '') $brak[] = 'Client secret';
    if ($brak) {
        return ['zle', 'Brak: ' . implode(' i ', $brak) . '. Bez tego klient nie zapłaci.'];
    }

    [$code, $res] = (wsm_tpay_transport())('POST', '/oauth/auth', [
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
    ], '');

    if ($code === 401 || $code === 400) {
        // LE PIEGE LE PLUS COURANT : des identifiants de sandbox employes en
        // production, ou l'inverse. Chacun est juste chez lui, et ensemble ils
        // n'ouvrent rien.
        return ['zle', 'tpay odrzucił dane (' . $code . '). Sprawdź, czy Client ID i secret '
                     . 'pochodzą ze środowiska „' . $ou . '" — to najczęstsza pomyłka.'];
    }
    if ($code < 200 || $code >= 300 || empty($res['access_token'])) {
        return ['uwaga', 'tpay odpowiedział ' . $code . ' — spróbuj ponownie za chwilę.'];
    }

    // Le canal d'encaissement est ouvert. Reste celui des notifications, et
    // son absence ne se voit nulle part ailleurs.
    if ($c['security_code'] === '') {
        return ['uwaga', 'Połączenie działa (środowisko „' . $ou . '"), ale BRAKUJE kodu bezpieczeństwa. '
                       . 'Klient zapłaci, a sklep odrzuci potwierdzenie od tpay — zamówienie zostanie '
                       . 'jako nieopłacone mimo pobranych pieniędzy.'];
    }
    return ['ok', 'Połączenie działa, środowisko „' . $ou . '", kod bezpieczeństwa ustawiony.'
                . ($c['sandbox'] ? ' To sandbox: prawdziwe pieniądze NIE są pobierane.' : '')];
}

/**
 * L'adresse que tpay doit appeler pour confirmer un paiement.
 *
 * Elle se COLLE dans le panneau tpay, et elle ne se devine pas : c'est celle
 * que la caisse envoie a chaque transaction. L'afficher ici evite qu'elle soit
 * recopiee de travers — une notification qui frappe a la mauvaise porte laisse
 * la commande impayee sur de l'argent encaisse.
 */
function wsm_tpay_notify_url(): string {
    return wsm_api_base_url() . '/shop/tpay/notify';
}

/**
 * Ouvre une transaction pour une commande et enregistre la tentative.
 * Renvoie la ligne wsm_payments telle qu'elle a été écrite.
 *
 * Sans identifiants : la ligne est créée avec le statut `niedostepne` et
 * aucune URL de redirection — la commande existe, elle attend simplement
 * que le paiement soit configuré. Rien n'est perdu.
 */
function wsm_tpay_start(PDO $pdo, array $order, string $returnUrl, string $notifyUrl): array {
    $title = $order['code'];
    $amount = $order['total_gross'] / 100;

    if (!wsm_tpay_enabled()) {
        $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_title, amount, currency, status)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$order['id'], 'tpay', $title, $order['total_gross'], 'PLN', 'niedostepne']);
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_niedostepna', 'tpay nieskonfigurowany', 'system');
        return ['status' => 'niedostepne', 'redirect_url' => '', 'tr_id' => ''];
    }

    $token = wsm_tpay_token();
    if ($token === null) {
        $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_title, amount, currency, status)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$order['id'], 'tpay', $title, $order['total_gross'], 'PLN', 'blad_autoryzacji']);
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_blad', 'tpay: autoryzacja nieudana', 'system');
        return ['status' => 'blad_autoryzacji', 'redirect_url' => '', 'tr_id' => ''];
    }

    [$code, $res] = wsm_tpay_http('POST', '/transactions', [
        'amount'      => round($amount, 2),
        'description' => 'Mister Szoko ' . $title,
        'hiddenDescription' => $title,                    // revient dans tr_crc
        'lang'        => $order['lang'] ?? 'pl',
        'payer' => [
            'email' => $order['email'],
            'name'  => trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: ($order['company'] ?? ''),
            'phone' => $order['phone'] ?? '',
        ],
        'callbacks' => [
            'payerUrls'      => ['success' => $returnUrl, 'error' => $returnUrl],
            'notification'   => ['url' => $notifyUrl, 'email' => ''],
        ],
    ], $token);

    $ok      = $code >= 200 && $code < 300 && !empty($res['transactionId']);
    $trId    = (string) ($res['transactionId'] ?? '');
    $redirect = (string) ($res['transactionPaymentUrl'] ?? '');
    $status  = $ok ? 'oczekuje' : 'blad_utworzenia';

    $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_id, tr_title, amount, currency, status, redirect_url)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$order['id'], 'tpay', $trId, $title, $order['total_gross'], 'PLN', $status, $redirect]);
    wsm_order_event($pdo, (int) $order['id'],
        $ok ? 'platnosc_utworzona' : 'platnosc_blad', 'tpay ' . ($trId !== '' ? $trId : (string) $code), 'system');

    return ['status' => $status, 'redirect_url' => $redirect, 'tr_id' => $trId];
}

/**
 * Signature de la notification classique tpay :
 *   md5( id . tr_id . tr_amount . tr_crc . code_de_securite )
 * Comparée avec hash_equals — une comparaison `==` sur un condensé se
 * mesure au chronomètre.
 */
function wsm_tpay_signature_ok(array $p): bool {
    $c = wsm_tpay_cfg();
    if ($c['security_code'] === '') return false;                 // fail-closed
    $sent = strtolower(trim((string) ($p['md5sum'] ?? '')));
    if ($sent === '') return false;
    $expected = md5(
        (string) ($p['id'] ?? '') . (string) ($p['tr_id'] ?? '') .
        (string) ($p['tr_amount'] ?? '') . (string) ($p['tr_crc'] ?? '') . $c['security_code']
    );
    return hash_equals($expected, $sent);
}

/**
 * Traite une notification tpay. Renvoie [corps de réponse, code HTTP].
 * tpay attend littéralement « TRUE » pour cesser de réémettre.
 */
function wsm_tpay_notification(PDO $pdo, array $p, string $raw): array {
    if (!wsm_tpay_can_verify()) {
        // Aucun code de sécurité : impossible de distinguer tpay d'un inconnu.
        return ['FALSE — payment not configured', 503];
    }
    $sigOk = wsm_tpay_signature_ok($p);
    $crc   = trim((string) ($p['tr_crc'] ?? ''));
    $trId  = trim((string) ($p['tr_id'] ?? ''));
    $status = strtolower(trim((string) ($p['tr_status'] ?? '')));
    $paid  = (int) round(((float) ($p['tr_paid'] ?? 0)) * 100);

    $insert = $pdo->prepare("INSERT INTO wsm_payment_events (provider, event_key, status, amount, signature_ok, raw)
                             VALUES (?,?,?,?,?,?)");

    // La signature se vérifie AVANT toute chose. Une notification non signée
    // est archivée sous une clé qui ne peut entrer en collision avec rien :
    // si elle occupait la clé d'idempotence de la vraie notification, il
    // suffirait de connaître un tr_id pour empêcher une commande d'être
    // encaissée — la notification authentique serait prise pour un doublon.
    if (!$sigOk) {
        $insert->execute(['tpay', 'rej:' . bin2hex(random_bytes(12)), $status, $paid, 0, mb_substr($raw, 0, 4000)]);
        return ['FALSE — bad signature', 400];
    }

    // Clé d'idempotence des notifications AUTHENTIQUES : c'est la base qui
    // empêche le double encaissement (index UNIQUE), pas une vérification
    // applicative qui perdrait la course entre deux requêtes simultanées.
    $eventKey = 'tpay:' . $trId . ':' . $status . ':' . $paid;
    try {
        $insert->execute(['tpay', $eventKey, $status, $paid, 1, mb_substr($raw, 0, 4000)]);
    } catch (Throwable $e) {
        return ['TRUE', 200];                                    // déjà traitée : on acquitte sans rejouer
    }

    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE code = ?");
    $st->execute([$crc]);
    $order = $st->fetch();
    if (!$order) return ['FALSE — unknown order', 404];

    $pdo->prepare("UPDATE wsm_payment_events SET order_id = ? WHERE event_key = ?")->execute([(int) $order['id'], $eventKey]);

    // Le montant payé doit correspondre au montant dû. Une notification
    // authentique mais partielle ne libère pas la commande.
    if ($paid !== (int) $order['total_gross']) {
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_kwota_niezgodna',
            wsm_money($paid) . ' ≠ ' . wsm_money((int) $order['total_gross']), 'tpay');
        return ['FALSE — amount mismatch', 409];
    }

    if ($status === 'true' || $status === 'correct' || $status === 'paid') {
        wsm_order_mark_paid($pdo, (int) $order['id'], 'tpay');
    } else {
        $pdo->prepare("UPDATE wsm_orders SET payment_status = 'nieudane' WHERE id = ? AND payment_status <> 'oplacone'")
            ->execute([(int) $order['id']]);
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_nieudana', $status, 'tpay');
    }
    return ['TRUE', 200];
}
