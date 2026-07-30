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
//    WSM_DB_NAME=mrszoko
//    WSM_DB_USER=...  WSM_DB_PASS=...
//    WSM_ADMIN_TOKEN=<service token>   (en-tête X-Admin-Token, automatisation)
//
//  Les humains ne se connectent PAS avec ce jeton : ils ouvrent une session
//  (e-mail + mot de passe) — voir auth.php. Le jeton n'a pas de valeur par
//  défaut : s'il est vide, la voie jeton est désactivée.
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
    // Jeton de SERVICE (automatisation / tests), en-tête X-Admin-Token.
    // AUCUNE valeur par défaut : vide = voie jeton entièrement désactivée
    // (fail-closed). En production il est écrit dans config.local.php ; en
    // local, exportez WSM_ADMIN_TOKEN (voir serve.sh).
    'admin_token' => getenv('WSM_ADMIN_TOKEN') ?: '',
    // Partage inter-origine : vide = aucun en-tête CORS émis. Le front est
    // servi sur la MÊME origine que l'API (/mrszoko/backoffice → ./api), donc
    // rien n'est nécessaire. Ne renseigner qu'une origine précise, jamais '*' :
    // les requêtes portent une session par cookie.
    'cors_origin' => getenv('WSM_CORS_ORIGIN') ?: '',
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
