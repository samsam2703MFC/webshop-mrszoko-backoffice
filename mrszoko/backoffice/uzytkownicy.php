<?php
// ============================================================================
//  uzytkownicy.php — les comptes et les rôles.
//
//  L'écran de la console héritée listait les utilisateurs et ne pouvait rien
//  écrire : il n'existait aucune route d'écriture côté API. Changer un rôle
//  n'avait donc aucun effet. C'était le bug.
//
//  Ici, les règles d'auth.php sont appliquées telles quelles, plus trois
//  garde-fous qu'un écran de gestion de comptes doit avoir :
//
//   1. ON NE SE VERROUILLE PAS DEHORS. Impossible de se désactiver soi-même,
//      ni de retirer le dernier compte Centrala capable de se connecter.
//      Sans cette règle, un clic malheureux ferme la console à tout le monde
//      et il faut un accès SSH pour rentrer.
//   2. UN MOT DE PASSE NE SE RELIT PAS. On le pose, on ne l'affiche jamais.
//      Minimum dix caractères — le même plancher que le déploiement.
//   3. TOUT CHANGEMENT EST DANS L'AUDIT, avec l'auteur réel.
//
//  Lecture : tout compte actif. Écriture : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();

$flash = ''; $kind = 'ok';

/** Les comptes actifs qui peuvent réellement se connecter avec le rôle siège. */
function admins_restants(PDO $pdo, int $exceptId = 0): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_users
                          WHERE act = 1 AND role = ? AND password_hash IS NOT NULL AND password_hash <> ''
                            AND id <> ?");
    $st->execute([WSM_ROLE_ADMIN, $exceptId]);
    return (int) $st->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać konta.'; $kind = 'err';
    } else {
        $id    = (int) ($_POST['id'] ?? 0);
        $meId  = (int) ($me['id'] ?? 0);
        $st    = $pdo->prepare("SELECT * FROM wsm_users WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();

        if (isset($_POST['nowy'])) {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $pass  = (string) ($_POST['password'] ?? '');
            $nom   = trim((string) ($_POST['nom'] ?? ''));
            $role  = (string) ($_POST['role'] ?? WSM_ROLE_ADMIN);
            try {
                // La fonction renvoie un message d'exploitation en français ;
                // l'écran est en polonais, on formule donc le nôtre.
                $msg = wsm_set_password($pdo, $email, $pass, $role, $nom);
                $msg = str_contains($msg, 'créé')
                    ? 'Utworzono konto ' . $email . '.'
                    : 'Konto ' . $email . ' istniało — ustawiono nowe hasło.';
                $pdo->prepare("UPDATE wsm_users SET portee = ? WHERE LOWER(email) = ?")
                    ->execute([trim((string) ($_POST['portee'] ?? '')), $email]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Konto', $email . ' · ' . $role, 'Sieć');
                $flash = $msg;
            } catch (InvalidArgumentException $e) {
                $flash = $e->getMessage(); $kind = 'err';
            }
        } elseif (!$u) {
            $flash = 'Nie znaleziono konta.'; $kind = 'err';
        } elseif (isset($_POST['zapisz'])) {
            $role = (string) ($_POST['role'] ?? $u['role']);
            $act  = empty($_POST['act']) ? 0 : 1;

            // Les deux façons de fermer la porte derrière soi.
            if ($id === $meId && $act === 0) {
                $flash = 'Nie możesz wyłączyć własnego konta.'; $kind = 'err';
            } elseif (($act === 0 || $role !== WSM_ROLE_ADMIN)
                      && (string) $u['role'] === WSM_ROLE_ADMIN && admins_restants($pdo, $id) === 0) {
                $flash = 'To ostatnie konto Centrali — po tej zmianie nikt nie wszedłby do konsoli.';
                $kind = 'err';
            } else {
                $pdo->prepare("UPDATE wsm_users SET nom = ?, role = ?, portee = ?, act = ? WHERE id = ?")
                    ->execute([
                        mb_substr(trim((string) ($_POST['nom'] ?? $u['nom'])), 0, 120),
                        $role,
                        mb_substr(trim((string) ($_POST['portee'] ?? '')), 0, 120),
                        $act, $id,
                    ]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana konta',
                          (string) $u['email'] . ' · ' . $role . ($act ? '' : ' · wyłączone'), 'Sieć');
                $flash = 'Zapisano konto ' . $u['email'] . '.';
            }
        } elseif (isset($_POST['haslo'])) {
            try {
                wsm_set_password($pdo, (string) $u['email'], (string) ($_POST['password'] ?? ''),
                                 (string) $u['role'], (string) $u['nom']);
                // Le journal retient QUE le fait, jamais le mot de passe.
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Nowe hasło', (string) $u['email'], 'Sieć');
                $flash = 'Ustawiono nowe hasło dla ' . $u['email'] . '.';
            } catch (InvalidArgumentException $e) {
                $flash = $e->getMessage(); $kind = 'err';
            }
        } elseif (isset($_POST['odblokuj'])) {
            $pdo->prepare("UPDATE wsm_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Odblokowanie', (string) $u['email'], 'Sieć');
            $flash = 'Odblokowano ' . $u['email'] . '.';
        }
    }
}

$users = $pdo->query("SELECT * FROM wsm_users ORDER BY act DESC, role, nom")->fetchAll() ?: [];
$roles = [WSM_ROLE_ADMIN => 'Centrala — pełny dostęp, może zmieniać dane',
          'Franczyza'    => 'Franczyza — tylko podgląd'];
$now = time();

console_head('Użytkownicy', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .usr { border-bottom: 1px solid var(--border-subtle); }
  .usr:last-child { border-bottom: 0; }
  .usr > summary { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
                   padding: 12px 4px; cursor: pointer; list-style: none; }
  .usr > summary::-webkit-details-marker { display: none; }
  .usr > summary::after { content: "▾"; color: var(--text-muted); font-size: 12px; margin-left: auto; }
  .usr[open] > summary::after { content: "▴"; }
  .usr .nm { font-weight: 600; color: var(--text-strong); }
  .usr .em { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  .usr .inner { padding: 6px 4px 20px; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Użytkownicy' => null]);
?>

<div class="panel">
  <h2>Konta i role</h2>
  <p class="why">
    <b>Centrala</b> widzi i zmienia wszystko. <b>Franczyza</b> tylko czyta — otwiera każdy ekran,
    ale przyciski zapisu są dla niej wyłączone (to samo ograniczenie działa w API, nie tylko
    w przeglądarce). Konta bez hasła nie mogą się zalogować w ogóle.
    Nie da się wyłączyć własnego konta ani ostatniej Centrali: inaczej konsola zamknęłaby się
    dla wszystkich i trzeba by wchodzić przez SSH.
  </p>

  <?php foreach ($users as $u):
    $locked = !empty($u['locked_until']) && strtotime((string) $u['locked_until']) > $now;
    $noPass = ((string) ($u['password_hash'] ?? '')) === ''; ?>
  <details class="usr"<?= (int) $u['id'] === (int) ($_GET['id'] ?? 0) ? ' open' : '' ?>>
    <summary>
      <span>
        <span class="nm"><?= h((string) $u['nom']) ?></span>
        <?php if ((int) $u['id'] === (int) ($me['id'] ?? 0)): ?> <span class="tag">to Ty</span><?php endif; ?>
        <br><span class="em"><?= h((string) $u['email']) ?></span>
      </span>
      <span class="tag <?= (string) $u['role'] === WSM_ROLE_ADMIN ? 'ok' : '' ?>"><?= h((string) $u['role']) ?></span>
      <?php if (!$u['act']): ?><span class="tag bad">wyłączone</span><?php endif; ?>
      <?php if ($noPass): ?><span class="tag no">bez hasła</span><?php endif; ?>
      <?php if ($locked): ?><span class="tag bad">zablokowane</span><?php endif; ?>
    </summary>

    <div class="inner">
      <?php if ($locked): ?>
      <p class="warnbox">Konto zablokowane po nieudanych próbach do
        <?= h((string) $u['locked_until']) ?>. Blokada zniknie sama; możesz ją też zdjąć teraz.</p>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <div class="grid2">
          <label class="field"><span>Imię i nazwisko</span>
            <input type="text" name="nom" value="<?= h((string) $u['nom']) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field"><span>Rola</span>
            <select name="role"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php foreach ($roles as $r => $desc): ?>
              <option value="<?= h($r) ?>"<?= (string) $u['role'] === $r ? ' selected' : '' ?>><?= h($desc) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="field"><span>Zakres (informacyjnie)</span>
            <input type="text" name="portee" value="<?= h((string) $u['portee']) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field" style="display:flex;gap:10px;align-items:center;margin-top:22px">
            <input type="checkbox" name="act" value="1"<?= $u['act'] ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
            <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Konto aktywne</span>
          </label>
        </div>
        <dl class="kv" style="margin:6px 0 14px">
          <dt>Ostatnie logowanie</dt><dd><?= h((string) ($u['last_login'] ?? '')) ?: '—' ?></dd>
          <dt>Nieudane próby</dt><dd><?= (int) ($u['failed_attempts'] ?? 0) ?></dd>
        </dl>
        <div class="actions">
          <button class="primary" type="submit" name="zapisz" value="1"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz</button>
          <?php if ($locked && $isAdmin): ?>
          <button type="submit" name="odblokuj" value="1">Odblokuj</button>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($isAdmin): ?>
      <h3>Nowe hasło</h3>
      <p class="why">Hasła nie da się odczytać — można je tylko ustawić na nowo.
        Minimum <?= WSM_PASSWORD_MIN ?> znaków. W dzienniku audytu zapisuje się sam fakt zmiany.</p>
      <form method="post" class="actions">
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <input type="password" name="password" autocomplete="new-password" placeholder="nowe hasło" required>
        <button type="submit" name="haslo" value="1">Ustaw hasło</button>
      </form>
      <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<?php if ($isAdmin): ?>
<div class="panel">
  <h2>Nowe konto</h2>
  <p class="why">Konto powstaje od razu z hasłem — konto bez hasła istnieje, ale nie może się
    zalogować, więc nikomu nie służy.</p>
  <form method="post">
    <input type="hidden" name="nowy" value="1">
    <div class="grid2">
      <label class="field"><span>E-mail</span>
        <input type="email" name="email" required autocomplete="off"></label>
      <label class="field"><span>Imię i nazwisko</span>
        <input type="text" name="nom"></label>
      <label class="field"><span>Rola</span>
        <select name="role">
          <?php foreach ($roles as $r => $desc): ?>
          <option value="<?= h($r) ?>"><?= h($desc) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Hasło (min. <?= WSM_PASSWORD_MIN ?> znaków)</span>
        <input type="password" name="password" autocomplete="new-password" required></label>
      <label class="field"><span>Zakres (informacyjnie)</span>
        <input type="text" name="portee" placeholder="np. Cała sieć"></label>
    </div>
    <div class="actions"><button class="primary" type="submit">Utwórz konto</button></div>
  </form>
</div>
<?php endif; ?>
<?php console_foot();
