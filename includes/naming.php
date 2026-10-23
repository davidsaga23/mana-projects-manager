<?php
// A show's file naming convention, and the check every new version must pass
// (migration 067; David, 2026-10-22).
//
// ⚠ A CONVENTION IS A TEMPLATE, NOT AN EXAMPLE. "FCM_SH040_CL_V00_AA" describes
// one file; "{PROJECT}_{SHOT}_{STEP}_V{VERSION}_{INITIALS}" describes every
// file on the show, and is the only form a machine can check a name against.
// The three documents David sent (MSD, FCM, STFC2) all reduce to two templates
// a show — one for shots, one for assets — plus the short codes they define for
// steps and asset types. Everything below is built from those.
//
// Placeholders:
//   {PROJECT}   the show's code                          MSD
//   {EPISODE}   the shot's episode, as EP + two digits    EP01   (shots)
//   {SHOT}      the shot code                             SH040  (shots)
//   {TYPE}      the asset type's code                     CH     (assets)
//   {ASSET}     the asset's name, spaces taken out        RealtaInt (assets)
//   {STEP}      the step's code                           FX
//   {VERSION}   the version number, digits                00, 01, 1 …
//   {INITIALS}  the artist's initials, 2 to 4 letters     DS
//
// Matching is case-insensitive and ignores the file extension.

require_once __DIR__ . '/functions.php';

/** Has migration 067 run? Until it has, nothing is checked. */
function naming_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'project_brief', 'naming_shots');
    return $ok;
}

/** The placeholders a template may use, with what each one means on screen. */
function naming_tokens(): array {
    return [
        'PROJECT'  => "the show's code",
        'EPISODE'  => 'the episode, written EP01',
        'SHOT'     => 'the shot code',
        'TYPE'     => "the asset type's code",
        'ASSET'    => "the asset's name, no spaces",
        'STEP'     => "the step's code",
        'VERSION'  => 'the version number',
        'INITIALS' => "the artist's initials",
    ];
}

/** One show's convention: both templates, the codes, and where versions start. */
function naming_settings(PDO $pdo, int $projectId): array {
    $out = ['shots' => '', 'assets' => '', 'codes' => ['steps' => [], 'types' => []], 'first' => 1];
    if (!naming_available($pdo)) return $out;
    $q = $pdo->prepare('SELECT naming_shots, naming_assets, naming_codes, naming_first_version
                          FROM project_brief WHERE project_id = ?');
    $q->execute([$projectId]);
    $r = $q->fetch();
    if (!$r) return $out;
    $codes = json_decode((string)($r['naming_codes'] ?? ''), true);
    return [
        'shots'  => trim((string)$r['naming_shots']),
        'assets' => trim((string)$r['naming_assets']),
        'codes'  => [
            'steps' => is_array($codes['steps'] ?? null) ? $codes['steps'] : [],
            'types' => is_array($codes['types'] ?? null) ? $codes['types'] : [],
        ],
        'first'  => (int)$r['naming_first_version'] === 0 ? 0 : 1,
    ];
}

/**
 * A code nobody has typed yet, guessed from the name the way the documents
 * build theirs: the first letters of two words ("File prep" → FP), or the first
 * two letters of one ("Animation" → AN). Only a starting point — the Brief shows
 * every guess and lets it be changed, and Comp, which the MSD document calls CP,
 * is exactly why.
 */
function naming_default_code(string $name): string {
    $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $name)));
    if (!$words) return '';
    if (count($words) >= 2) return strtoupper($words[0][0] . $words[1][0]);
    return strtoupper(substr($words[0], 0, 2));
}

function naming_step_code(array $settings, array $step): string {
    $c = trim((string)($settings['codes']['steps'][(string)$step['id']] ?? ''));
    return $c !== '' ? $c : naming_default_code((string)$step['name']);
}

function naming_type_code(array $settings, string $type): string {
    $c = trim((string)($settings['codes']['types'][$type] ?? ''));
    return $c !== '' ? $c : naming_default_code($type);
}

/** "David Salinas Garfias" → DS: first name and last name, as the documents ask. */
function naming_initials(string $name): string {
    $words = array_values(array_filter(preg_split('/\s+/', trim($name))));
    if (!$words) return 'AA';
    return strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
}

/** An episode as the STFC document writes it: "1" → EP01, "ep2" → EP02. */
function naming_episode(string $ep): string {
    $ep = trim($ep);
    if ($ep === '') return '';
    if (preg_match('/^(?:ep\s*)?(\d+)$/i', $ep, $m)) return 'EP' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    return strtoupper(preg_replace('/\s+/', '', $ep));
}

/** A name with its spaces and punctuation taken out: "Realta Int" → RealtaInt. */
function naming_compact(string $s): string {
    return preg_replace('/[^A-Za-z0-9]+/', '', $s);
}

/**
 * The rule for one task, or null when the show has none for that kind of item
 * (and for a whole-show deliverable, which the documents do not name).
 *
 * Returns ['template', 'regex' (no delimiters, for PHP and JavaScript alike),
 *          'first', 'values' => [token => value]].
 */
function naming_rule(PDO $pdo, int $projectId, int $stepId, string $itemKey): ?array {
    if (!naming_available($pdo)) return null;
    $kind = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    if ($kind !== 's' && $kind !== 'a') return null;

    $set = naming_settings($pdo, $projectId);
    $template = $kind === 's' ? $set['shots'] : $set['assets'];
    if ($template === '') return null;

    $p = $pdo->prepare('SELECT code FROM projects WHERE id = ?');
    $p->execute([$projectId]);
    $st = $pdo->prepare('SELECT id, name FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $st->execute([$stepId, $projectId]);
    $step = $st->fetch();
    if (!$step) return null;

    $values = ['PROJECT' => strtoupper((string)$p->fetchColumn()), 'STEP' => naming_step_code($set, $step)];
    // ⚠ EVERY PLACEHOLDER GETS A VALUE, empty where it means nothing for this
    // kind of item — {TYPE} and {ASSET} on a shot, {SHOT} and {EPISODE} on an
    // asset. Left unset, one was matched as the literal text "{TYPE}": the form
    // told artists to name a shot file MSD_SH010_{TYPE}_FX_V04_DS (David,
    // 2026-10-23). Empty, it is dropped below with its separator.
    if ($kind === 's') {
        $q = $pdo->prepare('SELECT episode, shot_code FROM shots WHERE id = ? AND project_id = ?');
        $q->execute([$itemId, $projectId]);
        $r = $q->fetch() ?: ['episode' => '', 'shot_code' => ''];
        $values['SHOT'] = (string)$r['shot_code'];
        $values['EPISODE'] = naming_episode((string)$r['episode']);
        $values['TYPE'] = '';
        $values['ASSET'] = '';
    } else {
        $q = $pdo->prepare("SELECT name, COALESCE(asset_type, '') AS asset_type FROM assets WHERE id = ? AND project_id = ?");
        $q->execute([$itemId, $projectId]);
        $r = $q->fetch() ?: ['name' => '', 'asset_type' => ''];
        $values['ASSET'] = naming_compact((string)$r['name']);
        $values['TYPE'] = naming_type_code($set, (string)$r['asset_type']);
        $values['SHOT'] = '';
        $values['EPISODE'] = '';
    }

    // A placeholder with nothing behind it (a shot with no episode on a show
    // whose template has one) goes, along with ONE separator beside it, so the
    // rule reads SH040_FX rather than demanding __SH040.
    foreach ($values as $tok => $v) {
        if ($v !== '') continue;
        $template = preg_replace(['/\{' . $tok . '\}[_\-.]?/', '/[_\-.]?\{' . $tok . '\}/'], '', $template, 1);
    }

    $re = '';
    foreach (preg_split('/(\{[A-Z]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
        if (preg_match('/^\{([A-Z]+)\}$/', $part, $m)) {
            $tok = $m[1];
            if ($tok === 'VERSION')      { $re .= '(\d+)'; continue; }
            if ($tok === 'INITIALS')     { $re .= '[A-Za-z]{2,4}'; continue; }
            if (isset($values[$tok]))    { $re .= preg_quote($values[$tok], '/'); continue; }
        }
        $re .= preg_quote($part, '/');
    }
    return ['template' => $template, 'regex' => '^' . $re . '$', 'first' => $set['first'], 'values' => $values];
}

/** What one file should be called: the rule filled in for a version and a person. */
function naming_example(array $rule, int $versionNo, string $initials): string {
    $num = str_pad((string)max(0, $versionNo - 1 + $rule['first']), 2, '0', STR_PAD_LEFT);
    $fill = $rule['values'] + ['VERSION' => $num, 'INITIALS' => $initials];
    return preg_replace_callback('/\{([A-Z]+)\}/', fn($m) => $fill[$m[1]] ?? $m[0], $rule['template']);
}

/**
 * The same name with the version left as the marker {V}.
 *
 * ⚠ FOR THE BROWSER, WHICH HAS TO BUILD ONE NAME PER FILE: each file picked
 * becomes its own version, so the second of two is V05 where the first is V04.
 * It cannot get there by replacing "04" in a finished example — SH040 carries
 * the same two digits, and MSD_SH040_FX_V04_DS would come back as
 * MSD_SH050_FX_V04_DS. A marker is the only safe seam.
 */
function naming_example_template(array $rule, string $initials): string {
    $fill = $rule['values'] + ['VERSION' => '{V}', 'INITIALS' => $initials];
    // preg_replace_callback does not re-scan what it substitutes, so the {V}
    // this puts in is not matched again.
    return preg_replace_callback('/\{([A-Z]+)\}/', fn($m) => $fill[$m[1]] ?? $m[0], $rule['template']);
}

/**
 * Check every file of one upload. $names in the order they will be numbered;
 * $nextVersion is what the first of them becomes in Anima (3 for v03).
 * Returns the sentences to show, one per file that fails; empty when all pass.
 */
function naming_check(array $rule, array $names, int $nextVersion, string $initials): array {
    $bad = [];
    foreach (array_values($names) as $i => $name) {
        $base = preg_replace('/\.[^.]*$/', '', (string)$name);
        $want = naming_example($rule, $nextVersion + $i, $initials);
        if (!preg_match('/' . $rule['regex'] . '/i', $base, $m)) {
            $bad[] = '"' . $name . '" does not follow this show\'s naming convention. It should be named like '
                   . $want . '.';
            continue;
        }
        $got = (int)$m[1];
        $expect = $nextVersion + $i - 1 + $rule['first'];
        if ($got !== $expect) {
            $bad[] = '"' . $name . '" is numbered ' . $got . ', but this upload is the next version, '
                   . str_pad((string)$expect, 2, '0', STR_PAD_LEFT) . ' (' . $want . ').';
        }
    }
    return $bad;
}
