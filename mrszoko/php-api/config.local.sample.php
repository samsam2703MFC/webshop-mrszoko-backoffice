<?php
// Copy to config.local.php ON THE SERVER (it is gitignored and web-denied) and
// fill in the real values. config.php merges it over the defaults. This keeps
// MySQL credentials and the admin token out of git.
return [
    'engine' => 'mysql',
    'mysql'  => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'webshop_mrszoko',
        'user' => 'REPLACE_ME',
        'pass' => 'REPLACE_ME',
    ],
    // Must match the adminToken the back-office sends (localStorage['adminToken']).
    'admin_token' => 'REPLACE_WITH_A_LONG_RANDOM_TOKEN',
];
