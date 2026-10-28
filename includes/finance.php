<?php
/**
 * The money side: budgets, the expense projection, and invoices.
 *
 * ADMIN ONLY, and it goes through includes/money.php — the one place the finance
 * database connection is ever opened. Nothing here names that connection itself.
 *
 * THE EXPENSE PROJECTION IS COMPUTED, NEVER STORED.
 * It comes out of production data every time it is asked for: pipeline steps,
 * their person-days, the lanes staffed on them, multiplied by the rates in
 * crew_terms. Storing it would create a second number that goes stale the moment
 * somebody changes a headcount — and the entire reason production and finance are
 * joined on stable ids is so the money side reads the CURRENT truth rather than a
 * copy of an old one. Only what cannot be derived is stored: the agreed budget,
 * and what has actually been invoiced.
 *
 * The consequence worth knowing: a projection is only as complete as the rates
 * behind it. A step staffed by three unfilled slots has no rate to multiply, and
 * the screen says how much of the plan it could not price rather than quietly
 * totalling the part it could. A cost that looks low because half of it is
 * missing is worse than no cost at all.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/crew.php';
require_once __DIR__ . '/timelog.php';   // hours_per_day(), for converting an hourly rate
require_once __DIR__ . '/standards.php'; // the IDEAL quota, which the budget is built on
require_once __DIR__ . '/fx.php';        // the project's fixed exchange rates

/** The agreed budget for one project, or null. Empty for a non-admin. */
function project_budget(int $projectId): ?array {
    if (!can_see_money()) return null;
    $db = finance_db();
    if (!$db) return null;
    $q = $db->prepare('SELECT * FROM project_budgets WHERE project_id = ?');
    $q->execute([$projectId]);
    return $q->fetch() ?: null;
}

/** Budgets for many projects at once, keyed by project id. */
function project_budgets(array $projectIds): array {
    if (!can_see_money() || !$projectIds) return [];
    $db = finance_db();
    if (!$db) return [];
    $ids = implode(',', array_map('intval', $projectIds));
    $out = [];
    foreach ($db->query("SELECT * FROM project_budgets WHERE project_id IN ({$ids})") as $r) {
        $out[(int)$r['project_id']] = $r;
    }
    return $out;
}

/**
 * Every change made to a project's budget after kick-off sign-off, newest
 * first. Empty for a non-admin, and before finance_install_10.sql is imported.
 */
function project_budget_changes(int $projectId): array {
    if (!can_see_money() || !finance_column_exists('budget_changes', 'reason')) return [];
    $db = finance_db();
    if (!$db) return [];
    $q = $db->prepare('SELECT * FROM budget_changes WHERE project_id = ? ORDER BY changed_at DESC, id DESC');
    $q->execute([$projectId]);
    return $q->fetchAll();
}

/**
 * Save the agreed budget. Returns a sentence for the flash message.
 *
 * $lock is budget_lock() from includes/budget_lock.php: null while the show is
 * not signed off (saved as it always was), otherwise the save is refused unless
 * the account may change a signed-off budget, needs a reason, and writes one
 * budget_changes row (finance_install_10.sql) in the same transaction.
 */
function save_project_budget(int $projectId, array $project, array $in, string $who = '', ?array $lock = null): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can change a budget.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet, see finance_install.sql.');

    $raw = trim((string)($in['budget_amount'] ?? ''));
    if ($raw === '') {
        $amount = null;
    } else {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $raw . '" is not a number, nothing was saved.');
        }
        $amount = round((float)$clean, 2);
        if ($amount < 0) throw new RuntimeException('A budget cannot be negative.');
    }

    // ⚠ REVENUE WAS REMOVED HERE ON 2026-10-12, one week after it went in.
    // David: "we'll delete the total revenue and only going to keep only these"
    // — and the list that followed is seven figures about COST, with no client
    // money among them. The Overview answers "are we inside what Jorik approved
    // and how much of it have we spent"; what the client is billed is a
    // different question with a different audience, and a figure sitting on the
    // panel taking part in no sum was an invitation to read it as profit.
    //
    // The `revenue_amount` COLUMN IS LEFT IN PLACE in the finance database, and
    // nothing reads or writes it any more. Dropping it would need a hand-run
    // statement in phpMyAdmin and would throw away the figure already typed
    // there; leaving it costs nothing and keeps that number recoverable.

    $cur = strtoupper(trim((string)($in['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    // ⚠ TWO STATEMENTS, CHOSEN BY WHAT THE DATABASE ACTUALLY HAS. Naming a
    // column that is not there is a fatal error on every save, which would take
    // the whole budget form down for the sake of one optional field.
    $cols = 'project_id, project_code, project_name, budget_amount, currency, terms, notes, updated_by';
    $marks = '?, ?, ?, ?, ?, ?, ?, ?';
    $upd  = 'project_code = VALUES(project_code), project_name = VALUES(project_name), '
          . 'budget_amount = VALUES(budget_amount), currency = VALUES(currency), '
          . 'terms = VALUES(terms), notes = VALUES(notes), updated_by = VALUES(updated_by)';
    $terms = trim((string)($in['terms'] ?? '')) ?: null;
    $notes = trim((string)($in['notes'] ?? '')) ?: null;
    $args = [
        $projectId, $project['code'] ?? null, $project['name'] ?? null,
        $amount, $cur, $terms, $notes, $who,
    ];
    $upsert = $db->prepare(
        'INSERT INTO project_budgets (' . $cols . ') VALUES (' . $marks . ')
         ON DUPLICATE KEY UPDATE ' . $upd
    );

    if ($lock === null) {
        $upsert->execute($args);
    } else {
        // SIGNED OFF: the approved figure is fixed (David, 2026-10-28). Checked
        // here as well as by the read-only form, because a form is only a
        // suggestion to whoever sends the POST.
        if (!$lock['may_change']) {
            throw new RuntimeException('This budget was fixed when the kick-off was signed off. '
                . ($lock['problem'] ?? ($lock['changers']
                    ? 'Only ' . implode(' or ', $lock['changers']) . ' can change it.'
                    : 'Nobody is ticked to change it yet ("Changes budget", on the Kick-off page under Who signs off).'))
                . ' Nothing was saved.');
        }
        // A change that cannot be recorded is not made: the history is half
        // of what was asked for.
        if (!finance_column_exists('budget_changes', 'reason')) {
            throw new RuntimeException('A signed-off budget can only be changed once finance_install_10.sql has been '
                . 'imported (by hand, in phpMyAdmin), so the change can be recorded. Nothing was saved.');
        }
        $reason = trim((string)($in['change_reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Say in a few words why the signed-off budget is changing. Nothing was saved.');
        }
        $reason = mb_substr($reason, 0, 500);

        $q = $db->prepare('SELECT budget_amount, currency, terms, notes FROM project_budgets WHERE project_id = ?');
        $q->execute([$projectId]);
        $old = $q->fetch() ?: ['budget_amount' => null, 'currency' => null, 'terms' => null, 'notes' => null];
        $oldAmount = $old['budget_amount'] !== null ? round((float)$old['budget_amount'], 2) : null;

        $also = [];
        if ((string)($old['terms'] ?? '') !== (string)$terms) $also[] = 'terms';
        if ((string)($old['notes'] ?? '') !== (string)$notes) $also[] = 'notes';
        $amountChanged = $oldAmount !== $amount || (string)($old['currency'] ?? '') !== $cur;
        if (!$amountChanged && !$also) {
            throw new RuntimeException('Nothing on the budget is different from what is saved, so nothing was recorded.');
        }

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO budget_changes (project_id, kickoff_id, old_amount, new_amount, old_currency,
                    new_currency, also_changed, reason, changed_by_user_id, changed_by, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $projectId, $lock['kickoff_id'] ?: null, $oldAmount, $amount, $old['currency'], $cur,
                $also ? implode(' and ', $also) : null, $reason,
                $lock['user_id'] ?: null, $who !== '' ? $who : 'unknown', date('Y-m-d H:i:s'),
            ]);
            $upsert->execute($args);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    $said = $amount === null
        ? 'Approved production budget cleared.'
        : 'Approved production budget saved: ' . fmt_money_plain($amount, $cur) . '.';
    return $said;
}

/**
 * The expense projection — one row per PERSON, in the shape of David's own
 * spreadsheet (RTC_ExpenseProjection_v01.xlsx, read 2026-08-18).
 *
 * That sheet is the specification, and its shape is deliberate: a row is not a
 * step, it is a person on a step. "Layout (Environment)" with two artists is two
 * rows, because they are two people who each get paid — and a step with three
 * animators costs three times what one does. Grouping by department with
 * subtotals is how the sheet gets read out loud in a budget meeting.
 *
 * Columns, mapped from the sheet:
 *   Department      pipeline_steps.department (migration 015)
 *   Role / Position step name + its scope — "Concept (Character)"
 *   Artist Name     the crew row in that lane, a real hire or a planning slot
 *   Rate type/Rate  crew_terms, from the finance database
 *   Total days      that lane's share of the step's person-days, rounded UP to a
 *                   whole day (2026-10-21)
 *   Total months    days / 22, the sheet's own divisor
 *   Projected cost  rate x days
 *   Units           how many items the step covers
 *   Actual days     from time_logs, converted at the project's hours per day
 *
 * COMPUTED, NEVER STORED — see the file header.
 */

/** Working days in a month, matching the sheet's own divisor (4 / 0.1818 = 22). */
const DAYS_PER_MONTH = 22.0;

function expense_projection(PDO $pdo, int $projectId): array {
    $steps = pipeline_steps($pdo, $projectId);

    // ---- THE NUMBER THE BUDGET IS BUILT ON --------------------------------
    //
    // David, 2026-08-17: *"Ideal is what the studio or production head
    // determines will budget the project based on."* So the projection is costed
    // from the ideal quota, through effective_days_per_unit(), the same reader
    // the schedule and the quotas use.
    //
    // Since 2026-09-17 that ideal is this project's own, per step (migration
    // 034), so every row rests on it. Before 034 the old rule stands: the type
    // sheet's standard, falling back to what this project was planned at, and
    // each row says which, so the screen can report the mix in one line.
    $ptype = (string)$pdo->query(
        'SELECT type FROM projects WHERE id = ' . (int)$projectId
    )->fetchColumn();

    // project_lanes() returns a FLAT list ordered by step; group it, because the
    // projection walks step by step and a linear scan per step would be n-squared.
    $lanes = [];
    foreach (project_lanes($pdo, $projectId) as $l) {
        $lanes[(int)$l['step_id']][] = $l;
    }
    $retake = project_retake_pct($pdo, $projectId);
    $estimates = project_estimates($pdo, $projectId);
    $hoursPerDay = hours_per_day($pdo, $projectId);

    $crewIds = [];
    foreach ($lanes as $laneRows) {
        foreach ($laneRows as $l) if (!empty($l['crew_id'])) $crewIds[] = (int)$l['crew_id'];
    }
    $terms = crew_terms(array_values(array_unique($crewIds)));
    $actual = actual_days_by_crew_step($pdo, $projectId, $hoursPerDay);
    // ACTUAL COST COMES FROM INVOICES, not from days times a rate. That product
    // is a second estimate wearing an actual's clothes; only an invoice records
    // what was really billed (David, 2026-08-18).
    $billedBy = invoiced_by_position($projectId);

    $rows = []; $total = 0.0; $actualTotal = 0.0;
    $unpricedDays = 0.0; $currency = null; $mixed = false;

    // ---- a contract in another currency ------------------------------------
    // Tim and Jorik, 2026-10-28: a rare contract in another currency (Lewis in
    // £) is costed in DOLLARS at the one exchange rate fixed for this project,
    // and the row keeps what it is in its own currency so the screen can show
    // both. Converted HERE, once, after the cost is rounded to cents in its own
    // currency, so every total below is in one currency by construction.
    //
    // ⚠ THE ROW'S `rate` AND `currency` STAY THE CONTRACT'S. The rate box on
    // the projection writes straight back into crew_terms; showing it a dollar
    // figure would save dollars over a sterling rate. Only `cost` (and the
    // totals) move to dollars, and `cost_currency` says which they are in.
    //
    // A currency with no fixed rate is left as it is and still counts as mixed,
    // with the old warning (see includes/fx.php).
    $fx = project_fx_rates($projectId);
    $currenciesSeen = [];
    $inDollars = function (?float $cost, $cur) use ($fx, &$currenciesSeen): array {
        $cur = fx_code($cur) ?? 'USD';
        if ($cost === null) return [null, null, null, null];
        $currenciesSeen[$cur] = true;
        $usd = $cur === 'USD' ? null : fx_to_usd($cost, $cur, $fx);
        if ($usd === null) return [$cost, $cur, null, null];
        return [$usd, 'USD', $cost, $fx[$cur]];
    };
    // How much of the plan is costed against a studio standard rather than one
    // project's estimate. Counted so the screen can report it in one line
    // instead of making somebody read every row.
    $onIdeal = 0; $onEstimate = 0;

    foreach ($steps as $step) {
        $stepId = (int)$step['id'];
        $quota = step_quota($pdo, $projectId, $step, null, $estimates, $retake);

        $units = (float)($quota['units'] ?? 0);

        // The same helper the quota and the scheduler use, rather than a second
        // copy of the rule here — a duplicated lookup is how this file and the
        // planning screen came to disagree in the first place.
        $estimateDays = (float)($step['days_per_unit'] ?: 0);
        $baseDays     = effective_days_per_unit($pdo, $projectId, $step);
        $daysSource   = effective_days_source($pdo, $projectId, $step);
        $idealDays    = $daysSource === 'ideal' ? $baseDays : null;

        // Retakes scale days-per-item whichever source it came from — a revision
        // takes as long on a standard as on a guess (DECISIONS, 2026-08-12).
        $daysPerUnit = $baseDays * retake_factor($retake);
        $personDays  = $units * $daysPerUnit;

        $stepLanes = $lanes[$stepId] ?? [];
        $laneCount = max(1, count($stepLanes));
        $daysPerLane = $personDays / $laneCount;

        // ---- paying by the second -----------------------------------------
        // For a footage step `units` IS seconds, so one lane's share of the work
        // is one lane's share of the footage.
        //
        // ⚠ NOT retake-adjusted, unlike $daysPerLane above. A retake allowance
        // lengthens the work; it does not create more footage. 380 seconds stays
        // 380 seconds however often it is revised, so a per-second cost is flat
        // while its duration grows — which is what buying by the second means
        // (DECISIONS, 2026-08-17).
        $tracksSeconds = (int)($step['tracks_seconds'] ?? 0) === 1;
        $unitsPerLane  = $units / $laneCount;

        // A step with nobody on it still belongs in the sheet, as a row with no
        // artist. Leaving it out would hide work somebody still has to be paid
        // to do — the opposite of what a projection is for.
        if (!$stepLanes) {
            $stepLanes = [null];
            $daysPerLane = $personDays;
        }

        // ⚠ WHOLE DAYS, ROUNDED UP, ON EVERY ROW OF EVERY PROJECT (David,
        // 2026-10-21: "all these days need to be rounded up in the expense
        // projections ... in all projects as a rule"). Nobody is booked or paid
        // for 2.75 days, so a person's share is the next whole day, and the cost,
        // months and every subtotal follow from it. Done ONCE here, so the screen,
        // the export, the person page and the kick-off snapshot all agree.
        //
        // The round() first: 15.4 x 3 lanes can come back as 15.400000000000002,
        // and ceil() of that is 16. A per-second cost is unaffected (it is priced
        // on footage, not days). The unrounded share is kept as days_exact.
        $daysExact   = $daysPerLane;
        $daysPerLane = ceil(round($daysPerLane, 6));

        foreach ($stepLanes as $lane) {
            $cid = $lane ? (int)($lane['crew_id'] ?? 0) : 0;
            $t = $cid ? ($terms[$cid] ?? null) : null;
            $rate = ($t && $t['day_rate'] !== null) ? (float)$t['day_rate'] : null;
            $unit = $t['rate_unit'] ?? 'day';
            $cur  = $t['rate_currency'] ?? 'USD';

            $cost = null; $costCur = null; $costOwn = null; $rowFx = null;
            $rateMismatch = false;
            if ($rate !== null) {
                // Hourly converts at the project's own hours per day. A flat fee
                // is not multiplied by anything, because that is what flat means.
                if ($unit === 'flat') {
                    $cost = $rate;
                } elseif ($unit === 'hour') {
                    $cost = $rate * $hoursPerDay * $daysPerLane;
                } elseif ($unit === 'second') {
                    // ⚠ GUARDED ON THE STEP ACTUALLY TRACKING SECONDS. On a step
                    // measured in shots or assets, `units` is an ITEM COUNT — a
                    // per-second rate times 41 shots would be a confident, wrong
                    // number that nothing on screen would question. Reported as a
                    // mismatch and left unpriced instead.
                    if ($tracksSeconds) {
                        $cost = $rate * $unitsPerLane;
                    } else {
                        $cost = null;
                        $rateMismatch = true;
                    }
                } else {
                    $cost = $rate * $daysPerLane;
                }

                // MONEY IS CENTS, and it becomes cents HERE — once, in the one
                // place a cost is worked out — so every reader of this array
                // agrees by construction.
                //
                // Without it a lane cost carried a fraction of a cent (230 x
                // 2.45025 = 563.5575), every screen and the export DISPLAYED
                // 563.56, and every total SUMMED 563.5575. The document did not
                // add up: six departments showed rows totalling 14,922.05 under
                // a grand total of 14,922.02.
                //
                // The error is per ROW, not per amount, so it grows with the
                // size of the sheet rather than the size of the budget — about
                // half a cent a row, which is a whole unit on a 200-line
                // projection and the reason this is worth fixing rather than
                // living with (David, 2026-08-18).
                //
                // The sum of the line items IS the budget: there is no truer
                // total hiding behind the rounding, because nobody can be paid
                // 563.5575.
                if ($cost !== null) $cost = round($cost, 2);
                [$cost, $costCur, $costOwn, $rowFx] = $inDollars($cost, $cur);

                if ($cost !== null) {
                    $total += $cost;
                    if ($currency === null) $currency = $costCur;
                    elseif ($currency !== $costCur) $mixed = true;
                } else {
                    // Priced at a unit this step cannot be measured in — counted
                    // with the unpriced days rather than as free.
                    $unpricedDays += $daysPerLane;
                }
            } else {
                $unpricedDays += $daysPerLane;
            }

            $actDays = $cid ? ($actual[$cid . '|' . $stepId] ?? 0.0) : 0.0;
            // Null means nothing has been invoiced against this position — which
            // is not the same as it having cost nothing, so the screen shows a
            // dash rather than a zero.
            $actCost = $cid ? ($billedBy[$cid . '|' . $stepId] ?? null) : null;
            if ($actCost !== null) $actualTotal += $actCost;

            if ($daysSource === 'ideal') $onIdeal++; else $onEstimate++;

            $rows[] = [
                'department'  => trim((string)($step['department'] ?? '')) ?: 'Unassigned',
                'step'        => $step,
                'role'        => $step['name'] . ' (' . step_scope_tag($step) . ')',
                'crew_id'     => $cid ?: null,
                'artist'      => $lane ? ($lane['name'] ?? null) : null,
                'is_slot'     => $lane ? (int)($lane['is_placeholder'] ?? 0) === 1 : false,
                'rate'        => $rate,
                'rate_unit'   => $rate !== null ? $unit : null,
                'currency'    => $rate !== null ? $cur : null,
                // The cost's own currency: USD when a fixed rate converted it.
                // cost_own is the same cost in the contract's currency, and fx
                // the project rate used (null when nothing was converted).
                'cost_currency' => $costCur,
                'cost_own'    => $costOwn,
                'fx'          => $rowFx,
                'rate_usd'    => ($rowFx && $rate !== null) ? $rate * (float)$rowFx['usd_per_unit'] : null,
                'days'        => $daysPerLane,      // whole days, rounded up (2026-10-21)
                'days_exact'  => $daysExact,
                'months'      => $daysPerLane / DAYS_PER_MONTH,
                // Which number this row's cost rests on, so the screen can say
                // so rather than leaving a reader to wonder why two steps of the
                // same size cost differently.
                'days_source'   => $daysSource,
                // The export needs to know whether a per-second rate can price
                // this row, and the screen needs it to explain when one cannot.
                'tracks_seconds' => $tracksSeconds,
                'units_per_lane' => $unitsPerLane,
                // HOW MANY WAYS THE STEP IS SPLIT. The Units column carries the
                // whole step's footage while Days carries one lane's share, so a
                // per-second cost needs this to reconcile the two. The export
                // wrote `rate x units` without it and charged all 40.5 seconds to
                // each of three animators.
                'lane_count'     => $laneCount,
                'rate_mismatch'  => $rateMismatch,
                'ideal_days'    => $idealDays,
                'estimate_days' => $estimateDays ?: null,
                'cost'        => $cost,
                'units'       => $units,
                'unit_label'  => $quota['unit_label'] ?? 'items',
                'actual_days' => $actDays,
                'actual_cost' => $actCost,
                'variance'    => ($cost !== null && $actCost !== null) ? $cost - $actCost : null,
            ];
        }
    }

    // ---- supervision: a person for a stretch of dates -----------------------
    // Anim Sup, CG Sup (David, 2026-10-26, migration 068). No quota and no
    // items, so the days are the working days between the position's two dates,
    // rounded the same way as every other row (they are already whole). Priced
    // from the person's own rate exactly as a lane is: per day, per hour at the
    // project's hours per day, or flat. A per-second rate has no footage to
    // multiply here, so it is a mismatch, as it is on a step that is not
    // measured in seconds. See includes/supervision.php.
    require_once __DIR__ . '/supervision.php';
    $supervision = supervision_positions($pdo, $projectId);
    $supTerms = $supervision
        ? crew_terms(array_values(array_unique(array_map(fn($p) => (int)$p['crew_id'], $supervision))))
        : [];
    foreach ($supervision as $p) {
        $cid  = (int)$p['crew_id'];
        $days = (float)supervision_days($p);
        $t    = $supTerms[$cid] ?? null;
        $rate = ($t && $t['day_rate'] !== null) ? (float)$t['day_rate'] : null;
        $unit = $t['rate_unit'] ?? 'day';
        $cur  = $t['rate_currency'] ?? 'USD';

        $cost = null; $rateMismatch = false;
        if ($rate !== null && $days > 0) {
            if ($unit === 'flat')       $cost = $rate;
            elseif ($unit === 'hour')   $cost = $rate * $hoursPerDay * $days;
            elseif ($unit === 'second') $rateMismatch = true;
            else                        $cost = $rate * $days;
        }
        $costCur = null; $costOwn = null; $rowFx = null;
        if ($cost !== null) {
            [$cost, $costCur, $costOwn, $rowFx] = $inDollars(round($cost, 2), $cur);
            $total += $cost;
            if ($currency === null) $currency = $costCur;
            elseif ($currency !== $costCur) $mixed = true;
        } else {
            $unpricedDays += $days;
        }

        $supBilled = $billedBy[$cid . '|' . -(int)$p['id']] ?? null;
        $rows[] = [
            'department'     => 'Supervision',
            // Shaped like a step so every reader that draws a dot or sorts by
            // step order keeps working. The id is the position's, NEGATIVE, the
            // same number an invoice for it carries in step_id (see
            // project_positions()); no ideal or pool row can match it.
            'step'           => ['id' => -(int)$p['id'], 'name' => $p['title'], 'color' => '#8a8f98',
                                 'step_order' => (int)$p['sort_order'], 'applies_to' => 'project',
                                 'applies_to_asset_type' => null, 'tracks_seconds' => 0],
            'role'           => $p['title'],
            'crew_id'        => $cid,
            'artist'         => $p['name'],
            'is_slot'        => (int)$p['is_placeholder'] === 1,
            'rate'           => $rate,
            'rate_unit'      => $rate !== null ? $unit : null,
            'currency'       => $rate !== null ? $cur : null,
            'cost_currency'  => $costCur,
            'cost_own'       => $costOwn,
            'fx'             => $rowFx,
            'rate_usd'       => ($rowFx && $rate !== null) ? $rate * (float)$rowFx['usd_per_unit'] : null,
            'days'           => $days,
            'days_exact'     => $days,
            'months'         => $days / DAYS_PER_MONTH,
            'days_source'    => 'dates',
            'supervision'    => true,
            'start_date'     => $p['start_date'],
            'end_date'       => $p['end_date'],
            'tracks_seconds' => false,
            'units_per_lane' => 0,
            'lane_count'     => 1,
            'rate_mismatch'  => $rateMismatch,
            'ideal_days'     => null,
            'estimate_days'  => null,
            'cost'           => $cost,
            'units'          => 0,
            'unit_label'     => 'days',
            // Actual cost is what has been invoiced against the position, as
            // for a lane. No actual days: a supervisor logs no time on a step.
            'actual_days'    => 0.0,
            'actual_cost'    => $supBilled,
            'variance'       => ($cost !== null && $supBilled !== null) ? $cost - $supBilled : null,
        ];
        if ($supBilled !== null) $actualTotal += $supBilled;
    }

    // Department order as the sheet reads it, with anything unrecognised last.
    // Supervision sits straight after Above the line: it runs the length of the
    // show rather than belonging to one phase of it.
    $order = ['Above the line' => 1, 'Supervision' => 1.5, 'Pre-production' => 2, 'Production' => 3,
              'Post-production' => 4];
    usort($rows, function ($a, $b) use ($order) {
        $oa = $order[$a['department']] ?? 9;
        $ob = $order[$b['department']] ?? 9;
        if ($oa !== $ob) return $oa <=> $ob;
        $s = (int)$a['step']['step_order'] <=> (int)$b['step']['step_order'];
        return $s !== 0 ? $s : strcasecmp((string)$a['artist'], (string)$b['artist']);
    });

    $byDept = [];
    foreach ($rows as $r) {
        $d = $r['department'];
        $byDept[$d] = $byDept[$d] ?? ['rows' => [], 'cost' => 0.0, 'days' => 0.0, 'actual' => 0.0];
        $byDept[$d]['rows'][] = $r;
        $byDept[$d]['cost']   += (float)($r['cost'] ?? 0);
        $byDept[$d]['days']   += (float)$r['days'];
        $byDept[$d]['actual'] += (float)($r['actual_cost'] ?? 0);
    }

    // Costs that are not somebody's day rate — software, render time, licences,
    // travel. Folded in HERE rather than added on by each screen, because this
    // function is the single source the hub, the project page and the person
    // page all read. A total assembled separately on three screens is three
    // totals that disagree within a week; that is the same rule that keeps the
    // projection computed rather than stored.
    $other = project_expenses($projectId);
    $otherTotal = 0.0; $otherPaid = 0.0;
    $byCategory = [];
    foreach ($other as $i => $e) {
        // In dollars at the project's fixed rate when it has one; the row keeps
        // `amount` and `currency` as entered (the edit form reads them) and
        // gains amount_usd for the totals and the screen (2026-10-28).
        [$amt, $eCur, $eOwn] = $inDollars((float)$e['amount'], $e['currency']);
        $e['amount_usd'] = $eCur === 'USD' ? $amt : null;
        $e['amount_own'] = $eOwn;
        $other[$i] = $e;
        $otherTotal += $amt;
        if (!empty($e['paid_date'])) $otherPaid += $amt;

        $c = trim((string)$e['category']) ?: 'Other';
        $byCategory[$c] = $byCategory[$c] ?? ['rows' => [], 'amount' => 0.0, 'paid' => 0.0];
        $byCategory[$c]['rows'][] = $e;
        $byCategory[$c]['amount'] += $amt;
        if (!empty($e['paid_date'])) $byCategory[$c]['paid'] += $amt;

        if ($currency === null) $currency = $eCur;
        elseif ($currency !== $eCur) $mixed = true;
    }
    ksort($byCategory, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'rows'           => $rows,
        'by_department'  => $byDept,
        // LABOUR only, deliberately. The projection table's own footer sums the
        // rows above it, and a total larger than its own rows would be the kind
        // of quietly-wrong figure this file exists to avoid.
        'total'          => $total,
        'actual_total'   => $actualTotal,
        // Everything that is not a person on a step.
        'other'          => $other,
        'by_category'    => $byCategory,
        'other_total'    => $otherTotal,
        'other_paid'     => $otherPaid,
        // What the show costs. THIS is the figure a margin is drawn against —
        // budget minus labour alone was read as headroom and was not.
        'grand_total'    => $total + $otherTotal,
        'currency'       => $currency ?: 'USD',
        'mixed_currency' => $mixed,
        // Every currency the costs came in, and the fixed rates that turned
        // them into dollars. fx_project_currencies() reads the first to know
        // which rates a project still lacks.
        'currencies_seen' => $currenciesSeen,
        'fx'             => $fx,
        'unpriced_days'  => $unpricedDays,
        'hours_per_day'  => $hoursPerDay,
        // The mix of sources behind the cost above.
        'rows_on_ideal'    => $onIdeal,
        'rows_on_estimate' => $onEstimate,
        'standards_ready'  => standards_available($pdo),
        'project_type'     => $ptype,
    ];
}

/* ---------------------------------------------------------------------------
 * Other expenses — costs with no lane behind them.
 *
 * WHERE THE LINE FALLS AGAINST `invoices`, because the two overlap and somebody
 * will otherwise record the same cost twice:
 *
 *     invoices          a PERSON billing the show, and the actual cost of
 *                       planned labour.
 *     project_expenses  everything that is not a person's time — a vendor, a
 *                       licence, a machine, a trip.
 *
 * Stated that way they never compete for the same row, and the screen says so.
 * ------------------------------------------------------------------------- */

/** Has finance_install_05.sql been imported? */
function expenses_available(): bool {
    return finance_column_exists('project_expenses', 'id');
}

/**
 * What a project owns in the OTHER database — budget, invoices, expenses, quotes.
 *
 * Exists for ONE caller: deleting a project. Production cascades cleanly (every
 * one of the twenty tables carrying project_id has ON DELETE CASCADE), but the
 * money lives in a separate database with a separate user and NO foreign key
 * back to projects. A delete cannot reach it, so those rows would survive their
 * project as records nothing can explain.
 *
 * Worse than untidy: `projects.id` is the only thing joining the two databases,
 * and DECISIONS says it must never be reused. A future project handed a dead
 * project's id would silently inherit its budget and its invoices.
 *
 * 'reachable' is the important field. FALSE means we could not look — the
 * finance side is configured but would not open, or the caller is not an admin.
 * A delete must refuse on false rather than assume zero, because "I could not
 * check" and "there is nothing there" are the same shape and only one is safe.
 */
function project_finance_footprint(int $projectId): array {
    $out = ['budget' => 0, 'invoices' => 0, 'expenses' => 0, 'quotes' => 0,
            'total' => 0, 'reachable' => true];

    // No finance database configured at all, so there is definitively nothing
    // keyed to this project on that side.
    if (!finance_configured()) return $out;

    if (!can_see_money()) { $out['reachable'] = false; return $out; }
    $db = finance_db();
    if (!$db) { $out['reachable'] = false; return $out; }

    // Table names are literals from the line below, never input. Each is guarded
    // by finance_column_exists() because 04 and 05 are imported by hand and may
    // legitimately not be there yet.
    $count = function (string $table) use ($db, $projectId): int {
        if (!finance_column_exists($table, 'project_id')) return 0;
        $q = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE project_id = ?');
        $q->execute([$projectId]);
        return (int)$q->fetchColumn();
    };

    $out['budget']   = $count('project_budgets');
    $out['invoices'] = $count('invoices');
    $out['expenses'] = $count('project_expenses');
    $out['quotes']   = $count('hire_quotes');
    $out['total']    = $out['budget'] + $out['invoices'] + $out['expenses'] + $out['quotes'];
    return $out;
}

/** Same shape as invoices_need_upgrade(), for telling "none yet" from "no table". */
function expenses_need_upgrade(): bool {
    if (!can_see_money() || !finance_configured()) return false;
    return !expenses_available();
}

/** Every non-labour cost on a project, newest first within category. */
function project_expenses(int $projectId): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db || !expenses_available()) return [];
    $q = $db->prepare(
        'SELECT * FROM project_expenses WHERE project_id = ?
          ORDER BY category, incurred_date IS NULL, incurred_date DESC, id DESC'
    );
    $q->execute([$projectId]);
    return $q->fetchAll();
}

/** Non-labour totals for many projects at once, for the hub. Keyed by project id. */
function expense_totals(array $projectIds): array {
    if (!can_see_money() || !$projectIds) return [];
    $db = finance_db();
    if (!$db || !expenses_available()) return [];
    $ids = implode(',', array_map('intval', $projectIds));
    $out = [];
    foreach ($db->query(
        "SELECT project_id, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
           FROM project_expenses WHERE project_id IN ({$ids}) GROUP BY project_id"
    ) as $r) {
        $out[(int)$r['project_id']] = $r;
    }
    return $out;
}

/**
 * The categories to offer — the studio's own vocabulary plus a few starters.
 *
 * GLOBAL, not per project: which categories a studio actually spends against is
 * worth being able to compare across shows, and a list rebuilt per project would
 * make every new show start from nothing. Same reasoning as step_library.
 */
function expense_categories(): array {
    $starters = [
        'Software & licences', 'Render / cloud', 'Music & sound', 'Stock & assets',
        'Equipment', 'Travel', 'Services', 'Contingency', 'Other',
    ];
    $seen = [];
    foreach ($starters as $s) $seen[mb_strtolower($s)] = $s;

    $db = can_see_money() ? finance_db() : null;
    if ($db && expenses_available()) {
        foreach ($db->query('SELECT DISTINCT category FROM project_expenses ORDER BY category') as $r) {
            $c = trim((string)$r['category']);
            if ($c !== '') $seen[mb_strtolower($c)] = $c;
        }
    }
    $out = array_values($seen);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Record several expenses at once, from the entry grid.
 *
 * Same rules as save_invoices_bulk(): one bad row never stops the rest, and a
 * BLANK row and an INCOMPLETE row are different facts. A row with a description
 * and no amount is somebody interrupted mid-typing, and is reported against its
 * row number rather than dropped.
 *
 * @return array ['added' => int, 'errors' => array]
 */
function save_expenses_bulk(array $rows, array $project, string $who = ''): array {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can record an expense.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet, see finance_install.sql.');
    if (!expenses_available()) {
        throw new RuntimeException(
            'Other expenses need finance_install_05.sql, which has not been imported into the '
            . 'finance database yet.'
        );
    }

    $out = ['added' => 0, 'errors' => []];
    $asDate = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };

    $ins = $db->prepare(
        'INSERT INTO project_expenses
           (project_id, project_code, category, description, vendor, amount, currency,
            status, incurred_date, paid_date, document_url, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($rows as $i => $row) {
        $desc   = trim((string)($row['description'] ?? ''));
        $rawAmt = trim((string)($row['amount'] ?? ''));

        // What counts as "typed in" excludes the fields that carry a default on
        // every row — currency, status — or every blank row would be an error.
        $typed = $desc . trim((string)($row['vendor'] ?? '')) . trim((string)($row['notes'] ?? ''))
               . trim((string)($row['document_url'] ?? '')) . trim((string)($row['incurred_date'] ?? ''));

        if ($rawAmt === '' && $typed === '') continue;          // an unused row

        if ($desc === '') {
            $out['errors'][] = sprintf('Row %d has no description, so it was not recorded, '
                                     . 'a cost nobody can identify later is not a record.', $i + 1);
            continue;
        }
        if ($rawAmt === '') {
            $out['errors'][] = sprintf('Row %d ("%s") has no amount, so it was not recorded.',
                                       $i + 1, $desc);
            continue;
        }

        // Accept "1,200.00" and "$1200" — people paste from a receipt.
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $rawAmt));
        if ($clean === '' || !is_numeric($clean)) {
            $out['errors'][] = sprintf('Row %d ("%s"): "%s" is not an amount.', $i + 1, $desc, $rawAmt);
            continue;
        }
        $amount = round((float)$clean, 2);
        if ($amount <= 0) {
            $out['errors'][] = sprintf('Row %d ("%s") must be for more than nothing.', $i + 1, $desc);
            continue;
        }

        $cur = strtoupper(trim((string)($row['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

        $status = in_array($row['status'] ?? '', ['planned', 'committed', 'paid'], true)
                ? $row['status'] : 'planned';
        $paid = $asDate($row['paid_date'] ?? '');
        // The same correction invoices apply: a state and its date must never
        // contradict each other, because one of them is then a lie.
        if ($status === 'paid' && $paid === null) $paid = date('Y-m-d');
        if ($paid !== null && $status !== 'paid')  $status = 'paid';

        $url = trim((string)($row['document_url'] ?? ''));
        if ($url !== '') {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $out['errors'][] = sprintf('Row %d ("%s"): a document link must start with '
                                         . 'http:// or https://.', $i + 1, $desc);
                continue;
            }
        }

        try {
            $ins->execute([
                (int)$project['id'], $project['code'] ?? null,
                trim((string)($row['category'] ?? '')) ?: 'Other',
                $desc,
                trim((string)($row['vendor'] ?? '')) ?: null,
                $amount, $cur, $status,
                $asDate($row['incurred_date'] ?? ''), $paid,
                $url !== '' ? $url : null,
                trim((string)($row['notes'] ?? '')) ?: null,
                $who,
            ]);
            $out['added']++;
        } catch (Throwable $e) {
            $out['errors'][] = sprintf('Row %d ("%s") was not recorded: %s', $i + 1, $desc, $e->getMessage());
        }
    }
    return $out;
}

/** Remove one expense. */
function delete_expense(int $id): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db || !expenses_available()) throw new RuntimeException('The expenses table is not set up yet.');
    $db->prepare('DELETE FROM project_expenses WHERE id = ?')->execute([$id]);
    return 'Expense removed.';
}

/** Remove an invoice. */

/**
 * Every position on a show an invoice can name, and a rate can be set for: each
 * slot (crew id + step id) and each supervision position (crew id + MINUS the
 * position's id, migration 068).
 *
 * ⚠ THE NEGATIVE STEP ID IS DELIBERATE. invoices.step_id lives in the finance
 * database and is a plain INT with no foreign key (the two databases cannot have
 * one), and "crew + step" is how every reader already finds an invoice's row. A
 * pipeline step id is never negative, so -12 can only ever mean supervision
 * position 12, and supervision invoices needed no finance import to work.
 *
 * [['crew_id','step_id','crew_name','step_name','label','is_placeholder'], ...]
 */
function project_positions(PDO $pdo, int $projectId): array {
    $out = [];
    foreach (project_lanes($pdo, $projectId) as $l) {
        $out[] = [
            'crew_id'   => (int)$l['crew_id'],
            'step_id'   => (int)$l['step_id'],
            'crew_name' => (string)$l['name'],
            'step_name' => (string)$l['step_name'],
            'label'     => $l['name'] . ', ' . $l['step_name'],
            'is_placeholder' => !empty($l['is_placeholder']),
        ];
    }
    require_once __DIR__ . '/supervision.php';
    foreach (supervision_positions($pdo, $projectId) as $p) {
        $out[] = [
            'crew_id'   => (int)$p['crew_id'],
            'step_id'   => -(int)$p['id'],
            'crew_name' => (string)$p['name'],
            'step_name' => (string)$p['title'],
            'label'     => $p['name'] . ', ' . $p['title'] . ' (supervision)',
            'is_placeholder' => (int)$p['is_placeholder'] === 1,
        ];
    }
    return $out;
}

/**
 * What has actually been INVOICED, per position, keyed "crewId|stepId".
 *
 * This is the actual cost on the expense projection. An invoice with no step —
 * a kill fee, a one-off, a bulk settlement — is deliberately excluded here: it
 * belongs to no projection row, and spreading it across rows would put a
 * fiction into a subtotal. It still counts in the project's invoiced total,
 * and the screen says how much is unattached rather than losing it quietly.
 */
function invoiced_by_position(int $projectId): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db) return [];
    // finance_install_03.sql may not have been imported yet. Without it there is
    // no position on an invoice, so nothing can attach — reported by
    // invoices_need_upgrade() rather than crashing the page.
    if (!finance_column_exists('invoices', 'step_id')) return [];
    // Per currency, then into dollars at the project's fixed rate (2026-10-28),
    // because the projection row it lands on is costed in dollars. A currency
    // with no fixed rate is added as it is, which is what this always did; the
    // projection's mixed-currency warning covers that case.
    $q = $db->prepare(
        'SELECT crew_id, step_id, currency, SUM(amount) AS billed
           FROM invoices
          WHERE project_id = ? AND crew_id IS NOT NULL AND step_id IS NOT NULL
          GROUP BY crew_id, step_id, currency'
    );
    $q->execute([$projectId]);
    $fx = project_fx_rates($projectId);
    $out = [];
    foreach ($q as $r) {
        $k = (int)$r['crew_id'] . '|' . (int)$r['step_id'];
        $out[$k] = round(($out[$k] ?? 0.0) + fx_usd_or_own((float)$r['billed'], $r['currency'], $fx)[0], 2);
    }
    return $out;
}

/**
 * Days actually worked, per person per step, from `time_logs`.
 *
 * Keyed "crewId|stepId". Minutes convert to days at the project's own
 * hours-per-day — the same figure the dashboard uses when it puts hours and days
 * beside each other, so "3.2 days actual" here means what it means there.
 *
 * Empty until migration 022 has run and somebody has logged time. The screen
 * shows a dash rather than a zero, because "nobody logged it" and "it took no
 * time" are different facts.
 */
function actual_days_by_crew_step(PDO $pdo, int $projectId, float $hoursPerDay): array {
    if (!time_logs_available($pdo) || $hoursPerDay <= 0) return [];
    $stmt = $pdo->prepare(
        'SELECT tl.crew_id, pr.step_id, SUM(tl.minutes) AS m
           FROM time_logs tl
           JOIN progress pr ON pr.id = tl.progress_id
          WHERE tl.project_id = ? AND tl.progress_id IS NOT NULL
          GROUP BY tl.crew_id, pr.step_id'
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int)$r['crew_id'] . '|' . (int)$r['step_id']] = ((int)$r['m'] / 60) / $hoursPerDay;
    }
    return $out;
}

/** Invoices for one project, newest first. */
function project_invoices(int $projectId): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db) return [];
    $q = $db->prepare('SELECT * FROM invoices WHERE project_id = ? ORDER BY issued_date DESC, id DESC');
    $q->execute([$projectId]);
    return $q->fetchAll();
}

/** Totals across every project, for the hub. Keyed by project id. */
function invoice_totals(array $projectIds): array {
    if (!can_see_money() || !$projectIds) return [];
    $db = finance_db();
    if (!$db) return [];
    $ids = implode(',', array_map('intval', $projectIds));
    // Per currency, then into dollars at each project's fixed rate (2026-10-28).
    // A currency with no fixed rate is added as it is, as before.
    $out = [];
    foreach ($db->query(
        "SELECT project_id, currency,
                COUNT(*) AS n,
                COALESCE(SUM(amount), 0) AS billed,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL THEN amount ELSE 0 END), 0) AS paid
           FROM invoices WHERE project_id IN ({$ids}) GROUP BY project_id, currency"
    ) as $r) {
        $pid = (int)$r['project_id'];
        $fx  = project_fx_rates($pid);
        $o   = $out[$pid] ?? ['project_id' => $pid, 'n' => 0, 'billed' => 0.0, 'paid' => 0.0];
        $o['n']      += (int)$r['n'];
        $o['billed'] = round($o['billed'] + fx_usd_or_own((float)$r['billed'], $r['currency'], $fx)[0], 2);
        $o['paid']   = round($o['paid'] + fx_usd_or_own((float)$r['paid'], $r['currency'], $fx)[0], 2);
        $out[$pid] = $o;
    }
    return $out;
}

/**
 * The statuses an invoice can have, value => label, in dropdown order.
 *
 * Expected / Pending / Paid since 2026-10-26 (David), once finance_install_09
 * has been imported into the finance database. Until then the database still
 * holds the old four and would refuse the new values, so the old ones are
 * offered — read from the column itself rather than assumed, so the dropdown
 * can never offer a value the database will not store.
 */
function invoice_statuses(): array {
    static $out = null;
    if ($out !== null) return $out;
    $new = ['expected' => 'Expected', 'pending' => 'Pending', 'paid' => 'Paid'];
    $old = ['received' => 'Received', 'approved' => 'Approved', 'paid' => 'Paid', 'disputed' => 'Disputed'];
    $db = finance_db();
    if (!$db) return $out = $new;
    try {
        $col = $db->query("SHOW COLUMNS FROM invoices LIKE 'status'")->fetch();
        $type = strtolower((string)($col['Type'] ?? ''));
    } catch (PDOException $e) {
        $type = '';
    }
    return $out = strpos($type, "'pending'") !== false ? $new : $old;
}

/** The status a new invoice gets when none is chosen. */
function invoice_default_status(): string {
    return isset(invoice_statuses()['pending']) ? 'pending' : 'received';
}

/** An invoice's status in words, for a value from either the old or new set. */
function invoice_status_label(string $status): string {
    $all = ['expected' => 'Expected', 'pending' => 'Pending', 'paid' => 'Paid',
            'received' => 'Received', 'approved' => 'Approved', 'disputed' => 'Disputed'];
    return $all[$status] ?? ucfirst($status);
}

/** Record or update an invoice. Returns a sentence for the flash message. */
function save_invoice(array $in, array $project, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can record an invoice.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');

    $raw = trim((string)($in['amount'] ?? ''));
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
    if ($clean === '' || !is_numeric($clean)) {
        throw new RuntimeException('"' . $raw . '" is not an amount, nothing was saved.');
    }
    $amount = round((float)$clean, 2);
    if ($amount <= 0) throw new RuntimeException('An invoice has to be for more than nothing.');

    $cur = strtoupper(trim((string)($in['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    $status = isset(invoice_statuses()[(string)($in['status'] ?? '')])
        ? (string)$in['status'] : invoice_default_status();

    $date = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $paid = $date($in['paid_date'] ?? '');
    // A status of paid with no date is a fact nobody can check later.
    if ($status === 'paid' && $paid === null) $paid = date('Y-m-d');
    if ($paid !== null && $status !== 'paid') $status = 'paid';

    $url = trim((string)($in['document_url'] ?? ''));
    if ($url !== '') {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('A document link must start with http:// or https://.');
        }
    }

    $hasPosition = finance_column_exists('invoices', 'step_id');
    $id = (int)($in['invoice_id'] ?? 0);
    $args = [
        (int)$project['id'], $project['code'] ?? null,
        !empty($in['crew_id']) ? (int)$in['crew_id'] : null,
        trim((string)($in['crew_name'] ?? '')) ?: null,
        trim((string)($in['invoice_ref'] ?? '')) ?: null,
        $amount, $cur,
        $date($in['period_start'] ?? ''), $date($in['period_end'] ?? ''),
        $date($in['issued_date'] ?? ''), $date($in['due_date'] ?? ''), $paid,
        $status, $url !== '' ? $url : null,
        trim((string)($in['notes'] ?? '')) ?: null,
        $who,
    ];

    if ($hasPosition) {
        // Positioned after crew_name, matching the column order below.
        array_splice($args, 4, 0, [
            !empty($in['step_id']) ? (int)$in['step_id'] : null,
            trim((string)($in['step_name'] ?? '')) ?: null,
        ]);
    }

    $posCols = $hasPosition ? 'step_id = ?, step_name = ?,' : '';
    $posIns  = $hasPosition ? 'step_id, step_name,' : '';
    $posVals = $hasPosition ? '?, ?,' : '';

    if ($id > 0) {
        $args[] = $id;
        $db->prepare(
            "UPDATE invoices SET project_id = ?, project_code = ?, crew_id = ?, crew_name = ?,
                    {$posCols}
                    invoice_ref = ?, amount = ?, currency = ?, period_start = ?, period_end = ?,
                    issued_date = ?, due_date = ?, paid_date = ?, status = ?, document_url = ?,
                    notes = ?, updated_by = ? WHERE id = ?"
        )->execute($args);
        return 'Invoice updated.';
    }

    $db->prepare(
        "INSERT INTO invoices
           (project_id, project_code, crew_id, crew_name, {$posIns}
            invoice_ref, amount, currency,
            period_start, period_end, issued_date, due_date, paid_date, status,
            document_url, notes, updated_by)
         VALUES (?, ?, ?, ?, {$posVals} ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute($args);

    return 'Invoice recorded: ' . fmt_money_plain($amount, $cur) . '.';
}

/**
 * Record several invoices in one go, from the entry grid.
 *
 * Same shape and the same rules as save_hires_bulk(): one bad row never stops
 * the rest, and every category of outcome is reported rather than inferred.
 * A month's invoices arrive as a batch, so entering them one form-submit at a
 * time was six page loads to do one afternoon's bookkeeping.
 *
 * ⚠ AN EMPTY ROW AND AN INCOMPLETE ROW ARE NOT THE SAME THING, and this is the
 * part worth keeping. A row with nothing in it is one of the five that was not
 * needed — skipped in silence, correctly. A row with a NAME and no AMOUNT is
 * somebody who started typing and was interrupted, and skipping that quietly
 * would lose work they believed they had saved. It is reported as an error
 * against its row number.
 *
 * @param array $rows        each a field => value map
 * @param array $positionMap "crewId:stepId" => ['crew_id','step_id','crew_name','step_name']
 * @return array ['added' => int, 'errors' => array]
 */
function save_invoices_bulk(array $rows, array $project, array $positionMap, string $who = ''): array {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can record an invoice.');
    $out = ['added' => 0, 'errors' => []];

    foreach ($rows as $i => $row) {
        $amount = trim((string)($row['amount'] ?? ''));

        // What counts as "somebody typed in this row" — deliberately not the
        // fields that carry a default (currency, issued date, status), which
        // are filled on every row whether it is used or not.
        $typed = '';
        foreach (['crew_name', 'invoice_ref', 'notes', 'document_url', 'position',
                  'period_start', 'period_end', 'due_date', 'paid_date'] as $f) {
            $typed .= trim((string)($row[$f] ?? ''));
        }

        if ($amount === '') {
            if ($typed !== '') {
                $out['errors'][] = sprintf(
                    'Row %d has details in it but no amount, so it was not recorded. '
                    . 'Add the amount and save again.', $i + 1
                );
            }
            continue;
        }

        // The picker's value is "crewId:stepId". Resolved HERE rather than
        // trusted from hidden fields the browser filled in: three hidden inputs
        // per row is three chances for the name and the id to disagree, and the
        // id is the half that decides which projection row this lands on.
        $pos = trim((string)($row['position'] ?? ''));
        if ($pos !== '' && isset($positionMap[$pos])) {
            $row = array_merge($row, $positionMap[$pos]);
        }

        try {
            save_invoice($row, $project, $who);
            $out['added']++;
        } catch (Throwable $e) {
            $out['errors'][] = sprintf('Row %d was not recorded: %s', $i + 1, $e->getMessage());
        }
    }
    return $out;
}

/**
 * Change only the note on an invoice already recorded — the Notes box in the
 * invoice list. Deliberately NOT save_invoice(): that writes every column from
 * what it is given, so a form carrying just the note would wipe the amount, the
 * dates and the status. An empty box clears the note.
 */
function save_invoice_notes(int $id, string $notes, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');
    $notes = trim($notes);
    $db->prepare('UPDATE invoices SET notes = ?, updated_by = ? WHERE id = ?')
       ->execute([$notes !== '' ? $notes : null, $who, $id]);
    return $notes !== '' ? 'Note saved.' : 'Note cleared.';
}

/** Remove an invoice. */
function delete_invoice(int $id): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');
    $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    return 'Invoice removed.';
}

/** "12,500.00 USD" — an amount without the per-day suffix fmt_money() adds. */
function fmt_money_plain(?float $amount, string $currency = 'USD'): string {
    if ($amount === null) return '—';
    return number_format($amount, 2) . ' ' . $currency;
}

/** Has finance_install_03.sql been imported? Invoices cannot name a position without it. */
function invoices_need_upgrade(): bool {
    if (!can_see_money() || !finance_configured()) return false;
    return !finance_column_exists('invoices', 'step_id');
}
