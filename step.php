<?php
// One pipeline step, in detail.
//
// The screen you open when a card on the dashboard looks wrong. It answers
// three questions in order, which is the order people actually ask them:
//
//   1. How far along is this, and will it make its date?
//   2. What does each person have to finish per day to get there — and what are
//      they finishing?
//   3. Which specific shots or assets are outstanding?

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/dashboard.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/tickets.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stepId    = (int)($_GET['step'] ?? $_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$stepIdOk = $pdo->prepare('SELECT 1 FROM pipeline_steps WHERE id = ? AND project_id = ?');
$stepIdOk->execute([$stepId, $projectId]);

// ---- changing the deadline ---------------------------------------------------
// David, 2026-09-14, with a screenshot of STFC's Deadline card. Before the
// figures are worked out, so the page it redirects to is computed from the new
// date. require_login() has already refused a POST from a view-only account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stepIdOk->fetchColumn()) {
    try {
        if (($_POST['action'] ?? '') === 'clear_deadline') {
            // The "use the project's delivery date" link. It is about the deadline
            // and must not touch the start date, so the start is passed as null.
            [$said, $warn] = set_step_dates($pdo, $project, $stepId, null, '');
        } else {
            // ⚠ BOTH FIELDS ARE ON THE FORM, so both are sent — including an empty
            // one, which means "clear it". Reading only the filled boxes would make
            // clearing a date impossible from a screen that shows it.
            [$said, $warn] = set_step_dates(
                $pdo, $project, $stepId,
                (string)($_POST['start_date'] ?? ''), (string)($_POST['deadline'] ?? '')
            );
        }
        flash('ok', $said . ' The pace and every figure on this page are worked out from the deadline.');
        if ($warn) flash('warn', $warn);
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: step.php?project=' . $projectId . '&step=' . $stepId);
    exit;
}

$today    = date('Y-m-d');
$delivery = $project['delivery_date'] ?: null;

$all = dashboard_steps($pdo, $projectId, $delivery);
$s = null;
foreach ($all as $row) if ((int)$row['step']['id'] === $stepId) $s = $row;
if (!$s) {
    flash('err', 'That step is not part of this project.');
    header('Location: dashboard.php?project=' . $projectId);
    exit;
}
$step = $s['step'];

// --- the items at this step --------------------------------------------------
// One query covering shots and assets, because a step can apply to either and
// two lists would only have to be merged again for display.
$items = $pdo->prepare(
    "SELECT pr.id, pr.item_key, pr.status AS raw_status, pr.is_delivered, pr.is_approved,
            pr.updated_at, pr.assignee_raw,
            sh.episode, sh.shot_code, sh.placeholder_seconds,
            a.name AS asset_name, a.asset_type,
            st.name AS status_name, st.color AS status_color,
            st.is_blocked, st.is_excluded,
            c.name AS crew_name,
            sc.start_date, sc.end_date
       FROM progress pr
       LEFT JOIN shots  sh ON sh.id = pr.shot_id
       LEFT JOIN assets a  ON a.id  = pr.asset_id
       LEFT JOIN statuses st ON st.id = pr.status_id
       LEFT JOIN crew   c  ON c.id  = pr.crew_id
       LEFT JOIN schedule sc ON sc.step_id = pr.step_id AND sc.item_key = pr.item_key
      WHERE pr.step_id = ?
      ORDER BY pr.is_approved, sh.episode, sh.shot_code, a.asset_type, a.name"
);
$items->execute([$stepId]);
$rows = $items->fetchAll();

// --- daily history ------------------------------------------------------------
$history = [];
try {
    $h = $pdo->prepare(
        'SELECT * FROM daily_snapshots WHERE step_id = ? ORDER BY snapshot_date DESC LIMIT 30'
    );
    $h->execute([$stepId]);
    $history = $h->fetchAll();
} catch (PDOException $e) { /* migration 011 pending */ }

// Slots on this step, for the per-artist quota.
$slots = [];
try {
    $q = $pdo->prepare(
        'SELECT cl.lane_no, c.name, c.is_placeholder FROM crew_lanes cl
           JOIN crew c ON c.id = cl.crew_id
          WHERE cl.step_id = ? ORDER BY cl.lane_no'
    );
    $q->execute([$stepId]);
    $slots = $q->fetchAll();
} catch (PDOException $e) { /* migration 012 pending */ }

// Open tickets raised against this step. This is the payoff for tickets.step_id:
// "what is outstanding on Compositing" is a question the board can only answer
// per card, and the step page is where somebody is already looking when they
// ask it.
$stepTickets = [];
try {
    $stepTickets = tickets_for_step($pdo, $stepId);
} catch (PDOException $e) { /* migration 017 pending */ }

$unit = $s['unit'];

$pageTitle = $step['name'] . ' · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php // A drill-down of the dashboard, so that tab stays lit rather than none. ?>
<?php project_nav($project, 'dashboard'); ?>
<p class="crumb"><a href="dashboard.php?project=<?= $projectId ?>">← Back to the dashboard</a></p>

<h2 class="wizard-title">
  <span class="dot" style="background:<?= e($step['color'] ?: '#ccc') ?>"></span>
  <?= e($step['name']) ?>
  <span class="muted">· <?= e(applies_to_phrase($step['applies_to'], $step['applies_to_asset_type'] ?? null)) ?></span>
  <?php if ($s['tracks_seconds']): ?><span class="unit-badge">Seconds</span><?php endif; ?>
</h2>

<?php if ($stepTickets): ?>
  <div class="flash flash-warn">
    <strong><?= count($stepTickets) ?></strong> open
    ticket<?= count($stepTickets) === 1 ? '' : 's' ?> raised against this step:
    <?php foreach ($stepTickets as $i => $tk): ?>
      <?php if ($i > 0): ?>·<?php endif; ?>
      <a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e(ticket_ref($tk)) ?></a>
      <?= e(mb_strimwidth($tk['title'], 0, 60, '…')) ?><?php
        $tkShot = shot_label($tk['episode'] ?? '', $tk['shot_code'] ?? '');
        if ($tkShot !== '') echo ' (' . e($tkShot) . ')';
        elseif (!empty($tk['asset_name'])) echo ' (' . e($tk['asset_name']) . ')';
      ?>
    <?php endforeach; ?>
    <a href="tasks.php?project=<?= $projectId ?>">Open the board →</a>
  </div>
<?php endif; ?>

<div class="kpi-grid kpi-grid-6">
  <div><div class="kpi-label">Approved</div>
    <div class="kpi-value ok"><?= e(fmt_unit($s['approved_v'], $unit)) ?></div>
    <div class="muted small">of <?= e(fmt_unit($s['target'], $unit)) ?></div></div>
  <div><div class="kpi-label">Delivered, awaiting approval</div>
    <div class="kpi-value info"><?= e(fmt_unit(max(0, $s['delivered_v'] - $s['approved_v']), $unit)) ?></div></div>
  <div><div class="kpi-label">Remaining</div>
    <div class="kpi-value"><?= e(fmt_unit($s['remaining'], $unit)) ?></div>
    <?php if ($s['blocked']): ?><div class="warn-text small"><?= (int)$s['blocked'] ?> blocked</div><?php endif; ?></div>
  <div><div class="kpi-label">Working days left</div>
    <div class="kpi-value <?= ($s['days_left'] !== null && $s['days_left'] <= 5) ? 'warn' : '' ?>">
      <?= $s['overdue'] ? 'overdue' : ($s['days_left'] === null ? '—' : (int)$s['days_left']) ?></div>
    <div class="muted small">to <?= e($s['deadline'] ?: 'no deadline') ?></div></div>
  <div><div class="kpi-label">Required pace</div>
    <div class="kpi-value warn"><?= e(pace_text($s['required_pace'], $unit)) ?></div>
    <div class="muted small">across the team</div></div>
  <div><div class="kpi-label">Actual, last 7 days</div>
    <div class="kpi-value status-<?= e($s['status']) ?>">
      <?= $s['actual_pace'] === null ? '—' : e(fmt_unit((float)$s['actual_pace'], $unit)) ?></div>
    <div class="muted small">
      <?php if ($s['status'] === 'ontrack'): ?>on track
      <?php elseif ($s['status'] === 'behind'): ?>behind
      <?php elseif ($s['status'] === 'critical'): ?>critical
      <?php else: ?>not enough history yet<?php endif; ?>
    </div></div>
</div>

<?php
// This project's IDEAL for the step, beside the pace the deadline demands.
//
// Set on the business side (Ideal quotas, admin only) and READ here, which is
// the split David asked for: the number is a business decision, and it is
// useless unless the person running the step can see it. Nothing here can edit
// it — this screen shows what the ideal says and what the plan asks. Per project
// since 2026-09-17, read through the same function as every other reader.
require_once __DIR__ . '/includes/standards.php';
$idealDays = effective_days_per_unit($pdo, $projectId, $s['step']);
$idealPerDay = $idealDays > 0 ? 1 / $idealDays : null;
?>

<?php // --- the deadline --------------------------------------------------------
      // Directly ABOVE the quota, because the quota is worked back from it:
      // change the date, and the numbers under it are the ones that move.
      //
      // ⚠ STFC's card also said it "re-snapshots today's numbers". That is not
      // copied, because here it would be a false claim. The daily history in this
      // app records PROGRESS, not pace, so there is nothing in it a new date
      // changes; the pace is recalculated from the deadline on every page load.
      // And a history that rewrote itself whenever a date moved would stop being
      // a history. ?>
<?php if (can_edit()):
    $ownDeadline = $step['deadline'] ?? null;
    $inherited   = !$ownDeadline && $delivery;
?>
  <div class="deadline-card">
    <?php // Not "Deadline" any more: the card holds both ends of the step. ?>
    <h3>Dates</h3>
    <form method="post" class="inline-form deadline-form">
      <?php // ⚠ THE START DATE EARNS ITS PLACE HERE BUT CHANGES NOTHING ON THIS
            // PAGE. Every figure above is worked from the DEADLINE and today: the
            // pace, the days left, the quota. The start drives the timeline bar and
            // the scheduler instead. Said below the fields, because a date that
            // visibly moves nothing looks broken otherwise. ?>
      <label class="inline" for="startInput">Starts</label>
      <input type="date" id="startInput" name="start_date"
             value="<?= e($step['start_date'] ?? '') ?>">
      <label class="inline" for="deadlineInput">Due</label>
      <input type="date" id="deadlineInput" name="deadline"
             value="<?= e((string)($ownDeadline ?: $delivery ?: '')) ?>" required>
      <button type="submit" name="action" value="set_deadline">Save</button>
      <span class="muted small deadline-now">
        Current:
        <?php if ($s['deadline']): ?>
          <strong><?= e($s['deadline']) ?></strong>
          <?php if ($s['overdue']): ?>
            <span class="err">(passed)</span>
          <?php elseif ($s['days_left'] !== null): ?>
            (<?= (int)$s['days_left'] ?> working day<?= (int)$s['days_left'] === 1 ? '' : 's' ?> left, today included)
          <?php endif; ?>
          <?php // Which date it is matters as much as what it is. Clearing a
                // step's own deadline does not remove the deadline; it falls
                // back to the project's delivery date, and a screen that did not
                // say so would make that look like a bug. ?>
          <?php if ($inherited): ?>
            · <em>the project's delivery date, this step has none of its own</em>
          <?php endif; ?>
        <?php else: ?>
          <strong>none</strong> <span class="muted">(and the project has no delivery date)</span>
        <?php endif; ?>
      </span>
    </form>
    <?php if ($ownDeadline && $delivery): ?>
      <form method="post" class="deadline-clear">
        <button type="submit" name="action" value="clear_deadline" class="linkish">
          Use the project's delivery date instead (<?= e($delivery) ?>)</button>
      </form>
    <?php endif; ?>
    <p class="muted small">
      The <strong>deadline</strong> recalculates the required pace and every figure on this page
      straight away. The <strong>start date</strong> changes nothing here — the pace is measured
      from today — but it is what the timeline bar and the scheduler are drawn from. Clearing
      either box empties that date.
      A schedule already saved is not replanned; <a href="schedule.php?project=<?= $projectId ?>">re-run
      it</a> to fit the new date.
    </p>
  </div>
<?php endif; ?>

<?php // --- the quota, per team and per artist ------------------------------ ?>
<h3>The daily quota</h3>
<?php if ($s['required_pace'] === null): ?>
  <p class="muted">This step has no deadline and the project has no delivery date, so there is
     nothing to work a quota back from.
     <a href="wizard.php?project=<?= $projectId ?>&step=6">Set one →</a></p>
<?php elseif (is_infinite($s['required_pace'])): ?>
  <p class="flash flash-err">The deadline of <?= e($s['deadline']) ?> has passed with
     <?= e(fmt_unit($s['remaining'], $unit)) ?> still to do, so there is no rate that reaches it.
     The date has to move, or the remaining work does.</p>
<?php else: ?>
  <div class="dash-pace">
    <div><span class="muted small">The team must finish</span>
      <strong><?= e(fmt_unit((float)$s['required_pace'], $unit)) ?></strong>
      <span class="muted small">per working day, every day, to <?= e($s['deadline']) ?></span></div>
    <div><span class="muted small">Each person must finish</span>
      <strong><?= $s['per_artist'] === null ? '—' : e(fmt_unit((float)$s['per_artist'], $unit)) ?></strong>
      <span class="muted small">
        <?= $s['per_artist'] === null ? 'no headcount set on this step'
            : 'per working day, across ' . e(fmt_num($s['headcount'])) . ' people' ?></span></div>
    <div><span class="muted small">Being finished</span>
      <strong class="status-<?= e($s['status']) ?>">
        <?= $s['actual_pace'] === null ? '—' : e(fmt_unit((float)$s['actual_pace'], $unit)) ?></strong>
      <span class="muted small">per day, measured over the last 7 days</span></div>
    <?php if ($idealPerDay !== null): ?>
      <div><span class="muted small">This project's ideal is</span>
        <strong><?= e(fmt_unit($idealPerDay, $unit)) ?></strong>
        <span class="muted small">per person per day
          <?php if (is_admin()): ?>
            · <a href="standards.php?project=<?= (int)$project['id'] ?>">change it</a>
          <?php endif; ?></span></div>
    <?php endif; ?>
  </div>

  <?php
  // The comparison, said in a sentence rather than left as two numbers to divide
  // in your head. Above the ideal is the finding: the plan is asking for a pace
  // this project does not claim is achievable, and the fixes are more people,
  // more time, or less work.
  if ($idealPerDay !== null && $s['per_artist'] !== null && $idealPerDay > 0):
    $ratio = (float)$s['per_artist'] / $idealPerDay; ?>
    <?php if ($ratio > 1.05): ?>
      <div class="flash flash-err">
        This step needs <strong><?= e(fmt_num(($ratio - 1) * 100, 0)) ?>% more per person per
        day</strong> than this project's ideal. That is not an ambitious target, it is one nobody is
        expected to hit. More people, more time, or less work.
      </div>
    <?php elseif ($ratio < 0.95): ?>
      <p class="muted small ok">
        Comfortably inside this project's ideal, <?= e(fmt_num((1 - $ratio) * 100, 0)) ?>% under.
      </p>
    <?php else: ?>
      <p class="muted small">The deadline asks for almost exactly this project's ideal.</p>
    <?php endif; ?>
  <?php endif; ?>
  <p class="muted small">
    The team figure does not fall when people are added, the work needed to hit a date is
    fixed. Only each person's share of it moves.
    <?php if ($s['actual_pace'] !== null && $s['actual_pace'] < $s['required_pace']): ?>
      <span class="warn-text">At the current rate this step does not reach its deadline.</span>
    <?php endif; ?>
  </p>
  <?php if ($slots): ?>
    <p class="muted small">On this step:
      <?php $names = [];
        foreach ($slots as $sl) $names[] = $sl['name'] . ((int)$sl['is_placeholder'] === 1 ? ' (slot)' : '');
        echo e(implode(' · ', $names)); ?>
      · <a href="crew.php?project=<?= $projectId ?>">crew</a>
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php // --- the trend ---------------------------------------------------------
      // $history is newest-first for the table below; a chart reads left to
      // right, so it is reversed here rather than queried twice.
      $trendRows = [];
      foreach (array_reverse($history) as $h) {
          $trendRows[] = [
              'date'      => $h['snapshot_date'],
              'approved'  => $s['tracks_seconds'] ? (float)$h['seconds_approved_cum']  : (float)$h['items_approved_cum'],
              'delivered' => $s['tracks_seconds'] ? (float)$h['seconds_delivered_cum'] : (float)$h['items_delivered_cum'],
          ];
      }
?>
<h3>Trend</h3>
<?php if (count($trendRows) < 2): ?>
  <p class="muted">
    A trend needs at least two days of history, and snapshots only began on 13 August 2026,
    the day the writer was added. This fills in on its own from here, one reading per day, and
    <strong>cannot be backfilled</strong>: a day nobody opened the app is a day that was never
    recorded. That is why the snapshot work jumped the queue.
  </p>
<?php else: ?>
  <div class="trend-wrap">
    <?= step_trend_svg($trendRows, (float)$s['target'], $s['deadline'] ?: null, $unit) ?>
  </div>
  <p class="dash-legend">
    <span class="legend-key"><span class="trend-swatch approved"></span> Approved</span>
    <span class="legend-key"><span class="trend-swatch delivered"></span> Delivered</span>
    <span class="legend-key"><span class="trend-swatch target"></span> Target (<?= e(fmt_unit((float)$s['target'], $unit)) ?>)</span>
  </p>
<?php endif; ?>

<?php // --- daily history ---------------------------------------------------- ?>
<h3>Daily history</h3>
<?php if (count($history) < 2): ?>
  <p class="muted">Progress is recorded once a day. There isn't enough yet to show a history,
     it fills itself in from here.</p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Date</th><th class="num">Approved</th><th class="num">That day</th>
        <th class="num">Delivered</th><th class="num">Blocked</th></tr>
    <?php
    // Newest first, and each row's delta is against the row BELOW it — the one
    // before it in time.
    foreach ($history as $i => $h):
      $prev = $history[$i + 1] ?? null;
      $ap   = $s['tracks_seconds'] ? (float)$h['seconds_approved_cum'] : (int)$h['items_approved_cum'];
      $de   = $s['tracks_seconds'] ? (float)$h['seconds_delivered_cum'] : (int)$h['items_delivered_cum'];
      $prevAp = $prev ? ($s['tracks_seconds'] ? (float)$prev['seconds_approved_cum'] : (int)$prev['items_approved_cum']) : null;
      $delta = $prevAp === null ? null : $ap - $prevAp;
    ?>
    <tr>
      <td><?= e($h['snapshot_date']) ?></td>
      <td class="num"><?= e(fmt_unit((float)$ap, $unit)) ?></td>
      <td class="num <?= ($delta !== null && $delta > 0) ? 'ok' : 'muted' ?>">
        <?= $delta === null ? '—' : ($delta > 0 ? '+' : '') . e(fmt_unit((float)$delta, $unit)) ?></td>
      <td class="num muted"><?= e(fmt_unit((float)$de, $unit)) ?></td>
      <td class="num <?= (int)$h['blocked_count'] > 0 ? 'warn-text' : 'muted' ?>"><?= (int)$h['blocked_count'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php // --- the items themselves ---------------------------------------------- ?>
<h3>Everything at this step <span class="muted">· <?= count($rows) ?> tracked of <?= (int)$s['applicable'] ?></span></h3>

<?php // ⚠ THE LEFTOVERS, NAMED. Changing a step's scope does not delete the
      // progress rows the old scope created, so a step re-scoped from shots to
      // the whole project keeps 21 shot rows it no longer covers. They are listed
      // below because they are real records — but they count towards nothing, and
      // a list longer than the total with no explanation reads as a fault. ?>
<?php if (!empty($s['stale_rows'])): ?>
  <p class="flash flash-warn">
    <strong><?= (int)$s['stale_rows'] ?> of these were tracked under a different scope</strong>
    and are not counted any more. This step now covers
    <?= e(applies_to_phrase((string)$step['applies_to'], $step['applies_to_asset_type'] ?? null)) ?>, so only
    <?= (int)$s['tracked'] ?> of the rows below count towards its
    <?= e(fmt_unit($s['target'], $unit)) ?>. They are kept rather than deleted: they are
    what really happened, and the scope may change back.
  </p>
<?php endif; ?>
<?php if (!$rows): ?>
  <p class="muted">Nothing has been recorded against this step yet. Progress arrives through
     <a href="import.php?project=<?= $projectId ?>">the importer</a>.</p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Item</th><?php if ($s['tracks_seconds']): ?><th class="num">Sec</th><?php endif; ?>
        <th>Status</th><th>Who</th><th>Scheduled</th><th>Last update</th></tr>
    <?php foreach ($rows as $r):
      $name = $r['shot_code'] !== null
            ? trim(($r['episode'] ? $r['episode'] . ' ' : '') . $r['shot_code'])
            : ($r['asset_name'] ?? $r['item_key']);
      $cls = (int)$r['is_approved'] === 1 ? 'row-done'
           : ((int)$r['is_blocked'] === 1 ? 'has-problem' : '');
    ?>
    <tr class="<?= $cls ?>">
      <td><strong><?= e($name) ?></strong>
        <?php if ($r['asset_type']): ?><span class="muted small"><?= e($r['asset_type']) ?></span><?php endif; ?>
        <?php if ((int)$r['is_excluded'] === 1): ?><span class="est-tag">n/a</span><?php endif; ?></td>
      <?php if ($s['tracks_seconds']): ?>
        <td class="num muted"><?= $r['placeholder_seconds'] !== null ? e(fmt_num((float)$r['placeholder_seconds'], 2)) : '—' ?></td>
      <?php endif; ?>
      <td>
        <?php if ($r['status_name']): ?>
          <span class="dot" style="background:<?= e($r['status_color'] ?: '#ccc') ?>"></span><?= e($r['status_name']) ?>
        <?php else: ?>
          <span class="muted"><?= e($r['raw_status'] ?: '—') ?></span>
          <?php if ($r['raw_status']): ?><span class="warn-text small">unmapped</span><?php endif; ?>
        <?php endif; ?>
      </td>
      <td class="muted"><?= e($r['crew_name'] ?: ($r['assignee_raw'] ?: '—')) ?></td>
      <td class="muted small"><?= $r['start_date'] ? e($r['start_date'] . ' → ' . $r['end_date']) : '—' ?></td>
      <td class="muted small"><?= e(substr((string)$r['updated_at'], 0, 10)) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">Approved rows are dimmed; blocked rows are highlighted. "Who" falls back
     to the raw imported name when it hasn't been matched to anyone yet.</p>
<?php endif; ?>

</div>
</body></html>
