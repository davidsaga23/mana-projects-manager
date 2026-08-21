<?php
// Freezing what a step was estimated at, so it can be compared with what it
// actually took — on this project, and on every project after it.
//
// The estimates themselves live on pipeline_steps and are edited in place. That
// is right for planning and useless for learning: by the time a show wraps, the
// numbers it started with are gone. This keeps a copy at the moments that
// matter, and never touches one once written.

require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/pipeline.php';

/**
 * Write a baseline row for every step in a project.
 *
 * Append-only — this never updates. Two rows for one step is not a duplicate,
 * it is the history: an original estimate and a revision, each with its own
 * reason and timestamp.
 *
 * Returns how many rows were written.
 */
function capture_baseline(PDO $pdo, int $projectId, string $reason = 'setup complete'): int {
    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) return 0;

    $estimates = project_estimates($pdo, $projectId);
    $retakePct = project_retake_pct($pdo, $projectId);

    $ins = $pdo->prepare(
        'INSERT INTO step_estimate_baseline
            (project_id, step_id, step_name, applies_to, applies_to_asset_type, tracks_seconds,
             days_per_unit, coverage_pct, planned_headcount, retake_pct,
             work_units, work_label, units_estimated, start_date, deadline, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($steps as $s) {
            // The scale the estimate was made against, captured the same way the
            // planning screen computes it — so a baseline and the screen that
            // produced it can never disagree.
            $work = step_work_units($pdo, $projectId, $s, $estimates);
            $ins->execute([
                $projectId, (int)$s['id'], $s['name'],
                $s['applies_to'], $s['applies_to_asset_type'] ?? '', (int)$s['tracks_seconds'],
                // What the project was actually PLANNED against, which since
                // 2026-08-17 is the studio ideal wherever one exists. Recording
                // the raw column would freeze a number the plan never used, and
                // the whole point of a baseline is comparing what was believed
                // with what happened.
                effective_days_per_unit($pdo, $projectId, $s), (float)($s['coverage_pct'] ?? 100),
                $s['planned_headcount'] !== null ? (float)$s['planned_headcount'] : null,
                $retakePct,
                (float)$work['units'], (string)$work['label'], !empty($work['estimated']) ? 1 : 0,
                $s['start_date'] ?: null, $s['deadline'] ?: null,
                mb_substr($reason, 0, 60),
            ]);
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** When a project was last baselined, or null if it never has been. */
function last_baseline(PDO $pdo, int $projectId): ?array {
    try {
        $stmt = $pdo->prepare(
            'SELECT reason, captured_at, COUNT(*) AS steps
               FROM step_estimate_baseline
              WHERE project_id = ?
                AND captured_at = (SELECT MAX(captured_at) FROM step_estimate_baseline
                                    WHERE project_id = ?)
              GROUP BY reason, captured_at'
        );
        $stmt->execute([$projectId, $projectId]);
    } catch (PDOException $e) {
        return null;   // migration 013 pending
    }
    return $stmt->fetch() ?: null;
}

/**
 * How today's estimates differ from the last baseline, per step.
 *
 * The point of showing this: an estimate that has drifted a long way from what
 * the project committed to is either a plan that changed (worth re-baselining
 * deliberately) or a number somebody nudged without meaning to. Both are worth
 * seeing; neither is visible today.
 *
 * Returns [step_id => ['days_per_unit' => [was, now], ...]] for changed steps.
 */
function baseline_drift(PDO $pdo, int $projectId): array {
    try {
        $stmt = $pdo->prepare(
            'SELECT b.step_id, b.days_per_unit, b.coverage_pct, b.planned_headcount
               FROM step_estimate_baseline b
              WHERE b.project_id = ?
                AND b.captured_at = (SELECT MAX(captured_at) FROM step_estimate_baseline
                                      WHERE project_id = ?)'
        );
        $stmt->execute([$projectId, $projectId]);
    } catch (PDOException $e) {
        return [];
    }

    $base = [];
    foreach ($stmt as $r) {
        if ($r['step_id'] === null) continue;
        $base[(int)$r['step_id']] = $r;
    }
    if (!$base) return [];

    $drift = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) {
        $id = (int)$s['id'];
        if (!isset($base[$id])) continue;
        $b = $base[$id];
        $row = [];

        // Compared with a tolerance, not ==: these are DECIMALs coming back as
        // strings, and 2.00 vs 2 is not a change anybody made.
        $pairs = [
            'days_per_unit'     => [(float)$b['days_per_unit'],
                                    effective_days_per_unit($pdo, $projectId, $s)],
            'coverage_pct'      => [(float)$b['coverage_pct'],   (float)($s['coverage_pct'] ?? 100)],
            'planned_headcount' => [
                $b['planned_headcount'] === null ? null : (float)$b['planned_headcount'],
                $s['planned_headcount'] === null ? null : (float)$s['planned_headcount'],
            ],
        ];
        foreach ($pairs as $key => [$was, $now]) {
            if ($was === null && $now === null) continue;
            if ($was === null || $now === null || abs($was - $now) > 0.0001) {
                $row[$key] = [$was, $now];
            }
        }
        if ($row) $drift[$id] = ['step' => $s] + $row;
    }
    return $drift;
}
