<?php
// Project hub — where everything about one project is reachable from.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require __DIR__ . '/includes/names.php';
require_login();

$projectId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$shotCount  = (int)$pdo->query("SELECT COUNT(*) FROM shots  WHERE project_id = $projectId")->fetchColumn();
$assetCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE project_id = $projectId")->fetchColumn();

$assetTypeCounts = [];
$stmt = $pdo->prepare(
    "SELECT COALESCE(asset_type, '') AS t, COUNT(*) AS n FROM assets WHERE project_id = ? GROUP BY asset_type"
);
$stmt->execute([$projectId]);
foreach ($stmt as $row) $assetTypeCounts[$row['t']] = (int)$row['n'];

$stmt = $pdo->prepare(
    'SELECT ps.id, ps.name, ps.applies_to, ps.applies_to_asset_type, ps.color, ps.deadline,
            COUNT(pr.id)              AS tracked,
            COALESCE(SUM(pr.is_delivered), 0) AS delivered,
            COALESCE(SUM(pr.is_approved), 0)  AS approved
       FROM pipeline_steps ps
       LEFT JOIN progress pr ON pr.step_id = ps.id
      WHERE ps.project_id = ?
      GROUP BY ps.id, ps.name, ps.applies_to, ps.applies_to_asset_type, ps.color, ps.deadline, ps.step_order
      ORDER BY ps.step_order, ps.id'
);
$stmt->execute([$projectId]);
$steps = $stmt->fetchAll();

// Blocked work is remaining work, but it needs someone to unblock it rather than
// an artist — so it is counted separately everywhere it appears.
$blocked = (int)$pdo->query(
    "SELECT COUNT(*) FROM progress pr
       JOIN statuses s ON s.id = pr.status_id
      WHERE pr.project_id = $projectId AND s.is_blocked = 1"
)->fetchColumn();

$unmatchedPeople  = count(unmatched_assignees($pdo, $projectId));
$unmappedStatuses = count(unmapped_statuses($pdo, $projectId));

// Whether a schedule exists yet, for the hub card. Wrapped because the schedule
// table is empty until Phase 4's migration has been applied and the pass run —
// an unscheduled project is the normal state, not an error.
// Crew slots, for the hub card. Guarded rather than assumed: crew_lanes arrives
// in migration 012 and a project with no slots yet is the normal starting state.
$slotCount = 0; $slotsUnfilled = 0;
try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*), COALESCE(SUM(c.is_placeholder), 0)
           FROM crew_lanes cl JOIN crew c ON c.id = cl.crew_id
          WHERE cl.project_id = ?'
    );
    $stmt->execute([$projectId]);
    if ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $slotCount     = (int)$row[0];
        $slotsUnfilled = (int)$row[1];
    }
} catch (PDOException $e) {
    // Migration pending; the header already says so.
}

$scheduled = 0; $scheduleEnd = null;
$stmt = $pdo->prepare('SELECT COUNT(*), MAX(end_date) FROM schedule WHERE project_id = ?');
$stmt->execute([$projectId]);
if ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $scheduled   = (int)$row[0];
    $scheduleEnd = $row[1];
}

$pageTitle = $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← All projects</a></p>

<h2 class="wizard-title">
  <?= e($project['name']) ?> <span class="muted">(<?= e($project['code']) ?>)</span>
  <span class="status status-<?= e($project['status']) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
</h2>

<p class="summary-line">
  <?= e($project['type']) ?> ·
  <strong><?= $shotCount ?></strong> shots ·
  <strong><?= $assetCount ?></strong> assets ·
  <?= e($project['start_date'] ?: '—') ?> → <?= e($project['delivery_date'] ?: '—') ?>
</p>

<?php if ($unmatchedPeople || $unmappedStatuses): ?>
  <div class="flash flash-warn">
    <?php if ($unmatchedPeople): ?>
      <strong><?= $unmatchedPeople ?></strong> assignee name<?= $unmatchedPeople === 1 ? '' : 's' ?>
      didn't match anyone in the crew list.
    <?php endif; ?>
    <?php if ($unmappedStatuses): ?>
      <strong><?= $unmappedStatuses ?></strong> status value<?= $unmappedStatuses === 1 ? '' : 's' ?>
      arrived without a meaning.
    <?php endif; ?>
    <a href="aliases.php?project=<?= $projectId ?>">Review them →</a>
  </div>
<?php endif; ?>

<div class="hub-grid">
  <a class="hub-card" href="wizard.php?project=<?= $projectId ?>&step=1">
    <strong>Setup</strong>
    <span class="muted">Details, pipeline, workflow, shots, assets, dates</span>
  </a>
  <a class="hub-card" href="schedule.php?project=<?= $projectId ?>">
    <strong>Schedule</strong>
    <span class="muted">
      <?php if ($scheduled): ?>
        <?= $scheduled ?> task<?= $scheduled === 1 ? '' : 's' ?> planned
        <?php if ($scheduleEnd): ?>· finishes <?= e($scheduleEnd) ?><?php endif; ?>
      <?php else: ?>
        Work forwards from the pipeline to real dates
      <?php endif; ?>
    </span>
  </a>
  <a class="hub-card" href="crew.php?project=<?= $projectId ?>">
    <strong>Crew</strong>
    <span class="muted">
      <?php if ($slotCount): ?>
        <?= $slotCount ?> slot<?= $slotCount === 1 ? '' : 's' ?><?php
          if ($slotsUnfilled): ?> · <?= $slotsUnfilled ?> still a placeholder<?php endif; ?>
      <?php else: ?>
        Who is on this show — real people and planned slots
      <?php endif; ?>
    </span>
  </a>
  <a class="hub-card" href="import.php?project=<?= $projectId ?>">
    <strong>Import progress</strong>
    <span class="muted">Read a Plaky export and update what's tracked</span>
  </a>
  <a class="hub-card" href="statuses_edit.php?project=<?= $projectId ?>">
    <strong>Statuses</strong>
    <span class="muted">What each state means for reporting</span>
  </a>
  <a class="hub-card" href="export.php?project=<?= $projectId ?>">
    <strong>Export setup (CSV)</strong>
    <span class="muted">Work, days and headcount per step — the numbers an expense
      projection is built from</span>
  </a>
  <a class="hub-card" href="aliases.php?project=<?= $projectId ?>">
    <strong>Names &amp; aliases</strong>
    <span class="muted">
      <?php if ($unmatchedPeople || $unmappedStatuses): ?>
        <span class="warn-text"><?= $unmatchedPeople + $unmappedStatuses ?> need attention</span>
      <?php else: ?>
        Everything matched
      <?php endif; ?>
    </span>
  </a>
</div>

<h3>Progress by step</h3>
<?php if (!$steps): ?>
  <p class="muted">No pipeline steps yet — <a href="wizard.php?project=<?= $projectId ?>&step=2">add them</a>.</p>
<?php else: ?>
  <?php if ($blocked): ?>
    <p class="flash flash-warn"><strong><?= $blocked ?></strong> tracked
      <?= $blocked === 1 ? 'task is' : 'tasks are' ?> blocked — they still count as work
      remaining, but they need unblocking rather than an artist.</p>
  <?php endif; ?>
  <table class="review-table">
    <tr><th>Step</th><th>Applies to</th><th>Tracked</th><th>Delivered</th><th>Approved</th><th>Deadline</th></tr>
    <?php foreach ($steps as $s):
      // A whole-project step is one deliverable; a type-scoped step counts only
      // the assets of that type, never all of them.
      $type = $s['applies_to_asset_type'] ?? null;
      if ($s['applies_to'] === 'project') {
          $applicable = 1;
      } else {
          $applicable = 0;
          if (in_array($s['applies_to'], ['shot','both'], true))  $applicable += $shotCount;
          if (in_array($s['applies_to'], ['asset','both'], true)) {
              $applicable += ($type !== null && $type !== '')
                  ? ($assetTypeCounts[$type] ?? 0)
                  : $assetCount;
          }
      }
    ?>
    <tr>
      <td class="wf-name"><span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span><?= e($s['name']) ?></td>
      <td class="muted"><?= e(applies_to_phrase($s['applies_to'], $type)) ?></td>
      <td><?= (int)$s['tracked'] ?> <span class="muted">of <?= $applicable ?></span></td>
      <td><?= (int)$s['delivered'] ?></td>
      <td><strong><?= (int)$s['approved'] ?></strong></td>
      <td class="muted"><?= e($s['deadline'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">"Tracked" is how many items have a progress record for that step —
     lower than the total simply means nothing has been recorded for the rest yet.
     <a href="export.php?project=<?= $projectId ?>">Export these figures as CSV</a>, or
     <a href="export.php?project=<?= $projectId ?>&amp;what=items">export the shot and asset
     list</a>.</p>
<?php endif; ?>

</div>
</body></html>
