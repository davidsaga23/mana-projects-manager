<?php
// Project hub — where everything about one project is reachable from.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/quota.php';   // project_estimates(), for the footage fallback
require __DIR__ . '/includes/names.php';
require_once __DIR__ . '/includes/dashboard.php';
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
// PER-USER VISIBILITY (David, 2026-10-21): the hub is the one door every project
// screen hangs off, so a scoped user who followed a stale link to a show they
// are not staffed on is turned back here rather than shown its insides. See-all
// roles (admin / Production) pass straight through.
require_project_access($pdo, $projectId);

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

// ---- the glance panel -------------------------------------------------------
//
// David, 2026-09-02: "a summary of total assets (by type) and shots in a list...
// bigger capsules with the start and end date. This view should give you a clean
// and quick glance at the project."

// FOOTAGE, read exactly the way step_quota() reads it when pricing a per-second
// step — the shots' own seconds, falling back to the early estimate. Two readers
// of one number, so the glance and the money can never disagree about how long
// the show is.
$stmt = $pdo->prepare('SELECT COALESCE(SUM(placeholder_seconds), 0) FROM shots WHERE project_id = ?');
$stmt->execute([$projectId]);
$footage = (float)$stmt->fetchColumn();
$footageEstimated = false;
if ($footage <= 0) {
    $est = project_estimates($pdo, $projectId);
    $footage = (float)($est['seconds'] ?? 0);
    $footageEstimated = $footage > 0;
}

// The window the show has, in working days — the studio calendar, not raw days.
$spanDays = ($project['start_date'] && $project['delivery_date'])
    ? working_days_between($project['start_date'], $project['delivery_date']) : 0;

// ⚠ THE ONE COMPARISON NOTHING ON THIS PAGE WAS MAKING. The delivery date sat in
// one sentence and "finishing <date>" sat in another three lines below, and
// nobody was subtracting them. FC Mobile delivered 16 Oct with a schedule
// finishing 5 Nov, and the screen reported both without a word about the gap.
//
// working_days_between() counts both ends, so the delivery day itself is in the
// count and comes back off: what is wanted is days PAST it, not days including.
$overrunDays = 0;
if ($scheduleEnd && $project['delivery_date'] && $scheduleEnd > $project['delivery_date']) {
    $overrunDays = max(0, working_days_between($project['delivery_date'], $scheduleEnd) - 1);
}

// Biggest group first: on a show with eleven asset types, the order that helps is
// the order of how much there is.
arsort($assetTypeCounts);

// The same headline the dashboard leads with. Computed here rather than passed
// around, so the two screens can never show different numbers for the same day.
$overall = dashboard_overall(
    dashboard_steps($pdo, $projectId, $project['delivery_date'] ?: null),
    $project['delivery_date'] ?: null,
    dashboard_pace($pdo, $projectId, $project['delivery_date'] ?: null)
);

$pageTitle = $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'overview'); ?>

<h2 class="wizard-title">
  <?= e($project['name']) ?> <span class="muted">(<?= e($project['code']) ?>)</span>
  <span class="status status-<?= e($project['status']) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
</h2>

<?php
// The glance. This replaced a single grey sentence carrying the same four facts
// at the same weight — "2D · 45 shots · 26 assets · 2026-08-31 → 2026-10-16" —
// where the dates were the thing people came for and read like punctuation.
//
// Written as ONE readable date with the ISO in a title, because this is the only
// place in the app that shouts a date rather than listing one. Everywhere else
// stays ISO, where columns of dates have to line up and be compared.
$fmtDay = function (?string $d): string {
    return $d ? date('j M Y', strtotime($d)) : '—';
};
$today = date('Y-m-d');
// Calendar days, not working days, for "3 days ago" — that phrase means what a
// person means by it, and nobody counts a weekend out of it in their head.
$daysTo = function (?string $d) use ($today): ?int {
    if (!$d) return null;
    return (int)round((strtotime($d) - strtotime($today)) / 86400);
};
$startIn = $daysTo($project['start_date']);
?>
<div class="glance">

  <div class="glance-dates">
    <div class="date-capsule">
      <div class="cap-label">Starts</div>
      <div class="cap-date" title="<?= e($project['start_date'] ?: '') ?>"><?= e($fmtDay($project['start_date'])) ?></div>
      <div class="cap-note">
        <?php if ($startIn === null): ?><span class="muted">no start date</span>
        <?php elseif ($startIn > 0): ?>in <?= $startIn ?> day<?= $startIn === 1 ? '' : 's' ?>
        <?php elseif ($startIn === 0): ?>today
        <?php else: ?><?= abs($startIn) ?> day<?= abs($startIn) === 1 ? '' : 's' ?> ago<?php endif; ?>
      </div>
    </div>

    <div class="cap-span">
      <span class="cap-arrow" aria-hidden="true">→</span>
      <?php if ($spanDays > 0): ?>
        <span><strong><?= $spanDays ?></strong> working days</span>
      <?php endif; ?>
    </div>

    <div class="date-capsule cap-delivery<?= $overrunDays > 0 ? ' cap-late' : '' ?>">
      <div class="cap-label">Delivery</div>
      <div class="cap-date" title="<?= e($project['delivery_date'] ?: '') ?>"><?= e($fmtDay($project['delivery_date'])) ?></div>
      <div class="cap-note">
        <?php // The comparison, made where the date is rather than left for a
              // reader to do between two paragraphs three lines apart. Shown in
              // BOTH states: when the schedule fits, when it lands is still the
              // thing you want to know, and only the colour should change. ?>
        <?php if ($scheduleEnd): ?>
          <span class="<?= $overrunDays > 0 ? 'warn-text' : '' ?>">schedule ends
            <?= e($fmtDay($scheduleEnd)) ?><?php
            // The GAP, not just the later date. It is the whole reason the two
            // dates are worth putting side by side, and it used to need a
            // sentence of its own underneath — which David had removed by
            // 2026-09-02 as prose. Four words in the capsule say the same thing.
            if ($overrunDays > 0): ?> · <?= $overrunDays ?>d late<?php endif; ?></span>
        <?php else: ?>
          <span class="muted">nothing scheduled</span>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <?php // The counts, as a list rather than a run-on sentence. Assets indent
        // under their total because the types SUM to it — a reader should be
        // able to check that by eye. ?>
  <ul class="glance-counts">
    <li>
      <span class="gc-n"><?= $shotCount ?></span>
      <span class="gc-l">Shot<?= $shotCount === 1 ? '' : 's' ?></span>
      <?php if ($footage > 0): ?>
        <span class="gc-sub"><?= e(fmt_num($footage, 1)) ?>s of footage<?php
          if ($footage >= 60): ?> · <?= e(fmt_num($footage / 60, 1)) ?> min<?php endif;
          if ($footageEstimated): ?> <span class="est-tag">est.</span><?php endif; ?></span>
      <?php endif; ?>
    </li>
    <li>
      <span class="gc-n"><?= $assetCount ?></span>
      <span class="gc-l">Asset<?= $assetCount === 1 ? '' : 's' ?></span>
    </li>
    <?php foreach ($assetTypeCounts as $type => $n): ?>
      <li class="gc-child">
        <span class="gc-n"><?= (int)$n ?></span>
        <span class="gc-l"><?= e($type !== '' ? $type : 'No type set') ?></span>
      </li>
    <?php endforeach; ?>
    <?php // CREW, asked for 2026-09-02. Slots rather than the sum of the
          // headcount column, because a slot is the real seat somebody is hired
          // into — and how many are still nobody is the fact that matters when
          // the show has already started. ?>
    <?php if ($slotCount): ?>
      <li>
        <span class="gc-n"><?= $slotCount ?></span>
        <span class="gc-l">Crew</span>
        <?php if ($slotsUnfilled): ?>
          <span class="gc-sub"><?= $slotCount - $slotsUnfilled ?> named ·
            <span class="warn-text"><?= $slotsUnfilled ?> still open</span></span>
        <?php else: ?>
          <span class="gc-sub">all named</span>
        <?php endif; ?>
      </li>
    <?php endif; ?>
    <li class="gc-meta">
      <span class="gc-l"><?= e($project['type']) ?> · <?= (int)$project['fps'] ?> fps</span>
    </li>
  </ul>

</div>

<?php // Compact: the shape of where the show is. The rates and deltas behind it
       // live on the dashboard, which is one click away and lit in the tab bar. ?>
<?php render_overall_progress($overall, true); ?>
<?php // ⚠ OVERVIEW IS THE VIEWER'S LANDING PAGE, so its links into the screens
      // they no longer have tabs for come out with the tabs (David, 2026-09-28).
      // Hiding a tab and then opening the same screen from a link on the first
      // page they see would be tidying nothing. hidden_from_viewers() reads the
      // same list the tab bar does, so the two cannot drift. ?>
<?php if (!hidden_from_viewers('dashboard')): ?>
  <p class="muted small">
    <a href="dashboard.php?project=<?= $projectId ?>">See the pace behind this →</a>
  </p>
<?php endif; ?>

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
    <?php if (!hidden_from_viewers('aliases')): ?>
      <a href="aliases.php?project=<?= $projectId ?>">Review them →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php // The card grid that used to sit here is gone (2026-08-15). It was built
      // before the tab bar existed and duplicated it exactly — nine cards
      // linking to nine tabs three centimetres above them. The two facts the
      // cards carried that the tabs cannot (how much is scheduled, how many crew
      // slots are still placeholders) are in the line below instead, which is
      // where a number belongs rather than inside a navigation control. ?>
<p class="muted small">
  <?php if ($scheduled): ?>
    <?php // The finish date used to be here too. It belongs beside the delivery
          // date it has to be compared against, so the capsule above owns it now
          // and this line just counts the work. ?>
    <strong><?= $scheduled ?></strong> task<?= $scheduled === 1 ? '' : 's' ?> scheduled.
  <?php else: ?>
    <?php if (hidden_from_viewers('schedule')): ?>
      Nothing scheduled yet.
    <?php else: ?>
      Nothing scheduled yet, the <a href="schedule.php?project=<?= $projectId ?>">Schedule</a>
      screen works the pipeline forwards into real dates.
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($slotCount): ?>
    <?= $slotCount ?> crew slot<?= $slotCount === 1 ? '' : 's' ?><?php
      if ($slotsUnfilled): ?>, <strong><?= $slotsUnfilled ?></strong> still a placeholder<?php endif; ?>.
  <?php endif; ?>
</p>

<h3>Progress by step</h3>
<?php if (!$steps): ?>
  <?php if (hidden_from_viewers('setup')): ?>
    <p class="muted">No pipeline steps yet.</p>
  <?php else: ?>
    <p class="muted">No pipeline steps yet, <a href="wizard.php?project=<?= $projectId ?>&amp;step=2">add them</a>.</p>
  <?php endif; ?>
<?php else: ?>
  <?php if ($blocked): ?>
    <p class="flash flash-warn"><strong><?= $blocked ?></strong> tracked
      <?= $blocked === 1 ? 'task is' : 'tasks are' ?> blocked, they still count as work
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
  <?php // Three buttons where a paragraph used to bury two links in an
        // explanation of the Tracked column. Each names the FILE it produces
        // rather than the act of exporting, so the row reads as a choice of
        // three sheets rather than three ways to press Export. ?>
  <?php if (!hidden_from_viewers('export')): ?>
    <div class="export-row">
      <a class="button secondary" href="export.php?project=<?= $projectId ?>">Export progress ↓</a>
      <a class="button secondary" href="export.php?project=<?= $projectId ?>&amp;what=shots">Export shot list ↓</a>
      <a class="button secondary" href="export.php?project=<?= $projectId ?>&amp;what=assets">Export asset list ↓</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
