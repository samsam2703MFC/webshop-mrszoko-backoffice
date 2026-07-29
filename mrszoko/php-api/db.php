<?php
// ============================================================================
//  db.php — PDO factory for webshop_mrszoko. Connects to MySQL in prod or the
//  local SQLite mirror in dev/CI, and can bootstrap the schema on demand.
// ============================================================================

function wsm_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
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
        // The MySQL file contains CREATE DATABASE / USE — run it as one script.
        $pdo->exec($sql);
    } else {
        // SQLite: split on ';' at statement boundaries and run each.
        foreach (wsm_split_sql($sql) as $stmt) {
            if (trim($stmt) !== '') $pdo->exec($stmt);
        }
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
    return $pdo;
}
