<?php
// The list behind one number in the Overview's progress table.
//
// David, 2026-11-16, circling the Delivered and Approved columns: "let's make a
// new feature when the cursor clicks those numbers we can see the list of shots
// or assets that that number is counting". Tracked does the same, because "21 of
// 21" raises the same question the day it reads "19 of 21".
//
// ⚠ LOADED ON CLICK, NOT WITH THE PAGE. The Overview is everybody's landing
// page; ten steps times three columns of lists nobody opened would be paid for
// on every visit. This answers one cell at a time.
//
// ⚠ RETURNS MARKUP, NOT JSON, for the reason board_task.php gives: the escaping
// stays in PHP with the rest of the codebase, and the "open" buttons are the
// same .board-open-task buttons Reviews and My tasks draw, so
// assets/board-screen.js opens them in Spell's panel without a line of new code.
//
// THE LIST IS step_progress_items(), which filters with progress_measure_sql():
// the same test the count was summed with in dashboard_steps(). Its length is
// the number that was clicked, by construction.
//
// Doors: FEATURE_SCRIPTS maps this file to the Overview switch, and
// feature_request_project() refuses a show this account is not on; both run in
// require_login(). may_see_project() is checked again here so the file is safe
// even if it ever drops out of those lists.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_GET['project'] ?? 0);
$stepId    = (int)($_GET['step'] ?? 0);
$which     = (string)($_GET['what'] ?? '');
$labels    = ['tracked' => 'tracked', 'delivered' => 'delivered', 'approved' => 'approved'];

if (!isset($labels[$which]) || $projectId <= 0 || !may_see_project($pdo, $projectId)) {
    http_response_code(404);
    echo '<p class="muted">That list is not one this page can show.</p>';
    exit;
}

$q = $pdo->prepare('SELECT id, name FROM pipeline_steps WHERE id = ? AND project_id = ?');
$q->execute([$stepId, $projectId]);
$step = $q->fetch();
if (!$step) {
    http_response_code(404);
    echo '<p class="muted">That list is not one this page can show.</p>';
    exit;
}

$rows = step_progress_items($pdo, $projectId, $stepId, $which);

// Spell's panel opens only for accounts that may see Spell: board_task.php is
// behind that switch, and a button that answers "not open to your account" is
// worse than plain text.
$canOpen = !function_exists('feature_on') || feature_on('spell');
?>
<div class="psi-head">
  <strong><?= count($rows) ?></strong> <?= e($labels[$which]) ?> on <?= e($step['name']) ?>
  <button type="button" class="psi-close" aria-label="Close this list">×</button>
</div>
<?php if (!$rows): ?>
  <p class="muted">Nothing yet.</p>
<?php else: ?>
  <table class="psi-table">
    <thead><tr><th>Item</th><th>Status</th><th>Assignee</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $who = (string)($r['assignee'] ?? '');
      if ($who === '' && trim((string)($r['assignee_raw'] ?? '')) !== '') {
          // A name the import carried that matched nobody in the crew list.
          $who = trim((string)$r['assignee_raw']) . ' (unmatched)';
      }
    ?>
      <tr>
        <td>
          <?php if ($canOpen): ?>
            <button type="button" class="board-open-task psi-open"
                    data-project="<?= $projectId ?>" data-step="<?= (int)$r['step_id'] ?>"
                    data-item="<?= e($r['item_key']) ?>"
                    aria-label="Open <?= e($step['name']) ?> on <?= e($r['item']) ?>"><?= e($r['item']) ?></button>
          <?php else: ?>
            <?= e($r['item']) ?>
          <?php endif; ?>
          <?php if ($r['kind'] === 'asset'): ?><small class="muted">asset</small><?php endif; ?>
        </td>
        <td>
          <?php if ($r['status_name'] !== null): ?>
            <span class="dot" style="background:<?= e($r['status_color'] ?: '#ccc') ?>"></span><?= e($r['status_name']) ?>
          <?php else: ?>
            <span class="muted">not started</span>
          <?php endif; ?>
          <?php if ((int)($r['is_blocked'] ?? 0) === 1): ?><small class="warn-text">blocked</small><?php endif; ?>
        </td>
        <td>
          <?= $who !== '' ? e($who) : '<span class="muted">nobody</span>' ?>
          <?php if (!empty($r['others'])): ?><small class="muted">+ <?= e($r['others']) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
