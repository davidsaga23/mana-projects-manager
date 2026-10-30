<?php
// THE BOARD — every shot and asset of a show, with its pipeline steps under it.
//
// David, 2026-10-22, on moving the studio off Plaky: "we need a new screen that
// looks like Plaky or Monday where we and the artists can see everything",
// reached from "a big button at the top ... so you don't have one per project
// and just one place to take a look at the whole thing".
//
// ⚠ THIS SCREEN INVENTS NO DATA. Every row it draws already existed:
//
//   Plaky group     an episode of shots, an asset type, or a department of
//                   whole-show deliverables
//   Plaky item      a `shots` or `assets` row — or, for a whole-show step, the
//                   step itself, which is why those groups have no sub-rows
//   Plaky subitem   a `progress` row, one per step per item, which this schema
//                   has had since v1 (UNIQUE KEY uniq_step_item)
//   Status          the project's own `statuses`, with its colour and its
//                   maps_to meaning — never a second vocabulary
//   Assignee        progress.crew_id (progress_crew holds a second name)
//   Due date        schedule.end_date for that (step, item) — THE SAME DATE THE
//                   TIMELINE DRAWS, by David's choice ("let's use the built in
//                   one"), so the two can never disagree
//   Total frames    shots.frames, from the breakdown, shown and never edited
//   Link            progress.link (migration 057)
//
// So the board is a VIEW, and the day Plaky is switched off nothing migrates:
// the importer simply stops running and people set these statuses by hand. That
// was the whole point of the 2026-08-11 status-model decision.
//
// ⚠ A SHOT'S OWN STATUS IS DERIVED, never stored. Plaky makes somebody keep a
// parent row's status honest by hand; here it is read from the tasks underneath
// (board_item_status()), so it cannot drift from them and there is one less
// thing for a freelancer to get wrong.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/studio.php';      // studio_exclude_sql(): nobody works on the Studio
require_once __DIR__ . '/grid.php';        // step_covers_item(), the one scope rule
// ⚠ THE COMMENTS ON A BOARD TASK ARE TICKET COMMENTS (migration 059). Not a
// copy of them — the same rows, the same mentions table, the same threading.
// David, 2026-10-22: "those comments are the ones that currently live under my
// tasks, they're the exact same just displayed in this context too." So this
// file borrows ticket_parse_mentions(), ticket_render_body() and
// ticket_thread() rather than growing its own, and a mention typed on the board
// lands in the same My tasks list as one typed on a ticket.
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/notify.php';     // the bell beside your name (migration 060)
require_once __DIR__ . '/uploads.php';    // version_label(), attachment_is_image() for the Version column
require_once __DIR__ . '/task_events.php'; // the task's Timeline: assignee, due, note and link changes (066)
require_once __DIR__ . '/naming.php';      // the show's file naming convention, checked on upload (067)

/* ---------------------------------------------------------------------------
 * The three phases, named as the studio names them.
 *
 * David, 2026-10-22: "these names should be the departments Anima already uses
 * like Pre-Production, production and post-production." They are the same three
 * the tracking grid has grouped by since 2026-08-14 and the same subtotal
 * boundaries the expense projection uses, so a board, a grid and a budget all
 * read the show the same way round.
 *
 * ⚠ AN ITEM TAKES A PHASE, NOT A STEP'S DEPARTMENT. A shot passes through eight
 * departments; it cannot belong to one. So the split is by what the item IS —
 * assets are pre-production, shots are production — and only a whole-show step,
 * which really is one piece of work, takes the department it names.
 * ------------------------------------------------------------------------ */

/** A department as it should read, whatever case somebody typed it in. */
function board_dept_name(string $dept): string {
    $known = ['pre-production' => 'Pre-production', 'preproduction' => 'Pre-production',
              'pre production' => 'Pre-production', 'production' => 'Production',
              'post-production' => 'Post-production', 'postproduction' => 'Post-production',
              'post production' => 'Post-production', 'above the line' => 'Above the line'];
    return $known[strtolower(trim($dept))] ?? $dept;
}

/** Where a department sits in the order a show is read in. */
function board_dept_rank(string $dept): int {
    $order = ['Above the line' => 0, 'Pre-production' => 1, 'Production' => 2, 'Post-production' => 3];
    return $order[board_dept_name($dept)] ?? 9;
}

/** A department's colour, so the dots mean the same thing on every group. */
function board_dept_color(string $dept): string {
    $by = ['Above the line' => 'var(--chip-teal-ink)', 'Pre-production' => 'var(--primary)',
           'Production' => 'var(--violet)', 'Post-production' => 'var(--warn)'];
    return $by[board_dept_name($dept)] ?? 'var(--step-default)';
}

/**
 * A status's colour at low opacity, for the pill it sits in.
 *
 * ⚠ THE ONE PLACE A COLOUR IS COMPUTED RATHER THAN NAMED, and only because the
 * value is DATA: a status carries its own hex, chosen per project on the
 * Statuses screen, so no `:root` role could name it (lesson 21 is about colours
 * written into RULES, which this is not). Everything structural on the board
 * still comes from a token.
 *
 * A soft fill with the colour as the ink reads far better than twenty solid
 * blocks down a column, and it keeps the status legible on the dark ground.
 */
function board_tint(string $hex, float $alpha = 0.15): string {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return 'var(--surface-3)';
    return sprintf('rgba(%d, %d, %d, %.2f)',
        hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
}

/**
 * The shows the board offers, newest work first.
 *
 * Setup, active and on hold — the same three Bookings reads, and for the same
 * reason: a wrapped or archived show is over, and a board of it is a museum.
 * Scoped to what this account may see, so a supervisor or artist is offered
 * only the shows they are staffed on.
 */
function board_projects(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id, code, name, status FROM projects
          WHERE status IN ('setup', 'active', 'on_hold')"
        . studio_exclude_sql($pdo, '')
        . " ORDER BY FIELD(status, 'active', 'setup', 'on_hold'), name"
    )->fetchAll();

    // visible_project_ids() is the per-user scope added with PR #26; it returns
    // null when this account sees everything (admins and Production).
    $allowed = function_exists('visible_project_ids') ? visible_project_ids($pdo) : null;
    if ($allowed === null) return $rows;

    $ok = array_flip(array_map('intval', $allowed));
    return array_values(array_filter($rows, fn($p) => isset($ok[(int)$p['id']])));
}

/**
 * What a shot or asset's own status reads, worked out from its tasks.
 *
 * Deliberately the same three answers the cards elsewhere give, and in this
 * order: everything applicable finished, something under way, nothing started.
 * N/A rows leave the reckoning entirely — an item whose only remaining step is
 * marked not-applicable is finished, not stuck.
 *
 * @param array $tasks rows from board_data()
 */
function board_item_status(array $tasks): array {
    $live = array_values(array_filter($tasks, fn($t) => !$t['is_excluded']));
    if (!$live) return ['name' => 'Nothing to do', 'color' => 'var(--faint)'];

    $approved = array_filter($live, fn($t) => $t['is_approved']);
    if (count($approved) === count($live)) {
        return ['name' => 'Finished', 'color' => 'var(--ok)'];
    }
    // ⚠ "STARTED" IS A FACT ON THE STATUS, not a guess from maps_to. David's
    // own rule: in progress as soon as ANY task says something other than
    // Waiting to start or Ready to start. See status_has_begun(); the first
    // version of this asked maps_to, which calls In progress and Retake
    // untouched, so a shot read Not started while three people worked on it.
    $started = array_filter($live, fn($t) => $t['begun']);
    if ($started) return ['name' => 'In progress', 'color' => 'var(--warn)'];

    return ['name' => 'Not started', 'color' => 'var(--violet)'];
}

/**
 * How far along one shot or asset is, as a stacked bar of its tasks' own
 * status colours (David, 2026-10-22: "the more complete/approved the more the
 * bar is going to be filled with that color").
 *
 * ⚠ IT FILLS FROM THE LEFT, most finished first, so it reads as a progress bar
 * rather than as a chart somebody has to decode: Final, then delivered, then
 * blocked, then merely started, then nothing yet. The order is the STRUCTURE of
 * each status (`maps_to`, `is_blocked`), never its name — every show spells
 * these its own way, which is the whole reason `statuses` is per project.
 *
 * N/A leaves the bar entirely: a step this item does not pass through is not
 * work waiting to be done, so counting it would make the shot look unfinished
 * for ever.
 *
 * @return array{segments: array, done: int, total: int}
 */
function board_item_bar(array $tasks, array $statusMap): array {
    $live = array_values(array_filter($tasks, fn($t) => !$t['is_excluded']));
    $total = count($live);
    if ($total === 0) return ['segments' => [], 'done' => 0, 'total' => 0];

    // Gather by status, keeping "nothing recorded" as its own bucket.
    $buckets = [];
    foreach ($live as $t) {
        $key = $t['status_id'] === null ? 'none' : (string)$t['status_id'];
        if (!isset($buckets[$key])) {
            $s = $t['status_id'] !== null ? ($statusMap[$t['status_id']] ?? null) : null;
            $buckets[$key] = [
                'name'  => $s['name'] ?? 'Not started',
                'color' => $s ? ($s['color'] ?: 'var(--border-strong)') : 'var(--border-strong)',
                'n'     => 0,
                'rank'  => board_status_rank($s),
                'order' => $s ? (int)($s['sort_order'] ?? 0) : 9999,
            ];
        }
        $buckets[$key]['n']++;
    }

    $segments = array_values($buckets);
    usort($segments, fn($a, $b) => [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']]);

    $done = 0;
    foreach ($live as $t) if ($t['is_approved']) $done++;

    foreach ($segments as &$seg) $seg['pct'] = $seg['n'] / $total * 100;
    unset($seg);

    return ['segments' => $segments, 'done' => $done, 'total' => $total];
}

/**
 * One progress bar, with the panel that answers "how many of each colour?"
 * (David, 2026-10-22).
 *
 * ⚠ A SEGMENT'S OWN TOOLTIP IS NOT ENOUGH. These bars are 7px tall, and a
 * slice holding one task of eight is a few pixels wide — hovering it exactly is
 * a game, and the native tooltip waits a second before saying anything. So the
 * whole bar carries one panel listing every status in it with its count, shown
 * on hover and on keyboard focus, and the bar is focusable so it is reachable
 * without a mouse. The per-segment titles stay for touch, where nothing hovers.
 */
function board_bar_html(array $bar, string $extraClass = ''): string {
    if (($bar['total'] ?? 0) <= 0) return '';
    $sum = $bar['done'] . ' of ' . $bar['total'] . ' tasks finished';

    $track = '';
    $keys  = '';
    foreach ($bar['segments'] as $seg) {
        $track .= '<span class="board-bar-seg" style="width:' . e(fmt_num($seg['pct'], 3))
                . '%;background:' . e($seg['color']) . '" title="' . e($seg['name'] . ', '
                . $seg['n'] . ' of ' . $bar['total']) . '"></span>';
        $keys  .= '<span class="board-bar-key"><i style="background:' . e($seg['color'])
                . '"></i>' . e($seg['name']) . '<b>' . (int)$seg['n'] . '</b></span>';
    }

    return '<span class="board-bar ' . e($extraClass) . '" tabindex="0" role="img"'
         . ' aria-label="' . e($sum) . '">'
         . '<span class="board-bar-track">' . $track . '</span>'
         . '<span class="board-bar-pop">' . $keys
         . '<span class="board-bar-sum">' . e($sum) . '</span></span>'
         . '</span>';
}

/**
 * How far along a status is, 0 being finished. Read from what it MEANS, so a
 * show that calls its finished state "Delivered" or "Aprobado" still sorts
 * right. assets/board-screen.js mirrors this, from the same facts carried on
 * each option — the two must move together.
 */
function board_status_rank(?array $status): int {
    if (!$status) return 4;                                     // nothing recorded
    if (($status['maps_to'] ?? '') === 'approved') return 0;
    if (($status['maps_to'] ?? '') === 'delivered') return 1;
    if ((int)($status['is_blocked'] ?? 0) === 1) return 2;      // stuck, but real work
    return 3;
}

/**
 * Everything the board draws for one show: groups, their items, and each
 * item's tasks.
 *
 * ⚠ SIX QUERIES, WHATEVER THE SIZE OF THE SHOW. Everything is read in bulk and
 * matched up in PHP, because the obvious shape — a query per item, or per cell —
 * is 1,500 round trips on a show the size of STFC. The tracking grid learned
 * this first; this follows it.
 *
 * @return array ['groups' => [...], 'statuses' => [...], 'counts' => [...]]
 */
function board_data(PDO $pdo, int $projectId): array {
    $steps = pipeline_steps($pdo, $projectId);
    $statusMap = statuses_by_id($pdo, $projectId);
    $hasLink = column_exists($pdo, 'progress', 'link');

    $shots = $pdo->prepare(
        'SELECT id, episode, shot_code, frames, placeholder_seconds, notes
           FROM shots WHERE project_id = ? ORDER BY episode, shot_code'
    );
    $shots->execute([$projectId]);
    $shots = $shots->fetchAll();

    $assets = $pdo->prepare(
        "SELECT id, name, COALESCE(asset_type, '') AS asset_type, notes
           FROM assets WHERE project_id = ? ORDER BY asset_type, name"
    );
    $assets->execute([$projectId]);
    $assets = $assets->fetchAll();

    // The cells. LEFT JOIN on crew because an unassigned task is the normal
    // case, not a missing row — 76 of MSD's 147 have nobody on them.
    $cells = $pdo->prepare(
        'SELECT pr.id, pr.step_id, pr.item_key, pr.status_id, pr.version, pr.notes'
        . ($hasLink ? ', pr.link' : '') . ',
                pr.crew_id, pr.is_delivered, pr.is_approved, c.name AS person
           FROM progress pr
           LEFT JOIN crew c ON c.id = pr.crew_id
          WHERE pr.project_id = ?'
    );
    $cells->execute([$projectId]);
    $byCell = [];
    foreach ($cells as $r) $byCell[$r['step_id'] . '|' . $r['item_key']] = $r;

    // The dates, from the schedule the timeline draws.
    $dates = $pdo->prepare(
        'SELECT step_id, item_key, end_date, is_manual FROM schedule WHERE project_id = ?'
    );
    $dates->execute([$projectId]);
    $byDate = [];
    foreach ($dates as $r) $byDate[$r['step_id'] . '|' . $r['item_key']] = $r;

    // The latest version of every task, and how many comments each has — the
    // Version and Comments columns (David, 2026-10-22). One query each for the
    // whole show, keyed by progress id, for the reason this function gives at
    // the top. Guarded: before migrations 063 / 059 the columns read a dash.
    $latestVersion = [];
    if (board_versions_available($pdo)) {
        $q = $pdo->prepare(
            'SELECT v.progress_id, v.id, v.version_no, v.mime_type, v.original_name
               FROM progress_versions v
               JOIN (SELECT pv.progress_id, MAX(pv.version_no) AS top
                       FROM progress_versions pv
                       JOIN progress p ON p.id = pv.progress_id
                      WHERE p.project_id = ?
                      GROUP BY pv.progress_id) m
                 ON m.progress_id = v.progress_id AND m.top = v.version_no'
        );
        $q->execute([$projectId]);
        foreach ($q as $r) $latestVersion[(int)$r['progress_id']] = $r;
    }
    // How many review notes each VERSION has (migration 065). The Notes column
    // (David, 2026-10-22: "adjust the comments to be Notes instead") counts the
    // ones on the task's current version and leads to them.
    $reviewCount = [];
    if (board_comments_available($pdo) && board_reviews_available($pdo)) {
        $q = $pdo->prepare(
            'SELECT c.version_id, COUNT(*) AS n
               FROM ticket_comments c
               JOIN progress p ON p.id = c.progress_id
              WHERE p.project_id = ? AND c.version_id IS NOT NULL
              GROUP BY c.version_id'
        );
        $q->execute([$projectId]);
        foreach ($q as $r) $reviewCount[(int)$r['version_id']] = (int)$r['n'];
    }

    $today = date('Y-m-d');

    /** One task row: a step's work on one item. */
    $task = function (array $step, string $itemKey) use ($byCell, $byDate, $statusMap, $today, $hasLink,
                                                         $latestVersion, $reviewCount): array {
        $key = (int)$step['id'] . '|' . $itemKey;
        $cell = $byCell[$key] ?? null;
        $sched = $byDate[$key] ?? null;
        $statusId = $cell && $cell['status_id'] !== null ? (int)$cell['status_id'] : null;
        $status = $statusId !== null ? ($statusMap[$statusId] ?? null) : null;
        $due = $sched['end_date'] ?? null;

        return [
            'step_id'     => (int)$step['id'],
            'step'        => (string)$step['name'],
            'step_color'  => $step['color'] ?: '',
            'item_key'    => $itemKey,
            'status_id'   => $status ? $statusId : null,
            'status_name' => $status['name'] ?? null,
            'status_color' => $status ? ($status['color'] ?: '') : '',
            'is_approved' => $cell ? (int)$cell['is_approved'] === 1 : false,
            'is_excluded' => $status ? (int)($status['is_excluded'] ?? 0) === 1 : false,
            // Has anybody picked this up? Read from the status itself, so a
            // show that spells its states its own way still reads right.
            'begun'       => status_has_begun($status),
            'crew_id'     => $cell && $cell['crew_id'] ? (int)$cell['crew_id'] : null,
            'person'      => $cell['person'] ?? null,
            'due'         => $due,
            'due_late'    => $due !== null && $due < $today
                             && !($cell && (int)$cell['is_approved'] === 1),
            'manual'      => $sched ? (int)$sched['is_manual'] === 1 : false,
            'version'     => $cell['version'] ?? null,
            'latest'      => $cell ? ($latestVersion[(int)$cell['id']] ?? null) : null,
            'review_notes' => ($cell && isset($latestVersion[(int)$cell['id']]))
                              ? ($reviewCount[(int)$latestVersion[(int)$cell['id']]['id']] ?? 0) : 0,
            'notes'       => $cell['notes'] ?? null,
            'link'        => $hasLink ? ($cell['link'] ?? null) : null,
            // Overwritten by $tasksFor() for a shot or an asset, where the
            // steps before this one are what decide it. A whole-show
            // deliverable has nothing upstream, so this is its answer.
            'ready'       => !status_has_begun($status)
                             && !($cell && (int)$cell['is_approved'] === 1)
                             && !($status && (int)($status['is_excluded'] ?? 0) === 1),
        ];
    };

    /**
     * The tasks covering one shot or asset, in pipeline order.
     *
     * ⚠ READINESS IS WORKED OUT HERE AND NOWHERE ELSE, in this one left-to-right
     * pass, because it is the only place that sees a shot's steps IN ORDER.
     * David asked for it when the board was being designed: "it's still useful
     * if I'm a supervisor taking a look at what shots/assets are ready for my
     * department".
     *
     * A task is ready to pick up when nothing before it is outstanding and
     * nobody has started it. Three details carry the meaning:
     *
     *   APPROVED, NOT DELIVERED, clears the way. Delivered-not-approved is work
     *   sitting in review — the next department starting on it is exactly the
     *   rework this is meant to prevent.
     *
     *   AN EXCLUDED STEP IS NOT WORK, so it blocks nothing. "This shot has no
     *   FX" must not leave Comp waiting forever, which is the same rule the
     *   grid and the scheduler already keep.
     *
     *   THE FIRST STEP IS READY BY DEFAULT: there is nothing upstream of it.
     */
    $tasksFor = function (array $itemRow, string $scope, string $itemKey) use ($steps, $task): array {
        $out = [];
        $clear = true;
        foreach ($steps as $step) {
            if (!step_covers_item($step, $itemRow, $scope)) continue;
            $t = $task($step, $itemKey);
            $t['ready'] = $clear && !$t['begun'] && !$t['is_approved'] && !$t['is_excluded'];
            if (!$t['is_excluded'] && !$t['is_approved']) $clear = false;
            $out[] = $t;
        }
        return $out;
    };

    $groups = [];
    $counts = ['items' => 0, 'tasks' => 0, 'done' => 0];   // tasks/done filled from the bar, below
    $allTasks = [];                      // every task on the show, for the top bar

    /** Fold one built item into its group, making the group when first seen. */
    $add = function (string $key, string $dept, string $detail, array $item)
                     use (&$groups, &$counts, &$allTasks) {
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key'    => $key,
                'name'   => $dept,
                'detail' => $detail,
                'color'  => board_dept_color($dept),
                'rank'   => board_dept_rank($dept),
                'items'  => [],
                '_tasks' => [],      // gathered for the group's own bar, below
            ];
        }
        $groups[$key]['items'][] = $item;
        // The header says "N shots and assets", so a whole-show deliverable
        // (Animatic, Color Script) is not one of them, though its task still
        // counts below (David, 2026-10-29: "shots should be 21 not 23" on MSD).
        if ($item['kind'] !== 'project') $counts['items']++;
        // A whole-show deliverable IS one task, so it contributes itself; a shot
        // contributes the tasks under it. Either way the group's bar is made of
        // exactly the work the group holds.
        foreach ($item['tasks'] ?: array_filter([$item['self']]) as $t) {
            $groups[$key]['_tasks'][] = $t;
            $allTasks[] = $t;
        }
    };

    // ---- whole-show deliverables ------------------------------------------
    //
    // ⚠ THE STEP IS THE ITEM HERE, which is why these rows have nothing under
    // them. MSD's board reads exactly this way: a Pre-production group holding
    // Animatic and Color Script, each one row with a status, a date and a
    // Replay link, and no sub-rows at all. A whole-show step has one progress
    // row ('p' + project id), so there is nothing to nest.
    $projectKey = 'p' . $projectId;
    foreach ($steps as $step) {
        if (($step['applies_to'] ?? '') !== 'project') continue;
        $row = $task($step, $projectKey);
        // Its own department where the step names one, else Post-production —
        // the phase the tracking grid has put whole-show steps in since 2026-08-14
        // (SFX, music, the mix, the master). MSD's animatic says Pre-production,
        // and it is right: this reads the step rather than assuming.
        $dept = board_dept_name(trim((string)($step['department'] ?? '')) ?: 'Post-production');
        $add('d:' . strtolower($dept), $dept, '', [
            'kind'      => 'project',
            'id'        => (int)$step['id'],
            'item_key'  => $projectKey,
            'name'      => (string)$step['name'],
            'scope'     => 'one deliverable',
            'frames'    => null,
            'tasks'     => [],
            'self'      => $row,            // this row IS the work
            'status'    => [
                'name'  => $row['status_name'] ?: 'Not started',
                'color' => $row['status_color'] ?: 'var(--violet)',
            ],
        ]);
    }

    // ---- shots -------------------------------------------------------------
    // The group is the EPISODE where a show has them, and one "Shots" group
    // where it does not. Both are how the Plaky board already reads.
    foreach ($shots as $s) {
        $itemKey = 's' . (int)$s['id'];
        $tasks = $tasksFor(['asset_type' => null], 'shot', $itemKey);
        if (!$tasks) continue;                   // no step covers shots at all
        // ⚠ SHOTS ARE PRODUCTION (David, 2026-10-22: "these names should be the
        // departments Anima already uses"). A shot cannot take a step's
        // department — it passes through eight of them — so it takes the PHASE
        // it belongs to, which is the same three-way split the tracking grid has
        // used since 2026-08-14: assets are pre-production, shots are
        // production, whole-show steps are post. The episode stays beside the
        // name rather than replacing it, because a 188-shot show in one group
        // would lose the one division that makes it readable.
        $ep = trim((string)($s['episode'] ?? ''));
        $add('prod:' . $ep, 'Production', $ep, [
            'kind'     => 'shot',
            'id'       => (int)$s['id'],
            'item_key' => $itemKey,
            'name'     => (string)$s['shot_code'],
            'scope'    => count($tasks) . ' task' . (count($tasks) === 1 ? '' : 's'),
            'frames'   => $s['frames'] !== null ? (int)$s['frames'] : null,
            'notes'    => (string)($s['notes'] ?? ''),
            'tasks'    => $tasks,
            'self'     => null,
            'status'   => board_item_status($tasks),
            'bar'      => board_item_bar($tasks, $statusMap),
        ]);
    }

    // ---- assets ------------------------------------------------------------
    foreach ($assets as $a) {
        $itemKey = 'a' . (int)$a['id'];
        $tasks = $tasksFor(['asset_type' => $a['asset_type']], 'asset', $itemKey);
        if (!$tasks) continue;
        $type = trim((string)$a['asset_type']);
        $add('pre:' . strtolower($type), 'Pre-production', $type, [
            'kind'     => 'asset',
            'id'       => (int)$a['id'],
            'item_key' => $itemKey,
            'name'     => (string)$a['name'],
            'scope'    => count($tasks) . ' task' . (count($tasks) === 1 ? '' : 's'),
            'frames'   => null,
            'notes'    => (string)($a['notes'] ?? ''),
            'tasks'    => $tasks,
            'self'     => null,
            'status'   => board_item_status($tasks),
            'bar'      => board_item_bar($tasks, $statusMap),
        ]);
    }

    // Pre-production, Production, Post-production, then anything a step named
    // itself — the order a crew list, a call sheet and the expense projection
    // are all already read in.
    // ⚠ THE GROUP'S BAR IS BUILT FROM EVERY TASK IN IT, not from its shots'
    // bars averaged. Averaging percentages weights a 2-task deliverable the
    // same as an 8-task shot, which is how a phase ends up reading finished
    // while half its work is outstanding (David, 2026-10-22).
    foreach ($groups as &$g) {
        $g['bar'] = board_item_bar($g['_tasks'], $statusMap);
        unset($g['_tasks']);
    }
    unset($g);

    $groups = array_values($groups);
    usort($groups, function ($a, $b) {
        return [$a['rank'], $a['detail']] <=> [$b['rank'], $b['detail']];
    });

    // ⚠ THE HEADER'S "N TASKS · N FINISHED" IS READ OFF THE SHOW'S BAR, never
    // counted a second time. It used to be counted separately, from each item's
    // `tasks` list, and that list is empty for a whole-show deliverable (its one
    // task is `self`), so Animatic and Color Script were in the bar and missing
    // from the header: David, 2026-10-29, on MSD, "144 tasks · 73 finished" above
    // a bar saying "75 of 146 tasks finished". One reckoning, one answer.
    $bar = board_item_bar($allTasks, $statusMap);
    $counts['tasks'] = $bar['total'];
    $counts['done']  = $bar['done'];

    return [
        'groups'   => $groups,
        'statuses' => array_values($statusMap),
        // In pipeline order, for the Step filter: a supervisor picks their own
        // department and the board narrows to it.
        'steps'    => $steps,
        'counts'   => $counts,
        // The whole show in one bar, over every task on it — the same reckoning
        // a group's bar makes, one level up.
        'bar'      => $bar,
    ];
}

/* ---------------------------------------------------------------------------
 * The cells.
 *
 * ⚠ REAL FORM CONTROLS, not divs with click handlers. A <select> opens with the
 * keyboard, reads out to a screen reader, and on a phone gets the operating
 * system's own picker — and the person this screen has to be effortless for is
 * a freelancer on their first day. Each control carries the three ids
 * board_api.php needs and saves itself through assets/board-screen.js.
 * ------------------------------------------------------------------------ */

/** The ids every cell posts back, as data attributes. */
function board_cell_attrs(array $t, int $projectId): string {
    return ' data-project="' . $projectId . '" data-step="' . (int)$t['step_id']
         . '" data-item="' . e($t['item_key']) . '"';
}

/**
 * The status, the one control everybody uses all day.
 *
 * Statuses outside the two an artist may set are still LISTED, and disabled —
 * so the board says what the other states are and who sets them, rather than
 * pretending a show has two statuses.
 */
function board_status_cell(array $t, array $statuses, int $projectId,
                           bool $mayChange, int $myCrewId, bool $mayEdit): string {
    $color = $t['status_color'] ?: '';
    $style = $color !== ''
        ? 'background:' . board_tint($color) . ';color:' . e($color) . ';'
        : '';

    if (!$mayChange) {
        return '<div class="board-cell"><span class="board-pill" style="' . $style . '">'
             . e($t['status_name'] ?: 'Not started') . '</span></div>';
    }

    $out = '<div class="board-cell"><select class="board-status"' . board_cell_attrs($t, $projectId)
         . ' style="' . $style . '" aria-label="Status of ' . e($t['step']) . '">';
    // "Not started" is only offered to Production: an artist clearing a status
    // is not a thing anybody asked for, and it reads as undoing somebody's work.
    if ($mayEdit || $t['status_id'] === null) {
        $out .= '<option value=""' . ($t['status_id'] === null ? ' selected' : '')
              . ($mayEdit ? '' : ' disabled') . '>Not started</option>';
    }
    foreach ($statuses as $s) {
        $id = (int)$s['id'];
        $isNow = $t['status_id'] === $id;
        $open = status_self_serve($s);
        // ⚠ WHAT A STATUS MEANS, ON THE OPTION ITSELF. board-screen.js redraws
        // the shot's derived status when one of these changes, and it must
        // decide the same way board_item_status() does — from maps_to and the
        // flags, never by matching the words "Final" or "N/A", which are each
        // show's own and change per project. Same rule as progress_log's
        // structural retake test.
        $out .= '<option value="' . $id . '"'
              . ' data-color="' . e($s['color'] ?: '') . '"'
              . (($s['maps_to'] ?? '') === 'approved' ? ' data-approved="1"' : '')
              . (($s['maps_to'] ?? '') === 'delivered' ? ' data-delivered="1"' : '')
              . ((int)($s['is_blocked'] ?? 0) === 1 ? ' data-blocked="1"' : '')
              . ((int)($s['is_excluded'] ?? 0) === 1 ? ' data-excluded="1"' : '')
              . (status_has_begun($s) ? ' data-begun="1"' : '')
              . ($isNow ? ' selected' : '')
              // Shown, so the show's whole vocabulary is visible, but not
              // selectable by somebody who may not set it.
              . ($open ? '' : ' disabled') . '>' . e($s['name']) . '</option>';
    }
    return $out . '</select></div>';
}

/** Who the task belongs to. Production only: an artist cannot hand work about. */
function board_crew_cell(array $t, array $crew, int $projectId, bool $mayEdit): string {
    $byId = [];
    foreach ($crew as $c) $byId[(int)$c['id']] = $c;
    if (!$mayEdit) {
        $name = $t['crew_id'] !== null && isset($byId[(int)$t['crew_id']])
              ? $byId[(int)$t['crew_id']]['name'] : $t['person'];
        return '<div class="board-cell">' . ($name
            ? '<span class="board-who"><span class="board-avatar">' . e(board_initials($name))
              . '</span>' . e($name) . '</span>'
            : '<span class="muted">—</span>') . '</div>';
    }
    // This step's people, the rest of the show, everybody else (see
    // board_assignees()). An empty group is not drawn.
    $stepId = (int)($t['step_id'] ?? 0);
    $groups = [[(string)($t['step'] ?? 'This step'), []], ['Rest of the crew', []], ['Other people', []]];
    foreach ($crew as $c) {
        $g = in_array($stepId, $c['steps'], true) ? 0 : ($c['on_show'] ? 1 : 2);
        $groups[$g][1][] = $c;
    }
    $out = '<div class="board-cell"><select class="board-crew"' . board_cell_attrs($t, $projectId)
         . ' aria-label="Who is on ' . e($t['step']) . '">'
         . '<option value="">Nobody yet</option>';
    $seen = false;
    foreach ($groups as [$label, $people]) {
        if (!$people) continue;
        $out .= '<optgroup label="' . e($label) . '">';
        foreach ($people as $c) {
            $id = (int)$c['id'];
            $on = $t['crew_id'] === $id;
            if ($on) $seen = true;
            $out .= '<option value="' . $id . '"' . ($on ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        $out .= '</optgroup>';
    }
    // Somebody named on the row who is no longer on the crew list still has to
    // appear, or opening this dropdown would quietly unassign them.
    if (!$seen && $t['crew_id'] !== null) {
        $out .= '<option value="' . (int)$t['crew_id'] . '" selected>'
              . e($t['person'] ?: 'Somebody no longer listed') . '</option>';
    }
    return $out . '</select></div>';
}

/**
 * The Version column (David, 2026-10-22): the current version's number, as a
 * BUTTON ("make that one and the version link buttons instead of hyperlinks")
 * that opens its preview. Still an <a> underneath, styled as a button, because
 * the lightbox (`att-zoom`, assets/tickets.js) listens for links and ctrl-click
 * should still open a tab. Anything not an image opens in a new tab. A dash
 * before the first version, so an empty column is visibly empty.
 */
function board_version_cell(array $t): string {
    $v = $t['latest'] ?? null;
    if (!$v) return '<div class="board-cell board-version-cell muted">—</div>';
    $url  = 'attachment.php?kind=version&amp;id=' . (int)$v['id'];
    $name = e((string)$v['original_name']);
    $label = e(version_label((int)$v['version_no']));
    $zoom = attachment_is_image((string)($v['mime_type'] ?? '')) ? ' att-zoom' : '';
    return '<div class="board-cell board-version-cell"><a href="' . $url . '" class="board-version-link'
         . $zoom . '" data-name="' . $name . '" target="_blank" rel="noopener" title="Open ' . $label
         . ', ' . $name . '">' . $label . '</a></div>';
}

/**
 * The Notes column: a button with how many REVIEW NOTES the task's current
 * version has (David, 2026-10-22, first a comment count, then "let's adjust the
 * comments to be Notes instead and they can also be a link to the note related
 * to that version"). It opens the task on Versions with that version's notes
 * shown. Empty at zero, so the column draws the eye only where somebody has
 * reviewed the latest work.
 */
function board_notes_cell(array $t, int $projectId, string $itemName): string {
    $n = (int)($t['review_notes'] ?? 0);
    $v = $t['latest'] ?? null;
    if ($n === 0 || !$v) return '<div class="board-cell board-notes-cell"></div>';
    $label = version_label((int)$v['version_no']);
    return '<div class="board-cell board-notes-cell"><button type="button" class="board-notes-btn"'
         . ' data-project="' . $projectId . '" data-step="' . (int)$t['step_id'] . '"'
         . ' data-item="' . e($t['item_key']) . '" data-version="' . (int)$v['id'] . '"'
         . ' aria-label="' . $n . ' review note' . ($n === 1 ? '' : 's') . ' on ' . e($label) . ' of '
         . e($t['step'] ?? 'this task') . ($itemName !== '' ? ' on ' . e($itemName) : '') . '">'
         . ($n > 99 ? '99+' : $n) . '</button></div>';
}

/** The due date — schedule.end_date, the same one the timeline draws. */
function board_due_cell(array $t, int $projectId, bool $mayEdit): string {
    $due = $t['due'];
    $late = !empty($t['due_late']);
    if (!$mayEdit) {
        return '<div class="board-cell"><span class="' . ($late ? 'err' : 'muted') . '">'
             . ($due ? e(date('j M', strtotime($due))) : '—') . '</span></div>';
    }
    return '<div class="board-cell"><input type="date" class="board-due' . ($late ? ' is-late' : '') . '"'
         . board_cell_attrs($t, $projectId) . ' value="' . e((string)$due) . '"'
         . ' aria-label="Due date for ' . e($t['step']) . '"></div>';
}

/** A link or a note, saved when the box is left. */
function board_text_cell(array $t, string $field, int $projectId, bool $mayEdit, string $hint): string {
    $value = (string)($t[$field] ?? '');
    if (!$mayEdit) {
        if ($value === '') return '<div class="board-cell"><span class="muted">—</span></div>';
        if ($field === 'link') {
            // rel="noopener" because the page it opens gets a handle on this one
            // otherwise, and these addresses are typed by people.
            return '<div class="board-cell"><a href="' . e($value) . '" target="_blank"'
                 . ' rel="noopener noreferrer">' . e(board_link_label($value)) . '</a></div>';
        }
        return '<div class="board-cell"><span class="board-note">' . e($value) . '</span></div>';
    }
    return '<div class="board-cell"><input type="' . ($field === 'link' ? 'url' : 'text') . '"'
         . ' class="board-text" data-field="' . e($field) . '"' . board_cell_attrs($t, $projectId)
         . ' value="' . e($value) . '" placeholder="' . e($hint) . '"'
         . ' aria-label="' . e(ucfirst($field) . ' for ' . $t['step']) . '"></div>';
}

/**
 * A shot's or an asset's OWN note (David, 2026-10-22: "notes for the
 * containers, they should be linked to those entities").
 *
 * ⚠ THIS IS NOT A TASK'S NOTE. `shots.notes` and `assets.notes` have been in
 * the schema since v1 and describe the THING — "the client hates this angle" —
 * while `progress.notes` describes one step's work on it. The board had a
 * column for the second and nowhere for the first. No step id goes with it,
 * which is what tells board_api.php which of the two is being written.
 */
function board_item_note_cell(array $item, int $projectId, bool $mayEdit): string {
    $value = (string)($item['notes'] ?? '');
    if (!$mayEdit) {
        return '<div class="board-cell">' . ($value !== ''
            ? '<span class="board-note">' . e($value) . '</span>'
            : '<span class="muted">—</span>') . '</div>';
    }
    return '<div class="board-cell"><input type="text" class="board-text board-item-text"'
         . ' data-project="' . $projectId . '" data-item="' . e($item['item_key']) . '"'
         . ' data-field="notes" value="' . e($value) . '" placeholder="Notes"'
         . ' aria-label="Notes about ' . e($item['name']) . '"></div>';
}

/** Somebody's initials, for the small round avatar. */
function board_initials(string $name): string {
    $parts = preg_split('/[\s._]+/', trim($name)) ?: [];
    $a = isset($parts[0][0]) ? $parts[0][0] : '';
    $b = isset($parts[1][0]) ? $parts[1][0] : '';
    return mb_strtoupper($a . $b);
}

/**
 * "Copy URL" — this one task's address, on the clipboard in one click.
 *
 * David, 2026-10-23: "let's create another column here with a button that says
 * copy URL. That button will copy the URL of that specific task to the
 * clipboard with just that click." It is the piece of the review loop that
 * already pays for itself: a producer looking at a shot wants to paste it into
 * Slack and say "this one", and today that means reading the address bar of a
 * page they are not on, because the panel opens over the board.
 *
 * ⚠ THE ADDRESS IS STORED AS A PATH, NOT AN ABSOLUTE URL, and assets/
 * board-screen.js resolves it against location.href at the moment of the
 * click. PHP would have to guess the scheme and host from $_SERVER to write an
 * absolute one here — which is exactly the guess that puts "http://localhost"
 * into somebody's Slack message when the app sits behind a proxy. The browser
 * already knows the answer.
 *
 * The link is the same one mention emails use, so a task opened from Slack and
 * a task opened from a notification land in the same place: the panel, over
 * the board, already open.
 */
function board_copy_cell(array $t, int $projectId, string $itemName = ''): string {
    $what = trim(($itemName !== '' ? $itemName . ' · ' : '') . (string)($t['step'] ?? 'this task'));
    return '<div class="board-cell">'
         . board_copy_button(board_task_path($projectId, (int)$t['step_id'], (string)$t['item_key']), $what)
         . '</div>';
}

/**
 * The address of one task, or of one thing inside it.
 *
 * ⚠ ONE FUNCTION BUILDS EVERY DEEP LINK IN THIS APP — the board's Copy URL
 * column, the per-comment and per-version buttons, and the links in mention
 * emails. They have to agree, because assets/board-screen.js reads all three
 * parts of what this writes: `open` says which task, `tab` which tab to show,
 * and the fragment which row inside it to scroll to.
 *
 * A PATH, never an absolute URL. The scheme and host are the browser's to
 * know; guessing them from $_SERVER is what puts "http://localhost" into
 * somebody's Slack message when the app sits behind a proxy.
 *
 * @param string $tab  a tab key as board_task.php writes it: comments,
 *                     versions, timeline. Empty opens the task as it is.
 * @param string $frag an element id inside that tab, e.g. "bc12" or "v34"
 */
function board_task_path(int $projectId, int $stepId, string $itemKey,
                         string $tab = '', string $frag = ''): string {
    return 'spell.php?project=' . $projectId
         . '&open=' . rawurlencode($stepId . ':' . $itemKey)
         . ($tab !== '' ? '&tab=' . rawurlencode($tab) : '')
         . ($frag !== '' ? '#' . rawurlencode($frag) : '');
}

/**
 * "Copy URL" — one address, on the clipboard in one click.
 *
 * David asked for this four times on 2026-10-23, in four places: the board
 * ("the URL of that specific task"), each comment thread, each version and
 * each review ("so it's easy to share"), and the timeline. It is one button
 * because it is one need — a producer looking at something wants to paste it
 * into Slack and say "this one", and today that means reading the address bar
 * of a page they are not on, because all of it opens in a panel over the board.
 *
 * $compact is for the ones that sit inside a line of text rather than in a
 * column of their own.
 */
function board_copy_button(string $path, string $what, bool $compact = false): string {
    return '<button type="button" class="board-copy-url' . ($compact ? ' is-compact' : '') . '"'
         . ' data-copy="' . e($path) . '"'
         . ' aria-label="Copy a link to ' . e($what) . '">Copy URL</button>';
}

/** What a link reads as: the service, not three hundred characters of address. */
function board_link_label(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    $host = preg_replace('/^www\./', '', $host);
    $known = ['replay.dropbox.com' => 'Replay', 'app.lucidlink.com' => 'LucidLink',
              'drive.google.com' => 'Drive', 'dropbox.com' => 'Dropbox'];
    return $known[$host] ?? $host;
}

/**
 * One task, for the panel that opens over the board.
 *
 * Reads the same row the board drew it from, so the panel can never disagree
 * with the line behind it, plus the two things the board has no column for:
 * what has been logged against it, and what has happened to it.
 *
 * @return array|null null when the step and item are not this project's
 */
function board_task_detail(PDO $pdo, int $projectId, int $stepId, string $itemKey): ?array {
    $q = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $q->execute([$stepId, $projectId]);
    $step = $q->fetch();
    if (!$step) return null;

    $kind = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    if (!in_array($kind, ['s', 'a', 'p'], true) || $itemId <= 0) return null;

    $item = ['name' => '', 'group' => '', 'frames' => null, 'kind' => $kind];
    if ($kind === 's') {
        $r = $pdo->prepare('SELECT episode, shot_code, frames FROM shots WHERE id = ? AND project_id = ?');
        $r->execute([$itemId, $projectId]);
        $row = $r->fetch();
        if (!$row) return null;
        $item['name']   = (string)$row['shot_code'];
        $item['group']  = trim((string)$row['episode']) !== '' ? (string)$row['episode'] : 'Production';
        $item['frames'] = $row['frames'] !== null ? (int)$row['frames'] : null;
    } elseif ($kind === 'a') {
        $r = $pdo->prepare("SELECT name, COALESCE(asset_type, '') AS asset_type
                              FROM assets WHERE id = ? AND project_id = ?");
        $r->execute([$itemId, $projectId]);
        $row = $r->fetch();
        if (!$row) return null;
        $item['name']  = (string)$row['name'];
        $item['group'] = trim((string)$row['asset_type']) ?: 'Pre-production';
    } else {
        if ($itemId !== $projectId) return null;
        $item['name']  = (string)$step['name'];
        $item['group'] = board_dept_name(trim((string)($step['department'] ?? '')) ?: 'Post-production');
    }

    $itemRow = ['asset_type' => null];
    if ($kind === 'a') {
        $t = $pdo->prepare('SELECT asset_type FROM assets WHERE id = ?');
        $t->execute([$itemId]);
        $itemRow['asset_type'] = $t->fetchColumn() ?: null;
    }
    $scope = ['s' => 'shot', 'a' => 'asset', 'p' => 'project'][$kind];
    if (!step_covers_item($step, $itemRow, $scope)) return null;

    // The cell itself, built by the same code the board rows are built from, so
    // the panel and the line behind it cannot drift apart.
    $board = board_data($pdo, $projectId);
    $task = null;
    foreach ($board['groups'] as $g) {
        foreach ($g['items'] as $it) {
            if ($it['item_key'] !== $itemKey) continue;
            foreach (array_merge($it['tasks'], array_filter([$it['self']])) as $t) {
                if ((int)$t['step_id'] === $stepId) $task = $t;
            }
        }
    }
    if ($task === null) return null;

    return [
        'task'      => $task,
        'step'      => $step,
        'item'      => $item,
        'statuses'  => $board['statuses'],
        'activity'  => board_task_activity($pdo, $projectId, $stepId, $itemKey),
        'logged'    => board_task_logged($pdo, $projectId, $stepId, $itemKey),
    ];
}

/** What has happened to one task, newest first. Empty before migration 021. */
function board_task_activity(PDO $pdo, int $projectId, int $stepId, string $itemKey): array {
    if (!function_exists('activity_available') || !activity_available($pdo)) return [];
    try {
        $q = $pdo->prepare(
            'SELECT old_status_name, new_status_name, change_type, source, changed_by,
                    crew_name, log_date, created_at
               FROM progress_log
              WHERE project_id = ? AND step_id = ? AND item_key = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 40'
        );
        $q->execute([$projectId, $stepId, $itemKey]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Hours logged against this task, or null where time logging is not set up. */
function board_task_logged(PDO $pdo, int $projectId, int $stepId, string $itemKey): ?float {
    if (!function_exists('time_logs_available') || !time_logs_available($pdo)) return null;
    try {
        $q = $pdo->prepare(
            'SELECT COALESCE(SUM(tl.minutes), 0)
               FROM time_logs tl
               JOIN progress pr ON pr.id = tl.progress_id
              WHERE pr.project_id = ? AND pr.step_id = ? AND pr.item_key = ?'
        );
        $q->execute([$projectId, $stepId, $itemKey]);
        return ((float)$q->fetchColumn()) / 60;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Everybody a task on this show can be handed to, each with where they stand
 * on it: the Assignee dropdown and the "Anybody" filter both group by that.
 *
 * ⚠ GROUPED BY STAFFING, NOT ONE A-Z LIST (David, 2026-10-29). Every task
 * offered the same 23 names, people on no slot of the show among them. Now a
 * task's own step comes first (its slots on Staffing: a BG Layout task opens on
 * the BG Layout artists), then the rest of the show's crew, then "Other people"
 * — still everybody, because a task can go to somebody not on Staffing yet.
 * "On the show" means a slot on Staffing (crew_lanes), not crew_projects: that
 * one keeps everybody who ever touched the show, which is how people not on
 * MSD's Staffing ended up in the list.
 *
 * Placeholder slots are left out — "Animation 02" is a position, not somebody
 * who can be told a task is theirs. A team row is left out for the same reason.
 * Somebody who has left the studio stays only while they still hold a task here.
 *
 * Rows of id, name (board_person_name()), steps (the step ids they fill a slot
 * on here), on_show and has_task, sorted by name.
 */
function board_assignees(PDO $pdo, int $projectId): array {
    $login = column_exists($pdo, 'users', 'crew_id')
        ? '(SELECT u.display_name FROM users u WHERE u.crew_id = c.id AND u.active = 1
              ORDER BY u.id LIMIT 1)'
        : 'NULL';
    $q = $pdo->prepare(
        'SELECT c.id, c.name, ' . $login . ' AS login_name,
                EXISTS (SELECT 1 FROM progress pr
                         WHERE pr.crew_id = c.id AND pr.project_id = ?) AS has_task
           FROM crew c
          WHERE COALESCE(c.is_placeholder, 0) = 0
            AND COALESCE(c.is_team, 0) = 0
            AND (c.active = 1 OR EXISTS (SELECT 1 FROM progress pr2
                                          WHERE pr2.crew_id = c.id AND pr2.project_id = ?))'
    );
    $q->execute([$projectId, $projectId]);
    $steps = [];
    $l = $pdo->prepare('SELECT crew_id, step_id FROM crew_lanes WHERE project_id = ?');
    $l->execute([$projectId]);
    foreach ($l->fetchAll() as $r) $steps[(int)$r['crew_id']][(int)$r['step_id']] = true;

    $out = [];
    foreach ($q->fetchAll() as $c) {
        $id = (int)$c['id'];
        $out[] = [
            'id'       => $id,
            'name'     => board_person_name((string)$c['name'], $c['login_name']),
            'steps'    => array_keys($steps[$id] ?? []),
            'on_show'  => isset($steps[$id]),
            'has_task' => (bool)$c['has_task'],
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * A person's full name, never their username (David, 2026-10-29: the list read
 * "Julia_tolstova" and "The.real.elemental.artist"). Some crew rows were made
 * from a login and carry its username as their name; the login's display name
 * is the real one. Only swapped when the crew name looks like a username (no
 * space) and the display name looks like a name (has one), so a crew name
 * somebody typed properly is never overridden.
 */
function board_person_name(string $crewName, $loginName): string {
    $login = trim((string)$loginName);
    if ($login !== '' && !str_contains(trim($crewName), ' ') && str_contains($login, ' ')) return $login;
    return $crewName;
}

/**
 * Who can be NAMED on a task: the show's crew, and everybody with a login.
 *
 * ⚠ WIDER THAN board_assignees() ON PURPOSE (David, 2026-10-22: "still can't
 * tag people here", typing @rena). Assigning is about who works the show;
 * naming is about who should look — and the people who review are producers
 * and supervisors who are on no show's crew. The ticket screen settled this
 * long ago in ticket_assignees() ("anyone with a login is an owner on EVERY
 * project"), so the same list is used here, less teams and empty slots, which
 * have nobody behind them to ring.
 */
function board_mentionable(PDO $pdo, int $projectId): array {
    $out = [];
    foreach (ticket_assignees($pdo, $projectId) as $p) {
        if ((int)($p['is_team'] ?? 0) === 1 || (int)($p['is_placeholder'] ?? 0) === 1) continue;
        $out[] = ['id' => (int)$p['id'], 'name' => (string)$p['name']];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * TALKING ABOUT A TASK (David, 2026-10-22), migration 059.
 *
 * "let's also add a comments section tab between details and activity", then —
 * when the first design was about to grow a second comment table — "those
 * comments are the ones that currently live under my tasks, they're the exact
 * same just displayed in this context too", and "remember to have the option to
 * create threads".
 *
 * So everything below is plumbing, not a feature: it finds the progress row a
 * board task IS, hangs `ticket_comments` off it, and hands the result to the
 * renderers ticket.php already uses. Threads, mentions, the seen_at stamp and
 * the My tasks list all arrive working because none of them is new.
 * ------------------------------------------------------------------------ */

/** Has migration 059 run? Until it has, the tab says so instead of failing. */
function board_comments_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'ticket_comments', 'progress_id');
    return $ok;
}

/**
 * The `progress` row a board task is, created if the importer never made one.
 *
 * ⚠ CREATING IT IS HARMLESS AND DELIBERATE. A task with no status set has no
 * row yet, and board_api.php's $upsert has always conjured one on the first
 * save; a comment is the same kind of first touch. The row it makes carries no
 * status, so nothing on any screen moves — unlike the alternative considered
 * and rejected in migration 059, which would have invented a TICKET and put a
 * duplicate card in front of the assignee for the crime of someone commenting.
 *
 * The caller must already have checked the step and item are this project's;
 * board_task_detail() and board_comment.php both do, and the project_id test
 * here is the belt to that braces.
 *
 * @return int|null null when there is no row and none was asked for
 */
function board_task_progress_id(PDO $pdo, int $projectId, int $stepId,
                                string $itemKey, bool $create = false): ?int {
    $q = $pdo->prepare('SELECT id FROM progress WHERE step_id = ? AND item_key = ? AND project_id = ?');
    $q->execute([$stepId, $itemKey, $projectId]);
    $id = $q->fetchColumn();
    if ($id) return (int)$id;
    if (!$create) return null;

    $kind   = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    $pdo->prepare(
        'INSERT INTO progress (project_id, step_id, shot_id, asset_id, item_key)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    )->execute([
        $projectId, $stepId,
        $kind === 's' ? $itemId : null,
        $kind === 'a' ? $itemId : null,
        $itemKey,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Everything said about one task, oldest first, with who said it.
 *
 * Oldest first because a conversation is read down the page; the ticket screen
 * does the same. Mentions come back keyed by comment so one query covers the
 * lot rather than one per line.
 *
 * @return array{comments: array, mentions: array<int, string[]>}
 */
function board_task_comments(PDO $pdo, int $progressId): array {
    if (!board_comments_available($pdo) || $progressId <= 0) {
        return ['comments' => [], 'mentions' => [], 'files' => []];
    }
    $q = $pdo->prepare(
        'SELECT c.id, c.parent_id, c.user_id, c.kind, c.body, c.created_at,
                u.display_name'
        . (board_reviews_available($pdo) ? ', c.version_id, pv.version_no AS review_of' : '') . '
           FROM ticket_comments c
           LEFT JOIN users u ON u.id = c.user_id'
        . (board_reviews_available($pdo) ? ' LEFT JOIN progress_versions pv ON pv.id = c.version_id' : '') . '
          WHERE c.progress_id = ?
          ORDER BY c.created_at, c.id'
    );
    $q->execute([$progressId]);
    $comments = $q->fetchAll();

    // What is attached to each, in one query for the lot (migration 061). The
    // files hang off the COMMENT, not off a ticket — a board comment has none.
    $files = [];
    if ($comments) {
        try {
            $ids = implode(',', array_map(fn($c) => (int)$c['id'], $comments));
            foreach ($pdo->query(
                'SELECT * FROM ticket_attachments WHERE comment_id IN (' . $ids . ')
                  ORDER BY created_at, id'
            ) as $a) {
                $files[(int)$a['comment_id']][] = $a;
            }
        } catch (PDOException $e) {
            $files = [];   // before migration 017, or a table mid-upgrade
        }
    }

    $mentions = [];
    if ($comments && ticket_mentions_available($pdo)) {
        $ids = implode(',', array_map(fn($c) => (int)$c['id'], $comments));
        foreach ($pdo->query(
            'SELECT comment_id, named_as FROM ticket_mentions WHERE comment_id IN (' . $ids . ')'
        ) as $m) {
            $mentions[(int)$m['comment_id']][] = (string)$m['named_as'];
        }
    }
    return ['comments' => $comments, 'mentions' => $mentions, 'files' => $files];
}

/**
 * Say something about a task, and tell anybody it named.
 *
 * Returns [commentId, a sentence for the author]. Throws on anything it will
 * not do, which the caller turns into a line of red in the panel.
 *
 * @param array $ctx the task's names, for the email's subject and link
 */
function board_comment_add(PDO $pdo, int $projectId, int $stepId, string $itemKey,
                           string $body, ?int $parentId, int $userId, array $ctx = [],
                           bool $hasFiles = false, ?int $versionId = null): array {
    if (!board_comments_available($pdo)) {
        throw new RuntimeException('Comments need database update 059, which has not been applied yet.');
    }
    $body = trim($body);
    // A file on its own is a comment — "here is the reference" said without
    // typing it. The ticket screen has always allowed this; the placeholder is
    // the same one, so the two read alike.
    if ($body === '' && $hasFiles) $body = '(attachment)';
    if ($body === '') {
        throw new RuntimeException('Nothing to post — the box was empty and no file was attached.');
    }
    if (mb_strlen($body) > 5000) {
        throw new RuntimeException('That is longer than a comment holds (5000 characters).');
    }

    $progressId = board_task_progress_id($pdo, $projectId, $stepId, $itemKey, true);
    if (!$progressId) throw new RuntimeException('That task could not be found to comment on.');

    // ⚠ A REPLY'S PARENT MUST BE ON THIS TASK. Without the check, a posted id is
    // a way to hang a reply on another show's conversation — the same guard
    // ticket.php keeps, for the same reason.
    $parent = null;
    if ($parentId && ticket_threads_available($pdo)) {
        $q = $pdo->prepare('SELECT id FROM ticket_comments WHERE id = ? AND progress_id = ?');
        $q->execute([$parentId, $progressId]);
        $parent = $q->fetchColumn() ?: null;
    }

    // ⚠ A REVIEW'S VERSION MUST BE THIS TASK'S, by the same reasoning as the
    // parent above: a posted id is otherwise a way to pin a note on another
    // show's work. Dropped silently before migration 065.
    $version = null;
    if ($versionId && board_reviews_available($pdo)) {
        $q = $pdo->prepare('SELECT id FROM progress_versions WHERE id = ? AND progress_id = ?');
        $q->execute([$versionId, $progressId]);
        $version = $q->fetchColumn() ?: null;
        if (!$version) throw new RuntimeException('That version is not on this task any more.');
    }

    $cols  = 'progress_id, user_id, kind, body';
    $marks = "?, ?, 'user', ?";
    $args  = [$progressId, $userId ?: null, $body];
    if (ticket_threads_available($pdo)) { $cols .= ', parent_id'; $marks .= ', ?'; $args[] = $parent; }
    if ($version !== null) { $cols .= ', version_id'; $marks .= ', ?'; $args[] = (int)$version; }
    $pdo->prepare('INSERT INTO ticket_comments (' . $cols . ') VALUES (' . $marks . ')')->execute($args);
    $commentId = (int)$pdo->lastInsertId();

    // Resolved against this show's people and stored as ids, never left in the
    // text: the rule migration 039 wrote down.
    $mentions = ticket_parse_mentions($body, board_mentionable($pdo, $projectId));
    ticket_save_mentions($pdo, $commentId, $mentions);

    // ⚠ THE BELL FIRST, THE EMAIL SECOND, and the order is the point. David,
    // 2026-10-22, reading "naming somebody emails them and puts it in their My
    // tasks": "this is not the right behavior, you need to get a notification
    // in a bell close to your name". The email is a courtesy that may fail, be
    // filtered or go to somebody with no address on file; the bell is the one
    // that is always there when they next open Anima.
    if ($mentions) {
        notify_add($pdo, array_keys($mentions), [
            'kind'        => 'mention',
            'actor'       => current_user_display(),
            'summary'     => trim(trim((string)($ctx['step_name'] ?? '')) . ' on '
                                . trim((string)($ctx['item_name'] ?? '')), ' on '),
            'project_id'  => $projectId,
            'step_id'     => $stepId,
            'item_key'    => $itemKey,
            'progress_id' => $progressId,
            'comment_id'  => $commentId,
        ], (int)(effective_crew_id($pdo) ?? 0));
    }

    $said = $version !== null ? 'Review posted.' : ($parent ? 'Reply posted.' : 'Comment posted.');
    if ($mentions && function_exists('notify_mentioned_task')) {
        // Naming somebody tells them, here as on a ticket — David settled that
        // on 2026-10-06 ("not an option but something already built in by
        // default"). The mailer opens SMTP inline, which is also why posting a
        // comment with an @ in it takes a moment; see the note in ticket.php.
        [, $more] = notify_mentioned_task($pdo, array_keys($mentions), $body,
            current_user_display(), $ctx + ['project_id' => $projectId,
                                            'step_id' => $stepId, 'item_key' => $itemKey]);
        if ($more !== '') $said .= ' ' . $more;
    }
    return [$commentId, $said];
}

/**
 * The Comments tab's contents.
 *
 * ⚠ MARKUP, NOT JSON, for the reason board_task.php gives: posting a comment
 * re-renders this pane through the same function that drew it, so a reply, a
 * highlighted mention and a thread indent are written once. It is also why
 * board_comment.php answers with HTML.
 */
function board_comments_html(PDO $pdo, int $projectId, int $stepId, string $itemKey,
                             bool $mayPost, array $people): string {
    if (!board_comments_available($pdo)) {
        return '<p class="warn-text small">Comments need database update 059. '
             . 'Everything else on this task works meanwhile.</p>';
    }

    $progressId = board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $found = board_task_comments($pdo, (int)$progressId);
    $threaded = ticket_thread($found['comments']);

    // ONE RENDERER for a comment and for a reply, so the two cannot drift apart
    // in anything but their indent. Depth is 0 or 1 and nothing else — see
    // migration 039 for why a task is not a forum.
    $myUserId = (int)($_SESSION['user_id'] ?? 0);
    $cProject = $projectId; $cStep = $stepId; $cItem = $itemKey;   // for the copy links
    $one = function (array $c, int $depth) use ($found, $mayPost, $pdo, $myUserId,
                                                $cProject, $cStep, $cItem): string {
        $cid = (int)$c['id'];
        $named = $found['mentions'][$cid] ?? [];
        $h = '<div class="comment' . ($depth > 0 ? ' comment-reply' : '')
           . '" id="bc' . $cid . '">'
           . '<div class="comment-head"><strong>'
           . e($c['display_name'] ?? 'Someone') . '</strong>'
           // A review shows here too, saying which version it is about.
           . (!empty($c['review_of'])
                ? '<span class="version-tag review-tag">review of ' . e(version_label((int)$c['review_of'])) . '</span>'
                : '')
           . '<span class="muted small">'
           . e(date('j M Y · g:i A', strtotime((string)$c['created_at']))) . '</span>';
        if ($mayPost && $depth === 0 && ticket_threads_available($pdo)) {
            // ⚠ A BUTTON THAT LOOKS LIKE ONE (David, 2026-10-23: "let's make
            // that reply a button too"). It was `linkish` — blue, underlined,
            // indistinguishable from the shot link three lines up — so it read
            // as somewhere to go rather than something to do.
            $h .= '<button type="button" class="comment-reply-btn" data-reply-to="' . $cid
                . '" data-reply-name="' . e($c['display_name'] ?? '') . '">Reply</button>';
        }
        // Every thread is worth sharing on its own: the link opens the task
        // with Comments showing and scrolls to this one.
        $h .= board_copy_button(
            board_task_path($cProject, $cStep, $cItem, 'comments', 'bc' . $cid),
            'this comment', true
        );
        // DELETE, for whoever wrote it and for admins (David, 2026-10-29: "only
        // who posted and admin can delete those"). board_comment.php checks the
        // same rule again; this only decides whether the button is drawn.
        if ($mayPost && (is_admin() || ((int)($c['user_id'] ?? 0) === $myUserId && $myUserId > 0))) {
            $h .= '<button type="button" class="link-danger board-comment-del" data-comment="' . $cid . '"'
                . ' title="Delete this ' . ($depth > 0 ? 'reply' : 'comment') . ' and its files">Delete</button>';
        }
        $h .= '</div>';
        // "(attachment)" is what a comment that is only a file is saved as;
        // the picture says it, so the panel does not draw the word (David,
        // 2026-10-29). The saved text is unchanged, so the bell still has
        // something to say.
        if ((string)$c['body'] !== '(attachment)') {
            $h .= '<div class="comment-body">'
                . ticket_render_body((string)$c['body'], $named) . '</div>';
        }

        // The files, drawn by the renderer the ticket screen uses — moved into
        // includes/uploads.php when this screen needed it too, rather than
        // copied (David, 2026-10-22: "allow comments to attach files").
        if (!empty($found['files'][$cid])) {
            $h .= '<div class="attachment-grid">';
            foreach ($found['files'][$cid] as $a) {
                // Yours to remove, or an admin's. An × that refuses when pressed
                // is worse than no ×, so it is only drawn where it will work.
                $mine = $myUserId > 0 && (int)$a['uploaded_by'] === $myUserId;
                $can  = $mayPost && (is_admin() || $mine);
                ob_start();
                render_attachment($a, $can, $can
                    ? '<button type="button" class="link-danger board-att-del" data-att="'
                      . (int)$a['id'] . '" title="Remove this file">×</button>'
                    : '');
                $h .= ob_get_clean();
            }
            $h .= '</div>';
        }
        $h .= '</div>';
        return $h;
    };

    $out = '';
    foreach ($threaded as $c) {
        $out .= $one($c, 0);
        foreach (($c['replies'] ?? []) as $r) $out .= $one($r, 1);
    }
    if ($out === '') {
        $out = '<p class="muted small">Nothing said about this task yet. Anything written here is the '
             . 'same conversation that shows on My tasks, so naming somebody reaches them there too.</p>';
    }

    if ($mayPost) {
        $out .= '<form class="comment-form board-comment-form" method="post"'
              . ' enctype="multipart/form-data"'
              . ' data-project="' . $projectId . '" data-step="' . $stepId . '"'
              . ' data-item="' . e($itemKey) . '">'
              . '<div class="reply-banner" hidden>Replying to <strong></strong> '
              . '<button type="button" class="linkish" data-reply-cancel>post as a new comment instead</button></div>'
              // add_comment is the FIELD artist_may_post() allows, not a value:
              // the allow-list in includes/auth.php is read that way, so this
              // name is what lets an artist speak here at all.
              . '<input type="hidden" name="add_comment" value="1">'
              . '<input type="hidden" name="parent_id" value="">'
              . '<label>Add a comment'
              . '<textarea name="body" rows="3" class="board-comment-box"'
              . ' placeholder="What is going on with this one… type @ to name somebody"></textarea>'
              . '</label>'
              // Same limits, same validation and same folder as a ticket's
              // attachments — one uploader, which is where the MIME check lives.
              . '<label class="board-comment-files">Attach files'
              . '<input type="file" name="attachments[]" multiple'
              . ' data-drop="Drop files here">'
              . '</label>'
              . '<p class="muted small">Type @ and a name to notify someone (bell).</p>'
              . '<button type="submit" class="primary board-comment-post">Post</button>'
              . '</form>'
              // The picker reads this, exactly as ticket.php feeds assets/mentions.js:
              // the names offered are the names the parser can resolve, never more.
              . '<script type="application/json" class="board-comment-people">'
              . json_encode(array_map(fn($p) => ['name' => $p['name']], $people),
                            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
              . '</script>';
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * VERSIONS AND REVIEW (David, 2026-10-22), migration 063.
 *
 * "Version should not be typed in but got from the number of files uploaded",
 * and "Let's create a Versions and review tab next to activity — people should
 * be able to upload images, open a preview, versions will start at v01 and
 * increment by 1 every time a new version is up".
 *
 * So `progress.version` is no longer a field somebody fills in; it is a label
 * written by save_task_versions() from the rows that exist. The panel shows it
 * and the board column goes on reading it, both unchanged — what changed is
 * that nobody can type "v07" on a task that has three versions.
 * ------------------------------------------------------------------------ */

/** Does a version carry a link to its final files? Also migration 065. */
function board_version_links_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'progress_versions', 'link');
    return $ok;
}

/** Has migration 065 run? A review is a comment that names its version. */
function board_reviews_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'ticket_comments', 'version_id');
    return $ok;
}

/**
 * Who makes reviews rather than versions: supervisors, Production and admins
 * (David, 2026-10-22). Artists put work up; these people say what to change.
 */
function board_is_reviewer(): bool {
    return is_admin() || can_edit() || is_supervisor();
}

/** Has migration 063 run? Until it has, the tab says so instead of failing. */
function board_versions_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'progress_versions', 'version_no');
    return $ok;
}

/**
 * Every version of one task, NEWEST FIRST.
 *
 * Newest first because the question this tab answers is "what is the current
 * one" far more often than "what did we start with" — the opposite of the
 * comments tab, where a conversation is read downwards.
 */
function board_task_versions(PDO $pdo, int $progressId): array {
    if ($progressId <= 0 || !board_versions_available($pdo)) return [];
    try {
        $q = $pdo->prepare(
            'SELECT v.*, u.display_name
               FROM progress_versions v
               LEFT JOIN users u ON u.id = v.uploaded_by
              WHERE v.progress_id = ?
              ORDER BY v.version_no DESC'
        );
        $q->execute([$progressId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * The Versions and review tab.
 *
 * ⚠ MARKUP, NOT JSON, the same choice board_task.php and board_comments_html()
 * make: uploading re-renders this pane through the function that drew it, so a
 * thumbnail, a version label and the empty state are written once.
 *
 * Images get a real thumbnail linked with class="att-zoom", which is the hook
 * the lightbox in assets/tickets.js already listens for — clicking one shows it
 * full size over the board instead of taking you off the screen you are on.
 * The href is a working link underneath, so ctrl-click and middle-click behave.
 */
function board_versions_html(PDO $pdo, int $projectId, int $stepId, string $itemKey,
                             bool $mayPost): string {
    if (!board_versions_available($pdo)) {
        return '<p class="warn-text small">Versions need database update 063. '
             . 'Everything else on this task works meanwhile.</p>';
    }

    $progressId = board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $versions = board_task_versions($pdo, (int)$progressId);
    $myUserId = (int)($_SESSION['user_id'] ?? 0);
    // The people a note can name (David, 2026-10-22: "make sure we can tag people
    // in the versions"). The same list the comment box offers and the parser
    // resolves against, so a highlighted name is always somebody who was told.
    $people = board_mentionable($pdo, $projectId);

    // REVIEWS (David, 2026-10-22): supervisors, Production and admins "make a
    // review" against a version rather than putting one up. A review is a
    // comment that names its version (migration 065), so it is read here from
    // the same table, with its files and its mentions.
    $reviewsOn = board_comments_available($pdo) && board_reviews_available($pdo);
    $reviewer  = $mayPost && $reviewsOn && board_is_reviewer();
    $reviews = [];      // version id => [comment rows]
    $files   = [];      // comment id => [attachments]
    $named   = [];      // comment id => [names as written]
    if ($reviewsOn && $progressId) {
        $q = $pdo->prepare(
            'SELECT c.id, c.version_id, c.body, c.created_at, u.display_name
               FROM ticket_comments c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.progress_id = ? AND c.version_id IS NOT NULL
              ORDER BY c.created_at, c.id'
        );
        $q->execute([(int)$progressId]);
        $ids = [];
        foreach ($q as $r) { $reviews[(int)$r['version_id']][] = $r; $ids[] = (int)$r['id']; }
        if ($ids) {
            $in = implode(',', $ids);
            try {
                foreach ($pdo->query('SELECT * FROM ticket_attachments WHERE comment_id IN (' . $in . ')
                                       ORDER BY created_at, id') as $a) {
                    $files[(int)$a['comment_id']][] = $a;
                }
            } catch (PDOException $e) {
                $files = [];
            }
            if (ticket_mentions_available($pdo)) {
                foreach ($pdo->query('SELECT comment_id, named_as FROM ticket_mentions
                                       WHERE comment_id IN (' . $in . ')') as $m) {
                    $named[(int)$m['comment_id']][] = (string)$m['named_as'];
                }
            }
        }
    }

    $out = '';
    if (!$versions) {
        $out .= '<p class="muted small">No versions yet. The first file put up here is '
              . '<strong>v01</strong>, and every one after it counts on from there — nobody '
              . 'types a version number.</p>';
    } else {
        $out .= '<ul class="version-list">';
        foreach ($versions as $i => $v) {
            $url = 'attachment.php?kind=version&amp;id=' . (int)$v['id'];
            $name = e((string)$v['original_name']);
            $isTop = $i === 0;
            $vid = (int)$v['id'];
            $mine = $reviews[$vid] ?? [];

            $out .= '<li class="version' . ($isTop ? ' is-current' : '') . '" id="v' . $vid . '">'
                  . '<div class="version-head">'
                  . '<strong class="version-no">' . e(version_label((int)$v['version_no'])) . '</strong>'
                  . ($isTop ? '<span class="version-tag">current</span>' : '')
                  . '<span class="muted small">' . e($v['display_name'] ?? 'Someone') . ' · '
                  . e(date('j M Y · g:i A', strtotime((string)$v['created_at']))) . '</span>';

            // AT THE TOP OF EVERY VERSION (David, 2026-10-22): the way to its
            // review notes, or — for a reviewer, where there are none yet — the
            // way to start them, with this version already picked in the form.
            //
            // ⚠ NOTES ARE LOUD ON PURPOSE (David, 2026-10-22: "a bit more on the
            // face so we don't miss them, or the artists"). The button is filled
            // in the warning colour, and the CURRENT version's notes are open
            // from the start — they are what the artist has to act on next.
            if ($mine) {
                $n = count($mine);
                // The chevron comes from the CSS on [aria-expanded], so the
                // arrow and the state cannot disagree: there is only one of them.
                $out .= '<button type="button" class="version-reviews-btn has-notes is-toggle"'
                      . ' aria-expanded="' . ($isTop ? 'true' : 'false') . '"'
                      . ' data-version-reviews="vr' . $vid . '">' . $n . ' review note' . ($n === 1 ? '' : 's')
                      . '</button>';
            } elseif ($reviewer) {
                $out .= '<button type="button" class="version-reviews-btn" data-review-version="' . $vid
                      . '">Review this version</button>';
            }

            // THE VIEWER, BESIDE THE NOTES (David, 2026-10-27: "add the review &
            // draw button at the top of the version like the make a review
            // one"). Pictures and, since David asked for "a better preview for
            // video ... frame by frame, 2x, 3x", videos too. A PDF has nothing
            // assets/review-viewer.js can open.
            $isImage = attachment_is_image((string)($v['mime_type'] ?? ''));
            $isVideo = attachment_is_video((string)($v['mime_type'] ?? ''));
            if ($isImage || $isVideo) {
                $out .= '<button type="button" class="version-review-open" data-review-open="' . $vid . '">'
                      . ($reviewer ? 'Review &amp; draw' : 'Open viewer') . '</button>';
            }

            // ⚠ EVERY VERSION IS ITS OWN ADDRESS (David, 2026-10-23: "copy url
            // button for each of the new versions and also for the reviews so
            // it's easy to share"). v02 is a thing people talk about by name,
            // and the link lands on it with the tab already open.
            $out .= board_copy_button(
                board_task_path($projectId, $stepId, $itemKey, 'versions', 'v' . $vid),
                version_label((int)$v['version_no']), true
            );

            // Yours to remove, or an admin's — and only while it is the latest,
            // because taking a version out of the middle would leave a gap in a
            // sequence people quote at each other.
            if ($mayPost && $isTop && (is_admin() || (int)$v['uploaded_by'] === $myUserId)) {
                $out .= '<button type="button" class="link-danger board-version-del" data-version="'
                      . (int)$v['id'] . '" title="Remove this version">&times;</button>';
            }
            $out .= '</div>';

            // The thread itself, under the head and ABOVE the picture. Open on the
            // current version, shut on older ones, whose notes have been acted on
            // and would only push their own pictures off screen.
            if ($mine) {
                $out .= '<div class="version-reviews" id="vr' . $vid . '"' . ($isTop ? '' : ' hidden') . '>'
                      . '<div class="version-reviews-title">Review notes on '
                      . e(version_label((int)$v['version_no'])) . '</div>';
                foreach ($mine as $r) {
                    $cid = (int)$r['id'];
                    $out .= '<div class="comment version-review" id="bc' . $cid . '">'
                          . '<div class="comment-head"><strong>'
                          . e($r['display_name'] ?? 'Someone') . '</strong><span class="muted small">'
                          . e(date('j M Y · g:i A', strtotime((string)$r['created_at']))) . '</span>'
                          // A review is the thing most worth pasting into Slack:
                          // it is somebody's verdict on a named version.
                          . board_copy_button(
                                board_task_path($projectId, $stepId, $itemKey, 'versions', 'bc' . $cid),
                                'these review notes', true
                            )
                          . '</div>';
                    // "(attachment)" is the placeholder for a review that is only
                    // a picture; the picture says it, so the word is not drawn.
                    if ((string)$r['body'] !== '(attachment)') {
                        $out .= '<div class="comment-body">'
                              . ticket_render_body((string)$r['body'], $named[$cid] ?? []) . '</div>';
                    }
                    if (!empty($files[$cid])) {
                        $out .= '<div class="attachment-grid">';
                        foreach ($files[$cid] as $a) {
                            ob_start();
                            render_attachment($a, false);
                            $out .= ob_get_clean();
                        }
                        $out .= '</div>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }

            // ⚠ AN IMAGE OPENS THE REVIEW VIEWER, NOT THE LIGHTBOX (David,
            // 2026-10-27: "a very basic image editor to review new versions
            // online"). assets/review-viewer.js builds it from this pane —
            // data-review-open names the version, img.version-img is how it
            // finds the pictures. The href stays a working link, so ctrl-click
            // still opens the file on its own.
            if ($isImage) {
                $out .= '<a href="' . $url . '" class="version-shot" data-review-open="' . $vid . '"'
                      . ' title="Open in the review viewer" target="_blank" rel="noopener">'
                      . '<img class="version-img" src="' . $url . '" alt="' . $name . '"></a>';
            } elseif ($isVideo) {
                // version-vid and data-name are how the viewer finds it. The
                // browser's own controls stay, for a quick look in place.
                $out .= '<video class="version-shot version-vid" src="' . $url . '" data-name="' . $name
                      . '" controls preload="metadata"></video>';
            } else {
                $out .= '<a href="' . $url . '" class="file-link" target="_blank" rel="noopener">📄 '
                      . $name . '</a>';
            }

            $out .= '<div class="version-meta muted small"><span title="' . $name . '">' . $name
                  . '</span> · ' . e(human_size((int)$v['file_size'])) . '</div>';
            if (trim((string)($v['link'] ?? '')) !== '') {
                $out .= '<a class="version-files-btn" href="' . e((string)$v['link'])
                      . '" target="_blank" rel="noopener">Open final files</a>';
            }
            if (trim((string)($v['note'] ?? '')) !== '') {
                // Names resolved at render time rather than stored: a version note
                // is not a comment and has no row in ticket_mentions, and the
                // parser is the same one that decided who to tell on upload.
                $note = (string)$v['note'];
                $out .= '<p class="version-note">'
                      . ticket_render_body($note, ticket_parse_mentions($note, $people)) . '</p>';
            }
            $out .= '</li>';
        }
        $out .= '</ul>';
    }

    // ⚠ THE FORM GOES ON TOP (David, 2026-10-22: "make sure this part is always
    // at the top"). Versions are newest first and each carries a full-size
    // preview, so below them the form sank further with every round of review.
    $form = '';
    if ($mayPost) {
        // THE SHOW'S NAMING CONVENTION (migration 067, set on the Brief): what
        // the file must be called is said BEFORE anything is picked, and the
        // rule rides on the form so assets/board-screen.js can check each file
        // the moment it is chosen. board_version.php checks it again.
        $nextNo = count($versions) > 0 ? ((int)$versions[0]['version_no']) + 1 : 1;
        $rule = naming_rule($pdo, $projectId, $stepId, $itemKey);
        $initials = naming_initials(current_user_display());
        $namingAttr = '';
        $namingHint = '';
        if ($rule) {
            $namingAttr = ' data-naming="' . e(json_encode([
                're' => $rule['regex'], 'first' => $rule['first'], 'next' => $nextNo,
                'pad' => 2, 'example' => naming_example($rule, $nextNo, $initials),
                // The same name with {V} where the number goes, so the browser
                // can build one per file without string-replacing digits that
                // also appear in a shot code.
                'exampleTpl' => naming_example_template($rule, $initials),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
            $namingHint = '<p class="naming-hint">Name it <code>' . e(naming_example($rule, $nextNo, $initials))
                        . '</code> <span class="muted small">· the show\'s naming convention, set on the '
                        . '<a href="brief.php?project=' . $projectId . '#naming">Brief</a></span></p>';
        }

        // "Upload new version", not "Put up v02" (David, 2026-10-22): the
        // number is the app's business, and the version list says what it was.
        // Folded under its own "Upload a new version" summary for reviewers,
        // so the form does not repeat the title right under it (David,
        // 2026-10-29).
        $uploadFolded = $reviewer && $versions;
        $upload = '<form class="comment-form board-version-form" method="post"'
              . ' enctype="multipart/form-data"'
              . ' data-project="' . $projectId . '" data-step="' . $stepId . '"'
              . ' data-item="' . e($itemKey) . '"' . $namingAttr . '>'
              . '<input type="hidden" name="add_version" value="1">'
              . '<label>' . ($uploadFolded ? '' : '<span class="board-version-title">Upload new version</span>')
              . $namingHint
              . '<input type="file" name="versions[]" accept="image/*,video/*,.pdf" multiple'
              . ' data-drop="Drop your video or image here" data-drop-paste="no" data-drop-pick="choose a file">'
              . '</label>'
              . ($rule ? '<div class="naming-check" hidden></div>' : '')
              // ⚠ REQUIRED (David, 2026-10-22: "so people describe what they
              // modify"). A reviewer handed v04 with no note has to diff it by
              // eye against v03. board_version.php refuses an empty one too.
              . '<label>What changed'
              // board-comment-box is the hook assets/board-screen.js arms the @
              // picker on, and the one it refocuses after a post.
              . '<textarea name="note" rows="2" class="board-comment-box" required'
              . ' placeholder="Fixed the camera move on the tail… type @ to name a reviewer"></textarea>'
              . '</label>'
              // Where the final files live (David, 2026-10-22), optional: a
              // work-in-progress round may have nothing worth linking yet.
              . (board_version_links_available($pdo)
                  ? '<label>Link to the final files <small class="muted">(Drive or LucidLink, optional)</small>'
                    . '<input type="url" name="link" maxlength="500" placeholder="https://…"></label>'
                  : '')
              . '<p class="muted small">Several files? Each one becomes the next version ('
              . e(version_label($nextNo)) . ', ' . e(version_label($nextNo + 1)) . '...). '
              . 'Type @ and a name to notify someone (bell).</p>'
              . '<button type="submit" class="primary board-version-post">Send for review</button>'
              . '</form>';

        if ($uploadFolded) {
            // MAKE A REVIEW, for the people who review (David, 2026-10-22): which
            // version it is about, what to change, and pictures pasted straight
            // into the text or picked from disk. Uploading a version stays one
            // click away, for the day a supervisor paints over a frame.
            $opts = '';
            foreach ($versions as $i => $v) {
                $opts .= '<option value="' . (int)$v['id'] . '"' . ($i === 0 ? ' selected' : '') . '>'
                       . e(version_label((int)$v['version_no'])) . ($i === 0 ? ' (current)' : '')
                       . '</option>';
            }
            // ⚠ BOTH FORMS ARE FOLDED AWAY NOW (David, 2026-10-23: "let's make
            // 'Make review' an expandable item too"). The tab is for LOOKING at
            // versions and the notes on them; putting the work up and reviewing
            // it are two things you do occasionally, and a form open by default
            // pushed the current version — the thing everybody came to see —
            // below the fold.
            //
            // The version picker moves out of the title and into the body: a
            // <select> inside a <summary> is a trap, because clicking it
            // collapses the thing you were about to fill in.
            $form = '<details class="board-expand board-review-expand">'
                  . '<summary><span class="board-version-title">Review this version</span></summary>'
                  . '<form class="comment-form board-version-form board-review-form" method="post"'
                  . ' enctype="multipart/form-data"'
                  . ' data-project="' . $projectId . '" data-step="' . $stepId . '"'
                  . ' data-item="' . e($itemKey) . '">'
                  . '<input type="hidden" name="add_review" value="1">'
                  . '<label class="board-review-version">Reviewing <select name="version_id"'
                  . ' aria-label="Which version this review is about">' . $opts . '</select></label>'
                  . '<label>Notes'
                  . '<textarea name="body" rows="3" class="board-comment-box board-review-box"'
                  . ' placeholder="What to change… paste an image straight in, type @ to name somebody">'
                  . '</textarea></label>'
                  . '<label class="board-comment-files">Attach files'
                  // ⚠ data-no-drop: assets/dropzone.js leaves this one alone. The
                  // review keeps ONE list of its files (pasted, picked or dropped
                  // anywhere on the form) and draws them as thumbnails; a drop
                  // zone keeping a second list of the same input lost the pasted
                  // ones the moment a file was picked.
                  . '<input type="file" name="attachments[]" multiple data-no-drop>'
                  . '</label>'
                  // Pasted, picked and dropped files as small thumbnails, each
                  // with an × (David, 2026-10-22). Drawn by assets/board-screen.js.
                  . '<div class="board-review-thumbs" hidden></div>'
                  . '<p class="muted small">Images you paste or attach are added to your review. '
                  . 'Type @ and a name to notify someone (bell).</p>'
                  . '<button type="submit" class="primary board-version-post">Post review</button>'
                  . '</form></details>'
                  . '<details class="board-expand board-upload-instead">'
                  . '<summary><span class="board-version-title">Upload a new version</span></summary>'
                  . $upload . '</details>';
        } else {
            $form = $upload;
        }
        if ($reviewer && $progressId) $form .= board_verdict_html($pdo, $projectId, $stepId, $itemKey, (int)$progressId);
        $form .= '<script type="application/json" class="board-comment-people">'
               . json_encode(array_map(fn($p) => ['name' => $p['name']], $people),
                             JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
               . '</script>';
    }
    return $form . $out;
}

/**
 * APPROVE OR RETAKE, IN THE VIEWER OPENED FROM A TASK (David, 2026-10-29: "let's
 * also include the approve or retake buttons here for supervisors and
 * production"). The Reviews screen hands the viewer its own two buttons; Spell's
 * panel and My tasks open it from the Versions pane, so the pane carries this
 * hidden marker for assets/review-viewer.js to build the same two from.
 *
 * Drawn only when this account may give a verdict on this task NOW: a reviewer
 * whose stage (REVIEW_STAGES) starts from the status the task is in, on a show
 * they review. data-to and data-retake are the show's own statuses, empty when
 * the show has none, which leaves that button off. review_apply() checks all of
 * it again when one is pressed.
 */
function board_verdict_html(PDO $pdo, int $projectId, int $stepId, string $itemKey, int $progressId): string {
    require_once __DIR__ . '/review.php';
    if (!review_stages()) return '';
    if (!in_array($projectId, review_project_ids($pdo), true)) return '';
    $q = $pdo->prepare('SELECT st.name FROM progress pr LEFT JOIN statuses st ON st.id = pr.status_id WHERE pr.id = ?');
    $q->execute([$progressId]);
    // Admins and Production review both stages (2026-10-29), so the stage is
    // the one the task's status puts it in, as review_apply() works it out.
    $stage = review_stage_of((string)$q->fetchColumn());
    if (!$stage) return '';
    $cfg = REVIEW_STAGES[$stage];
    $to = review_status_named($pdo, $projectId, $cfg['to']);
    $retake = review_status_named($pdo, $projectId, REVIEW_RETAKE);
    return '<div class="board-verdict" hidden data-project="' . $projectId . '" data-step="' . $stepId . '"'
         . ' data-item="' . e($itemKey) . '"'
         . ' data-to="' . e($to ? (string)$to['name'] : '') . '"'
         . ' data-retake="' . e($retake ? (string)$retake['name'] : '') . '"></div>';
}

/* ---------------------------------------------------------------------------
 * THE TASK'S TIMELINE (David, 2026-10-22).
 *
 * "Let's change Activity for Timeline and treat it like a vertical timeline
 * with the most recent at the top ... new versions (with vignettes, clickable to
 * expand), status changes, reassignments, review notes, notes, etc so in every
 * scroll I can access virtually anything that will open with a pop up so I
 * don't have to leave the timeline. Everything should be interactive."
 *
 * ⚠ NOTHING NEW IS STORED FOR THIS EXCEPT THE ROW CHANGES (migration 066). Every
 * other entry is read from where it already lives — progress_log for statuses,
 * progress_versions for versions, ticket_comments for comments and reviews — so
 * the timeline can never disagree with the tab that owns the thing.
 * ------------------------------------------------------------------------ */

/**
 * Everything that happened to one task, NEWEST FIRST, each entry
 * ['at' => 'Y-m-d H:i:s', 'type' => status|version|comment|review|assignee|due|notes|link, ...].
 */
function board_task_timeline(PDO $pdo, int $projectId, int $stepId, string $itemKey): array {
    $out = [];
    $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);

    // Statuses: the same rows the old Activity tab drew, all of them now.
    if (function_exists('activity_available') && activity_available($pdo)) {
        try {
            $q = $pdo->prepare(
                'SELECT id, old_status_name, new_status_name, change_type, source, changed_by,
                        crew_name, created_at
                   FROM progress_log
                  WHERE project_id = ? AND step_id = ? AND item_key = ?'
            );
            $q->execute([$projectId, $stepId, $itemKey]);
            foreach ($q as $r) {
                $out[] = ['at' => (string)$r['created_at'], 'type' => 'status', 'sort' => (int)$r['id'],
                          'who' => $r['changed_by'] ?: ($r['crew_name'] ?: 'Somebody'), 'row' => $r];
            }
        } catch (PDOException $e) {
            // before migration 021
        }
    }

    if ($pid > 0) {
        foreach (board_task_versions($pdo, $pid) as $v) {
            $out[] = ['at' => (string)$v['created_at'], 'type' => 'version', 'sort' => (int)$v['id'],
                      'who' => $v['display_name'] ?? 'Someone', 'row' => $v];
        }

        $talk = board_task_comments($pdo, $pid);
        foreach ($talk['comments'] as $c) {
            $cid = (int)$c['id'];
            $out[] = ['at' => (string)$c['created_at'],
                      'type' => !empty($c['review_of']) ? 'review' : 'comment', 'sort' => $cid,
                      'who' => $c['display_name'] ?? 'Someone', 'row' => $c,
                      'named' => $talk['mentions'][$cid] ?? [], 'files' => $talk['files'][$cid] ?? []];
        }

        if (function_exists('task_events_available') && task_events_available($pdo)) {
            $q = $pdo->prepare('SELECT * FROM progress_events WHERE progress_id = ?');
            $q->execute([$pid]);
            foreach ($q as $r) {
                $out[] = ['at' => (string)$r['created_at'], 'type' => (string)$r['kind'], 'sort' => (int)$r['id'],
                          'who' => $r['actor'] ?: 'Somebody', 'row' => $r];
            }
        }
    }

    // Newest first; within one second, by the order things were written.
    usort($out, fn($a, $b) => [$b['at'], $b['sort']] <=> [$a['at'], $a['sort']]);
    return $out;
}

/**
 * The Timeline tab.
 *
 * Every entry is a button-like row: a dot on the line, a small date, one line
 * saying what happened. Clicking it opens a pop-up over the panel with the whole
 * thing — the full note, the full-size version, the files on a review, the
 * before and after of a change — built from a <template> carried in the entry,
 * so opening one costs no round trip. Thumbnails and files inside open the
 * lightbox the rest of the board uses (`att-zoom`).
 */
function board_timeline_html(PDO $pdo, int $projectId, int $stepId, string $itemKey): string {
    $entries = board_task_timeline($pdo, $projectId, $stepId, $itemKey);
    $eventsOn = function_exists('task_events_available') && task_events_available($pdo);

    $when = fn(string $at) => e(date('j M Y · g:i A', strtotime($at)));
    $val  = fn($v, string $none) => trim((string)$v) !== '' ? e((string)$v) : '<span class="muted">' . e($none) . '</span>';

    $labels = [
        'status' => 'Status', 'version' => 'New version', 'comment' => 'Comment', 'review' => 'Review notes',
        'assignee' => 'Reassigned', 'due' => 'Due date', 'notes' => 'Task note', 'link' => 'Link',
    ];

    // At the top, before anything in the feed: the history of one task is a
    // thing people send each other ("look at what happened on FX"), and unlike
    // a comment or a version it has no row of its own to hang a button on.
    $out = '<div class="tl-bar">'
         . board_copy_button(
               board_task_path($projectId, $stepId, $itemKey, 'timeline'),
               'this timeline', true
           )
         . '</div>';
    if (!$entries) {
        $out .= '<p class="muted small">Nothing has happened on this task yet. Status changes, versions, '
              . 'comments and review notes appear here as they happen, newest at the top.</p>';
    }
    if (!$eventsOn) {
        $out .= '<p class="muted small">Reassignments, due dates and note changes start appearing once '
              . 'database update 066 has been applied.</p>';
    }
    if (!$entries) return $out;


    $out .= '<ol class="tl-feed">';
    foreach ($entries as $i => $en) {
        $r = $en['row'];
        $type = $en['type'];
        $who = e((string)$en['who']);
        $title = '';      // the one line in the feed
        $preview = '';    // a little more, under it
        $pop = '';        // the whole thing, in the pop-up

        switch ($type) {
            case 'status':
                $from = trim((string)$r['old_status_name']);
                $to = trim((string)$r['new_status_name']) ?: 'Not started';
                $title = '<strong>' . $who . '</strong> moved it to <strong>' . e($to) . '</strong>';
                $pop = '<p class="tl-pop-change">' . ($from !== '' ? e($from) : '<span class="muted">Not started</span>')
                     . ' <span class="panel-sep">→</span> <strong>' . e($to) . '</strong></p>'
                     . '<p class="muted small">By ' . $who
                     . (($r['source'] ?? '') === 'import' ? ', from the Plaky import' : '')
                     . (($r['change_type'] ?? '') === 'retake' ? '. This sent it back: a retake.' : '.') . '</p>';
                break;

            case 'version':
                $vid = (int)$r['id'];
                $url = 'attachment.php?kind=version&amp;id=' . $vid;
                $name = e((string)$r['original_name']);
                $label = e(version_label((int)$r['version_no']));
                $isImg = attachment_is_image((string)($r['mime_type'] ?? ''));
                $title = '<strong>' . $who . '</strong> sent <strong>' . $label . '</strong> for review';
                if ($isImg) {
                    // The vignette: clicking it expands it in the lightbox, the
                    // rest of the entry opens the pop-up.
                    $preview .= '<a href="' . $url . '" class="att-zoom tl-thumb" data-name="' . $name
                              . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . $name . '"></a>';
                }
                if (trim((string)($r['note'] ?? '')) !== '') {
                    $preview .= '<p class="tl-snippet">' . e(mb_strimwidth((string)$r['note'], 0, 140, '…')) . '</p>';
                }
                $media = $isImg
                    ? '<a href="' . $url . '" class="att-zoom tl-pop-media" data-name="' . $name
                      . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . $name . '"></a>'
                    : (attachment_is_video((string)($r['mime_type'] ?? ''))
                        ? '<video class="tl-pop-media" src="' . $url . '" controls preload="metadata"></video>'
                        : '<a href="' . $url . '" class="file-link" target="_blank" rel="noopener">📄 ' . $name . '</a>');
                $pop = $media
                     . (trim((string)($r['note'] ?? '')) !== ''
                        ? '<p class="tl-pop-text"><strong>What changed:</strong> ' . nl2br(e((string)$r['note'])) . '</p>' : '')
                     . '<p class="muted small">' . $name . ' · ' . e(human_size((int)$r['file_size'])) . '</p>'
                     . '<div class="tl-pop-actions">'
                     . (trim((string)($r['link'] ?? '')) !== ''
                        ? '<a class="version-files-btn" href="' . e((string)$r['link']) . '" target="_blank" rel="noopener">Open final files</a>' : '')
                     . '<button type="button" class="panel-tab-link tl-go" data-tab="versions">See it in Versions and notes</button>'
                     . '</div>';
                break;

            case 'comment':
            case 'review':
                $body = (string)$r['body'];
                $isReview = $type === 'review';
                $title = '<strong>' . $who . '</strong> '
                       . ($isReview ? 'left review notes on <strong>' . e(version_label((int)$r['review_of'])) . '</strong>'
                                    : 'commented');
                if ($body !== '(attachment)') {
                    $preview .= '<p class="tl-snippet">' . e(mb_strimwidth($body, 0, 140, '…')) . '</p>';
                }
                $files = '';
                foreach ($en['files'] as $a) {
                    ob_start();
                    render_attachment($a, false);
                    $files .= ob_get_clean();
                }
                if ($en['files']) {
                    $preview .= '<span class="tl-files muted small">📎 ' . count($en['files']) . ' file'
                              . (count($en['files']) === 1 ? '' : 's') . '</span>';
                }
                $pop = ($body !== '(attachment)'
                        ? '<div class="comment-body tl-pop-text">' . ticket_render_body($body, $en['named']) . '</div>' : '')
                     . ($files !== '' ? '<div class="attachment-grid">' . $files . '</div>' : '')
                     . '<div class="tl-pop-actions"><button type="button" class="panel-tab-link tl-go" data-tab="'
                     . ($isReview ? 'versions' : 'comments') . '">'
                     . ($isReview ? 'See it in Versions and notes' : 'Reply in Comments') . '</button></div>';
                break;

            case 'assignee':
                $title = '<strong>' . $who . '</strong> '
                       . (trim((string)$r['new_value']) !== ''
                          ? 'handed it to <strong>' . e((string)$r['new_value']) . '</strong>'
                          : 'took it off ' . $val($r['old_value'], 'nobody'));
                $pop = '<p class="tl-pop-change">' . $val($r['old_value'], 'Nobody') . ' <span class="panel-sep">→</span> <strong>'
                     . $val($r['new_value'], 'Nobody') . '</strong></p>';
                break;

            case 'due':
                $fmt = fn($d) => trim((string)$d) !== '' ? e(date('j M Y', strtotime((string)$d))) : '';
                $title = '<strong>' . $who . '</strong> '
                       . (trim((string)$r['new_value']) !== '' ? 'set it due <strong>' . $fmt($r['new_value']) . '</strong>'
                                                                : 'cleared the due date');
                $pop = '<p class="tl-pop-change">' . ($fmt($r['old_value']) ?: '<span class="muted">No date</span>')
                     . ' <span class="panel-sep">→</span> <strong>' . ($fmt($r['new_value']) ?: 'No date') . '</strong></p>';
                break;

            case 'notes':
            case 'link':
                $what = $type === 'notes' ? 'the task note' : 'the link';
                $title = '<strong>' . $who . '</strong> '
                       . (trim((string)$r['new_value']) !== '' ? 'changed ' . $what : 'cleared ' . $what);
                if (trim((string)$r['new_value']) !== '') {
                    $preview .= '<p class="tl-snippet">' . e(mb_strimwidth((string)$r['new_value'], 0, 140, '…')) . '</p>';
                }
                $show = function ($v) use ($type, $val) {
                    if (trim((string)$v) === '') return '<span class="muted">Empty</span>';
                    return $type === 'link'
                        ? '<a href="' . e((string)$v) . '" target="_blank" rel="noopener">' . e((string)$v) . '</a>'
                        : nl2br(e((string)$v));
                };
                $pop = '<p class="muted small">Before</p><div class="tl-pop-text tl-was">' . $show($r['old_value']) . '</div>'
                     . '<p class="muted small">After</p><div class="tl-pop-text">' . $show($r['new_value']) . '</div>';
                break;

            default:
                continue 2;
        }

        $out .= '<li class="tl-entry tl-' . e($type) . '" tabindex="0" role="button"'
              . ' aria-label="' . e(strip_tags($title)) . ', ' . $when($en['at']) . '. Open details.">'
              . '<span class="tl-dot" aria-hidden="true"></span>'
              . '<div class="tl-body">'
              . '<div class="tl-meta"><span class="tl-kind">' . e($labels[$type] ?? $type) . '</span>'
              . '<span class="tl-when">' . $when($en['at']) . '</span></div>'
              . '<div class="tl-title">' . $title . '</div>' . $preview
              . '</div>'
              . '<template class="tl-pop"><div class="tl-pop-head"><span class="tl-kind">'
              . e($labels[$type] ?? $type) . '</span><span class="tl-when">' . $when($en['at']) . '</span></div>'
              . '<h3 class="tl-pop-title">' . $title . '</h3>' . $pop . '</template>'
              . '</li>';
    }
    return $out . '</ol>';
}
