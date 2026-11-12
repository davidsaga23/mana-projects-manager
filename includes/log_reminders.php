<?php
// REMINDERS TO LOG TIME (migration 080).
//
// David, 2026-11-11: "add pop up reminders for everyone to log times at the end
// of the day and a button for admins to send and instant pop up for logging
// times as an alternative". Artists log their time every day (the money rules in
// CLAUDE.md); statements, actual days and the budget pool are only as good as
// those logs, and on MSD most people had logged nothing.
//
// Settled with David the same day:
//   - 5 pm studio time (config.php 'timezone'), working days only: never on a
//     weekend, a studio holiday (non_working_days) or the person's own day off
//     (crew_absences). A "Remind me in an hour" snooze.
//   - Anyone ON A SHOW THAT DAY gets it, salaried staff included (their time is
//     project cost at the blended day rate). Nobody with no show.
//   - The automatic one is a pop-up and a bell, never an email. An admin's
//     instant one may also be emailed, when the sender ticks the box.
//
// TWO PARTS, ONE POP-UP:
//   auto     Nothing is stored to make it happen and there is no cron. Each open
//            page asks log_reminder_api.php every few minutes; after 5 pm the
//            answer is worked out from what is already in the database.
//   instant  An admin presses Send on log_reminders.php. That writes one
//            log_reminders row and a log_reminder_recipients row per person, and
//            the next poll on each of their pages finds it.
//
// "PUT AWAY" LIVES IN TWO PLACES, deliberately. An instant reminder is a row, so
// dismissing it marks the row and it stays dismissed on every device. The
// automatic one has no row to mark; its "Dismiss" and the snooze are kept in the
// login's session, which every tab of that browser shares. A second device may
// show the automatic one once more, which is harmless, and needs no table.
//
// ⚠ NOTHING HERE MAY THROW INTO A PAGE. The poll runs under every screen; a
// table that is missing or a query that fails answers "nothing to show".

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/home.php';        // home_projects(), statement_window(), crew_absences()

/** The hour (studio time, 24h) after which the end-of-day pop-up may appear. */
const LOG_REMINDER_HOUR = 17;

/** The snoozes the pop-up offers, in minutes. Anything else posted is refused. */
const LOG_REMINDER_SNOOZES = [30, 60];

/** Has migration 080 run (the instant reminders and their record)? */
function log_reminders_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'log_reminder_recipients', 'crew_id');
    return $ok;
}

/** Can the bell hold a 'reminder' yet (also migration 080)? */
function log_reminder_bell_ready(PDO $pdo): bool {
    return notify_available($pdo) && enum_has_value($pdo, 'notifications', 'kind', 'reminder');
}

/** Minutes this person has logged on one day, every show and the Studio together. */
function log_reminder_logged(PDO $pdo, int $crewId, string $day): int {
    if (!time_logs_available($pdo)) return 0;
    try {
        $q = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE crew_id = ? AND log_date = ?');
        $q->execute([$crewId, $day]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Is this person on a recorded day off on this day? */
function log_reminder_away(PDO $pdo, int $crewId, string $day): bool {
    if (!absences_available($pdo)) return false;
    try {
        $q = $pdo->prepare('SELECT 1 FROM crew_absences WHERE crew_id = ? AND ? BETWEEN from_date AND to_date LIMIT 1');
        $q->execute([$crewId, $day]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Is this person on a show on this day?
 *
 * The same reading Home's "missing days" panel gives (home_missing_days()): one
 * of their shows (a position, a staffing row or a task on a show that is set up,
 * active or on hold) whose dates cover the day. A show with no dates covers
 * every day. The Studio is not a show, so somebody with only Studio work is not
 * reminded.
 */
function log_reminder_on_show(PDO $pdo, int $crewId, string $day): bool {
    try {
        foreach (home_projects($pdo, $crewId) as $pid => $p) {
            $w = statement_window($pdo, (int)$pid, $crewId);
            if ((!$w['from'] || $day >= $w['from']) && (!$w['to'] || $day <= $w['to'])) return true;
        }
    } catch (Throwable $e) {
        error_log('[Anima log reminder] on_show: ' . $e->getMessage());
    }
    return false;
}

/** Should this person be logging today at all: a working day, not away, on a show? */
function log_reminder_should_log(PDO $pdo, int $crewId, string $day): bool {
    if (!is_working_day($day)) return false;
    if (log_reminder_away($pdo, $crewId, $day)) return false;
    return log_reminder_on_show($pdo, $crewId, $day);
}

/**
 * The newest instant reminder sent to this person TODAY that they have not put
 * away, or null. Yesterday's is old news: tomorrow's 5 pm takes over.
 */
function log_reminder_pending_instant(PDO $pdo, int $crewId): ?array {
    if ($crewId <= 0 || !log_reminders_available($pdo)) return null;
    try {
        $q = $pdo->prepare(
            'SELECT r.id, r.sent_by_name, r.sent_at, r.note
               FROM log_reminder_recipients rr
               JOIN log_reminders r ON r.id = rr.reminder_id
              WHERE rr.crew_id = ? AND rr.dismissed_at IS NULL AND r.sent_at >= ?
              ORDER BY r.sent_at DESC, r.id DESC LIMIT 1'
        );
        $q->execute([$crewId, date('Y-m-d') . ' 00:00:00']);
        return $q->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Put one instant reminder away, for this person only. The crew id is the asker's own. */
function log_reminder_dismiss(PDO $pdo, int $crewId, int $reminderId): bool {
    if ($crewId <= 0 || $reminderId <= 0 || !log_reminders_available($pdo)) return false;
    try {
        // ⚠ THE OWNERSHIP CHECK IS THE WHERE CLAUSE: a reminder id belonging to
        // somebody else matches no row, so nothing changes.
        $q = $pdo->prepare('UPDATE log_reminder_recipients SET dismissed_at = NOW()
                             WHERE reminder_id = ? AND crew_id = ? AND dismissed_at IS NULL');
        $q->execute([$reminderId, $crewId]);
        return $q->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The automatic reminder's bell line, written the first time the pop-up comes up
 * on a day, so somebody who closed the tab still finds it in the bell. Once a
 * day per person.
 */
function log_reminder_ring_auto(PDO $pdo, int $crewId, string $day): void {
    if ($crewId <= 0 || !log_reminder_bell_ready($pdo)) return;
    try {
        $q = $pdo->prepare("SELECT 1 FROM notifications WHERE crew_id = ? AND kind = 'reminder'
                              AND actor = 'Anima' AND created_at >= ? LIMIT 1");
        $q->execute([$crewId, $day . ' 00:00:00']);
        if ($q->fetchColumn()) return;
    } catch (Throwable $e) {
        return;
    }
    notify_add($pdo, [$crewId], [
        'kind' => 'reminder', 'actor' => 'Anima',
        'summary' => 'your time for ' . date('D j M', strtotime($day)),
    ]);
}

/**
 * THE POLL'S ANSWER for the person signed in: what to pop up now, or null.
 *
 * The session remembers the automatic one's "Dismiss", the snooze, and that the
 * person has logged today, so once they have, the rest of the day's polls cost
 * one indexed query (the instant check) and nothing else.
 *
 * @return array|null ['kind' => 'auto'|'instant', 'id' => int, 'title', 'text', 'note']
 */
function log_reminder_due(PDO $pdo, int $crewId): ?array {
    if ($crewId <= 0) return null;
    $today = date('Y-m-d');
    $st = log_reminder_state($today);
    if ($st['snooze_until'] > time()) return null;

    // An admin's reminder first: it was sent on purpose, today, and outranks the clock.
    $instant = log_reminder_pending_instant($pdo, $crewId);
    if ($instant) {
        $logged = log_reminder_logged($pdo, $crewId, $today);
        $who = trim((string)$instant['sent_by_name']) ?: 'Production';
        return [
            'kind'  => 'instant',
            'id'    => (int)$instant['id'],
            'title' => $who . ' asks you to log your time',
            'text'  => $logged > 0
                ? 'You have ' . fmt_hours($logged) . ' logged today. Add anything that is missing.'
                : 'You have not logged anything today yet.',
            'note'  => (string)($instant['note'] ?? ''),
        ];
    }

    if ($st['dismissed'] || $st['logged']) return null;
    if ((int)date('G') < LOG_REMINDER_HOUR) return null;
    if (log_reminder_logged($pdo, $crewId, $today) > 0) {
        log_reminder_remember('logged', true);
        return null;
    }
    if (!log_reminder_should_log($pdo, $crewId, $today)) {
        // Not a day they owe a log (weekend, holiday, day off, no show): ask no
        // more today. Treated like "logged" so later polls stop here.
        log_reminder_remember('logged', true);
        return null;
    }

    log_reminder_ring_auto($pdo, $crewId, $today);
    return [
        'kind'  => 'auto',
        'id'    => 0,
        'title' => "You haven't logged today",
        'text'  => 'Log today\'s hours before you go. Statements, actual days and the budget all come from them.',
        'note'  => '',
    ];
}

/** Today's remembered answers, reset when the date changes. */
function log_reminder_state(string $today): array {
    $st = $_SESSION['log_reminder'] ?? null;
    if (!is_array($st) || ($st['day'] ?? '') !== $today) {
        $st = ['day' => $today, 'dismissed' => false, 'logged' => false, 'snooze_until' => 0];
        $_SESSION['log_reminder'] = $st;
    }
    return $st + ['dismissed' => false, 'logged' => false, 'snooze_until' => 0];
}

/** Remember one of today's answers in the session. */
function log_reminder_remember(string $key, $value): void {
    $st = log_reminder_state(date('Y-m-d'));
    $st[$key] = $value;
    $_SESSION['log_reminder'] = $st;
}

/* ---------------------------------------------------------------------------
 * SENDING ONE NOW (log_reminders.php)
 * ------------------------------------------------------------------------ */

/**
 * Everybody with an active login linked to a person, and how today looks for
 * them: on a show, away, logged, and whether their pop-up switch is on.
 * [crew_id => row], by name.
 */
function log_reminder_people(PDO $pdo, string $day): array {
    if (!column_exists($pdo, 'users', 'crew_id')) return [];
    $people = [];
    try {
        $rows = $pdo->query(
            "SELECT u.id AS user_id, u.role, u.crew_id, c.name, c.email
               FROM users u JOIN crew c ON c.id = u.crew_id
              WHERE u.active = 1 AND c.active = 1 AND c.is_team = 0
              ORDER BY c.name, u.id"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $working = is_working_day($day);
    foreach ($rows as $r) {
        $cid = (int)$r['crew_id'];
        $role = $r['role'] === 'viewer' ? 'artist' : (string)$r['role'];
        $switchOn = function_exists('feature_for_user')
            ? feature_for_user('log_reminders', (int)$r['user_id'], $role) : true;
        if (isset($people[$cid])) {
            // A person with two logins: on if either login has it on.
            $people[$cid]['switch_on'] = $people[$cid]['switch_on'] || $switchOn;
            continue;
        }
        $away = log_reminder_away($pdo, $cid, $day);
        $people[$cid] = [
            'crew_id'   => $cid,
            'name'      => (string)$r['name'],
            'email'     => trim((string)($r['email'] ?? '')),
            'role'      => $role,
            'on_show'   => log_reminder_on_show($pdo, $cid, $day),
            'away'      => $away,
            'working'   => $working,
            'logged'    => log_reminder_logged($pdo, $cid, $day),
            'switch_on' => $switchOn,
        ];
    }
    return $people;
}

/**
 * Send an instant reminder to these people. Returns the sentence for the flash.
 *
 * @param int[] $crewIds who to remind (already checked against log_reminder_people())
 */
function log_reminder_send(PDO $pdo, array $crewIds, bool $toAll, bool $email, string $note,
                           int $senderUserId, string $senderName): string {
    if (!log_reminders_available($pdo)) {
        throw new RuntimeException('Sending a reminder needs migration 080, which has not been applied yet.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $crewIds), fn($i) => $i > 0)));
    if (!$ids) throw new RuntimeException('Tick at least one person to remind.');
    $note = mb_substr(trim($note), 0, 255);
    $senderName = mb_substr(trim($senderName), 0, 120);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO log_reminders (sent_by, sent_by_name, sent_at, to_all, emailed, note)
                       VALUES (?, ?, NOW(), ?, ?, ?)')
            ->execute([$senderUserId ?: null, $senderName, $toAll ? 1 : 0, $email ? 1 : 0, $note !== '' ? $note : null]);
        $rid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO log_reminder_recipients (reminder_id, crew_id) VALUES (?, ?)');
        foreach ($ids as $cid) $ins->execute([$rid, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // The bell, for whoever does not have Anima open. Never throws (notify_add()).
    if (log_reminder_bell_ready($pdo)) {
        notify_add($pdo, $ids, [
            'kind' => 'reminder', 'actor' => $senderName ?: 'Production',
            'summary' => 'your time for ' . date('D j M'),
        ]);
    }

    $n = count($ids);
    $msg = 'Reminder sent to ' . $n . ' ' . ($n === 1 ? 'person' : 'people')
         . '. It pops up on their open pages within a few minutes, and waits in their bell.';
    if ($email) $msg .= ' ' . log_reminder_email($pdo, $ids, $note, $senderName);
    return $msg;
}

/**
 * The "also email them" tick. One message per person (never one with every
 * address on it), through the same SMTP mailbox as every other Anima email.
 *
 * ⚠ SLOW BY NATURE: the mailer talks to the mail server inline, person by
 * person, so the page waits. That is why it is a tick, off by default.
 */
function log_reminder_email(PDO $pdo, array $crewIds, string $note, string $senderName): string {
    global $config;
    if (!class_exists('SmtpMailer') || !SmtpMailer::isConfigured($config)) {
        return 'Email is not set up on this server, so nobody was emailed.';
    }
    $in = implode(',', array_map('intval', $crewIds));
    try {
        $people = $pdo->query("SELECT id, name, email FROM crew WHERE id IN ($in)")->fetchAll();
    } catch (Throwable $e) {
        return 'The email could not be sent.';
    }
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $link = site_url('mytasks.php');
    $who = $senderName ?: 'Production';
    $emailed = []; $noEmail = []; $failed = [];
    foreach ($people as $c) {
        $addr = trim((string)($c['email'] ?? ''));
        if ($addr === '') { $noEmail[] = (string)$c['name']; continue; }
        $subject = '[Anima] Please log your time for today';
        $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
              . '<p>Hi ' . $esc($c['name']) . ',</p>'
              . '<p>' . $esc($who) . ' asks you to log your time for today, ' . $esc(date('l j M')) . '.</p>'
              . ($note !== '' ? '<p style="border-left:3px solid #c9d3dc;padding-left:10px;color:#3a4a5a;">' . $esc($note) . '</p>' : '')
              . '<p><a href="' . $esc($link) . '" style="color:#2f6fb2;font-weight:600;">Log your time on My tasks</a></p>'
              . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
              . '</div>';
        $text = "Hi {$c['name']},\n\n{$who} asks you to log your time for today, " . date('l j M') . ".\n\n"
              . ($note !== '' ? "\"{$note}\"\n\n" : '')
              . "Log your time on My tasks: {$link}\n\nAnima | Project manager\n";
        try {
            (new SmtpMailer($config))->send([['email' => $addr, 'name' => $c['name']]], $subject, $html, $text);
            $emailed[] = (string)$c['name'];
        } catch (Throwable $e) {
            error_log('[Anima log reminder mail] ' . $e->getMessage());
            $failed[] = (string)$c['name'];
        }
    }
    $parts = [];
    if ($emailed) $parts[] = 'Emailed ' . count($emailed) . '.';
    if ($noEmail) $parts[] = 'No email address on file for ' . implode(', ', $noEmail) . '.';
    if ($failed)  $parts[] = 'The email to ' . implode(', ', $failed) . ' failed to send.';
    return implode(' ', $parts);
}

/** The reminders sent lately, newest first, with who they went to and how many put them away. */
function log_reminder_history(PDO $pdo, int $limit = 20): array {
    if (!log_reminders_available($pdo)) return [];
    try {
        $rows = $pdo->query(
            'SELECT r.*, COUNT(rr.crew_id) AS recipients, SUM(rr.dismissed_at IS NOT NULL) AS dismissed,
                    GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR \', \') AS names
               FROM log_reminders r
               LEFT JOIN log_reminder_recipients rr ON rr.reminder_id = r.id
               LEFT JOIN crew c ON c.id = rr.crew_id
              GROUP BY r.id
              ORDER BY r.sent_at DESC, r.id DESC
              LIMIT ' . max(1, min(100, $limit))
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}
