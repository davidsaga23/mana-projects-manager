<?php
// Logged time: what work actually took, against what it was estimated at.
//
// The importer has always written progress.time_spent_minutes — the Plaky
// column, converted with the unit declared on the upload screen. Nothing ever
// read it back, so the numbers went in and disappeared. This is the reading
// half.
//
// Stored in MINUTES, shown in HOURS, everywhere (docs/DECISIONS.md 2026-08-12):
// an integer count of minutes is exact, and hours as a decimal drift when summed
// against an invoice.

require_once __DIR__ . '/functions.php';

/** "16 h", "7.5 h", "—". Never a bare number: the unit is the whole point. */
function fmt_hours(?int $minutes): string {
    if ($minutes === null || $minutes <= 0) return '—';
    $h = $minutes / 60;
    if ($h >= 100) return number_format($h, 0) . ' h';
    if ($h >= 10)  return number_format($h, 1) . ' h';
    return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . ' h';
}

/**
 * How many working hours a day is, for turning estimated DAYS into hours.
 *
 * Estimates are in days; logged time is in minutes. Comparing them needs one
 * number that only a studio can supply, so it is a setting rather than a
 * constant — and every figure derived from it says it was derived from it.
 * Eight is the default because it is the one nobody has to explain.
 */
function hours_per_day(PDO $pdo, int $projectId): float {
    $v = (float)project_setting($pdo, $projectId, 'hours_per_day', '8');
    return $v > 0 ? $v : 8.0;
}

/**
 * Logged time per pipeline step, beside what that step was estimated to take.
 *
 * The estimate is person-days: work units × days-per-item × the retake
 * allowance — the same arithmetic the quota and the scheduler use, so a step
 * cannot show one number here and another there.
 *
 * 'coverage' is how much of the step has any time logged against it at all.
 * Without it a step with two of forty tasks filled in looks wildly under
 * budget, when really it is barely reported.
 */
function timelog_by_step(PDO $pdo, int $projectId, array $steps): array {
    $stmt = $pdo->prepare(
        'SELECT step_id,
                COALESCE(SUM(time_spent_minutes), 0) AS minutes,
                COUNT(*) AS tracked,
                COALESCE(SUM(time_spent_minutes IS NOT NULL AND time_spent_minutes > 0), 0) AS with_time
           FROM progress WHERE project_id = ? GROUP BY step_id'
    );
    $stmt->execute([$projectId]);
    $byStep = [];
    foreach ($stmt as $r) $byStep[(int)$r['step_id']] = $r;

    $estimates = project_estimates($pdo, $projectId);
    $retake    = retake_factor(project_retake_pct($pdo, $projectId));
    $hpd       = hours_per_day($pdo, $projectId);

    $out = [];
    foreach ($steps as $s) {
        $id  = (int)$s['id'];
        $row = $byStep[$id] ?? null;
        $minutes = (int)($row['minutes'] ?? 0);

        $work = step_work_units($pdo, $projectId, $s, $estimates);
        $estDays  = $work['units'] * (float)($s['days_per_unit'] ?: 1) * $retake;
        $estHours = $estDays * $hpd;

        $out[] = [
            'step'      => $s,
            'minutes'   => $minutes,
            'hours'     => $minutes / 60,
            'tracked'   => (int)($row['tracked'] ?? 0),
            'with_time' => (int)($row['with_time'] ?? 0),
            'est_days'  => $estDays,
            'est_hours' => $estHours,
            // Only meaningful once most of the step is actually reported, so the
            // caller is handed the coverage rather than just a ratio it might
            // read as fact.
            'variance'  => ($estHours > 0 && $minutes > 0) ? (($minutes / 60) - $estHours) / $estHours : null,
            'coverage'  => ($row && (int)$row['tracked'] > 0)
                           ? (int)$row['with_time'] / (int)$row['tracked'] : 0.0,
        ];
    }
    return $out;
}

/**
 * Logged time per person.
 *
 * Reads progress_crew where it exists so a task with two names on it counts for
 * both, falling back to progress.crew_id. NOTE the double count: an hour on a
 * shared task appears against each person. That is right for "who worked on
 * this" and wrong for "how much did each deliver" — the open question in
 * DECISIONS.md, surfaced here rather than silently decided.
 */
function timelog_by_person(PDO $pdo, int $projectId): array {
    try {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.name, c.is_placeholder,
                    COUNT(DISTINCT pr.id) AS tasks,
                    COALESCE(SUM(pr.time_spent_minutes), 0) AS minutes,
                    COALESCE(SUM(pr.is_approved), 0) AS approved
               FROM progress pr
               JOIN progress_crew pc ON pc.progress_id = pr.id
               JOIN crew c ON c.id = pc.crew_id
              WHERE pr.project_id = ?
              GROUP BY c.id, c.name, c.is_placeholder
              HAVING minutes > 0 OR tasks > 0
              ORDER BY minutes DESC, c.name'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    } catch (PDOException $e) { /* progress_crew is optional */ }

    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.is_placeholder,
                COUNT(pr.id) AS tasks,
                COALESCE(SUM(pr.time_spent_minutes), 0) AS minutes,
                COALESCE(SUM(pr.is_approved), 0) AS approved
           FROM progress pr JOIN crew c ON c.id = pr.crew_id
          WHERE pr.project_id = ?
          GROUP BY c.id, c.name, c.is_placeholder
          ORDER BY minutes DESC, c.name'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** Does this project have any logged time at all? Decides whether to draw any of it. */
function has_logged_time(PDO $pdo, int $projectId): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM progress
          WHERE project_id = ? AND time_spent_minutes IS NOT NULL AND time_spent_minutes > 0'
    );
    $stmt->execute([$projectId]);
    return (int)$stmt->fetchColumn() > 0;
}
