<?php
/**
 * Two email summaries a day, instead of one email per thing (migration 104).
 *
 * David, 2026-11-17: "instead of sending an email for each mention, new task or
 * comment, let's send 2 instead. One at the beginning of the day (around 9 am
 * PST) and one at the end (5 pm PST) with a summary of new stuff for that
 * person."
 *
 * HOW IT HANGS TOGETHER
 *
 *   1. Nothing sends an email the moment something happens any more. Every
 *      place that used to (an @ on a ticket or a board task, a ticket handed to
 *      somebody or moved, tasks made from a meeting transcript) now rings that
 *      person's BELL (includes/notify.php) instead. Board tasks, review notes,
 *      Realm ravens and tags already rang it.
 *   2. At 9 am and 5 pm Pacific, digest_run() goes through everybody whose bell
 *      has something new and still UNREAD, and sends each one email listing it,
 *      grouped, with a link to each thing. Nothing new, no email.
 *   3. digest_run() is started by cron_digest.php, which David adds once as a
 *      cPanel Cron Job every 15 minutes. If there is no cron job, the bell's own
 *      30-second check (notify_poll.php) starts it instead, 20 minutes late, as
 *      a fallback (digest_web_fallback()).
 *
 * ⚠ THE BELL IS THE ONE LIST. The summary is not a second record of "things to
 * email" that would have to be kept in step with the bell by every writer; it
 * READS the bell. A new kind of bell item turns up in the summary on its own.
 * That also settles "who may see this": a bell item is only ever written for
 * somebody who could see what it points at (a private Realm channel rings only
 * its members, a task rings the people on it), and the summary sends nobody
 * anything that is not already in their own bell. No amounts or rates are in
 * the bell, so none can be in the summary.
 *
 * ⚠ ONLY WHAT IS STILL UNREAD. Opening the bell marks everything in it read
 * (notify_mark_seen()), and anything somebody has already read in Anima is left
 * out of their email: the summary is for what they have NOT seen. An email that
 * repeats what you read an hour ago is the email people learn to delete unread.
 *
 * ⚠ NEVER TWICE. Each person's summary for a slot ("2026-11-17 am") is CLAIMED
 * in digest_people before the email goes out, with an UPDATE that only one
 * sender can win, so a cron job and the fallback running at the same moment, or
 * a cron job that runs twice, still send one email. Each person also remembers
 * the last bell item already looked at (upto_id), so the evening summary starts
 * where the morning one stopped. A failed send gives the claim back, so the
 * next run tries again.
 */

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailer.php';

/** The studio's clock for the summaries: Pacific, daylight saving included. */
const DIGEST_TZ = 'America/Los_Angeles';

/**
 * When each summary goes out, and how late it may still go. Pacific hours.
 * A run that misses its window entirely (the server was down all morning) sends
 * nothing for that slot; what it would have listed is still unread and simply
 * goes in the next one, so nothing is lost, and nobody gets a "morning" summary
 * at 4 pm.
 */
const DIGEST_SLOTS = [
    'am' => ['from' => 9,  'until' => 13, 'name' => 'morning'],
    'pm' => ['from' => 17, 'until' => 22, 'name' => 'end-of-day'],
];

/**
 * Kinds that are never in a summary. 'reminder' (log your time) and 'later' (a
 * saved Realm message's reminder) are reminders whose moment has passed by the
 * time a summary goes out. 'dayoff' (a request for days off, and its answer)
 * still has its OWN email, sent at once, because somebody is waiting on it
 * (includes/day_off_requests.php); listing it again here would say it twice.
 */
const DIGEST_SKIP_KINDS = ['reminder', 'later', 'dayoff'];

/** Has migration 104 run? Until it has, nothing is sent and nothing breaks. */
function digest_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = notify_available($pdo)
           && column_exists($pdo, 'digest_people', 'upto_id')
           && column_exists($pdo, 'digest_runs', 'slot_key');
    }
    return $ok;
}

function digest_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ));
}

/**
 * Which summary is due right now, if any.
 *
 * @return array{key:string, slot:string, name:string, start:DateTimeImmutable}|null
 */
function digest_due_slot(DateTimeImmutable $now): ?array {
    $now = $now->setTimezone(new DateTimeZone(DIGEST_TZ));
    $h = (int)$now->format('G');
    foreach (DIGEST_SLOTS as $slot => $s) {
        if ($h >= $s['from'] && $h < $s['until']) return digest_slot($now, $slot);
    }
    return null;
}

/** The slot $slot on $now's Pacific date, whatever the hour (for --slot testing). */
function digest_slot(DateTimeImmutable $now, string $slot): array {
    $now = $now->setTimezone(new DateTimeZone(DIGEST_TZ));
    $s = DIGEST_SLOTS[$slot];
    return [
        'key'   => $now->format('Y-m-d') . ' ' . $slot,
        'slot'  => $slot,
        'name'  => $s['name'],
        'start' => $now->setTime($s['from'], 0),
    ];
}

// ---------------------------------------------------------------------------
// Each person's choice: both summaries, one of them, or none
// ---------------------------------------------------------------------------

/** The choices, in the words Home shows. */
function digest_pref_options(): array {
    return [
        'both'    => 'Morning and end of day (9 am and 5 pm Pacific)',
        'morning' => 'Morning only (9 am Pacific)',
        'evening' => 'End of day only (5 pm Pacific)',
        'off'     => 'No email summaries',
    ];
}

/** This person's choice. 'both' when they never chose, or before migration 104. */
function digest_pref(PDO $pdo, int $crewId): string {
    if ($crewId <= 0 || !digest_available($pdo)) return 'both';
    try {
        $q = $pdo->prepare('SELECT pref FROM digest_people WHERE crew_id = ?');
        $q->execute([$crewId]);
        $p = (string)($q->fetchColumn() ?: 'both');
        return isset(digest_pref_options()[$p]) ? $p : 'both';
    } catch (Throwable $e) {
        return 'both';
    }
}

/** Save this person's choice. Throws a sentence for the flash message. */
function digest_set_pref(PDO $pdo, int $crewId, string $pref): string {
    if (!digest_available($pdo)) {
        throw new RuntimeException('Email summaries need database update 104, which has not been applied yet.');
    }
    $opts = digest_pref_options();
    if ($crewId <= 0 || !isset($opts[$pref])) throw new RuntimeException('That is not one of the choices.');
    $pdo->prepare('INSERT INTO digest_people (crew_id, pref) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE pref = VALUES(pref)')->execute([$crewId, $pref]);
    return $pref === 'off'
        ? 'Saved: no email summaries. Your bell in Anima still shows everything.'
        : 'Saved: ' . lcfirst($opts[$pref]) . '.';
}

// ---------------------------------------------------------------------------
// The things that used to email straight away, now ringing the bell instead
// ---------------------------------------------------------------------------

/** The person doing this, so their own action never rings their own bell. */
function digest_actor_crew(PDO $pdo): int {
    return function_exists('effective_crew_id') ? (int)(effective_crew_id($pdo) ?? 0) : 0;
}

/** "RTC-12" and the title, for a ticket's line in the bell. Null if it is gone. */
function ticket_bell_row(PDO $pdo, int $ticketId): ?array {
    $q = $pdo->prepare(
        'SELECT t.id, t.seq, t.title, t.project_id, t.assigned_crew_id,
                p.code AS project_code, st.name AS status_name,
                c.name AS crew_name, c.is_team, c.is_placeholder
           FROM tickets t
           LEFT JOIN projects p   ON p.id  = t.project_id
           LEFT JOIN statuses st  ON st.id = t.status_id
           LEFT JOIN crew c       ON c.id  = t.assigned_crew_id
          WHERE t.id = ?'
    );
    $q->execute([$ticketId]);
    $t = $q->fetch();
    if (!$t) return null;
    // The same fallback ticket_ref() uses: a card with no project is called by its id.
    $code = (string)($t['project_code'] ?? '');
    $t['label'] = $code !== '' ? $code . '-' . (int)$t['seq'] : '#' . (int)$t['id'];
    $t['line']  = mb_substr($t['label'] . ' ' . (string)$t['title'], 0, 200);
    return $t;
}

/** "Renata", "Renata and Tim", "Renata, Tim and Ana". */
function digest_names(array $names): string {
    $names = array_values(array_filter(array_map('strval', $names), 'strlen'));
    if (count($names) <= 1) return $names[0] ?? '';
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/**
 * The sentence the author sees after naming or assigning somebody: who will be
 * told, and who has no email address for the summary. Said out loud for the
 * same reason the mailers always did: a notice that silently reaches nobody is
 * worse than none, because the author has moved on believing it landed.
 */
function digest_told_sentence(PDO $pdo, array $crewIds): string {
    $crewIds = array_values(array_unique(array_filter(array_map('intval', $crewIds))));
    if (!$crewIds) return '';
    // Only a sentence: a failure here must never cost the comment that was saved.
    try {
        $rows = $pdo->query('SELECT id, name, email FROM crew WHERE id IN (' . implode(',', $crewIds) . ')')->fetchAll();
    } catch (Throwable $e) {
        return '';
    }
    $all = []; $noEmail = [];
    foreach ($rows as $r) {
        $all[] = (string)$r['name'];
        if (trim((string)($r['email'] ?? '')) === '') $noEmail[] = (string)$r['name'];
    }
    if (!$all) return '';
    $said = digest_names($all) . ' will see it in the bell'
          . (digest_available($pdo) ? ' and in the next email summary.' : '.');
    if ($noEmail && digest_available($pdo)) {
        $said .= ' ' . digest_names($noEmail) . ' ' . (count($noEmail) === 1 ? 'has' : 'have')
               . ' no email address on file, so only the bell will say so.';
    }
    return $said;
}

/**
 * The people a ticket's owner stands for: the person, or for a TEAM ("Animation")
 * every active person whose role names it, the fan-out notify_assignee() did for
 * the email. A crew SLOT nobody fills yet is nobody.
 *
 * @return array{0:int[], 1:string} [crew ids, a sentence when there is nobody]
 */
function ticket_bell_people(PDO $pdo, array $t): array {
    if (empty($t['assigned_crew_id'])) return [[], ''];
    if ((int)($t['is_team'] ?? 0) === 1) {
        $q = $pdo->prepare(
            "SELECT id FROM crew
              WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
                AND LOWER(role) LIKE CONCAT('%', LOWER(?), '%')"
        );
        $q->execute([(string)$t['crew_name']]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        return [$ids, $ids ? '' : 'Assigned to the team "' . $t['crew_name']
            . '", but nobody active has that word in their role, so nobody was told.'];
    }
    if ((int)($t['is_placeholder'] ?? 0) === 1) {
        return [[], 'Assigned to the slot "' . $t['crew_name']
            . '", which nobody fills yet, so there was nobody to tell. Putting somebody into that position on the Staffing screen fixes this.'];
    }
    return [[(int)$t['assigned_crew_id']], ''];
}

/**
 * A ticket was handed to somebody ('assigned') or its status moved ('status'):
 * ring the owner's bell. Replaces notify_assignee()'s email.
 * Never throws; returns [told anybody?, a sentence for the flash].
 */
function ticket_ring_assignee(PDO $pdo, int $ticketId, string $reason = 'assigned'): array {
    try {
        $t = ticket_bell_row($pdo, $ticketId);
        if (!$t) return [false, ''];
        [$people, $why] = ticket_bell_people($pdo, $t);
        if (!$people) return [false, $why];
        $isStatus = $reason === 'status';
        $me = digest_actor_crew($pdo);
        notify_add($pdo, $people, [
            'kind'       => $isStatus ? 'status' : 'assigned',
            'actor'      => function_exists('current_user_display') ? current_user_display() : 'Anima',
            'summary'    => $t['line'] . ($isStatus && $t['status_name'] ? ' → ' . $t['status_name'] : ''),
            'project_id' => $t['project_id'] ?: null,
            'ticket_id'  => $ticketId,
        ], $me);
        $others = array_values(array_diff($people, [$me]));
        return [(bool)$others, $others ? digest_told_sentence($pdo, $others) : ''];
    } catch (Throwable $e) {
        error_log('[Anima bell] ticket ring: ' . $e->getMessage());
        return [false, ''];
    }
}

/**
 * A comment on a ticket named people: ring their bells. Replaces
 * notify_mentioned()'s email. Never throws.
 *
 * @param int[] $crewIds from ticket_parse_mentions()
 */
function ticket_ring_mentioned(PDO $pdo, int $ticketId, int $commentId, array $crewIds, string $byWho): array {
    if (!$crewIds) return [false, ''];
    try {
        $t = ticket_bell_row($pdo, $ticketId);
        if (!$t) return [false, ''];
        $me = digest_actor_crew($pdo);
        notify_add($pdo, $crewIds, [
            'kind'       => 'mention',
            'actor'      => $byWho,
            'summary'    => $t['line'],
            'project_id' => $t['project_id'] ?: null,
            'ticket_id'  => $ticketId,
            'comment_id' => $commentId ?: null,
        ], $me);
        $others = array_values(array_diff(array_map('intval', $crewIds), [$me]));
        return [(bool)$others, digest_told_sentence($pdo, $others)];
    } catch (Throwable $e) {
        error_log('[Anima bell] ticket mention: ' . $e->getMessage());
        return [false, ''];
    }
}

/**
 * Tasks just made from a meeting transcript: one bell line per card for the
 * person it was given to. Replaces notify_transcript_tasks()'s email; the
 * "one email, not a dozen" that function was written for is now what every
 * summary is.
 *
 * @param array<int, array<int, array{ticket_id?:int}>> $byCrew crew id => their cards
 */
function ticket_ring_transcript(PDO $pdo, array $byCrew): array {
    $told = [];
    foreach ($byCrew as $crewId => $items) {
        foreach ($items as $it) {
            if (empty($it['ticket_id'])) continue;
            [$ok] = ticket_ring_assignee($pdo, (int)$it['ticket_id'], 'assigned');
            if ($ok) $told[(int)$crewId] = true;
        }
    }
    return [(bool)$told, $told ? digest_told_sentence($pdo, array_keys($told)) : ''];
}

// ---------------------------------------------------------------------------
// The summary itself
// ---------------------------------------------------------------------------

/** How each kind of bell item is grouped in the email, in this order. */
function digest_groups(): array {
    return [
        'assigned' => 'New tasks for you',
        'mention'  => 'Mentions',
        'review'   => 'Review notes',
        'status'   => 'Updates on tasks you follow',
        'realm'    => 'In the Realm',
        'other'    => 'Everything else',
    ];
}

function digest_group_of(string $kind): string {
    if (in_array($kind, ['raven', 'tag'], true)) return 'realm';
    // A change on a task subscribed to on Spell (migration 108) is an update on a
    // task you follow, which is exactly what that group already says.
    if ($kind === 'update') return 'status';
    return isset(digest_groups()[$kind]) ? $kind : 'other';
}

/**
 * What is new and unread in one person's bell since their last summary.
 *
 * $uptoId null means they have never had one: only the last 12 hours count,
 * so the first summary after this goes live is not a list of everything that
 * ever rang.
 *
 * @return array{0:array, 1:int} [bell rows, oldest first, with project and snippet; highest id looked at]
 */
function digest_items_for(PDO $pdo, int $crewId, ?int $uptoId): array {
    // The ceiling first, so a bell item written while this runs is left for the
    // next summary rather than marked as looked-at without being listed.
    $q = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE crew_id = ?');
    $q->execute([$crewId]);
    $maxId = (int)$q->fetchColumn();

    $skip = "'" . implode("','", DIGEST_SKIP_KINDS) . "'";
    $sql = "SELECT * FROM notifications
             WHERE crew_id = ? AND seen_at IS NULL AND id <= ? AND kind NOT IN ({$skip})";
    $args = [$crewId, $maxId];
    if ($uptoId === null) {
        $sql .= ' AND created_at >= NOW() - INTERVAL 12 HOUR';
    } else {
        $sql .= ' AND id > ?';
        $args[] = $uptoId;
    }
    $q = $pdo->prepare($sql . ' ORDER BY id LIMIT 200');
    $q->execute($args);
    $rows = $q->fetchAll();
    if (!$rows) return [[], $maxId];

    // The show each line is about, in one query.
    $pids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['project_id'] ?? 0), $rows))));
    $projects = [];
    if ($pids) {
        foreach ($pdo->query('SELECT id, code, name FROM projects WHERE id IN (' . implode(',', $pids) . ')') as $p) {
            $projects[(int)$p['id']] = $p;
        }
    }
    // What was said, for a mention or a review note: enough to know whether it
    // needs answering now. Only for those two kinds, whose bell line was written
    // for this person BECAUSE of that comment, on a task they are on.
    $cids = [];
    foreach ($rows as $r) {
        // 'update' too (108): a new comment on a task the person subscribed to,
        // on a show they can see, which is why they were rung at all.
        if (!empty($r['comment_id']) && in_array($r['kind'], ['mention', 'review', 'update'], true)) $cids[] = (int)$r['comment_id'];
    }
    $snips = [];
    if ($cids) {
        foreach ($pdo->query('SELECT id, body FROM ticket_comments WHERE id IN (' . implode(',', array_unique($cids)) . ')') as $c) {
            $b = trim(preg_replace('/\s+/u', ' ', (string)$c['body']));
            $snips[(int)$c['id']] = mb_strlen($b) > 220 ? mb_substr($b, 0, 220) . '…' : $b;
        }
    }
    foreach ($rows as &$r) {
        $p = $projects[(int)($r['project_id'] ?? 0)] ?? null;
        $r['_project'] = $p ? trim(($p['code'] ? $p['code'] . ' · ' : '') . $p['name']) : '';
        $r['_snippet'] = (!empty($r['comment_id']) && in_array($r['kind'], ['mention', 'review', 'update'], true))
            ? ($snips[(int)$r['comment_id']] ?? '') : '';
    }
    unset($r);
    return [$rows, $maxId];
}

/** When it happened, in Pacific time: "9:14 am", or "Mon 4:02 pm" if not today. */
function digest_when(string $createdAt, DateTimeImmutable $now): string {
    try {
        $t = (new DateTimeImmutable($createdAt, new DateTimeZone(date_default_timezone_get())))
                 ->setTimezone(new DateTimeZone(DIGEST_TZ));
    } catch (Throwable $e) {
        return '';
    }
    $fmt = $t->format('Y-m-d') === $now->setTimezone(new DateTimeZone(DIGEST_TZ))->format('Y-m-d') ? 'g:i a' : 'D g:i a';
    return $t->format($fmt);
}

/**
 * The email for one person: [subject, html, text, how many items].
 *
 * Plain on purpose: one column, system fonts, inline styles (mail programs
 * throw away <style> blocks), and the same blue button and grey footer the
 * other Anima emails have always had.
 *
 * @param array $person name
 * @param array $items  from digest_items_for()
 * @param array $slot   from digest_due_slot()
 */
function digest_render(array $person, array $items, array $slot, ?DateTimeImmutable $now = null): array {
    $now = $now ?: digest_now();
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $first = trim(explode(' ', trim((string)$person['name']))[0] ?? '') ?: 'there';

    $groups = [];
    foreach ($items as $n) $groups[digest_group_of((string)$n['kind'])][] = $n;
    $order = digest_groups();
    uksort($groups, fn($a, $b) => array_search($a, array_keys($order)) <=> array_search($b, array_keys($order)));

    $total = count($items);
    // "2 mentions, 1 new task": the subject says what is inside without opening it.
    $countWords = [
        'assigned' => ['new task', 'new tasks'], 'mention' => ['mention', 'mentions'],
        'review'   => ['review note', 'review notes'], 'status' => ['update', 'updates'],
        'realm'    => ['Realm message', 'Realm messages'],
        'other'    => ['other thing', 'other things'],
    ];
    $bits = [];
    foreach ($groups as $g => $list) {
        $c = count($list);
        $bits[] = $c . ' ' . $countWords[$g][$c === 1 ? 0 : 1];
    }
    $isAm = $slot['slot'] === 'am';
    $subject = 'Anima ' . ($isAm ? 'morning' : 'end-of-day') . ' summary: ' . implode(', ', array_slice($bits, 0, 3))
             . (count($bits) > 3 ? ' and more' : '');
    $intro = $isAm
        ? 'Good morning, ' . $first . '. Here is what is new for you in Anima since your last summary.'
        : 'Hi ' . $first . ', here is what came in for you in Anima today that you have not seen yet.';

    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.45;color:#1c2733;max-width:620px;">'
          . '<p style="margin:0 0 16px;">' . $esc($intro) . '</p>';
    $text = $intro . "\n";

    foreach ($groups as $g => $list) {
        $html .= '<h3 style="font-size:15px;margin:20px 0 8px;padding-bottom:4px;border-bottom:1px solid #e3e8ee;">'
               . $esc($order[$g]) . ' <span style="color:#8a97a3;font-weight:normal;">(' . count($list) . ')</span></h3>'
               . '<ul style="list-style:none;padding:0;margin:0;">';
        $text .= "\n" . mb_strtoupper($order[$g]) . ' (' . count($list) . ")\n";
        foreach ($list as $n) {
            $kind  = (string)$n['kind'];
            $actor = trim((string)($n['actor'] ?: 'Somebody'));
            $verb  = notify_verb($kind);
            $what  = trim((string)($n['summary'] ?: 'a task'));
            $url   = site_url(notify_link($n));
            $meta  = array_filter([$n['_project'], digest_when((string)$n['created_at'], $now)]);

            $html .= '<li style="margin:0 0 12px;">'
                   . '<a href="' . $esc($url) . '" style="color:#1c2733;text-decoration:none;">'
                   . '<strong>' . $esc($actor) . '</strong> ' . $esc($verb) . ($verb !== '' ? ' ' : '')
                   . '<span style="color:#2f6fb2;text-decoration:underline;">' . $esc($what) . '</span></a>'
                   . ($meta ? '<div style="color:#8a97a3;font-size:12px;">' . $esc(implode(' · ', $meta)) . '</div>' : '')
                   . ($n['_snippet'] !== ''
                       ? '<div style="background:#f4f6f8;padding:6px 10px;border-radius:6px;margin-top:4px;color:#3b4a59;">'
                         . $esc($n['_snippet']) . '</div>'
                       : '')
                   . '</li>';
            $text .= '- ' . trim($actor . ' ' . $verb . ' ' . $what)
                   . ($meta ? ' (' . implode(', ', $meta) . ')' : '') . "\n"
                   . ($n['_snippet'] !== '' ? '  "' . $n['_snippet'] . "\"\n" : '')
                   . '  ' . $url . "\n";
        }
        $html .= '</ul>';
    }

    $home  = site_url('home.php?tab=general#email-summaries');
    $open  = site_url('index.php');
    $html .= '<p style="margin:22px 0 0;"><a href="' . $esc($open) . '" '
           . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
           . 'Open Anima</a></p>'
           . '<p style="color:#8a97a3;font-size:12px;margin-top:22px;">'
           . 'Anima sends this at 9 am and 5 pm Pacific, only when something is new, and leaves out anything '
           . 'you have already seen in the bell. <a href="' . $esc($home) . '" style="color:#8a97a3;">Choose which summaries you get</a>.'
           . '<br>Anima | Project manager</p>'
           . '</div>';
    $text .= "\nOpen Anima: {$open}\n\n"
           . "Anima sends this at 9 am and 5 pm Pacific, only when something is new, and leaves out anything you have already seen in the bell.\n"
           . "Choose which summaries you get: {$home}\n\nAnima | Project manager\n";

    return [$subject, $html, $text, $total];
}

/**
 * Send whichever summary is due. The cron job and the fallback both call this.
 *
 * @param string $via  'cron' or 'web'
 * @param array  $opt  slot ('am'/'pm', forces that slot today, for testing),
 *                     dry (true: send and record nothing, report who would get
 *                     what), budget (seconds before it stops and leaves the
 *                     rest for the next run; 20 by default, under the host's
 *                     usual 30-second limit), now (a DateTimeImmutable)
 * @return array{status:string, slot:?string, sent:int, failed:int, empty:int, lines:string[]}
 */
function digest_run(PDO $pdo, string $via, array $opt = []): array {
    global $config;
    $out = ['status' => '', 'slot' => null, 'sent' => 0, 'failed' => 0, 'empty' => 0, 'lines' => []];
    if (!digest_available($pdo)) { $out['status'] = 'Database update 104 has not been applied yet.'; return $out; }

    $now  = $opt['now'] ?? digest_now();
    $slot = !empty($opt['slot']) && isset(DIGEST_SLOTS[$opt['slot']]) ? digest_slot($now, $opt['slot']) : digest_due_slot($now);
    if (!$slot) { $out['status'] = 'No summary is due at ' . $now->format('g:i a') . ' Pacific.'; return $out; }
    $out['slot'] = $slot['key'];
    $dry = !empty($opt['dry']);

    $done = $pdo->prepare('SELECT 1 FROM digest_runs WHERE slot_key = ? AND complete = 1 LIMIT 1');
    $done->execute([$slot['key']]);
    if ($done->fetchColumn() && !$dry) { $out['status'] = 'The ' . $slot['key'] . ' summaries have already gone out.'; return $out; }

    if (!SmtpMailer::isConfigured($config) && !$dry) {
        $out['status'] = 'Email is not set up on this server (smtp_* in config.php), so no summaries were sent.';
        return $out;
    }

    $runId = 0;
    if (!$dry) {
        $pdo->prepare('INSERT INTO digest_runs (slot_key, via, started_at) VALUES (?, ?, NOW())')
            ->execute([$slot['key'], substr($via, 0, 10)]);
        $runId = (int)$pdo->lastInsertId();
    }

    // Everybody with somebody to email, who wants this slot, has not had it yet,
    // and has something new and unread. A team or an empty slot is not a person.
    $want = $slot['slot'] === 'am' ? 'morning' : 'evening';
    $skip = "'" . implode("','", DIGEST_SKIP_KINDS) . "'";
    $q = $pdo->prepare(
        "SELECT c.id, c.name, TRIM(c.email) AS email, dp.last_slot, dp.upto_id
           FROM crew c
           LEFT JOIN digest_people dp ON dp.crew_id = c.id
          WHERE c.active = 1 AND COALESCE(c.is_team, 0) = 0 AND COALESCE(c.is_placeholder, 0) = 0
            AND c.email IS NOT NULL AND TRIM(c.email) <> ''
            AND COALESCE(dp.pref, 'both') IN ('both', ?)
            AND (dp.last_slot IS NULL OR dp.last_slot <> ?)
            AND EXISTS (SELECT 1 FROM notifications n
                         WHERE n.crew_id = c.id AND n.seen_at IS NULL
                           AND n.id > COALESCE(dp.upto_id, 0) AND n.kind NOT IN ({$skip}))
          ORDER BY c.id"
    );
    $q->execute([$want, $slot['key']]);
    $people = $q->fetchAll();

    $budget  = (float)($opt['budget'] ?? 20);
    $started = microtime(true);
    $stopped = false;

    foreach ($people as $p) {
        if (microtime(true) - $started > $budget) { $stopped = true; break; }
        $crewId = (int)$p['id'];
        $upto   = $p['upto_id'] !== null ? (int)$p['upto_id'] : null;

        if (!$dry) {
            // ⚠ THE CLAIM. Only one sender can move last_slot to this slot, so
            // only one sends. Everybody else finds the row already moved and
            // leaves this person alone.
            $pdo->prepare('INSERT IGNORE INTO digest_people (crew_id) VALUES (?)')->execute([$crewId]);
            $claim = $pdo->prepare('UPDATE digest_people SET last_slot = ?
                                     WHERE crew_id = ? AND (last_slot IS NULL OR last_slot <> ?)');
            $claim->execute([$slot['key'], $crewId, $slot['key']]);
            if ($claim->rowCount() !== 1) continue;
        }

        try {
            [$items, $maxId] = digest_items_for($pdo, $crewId, $upto);
            if (!$items) {
                // Nothing left once looked at closely (the first-time 12-hour
                // window, or read a moment ago): no email, and start after it.
                if (!$dry) $pdo->prepare('UPDATE digest_people SET upto_id = ? WHERE crew_id = ?')->execute([$maxId, $crewId]);
                $out['empty']++;
                continue;
            }
            [$subject, $html, $text, $n] = digest_render($p, $items, $slot, $now);
            if ($dry) {
                $out['lines'][] = "would email {$p['name']} <{$p['email']}>: {$subject}";
                $out['sent']++;
                continue;
            }
            (new SmtpMailer($config))->send([['email' => $p['email'], 'name' => $p['name']]], $subject, $html, $text);
            $pdo->prepare('UPDATE digest_people SET upto_id = ?, sent_at = NOW() WHERE crew_id = ?')->execute([$maxId, $crewId]);
            $out['sent']++;
            $out['lines'][] = "emailed {$p['name']} ({$n} new)";
        } catch (Throwable $e) {
            // Give the claim back, so the next run tries this person again.
            error_log('[Anima digest] ' . $p['name'] . ': ' . $e->getMessage());
            if (!$dry) {
                $pdo->prepare('UPDATE digest_people SET last_slot = ? WHERE crew_id = ? AND last_slot = ?')
                    ->execute([$p['last_slot'], $crewId, $slot['key']]);
            }
            $out['failed']++;
            $out['lines'][] = "FAILED {$p['name']}: " . $e->getMessage();
        }
    }

    $complete = !$stopped && $out['failed'] === 0;
    if ($runId) {
        $note = $stopped ? 'Stopped after ' . (int)$budget . ' s; the next run carries on.' : null;
        $pdo->prepare('UPDATE digest_runs SET finished_at = NOW(), sent = ?, failed = ?, complete = ?, note = ? WHERE id = ?')
            ->execute([$out['sent'], $out['failed'], $complete ? 1 : 0, $note, $runId]);
    }
    $out['status'] = ($dry ? 'Dry run, nothing sent. ' : '')
        . ucfirst($slot['name']) . ' summary ' . $slot['key'] . ': '
        . $out['sent'] . ($dry ? ' would be sent' : ' sent') . ', ' . $out['failed'] . ' failed'
        . ($out['empty'] ? ', ' . $out['empty'] . ' had nothing new once checked' : '')
        . ($stopped ? '; stopped for time, the next run carries on' : '') . '.';
    return $out;
}

/**
 * The fallback for when there is no cron job: the bell's 30-second check
 * (notify_poll.php) calls this after it has answered.
 *
 * It does nothing at all unless a summary is due, is at least 20 minutes late
 * (a cron job running every 15 minutes would have taken it by then), has not
 * gone out, and no other run started in the last 10 minutes. So with the cron
 * job in place it costs one small query, and only inside the two windows.
 *
 * ⚠ NEVER THROWS, AND NEVER MAKES THE BELL WAIT: the answer has already been
 * sent to the browser before any email is written (fastcgi_finish_request(),
 * or LiteSpeed's twin), and everything is caught.
 * Switch it off with 'digest_web_fallback' => false in config.php.
 */
function digest_web_fallback(PDO $pdo): void {
    global $config;
    try {
        if (isset($config['digest_web_fallback']) && !$config['digest_web_fallback']) return;
        $now  = digest_now();
        $slot = digest_due_slot($now);
        if (!$slot || $now < $slot['start']->modify('+20 minutes')) return;
        if (!digest_available($pdo) || !SmtpMailer::isConfigured($config)) return;

        $q = $pdo->prepare('SELECT MAX(complete) AS done, MAX(started_at >= NOW() - INTERVAL 10 MINUTE) AS busy
                              FROM digest_runs WHERE slot_key = ?');
        $q->execute([$slot['key']]);
        $r = $q->fetch() ?: [];
        if ((int)($r['done'] ?? 0) === 1 || (int)($r['busy'] ?? 0) === 1) return;

        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        ignore_user_abort(true);
        digest_run($pdo, 'web', ['budget' => 15]);
    } catch (Throwable $e) {
        error_log('[Anima digest] fallback: ' . $e->getMessage());
    }
}
