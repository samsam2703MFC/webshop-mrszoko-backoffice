<?php
// ============================================================================
//  e2e_kategorie.php — les rayons de la boutique, et le ménage.
//
//  DEUX CHOSES QUE CE TEST GARDE.
//
//  1. LA VITRINE LIT LES CATÉGORIES EN BASE. Elle les ignorait complètement :
//     pas de filtre, pas de menu, tout dans un catalogue à plat, alors que le
//     champ est obligatoire sur chaque produit depuis toujours. On rangeait
//     donc les articles dans des tiroirs que personne n'ouvrait.
//
//     Une catégorie VIDE ne s'affiche pas : un rayon qui mène à « aucun
//     produit » est une impasse, et il s'en crée un dès qu'on retire le
//     dernier article d'une gamme.
//
//  2. LE MÉNAGE NE DÉTRUIT RIEN. Cinq catégories venaient d'une maquette de
//     boulangerie — Pieczywo, Lody, Katering — pour une chocolaterie. On les
//     DÉSACTIVE : category_id est NOT NULL, donc une catégorie effacée
//     laisserait ses produits avec une référence morte. Et une catégorie qui
//     tient encore un produit VISIBLE n'est pas touchée : si quelqu'un s'en
//     sert pour de vrai, ce n'est plus une maquette.
//
//  Usage :  php tests/e2e_kategorie.php
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
require_once dirname(__DIR__) . '/seed.php';

echo "webshop_mrszoko — end-to-end kategorie\n\n";

// Base isolée : le ménage touche des lignes réelles, on ne le joue pas sur la
// base de travail.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE wsm_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE,
           sort_order INT DEFAULT 0, active INT DEFAULT 1)");
$db->exec("CREATE TABLE wsm_products (id TEXT PRIMARY KEY, category_id INT, nom TEXT,
           shop_visible INT DEFAULT 0, active INT DEFAULT 1)");
$cat = function (string $n) use ($db): int {
    $db->prepare("INSERT INTO wsm_categories (name) VALUES (?)")->execute([$n]);
    return (int) $db->lastInsertId();
};
$prod = function (string $id, int $c, int $vis) use ($db) {
    $db->prepare("INSERT INTO wsm_products (id, category_id, nom, shop_visible, active) VALUES (?,?,?,?,1)")
       ->execute([$id, $c, $id, $vis]);
};

$czek = $cat('Czekolada');
$piec = $cat('Pieczywo');          // maquette, produits masqués
$lody = $cat('Lody');              // maquette, mais un produit EN VENTE
$vide = $cat('Pralinki');          // active, aucun produit
$prod('s-dark70', $czek, 1);
$prod('s-ruby',   $czek, 1);
$prod('p-chleb',  $piec, 0);       // dort déjà
$prod('l-sorbet', $lody, 1);       // vendu pour de vrai
$prod('test-dpd-abc', $czek, 1);   // artefact de mise au point

// ---- 1. Le ménage, joué sur cette base --------------------------------------
echo "-- sprzatanie nie niszczy --\n";
$n = 0;
foreach (wsm_content_menage() as [$sql, $args]) {
    $st = $db->prepare($sql); $st->execute($args); $n += $st->rowCount();
}
ok('il a touché quelque chose', $n > 0, $n);

$act = fn(string $nom) => (int) $db->query("SELECT active FROM wsm_categories WHERE name = '$nom'")->fetchColumn();
ok('« Pieczywo » désactivée — plus rien de visible dedans', $act('Pieczywo') === 0);
ok('« Lody » INTACTE — elle tient encore un produit en vente', $act('Lody') === 1);
ok('« Czekolada » intacte', $act('Czekolada') === 1);
// Une catégorie vide qui n'est PAS dans la liste nommée reste active : le
// ménage ne devine pas, il retire ce qu'on a désigné.
ok('« Pralinki », vide mais non nommée, reste active', $act('Pralinki') === 1);

$vis = (int) $db->query("SELECT shop_visible FROM wsm_products WHERE id = 'test-dpd-abc'")->fetchColumn();
ok('le produit de test DPD est masqué', $vis === 0);
ok('… mais toujours en base — les documents qui le nomment restent lisibles',
   (int) $db->query("SELECT COUNT(*) FROM wsm_products WHERE id = 'test-dpd-abc'")->fetchColumn() === 1);
ok('aucune catégorie supprimée', (int) $db->query("SELECT COUNT(*) FROM wsm_categories")->fetchColumn() === 4);
ok('aucun produit supprimé', (int) $db->query("SELECT COUNT(*) FROM wsm_products")->fetchColumn() === 5);

// Idempotence : rejoué, il ne trouve plus rien.
$n2 = 0;
foreach (wsm_content_menage() as [$sql, $args]) {
    $st = $db->prepare($sql); $st->execute($args); $n2 += $st->rowCount();
}
ok('rejoué, il ne change plus rien', $n2 === 0, $n2);

// ---- 2. Les rayons que la boutique montre -----------------------------------
echo "\n-- witryna czyta kategorie z bazy --\n";
$rayons = wsm_shop_categories($db);
$noms = array_column($rayons, 'name');
ok('« Czekolada » est un rayon', in_array('Czekolada', $noms, true), $noms);
ok('« Lody » aussi — son produit est en vente', in_array('Lody', $noms, true), $noms);
// LE POINT : une catégorie sans produit visible n'est PAS un rayon. Un lien
// vers un rayon vide est une impasse, et le client la découvre après le clic.
ok('« Pieczywo » n\'est PAS un rayon — rien de visible dedans', !in_array('Pieczywo', $noms, true), $noms);
ok('« Pralinki » non plus — aucune ligne dedans', !in_array('Pralinki', $noms, true), $noms);

$ile = array_column($rayons, 'ile', 'name');
// Le produit de test vient d'être masqué : il ne doit plus être compté.
ok('le compte de « Czekolada » ignore le produit masqué', (int) ($ile['Czekolada'] ?? 0) === 2, $ile);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
