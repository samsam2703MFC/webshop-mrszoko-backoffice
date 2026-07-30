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
 */
function wsm_media_store(array $file): array {
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
    // Fond crème plutôt que noir : une PNG transparente posée sur du noir
    // ressortirait en carré sombre au milieu de la boutique.
    imagefill($dst, 0, 0, imagecolorallocate($dst, 0xFB, 0xF6, 0xEF));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = wsm_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { imagedestroy($dst); return [null, 'brak katalogu media/']; }
    if (!is_writable($dir)) { imagedestroy($dst); return [null, 'katalog media/ nie jest zapisywalny']; }

    $webp = function_exists('imagewebp');
    $name = bin2hex(random_bytes(12)) . ($webp ? '.webp' : '.jpg');
    $path = $dir . '/' . $name;
    $written = $webp
        ? @imagewebp($dst, $path, WSM_MEDIA_QUALITY)
        : @imagejpeg($dst, $path, WSM_MEDIA_QUALITY);
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
    if (!preg_match('#^media/([a-f0-9]{24}\.(webp|jpg))$#', $url, $m)) return false;
    $path = wsm_media_dir() . '/' . $m[1];
    return is_file($path) && @unlink($path);
}

/** Une URL d'image acceptable en base : notre média, ou une adresse https. */
function wsm_media_valid_url(string $url): bool {
    if ($url === '') return true;                       // vider le champ est permis
    if (preg_match('#^media/[a-f0-9]{24}\.(webp|jpg)$#', $url)) return true;
    // Une image distante en http ferait basculer la page en contenu mixte et
    // le navigateur la bloquerait : https uniquement.
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://');
}
