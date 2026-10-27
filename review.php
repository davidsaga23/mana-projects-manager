<?php
// Reviews — the work waiting on you, and a verdict on each in one click.
//
// David, 2026-10-23 (roadmap) and 2026-10-27: a reviewer "can easily see what's
// for review, give review notes, approve directly or copy the URL of that
// version to bring the conversation to slack". Reached from the Reviews button
// in the top bar, beside My tasks, which carries the count.
//
// Supervisors see Pending review and approve to Ready for review; admins and
// Production see Ready for review and approve to Complete. Either may send work
// back with a note. The rules are in includes/review.php.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/uploads.php';   // version_label()
require_once __DIR__ . '/includes/mailer.php';    // notify_mentioned_task(), for @ in a note
require_login();

$stage = review_stage();
if (!$stage) {
    flash('err', 'Reviews are for supervisors, Production and admins.');
    header('Location: index.php');
    exit;
}
$cfg = REVIEW_STAGES[$stage];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', review_apply($pdo, (int)($_POST['progress_id'] ?? 0),
            $action, (string)($_POST['note'] ?? ''), (int)($_POST['back_status'] ?? 0)));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: review.php' . (isset($_POST['project']) && $_POST['project'] !== ''
        ? '?project=' . (int)$_POST['project'] : ''));
    exit;
}

$queue = review_queue($pdo, $stage);

// One chip per show that has something waiting, so a long queue can be cut down
// to the show you are on today. All of them by default.
$shows = [];
foreach ($queue as $t) {
    $pid = (int)$t['project_id'];
    $shows[$pid] = ($shows[$pid] ?? ['code' => $t['project_code'], 'n' => 0]);
    $shows[$pid]['n']++;
}
$only = (int)($_GET['project'] ?? 0);
if ($only && !isset($shows[$only])) $only = 0;
$shown = $only ? array_values(array_filter($queue, fn($t) => (int)$t['project_id'] === $only)) : $queue;

// The send-back choices, per show, worked out once rather than per row.
$backOptions = [];
$missingTarget = [];
foreach (array_keys($shows) as $pid) {
    $backOptions[$pid] = review_back_statuses($pdo, $pid, $stage);
    if (!review_status_named($pdo, $pid, $cfg['to'])) $missingTarget[] = $shows[$pid]['code'];
}

$pageTitle = 'Reviews';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title"><?= e($cfg['label']) ?>
  <span class="muted">· <?= count($queue) ?> waiting on you</span></h2>
<p class="lead">
  Everything in <strong><?= e(ucwords($cfg['from'])) ?></strong> on the shows you can see, newest version
  first. <strong>Approve</strong> moves it to <strong><?= e(ucwords($cfg['to'])) ?></strong>;
  <strong>Send back</strong> needs a note and goes to the status you pick. A note is posted as a review
  on the current version, so it is in the task's own conversation in Spell.
</p>

<?php if ($missingTarget): ?>
  <p class="flash flash-warn">
    <?= e(implode(', ', $missingTarget)) ?> <?= count($missingTarget) === 1 ? 'has' : 'have' ?> no status called
    <strong><?= e(ucwords($cfg['to'])) ?></strong>, so Approve is refused there until it is added on the
    Statuses screen.
  </p>
<?php endif; ?>

<?php if (count($shows) > 1): ?>
  <p class="board-projects review-shows">
    <a class="board-chip<?= $only ? '' : ' is-on' ?>" href="review.php">All shows (<?= count($queue) ?>)</a>
    <?php foreach ($shows as $pid => $s): ?>
      <a class="board-chip<?= $only === $pid ? ' is-on' : '' ?>" href="review.php?project=<?= $pid ?>"><?= e($s['code']) ?> (<?= $s['n'] ?>)</a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<?php if (!$shown): ?>
  <div class="empty-state"><p>Nothing is waiting for your review. Tasks arrive here when they are set to
    <strong><?= e(ucwords($cfg['from'])) ?></strong>.</p></div>
<?php else: ?>
  <ul class="review-queue">
  <?php foreach ($shown as $t):
      $pid = (int)$t['project_id'];
      $v = $t['version'];
      $openPath = board_task_path($pid, (int)$t['step_id'], (string)$t['item_key'], 'versions', $v ? 'v' . (int)$v['id'] : '');
      $isImage = $v && strpos((string)($v['mime_type'] ?? ''), 'image/') === 0;
      $fileUrl = $v ? 'attachment.php?kind=version&amp;id=' . (int)$v['id'] : '';
  ?>
    <li class="review-item">
      <div class="review-thumb">
        <?php if ($isImage): ?>
          <a href="<?= $fileUrl ?>" class="att-zoom" data-name="<?= e((string)$v['original_name']) ?>">
            <img src="<?= $fileUrl ?>" alt="<?= e((string)$v['original_name']) ?>" loading="lazy"></a>
        <?php elseif ($v): ?>
          <a href="<?= $fileUrl ?>" class="review-file" target="_blank" rel="noopener"><?= e((string)$v['original_name']) ?></a>
        <?php else: ?>
          <span class="muted small">no version uploaded</span>
        <?php endif; ?>
      </div>

      <div class="review-body">
        <div class="review-title">
          <span class="dot" style="background:<?= e($t['step_color'] ?: '#ccc') ?>"></span>
          <strong><?= e($t['item_name']) ?></strong> · <?= e($t['step_name']) ?>
          <span class="muted small"><?= e($t['project_code']) ?></span>
          <?php if ($v): ?>
            <span class="version-tag"><?= e(version_label((int)$v['version_no'])) ?></span>
          <?php endif; ?>
          <?php if ($t['notes'] > 0): ?>
            <span class="review-notes-count" title="Review notes already on this version"><?= (int)$t['notes'] ?> note<?= $t['notes'] === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </div>
        <div class="muted small">
          <?= $t['crew_name'] ? e($t['crew_name']) : 'Nobody assigned' ?>
          <?php if ($v): ?>· put up <?= e(date('j M Y · H:i', strtotime((string)$v['created_at']))) ?><?php endif; ?>
          <?php if ($v && trim((string)($v['note'] ?? '')) !== ''): ?>· “<?= e((string)$v['note']) ?>”<?php endif; ?>
        </div>
        <?php if ($v && !empty($v['link'])): ?>
          <div class="small"><a href="<?= e((string)$v['link']) ?>" target="_blank" rel="noopener">Final files ↗</a></div>
        <?php endif; ?>

        <form method="post" class="review-verdict">
          <input type="hidden" name="progress_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="project" value="<?= $only ?: '' ?>">
          <textarea name="note" rows="2" placeholder="Review note: optional to approve, needed to send back. @Name tags someone."></textarea>
          <div class="review-actions">
            <button type="submit" name="action" value="approve">Approve → <?= e(ucwords($cfg['to'])) ?></button>
            <span class="review-back">
              <select name="back_status" aria-label="Status to send it back to">
                <?php foreach ($backOptions[$pid] as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="action" value="back" class="secondary">Send back</button>
            </span>
            <span class="spacer"></span>
            <a class="button secondary" href="<?= e($openPath) ?>">Open in Spell</a>
            <button type="button" class="secondary board-copy-url" data-copy="<?= e($openPath) ?>"
                    title="A link to this version. It opens for anyone signed in to Anima, not publicly.">Copy URL</button>
          </div>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<script>
// Copy URL, the same as Spell's: a full address on the clipboard, with a
// fallback for a studio on plain http where navigator.clipboard is refused.
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.board-copy-url') : null;
  if (!btn) return;
  e.preventDefault();
  var url = new URL(btn.getAttribute('data-copy') || '', window.location.href).href;
  var done = function (ok) {
    var was = btn.textContent;
    btn.textContent = ok ? 'Copied' : 'Copy failed';
    setTimeout(function () { btn.textContent = was; }, 1500);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(function () { done(true); }, function () { done(false); });
    return;
  }
  var box = document.createElement('textarea');
  box.value = url;
  box.style.cssText = 'position:fixed;top:-1000px;opacity:0';
  document.body.appendChild(box);
  box.select();
  var ok = false;
  try { ok = document.execCommand('copy'); } catch (err) {}
  box.remove();
  done(ok);
});
// Send back without a note would only be refused by the server; say so here.
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (!f.classList || !f.classList.contains('review-verdict')) return;
  var btn = e.submitter;
  if (btn && btn.value === 'back' && !f.note.value.trim()) {
    e.preventDefault();
    f.note.focus();
    f.note.placeholder = 'Say what needs changing before sending it back.';
  }
});
</script>

</div>
</body></html>
