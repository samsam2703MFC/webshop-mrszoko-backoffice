<?php
// ============================================================================
//  e2e_levano.php — Mister Szoko devient Market Levano, et la vitrine dit ce
//  que l'équipe voulait qu'elle dise.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LE NOM CHANGE JUSQU'EN BASE. Le fichier de contenu ne réécrit jamais un
//      texte déjà en base : sans le remplacement en place, la production
//      aurait gardé « Mister Szoko » sous un déploiement vert. Une phrase
//      retouchée garde tout le reste ; le titre du catalogue ne change que
//      s'il est encore celui d'origine. Même travail par la voie SQL.
//   2. UN LOGO DE PAIEMENT EST UNE PROMESSE : par défaut, la vitrine montre
//      les modes de livraison ACTIFS et, pour payer, tpay s'il est configuré,
//      sinon le virement. Le choix de la console fait foi, « rien » compris.
//   3. LES DESSINS NE CHARGENT RIEN : aucune adresse externe, aucun script,
//      les libellés échappés.
//   4. LES VALEURS NUTRITIVES SONT DES CHIFFRES, dans l'ordre du règlement UE
//      1169/2011 ; un mot est refusé. Les propriétés sont des textes traduits.
//   5. LA PAGE SERVIE (8091) : nom et monogramme, illustration, icônes
//      dessinées, téléphone cliquable, logos en pied de page, fiche produit
//      en trois blocs, titre de rayon, et pas une trace de l'ancien nom.
//
//  Usage :  php tests/e2e_levano.php
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
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/seed.php';
require_once dirname(__DIR__) . '/layout.php';
require_once dirname(dirname(__DIR__), 2) . '/shop/znaki.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end Market Levano\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$val = function (string $k, string $lang = 'pl') use ($pdo): ?string {
    $st = $pdo->prepare("SELECT v FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
    $st->execute([$lang, $k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
};

// ---- 1. Le nom -------------------------------------------------------------------------
echo "-- nazwa: Market Levano w plikach, w bazie i w SQL wdrożenia --\n";
foreach (['mrszoko/shop/content_seed.json', 'mrszoko/landing/content_seed.json'] as $f) {
    $doc = json_decode($lire($f), true);
    $txt = json_encode($doc['strings'] ?? [], JSON_UNESCAPED_UNICODE);
    ok("$f : plus un « Mister Szoko » dans les textes", !str_contains($txt, 'Mister Szoko') && !str_contains($txt, 'misterszoko'));
}
$seed = json_decode($lire('mrszoko/shop/content_seed.json'), true)['strings'];
ok('la marque, dans les trois langues', ($seed['pl']['brand'] ?? '') === 'Market Levano' && ($seed['uk']['brand'] ?? '') === 'Market Levano' && ($seed['en']['brand'] ?? '') === 'Market Levano');
ok('l\'adresse et le téléphone de contact', ($seed['pl']['footer.email'] ?? '') === 'MarketLevano@gmail.com' && ($seed['pl']['footer.phone'] ?? '') === '660 884 677');
$neufs = ['home.contact.title', 'metody.ship', 'metody.pay', 'metoda.blik', 'product.props', 'product.nutrition', 'nutri.energy', 'nutri.salt', 'product.category'];
ok('les nouveaux libellés existent en pl, uk et en', !array_filter($neufs, fn($k) => empty($seed['pl'][$k]) || empty($seed['uk'][$k]) || empty($seed['en'][$k])));
ok('… sans tiret cadratin (le déploiement les changerait en virgules)', !array_filter($neufs, fn($k) => str_contains($seed['pl'][$k] . $seed['uk'][$k] . $seed['en'][$k], ' — ')));

// Le remplacement en place : une phrase retouchée garde le reste.
$cle = "test.levano.$sfx";
$pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', ?, ?)")->execute([$cle, 'Napisz do Mister Szoko: pro@misterszoko.com — dziękujemy']);
$pdo->prepare("INSERT INTO wsm_mail_templates (code, lang, name, subject, body, event, active) VALUES (?, 'pl', 't', 'Zamówienie — Mister Szoko', ?, '', 0)")
    ->execute(["test-$sfx", "Dziękujemy.\nMister Szoko"]);
$tytulAvant = $val('catalog.title');
$pdo->prepare("UPDATE wsm_shop_i18n SET v = 'Nasze czekolady' WHERE lang = 'pl' AND k = 'catalog.title'")->execute();
$pdo->prepare("UPDATE wsm_shop_i18n SET v = 'Moje własne czekolady' WHERE lang = 'en' AND k = 'catalog.title'")->execute();
wsm_sync_content($pdo);
ok('le nom change dans une phrase retouchée, le reste reste', $val($cle) === 'Napisz do Market Levano: MarketLevano@gmail.com, dziękujemy', $val($cle));
$mt = $pdo->query("SELECT subject, body FROM wsm_mail_templates WHERE code = 'test-$sfx'")->fetch();
ok('… et dans les messages aux clients, objet et signature', str_contains((string) $mt['subject'], 'Market Levano') && str_ends_with((string) $mt['body'], 'Market Levano'), $mt);
ok('le titre du catalogue d\'origine devient « Nasze produkty »', $val('catalog.title') === 'Nasze produkty', $val('catalog.title'));
ok('… un titre retouché reste intact', $val('catalog.title', 'en') === 'Moje własne czekolady', $val('catalog.title', 'en'));
ok('rejoué, le remplacement ne trouve plus rien', wsm_sync_content($pdo)[2] === 0);
$pdo->prepare("DELETE FROM wsm_shop_i18n WHERE k = ?")->execute([$cle]);
$pdo->prepare("DELETE FROM wsm_mail_templates WHERE code = ?")->execute(["test-$sfx"]);
$pdo->prepare("UPDATE wsm_shop_i18n SET v = ? WHERE lang = 'pl' AND k = 'catalog.title'")->execute([$tytulAvant ?? 'Nasze produkty']);
$pdo->prepare("UPDATE wsm_shop_i18n SET v = 'Our products' WHERE lang = 'en' AND k = 'catalog.title'")->execute();
$sql = wsm_sync_content_sql();
ok('la voie SQL de production fait le même remplacement', str_contains($sql, 'UPDATE wsm_shop_i18n SET v = REPLACE(v, ' . wsm_sql_txt('Mister Szoko') . ', ' . wsm_sql_txt('Market Levano') . ')')
   && str_contains($sql, 'UPDATE wsm_mail_templates SET body = REPLACE(body, ' . wsm_sql_txt('Mister Szoko')));
ok('… et le même titre de catalogue, sur la phrase d\'origine seulement', str_contains($sql, "UPDATE wsm_shop_i18n SET v = " . wsm_sql_txt('Nasze produkty') . " WHERE k = " . wsm_sql_txt('catalog.title')));
ok('le nom lu par la console vient de Treści', wsm_brand_name() === ($val('brand') ?: 'Market Levano'), wsm_brand_name());
$consoleTxt = '';
foreach (glob($racine . '/mrszoko/backoffice/*.php') as $f) foreach (file($f) as $l) if (!preg_match('#^\s*(//|\*|/\*)#', $l)) $consoleTxt .= $l;
ok('plus un « Mister Szoko » affiché par la console', !str_contains($consoleTxt, 'Mister Szoko'));
ok('la console prend le monogramme, pas l\'ancien visage', str_contains($lire('mrszoko/backoffice/console.php'), 'img/logo.svg') && !str_contains($consoleTxt, 'img/logo.png'));

// ---- 2. Le logo et les dessins ------------------------------------------------------------
echo "\n-- logo i rysunki: nic z zewnątrz, nic wykonywalnego --\n";
foreach (['mrszoko/shop/assets/logo.svg', 'mrszoko/backoffice/img/logo.svg', 'mrszoko/shop/assets/hero-art.svg'] as $f) {
    $svg = $lire($f);
    ok("$f : un SVG sans script ni adresse externe", str_starts_with(ltrim($svg), '<svg') && !preg_match('/<script|on[a-z]+=|https?:\/\/(?!www\.w3\.org)|xlink:href="http/i', $svg));
}
$tous = array_merge(array_keys(WSM_METODY_WYSYLKI), array_keys(WSM_METODY_PLATNOSCI));
ok('chaque logo de la console a sa tuile', !array_filter($tous, fn($c) => znak_tile($c) === ''));
ok('un code inconnu ne dessine rien', znak_tile('paypal') === '' && znak_tile('<b>') === '');
$tuiles = implode('', array_map('znak_tile', $tous));
ok('les tuiles ne chargent rien et n\'exécutent rien', !preg_match('/<script|on[a-z]+=|https?:|<img|href/i', $tuiles));
ok('Mastercard : deux cercles et leur intersection, aux trois couleurs', str_contains($tuiles, '#EB001B') && str_contains($tuiles, '#F79E1B') && str_contains($tuiles, '#FF5F00'));
ok('les libellés passent échappés', str_contains(znaki_lista(['blik'], fn($c) => '<b>X</b>'), '&lt;b&gt;X&lt;/b&gt;'));
ok('une liste vide ne rend rien', znaki_lista([], fn($c) => $c) === '' && znaki_lista(['nic'], fn($c) => $c) === '');
ok('trois icônes de promesse, dessinées au trait', ikona_obietnicy(1) !== '' && ikona_obietnicy(2) !== '' && ikona_obietnicy(3) !== '' && ikona_obietnicy(4) === ''
   && str_contains(ikona_obietnicy(1), 'stroke="currentColor"'));

// ---- 3. Ce que la vitrine montre pour payer et recevoir -----------------------------------
echo "\n-- metody: domyślnie prawda, wybór konsoli wygrywa --\n";
$actifs = array_column($pdo->query("SELECT id FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order, id")->fetchAll(), 'id');
$attendu = array_values(array_unique(array_filter(array_map(fn($i) => WSM_METODY_Z_DOSTAWY[$i] ?? null, $actifs))));
ok('par défaut, les modes de livraison ACTIFS', wsm_metody_auto($pdo, 'wysylka') === $attendu, [wsm_metody_auto($pdo, 'wysylka'), $actifs]);
ok('… et DPD Pickup n\'y est pas tant que la caisse ne l\'offre pas', !in_array('dpd_pickup', wsm_metody_auto($pdo, 'wysylka'), true));
$tpayAvant = wsm_config()['tpay'] ?? [];
wsm_config_overlay(['tpay' => ['client_id' => '', 'client_secret' => '']]);
ok('sans tpay, le virement seulement — le seul moyen qui marche alors', wsm_metody_auto($pdo, 'platnosc') === ['przelew']);
wsm_config_overlay(['tpay' => ['client_id' => 'abc', 'client_secret' => 'def']]);
ok('avec tpay, BLIK, cartes, portefeuilles, virement rapide', wsm_metody_auto($pdo, 'platnosc') === ['blik', 'visa', 'mastercard', 'googlepay', 'applepay', 'przelew_online']);
wsm_config_overlay(['tpay' => $tpayAvant]);
$shopAvant = wsm_config()['shop'] ?? [];
wsm_config_overlay(['shop' => ['metody_platnosci' => 'visa,blik,zly']]);
ok('le choix de la console fait foi, dans l\'ordre du dictionnaire, sans code inconnu', wsm_metody($pdo, 'platnosc') === ['blik', 'visa']);
wsm_config_overlay(['shop' => ['metody_platnosci' => 'brak']]);
ok('« brak » : rien du tout', wsm_metody($pdo, 'platnosc') === []);
wsm_config_overlay(['shop' => ['metody_platnosci' => 'xxxx']]);
ok('le masque d\'un réglage vidé : le défaut', wsm_metody($pdo, 'platnosc') === wsm_metody_auto($pdo, 'platnosc'));
wsm_config_overlay(['shop' => $shopAvant]);
$avantM = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn();
wsm_settings_save($pdo, ['metody_platnosci__multi' => '1', 'metody_platnosci' => ['visa', 'blik', '<script>']], 'test');
ok('la console enregistre les cases cochées, dans l\'ordre, sans intrus', $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn() === 'blik,visa');
wsm_settings_save($pdo, ['metody_platnosci__multi' => '1'], 'test');
ok('tout décoché s\'enregistre « brak » — pas « inchangé »', $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn() === 'brak');
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'metody_platnosci'")->execute();
if ($avantM !== false) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('metody_platnosci', ?, 0, ?, 'test')")->execute([$avantM, date('Y-m-d H:i:s')]);
ok('sans le témoin, un autre formulaire ne touche pas au choix', (function () use ($pdo) {
    $avant = $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'metody_wysylki'")->fetchColumn();
    wsm_settings_save($pdo, ['shop_url' => ''], 'test');
    return $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'metody_wysylki'")->fetchColumn() === $avant;
})());

// ---- 4. Valeurs nutritives et propriétés -----------------------------------------------------
echo "\n-- wartości odżywcze i właściwości --\n";
$e = [];
$n = wsm_nutrition_clean(['salt' => '0.02', 'energy_kcal' => '598', 'fat' => ' 54 ', 'saturates' => '<0,5', 'sugars' => 'dużo', 'protein' => '', 'nieznane' => '5'], $e);
ok('des nombres, virgule polonaise, « < » permis, dans l\'ordre du règlement', $n === ['energy_kcal' => '598', 'fat' => '54', 'saturates' => '<0,5', 'salt' => '0,02'], $n);
ok('un mot est refusé, et nommé', $e === ['sugars' => 'dużo'], $e);
ok('aller-retour JSON sans perte', wsm_nutrition_decode(wsm_nutrition_encode($n)) === $n && wsm_nutrition_encode([]) === '' && wsm_nutrition_decode('pas du json') === []);
$S = ['nutri.energy' => 'Wartość energetyczna', 'nutri.fat' => 'Tłuszcz', 'nutri.saturates' => 'w tym nasycone', 'nutri.salt' => 'Sól'];
$rows = wsm_nutrition_rows(['energy_kj' => '2475', 'energy_kcal' => '598', 'fat' => '54', 'saturates' => '32,5', 'salt' => '0,02'], $S);
ok('l\'énergie sur une ligne, kJ puis kcal', $rows[0] === ['Wartość energetyczna', '2475 kJ / 598 kcal', false], $rows[0] ?? null);
ok('« dont » marqué comme sous-ligne, l\'unité ajoutée', $rows[2] === ['w tym nasycone', '32,5 g', true] && $rows[3] === ['Sól', '0,02 g', false], $rows);
$S = ["product.x.prop.1.k" => 'Opakowanie', "product.x.prop.1.v" => 'Worek', "product.x.prop.2.k" => 'Skład', "product.x.prop.2.v" => '',
      "product.x.prop.12.k" => 'Kraj', "product.x.prop.12.v" => 'Belgia', "product.x.prop.13.k" => 'Za dużo', "product.x.prop.13.v" => 'x'];
ok('une propriété n\'est montrée qu\'avec ses deux moitiés, douze au plus', wsm_product_props($S, 'x') === [['Opakowanie', 'Worek'], ['Kraj', 'Belgia']], wsm_product_props($S, 'x'));
ok('les deux schémas et db.php connaissent la colonne nutrition',
   str_contains($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.mysql.sql'), '`nutrition`') && str_contains($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.sqlite.sql'), 'nutrition       TEXT')
   && str_contains($lire('mrszoko/backoffice/php-api/db.php'), "'nutrition'    => ['TEXT', 'TEXT']"));
ok('la colonne existe dans cette base', in_array('nutrition', wsm_table_columns($pdo, 'wsm_products'), true));
$prod = $lire('mrszoko/backoffice/produkty.php');
ok('la fiche de la console porte les deux tableaux', str_contains($prod, 'name="nutri__on"') && str_contains($prod, 'name="prop[<?= $n ?>][k]"') && str_contains($prod, 'wsm_nutrition_clean('));

// ---- 5. Układ : le contact sur l'accueil, le téléphone en pied de page ----------------------
echo "\n-- układ: kontakt na stronie głównej, telefon w stopce --\n";
ok('« kontakt » est une section de l\'accueil, réglable', isset(wsm_layout_home_builtins()['kontakt']) && empty(wsm_layout_home_builtins()['kontakt']['fixed']));
ok('« telefon » est un lien du pied de page, juste après l\'e-mail', array_slice(array_keys(wsm_layout_footer_builtins()), 0, 2) === ['email', 'telefon']);
ok('cachée, la section laisse son marqueur', wsm_layout_marker('kontakt') === '<!-- kontakt: ukryty w Układzie strony -->');

// ---- 6. La page servie ---------------------------------------------------------------------
echo "\n-- strona podana (8091) --\n";
$base = 'http://localhost:8091';
$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept-Language: pl\r\n"]]);
$get = fn(string $p) => @file_get_contents($base . $p, false, $ctx);
$h = $get('/?lang=pl&t=' . microtime(true));
if ($h === false) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    $brand = $val('brand') ?: 'Market Levano';
    ok('le nom à côté du monogramme', str_contains($h, '<span class="head-nazwa">' . htmlspecialchars($brand) . '</span>') && str_contains($h, 'assets/logo.svg'));
    ok('pas une trace de l\'ancien nom, ni de son adresse', !str_contains($h, 'Mister Szoko') && !str_contains($h, 'misterszoko'));
    ok('sans photo, l\'illustration', str_contains($h, '<section class="hero hero--art"'));
    ok('les trois promesses portent leur dessin', substr_count($h, 'promise-ikona--rys') === 3);
    ok('le contact sur l\'accueil : un appel, un e-mail, le formulaire', str_contains($h, 'class="wrap block kontakt-dom"')
       && str_contains($h, 'href="tel:+48660884677"') && str_contains($h, 'href="mailto:MarketLevano@gmail.com"') && preg_match('#kontakt-poz--form" href="[^"]*/kontakt"#', $h) === 1);
    ok('le téléphone aussi en pied de page', preg_match('#<nav class="foot-links"[^>]*>.*href="tel:\+48660884677">660 884 677</a>#s', $h) === 1);
    ok('les logos en pied de page, au-dessus des liens', str_contains($h, '<div class="foot-metody">') && strpos($h, 'foot-metody') < strpos($h, 'class="foot-links"'));
    ok('le pied de page n\'est plus en police mono', !preg_match('#<footer class="site-foot">.*class="[^"]*\bmono\b#s', $h));
    // Une fiche complète, sur un produit de la base.
    $p = $pdo->query("SELECT p.id, p.slug, p.category_id, c.name AS cat FROM wsm_products p JOIN wsm_categories c ON c.id = p.category_id
                       WHERE p.shop_visible = 1 AND p.active = 1 AND p.slug <> '' ORDER BY p.sort_order LIMIT 1")->fetch();
    $avantN = $pdo->query("SELECT nutrition FROM wsm_products WHERE id = " . $pdo->quote($p['id']))->fetchColumn();
    $avantD = $val("product.{$p['id']}.desc");
    $pdo->prepare("UPDATE wsm_products SET nutrition = ? WHERE id = ?")->execute([wsm_nutrition_encode(['energy_kj' => '2475', 'energy_kcal' => '598', 'fat' => '54', 'saturates' => '32,5']), $p['id']]);
    $up = fn(string $k, string $v) => $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', ?, ?)")->execute([$k, $v]);
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND (k = ? OR k LIKE ?)")->execute(["product.{$p['id']}.desc", "product.{$p['id']}.prop.%"]);
    $up("product.{$p['id']}.desc", "Pierwsze zdanie.\nDruga linia <script>x</script>.\n\nZastosowanie:\n- polewy\n- **pralinki**");
    $up("product.{$p['id']}.prop.1.k", 'Opakowanie'); $up("product.{$p['id']}.prop.1.v", "Worek $sfx");
    $hp = (string) $get('/p/' . rawurlencode($p['slug']) . '?lang=pl&t=' . microtime(true));
    ok('la fiche : le rayon dans le fil d\'Ariane, un lien vers son onglet', preg_match('#<nav class="crumbs".*href="[^"]*\?k=' . (int) $p['category_id'] . '\#katalog">' . preg_quote(htmlspecialchars($p['cat'])) . '</a>#s', $hp) === 1);
    ok('… la référence et le rayon sous le bouton', str_contains($hp, '<dl class="buy-meta">') && str_contains($hp, '?k=' . (int) $p['category_id']));
    ok('… comment recevoir et comment payer, sous le bouton', str_contains($hp, 'class="metody metody--karta"'));
    ok('… la description en paragraphes et en liste, le HTML en texte', str_contains($hp, '<p>Pierwsze zdanie.<br>Druga linia &lt;script&gt;x&lt;/script&gt;.</p><p>Zastosowanie:</p><ul><li>polewy</li><li><strong>pralinki</strong></li></ul>'));
    ok('… les propriétés dans leur tableau', str_contains($hp, "<dt>Opakowanie</dt><dd>Worek $sfx</dd>"));
    ok('… les valeurs nutritives pour 100 g, « dont » en retrait', str_contains($hp, '<dd>2475 kJ / 598 kcal</dd>') && preg_match('#<div class="pod"><dt>[^<]+</dt><dd>32,5 g</dd>#', $hp) === 1);
    $pdo->prepare("UPDATE wsm_products SET nutrition = ? WHERE id = ?")->execute([$avantN === false ? null : $avantN, $p['id']]);
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND (k = ? OR k LIKE ?)")->execute(["product.{$p['id']}.desc", "product.{$p['id']}.prop.%"]);
    if ($avantD !== null) $up("product.{$p['id']}.desc", $avantD);
    $hk = (string) $get('/?lang=pl&k=' . (int) $p['category_id'] . '&t=' . microtime(true));
    ok('un onglet choisi donne son nom au titre du catalogue', str_contains($hk, '<h2>' . htmlspecialchars($p['cat']) . '</h2>'));
    // Un logo déposé qui porte déjà le nom : « 0 » retire le texte, l'image prend le nom en alt.
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_nazwa'")->execute();
    $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('logo_nazwa', '0', 0, ?, 'test')")->execute([date('Y-m-d H:i:s')]);
    $h0 = (string) $get('/?lang=pl&t=' . microtime(true));
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_nazwa'")->execute();
    ok('« nazwa obok logo = 0 » : le logo seul, le nom dans son alt', !str_contains($h0, 'head-nazwa') && str_contains($h0, 'alt="' . htmlspecialchars($brand) . '" height="44"'));
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
