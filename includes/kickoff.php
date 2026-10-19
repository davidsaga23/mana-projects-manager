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
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'mana.davidsaga.com';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return "{$scheme}://{$host}{$dir}/kickoff.php?project={$projectId}";
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

/** The order the sections are shown in, as David listed them. */
const KICKOFF_SECTIONS = [
    'brief'    => 'Brief',
    'staffing' => 'Staffing',
    'schedule' => 'Schedule',
    'timeline' => 'Timeline',
    'shots'    => 'Shots',
    'setup'    => 'Setup',
    'finance'  => 'Finance management',
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
            'Tasks with nobody on them' => [$nobody, 'num'],
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
        $projRows[kickoff_unique_key($projRows, $key)] = [
            'rate' => $r['rate'], 'unit' => (string)($r['rate_unit'] ?? ''),
            'days' => round((float)$r['days'], 2), 'cost' => $r['cost'],
        ];
    }
    $otherRows = [];
    foreach ($proj['other'] ?? [] as $x) {
        $key = trim(($x['category'] ?? '') . ': ' . ($x['description'] ?? ''), ': ');
        $otherRows[kickoff_unique_key($otherRows, $key ?: 'Expense')] = [
            'amount' => (float)$x['amount'], 'status' => (string)($x['status'] ?? ''),
        ];
    }
    $invRows = []; $invoiced = 0.0;
    foreach (project_invoices($pid) as $i) {
        $invoiced += (float)$i['amount'];
        $key = trim((string)($i['invoice_ref'] ?? '')) ?: ('Invoice ' . (int)$i['id']);
        $invRows[kickoff_unique_key($invRows, $key)] = [
            'person' => (string)($i['crew_name'] ?? ''), 'amount' => (float)$i['amount'],
            'status' => (string)$i['status'],
        ];
    }
    $pool = budget_pool($pdo, $project);
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
            'Revenue (billed to the client)' => [isset($budget['revenue_amount']) ? (float)$budget['revenue_amount'] : null, 'money'],
            'Approved production budget'     => [isset($budget['budget_amount']) ? (float)$budget['budget_amount'] : null, 'money'],
            'Projected labour cost'          => [(float)$proj['total'], 'money'],
            'Other expenses'                 => [(float)$proj['other_total'], 'money'],
            'Projected total'                => [(float)$proj['grand_total'], 'money'],
            'Invoiced so far'                => [$invoiced, 'money'],
            'Budget pool, days'              => [round((float)$pool['days']['pool'], 2), 'num'],
            'Budget pool, days used'         => [round((float)$pool['days']['used'], 2), 'num'],
        ],
        'texts' => [],
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
    return $raw ? (json_decode((string)$raw, true) ?: null) : null;
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
    if ($project['status'] !== 'setup') {
        throw new RuntimeException('Only a project in setup is sent for sign-off.');
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

    $snap = kickoff_snapshot($pdo, $project);
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
    foreach ($signers as $s) {
        [$ok, $res] = kickoff_mail(
            ['email' => $s['email'], 'name' => $s['name']],
            sprintf('[%s] Kick-off sign-off: %s', $project['code'], $project['name']),
            kickoff_mail_html('<strong>' . htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8')
                . '</strong> is ready to go from setup to active, and needs your sign-off. '
                . 'Please look at how it is set up and approve it, or ask for changes.',
                $lines, $url, 'Review and sign off'),
            "{$project['name']} is ready to go from setup to active, and needs your sign-off.\n\n"
            . implode("\n", $lines) . "\n\nReview and sign off: {$url}\n"
        );
        $upd->execute([mb_substr($res, 0, 255), $kid, (int)$s['id']]);
        $msgs[] = $s['name'] . ': ' . $res . '.';
    }
    return $msgs;
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
        $msgs[] = 'Sent back for changes. The project stays in setup.';
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
    try {
        $n = capture_baseline($pdo, (int)$project['id'], 'kick-off sign-off (override)');
        if ($n > 0) $msgs[] = "Recorded the starting estimate for $n step" . ($n === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        $msgs[] = 'The starting estimate per step could not be recorded: ' . $e->getMessage();
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
function kickoff_render_section(string $title, array $was, ?array $now, string $currency = 'USD'): void {
    $compare = $now !== null;
    ?>
    <section class="kickoff-section">
      <h3><?= e($title) ?></h3>
      <?php if ($was['figures']): ?>
        <table class="data kickoff-figures">
          <?php if ($compare): ?>
            <thead><tr><th></th><th>At kick-off</th><th>Now</th><th>Change</th></tr></thead>
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
            </tr>
          <?php endforeach; ?>
          <?php if ($compare): // a figure that only exists now, like a new asset type
              foreach ($now['figures'] as $label => [$n, $kind]):
                if (array_key_exists($label, $was['figures'])) continue; ?>
            <tr class="kickoff-moved">
              <th scope="row"><?= e($label) ?></th><td class="num">—</td>
              <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?= e(kickoff_fmt($n, $kind, $currency)) ?></td>
              <td class="kickoff-chg">added</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php foreach ($was['texts'] as $label => $text):
          $nt = $compare ? (string)($now['texts'][$label] ?? '') : null;
          if (trim($text) === '' && trim((string)$nt) === '') continue; ?>
        <div class="kickoff-text">
          <h4><?= e($label) ?><?php if ($compare && $nt !== $text): ?> <span class="kickoff-chg">changed</span><?php endif; ?></h4>
          <?php if ($compare && $nt !== $text): ?>
            <div class="kickoff-text-pair">
              <div><div class="muted small">At kick-off</div><div class="kickoff-prose"><?= e($text !== '' ? $text : '—') ?></div></div>
              <div><div class="muted small">Now</div><div class="kickoff-prose"><?= e($nt !== '' ? $nt : '—') ?></div></div>
            </div>
          <?php else: ?>
            <div class="kickoff-prose"><?= e($text) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach ($was['lists'] as $listTitle => $list):
          kickoff_render_list($listTitle, $list, $compare ? ($now['lists'][$listTitle] ?? ['cols' => $list['cols'], 'rows' => []]) : null, $currency);
      endforeach; ?>
    </section>
    <?php
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
            <thead><tr><th></th><?php foreach ($cols as [$l]): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead>
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
          · <?= count($was['rows']) ?> at kick-off, <?= count($now['rows']) ?> now</span></h4>
      <?php if ($moved): ?>
        <table class="data">
          <thead><tr><th></th><th></th><?php foreach ($cols as [$l]): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($moved as [$key, $how, $row, $nrow]): ?>
            <tr class="kickoff-row-<?= e($how) ?>">
              <th scope="row"><?= e($key) ?></th><td class="kickoff-chg"><?= e($how) ?></td>
              <?php foreach ($cols as $c => [, $kind]):
                  $a = $row[$c] ?? null; $b = $nrow[$c] ?? null; ?>
                <td class="<?= $kind === 'text' ? '' : 'num' ?>"><?php
                  if ($how === 'changed' && kickoff_change($a, $b, $kind, $currency) !== ''): ?><span class="muted"><?= e(kickoff_fmt($a, $kind, $currency)) ?> →</span> <?= e(kickoff_fmt($b, $kind, $currency)) ?><?php
                  else: ?><?= e(kickoff_fmt($how === 'added' ? $b : $a, $kind, $currency)) ?><?php endif; ?></td>
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
