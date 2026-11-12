<?php
// The Realm's one endpoint: the heartbeat, and the four things a person can
// change about THEMSELVES there (their door, their look, where they stand).
//
// David, 2026-11-12, stage 1 of the Realm. Rules and data are in
// includes/realm.php; this file only decides who is asking and what they asked.
//
// ⚠ THERE IS NO USER ID IN ANY REQUEST. Every write is about the asker's own
// row, and the id comes from the session (realm_user_id()). The one id that can
// be posted is the house somebody walks to, and realm_arrive() checks that door
// before letting them inside. That is the second lock; the first is the
// allow-list in artist_may_post(), which names this file and these four fields.
//
// ⚠ NOT INSIDE A PREVIEW. "View as" must not move the real person's character or
// make them look present, so a preview is refused for reading as well as saving.
//
// GET                      the whole map: people and lands.
// POST ping=1&active=0|1   heartbeat; answers with the whole map.
// POST set_door=open|ajar|sealed
// POST set_look=1&cloak=&hat=&pet=&house_kind=
// POST arrive=<place>&inside=0|1

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_login_json();

header('Content-Type: application/json');

$uid = realm_user_id();
// The session is not needed again, and holding it would make this person's other
// tabs wait behind every heartbeat.
session_write_close();

if (in_preview()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'The Realm is not available while you are viewing the app as somebody else.']);
    exit;
}
if (!realm_available($pdo)) {
    echo json_encode(['ok' => false, 'error' => 'The Realm needs a database update first. An administrator opens migrate.php once.']);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['ping'])) {
            realm_ping($pdo, $uid, (string)($_POST['active'] ?? '0') === '1');
        } elseif (isset($_POST['set_door'])) {
            $error = realm_set_door($pdo, $uid, (string)$_POST['set_door']);
        } elseif (isset($_POST['set_look'])) {
            $error = realm_set_look($pdo, $uid, $_POST);
        } elseif (isset($_POST['arrive'])) {
            $error = realm_arrive($pdo, $uid, (string)$_POST['arrive'], (string)($_POST['inside'] ?? '0') === '1');
        } else {
            $error = 'Nothing was asked.';
        }
    } catch (Throwable $e) {
        $error = 'That could not be saved. Try again in a moment.';
    }
}

$state = realm_state($pdo, $uid);
if ($error !== '') $state['error'] = $error;
echo json_encode($state);
