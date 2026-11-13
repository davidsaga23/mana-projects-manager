<?php
// The Realm's chat: channels people make themselves (migration 096).
//
// David, 2026-11-13, with a picture of the channel list: "let's add the option
// to create new channels (public/private) and ask if it's linked to a specific
// project so they can be nested inside the general channels for each project".
//
// What was settled, and is relied on below:
//   * A MADE CHANNEL IS "ch:<id>" in the chat, next to the built-in ones
//     ("tavern", "project:12", ...), which are not rows in any table and do not
//     change. realm_channels() in includes/realm_chat.php adds the made ones to
//     its list, so every place that already asks that list who may read what
//     (opening a channel, unread counts, the Threads row, search, pictures,
//     reactions, the bell) asks it about these too. There is no second list.
//   * PUBLIC: everybody who lives in the Realm reads and writes it, the way the
//     Tavern works today. Nobody has to join.
//   * PRIVATE: only its members. ⚠ NOBODY ELSE IS TOLD IT EXISTS, admins
//     included, the same rule as ravens ("They should only be visible for the
//     2 in a DM", David, 2026-11-12). It is not in their list, their counts or
//     their search, and opening it by its id answers as if there were no such
//     channel.
//   * FOR A SHOW: listed under that show's own channel, and only for people
//     who may see that show (may_see_project()), public or private, the rule a
//     show's own channel already follows.
//   * WHO MAY DO WHAT (proposed to David, 2026-11-13; one place to change, see
//     realm_made_may_manage()): anybody in the Realm may make a channel, public
//     or private. The person who made it, and Production and admins, may rename
//     it, change who is in it (private only) and archive it, but only a channel
//     they can see: an admin who is not in a private channel cannot reach it.
//     Anybody in a private channel may leave it, except the one who made it.
//   * ARCHIVED: gone from the list and nobody writes in it, but nothing is
//     deleted. Its messages are still found by the search and open from there.
//
// ⚠ EVERY WRITE NAMES A CHANNEL BY ITS ID, AND THE ID IS CHECKED HERE against
// what the session's own account may see (realm_made_find()), never trusted.

/** The longest name a channel may have. */
const REALM_CHANNEL_NAME_MAX = 60;

/** Has migration 096 made the tables? Asked once per request; never throws. */
function realm_made_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_chat_channels LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_chat_members LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** The number in "ch:12", or 0 when the id is not a made channel's. */
function realm_made_num(string $channel): int {
    return preg_match('/^ch:(\d{1,9})$/', $channel, $m) === 1 ? (int)$m[1] : 0;
}

/**
 * The made channels this person may read, as rows, by name. Archived ones only
 * with $archived. Kept for the rest of the request: realm_channels() is asked
 * several times per answer. realm_made_forget() empties it after a change.
 * Never throws: an empty list is "none".
 */
function realm_made_rows(PDO $pdo, int $uid, bool $archived = false): array {
    $memo = &realm_made_memo();
    $key = $uid . ($archived ? 'a' : '');
    if (isset($memo[$key])) return $memo[$key];
    if ($uid <= 0 || !realm_made_available($pdo)) return $memo[$key] = [];
    $out = [];
    try {
        // ⚠ THE PRIVACY RULE IS THIS WHERE: a private channel only with a
        // membership row for this very account. No role reaches past it.
        $q = $pdo->prepare('SELECT c.id, c.name, c.is_private, c.project_id, c.created_by, c.archived_at
                              FROM realm_chat_channels c
                         LEFT JOIN realm_chat_members m ON m.channel_id = c.id AND m.user_id = ?
                             WHERE (c.is_private = 0 OR m.user_id IS NOT NULL)'
                           . ($archived ? '' : ' AND c.archived_at IS NULL') . '
                          ORDER BY c.name, c.id');
        $q->execute([$uid]);
        foreach ($q->fetchAll() as $r) {
            $pid = (int)($r['project_id'] ?? 0);
            // A show's channel is for people who may see that show.
            if ($pid > 0 && function_exists('may_see_project') && !may_see_project($pdo, $pid)) continue;
            $out[] = $r;
        }
    } catch (Throwable $e) {
        $out = [];
    }
    return $memo[$key] = $out;
}

/** The lists read so far in this request, shared by the two functions around it. */
function &realm_made_memo(): array {
    static $memo = [];
    return $memo;
}

/** Something was made, renamed, archived or joined: read the lists again. */
function realm_made_forget(): void {
    $memo = &realm_made_memo();
    $memo = [];
}

/**
 * The made channels as realm_channels() lists them. `private`, `show` (the
 * show's project id, 0 for studio-wide) and `made` are what the side panel
 * needs to put a lock on it and nest it under its show.
 */
function realm_made_channels(PDO $pdo, int $uid, bool $archived = false): array {
    $out = [];
    foreach (realm_made_rows($pdo, $uid, $archived) as $r) {
        $gone = $r['archived_at'] !== null;
        $out[] = [
            'id'       => 'ch:' . (int)$r['id'],
            'name'     => (string)$r['name'],
            'post'     => !$gone,
            'private'  => (int)$r['is_private'] === 1,
            'show'     => (int)($r['project_id'] ?? 0),
            'made'     => true,
            'archived' => $gone,
        ];
    }
    return $out;
}

/** One made channel this person may read (archived too), or null. The check every write starts with. */
function realm_made_find(PDO $pdo, int $uid, string $channel): ?array {
    $n = realm_made_num($channel);
    if ($n <= 0) return null;
    foreach (realm_made_rows($pdo, $uid, true) as $r) {
        if ((int)$r['id'] === $n) return $r;
    }
    return null;
}

/**
 * May this person rename, archive and choose the members of this channel? The
 * one who made it, or Production and admins. The channel came from
 * realm_made_find(), so they can already see it.
 */
function realm_made_may_manage(array $row, int $uid): bool {
    if ((int)($row['created_by'] ?? 0) === $uid) return true;
    return (function_exists('can_edit') && can_edit()) || (function_exists('is_admin') && is_admin());
}

/** Everybody who lives in the Realm: user id => the name the Realm shows. The only people a channel can hold. */
function realm_made_people(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT rp.user_id, u.display_name, u.username, c.name AS crew_name
                            FROM realm_people rp JOIN users u ON u.id = rp.user_id
                       LEFT JOIN crew c ON c.id = u.crew_id') as $r) {
        $out[(int)$r['user_id']] = trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])));
    }
    return $out;
}

/** The ids in "3,7,12", kept only if they live in the Realm. */
function realm_made_pick(PDO $pdo, string $ids): array {
    $people = realm_made_people($pdo);
    $out = [];
    foreach (explode(',', $ids) as $id) {
        $id = (int)trim($id);
        if ($id > 0 && isset($people[$id]) && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out;
}

/** A name as typed, tidied: one line, no "#" in front, at most REALM_CHANNEL_NAME_MAX letters. */
function realm_made_name(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = preg_replace('/^[#\s]+/u', '', $name) ?? '';
    if (function_exists('mb_substr')) return trim(mb_substr($name, 0, REALM_CHANNEL_NAME_MAX));
    return trim(substr($name, 0, REALM_CHANNEL_NAME_MAX));
}

/**
 * Is that name already used by another channel this person can see, in the
 * same place (the same show, or studio-wide)? Only channels they can see are
 * asked, so the answer never gives away a private channel's name.
 */
function realm_made_name_taken(PDO $pdo, int $uid, string $name, int $show, int $except = 0): bool {
    $low = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    foreach (realm_made_rows($pdo, $uid) as $r) {
        if ((int)$r['id'] !== $except && (int)($r['project_id'] ?? 0) === $show && $low((string)$r['name']) === $low($name)) return true;
    }
    // The built-in channels, and a show's own channel, keep their names too.
    foreach (realm_channels($pdo, false, $uid) as $c) {
        if (empty($c['made']) && $show === 0 && $low((string)$c['name']) === $low($name)) return true;
    }
    return false;
}

/**
 * Make a channel. Returns ['id' => 'ch:N'] or ['error' => sentence].
 * $show is a project id or 0; it must be a show that has a channel of its own
 * which this person may open, the list the form offered.
 */
function realm_made_create(PDO $pdo, int $uid, string $name, bool $private, int $show, string $memberIds): array {
    if (!realm_made_available($pdo)) return ['error' => 'New channels need a database update first. An administrator opens migrate.php once.'];
    $name = realm_made_name($name);
    if ($name === '') return ['error' => 'Give the channel a name.'];
    if ($show > 0) {
        $ok = false;
        foreach (realm_channels($pdo, false, $uid) as $c) if ($c['id'] === 'project:' . $show) $ok = true;
        if (!$ok) return ['error' => 'That show has no channel you can open.'];
    }
    if (realm_made_name_taken($pdo, $uid, $name, $show)) return ['error' => 'There is already a channel called "' . $name . '" there. Pick another name.'];
    $members = $private ? realm_made_pick($pdo, $memberIds) : [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO realm_chat_channels (name, is_private, project_id, created_by, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$name, $private ? 1 : 0, $show > 0 ? $show : null, $uid]);
        $id = (int)$pdo->lastInsertId();
        if ($private) {
            // The one who made it is always in it.
            if (!in_array($uid, $members, true)) array_unshift($members, $uid);
            $add = $pdo->prepare('INSERT IGNORE INTO realm_chat_members (channel_id, user_id, added_by, added_at) VALUES (?, ?, ?, NOW())');
            foreach ($members as $m) $add->execute([$id, $m, $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    realm_made_forget();
    return ['id' => 'ch:' . $id];
}

/**
 * Rename, archive, change members, or leave. $what is one of rename, archive,
 * members, leave. Returns '' when done, or the sentence to show.
 *
 * ⚠ THE CHANNEL IS LOOKED UP AS THIS ACCOUNT SEES IT. A private channel they
 * are not in answers "not there", exactly like one that does not exist.
 */
function realm_made_change(PDO $pdo, int $uid, string $channel, string $what, array $in): string {
    if (!realm_made_available($pdo)) return 'Channels need a database update first. An administrator opens migrate.php once.';
    $row = realm_made_find($pdo, $uid, $channel);
    if ($row === null) return 'That channel is not there any more.';
    $id = (int)$row['id'];
    $private = (int)$row['is_private'] === 1;
    $gone = $row['archived_at'] !== null;

    if ($what === 'leave') {
        if (!$private) return 'Everybody is in a public channel; there is nothing to leave.';
        if ((int)$row['created_by'] === $uid) return 'You made this channel, so you stay in it. Archive it instead if it is no longer needed.';
        $pdo->prepare('DELETE FROM realm_chat_members WHERE channel_id = ? AND user_id = ?')->execute([$id, $uid]);
        realm_made_forget();
        return '';
    }
    if (!realm_made_may_manage($row, $uid)) return 'Only the person who made this channel, or Production, can change it.';
    if ($gone) return 'This channel is archived, so it cannot be changed.';

    if ($what === 'rename') {
        $name = realm_made_name((string)($in['name'] ?? ''));
        if ($name === '') return 'Give the channel a name.';
        if (realm_made_name_taken($pdo, $uid, $name, (int)($row['project_id'] ?? 0), $id)) return 'There is already a channel called "' . $name . '" there. Pick another name.';
        $pdo->prepare('UPDATE realm_chat_channels SET name = ? WHERE id = ?')->execute([$name, $id]);
    } elseif ($what === 'archive') {
        $pdo->prepare('UPDATE realm_chat_channels SET archived_at = NOW(), archived_by = ? WHERE id = ? AND archived_at IS NULL')->execute([$uid, $id]);
    } elseif ($what === 'members') {
        if (!$private) return 'Everybody is in a public channel already.';
        $add = realm_made_pick($pdo, (string)($in['add'] ?? ''));
        $remove = realm_made_pick($pdo, (string)($in['remove'] ?? ''));
        // The one who made it stays in it: otherwise a private channel can end
        // up with nobody able to manage it but Production, who cannot see it.
        $remove = array_values(array_filter($remove, function ($m) use ($row) { return $m !== (int)$row['created_by']; }));
        $ins = $pdo->prepare('INSERT IGNORE INTO realm_chat_members (channel_id, user_id, added_by, added_at) VALUES (?, ?, ?, NOW())');
        foreach ($add as $m) $ins->execute([$id, $m, $uid]);
        $del = $pdo->prepare('DELETE FROM realm_chat_members WHERE channel_id = ? AND user_id = ?');
        foreach ($remove as $m) $del->execute([$id, $m]);
    } else {
        return 'Nothing was asked.';
    }
    realm_made_forget();
    return '';
}

/**
 * What the head of an open made channel needs: its name, whether it is private
 * or archived, who may manage it, and, for a private one, who is in it. Only
 * ever asked after realm_channel_access() said this person may read it.
 */
function realm_made_info(PDO $pdo, int $uid, string $channel): ?array {
    $row = realm_made_find($pdo, $uid, $channel);
    if ($row === null) return null;
    $private = (int)$row['is_private'] === 1;
    $members = [];
    if ($private) {
        $people = realm_made_people($pdo);
        $q = $pdo->prepare('SELECT m.user_id, u.display_name, u.username, c.name AS crew_name
                              FROM realm_chat_members m JOIN users u ON u.id = m.user_id
                         LEFT JOIN crew c ON c.id = u.crew_id WHERE m.channel_id = ?');
        $q->execute([(int)$row['id']]);
        foreach ($q->fetchAll() as $r) {
            $mid = (int)$r['user_id'];
            $members[] = ['id' => $mid, 'name' => $people[$mid] ?? trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])))];
        }
        usort($members, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    return [
        'name'     => (string)$row['name'],
        'private'  => $private,
        'show'     => (int)($row['project_id'] ?? 0),
        'archived' => $row['archived_at'] !== null,
        'manage'   => realm_made_may_manage($row, $uid) && $row['archived_at'] === null,
        'mine'     => (int)($row['created_by'] ?? 0) === $uid,
        'by'       => (int)($row['created_by'] ?? 0),
        'members'  => $members,
    ];
}

/**
 * Who in a private channel may be rung by a tag: its members. For any other
 * channel null, meaning "as before".
 */
function realm_made_ring_only(PDO $pdo, string $channel): ?array {
    $n = realm_made_num($channel);
    if ($n <= 0 || !realm_made_available($pdo)) return null;
    try {
        $q = $pdo->prepare('SELECT is_private FROM realm_chat_channels WHERE id = ?');
        $q->execute([$n]);
        if ((int)$q->fetchColumn() !== 1) return null;
        $q = $pdo->prepare('SELECT user_id FROM realm_chat_members WHERE channel_id = ?');
        $q->execute([$n]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}
