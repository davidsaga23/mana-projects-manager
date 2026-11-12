<?php
// "Is there anything new in my bell?"
//
// David, 2026-11-12, after trying ravens with Renata: the bell "only refreshes
// when a page loads ... Can we change that behavior to be more real-time and
// get those notifications?" Until now the bell was drawn once, by
// includes/header.php, and stayed as it was until the next page: somebody who
// kept one screen open all morning learned of an assignment at lunch.
//
// This is the question assets/bell.js now asks every 30 seconds while the page
// is in front of somebody. It answers with the same count and the same list the
// header draws, built by the same functions, so the two can never disagree.
//
// ⚠ READ-ONLY. Asking does NOT mark anything read: that is still only what
// opening the bell does (notify_seen.php). A count that cleared itself because
// a page was open would be a bell that never rings.
//
// ⚠ YOUR OWN BELL AND NOBODY ELSE'S, as in notify_seen.php: the crew id comes
// from the session, never from the request. There is nothing to post and
// nothing to forge.
//
// One thing this changes about "Last seen" on the Users page: a page left open
// and in front now counts as that person being here, because this request
// passes through the same login gate as any other (throttled to once in five
// minutes there). A page in a background tab does not ask, so it does not count.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';
require_login_json();

header('Content-Type: application/json');
// Not needed again, and holding it would make this person's other tabs wait.
session_write_close();

if (function_exists('feature_on') && !feature_on('bell')) {
    echo json_encode(['ok' => false]);
    exit;
}

$crewId = (int)(effective_crew_id($pdo) ?? 0);
$items = [];
foreach (notify_recent($pdo, $crewId) as $n) {
    $items[] = [
        'id'      => (int)$n['id'],
        'href'    => notify_link($n),
        'kind'    => (string)$n['kind'],
        'actor'   => (string)($n['actor'] ?: 'Somebody'),
        'verb'    => notify_verb((string)$n['kind']),
        'summary' => (string)($n['summary'] ?: 'a task'),
        'when'    => date('j M', strtotime((string)$n['created_at'])),
        'new'     => $n['seen_at'] === null,
    ];
}
echo json_encode(['ok' => true, 'unseen' => notify_unseen($pdo, $crewId), 'items' => $items]);
