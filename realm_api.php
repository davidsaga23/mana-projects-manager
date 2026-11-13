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
// POST voice=join|poll|leave              voice in the room you are inside (further down)

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_once __DIR__ . '/includes/realm_chat.php';
require_once __DIR__ . '/includes/uploads.php';  // a picture in a message passes accept_upload()
require_once __DIR__ . '/includes/notify.php';   // a raven for somebody away rings the bell
require_once __DIR__ . '/includes/realm_focus.php';
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

// ---- the focus desk (David, 2026-11-12) --------------------------------------
// GET  focus=1                       the clock and this person's open work
// POST focus=start&task=p:12|t:34    start Anima's timer on one of their own
// POST focus=stop                    stop it, logging the time as My tasks would
// POST focus=settle                  a focus block ran out: log it, start the break
// POST task_status=<status id>&task=p:12|t:34
//                                    set the status of one of their own, by My tasks' rules
//
// ⚠ task_status IS ITS OWN FIELD, not a fourth word for `focus`, because two
// lists are keyed on the field: artist_may_post() (who may send it) and
// FEATURE_POSTS (the "Set the status of my own task" switch).
//
// The timer code and the card list are only loaded for these requests; the
// heartbeat and chat never need them.
if (isset($_GET['focus']) || isset($_POST['focus']) || isset($_POST['task_status'])) {
    $said = '';
    $bad = '';
    try {
        require_once __DIR__ . '/includes/statuses.php';
        require_once __DIR__ . '/includes/mailer.php';
        require_once __DIR__ . '/includes/tickets.php';
        require_once __DIR__ . '/includes/timelog.php';
        require_once __DIR__ . '/includes/own_status.php';   // the rules My tasks changes a status by
        $crewId = realm_crew_id($pdo, $uid);
        $why = realm_focus_ready($pdo, $crewId);
        if ($why === '' && (isset($_POST['focus']) || isset($_POST['task_status']))) {
            $what = isset($_POST['task_status']) ? 'status' : (string)$_POST['focus'];
            try {
                if ($what === 'status') {
                    $said = realm_focus_set_status($pdo, $uid, $crewId, (string)($_POST['task'] ?? ''), (string)$_POST['task_status']);
                } elseif ($what === 'start') {
                    $said = realm_focus_start($pdo, $uid, $crewId, (string)($_POST['task'] ?? ''));
                } elseif ($what === 'stop') {
                    $said = stop_timer($pdo, $crewId, $uid);
                } elseif ($what === 'settle') {
                    $said = function_exists('pomodoro_settle') ? pomodoro_settle($pdo, $crewId, $uid) : '';
                }
            } catch (PDOException $e) {
                // A PDOException IS a RuntimeException, and its message is the
                // database's own words: not something to print on the desk.
                $bad = $what === 'status' ? 'The status could not be saved. Try again in a moment.'
                                          : 'The clock could not be changed. Try again in a moment.';
            } catch (RuntimeException $e) {
                // The timer's own refusals ("That task is not assigned to you."),
                // and the status rules' ("You can set your own work to In progress
                // or Pending review. Any other status is set by Production.").
                $bad = $e->getMessage();
            }
            // The door follows the clock, so it is only touched when the clock
            // was: a refused request, or a word this desk does not know, must
            // not open a door somebody had set ajar or sealed by hand.
            if ($bad === '' && in_array($what, ['start', 'stop', 'settle'], true)) {
                realm_focus_door($pdo, $uid, realm_focus_now($pdo, $crewId));
            }
        }
        $answer = ['ok' => true, 'focus' => realm_focus_state($pdo, $crewId), 'said' => trim($said)];
        if ($bad !== '') $answer['error'] = $bad;
        echo json_encode($answer);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Your tasks could not be reached. Try again in a moment.']);
    }
    exit;
}

// ---- voice (David, 2026-11-12, migration 085) --------------------------------
// POST voice=join&place=<room>            join the voice chat of the room I am inside
// POST voice=poll&sid=<n>&muted=0|1&out=<JSON>
//                                         once a second while in voice: I am still
//                                         here, deliver these signals, give me mine
// POST voice=leave&sid=<n>                leave it
//
// The sound itself never comes here: it goes from browser to browser. These
// requests only keep the list of who is talking and pass the set-up notes
// along; the rules are in includes/realm_voice.php.
//
// ⚠ ONE FIELD, `voice`, is what artist_may_post() names. Everything is about the
// asker's own account; the one id that can be sent is a signal's recipient, and
// realm_voice_poll() only delivers to somebody who is in voice in the same room.
//
// Answered here, apart from the map: it is asked every second by the few people
// who are talking and must stay as light as it can be.
if (isset($_POST['voice'])) {
    $what = (string)$_POST['voice'];
    $sid  = (int)($_POST['sid'] ?? 0);
    $none = ['in' => false, 'why' => '', 'people' => [], 'signals' => []];
    try {
        if ($what === 'join') {
            $joined = realm_voice_join($pdo, $uid, (string)($_POST['place'] ?? ''));
            if ($joined['why'] !== '') {
                echo json_encode(['ok' => true, 'voice' => $none, 'error' => $joined['why']]);
                exit;
            }
            $sid = (int)$joined['sid'];
            $answer = realm_voice_poll($pdo, $uid, $sid, false, []);
            $answer['sid'] = $sid;
        } elseif ($what === 'leave') {
            realm_voice_leave($pdo, $uid, $sid);
            $answer = $none;
        } elseif ($what === 'poll') {
            $out = json_decode((string)($_POST['out'] ?? '[]'), true);
            $answer = realm_voice_poll($pdo, $uid, $sid, (string)($_POST['muted'] ?? '0') === '1', is_array($out) ? $out : []);
        } else {
            $answer = $none;
        }
        echo json_encode(['ok' => true, 'voice' => $answer]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'The call could not be reached. Try again in a moment.']);
    }
    exit;
}

// ---- a house's room, and the portrait in it (David, 2026-11-12, migration 086) --
// GET  room=<owner's user id>        that person's room, to draw it
// POST save_room=<the room as JSON>  the asker's OWN room, after decorating
// GET  profile=<owner's user id>     the profile behind the portrait
// POST save_profile=1&phone=&bio=&remove_photo=0|1 (+ a file field `photo`)
//                                    the asker's OWN profile, by Home's rules
//
// ⚠ NEITHER SAVE TAKES AN ID. The room is the session's own account
// (realm_room_save), and the profile is the person that account is linked to
// (realm_crew_id), saved by home_save_profile(), the very function Home calls.
// Both fields are in artist_may_post(); save_profile also asks Home's switch in
// FEATURE_POSTS, so somebody whose Home is off cannot edit a profile from here.
if (isset($_GET['room']) || isset($_POST['save_room']) || isset($_GET['profile']) || isset($_POST['save_profile'])) {
    try {
        require_once __DIR__ . '/includes/realm_room.php';
        if (isset($_GET['room']) || isset($_POST['save_room'])) {
            $bad = isset($_POST['save_room']) ? realm_room_save($pdo, $uid, (string)$_POST['save_room']) : '';
            $room = realm_room_answer($pdo, $uid, isset($_POST['save_room']) ? $uid : (int)$_GET['room']);
            $answer = $room === null ? ['ok' => false, 'error' => 'There is no house there.'] : ['ok' => true, 'room' => $room];
            if ($bad !== '') { $answer['error'] = $bad; $answer['saved'] = false; }
            elseif (isset($_POST['save_room'])) $answer['saved'] = true;
            echo json_encode($answer);
            exit;
        }
        $bad = '';
        $said = '';
        if (isset($_POST['save_profile'])) {
            require_once __DIR__ . '/includes/profile.php';
            $crewId = realm_crew_id($pdo, $uid);
            if ($crewId <= 0) {
                $bad = 'Your login is not linked to a person yet, so there is no profile to fill in.';
            } else {
                try {
                    $said = home_save_profile($pdo, $crewId, $_POST, $_FILES['photo'] ?? null, !empty($_POST['remove_photo']));
                } catch (PDOException $e) {
                    // The database's own words are not for the screen (see the focus desk above).
                    $bad = 'Your profile could not be saved. Try again in a moment.';
                } catch (RuntimeException $e) {
                    // Home's own refusals: a phone number with letters in it, a file that is not a picture.
                    $bad = $e->getMessage();
                }
            }
        }
        $profile = realm_profile($pdo, $uid, isset($_POST['save_profile']) ? $uid : (int)$_GET['profile']);
        $answer = $profile === null ? ['ok' => false, 'error' => 'There is nobody living there.'] : ['ok' => true, 'profile' => $profile, 'said' => $said];
        if ($bad !== '') $answer['error'] = $bad;
        echo json_encode($answer);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'That could not be reached. Try again in a moment.']);
    }
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
                      'knocks' => realm_knock_news($pdo, $uid), 'voice' => realm_voice_here($pdo, $uid)]);
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
                   'unread' => realm_unread($pdo, $uid, $mayAnnounce), 'knocks' => realm_knock_news($pdo, $uid),
                   'voice' => realm_voice_here($pdo, $uid)];
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
