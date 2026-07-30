<?php
// ============================================================================
//  e2e_cms.php — preuve que le CMS pilote vraiment les pages publiques.
//
//  Un écran d'édition qui écrit dans une table que personne ne lit serait pire
//  qu'inutile : on croirait avoir corrigé la boutique. Les assertions vont
//  donc jusqu'au bout de la chaîne — on écrit par le CMS, puis on RELIT par la
//  fonction qu'utilise réellement la vitrine.
//
//  Ce qu'on démontre :
//
//   1. LE TEXTE ÉDITÉ ARRIVE SUR LA PAGE. wsm_shop_strings() rend la nouvelle
//      valeur, sans cache à vider.
//   2. UNE TRADUCTION VIDE NE FAIT PAS DE TROU. Elle retombe sur le polonais —
//      c'est ce qui rend « effacer » une opération sûre.
//   3. ON N'ÉCRIT QUE CE QUI CHANGE. Renvoyer une section entière inchangée ne
//      produit aucune écriture, donc aucune ligne d'audit mensongère.
//   4. UN POST NE CRÉE PAS DE CLÉ. Ni de langue. Le contenu public n'est pas
//      un endroit où l'on invente des champs depuis un navigateur.
//   5. ON PEUT REVENIR EN ARRIÈRE. Le texte livré par le dépôt reste la
//      référence, clé par clé.
//
//  Usage :  php tests/e2e_cms.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/cms.php';
$pdo = wsm_bootstrap();
wsm_ensure_landing($pdo);

echo "webshop_mrszoko — end-to-end CMS (treści dwóch stron publicznych)\n\n";

// ---- 1. Les deux sites sont réels --------------------------------------------
echo "-- strony --\n";
$sites = wsm_cms_sites();
ok('deux sites pilotés', count($sites) === 2, array_keys($sites));
foreach ($sites as $key => $s) {
    ok("« $key » : le fichier source existe", is_file($s['seed']), $s['seed']);
    ok("« $key » : la table existe", wsm_table_exists($pdo, $s['table']), $s['table']);
    ok("« $key » : la table n'est pas vide",
        (int) $pdo->query("SELECT COUNT(*) FROM {$s['table']}")->fetchColumn() > 0);
}
ok('un site inconnu ne renvoie rien', wsm_cms_site('nie-ma') === null);

// ---- 2. Chargement -----------------------------------------------------------
echo "\n-- wczytanie --\n";
$T = $sites['sklep']['table'];
$langs = wsm_cms_langs($pdo, $T);
ok('les trois langues sont là', count(array_intersect(['pl', 'uk', 'en'], $langs)) === 3, $langs);
ok('le polonais vient en premier — c\'est la langue de repli', $langs[0] === 'pl', $langs);

$content = wsm_cms_load($pdo, $T, $langs);
ok('le contenu de la boutique est chargé', count($content) > 100, count($content));
$sample = $content['cart.title'] ?? null;
ok('chaque clé porte toutes les langues, même vides',
    is_array($sample) && count($sample) === count($langs), $sample);
ok('le polonais d\'une clé connue est rempli', trim((string) ($sample['pl'] ?? '')) !== '', $sample);

// ---- 3. Le classement par section --------------------------------------------
echo "\n-- sekcje --\n";
ok('le préfixe d\'une clé est sa section', wsm_cms_prefix('checkout.city') === 'checkout');
ok('une clé sans point est sa propre section', wsm_cms_prefix('brand') === 'brand');
$groups = wsm_cms_groups($content);
ok('les sections sont formées', count($groups) > 10, count($groups));
$order = array_keys($groups);
$iNav = array_search('nav', $order, true);
$iFoot = array_search('footer', $order, true);
ok('l\'ordre suit le parcours du visiteur, pas l\'alphabet', $iNav !== false && $iFoot !== false && $iNav < $iFoot,
    [$iNav, $iFoot]);
ok('chaque section porte un nom lisible',
    wsm_cms_section_label('checkout') === 'Zamawianie', wsm_cms_section_label('checkout'));
ok('une section inconnue s\'affiche quand même, sous sa clé',
    wsm_cms_section_label('cos-nowego') === 'cos-nowego');

// Toutes les sections réellement présentes devraient avoir un libellé : sinon
// l'écran afficherait « ship » à un utilisateur.
$sansLabel = array_values(array_filter(array_keys($groups), fn($p) => !isset(WSM_CMS_SECTIONS[$p])));
ok('aucune section de la boutique sans libellé polonais', $sansLabel === [], $sansLabel);

$lgroups = wsm_cms_groups(wsm_cms_load($pdo, $sites['strona']['table'], $langs));
$sansLabel2 = array_values(array_filter(array_keys($lgroups), fn($p) => !isset(WSM_CMS_SECTIONS[$p])));
ok('aucune section de la page d\'accueil sans libellé', $sansLabel2 === [], $sansLabel2);

// ---- 4. Écriture : jusqu'à la page --------------------------------------------
echo "\n-- zapis trafia na stronę --\n";
$key = 'cart.title';
$avant = $content[$key];
$neuf = 'Koszyk testowy ' . bin2hex(random_bytes(2));

[$n, $keys] = wsm_cms_save($pdo, $T, [$key => ['pl' => $neuf]], $langs);
ok('une valeur changée → une écriture', $n === 1, $n);
ok('la clé touchée est rapportée', $keys === [$key], $keys);

$vitrine = wsm_shop_strings($pdo, 'pl');
ok('la boutique sert le nouveau texte immédiatement', ($vitrine[$key] ?? '') === $neuf, $vitrine[$key] ?? null);

// Renvoyer la même chose ne doit rien écrire : sinon l'audit mentirait.
[$n0] = wsm_cms_save($pdo, $T, [$key => ['pl' => $neuf]], $langs);
ok('renvoyer une valeur inchangée n\'écrit rien', $n0 === 0, $n0);

// Les fins de ligne Windows et les espaces de bord sont normalisés.
[$n1] = wsm_cms_save($pdo, $T, [$key => ['pl' => "  $neuf  "]], $langs);
ok('les espaces de bord ne comptent pas comme un changement', $n1 === 0, $n1);

// ---- 5. Une traduction vide retombe sur le polonais ---------------------------
echo "\n-- pusty przekład wraca do polskiego --\n";
wsm_cms_save($pdo, $T, [$key => ['en' => '']], $langs);
$en = wsm_shop_strings($pdo, 'en');
ok('l\'anglais vide affiche le polonais, pas un trou', ($en[$key] ?? '') === $neuf, $en[$key] ?? null);

$enNeuf = 'Test cart ' . bin2hex(random_bytes(2));
wsm_cms_save($pdo, $T, [$key => ['en' => $enNeuf]], $langs);
$en2 = wsm_shop_strings($pdo, 'en');
ok('une fois traduit, l\'anglais reprend ses droits', ($en2[$key] ?? '') === $enNeuf, $en2[$key] ?? null);
ok('et le polonais n\'a pas bougé', (wsm_shop_strings($pdo, 'pl')[$key] ?? '') === $neuf);

// ---- 6. Ce qu'un POST ne peut pas faire ----------------------------------------
echo "\n-- czego POST nie może --\n";
$avantN = (int) $pdo->query("SELECT COUNT(*) FROM $T")->fetchColumn();
[$nk] = wsm_cms_save($pdo, $T, ['klucz.wymyslony' => ['pl' => 'nie']], $langs);
ok('un POST ne crée pas une clé', $nk === 0, $nk);
[$nl] = wsm_cms_save($pdo, $T, [$key => ['de' => 'Warenkorb']], $langs);
ok('un POST ne crée pas une langue', $nl === 0, $nl);
ok('le nombre de lignes n\'a pas bougé',
    (int) $pdo->query("SELECT COUNT(*) FROM $T")->fetchColumn() === $avantN);

$long = str_repeat('a', 5000);
wsm_cms_save($pdo, $T, [$key => ['pl' => $long]], $langs);
$coupe = wsm_cms_load($pdo, $T, $langs)[$key]['pl'];
ok('un texte démesuré est borné', mb_strlen($coupe) === 4000, mb_strlen($coupe));

// ---- 7. Retour au texte d'origine ------------------------------------------------
echo "\n-- przywrócenie tekstu pierwotnego --\n";
$src = wsm_cms_source($sites['sklep']['seed']);
ok('le fichier source est lisible', count($src) > 100, count($src));
ok('il contient la clé testée', isset($src[$key]['pl']));

$r = wsm_cms_revert($pdo, $T, $sites['sklep']['seed'], $key, $langs);
ok('le retour en arrière écrit', $r >= 1, $r);
$apres = wsm_cms_load($pdo, $T, $langs)[$key];
ok('le polonais est celui du dépôt', $apres['pl'] === $src[$key]['pl'], [$apres['pl'], $src[$key]['pl']]);
ok('l\'anglais aussi', $apres['en'] === ($src[$key]['en'] ?? $apres['en']));
ok('et la boutique le sert', (wsm_shop_strings($pdo, 'pl')[$key] ?? '') === $src[$key]['pl']);

ok('une clé sans texte d\'origine ne prétend pas revenir en arrière',
    wsm_cms_revert($pdo, $T, $sites['sklep']['seed'], 'klucz.bez.zrodla', $langs) === 0);

// ---- 8. Le compte des traductions manquantes -------------------------------------
echo "\n-- braki tłumaczeń --\n";
$m = wsm_cms_missing($content, $langs);
ok('le compte existe pour chaque langue', count($m) === count($langs), $m);
ok('le polonais ne se manque jamais à lui-même', $m['pl'] === 0, $m['pl']);
$faux = ['a.b' => ['pl' => 'tekst', 'en' => '', 'uk' => 'текст'],
         'c.d' => ['pl' => '',      'en' => '', 'uk' => '']];
$mm = wsm_cms_missing($faux, ['pl', 'en', 'uk']);
ok('une clé traduite en partie compte une fois', $mm['en'] === 1, $mm);
ok('une clé vide même en polonais n\'est pas comptée comme non traduite', $mm['uk'] === 0, $mm);

// ---- 9. Recherche et forme des champs ---------------------------------------------
echo "\n-- szukanie i forma pól --\n";
ok('une recherche vide laisse tout passer', wsm_cms_match('a.b', ['pl' => 'x'], ''));
ok('on cherche dans la clé', wsm_cms_match('checkout.city', ['pl' => 'Miasto'], 'CHECKOUT'));
ok('et dans le texte', wsm_cms_match('checkout.city', ['pl' => 'Miasto'], 'miast'));
ok('ce qui ne correspond pas est écarté', !wsm_cms_match('checkout.city', ['pl' => 'Miasto'], 'paczkomat'));

ok('un libellé court se saisit sur une ligne', !wsm_cms_multiline(['pl' => 'Koszyk', 'en' => 'Cart']));
ok('un paragraphe demande une zone de texte', wsm_cms_multiline(['pl' => str_repeat('mot ', 40)]));
ok('une seule langue longue suffit à élargir le champ',
    wsm_cms_multiline(['pl' => 'Krótko', 'uk' => str_repeat('слово ', 30)]));
ok('un texte à plusieurs lignes aussi', wsm_cms_multiline(['pl' => "linia\nlinia"]));

// ---- 10. Le prix du port, saisi comme les gens l'écrivent ----------------------------
//  Ces valeurs s'affichent sur la boutique (« Darmowa dostawa od… ») et
//  n'étaient jusqu'ici modifiables qu'en redéployant.
echo "\n-- cennik dostawy --\n";
ok('« 13,50 » → 1350 grosze (virgule polonaise)', wsm_cms_grosze('13,50') === 1350, wsm_cms_grosze('13,50'));
ok('le point décimal passe aussi', wsm_cms_grosze('13.50') === 1350);
ok('les espaces d\'un copier-coller sont ignorés', wsm_cms_grosze(' 1 250,00 ') === 125000,
    wsm_cms_grosze(' 1 250,00 '));
ok('un champ vide vaut zéro', wsm_cms_grosze('') === 0);
ok('du texte ne devient pas un prix', wsm_cms_grosze('za darmo') === 0);
ok('un port négatif est impossible', wsm_cms_grosze('-10') === 0, wsm_cms_grosze('-10'));
ok('les fractions de grosz sont arrondies', wsm_cms_grosze('0,005') === 1, wsm_cms_grosze('0,005'));

$sm = $pdo->query("SELECT * FROM wsm_shipping_methods ORDER BY sort_order")->fetchAll() ?: [];
ok('les méthodes de livraison existent', count($sm) > 0, count($sm));
ok('chacune porte un prix en grosze, pas en złotys',
    !array_filter($sm, fn($m) => !is_numeric($m['price_net'])));

// ---- 11. La page d'accueil suit les mêmes règles ------------------------------------
echo "\n-- strona główna --\n";
$L = $sites['strona']['table'];
$lc = wsm_cms_load($pdo, $L, $langs);
ok('le contenu de la page d\'accueil est chargé', count($lc) > 40, count($lc));
$lk = 'hero.title';
if (isset($lc[$lk])) {
    $avantL = $lc[$lk]['pl'];
    [$ln] = wsm_cms_save($pdo, $L, [$lk => ['pl' => 'Test ' . $avantL]], $langs);
    ok('on écrit aussi dans la page d\'accueil', $ln === 1, $ln);
    wsm_cms_save($pdo, $L, [$lk => ['pl' => $avantL]], $langs);
    ok('et on remet en place', wsm_cms_load($pdo, $L, $langs)[$lk]['pl'] === $avantL);
} else {
    ok('la clé du nagłówek existe', false, array_slice(array_keys($lc), 0, 8));
}
$cards = (int) $pdo->query("SELECT COUNT(*) FROM wsm_landing_products")->fetchColumn();
ok('les cartes de la gamme sont éditables', $cards > 0, $cards);

// ---- Remise en état ------------------------------------------------------------------
foreach ($langs as $l) {
    if (isset($avant[$l])) {
        $st = $pdo->prepare("UPDATE $T SET v = ? WHERE lang = ? AND k = ?");
        $st->execute([$avant[$l], $l, $key]);
    }
}
ok('le texte d\'origine de la boutique est rétabli',
    (wsm_shop_strings($pdo, 'pl')[$key] ?? '') === $avant['pl'], $avant['pl']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
