<?php
// The project dashboard — what needs attention today.
//
// Deliberately not "every number we have". Every figure here is a COMPARISON,
// because a number on its own ("47 approved") tells you nothing without the one
// it should be measured against. Anything that can't be compared belongs on the
// screen that owns it, not here.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require __DIR__ . '/includes/names.php';
require_once __DIR__ . '/includes/dashboard.php';
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

$today    = date('Y-m-d');
$delivery = $project['delivery_date'] ?: null;

$steps    = dashboard_steps($pdo, $projectId, $delivery);
$pace     = dashboard_pace($pdo, $projectId, $delivery, $today);
$workload = dashboard_workload($pdo, $projectId, $today);

$sched = $pdo->prepare('SELECT MIN(start_date) s, MAX(end_date) e, COUNT(*) n FROM schedule WHERE project_id = ?');
$sched->execute([$projectId]);
$plan = $sched->fetch();

$totApplicable = 0; $totApproved = 0; $totDelivered = 0; $totBlocked = 0; $lateSteps = [];
foreach ($steps as $s) {
    $totApplicable += $s['applicable'];
    $totApproved   += min($s['approved'], $s['applicable']);
    $totDelivered  += min($s['delivered'], $s['applicable']);
    $totBlocked    += $s['blocked'];
    if ($s['late_days'] > 0) $lateSteps[] = $s;
}
$overallPct = $totApplicable > 0 ? round($totApproved / $totApplicable * 100) : null;

$daysLeft   = ($delivery && $delivery >= $today) ? working_days_between($today, $delivery) : null;
$overdue    = ($delivery && $delivery < $today);
$planOver   = ($delivery && $plan['e'] && $plan['e'] > $delivery)
              ? working_days_after($delivery, $plan['e']) : 0;

$unmatched  = count(unmatched_assignees($pdo, $projectId)) + count(unmapped_statuses($pdo, $projectId));

$pageTitle = 'Dashboard · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>
<h2 class="wizard-title">Dashboard <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php // --- the headline: three comparisons, not three numbers ---------- ?>
<div class="dash-strip">
  <div>
    <span class="muted small">Approved</span>
    <strong><?= $overallPct === null ? '—' : $overallPct . '%' ?></strong>
    <span class="muted small"><?= $totApproved ?> of <?= $totApplicable ?> tasks</span>
  </div>
  <div>
    <span class="muted small">Plan finishes</span>
    <strong class="<?= $planOver > 0 ? 'warn-text' : '' ?>"><?= e($plan['e'] ?: '—') ?></strong>
    <span class="muted small">
      <?php if ($planOver > 0): ?><?= $planOver ?> working days past delivery
      <?php elseif ($plan['e'] && $delivery): ?>inside the delivery date
      <?php else: ?>no schedule saved<?php endif; ?>
    </span>
  </div>
  <div>
    <span class="muted small">Delivery</span>
    <strong class="<?= $overdue ? 'warn-text' : '' ?>"><?= e($delivery ?: '—') ?></strong>
    <span class="muted small">
      <?php if ($overdue): ?>passed
      <?php elseif ($daysLeft !== null): ?><?= $daysLeft ?> working days left
      <?php else: ?>not set<?php endif; ?>
    </span>
  </div>
  <div>
    <span class="muted small">Blocked</span>
    <strong class="<?= $totBlocked > 0 ? 'warn-text' : '' ?>"><?= $totBlocked ?></strong>
    <span class="muted small">need unblocking, not an artist</span>
  </div>
</div>

<?php // --- needs attention ------------------------------------------------ ?>
<?php
$attention = [];
if ($planOver > 0) {
    $attention[] = ['The saved plan finishes ' . $planOver . ' working days after the delivery date.',
                    'schedule.php?project=' . $projectId, 'Look at the schedule'];
}
if ($lateSteps) {
    $names = array_map(function ($s) { return $s['step']['name']; }, array_slice($lateSteps, 0, 4));
    $attention[] = [count($lateSteps) . ' step' . (count($lateSteps) === 1 ? '' : 's') .
                    ' finish past their own deadline — ' . implode(', ', $names) .
                    (count($lateSteps) > 4 ? '…' : '') . '.',
                    'timeline.php?project=' . $projectId, 'See the timeline'];
}
if ($totBlocked > 0) {
    $attention[] = [$totBlocked . ' task' . ($totBlocked === 1 ? ' is' : 's are') .
                    ' blocked. They still count as work remaining, but somebody has to unblock them.',
                    'statuses_edit.php?project=' . $projectId, 'Check what counts as blocked'];
}
if ($unmatched > 0) {
    $attention[] = [$unmatched . ' imported name' . ($unmatched === 1 ? '' : 's') .
                    ' or status value didn\'t match anything, so that work is missing from reporting.',
                    'aliases.php?project=' . $projectId, 'Match them'];
}
if (!$plan['n']) {
    $attention[] = ['No schedule has been saved, so there are no dates to measure anything against.',
                    'schedule.php?project=' . $projectId, 'Work one out'];
}
?>
<?php if ($attention): ?>
  <h3>Needs attention</h3>
  <ul class="dash-attention">
    <?php foreach ($attention as [$text, $href, $label]): ?>
      <li><?= e($text) ?> <a href="<?= e($href) ?>"><?= e($label) ?> →</a></li>
    <?php endforeach; ?>
  </ul>
<?php else: ?>
  <p class="flash flash-ok">Nothing is overdue, blocked or unmatched.</p>
<?php endif; ?>

<?php // --- are we keeping up? --------------------------------------------- ?>
<h3>Are we keeping up?</h3>
<?php if (!$pace): ?>
  <p class="muted">
    Not enough history yet. Progress is recorded once a day, from the day the tracking went in
    — so this needs a few days before it can say anything true. It fills itself in.
  </p>
<?php else: ?>
  <div class="dash-pace">
    <div>
      <span class="muted small">Finished per day, actually</span>
      <strong><?= e(fmt_rate($pace['actual_per_day'])) ?></strong>
      <span class="muted small">over <?= (int)$pace['span_days'] ?> working days</span>
    </div>
    <div>
      <span class="muted small">Needed per day, from here</span>
      <strong class="<?= ($pace['needed_per_day'] !== null && $pace['needed_per_day'] > $pace['actual_per_day'])
                        ? 'warn-text' : '' ?>">
        <?= $pace['needed_per_day'] === null ? '—' : e(fmt_rate($pace['needed_per_day'])) ?>
      </strong>
      <span class="muted small">
        <?= (int)$pace['remaining'] ?> left<?= $pace['days_left'] ? ' in ' . (int)$pace['days_left'] . ' days' : '' ?>
      </span>
    </div>
  </div>

  <?php if (!$pace['confident']): ?>
    <p class="muted small">Two readings is arithmetic, not a trend — treat it loosely until
       there are a few more days behind it.</p>
  <?php elseif ($pace['needed_per_day'] !== null): ?>
    <p class="<?= $pace['needed_per_day'] > $pace['actual_per_day'] ? 'warn-text' : 'muted' ?>">
      <?php if ($pace['needed_per_day'] > $pace['actual_per_day']): ?>
        At the current rate this doesn't reach the delivery date. Either the rate rises, more
        people join, or the date moves.
      <?php else: ?>
        The current rate is enough to reach the delivery date.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?= dashboard_burnup_svg($pace['points'], $delivery) ?>
  <p class="muted small">Approved work over time. The flat line at the top is everything that
     has to be finished; the vertical line is the delivery date.</p>
<?php endif; ?>

<?php // --- per step -------------------------------------------------------- ?>
<h3>Progress by step</h3>
<table class="review-table dash-steps">
  <tr><th>Step</th><th>Progress</th><th>Approved</th><th>Scheduled</th><th>Deadline</th></tr>
  <?php foreach ($steps as $s): ?>
  <tr class="<?= $s['late_days'] > 0 ? 'has-problem' : '' ?>">
    <td class="wf-name">
      <span class="dot" style="background:<?= e($s['step']['color'] ?: '#ccc') ?>"></span>
      <?= e($s['step']['name']) ?>
      <span class="muted small"><?= e(applies_to_phrase($s['step']['applies_to'], $s['step']['applies_to_asset_type'] ?? null)) ?></span>
    </td>
    <td>
      <?php // Two bands in one bar: approved solid, delivered-but-not-approved
            // lighter behind it. The gap between them is work sitting in review. ?>
      <div class="pbar" title="<?= (int)$s['approved'] ?> approved, <?= (int)$s['delivered'] ?> delivered, of <?= (int)$s['applicable'] ?>">
        <span class="pbar-delivered" style="width:<?= (int)($s['pct_delivered'] ?? 0) ?>%"></span>
        <span class="pbar-approved" style="width:<?= (int)($s['pct'] ?? 0) ?>%;background:<?= e($s['step']['color'] ?: '#4A90D9') ?>"></span>
      </div>
      <?php if ($s['blocked']): ?>
        <span class="warn-text small"><?= (int)$s['blocked'] ?> blocked</span>
      <?php endif; ?>
    </td>
    <td><strong><?= $s['pct'] === null ? '—' : $s['pct'] . '%' ?></strong>
      <span class="muted small"><?= (int)$s['approved'] ?>/<?= (int)$s['applicable'] ?></span></td>
    <td class="muted small">
      <?php if ($s['sched_n']): ?>
        <?= e($s['sched_start']) ?> → <?= e($s['sched_end']) ?>
      <?php else: ?>—<?php endif; ?>
    </td>
    <td class="<?= $s['late_days'] > 0 ? 'warn-text' : 'muted' ?>">
      <?= e($s['deadline'] ?: '—') ?>
      <?php if ($s['late_days'] > 0): ?><div class="small">+<?= (int)$s['late_days'] ?>d</div><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<?php // --- workload -------------------------------------------------------- ?>
<h3>Who is carrying what</h3>
<?php if (!$workload): ?>
  <p class="muted">
    No scheduled task has anyone on it yet. Slots get filled from the headcount on the
    <a href="crew.php?project=<?= $projectId ?>">crew screen</a>, and the schedule assigns to them.
  </p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Who</th><th>Tasks</th><th>Still to do</th><th>Runs</th></tr>
    <?php foreach ($workload as $w): ?>
    <tr>
      <td><strong><?= e($w['name']) ?></strong>
        <?php if ((int)$w['is_placeholder'] === 1): ?><span class="est-tag">slot</span><?php endif; ?></td>
      <td><?= (int)$w['tasks'] ?></td>
      <td><strong><?= (int)$w['remaining'] ?></strong></td>
      <td class="muted small"><?= e($w['first_start']) ?> → <?= e($w['last_end']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">
    "Still to do" counts tasks whose end date hasn't passed. It reads the schedule, not
    what anyone has reported finishing — those agree only when the schedule is up to date.
  </p>
<?php endif; ?>

</div>
</body></html>
