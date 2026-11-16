<?php
// The Realm: "Activity", one list of everything that is FOR YOU (migration 098).
//
// David, 2026-11-16, choosing the next piece of the Realm: an Activity view as in
// Slack, so nothing gets lost when channels are busy. What was pitched to him:
// "your mentions, replies to your threads, and reactions to your messages, in
// one list". It also carries the ravens sent to you (a forwarded message sent to
// you arrives as a raven, and says so).
//
// What is relied on below:
//   * NO NEW RECORD OF EVENTS. Everything in the list is read from what the chat
//     already keeps: the messages (a mention is "@My Name", or @here/@channel,
//     in the words), the threads (migration 095) and the reactions (migration
//     093). So the list can never disagree with the conversations themselves,
//     and an edited or deleted message changes or leaves the list at once.
//   * ⚠ PRIVACY IS THE CHAT'S OWN: every query is limited by
//     realm_channel_filter(), the same list of places the unread counts, the
//     threads and the saved list use. A private channel (migration 097) counts
//     only for its members; a raven only for its two people. Nothing posted by
//     the page widens it.
//   * READ IS SHARED WITH THE CHAT. A message is read once its conversation (or
//     its thread) has been read there (realm_reads / realm_thread_reads), so the
//     Activity count and the channel counts go down together. The list adds its
//     own marks on top (realm_activity_reads, migration 098): one row read by
//     clicking it, or everything before a moment with "Mark all as read".
//     Reactions have no conversation read mark, so they are read only by those.
//   * IT RIDES ON THE EXISTING POLLING. The count comes with the heartbeat, the
//     `?unread=1` poll and every chat answer; the list is asked for only while
//     it is open. So it must stay cheap: only the last REALM_ACTIVITY_DAYS days
//     are looked at, starting from the first message id of that window, which
//     every query can use its index for.
//   * THE BELL. Anima's bell rings for ravens and tags (includes/notify.php,
//     kinds `raven` and `tag`), one line per sender, and never says the words.
//     Activity is the same news told in full inside the Realm. Reading a raven
//     in the Realm already quiets its bell line; "Mark all as read" here quiets
//     the Realm's raven and tag lines too, and opening a mention quiets the tag
//     line from its sender, so the two never point at news already dealt with.

/** How far back the list looks. Older news is still in the conversations, and in search. */
const REALM_ACTIVITY_DAYS = 30;
/** How many rows the list shows. */
const REALM_ACTIVITY_PAGE = 60;
/** The kinds of row, which are also the filters the list offers. */
const REALM_ACTIVITY_KINDS = ['mention', 'thread', 'reaction', 'raven'];

/** Has migration 098 made the read marks? Asked once per request; never throws. */
function realm_activity_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_activity_reads LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * The first message id inside the window. Found from the newest end, so only
 * the window's own messages are walked, however many years of chat are kept.
 * Asked once per request.
 */
function realm_activity_start(PDO $pdo): int {
    static $id = null;
    if ($id !== null) return $id;
    try {
        $q = $pdo->prepare('SELECT id FROM realm_messages WHERE created_at < ? ORDER BY id DESC LIMIT 1');
        $q->execute([realm_activity_since()]);
        return $id = (int)$q->fetchColumn() + 1;
    } catch (Throwable $e) {
        return $id = 1;
    }
}

/** The start of the window as a time. */
function realm_activity_since(): string {
    return date('Y-m-d H:i:s', time() - REALM_ACTIVITY_DAYS * 86400);
}

/** The name the Realm shows for somebody, from a row with display_name, username, crew_name. */
function realm_activity_name(array $r): string {
    // `uid` is the joined account: null when the person has left Anima.
    if (array_key_exists('uid', $r) && $r['uid'] === null) return 'Somebody who left';
    return trim((string)(($r['crew_name'] ?? '') ?: (($r['display_name'] ?? '') ?: ($r['username'] ?? '')))) ?: 'Somebody';
}

/**
 * Is $me tagged in $body? The same rule realm_tag_bell() rings by: "@Name",
 * longest names first, each match taken out before the next is looked for, so
 * "@Renata Ruiz" is not also a mention of a "Renata". @here and @channel mean
 * everybody who reads the place.
 */
function realm_activity_tagged(string $body, string $me, array $names): bool {
    if (strpos($body, '@') === false) return false;
    if (preg_match('/@(here|channel)\b/i', $body) === 1) return true;
    if ($me === '') return false;
    $rest = $body;
    foreach ($names as $n) {
        if ($n === '' || stripos($rest, '@' . $n) === false) continue;
        if (strcasecmp($n, $me) === 0) return true;
        $rest = str_ireplace('@' . $n, ' ', $rest);
    }
    return false;
}

/** Everybody's Realm name, longest first, for realm_activity_tagged(). */
function realm_activity_names(PDO $pdo): array {
    $names = [];
    foreach ($pdo->query('SELECT u.display_name, u.username, c.name AS crew_name
                            FROM realm_people rp JOIN users u ON u.id = rp.user_id
                       LEFT JOIN crew c ON c.id = u.crew_id')->fetchAll() as $r) {
        $names[] = trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])));
    }
    usort($names, function ($a, $b) { return strlen($b) <=> strlen($a); });
    return $names;
}

/** What this person marked in the list: ['all' => time or '', 'items' => key => true]. */
function realm_activity_marks(PDO $pdo, int $uid): array {
    $out = ['all' => '', 'items' => []];
    if (!realm_activity_available($pdo)) return $out;
    try {
        $q = $pdo->prepare('SELECT item, at FROM realm_activity_reads WHERE user_id = ?');
        $q->execute([$uid]);
        foreach ($q->fetchAll() as $r) {
            if ($r['item'] === '*') $out['all'] = (string)$r['at'];
            else $out['items'][(string)$r['item']] = true;
        }
    } catch (Throwable $e) {
        // Nothing marked: the chat's own read marks still count.
    }
    return $out;
}

/**
 * The list, newest first: rows of
 *   key      'm:<message id>' or 'r:<message id>:<user id>:<emoji>'
 *   kind     mention | thread | reaction | raven
 *   id       the message to open at
 *   channel  its conversation; thread: the thread to open instead (0 = the conversation)
 *   who      who did it
 *   what     mine_thread (a reply in a thread I started), fwd (a forwarded raven), emoji (a reaction)
 *   about    the start of the message; for a reaction, of MY message reacted to
 *   at       when, as the chat prints it; unread true/false
 * $kind '' is everything, else one of REALM_ACTIVITY_KINDS. With $unreadOnly the
 * rows that are read are left out (that is how the count is made). Never throws.
 */
function realm_activity(PDO $pdo, int $uid, bool $mayAnnounce, string $kind = '', bool $unreadOnly = false, int $limit = REALM_ACTIVITY_PAGE): array {
    if (!realm_chat_available($pdo)) return [];
    try {
        $start = realm_activity_start($pdo);
        $since = realm_activity_since();
        [$may, $vals] = realm_channel_filter($pdo, $uid, $mayAnnounce, 'm.channel');
        $threads = realm_threads_available($pdo);
        $acts = function_exists('realm_msgact_available') && realm_msgact_available($pdo);
        $marks = realm_activity_marks($pdo, $uid);
        $alive = $acts ? ' AND m.deleted_at IS NULL' : '';
        $who = 'u.id AS uid, u.display_name, u.username, c.name AS crew_name';
        // The read marks of the conversation and of the thread the message is in.
        // $uid is an int from the session, written straight in only to keep the
        // joins readable; every value from the page goes through a placeholder.
        $uid = (int)$uid;
        $reads = ' LEFT JOIN realm_reads rr ON rr.user_id = ' . $uid . ' AND rr.channel = m.channel'
               . ($threads ? ' LEFT JOIN realm_thread_reads tr ON tr.user_id = ' . $uid . ' AND tr.parent_id = m.parent_id' : '');
        $cols = 'm.id, m.channel, m.user_id, m.body, m.created_at, COALESCE(rr.last_id, 0) AS read_ch, '
              . ($threads ? 'm.parent_id, m.also_channel, COALESCE(tr.last_id, 0) AS read_th' : 'NULL AS parent_id, 0 AS also_channel, 0 AS read_th')
              . ($acts ? ', m.fwd_from' : ', NULL AS fwd_from');
        $base = ' FROM realm_messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN crew c ON c.id = u.crew_id' . $reads;
        $notMine = ' AND (m.user_id IS NULL OR m.user_id <> ' . $uid . ')';
        $rows = [];   // key => row; a message is listed once, the first kind below wins

        $want = function (string $k) use ($kind) { return $kind === '' || $kind === $k; };
        // Read in the chat: in its thread when it was kept there, else in its conversation.
        $chatRead = function (array $r): bool {
            $inThread = (int)($r['parent_id'] ?? 0) > 0 && (int)$r['also_channel'] !== 1;
            if ((int)($r['parent_id'] ?? 0) > 0 && (int)$r['read_th'] >= (int)$r['id']) return true;
            return !$inThread && (int)$r['read_ch'] >= (int)$r['id'];
        };
        $add = function (array $r, string $k, array $extra) use (&$rows, $uid, $marks, $chatRead, $unreadOnly) {
            $key = 'm:' . (int)$r['id'];
            if (isset($rows[$key])) return;
            $read = $chatRead($r) || isset($marks['items'][$key]) || ($marks['all'] !== '' && (string)$r['created_at'] <= $marks['all']);
            if ($unreadOnly && $read) return;
            $rows[$key] = $extra + [
                'key' => $key, 'kind' => $k, 'id' => (int)$r['id'], 'channel' => (string)$r['channel'],
                'thread' => (int)($r['parent_id'] ?? 0) > 0 && (int)$r['also_channel'] !== 1 ? (int)$r['parent_id'] : 0,
                'who' => realm_activity_name($r), 'about' => realm_chat_snippet((string)$r['body']),
                'at' => realm_chat_when(strtotime((string)$r['created_at'])), 'ts' => strtotime((string)$r['created_at']),
                'unread' => !$read,
            ];
        };

        // 1. MENTIONS: "@My Name", @here or @channel, in any channel I may read (a
        // raven is listed as a raven). LIKE finds the candidates, PHP decides.
        if ($want('mention')) {
            $me = realm_person_name($pdo, $uid);
            $like = function (string $s) { return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s) . '%'; };
            $q = $pdo->prepare('SELECT ' . $cols . ', ' . $who . $base . ' WHERE m.id >= ?' . $notMine . $alive
                             . " AND m.channel NOT LIKE 'dm:%' AND " . $may
                             . " AND (m.body LIKE ? ESCAPE '!' OR m.body LIKE '%@here%' OR m.body LIKE '%@channel%')"
                             . ' ORDER BY m.id DESC LIMIT 300');
            $q->execute(array_merge([$start], $vals, [$like('@' . $me)]));
            $found = $q->fetchAll();
            if ($found) {
                $names = realm_activity_names($pdo);
                foreach ($found as $r) {
                    if (realm_activity_tagged((string)$r['body'], $me, $names)) $add($r, 'mention', []);
                }
            }
        }

        // 2. REPLIES in threads I follow: ones I started, or replied in (the rule
        // the Threads row uses, realm_thread_unread()).
        if ($threads && $want('thread')) {
            $q = $pdo->prepare('SELECT ' . $cols . ', ' . $who . ', p.user_id AS parent_user' . $base
                             . ' JOIN realm_messages p ON p.id = m.parent_id
                                WHERE m.parent_id IS NOT NULL AND m.id >= ?' . $notMine . $alive . '
                                  AND (p.user_id = ? OR EXISTS (SELECT 1 FROM realm_messages s WHERE s.parent_id = m.parent_id AND s.user_id = ?))
                                  AND ' . $may . ' ORDER BY m.id DESC LIMIT 300');
            $q->execute(array_merge([$start, $uid, $uid], $vals));
            foreach ($q->fetchAll() as $r) $add($r, 'thread', ['mine_thread' => (int)$r['parent_user'] === $uid]);
        }

        // 3. RAVENS to me: the other person's messages in my ravens (a reply kept
        // in a raven's thread is listed as a thread reply above).
        if ($want('raven')) {
            $q = $pdo->prepare('SELECT ' . $cols . ', ' . $who . $base . ' WHERE m.id >= ?' . $notMine . $alive
                             . ' AND (m.channel LIKE ? OR m.channel LIKE ?)'
                             . ($threads ? ' AND (m.parent_id IS NULL OR m.also_channel = 1)' : '')
                             . ' ORDER BY m.id DESC LIMIT 300');
            $q->execute([$start, 'dm:' . $uid . ':%', 'dm:%:' . $uid]);
            foreach ($q->fetchAll() as $r) $add($r, 'raven', ['fwd' => ($r['fwd_from'] ?? null) !== null]);
        }

        // 4. REACTIONS to my messages, by anybody else. Read only through the list.
        if ($want('reaction') && realm_reactions_available($pdo)) {
            $q = $pdo->prepare('SELECT r.message_id, r.user_id AS uid, r.emoji, r.created_at, m.channel, m.body, '
                             . ($threads ? 'm.parent_id, m.also_channel' : 'NULL AS parent_id, 0 AS also_channel')
                             . ', u.display_name, u.username, c.name AS crew_name
                                 FROM realm_messages m
                                 JOIN realm_reactions r ON r.message_id = m.id
                            LEFT JOIN users u ON u.id = r.user_id LEFT JOIN crew c ON c.id = u.crew_id
                                WHERE m.user_id = ? AND r.user_id <> ? AND r.created_at >= ?' . $alive . ' AND ' . $may . '
                             ORDER BY r.created_at DESC LIMIT 300');
            $q->execute(array_merge([$uid, $uid, $since], $vals));
            foreach ($q->fetchAll() as $r) {
                if (!in_array((string)$r['emoji'], REALM_REACTIONS, true)) continue;
                $key = 'r:' . (int)$r['message_id'] . ':' . (int)$r['uid'] . ':' . $r['emoji'];
                $read = isset($marks['items'][$key]) || ($marks['all'] !== '' && (string)$r['created_at'] <= $marks['all']);
                if ($unreadOnly && $read) continue;
                $rows[$key] = [
                    'key' => $key, 'kind' => 'reaction', 'id' => (int)$r['message_id'], 'channel' => (string)$r['channel'],
                    'thread' => (int)($r['parent_id'] ?? 0) > 0 && (int)$r['also_channel'] !== 1 ? (int)$r['parent_id'] : 0,
                    'who' => realm_activity_name($r), 'emoji' => (string)$r['emoji'],
                    'about' => realm_chat_snippet((string)$r['body']),
                    'at' => realm_chat_when(strtotime((string)$r['created_at'])), 'ts' => strtotime((string)$r['created_at']),
                    'unread' => !$read,
                ];
            }
        }

        $rows = array_values($rows);
        usort($rows, function ($a, $b) { return ($b['ts'] <=> $a['ts']) ?: ($b['id'] <=> $a['id']); });
        return array_slice($rows, 0, $limit);
    } catch (Throwable $e) {
        return [];
    }
}

/** How many rows of the list are unread: the number on the Activity row. Never throws. */
function realm_activity_count(PDO $pdo, int $uid, bool $mayAnnounce): int {
    return count(realm_activity($pdo, $uid, $mayAnnounce, '', true, 100));
}

/**
 * Mark one row read ($item is its key), or everything ($item 'all'). Returns ''
 * or the sentence to show. Only ever this person's own marks: a key names a
 * message, but marking it read tells nobody anything and changes nothing else.
 */
function realm_activity_mark(PDO $pdo, int $uid, string $item): string {
    if (!realm_activity_available($pdo)) return 'Marking as read needs a database update first. An administrator opens migrate.php once.';
    if ($item === 'all') {
        // Everything before now is read; the single marks are no longer needed.
        $pdo->prepare("INSERT INTO realm_activity_reads (user_id, item, at) VALUES (?, '*', NOW())
                       ON DUPLICATE KEY UPDATE at = NOW()")->execute([$uid]);
        $pdo->prepare("DELETE FROM realm_activity_reads WHERE user_id = ? AND item <> '*'")->execute([$uid]);
        realm_activity_bell_clear($pdo, $uid, null);
        return '';
    }
    if (preg_match('/^(m:\d+|r:\d+:\d+:[a-z]{1,12})$/', $item) !== 1) return 'That row is not there any more.';
    $pdo->prepare('INSERT IGNORE INTO realm_activity_reads (user_id, item, at) VALUES (?, ?, NOW())')->execute([$uid, $item]);
    // Old single marks fall outside the window and are not needed any more.
    $pdo->prepare("DELETE FROM realm_activity_reads WHERE user_id = ? AND item <> '*' AND at < ?")
        ->execute([$uid, date('Y-m-d H:i:s', time() - (REALM_ACTIVITY_DAYS + 1) * 86400)]);
    // A mention opened here is no longer news in the bell either.
    if (strpos($item, 'm:') === 0) {
        try {
            $q = $pdo->prepare("SELECT user_id FROM realm_messages WHERE id = ? AND channel NOT LIKE 'dm:%' AND body LIKE '%@%'");
            $q->execute([(int)substr($item, 2)]);
            $from = (int)$q->fetchColumn();
            if ($from > 0 && $from !== $uid) realm_activity_bell_clear($pdo, $uid, realm_person_name($pdo, $from));
        } catch (Throwable $e) {
            // The bell keeps its line a little longer.
        }
    }
    return '';
}

/**
 * Quiet the Realm's lines in this person's bell: the `tag` lines from $actor, or
 * with $actor null every unread `raven` and `tag` line. Never throws.
 */
function realm_activity_bell_clear(PDO $pdo, int $uid, ?string $actor): void {
    try {
        $q = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
        $q->execute([$uid]);
        $crew = (int)$q->fetchColumn();
        if ($crew <= 0) return;
        if ($actor === null) {
            $pdo->prepare("UPDATE notifications SET seen_at = NOW() WHERE crew_id = ? AND kind IN ('raven', 'tag') AND seen_at IS NULL")
                ->execute([$crew]);
        } else {
            $pdo->prepare("UPDATE notifications SET seen_at = NOW() WHERE crew_id = ? AND kind = 'tag' AND actor = ? AND seen_at IS NULL")
                ->execute([$crew, $actor]);
        }
    } catch (Throwable $e) {
        // Opening the bell clears it.
    }
}
