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
        // Notre numéro de TVA. Ce n'est PAS un secret — il figure au registre
        // public KRS — et il doit être présent pour que VIES délivre un numéro
        // de consultation opposable. Surchargeable par l'environnement.
        'requester' => getenv('WSM_VIES_REQUESTER') ?: 'PL8971902620',
        'timeout'   => (int) (getenv('WSM_VIES_TIMEOUT') ?: 6),
        'ttl'       => (int) (getenv('WSM_VIES_TTL') ?: 2592000),
    ],

    // ---- Poczta : les messages envoyés aux clients -------------------------
    // Tout est vide par défaut : sans adresse d'expéditeur, la messagerie
    // n'envoie rien et garde les messages en file, visibles dans la console.
    // Ces réglages se saisissent aussi depuis le back-office (écran
    // Ustawienia) — mais ce qui est posé ici l'emporte. Le mot de passe SMTP
    // n'a rien à faire dans ce fichier : ce dépôt est public.
    'mail' => [
        'transport'   => getenv('WSM_MAIL_TRANSPORT') ?: '',   // mail | smtp
        'from'        => getenv('WSM_MAIL_FROM') ?: '',
        'from_name'   => getenv('WSM_MAIL_FROM_NAME') ?: '',
        'reply_to'    => getenv('WSM_MAIL_REPLY_TO') ?: '',
        'bcc'         => getenv('WSM_MAIL_BCC') ?: '',
        'smtp_host'   => getenv('WSM_MAIL_SMTP_HOST') ?: '',
        'smtp_port'   => (int) (getenv('WSM_MAIL_SMTP_PORT') ?: 0),
        'smtp_user'   => getenv('WSM_MAIL_SMTP_USER') ?: '',
        'smtp_pass'   => getenv('WSM_MAIL_SMTP_PASS') ?: '',
        'smtp_secure' => getenv('WSM_MAIL_SMTP_SECURE') ?: '',
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
