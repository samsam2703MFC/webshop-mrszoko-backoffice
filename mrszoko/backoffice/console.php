<?php
// ============================================================================
//  console.php — le châssis commun aux écrans de la console marque.
//
//  Chaque écran (Zamówienia, Produkty, Poczta…) est une page PHP autonome,
//  rendue côté serveur. Ce fichier porte ce qu'elles partagent : l'amorçage,
//  la session, la barre de navigation et la feuille de style. Avant lui,
//  chaque écran recopiait 60 lignes de CSS — corriger l'affichage sur
//  téléphone aurait voulu dire corriger cinq fois la même chose.
//
//  Le format mobile n'est pas une option : la personne qui suit les commandes
//  le fait depuis son téléphone, debout dans l'atelier. Les tableaux se
//  replient en fiches sous 760 px (console.css), la navigation tient dans un
//  menu dépliable sans une ligne de JavaScript, et les zones tactiles font
//  44 px.
// ============================================================================
declare(strict_types=1);

/**
 * Amorce un écran : en-têtes, base, session. Renvoie [PDO, utilisateur, admin].
 * Sans session, renvoie vers la console qui porte l'écran de connexion —
 * exactement la règle de l'API, pas un contrôle parallèle.
 */
function console_boot(): array {
    $api = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
    require_once $api . '/db.php';
    require_once $api . '/auth.php';
    require_once $api . '/delivery.php';        // wsm_audit(), utilisé par auth.php

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: private, no-store');

    $pdo = wsm_bootstrap();
    wsm_session_start();
    $me = wsm_current_user($pdo);
    if (!$me) {
        header('Location: ./', true, 302);
        exit;
    }
    return [$pdo, $me, ($me['role'] ?? '') === WSM_ROLE_ADMIN];
}

/** Le répertoire de l'API, quel que soit le nom du dossier déployé. */
function console_api_dir(): string {
    return is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
}

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Grosze → « 129,90 zł » avec espaces insécables : un prix ne se coupe pas. */
function pln(int $g): string { return number_format($g / 100, 2, ',', "\u{202F}") . "\u{202F}zł"; }

/** Les écrans de la console, dans l'ordre du travail réel. */
function console_menu(): array {
    return [
        'zamowienia.php'  => 'Zamówienia',
        'poczta.php'      => 'Poczta',
        'produkty.php'    => 'Produkty',
        'kontrahenci.php' => 'Kontrahenci',
        'kraje.php'       => 'Kraje',
        'rabaty.php'      => 'Rabaty',
        'ustawienia.php'  => 'Ustawienia',
    ];
}

/**
 * Ouvre la page : en-tête HTML, barre, navigation.
 * $extraCss : le peu de style propre à un écran, quand il y en a.
 */
function console_head(string $title, array $me, string $extraCss = '', string $badge = ''): void {
    $file = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    ?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<title><?= h($title) ?> — Mister Szoko</title>
<link rel="icon" type="image/png" href="img/logo.png">
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<link rel="stylesheet" href="<?= h(console_asset('console.css')) ?>">
<?php if ($extraCss !== ''): ?><style><?= $extraCss ?></style><?php endif; ?>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <a class="brand" href="./"><img src="img/logo.png" alt="Mister Szoko"></a>
    <h1><?= h($title) ?><?= $badge !== '' ? ' <span class="badge">' . h($badge) . '</span>' : '' ?></h1>
    <input type="checkbox" id="wsm-menu" class="menu-toggle">
    <label class="menu-btn" for="wsm-menu">Menu</label>
    <span class="who"><?= h((string) ($me['nom'] ?? '')) ?> · <?= h((string) ($me['role'] ?? '')) ?></span>
    <nav class="menu">
      <a href="./">← Konsola</a>
      <?php foreach (console_menu() as $f => $label): ?>
      <a href="<?= h($f) ?>"<?= $f === $file ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="../shop/" target="_blank" rel="noopener">Sklep ↗</a>
    </nav>
  </div>
</header>
<main class="wrap">
<?php
}

function console_foot(): void {
    ?></main>
</body>
</html>
<?php
}

/**
 * URL d'un fichier statique avec l'empreinte de son contenu. Sans elle, le
 * cache d'une semaine posé par .htaccess sert une feuille de style périmée —
 * la mise en forme corrigée dans le dépôt n'arrive jamais à l'écran.
 */
function console_asset(string $file): string {
    static $v = [];
    if (!isset($v[$file])) {
        $p = __DIR__ . '/' . $file;
        $v[$file] = is_file($p) ? substr(md5((string) filemtime($p) . filesize($p)), 0, 8) : '0';
    }
    return $file . '?v=' . $v[$file];
}

/** Bandeau d'information (succès / erreur / avertissement). */
function console_flash(string $msg, string $kind = 'ok'): void {
    if ($msg === '') return;
    echo '<p class="flash ' . h($kind) . '">' . h($msg) . '</p>';
}
