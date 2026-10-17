<?php
// The project dashboard.
//
// Shaped after STFC's, which is the version that got used every morning. Its
// governing idea, kept: every figure is a COMPARISON. "47 approved" tells you
// nothing; "47 of 188, needing 11.4 a day against the 8.2 you are managing"
// tells you what to do today.
//
// Layout, top to bottom: the one step that carries the show, then a card per
// step with its quota, then the grids, then who is carrying what.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require __DIR__ . '/includes/names.php';
require_once __DIR__ . '/includes/dashboard.php';
require_once __DIR__ . '/includes/timelog.php';
require_once __DIR__ . '/includes/activity.php';
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
$episodic = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';

$steps    = dashboard_steps($pdo, $projectId, $delivery);

// This project's ideal quotas. Set on the business side (Days per item on
// Dates & headcount, admin only) and read here — David's split, 2026-08-17: the number is a business
// decision and it is useless unless the people running the show can see it.
// Read-only; nothing on this screen can change one. Per step, per project since
// 2026-09-17, through effective_days_per_unit() like every other reader.
require_once __DIR__ . '/includes/standards.php';
$visible  = array_values(array_filter($steps, function ($s) {
    return (int)($s['step']['show_in_dashboard'] ?? 1) === 1;
}));
$tables   = dashboard_breakdown($pdo, $projectId, $visible, $episodic);
$workload = dashboard_workload($pdo, $projectId, $today);
$pace     = dashboard_pace($pdo, $projectId, $delivery, $today);

// No step gets promoted out of the grid. STFC singled out its seconds-tracked
// step because one step carried that whole show; here the headline is the
// PROJECT, and every step — animatic included — is a card of the same size.
$others = $visible;
$overall = dashboard_overall($steps, $delivery, $pace, $today);

$shotCount  = (int)$pdo->query("SELECT COUNT(*) FROM shots  WHERE project_id = $projectId")->fetchColumn();
$assetCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE project_id = $projectId")->fetchColumn();
$runtime    = (float)$pdo->query("SELECT COALESCE(SUM(placeholder_seconds),0) FROM shots WHERE project_id = $projectId")->fetchColumn();

$totBlocked = 0; $lateSteps = [];
foreach ($steps as $s) {
    $totBlocked += $s['blocked'];
    if ($s['late_days'] > 0) $lateSteps[] = $s;
}
$unmatched = count(unmatched_assignees($pdo, $projectId)) + count(unmapped_statuses($pdo, $projectId));

$sched = $pdo->prepare('SELECT MAX(end_date) e, COUNT(*) n FROM schedule WHERE project_id = ?');
$sched->execute([$projectId]);
$plan = $sched->fetch();
$planOver = ($delivery && $plan['e'] && $plan['e'] > $delivery)
          ? working_days_after($delivery, $plan['e']) : 0;

// What moved today, grouped into the three phases Production tracking uses.
// ?day= looks back; the picker only offers days that actually have something,
// so an empty day is never mistaken for a broken screen.
$activityDays = activity_days($pdo, $projectId, 30);
$activityDay  = $_GET['day'] ?? ($activityDays[0]['log_date'] ?? $today);
$activity     = activity_for_day($pdo, $projectId, $activityDay);
$activityTotal = count($activity['asset']) + count($activity['shot']) + count($activity['project']);

$pageTitle = 'Dashboard · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'dashboard'); ?>

<div class="dash-hero">
  <div>
    <h2 class="wizard-title"><?= e($project['name']) ?> <span class="muted">· Dashboard</span></h2>
    <p class="muted">
      <?= date('D M j, Y', strtotime($today)) ?> ·
      Delivery <strong><?= e($delivery ?: 'not set') ?></strong>
      <?php if ($runtime > 0): ?> · Runtime <strong><?= e(fmt_secs($runtime)) ?></strong><?php endif; ?>
      · <strong><?= $shotCount ?></strong> shots · <strong><?= $assetCount ?></strong> assets
    </p>
  </div>
  <div class="tl-controls">
    <button type="button" class="secondary" onclick="downloadDashboard()">PNG</button>
    <a class="button" href="timeline.php?project=<?= $projectId ?>">Timeline</a>
  </div>
</div>

<?php // --- what needs attention ------------------------------------------- ?>
<?php
$attention = [];
if (!$plan['n']) $attention[] = ['No schedule saved, so there are no dates to measure against.',
                                 'schedule.php?project=' . $projectId, 'Work one out'];
if ($planOver > 0) $attention[] = ['The saved plan finishes ' . $planOver . ' working days past delivery.',
                                   'schedule.php?project=' . $projectId, 'Look at the schedule'];
if ($lateSteps) {
    $n = array_map(function ($s) { return $s['step']['name']; }, array_slice($lateSteps, 0, 4));
    $attention[] = [count($lateSteps) . ' step' . (count($lateSteps) === 1 ? '' : 's') .
        ' finish past their own deadline, ' . implode(', ', $n) . (count($lateSteps) > 4 ? '…' : '') . '.',
        'timeline.php?project=' . $projectId, 'See the timeline'];
}
if ($totBlocked) $attention[] = [$totBlocked . ' task' . ($totBlocked === 1 ? ' is' : 's are') .
    ' blocked, they need unblocking, not an artist.', 'aliases.php?project=' . $projectId, 'Review'];
if ($unmatched) $attention[] = [$unmatched . ' imported name or status didn\'t match anything, so that ' .
    'work is missing from every number here.', 'aliases.php?project=' . $projectId, 'Match them'];
?>
<?php if ($attention): ?>
  <ul class="dash-attention">
    <?php foreach ($attention as [$text, $href, $label]): ?>
      <li><?= e($text) ?> <a href="<?= e($href) ?>"><?= e($label) ?> →</a></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php // --- the whole project, in one block --------------------------------- ?>
<?php render_overall_progress($overall); ?>

<?php // --- a card per step, each carrying its quota ------------------------ ?>
<h3>Every step, and what it needs per day</h3>
<div class="step-cards">
  <?php foreach ($others as $s): ?>
    <?php
    // FINISHED (David, 2026-09-22): everything that applies to this step has
    // been approved, so the card and its bar go green.
    //
    // It needs target > 0 as well as nothing to go. A step with nothing
    // applicable at all — every item marked N/A, or no items added yet — has
    // "0 to go" too, and painting that green would say the work was done when
    // there was never any work. Those cards stay as they were.
    $done = (float)$s['target'] > 0 && (float)$s['remaining'] <= 0.0001;
    ?>
    <article class="step-card <?= $done ? 'done' : ($s['overdue'] ? 'overdue' : '') ?>">
      <div class="step-card-head">
        <h4><a href="step.php?project=<?= $projectId ?>&step=<?= (int)$s['step']['id'] ?>"><?= e($s['step']['name']) ?></a></h4>
        <span class="unit-badge"><?= e(applies_to_phrase($s['step']['applies_to'], $s['step']['applies_to_asset_type'] ?? null)) ?></span>
      </div>
      <?php // The bar carries the step's own colour, as it does on the timeline,
            // until the step is finished: then it is green like everything else
            // that means done here. ?>
      <div class="mini-bar"><span style="width:<?= (float)($s['pct'] ?? 0) ?>%;background:<?= $done ? 'var(--ok)' : e($s['step']['color'] ?: 'var(--step-default)') ?>"></span></div>
      <div class="mini-num">
        <?= e(fmt_unit($s['approved_v'], $s['unit'])) ?><span class="mini-total">/<?= e(fmt_unit($s['target'], $s['unit'])) ?></span>
      </div>
      <div class="muted small">
        <?= e(fmt_unit($s['remaining'], $s['unit'])) ?> to go<?php if ($s['blocked']): ?>
          · <span class="warn-text"><?= (int)$s['blocked'] ?> blocked</span><?php endif; ?>
      </div>
      <?php if (!empty($s['seconds_missing'])): ?>
        <div class="muted small">Set to track seconds, but no shot has a duration, counted as
          items until they do.</div>
      <?php endif; ?>
      <div class="mini-pace">
        <?php // A finished step needs no pace: "0.00/day each across 2, 0 working
              // days left" is arithmetic about work that no longer exists. ?>
        <?php if ($done): ?>
          <span class="done-text">✓ Finished</span>
        <?php elseif ($s['overdue']): ?>
          <span class="warn-text">Overdue since <?= e($s['deadline']) ?></span>
        <?php elseif ($s['required_pace'] === null): ?>
          <span class="muted">No deadline set</span>
        <?php else: ?>
          Team needs <strong><?= e(fmt_unit((float)$s['required_pace'], $s['unit'])) ?>/day</strong>
          <?php if ($s['per_artist'] !== null): ?>
            <div class="muted small"><?= e(fmt_unit((float)$s['per_artist'], $s['unit'])) ?>/day each
              across <?= e(fmt_num($s['headcount'])) ?></div>
          <?php else: ?>
            <div class="muted small">no headcount set</div>
          <?php endif; ?>
          <?php
          // The ideal, read from the business side. Only shown when it DISAGREES
          // with the required pace — a card that repeats "and the ideal says the
          // same" on every step is noise, and the one card where they differ is
          // the one worth seeing.
          $idealDays = effective_days_per_unit($pdo, $projectId, $s['step']);
          if ($s['per_artist'] !== null && $idealDays > 0):
            $ideal = 1 / $idealDays;
            $ratio = (float)$s['per_artist'] / $ideal;
            if ($ratio > 1.05): ?>
              <div class="warn-text small">
                <?= e(fmt_num(($ratio - 1) * 100, 0)) ?>% above this project's ideal
                (<?= e(fmt_unit($ideal, $s['unit'])) ?>/day each)
              </div>
          <?php endif; endif; ?>
          <div class="muted small"><?= (int)$s['days_left'] ?> working days left</div>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php // --- breakdown grids -------------------------------------------------- ?>
<?php foreach ($tables as $t): ?>
  <h3><?= e($t['title']) ?></h3>
  <div class="table-scroll">
    <table class="review-table grid-table">
      <tr>
        <th><?= e($t['kind'] === 'shot' && $episodic ? 'Episode' : 'Group') ?></th>
        <th class="num"><?= e($t['unit_label']) ?></th>
        <?php foreach ($t['steps'] as $st): ?>
          <th class="num"><a href="step.php?project=<?= $projectId ?>&step=<?= (int)$st['step']['id'] ?>"><?= e($st['step']['name']) ?></a></th>
        <?php endforeach; ?>
      </tr>
      <?php foreach ($t['groups'] as $g): ?>
      <tr>
        <td><strong><?= e($g['name']) ?></strong></td>
        <td class="num muted"><?= (int)$g['count'] ?></td>
        <?php foreach ($t['steps'] as $st):
          $total = breakdown_cell_total($st, $g, $t['kind']);
          if ($total === null) { echo '<td class="num muted">—</td>'; continue; }
          $ap = (float)($g['cells'][(int)$st['step']['id']] ?? 0);
          $health = cell_health($ap, $total, $st['sched_start'] ?: $project['start_date'], $st['deadline']);
          $disp = $st['tracks_seconds']
                ? fmt_secs($ap) . ' / ' . fmt_secs($total)
                : (int)$ap . '/' . (int)$total;
        ?>
          <td class="num health-<?= e($health) ?>"><?= e($disp) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; ?>
<p class="muted small legend-key">
  <span class="hdot green"></span> On target ·
  <span class="hdot amber"></span> At risk ·
  <span class="hdot red"></span> Critical,
  judged against how much of the step's time has elapsed, not a flat percentage.
</p>

<?php // --- trend + workload -------------------------------------------------- ?>
<?php if ($pace): ?>
  <h3>Approved over time</h3>
  <?= dashboard_burnup_svg($pace['points'], $delivery) ?>
  <?php if (!$pace['confident']): ?>
    <p class="muted small">Two readings is arithmetic, not a trend, this firms up over a few days.</p>
  <?php endif; ?>
<?php endif; ?>

<?php // "Time logged" (Plaky's hours per step and per person) was removed on 2026-10-17:
      // the import no longer reads that column, and David chose to hide the old figures
      // rather than show numbers that will never move again. ?>

<h3>Status changes<?= $activityTotal ? ' · ' . $activityTotal : '' ?></h3>

<?php if (!activity_available($pdo)): ?>
  <p class="muted">
    Recording status changes needs migration <code>021</code>, which hasn't been applied yet.
    <strong>Nothing before it runs can be recovered</strong>, the platform only ever stored the
    current status, never the previous one, so this fills in from the day it is applied.
    <?php if (is_admin()): ?><a href="migrate.php">Review and apply it →</a><?php endif; ?>
  </p>
<?php else: ?>

  <form method="get" class="inline-form">
    <input type="hidden" name="project" value="<?= $projectId ?>">
    <label>Day
      <select name="day" onchange="this.form.submit()">
        <?php if (!$activityDays): ?>
          <option><?= e($activityDay) ?></option>
        <?php endif; ?>
        <?php foreach ($activityDays as $d): ?>
          <option value="<?= e($d['log_date']) ?>" <?= $d['log_date'] === $activityDay ? 'selected' : '' ?>>
            <?= e(date('D j M Y', strtotime($d['log_date']))) ?>, <?= (int)$d['n'] ?> change<?= (int)$d['n'] === 1 ? '' : 's' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if (!$activityTotal): ?>
    <p class="muted">Nothing changed status on <?= e($activityDay) ?>.</p>
  <?php else: ?>
    <?php foreach (['asset' => 'Pre-production', 'shot' => 'Production', 'project' => 'Post-production'] as $bucket => $label): ?>
      <?php $rowsA = $activity[$bucket]; if (!$rowsA) continue; ?>
      <h4 class="phase-head"><?= e($label) ?> <span class="muted small">· <?= count($rowsA) ?></span></h4>
      <table class="activity-table">
        <thead>
          <tr><th><?= $bucket === 'shot' ? 'Shot' : ($bucket === 'asset' ? 'Asset' : 'Deliverable') ?></th>
              <th>Step</th><th>Was</th><th></th><th>Now</th><th>Type</th><th>Who</th><th>Source</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rowsA as $a): ?>
          <tr>
            <td class="track-name"><?= e($a['display_name']) ?></td>
            <td><span class="dot" style="background:<?= e($a['step_color'] ?: '#ccc') ?>"></span><?= e($a['step_name'] ?: '—') ?></td>
            <td class="muted"><?= e($a['old_status_name'] ?: 'nothing recorded') ?></td>
            <td class="muted">&rarr;</td>
            <td><strong><?= e($a['new_status_name'] ?: 'cleared') ?></strong></td>
            <td class="<?= e(change_type_class($a['change_type'])) ?>"><?= e(ucfirst($a['change_type'])) ?></td>
            <td class="muted"><?= e($a['crew_name'] ?: '—') ?></td>
            <td class="muted small"><?= e($a['source'] === 'import' ? 'Plaky import' : ($a['source'] === 'grid' ? 'Tracking grid' : 'Item page')) ?><?php
              if (!empty($a['changed_by'])): ?> · <?= e($a['changed_by']) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
    <p class="muted small">
      <strong>Type</strong> is worked out from your own status vocabulary, not from the words in
      a name: <em>approved</em> and <em>delivered</em> follow <code>maps_to</code>, and
      <em>retake</em> means the work had reached delivered or approved and is now neither,
      so it is caught whatever the show happens to call that state.
      <strong>Source</strong> separates an import moving 140 rows from a person moving one.
    </p>
  <?php endif; ?>
<?php endif; ?>

<h3>Who is carrying what</h3>
<?php if (!$workload): ?>
  <p class="muted">No scheduled task has anyone on it yet, slots come from the headcount on the
    <a href="crew.php?project=<?= $projectId ?>">crew screen</a>.</p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Who</th><th class="num">Tasks</th><th class="num">Still to do</th><th>Runs</th></tr>
    <?php foreach ($workload as $w): ?>
    <tr>
      <td><strong><?= e($w['name']) ?></strong>
        <?php if ((int)$w['is_placeholder'] === 1): ?><span class="est-tag">slot</span><?php endif; ?></td>
      <td class="num"><?= (int)$w['tasks'] ?></td>
      <td class="num"><strong><?= (int)$w['remaining'] ?></strong></td>
      <td class="muted small"><?= e($w['first_start']) ?> → <?= e($w['last_end']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
function downloadDashboard() {
  var el = document.querySelector('.wrap');
  var btn = event.target;
  btn.disabled = true; btn.textContent = 'Rendering…';
  html2canvas(el, { backgroundColor: getComputedStyle(document.body).backgroundColor, scale: 2, useCORS: true,
                    windowWidth: el.scrollWidth, width: el.scrollWidth })
    .then(function (canvas) {
      // Local date parts, never toISOString, it shifts a day at UTC−6.
      var d = new Date();
      var stamp = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
                + '-' + String(d.getDate()).padStart(2, '0');
      var a = document.createElement('a');
      a.download = <?= json_encode(preg_replace('/[^A-Za-z0-9_-]/', '_', $project['code'] ?: 'project')) ?> + '_dashboard_' + stamp + '.png';
      a.href = canvas.toDataURL('image/png');
      a.click();
    })
    .catch(function (e) { alert('Download failed: ' + e.message); })
    .then(function () { btn.disabled = false; btn.textContent = 'PNG'; });
}
</script>

</div>
</body></html>
