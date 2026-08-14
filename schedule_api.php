<?php
// Editing a single scheduled task: dragging a bar, resizing it, or saving the
// editor modal. JSON in, JSON out.
//
// Every write here sets is_manual = 1. Moving a bar by hand IS the statement
// that the scheduler should stop deciding this one — if a re-run then shoved it
// back, the drag would look like it hadn't saved, which is the silent-failure
// pattern this app keeps stamping out.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login_json();

header('Content-Type: application/json');

function api_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action !== 'update') api_fail('Unknown action.');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) api_fail('No task given.');

// Scoped to the project the caller says it is editing. Without this, a task id
// from any project would be writable from any other project's page — everything
// else in this app is scoped that way and an endpoint that isn't would be the
// hole that outlasts the habit.
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
if ($projectId <= 0) api_fail('No project given.');

$stmt = $pdo->prepare(
    'SELECT sc.*, ps.name AS step_name
       FROM schedule sc JOIN pipeline_steps ps ON ps.id = sc.step_id
      WHERE sc.id = ? AND sc.project_id = ?'
);
$stmt->execute([$id, $projectId]);
$task = $stmt->fetch();
if (!$task) api_fail('That task no longer exists — reload the page.', 404);

$start = trim($_POST['start_date'] ?? '');
$end   = trim($_POST['end_date'] ?? '');
foreach ([$start, $end] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) api_fail('Dates must be YYYY-MM-DD.');
}
// Caught here as well as in the browser: the fetch can be replayed, and an
// inverted range would store a task that renders as a negative-width bar.
if ($end < $start) api_fail('The end date cannot be before the start date.');

// crew_id is optional. An empty string means "unassigned" rather than "unchanged"
// — the editor's blank option has to be able to clear it.
$crewId = null;
if (array_key_exists('crew_id', $_POST) && trim((string)$_POST['crew_id']) !== '') {
    $crewId = (int)$_POST['crew_id'];
    $check = $pdo->prepare('SELECT id FROM crew WHERE id = ?');
    $check->execute([$crewId]);
    if (!$check->fetch()) api_fail('That person is not on the crew list.');
}

// is_manual defaults to 1 for the reason in the header comment, but the editor
// can explicitly hand a task back to the scheduler by unticking the lock.
$isManual = array_key_exists('is_manual', $_POST) ? ((int)$_POST['is_manual'] === 1 ? 1 : 0) : 1;

try {
    // source follows is_manual in BOTH directions. Letting it keep saying
    // 'manual' after a task was unlocked left the row in a state that neither
    // the scheduler's delete nor its hand-planned lookup matched — see the
    // comment on the DELETE in commit_schedule().
    $pdo->prepare(
        "UPDATE schedule
            SET start_date = ?, end_date = ?, crew_id = ?, is_manual = ?,
                source = CASE WHEN ? = 1 THEN 'manual' ELSE 'auto' END
          WHERE id = ?"
    )->execute([$start, $end, $crewId, $isManual, $isManual, (int)$task['id']]);
} catch (Throwable $e) {
    api_fail('Could not save: ' . $e->getMessage(), 500);
}

echo json_encode([
    'ok'         => true,
    'id'         => $id,
    'start_date' => $start,
    'end_date'   => $end,
    'crew_id'    => $crewId,
    'is_manual'  => $isManual,
]);
