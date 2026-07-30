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

    // Adresse publique de la boutique — sert à composer les URL de retour et
    // de notification envoyées à tpay. Vide : déduite de la requête en cours.
    'shop_url' => getenv('WSM_SHOP_URL') ?: '',

    // ---- tpay.com : encaissement ------------------------------------------
    // AUCUN défaut. Sans client_id/secret, aucune transaction n'est créée ;
    // sans security_code, aucune notification n'est acceptée (fail-closed).
    // Ces valeurs vivent dans config.local.php sur le serveur, jamais ici :
    // ce dépôt est public.
    'tpay' => [
        'merchant_id'   => getenv('WSM_TPAY_MERCHANT_ID') ?: '',
        'client_id'     => getenv('WSM_TPAY_CLIENT_ID') ?: '',
        'client_secret' => getenv('WSM_TPAY_CLIENT_SECRET') ?: '',
        'security_code' => getenv('WSM_TPAY_SECURITY_CODE') ?: '',
        'sandbox'       => (getenv('WSM_TPAY_SANDBOX') ?: '1') !== '0',
    ],

    // ---- VIES : vérification des numéros de TVA intracommunautaire ---------
    // Service public de la Commission européenne : aucun identifiant requis.
    // `requester` est NOTRE numéro de TVA — sans lui, VIES ne délivre pas de
    // numéro de consultation, et il n'y a donc pas de preuve opposable.
    // `enabled=0` coupe l'appel réseau (utile en CI ou hors ligne) : les
    // numéros ne sont alors validés que sur la forme.
    'vies' => [
        'enabled'   => (getenv('WSM_VIES_ENABLED') ?: '1') !== '0',
        'requester' => getenv('WSM_VIES_REQUESTER') ?: '',
        'timeout'   => (int) (getenv('WSM_VIES_TIMEOUT') ?: 6),
        'ttl'       => (int) (getenv('WSM_VIES_TTL') ?: 2592000),
    ],

    // ---- InPost ShipX : expédition ----------------------------------------
    // geowidget_token est le jeton PUBLIC du sélecteur de Paczkomat (il part
    // dans la page) ; token est le jeton SERVEUR ShipX (il n'en sort jamais).
    'inpost' => [
        'token'           => getenv('WSM_INPOST_TOKEN') ?: '',
        'organization_id' => getenv('WSM_INPOST_ORG_ID') ?: '',
        'geowidget_token' => getenv('WSM_INPOST_GEOWIDGET_TOKEN') ?: '',
        'sending_method'  => getenv('WSM_INPOST_SENDING_METHOD') ?: 'parcel_locker',
        'sandbox'         => (getenv('WSM_INPOST_SANDBOX') ?: '1') !== '0',
    ],
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
