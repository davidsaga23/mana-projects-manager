<?php
/**
 * THE INVOICE TEMPLATE — one freelancer's invoice for one month, filled in by
 * Anima (David, 2026-11-11: "Let's work on the invoice template now").
 *
 * WHY IT EXISTS. Freelancers send ONE invoice a month to accounting@mana.tv,
 * a line per project and position, and Jorik pays it once (the money rules in
 * CLAUDE.md). Home's Invoice and billing tab already told them what to put on
 * it; this turns that into the invoice itself, so every invoice has the same
 * layout and the same labels. That cuts wrong invoices, and it is what makes
 * the planned email intake (DECISIONS 2026-10-28, "Invoices arrive by email and
 * file themselves") reliable: one table of lines, the same field names on
 * every PDF.
 *
 * HOW IT IS MADE (David's picks, 2026-11-11):
 *   - Anima fills it in; the artist prints it to PDF from the browser ("Save as
 *     PDF"). No PDF library: a print stylesheet (assets/invoice.css) is all.
 *     The layout follows the two invoices David shared as good examples: a big
 *     "Invoice" title, number / date of issue / due date, Billed to and From,
 *     one table of lines, the total, then notes and bank account details.
 *   - Their legal name, address, tax ID and bank details are STORED in Anima,
 *     in the finance database (finance_install_18, includes/money.php).
 *   - The invoice number is suggested (MANA-2026-11-ABC) and editable.
 *   - An administrator can open anybody's, from Finance → Monthly invoices.
 *
 * THE LINES are the ones Finance → Monthly invoices prefills from, counted the
 * same way as Home (home_invoice_lines()): every show the person logged time on
 * that month, one line per show and position, at the position's own contract
 * when it has one. A show that wrapped since still gets its line, because the
 * shows are the ones with time logged in the month, not the ones open today.
 */

require_once __DIR__ . '/home.php';       // home_invoice_lines(), home_show(), home_money(), home_fx()
require_once __DIR__ . '/studio.php';     // studio_exclude_sql()

/** A well-formed 'YYYY-MM' that is not in the future; this month otherwise. */
function invoice_doc_month($m): string {
    $m = (string)$m;
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) || $m > date('Y-m')) $m = date('Y-m');
    return $m;
}

/**
 * The month's lines for one person: home_invoice_lines() over every show they
 * logged time on in the month. Pay terms come through the own-or-admin doors,
 * so an artist gets only their own and an admin gets anybody's.
 *
 * Returns home_invoice_lines()'s ['lines', 'totals', 'outside'] plus 'from',
 * 'to' (the month's last day) and 'counted_to' (today, while the month runs).
 */
function invoice_doc_lines(PDO $pdo, int $crewId, string $month): array {
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $today = date('Y-m-d');
    $countedTo = $to < $today ? $to : $today;
    $out = ['lines' => [], 'totals' => [], 'outside' => 0, 'from' => $from, 'to' => $to, 'counted_to' => $countedTo];

    $shows = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT p.* FROM time_logs tl JOIN projects p ON p.id = tl.project_id
                             WHERE tl.crew_id = ? AND tl.log_date BETWEEN ? AND ?'
                           . studio_exclude_sql($pdo, 'p') . ' ORDER BY p.name');
        $q->execute([$crewId, $from, $countedTo]);
        foreach ($q->fetchAll() as $p) $shows[(int)$p['id']] = home_show($pdo, $p, $crewId, $from, $today);
    } catch (PDOException $e) {
        return $out;    // time_logs before its migration
    }
    if (!$shows) return $out;

    $terms = own_crew_term($crewId);
    $posTerms = own_position_terms($crewId);
    return home_invoice_lines($pdo, $crewId, $shows, $terms, $from, $countedTo, $posTerms)
         + ['from' => $from, 'to' => $to, 'counted_to' => $countedTo];
}

/**
 * The suggested invoice number: MANA-2026-11-ABC, the month and the person's
 * initials (from their legal name when they gave one). A suggestion only: the
 * box is editable, for anybody whose own tax system numbers their invoices.
 */
function invoice_doc_number(string $month, string $name): string {
    $plain = $name;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if (is_string($t) && $t !== '') $plain = $t;
    }
    $initials = '';
    foreach (preg_split('/\s+/', trim($plain)) ?: [] as $w) {
        $w = preg_replace('/[^A-Za-z]/', '', $w);
        if ($w !== '') $initials .= strtoupper($w[0]);
        if (strlen($initials) >= 3) break;
    }
    return 'MANA-' . $month . ($initials !== '' ? '-' . $initials : '');
}

/** One line's description: "FC Mobile · CH Layout", then the days worked. */
function invoice_doc_description(array $l): array {
    $title = (string)$l['project']['name'] . ' · ' . (string)$l['step'];
    $sub = !empty($l['dates']) ? 'Days worked: ' . home_days_worked($l['dates']) : '';
    return [$title, $sub];
}

/** The quantity column: "5.00 days", "12.50 hours", or the hours logged for a per-second or flat line. */
function invoice_doc_qty(array $l): string {
    return (string)$l['qty_label'];
}

/** The amount column, with the contract's own currency beside it when it was converted: "$1,040.00 (£800.00)". */
function invoice_doc_amount(array $l): string {
    if ($l['total'] === null) return '';
    $s = home_money((float)$l['total'], (string)$l['currency']);
    if ($l['total_own'] !== null) $s .= ' (' . home_money((float)$l['total_own'], (string)$l['own_currency']) . ')';
    return $s;
}
