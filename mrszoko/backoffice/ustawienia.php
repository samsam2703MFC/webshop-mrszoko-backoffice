<?php
// ============================================================================
//  ustawienia.php — les identifiants d'intégration, saisis ici.
//
//  Les champs sont livrés avec « xxxx » : tant qu'on ne les remplace pas,
//  l'intégration reste éteinte plutôt qu'à moitié branchée. Un secret déjà
//  enregistré n'est jamais réaffiché — l'écran montre des points et le mot
//  « ustawione ». Le laisser tel quel ne le modifie pas.
//
//  Ce que le serveur impose (config.local.php, variables d'environnement) est
//  affiché comme verrouillé : un navigateur ne renverse pas un réglage posé
//  par l'exploitant.
//
//  Écriture : Centrala uniquement. Les valeurs ne sont jamais journalisées —
//  l'audit ne retient QUE la liste des clés modifiées.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/settings.php';
require_once $API . '/tpay.php';
require_once $API . '/inpost.php';
require_once $API . '/dpd.php';
require_once $API . '/mail.php';
require_once $API . '/shop.php';
require_once $API . '/invoice.php';

$flash = ''; $flashKind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać ustawienia.'; $flashKind = 'err';
    } elseif (isset($_POST['test_poczty'])) {
        $to = trim((string) ($_POST['test_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Podaj poprawny adres do testu.'; $flashKind = 'err';
        } else {
            $id = wsm_mail_queue($pdo, [
                'email' => $to, 'direction' => 'wyjscie',
                'subject' => 'Mister Szoko — test poczty',
                'body' => "To jest wiadomość testowa z konsoli Mister Szoko.\nJeśli ją widzisz, wysyłka działa.",
                'actor' => (string) ($me['nom'] ?? ''),
            ]);
            [$ok, $err] = $id ? wsm_mail_send($pdo, $id) : [false, 'nie zapisano wiadomości'];
            $flash = $ok ? 'Wysłano wiadomość testową na ' . $to
                         : 'Nie wysłano: ' . ($err ?: 'nieznany błąd') . ' — wiadomość została w kolejce.';
            $flashKind = $ok ? 'ok' : 'err';
        }
    } else {
        $changed = wsm_settings_save($pdo, $_POST, (string) ($me['nom'] ?? ''));
        if ($changed) {
            // L'audit retient les CLÉS, jamais les valeurs.
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Ustawienia integracji',
                      implode(', ', $changed), 'Sieć');
            $flash = 'Zapisano: ' . count($changed) . ' ustawień. Odśwież stronę, aby zobaczyć nowy stan integracji.';
        } else {
            $flash = 'Nic nie zmieniono.';
        }
        // Redirection : évite qu'un rafraîchissement ne renvoie les secrets.
        header('Location: ustawienia.php?ok=' . rawurlencode($flash), true, 303);
        exit;
    }
}

if (isset($_GET['ok'])) $flash = (string) $_GET['ok'];

$view = wsm_settings_view($pdo);
$groups = [
    'tpay'   => ['tpay.com — płatności', 'Bez client_id i client_secret nie powstanie żadna transakcja; bez kodu bezpieczeństwa żadne powiadomienie o płatności nie zostanie przyjęte.'],
    'inpost' => ['InPost ShipX — wysyłka', 'Token serwerowy służy do tworzenia etykiet. Token Geowidget trafia do strony sklepu — to token przeglądarkowy.'],
    'dpd'    => ['DPD Polska — wysyłka pod adres', 'Login, hasło i numer klienta (FID) z panelu DPD. Adres nadawcy jest drukowany na etykiecie: bez niego paczka odrzucona w doręczeniu nie ma dokąd wrócić. API DPD to SOAP — serwer musi mieć rozszerzenie php-soap.'],
    'mail'   => ['Poczta — wiadomości do klientów', 'Bez adresu nadawcy wiadomości czekają w kolejce w zakładce Poczta i nic nie ginie.'],
    'faktura' => ['Faktury', 'Te dane trafiają na każdy wystawiony dokument. Zmiana nie przepisuje faktur już wystawionych — każda z nich trzyma własną kopię.'],
    'sklep'  => ['Sklep', ''],
];

$state = [
    'tpay'    => wsm_tpay_enabled(),
    'inpost'  => wsm_inpost_enabled(),
    'dpd'     => function_exists('wsm_dpd_enabled') && wsm_dpd_enabled(),
    'mail'    => wsm_mail_enabled(),
    'faktura' => wsm_invoice_blockers() === [],
];

console_head('Ustawienia', $me);
console_flash($flash, $flashKind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Ustawienia' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= $state['tpay'] ? 'TAK' : 'NIE' ?></b><span>tpay gotowy</span></div>
  <div class="kpi"><b><?= $state['inpost'] ? 'TAK' : 'NIE' ?></b><span>InPost gotowy</span></div>
  <div class="kpi"><b><?= $state['mail'] ? 'TAK' : 'NIE' ?></b><span>Poczta gotowa</span></div>
  <div class="kpi"><b><?= $state['faktura'] ? 'TAK' : 'NIE' ?></b><span>Faktury gotowe</span></div>
</div>

<p class="warnbox">
  Pola wypełnione <b>xxxx</b> czekają na prawdziwe dane. Dopóki tam są, integracja
  jest wyłączona — sklep działa, ale nie pobierze płatności ani nie utworzy etykiety.
  Zapisane hasła i tokeny nie są nigdy pokazywane ponownie; puste pole hasła oznacza
  „nie zmieniaj”.
</p>

<?php
// UN CHAMP SANS PANNEAU DISPARAÎT SANS UN MOT.
//
// Les champs sont déclarés dans settings.php ; les panneaux, ici. Deux listes,
// et rien ne les appariait : les dix champs DPD ont été déclarés, enregistrés,
// documentés — et jamais affichés, parce qu'aucun panneau ne portait leur
// groupe. array_filter() les écartait en silence, et l'écran avait l'air
// parfaitement normal.
//
// On ne peut pas fusionner les deux listes : un panneau porte un titre et une
// explication, un champ porte un libellé et un type. Mais on peut refuser de
// se taire.
$orphelins = [];
foreach ($view as $k => $f) {
    if (!isset($groups[$f['group']])) $orphelins[$f['group']] = true;
}
if ($orphelins): ?>
<p class="warnbox">
  <b>Uwaga dla wdrażającego :</b> pola z grup
  <b><?= h(implode(', ', array_keys($orphelins))) ?></b> są zadeklarowane,
  ale żaden panel ich nie pokazuje — nikt ich tu nie ustawi.
  Dopisz grupę w <code>ustawienia.php</code>.
</p>
<?php endif; ?>

<form method="post">
<?php foreach ($groups as $g => [$title, $intro]):
  $fields = array_filter($view, fn($f) => $f['group'] === $g); ?>
  <div class="panel">
    <h2><?= h($title) ?><?php if (isset($state[$g])): ?>
      <span class="tag <?= $state[$g] ? 'ok' : 'bad' ?>"><?= $state[$g] ? 'działa' : 'wyłączone' ?></span>
    <?php endif; ?></h2>
    <?php if ($intro !== ''): ?><p class="muted small"><?= h($intro) ?></p><?php endif; ?>
    <div class="grid2">
    <?php foreach ($fields as $key => $f): ?>
      <label class="field">
        <span><?= h($f['label']) ?>
          <?php if ($f['locked']): ?><em class="tag">serwer</em>
          <?php elseif ($f['source'] === 'baza'): ?><em class="tag ok">zapisane</em>
          <?php endif; ?>
        </span>
        <?php if (str_starts_with($f['type'], 'select:')):
          $opts = explode('|', substr($f['type'], 7)); ?>
          <select name="<?= h($f['form']) ?>"<?= $f['locked'] ? ' disabled' : '' ?>>
            <?php foreach ($opts as $o): ?>
            <option value="<?= h($o) ?>"<?= (string) $f['show'] === $o ? ' selected' : '' ?>><?= h($o) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($f['type'] === 'secret'): ?>
          <input type="password" name="<?= h($f['form']) ?>" autocomplete="new-password"
                 placeholder="<?= h((string) $f['show']) ?>"<?= $f['locked'] ? ' disabled' : '' ?>>
        <?php else: ?>
          <input type="text" name="<?= h($f['form']) ?>" value="<?= h((string) $f['show']) ?>"
                 autocomplete="off" spellcheck="false"<?= $f['locked'] ? ' disabled' : '' ?>>
        <?php endif; ?>
        <span class="hint"><?= h($f['help']) ?><?php if ($f['locked']): ?> Ustawione na serwerze (<?= h($f['env']) ?>) — tu tylko do wglądu.<?php endif; ?></span>
      </label>
    <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
  <div class="actions">
    <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz ustawienia</button>
  </div>
</form>

<div class="panel">
  <h2>Test poczty</h2>
  <p class="muted small">Wysyła jedną wiadomość na wskazany adres i zapisuje wynik w zakładce Poczta.</p>
  <form method="post" class="actions">
    <?php // Le champ portait l'adresse de l'utilisateur et AUCUN nom : à
          // l'oreille, une zone de saisie sans intitulé au milieu d'un
          // formulaire de réglages. ?>
    <input type="email" name="test_email" aria-label="Adres, na który wysłać wiadomość testową"
           placeholder="adres do testu" value="<?= h((string) ($me['email'] ?? '')) ?>" required>
    <button type="submit" name="test_poczty" value="1"<?= $isAdmin ? '' : ' disabled' ?>>Wyślij test</button>
  </form>
</div>

<div class="panel">
  <h2>Gdzie to jest zapisane</h2>
  <p class="small">
    Wartości wpisane tutaj trafiają do bazy (tabela <code>wsm_settings</code>), nigdy do repozytorium —
    ono jest publiczne. Jeśli to samo ustawienie jest podane na serwerze
    (<code>config.local.php</code> lub zmienna środowiskowa), <b>serwer ma pierwszeństwo</b>
    i pole jest tutaj zablokowane.
  </p>
</div>
<?php console_foot();
