<?php
// What a show has cost so far, and what stopping it would still cost.
//
// David, 2026-10-06, the day FCM went on standby: his boss needs a number to
// take to the client — "how much we've spent or committed to pay the current
// crew to this day".
//
// ⚠ THAT IS FOUR NUMBERS, NOT ONE, AND THIS FILE REFUSES TO BLEND THEM. They
// differ in how defensible they are, and a single figure invites exactly one
// question — "how did you get that?" — which the blend cannot survive. In
// descending order of hardness:
//
//   1. INVOICED AND PAID     money that has left the building. Unarguable.
//   2. INVOICED, UNPAID      somebody billed; the document exists. Unarguable.
//   3. WORKED, NOT INVOICED  hours logged that nobody has billed for yet.
//                            ⚠ AN ESTIMATE, and labelled one everywhere it is
//                            shown. DECISIONS, 2026-08-18: "actual cost comes
//                            from invoices, not from days times a rate — that
//                            product is a second estimate wearing an actual's
//                            clothes." That rule is not weakened here. It is
//                            still the right number to quote, because the only
//                            alternative is pretending the work did not happen.
//   4. BOOKED BEYOND TODAY   what the plan still has people down for.
//                            ⚠ NOT A CONTRACTUAL COMMITMENT, and this file says
//                            so rather than letting the screen imply it.
//
// ⚠ WHAT THIS CANNOT ANSWER, said here because it is the question actually
// being asked. "What do we owe if the show never restarts" needs a contract
// start, an end, a notice period or a guaranteed minimum. `crew_terms` holds a
// rate, a currency, a contract URL and notes — and none of those four. So the
// true commitment has to be read out of the contracts by hand, one at a time.
// Number 4 is the nearest thing available and it is the right order of
// magnitude, which is not the same as being right. See ROADMAP, 2026-10-06.
//
// ⚠ AND NOTHING HERE ADDS TWO CURRENCIES TOGETHER. Rates carry their own, there
// is no FX table anywhere in this platform, and inventing a rate on the screen
// that goes to a client would be the worst possible place to do it. Every total
// is per currency, and a show paying in two reports two totals.

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/finance.php';   // fmt_money_plain()
require_once __DIR__ . '/fx.php';        // the project's fixed exchange rates
require_once __DIR__ . '/timelog.php';   // hours_per_day()
require_once __DIR__ . '/calendar.php';

/**
 * The four numbers, for one project.
 *
 * $invoices is passed in rather than re-read: finance_project.php already has
 * them, and a second query is a second chance for the two to disagree.
 *
 * @return array{
 *   paid: array<string,float>, unpaid: array<string,float>,
 *   worked: array, booked: array, unpriced: array, flat: array,
 *   currencies: array<int,string>, mixed: bool, ok: bool, why: string
 * }
 */
function project_stop_cost(PDO $pdo, int $projectId, array $invoices): array {
    $out = [
        'paid'       => [],   // currency => amount
        'unpaid'     => [],
        'worked'     => ['rows' => [], 'totals' => [], 'hours' => 0.0],
        'booked'     => ['rows' => [], 'totals' => [], 'days' => 0.0],
        'unpriced'   => [],   // logged hours, nobody set a rate
        'flat'       => [],   // paid a flat fee: hours cannot price it
        'currencies' => [],
        'mixed'      => false,
        'ok'         => true,
        'why'        => '',
    ];

    if (!can_see_money()) {
        $out['ok'] = false;
        $out['why'] = 'Rates are administrators-only, so this cannot be worked out here.';
        return $out;
    }

    // ---- 1 and 2: the invoices ---------------------------------------------
    // Per currency from the start. An invoice carries its own, and the two
    // halves must split the same way or the paid and unpaid totals on screen
    // would be in different money from each other.
    //
    // ⚠ A CURRENCY WITH A FIXED PROJECT RATE BECOMES DOLLARS (Tim and Jorik,
    // 2026-10-28). That rate is the studio's own, named and dated on the
    // Overview, which is what the warning below always asked for: "a rate you
    // can name and date". Here and in sections 3 and 4. A currency with no
    // fixed rate still keeps its own total and the warning.
    $fx = project_fx_rates($projectId);
    foreach ($invoices as $iv) {
        [$amt, $c] = fx_usd_or_own((float)$iv['amount'], $iv['currency'] ?? 'USD', $fx);
        if (!empty($iv['paid_date'])) $out['paid'][$c]   = ($out['paid'][$c]   ?? 0) + $amt;
        else                          $out['unpaid'][$c] = ($out['unpaid'][$c] ?? 0) + $amt;
    }

    // ---- 3: worked but not yet invoiced ------------------------------------
    // ⚠ FROM `time_logs`, NOT `progress.time_spent_minutes`. The second is what
    // Plaky said at the last import and is a running total nobody here wrote;
    // time_logs is what people in this platform actually logged, with the date
    // the work HAPPENED. timelog_by_person() reads the former and is therefore
    // the wrong helper for a figure that goes to a client.
    $hoursPerDay = hours_per_day($pdo, $projectId);
    $logged = [];
    try {
        $q = $pdo->prepare(
            'SELECT c.id, c.name, COALESCE(SUM(tl.minutes), 0) AS minutes
               FROM time_logs tl
               JOIN crew c ON c.id = tl.crew_id
              WHERE tl.project_id = ?
              GROUP BY c.id, c.name
              HAVING minutes > 0
              ORDER BY minutes DESC, c.name'
        );
        $q->execute([$projectId]);
        $logged = $q->fetchAll();
    } catch (PDOException $e) {
        // time_logs arrives in migration 022. A project on an older database
        // gets the invoice half and is told why the rest is missing, rather
        // than being shown a zero that reads as "nobody has worked".
        $out['why'] = 'Logged hours are unavailable on this database (migration 022 '
                    . 'creates time_logs), so nothing can be said about work that has '
                    . 'not been invoiced yet.';
    }

    $crewIds = array_map(fn($r) => (int)$r['id'], $logged);
    $terms = $crewIds ? crew_terms($crewIds) : [];

    foreach ($logged as $r) {
        $id = (int)$r['id'];
        $hours = round(((int)$r['minutes']) / 60, 2);
        $out['worked']['hours'] += $hours;
        $t = $terms[$id] ?? null;
        $rate = $t && $t['day_rate'] !== null ? (float)$t['day_rate'] : null;
        $unit = $t['rate_unit'] ?? 'day';
        $c    = strtoupper(trim((string)($t['rate_currency'] ?? 'USD'))) ?: 'USD';

        // ⚠ NAMED, NOT ZEROED. Somebody with hours and no rate on file is the
        // single most expensive thing this screen could get wrong: a silent 0
        // makes the total look complete and understates what is owed to a real
        // person. Lesson 11 — a screen that quietly renders low is the same
        // failure as one that renders empty.
        if ($rate === null || $rate <= 0) {
            $out['unpriced'][] = ['name' => (string)$r['name'], 'hours' => $hours];
            continue;
        }
        // A flat fee is not a function of hours. Pricing it per hour would
        // invent a number; it is listed separately and left out of the total.
        if ($unit === 'flat') {
            [$fAmt, $fCur] = fx_usd_or_own($rate, $c, $fx);
            $out['flat'][] = ['name' => (string)$r['name'], 'hours' => $hours,
                              'amount' => $fAmt, 'currency' => $fCur,
                              'amount_own' => $fCur !== $c ? $rate : null, 'currency_own' => $c];
            continue;
        }

        $cost = $unit === 'hour' ? $hours * $rate : ($hours / $hoursPerDay) * $rate;
        $cost = round($cost, 2);
        // `rate` and `rate_currency` stay the contract's; `cost` and
        // `currency` are dollars when the project has a rate for it.
        [$usd, $cc] = fx_usd_or_own($cost, $c, $fx);
        $out['worked']['rows'][] = [
            'name' => (string)$r['name'], 'hours' => $hours,
            'rate' => $rate, 'unit' => $unit, 'rate_currency' => $c,
            'currency' => $cc, 'cost' => $usd, 'cost_own' => $cc !== $c ? $cost : null,
        ];
        $out['worked']['totals'][$cc] = ($out['worked']['totals'][$cc] ?? 0) + $usd;
    }

    // ---- 4: still booked after today ---------------------------------------
    // A lane is one full-time slot on one step, so the working days left in that
    // step's window are the days that slot is still down for. Steps with no
    // dates cannot be counted and are simply absent — there is nothing to
    // measure, and guessing a window would be inventing the answer.
    $today = date('Y-m-d');
    try {
        $q = $pdo->prepare(
            'SELECT c.id, c.name, ps.name AS step_name, ps.start_date, ps.deadline
               FROM crew_lanes cl
               JOIN crew c ON c.id = cl.crew_id
               JOIN pipeline_steps ps ON ps.id = cl.step_id
              WHERE cl.project_id = ?
                AND ps.start_date IS NOT NULL AND ps.deadline IS NOT NULL
                AND ps.deadline >= ?
                AND COALESCE(c.is_placeholder, 0) = 0
              ORDER BY c.name, ps.start_date'
        );
        $q->execute([$projectId, $today]);
        $lanes = $q->fetchAll();
    } catch (PDOException $e) {
        $lanes = [];
    }

    $laneIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $lanes)));
    $laneTerms = $laneIds ? crew_terms($laneIds) : [];

    foreach ($lanes as $r) {
        $from = max($today, (string)$r['start_date']);
        // Two arguments only: it reads the holiday calendar itself and caches it
        // statically, so a loop over every lane costs one query, not one each.
        $days = working_days_between($from, (string)$r['deadline']);
        if ($days <= 0) continue;
        $out['booked']['days'] += $days;

        $t = $laneTerms[(int)$r['id']] ?? null;
        $rate = $t && $t['day_rate'] !== null ? (float)$t['day_rate'] : null;
        $unit = $t['rate_unit'] ?? 'day';
        $c    = strtoupper(trim((string)($t['rate_currency'] ?? 'USD'))) ?: 'USD';

        // A day rate is the only unit this can use. An hourly person is costed
        // at hours-per-day, which is a planning assumption rather than a fact
        // about them; a flat fee has no per-day meaning at all. Both are shown
        // with their days and no money, which is the honest reading.
        $cost = null; $costOwn = null; $cc = '';
        if ($rate !== null && $rate > 0 && $unit !== 'flat') {
            $own = round($unit === 'hour' ? $days * $hoursPerDay * $rate : $days * $rate, 2);
            [$cost, $cc] = fx_usd_or_own($own, $c, $fx);
            if ($cc !== $c) $costOwn = $own;
            $out['booked']['totals'][$cc] = ($out['booked']['totals'][$cc] ?? 0) + $cost;
        }
        $out['booked']['rows'][] = [
            'name' => (string)$r['name'], 'step' => (string)$r['step_name'],
            'from' => $from, 'to' => (string)$r['deadline'],
            'days' => $days, 'cost' => $cost, 'currency' => $cc,
            'cost_own' => $costOwn, 'currency_own' => $c,
        ];
    }

    // ---- which currencies are in play --------------------------------------
    $seen = [];
    foreach ([$out['paid'], $out['unpaid'], $out['worked']['totals'], $out['booked']['totals']] as $set) {
        foreach (array_keys($set) as $c) $seen[$c] = true;
    }
    foreach ($out['flat'] as $f) $seen[$f['currency']] = true;
    $out['currencies'] = array_keys($seen);
    sort($out['currencies']);
    $out['mixed'] = count($out['currencies']) > 1;

    return $out;
}

/**
 * "12,400.00 USD" for each currency in a per-currency total, or an em dash.
 *
 * Joined with " + " and never summed, so a mixed-currency show reads
 * "9,000.00 USD + 4,200.00 EUR" — which is unwieldy on purpose. It is meant to
 * be noticed.
 */
function stop_cost_amounts(array $byCurrency): string {
    if (!$byCurrency) return '—';
    $parts = [];
    ksort($byCurrency);
    foreach ($byCurrency as $c => $amt) $parts[] = fmt_money_plain((float)$amt, (string)$c);
    return implode(' + ', $parts);
}
