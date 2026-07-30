<?php
// ============================================================================
//  db.php — PDO factory for webshop_mrszoko. Connects to MySQL in prod or the
//  local SQLite mirror in dev/CI, and can bootstrap the schema on demand.
// ============================================================================

function wsm_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    $over = wsm_config_overlay();
    return $over ? array_replace_recursive($cfg, $over) : $cfg;
}

/**
 * Surcouche de configuration posée à l'exécution — c'est par là que les
 * réglages saisis dans le back-office (settings.php) entrent en vigueur.
 * Séparée de wsm_config() pour une raison mécanique : lire la base exige une
 * connexion, et ouvrir la connexion exige la configuration. La surcouche est
 * donc appliquée APRÈS, depuis wsm_bootstrap(), sans jamais rappeler la base.
 */
function wsm_config_overlay(?array $patch = null): array {
    static $over = [];
    if ($patch !== null) $over = array_replace_recursive($over, $patch);
    return $over;
}

/** Open a PDO connection to the configured engine. */
function wsm_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = wsm_config();
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($cfg['engine'] === 'mysql') {
        $m = $cfg['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $m['user'], $m['pass'], $opts);
    } else {
        $path = $cfg['sqlite_path'];
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** Whether the schema (wsm_deliveries as sentinel) already exists. */
function wsm_schema_exists(PDO $pdo): bool {
    $cfg = wsm_config();
    try {
        if ($cfg['engine'] === 'mysql') {
            $q = $pdo->query("SHOW TABLES LIKE 'wsm_deliveries'");
            return (bool) $q->fetchColumn();
        }
        $q = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wsm_deliveries'");
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Run the engine-appropriate schema file (idempotent). */
function wsm_apply_schema(PDO $pdo): void {
    $cfg = wsm_config();
    $file = __DIR__ . '/schema/webshop_mrszoko.' . ($cfg['engine'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
    $sql = file_get_contents($file);
    if ($cfg['engine'] === 'mysql') {
        // Target the CONFIGURED database (the connection's dbname — e.g. the
        // server's `mrszoko`), which may differ from the canonical file's
        // webshop_mrszoko: drop the file's CREATE DATABASE / USE statements and
        // run everything else in the connected schema, statement by statement
        // (PDO multi-statement exec is unreliable across drivers).
        $sql = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
    }
    foreach (wsm_split_sql($sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
}

/** Naive-but-sufficient SQL splitter for our comment-and-statement files. */
function wsm_split_sql(string $sql): array {
    $out = [];
    $buf = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '--') || $trim === '') continue;
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) { $out[] = $buf; $buf = ''; }
    }
    if (trim($buf) !== '') $out[] = $buf;
    return $out;
}

/** Ensure schema + seed exist; used by the API and the CLI so first hit works. */
function wsm_bootstrap(bool $seed = true): PDO {
    $pdo = wsm_pdo();
    if (!wsm_schema_exists($pdo)) {
        wsm_apply_schema($pdo);
        if ($seed) {
            require_once __DIR__ . '/seed.php';
            wsm_seed($pdo);
        }
    }
    wsm_ensure_auth_columns($pdo);
    wsm_ensure_commerce_columns($pdo);
    wsm_ensure_shop($pdo);
    wsm_ensure_vies($pdo);
    wsm_ensure_countries($pdo);
    wsm_ensure_trade($pdo);
    wsm_ensure_mail($pdo);
    wsm_ensure_invoices($pdo);
    wsm_ensure_stock($pdo);
    require_once __DIR__ . '/brand.php';
    wsm_ensure_brands($pdo);
    // En dernier : les réglages saisis en console entrent en vigueur une fois
    // que leur table existe, et seulement là où le fichier serveur se tait.
    require_once __DIR__ . '/settings.php';
    wsm_settings_apply($pdo);
    return $pdo;
}

/** Les colonnes d'une table existantes, en minuscules (MySQL + SQLite). */
function wsm_table_columns(PDO $pdo, string $table): array {
    $cfg = wsm_config();
    try {
        if ($cfg['engine'] === 'mysql') {
            $st = $pdo->prepare("SELECT LOWER(column_name) FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $rows = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
        return array_map(fn($r) => strtolower((string) $r['name']), $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ajoute à une table les colonnes manquantes. Nécessaire car
 * CREATE TABLE IF NOT EXISTS ne touche pas une table déjà créée — c'est le cas
 * de la base MySQL de production. Idempotent.
 *
 * @param array $cols  colonne => [déclaration MySQL, déclaration SQLite]
 */
function wsm_ensure_columns(PDO $pdo, string $table, array $cols): void {
    $have = wsm_table_columns($pdo, $table);
    if (!$have) return;                                   // table absente : le schéma la créera complète
    $mysql = wsm_config()['engine'] === 'mysql';
    foreach ($cols as $col => $decl) {
        if (in_array(strtolower($col), $have, true)) continue;
        $sql = $mysql ? $decl[0] : $decl[1];
        try { $pdo->exec("ALTER TABLE $table ADD COLUMN " . ($mysql ? "`$col`" : $col) . " $sql"); }
        catch (Throwable $e) { /* concurrence : une autre requête vient de l'ajouter */ }
    }
}

/** Colonnes d'authentification (voir auth.php). */
function wsm_ensure_auth_columns(PDO $pdo): void {
    wsm_ensure_columns($pdo, 'wsm_users', [
        'password_hash'   => ['VARCHAR(255) NULL DEFAULT NULL', 'TEXT DEFAULT NULL'],
        'last_login'      => ['DATETIME NULL DEFAULT NULL',     'TEXT DEFAULT NULL'],
        'failed_attempts' => ['INT NOT NULL DEFAULT 0',         'INTEGER NOT NULL DEFAULT 0'],
        'locked_until'    => ['DATETIME NULL DEFAULT NULL',     'TEXT DEFAULT NULL'],
    ]);
}

/**
 * Colonnes exigées par tpay (payeur, facture, TVA) et InPost (destinataire,
 * point de retrait, poids/dimensions du colis) — voir commerce.php.
 */
function wsm_ensure_commerce_columns(PDO $pdo): void {
    $txt = fn(int $n, string $d = "''") => ["VARCHAR($n) NOT NULL DEFAULT $d", "TEXT NOT NULL DEFAULT $d"];
    $int = ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'];

    wsm_ensure_columns($pdo, 'wsm_clients', [
        'client_type'   => $txt(10, "'firma'"),
        'email'         => $txt(200),
        'phone'         => $txt(20),
        'first_name'    => $txt(120),
        'last_name'     => $txt(120),
        'nip'           => $txt(15),
        'vat_eu'        => $txt(20),
        'bill_street'   => $txt(200),
        'bill_building' => $txt(30),
        'bill_postcode' => $txt(10),
        'bill_city'     => $txt(120),
        'bill_country'  => $txt(2, "'PL'"),
    ]);

    wsm_ensure_columns($pdo, 'wsm_client_points', [
        'delivery_method' => $txt(20, "'inpost_locker'"),
        'inpost_point'    => $txt(20),
        'street'          => $txt(200),
        'building'        => $txt(30),
        'postcode'        => $txt(10),
        'city'            => $txt(120),
        'country'         => $txt(2, "'PL'"),
        'contact_phone'   => $txt(20),
        'contact_email'   => $txt(200),
    ]);

    wsm_ensure_columns($pdo, 'wsm_products', [
        'sku'             => $txt(60),
        'ean'             => $txt(14),
        'vat_rate'        => ['DECIMAL(4,2) NOT NULL DEFAULT 0.23', 'REAL NOT NULL DEFAULT 0.23'],
        'weight_g'        => $int,
        'length_mm'       => $int,
        'width_mm'        => $int,
        'height_mm'       => $int,
        'parcel_template' => $txt(1),
    ]);
}

/**
 * Contrôles VIES : la table d'historique, plus l'état du dernier contrôle
 * porté sur le client et sur la commande. Idempotent.
 */
function wsm_ensure_vies(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_vies_checks')) wsm_apply_schema($pdo);
    $cols = [
        'vat_status'       => ["VARCHAR(16) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        'vat_checked_at'   => ['DATETIME NULL DEFAULT NULL',      'TEXT DEFAULT NULL'],
        'vat_name'         => ["VARCHAR(200) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        'vat_consultation' => ["VARCHAR(64) NOT NULL DEFAULT ''",  "TEXT NOT NULL DEFAULT ''"],
    ];
    wsm_ensure_columns($pdo, 'wsm_clients', $cols);
    wsm_ensure_columns($pdo, 'wsm_orders',  $cols);
}

/**
 * Pays servis + périmètre de chaque mode de livraison. La liste est seedée une
 * seule fois : ensuite c'est le back-office qui décide où l'on vend.
 */
function wsm_ensure_countries(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_countries')) wsm_apply_schema($pdo);
    // Un mode de livraison ne dessert pas forcément toute l'Europe : InPost
    // Paczkomat, par exemple, est polonais.
    wsm_ensure_columns($pdo, 'wsm_shipping_methods', [
        'countries' => ["VARCHAR(255) NOT NULL DEFAULT 'PL'", "TEXT NOT NULL DEFAULT 'PL'"],
    ]);
    // La commande retient le pays de livraison et le régime appliqué.
    wsm_ensure_columns($pdo, 'wsm_orders', [
        'reverse_charge' => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
    ]);
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_countries")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_countries($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/**
 * Deux règles commerciales : la remise au poids, et le fait qu'une commande
 * passe même si le stock ne suit pas (on recontacte l'acheteur). Idempotent.
 */
function wsm_ensure_trade(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_discount_tiers')) wsm_apply_schema($pdo);
    $int = ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'];
    wsm_ensure_columns($pdo, 'wsm_orders', [
        // Une commande peut dépasser le stock : on l'accepte et on prévient.
        'backorder'        => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        'discount_percent' => ['DECIMAL(5,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0'],
        'discount_amount'  => $int,
    ]);
    wsm_ensure_columns($pdo, 'wsm_order_items', [
        // Combien de cette ligne reste à produire : ce que l'atelier doit savoir.
        'backorder' => $int,
        // Le coût de revient AU MOMENT DE LA VENTE, en grosze. Recalculer une
        // marge d'il y a six mois avec le prix d'achat d'aujourd'hui donnerait
        // un chiffre faux : la matière première bouge, la vente est passée.
        // Comme les lignes de facture, la ligne de commande fige ce qu'elle
        // doit prouver.
        'unit_cost' => $int,
    ]);
    // Ce que le transporteur nous facture, à distinguer de ce que le client
    // paie. Sans les deux, « quelle part du port le client couvre-t-il ? » n'a
    // pas de réponse : le rapport vaudrait 100 % par construction.
    wsm_ensure_columns($pdo, 'wsm_shipping_methods', [
        'cost_net' => $int,
    ]);
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_discount_tiers")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_discounts($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/**
 * Messagerie : modèles, file des messages, réglages d'intégration. Les modèles
 * ne sont semés qu'une fois — ensuite c'est la console qui les écrit, et un
 * déploiement ne doit jamais réécrire un texte que quelqu'un a corrigé.
 */
function wsm_ensure_mail(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_messages') || !wsm_table_exists($pdo, 'wsm_settings')
        || !wsm_table_exists($pdo, 'wsm_mail_templates')) {
        wsm_apply_schema($pdo);
    }
    try {
        require_once __DIR__ . '/seed.php';
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_mail_templates")->fetchColumn()) {
            wsm_seed_mail_templates($pdo);
        } else {
            // Une base déjà en service : on n'ajoute QUE les modèles nouveaux.
            // Sans ça, une fonctionnalité livrée après la mise en route reste
            // muette pour toujours, faute du modèle qu'elle utilise.
            wsm_seed_mail_templates_topup($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/** Facturation : les deux tables du document. Idempotent. */
function wsm_ensure_invoices(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_invoices') || !wsm_table_exists($pdo, 'wsm_invoice_items')) {
        wsm_apply_schema($pdo);
    }
}

/** Journal des mouvements de stock. Idempotent. */
function wsm_ensure_stock(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_stock_moves') || !wsm_table_exists($pdo, 'wsm_stock_docs')) {
        wsm_apply_schema($pdo);
    }
    // Le mouvement rattaché à son document : c'est ce lien qui permet de
    // rouvrir un bon et de retrouver exactement ce qui est entré ou sorti.
    wsm_ensure_columns($pdo, 'wsm_stock_moves', [
        'doc_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'INTEGER DEFAULT NULL'],
    ]);
}

/** Une table existe-t-elle ? (MySQL + SQLite) */
function wsm_table_exists(PDO $pdo, string $table): bool {
    try {
        $q = wsm_config()['engine'] === 'mysql'
            ? $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))
            : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Boutique en ligne : tables commande/paiement/expédition + colonnes vitrine
 * sur wsm_products. La base de production existe déjà, donc
 * CREATE TABLE IF NOT EXISTS suffit pour les nouvelles tables mais PAS pour
 * les nouvelles colonnes d'une table déjà créée — d'où wsm_ensure_columns.
 * Idempotent : appelé à chaque requête, ne fait rien quand tout est en place.
 */
function wsm_ensure_shop(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_orders')) {
        wsm_apply_schema($pdo);                       // idempotent : n'ajoute que ce qui manque
    }
    $txt = fn(int $n, string $d = "''") => ["VARCHAR($n) NOT NULL DEFAULT $d", "TEXT NOT NULL DEFAULT $d"];
    wsm_ensure_columns($pdo, 'wsm_products', [
        'slug'         => $txt(80),
        'shop_visible' => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        'stock'        => ['INT NOT NULL DEFAULT 0',        'INTEGER NOT NULL DEFAULT 0'],
        'image_url'    => $txt(255),
        'swatch_from'  => $txt(32, "'--choco-500'"),
        'swatch_to'    => $txt(32, "'--choco-800'"),
        'origin'       => $txt(80),
        'cocoa'        => $txt(16),
        'unit_label'   => $txt(40),
        'badge'        => $txt(40),
    ]);

    // Contenu minimal de la boutique : modes de livraison + textes 3 langues.
    // Seedé une seule fois ; ensuite la base fait foi et l'admin peut éditer.
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_shipping_methods")->fetchColumn()
            || !(int) $pdo->query("SELECT COUNT(*) FROM wsm_shop_i18n")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_shop($pdo);
        }
    } catch (Throwable $e) { /* tables absentes : le schéma vient d'échouer, on n'aggrave pas */ }
}

/**
 * Upgrade path for databases created before the landing tables existed:
 * the schema files are idempotent (IF NOT EXISTS), so re-applying them adds
 * only what's missing, then the landing content is (re)seeded from
 * landing/content_seed.json. No-op when the table is already there.
 */
function wsm_ensure_landing(PDO $pdo): void {
    $cfg = wsm_config();
    try {
        $q = $cfg['engine'] === 'mysql'
            ? $pdo->query("SHOW TABLES LIKE 'wsm_landing_i18n'")
            : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wsm_landing_i18n'");
        if ($q->fetchColumn()) return;
    } catch (Throwable $e) { /* fall through and create */ }
    wsm_apply_schema($pdo);
    require_once __DIR__ . '/seed.php';
    wsm_seed_landing($pdo);
}
