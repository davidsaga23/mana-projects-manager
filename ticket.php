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

$projectId = (int)$t['project_id'];
$pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$pstmt->execute([$projectId]);
$project = $pstmt->fetch();

$selfUrl  = 'ticket.php?id=' . $id;
$boardUrl = 'tasks.php?project=' . $projectId;
$isTicket = ($t['type'] ?? 'task') === 'ticket';
$label    = ticket_ref($t);
$canDeleteCard = is_admin() || (int)$t['created_by'] === $userId;

$statusMap = statuses_by_id($pdo, $projectId);
[$columns] = ticket_board_columns($pdo, $projectId);
$assignees = ticket_assignees($pdo, $projectId);

// --- post a comment ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $body = trim($_POST['body'] ?? '');
    $hasFiles = !empty($_FILES['attachments']['name'][0] ?? '');

    if ($body === '' && !$hasFiles) {
        flash('warn', 'Nothing to post, the comment was empty and no file was attached.');
        finish_post($selfUrl);
    }

    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, kind, body) VALUES (?, ?, \'user\', ?)')
        ->execute([$id, $userId ?: null, $body !== '' ? $body : '(attachment)']);
    $commentId = (int)$pdo->lastInsertId();

    $notes = ['Comment posted.'];
    if ($hasFiles) {
        $res = save_ticket_attachments($pdo, normalize_files_array($_FILES['attachments']), $id, $commentId, $userId);
        if ($res['saved'])  $notes[] = $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $notes[] = implode(' ', $res['errors']);
    }
    flash('ok', implode(' ', $notes));
    finish_post($selfUrl);
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
$statusColor = $t['status_color'] ?: '#8a97a3';

/** One attachment: previewed inline when the browser can show it, linked otherwise. */
function render_attachment(array $a, bool $canDelete): void {
    $url  = 'attachment.php?id=' . (int)$a['id'];
    $name = e($a['original_name']);
    echo '<div class="attachment">';
    if (attachment_is_image($a['mime_type'])) {
        // The href is real and still works — ctrl-click, middle-click and a
        // browser with JavaScript off all open the file directly. The click
        // handler in tickets.js intercepts an ordinary click and shows the image
        // full size over this page instead, so looking at a reference does not
        // cost you the ticket you were reading.
        echo '<a href="' . $url . '" class="att-zoom" data-name="' . $name . '"'
           . ' target="_blank" rel="noopener"><img src="' . $url . '" alt="' . $name . '"></a>';
    } elseif (attachment_is_video($a['mime_type'])) {
        echo '<video src="' . $url . '" controls preload="metadata"></video>';
    } else {
        echo '<a href="' . $url . '" class="file-link" target="_blank" rel="noopener">📄 ' . $name . '</a>';
    }
    echo '<div class="attachment-meta"><span title="' . $name . '">' . $name . '</span>';
    echo '<span class="muted small">' . human_size((int)$a['file_size']) . '</span>';
    if ($canDelete) {
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Remove this attachment?\');">'
           . '<button type="submit" name="delete_attachment" value="' . (int)$a['id'] . '" class="link-danger">×</button>'
           . '</form>';
    }
    echo '</div></div>';
}

$pageTitle = $label . ' · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'tasks'); ?>

<p class="crumb"><a href="<?= e($boardUrl) ?>">← Back to the board</a></p>

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
  </p>

  <?php if ((int)($t['is_placeholder'] ?? 0) === 1): ?>
    <p class="flash flash-warn" style="margin-top:10px;">
      This is owned by <strong><?= e($t['crew_name']) ?></strong>, a planning slot that nobody
      fills yet, so no notification email was sent. Renaming the slot to a real person on the
      <a href="crew.php?project=<?= $projectId ?>">Staffing screen</a> makes this theirs, with nothing to move.
    </p>
  <?php elseif ($t['crew_name'] && empty($t['crew_email']) && (int)($t['is_team'] ?? 0) === 0): ?>
    <p class="flash flash-warn" style="margin-top:10px;">
      <strong><?= e($t['crew_name']) ?></strong> has no email address on file, so notifications about
      this card go nowhere. Add one on the <a href="crew.php?project=<?= $projectId ?>">Staffing screen</a>.
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

  <?php foreach ($comments as $c): $isSystem = ($c['kind'] ?? 'user') === 'system'; ?>
    <div class="comment <?= $isSystem ? 'comment-system' : '' ?>">
      <div class="comment-head">
        <strong><?= e($c['display_name'] ?? 'Someone') ?></strong>
        <?php if ($isSystem): ?><span class="muted small">changed</span><?php endif; ?>
        <span class="muted small"><?= date('M j, Y · g:i A', strtotime($c['created_at'])) ?></span>
      </div>
      <div class="comment-body"><?= nl2br(e($c['body'])) ?></div>
      <?php if (!empty($attachmentsByComment[(int)$c['id']])): ?>
        <div class="attachment-grid">
          <?php foreach ($attachmentsByComment[(int)$c['id']] as $a) {
                  render_attachment($a, can_edit() && (is_admin() || (int)$a['uploaded_by'] === $userId));
                } ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (can_edit()): ?>
  <form method="post" enctype="multipart/form-data" class="comment-form"
        data-ticket-form data-redirect="<?= e($selfUrl) ?>">
    <input type="hidden" name="add_comment" value="1">
    <label>Add a comment
      <textarea name="body" rows="3" placeholder="Reply, add what you found, or attach a reference…"></textarea>
    </label>
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
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
</div>
</body></html>
