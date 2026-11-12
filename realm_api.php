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
// POST knock=<owner's user id>            knock on an ajar door you are standing at
// POST answer_knock=<knock id>&let=0|1    answer a knock on your own door

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_once __DIR__ . '/includes/realm_chat.php';
require_once __DIR__ . '/includes/uploads.php';  // a picture in a message passes accept_upload()
require_once __DIR__ . '/includes/notify.php';   // a raven for somebody away rings the bell
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

// ---- chat (stage 2, migration 082) ------------------------------------------
// GET  chat=<channel>&after=<id>           what was said, and marks it read
// POST say=<channel>&body=...&after=<id>   say something, then the same answer
//      (+ a file field `image`, one picture, migration 083)
// GET  unread=1                            only the unread counts
//
// Answered here, apart from the map: an open conversation asks every few
// seconds and has no use for where everybody is standing.
$mayAnnounce = can_edit();
// GET unread=1: only the unread counts. David, 2026-11-12, trying ravens with
// Renata: the count beside the sender's name was "not working atm". It was
// arriving with the 25-second heartbeat, which reads as broken when you are
// watching for it. Somebody with no conversation open now asks this every few
// seconds instead; it is one small query and touches nothing.
if (isset($_GET['unread'])) {
    echo json_encode(['ok' => true, 'unread' => realm_chat_available($pdo) ? realm_unread($pdo, $uid, $mayAnnounce) : [],
                      'knocks' => realm_knock_news($pdo, $uid)]);
    exit;
}
$channel = isset($_POST['say']) ? (string)$_POST['say'] : (isset($_GET['chat']) ? (string)$_GET['chat'] : '');
if ($channel !== '') {
    if (!realm_chat_available($pdo)) {
        echo json_encode(['ok' => false, 'error' => 'Chat needs a database update first. An administrator opens migrate.php once.']);
        exit;
    }
    try {
        $may = realm_channel_access($pdo, $uid, $channel, $mayAnnounce);
        if (!$may['read']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'That conversation is not yours to read.']);
            exit;
        }
        $said = '';
        if (isset($_POST['say'])) {
            if (!$may['post']) {
                $said = 'Only Production can write in Announcements.';
            } else {
                // One picture may come with the words (migration 083). A refused
                // picture refuses the whole message, so nothing half-arrives.
                $image = null;
                if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $image = realm_take_image($pdo, $_FILES['image'], $said);
                }
                if ($said === '') $said = realm_say($pdo, $uid, $channel, (string)($_POST['body'] ?? ''), $image);
                if ($said === '' && preg_match('/^dm:(\d+):(\d+)$/', $channel, $m) === 1) {
                    realm_raven_bell($pdo, $uid, (int)$m[1] === $uid ? (int)$m[2] : (int)$m[1]);
                } elseif ($said === '') {
                    realm_tag_bell($pdo, $uid, $channel, (string)($_POST['body'] ?? ''), $mayAnnounce);
                }
            }
        }
        $after = max(0, (int)($_POST['after'] ?? ($_GET['after'] ?? 0)));
        $messages = realm_messages($pdo, $uid, $channel, $after);
        if ($messages) {
            realm_mark_read($pdo, $uid, $channel, (int)$messages[count($messages) - 1]['id']);
            // Read here, so it is no longer news in Anima's bell.
            if (preg_match('/^dm:(\d+):(\d+)$/', $channel, $dm) === 1) {
                realm_raven_bell_clear($pdo, $uid, (int)$dm[1] === $uid ? (int)$dm[2] : (int)$dm[1]);
            }
        }
        $answer = ['ok' => true, 'channel' => $channel, 'messages' => $messages, 'can_post' => $may['post'],
                   'unread' => realm_unread($pdo, $uid, $mayAnnounce), 'knocks' => realm_knock_news($pdo, $uid)];
        if ($said !== '') $answer['error'] = $said;
        echo json_encode($answer);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Chat could not be reached. Try again in a moment.']);
    }
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
        } elseif (isset($_POST['knock'])) {
            $error = realm_knock($pdo, $uid, (int)$_POST['knock']);
        } elseif (isset($_POST['answer_knock'])) {
            $error = realm_knock_answer($pdo, $uid, (int)$_POST['answer_knock'], (string)($_POST['let'] ?? '0') === '1');
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
$state['chat'] = realm_chat_summary($pdo, $uid, $mayAnnounce);
if ($error !== '') $state['error'] = $error;
echo json_encode($state);
