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
 * @param string   $q     free text over project name, code, step name and reason
 * @param int[]     $projs the shows to include; EMPTY MEANS ALL of them, which
 *                         is what an untouched set of checkboxes should mean.
 *                         Ticking none and seeing nothing would be a screen that
 *                         looks broken on arrival.
 * @param string[]  $types project types (2D, 3D) to include; empty means all.
 * @param string[]  $steps step IDENTITIES to include, each "name|applies_to|asset_type";
 *                         empty means all. The identity triple, not the name:
 *                         "Concept, Character" and "Concept, Environment" are
 *                         different jobs, which is the whole reason the baseline
 *                         index is keyed on all three.
 * @return array<int, array> capture batches, each carrying its own 'rows'
 */
function quota_history(PDO $pdo, string $q = '', array $projs = [],
                       array $types = [], array $steps = []): array {
    // project_type comes from the JOIN rather than from the baseline row: the
    // baseline copies the STEP's identity because steps get renamed and deleted,
    // but a baseline cannot outlive its project (ON DELETE CASCADE), so the
    // project's own columns are always there to be read.
    $sql = 'SELECT b.*, p.code AS project_code, p.name AS project_name,
                   p.status AS project_status, p.type AS project_type
              FROM step_estimate_baseline b
              JOIN projects p ON p.id = b.project_id
             WHERE 1=1';
    $args = [];

    $projs = array_values(array_unique(array_filter(array_map('intval', $projs), fn($i) => $i > 0)));
    if ($projs) {
        $sql .= ' AND b.project_id IN (' . implode(',', array_fill(0, count($projs), '?')) . ')';
        foreach ($projs as $i) $args[] = $i;
    }

    $types = array_values(array_unique(array_filter(array_map('strval', $types), fn($t) => $t !== '')));
    if ($types) {
        $sql .= ' AND p.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        foreach ($types as $t) $args[] = $t;
    }

    // Three equality tests OR'd together per step rather than one CONCAT over
    // the three columns: a concatenation cannot use idx_baseline_identity, and
    // that index exists for precisely this lookup.
    $steps = array_values(array_unique(array_filter(array_map('strval', $steps), fn($t) => $t !== '')));
    if ($steps) {
        $ors = [];
        foreach ($steps as $key) {
            $parts = explode('|', $key, 3);
            if (count($parts) !== 3) continue;
            $ors[] = '(b.step_name = ? AND b.applies_to = ? AND b.applies_to_asset_type = ?)';
            array_push($args, $parts[0], $parts[1], $parts[2]);
        }
        // Every key malformed means the caller asked for nothing that exists,
        // and that is an empty result, not an unfiltered one.
        $sql .= $ors ? ' AND (' . implode(' OR ', $ors) . ')' : ' AND 1=0';
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
                'project_type'   => (string)($r['project_type'] ?? ''),
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
 * THE TWO QUOTAS, recovered from a frozen baseline row.
 *
 * David, 2026-09-02, asked for both on this screen. They come from opposite
 * directions and the gap between them is the whole finding:
 *
 *   IDEAL    deliveries per person per day at the days-per-item this plan was
 *            priced at. days_per_unit in a baseline is what capture_baseline()
 *            calls effective_days_per_unit(), which is the studio ideal where
 *            one exists and the project's own estimate where it does not, so
 *            this IS the rate the expense projection costs the work with.
 *   MINIMUM  what the dates demanded of one person per day: the work in the
 *            step spread over its working days, divided by the people planned.
 *
 * A minimum above the ideal means the plan asked for a pace the studio does not
 * believe in. The same pair is drawn live on Finance management, out of
 * step_quota(); this is the frozen version of it.
 *
 * ⚠ COUNTED FROM THE STEP'S START, NOT FROM THE CAPTURE DATE. step_quota()
 * counts from today because it is asking "can we still make it"; a record is
 * asking "what did this plan demand of the span it drew", and an answer that
 * moved with the day somebody pressed the button is exactly the volatility a
 * baseline exists to remove. Same reasoning as the planning window on Dates &
 * headcount, which stopped counting from today for this reason on 2026-09-01.
 *
 * Retakes are deliberately NOT applied, matching step_quota(): they scale
 * days-per-item, not the amount of work.
 *
 * Any of these can be null, and null means "cannot be known from what was
 * recorded", never zero.
 */
function baseline_quota(array $row): array {
    $out = ['ideal' => null, 'minimum' => null, 'team_per_day' => null, 'working_days' => null];

    $days = (float)($row['days_per_unit'] ?? 0);
    if ($days > 0) $out['ideal'] = 1 / $days;

    $units = (float)($row['work_units'] ?? 0);
    $start = $row['start_date'] ?? null;
    $end   = $row['deadline'] ?? null;
    if ($units <= 0 || !$start || !$end || $end < $start) return $out;

    $wd = working_days_between((string)$start, (string)$end);
    if ($wd <= 0) return $out;
    $out['working_days'] = $wd;
    $out['team_per_day'] = $units / $wd;

    $head = $row['planned_headcount'] !== null ? (float)$row['planned_headcount'] : null;
    if ($head !== null && $head > 0) $out['minimum'] = $out['team_per_day'] / $head;
    return $out;
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
            $out[$label][] = $r + [
                'project_code' => $b['project_code'],
                'project_id'   => $b['project_id'],
                'project_name' => $b['project_name'],
                'captured_at'  => $b['captured_at'],
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
/**
 * Every step identity a baseline has ever been written for, with a count.
 *
 * Read from the BASELINE table, not from pipeline_steps: this filter offers what
 * there are records of, so a step that was deleted from a live project is still
 * offered as long as its history survives, and a step nobody has ever baselined
 * is not offered at all. Offering a filter that can only ever return nothing is
 * worse than not offering it.
 */
function history_steps(PDO $pdo): array {
    if (!history_quotas_available($pdo)) return [];
    return $pdo->query(
        'SELECT step_name, applies_to, applies_to_asset_type, COUNT(*) AS n
           FROM step_estimate_baseline
          GROUP BY step_name, applies_to, applies_to_asset_type
          ORDER BY step_name, applies_to, applies_to_asset_type'
    )->fetchAll();
}

/** The identity key a step filter checkbox carries. One spelling, two readers. */
function step_identity_key(array $row): string {
    return ($row['step_name'] ?? '') . '|' . ($row['applies_to'] ?? '')
         . '|' . ($row['applies_to_asset_type'] ?? '');
}

function history_projects(PDO $pdo): array {
    return $pdo->query(
        'SELECT id, code, name, status, type FROM projects
          ORDER BY COALESCE(start_date, created_at) DESC, id DESC'
    )->fetchAll();
}
