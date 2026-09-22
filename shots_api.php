<?php
// Linking an asset to a shot, and setting a shot's frame count.
//
// One change per request, saved as you make it — the same shape production_api.php
// uses for a status cell, and for the same reason. A breakdown is dozens of small
// decisions ("is Quark in this one?"), and a screen that made you press Save
// after each would be unusable; one that batched them would lose the lot on a
// dropped connection.
//
// It also keeps the page still. 45 shots with a picker open is a lot of state to
// throw away on a reload every time somebody adds a character.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/breakdown.php';
// Refuses a POST from a view-only account and answers in JSON rather than
// redirecting to a login page a fetch() cannot follow.
require_login_json();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('This endpoint only accepts POST.');
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $shotId    = (int)($_POST['shot_id'] ?? 0);
    $action    = (string)($_POST['action'] ?? '');
    if ($projectId <= 0 || $shotId <= 0) {
        throw new RuntimeException('A project and a shot are both required.');
    }

    // The project must exist. Everything below then checks the shot and the
    // asset against THIS id, so an id from another show cannot be written into
    // a project it has nothing to do with.
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) throw new RuntimeException('That project no longer exists.');

    if ($action === 'link' || $action === 'unlink') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        if ($assetId <= 0) throw new RuntimeException('No asset named.');
        $msg = $action === 'link'
            ? breakdown_link($pdo, $projectId, $shotId, $assetId)
            : breakdown_unlink($pdo, $projectId, $shotId, $assetId);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit;
    }

    if ($action === 'frames') {
        $r = breakdown_set_frames($pdo, $project, $shotId, (string)($_POST['frames'] ?? ''));
        // The derived seconds go back so the row can show what the money will
        // now use, without a reload and without the browser doing the division
        // a second time and rounding it differently.
        echo json_encode([
            'ok'      => true,
            'frames'  => $r['frames'],
            'seconds' => $r['seconds'],
            'label'   => $r['seconds'] !== null ? fmt_num($r['seconds'], 3) . 's' : '',
        ]);
        exit;
    }

    if ($action === 'notes') {
        $r = breakdown_set_note($pdo, $project, $shotId, (string)($_POST['notes'] ?? ''));
        echo json_encode([
            'ok'    => true,
            'notes' => $r['notes'],
            // Said, not silently done: a note cut to fit would otherwise look
            // saved in full until the next page load.
            'message' => $r['cut'] ? 'That note was longer than 2,000 characters, so it was cut.' : '',
        ]);
        exit;
    }

    throw new RuntimeException('Unknown action.');

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
