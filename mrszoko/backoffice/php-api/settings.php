<?php
// ============================================================================
//  settings.php — les réglages d'intégration saisis depuis le back-office.
//
//  Pourquoi une table plutôt que le fichier serveur : les identifiants tpay,
//  InPost et le compte d'envoi de courrier arrivent APRÈS la mise en ligne, et
//  celui qui les reçoit n'a pas d'accès SSH. L'écran Ustawienia les prend, la
//  base les garde, et rien ne part dans le dépôt — qui est public.
//
//  Trois règles, appliquées ici et nulle part ailleurs :
//
//   1. LE FICHIER SERVEUR L'EMPORTE. config.local.php et les variables
//      d'environnement restent la source d'autorité ; la base ne remplit que
//      ce qu'ils laissent vide. Un réglage posé par l'exploitant ne peut pas
//      être renversé depuis un navigateur.
//
//   2. « xxxx » N'EST PAS UNE VALEUR. Le formulaire est livré avec des
//      espaces réservés ; tant qu'ils y sont, l'intégration reste éteinte
//      (fail-closed) plutôt qu'à moitié branchée sur des identifiants faux.
//
//   3. UN SECRET NE SE RELIT PAS. Une fois enregistré, un secret n'est jamais
//      renvoyé au navigateur : l'écran affiche « ustawione », pas la valeur.
//      Il ne part jamais non plus dans le journal d'audit.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** L'espace réservé livré dans le formulaire : présent = pas encore réglé. */
const WSM_SETTING_PLACEHOLDER = 'xxxx';

/** Une valeur qui ne vaut rien : vide, ou l'espace réservé du formulaire. */
function wsm_setting_blank(string $v): bool {
    $v = trim($v);
    return $v === '' || (bool) preg_match('/^x{2,}$/i', $v);
}

/**
 * Les réglages pilotables depuis la console.
 *   cle => [groupe, libellé, chemin dans wsm_config, variable d'env, type, aide]
 * type : text | secret | bool | select:a|b|c
 */
function wsm_settings_fields(): array {
    return [
        'tpay.merchant_id'   => ['tpay', 'ID sprzedawcy (merchant ID)', ['tpay', 'merchant_id'],   'WSM_TPAY_MERCHANT_ID',   'text',   'Numer ID z panelu tpay.'],
        'tpay.client_id'     => ['tpay', 'Client ID (Open API)',        ['tpay', 'client_id'],     'WSM_TPAY_CLIENT_ID',     'text',   'Z zakładki Integracja → Open API.'],
        'tpay.client_secret' => ['tpay', 'Client secret',               ['tpay', 'client_secret'], 'WSM_TPAY_CLIENT_SECRET', 'secret', 'Pokazywany w panelu tylko raz.'],
        'tpay.security_code' => ['tpay', 'Kod bezpieczeństwa (powiadomienia)', ['tpay', 'security_code'], 'WSM_TPAY_SECURITY_CODE', 'secret', 'Bez niego żadne powiadomienie o płatności nie zostanie przyjęte.'],
        'tpay.sandbox'       => ['tpay', 'Środowisko', ['tpay', 'sandbox'], 'WSM_TPAY_SANDBOX', 'select:1|0', '1 = sandbox (testy), 0 = produkcja (prawdziwe pieniądze).'],

        'inpost.token'           => ['inpost', 'Token ShipX (serwerowy)',  ['inpost', 'token'],           'WSM_INPOST_TOKEN',           'secret', 'Nigdy nie opuszcza serwera.'],
        'inpost.organization_id' => ['inpost', 'ID organizacji',           ['inpost', 'organization_id'], 'WSM_INPOST_ORG_ID',          'text',   'Numer organizacji w ShipX.'],
        'inpost.geowidget_token' => ['inpost', 'Token Geowidget (publiczny)', ['inpost', 'geowidget_token'], 'WSM_INPOST_GEOWIDGET_TOKEN', 'text', 'Trafia do strony — to token przeglądarkowy.'],
        'inpost.sandbox'         => ['inpost', 'Środowisko', ['inpost', 'sandbox'], 'WSM_INPOST_SANDBOX', 'select:1|0', '1 = sandbox, 0 = produkcja.'],

        'mail.transport'   => ['mail', 'Sposób wysyłki', ['mail', 'transport'], 'WSM_MAIL_TRANSPORT', 'select:mail|smtp', 'mail = funkcja serwera, smtp = konto pocztowe.'],
        'mail.from'        => ['mail', 'Adres nadawcy',  ['mail', 'from'],      'WSM_MAIL_FROM',      'text',   'Bez niego poczta jest wyłączona.'],
        'mail.from_name'   => ['mail', 'Nazwa nadawcy',  ['mail', 'from_name'], 'WSM_MAIL_FROM_NAME', 'text',   'Widoczna w skrzynce klienta.'],
        'mail.reply_to'    => ['mail', 'Adres do odpowiedzi', ['mail', 'reply_to'], 'WSM_MAIL_REPLY_TO', 'text', 'Tu trafią odpowiedzi klientów.'],
        'mail.bcc'         => ['mail', 'Kopia ukryta (BCC)',  ['mail', 'bcc'],  'WSM_MAIL_BCC',       'text',   'Kopia każdej wiadomości do archiwum.'],
        'mail.smtp_host'   => ['mail', 'Serwer SMTP',    ['mail', 'smtp_host'], 'WSM_MAIL_SMTP_HOST', 'text',   'Tylko przy sposobie „smtp”.'],
        'mail.smtp_port'   => ['mail', 'Port SMTP',      ['mail', 'smtp_port'], 'WSM_MAIL_SMTP_PORT', 'text',   '587 dla TLS, 465 dla SSL.'],
        'mail.smtp_user'   => ['mail', 'Użytkownik SMTP', ['mail', 'smtp_user'], 'WSM_MAIL_SMTP_USER', 'text',  'Zwykle pełny adres e-mail.'],
        'mail.smtp_pass'   => ['mail', 'Hasło SMTP',     ['mail', 'smtp_pass'], 'WSM_MAIL_SMTP_PASS', 'secret', 'Zapisane w bazie, nigdy nie pokazywane ponownie.'],
        'mail.smtp_secure' => ['mail', 'Szyfrowanie',    ['mail', 'smtp_secure'], 'WSM_MAIL_SMTP_SECURE', 'select:tls|ssl|brak', 'tls dla portu 587, ssl dla 465.'],

        'shop_url' => ['sklep', 'Publiczny adres sklepu', ['shop_url'], 'WSM_SHOP_URL', 'text', 'Używany w linkach wysyłanych klientom i w powrocie z tpay.'],

        // ---- Faktury : ce qui figure sur le document ----------------------
        // Rien n'est en dur dans le code. Le vendeur change d'adresse ou de
        // banque sans redéploiement, et une facture déjà émise garde SA copie
        // (voir invoice.php) : modifier ces champs ne réécrit pas le passé.
        'invoice.seller_name'    => ['faktura', 'Sprzedawca — nazwa',     ['invoice', 'seller_name'],    'WSM_INV_SELLER_NAME',    'text',   'Pełna nazwa z KRS.'],
        'invoice.seller_nip'     => ['faktura', 'Sprzedawca — NIP',       ['invoice', 'seller_nip'],     'WSM_INV_SELLER_NIP',     'text',   'Bez prefiksu PL na fakturze krajowej.'],
        'invoice.seller_address' => ['faktura', 'Sprzedawca — adres',     ['invoice', 'seller_address'], 'WSM_INV_SELLER_ADDRESS', 'text',   'Ulica, kod, miasto, kraj.'],
        'invoice.place'          => ['faktura', 'Miejsce wystawienia',    ['invoice', 'place'],          'WSM_INV_PLACE',          'text',   'Zwykle siedziba firmy.'],
        'invoice.iban'           => ['faktura', 'Numer rachunku (IBAN)',  ['invoice', 'iban'],           'WSM_INV_IBAN',           'text',   'Trafia na każdą fakturę z przelewem.'],
        'invoice.bank'           => ['faktura', 'Nazwa banku',            ['invoice', 'bank'],           'WSM_INV_BANK',           'text',   'Obok numeru rachunku.'],
        'invoice.payment_days'   => ['faktura', 'Termin płatności (dni)', ['invoice', 'payment_days'],   'WSM_INV_PAYMENT_DAYS',   'text',   '0 = płatne przy zamówieniu (tpay pobiera od razu).'],
        'invoice.number_format'  => ['faktura', 'Format numeru',          ['invoice', 'number_format'],  'WSM_INV_NUMBER_FORMAT',  'text',   'xxx = kolejny numer, mm = miesiąc, yy = rok. Numeracja zeruje się wraz z tym, co jest w formacie.'],
    ];
}

/** Les valeurs enregistrées, brutes. */
function wsm_settings_all(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT cle, val FROM wsm_settings")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[(string) $r['cle']] = (string) $r['val'];
    return $out;
}

/** La valeur actuellement en vigueur pour un chemin de configuration. */
function wsm_config_at(array $path) {
    $node = wsm_config();
    foreach ($path as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) return null;
        $node = $node[$k];
    }
    return $node;
}

/**
 * Superpose les réglages de la base sur la configuration — mais UNIQUEMENT
 * là où le fichier serveur et l'environnement n'ont rien dit. Appelé une fois
 * par requête, depuis wsm_bootstrap().
 */
function wsm_settings_apply(PDO $pdo): void {
    $vals = wsm_settings_all($pdo);
    if (!$vals) return;
    $patch = [];
    foreach (wsm_settings_fields() as $key => [$grp, $label, $path, $env, $type, $help]) {
        if (!isset($vals[$key])) continue;
        $v = (string) $vals[$key];
        if (wsm_setting_blank($v)) continue;                 // « xxxx » : rien n'est configuré
        if (getenv($env) !== false) continue;                // l'environnement a parlé
        $cur = wsm_config_at($path);
        if ($type !== 'bool' && $type !== 'select:1|0' && is_string($cur) && $cur !== '') continue;  // le fichier a parlé

        if ($type === 'select:1|0') $v = ($v === '1' || $v === 'tak');
        if ($key === 'mail.smtp_port') $v = (int) $v;
        if ($key === 'mail.smtp_secure' && $v === 'brak') $v = '';

        // Reconstitue le chemin : ['tpay','client_id'] => ['tpay' => ['client_id' => v]]
        $node = $v;
        foreach (array_reverse($path) as $k) $node = [$k => $node];
        $patch = array_replace_recursive($patch, $node);
    }
    if ($patch) wsm_config_overlay($patch);
}

/**
 * Enregistre le formulaire. Un champ laissé sur son masque (•••) ne touche à
 * rien : c'est la façon de modifier un réglage sans retaper les secrets.
 * Renvoie la liste des clés modifiées — les VALEURS ne sont jamais renvoyées.
 */
function wsm_settings_save(PDO $pdo, array $post, string $actor = ''): array {
    $fields = wsm_settings_fields();
    $now = date('Y-m-d H:i:s');
    $changed = [];
    $up = $pdo->prepare("UPDATE wsm_settings SET val = ?, secret = ?, updated_at = ?, updated_by = ? WHERE cle = ?");
    $ins = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,?,?,?)");
    foreach ($fields as $key => [$grp, $label, $path, $env, $type, $help]) {
        $formKey = str_replace('.', '__', $key);
        if (!array_key_exists($formKey, $post)) continue;
        $v = trim((string) $post[$formKey]);
        if ($type === 'secret' && ($v === '' || str_starts_with($v, '•'))) continue;   // inchangé
        if ($type === 'text' && $v === '') $v = WSM_SETTING_PLACEHOLDER;               // vider = revenir au masque

        $up->execute([$v, $type === 'secret' ? 1 : 0, $now, mb_substr($actor, 0, 120), $key]);
        if ($up->rowCount() === 0) {
            try { $ins->execute([$key, $v, $type === 'secret' ? 1 : 0, $now, mb_substr($actor, 0, 120)]); }
            catch (Throwable $e) { continue; }
        }
        $changed[] = $key;
    }
    return $changed;
}

/**
 * Ce que l'écran affiche : par champ, la valeur montrable, sa provenance et
 * son état. Un secret n'est JAMAIS renvoyé — seulement le fait qu'il existe.
 */
function wsm_settings_view(PDO $pdo): array {
    $vals = wsm_settings_all($pdo);
    $out = [];
    foreach (wsm_settings_fields() as $key => [$grp, $label, $path, $env, $type, $help]) {
        $db = (string) ($vals[$key] ?? '');
        $effective = wsm_config_at($path);
        $set = is_bool($effective) ? true : (is_int($effective) ? $effective !== 0 : !wsm_setting_blank((string) $effective));

        $source = 'brak';
        if (getenv($env) !== false)                      $source = 'serwer';
        elseif (!wsm_setting_blank($db))                 $source = 'baza';
        elseif (str_starts_with($type, 'select:'))       $source = 'domyślne';
        elseif ($set)                                    $source = 'serwer';

        if ($type === 'secret') {
            $show = $set ? str_repeat('•', 12) : WSM_SETTING_PLACEHOLDER;
        } elseif ($type === 'select:1|0') {
            $show = ($effective === true || $effective === '1' || $effective === 1) ? '1' : '0';
        } else {
            $show = is_scalar($effective) && (string) $effective !== '' ? (string) $effective : WSM_SETTING_PLACEHOLDER;
            if ($key === 'mail.smtp_secure' && $show === WSM_SETTING_PLACEHOLDER) $show = 'tls';
        }

        $out[$key] = [
            'group' => $grp, 'label' => $label, 'type' => $type, 'help' => $help,
            'env' => $env, 'show' => $show, 'set' => $set, 'source' => $source,
            'form' => str_replace('.', '__', $key),
            'locked' => getenv($env) !== false,
        ];
    }
    return $out;
}
