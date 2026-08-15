<?php
// Setting one cell's status from the tracking grid.
//
// STFC's preprod_api.php toggled a boolean. Here a cell is a status_id pointing
// at the project's own vocabulary, so this writes that — and, crucially, also
// recomputes progress.is_delivered / is_approved, which are what every dashboard
// figure counts. Writing status_id alone would change the colour on screen and
// leave every number on the Dashboard unmoved, which is worse than not writing
// at all: it would look like it worked.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/grid.php';
require_login_json();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('This endpoint only accepts POST.');
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $stepId    = (int)($_POST['step_id'] ?? 0);
    $itemKey   = trim($_POST['item_key'] ?? '');
    $rawStatus = $_POST['status_id'] ?? '';
    $statusId  = ($rawStatus === '' || $rawStatus === null) ? null : (int)$rawStatus;

    if (!$projectId || !$stepId || $itemKey === '') {
        throw new RuntimeException('A project, a step and an item are all required.');
    }

    // The step must belong to the project named. Without this an id from another
    // show would be accepted and written into a project it has nothing to do with.
    $chk = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $chk->execute([$stepId, $projectId]);
    $step = $chk->fetch();
    if (!$step) throw new RuntimeException('That step is not part of this project.');

    // Same for the status.
    $status = null;
    if ($statusId !== null) {
        $map = statuses_by_id($pdo, $projectId);
        if (!isset($map[$statusId])) throw new RuntimeException('That status is not part of this project.');
        $status = $map[$statusId];
    }

    // item_key is the convention the importer and the scheduler already use:
    // 's' + shot id, 'a' + asset id, 'p' + project id for a whole-show
    // deliverable. Parsed rather than trusted, and the row it names must belong
    // to this project.
    $kind = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    if (!in_array($kind, ['s', 'a', 'p'], true) || $itemId <= 0) {
        throw new RuntimeException('That item reference is not one this screen can set.');
    }

    $scope = ['s' => 'shot', 'a' => 'asset', 'p' => 'project'][$kind];
    $itemRow = ['asset_type' => null];

    if ($kind === 'p') {
        // The only valid whole-show key is this project's own.
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

    // Does the step actually cover this item? The grid greys those cells out, but
    // the check belongs here too — the screen is a convenience, not the guard.
    if (!step_covers_item($step, $itemRow, $scope)) {
        throw new RuntimeException('"' . $step['name'] . '" does not apply to that item.');
    }

    $flags = status_flags($status);

    // One row per (step, item), enforced by UNIQUE (step_id, item_key) and set by
    // the application on every insert — the 2026-08-11 item_key decision. This is
    // an upsert because a cell may never have been imported at all.
    $pdo->prepare(
        'INSERT INTO progress (project_id, step_id, shot_id, asset_id, item_key,
                               status_id, is_delivered, is_approved)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             status_id    = VALUES(status_id),
             is_delivered = VALUES(is_delivered),
             is_approved  = VALUES(is_approved)'
    )->execute([
        // Both item columns stay NULL for a whole-show deliverable — it is
        // neither a shot nor an asset, and item_key is what identifies it. Same
        // shape the importer writes.
        $projectId, $stepId,
        $kind === 's' ? $itemId : null,
        $kind === 'a' ? $itemId : null,
        $itemKey, $statusId, $flags['is_delivered'], $flags['is_approved'],
    ]);

    echo json_encode([
        'ok'      => true,
        'color'   => $status ? ($status['color'] ?: '#8a97a3') : null,
        'name'    => $status['name'] ?? null,
        'done'    => $status !== null && ($status['maps_to'] ?? '') === 'approved',
        'na'      => $status !== null && (int)($status['is_excluded'] ?? 0) === 1,
        'message' => $status ? ('Set to ' . $status['name'] . '.') : 'Cleared.',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
