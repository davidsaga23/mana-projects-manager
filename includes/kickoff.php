<?php
// The kick-off sign-off, and the picture of the project it freezes.
//
// David, 2026-10-16: going from setup to active is an important change. It
// warns whoever makes it, emails the people who sign off (Tim and Jorik), and
// only turns the project active once they have all approved. What they approved
// is kept, so that at the postmortem "how it started" and "how it ended" can be
// put side by side.
//
// THE ONE IDEA THAT KEEPS THIS SMALL: the frozen picture and the live one are
// built by the SAME function, kickoff_snapshot(). The postmortem screen runs it
// again today and compares the two, so "at kick-off" and "now" can never be
// measured two different ways.
//
// What it is NOT: a copy of tables. A copy would let a postmortem re-open the
// show as it was, but nobody asked for that, and it would break the first time a
// column is added. The snapshot holds the figures and lists a person reads.
// The per-step estimate baseline (includes/baseline.php) is still written at
// approval, so Historic quotas keeps working exactly as before.
//
// ⚠ THE MONEY GOES TO THE FINANCE DATABASE (finance_install_08.sql), never into
// project_kickoffs. Same reason rates live there: the production database's user
// cannot read that one, so an ordinary bug on an ordinary screen cannot leak it.

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/standards.php';
require_once __DIR__ . '/crew.php';
require_once __DIR__ . '/baseline.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/money.php';

/** Has migration 049 run? */
function kickoff_ready(PDO $pdo): bool {
    return column_exists($pdo, 'project_kickoffs', 'snapshot')
        && column_exists($pdo, 'users', 'signs_off_kickoff');
}

/**
 * Is the finance side of the snapshot possible for the person asking?
 *
 * Returns null when it is, or the sentence saying why not. "Finance is not set
 * up on this server" is NOT a reason to refuse — there is simply no money to
 * freeze — so that case returns null too, and the snapshot records the absence.
 */
function kickoff_finance_problem(): ?string {
    if (!finance_configured()) return null;
    if (!can_see_money()) {
        return 'Only an administrator can send a project for sign-off, because the snapshot '
             . 'includes its finance management and only administrators can read that.';
    }
    $db = finance_db();
    if (!$db) {
        return 'The finance database is configured but would not open, so the money side of the '
             . 'kick-off could not be stored. Nothing was sent.';
    }
    try {
        $db->query('SELECT 1 FROM kickoff_finance_snapshots LIMIT 0');
    } catch (PDOException $e) {
        return 'The finance database needs finance_install_08.sql imported (by hand, in '
             . 'phpMyAdmin) before a kick-off can store its money side. Nothing was sent.';
    }
    return null;
}

/**
 * The people who sign off a kick-off, with the address each is emailed at.
 *
 * Their own email on Users first; the linked crew member's as a fallback, since
 * many logins are already joined to a person who has one.
 */
function kickoff_signers(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT u.id, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS name,
                u.role, NULLIF(TRIM(COALESCE(u.email, '')), '') AS own_email,
                NULLIF(TRIM(COALESCE(c.email, '')), '') AS crew_email
           FROM users u
           LEFT JOIN crew c ON c.id = u.crew_id
          WHERE u.signs_off_kickoff = 1 AND u.active = 1
          ORDER BY name"
    )->fetchAll();
    foreach ($rows as &$r) $r['email'] = $r['own_email'] ?? $r['crew_email'];
    return $rows;
}

/**
 * Everybody who COULD sign off: active admins and editors (approving is a save,
 * and an artist's saves are refused). `default` marks the ones ticked under
 * Who signs off, which the pop-up pre-ticks.
 */
function kickoff_candidates(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.role,
                u.signs_off_kickoff AS is_default,
                COALESCE(NULLIF(TRIM(COALESCE(u.email, '')), ''), NULLIF(TRIM(COALESCE(c.email, '')), '')) AS email
           FROM users u
           LEFT JOIN crew c ON c.id = u.crew_id
          WHERE u.active = 1 AND u.role IN ('admin', 'editor')
          ORDER BY u.signs_off_kickoff DESC, name"
    )->fetchAll();
    return $rows;
}

/**
 * Should changing a project to this status open the kick-off sign-off, instead
 * of just saving the new status?
 *
 * David, 2026-10-19: the sign-off fires on the move INTO active. True only the
 * FIRST time a project is activated — once it has a signed-off kick-off, going
 * active again (for example back from On hold) is a plain status change, so
 * "how it started" stays one record. Only the pre-launch statuses count:
 * reactivating a wrapped, postmortem or archived show is not a fresh kick-off.
 * Before migration 049 this is always false, so the old one-click change stands.
 */
function kickoff_should_prompt(PDO $pdo, array $project, string $newStatus): bool {
    if ($newStatus !== 'active') return false;
    $was = (string)($project['status'] ?? '');
    if (!in_array($was, ['setup', 'on_hold'], true)) return false;
    if (!kickoff_ready($pdo)) return false;
    return kickoff_approved($pdo, (int)$project['id']) === null;
}

/** The latest sign-off request for a project that has not been withdrawn. */
function kickoff_current(PDO $pdo, int $projectId): ?array {
    $q = $pdo->prepare(
        "SELECT * FROM project_kickoffs WHERE project_id = ? AND state <> 'withdrawn'
          ORDER BY id DESC LIMIT 1"
    );
    $q->execute([$projectId]);
    return $q->fetch() ?: null;
}

/** The approved kick-off, which is the one the postmortem compares against. */
function kickoff_approved(PDO $pdo, int $projectId): ?array {
    $q = $pdo->prepare(
        "SELECT * FROM project_kickoffs WHERE project_id = ? AND state = 'approved'
          ORDER BY id DESC LIMIT 1"
    );
    $q->execute([$projectId]);
    return $q->fetch() ?: null;
}

/** project id => 'pending' | 'changes_requested' for every open request. */
function kickoff_open_states(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query(
            "SELECT k.project_id, k.state FROM project_kickoffs k
              WHERE k.id IN (SELECT MAX(id) FROM project_kickoffs
                              WHERE state <> 'withdrawn' GROUP BY project_id)"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    foreach ($rows as $r) $out[(int)$r['project_id']] = $r['state'];
    return $out;
}

/** project id => true for every project that has a signed-off (approved) kick-off. */
function kickoff_approved_ids(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT DISTINCT project_id FROM project_kickoffs WHERE state = 'approved'")
                    ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];   // migration 049 pending
    }
    foreach ($rows as $pid) $out[(int)$pid] = true;
    return $out;
}

function kickoff_signoffs(PDO $pdo, int $kickoffId): array {
    $q = $pdo->prepare('SELECT * FROM project_kickoff_signoffs WHERE kickoff_id = ? ORDER BY signer_name');
    $q->execute([$kickoffId]);
    return $q->fetchAll();
}

/** Absolute link to a project's kick-off page, for emails. */
function kickoff_url(int $projectId): string {
    return site_url("kickoff.php?project={$projectId}");
}

// ---------------------------------------------------------------------------
// THE PICTURE
//
// Every section has one shape, so one renderer draws all of them and one
// comparer compares all of them:
//
//   figures  label => [value, kind]          the headline numbers and dates
//   texts    label => string                  long prose (the brief)
//   lists    title => ['cols' => [key => [label, kind]], 'rows' => [key => [col => value]]]
//
// kind is 'num', 'money', 'date' or 'text'; it decides how a value is written
// and how a change is described ("+3", "+12 days", "changed").
// ---------------------------------------------------------------------------

/**
 * The order the sections are shown in, as David listed them, except Finance,
 * which he moved to the top (2026-11-11: "let's move this section to the top
 * of the page"). Administrators only see it, so for everyone else Brief still
 * opens the page.
 */
const KICKOFF_SECTIONS = [
    'finance'  => 'Finance management',
    'brief'    => 'Brief',
    'staffing' => 'Staffing',
    'schedule' => 'Schedule',
    'timeline' => 'Timeline',
    'shots'    => 'Shots',
    'setup'    => 'Setup',
];

/** A list row key that stays unique when two rows would read the same. */
function kickoff_unique_key(array $rows, string $key): string {
    if (!isset($rows[$key])) return $key;
    $n = 2;
    while (isset($rows[$key . ' (' . $n . ')'])) $n++;
    return $key . ' (' . $n . ')';
}

/**
 * Everything but the money, as it stands right now.
 */
function kickoff_snapshot(PDO $pdo, array $project): array {
    $pid   = (int)$project['id'];
    $steps = pipeline_steps($pdo, $pid);
    $label = [];
    foreach ($steps as $s) $label[(int)$s['id']] = step_display_name($s, $steps);

    $out = ['taken_at' => date('Y-m-d H:i:s'), 'sections' => []];

    // ---- Brief ------------------------------------------------------------
    $brief = null; $files = [];
    if (column_exists($pdo, 'project_brief', 'project_id')) {
        $q = $pdo->prepare('SELECT * FROM project_brief WHERE project_id = ?');
        $q->execute([$pid]);
        $brief = $q->fetch() ?: null;
        $q = $pdo->prepare('SELECT original_name FROM project_attachments WHERE project_id = ? ORDER BY created_at, id');
        $q->execute([$pid]);
        $files = $q->fetchAll(PDO::FETCH_COLUMN);
    }
    $fileRows = [];
    foreach ($files as $f) $fileRows[kickoff_unique_key($fileRows, (string)$f)] = [];
    $out['sections']['brief'] = [
        'figures' => [
            'Client'         => [$brief['client'] ?? '', 'text'],
            'Client contact' => [$brief['client_contact'] ?? '', 'text'],
            'Attachments'    => [count($files), 'num'],
        ],
        'texts' => [
            'What was asked for' => (string)($brief['summary'] ?? ''),
            'Deliverables'       => (string)($brief['deliverables'] ?? ''),
            'References'         => (string)($brief['reference_links'] ?? ''),
            'Notes'              => (string)($brief['notes'] ?? ''),
        ],
        'lists' => [
            'Attachments' => ['cols' => [], 'rows' => $fileRows],
        ],
    ];

    // ---- Staffing ---------------------------------------------------------
    $lanes = project_lanes($pdo, $pid);
    $posRows = []; $filled = 0;
    foreach ($lanes as $l) {
        $isSlot = (int)($l['is_placeholder'] ?? 0) === 1;
        if (!$isSlot) $filled++;
        $key = ($label[(int)$l['step_id']] ?? $l['step_name']) . ' #' . (int)$l['lane_no'];
        $posRows[kickoff_unique_key($posRows, $key)] = [
            'department' => trim((string)($l['department'] ?? '')) ?: 'Unassigned',
            'person'     => $isSlot ? 'unfilled' : (string)$l['name'],
        ];
    }
    $out['sections']['staffing'] = [
        'figures' => [
            'Positions' => [count($lanes), 'num'],
            'Filled'    => [$filled, 'num'],
            'Unfilled'  => [count($lanes) - $filled, 'num'],
        ],
        'texts' => [],
        'lists' => [
            'Positions' => [
                'cols' => ['department' => ['Department', 'text'], 'person' => ['Person', 'text']],
                'rows' => $posRows,
            ],
        ],
    ];

    // ---- Schedule (the detailed one, task by task) -----------------------
    $q = $pdo->prepare(
        'SELECT step_id, COUNT(*) AS n, MIN(start_date) AS first_start, MAX(end_date) AS last_end,
                SUM(crew_id IS NULL) AS nobody
           FROM schedule WHERE project_id = ? GROUP BY step_id'
    );
    $q->execute([$pid]);
    $byStep = [];
    foreach ($q as $r) $byStep[(int)$r['step_id']] = $r;
    $schedRows = []; $tasks = 0; $nobody = 0; $first = null; $last = null;
    foreach ($steps as $s) {
        $r = $byStep[(int)$s['id']] ?? null;
        if (!$r) continue;
        $tasks  += (int)$r['n'];
        $nobody += (int)$r['nobody'];
        if ($r['first_start'] && ($first === null || $r['first_start'] < $first)) $first = $r['first_start'];
        if ($r['last_end'] && ($last === null || $r['last_end'] > $last)) $last = $r['last_end'];
        $schedRows[$label[(int)$s['id']]] = [
            'tasks' => (int)$r['n'], 'start' => $r['first_start'], 'end' => $r['last_end'],
        ];
    }
    $out['sections']['schedule'] = [
        'figures' => [
            'Scheduled tasks'          => [$tasks, 'num'],
            'First task starts'        => [$first, 'date'],
            'Last task ends'           => [$last, 'date'],
            // David, 2026-11-11: "change task with nobody assigned to tasks not
            // assigned". Kick-offs saved before then hold the old label;
            // kickoff_render_section() renames it so v1 and now stay one row.
            'Tasks not assigned'       => [$nobody, 'num'],
        ],
        'texts' => [],
        'lists' => [
            'By step' => [
                'cols' => ['tasks' => ['Tasks', 'num'], 'start' => ['Starts', 'date'], 'end' => ['Ends', 'date']],
                'rows' => $schedRows,
            ],
        ],
    ];

    // ---- Timeline (the general one, a bar per step, and the milestones) ---
    $tlRows = []; $dated = 0; $tStart = null; $tEnd = null;
    foreach ($steps as $s) {
        $a = $s['start_date'] ?: null; $b = $s['deadline'] ?: null;
        if ($a || $b) $dated++;
        if ($a && ($tStart === null || $a < $tStart)) $tStart = $a;
        if ($b && ($tEnd === null || $b > $tEnd)) $tEnd = $b;
        $tlRows[$label[(int)$s['id']]] = [
            'start' => $a, 'end' => $b,
            'days'  => ($a && $b && $b >= $a) ? working_days_between($a, $b) : null,
            // Not a column (not in 'cols', so never compared or listed): the
            // step's colour, for the bars of Kick-off vs now.
            'color' => (string)($s['color'] ?? ''),
        ];
    }
    $msRows = [];
    if (column_exists($pdo, 'project_milestones', 'milestone_date')) {
        $q = $pdo->prepare('SELECT * FROM project_milestones WHERE project_id = ? ORDER BY milestone_date, id');
        $q->execute([$pid]);
        foreach ($q as $m) {
            $msRows[kickoff_unique_key($msRows, (string)$m['name'])] = [
                'date' => $m['milestone_date'],
                'step' => isset($m['step_id'], $label[(int)$m['step_id']]) ? $label[(int)$m['step_id']] : '',
            ];
        }
    }
    $out['sections']['timeline'] = [
        'figures' => [
            'Steps with dates' => [$dated, 'num'],
            'First step starts' => [$tStart, 'date'],
            'Last step ends'    => [$tEnd, 'date'],
            'Milestones'        => [count($msRows), 'num'],
        ],
        'texts' => [],
        'lists' => [
            'Steps' => [
                'cols' => ['start' => ['Starts', 'date'], 'end' => ['Ends', 'date'], 'days' => ['Working days', 'num']],
                'rows' => $tlRows,
            ],
            'Milestones' => [
                'cols' => ['date' => ['Date', 'date'], 'step' => ['Step', 'text']],
                'rows' => $msRows,
            ],
        ],
    ];

    // ---- Shots and assets ------------------------------------------------
    $fps = max(1, (int)($project['fps'] ?? 24));
    $q = $pdo->prepare('SELECT episode, shot_code, frames FROM shots WHERE project_id = ? ORDER BY episode, shot_code, id');
    $q->execute([$pid]);
    $shotRows = []; $frames = 0; $noFrames = 0;
    foreach ($q as $s) {
        $f = $s['frames'] !== null ? (int)$s['frames'] : null;
        if ($f === null) $noFrames++; else $frames += $f;
        $shotRows[kickoff_unique_key($shotRows, shot_label($s['episode'], $s['shot_code']))] = ['frames' => $f];
    }
    $q = $pdo->prepare('SELECT name, asset_type FROM assets WHERE project_id = ? ORDER BY asset_type, name, id');
    $q->execute([$pid]);
    $assetRows = []; $byType = [];
    foreach ($q as $a) {
        $t = trim((string)$a['asset_type']) ?: 'Untyped';
        $byType[$t] = ($byType[$t] ?? 0) + 1;
        $assetRows[kickoff_unique_key($assetRows, (string)$a['name'])] = ['type' => $t];
    }
    $shotFigures = [
        'Shots'            => [count($shotRows), 'num'],
        'Frames'           => [$frames, 'num'],
        'Footage, seconds' => [round($frames / $fps, 1), 'num'],
        'Shots with no frame count' => [$noFrames, 'num'],
        'Assets'           => [count($assetRows), 'num'],
    ];
    ksort($byType);
    foreach ($byType as $t => $n) $shotFigures['Assets: ' . $t] = [$n, 'num'];
    $out['sections']['shots'] = [
        'figures' => $shotFigures,
        'texts'   => [],
        'lists'   => [
            'Shots'  => ['cols' => ['frames' => ['Frames', 'num']], 'rows' => $shotRows],
            'Assets' => ['cols' => ['type' => ['Type', 'text']], 'rows' => $assetRows],
        ],
    ];

    // ---- Setup -----------------------------------------------------------
    $estimates = project_estimates($pdo, $pid);
    $retake    = project_retake_pct($pdo, $pid);
    $deps      = step_dependencies_map($pdo, $pid);
    $stepRows = []; $headcount = 0.0;
    foreach ($steps as $s) {
        $hc = $s['planned_headcount'] !== null ? (float)$s['planned_headcount'] : null;
        $headcount += (float)$hc;
        $dur = step_duration($pdo, $pid, $s, $estimates, $retake);
        $stepRows[$label[(int)$s['id']]] = [
            'department' => trim((string)($s['department'] ?? '')) ?: 'Unassigned',
            'applies'    => applies_to_phrase((string)$s['applies_to'], $s['applies_to_asset_type'] ?? null),
            'per_unit'   => effective_days_per_unit($pdo, $pid, $s),
            'coverage'   => (float)($s['coverage_pct'] ?? 100),
            'headcount'  => $hc,
            'duration'   => $dur['days'],
        ];
    }
    $flowRows = [];
    foreach ($deps as $stepId => $parents) {
        foreach ((array)$parents as $parentId) {
            if (!isset($label[(int)$stepId], $label[(int)$parentId])) continue;
            $flowRows[$label[(int)$parentId] . ' → ' . $label[(int)$stepId]] = [];
        }
    }
    ksort($flowRows);
    $setupFigures = [
        'Project name'   => [(string)$project['name'], 'text'],
        'Code'           => [(string)$project['code'], 'text'],
        'Type'           => [(string)$project['type'], 'text'],
        'Frames per second' => [(int)$project['fps'], 'num'],
        'Start date'     => [$project['start_date'] ?: null, 'date'],
        'Delivery date'  => [$project['delivery_date'] ?: null, 'date'],
        'Pipeline steps' => [count($steps), 'num'],
        'Shots'          => [count($shotRows), 'num'],
        'Assets'         => [count($assetRows), 'num'],
        'Head count'     => [round($headcount, 2), 'num'],
        'Retakes allowed, %' => [$retake, 'num'],
    ];
    if ($estimates['seconds'] > 0) $setupFigures['Early estimate: seconds'] = [$estimates['seconds'], 'num'];
    if ($estimates['shots'] > 0)   $setupFigures['Early estimate: shots']   = [$estimates['shots'], 'num'];
    if ($estimates['assets'] > 0)  $setupFigures['Early estimate: assets']  = [$estimates['assets'], 'num'];
    $out['sections']['setup'] = [
        'figures' => $setupFigures,
        'texts'   => [],
        'lists'   => [
            'Pipeline' => [
                'cols' => [
                    'department' => ['Department', 'text'],
                    'applies'    => ['Applies to', 'text'],
                    'per_unit'   => ['Days per item', 'num'],
                    'coverage'   => ['Coverage %', 'num'],
                    'headcount'  => ['Head count', 'num'],
                    'duration'   => ['Working days needed', 'num'],
                ],
                'rows' => $stepRows,
            ],
            'Workflow' => ['cols' => [], 'rows' => $flowRows],
        ],
    ];

    return $out;
}

/**
 * The money, as it stands right now, or null when the caller cannot read it.
 *
 * Same functions as the finance screens, so a figure here and a figure there
 * cannot disagree. ['configured' => false] when finance is not set up at all.
 */
function kickoff_finance_snapshot(PDO $pdo, array $project): ?array {
    if (!finance_configured()) return ['configured' => false];
    if (!can_see_money() || !finance_db()) return null;

    require_once __DIR__ . '/finance.php';
    require_once __DIR__ . '/dashboard.php';
    require_once __DIR__ . '/budgetpool.php';

    $pid    = (int)$project['id'];
    $budget = project_budget($pid);
    $proj   = expense_projection($pdo, $pid);
    $cur    = (string)($budget['currency'] ?? $proj['currency'] ?? 'USD');

    $projRows = [];
    foreach ($proj['rows'] as $r) {
        $key = $r['role'] . ' · ' . ($r['artist'] !== null && !$r['is_slot'] ? $r['artist'] : 'unfilled');
        // In dollars when the project's fixed rate converted the row, like its
        // cost; the rate in its own currency is in the Exchange rates text.
        $projRows[kickoff_unique_key($projRows, $key)] = [
            'rate' => $r['rate_usd'] ?? $r['rate'], 'unit' => (string)($r['rate_unit'] ?? ''),
            'days' => round((float)$r['days'], 2), 'cost' => $r['cost'],
            // Which step the row belongs to, so the Overview can judge each step
            // against its own plan from sign-off even after a rename. Not one of
            // the list's columns, so the kick-off page never draws it.
            'step_id' => (int)$r['step']['id'],
            // What the step works on, as it was when this was saved, so the
            // cost of shots and assets can be split later even if the step is
            // retagged or deleted. Not columns either.
            'applies'    => (string)($r['step']['applies_to'] ?? ''),
            'asset_type' => (string)($r['step']['applies_to_asset_type'] ?? ''),
        ];
    }
    $otherRows = [];
    foreach ($proj['other'] ?? [] as $x) {
        $key = trim(($x['category'] ?? '') . ': ' . ($x['description'] ?? ''), ': ');
        $otherRows[kickoff_unique_key($otherRows, $key ?: 'Expense')] = [
            'amount' => (float)($x['amount_usd'] ?? $x['amount']), 'status' => (string)($x['status'] ?? ''),
        ];
    }
    $invRows = []; $invoiced = 0.0;
    $fx = $proj['fx'] ?? [];
    foreach (project_invoices($pid) as $i) {
        $i['amount'] = fx_usd_or_own((float)$i['amount'], $i['currency'] ?? 'USD', $fx)[0];
        $invoiced += (float)$i['amount'];
        $key = trim((string)($i['invoice_ref'] ?? '')) ?: ('Invoice ' . (int)$i['id']);
        $invRows[kickoff_unique_key($invRows, $key)] = [
            'person' => (string)($i['crew_name'] ?? ''), 'amount' => (float)$i['amount'],
            'status' => (string)$i['status'],
        ];
    }
    // David, 2026-11-11: "let's include a row for committed so far so we can
    // compare too". The Overview's own figure (step_variance()'s 'spent': every
    // invoice plus logged days not invoiced yet), so the two cannot disagree.
    // A failure here leaves the row empty rather than stopping a sign-off.
    $committed = null;
    try {
        require_once __DIR__ . '/step_variance.php';
        $sv = step_variance($pdo, $project, $proj,
                            isset($budget['budget_amount']) ? (float)$budget['budget_amount'] : null,
                            dashboard_steps($pdo, $pid, $project['delivery_date'] ?? null));
        $committed = isset($sv['spent']) ? (float)$sv['spent'] : null;
    } catch (Throwable $e) {
        $committed = null;
    }
    $pool = budget_pool($pdo, $project, $proj);
    $poolRows = [];
    foreach ($pool['days']['rows'] ?? [] as $r) {
        $poolRows[kickoff_unique_key($poolRows, (string)($r['name'] ?? 'Step'))] = [
            'pool' => round((float)($r['pool'] ?? 0), 2), 'used' => round((float)($r['used'] ?? 0), 2),
        ];
    }

    return [
        'configured' => true,
        'currency'   => $cur,
        'figures' => [
            // ⚠ NO REVENUE (David, 2026-11-06: "Remove 'Revenue (billed to
            // the client)' from the kick-off snapshot, Kick-off vs now, and
            // anywhere else"). Anima tracks budget against cost, never profit;
            // the Overview dropped revenue on 2026-10-12, and this was the last
            // place still writing it. Older snapshots that hold it have it
            // stripped as they are read, in kickoff_finance_load().
            'Approved production budget'     => [isset($budget['budget_amount']) ? (float)$budget['budget_amount'] : null, 'money'],
            'Projected labour cost'          => [(float)$proj['total'], 'money'],
            'Other expenses'                 => [(float)$proj['other_total'], 'money'],
            'Projected total'                => [(float)$proj['grand_total'], 'money'],
            'Invoiced so far'                => [$invoiced, 'money'],
            'Committed so far'               => [$committed, 'money'],
            'Budget pool, days'              => [round((float)$pool['days']['pool'], 2), 'num'],
            'Budget pool, days used'         => [round((float)$pool['days']['used'], 2), 'num'],
        ],
        // The fixed rates the dollar figures above rest on, with their dates
        // and sources (2026-10-28). Empty, and so not drawn, on an all-dollar
        // show.
        'texts' => $fx ? ['Exchange rates' => implode('; ', array_map('fx_describe', $fx))] : [],
        'lists' => [
            'Expense projection' => [
                'cols' => ['rate' => ['Rate', 'money'], 'unit' => ['Per', 'text'],
                           'days' => ['Days', 'num'], 'cost' => ['Cost', 'money']],
                'rows' => $projRows,
            ],
            'Other expenses' => [
                'cols' => ['amount' => ['Amount', 'money'], 'status' => ['Status', 'text']],
                'rows' => $otherRows,
            ],
            'Invoices' => [
                'cols' => ['person' => ['From', 'text'], 'amount' => ['Amount', 'money'], 'status' => ['Status', 'text']],
                'rows' => $invRows,
            ],
            'Budget pool by step' => [
                'cols' => ['pool' => ['Days in the pool', 'num'], 'used' => ['Days used', 'num']],
                'rows' => $poolRows,
            ],
        ],
    ];
}

/** The money side of a stored kick-off, or null when it cannot be read. */
function kickoff_finance_load(int $kickoffId): ?array {
    if (!can_see_money()) return null;
    $db = finance_db();
    if (!$db) return null;
    try {
        $q = $db->prepare('SELECT snapshot FROM kickoff_finance_snapshots WHERE kickoff_id = ?');
        $q->execute([$kickoffId]);
        $raw = $q->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
    $snap = $raw ? (json_decode((string)$raw, true) ?: null) : null;
    // Snapshots taken before 2026-11-06 still hold the client revenue figure.
    // Dropped on the way out rather than rewritten in the database: the stored
    // record stays as it was taken, and no screen shows the figure.
    if (is_array($snap) && isset($snap['figures']) && is_array($snap['figures'])) {
        unset($snap['figures']['Revenue (billed to the client)']);
        // Snapshots taken before 2026-11-11 did not record Committed so far.
        // An empty slot right under Invoiced keeps the row in its place
        // (shown "—") instead of trailing at the bottom as "added".
        if (!array_key_exists('Committed so far', $snap['figures'])) {
            $f = [];
            foreach ($snap['figures'] as $k => $v) {
                $f[$k] = $v;
                if ($k === 'Invoiced so far') $f['Committed so far'] = [null, 'money'];
            }
            $snap['figures'] = $f;
        }
    }
    return $snap;
}

// ---------------------------------------------------------------------------
// KICK-OFF VERSIONS
//
// David, 2026-11-06: "Every project has a kick-off approved by Jorik. When
// Jorik approves more budget, a new kick-off version is saved, and 'Kick-off vs
// now' always compares with the latest one he approved."
//
// So a project can have SEVERAL approved kick-offs. The first is the sign-off
// (Tim and Jorik, setup to active); each later one is written when somebody
// ticked "Changes budget" (Jorik) changes the signed-off budget amount. Every
// reader that asks for "the kick-off" (kickoff_approved(): Kick-off vs now, the
// budget lock, the per-step plan on the Overview) already takes the NEWEST
// approved row, so a new row is all it takes to move the baseline. Older ones
// are never touched, and stay readable on Kick-off vs now.
//
// NO MIGRATION: a budget version is an ordinary approved project_kickoffs row
// plus its kickoff_finance_snapshots row. What tells it apart is a 'version'
// key inside the two snapshots. The production one says only that it was a
// budget change; the amounts and the reason go in the finance one, because
// they are money and an editor can read the production snapshot.
//
// The whole show is snapshotted again, not just the budget: "vs now" has to
// mean "since Jorik last approved it", and comparing a new budget with a
// months-old schedule would mix the two moments.
// ---------------------------------------------------------------------------

/**
 * What made a kick-off row: 'signoff' (setup to active), 'replan' (sent again
 * after a re-plan) or 'budget_change' (a budget request approved). Read from
 * the 'version' key of its production snapshot; rows without one are
 * sign-offs.
 */
function kickoff_kind(array $kickoff): string {
    $snap = json_decode((string)($kickoff['snapshot'] ?? ''), true);
    return (string)($snap['version']['kind'] ?? 'signoff');
}

/**
 * The two column headings of a comparison: "At kick-off" and "Now" unless the
 * page compares two stored versions. Set once by the page and read by the
 * renderers below, so their signatures stay as every caller knows them.
 */
function kickoff_labels(?array $set = null): array {
    // [long left, long right, short left, short right]; the short pair heads the
    // paired columns of a compared list.
    static $labels = ['At kick-off', 'Now', 'Kick-off', 'Now'];
    if ($set !== null) $labels = $set;
    return $labels;
}

/**
 * Every approved kick-off of a project, oldest first, each with its version
 * number ('n') and 'kind' (see kickoff_kind()).
 */
function kickoff_versions(PDO $pdo, int $projectId): array {
    try {
        $q = $pdo->prepare(
            "SELECT * FROM project_kickoffs WHERE project_id = ? AND state = 'approved' ORDER BY id"
        );
        $q->execute([$projectId]);
        $rows = $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $n = 0;
    foreach ($rows as &$r) {
        $r['n'] = ++$n;
        $r['kind'] = kickoff_kind($r);
    }
    unset($r);
    return $rows;
}

/**
 * After a signed-off budget was changed, save the show as a new approved
 * kick-off version. Called by finance_project.php straight after
 * save_project_budget() succeeded, with the kick-off that locked the budget
 * before the change.
 *
 * Only when the AMOUNT (or its currency) moved: a change to the terms or notes
 * alone is recorded in the budget history as before, but it is not a new
 * approved budget.
 *
 * @return string|null the sentence for the screen, or null when no version was due
 */
function kickoff_budget_version(PDO $pdo, array $project, int $previousKickoffId): ?string {
    if (!kickoff_ready($pdo) || !can_see_money() || !finance_db()) return null;
    require_once __DIR__ . '/finance.php';
    $pid = (int)$project['id'];

    // The change save_project_budget() has just written, newest first. Checked
    // against the kick-off it was made under, so a double submit or a stale
    // read cannot write the same version twice.
    $c = project_budget_changes($pid)[0] ?? null;
    if (!$c || (int)($c['kickoff_id'] ?? 0) !== $previousKickoffId) return null;
    $amountMoved = (string)($c['old_amount'] ?? '') !== (string)($c['new_amount'] ?? '')
                || (string)($c['old_currency'] ?? '') !== (string)($c['new_currency'] ?? '');
    if (!$amountMoved) return null;

    $snap = kickoff_snapshot($pdo, $project);
    $snap['version'] = ['kind' => 'budget_change', 'previous' => $previousKickoffId];
    $fin  = kickoff_finance_snapshot($pdo, $project);
    if ($fin !== null && !empty($fin['configured'])) {
        $fin['version'] = [
            'kind'         => 'budget_change',
            'change_id'    => (int)$c['id'],
            'reason'       => (string)$c['reason'],
            'old_amount'   => $c['old_amount'] !== null ? (float)$c['old_amount'] : null,
            'new_amount'   => $c['new_amount'] !== null ? (float)$c['new_amount'] : null,
            'old_currency' => (string)($c['old_currency'] ?: 'USD'),
            'new_currency' => (string)($c['new_currency'] ?: 'USD'),
        ];
    }
    $user = current_user();
    $who  = mb_substr(current_user_display(), 0, 100);
    $now  = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO project_kickoffs (project_id, state, requested_by, requested_by_name,
                              requested_at, decided_at, snapshot)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$pid, 'approved', $user['id'] ?? null, $who, $now, $now,
                       json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        $kid = (int)$pdo->lastInsertId();
        // The person who changed it is the one who approved it: recorded as a
        // sign-off so "signed off by" reads the same on every version. No
        // reason here; it is money talk, and it is in the finance snapshot.
        $pdo->prepare('INSERT INTO project_kickoff_signoffs (kickoff_id, user_id, signer_name, decision, note,
                              decided_at, email_result)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kid, $user['id'] ?? null, $who, 'approved', 'Approved a change to the budget.',
                       $now, 'not emailed: saved by a budget change']);
        if ($fin !== null && !empty($fin['configured'])) {
            finance_db()->prepare(
                'INSERT INTO kickoff_finance_snapshots (kickoff_id, project_id, project_code, snapshot, captured_at, captured_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$kid, $pid, $project['code'], json_encode($fin, JSON_UNESCAPED_UNICODE), $now, $who]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $n = count(kickoff_versions($pdo, $pid));
    return 'Saved as kick-off version ' . $n . ': Kick-off vs now compares with this approved budget from now on.';
}

// ---------------------------------------------------------------------------
// THE FLOW
// ---------------------------------------------------------------------------

/** Send one email, never throwing. Returns the sentence that goes on screen. */
function kickoff_mail(array $to, string $subject, string $html, string $text): array {
    global $config;
    if (!SmtpMailer::isConfigured($config)) return [false, 'email is not set up on this server'];
    if (empty($to['email'])) return [false, 'no email address on file'];
    try {
        (new SmtpMailer($config))->send([$to], $subject, $html, $text);
        return [true, 'emailed ' . $to['email']];
    } catch (Throwable $e) {
        return [false, 'the email failed: ' . $e->getMessage()];
    }
}

/** The shared look of every email this file sends. */
function kickoff_mail_html(string $lead, array $lines, string $url, string $button, string $quote = ''): string {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
        . '<p>' . $lead . '</p>'
        . ($lines ? '<p style="color:#5a6a7a;margin:0 0 12px;">' . implode('<br>', array_map($esc, $lines)) . '</p>' : '')
        . ($quote !== '' ? '<div style="background:#f4f6f8;padding:10px 12px;border-radius:6px;white-space:pre-wrap;">'
                          . $esc($quote) . '</div>' : '')
        . '<p style="margin-top:16px;"><a href="' . $esc($url) . '" '
        . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
        . $esc($button) . '</a></p>'
        . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
        . '</div>';
}

/** The requester's address: their own, else their linked crew member's. */
function kickoff_user_email(PDO $pdo, ?int $userId): ?array {
    if (!$userId) return null;
    $q = $pdo->prepare(
        "SELECT COALESCE(NULLIF(u.display_name, ''), u.username) AS name,
                COALESCE(NULLIF(TRIM(COALESCE(u.email, '')), ''), NULLIF(TRIM(COALESCE(c.email, '')), '')) AS email
           FROM users u LEFT JOIN crew c ON c.id = u.crew_id WHERE u.id = ?"
    );
    $q->execute([$userId]);
    $r = $q->fetch();
    return $r && $r['email'] ? ['email' => $r['email'], 'name' => $r['name']] : null;
}

/**
 * Freeze the setup and ask the sign-off people to approve it.
 *
 * Any earlier open request is withdrawn first, so there is only ever one thing
 * waiting to be approved, and it is always the latest setup.
 *
 * @return string[] sentences for the screen
 */
function kickoff_request(PDO $pdo, array $project, array $signerIds): array {
    $pid = (int)$project['id'];
    // A RE-PLAN (David, 2026-11-06: "A new version is made when Jorik approves
    // a budget request, or when the project is sent for sign-off again after a
    // re-plan"). A project that is already signed off can be sent again; it
    // keeps its status and is NOT frozen while it waits (his choice that day:
    // "Stays active, not frozen"). Approval makes it the next kick-off version.
    $replan = $project['status'] !== 'setup';
    if ($replan && kickoff_approved($pdo, $pid) === null) {
        throw new RuntimeException('Only a project in setup, or one already signed off, is sent for sign-off.');
    }
    if ($why = kickoff_finance_problem()) throw new RuntimeException($why);
    // Chosen in the pop-up, and checked again here: only people who can save
    // can approve.
    $signerIds = array_map('intval', $signerIds);
    $signers = array_values(array_filter(kickoff_candidates($pdo),
        fn($u) => in_array((int)$u['id'], $signerIds, true)));
    if (!$signers) {
        throw new RuntimeException('Choose at least one person to sign it off. Nothing was sent.');
    }

    // ⚠ THE EXCHANGE RATE IS FIXED BY NOW AT THE LATEST (Tim and Jorik,
    // 2026-10-28). Normally the Finance page saved it at set-up; any foreign
    // currency still without one gets the day's rate here, before the money is
    // snapshotted, so the snapshot is in dollars. fx_fix_missing() never
    // throws and a failed fetch leaves that currency unconverted (the
    // snapshot says so): a currency service being down must not stop a
    // sign-off. Its sentences go back to the screen with the rest.
    $fxNotes = [];
    if (can_see_money() && finance_db()) {
        require_once __DIR__ . '/finance.php';
        try {
            $fxNotes = fx_fix_missing($pid, fx_project_currencies(expense_projection($pdo, $pid), project_invoices($pid)),
                                      current_user_display(), true);
        } catch (Throwable $e) {
            error_log('[Anima fx at sign-off] ' . $e->getMessage());
        }
    }

    $snap = kickoff_snapshot($pdo, $project);
    // ⚠ FIRST KEY, ON PURPOSE: kickoff_frozen() in includes/auth.php tells a
    // re-plan from a first sign-off by how the stored JSON BEGINS
    // ('{"version":{"kind":"replan"'), so it can skip the freeze without
    // reading the whole snapshot on every save.
    if ($replan) $snap = ['version' => ['kind' => 'replan']] + $snap;
    $fin  = kickoff_finance_snapshot($pdo, $project);
    $user = current_user();
    $who  = current_user_display();
    $now  = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE project_kickoffs SET state = 'withdrawn', decided_at = ?
                        WHERE project_id = ? AND state IN ('pending','changes_requested')")
            ->execute([$now, $pid]);
        $pdo->prepare('INSERT INTO project_kickoffs (project_id, state, requested_by, requested_by_name, requested_at, snapshot)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$pid, 'pending', $user['id'] ?? null, mb_substr($who, 0, 100), $now,
                       json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        $kid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO project_kickoff_signoffs (kickoff_id, user_id, signer_name, signer_email)
                              VALUES (?, ?, ?, ?)');
        foreach ($signers as $s) $ins->execute([$kid, (int)$s['id'], mb_substr($s['name'], 0, 100), $s['email']]);

        // The money goes to the other database. Inside this transaction's try
        // so a failure there undoes the request: a kick-off approved without
        // its finance is exactly the gap this feature exists to close.
        if ($fin !== null && !empty($fin['configured'])) {
            finance_db()->prepare(
                'INSERT INTO kickoff_finance_snapshots (kickoff_id, project_id, project_code, snapshot, captured_at, captured_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$kid, $pid, $project['code'], json_encode($fin, JSON_UNESCAPED_UNICODE), $now, mb_substr($who, 0, 100)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // The emails go AFTER the commit: a slow mail server must not hold the
    // request open, and a failed send must not undo it. Each result is kept.
    $url = kickoff_url($pid);
    $setup = $snap['sections']['setup']['figures'];
    $lines = [
        'Project: ' . $project['name'] . ' (' . $project['code'] . ')',
        'Sent by: ' . $who,
        'Dates: ' . ($setup['Start date'][0] ?: 'no start date') . ' to ' . ($setup['Delivery date'][0] ?: 'no delivery date'),
        $setup['Pipeline steps'][0] . ' steps, ' . $setup['Shots'][0] . ' shots, ' . $setup['Assets'][0]
            . ' assets, head count ' . $setup['Head count'][0],
    ];
    $msgs = [];
    $upd = $pdo->prepare('UPDATE project_kickoff_signoffs SET email_result = ? WHERE kickoff_id = ? AND user_id = ?');
    $ask = $replan
        ? ' has been re-planned and needs your sign-off. It carries on as it is while you look; '
          . 'once approved, this plan becomes its new kick-off version.'
        : ' is ready to go from setup to active, and needs your sign-off. '
          . 'Please look at how it is set up and approve it, or ask for changes.';
    foreach ($signers as $s) {
        [$ok, $res] = kickoff_mail(
            ['email' => $s['email'], 'name' => $s['name']],
            sprintf('[%s] %s: %s', $project['code'], $replan ? 'Re-plan sign-off' : 'Kick-off sign-off', $project['name']),
            kickoff_mail_html('<strong>' . htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') . '</strong>'
                . htmlspecialchars($ask, ENT_QUOTES, 'UTF-8'), $lines, $url, 'Review and sign off'),
            $project['name'] . $ask . "\n\n" . implode("\n", $lines) . "\n\nReview and sign off: {$url}\n"
        );
        $upd->execute([mb_substr($res, 0, 255), $kid, (int)$s['id']]);
        $msgs[] = $s['name'] . ': ' . $res . '.';
    }
    return array_merge($fxNotes, $msgs);
}

/**
 * One signer's answer. When the last approval lands the project turns active.
 *
 * @return string[] sentences for the screen
 */
function kickoff_decide(PDO $pdo, array $project, array $kickoff, string $decision, string $note): array {
    $user = current_user();
    $uid  = (int)($user['id'] ?? 0);
    if ($kickoff['state'] !== 'pending') {
        throw new RuntimeException('This sign-off is no longer waiting for an answer.');
    }
    if (!in_array($decision, ['approved', 'changes'], true)) {
        throw new RuntimeException('That is not an answer this page knows.');
    }
    $note = trim($note);
    if ($decision === 'changes' && $note === '') {
        throw new RuntimeException('Say what should change, so whoever set it up knows what to fix.');
    }
    $q = $pdo->prepare('SELECT * FROM project_kickoff_signoffs WHERE kickoff_id = ? AND user_id = ?');
    $q->execute([(int)$kickoff['id'], $uid]);
    $mine = $q->fetch();
    if (!$mine) throw new RuntimeException('You are not one of the people asked to sign this off.');
    if ($mine['decision'] !== null) throw new RuntimeException('You have already answered this one.');

    $now = date('Y-m-d H:i:s');
    $kid = (int)$kickoff['id'];
    $pid = (int)$project['id'];
    $activated = false;
    $replan = kickoff_kind($kickoff) === 'replan';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE project_kickoff_signoffs SET decision = ?, note = ?, decided_at = ? WHERE id = ?')
            ->execute([$decision, $note !== '' ? $note : null, $now, (int)$mine['id']]);

        if ($decision === 'changes') {
            $pdo->prepare("UPDATE project_kickoffs SET state = 'changes_requested', decided_at = ? WHERE id = ?")
                ->execute([$now, $kid]);
        } else {
            $left = $pdo->prepare("SELECT COUNT(*) FROM project_kickoff_signoffs
                                    WHERE kickoff_id = ? AND (decision IS NULL OR decision <> 'approved')");
            $left->execute([$kid]);
            if ((int)$left->fetchColumn() === 0) {
                $pdo->prepare("UPDATE project_kickoffs SET state = 'approved', decided_at = ? WHERE id = ?")
                    ->execute([$now, $kid]);
                // Only from setup: if somebody moved the project on by another
                // route meanwhile, the approval is recorded and the status is
                // left as they set it.
                $st = $pdo->prepare("UPDATE projects SET status = 'active' WHERE id = ? AND status = 'setup'");
                $st->execute([$pid]);
                $activated = $st->rowCount() > 0;
                $pdo->prepare('DELETE FROM project_settings WHERE project_id = ? AND setting_key = ?')
                    ->execute([$pid, 'wizard_step']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $msgs = [];
    $who  = current_user_display();
    $url  = kickoff_url($pid);
    $to   = kickoff_user_email($pdo, $kickoff['requested_by'] !== null ? (int)$kickoff['requested_by'] : null);

    if ($decision === 'changes') {
        $msgs[] = $replan ? 'Sent back for changes. The project carries on with its current kick-off version.'
                          : 'Sent back for changes. The project stays in setup.';
        if ($to) {
            [, $res] = kickoff_mail($to,
                sprintf('[%s] Changes asked for before kick-off: %s', $project['code'], $project['name']),
                kickoff_mail_html(htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . ' asked for changes before <strong>'
                    . htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') . '</strong> can go active.',
                    [], $url, 'Open the sign-off', $note),
                "{$who} asked for changes before {$project['name']} can go active.\n\n{$note}\n\n{$url}\n");
            $msgs[] = ($kickoff['requested_by_name'] ?: 'Whoever sent it') . ': ' . $res . '.';
        }
        return $msgs;
    }

    $state = $pdo->prepare('SELECT state FROM project_kickoffs WHERE id = ?');
    $state->execute([$kid]);
    if ($state->fetchColumn() !== 'approved') {
        $msgs[] = 'Approved. The project turns active once everybody else has approved too.';
        return $msgs;
    }

    if ($replan) {
        // A re-plan changes no status and leaves the starting estimate per
        // step (Historic quotas) as the first sign-off recorded it.
        $msgs[] = 'Re-plan signed off by everybody: saved as kick-off version ' . count(kickoff_versions($pdo, $pid))
                . '. Kick-off vs now compares with it from now on.';
        if ($to) {
            [, $res] = kickoff_mail($to,
                sprintf('[%s] Re-plan signed off: %s', $project['code'], $project['name']),
                kickoff_mail_html('The re-plan of <strong>' . htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8')
                    . '</strong> has been signed off by everybody and is its new kick-off version.', [], $url, 'Open the kick-off'),
                "The re-plan of {$project['name']} has been signed off by everybody and is its new kick-off version.\n\n{$url}\n");
            $msgs[] = ($kickoff['requested_by_name'] ?: 'Whoever sent it') . ': ' . $res . '.';
        }
        return $msgs;
    }

    $msgs[] = $activated
        ? 'Signed off by everybody: "' . $project['name'] . '" is now active, and its kick-off is stored.'
        : 'Signed off by everybody, and the kick-off is stored. The project had already left setup, so its status was left as it is.';

    // The per-step estimate baseline, as finishing the wizard used to write it.
    // Never blocks the approval; reported if it fails.
    try {
        $n = capture_baseline($pdo, $pid, 'kick-off sign-off');
        if ($n > 0) $msgs[] = "Recorded the starting estimate for $n step" . ($n === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        $msgs[] = 'The starting estimate per step could not be recorded: ' . $e->getMessage();
    }

    if ($to) {
        [, $res] = kickoff_mail($to,
            sprintf('[%s] Signed off, now active: %s', $project['code'], $project['name']),
            kickoff_mail_html('<strong>' . htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8')
                . '</strong> has been signed off by everybody and is now active.', [], $url, 'Open the kick-off'),
            "{$project['name']} has been signed off by everybody and is now active.\n\n{$url}\n");
        $msgs[] = ($kickoff['requested_by_name'] ?: 'Whoever sent it') . ': ' . $res . '.';
    }
    return $msgs;
}

/**
 * An administrator approves in place of everybody who has not answered yet.
 *
 * David, 2026-10-16: possible, for when somebody is away, but everybody is
 * emailed that the original state is being set this way. The reason is kept on
 * each row it filled in, so the record says it was an override, by whom and why.
 */
function kickoff_override(PDO $pdo, array $project, array $kickoff, string $reason): array {
    if (!is_admin()) throw new RuntimeException('Only an administrator can approve on somebody else\'s behalf.');
    if ($kickoff['state'] !== 'pending') throw new RuntimeException('This sign-off is no longer waiting for an answer.');
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Say why it is being approved without them; everybody is told.');

    $who = current_user_display();
    $now = date('Y-m-d H:i:s');
    $kid = (int)$kickoff['id'];
    $missing = array_values(array_filter(kickoff_signoffs($pdo, $kid), fn($s) => $s['decision'] === null));
    if (!$missing) throw new RuntimeException('Everybody has already answered.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE project_kickoff_signoffs
                          SET decision = 'approved', decided_at = ?, approved_by_override = ?, note = ?
                        WHERE kickoff_id = ? AND decision IS NULL")
            ->execute([$now, mb_substr($who, 0, 100), 'Approved by ' . $who . ' on their behalf: ' . $reason, $kid]);
        // Same ending as a normal last approval: active, baseline, emails.
        $pdo->prepare("UPDATE project_kickoffs SET state = 'approved', decided_at = ? WHERE id = ?")->execute([$now, $kid]);
        $st = $pdo->prepare("UPDATE projects SET status = 'active' WHERE id = ? AND status = 'setup'");
        $st->execute([(int)$project['id']]);
        $pdo->prepare('DELETE FROM project_settings WHERE project_id = ? AND setting_key = ?')
            ->execute([(int)$project['id'], 'wizard_step']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $names = implode(' and ', array_map(fn($s) => $s['signer_name'], $missing));
    $msgs = ['Approved on behalf of ' . $names . '. "' . $project['name'] . '" is '
           . ($st->rowCount() ? 'now active' : 'signed off') . ', and everybody is being told.'];
    // Not for a re-plan: the starting estimate per step stays the first one.
    if (kickoff_kind($kickoff) !== 'replan') {
        try {
            $n = capture_baseline($pdo, (int)$project['id'], 'kick-off sign-off (override)');
            if ($n > 0) $msgs[] = "Recorded the starting estimate for $n step" . ($n === 1 ? '' : 's') . '.';
        } catch (Throwable $e) {
            $msgs[] = 'The starting estimate per step could not be recorded: ' . $e->getMessage();
        }
    }

    // EVERYBODY: every signer asked, and whoever sent it, once each.
    $to = [];
    foreach (kickoff_signoffs($pdo, $kid) as $s) {
        if ($s['signer_email']) $to[strtolower($s['signer_email'])] = ['email' => $s['signer_email'], 'name' => $s['signer_name']];
    }
    if ($r = kickoff_user_email($pdo, $kickoff['requested_by'] !== null ? (int)$kickoff['requested_by'] : null)) {
        $to[strtolower($r['email'])] = $r;
    }
    $url  = kickoff_url((int)$project['id']);
    $line = $who . ' approved the kick-off of ' . $project['name'] . ' on behalf of ' . $names
          . ', so its original state is being set without their sign-off.';
    foreach ($to as $t) {
        [, $res] = kickoff_mail($t,
            sprintf('[%s] Original state set by override: %s', $project['code'], $project['name']),
            kickoff_mail_html(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), [], $url, 'Open the kick-off', 'Reason: ' . $reason),
            "{$line}\n\nReason: {$reason}\n\n{$url}\n");
        $msgs[] = $t['name'] . ': ' . $res . '.';
    }
    if (!$to) $msgs[] = 'Nobody involved has an email address on file, so nobody was emailed.';
    return $msgs;
}

/** Take back an open request. The project stays in setup. */
function kickoff_withdraw(PDO $pdo, array $kickoff): void {
    if (!in_array($kickoff['state'], ['pending', 'changes_requested'], true)) {
        throw new RuntimeException('There is nothing open to withdraw.');
    }
    $pdo->prepare("UPDATE project_kickoffs SET state = 'withdrawn', decided_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), (int)$kickoff['id']]);
}

/**
 * Reopen a frozen project for editing AND tell the people signing off.
 *
 * David, 2026-10-19: an admin needs a way to restart edits on a project that is
 * waiting for sign-off, and the people asked to sign it off should be told it is
 * open again and to wait for a NEW request. This is a withdraw (which voids the
 * request and unfreezes the project) PLUS that email — a bare withdraw told
 * nobody, which is the gap this closes. The old row is kept as 'withdrawn' so the
 * history still reads "asked, then reopened"; re-sending writes a fresh one.
 *
 * @return string[] sentences for the screen
 */
function kickoff_reopen(PDO $pdo, array $project, array $kickoff): array {
    if (!in_array($kickoff['state'], ['pending', 'changes_requested'], true)) {
        throw new RuntimeException('There is nothing open to reopen.');
    }
    $kid     = (int)$kickoff['id'];
    $signers = kickoff_signoffs($pdo, $kid);   // read the list before voiding it
    $pdo->prepare("UPDATE project_kickoffs SET state = 'withdrawn', decided_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $kid]);

    $who  = current_user_display();
    $url  = kickoff_url((int)$project['id']);
    $msgs = ['Editing is open again and the sign-off request is cancelled. The people who were asked to sign '
           . 'off are being told to wait for a new request.'];

    // One email per person asked, deduplicated by address.
    $to = [];
    foreach ($signers as $s) {
        if (empty($s['signer_email'])) continue;
        $to[strtolower($s['signer_email'])] = ['email' => $s['signer_email'], 'name' => $s['signer_name']];
    }
    $line = $who . ' has reopened ' . $project['name'] . ' for editing, so the sign-off you were asked for is '
          . 'cancelled. There is nothing to do right now — please wait for a fresh sign-off request.';
    foreach ($to as $t) {
        [, $res] = kickoff_mail($t,
            sprintf('[%s] Sign-off paused, please wait: %s', $project['code'], $project['name']),
            kickoff_mail_html(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), [], $url, 'Open the project'),
            "{$line}\n\n{$url}\n");
        $msgs[] = $t['name'] . ': ' . $res . '.';
    }
    if (!$to) $msgs[] = 'Nobody on the sign-off list has an email address on file, so nobody was emailed.';
    return $msgs;
}

// ---------------------------------------------------------------------------
// DRAWING IT
// ---------------------------------------------------------------------------

/** One value, written for a person. */
function kickoff_fmt($v, string $kind, string $currency = 'USD'): string {
    if ($v === null || $v === '') return '—';
    if ($kind === 'money') require_once __DIR__ . '/finance.php';   // fmt_money_plain()
    switch ($kind) {
        case 'money': return fmt_money_plain((float)$v, $currency);
        case 'num':   return fmt_num((float)$v, floor((float)$v) == (float)$v ? 0 : 2);
        case 'date':  return date('j M Y', strtotime((string)$v));
        default:      return (string)$v;
    }
}

/** How one value moved, in words, or '' when it did not. */
function kickoff_change($was, $now, string $kind, string $currency = 'USD'): string {
    if ((string)$was === (string)$now) return '';
    if ($kind === 'money') require_once __DIR__ . '/finance.php';
    if ($was === null || $was === '') return 'added';
    if ($now === null || $now === '') return 'removed';
    switch ($kind) {
        case 'money':
        case 'num':
            $d = (float)$now - (float)$was;
            if (abs($d) < 0.005) return '';
            $s = $d > 0 ? '+' : '−';
            $abs = $kind === 'money' ? fmt_money_plain(abs($d), $currency) : fmt_num(abs($d), floor(abs($d)) == abs($d) ? 0 : 2);
            $pct = (float)$was != 0.0 ? sprintf(' (%s%d%%)', $s, (int)round(abs($d) / abs((float)$was) * 100)) : '';
            return $s . $abs . $pct;
        case 'date':
            $days = (int)round((strtotime((string)$now) - strtotime((string)$was)) / 86400);
            if ($days === 0) return '';
            return ($days > 0 ? '+' : '−') . abs($days) . ' day' . (abs($days) === 1 ? '' : 's');
        default:
            return 'changed';
    }
}

/**
 * Draw one section: the frozen picture alone ($now null), or kick-off beside now.
 */
function kickoff_render_section(string $title, array $was, ?array $now, string $currency = 'USD', ?array $costs = null): void {
    $compare = $now !== null;
    // $costs, compared only: [left, right, project-wide left, project-wide
    // right], the first two figure label => money (see kickoff_shot_costs()).
    if (!$compare) $costs = null;
    // Figures are matched by label, and a saved kick-off keeps the label it was
    // saved with. A renamed figure is mapped back to its new name here, in
    // place (the order is kept), so an old version and now still line up.
    $renamed = ['Tasks with nobody on them' => 'Tasks not assigned'];
    $relabel = static function (array $figs) use ($renamed): array {
        $out = [];
        foreach ($figs as $label => $fig) $out[$renamed[$label] ?? $label] = $fig;
        return $out;
    };
    $was['figures'] = $relabel($was['figures'] ?? []);
    if ($compare) $now['figures'] = $relabel($now['figures'] ?? []);
    ?>
    <section class="kickoff-section">
      <h3><?= e($title) ?></h3>
      <?php if ($was['figures']): ?>
        <table class="data kickoff-figures">
          <?php if ($compare): ?>
            <thead><tr><th></th><th><?= e(kickoff_labels()[0]) ?></th><th><?= e(kickoff_labels()[1]) ?></th><th>Change</th>
              <?php if ($costs !== null): $L = kickoff_labels(); ?>
                <th class="num kickoff-cost"><?= e(($L[2] ?? $L[0]) . ' cost') ?></th><th class="num"><?= e(($L[3] ?? $L[1]) . ' cost') ?></th>
              <?php endif; ?></tr></thead>
          <?php endif; ?>
          <tbody>
          <?php foreach ($was['figures'] as $label => [$v, $kind]):
              $n = $compare ? ($now['figures'][$label][0] ?? null) : null;
              $chg = $compare ? kickoff_change($v, $n, $kind, $currency) : ''; ?>
            <tr<?= $chg !== '' ? ' class="kickoff-moved"' : '' ?>>
              <th scope="row"><?= e($label) ?></th>
              <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($v, $kind, $currency)) ?></td>
              <?php if ($compare): ?>
                <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($n, $kind, $currency)) ?></td>
                <td class="kickoff-chg"><?= e($chg) ?></td>
              <?php endif; ?>
              <?php if ($costs !== null):
                  $ca = $costs[0][$label] ?? null; $cb = $costs[1][$label] ?? null; ?>
                <td class="num kickoff-cost"><?= $ca === null && $cb === null ? '' : e(kickoff_fmt($ca, 'money', $currency)) ?></td>
                <td class="num<?= $ca !== null && $cb !== null && abs($ca - $cb) >= 0.5 ? ' kickoff-diff' : '' ?>"><?= $ca === null && $cb === null ? '' : e(kickoff_fmt($cb, 'money', $currency)) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($compare): // a figure that only exists now, like a new asset type
              foreach ($now['figures'] as $label => [$n, $kind]):
                if (array_key_exists($label, $was['figures'])) continue; ?>
            <tr class="kickoff-moved">
              <th scope="row"><?= e($label) ?></th><td class="num">—</td>
              <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($n, $kind, $currency)) ?></td>
              <td class="kickoff-chg">added</td>
              <?php if ($costs !== null):
                  $ca = $costs[0][$label] ?? null; $cb = $costs[1][$label] ?? null; ?>
                <td class="num kickoff-cost"><?= $ca === null && $cb === null ? '' : e(kickoff_fmt($ca, 'money', $currency)) ?></td>
                <td class="num<?= $ca !== null && $cb !== null && abs($ca - $cb) >= 0.5 ? ' kickoff-diff' : '' ?>"><?= $ca === null && $cb === null ? '' : e(kickoff_fmt($cb, 'money', $currency)) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php if ($costs !== null): ?>
          <p class="muted small">Costs are the expense projection's labour, step by step. A step for all assets is
            shared across the types by how many of each there are, and one for shots and assets by count too.
            <?php if (($costs[2] ?? 0) > 0 || ($costs[3] ?? 0) > 0): ?>Project-wide steps belong to no shot or asset and are left out:
            <?= e(kickoff_fmt($costs[2], 'money', $currency)) ?> <?= e(lcfirst(kickoff_labels()[2] ?? 'kick-off')) ?>,
            <?= e(kickoff_fmt($costs[3], 'money', $currency)) ?> <?= e(lcfirst(kickoff_labels()[3] ?? 'now')) ?>.<?php endif; ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php foreach ($was['texts'] as $label => $text):
          $nt = $compare ? (string)($now['texts'][$label] ?? '') : null;
          if (trim($text) === '' && trim((string)$nt) === '') continue; ?>
        <div class="kickoff-text">
          <h4><?= e($label) ?><?php if ($compare && $nt !== $text): ?> <span class="kickoff-chg">changed</span><?php endif; ?></h4>
          <?php if ($compare && $nt !== $text): ?>
            <div class="kickoff-text-pair">
              <div><div class="muted small"><?= e(kickoff_labels()[0]) ?></div><div class="kickoff-prose"><?= e($text !== '' ? $text : '—') ?></div></div>
              <div><div class="muted small"><?= e(kickoff_labels()[1]) ?></div><div class="kickoff-prose"><?= e($nt !== '' ? $nt : '—') ?></div></div>
            </div>
          <?php else: ?>
            <div class="kickoff-prose"><?= e($text) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach ($was['lists'] as $listTitle => $list):
          // David, 2026-11-11: "delete this section for the workflow". The
          // step-to-step links are still saved with each kick-off, just not
          // shown: a re-plan rewires them wholesale and the list was all noise.
          if ($listTitle === 'Workflow') continue;
          $nowList = $compare ? ($now['lists'][$listTitle] ?? ['cols' => $list['cols'], 'rows' => []]) : null;
          // David, 2026-11-11: "instead of the timeline being a table let's do a
          // first bar with the kick off dates and a faded same color for the
          // actual/current below". The Timeline's step list, compared, is
          // drawn as bars; its table folds away underneath for exact dates.
          if ($compare && $listTitle === 'Steps' && isset($list['cols']['start'], $list['cols']['end'])
              && kickoff_render_gantt($list, $nowList)) {
              echo '<details class="kickoff-gantt-table"><summary class="muted small">The same dates as a table</summary>';
              kickoff_render_list($listTitle, $list, $nowList, $currency);
              echo '</details>';
              continue;
          }
          kickoff_render_list($listTitle, $list, $nowList, $currency);
      endforeach; ?>
    </section>
    <?php
}

/**
 * The labour cost behind each Shots and assets figure, for one side.
 *
 * David, 2026-11-11: "new columns here where we can see the kick off cost of
 * things vs the current cost of assets". Money is projected per role per STEP,
 * not per asset, so each step's cost goes to what the step works on. He chose
 * how shared steps split ("Split by count"): a step for all assets is shared
 * across the types by how many assets of each there are, and a step for shots
 * and assets is split by count between the two. Project-wide steps belong to
 * nothing here and are returned apart.
 *
 * $fin is one side's finance snapshot; $figs that side's Shots and assets
 * figures (the counts). A row saved before 2026-11-11 holds no 'applies', so
 * its step is looked up as it is today ($stepsNow, id => step); a deleted one
 * cannot be placed and is skipped.
 * Returns [label => cost, project-wide total].
 */
function kickoff_shot_costs(array $fin, array $figs, array $stepsNow): array {
    $shots = (float)($figs['Shots'][0] ?? 0);
    $types = [];
    foreach ($figs as $label => [$n]) {
        if (strpos((string)$label, 'Assets: ') === 0) $types[substr((string)$label, 8)] = (float)$n;
    }
    $assets = array_sum($types);
    $out = []; $project = 0.0; $any = false;
    $add = static function (string $label, float $c) use (&$out) { $out[$label] = ($out[$label] ?? 0.0) + $c; };
    $toAssets = static function (float $c, string $type) use (&$add, $types, $assets) {
        $add('Assets', $c);
        if ($type !== '') { $add('Assets: ' . $type, $c); return; }
        if ($assets <= 0) return;   // no assets to share it across: on the total only
        foreach ($types as $t => $n) if ($n > 0) $add('Assets: ' . $t, $c * $n / $assets);
    };
    foreach ($fin['lists']['Expense projection']['rows'] ?? [] as $r) {
        if (!isset($r['cost']) || $r['cost'] === null) continue;
        $c = (float)$r['cost'];
        $sid = (int)($r['step_id'] ?? 0);
        if (isset($r['applies'])) {
            $ap = (string)$r['applies']; $ty = trim((string)($r['asset_type'] ?? ''));
        } elseif (isset($stepsNow[$sid])) {
            $ap = (string)$stepsNow[$sid]['applies_to']; $ty = trim((string)($stepsNow[$sid]['applies_to_asset_type'] ?? ''));
        } else {
            continue;
        }
        $any = true;
        if ($ap === 'shot') {
            $add('Shots', $c);
        } elseif ($ap === 'asset') {
            $toAssets($c, $ty);
        } elseif ($ap === 'both') {
            $all = $shots + ($ty !== '' ? ($types[$ty] ?? 0) : $assets);
            if ($all <= 0) { $project += $c; continue; }
            $add('Shots', $c * $shots / $all);
            $toAssets($c * ($all - $shots) / $all, $ty);
        } else {
            $project += $c;
        }
    }
    return [$any ? $out : [], $project];
}

/**
 * The Timeline's steps as bars: per step, the kick-off dates as a solid bar in
 * the step's colour and now as a faded bar of the same colour just below, on
 * one shared calendar. Returns false (and draws nothing) when no step has a
 * date on either side, so the caller falls back to the table.
 */
function kickoff_render_gantt(array $was, array $now): bool {
    $wr = $was['rows'] ?? []; $nr = $now['rows'] ?? [];
    $day = static function ($d): ?int {
        if (!$d) return null;
        $t = strtotime((string)$d);
        return $t === false ? null : (int)floor($t / 86400);
    };
    $span = static function (?array $r) use ($day): ?array {
        if (!$r) return null;
        $a = $day($r['start'] ?? null); $b = $day($r['end'] ?? null);
        if ($a === null && $b === null) return null;
        $a = $a ?? $b; $b = $b ?? $a;
        return [min($a, $b), max($a, $b), (string)($r['start'] ?? ''), (string)($r['end'] ?? '')];
    };
    $rows = []; $min = null; $max = null; $i = 0;
    $keys = array_unique(array_merge(array_map('strval', array_keys($wr)), array_map('strval', array_keys($nr))));
    foreach ($keys as $key) {
        $w = $span($wr[$key] ?? null); $n = $span($nr[$key] ?? null);
        if (!$w && !$n) continue;
        foreach ([$w, $n] as $x) {
            if (!$x) continue;
            $min = $min === null ? $x[0] : min($min, $x[0]);
            $max = $max === null ? $x[1] : max($max, $x[1]);
        }
        // A step colour is data (a hex picked per step); anything else falls
        // back to the default step colour rather than reaching the style.
        $c = (string)($nr[$key]['color'] ?? $wr[$key]['color'] ?? '');
        if (!preg_match('/^#[0-9a-f]{3,8}$/i', $c)) $c = 'var(--step-default)';
        $how = !isset($wr[$key]) ? 'added' : (!isset($nr[$key]) ? 'removed' : '');
        $rows[] = [$key, $w, $n, $c, $how, ($w ?? $n)[0], $i++];
    }
    if (!$rows) return false;
    // Earliest kick-off start first (a new step by its start now), so the bars
    // fall like a cascade; ties keep the pipeline order.
    usort($rows, fn($x, $y) => [$x[5], $x[6]] <=> [$y[5], $y[6]]);
    $total = max(1, $max - $min + 1);
    $pos = static fn(array $x): string => sprintf('left:%.3f%%;width:%.3f%%',
        ($x[0] - $min) / $total * 100, max(0.6, ($x[1] - $x[0] + 1) / $total * 100));
    $fmt = static function (array $x): string {
        if ($x[2] !== '' && $x[3] !== '') return kickoff_fmt($x[2], 'date') . ' – ' . kickoff_fmt($x[3], 'date');
        return kickoff_fmt($x[2] !== '' ? $x[2] : $x[3], 'date');
    };
    $L = kickoff_labels();
    // A tick at the first of each month inside the range.
    $ticks = [];
    $m = strtotime(gmdate('Y-m-01', $min * 86400) . ' UTC');
    while ($m !== false && ($d = (int)floor($m / 86400)) <= $max) {
        if ($d >= $min) $ticks[] = [($d - $min) / $total * 100, gmdate('M Y', $m)];
        $m = strtotime('+1 month', $m);
    }
    $today = $day(date('Y-m-d'));
    ?>
    <div class="kickoff-gantt">
      <div class="kickoff-gantt-key muted small">
        <span><i class="kickoff-gantt-swatch"></i><?= e($L[0]) ?></span>
        <span><i class="kickoff-gantt-swatch is-now"></i><?= e($L[1]) ?></span>
      </div>
      <div class="kickoff-gantt-row kickoff-gantt-head">
        <div></div>
        <div class="kickoff-gantt-track">
          <?php foreach ($ticks as [$p, $txt]): ?><span class="kickoff-gantt-tick" style="left:<?= sprintf('%.3f', $p) ?>%"><?= e($txt) ?></span><?php endforeach; ?>
        </div>
      </div>
      <?php foreach ($rows as [$key, $w, $n, $c, $how]): ?>
        <div class="kickoff-gantt-row">
          <div class="kickoff-gantt-name" title="<?= e($key) ?>"><?= e($key) ?><?php if ($how): ?> <span class="kickoff-chg"><?= e($how) ?></span><?php endif; ?></div>
          <div class="kickoff-gantt-track" style="--bar:<?= e($c) ?>">
            <?php foreach ($ticks as [$p]): ?><span class="kickoff-gantt-grid" style="left:<?= sprintf('%.3f', $p) ?>%"></span><?php endforeach; ?>
            <?php if ($today !== null && $today >= $min && $today <= $max): ?><span class="kickoff-gantt-today" style="left:<?= sprintf('%.3f', ($today - $min) / $total * 100) ?>%" title="Today"></span><?php endif; ?>
            <?php if ($w): ?><span class="kickoff-gantt-bar" style="<?= $pos($w) ?>" title="<?= e($key . ', ' . $L[0] . ': ' . $fmt($w)) ?>"></span><?php endif; ?>
            <?php if ($n): ?><span class="kickoff-gantt-bar is-now" style="<?= $pos($n) ?>" title="<?= e($key . ', ' . $L[1] . ': ' . $fmt($n)) ?>"></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return true;
}

/** One list. Compared, the rows that moved come first and the rest fold away. */
function kickoff_render_list(string $title, array $was, ?array $now, string $currency): void {
    $cols = $was['cols'];
    if ($now === null) {
        if (!$was['rows']) return;
        ?>
        <details class="kickoff-list">
          <summary><?= e($title) ?> <span class="muted">(<?= count($was['rows']) ?>)</span></summary>
          <table class="data">
            <thead><tr><th></th><?php foreach ($cols as [$l, $hk]): ?><th class="<?= $hk === 'text' ? '' : 'num' ?>"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($was['rows'] as $key => $row): ?>
              <tr><th scope="row"><?= e((string)$key) ?></th>
                <?php foreach ($cols as $c => [, $kind]): ?>
                  <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($row[$c] ?? null, $kind, $currency)) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </details>
        <?php
        return;
    }

    $moved = []; $same = [];
    foreach ($was['rows'] as $key => $row) {
        $key = (string)$key;
        if (!array_key_exists($key, $now['rows'])) { $moved[] = [$key, 'removed', $row, null]; continue; }
        $nrow = $now['rows'][$key];
        $diff = false;
        foreach ($cols as $c => [, $kind]) {
            if (kickoff_change($row[$c] ?? null, $nrow[$c] ?? null, $kind, $currency) !== '') { $diff = true; break; }
        }
        if ($diff) $moved[] = [$key, 'changed', $row, $nrow]; else $same[] = [$key, '', $row, $nrow];
    }
    foreach ($now['rows'] as $key => $nrow) {
        if (!array_key_exists((string)$key, $was['rows'])) $moved[] = [(string)$key, 'added', null, $nrow];
    }
    if (!$moved && !$same) return;
    ?>
    <div class="kickoff-list">
      <h4><?= e($title) ?>
        <span class="muted small"><?= count($moved) ? count($moved) . ' changed, added or removed' : 'nothing changed' ?>
          · <?= count($was['rows']) ?> <?= e(lcfirst(kickoff_labels()[0])) ?>, <?= count($now['rows']) ?> <?= e(lcfirst(kickoff_labels()[1])) ?></span></h4>
      <?php if ($moved): ?>
        <?php
        // David, 2026-11-11: "instead of starts and ends change it to Kick off
        // and Now". A cell used to read "2 Sep → 26 Oct", which looked like a
        // step's start and end. Each column is now a pair, kick-off and now
        // side by side under it, one date per cell.
        $L = kickoff_labels(); $short0 = $L[2] ?? $L[0]; $short1 = $L[3] ?? $L[1]; ?>
        <table class="data kickoff-cmp">
          <thead>
            <tr><th rowspan="2"></th><th rowspan="2"></th>
              <?php foreach ($cols as [$l]): ?><th colspan="2" class="kickoff-grp"><?= e($l) ?></th><?php endforeach; ?></tr>
            <tr><?php foreach ($cols as [, $hk]): $nc = $hk === 'text' ? '' : 'num'; ?>
              <th class="<?= $nc ?> kickoff-was"><?= e($short0) ?></th><th class="<?= $nc ?>"><?= e($short1) ?></th>
            <?php endforeach; ?></tr>
          </thead>
          <tbody>
          <?php foreach ($moved as [$key, $how, $row, $nrow]): ?>
            <tr class="kickoff-row-<?= e($how) ?>">
              <th scope="row"><?= e($key) ?></th><td class="kickoff-chg"><?= e($how) ?></td>
              <?php foreach ($cols as $c => [, $kind]):
                  $a = $how === 'added' ? null : ($row[$c] ?? null);
                  $b = $how === 'removed' ? null : ($nrow[$c] ?? null);
                  $nc = $kind === 'text' ? '' : 'num';
                  // In a changed row, a figure that held still is muted so the
                  // one that moved stands out.
                  $tone = $how !== 'changed' ? '' : (kickoff_change($a, $b, $kind, $currency) !== '' ? ' kickoff-diff' : ' muted'); ?>
                <td class="<?= $nc ?> kickoff-was<?= $how === 'added' ? ' muted' : '' ?>"><?= $how === 'added' ? '—' : e(kickoff_fmt($a, $kind, $currency)) ?></td>
                <td class="<?= $nc ?><?= $tone ?><?= $how === 'removed' ? ' muted' : '' ?>"><?= $how === 'removed' ? '—' : e(kickoff_fmt($b, $kind, $currency)) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($same): ?>
        <details>
          <summary class="muted small"><?= count($same) ?> unchanged</summary>
          <table class="data">
            <tbody>
            <?php foreach ($same as [$key, , $row]): ?>
              <tr><th scope="row"><?= e($key) ?></th>
                <?php foreach ($cols as $c => [, $kind]): ?>
                  <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($row[$c] ?? null, $kind, $currency)) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    </div>
    <?php
}
