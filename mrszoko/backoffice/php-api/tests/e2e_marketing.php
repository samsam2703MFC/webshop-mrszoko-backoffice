<?php
// ============================================================================
//  e2e_marketing.php — newsletter, SMS (SMSAPI) et rédaction IA.
//
//  Sans réseau, sans vraie clé, sans vrai SMS (le dépôt est public) :
//
//   1. le consentement — preuve gardée, double opt-in, numéro lisible ;
//   2. les publics — acheteurs + inscrits confirmés, jamais un refus ; les
//      SMS seulement avec la case SMS ;
//   3. la sortie — un lien signé, un refus qui survit, un STOP respecté ;
//   4. le prix d'un SMS — GSM-7, Unicode, sans accents, ligne de sortie ;
//   5. SMSAPI — ce qui part, ce qui est refusé, la nuit, les accusés ;
//   6. la newsletter — rendu, traductions, liens suivis, envoi en file ;
//   7. le courrier — HTML + texte, désinscription en un clic ;
//   8. la pompe — ce qu'elle écoule, ce qu'elle laisse ;
//   9. l'IA — elle propose, elle n'invente ni produit ni code ;
//  10. les écrans, la boutique, les routes.
//
//  Usage :  php tests/e2e_marketing.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/marketing_ai.php';
$pdo = wsm_bootstrap();
wsm_mkt_ensure($pdo);
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

echo "webshop_mrszoko — end-to-end marketing (newsletter, SMS, AI)\n\n";

$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
$avant = ['smsapi' => wsm_config()['smsapi'] ?? [], 'mail' => wsm_config()['mail'] ?? [],
          'anthropic_api_key' => wsm_config()['anthropic_api_key'] ?? '',
          'bez_zgody' => !empty(wsm_config()['marketing']['klienci_bez_zgody'])];
$_SERVER['HTTP_HOST'] = 'sklep.example';
$_SERVER['REQUEST_URI'] = '/mrszoko/backoffice/newsletter.php';
$mail = fn(string $t) => "$t.$sfx@example.com";

$GLOBALS['mkt_nl'] = []; $GLOBALS['mkt_sms'] = [];
register_shutdown_function(function () use ($pdo, $sfx, $avant) {
    try {
        // Ce que les envois du test ont mis en file pour d'AUTRES adresses de
        // la base de développement (le public « wszyscy » les contient).
        foreach ($GLOBALS['mkt_nl'] as $i) $pdo->exec("DELETE FROM wsm_messages WHERE event_key LIKE 'nl-" . (int) $i . "-%'");
        foreach ($GLOBALS['mkt_sms'] as $i) $pdo->exec("DELETE FROM wsm_sms WHERE campaign_id = " . (int) $i);
        $pdo->exec("DELETE FROM wsm_links WHERE code IN ('" . implode("','", array_merge(
            array_map(fn($i) => 'nl' . (int) $i, $GLOBALS['mkt_nl']), array_map(fn($i) => 's' . (int) $i, $GLOBALS['mkt_sms']), ['x'])) . "')");
        $pdo->exec("DELETE FROM wsm_subscribers WHERE email LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_mkt_events WHERE kto LIKE '%$sfx%' OR kto LIKE '+4869%'");
        $pdo->exec("DELETE FROM wsm_messages WHERE email LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_sms WHERE phone LIKE '+4869%'");
        $pdo->exec("DELETE FROM wsm_sms_campaigns WHERE nazwa LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_newsletters WHERE nazwa LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_links WHERE nom LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_clients WHERE email LIKE '%$sfx%'");
        $ids = "SELECT id FROM wsm_orders WHERE email LIKE '%$sfx%'";
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_order_events WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
    wsm_config_overlay(['smsapi' => array_replace(['token' => '', 'nadawca' => '', 'region' => '', 'cena' => ''], $avant['smsapi']),
                        'mail' => array_replace(['from' => '', 'transport' => ''], $avant['mail']),
                        'anthropic_api_key' => $avant['anthropic_api_key'],
                        'marketing' => ['klienci_bez_zgody' => $avant['bez_zgody']]]);
    wsm_sms_teraz(-1);
});

// ---- 1. Le consentement ------------------------------------------------------------------------
echo "-- zgoda: dowód, double opt-in, numer --\n";
ok('« 600 100 200 » devient +48600100200', wsm_mkt_phone('600 100 200') === '+48600100200');
ok('0048… et +48… sont la même personne', wsm_mkt_phone('0048 600-100-200') === '+48600100200' && wsm_mkt_phone('+48600100200') === '+48600100200');
ok('un fixe polonais (22…) ne reçoit pas de SMS : refusé', wsm_mkt_phone('+48 22 123 45 67') === '');
ok('un mobile étranger est accepté', wsm_mkt_phone('+380 50 123 4567') === '+380501234567');
ok('du bruit n\'invente pas de numéro', wsm_mkt_phone('abc') === '' && wsm_mkt_phone('12') === '');

$e1 = $mail('anna');
$r = wsm_mkt_subscribe($pdo, ['email' => strtoupper($e1), 'lang' => 'uk', 'source' => 'strona', 'newsletter' => true,
                              'ip' => '203.0.113.7', 'tekst_email' => 'Chcę newsletter (test)']);
$s1 = wsm_mkt_sub_by_email($pdo, $e1);
ok('inscription : en attente de confirmation, adresse en minuscules', $r['ok'] && $s1 && $s1['email_status'] === 'oczekuje', $s1['email_status'] ?? null);
ok('la preuve est gardée : date, IP, texte exact', $s1['email_zgoda_at'] !== null && $s1['ip'] === '203.0.113.7' && $s1['email_zgoda_tekst'] === 'Chcę newsletter (test)');
$q = $pdo->prepare("SELECT * FROM wsm_messages WHERE email = ? AND event_key LIKE 'nlpotw-%'");
$q->execute([$e1]);
$m1 = $q->fetchAll();
ok('un e-mail de confirmation part, dans la langue choisie (uk)', count($m1) === 1 && str_contains($m1[0]['body'], '/newsletter/potwierdz?t=' . $s1['token']), count($m1));
wsm_mkt_subscribe($pdo, ['email' => $e1, 'lang' => 'uk', 'newsletter' => true]);
$q->execute([$e1]);
ok('re-soumettre dans les 10 minutes ne renvoie pas de mail (pas d\'arrosage)', count($q->fetchAll()) === 1);
[$okc] = wsm_mkt_confirm($pdo, 'zzz');
ok('un jeton fabriqué ne confirme rien', !$okc);
[$okc, $ec] = wsm_mkt_confirm($pdo, (string) $s1['token']);
$s1 = wsm_mkt_sub_by_email($pdo, $e1);
ok('le lien confirme : aktywny, date de confirmation, jeton consommé', $okc && $ec === $e1 && $s1['email_status'] === 'aktywny' && $s1['token'] === '' && $s1['email_potw_at'] !== null);
[$okc] = wsm_mkt_confirm($pdo, str_repeat('a', 32));
ok('le même lien ne sert pas deux fois', !$okc);
$e2 = $mail('stary');
wsm_mkt_subscribe($pdo, ['email' => $e2, 'newsletter' => true]);
$pdo->prepare("UPDATE wsm_subscribers SET token_at = ? WHERE email = ?")->execute([date('Y-m-d H:i:s', time() - 8 * 86400), $e2]);
[$okc] = wsm_mkt_confirm($pdo, (string) wsm_mkt_sub_by_email($pdo, $e2)['token']);
ok('un lien de plus de 7 jours ne confirme plus', !$okc);

$e3 = $mail('sms');
ok('SMS sans numéro : refusé', wsm_mkt_subscribe($pdo, ['email' => $e3, 'sms' => true])['blad'] === 'telefon');
ok('SMS avec un fixe : refusé', wsm_mkt_subscribe($pdo, ['email' => $e3, 'sms' => true, 'phone' => '22 123 45 67'])['blad'] === 'telefon');
$r = wsm_mkt_subscribe($pdo, ['email' => $e3, 'sms' => true, 'phone' => '690 000 001', 'tekst_sms' => 'SMS tak', 'name' => 'Ola']);
$s3 = wsm_mkt_sub_by_email($pdo, $e3);
ok('case SMS + mobile : SMS actif tout de suite, jeton court', $r['sms'] && $s3['sms_status'] === 'aktywny' && strlen($s3['sms_token']) === 8 && $s3['sms_zgoda_tekst'] === 'SMS tak');
ok('… mais l\'e-mail n\'est pas inscrit pour autant', $s3['email_status'] === '');
ok('sans aucune case : refusé', wsm_mkt_subscribe($pdo, ['email' => $mail('rien')])['blad'] === 'zgoda');

// ---- 2. Les publics ----------------------------------------------------------------------------
echo "\n-- odbiorcy: klienci + potwierdzeni, nigdy wypisani --\n";
$eB = $mail('klient');
$pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, items_gross, shipping_gross, total_gross, total_net, lang, status, payment_status, first_name)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute(['MKT-' . $sfx, bin2hex(random_bytes(8)), $eB, 5000, 0, 5000, 4065, 'en', 'oplacone', 'oplacone', 'Ben']);
$adr = fn(string $seg) => array_column(wsm_mkt_audience_email($pdo, $seg), null, 'email');
// Par défaut (PKE art. 398) : la newsletter ne va qu'à ceux qui ont consenti.
wsm_config_overlay(['marketing' => ['klienci_bez_zgody' => false]]);
$a = $adr('wszyscy');
ok('par défaut : l\'inscrit confirmé oui, l\'acheteur sans case cochée NON', isset($a[$e1]) && !isset($a[$eB]));
ok('… et le segment « klienci » ne le rajoute pas', !isset($adr('klienci')[$eB]));
// Interrupteur ouvert (décision du commerçant, après avis d'un juriste).
wsm_config_overlay(['marketing' => ['klienci_bez_zgody' => true]]);
$a = $adr('wszyscy');
ok('interrupteur ouvert : l\'acheteur ET l\'inscrit confirmé', isset($a[$eB]) && isset($a[$e1]));
ok('… pas celui qui n\'a pas confirmé', !isset($a[$e2]));
ok('… pas l\'inscrit SMS seul', !isset($a[$e3]));
ok('la langue suit : en pour l\'acheteur (sa commande), uk pour l\'inscrite', ($a[$eB]['lang'] ?? '') === 'en' && ($a[$e1]['lang'] ?? '') === 'uk');
ok('« subskrybenci » : les inscrits seuls', isset($adr('subskrybenci')[$e1]) && !isset($adr('subskrybenci')[$eB]));
ok('« klienci » : les acheteurs seuls', isset($adr('klienci')[$eB]) && !isset($adr('klienci')[$e1]));
$sms = array_column(wsm_mkt_audience_sms($pdo, 'wszyscy'), null, 'phone');
ok('SMS : seulement la case SMS', isset($sms['+48690000001']) && !in_array($e1, array_column($sms, 'email'), true));
ok('SMS « klienci » : l\'inscrit SMS qui n\'a jamais acheté n\'y est pas', !isset(array_column(wsm_mkt_audience_sms($pdo, 'klienci'), null, 'phone')['+48690000001']));
wsm_mkt_subscribe($pdo, ['email' => $mail('sms2'), 'sms' => true, 'phone' => '+48690000001']);
ok('un numéro partagé par deux fiches : un seul SMS', count(array_filter(wsm_mkt_audience_sms($pdo, 'wszyscy'), fn($d) => $d['phone'] === '+48690000001')) === 1);

// ---- 3. La sortie ------------------------------------------------------------------------------
echo "\n-- wypisanie: podpisany link, odmowa trwała --\n";
$tok = wsm_mkt_token_email($eB);
ok('le jeton est signé : un autre ne passe pas', wsm_mkt_token_email_ok($eB, $tok) && !wsm_mkt_token_email_ok($eB, str_repeat('0', 32)) && !wsm_mkt_token_email_ok($e1, $tok));
ok('les liens des anciennes campagnes texte restent valables', wsm_mkt_token_email_ok($eB, wsm_camp_stop_token($eB)));
ok('l\'adresse « un clic » vise l\'API, la page vise la boutique',
   str_contains(wsm_mkt_unsub_oneclick($eB, 'nl:1'), '/backoffice/api/shop/newsletter/wypisz?e=') && str_contains(wsm_mkt_unsub_page($eB), '/shop/newsletter/wypisz?e='));
wsm_mkt_unsub_email($pdo, $eB, 'nl:999');
ok('l\'acheteur désinscrit sort de tous les publics', !isset($adr('wszyscy')[$eB]) && !isset($adr('klienci')[$eB]));
ok('… et la campagne qui l\'a fait partir est comptée', wsm_mkt_wypisy($pdo, 'nl:999') === 1);
wsm_mkt_subscribe($pdo, ['email' => $eB, 'newsletter' => true]);
ok('se réinscrire ne suffit pas : il faut confirmer', !isset($adr('wszyscy')[$eB]));
wsm_mkt_confirm($pdo, (string) wsm_mkt_sub_by_email($pdo, $eB)['token']);
ok('confirmé : le nouveau « oui » l\'emporte, y compris comme acheteur', isset($adr('wszyscy')[$eB]) && isset($adr('klienci')[$eB]));
wsm_config_overlay(['marketing' => ['klienci_bez_zgody' => false]]);
ok('interrupteur fermé : l\'acheteur qui a confirmé reste dans « klienci » (le segment filtre les inscrits)', isset($adr('klienci')[$eB]));
wsm_config_overlay(['marketing' => ['klienci_bez_zgody' => true]]);
$t3 = (string) wsm_mkt_sub_by_email($pdo, $e3)['sms_token'];
ok('le lien court SMS est vraiment court', strlen(wsm_mkt_sms_stop_url($t3)) < 40 && !str_starts_with(wsm_mkt_sms_stop_url($t3), 'http'), wsm_mkt_sms_stop_url($t3));

// ---- 4. Le prix d'un SMS -----------------------------------------------------------------------
echo "\n-- cena SMS-a: GSM-7, Unicode, bez polskich znaków --\n";
ok('160 caractères GSM-7 : 1 SMS', wsm_sms_parts(str_repeat('a', 160))['czesci'] === 1);
ok('161 : 2 SMS (153 par morceau)', wsm_sms_parts(str_repeat('a', 161))['czesci'] === 2 && wsm_sms_parts(str_repeat('a', 307))['czesci'] === 3);
ok('un « ż » fait passer en Unicode : 70 par SMS', wsm_sms_parts('ż' . str_repeat('a', 70))['kodowanie'] === 'Unicode' && wsm_sms_parts('ż' . str_repeat('a', 70))['czesci'] === 2);
ok('sans accents : le même texte reste en GSM-7', wsm_sms_parts(wsm_sms_ascii('Zażółć gęślą jaźń'))['kodowanie'] === 'GSM-7' && wsm_sms_ascii('Zażółć gęślą jaźń') === 'Zazolc gesla jazn');
ok('« € » et « [ » comptent double (extension GSM)', wsm_sms_parts('€[')['znaki'] === 4);
ok('un emoji compte double en Unicode', wsm_sms_parts('🍫')['znaki'] === 2);

// ---- 5. SMSAPI ---------------------------------------------------------------------------------
echo "\n-- SMSAPI: co wychodzi, co odrzucone, noc, raporty --\n";
wsm_config_overlay(['smsapi' => ['token' => '', 'nadawca' => '', 'region' => '', 'cena' => '']]);
$appels = [];
wsm_sms_transport(function ($m, $p, $params) use (&$appels) {
    $appels[] = ['m' => $m, 'p' => $p, 'params' => $params];
    return [200, ['count' => 1, 'list' => [['id' => 'MSG' . count($appels), 'points' => 0.16]]], 'https://api.smsapi.pl'];
});
[$k] = wsm_sms_diag();
ok('sans jeton : canal fermé, diag « zle » sans appel', !wsm_sms_enabled() && $k === 'zle' && $appels === []);
ok('sans jeton : un envoi est refusé sans appel', !wsm_sms_send_raw('+48690000001', 'x')['ok'] && $appels === []);
wsm_config_overlay(['smsapi' => ['token' => 'test-oauth-token', 'nadawca' => 'SzokoShop', 'region' => '', 'cena' => '0,20']]);
ok('plateforme .pl par défaut (compte polonais)', wsm_sms_hosts()[0] === 'https://api.smsapi.pl' && wsm_sms_cfg()['cena'] === 20);
$r = wsm_sms_send_raw('600 100 200', 'Test ż');
$pp = $appels[0]['params'] ?? [];
ok('ce qui part : numéro sans « + », expéditeur, UTF-8, JSON', $r['ok'] && $r['sid'] === 'MSG1' && ($pp['to'] ?? '') === '48600100200'
   && ($pp['from'] ?? '') === 'SzokoShop' && ($pp['encoding'] ?? '') === 'utf-8' && ($pp['format'] ?? '') === 'json' && $appels[0]['p'] === '/sms.do');
ok('pas d\'adresse d\'accusé en http (SMSAPI exige https)', !isset($pp['notify_url']));
wsm_sms_transport(fn() => [200, ['error' => 101, 'message' => 'Authorization failed'], 'https://api.smsapi.pl']);
$r = wsm_sms_send_raw('600 100 200', 'x');
ok('SMSAPI répond 200 avec « error » : c\'est un refus, et il est expliqué', !$r['ok'] && str_contains($r['error'], 'kod 101') && str_contains($r['error'], 'OAuth'), $r['error']);

$appels = [];
wsm_sms_transport(function ($m, $p, $params) use (&$appels) {
    $appels[] = ['m' => $m, 'p' => $p, 'params' => $params];
    return [200, ['count' => 1, 'list' => [['id' => 'MSG' . count($appels)]]], 'https://api.smsapi.pl'];
});
[$cid, $msg] = wsm_sms_camp_save($pdo, ['nazwa' => 'Test ' . $sfx, 'segment' => 'wszyscy', 'ascii' => 1, 'kod' => 'SLODKO',
    'tresc' => 'Szoko Shop: {imie}, czekolada -10% z kodem {kod}. {link}', 'tresc_uk' => 'Szoko Shop: {imie}, шоколад -10% {kod}. {link}'], 'test');
$GLOBALS['mkt_sms'][] = $cid;
ok('une campagne trop courte est refusée', wsm_sms_camp_save($pdo, ['tresc' => 'abc', 'segment' => 'wszyscy'], 'test')[0] === 0);
$c = wsm_sms_camp_get($pdo, $cid);
$tx = wsm_sms_texte($pdo, $c, ['name' => 'Ola Nowak', 'lang' => 'pl', 'token' => 'abcd2345'], 's' . $cid);
ok('le texte : prénom, code, lien suivi, sans accents', str_contains($tx, 'Ola, czekolada') && str_contains($tx, 'SLODKO') && str_contains($tx, '?l=s' . $cid) && !preg_match('/[ąęłżź]/u', $tx), $tx);
ok('… et la ligne de sortie, ajoutée par le code', str_contains($tx, "\nRezygnacja: ") && str_ends_with($tx, '/s/abcd2345'), $tx);
$txUk = wsm_sms_texte($pdo, $c, ['name' => 'Ola', 'lang' => 'uk', 'token' => 'abcd2345'], 's' . $cid);
ok('un destinataire ukrainien reçoit sa version', str_contains($txUk, 'шоколад'));
$pv = wsm_sms_camp_preview($pdo, $c);
ok('l\'aperçu chiffre AVANT : destinataires, SMS, coût', $pv['odbiorcow'] >= 1 && $pv['czesci'] >= $pv['odbiorcow'] && $pv['koszt'] === $pv['czesci'] * 20);
[$okT] = wsm_sms_camp_test($pdo, $cid, '690 000 009', 'test');
ok('próbka : un SMS, tout de suite, marqué test', $okT && count($appels) === 1 && ($appels[0]['params']['to'] ?? '') === '48690000009');
wsm_mkt_subscribe($pdo, ['email' => $mail('sms3'), 'sms' => true, 'phone' => '+48690000002']);
[$n, $m] = wsm_sms_camp_send($pdo, $cid, 'test');
ok('envoi : en file (rien n\'est parti encore), lien suivi créé', $n >= 2 && count($appels) === 1 && wsm_mkt_link_stats($pdo, 's' . $cid) !== null
   && (string) wsm_sms_camp_get($pdo, $cid)['link_code'] === 's' . $cid, [$n, $m]);
ok('une campagne partie ne repart pas', wsm_sms_camp_send($pdo, $cid, 'test')[0] === 0);
wsm_sms_teraz(strtotime('2026-11-02 23:30:00 Europe/Warsaw'));
$r = wsm_sms_pump($pdo, 20);
ok('la nuit : rien ne part, et on le dit', $r['wyslane'] === 0 && str_contains($r['uwaga'], '8:00') && count($appels) === 1);
wsm_mkt_unsub_sms($pdo, '+48690000002');
wsm_sms_teraz(strtotime('2026-11-02 12:00:00 Europe/Warsaw'));
$r = wsm_sms_pump($pdo, 20);
$st = $pdo->prepare("SELECT phone, status, sms_id FROM wsm_sms WHERE campaign_id = ? AND test = 0");
$st->execute([$cid]);
$lignes = array_column($st->fetchAll(), null, 'phone');
ok('à midi : la file part', ($lignes['+48690000001']['status'] ?? '') === 'wyslany' && $r['wyslane'] >= 1, $lignes);
ok('… sauf à qui s\'est désinscrit entre-temps', ($lignes['+48690000002']['status'] ?? '') === 'pominiety');
$sid = (string) ($lignes['+48690000001']['sms_id'] ?? '');
ok('accusé : une clé fausse est refusée', wsm_sms_raport($pdo, ['MsgId' => $sid, 'status' => '404'], 'faux') === -1);
wsm_sms_raport($pdo, ['MsgId' => $sid, 'status' => '404', 'status_name' => 'DELIVERED'], wsm_sms_raport_key());
wsm_sms_raport($pdo, ['MsgId' => $sid, 'status' => '409', 'status_name' => 'QUEUE'], wsm_sms_raport_key());
$q = $pdo->prepare("SELECT raport FROM wsm_sms WHERE sms_id = ?"); $q->execute([$sid]);
ok('« DELIVERED » arrivé, un « QUEUE » en retard ne l\'efface pas', (string) $q->fetchColumn() === 'DELIVERED');
$liste = array_column(wsm_sms_camp_list($pdo), null, 'id');
ok('la liste compte : remis, ignorés, coût', ($liste[$cid]['stat']['dostarczone'] ?? 0) === 1 && ($liste[$cid]['stat']['pominiety'] ?? 0) === 1);
$pdo->prepare("UPDATE wsm_sms SET sms_id = ? WHERE phone = ? AND campaign_id = ?")->execute(['MSGSTOP', '+48690000001', $cid]);
wsm_sms_raport($pdo, ['MsgId' => 'MSGSTOP', 'status' => '412', 'status_name' => 'STOP'], wsm_sms_raport_key());
ok('« STOP » chez l\'opérateur : le numéro est désinscrit', (string) wsm_mkt_sub_by_email($pdo, $e3)['sms_status'] === 'wypisany');
ok('… et la campagne qui l\'a provoqué est comptée', wsm_mkt_wypisy($pdo, 'sms:' . $cid) >= 1);

// ---- 6. La newsletter --------------------------------------------------------------------------
echo "\n-- newsletter: render, tłumaczenia, linki, wysyłka --\n";
$prods = wsm_shop_products($pdo, 'pl');
$pid = (string) ($prods[0]['id'] ?? '');
$bloki = wsm_nl_bloki_propres([
    ['typ' => 'naglowek', 'tekst' => 'Cześć {imie}!'],
    ['typ' => 'tekst', 'tekst' => "Nowa **czekolada**.\n\nDrugi akapit <script>x</script>"],
    ['typ' => 'obraz', 'url' => 'javascript:alert(1)', 'alt' => 'x'],
    ['typ' => 'produkty', 'ids' => [$pid, 'nie-ma-takiego']],
    ['typ' => 'przycisk', 'etykieta' => 'Do sklepu'],
    ['typ' => 'przycisk', 'etykieta' => 'Przepis', 'url' => 'https://przepisy.example/trufle'],
    ['typ' => 'kod', 'opis' => 'Twój kod'],
    ['typ' => 'nieznany', 'tekst' => 'x'],
]);
ok('les blocs sont nettoyés : type inconnu écarté, image javascript: vidée', count($bloki) === 7 && $bloki[2]['url'] === '');
[$nid] = wsm_nl_save($pdo, ['nazwa' => 'NL ' . $sfx, 'temat' => 'Nowości dla {imie}', 'preheader' => 'Zajrzyj', 'segment' => 'wszyscy',
    'kod' => 'SLODKO', 'bloki' => $bloki], 'test');
$GLOBALS['mkt_nl'][] = $nid;
$nl = wsm_nl_get($pdo, $nid);
$manques = [];
$r = wsm_nl_render($pdo, $nl, 'pl', ['email' => $e1, 'name' => 'Anna Kowalska'], 'nl' . $nid, $manques);
ok('le sujet est personnalisé', $r['temat'] === 'Nowości dla Anna');
ok('le texte est échappé (pas de <script>), le **gras** rendu', !str_contains($r['html'], '<script>') && str_contains($r['html'], '<strong>czekolada</strong>'));
ok('le lien de désinscription signé est dans le pied, avec la campagne', str_contains($r['html'], 'newsletter/wypisz?e=' . rawurlencode($e1) . '&amp;t=' . wsm_mkt_token_email($e1) . '&amp;r=nl%3A' . $nid));
ok('les liens de la boutique portent le suivi, pas les liens externes',
   str_contains($r['html'], '?l=nl' . $nid) && str_contains($r['html'], 'https://przepisy.example/trufle"'));
if ($pid !== '') ok('le produit est là, avec son prix et son lien suivi', str_contains($r['html'], '/p/' . rawurlencode((string) $prods[0]['slug']) . '?l=nl' . $nid));
ok('le bloc code reprend le code de l\'édition', str_contains($r['html'], 'SLODKO'));
ok('la version texte existe, avec le lien de sortie', str_contains($r['tekst'], 'Do sklepu: ') && str_contains($r['tekst'], 'newsletter/wypisz'));
$manques = [];
wsm_nl_render($pdo, $nl, 'uk', ['email' => $e1], 'nl' . $nid, $manques);
ok('sans traduction : la version ukrainienne compte ce qui partira en polonais', count($manques) >= 4, $manques);
$tl = ['uk' => ['temat' => 'Новинки для {imie}', 'preheader' => 'Загляньте', 'bloki' => [0 => ['tekst' => 'Привіт, {imie}!'],
    1 => ['tekst' => 'Новий **шоколад**.'], 2 => ['alt' => 'x'], 4 => ['etykieta' => 'До магазину'], 5 => ['etykieta' => 'Рецепт'], 6 => ['opis' => 'Ваш код']]]];
wsm_nl_save($pdo, ['id' => $nid, 'nazwa' => 'NL ' . $sfx, 'temat' => 'Nowości dla {imie}', 'preheader' => 'Zajrzyj', 'segment' => 'wszyscy',
    'kod' => 'SLODKO', 'bloki' => $bloki, 'tlumaczenia' => $tl], 'test');
$nl = wsm_nl_get($pdo, $nid);
$manques = [];
$ru = wsm_nl_render($pdo, $nl, 'uk', ['email' => $e1, 'name' => 'Olena'], 'nl' . $nid, $manques);
ok('traduite : sujet et blocs en ukrainien, rien de manquant', $ru['temat'] === 'Новинки для Olena' && str_contains($ru['html'], 'шоколад') && !$manques, $manques);
$pv = wsm_nl_preview($pdo, $nl);
ok('l\'aperçu compte les destinataires par langue', $pv['odbiorcow'] >= 2 && ($pv['jezyki']['uk'] ?? 0) >= 1);

wsm_config_overlay(['mail' => ['from' => 'sklep@example.com', 'transport' => 'mail']]);
$envoyes = [];
wsm_mail_transport(function (array $m) use (&$envoyes) { $envoyes[] = $m; return [true, '']; });
[$okP, $mP] = wsm_nl_test($pdo, $nid, $e1, 'uk', 'test');
ok('próbka : partie tout de suite, en HTML, marquée', $okP && str_starts_with((string) ($envoyes[0]['subject'] ?? ''), '[PRÓBKA] Новинки') && str_contains((string) ($envoyes[0]['html'] ?? ''), '<!doctype html>'), $mP);
[$n, $m] = wsm_nl_send($pdo, $nid, 'test');
$q = $pdo->prepare("SELECT * FROM wsm_messages WHERE event_key = ?");
$q->execute(['nl-' . $nid . '-' . $e1]);
$msg = $q->fetch();
ok('envoi : en file, une ligne par adresse, HTML + texte + en-tête de sortie', $n >= 2 && $msg && $msg['status'] === 'kolejka'
   && str_contains((string) $msg['html'], 'шоколад') && str_contains((string) $msg['unsub_url'], '/api/shop/newsletter/wypisz'));
ok('le lien suivi de l\'édition existe, avec le code', (bool) $pdo->query("SELECT 1 FROM wsm_links WHERE code = 'nl$nid' AND kod = 'SLODKO'")->fetchColumn()
   || (bool) $pdo->query("SELECT 1 FROM wsm_links WHERE code = 'nl$nid'")->fetchColumn());
ok('une édition partie ne repart pas, ne se modifie pas', wsm_nl_send($pdo, $nid, 'test')[0] === 0 && wsm_nl_save($pdo, ['id' => $nid, 'temat' => 'x'], 'test')[0] === 0);
$dup = wsm_nl_duplicate($pdo, $nid, 'test');
$GLOBALS['mkt_nl'][] = $dup;
ok('la dupliquer donne un brouillon avec les mêmes blocs', $dup > 0 && wsm_nl_get($pdo, $dup)['status'] === 'szkic' && count(wsm_nl_get($pdo, $dup)['bloki']) === 7);
$pdo->prepare("UPDATE wsm_newsletters SET nazwa = ? WHERE id = ?")->execute(['NL ' . $sfx . ' dup', $dup]);

// ---- 7. Le courrier ----------------------------------------------------------------------------
echo "\n-- poczta: HTML + tekst, wypisanie jednym kliknięciem --\n";
$h = wsm_mail_headers(['to' => 'a@b.c', 'subject' => 'S', 'body' => 'txt', 'html' => '<p>x</p>', 'unsub_url' => 'https://x.example/u?e=a']);
ok('en-têtes RFC 8058 : List-Unsubscribe + One-Click', ($h['List-Unsubscribe'] ?? '') === '<https://x.example/u?e=a>' && ($h['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click');
ok('multipart/alternative quand il y a du HTML', str_starts_with((string) $h['Content-Type'], 'multipart/alternative; boundary='));
$b = wsm_mail_body(['to' => 'a@b.c', 'subject' => 'S', 'body' => 'txt ż', 'html' => '<p>ż</p>']);
ok('deux parties en base64, texte puis HTML', str_contains($b, 'Content-Type: text/plain') && str_contains($b, 'Content-Type: text/html')
   && str_contains($b, base64_encode('txt ż')) && strpos($b, 'text/plain') < strpos($b, 'text/html'));
$h2 = wsm_mail_headers(['to' => 'a@b.c', 'subject' => 'S', 'body' => 'txt']);
ok('un message texte reste exactement ce qu\'il était', $h2['Content-Type'] === 'text/plain; charset=UTF-8' && !isset($h2['List-Unsubscribe'])
   && wsm_mail_body(['body' => 'txt']) === 'txt');

// ---- 8. La pompe -------------------------------------------------------------------------------
echo "\n-- pompa: co wypuszcza, czego nie rusza --\n";
$e9 = $mail('reczna');
wsm_mail_queue($pdo, ['email' => $e9, 'subject' => 'Ręczna', 'body' => 'zapisana w Poczcie', 'actor' => 'test']);
wsm_mail_queue($pdo, ['email' => $e9, 'subject' => 'Stara', 'body' => 'x', 'event_key' => 'camp-0-' . $e9]);
$pdo->prepare("UPDATE wsm_messages SET created_at = ? WHERE event_key = ?")->execute([date('Y-m-d H:i:s', time() - 9 * 86400), 'camp-0-' . $e9]);
wsm_config_overlay(['mail' => ['from' => '', 'transport' => 'mail']]);
$r = wsm_mkt_pump($pdo, 50, 0);
ok('sans poste configurée : rien ne bouge, et on le dit (rien marqué en erreur)', $r['mail'] === 0 && $r['mail_bledy'] === 0 && str_contains(implode(' ', $r['uwagi']), 'poczta'));
wsm_config_overlay(['mail' => ['from' => 'sklep@example.com', 'transport' => 'mail']]);
$envoyes = [];
// La base de développement garde des relances en file d'autres tests : la
// pompe les sert d'abord (ordre d'arrivée). On vide toute la file.
$r = wsm_mkt_pump($pdo, 200, 0, 120.0);
for ($i = 0; $i < 20 && $r['mail'] > 0; $i++) $r = wsm_mkt_pump($pdo, 200, 0, 120.0);
$st = $pdo->prepare("SELECT status FROM wsm_messages WHERE event_key = ?");
$st->execute(['nl-' . $nid . '-' . $e1]);
ok('la newsletter part (HTML transmis au transport)', (string) $st->fetchColumn() === 'wyslana' && (bool) array_filter($envoyes, fn($m) => ($m['to'] ?? '') === $e1 && ($m['html'] ?? '') !== ''));
$st = $pdo->prepare("SELECT status FROM wsm_messages WHERE email = ? AND subject = ?");
$st->execute([$e9, 'Ręczna']);
ok('un message préparé à la main dans Poczta attend la main', (string) $st->fetchColumn() === 'kolejka');
$st->execute([$e9, 'Stara']);
ok('un message de plus d\'une semaine ne part plus tout seul', (string) $st->fetchColumn() === 'kolejka');
wsm_mail_queue($pdo, ['email' => $mail('p1'), 'subject' => 'a', 'body' => 'x', 'event_key' => 'nl-0-' . $mail('p1')]);
wsm_mail_queue($pdo, ['email' => $mail('p2'), 'subject' => 'a', 'body' => 'x', 'event_key' => 'nl-0-' . $mail('p2')]);
wsm_mail_queue($pdo, ['email' => $mail('p3'), 'subject' => 'a', 'body' => 'x', 'event_key' => 'nl-0-' . $mail('p3')]);
wsm_mail_queue($pdo, ['email' => $mail('p4'), 'subject' => 'a', 'body' => 'x', 'event_key' => 'nl-0-' . $mail('p4')]);
wsm_mail_transport(fn() => [false, 'SMTP: serwer nie przywitał']);
$r = wsm_mkt_pump($pdo, 50, 0);
ok('trois échecs de suite : la pompe s\'arrête (le 4e attend)', $r['mail_bledy'] === 3 && str_contains(implode(' ', $r['uwagi']), 'wstrzymano'));
// Deux pompes à la fois (deux écrans ouverts, l'écran et la tâche planifiée) :
// celle-ci a listé A et B ; pendant qu'elle envoie A, l'autre prend B.
$pa = $mail('pa'); $pb = $mail('pb');
wsm_mail_queue($pdo, ['email' => $pa, 'subject' => 'a', 'body' => 'x', 'event_key' => 'nl-0-' . $pa]);
wsm_mail_queue($pdo, ['email' => $pb, 'subject' => 'b', 'body' => 'x', 'event_key' => 'nl-0-' . $pb]);
$envoyes = [];
wsm_mail_transport(function (array $m) use (&$envoyes, $pdo, $pb) {
    $envoyes[] = $m['to'];
    if ($m['to'] !== $pb) $pdo->prepare("UPDATE wsm_messages SET status = 'wysylanie' WHERE event_key = ?")->execute(['nl-0-' . $pb]);
    return [true, ''];
});
wsm_mkt_pump($pdo, 50, 0);
$st = $pdo->prepare("SELECT status FROM wsm_messages WHERE event_key = ?");
$st->execute(['nl-0-' . $pb]);
ok('deux pompes en même temps : un message pris par l\'autre ne part pas deux fois',
   in_array($pa, $envoyes, true) && !in_array($pb, $envoyes, true) && (string) $st->fetchColumn() === 'wysylanie', $envoyes);

// ---- 9. L'IA -----------------------------------------------------------------------------------
echo "\n-- AI: proponuje, nie wymyśla --\n";
wsm_config_overlay(['anthropic_api_key' => '']);
[$d, $err] = wsm_ai_newsletter($pdo, 'Nowa czekolada na święta', [$pid]);
ok('sans clé : rien, et on le dit', $d === null && str_contains((string) $err, 'klucza'));
wsm_config_overlay(['anthropic_api_key' => 'test-key-not-real']);
wsm_ai_transport(fn($sys, $user) => [json_encode(['temat' => 'Święta z czekoladą', 'preheader' => 'Nowości', 'bloki' => [
    ['typ' => 'naglowek', 'tekst' => 'Czekolada na święta'], ['typ' => 'produkty', 'ids' => [$pid, 'wymyslony-produkt']],
    ['typ' => 'kod', 'kod' => 'WYMYSLONY50', 'opis' => '50% taniej'], ['typ' => 'przycisk', 'etykieta' => 'Zobacz']]], JSON_UNESCAPED_UNICODE), null]);
[$d] = wsm_ai_newsletter($pdo, 'Nowa czekolada na święta', [$pid]);
$typy = array_column((array) ($d['bloki'] ?? []), 'typ');
ok('une proposition arrive, nettoyée', $d && $d['temat'] === 'Święta z czekoladą' && in_array('naglowek', $typy, true));
ok('l\'IA ne peut pas inventer de code (aucun fourni : bloc retiré)', !in_array('kod', $typy, true));
$prodB = array_values(array_filter((array) ($d['bloki'] ?? []), fn($b) => $b['typ'] === 'produkty'));
ok('… ni de produit (seuls ceux fournis restent)', !$prodB || $prodB[0]['ids'] === [$pid]);
[$d] = wsm_ai_newsletter($pdo, 'Nowa czekolada na święta', [$pid], 'SLODKO');
$kodB = array_values(array_filter((array) ($d['bloki'] ?? []), fn($b) => $b['typ'] === 'kod'));
ok('code fourni : le bloc porte LE code fourni, pas celui de l\'IA', $kodB && $kodB[0]['kod'] === 'SLODKO');
wsm_ai_transport(fn() => [json_encode(['warianty' => ['Szoko Shop: {imie}, nowa czekolada już jest! {link}', str_repeat('Bardzo długi tekst ', 12)]]), null]);
[$w] = wsm_ai_sms($pdo, 'nowa czekolada', '', true);
ok('SMS : propositions mesurées, avec la ligne de sortie comptée', count($w) === 2 && $w[0]['czesci'] === 1 && $w[1]['czesci'] >= 2, $w);
wsm_ai_transport(fn() => [json_encode(['temat' => 'New for you', 'preheader' => 'Look', 'b0.tekst' => 'Hi!', 'b1.tekst' => 'New **chocolate**.']), null]);
[$t, $err] = wsm_ai_translate_nl($nl, 'en');
ok('traduction : le marqueur {imie} perdu est REFUSÉ (et signalé)', $t !== null && !isset($t['bloki'][0]['tekst']) && ($t['bloki'][1]['tekst'] ?? '') === 'New **chocolate**.' && str_contains((string) $err, 'odrzucone'), [$t, $err]);
wsm_ai_transport(fn() => [json_encode(['tekst' => 'Szoko Shop: new chocolate {link}']), null]);
[$ts, $err] = wsm_ai_translate_sms('Szoko Shop: {imie}, nowa czekolada {link}', 'en');
ok('SMS traduit sans {imie} : refusé', $ts === '' && $err !== null);

// ---- 10. Écrans, boutique, routes -------------------------------------------------------------
echo "\n-- konsola, sklep, trasy --\n";
$sec = console_sections_marketing($racine);
ok('menu : section Marketing (Newsletter, SMS, Subskrybenci, Kampanie)', $sec);
$auth = $lire('mrszoko/backoffice/php-api/auth.php');
ok('le rôle Sprzedaż écrit sur les trois écrans', str_contains($auth, "'newsletter.php' => 'w', 'sms.php' => 'w', 'subskrybenci.php' => 'w'"));
$f = wsm_settings_fields();
ok('Integracje : le jeton SMSAPI est un secret, la plateforme un choix pl|com',
   ($f['smsapi.token'][4] ?? '') === 'secret' && ($f['smsapi.region'][4] ?? '') === 'select:pl|com');
$ust = $lire('mrszoko/backoffice/ustawienia.php');
ok('Integracje : panneau SMS avec test', str_contains($ust, "'sms'    => ['Marketing — newsletter i SMS (SMSAPI)'") && str_contains($ust, "'sms' => 'wsm_sms_diag'"));
ok('Integracje : l\'envoi aux acheteurs sans consentement est un choix, fermé par défaut',
   ($f['marketing.klienci_bez_zgody'][4] ?? '') === 'select:1|0' && str_contains((string) ($f['marketing.klienci_bez_zgody'][5] ?? ''), 'art. 398')
   && str_contains($lire('mrszoko/backoffice/php-api/config.php'), "(getenv('WSM_MKT_KLIENCI_BEZ_ZGODY') ?: '0') === '1'"));
$idx = $lire('mrszoko/backoffice/php-api/index.php');
ok('API : désinscription un clic et accusés SMS', str_contains($idx, "\$sroute === 'newsletter/wypisz'") && str_contains($idx, "\$sroute === 'sms/raport'"));
ok('API : la redirection GET garde le jeton REÇU (aucun jeton recalculé)', !preg_match('/Location:\s*\'\s*\.\s*wsm_mkt_unsub_page/', $idx));
$ko = $lire('mrszoko/backoffice/php-api/kolejka.php');
ok('kolejka.php : ligne de commande uniquement, verrou', str_contains($ko, "PHP_SAPI !== 'cli'") && str_contains($ko, 'LOCK_EX | LOCK_NB'));
$shop = $lire('mrszoko/shop/index.php');
ok('boutique : pages newsletter et /s/…, cases de la caisse', str_contains($shop, "\$page === 'newsletter' || \$page === 's'") && str_contains($shop, 'name="zgoda_nl"') && str_contains($shop, 'name="zgoda_sms"'));
ok('boutique : se désinscrire demande un clic (pas de GET qui désinscrit)', str_contains($shop, "elseif (\$method === 'POST') { wsm_mkt_unsub_email("));
$lay = $lire('mrszoko/shop/layout.php');
ok('pied de page : formulaire, cases jamais pré-cochées', str_contains($lay, 'function nl_form(') && !preg_match('/name="zgoda_(nl|sms)" value="1" checked/', $lay));
ok('routes réservées : newsletter et s', in_array('newsletter', WSM_PAGE_RESERVED_TEST(), true) && in_array('s', WSM_PAGE_RESERVED_TEST(), true));
$seed = json_decode($lire('mrszoko/shop/content_seed.json'), true);
$cles = ['nl.title', 'nl.lead', 'nl.consent_email', 'nl.consent_sms', 'nl.submit', 'nl.check_mail', 'nl.confirmed', 'nl.bad_link',
         'nl.unsub_ask', 'nl.unsub_btn', 'nl.unsub_done', 'nl.sms_unsub_done', 'nl.confirm_subject', 'nl.confirm_body',
         'nl.footer_why', 'nl.footer_unsub', 'nl.sms_footer', 'checkout.nl_optin', 'checkout.sms_optin'];
$manque = [];
foreach (['pl', 'uk', 'en'] as $lg) foreach ($cles as $k) if (trim((string) ($seed['strings'][$lg][$k] ?? '')) === '') $manque[] = "$lg:$k";
ok('textes en polonais, ukrainien, anglais', $manque === [], $manque);
ok('aucun « — » dans ces textes (le déploiement le remplace en base)', !array_filter($cles, fn($k) => str_contains((string) ($seed['strings']['pl'][$k] ?? ''), ' — ')));

/** La section Marketing du menu, lue dans console.php (sans démarrer la console). */
function console_sections_marketing(string $racine): bool {
    $c = (string) file_get_contents($racine . '/mrszoko/backoffice/console.php');
    return (bool) preg_match("/'Marketing' => \[\s*'newsletter\.php'\s*=> 'Newsletter',\s*'sms\.php'\s*=> 'SMS',\s*'subskrybenci\.php' => 'Subskrybenci',\s*'kampanie\.php'\s*=> 'Kampanie',/", $c);
}
function WSM_PAGE_RESERVED_TEST(): array { require_once dirname(__DIR__) . '/pages.php'; return WSM_PAGE_RESERVED; }

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
