<?php
// ============================================================================
//  seed.php — reference/demo data for webshop_mrszoko (engine-agnostic).
//  This is the ONLY place default business data lives on the server side; it
//  mirrors the front-end dev seed so behaviour is identical whether the API is
//  live or not. Runs once on a fresh database (see wsm_bootstrap()).
// ============================================================================

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
        ['CA réseau (mois)', '428 k€', 'var(--color-text)', '▲ +6,4 %', '#2d7a3e'],
        ['CA boutique', '306 k€', 'var(--color-primary)', '▲ +4,8 %', '#2d7a3e'],
        ['CA livraison bureau', '122 k€', '#C87A3F', '▲ +11 %', '#2d7a3e'],
        ['Boutiques actives', '14 / 15', 'var(--color-text)', '▲ +1 ce trim.', '#2d7a3e'],
        ['Commandes du jour', '512', 'var(--color-text)', '▲ +38 vs hier', '#2d7a3e'],
        ['Adoption whitelist', '82 %', 'var(--color-text)', '▼ −3 pts', 'var(--color-primary)'],
    ];
    foreach ($kpis as $i => $k) {
        $ins('wsm_kpis', ['sort_order' => $i, 'label' => $k[0], 'value' => $k[1],
            'val_color' => $k[2], 'delta' => $k[3], 'delta_color' => $k[4]]);
    }

    // ---- Shops --------------------------------------------------------------
    $shops = [
        ['bxl', "L'Atelier — Bruxelles-Centre", 'Bruxelles 1000', 1, 'Succursale', 1, 29800, 8400, 96, 'var(--color-primary)'],
        ['and', "L'Atelier — Anderlecht", 'Anderlecht 1070', 1, 'Franchise', 1, 18600, 6200, 88, '#E8A15C'],
        ['ucc', "L'Atelier — Uccle", 'Uccle 1180', 1, 'Franchise', 1, 22100, 9400, 79, '#8C4A2F'],
        ['sch', "L'Atelier — Schaerbeek", 'Schaerbeek 1030', 0, 'Franchise', 1, 0, 0, 0, '#E8A15C'],
        ['lv', "L'Atelier — Louvain", 'Louvain 3000', 1, 'Master', 0, 14200, 5200, 71, '#8C4A2F'],
    ];
    foreach ($shops as $i => $s) {
        $ins('wsm_shops', ['id' => $s[0], 'nom' => $s[1], 'ville' => $s[2], 'web' => $s[3],
            'contrat' => $s[4], 'act' => $s[5], 'ca_shop' => $s[6], 'ca_office' => $s[7],
            'adoption' => $s[8], 'accent' => $s[9], 'sort_order' => $i]);
    }

    // ---- Categories (menu_default from the menu seed) -----------------------
    $catDefaults = ['Menus & formules' => 1, 'Traiteur' => 1];
    $cats = ['Boulangerie', 'Pâtisserie fraîche', 'Chocolaterie', 'Traiteur', 'Glaces', 'Menus & formules'];
    $catId = [];
    foreach ($cats as $i => $name) {
        $catId[$name] = $ins('wsm_categories', ['name' => $name, 'sort_order' => $i,
            'menu_default' => $catDefaults[$name] ?? 0, 'brand_whitelist' => 1,
            'office_delivery' => ($name === 'Traiteur' ? 1 : 0), 'brand_mandatory' => 0, 'active' => 1]);
    }

    // ---- Products (catalogue + menu products) -------------------------------
    // [id, category, nom, prix, base_cost, statut, saison, bw, bm, ad, menu_override]
    $products = [
        ['p-baguette', 'Boulangerie', 'Baguette tradition', 1.35, 0.40, 'Publié', '', 1, 1, 96, null],
        ['p-pain-choco', 'Boulangerie', 'Pain au chocolat', 1.60, 0.55, 'Publié', '', 1, 0, 74, null],
        ['p-eclair', 'Pâtisserie fraîche', 'Éclair chocolat', 3.50, 1.20, 'Publié', '', 1, 1, 88, null],
        ['p-tarte-fraises', 'Pâtisserie fraîche', 'Tarte aux fraises', 4.20, 1.60, 'Saisonnier', 'Été', 1, 0, 52, null],
        ['p-buche', 'Pâtisserie fraîche', 'Bûche signature', 24.00, 9.00, 'Publié', 'Noël', 1, 1, 100, null],
        ['p-macarons', 'Chocolaterie', 'Macarons (boîte 24)', 19.90, 7.50, 'Publié', '', 1, 0, 64, null],
        ['p-quiche', 'Traiteur', 'Quiche lorraine', 5.80, 2.20, 'Brouillon', '', 0, 0, 22, null],
        ['p-foiegras', 'Traiteur', 'Foie gras mi-cuit', 28.00, 12.00, 'Publié', '', 1, 0, 41, null],
        ['p-glace', 'Glaces', 'Glace artisanale', 6.50, 2.10, 'Publié', 'Été', 0, 0, 30, null],
        // menu products (menu builder)
        ['p-midi', 'Menus & formules', "Menu du Midi — L'Atelier", 8.50, 2.40, 'Publié', '', 0, 0, 0, 'on'],
        ['p-gouter', 'Menus & formules', "Formule Goûter — L'Atelier", 3.20, 0.90, 'Publié', '', 0, 0, 0, 'on'],
        ['p-cafe', 'Menus & formules', "Café Gourmand — L'Atelier", 6.50, 2.10, 'Publié', '', 0, 0, 0, 'off'],
        ['p-brunch', 'Menus & formules', "Brunch du Week-end — L'Atelier", 18.00, 5.50, 'Publié', '', 0, 0, 0, null],
    ];
    foreach ($products as $i => $p) {
        $ins('wsm_products', ['id' => $p[0], 'category_id' => $catId[$p[1]], 'nom' => $p[2],
            'prix' => $p[3], 'base_cost' => $p[4], 'statut' => $p[5], 'saison' => $p[6],
            'brand_whitelist' => $p[7], 'brand_mandatory' => $p[8], 'adoption' => $p[9],
            'menu_override' => $p[10], 'sort_order' => $i, 'active' => 1]);
    }

    // ---- Menu builder tree (bundles → slots → choices) ----------------------
    // p-midi
    wsm_seed_bundle($ins, 'p-midi', 'b1', 'Formule Complète', 'Plat + boisson + dessert au choix', 4.50, 0, [
        ['s1', 'Le plat', 1, 'single', 1, 1, 0, [
            ['c1', 'Quiche lorraine', 'a', 0, 1.10, 0], ['c2', 'Croque signature', 'b', 1.50, 1.60, 1], ['c3', 'Salade César', 'd', 0, 1.30, 2]]],
        ['s2', 'La boisson', 1, 'single', 1, 1, 1, [
            ['c4', 'Eau plate 50cl', '', 0, 0.30, 0], ['c5', 'Soft 33cl', '', 0.50, 0.45, 1], ['c6', 'Jus pressé maison', 'c', 1.20, 0.90, 2]]],
        ['s3', 'Suppléments gourmands', 0, 'multi', 0, 2, 2, [
            ['c7', 'Cookie maison', 'a', 2.00, 0.70, 0], ['c8', 'Part de tarte', 'b', 2.80, 1.10, 1], ['c9', 'Café gourmand', '', 3.20, 1.40, 2, 0]]],
    ]);
    wsm_seed_bundle($ins, 'p-midi', 'b2', 'Formule Enfant', 'Petit plat + sirop + surprise', -1.00, 1, [
        ['s4', 'Le petit plat', 1, 'single', 1, 1, 0, [
            ['c10', 'Mini croque', 'b', 0, 0.90, 0], ['c11', 'Nuggets maison', 'a', 0, 1.10, 1]]],
        ['s5', 'La boisson', 1, 'single', 1, 1, 1, [
            ['c12', "Sirop à l'eau", '', 0, 0.20, 0], ['c13', 'Jus de pomme', 'c', 0, 0.40, 1]]],
    ]);
    // p-gouter
    wsm_seed_bundle($ins, 'p-gouter', 'gb1', 'Duo Goûter', 'Une viennoiserie + une boisson chaude', 1.20, 0, [
        ['gs1', 'La viennoiserie', 1, 'single', 1, 1, 0, [
            ['gc1', 'Pain au chocolat', 'b', 0, 0.50, 0], ['gc2', 'Croissant amandes', 'a', 0.60, 0.65, 1]]],
        ['gs2', 'La boisson chaude', 1, 'single', 1, 1, 1, [
            ['gc3', 'Café', '', 0, 0.35, 0], ['gc4', 'Chocolat chaud', 'd', 0.50, 0.55, 1]]],
    ]);

    // ---- Vouchers -----------------------------------------------------------
    foreach ([
        ['MARQUE15', '−15 % sur la pâtisserie', 'Panier', 'campagne été'],
        ['BIENVENUE', 'Onboarding B2B', 'add_office', 'permanent'],
        ['RENTREE10', '−10 € dès 50 €', 'Montant', 'sept.'],
    ] as $v) $ins('wsm_vouchers', ['code' => $v[0], 'valeur' => $v[1], 'type' => $v[2], 'validite' => $v[3]]);

    // ---- Pricing rules ------------------------------------------------------
    foreach ([
        ['Menu marque printemps', 'Menus', '19,90 €'],
        ['Tarif réseau pâtisserie', 'Pâtisserie fraîche', 'prix fixe'],
        ['Happy hour réseau', 'Boulangerie 18–19h', '−20 %'],
    ] as $r) $ins('wsm_pricing_rules', ['nom' => $r[0], 'cible' => $r[1], 'effet' => $r[2], 'shop_id' => null]);

    // ---- Params -------------------------------------------------------------
    foreach ([
        ['admin.schema_reports', 'bool', '1'],
        ['webshop.enabled', 'bool', '1'],
        ['nav.icon_back', 'text', 'arrow-left'],
        ['delivery.enabled', 'bool', '1'],
        ['order.cutoff_default', 'text', '17:00'],
        ['brand.support_url', 'text', 'https://aide.latelierby.be'],
    ] as $p) $ins('wsm_params', ['cle' => $p[0], 'type' => $p[1], 'val' => $p[2]]);

    // ---- Email templates ----------------------------------------------------
    foreach ([
        ['order_confirm', 'FR', 'Votre commande {{commande_ref}} est confirmée'],
        ['order_ready', 'FR', 'Votre commande est prête'],
        ['invoice', 'FR', 'Facture {{commande_ref}}'],
        ['office_onboarding', 'FR', 'Bienvenue — votre compte {{bureau}}'],
        ['office_reject', 'FR', 'Votre demande de rattachement'],
        ['delivery_confirmed', 'FR', 'Votre livraison {{livraison_ref}} est confirmée'],
    ] as $t) $ins('wsm_email_templates', ['cle' => $t[0], 'langue' => $t[1], 'sujet' => $t[2]]);

    // ---- Users + user↔shop scopes -------------------------------------------
    $users = [
        ['Sophie Renard', 'sophie.renard@latelierby.be', 'Siège', 'Réseau complet', 1, []],
        ['Thomas Legrand', 'thomas.legrand@latelierby.be', 'Franchise', 'Bruxelles-Centre', 1, ['bxl']],
        ['Marek Kowalski', 'm.kowalski@latelierby.be', 'Franchise', 'Anderlecht, Uccle', 1, ['and', 'ucc']],
        ['Julie Peeters', 'j.peeters@latelierby.be', 'Franchise', 'Louvain', 0, ['lv']],
    ];
    foreach ($users as $u) {
        $uid = $ins('wsm_users', ['nom' => $u[0], 'email' => $u[1], 'role' => $u[2], 'portee' => $u[3], 'act' => $u[4]]);
        foreach ($u[5] as $sid) $ins('wsm_user_shops', ['user_id' => $uid, 'shop_id' => $sid]);
    }

    // ---- Audit --------------------------------------------------------------
    foreach ([
        ['17/07 14:22', 'Sophie Renard', 'Modification', 'wsm_products #128 (brand_mandatory)', 'Réseau'],
        ['17/07 13:05', 'Thomas Legrand', 'Création', 'wsm_vouchers BXL10', 'Bruxelles-Centre'],
        ['17/07 11:40', 'Sophie Renard', 'Modification', 'wsm_params webshop.enabled', 'Réseau'],
        ['16/07 18:12', 'Marek Kowalski', 'Suppression', 'wsm_deliveries #44', 'Anderlecht'],
        ['16/07 09:30', 'Sophie Renard', 'Création', 'wsm_users j.peeters', 'Louvain'],
    ] as $a) $ins('wsm_audit', ['ts' => $a[0], 'user' => $a[1], 'verb' => $a[2], 'entity' => $a[3], 'shop' => $a[4]]);

    // ---- Catchment ----------------------------------------------------------
    foreach ([
        ['Bruxelles Capitale (19 communes)', '1000 · 1020 · 1030 · 1040 · 1050', 1, 1, null],
        ['Brabant flamand — périphérie', '1600 · 1700 · 1800 · 3000', 1, 1, null],
    ] as $z) $ins('wsm_catchment', ['name' => $z[0], 'postcodes' => $z[1], 'exclusive' => $z[2], 'active' => $z[3], 'shop_id' => $z[4]]);

    // ---- Delivery module ----------------------------------------------------
    // Clients + their delivery points
    $clients = [
        ['CL-0021', 'Le Cirio SA', 'horeca', 'actif', 'BE 0421.111.222', '30 j fin de mois', 6000, 3200, '250 €', '8 %', 'Mensuel',
            ['Brasserie — entrée arrière', 'Rue de la Bourse 18, 1000 Bruxelles', '08:00–11:00', 'L Ma Me J V S', 'QR', 230, 50.8481, 4.3520]],
        ['CL-0044', 'Rocco Forte', 'horeca', 'actif', 'BE 0455.222.333', '30 j', 8000, 2600, '300 €', '10 %', 'Hebdomadaire',
            ['Cuisine — quai de service', "Rue de l'Amigo 1-3, 1000 Bruxelles", '07:30–10:00', 'L Ma Me J V', 'PIN', 205, 50.8455, 4.3519]],
        ['CL-0052', 'Belga SPRL', 'horeca', 'suspendu', 'BE 0466.333.444', '7 j', 4000, 4120, '—', '5 %', 'Par livraison',
            ['Terrasse — accès Flagey', 'Place Eugène Flagey 18, 1050 Ixelles', '09:00–11:30', 'Ma Me J V S', 'Signature', 60, 50.8275, 4.3705]],
        ['CL-0060', 'Dandoy', 'retail', 'actif', 'BE 0401.444.555', '30 j', 5000, 1900, '200 €', '6 %', 'Mensuel',
            ['Boutique Sablon — arrière', 'Rue Charles Buls 14, 1000 Bruxelles', '08:00–10:30', 'L Me V', 'QR', 180, 50.8459, 4.3524]],
        ['CL-0071', 'KBC Group', 'corporate', 'actif', 'BE 0403.227.515', '30 j fin de mois', 12000, 5400, '400 €', '12 %', 'Mensuel',
            ['Cafétéria HQ — hall livraison', 'Havenlaan 2, 3000 Leuven', '07:00–09:00', 'L Ma Me J V', 'PIN', -15, 50.8798, 4.7005]],
        ['CL-0088', 'Événements Sud', 'event', 'prospect', 'BE 0788.555.666', 'Comptant', 2000, 0, '—', '0 %', 'Par livraison',
            ['Château — accès traiteur', 'Chaussée de Bruxelles 100, 1410 Waterloo', '11:00–13:00', 'S D', 'Dépôt libre', -78, 50.7147, 4.3990]],
    ];
    $pointId = [];  // client code → point id
    foreach ($clients as $c) {
        $cid = $ins('wsm_clients', ['code' => $c[0], 'raison' => $c[1], 'seg' => $c[2], 'statut' => $c[3],
            'tva' => $c[4], 'paiement' => $c[5], 'plafond' => $c[6], 'encours' => $c[7],
            'franco' => $c[8], 'remise' => $c[9], 'fact' => $c[10]]);
        $pt = $c[11];
        $pid = $ins('wsm_client_points', ['client_id' => $cid, 'libelle' => $pt[0], 'adresse' => $pt[1],
            'fenetre' => $pt[2], 'jours' => $pt[3], 'validation' => $pt[4], 'marge' => $pt[5],
            'lat' => $pt[6], 'lng' => $pt[7]]);
        $pointId[$c[0]] = ['point' => $pid, 'client' => $cid, 'window' => $pt[2], 'validation' => $pt[4]];
    }

    // Drivers
    $driverId = [];
    foreach ([
        ['Marek Kowalski', 'BXL-Centre · Renault frigo', '#8D1D2C', 'Renault Master frigo', 'Bruxelles-Centre'],
        ['Julien Dubois', 'Sud · Iveco Daily', '#3B3468', 'Iveco Daily', 'Sud'],
        ['Sofie Peeters', 'Est · Renault Kangoo', '#2d7a3e', 'Renault Kangoo', 'Est'],
    ] as $d) {
        $driverId[$d[0]] = $ins('wsm_drivers', ['nom' => $d[0], 'info' => $d[1], 'color' => $d[2],
            'vehicule' => $d[3], 'zone' => $d[4], 'active' => 1]);
    }

    // Rounds
    $roundId = [];
    foreach ([
        ['Tournée Bruxelles-Centre', 'Marek Kowalski'],
        ['Tournée Sud', 'Julien Dubois'],
        ['Tournée Est', 'Sofie Peeters'],
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
    $mkDelivery(['ref' => 'LIV-2026-0001', 'client' => 'CL-0021', 'driver' => 'Marek Kowalski', 'round' => 'Tournée Bruxelles-Centre',
        'status' => 'livrée', 'confirmed' => 1, 'code' => 'QR-8842', 'ca' => 520, 'couts' => 210,
        'events' => [['créée', 'Livraison planifiée', 'Sophie Renard'], ['assignée', 'Chauffeur Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Départ tournée BXL-Centre', 'Marek Kowalski'], ['livrée', 'Confirmée par QR-8842', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0002', 'client' => 'CL-0044', 'driver' => 'Marek Kowalski', 'round' => 'Tournée Bruxelles-Centre',
        'status' => 'en_cours', 'ca' => 300, 'couts' => 150,
        'events' => [['créée', 'Livraison planifiée', 'Sophie Renard'], ['assignée', 'Chauffeur Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Départ tournée BXL-Centre', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0003', 'client' => 'CL-0060', 'driver' => 'Julien Dubois', 'round' => 'Tournée Sud',
        'status' => 'assignée', 'ca' => 415, 'couts' => 260,
        'events' => [['créée', 'Livraison planifiée', 'Sophie Renard'], ['assignée', 'Chauffeur Julien Dubois', 'Sophie Renard']]]);
    $mkDelivery(['ref' => 'LIV-2026-0004', 'client' => 'CL-0071', 'driver' => null, 'round' => null,
        'status' => 'planifiée', 'ca' => 580, 'couts' => 312,
        'events' => [['créée', 'Livraison planifiée', 'Sophie Renard']]]);

    // Incidents
    foreach ([
        ['INC-2026-0412', 'LIV-2026-0002', 'Colis endommagé', 'Café Belga · Ixelles', 'À traiter', '24 €',
            'Bac isotherme percuté au déchargement. 2 pots de confiture cassés.', '50.8275, 4.3705'],
        ['INC-2026-0411', null, 'Colis manquant', 'Hôtel Amigo · Sablon', 'À traiter', '46 €',
            '1 colis attendu absent au scan de dépôt.', '50.8451, 4.3520'],
        ['INC-2026-0407', null, 'Livraison refusée', 'Event Château · Waterloo', 'En cours', '40 €',
            'Arrivée hors fenêtre horaire. Client absent, dépôt refusé.', '50.7147, 4.3990'],
        ['INC-2026-0403', 'LIV-2026-0001', 'Retour consigne', 'Maison Dandoy · Sablon', 'Résolu', '0 €',
            '3 bacs consignés récupérés au point. Rapprochement OK.', '50.8410, 4.3560'],
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
