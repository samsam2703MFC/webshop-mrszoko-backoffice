<?php
// ============================================================================
//  produkty.php — écran « Produits et photos » de la console marque.
//
//  C'est ici qu'on envoie une photo de produit. Le fichier est décodé et
//  ré-encodé côté serveur (media.php), donc ce qui atterrit dans la boutique
//  est une image fabriquée par nous, redimensionnée et sans métadonnées.
//
//  Même logique que zamowienia.php : page PHP autonome, même session et mêmes
//  rôles que la console. Lecture pour tout compte actif, écriture pour Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/cms.php';   // les textes du produit vivent dans wsm_shop_i18n
require_once $API . '/media.php';
require_once $API . '/stock.php';
require_once $API . '/brand.php';

/** La table produits stocke des złotys, pas des grosze : conversion locale. */
function zl($v): string { return number_format((float) $v, 2, ',', "\u{202F}") . "\u{202F}zł"; }

// Le chemin enregistré en base est relatif à la boutique (« media/… ») :
// depuis /backoffice/ il faut remonter d'un cran pour l'afficher.
function img_src(string $url): string {
    return str_starts_with($url, 'media/') ? '../shop/' . $url : $url;
}

// Jeton anti-CSRF, émis avant toute sortie (un en-tête ne s'ajoute plus après).
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $fieldErrors = []; $openId = (string) ($_GET['id'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) {
        http_response_code(400); exit('Bad request.');
    }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać produkty.'; $kind = 'err';
    } else {
        $id = (string) ($_POST['id'] ?? '');
        $openId = $id;

        // ---- CRÉER UN PRODUIT ----------------------------------------------
        //
        // CE CHEMIN N'EXISTAIT PAS. L'écran s'appelle « Produkty », on y
        // modifie et on y supprime — donc on suppose qu'on peut y ajouter. Les
        // 22 produits de la base venaient tous du fichier de semis ou
        // d'anciennes maquettes, et rien ne le disait.
        //
        // ON CRÉE PEU, PUIS ON COMPLÈTE. Trois champs suffisent (nom,
        // catégorie, prix) : le reste a des défauts, et la fiche qui s'ouvre
        // juste après porte déjà tout. Un formulaire de création qui
        // redemanderait les quinze champs de la fiche serait un deuxième
        // endroit où les tenir à jour.
        //
        // ET IL NAÎT INVISIBLE. shop_visible = 0 : un produit sans photo, sans
        // description et sans poids n'a rien à faire en vente. On le rend
        // visible quand il est prêt, d'un clic sur sa fiche.
        if (isset($_POST['nowy'])) {
            $nazwa = trim((string) ($_POST['n_nazwa'] ?? ''));
            $kat   = (int) ($_POST['n_kategoria'] ?? 0);
            $cena  = wsm_parse_price((string) ($_POST['n_cena'] ?? ''));
            if ($nazwa === '') {
                $fieldErrors['n_nazwa'] = 'wymagana';
            }
            if ($kat <= 0) {
                $fieldErrors['n_kategoria'] = 'wybierz kategorię';
            } else {
                $st = $pdo->prepare("SELECT 1 FROM wsm_categories WHERE id = ?");
                $st->execute([$kat]);
                if (!$st->fetchColumn()) $fieldErrors['n_kategoria'] = 'nie ma takiej kategorii';
            }
            if (($_POST['n_cena'] ?? '') !== '' && $cena === null) {
                $fieldErrors['n_cena'] = 'nie rozumiem tej ceny';
            }

            if (!$fieldErrors) {
                // L'identifiant est une CLÉ, pas une adresse : commandes,
                // stock et factures le portent pour toujours. Il se dérive du
                // nom comme le slug, et se rend unique de la même façon.
                $base = wsm_slugify($nazwa, 'produkt');
                $newId = wsm_slug_libre($pdo, $base, 'id');
                $slug  = wsm_slug_libre($pdo, $base, 'slug', $newId);
                try {
                    $pdo->prepare("INSERT INTO wsm_products
                            (id, category_id, nom, slug, prix, statut, active, shop_visible, sort_order)
                            VALUES (?,?,?,?,?,?,1,0,?)")
                        ->execute([$newId, $kat, $nazwa, $slug, $cena ?? 0, 'Szkic',
                                   (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM wsm_products")->fetchColumn()]);

                    // Les trois textes, créés VIDES sauf le nom. Sans ces
                    // lignes, wsm_cms_save() refuserait plus tard de les
                    // écrire — « pas de clé inventée par un POST » — et la
                    // description saisie sur la fiche partirait en silence.
                    $insT = $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)");
                    foreach (['name' => $nazwa, 'subtitle' => '', 'desc' => ''] as $suf => $val) {
                        try { $insT->execute([WSM_CMS_BASE_LANG, 'product.' . $newId . '.' . $suf, $val]); }
                        catch (Throwable $e) { /* déjà là : rien à faire */ }
                    }

                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Dodanie produktu',
                              'wsm_products ' . $newId, 'Sieć');
                    $openId = $newId;
                    $flash = 'Utworzono ' . $newId . '. Uzupełnij zdjęcie, opis i gramaturę, '
                           . 'potem włącz widoczność w sklepie.';
                } catch (Throwable $e) {
                    $flash = 'Nie udało się utworzyć produktu.'; $kind = 'err';
                }
            } else {
                $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
            }
        }

        // ---- Marques -------------------------------------------------------
        elseif (isset($_POST['marka_zapisz'])) {
            $bid = (int) $_POST['marka_zapisz'] ?: null;
            [$b, $errs] = wsm_brand_save($pdo, $_POST, $_FILES['logo'] ?? [], $bid);
            if ($errs) {
                $fieldErrors = $errs;
                $flash = 'Popraw dane marki: ' . implode(' · ', $errs); $kind = 'err';
            } else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), $bid ? 'Zmiana' : 'Dodanie',
                          'wsm_brands ' . $b['name'], 'Sieć');
                $flash = 'Zapisano markę ' . $b['name'] . '.';
            }
            $openId = '';
        } elseif (isset($_POST['marka_usun'])) {
            [$ok, $msg] = wsm_brand_delete($pdo, (int) $_POST['marka_usun']);
            if ($ok) wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie', 'wsm_brands #' . (int) $_POST['marka_usun'], 'Sieć');
            $flash = $msg; $kind = $ok ? 'ok' : 'err';
            $openId = '';
        } else

        // ---- Désactiver / réactiver / supprimer ----------------------------
        //
        // DEUXIÈME CHAÎNE, et elle a besoin d'une garde. Son `else` final
        // traite la fiche d'un produit ; une création ou une marque tombait
        // dedans avec un identifiant vide, écrasait le message de réussite par
        // « Nie znaleziono produktu » et faisait passer l'écran en rouge alors
        // que tout venait de se passer correctement.
        //
        // Désactiver retire le produit du catalogue et du magasin sans toucher
        // à l'histoire : les commandes passées, les factures et les mouvements
        // continuent de le nommer. C'est presque toujours ce qu'on veut.
        if (isset($_POST['nowy']) || isset($_POST['marka_zapisz']) || isset($_POST['marka_usun'])) {
            // déjà traité au-dessus
        }
        elseif (isset($_POST['aktywacja'])) {
            $on = $_POST['aktywacja'] === '1' ? 1 : 0;
            $pdo->prepare("UPDATE wsm_products SET active = ?, shop_visible = CASE WHEN ? = 0 THEN 0 ELSE shop_visible END WHERE id = ?")
                ->execute([$on, $on, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), $on ? 'Włączenie produktu' : 'Wyłączenie produktu', 'wsm_products ' . $id, 'Sieć');
            $flash = $on ? 'Produkt włączony: ' . $id : 'Produkt wyłączony — zniknął ze sklepu i z magazynu.';
            $kind = 'ok';
        }
        // Supprimer n'est possible QUE si rien ne s'y réfère. Un produit cité
        // par une commande ou par une facture ne peut pas disparaître : le
        // document deviendrait illisible, et une facture doit se relire à
        // l'identique dans dix ans. Dans ce cas on propose la désactivation.
        elseif (isset($_POST['usun'])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_order_items WHERE product_id = ?");
            $st->execute([$id]);
            $used = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_stock_moves WHERE product_id = ?");
            $st->execute([$id]);
            $moved = (int) $st->fetchColumn();
            if ($used > 0) {
                $flash = 'Nie można usunąć: produkt występuje w ' . $used . ' pozycjach zamówień. '
                       . 'Dokumenty muszą pozostać czytelne — wyłącz go zamiast usuwać.';
                $kind = 'err';
            } elseif ($moved > 0) {
                $flash = 'Nie można usunąć: produkt ma ' . $moved . ' ruchów magazynowych. Wyłącz go zamiast usuwać.';
                $kind = 'err';
            } else {
                $st = $pdo->prepare("SELECT image_url FROM wsm_products WHERE id = ?");
                $st->execute([$id]);
                $img = (string) $st->fetchColumn();
                $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
                if ($img !== '') wsm_media_delete($img);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie produktu', 'wsm_products ' . $id, 'Sieć');
                $flash = 'Usunięto produkt ' . $id . '.';
                $kind = 'ok';
                $openId = '';
            }
        }
        else {
        $st = $pdo->prepare("SELECT id, image_url, weight_g, length_mm, width_mm, height_mm
                               FROM wsm_products WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            $flash = 'Nie znaleziono produktu.'; $kind = 'err';
        } else {
            $body = [];
            foreach (['slug', 'origin', 'cocoa', 'unit_label', 'badge', 'vat_rate'] as $k) {
                if (isset($_POST[$k])) $body[$k] = $_POST[$k];
            }
            // ── LA GRAMATURE ET LES DIMENSIONS ───────────────────────────
            //
            // Elles n'étaient éditables sur AUCUN écran, alors qu'elles
            // décident du gabarit InPost et du coût d'expédition. Un produit
            // resté à 0 g part au tarif d'un gabarit choisi sur du vide.
            $mesures = [];
            foreach (['weight_g' => 200000, 'length_mm' => 3000, 'width_mm' => 3000, 'height_mm' => 3000] as $k => $max) {
                if (!isset($_POST[$k]) || trim((string) $_POST[$k]) === '') continue;
                $n = (int) round(wsm_parse_price((string) $_POST[$k]) ?? -1);
                if ($n < 0 || $n > $max) { $fieldErrors[$k] = 'od 0 do ' . $max; continue; }
                $mesures[$k] = $n;
            }
            // Le stock ne se pose plus directement : il se corrige, et la
            // correction laisse une trace dans le magasin (qui, quand, pourquoi).
            $wantStock = isset($_POST['stock']) ? max(0, (int) $_POST['stock']) : null;
            $body['shop_visible'] = !empty($_POST['shop_visible']) ? 1 : 0;

            $old = (string) $cur['image_url'];
            $newUrl = null;

            if (!empty($_POST['remove_image'])) {
                $body['image_url'] = '';
            } elseif (!empty($_FILES['photo']['name'] ?? '')) {
                [$url, $err] = wsm_media_store($_FILES['photo']);
                if ($err !== null) { $fieldErrors['photo'] = $err; }
                else { $body['image_url'] = $url; $newUrl = $url; }
            } elseif (isset($_POST['image_url'])) {
                // LE CHAMP VIDE VEUT DIRE « JE N'Y TOUCHE PAS », JAMAIS
                // « efface ». Quand la photo est un fichier envoyé, le
                // formulaire rend ce champ VIDE — c'est un champ d'adresse
                // externe, une adresse media/ n'a rien à y faire. La condition
                // d'avant comparait ce vide à « media/xyz.webp », les trouvait
                // différents, et posait une chaîne vide : chaque « Zapisz »
                // effaçait la photo qu'on venait de mettre.
                //
                // Effacer reste possible, mais par la case « Usuń zdjęcie » :
                // un geste explicite, coché exprès. Une destruction ne doit pas
                // être le comportement par défaut d'un bouton d'enregistrement.
                $v = trim((string) $_POST['image_url']);
                if ($v !== '' && $v !== $old) $body['image_url'] = $v;
            }

            if (!$fieldErrors) {
                [$cols, $errs] = wsm_validate_product_shop($pdo, $body, $id);
                if ($errs) {
                    $fieldErrors = $errs;
                    // L'envoi a réussi mais l'enregistrement échoue : on ne
                    // laisse pas le fichier orphelin sur le disque.
                    if ($newUrl !== null) wsm_media_delete($newUrl);
                    $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
                } else {
                    $set = []; $vals = [];
                    foreach ($cols as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
                    // null = saisie inexploitable : on NE TOUCHE PAS au prix.
                    // L'ancien code écrivait (float) d'une chaîne à espaces —
                    // « 1 234,50 » devenait 1,00 zł, en silence.
                    if (isset($_POST['prix']) && trim((string) $_POST['prix']) !== '') {
                        $pr = wsm_parse_price((string) $_POST['prix']);
                        if ($pr === null) $fieldErrors['prix'] = 'nie rozumiem tej ceny';
                        elseif ($pr < 0)  $fieldErrors['prix'] = 'nie może być ujemna';
                        else { $set[] = 'prix = ?'; $vals[] = $pr; }
                    }
                    // La marque est une référence : la chaîne vide veut dire
                    // « aucune », et NULL est le bon marqueur en base — 0
                    // pointerait vers une ligne qui n'existe pas.
                    if (isset($_POST['brand_id'])) {
                        $bid = (int) $_POST['brand_id'];
                        $set[] = 'brand_id = ?'; $vals[] = $bid > 0 ? $bid : null;
                    }
                    foreach ($mesures as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
                    // Le gabarit se DÉDUIT des dimensions : le laisser en
                    // arrière ferait payer un tarif qui ne correspond plus au
                    // colis. Même règle que le semis (wsm_seed_shop).
                    if ($mesures && function_exists('wsm_inpost_template')) {
                        $dim = fn(string $k) => (int) ($mesures[$k] ?? $cur[$k] ?? 0);
                        $set[] = 'parcel_template = ?';
                        $vals[] = wsm_inpost_template($dim('length_mm'), $dim('width_mm'), $dim('height_mm'));
                    }
                    $vals[] = $id;
                    if ($set) {
                        $pdo->prepare("UPDATE wsm_products SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                    }
                    if ($wantStock !== null) {
                        wsm_stock_set($pdo, $id, $wantStock, [
                            'reason' => trim((string) ($_POST['stock_reason'] ?? '')) ?: 'korekta ręczna',
                            'actor'  => (string) ($me['nom'] ?? ''),
                        ]);
                    }
                    // ── Les trois textes, dans la langue de base ──────────
                    //
                    // wsm_cms_save() refuse une clé absente de la base — garde
                    // juste, elle empêche un POST d'inventer des clés. Mais un
                    // produit peut n'avoir jamais eu de description : on pose
                    // donc la ligne vide AVANT, sinon la saisie serait avalée
                    // en silence, ce qui est exactement le défaut qu'on répare.
                    $vals = [];
                    foreach (['nazwa' => 'name', 'podtytul' => 'subtitle', 'opis' => 'desc'] as $champ => $suf) {
                        if (!isset($_POST[$champ])) continue;
                        $cle = 'product.' . $id . '.' . $suf;
                        $st2 = $pdo->prepare("SELECT COUNT(*) FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
                        $st2->execute([WSM_CMS_BASE_LANG, $cle]);
                        if (!(int) $st2->fetchColumn()) {
                            $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,'')")
                                ->execute([WSM_CMS_BASE_LANG, $cle]);
                        }
                        $vals[$cle] = [WSM_CMS_BASE_LANG => (string) $_POST[$champ]];
                    }
                    $nTxt = 0;
                    if ($vals) {
                        [$nTxt, ] = wsm_cms_save($pdo, 'wsm_shop_i18n', $vals,
                                                 [WSM_CMS_BASE_LANG], (string) ($me['nom'] ?? ''));
                        // La console liste et trie sur wsm_products.nom : le
                        // laisser en arrière ferait dire deux noms différents
                        // au même produit, selon l'écran qu'on regarde.
                        $nn = trim((string) ($_POST['nazwa'] ?? ''));
                        if ($nn !== '') $pdo->prepare("UPDATE wsm_products SET nom = ? WHERE id = ?")->execute([$nn, $id]);
                    }
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana',
                              'wsm_products ' . $id . ($nTxt ? ' · teksty: ' . $nTxt : ''), 'Sieć');
                    // L'ancienne photo n'est effacée qu'une fois la nouvelle
                    // réellement enregistrée en base.
                    if ($newUrl !== null && $old !== '' && $old !== $newUrl) wsm_media_delete($old);
                    if (!empty($_POST['remove_image']) && $old !== '') wsm_media_delete($old);
                    $flash = 'Zapisano: ' . $id; $kind = 'ok';
                }
            } else {
                $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
            }
        }
        }
    }
}

$brands = wsm_brands_all($pdo);
$brandCounts = wsm_brand_counts($pdo);

// ─── LES TEXTES DU PRODUIT ─────────────────────────────────────────────────
//
// Le nom, le sous-titre et la description d'un produit ne sont PAS des
// colonnes de wsm_products : ce sont des textes traduits, `product.<id>.name`
// et compagnie, que la boutique lit dans wsm_shop_i18n (shop.php : `$S[...]
// ?? $r['nom']`). Ils vivaient donc uniquement dans l'écran Treści, à l'autre
// bout de la console — et cet écran-ci, celui du produit, n'en portait aucun.
//
// Résultat, rapporté depuis la boutique : « zdjęcie się dodaje ale opis nie
// chce się zmienić ». La photo est sur cet écran, le texte non. Rien n'était
// écrasé : il n'y avait simplement nulle part où l'écrire.
// ─── LE SLUG MANQUANT BLOQUAIT TOUT ────────────────────────────────────────
//
// 13 produits sur 22 n'ont pas de slug — des lignes d'avant la boutique, que
// personne n'a jamais rouvertes. Or wsm_validate_product_shop() le déclare
// « wymagany », et le formulaire le poste toujours, même vide : TOUT
// enregistrement sur ces produits était donc refusé en bloc, avec l'erreur
// posée sur un champ que personne n'éditait. On changeait un prix, une
// description, et rien n'entrait — « jakby były nadpisane ».
//
// On propose donc une adresse, VISIBLE dans le champ avant d'enregistrer. Pas
// de génération silencieuse : le slug est l'adresse publique du produit, on
// ne l'invente pas dans le dos de qui appuie sur le bouton.
$slugProp = function (string $nom, string $id) use ($pdo): string {
    $tr = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
           'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];
    $v = strtolower(strtr(trim($nom), $tr));
    $v = trim((string) preg_replace('/[^a-z0-9]+/', '-', $v), '-');
    if ($v === '') $v = strtolower($id);
    $v = substr($v, 0, 80);
    // Deux produits ne peuvent pas partager une adresse : l'un deviendrait
    // inatteignable. En cas de collision, l'identifiant tranche.
    $st = $pdo->prepare("SELECT id FROM wsm_products WHERE slug = ? AND id <> ?");
    $st->execute([$v, $id]);
    return $st->fetchColumn() ? substr($v . '-' . strtolower($id), 0, 80) : $v;
};

// UN CHAMP REFUSÉ NE DOIT PAS EFFACER LES DOUZE AUTRES.
//
// En cas d'erreur, la fiche se rechargeait depuis la BASE : tout ce qui venait
// d'être tapé — prix, description, gramatura — disparaissait, et seul un petit
// « wymagany » sous un champ qu'on n'éditait pas expliquait pourquoi. De
// l'extérieur, ça se lit exactement comme « zmiany nie chcą wejść ».
//
// $vv() redonne donc la saisie quand l'enregistrement a échoué SUR CE
// produit-là. Ailleurs, la base fait foi.
$vv = function (string $champ, string $defaut) use (&$fieldErrors, &$openId, &$id) {
    $vise = ($openId !== '' && isset($id) && $openId === $id);
    return ($fieldErrors && $vise && isset($_POST[$champ])) ? (string) $_POST[$champ] : $defaut;
};

$kategorie = $pdo->query("SELECT id, name FROM wsm_categories WHERE active = 1 ORDER BY sort_order, name")
                 ->fetchAll(PDO::FETCH_ASSOC);

$T = wsm_cms_load($pdo, 'wsm_shop_i18n', [WSM_CMS_BASE_LANG]);
$txt = fn(string $id, string $champ): string
     => (string) ($T['product.' . $id . '.' . $champ][WSM_CMS_BASE_LANG] ?? '');

$rows = $pdo->query(
    "SELECT p.*, c.name AS cat FROM wsm_products p
       LEFT JOIN wsm_categories c ON c.id = p.category_id
      ORDER BY p.shop_visible DESC, p.sort_order, p.nom"
)->fetchAll();
$writable = is_dir(wsm_media_dir()) ? is_writable(wsm_media_dir()) : is_writable(dirname(wsm_media_dir()));
$nVisible = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1));
$nPhoto   = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1 && (string) $r['image_url'] !== ''));

console_head('Produkty i zdjęcia', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 78ch; line-height: 1.55; }
  .item { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          margin-bottom: 14px; box-shadow: var(--shadow-xs); overflow: hidden; }
  .item > summary { display: flex; align-items: center; gap: 14px; padding: 12px 14px; cursor: pointer; list-style: none; }
  .item > summary::-webkit-details-marker { display: none; }
  .item[open] > summary { border-bottom: 1px solid var(--border-subtle); background: var(--surface-raised); }
  .thumb { width: 56px; height: 56px; flex: none; border-radius: 10px; object-fit: cover; background: var(--cream-200); }
  .thumb.empty { display: grid; place-items: center; font-family: var(--font-mono); font-size: 10px;
                 color: var(--text-muted); text-align: center; line-height: 1.25; padding: 4px; }
  .sum-name { font-family: var(--font-display); font-size: 16px; color: var(--text-strong); font-weight: 600; }
  .sum-meta { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .sum-right { margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
  .tag.on  { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.off { background: var(--cream-300); color: var(--text-muted); }
  .edit { padding: 18px 14px; display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 820px) { .edit { grid-template-columns: 260px 1fr; gap: 24px; } }
  .preview img, .preview .ph { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 12px;
                               background: var(--cream-200); display: block; }
  .preview .ph { display: grid; place-items: center; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  label.f { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600;
            color: var(--text-strong); margin-bottom: 12px; }
  label.f input { font-weight: 400; }
  label.f small { font-weight: 400; color: var(--text-muted); font-size: 12px; }
  label.f small.err { color: var(--danger); font-weight: 600; }
  label.chk { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; cursor: pointer; margin-bottom: 10px; }
  label.chk input { accent-color: var(--brand); }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  a.view { font-size: 13px; color: var(--brand); font-weight: 600; text-decoration: none; }
  a.view:hover { text-decoration: underline; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Produkty' => null]);
?>
  <?php if (!$isAdmin): ?>
    <p class="warnbox">Twoja rola pozwala tylko przeglądać. Zmiany może zapisywać rola <b>Centrala</b>.</p>
  <?php elseif (!$writable): ?>
    <p class="warnbox">Katalog <code>shop/media/</code> nie jest zapisywalny — wgrywanie zdjęć nie zadziała.
      Na serwerze: <code>chown www-data shop/media</code>.</p>
  <?php endif; ?>

  <p class="hint">
    Zdjęcie wgrywasz tutaj — plik jest po stronie serwera <b>ponownie zakodowany</b> i zmniejszony
    (maks. 1400 px, WebP), więc do sklepu trafia lekki obraz bez metadanych. Przyjmujemy JPEG, PNG,
    WebP i GIF do 8 MB. Zamiast pliku możesz też podać adres <code>https://</code>.
    Produkt pojawia się w sklepie dopiero po zaznaczeniu <b>W sprzedaży</b> i nadaniu adresu (slug).
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= $nVisible ?></b><span>W sprzedaży</span></div>
    <div class="kpi"><b><?= $nPhoto ?> / <?= $nVisible ?></b><span>Ze zdjęciem</span></div>
    <div class="kpi"><b><?= count($rows) ?></b><span>Produkty w bazie</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <?php // TROIS CHAMPS, PAS QUINZE. Le reste a des défauts et s'édite sur la
        // fiche qui s'ouvre juste après : un formulaire de création qui
        // redemanderait tout serait un second endroit à tenir à jour, et il
        // divergerait au premier champ ajouté.
        $nErr = fn(string $k) => isset($fieldErrors[$k]) ? '<small class="err">' . h($fieldErrors[$k]) . '</small>' : ''; ?>
  <details class="nowy"<?= isset($fieldErrors['n_nazwa']) || isset($fieldErrors['n_kategoria']) || isset($fieldErrors['n_cena']) ? ' open' : '' ?>>
    <summary>+ Nowy produkt</summary>
    <form method="post" class="nowy-in">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <label class="f">Nazwa
        <input type="text" name="n_nazwa" value="<?= h((string) ($_POST['n_nazwa'] ?? '')) ?>"
               maxlength="120" required>
        <?= $nErr('n_nazwa') ?: '<small>Z niej powstaje adres w sklepie i identyfikator.</small>' ?>
      </label>
      <label class="f">Kategoria
        <select name="n_kategoria" required>
          <option value="">— wybierz —</option>
          <?php foreach ($kategorie as $k): ?>
          <option value="<?= (int) $k['id'] ?>"<?= (int) ($_POST['n_kategoria'] ?? 0) === (int) $k['id'] ? ' selected' : '' ?>><?= h((string) $k['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $nErr('n_kategoria') ?>
      </label>
      <label class="f">Cena brutto (zł)
        <input type="text" name="n_cena" value="<?= h((string) ($_POST['n_cena'] ?? '')) ?>" placeholder="64,90">
        <?= $nErr('n_cena') ?: '<small>Można uzupełnić później.</small>' ?>
      </label>
      <div class="nowy-akcja">
        <button name="nowy" value="1">Utwórz produkt</button>
        <small>Powstaje NIEWIDOCZNY w sklepie. Uzupełniasz zdjęcie, opis i gramaturę, potem włączasz sprzedaż.</small>
      </div>
    </form>
  </details>
  <?php endif; ?>

  <?php foreach ($rows as $p):
    $id = (string) $p['id'];
    $img = (string) $p['image_url'];
    $vis = (int) $p['shop_visible'] === 1;
    $act = (int) ($p['active'] ?? 1) === 1;
    $open = $openId === $id; ?>
  <details class="item"<?= $open ? ' open' : '' ?> id="p-<?= h($id) ?>">
    <summary>
      <?php if ($img !== ''): ?>
        <img class="thumb" src="<?= h(img_src($img)) ?>" alt="">
      <?php else: ?>
        <div class="thumb empty">brak<br>zdjęcia</div>
      <?php endif; ?>
      <div>
        <div class="sum-name"><?= h((string) $p['nom']) ?></div>
        <div class="sum-meta"><?= h($id) ?> · <?= h((string) ($p['cat'] ?? '')) ?> · <?= h(zl($p['prix'])) ?>
          · VAT <?= h(wsm_vat_percent((float) ($p['vat_rate'] ?? 0.23))) ?> %</div>
      </div>
      <div class="sum-right">
        <?php if ($vis && $img === ''): ?><span class="tag no">bez zdjęcia</span><?php endif; ?>
        <?php if (!$act): ?><span class="tag bad">wyłączony</span><?php endif; ?>
        <span class="tag <?= $vis ? 'on' : 'off' ?>"><?= $vis ? 'W sprzedaży' : 'Ukryty' ?></span>
        <span class="tag">stan <?= (int) $p['stock'] ?></span>
      </div>
    </summary>

    <form class="edit" method="post" enctype="multipart/form-data" action="produkty.php?id=<?= h(urlencode($id)) ?>#p-<?= h($id) ?>">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($id) ?>">

      <div class="preview">
        <?php if ($img !== ''): ?>
          <img src="<?= h(img_src($img)) ?>" alt="<?= h((string) $p['nom']) ?>">
          <?php if ($isAdmin): ?>
          <label class="chk" style="margin-top:12px">
            <input type="checkbox" name="remove_image" value="1"><span>Usuń zdjęcie przy zapisie</span>
          </label>
          <?php endif; ?>
        <?php else: ?>
          <div class="ph">Bez zdjęcia</div>
        <?php endif; ?>
        <?php if ($vis && $p['slug'] !== ''): ?>
          <p style="margin:12px 0 0"><a class="view" href="../shop/p/<?= h(urlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener">Zobacz w sklepie ↗</a></p>
        <?php endif; ?>
      </div>

      <div>
        <div class="grid2">
          <label class="f">Zdjęcie (plik)
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['photo']) && $open): ?>
              <small class="err"><?= h($fieldErrors['photo']) ?></small>
            <?php else: ?><small>JPEG · PNG · WebP · GIF, maks. 8 MB</small><?php endif; ?>
          </label>
          <label class="f">…albo adres obrazu
            <input type="url" name="image_url" value="<?= h(str_starts_with($img, 'media/') ? '' : $img) ?>"
                   placeholder="https://…"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['image_url']) && $open): ?>
              <small class="err"><?= h($fieldErrors['image_url']) ?></small>
            <?php else: ?><small>Tylko https — inaczej przeglądarka zablokuje obraz.</small><?php endif; ?>
          </label>

          <?php // LE NOM ET LA DESCRIPTION EN PREMIER. Ce sont les deux
                // choses qu'on vient changer le plus souvent sur un produit,
                // et elles n'étaient sur AUCUN écran de produit. ?>
          <label class="f" style="grid-column:1/-1">Nazwa produktu
            <input type="text" name="nazwa" value="<?= h($vv('nazwa', $txt((string) $p['id'], 'name'))) ?>"
                   maxlength="120"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Widoczna w sklepie i na dokumentach. Tłumaczenia — w <a class="view" href="tresci.php?sekcja=product">Treściach</a>.</small>
          </label>
          <label class="f" style="grid-column:1/-1">Podtytuł
            <input type="text" name="podtytul" value="<?= h($vv('podtytul', $txt((string) $p['id'], 'subtitle'))) ?>"
                   maxlength="200"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Jedna linia pod nazwą na karcie produktu.</small>
          </label>
          <label class="f" style="grid-column:1/-1">Opis
            <textarea name="opis" rows="4"<?= $isAdmin ? '' : ' disabled' ?>><?= h($vv('opis', $txt((string) $p['id'], 'desc'))) ?></textarea>
            <small>Tekst na stronie produktu. Puste pole = bez opisu.</small>
          </label>

          <label class="f">Adres w sklepie (slug)
            <?php $slugVal = (string) $p['slug'] !== ''
                    ? (string) $p['slug']
                    : $slugProp($txt((string) $id, 'name') ?: (string) $p['nom'], (string) $id); ?>
            <input type="text" name="slug" value="<?= h($vv('slug', $slugVal)) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['slug']) && $open): ?>
              <small class="err"><?= h($fieldErrors['slug']) ?></small>
            <?php else: ?><small>/shop/p/<b><?= h((string) $p['slug'] ?: '…') ?></b></small><?php endif; ?>
          </label>
          <label class="f">Cena brutto (zł)
            <input type="text" name="prix" value="<?= h($vv('prix', number_format((float) $p['prix'], 2, ',', ''))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Cena widoczna dla klienta, z VAT.</small>
          </label>

          <?php $rate = (float) ($p['vat_rate'] ?? 0.23);
                [$netG, $vatG] = wsm_split_vat(wsm_grosze($p['prix']), $rate); ?>
          <?php // GRAMATURA ET WYMIARY. Absents de tous les écrans jusqu'ici,
                // alors qu'ils choisissent le gabarit InPost et donc le prix
                // payé pour expédier. ?>
          <label class="f">Gramatura (g)
            <input type="text" name="weight_g" value="<?= h($vv('weight_g', (string) (int) ($p['weight_g'] ?? 0))) ?>"
                   placeholder="1000"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['weight_g']) && $open): ?>
              <small class="err"><?= h($fieldErrors['weight_g']) ?></small>
            <?php else: ?><small>Waga produktu. Wchodzi w wagę paczki.</small><?php endif; ?>
          </label>
          <label class="f">Wymiary (mm) — dł. × szer. × wys.
            <span class="wym">
              <input type="text" name="length_mm" value="<?= h($vv('length_mm', (string) (int) ($p['length_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
              <input type="text" name="width_mm"  value="<?= h($vv('width_mm',  (string) (int) ($p['width_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
              <input type="text" name="height_mm" value="<?= h($vv('height_mm', (string) (int) ($p['height_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            </span>
            <small>Gabaryt paczkomatu wylicza się z tych trzech liczb<?php
              $gab = (string) ($p['parcel_template'] ?? ''); echo $gab !== '' ? ' — teraz: <b>' . h($gab) . '</b>' : ''; ?>.</small>
          </label>

          <label class="f">Stawka VAT
            <select name="vat_rate"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php foreach (WSM_VAT_RATES as $r): ?>
              <option value="<?= h((string) $r) ?>"<?= abs($r - $rate) < 0.0005 ? ' selected' : '' ?>><?= h(wsm_vat_percent($r)) ?> %</option>
              <?php endforeach; ?>
              <?php if (!array_filter(WSM_VAT_RATES, fn($r) => abs($r - $rate) < 0.0005)): ?>
              <option value="<?= h((string) $rate) ?>" selected><?= h(wsm_vat_percent($rate)) ?> % (nietypowa)</option>
              <?php endif; ?>
            </select>
            <?php if (isset($fieldErrors['vat_rate']) && $open): ?>
              <small class="err"><?= h($fieldErrors['vat_rate']) ?></small>
            <?php else: ?>
              <small>Z ceny brutto: <b><?= h(number_format($netG / 100, 2, ',', ' ')) ?> zł netto</b>
                + <?= h(number_format($vatG / 100, 2, ',', ' ')) ?> zł VAT. Tak trafi na fakturę.</small>
            <?php endif; ?>
          </label>

          <label class="f">Stan magazynowy
            <input type="number" name="stock" min="0" value="<?= (int) $p['stock'] ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['stock']) && $open): ?>
              <small class="err"><?= h($fieldErrors['stock']) ?></small>
            <?php else: ?><small>Zamówienie ponad stan przechodzi — klient dostaje mail „skontaktujemy się”.</small><?php endif; ?>
          </label>
          <label class="f">Powód zmiany stanu
            <input type="text" name="stock_reason" placeholder="np. inwentaryzacja, stłuczka"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Zapisywany w <a class="view" href="magazyn.php">Magazynie</a>. Zostaw puste, jeśli stanu nie zmieniasz.</small>
          </label>
          <label class="f">Gramatura
            <input type="text" name="unit_label" value="<?= h($vv('unit_label', (string) $p['unit_label'])) ?>" placeholder="1 kg"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Pokazywana na karcie produktu.</small>
          </label>

          <label class="f">Marka
            <select name="brand_id"<?= $isAdmin ? '' : ' disabled' ?>>
              <option value="">— bez marki —</option>
              <?php foreach ($brands as $b): ?>
              <option value="<?= (int) $b['id'] ?>"<?= (int) ($p['brand_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>>
                <?= h((string) $b['name']) ?><?= (int) $b['active'] === 1 ? '' : ' (wyłączona)' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small>Logo pokazuje się na kafelku i na karcie produktu w sklepie.
              Marki dodaje się <a class="view" href="produkty.php#marki">niżej na tej stronie</a>.</small>
          </label>

          <label class="f">Pochodzenie
            <input type="text" name="origin" value="<?= h($vv('origin', (string) $p['origin'])) ?>" placeholder="Madagaskar"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>
          <label class="f">Kakao
            <input type="text" name="cocoa" value="<?= h($vv('cocoa', (string) $p['cocoa'])) ?>" placeholder="70 %"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>

          <label class="f">Etykieta
            <input type="text" name="badge" value="<?= h($vv('badge', (string) $p['badge'])) ?>" placeholder="bestseller"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>bestseller · nowosc · prezent — tłumaczone w wsm_shop_i18n.</small>
          </label>
          <div></div>
        </div>

        <label class="chk" style="margin-top:14px">
          <input type="checkbox" name="shop_visible" value="1"<?= $vis ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
          <span><b>W sprzedaży</b><br><small style="color:var(--text-muted)">Widoczny w sklepie i możliwy do kupienia.</small></span>
        </label>

        <?php if ($isAdmin): ?>
        <div class="actions"><button class="primary" type="submit">Zapisz</button></div>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($isAdmin): ?>
    <div class="edit" style="padding-top:0">
      <div></div>
      <div>
        <h3 style="font-family:var(--font-display);font-size:15px;margin:0 0 6px">Cykl życia produktu</h3>
        <p style="font-size:12.5px;color:var(--text-muted);line-height:1.6;margin:0 0 10px">
          <b>Wyłączenie</b> zdejmuje produkt ze sklepu i z magazynu, ale zostawia historię:
          dawne zamówienia i faktury nadal go nazywają. To niemal zawsze właściwy ruch.
          <b>Usunięcie</b> jest możliwe tylko wtedy, gdy nic się do produktu nie odwołuje —
          inaczej dokumenty przestałyby być czytelne.
        </p>
        <div class="actions">
          <form method="post">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <button type="submit" name="aktywacja" value="<?= $act ? '0' : '1' ?>">
              <?= $act ? 'Wyłącz produkt' : 'Włącz produkt' ?>
            </button>
          </form>
          <form method="post" onsubmit="return confirm('Usunąć produkt <?= h($id) ?> bezpowrotnie?')">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <button class="danger" type="submit" name="usun" value="1">Usuń trwale</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>

<?php
// ---------------------------------------------------------------------------
//  Marques
//
//  Une marque vit ici plutôt que sur chaque fiche produit : le logo, le nom et
//  l'adresse du site se corrigent UNE fois et se voient partout. La fiche
//  produit ne fait que la désigner.
// ---------------------------------------------------------------------------
$editB = isset($_GET['marka']) ? wsm_brand($pdo, (int) $_GET['marka']) : null;
?>
<div class="panel" id="marki">
  <h2>Marki</h2>
  <p class="muted small">
    Logo marki pokazuje się na kafelku w katalogu i na karcie produktu w sklepie.
    Wgrywany plik zachowuje <b>przezroczystość</b> — logo z przezroczystym tłem nie dostanie
    kremowego prostokąta. Marka bez logo pokazuje swoją nazwę: pusty kadr wygląda jak zepsuty obrazek.
  </p>

  <?php if ($brands): ?>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Logo</th><th>Nazwa</th><th>Strona</th><th class="num">Produkty</th><th>Stan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($brands as $b): $n = $brandCounts[(int) $b['id']] ?? 0; ?>
    <tr>
      <td data-l="Logo">
        <?php if ((string) $b['logo_url'] !== ''): ?>
          <img src="<?= h(img_src((string) $b['logo_url'])) ?>" alt="<?= h((string) $b['name']) ?>"
               style="height:26px;width:auto;max-width:110px;object-fit:contain;display:block">
        <?php else: ?><span class="muted small">brak logo</span><?php endif; ?>
      </td>
      <td data-l="Nazwa"><b><?= h((string) $b['name']) ?></b><br>
        <code class="muted" style="font-size:11.5px"><?= h((string) $b['slug']) ?></code></td>
      <td data-l="Strona"><?= (string) $b['site_url'] !== ''
            ? '<a href="' . h((string) $b['site_url']) . '" target="_blank" rel="noopener">otwórz ↗</a>'
            : '<span class="muted">—</span>' ?></td>
      <td data-l="Produkty" class="num"><?= $n ?></td>
      <td data-l="Stan"><span class="tag <?= (int) $b['active'] === 1 ? 'ok' : 'off' ?>">
            <?= (int) $b['active'] === 1 ? 'czynna' : 'wyłączona' ?></span></td>
      <td data-l="">
        <?php if ($isAdmin): ?>
        <div class="actions">
          <a class="code" href="produkty.php?marka=<?= (int) $b['id'] ?>#marki">Edytuj</a>
          <?php if ($n === 0): ?>
          <form method="post" onsubmit="return confirm('Usunąć markę <?= h((string) $b['name']) ?>?')">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <button class="danger" type="submit" name="marka_usun" value="<?= (int) $b['id'] ?>">Usuń</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted small">Nie ma jeszcze żadnej marki.</p>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <h3><?= $editB ? 'Edycja marki — ' . h((string) $editB['name']) : 'Nowa marka' ?></h3>
  <form method="post" enctype="multipart/form-data" class="grid2">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="marka_zapisz" value="<?= (int) ($editB['id'] ?? 0) ?>">
    <label class="field"><span>Nazwa</span>
      <input type="text" name="name" value="<?= h((string) ($editB['name'] ?? '')) ?>" required maxlength="120"></label>
    <label class="field"><span>Strona marki (https://)</span>
      <input type="url" name="site_url" value="<?= h((string) ($editB['site_url'] ?? '')) ?>" placeholder="https://"></label>
    <label class="field"><span>Logo (PNG z przezroczystością, SVG nie)</span>
      <input type="file" name="logo" accept="image/png,image/webp,image/jpeg,image/gif"></label>
    <label class="field"><span>Kolejność</span>
      <input type="number" name="sort_order" value="<?= (int) ($editB['sort_order'] ?? 0) ?>" style="max-width:120px"></label>
    <label class="field" style="grid-column:1/-1"><span>Notatka wewnętrzna</span>
      <input type="text" name="note" value="<?= h((string) ($editB['note'] ?? '')) ?>" maxlength="255"
             placeholder="np. kontakt handlowy, warunki"></label>
    <label class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="active" value="1" <?= !$editB || (int) $editB['active'] === 1 ? 'checked' : '' ?>>
      <span style="margin:0">Czynna — widoczna w sklepie</span></label>
    <?php if ($editB && (string) $editB['logo_url'] !== ''): ?>
    <label class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="remove_logo" value="1">
      <span style="margin:0">Usuń obecne logo</span></label>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="primary" type="submit"><?= $editB ? 'Zapisz markę' : 'Dodaj markę' ?></button>
      <?php if ($editB): ?><a class="code" href="produkty.php#marki">Anuluj</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php console_foot();
