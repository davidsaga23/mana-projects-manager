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
$visible  = array_values(array_filter($steps, function ($s) {
    return (int)($s['step']['show_in_dashboard'] ?? 1) === 1;
}));
$tables   = dashboard_breakdown($pdo, $projectId, $visible, $episodic);
$workload = dashboard_workload($pdo, $projectId, $today);
$pace     = dashboard_pace($pdo, $projectId, $delivery, $today);

// The step the show is judged by: the one measured in footage. Falls back to
// whatever is furthest from finished, so this block is never empty on a project
// that doesn't track seconds at all.
$primary = null; $others = [];
foreach ($visible as $s) {
    if ($s['tracks_seconds'] && $primary === null) $primary = $s;
    else $others[] = $s;
}
if ($primary === null && $visible) {
    usort($visible, function ($a, $b) { return ($b['remaining'] <=> $a['remaining']); });
    $primary = $visible[0];
    $others = array_slice($visible, 1);
}

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

$pageTitle = 'Dashboard · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>

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
        ' finish past their own deadline — ' . implode(', ', $n) . (count($lateSteps) > 4 ? '…' : '') . '.',
        'timeline.php?project=' . $projectId, 'See the timeline'];
}
if ($totBlocked) $attention[] = [$totBlocked . ' task' . ($totBlocked === 1 ? ' is' : 's are') .
    ' blocked — they need unblocking, not an artist.', 'aliases.php?project=' . $projectId, 'Review'];
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

<?php // --- the step the show is judged by ---------------------------------- ?>
<?php if ($primary): $p = $primary; ?>
<section class="dash-primary">
  <div class="dash-primary-head">
    <div>
      <h3><a href="step.php?project=<?= $projectId ?>&step=<?= (int)$p['step']['id'] ?>"><?= e($p['step']['name']) ?></a>
        <span class="unit-badge"><?= $p['tracks_seconds'] ? 'Seconds' : 'Items' ?> · Primary</span>
      </h3>
      <p class="muted small">
        Deadline <strong><?= e($p['deadline'] ?: 'not set') ?></strong>
        <?php if ($p['days_left'] !== null): ?>
          · <span class="<?= $p['days_left'] <= 5 ? 'warn-text' : '' ?>"><?= (int)$p['days_left'] ?> working days left</span>
        <?php elseif ($p['overdue']): ?>
          · <span class="warn-text">overdue</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="dash-big">
      <div class="big-pct"><?= $p['pct'] === null ? '—' : $p['pct'] . '%' ?></div>
      <div class="muted small">of <?= $p['tracks_seconds'] ? 'runtime' : 'items' ?> approved</div>
    </div>
  </div>

  <?php $pendingOnly = max(0, $p['delivered_v'] - $p['approved_v']); ?>
  <div class="stacked-progress">
    <span class="band-approved" style="width:<?= (float)($p['pct'] ?? 0) ?>%"></span>
    <span class="band-delivered" style="left:<?= (float)($p['pct'] ?? 0) ?>%;width:<?= max(0, (float)($p['pct_delivered'] ?? 0) - (float)($p['pct'] ?? 0)) ?>%"></span>
  </div>
  <div class="dash-legend">
    <span><i class="sw sw-approved"></i>Approved <?= e(fmt_unit($p['approved_v'], $p['unit'])) ?></span>
    <span><i class="sw sw-delivered"></i>Delivered pending <?= e(fmt_unit($pendingOnly, $p['unit'])) ?></span>
    <span class="muted">Remaining <?= e(fmt_unit($p['remaining'], $p['unit'])) ?></span>
  </div>

  <div class="kpi-grid">
    <div><div class="kpi-label">Approved since last reading</div>
      <div class="kpi-value ok"><?= $p['delta']['approved'] === null ? '—'
            : '+' . e(fmt_unit((float)$p['delta']['approved'], $p['unit'])) ?></div></div>
    <div><div class="kpi-label">Delivered since last reading</div>
      <div class="kpi-value info"><?= $p['delta']['delivered'] === null ? '—'
            : '+' . e(fmt_unit((float)$p['delta']['delivered'], $p['unit'])) ?></div></div>
    <div><div class="kpi-label">Required pace</div>
      <div class="kpi-value warn"><?= e(pace_text($p['required_pace'], $p['unit'])) ?>
        <span class="unit"><?= $p['tracks_seconds'] ? 'sec/day' : '/day' ?></span></div></div>
    <div><div class="kpi-label">Actual, last 7 days</div>
      <div class="kpi-value status-<?= e($p['status']) ?>">
        <?= $p['actual_pace'] === null ? '—' : e(fmt_unit((float)$p['actual_pace'], $p['unit'])) ?>
        <span class="unit"><?= $p['tracks_seconds'] ? 'sec/day' : '/day' ?></span>
        <?php if ($p['status'] === 'ontrack'): ?><span class="trend up">▲ on track</span>
        <?php elseif ($p['status'] === 'behind'): ?><span class="trend down">▼ behind</span>
        <?php elseif ($p['status'] === 'critical'): ?><span class="trend down">▼ critical</span>
        <?php else: ?><span class="muted small">no history yet</span><?php endif; ?>
      </div></div>
  </div>
</section>
<?php endif; ?>

<?php // --- a card per step, each carrying its quota ------------------------ ?>
<h3>Every step, and what it needs per day</h3>
<div class="step-cards">
  <?php foreach ($others as $s): ?>
    <article class="step-card <?= $s['overdue'] ? 'overdue' : '' ?>">
      <div class="step-card-head">
        <h4><a href="step.php?project=<?= $projectId ?>&step=<?= (int)$s['step']['id'] ?>"><?= e($s['step']['name']) ?></a></h4>
        <span class="unit-badge"><?= e(applies_to_phrase($s['step']['applies_to'], $s['step']['applies_to_asset_type'] ?? null)) ?></span>
      </div>
      <div class="mini-bar"><span style="width:<?= (float)($s['pct'] ?? 0) ?>%;background:<?= e($s['step']['color'] ?: '#4A90D9') ?>"></span></div>
      <div class="mini-num">
        <?= e(fmt_unit($s['approved_v'], $s['unit'])) ?><span class="mini-total">/<?= e(fmt_unit($s['target'], $s['unit'])) ?></span>
      </div>
      <div class="muted small">
        <?= e(fmt_unit($s['remaining'], $s['unit'])) ?> to go<?php if ($s['blocked']): ?>
          · <span class="warn-text"><?= (int)$s['blocked'] ?> blocked</span><?php endif; ?>
      </div>
      <div class="mini-pace">
        <?php if ($s['overdue']): ?>
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
  <span class="hdot red"></span> Critical —
  judged against how much of the step's time has elapsed, not a flat percentage.
</p>

<?php // --- trend + workload -------------------------------------------------- ?>
<?php if ($pace): ?>
  <h3>Approved over time</h3>
  <?= dashboard_burnup_svg($pace['points'], $delivery) ?>
  <?php if (!$pace['confident']): ?>
    <p class="muted small">Two readings is arithmetic, not a trend — this firms up over a few days.</p>
  <?php endif; ?>
<?php endif; ?>

<h3>Who is carrying what</h3>
<?php if (!$workload): ?>
  <p class="muted">No scheduled task has anyone on it yet — slots come from the headcount on the
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
  html2canvas(el, { backgroundColor: '#f4f6f8', scale: 2, useCORS: true,
                    windowWidth: el.scrollWidth, width: el.scrollWidth })
    .then(function (canvas) {
      // Local date parts, never toISOString — it shifts a day at UTC−6.
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
