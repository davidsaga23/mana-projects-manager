<?php
/**
 * Per-person performance: what somebody is carrying, and how they are doing
 * against the pace their steps require.
 *
 * WHAT THIS CAN AND CANNOT MEASURE, stated up front because the difference
 * matters and is easy to forget once the numbers are on a screen:
 *
 *   Assignments, time logged, and how much of their work is approved — solid.
 *   These are counts of rows that exist.
 *
 *   Pace over time — APPROXIMATE. `daily_snapshots` records history per STEP,
 *   not per person, so "how much did Ana approve last week" has no history to
 *   read. The best available signal is `progress.updated_at`, which is when a
 *   row last changed for any reason — a status edit, a re-import, a reassignment.
 *   It is labelled as approximate everywhere it appears rather than being
 *   presented as measurement. A per-person history table is what would make it
 *   real, and it is the same gap `time_logs` fills for effort (ROADMAP).
 *
 * Money is NOT here. It lives behind includes/money.php, which is the only door.
 */

require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/timelog.php';

/**
 * Everyone worth showing on a project's team screen.
 *
 * Placeholder slots are included and flagged: "Animation 02 is carrying 40 shots"
 * is a real and useful statement about a plan, even though nobody fills the slot
 * yet. Teams (`is_team`) are excluded — a department is not a person, and
 * averaging its output per head is meaningless.
 */
function project_people(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name, c.role, c.email, c.is_placeholder, c.active'
        . (column_exists($pdo, 'crew', 'department') ? ', c.department' : '')
        . ' FROM crew c
           LEFT JOIN crew_projects cp ON cp.crew_id = c.id AND cp.project_id = ?
           LEFT JOIN crew_lanes   cl ON cl.crew_id = c.id AND cl.project_id = ?
           LEFT JOIN progress     pr ON pr.crew_id = c.id AND pr.project_id = ?
          WHERE c.is_team = 0
            AND (cp.crew_id IS NOT NULL OR cl.crew_id IS NOT NULL OR pr.crew_id IS NOT NULL)
          ORDER BY c.is_placeholder ASC, c.name'
    );
    $stmt->execute([$projectId, $projectId, $projectId]);
    return $stmt->fetchAll();
}

/**
 * What one person is carrying on one project, broken down by step.
 *
 * Counts BOTH the primary assignee and the join table, because Phase 3 learned
 * that real work has two names on it (`progress_crew`) and per-artist reporting
 * that drops the second one is worse than useless. Whether a shared task should
 * count for both or be halved is still open in DECISIONS — it counts for both
 * here, which is right for "what is this person involved in" and generous for
 * "how much did they deliver". The screen says which it is.
 */
function person_step_load(PDO $pdo, int $projectId, int $crewId): array {
    $stmt = $pdo->prepare(
        "SELECT ps.id AS step_id, ps.name AS step_name, ps.applies_to,
                ps.applies_to_asset_type, ps.deadline, ps.color,
                COUNT(*)                                   AS assigned,
                COALESCE(SUM(pr.is_approved), 0)           AS approved,
                COALESCE(SUM(pr.is_delivered), 0)          AS delivered,
                COALESCE(SUM(COALESCE(st.is_blocked, 0)), 0) AS blocked,
                COALESCE(SUM(pr.time_spent_minutes), 0)    AS minutes,
                COALESCE(SUM(pr.time_spent_minutes IS NOT NULL), 0) AS with_time,
                MAX(pr.updated_at)                         AS last_touch
           FROM progress pr
           JOIN pipeline_steps ps ON ps.id = pr.step_id
           LEFT JOIN statuses st  ON st.id = pr.status_id
          WHERE pr.project_id = ?
            AND (pr.crew_id = ?
                 OR EXISTS (SELECT 1 FROM progress_crew pc
                             WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
          GROUP BY ps.id, ps.name, ps.applies_to, ps.applies_to_asset_type,
                   ps.deadline, ps.color, ps.step_order
          ORDER BY ps.step_order, ps.name"
    );
    $stmt->execute([$projectId, $crewId, $crewId]);
    return $stmt->fetchAll();
}

/**
 * One person's headline figures on one project.
 *
 * `required_per_day` is the number this whole platform exists to produce: the
 * minimum an artist has to finish each day for the step's deadline to hold,
 * divided by the people planned on it. It comes from step_quota(), so this
 * screen and the dashboard cannot disagree about it.
 */
function person_summary(PDO $pdo, int $projectId, int $crewId, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $load  = person_step_load($pdo, $projectId, $crewId);

    $assigned = 0; $approved = 0; $delivered = 0; $blocked = 0;
    $minutes = 0; $withTime = 0; $lastTouch = null;
    foreach ($load as $r) {
        $assigned  += (int)$r['assigned'];
        $approved  += (int)$r['approved'];
        $delivered += (int)$r['delivered'];
        $blocked   += (int)$r['blocked'];
        $minutes   += (int)$r['minutes'];
        $withTime  += (int)$r['with_time'];
        if ($r['last_touch'] && (!$lastTouch || $r['last_touch'] > $lastTouch)) $lastTouch = $r['last_touch'];
    }

    // Scheduled work, which is the plan rather than the record: how many tasks
    // the scheduler put in this person's lane and when they run out.
    $sch = $pdo->prepare(
        'SELECT COUNT(*) AS tasks,
                COALESCE(SUM(end_date >= ?), 0) AS remaining,
                MIN(start_date) AS first_start, MAX(end_date) AS last_end
           FROM schedule WHERE project_id = ? AND crew_id = ?'
    );
    $sch->execute([$today, $projectId, $crewId]);
    $schedule = $sch->fetch() ?: ['tasks' => 0, 'remaining' => 0, 'first_start' => null, 'last_end' => null];

    return [
        'assigned'   => $assigned,
        'approved'   => $approved,
        'delivered'  => $delivered,
        'blocked'    => $blocked,
        'remaining'  => max(0, $assigned - $approved),
        'pct'        => $assigned > 0 ? ($approved / $assigned) * 100 : null,
        'minutes'    => $minutes,
        'with_time'  => $withTime,
        'last_touch' => $lastTouch,
        'schedule'   => $schedule,
        'steps'      => $load,
    ];
}

/**
 * Their required pace per step, against what they appear to be managing.
 *
 * "Appear to be managing" is doing real work in that sentence — see the file
 * header. The recent figure counts their rows whose `updated_at` falls in the
 * window AND which are approved, which over-reports when a re-import touches
 * old rows and under-reports when somebody finishes work nobody records for a
 * week. It is a direction of travel, not a measurement, and it says so.
 */
function person_pace(PDO $pdo, int $projectId, int $crewId, array $load, int $windowDays = 7): array {
    $since = date('Y-m-d', strtotime("-{$windowDays} days"));
    $out = [];

    foreach ($load as $row) {
        $stepId = (int)$row['step_id'];

        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM progress pr
              WHERE pr.project_id = ? AND pr.step_id = ? AND pr.is_approved = 1
                AND pr.updated_at >= ?
                AND (pr.crew_id = ?
                     OR EXISTS (SELECT 1 FROM progress_crew pc
                                 WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
        );
        $q->execute([$projectId, $stepId, $since . ' 00:00:00', $crewId, $crewId]);
        $recent = (int)$q->fetchColumn();

        // Working days in the window, so a week with a public holiday in it does
        // not make everyone look slower. Same calendar the quota uses.
        $days = max(1, working_days_between($since, date('Y-m-d')));
        $actual = $recent / $days;

        // What the step needs from ONE person per day. Null when the step has no
        // deadline — there is nothing to be behind.
        $required = null;
        $stepRow = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ?');
        $stepRow->execute([$stepId]);
        $step = $stepRow->fetch();
        if ($step && !empty($step['deadline'])) {
            // per_artist_day, NOT team_per_day: the work a date needs is fixed,
            // and only the per-person share moves as the team grows. That
            // distinction confused people on STFC and is labelled on screen.
            $quota = step_quota($pdo, $projectId, $step);
            $required = $quota['per_artist_day'] ?? null;
        }

        $out[$stepId] = [
            'recent'     => $recent,
            'window'     => $windowDays,
            'actual'     => $actual,
            'required'   => $required,
            'on_track'   => ($required === null || $required <= 0) ? null : ($actual >= $required),
        ];
    }
    return $out;
}

/**
 * The whole team at a glance, for the team screen.
 *
 * One query per person rather than one clever query for everyone: a studio has
 * tens of people, not thousands, and the readable version is the one that stays
 * correct when somebody adds a column to it.
 */
function team_overview(PDO $pdo, int $projectId, ?string $today = null): array {
    $out = [];
    foreach (project_people($pdo, $projectId) as $person) {
        $sum = person_summary($pdo, $projectId, (int)$person['id'], $today);
        $out[] = $person + ['summary' => $sum];
    }

    // Busiest first — the people carrying the most unfinished work are the ones
    // a producer is looking for. Placeholders sink below real people at equal
    // load, because a slot cannot be spoken to.
    usort($out, function ($a, $b) {
        $r = $b['summary']['remaining'] <=> $a['summary']['remaining'];
        if ($r !== 0) return $r;
        $p = (int)($a['is_placeholder'] ?? 0) <=> (int)($b['is_placeholder'] ?? 0);
        if ($p !== 0) return $p;
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/** The department list, for the picker. Suggestions from what is already used. */
function crew_departments(PDO $pdo): array {
    $known = ['Above the line', 'Pre-production', 'Production', 'Post-production'];
    if (!column_exists($pdo, 'crew', 'department')) return $known;
    $used = $pdo->query(
        "SELECT DISTINCT department FROM crew WHERE department <> '' ORDER BY department"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($used as $d) {
        if (!in_array($d, $known, true)) $known[] = $d;
    }
    return $known;
}
