<?php
// The list behind one number on the projects list: Departments, Shots, Assets or
// Head count (David, 2026-11-17: "can we make these clickable to show the list
// of that count?").
//
// Built like project_step_items.php and dashboard_crew_tasks.php: loaded on
// click, answered as an HTML fragment so the escaping stays in PHP, opened under
// the row by assets/progress-lists.js. The lists come from includes/
// project_lists.php, which also holds the head-count rule index.php counts with.
//
// Doors: feature_request_project() refuses a show this account is not on (it
// runs in require_login()); may_see_project() is checked again here. A link out
// of the list is drawn only when the account may open the screen it leads to.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/project_lists.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_GET['project'] ?? 0);
$which     = (string)($_GET['what'] ?? '');
$labels    = ['departments' => 'departments', 'shots' => 'shots', 'assets' => 'assets', 'people' => 'people'];

if (!isset($labels[$which]) || $projectId <= 0 || !may_see_project($pdo, $projectId)) {
    http_response_code(404);
    echo '<p class="muted">That list is not one this page can show.</p>';
    exit;
}

$on = function (string $feature): bool {
    return !function_exists('feature_on') || feature_on($feature);
};

if ($which === 'departments')  $rows = project_departments_list($pdo, $projectId);
elseif ($which === 'shots')    $rows = project_shots_list($pdo, $projectId);
elseif ($which === 'assets')   $rows = project_assets_list($pdo, $projectId);
else {
    try { $rows = head_count_people($pdo, $projectId); }
    catch (Throwable $e) {
        echo '<p class="muted">The head count could not be listed.</p>';
        exit;
    }
}
?>
<div class="psi-head">
  <strong><?= count($rows) ?></strong> <?= e($labels[$which]) ?>
  <button type="button" class="psi-close" aria-label="Close this list">×</button>
</div>
<?php if (!$rows): ?>
  <p class="muted">Nothing here.</p>
<?php elseif ($which === 'departments'): $link = $on('dashboard'); ?>
  <table class="psi-table">
    <thead><tr><th>Department</th><th>Applies to</th><th>Deadline</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><span class="dot" style="background:<?= e($r['color'] ?: '#ccc') ?>"></span><?php if ($link): ?><a href="step.php?project=<?= $projectId ?>&amp;step=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif; ?></td>
        <td class="muted"><?= e(applies_to_phrase((string)$r['applies_to'], $r['applies_to_asset_type'])) ?></td>
        <td class="muted"><?= e($r['deadline'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php elseif ($which === 'shots'): $link = $on('production'); ?>
  <table class="psi-table">
    <thead><tr><th>Shot</th><th>Frames</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $label = shot_label($r['episode'] ?? '', $r['shot_code'] ?? ''); ?>
      <tr>
        <td><?php if ($link): ?><a href="item.php?project=<?= $projectId ?>&amp;item=<?= e(urlencode('s' . (int)$r['id'])) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></td>
        <td class="muted"><?= $r['frames'] !== null ? (int)$r['frames'] : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php elseif ($which === 'assets'): $link = $on('production'); ?>
  <table class="psi-table">
    <thead><tr><th>Asset</th><th>Type</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php if ($link): ?><a href="item.php?project=<?= $projectId ?>&amp;item=<?= e(urlencode('a' . (int)$r['id'])) ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif; ?></td>
        <td class="muted"><?= e($r['asset_type'] !== null && $r['asset_type'] !== '' ? $r['asset_type'] : 'No type set') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php else: $link = $on('staffing'); ?>
  <table class="psi-table">
    <thead><tr><th>Person</th><th>Position on the show</th><th>Engaged</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $pos = trim((string)($r['positions'] ?? ''));
      if ($pos === '') $pos = trim((string)($r['role'] ?? ''));
    ?>
      <tr>
        <td><?php if ($link): ?><a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif; ?></td>
        <td><?= $pos !== '' ? e($pos) : '<span class="muted">—</span>' ?></td>
        <td class="muted"><?php if ($r['start_date'] || $r['end_date']): ?><?= e($r['start_date'] ?: '…') ?> → <?= e($r['end_date'] ?: 'open') ?><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
