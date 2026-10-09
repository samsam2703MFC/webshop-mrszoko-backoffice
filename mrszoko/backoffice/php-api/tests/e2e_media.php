<?php
// ============================================================================
//  e2e_media.php — la médiathèque : ce qui est déposé, et à quoi ça sert.
//
//   1. LA LISTE NE MONTRE QUE NOS FICHIERS : un nom qui n'a pas la forme que
//      nous produisons n'y entre pas, et ne se supprime pas d'ici.
//   2. LES USAGES SE LISENT DANS LA BASE, à l'affichage : produit, marque,
//      réglage, page (image ET image citée dans le corps), client.
//   3. UN FICHIER UTILISÉ NE SE SUPPRIME PAS depuis l'écran ; un orphelin, oui.
//
//  Usage :  php tests/e2e_media.php
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
require_once dirname(__DIR__) . '/media.php';
require_once dirname(__DIR__) . '/pages.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end medioteka\n\n";

$dir = wsm_media_dir();
if (!is_dir($dir)) mkdir($dir, 0775, true);
$sfx = bin2hex(random_bytes(3));

// Deux fichiers « à nous » (le nom que wsm_media_store produit) et un intrus.
$im = imagecreatetruecolor(8, 8);
$nomA = bin2hex(random_bytes(12)) . '.webp';
$nomB = bin2hex(random_bytes(12)) . '.png';
imagewebp($im, "$dir/$nomA"); imagepng($im, "$dir/$nomB");
file_put_contents("$dir/intruz-$sfx.txt", 'nie obraz');
imagedestroy($im);
$urlA = 'media/' . $nomA; $urlB = 'media/' . $nomB;

echo "-- lista: tylko nasze pliki --\n";
$liste = wsm_media_list();
$noms = array_column($liste, 'name');
ok('les deux fichiers sont listés', in_array($nomA, $noms, true) && in_array($nomB, $noms, true));
ok('l\'intrus n\'est pas listé', !in_array("intruz-$sfx.txt", $noms, true));
$a = array_values(array_filter($liste, fn($m) => $m['name'] === $nomA))[0] ?? null;
ok('avec sa taille et ses dimensions', ($a['w'] ?? 0) === 8 && ($a['h'] ?? 0) === 8 && ($a['bytes'] ?? 0) > 0, $a);
ok('et son adresse telle que la base la range', ($a['url'] ?? '') === $urlA);

echo "\n-- użycia: czytane z bazy --\n";
$e = [];
$pid = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "media-test-$sfx", 'image_url' => $urlA,
    't' => ['pl' => ['title' => "Test mediów $sfx", 'body' => "Obraz w treści: ![x]($urlB)"]]], 'test', $e);
ok('une page avec image et image citée', $pid !== null, $e);
$avantIk = (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = 'promise_icon_3'")->fetchColumn() ?: '');
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_3'")->execute();
$pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(["promise_icon_3", $urlB, date('Y-m-d H:i:s'), 'test']);
$u = wsm_media_usages($pdo);
ok('l\'image de la page est vue comme utilisée par la page', in_array("strona: Test mediów $sfx", $u[$urlA] ?? [], true), $u[$urlA] ?? null);
ok('l\'image citée dans le corps aussi', in_array("w treści: Test mediów $sfx", $u[$urlB] ?? [], true), $u[$urlB] ?? null);
ok('… et par le réglage qui la porte', in_array('ustawienie: ikona obietnicy 3', $u[$urlB] ?? [], true), $u[$urlB] ?? null);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_3'")->execute();
if ($avantIk !== '') $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(["promise_icon_3", $avantIk, date('Y-m-d H:i:s'), 'test']);

echo "\n-- usuwanie: tylko sierota, tylko nasz plik --\n";
ok('un nom hors de notre forme ne se supprime pas d\'ici', !wsm_media_delete("media/intruz-$sfx.txt") && is_file("$dir/intruz-$sfx.txt"));
ok('un chemin tordu non plus', !wsm_media_delete('media/../../api/config.php'));
$scr = (string) @file_get_contents(dirname(__DIR__, 2) . '/media.php');
ok('l\'écran refuse de supprimer un fichier utilisé', str_contains($scr, 'isset(wsm_media_usages($pdo)[$url])'));
ok('… et demande une confirmation', str_contains($scr, 'na_pewno'));
ok('… et ne montre le bouton que sur un orphelin', str_contains($scr, '<?php else: ?>') && str_contains($scr, 'Usuń plik'));
wsm_page_delete($pdo, (int) $pid);
$u2 = wsm_media_usages($pdo);
ok('la page supprimée, les deux fichiers redeviennent orphelins', !isset($u2[$urlA]) && !isset($u2[$urlB]));
ok('un orphelin se supprime', wsm_media_delete($urlA) && !is_file("$dir/$nomA"));
ok('… et disparaît de la liste', !in_array($nomA, array_column(wsm_media_list(), 'name'), true));

// ---- Nettoyage ------------------------------------------------------------------------
@unlink("$dir/$nomB"); @unlink("$dir/intruz-$sfx.txt");

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
