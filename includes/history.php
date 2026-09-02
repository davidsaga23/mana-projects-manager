<?php
// The record of what we used to plan, and what we used to pay.
//
// David, 2026-09-01: "I'll need from time to time, to check the records and see
// what we used to charge certain slots or quotas." Both answers already existed
// in the database and neither had a screen:
//
//   quotas   step_estimate_baseline, written by capture_baseline() every time
//            somebody re-baselines a project. Append-only, and migration 013
//            already carries idx_baseline_identity for exactly this lookup —
//            "the cross-project lookup this table exists to serve".
//   fees     crew_terms and invoices in the finance database, joined to the
//            lanes in the production one.
//
// ⚠ THE TWO HALVES ARE NOT THE SAME KIND OF HISTORY, and the screens say so
// rather than letting a reader assume. A baseline is a SNAPSHOT: it copied the
// step's name, scale, dates and retake allowance at the moment it was taken, so
// it stays true however much the project changes afterwards. A rate is not.
// crew_terms holds ONE row per person, with no project and no date, so a real
// artist's rate is what they cost TODAY — not what they cost on a show that
// wrapped a year ago. Only two things on the money side are genuinely historic:
// a SLOT's rate, because a slot belongs to one project and dies with it, and an
// INVOICE, because it records a payment that actually happened on a date.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crew.php';
require_once __DIR__ . '/money.php';

/**
 * True once migration 013 has been applied and there is a baseline table.
 *
 * column_exists() rather than a table probe of its own: it already answers
 * false for a table that is not there, and it caches a whole table's columns in
 * one round trip, so this costs nothing the page was not paying anyway.
 */
function history_quotas_available(PDO $pdo): bool {
    return column_exists($pdo, 'step_estimate_baseline', 'days_per_unit');
}

/**
 * Every baseline ever captured, newest first, grouped into the batches they
 * were written in.
 *
 * capture_baseline() writes one row per step inside a transaction, so a batch is
 * (project, reason, the minute it landed). Grouped on the MINUTE rather than the
 * exact timestamp because a project with enough steps can cross a second
 * boundary mid-loop, which would split one capture into two on screen.
 *
 * @param string $q    free text over project name, code, step name and reason
 * @param int    $proj one project, or 0 for all of them
 * @return array<int, array> capture batches, each carrying its own 'rows'
 */
function quota_history(PDO $pdo, string $q = '', int $proj = 0): array {
    $sql = 'SELECT b.*, p.code AS project_code, p.name AS project_name, p.status AS project_status
              FROM step_estimate_baseline b
              JOIN projects p ON p.id = b.project_id
             WHERE 1=1';
    $args = [];
    if ($proj > 0) {
        $sql .= ' AND b.project_id = ?';
        $args[] = $proj;
    }
    if ($q !== '') {
        $sql .= ' AND (b.step_name LIKE ? OR p.name LIKE ? OR p.code LIKE ? OR b.reason LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    // Newest capture first, but the steps INSIDE one capture in the order they
    // were written, which is pipeline order — the order somebody reading a plan
    // expects to meet them in.
    $sql .= ' ORDER BY b.captured_at DESC, b.project_id, b.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    $batches = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = $r['project_id'] . '|' . $r['reason'] . '|' . substr((string)$r['captured_at'], 0, 16);
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'project_id'     => (int)$r['project_id'],
                'project_code'   => (string)$r['project_code'],
                'project_name'   => (string)$r['project_name'],
                'project_status' => (string)$r['project_status'],
                'captured_at'    => (string)$r['captured_at'],
                'reason'         => (string)$r['reason'],
                'rows'           => [],
            ];
        }
        $batches[$key]['rows'][] = $r;
    }
    return array_values($batches);
}

/**
 * How many days per item a step has been baselined at across every project.
 *
 * The answer to "what do we normally allow for this?", which is the question a
 * per-project list cannot answer on its own. Keyed on the same identity triple
 * the baseline index uses — "Concept · Character" and "Concept · Environment"
 * are different jobs, and averaging them together would destroy the signal.
 */
function quota_history_by_step(array $batches): array {
    $out = [];
    foreach ($batches as $b) {
        foreach ($b['rows'] as $r) {
            $label = $r['step_name'] . ' (' . step_scope_tag($r) . ')';
            $out[$label][] = [
                'project_code' => $b['project_code'],
                'project_id'   => $b['project_id'],
                'captured_at'  => $b['captured_at'],
                'days'         => (float)$r['days_per_unit'],
                'units'        => (float)$r['work_units'],
                'label'        => (string)$r['work_label'],
                'headcount'    => $r['planned_headcount'] !== null ? (float)$r['planned_headcount'] : null,
                'retake_pct'   => (float)$r['retake_pct'],
                'estimated'    => (int)$r['units_estimated'] === 1,
            ];
        }
    }
    ksort($out);
    return $out;
}

/**
 * What each position on each show has cost, grouped by department and step.
 *
 * CROSS-DATABASE, and therefore assembled in PHP rather than in SQL. The lanes,
 * the steps and the projects are production; the rates and the invoices are
 * finance, reached only through includes/money.php. There is no foreign key
 * between them — crew.id and projects.id ARE the join, which is exactly why
 * neither id is ever reused.
 *
 * One row per LANE, because a lane is the thing that gets paid: a person on two
 * steps is two positions at two rates, the same shape the expense projection
 * draws.
 *
 * @return array department => step label => rows
 */
function fee_history(PDO $pdo, string $q = '', int $proj = 0, bool $slotsOnly = false): array {
    if (!can_see_money()) return [];

    $sql = 'SELECT cl.project_id, cl.step_id, cl.crew_id, cl.lane_no,
                   c.name AS crew_name, c.is_placeholder, c.active,
                   ps.name AS step_name, ps.applies_to, ps.applies_to_asset_type,
                   ps.step_order,
                   p.code AS project_code, p.name AS project_name,
                   p.status AS project_status, p.start_date, p.delivery_date
              FROM crew_lanes cl
              JOIN crew c            ON c.id  = cl.crew_id
              JOIN pipeline_steps ps ON ps.id = cl.step_id
              JOIN projects p        ON p.id  = cl.project_id
             WHERE 1=1';
    $args = [];
    if ($proj > 0)  {
        $sql .= ' AND cl.project_id = ?';
        $args[] = $proj;
    }
    if ($slotsOnly) {
        $sql .= ' AND c.is_placeholder = 1';
    }
    if ($q !== '') {
        $sql .= ' AND (ps.name LIKE ? OR c.name LIKE ? OR p.name LIKE ? OR p.code LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY ps.step_order, ps.id, p.start_date DESC, cl.lane_no';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $lanes = $stmt->fetchAll();
    if (!$lanes) return [];

    // The department lives on pipeline_steps, but only once migration 015 has
    // run. Probed rather than assumed, like every other optional column here —
    // naming a column that is not there is a fatal that takes the page down.
    $depts = [];
    if (column_exists($pdo, 'pipeline_steps', 'department')) {
        foreach ($pdo->query('SELECT id, department FROM pipeline_steps')->fetchAll() as $d) {
            $depts[(int)$d['id']] = trim((string)$d['department']);
        }
    }

    // The money, fetched once for every crew id on the board rather than per row.
    $crewIds = [];
    foreach ($lanes as $l) $crewIds[] = (int)$l['crew_id'];
    $terms = crew_terms(array_values(array_unique($crewIds)));

    // What was ACTUALLY BILLED against each position.
    $billed = fee_history_invoiced();

    $out = [];
    foreach ($lanes as $l) {
        $cid  = (int)$l['crew_id'];
        $t    = $terms[$cid] ?? null;
        $rate = ($t && $t['day_rate'] !== null) ? (float)$t['day_rate'] : null;

        $dept = $depts[(int)$l['step_id']] ?? '';
        if ($dept === '') $dept = 'Unassigned';
        $label = $l['step_name'] . ' (' . step_scope_tag($l) . ')';

        $key = (int)$l['project_id'] . ':' . (int)$l['step_id'] . ':' . $cid;
        $out[$dept][$label][] = [
            'project_id'     => (int)$l['project_id'],
            'project_code'   => (string)$l['project_code'],
            'project_name'   => (string)$l['project_name'],
            'project_status' => (string)$l['project_status'],
            'started'        => $l['start_date'],
            'delivered'      => $l['delivery_date'],
            'crew_id'        => $cid,
            'crew_name'      => (string)$l['crew_name'],
            'is_slot'        => (int)$l['is_placeholder'] === 1,
            'rate'           => $rate,
            'rate_unit'      => $t['rate_unit'] ?? 'day',
            'currency'       => $t['rate_currency'] ?? 'USD',
            'invoiced'       => $billed[$key]['amount'] ?? null,
            'invoice_count'  => $billed[$key]['count'] ?? 0,
            'last_invoice'   => $billed[$key]['last'] ?? null,
        ];
    }

    // The phases in the order a show runs through them, anything unfiled last —
    // the SAME order expense_projection() bands its rows by, so a department
    // reads in one place where it reads in the other. Alphabetical would put
    // Post-production above Production, which is backwards.
    $order = ['Above the line' => 1, 'Pre-production' => 2, 'Production' => 3, 'Post-production' => 4];
    uksort($out, function ($a, $b) use ($order) {
        $oa = $order[$a] ?? 9;
        $ob = $order[$b] ?? 9;
        return $oa !== $ob ? $oa <=> $ob : strcasecmp($a, $b);
    });
    return $out;
}

/**
 * Everything invoiced, totalled per position, keyed "project:step:crew".
 *
 * Guarded on invoices.step_id, which finance_install_02.sql adds — that exact
 * column's absence took the Finance hub down once already.
 */
function fee_history_invoiced(): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db) return [];
    if (!finance_column_exists('invoices', 'step_id')) return [];

    $rows = $db->query(
        'SELECT project_id, step_id, crew_id,
                SUM(amount) AS amount, COUNT(*) AS n, MAX(issued_date) AS last_issued
           FROM invoices
          WHERE crew_id IS NOT NULL AND step_id IS NOT NULL
          GROUP BY project_id, step_id, crew_id'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['project_id'] . ':' . (int)$r['step_id'] . ':' . (int)$r['crew_id']] = [
            'amount' => (float)$r['amount'],
            'count'  => (int)$r['n'],
            'last'   => $r['last_issued'],
        ];
    }
    return $out;
}

/**
 * The spread of rates across a set of positions: low, typical, high.
 *
 * THE MEDIAN, not the mean. One flat-fee supervisor among eight animators drags
 * an average to a number no real position ever sat at. Somebody asking "what do
 * we normally pay for this?" wants the typical row, and the median is the only
 * summary that always names one.
 *
 * A flat fee is left out entirely rather than compared against day rates, and an
 * unpriced lane is left out rather than counted as zero — the same rule the
 * expense projection applies, for the same reason: absent is not free.
 */
function fee_spread(array $rows): array {
    $vals = [];
    foreach ($rows as $r) {
        if ($r['rate'] !== null && $r['rate_unit'] !== 'flat') $vals[] = (float)$r['rate'];
    }
    if (!$vals) return ['n' => 0, 'low' => null, 'high' => null, 'mid' => null];
    sort($vals);
    $n = count($vals);
    $mid = $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
    return ['n' => $n, 'low' => $vals[0], 'high' => $vals[$n - 1], 'mid' => round($mid, 2)];
}

/**
 * The projects that actually appear in a history, for the filter dropdown.
 *
 * Built from what is on the screen rather than from every project that exists,
 * so the picker can never offer a show that would come back empty.
 */
function history_projects(PDO $pdo): array {
    return $pdo->query(
        'SELECT id, code, name, status FROM projects ORDER BY COALESCE(start_date, created_at) DESC, id DESC'
    )->fetchAll();
}
