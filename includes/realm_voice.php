<?php
// The Realm's voice: who is talking in which room, and the notes two browsers
// pass each other to set up a direct line (migration 085).
//
// David, 2026-11-12: "let's start working on the voice chats". What he settled,
// and what is relied on below:
//   * SMALL GROUPS. "I think is fine 3-5 people talking informally but if we
//     need more people (which rarely happens) we can cast a spell and connect it
//     with google meetings". So a table holds REALM_VOICE_CAP people and no more.
//   * FACE TO FACE. "voice chats with a specific person ... should be
//     face-to-face in the map. So if I ever want to do any of those things the
//     avatars should be close or in that person's office". Voice is between the
//     people INSIDE the same room, in every room: a house, the Tavern, the
//     Council Hall, a show's war room. A house still needs its door open, or a
//     knock and being let in; that is realm_arrive()'s job and is not repeated.
//   * NOBODY IS HEARD BY SURPRISE. Walking into a room never opens a microphone.
//     Each person presses Join call.
//
// THE WORD PEOPLE READ IS "CALL". David, 2026-11-13: "let's change 'Join voice'
// for 'Join call'". Every sentence shown on screen says call; the code, the
// tables and the request field keep the word voice, which is not worth a
// migration to rename.
//
// ⚠ THE SOUND NEVER COMES THROUGH HERE. It travels straight from one browser to
// another, encrypted by the browsers (WebRTC). This file only keeps the list of
// who is in voice and relays the set-up notes ("signals": an offer, an answer,
// network addresses). The hosting cannot hold a connection open, so a browser
// in voice asks once a second (realm_voice_poll) and nobody else pays that cost.
//
// ⚠ ONE OUTSIDE SERVICE, AND ONLY THE BROWSER TALKS TO IT. To reach a browser on
// another network, each browser asks an address-lookup service (STUN) what its
// own internet address is. It carries no sound and nothing of Anima's. David
// agreed to Google's free one on 2026-11-12, after asking what it means for
// privacy and ease of use. It is the first thing in the Realm that contacts
// anybody outside, it is contacted only while somebody is in voice, and it is
// the one line in realm_voice_ice(). Anima's server still talks to nobody.
// There is NO relay (TURN): two people on strict networks may fail to connect,
// and the screen says so. A relay costs a few dollars a month and was left for
// when it is needed.
//
// ⚠ WHO MAY SIGNAL WHOM IS DECIDED HERE, not in the browser. A signal is taken
// only from somebody who is in voice in a room they are really inside, and only
// for somebody who is in voice in that same room. The person is always the
// session's own account (realm_user_id()); the only id that can be posted is
// the recipient's, and it is matched against that room's list.

/** David, 2026-11-12: "3-5 people talking informally". */
const REALM_VOICE_CAP = 5;
/** A voice row not touched for this many seconds means that page is closed. */
const REALM_VOICE_SECONDS = 15;
/** A signal older than this is ignored: the connection it was for has moved on. */
const REALM_SIGNAL_SECONDS = 30;
/**
 * The longest signal taken, in characters. An audio-only offer is a few
 * thousand; the offer for a shared screen lists every picture format the browser
 * knows and runs to about ten thousand, so the limit was raised from 12000 on
 * 2026-11-13. `realm_signals.body` is TEXT, which holds 65535.
 */
const REALM_SIGNAL_MAX = 30000;
/**
 * offer / answer / ice set up the line the SOUND travels on. soffer / sanswer /
 * sice set up the separate, one-way line a SHARED SCREEN travels on (migration
 * 088), so that a screen that cannot get through never disturbs the sound.
 */
const REALM_SIGNAL_KINDS = ['offer', 'answer', 'ice', 'soffer', 'sanswer', 'sice'];

/** Have the tables arrived (migration 085)? Asked once per request; never throws. */
function realm_voice_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_voice LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_signals LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Sharing a screen in a call (migration 088). David, 2026-11-13: "let's discuss
 * the option to share your screen while in a voice chat", and that day: one
 * person at a time, seen only by the people in the call, ended when the sharer
 * stops, leaves the call or leaves the room, and no sound from the screen.
 *
 * The picture goes from browser to browser like the sound. All the server keeps
 * is who holds the screen in a room. Asked once per request; never throws.
 * Until the migration runs this is false and the browser shows no Share button.
 */
function realm_share_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!realm_voice_available($pdo)) return $ok = false;
    try {
        $pdo->query('SELECT sharing, share_since FROM realm_voice LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * The address-lookup service each browser asks while in voice (see the note at
 * the top). A relay, if one is ever added, is a second entry here and nothing
 * else changes.
 */
function realm_voice_ice(): array {
    return [['urls' => 'stun:stun.l.google.com:19302']];
}

/** The room this person is INSIDE right now, or '' when they are outdoors. Never throws. */
function realm_voice_room_of(PDO $pdo, int $uid): string {
    try {
        $q = $pdo->prepare('SELECT place, inside FROM realm_people WHERE user_id = ?');
        $q->execute([$uid]);
        $r = $q->fetch();
        return ($r && (int)$r['inside'] === 1) ? (string)$r['place'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Who is in voice in $place: [{id, muted, sid, share}], in the order they joined.
 *
 * `share` is true for AT MOST ONE person: the one sharing their screen. When two
 * rows both say sharing (two people pressed Share in the same second), the one
 * who started first holds the screen, then the smaller id; the other browser is
 * told by seeing that it is not the one named, and stops.
 *
 * Somebody counts only while ALL of this is true: their voice row is fresh,
 * it names this room, and their character is still inside this room with a
 * fresh heartbeat. So walking out, or closing the page, takes a person off the
 * list without anything having to delete their row.
 */
function realm_voice_roster(PDO $pdo, string $place): array {
    if ($place === '') return [];
    $share = realm_share_available($pdo);
    $q = $pdo->prepare('SELECT v.user_id, v.muted, v.session' . ($share ? ', v.sharing, v.share_since' : '') . '
                          FROM realm_voice v
                          JOIN realm_people rp ON rp.user_id = v.user_id
                         WHERE v.place = ?
                           AND v.last_seen > DATE_SUB(NOW(), INTERVAL ' . REALM_VOICE_SECONDS . ' SECOND)
                           AND rp.place = v.place AND rp.inside = 1
                           AND rp.last_ping > DATE_SUB(NOW(), INTERVAL ' . REALM_ONLINE_SECONDS . ' SECOND)
                      ORDER BY v.joined_at, v.user_id');
    $q->execute([$place]);
    $out = [];
    $holder = -1;       // where in $out the one who holds the screen is
    $since  = '';
    foreach ($q->fetchAll() as $r) {
        $id = (int)$r['user_id'];
        if ($share && (int)$r['sharing'] === 1) {
            $at = (string)($r['share_since'] ?? '');
            if ($holder < 0 || $at < $since || ($at === $since && $id < $out[$holder]['id'])) {
                $holder = count($out);
                $since  = $at;
            }
        }
        $out[] = ['id' => $id, 'muted' => (int)$r['muted'] === 1, 'sid' => (int)$r['session'], 'share' => false];
    }
    if ($holder >= 0) $out[$holder]['share'] = true;
    return $out;
}

/**
 * Who is in voice in the room the asker is inside, for somebody who is NOT in
 * voice themselves: [{id, muted, share}]. Null until migration 085 has run.
 * `share` lets the room say "Renata is sharing a screen, join the call to see it".
 *
 * Sent with the unread counts and with every chat answer, as knock news is, so
 * somebody standing in a room sees a voice chat begin within a few seconds
 * instead of at the next 25-second heartbeat. Never throws.
 */
function realm_voice_here(PDO $pdo, int $uid): ?array {
    if (!realm_voice_available($pdo)) return null;
    try {
        $out = [];
        foreach (realm_voice_roster($pdo, realm_voice_room_of($pdo, $uid)) as $p) {
            $out[] = ['id' => $p['id'], 'muted' => $p['muted'], 'share' => $p['share']];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Join the voice chat of the room the asker is inside.
 * Returns ['why' => '' and 'sid' => the new session number] when joined, or
 * ['why' => the sentence to show, 'sid' => 0].
 *
 * $place is the room the browser believes it is in. It is NOT trusted: it is
 * only used when the server has not yet heard that the person walked in (the
 * "I went inside" request and this one can cross on the wire), and then
 * realm_arrive() is asked, which checks the door exactly as walking in does.
 *
 * Two people joining a table with one seat left in the same instant can both
 * get in, making six. That is harmless (six browsers can still talk) and not
 * worth a lock on shared hosting.
 */
function realm_voice_join(PDO $pdo, int $uid, string $place): array {
    $no = function (string $why): array { return ['why' => $why, 'sid' => 0]; };
    if (!realm_voice_available($pdo)) return $no('Calls need a database update first. An administrator opens migrate.php once.');
    $room = realm_voice_room_of($pdo, $uid);
    if ($room === '' || ($place !== '' && $place !== $room)) {
        if ($place === '') return $no('Go inside a room first. A call is for people in the same room.');
        $why = realm_arrive($pdo, $uid, $place, true);
        if ($why !== '') return $no($why);
        $room = $place;
    }
    $others = 0;
    foreach (realm_voice_roster($pdo, $room) as $p) {
        if ($p['id'] !== $uid) $others++;
    }
    if ($others >= REALM_VOICE_CAP) {
        return $no('This call is full, ' . REALM_VOICE_CAP . ' people are already talking. For a bigger meeting, use a video call.');
    }
    $sid = random_int(1, 2147483647);
    $pdo->prepare('INSERT INTO realm_voice (user_id, place, session, muted, joined_at, last_seen) VALUES (?, ?, ?, 0, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE place = VALUES(place), session = VALUES(session), muted = 0, joined_at = NOW(), last_seen = NOW()')
        ->execute([$uid, $room, $sid]);
    // A fresh Join never arrives already sharing a screen (migration 088).
    if (realm_share_available($pdo)) {
        $pdo->prepare('UPDATE realm_voice SET sharing = 0, share_since = NULL WHERE user_id = ?')->execute([$uid]);
    }
    // Anything still addressed to me was for an earlier call. And, since nothing
    // else ever sweeps these two tables: rows left behind by closed pages.
    $pdo->prepare('DELETE FROM realm_signals WHERE to_user = ? OR from_user = ?')->execute([$uid, $uid]);
    try {
        $pdo->exec('DELETE FROM realm_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
        $pdo->exec('DELETE FROM realm_voice WHERE last_seen < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        // Tidying is a courtesy; the join stands without it.
    }
    return ['why' => '', 'sid' => $sid];
}

/** Leave voice. Only the window that is in voice ($sid) can end it, so an old tab cannot hang up a new one. */
function realm_voice_leave(PDO $pdo, int $uid, int $sid): void {
    if (!realm_voice_available($pdo)) return;
    $d = $pdo->prepare('DELETE FROM realm_voice WHERE user_id = ? AND session = ?');
    $d->execute([$uid, $sid]);
    if ($d->rowCount() === 1) {
        $pdo->prepare('DELETE FROM realm_signals WHERE to_user = ? OR from_user = ?')->execute([$uid, $uid]);
    }
}

/**
 * The once-a-second request of a browser that is in voice. In one go it:
 *   says "I am still here" (and whether I am muted),
 *   says whether I am sharing my screen ($share, migration 088),
 *   hands over the signals I want delivered ($out: [{to, kind, body}]),
 *   and collects the signals waiting for me, which are then deleted.
 *
 * Answers ['in' => true, 'place', 'people' => the room's voice list,
 *          'signals' => [{from, sid, kind, body}]]
 *      or ['in' => false, 'why' => a sentence, or '' when there is nothing to say].
 *
 * ⚠ EVERY CHECK IS MADE AGAIN ON EVERY REQUEST: that my voice row exists and is
 * this window's, and that my character is still inside that room. A signal goes
 * only to somebody on that room's voice list, which realm_voice_roster() builds
 * from who is really inside. `body` is never read here; it is the browsers' own
 * text and is handed over as it came, within a length limit.
 *
 * ⚠ ONE SCREEN PER ROOM (David, 2026-11-13: "One at a time"). $share = true is a
 * request, not a fact: it is granted only while nobody else on the room's list
 * holds the screen. The answer's list marks who does (`share`), and a browser
 * that asked and is not the one marked stops its own share and says who was
 * first. $share = false always lets go. Leaving the call deletes the row, and
 * walking out or closing the page takes the person off the list, so each of
 * those ends the share with nothing extra to do.
 */
function realm_voice_poll(PDO $pdo, int $uid, int $sid, bool $muted, array $out, bool $share = false): array {
    $gone = function (string $why): array { return ['in' => false, 'why' => $why, 'people' => [], 'signals' => []]; };
    if (!realm_voice_available($pdo)) return $gone('Calls need a database update first.');
    $q = $pdo->prepare('SELECT place, session FROM realm_voice WHERE user_id = ?');
    $q->execute([$uid]);
    $row = $q->fetch();
    if (!$row) return $gone('');
    if ((int)$row['session'] !== $sid) return $gone('Your call moved to another window.');
    $place = (string)$row['place'];
    if (realm_voice_room_of($pdo, $uid) !== $place) {
        realm_voice_leave($pdo, $uid, $sid);
        return $gone('You left the room, so you left its call.');
    }
    $pdo->prepare('UPDATE realm_voice SET last_seen = NOW(), muted = ? WHERE user_id = ? AND session = ?')
        ->execute([$muted ? 1 : 0, $uid, $sid]);

    $sharing = realm_share_available($pdo);
    if ($sharing && !$share) {
        $pdo->prepare('UPDATE realm_voice SET sharing = 0, share_since = NULL WHERE user_id = ? AND session = ? AND sharing = 1')
            ->execute([$uid, $sid]);
    }
    $people = realm_voice_roster($pdo, $place);
    if ($sharing && $share) {
        $held = false;      // does anybody, me included, hold the screen already?
        foreach ($people as $p) {
            if ($p['share']) $held = true;
        }
        if (!$held) {
            $pdo->prepare('UPDATE realm_voice SET sharing = 1, share_since = NOW() WHERE user_id = ? AND session = ?')
                ->execute([$uid, $sid]);
            $people = realm_voice_roster($pdo, $place);
        }
    }
    $there = [];
    foreach ($people as $p) $there[$p['id']] = true;

    if ($out) {
        $ins = $pdo->prepare('INSERT INTO realm_signals (from_user, from_session, to_user, place, kind, body, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        foreach (array_slice($out, 0, 40) as $s) {
            if (!is_array($s)) continue;
            $to   = (int)($s['to'] ?? 0);
            $kind = (string)($s['kind'] ?? '');
            $body = $s['body'] ?? '';
            if (!is_string($body) || $body === '' || strlen($body) > REALM_SIGNAL_MAX) continue;
            if ($to === $uid || !isset($there[$to]) || !in_array($kind, REALM_SIGNAL_KINDS, true)) continue;
            $ins->execute([$uid, $sid, $to, $place, $kind, $body]);
        }
    }

    $signals = [];
    $g = $pdo->prepare('SELECT id, from_user, from_session, kind, body FROM realm_signals
                         WHERE to_user = ? AND place = ?
                           AND created_at > DATE_SUB(NOW(), INTERVAL ' . REALM_SIGNAL_SECONDS . ' SECOND)
                      ORDER BY id LIMIT 200');
    $g->execute([$uid, $place]);
    $last = 0;
    foreach ($g->fetchAll() as $r) {
        $last = (int)$r['id'];
        $signals[] = ['from' => (int)$r['from_user'], 'sid' => (int)$r['from_session'],
                      'kind' => (string)$r['kind'], 'body' => (string)$r['body']];
    }
    // Read once: everything handed over, and anything older that was skipped, goes.
    if ($last > 0) $pdo->prepare('DELETE FROM realm_signals WHERE to_user = ? AND id <= ?')->execute([$uid, $last]);

    return ['in' => true, 'why' => '', 'place' => $place, 'people' => $people, 'signals' => $signals];
}
