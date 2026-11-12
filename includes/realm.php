<?php
// The Realm — the studio as a village you can walk around (migration 081).
//
// David, 2026-11-12: "an epic fantasy office space where we can hang out, visit
// other artist's offices, request meetings so they open up a voice chat." This
// file is STAGE 1 of that: the village, who is in it, how each door stands and
// what each character wears. Chat, voice, mana and the Slack bridge are later
// stages and none of it is here.
//
// What was settled that day, and is relied on below:
//   * ONE VILLAGE. Everybody's house is in the same place whatever shows they
//     are on ("If a person is in 2 or more different projects where is their
//     office going to be? I think they should stay in one place only").
//   * A SHOW IS LANDSCAPE, not a neighbourhood ("mountains, valley, forest ...
//     I don't think we'll ever have more than 5 projects at the same time"), and
//     land with no show on it is drawn as inactive.
//   * A CHARACTER ON THE MAP MEANS THAT PERSON IS ONLINE. Resting after 25
//     minutes without touching the page (David's number), away once the page is
//     closed.
//   * OTHERS SEE WHERE YOU ARRIVED, never the walk ("Let's start only where they
//     have arrived"). So the only thing stored about movement is `place`.
//   * THREE DOORS: open (walk in), ajar (knock, which arrives with voice),
//     sealed (leave them be).
//
// ⚠ IT IS ALWAYS THE REAL LOGIN, NEVER A PREVIEWED ONE. Every function here
// takes the user id from realm_user_id(), which reads the session's own
// account. "View as Renata" must not make Renata appear at her door when she is
// not there, so the page and the API both refuse to run inside a preview.
//
// ⚠ NOTHING HERE TOUCHES MONEY. No rate, budget or invoice is read, and the
// finance connection is never opened.

/** How many plots the village has. Must match the plots assets/realm.js lays out. */
const REALM_SLOTS = 26;
/** A heartbeat older than this many seconds means the page is closed. */
const REALM_ONLINE_SECONDS = 90;
/** David, 2026-11-12: "25 min" before somebody shows as resting. */
const REALM_IDLE_MINUTES = 25;

const REALM_DOORS  = ['open', 'ajar', 'sealed'];
const REALM_KINDS  = ['study', 'tower', 'forge'];
const REALM_CLOAKS = ['candle', 'mana', 'spell', 'ember', 'violet', 'rose', 'teal'];
const REALM_HATS   = ['none', 'hat', 'hood'];
const REALM_PETS   = ['none', 'owl', 'cat'];
/** The five pieces of landscape, in the order a new show takes them. */
const REALM_LANDS  = ['peaks', 'wood', 'ash', 'lake', 'moor'];

/** Have the tables arrived (migration 081)? Asked once per request; never throws. */
function realm_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_people LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_lands LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** The account that is really logged in. Never the previewed one: see the note at the top. */
function realm_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Somebody opened the Realm. Give them a row and a plot if they have none, and
 * stand them at their own door: wherever they were yesterday, they arrive home.
 *
 * The plot is the lowest free number. Two people arriving in the same second
 * could both pick it, so the UNIQUE key decides and the loser simply tries the
 * next one. Never throws: a village that is full leaves house_slot NULL, and
 * that person sees the map but has no house until a plot is added.
 */
function realm_enter(PDO $pdo, int $uid): void {
    if ($uid <= 0) return;
    try {
        $pdo->prepare('INSERT IGNORE INTO realm_people (user_id, house_kind, cloak, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$uid, REALM_KINDS[$uid % count(REALM_KINDS)], REALM_CLOAKS[$uid % count(REALM_CLOAKS)]]);
        $pdo->prepare('UPDATE realm_people SET place = ?, inside = 0, last_ping = NOW(), last_active = NOW() WHERE user_id = ?')
            ->execute(['user:' . $uid, $uid]);

        $q = $pdo->prepare('SELECT house_slot FROM realm_people WHERE user_id = ?');
        $q->execute([$uid]);
        if ($q->fetchColumn() !== null) return;

        for ($try = 0; $try < 4; $try++) {
            $taken = array_map('intval', $pdo->query('SELECT house_slot FROM realm_people WHERE house_slot IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
            $free = null;
            for ($s = 0; $s < REALM_SLOTS; $s++) {
                if (!in_array($s, $taken, true)) { $free = $s; break; }
            }
            if ($free === null) return;
            try {
                $pdo->prepare('UPDATE realm_people SET house_slot = ? WHERE user_id = ? AND house_slot IS NULL')
                    ->execute([$free, $uid]);
                return;
            } catch (PDOException $e) {
                // Somebody took that plot between the read and the write. Look again.
            }
        }
    } catch (Throwable $e) {
        // The page still draws; it says the person has no house yet.
    }
}

/**
 * Match the five lands to the shows that are active right now. A show that is no
 * longer active gives its land back; a newly active one takes the first free
 * land. The Studio is not a show and never takes one.
 *
 * Called when somebody opens the page, not on every heartbeat: a show changes
 * status a few times a year.
 */
function realm_sync_lands(PDO $pdo): void {
    try {
        $extra = function_exists('studio_exclude_sql') ? studio_exclude_sql($pdo, 'p') : '';
        $active = array_map('intval', $pdo->query("SELECT p.id FROM projects p WHERE p.status = 'active'" . $extra . ' ORDER BY p.id')->fetchAll(PDO::FETCH_COLUMN));
        $claims = [];
        foreach ($pdo->query('SELECT land, project_id FROM realm_lands') as $r) {
            $claims[(string)$r['land']] = (int)$r['project_id'];
        }
        foreach ($claims as $land => $pid) {
            if (!in_array($pid, $active, true) || !in_array($land, REALM_LANDS, true)) {
                $pdo->prepare('DELETE FROM realm_lands WHERE land = ?')->execute([$land]);
                unset($claims[$land]);
            }
        }
        foreach ($active as $pid) {
            if (in_array($pid, $claims, true)) continue;
            foreach (REALM_LANDS as $land) {
                if (isset($claims[$land])) continue;
                $pdo->prepare('INSERT IGNORE INTO realm_lands (land, project_id, claimed_at) VALUES (?, ?, NOW())')->execute([$land, $pid]);
                $claims[$land] = $pid;
                break;
            }
        }
    } catch (Throwable $e) {
        // The map draws every land as unclaimed rather than failing.
    }
}

/**
 * The five lands and the show on each, for the person asking.
 *
 * ⚠ A SHOW'S NAME IS ONLY GIVEN TO SOMEBODY WHO MAY SEE THAT SHOW. Since
 * 2026-11-04 a scoped account only sees the shows it is staffed on, and the map
 * must not become the one place that rule leaks. Everybody sees that the land
 * is taken; only people on the show see by what.
 */
function realm_lands(PDO $pdo): array {
    $out = [];
    foreach (REALM_LANDS as $land) $out[$land] = ['land' => $land, 'claimed' => false, 'name' => '', 'project_id' => 0];
    try {
        $rows = $pdo->query('SELECT l.land, p.id, p.name FROM realm_lands l JOIN projects p ON p.id = l.project_id')->fetchAll();
        foreach ($rows as $r) {
            $land = (string)$r['land'];
            if (!isset($out[$land])) continue;
            $mine = function_exists('may_see_project') ? may_see_project($pdo, (int)$r['id']) : true;
            $out[$land] = ['land' => $land, 'claimed' => true,
                           'name' => $mine ? (string)$r['name'] : 'Another show',
                           'project_id' => $mine ? (int)$r['id'] : 0];
        }
    } catch (Throwable $e) {
        // Unclaimed, as above.
    }
    return array_values($out);
}

/** The heartbeat. $active says the person touched the page since the last one. */
function realm_ping(PDO $pdo, int $uid, bool $active): void {
    try {
        $pdo->prepare('UPDATE realm_people SET last_ping = NOW(), last_active = IF(?, NOW(), last_active) WHERE user_id = ?')
            ->execute([$active ? 1 : 0, $uid]);
    } catch (Throwable $e) {
        // A missed heartbeat shows the person as away a little early; the next one corrects it.
    }
}

/** Set your own door. Returns '' when saved, or the sentence to show. */
function realm_set_door(PDO $pdo, int $uid, string $door): string {
    if (!in_array($door, REALM_DOORS, true)) return 'That is not a way a door can stand.';
    $pdo->prepare('UPDATE realm_people SET door = ?, last_active = NOW() WHERE user_id = ?')->execute([$door, $uid]);
    return '';
}

/** Set what your own character wears and which kind of house you have. Unknown values are ignored. */
function realm_set_look(PDO $pdo, int $uid, array $in): string {
    $set = [];
    $args = [];
    foreach (['cloak' => REALM_CLOAKS, 'hat' => REALM_HATS, 'pet' => REALM_PETS, 'house_kind' => REALM_KINDS] as $col => $allowed) {
        $v = (string)($in[$col] ?? '');
        if ($v !== '' && in_array($v, $allowed, true)) { $set[] = $col . ' = ?'; $args[] = $v; }
    }
    if (!$set) return 'Nothing to change.';
    $args[] = $uid;
    $pdo->prepare('UPDATE realm_people SET ' . implode(', ', $set) . ', last_active = NOW() WHERE user_id = ?')->execute($args);
    return '';
}

/**
 * Your character arrived somewhere. $place is 'tavern', 'council', 'land:<name>'
 * or 'user:<id>'; $inside says whether you went in or stopped at the door.
 *
 * ⚠ THE DOOR IS CHECKED HERE, not only on the screen: going INSIDE somebody
 * else's house needs their door open and them at home. Standing outside is
 * always allowed. Your own house is always yours to enter.
 */
function realm_arrive(PDO $pdo, int $uid, string $place, bool $inside): string {
    if ($place === 'tavern' || $place === 'council') {
        // Always open.
    } elseif (strpos($place, 'land:') === 0) {
        $land = substr($place, 5);
        $q = $pdo->prepare('SELECT 1 FROM realm_lands WHERE land = ?');
        $q->execute([$land]);
        if (!in_array($land, REALM_LANDS, true) || !$q->fetchColumn()) return 'No show has made camp there.';
    } elseif (preg_match('/^user:(\d+)$/', $place, $m) === 1) {
        $owner = (int)$m[1];
        $q = $pdo->prepare('SELECT door, house_slot, TIMESTAMPDIFF(SECOND, last_ping, NOW()) AS age FROM realm_people WHERE user_id = ?');
        $q->execute([$owner]);
        $row = $q->fetch();
        if (!$row || $row['house_slot'] === null) return 'Nobody lives there.';
        if ($inside && $owner !== $uid) {
            if ($row['age'] === null || (int)$row['age'] > REALM_ONLINE_SECONDS) return 'Nobody is home.';
            if ($row['door'] === 'sealed') return 'That door is sealed. They are not to be disturbed.';
            // Ajar: only somebody the owner has just let in (migration 084).
            if ($row['door'] !== 'open' && !realm_has_pass($pdo, $uid, $owner)) return 'That door is ajar. Knock, and wait to be let in.';
        }
    } else {
        return 'That is not a place in the Realm.';
    }
    $pdo->prepare('UPDATE realm_people SET place = ?, inside = ?, last_ping = NOW(), last_active = NOW() WHERE user_id = ?')
        ->execute([$place, $inside ? 1 : 0, $uid]);
    return '';
}

/**
 * Everything the map draws: every person with a house, and the five lands.
 *
 * A person is 'online' (heartbeat fresh, touched the page lately), 'idle'
 * (heartbeat fresh, nothing touched for REALM_IDLE_MINUTES) or 'away'. Somebody
 * away is reported at home and outside, whatever was last stored: a closed page
 * cannot be standing in the tavern.
 *
 * `focus` is true while that person's Pomodoro is in a focus phase, read from
 * the timer Anima already runs (migrations 043 and 048). Nothing is written to
 * it. Clients and switched-off accounts are never listed.
 */
function realm_state(PDO $pdo, int $uid): array {
    $people = [];
    try {
        $timer = function_exists('column_exists') && column_exists($pdo, 'time_timers', 'phase');
        $sql = 'SELECT rp.user_id, rp.house_slot, rp.house_kind, rp.door, rp.place, rp.inside, rp.cloak, rp.hat, rp.pet,
                       TIMESTAMPDIFF(SECOND, rp.last_ping, NOW()) AS ping_age,
                       TIMESTAMPDIFF(MINUTE, rp.last_active, NOW()) AS idle_min,
                       u.display_name, u.username, c.name AS crew_name, ' . ($timer ? 't.phase' : 'NULL') . ' AS phase
                  FROM realm_people rp
                  JOIN users u ON u.id = rp.user_id
             LEFT JOIN crew c ON c.id = u.crew_id' . ($timer ? '
             LEFT JOIN time_timers t ON t.crew_id = u.crew_id' : '') . "
                 WHERE rp.house_slot IS NOT NULL AND u.active = 1 AND u.role <> 'client'
              ORDER BY rp.house_slot";
        foreach ($pdo->query($sql) as $r) {
            $id = (int)$r['user_id'];
            $here = $r['ping_age'] !== null && (int)$r['ping_age'] <= REALM_ONLINE_SECONDS;
            $state = !$here ? 'away' : (($r['idle_min'] !== null && (int)$r['idle_min'] >= REALM_IDLE_MINUTES) ? 'idle' : 'online');
            $name = trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])));
            $people[] = [
                'id'     => $id,
                'name'   => $name,
                'slot'   => (int)$r['house_slot'],
                'kind'   => in_array($r['house_kind'], REALM_KINDS, true) ? (string)$r['house_kind'] : 'study',
                'door'   => in_array($r['door'], REALM_DOORS, true) ? (string)$r['door'] : 'open',
                'state'  => $state,
                'place'  => ($here && (string)$r['place'] !== '') ? (string)$r['place'] : 'user:' . $id,
                'inside' => $here && (int)$r['inside'] === 1,
                'focus'  => $here && (string)$r['phase'] === 'focus',
                'cloak'  => in_array($r['cloak'], REALM_CLOAKS, true) ? (string)$r['cloak'] : 'mana',
                'hat'    => in_array($r['hat'], REALM_HATS, true) ? (string)$r['hat'] : 'none',
                'pet'    => in_array($r['pet'], REALM_PETS, true) ? (string)$r['pet'] : 'none',
                'me'     => $id === $uid,
            ];
        }
    } catch (Throwable $e) {
        $people = [];
    }
    return ['ok' => true, 'me' => $uid, 'people' => $people, 'lands' => realm_lands($pdo),
            'idle_minutes' => REALM_IDLE_MINUTES, 'slots' => REALM_SLOTS,
            'knocks' => realm_knock_news($pdo, $uid)];
}

// ---- knocking (migration 084) -------------------------------------------------
// David, 2026-11-12: "when a door is ajar, let's open the option to knock".
//
// Somebody standing at an ajar door knocks; the owner sees who it is and answers
// "Let in" or "Not now"; being let in walks the visitor inside. A door that is
// open needs no knock and a sealed one takes none.
//
// ⚠ FACE TO FACE. A knock is only taken from somebody whose character is
// standing at that door (their `place` is that house). It is not a way to ping
// somebody from across the map: that is what a raven is for.
//
// ⚠ THE ANSWER IS THE OWNER'S ALONE. realm_knock_answer() matches the knock to
// the session's own account, so nobody can let themselves in.

/** How long a knock waits for an answer, in seconds. */
const REALM_KNOCK_SECONDS = 120;
/** How long being let in lasts, in minutes. */
const REALM_KNOCK_PASS_MINUTES = 10;

/** Has migration 084 arrived? Asked once per request; never throws. */
function realm_knocks_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_knocks LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Knock on $owner's door. Returns '' when the knock was made, or the sentence to show. */
function realm_knock(PDO $pdo, int $uid, int $owner): string {
    if (!realm_knocks_available($pdo)) return 'Knocking needs a database update first. An administrator opens migrate.php once.';
    if ($owner === $uid) return 'That is your own door.';
    $q = $pdo->prepare('SELECT door, house_slot, TIMESTAMPDIFF(SECOND, last_ping, NOW()) AS age FROM realm_people WHERE user_id = ?');
    $q->execute([$owner]);
    $row = $q->fetch();
    if (!$row || $row['house_slot'] === null) return 'Nobody lives there.';
    if ($row['age'] === null || (int)$row['age'] > REALM_ONLINE_SECONDS) return 'Nobody is home.';
    if ($row['door'] === 'sealed') return 'That door is sealed. They are not to be disturbed.';
    if ($row['door'] === 'open') return 'The door is open. Walk in.';
    $q = $pdo->prepare('SELECT place FROM realm_people WHERE user_id = ?');
    $q->execute([$uid]);
    if ((string)$q->fetchColumn() !== 'user:' . $owner) return 'Walk up to the door first.';
    $pdo->prepare('DELETE FROM realm_knocks WHERE from_user = ? AND to_user = ?')->execute([$uid, $owner]);
    $pdo->prepare("INSERT INTO realm_knocks (from_user, to_user, status, created_at) VALUES (?, ?, 'waiting', NOW())")->execute([$uid, $owner]);
    return '';
}

/** Answer a knock on your own door. Returns '' when answered, or the sentence to show. */
function realm_knock_answer(PDO $pdo, int $uid, int $knockId, bool $let): string {
    if (!realm_knocks_available($pdo)) return 'Knocking needs a database update first.';
    $u = $pdo->prepare("UPDATE realm_knocks SET status = ?, answered_at = NOW() WHERE id = ? AND to_user = ? AND status = 'waiting'");
    $u->execute([$let ? 'in' : 'no', $knockId, $uid]);
    return $u->rowCount() === 1 ? '' : 'That knock has already been answered, or they walked away.';
}

/** Has $owner let $uid in during the last REALM_KNOCK_PASS_MINUTES? Never throws. */
function realm_has_pass(PDO $pdo, int $uid, int $owner): bool {
    if (!realm_knocks_available($pdo)) return false;
    try {
        $q = $pdo->prepare("SELECT 1 FROM realm_knocks WHERE from_user = ? AND to_user = ? AND status = 'in'
                               AND answered_at > DATE_SUB(NOW(), INTERVAL " . REALM_KNOCK_PASS_MINUTES . " MINUTE) LIMIT 1");
        $q->execute([$uid, $owner]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * What this person needs to know about knocks right now:
 *   for_me  who is waiting at my door: [{id, from, name}]
 *   mine    my own latest knock: {to, status} where status is waiting, in, no
 *           or gone (nobody answered in time); null when there is nothing recent
 *   ready   false until migration 084 has run
 * Sent with the map, with the unread counts and with every chat answer, so a
 * knock is seen within a few seconds whatever the person is doing in the Realm.
 */
function realm_knock_news(PDO $pdo, int $uid): array {
    $out = ['ready' => realm_knocks_available($pdo), 'for_me' => [], 'mine' => null];
    if (!$out['ready']) return $out;
    try {
        $q = $pdo->prepare("SELECT k.id, k.from_user, u.display_name, u.username, c.name AS crew_name
                              FROM realm_knocks k JOIN users u ON u.id = k.from_user
                         LEFT JOIN crew c ON c.id = u.crew_id
                             WHERE k.to_user = ? AND k.status = 'waiting'
                               AND k.created_at > DATE_SUB(NOW(), INTERVAL " . REALM_KNOCK_SECONDS . " SECOND)
                          ORDER BY k.id");
        $q->execute([$uid]);
        foreach ($q->fetchAll() as $r) {
            $out['for_me'][] = ['id' => (int)$r['id'], 'from' => (int)$r['from_user'],
                                'name' => trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username'])))];
        }
        $q = $pdo->prepare('SELECT to_user, status, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age,
                                   TIMESTAMPDIFF(MINUTE, answered_at, NOW()) AS answered
                              FROM realm_knocks WHERE from_user = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$uid]);
        $m = $q->fetch();
        if ($m) {
            $status = (string)$m['status'];
            if ($status === 'waiting' && (int)$m['age'] > REALM_KNOCK_SECONDS) $status = 'gone';
            $old = $status === 'waiting' ? false
                 : ($status === 'gone' ? (int)$m['age'] > REALM_KNOCK_SECONDS + 60
                                       : (int)$m['answered'] >= REALM_KNOCK_PASS_MINUTES);
            if (!$old) $out['mine'] = ['to' => (int)$m['to_user'], 'status' => $status];
        }
    } catch (Throwable $e) {
        // No news rather than a failed map.
    }
    return $out;
}
