<?php
// ============================================================================
//  inpost.php — expédition des commandes par InPost (ShipX).
//
//  Deux services : Paczkomat (le colis part vers un casier, identifié par son
//  code — KRA010) et coursier (adresse complète). Le reste est commun : un
//  destinataire nommé, un téléphone à 9 chiffres, un poids et un gabarit.
//
//  La charge utile ShipX est construite ici et exposée telle quelle au
//  back-office. Sans jeton configuré, aucune expédition n'est créée : la
//  commande reste `do_utworzenia` et le colis se prépare à la main. Une
//  boutique qui vend sans pouvoir expédier reste une boutique qui vend.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/shop.php';

function wsm_inpost_cfg(): array {
    $c = wsm_config()['inpost'] ?? [];
    return [
        'token'           => (string) ($c['token'] ?? ''),
        'organization_id' => (string) ($c['organization_id'] ?? ''),
        'geowidget_token' => (string) ($c['geowidget_token'] ?? ''),
        'sending_method'  => (string) ($c['sending_method'] ?? 'parcel_locker'),
        'sandbox'         => !empty($c['sandbox']),
    ];
}

/** Peut-on créer une expédition ? (jeton ShipX + organisation) */
function wsm_inpost_enabled(): bool {
    $c = wsm_inpost_cfg();
    return $c['token'] !== '' && $c['organization_id'] !== '';
}

/** Le sélecteur de Paczkomat n'est affiché que si son jeton public existe. */
function wsm_inpost_geowidget_token(): string {
    return wsm_inpost_cfg()['geowidget_token'];
}

function wsm_inpost_base(): string {
    return wsm_inpost_cfg()['sandbox'] ? 'https://sandbox-api-shipx-pl.easypack24.net' : 'https://api-shipx-pl.easypack24.net';
}

/**
 * Charge utile ShipX pour une commande. Construite même quand l'intégration
 * est éteinte : le back-office l'affiche, et on voit tout de suite ce qui
 * manquerait avant d'avoir payé un jeton.
 */
function wsm_inpost_payload(array $order): array {
    $c = wsm_inpost_cfg();
    $locker = $order['delivery_method'] === 'inpost_locker';

    $receiver = [
        'first_name'   => $order['first_name'] ?: '—',
        'last_name'    => $order['last_name'] ?: '—',
        'email'        => $order['email'],
        'phone'        => $order['phone'],
    ];
    if (!$locker) {
        $receiver['address'] = [
            'street'       => $order['ship']['street'] ?? '',
            'building_number' => $order['ship']['building'] ?? '',
            'city'         => $order['ship']['city'] ?? '',
            'post_code'    => $order['ship']['postcode'] ?? '',
            'country_code' => $order['ship']['country'] ?? 'PL',
        ];
    }
    if (($order['company'] ?? '') !== '') $receiver['company_name'] = $order['company'];

    $parcel = ['id' => 'small'];
    $tpl = strtoupper((string) ($order['parcel_template'] ?? ''));
    if ($tpl !== '') {
        $parcel = ['template' => strtolower($tpl)];               // 'a' | 'b' | 'c'
    }
    if (($order['weight_g'] ?? 0) > 0) {
        $parcel['weight'] = ['amount' => round($order['weight_g'] / 1000, 3), 'unit' => 'kg'];
    }

    $payload = [
        'receiver'       => $receiver,
        'parcels'        => [$parcel],
        'service'        => $locker ? 'inpost_locker_standard' : 'inpost_courier_standard',
        'reference'      => $order['code'],
        'comments'       => mb_substr((string) ($order['note'] ?? ''), 0, 100),
        'custom_attributes' => [],
    ];
    if ($locker) {
        $payload['custom_attributes'] = [
            'target_point'  => $order['inpost_point'],
            'sending_method' => $c['sending_method'],
        ];
    }
    // Paiement à la livraison non utilisé : tout est encaissé par tpay avant
    // expédition. On l'écrit noir sur blanc pour qu'aucun COD ne s'invite.
    $payload['cod'] = null;
    return $payload;
}

/** Ce qui manque encore pour que ShipX accepte l'expédition. */
function wsm_inpost_blockers(array $order): array {
    $out = [];
    if (($order['phone'] ?? '') === '' || !wsm_valid_phone((string) $order['phone'])) $out[] = 'telefon';
    if (($order['email'] ?? '') === '') $out[] = 'e-mail';
    if (($order['first_name'] ?? '') === '' && ($order['company'] ?? '') === '') $out[] = 'odbiorca';
    if (($order['weight_g'] ?? 0) <= 0) $out[] = 'waga';
    if ($order['delivery_method'] === 'inpost_locker') {
        if (($order['inpost_point'] ?? '') === '') $out[] = 'paczkomat';
    } else {
        foreach (['street', 'building', 'postcode', 'city'] as $k) {
            if (($order['ship'][$k] ?? '') === '') $out[] = 'adres.' . $k;
        }
    }
    return $out;
}

/**
 * Crée l'expédition chez InPost. Renvoie [ligne d'expédition, erreur|null].
 * Sans jeton, la ligne reste `do_utworzenia` — c'est un état d'attente, pas
 * un échec : la commande est payée et le colis part à la main.
 */
function wsm_inpost_create(PDO $pdo, array $order): array {
    $blockers = wsm_inpost_blockers($order);
    if ($blockers) {
        return [null, 'brakujace_dane: ' . implode(', ', $blockers)];
    }
    if (!wsm_inpost_enabled()) {
        $pdo->prepare("UPDATE wsm_shipments SET status = 'oczekuje_na_konfiguracje' WHERE order_id = ?")
            ->execute([$order['id']]);
        return [null, 'inpost_nieskonfigurowany'];
    }

    $c = wsm_inpost_cfg();
    $ch = curl_init(wsm_inpost_base() . '/v1/organizations/' . rawurlencode($c['organization_id']) . '/shipments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $c['token'],
            'Content-Type: application/json', 'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(wsm_inpost_payload($order), JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = json_decode((string) $raw, true);

    if ($code < 200 || $code >= 300 || empty($res['id'])) {
        $msg = 'shipx_' . $code . ': ' . mb_substr((string) ($res['message'] ?? $raw), 0, 180);
        $pdo->prepare("UPDATE wsm_shipments SET status = 'blad' WHERE order_id = ?")->execute([$order['id']]);
        wsm_order_event($pdo, (int) $order['id'], 'wysylka_blad', $msg, 'inpost');
        return [null, $msg];
    }

    $tracking = (string) ($res['tracking_number'] ?? '');
    $pdo->prepare("UPDATE wsm_shipments
                      SET shipment_id = ?, tracking_number = ?, status = 'utworzona'
                    WHERE order_id = ?")
        ->execute([(string) $res['id'], $tracking, $order['id']]);
    $pdo->prepare("UPDATE wsm_orders SET status = 'wyslane' WHERE id = ? AND status <> 'anulowane'")
        ->execute([$order['id']]);
    wsm_order_event($pdo, (int) $order['id'], 'wysylka_utworzona', $tracking, 'inpost');

    // Le client apprend le départ de sa paczka par la messagerie, pas en
    // regardant l'écran d'un back-office qu'il ne voit pas.
    if (function_exists('wsm_mail_auto')) {
        $fresh = wsm_order_by_id($pdo, (int) $order['id']);
        if ($fresh) wsm_mail_auto($pdo, 'wysylka', $fresh);
    }

    $st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$order['id']]);
    return [$st->fetch() ?: null, null];
}

/**
 * L'étiquette du transporteur, en PDF.
 *
 * Elle ne s'ouvre pas d'un simple lien : ShipX exige le jeton porteur, qu'un
 * navigateur n'a pas et ne doit pas avoir. La console la récupère donc
 * elle-même et la relaie — c'est le seul moyen d'imprimer l'étiquette
 * OBLIGATOIRE (celle qui porte le code-barres) sans publier le jeton.
 *
 * On ne met pas le PDF en cache : une étiquette réimprimée doit être celle que
 * le transporteur reconnaît aujourd'hui, pas celle d'avant une modification
 * d'adresse.
 *
 * @param string $format 'pdf' ou 'zpl' · $size 'A6' ou 'A4'
 * @return array [contenu binaire|null, type MIME, erreur|null]
 */
function wsm_inpost_label(array $shipment, string $format = 'pdf', string $size = 'A6'): array {
    $sid = trim((string) ($shipment['shipment_id'] ?? ''));
    if ($sid === '') return [null, '', 'przesyłka nie została jeszcze utworzona w InPost'];
    if (!wsm_inpost_enabled()) return [null, '', 'inpost_nieskonfigurowany'];

    $format = in_array($format, ['pdf', 'zpl', 'epl'], true) ? $format : 'pdf';
    $size   = in_array($size, ['A6', 'A4'], true) ? $size : 'A6';

    $c  = wsm_inpost_cfg();
    $url = wsm_inpost_base() . '/v1/shipments/' . rawurlencode($sid) . '/label'
         . '?format=' . $format . '&type=' . ($size === 'A4' ? 'A6P' : 'normal');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['token'], 'Accept: application/pdf'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || $raw === false || $raw === '') {
        // Le corps d'erreur de ShipX est du JSON ; on le remonte tel quel plutôt
        // que d'afficher « échec » et de laisser chercher.
        $j = json_decode((string) $raw, true);
        return [null, '', 'shipx_' . $code . ': ' . mb_substr((string) ($j['message'] ?? $raw), 0, 180)];
    }
    return [(string) $raw, $mime !== '' ? $mime : 'application/pdf', null];
}

/**
 * Le lien public de l'étiquette, tel que ShipX le publie parfois sur l'envoi.
 * Rangé sur l'expédition quand on le voit passer : il évite un aller-retour,
 * mais ne remplace pas wsm_inpost_label() — tous les comptes n'en ont pas.
 */
function wsm_inpost_remember_label(PDO $pdo, int $orderId, string $url): void {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return;
    $pdo->prepare("UPDATE wsm_shipments SET label_url = ? WHERE order_id = ?")->execute([$url, $orderId]);
}
