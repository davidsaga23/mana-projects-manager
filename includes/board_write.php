<?php
// SAVING ONE TASK'S STATUS OR ASSIGNEE — the one copy of how that is done.
//
// Moved out of board_api.php on 2026-11-19, unchanged in behaviour, so that a
// second screen could save a status the same way Spell does. That screen is the
// Google Sheet catch-up (sheet_backup.php): after an outage the team's edits in
// the backup sheet come back into Anima through THESE functions, so the task's
// history, its Timeline and everybody's bell read exactly as if the change had
// been made on Spell. David, 2026-11-19, after a morning he could not open
// Anima: he wanted a spreadsheet to fall back on, and a change made there must
// not be a second-class change once it is brought back.
//
// ⚠ PERMISSION IS THE CALLER'S. These functions check that the ids belong
// together (the department is this show's, the item is this show's, the status
// is this show's) but not WHO is asking: board_api.php keeps the artist rules
// (status_self_serve(), "is it yours?") beside the code that answers the
// browser, and sheet_backup.php is Production-only. Keep it that way, so the
// rules are read in the file that receives the request.
//
// ⚠ A WRITE TO `progress` MUST ALSO RECOMPUTE is_delivered / is_approved, the
// flags every dashboard figure counts. production_api.php learned this: writing
// status_id alone moves the colour and leaves every number unchanged.
//
// Needs includes/auth.php loaded by the caller (current_user_display(),
// effective_crew_id()), as every screen that saves already does.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/grid.php';         // step_covers_item(), the one scope rule
require_once __DIR__ . '/activity.php';     // log_status_change_by_id()
require_once __DIR__ . '/notify.php';       // the bell (migration 060)
require_once __DIR__ . '/task_events.php';  // the task's Timeline (migration 066)

/**
 * The task named by (project, department, item), checked to belong together,
 * with its progress row as it stands BEFORE anything is written: a log that
 * cannot say what a thing changed FROM is half a log.
 *
 * Every id arrives from outside (a browser, or a sheet anybody could type in),
 * so this is the guard, whatever the screen greyed out.
 *
 * @throws RuntimeException with a sentence fit to show the person
 */
function board_cell_context(PDO $pdo, int $projectId, int $stepId, string $itemKey): array {
    if (!$projectId || !$stepId || $itemKey === '') {
        throw new RuntimeException('A project, a department and an item are all required.');
    }
    $chk = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $chk->execute([$stepId, $projectId]);
    $step = $chk->fetch();
    if (!$step) throw new RuntimeException('That department is not part of this project.');

    $kind   = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    if (!in_array($kind, ['s', 'a', 'p'], true) || $itemId <= 0) {
        throw new RuntimeException('That item reference is not one this screen can set.');
    }
    $scope   = ['s' => 'shot', 'a' => 'asset', 'p' => 'project'][$kind];
    $itemRow = ['asset_type' => null];

    if ($kind === 'p') {
        if ($itemId !== $projectId) {
            throw new RuntimeException('That deliverable belongs to a different project.');
        }
    } else {
        $table = $kind === 's' ? 'shots' : 'assets';
        $own = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND project_id = ?");
        $own->execute([$itemId, $projectId]);
        if (!$own->fetchColumn()) throw new RuntimeException('That item is not part of this project.');
        if ($kind === 'a') {
            $t = $pdo->prepare('SELECT asset_type FROM assets WHERE id = ?');
            $t->execute([$itemId]);
            $itemRow['asset_type'] = $t->fetchColumn() ?: null;
        }
    }

    if (!step_covers_item($step, $itemRow, $scope)) {
        throw new RuntimeException('"' . $step['name'] . '" does not apply to that item.');
    }

    $cur = $pdo->prepare('SELECT * FROM progress WHERE step_id = ? AND item_key = ?');
    $cur->execute([$stepId, $itemKey]);

    return [
        'project_id' => $projectId,
        'step_id'    => $stepId,
        'item_key'   => $itemKey,
        'step'       => $step,
        'kind'       => $kind,
        'item_id'    => $itemId,
        'scope'      => $scope,
        'cell'       => $cur->fetch() ?: null,
    ];
}

/** Write the cell, creating it when the importer never made one. */
function board_cell_upsert(PDO $pdo, array $ctx, array $set): void {
    $cols  = array_keys($set);
    $place = implode(', ', array_fill(0, count($cols), '?'));
    $dupes = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols));
    $sql = 'INSERT INTO progress (project_id, step_id, shot_id, asset_id, item_key, '
         . implode(', ', $cols) . ') VALUES (?, ?, ?, ?, ?, ' . $place . ')'
         . ' ON DUPLICATE KEY UPDATE ' . $dupes;
    $pdo->prepare($sql)->execute(array_merge([
        $ctx['project_id'], $ctx['step_id'],
        $ctx['kind'] === 's' ? $ctx['item_id'] : null,
        $ctx['kind'] === 'a' ? $ctx['item_id'] : null,
        $ctx['item_key'],
    ], array_values($set)));
}

/** The row's id after a write, which an upsert does not hand back. */
function board_cell_progress_id(PDO $pdo, array $ctx): int {
    $q = $pdo->prepare('SELECT id FROM progress WHERE step_id = ? AND item_key = ?');
    $q->execute([$ctx['step_id'], $ctx['item_key']]);
    return (int)$q->fetchColumn();
}

/**
 * What this task is called, for the sentence in somebody's bell.
 *
 * ⚠ READ ONCE AND STORED IN THE NOTIFICATION, never re-derived later — the same
 * choice migration 021 made for progress_log, and migration 060 repeats: a
 * notification is a record of a moment, and a department renamed next week must
 * not rewrite what it said today.
 */
function board_cell_task_name(PDO $pdo, array $ctx): string {
    $name = '';
    if ($ctx['kind'] === 's') {
        $q = $pdo->prepare('SELECT shot_code FROM shots WHERE id = ?');
        $q->execute([$ctx['item_id']]);
        $name = (string)$q->fetchColumn();
    } elseif ($ctx['kind'] === 'a') {
        $q = $pdo->prepare('SELECT name FROM assets WHERE id = ?');
        $q->execute([$ctx['item_id']]);
        $name = (string)$q->fetchColumn();
    }
    return $name !== '' ? ($ctx['step']['name'] . ' on ' . $name) : (string)$ctx['step']['name'];
}

/**
 * The status this show calls $statusId, or null for "not started".
 *
 * @throws RuntimeException when the id is not one of this show's statuses
 */
function board_status_for(PDO $pdo, int $projectId, ?int $statusId): ?array {
    if ($statusId === null) return null;
    $map = statuses_by_id($pdo, $projectId);
    if (!isset($map[$statusId])) throw new RuntimeException('That status is not part of this project.');
    return $map[$statusId];
}

/**
 * Set one task's status: the flags the dashboards count, everybody following
 * the task told, and the change logged. $source says where it was done
 * ('board' for Spell, 'sheet' for the Google Sheet catch-up), up to 20 letters.
 *
 * @return array ['status' => ?array, 'flags' => array]
 */
function board_set_status(PDO $pdo, array $ctx, ?int $statusId, string $source = 'board'): array {
    $projectId = (int)$ctx['project_id'];
    $status = board_status_for($pdo, $projectId, $statusId);
    $cell = $ctx['cell'];

    $was = $cell && $cell['status_id'] !== null ? (int)$cell['status_id'] : null;
    $flags = status_flags($status);
    board_cell_upsert($pdo, $ctx, [
        'status_id'    => $statusId,
        'is_delivered' => $flags['is_delivered'],
        'is_approved'  => $flags['is_approved'],
    ]);

    // Everybody following this task, which is nobody to tick and nobody to
    // forget to tick — see notify_subscribers(). The person who moved it is
    // dropped inside notify_add(), so this cannot ring your own bell.
    $pid = board_cell_progress_id($pdo, $ctx);
    notify_add($pdo, notify_subscribers($pdo, $pid), [
        'kind'        => 'status',
        'actor'       => current_user_display(),
        'summary'     => board_cell_task_name($pdo, $ctx) . ' → ' . ($status['name'] ?? 'Not started'),
        'project_id'  => $projectId,
        'step_id'     => (int)$ctx['step_id'],
        'item_key'    => $ctx['item_key'],
        'progress_id' => $pid,
    ], (int)(effective_crew_id($pdo) ?? 0));

    log_status_change_by_id($pdo, $projectId, $ctx['step'], $ctx['item_key'], $was, $statusId, [
        'item_name'  => null,
        'shot_id'    => $ctx['kind'] === 's' ? $ctx['item_id'] : null,
        'asset_id'   => $ctx['kind'] === 'a' ? $ctx['item_id'] : null,
        'source'     => $source,
        'changed_by' => current_user_display(),
    ]);

    return ['status' => $status, 'flags' => $flags];
}

/**
 * Give one task to somebody, or to nobody (null).
 *
 * A real person, never a slot: "Animation 02" is a position, and a task cannot
 * be told it belongs to one.
 *
 * @return array ['crew_id' => ?int, 'person' => ?string]
 * @throws RuntimeException when $crewId is not somebody a task can be given to
 */
function board_set_crew(PDO $pdo, array $ctx, ?int $crewId): array {
    $name = null;
    if ($crewId !== null) {
        $q = $pdo->prepare(
            'SELECT name FROM crew
              WHERE id = ? AND COALESCE(is_placeholder, 0) = 0 AND COALESCE(is_team, 0) = 0'
        );
        $q->execute([$crewId]);
        $name = $q->fetchColumn();
        if (!$name) throw new RuntimeException('That is not somebody a task can be given to.');
    }
    board_cell_upsert($pdo, $ctx, ['crew_id' => $crewId]);

    // On the task's Timeline: from whom to whom, in names, read now.
    $cell = $ctx['cell'];
    $wasName = null;
    if ($cell && $cell['crew_id']) {
        $w = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
        $w->execute([(int)$cell['crew_id']]);
        $wasName = $w->fetchColumn() ?: null;
    }
    task_event_log($pdo, board_cell_progress_id($pdo, $ctx), 'assignee', $wasName, $crewId !== null ? $name : null);

    // ⚠ ONLY THE NEW OWNER, and only when there is one. Clearing an assignee
    // tells nobody: "you no longer have this" is not news anybody needs at the
    // top of their screen, and the person who DID have it finds out the moment
    // they look at the board.
    if ($crewId !== null) {
        notify_add($pdo, [$crewId], [
            'kind'        => 'assigned',
            'actor'       => current_user_display(),
            'summary'     => board_cell_task_name($pdo, $ctx),
            'project_id'  => (int)$ctx['project_id'],
            'step_id'     => (int)$ctx['step_id'],
            'item_key'    => $ctx['item_key'],
            'progress_id' => board_cell_progress_id($pdo, $ctx),
        ], (int)(effective_crew_id($pdo) ?? 0));
    }

    return ['crew_id' => $crewId, 'person' => $crewId !== null ? (string)$name : null];
}
