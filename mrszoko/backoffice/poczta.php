<?php
// ============================================================================
//  poczta.php — la messagerie de la console.
//
//  Ce que l'écran garantit, et qui n'existait pas avant :
//   • une commande qui dépasse le stock part avec un message au client, et ce
//     message est VISIBLE ici — envoyé, en file, ou en erreur ;
//   • une réponse s'écrit depuis un modèle, avec les variables de la commande
//     déjà remplacées, pour ne pas retaper le numéro et le montant ;
//   • les modèles s'éditent en trois langues sans redéploiement.
//
//  Lecture : tout compte actif. Écriture (envoi, modèles) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/mail.php';

$flash = ''; $flashKind = 'ok';
$view  = ($_GET['widok'] ?? '') === 'szablony' ? 'szablony' : 'poczta';

// ---- Actions (réservées à Centrala) ---------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wysyłać wiadomości.'; $flashKind = 'err';
    } elseif (isset($_POST['wyslij'])) {
        $id = (int) $_POST['wyslij'];
        [$ok, $err] = wsm_mail_send($pdo, $id);
        $flash = $ok ? 'Wiadomość wysłana.' : ('Nie wysłano: ' . ($err ?: 'nieznany błąd'));
        $flashKind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['nowa'])) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Adres odbiorcy jest nieprawidłowy.'; $flashKind = 'err';
        } elseif ($subject === '' || trim($body) === '') {
            $flash = 'Temat i treść nie mogą być puste.'; $flashKind = 'err';
        } else {
            $id = wsm_mail_queue($pdo, [
                'order_id'      => ((int) ($_POST['order_id'] ?? 0)) ?: null,
                'email'         => $email,
                'direction'     => 'wyjscie',
                'subject'       => $subject,
                'body'          => $body,
                'template_code' => (string) ($_POST['tpl'] ?? ''),
                'actor'         => (string) ($me['nom'] ?? ''),
            ]);
            if ($id && (int) ($_POST['order_id'] ?? 0)) {
                wsm_order_event($pdo, (int) $_POST['order_id'], 'wiadomosc', $subject, (string) ($me['nom'] ?? ''));
            }
            if ($id && !empty($_POST['teraz'])) {
                [$ok, $err] = wsm_mail_send($pdo, $id);
                $flash = $ok ? 'Wysłano do ' . $email : ('W kolejce — nie udało się wysłać: ' . $err);
                $flashKind = $ok ? 'ok' : 'err';
            } else {
                $flash = $id ? 'Wiadomość zapisana w kolejce.' : 'Nie zapisano wiadomości.';
                $flashKind = $id ? 'ok' : 'err';
            }
        }
    } elseif (isset($_POST['szablon'])) {
        $id = (int) $_POST['szablon'];
        $ev = (string) ($_POST['event'] ?? '');
        if ($ev !== '' && !isset(WSM_MAIL_EVENTS[$ev])) $ev = '';
        $pdo->prepare("UPDATE wsm_mail_templates SET name = ?, subject = ?, body = ?, event = ?, active = ? WHERE id = ?")
            ->execute([
                mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120),
                mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 250),
                (string) ($_POST['body'] ?? ''),
                $ev,
                empty($_POST['active']) ? 0 : 1,
                $id,
            ]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Szablon poczty', 'wsm_mail_templates #' . $id, 'Sieć');
        $flash = 'Szablon zapisany.';
        $view = 'szablony';
    }
}

// ---- Lectures --------------------------------------------------------------
$kpis     = wsm_mail_kpis($pdo);
$blockers = wsm_mail_blockers();
$detail   = isset($_GET['id']) ? wsm_message_by_id($pdo, (int) $_GET['id']) : null;
$edit     = isset($_GET['szablon']) ? (function (PDO $pdo, int $id) {
                $st = $pdo->prepare("SELECT * FROM wsm_mail_templates WHERE id = ?");
                $st->execute([$id]);
                return $st->fetch() ?: null;
            })($pdo, (int) $_GET['szablon']) : null;

$messages  = wsm_messages_list($pdo, ['status' => (string) ($_GET['status'] ?? ''), 'q' => (string) ($_GET['q'] ?? '')]);
$templates = wsm_mail_templates($pdo);
$orders    = wsm_orders_list($pdo, 60);

// Rédaction : commande et modèle choisis en amont, variables déjà remplacées.
$pickOrder = (int) ($_GET['order_id'] ?? 0);
$pickTpl   = (string) ($_GET['tpl'] ?? '');
$draft = ['email' => '', 'subject' => '', 'body' => ''];
$draftOrder = $pickOrder ? wsm_order_by_id($pdo, $pickOrder) : null;
if ($draftOrder) $draft['email'] = (string) $draftOrder['email'];
if ($pickTpl !== '') {
    $tpl = wsm_mail_template($pdo, $pickTpl, (string) ($draftOrder['lang'] ?? 'pl'));
    if ($tpl) {
        $vars = wsm_mail_vars($draftOrder);
        $draft['subject'] = wsm_mail_render((string) $tpl['subject'], $vars);
        $draft['body']    = wsm_mail_render((string) $tpl['body'], $vars);
    }
}

$statusTag = ['wyslana' => 'ok', 'kolejka' => 'wait', 'blad' => 'bad'];
$statusLbl = ['wyslana' => 'Wysłana', 'kolejka' => 'W kolejce', 'blad' => 'Błąd'];

console_head('Poczta', $me, '', $kpis['queued'] ? $kpis['queued'] . ' w kolejce' : '');
console_flash($flash, $flashKind);
?>

<?php if ($blockers): ?>
<p class="warnbox">
  Poczta jeszcze nie wysyła — brakuje: <?= h(implode(', ', $blockers)) ?>.
  Wiadomości są zapisywane w kolejce i nic nie ginie; uzupełnij dane w
  <a href="ustawienia.php">Ustawieniach</a>, potem wyślij je stąd jednym kliknięciem.
</p>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['total'] ?></b><span>Wiadomości</span></div>
  <div class="kpi"><b><?= (int) $kpis['sent'] ?></b><span>Wysłane</span></div>
  <div class="kpi"><b><?= (int) $kpis['queued'] ?></b><span>W kolejce</span></div>
  <div class="kpi"><b><?= (int) $kpis['failed'] ?></b><span>Błędy</span></div>
</div>

<p class="tabs">
  <a href="poczta.php"<?= $view === 'poczta' ? ' class="on"' : '' ?>>Skrzynka</a>
  <a href="poczta.php?widok=szablony"<?= $view === 'szablony' ? ' class="on"' : '' ?>>Szablony i automaty</a>
</p>

<?php if ($view === 'szablony'): ?>

  <?php if ($edit): ?>
  <div class="panel">
    <h2>Szablon <?= h($edit['code']) ?> · <?= h(strtoupper((string) $edit['lang'])) ?></h2>
    <form method="post">
      <input type="hidden" name="szablon" value="<?= (int) $edit['id'] ?>">
      <div class="grid2">
        <label class="field"><span>Nazwa robocza</span>
          <input type="text" name="name" value="<?= h($edit['name']) ?>"></label>
        <label class="field"><span>Wysyłaj automatycznie przy</span>
          <select name="event">
            <option value="">— nie wysyłaj automatycznie —</option>
            <?php foreach (WSM_MAIL_EVENTS as $ev => $lbl): ?>
            <option value="<?= h($ev) ?>"<?= $ev === $edit['event'] ? ' selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label class="field"><span>Temat</span>
        <input type="text" name="subject" value="<?= h($edit['subject']) ?>"></label>
      <label class="field"><span>Treść</span>
        <textarea name="body" rows="14"><?= h($edit['body']) ?></textarea>
        <span class="hint">Zmienne: {{numer}} {{imie}} {{nazwisko}} {{firma}} {{kwota}} {{pozycje}}
          {{brakujace}} {{paczkomat}} {{link}} {{data}} — wstawiane przy wysyłce.</span></label>
      <label class="field" style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="active" value="1"<?= $edit['active'] ? ' checked' : '' ?>>
        <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Szablon aktywny</span>
      </label>
      <div class="actions">
        <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz szablon</button>
        <a class="code" href="poczta.php?widok=szablony">Anuluj</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Szablony</h2>
    <p class="muted small">
      Modele wiadomości, po jednym na język. „Wysyłaj automatycznie przy” decyduje,
      co klient dostaje bez udziału człowieka — w szczególności wtedy, gdy zamówienie
      przekracza stan magazynu: zamówienie przechodzi, a klient od razu wie, że się odezwiemy.
    </p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kod</th><th>Język</th><th>Temat</th><th>Automat</th><th>Stan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): ?>
      <tr>
        <td data-l="Kod"><?= h($t['code']) ?></td>
        <td data-l="Język"><?= h(strtoupper((string) $t['lang'])) ?></td>
        <td data-l="Temat" class="wide"><?= h($t['subject']) ?></td>
        <td data-l="Automat"><?= $t['event'] !== '' ? '<span class="tag">' . h(WSM_MAIL_EVENTS[$t['event']] ?? $t['event']) . '</span>' : '<span class="muted">ręcznie</span>' ?></td>
        <td data-l="Stan"><span class="tag <?= $t['active'] ? 'ok' : 'bad' ?>"><?= $t['active'] ? 'aktywny' : 'wyłączony' ?></span></td>
        <td data-l=""><a class="code" href="poczta.php?widok=szablony&amp;szablon=<?= (int) $t['id'] ?>">Edytuj</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php else: ?>

  <?php if ($detail): ?>
  <div class="panel">
    <h2><?= h($detail['subject']) ?></h2>
    <dl class="kv">
      <dt>Do</dt><dd><?= h($detail['email']) ?></dd>
      <dt>Stan</dt><dd><span class="tag <?= h($statusTag[$detail['status']] ?? '') ?>"><?= h($statusLbl[$detail['status']] ?? $detail['status']) ?></span>
        <?= $detail['error'] !== '' ? ' · ' . h($detail['error']) : '' ?></dd>
      <dt>Utworzono</dt><dd><?= h((string) $detail['created_at']) ?><?= $detail['sent_at'] ? ' · wysłano ' . h((string) $detail['sent_at']) : '' ?></dd>
      <dt>Autor</dt><dd><?= h($detail['actor']) ?><?= $detail['template_code'] !== '' ? ' · szablon ' . h($detail['template_code']) : '' ?></dd>
      <?php if ($detail['order_code']): ?>
      <dt>Zamówienie</dt><dd><a class="code" href="zamowienia.php?id=<?= (int) $detail['order_id'] ?>"><?= h($detail['order_code']) ?></a></dd>
      <?php endif; ?>
    </dl>
    <pre style="white-space:pre-wrap"><?= h($detail['body']) ?></pre>
    <?php if ($isAdmin && $detail['direction'] === 'wyjscie' && $detail['status'] !== 'wyslana'): ?>
    <form method="post" class="actions">
      <button class="primary" type="submit" name="wyslij" value="<?= (int) $detail['id'] ?>">Wyślij teraz</button>
    </form>
    <?php endif; ?>
    <a class="code back" href="poczta.php">← Skrzynka</a>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Nowa wiadomość</h2>
    <form method="get">
      <input type="hidden" name="widok" value="poczta">
      <div class="grid2">
        <label class="field"><span>Zamówienie</span>
          <select name="order_id">
            <option value="0">— bez zamówienia —</option>
            <?php foreach ($orders as $o): ?>
            <option value="<?= (int) $o['id'] ?>"<?= (int) $o['id'] === $pickOrder ? ' selected' : '' ?>>
              <?= h($o['code']) ?> · <?= h($o['client']) ?><?= !empty($o['backorder']) ? ' · do potwierdzenia' : '' ?>
            </option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>Szablon</span>
          <select name="tpl">
            <option value="">— pusty —</option>
            <?php foreach ($templates as $t): if (!$t['active']) continue; ?>
            <option value="<?= h($t['code']) ?>"<?= $t['code'] === $pickTpl ? ' selected' : '' ?>>
              <?= h($t['code']) ?> · <?= h($t['name']) ?>
            </option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <div class="actions"><button type="submit">Wczytaj szablon</button></div>
    </form>

    <form method="post" style="margin-top:12px">
      <input type="hidden" name="nowa" value="1">
      <input type="hidden" name="order_id" value="<?= $pickOrder ?>">
      <input type="hidden" name="tpl" value="<?= h($pickTpl) ?>">
      <label class="field"><span>Do</span>
        <input type="email" name="email" value="<?= h($draft['email']) ?>" required></label>
      <label class="field"><span>Temat</span>
        <input type="text" name="subject" value="<?= h($draft['subject']) ?>" required></label>
      <label class="field"><span>Treść</span>
        <textarea name="body" rows="12" required><?= h($draft['body']) ?></textarea></label>
      <label class="field" style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="teraz" value="1" checked>
        <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Wyślij od razu</span>
      </label>
      <div class="actions">
        <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz i wyślij</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2>Skrzynka</h2>
    <form method="get" class="actions" style="margin-bottom:12px">
      <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="adres lub temat">
      <select name="status">
        <option value="">wszystkie</option>
        <?php foreach (WSM_MAIL_STATUSES as $s): ?>
        <option value="<?= h($s) ?>"<?= ($_GET['status'] ?? '') === $s ? ' selected' : '' ?>><?= h($statusLbl[$s] ?? $s) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Szukaj</button>
    </form>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Do</th><th>Temat</th><th>Zamówienie</th><th>Stan</th><th></th></tr></thead>
      <tbody>
      <?php if (!$messages): ?>
      <tr><td colspan="6" class="muted">Brak wiadomości.</td></tr>
      <?php endif; ?>
      <?php foreach ($messages as $m): ?>
      <tr>
        <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
        <td data-l="Do"><?= h($m['email']) ?></td>
        <td data-l="Temat" class="wide"><a class="code" href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h($m['subject']) ?></a></td>
        <td data-l="Zamówienie"><?= $m['order_code'] ? '<a class="code" href="zamowienia.php?id=' . (int) $m['order_id'] . '">' . h($m['order_code']) . '</a>' : '—' ?></td>
        <td data-l="Stan"><span class="tag <?= h($statusTag[$m['status']] ?? '') ?>"><?= h($statusLbl[$m['status']] ?? $m['status']) ?></span></td>
        <td data-l="">
          <?php if ($isAdmin && $m['status'] !== 'wyslana' && $m['direction'] === 'wyjscie'): ?>
          <form method="post"><button type="submit" name="wyslij" value="<?= (int) $m['id'] ?>">Wyślij</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php endif; ?>
<?php console_foot();
