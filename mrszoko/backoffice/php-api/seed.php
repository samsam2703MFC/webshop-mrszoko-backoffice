<?php
// ============================================================================
//  seed.php — reference/demo data for webshop_mrszoko (engine-agnostic).
//  This is the ONLY place default business data lives on the server side; it
//  mirrors the front-end dev seed so behaviour is identical whether the API is
//  live or not. Runs once on a fresh database (see wsm_bootstrap()).
// ============================================================================

require_once __DIR__ . '/commerce.php';

function wsm_seed(PDO $pdo): void {
    $ins = function(string $table, array $row) use ($pdo) {
        $cols = array_keys($row);
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($row);
        return (int) $pdo->lastInsertId();
    };

    $pdo->beginTransaction();

    // ---- KPIs (dashboard snapshot) -----------------------------------------
    $kpis = [
        ['Obrót sieci (miesiąc)', '428 k€', 'var(--color-text)', '▲ +6,4 %', 'var(--success)'],
        ['Obrót sklepów', '306 k€', 'var(--color-primary)', '▲ +4,8 %', 'var(--success)'],
        ['Obrót dostaw biurowych', '122 k€', 'var(--accent)', '▲ +11 %', 'var(--success)'],
        ['Aktywne sklepy', '14 / 15', 'var(--color-text)', '▲ +1 w tym kw.', 'var(--success)'],
        ['Zamówienia dnia', '512', 'var(--color-text)', '▲ +38 vs wczoraj', 'var(--success)'],
        ['Adopcja whitelisty', '82 %', 'var(--color-text)', '▼ −3 pkt', 'var(--color-primary)'],
    ];
    foreach ($kpis as $i => $k) {
        $ins('wsm_kpis', ['sort_order' => $i, 'label' => $k[0], 'value' => $k[1],
            'val_color' => $k[2], 'delta' => $k[3], 'delta_color' => $k[4]]);
    }

    // ---- Shops --------------------------------------------------------------
    $shops = [
        ['bxl', "Mister Szoko — Bruxelles-Centre", 'Bruxelles 1000', 1, 'Oddział', 1, 29800, 8400, 96, 'var(--color-primary)'],
        ['and', "Mister Szoko — Anderlecht", 'Anderlecht 1070', 1, 'Franczyza', 1, 18600, 6200, 88, 'var(--caramel-400)'],
        ['ucc', "Mister Szoko — Uccle", 'Uccle 1180', 1, 'Franczyza', 1, 22100, 9400, 79, 'var(--choco-500)'],
        ['sch', "Mister Szoko — Schaerbeek", 'Schaerbeek 1030', 0, 'Franczyza', 1, 0, 0, 0, 'var(--caramel-400)'],
        ['lv', "Mister Szoko — Louvain", 'Louvain 3000', 1, 'Master', 0, 14200, 5200, 71, 'var(--choco-500)'],
    ];
    foreach ($shops as $i => $s) {
        $ins('wsm_shops', ['id' => $s[0], 'nom' => $s[1], 'ville' => $s[2], 'web' => $s[3],
            'contrat' => $s[4], 'act' => $s[5], 'ca_shop' => $s[6], 'ca_office' => $s[7],
            'adoption' => $s[8], 'accent' => $s[9], 'sort_order' => $i]);
    }

    // ---- Categories (menu_default from the menu seed) -----------------------
    $catDefaults = ['Menu i zestawy' => 1, 'Katering' => 1];
    $cats = ['Pieczywo', 'Ciasta świeże', 'Czekolada', 'Katering', 'Lody', 'Menu i zestawy'];
    $catId = [];
    foreach ($cats as $i => $name) {
        $catId[$name] = $ins('wsm_categories', ['name' => $name, 'sort_order' => $i,
            'menu_default' => $catDefaults[$name] ?? 0, 'brand_whitelist' => 1,
            'office_delivery' => ($name === 'Katering' ? 1 : 0), 'brand_mandatory' => 0, 'active' => 1]);
    }

    // ---- Products (catalogue + menu products) -------------------------------
    // [id, category, nom, prix, base_cost, statut, saison, bw, bm, ad, menu_override]
    $products = [
        ['p-baguette', 'Pieczywo', 'Bagietka tradycyjna', 1.35, 0.40, 'Opublikowany', '', 1, 1, 96, null, [280, 650, 90, 90]],
        ['p-pain-choco', 'Pieczywo', 'Czekoladowa drożdżówka', 1.60, 0.55, 'Opublikowany', '', 1, 0, 74, null, [90, 140, 90, 60]],
        ['p-eclair', 'Ciasta świeże', 'Ekler czekoladowy', 3.50, 1.20, 'Opublikowany', '', 1, 1, 88, null, [120, 160, 60, 50]],
        ['p-tarte-fraises', 'Ciasta świeże', 'Tarta truskawkowa', 4.20, 1.60, 'Sezonowy', 'Lato', 1, 0, 52, null, [850, 260, 260, 60]],
        ['p-buche', 'Ciasta świeże', 'Rolada firmowa', 24.00, 9.00, 'Opublikowany', 'Boże Narodzenie', 1, 1, 100, null, [1200, 350, 120, 100]],
        ['p-macarons', 'Czekolada', 'Makaroniki (pudełko 24)', 19.90, 7.50, 'Opublikowany', '', 1, 0, 64, null, [420, 250, 180, 60]],
        ['p-quiche', 'Katering', 'Quiche lorraine', 5.80, 2.20, 'Szkic', '', 0, 0, 22, null, [600, 240, 240, 45]],
        ['p-foiegras', 'Katering', 'Foie gras mi-cuit', 28.00, 12.00, 'Opublikowany', '', 1, 0, 41, null, [350, 150, 90, 70]],
        ['p-glace', 'Lody', 'Lody rzemieślnicze', 6.50, 2.10, 'Opublikowany', 'Lato', 0, 0, 30, null, [500, 120, 120, 110]],
        // menu products (menu builder)
        ['p-midi', 'Menu i zestawy', "Menu lunchowe — Mister Szoko", 8.50, 2.40, 'Opublikowany', '', 0, 0, 0, 'on'],
        ['p-gouter', 'Menu i zestawy', "Zestaw podwieczorkowy — Mister Szoko", 3.20, 0.90, 'Opublikowany', '', 0, 0, 0, 'on'],
        ['p-cafe', 'Menu i zestawy', "Café Gourmand — Mister Szoko", 6.50, 2.10, 'Opublikowany', '', 0, 0, 0, 'off'],
        ['p-brunch', 'Menu i zestawy', "Brunch weekendowy — Mister Szoko", 18.00, 5.50, 'Opublikowany', '', 0, 0, 0, null],
    ];
    foreach ($products as $i => $p) {
        // Logistique InPost + TVA tpay : gabarit déduit des dimensions.
        $lg = $p[11] ?? [230, 0, 0, 0];        // [poids g, L, l, H en mm]
        $ins('wsm_products', ['id' => $p[0], 'category_id' => $catId[$p[1]], 'nom' => $p[2],
            'prix' => $p[3], 'base_cost' => $p[4], 'statut' => $p[5], 'saison' => $p[6],
            'brand_whitelist' => $p[7], 'brand_mandatory' => $p[8], 'adoption' => $p[9],
            'menu_override' => $p[10], 'sort_order' => $i, 'active' => 1,
            'sku' => strtoupper(str_replace('p-', 'MS-', $p[0])), 'ean' => '',
            'vat_rate' => 0.23, 'weight_g' => $lg[0],
            'length_mm' => $lg[1], 'width_mm' => $lg[2], 'height_mm' => $lg[3],
            'parcel_template' => wsm_inpost_template($lg[1], $lg[2], $lg[3])]);
    }

    // ---- Menu builder tree (bundles → slots → choices) ----------------------
    // p-midi
    wsm_seed_bundle($ins, 'p-midi', 'b1', 'Zestaw pełny', 'Danie + napój + deser do wyboru', 4.50, 0, [
        ['s1', 'Danie', 1, 'single', 1, 1, 0, [
            ['c1', 'Quiche lorraine', 'a', 0, 1.10, 0], ['c2', 'Tost firmowy', 'b', 1.50, 1.60, 1], ['c3', 'Sałatka Cezar', 'd', 0, 1.30, 2]]],
        ['s2', 'Napój', 1, 'single', 1, 1, 1, [
            ['c4', 'Woda niegazowana 50cl', '', 0, 0.30, 0], ['c5', 'Napój 33cl', '', 0.50, 0.45, 1], ['c6', 'Świeżo wyciskany sok', 'c', 1.20, 0.90, 2]]],
        ['s3', 'Dodatki dla łasuchów', 0, 'multi', 0, 2, 2, [
            ['c7', 'Domowe ciastko', 'a', 2.00, 0.70, 0], ['c8', 'Kawałek tarty', 'b', 2.80, 1.10, 1], ['c9', 'Café gourmand', '', 3.20, 1.40, 2, 0]]],
    ]);
    wsm_seed_bundle($ins, 'p-midi', 'b2', 'Zestaw dziecięcy', 'Małe danie + syrop + niespodzianka', -1.00, 1, [
        ['s4', 'Małe danie', 1, 'single', 1, 1, 0, [
            ['c10', 'Mini tost', 'b', 0, 0.90, 0], ['c11', 'Domowe nuggetsy', 'a', 0, 1.10, 1]]],
        ['s5', 'Napój', 1, 'single', 1, 1, 1, [
            ['c12', "Syrop z wodą", '', 0, 0.20, 0], ['c13', 'Sok jabłkowy', 'c', 0, 0.40, 1]]],
    ]);
    // p-gouter
    wsm_seed_bundle($ins, 'p-gouter', 'gb1', 'Duet podwieczorkowy', 'Wypiek + gorący napój', 1.20, 0, [
        ['gs1', 'Wypiek', 1, 'single', 1, 1, 0, [
            ['gc1', 'Czekoladowa drożdżówka', 'b', 0, 0.50, 0], ['gc2', 'Rogalik migdałowy', 'a', 0.60, 0.65, 1]]],
        ['gs2', 'Gorący napój', 1, 'single', 1, 1, 1, [
            ['gc3', 'Kawa', '', 0, 0.35, 0], ['gc4', 'Gorąca czekolada', 'd', 0.50, 0.55, 1]]],
    ]);

    // ---- Vouchers -----------------------------------------------------------
    foreach ([
        ['MARQUE15', '−15 % na ciasta', 'Koszyk', 'kampania letnia'],
        ['BIENVENUE', 'Onboarding B2B', 'add_office', 'bezterminowo'],
        ['RENTREE10', '−10 € od 50 €', 'Kwota', 'wrz.'],
    ] as $v) $ins('wsm_vouchers', ['code' => $v[0], 'valeur' => $v[1], 'type' => $v[2], 'validite' => $v[3]]);

    // ---- Pricing rules ------------------------------------------------------
    foreach ([
        ['Wiosenne menu marki', 'Menu', '19,90 €'],
        ['Cennik sieciowy ciast', 'Ciasta świeże', 'cena stała'],
        ['Happy hour sieci', 'Pieczywo 18–19', '−20 %'],
    ] as $r) $ins('wsm_pricing_rules', ['nom' => $r[0], 'cible' => $r[1], 'effet' => $r[2], 'shop_id' => null]);

    // ---- Params -------------------------------------------------------------
    foreach ([
        ['admin.schema_reports', 'bool', '1'],
        ['webshop.enabled', 'bool', '1'],
        ['nav.icon_back', 'text', 'arrow-left'],
        ['delivery.enabled', 'bool', '1'],
        ['order.cutoff_default', 'text', '17:00'],
        ['brand.support_url', 'text', 'https://pomoc.misterszoko.com'],
    ] as $p) $ins('wsm_params', ['cle' => $p[0], 'type' => $p[1], 'val' => $p[2]]);

    // ---- Email templates ----------------------------------------------------
    foreach ([
        ['order_confirm', 'PL', 'Twoje zamówienie {{commande_ref}} jest potwierdzone'],
        ['order_ready', 'PL', 'Twoje zamówienie jest gotowe'],
        ['invoice', 'PL', 'Faktura {{commande_ref}}'],
        ['office_onboarding', 'PL', 'Witamy — Twoje konto {{bureau}}'],
        ['office_reject', 'PL', 'Twój wniosek o przyłączenie'],
        ['delivery_confirmed', 'PL', 'Twoja dostawa {{livraison_ref}} jest potwierdzona'],
    ] as $t) $ins('wsm_email_templates', ['cle' => $t[0], 'langue' => $t[1], 'sujet' => $t[2]]);

    // ---- Users + user↔shop scopes -------------------------------------------
    $users = [
        ['Sophie Renard', 'sophie.renard@misterszoko.com', 'Centrala', 'Cała sieć', 1, []],
        ['Thomas Legrand', 'thomas.legrand@misterszoko.com', 'Franczyza', 'Bruxelles-Centre', 1, ['bxl']],
        ['Marek Kowalski', 'm.kowalski@misterszoko.com', 'Franczyza', 'Anderlecht, Uccle', 1, ['and', 'ucc']],
        ['Julie Peeters', 'j.peeters@misterszoko.com', 'Franczyza', 'Louvain', 0, ['lv']],
    ];
    foreach ($users as $u) {
        $uid = $ins('wsm_users', ['nom' => $u[0], 'email' => $u[1], 'role' => $u[2], 'portee' => $u[3], 'act' => $u[4]]);
        foreach ($u[5] as $sid) $ins('wsm_user_shops', ['user_id' => $uid, 'shop_id' => $sid]);
    }

    // ---- Audit --------------------------------------------------------------
    foreach ([
        ['17/07 14:22', 'Sophie Renard', 'Zmiana', 'wsm_products #128 (brand_mandatory)', 'Sieć'],
        ['17/07 13:05', 'Thomas Legrand', 'Utworzenie', 'wsm_vouchers BXL10', 'Bruxelles-Centre'],
        ['17/07 11:40', 'Sophie Renard', 'Zmiana', 'wsm_params webshop.enabled', 'Sieć'],
        ['16/07 18:12', 'Marek Kowalski', 'Usunięcie', 'wsm_deliveries #44', 'Anderlecht'],
        ['16/07 09:30', 'Sophie Renard', 'Utworzenie', 'wsm_users j.peeters', 'Louvain'],
    ] as $a) $ins('wsm_audit', ['ts' => $a[0], 'user' => $a[1], 'verb' => $a[2], 'entity' => $a[3], 'shop' => $a[4]]);

    // ---- Catchment ----------------------------------------------------------
    foreach ([
        ['Bruxelles Capitale (19 gmin)', '1000 · 1020 · 1030 · 1040 · 1050', 1, 1, null],
        ['Brabant flamand — peryferie', '1600 · 1700 · 1800 · 3000', 1, 1, null],
    ] as $z) $ins('wsm_catchment', ['name' => $z[0], 'postcodes' => $z[1], 'exclusive' => $z[2], 'active' => $z[3], 'shop_id' => $z[4]]);

    // ---- Delivery module ----------------------------------------------------
    // Clients + their delivery points
    $clients = [
        ['CL-0021', 'Le Cirio SA', 'horeca', 'aktywny', 'BE 0421.111.222', '30 dni koniec mies.', 6000, 3200, '250 €', '8 %', 'Miesięczna',
            ['Brasserie — wejście od tyłu', 'Rue de la Bourse 18, 1000 Bruxelles', '08:00–11:00', 'Pn Wt Śr Cz Pt So', 'QR', 230, 50.8481, 4.3520, ['inpost_locker', 'WAW01M', 'Marszałkowska', '104', '00-026', 'Warszawa', '512340011', 'zamowienia@lecirio.pl']],
            ['firma', 'zamowienia@lecirio.pl', '512340011', '', '', '5252248481', 'PL5252248481', 'Marszałkowska', '104', '00-026', 'Warszawa', 'PL']],
        ['CL-0044', 'Rocco Forte', 'horeca', 'aktywny', 'BE 0455.222.333', '30 dni', 8000, 2600, '300 €', '10 %', 'Tygodniowa',
            ['Kuchnia — rampa serwisowa', "Rue de l'Amigo 1-3, 1000 Bruxelles", '07:30–10:00', 'Pn Wt Śr Cz Pt', 'PIN', 205, 50.8455, 4.3519, ['inpost_courier', '', 'Floriańska', '12', '31-019', 'Kraków', '512340022', 'kuchnia@roccoforte.pl']],
            ['firma', 'kuchnia@roccoforte.pl', '512340022', '', '', '6751745962', 'PL6751745962', 'Floriańska', '12', '31-019', 'Kraków', 'PL']],
        ['CL-0052', 'Belga SPRL', 'horeca', 'zawieszony', 'BE 0466.333.444', '7 dni', 4000, 4120, '—', '5 %', 'Za dostawę',
            ['Taras — dostęp Flagey', 'Place Eugène Flagey 18, 1050 Ixelles', '09:00–11:30', 'Wt Śr Cz Pt So', 'Podpis', 60, 50.8275, 4.3705, ['inpost_locker', 'LOD24A', 'Piotrkowska', '58', '90-105', 'Łódź', '512340033', 'biuro@belga.pl']],
            ['firma', 'biuro@belga.pl', '512340033', '', '', '9542752600', 'PL9542752600', 'Piotrkowska', '58', '90-105', 'Łódź', 'PL']],
        ['CL-0060', 'Dandoy', 'retail', 'aktywny', 'BE 0401.444.555', '30 dni', 5000, 1900, '200 €', '6 %', 'Miesięczna',
            ['Sklep Sablon — tył', 'Rue Charles Buls 14, 1000 Bruxelles', '08:00–10:30', 'Pn Śr Pt', 'QR', 180, 50.8459, 4.3524, ['inpost_courier', '', 'Długa', '7', '80-827', 'Gdańsk', '512340044', 'sklep@dandoy.pl']],
            ['firma', 'sklep@dandoy.pl', '512340044', '', '', '5213017228', 'PL5213017228', 'Długa', '7', '80-827', 'Gdańsk', 'PL']],
        ['CL-0071', 'KBC Group', 'corporate', 'aktywny', 'BE 0403.227.515', '30 dni koniec mies.', 12000, 5400, '400 €', '12 %', 'Miesięczna',
            ['Kafeteria HQ — hala dostaw', 'Havenlaan 2, 3000 Leuven', '07:00–09:00', 'Pn Wt Śr Cz Pt', 'PIN', -15, 50.8798, 4.7005, ['inpost_courier', '', 'Świdnicka', '40', '50-024', 'Wrocław', '512340055', 'kafeteria@kbc.pl']],
            ['firma', 'kafeteria@kbc.pl', '512340055', '', '', '5252248481', 'PL5252248481', 'Świdnicka', '40', '50-024', 'Wrocław', 'PL']],
        ['CL-0088', 'Événements Sud', 'event', 'prospekt', 'BE 0788.555.666', 'Gotówka', 2000, 0, '—', '0 %', 'Za dostawę',
            ['Zamek — dostęp kateringu', 'Chaussée de Bruxelles 100, 1410 Waterloo', '11:00–13:00', 'So Nd', 'Zostawienie', -78, 50.7147, 4.3990, ['inpost_locker', 'POZ103', 'Zamkowa', '3', '61-768', 'Poznań', '512340066', 'anna.nowak@example.pl']],
            ['osoba', 'anna.nowak@example.pl', '512340066', 'Anna', 'Nowak', '', '', 'Zamkowa', '3', '61-768', 'Poznań', 'PL']],
    ];
    $pointId = [];  // client code → point id
    foreach ($clients as $c) {
        // Champs tpay (payeur + facture) / InPost (destinataire) — NIP à somme
        // de contrôle valide, téléphones à 9 chiffres, codes postaux NN-NNN.
        $x = $c[12] ?? [];
        $cid = $ins('wsm_clients', ['code' => $c[0], 'raison' => $c[1], 'seg' => $c[2], 'statut' => $c[3],
            'tva' => $c[4], 'paiement' => $c[5], 'plafond' => $c[6], 'encours' => $c[7],
            'franco' => $c[8], 'remise' => $c[9], 'fact' => $c[10],
            'client_type' => $x[0] ?? 'firma', 'email' => $x[1] ?? '', 'phone' => $x[2] ?? '',
            'first_name' => $x[3] ?? '', 'last_name' => $x[4] ?? '', 'nip' => $x[5] ?? '',
            'vat_eu' => $x[6] ?? '', 'bill_street' => $x[7] ?? '', 'bill_building' => $x[8] ?? '',
            'bill_postcode' => $x[9] ?? '', 'bill_city' => $x[10] ?? '', 'bill_country' => $x[11] ?? 'PL']);
        $pt = $c[11];
        $px = $pt[8] ?? [];
        $pid = $ins('wsm_client_points', ['client_id' => $cid, 'libelle' => $pt[0], 'adresse' => $pt[1],
            'fenetre' => $pt[2], 'jours' => $pt[3], 'validation' => $pt[4], 'marge' => $pt[5],
            'lat' => $pt[6], 'lng' => $pt[7],
            'delivery_method' => $px[0] ?? 'inpost_courier', 'inpost_point' => $px[1] ?? '',
            'street' => $px[2] ?? '', 'building' => $px[3] ?? '', 'postcode' => $px[4] ?? '',
            'city' => $px[5] ?? '', 'country' => 'PL',
            'contact_phone' => $px[6] ?? '', 'contact_email' => $px[7] ?? '']);
        $pointId[$c[0]] = ['point' => $pid, 'client' => $cid, 'window' => $pt[2], 'validation' => $pt[4]];
    }

    // Drivers
    $driverId = [];
    foreach ([
        ['Marek Kowalski', 'BXL-Centre · Renault chłodnia', 'var(--brand)', 'Renault Master chłodnia', 'Bruxelles-Centre'],
        ['Julien Dubois', 'Południe · Iveco Daily', 'var(--berry-600)', 'Iveco Daily', 'Południe'],
        ['Sofie Peeters', 'Wschód · Renault Kangoo', 'var(--success)', 'Renault Kangoo', 'Wschód'],
    ] as $d) {
        $driverId[$d[0]] = $ins('wsm_drivers', ['nom' => $d[0], 'info' => $d[1], 'color' => $d[2],
            'vehicule' => $d[3], 'zone' => $d[4], 'active' => 1]);
    }

    // Rounds
    $roundId = [];
    foreach ([
        ['Trasa Bruxelles-Centre', 'Marek Kowalski'],
        ['Trasa Południe', 'Julien Dubois'],
        ['Trasa Wschód', 'Sofie Peeters'],
    ] as $r) {
        $roundId[$r[0]] = $ins('wsm_rounds', ['name' => $r[0], 'driver_id' => $driverId[$r[1]] ?? null,
            'round_date' => null, 'status' => 'planifiée']);
    }

    // Deliveries (a few, in different statuses) + their event trails
    $mkDelivery = function(array $d) use ($ins, $pointId, $driverId, $roundId) {
        $c = $pointId[$d['client']];
        $did = $ins('wsm_deliveries', [
            'ref' => $d['ref'], 'client_id' => $c['client'], 'point_id' => $c['point'],
            'driver_id' => $d['driver'] ? ($driverId[$d['driver']] ?? null) : null,
            'round_id' => $d['round'] ? ($roundId[$d['round']] ?? null) : null,
            'status' => $d['status'], 'window_label' => $c['window'],
            'validation_method' => $c['validation'], 'confirm_code' => $d['code'] ?? '',
            'confirmed' => $d['confirmed'] ?? 0, 'ca' => $d['ca'], 'couts' => $d['couts'],
            'scheduled_date' => null, 'notes' => $d['notes'] ?? '',
            'confirmed_at' => ($d['confirmed'] ?? 0) ? '2026-07-17 09:40:00' : null,
        ]);
        foreach ($d['events'] as $e) {
            $ins('wsm_delivery_events', ['delivery_id' => $did, 'event' => $e[0], 'detail' => $e[1], 'actor' => $e[2]]);
        }
        return $did;
    };
    $mkDelivery(['ref' => 'LIV-2026-0001', 'client' => 'CL-0021', 'driver' => 'Marek Kowalski', 'round' => 'Trasa Bruxelles-Centre',
        'status' => 'livrée', 'confirmed' => 1, 'code' => 'QR-8842', 'ca' => 520, 'couts' => 210,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Wyjazd na trasę BXL-Centre', 'Marek Kowalski'], ['livrée', 'Potwierdzona przez QR-8842', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0002', 'client' => 'CL-0044', 'driver' => 'Marek Kowalski', 'round' => 'Trasa Bruxelles-Centre',
        'status' => 'en_cours', 'ca' => 300, 'couts' => 150,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Wyjazd na trasę BXL-Centre', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0003', 'client' => 'CL-0060', 'driver' => 'Julien Dubois', 'round' => 'Trasa Południe',
        'status' => 'assignée', 'ca' => 415, 'couts' => 260,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Julien Dubois', 'Sophie Renard']]]);
    $mkDelivery(['ref' => 'LIV-2026-0004', 'client' => 'CL-0071', 'driver' => null, 'round' => null,
        'status' => 'planifiée', 'ca' => 580, 'couts' => 312,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard']]]);

    // Incidents
    foreach ([
        ['INC-2026-0412', 'LIV-2026-0002', 'Uszkodzona paczka', 'Café Belga · Ixelles', 'Do obsłużenia', '24 €',
            'Pojemnik izotermiczny uderzony przy rozładunku. 2 słoiki konfitur stłuczone.', '50.8275, 4.3705'],
        ['INC-2026-0411', null, 'Brakująca paczka', 'Hôtel Amigo · Sablon', 'Do obsłużenia', '46 €',
            '1 oczekiwana paczka nieobecna przy skanie zdawczym.', '50.8451, 4.3520'],
        ['INC-2026-0407', null, 'Dostawa odrzucona', 'Event Château · Waterloo', 'W trakcie', '40 €',
            'Przyjazd poza oknem czasowym. Klient nieobecny, zostawienie odrzucone.', '50.7147, 4.3990'],
        ['INC-2026-0403', 'LIV-2026-0001', 'Zwrot kaucji', 'Maison Dandoy · Sablon', 'Rozwiązany', '0 €',
            '3 pojemniki kaucyjne odebrane w punkcie. Uzgodnienie OK.', '50.8410, 4.3560'],
    ] as $inc) {
        $did = null;
        if ($inc[1]) {
            $q = $pdo->prepare('SELECT id FROM wsm_deliveries WHERE ref = ?');
            $q->execute([$inc[1]]);
            $did = $q->fetchColumn() ?: null;
        }
        $ins('wsm_incidents', ['ref' => $inc[0], 'delivery_id' => $did, 'type' => $inc[2], 'point' => $inc[3],
            'statut' => $inc[4], 'impact' => $inc[5], 'description' => $inc[6], 'geo' => $inc[7]]);
    }

    $pdo->commit();

    wsm_seed_landing($pdo);
}

/** Helper: insert one bundle + its slots + choices. */
function wsm_seed_bundle(callable $ins, string $pid, string $bid, string $name, string $desc, float $mod, int $order, array $slots): void {
    $ins('wsm_bundles', ['id' => $bid, 'product_id' => $pid, 'name' => $name, 'description' => $desc,
        'price_modifier' => $mod, 'sort_order' => $order, 'active' => 1]);
    foreach ($slots as $s) {
        // [id, label, required, kind, min, max, order, choices]
        $ins('wsm_bundle_slots', ['id' => $s[0], 'bundle_id' => $bid, 'label' => $s[1], 'required' => $s[2],
            'kind' => $s[3], 'min_select' => $s[4], 'max_select' => $s[5], 'sort_order' => $s[6], 'active' => 1]);
        foreach ($s[7] as $c) {
            // [id, label, img, delta, cost, order, active?]
            $ins('wsm_bundle_slot_choices', ['id' => $c[0], 'slot_id' => $s[0], 'label' => $c[1], 'img' => $c[2],
                'delta' => $c[3], 'cost' => $c[4], 'sort_order' => $c[5], 'active' => $c[6] ?? 1]);
        }
    }
}

/**
 * Landing Mister Szoko — peuple wsm_landing_i18n + wsm_landing_products depuis
 * la SOURCE UNIQUE landing/content_seed.json (aucun texte en dur, ni ici ni
 * dans la page ; app.js utilise le même fichier comme repli hors-API).
 * Idempotent : vide puis réinsère les deux tables (contenu éditorial pur).
 * Chemin identique en repo et sur le serveur : ../../landing depuis php-api.
 */
function wsm_seed_landing(PDO $pdo): void {
    $file = __DIR__ . '/../../landing/content_seed.json';
    if (!is_file($file)) return; // pas de landing embarquée (ex: API seule) → tables vides
    $doc = json_decode((string) file_get_contents($file), true);
    if (!is_array($doc) || empty($doc['strings'])) return;

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM wsm_landing_i18n');
    $pdo->exec('DELETE FROM wsm_landing_products');

    $si = $pdo->prepare('INSERT INTO wsm_landing_i18n (lang, k, v) VALUES (?,?,?)');
    foreach ($doc['strings'] as $lang => $pairs) {
        foreach ($pairs as $k => $v) $si->execute([$lang, $k, (string) $v]);
    }

    $pi = $pdo->prepare('INSERT INTO wsm_landing_products
        (id, sort_order, swatch_from, swatch_to, fluidity, active,
         price_from_pln, price_perkg_pln, price_from_eur, price_perkg_eur)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($doc['products'] ?? [] as $p) {
        $pi->execute([$p['id'], $p['sort_order'] ?? 0,
            $p['swatch_from'] ?? '--choco-900', $p['swatch_to'] ?? '--choco-700',
            $p['fluidity'] ?? 3, $p['active'] ?? 1,
            $p['price_from_pln'] ?? null, $p['price_perkg_pln'] ?? null,
            $p['price_from_eur'] ?? null, $p['price_perkg_eur'] ?? null]);
    }
    $pdo->commit();
}

/**
 * Boutique en ligne — peuple wsm_shop_i18n, wsm_shipping_methods et les
 * colonnes vitrine de wsm_products depuis la SOURCE UNIQUE
 * shop/content_seed.json. Même principe que la landing : un seul fichier
 * décrit le contenu, la base le sert, les pages ne contiennent aucun libellé.
 *
 * Idempotent et NON destructif pour les commandes : les textes et les tarifs
 * sont remplacés, les produits sont créés ou mis à jour — jamais supprimés,
 * parce qu'une ligne de commande passée y fait référence.
 */
function wsm_seed_shop(PDO $pdo): void {
    require_once __DIR__ . '/commerce.php';               // wsm_inpost_template()

    $file = __DIR__ . '/../../shop/content_seed.json';
    if (!is_file($file)) return;
    $doc = json_decode((string) file_get_contents($file), true);
    if (!is_array($doc) || empty($doc['strings'])) return;

    $pdo->beginTransaction();

    // --- Textes -------------------------------------------------------------
    $pdo->exec('DELETE FROM wsm_shop_i18n');
    $si = $pdo->prepare('INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)');
    foreach ($doc['strings'] as $lang => $pairs) {
        foreach ($pairs as $k => $v) $si->execute([$lang, $k, (string) $v]);
    }

    // --- Modes de livraison -------------------------------------------------
    $pdo->exec('DELETE FROM wsm_shipping_methods');
    $sm = $pdo->prepare('INSERT INTO wsm_shipping_methods
        (id, carrier, sort_order, active, price_net, vat_rate, free_from, max_weight_g)
        VALUES (?,?,?,?,?,?,?,?)');
    foreach ($doc['shipping'] ?? [] as $m) {
        $sm->execute([$m['id'], $m['carrier'] ?? 'inpost', $m['sort_order'] ?? 0, $m['active'] ?? 1,
            $m['price_net'] ?? 0, $m['vat_rate'] ?? 0.23, $m['free_from'] ?? 0, $m['max_weight_g'] ?? 25000]);
    }

    // --- Produits vendus en ligne ------------------------------------------
    $lang0 = $doc['default'] ?? 'pl';
    foreach ($doc['products'] ?? [] as $p) {
        $catName = (string) ($p['category'] ?? 'Czekolada');
        $st = $pdo->prepare('SELECT id FROM wsm_categories WHERE name = ?');
        $st->execute([$catName]);
        $catId = (int) $st->fetchColumn();
        if (!$catId) {
            $pdo->prepare('INSERT INTO wsm_categories (name, sort_order, active) VALUES (?,?,1)')
                ->execute([$catName, 99]);
            $catId = (int) $pdo->lastInsertId();
        }

        $shop = [
            'slug' => $p['slug'] ?? $p['id'], 'shop_visible' => 1, 'stock' => $p['stock'] ?? 0,
            'image_url' => $p['image_url'] ?? '',
            'swatch_from' => $p['swatch_from'] ?? '--choco-500', 'swatch_to' => $p['swatch_to'] ?? '--choco-800',
            'origin' => $p['origin'] ?? '', 'cocoa' => $p['cocoa'] ?? '',
            'unit_label' => $p['unit_label'] ?? '', 'badge' => $p['badge'] ?? '',
            'sku' => $p['sku'] ?? '', 'ean' => $p['ean'] ?? '',
            'vat_rate' => $p['vat_rate'] ?? 0.23, 'weight_g' => $p['weight_g'] ?? 0,
            'length_mm' => $p['length_mm'] ?? 0, 'width_mm' => $p['width_mm'] ?? 0, 'height_mm' => $p['height_mm'] ?? 0,
            'parcel_template' => wsm_inpost_template((int) ($p['length_mm'] ?? 0), (int) ($p['width_mm'] ?? 0), (int) ($p['height_mm'] ?? 0)),
            'category_id' => $catId,
            'nom' => $doc['strings'][$lang0]['product.' . $p['id'] . '.name'] ?? $p['id'],
            'prix' => $p['price'] ?? 0, 'statut' => 'Opublikowany',
            'sort_order' => $p['sort_order'] ?? 0, 'active' => 1,
        ];

        $st = $pdo->prepare('SELECT 1 FROM wsm_products WHERE id = ?');
        $st->execute([$p['id']]);
        if ($st->fetchColumn()) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($shop)));
            $pdo->prepare("UPDATE wsm_products SET $set WHERE id = ?")
                ->execute([...array_values($shop), $p['id']]);
        } else {
            $cols = array_merge(['id' => $p['id']], $shop);
            $pdo->prepare('INSERT INTO wsm_products (' . implode(',', array_keys($cols)) . ') VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($cols));
        }
    }

    $pdo->commit();
}

/**
 * Complète les tables de contenu avec les clés ABSENTES, sans toucher aux
 * existantes. C'est ce qui permet de livrer un nouveau libellé (un lien, un
 * bouton) sans effacer les retouches faites depuis la console : le seed
 * complet, lui, remplace tout et n'est joué qu'à la création de la base.
 *
 * @return array [clés i18n ajoutées, méthodes de livraison ajoutées]
 */
function wsm_sync_content(PDO $pdo): array {
    $added = 0; $ship = 0;

    $sync = function (string $table, string $file) use ($pdo, &$added): void {
        if (!is_file($file)) return;
        $doc = json_decode((string) file_get_contents($file), true);
        if (!is_array($doc) || empty($doc['strings'])) return;
        $have = [];
        foreach ($pdo->query("SELECT lang, k FROM $table")->fetchAll() as $r) {
            $have[$r['lang'] . "\0" . $r['k']] = true;
        }
        $ins = $pdo->prepare("INSERT INTO $table (lang, k, v) VALUES (?,?,?)");
        foreach ($doc['strings'] as $lang => $pairs) {
            foreach ($pairs as $k => $v) {
                if (isset($have[$lang . "\0" . $k])) continue;
                $ins->execute([$lang, $k, (string) $v]);
                $added++;
            }
        }
    };

    $sync('wsm_landing_i18n', __DIR__ . '/../../landing/content_seed.json');
    $sync('wsm_shop_i18n',    __DIR__ . '/../../shop/content_seed.json');

    // Modes de livraison : ajoutés s'ils manquent, jamais retarifés d'office —
    // un prix de port se décide en console, pas au déploiement.
    $shopFile = __DIR__ . '/../../shop/content_seed.json';
    if (is_file($shopFile)) {
        $doc = json_decode((string) file_get_contents($shopFile), true);
        $ins = $pdo->prepare('INSERT INTO wsm_shipping_methods
            (id, carrier, sort_order, active, price_net, vat_rate, free_from, max_weight_g)
            VALUES (?,?,?,?,?,?,?,?)');
        foreach ($doc['shipping'] ?? [] as $m) {
            $st = $pdo->prepare('SELECT 1 FROM wsm_shipping_methods WHERE id = ?');
            $st->execute([$m['id']]);
            if ($st->fetchColumn()) continue;
            $ins->execute([$m['id'], $m['carrier'] ?? 'inpost', $m['sort_order'] ?? 0, $m['active'] ?? 1,
                $m['price_net'] ?? 0, $m['vat_rate'] ?? 0.23, $m['free_from'] ?? 0, $m['max_weight_g'] ?? 25000]);
            $ship++;
        }
    }
    return [$added, $ship];
}

/**
 * Pays de l'Union servis par la boutique. Seule la Pologne est ouverte au
 * départ : c'est le marché réel (InPost livre en Pologne). Le back-office
 * ouvre les autres quand l'expédition suit.
 *
 * `is_eu` n'est pas une étiquette : c'est lui qui autorise l'autoliquidation.
 */
function wsm_seed_countries(PDO $pdo): void {
    // code, pl, uk, en
    $eu = [
        ['PL', 'Polska', 'Польща', 'Poland'],
        ['AT', 'Austria', 'Австрія', 'Austria'],
        ['BE', 'Belgia', 'Бельгія', 'Belgium'],
        ['BG', 'Bułgaria', 'Болгарія', 'Bulgaria'],
        ['HR', 'Chorwacja', 'Хорватія', 'Croatia'],
        ['CY', 'Cypr', 'Кіпр', 'Cyprus'],
        ['CZ', 'Czechy', 'Чехія', 'Czechia'],
        ['DK', 'Dania', 'Данія', 'Denmark'],
        ['EE', 'Estonia', 'Естонія', 'Estonia'],
        ['FI', 'Finlandia', 'Фінляндія', 'Finland'],
        ['FR', 'Francja', 'Франція', 'France'],
        ['EL', 'Grecja', 'Греція', 'Greece'],
        ['ES', 'Hiszpania', 'Іспанія', 'Spain'],
        ['NL', 'Holandia', 'Нідерланди', 'Netherlands'],
        ['IE', 'Irlandia', 'Ірландія', 'Ireland'],
        ['LT', 'Litwa', 'Литва', 'Lithuania'],
        ['LU', 'Luksemburg', 'Люксембург', 'Luxembourg'],
        ['LV', 'Łotwa', 'Латвія', 'Latvia'],
        ['MT', 'Malta', 'Мальта', 'Malta'],
        ['DE', 'Niemcy', 'Німеччина', 'Germany'],
        ['PT', 'Portugalia', 'Португалія', 'Portugal'],
        ['RO', 'Rumunia', 'Румунія', 'Romania'],
        ['SK', 'Słowacja', 'Словаччина', 'Slovakia'],
        ['SI', 'Słowenia', 'Словенія', 'Slovenia'],
        ['SE', 'Szwecja', 'Швеція', 'Sweden'],
        ['HU', 'Węgry', 'Угорщина', 'Hungary'],
        ['IT', 'Włochy', 'Італія', 'Italy'],
    ];
    $ins = $pdo->prepare('INSERT INTO wsm_countries (code, name_pl, name_uk, name_en, is_eu, active, sort_order)
                          VALUES (?,?,?,?,1,?,?)');
    foreach ($eu as $i => [$c, $pl, $uk, $en]) {
        // La Pologne d'abord, et seule ouverte : on ne prétend pas livrer
        // ailleurs tant qu'aucun transporteur ne le fait.
        $ins->execute([$c, $pl, $uk, $en, $c === 'PL' ? 1 : 0, $c === 'PL' ? 0 : $i + 10]);
    }
    // Périmètre des transporteurs : InPost, c'est la Pologne.
    try { $pdo->exec("UPDATE wsm_shipping_methods SET countries = 'PL' WHERE countries = ''"); }
    catch (Throwable $e) { /* colonne pas encore migrée */ }
}

/**
 * Paliers de remise au poids. Reprennent la logique annoncée depuis le début :
 * le kilogramme baisse avec le format. Réglables ensuite en console.
 */
function wsm_seed_discounts(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT INTO wsm_discount_tiers (min_weight_g, percent, label, active) VALUES (?,?,?,1)');
    foreach ([[3000, 5.0, 'od 3 kg'], [10000, 12.0, 'od 10 kg'], [20000, 20.0, 'od 20 kg']] as [$g, $p, $l]) {
        $ins->execute([$g, $p, $l]);
    }
}

/**
 * Modèles de courrier. Semés une seule fois : dès que la console en modifie un,
 * c'est la base qui fait foi et un déploiement ne le réécrit plus.
 *
 * Le modèle « na_zamowienie » est celui qui tient la promesse commerciale :
 * une commande dépassant le stock passe quand même, et l'acheteur reçoit
 * immédiatement un mot disant qu'on le recontacte avec la date.
 */
function wsm_seed_mail_templates(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT INTO wsm_mail_templates (code, lang, name, subject, body, event, active)
                          VALUES (?,?,?,?,?,?,1)');

    $t = [];

    // ---- Commande reçue ----------------------------------------------------
    $t[] = ['zamowienie', 'pl', 'Potwierdzenie zamówienia', 'Zamówienie {{numer}} przyjęte',
"Dzień dobry {{imie}},

dziękujemy za zamówienie {{numer}} na kwotę {{kwota}}.

{{pozycje}}

Podgląd zamówienia: {{link}}

Odezwiemy się, gdy paczka ruszy w drogę.

Mister Szoko", 'zamowienie'];

    $t[] = ['zamowienie', 'uk', 'Підтвердження замовлення', 'Замовлення {{numer}} прийнято',
"Доброго дня, {{imie}}!

Дякуємо за замовлення {{numer}} на суму {{kwota}}.

{{pozycje}}

Перегляд замовлення: {{link}}

Ми напишемо, щойно посилка вирушить.

Mister Szoko", 'zamowienie'];

    $t[] = ['zamowienie', 'en', 'Order confirmation', 'Order {{numer}} received',
"Hello {{imie}},

thank you for order {{numer}}, total {{kwota}}.

{{pozycje}}

Order details: {{link}}

We will write again as soon as the parcel is on its way.

Mister Szoko", 'zamowienie'];

    // ---- Commande au-delà du stock ----------------------------------------
    $t[] = ['na_zamowienie', 'pl', 'Zamówienie ponad stan — kontakt', 'Zamówienie {{numer}} — skontaktujemy się mailowo',
"Dzień dobry {{imie}},

zamówienie {{numer}} zostało przyjęte. Część pozycji robimy dla Państwa
na świeżo, dlatego skontaktujemy się mailowo z terminem wysyłki:

{{brakujace}}

Reszta zamówienia czeka spakowana. Podgląd: {{link}}

Mister Szoko", 'na_zamowienie'];

    $t[] = ['na_zamowienie', 'uk', 'Замовлення понад запас — контакт', 'Замовлення {{numer}} — ми напишемо вам',
"Доброго дня, {{imie}}!

Замовлення {{numer}} прийнято. Частину позицій ми виготовляємо свіжими,
тож напишемо вам електронною поштою про строк відправлення:

{{brakujace}}

Решта замовлення вже спакована. Перегляд: {{link}}

Mister Szoko", 'na_zamowienie'];

    $t[] = ['na_zamowienie', 'en', 'Order beyond stock — contact', 'Order {{numer}} — we will contact you by e-mail',
"Hello {{imie}},

order {{numer}} has been accepted. Some items are made fresh for you,
so we will contact you by e-mail with the shipping date:

{{brakujace}}

The rest of your order is already packed. Details: {{link}}

Mister Szoko", 'na_zamowienie'];

    // ---- Paiement reçu -----------------------------------------------------
    $t[] = ['platnosc', 'pl', 'Płatność otrzymana', 'Płatność za {{numer}} zaksięgowana',
"Dzień dobry {{imie}},

potwierdzamy wpłatę {{kwota}} za zamówienie {{numer}}.
Zabieramy się do pakowania.

Mister Szoko", 'platnosc'];

    $t[] = ['platnosc', 'uk', 'Оплату отримано', 'Оплату за {{numer}} зараховано',
"Доброго дня, {{imie}}!

Підтверджуємо оплату {{kwota}} за замовлення {{numer}}.
Беремося пакувати.

Mister Szoko", 'platnosc'];

    $t[] = ['platnosc', 'en', 'Payment received', 'Payment for {{numer}} confirmed',
"Hello {{imie}},

we confirm your payment of {{kwota}} for order {{numer}}.
We are getting it packed.

Mister Szoko", 'platnosc'];

    // ---- Expédition --------------------------------------------------------
    $t[] = ['wysylka', 'pl', 'Przesyłka nadana', 'Zamówienie {{numer}} wysłane',
"Dzień dobry {{imie}},

paczka z zamówieniem {{numer}} jest w drodze.
Punkt odbioru: {{paczkomat}}

Śledzenie: {{link}}

Mister Szoko", 'wysylka'];

    $t[] = ['wysylka', 'uk', 'Посилку відправлено', 'Замовлення {{numer}} відправлено',
"Доброго дня, {{imie}}!

Посилка із замовленням {{numer}} у дорозі.
Пункт видачі: {{paczkomat}}

Відстеження: {{link}}

Mister Szoko", 'wysylka'];

    $t[] = ['wysylka', 'en', 'Parcel dispatched', 'Order {{numer}} dispatched',
"Hello {{imie}},

the parcel with order {{numer}} is on its way.
Pick-up point: {{paczkomat}}

Tracking: {{link}}

Mister Szoko", 'wysylka'];

    // ---- Modèle libre, sans événement : la réponse écrite à la main --------
    $t[] = ['kontakt', 'pl', 'Odpowiedź do klienta (pusty)', 'W sprawie zamówienia {{numer}}',
"Dzień dobry {{imie}},

Mister Szoko", ''];

    foreach ($t as $row) $ins->execute($row);
}
