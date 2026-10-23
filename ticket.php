<?php
// One ticket or task, in full: the thread, its attachments, and the fields.
//
// Ported from STFC's ticket.php. Two things are different beyond the usual four:
//
//   * The Activity section is honestly named. On STFC it holds only what people
//     typed, so "who moved this to Done, and when" was nowhere — the one thing
//     anybody asks about a card that changed behind their back. Status, owner,
//     priority and due-date changes are written into the SAME thread as system
//     lines (ticket_comments.kind), so the card reads as one story.
//   * A card can name a pipeline STEP as well as a shot or an asset.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/tickets.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!column_exists($pdo, 'tickets', 'id')) {
    flash('warn', 'The tasks feature needs migration 017_tickets.sql, which has not been applied yet.');
    header('Location: index.php');
    exit;
}

$t = $id ? load_ticket($pdo, $id) : null;
if (!$t) {
    flash('err', 'That card no longer exists.');
    header('Location: index.php');
    exit;
}

// ⚠ A CARD MAY HAVE NO PROJECT (migration 041). Everything below that is
// scoped to one — the statuses, the board columns, the shot and asset pickers,
// the nav bar — is therefore conditional. The card still opens, still takes a
// title and a description, and carries one control that matters: move it to a
// show.
$projectId = (int)($t['project_id'] ?? 0);
$project = null;
if ($projectId > 0) {
    $pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $pstmt->execute([$projectId]);
    $project = $pstmt->fetch() ?: null;
}
if (!$project) $projectId = 0;

$selfUrl  = 'ticket.php?id=' . $id;
$boardUrl = $projectId ? 'tasks.php?project=' . $projectId : 'alltasks.php';

// Where "← Back" goes. When the card is the viewer's OWN (the owner, i.e. its
// assigned_crew_id matches their users.crew_id — the same test My tasks uses),
// send them back to My tasks, which is where they opened it from. Everyone
// else, and every card that is not theirs, keeps going back to the board the
// card sits on (or Studio Tasks when it has no show). An unfiled card of their
// own has no board, so My tasks is the right place for it too.
$myCrewId = 0;
if ($userId) {
    $mc = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
    $mc->execute([$userId]);
    $myCrewId = (int)$mc->fetchColumn();
}
$isMine    = $myCrewId > 0 && (int)($t['assigned_crew_id'] ?? 0) === $myCrewId;
$backUrl   = $isMine ? 'mytasks.php' : $boardUrl;
$backLabel = $isMine ? '← Back to my tasks' : '← Back to the board';

$isTicket = ($t['type'] ?? 'task') === 'ticket';
$label    = ticket_ref($t);
$canDeleteCard = is_admin() || (int)$t['created_by'] === $userId;

$statusMap = $projectId ? statuses_by_id($pdo, $projectId) : [];
[$columns] = $projectId ? ticket_board_columns($pdo, $projectId) : [[], false];
// Studio-wide when the card has no show, so it can still be handed to somebody
// before anybody has decided which project it belongs to.
$assignees = $projectId
    ? ticket_assignees($pdo, $projectId)
    : $pdo->query('SELECT id, name, role, email, is_team, is_placeholder FROM crew
                    WHERE active = 1 ORDER BY name')->fetchAll();

// --- post a comment ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $body = trim($_POST['body'] ?? '');
    $hasFiles = !empty($_FILES['attachments']['name'][0] ?? '');

    if ($body === '' && !$hasFiles) {
        flash('warn', 'Nothing to post, the comment was empty and no file was attached.');
        finish_post($selfUrl);
    }

    // A reply carries the comment it answers. Verified to belong to THIS ticket:
    // without that check a posted id is a way to hang a reply on another show's
    // conversation.
    $parentId = null;
    if (ticket_threads_available($pdo) && !empty($_POST['parent_id'])) {
        $q = $pdo->prepare('SELECT id FROM ticket_comments WHERE id = ? AND ticket_id = ?');
        $q->execute([(int)$_POST['parent_id'], $id]);
        $parentId = $q->fetchColumn() ?: null;
    }

    $cols = 'ticket_id, user_id, kind, body';
    $marks = '?, ?, \'user\', ?';
    $args = [$id, $userId ?: null, $body !== '' ? $body : '(attachment)'];
    if (ticket_threads_available($pdo)) { $cols .= ', parent_id'; $marks .= ', ?'; $args[] = $parentId; }
    $pdo->prepare('INSERT INTO ticket_comments (' . $cols . ') VALUES (' . $marks . ')')
        ->execute($args);
    $commentId = (int)$pdo->lastInsertId();

    // Who was named, resolved against this project's people and stored as ids.
    $mentions = ticket_parse_mentions($body, $assignees);
    ticket_save_mentions($pdo, $commentId, $mentions);

    $notes = [$parentId ? 'Reply posted.' : 'Comment posted.'];
    if ($mentions) {
        // ⚠ ALWAYS, since David asked for it on 2026-10-06: "make the email the
        // people not an option but something already built in by default". It
        // was a tick for one day, unticked by default, because this mailer opens
        // SMTP INLINE and the page waits for the mail server before it returns.
        // That cost has not gone away — it has been accepted. Naming somebody now
        // means telling them, which is what everyone expects an @ to do, and the
        // pause is the price.
        //
        // The fix for the pause is to move the mailer off the save path, which
        // would also speed up creating a ticket and changing a status. It is on
        // the roadmap and this is now its third caller.
        //
        // notify_mentioned() says who it reached AND who it could not: a mention
        // that silently goes nowhere is worse than none, because the author has
        // already moved on believing it landed.
        [, $said] = notify_mentioned($pdo, $id, array_keys($mentions), $body, current_user_display());
        if ($said !== '') $notes[] = $said;
    }
    if ($hasFiles) {
        $res = save_ticket_attachments($pdo, normalize_files_array($_FILES['attachments']), $id, $commentId, $userId);
        if ($res['saved'])  $notes[] = $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $notes[] = implode(' ', $res['errors']);
    }
    flash('ok', implode(' ', $notes));
    finish_post($selfUrl);
}

// --- move the card to another show ------------------------------------------
//
// ⚠ ITS OWN ACTION, not a field on the Save form. Moving renumbers the card,
// remaps its status and clears the shot, asset and step — four changes with
// consequences, where everything else on that form is one field. Folded into
// Save it would happen as a side effect of pressing a button somebody pressed to
// fix a typo. A separate control, with its own confirmation, is the honest shape.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_project'])) {
    $to = (int)($_POST['to_project'] ?? 0);
    if ($to <= 0) {
        flash('err', 'No project chosen, so nothing moved.');
        header('Location: ' . $selfUrl);
        exit;
    }
    $res = move_ticket_to_project($pdo, $id, $to);
    flash($res['ok'] ? 'ok' : 'err', $res['said']);
    if ($res['ok']) {
        // Into the card's own thread, because the reference changing is the kind
        // of thing somebody reads this history to understand.
        log_ticket_event($pdo, $id, $userId, $res['said']);
    }
    header('Location: ' . $selfUrl);
    exit;
}

// --- snooze / un-snooze -----------------------------------------------------
// A wake-up date (migration 054). Production + admins only, its own button so it
// never rides along with a field save. Un-snooze clears it. The card stays
// visible on its own detail page so this control is always reachable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['snooze']) && can_edit()) {
    $date = trim($_POST['snoozed_until'] ?? '');
    $clear = ($_POST['snooze'] === 'clear') || $date === '';
    if (!$clear && $date <= date('Y-m-d')) {
        flash('err', 'Pick a date in the future to snooze to, nothing changed.');
        header('Location: ' . $selfUrl);
        exit;
    }
    $said = snooze_ticket($pdo, $id, $clear ? null : $date, $userId);
    flash('ok', $said);
    header('Location: ' . $selfUrl);
    exit;
}

// --- save the fields --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $aboutPair = ticket_about_from_post($_POST['step_id'] ?? '');
    $newStatus = !empty($_POST['status_id']) ? (int)$_POST['status_id'] : null;
    if ($newStatus !== null && !isset($statusMap[$newStatus])) $newStatus = null;

    $newCrew = !empty($_POST['assigned_crew_id']) ? (int)$_POST['assigned_crew_id'] : null;

    // A ticket always needs an owner — refuse to strip the assignee off one
    // rather than accepting the save and quietly leaving it unowned.
    if ($isTicket && !$newCrew) {
        flash('err', 'A ticket has to stay assigned to somebody, nothing was saved.');
        header('Location: ' . $selfUrl);
        exit;
    }

    $newTitle = trim($_POST['title'] ?? '');
    if ($newTitle === '') {
        flash('err', 'The title cannot be empty, nothing was saved.');
        header('Location: ' . $selfUrl);
        exit;
    }

    $typedShot  = trim($_POST['shot_label'] ?? '');
    $typedAsset = trim($_POST['asset_label'] ?? '');
    $after = [
        'title'            => $newTitle,
        'description'      => trim($_POST['description'] ?? '') ?: null,
        'status_id'        => $newStatus,
        'priority'         => in_array($_POST['priority'] ?? '', ticket_priorities(), true) ? $_POST['priority'] : 'medium',
        'assigned_crew_id' => $newCrew,
        'due_date'         => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
        'shot_id'          => resolve_shot_id($pdo, $projectId, $typedShot),
        'asset_id'         => resolve_asset_id($pdo, $projectId, $typedAsset),
        'step_id'          => $aboutPair['step_id'],
    ];

    $pdo->prepare(
        'UPDATE tickets
            SET title = ?, description = ?, status_id = ?, priority = ?,
                assigned_crew_id = ?, due_date = ?, shot_id = ?, asset_id = ?, step_id = ?'
          . (ticket_about_available($pdo) ? ', about = ?' : '') . '
          WHERE id = ?'
    )->execute(array_merge([
        $after['title'], $after['description'], $after['status_id'], $after['priority'],
        $after['assigned_crew_id'], $after['due_date'], $after['shot_id'],
        $after['asset_id'], $after['step_id'],
    ], ticket_about_available($pdo) ? [$aboutPair['about']] : [], [$id]));

    sync_ticket_completion($pdo, $id, $after['status_id'] ? ($statusMap[$after['status_id']] ?? null) : null);

    // Write the change into the thread, so the card carries its own history.
    $statusNames = [];
    foreach ($statusMap as $sid => $s) $statusNames[$sid] = $s['name'];
    $crewNames = [];
    foreach ($assignees as $c) $crewNames[(int)$c['id']] = $c['name'];
    if ($t['assigned_crew_id'] && !isset($crewNames[(int)$t['assigned_crew_id']])) {
        // Somebody who has since left the project still has to be nameable in
        // the history, or the line reads "nobody → Ana" for a reassignment.
        $crewNames[(int)$t['assigned_crew_id']] = $t['crew_name'] ?? ('crew #' . (int)$t['assigned_crew_id']);
    }
    $changes = describe_ticket_changes($t, $after, $statusNames, $crewNames);
    if ($changes) {
        log_ticket_event($pdo, $id, $userId, implode("\n", $changes));
    }

    $notes = ['Saved.'];
    if ($typedShot !== '' && $after['shot_id'] === null) {
        $notes[] = 'No shot called "' . $typedShot . '" in this project, so it was not linked.';
    }
    if ($typedAsset !== '' && $after['asset_id'] === null) {
        $notes[] = 'No asset called "' . $typedAsset . '" in this project, so it was not linked.';
    }

    // Notify on a genuine change of hands, or when the status moved — so the
    // owner learns their work is wanted, or the reporter learns it is ready.
    $crewChanged   = (int)($t['assigned_crew_id'] ?? 0) !== (int)($newCrew ?? 0);
    $statusChanged = (int)($t['status_id'] ?? 0) !== (int)($newStatus ?? 0);
    if ($newCrew && $crewChanged) {
        [, $m] = notify_assignee($pdo, $id, 'assigned');
        $notes[] = $m;
    } elseif ($newCrew && $statusChanged) {
        [, $m] = notify_assignee($pdo, $id, 'status');
        $notes[] = $m;
    }

    flash('ok', implode(' ', $notes));
    header('Location: ' . $selfUrl);
    exit;
}

// --- delete the card --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ticket'])) {
    if ($canDeleteCard) {
        $res = delete_ticket_with_files($pdo, $id);
        flash($res['deleted'] ? 'ok' : 'warn', $res['deleted']
            ? $label . ' deleted' . ($res['files'] ? ", and {$res['files']} attached file(s) removed." : '.')
            : 'Nothing was deleted.');
        header('Location: ' . $boardUrl);
        exit;
    }
    flash('err', 'Only an administrator, or whoever raised it, can delete this card.');
    header('Location: ' . $selfUrl);
    exit;
}

// --- delete one attachment --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'])) {
    $aid = (int)$_POST['delete_attachment'];
    $q = $pdo->prepare('SELECT stored_name, uploaded_by FROM ticket_attachments WHERE id = ? AND ticket_id = ?');
    $q->execute([$aid, $id]);
    if ($a = $q->fetch()) {
        if (is_admin() || (int)$a['uploaded_by'] === $userId) {
            @unlink(upload_dir_path() . '/' . basename($a['stored_name']));
            $pdo->prepare('DELETE FROM ticket_attachments WHERE id = ?')->execute([$aid]);
            flash('ok', 'Attachment removed.');
        } else {
            flash('err', 'You can only remove attachments you uploaded yourself.');
        }
    } else {
        flash('warn', 'That attachment is already gone.');
    }
    header('Location: ' . $selfUrl);
    exit;
}

// --- load the rest ----------------------------------------------------------
$commentsQ = $pdo->prepare(
    'SELECT tc.*, u.display_name FROM ticket_comments tc
     LEFT JOIN users u ON u.id = tc.user_id
     WHERE tc.ticket_id = ? ORDER BY tc.created_at ASC, tc.id ASC'
);
$commentsQ->execute([$id]);
$comments = $commentsQ->fetchAll();

// ⚠ OPENING THE TICKET IS WHAT MARKS A MENTION READ, not seeing it listed on My
// tasks — a count there is a pointer, and clearing it on a glance would lose the
// one signal saying where to go. Written on a GET, deliberately: it is a fact
// about somebody having looked, and there is no form to submit. The daily
// snapshot does the same thing for the same reason (DECISIONS, 2026-08-13).
if (ticket_mentions_available($pdo) && column_exists($pdo, 'ticket_mentions', 'seen_at')
    && $_SERVER['REQUEST_METHOD'] === 'GET' && $userId) {
    $me = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
    $me->execute([$userId]);
    $myCrew = (int)$me->fetchColumn();
    if ($myCrew > 0) {
        $pdo->prepare(
            'UPDATE ticket_mentions m
               JOIN ticket_comments c ON c.id = m.comment_id
                SET m.seen_at = NOW()
              WHERE c.ticket_id = ? AND m.crew_id = ? AND m.seen_at IS NULL'
        )->execute([$id, $myCrew]);
    }
}

// Who each comment named, for the highlight. One query for the page rather than
// one per comment — a long ticket is forty comments.
$mentionsByComment = [];
if (ticket_mentions_available($pdo) && $comments) {
    $ids = implode(',', array_map(function ($c) { return (int)$c['id']; }, $comments));
    foreach ($pdo->query(
        'SELECT comment_id, named_as FROM ticket_mentions WHERE comment_id IN (' . $ids . ')'
    ) as $m) {
        $mentionsByComment[(int)$m['comment_id']][] = (string)$m['named_as'];
    }
}
$threaded = ticket_threads_available($pdo) ? ticket_thread($comments) : $comments;
$userComments = array_filter($comments, fn($c) => ($c['kind'] ?? 'user') === 'user');

$attQ = $pdo->prepare('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY created_at ASC');
$attQ->execute([$id]);
$attachmentsByComment = [];
foreach ($attQ->fetchAll() as $a) {
    $attachmentsByComment[$a['comment_id'] === null ? 0 : (int)$a['comment_id']][] = $a;
}

$stepsQ = $pdo->prepare('SELECT id, name FROM pipeline_steps WHERE project_id = ? ORDER BY step_order, name');
$stepsQ->execute([$projectId]);
$steps = $stepsQ->fetchAll();

$shotsQ = $pdo->prepare('SELECT episode, shot_code FROM shots WHERE project_id = ? ORDER BY episode, shot_code');
$shotsQ->execute([$projectId]);
$shotOptions = [];
foreach ($shotsQ->fetchAll() as $s) {
    $l = shot_label($s['episode'], $s['shot_code']);
    if ($l !== '') $shotOptions[] = $l;
}

$assetsQ = $pdo->prepare('SELECT name FROM assets WHERE project_id = ? ORDER BY name');
$assetsQ->execute([$projectId]);
$assetOptions = $assetsQ->fetchAll(PDO::FETCH_COLUMN);

$episodic  = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';
$currentShot = shot_label($t['episode'] ?? '', $t['shot_code'] ?? '');

// Staffing is per show, so an unfiled card can only offer the studio-wide list.
$staffHref = $projectId ? 'crew.php?project=' . $projectId : 'crew.php';
$statusColor = $t['status_color'] ?: '#8a97a3';

/** One attachment: previewed inline when the browser can show it, linked otherwise. */

$pageTitle = $label . ' · ' . ($project['name'] ?? 'no project yet');
require __DIR__ . '/includes/header.php';
?>

<?php // ⚠ NO BAR WITHOUT A PROJECT. The bar is a show's own navigation —
      // its board, its shots, its staffing — and there is no show to navigate.
      // The card still opens, and the control that matters, filing it against a
      // show, is at the bottom of this page. ?>
<?php if ($project): ?>
  <?php project_nav($project, 'tasks'); ?>
<?php else: ?>
  <p class="flash flash-warn">
    <strong>This card is not on a project yet.</strong>
    It has no board, no status and no reference number until it is filed against a
    show — choose one at the bottom of this page. Until then it lives on
    <a href="alltasks.php">Studio Tasks</a>.
  </p>
<?php endif; ?>

<p class="crumb"><a href="<?= e($backUrl) ?>"><?= e($backLabel) ?></a></p>

<?php // The same helper the notification emails use, so a pasted link and an
      // emailed one are always the same URL.
      $shareUrl = ticket_url($id); ?>
<div class="share-url">
  <span class="share-url-label">Link</span>
  <input type="text" id="shareUrl" value="<?= e($shareUrl) ?>" readonly onclick="this.select();"
         title="Click to select, or use Copy">
  <button type="button" class="secondary" id="copyUrlBtn" onclick="copyTicketUrl()">Copy</button>
</div>

<section class="panel">
  <h2 style="margin:0;">
    <span class="ticket-ref-lg <?= $isTicket ? 'ref-ticket' : 'ref-task' ?>"><?= e($label) ?></span>
    <?= e($t['title']) ?>
  </h2>
  <p class="muted small" style="margin-top:6px;">
    <span class="status-chip" style="background:<?= e($statusColor) ?>1a;color:<?= e($statusColor) ?>;border:1px solid <?= e($statusColor) ?>44;">
      <?= e($t['status_name'] ?? 'no status') ?>
    </span>
    <?php if ((int)($t['status_blocked'] ?? 0) === 1): ?>
      <span class="status-chip" style="background:#fff3cd;color:#8a6d1a;border:1px solid #e0c98a;">blocked</span>
    <?php endif; ?>
    · <?= ucfirst($t['priority']) ?> priority
    <?php if ($t['reporter_name']): ?> · raised by <strong><?= e($t['reporter_name']) ?></strong><?php endif; ?>
    · <?= date('M j, Y', strtotime($t['created_at'])) ?>
    <?php if ($t['crew_name']): ?> · owned by <strong><?= e($t['crew_name']) ?></strong><?php endif; ?>
    <?php if ($t['due_date']): ?> · due <strong><?= date('M j, Y', strtotime($t['due_date'])) ?></strong><?php endif; ?>
    <?php if ((int)$t['archived'] === 1): ?> · <em>archived</em><?php endif; ?>
    <?php if (($t['snoozed_until'] ?? null) && $t['snoozed_until'] > date('Y-m-d')): ?>
      · <em>snoozed until <?= date('M j, Y', strtotime($t['snoozed_until'])) ?></em>
    <?php endif; ?>
  </p>

  <?php if ((int)($t['is_placeholder'] ?? 0) === 1): ?>
    <p class="flash flash-warn" style="margin-top:10px;">
      This is owned by <strong><?= e($t['crew_name']) ?></strong>, a planning slot that nobody
      fills yet, so no notification email was sent. Renaming the slot to a real person on the
      <a href="<?= e($staffHref) ?>">Staffing screen</a> makes this theirs, with nothing to move.
    </p>
  <?php elseif ($t['crew_name'] && empty($t['crew_email']) && (int)($t['is_team'] ?? 0) === 0): ?>
    <p class="flash flash-warn" style="margin-top:10px;">
      <strong><?= e($t['crew_name']) ?></strong> has no email address on file, so notifications about
      this card go nowhere. Add one on the <a href="<?= e($staffHref) ?>">Staffing screen</a>.
    </p>
  <?php endif; ?>

  <?php if ($currentShot !== '' || $t['asset_name'] || $t['step_name']
            || ($t['about'] ?? '') === TICKET_ABOUT_PLATFORM): ?>
    <p style="margin-top:8px;">
      <?php if ($currentShot !== ''): ?><span class="ctx-chip ctx-shot"><?= e($currentShot) ?></span><?php endif; ?>
      <?php if ($t['asset_name']): ?><span class="ctx-chip ctx-asset"><?= e($t['asset_name']) ?></span><?php endif; ?>
      <?php if ($t['step_name']): ?><span class="ctx-chip ctx-step"><?= e($t['step_name']) ?></span><?php endif; ?>
      <?php if (($t['about'] ?? '') === TICKET_ABOUT_PLATFORM): ?>
        <span class="ctx-chip ctx-platform"><?= e(ticket_about_label(TICKET_ABOUT_PLATFORM)) ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($t['description']): ?>
    <div class="ticket-body"><?= nl2br(e($t['description'])) ?></div>
  <?php endif; ?>

  <?php if (!empty($attachmentsByComment[0])): ?>
    <div class="attachment-grid">
      <?php foreach ($attachmentsByComment[0] as $a) {
              render_attachment($a, can_edit() && (is_admin() || (int)$a['uploaded_by'] === $userId));
            } ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Activity <span class="muted small">(<?= count($userComments) ?> comment<?= count($userComments) === 1 ? '' : 's' ?>)</span></h2>

  <?php if (empty($comments)): ?>
    <p class="muted small">Nothing yet. Changes to the status, owner, priority or due date are
       recorded here automatically, alongside anything anyone writes.</p>
  <?php endif; ?>

  <?php
  // ONE RENDERER, used for a comment and for a reply, so the two cannot drift
  // apart in anything but their indent. $depth is 0 or 1 and nothing else —
  // see migration 039 for why a ticket is not a forum.
  $renderComment = function (array $c, int $depth) use (
      $attachmentsByComment, $mentionsByComment, $userId, $pdo, $selfUrl
  ) {
      $isSystem = ($c['kind'] ?? 'user') === 'system';
      $cid = (int)$c['id'];
      $named = $mentionsByComment[$cid] ?? [];
      ?>
      <div class="comment <?= $isSystem ? 'comment-system' : '' ?><?= $depth > 0 ? ' comment-reply' : '' ?>"
           id="c<?= $cid ?>">
        <div class="comment-head">
          <strong><?= e($c['display_name'] ?? 'Someone') ?></strong>
          <?php if ($isSystem): ?><span class="muted small">changed</span><?php endif; ?>
          <span class="muted small"><?= date('M j, Y · g:i A', strtotime($c['created_at'])) ?></span>
          <?php // Only on a real comment, and only at the top level: a reply to a
                // reply would be a third tier, and "changed the status" is not
                // something to answer. ?>
          <?php if (!$isSystem && $depth === 0 && can_edit() && ticket_threads_available($pdo)): ?>
            <button type="button" class="comment-reply-btn linkish"
                    data-reply-to="<?= $cid ?>"
                    data-reply-name="<?= e($c['display_name'] ?? '') ?>">Reply</button>
          <?php endif; ?>
        </div>
        <div class="comment-body"><?= ticket_render_body((string)$c['body'], $named) ?></div>
        <?php if (!empty($attachmentsByComment[$cid])): ?>
          <div class="attachment-grid">
            <?php foreach ($attachmentsByComment[$cid] as $a) {
                    render_attachment($a, can_edit() && (is_admin() || (int)$a['uploaded_by'] === $userId));
                  } ?>
          </div>
        <?php endif; ?>
      </div>
      <?php
  };
  ?>
  <?php foreach ($threaded as $c): ?>
    <?php $renderComment($c, 0); ?>
    <?php foreach (($c['replies'] ?? []) as $r): ?>
      <?php $renderComment($r, 1); ?>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php if (can_edit()): ?>
  <form method="post" enctype="multipart/form-data" class="comment-form"
        data-ticket-form data-redirect="<?= e($selfUrl) ?>">
    <input type="hidden" name="add_comment" value="1">
    <input type="hidden" name="parent_id" value="" id="replyTo">
    <?php // Says what the box will do before it does it. Hidden until Reply is
          // pressed, because a bar saying "replying to nobody" is furniture. ?>
    <div class="reply-banner" id="replyBanner" hidden>
      Replying to <strong id="replyName"></strong>
      <button type="button" class="linkish" id="replyCancel">post as a new comment instead</button>
    </div>
    <label>Add a comment
      <textarea name="body" rows="3" id="commentBody"
                placeholder="Reply, add what you found, or attach a reference… type @ to name somebody"></textarea>
    </label>
    <?php // No tick and no explanation: naming somebody emails them, full stop
          // (David, 2026-10-06). The placeholder on the box above already says
          // what @ does, and a line of small print explaining a thing that
          // always happens is just something to read every time. ?>
    <label>Attach files
      <input type="file" name="attachments[]" multiple data-filepicker
             accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,video/webm,application/pdf">
    </label>
    <div class="file-list" data-filelist></div>
    <div class="upload-progress" data-progress style="display:none;">
      <div class="progress-track"><div class="progress-fill"></div></div>
      <div class="progress-label muted small">Preparing…</div>
    </div>
    <div class="actions"><button type="submit">Post comment</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Details</h2>
  <form method="post" class="detail-form">
    <input type="hidden" name="update" value="1">

    <label>Title
      <input name="title" value="<?= e($t['title']) ?>" required <?= can_edit() ? '' : 'disabled' ?>>
    </label>

    <label>Description
      <textarea name="description" rows="4" <?= can_edit() ? '' : 'disabled' ?>
                placeholder="What happened, what you expected, how to see it…"><?= e($t['description'] ?? '') ?></textarea>
    </label>

    <div class="row">
      <?php // ⚠ HIDDEN, NOT EMPTY, WITHOUT A PROJECT. A shot and an asset both
            // belong to one show, so with no show there is nothing either box could
            // ever match, and two boxes that can only be wrong are worse than none.
            // They appear the moment the card is filed. The pipeline step stays:
            // "Anima Dev" is about the platform itself and needs no project. ?>
      <?php if ($projectId): ?>
      <label>Shot <span class="muted small">(type to search)</span>
        <input name="shot_label" list="shotList" autocomplete="off" value="<?= e($currentShot) ?>"
               placeholder="<?= $episodic ? 'e.g. EP002_SH050' : 'e.g. SH050' ?>" <?= can_edit() ? '' : 'disabled' ?>>
        <datalist id="shotList">
          <?php foreach ($shotOptions as $s): ?><option value="<?= e($s) ?>"></option><?php endforeach; ?>
        </datalist>
      </label>
      <label>Asset <span class="muted small">(type to search)</span>
        <input name="asset_label" list="assetList" autocomplete="off" value="<?= e($t['asset_name'] ?? '') ?>"
               placeholder="e.g. Romulan Officer" <?= can_edit() ? '' : 'disabled' ?>>
        <datalist id="assetList">
          <?php foreach ($assetOptions as $a): ?><option value="<?= e($a) ?>"></option><?php endforeach; ?>
        </datalist>
      </label>
      <?php endif; ?>
      <label>Pipeline step
        <?php // Drawn by the same function the new-ticket form uses, so the two
              // cannot disagree about what is on offer. ?>
        <select name="step_id" <?= can_edit() ? '' : 'disabled' ?>>
          <?= ticket_step_options($steps, $t['step_id'], (string)($t['about'] ?? ''),
                                  ticket_about_available($pdo)) ?>
        </select>
      </label>
    </div>

    <div class="row">
      <label>Status
        <select name="status_id" <?= can_edit() ? '' : 'disabled' ?>>
          <?php // Statuses are a project's own, so there are none to offer yet. Said
                // out loud rather than drawn as an empty dropdown. ?>
          <?php if (!$projectId): ?>
            <option value="">no project, so no statuses yet</option>
          <?php endif; ?>
          <?php
          // Every status, not only the board columns: a card can legitimately sit
          // in one that isn't a column ("Retake"), and hiding those here would
          // make the board silently rewrite it on the next save.
          foreach ($statusMap as $sid => $s): ?>
            <option value="<?= (int)$sid ?>" <?= (int)$t['status_id'] === (int)$sid ? 'selected' : '' ?>>
              <?= e($s['name']) ?><?= in_array((int)$sid, array_map(fn($c) => (int)$c['id'], $columns), true) ? '' : ' (not a board column)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Priority
        <select name="priority" <?= can_edit() ? '' : 'disabled' ?>>
          <?php foreach (ticket_priorities() as $p): ?>
            <option value="<?= $p ?>" <?= $t['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Owner <?php if ($isTicket): ?><span class="req-mark">*</span><?php endif; ?>
        <select name="assigned_crew_id" <?= $isTicket ? 'required' : '' ?> <?= can_edit() ? '' : 'disabled' ?>>
          <option value="">nobody</option>
          <?php
          // A person who has since left the project still has to appear, or
          // saving anything else on the card would silently unassign them.
          $known = array_map(fn($c) => (int)$c['id'], $assignees);
          if ($t['assigned_crew_id'] && !in_array((int)$t['assigned_crew_id'], $known, true)) {
              echo '<option value="' . (int)$t['assigned_crew_id'] . '" selected>'
                 . e($t['crew_name'] ?? 'unknown') . ' (no longer on this project)</option>';
          }
          foreach ($assignees as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$t['assigned_crew_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e(assignee_label($c)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Due date
        <input type="date" name="due_date" value="<?= e($t['due_date'] ?? '') ?>" <?= can_edit() ? '' : 'disabled' ?>>
      </label>
    </div>

    <?php if (can_edit()): ?>
      <div class="actions"><button type="submit">Save changes</button></div>
    <?php endif; ?>
  </form>

  <?php // SNOOZE — a wake-up date (migration 054). Its own form, between Save and
        // Move: it hides the card from the active lists until the date, then the
        // card returns on its own. Separate from the due date on purpose. ?>
  <?php if (can_edit() && column_exists($pdo, 'tickets', 'snoozed_until')):
    $snoozedUntil = $t['snoozed_until'] ?? null;
    $isSnoozed = $snoozedUntil !== null && $snoozedUntil > date('Y-m-d');
  ?>
    <div class="move-zone">
      <div>
        <strong>Snooze</strong>
        <p class="muted small" style="margin:2px 0 0;">
          <?php if ($isSnoozed): ?>
            Hidden from My tasks, the board and Studio Tasks until
            <strong><?= date('M j, Y', strtotime($snoozedUntil)) ?></strong>, when it comes back on its own.
          <?php else: ?>
            Hide this <?= $isTicket ? 'ticket' : 'task' ?> until a future date — it drops off My tasks,
            the board and Studio Tasks and returns on its own that day. The due date is not changed.
          <?php endif; ?>
        </p>
      </div>
      <form method="post" class="inline-form">
        <input type="date" name="snoozed_until" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
               value="<?= e($isSnoozed ? $snoozedUntil : '') ?>" aria-label="Snooze until">
        <button type="submit" name="snooze" value="set" class="secondary"><?= $isSnoozed ? 'Change date' : 'Snooze' ?></button>
        <?php if ($isSnoozed): ?>
          <button type="submit" name="snooze" value="clear">Un-snooze</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php // MOVING IT, between Save and Delete: more consequential than editing a
        // field, less than destroying the card. The list is every other project,
        // and the current one is named in the sentence rather than being an
        // option that would do nothing. ?>
  <?php if (can_edit()):
    $others = $pdo->query(
      // ⚠ 'postmortem' MUST be named in the FIELD list even though the WHERE
      // keeps it. FIELD() returns 0 for a value it does not list, and 0 sorts
      // FIRST ascending — so leaving it out would float every show waiting on
      // its postmortem to the top of this picker, above the live ones.
      "SELECT id, code, name FROM projects WHERE status <> 'archived' ORDER BY
         FIELD(status, 'active','setup','on_hold','wrapped','postmortem'), name"
    )->fetchAll();
    $others = array_values(array_filter($others, function ($o) use ($projectId) {
        return (int)$o['id'] !== $projectId;
    }));
    if ($others): ?>
    <div class="move-zone">
      <div>
        <strong><?= $projectId ? 'Move to another project' : 'File this on a project' ?></strong>
        <p class="muted small" style="margin:2px 0 0;">
          <?php if ($projectId): ?>
            Currently on <strong><?= e((string)($t['project_name'] ?? '')) ?></strong>.
          <?php else: ?>
            <strong>Not on a project yet.</strong> Filing it gives it a board, a status
            and a reference number.
          <?php endif; ?>
          <?= e($label) ?> becomes the next number on the project it goes to.
          <?php if ($projectId): ?>
            Any shot, asset or step is cleared, because those belong to this show.
          <?php else: ?>
            It joins the first column on that board.
          <?php endif; ?>
          Comments, attachments and the assignee go with it.
        </p>
      </div>
      <form method="post" class="inline-form"
            onsubmit="return confirm('<?= e($label) ?>: <?= $projectId ? 'move it to another project' : 'file it on this project' ?>?

Its reference will change<?= $projectId ? ' and any shot, asset or step will be cleared' : '' ?>.');">
        <select name="to_project" aria-label="Project to move this card to">
          <?php foreach ($others as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?> (<?= e($o['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="move_project" value="1" class="secondary"><?= $projectId ? 'Move' : 'File' ?></button>
      </form>
    </div>
  <?php endif; endif; ?>

  <?php if (can_edit() && $canDeleteCard): ?>
    <div class="danger-zone">
      <div>
        <strong>Delete this <?= $isTicket ? 'ticket' : 'task' ?></strong>
        <p class="muted small" style="margin:2px 0 0;">
          Removes it along with all <?= count($userComments) ?> comment(s) and every attached file.
          This cannot be undone. To take it off the board without losing it, move it to a status
          that counts as approved and let it archive instead.
        </p>
      </div>
      <form method="post"
            onsubmit="return confirm('Delete <?= e($label) ?> permanently?\n\nComments and attachments go too. This cannot be undone.');">
        <button type="submit" name="delete_ticket" value="1" class="danger">Delete</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<script>
window.TICKET_MAX_FILES = <?= (int)($config['upload_max_files'] ?? 5) ?>;
window.TICKET_MAX_BYTES = <?= (int)($config['upload_max_bytes'] ?? 26214400) ?>;

function copyTicketUrl() {
  const input = document.getElementById('shareUrl');
  const btn = document.getElementById('copyUrlBtn');
  const done = () => {
    btn.textContent = 'Copied';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1600);
  };
  // navigator.clipboard needs a secure context; fall back where there isn't one.
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(input.value).then(done).catch(() => {
      input.select(); document.execCommand('copy'); done();
    });
  } else {
    input.select();
    document.execCommand('copy');
    done();
  }
}
</script>
<?php // The people who can be named, which is exactly the list the owner
      // dropdown offers — so a name the picker suggests is always a name
      // ticket_parse_mentions() can resolve. Teams and unfilled slots are left
      // out: naming a placeholder tells nobody anything. ?>
<script>
  window.TICKET_PEOPLE = <?= json_encode(array_values(array_map(
      function ($a) { return ['name' => (string)$a['name'], 'role' => (string)($a['role'] ?? '')]; },
      array_filter($assignees, function ($a) {
          return (int)($a['is_team'] ?? 0) !== 1 && (int)($a['is_placeholder'] ?? 0) !== 1;
      })
  )), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="assets/mentions.js<?= asset_ver('mentions.js') ?>"></script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
</div>
</body></html>
