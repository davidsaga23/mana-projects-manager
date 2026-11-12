<?php
// Invoice payments — every invoice waiting to be paid, across every show, for
// Jorik (payments.php, and the "Invoices" button in the top bar).
//
// David, 2026-11-11: "let's create an invoice payment tab/button at the top
// next to reviews for Jorik. It should list all the pending invoices, access to
// the pdfs, amounts, positions, names, dates and status so Jorik can easily
// check them, pay each one of them, change their status to approved and then
// that invoice disappears from the filter (don't delete them from that view,
// just filter all the pending invoices)".
//
// ⚠ ONE ROW PER INVOICE AS JORIK RECEIVES IT, NOT PER `invoices` ROW. A monthly
// invoice (IN4, includes/invoice_split.php) is an invoice_headers row whose
// lines are ordinary invoices rows on their own projects. Jorik pays the
// document once, so it is ONE row here with its shows listed, and paying it is
// pay_invoice_header(): the header and every line, in one transaction. An
// invoice entered on a project's Invoices tab (header_id NULL) is a row of its
// own, as it always was.
//
// THE FILTER IS THE STATUS, NOTHING IS REMOVED. The four views:
//   waiting   arrived, not paid yet (status pending — or one of the four old
//             values before finance_install_09 — and no paid date)
//   expected  due from somebody but not arrived (status expected): nothing to
//             pay yet, so not in the count, but visible so Jorik knows it is coming
//   paid      paid, newest payment first
//   all       the three together, in that order
// Paying moves an invoice from "waiting" to "paid"; it is never deleted.
//
// ADMIN ONLY, like every invoice: everything goes through finance_db(), which
// answers null for anybody else, and the page itself calls require_admin().

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/fx.php';

/** The views, key => label, in chip order. */
const PAYMENT_VIEWS = [
    'waiting'  => 'Waiting to be paid',
    'expected' => 'Expected',
    'paid'     => 'Paid',
    'all'      => 'All',
];

/** The most paid invoices one page lists: the newest payments. */
const PAYMENTS_PAID_LIMIT = 200;

/** SQL for one view's rows, against a table aliased as given. */
function payments_where(string $view, string $t): string {
    switch ($view) {
        case 'waiting':
            return "{$t}.paid_date IS NULL AND {$t}.status NOT IN ('paid', 'expected')";
        case 'expected':
            return "{$t}.paid_date IS NULL AND {$t}.status = 'expected'";
        case 'paid':
            return "({$t}.paid_date IS NOT NULL OR {$t}.status = 'paid')";
    }
    return '1 = 1';
}

/** Have monthly invoices (finance_install_12) been set up? */
function payments_have_headers(): bool {
    return finance_column_exists('invoices', 'header_id')
        && finance_column_exists('invoice_headers', 'month');
}

/**
 * How many invoices are in each view: ['waiting' => n, 'expected' => n, 'paid' => n].
 * A monthly invoice counts once, however many shows it covers.
 */
function payments_counts(): array {
    $out = [];
    foreach (['waiting', 'expected', 'paid'] as $view) $out[$view] = payments_count($view);
    return $out;
}

/** How many invoices are in one view. */
function payments_count(string $view): int {
    $db = finance_db();
    if (!$db) return 0;
    $headers = payments_have_headers();
    $n = (int)$db->query('SELECT COUNT(*) FROM invoices i WHERE ' . payments_where($view, 'i')
                         . ($headers ? ' AND i.header_id IS NULL' : ''))->fetchColumn();
    if ($headers) {
        $n += (int)$db->query('SELECT COUNT(*) FROM invoice_headers h WHERE '
                              . payments_where($view, 'h'))->fetchColumn();
    }
    return $n;
}

/**
 * THE TOP-BAR COUNT: invoices waiting to be paid. Admins only; 0 for anybody
 * else. Kept in the session for two minutes, so it is not a finance-database
 * connection on every page load, and forgotten whenever an invoice is saved or
 * paid (payments_forget()). NEVER THROWS: any trouble counts as 0, because the
 * top bar must not be able to take a page down.
 */
function payments_waiting_count(): int {
    try {
        if (!can_see_money() || !finance_configured()) return 0;
        $now = time();
        $c = $_SESSION['payments_waiting'] ?? null;
        if (is_array($c) && isset($c['n'], $c['at']) && $now - (int)$c['at'] < 120) return (int)$c['n'];
        $n = payments_count('waiting');
        $_SESSION['payments_waiting'] = ['n' => $n, 'at' => $now];
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Forget the top bar's cached count, after an invoice changed. */
function payments_forget(): void {
    unset($_SESSION['payments_waiting']);
}

/**
 * The rows of one view, each:
 *   kind        'single' (one invoices row) or 'monthly' (an invoice_headers row)
 *   id          the invoices id or the header id
 *   crew_id, name, invoice_ref, month (monthly only)
 *   period_start, period_end, issued_date, due_date, paid_date, status
 *   document_url, notes, needs_review, review_reason
 *   usd         the amount in dollars, null when a currency has no fixed rate
 *   own         [currency => amount] as invoiced, for showing a non-USD contract
 *   lines       [['project_id','project_code','position','amount','currency'], ...]
 *   state       'waiting' | 'expected' | 'paid'
 *
 * Waiting and expected come oldest-due first (what is late is on top); paid
 * comes newest payment first.
 */
function payments_rows(string $view): array {
    $db = finance_db();
    if (!$db) return [];
    if ($view === 'all') {
        return array_merge(payments_rows('waiting'), payments_rows('expected'), payments_rows('paid'));
    }
    if (!isset(PAYMENT_VIEWS[$view])) $view = 'waiting';
    $headers = payments_have_headers();
    $limit = $view === 'paid' ? ' LIMIT ' . PAYMENTS_PAID_LIMIT : '';
    $rows = [];

    // ---- invoices entered on a project's Invoices tab -----------------------
    $hasStep = finance_column_exists('invoices', 'step_id');
    $q = $db->query('SELECT * FROM invoices i WHERE ' . payments_where($view, 'i')
                    . ($headers ? ' AND i.header_id IS NULL' : '')
                    . ' ORDER BY ' . ($view === 'paid' ? 'i.paid_date DESC, i.id DESC' : 'i.id') . $limit);
    foreach ($q as $iv) {
        $cur = (string)($iv['currency'] ?: 'USD');
        $usd = fx_to_usd((float)$iv['amount'], $cur, project_fx_rates((int)$iv['project_id']));
        $rows[] = [
            'kind' => 'single', 'id' => (int)$iv['id'],
            'crew_id' => $iv['crew_id'] !== null ? (int)$iv['crew_id'] : null,
            'name' => trim((string)($iv['crew_name'] ?? '')),
            'invoice_ref' => (string)($iv['invoice_ref'] ?? ''), 'month' => null,
            'period_start' => $iv['period_start'], 'period_end' => $iv['period_end'],
            'issued_date' => $iv['issued_date'], 'due_date' => $iv['due_date'],
            'paid_date' => $iv['paid_date'], 'status' => (string)$iv['status'],
            'document_url' => (string)($iv['document_url'] ?? ''),
            'notes' => (string)($iv['notes'] ?? ''), 'needs_review' => false, 'review_reason' => '',
            'usd' => $usd, 'own' => [$cur => (float)$iv['amount']],
            'lines' => [[
                'project_id' => (int)$iv['project_id'], 'project_code' => (string)($iv['project_code'] ?? ''),
                'position' => $hasStep ? (string)($iv['step_name'] ?? '') : '',
                'amount' => (float)$iv['amount'], 'currency' => $cur,
            ]],
        ];
    }

    // ---- monthly invoices, one row each with their lines --------------------
    if ($headers) {
        $hs = $db->query('SELECT * FROM invoice_headers h WHERE ' . payments_where($view, 'h')
                         . ' ORDER BY ' . ($view === 'paid' ? 'h.paid_date DESC, h.id DESC' : 'h.id') . $limit)
                 ->fetchAll();
        $linesBy = [];
        if ($hs) {
            $ids = implode(',', array_map(fn($h) => (int)$h['id'], $hs));
            foreach ($db->query("SELECT header_id, project_id, project_code, step_name, amount, currency, due_date
                                   FROM invoices WHERE header_id IN ({$ids})
                                  ORDER BY project_code, step_name, id") as $l) {
                $linesBy[(int)$l['header_id']][] = $l;
            }
        }
        foreach ($hs as $h) {
            $hid = (int)$h['id'];
            $lines = []; $own = []; $usd = 0.0; $convertible = true; $due = null;
            foreach ($linesBy[$hid] ?? [] as $l) {
                $cur = (string)($l['currency'] ?: 'USD');
                $own[$cur] = round(($own[$cur] ?? 0) + (float)$l['amount'], 2);
                $u = fx_to_usd((float)$l['amount'], $cur, project_fx_rates((int)$l['project_id']));
                if ($u === null) $convertible = false; else $usd += $u;
                if ($l['due_date'] && ($due === null || $l['due_date'] < $due)) $due = $l['due_date'];
                $lines[] = ['project_id' => (int)$l['project_id'], 'project_code' => (string)($l['project_code'] ?? ''),
                            'position' => (string)($l['step_name'] ?? ''),
                            'amount' => (float)$l['amount'], 'currency' => $cur];
            }
            // No lines yet: the total typed from the document is all there is.
            if (!$lines && $h['total'] !== null) {
                $own = [(string)$h['currency'] => (float)$h['total']];
                $usd = (string)$h['currency'] === 'USD' ? (float)$h['total'] : 0.0;
                $convertible = (string)$h['currency'] === 'USD';
            }
            [$from, $to] = [$h['month'] . '-01', date('Y-m-t', strtotime($h['month'] . '-01'))];
            $rows[] = [
                'kind' => 'monthly', 'id' => $hid,
                'crew_id' => (int)$h['crew_id'], 'name' => trim((string)($h['crew_name'] ?? '')),
                'invoice_ref' => (string)($h['invoice_ref'] ?? ''), 'month' => (string)$h['month'],
                'period_start' => $from, 'period_end' => $to,
                'issued_date' => $h['received_date'], 'due_date' => $due,
                'paid_date' => $h['paid_date'], 'status' => (string)$h['status'],
                'document_url' => (string)($h['document_url'] ?? ''),
                'notes' => (string)($h['notes'] ?? ''),
                'needs_review' => !empty($h['needs_review']), 'review_reason' => (string)($h['review_reason'] ?? ''),
                'usd' => $convertible ? round($usd, 2) : null, 'own' => $own, 'lines' => $lines,
            ];
        }
    }

    foreach ($rows as &$r) $r['state'] = $view;
    unset($r);

    // Waiting / expected: the soonest due first, then the oldest arrival, so
    // what is late is at the top. Paid: the newest payment first.
    usort($rows, function ($a, $b) use ($view) {
        if ($view === 'paid') return strcmp((string)$b['paid_date'], (string)$a['paid_date']);
        $da = $a['due_date'] ?: '9999-12-31'; $dz = $b['due_date'] ?: '9999-12-31';
        if ($da !== $dz) return strcmp($da, $dz);
        return strcmp((string)($a['issued_date'] ?: '9999-12-31'), (string)($b['issued_date'] ?: '9999-12-31'));
    });
    return $rows;
}

/** A Y-m-d, or today. Never a date in the future: a payment is a fact. */
function payments_date($v): string {
    $v = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) return date('Y-m-d');
    return $v > date('Y-m-d') ? date('Y-m-d') : $v;
}

/**
 * Mark one invoice paid on a date. $kind 'monthly' pays the whole monthly
 * invoice and every line of it through pay_invoice_header() (IN4), so there is
 * one way to pay one. 'single' pays one invoice entered on a project page —
 * never a line of a monthly invoice, which would leave the rest unpaid.
 */
function payments_mark_paid(string $kind, int $id, $date, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');
    $date = payments_date($date);
    payments_forget();
    if ($kind === 'monthly') {
        require_once __DIR__ . '/invoice_split.php';
        return pay_invoice_header($id, $date, $who);
    }
    payments_single_or_fail($id);
    $db->prepare("UPDATE invoices SET status = 'paid', paid_date = ?, updated_by = ? WHERE id = ?")
       ->execute([$date, $who, $id]);
    return 'Invoice marked paid on ' . date('j M Y', strtotime($date)) . '.';
}

/**
 * Refuse unless $id is an invoice entered on a project page. A LINE of a
 * monthly invoice is refused: paying one line would leave the rest of that
 * invoice unpaid on its other shows.
 */
function payments_single_or_fail(int $id): void {
    $guard = payments_have_headers() ? ' AND header_id IS NULL' : '';
    $q = finance_db()->prepare("SELECT id FROM invoices WHERE id = ?{$guard}");
    $q->execute([$id]);
    if (!$q->fetch()) {
        throw new RuntimeException('That invoice was not found, or is part of a monthly invoice. Nothing changed.');
    }
}

/**
 * Take an invoice back out of Paid, for a payment marked by mistake: back to
 * waiting, with its paid date cleared. A monthly invoice goes back with every
 * line, in one transaction, the mirror of pay_invoice_header().
 */
function payments_mark_unpaid(string $kind, int $id, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');
    require_once __DIR__ . '/finance.php';
    $status = invoice_default_status();    // 'pending', or 'received' before finance_install_09
    payments_forget();
    if ($kind === 'monthly') {
        $q = payments_have_headers() ? $db->prepare('SELECT id FROM invoice_headers WHERE id = ?') : null;
        if ($q) $q->execute([$id]);
        if (!$q || !$q->fetch()) throw new RuntimeException('That invoice no longer exists.');
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE invoice_headers SET status = ?, paid_date = NULL, updated_at = ?, updated_by = ?
                           WHERE id = ?')
               ->execute([$status, date('Y-m-d H:i:s'), $who, $id]);
            $db->prepare('UPDATE invoices SET status = ?, paid_date = NULL, updated_by = ? WHERE header_id = ?')
               ->execute([$status, $who, $id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return 'Invoice set back to waiting to be paid, with all its lines.';
    }
    payments_single_or_fail($id);
    $db->prepare('UPDATE invoices SET status = ?, paid_date = NULL, updated_by = ? WHERE id = ?')
       ->execute([$status, $who, $id]);
    return 'Invoice set back to waiting to be paid.';
}

/**
 * The shows' names, for the rows' project codes: [project id => name]. From the
 * production database, which is where projects live.
 */
function payments_project_names(PDO $pdo, array $rows): array {
    $ids = [];
    foreach ($rows as $r) foreach ($r['lines'] as $l) $ids[(int)$l['project_id']] = true;
    if (!$ids) return [];
    try {
        $out = [];
        foreach ($pdo->query('SELECT id, name, code FROM projects WHERE id IN ('
                             . implode(',', array_keys($ids)) . ')') as $p) {
            $out[(int)$p['id']] = ['name' => (string)$p['name'], 'code' => (string)$p['code']];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}
