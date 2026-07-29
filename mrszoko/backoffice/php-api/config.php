<?php
// ============================================================================
//  config.php — connection + auth config for the webshop_mrszoko API.
//  Everything is env-driven (no secrets hardcoded). Copy nothing: this file
//  reads the environment and falls back to a local SQLite database so the API
//  runs out-of-the-box for dev / CI / the end-to-end delivery test.
//
//  Production (MySQL):
//    WSM_DB_ENGINE=mysql
//    WSM_DB_HOST=127.0.0.1  WSM_DB_PORT=3306
//    WSM_DB_NAME=webshop_mrszoko
//    WSM_DB_USER=...  WSM_DB_PASS=...
//    WSM_ADMIN_TOKEN=<shared admin token>   (sent by the front as X-Admin-Token)
//
//  Local / CI (SQLite, default):
//    WSM_DB_ENGINE=sqlite (default)
//    WSM_SQLITE_PATH=<file>  (default: php-api/data/webshop_mrszoko.sqlite)
// ============================================================================

$cfg = [
    'engine'      => getenv('WSM_DB_ENGINE') ?: 'sqlite',
    'mysql'       => [
        'host'    => getenv('WSM_DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('WSM_DB_PORT') ?: '3306',
        'name'    => getenv('WSM_DB_NAME') ?: 'webshop_mrszoko',
        'user'    => getenv('WSM_DB_USER') ?: 'root',
        'pass'    => getenv('WSM_DB_PASS') ?: '',
    ],
    'sqlite_path' => getenv('WSM_SQLITE_PATH') ?: (__DIR__ . '/data/webshop_mrszoko.sqlite'),
    // Admin token required for every write endpoint. Overridable via env.
    // The default is a dev-only placeholder — set WSM_ADMIN_TOKEN in prod.
    'admin_token' => getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token',
    // Allow the front-end (served from a different path/origin during dev) to call us.
    'cors_origin' => getenv('WSM_CORS_ORIGIN') ?: '*',
];

// Optional local override (untracked, gitignored): drop a config.local.php on the
// server that `return`s an array of keys to override — e.g. real MySQL creds and
// the admin token — without editing this file or setting env vars.
//   <?php return ['engine'=>'mysql','mysql'=>[...],'admin_token'=>'...'];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) $cfg = array_replace_recursive($cfg, $local);
}

return $cfg;
