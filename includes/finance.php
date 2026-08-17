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

/** Save the agreed budget. Returns a sentence for the flash message. */
function save_project_budget(int $projectId, array $project, array $in, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can change a budget.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet — see finance_install.sql.');

    $raw = trim((string)($in['budget_amount'] ?? ''));
    if ($raw === '') {
        $amount = null;
    } else {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $raw . '" is not a number — nothing was saved.');
        }
        $amount = round((float)$clean, 2);
        if ($amount < 0) throw new RuntimeException('A budget cannot be negative.');
    }

    $cur = strtoupper(trim((string)($in['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    $db->prepare(
        'INSERT INTO project_budgets
           (project_id, project_code, project_name, budget_amount, currency, terms, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           project_code = VALUES(project_code), project_name = VALUES(project_name),
           budget_amount = VALUES(budget_amount), currency = VALUES(currency),
           terms = VALUES(terms), notes = VALUES(notes), updated_by = VALUES(updated_by)'
    )->execute([
        $projectId, $project['code'] ?? null, $project['name'] ?? null,
        $amount, $cur,
        trim((string)($in['terms'] ?? '')) ?: null,
        trim((string)($in['notes'] ?? '')) ?: null,
        $who,
    ]);

    return $amount === null ? 'Budget cleared.' : 'Budget saved: ' . fmt_money_plain($amount, $cur) . '.';
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
 *   Total days      that lane's share of the step's person-days
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

    foreach ($steps as $step) {
        $stepId = (int)$step['id'];
        $quota = step_quota($pdo, $projectId, $step, null, $estimates, $retake);

        $units = (float)($quota['units'] ?? 0);
        $daysPerUnit = (float)($step['days_per_unit'] ?: 0) * retake_factor($retake);
        $personDays = $units * $daysPerUnit;

        $stepLanes = $lanes[$stepId] ?? [];
        $laneCount = max(1, count($stepLanes));
        $daysPerLane = $personDays / $laneCount;

        // A step with nobody on it still belongs in the sheet, as a row with no
        // artist. Leaving it out would hide work somebody still has to be paid
        // to do — the opposite of what a projection is for.
        if (!$stepLanes) {
            $stepLanes = [null];
            $daysPerLane = $personDays;
        }

        foreach ($stepLanes as $lane) {
            $cid = $lane ? (int)($lane['crew_id'] ?? 0) : 0;
            $t = $cid ? ($terms[$cid] ?? null) : null;
            $rate = ($t && $t['day_rate'] !== null) ? (float)$t['day_rate'] : null;
            $unit = $t['rate_unit'] ?? 'day';
            $cur  = $t['rate_currency'] ?? 'USD';

            $cost = null;
            if ($rate !== null) {
                // Hourly converts at the project's own hours per day. A flat fee
                // is not multiplied by anything, because that is what flat means.
                if ($unit === 'flat')     $cost = $rate;
                elseif ($unit === 'hour') $cost = $rate * $hoursPerDay * $daysPerLane;
                else                      $cost = $rate * $daysPerLane;

                $total += $cost;
                if ($currency === null) $currency = $cur;
                elseif ($currency !== $cur) $mixed = true;
            } else {
                $unpricedDays += $daysPerLane;
            }

            $actDays = $cid ? ($actual[$cid . '|' . $stepId] ?? 0.0) : 0.0;
            // Null means nothing has been invoiced against this position — which
            // is not the same as it having cost nothing, so the screen shows a
            // dash rather than a zero.
            $actCost = $cid ? ($billedBy[$cid . '|' . $stepId] ?? null) : null;
            if ($actCost !== null) $actualTotal += $actCost;

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
                'days'        => $daysPerLane,
                'months'      => $daysPerLane / DAYS_PER_MONTH,
                'cost'        => $cost,
                'units'       => $units,
                'unit_label'  => $quota['unit_label'] ?? 'items',
                'actual_days' => $actDays,
                'actual_cost' => $actCost,
                'variance'    => ($cost !== null && $actCost !== null) ? $cost - $actCost : null,
            ];
        }
    }

    // Department order as the sheet reads it, with anything unrecognised last.
    $order = ['Above the line' => 1, 'Pre-production' => 2, 'Production' => 3, 'Post-production' => 4];
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

    return [
        'rows'           => $rows,
        'by_department'  => $byDept,
        'total'          => $total,
        'actual_total'   => $actualTotal,
        'currency'       => $currency ?: 'USD',
        'mixed_currency' => $mixed,
        'unpriced_days'  => $unpricedDays,
        'hours_per_day'  => $hoursPerDay,
    ];
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
    $q = $db->prepare(
        'SELECT crew_id, step_id, SUM(amount) AS billed
           FROM invoices
          WHERE project_id = ? AND crew_id IS NOT NULL AND step_id IS NOT NULL
          GROUP BY crew_id, step_id'
    );
    $q->execute([$projectId]);
    $out = [];
    foreach ($q as $r) $out[(int)$r['crew_id'] . '|' . (int)$r['step_id']] = (float)$r['billed'];
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
    $out = [];
    foreach ($db->query(
        "SELECT project_id,
                COUNT(*) AS n,
                COALESCE(SUM(amount), 0) AS billed,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL THEN amount ELSE 0 END), 0) AS paid
           FROM invoices WHERE project_id IN ({$ids}) GROUP BY project_id"
    ) as $r) {
        $out[(int)$r['project_id']] = $r;
    }
    return $out;
}

/** Record or update an invoice. Returns a sentence for the flash message. */
function save_invoice(array $in, array $project, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can record an invoice.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');

    $raw = trim((string)($in['amount'] ?? ''));
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
    if ($clean === '' || !is_numeric($clean)) {
        throw new RuntimeException('"' . $raw . '" is not an amount — nothing was saved.');
    }
    $amount = round((float)$clean, 2);
    if ($amount <= 0) throw new RuntimeException('An invoice has to be for more than nothing.');

    $cur = strtoupper(trim((string)($in['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    $status = in_array($in['status'] ?? '', ['received','approved','paid','disputed'], true)
        ? $in['status'] : 'received';

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
                    notes = ?, updated_by = ? WHERE id = ?'
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
