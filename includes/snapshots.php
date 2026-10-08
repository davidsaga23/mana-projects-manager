<?php
// Recording where each project stood, once a day.
//
// Everything else in this app answers "what is true now". A snapshot is the only
// thing that lets it answer "and is that better or worse than last week" — which
// is the question a dashboard exists for.
//
// It runs on page load rather than from a cron: nothing to configure in cPanel,
// and it starts working the moment the code is deployed. The trade-off is
// honest and worth stating — on a day nobody opens the app, no snapshot is
// taken. That gap is REPORTED rather than hidden (snapshot_gaps() below), so a
// hole in the history can never be mistaken for a flat week.

// No require of functions.php here, deliberately: this file is loaded FROM
// functions.php, and requiring it back would be circular. snapshot_gaps() uses
// working_days_list() from there, which is always loaded by the time anything
// calls it.

/**
 * Projects that still need today's snapshot.
 *
 * One query, and the reason migration 011 adds an index on snapshot_date: this
 * runs on every page view.
 *
 * Only 'active' and 'on_hold' projects. A project still in setup has nothing
 * meaningful to record, and a wrapped one has stopped moving — recording either
 * every day would bury the real shows in noise.
 *
 * ⚠ 'postmortem' (migration 044) IS DELIBERATELY NOT SNAPSHOTTED, decided
 * 2026-10-08. A snapshot answers "where did this show stand on this day" so a
 * chart can show the work advancing. A show in postmortem has, by definition,
 * finished its production work — it got there THROUGH 'wrapped' — so every
 * further snapshot would write the same numbers again and flatten the end of
 * the chart with a tail of identical days. The postmortem is a meeting, not
 * production, and nothing it produces is a step percentage.
 *
 * Nothing is lost by this: the snapshots taken while the show was active are
 * the history, and they stay. See snapshot_gaps(), which reports missing days
 * rather than inventing them.
 */
function projects_needing_snapshot(PDO $pdo, string $today): array {
    $stmt = $pdo->prepare(
        "SELECT p.id
           FROM projects p
           LEFT JOIN (
                SELECT project_id, MAX(snapshot_date) AS last_date
                  FROM daily_snapshots GROUP BY project_id
           ) d ON d.project_id = p.id
          WHERE p.status IN ('active', 'on_hold')
            AND (d.last_date IS NULL OR d.last_date < ?)"
    );
    $stmt->execute([$today]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Write one project's position for one day.
 *
 * A row is written for EVERY pipeline step, including steps with nothing
 * recorded against them yet — zeroes are data. Without them a chart would show
 * a line that simply begins partway through, which reads as "work started here"
 * rather than "nothing had been logged yet".
 *
 * Idempotent: running twice in a day overwrites rather than duplicating, so a
 * re-run after fixing an import corrects the day instead of doubling it.
 *
 * Returns how many step rows were written.
 */
function take_snapshot(PDO $pdo, int $projectId, ?string $today = null): int {
    $today = $today ?: date('Y-m-d');

    $stmt = $pdo->prepare('SELECT id FROM pipeline_steps WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $stepIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$stepIds) return 0;

    // What is recorded against each step right now. is_delivered/is_approved are
    // kept in step with the status vocabulary by statuses_edit.php and the
    // importer, so this reads flags rather than re-deriving meaning.
    $stmt = $pdo->prepare(
        'SELECT pr.step_id,
                COUNT(*)                                   AS items_total,
                COALESCE(SUM(pr.is_delivered), 0)          AS items_delivered,
                COALESCE(SUM(pr.is_approved), 0)           AS items_approved,
                COALESCE(SUM(pr.shot_id IS NOT NULL AND pr.is_delivered = 1), 0) AS shots_delivered,
                COALESCE(SUM(pr.shot_id IS NOT NULL AND pr.is_approved  = 1), 0) AS shots_approved,
                COALESCE(SUM(CASE WHEN pr.is_delivered = 1
                                  THEN COALESCE(sh.placeholder_seconds, 0) ELSE 0 END), 0) AS seconds_delivered,
                COALESCE(SUM(CASE WHEN pr.is_approved = 1
                                  THEN COALESCE(sh.placeholder_seconds, 0) ELSE 0 END), 0) AS seconds_approved,
                COALESCE(SUM(st.is_blocked = 1), 0)        AS blocked_count
           FROM progress pr
           LEFT JOIN shots    sh ON sh.id = pr.shot_id
           LEFT JOIN statuses st ON st.id = pr.status_id
          WHERE pr.project_id = ?
          GROUP BY pr.step_id'
    );
    $stmt->execute([$projectId]);
    $byStep = [];
    foreach ($stmt as $r) $byStep[(int)$r['step_id']] = $r;

    $ins = $pdo->prepare(
        'INSERT INTO daily_snapshots
            (project_id, step_id, snapshot_date,
             items_delivered_cum, items_approved_cum, items_total, blocked_count,
             shots_delivered_cum, shots_approved_cum,
             seconds_delivered_cum, seconds_approved_cum)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            items_delivered_cum   = VALUES(items_delivered_cum),
            items_approved_cum    = VALUES(items_approved_cum),
            items_total           = VALUES(items_total),
            blocked_count         = VALUES(blocked_count),
            shots_delivered_cum   = VALUES(shots_delivered_cum),
            shots_approved_cum    = VALUES(shots_approved_cum),
            seconds_delivered_cum = VALUES(seconds_delivered_cum),
            seconds_approved_cum  = VALUES(seconds_approved_cum)'
    );

    $written = 0;
    foreach ($stepIds as $stepId) {
        $r = $byStep[(int)$stepId] ?? null;
        $ins->execute([
            $projectId, (int)$stepId, $today,
            (int)($r['items_delivered'] ?? 0), (int)($r['items_approved'] ?? 0),
            (int)($r['items_total'] ?? 0),     (int)($r['blocked_count'] ?? 0),
            (int)($r['shots_delivered'] ?? 0), (int)($r['shots_approved'] ?? 0),
            (float)($r['seconds_delivered'] ?? 0), (float)($r['seconds_approved'] ?? 0),
        ]);
        $written++;
    }
    return $written;
}

/**
 * Take any snapshots today still needs. Called once per request from
 * require_login(), on GET only.
 *
 * Never throws: a failure here must not stop somebody using the app. It does
 * NOT fail silently either — the reason is stashed for the caller to surface,
 * because a feature that switches itself off on a swallowed exception is
 * indistinguishable from one that was never built (START_HERE, lesson 11).
 *
 * Returns null when there was nothing to do or it worked, or a message when it
 * didn't.
 */
function maybe_take_snapshots(PDO $pdo, ?string $today = null): ?string {
    static $ran = false;
    if ($ran) return null;
    $ran = true;

    $today = $today ?: date('Y-m-d');
    try {
        foreach (projects_needing_snapshot($pdo, $today) as $projectId) {
            take_snapshot($pdo, (int)$projectId, $today);
        }
        return null;
    } catch (PDOException $e) {
        // Migration 011 pending is the ordinary case and not worth alarming
        // anyone about — the header already says there are updates waiting.
        if (stripos($e->getMessage(), 'daily_snapshots') !== false
            || stripos($e->getMessage(), 'Unknown column') !== false) {
            return null;
        }
        return 'The daily progress snapshot could not be recorded: ' . $e->getMessage();
    }
}

/**
 * Days between the first and last snapshot with nothing recorded.
 *
 * The cost of running this on page load rather than from a cron: a day when
 * nobody opened the app has no row. Left unsaid, a gap looks like a week where
 * nothing moved. Reported, it is just a gap.
 *
 * Weekends are excluded — nobody expects Saturday in the history.
 */
function snapshot_gaps(PDO $pdo, int $projectId, int $limit = 60): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT snapshot_date FROM daily_snapshots
          WHERE project_id = ? ORDER BY snapshot_date'
    );
    $stmt->execute([$projectId]);
    $have = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($have) < 2) return [];

    $dates = array_keys($have);
    $gaps = [];
    foreach (working_days_list($dates[0], end($dates)) as $day) {
        if (!isset($have[$day])) $gaps[] = $day;
        if (count($gaps) >= $limit) break;
    }
    return $gaps;
}

/** A project's recorded history, oldest first, for charting. */
function snapshot_history(PDO $pdo, int $projectId, ?int $stepId = null): array {
    $sql = 'SELECT ds.*, ps.name AS step_name, ps.color
              FROM daily_snapshots ds
              JOIN pipeline_steps ps ON ps.id = ds.step_id
             WHERE ds.project_id = ?';
    $args = [$projectId];
    if ($stepId !== null) { $sql .= ' AND ds.step_id = ?'; $args[] = $stepId; }
    $sql .= ' ORDER BY ds.snapshot_date, ps.step_order, ps.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
