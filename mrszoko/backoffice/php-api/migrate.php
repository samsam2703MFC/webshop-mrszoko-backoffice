<?php
// ============================================================================
//  migrate.php — CLI. Creates the webshop_mrszoko schema and seeds it.
//
//  Usage:
//    php migrate.php            # create schema + seed if empty (idempotent)
//    php migrate.php --fresh    # drop everything and rebuild from scratch
//    php migrate.php --no-seed  # schema only
//
//  Comptes (authentification — voir auth.php) :
//    php migrate.php --set-password <email> <mot-de-passe> [role] [nom]
//        crée le compte s'il n'existe pas, sinon repose son mot de passe.
//    php migrate.php --ensure-admin <email> <mot-de-passe>
//        ne fait rien si un compte capable de se connecter existe déjà ;
//        sinon crée ce compte administrateur. Idempotent : c'est l'amorçage
//        utilisé par le déploiement.
// ============================================================================
require __DIR__ . '/db.php';
require __DIR__ . '/delivery.php';   // wsm_audit(), utilisé par auth.php
require __DIR__ . '/auth.php';

$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed = !in_array('--no-seed', $args, true);
$cfg = wsm_config();
$pdo = wsm_pdo();

// ---- Rebranding L'Atelier → Mister Szoko (sortie immédiate) ----------------
// Les bases déjà seedées portent les libellés de démonstration L'Atelier. Ce
// passage les réécrit en place. Idempotent : une fois exécuté, REPLACE() ne
// trouve plus rien à remplacer. Ne touche qu'à des libellés d'affichage —
// aucun identifiant, aucune clé technique.
if (in_array('--rebrand', $args, true)) {
    $pdo = wsm_bootstrap();
    $ops = [
        ["wsm_shops",    "nom",   "L'Atelier — ", "Mister Szoko — "],
        ["wsm_products", "nom",   "— L'Atelier",  "— Mister Szoko"],
        ["wsm_users",    "email", "@latelierby.be", "@misterszoko.com"],
        ["wsm_params",   "val",   "aide.latelierby.be", "pomoc.misterszoko.com"],
    ];
    $total = 0;
    foreach ($ops as [$t, $c, $from, $to]) {
        try {
            $st = $pdo->prepare("UPDATE $t SET $c = REPLACE($c, ?, ?) WHERE $c LIKE ?");
            $st->execute([$from, $to, '%' . $from . '%']);
            $n = $st->rowCount();
            $total += $n;
            if ($n) echo "  $t.$c : $n ligne(s)\n";
        } catch (Throwable $e) {
            echo "  $t.$c : ignoré (" . $e->getMessage() . ")\n";
        }
    }
    echo $total ? "rebranding appliqué ($total lignes)\n" : "rien à rebrander — déjà Mister Szoko\n";
    exit(0);
}

// ---- Gestion des comptes (sortie immédiate) --------------------------------
$idx = array_search('--set-password', $args, true);
$ensure = array_search('--ensure-admin', $args, true);
if ($idx !== false || $ensure !== false) {
    $at = $idx !== false ? $idx : $ensure;
    $email = $args[$at + 1] ?? '';
    $pass  = $args[$at + 2] ?? '';
    if ($email === '' || $pass === '') {
        fwrite(STDERR, "usage: php migrate.php " . $args[$at] . " <email> <mot-de-passe> [role] [nom]\n");
        exit(2);
    }
    $pdo = wsm_bootstrap();                       // garantit schéma + colonnes d'auth
    if ($ensure !== false && wsm_has_login_account($pdo)) {
        echo "un compte de connexion existe déjà — aucun changement\n";
        exit(0);
    }
    try {
        echo wsm_set_password($pdo, $email, $pass, $args[$at + 3] ?? WSM_ROLE_ADMIN, $args[$at + 4] ?? '') . "\n";
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'erreur : ' . $e->getMessage() . "\n");
        exit(2);
    }
    exit(0);
}

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
