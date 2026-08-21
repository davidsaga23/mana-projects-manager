<?php
// Reverse scheduling: from work + deadline + headcount, derive the quota.
//
// This is the number David considers the single biggest driver of a project
// landing on time, so the wizard shows it BEFORE a project is committed —
// an unrealistic plan should be visible at setup, not discovered in week three.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/standards.php';   // effective_days_per_unit()
require_once __DIR__ . '/db.php';        // project_setting(), for the early estimates

/**
 * The project's early estimate, used before a real breakdown exists.
 *
 * Before an animatic is cut and labelled nobody knows the shot count, but the
 * runtime is usually contracted — so seconds are the honest early number. These
 * are only ever a FALLBACK: the moment real shots or assets exist, they are
 * counted instead, and the UI says which was used.
 */
/**
 * Expected retakes, as a percentage of the base effort.
 *
 * Every projection Mana writes carries a retake allowance — the reference sheet
 * applies "+10% RTK" to each line. It is a real, predictable cost: work comes
 * back and is done again, so planning without it plans for a world where nothing
 * is ever revised.
 *
 * It does NOT change how much there is to make — 22 assets stay 22 assets. It
 * changes how long each one takes, so it multiplies days-per-item, never the
 * work units.
 */
function project_retake_pct(PDO $pdo, int $projectId): float {
    return max(0.0, (float)project_setting($pdo, $projectId, 'retake_pct', '0'));
}

/** The multiplier that allowance becomes: 10% -> 1.10. */
function retake_factor(float $retakePct): float {
    return 1 + ($retakePct / 100);
}

function project_estimates(PDO $pdo, int $projectId): array {
    return [
        'seconds' => (float)project_setting($pdo, $projectId, 'estimated_seconds', '0'),
        'shots'   => (float)project_setting($pdo, $projectId, 'estimated_shots', '0'),
        'assets'  => (float)project_setting($pdo, $projectId, 'estimated_assets', '0'),
    ];
}

/**
 * How much work a step covers.
 *
 * Steps tracked in seconds measure the shot footage passing through them;
 * everything else counts items. A step applying to 'both' counts shots and
 * assets together, because both really do pass through it.
 *
 * Falls back to the project estimate when nothing has been broken down yet, so
 * "how many people will this need?" can be answered during quoting rather than
 * only after the animatic. `estimated` says which source was used — an estimate
 * must never be mistaken for a count.
 *
 * Returns ['units' => float, 'label' => string, 'estimated' => bool].
 */
function step_work_units(PDO $pdo, int $projectId, array $step, ?array $estimates = null): array {
    $estimates = $estimates ?? project_estimates($pdo, $projectId);
    // How much of its scope this step actually touches — FX on a third of the
    // running time, concept skipped for character variations.
    $coverage = (float)($step['coverage_pct'] ?? 100) / 100;
    if ($coverage <= 0) $coverage = 0.0;

    // One deliverable for the whole show — an animatic, a music track, the final
    // mix. Never multiplied by the shot count, and coverage doesn't apply: half
    // an animatic isn't a thing.
    if (($step['applies_to'] ?? '') === 'project') {
        return ['units' => 1.0, 'units_raw' => 1.0, 'coverage' => 1.0,
                'label' => 'deliverable', 'estimated' => false];
    }

    $appliesToShots  = in_array($step['applies_to'], ['shot', 'both'], true);
    $appliesToAssets = in_array($step['applies_to'], ['asset', 'both'], true);
    $assetType = $step['applies_to_asset_type'] ?? null;

    if ($step['tracks_seconds'] && $appliesToShots) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(placeholder_seconds), 0) FROM shots WHERE project_id = ?'
        );
        $stmt->execute([$projectId]);
        $seconds = (float)$stmt->fetchColumn();
        if ($seconds > 0) {
            return ['units' => $seconds * $coverage, 'units_raw' => $seconds,
                    'coverage' => $coverage, 'label' => 'seconds', 'estimated' => false];
        }
        return ['units' => $estimates['seconds'] * $coverage, 'units_raw' => $estimates['seconds'],
                'coverage' => $coverage, 'label' => 'seconds', 'estimated' => $estimates['seconds'] > 0];
    }

    $units = 0.0; $estimated = 0.0;
    if ($appliesToShots) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM shots WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $units += (float)$stmt->fetchColumn();
        $estimated += $estimates['shots'];
    }
    if ($appliesToAssets) {
        // A step scoped to one kind of asset counts only those: rigging is
        // character work, and counting all 55 assets would plan for four times
        // the work that exists.
        if ($assetType !== null && $assetType !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE project_id = ? AND asset_type = ?');
            $stmt->execute([$projectId, $assetType]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE project_id = ?');
            $stmt->execute([$projectId]);
        }
        $units += (float)$stmt->fetchColumn();
        // The project-level estimate can't be split by type, so it only stands
        // in for a step covering every asset.
        if ($assetType === null || $assetType === '') $estimated += $estimates['assets'];
    }
    $label = $appliesToShots && $appliesToAssets ? 'items'
           : ($appliesToShots ? 'shots'
           : (($assetType !== null && $assetType !== '') ? $assetType . ' assets' : 'assets'));

    if ($units > 0) {
        return ['units' => $units * $coverage, 'units_raw' => $units,
                'coverage' => $coverage, 'label' => $label, 'estimated' => false];
    }
    return ['units' => $estimated * $coverage, 'units_raw' => $estimated,
            'coverage' => $coverage, 'label' => $label, 'estimated' => $estimated > 0];
}

/**
 * The quota maths for one step.
 *
 * team_per_day deliberately does NOT depend on headcount — the work needed to
 * hit a date is fixed; only each person's share of it moves. This confused
 * people on STFC, so the UI labels both numbers explicitly.
 *
 * required_headcount answers the question the team actually asks: "how many
 * people does this date need?" — derived from how long one item takes, never
 * assumed to be 1 (that assumption turned a 3-week schedule into 9 months).
 *
 * Returns a row of numbers plus 'problems' — anything that makes the plan
 * unworkable or unknowable, in plain language.
 */
function step_quota(PDO $pdo, int $projectId, array $step, ?string $today = null,
                    ?array $estimates = null, ?float $retakePct = null): array {
    $today = $today ?: date('Y-m-d');
    $work  = step_work_units($pdo, $projectId, $step, $estimates);
    $retakePct = $retakePct ?? project_retake_pct($pdo, $projectId);

    $out = [
        'step'              => $step,
        'units'             => $work['units'],
        'units_raw'         => $work['units_raw'] ?? $work['units'],
        'coverage'          => $work['coverage'] ?? 1.0,
        'unit_label'        => $work['label'],
        'estimated'         => $work['estimated'],
        'working_days'      => null,
        'team_per_day'      => null,
        'per_artist_day'    => null,
        'required_headcount'=> null,   // rounded up, for display
        'required_exact'    => null,   // unrounded, for the comparison below
        'retake_pct'        => $retakePct,
        'headcount'         => $step['planned_headcount'] !== null ? (float)$step['planned_headcount'] : null,
        'problems'          => [],
    ];

    if (empty($step['deadline'])) {
        $out['problems'][] = 'No deadline set, so no quota can be worked out.';
        return $out;
    }
    if ($work['units'] <= 0) {
        $out['problems'][] = 'No ' . $work['label'] . ' added yet, and no estimate set, ' .
                             'so there is nothing to work from.';
        return $out;
    }

    // Count from the step's own start when it is in the future, otherwise from
    // today — work can't be done before a step opens, and can't be done in the past.
    $from = !empty($step['start_date']) && $step['start_date'] > $today ? $step['start_date'] : $today;
    if ($step['deadline'] < $from) {
        $out['problems'][] = 'The deadline (' . $step['deadline'] . ') has already passed.';
        return $out;
    }

    $days = working_days_between($from, $step['deadline']);
    $out['working_days'] = $days;
    if ($days <= 0) {
        $out['problems'][] = 'No working days left before the deadline.';
        return $out;
    }

    $out['team_per_day'] = $work['units'] / $days;

    // Retakes make each item take longer, so they scale days-per-item — not the
    // amount of work, which is fixed by the breakdown.
    // The studio ideal where there is one; the typed estimate otherwise. One
    // reader for the whole codebase — see effective_days_per_unit().
    $daysPerUnit = effective_days_per_unit($pdo, $projectId, $step) * retake_factor($retakePct);
    $out['required_exact']     = $out['team_per_day'] * $daysPerUnit;
    $out['required_headcount'] = (int)ceil($out['required_exact']);

    if ($out['headcount'] !== null && $out['headcount'] > 0) {
        $out['per_artist_day'] = $out['team_per_day'] / $out['headcount'];
        // Compare against the unrounded requirement: 2.2 people needed against 2.5
        // planned is fine, and rounding first would raise a warning that isn't real.
        // The epsilon keeps floating-point noise from tripping an exact match.
        if ($out['required_exact'] > $out['headcount'] + 0.0001) {
            $out['problems'][] = sprintf(
                'Needs about %s people to hit this date; %s planned.',
                fmt_num($out['required_exact'], 1),
                fmt_num($out['headcount'])
            );
        }
    } else {
        $out['problems'][] = 'No headcount set, so the per-artist share is unknown.';
    }

    return $out;
}

/** Quotas for every step in a project, in pipeline order. */
function project_quotas(PDO $pdo, int $projectId, array $steps): array {
    // Read once, not per step.
    $estimates = project_estimates($pdo, $projectId);
    $retakePct = project_retake_pct($pdo, $projectId);
    $rows = [];
    foreach ($steps as $step) {
        $rows[] = step_quota($pdo, $projectId, $step, null, $estimates, $retakePct);
    }
    return $rows;
}

/** Format a rate for display without pretending to precision we don't have. */
function fmt_rate(?float $v): string {
    if ($v === null) return '—';
    if ($v >= 10) return number_format($v, 0);
    if ($v >= 1)  return number_format($v, 1);
    return number_format($v, 2);
}
