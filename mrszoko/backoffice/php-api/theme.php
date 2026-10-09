<?php
declare(strict_types=1);
/**
 * theme.php — le WYGLĄD de la boutique, réglé depuis la console.
 *
 * L'équipe demandait « un panneau pour changer la police, les couleurs, la
 * mise en page ». Un éditeur de pages est un produit en soi ; ce fichier fait
 * la part qui tient dans la main : cinq polices, trois couleurs, la forme des
 * coins, l'alignement du haut de page et deux blocs de l'accueil qu'on montre
 * ou qu'on cache. Chaque réglage est un JETON du design system — la boutique
 * ne change pas de structure, elle change d'habit, et tout ce qui a été testé
 * reste testé.
 *
 * TROIS COULEURS, PAS SOIXANTE. La palette compte une dizaine de bruns et
 * autant de crèmes : les faire régler un par un ferait un écran que personne
 * n'ose toucher. On règle la couleur de marque, l'accent et le papier ; les
 * nuances se CALCULENT (color-mix) à partir de là, comme le design system les
 * avait dérivées à la main.
 *
 * UNE COULEUR ILLISIBLE EST REFUSÉE, pas « appliquée puis corrigée ». La
 * marque porte du texte clair (bandeau, pied de page) : elle doit rester
 * sombre. Le papier porte du texte sombre : il doit rester clair. L'accent
 * porte du blanc sur les boutons : il ne peut pas être pastel. Un écran qui
 * accepterait n'importe quoi mettrait la boutique en blanc sur blanc à la
 * première fausse manipulation, et la découverte se ferait sur le site.
 *
 * Les valeurs vivent dans wsm_settings sous « theme.* », comme les autres
 * réglages. « Przywróć domyślne » les efface : rien ne reste à démêler.
 */

const WSM_THEME_PREFIX = 'theme.';

/**
 * LA BASE n'est pas LE DÉFAUT. La base, c'est ce que tokens.css déclare
 * (Mulish) : ce que la page porte quand le thème n'imprime rien, et ce que
 * la console garde. Le défaut de la BOUTIQUE est autre chose — Lora pour le
 * texte, Playfair Display pour les titres, demandé par l'équipe en octobre
 * 2026 (« inny wygląd »). Un défaut différent de la base doit donc être
 * IMPRIMÉ pour s'appliquer ; « Przywróć domyślne » y ramène, et Mulish reste
 * dans la liste pour qui préfère l'ancien habit.
 */
const WSM_THEME_FONT_BAZA = 'mulish';

/**
 * Les familles proposées : clé → [nom, pile CSS, étiquette lue par l'équipe].
 * Toutes HÉBERGÉES ICI (tools/fetch-fonts.sh), jamais chez Google : un
 * @font-face inutilisé ne coûte rien au visiteur, seule la famille choisie
 * est téléchargée.
 */
function wsm_theme_fonts(): array {
    return [
        'mulish'   => ['Mulish',           "'Mulish', system-ui, sans-serif",    'Mulish — poprzednia: humanistyczna, neutralna (czcionka konsoli)'],
        'nunito'   => ['Nunito',           "'Nunito', system-ui, sans-serif",    'Nunito — zaokrąglona, przyjazna'],
        'jost'     => ['Jost',             "'Jost', system-ui, sans-serif",      'Jost — geometryczna, nowoczesna'],
        'lora'     => ['Lora',             "'Lora', Georgia, serif",             'Lora — szeryfowa, klasyczna (domyślna dla tekstu)'],
        'playfair' => ['Playfair Display', "'Playfair Display', Georgia, serif", 'Playfair Display — szeryfowa, elegancka (domyślna dla nagłówków)'],
    ];
}

/**
 * Les réglages : clé → [étiquette, type, défaut, options, aide].
 * Les défauts sont LES VALEURS DU DESIGN SYSTEM (colors.css, radius.css) :
 * un thème vide, c'est la boutique telle qu'elle est aujourd'hui.
 */
function wsm_theme_fields(): array {
    $fonts = [];
    foreach (wsm_theme_fonts() as $k => $f) $fonts[$k] = $f[2];
    return [
        'font_body'  => ['Czcionka tekstu', 'select', 'lora', $fonts,
                         'Cały tekst sklepu: opisy, ceny, przyciski, stopka.'],
        'font_head'  => ['Czcionka nagłówków', 'select', 'playfair', ['same' => 'Taka sama jak tekst'] + $fonts,
                         'Druga rodzina tylko wtedy, gdy ma KONTRASTOWAĆ — dwie podobne wyglądają jak pomyłka, nie jak zamysł.'],
        'brand'      => ['Kolor marki', 'color', '#41281A', [],
                         'Nagłówek strony głównej, stopka, ceny. Z niego wyliczają się jaśniejsze i ciemniejsze odcienie. Musi być ciemny — niesie jasny tekst.'],
        'accent'     => ['Kolor akcentu', 'color', '#C68A3C', [],
                         'Przyciski „Do koszyka” i „Zamawiam i płacę”, podkreślenia. Niesie biały napis — nie może być pastelowy.'],
        'bg'         => ['Tło strony', 'color', '#FBF6EF', [],
                         'Papier pod wszystkim. Musi być jasny — tekst zostaje ciemny.'],
        'radius'     => ['Kształt narożników', 'select', 'lagodne',
                         ['ostre' => 'Ostre', 'lagodne' => 'Łagodne (obecne)', 'okragle' => 'Okrągłe'],
                         'Karty, zdjęcia, pola i przyciski — jednym ruchem.'],
        'hero_align' => ['Nagłówek strony głównej', 'select', 'lewo',
                         ['lewo' => 'Tekst po lewej (obecnie)', 'srodek' => 'Tekst na środku'],
                         'Układ tytułu i hasła nad katalogiem.'],
        // « Pokaż / ukryj » les sections ? Dans Układ strony (layout.php),
        // avec l'ordre : une seule vérité par section, pas deux écrans.
    ];
}

function wsm_theme_defaults(): array {
    $d = [];
    foreach (wsm_theme_fields() as $k => $f) $d[$k] = $f[2];
    return $d;
}

/** Luminance relative (WCAG) d'une couleur #RRGGBB : 0 = noir, 1 = blanc. */
function wsm_theme_lum(string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return 0.0;
    $lin = function (int $c): float {
        $v = $c / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return 0.2126 * $lin((int) $r) + 0.7152 * $lin((int) $g) + 0.0722 * $lin((int) $b);
}

/**
 * Une valeur acceptable pour un réglage, ou null avec la raison dans $why.
 * La raison est en polonais : elle s'affiche telle quelle à l'écran.
 */
function wsm_theme_valide(string $key, $v, ?string &$why = null): ?string {
    $f = wsm_theme_fields()[$key] ?? null;
    if (!$f) { $why = 'nieznane ustawienie'; return null; }
    $v = trim((string) $v);
    if ($f[1] === 'select') {
        if (!isset($f[3][$v])) { $why = $f[0] . ': nieznana wartość'; return null; }
        return $v;
    }
    // color
    if (!preg_match('/^#[0-9a-f]{6}$/i', $v)) { $why = $f[0] . ': podaj kolor jako #RRGGBB'; return null; }
    $v = strtoupper($v);
    $lum = wsm_theme_lum($v);
    // Les seuils ne sont pas esthétiques, ils sont de LISIBILITÉ : ce que
    // chaque couleur porte comme texte décide de ce qu'elle a le droit d'être.
    if ($key === 'brand' && $lum > 0.25)  { $why = 'Kolor marki musi być ciemny — niesie jasny tekst w nagłówku i stopce'; return null; }
    if ($key === 'bg' && $lum < 0.6)      { $why = 'Tło musi być jasne — ciemne tło zrobiłoby tekst nieczytelnym'; return null; }
    if ($key === 'accent' && $lum > 0.45)  { $why = 'Kolor akcentu niesie biały napis na przyciskach — nie może być pastelowy'; return null; }
    return $v;
}

/**
 * Le thème EN VIGUEUR : les défauts, recouverts par ce que la console a
 * enregistré. Une valeur rangée en base qui ne passe plus la validation
 * retombe sur le défaut — on ne sert jamais une couleur qu'on refuserait.
 */
function wsm_theme_get(PDO $pdo, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $t = wsm_theme_defaults();
    try {
        $st = $pdo->prepare("SELECT cle, val FROM wsm_settings WHERE cle LIKE ?");
        $st->execute([WSM_THEME_PREFIX . '%']);
        foreach ($st->fetchAll() ?: [] as $r) {
            $k = substr((string) $r['cle'], strlen(WSM_THEME_PREFIX));
            if (!isset($t[$k])) continue;
            $v = wsm_theme_valide($k, (string) $r['val']);
            if ($v !== null) $t[$k] = $v;
        }
    } catch (Throwable $e) { /* table absente : la boutique garde son habit */ }
    return $cache = $t;
}

/**
 * Enregistre ce que le formulaire poste. Un champ absent du POST ne bouge
 * pas ; un champ refusé est rapporté dans $refus ET n'empêche pas les autres
 * — l'écran dit lequel et pourquoi.
 * Renvoie les clés dont la valeur a changé.
 */
function wsm_theme_save(PDO $pdo, array $post, string $actor = '', array &$refus = []): array {
    $refus = [];
    $avant = wsm_theme_get($pdo, true);
    $now = date('Y-m-d H:i:s');
    $up  = $pdo->prepare("UPDATE wsm_settings SET val = ?, secret = 0, updated_at = ?, updated_by = ? WHERE cle = ?");
    $ins = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)");
    $changed = [];
    foreach (array_keys(wsm_theme_fields()) as $k) {
        if (!array_key_exists($k, $post)) continue;
        $why = null;
        $v = wsm_theme_valide($k, $post[$k], $why);
        if ($v === null) { $refus[$k] = (string) $why; continue; }
        if ($v === $avant[$k]) continue;
        $up->execute([$v, $now, mb_substr($actor, 0, 120), WSM_THEME_PREFIX . $k]);
        if ($up->rowCount() === 0) $ins->execute([WSM_THEME_PREFIX . $k, $v, $now, mb_substr($actor, 0, 120)]);
        $changed[] = $k;
    }
    wsm_theme_get($pdo, true);
    return $changed;
}

/** Retour au design system : les lignes « theme.* » disparaissent. */
function wsm_theme_reset(PDO $pdo): int {
    $st = $pdo->prepare("DELETE FROM wsm_settings WHERE cle LIKE ?");
    $st->execute([WSM_THEME_PREFIX . '%']);
    wsm_theme_get($pdo, true);
    return $st->rowCount();
}

function wsm_theme_is_default(array $t): bool {
    foreach (wsm_theme_defaults() as $k => $d) {
        if (strcasecmp((string) ($t[$k] ?? $d), $d) !== 0) return false;
    }
    return true;
}

/**
 * La feuille qui habille la boutique : des JETONS redéfinis sur :root, et
 * deux règles de mise en page. Vide quand tout est à la BASE du design
 * system (Mulish, couleurs de colors.css) — la feuille du design system fait
 * alors foi. Au défaut de la boutique elle porte au moins la paire de
 * polices, puisque ce défaut n'est pas la base.
 *
 * Les nuances d'une couleur se calculent en CSS (color-mix), pas en PHP :
 * c'est le navigateur qui mélange, dans l'espace où il peint, et la formule
 * se lit dans l'inspecteur au lieu d'être enfouie ici.
 */
function wsm_theme_css(array $t): string {
    $d = wsm_theme_defaults();
    $fonts = wsm_theme_fonts();
    $mix = fn(string $c, string $with, int $pct): string => "color-mix(in srgb, $c $pct%, $with)";
    $vars = [];
    $rules = [];

    // Les polices se comparent à la BASE (ce que tokens.css déclare), pas au
    // défaut du thème : le défaut est Lora/Playfair et doit être imprimé.
    $baza = $fonts[WSM_THEME_FONT_BAZA];
    $body = $fonts[$t['font_body']] ?? $baza;
    $head = $t['font_head'] === 'same' ? $body : ($fonts[$t['font_head']] ?? $body);
    if ($body !== $baza) $vars['--font-sans'] = $body[1];
    if ($head !== $baza) $vars['--font-display'] = $head[1];

    if (strcasecmp($t['brand'], $d['brand']) !== 0) {
        $b = $t['brand'];
        $vars['--choco-700'] = $b;
        $vars['--choco-800'] = $mix($b, 'black', 74);
        $vars['--choco-900'] = $mix($b, 'black', 56);
        $vars['--choco-950'] = $mix($b, 'black', 44);
        $vars['--choco-600'] = $mix($b, 'white', 80);
        $vars['--choco-500'] = $mix($b, 'white', 62);
        $vars['--choco-400'] = $mix($b, 'white', 46);
        $vars['--choco-300'] = $mix($b, 'white', 30);
        $vars['--choco-200'] = $mix($b, 'white', 18);
        $vars['--choco-100'] = $mix($b, 'white', 9);
    }
    if (strcasecmp($t['accent'], $d['accent']) !== 0) {
        $a = $t['accent'];
        $vars['--caramel-500'] = $a;
        $vars['--caramel-600'] = $mix($a, 'black', 84);
        $vars['--caramel-400'] = $mix($a, 'white', 78);
    }
    if (strcasecmp($t['bg'], $d['bg']) !== 0) {
        $g = $t['bg'];
        $vars['--cream-50']  = $g;
        $vars['--cream-100'] = $mix($g, 'var(--choco-700)', 95);
        $vars['--cream-200'] = $mix($g, 'var(--choco-700)', 90);
        $vars['--cream-300'] = $mix($g, 'var(--choco-700)', 80);
        $vars['--cream-400'] = $mix($g, 'var(--choco-700)', 68);
    }
    if ($t['radius'] === 'ostre') {
        $vars += ['--radius-xs' => '2px', '--radius-sm' => '3px', '--radius-md' => '5px', '--radius-lg' => '8px',
                  '--radius-xl' => '10px', '--radius-2xl' => '14px', '--radius-pill' => '6px'];
    } elseif ($t['radius'] === 'okragle') {
        $vars += ['--radius-sm' => '12px', '--radius-md' => '18px', '--radius-lg' => '26px',
                  '--radius-xl' => '36px', '--radius-2xl' => '48px'];
    }
    if ($t['hero_align'] === 'srodek') {
        $rules[] = '.hero-in{text-align:center}.hero h1,.hero .lead{margin-left:auto;margin-right:auto}'
                 . '.hero-strip-in{justify-content:center}';
    }

    $css = '';
    if ($vars) {
        $css .= ':root{';
        foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';';
        $css .= '}';
    }
    return $css . implode('', $rules);
}

/**
 * Ce que la vitrine imprime dans <head>, après shop.css : toujours présent,
 * même vide, pour qu'un contrôle puisse constater que la boutique LIT le
 * thème — un <style> absent ne distingue pas « défaut » de « débranché ».
 */
function wsm_theme_head(?PDO $pdo = null): string {
    $t = wsm_theme_get($pdo ?? wsm_pdo());
    $css = wsm_theme_css($t);
    return '<style id="motyw">' . ($css !== '' ? $css : '/* wygląd domyślny */') . "</style>\n";
}
