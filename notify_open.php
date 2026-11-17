<?php
// "I clicked this one."
//
// Tim, 2026-11-17: "is it possible to have the notifications (once clicked on)
// get cleared from the list?" David: "a collapsible notification for past/read
// notifications". assets/bell.js sends this (as a beacon, so it survives the page
// changing) when a row of the bell is clicked; the row then moves under "Earlier"
// (migration 103, notifications.opened_at).
//
// ⚠ YOUR OWN NOTIFICATIONS AND NOBODY ELSE'S. The id comes from the request, so
// the update also requires crew_id to be the session's own: an id that is not
// yours changes nothing (notify_mark_opened()). It is on the artist allow-list in
// includes/auth.php for that reason, field `id`.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';
require_login_json();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This endpoint only accepts POST.']);
    exit;
}
// Not inside "View as": opening somebody else's notifications is not theirs to record.
if (function_exists('in_preview') && in_preview()) {
    echo json_encode(['ok' => true, 'opened' => 0]);
    exit;
}

$crewId = (int)(effective_crew_id($pdo) ?? 0);
$id = (int)($_POST['id'] ?? 0);
echo json_encode(['ok' => true, 'opened' => notify_mark_opened($pdo, $crewId, $id)]);
