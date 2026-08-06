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

// ---- Synchronisation du contenu éditorial (sortie immédiate) ---------------
// Ajoute les libellés livrés depuis le dernier déploiement SANS écraser ceux
// que la console a modifiés. Joué à chaque déploiement : sans lui, un nouveau
// bouton livré dans le code resterait vide sur une base déjà seedée.
if (in_array('--sync-content', $args, true)) {
    $pdo = wsm_bootstrap();
    require_once __DIR__ . '/seed.php';
    [$keys, $ship] = wsm_sync_content($pdo);
    echo "contenu synchronisé : $keys libellés ajoutés, $ship modes de livraison ajoutés\n";
    exit(0);
}

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

// ---- Retrait des boutiques belges de démonstration (sortie immédiate) ------
// La maquette d'origine peuplait cinq boutiques belges avec leur chiffre
// d'affaires et leur taux d'adoption. C'était le décor d'une démonstration de
// franchise ; l'affaire réelle est UNE boutique à Wrocław. Des chiffres de
// démonstration qui ressemblent à des vrais sont pires que pas de chiffres :
// on lit « 29 800 » sur un tableau de bord et on en tire une conclusion.
//
// TROIS PRUDENCES, parce qu'une suppression ne se rejoue pas :
//
//  1. ON NE SUPPRIME QUE LES CINQ IDENTIFIANTS CONNUS. Pas de TRUNCATE : le
//     jour où quelqu'un ouvre un second point de vente, ce passage ne doit pas
//     l'effacer. Il est idempotent — rejoué, il ne trouve plus rien.
//  2. ON NE TOUCHE PAS À L'AUDIT. Cinq lignes y nomment ces boutiques. Un
//     journal d'audit est le récit de ce qui s'est passé ; le réécrire pour
//     faire propre est exactement ce qu'un journal d'audit existe pour
//     empêcher.
//  3. ON NE SUPPRIME AUCUN COMPTE. Les liens portée→boutique partent, parce
//     qu'un lien vers une boutique absente est un compte qui ne voit rien ;
//     les comptes eux-mêmes ne nous appartiennent pas.
if (in_array('--purge-demo-shops', $args, true)) {
    $pdo = wsm_bootstrap();
    $demo = ['bxl', 'and', 'ucc', 'sch', 'lv'];
    $in = implode(',', array_fill(0, count($demo), '?'));

    $avant = $pdo->prepare("SELECT id, nom FROM wsm_shops WHERE id IN ($in)");
    $avant->execute($demo);
    $trouve = $avant->fetchAll();

    if (!$trouve) {
        echo "rien à retirer — aucune boutique de démonstration en base\n";
    } else {
        $pdo->beginTransaction();
        try {
            $l = $pdo->prepare("DELETE FROM wsm_user_shops WHERE shop_id IN ($in)");
            $l->execute($demo);
            $liens = $l->rowCount();

            // La portée écrite en toutes lettres sur le compte est un libellé,
            // pas une clé : sans ce passage, l'écran Użytkownicy afficherait
            // encore « Bruxelles-Centre » pour un compte qui ne voit plus rien.
            $p = $pdo->prepare("UPDATE wsm_users SET portee = 'Cała sieć'
                                 WHERE portee IN ('Bruxelles-Centre','Anderlecht, Uccle','Louvain',
                                                  'Anderlecht','Uccle','Schaerbeek')");
            $p->execute();
            $portees = $p->rowCount();

            $s = $pdo->prepare("DELETE FROM wsm_shops WHERE id IN ($in)");
            $s->execute($demo);
            $pdo->commit();

            foreach ($trouve as $t) echo "  retirée : {$t['id']} — {$t['nom']}\n";
            echo "  liens portée→boutique retirés : $liens\n";
            echo "  portées ramenées au réseau     : $portees\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fwrite(STDERR, "échec du retrait : " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    // Ce qui reste et qu'on n'a PAS touché, dit à voix haute. Un nettoyage qui
    // tait ce qu'il laisse derrière lui se lit comme un nettoyage complet.
    $reste = (int) $pdo->query("SELECT COUNT(*) FROM wsm_shops")->fetchColumn();
    echo "  boutiques restantes            : $reste\n";
    try {
        $aud = (int) $pdo->query("SELECT COUNT(*) FROM wsm_audit
                                   WHERE shop IN ('Bruxelles-Centre','Anderlecht','Uccle','Schaerbeek','Louvain')")
                         ->fetchColumn();
        if ($aud > 0) echo "  NON TOUCHÉ — $aud ligne(s) d'audit nomment encore ces villes (c'est l'histoire, elle reste)\n";
    } catch (Throwable $e) { /* table absente : rien à dire */ }
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
