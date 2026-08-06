<?php
// ============================================================================
//  auth.php — authentification de l'API webshop_mrszoko.
//
//  Deux voies, aucune valeur par défaut (fail-closed) :
//   1. SESSION UTILISATEUR — e-mail + mot de passe (wsm_users.password_hash,
//      hachage password_hash/PASSWORD_DEFAULT). Cookie de session HttpOnly,
//      SameSite=Lax, Secure dès que la requête est en HTTPS. C'est la voie des
//      humains ; les rôles de wsm_users sont RÉELLEMENT appliqués.
//   2. JETON DE SERVICE — en-tête X-Admin-Token, pour l'automatisation et les
//      tests. Doit être configuré explicitement (config.local.php ou
//      WSM_ADMIN_TOKEN) : si aucun jeton n'est configuré, cette voie est
//      entièrement désactivée — jamais de secret « par défaut ».
//
//  Règles appliquées :
//   • /landing/content        → public (c'est le site vitrine)
//   • GET  /franchisor/*      → tout utilisateur authentifié et actif
//   • POST /franchisor/*      → rôle « Centrala » (siège) uniquement
// ============================================================================
declare(strict_types=1);

const WSM_SESSION_NAME     = 'WSMSESS';
const WSM_SESSION_IDLE     = 28800;  // 8 h sans activité → session expirée
const WSM_LOGIN_MAX_TRIES  = 5;      // échecs avant verrouillage du compte
const WSM_LOGIN_LOCK       = 900;    // 15 min de verrouillage
const WSM_PASSWORD_MIN     = 10;
// ---------------------------------------------------------------------------
//  LES RÔLES DE LA CONSOLE
//
//  Ils décrivent une boutique en ligne qui vend du chocolat, pas un réseau de
//  franchise : « Centrala » et « Franczyza » venaient de la maquette d'origine
//  et ne nommaient le métier de personne. Surtout, l'ancien modèle était
//  BINAIRE — Centrala écrivait tout, Franczyza ne lisait que tout — ce qui
//  oblige à donner les clés de la facturation à quelqu'un qui doit seulement
//  imprimer des étiquettes.
//
//  Chaque rôle porte donc la liste des écrans qu'il peut OUVRIR, et pour
//  chacun s'il peut y ÉCRIRE. Un écran hors de la liste disparaît du rail et
//  répond 403 : cacher un lien n'est pas une protection, la page se garde
//  elle-même.
//
//  'w' = ouvrir et modifier · 'r' = ouvrir en lecture seule · '*' = tous.
// ---------------------------------------------------------------------------
const WSM_ROLE_ADMIN      = 'Administrator';
const WSM_ROLE_SUPERADMIN = 'Superadmin';

/**
 * L'ancien vocabulaire, et ce qu'il devient. Sert à la migration des comptes
 * existants et à ne pas éjecter quelqu'un dont la ligne n'a pas encore été
 * réécrite : une session ouverte sur « Centrala » doit continuer de marcher.
 */
const WSM_ROLES_ANCIENS = ['Centrala' => WSM_ROLE_ADMIN, 'Franczyza' => 'Podgląd'];

function wsm_roles(): array {
    // Les écrans « métier » de chaque rôle. Les impressions (…_druk.php) et
    // l'étiquette InPost suivent l'écran qui les ouvre.
    $vente  = ['zamowienia.php' => 'w', 'zamowienie_druk.php' => 'w', 'subskrypcje.php' => 'w',
               'klienci.php' => 'w', 'kontrahenci.php' => 'w', 'poczta.php' => 'w',
               'przypomnienia.php' => 'w', 'kampanie.php' => 'w', 'zgloszenia.php' => 'w',
               'rabaty.php' => 'w', 'produkty.php' => 'r', 'faktury.php' => 'r', 'pulpit.php' => 'r'];
    $stock  = ['wysylka.php' => 'w', 'magazyn.php' => 'w', 'magazyn_druk.php' => 'w',
               'etykieta_druk.php' => 'w', 'etykieta_inpost.php' => 'w', 'produkty.php' => 'w',
               'zamowienia.php' => 'r', 'zamowienie_druk.php' => 'r', 'pulpit.php' => 'r'];
    $compta = ['faktury.php' => 'w', 'faktura_druk.php' => 'w', 'ksef.php' => 'w',
               'kontrahenci.php' => 'w', 'zamowienia.php' => 'r', 'klienci.php' => 'r',
               'zgloszenia.php' => 'r', 'pulpit.php' => 'r'];

    return [
        // Le propriétaire de la plateforme. Seul rôle qui ouvre l'écran de
        // facturation de la plateforme — et seul rôle qui peut l'attribuer
        // (voir wsm_peut_donner_role) : sinon un Administrator compromis se
        // hisserait tout seul jusqu'à sa propre facture.
        WSM_ROLE_SUPERADMIN => ['ecrans' => '*', 'ecrit' => true, 'super' => true,
            'aide' => 'Superadmin — tout, y compris la facturation de la plateforme'],
        WSM_ROLE_ADMIN => ['ecrans' => '*', 'ecrit' => true,
            'aide' => 'Administrator — toute la boutique, sauf la plateforme'],
        'Sprzedaż' => ['ecrans' => $vente,
            'aide' => 'Sprzedaż — zamówienia, klienci, poczta, zgłoszenia, rabaty'],
        'Magazyn' => ['ecrans' => $stock,
            'aide' => 'Magazyn — wysyłka, stany, produkty i etykiety'],
        'Księgowość' => ['ecrans' => $compta,
            'aide' => 'Księgowość — faktury, KSeF, dane kontrahentów'],
        // Lecture seule PARTOUT sauf les écrans qui donnent les clés : les
        // comptes et les réglages (jetons, mots de passe) ne se regardent pas
        // « juste pour voir ».
        'Podgląd' => ['ecrans' => '*', 'ecrit' => false, 'sauf' => ['uzytkownicy.php', 'ustawienia.php'],
            'aide' => 'Podgląd — wszystko do czytania, nic do zmiany'],
    ];
}

/** Le rôle d'un compte, ancien vocabulaire compris. */
function wsm_role_de(?array $u): string {
    $r = trim((string) ($u['role'] ?? ''));
    if (!empty($u['service'])) return WSM_ROLE_ADMIN;      // jeton de service
    $r = WSM_ROLES_ANCIENS[$r] ?? $r;
    return isset(wsm_roles()[$r]) ? $r : 'Podgląd';        // rôle inconnu = le moins permissif
}

/**
 * Le droit d'un compte sur un écran : 'w', 'r' ou '' (interdit).
 *
 * Un écran absent de la liste d'un rôle n'est pas « à voir plus tard » : il
 * est fermé. C'est le sens de la liste — on énumère ce qu'on ouvre, jamais ce
 * qu'on ferme, sinon chaque écran livré serait ouvert à tous par défaut.
 */
function wsm_droit_ecran(?array $u, string $ecran): string {
    $role = wsm_role_de($u);
    $def  = wsm_roles()[$role] ?? [];
    if (in_array($ecran, (array) ($def['sauf'] ?? []), true)) return '';
    if (($def['ecrans'] ?? null) === '*') {
        if ($ecran === 'superadmin.php' && empty($def['super'])) return '';
        return !empty($def['ecrit']) ? 'w' : 'r';
    }
    $d = (array) ($def['ecrans'] ?? []);
    return (string) ($d[$ecran] ?? '');
}

/** Qui peut attribuer quel rôle. Seul un Superadmin fabrique un Superadmin. */
function wsm_peut_donner_role(?array $u, string $role): bool {
    if (wsm_droit_ecran($u, 'uzytkownicy.php') !== 'w') return false;
    if ($role === WSM_ROLE_SUPERADMIN) return wsm_role_de($u) === WSM_ROLE_SUPERADMIN;
    return isset(wsm_roles()[$role]);
}

/** La requête courante arrive-t-elle en HTTPS (y compris derrière un proxy) ? */
function wsm_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function wsm_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') return;                 // migrate.php / tests hors HTTP
    ini_set('session.use_strict_mode', '1');        // refuse un identifiant de session non émis par nous
    session_name(WSM_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                          // inaccessible au JavaScript
        'secure'   => wsm_is_https(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function wsm_logout(): void {
    if (PHP_SAPI === 'cli') return;
    wsm_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool) ($p['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** L'utilisateur de la session courante, ou null. Applique l'expiration d'inactivité. */
function wsm_current_user(PDO $pdo): ?array {
    if (PHP_SAPI === 'cli') return null;
    wsm_session_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    $seen = (int) ($_SESSION['seen'] ?? 0);
    if ($seen > 0 && (time() - $seen) > WSM_SESSION_IDLE) { wsm_logout(); return null; }
    $_SESSION['seen'] = time();

    $st = $pdo->prepare("SELECT id, nom, email, role, portee, act FROM wsm_users WHERE id = ?");
    $st->execute([(int) $uid]);
    $u = $st->fetch();
    if (!$u || !$u['act']) { wsm_logout(); return null; }   // compte supprimé ou désactivé → session coupée
    return $u;
}

/** Le jeton de service est-il configuré ET correctement présenté ? */
function wsm_service_token_ok(): bool {
    $cfg = wsm_config();
    $expected = (string) ($cfg['admin_token'] ?? '');
    if ($expected === '') return false;                       // non configuré → voie fermée
    $sent = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    if ($sent === '') return false;
    return hash_equals($expected, $sent);                     // comparaison à temps constant
}

/**
 * Exige une identité pour lire. Renvoie l'acteur :
 *   ['nom','role','service'=>bool].
 * Coupe la requête en 401 si personne n'est authentifié.
 */
function wsm_require_read(PDO $pdo): array {
    if (wsm_service_token_ok()) {
        return ['nom' => 'Konsola marki', 'email' => '', 'role' => WSM_ROLE_ADMIN, 'portee' => '', 'service' => true];
    }
    $u = wsm_current_user($pdo);
    if (!$u) wsm_fail('unauthenticated', 401);
    $u['service'] = false;
    return $u;
}

/**
 * Exige un rôle qui écrit PARTOUT. 403 si simplement authentifié.
 *
 * L'API n'a pas d'écran, donc pas de périmètre à appliquer : elle ne peut pas
 * dire « cette route appartient au magasin ». Elle reste donc réservée aux
 * deux rôles complets, et les rôles métier passent par les écrans, qui
 * connaissent, eux, le leur.
 *
 * Le rôle est lu par wsm_role_de() et pas comparé à la main : un compte encore
 * en « Centrala » — base non migrée, session déjà ouverte — doit continuer de
 * travailler. Comparer la chaîne brute l'aurait éjecté au premier
 * déploiement, et c'est exactement ce qui est arrivé au test d'authentification
 * avant cette ligne.
 */
function wsm_require_write(PDO $pdo): array {
    $actor = wsm_require_read($pdo);
    if (empty($actor['service'])
        && !in_array(wsm_role_de($actor), [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true)) {
        wsm_fail('forbidden_role', 403);
    }
    return $actor;
}

/**
 * Connexion. Message d'erreur identique que l'e-mail existe ou non, délai
 * aléatoire, et verrouillage temporaire après WSM_LOGIN_MAX_TRIES échecs.
 */
function wsm_login(PDO $pdo, string $email, string $password): array {
    $email = strtolower(trim($email));
    $st = $pdo->prepare("SELECT * FROM wsm_users WHERE LOWER(email) = ?");
    $st->execute([$email]);
    $u = $st->fetch();
    $now = time();

    if ($u && !empty($u['locked_until']) && strtotime((string) $u['locked_until']) > $now) {
        wsm_fail('account_locked', 429);
    }

    $hash = (string) ($u['password_hash'] ?? '');
    $ok = $u && $u['act'] && $hash !== '' && password_verify($password, $hash);

    if (!$ok) {
        if ($u) {
            $n = (int) $u['failed_attempts'] + 1;
            $lock = $n >= WSM_LOGIN_MAX_TRIES ? date('Y-m-d H:i:s', $now + WSM_LOGIN_LOCK) : null;
            $pdo->prepare("UPDATE wsm_users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$n, $lock, $u['id']]);
        }
        usleep(random_int(150000, 400000));   // brouille la mesure du temps de réponse
        wsm_fail('bad_credentials', 401);
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $pdo->prepare("UPDATE wsm_users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    $pdo->prepare("UPDATE wsm_users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $u['id']]);

    wsm_session_start();
    session_regenerate_id(true);              // pare la fixation de session
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['seen'] = $now;

    wsm_audit($pdo, (string) $u['nom'], 'Logowanie', 'wsm_users ' . $u['email'], 'Sieć');
    return wsm_public_user($u);
}

/** Projection sans secret, sûre à renvoyer au navigateur. */
function wsm_public_user(array $u): array {
    return [
        'nom'    => $u['nom'] ?? '',
        'email'  => $u['email'] ?? '',
        'role'   => $u['role'] ?? '',
        'portee' => $u['portee'] ?? '',
        // L'API n'a pas d'écran : « admin » y veut dire « peut écrire ».
        // Seuls les deux rôles qui écrivent partout l'obtiennent — un rôle
        // métier passe par les écrans, qui appliquent SON périmètre.
        'admin'  => !empty($u['service'])
                    || in_array(wsm_role_de($u), [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true),
    ];
}

/**
 * Crée ou met à jour le mot de passe d'un compte (CLI : migrate.php).
 * Renvoie un message destiné à l'opérateur ; ne journalise jamais le secret.
 */
function wsm_set_password(PDO $pdo, string $email, string $password, string $role = WSM_ROLE_ADMIN, string $nom = ''): string {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('e-mail invalide');
    if (strlen($password) < WSM_PASSWORD_MIN) throw new InvalidArgumentException('mot de passe trop court (min ' . WSM_PASSWORD_MIN . ')');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $pdo->prepare("SELECT id FROM wsm_users WHERE LOWER(email) = ?");
    $st->execute([$email]);
    $id = $st->fetchColumn();

    if ($id) {
        $pdo->prepare("UPDATE wsm_users SET password_hash = ?, act = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?")
            ->execute([$hash, (int) $id]);
        return "mot de passe mis à jour pour $email";
    }
    $pdo->prepare("INSERT INTO wsm_users (nom, email, role, portee, act, password_hash) VALUES (?,?,?,?,1,?)")
        ->execute([$nom !== '' ? $nom : $email, $email, $role, 'Cała sieć', $hash]);
    return "compte créé : $email (rôle $role)";
}

/** Existe-t-il au moins un compte actif capable de se connecter ? */
function wsm_has_login_account(PDO $pdo): bool {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM wsm_users WHERE act = 1 AND password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn();
        return ((int) $n) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
