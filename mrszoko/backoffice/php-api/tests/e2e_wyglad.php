<?php
// ============================================================================
//  e2e_wyglad.php — l'habit de la boutique réglé depuis la console (theme.php).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. AU DÉFAUT, RIEN N'EST ENVOYÉ : la feuille est vide, le design system
//      fait foi — mais la balise est là, pour qu'un contrôle la trouve.
//   2. UNE COULEUR ILLISIBLE EST REFUSÉE, et dite : marque claire, papier
//      sombre, accent pastel. Un refus ne touche à rien d'autre.
//   3. LES JETONS SE CALCULENT : une couleur donne ses nuances par color-mix,
//      une police change le corps ET les titres, les coins touchent aussi les
//      boutons. Rien d'autre que des jetons et deux règles — jamais de HTML.
//   4. LES POLICES AU CHOIX SONT VRAIMENT LÀ : @font-face déclaré ET fichiers
//      livrés. Une famille déclarée sans fichiers donne un sélecteur qui
//      « marche » et une boutique en Georgia.
//   5. PRZYWRÓĆ ramène au design system, sans reste.
//   6. LA PAGE SERVIE porte le style, respecte les interrupteurs, et le dit
//      quand un bloc est caché — vérifié sur un serveur de boutique s'il tourne.
//
//  Usage :  php tests/e2e_wyglad.php
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
require_once dirname(__DIR__) . '/theme.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end wygląd sklepu\n\n";

$racine = dirname(__DIR__, 4);   // la racine du dépôt : tests → php-api → backoffice → mrszoko → ici
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

// Ce qu'une base de développement porte déjà : remis en place à la fin.
$st = $pdo->prepare("SELECT cle, val FROM wsm_settings WHERE cle LIKE 'theme.%'");
$st->execute();
$avant = $st->fetchAll() ?: [];
wsm_theme_reset($pdo);

// ---- 1. Au défaut ------------------------------------------------------------------------
echo "-- domyślnie: nic do wysłania, ale znacznik jest --\n";
$t = wsm_theme_get($pdo, true);
ok('tout est au défaut', wsm_theme_is_default($t));
ok('la feuille est vide — le design system fait foi', wsm_theme_css($t) === '', wsm_theme_css($t));
ok('mais la balise est imprimée, pour qu\'un contrôle la trouve',
   str_contains(wsm_theme_head($pdo), '<style id="motyw">'));
ok('la police par défaut est celle de typography.css',
   $t['font_body'] === 'mulish' && str_contains($lire('mrszoko/design-system/tokens/typography.css'), "'Mulish'"));
$colors = $lire('mrszoko/design-system/tokens/colors.css');
ok('les couleurs par défaut sont CELLES de colors.css — un thème vide est la boutique d\'aujourd\'hui',
   str_contains($colors, $t['brand']) && str_contains($colors, $t['accent']) && str_contains($colors, $t['bg']),
   [$t['brand'], $t['accent'], $t['bg']]);

// ---- 2. Refus ----------------------------------------------------------------------------
echo "\n-- zapis: poprawne wchodzi, nieczytelne odpada i jest nazwane --\n";
$r = [];
$zm = wsm_theme_save($pdo, ['accent' => '#1F5F8B', 'font_body' => 'lora', 'radius' => 'ostre',
                            'hero_align' => 'srodek', 'show_pro' => '0'], 'test', $r);
ok('cinq réglages enregistrés d\'un coup', count($zm) === 5 && $r === [], [$zm, $r]);
$t = wsm_theme_get($pdo, true);
ok('relus tels quels', $t['accent'] === '#1F5F8B' && $t['font_body'] === 'lora' && $t['radius'] === 'ostre'
   && $t['hero_align'] === 'srodek' && $t['show_pro'] === '0', $t);
$rB = []; wsm_theme_save($pdo, ['brand' => '#FFFFFF'], 'test', $rB);
ok('une marque blanche est refusée — elle porte du texte clair', isset($rB['brand']), $rB);
ok('… et le refus est dit en polonais, à l\'écran', str_contains((string) ($rB['brand'] ?? ''), 'ciemny'), $rB['brand'] ?? null);
$rG = []; wsm_theme_save($pdo, ['bg' => '#000000'], 'test', $rG);
ok('un papier noir est refusé — le texte est sombre', isset($rG['bg']), $rG);
$rA = []; wsm_theme_save($pdo, ['accent' => '#FFEEDD'], 'test', $rA);
ok('un accent pastel est refusé — blanc sur pastel', isset($rA['accent']), $rA);
$rA3 = []; wsm_theme_save($pdo, ['accent' => '#E0B24A'], 'test', $rA3);
ok('… l\'or de la palette aussi : blanc sur or ne se lit pas', isset($rA3['accent']), $rA3);
$rR = []; wsm_theme_save($pdo, ['accent' => 'red'], 'test', $rR);
ok('« red » n\'est pas une couleur #RRGGBB', isset($rR['accent']), $rR);
$rF = []; wsm_theme_save($pdo, ['font_body' => 'comic'], 'test', $rF);
ok('une police inconnue est refusée', isset($rF['font_body']), $rF);
$rS = []; wsm_theme_save($pdo, ['show_pro' => 'maybe'], 'test', $rS);
ok('un interrupteur ne prend que Pokaż/Ukryj', isset($rS['show_pro']), $rS);
$rN = []; wsm_theme_save($pdo, ['nonexistent' => '1'], 'test', $rN);
ok('une clé inconnue est ignorée, pas enregistrée', $rN === [] && !$pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'theme.nonexistent'")->fetchColumn());
$t = wsm_theme_get($pdo, true);
ok('un refus ne touche à rien', $t['accent'] === '#1F5F8B' && $t['brand'] === '#41281A' && $t['bg'] === '#FBF6EF', $t);
$rOk = []; wsm_theme_save($pdo, ['accent' => '#b0402e'], 'test', $rOk);
ok('un rouge franc passe, et se range en majuscules', $rOk === [] && wsm_theme_get($pdo, true)['accent'] === '#B0402E');
wsm_theme_save($pdo, ['accent' => '#1F5F8B'], 'test');

// ---- 3. La feuille -----------------------------------------------------------------------
echo "\n-- arkusz: jetony przeliczone, nie wpisane na sztywno --\n";
$t = wsm_theme_get($pdo, true);
$css = wsm_theme_css($t);
ok(':root redéfinit l\'accent', str_contains($css, '--caramel-500:#1F5F8B'), $css);
ok('… et ses nuances se calculent dans le navigateur',
   str_contains($css, '--caramel-600:color-mix(in srgb, #1F5F8B 84%, black)')
   && str_contains($css, '--caramel-400:color-mix(in srgb, #1F5F8B 78%, white)'));
ok('la marque, inchangée, n\'est pas touchée', !str_contains($css, '--choco-700'));
ok('le papier, inchangé, non plus', !str_contains($css, '--cream-50'));
ok('la police change le corps ET les titres', str_contains($css, "--font-sans:'Lora', Georgia, serif;")
   && str_contains($css, "--font-display:'Lora', Georgia, serif;"));
ok('coins « ostre » : les boutons aussi', str_contains($css, '--radius-pill:6px') && str_contains($css, '--radius-lg:8px'));
ok('hero centré : une règle, pas un jeton', str_contains($css, '.hero-in{text-align:center}'));
ok('rien d\'autre que des jetons et des règles — jamais de balise', !str_contains($css, '<') && !str_contains($css, '>'));
wsm_theme_save($pdo, ['font_head' => 'playfair'], 'test');
$css = wsm_theme_css(wsm_theme_get($pdo, true));
ok('une famille de titres distincte, le corps reste', str_contains($css, "--font-display:'Playfair Display'")
   && str_contains($css, "--font-sans:'Lora'"));
wsm_theme_save($pdo, ['brand' => '#2A1A3E', 'bg' => '#F2F7F2', 'radius' => 'okragle'], 'test');
$css = wsm_theme_css(wsm_theme_get($pdo, true));
ok('la marque redéfinit toute la gamme de bruns, du plus sombre au plus clair',
   str_contains($css, '--choco-700:#2A1A3E') && str_contains($css, '--choco-950:color-mix(in srgb, #2A1A3E 44%, black)')
   && str_contains($css, '--choco-100:color-mix(in srgb, #2A1A3E 9%, white)'));
ok('le papier redéfinit les crèmes, teintées par la marque',
   str_contains($css, '--cream-50:#F2F7F2') && str_contains($css, '--cream-300:color-mix(in srgb, #F2F7F2 80%, var(--choco-700))'));
ok('coins « okragle » : les boutons restent en pilule', str_contains($css, '--radius-lg:26px') && !str_contains($css, '--radius-pill'));
ok('une valeur rangée en base qui ne passe plus la validation retombe au défaut',
   (function () use ($pdo) {
       $pdo->prepare("UPDATE wsm_settings SET val = '#FFFFFF' WHERE cle = 'theme.brand'")->execute();
       $t = wsm_theme_get($pdo, true);
       return $t['brand'] === '#41281A';
   })());

// ---- 4. Les polices sont vraiment là -----------------------------------------------------
echo "\n-- czcionki do wyboru naprawdę są na serwerze --\n";
$fontsCss = $lire('mrszoko/design-system/tokens/fonts.css');
$tokens   = $lire('mrszoko/shop/tokens.css');
foreach (wsm_theme_fonts() as $k => $f) {
    $slug = strtolower(str_replace(' ', '-', $f[0]));
    ok("$f[0] : @font-face déclaré dans le design system", str_contains($fontsCss, "font-family: '$f[0]'"));
    ok("… et dans tokens.css, la feuille que la boutique charge", str_contains($tokens, "font-family: '$f[0]'"));
    ok("… avec ses fichiers livrés à la boutique (latin-ext : le polonais)",
       glob("$racine/mrszoko/shop/fonts/$slug-normal-*-latin-ext.woff2") !== []);
    ok("… et à la console — les échantillons de Wygląd sont réels",
       glob("$racine/mrszoko/backoffice/_ds/mister-szoko/tokens/fonts/$slug-normal-*-latin-ext.woff2") !== []);
}
ok('aucune feuille ne réclame les polices à Google', !preg_match('#@import url\(.https://fonts#', $tokens));
ok('Plus Jakarta Sans n\'est pas revenue par la fenêtre', !str_contains($fontsCss, "Plus Jakarta Sans'"));

// ---- 5. Przywróć ---------------------------------------------------------------------------
echo "\n-- przywrócenie --\n";
$n = wsm_theme_reset($pdo);
ok('les lignes disparaissent', $n >= 5, $n);
$t = wsm_theme_get($pdo, true);
ok('retour au design system', wsm_theme_is_default($t));
ok('la feuille est vide à nouveau', wsm_theme_css($t) === '');
ok('un second reset ne casse rien', wsm_theme_reset($pdo) === 0);

// ---- 6. Vitrine et console ----------------------------------------------------------------
echo "\n-- witryna i konsola --\n";
ok('layout.php imprime le thème après shop.css',
   preg_match('/shop\.css.*theme_head\(\)/s', $lire('mrszoko/shop/layout.php')) === 1);
$lib = $lire('mrszoko/shop/lib.php');
ok('lib.php charge theme.php sans tomber s\'il manque', str_contains($lib, "is_file(\$f)) require_once \$f") && str_contains($lib, "'show_pro' => '1'"));
$vit = $lire('mrszoko/shop/index.php');
ok('index.php respecte les deux interrupteurs', str_contains($vit, "['show_promises']") && str_contains($vit, "['show_pro']"));
ok('… et DIT quand un bloc est caché', str_contains($vit, 'sekcja pro: ukryta') && str_contains($vit, 'obietnice: ukryte'));
ok('wyglad.php existe', is_file("$racine/mrszoko/backoffice/wyglad.php"));
ok('… dans le rail, à côté de Treści', preg_match("/'tresci\.php'.*'wyglad\.php'/s", $lire('mrszoko/backoffice/console.php')) === 1);
$wyg = $lire('mrszoko/backoffice/wyglad.php');
ok('… poste avec le jeton CSRF', str_contains($wyg, 'console_csrf_ok()') && str_contains($wyg, 'console_csrf_field()'));
ok('… n\'écrit que pour Centrala', str_contains($wyg, 'if (!$isAdmin)'));
ok('… et « Przywróć » demande une confirmation', str_contains($wyg, "na_pewno"));
ok('… l\'aperçu est la vraie page d\'accueil', str_contains($wyg, 'src="../shop/?w='));

// La page SERVIE, quand un serveur de boutique tourne (php -S localhost:8091).
$base = rtrim(getenv('WSM_SHOP_BASE') ?: 'http://localhost:8091', '/');
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$home = @file_get_contents($base . '/', false, $ctx);
if ($home === false || $home === '') {
    echo "  (pominięte: brak serwera sklepu pod $base)\n";
} else {
    echo "\n-- strona główna nosi styl --\n";
    ok('au défaut, la balise est là et vide', str_contains($home, '<style id="motyw">/* wygląd domyślny */</style>'));
    ok('… et le bloc B2B est sur la page', str_contains($home, 'id="pro"'));
    wsm_theme_save($pdo, ['accent' => '#1F5F8B', 'show_pro' => '0', 'show_promises' => '0'], 'test');
    $h1 = (string) @file_get_contents($base . '/?t=' . time(), false, $ctx);
    ok('la page servie porte l\'accent', str_contains($h1, '--caramel-500:#1F5F8B'));
    ok('le bloc B2B est caché — et la page le dit', !str_contains($h1, 'id="pro"') && str_contains($h1, 'sekcja pro: ukryta'));
    ok('les promesses aussi', !str_contains($h1, 'class="promise"') && str_contains($h1, 'obietnice: ukryte'));
    ok('le catalogue, lui, est toujours là', str_contains($h1, 'id="katalog"'));
    wsm_theme_reset($pdo);
    $h0 = (string) @file_get_contents($base . '/?t=' . (time() + 1), false, $ctx);
    ok('après Przywróć, tout revient', str_contains($h0, 'id="pro"') && str_contains($h0, 'class="promise"')
       && str_contains($h0, '/* wygląd domyślny */'));
}

// ---- Remise en place ----------------------------------------------------------------------
wsm_theme_reset($pdo);
$ins = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)");
foreach ($avant as $row) $ins->execute([$row['cle'], $row['val'], date('Y-m-d H:i:s'), 'test']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
