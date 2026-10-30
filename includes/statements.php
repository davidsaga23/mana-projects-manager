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
// pipeline_steps(), which expense_projection() calls but finance.php does not
// load: statement.php always required it itself, and Home did not, so a
// statement read on Home stopped with "undefined function pipeline_steps()"
// (2026-10-30). require_once, and only from here: thirteen pages load
// pipeline.php with a plain require, and none of them reach this file.
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/finance.php';      // expense_projection()
require_once __DIR__ . '/fx.php';           // the project's fixed exchange rates
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
    $add('SELECT crew_id FROM supervision_positions WHERE project_id = ?');   // 068
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
    // A supervisor holds no slot: their supervision position's own dates (068).
    if (!$from || !$to) {
        try {
            $q = $pdo->prepare(
                'SELECT MIN(start_date) AS s, MAX(end_date) AS e FROM supervision_positions
                  WHERE project_id = ? AND crew_id = ?'
            );
            $q->execute([$projectId, $crewId]);
            $r = $q->fetch() ?: [];
            if (!$from && !empty($r['s'])) { $from = $r['s']; $source = $source === 'none' ? 'department' : $source; }
            if (!$to && !empty($r['e']))   { $to = $r['e'];   $source = $source === 'none' ? 'department' : $source; }
        } catch (PDOException $e) {
            // supervision_positions before migration 068
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
 * Approved seconds per STEP, for one person: [step id => seconds]. The same
 * count as statement_approved_seconds(), split by step so a position with its
 * own contract (finance_install_13) can be paid on its own footage.
 */
function statement_approved_seconds_by_step(PDO $pdo, int $projectId, int $crewId): array {
    $hasCrew = column_exists($pdo, 'progress_crew', 'crew_id');
    $sql = 'SELECT pr.id, pr.step_id, sh.placeholder_seconds AS secs'
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
        return [];
    }
    $out = [];
    foreach ($q as $r) {
        $sid = (int)$r['step_id'];
        $out[$sid] = ($out[$sid] ?? 0.0) + (float)$r['secs'] / max(1, (int)$r['sharers']);
    }
    return array_map(fn($v) => round($v, 3), $out);
}

/**
 * Minutes one person logged on a show per STEP, inside a window: [step id =>
 * minutes]. Time on a production task counts for its step; time on a card
 * counts for the card's step when it has one; anything else is step 0.
 */
function statement_minutes_by_step(PDO $pdo, int $projectId, int $crewId, ?string $from, ?string $to): array {
    if (!time_logs_available($pdo)) return [];
    $stepExpr = column_exists($pdo, 'tickets', 'step_id') ? 'COALESCE(pr.step_id, t.step_id, 0)' : 'COALESCE(pr.step_id, 0)';
    $sql = "SELECT {$stepExpr} AS sid, SUM(tl.minutes) AS m
              FROM time_logs tl
              LEFT JOIN progress pr ON pr.id = tl.progress_id
              LEFT JOIN tickets t ON t.id = tl.ticket_id
             WHERE tl.crew_id = ? AND tl.project_id = ?";
    $args = [$crewId, $projectId];
    if ($from) { $sql .= ' AND tl.log_date >= ?'; $args[] = $from; }
    if ($to)   { $sql .= ' AND tl.log_date <= ?'; $args[] = $to; }
    $sql .= ' GROUP BY sid';
    try {
        $q = $pdo->prepare($sql);
        $q->execute($args);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($q as $r) $out[(int)$r['sid']] = (int)$r['m'];
    return $out;
}

/**
 * What one set of terms makes due, in the unit its rate is a price of.
 * [rate, unit, currency, basis, basis label, due]. The one place the switch
 * below is written, for the whole show and for one position alike.
 */
function statement_price(?array $terms, float $hours, float $days, float $seconds): array {
    $rate = ($terms && $terms['day_rate'] !== null) ? (float)$terms['day_rate'] : null;
    $unit = (string)($terms['rate_unit'] ?? 'day');
    $cur  = fx_code($terms['rate_currency'] ?? 'USD') ?? 'USD';
    switch ($unit) {
        case 'hour':   $basis = $hours;   $label = 'logged hours'; break;
        case 'second': $basis = $seconds; $label = 'approved seconds'; break;
        case 'flat':   $basis = 1.0;      $label = 'flat fee'; break;
        default:       $basis = $days;    $label = 'days logged'; $unit = 'day';
    }
    $due = $rate !== null ? round($rate * $basis, 2) : null;
    return [$rate, $unit, $cur, $basis, $label, $due];
}

/**
 * One person's statement on one show.
 *
 * $terms and $invoices are passed in, not read here, so the admin tab can read
 * every person's in one go and the artist's own view reads through the
 * own_*() doors. Either may be empty: the figures still stand without a rate.
 */
function artist_statement(PDO $pdo, int $projectId, int $crewId, ?array $terms, array $invoices,
                          ?array $projection = null, array $fx = [], array $positionTerms = []): array {
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
    [$rate, $unit, $cur, $basis, $basisLabel, $due] = statement_price($terms, $hours, $days, $approvedSecs);

    // ⚠ A CONTRACT IN ANOTHER CURRENCY, WITH A RATE FIXED FOR THE PROJECT
    // ($fx, from project_fx_rates() or own_project_fx_rates()), is stated in
    // DOLLARS, with its own currency beside it (Tim and Jorik, 2026-10-28:
    // "$520/day (£400)"). `currency` is then USD and is the money every figure
    // below is in; `rate_currency` and `due_own` keep the contract's own.
    // Without a fixed rate it stays in its own currency, as before.
    $fxRow = ($cur !== 'USD' && isset($fx[$cur])) ? $fx[$cur] : null;
    $money = $fxRow ? 'USD' : $cur;
    $dueOwn = null;
    if ($fxRow && $due !== null) { $dueOwn = $due; $due = fx_to_usd($due, $cur, $fx); }

    // ⚠ A POSITION WITH ITS OWN CONTRACT (finance_install_13, David 2026-10-29)
    // is paid on ITS time and footage at ITS rate; everything else is paid on
    // the default. $positionTerms is [step id => position_terms row]. With none
    // the figures above stand exactly as they always did.
    //
    // The rest is time on any other step, and time on no step at all (cards,
    // general). When the person holds a SUPERVISION position with its own
    // contract (step id below zero), the rest is paid on that one instead: a
    // supervisor logs no production steps, so the rest IS their supervision.
    $lines = [];
    if ($positionTerms) {
        $minByStep = statement_minutes_by_step($pdo, $projectId, $crewId, $win['from'], $win['to']);
        $secsByStep = statement_approved_seconds_by_step($pdo, $projectId, $crewId);
        $restTerms = $terms; $restName = 'Everything else'; $restOwn = false;
        foreach ($positionTerms as $sid => $pt) {
            if ((int)$sid < 0) {
                $restTerms = $pt; $restOwn = true;
                $restName = (string)($pt['position_name'] ?? 'Supervision');
                break;
            }
        }
        $restMin = $minutesIn; $restSecs = $approvedSecs;
        $buckets = [];
        foreach ($positionTerms as $sid => $pt) {
            if ((int)$sid <= 0) continue;
            $m = (int)($minByStep[(int)$sid] ?? 0);
            $sc = (float)($secsByStep[(int)$sid] ?? 0.0);
            $restMin -= $m; $restSecs -= $sc;
            $buckets[] = [(string)($pt['position_name'] ?? 'Position'), $pt, $m, $sc, true];
        }
        $buckets[] = [$restName, $restTerms, max(0, $restMin), max(0.0, $restSecs), $restOwn];

        $moneys = [];
        foreach ($buckets as [$name, $bt, $m, $sc, $own]) {
            $h = $m / 60;
            $d = $hpd > 0 ? $h / $hpd : 0.0;
            [$lr, $lu, $lc, $lb, $ll, $ld] = statement_price($bt, $h, $d, $sc);
            // A position nobody has worked on yet, on a rate that is not a
            // flat fee, has nothing to say; it stays off the list.
            if ($lu !== 'flat' && $m <= 0 && $sc <= 0) continue;
            $lFx = ($lc !== 'USD' && isset($fx[$lc])) ? $fx[$lc] : null;
            $lMoney = $lFx ? 'USD' : $lc;
            $lDueOwn = null;
            if ($lFx && $ld !== null) { $lDueOwn = $ld; $ld = fx_to_usd($ld, $lc, $fx); }
            $moneys[$lMoney] = true;
            $lines[] = ['position' => $name, 'own_contract' => $own, 'rate' => $lr, 'unit' => $lu,
                        'rate_currency' => $lc, 'currency' => $lMoney, 'hours' => $h, 'days' => $d,
                        'seconds' => $sc, 'basis' => $lb, 'basis_label' => $ll, 'due' => $ld,
                        'due_own' => $lDueOwn,
                        'rate_usd' => ($lFx && $lr !== null) ? $lr * (float)$lFx['usd_per_unit'] : null];
        }
        // One line on the default is the statement as it always was (the own
        // contract has nothing logged against it yet): drawn the usual way.
        if (count($lines) === 1 && !$lines[0]['own_contract']) $lines = [];

        // The show's due is the sum of its lines, in one currency, or nothing
        // when the lines are in two that no fixed rate brings together.
        if ($lines) {
            $unpriced = false; $sum = 0.0;
            foreach ($lines as $l) { if ($l['due'] === null) $unpriced = true; else $sum += $l['due']; }
            $oneMoney = count($moneys) === 1;
            $money = $oneMoney ? (string)array_key_first($moneys) : 'USD';
            $due = ($oneMoney && !$unpriced) ? round($sum, 2) : null;
            $fxRow = null; $dueOwn = null;
            $rate = $lines[0]['rate']; $unit = $lines[0]['unit']; $cur = $lines[0]['rate_currency'];
            $basis = $lines[0]['basis']; $basisLabel = $lines[0]['basis_label'];
        }
    }

    $invoiced = 0.0; $otherCurrency = false;
    foreach ($invoices as $inv) {
        // An EXPECTED invoice has not arrived (finance_install_09): it is not
        // something the artist has invoiced yet, so it is not subtracted.
        if (($inv['status'] ?? '') === 'expected') continue;
        // In the statement's money, converting at the fixed rate when there is
        // one; an invoice in a currency with no fixed rate is left out and
        // reported, as it always was.
        $ic = fx_code($inv['currency'] ?? $cur) ?? $cur;
        $amt = $ic === $money ? (float)$inv['amount']
             : ($money === 'USD' ? fx_to_usd((float)$inv['amount'], $ic, $fx) : null);
        if ($amt === null) { $otherCurrency = true; continue; }
        $invoiced += $amt;
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
        'currency'        => $money,
        'rate_currency'   => $cur,
        'rate_usd'        => ($fxRow && $rate !== null) ? $rate * (float)$fxRow['usd_per_unit'] : null,
        'due_own'         => $dueOwn,
        'fx'              => $fxRow,
        'basis'           => $basis,
        'basis_label'     => $basisLabel,
        'due'             => $due,
        'invoiced'        => round($invoiced, 2),
        'invoices'        => $invoices,
        'invoiced_other_currency' => $otherCurrency,
        'left'            => $due !== null ? round($due - $invoiced, 2) : null,
        // One line per contract when a position has its own (finance_install_13).
        // Empty when everything is paid on the one set of terms.
        'lines'           => $lines,
    ];
}

/** "1:23.5" style, for seconds of footage. */
function statement_secs(float $s): string {
    return function_exists('fmt_secs') ? fmt_secs($s) : number_format($s, 1) . 's';
}
