<?php
/**
 * The bell beside your name — migration 060.
 *
 * David, 2026-10-22: "you need to get a notification in a bell close to your
 * name so you know someone assign, tag or changed a tasked you're subscribed
 * to". Three kinds, and that is the whole vocabulary:
 *
 *   mention   somebody wrote your name in a comment
 *   assigned  a task was handed to you
 *   status    a task you follow moved
 *
 * Since added: review (review notes on your version, 079) and reminder (log
 * your time, 080; includes/log_reminders.php), and dayoff (a request for days
 * off, and its answer, 101; includes/day_off_requests.php).
 *
 * ⚠ NOTHING IN HERE MAY THROW. Every writer below sits in the middle of a save
 * — a status change, an assignment, a posted comment — and a bell that cannot
 * be written must never be the reason somebody's work is not. So notify_add()
 * swallows its own failures into error_log, exactly as the mailers do, and
 * every reader returns an empty bell rather than an exception. The chrome on
 * every page calls these.
 */

/** Has migration 060 run? Until it has, there is simply no bell. */
function notify_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'notifications', 'crew_id');
    return $ok;
}

/**
 * Who follows this task.
 *
 * ⚠ DERIVED, NEVER STORED. There is nothing to subscribe to and nothing to
 * forget to subscribe to: you follow a task if it is yours, if you have spoken
 * on it, or if somebody named you on it. All three are already in the database,
 * which is why a freelancer is following their own work from the moment it
 * becomes theirs without ever being taught the word "subscription".
 *
 * Since migration 108 (David, 2026-11-20) a person may also subscribe or
 * unsubscribe by hand on Spell; those choices are applied last, on top of the
 * derived list. includes/task_follow.php holds them.
 *
 * @return int[] crew ids, each once
 */
function notify_subscribers(PDO $pdo, int $progressId): array {
    if ($progressId <= 0) return [];
    $found = [];
    $add = function ($rows) use (&$found) {
        foreach ($rows as $r) if ((int)$r > 0) $found[(int)$r] = true;
    };

    try {
        // On it — the assignee, and anybody on the second-name table.
        $q = $pdo->prepare('SELECT crew_id FROM progress WHERE id = ? AND crew_id IS NOT NULL');
        $q->execute([$progressId]);
        $add($q->fetchAll(PDO::FETCH_COLUMN));

        $q = $pdo->prepare('SELECT crew_id FROM progress_crew WHERE progress_id = ?');
        $q->execute([$progressId]);
        $add($q->fetchAll(PDO::FETCH_COLUMN));

        // Has spoken on it. Comments are a login's; the person is users.crew_id.
        if (column_exists($pdo, 'ticket_comments', 'progress_id')) {
            $q = $pdo->prepare(
                'SELECT DISTINCT u.crew_id
                   FROM ticket_comments c
                   JOIN users u ON u.id = c.user_id
                  WHERE c.progress_id = ? AND u.crew_id IS NOT NULL'
            );
            $q->execute([$progressId]);
            $add($q->fetchAll(PDO::FETCH_COLUMN));

            // Has been named on it.
            if (column_exists($pdo, 'ticket_mentions', 'crew_id')) {
                $q = $pdo->prepare(
                    'SELECT DISTINCT m.crew_id
                       FROM ticket_mentions m
                       JOIN ticket_comments c ON c.id = m.comment_id
                      WHERE c.progress_id = ?'
                );
                $q->execute([$progressId]);
                $add($q->fetchAll(PDO::FETCH_COLUMN));
            }
        }
    } catch (Throwable $e) {
        error_log('[Anima bell] subscribers: ' . $e->getMessage());
    }

    // ⚠ AND WHAT PEOPLE CHOSE ON SPELL (David, 2026-11-20, migration 108): the
    // hand-subscribed join, the unsubscribed leave, even when the rules above
    // would have them. Loaded only here, so the chrome on every page stays light;
    // it never throws, and before 108 it changes nothing.
    try {
        require_once __DIR__ . '/task_follow.php';
        $chosen = task_follow_choices_by_progress($pdo, $progressId);
        foreach ($chosen['on'] as $id)  $found[$id] = true;
        foreach ($chosen['off'] as $id) unset($found[$id]);
    } catch (Throwable $e) {
        error_log('[Anima bell] subscriptions: ' . $e->getMessage());
    }
    return array_keys($found);
}

/**
 * Ring somebody's bell. Never throws; see the note at the top of this file.
 *
 * ⚠ IT REFUSES TO TELL YOU ABOUT YOUR OWN ACTION, and that guard lives here
 * rather than in each caller so it cannot be forgotten by the next one. A bell
 * that lights up because you moved a status is a bell people stop reading
 * within a day — and then the one that mattered is in there too.
 *
 * @param int[] $crewIds who to tell
 * @param array $n kind, actor, summary, and whichever of project_id, step_id,
 *                 item_key, progress_id, ticket_id, comment_id apply
 * @param int   $exceptCrewId the person who did it
 */
function notify_add(PDO $pdo, array $crewIds, array $n, int $exceptCrewId = 0): int {
    if (!notify_available($pdo)) return 0;

    $want = [];
    foreach ($crewIds as $id) {
        $id = (int)$id;
        if ($id > 0 && $id !== $exceptCrewId) $want[$id] = true;
    }
    if (!$want) return 0;

    $kind = (string)($n['kind'] ?? '');
    if (!in_array($kind, ['mention', 'assigned', 'status', 'review', 'reminder', 'raven', 'tag', 'later', 'dayoff', 'update'], true)) return 0;
    // 'update' (a change on a task you subscribed to on Spell, migration 108):
    // no fallback, and none needed, since nobody is subscribed before 108 runs.
    if ($kind === 'update' && !enum_has_value($pdo, 'notifications', 'kind', 'update')) return 0;
    // 'later' (a saved Realm message's reminder, migration 096): the same, no fallback.
    if ($kind === 'later' && !enum_has_value($pdo, 'notifications', 'kind', 'later')) return 0;
    // 'dayoff' (a request for days off, or its answer, migration 101): no
    // fallback, and none needed, since there are no requests before 101 runs.
    if ($kind === 'dayoff' && !enum_has_value($pdo, 'notifications', 'kind', 'dayoff')) return 0;
    // 'raven' (a message left in the Realm for somebody who is away, migration
    // 082) has no fallback either: until 082 runs there are no ravens to ring for.
    if ($kind === 'raven' && !enum_has_value($pdo, 'notifications', 'kind', 'raven')) return 0;
    // 'tag' (somebody named you in a Realm channel, migration 083): the same.
    if ($kind === 'tag' && !enum_has_value($pdo, 'notifications', 'kind', 'tag')) return 0;
    // 'review' arrives with migration 079 (the column is an ENUM). Until then a
    // review note still rings, as a mention: late wording beats a silent bell.
    if ($kind === 'review' && !notify_kind_review_ready($pdo)) $kind = 'mention';
    // 'reminder' (log your time, migration 080) has no fallback: "mentioned you
    // on" would be untrue, and the pop-up is the reminder itself. Until 080
    // runs the reminder simply does not ring.
    if ($kind === 'reminder' && !enum_has_value($pdo, 'notifications', 'kind', 'reminder')) return 0;

    try {
        $ins = $pdo->prepare(
            'INSERT INTO notifications
               (crew_id, kind, actor, summary, project_id, step_id, item_key,
                progress_id, ticket_id, comment_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_keys($want) as $crewId) {
            $ins->execute([
                $crewId, $kind,
                mb_substr((string)($n['actor'] ?? ''), 0, 120),
                mb_substr((string)($n['summary'] ?? ''), 0, 255),
                !empty($n['project_id'])  ? (int)$n['project_id']  : null,
                !empty($n['step_id'])     ? (int)$n['step_id']     : null,
                !empty($n['item_key'])    ? (string)$n['item_key'] : null,
                !empty($n['progress_id']) ? (int)$n['progress_id'] : null,
                !empty($n['ticket_id'])   ? (int)$n['ticket_id']   : null,
                !empty($n['comment_id'])  ? (int)$n['comment_id']  : null,
            ]);
        }
        return count($want);
    } catch (Throwable $e) {
        // A bell that cannot be written must never be the reason somebody's
        // work is not saved.
        error_log('[Anima bell] write: ' . $e->getMessage());
        return 0;
    }
}

/** Has migration 079 added 'review' to notifications.kind? Asked once per request. */
function notify_kind_review_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'kind'")->fetch();
        $ready = $col && strpos((string)$col['Type'], "'review'") !== false;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** How many are unread. 0 whenever anything is missing, so the chrome is safe. */
function notify_unseen(PDO $pdo, int $crewId): int {
    if ($crewId <= 0 || !notify_available($pdo)) return 0;
    try {
        $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE crew_id = ? AND seen_at IS NULL');
        $q->execute([$crewId]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** The last few, newest first — unread and read together, as a bell shows them. */
function notify_recent(PDO $pdo, int $crewId, int $limit = 20): array {
    if ($crewId <= 0 || !notify_available($pdo)) return [];
    try {
        $q = $pdo->prepare(
            'SELECT * FROM notifications WHERE crew_id = ?
              ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $q->execute([$crewId]);
        return $q->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Everything in this bell is now read.
 *
 * ⚠ ON OPENING THE BELL, which is the opposite of what migration 040 decided
 * for a mention on a ticket — and the difference is real rather than an
 * inconsistency. A mention count on My tasks is a POINTER: it says go and read
 * something that lives elsewhere, so clearing it when somebody glances at the
 * screen loses the only signal telling them where to go. The bell is not a
 * pointer; the list inside it IS the message — "Ana assigned you BG Layout on
 * SH160" has been delivered the moment it has been read. Each row still links
 * through for when you want the task itself.
 */
function notify_mark_seen(PDO $pdo, int $crewId): int {
    if ($crewId <= 0 || !notify_available($pdo)) return 0;
    try {
        $q = $pdo->prepare('UPDATE notifications SET seen_at = NOW() WHERE crew_id = ? AND seen_at IS NULL');
        $q->execute([$crewId]);
        return $q->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Has migration 103 (notifications.opened_at) run? Asked once per request; never throws.
 * Before it has, the bell shows every row in one list, as it always did.
 */
function notify_opened_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = function_exists('column_exists') && column_exists($pdo, 'notifications', 'opened_at');
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Has this row been clicked (Tim, 2026-11-17: "once clicked on, get cleared from
 * the list")? False for every row until migration 103 runs.
 */
function notify_is_opened(array $n): bool {
    return !empty($n['opened_at']);
}

/** Mark ONE of $crewId's own notifications opened. 0 when it is not theirs or already opened. Never throws. */
function notify_mark_opened(PDO $pdo, int $crewId, int $id): int {
    if ($crewId <= 0 || $id <= 0 || !notify_available($pdo) || !notify_opened_available($pdo)) return 0;
    try {
        $q = $pdo->prepare('UPDATE notifications SET opened_at = NOW(), seen_at = COALESCE(seen_at, NOW())
                             WHERE id = ? AND crew_id = ? AND opened_at IS NULL');
        $q->execute([$id, $crewId]);
        return $q->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The latest version of each task the bell is talking about, for a thumbnail.
 *
 * David, 2026-11-17, looking at a bell full of "Renata Ruiz moved Comp on SH210
 * → Ready to start": "is it possible to get the latest version as a thumbnail in
 * the notifications? So we can easily visualize the task". So a line about a
 * task (a Department on a shot or asset) carries a small picture of the newest
 * version put up on it. A line about anything else, or about a task with nothing
 * put up yet, or whose newest version is a PDF, carries none and reads as before.
 *
 * ⚠ ONE QUERY FOR THE WHOLE LIST, never one per line: this runs in the chrome of
 * every page and on the 30-second poll.
 *
 * ⚠ ONLY WHAT SPELL'S PANEL WOULD SHOW YOU. The picture is the task's own
 * version, so it follows the same doors as the panel that shows it: the Spell
 * switch (feature_on('spell')), the shows this account is staffed on
 * (visible_projects_sql(), as board_projects() scopes Spell) and the shows Spell
 * lists at all (set up, active, on hold; never the Studio). A bell line about a
 * show you can no longer open keeps its words and loses its picture.
 *
 * ⚠ NEVER THROWS, like everything here. Before migration 063 (versions) or on
 * any error, the answer is "no pictures".
 *
 * @param array $items notification rows, as notify_recent() returns them
 * @return array notification id => ['url' => attachment URL, 'video' => bool]
 */
function notify_thumbs(PDO $pdo, array $items): array {
    if (!$items) return [];
    try {
        if (function_exists('feature_on') && !feature_on('spell')) return [];
        if (!function_exists('column_exists') || !column_exists($pdo, 'progress_versions', 'version_no')) return [];

        // Which tasks: every line that names a show, a Department and a shot or
        // asset, each task once. Days off and saved Realm messages put other
        // things in item_key but never a Department, so step_id leaves them out.
        $tasks = [];
        foreach ($items as $n) {
            $p = (int)($n['project_id'] ?? 0);
            $s = (int)($n['step_id'] ?? 0);
            $k = (string)($n['item_key'] ?? '');
            if ($p > 0 && $s > 0 && $k !== '') $tasks[$p . ':' . $s . ':' . $k] = [$p, $s, $k];
        }
        if (!$tasks) return [];

        $where = [];
        $args = [];
        foreach ($tasks as [$p, $s, $k]) {
            $where[] = '(pr.project_id = ? AND pr.step_id = ? AND pr.item_key = ?)';
            array_push($args, $p, $s, $k);
        }
        $scope = function_exists('visible_projects_sql') ? visible_projects_sql($pdo, 'p.id') : '';
        $studio = function_exists('studio_exclude_sql') ? studio_exclude_sql($pdo, 'p') : '';
        $q = $pdo->prepare(
            "SELECT pr.project_id, pr.step_id, pr.item_key, v.id, v.mime_type
               FROM progress pr
               JOIN projects p ON p.id = pr.project_id
               JOIN progress_versions v ON v.progress_id = pr.id
              WHERE (" . implode(' OR ', $where) . ")
                AND p.status IN ('setup', 'active', 'on_hold')" . $studio
                . ($scope !== '' ? ' AND ' . $scope : '') . "
                AND v.version_no = (SELECT MAX(v2.version_no) FROM progress_versions v2
                                     WHERE v2.progress_id = pr.id)"
        );
        $q->execute($args);
        $latest = [];
        foreach ($q as $r) {
            $mime = (string)($r['mime_type'] ?? '');
            $isVideo = strncmp($mime, 'video/', 6) === 0;
            // Pictures and films only: a PDF or a .zip has nothing to show this small.
            if (!$isVideo && strncmp($mime, 'image/', 6) !== 0) continue;
            $latest[(int)$r['project_id'] . ':' . (int)$r['step_id'] . ':' . $r['item_key']] = [
                'url'   => 'attachment.php?kind=version&id=' . (int)$r['id'],
                'video' => $isVideo,
            ];
        }

        $out = [];
        foreach ($items as $n) {
            $key = (int)($n['project_id'] ?? 0) . ':' . (int)($n['step_id'] ?? 0) . ':' . (string)($n['item_key'] ?? '');
            if (isset($latest[$key])) $out[(int)$n['id']] = $latest[$key];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[Anima bell] thumbs: ' . $e->getMessage());
        return [];
    }
}

/**
 * The thumbnail's markup for one bell line, or '' for none. Shared by
 * includes/header.php; assets/bell.js writes the same thing from the poll.
 *
 * ⚠ data-src, NOT src: the panel is shut on every page load, and twenty
 * full-size frames fetched for a list nobody opened would be paid on every
 * page. assets/bell.js moves data-src to src the first time the bell opens.
 */
function notify_thumb_html(?array $t): string {
    if (!$t) return '';
    $url = e($t['url']);
    return '<span class="bell-thumb" aria-hidden="true">'
         . ($t['video']
             ? '<video data-src="' . $url . '" muted playsinline preload="none"></video>'
             : '<img data-src="' . $url . '" alt="">')
         . '</span>';
}

/** Where a notification goes when you click it. */
function notify_link(array $n): string {
    // A raven is read in the Realm, where the sender's name carries the unread mark.
    if (in_array($n['kind'] ?? '', ['raven', 'tag'], true)) return 'realm.php';
    // Days off (101): a request goes to where Production answers it, an answer
    // to the asker's own list on Home.
    if (($n['kind'] ?? '') === 'dayoff') {
        return ($n['item_key'] ?? '') === 'approve' ? 'bookings.php?tab=days-off#requests' : 'home.php?tab=general#days-off';
    }
    // A reminder about a saved Realm message (migration 096) opens the Realm AT
    // that message; item_key holds its id. The Realm checks it may still be read.
    if (($n['kind'] ?? '') === 'later') return 'realm.php' . (!empty($n['item_key']) ? '?msg=' . (int)$n['item_key'] : '');
    if (!empty($n['ticket_id'])) {
        return 'ticket.php?id=' . (int)$n['ticket_id']
             . (!empty($n['comment_id']) ? '#c' . (int)$n['comment_id'] : '');
    }
    if (!empty($n['project_id']) && !empty($n['step_id']) && !empty($n['item_key'])) {
        // spell.php reads `open` and opens that task's panel for you. (The
        // screen was still called the Board when this was written; board.php
        // is now a 301 that keeps the query string, so old bells still work —
        // but a new one should not spend a redirect.)
        return 'spell.php?project=' . (int)$n['project_id']
             . '&open=' . rawurlencode($n['step_id'] . ':' . $n['item_key']);
    }
    return 'mytasks.php';
}

/** The verb, for the line in the list. */
function notify_verb(string $kind): string {
    $words = [
        'mention'  => 'mentioned you on',
        'assigned' => 'assigned you',
        'status'   => 'moved',
        'review'   => 'left review notes on',
        'reminder' => 'asked you to log',     // + "your time for Tue 11 Nov" (080)
        'raven'    => 'sent you a raven',     // + "in the Realm" (082)
        'tag'      => 'tagged you in',        // + "The Tavern in the Realm" (083)
        'later'    => 'about',
        'update'   => 'updated',                // + "Comp on SH020 · due 14 Jan" (108)
        'dayoff'   => '',                     // the summary says it all: "asked for days off, Mon 24 Nov" (101)                // "Reminder about Renata's message in the Realm" (096)
    ];
    return $words[$kind] ?? 'changed';
}
