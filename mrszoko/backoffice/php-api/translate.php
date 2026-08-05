<?php
// ============================================================================
//  translate.php — remplir les traductions manquantes avec Claude.
//
//  CE QUE CE FICHIER NE FAIT PAS. Il ne traduit jamais par-dessus un texte
//  existant. Une traduction relue par un humain vaut mieux qu'une traduction
//  automatique fraîche, toujours, et l'écraser serait détruire du travail
//  sans le dire. On ne remplit que le VIDE.
//
//  QUATRE PRÉCAUTIONS QUI COMPTENT PLUS QUE LA QUALITÉ DU MODÈLE :
//
//   1. SANS CLÉ, RIEN. Pas de demi-fonctionnement, pas de repli sur un
//      service tiers gratuit : la fonction se déclare indisponible et l'écran
//      n'affiche pas le bouton. « xxxx » ne compte pas comme une clé.
//   2. LE RÉSULTAT EST MARQUÉ `auto`. Une machine se trompe avec aplomb.
//      Tant qu'une personne n'a pas relu, l'écran doit pouvoir le dire —
//      sinon la seule information qui permet de relire est perdue.
//   3. LES PLACEHOLDERS SONT SACRÉS. « {qty} szt. » traduit en « {ilość} pcs »
//      casse le rendu : la variable ne sera pas remplacée et le client lira
//      « {ilość} ». On vérifie donc que chaque marqueur du texte source se
//      retrouve à l'identique, et on REFUSE la traduction sinon.
//   4. ON TRADUIT PAR LOTS, AVEC LE CONTEXTE. Une clé isolée (« Zamów »)
//      n'a pas de sens traduisible : bouton ? titre ? Le nom de la clé
//      (« checkout.submit ») et le lot voisin donnent le registre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

/** Le modèle : rapide et bon marché pour de la chaîne d'interface courte. */
const WSM_TR_MODEL = 'claude-sonnet-5';

/** Combien de clés par appel. Assez pour donner du contexte, pas trop pour
 *  qu'un refus fasse tout recommencer. */
const WSM_TR_BATCH = 40;

/**
 * La clé d'API, lue dans la configuration du serveur uniquement.
 * Ce dépôt est public : elle ne peut vivre que dans config.local.php.
 */
function wsm_tr_key(): string {
    $k = trim((string) (wsm_config()['anthropic_api_key'] ?? ''));
    // « xxxx » est la marque de « pas encore renseigné » dans tout le projet.
    return ($k === '' || $k === 'xxxx') ? '' : $k;
}

/** La traduction automatique est-elle utilisable ? */
function wsm_tr_enabled(): bool { return wsm_tr_key() !== ''; }

/**
 * Les marqueurs d'un texte : {qty}, {total}, %s, %1$s.
 * Ils doivent traverser la traduction sans une égratignure.
 */
function wsm_tr_placeholders(string $s): array {
    $out = [];
    if (preg_match_all('/\{[a-z0-9_]+\}|%[0-9]*\$?[sd]/i', $s, $m)) $out = $m[0];
    sort($out);
    return $out;
}

/**
 * La traduction respecte-t-elle les marqueurs de la source ?
 *
 * Comparaison ensembliste : l'ordre des mots change d'une langue à l'autre,
 * donc l'ordre des marqueurs aussi. Ce qui ne doit pas changer, c'est
 * lesquels sont présents et combien de fois.
 */
function wsm_tr_placeholders_ok(string $src, string $out): bool {
    return wsm_tr_placeholders($src) === wsm_tr_placeholders($out);
}

/**
 * Appelle Claude sur un lot de chaînes.
 *
 * @param array  $lot  [clé => texte polonais]
 * @return array [traductions [clé => texte], erreur|null]
 */
function wsm_tr_call(array $lot, string $cible): array {
    $key = wsm_tr_key();
    if ($key === '') return [[], 'brak klucza API'];
    $nom = WSM_LANGS[$cible][2] ?? $cible;

    $sys = "Tu traduis l'interface d'une boutique en ligne de chocolat artisanal "
         . "polonaise (Mister Szoko) du polonais vers le $nom.\n\n"
         . "RÈGLES :\n"
         . "- Registre : commerçant soigné, chaleureux, jamais familier.\n"
         . "- Ce sont des chaînes d'INTERFACE : garde-les aussi courtes que la source. "
         . "Un bouton qui double de longueur casse la mise en page.\n"
         . "- La clé indique le contexte (checkout.submit = un bouton, meta.title = un titre de page).\n"
         . "- Les marqueurs comme {qty}, {total}, %s doivent être RECOPIÉS À L'IDENTIQUE, "
         . "sans les traduire ni les modifier.\n"
         . "- « Mister Szoko » ne se traduit jamais.\n"
         . "- Ne traduis pas les unités techniques (zł, kg, g).\n\n"
         . "Réponds UNIQUEMENT par un objet JSON {clé: traduction}, sans texte autour.";

    $payload = json_encode([
        'model' => WSM_TR_MODEL,
        'max_tokens' => 8000,
        'system' => $sys,
        'messages' => [['role' => 'user', 'content' => json_encode($lot, JSON_UNESCAPED_UNICODE)]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errc = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [[], 'sieć: ' . $errc];
    if ($code !== 200) {
        // On ne renvoie JAMAIS le corps brut à l'écran : une réponse d'erreur
        // d'API peut contenir des fragments de la requête, donc du contenu.
        return [[], 'API odpowiedziało ' . $code];
    }

    $d = json_decode((string) $body, true);
    $txt = '';
    foreach ($d['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $txt .= (string) $b['text'];
    }
    // Le modèle encadre parfois le JSON d'une clôture markdown malgré la
    // consigne : on récupère l'objet plutôt que d'échouer sur un détail.
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $out = json_decode($txt, true);
    if (!is_array($out)) return [[], 'odpowiedź nie jest JSON-em'];

    $prop = [];
    foreach ($out as $k => $v) {
        if (!is_string($v) || !isset($lot[$k])) continue;
        $prop[(string) $k] = trim($v);
    }
    return [$prop, null];
}

/**
 * Remplit les traductions manquantes d'une langue, sur une table.
 *
 * Ne touche que le vide (règle en tête de fichier). Chaque écriture est
 * marquée `auto` et journalisée, pour qu'on sache quoi relire.
 *
 * @param ?int $max  plafond de clés traitées (null = tout)
 * @return array ['written','skipped','placeholder_rejected','errors'=>string[]]
 */
function wsm_tr_fill(PDO $pdo, string $table, string $cible, string $actor, ?int $max = null): array {
    $r = ['written' => 0, 'skipped' => 0, 'placeholder_rejected' => 0, 'errors' => []];
    if (!wsm_tr_enabled())          { $r['errors'][] = 'tłumaczenie automatyczne nie jest skonfigurowane'; return $r; }
    if (!isset(WSM_LANG_TABLES[$table])) { $r['errors'][] = 'nieznana tabela'; return $r; }
    if ($cible === WSM_LANG_BASE)   { $r['errors'][] = 'polski jest źródłem — nie tłumaczy się go na siebie'; return $r; }
    if (!isset(WSM_LANGS[$cible]))  { $r['errors'][] = 'nieznany język'; return $r; }

    wsm_i18n_ensure_origin($pdo);
    $cov = wsm_lang_coverage($pdo, $table, $cible);
    $manquantes = $cov['missing'];
    if ($max !== null) $manquantes = array_slice($manquantes, 0, max(0, $max));
    if (!$manquantes) return $r;

    // Les textes polonais des clés à traduire.
    $src = [];
    $st = $pdo->prepare("SELECT k, v FROM $table WHERE lang = ?");
    $st->execute([WSM_LANG_BASE]);
    foreach ($st->fetchAll() ?: [] as $row) $src[(string) $row['k']] = (string) $row['v'];

    foreach (array_chunk($manquantes, WSM_TR_BATCH) as $lotClefs) {
        $lot = [];
        foreach ($lotClefs as $k) if (isset($src[$k]) && trim($src[$k]) !== '') $lot[$k] = $src[$k];
        if (!$lot) continue;

        [$prop, $err] = wsm_tr_call($lot, $cible);
        if ($err !== null) { $r['errors'][] = $err; break; }   // inutile d'insister lot après lot

        foreach ($prop as $k => $v) {
            if ($v === '') { $r['skipped']++; continue; }
            if (!wsm_tr_placeholders_ok($lot[$k], $v)) {
                // Un marqueur perdu affiche « {qty} » au client. On préfère
                // une case vide, qui retombe sur le polonais et se voit.
                $r['placeholder_rejected']++;
                continue;
            }
            // Rien n'a pu être écrit entre-temps ? On revérifie : deux
            // remplissages lancés en même temps ne doivent pas se marcher
            // dessus et retomber en `auto` sur du texte relu.
            if (trim(wsm_i18n_get($pdo, $table, $cible, $k)) !== '') { $r['skipped']++; continue; }

            wsm_i18n_put($pdo, $table, $cible, $k, $v, 'auto');
            wsm_i18n_log($pdo, $table, $cible, $k, '', $v, $actor, 'auto');
            $r['written']++;
        }
    }
    return $r;
}

/** Combien de textes attendent encore une relecture humaine. */
function wsm_tr_pending(PDO $pdo, string $lang): int {
    $n = 0;
    foreach (array_keys(WSM_LANG_TABLES) as $t) {
        if (!wsm_table_exists($pdo, $t)) continue;
        if (!in_array('origin', wsm_table_columns($pdo, $t), true)) continue;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE lang = ? AND origin = 'auto'");
            $st->execute([$lang]);
            $n += (int) $st->fetchColumn();
        } catch (Throwable $e) { /* rien */ }
    }
    return $n;
}

/** Marque un texte comme relu par un humain — il cesse d'être « à vérifier ». */
function wsm_tr_approve(PDO $pdo, string $table, string $lang, string $key): bool {
    if (!isset(WSM_LANG_TABLES[$table])) return false;
    if (!in_array('origin', wsm_table_columns($pdo, $table), true)) return false;
    try {
        $pdo->prepare("UPDATE $table SET origin = 'human' WHERE lang = ? AND k = ?")
            ->execute([$lang, $key]);
        return true;
    } catch (Throwable $e) { return false; }
}
