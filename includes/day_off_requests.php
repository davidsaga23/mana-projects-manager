<?php
// Asking for days off, and Production answering (migration 101).
//
// David, 2026-11-16: "let's add a button to request days off in peoples home
// page, that one need to be sent to production or admins for approval. New
// requests should be available in bookings".
//
// The life of a request:
//   * somebody asks, on Home, for THEIR OWN person only (request_day_off());
//   * Production and admins hear about it, in the bell and by email;
//   * on Bookings → Days off, one of them approves it, which writes a real day
//     off through add_crew_absence(), or declines it, with an optional note;
//   * the person hears the answer, in the bell and by email.
// Until it is approved a request counts NOWHERE: it is not in crew_absences,
// which is what the scheduler, missing-log days and the log reminder read. See
// the migration for why it is a table of its own.
//
// ⚠ THE NOTIFIERS NEVER THROW: a bell or an email that cannot be sent must not
// be the reason a request or an answer is not saved. They say so in the flash.

require_once __DIR__ . '/absences.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailer.php';

/** Has migration 101 run? */
function day_off_requests_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = absences_available($pdo) && column_exists($pdo, 'day_off_requests', 'state');
    return $ok;
}

/** "Mon 24 Nov" or "Mon 24 to Fri 28 Nov", for bells, emails and lists. */
function day_off_range_phrase(string $from, string $to): string {
    $f = strtotime($from);
    $t = strtotime($to);
    if ($from === $to) return date('D j M', $f);
    $sameMonth = date('Y-m', $f) === date('Y-m', $t);
    return date($sameMonth ? 'D j' : 'D j M', $f) . ' to ' . date('D j M', $t);
}

/**
 * Ask for days off. The crew id is the asker's own (home.php passes
 * effective_crew_id() and nothing posted can change it). Returns the flash.
 */
function request_day_off(PDO $pdo, int $crewId, string $from, string $to, string $reason, string $by): string {
    if (!day_off_requests_available($pdo)) {
        throw new RuntimeException('Asking for days off needs a database update (migration 101) an administrator has not applied yet.');
    }
    if ($crewId <= 0) throw new RuntimeException('Your login is not linked to a person in Anima yet. Ask Production.');
    [$from, $to] = absence_dates_check($from, $to);
    $reason = mb_substr(trim($reason), 0, 120);

    // The same days asked twice is a double click, not a second request.
    $dup = $pdo->prepare("SELECT id FROM day_off_requests
                           WHERE crew_id = ? AND from_date = ? AND to_date = ? AND state = 'pending'");
    $dup->execute([$crewId, $from, $to]);
    if ($dup->fetchColumn()) return 'You have already asked for those days. Production will answer it.';

    $pdo->prepare('INSERT INTO day_off_requests (crew_id, from_date, to_date, reason, requested_by)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$crewId, $from, $to, $reason !== '' ? $reason : null, $by]);

    $name = day_off_person_name($pdo, $crewId);
    $when = day_off_range_phrase($from, $to);
    notify_add($pdo, array_keys(day_off_approvers($pdo)), [
        'kind' => 'dayoff', 'actor' => $name, 'item_key' => 'approve',
        'summary' => 'asked for days off, ' . $when . ($reason !== '' ? ' (' . $reason . ')' : ''),
    ], $crewId);
    $mail = day_off_mail_approvers($pdo, $crewId, $name, $when, $reason);

    $days = working_days_between($from, $to);
    return sprintf('Asked for %s (%d working day%s). Production will approve or decline it, and you will be told.%s',
        $when, $days, $days === 1 ? '' : 's', $mail !== '' ? ' ' . $mail : '');
}

/** Take back a request nobody has answered yet. Only the asker's own. */
function withdraw_day_off_request(PDO $pdo, int $crewId, int $requestId): string {
    if (!day_off_requests_available($pdo)) return 'Nothing to withdraw.';
    $q = $pdo->prepare("DELETE FROM day_off_requests WHERE id = ? AND crew_id = ? AND state = 'pending'");
    $q->execute([$requestId, $crewId]);
    return $q->rowCount()
        ? 'Request withdrawn.'
        : 'That request was already answered or withdrawn, so nothing changed.';
}

/** One person's requests, newest first (Home). */
function day_off_requests_for_crew(PDO $pdo, int $crewId, int $limit = 20): array {
    if (!day_off_requests_available($pdo) || $crewId <= 0) return [];
    $q = $pdo->prepare('SELECT * FROM day_off_requests WHERE crew_id = ?
                         ORDER BY requested_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)));
    $q->execute([$crewId]);
    return $q->fetchAll();
}

/** Every request waiting for an answer, soonest first, with the person's name (Bookings). */
function day_off_requests_pending(PDO $pdo): array {
    if (!day_off_requests_available($pdo)) return [];
    return $pdo->query(
        "SELECT r.*, c.name FROM day_off_requests r JOIN crew c ON c.id = r.crew_id
          WHERE r.state = 'pending' ORDER BY r.from_date, c.name"
    )->fetchAll();
}

/** How many are waiting. 0 on any trouble, so a tab label can ask safely. */
function day_off_requests_pending_count(PDO $pdo): int {
    try {
        if (!day_off_requests_available($pdo)) return 0;
        return (int)$pdo->query("SELECT COUNT(*) FROM day_off_requests WHERE state = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Approve or decline. Production and admins only: bookings.php's POST is held
 * by auth.php (no artist may post there) and the bookings_edit switch, and
 * includes/days_off/prepare.php checks can_edit() again before calling this.
 *
 * Approving goes through add_crew_absence(), inside the same transaction as the
 * answer, so a request is never "approved" without its day off, nor the other
 * way round. Returns the flash.
 */
function decide_day_off_request(PDO $pdo, int $requestId, bool $approve, string $note, string $by): string {
    if (!day_off_requests_available($pdo)) throw new RuntimeException('The database update for day-off requests (migration 101) has not been applied yet.');
    $note = mb_substr(trim($note), 0, 255);

    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM day_off_requests WHERE id = ? AND state = 'pending' FOR UPDATE");
        $q->execute([$requestId]);
        $r = $q->fetch();
        if (!$r) throw new RuntimeException('That request was already answered or withdrawn, so nothing changed.');

        $absenceId = null;
        $saved = '';
        if ($approve) {
            $saved = add_crew_absence($pdo, (int)$r['crew_id'], (string)$r['from_date'], (string)$r['to_date'],
                                      (string)($r['reason'] ?? ''), $by);
            $absenceId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE day_off_requests SET state = ?, decided_by = ?, decided_at = NOW(), note = ?, absence_id = ?
                        WHERE id = ?")
            ->execute([$approve ? 'approved' : 'declined', $by, $note !== '' ? $note : null, $absenceId, $requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Tell the person, after the answer is safely saved.
    $crewId = (int)$r['crew_id'];
    $name = day_off_person_name($pdo, $crewId);
    $when = day_off_range_phrase((string)$r['from_date'], (string)$r['to_date']);
    $summary = ($approve ? 'approved your days off, ' : 'declined your days off, ') . $when
             . (!$approve && $note !== '' ? ': ' . $note : '');
    notify_add($pdo, [$crewId], ['kind' => 'dayoff', 'actor' => $by, 'item_key' => 'mine', 'summary' => $summary],
               (int)(function_exists('effective_crew_id') ? (effective_crew_id($pdo) ?? 0) : 0));
    $mail = day_off_mail_person($pdo, $crewId, $by, $approve, $when, $note);

    if ($approve) {
        return 'Approved ' . $name . "'s days off. " . str_replace('Days off saved, ', 'Saved, ', $saved)
             . ($mail !== '' ? ' ' . $mail : '');
    }
    return 'Declined ' . $name . "'s request for " . $when . '.' . ($mail !== '' ? ' ' . $mail : '');
}

// --- who is told, and how ---------------------------------------------------

function day_off_person_name(PDO $pdo, int $crewId): string {
    $q = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
    $q->execute([$crewId]);
    return (string)($q->fetchColumn() ?: 'Somebody');
}

/**
 * Production and admins who can answer: active logins with role admin or
 * editor. crew_id => ['name', 'email'] for those linked to a person (the bell
 * is a person's), plus anybody unlinked under a negative key so they still get
 * the email. Never throws.
 */
function day_off_approvers(PDO $pdo): array {
    try {
        $ownEmail = column_exists($pdo, 'users', 'email') ? "NULLIF(TRIM(COALESCE(u.email, '')), '')" : 'NULL';
        $rows = $pdo->query(
            "SELECT u.id, u.crew_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name,
                    COALESCE({$ownEmail}, NULLIF(TRIM(COALESCE(c.email, '')), '')) AS email
               FROM users u LEFT JOIN crew c ON c.id = u.crew_id
              WHERE u.active = 1 AND u.role IN ('admin', 'editor')"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[Anima days off] approvers: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $key = (int)$r['crew_id'] > 0 ? (int)$r['crew_id'] : -(int)$r['id'];
        // Two logins for one person: keep the one with an email.
        if (!isset($out[$key]) || (!$out[$key]['email'] && $r['email'])) {
            $out[$key] = ['name' => (string)$r['name'], 'email' => (string)($r['email'] ?? '')];
        }
    }
    return $out;
}

/** Email Production and admins about a new request. Returns a sentence, or ''. */
function day_off_mail_approvers(PDO $pdo, int $askerCrewId, string $name, string $when, string $reason): string {
    $to = [];
    foreach (day_off_approvers($pdo) as $key => $a) {
        if ($key === $askerCrewId || $a['email'] === '') continue;   // never tell you about your own request
        $to[$a['email']] = ['email' => $a['email'], 'name' => $a['name']];
    }
    if (!$to) return '';
    $url = site_url('bookings.php?tab=days-off#requests');
    return day_off_send(array_values($to),
        $name . ' asked for days off, ' . $when,
        '<p><strong>' . day_off_h($name) . '</strong> asked for days off: <strong>' . day_off_h($when) . '</strong>'
          . ($reason !== '' ? ' (' . day_off_h($reason) . ')' : '') . '.</p>'
          . '<p>Approve or decline it on Bookings, in the Days off tab.</p>',
        $name . ' asked for days off: ' . $when . ($reason !== '' ? ' (' . $reason . ')' : '') . ".\n"
          . "Approve or decline it on Bookings, in the Days off tab.\n",
        $url, 'Open the requests', 'Production was emailed.');
}

/** Email the person the answer. Returns a sentence, or ''. */
function day_off_mail_person(PDO $pdo, int $crewId, string $by, bool $approved, string $when, string $note): string {
    try {
        $q = $pdo->prepare("SELECT name, NULLIF(TRIM(COALESCE(email, '')), '') AS email FROM crew WHERE id = ?");
        $q->execute([$crewId]);
        $p = $q->fetch();
    } catch (Throwable $e) {
        return '';
    }
    if (!$p || !$p['email']) return '';
    $verb = $approved ? 'approved' : 'declined';
    $html = '<p><strong>' . day_off_h($by) . '</strong> ' . $verb . ' your days off: <strong>' . day_off_h($when) . '</strong>.</p>'
          . ($note !== '' ? '<p>' . nl2br(day_off_h($note)) . '</p>' : '')
          . ($approved ? '<p>You do not need to log time on those days.</p>' : '');
    $text = $by . ' ' . $verb . ' your days off: ' . $when . ".\n"
          . ($note !== '' ? "\n" . $note . "\n" : '')
          . ($approved ? "\nYou do not need to log time on those days.\n" : '');
    return day_off_send([['email' => $p['email'], 'name' => $p['name']]],
        'Your days off were ' . $verb . ', ' . $when, $html, $text,
        site_url('home.php?tab=general#days-off'), 'Open Home', (string)$p['name'] . ' was emailed.');
}

function day_off_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Send one email in the house style. Never throws; returns $ok or a sentence about the failure. */
function day_off_send(array $to, string $subject, string $bodyHtml, string $bodyText, string $url, string $button, string $ok): string {
    global $config;
    if (!SmtpMailer::isConfigured($config ?? [])) return '';
    $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
          . $bodyHtml
          . '<p style="margin-top:16px;"><a href="' . day_off_h($url) . '" '
          . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
          . day_off_h($button) . '</a></p>'
          . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p></div>';
    try {
        (new SmtpMailer($config))->send($to, '[Anima] ' . $subject, $html, $bodyText . "\n" . $button . ': ' . $url . "\n");
        return $ok;
    } catch (Throwable $e) {
        error_log('[Anima days off mail] ' . $e->getMessage());
        return 'The email could not be sent (' . $e->getMessage() . '), the bell still rang.';
    }
}
