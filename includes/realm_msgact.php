<?php
// The Realm's chat: Slack's message actions (migration 096).
//
// David, 2026-11-13, sending a screenshot of the small bar Slack floats over a
// message on hover: "let's add these same features from slack so the transition
// is smoother". The Realm is meant to replace Slack for the studio, and it only
// will if it feels familiar, so the bar matches Slack's habits: three quick
// reactions, add reaction, reply in thread, forward, save for later / remind
// me, and a "More" menu. Settled with him the same day:
//   * QUICK REACTIONS are each person's three most used, from the six the Realm
//     already has (no new emoji). See realm_reaction_favs().
//   * FORWARD to a channel or raven the person may write in. The copy says
//     "Forwarded from <place> · <who>" above the original words and picture.
//   * SAVE FOR LATER, with an optional reminder (in 20 minutes, 1 hour, 3 hours,
//     tomorrow at 9:00) that rings Anima's bell. Private to each person.
//   * MORE: edit and delete your OWN messages, copy a link to a message, mark
//     unread. Not pinning ("Edit & delete my own, Copy link, Mark unread").
//
// What is relied on below:
//   * EVERY ACTION NAMES A MESSAGE, NEVER A CHANNEL. The channel is read from the
//     message (realm_message_channel()) and checked with realm_channel_access(),
//     exactly as reactions do, so no id can reach into a raven between two other
//     people.
//   * ONLY THE AUTHOR EDITS OR DELETES, checked here against the session's own
//     account, whatever the page sent. There is no admin override, the same as
//     for reading ravens.
//   * A DELETED MESSAGE KEEPS ITS ROW ("This message was deleted"), so a thread
//     under it, and the channel's order and unread counts, are not disturbed.
//     Its words and its picture are emptied at once.
//   * NO NEW POLLING. Edits and deletions reach the others on the 3-second poll
//     an open conversation already makes (realm_changed_messages()); reminders
//     ring on the bell's 30-second poll (notify_poll.php) and the Realm's
//     25-second heartbeat, whichever comes first.
//
// ⚠ THIS FILE IS ALSO LOADED BY notify_poll.php, every 30 seconds on every page
// of Anima. realm_reminders_ring() must stay cheap and must never throw, and
// must not call anything from includes/realm_chat.php (not loaded there).

/** The reminder times the save menu offers, in its order. */
const REALM_REMIND = ['20m' => 'In 20 minutes', '1h' => 'In 1 hour', '3h' => 'In 3 hours', 'tomorrow' => 'Tomorrow at 9:00'];

/** Has migration 096 arrived? Asked once per request; never throws. */
function realm_msgact_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if (!function_exists('column_exists') || !column_exists($pdo, 'realm_messages', 'fwd_from')) return $ok = false;
        $pdo->query('SELECT 1 FROM realm_saved LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * The words that name where a forwarded message was first said, as THIS reader
 * may see it: "#The Tavern" for a channel they can open, "a raven" for any raven
 * (whose two people are nobody else's business), "a show channel" for a show
 * they may not see (whose name must not leak through a forward).
 */
function realm_fwd_place(PDO $pdo, string $channel): string {
    static $names = null;
    if (strpos($channel, 'dm:') === 0) return 'a raven';
    if ($names === null) {
        $names = [];
        try {
            foreach (realm_channels($pdo, false) as $c) $names[$c['id']] = (string)$c['name'];
        } catch (Throwable $e) {
            // Every place is then named in general terms below.
        }
    }
    if (isset($names[$channel])) return '#' . $names[$channel];
    return strpos($channel, 'project:') === 0 ? 'a show channel' : 'a channel';
}

/**
 * This person's three most-used reactions, most used first, from the Realm's
 * fixed set (REALM_REACTIONS). Fewer than three when they have reacted with
 * fewer; the page fills the rest. Never throws.
 */
function realm_reaction_favs(PDO $pdo, int $uid): array {
    if (!function_exists('realm_reactions_available') || !realm_reactions_available($pdo)) return [];
    try {
        $q = $pdo->prepare('SELECT emoji, COUNT(*) AS n FROM realm_reactions WHERE user_id = ? GROUP BY emoji ORDER BY n DESC, MAX(created_at) DESC LIMIT 6');
        $q->execute([$uid]);
        $out = [];
        foreach ($q->fetchAll() as $r) {
            if (in_array((string)$r['emoji'], REALM_REACTIONS, true)) $out[] = (string)$r['emoji'];
            if (count($out) >= 3) break;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** One message's row, for the checks below, or null. */
function realm_msgact_row(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $q = $pdo->prepare('SELECT * FROM realm_messages WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/**
 * Change the words of my own message. Returns '' when done, or the sentence to
 * show. A forwarded copy is not mine to reword: it quotes somebody else.
 */
function realm_edit_message(PDO $pdo, int $uid, int $id, string $body): string {
    if (!realm_msgact_available($pdo)) return 'Editing needs a database update first. An administrator opens migrate.php once.';
    $m = realm_msgact_row($pdo, $id);
    if (!$m || (int)$m['user_id'] !== $uid) return 'Only the person who wrote a message can edit it.';
    if ($m['deleted_at'] !== null) return 'That message was deleted.';
    if ($m['fwd_from'] !== null) return 'A forwarded message cannot be edited. Delete it and forward it again.';
    $body = trim(str_replace("\r\n", "\n", $body));
    if (function_exists('mb_substr')) $body = mb_substr($body, 0, REALM_MESSAGE_MAX);
    else $body = substr($body, 0, REALM_MESSAGE_MAX);
    if ($body === '' && (string)($m['image'] ?? '') === '') return 'A message needs some words. To take it away, delete it.';
    if ($body === (string)$m['body']) return '';
    $pdo->prepare('UPDATE realm_messages SET body = ?, edited_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$body, $id, $uid]);
    return '';
}

/**
 * Delete my own message. The row stays as "This message was deleted"; its words
 * and picture go at once, and so do its reactions.
 *
 * The picture's FILE is left in the uploads folder on purpose: a forwarded copy
 * points at the same file, and the person it was forwarded to keeps what was
 * forwarded. Nothing can reach the file through this message any more, because
 * realm_image.php reads the (now empty) column.
 */
function realm_delete_message(PDO $pdo, int $uid, int $id): string {
    if (!realm_msgact_available($pdo)) return 'Deleting needs a database update first. An administrator opens migrate.php once.';
    $m = realm_msgact_row($pdo, $id);
    if (!$m || (int)$m['user_id'] !== $uid) return 'Only the person who wrote a message can delete it.';
    if ($m['deleted_at'] !== null) return '';
    $set = 'body = \'\', deleted_at = NOW()' . (function_exists('realm_chat_images') && realm_chat_images($pdo) ? ', image = NULL, image_name = NULL, image_mime = NULL' : '');
    $pdo->prepare('UPDATE realm_messages SET ' . $set . ' WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    try {
        $pdo->prepare('DELETE FROM realm_reactions WHERE message_id = ?')->execute([$id]);
    } catch (Throwable $e) {
        // Reactions not there yet (migration 093): nothing to take away.
    }
    return '';
}

/**
 * Mark a channel unread from this message on, for me: the count beside the
 * channel comes back from here. The caller has checked realm_channel_access()
 * for the message's own channel. Moves the mark BACK, which realm_mark_read()
 * never does, so it is its own statement.
 */
function realm_mark_unread_from(PDO $pdo, int $uid, string $channel, int $id): void {
    $pdo->prepare('INSERT INTO realm_reads (user_id, channel, last_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE last_id = VALUES(last_id)')
        ->execute([$uid, $channel, max(0, $id - 1)]);
}

/**
 * Forward a message to another channel or raven. Returns '' when done, or the
 * sentence to show. An optional note goes first, as my own ordinary message.
 *
 * ⚠ TWO LOCKS: I must be able to READ where the message is, and WRITE where it
 * goes. Both are asked of realm_channel_access(); the source channel is the
 * message's own, never one that was posted.
 */
function realm_forward(PDO $pdo, int $uid, int $id, string $to, string $note, bool $mayAnnounce): string {
    if (!realm_msgact_available($pdo)) return 'Forwarding needs a database update first. An administrator opens migrate.php once.';
    $m = realm_msgact_row($pdo, $id);
    if (!$m || !realm_channel_access($pdo, $uid, (string)$m['channel'], $mayAnnounce)['read']) return 'That message is no longer there.';
    if ($m['deleted_at'] !== null) return 'That message was deleted.';
    $may = realm_channel_access($pdo, $uid, $to, $mayAnnounce);
    if (!$may['read']) return 'That conversation is not yours to write in.';
    if (!$may['post']) return 'Only Production can write in Announcements.';

    $note = trim($note);
    if ($note !== '') {
        $said = realm_say($pdo, $uid, $to, $note);
        if ($said !== '') return $said;
    }
    // A copy of a copy names the ORIGINAL: where it was first said, by whom, when.
    $first = $m['fwd_from'] !== null;
    $cols = ['channel', 'user_id', 'body', 'fwd_from', 'fwd_channel', 'fwd_user', 'fwd_at'];
    $vals = [$to, $uid, (string)$m['body'],
             $first ? (int)$m['fwd_from'] : (int)$m['id'],
             $first ? (string)$m['fwd_channel'] : (string)$m['channel'],
             $first ? ($m['fwd_user'] === null ? null : (int)$m['fwd_user']) : ($m['user_id'] === null ? null : (int)$m['user_id']),
             $first ? $m['fwd_at'] : $m['created_at']];
    // The picture travels too: the copy points at the same stored file, and
    // realm_image.php serves it to whoever may read the COPY's conversation.
    if ((string)($m['image'] ?? '') !== '') {
        array_push($cols, 'image', 'image_name', 'image_mime');
        array_push($vals, (string)$m['image'], (string)$m['image_name'], (string)$m['image_mime']);
    }
    $pdo->prepare('INSERT INTO realm_messages (' . implode(', ', $cols) . ', created_at) VALUES ('
                  . implode(', ', array_fill(0, count($vals), '?')) . ', NOW())')
        ->execute($vals);
    // The bell, as for anything I write there: a raven rings the other person;
    // in a channel only my own note can tag anybody.
    if (preg_match('/^dm:(\d+):(\d+)$/', $to, $dm) === 1) {
        realm_raven_bell($pdo, $uid, (int)$dm[1] === $uid ? (int)$dm[2] : (int)$dm[1]);
    } elseif ($note !== '') {
        realm_tag_bell($pdo, $uid, $to, $note, $mayAnnounce);
    }
    return '';
}

// ---- saved for later, and reminders ------------------------------------------

/** When a reminder word means, as a time the database can hold, or null. */
function realm_remind_time(string $when): ?string {
    $now = time();
    switch ($when) {
        case '20m': return date('Y-m-d H:i:s', $now + 20 * 60);
        case '1h':  return date('Y-m-d H:i:s', $now + 3600);
        case '3h':  return date('Y-m-d H:i:s', $now + 3 * 3600);
        case 'tomorrow': return date('Y-m-d 09:00:00', strtotime('+1 day', $now));
    }
    return null;
}

/** A reminder time as the page prints it: "15:20", "tomorrow 09:00", "14 Nov, 09:00". */
function realm_remind_words(?string $at): string {
    if ($at === null || $at === '') return '';
    $t = strtotime($at);
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'today ' . date('H:i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('+1 day'))) return 'tomorrow ' . date('H:i', $t);
    return date('j M, H:i', $t);
}

/**
 * Save a message for later, with or without a reminder ($when: '' for none, or a
 * key of REALM_REMIND). Saving again changes the reminder. The caller has
 * checked I may read the message. Returns '' or the sentence to show.
 */
function realm_save_message(PDO $pdo, int $uid, int $id, string $when): string {
    if (!realm_msgact_available($pdo)) return 'Saving needs a database update first. An administrator opens migrate.php once.';
    if ($when !== '' && !array_key_exists($when, REALM_REMIND)) return 'That reminder time is not one the Realm offers.';
    $at = $when === '' ? null : realm_remind_time($when);
    $pdo->prepare('INSERT INTO realm_saved (user_id, message_id, remind_at, rung, created_at) VALUES (?, ?, ?, 0, NOW())
                   ON DUPLICATE KEY UPDATE remind_at = VALUES(remind_at), rung = 0')
        ->execute([$uid, $id, $at]);
    return '';
}

/** Take a message out of my saved list. Only ever my own line. */
function realm_unsave_message(PDO $pdo, int $uid, int $id): void {
    if (!realm_msgact_available($pdo)) return;
    $pdo->prepare('DELETE FROM realm_saved WHERE user_id = ? AND message_id = ?')->execute([$uid, $id]);
}

/**
 * Which messages of this channel, from $from on, I have saved: id => the
 * reminder in words ('' for none, or one that has already rung). What the bar
 * needs to say "Saved". Never throws.
 */
function realm_saved_here(PDO $pdo, int $uid, string $channel, int $from): array {
    if ($from <= 0 || !realm_msgact_available($pdo)) return [];
    try {
        $q = $pdo->prepare('SELECT s.message_id, s.remind_at, s.rung FROM realm_saved s JOIN realm_messages m ON m.id = s.message_id
                             WHERE s.user_id = ? AND m.channel = ? AND m.id >= ?');
        $q->execute([$uid, $channel, $from]);
        $out = [];
        foreach ($q->fetchAll() as $r) {
            $out[(string)(int)$r['message_id']] = (int)$r['rung'] === 1 ? '' : realm_remind_words($r['remind_at']);
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * My saved messages: reminders still to come first (soonest first), then the
 * rest, the latest saved first. Only messages in places I may still read: a
 * show I was taken off, say, drops out of the list.
 */
function realm_saved_list(PDO $pdo, int $uid, bool $mayAnnounce): array {
    if (!realm_msgact_available($pdo)) return [];
    [$may, $vals] = realm_channel_filter($pdo, $uid, $mayAnnounce, 'm.channel');
    $threads = realm_threads_available($pdo);
    $q = $pdo->prepare('SELECT m.id, m.channel, m.user_id, m.body, m.created_at, m.deleted_at, '
                     . (realm_chat_images($pdo) ? 'm.image' : 'NULL') . ' AS image, '
                     . ($threads ? 'IF(m.also_channel = 1, NULL, m.parent_id)' : 'NULL') . ' AS thread_of,
                              s.remind_at, s.rung, u.display_name, u.username, c.name AS crew_name
                         FROM realm_saved s
                         JOIN realm_messages m ON m.id = s.message_id
                    LEFT JOIN users u ON u.id = m.user_id
                    LEFT JOIN crew c ON c.id = u.crew_id
                        WHERE s.user_id = ? AND ' . $may . '
                     ORDER BY (s.remind_at IS NOT NULL AND s.rung = 0) DESC, s.remind_at, s.created_at DESC
                        LIMIT 200');
    $q->execute(array_merge([$uid], $vals));
    $places = function_exists('realm_search_places') ? realm_search_places($pdo, $uid, $mayAnnounce) : [];
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $deleted = $r['deleted_at'] !== null;
        $out[] = [
            'id'      => (int)$r['id'],
            'channel' => (string)$r['channel'],
            'where'   => $places[(string)$r['channel']] ?? 'Chat',
            'thread'  => (int)($r['thread_of'] ?? 0),
            'name'    => $r['user_id'] === null ? 'Somebody who left' : trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username']))),
            'mine'    => (int)$r['user_id'] === $uid,
            'about'   => $deleted ? 'This message was deleted.' : realm_chat_snippet((string)$r['body']),
            'deleted' => $deleted,
            'image'   => (string)($r['image'] ?? '') !== '',
            'at'      => realm_chat_when(strtotime((string)$r['created_at'])),
            'remind'  => (int)$r['rung'] === 1 ? '' : realm_remind_words($r['remind_at']),
        ];
    }
    return $out;
}

/**
 * Ring the bell for every reminder of mine whose time has come, once each.
 * Returns what was rung, for the Realm to show as a notice too:
 * [['id', 'name', 'channel']].
 *
 * Called by notify_poll.php (any page of Anima, every 30 seconds) and by the
 * Realm's heartbeat. ⚠ CHEAP AND SILENT: one indexed query that finds nothing
 * is the usual case, and nothing here throws. The UPDATE ... AND rung = 0 makes
 * two pages asking at once ring once.
 *
 * The bell line says whose message, never its words, and not where: the bell
 * is shown on every page and the place may be a show's channel. Clicking it
 * opens the Realm at the message (notify_link(): realm.php?msg=<id>), where
 * the usual check decides whether it may still be read.
 */
function realm_reminders_ring(PDO $pdo, int $uid): array {
    $rung = [];
    if ($uid <= 0) return $rung;
    try {
        $q = $pdo->prepare('SELECT s.message_id, m.channel, m.user_id, u.crew_id AS my_crew,
                                   au.display_name, au.username, ac.name AS crew_name
                              FROM realm_saved s
                              JOIN realm_messages m ON m.id = s.message_id
                              JOIN users u ON u.id = s.user_id
                         LEFT JOIN users au ON au.id = m.user_id
                         LEFT JOIN crew ac ON ac.id = au.crew_id
                             WHERE s.user_id = ? AND s.rung = 0 AND s.remind_at IS NOT NULL AND s.remind_at <= NOW()
                             LIMIT 20');
        $q->execute([$uid]);
        $due = $q->fetchAll();
        if (!$due) return $rung;
        $mark = $pdo->prepare('UPDATE realm_saved SET rung = 1 WHERE user_id = ? AND message_id = ? AND rung = 0');
        foreach ($due as $r) {
            $mark->execute([$uid, (int)$r['message_id']]);
            if ($mark->rowCount() === 0) continue;   // another page rang it a moment ago
            $mine = (int)$r['user_id'] === $uid;
            $who = $r['user_id'] === null ? 'somebody' : trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])));
            $rung[] = ['id' => (int)$r['message_id'], 'name' => $mine ? 'You' : $who, 'channel' => (string)$r['channel']];
            $crew = (int)($r['my_crew'] ?? 0);
            if ($crew > 0 && function_exists('notify_add')) {
                notify_add($pdo, [$crew], ['kind' => 'later', 'actor' => 'Reminder',
                    'summary' => ($mine ? 'your message' : $who . '\'s message') . ' in the Realm',
                    'item_key' => (string)(int)$r['message_id']]);
            }
        }
    } catch (Throwable $e) {
        // Before migration 096 there is no table: nothing is due, nothing rings.
    }
    return $rung;
}
