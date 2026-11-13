<?php
// The Realm: search every conversation a person may read (David, 2026-11-13).
//
// WHY: the studio is on Slack's free plan, and losing everything older than 90
// days is one of the things they dislike most about it. Every Realm message is
// kept in Anima's own database, so it can be found again forever. David chose
// this next, and asked for it across "all channels and dms", with the box at
// the top of the side panel and no filters to start with.
//
// ⚠ PRIVACY IS DECIDED HERE, ON THE SERVER, NEVER IN THE BROWSER. The search
// only ever looks inside an explicit list of conversations this person may read:
//   * the shared channels and the show channels realm_channels() gives them
//     (a show's channel only for people who may see that show), and
//   * their OWN ravens, one per other person living in the Realm: exactly the
//     ravens realm_channel_access() would let them open. Nobody else's ravens,
//     and no admin override, as for reading them.
// The list is built first and the query is limited to it, so a message from a
// conversation that is not theirs can never be in the answer.
//
// ⚠ IT MUST STAY QUICK WITH YEARS OF MESSAGES. Migration 094 adds a FULLTEXT
// index on the words. Until it has run (and for words the index cannot hold:
// shorter than three letters, or very common ones like "the"), every word is
// matched with LIKE, newest first, 30 at a time. That is slower on a big table,
// but it is correct, and on the Realm's size it is still fast. With the index,
// the index narrows the rows and LIKE still checks each word. One difference
// is visible: without the index a word is found anywhere ("mat" finds
// "animatic"); with it, a word of three or more letters is found at the start
// of a word ("anim" finds "animatic", "mat" does not). Upper or lower case
// never matters.

/** How many results one page shows. "Older results" asks for the next page. */
const REALM_SEARCH_PAGE = 30;
/** How many words of a search are used. More is ignored. */
const REALM_SEARCH_WORDS = 6;

/** Has migration 094 added the FULLTEXT index? Asked once per request; never throws. */
function realm_search_indexed(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $q = $pdo->query("SELECT 1 FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'realm_messages'
                             AND INDEX_NAME = 'ft_realm_msg_body' LIMIT 1");
        return $ok = (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Every conversation this person may read: channel id => what to call it.
 * The ONE place the search learns where it may look.
 */
function realm_search_places(PDO $pdo, int $uid, bool $mayAnnounce): array {
    $out = [];
    foreach (realm_channels($pdo, $mayAnnounce) as $c) $out[(string)$c['id']] = (string)$c['name'];
    // My ravens: one per other person who lives in the Realm, which is the rule
    // realm_channel_access() opens a raven by.
    $q = $pdo->prepare('SELECT rp.user_id, u.display_name, u.username, c.name AS crew_name
                          FROM realm_people rp JOIN users u ON u.id = rp.user_id
                     LEFT JOIN crew c ON c.id = u.crew_id
                         WHERE rp.user_id <> ?');
    $q->execute([$uid]);
    foreach ($q->fetchAll() as $r) {
        $name = trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])));
        $out[realm_dm_channel($uid, (int)$r['user_id'])] = 'Ravens with ' . ($name !== '' ? $name : 'somebody');
    }
    return $out;
}

/**
 * The words of a search, as typed: lower case, at most REALM_SEARCH_WORDS,
 * duplicates dropped. A word in "double quotes" is not treated specially.
 */
function realm_search_words(string $q): array {
    $q = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
    $words = [];
    foreach (preg_split('/\s+/u', trim($q)) ?: [] as $w) {
        $w = trim($w, "\"'.,;:!?()[]{}");
        if ($w === '' || in_array($w, $words, true)) continue;
        $words[] = function_exists('mb_substr') ? mb_substr($w, 0, 50) : substr($w, 0, 50);
        if (count($words) >= REALM_SEARCH_WORDS) break;
    }
    return $words;
}

/**
 * Search. Returns ['results' => [...], 'more' => bool, 'note' => string].
 * $before is 0 for the newest page, else the id of the last result already shown.
 * Never throws for the caller's sake: realm_api.php catches anything left.
 */
function realm_search(PDO $pdo, int $uid, string $q, bool $mayAnnounce, int $before = 0): array {
    $words = realm_search_words($q);
    $letters = function_exists('mb_strlen') ? mb_strlen(implode('', $words)) : strlen(implode('', $words));
    if (!$words || $letters < 2) return ['results' => [], 'more' => false, 'note' => 'Type at least two letters.'];

    $places = realm_search_places($pdo, $uid, $mayAnnounce);
    if (!$places) return ['results' => [], 'more' => false, 'note' => ''];

    $where = ['m.channel IN (' . implode(',', array_fill(0, count($places), '?')) . ')'];
    $args = array_keys($places);
    if ($before > 0) { $where[] = 'm.id < ?'; $args[] = $before; }

    // Every word must be in the message, anywhere, as typed (the column's
    // collation makes it ignore upper and lower case).
    foreach ($words as $w) {
        // "!" escapes LIKE's own wildcards, so "50%" means fifty percent. Not a
        // backslash: the server's SQL mode may treat that differently.
        $where[] = "m.body LIKE ? ESCAPE '!'";
        $args[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $w) . '%';
    }

    // With migration 094, the index narrows the rows first. Only words it can
    // hold are given to it: letters and digits only, three or more of them, and
    // not one of InnoDB's default stop words (a stop word marked "+" would make
    // the whole search find nothing). The LIKEs above still decide.
    $indexed = realm_search_indexed($pdo);
    if ($indexed) {
        static $stop = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what',
                        'when', 'where', 'who', 'will', 'with', 'und', 'www'];
        $ft = [];
        foreach ($words as $w) {
            $len = function_exists('mb_strlen') ? mb_strlen($w) : strlen($w);
            if ($len >= 3 && preg_match('/^[\p{L}\p{N}]+$/u', $w) === 1 && !in_array($w, $stop, true)) $ft[] = '+' . $w . '*';
        }
        if ($ft) {
            $where[] = 'MATCH(m.body) AGAINST (? IN BOOLEAN MODE)';
            $args[] = implode(' ', $ft);
        }
    }

    $cols = 'm.id, m.channel, m.user_id, m.body, m.created_at, u.display_name, u.username, c.name AS crew_name, '
          . (realm_chat_images($pdo) ? 'm.image' : 'NULL') . ' AS image, '
          // Threads (migration 095): a reply kept in its thread opens that thread, not the channel.
          . (function_exists('realm_threads_available') && realm_threads_available($pdo) ? 'IF(m.also_channel = 1, NULL, m.parent_id)' : 'NULL') . ' AS thread_of';
    $sql = 'SELECT ' . $cols . ' FROM realm_messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN crew c ON c.id = u.crew_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT ' . (REALM_SEARCH_PAGE + 1);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > REALM_SEARCH_PAGE;
    if ($more) array_pop($rows);

    $today = date('Y-m-d');
    $year = date('Y');
    $out = [];
    foreach ($rows as $r) {
        $t = strtotime((string)$r['created_at']);
        $out[] = [
            'id'      => (int)$r['id'],
            'channel' => (string)$r['channel'],
            'where'   => $places[(string)$r['channel']] ?? 'Chat',
            'name'    => $r['user_id'] === null ? 'Somebody who left' : trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username']))),
            'mine'    => (int)$r['user_id'] === $uid,
            // Older than this year carries its year: that is the point of keeping everything.
            'at'      => date('Y-m-d', $t) === $today ? 'Today, ' . date('H:i', $t)
                       : (date('Y', $t) === $year ? date('j M, H:i', $t) : date('j M Y', $t)),
            'snippet' => realm_search_snippet((string)$r['body'], $words[0]),
            'image'   => (string)($r['image'] ?? '') !== '',
            'thread'  => (int)($r['thread_of'] ?? 0),
        ];
    }
    return ['results' => $out, 'more' => $more, 'note' => ''];
}

/**
 * The part of a long message worth showing in a result: about 160 letters
 * around the first word searched for, with "…" where it was cut. Plain text:
 * the page escapes it and marks the words.
 */
function realm_search_snippet(string $body, string $word): string {
    $body = trim(preg_replace('/\s+/u', ' ', $body));
    $mb = function_exists('mb_strlen');
    $len = $mb ? mb_strlen($body) : strlen($body);
    if ($len <= 160) return $body;
    $at = $mb ? mb_stripos($body, $word) : stripos($body, $word);
    $start = $at === false ? 0 : max(0, $at - 50);
    $cut = $mb ? mb_substr($body, $start, 160) : substr($body, $start, 160);
    return ($start > 0 ? '…' : '') . $cut . ($start + 160 < $len ? '…' : '');
}

/**
 * Where a conversation opened AT a found message starts: the answer is the
 * `after` that realm_messages() is then asked with, so that about twenty
 * messages before the found one are shown above it, and everything after it
 * below. 0 when it cannot be worked out, and the conversation then opens at
 * its newest page as usual. The caller has checked realm_channel_access().
 *
 * ⚠ realm_messages() ITSELF IS NOT CHANGED: the conversation is read exactly
 * as a normal "what came after this id" poll reads it, so whatever else a
 * message carries there arrives here too, and the open conversation keeps
 * polling for newer messages from where this page ends.
 */
function realm_search_start(PDO $pdo, string $channel, int $messageId): int {
    if ($messageId <= 0) return 0;
    try {
        $q = $pdo->prepare('SELECT MIN(id) FROM (SELECT id FROM realm_messages WHERE channel = ? AND id < ? ORDER BY id DESC LIMIT 20) t');
        $q->execute([$channel, $messageId]);
        $first = (int)$q->fetchColumn();
        // No earlier message: start just before the found one.
        $from = $first > 0 ? $first : $messageId;
        return max(0, $from - 1);
    } catch (Throwable $e) {
        return 0;
    }
}
