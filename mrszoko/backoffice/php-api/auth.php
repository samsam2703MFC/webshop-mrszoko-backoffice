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
const WSM_ROLE_ADMIN       = 'Centrala';

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

/** Exige en plus le rôle siège pour écrire. 403 si simplement authentifié. */
function wsm_require_write(PDO $pdo): array {
    $actor = wsm_require_read($pdo);
    if (empty($actor['service']) && ($actor['role'] ?? '') !== WSM_ROLE_ADMIN) {
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
        'admin'  => ($u['role'] ?? '') === WSM_ROLE_ADMIN || !empty($u['service']),
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
