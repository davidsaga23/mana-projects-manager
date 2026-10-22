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
    // "Started" is anything that is not an untouched cell: a status with no
    // meaning yet counts as not started, which is what a blank Plaky cell means.
    $started = array_filter($live, fn($t) => $t['status_id'] !== null && !$t['is_started_blank']);
    if ($started) return ['name' => 'In progress', 'color' => 'var(--warn)'];

    return ['name' => 'Not started', 'color' => 'var(--violet)'];
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
        'SELECT id, episode, shot_code, frames, placeholder_seconds
           FROM shots WHERE project_id = ? ORDER BY episode, shot_code'
    );
    $shots->execute([$projectId]);
    $shots = $shots->fetchAll();

    $assets = $pdo->prepare(
        "SELECT id, name, COALESCE(asset_type, '') AS asset_type
           FROM assets WHERE project_id = ? ORDER BY asset_type, name"
    );
    $assets->execute([$projectId]);
    $assets = $assets->fetchAll();

    // The cells. LEFT JOIN on crew because an unassigned task is the normal
    // case, not a missing row — 76 of MSD's 147 have nobody on them.
    $cells = $pdo->prepare(
        'SELECT pr.step_id, pr.item_key, pr.status_id, pr.version, pr.notes'
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

    $today = date('Y-m-d');

    /** One task row: a step's work on one item. */
    $task = function (array $step, string $itemKey) use ($byCell, $byDate, $statusMap, $today, $hasLink): array {
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
            // A status that means nothing yet reads as untouched, like a blank
            // cell — it is what "Waiting to start" is for.
            'is_started_blank' => $status ? ($status['maps_to'] ?? 'none') === 'none'
                                            && (int)($status['is_blocked'] ?? 0) === 0 : true,
            'crew_id'     => $cell && $cell['crew_id'] ? (int)$cell['crew_id'] : null,
            'person'      => $cell['person'] ?? null,
            'due'         => $due,
            'due_late'    => $due !== null && $due < $today
                             && !($cell && (int)$cell['is_approved'] === 1),
            'manual'      => $sched ? (int)$sched['is_manual'] === 1 : false,
            'version'     => $cell['version'] ?? null,
            'notes'       => $cell['notes'] ?? null,
            'link'        => $hasLink ? ($cell['link'] ?? null) : null,
        ];
    };

    /** The tasks covering one shot or asset, in pipeline order. */
    $tasksFor = function (array $itemRow, string $scope, string $itemKey) use ($steps, $task): array {
        $out = [];
        foreach ($steps as $step) {
            if (!step_covers_item($step, $itemRow, $scope)) continue;
            $out[] = $task($step, $itemKey);
        }
        return $out;
    };

    $groups = [];
    $counts = ['items' => 0, 'tasks' => 0, 'done' => 0];

    /** Fold one built item into its group, making the group when first seen. */
    $add = function (string $key, string $name, string $color, array $item) use (&$groups, &$counts) {
        if (!isset($groups[$key])) {
            $groups[$key] = ['key' => $key, 'name' => $name, 'color' => $color, 'items' => []];
        }
        $groups[$key]['items'][] = $item;
        $counts['items']++;
        foreach ($item['tasks'] as $t) {
            if ($t['is_excluded']) continue;
            $counts['tasks']++;
            if ($t['is_approved']) $counts['done']++;
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
        $dept = trim((string)($step['department'] ?? '')) ?: 'Whole show';
        $add('d:' . strtolower($dept), $dept, $step['color'] ?: 'var(--danger)', [
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
        $ep = trim((string)($s['episode'] ?? ''));
        $add($ep !== '' ? 'e:' . $ep : 'shots', $ep !== '' ? $ep : 'Shots', 'var(--violet)', [
            'kind'     => 'shot',
            'id'       => (int)$s['id'],
            'item_key' => $itemKey,
            'name'     => (string)$s['shot_code'],
            'scope'    => count($tasks) . ' task' . (count($tasks) === 1 ? '' : 's'),
            'frames'   => $s['frames'] !== null ? (int)$s['frames'] : null,
            'tasks'    => $tasks,
            'self'     => null,
            'status'   => board_item_status($tasks),
        ]);
    }

    // ---- assets ------------------------------------------------------------
    foreach ($assets as $a) {
        $itemKey = 'a' . (int)$a['id'];
        $tasks = $tasksFor(['asset_type' => $a['asset_type']], 'asset', $itemKey);
        if (!$tasks) continue;
        $type = trim((string)$a['asset_type']) ?: 'Assets';
        $add('t:' . strtolower($type), $type, 'var(--primary)', [
            'kind'     => 'asset',
            'id'       => (int)$a['id'],
            'item_key' => $itemKey,
            'name'     => (string)$a['name'],
            'scope'    => count($tasks) . ' task' . (count($tasks) === 1 ? '' : 's'),
            'frames'   => null,
            'tasks'    => $tasks,
            'self'     => null,
            'status'   => board_item_status($tasks),
        ]);
    }

    return [
        'groups'   => array_values($groups),
        'statuses' => array_values($statusMap),
        'counts'   => $counts,
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
              . ((int)($s['is_excluded'] ?? 0) === 1 ? ' data-excluded="1"' : '')
              . ((($s['maps_to'] ?? 'none') === 'none' && (int)($s['is_blocked'] ?? 0) === 0)
                  ? ' data-blank="1"' : '')
              . ($isNow ? ' selected' : '')
              // Shown, so the show's whole vocabulary is visible, but not
              // selectable by somebody who may not set it.
              . ($open ? '' : ' disabled') . '>' . e($s['name']) . '</option>';
    }
    return $out . '</select></div>';
}

/** Who the task belongs to. Production only: an artist cannot hand work about. */
function board_crew_cell(array $t, array $crew, int $projectId, bool $mayEdit): string {
    if (!$mayEdit) {
        $name = $t['person'];
        return '<div class="board-cell">' . ($name
            ? '<span class="board-who"><span class="board-avatar">' . e(board_initials($name))
              . '</span>' . e($name) . '</span>'
            : '<span class="muted">—</span>') . '</div>';
    }
    $out = '<div class="board-cell"><select class="board-crew"' . board_cell_attrs($t, $projectId)
         . ' aria-label="Who is on ' . e($t['step']) . '">'
         . '<option value="">Nobody yet</option>';
    $seen = false;
    foreach ($crew as $c) {
        $id = (int)$c['id'];
        $on = $t['crew_id'] === $id;
        if ($on) $seen = true;
        $out .= '<option value="' . $id . '"' . ($on ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    // Somebody named on the row who is no longer on the crew list still has to
    // appear, or opening this dropdown would quietly unassign them.
    if (!$seen && $t['crew_id'] !== null) {
        $out .= '<option value="' . (int)$t['crew_id'] . '" selected>'
              . e($t['person'] ?: 'Somebody no longer listed') . '</option>';
    }
    return $out . '</select></div>';
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

/** Somebody's initials, for the small round avatar. */
function board_initials(string $name): string {
    $parts = preg_split('/[\s._]+/', trim($name)) ?: [];
    $a = isset($parts[0][0]) ? $parts[0][0] : '';
    $b = isset($parts[1][0]) ? $parts[1][0] : '';
    return mb_strtoupper($a . $b);
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
 * The crew this show's tasks can be handed to: real people on the project,
 * plus anybody already named on one of its rows.
 *
 * Placeholder slots are left out — "Animation 02" is a position, not somebody
 * who can be told a task is theirs. A team row is left out for the same reason.
 */
function board_assignees(PDO $pdo, int $projectId): array {
    $q = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name
           FROM crew c
           LEFT JOIN crew_projects cp ON cp.crew_id = c.id AND cp.project_id = ?
           LEFT JOIN progress pr ON pr.crew_id = c.id AND pr.project_id = ?
          WHERE COALESCE(c.is_placeholder, 0) = 0
            AND COALESCE(c.is_team, 0) = 0
            AND (cp.crew_id IS NOT NULL OR pr.crew_id IS NOT NULL)
          ORDER BY c.name'
    );
    $q->execute([$projectId, $projectId]);
    return $q->fetchAll();
}
