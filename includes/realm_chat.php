<?php
// The Realm, stage 2: written chat (migration 082).
//
// David, 2026-11-12: the Realm is "mainly for communication", with "an actual
// chat and channels like discord or slack". This is that: a channel for each
// shared place, one for each show, and ravens between two people.
//
// What was settled, and is relied on below:
//   * CHANNELS ARE READ FROM ANYWHERE. The face-to-face rule (David, same day)
//     covers praise, trades and a voice chat with one person; written chat is
//     the Discord way, read and written from the list without walking there.
//   * A RAVEN IS BETWEEN TWO PEOPLE AND NOBODY ELSE ("They should only be
//     visible for the 2 in a DM"). The channel name carries both ids, and
//     realm_channel_access() refuses anybody who is not one of them. There is
//     no admin override: an admin cannot read two other people's ravens here.
//   * A SHOW'S CHANNEL IS FOR PEOPLE WHO MAY SEE THAT SHOW, the same rule the
//     map uses for its name (2026-11-04).
//   * ANNOUNCEMENTS are read by everybody and written by Production and admins.
//
// ⚠ NOTHING HERE HOLDS A CONNECTION OPEN. The hosting cannot. An open chat asks
// for anything new every few seconds; everything else learns of unread messages
// from the 25-second heartbeat.
//
// ⚠ ALWAYS THE REAL LOGIN, as in includes/realm.php: the caller passes
// realm_user_id(), and realm_api.php refuses to run inside a preview.

/** The longest message that is kept. Longer is cut, not refused. */
const REALM_MESSAGE_MAX = 2000;
/** How many messages a conversation shows when it is opened. */
const REALM_CHAT_PAGE = 60;

/** Have the tables arrived (migration 082)? Asked once per request; never throws. */
function realm_chat_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_messages LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_reads LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** The name of the raven channel between two people: the lower id first, so both sides agree. */
function realm_dm_channel(int $a, int $b): string {
    return 'dm:' . min($a, $b) . ':' . max($a, $b);
}

/**
 * The shared channels this person may open, in the order the list shows them.
 * Ravens are not in here: they are listed against the people, not as channels.
 */
function realm_channels(PDO $pdo, bool $mayAnnounce): array {
    $out = [
        ['id' => 'announcements', 'name' => 'Announcements', 'post' => $mayAnnounce],
        ['id' => 'tavern',        'name' => 'The Tavern',    'post' => true],
        ['id' => 'council',       'name' => 'Council Hall',  'post' => true],
    ];
    try {
        foreach ($pdo->query('SELECT p.id, p.name FROM realm_lands l JOIN projects p ON p.id = l.project_id ORDER BY p.name') as $r) {
            if (function_exists('may_see_project') && !may_see_project($pdo, (int)$r['id'])) continue;
            $out[] = ['id' => 'project:' . (int)$r['id'], 'name' => (string)$r['name'], 'post' => true];
        }
    } catch (Throwable $e) {
        // The three shared places still work.
    }
    return $out;
}

/**
 * May this person read this channel, and may they write in it?
 * Returns ['read' => bool, 'post' => bool]; read false means it is not theirs.
 *
 * ⚠ THIS IS THE SECOND LOCK on every chat request: artist_may_post() says a
 * person may send a message at all, this says where.
 */
function realm_channel_access(PDO $pdo, int $uid, string $channel, bool $mayAnnounce): array {
    $no = ['read' => false, 'post' => false];
    if (preg_match('/^dm:(\d+):(\d+)$/', $channel, $m) === 1) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a >= $b || ($uid !== $a && $uid !== $b)) return $no;
        // The other end must be somebody who lives in the Realm.
        $q = $pdo->prepare('SELECT 1 FROM realm_people WHERE user_id = ?');
        $q->execute([$uid === $a ? $b : $a]);
        return $q->fetchColumn() ? ['read' => true, 'post' => true] : $no;
    }
    foreach (realm_channels($pdo, $mayAnnounce) as $c) {
        if ($c['id'] === $channel) return ['read' => true, 'post' => (bool)$c['post']];
    }
    return $no;
}

/**
 * Messages in a channel: the latest page when $after is 0, otherwise only what
 * came after that id. Oldest first, ready to print. The caller has already
 * checked realm_channel_access().
 */
function realm_messages(PDO $pdo, int $uid, string $channel, int $after): array {
    $cols = 'm.id, m.user_id, m.body, m.created_at, u.display_name, u.username, c.name AS crew_name';
    $from = ' FROM realm_messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN crew c ON c.id = u.crew_id';
    if ($after > 0) {
        $q = $pdo->prepare('SELECT ' . $cols . $from . ' WHERE m.channel = ? AND m.id > ? ORDER BY m.id LIMIT 200');
        $q->execute([$channel, $after]);
        $rows = $q->fetchAll();
    } else {
        $q = $pdo->prepare('SELECT ' . $cols . $from . ' WHERE m.channel = ? ORDER BY m.id DESC LIMIT ' . REALM_CHAT_PAGE);
        $q->execute([$channel]);
        $rows = array_reverse($q->fetchAll());
    }
    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['created_at']);
        $out[] = [
            'id'   => (int)$r['id'],
            'user' => (int)$r['user_id'],
            'name' => $r['user_id'] === null ? 'Somebody who left' : trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username']))),
            'body' => (string)$r['body'],
            'at'   => date('Y-m-d', $t) === $today ? date('H:i', $t) : date('j M, H:i', $t),
            'mine' => (int)$r['user_id'] === $uid,
        ];
    }
    return $out;
}

/** Say something. Returns '' when it was kept, or the sentence to show. */
function realm_say(PDO $pdo, int $uid, string $channel, string $body): string {
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') return 'There was nothing to send.';
    if (function_exists('mb_substr')) $body = mb_substr($body, 0, REALM_MESSAGE_MAX);
    else $body = substr($body, 0, REALM_MESSAGE_MAX);
    $pdo->prepare('INSERT INTO realm_messages (channel, user_id, body, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$channel, $uid, $body]);
    return '';
}

/** This person has seen everything in the channel up to $lastId. Only ever moves forward. */
function realm_mark_read(PDO $pdo, int $uid, string $channel, int $lastId): void {
    if ($lastId <= 0) return;
    try {
        $pdo->prepare('INSERT INTO realm_reads (user_id, channel, last_id) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE last_id = GREATEST(last_id, VALUES(last_id))')
            ->execute([$uid, $channel, $lastId]);
    } catch (Throwable $e) {
        // The count stays a little high until the next read; nothing is lost.
    }
}

/**
 * How many messages this person has not seen, per channel: channel => count.
 * Only channels they may read are counted, and never their own messages.
 * Never throws: an empty answer is "nothing unread".
 */
function realm_unread(PDO $pdo, int $uid, bool $mayAnnounce): array {
    $out = [];
    try {
        $ids = array_column(realm_channels($pdo, $mayAnnounce), 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT m.channel, COUNT(*) AS n
                  FROM realm_messages m
             LEFT JOIN realm_reads r ON r.user_id = ? AND r.channel = m.channel
                 WHERE m.id > COALESCE(r.last_id, 0)
                   AND (m.user_id IS NULL OR m.user_id <> ?)
                   AND (m.channel IN (' . $marks . ') OR m.channel LIKE ? OR m.channel LIKE ?)
              GROUP BY m.channel';
        $q = $pdo->prepare($sql);
        $q->execute(array_merge([$uid, $uid], $ids, ['dm:' . $uid . ':%', 'dm:%:' . $uid]));
        foreach ($q->fetchAll() as $r) $out[(string)$r['channel']] = (int)$r['n'];
    } catch (Throwable $e) {
        $out = [];
    }
    return $out;
}

/** What the page needs to draw the channel list: the channels and the unread counts. */
function realm_chat_summary(PDO $pdo, int $uid, bool $mayAnnounce): ?array {
    if (!realm_chat_available($pdo)) return null;
    return ['channels' => realm_channels($pdo, $mayAnnounce), 'unread' => realm_unread($pdo, $uid, $mayAnnounce)];
}

/**
 * Ring Anima's bell for a raven (David, 2026-11-12: "let's try it").
 *
 * ⚠ IT RINGS WHETHER OR NOT THE OTHER PERSON HAS THE REALM OPEN. The first
 * version stayed silent for anybody whose Realm was open, on the theory that
 * they would see it there. David and Renata tried it with the Realm in one tab
 * and Anima in another, which is how people actually work, and "we don't get
 * any notifications in anima". A line that is rung and then read in the Realm
 * is taken out of the bell again by realm_raven_bell_clear(), so it does not
 * linger as old news.
 *
 * ⚠ ONE RING PER SENDER UNTIL IT IS READ. Ten ravens in a row are one line in
 * the bell, not ten: a bell that fills up with one conversation stops being read.
 * The bell says who, never what: the words stay between the two people.
 *
 * Never throws, and never the reason a raven is not sent.
 */
function realm_raven_bell(PDO $pdo, int $fromUid, int $toUid): void {
    try {
        if (!function_exists('notify_add')) return;
        $q = $pdo->prepare('SELECT u.crew_id FROM users u WHERE u.id = ?');
        $q->execute([$toUid]);
        $to = $q->fetch();
        if (!$to) return;
        $crew = (int)($to['crew_id'] ?? 0);
        if ($crew <= 0) return;

        $q = $pdo->prepare('SELECT u.display_name, u.username, c.name AS crew_name
                              FROM users u LEFT JOIN crew c ON c.id = u.crew_id WHERE u.id = ?');
        $q->execute([$fromUid]);
        $f = $q->fetch() ?: [];
        $actor = trim((string)(($f['crew_name'] ?? '') ?: (($f['display_name'] ?? '') ?: ($f['username'] ?? 'Somebody'))));

        $q = $pdo->prepare("SELECT 1 FROM notifications WHERE crew_id = ? AND kind = 'raven' AND actor = ? AND seen_at IS NULL LIMIT 1");
        $q->execute([$crew, $actor]);
        if ($q->fetchColumn()) return;

        notify_add($pdo, [$crew], ['kind' => 'raven', 'actor' => $actor, 'summary' => 'in the Realm']);
    } catch (Throwable $e) {
        // The raven itself was kept; only the bell is silent.
    }
}

/** The name an account goes by in the bell and in chat: linked person, else display name, else login. */
function realm_person_name(PDO $pdo, int $uid): string {
    $q = $pdo->prepare('SELECT u.display_name, u.username, c.name AS crew_name
                          FROM users u LEFT JOIN crew c ON c.id = u.crew_id WHERE u.id = ?');
    $q->execute([$uid]);
    $f = $q->fetch() ?: [];
    return trim((string)(($f['crew_name'] ?? '') ?: (($f['display_name'] ?? '') ?: ($f['username'] ?? 'Somebody'))));
}

/**
 * $readerUid has just read the ravens from $senderUid in the Realm, so the line
 * about them in the reader's bell is no longer news. Marks it seen; it stays in
 * the list like any other read line. Never throws.
 */
function realm_raven_bell_clear(PDO $pdo, int $readerUid, int $senderUid): void {
    try {
        $q = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
        $q->execute([$readerUid]);
        $crew = (int)$q->fetchColumn();
        if ($crew <= 0) return;
        $pdo->prepare("UPDATE notifications SET seen_at = NOW()
                        WHERE crew_id = ? AND kind = 'raven' AND actor = ? AND seen_at IS NULL")
            ->execute([$crew, realm_person_name($pdo, $senderUid)]);
    } catch (Throwable $e) {
        // The bell keeps a line a little longer than it should; opening the bell clears it.
    }
}
