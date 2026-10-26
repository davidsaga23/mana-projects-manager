<?php
// The artist statement: what each person should invoice on a show, and why
// (David, 2026-10-26: "project and inform artists how many days, logged time
// and seconds we need to pay/account for their invoices").
//
// Settled with him the same day:
//   * THE PERIOD is the person's own window on the show — their contract dates
//     from the person page (crew_projects.start_date / end_date, migration
//     024), and where those are not set, the dates of the steps they hold a
//     position on, which is their department's window.
//   * SECONDS count only when APPROVED. Footage delivered and sent back is not
//     footage the studio pays for yet.
//   * ARTISTS SEE IT — their own, through own_crew_term() and own_invoices() in
//     includes/money.php, which give nobody anything about anybody else.
//
// WHAT IS DUE follows the person's rate unit, because that is what the rate
// is a price OF:
//   day     logged hours ÷ the show's hours per day, as days
//   hour    logged hours
//   second  approved seconds on the footage steps they are on
//   flat    the flat fee, once
// The planned figures from the expense projection sit beside each, so a
// person can see what the show expected of them as well as what has happened.
//
// Nothing here writes. Every number is read from where it already lives:
// time_logs, progress, shots, the projection, and the finance database.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/finance.php';      // expense_projection()
require_once __DIR__ . '/money.php';        // own_crew_term(), own_invoices()
require_once __DIR__ . '/timelog.php';      // hours_per_day(), logged_total_for_crew()
require_once __DIR__ . '/hiring.php';       // engagement()

/**
 * Everybody with something to invoice on a show: a position on it, logged
 * time on it, or a task on it. Real people only — an empty slot invoices
 * nothing. [crew id => name], by name.
 */
function statement_people(PDO $pdo, int $projectId): array {
    $ids = [];
    $add = function (string $sql) use ($pdo, $projectId, &$ids) {
        try {
            $q = $pdo->prepare($sql);
            $q->execute([$projectId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) if ($id) $ids[(int)$id] = true;
        } catch (PDOException $e) {
            // a table whose migration has not run on this install
        }
    };
    $add('SELECT crew_id FROM crew_lanes WHERE project_id = ?');
    $add('SELECT DISTINCT crew_id FROM time_logs WHERE project_id = ?');
    $add('SELECT DISTINCT crew_id FROM progress WHERE project_id = ? AND crew_id IS NOT NULL');
    if (!$ids) return [];
    $in = implode(',', array_keys($ids));
    $out = [];
    foreach ($pdo->query("SELECT id, name FROM crew WHERE id IN ($in)
                            AND COALESCE(is_placeholder, 0) = 0 AND COALESCE(is_team, 0) = 0
                          ORDER BY name") as $r) {
        $out[(int)$r['id']] = (string)$r['name'];
    }
    return $out;
}

/**
 * The window a person's statement covers: their contract on the show, or
 * failing that the span of the steps they hold a position on.
 * ['from' => ?Y-m-d, 'to' => ?Y-m-d, 'source' => 'contract'|'department'|'none']
 */
function statement_window(PDO $pdo, int $projectId, int $crewId): array {
    $eng = function_exists('engagement') ? engagement($pdo, $crewId, $projectId) : null;
    $from = $eng['start_date'] ?? null;
    $to   = $eng['end_date'] ?? null;
    $source = ($from || $to) ? 'contract' : 'none';

    if (!$from || !$to) {
        try {
            $q = $pdo->prepare(
                'SELECT MIN(ps.start_date) AS s, MAX(ps.deadline) AS e
                   FROM crew_lanes cl JOIN pipeline_steps ps ON ps.id = cl.step_id
                  WHERE cl.project_id = ? AND cl.crew_id = ?'
            );
            $q->execute([$projectId, $crewId]);
            $r = $q->fetch() ?: [];
            if (!$from && !empty($r['s'])) { $from = $r['s']; $source = $source === 'none' ? 'department' : $source; }
            if (!$to && !empty($r['e']))   { $to = $r['e'];   $source = $source === 'none' ? 'department' : $source; }
        } catch (PDOException $e) {
            // crew_lanes before migration 012
        }
    }
    return ['from' => $from ?: null, 'to' => $to ?: null, 'source' => $source];
}

/**
 * Seconds of footage APPROVED on the steps that track seconds, for one person.
 * A shot two people share is split evenly between them — the progress row
 * names both (progress_crew), and paying each the whole shot would pay it twice.
 */
function statement_approved_seconds(PDO $pdo, int $projectId, int $crewId): float {
    $hasCrew = column_exists($pdo, 'progress_crew', 'crew_id');
    $sql = 'SELECT pr.id, sh.placeholder_seconds AS secs'
         . ($hasCrew ? ', (SELECT COUNT(*) FROM progress_crew pc2 WHERE pc2.progress_id = pr.id) AS sharers' : ', 1 AS sharers')
         . ' FROM progress pr
             JOIN pipeline_steps ps ON ps.id = pr.step_id
             JOIN shots sh ON sh.id = pr.shot_id
            WHERE pr.project_id = ? AND pr.is_approved = 1 AND COALESCE(ps.tracks_seconds, 0) = 1
              AND (pr.crew_id = ?'
         . ($hasCrew ? ' OR EXISTS (SELECT 1 FROM progress_crew pc WHERE pc.progress_id = pr.id AND pc.crew_id = ?)' : '')
         . ')';
    $args = $hasCrew ? [$projectId, $crewId, $crewId] : [$projectId, $crewId];
    try {
        $q = $pdo->prepare($sql);
        $q->execute($args);
    } catch (PDOException $e) {
        return 0.0;
    }
    $total = 0.0;
    foreach ($q as $r) {
        $total += (float)$r['secs'] / max(1, (int)$r['sharers']);
    }
    return round($total, 3);
}

/**
 * One person's statement on one show.
 *
 * $terms and $invoices are passed in, not read here, so the admin tab can read
 * every person's in one go and the artist's own view reads through the
 * own_*() doors. Either may be empty: the figures still stand without a rate.
 */
function artist_statement(PDO $pdo, int $projectId, int $crewId, ?array $terms, array $invoices,
                          ?array $projection = null): array {
    $hpd = hours_per_day($pdo, $projectId);
    $win = statement_window($pdo, $projectId, $crewId);

    // What the show planned for them: their rows of the expense projection.
    $projection = $projection ?? expense_projection($pdo, $projectId);
    $positions = [];
    $plannedDays = 0.0; $plannedSeconds = 0.0;
    foreach ($projection['rows'] as $r) {
        if ((int)($r['crew_id'] ?? 0) !== $crewId) continue;
        $secs = !empty($r['tracks_seconds']) && (int)($r['lane_count'] ?? 0) > 0
            ? (float)$r['units_per_lane'] : 0.0;
        $positions[] = ['role' => (string)$r['role'], 'days' => (float)$r['days'], 'seconds' => $secs];
        $plannedDays += (float)$r['days'];
        $plannedSeconds += $secs;
    }

    // What happened: hours logged inside their window, and any outside it,
    // which is reported rather than dropped or silently counted.
    $minutesIn = logged_total_for_crew($pdo, $crewId, $projectId, $win['from'], $win['to']);
    $minutesAll = logged_total_for_crew($pdo, $crewId, $projectId);
    $hours = $minutesIn / 60;
    $days = $hpd > 0 ? $hours / $hpd : 0.0;
    $approvedSecs = statement_approved_seconds($pdo, $projectId, $crewId);

    // What is due, in the unit the rate is a price of.
    $rate = ($terms && $terms['day_rate'] !== null) ? (float)$terms['day_rate'] : null;
    $unit = (string)($terms['rate_unit'] ?? 'day');
    $cur  = (string)($terms['rate_currency'] ?? 'USD');
    $basis = null; $basisLabel = '';
    switch ($unit) {
        case 'hour':   $basis = $hours;        $basisLabel = 'logged hours'; break;
        case 'second': $basis = $approvedSecs; $basisLabel = 'approved seconds'; break;
        case 'flat':   $basis = 1.0;           $basisLabel = 'flat fee'; break;
        default:       $basis = $days;         $basisLabel = 'days logged'; $unit = 'day';
    }
    $due = $rate !== null ? round($rate * $basis, 2) : null;

    $invoiced = 0.0; $otherCurrency = false;
    foreach ($invoices as $inv) {
        if (strtoupper((string)($inv['currency'] ?? $cur)) !== strtoupper($cur)) { $otherCurrency = true; continue; }
        $invoiced += (float)$inv['amount'];
    }

    return [
        'window'          => $win,
        'hours_per_day'   => $hpd,
        'positions'       => $positions,
        'planned_days'    => $plannedDays,
        'planned_seconds' => $plannedSeconds,
        'hours'           => $hours,
        'days'            => $days,
        'hours_outside'   => max(0, $minutesAll - $minutesIn) / 60,
        'approved_seconds'=> $approvedSecs,
        'rate'            => $rate,
        'unit'            => $unit,
        'currency'        => $cur,
        'basis'           => $basis,
        'basis_label'     => $basisLabel,
        'due'             => $due,
        'invoiced'        => round($invoiced, 2),
        'invoices'        => $invoices,
        'invoiced_other_currency' => $otherCurrency,
        'left'            => $due !== null ? round($due - $invoiced, 2) : null,
    ];
}

/** "1:23.5" style, for seconds of footage. */
function statement_secs(float $s): string {
    return function_exists('fmt_secs') ? fmt_secs($s) : number_format($s, 1) . 's';
}
