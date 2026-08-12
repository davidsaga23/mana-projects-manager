<?php
// Reverse scheduling: from work + deadline + headcount, derive the quota.
//
// This is the number David considers the single biggest driver of a project
// landing on time, so the wizard shows it BEFORE a project is committed —
// an unrealistic plan should be visible at setup, not discovered in week three.

require_once __DIR__ . '/functions.php';

/**
 * How much work a step covers.
 *
 * Steps tracked in seconds measure the shot footage passing through them;
 * everything else counts items. A step applying to 'both' counts shots and
 * assets together, because both really do pass through it.
 *
 * Returns ['units' => float, 'label' => string].
 */
function step_work_units(PDO $pdo, int $projectId, array $step): array {
    $appliesToShots  = in_array($step['applies_to'], ['shot', 'both'], true);
    $appliesToAssets = in_array($step['applies_to'], ['asset', 'both'], true);

    if ($step['tracks_seconds'] && $appliesToShots) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(placeholder_seconds), 0) FROM shots WHERE project_id = ?'
        );
        $stmt->execute([$projectId]);
        return ['units' => (float)$stmt->fetchColumn(), 'label' => 'seconds'];
    }

    $units = 0.0;
    if ($appliesToShots) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM shots WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $units += (float)$stmt->fetchColumn();
    }
    if ($appliesToAssets) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $units += (float)$stmt->fetchColumn();
    }
    $label = $appliesToShots && $appliesToAssets ? 'items'
           : ($appliesToShots ? 'shots' : 'assets');
    return ['units' => $units, 'label' => $label];
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
function step_quota(PDO $pdo, int $projectId, array $step, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $work  = step_work_units($pdo, $projectId, $step);

    $out = [
        'step'              => $step,
        'units'             => $work['units'],
        'unit_label'        => $work['label'],
        'working_days'      => null,
        'team_per_day'      => null,
        'per_artist_day'    => null,
        'required_headcount'=> null,   // rounded up, for display
        'required_exact'    => null,   // unrounded, for the comparison below
        'headcount'         => $step['planned_headcount'] !== null ? (float)$step['planned_headcount'] : null,
        'problems'          => [],
    ];

    if (empty($step['deadline'])) {
        $out['problems'][] = 'No deadline set, so no quota can be worked out.';
        return $out;
    }
    if ($work['units'] <= 0) {
        $out['problems'][] = 'No ' . $work['label'] . ' added yet, so there is nothing to schedule.';
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

    $daysPerUnit = (float)($step['days_per_unit'] ?: 1.0);
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
    $rows = [];
    foreach ($steps as $step) $rows[] = step_quota($pdo, $projectId, $step);
    return $rows;
}

/** Format a rate for display without pretending to precision we don't have. */
function fmt_rate(?float $v): string {
    if ($v === null) return '—';
    if ($v >= 10) return number_format($v, 0);
    if ($v >= 1)  return number_format($v, 1);
    return number_format($v, 2);
}
