<?php
/**
 * Bug reports (migration 062).
 *
 * POST report_bug — filed from the Bug button every screen draws
 *   (render_bug_button() in includes/bugs.php). Anyone signed in may file one,
 *   artists and supervisors included: artist_may_post() lists this field.
 * POST set_status — an admin marks a report fixed, won't fix, or open again.
 * POST save_notes — an admin adds the detail a report is waiting on (064).
 * GET — the reports. Admins see everybody's; everybody else sees their own, so
 *   they can tell a report went through and whether it has been dealt with.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/bugs.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);

if (!bugs_available($pdo)) {
    $pageTitle = 'Bug reports';
    require __DIR__ . '/includes/header.php';
    echo '<h2>Bug reports</h2><div class="flash flash-warn">Bug reports need database update 062. '
       . (is_admin() ? '<a href="migrate.php">Apply it on Migrations →</a>' : 'Ask an administrator to apply it.')
       . '</div></div></body></html>';
    exit;
}

// Over post_max_size PHP hands us an empty POST: no fields, no files. Say so,
// rather than drawing the list as though nothing had been sent. (The form
// checks sizes before sending, so this is the no-JavaScript path.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('err', 'That report was too large for the server to accept, so nothing was saved. '
               . 'Try again with a shorter clip or a screenshot.');
    header('Location: bugs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_bug'])) {
    // Back to the page the button was pressed on. The value came from the form,
    // so it is the caller's text: only a same-site path is ours to return to
    // (the same rule require_login() keeps for REQUEST_URI).
    $back = (string)($_POST['page_url'] ?? '');
    $second = $back[1] ?? '';
    if ($back === '' || $back[0] !== '/' || $second === '/' || $second === chr(92)) {
        $back = 'bugs.php';
    }

    $description = trim((string)($_POST['description'] ?? ''));
    $section = trim((string)($_POST['section'] ?? ''));
    if (!in_array($section, bug_sections(), true)) $section = bug_sections()[''];

    if ($description === '') {
        flash('err', 'The bug report needs a description, nothing was sent.');
        header('Location: ' . $back);
        exit;
    }

    // Who, in what role, and when come from the session and the clock, never
    // the form. real_role(), not user_role(): an admin viewing the app as an
    // artist is still an admin filing it, and the report should say so.
    $user = current_user();
    $name = (string)($user['display_name'] ?: ($user['username'] ?? current_user_display()));
    $role = real_role();
    if (in_user_preview()) {
        $pu = preview_user();
        $name .= ' (viewing as ' . (string)($pu['display_name'] ?: $pu['username']) . ')';
    }

    $pdo->prepare('INSERT INTO bug_reports
                     (user_id, reporter_name, role_at_report, section, description, page_url, user_agent)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId ?: null, mb_substr($name, 0, 150), $role, $section,
                   mb_substr($description, 0, 5000),
                   mb_substr((string)($_POST['page_url'] ?? ''), 0, 500) ?: null,
                   mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300) ?: null]);
    $bugId = (int)$pdo->lastInsertId();

    $result = ['saved' => 0, 'errors' => []];
    if (!empty($_FILES['files'])) {
        $result = save_attachments($pdo, normalize_files_array($_FILES['files']),
                                   'bug_attachments', 'bug_id', $bugId, null, $userId);
    }
    // A VIDEO FLAGS THE REPORT (migration 064). Claude, which works these
    // reports through bugs_export.php, can read a screenshot but cannot watch a
    // recording, so a report carried by a video waits for David to say what
    // happens in it. Read back from what was SAVED, not what was posted, so a
    // refused video flags nothing.
    if ($result['saved'] && bugs_flags_available($pdo)) {
        $v = $pdo->prepare("SELECT 1 FROM bug_attachments WHERE bug_id = ? AND mime_type LIKE 'video/%' LIMIT 1");
        $v->execute([$bugId]);
        if ($v->fetchColumn()) {
            $pdo->prepare('UPDATE bug_reports SET needs_info = 1, flag_reason = ? WHERE id = ?')
                ->execute([BUG_VIDEO_FLAG, $bugId]);
        }
    }
    // A post bigger than post_max_size arrives with $_FILES and $_POST both
    // empty, so it never reaches here; a single file over upload_max_filesize
    // does, and save_attachments() names it.
    flash('ok', "Thanks, bug #{$bugId} is reported"
        . ($result['saved'] ? " with {$result['saved']} file" . ($result['saved'] === 1 ? '' : 's') : '') . '.');
    foreach ($result['errors'] as $err) flash('warn', $err);
    header('Location: ' . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!is_admin()) {
        flash('err', 'Only an administrator can close a bug report.');
    } else {
        $status = (string)$_POST['set_status'];
        if (in_array($status, ['open', 'fixed', 'wontfix'], true)) {
            $pdo->prepare('UPDATE bug_reports
                              SET status = ?, closed_at = IF(? = \'open\', NULL, NOW())
                            WHERE id = ?')
                ->execute([$status, $status, (int)($_POST['bug_id'] ?? 0)]);
        }
    }
    header('Location: bugs.php' . (isset($_GET['show']) ? '?show=' . urlencode((string)$_GET['show']) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    if (!is_admin()) {
        flash('err', 'Only an administrator can add notes to a bug report.');
    } elseif (bugs_flags_available($pdo)) {
        // Writing the detail IS the answer to the flag, so a note clears it;
        // emptying the note leaves the flag where it was.
        $notes = trim((string)($_POST['admin_notes'] ?? ''));
        $pdo->prepare("UPDATE bug_reports
                          SET admin_notes = ?, needs_info = IF(? = '', needs_info, 0)
                        WHERE id = ?")
            ->execute([$notes === '' ? null : mb_substr($notes, 0, 5000), $notes, (int)($_POST['bug_id'] ?? 0)]);
        flash('ok', 'Notes saved.');
    }
    header('Location: bugs.php' . (isset($_GET['show']) ? '?show=' . urlencode((string)$_GET['show']) : ''));
    exit;
}

// ---- the list --------------------------------------------------------------
$flags = bugs_flags_available($pdo);
$show = in_array($_GET['show'] ?? '', ['open', 'needs', 'closed', 'all'], true) ? $_GET['show'] : 'open';
if ($show === 'needs' && !$flags) $show = 'open';
$where = [];
$args = [];
if (!is_admin()) { $where[] = 'b.user_id = ?'; $args[] = $userId; }
if ($show === 'open')   $where[] = "b.status = 'open'";
if ($show === 'closed') $where[] = "b.status <> 'open'";
if ($show === 'needs')  $where[] = "b.status = 'open' AND b.needs_info = 1";
$sql = 'SELECT b.* FROM bug_reports b'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY b.created_at DESC, b.id DESC LIMIT 500';
$q = $pdo->prepare($sql);
$q->execute($args);
$bugs = $q->fetchAll();

$files = [];
if ($bugs) {
    $ids = array_map(fn($b) => (int)$b['id'], $bugs);
    $f = $pdo->query('SELECT id, bug_id, original_name, mime_type, file_size FROM bug_attachments
                       WHERE bug_id IN (' . implode(',', $ids) . ') ORDER BY id');
    foreach ($f as $row) $files[(int)$row['bug_id']][] = $row;
}

$pageTitle = 'Bug reports';
require __DIR__ . '/includes/header.php';
?>
<h2>Bug reports</h2>
<p class="muted"><?= is_admin()
    ? 'Everything reported with the 🐞 button, newest first.'
    : 'The bugs you have reported with the 🐞 button, and whether they have been dealt with.' ?></p>

<div class="bug-filter">
  <?php foreach (['open' => 'Open'] + ($flags ? ['needs' => 'Needs your description'] : [])
                 + ['closed' => 'Closed', 'all' => 'All'] as $k => $label): ?>
    <a class="button<?= $show === $k ? '' : ' secondary' ?>" href="bugs.php?show=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$bugs): ?>
  <p class="muted"><?= $show === 'needs' ? 'Nothing is waiting on you.'
      : 'No ' . ($show === 'all' ? '' : e($show) . ' ') . 'bug reports.' ?></p>
<?php endif; ?>

<?php foreach ($bugs as $b): $bid = (int)$b['id'];
      $hasVideo = false;
      foreach ($files[$bid] ?? [] as $a) $hasVideo = $hasVideo || attachment_is_video($a['mime_type']); ?>
  <div class="panel bug-report bug-<?= e($b['status']) ?>">
    <div class="bug-head">
      <strong>#<?= $bid ?> · <?= e($b['section']) ?>
        <?php if ($hasVideo): ?><span class="bug-tag" title="This report has a video">🎥 Video</span><?php endif; ?></strong>
      <span class="bug-status"><?= e(bug_status_label($b['status'])) ?></span>
    </div>
    <div class="muted small">
      <?= e($b['reporter_name']) ?> · <?= e(ucfirst(trim(preg_replace('/^an? /', '', role_in_words($b['role_at_report']))))) ?>
      · <?= e(date('j M Y, H:i', strtotime($b['created_at']))) ?>
      <?php if (!empty($b['page_url'])): ?>
        · on <a href="<?= e($b['page_url']) ?>"><?= e(mb_strimwidth($b['page_url'], 0, 60, '…')) ?></a>
      <?php endif; ?>
    </div>
    <p class="bug-desc"><?= nl2br(e($b['description'])) ?></p>
    <?php if (!empty($files[$bid])): ?>
      <div class="attachment-grid">
        <?php foreach ($files[$bid] as $a): $url = 'attachment.php?kind=bug&id=' . (int)$a['id']; $n = e($a['original_name']); ?>
          <div class="attachment">
            <?php if (attachment_is_image($a['mime_type'])): ?>
              <a href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= $url ?>" alt="<?= $n ?>"></a>
            <?php elseif (attachment_is_video($a['mime_type'])): ?>
              <video src="<?= $url ?>" controls preload="metadata"></video>
            <?php else: ?>
              <a href="<?= $url ?>" class="file-link" target="_blank" rel="noopener">📄 <?= $n ?></a>
            <?php endif; ?>
            <div class="attachment-meta"><span title="<?= $n ?>"><?= $n ?></span>
              <span class="muted small"><?= human_size((int)$a['file_size']) ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($flags && $b['status'] === 'open' && (int)$b['needs_info'] === 1): ?>
      <div class="bug-needs"><strong>Waiting for your description.</strong> <?= e((string)$b['flag_reason']) ?></div>
    <?php endif; ?>
    <?php if ($flags && is_admin()): ?>
      <form method="post" class="bug-notes">
        <input type="hidden" name="bug_id" value="<?= $bid ?>">
        <label>Your notes for Claude
          <textarea name="admin_notes" rows="3" maxlength="5000"
            placeholder="What happens in the video, how to reproduce it, what should happen instead."><?= e((string)$b['admin_notes']) ?></textarea>
        </label>
        <button type="submit" name="save_notes" value="1" class="secondary">Save notes</button>
      </form>
    <?php elseif ($flags && !empty($b['admin_notes'])): ?>
      <p class="bug-desc"><strong>Notes:</strong> <?= nl2br(e($b['admin_notes'])) ?></p>
    <?php endif; ?>
    <?php if ($flags && !empty($b['fix_note'])): ?>
      <p class="muted small">Fix: <?= e($b['fix_note']) ?></p>
    <?php endif; ?>
    <?php if (is_admin()): ?>
      <form method="post" class="bug-actions">
        <input type="hidden" name="bug_id" value="<?= $bid ?>">
        <?php if ($b['status'] === 'open'): ?>
          <button type="submit" name="set_status" value="fixed">Mark fixed</button>
          <button type="submit" name="set_status" value="wontfix" class="secondary">Won't fix</button>
        <?php else: ?>
          <span class="muted small"><?= e(bug_status_label($b['status'])) ?>
            <?= $b['closed_at'] ? ' on ' . e(date('j M Y', strtotime($b['closed_at']))) : '' ?></span>
          <button type="submit" name="set_status" value="open" class="secondary">Reopen</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

</div>
</body></html>
