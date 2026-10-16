<?php
// My tasks — one person's day, across every show.
//
// It began as a list of tickets. It is now the screen an artist actually opens:
// their PRODUCTION tasks (a step against a shot or an asset — the rows that
// carry an assignee) as well as their tickets, with the status changed and the
// time logged in place. Nobody should have to open five screens to say "I did
// four hours on SH050 comp and it's ready for review".
//
// `users` are logins and `crew` are people; the link is users.crew_id, asked for
// once. Everything here hangs off that.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/timelog.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/crew.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$ready  = column_exists($pdo, 'tickets', 'id') && column_exists($pdo, 'users', 'crew_id');
$canLog = time_logs_available($pdo);
// Separate from $canLog on purpose: migration 043 can be missing while 022 is
// applied, and the page should then show exactly the Log box it showed
// yesterday rather than a column of buttons that cannot work.
$canTimer = $canLog && timers_available($pdo);

// --- claim a crew row -------------------------------------------------------
//
// ⚠ EDITORS AND ADMINS ONLY, and artist_may_post() leaves it out of the artist
// allow-list for the same reason: this accepts ANY crew id, so a self-link is a
// way to claim to be somebody else and then change their work. An admin links
// the account on the Users screen, which checks the choice against the crew list.
// The guard in require_login() already refuses the POST; this is the second lock,
// here so the reason sits next to the code it protects.
if ($ready && can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_crew'])) {
    try {
        if ($_POST['set_crew'] === 'new') {
            $me = current_user();
            [, $msg] = link_login_to_crew($pdo, $userId, (string)($me['display_name'] ?: $me['username']));
            flash('ok', $msg);
        } else {
            $crewId = (int)$_POST['set_crew'] ?: null;
            $pdo->prepare('UPDATE users SET crew_id = ? WHERE id = ?')->execute([$crewId, $userId]);
            flash('ok', $crewId
                ? 'Linked. This list now shows what is assigned to you.'
                : 'Unlinked from a crew member.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

// WHO MAY MOVE A STATUS ON THIS SCREEN. An editor may, and so may an artist —
// on their own rows, which is what every query below already enforces. Written
// once and read by each control, so the buttons and the guard in
// require_login() cannot disagree about who is allowed.
$canSetStatus = can_edit() || is_artist() || is_supervisor();

$me = current_user();
$myCrewId = 0;
if ($ready) {
    $mc = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
    $mc->execute([$userId]);
    $myCrewId = (int)$mc->fetchColumn();
}

// --- change the status of one production task -------------------------------
if ($myCrewId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    try {
        $progressId = (int)$_POST['progress_id'];
        $statusId   = !empty($_POST['status_id']) ? (int)$_POST['status_id'] : null;

        // The row must be one of THEIRS. Without this check the form's hidden id
        // is a way to edit anybody's task.
        $q = $pdo->prepare(
            'SELECT pr.*, ps.name AS step_name FROM progress pr
               JOIN pipeline_steps ps ON ps.id = pr.step_id
              WHERE pr.id = ? AND (pr.crew_id = ?
                    OR EXISTS (SELECT 1 FROM progress_crew pc
                                WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
        );
        $q->execute([$progressId, $myCrewId, $myCrewId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('That task is not assigned to you.');

        $map = statuses_by_id($pdo, (int)$row['project_id']);
        if ($statusId !== null && !isset($map[$statusId])) {
            throw new RuntimeException('That status is not part of that project.');
        }
        $status = $statusId ? $map[$statusId] : null;
        $flags  = status_flags($status);
        $oldId  = $row['status_id'] !== null ? (int)$row['status_id'] : null;

        $pdo->prepare(
            'UPDATE progress SET status_id = ?, is_delivered = ?, is_approved = ? WHERE id = ?'
        )->execute([$statusId, $flags['is_delivered'], $flags['is_approved'], $progressId]);

        log_status_change_by_id($pdo, (int)$row['project_id'],
            ['id' => (int)$row['step_id'], 'name' => $row['step_name']],
            (string)$row['item_key'], $oldId, $statusId, [
                'shot_id'    => $row['shot_id'],
                'asset_id'   => $row['asset_id'],
                'crew_id'    => $myCrewId,
                'crew_name'  => current_user_display(),
                'source'     => 'item',
                'changed_by' => current_user_display(),
            ]);

        flash('ok', $row['step_name'] . ' set to ' . ($status['name'] ?? 'nothing recorded') . '.');
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

// --- change the status of one TICKET ----------------------------------------
//
// Added 2026-09-28 with the artist role. The ticket screen's own `update` was
// not reusable: it rewrites the whole card — title, assignee, priority, due
// date — and an artist saying "this is ready for review" should not be able to
// hand the ticket to somebody else in the same request.
//
// The ownership test is the same shape as the production task above, and matters
// for the same reason: without it the form's hidden id is a way to move anybody's
// ticket.
if ($myCrewId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_ticket_status'])) {
    try {
        $ticketId = (int)$_POST['ticket_id'];
        $statusId = !empty($_POST['status_id']) ? (int)$_POST['status_id'] : null;

        // ⚠ LEFT, since migration 041: an INNER JOIN would have told the owner
        // of an unfiled card it "is not assigned to you", which is false.
        $q = $pdo->prepare(
            'SELECT t.*, p.code AS project_code FROM tickets t
               LEFT JOIN projects p ON p.id = t.project_id
              WHERE t.id = ? AND t.assigned_crew_id = ?'
        );
        $q->execute([$ticketId, $myCrewId]);
        $tk = $q->fetch();
        if (!$tk) throw new RuntimeException('That ticket is not assigned to you.');

        if ($tk['project_id'] === null) {
            throw new RuntimeException(
                'That card is not on a project yet, so there are no statuses to choose from. '
              . 'Open it and file it on a show first.'
            );
        }

        $map = statuses_by_id($pdo, (int)$tk['project_id']);
        if ($statusId !== null && !isset($map[$statusId])) {
            throw new RuntimeException('That status is not part of that project.');
        }
        $status = $statusId ? $map[$statusId] : null;
        $oldId  = $tk['status_id'] !== null ? (int)$tk['status_id'] : null;

        if ($oldId === $statusId) {
            flash('ok', 'That was already its status, nothing changed.');
            header('Location: mytasks.php');
            exit;
        }

        $pdo->prepare('UPDATE tickets SET status_id = ? WHERE id = ?')
            ->execute([$statusId, $ticketId]);
        // Closing a card is a property of the STATUS, not of a separate tick, so
        // the same helper the ticket screen uses keeps the two screens agreeing.
        sync_ticket_completion($pdo, $ticketId, $status);

        // Into the card's own thread, so its history reads the same whoever moved
        // it and from wherever.
        $was = $oldId && isset($map[$oldId]) ? $map[$oldId]['name'] : 'no status';
        $now = $status['name'] ?? 'no status';
        log_ticket_event($pdo, $ticketId, $userId, 'Status: ' . $was . ' → ' . $now);

        flash('ok', ticket_ref($tk) . ' set to ' . $now . '.');
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

// --- the running timer ------------------------------------------------------
//
// David, 2026-10-08: "add a pomodoro or track time option at the end of each
// task so the user can log time automatically."
//
// ⚠ THE SAME OWNERSHIP CHECK AS LOGGING, because starting a timer IS logging,
// only later. Without it anybody could time somebody else's card and drop hours
// into a show they have nothing to do with. The check is repeated rather than
// shared because the two handlers take different inputs; see below.
if ($myCrewId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timer'])) {
    try {
        $what = (string)$_POST['timer'];
        if ($what === 'stop') {
            flash('ok', stop_timer($pdo, $myCrewId, $userId));
        } elseif ($what === 'discard') {
            flash('ok', discard_timer($pdo, $myCrewId));
        } else {
            $progressId = !empty($_POST['progress_id']) ? (int)$_POST['progress_id'] : null;
            $ticketId   = !empty($_POST['ticket_id'])   ? (int)$_POST['ticket_id']   : null;

            // Yours, or nothing happens. Identical to the rule the log handler
            // below applies — a timer that could be started on another person's
            // work would write that person's project into your ledger on stop.
            if ($progressId) {
                $q = $pdo->prepare(
                    'SELECT 1 FROM progress pr WHERE pr.id = ? AND (pr.crew_id = ?
                        OR EXISTS (SELECT 1 FROM progress_crew pc
                                    WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
                );
                $q->execute([$progressId, $myCrewId, $myCrewId]);
                if (!$q->fetchColumn()) throw new RuntimeException('That task is not assigned to you.');
            } elseif ($ticketId) {
                $q = $pdo->prepare('SELECT 1 FROM tickets WHERE id = ? AND assigned_crew_id = ?');
                $q->execute([$ticketId, $myCrewId]);
                if (!$q->fetchColumn()) throw new RuntimeException('That ticket is not assigned to you.');
            }

            flash('ok', start_timer($pdo, [
                'crew_id'     => $myCrewId,
                'project_id'  => (int)($_POST['project_id'] ?? 0),
                'progress_id' => $progressId,
                'ticket_id'   => $ticketId,
                'entered_by'  => $userId,
            ]));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

// --- log time ---------------------------------------------------------------
if ($myCrewId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_time'])) {
    try {
        $progressId = !empty($_POST['progress_id']) ? (int)$_POST['progress_id'] : null;
        $ticketId   = !empty($_POST['ticket_id'])   ? (int)$_POST['ticket_id']   : null;
        $projectId  = (int)$_POST['project_id'];

        // Same rule as the status change: only against your own work.
        if ($progressId) {
            $q = $pdo->prepare(
                'SELECT 1 FROM progress pr WHERE pr.id = ? AND (pr.crew_id = ?
                    OR EXISTS (SELECT 1 FROM progress_crew pc
                                WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
            );
            $q->execute([$progressId, $myCrewId, $myCrewId]);
            if (!$q->fetchColumn()) throw new RuntimeException('That task is not assigned to you.');
        } elseif ($ticketId) {
            $q = $pdo->prepare('SELECT 1 FROM tickets WHERE id = ? AND assigned_crew_id = ?');
            $q->execute([$ticketId, $myCrewId]);
            if (!$q->fetchColumn()) throw new RuntimeException('That ticket is not assigned to you.');
        }

        flash('ok', log_time($pdo, [
            'project_id'  => $projectId,
            'progress_id' => $progressId,
            'ticket_id'   => $ticketId,
            'crew_id'     => $myCrewId,
            'hours'       => $_POST['hours'] ?? '',
            'log_date'    => $_POST['log_date'] ?? '',
            'notes'       => $_POST['notes'] ?? '',
            'entered_by'  => $userId,
        ]));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

if ($myCrewId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    try {
        flash('ok', delete_time_log($pdo, (int)$_POST['delete_log'], $userId, is_admin()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

// --- load -------------------------------------------------------------------
$tickets = []; $tasks = []; $candidates = []; $entries = []; $statusesByProject = [];
$mentions = []; $unseenMentions = 0;
$myCrewName = '';

if ($ready && $myCrewId) {
    $n = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
    $n->execute([$myCrewId]);
    $myCrewName = (string)$n->fetchColumn();

    $tickets = tickets_for_crew($pdo, $myCrewId);

    // Production tasks: unfinished steps assigned to them, on active projects.
    // Both the primary assignee and the join table, because real work has two
    // names on it and dropping the second is what progress_crew exists to stop.
    //
    // ⚠ 'postmortem' (migration 044) IS DELIBERATELY NOT ADDED HERE, decided
    // 2026-10-08. It is tempting — a show in postmortem may still have cards to
    // close — but the lifecycle makes it wrong: postmortem comes strictly AFTER
    // 'wrapped', and 'wrapped' already drops a show's production tasks off this
    // screen. Adding postmortem would make tasks that vanished at wrap-up
    // REAPPEAR a step later, which is stranger and harder to trust than them
    // staying gone. If unfinished work is the reason a show cannot be called
    // done, the honest move is to leave it 'active'.
    //
    // ⚠ And the cards are a separate thing: tickets_for_crew() above is NOT
    // filtered by project status at all, so a ticket raised out of a postmortem
    // meeting still lands on the assignee's My tasks. Only the production steps
    // are gated by the JOIN below.
    $t = $pdo->prepare(
        "SELECT pr.id AS progress_id, pr.project_id, pr.step_id, pr.item_key,
                pr.status_id, pr.time_spent_minutes,
                p.name AS project_name, p.code AS project_code,
                ps.name AS step_name, ps.color AS step_color, ps.deadline,
                s.episode, s.shot_code, a.name AS asset_name,
                st.name AS status_name, st.color AS status_color, st.is_blocked,
                sc.end_date
           FROM progress pr
           JOIN projects p ON p.id = pr.project_id AND p.status IN ('active','setup')
           JOIN pipeline_steps ps ON ps.id = pr.step_id
           LEFT JOIN shots  s ON s.id = pr.shot_id
           LEFT JOIN assets a ON a.id = pr.asset_id
           LEFT JOIN statuses st ON st.id = pr.status_id
           LEFT JOIN schedule sc ON sc.step_id = pr.step_id AND sc.item_key = pr.item_key
          WHERE (pr.crew_id = ?
                 OR EXISTS (SELECT 1 FROM progress_crew pc
                             WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
            AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
            AND COALESCE(st.is_excluded, 0) = 0
          ORDER BY sc.end_date IS NULL, sc.end_date, p.code, ps.step_order"
    );
    $t->execute([$myCrewId, $myCrewId]);
    $tasks = $t->fetchAll();

    // ⚠ TICKETS TOO, since 2026-09-28. This loop covered the production tasks
    // only, which was invisible while a ticket's status was a read-only chip:
    // give a ticket a picker and one on a project with no tasks assigned draws
    // an empty list.
    foreach (array_merge($tasks, $tickets) as $row) {
        $pid = (int)$row['project_id'];
        // 0 is an unfiled card (migration 041). There are no statuses to load for
        // it, and asking would be one query per such card for an empty answer.
        if ($pid && !isset($statusesByProject[$pid])) {
            $statusesByProject[$pid] = statuses_by_id($pdo, $pid);
        }
    }

    $logged = logged_by_progress($pdo, array_map(fn($r) => (int)$r['progress_id'], $tasks));
    foreach ($tasks as $i => $row) $tasks[$i]['logged'] = $logged[(int)$row['progress_id']] ?? 0;

    $entries = time_logs_for_crew($pdo, $myCrewId, null, 15);

    // Who has named me, newest first, unread at the top. Capped: this is a
    // prompt to go and look, not an archive.
    if (ticket_mentions_available($pdo) && column_exists($pdo, 'ticket_mentions', 'seen_at')) {
        $mq = $pdo->prepare(
            'SELECT m.comment_id, m.seen_at, c.body, c.created_at, c.ticket_id,
                    t.seq, t.title, t.type, p.code AS project_code,
                    u.display_name AS said_by
               FROM ticket_mentions m
               JOIN ticket_comments c ON c.id = m.comment_id
               JOIN tickets t         ON t.id = c.ticket_id
               -- ⚠ LEFT, since migration 041. An INNER JOIN here would drop a
               -- mention on an unfiled card: named, notified by email, and then
               -- nothing on the screen the email points at.
               LEFT JOIN projects p   ON p.id = t.project_id
               LEFT JOIN users u      ON u.id = c.user_id
              WHERE m.crew_id = ? AND t.archived = 0
              ORDER BY m.seen_at IS NULL DESC, c.created_at DESC
              LIMIT 15'
        );
        $mq->execute([$myCrewId]);
        $mentions = $mq->fetchAll();
        foreach ($mentions as $m) if ($m['seen_at'] === null) $unseenMentions++;
    }
} elseif ($ready) {
    $candidates = $pdo->query(
        'SELECT id, name, role FROM crew
          WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
          ORDER BY name'
    )->fetchAll();
}

$today = date('Y-m-d');
// Read once for the whole page. Every row asks "is it me?" against this, so a
// query per row would be forty round trips to answer one question.
$timer = $canTimer ? running_timer($pdo, $myCrewId) : null;

/**
 * The Timer cell, drawn the same way in both tables below.
 *
 * ⚠ THE STOP BUTTON ONLY EXISTS ON THE ROW BEING TIMED. A stop control on
 * every row would invite somebody to stop a timer from the wrong line and watch
 * half an hour land on a card they were not working on — and they would have no
 * way of knowing, because the hours would simply appear somewhere else.
 *
 * Every other row keeps its Start button rather than being disabled while a
 * timer runs. start_timer() stops and logs the running one first and SAYS so, so
 * switching tasks is one click; disabling them would make people leave the old
 * timer going, which is the one outcome that actually corrupts the figures.
 *
 * A card with no project gets a dash, matching the Log cell beside it: hours are
 * costed to a show, so there is nothing to time against yet.
 */
function timer_cell(?array $timer, string $kind, int $id, int $projectId): string {
    if ($projectId <= 0) {
        return '<span class="muted small" title="Hours are costed to a show, '
             . 'so the card needs one first">—</span>';
    }
    $field  = $kind === 'ticket' ? 'ticket_id' : 'progress_id';
    $mine   = $timer && (int)($timer[$field] ?? 0) === $id;
    $off    = can_edit() ? '' : ' disabled';

    if ($mine) {
        // data-since carries the seconds already elapsed, counted by MySQL at
        // page load; the script at the foot of the page ticks it from there.
        // Rendering the final string alone would freeze the moment the page did.
        // ⚠ BOTH BUTTONS CARRY name="timer", and neither value is in a hidden
        // field. The first version put <input type="hidden" name="timer" value="stop">
        // in the form AND name="timer" value="discard" on the drop button, which
        // works only because PHP keeps the LAST of two same-named fields — so
        // "drop" throwing the timer away rather than logging it depended on the
        // order the markup happened to be written in. On a button that decides
        // whether somebody's hours are kept, that is not a thing to leave to
        // chance.
        return '<form method="post" class="inline-form timer-form" style="margin:0;">'
             . '<button type="submit" name="timer" value="stop" class="timer-btn running"' . $off
             . ' title="Stop and log what this timer measured">■ Stop '
             . '<span class="timer-elapsed" data-since="' . (int)$timer['elapsed_seconds'] . '">'
             . e(fmt_elapsed((int)$timer['elapsed_seconds'])) . '</span></button>'
             . '<button type="submit" name="timer" value="discard" class="timer-drop"' . $off
             . ' title="Throw this timer away without logging it">drop</button>'
             . '</form>';
    }

    $note = $timer ? ' This stops and logs the one already running.' : '';
    return '<form method="post" class="inline-form timer-form" style="margin:0;">'
         . '<input type="hidden" name="timer" value="start">'
         . '<input type="hidden" name="' . $field . '" value="' . $id . '">'
         . '<input type="hidden" name="project_id" value="' . $projectId . '">'
         . '<button type="submit" class="timer-btn"' . $off
         . ' title="Start timing this, and log the minutes when you stop.' . e($note) . '">'
         . '▶ Start</button></form>';
}
$weekAgo = date('Y-m-d', strtotime('-6 days'));
$loggedWeek = $myCrewId ? logged_total_for_crew($pdo, $myCrewId, null, $weekAgo, $today) : 0;
$loggedToday = $myCrewId ? logged_total_for_crew($pdo, $myCrewId, null, $today, $today) : 0;

$overdue = 0;
foreach ($tasks as $t2) if (!empty($t2['end_date']) && $t2['end_date'] < $today) $overdue++;
foreach ($tickets as $t2) if (!empty($t2['due_date']) && $t2['due_date'] < $today) $overdue++;

$pageTitle = 'My tasks';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← All projects</a></p>
<h2 class="wizard-title">My tasks</h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>This screen needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>017_tickets.sql</code> creates the tasks tables and the link
       between a login and a person.</p>
    <?php if (is_admin()): ?><p><a class="button" href="migrate.php">Review and apply it →</a></p><?php endif; ?>
  </div>

<?php elseif (!$myCrewId && !can_edit()): ?>
  <?php // ⚠ NOT A FORM FOR AN ARTIST. The claim below accepts any crew id, so
        // offering it here would be offering a way to be somebody else. An admin
        // makes the link on the Users screen, which checks the choice. Saying who
        // to ask beats a control that would be refused. ?>
  <div class="empty-state">
    <p><strong>This account is not linked to anyone in the crew yet.</strong></p>
    <p class="muted">Work is assigned to crew members and logins are separate from people, so
       until the two are joined up there is nothing to show here. An administrator does it on
       the Users screen — ask for <strong><?= e($me['display_name'] ?: $me['username']) ?></strong>
       to be linked to your crew record.</p>
  </div>

<?php elseif (!$myCrewId): ?>
  <p class="lead">Work is assigned to <strong>crew members</strong>, and logins are separate from
     people, so this screen needs to know which of the crew you are. It is asked once.</p>

  <?php if (!$candidates): ?>
    <div class="empty-state">
      <p><strong>Nobody is in the crew list yet, but you can add yourself.</strong></p>
      <form method="post">
        <button type="submit" name="set_crew" value="new">
          Add <?= e($me['display_name'] ?: $me['username']) ?> to the crew
        </button>
      </form>
    </div>
  <?php else: ?>
    <?php $guess = 0;
      foreach ($candidates as $c) {
          if (strcasecmp(trim($c['name']), trim((string)($me['display_name'] ?? ''))) === 0) {
              $guess = (int)$c['id']; break;
          }
      } ?>
    <form method="post" class="inline-form">
      <label>Which of the crew are you?
        <select name="set_crew" required>
          <option value="">choose</option>
          <?php foreach ($candidates as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $guess === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['role'] ? ' · ' . e($c['role']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Save</button>
    </form>
    <?php if (!$guess): ?>
      <p class="muted small">Not in that list? Add yourself:</p>
      <form method="post">
        <button type="submit" name="set_crew" value="new" class="secondary">
          Add <?= e($me['display_name'] ?: $me['username']) ?> to the crew
        </button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>
  <p class="lead">
    Everything assigned to <strong><?= e($myCrewName) ?></strong>, across every project.
    <?php if ($overdue): ?><span class="err"><?= $overdue ?> past its date.</span><?php endif; ?>
  </p>

  <div class="dash-strip">
    <div><span class="muted small">Production tasks</span><strong><?= count($tasks) ?></strong></div>
    <div><span class="muted small">Tickets</span><strong><?= count($tickets) ?></strong></div>
    <?php if ($unseenMentions): ?>
      <div><span class="muted small">New mentions</span><strong class="warn-text"><?= $unseenMentions ?></strong></div>
    <?php endif; ?>
    <?php if ($canLog): ?>
      <div><span class="muted small">Logged today</span><strong><?= e(fmt_hours($loggedToday)) ?></strong></div>
      <div><span class="muted small">Logged last 7 days</span><strong><?= e(fmt_hours($loggedWeek)) ?></strong></div>
    <?php endif; ?>
  </div>

  <?php if (!$canLog): ?>
    <p class="flash flash-warn">
      Time logging needs migration <code>022</code>, which hasn't been applied yet. Everything
      else on this page works.
      <?php if (is_admin()): ?><a href="migrate.php">Review and apply it →</a><?php endif; ?>
    </p>
  <?php endif; ?>

  <h3>Production tasks <span class="muted">· <?= count($tasks) ?></span></h3>
  <?php if (!$tasks): ?>
    <p class="muted">Nothing unfinished is assigned to you. Tasks appear here when somebody is
       named on a step, from a Plaky import, the tracking grid or an item page.</p>
  <?php else: ?>
    <table class="mywork-table">
      <thead>
        <tr><th>What</th><th>Project</th><th>Status</th><th>Due</th>
            <?php if ($canLog): ?><th>Logged</th><th>Log time</th><?php endif; ?>
            <?php if ($canTimer): ?><th>Timer</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($tasks as $row):
        $item = shot_label($row['episode'] ?? '', $row['shot_code'] ?? '');
        if ($item === '') $item = (string)($row['asset_name'] ?? 'Whole show');
        $late = !empty($row['end_date']) && $row['end_date'] < $today;
        $pid  = (int)$row['project_id'];
      ?>
        <tr class="<?= $late ? 'row-overdue' : '' ?>">
          <td>
            <a href="item.php?project=<?= $pid ?>&amp;item=<?= e(urlencode($row['item_key'])) ?>"><?= e($item) ?></a>
            <small class="muted"><span class="dot" style="background:<?= e($row['step_color'] ?: '#ccc') ?>"></span><?= e($row['step_name']) ?></small>
          </td>
          <td class="muted small"><?= e($row['project_code']) ?></td>
          <td>
            <?php // A dropdown of the project's own statuses rather than two fixed
                  // buttons: status names are per-project DATA, so hardcoding
                  // "Pending review" would break on a show that calls it something
                  // else — the mistake the whole statuses table exists to avoid. ?>
            <form method="post" class="inline-form" style="margin:0;">
              <input type="hidden" name="set_status" value="1">
              <input type="hidden" name="progress_id" value="<?= (int)$row['progress_id'] ?>">
              <select name="status_id" onchange="this.form.submit()" <?= $canSetStatus ? '' : 'disabled' ?>>
                <option value="">not started</option>
                <?php foreach (($statusesByProject[$pid] ?? []) as $sid => $s): ?>
                  <?php if ((int)($s['is_excluded'] ?? 0) === 1) continue; ?>
                  <option value="<?= (int)$sid ?>" <?= (int)$row['status_id'] === (int)$sid ? 'selected' : '' ?>>
                    <?= e($s['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php if ((int)($row['is_blocked'] ?? 0) === 1): ?><small class="warn-text">blocked</small><?php endif; ?>
          </td>
          <td class="muted small <?= $late ? 'err' : '' ?>"><?= e($row['end_date'] ?: '—') ?></td>
          <?php if ($canLog): ?>
            <td class="num muted">
              <?= $row['logged'] ? e(fmt_hours((int)$row['logged'])) : '—' ?>
              <?php if (!$row['logged'] && $row['time_spent_minutes']): ?>
                <small class="muted" title="Imported from Plaky, not logged here"><?= e(fmt_hours((int)$row['time_spent_minutes'])) ?> imported</small>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="log-form">
                <input type="hidden" name="log_time" value="1">
                <input type="hidden" name="progress_id" value="<?= (int)$row['progress_id'] ?>">
                <input type="hidden" name="project_id" value="<?= $pid ?>">
                <input name="hours" placeholder="1.5" class="hrs-input" inputmode="decimal"
                       title="Hours. 90m works too." <?= can_edit() ? '' : 'disabled' ?>>
                <input type="date" name="log_date" value="<?= $today ?>" max="<?= $today ?>"
                       class="day-input" <?= can_edit() ? '' : 'disabled' ?>>
                <button type="submit" class="secondary" <?= can_edit() ? '' : 'disabled' ?>>Log</button>
              </form>
            </td>
          <?php endif; ?>
          <?php if ($canTimer): ?>
            <td><?= timer_cell($timer, 'progress', (int)$row['progress_id'], $pid) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php // MENTIONED, before the lists, because it is the only thing on this page
        // somebody else put there — the rest is work already assigned. Unread
        // first and drawn only when there is something to read; a permanent
        // "0 mentions" heading is furniture. ?>
  <?php if ($mentions): ?>
    <h3>Mentioned <span class="muted">· <?= count($mentions) ?><?php
      if ($unseenMentions): ?>, <strong class="warn-text"><?= $unseenMentions ?> new</strong><?php endif; ?></span></h3>
    <table class="mywork-table">
      <thead><tr><th>Ref</th><th>Ticket</th><th>Project</th><th>Said by</th><th>What</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($mentions as $m): ?>
        <tr class="<?= $m['seen_at'] === null ? 'mention-new' : '' ?>">
          <td><a href="ticket.php?id=<?= (int)$m['ticket_id'] ?>#c<?= (int)$m['comment_id'] ?>"
                 class="<?= $m['type'] === 'ticket' ? 'ref-ticket' : 'ref-task' ?>"><?= e(ticket_ref($m)) ?></a></td>
          <td><a href="ticket.php?id=<?= (int)$m['ticket_id'] ?>#c<?= (int)$m['comment_id'] ?>"><?= e($m['title']) ?></a></td>
          <td class="muted small"><?= e($m['project_code']) ?></td>
          <td class="muted small"><?= e($m['said_by'] ?: 'Someone') ?></td>
          <td class="muted small"><?= e(mb_substr(trim((string)$m['body']), 0, 90))
                ?><?= mb_strlen(trim((string)$m['body'])) > 90 ? '…' : '' ?></td>
          <td class="muted small"><?= e(date('M j', strtotime($m['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3>Tickets <span class="muted">· <?= count($tickets) ?></span></h3>
  <?php if (!$tickets): ?>
    <p class="muted">No open tickets are assigned to you.</p>
  <?php else: ?>
    <table class="mywork-table">
      <thead>
        <tr><th>Ref</th><th>Title</th><th>Project</th><th>Status</th><th>Priority</th><th>Due</th>
            <?php if ($canLog): ?><th>Log time</th><?php endif; ?>
            <?php if ($canTimer): ?><th>Timer</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t3):
        $late = $t3['due_date'] && $t3['due_date'] < $today;
        $c = $t3['status_color'] ?: '#8a97a3';
      ?>
        <tr class="<?= $late ? 'row-overdue' : '' ?>">
          <td><a href="ticket.php?id=<?= (int)$t3['id'] ?>" class="<?= $t3['type'] === 'ticket' ? 'ref-ticket' : 'ref-task' ?>"><?= e(ticket_ref($t3)) ?></a></td>
          <td><a href="ticket.php?id=<?= (int)$t3['id'] ?>"><?= e($t3['title']) ?></a></td>
          <td class="muted small"><?= ($t3['project_code'] ?? '') !== ''
              ? e($t3['project_code']) : '<span class="warn-text">no project</span>' ?></td>
          <?php // The same control the production tasks above carry, for the same
                // reason: this is the screen an artist opens to say where their
                // work has got to, and a ticket is half of what is on it. Still
                // a coloured chip when it cannot be changed — a disabled select
                // reads as broken, not as read-only. ?>
          <td>
            <?php // ⚠ NO PICKER WITHOUT A PROJECT. Statuses are a show's own, so an
                  // unfiled card has none to offer — an empty dropdown would read as
                  // broken. Said in words, with the way out. ?>
            <?php if (!$t3['project_id']): ?>
              <a class="muted small" href="ticket.php?id=<?= (int)$t3['id'] ?>"
                 title="Open the card and file it on a show">no project yet</a>
            <?php elseif ($canSetStatus): ?>
              <form method="post" class="inline-form" style="margin:0;">
                <input type="hidden" name="set_ticket_status" value="1">
                <input type="hidden" name="ticket_id" value="<?= (int)$t3['id'] ?>">
                <select name="status_id" onchange="this.form.submit()"
                        style="border-color:<?= e($c) ?>44;color:<?= e($c) ?>;">
                  <option value="">no status</option>
                  <?php foreach (($statusesByProject[(int)$t3['project_id']] ?? []) as $sid => $st): ?>
                    <?php if ((int)($st['is_excluded'] ?? 0) === 1) continue; ?>
                    <option value="<?= (int)$sid ?>" <?= (int)$t3['status_id'] === (int)$sid ? 'selected' : '' ?>>
                      <?= e($st['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <span class="status-chip" style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
                <?= e($t3['status_name'] ?? 'no status') ?></span>
            <?php endif; ?>
          </td>
          <td><span class="prio-dot priority-<?= e($t3['priority']) ?>"></span> <?= ucfirst($t3['priority']) ?></td>
          <td class="muted small <?= $late ? 'err' : '' ?>"><?= e($t3['due_date'] ?: '—') ?></td>
          <?php if ($canLog): ?>
            <td>
              <?php // ⚠ NO TIME AGAINST AN UNFILED CARD. Hours are costed to a show
                    // and read by Finance; a row with project_id 0 would be hours
                    // belonging to nothing, quietly missing from every total. ?>
              <?php if (!$t3['project_id']): ?>
                <span class="muted small" title="Hours are costed to a show, so the card needs one first">—</span>
              <?php else: ?>
              <form method="post" class="log-form">
                <input type="hidden" name="log_time" value="1">
                <input type="hidden" name="ticket_id" value="<?= (int)$t3['id'] ?>">
                <input type="hidden" name="project_id" value="<?= (int)$t3['project_id'] ?>">
                <input name="hours" placeholder="1.5" class="hrs-input" inputmode="decimal" <?= can_edit() ? '' : 'disabled' ?>>
                <input type="date" name="log_date" value="<?= $today ?>" max="<?= $today ?>"
                       class="day-input" <?= can_edit() ? '' : 'disabled' ?>>
                <button type="submit" class="secondary" <?= can_edit() ? '' : 'disabled' ?>>Log</button>
              </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <?php if ($canTimer): ?>
            <td><?= timer_cell($timer, 'ticket', (int)$t3['id'], (int)$t3['project_id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($canLog && $entries): ?>
    <h3>Recently logged</h3>
    <table class="review-table">
      <thead><tr><th>Day</th><th>Against</th><th class="num">Hours</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $en): ?>
        <tr>
          <td class="muted small"><?= e(date('D j M', strtotime($en['log_date']))) ?></td>
          <td><?= e(time_log_target($en)) ?></td>
          <td class="num"><?= e(fmt_hours((int)$en['minutes'])) ?></td>
          <td class="muted small"><?= e($en['notes'] ?: '') ?></td>
          <td class="row-actions">
            <?php if (can_edit() && (is_admin() || (int)$en['entered_by'] === $userId)): ?>
              <form method="post" onsubmit="return confirm('Remove this entry?');">
                <button type="submit" name="delete_log" value="<?= (int)$en['id'] ?>" class="link-danger">remove</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">
      Hours are what you type; minutes are what is stored, so nothing drifts when a column of
      these is summed. <strong>The date is the day the work happened</strong>, not the day you
      typed it, logging Friday on Monday is normal and the default is simply today.
      <em>Imported</em> next to a figure means it came from Plaky as one running total rather
      than being logged here.
    </p>
  <?php endif; ?>

  <form method="post" class="inline-form" style="margin-top:22px;">
    <input type="hidden" name="set_crew" value="0">
    <small>Showing work for <strong><?= e($myCrewName) ?></strong>.</small>
    <button type="submit" class="secondary">That isn't me, unlink</button>
  </form>
<?php endif; ?>

</div>
<?php // SORTABLE HEADINGS (David, 2026-10-05: "sort by the header in this
      // section"). Sort only — no filter boxes and no count row: these two lists
      // are one person's own work, short enough to read, and the heading was the
      // whole request. assets/gridtools.js is the same machinery the Artists and
      // Shots grids use, so clicking a heading behaves identically everywhere. ?>
<script src="assets/gridtools.js<?= asset_ver('gridtools.js') ?>"></script>
<script>
  // The running timer's readout, ticked once a second.
  //
  // ⚠ COUNTED FROM THE SERVER'S OWN ELAPSED SECONDS, not from a start time
  // compared against the browser's clock. data-since is what MySQL measured when
  // the page was built, so a laptop whose clock is twenty minutes out still
  // shows the right figure — and the number on screen can never disagree with
  // the number that gets logged.
  //
  // Cosmetic only. If this script never runs, the cell still shows the elapsed
  // time as it was at page load and Stop still logs the correct minutes, because
  // the real measurement happens in MySQL on stop.
  (function () {
    var spans = document.querySelectorAll('.timer-elapsed[data-since]');
    if (!spans.length) return;

    var base = [];
    for (var i = 0; i < spans.length; i++) base.push(parseInt(spans[i].getAttribute('data-since'), 10) || 0);
    // Measured as a DURATION since the page loaded rather than by incrementing a
    // counter: a background tab throttles setInterval to once a minute or less,
    // and a counter would simply lose that time and show the wrong total on the
    // way back.
    var t0 = Date.now();

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmt(s) {
      var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
      return h > 0 ? h + ':' + pad(m) + ':' + pad(s % 60) : m + ':' + pad(s % 60);
    }
    function tick() {
      var on = Math.floor((Date.now() - t0) / 1000);
      for (var i = 0; i < spans.length; i++) spans[i].textContent = fmt(base[i] + on);
    }
    tick();
    setInterval(tick, 1000);
  }());

  (function () {
    if (!window.GridTools) return;
    // ⚠ PRIORITY SORTS BY URGENCY, not by the alphabet, which would read
    // High, Low, Medium, Urgent. The order is ticket_priorities()' own.
    var RANK = { urgent: 0, high: 1, medium: 2, low: 3 };
    function sortKey(td, text) {
      var dot = td ? td.querySelector('.prio-dot') : null;
      if (dot) {
        var m = /priority-(\w+)/.exec(dot.className || '');
        var r = m ? RANK[m[1]] : undefined;
        if (r !== undefined) return [0, r];
      }
      return window.GridTools.sortKey(td, text);
    }
    Array.prototype.forEach.call(document.querySelectorAll('.mywork-table'), function (t) {
      window.GridTools.attach(t, {
        sortOnly: true,
        sortKey: sortKey,
        // Nothing to sort a form by.
        skip: function (th) {
          var t = th.textContent.trim();
          return t === 'Log time' || t === 'Timer';
        }
      });
    });
  }());
</script>
</body></html>
