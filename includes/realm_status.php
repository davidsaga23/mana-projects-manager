<?php
// The Realm: status messages, and lunch in the Diner (migration 099).
//
// David, 2026-11-16: "Status messages: Let's also create a diner so people's
// avatars go immediately there when they set up their status 'Lunch'". The
// status is Slack's: a short line and an emoji next to your name ("In review
// until 3", "Lunch", "Off tomorrow"), chosen from a few ready-made ones or
// written by hand, and cleared by itself after a while.
//
// What is relied on below:
//   * ONE STATUS PER PERSON, on their own realm_people row (status_* columns).
//     Only the person themselves sets or clears it: realm_api.php takes the id
//     from the session, never from the form.
//   * THE SERVER ENDS IT, not the page. `status_until` is when it runs out, and
//     realm_status_sweep() clears every status past it before the map is read,
//     so nobody is left on "Lunch" for a week because their page was closed.
//     An expired status is also never sent (realm_status_of() checks the time
//     again), so a sweep that failed still shows nothing stale.
//   * LUNCH MOVES YOUR CHARACTER. Setting it stands you inside the Diner
//     straight away (the page draws the teleport sparkle); when it ends, by the
//     clock or by clearing it, you go back to REALM_LUNCH_BACK. The move is made
//     HERE, so everybody else sees you in the Diner at once, whatever your own
//     page is doing. Walking out of the Diner while on lunch is allowed and does
//     not end it.
//   * THE DAYS-OFF CALENDAR (built separately) may later set "Off" by itself.
//     Its hook is realm_status_set() with $from = 'calendar': see the note on
//     that function. Nothing here calls it yet.
//
// ⚠ NOTHING HERE TOUCHES MONEY OR HOURS. A status is words on the map: it does
// not log time, start or stop a clock, or open the finance connection.

/** Where a character goes when lunch ends: 'home' (inside their own house) or
 *  'before' (back where they were when they set it). David to choose
 *  (asked 2026-11-16); 'home' until he says otherwise. */
const REALM_LUNCH_BACK = 'home';

/**
 * The ready-made statuses, in the order the picker shows them:
 * kind => [emoji, words, how long it lasts unless changed].
 * The kind is stored; the words and the emoji are stored too, so a status set
 * today still reads the same if this list changes later.
 */
const REALM_STATUS_PRESETS = [
    'lunch'  => ['🍔', 'Lunch',     '1h'],
    'review' => ['🎬', 'In review', '1h'],
    'focus'  => ['🎧', 'Focusing',  '1h'],
    'sick'   => ['🤒', 'Out sick',  'today'],
    'off'    => ['🌴', 'Off today', 'today'],
];

/** The emoji a status may wear. A fixed set: whatever is typed into a form can
 *  never put anything else beside a name. The presets' own come first. */
const REALM_STATUS_EMOJI = ['🍔', '🎬', '🎧', '🤒', '🌴', '💬', '📞', '☕', '🍕', '🚗', '✈️', '🏠',
                            '🎨', '✏️', '📝', '🔥', '🎉', '🤔', '💤', '🩺', '🏃', '📅', '👀', '🙏'];

/** How long a status may be set for: picker value => seconds, or null for
 *  "never" and 'today' for "until midnight" (the studio's clock, includes/db.php). */
const REALM_STATUS_CLEAR = ['30m' => 1800, '1h' => 3600, 'today' => 'today', 'never' => null];

/** The longest line a status may have, in characters. */
const REALM_STATUS_MAX = 80;

/** Has migration 099 arrived? Asked once per request; never throws. */
function realm_status_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT status_kind, status_emoji, status_text, status_until, status_from, status_back FROM realm_people LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Set or clear the status of $uid. Returns '' when saved, or the sentence to show.
 *
 *   $kind   a key of REALM_STATUS_PRESETS, 'custom', or '' to clear
 *   $emoji  one of REALM_STATUS_EMOJI ('' takes the preset's own)
 *   $text   the words ('' takes the preset's own); trimmed to REALM_STATUS_MAX
 *   $clear  a key of REALM_STATUS_CLEAR ('' takes the preset's own)
 *   $from   who set it: 'self' (the person, from the Realm) or 'calendar'
 *
 * ⚠ THE HOOK FOR THE DAYS-OFF CALENDAR. When a day off starts it can call
 *   realm_status_set($pdo, $userId, 'off', '', '', 'today', 'calendar');
 * and the person shows "🌴 Off today" until midnight. It should not overwrite a
 * status the person set themselves that day: read realm_status_of() first and
 * leave it alone unless it is empty or its `from` is 'calendar'. (Not built:
 * David, 2026-11-16, said the calendar is done separately.)
 */
function realm_status_set(PDO $pdo, int $uid, string $kind, string $emoji, string $text, string $clear, string $from = 'self'): string {
    if (!realm_status_ready($pdo)) return 'Statuses need a database update first. An administrator opens migrate.php once.';
    if ($uid <= 0) return 'Sign in first.';
    if ($kind === '') {
        realm_status_end($pdo, $uid);
        return '';
    }
    $preset = REALM_STATUS_PRESETS[$kind] ?? null;
    if ($preset === null && $kind !== 'custom') return 'That is not a status.';

    if ($emoji === '') $emoji = $preset[0] ?? '💬';
    if (!in_array($emoji, REALM_STATUS_EMOJI, true)) return 'Pick one of the emoji in the list.';

    // One line, no control characters, at most REALM_STATUS_MAX characters.
    $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
    if ($text === '') $text = $preset[1] ?? '';
    if ($text === '') return 'Write what your status says.';
    if (mb_strlen($text) > REALM_STATUS_MAX) $text = mb_substr($text, 0, REALM_STATUS_MAX);

    if ($clear === '') $clear = $preset[2] ?? 'today';
    if (!array_key_exists($clear, REALM_STATUS_CLEAR)) return 'That is not a time to clear it.';
    $len = REALM_STATUS_CLEAR[$clear];
    $until = $len === null ? null
           : ($len === 'today' ? date('Y-m-d 23:59:59') : date('Y-m-d H:i:s', time() + (int)$len));
    $from = $from === 'calendar' ? 'calendar' : 'self';

    try {
        $q = $pdo->prepare('SELECT place, inside, status_kind, status_back FROM realm_people WHERE user_id = ?');
        $q->execute([$uid]);
        $row = $q->fetch();
        if (!$row) return 'Open the Realm once first, so you have a place in it.';
        $wasLunch = (string)$row['status_kind'] === 'lunch';

        if ($kind === 'lunch') {
            // Where to come back to, kept from the FIRST lunch only: setting
            // lunch again while already in the Diner must not make the Diner the
            // way back.
            $back = $wasLunch && $row['status_back'] !== null ? (string)$row['status_back']
                  : (string)$row['place'] . '|' . ((int)$row['inside'] === 1 ? '1' : '0');
            $pdo->prepare('UPDATE realm_people SET status_kind = ?, status_emoji = ?, status_text = ?, status_until = ?, status_from = ?, status_back = ?,
                                  place = ?, inside = 1,' . realm_spot_reset($pdo) . ' last_active = NOW()
                            WHERE user_id = ?')
                ->execute([$kind, $emoji, $text, $until, $from, mb_substr($back, 0, 42), 'diner', $uid]);
            return '';
        }
        // Any other status. Changing FROM lunch is lunch ending: go back first.
        if ($wasLunch) realm_lunch_back($pdo, $uid);
        $pdo->prepare('UPDATE realm_people SET status_kind = ?, status_emoji = ?, status_text = ?, status_until = ?, status_from = ?, status_back = NULL, last_active = NOW()
                        WHERE user_id = ?')
            ->execute([$kind, $emoji, $text, $until, $from, $uid]);
    } catch (Throwable $e) {
        return 'Your status could not be saved. Try again in a moment.';
    }
    return '';
}

/** Clear the status of $uid; if it was lunch, go back from the Diner. Never throws. */
function realm_status_end(PDO $pdo, int $uid): void {
    if (!realm_status_ready($pdo)) return;
    try {
        $q = $pdo->prepare('SELECT status_kind FROM realm_people WHERE user_id = ?');
        $q->execute([$uid]);
        if ((string)$q->fetchColumn() === 'lunch') realm_lunch_back($pdo, $uid);
        $pdo->prepare('UPDATE realm_people SET status_kind = NULL, status_emoji = NULL, status_text = NULL, status_until = NULL, status_from = NULL, status_back = NULL
                        WHERE user_id = ?')->execute([$uid]);
    } catch (Throwable $e) {
        // The sweep tries again on the next look at the map.
    }
}

/**
 * Lunch is over: if the character is still in the Diner, stand them where
 * REALM_LUNCH_BACK says. Somebody who already walked out of the Diner is left
 * where they are. Going back INSIDE somewhere is asked of realm_arrive(), with
 * its door rules; if that is refused they stand outside it, and failing that at
 * their own door. Never throws.
 */
function realm_lunch_back(PDO $pdo, int $uid): void {
    try {
        $q = $pdo->prepare('SELECT place, status_back FROM realm_people WHERE user_id = ?');
        $q->execute([$uid]);
        $row = $q->fetch();
        if (!$row || (string)$row['place'] !== 'diner') return;
        $home = 'user:' . $uid;
        $to = $home;
        $in = true;
        if (REALM_LUNCH_BACK === 'before' && $row['status_back'] !== null) {
            [$p, $i] = array_pad(explode('|', (string)$row['status_back'], 2), 2, '0');
            if ($p !== '' && $p !== 'diner') { $to = $p; $in = $i === '1'; }
        }
        if (realm_arrive($pdo, $uid, $to, $in) === '') return;
        if ($in && realm_arrive($pdo, $uid, $to, false) === '') return;
        realm_arrive($pdo, $uid, $home, false);
    } catch (Throwable $e) {
        // They stay in the Diner a little longer; the next sweep tries again.
    }
}

/**
 * Clear every status that has run out. Called before the map is read
 * (realm_state), so an expired status is gone for everybody within one
 * heartbeat. One small SELECT when nothing has run out. Never throws.
 */
function realm_status_sweep(PDO $pdo): void {
    if (!realm_status_ready($pdo)) return;
    try {
        $ids = $pdo->query('SELECT user_id FROM realm_people WHERE status_until IS NOT NULL AND status_until <= NOW() LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) realm_status_end($pdo, (int)$id);
    } catch (Throwable $e) {
        // Nothing stale is shown anyway: realm_status_of() checks the time again.
    }
}

/**
 * A person's status for the screen, from their realm_people row (the status_*
 * columns plus `status_left`, seconds until it runs out), or null:
 *   kind   'lunch', 'review', ... or 'custom'
 *   emoji  one of REALM_STATUS_EMOJI
 *   text   the words
 *   left   seconds until it clears, or null for "never"
 *   from   'self' or 'calendar'
 */
function realm_status_of(array $r): ?array {
    if (!isset($r['status_kind']) || $r['status_kind'] === null || (string)$r['status_kind'] === '') return null;
    $left = $r['status_left'] ?? null;
    if ($left !== null && (int)$left <= 0) return null;
    return ['kind'  => (string)$r['status_kind'],
            'emoji' => in_array((string)$r['status_emoji'], REALM_STATUS_EMOJI, true) ? (string)$r['status_emoji'] : '💬',
            'text'  => (string)$r['status_text'],
            'left'  => $left === null ? null : (int)$left,
            'from'  => (string)($r['status_from'] ?? 'self') === 'calendar' ? 'calendar' : 'self'];
}

/** What the picker needs, sent with the map: has 099 run, the presets, the emoji, the times. */
function realm_status_menu(PDO $pdo): array {
    $presets = [];
    foreach (REALM_STATUS_PRESETS as $k => $p) $presets[] = ['kind' => $k, 'emoji' => $p[0], 'text' => $p[1], 'clear' => $p[2]];
    return ['ready' => realm_status_ready($pdo), 'presets' => $presets, 'emoji' => REALM_STATUS_EMOJI,
            'clear' => array_keys(REALM_STATUS_CLEAR), 'max' => REALM_STATUS_MAX];
}
