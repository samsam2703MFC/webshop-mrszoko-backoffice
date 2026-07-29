<?php
// ============================================================================
//  migrate.php — CLI. Creates the webshop_mrszoko schema and seeds it.
//
//  Usage:
//    php migrate.php            # create schema + seed if empty (idempotent)
//    php migrate.php --fresh    # drop everything and rebuild from scratch
//    php migrate.php --no-seed  # schema only
// ============================================================================
require __DIR__ . '/db.php';

$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed = !in_array('--no-seed', $args, true);
$cfg = wsm_config();
$pdo = wsm_pdo();

echo "engine: {$cfg['engine']}\n";

if ($fresh) {
    echo "dropping all wsm_ tables...\n";
    wsm_drop_all($pdo, $cfg['engine']);
}

if (wsm_schema_exists($pdo) && !$fresh) {
    echo "schema already present (use --fresh to rebuild).\n";
} else {
    echo "applying schema...\n";
    wsm_apply_schema($pdo);
    if ($seed) {
        echo "seeding...\n";
        require __DIR__ . '/seed.php';
        wsm_seed($pdo);
    }
}

// quick summary
foreach (['wsm_shops', 'wsm_products', 'wsm_clients', 'wsm_drivers', 'wsm_deliveries', 'wsm_incidents'] as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    printf("  %-22s %d rows\n", $t, $n);
}
echo "done.\n";

function wsm_drop_all(PDO $pdo, string $engine): void {
    $tables = [
        'wsm_incidents', 'wsm_delivery_events', 'wsm_deliveries', 'wsm_rounds', 'wsm_drivers',
        'wsm_client_points', 'wsm_clients', 'wsm_bundle_slot_choices', 'wsm_bundle_slots', 'wsm_bundles',
        'wsm_catchment', 'wsm_audit', 'wsm_user_shops', 'wsm_users', 'wsm_email_templates',
        'wsm_params', 'wsm_pricing_rules', 'wsm_vouchers', 'wsm_products', 'wsm_categories',
        'wsm_shops', 'wsm_kpis',
    ];
    if ($engine === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    else $pdo->exec('PRAGMA foreign_keys=OFF');
    foreach ($tables as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    if ($engine === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    else $pdo->exec('PRAGMA foreign_keys=ON');
}
