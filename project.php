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

// ⚠ THE PROGRESS TABLE READS dashboard_steps(), NOT A QUERY OF ITS OWN (2026-11-16).
// It used to count COUNT(pr.id) over every progress row of the step, which is the
// bug dashboard_steps() fixed on 2026-10-07 and this table never got: Color Script,
// re-scoped from shots to the whole project, kept its 21 old shot rows and read
// "22 of 1" tracked. Those rows are real data and stay; they are just not counted,
// here or on the dashboard. One reader also means the lists behind each number
// (project_step_items.php) agree with it, since both use progress_measure_sql().
$steps = dashboard_steps($pdo, $projectId, $project['delivery_date'] ?: null);

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
    $steps,
    $project['delivery_date'] ?: null,
    dashboard_pace($pdo, $projectId, $project['delivery_date'] ?: null)
);

$pageTitle = $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'overview'); ?>

<?php // The three exports sit at the top right, beside the title (David, 2026-11-16:
      // "let's change these buttons to the top of the page on the right side"). They
      // used to follow the progress table at the very bottom of the page. Each names
      // the FILE it produces, so the row reads as a choice of three sheets. ?>
<div class="page-head project-head">
  <h2 class="wizard-title">
    <?= e($project['name']) ?> <span class="muted">(<?= e($project['code']) ?>)</span>
    <span class="status status-<?= e($project['status']) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
  </h2>
  <?php if (!hidden_from_viewers('export')): ?>
    <div class="export-row">
      <a class="button secondary" href="export.php?project=<?= $projectId ?>">Export progress ↓</a>
      <a class="button secondary" href="export.php?project=<?= $projectId ?>&amp;what=shots">Export shot list ↓</a>
      <a class="button secondary" href="export.php?project=<?= $projectId ?>&amp;what=assets">Export asset list ↓</a>
    </div>
  <?php endif; ?>
</div>

<?php
// THE WAY TO SIGN-OFF FROM THE PROJECT'S OWN PAGE (David, 2026-11-03: "there
// should be a button in project home to ask for sign off"). Until now it was
// reached only by Finish on the last wizard screen, or by picking Active on the
// project list, and neither is where you are when you look a set-up project
// over. The button lands on kickoff.php with send=1, the same place Finish goes,
// so the warning, the freeze and the choice of signers are all unchanged.
//
// Setup is finished when it has pipeline steps, the one thing Finish insists
// on; without them the button sends you back to the wizard instead.
if ($project['status'] === 'setup' && can_edit()):
    require_once __DIR__ . '/includes/kickoff.php';
    $kick = kickoff_ready($pdo) ? kickoff_current($pdo, $projectId) : null;
    $kickState = $kick['state'] ?? '';
    $hasSteps = (bool)pipeline_steps($pdo, $projectId);
?>
  <div class="flash flash-warn setup-signoff">
    <?php if (!kickoff_ready($pdo)): ?>
      This project is still in set-up. <a href="wizard.php?project=<?= $projectId ?>&amp;step=7">Finish setup</a>
      to make it active.
    <?php elseif ($kickState === 'pending'): ?>
      Waiting for sign-off. The set-up is frozen until everyone asked has approved.
      <a class="button secondary" href="kickoff.php?project=<?= $projectId ?>">See the sign-off</a>
    <?php elseif (!$hasSteps): ?>
      This project is still in set-up. Add its pipeline steps before asking for sign-off.
      <a class="button secondary" href="wizard.php?project=<?= $projectId ?>&amp;step=2">Resume setup</a>
    <?php else: ?>
      <?= $kickState === 'changes_requested'
          ? 'Changes were asked for at sign-off. Once they are made, ask again.'
          : 'This project is still in set-up. When the set-up is finished, ask for it to be signed off.' ?>
      <?php if ($kickState === 'changes_requested'): ?>
        <a href="kickoff.php?project=<?= $projectId ?>">Read the note</a>
      <?php endif; ?>
      <a class="button" href="kickoff.php?project=<?= $projectId ?>&amp;send=1">Ask for sign-off</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

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

<?php // The "N tasks scheduled. N crew slots, N still a placeholder." line that
      // sat here is gone (David, 2026-11-16: "let's delete this under the
      // overall progress bar"). The crew figures are already in the summary at
      // the top ("21 Crew · 20 named · 1 still open"), and the schedule has its
      // own tab. ?>

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
    <?php foreach ($steps as $d):
      $s    = $d['step'];
      $type = $s['applies_to_asset_type'] ?? null;
      // "of N" is every item the step covers, N/A ones included, as this column
      // has always read: step_applicable_count() with nothing excluded. A whole-
      // project step is one deliverable; a type-scoped step counts only its type.
      $applicable = step_applicable_count(array_merge($s, ['excluded' => 0]),
                                          $shotCount, $assetTypeCounts, $assetCount);
      // A count that opens the list behind it (David, 2026-11-16). A 0 stays plain
      // text: there is nothing to list.
      $countCell = function (int $n, string $what, bool $strong = false) use ($d, $projectId): string {
          $txt = $strong ? '<strong>' . $n . '</strong>' : (string)$n;
          if ($n <= 0) return $txt;
          return '<button type="button" class="psi-count" data-project="' . $projectId . '"'
               . ' data-step="' . (int)$d['step']['id'] . '" data-what="' . e($what) . '"'
               . ' aria-expanded="false"'
               . ' title="See the ' . $n . ' ' . e($what) . '">' . $txt . '</button>';
      };
    ?>
    <tr>
      <td class="wf-name"><span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span><?= e($s['name']) ?></td>
      <td class="muted"><?= e(applies_to_phrase($s['applies_to'], $type)) ?></td>
      <td><?= $countCell((int)$d['tracked'], 'tracked') ?> <span class="muted">of <?= $applicable ?></span></td>
      <td><?= $countCell((int)$d['delivered'], 'delivered') ?></td>
      <td><?= $countCell((int)$d['approved'], 'approved', true) ?></td>
      <td class="muted"><?= e($s['deadline'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php if ($steps): ?>
<?php // SPELL'S TASK PANEL, borrowed as Reviews and My tasks borrow it, so an item
      // in one of the lists above opens in place, with its versions and notes. ?>
<div class="board-panel-veil" id="boardVeil" hidden></div>
<aside class="board-panel" id="boardPanel" role="dialog" aria-modal="true"
       aria-label="Task details" hidden>
  <button type="button" class="board-panel-close" id="boardPanelClose" aria-label="Close">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.1" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
  </button>
  <div class="board-panel-inner" id="boardPanelInner"></div>
</aside>
<script src="assets/mentions.js<?= asset_ver('mentions.js') ?>"></script>
<script src="assets/dropzone.js<?= asset_ver('dropzone.js') ?>"></script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<script src="assets/board-screen.js<?= asset_ver('board-screen.js') ?>"></script>
<script src="assets/review-files.js<?= asset_ver('review-files.js') ?>"></script>
<script src="assets/review-viewer.js<?= asset_ver('review-viewer.js') ?>"></script>
<script src="assets/progress-lists.js<?= asset_ver('progress-lists.js') ?>"></script>
<?php endif; ?>

</div>
</body></html>
