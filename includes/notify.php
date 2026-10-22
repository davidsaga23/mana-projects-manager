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
    if (!in_array($kind, ['mention', 'assigned', 'status'], true)) return 0;

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
function notify_recent(PDO $pdo, int $crewId, int $limit = 12): array {
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

/** Where a notification goes when you click it. */
function notify_link(array $n): string {
    if (!empty($n['ticket_id'])) {
        return 'ticket.php?id=' . (int)$n['ticket_id']
             . (!empty($n['comment_id']) ? '#c' . (int)$n['comment_id'] : '');
    }
    if (!empty($n['project_id']) && !empty($n['step_id']) && !empty($n['item_key'])) {
        // board.php reads `open` and opens that task's panel for you.
        return 'board.php?project=' . (int)$n['project_id']
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
    ];
    return $words[$kind] ?? 'changed';
}
