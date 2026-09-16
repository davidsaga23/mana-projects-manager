<?php
/**
 * Ideal quotas — the studio's base sheet, one per kind of project.
 *
 * THE THREE NUMBERS, because this file only makes sense against the other two
 * and mistaking them for each other makes all three useless:
 *
 *   REQUIRED   what the deadline demands. step_quota() derives it from work
 *              remaining over working days left. Nobody types it; it moves when
 *              the date or the progress moves.
 *   ESTIMATED  what THIS project was planned at — pipeline_steps.days_per_unit.
 *   IDEAL      what the studio believes the work takes when done properly, for
 *              this KIND of project. Typed here, true across shows.
 *
 * The ideal is the only one that can contradict the other two, and that is its
 * whole value. A required pace far above the ideal is not an ambitious plan, it
 * is an impossible one, and today that is discoverable only in week three.
 *
 * Stored as DAYS PER UNIT rather than units per day, because everything it feeds
 * is already in those terms. Both are shown, because "half a day each" and "two
 * a day" are the same fact and people say whichever suits the step.
 */

require_once __DIR__ . '/functions.php';

/** Has migration 026 been applied? */
function standards_available(PDO $pdo): bool {
    return column_exists($pdo, 'quota_standards', 'days_per_unit');
}

/** The project types a base sheet can exist for — projects.type, plus any already used. */
function standard_project_types(PDO $pdo): array {
    $types = ['2D', '3D', 'Mixed', 'Other'];
    try {
        $used = $pdo->query('SELECT DISTINCT project_type FROM quota_standards')
                    ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $used = [];                       // migration 026 pending
    }
    return array_values(array_unique(array_merge($types, array_filter($used))));
}

/**
 * The identity of a step, as a single string key.
 *
 * The triple migration 009 made unique, and the one ROADMAP names as the
 * cross-project comparison key. Lowercased so that a step typed "concept" on one
 * show matches "Concept" on another — the same normalisation the alias layer and
 * the asset-type merge apply, and for the same reason.
 */
function standard_key(string $name, string $appliesTo, ?string $assetType): string {
    return mb_strtolower(trim($name)) . '|' . $appliesTo . '|' . mb_strtolower(trim((string)$assetType));
}

/** Every standard for one kind of project, keyed by standard_key(). */
function standards_for_type(PDO $pdo, string $projectType): array {
    if (!standards_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM quota_standards WHERE project_type = ?
          ORDER BY step_name, applies_to, applies_to_asset_type'
    );
    $stmt->execute([$projectType]);
    $out = [];
    foreach ($stmt as $r) {
        $out[standard_key($r['step_name'], $r['applies_to'], $r['applies_to_asset_type'])] = $r;
    }
    return $out;
}

/**
 * The same list, IN PIPELINE ORDER — the order a show is actually made in.
 *
 * Alphabetical put Animatic next to CH sheet next to Cleanup, which is three
 * different phases of production filed by first letter. This sheet is read
 * beside the Dates & headcount screen, and the two disagreeing about the order
 * of the same steps makes comparing them a hunting exercise (David, 2026-08-17).
 *
 * ⚠ THE ORDER HAS TO BE BORROWED, because this table has none of its own. It is
 * studio-wide — one row per step identity per project TYPE — while step_order
 * lives on a project's own pipeline. So each standard takes the EARLIEST position
 * that identity holds on any project of that type, which is what "where this step
 * comes in a show of this kind" means when there are several shows.
 *
 * Identities no project currently uses have no position to borrow. They sort last,
 * alphabetically, rather than being dropped or guessed at — a standard written for
 * a step nobody runs yet is still a standard.
 */
function standards_list(PDO $pdo, string $projectType): array {
    if (!standards_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT qs.*,
                (SELECT ps.color
                   FROM pipeline_steps ps
                   JOIN projects p ON p.id = ps.project_id
                  WHERE p.type = qs.project_type
                    AND ps.name = qs.step_name
                    AND ps.applies_to = qs.applies_to
                    AND COALESCE(ps.applies_to_asset_type, \'\') = qs.applies_to_asset_type
                  ORDER BY ps.step_order LIMIT 1
                ) AS color,
                (SELECT MIN(ps.step_order)
                   FROM pipeline_steps ps
                   JOIN projects p ON p.id = ps.project_id
                  WHERE p.type = qs.project_type
                    AND ps.name = qs.step_name
                    AND ps.applies_to = qs.applies_to
                    AND COALESCE(ps.applies_to_asset_type, \'\') = qs.applies_to_asset_type
                ) AS pipeline_order
           FROM quota_standards qs
          WHERE qs.project_type = ?
          ORDER BY pipeline_order IS NULL, pipeline_order,
                   qs.step_name, qs.applies_to, qs.applies_to_asset_type'
    );
    $stmt->execute([$projectType]);
    return $stmt->fetchAll();
}

/* ---------------------------------------------------------------------------
 * THE ONE NUMBER THE PLAN RUNS ON
 *
 * David, 2026-08-17: *"Let's plan with the ideal quotas."* So the studio standard
 * is not a comparison any more — it IS days-per-item, everywhere. The value typed
 * on Dates & headcount is the fallback for steps the base sheet has no answer for.
 *
 * ⚠ EVERY CALCULATION GOES THROUGH effective_days_per_unit(). Before this there
 * were six separate readers of `pipeline_steps.days_per_unit` — the quota, the
 * scheduler, the budget, the time-log comparison, the CSV export and the estimate
 * baseline — and the budget had already been switched to the ideal on its own.
 * That is how the Dates & headcount screen and the expense projection came to
 * disagree about the same step. One reader, one answer.
 * ------------------------------------------------------------------------- */

/**
 * standards_for_type() memoised per request.
 *
 * The helper below is called once per step inside loops that already run per
 * step, so the uncached version turned one query into thirty. Static rather than
 * a cache table: it is only ever right for the length of one request anyway.
 */
function standards_cached(PDO $pdo, string $projectType): array {
    static $byType = [];
    if (!array_key_exists($projectType, $byType)) {
        $byType[$projectType] = standards_for_type($pdo, $projectType);
    }
    return $byType[$projectType];
}

/** A project's type, memoised — the helper needs it and callers have an id. */
function project_type_of(PDO $pdo, int $projectId): string {
    static $types = [];
    if (!array_key_exists($projectId, $types)) {
        $stmt = $pdo->prepare('SELECT type FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $types[$projectId] = (string)($stmt->fetchColumn() ?: '');
    }
    return $types[$projectId];
}

/** The standard governing one step, or null. */
function step_standard(PDO $pdo, int $projectId, array $step): ?array {
    if (!standards_available($pdo)) return null;
    $sheet = standards_cached($pdo, project_type_of($pdo, $projectId));
    if (!$sheet) return null;
    return $sheet[standard_key($step['name'] ?? '', $step['applies_to'] ?? 'shot',
                               $step['applies_to_asset_type'] ?? '')] ?? null;
}

/**
 * Days per item for planning: the studio ideal, or the typed estimate when the
 * base sheet has nothing to say about this step.
 *
 * ⚠ NOT retake-adjusted. Retakes are applied by the caller, because not every
 * caller applies them the same way — a per-second COST must not be inflated by
 * them (the seconds delivered do not change) while the DAYS must be. Folding the
 * retake in here would make that distinction impossible to express.
 */
function effective_days_per_unit(PDO $pdo, int $projectId, array $step): float {
    $std = step_standard($pdo, $projectId, $step);
    if ($std !== null && (float)$std['days_per_unit'] > 0) return (float)$std['days_per_unit'];
    return (float)($step['days_per_unit'] ?: 1.0);
}

/** 'ideal' or 'estimate' — which of the two the figure above came from. */
function effective_days_source(PDO $pdo, int $projectId, array $step): string {
    $std = step_standard($pdo, $projectId, $step);
    return ($std !== null && (float)$std['days_per_unit'] > 0) ? 'ideal' : 'estimate';
}

/**
 * The ideal for one step of one project, or null.
 *
 * Falls back from the project's own type to nothing — deliberately NOT to
 * another type. A 2D short's Concept standard is not evidence about a 3D
 * series, and quietly borrowing one would put a number on screen that nobody
 * agreed to. ROADMAP is explicit that comparing across types "would average
 * away the signal".
 */
function standard_for_step(PDO $pdo, string $projectType, array $step): ?array {
    $all = standards_for_type($pdo, $projectType);
    $key = standard_key($step['name'], $step['applies_to'] ?? 'shot',
                        $step['applies_to_asset_type'] ?? '');
    return $all[$key] ?? null;
}

/**
 * Save a base sheet, from the editing grid. Returns what it did.
 *
 * A blank days-per-unit REMOVES that standard rather than storing a zero: zero
 * days per item is not a slow standard, it is a step that takes no time, and the
 * planner would divide by it.
 *
 * @param array $rows  each ['step_name','applies_to','applies_to_asset_type',
 *                           'days_per_unit','typical_headcount','source','notes']
 * @return array ['saved' => int, 'removed' => int, 'errors' => string[]]
 */
function save_standards(PDO $pdo, string $projectType, array $rows, string $who = ''): array {
    if (!standards_available($pdo)) {
        throw new RuntimeException(
            'The database updates for ideal quotas have not been applied yet (migration 026).'
        );
    }
    $out = ['saved' => 0, 'removed' => 0, 'errors' => []];

    $ins = $pdo->prepare(
        'INSERT INTO quota_standards
           (project_type, step_name, applies_to, applies_to_asset_type,
            days_per_unit, typical_headcount, source, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            days_per_unit = VALUES(days_per_unit),
            typical_headcount = VALUES(typical_headcount),
            source = VALUES(source), notes = VALUES(notes),
            updated_by = VALUES(updated_by)'
    );
    $del = $pdo->prepare(
        'DELETE FROM quota_standards
          WHERE project_type = ? AND step_name = ? AND applies_to = ? AND applies_to_asset_type = ?'
    );
    $log = column_exists($pdo, 'quota_standards_log', 'days_per_unit')
        ? $pdo->prepare(
            'INSERT INTO quota_standards_log
               (project_type, step_name, applies_to, applies_to_asset_type,
                days_per_unit, typical_headcount, source, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
          )
        : null;

    foreach ($rows as $i => $row) {
        $name = trim((string)($row['step_name'] ?? ''));
        if ($name === '') continue;                       // an unused grid row

        $appliesTo = in_array($row['applies_to'] ?? '', ['shot', 'asset', 'both', 'project'], true)
                   ? $row['applies_to'] : 'shot';
        $assetType = trim((string)($row['applies_to_asset_type'] ?? ''));

        $rawDays = trim((string)($row['days_per_unit'] ?? ''));
        if ($rawDays === '') {
            // Cleared on purpose. Removed rather than zeroed — see the docblock.
            $del->execute([$projectType, $name, $appliesTo, $assetType]);
            if ($del->rowCount() > 0) {
                $out['removed']++;
                if ($log) $log->execute([$projectType, $name, $appliesTo, $assetType,
                                         null, null, 'cleared', $who]);
            }
            continue;
        }
        if (!is_numeric($rawDays) || (float)$rawDays <= 0) {
            $out['errors'][] = sprintf('"%s": %s is not a number of days above zero.', $name, $rawDays);
            continue;
        }
        $days = round((float)$rawDays, 3);

        $rawHead = trim((string)($row['typical_headcount'] ?? ''));
        $head = ($rawHead !== '' && is_numeric($rawHead) && (float)$rawHead > 0)
              ? round((float)$rawHead, 2) : null;

        $ins->execute([
            $projectType, $name, $appliesTo, $assetType, $days, $head,
            trim((string)($row['source'] ?? '')) ?: null,
            trim((string)($row['notes'] ?? '')) ?: null,
            $who,
        ]);
        $out['saved']++;
        if ($log) $log->execute([$projectType, $name, $appliesTo, $assetType, $days, $head,
                                 trim((string)($row['source'] ?? '')) ?: null, $who]);
    }
    return $out;
}

/**
 * Change the days-per-item of ONE existing studio ideal, and nothing else.
 *
 * David, 2026-09-16: Dates & headcount showed an editable "days per item" that
 * the plan ignored whenever a studio ideal existed. "It makes no sense to have
 * an editable field that's not used." The field now IS the ideal, and this is
 * what saving it calls.
 *
 * ⚠ A MERGE, NOT A PLAIN save_standards() CALL. save_standards() writes every
 * column of a standard - typical headcount, source and notes as well as the
 * days - so handing it a row with only the days would wipe those three on the
 * studio sheet for every project of the type. The existing row is read first
 * and only the days are replaced. It still goes THROUGH save_standards(), so
 * there is one writer and the change lands in quota_standards_log like any
 * other.
 *
 * ⚠ NEVER REMOVES A STANDARD. A blank on the Ideal quotas sheet deletes the
 * standard, which is right on the screen whose job that is. From a single
 * project's planning table it would delete a studio-wide number as a side effect
 * of clearing a box, so a blank is refused here.
 *
 * Returns the old value, or null when nothing changed. Throws, writing nothing,
 * when the value is not a number of days above zero.
 */
function update_standard_days(PDO $pdo, string $projectType, array $step, string $raw,
                              string $who = ''): ?float {
    $key = standard_key($step['name'] ?? '', $step['applies_to'] ?? 'shot',
                        $step['applies_to_asset_type'] ?? '');
    $sheet = standards_for_type($pdo, $projectType);
    $row = $sheet[$key] ?? null;
    if ($row === null) {
        throw new RuntimeException(sprintf('"%s" has no studio ideal to change.', $step['name'] ?? ''));
    }

    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException(sprintf(
            '%s: a studio ideal cannot be removed from a project screen, because it applies to every '
          . '%s project. It was left as it was. Remove it on the Ideal quotas sheet if that is meant.',
            $step['name'] ?? '', $projectType));
    }
    if (!is_numeric($raw) || (float)$raw <= 0) {
        throw new RuntimeException(sprintf('%s: "%s" is not a number of days above zero, so the '
                                         . 'studio ideal was left as it was.', $step['name'] ?? '', $raw));
    }

    $old = (float)$row['days_per_unit'];
    $new = round((float)$raw, 3);
    // ⚠ Within a thousandth is NOT a change. The column holds three decimals,
    // and a page that re-posts a displayed value must never rewrite a studio
    // number just by being saved.
    if (abs($new - $old) < 0.0005) return null;

    $r = save_standards($pdo, $projectType, [[
        'step_name'             => $row['step_name'] ?? ($step['name'] ?? ''),
        'applies_to'            => $row['applies_to'] ?? ($step['applies_to'] ?? 'shot'),
        'applies_to_asset_type' => $row['applies_to_asset_type'] ?? ($step['applies_to_asset_type'] ?? ''),
        'days_per_unit'         => (string)$new,
        // Carried through unchanged - see the docblock.
        'typical_headcount'     => $row['typical_headcount'] ?? '',
        'source'                => $row['source'] ?? '',
        'notes'                 => $row['notes'] ?? '',
    ]], $who);
    if (!empty($r['errors'])) throw new RuntimeException(implode(' ', $r['errors']));
    return $old;
}

/**
 * Compare a project's steps against the base sheet for its type.
 *
 * Returns one row per step with the ideal, this project's estimate, and the
 * difference — plus a `verdict` that is the sentence somebody actually needs.
 *
 * NOTHING IS CHANGED BY THIS. The comparison reports; a person decides. That is
 * the same rule the near-duplicate asset types follow (2026-08-13): detection
 * only, never a silent merge, because guessing quietly is the habit the alias
 * layer exists to avoid.
 */
function standards_drift(PDO $pdo, array $project): array {
    $out = [];
    if (!standards_available($pdo)) return $out;

    $standards = standards_for_type($pdo, (string)$project['type']);
    if (!$standards) return $out;

    foreach (pipeline_steps($pdo, (int)$project['id']) as $step) {
        $key = standard_key($step['name'], $step['applies_to'] ?? 'shot',
                            $step['applies_to_asset_type'] ?? '');
        $std = $standards[$key] ?? null;

        $planned = (float)($step['days_per_unit'] ?: 0);
        $ideal   = $std ? (float)$std['days_per_unit'] : null;

        $verdict = 'No standard recorded for this step yet.';
        $state   = 'none';
        if ($ideal !== null && $planned > 0) {
            $ratio = $planned / $ideal;
            if (abs($ratio - 1) < 0.05) {
                $verdict = 'Planned at the studio standard.';
                $state   = 'ok';
            } elseif ($ratio < 1) {
                // Under the money, whatever else it is. Deliverability is a
                // separate question and a separate warning.
                $verdict = sprintf('Planned %s%% faster than the standard, %s a day against %s.',
                    fmt_num((1 - $ratio) * 100, 0),
                    fmt_num($planned > 0 ? 1 / $planned : 0, 2),
                    fmt_num(1 / $ideal, 2));
                $state   = 'fast';
            } else {
                // The budget is built on the standard, so slower than it is
                // spending days nobody costed — not "the safe direction", which
                // is what this said before David corrected it (2026-08-17).
                $verdict = sprintf('Planned %s%% SLOWER than the standard, beyond the days the '
                                 . 'budget was built on.', fmt_num(($ratio - 1) * 100, 0));
                $state   = 'slow';
            }
        }

        $out[] = [
            'step'    => $step,
            'ideal'   => $ideal,
            'planned' => $planned ?: null,
            'standard' => $std,
            'verdict' => $verdict,
            'state'   => $state,
        ];
    }
    return $out;
}

/** "0.50 days each · 2.00 a day" — the same fact both ways round. */
function fmt_standard(float $daysPerUnit): string {
    if ($daysPerUnit <= 0) return '—';
    return fmt_num($daysPerUnit, 2) . ' days each · ' . fmt_num(1 / $daysPerUnit, 2) . ' a day';
}
