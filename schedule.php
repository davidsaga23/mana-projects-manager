<?php
// Phase 4 — the scheduler screen.
//
// Shows the plan the forward pass WOULD write, in full, before anything is
// saved. Same dry-run-first convention as the importer and the migration
// runner, and for the same reason: several STFC bugs presented as "nothing
// happened", so every action here says what it did, what it skipped and why.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
// require_once, not require: functions.php already pulls calendar.php in, and
// loading it a second time would redeclare every function in it.
require_once __DIR__ . '/includes/calendar.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/quota.php';
require __DIR__ . '/includes/scheduler.php';
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

// How many rows the per-item listing will print before it stops. A 200-shot show
// with six steps is over a thousand tasks; rendering all of them makes the page
// slow and the summary above it unreadable.
const SCHEDULE_ROW_LIMIT = 400;

// The scheduler writes columns that arrive in migration 010. A plan can still be
// computed and shown without them — it is only saving that would fail — so the
// preview stays available and the button is what disappears, with a reason.
$schemaReady = column_exists($pdo, 'schedule', 'source')
            && column_exists($pdo, 'schedule', 'lane');

// --- actions ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!$schemaReady) {
            throw new RuntimeException(
                'The database updates this screen needs have not been applied yet, so nothing ' .
                'was saved. An administrator can apply them from the Migrations screen.'
            );
        }
        if ($action === 'commit') {
            $plan = build_schedule($pdo, $project);
            if (!$plan['tasks']) {
                throw new RuntimeException('There is nothing to save — the plan came out empty. ' .
                                           'The reasons are listed on the screen.');
            }
            $res = commit_schedule($pdo, $projectId, $plan);
            flash('ok', sprintf(
                'Saved %d scheduled task%s, replacing %d from the previous run. %s',
                $res['written'], $res['written'] === 1 ? '' : 's', $res['deleted'],
                $plan['kept'] ? count($plan['kept']) . ' hand-planned task' .
                                (count($plan['kept']) === 1 ? ' was' : 's were') . ' left untouched.'
                              : 'No hand-planned tasks were affected.'
            ));
            foreach ($plan['problems'] as $p) flash('warn', $p);
        } elseif ($action === 'clear') {
            $del = $pdo->prepare(
                "DELETE FROM schedule WHERE project_id = ? AND is_manual = 0 AND source = 'auto'"
            );
            $del->execute([$projectId]);
            flash('ok', 'Removed ' . $del->rowCount() . ' automatically scheduled task(s). ' .
                        'Hand-planned tasks were kept.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: schedule.php?project=' . $projectId);
    exit;
}

// --- what is saved right now -------------------------------------------------
// Counted rather than fetched: a real show is a four-figure number of rows, and
// this line only ever prints four numbers.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS n, SUM(is_manual) AS manual, MIN(start_date) AS first, MAX(end_date) AS last
       FROM schedule WHERE project_id = ?'
);
$stmt->execute([$projectId]);
$saved = $stmt->fetch() ?: ['n' => 0, 'manual' => 0, 'first' => null, 'last' => null];
$savedCount  = (int)$saved['n'];
$savedManual = (int)$saved['manual'];
$savedAuto   = $savedCount - $savedManual;

// --- the plan ----------------------------------------------------------------
$plan = build_schedule($pdo, $project);
$holidays = non_working_days($pdo);

$pageTitle = 'Schedule · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>

<h2 class="wizard-title">Schedule</h2>

<p class="lead">
  Working forwards from the pipeline: every step waits for the ones before it, the work is
  spread across as many people as are planned for it, and the finish date is whatever falls
  out. Nothing below is saved until you press the button.
</p>

<?php
// --- saved vs. previewed ----------------------------------------------------
// These are two different things and the page shows both at once, so the line
// has to say WHICH it is talking about. "No schedule" on its own, printed
// directly above a full plan with dates in it, reads as a failure rather than
// as "you haven't pressed the button yet".
?>
<div class="summary-line">
  <?php if ($savedCount): ?>
    <strong>Saved:</strong> <?= $savedCount ?> task<?= $savedCount === 1 ? '' : 's' ?>
    <?php if ($savedManual): ?>
      (<?= $savedManual ?> hand-planned)
    <?php endif; ?>
    · <?= e($saved['first']) ?> → <?= e($saved['last']) ?>.
    The plan below is worked out fresh each time this page opens, so it may differ from what
    is stored — saving again replaces it.
  <?php else: ?>
    <strong>Nothing saved yet.</strong>
    Everything below is a preview, worked out fresh just now and thrown away when you leave.
    <?php if ($plan['tasks']): ?>
      Press <em>Save this schedule</em> at the bottom to keep it.
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($plan['problems'] as $p): ?>
  <div class="flash flash-err"><?= e($p) ?></div>
<?php endforeach; ?>
<?php foreach ($plan['notes'] as $n): ?>
  <div class="flash flash-warn"><?= e($n) ?></div>
<?php endforeach; ?>

<?php
// A condition that removes the save button has to be stated BEFORE the plan,
// not underneath it. Hidden further down, it reads as the button simply not
// existing.
?>
<?php if (!$schemaReady): ?>
  <div class="flash flash-err">
    This plan can be looked at but not saved: the database updates the scheduler needs
    haven't been applied, so there is nowhere to put it.
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>'
                   : 'Ask an administrator to run them.' ?>
  </div>
<?php endif; ?>

<?php if (!$holidays): ?>
  <div class="flash flash-warn">
    No public holidays are recorded, so every weekday counts as a working day.
    <a href="calendar.php">Set up the calendar →</a>
  </div>
<?php endif; ?>

<?php if ($plan['tasks']): ?>

  <div class="sched-headline">
    <div>
      <span class="muted small">Starts</span>
      <strong><?= e($plan['anchor']) ?></strong>
    </div>
    <div>
      <span class="muted small">Finishes</span>
      <strong class="<?= $project['delivery_date'] && $plan['end'] > $project['delivery_date'] ? 'warn-text' : '' ?>">
        <?= e($plan['end']) ?>
      </strong>
    </div>
    <div>
      <span class="muted small">Delivery date</span>
      <strong><?= e($project['delivery_date'] ?: '—') ?></strong>
    </div>
    <div>
      <span class="muted small">Tasks</span>
      <strong><?= count($plan['tasks']) ?></strong>
    </div>
  </div>

  <h3>By step</h3>
  <table class="review-table sched-table">
    <tr>
      <th>Step</th>
      <th title="What the plan is made of: one row per shot or asset, or a single band where the detail isn't known yet.">Planned as</th>
      <th>Work left</th>
      <th title="How many people run side by side. Taken from the planned headcount; derived from the deadline when none is set.">Lanes</th>
      <th>Starts</th><th>Finishes</th><th>Deadline</th>
    </tr>
    <?php foreach ($plan['steps'] as $stepId => $s):
      $step = $s['step'];
      $late = $s['late_days'] > 0;
    ?>
    <tr class="<?= $late ? 'has-problem' : '' ?>">
      <td class="wf-name">
        <span class="dot" style="background:<?= e($step['color'] ?: '#ccc') ?>"></span><?= e($step['name']) ?>
        <span class="muted small"><?= e(applies_to_phrase($step['applies_to'], $step['applies_to_asset_type'] ?? null)) ?></span>
      </td>
      <td>
        <?php if ($s['scheduled'] === 0): ?>
          <span class="muted">nothing to place</span>
        <?php elseif ($s['mode'] === 'band'): ?>
          <span class="est-tag">one band</span>
        <?php elseif ($s['mode'] === 'project'): ?>
          <span class="muted">one deliverable</span>
        <?php else: ?>
          <?= (int)$s['scheduled'] ?> <span class="muted">of <?= (int)$s['items_total'] ?></span>
        <?php endif; ?>
        <?php
        // Anything dropped from the plan is named here rather than just going
        // missing — "nothing happened" is the failure mode this app fights.
        $dropped = [];
        if ($s['done'])     $dropped[] = $s['done'] . ' approved';
        if ($s['excluded']) $dropped[] = $s['excluded'] . ' not applicable';
        if ($s['manual'])   $dropped[] = $s['manual'] . ' hand-planned';
        if ($dropped): ?>
          <div class="muted small"><?= e(implode(' · ', $dropped)) ?></div>
        <?php endif; ?>
        <?php if ($s['blocked']): ?>
          <div class="warn-text small"><?= (int)$s['blocked'] ?> blocked</div>
        <?php endif; ?>
      </td>
      <td><?= $s['work_days'] > 0 ? e(fmt_rate((float)$s['work_days'])) . ' <span class="muted">days</span>' : '<span class="muted">—</span>' ?></td>
      <td>
        <?= (int)$s['lanes'] ?>
        <span class="muted small">
          <?= $s['lane_source'] === 'planned' ? 'planned'
             : ($s['lane_source'] === 'deadline' ? 'from the date'
             : ($s['lane_source'] === 'capped' ? 'capped' : 'assumed')) ?>
        </span>
      </td>
      <td class="muted"><?= e($s['start_date'] ?: '—') ?></td>
      <td class="<?= $late ? 'warn-text' : 'muted' ?>"><?= e($s['end_date'] ?: '—') ?></td>
      <td class="muted">
        <?= e($s['deadline'] ?: '—') ?>
        <?php if ($s['deadline'] && !$s['deadline_own']): ?>
          <span class="muted small">(project)</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($late): ?>
      <tr class="problem-row">
        <td></td>
        <td colspan="6" class="warn-text">
          Finishes <?= (int)$s['late_days'] ?> working day<?= $s['late_days'] === 1 ? '' : 's' ?>
          past its deadline. Either the date moves, or this step needs more people than the
          <?= (int)$s['lanes'] ?> planned.
        </td>
      </tr>
    <?php endif; ?>
    <?php foreach ($s['notes'] as $note): ?>
      <tr class="problem-row">
        <td></td>
        <td colspan="6" class="muted"><?= e($note) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </table>

  <?php // --- who is on what ------------------------------------------- ?>
  <h3>Lanes</h3>
  <p class="muted small">
    Names are placeholders derived from the step and how many people are on it — there is no
    record yet of who does which discipline, and a real name on a task would read as a real
    assignment. They become people when the crew screen exists.
  </p>
  <div class="lane-list">
    <?php foreach ($plan['steps'] as $s): if ($s['scheduled'] === 0) continue; ?>
      <?php for ($i = 1; $i <= $s['lanes']; $i++): ?>
        <span class="lane-chip">
          <span class="dot" style="background:<?= e($s['step']['color'] ?: '#ccc') ?>"></span>
          <?= e(lane_label($s['step']['name'], $i, (int)$s['lanes'])) ?>
        </span>
      <?php endfor; ?>
    <?php endforeach; ?>
  </div>

  <?php // --- the tasks themselves -------------------------------------- ?>
  <h3>Every task</h3>
  <?php if (count($plan['tasks']) > SCHEDULE_ROW_LIMIT): ?>
    <p class="muted small">
      Showing the first <?= SCHEDULE_ROW_LIMIT ?> of <?= count($plan['tasks']) ?> tasks, in date
      order. All of them are saved when you press the button — this listing is trimmed only to
      keep the page readable.
    </p>
  <?php endif; ?>
  <table class="review-table sched-tasks">
    <tr><th>Starts</th><th>Finishes</th><th>Step</th><th>Item</th><th>Lane</th></tr>
    <?php foreach (array_slice($plan['tasks'], 0, SCHEDULE_ROW_LIMIT) as $t): ?>
    <tr class="<?= $t['blocked'] ? 'has-problem' : '' ?>">
      <td class="muted"><?= e($t['start_date']) ?></td>
      <td class="muted"><?= e($t['end_date']) ?></td>
      <td class="wf-name">
        <span class="dot" style="background:<?= e($t['color'] ?: '#ccc') ?>"></span><?= e($t['step_name']) ?>
      </td>
      <td>
        <?= e($t['label']) ?>
        <?php if ($t['blocked']): ?>
          <span class="warn-text small">blocked</span>
        <?php endif; ?>
      </td>
      <td class="muted"><?= e($t['lane_label']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($plan['kept']): ?>
    <h3>Left alone</h3>
    <p class="muted small">
      Hand-planned tasks. The pass reads them — what comes after still waits for them — but
      never rewrites them.
    </p>
    <table class="review-table">
      <tr><th>Step</th><th>Item</th><th>Starts</th><th>Finishes</th></tr>
      <?php foreach ($plan['kept'] as $k): ?>
      <tr>
        <td class="wf-name"><?= e($k['step']) ?></td>
        <td><?= e($k['item']) ?></td>
        <td class="muted"><?= e($k['start_date']) ?></td>
        <td class="muted"><?= e($k['end_date']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($schemaReady && can_edit()): ?>
  <form method="post" class="actions">
    <input type="hidden" name="action" value="commit">
    <button type="submit">Save this schedule</button>
    <small class="muted">
      Replaces whatever the last run saved. Hand-planned tasks are never touched.
    </small>
  </form>
  <?php elseif (!can_edit()): ?>
    <p class="muted small">
      Your account has view-only access, so this plan can be read but not saved.
    </p>
  <?php endif; ?>

<?php else: ?>
  <p class="muted">
    There is nothing to schedule yet. The reasons are listed above — usually a missing
    breakdown, a missing deadline, or a pipeline with no steps in it.
  </p>
<?php endif; ?>

<?php if ($savedAuto > 0 && can_edit() && $schemaReady): ?>
  <form method="post" class="actions danger-actions"
        onsubmit="return confirm('Remove the <?= $savedAuto ?> automatically scheduled task(s)? Hand-planned tasks are kept.');">
    <input type="hidden" name="action" value="clear">
    <button type="submit" class="secondary">Clear the saved schedule</button>
    <small class="muted">Removes only what the scheduler wrote.</small>
  </form>
<?php endif; ?>

<p class="muted small">
  Non-working days come from the <a href="calendar.php">studio calendar</a>
  (<?= count($holidays) ?> recorded). Both dates on every task are inclusive: a task that
  starts and finishes on the same day is one day of work.
</p>

</div>
</body></html>
