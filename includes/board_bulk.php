<?php
/**
 * Spell's bulk actions: give many tasks to one person, or move many to one status.
 *
 * David, 2026-11-20, once tasks could be ticked to subscribe to them: "let's also
 * add 'Assign' and 'Change status' so we can do bulk actions with those tasks".
 *
 * ⚠ EACH TASK IS SAVED EXACTLY AS ONE CHANGE ON THE BOARD WOULD BE (board_api.php
 * set_crew / set_status): the same progress row, the same is_delivered /
 * is_approved flags every dashboard figure counts, the same progress_log row, the
 * same Timeline entry, the same bell. Only the loop is new. A task already
 * holding the value asked for is left alone and tells nobody, so ticking a whole
 * shot and setting it to In progress does not ring about the tasks that already were.
 *
 * PRODUCTION ONLY (can_edit()), checked by board_api.php before calling here. An
 * artist may set the status of their own work one task at a time, as before; the
 * bulk bar does not offer them these two.
 */

require_once __DIR__ . '/task_follow.php';   // board_tasks_checked(), task_follow_ring()
require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/task_events.php';
require_once __DIR__ . '/notify.php';

/** Write one task's cell, creating its progress row when the importer never made one. */
function board_bulk_upsert(PDO $pdo, int $projectId, array $t, array $set): void {
    $cols = array_keys($set);
    $sql = 'INSERT INTO progress (project_id, step_id, shot_id, asset_id, item_key, '
         . implode(', ', $cols) . ') VALUES (?, ?, ?, ?, ?, '
         . implode(', ', array_fill(0, count($cols), '?')) . ')'
         . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols));
    $pdo->prepare($sql)->execute(array_merge([
        $projectId, $t['step_id'],
        $t['kind'] === 's' ? $t['item_id'] : null,
        $t['kind'] === 'a' ? $t['item_id'] : null,
        $t['item_key'],
    ], array_values($set)));
}

/** The task's progress row as it stands, or null. */
function board_bulk_cell(PDO $pdo, array $t): ?array {
    $q = $pdo->prepare('SELECT * FROM progress WHERE step_id = ? AND item_key = ?');
    $q->execute([$t['step_id'], $t['item_key']]);
    return $q->fetch() ?: null;
}

/**
 * Move every task to one status (null = clear it).
 * @return array{changed:int, same:int, skipped:int, name:?string, color:string}
 */
function board_bulk_status(PDO $pdo, int $projectId, array $tasks, ?int $statusId): array {
    $map = statuses_by_id($pdo, $projectId);
    if ($statusId !== null && !isset($map[$statusId])) {
        throw new RuntimeException('That status is not part of this show.');
    }
    $status = $statusId !== null ? $map[$statusId] : null;
    $flags = status_flags($status);
    $me = (int)(effective_crew_id($pdo) ?? 0);

    $checked = board_tasks_checked($pdo, $projectId, $tasks);
    $changed = 0;
    $same = 0;
    foreach ($checked['tasks'] as $t) {
        $cell = board_bulk_cell($pdo, $t);
        $was = $cell && $cell['status_id'] !== null ? (int)$cell['status_id'] : null;
        if ($was === $statusId) { $same++; continue; }

        board_bulk_upsert($pdo, $projectId, $t, [
            'status_id'    => $statusId,
            'is_delivered' => $flags['is_delivered'],
            'is_approved'  => $flags['is_approved'],
        ]);
        $pid = (int)(board_bulk_cell($pdo, $t)['id'] ?? 0);

        notify_add($pdo, notify_subscribers($pdo, $pid), [
            'kind'        => 'status',
            'actor'       => current_user_display(),
            'summary'     => $t['name'] . ' → ' . ($status['name'] ?? 'Waiting to start'),
            'project_id'  => $projectId,
            'step_id'     => $t['step_id'],
            'item_key'    => $t['item_key'],
            'progress_id' => $pid,
        ], $me);

        log_status_change_by_id($pdo, $projectId, $t['step'], $t['item_key'], $was, $statusId, [
            'item_name'  => null,
            'shot_id'    => $t['kind'] === 's' ? $t['item_id'] : null,
            'asset_id'   => $t['kind'] === 'a' ? $t['item_id'] : null,
            'source'     => 'board',
            'changed_by' => current_user_display(),
        ]);
        $changed++;
    }
    return [
        'changed' => $changed, 'same' => $same, 'skipped' => $checked['skipped'],
        'name' => $status['name'] ?? null, 'color' => $status ? (string)($status['color'] ?: '') : '',
        'approved' => $flags['is_approved'] === 1,
    ];
}

/**
 * Give every task to one person (null = nobody).
 * @return array{changed:int, same:int, skipped:int, person:?string}
 */
function board_bulk_crew(PDO $pdo, int $projectId, array $tasks, ?int $crewId): array {
    $name = null;
    if ($crewId !== null) {
        // A real person, never a slot or a team: the rule set_crew keeps.
        $q = $pdo->prepare('SELECT name FROM crew
                             WHERE id = ? AND COALESCE(is_placeholder, 0) = 0 AND COALESCE(is_team, 0) = 0');
        $q->execute([$crewId]);
        $name = $q->fetchColumn() ?: null;
        if ($name === null) throw new RuntimeException('That is not somebody a task can be given to.');
    }
    $me = (int)(effective_crew_id($pdo) ?? 0);
    $names = [];
    $nameOf = function (?int $id) use ($pdo, &$names): ?string {
        if (!$id) return null;
        if (!array_key_exists($id, $names)) {
            $q = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
            $q->execute([$id]);
            $names[$id] = $q->fetchColumn() ?: null;
        }
        return $names[$id];
    };

    $checked = board_tasks_checked($pdo, $projectId, $tasks);
    $changed = 0;
    $same = 0;
    foreach ($checked['tasks'] as $t) {
        $cell = board_bulk_cell($pdo, $t);
        $was = $cell && $cell['crew_id'] !== null ? (int)$cell['crew_id'] : null;
        if ($was === $crewId) { $same++; continue; }

        board_bulk_upsert($pdo, $projectId, $t, ['crew_id' => $crewId]);
        $pid = (int)(board_bulk_cell($pdo, $t)['id'] ?? 0);
        task_event_log($pdo, $pid, 'assignee', $nameOf($was), $name);

        // Only the new owner, as set_crew does: clearing tells nobody.
        if ($crewId !== null) {
            notify_add($pdo, [$crewId], [
                'kind'        => 'assigned',
                'actor'       => current_user_display(),
                'summary'     => $t['name'],
                'project_id'  => $projectId,
                'step_id'     => $t['step_id'],
                'item_key'    => $t['item_key'],
                'progress_id' => $pid,
            ], $me);
        }
        task_follow_ring($pdo, $projectId, $t['step_id'], $t['item_key'], $pid, $t['name'],
            $crewId !== null ? ('given to ' . $name) : 'nobody on it now',
            $crewId !== null ? [$crewId] : []);
        $changed++;
    }
    return ['changed' => $changed, 'same' => $same, 'skipped' => $checked['skipped'], 'person' => $name];
}
