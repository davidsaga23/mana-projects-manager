<?php
// Phase 5 — the timeline.
//
// Reads the SAVED schedule, not a fresh calculation. That is the difference
// between this screen and schedule.php: there you look at what the plan would
// be, here you look at what the plan IS. A Gantt that quietly recomputed itself
// on every visit would be a picture of a plan nobody agreed to.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/scheduler.php';
require_once __DIR__ . '/includes/timeline.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$schedule = stored_schedule($pdo, $projectId);
$rows     = timeline_rows($schedule);

// Step deadlines, for the summary underneath — a bar that runs past its own
// deadline is the thing worth spotting.
$steps = pipeline_steps($pdo, $projectId);
$stepById = [];
foreach ($steps as $s) $stepById[(int)$s['id']] = $s;

$today = date('Y-m-d');
$late  = [];
foreach ($rows as $r) {
    $step = $stepById[$r['step_id']] ?? null;
    $deadline = $step && $step['deadline'] ? $step['deadline'] : ($project['delivery_date'] ?: null);
    if ($deadline && $r['end'] > $deadline) {
        $late[] = ['row' => $r, 'deadline' => $deadline,
                   'days' => working_days_after($deadline, $r['end'])];
    }
}

$svg = timeline_svg($rows, [
    'today'         => $today,
    'delivery_date' => $project['delivery_date'] ?: null,
]);

$pageTitle = 'Timeline · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>

<h2 class="wizard-title">Timeline</h2>

<?php if (!$schedule): ?>
  <div class="empty-state">
    <h2>Nothing scheduled yet</h2>
    <p>The timeline draws the schedule that has been saved. Work one out and save it, and it
       appears here.</p>
    <p><a class="button" href="schedule.php?project=<?= $projectId ?>">Open the schedule →</a></p>
  </div>
<?php else: ?>

  <p class="lead">
    One row per person — a placeholder slot until somebody is hired into it. Gaps in a row are
    days that person has nothing scheduled. This is the <strong>saved</strong> plan; changing
    an estimate doesn't move it until you re-save the schedule.
  </p>

  <div class="summary-line">
    <strong><?= count($schedule) ?></strong> task<?= count($schedule) === 1 ? '' : 's' ?>
    across <strong><?= count($rows) ?></strong> <?= count($rows) === 1 ? 'person' : 'people' ?>
    · <?= e(min(array_column($schedule, 'start_date'))) ?>
    → <?= e(max(array_column($schedule, 'end_date'))) ?>
    <?php if ($project['delivery_date']): ?>
      · delivery <?= e($project['delivery_date']) ?>
    <?php endif; ?>
  </div>

  <?php if ($late): ?>
    <div class="flash flash-warn">
      <strong><?= count($late) ?></strong>
      <?= count($late) === 1 ? 'row runs' : 'rows run' ?> past
      <?= count($late) === 1 ? 'its' : 'their' ?> deadline —
      <?php
      $bits = [];
      foreach (array_slice($late, 0, 6) as $l) {
          $bits[] = $l['row']['label'] . ' (+' . $l['days'] . 'd)';
      }
      echo e(implode(', ', $bits)) . (count($late) > 6 ? ' …' : '');
      ?>.
    </div>
  <?php endif; ?>

  <div class="gantt-wrap">
    <?= $svg ?>
  </div>

  <p class="muted small">
    Shaded columns are weekends and days on the
    <a href="calendar.php">studio calendar</a>. Both dates on every bar are inclusive — a bar
    one day wide is one day of work. Hover a bar for the shot or asset it covers.
    <?php
    $manual = 0;
    foreach ($rows as $r) $manual += $r['manual'];
    if ($manual): ?>
      <?= $manual ?> hand-planned task<?= $manual === 1 ? '' : 's' ?>
      <?= $manual === 1 ? 'is' : 'are' ?> outlined — the scheduler never rewrites those.
    <?php endif; ?>
  </p>

  <div class="actions">
    <a class="button" href="schedule.php?project=<?= $projectId ?>">Recalculate the schedule</a>
  </div>

<?php endif; ?>

</div>
</body></html>
