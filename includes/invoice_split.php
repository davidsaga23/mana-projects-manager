<?php
// One freelancer invoice with lines on several projects (Studio board IN4).
//
// Tim and Jorik, 2026-10-28: a freelancer sends ONE invoice per month to
// accounting@mana.tv with a line per project and position ("FCM CH Layout
// 5 days, MSD BG Color 3 days"); Jorik pays it once and Anima splits it across
// the projects.
//
// ⚠ THE LINES ARE ORDINARY `invoices` ROWS. A monthly invoice is an
// `invoice_headers` row (finance_install_12) and its lines are invoices rows on
// their own projects, pointing at it through invoices.header_id. That was
// chosen over a separate lines table because every reader of invoices — the
// project's Invoices tab and totals, actual cost per position, statements,
// Final balance, the hub — then keeps working untouched, and the invoices
// recorded before this (header_id NULL) stay what they were: one-project
// invoices. The other way meant moving every one of those readers over and
// keeping two kinds of invoice in step for ever.
//
// What belongs to the invoice as a whole (its number, when it arrived, when it
// was paid, its status, the PDF link) lives on the header and is COPIED down
// onto every line when the header is saved, so a project page shows the same
// facts. Paying the header pays every line in one transaction.
//
// Admin only, like every other invoice: everything here goes through
// finance_db(), which refuses anybody else.

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/fx.php';
require_once __DIR__ . '/home.php';     // home_show(), home_invoice_lines(), home_is_salaried()

/** Has finance_install_12 been imported? Nothing here saves without it. */
function invoice_split_available(): bool {
    return finance_column_exists('invoices', 'header_id')
        && finance_column_exists('invoices', 'step_id')
        && finance_column_exists('invoice_headers', 'month');
}

/** A 'YYYY-MM' from the request, or this month. Never a future month. */
function invoice_split_month(?string $m): string {
    $m = (string)$m;
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) || $m > date('Y-m')) $m = date('Y-m');
    return $m;
}

/** First and last day of a 'YYYY-MM'. */
function invoice_split_bounds(string $month): array {
    $from = $month . '-01';
    return [$from, date('Y-m-t', strtotime($from))];
}

/** One header by id, or null. */
function invoice_header(int $id): ?array {
    if ($id <= 0 || !invoice_split_available()) return null;
    $q = finance_db()->prepare('SELECT * FROM invoice_headers WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** A person's invoice for a month, or null. The oldest, if there were ever two. */
function invoice_header_for(int $crewId, string $month): ?array {
    if ($crewId <= 0 || !invoice_split_available()) return null;
    $q = finance_db()->prepare('SELECT * FROM invoice_headers WHERE crew_id = ? AND month = ?
                                 ORDER BY id LIMIT 1');
    $q->execute([$crewId, $month]);
    return $q->fetch() ?: null;
}

/** Every header for a month, keyed by crew id. */
function invoice_headers_for_month(string $month): array {
    if (!invoice_split_available()) return [];
    $q = finance_db()->prepare('SELECT * FROM invoice_headers WHERE month = ? ORDER BY id');
    $q->execute([$month]);
    $out = [];
    foreach ($q as $h) if (!isset($out[(int)$h['crew_id']])) $out[(int)$h['crew_id']] = $h;
    return $out;
}

/** The lines of one invoice: its invoices rows, by project then position. */
function invoice_header_lines(int $headerId): array {
    if ($headerId <= 0 || !invoice_split_available()) return [];
    $q = finance_db()->prepare('SELECT * FROM invoices WHERE header_id = ?
                                 ORDER BY project_code, project_id, step_name, id');
    $q->execute([$headerId]);
    return $q->fetchAll();
}

/** Line totals per currency, [currency => amount]. */
function invoice_lines_total(array $lines): array {
    $out = [];
    foreach ($lines as $l) {
        $c = (string)($l['currency'] ?? 'USD');
        $out[$c] = round(($out[$c] ?? 0) + (float)$l['amount'], 2);
    }
    return $out;
}

/**
 * The people with time logged on a show in the month, freelancers first:
 * [crew id => ['name', 'salaried']], by name. A salaried person (David, Tim,
 * Renata, Junior) sends no invoice; they are listed apart so nobody wonders
 * where they went.
 */
function invoice_split_people(PDO $pdo, string $month): array {
    if (!time_logs_available($pdo)) return [];
    [$from, $to] = invoice_split_bounds($month);
    $out = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT c.id, c.name FROM time_logs tl
                              JOIN crew c ON c.id = tl.crew_id
                              JOIN projects p ON p.id = tl.project_id
                             WHERE tl.log_date BETWEEN ? AND ?
                               AND COALESCE(c.is_placeholder, 0) = 0 AND COALESCE(c.is_team, 0) = 0'
                           . studio_exclude_sql($pdo, 'p') . ' ORDER BY c.name');
        $q->execute([$from, $to]);
        foreach ($q as $r) {
            $out[(int)$r['id']] = ['name' => (string)$r['name'],
                                   'salaried' => home_is_salaried($pdo, (int)$r['id'])];
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

/**
 * WHAT THE INVOICE SHOULD SAY, FROM THE LOG: one line per project and position
 * with the days logged that month, the rate and the amount — the very figures
 * the person's own Home shows them to put on it (home_invoice_lines(), PR #118),
 * so what we expect and what they were told can never be two sums.
 *
 * Every show they logged time on in the month counts, wrapped ones included:
 * October's invoice arrives in November, after a show may have wrapped.
 *
 * @return array ['lines' => [...], 'outside' => minutes, 'terms' => ?array]
 */
function invoice_split_prefill(PDO $pdo, int $crewId, string $month): array {
    [$from, $to] = invoice_split_bounds($month);
    $terms = crew_term($crewId);
    $shows = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT p.* FROM time_logs tl JOIN projects p ON p.id = tl.project_id
                             WHERE tl.crew_id = ? AND tl.log_date BETWEEN ? AND ?'
                           . studio_exclude_sql($pdo, 'p') . ' ORDER BY p.name');
        $q->execute([$crewId, $from, $to]);
        foreach ($q->fetchAll() as $p) {
            $shows[(int)$p['id']] = home_show($pdo, $p, $crewId, $from, date('Y-m-d'));
        }
    } catch (PDOException $e) {
        $shows = [];
    }
    $info = home_invoice_lines($pdo, $crewId, $shows, $terms, $from, $to);
    return ['lines' => $info['lines'], 'outside' => $info['outside'], 'terms' => $terms];
}

/**
 * Where a line may go, for this person: their positions on each show (slots and
 * supervision, as on a project's Invoices tab) plus "no particular position".
 * Keyed "projectId:stepId" (stepId 0 = no position), the value the form posts,
 * so a line's project and position are always looked up here and never trusted
 * from the browser.
 *
 * @param int[] $projectIds the shows to offer
 */
function invoice_split_positions(PDO $pdo, int $crewId, array $projectIds): array {
    $out = [];
    if (!$projectIds) return $out;
    $in = implode(',', array_map('intval', array_unique($projectIds)));
    foreach ($pdo->query("SELECT id, name, code FROM projects WHERE id IN ($in) ORDER BY name") as $p) {
        $pid = (int)$p['id'];
        $base = ['project_id' => $pid, 'project_code' => (string)$p['code'], 'project_name' => (string)$p['name']];
        foreach (project_positions($pdo, $pid) as $ps) {
            if ((int)$ps['crew_id'] !== $crewId) continue;
            $out[$pid . ':' . (int)$ps['step_id']] = $base + [
                'step_id' => (int)$ps['step_id'], 'step_name' => (string)$ps['step_name'],
                'label' => $p['name'] . ' · ' . $ps['step_name'],
            ];
        }
        $out[$pid . ':0'] = $base + ['step_id' => null, 'step_name' => null,
                                     'label' => $p['name'] . ' · no particular position'];
    }
    return $out;
}

/** A Y-m-d or null. */
function invoice_split_date($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/** A typed amount or quantity as a float, or null when blank. Throws on nonsense. */
function invoice_split_number($v, string $what): ?float {
    $raw = trim((string)$v);
    if ($raw === '') return null;
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
    if ($clean === '' || !is_numeric($clean)) {
        throw new RuntimeException('"' . $raw . '" is not ' . $what . '.');
    }
    return (float)$clean;
}

/**
 * Save a person's invoice for a month: the header and its lines, in one
 * transaction, so a half-saved invoice never shows on a project page.
 *
 * $h: invoice_ref, total, currency, status, received_date, paid_date,
 *     document_url, notes, needs_review, review_reason
 * $rows: each line_id (0 for new), use (ticked), remove (ticked), pos
 *     ("projectId:stepId"), quantity, unit, rate, amount, currency
 *
 * @return array ['header_id' => int, 'message' => string, 'errors' => string[]]
 */
function save_invoice_split(int $crewId, string $crewName, string $month, array $h, array $rows,
                            array $positions, string $who = ''): array {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can record an invoice.');
    if (!invoice_split_available()) {
        throw new RuntimeException('The finance database needs finance_install_12.sql before a monthly '
                                 . 'invoice can be saved.');
    }
    $db = finance_db();
    $month = invoice_split_month($month);
    [$from, $to] = invoice_split_bounds($month);

    // ---- the header's own fields, checked before anything is written ----
    $status = isset(invoice_statuses()[(string)($h['status'] ?? '')])
        ? (string)$h['status'] : invoice_default_status();
    $paid = invoice_split_date($h['paid_date'] ?? '');
    // The same correction save_invoice() makes: paid means a date, a date means paid.
    if ($status === 'paid' && $paid === null) $paid = date('Y-m-d');
    if ($paid !== null && $status !== 'paid') $status = 'paid';
    $received = invoice_split_date($h['received_date'] ?? '');
    $cur = strtoupper(trim((string)($h['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';
    $total = invoice_split_number($h['total'] ?? '', 'an amount');
    $url = trim((string)($h['document_url'] ?? ''));
    if ($url !== '' && !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('A document link must start with http:// or https://.');
    }
    $ref = trim((string)($h['invoice_ref'] ?? ''));
    $review = !empty($h['needs_review']) ? 1 : 0;
    $reason = trim((string)($h['review_reason'] ?? ''));
    $notes = trim((string)($h['notes'] ?? ''));
    $now = date('Y-m-d H:i:s');

    $existing = invoice_header_for($crewId, $month);
    $hid = $existing ? (int)$existing['id'] : 0;
    $mine = [];
    foreach ($hid ? invoice_header_lines($hid) : [] as $l) $mine[(int)$l['id']] = $l;

    // ---- the lines, checked before anything is written ----
    $errors = []; $writes = [];
    foreach ($rows as $i => $r) {
        $lineId = (int)($r['line_id'] ?? 0);
        if ($lineId > 0 && !isset($mine[$lineId])) {
            $errors[] = sprintf('Line %d is not on this invoice, so it was left alone.', $i + 1);
            continue;
        }
        if ($lineId > 0 && !empty($r['remove'])) { $writes[] = ['delete', $lineId]; continue; }
        if ($lineId === 0 && empty($r['use'])) continue;

        $pos = $positions[(string)($r['pos'] ?? '')] ?? null;
        if (!$pos) {
            $errors[] = sprintf('Line %d has no project, so it was not saved.', $i + 1);
            continue;
        }
        try {
            $qty  = invoice_split_number($r['quantity'] ?? '', 'a number of days');
            $rate = invoice_split_number($r['rate'] ?? '', 'a rate');
            $amt  = invoice_split_number($r['amount'] ?? '', 'an amount');
        } catch (RuntimeException $e) {
            $errors[] = sprintf('Line %d was not saved: %s', $i + 1, $e->getMessage());
            continue;
        }
        // Days × rate when the amount is left blank; a typed amount wins,
        // because it is what is printed on the invoice.
        if ($amt === null && $qty !== null && $rate !== null) $amt = $qty * $rate;
        $amt = $amt !== null ? round($amt, 2) : null;
        if ($amt === null || $amt <= 0) {
            $errors[] = sprintf('Line %d (%s) has no amount, so it was not saved.', $i + 1, $pos['label']);
            continue;
        }
        $lc = strtoupper(trim((string)($r['currency'] ?? $cur)));
        if (!preg_match('/^[A-Z]{3}$/', $lc)) $lc = $cur;
        $unit = ($qty !== null && in_array((string)($r['unit'] ?? ''), ['day', 'hour'], true))
            ? (string)$r['unit'] : null;
        $writes[] = [$lineId > 0 ? 'update' : 'insert', $lineId, $pos, $qty, $unit, $rate, $amt, $lc];
    }

    $db->beginTransaction();
    try {
        if ($hid) {
            $db->prepare('UPDATE invoice_headers SET crew_name = ?, invoice_ref = ?, total = ?, currency = ?,
                                 status = ?, received_date = ?, paid_date = ?, document_url = ?,
                                 needs_review = ?, review_reason = ?, notes = ?, updated_at = ?, updated_by = ?
                           WHERE id = ?')
               ->execute([$crewName, $ref ?: null, $total, $cur, $status, $received, $paid, $url ?: null,
                          $review, $reason ?: null, $notes ?: null, $now, $who, $hid]);
        } else {
            $db->prepare('INSERT INTO invoice_headers
                            (crew_id, crew_name, month, invoice_ref, total, currency, status, received_date,
                             paid_date, document_url, source, needs_review, review_reason, notes,
                             created_at, updated_at, updated_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'manual\', ?, ?, ?, ?, ?, ?)')
               ->execute([$crewId, $crewName, $month, $ref ?: null, $total, $cur, $status, $received, $paid,
                          $url ?: null, $review, $reason ?: null, $notes ?: null, $now, $now, $who]);
            $hid = (int)$db->lastInsertId();
        }

        $ins = $db->prepare('INSERT INTO invoices
                               (header_id, project_id, project_code, crew_id, crew_name, step_id, step_name,
                                amount, quantity, quantity_unit, unit_rate, currency, status, updated_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $upd = $db->prepare('UPDATE invoices SET project_id = ?, project_code = ?, step_id = ?, step_name = ?,
                                    amount = ?, quantity = ?, quantity_unit = ?, unit_rate = ?, currency = ?
                              WHERE id = ? AND header_id = ?');
        $del = $db->prepare('DELETE FROM invoices WHERE id = ? AND header_id = ?');
        $added = 0; $changed = 0; $removed = 0;
        foreach ($writes as $w) {
            if ($w[0] === 'delete') { $del->execute([$w[1], $hid]); $removed++; continue; }
            [$kind, $lineId, $pos, $qty, $unit, $rate, $amt, $lc] = $w;
            if ($kind === 'insert') {
                $ins->execute([$hid, $pos['project_id'], $pos['project_code'], $crewId, $crewName,
                               $pos['step_id'], $pos['step_name'], $amt, $qty, $unit, $rate, $lc, $status, $who]);
                $added++;
            } else {
                $upd->execute([$pos['project_id'], $pos['project_code'], $pos['step_id'], $pos['step_name'],
                               $amt, $qty, $unit, $rate, $lc, $lineId, $hid]);
                $changed++;
            }
        }

        // The header's facts, copied onto every line, so a project's Invoices
        // tab, its statements and Final balance read the same thing this page
        // does. The invoice's date on a line is the day it was received: the
        // header keeps one date for "arrived", and a line's "issued" is what the
        // project list sorts and shows by.
        $db->prepare('UPDATE invoices SET crew_id = ?, crew_name = ?, invoice_ref = ?, period_start = ?,
                             period_end = ?, issued_date = ?, paid_date = ?, status = ?, document_url = ?,
                             updated_by = ?
                       WHERE header_id = ?')
           ->execute([$crewId, $crewName, $ref ?: null, $from, $to, $received, $paid, $status,
                      $url ?: null, $who, $hid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $bits = [];
    if ($added)   $bits[] = $added . ' line' . ($added === 1 ? '' : 's') . ' added';
    if ($changed) $bits[] = $changed . ' updated';
    if ($removed) $bits[] = $removed . ' removed';
    return ['header_id' => $hid, 'errors' => $errors,
            'message' => ($existing ? 'Invoice saved' : 'Invoice recorded')
                       . ($bits ? ': ' . implode(', ', $bits) : '') . '.'];
}

/**
 * PAY THE WHOLE INVOICE: the header and every line paid on the same date, in
 * one transaction. Jorik pays an invoice once; a line left unpaid on some
 * project would be a debt that does not exist.
 */
function pay_invoice_header(int $headerId, ?string $date, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    if (!invoice_header($headerId)) throw new RuntimeException('That invoice no longer exists.');
    $date = invoice_split_date($date) ?? date('Y-m-d');
    $db = finance_db();
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE invoice_headers SET status = 'paid', paid_date = ?, updated_at = ?, updated_by = ?
                       WHERE id = ?")->execute([$date, date('Y-m-d H:i:s'), $who, $headerId]);
        $q = $db->prepare("UPDATE invoices SET status = 'paid', paid_date = ?, updated_by = ? WHERE header_id = ?");
        $q->execute([$date, $who, $headerId]);
        $n = $q->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return 'Invoice marked paid on ' . date('j M Y', strtotime($date)) . ($n ? ', with all its lines.' : '.');
}

/** Remove an invoice and every line of it, from every project. */
function delete_invoice_header(int $headerId): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can do that.');
    $db = finance_db();
    if (!$db || !invoice_split_available()) throw new RuntimeException('The finance database is not ready.');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM invoices WHERE header_id = ?')->execute([$headerId]);
        $db->prepare('DELETE FROM invoice_headers WHERE id = ?')->execute([$headerId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return 'Invoice removed, with its lines on every project.';
}
