<?php
// Is the money on track, STEP BY STEP — the top of Finance management → Overview.
//
// David, 2026-10-29, on the single "budget left" figure Tim proposed: *"I might
// be 'underbudget' by day 70/100 but I know that all the previous departments
// are overbudget, is just that I haven't spent the rest (30%) so that makes the
// project seem like we're fine and we'll only see the overbudget at the very
// end. … I might be overbudget from the animatic (like with FCM) but I'm
// technically underbudget overall."*
//
// So nothing here is ever NETTED across steps. Every step is judged against its
// own plan from the kick-off sign-off, and the answer comes in three figures
// that are kept apart on purpose:
//
//   locked in  finished steps that cost more than their plan. No later saving
//              can undo it.
//   expected   running steps heading past their plan at the pace they are
//              ACTUALLY going, not the pace the plan assumed.
//   reserve    the approved budget less the plan at sign-off, plus whatever
//              finished steps saved. A running step that merely LOOKS cheap
//              adds nothing until it finishes.
//
// HOW EACH NUMBER IS MEASURED (David asked, 2026-10-29: "is it through logged
// time? Is it through the start date until now? Is it by how many assets we've
// finished?"):
//
//   committed  per person per step: what has been invoiced, plus the logged
//              days not invoiced yet at that person's rate. Never "start date
//              until today": the calendar measures time passing, not money.
//              People paid per second or per job have no days to price, so
//              theirs is the share of their fee the approved work has earned.
//              (Kept under the key 'spent' in the arrays below; the screen
//              calls it Committed.)
//   spent      invoices marked PAID, and nothing else (David, 2026-10-29:
//              "Spent so far sounds like money we've actually paid, can we add
//              a commited cost that sums the invoices and the logged time and
//              leave spent so far as only paid invoices?"). Kept as 'paid'.
//   done       approved out of the step's items (seconds on a footage step),
//              the same count the production dashboard draws.
//   forecast   committed ÷ done, once a step is far enough in to mean something;
//              before that, the step's cost in today's projection.
//
// COMPUTED, NEVER STORED, like the projection it reads. Everything comes out of
// expense_projection() and dashboard_steps(), so this block and the tabs under
// it cannot disagree about a rate, a day or an item count.

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/kickoff.php';
require_once __DIR__ . '/dashboard.php';

/**
 * Below this share of the work done, spent ÷ done is noise: the first shots
 * through a step carry its set-up. The step shows its projected cost instead,
 * and says so. The same 15% the Budget pool tab waits for (POOL_PACE_MIN_USED).
 */
const SV_MIN_DONE = 0.15;

/**
 * Below this many items a step cannot be read by pace at all: a whole-project
 * step is ONE item, 0% done until its last day (POOL_PACE_MIN_ITEMS, and MSD's
 * Color Script is the example there).
 */
const SV_MIN_ITEMS = 3.0;

/** Working days without a logged hour before a running step is flagged. */
const SV_LOG_GAP_DAYS = 4;

/** Money in this block is compared to the half cent, as on the rest of the page. */
const SV_CENT = 0.005;

/**
 * The plan each step was signed off at, from the kick-off finance snapshot.
 *
 * Returns ['source' => 'kickoff'|'none', 'date' => ?string, 'steps' => stepId =>
 * cost, 'total' => grand total at sign-off, 'other' => other expenses at
 * sign-off, 'unmatched' => role => cost, 'why' => sentence].
 *
 * ⚠ OLDER SNAPSHOTS NAME A ROW, NOT A STEP. Rows were keyed "role · artist",
 * so they are matched back by the role ("Animation (Shot)") that today's
 * projection gives each step. A step renamed since sign-off cannot be matched,
 * and its plan is REPORTED as unmatched rather than guessed at. Snapshots taken
 * from 2026-10-29 also carry the step id, which is used first.
 */
function sv_signed_off_plan(PDO $pdo, int $projectId, array $proj): array {
    $out = ['source' => 'none', 'date' => null, 'steps' => [], 'total' => null,
            'other' => null, 'unmatched' => [], 'why' => ''];
    if (!kickoff_ready($pdo)) {
        $out['why'] = 'Kick-off sign-off is not set up on this server yet (migration 049)';
        return $out;
    }
    $k = kickoff_approved($pdo, $projectId);
    if (!$k) {
        $out['why'] = 'This show has not been signed off yet';
        return $out;
    }
    $snap = kickoff_finance_load((int)$k['id']);
    $rows = $snap['lists']['Expense projection']['rows'] ?? null;
    if (!$snap || empty($snap['configured']) || !is_array($rows)) {
        $out['why'] = 'The sign-off kept no money figures for this show';
        return $out;
    }

    // role => step id, from today's projection, which builds the role the same
    // way it did at sign-off.
    $roleStep = [];
    foreach ($proj['rows'] as $r) $roleStep[(string)$r['role']] = (int)$r['step']['id'];

    foreach ($rows as $key => $row) {
        $cost = isset($row['cost']) && $row['cost'] !== null ? (float)$row['cost'] : 0.0;
        $sid = isset($row['step_id']) ? (int)$row['step_id'] : null;
        if ($sid === null) {
            $key = (string)$key;
            $cut = strpos($key, ' · ');
            $role = $cut === false ? $key : substr($key, 0, $cut);
            $sid = $roleStep[$role] ?? null;
            if ($sid === null) {
                $out['unmatched'][$role] = ($out['unmatched'][$role] ?? 0.0) + $cost;
                continue;
            }
        }
        $out['steps'][$sid] = ($out['steps'][$sid] ?? 0.0) + $cost;
    }

    $fig = $snap['figures'] ?? [];
    $out['source'] = 'kickoff';
    $out['date']   = substr((string)($k['decided_at'] ?? $k['requested_at'] ?? ''), 0, 10) ?: null;
    $out['other']  = isset($fig['Other expenses'][0]) ? (float)$fig['Other expenses'][0] : 0.0;
    $out['total']  = isset($fig['Projected total'][0]) ? (float)$fig['Projected total'][0]
                   : array_sum($out['steps']) + array_sum($out['unmatched']) + $out['other'];
    return $out;
}

/**
 * Invoiced amount, the PAID part of it, and invoiced DAYS per position, keyed
 * "crewId|stepId". Paid means a paid date is set: marking an invoice paid
 * always fills one in (finance.php), so the date is the one test.
 *
 * The days are what stop logged time being counted twice: a person who has
 * invoiced 10 days and logged 14 has 4 days of work nobody has billed yet.
 * An invoice line from the monthly split carries its quantity in days or
 * hours (IN4, 2026-10-28); an older invoice carries none, and its days are
 * read back from its amount at the position's day rate, in the caller.
 */
function sv_invoiced_by_position(int $projectId, float $hoursPerDay): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db || !finance_column_exists('invoices', 'step_id')) return [];
    $hasQty = finance_column_exists('invoices', 'quantity') && finance_column_exists('invoices', 'quantity_unit');
    try {
        $q = $db->prepare(
            'SELECT crew_id, step_id, currency, amount, paid_date'
            . ($hasQty ? ', quantity, quantity_unit' : '')
            . ' FROM invoices WHERE project_id = ? AND crew_id IS NOT NULL AND step_id IS NOT NULL'
        );
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];
    }
    $fx = project_fx_rates($projectId);
    $out = [];
    foreach ($q as $r) {
        $k = (int)$r['crew_id'] . '|' . (int)$r['step_id'];
        $o = $out[$k] ?? ['amount' => 0.0, 'paid' => 0.0, 'days' => 0.0, 'amount_no_qty' => 0.0];
        $usd = fx_usd_or_own((float)$r['amount'], $r['currency'], $fx)[0];
        $o['amount'] += $usd;
        if (!empty($r['paid_date'])) $o['paid'] += $usd;
        $unit = $hasQty ? (string)($r['quantity_unit'] ?? '') : '';
        $qty  = $hasQty && $r['quantity'] !== null ? (float)$r['quantity'] : null;
        if ($qty !== null && $unit === 'day') {
            $o['days'] += $qty;
        } elseif ($qty !== null && $unit === 'hour' && $hoursPerDay > 0) {
            $o['days'] += $qty / $hoursPerDay;
        } else {
            $o['amount_no_qty'] += $usd;
        }
        $out[$k] = $o;
    }
    return $out;
}

/** The last day anybody logged time on each step, step id => 'Y-m-d'. */
function sv_last_log_by_step(PDO $pdo, int $projectId): array {
    if (!time_logs_available($pdo)) return [];
    try {
        $q = $pdo->prepare(
            'SELECT pr.step_id, MAX(tl.log_date) AS last_day
               FROM time_logs tl JOIN progress pr ON pr.id = tl.progress_id
              WHERE tl.project_id = ? GROUP BY pr.step_id'
        );
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($q as $r) $out[(int)$r['step_id']] = (string)$r['last_day'];
    return $out;
}

/**
 * The whole block: one row per step, the three headline figures, and the
 * sentence that sums them up.
 *
 * @param array      $proj      expense_projection() for the show
 * @param ?float     $budget    the approved production budget, or null
 * @param array      $dashSteps dashboard_steps() for the show
 */
function step_variance(PDO $pdo, array $project, array $proj, ?float $budget, array $dashSteps): array {
    $projectId = (int)$project['id'];
    $today = date('Y-m-d');
    $hpd   = (float)($proj['hours_per_day'] ?? hours_per_day($pdo, $projectId));

    $plan    = sv_signed_off_plan($pdo, $projectId, $proj);
    $billed  = sv_invoiced_by_position($projectId, $hpd);
    $lastLog = sv_last_log_by_step($pdo, $projectId);

    $done = [];
    foreach ($dashSteps as $d) $done[(int)$d['step']['id']] = $d;

    // ---- spent, per row of the projection, then summed per step ------------
    $steps = [];
    foreach ($proj['rows'] as $r) {
        $sid = (int)$r['step']['id'];
        if (!isset($steps[$sid])) {
            $isSup = !empty($r['supervision']);
            $steps[$sid] = [
                'id' => $sid, 'name' => (string)$r['step']['name'], 'role' => (string)$r['role'],
                'department' => (string)$r['department'],
                'order' => (float)($r['step']['step_order'] ?? 0),
                'supervision' => $isSup,
                'start' => (string)($isSup ? $r['start_date'] : ($r['step']['start_date'] ?? '')),
                'end'   => (string)($isSup ? $r['end_date'] : ($r['step']['deadline'] ?? '')),
                'cost_now' => 0.0, 'spent' => 0.0, 'invoiced' => 0.0, 'paid' => 0.0, 'logged_value' => 0.0,
                'people' => 0, 'unpriced_logged' => 0.0,
            ];
        }
        $s =& $steps[$sid];
        $cost = (float)($r['cost'] ?? 0);
        $s['cost_now'] += $cost;
        if ($r['crew_id'] && empty($r['is_slot'])) $s['people']++;

        $k = ($r['crew_id'] ? (int)$r['crew_id'] : 0) . '|' . $sid;
        $inv = $billed[$k] ?? ['amount' => 0.0, 'paid' => 0.0, 'days' => 0.0, 'amount_no_qty' => 0.0];
        $unit = (string)($r['rate_unit'] ?? '');
        $rate = $r['rate'] !== null ? (float)($r['rate_usd'] ?? $r['rate']) : null;
        $dayRate = $rate === null ? null : ($unit === 'hour' ? $rate * $hpd : ($unit === 'day' ? $rate : null));

        if (!empty($r['supervision'])) {
            // A person for a stretch of dates: the dates ARE the work, so here,
            // and only here, the calendar is the measure.
            $f = sv_dates_fraction($s['start'], $s['end'], $today);
            $earned = $cost * $f;
            $spent = max($inv['amount'], $earned);
            $s['logged_value'] += max(0.0, $spent - $inv['amount']);
        } elseif ($dayRate !== null) {
            // Paid by the day or the hour: invoiced, plus logged days nobody has
            // invoiced yet at the rate. An invoice with no quantity stands for
            // amount ÷ rate days.
            $invDays = $inv['days'] + ($dayRate > 0 ? $inv['amount_no_qty'] / $dayRate : 0.0);
            $extra = max(0.0, (float)$r['actual_days'] - $invDays) * $dayRate;
            $spent = $inv['amount'] + $extra;
            $s['logged_value'] += $extra;
        } elseif ($rate !== null) {
            // Per second or a flat fee: no days to price, so the share of the
            // fee the approved work has earned, or the invoices if more.
            $d = $done[$sid] ?? null;
            $f = ($d && $d['target'] > 0) ? min(1.0, $d['approved_v'] / $d['target']) : 0.0;
            $spent = max($inv['amount'], $cost * $f);
            $s['logged_value'] += max(0.0, $spent - $inv['amount']);
        } else {
            // No rate: only what was invoiced can be counted. Logged days are
            // reported, never priced at a guess.
            $spent = $inv['amount'];
            $s['unpriced_logged'] += (float)$r['actual_days'];
        }
        $s['invoiced'] += $inv['amount'];
        $s['paid'] += $inv['paid'];
        $s['spent'] += $spent;
        unset($s);
    }

    // ---- per step: done, forecast, result ----------------------------------
    $rows = [];
    $locked = 0.0; $expected = 0.0; $saved = 0.0;
    $lockedSteps = []; $expectedSteps = []; $savedSteps = [];
    $spentTotal = 0.0; $invoicedTotal = 0.0; $paidTotal = 0.0; $forecastTotal = 0.0; $planTotal = 0.0;
    $earnedPlan = 0.0;
    $logGaps = [];
    foreach ($steps as $sid => $s) {
        foreach (['cost_now', 'spent', 'invoiced', 'paid', 'logged_value'] as $f) $s[$f] = round($s[$f], 2);
        $p = $plan['source'] === 'kickoff' ? ($plan['steps'][$sid] ?? null) : $s['cost_now'];
        $s['plan'] = $p;
        $s['new_since_signoff'] = $plan['source'] === 'kickoff' && $p === null;
        $p = (float)$p;

        if ($s['supervision']) {
            $frac = sv_dates_fraction($s['start'], $s['end'], $today);
            $s['done_text'] = $s['start'] && $s['end']
                ? 'day ' . working_days_between($s['start'], min($today, $s['end'])) . ' of ' . working_days_between($s['start'], $s['end'])
                : 'no dates';
            $items = 99.0;   // dates, not items: always readable
        } else {
            $d = $done[$sid] ?? null;
            $target = $d ? (float)$d['target'] : 0.0;
            $frac = $target > 0 ? min(1.0, (float)$d['approved_v'] / $target) : 0.0;
            $items = $target;
            $noun = $d && $d['unit'] === 'sec' ? 's' : '';
            $s['done_text'] = $d
                ? fmt_num($d['approved_v'], 0) . $noun . ' of ' . fmt_num($target, 0) . $noun
                : 'no items';
        }
        $s['done'] = $frac;

        if ($frac >= 1.0 - 1e-9 && ($s['supervision'] ? $s['end'] !== '' && $s['end'] < $today : $items > 0)) {
            $state = 'finished';
            $forecast = $s['spent'];
            $readable = true;
        } elseif ($frac <= 0 && $s['spent'] < SV_CENT) {
            $state = 'not_started';
            $forecast = $s['cost_now'];
            $readable = false;
        } else {
            $state = 'running';
            // ⚠ WORK APPROVED BUT NOTHING SPENT is missing time logs, not free
            // work: spent ÷ done would forecast the step at zero.
            $readable = $s['supervision']
                     || ($frac >= SV_MIN_DONE && $items >= SV_MIN_ITEMS && $s['spent'] >= SV_CENT);
            $forecast = $s['supervision'] ? max($s['cost_now'], $s['spent'])
                      : ($readable ? $s['spent'] / $frac : max($s['cost_now'], $s['spent']));
        }
        $forecast = round($forecast, 2);
        $s['state'] = $state;
        $s['readable'] = $readable;
        $s['forecast'] = $forecast;
        $s['why_unreadable'] = $readable ? '' : ($state === 'not_started' ? 'not started'
            : ($items > 0 && $items < SV_MIN_ITEMS ? 'too few items to read by pace'
            : ($frac >= SV_MIN_DONE && $s['spent'] < SV_CENT ? 'work approved, nothing logged or invoiced'
                                                              : 'too early to read: needs 15% done')));

        $diff = round($forecast - $p, 2);
        $s['diff'] = $diff;
        // ⚠ A SAVING COUNTS ONLY ONCE IT IS INVOICED. A finished step nobody
        // logged against would otherwise hand its whole plan to the reserve, and
        // logged days are an estimate until the invoice arrives. An overrun
        // counts at once, a saving waits: the cautious way round.
        $s['saving_pending'] = false;
        if ($state === 'finished') {
            if ($diff > SV_CENT) {
                $locked += $diff; $lockedSteps[] = [$s['name'], $diff];
            } elseif ($diff < -SV_CENT) {
                if ($s['spent'] < SV_CENT || $s['logged_value'] > SV_CENT) {
                    $s['saving_pending'] = true;
                } else {
                    $saved += -$diff; $savedSteps[] = [$s['name'], -$diff];
                }
            }
        } elseif ($diff > SV_CENT) {
            $expected += $diff; $expectedSteps[] = [$s['name'], $diff];
        }

        // ⚠ BOOKED BUT NOBODY LOGGING. Spent comes from logged time, so a step
        // whose people stop logging looks cheaper than it is. Named rather than
        // guessed at: artists log every day (David, 2026-10-28). Not-started
        // counts too: inside its dates with people on it and nothing logged is
        // exactly this case.
        if ($state !== 'finished' && !$s['supervision'] && $s['people'] > 0
            && $s['start'] !== '' && $s['start'] <= $today && ($s['end'] === '' || $s['end'] >= $today)) {
            $last = $lastLog[$sid] ?? null;
            $gap = $last === null ? null : working_days_between($last, $today) - 1;
            if ($last === null || $gap >= SV_LOG_GAP_DAYS) {
                $logGaps[] = ['name' => $s['name'], 'people' => $s['people'], 'last' => $last, 'gap' => $gap];
            }
        }

        $spentTotal += $s['spent']; $invoicedTotal += $s['invoiced']; $paidTotal += $s['paid'];
        $forecastTotal += $forecast; $planTotal += $p;
        $earnedPlan += $p * $frac;
        $rows[$sid] = $s;
    }

    // ---- other expenses: software, travel, render time ---------------------
    // No items and no pace, so entered is spent and spent is the forecast. Kept
    // as its own line so an overrun on it is still never netted against a step.
    $otherNow  = round((float)($proj['other_total'] ?? 0), 2);
    $otherPaid = round((float)($proj['other_paid'] ?? 0), 2);
    $otherPlan = $plan['source'] === 'kickoff' ? (float)$plan['other'] : $otherNow;
    if ($otherNow > SV_CENT || $otherPlan > SV_CENT) {
        $diff = round($otherNow - $otherPlan, 2);
        $rows['other'] = [
            'id' => 0, 'name' => 'Other expenses', 'role' => '', 'department' => 'Other',
            'order' => 9999, 'supervision' => false, 'start' => '', 'end' => '',
            'plan' => $otherPlan, 'new_since_signoff' => false, 'cost_now' => $otherNow,
            'spent' => $otherNow, 'invoiced' => 0.0, 'paid' => $otherPaid, 'logged_value' => 0.0, 'people' => 0,
            'unpriced_logged' => 0.0, 'done' => null, 'done_text' => 'software, travel, services',
            'state' => 'running', 'readable' => true, 'why_unreadable' => '', 'saving_pending' => false,
            'forecast' => $otherNow, 'diff' => $diff,
        ];
        if ($diff > SV_CENT) { $expected += $diff; $expectedSteps[] = ['Other expenses', $diff]; }
        $spentTotal += $otherNow; $paidTotal += $otherPaid; $forecastTotal += $otherNow; $planTotal += $otherPlan;
        $earnedPlan += $otherPlan;   // counted as done: it is already entered
    }

    // The plan the reserve is measured from: the signed-off grand total when
    // there is one (it includes any role that no longer matches a step).
    $planBase = $plan['source'] === 'kickoff' ? (float)$plan['total'] : $planTotal;
    $reserve = $budget !== null ? round($budget - $planBase + $saved, 2) : null;
    $left    = $reserve !== null ? round($reserve - $locked - $expected, 2) : null;

    $byState = ['finished' => [], 'running' => [], 'not_started' => []];
    foreach ($rows as $k => $r) $byState[$r['state']][$k] = $r;

    return [
        'plan_source' => $plan['source'], 'plan_date' => $plan['date'], 'plan_why' => $plan['why'],
        'unmatched'   => $plan['unmatched'],
        'budget'      => $budget,
        'plan_total'  => round($planBase, 2),
        'groups'      => $byState,
        'locked'      => round($locked, 2),   'locked_steps'   => $lockedSteps,
        'expected'    => round($expected, 2), 'expected_steps' => $expectedSteps,
        'saved'       => round($saved, 2),    'saved_steps'    => $savedSteps,
        'reserve'     => $reserve,
        'reserve_base'=> $budget !== null ? round($budget - $planBase, 2) : null,
        'left'        => $left,
        'spent'       => round($spentTotal, 2),
        'invoiced'    => round($invoicedTotal, 2),
        // Invoices marked paid, plus other expenses with a paid date: money
        // that has actually left. 'spent' above is the commitment.
        'paid'        => round($paidTotal, 2),
        'forecast'    => round($forecastTotal + array_sum($plan['unmatched']), 2),
        'pct_done'    => $planTotal > 0 ? $earnedPlan / $planTotal : null,
        'log_gaps'    => $logGaps,
        'unpriced_logged' => array_sum(array_column($rows, 'unpriced_logged')),
    ];
}

/** How far through its dates a supervision position is, 0..1. */
function sv_dates_fraction(string $start, string $end, string $today): float {
    if ($start === '' || $end === '' || $today < $start) return 0.0;
    $all = working_days_between($start, $end);
    if ($all <= 0) return 0.0;
    return min(1.0, working_days_between($start, min($today, $end)) / $all);
}

/**
 * The one sentence at the top. Plain words, the figures in it, and never
 * "under budget" while a step has already gone over.
 */
function step_variance_verdict(array $v): array {
    $m = fn(float $x) => fmt_money_plain($x, 'USD');
    if ($v['budget'] === null) {
        return ['muted', 'No approved budget yet', 'Each step is shown against its plan, but there is no budget to measure the reserve against.'];
    }
    $lockedNames   = implode(' and ', array_map(fn($x) => $x[0], $v['locked_steps']));
    $expectedNames = implode(' and ', array_map(fn($x) => $x[0], $v['expected_steps']));
    $parts = [];
    if ($v['locked'] > SV_CENT) {
        $parts[] = $lockedNames . ' already cost ' . $m($v['locked']) . ' more than planned, and that cannot be won back.';
    }
    if ($v['expected'] > SV_CENT) {
        $parts[] = $expectedNames . ' ' . (count($v['expected_steps']) === 1 ? 'is' : 'are')
                 . ' heading ' . $m($v['expected']) . ' over at the pace ' . (count($v['expected_steps']) === 1 ? 'it is' : 'they are') . ' actually going.';
    }
    $reserve = (float)$v['reserve'];
    $left = (float)$v['left'];
    if ($left < -SV_CENT) {
        $title = $v['locked'] > $reserve + SV_CENT ? 'Over budget already' : 'Heading over budget by ' . $m(-$left);
        $parts[] = 'That is more than the ' . $m(max(0.0, $reserve)) . ' reserve: the show needs more money or a cut.';
        return ['err', $title, implode(' ', $parts)];
    }
    if ($v['locked'] + $v['expected'] < SV_CENT) {
        return ['ok', 'On plan', 'No step has gone over its plan, and none is heading over at its current pace. Reserve: ' . $m($left) . '.'];
    }
    $tight = $reserve > 0 && $left < 0.25 * $reserve;
    $parts[] = 'That leaves ' . $m($left) . ' of the ' . $m($reserve) . ' reserve'
             . ($tight ? ': one more slip and the show is over budget.' : '.');
    return ['warn', $tight ? 'Not over budget yet, but the reserve is almost gone' : 'Within budget, but steps are over their plan',
            implode(' ', $parts)];
}

/** Width, in percent of the bar, of an amount on a bar whose plan line sits at 62.5%. */
function sv_bar_pct(float $amount, float $plan, float $scaleTo): float {
    $base = $plan > SV_CENT ? $plan / 0.625 : max($scaleTo, 1.0);
    return max(0.0, min(100.0, $amount / $base * 100));
}

/**
 * Draw the block. Kept here rather than in finance_project.php, which most
 * finance work touches, so the Overview gains one call and not a page of HTML.
 */
function render_step_variance(array $v): void {
    $m = fn(?float $x) => fmt_money_plain($x, 'USD');
    [$vClass, $vTitle, $vText] = step_variance_verdict($v);
    $groups = ['finished' => 'Finished', 'running' => 'Running', 'not_started' => 'Not started'];
    $reserve = (float)($v['reserve'] ?? 0);
    $resPct = function (float $x) use ($reserve): float {
        return $reserve > SV_CENT ? max(0.0, min(100.0, $x / $reserve * 100)) : 0.0;
    };
    ?>
<h3>Is the money on track <span class="muted">· every step against its own plan, never netted against another</span></h3>

<div class="sv-verdict sv-<?= e($vClass) ?>">
  <strong><?= e($vTitle) ?></strong>
  <p><?= e($vText) ?></p>
</div>

<?php if ($v['plan_source'] !== 'kickoff'): ?>
  <p class="warn-text small"><?= e($v['plan_why']) ?>, so each step is judged against <strong>today's</strong>
    expense projection, which moves whenever the plan does. Once the show is signed off, every step keeps
    the plan it was approved at.</p>
<?php endif; ?>

<div class="sv-heads">
  <div class="sv-head<?= $v['locked'] > SV_CENT ? ' sv-locked' : '' ?>">
    <span class="sv-lbl">Overrun locked in</span>
    <span class="sv-big"><?= e($m($v['locked'])) ?></span>
    <span class="muted small">finished steps that cost more than their plan</span>
    <?php if ($v['locked_steps']): ?><ul>
      <?php foreach ($v['locked_steps'] as [$n, $x]): ?><li><?= e($n) ?> +<?= e($m($x)) ?></li><?php endforeach; ?>
    </ul><?php endif; ?>
  </div>
  <div class="sv-head<?= $v['expected'] > SV_CENT ? ' sv-risk' : '' ?>">
    <span class="sv-lbl">Overrun expected</span>
    <span class="sv-big"><?= e($m($v['expected'])) ?></span>
    <span class="muted small">running steps, at the pace they are actually going</span>
    <?php if ($v['expected_steps']): ?><ul>
      <?php foreach ($v['expected_steps'] as [$n, $x]): ?><li><?= e($n) ?> +<?= e($m($x)) ?></li><?php endforeach; ?>
    </ul><?php endif; ?>
  </div>
  <div class="sv-head<?= $v['left'] !== null && $v['left'] < -SV_CENT ? ' sv-locked' : '' ?>">
    <span class="sv-lbl">Reserve left</span>
    <?php if ($v['reserve'] === null): ?>
      <span class="sv-big">—</span><span class="muted small">no approved budget yet</span>
    <?php else: ?>
      <span class="sv-big"><?= $v['left'] < -SV_CENT ? 'over by ' . e($m(-$v['left'])) : e($m($v['left'])) ?></span>
      <span class="muted small">of <?= e($m($reserve)) ?></span>
      <?php if ($reserve > SV_CENT): ?>
        <div class="sv-reserve" title="Reserve <?= e($m($reserve)) ?>">
          <i class="sv-r-locked" style="width:<?= e(fmt_num($resPct($v['locked']), 1)) ?>%"></i><i
             class="sv-r-risk" style="width:<?= e(fmt_num($resPct($v['expected']), 1)) ?>%"></i><i
             class="sv-r-left" style="width:<?= e(fmt_num($resPct(max(0.0, (float)$v['left'])), 1)) ?>%"></i>
        </div>
      <?php endif; ?>
      <span class="muted small"><?= e($m($v['reserve_base'])) ?> between the approved budget and the plan<?php
        if ($v['saved'] > SV_CENT): ?>, + <?= e($m($v['saved'])) ?> that finished steps saved<?php endif; ?>.</span>
    <?php endif; ?>
  </div>
</div>

<div class="dash-strip">
  <div><span class="muted small">Approved budget</span>
    <strong><?= e($m($v['budget'])) ?></strong></div>
  <div><span class="muted small">Plan <?= $v['plan_source'] === 'kickoff' ? 'at sign-off' : 'today' ?></span>
    <strong><?= e($m($v['plan_total'])) ?></strong>
    <?php if ($v['plan_date']): ?><span class="muted small">signed off <?= e($v['plan_date']) ?></span><?php endif; ?></div>
  <?php // TWO FIGURES, KEPT APART (David, 2026-10-29). "Spent" is money that has
        // actually left: paid invoices and paid expenses. "Committed" is what the
        // show owes for the work done so far, paid or not: every invoice plus
        // logged days nobody has invoiced yet. The forecast and the results below
        // are built on Committed, because an unpaid invoice is still a cost. ?>
  <div><span class="muted small">Committed so far</span>
    <strong><?= e($m($v['spent'])) ?></strong>
    <span class="muted small"><?= e($m($v['invoiced'])) ?> invoiced, the rest logged and not invoiced yet</span></div>
  <div><span class="muted small">Spent so far</span>
    <strong><?= e($m($v['paid'])) ?></strong>
    <span class="muted small">invoices paid<?php
      $unpaid = (float)$v['invoiced'] - (float)$v['paid'];
      if ($unpaid > SV_CENT): ?>; <?= e($m($unpaid)) ?> invoiced, not paid yet<?php endif; ?></span></div>
  <div><span class="muted small">Forecast at the real pace</span>
    <strong class="<?= $v['budget'] !== null && $v['forecast'] > $v['budget'] + SV_CENT ? 'err' : '' ?>"><?= e($m($v['forecast'])) ?></strong>
    <?php if ($v['pct_done'] !== null): ?><span class="muted small"><?= e(fmt_num($v['pct_done'] * 100, 0)) ?>% of the work done, weighted by plan</span><?php endif; ?></div>
</div>

<div class="sv-table">
<table>
  <thead><tr>
    <th>Step</th><th class="num">Plan</th><th class="num">Committed</th><th>Work done</th>
    <th>Committed against plan</th><th class="num">Forecast at the end</th><th>Result</th>
  </tr></thead>
  <tbody>
  <?php foreach ($groups as $g => $label): if (!$v['groups'][$g]) continue; ?>
    <tr class="sv-group"><td colspan="7"><?= e($label) ?></td></tr>
    <?php foreach ($v['groups'][$g] as $r):
        $plan = (float)($r['plan'] ?? 0);
        $scaleTo = max((float)$r['forecast'], (float)$r['spent']);
        $spentIn = $plan > SV_CENT ? min((float)$r['spent'], $plan) : (float)$r['spent'];
        $over = $plan > SV_CENT ? max(0.0, (float)$r['spent'] - $plan) : 0.0;
        $fcFrom = (float)$r['spent'];
        $fcTo = max($fcFrom, (float)$r['forecast']);
        $fcBad = $r['forecast'] > $plan + SV_CENT; ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong>
        <?php if ($r['new_since_signoff']): ?><span class="sv-sub warn-text">not in the plan at sign-off</span>
        <?php elseif ($r['supervision']): ?><span class="sv-sub">supervision, by dates</span>
        <?php elseif ($r['people'] > 0): ?><span class="sv-sub"><?= (int)$r['people'] ?> <?= $r['people'] === 1 ? 'person' : 'people' ?></span><?php endif; ?></td>
      <td class="num"><?= $r['plan'] === null ? '—' : e($m($plan)) ?></td>
      <td class="num"><?= $r['spent'] > SV_CENT ? e($m($r['spent'])) : '<span class="muted">0.00</span>' ?>
        <?php if ($r['invoiced'] > SV_CENT && $r['logged_value'] > SV_CENT): ?>
          <span class="sv-sub"><?= e($m($r['invoiced'])) ?> invoiced + <?= e($m($r['logged_value'])) ?> not yet</span>
        <?php elseif ($r['logged_value'] > SV_CENT): ?><span class="sv-sub">not invoiced yet</span>
        <?php elseif ($r['invoiced'] > SV_CENT): ?><span class="sv-sub">invoiced</span><?php endif; ?>
        <?php if ($r['paid'] > SV_CENT): ?><span class="sv-sub"><?= e($m($r['paid'])) ?> paid</span><?php endif; ?>
        <?php if ($r['unpriced_logged'] > 0.05): ?><span class="sv-sub warn-text"><?= e(fmt_num($r['unpriced_logged'], 1)) ?> logged days with no rate</span><?php endif; ?></td>
      <td><?= e($r['done_text']) ?><?php if ($r['done'] !== null): ?> · <?= e(fmt_num($r['done'] * 100, 0)) ?>%
        <div class="sv-donebar"><i style="width:<?= e(fmt_num($r['done'] * 100, 1)) ?>%"></i></div><?php endif; ?></td>
      <td><div class="sv-bar">
        <span class="sv-spent" style="width:<?= e(fmt_num(sv_bar_pct($spentIn, $plan, $scaleTo), 1)) ?>%"></span>
        <?php if ($over > SV_CENT): ?><span class="sv-over" style="left:62.5%;width:<?= e(fmt_num(sv_bar_pct($over, $plan, $scaleTo), 1)) ?>%"></span><?php endif; ?>
        <?php if ($fcTo - $fcFrom > SV_CENT): ?><span class="sv-fc<?= $fcBad ? ' sv-fc-bad' : '' ?>"
              style="left:<?= e(fmt_num(sv_bar_pct($fcFrom, $plan, $scaleTo), 1)) ?>%;width:<?= e(fmt_num(sv_bar_pct($fcTo - $fcFrom, $plan, $scaleTo), 1)) ?>%"></span><?php endif; ?>
        <?php if ($plan > SV_CENT): ?><span class="sv-plan"></span><?php endif; ?>
      </div></td>
      <td class="num <?= $r['state'] !== 'finished' && $fcBad ? 'warn-text' : '' ?>"><?= e($m($r['forecast'])) ?>
        <?php if (!$r['readable'] && $r['state'] !== 'finished'): ?><span class="sv-sub">today's projection</span><?php endif; ?></td>
      <td>
        <?php if ($r['state'] === 'finished' && $r['diff'] > SV_CENT): ?>
          <span class="sv-chip sv-chip-locked">+<?= e($m($r['diff'])) ?> locked in</span>
          <?php if ($plan > SV_CENT): ?><span class="sv-sub"><?= e(fmt_num($r['diff'] / $plan * 100, 0)) ?>% over</span><?php endif; ?>
        <?php elseif ($r['state'] === 'finished' && $r['diff'] < -SV_CENT && $r['saving_pending']): ?>
          <span class="sv-chip"><?= e($m(-$r['diff'])) ?> under so far</span>
          <span class="sv-sub">goes to the reserve once it is all invoiced</span>
        <?php elseif ($r['state'] === 'finished' && $r['diff'] < -SV_CENT): ?>
          <span class="sv-chip sv-chip-saved"><?= e($m(-$r['diff'])) ?> saved → reserve</span>
        <?php elseif ($r['state'] === 'finished'): ?>
          <span class="sv-chip">on plan</span>
        <?php elseif ($r['diff'] > SV_CENT): ?>
          <span class="sv-chip sv-chip-risk">+<?= e($m($r['diff'])) ?> expected</span>
          <?php if ($r['readable'] && $plan > SV_CENT && $r['done'] !== null): ?><span class="sv-sub"><?=
            e(fmt_num($r['spent'] / $plan * 100, 0)) ?>% of plan committed, <?= e(fmt_num($r['done'] * 100, 0)) ?>% done</span><?php endif; ?>
        <?php elseif (!$r['readable']): ?>
          <span class="sv-chip sv-chip-early"><?= e($r['why_unreadable']) ?></span>
        <?php else: ?>
          <span class="sv-chip">on plan</span>
          <?php if ($r['diff'] < -SV_CENT): ?><span class="sv-sub">may finish <?= e($m(-$r['diff'])) ?> under; counted once it does</span><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr>
    <td>Whole show</td><td class="num"><?= e($m($v['plan_total'])) ?></td><td class="num"><?= e($m($v['spent'])) ?></td>
    <td><?= $v['pct_done'] !== null ? e(fmt_num($v['pct_done'] * 100, 0)) . '% of the work' : '' ?></td><td></td>
    <td class="num"><?= e($m($v['forecast'])) ?></td>
    <td class="muted">budget <?= e($m($v['budget'])) ?></td>
  </tr></tfoot>
</table>
</div>
<div class="sv-legend muted small">
  <span><i class="sv-k-spent"></i>committed so far</span>
  <span><i class="sv-k-over"></i>committed beyond the plan</span>
  <span><i class="sv-k-fc"></i>still to commit (forecast)</span>
  <span><i class="sv-k-fc-bad"></i>forecast past the plan</span>
  <span><i class="sv-k-plan"></i>the plan</span>
</div>

<?php foreach ($v['log_gaps'] as $gp): ?>
  <div class="flash flash-warn"><strong><?= e($gp['name']) ?>:</strong>
    <?= (int)$gp['people'] ?> <?= $gp['people'] === 1 ? 'person is' : 'people are' ?> on it,
    <?= $gp['last'] === null ? 'and nobody has logged any time on it yet'
          : 'but nobody has logged time on it for ' . (int)$gp['gap'] . ' working days' ?>.
    Committed may be too low until they log.</div>
<?php endforeach; ?>

<?php if ($v['unmatched']): ?>
  <div class="flash flash-warn">
    <?= e(implode(', ', array_keys($v['unmatched']))) ?> <?= count($v['unmatched']) === 1 ? 'was' : 'were' ?> in the
    plan at sign-off (<?= e($m(array_sum($v['unmatched']))) ?>) but <?= count($v['unmatched']) === 1 ? 'is' : 'are' ?>
    not a step today: renamed or removed. That money stays in the plan total and in the forecast, and is
    not matched to any step above.</div>
<?php endif; ?>

<details class="sv-how">
  <summary>How these numbers are worked out</summary>
  <ul>
    <li><strong>Plan:</strong> each step's cost in the expense projection signed off at kick-off. It does not move
      afterwards; a step added later has no plan, so all of its cost counts as over.</li>
    <li><strong>Spent so far:</strong> invoices marked paid, and other expenses with a paid date: money that has
      actually left, nothing else.</li>
    <li><strong>Committed:</strong> for each person on each step, what they have invoiced (paid or not), plus the days
      they have logged and not invoiced yet at their rate. Never "start date until today": the calendar measures time, not
      money. People paid per second or per job count the share of their fee the approved work has earned.
      Supervision counts its dates.</li>
    <li><strong>Work done:</strong> approved items out of the step's items (seconds on a footage step), the same count
      as the production dashboard.</li>
    <li><strong>Forecast at the end:</strong> committed ÷ work done. Below 15% done, or with fewer than 3 items, the step
      shows today's projection instead, because the first items through a step carry its set-up.</li>
    <li><strong>Locked in</strong> is a finished step's committed cost above its plan; <strong>expected</strong> is a running
      step's forecast above its plan. <strong>Reserve</strong> is the approved budget less the plan, plus what finished
      steps saved. A saving reaches the reserve only once the step is finished and all of it is invoiced; a running
      step that looks cheap adds nothing. None of these are netted against each other.</li>
  </ul>
</details>
<?php
}
