<?php
// ============================================================================
//  index.php — webshop_mrszoko API router (Console marque · franchisor).
//  Serves /franchisor/* endpoints. Reads shape data exactly as the front-end
//  expects; writes require the admin token (X-Admin-Token). Every response is
//  JSON. This is the single wiring point between the UI and the wsm_ tables.
// ============================================================================
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/delivery.php';

$cfg = wsm_config();

// ---- CORS + preflight ------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function wsm_send($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function wsm_fail(string $msg, int $code = 400): void { wsm_send(['error' => $msg], $code); }
function wsm_body(): array { $raw = file_get_contents('php://input'); $j = json_decode($raw ?: 'null', true); return is_array($j) ? $j : []; }
function wsm_require_admin(): void {
    $cfg = wsm_config();
    $tok = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!hash_equals($cfg['admin_token'], $tok)) wsm_fail('unauthorized', 401);
}

// ---- Resolve the route after /franchisor/ ----------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$route = '';
if (preg_match('#/franchisor/(.*)$#', $path, $m)) $route = rtrim($m[1], '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = wsm_bootstrap();  // ensure schema + seed on first hit
} catch (Throwable $e) {
    wsm_fail('db_unavailable: ' . $e->getMessage(), 500);
}

// ============================ READ ENDPOINTS ================================
if ($method === 'GET') {
    switch ($route) {
        case 'kpis':
            wsm_send(array_map(fn($r) => [
                'label' => $r['label'], 'value' => $r['value'], 'valColor' => $r['val_color'],
                'delta' => $r['delta'], 'deltaColor' => $r['delta_color'],
            ], $pdo->query("SELECT * FROM wsm_kpis ORDER BY sort_order")->fetchAll()));

        case 'shops':
            wsm_send(array_map(fn($r) => [
                'id' => $r['id'], 'nom' => $r['nom'], 'ville' => $r['ville'], 'web' => (bool) $r['web'],
                'contrat' => $r['contrat'], 'act' => (bool) $r['act'], 'caShop' => (int) $r['ca_shop'],
                'caOffice' => (int) $r['ca_office'], 'adoption' => (int) $r['adoption'], 'accent' => $r['accent'],
            ], $pdo->query("SELECT * FROM wsm_shops ORDER BY sort_order")->fetchAll()));

        case 'catalog':
            wsm_send(wsm_catalog($pdo));

        case 'vouchers':
            wsm_send(array_map(fn($r) => ['code' => $r['code'], 'valeur' => $r['valeur'], 'type' => $r['type'], 'validite' => $r['validite']],
                $pdo->query("SELECT * FROM wsm_vouchers ORDER BY id")->fetchAll()));

        case 'pricing-rules':
            wsm_send(array_map(fn($r) => ['nom' => $r['nom'], 'cible' => $r['cible'], 'effet' => $r['effet']],
                $pdo->query("SELECT * FROM wsm_pricing_rules WHERE shop_id IS NULL ORDER BY id")->fetchAll()));

        case 'params':
            wsm_send(array_map(fn($r) => ['cle' => $r['cle'], 'type' => $r['type'], 'val' => $r['val']],
                $pdo->query("SELECT * FROM wsm_params ORDER BY cle")->fetchAll()));

        case 'email-templates':
            wsm_send(array_map(fn($r) => ['cle' => $r['cle'], 'langue' => $r['langue'], 'sujet' => $r['sujet']],
                $pdo->query("SELECT * FROM wsm_email_templates ORDER BY id")->fetchAll()));

        case 'users':
            wsm_send(array_map(fn($r) => ['nom' => $r['nom'], 'email' => $r['email'], 'role' => $r['role'], 'portee' => $r['portee'], 'act' => (bool) $r['act']],
                $pdo->query("SELECT * FROM wsm_users ORDER BY id")->fetchAll()));

        case 'audit':
            wsm_send(array_map(fn($r) => ['ts' => $r['ts'], 'user' => $r['user'], 'verb' => $r['verb'], 'entity' => $r['entity'], 'shop' => $r['shop']],
                $pdo->query("SELECT * FROM wsm_audit ORDER BY id DESC")->fetchAll()));

        case 'catchment':
            wsm_send(array_map(fn($r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'postcodes' => $r['postcodes'],
                'exclusive' => (bool) $r['exclusive'], 'active' => (bool) $r['active'],
                'shop_id' => $r['shop_id'], 'shop_name' => wsm_shop_name($pdo, $r['shop_id']),
            ], $pdo->query("SELECT * FROM wsm_catchment ORDER BY id")->fetchAll()));

        case 'menus':
            wsm_send(wsm_menus($pdo));

        // ---- delivery module ----
        case 'deliveries':      wsm_send(wsm_delivery_list($pdo));
        case 'delivery-kpis':   wsm_send(wsm_delivery_kpis($pdo));
        case 'drivers':         wsm_send(wsm_drivers($pdo));
        case 'rounds':          wsm_send(wsm_rounds($pdo));
        case 'delivery-clients':wsm_send(wsm_delivery_clients($pdo));
        case 'incidents':       wsm_send(wsm_incidents($pdo));
        case 'delivery-events':
            $id = (int) ($_GET['delivery_id'] ?? 0);
            wsm_send(wsm_delivery_events($pdo, $id));
    }
    // /franchisor/deliveries/{id}  and  /franchisor/deliveries/{id}/events
    if (preg_match('#^deliveries/(\d+)(/events)?$#', $route, $mm)) {
        $id = (int) $mm[1];
        if (!empty($mm[2])) wsm_send(wsm_delivery_events($pdo, $id));
        $d = wsm_delivery_get($pdo, $id);
        $d ? wsm_send($d) : wsm_fail('delivery_not_found', 404);
    }
    wsm_fail('unknown_route: ' . $route, 404);
}

// ============================ WRITE ENDPOINTS ===============================
if ($method === 'POST') {
    wsm_require_admin();
    $body = wsm_body();

    switch ($route) {
        case 'param': {
            if (empty($body['cle'])) wsm_fail('cle_required');
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_params WHERE cle=?"); $st->execute([$body['cle']]);
            if ((int) $st->fetchColumn()) {
                $pdo->prepare("UPDATE wsm_params SET val=? WHERE cle=?")->execute([(string) ($body['val'] ?? ''), $body['cle']]);
            } else {
                $pdo->prepare("INSERT INTO wsm_params (cle,type,val) VALUES (?,?,?)")->execute([$body['cle'], $body['type'] ?? 'text', (string) ($body['val'] ?? '')]);
            }
            wsm_audit($pdo, 'Console marque', 'Modification', 'wsm_params ' . $body['cle'], 'Réseau');
            wsm_send(['ok' => true]);
        }

        case 'category': {
            if (empty($body['id'])) wsm_fail('id_required');
            $fields = ['active', 'office_delivery', 'brand_mandatory', 'menu_default', 'brand_whitelist'];
            $set = []; $vals = [];
            foreach ($fields as $f) if (array_key_exists($f, $body)) { $set[] = "$f=?"; $vals[] = (int) $body[$f]; }
            if (!$set) wsm_fail('no_fields');
            $vals[] = (int) $body['id'];
            $pdo->prepare("UPDATE wsm_categories SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
            wsm_send(['ok' => true]);
        }

        case 'catchment': {
            if (!empty($body['delete'])) {
                $pdo->prepare("DELETE FROM wsm_catchment WHERE id=?")->execute([(int) $body['delete']]);
                wsm_send(['ok' => true]);
            }
            $row = ['name' => $body['name'] ?? '', 'postcodes' => $body['postcodes'] ?? '',
                'exclusive' => (int) (!empty($body['exclusive'])), 'active' => (int) ($body['active'] ?? 1),
                'shop_id' => $body['shop_id'] ?? null];
            if (!empty($body['id'])) {
                $pdo->prepare("UPDATE wsm_catchment SET name=?,postcodes=?,exclusive=?,active=?,shop_id=? WHERE id=?")
                    ->execute([$row['name'], $row['postcodes'], $row['exclusive'], $row['active'], $row['shop_id'], (int) $body['id']]);
                wsm_send(['ok' => true, 'id' => (int) $body['id']]);
            }
            $pdo->prepare("INSERT INTO wsm_catchment (name,postcodes,exclusive,active,shop_id) VALUES (?,?,?,?,?)")
                ->execute([$row['name'], $row['postcodes'], $row['exclusive'], $row['active'], $row['shop_id']]);
            wsm_send(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        }

        // ---- delivery mutations ----
        case 'deliveries': {
            try { wsm_send(wsm_delivery_create($pdo, $body), 201); }
            catch (InvalidArgumentException $e) { wsm_fail($e->getMessage(), 422); }
        }
    }

    // /franchisor/deliveries/{id}/{action}
    if (preg_match('#^deliveries/(\d+)/(assign|confirm|status)$#', $route, $mm)) {
        $id = (int) $mm[1]; $action = $mm[2];
        try {
            if ($action === 'assign') {
                wsm_send(wsm_delivery_assign($pdo, $id,
                    isset($body['driver_id']) ? (int) $body['driver_id'] : null,
                    isset($body['round_id']) ? (int) $body['round_id'] : null));
            }
            if ($action === 'confirm') {
                wsm_send(wsm_delivery_confirm($pdo, $id, (string) ($body['code'] ?? '')));
            }
            if ($action === 'status') {
                wsm_send(wsm_delivery_status($pdo, $id, (string) ($body['status'] ?? '')));
            }
        } catch (InvalidArgumentException $e) { wsm_fail($e->getMessage(), 422); }
        catch (RuntimeException $e) { wsm_fail($e->getMessage(), 409); }
    }

    wsm_fail('unknown_route: ' . $route, 404);
}

wsm_fail('method_not_allowed', 405);

// ============================ SHAPERS =======================================
function wsm_shop_name(PDO $pdo, $shopId): string {
    if (!$shopId) return '';
    static $cache = [];
    if (!isset($cache[$shopId])) {
        $st = $pdo->prepare("SELECT nom FROM wsm_shops WHERE id=?"); $st->execute([$shopId]);
        $cache[$shopId] = $st->fetchColumn() ?: '';
    }
    return $cache[$shopId];
}

function wsm_catalog(PDO $pdo): array {
    $cats = $pdo->query("SELECT * FROM wsm_categories ORDER BY sort_order")->fetchAll();
    $prods = $pdo->query("SELECT * FROM wsm_products ORDER BY category_id, sort_order")->fetchAll();
    $byCat = [];
    foreach ($prods as $p) {
        $byCat[$p['category_id']][] = [
            'id' => $p['id'], 'nom' => $p['nom'], 'prix' => (float) $p['prix'], 'statut' => $p['statut'],
            'bw' => (bool) $p['brand_whitelist'], 'bm' => (bool) $p['brand_mandatory'],
            'ad' => (int) $p['adoption'], 'saison' => $p['saison'],
        ];
    }
    $out = [];
    foreach ($cats as $c) {
        // The dashboard catalogue screen shows the retail categories, not the pure menu group.
        if ($c['name'] === 'Menus & formules') continue;
        $out[] = ['id' => (int) $c['id'], 'cat' => $c['name'], 'prods' => $byCat[$c['id']] ?? []];
    }
    return $out;
}

function wsm_menus(PDO $pdo): array {
    $out = ['_categories' => []];
    foreach ($pdo->query("SELECT name, menu_default FROM wsm_categories")->fetchAll() as $c) {
        $out['_categories'][$c['name']] = ['menu_default' => (int) $c['menu_default']];
    }
    $cats = [];
    foreach ($pdo->query("SELECT id, name FROM wsm_categories")->fetchAll() as $c) $cats[$c['id']] = $c['name'];

    $prods = $pdo->query("SELECT * FROM wsm_products WHERE menu_override IS NOT NULL OR category_id IN
        (SELECT id FROM wsm_categories WHERE name='Menus & formules') ORDER BY sort_order")->fetchAll();
    // Bundle tree, loaded once and indexed.
    $bundles = $pdo->query("SELECT * FROM wsm_bundles ORDER BY product_id, sort_order")->fetchAll();
    $slots = $pdo->query("SELECT * FROM wsm_bundle_slots ORDER BY bundle_id, sort_order")->fetchAll();
    $choices = $pdo->query("SELECT * FROM wsm_bundle_slot_choices ORDER BY slot_id, sort_order")->fetchAll();
    $slotChoices = []; foreach ($choices as $ch) $slotChoices[$ch['slot_id']][] = $ch;
    $bundleSlots = []; foreach ($slots as $s) $bundleSlots[$s['bundle_id']][] = $s;
    $prodBundles = []; foreach ($bundles as $b) $prodBundles[$b['product_id']][] = $b;

    foreach ($prods as $p) {
        $blist = [];
        foreach ($prodBundles[$p['id']] ?? [] as $b) {
            $sl = [];
            foreach ($bundleSlots[$b['id']] ?? [] as $s) {
                $chs = [];
                foreach ($slotChoices[$s['id']] ?? [] as $c) {
                    $chs[] = ['id' => $c['id'], 'label' => $c['label'], 'img' => $c['img'],
                        'delta' => (float) $c['delta'], 'cost' => (float) $c['cost'],
                        'sort_order' => (int) $c['sort_order'], 'active' => (bool) $c['active']];
                }
                $sl[] = ['id' => $s['id'], 'label' => $s['label'], 'required' => (bool) $s['required'],
                    'kind' => $s['kind'], 'min_select' => (int) $s['min_select'], 'max_select' => (int) $s['max_select'],
                    'sort_order' => (int) $s['sort_order'], 'active' => (bool) $s['active'], 'choices' => $chs];
            }
            $blist[] = ['id' => $b['id'], 'name' => $b['name'], 'description' => $b['description'],
                'price_modifier' => (float) $b['price_modifier'], 'sort_order' => (int) $b['sort_order'],
                'active' => (bool) $b['active'], 'slots' => $sl];
        }
        $out[$p['id']] = [
            'productName' => $p['nom'], 'category' => $cats[$p['category_id']] ?? '',
            'menuOverride' => $p['menu_override'], 'basePrice' => (float) $p['prix'],
            'baseCost' => (float) $p['base_cost'], 'bundles' => $blist,
        ];
    }
    return $out;
}
