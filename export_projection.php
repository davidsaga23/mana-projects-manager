<?php
// The expense projection as an Excel file, in the shape of David's own sheet.
//
// RTC_ExpenseProjection_v01.xlsx is the specification, read column for column
// rather than approximated — the same way it was read when the Finance screen
// was built (DECISIONS, 2026-08-18). Fifteen columns, a band per department,
// a subtotal per department, a grand total, and a small summary block at the
// bottom.
//
// ADMIN ONLY. Every figure in it is money, and it goes through
// expense_projection() — the one calculation the screen, the hub and the person
// page all read — so a downloaded file can never disagree with the page it came
// from. That is the whole reason it is generated rather than kept as a
// spreadsheet somebody updates by hand.

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

$back = 'finance_project.php?project=' . $projectId;

// Reported, never a broken download. A file that will not open is the worst
// possible version of "nothing failed visibly" (lesson 7).
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

// --- the sheet's own column order, read from the file ------------------------
$header = [
    'Department', 'Role / Position', 'Artist Name', 'Rate Type', 'Day Rate (' . $cur . ')',
    'Total Days', 'Total Months', 'Projected Cost (' . $cur . ')', 'Total units count',
    'Actual day rate', 'Actual Days', 'Actual Cost (' . $cur . ')', 'Projection vs actuals',
    'Monthly cost (ref)', 'Notes',
];

$rows = [];

$rows[] = [['v' => $project['code'] . ': ' . $project['name'] . ' — Expense projection', 's' => XLSX_TITLE]];
$rows[] = [
    ['v' => 'Generated ' . date('Y-m-d H:i') . ' by ' . current_user_display()
          . '. Computed live from the pipeline — not a stored copy.', 's' => XLSX_DEFAULT],
];
// The retake allowance is stated in the file, because it is the single setting
// that makes these numbers differ from a sheet built by hand. A reader
// comparing the two needs to know which side applied it.
$rows[] = [
    ['v' => $retakePct > 0
        ? 'Retake allowance: +' . fmt_num($retakePct, 0) . '% applied to days per item.'
        : 'Retake allowance: NONE set on this project, so no uplift is applied. '
          . 'A hand-built sheet carrying "+10% RTK" will read about 10% higher on every '
          . 'day-rate line. Set it on the wizard\'s Basics screen.',
     's' => XLSX_DEFAULT],
];
$rows[] = [];

$rows[] = array_map(function ($h) { return ['v' => $h, 's' => XLSX_HEAD]; }, $header);

/** A blank cell carrying the band fill, so the colour runs the width of the sheet. */
$band = function (string $label) use ($header) {
    $row = [['v' => $label, 's' => XLSX_GROUP]];
    for ($i = 1; $i < count($header); $i++) $row[] = ['v' => '', 's' => XLSX_GROUP];
    return $row;
};

$grandCost = 0.0; $grandDays = 0.0; $grandActual = 0.0; $people = 0;

foreach ($proj['by_department'] as $dept => $group) {
    $rows[] = $band($dept);

    foreach ($group['rows'] as $r) {
        $rateUnit = $r['rate_unit'] ?: 'day';
        $monthly  = ($r['rate'] !== null && $rateUnit === 'day')
                  ? (float)$r['rate'] * DAYS_PER_MONTH : null;

        $rows[] = [
            $dept,
            $r['role'],
            // A slot is named as what it is. The sheet writes "Animatic Artist
            // 01" where the platform generates "Animatic"; both are the same
            // idea and the platform's is the one that is actually linked to
            // scheduled work, so it wins.
            $r['artist'] !== null ? $r['artist'] . ($r['is_slot'] ? ' (slot)' : '') : '',
            $rateUnit,
            $r['rate'] !== null ? ['v' => (float)$r['rate'], 's' => XLSX_MONEY] : '',
            ['v' => round((float)$r['days'], 2), 's' => XLSX_NUM],
            ['v' => round((float)$r['months'], 4), 's' => XLSX_NUM],
            $r['cost'] !== null ? ['v' => round((float)$r['cost'], 2), 's' => XLSX_MONEY] : '',
            $r['units'] > 0 ? ['v' => round((float)$r['units'], 2), 's' => XLSX_NUM] : '',
            '',                                            // Actual day rate — not tracked
            $r['actual_days'] > 0 ? ['v' => round((float)$r['actual_days'], 2), 's' => XLSX_NUM] : '',
            $r['actual_cost'] !== null ? ['v' => round((float)$r['actual_cost'], 2), 's' => XLSX_MONEY] : '',
            $r['cost'] !== null ? ['v' => round((float)$r['cost'], 2), 's' => XLSX_MONEY] : '',
            $monthly !== null ? ['v' => round($monthly, 2), 's' => XLSX_MONEY] : '',
            $r['rate'] === null ? 'No rate on file — this row is unpriced, not free' : '',
        ];

        $people++;
        $grandDays += (float)$r['days'];
    }

    $rows[] = [
        ['v' => $dept . ' subtotal', 's' => XLSX_BOLD], '', '', '', '',
        ['v' => round((float)$group['days'], 2), 's' => XLSX_NUM], '',
        ['v' => round((float)$group['cost'], 2), 's' => XLSX_MONEY_B], '', '', '',
        ['v' => round((float)$group['actual'], 2), 's' => XLSX_MONEY_B],
        ['v' => round((float)$group['cost'], 2), 's' => XLSX_MONEY_B], '', '',
    ];
    $grandCost   += (float)$group['cost'];
    $grandActual += (float)$group['actual'];
}

// --- costs with no lane behind them -----------------------------------------
// Included so the file reconciles with the screen. Leaving them out would make
// the export's grand total quietly smaller than the Projected cost it came from.
if (!empty($proj['other'])) {
    $rows[] = $band('Other expenses — not somebody\'s time');
    foreach ($proj['by_category'] as $cat => $g) {
        foreach ($g['rows'] as $ex) {
            $rows[] = [
                'Other expenses',
                $cat,
                $ex['vendor'] ?: '',
                $ex['status'],
                '', '', '',
                ['v' => round((float)$ex['amount'], 2), 's' => XLSX_MONEY],
                '', '', '',
                !empty($ex['paid_date']) ? ['v' => round((float)$ex['amount'], 2), 's' => XLSX_MONEY] : '',
                ['v' => round((float)$ex['amount'], 2), 's' => XLSX_MONEY],
                '',
                trim($ex['description'] . ($ex['notes'] ? ' — ' . $ex['notes'] : '')),
            ];
        }
    }
    $rows[] = [
        ['v' => 'Other expenses subtotal', 's' => XLSX_BOLD], '', '', '', '', '', '',
        ['v' => round((float)$proj['other_total'], 2), 's' => XLSX_MONEY_B], '', '', '',
        ['v' => round((float)$proj['other_paid'], 2), 's' => XLSX_MONEY_B],
        ['v' => round((float)$proj['other_total'], 2), 's' => XLSX_MONEY_B], '', '',
    ];
    $grandCost   += (float)$proj['other_total'];
    $grandActual += (float)$proj['other_paid'];
}

$rows[] = [];
$rows[] = [
    ['v' => 'GRAND TOTAL — ' . $project['name'], 's' => XLSX_BOLD], '', '', '', '',
    ['v' => round($grandDays, 2), 's' => XLSX_NUM], '',
    ['v' => round($grandCost, 2), 's' => XLSX_MONEY_B], '', '', '',
    ['v' => round($grandActual, 2), 's' => XLSX_MONEY_B],
    ['v' => round($grandCost, 2), 's' => XLSX_MONEY_B], '', '',
];

// --- the summary block the sheet ends with ----------------------------------
$rows[] = [];
$rows[] = [['v' => 'Summary', 's' => XLSX_BOLD]];
$rows[] = ['Total headcount (artists + slots)', ['v' => $people, 's' => XLSX_DEFAULT]];
$rows[] = ['Total projected person-days', ['v' => round($grandDays, 2), 's' => XLSX_NUM]];
$rows[] = ['Total projected person-months', ['v' => round($grandDays / DAYS_PER_MONTH, 2), 's' => XLSX_NUM]];
$rows[] = ['Projected cost', ['v' => round($grandCost, 2), 's' => XLSX_MONEY_B]];

if ($budget && $budget['budget_amount'] !== null) {
    $rows[] = ['Agreed budget', ['v' => round((float)$budget['budget_amount'], 2), 's' => XLSX_MONEY]];
    $rows[] = ['Margin', ['v' => round((float)$budget['budget_amount'] - $grandCost, 2), 's' => XLSX_MONEY_B]];
}
if ($proj['unpriced_days'] > 0) {
    $rows[] = [
        ['v' => 'Person-days with NO rate behind them', 's' => XLSX_BOLD],
        ['v' => round((float)$proj['unpriced_days'], 2), 's' => XLSX_NUM],
        'The projected cost above counts only what it could price. It is a floor, not a total.',
    ];
}
if ($proj['mixed_currency']) {
    $rows[] = ['', '', 'People on this show are paid in more than one currency and these totals '
                     . 'simply add the numbers. Nothing here converts between currencies.'];
}
$rows[] = ['A day is', ['v' => round($hoursDay, 2), 's' => XLSX_NUM], 'hours on this project. '
         . 'Months are days / ' . (int)DAYS_PER_MONTH . ', the divisor the reference sheet uses.'];

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
