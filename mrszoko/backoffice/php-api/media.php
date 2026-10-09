<?php
// ============================================================================
//  media.php — photos produit envoyées depuis la console.
//
//  Un fichier envoyé par un navigateur n'est pas une image parce qu'il finit
//  par « .jpg ». Il l'est parce qu'on a réussi à le décoder. C'est la règle ici :
//  chaque envoi est décodé puis RÉ-ENCODÉ par GD. Ce qui ressort est une image
//  fabriquée par nous — les métadonnées, les commentaires et tout ce qui
//  aurait pu voyager dedans sont laissés à la porte.
//
//  Trois autres précautions :
//    · le nom du fichier est tiré au sort, jamais repris de l'envoi (un nom
//      choisi par l'utilisateur, c'est un chemin choisi par l'utilisateur) ;
//    · l'extension vient du format ré-encodé, pas de ce qui était annoncé ;
//    · le dossier de destination refuse l'exécution (voir son .htaccess), donc
//      même une image parfaitement valide contenant du PHP ne serait que
//      téléchargée, jamais exécutée.
// ============================================================================
declare(strict_types=1);

const WSM_MEDIA_MAX_BYTES = 8 * 1024 * 1024;   // 8 Mo à l'envoi
const WSM_MEDIA_MAX_EDGE  = 1400;              // px — au-delà c'est du poids pour rien
const WSM_MEDIA_QUALITY   = 82;

/** Dossier physique des médias, côté serveur comme en dépôt. */
function wsm_media_dir(): string {
    return dirname(__DIR__, 2) . '/shop/media';
}

/** Chemin enregistré en base : relatif à la page boutique qui l'affichera. */
function wsm_media_url(string $filename): string {
    return 'media/' . $filename;
}

/** Types acceptés à l'entrée → fonction de lecture GD. */
function wsm_media_readers(): array {
    return [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
}

/**
 * Traite un fichier de $_FILES. Renvoie [url|null, erreur|null].
 *
 * @param array $file  une entrée de $_FILES
 * @param bool  $alpha Conserver la transparence. Vrai pour un LOGO, faux pour
 *   une photo de produit. Un logo aplati sur du crème ressort en rectangle
 *   sale dès qu'on le pose ailleurs que sur cette couleur exacte — sur une
 *   carte blanche, sur un bandeau sombre, à l'impression. Une photo, elle, n'a
 *   aucune transparence à préserver et gagne à peser moins.
 */
function wsm_media_store(array $file, bool $alpha = false): array {
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE)   return [null, 'nie wybrano pliku'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, 'plik za duży'];
    if ($err !== UPLOAD_ERR_OK)        return [null, 'błąd przesyłania (' . $err . ')'];

    $tmp = (string) ($file['tmp_name'] ?? '');
    // is_uploaded_file : la seule preuve que ce chemin vient bien d'un envoi
    // HTTP et n'a pas été soufflé par ailleurs.
    if ($tmp === '' || !is_uploaded_file($tmp)) return [null, 'nieprawidłowy plik'];
    if (filesize($tmp) > WSM_MEDIA_MAX_BYTES)   return [null, 'maks. 8 MB'];

    $info = @getimagesize($tmp);
    if (!$info || empty($info[2]))              return [null, 'to nie jest obraz'];
    $type = (int) $info[2];
    $readers = wsm_media_readers();
    if (!isset($readers[$type]))                return [null, 'dozwolone: JPEG, PNG, WebP, GIF'];
    if (!extension_loaded('gd'))                return [null, 'serwer bez rozszerzenia GD'];

    $src = @$readers[$type]($tmp);
    if (!$src)                                  return [null, 'nie udało się odczytać obrazu'];

    // Redimensionnement : on ne monte jamais une petite image en taille, on ne
    // fait que ramener les grandes à une largeur utile.
    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1.0, WSM_MEDIA_MAX_EDGE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    if ($alpha) {
        // On garde le canal alpha de bout en bout : sans alphablending à faux
        // ET savealpha à vrai, GD recompose la transparence sur du noir au
        // moment d'écrire, et le logo sort cerné.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        // Fond crème plutôt que noir : une PNG transparente posée sur du noir
        // ressortirait en carré sombre au milieu de la boutique.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 0xFB, 0xF6, 0xEF));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = wsm_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { imagedestroy($dst); return [null, 'brak katalogu media/']; }
    if (!is_writable($dir)) { imagedestroy($dst); return [null, 'katalog media/ nie jest zapisywalny']; }

    // JPEG n'a pas de canal alpha : pour un logo, c'est WebP ou PNG, jamais
    // JPEG — sinon la transparence qu'on vient de préserver serait perdue à
    // l'écriture, sans erreur ni message.
    $webp = function_exists('imagewebp');
    $ext  = $webp ? 'webp' : ($alpha ? 'png' : 'jpg');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $path = $dir . '/' . $name;
    $written = $webp
        ? @imagewebp($dst, $path, WSM_MEDIA_QUALITY)
        : ($alpha ? @imagepng($dst, $path, 6) : @imagejpeg($dst, $path, WSM_MEDIA_QUALITY));
    imagedestroy($dst);
    if (!$written) return [null, 'zapis nie powiódł się'];
    @chmod($path, 0644);

    return [wsm_media_url($name), null];
}

/**
 * Supprime un média précédemment enregistré. Refuse tout ce qui ne ressemble
 * pas exactement à un nom que nous avons nous-mêmes produit : c'est ce qui
 * empêche « media/../../api/config.local.php » d'être effacé.
 */
function wsm_media_delete(string $url): bool {
    if (!preg_match('#^media/([a-f0-9]{24}\.(webp|jpg|png))$#', $url, $m)) return false;
    $path = wsm_media_dir() . '/' . $m[1];
    return is_file($path) && @unlink($path);
}

/** Une URL d'image acceptable en base : notre média, ou une adresse https. */
function wsm_media_valid_url(string $url): bool {
    if ($url === '') return true;                       // vider le champ est permis
    if (preg_match('#^media/[a-f0-9]{24}\.(webp|jpg|png)$#', $url)) return true;
    // Une image distante en http ferait basculer la page en contenu mixte et
    // le navigateur la bloquerait : https uniquement.
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://');
}

// ---------------------------------------------------------------------------
//  LA MÉDIATHÈQUE. Les fichiers sont déposés depuis quatre écrans (produits,
//  marques, réglages, pages) et personne ne les voyait ensemble : un dossier
//  qui grossit, des fichiers orphelins, et aucun moyen de savoir lequel sert
//  encore. La liste dit, pour chaque fichier, OÙ il est utilisé — et on ne
//  supprime que ce qui ne sert nulle part.
// ---------------------------------------------------------------------------

/** Les fichiers du dossier média, les nôtres seulement, les plus récents d'abord. */
function wsm_media_list(): array {
    $dir = wsm_media_dir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if (!preg_match('/^[a-f0-9]{24}\.(webp|jpg|png)$/', $f)) continue;
        $path = $dir . '/' . $f;
        $info = @getimagesize($path);
        $out[] = [
            'url'   => wsm_media_url($f),
            'name'  => $f,
            'bytes' => (int) @filesize($path),
            'mtime' => (int) @filemtime($path),
            'w'     => (int) ($info[0] ?? 0),
            'h'     => (int) ($info[1] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/**
 * Où chaque média sert : url → liste d'usages lisibles (« produkt: Tabliczka
 * 70% », « ustawienie: zdjęcie na stronie głównej »…). Un fichier absent de
 * la table ne sert nulle part. Chaque source est lue à part : une table
 * manquante sur une base ancienne ne cache pas les autres usages.
 */
function wsm_media_usages(PDO $pdo): array {
    $u = [];
    $add = function (string $url, string $quoi) use (&$u): void {
        $url = trim($url);
        if ($url === '') return;
        $u[$url][] = $quoi;
    };
    try {
        foreach ($pdo->query("SELECT image_url, name FROM wsm_products WHERE image_url <> ''")->fetchAll() ?: [] as $r) {
            $add((string) $r['image_url'], 'produkt: ' . (string) $r['name']);
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT logo_url, name FROM wsm_brands WHERE logo_url <> ''")->fetchAll() ?: [] as $r) {
            $add((string) $r['logo_url'], 'marka: ' . (string) $r['name']);
        }
    } catch (Throwable $e) {}
    try {
        $noms = ['hero_image' => 'zdjęcie na stronie głównej', 'promise_icon_1' => 'ikona obietnicy 1',
                 'promise_icon_2' => 'ikona obietnicy 2', 'promise_icon_3' => 'ikona obietnicy 3'];
        foreach ($pdo->query("SELECT cle, val FROM wsm_settings WHERE cle IN ('hero_image','promise_icon_1','promise_icon_2','promise_icon_3')")->fetchAll() ?: [] as $r) {
            $add((string) $r['val'], 'ustawienie: ' . ($noms[(string) $r['cle']] ?? (string) $r['cle']));
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT photo_url, email FROM wsm_clients WHERE photo_url <> ''")->fetchAll() ?: [] as $r) {
            $add((string) $r['photo_url'], 'klient: ' . (string) $r['email']);
        }
    } catch (Throwable $e) {}
    try {
        $titres = [];
        foreach ($pdo->query("SELECT page_id, title FROM wsm_page_i18n WHERE lang = 'pl'")->fetchAll() ?: [] as $r) {
            $titres[(int) $r['page_id']] = (string) $r['title'];
        }
        foreach ($pdo->query("SELECT id, kind, image_url FROM wsm_pages")->fetchAll() ?: [] as $r) {
            $etiq = ((string) $r['kind'] === 'blok' ? 'blok: ' : 'strona: ') . ($titres[(int) $r['id']] ?? ('#' . (int) $r['id']));
            $add((string) $r['image_url'], $etiq);
        }
        foreach ($pdo->query("SELECT page_id, body FROM wsm_page_i18n WHERE body LIKE '%media/%'")->fetchAll() ?: [] as $r) {
            preg_match_all('#media/[a-f0-9]{24}\.(?:webp|jpg|png)#', (string) $r['body'], $m);
            foreach (array_unique($m[0] ?? []) as $url) {
                $add($url, 'w treści: ' . ($titres[(int) $r['page_id']] ?? ('#' . (int) $r['page_id'])));
            }
        }
    } catch (Throwable $e) {}
    foreach ($u as &$liste) $liste = array_values(array_unique($liste));
    unset($liste);
    return $u;
}
