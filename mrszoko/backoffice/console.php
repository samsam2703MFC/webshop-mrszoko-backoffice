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

    // Déconnexion : en POST, jamais en GET. Une image distante pointant sur
    // « ?wyloguj=1 » suffirait sinon à éjecter quelqu'un de sa session.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['wyloguj'])) {
        wsm_logout();
        header('Location: index.html', true, 303);
        exit;
    }

    $me = wsm_current_user($pdo);
    if (!$me) {
        header('Location: index.html', true, 302);
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

/** Les écrans PHP, dans l'ordre du travail réel. */
function console_menu(): array {
    return [
        'pulpit.php'      => 'Pulpit',
        'zamowienia.php'  => 'Zamówienia',
        'faktury.php'     => 'Faktury',
        'poczta.php'      => 'Poczta',
        'produkty.php'    => 'Produkty',
        'kontrahenci.php' => 'Kontrahenci',
        'kraje.php'       => 'Kraje',
        'rabaty.php'      => 'Rabaty',
        'ustawienia.php'  => 'Ustawienia',
    ];
}

/**
 * Les écrans de la console exportée. Elle navigue par état interne, sans
 * adresse : impossible d'y pointer directement. console-nav.js lit
 * « #ekran=… » à l'arrivée et clique l'entrée correspondante — c'est ce qui
 * rend ces écrans atteignables d'ici, et pas seulement l'inverse.
 */
function console_erp_menu(): array {
    // Volontairement court. La console héritée porte encore l'attirail d'un
    // réseau de franchise — Sklepy sieci, Promocje sieci, Strefy zasięgu,
    // Analiza geograficzna. Ici on vend en ligne : ces écrans ne décrivent
    // rien de réel et n'ont pas à être proposés. Ne restent que ceux qui
    // servent à une boutique : les comptes et la piste d'audit.
    return [
        'users' => 'Użytkownicy i role',
        'audit' => 'Dziennik audytu',
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
<!-- La case pilote le tiroir sur téléphone. Elle précède tout le reste :
     c'est ce qui permet à une règle CSS d'ouvrir la colonne de gauche sans
     une ligne de JavaScript. -->
<input type="checkbox" id="wsm-menu" class="menu-toggle">
<label class="scrim" for="wsm-menu" aria-hidden="true"></label>

<div class="shell">
  <aside class="side">
    <a class="brand" href="pulpit.php">
      <img src="img/logo.png" alt="Mister Szoko">
      <span>Konsola<br><b>Mister Szoko</b></span>
    </a>
    <!-- Fermer le tiroir depuis l'intérieur : le voile n'est tapable que sur
         la bande restée visible, ce qui ne suffit pas sur un petit écran. -->
    <label class="side-close" for="wsm-menu" title="Zamknij menu">×</label>

    <nav class="menu">
      <span class="sep">Webshop</span>
      <?php foreach (console_menu() as $f => $label): ?>
      <a href="<?= h($f) ?>"<?= $f === $file ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>

      <span class="sep">Konto</span>
      <?php foreach (console_erp_menu() as $k => $label): ?>
      <a href="./#ekran=<?= h($k) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>

      <span class="sep">Publiczne</span>
      <a href="../shop/" target="_blank" rel="noopener">Sklep ↗</a>
    </nav>

    <form class="side-foot" method="post" action="?wyloguj=1">
      <span class="who"><b><?= h((string) ($me['nom'] ?? '')) ?></b><br><?= h((string) ($me['role'] ?? '')) ?></span>
      <button type="submit" title="Wyloguj się">Wyloguj</button>
    </form>
  </aside>

  <div class="main">
    <header class="bar">
      <label class="menu-btn" for="wsm-menu">Menu</label>
      <h1><?= h($title) ?><?= $badge !== '' ? ' <span class="badge">' . h($badge) . '</span>' : '' ?></h1>
    </header>
    <main class="wrap">
<?php
}

function console_foot(): void {
    ?>    </main>
  </div>
</div>
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
