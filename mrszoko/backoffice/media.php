<?php
declare(strict_types=1);
/**
 * media.php — la médiathèque : tout ce qui a été déposé, et à quoi ça sert.
 *
 * Les images arrivent par quatre écrans (produits, marques, réglages, pages)
 * et personne ne les voyait ensemble. Résultat connu d'avance : un dossier qui
 * grossit, des fichiers que plus rien ne cite, et aucun moyen de savoir
 * lequel on peut jeter. Ici chaque fichier porte ses USAGES, lus dans la base
 * au moment de l'affichage — pas rangés quelque part où ils vieilliraient.
 *
 * ON NE SUPPRIME QUE CE QUI NE SERT NULLE PART, et en deux gestes. Une image
 * citée dans une page n'a pas de bouton de suppression : la retirer casserait
 * la page sans que l'écran Strony s'en aperçoive.
 *
 * Le dépôt direct sert surtout aux pages : une photo à insérer dans un texte
 * via ![opis](media/…). « Ikona / logo » garde la transparence, « zdjęcie »
 * est aplati sur crème — la même règle qu'ailleurs (media.php, API).
 *
 * CHAQUE FICHIER A UN NOM. L'adresse est un hash — stable, sûre, illisible ;
 * le nom est pris du fichier envoyé et se change ici, en une ligne. C'est ce
 * nom que montrent le Kreator et Strony quand on choisit une photo.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/media.php';
require_once $API . '/delivery.php';   // wsm_audit

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $qui = (string) ($me['nom'] ?? '');
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może dodawać i usuwać media.'; $kind = 'err';
    } elseif (isset($_POST['dodaj'])) {
        $alpha = ($_POST['rodzaj'] ?? 'zdjecie') === 'ikona';
        [$url, $err] = wsm_media_store($_FILES['plik'] ?? [], $alpha);
        if ($err !== null) { $flash = 'Nie dodano: ' . $err . '.'; $kind = 'err'; }
        else {
            // Le nom tapé gagne sur celui du fichier (posé à l'envoi).
            $nazwa = wsm_media_title_clean((string) ($_POST['nazwa'] ?? ''));
            if ($nazwa !== '') wsm_media_title_set($pdo, (string) $url, $nazwa, $qui);
            $tytul = wsm_media_titles($pdo)[(string) $url] ?? '';
            wsm_audit($pdo, $qui, 'Media', 'dodano ' . (string) $url . ($tytul !== '' ? ' „' . $tytul . '”' : ''), 'Sklep');
            $flash = 'Dodano' . ($tytul !== '' ? ' „' . $tytul . '”' : '') . '. Adres do wstawienia w treści: ' . (string) $url;
        }
    } elseif (isset($_POST['nazwij'])) {
        $url = (string) $_POST['nazwij'];
        $nazwa = (string) ($_POST['nazwa'] ?? '');
        if (!wsm_media_title_set($pdo, $url, $nazwa, $qui)) { $flash = 'Nie udało się nazwać pliku ' . $url . '.'; $kind = 'err'; }
        else {
            wsm_audit($pdo, $qui, 'Media', 'nazwano ' . $url . ' „' . wsm_media_title_clean($nazwa) . '”', 'Sklep');
            $flash = wsm_media_title_clean($nazwa) !== '' ? 'Nazwano „' . wsm_media_title_clean($nazwa) . '”.' : 'Usunięto nazwę — plik został.';
        }
    } elseif (isset($_POST['usun'])) {
        $url = (string) $_POST['usun'];
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, usuń”, żeby usunąć plik.'; $kind = 'err'; }
        elseif (isset(wsm_media_usages($pdo)[$url])) { $flash = 'Ten plik jest używany — najpierw podmień go tam, gdzie służy.'; $kind = 'err'; }
        elseif (!wsm_media_delete($url)) { $flash = 'Nie udało się usunąć pliku ' . $url . '.'; $kind = 'err'; }
        else { wsm_audit($pdo, $qui, 'Media', 'usunięto ' . $url, 'Sklep'); $flash = 'Usunięto ' . $url . '.'; }
    }
}

$media  = wsm_media_list($pdo);
$usages = wsm_media_usages($pdo);
$szukaj = wsm_media_title_clean((string) ($_GET['szukaj'] ?? ''));
if ($szukaj !== '') $media = array_values(array_filter($media, fn($m) => mb_stripos($m['label'] . ' ' . $m['url'], $szukaj) !== false));
$bajty  = array_sum(array_column($media, 'bytes'));
$nieuzywane = count(array_filter($media, fn($m) => !isset($usages[$m['url']])));
$mb = fn(int $b) => number_format($b / 1048576, $b >= 1048576 ? 1 : 2, ',', "\u{202F}") . "\u{202F}MB";

$css = <<<CSS
  .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; }
  .media-card { border: 1px solid var(--border-subtle); border-radius: 12px; padding: 10px; background: var(--surface-card); }
  .media-card img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: 8px;
                    background: repeating-conic-gradient(var(--border-subtle) 0 25%, transparent 0 50%) 0 0 / 16px 16px; }
  .media-card .nazwa { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center; }
  .media-card .nazwa input { flex: 1 1 100%; min-width: 0; font-size: 13px; font-weight: 600; }
  .media-card .tytul { font-weight: 600; margin-top: 8px; } .media-card .tytul.brak { color: var(--text-muted); font-weight: 400; font-style: italic; }
  .media-card .adres { font-family: var(--font-mono); font-size: 11px; word-break: break-all; margin: 4px 0 4px; }
  .szukaj { display: flex; gap: 8px; align-items: center; margin: 0 0 12px; } .szukaj input { max-width: 320px; }
  .media-card .meta { font-size: 12px; color: var(--text-muted); }
  .media-card ul { margin: 6px 0 0; padding-left: 1.1em; font-size: 12px; }
  .media-card details { margin-top: 8px; } .media-card summary { cursor: pointer; font-size: 12px; color: var(--danger); }
  .bdg.wolny { background: var(--surface-sunken); color: var(--text-muted); }
CSS;

console_head('Media', $me, $css, (string) count($media));
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Media' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= count($media) ?></b><span>plików</span></div>
  <div class="kpi"><b><?= h($mb($bajty)) ?></b><span>łącznie</span></div>
  <div class="kpi"><b><?= $nieuzywane ?></b><span>nieużywanych</span></div>
</div>

<p class="why">
  Każde zdjęcie wgrane w Produktach, Markach, Integracjach, Stronach i Kreatorze ląduje tu. Przy pliku widać, <b>gdzie służy</b> —
  liczone w chwili wyświetlenia, nie zapisane. Usunąć można tylko plik, którego nic nie cytuje.
  Każdy plik ma <b>nazwę</b> — z nazwy pliku przy wgraniu, do zmiany tutaj; adres (<code>media/…</code>) jest stały i to on jest zapisany
  w produktach, stronach i sekcjach. W treści strony zdjęcie wstawia się przez <code>![opis](media/plik.webp)</code>.
</p>

<?php if ($isAdmin): ?>
<form method="post" enctype="multipart/form-data" class="panel">
  <?= console_csrf_field() ?>
  <h2>Dodaj plik</h2>
  <div class="grid2">
    <label class="field"><span>Plik</span>
      <input type="file" name="plik" accept="image/jpeg,image/png,image/webp,image/gif" required>
      <small>JPEG · PNG · WebP · GIF, maks. 8 MB. Duże zdjęcia są zmniejszane do 1400 px.</small></label>
    <label class="field"><span>Rodzaj</span>
      <select name="rodzaj">
        <option value="zdjecie">Zdjęcie — tło kremowe, lżejszy plik</option>
        <option value="ikona">Ikona / logo — zachowaj przezroczystość</option>
      </select></label>
    <label class="field"><span>Nazwa</span>
      <input type="text" name="nazwa" maxlength="120" placeholder="puste = z nazwy pliku, np. „Tabliczka 70 % — front”">
      <small>Pod tą nazwą plik pojawi się w Kreatorze i w Stronach. Można ją zmienić później.</small></label>
  </div>
  <div class="actions"><button class="primary" type="submit" name="dodaj" value="1">Dodaj</button></div>
</form>
<?php endif; ?>

<div class="panel" style="margin-top:20px">
  <h2>Pliki <span class="code"><?= count($media) ?></span></h2>
  <form method="get" class="szukaj"><input type="search" name="szukaj" value="<?= h($szukaj) ?>" placeholder="Szukaj po nazwie…"><button class="btn sm ghost" type="submit">Szukaj</button>
    <?php if ($szukaj !== ''): ?><a class="code" href="media.php">wyczyść</a><?php endif; ?></form>
  <?php if (!$media): ?>
  <p class="why"><?= $szukaj !== '' ? 'Nic o takiej nazwie.' : 'Pusto. Pierwsze zdjęcie produktu albo zdjęcie nagłówka pojawi się tutaj.' ?></p>
  <?php else: ?>
  <div class="media-grid">
    <?php foreach ($media as $m): $uz = $usages[$m['url']] ?? []; ?>
    <div class="media-card">
      <img src="<?= h(img_src($m['url'])) ?>" alt="<?= h($m['title']) ?>" loading="lazy">
      <?php if ($isAdmin): ?>
      <form method="post" class="nazwa"><?= console_csrf_field() ?>
        <input type="text" name="nazwa" value="<?= h($m['title']) ?>" maxlength="120" placeholder="bez nazwy — nadaj" aria-label="Nazwa pliku">
        <button class="btn sm ghost" type="submit" name="nazwij" value="<?= h($m['url']) ?>">Zapisz</button>
      </form>
      <?php else: ?>
      <div class="tytul<?= $m['title'] === '' ? ' brak' : '' ?>"><?= h($m['title'] !== '' ? $m['title'] : 'bez nazwy') ?></div>
      <?php endif; ?>
      <div class="adres"><?= h($m['url']) ?></div>
      <div class="meta"><?= (int) $m['w'] ?> × <?= (int) $m['h'] ?> px · <?= h($mb((int) $m['bytes'])) ?> · <?= h(date('Y-m-d', (int) $m['mtime'])) ?></div>
      <?php if ($uz): ?>
      <ul><?php foreach ($uz as $u): ?><li><?= h($u) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
      <p class="meta"><span class="bdg wolny">nieużywany</span></p>
      <?php if ($isAdmin): ?>
      <details>
        <summary>Usuń plik</summary>
        <form method="post"><?= console_csrf_field() ?>
          <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, usuń</span></label>
          <button class="btn sm" type="submit" name="usun" value="<?= h($m['url']) ?>">Usuń</button>
        </form>
      </details>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php console_foot();
