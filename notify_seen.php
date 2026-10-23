<?php
// "I have read my bell."
//
// David, 2026-10-22: "you need to get a notification in a bell close to your
// name". This is the half that makes the count mean something — without it the
// number only ever goes up.
//
// ⚠ ON OPENING THE BELL, not on opening the task, which is the opposite of what
// migration 040 chose for a mention on a ticket. The difference is real. A
// mention count on My tasks is a POINTER — it says go and read something that
// lives elsewhere, so clearing it because somebody glanced at a screen loses
// the only signal telling them where to go. The bell is not a pointer: the list
// inside it IS the message, and "Ana assigned you BG Layout on SH010" has been
// delivered the moment it has been read. Each row still links through to the
// task for when you want more than the sentence.

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

// Your own bell and nobody else's: the crew id comes from the session, never
// from the request. There is no id to post here, which is the simplest way for
// there to be no id to forge.
$crewId = (int)(effective_crew_id($pdo) ?? 0);
echo json_encode(['ok' => true, 'cleared' => notify_mark_seen($pdo, $crewId)]);
