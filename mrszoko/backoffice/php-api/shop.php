<?php
// ============================================================================
//  shop.php — moteur de la boutique en ligne Mister Szoko.
//
//  RÈGLE CENTRALE : le navigateur n'envoie que des identifiants de produit et
//  des quantités. Tous les prix, la TVA, les frais de port et le total sont
//  relus et recalculés ici, dans la base. Un panier trafiqué côté client ne
//  change donc rien au montant réellement facturé.
//
//  Les montants circulent en GROSZE (entiers). Les flottants n'ont pas leur
//  place sur de l'argent : 0.1 + 0.2 ne fait pas 0.3 en binaire, et une TVA
//  fausse d'un grosz est une facture fausse.
//
//  Convention de prix : wsm_products.prix est le prix TTC affiché au client
//  (usage B2C polonais). Le HT est déduit du TTC, et la TVA est le RESTE de
//  la soustraction — la somme des lignes retombe donc toujours juste.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/vies.php';

const WSM_SHOP_LANGS       = ['pl', 'uk', 'en'];
const WSM_SHOP_DEFAULT_LANG = 'pl';
const WSM_SHOP_MAX_QTY     = 99;              // garde-fou : pas de panier à 10 000 unités
const WSM_ORDER_STATUSES   = ['nowe', 'oplacone', 'w_realizacji', 'wyslane', 'dostarczone', 'anulowane'];

/** Origine de la requête en cours (schéma + hôte), proxy TLS compris. */
function wsm_request_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** URL publique de l'API — sert à composer l'URL de notification tpay. */
function wsm_api_base_url(): string {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
    $cut  = strpos($path, '/shop/');
    $base = $cut !== false ? substr($path, 0, $cut) : rtrim($path, '/');
    return wsm_request_origin() . $base;
}

/** URL publique de la boutique — sert à composer l'URL de retour tpay. */
function wsm_shop_base_url(): string {
    $fixed = (string) (wsm_config()['shop_url'] ?? '');
    if ($fixed !== '') return rtrim($fixed, '/');
    $api = wsm_api_base_url();
    return preg_replace('#/backoffice/api$#', '/shop', $api) ?: $api;
}

/** Langue demandée, ramenée à une langue réellement disponible. */
function wsm_shop_lang(PDO $pdo, ?string $want): string {
    $have = wsm_shop_available_langs($pdo);
    $want = strtolower(trim((string) $want));
    if ($want !== '' && in_array($want, $have, true)) return $want;
    return in_array(WSM_SHOP_DEFAULT_LANG, $have, true) ? WSM_SHOP_DEFAULT_LANG : ($have[0] ?? WSM_SHOP_DEFAULT_LANG);
}

function wsm_shop_available_langs(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = $pdo->query("SELECT DISTINCT lang FROM wsm_shop_i18n ORDER BY lang")->fetchAll();
        $cache = array_values(array_map(fn($r) => (string) $r['lang'], $rows));
    } catch (Throwable $e) { $cache = []; }
    if (!$cache) $cache = [WSM_SHOP_DEFAULT_LANG];
    return $cache;
}

/** Tous les libellés d'une langue. Aucun texte de la boutique n'est en dur. */
function wsm_shop_strings(PDO $pdo, string $lang): array {
    $st = $pdo->prepare("SELECT k, v FROM wsm_shop_i18n WHERE lang = ?");
    $st->execute([$lang]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string) $r['k']] = (string) $r['v'];
    // Repli sur la langue par défaut pour toute clé pas encore traduite :
    // une chaîne manquante doit donner du polonais, pas un trou dans la page.
    if ($lang !== WSM_SHOP_DEFAULT_LANG) {
        $st = $pdo->prepare("SELECT k, v FROM wsm_shop_i18n WHERE lang = ?");
        $st->execute([WSM_SHOP_DEFAULT_LANG]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($out[(string) $r['k']])) $out[(string) $r['k']] = (string) $r['v'];
        }
    }
    return $out;
}

/** Convertit un prix décimal de la base en grosze. */
function wsm_grosze($decimal): int { return (int) round(((float) $decimal) * 100); }

/**
 * Décompose un montant TTC. Le HT est arrondi, la TVA est la différence —
 * ainsi net + tva == brut, toujours, sans dérive d'arrondi.
 */
function wsm_split_vat(int $gross, float $vatRate): array {
    $net = (int) round($gross / (1 + $vatRate));
    return [$net, $gross - $net];
}

/** Un produit de la boutique, textes résolus dans la langue demandée. */
function wsm_shop_row_to_product(array $r, array $S): array {
    $id    = (string) $r['id'];
    $gross = wsm_grosze($r['prix']);
    $vat   = (float) $r['vat_rate'];
    [$net, $vatAmt] = wsm_split_vat($gross, $vat);
    return [
        'id'        => $id,
        'slug'      => ((string) ($r['slug'] ?? '')) !== '' ? (string) $r['slug'] : $id,
        'name'      => $S['product.' . $id . '.name']     ?? (string) $r['nom'],
        'subtitle'  => $S['product.' . $id . '.subtitle'] ?? '',
        'desc'      => $S['product.' . $id . '.desc']     ?? '',
        'origin'    => (string) ($r['origin'] ?? ''),
        'cocoa'     => (string) ($r['cocoa'] ?? ''),
        'unit'      => (string) ($r['unit_label'] ?? ''),
        'badge'     => ((string) ($r['badge'] ?? '')) !== '' ? ($S['badge.' . $r['badge']] ?? (string) $r['badge']) : '',
        'image'     => (string) ($r['image_url'] ?? ''),
        'from'      => (string) ($r['swatch_from'] ?? '--choco-500'),
        'to'        => (string) ($r['swatch_to'] ?? '--choco-800'),
        'price'     => $gross,
        'price_net' => $net,
        'price_vat' => $vatAmt,
        'vat_rate'  => $vat,
        'stock'     => (int) ($r['stock'] ?? 0),
        'weight_g'  => (int) ($r['weight_g'] ?? 0),
        'sku'       => (string) ($r['sku'] ?? ''),
        'ean'       => (string) ($r['ean'] ?? ''),
        'category'  => (string) ($r['category_name'] ?? ''),
        'category_id' => (int) ($r['category_id'] ?? 0),
    ];
}

/** Le catalogue visible en boutique, dans l'ordre voulu par le back-office. */
function wsm_shop_products(PDO $pdo, string $lang): array {
    $S = wsm_shop_strings($pdo, $lang);
    $rows = $pdo->query(
        "SELECT p.*, c.name AS category_name
           FROM wsm_products p
           LEFT JOIN wsm_categories c ON c.id = p.category_id
          WHERE p.shop_visible = 1 AND p.active = 1
          ORDER BY p.sort_order, p.nom"
    )->fetchAll();
    return array_map(fn($r) => wsm_shop_row_to_product($r, $S), $rows);
}

/** Un produit par slug ou par identifiant. */
function wsm_shop_product(PDO $pdo, string $key, string $lang): ?array {
    $S = wsm_shop_strings($pdo, $lang);
    $st = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
           FROM wsm_products p
           LEFT JOIN wsm_categories c ON c.id = p.category_id
          WHERE p.shop_visible = 1 AND p.active = 1 AND (p.slug = ? OR p.id = ?)
          LIMIT 1"
    );
    $st->execute([$key, $key]);
    $r = $st->fetch();
    return $r ? wsm_shop_row_to_product($r, $S) : null;
}

/** Modes de livraison actifs, tarifs compris — pilotés par la base. */
function wsm_shipping_methods(PDO $pdo, string $lang): array {
    $S = wsm_shop_strings($pdo, $lang);
    $rows = $pdo->query("SELECT * FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order")->fetchAll();
    return array_map(function ($r) use ($S) {
        $net   = (int) $r['price_net'];
        $vat   = (float) $r['vat_rate'];
        $gross = $net + (int) round($net * $vat);
        return [
            'id'        => (string) $r['id'],
            'carrier'   => (string) $r['carrier'],
            'label'     => $S['ship.' . $r['id'] . '.label'] ?? (string) $r['id'],
            'note'      => $S['ship.' . $r['id'] . '.note'] ?? '',
            'price_net' => $net,
            'vat_rate'  => $vat,
            'price'     => $gross,
            'free_from' => (int) $r['free_from'],
            'max_weight_g' => (int) $r['max_weight_g'],
        ];
    }, $rows);
}

function wsm_shipping_method(PDO $pdo, string $id, string $lang): ?array {
    foreach (wsm_shipping_methods($pdo, $lang) as $m) if ($m['id'] === $id) return $m;
    return null;
}

/**
 * Chiffre un panier. `$items` = [['id' => 'p-…', 'qty' => 2], …] — rien d'autre
 * n'est lu : un « price » envoyé par le client est purement et simplement ignoré.
 *
 * Renvoie [devis, erreurs]. Les erreurs sont indexées par champ pour que le
 * formulaire les affiche au bon endroit.
 */
function wsm_shop_quote(PDO $pdo, array $items, string $methodId, string $lang): array {
    $e = [];
    $lines = [];
    $itemsGross = 0; $itemsNet = 0; $itemsVat = 0; $weight = 0;

    $wanted = [];
    foreach ($items as $it) {
        $id  = trim((string) ($it['id'] ?? ''));
        $qty = (int) ($it['qty'] ?? 0);
        if ($id === '' || $qty <= 0) continue;
        if ($qty > WSM_SHOP_MAX_QTY) $qty = WSM_SHOP_MAX_QTY;
        $wanted[$id] = ($wanted[$id] ?? 0) + $qty;   // le même produit deux fois = une seule ligne
    }
    if (!$wanted) $e['items'] = 'koszyk pusty';

    $S = wsm_shop_strings($pdo, $lang);
    foreach ($wanted as $id => $qty) {
        $st = $pdo->prepare("SELECT * FROM wsm_products WHERE id = ? AND shop_visible = 1 AND active = 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { $e['items'] = 'produkt niedostępny: ' . $id; continue; }

        $p = wsm_shop_row_to_product($r, $S);
        if ($p['stock'] < $qty) {
            $e['stock'] = ($e['stock'] ?? '') . ($e['stock'] ?? '' ? ' · ' : '') . $p['name'] . ' — ' . $p['stock'];
            continue;
        }
        $lineGross = $p['price'] * $qty;
        [$lineNet, $lineVat] = wsm_split_vat($lineGross, $p['vat_rate']);

        $lines[] = [
            'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'unit' => $p['unit'],
            'sku' => $p['sku'], 'ean' => $p['ean'], 'image' => $p['image'],
            'from' => $p['from'], 'to' => $p['to'],
            'qty' => $qty, 'unit_gross' => $p['price'], 'unit_net' => $p['price_net'],
            'vat_rate' => $p['vat_rate'],
            'line_net' => $lineNet, 'line_vat' => $lineVat, 'line_gross' => $lineGross,
            'weight_g' => $p['weight_g'] * $qty,
        ];
        $itemsGross += $lineGross; $itemsNet += $lineNet; $itemsVat += $lineVat;
        $weight += $p['weight_g'] * $qty;
    }

    // --- Livraison ----------------------------------------------------------
    $methods = wsm_shipping_methods($pdo, $lang);
    $method = null;
    foreach ($methods as $m) if ($m['id'] === $methodId) $method = $m;
    if ($methodId !== '' && !$method) $e['delivery_method'] = 'nieznana metoda dostawy';
    if (!$method && $methods) $method = $methods[0];

    $shipNet = 0; $shipVat = 0; $shipGross = 0; $freeShipping = false;
    if ($method) {
        if ($weight > $method['max_weight_g']) {
            $e['weight_g'] = 'przesyłka za ciężka: ' . $weight . ' g';
        }
        $freeShipping = $method['free_from'] > 0 && $itemsGross >= $method['free_from'];
        if (!$freeShipping) {
            $shipNet   = $method['price_net'];
            $shipGross = $shipNet + (int) round($shipNet * $method['vat_rate']);
            $shipVat   = $shipGross - $shipNet;
        }
    }

    $tpl = wsm_shop_parcel_template($pdo, $lines);

    return [[
        'lang'      => $lang,
        'currency'  => 'PLN',
        'lines'     => $lines,
        'items_net' => $itemsNet, 'items_vat' => $itemsVat, 'items_gross' => $itemsGross,
        'shipping_net' => $shipNet, 'shipping_vat' => $shipVat, 'shipping_gross' => $shipGross,
        'shipping_free' => $freeShipping,
        'total_net'   => $itemsNet + $shipNet,
        'total_vat'   => $itemsVat + $shipVat,
        'total_gross' => $itemsGross + $shipGross,
        'weight_g'    => $weight,
        'parcel_template' => $tpl,
        'method'      => $method,
        'methods'     => $methods,
        'vat_breakdown' => wsm_vat_breakdown($lines, $shipNet, $shipVat, $method['vat_rate'] ?? 0.23),
    ], $e];
}

/** TVA ventilée par taux — ce que réclame une facture polonaise. */
function wsm_vat_breakdown(array $lines, int $shipNet, int $shipVat, float $shipRate): array {
    $by = [];
    foreach ($lines as $l) {
        $k = (string) $l['vat_rate'];
        $by[$k] ??= ['rate' => (float) $l['vat_rate'], 'net' => 0, 'vat' => 0];
        $by[$k]['net'] += $l['line_net'];
        $by[$k]['vat'] += $l['line_vat'];
    }
    if ($shipNet > 0) {
        $k = (string) $shipRate;
        $by[$k] ??= ['rate' => $shipRate, 'net' => 0, 'vat' => 0];
        $by[$k]['net'] += $shipNet;
        $by[$k]['vat'] += $shipVat;
    }
    $out = array_values($by);
    usort($out, fn($a, $b) => $a['rate'] <=> $b['rate']);
    return $out;
}

/** Marge de calage : un colis n'est jamais rempli à 100 % de son volume. */
const WSM_PACKING_FACTOR = 1.30;

/**
 * Gabarit InPost pour l'ENSEMBLE du panier. Deux conditions, pas une :
 *   · le plus gros article doit entrer dans le casier (contrainte de forme) ;
 *   · le volume total, majoré du calage, doit y tenir (contrainte de place).
 *
 * Prendre le gabarit du plus gros article serait faux : deux sacs de 6 cm
 * d'épaisseur passent chacun dans un casier A (8 cm) mais pas ensemble.
 *
 * Reste une ESTIMATION — seul un vrai calcul de calage trancherait. Le
 * back-office l'affiche comme telle et l'emballeur peut la corriger.
 * Renvoie '' quand rien ne convient : c'est un envoi par coursier.
 */
function wsm_shop_parcel_template(PDO $pdo, array $lines): string {
    if (!$lines) return '';
    $volume = 0;
    $fits = [];                                          // gabarits acceptables pour CHAQUE article
    foreach ($lines as $l) {
        $st = $pdo->prepare("SELECT length_mm, width_mm, height_mm FROM wsm_products WHERE id = ?");
        $st->execute([$l['id']]);
        $d = $st->fetch();
        if (!$d) return '';
        $L = (int) $d['length_mm']; $W = (int) $d['width_mm']; $H = (int) $d['height_mm'];
        if ($L <= 0 || $W <= 0 || $H <= 0) return '';    // dimensions inconnues : on n'invente pas
        $volume += $L * $W * $H * max(1, (int) $l['qty']);
        $t = wsm_inpost_template($L, $W, $H);
        if ($t === '') return '';                        // un article hors casier ⇒ coursier
        $fits[] = $t;
    }
    $needed = (int) ceil($volume * WSM_PACKING_FACTOR);

    $order = array_keys(WSM_INPOST_TEMPLATES);           // A < B < C
    $floor = 0;
    foreach ($fits as $t) { $i = (int) array_search($t, $order, true); if ($i > $floor) $floor = $i; }

    foreach ($order as $i => $key) {
        if ($i < $floor) continue;
        $m = WSM_INPOST_TEMPLATES[$key]['max'];
        if ($m[0] * $m[1] * $m[2] >= $needed) return $key;
    }
    return '';
}

/**
 * Valide les champs « vitrine » d'un produit (ce que la boutique affiche).
 * Seules les clés PRÉSENTES dans la requête sont touchées : la page produit
 * envoie ce qu'elle modifie, pas l'objet entier.
 *
 * @return array [colonnes à écrire, erreurs par champ]
 */
function wsm_validate_product_shop(PDO $pdo, array $in, string $id): array {
    require_once __DIR__ . '/media.php';
    $e = []; $out = [];

    if (array_key_exists('slug', $in)) {
        $slug = strtolower(trim((string) $in['slug']));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $e['slug'] = 'wymagany';
        } elseif (strlen($slug) > 80) {
            $e['slug'] = 'maks. 80 znaków';
        } else {
            // Le slug est l'adresse publique du produit : deux produits ne
            // peuvent pas se la partager, sinon l'un devient inatteignable.
            $st = $pdo->prepare("SELECT id FROM wsm_products WHERE slug = ? AND id <> ?");
            $st->execute([$slug, $id]);
            if ($st->fetchColumn()) $e['slug'] = 'zajęty przez inny produkt';
        }
        $out['slug'] = $slug;
    }

    if (array_key_exists('image_url', $in)) {
        $url = trim((string) $in['image_url']);
        if (!wsm_media_valid_url($url)) $e['image_url'] = 'wgraj plik lub podaj adres https://';
        $out['image_url'] = $url;
    }

    if (array_key_exists('stock', $in)) {
        $s = (int) $in['stock'];
        if ($s < 0) $e['stock'] = 'nie może być ujemny';
        $out['stock'] = max(0, $s);
    }

    if (array_key_exists('shop_visible', $in)) $out['shop_visible'] = !empty($in['shop_visible']) ? 1 : 0;

    foreach (['origin' => 80, 'cocoa' => 16, 'unit_label' => 40, 'badge' => 40,
              'swatch_from' => 32, 'swatch_to' => 32] as $k => $max) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string) $in[$k]);
        if (mb_strlen($v) > $max) $e[$k] = 'maks. ' . $max . ' znaków';
        $out[$k] = $v;
    }

    // Un produit sans slug ne peut pas être mis en vente : il n'aurait pas d'URL.
    if (($out['shop_visible'] ?? 0) === 1 && array_key_exists('slug', $out) && $out['slug'] === '') {
        $e['slug'] = 'wymagany, aby produkt był widoczny w sklepie';
    }

    return [$out, $e];
}

/** Code de commande lisible : MS-AAMMJJ-NNNN. */
function wsm_next_order_code(PDO $pdo): string {
    $prefix = 'MS-' . date('ymd') . '-';
    $st = $pdo->prepare("SELECT code FROM wsm_orders WHERE code LIKE ? ORDER BY code DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string) $st->fetchColumn();
    $n = $last !== '' ? ((int) substr($last, strlen($prefix))) + 1 : 1;
    return $prefix . sprintf('%04d', $n);
}

/**
 * Valide l'acheteur : e-mail et téléphone pour tpay et InPost, adresse selon
 * le mode de livraison, NIP si une facture d'entreprise est demandée.
 */
function wsm_validate_buyer(array $in, string $method, bool $invoice): array {
    $e = [];
    $out = [];

    $email = trim((string) ($in['email'] ?? ''));
    if ($email === '') $e['email'] = 'wymagany';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'nieprawidłowy';
    $out['email'] = strtolower($email);

    $phone = (string) ($in['phone'] ?? '');
    if ($phone === '') $e['phone'] = 'wymagany (InPost)';
    elseif (!wsm_valid_phone($phone)) $e['phone'] = '9 cyfr';
    $out['phone'] = $phone === '' ? '' : wsm_normalize_phone($phone);

    foreach (['first_name' => 'imię', 'last_name' => 'nazwisko'] as $k => $lbl) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') $e[$k] = $lbl . ' wymagane';
        $out[$k] = $v;
    }

    $out['client_type'] = $invoice && trim((string) ($in['nip'] ?? '')) !== '' ? 'firma' : 'osoba';
    $out['invoice'] = $invoice ? 1 : 0;
    $out['company'] = trim((string) ($in['company'] ?? ''));
    $out['vat_eu']  = '';
    $vat = trim((string) ($in['vat_eu'] ?? ''));
    if ($vat !== '') {
        if (!wsm_valid_vat_eu($vat)) $e['vat_eu'] = 'format VIES (np. PL1234567890)';
        else $out['vat_eu'] = strtoupper(preg_replace('/[\s.-]+/', '', $vat) ?? '');
    }

    $nip = trim((string) ($in['nip'] ?? ''));
    if ($out['client_type'] === 'firma') {
        if ($nip === '') $e['nip'] = 'wymagany dla firmy';
        elseif (!wsm_valid_nip($nip)) $e['nip'] = 'błędna suma kontrolna';
        if ($out['company'] === '') $e['company'] = 'nazwa firmy wymagana';
    }
    $out['nip'] = $nip !== '' && wsm_valid_nip($nip) ? wsm_normalize_nip($nip) : '';

    // Adresse de facturation : exigée dès qu'une facture est demandée.
    $pc = (string) ($in['bill_postcode'] ?? '');
    if ($invoice) {
        foreach (['bill_street' => 'ulica', 'bill_building' => 'numer', 'bill_city' => 'miasto'] as $k => $lbl) {
            if (trim((string) ($in[$k] ?? '')) === '') $e[$k] = $lbl . ' wymagane do faktury';
        }
        if ($pc === '') $e['bill_postcode'] = 'wymagany do faktury';
    }
    if ($pc !== '' && !wsm_valid_postcode($pc)) $e['bill_postcode'] = 'format NN-NNN';
    $out['bill_postcode'] = $pc === '' ? '' : wsm_normalize_postcode($pc);
    foreach (['bill_street', 'bill_building', 'bill_city'] as $k) $out[$k] = trim((string) ($in[$k] ?? ''));
    $cc = strtoupper(trim((string) ($in['bill_country'] ?? 'PL')));
    $out['bill_country'] = preg_match('/^[A-Z]{2}$/', $cc) ? $cc : 'PL';

    // Destination : Paczkomat (code) ou adresse coursier complète.
    $point = strtoupper(trim((string) ($in['inpost_point'] ?? '')));
    if ($method === 'inpost_locker') {
        if ($point === '') $e['inpost_point'] = 'wybierz paczkomat';
        elseif (!wsm_valid_inpost_point($point)) $e['inpost_point'] = 'np. KRA010';
    }
    $out['inpost_point'] = $point;

    $spc = (string) ($in['ship_postcode'] ?? '');
    if ($method === 'inpost_courier') {
        foreach (['ship_street' => 'ulica', 'ship_building' => 'numer', 'ship_city' => 'miasto'] as $k => $lbl) {
            if (trim((string) ($in[$k] ?? '')) === '') $e[$k] = $lbl . ' wymagane dla kuriera';
        }
        if ($spc === '') $e['ship_postcode'] = 'wymagany dla kuriera';
    }
    if ($spc !== '' && !wsm_valid_postcode($spc)) $e['ship_postcode'] = 'format NN-NNN';
    $out['ship_postcode'] = $spc === '' ? '' : wsm_normalize_postcode($spc);
    foreach (['ship_street', 'ship_building', 'ship_city'] as $k) $out[$k] = trim((string) ($in[$k] ?? ''));
    $scc = strtoupper(trim((string) ($in['ship_country'] ?? 'PL')));
    $out['ship_country'] = preg_match('/^[A-Z]{2}$/', $scc) ? $scc : 'PL';

    if (empty($in['consent_terms'])) $e['consent_terms'] = 'akceptacja regulaminu wymagana';

    $out['note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);

    return [$out, $e];
}

/**
 * Crée la commande : devis recalculé, acheteur validé, stock décrémenté,
 * lignes figées, expédition préparée. Tout dans une transaction — une
 * commande à moitié écrite serait pire qu'une commande refusée.
 *
 * @return array [commande|null, erreurs]
 */
function wsm_shop_create_order(PDO $pdo, array $body): array {
    $lang   = wsm_shop_lang($pdo, (string) ($body['lang'] ?? ''));
    $method = (string) ($body['delivery_method'] ?? 'inpost_locker');
    $invoice = !empty($body['invoice']);

    [$quote, $qErr] = wsm_shop_quote($pdo, (array) ($body['items'] ?? []), $method, $lang);
    [$buyer, $bErr] = wsm_validate_buyer($body, $method, $invoice);
    $errors = $qErr + $bErr;
    if ($errors) return [null, $errors];
    if ($quote['total_gross'] <= 0) return [null, ['items' => 'koszyk pusty']];

    // ---- VIES : le numéro de TVA saisi à la caisse est-il réel ? ------------
    // Un numéro que VIES déclare inconnu ferait rejeter la facture : mieux vaut
    // le dire au client maintenant qu'après l'encaissement. En revanche, si le
    // service est en panne, la commande passe — on garde l'état pour plus tard.
    $vies = wsm_vies_check($pdo, (string) $buyer['vat_eu']);
    if (wsm_vies_blocks($vies)) return [null, ['vat_eu' => $vies['reason'] ?: 'nieznany w VIES']];

    $pdo->beginTransaction();
    try {
        // Stock : relu et décrémenté DANS la transaction. Deux commandes
        // simultanées sur le dernier article ne peuvent pas passer toutes deux.
        foreach ($quote['lines'] as $l) {
            $st = $pdo->prepare("UPDATE wsm_products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $st->execute([$l['qty'], $l['id'], $l['qty']]);
            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                return [null, ['stock' => $l['name']]];
            }
        }

        $code  = wsm_next_order_code($pdo);
        $token = bin2hex(random_bytes(16));

        $cols = [
            'code' => $code, 'access_token' => $token, 'lang' => $lang, 'currency' => 'PLN',
            'status' => 'nowe', 'payment_status' => 'oczekuje',
            'client_type' => $buyer['client_type'], 'email' => $buyer['email'], 'phone' => $buyer['phone'],
            'first_name' => $buyer['first_name'], 'last_name' => $buyer['last_name'],
            'company' => $buyer['company'], 'nip' => $buyer['nip'], 'vat_eu' => $buyer['vat_eu'],
            'invoice' => $buyer['invoice'],
            'bill_street' => $buyer['bill_street'], 'bill_building' => $buyer['bill_building'],
            'bill_postcode' => $buyer['bill_postcode'], 'bill_city' => $buyer['bill_city'],
            'bill_country' => $buyer['bill_country'],
            'delivery_method' => $method, 'inpost_point' => $buyer['inpost_point'],
            'ship_street' => $buyer['ship_street'], 'ship_building' => $buyer['ship_building'],
            'ship_postcode' => $buyer['ship_postcode'], 'ship_city' => $buyer['ship_city'],
            'ship_country' => $buyer['ship_country'],
            'items_net' => $quote['items_net'], 'items_vat' => $quote['items_vat'], 'items_gross' => $quote['items_gross'],
            'shipping_net' => $quote['shipping_net'], 'shipping_vat' => $quote['shipping_vat'],
            'shipping_gross' => $quote['shipping_gross'],
            'total_net' => $quote['total_net'], 'total_vat' => $quote['total_vat'], 'total_gross' => $quote['total_gross'],
            'weight_g' => $quote['weight_g'], 'parcel_template' => $quote['parcel_template'],
            'note' => $buyer['note'],
        ] + wsm_vies_columns($vies);
        $names = array_keys($cols);
        $sql = 'INSERT INTO wsm_orders (' . implode(',', $names) . ') VALUES (' .
               implode(',', array_fill(0, count($names), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($cols));
        $orderId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO wsm_order_items
               (order_id, product_id, name, sku, ean, qty, unit_net, unit_gross, vat_rate,
                line_net, line_vat, line_gross, weight_g)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($quote['lines'] as $l) {
            $ins->execute([$orderId, $l['id'], $l['name'], $l['sku'], $l['ean'], $l['qty'],
                $l['unit_net'], $l['unit_gross'], $l['vat_rate'],
                $l['line_net'], $l['line_vat'], $l['line_gross'], $l['weight_g']]);
        }

        $pdo->prepare(
            "INSERT INTO wsm_shipments (order_id, carrier, service, target_point, parcel_template, weight_g, status)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$orderId, 'inpost', $method, $buyer['inpost_point'],
                    $quote['parcel_template'], $quote['weight_g'], 'do_utworzenia']);

        wsm_order_event($pdo, $orderId, 'utworzone', $code . ' · ' . wsm_money($quote['total_gross']) . ' PLN', 'sklep');

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, ['db' => $ex->getMessage()]];
    }

    return [wsm_order_by_id($pdo, $orderId), []];
}

function wsm_order_event(PDO $pdo, int $orderId, string $event, string $detail = '', string $actor = ''): void {
    $pdo->prepare("INSERT INTO wsm_order_events (order_id, event, detail, actor) VALUES (?,?,?,?)")
        ->execute([$orderId, $event, mb_substr($detail, 0, 255), mb_substr($actor, 0, 120)]);
}

/** Montant en grosze → chaîne « 129,90 ». */
function wsm_money(int $grosze): string {
    return number_format($grosze / 100, 2, ',', ' ');
}

function wsm_order_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE id = ?");
    $st->execute([$id]);
    $o = $st->fetch();
    return $o ? wsm_order_hydrate($pdo, $o) : null;
}

/**
 * Relit une commande depuis son code + son jeton. Le jeton est comparé avec
 * hash_equals : sans lui, connaître un code de commande ne donne rien.
 */
function wsm_order_by_code(PDO $pdo, string $code, string $token): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE code = ?");
    $st->execute([$code]);
    $o = $st->fetch();
    if (!$o) return null;
    if ($token === '' || !hash_equals((string) $o['access_token'], $token)) return null;
    return wsm_order_hydrate($pdo, $o);
}

function wsm_order_hydrate(PDO $pdo, array $o): array {
    $id = (int) $o['id'];
    $st = $pdo->prepare("SELECT * FROM wsm_order_items WHERE order_id = ? ORDER BY id");
    $st->execute([$id]);
    $items = array_map(fn($r) => [
        'product_id' => $r['product_id'], 'name' => $r['name'], 'sku' => $r['sku'],
        'qty' => (int) $r['qty'], 'unit_gross' => (int) $r['unit_gross'], 'unit_net' => (int) $r['unit_net'],
        'vat_rate' => (float) $r['vat_rate'], 'line_net' => (int) $r['line_net'],
        'line_vat' => (int) $r['line_vat'], 'line_gross' => (int) $r['line_gross'],
    ], $st->fetchAll());

    $st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $ship = $st->fetch() ?: null;

    $st = $pdo->prepare("SELECT * FROM wsm_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $pay = $st->fetch() ?: null;

    return [
        'id' => $id, 'code' => (string) $o['code'], 'access_token' => (string) $o['access_token'],
        'lang' => (string) $o['lang'], 'currency' => (string) $o['currency'],
        'status' => (string) $o['status'], 'payment_status' => (string) $o['payment_status'],
        'email' => (string) $o['email'], 'phone' => (string) $o['phone'],
        'first_name' => (string) $o['first_name'], 'last_name' => (string) $o['last_name'],
        'company' => (string) $o['company'], 'nip' => (string) $o['nip'], 'invoice' => (int) $o['invoice'],
        'vat_eu' => (string) ($o['vat_eu'] ?? ''),
        'vat' => ['status' => (string) ($o['vat_status'] ?? ''), 'checked_at' => $o['vat_checked_at'] ?? null,
                  'name' => (string) ($o['vat_name'] ?? ''), 'consultation' => (string) ($o['vat_consultation'] ?? '')],
        'bill' => ['street' => $o['bill_street'], 'building' => $o['bill_building'],
                   'postcode' => $o['bill_postcode'], 'city' => $o['bill_city'], 'country' => $o['bill_country']],
        'delivery_method' => (string) $o['delivery_method'], 'inpost_point' => (string) $o['inpost_point'],
        'ship' => ['street' => $o['ship_street'], 'building' => $o['ship_building'],
                   'postcode' => $o['ship_postcode'], 'city' => $o['ship_city'], 'country' => $o['ship_country']],
        'items' => $items,
        'items_net' => (int) $o['items_net'], 'items_vat' => (int) $o['items_vat'], 'items_gross' => (int) $o['items_gross'],
        'shipping_net' => (int) $o['shipping_net'], 'shipping_vat' => (int) $o['shipping_vat'],
        'shipping_gross' => (int) $o['shipping_gross'],
        'total_net' => (int) $o['total_net'], 'total_vat' => (int) $o['total_vat'], 'total_gross' => (int) $o['total_gross'],
        'weight_g' => (int) $o['weight_g'], 'parcel_template' => (string) $o['parcel_template'],
        'note' => (string) ($o['note'] ?? ''),
        'created_at' => (string) $o['created_at'], 'paid_at' => $o['paid_at'],
        'shipment' => $ship ? ['service' => $ship['service'], 'target_point' => $ship['target_point'],
                               'parcel_template' => $ship['parcel_template'], 'status' => $ship['status'],
                               'tracking_number' => $ship['tracking_number'], 'label_url' => $ship['label_url']] : null,
        'payment' => $pay ? ['provider' => $pay['provider'], 'status' => $pay['status'],
                             'tr_id' => $pay['tr_id'], 'redirect_url' => $pay['redirect_url']] : null,
    ];
}

/** Marque une commande payée. Idempotent : rejouer la notification ne fait rien. */
function wsm_order_mark_paid(PDO $pdo, int $orderId, string $actor = 'tpay'): bool {
    $st = $pdo->prepare("SELECT payment_status FROM wsm_orders WHERE id = ?");
    $st->execute([$orderId]);
    $cur = (string) $st->fetchColumn();
    if ($cur === 'oplacone') return false;                    // déjà encaissée
    $pdo->prepare("UPDATE wsm_orders SET payment_status='oplacone', status='oplacone', paid_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$orderId]);
    $pdo->prepare("UPDATE wsm_payments SET status='oplacone' WHERE order_id=?")->execute([$orderId]);
    wsm_order_event($pdo, $orderId, 'oplacone', '', $actor);
    return true;
}

/** Liste pour le back-office. */
function wsm_orders_list(PDO $pdo, int $limit = 200): array {
    $rows = $pdo->query("SELECT * FROM wsm_orders ORDER BY id DESC LIMIT " . max(1, min(1000, $limit)))->fetchAll();
    return array_map(function ($o) use ($pdo) {
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(qty),0) FROM wsm_order_items WHERE order_id = ?");
        $st->execute([(int) $o['id']]);
        [$lines, $units] = $st->fetch(PDO::FETCH_NUM);
        return [
            'id' => (int) $o['id'], 'code' => $o['code'], 'created_at' => $o['created_at'],
            'client' => trim($o['first_name'] . ' ' . $o['last_name']) ?: $o['company'],
            'email' => $o['email'], 'phone' => $o['phone'],
            'status' => $o['status'], 'payment_status' => $o['payment_status'],
            'delivery_method' => $o['delivery_method'], 'inpost_point' => $o['inpost_point'],
            'lines' => (int) $lines, 'units' => (int) $units,
            'total_gross' => (int) $o['total_gross'], 'total_net' => (int) $o['total_net'],
            'weight_g' => (int) $o['weight_g'], 'parcel_template' => $o['parcel_template'],
            'invoice' => (int) $o['invoice'], 'nip' => $o['nip'],
        ];
    }, $rows);
}

/** Indicateurs de la boutique pour la console. */
function wsm_shop_kpis(PDO $pdo): array {
    $paid = "payment_status = 'oplacone'";
    $row = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_gross),0) s FROM wsm_orders")->fetch();
    $rowPaid = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_gross),0) s FROM wsm_orders WHERE $paid")->fetch();
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE payment_status = 'oczekuje'")->fetchColumn();
    $count = (int) $rowPaid['c'];
    return [
        'orders' => (int) $row['c'],
        'orders_paid' => $count,
        'orders_pending' => $pending,
        'revenue_gross' => (int) $rowPaid['s'],
        'basket_avg' => $count > 0 ? (int) round(((int) $rowPaid['s']) / $count) : 0,
    ];
}
