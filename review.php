<?php
// Reviews — the work waiting on you, and a verdict on each in one click.
//
// David, 2026-10-23 (roadmap) and 2026-10-27: a reviewer "can easily see what's
// for review, give review notes, approve directly or copy the URL of that
// version to bring the conversation to slack". Reached from the Reviews button
// in the top bar, beside My tasks, which carries the count.
//
// Supervisors see Pending review and approve to Ready for review; admins and
// Production see both stages (2026-10-29), each card saying which, and approve
// each to its next status. Anyone may send it to Retake instead. The rules are
// in includes/review.php.
//
// A GRID OF CARDS, AND THE PICTURE OPENS SPELL'S VIEWER (David, 2026-10-29: "a
// grid of cards instead of one item per line/box ... Once a supervisor clicks
// the image, they will see the same review view as in spell, they can leave
// notes directly there or have the option to attached images"). The viewer is
// assets/review-viewer.js, built from the task's Versions pane, which this page
// fetches from board_task.php?pane=versions when a picture is clicked. So the
// notes, the drawing and the files go through the same add_review as in Spell,
// and who may post is decided where it always was. Approve and Retake ride
// along into the viewer's top bar.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/uploads.php';   // version_label(), attachment_is_image()
require_once __DIR__ . '/includes/mailer.php';    // notify_mentioned_task(), for @ in a note
require_login();

$stages = review_stages();
if (!$stages) {
    flash('err', 'Reviews are for supervisors, Production and admins.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', review_apply($pdo, (int)($_POST['progress_id'] ?? 0),
            $action, (string)($_POST['note'] ?? '')));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: review.php' . (isset($_POST['project']) && $_POST['project'] !== ''
        ? '?project=' . (int)$_POST['project'] : ''));
    exit;
}

$queue = review_queue($pdo);

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

// The two statuses the verdicts move to, per show, worked out once rather than
// per card. A show missing one gets a warning and that button off, rather than
// a button that is only refused after the click.
// Approve is per stage: keyed [stage][show].
$noApprove = [];
$noRetake = [];
foreach ($queue as $t) {
    $pid = (int)$t['project_id'];
    $to = REVIEW_STAGES[$t['stage']]['to'];
    if (!isset($noApprove[$t['stage']][$pid]) && !review_status_named($pdo, $pid, $to)) {
        $noApprove[$t['stage']][$pid] = $shows[$pid]['code'];
    }
    if (!isset($noRetake[$pid]) && !review_status_named($pdo, $pid, REVIEW_RETAKE)) {
        $noRetake[$pid] = $shows[$pid]['code'];
    }
}
// How the stages read in the sentences below: "Pending review → Ready For Review".
$stageLine = fn(string $k) => '<strong>' . e(ucwords(REVIEW_STAGES[$k]['from'])) . '</strong> → <strong>'
                            . e(ucwords(REVIEW_STAGES[$k]['to'])) . '</strong>';

$pageTitle = 'Reviews';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title"><?= count($stages) > 1 ? 'Reviews' : e(REVIEW_STAGES[$stages[0]]['label']) ?>
  <span class="muted">· <?= count($queue) ?> waiting on you</span></h2>
<p class="lead">
  Everything waiting for your review on the shows you can see, newest version first.
  <strong>Approve</strong> moves it on: <?= implode('; ', array_map($stageLine, $stages)) ?>.
  <strong>Retake</strong> moves it to <strong>Retake</strong>. Click the picture to open it in the review
  viewer, the same one as in Spell: step through it, draw on it, write notes and attach images. Approve and
  Retake are also at the top of the viewer.
</p>

<?php
$warnings = [];
foreach ($noApprove as $k => $missing) $warnings[] = [$missing, ucwords(REVIEW_STAGES[$k]['to']), 'Approve'];
$warnings[] = [$noRetake, 'Retake', 'Retake'];
foreach ($warnings as [$missing, $named, $button]):
    if (!$missing) continue; ?>
  <p class="flash flash-warn">
    <?= e(implode(', ', $missing)) ?> <?= count($missing) === 1 ? 'has' : 'have' ?> no status called
    <strong><?= e($named) ?></strong>, so <?= e($button) ?> is off there until it is added on the
    Statuses screen.
  </p>
<?php endforeach; ?>

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
    <?= implode(' or ', array_map(fn($k) => '<strong>' . e(ucwords(REVIEW_STAGES[$k]['from'])) . '</strong>', $stages)) ?>.</p></div>
<?php else: ?>
  <ul class="rq-grid">
  <?php foreach ($shown as $t):
      $pid = (int)$t['project_id'];
      $to = ucwords(REVIEW_STAGES[$t['stage']]['to']);
      $offApprove = isset($noApprove[$t['stage']][$pid]);
      $v = $t['version'];
      $openPath = board_task_path($pid, (int)$t['step_id'], (string)$t['item_key'], 'versions', $v ? 'v' . (int)$v['id'] : '');
      $mime = $v ? (string)($v['mime_type'] ?? '') : '';
      $isImage = $v && attachment_is_image($mime);
      $isVideo = $v && attachment_is_video($mime);
      $fileUrl = $v ? 'attachment.php?kind=version&amp;id=' . (int)$v['id'] : '';
      $vName = $v ? e((string)$v['original_name']) : '';
      // The version and its note count, laid over the corners of the picture.
      $tags = '';
      if ($v) $tags .= '<span class="version-tag">' . e(version_label((int)$v['version_no'])) . '</span>';
      if ($t['notes'] > 0) {
          $tags .= '<span class="review-notes-count" title="Review notes already on this version">'
                 . (int)$t['notes'] . ' note' . ($t['notes'] === 1 ? '' : 's') . '</span>';
      }
  ?>
    <li class="rq-card">
      <?php if ($isImage || $isVideo): ?>
        <?php // The way into the viewer: which task, and which version to open on. ?>
        <button type="button" class="rq-media" title="Open in the review viewer"
                data-rq-open="<?= (int)$v['id'] ?>" data-project="<?= $pid ?>"
                data-step="<?= (int)$t['step_id'] ?>" data-item="<?= e((string)$t['item_key']) ?>">
          <?php if ($isImage): ?>
            <img src="<?= $fileUrl ?>" alt="<?= $vName ?>" loading="lazy">
          <?php else: ?>
            <?php // A frame just in, as the thumbnail; preload="metadata" fetches only the start until it is opened. ?>
            <video src="<?= $fileUrl ?>#t=0.1" preload="metadata" muted playsinline></video>
            <span class="rq-play" aria-hidden="true">▶</span>
          <?php endif; ?>
          <?= $tags ?>
        </button>
      <?php elseif ($v): ?>
        <?php // A PDF or anything else the viewer cannot show: the file itself. ?>
        <a class="rq-media is-file" href="<?= $fileUrl ?>" target="_blank" rel="noopener"><?= $vName ?><?= $tags ?></a>
      <?php else: ?>
        <div class="rq-media is-empty">No version uploaded yet</div>
      <?php endif; ?>

      <div class="rq-info">
        <div class="rq-title">
          <span class="dot" style="background:<?= e($t['step_color'] ?: '#ccc') ?>"></span>
          <strong title="<?= e($t['item_name'] . ' · ' . $t['step_name']) ?>"><?= e($t['item_name']) ?> · <?= e($t['step_name']) ?></strong>
          <span class="muted small rq-show" title="<?= e((string)$t['project_name']) ?>"><?= e($t['project_code']) ?></span>
        </div>
        <?php if (count($stages) > 1): ?>
          <?php // Which stage, when this page shows both: the status it is in now. ?>
          <span class="rq-stage is-<?= e($t['stage']) ?>"><?= e((string)$t['status_name']) ?></span>
        <?php endif; ?>
        <div class="muted small rq-meta">
          <?= $t['crew_name'] ? e($t['crew_name']) : 'Nobody assigned' ?>
          <?php if ($v): ?>· put up <?= e(date('j M · H:i', strtotime((string)$v['created_at']))) ?><?php endif; ?>
        </div>
        <?php if ($v && trim((string)($v['note'] ?? '')) !== ''): ?>
          <div class="muted small rq-meta" title="<?= e((string)$v['note']) ?>">“<?= e((string)$v['note']) ?>”</div>
        <?php endif; ?>
        <?php if ($v && !empty($v['link'])): ?>
          <div class="small"><a href="<?= e((string)$v['link']) ?>" target="_blank" rel="noopener">Final files ↗</a></div>
        <?php endif; ?>
      </div>

      <form method="post" class="rq-verdict">
        <input type="hidden" name="progress_id" value="<?= (int)$t['id'] ?>">
        <input type="hidden" name="project" value="<?= $only ?: '' ?>">
        <div class="rq-buttons">
          <button type="submit" name="action" value="approve"
                  title="<?= $offApprove ? e($t['project_code'] . ' has no ' . $to . ' status') : 'Moves it to ' . e($to) ?>"
                  <?= $offApprove ? 'disabled' : '' ?>>Approve</button>
          <button type="submit" name="action" value="retake" class="secondary danger"
                  title="<?= isset($noRetake[$pid]) ? e($t['project_code'] . ' has no Retake status') : 'Moves it to Retake' ?>"
                  <?= isset($noRetake[$pid]) ? 'disabled' : '' ?>>Retake</button>
        </div>
        <div class="rq-links">
          <a href="<?= e($openPath) ?>">Open in Spell</a>
          <button type="button" class="secondary board-copy-url" data-copy="<?= e($openPath) ?>"
                  title="A link to this version. It opens for anyone signed in to Anima, not publicly.">Copy URL</button>
        </div>
      </form>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php // Where the clicked task's Versions pane is put for the viewer to read. ?>
<div id="rqPane" hidden></div>

<?php // The @ picker in the viewer's box, the lightbox (tickets.js) for a picture
      // in somebody's note, the files going with a review, and the viewer. ?>
<script src="assets/mentions.js<?= asset_ver('mentions.js') ?>"></script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<script src="assets/review-files.js<?= asset_ver('review-files.js') ?>"></script>
<script src="assets/review-viewer.js<?= asset_ver('review-viewer.js') ?>"></script>
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
// A picture opens Spell's review viewer. The viewer is built from the task's
// Versions pane, so that pane is fetched first (board_task.php?pane=versions,
// the same checks as the task panel), and the card's own Approve and Retake
// go into the viewer's top bar: pressing one there presses it here.
document.addEventListener('click', function (e) {
  var t = e.target.closest ? e.target.closest('[data-rq-open]') : null;
  if (!t || t.classList.contains('is-loading')) return;
  e.preventDefault();
  var card = t.closest('.rq-card');
  var spell = card.querySelector('.rq-links a');
  var host = document.getElementById('rqPane');
  var url = 'board_task.php?pane=versions&project=' + encodeURIComponent(t.getAttribute('data-project'))
          + '&step=' + encodeURIComponent(t.getAttribute('data-step'))
          + '&item=' + encodeURIComponent(t.getAttribute('data-item'));
  t.classList.add('is-loading');
  fetch(url, { credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(function (html) {
      t.classList.remove('is-loading');
      host.innerHTML = html;
      var pane = host.querySelector('[data-pane]');
      if (!pane || !window.animaReviewViewer || !pane.querySelector('li.version')) throw new Error('no pane');
      var actions = [];
      card.querySelectorAll('.rq-buttons button').forEach(function (b) {
        if (b.disabled) return;
        actions.push({ label: b.textContent.trim(), cls: b.className, title: b.title,
                       run: function () { b.click(); } });
      });
      window.animaReviewViewer.open(pane, t.getAttribute('data-rq-open'), { actions: actions });
    })
    .catch(function () {
      // Whatever went wrong, the task in Spell still has everything.
      t.classList.remove('is-loading');
      if (spell) window.location.href = spell.href;
    });
});
</script>

</div>
</body></html>
