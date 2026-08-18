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

/** The same list, in display order rather than keyed. */
function standards_list(PDO $pdo, string $projectType): array {
    if (!standards_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM quota_standards WHERE project_type = ?
          ORDER BY step_name, applies_to, applies_to_asset_type'
    );
    $stmt->execute([$projectType]);
    return $stmt->fetchAll();
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
                $verdict = sprintf('Planned %s%% FASTER than the standard — %s a day against %s.',
                    fmt_num((1 - $ratio) * 100, 0),
                    fmt_num($planned > 0 ? 1 / $planned : 0, 2),
                    fmt_num(1 / $ideal, 2));
                $state   = 'fast';
            } else {
                $verdict = sprintf('Planned %s%% slower than the standard, which is the safe direction.',
                    fmt_num(($ratio - 1) * 100, 0));
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
