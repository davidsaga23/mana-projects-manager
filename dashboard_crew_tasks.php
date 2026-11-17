<?php
// The tasks behind one number in the Dashboard's "Who is carrying what".
//
// David, 2026-11-16, on the Still to do column: "if you click the number you can
// see the list of those tasks missing". Tasks opens the same way.
//
// The sibling of project_step_items.php (the Overview's progress counts), built
// the same way for the same reasons: loaded on click, not with the page; markup,
// not JSON, so the escaping stays in PHP and the items are the .board-open-task
// buttons assets/board-screen.js already opens in Spell's panel.
//
// THE LIST IS crew_workload_items(), which filters with workload_remaining_sql(),
// the test dashboard_workload() sums. Its length is the number that was clicked.
//
// Doors: FEATURE_SCRIPTS maps this file to the Dashboard switch, and
// feature_request_project() refuses a show this account is not on; both run in
// require_login(). may_see_project() is checked again here as well.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_GET['project'] ?? 0);
$crewId    = (int)($_GET['crew'] ?? 0);
$which     = (string)($_GET['what'] ?? '');
$labels    = ['tasks' => 'tasks', 'remaining' => 'still to do'];

if (!isset($labels[$which]) || $projectId <= 0 || $crewId <= 0 || !may_see_project($pdo, $projectId)) {
    http_response_code(404);
    echo '<p class="muted">That list is not one this page can show.</p>';
    exit;
}

$q = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
$q->execute([$crewId]);
$who = (string)($q->fetchColumn() ?: '');

// The same "today" the Dashboard counted with (dashboard.php: date('Y-m-d')).
$rows = crew_workload_items($pdo, $projectId, $crewId, $which, date('Y-m-d'));

// Spell's panel opens only for accounts that may see Spell (board_task.php is
// behind that switch); anyone else gets the item as plain text.
$canOpen = !function_exists('feature_on') || feature_on('spell');
$today   = date('Y-m-d');
?>
<div class="psi-head">
  <strong><?= count($rows) ?></strong> <?= e($labels[$which]) ?><?= $who !== '' ? ' for ' . e($who) : '' ?>
  <button type="button" class="psi-close" aria-label="Close this list">×</button>
</div>
<?php if (!$rows): ?>
  <p class="muted">Nothing here.</p>
<?php else: ?>
  <table class="psi-table">
    <thead><tr><th>Item</th><th>Department</th><th>Status</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <?php if ($canOpen): ?>
            <button type="button" class="board-open-task psi-open"
                    data-project="<?= $projectId ?>" data-step="<?= (int)$r['step_id'] ?>"
                    data-item="<?= e($r['item_key']) ?>"
                    aria-label="Open <?= e($r['step_name']) ?> on <?= e($r['item']) ?>"><?= e($r['item']) ?></button>
          <?php else: ?>
            <?= e($r['item']) ?>
          <?php endif; ?>
          <?php if ($r['kind'] === 'asset'): ?><small class="muted">asset</small><?php endif; ?>
        </td>
        <td><span class="dot" style="background:<?= e($r['step_color'] ?: '#ccc') ?>"></span><?= e($r['step_name']) ?></td>
        <td>
          <?php if ($r['status_name'] !== null): ?>
            <span class="dot" style="background:<?= e($r['status_color'] ?: '#ccc') ?>"></span><?= e($r['status_name']) ?>
          <?php else: ?>
            <span class="muted">not started</span>
          <?php endif; ?>
          <?php if ((int)($r['is_blocked'] ?? 0) === 1): ?><small class="warn-text">blocked</small><?php endif; ?>
        </td>
        <td class="<?= $r['end_date'] < $today ? 'muted' : '' ?>"><?= e($r['end_date']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
