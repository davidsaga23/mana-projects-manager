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
require_once __DIR__ . '/standards.php';   // effective_days_per_unit()

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
        // Estimated days must be measured against the number the plan actually
        // ran on, or "estimated vs actual" compares actual work to a figure
        // nothing was planned from.
        $estDays  = $work['units'] * effective_days_per_unit($pdo, $projectId, $s) * $retake;
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

// ---------------------------------------------------------------------------
// Per-day time entries (migration 022).
//
// Everything above this line reads progress.time_spent_minutes — the running
// total Plaky sends. Everything below reads `time_logs`, which is what people
// type here. Where both exist the entries win, and every figure says which it
// is showing: two sources for one number is only a trap when nothing tells you
// which one you are looking at.
// ---------------------------------------------------------------------------

/** Is per-day time logging switched on? */
function time_logs_available(PDO $pdo): bool {
    return column_exists($pdo, 'time_logs', 'id');
}

/**
 * Record one entry. Returns a sentence for the flash message.
 *
 * @throws RuntimeException on anything that would store a number nobody meant
 */
function log_time(PDO $pdo, array $in): string {
    if (!time_logs_available($pdo)) {
        throw new RuntimeException('Time logging needs migration 022, which has not been applied yet.');
    }

    $crewId = (int)($in['crew_id'] ?? 0);
    if ($crewId <= 0) throw new RuntimeException('Time has to be logged against a person.');

    // Typed in HOURS, stored in MINUTES. "1.5", "1,5" and "90m" are all things
    // people actually type; the first two mean the same thing and the third is
    // already minutes.
    $raw = trim((string)($in['hours'] ?? ''));
    if ($raw === '') throw new RuntimeException('How long? Enter a number of hours.');

    if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*m(?:in)?\s*$/i', $raw, $m)) {
        $minutes = (int)round((float)str_replace(',', '.', $m[1]));
    } else {
        $clean = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $raw));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $raw . '" is not a number of hours — nothing was logged.');
        }
        $minutes = (int)round((float)$clean * 60);
    }

    if ($minutes <= 0) throw new RuntimeException('That works out as zero — nothing was logged.');
    // A day has 24 hours in it, and somebody typing 800 meant 8.00. Refusing is
    // better than storing a figure that quietly poisons every average built on it.
    if ($minutes > 24 * 60) {
        throw new RuntimeException('That is more than 24 hours in one entry — check the number.');
    }

    $day = trim((string)($in['log_date'] ?? ''));
    if ($day === '') $day = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        throw new RuntimeException('That date is not a real date — nothing was logged.');
    }
    if ($day > date('Y-m-d')) {
        throw new RuntimeException('That day is in the future — time cannot be logged before it is worked.');
    }

    $pdo->prepare(
        'INSERT INTO time_logs (project_id, progress_id, ticket_id, crew_id,
                                log_date, minutes, notes, entered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$in['project_id'],
        !empty($in['progress_id']) ? (int)$in['progress_id'] : null,
        !empty($in['ticket_id'])   ? (int)$in['ticket_id']   : null,
        $crewId, $day, $minutes,
        trim((string)($in['notes'] ?? '')) ?: null,
        !empty($in['entered_by']) ? (int)$in['entered_by'] : null,
    ]);

    return 'Logged ' . fmt_hours($minutes) . ' on ' . date('j M', strtotime($day)) . '.';
}

/** Remove one entry, if the person asking is allowed to. */
function delete_time_log(PDO $pdo, int $id, int $userId, bool $isAdmin): string {
    if (!time_logs_available($pdo)) return 'Nothing to remove.';
    $q = $pdo->prepare('SELECT * FROM time_logs WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) return 'That entry is already gone.';

    // Whoever typed it, or an admin. An artist correcting their own slip should
    // not have to ask; somebody else's hours are not theirs to edit.
    if (!$isAdmin && (int)$row['entered_by'] !== $userId) {
        throw new RuntimeException('You can only remove entries you made yourself.');
    }
    $pdo->prepare('DELETE FROM time_logs WHERE id = ?')->execute([$id]);
    return 'Removed ' . fmt_hours((int)$row['minutes']) . '.';
}

/** Logged minutes per progress row, for a set of tasks. */
function logged_by_progress(PDO $pdo, array $progressIds): array {
    if (!$progressIds || !time_logs_available($pdo)) return [];
    $ids = implode(',', array_map('intval', $progressIds));
    $out = [];
    foreach ($pdo->query(
        "SELECT progress_id, SUM(minutes) AS m FROM time_logs
          WHERE progress_id IN ({$ids}) GROUP BY progress_id"
    ) as $r) {
        $out[(int)$r['progress_id']] = (int)$r['m'];
    }
    return $out;
}

/** One person's entries, newest first. */
function time_logs_for_crew(PDO $pdo, int $crewId, ?int $projectId = null, int $limit = 40): array {
    if (!time_logs_available($pdo)) return [];
    $limit = max(1, min(200, $limit));
    $sql = "SELECT tl.*, ps.name AS step_name, s.episode, s.shot_code, a.name AS asset_name,
                   t.seq AS ticket_seq, t.title AS ticket_title, p.code AS project_code
              FROM time_logs tl
              LEFT JOIN progress pr ON pr.id = tl.progress_id
              LEFT JOIN pipeline_steps ps ON ps.id = pr.step_id
              LEFT JOIN shots  s ON s.id = pr.shot_id
              LEFT JOIN assets a ON a.id = pr.asset_id
              LEFT JOIN tickets t ON t.id = tl.ticket_id
              LEFT JOIN projects p ON p.id = tl.project_id
             WHERE tl.crew_id = ?" . ($projectId ? ' AND tl.project_id = ?' : '') . "
             ORDER BY tl.log_date DESC, tl.id DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($projectId ? [$crewId, $projectId] : [$crewId]);
    return $stmt->fetchAll();
}

/** Total logged minutes for one person, optionally within a window. */
function logged_total_for_crew(PDO $pdo, int $crewId, ?int $projectId = null,
                               ?string $from = null, ?string $to = null): int {
    if (!time_logs_available($pdo)) return 0;
    $sql = 'SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE crew_id = ?';
    $args = [$crewId];
    if ($projectId) { $sql .= ' AND project_id = ?'; $args[] = $projectId; }
    if ($from) { $sql .= ' AND log_date >= ?'; $args[] = $from; }
    if ($to)   { $sql .= ' AND log_date <= ?'; $args[] = $to; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetchColumn();
}

/** A readable label for what an entry was against. */
function time_log_target(array $row): string {
    if (!empty($row['ticket_seq'])) {
        return ($row['project_code'] ?? '') . '-' . (int)$row['ticket_seq']
             . ' · ' . ($row['ticket_title'] ?? '');
    }
    $item = shot_label($row['episode'] ?? '', $row['shot_code'] ?? '');
    if ($item === '') $item = (string)($row['asset_name'] ?? '');
    $step = (string)($row['step_name'] ?? '');
    if ($item === '' && $step === '') return 'General';
    return trim($item . ($step !== '' ? ' · ' . $step : ''));
}
