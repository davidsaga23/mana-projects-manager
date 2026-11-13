<?php
// The Realm: the inside of a person's house, and what hangs in it (migration 086).
//
// David, 2026-11-12, in the "My house" thread: "let's start with decorate my
// office. Let's all start with the same basic look and they can modify it from a
// library of free and opt to buy new stuff. All of them should have at least
// these: Book shelf: for tasks. Treasure chest: to check their gold. A portrait
// in the wall: so they can see theirs and others bio and profile from Anima, and
// update it."
//
// What that became:
//   * ONE STARTING ROOM FOR EVERYBODY (realm_room_default()). Cottage, Tower and
//     Forge are now only the OUTSIDE of a house; the inside starts the same and is
//     the owner's to change.
//   * A ROOM IS A LIST OF THINGS AND WHERE THEY STAND, plus three choices (wall,
//     floor, rug). One row per person in realm_rooms, the list kept as JSON: it
//     is always read and written whole, and never searched.
//   * THREE THINGS ARE ALWAYS THERE: the bookshelf (tasks), the chest (gold) and
//     the portrait (profile). They can be moved, never removed, and there is
//     exactly one of each, so every house has the three ways in.
//   * THE LIBRARY IS REALM_ROOM_THINGS. Everything in it is free except the rows
//     marked gold, which are shown as "for sale later" and cannot be placed until
//     gold exists (stage 4). No prices are stored: David has not set any.
//
// ⚠ THE LIST BELOW IS ALSO IN assets/realm.js (THINGS), which draws each thing.
// The sizes MUST match: this file decides whether a room is allowed, that file
// shows the person where things fit while they drag. A thing added in one place
// and not the other is either refused on save or drawn as nothing.
//
// ⚠ A PERSON SAVES ONLY THEIR OWN ROOM. No user id is posted: realm_api.php takes
// it from the session, like every other write in the Realm. Reading somebody
// else's room is allowed to anybody in the Realm, as looking through an open
// window would be; nothing private is in it.
//
// ⚠ NOTHING HERE TOUCHES MONEY, like the rest of the Realm.

/**
 * The library. id => [where it goes, width, height, height it hangs at (wall
 * things only), how many one room may hold, always present, costs gold].
 * Measured in the room's own units: the room is 240 wide and 160 tall, the back
 * wall is the band from 10 to 52, the floor from 52 to 150.
 */
const REALM_ROOM_THINGS = [
    // on the back wall
    'shelf'     => ['wall', 40, 32, 14, 1, true,  false],
    'portrait'  => ['wall', 18, 22, 16, 1, true,  false],
    'window'    => ['wall', 20, 18, 18, 4, false, false],
    'banner'    => ['wall', 12, 28, 13, 3, false, false],
    'clock'     => ['wall', 12, 22, 16, 1, false, false],
    'sconce'    => ['wall',  6, 12, 22, 4, false, false],
    'potions'   => ['wall', 22, 12, 22, 2, false, false],
    'map'       => ['wall', 26, 18, 18, 1, false, false],
    'hearth'    => ['wall', 56, 40, 12, 1, false, false],
    'trophy'    => ['wall', 22, 22, 16, 1, false, true],
    // on the floor
    'chest'     => ['floor', 22, 16, 0, 1, true,  false],
    'desk'      => ['floor', 46, 22, 0, 1, false, false],
    'chair'     => ['floor', 18, 22, 0, 2, false, false],
    'table'     => ['floor', 30, 18, 0, 1, false, false],
    'plant'     => ['floor', 12, 20, 0, 4, false, false],
    'barrel'    => ['floor', 16, 20, 0, 3, false, false],
    'crate'     => ['floor', 16, 14, 0, 3, false, false],
    'candle'    => ['floor',  8, 24, 0, 3, false, false],
    'anvil'     => ['floor', 32, 14, 0, 1, false, false],
    'telescope' => ['floor', 26, 30, 0, 1, false, false],
    'cauldron'  => ['floor', 22, 20, 0, 1, false, false],
    'orb'       => ['floor', 14, 22, 0, 1, false, true],
    'throne'    => ['floor', 22, 34, 0, 1, false, true],
];
/** The three choices about the room itself. The first of each is the starting one. */
const REALM_ROOM_STYLES = [
    'wall'  => ['plaster', 'stone', 'timber'],
    'floor' => ['planks', 'flags', 'checker'],
    'rug'   => ['classic', 'round', 'none'],
];
/** The most things one room may hold. Far more than fit; it only stops a made-up request. */
const REALM_ROOM_MOST = 30;

/** Has migration 086 run? Until it has, every room is the starting room. */
function realm_rooms_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_rooms LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** The room everybody starts with. assets/realm.js holds the same one (ROOM_START). */
function realm_room_default(): array {
    return ['wall' => 'plaster', 'floor' => 'planks', 'rug' => 'classic',
            'items' => [['window', 36, 18], ['shelf', 100, 14], ['portrait', 152, 16], ['window', 190, 18],
                        ['desk', 22, 58], ['chest', 186, 64]]];
}

/**
 * Is this a room somebody may have? Returns it tidied (known words, whole
 * numbers, wall things at their own height), or null with $why set to the
 * sentence to show.
 *
 * The same rules assets/realm.js applies while a thing is dragged, asked again
 * here because the screen is not the lock:
 *   * a wall thing keeps clear of the next one along the wall;
 *   * a floor thing stands on the floor, and two may not stand on the same
 *     ground (only the bottom 10 units count, so a tall thing may rise behind
 *     a low one, as it would in a real room);
 *   * the bookshelf, the chest and the portrait are there exactly once.
 */
function realm_room_check($in, string &$why): ?array {
    $why = 'That room could not be read. Try again.';
    if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) return null;
    $out = [];
    foreach (REALM_ROOM_STYLES as $part => $allowed) {
        $v = (string)($in[$part] ?? '');
        $out[$part] = in_array($v, $allowed, true) ? $v : $allowed[0];
    }
    if (count($in['items']) > REALM_ROOM_MOST) { $why = 'That is more than one room can hold.'; return null; }
    $count = [];
    $items = [];
    foreach ($in['items'] as $it) {
        if (!is_array($it) || count($it) < 3) return null;
        $id = (string)$it[0];
        if (!isset(REALM_ROOM_THINGS[$id]) || !is_numeric($it[1]) || !is_numeric($it[2])) return null;
        [$zone, $w, $h, $hang, $most, , $gold] = REALM_ROOM_THINGS[$id];
        if ($gold) { $why = 'That one is bought with gold, and gold has not arrived yet.'; return null; }
        $count[$id] = ($count[$id] ?? 0) + 1;
        if ($count[$id] > $most) { $why = 'There are too many of one thing in that room.'; return null; }
        $x = (int)$it[1];
        $y = $zone === 'wall' ? $hang : (int)$it[2];
        if ($x < 12 || $x > 228 - $w) { $why = 'Something is standing outside the room.'; return null; }
        if ($zone === 'floor' && ($y + $h < 60 || $y + $h > 148)) { $why = 'Something is standing outside the room.'; return null; }
        $items[] = [$id, $x, $y];
    }
    foreach (REALM_ROOM_THINGS as $id => $t) {
        if ($t[5] && ($count[$id] ?? 0) !== 1) { $why = 'The bookshelf, the chest and the portrait stay in every house.'; return null; }
    }
    // Nothing on top of anything else.
    $n = count($items);
    for ($a = 0; $a < $n; $a++) {
        for ($b = $a + 1; $b < $n; $b++) {
            if (realm_room_clash($items[$a], $items[$b])) { $why = 'Two things are in the same spot.'; return null; }
        }
    }
    $out['items'] = $items;
    return $out;
}

/** Do two placed things get in each other's way? See realm_room_check(). */
function realm_room_clash(array $a, array $b): bool {
    $ta = REALM_ROOM_THINGS[$a[0]];
    $tb = REALM_ROOM_THINGS[$b[0]];
    if ($ta[0] !== $tb[0]) return false;
    if ($ta[0] === 'wall') {
        // Two units of wall between neighbours, so their outlines do not touch.
        return $a[1] < $b[1] + $tb[1] + 2 && $b[1] < $a[1] + $ta[1] + 2;
    }
    $fa = min(10, $ta[2]);
    $fb = min(10, $tb[2]);
    $ay = $a[2] + $ta[2] - $fa;
    $by = $b[2] + $tb[2] - $fb;
    return $a[1] < $b[1] + $tb[1] && $b[1] < $a[1] + $ta[1] && $ay < $by + $fb && $by < $ay + $fa;
}

/**
 * Somebody's room. The starting room when they have never decorated, when the
 * migration has not run, or when what is stored no longer passes the rules (a
 * thing taken out of the library later must not leave a house that cannot be
 * drawn). Never throws.
 */
function realm_room_get(PDO $pdo, int $owner): array {
    if (!realm_rooms_available($pdo)) return realm_room_default();
    try {
        $q = $pdo->prepare('SELECT layout FROM realm_rooms WHERE user_id = ?');
        $q->execute([$owner]);
        $raw = $q->fetchColumn();
        if ($raw === false || $raw === null) return realm_room_default();
        $why = '';
        return realm_room_check(json_decode((string)$raw, true), $why) ?? realm_room_default();
    } catch (Throwable $e) {
        return realm_room_default();
    }
}

/** Save the asker's OWN room. Returns '' when saved, or the sentence to show. */
function realm_room_save(PDO $pdo, int $uid, string $json): string {
    if (!realm_rooms_available($pdo)) return 'Decorating needs a database update first. An administrator opens migrate.php once.';
    if (strlen($json) > 6000) return 'That is more than one room can hold.';
    $why = '';
    $room = realm_room_check(json_decode($json, true), $why);
    if ($room === null) return $why;
    // Only somebody with a house has a room to decorate.
    $q = $pdo->prepare('SELECT 1 FROM realm_people WHERE user_id = ? AND house_slot IS NOT NULL');
    $q->execute([$uid]);
    if (!$q->fetchColumn()) return 'You have no house yet, so there is no room to decorate.';
    $pdo->prepare('INSERT INTO realm_rooms (user_id, layout, updated_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE layout = VALUES(layout), updated_at = NOW()')
        ->execute([$uid, json_encode($room)]);
    return '';
}

/**
 * What the screen needs to draw $owner's room: the room itself, whether rooms
 * can be saved yet, and the address of the owner's profile picture for the
 * portrait frame ('' when they have none). Null when $owner has no house.
 */
function realm_room_answer(PDO $pdo, int $uid, int $owner): ?array {
    try {
        $photo = function_exists('column_exists') && column_exists($pdo, 'crew', 'photo_stored');
        $q = $pdo->prepare('SELECT u.crew_id, ' . ($photo ? 'c.photo_stored' : 'NULL') . ' AS photo
                              FROM realm_people rp
                              JOIN users u ON u.id = rp.user_id
                         LEFT JOIN crew c ON c.id = u.crew_id
                             WHERE rp.user_id = ? AND rp.house_slot IS NOT NULL');
        $q->execute([$owner]);
        $r = $q->fetch();
        if (!$r) return null;
        return ['owner' => $owner, 'mine' => $owner === $uid, 'ready' => realm_rooms_available($pdo),
                'layout' => realm_room_get($pdo, $owner),
                // The stored name is in the address only so that a new picture is a new address.
                'portrait' => ((string)($r['photo'] ?? '') !== '' && (int)$r['crew_id'] > 0)
                    ? 'avatar.php?crew=' . (int)$r['crew_id'] . '&v=' . substr(md5((string)$r['photo']), 0, 8) : ''];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The profile behind a portrait (David, 2026-11-12: "so they can see theirs and
 * others bio and profile from Anima, and update it"). The same profile as Home's
 * General info tab (migration 075): nothing is copied, the Realm reads and
 * writes Anima's own.
 *
 * ⚠ WHAT SOMEBODY ELSE SEES: the name, the role, the bio and the picture. The
 * phone number and the email address are given only to the person themselves.
 * David asked for "bio and profile"; contact details were not named, and handing
 * them to everybody who walks into a house is not something to guess at.
 *
 * Null when $owner has no house in the Realm.
 */
function realm_profile(PDO $pdo, int $uid, int $owner): ?array {
    try {
        $has = function_exists('column_exists') && column_exists($pdo, 'crew', 'photo_stored');
        $q = $pdo->prepare('SELECT u.crew_id, u.display_name, u.username, c.name, c.role, c.email' . ($has ? ', c.phone, c.bio, c.photo_stored' : '') . '
                              FROM realm_people rp
                              JOIN users u ON u.id = rp.user_id
                         LEFT JOIN crew c ON c.id = u.crew_id
                             WHERE rp.user_id = ? AND rp.house_slot IS NOT NULL');
        $q->execute([$owner]);
        $r = $q->fetch();
        if (!$r) return null;
        $mine = $owner === $uid;
        $crew = (int)$r['crew_id'];
        $out = ['id' => $owner, 'mine' => $mine,
                'name' => trim((string)($r['name'] ?: ($r['display_name'] ?: $r['username']))),
                'role' => trim((string)($r['role'] ?? '')),
                'bio' => trim((string)($r['bio'] ?? '')),
                'photo' => ((string)($r['photo_stored'] ?? '') !== '' && $crew > 0)
                    ? 'avatar.php?crew=' . $crew . '&v=' . substr(md5((string)$r['photo_stored']), 0, 8) : '',
                // May this person change it here? Their own, linked to a person, with 075 applied.
                'can_edit' => $mine && $crew > 0 && $has,
                'why' => !$mine ? '' : ($crew <= 0 ? 'Your login is not linked to a person yet, so there is no profile to fill in. An administrator links it on the Users page.'
                                                  : (!$has ? 'Profiles need database update 075, which has not been applied yet.' : ''))];
        if ($mine) {
            $out['phone'] = trim((string)($r['phone'] ?? ''));
            $out['email'] = trim((string)($r['email'] ?? ''));
        }
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}
