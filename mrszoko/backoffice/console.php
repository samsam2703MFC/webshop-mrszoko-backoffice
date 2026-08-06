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
        header('Location: login.php', true, 303);
        exit;
    }

    $me = wsm_current_user($pdo);
    if (!$me) {
        header('Location: login.php', true, 302);
        exit;
    }

    // LE DROIT SE CALCULE ICI, UNE FOIS, POUR L'ÉCRAN COURANT.
    //
    // Le troisième élément rendu s'appelait « isAdmin » et voulait dire « le
    // rôle est Centrala ». Il veut maintenant dire « ce compte peut ÉCRIRE sur
    // CET écran » — ce que les cent trente endroits qui s'en servent testaient
    // déjà en pratique. Le sens change, pas les appels : c'est ce qui permet
    // de passer d'un modèle binaire à cinq rôles sans réécrire chaque écran,
    // et donc sans risquer d'en oublier un ouvert.
    $ecran = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $droit = wsm_droit_ecran($me, $ecran);

    // LA VOIE DU CODE PASSE PAR ICI, sinon elle n'existe pas.
    //
    // wsm_droit_ecran() ne connaît que les rôles : pour un Administrator,
    // superadmin.php répond ''. Le garde ci-dessous rendrait donc un 404
    // AVANT que la page ait pu demander le code — l'entrée serait dans le
    // rail et mènerait à une porte murée. On ouvre le passage ici ; la page,
    // elle, exige toujours le code avant d'afficher quoi que ce soit.
    if ($droit === '' && $ecran === 'superadmin.php') {
        $plat = console_api_dir() . '/platform.php';
        if (is_file($plat)) {
            require_once $plat;
            if (wsm_super_par_code($me)) $droit = 'w';
        }
    }

    if ($droit === '') {
        // DEUX REFUS QUI NE DISENT PAS LA MÊME CHOSE.
        //
        // « Cet écran n'est pas de ton métier » est un 403 : il existe, il ne
        // t'est pas ouvert, et le dire aide — on sait à qui demander.
        //
        // L'écran de la plateforme, lui, chiffre ce que la boutique doit à
        // qui la lui loue. Un 403 confirmerait à un locataire curieux qu'il y
        // a quelque chose derrière ; superadmin.php écrit d'ailleurs cette
        // règle en toutes lettres et rend un 404. Sauf que depuis que
        // console_boot() garde les écrans, c'est le 403 générique qui partait
        // en premier — la règle était écrite, plus appliquée. Elle l'est de
        // nouveau ici, avant que le reste ne s'exécute. Le « qui » est dans
        // auth.php (wsm_ecran_cache) pour qu'une suite puisse le tenir.
        if (wsm_ecran_cache($ecran)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta charset="utf-8"><title>404</title>'
               . '<p style="font:16px system-ui;padding:2rem">Nie znaleziono strony.</p>');
        }
        // Cacher le lien dans le rail n'est pas une protection : l'adresse se
        // devine, se retient et se partage. La page se garde elle-même.
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $r = h(wsm_role_de($me));
        exit('<!doctype html><meta charset="utf-8"><title>Brak dostępu</title>'
           . '<p style="font:16px/1.6 system-ui;max-width:60ch;margin:12vh auto;padding:0 20px">'
           . '<b>Ten ekran nie należy do roli ' . $r . '.</b><br>'
           . 'Nie chodzi o pomyłkę — po prostu nie jest częścią Twojej pracy. '
           . 'Jeśli powinien być, poproś administratora o zmianę roli.<br>'
           . '<a href="pulpit.php">← Wróć na pulpit</a></p>');
    }
    wsm_console_mesure($pdo, $me, $ecran);
    return [$pdo, $me, $droit === 'w'];
}

/**
 * L'ENREGISTREUR DE PAGES, branché au seul endroit par lequel tout passe.
 *
 * Il est posé ICI et nulle part ailleurs : chaque écran de la console démarre
 * par console_boot(), donc aucun ne peut être oublié — et un écran ajouté
 * demain est mesuré sans que personne ait à y penser. C'est la même raison
 * qui a fait dériver la liste du rail depuis console_sections() plutôt que de
 * la recopier : une seconde liste à tenir à jour est une liste qui ment.
 *
 * ON MESURE À L'EXTINCTION, pas maintenant. À cette ligne la page n'est pas
 * rendue, donc sa durée n'existe pas encore. register_shutdown_function()
 * s'exécute quoi qu'il arrive — y compris pour les écrans qui se terminent
 * par un exit(), comme les impressions et les étiquettes, qui autrement
 * n'auraient jamais été comptés.
 *
 * Ce qui part en base est décidé dans usage.php : l'écran, le rôle, la durée.
 * Ni l'adresse complète, ni la personne. Les raisons y sont écrites.
 */
function wsm_console_mesure(PDO $pdo, array $me, string $ecran): void {
    // Règle 5 : un POST est un geste, pas une visite. Il est déjà au journal
    // d'audit, et le compter ici doublerait chaque envoi de formulaire.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    $u = console_api_dir() . '/usage.php';
    if (!is_file($u)) return;                       // déploiement partiel : on se tait
    require_once $u;

    $ecran = wsm_usage_ecran($ecran);
    if ($ecran === '') return;
    $rola  = function_exists('wsm_role_de') ? wsm_role_de($me) : '';
    $skad  = wsm_usage_skad((string) ($_SERVER['HTTP_REFERER'] ?? ''), $ecran);
    $debut = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

    register_shutdown_function(static function () use ($pdo, $ecran, $rola, $skad, $debut) {
        $ms = (int) round((microtime(true) - $debut) * 1000);
        wsm_usage_record($pdo, $ecran, $rola, $ms, $skad);
    });
}

/** Le répertoire de l'API, quel que soit le nom du dossier déployé. */
function console_api_dir(): string {
    return is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
}

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Grosze → « 129,90 zł » avec espaces insécables : un prix ne se coupe pas. */
function pln(int $g): string { return number_format($g / 100, 2, ',', "\u{202F}") . "\u{202F}zł"; }

/**
 * Les écrans PHP, dans l'ordre du travail réel.
 *
 * « Superadmin » n'apparaît que pour le propriétaire de la plateforme, et
 * l'appartenance se lit dans la configuration du serveur — pas dans un rôle
 * de la base, que la console saurait écrire. Ce n'est pas la protection : la
 * page se garde elle-même. C'est simplement qu'un lien mort ou interdit dans
 * le rail invite à essayer, et n'apprend rien à personne.
 */
function console_menu(?array $me = null): array {
    $m = [];
    foreach (console_sections($me) as $items) {
        foreach ($items as $f => $label) $m[$f] = $label;
    }
    return $m;
}

/**
 * Le rail, RANGÉ PAR MÉTIER.
 *
 * À seize entrées, une liste plate n'est plus une liste : c'est un mur. On
 * lit du haut vers le bas jusqu'à retrouver le mot cherché, chaque fois, et
 * rien n'apprend où vivent les choses. Les sections répondent à la question
 * qu'on se pose vraiment — « où est-ce que je regarde une commande », « où
 * est-ce que je change un prix » — et elles rendent la position stable :
 * Faktury est TOUJOURS le troisième de Sprzedaż.
 *
 * Pulpit reste seul en tête, sans titre : c'est le point de départ, pas une
 * catégorie. Ustawienia ferme la marche parce qu'on y va rarement et jamais
 * dans l'urgence.
 *
 * @return array<string, array<string,string>>  titre de section => fichier => libellé
 */
function console_sections(?array $me = null): array {
    // CETTE FONCTION S'APPELLE AUSSI SEULE, sans passer par console_boot() :
    // le déploiement lui demande la liste des écrans à vérifier, et les deux
    // harnais d'audit aussi. Les règles de rôles vivent dans auth.php, que
    // seul console_boot() chargeait — appelée de l'extérieur, elle tombait
    // donc en « undefined function ». La garde du déploiement l'a vu (liste
    // vide = échec), mais mieux vaut que la fonction se suffise.
    if (!function_exists('wsm_droit_ecran')) {
        $api = console_api_dir();
        require_once $api . '/db.php';
        require_once $api . '/delivery.php';      // wsm_audit(), utilisé par auth.php
        require_once $api . '/auth.php';
    }
    $s = [
        // Le tableau de bord n'appartient à aucune section : il les résume.
        '' => ['pulpit.php' => 'Pulpit'],

        // Ce qui rentre : la vente, de la commande à la facture.
        'Sprzedaż' => [
            'zamowienia.php'  => 'Zamówienia',
            'subskrypcje.php' => 'Subskrypcje',
            'faktury.php'     => 'Faktury',
            'ksef.php'        => 'KSeF',
            'wysylka.php'     => 'Wysyłka',
            'zgloszenia.php'  => 'Zgłoszenia',
        ],

        // Les gens. La messagerie est ici et pas ailleurs : un message est
        // toujours quelqu'un, jamais un document.
        'Klienci' => [
            'klienci.php'     => 'Klienci',
            'kontrahenci.php' => 'Kontrahenci',
            'poczta.php'      => 'Poczta',
            'przypomnienia.php' => 'Przypomnienia',
            'kampanie.php'    => 'Kampanie',
        ],

        // Ce qu'on vend, et à quel prix.
        'Katalog' => [
            'produkty.php' => 'Produkty',
            'magazyn.php'  => 'Magazyn',
            'rabaty.php'   => 'Rabaty',
            'tresci.php'   => 'Treści',
            'allegro.php'  => 'Allegro',
        ],

        // Ce qu'on règle une fois et qu'on ne rouvre presque jamais.
        'Ustawienia' => [
            'kraje.php'       => 'Kraje',
            'uzytkownicy.php' => 'Użytkownicy',
            'audyt.php'       => 'Audyt',
            'ustawienia.php'  => 'Ustawienia',
        ],
    ];

    // « Superadmin » s'ouvre à TROIS titres, et le troisième est celui qui
    // rend l'entrée visible pour de vrai :
    //
    //   1. le rôle Superadmin en base ;
    //   2. l'appartenance déclarée côté serveur (superadmin_emails) ;
    //   3. LE CODE DU JOUR — un Administrator voit l'entrée et entre en
    //      tapant le code. C'est le seul des trois qui ne demande rien
    //      d'autre que de connaître le code.
    //
    //  POURQUOI LE TROISIÈME. Avec les deux premiers seulement, l'entrée
    //  n'apparaissait pour PERSONNE : aucun compte ne portait le rôle, la
    //  liste du serveur n'était pas renseignée, et seul un Superadmin peut en
    //  désigner un autre. Le rail était juste — et vide. Un lien qu'aucun
    //  œil ne voit ne mène nulle part.
    //
    //  CE QUE ÇA COÛTE, DIT FRANCHEMENT : le code devient la clé de l'écran
    //  qui chiffre le loyer. Qui le voit une fois par-dessus une épaule l'a.
    //  Il n'est donc proposé qu'aux comptes Administrator — pas à la vente,
    //  pas au magasin, pas à la lecture seule — et l'écran compte les essais.
    $plat = console_api_dir() . '/platform.php';
    if ($me && is_file($plat)) {
        require_once $plat;
        if (wsm_is_superadmin($me) || wsm_super_par_code($me)) {
            $s['Platforma'] = ['superadmin.php' => 'Superadmin'];
        }
    }

    // ON NE MONTRE QUE CE QUI S'OUVRE. Un rail qui propose un écran interdit
    // envoie quelqu'un contre une porte fermée plusieurs fois par jour, et
    // finit par faire croire que l'outil est cassé. Une section vidée de tous
    // ses écrans disparaît avec eux.
    if ($me) {
        // LE FILTRE DOIT CONNAÎTRE LES MÊMES VOIES QUE LA PORTE.
        //
        // wsm_droit_ecran() ne raisonne que sur les rôles : pour un
        // Administrator, superadmin.php répond ''. Ce filtre retirait donc
        // l'entrée juste après qu'on l'ait ajoutée — le rail restait vide et
        // la section « Platforma » n'existait que dans le code. Deux endroits
        // qui décident du même accès doivent décider pareil.
        $ouvre = function (string $f) use ($me): bool {
            if (wsm_droit_ecran($me, $f) !== '') return true;
            return $f === 'superadmin.php' && function_exists('wsm_super_par_code')
                && wsm_super_par_code($me);
        };
        foreach ($s as $titre => $items) {
            $s[$titre] = array_filter($items, $ouvre, ARRAY_FILTER_USE_KEY);
            if (!$s[$titre]) unset($s[$titre]);
        }
    }
    return $s;
}

/**
 * Ouvre la page : en-tête HTML, barre, navigation.
 * $extraCss : le peu de style propre à un écran, quand il y en a.
 */
/**
 * Le fil d'Ariane. Il ne décore pas : sur un écran qui a une vue « détail »
 * (une commande, une facture, un modèle), il dit où l'on est ET donne le
 * chemin du retour. Sans lui, on ne sort d'un détail qu'avec le bouton
 * « précédent » du navigateur.
 *
 * @param array $crumbs  [libellé => href|null], le dernier étant la page courante
 */
function console_crumbs(array $crumbs): void {
    if (!$crumbs) return;
    echo '<nav class="crumbs" aria-label="Ścieżka">';
    $last = array_key_last($crumbs);
    foreach ($crumbs as $label => $href) {
        if ($label !== array_key_first($crumbs)) echo '<span class="sepc" aria-hidden="true">›</span>';
        if ($href !== null && $label !== $last) {
            echo '<a href="' . h((string) $href) . '">' . h((string) $label) . '</a>';
        } else {
            echo '<span aria-current="page">' . h((string) $label) . '</span>';
        }
    }
    echo '</nav>';
}

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
      <?php // Le rail est rangé par métier (console_sections). Une section
            // sans titre — le Pulpit — s'affiche sans en-tête : c'est le point
            // de départ, pas une catégorie. ?>
      <?php
      // « Platforma » sort du rang et descend TOUT EN BAS, sous le lien vers
      // la boutique. Ce n'est pas un écran de la boutique : il chiffre ce que
      // la boutique doit à qui la lui loue. Le laisser au milieu des sections
      // métier le fait lire comme un réglage de plus, et sa place dans la
      // liste bougeait avec les droits de celui qui regardait. Tout en bas,
      // il est toujours au même endroit et ne se confond avec rien.
      //
      // Il reste dans console_sections() : c'est de là que le déploiement
      // tire la liste des écrans à vérifier. Une seconde liste mentirait.
      $sections = console_sections($me);
      $plateforme = $sections['Platforma'] ?? [];
      unset($sections['Platforma']);
      ?>
      <?php foreach ($sections as $titre => $items): ?>
        <?php if ($titre !== ''): ?><span class="sep"><?= h($titre) ?></span><?php endif; ?>
        <?php foreach ($items as $f => $label): ?>
        <a href="<?= h($f) ?>"<?= $f === $file ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <span class="sep">Publiczne</span>
      <a href="../shop/" target="_blank" rel="noopener">Sklep ↗</a>

      <?php if ($plateforme): ?>
      <span class="sep">Platforma</span>
      <?php foreach ($plateforme as $f => $label): ?>
      <a href="<?= h($f) ?>"<?= $f === $file ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <?php endif; ?>
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
