<?php
// The project brief — step 3 of the production cycle, and until now the only
// step with nowhere to live.
//
// A project has started here at "name, code, type, fps, delivery date" since
// Phase 2. What the client ASKED FOR — the deliverables, their formats, the
// reference, who to call — sat in somebody's email. Everything downstream is
// derived from it, and it is the document people go looking for in week six,
// after the person who received it has moved on to the next show.
//
// Deliberately free text rather than a form of fields. Every brief is shaped
// differently, and a form with the wrong boxes is worse than a blank page:
// people leave out whatever does not fit.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uploads.php';
require_login();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'brief.php?project=' . $projectId;
$userId  = (int)($_SESSION['user_id'] ?? 0);
$ready   = column_exists($pdo, 'project_brief', 'project_id');

// --- save -------------------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $pdo->prepare(
        'INSERT INTO project_brief
           (project_id, client, client_contact, summary, deliverables, reference_links, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           client = VALUES(client), client_contact = VALUES(client_contact),
           summary = VALUES(summary), deliverables = VALUES(deliverables),
           reference_links = VALUES(reference_links), notes = VALUES(notes),
           updated_by = VALUES(updated_by)'
    )->execute([
        $projectId,
        trim($_POST['client'] ?? '') ?: null,
        trim($_POST['client_contact'] ?? '') ?: null,
        trim($_POST['summary'] ?? '') ?: null,
        trim($_POST['deliverables'] ?? '') ?: null,
        trim($_POST['reference_links'] ?? '') ?: null,
        trim($_POST['notes'] ?? '') ?: null,
        current_user_display(),
    ]);

    $notes = ['Brief saved.'];
    if (!empty($_FILES['attachments'])) {
        $res = save_attachments($pdo, normalize_files_array($_FILES['attachments']),
                                'project_attachments', 'project_id', $projectId, null, $userId);
        if ($res['saved'])  $notes[] = $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $notes[] = implode(' ', $res['errors']);
    }
    flash('ok', implode(' ', $notes));
    header('Location: ' . $selfUrl);
    exit;
}

// --- remove one attachment --------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'])) {
    $aid = (int)$_POST['delete_attachment'];
    $q = $pdo->prepare('SELECT stored_name, uploaded_by FROM project_attachments WHERE id = ? AND project_id = ?');
    $q->execute([$aid, $projectId]);
    if ($a = $q->fetch()) {
        if (is_admin() || (int)$a['uploaded_by'] === $userId) {
            @unlink(upload_dir_path() . '/' . basename($a['stored_name']));
            $pdo->prepare('DELETE FROM project_attachments WHERE id = ?')->execute([$aid]);
            flash('ok', 'File removed.');
        } else {
            flash('err', 'You can only remove files you uploaded yourself.');
        }
    } else {
        flash('warn', 'That file is already gone.');
    }
    header('Location: ' . $selfUrl);
    exit;
}

$brief = null; $files = [];
if ($ready) {
    $q = $pdo->prepare('SELECT * FROM project_brief WHERE project_id = ?');
    $q->execute([$projectId]);
    $brief = $q->fetch() ?: null;

    $f = $pdo->prepare('SELECT * FROM project_attachments WHERE project_id = ? ORDER BY created_at');
    $f->execute([$projectId]);
    $files = $f->fetchAll();
}

$hasAny = $brief && (
    $brief['summary'] || $brief['deliverables'] || $brief['reference_links']
    || $brief['client'] || $brief['notes']
);

$pageTitle = 'Brief · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'brief'); ?>
<h2 class="wizard-title">Brief <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>The brief needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>023_project_brief.sql</code> creates the two tables this
       screen reads.</p>
    <?php if (is_admin()): ?><p><a class="button" href="migrate.php">Review and apply it →</a></p><?php endif; ?>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<p class="lead">
  What the client asked for, in their terms — the thing every date, quota and breakdown on the
  other screens is ultimately derived from. Written as prose rather than a form, because every
  brief is shaped differently and a box that doesn't fit is a box people leave empty.
</p>

<?php if (!$hasAny): ?>
  <div class="flash flash-warn">
    Nothing recorded yet. This is worth ten minutes at the start of a show: it is the document
    somebody goes looking for in week six, usually when the person who received it is busy on
    something else.
  </div>
<?php endif; ?>

<?php if ($brief && !empty($brief['updated_at'])): ?>
  <p class="muted small">
    Last updated <?= e(substr((string)$brief['updated_at'], 0, 16)) ?><?php
      if (!empty($brief['updated_by'])): ?> by <?= e($brief['updated_by']) ?><?php endif; ?>.
  </p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="stack brief-form"
      data-ticket-form data-redirect="<?= e($selfUrl) ?>">
  <input type="hidden" name="save" value="1">

  <div class="row">
    <label>Client
      <input name="client" value="<?= e($brief['client'] ?? '') ?>" placeholder="e.g. Scopely"
             <?= can_edit() ? '' : 'disabled' ?>>
    </label>
    <label>Contact <span class="muted small">(who to call)</span>
      <input name="client_contact" value="<?= e($brief['client_contact'] ?? '') ?>"
             placeholder="Name, email or phone" <?= can_edit() ? '' : 'disabled' ?>>
    </label>
  </div>

  <label>The ask
    <textarea name="summary" rows="6" placeholder="What the client wants, in their words."
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['summary'] ?? '') ?></textarea>
    <small>The brief itself. Paste the email if that is what arrived.</small>
  </label>

  <label>Deliverables
    <textarea name="deliverables" rows="5"
              placeholder="e.g. 6 × 30s spots, 1920×1080 ProRes 422 HQ, 23.976, stereo mix, captions as SRT"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['deliverables'] ?? '') ?></textarea>
    <small>Formats, durations, resolutions, quantities — the half people re-read, and the half a
           disagreement is usually about.</small>
  </label>

  <label>Reference
    <textarea name="reference_links" rows="4" placeholder="Links to boards, look reference, previous work"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['reference_links'] ?? '') ?></textarea>
  </label>

  <label>Notes
    <textarea name="notes" rows="3" placeholder="Anything agreed on a call, caveats, things to watch"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['notes'] ?? '') ?></textarea>
  </label>

  <?php if (can_edit()): ?>
    <label>Attach files
      <input type="file" name="attachments[]" multiple data-filepicker
             accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,video/webm,application/pdf">
      <small>The client's PDF, a reference board, a signed scope. Up to
             <?= (int)($config['upload_max_files'] ?? 5) ?> files, max
             <?= human_size((int)($config['upload_max_bytes'] ?? 26214400)) ?> each.</small>
    </label>
    <div class="file-list" data-filelist></div>
    <div class="upload-progress" data-progress style="display:none;">
      <div class="progress-track"><div class="progress-fill"></div></div>
      <div class="progress-label muted small">Preparing…</div>
    </div>
    <div class="actions"><button type="submit">Save brief</button></div>
  <?php endif; ?>
</form>

<?php if ($files): ?>
  <h3>Files <span class="muted">· <?= count($files) ?></span></h3>
  <div class="attachment-grid">
    <?php foreach ($files as $a):
      $url = 'attachment.php?kind=project&amp;id=' . (int)$a['id'];
      $name = e($a['original_name']);
      $canDelete = can_edit() && (is_admin() || (int)$a['uploaded_by'] === $userId);
    ?>
      <div class="attachment">
        <?php if (attachment_is_image($a['mime_type'])): ?>
          <a href="<?= $url ?>" class="att-zoom" data-name="<?= $name ?>" target="_blank" rel="noopener">
            <img src="<?= $url ?>" alt="<?= $name ?>"></a>
        <?php elseif (attachment_is_video($a['mime_type'])): ?>
          <video src="<?= $url ?>" controls preload="metadata"></video>
        <?php else: ?>
          <a href="<?= $url ?>" class="file-link" target="_blank" rel="noopener">📄 <?= $name ?></a>
        <?php endif; ?>
        <div class="attachment-meta">
          <span title="<?= $name ?>"><?= $name ?></span>
          <span class="muted small"><?= e(human_size((int)$a['file_size'])) ?></span>
          <?php if ($canDelete): ?>
            <form method="post" onsubmit="return confirm('Remove this file?');">
              <button type="submit" name="delete_attachment" value="<?= (int)$a['id'] ?>" class="link-danger">×</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="muted small">
  Files live in the same protected directory as ticket attachments, behind the same login and
  the same checks — never on a guessable public URL. Contracts and rates are deliberately
  <em>not</em> here: those are in the finance database, reachable from a person's page and only
  by an administrator.
</p>

<script>
window.TICKET_MAX_FILES = <?= (int)($config['upload_max_files'] ?? 5) ?>;
window.TICKET_MAX_BYTES = <?= (int)($config['upload_max_bytes'] ?? 26214400) ?>;
</script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
</div>
</body></html>
