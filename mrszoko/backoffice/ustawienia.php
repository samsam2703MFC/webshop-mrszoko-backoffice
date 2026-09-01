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
require_once $API . '/ksef.php';   // wsm_ksef_enabled() : la tuile « KSeF gotowy »

$flash = ''; $flashKind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Sans ce jeton, une page tierce fait poster ce formulaire par le
    // navigateur de quelqu'un qui a sa session ouverte, et la console
    // execute la demande comme si elle venait de lui.
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać ustawienia.'; $flashKind = 'err';
    } elseif (isset($_POST['test_polaczenia'])) {
        // ON N'ENREGISTRE RIEN ICI. Le bouton teste ce qui est DEJA en base :
        // tester ce qui vient d'etre tape sans l'enregistrer dirait « ca
        // marche » sur des identifiants que la boutique n'a pas — et l'inverse
        // est pire encore. On enregistre d'abord, on teste ensuite.
        $quoi = (string) $_POST['test_polaczenia'];
        $fn = ['tpay' => 'wsm_tpay_diag', 'inpost' => 'wsm_inpost_diag'][$quoi] ?? '';
        if ($fn === '' || !function_exists($fn)) {
            $flash = 'Nie ma takiego testu.'; $flashKind = 'err';
        } else {
            [$etat, $phrase] = $fn();
            $flash = $phrase;
            $flashKind = $etat === 'ok' ? 'ok' : ($etat === 'uwaga' ? 'warn' : 'err');
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Test połączenia ' . $quoi,
                      mb_substr($phrase, 0, 120), 'Sieć');
        }
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
        $refus = [];
        $changed = wsm_settings_save($pdo, $_POST, (string) ($me['nom'] ?? ''), $refus);
        // UN REFUS SE DIT, ET IL PASSE AVANT LE SUCCÈS. Une clé PEM mal collée
        // enregistrée « en partie » laisserait croire KSeF configuré, et la
        // session échouerait beaucoup plus tard, sur une facture réelle.
        if ($refus) {
            $flash = 'Odrzucone: ' . implode(' · ', array_map(
                fn($k, $e) => $k . ' — ' . $e, array_keys($refus), $refus));
            $flashKind = 'err';
            if ($changed) $flash .= ' · zapisano pozostałe (' . count($changed) . ')';
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Ustawienia integracji',
                      'odrzucone: ' . implode(', ', array_keys($refus)), 'Sieć');
        } elseif ($changed) {
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
    'ksef'   => ['KSeF — krajowy rejestr faktur', 'NIP bierze się z sekcji Faktury — nie powtarzamy go tutaj. Paragony nie idą do rejestru i tak ma być: zgłoszenie paragonu wpisałoby do rejestru dokument, który dla urzędu nie istnieje.'],
    'faktura' => ['Faktury', 'Te dane trafiają na każdy wystawiony dokument. Zmiana nie przepisuje faktur już wystawionych — każda z nich trzyma własną kopię.'],
    'sklep'  => ['Sklep', ''],
];

$state = [
    'tpay'    => wsm_tpay_enabled(),
    'inpost'  => wsm_inpost_enabled(),
    'dpd'     => function_exists('wsm_dpd_enabled') && wsm_dpd_enabled(),
    'mail'    => wsm_mail_enabled(),
    'faktura' => wsm_invoice_blockers() === [],
    'ksef'    => function_exists('wsm_ksef_enabled') && wsm_ksef_enabled(),
];

console_head('Integracje', $me);
console_flash($flash, $flashKind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Integracje' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= $state['tpay'] ? 'TAK' : 'NIE' ?></b><span>tpay gotowy</span></div>
  <div class="kpi"><b><?= $state['inpost'] ? 'TAK' : 'NIE' ?></b><span>InPost gotowy</span></div>
  <div class="kpi"><b><?= $state['mail'] ? 'TAK' : 'NIE' ?></b><span>Poczta gotowa</span></div>
  <div class="kpi"><b><?= $state['ksef'] ? 'TAK' : 'NIE' ?></b><span>KSeF gotowy</span></div>
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
// Un groupe peut être réglé AILLEURS à dessein. « zamowienia » — ce qu'un
// changement d'état déclenche — vit derrière la porte du Superadmin, avec le
// tableau qui explique chaque interrupteur : ces cases décident de ce qui part
// au fisc, elles n'ont pas leur place à côté d'un mot de passe SMTP. On le
// déclare ici pour que la garde continue d'attraper les VRAIS orphelins.
$ailleurs = ['zamowienia' => 'Superadmin → Statusy i wyzwalacze'];
$orphelins = [];
foreach ($view as $k => $f) {
    if (!isset($groups[$f['group']]) && !isset($ailleurs[$f['group']])) $orphelins[$f['group']] = true;
}
if ($orphelins): ?>
<p class="warnbox">
  <b>Uwaga dla wdrażającego :</b> pola z grup
  <b><?= h(implode(', ', array_keys($orphelins))) ?></b> są zadeklarowane,
  ale żaden panel ich nie pokazuje — nikt ich tu nie ustawi.
  Dopisz grupę w <code>ustawienia.php</code>.
</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
      <?= console_csrf_field() ?>
<?php foreach ($groups as $g => [$title, $intro]):
  $fields = array_filter($view, fn($f) => $f['group'] === $g); ?>
  <div class="panel">
    <h2><?= h($title) ?><?php if (isset($state[$g])): ?>
      <span class="tag <?= $state[$g] ? 'ok' : 'bad' ?>"><?= $state[$g] ? 'działa' : 'wyłączone' ?></span>
    <?php endif; ?></h2>
    <?php if ($intro !== ''): ?><p class="muted small"><?= h($intro) ?></p><?php endif; ?>
    <?php
    // LE DIAGNOSTIC DU SÉLECTEUR DE PACZKOMAT.
    //
    // Quand la clé ne convient pas, la caisse affiche « Brak dostępu, sprawdź
    // czy token został wygenerowany dla odpowiedniej witryny » — une phrase
    // d'InPost qui ne nomme NI le site attendu, NI celui qui sert la boutique,
    // et qui recouvre trois causes différentes. On ne peut rien vérifier
    // auprès d'InPost, mais la clé est un JWT : elle porte ses déclarations en
    // clair. On les lit et on les met en face de l'adresse réelle.
    if ($g === 'inpost' && function_exists('wsm_inpost_geo_verdict')):
      [$etat, $phrase] = wsm_inpost_geo_verdict((string) ($_SERVER['HTTP_HOST'] ?? ''));
      if ($etat !== 'ok' || wsm_inpost_geowidget_token() !== ''): ?>
      <p class="warnbox<?= $etat === 'ok' ? ' ok' : '' ?>" style="margin:0 0 14px">
        <b>Mapa paczkomatów:</b> <?= h($phrase) ?>
      </p>
      <?php endif;
    endif; ?>

    <?php // ─── « EST-CE QUE ÇA MARCHE ? », POSÉ LÀ OÙ L'ON COLLE ────────────
          //
          // Coller un identifiant et n'avoir aucun retour, c'est l'apprendre le
          // jour où un client a payé — ou pire, le jour où il n'a PAS pu payer
          // et où personne n'était devant l'écran. Le bouton vit à côté du
          // champ, pas sur un autre écran : c'est ici qu'on vient, et c'est ici
          // qu'on se demande si c'est bon.
          //
          // Il marche CANAL FERMÉ, volontairement : c'est précisément quand
          // rien ne fonctionne qu'on a besoin de savoir pourquoi.
          $diag = ['tpay' => 'wsm_tpay_diag', 'inpost' => 'wsm_inpost_diag'];
          if (isset($diag[$g]) && function_exists($diag[$g])): ?>
      <p class="actions" style="margin:0 0 14px">
        <button type="submit" name="test_polaczenia" value="<?= h($g) ?>" formnovalidate>Sprawdź połączenie</button>
        <?php if ($g === 'tpay' && function_exists('wsm_tpay_notify_url')): ?>
        <span class="muted small">Adres powiadomień do wklejenia w panelu tpay:
          <code><?= h(wsm_tpay_notify_url()) ?></code></span>
        <?php endif; ?>
      </p>
      <?php endif; ?>

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
        <?php elseif ($f['type'] === 'image'): ?>
          <?php $img = (string) ($f['show'] ?? ''); $aImg = $img !== '' && $img !== 'xxxx'; ?>
          <?php if ($aImg): ?>
            <img src="<?= h(img_src($img)) ?>" alt="" class="ust-foto">
          <?php endif; ?>
          <input type="file" name="<?= h($f['form']) ?>" accept="image/jpeg,image/png,image/webp">
          <?php if ($aImg): ?>
          <label class="chk"><input type="checkbox" name="<?= h($f['form']) ?>__usun" value="1"><span>Usuń zdjęcie przy zapisie</span></label>
          <?php endif; ?>
          <small><?= $aImg ? 'Wybierz plik, żeby podmienić. Puste pole = bez zmian.' : 'JPEG · PNG · WebP, maks. 8 MB.' ?></small>
        <?php elseif ($f['type'] === 'pem'): ?>
          <textarea name="<?= h($f['form']) ?>" rows="4" class="pem" spellcheck="false"
                    placeholder="-----BEGIN PUBLIC KEY-----&#10;…&#10;-----END PUBLIC KEY-----"></textarea>
          <small class="muted">Wklej klucz, żeby go zmienić. Puste pole = bez zmian.<?php
            $dep = (string) ($f['show'] ?? '');
            if ($dep !== '' && $dep !== 'xxxx') echo ' Zapisany: <code>' . h($dep) . '</code>'; ?></small>
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
      <?= console_csrf_field() ?>
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
