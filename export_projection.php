<?php
// The expense projection as an Excel file, in the shape of David's own sheet.
//
// RTC_ExpenseProjection_v01.xlsx is the specification, opened and parsed rather
// than approximated — its fifteen columns, its orange palette, its number
// formats (negatives in brackets, a zero as a dash) and, since 2026-08-17, ITS
// FORMULAS.
//
// WHY LIVE FORMULAS RATHER THAN FLAT NUMBERS, which is the whole point of the
// rewrite: a budget spreadsheet is something a producer ARGUES WITH. Type a
// different day rate into E and the row, the department subtotal, the grand
// total and the margin all move — which is what his own sheet does and what a
// wall of frozen numbers cannot. The values this app computed are still written
// into the cache beside each formula, so anything without a formula engine
// (Google Sheets preview, Quick Look, a phone) shows the right figures too.
//
// ADMIN ONLY, and every figure goes through expense_projection() — the one
// calculation the screen, the hub and the person page all read — so a
// downloaded file can never disagree with the page it came from.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/finance.php';
require_once __DIR__ . '/includes/xlsx.php';
require_admin();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: finance.php');
    exit;
}

$back = 'finance_project.php?project=' . $projectId . '&tab=projection';

// Reported, never a broken download (lesson 7).
if (!xlsx_available()) {
    flash('err', 'This server has no ZipArchive extension, so it cannot build an Excel file. '
               . 'Ask the host to enable php-zip. The CSV export on the project screen still works.');
    header('Location: ' . $back);
    exit;
}

try {
    $proj   = expense_projection($pdo, $projectId);
    $budget = project_budget($projectId);
} catch (Throwable $e) {
    flash('err', 'The projection could not be worked out, so nothing was exported: ' . $e->getMessage());
    header('Location: ' . $back);
    exit;
}

$cur       = $proj['currency'];
$retakePct = project_retake_pct($pdo, $projectId);
$hoursDay  = $proj['hours_per_day'];

/* The sheet's own column order. The letters are referenced by every formula
   below, so this list and those references have to move together. */
const COL_DEPT = 0, COL_ROLE = 1, COL_ARTIST = 2, COL_RATETYPE = 3, COL_RATE = 4,
      COL_DAYS = 5, COL_MONTHS = 6, COL_COST = 7, COL_UNITS = 8, COL_ARATE = 9,
      COL_ADAYS = 10, COL_ACOST = 11, COL_VS = 12, COL_MONTHLY = 13, COL_NOTES = 14;
const COL_COUNT = 15;

$header = [
    'Department', 'Role / Position', 'Artist Name', 'Rate Type', 'Day Rate (' . $cur . ')',
    'Total Days', 'Total Months', 'Projected Cost (' . $cur . ')', 'Total units count',
    'Actual day rate', 'Actual Days', 'Actual Cost (' . $cur . ')', 'Projection vs actuals',
    'Monthly cost (ref)', 'Notes',
];

$rows = [];
$r = 0;                       // the 1-based row number of the row just appended

/** Append a row and return the number it landed on. */
$put = function (array $cells) use (&$rows, &$r) {
    $rows[] = $cells;
    return ++$r;
};

/** A full-width band so the fill runs to the last column. */
$band = function (string $label, int $style) {
    $row = [['v' => $label, 's' => $style]];
    for ($i = 1; $i < COL_COUNT; $i++) $row[] = ['v' => '', 's' => $style];
    return $row;
};

// --- the title block --------------------------------------------------------
$put($band($project['code'] . ': ' . $project['name'] . ' — Expense projection', XLSX_TITLE));
$put([['v' => 'Generated ' . date('Y-m-d H:i') . ' by ' . current_user_display()
            . ' — computed live from the pipeline, not a stored copy. Blue cells are formulas: '
            . 'change a rate and the totals follow.', 's' => XLSX_NOTE]]);
$put([['v' => $retakePct > 0
        ? 'Retake allowance: +' . fmt_num($retakePct, 0) . '% applied to days per item.'
        : 'Retake allowance: NONE set on this project, so no uplift is applied. A hand-built '
          . 'sheet carrying "+10% RTK" reads about 10% higher on every day-rate line.',
       's' => XLSX_NOTE]]);
$put([]);
$put(array_map(function ($h) { return ['v' => $h, 's' => XLSX_HEAD]; }, $header));

$subtotalRows = [];
$firstDataRow = null; $lastDataRow = null;
$grandCost = 0.0; $grandDays = 0.0; $grandActual = 0.0; $people = 0;

// --- one block per department ----------------------------------------------
foreach ($proj['by_department'] as $dept => $group) {
    $put($band($dept, XLSX_BAND));

    $blockFirst = null; $blockLast = null;
    $shade = false;

    foreach ($group['rows'] as $row) {
        $shade = !$shade;                       // alternate the row fill
        $rateUnit = $row['rate_unit'] ?: 'day';
        $rate     = $row['rate'];
        $days     = round((float)$row['days'], 2);

        // The row number this will land on, needed by its own formulas.
        $rn = $r + 1;
        $E = 'E' . $rn; $F = 'F' . $rn; $H = 'H' . $rn; $L = 'L' . $rn;

        // A DAY rate is the only one where cost = rate x days, so it is the only
        // one that gets the formula. A flat fee is not multiplied by anything —
        // that is what flat means — and an hourly rate goes through the
        // project's hours-per-day, which is not a column in this sheet. Writing
        // E*F on those would produce a confidently wrong number the moment
        // somebody edited the rate.
        $liveCost = ($rate !== null && $rateUnit === 'day');

        $cells = [];
        $cells[COL_DEPT]     = ['v' => $dept, 's' => xlsx_alt(XLSX_TEXT, $shade)];
        $cells[COL_ROLE]     = ['v' => $row['role'], 's' => xlsx_alt(XLSX_TEXT, $shade)];
        $cells[COL_ARTIST]   = ['v' => $row['artist'] !== null
                                    ? $row['artist'] . ($row['is_slot'] ? ' (slot)' : '') : '',
                                's' => xlsx_alt(XLSX_TEXT, $shade)];
        $cells[COL_RATETYPE] = ['v' => $rateUnit, 's' => xlsx_alt(XLSX_TEXT, $shade)];
        $cells[COL_RATE]     = ['v' => $rate !== null ? (float)$rate : '',
                                's' => xlsx_alt(XLSX_MONEY, $shade)];
        $cells[COL_DAYS]     = ['v' => $days, 's' => xlsx_alt(XLSX_NUM, $shade)];
        $cells[COL_MONTHS]   = ['f' => 'IFERROR(' . $F . '/22,0)',
                                'v' => round((float)$row['months'], 4),
                                's' => xlsx_alt(XLSX_NUM_CALC, $shade)];

        if ($liveCost) {
            $cells[COL_COST] = ['f' => 'IFERROR(' . $E . '*' . $F . ',0)',
                                'v' => round((float)$row['cost'], 2),
                                's' => xlsx_alt(XLSX_MONEY_CALC, $shade)];
        } else {
            $cells[COL_COST] = ['v' => $row['cost'] !== null ? round((float)$row['cost'], 2) : '',
                                's' => xlsx_alt(XLSX_MONEY, $shade)];
        }

        $cells[COL_UNITS] = ['v' => $row['units'] > 0 ? round((float)$row['units'], 2) : '',
                             's' => xlsx_alt(XLSX_NUM, $shade)];
        $cells[COL_ARATE] = ['v' => '', 's' => xlsx_alt(XLSX_MONEY, $shade)];
        $cells[COL_ADAYS] = ['v' => $row['actual_days'] > 0 ? round((float)$row['actual_days'], 2) : '',
                             's' => xlsx_alt(XLSX_NUM, $shade)];
        $cells[COL_ACOST] = ['v' => $row['actual_cost'] !== null ? round((float)$row['actual_cost'], 2) : '',
                             's' => xlsx_alt(XLSX_MONEY, $shade)];
        $cells[COL_VS]    = ['f' => 'IFERROR(' . $H . '-' . $L . ',0)',
                             'v' => round((float)($row['cost'] ?? 0) - (float)($row['actual_cost'] ?? 0), 2),
                             's' => xlsx_alt(XLSX_MONEY_CALC, $shade)];
        $cells[COL_MONTHLY] = $liveCost
            ? ['f' => 'IFERROR(' . $E . '*22,0)', 'v' => round((float)$rate * 22, 2),
               's' => xlsx_alt(XLSX_MONEY_CALC, $shade)]
            : ['v' => '', 's' => xlsx_alt(XLSX_MONEY, $shade)];
        $cells[COL_NOTES] = ['v' => $rate === null
                                ? 'No rate on file — unpriced, not free'
                                : ($rateUnit === 'hour'
                                    ? 'Hourly, converted at ' . fmt_num($hoursDay, 1) . ' h/day'
                                    : ($rateUnit === 'flat' ? 'Flat fee — not rate x days' : '')),
                             's' => xlsx_alt(XLSX_NOTE, $shade)];

        ksort($cells);
        $landed = $put($cells);
        if ($blockFirst === null) $blockFirst = $landed;
        $blockLast = $landed;
        if ($firstDataRow === null) $firstDataRow = $landed;
        $lastDataRow = $landed;

        $people++;
        $grandDays += (float)$row['days'];
    }

    // --- the department subtotal, as a real SUM over the rows above it -------
    $sub = [];
    $sub[COL_DEPT]  = ['v' => $dept . ' subtotal', 's' => XLSX_SUB_LABEL];
    for ($i = 1; $i < COL_COUNT; $i++) $sub[$i] = ['v' => '', 's' => XLSX_SUB_LABEL];
    if ($blockFirst !== null) {
        $sub[COL_DAYS]  = ['f' => 'SUM(F' . $blockFirst . ':F' . $blockLast . ')',
                           'v' => round((float)$group['days'], 2), 's' => XLSX_SUB_NUM];
        $sub[COL_COST]  = ['f' => 'SUM(H' . $blockFirst . ':H' . $blockLast . ')',
                           'v' => round((float)$group['cost'], 2), 's' => XLSX_SUB_MONEY];
        $sub[COL_ACOST] = ['f' => 'SUM(L' . $blockFirst . ':L' . $blockLast . ')',
                           'v' => round((float)$group['actual'], 2), 's' => XLSX_SUB_MONEY];
        $sub[COL_VS]    = ['f' => 'SUM(M' . $blockFirst . ':M' . $blockLast . ')',
                           'v' => round((float)$group['cost'] - (float)$group['actual'], 2),
                           's' => XLSX_SUB_MONEY];
    }
    ksort($sub);
    $subtotalRows[] = $put($sub);

    $grandCost   += (float)$group['cost'];
    $grandActual += (float)$group['actual'];
}

// --- costs with no lane behind them ----------------------------------------
// Included so the file reconciles with the screen: leaving them out would make
// the exported grand total quietly smaller than the Projected cost it came from.
if (!empty($proj['other'])) {
    $put($band('Other expenses — not somebody\'s time', XLSX_BAND));
    $blockFirst = null; $blockLast = null; $shade = false;

    foreach ($proj['by_category'] as $cat => $g) {
        foreach ($g['rows'] as $ex) {
            $shade = !$shade;
            $rn = $r + 1;
            $cells = [];
            $cells[COL_DEPT]     = ['v' => 'Other expenses', 's' => xlsx_alt(XLSX_TEXT, $shade)];
            $cells[COL_ROLE]     = ['v' => $cat, 's' => xlsx_alt(XLSX_TEXT, $shade)];
            $cells[COL_ARTIST]   = ['v' => $ex['vendor'] ?: '', 's' => xlsx_alt(XLSX_TEXT, $shade)];
            $cells[COL_RATETYPE] = ['v' => $ex['status'], 's' => xlsx_alt(XLSX_TEXT, $shade)];
            $cells[COL_COST]     = ['v' => round((float)$ex['amount'], 2),
                                    's' => xlsx_alt(XLSX_MONEY, $shade)];
            $cells[COL_ACOST]    = ['v' => !empty($ex['paid_date']) ? round((float)$ex['amount'], 2) : '',
                                    's' => xlsx_alt(XLSX_MONEY, $shade)];
            $cells[COL_VS]       = ['f' => 'IFERROR(H' . $rn . '-L' . $rn . ',0)',
                                    'v' => round((float)$ex['amount']
                                          - (!empty($ex['paid_date']) ? (float)$ex['amount'] : 0), 2),
                                    's' => xlsx_alt(XLSX_MONEY_CALC, $shade)];
            $cells[COL_NOTES]    = ['v' => trim($ex['description'] . ($ex['notes'] ? ' — ' . $ex['notes'] : '')),
                                    's' => xlsx_alt(XLSX_NOTE, $shade)];
            for ($i = 0; $i < COL_COUNT; $i++) {
                if (!isset($cells[$i])) $cells[$i] = ['v' => '', 's' => xlsx_alt(XLSX_TEXT, $shade)];
            }
            ksort($cells);
            $landed = $put($cells);
            if ($blockFirst === null) $blockFirst = $landed;
            $blockLast = $landed;
            if ($firstDataRow === null) $firstDataRow = $landed;
            $lastDataRow = $landed;
        }
    }

    $sub = [];
    $sub[COL_DEPT] = ['v' => 'Other expenses subtotal', 's' => XLSX_SUB_LABEL];
    for ($i = 1; $i < COL_COUNT; $i++) $sub[$i] = ['v' => '', 's' => XLSX_SUB_LABEL];
    if ($blockFirst !== null) {
        $sub[COL_COST]  = ['f' => 'SUM(H' . $blockFirst . ':H' . $blockLast . ')',
                           'v' => round((float)$proj['other_total'], 2), 's' => XLSX_SUB_MONEY];
        $sub[COL_ACOST] = ['f' => 'SUM(L' . $blockFirst . ':L' . $blockLast . ')',
                           'v' => round((float)$proj['other_paid'], 2), 's' => XLSX_SUB_MONEY];
    }
    ksort($sub);
    $subtotalRows[] = $put($sub);

    $grandCost   += (float)$proj['other_total'];
    $grandActual += (float)$proj['other_paid'];
}

// --- the grand total, summing the SUBTOTAL CELLS ---------------------------
// H6+H22+H42+H49 in his sheet — the subtotals, not the data rows. Adding the
// rows again would double-count nothing today, but it would break the moment a
// subtotal were edited by hand, and it is not how the sheet reads.
$put([]);
$grand = [];
$grand[COL_DEPT] = ['v' => 'GRAND TOTAL — ' . $project['name'], 's' => XLSX_GRAND_LABEL];
for ($i = 1; $i < COL_COUNT; $i++) $grand[$i] = ['v' => '', 's' => XLSX_GRAND_LABEL];
if ($subtotalRows) {
    $sumOf = function (string $col) use ($subtotalRows) {
        $bits = [];
        foreach ($subtotalRows as $sr) $bits[] = $col . $sr;
        return implode('+', $bits);
    };
    $grand[COL_DAYS]  = ['f' => $sumOf('F'), 'v' => round($grandDays, 2),  's' => XLSX_GRAND_MONEY];
    $grand[COL_COST]  = ['f' => $sumOf('H'), 'v' => round($grandCost, 2),  's' => XLSX_GRAND_MONEY];
    $grand[COL_ACOST] = ['f' => $sumOf('L'), 'v' => round($grandActual, 2), 's' => XLSX_GRAND_MONEY];
    $grand[COL_VS]    = ['f' => $sumOf('M'), 'v' => round($grandCost - $grandActual, 2),
                         's' => XLSX_GRAND_MONEY];
}
ksort($grand);
$grandRow = $put($grand);

// --- the summary block his sheet ends with ---------------------------------
$put([]);
$put([['v' => 'Summary', 's' => XLSX_SUB_LABEL]]);

if ($firstDataRow !== null) {
    $put([['v' => 'Total headcount (artists + slots)', 's' => XLSX_TEXT],
          ['f' => 'COUNTA(C' . $firstDataRow . ':C' . $lastDataRow . ')',
           'v' => $people, 's' => XLSX_NUM_CALC]]);
    $put([['v' => 'Total projected person-days', 's' => XLSX_TEXT],
          ['f' => 'SUM(F' . $firstDataRow . ':F' . $lastDataRow . ')',
           'v' => round($grandDays, 2), 's' => XLSX_NUM_CALC]]);
    $put([['v' => 'Total projected person-months', 's' => XLSX_TEXT],
          ['f' => 'IFERROR(SUM(F' . $firstDataRow . ':F' . $lastDataRow . ')/22,0)',
           'v' => round($grandDays / DAYS_PER_MONTH, 2), 's' => XLSX_NUM_CALC]]);
}
$put([['v' => 'Projected cost', 's' => XLSX_TEXT],
      ['f' => 'H' . $grandRow, 'v' => round($grandCost, 2), 's' => XLSX_MONEY_CALC]]);

if ($budget && $budget['budget_amount'] !== null) {
    $budgetRow = $put([['v' => 'Agreed budget', 's' => XLSX_TEXT],
                       ['v' => round((float)$budget['budget_amount'], 2), 's' => XLSX_MONEY]]);
    $put([['v' => 'Margin', 's' => XLSX_TEXT],
          ['f' => 'B' . $budgetRow . '-H' . $grandRow,
           'v' => round((float)$budget['budget_amount'] - $grandCost, 2), 's' => XLSX_MONEY_CALC]]);
}
if ($proj['unpriced_days'] > 0) {
    $put([['v' => 'Person-days with NO rate behind them', 's' => XLSX_WARN],
          ['v' => round((float)$proj['unpriced_days'], 2), 's' => XLSX_NUM],
          ['v' => 'The projected cost counts only what it could price. It is a floor, not a total.',
           's' => XLSX_NOTE]]);
}
if ($proj['mixed_currency']) {
    $put([['v' => '', 's' => XLSX_DEFAULT], ['v' => '', 's' => XLSX_DEFAULT],
          ['v' => 'People on this show are paid in more than one currency and these totals simply '
                . 'add the numbers. Nothing here converts between currencies.', 's' => XLSX_NOTE]]);
}
$put([['v' => 'A day is', 's' => XLSX_TEXT],
      ['v' => round($hoursDay, 2), 's' => XLSX_NUM],
      ['v' => 'hours on this project. Months are days / ' . (int)DAYS_PER_MONTH
            . ', the divisor the reference sheet uses.', 's' => XLSX_NOTE]]);

$widths = [0 => 20, 1 => 26, 2 => 24, 3 => 10, 4 => 13, 5 => 11, 6 => 13, 7 => 17,
           8 => 16, 9 => 14, 10 => 12, 11 => 16, 12 => 20, 13 => 17, 14 => 46];

try {
    $bytes = xlsx_build('Expense projection', $rows, $widths);
} catch (Throwable $e) {
    flash('err', 'The Excel file could not be built, so nothing was downloaded: ' . $e->getMessage());
    header('Location: ' . $back);
    exit;
}

xlsx_send($bytes, $project['code'] . '_ExpenseProjection_' . date('Ymd') . '.xlsx');
