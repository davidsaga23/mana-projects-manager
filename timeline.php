<?php
// Phase 5 — the timeline.
//
// Modelled directly on STFC's, which is the version people actually used: two
// charts, filters, zoom, and the labels sitting either side of the bar rather
// than in a fixed gutter. That last detail is what makes it readable at any
// zoom — the assignee stays glued to the left edge of its bar and the item name
// to the right, so nothing has to line up with a column far off screen.
//
// Frappe Gantt does the drawing, as it did on STFC. Two date traps come with it,
// both already paid for once and both commented where they bite:
//   * its `end` is the INCLUSIVE last day, which is this platform's convention
//     too — so nothing is added or subtracted anywhere;
//   * never toISOString() a date in the browser (lesson 3).

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
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

// Regenerate: recompute and save, without leaving the timeline.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate'])) {
    try {
        $plan = build_schedule($pdo, $project);
        if (!$plan['tasks']) {
            throw new RuntimeException('The plan came out empty — open the Schedule screen, ' .
                                       'which lists the reasons.');
        }
        $res = commit_schedule($pdo, $projectId, $plan);
        flash('ok', sprintf('Rescheduled %d task%s, replacing %d from the previous run. %s',
            $res['written'], $res['written'] === 1 ? '' : 's', $res['deleted'],
            $plan['kept'] ? count($plan['kept']) . ' hand-locked task(s) were left alone.'
                          : 'No hand-locked tasks were affected.'));
        foreach ($plan['problems'] as $p) flash('warn', $p);
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: timeline.php?project=' . $projectId);
    exit;
}

$steps    = pipeline_steps($pdo, $projectId);
$episodic = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';

// --- the detailed chart ------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT sc.id, sc.start_date, sc.end_date, sc.crew_id, sc.is_manual, sc.lane,
            sc.shot_id, sc.asset_id, sc.item_key,
            ps.id AS step_id, ps.name AS step_name, ps.step_order, ps.color,
            sh.episode, sh.shot_code, a.name AS asset_name,
            c.name AS crew_name,
            COALESCE(pr.is_approved, 0) AS is_approved
       FROM schedule sc
       JOIN pipeline_steps ps ON ps.id = sc.step_id
       LEFT JOIN shots  sh ON sh.id = sc.shot_id
       LEFT JOIN assets a  ON a.id  = sc.asset_id
       LEFT JOIN crew   c  ON c.id  = sc.crew_id
       LEFT JOIN progress pr ON pr.step_id = sc.step_id AND pr.item_key = sc.item_key
      WHERE sc.project_id = ?
      ORDER BY sc.start_date, ps.step_order, sc.lane, sc.id"
);
$stmt->execute([$projectId]);
$rows = $stmt->fetchAll();

$episodes = [];
$tasks = [];
foreach ($rows as $r) {
    // What the bar is FOR. A shot carries its episode when the project has them;
    // an asset is just its name; a band or whole-project deliverable has neither
    // and falls back to the step, which is exactly what it represents.
    if ($r['shot_code'] !== null) {
        $item = trim(($r['episode'] ? $r['episode'] . ' ' : '') . $r['shot_code']);
    } elseif ($r['asset_name'] !== null) {
        $item = $r['asset_name'];
    } else {
        $item = $r['step_name'];
    }
    if ($r['episode']) $episodes[$r['episode']] = true;

    $tasks[] = [
        'id'          => (string)$r['id'],
        // Frappe draws this to the RIGHT of the bar — the item and what is being
        // done to it, which is the pairing a supervisor scans for.
        'name'        => $item . ' · ' . $r['step_name'],
        'start'       => $r['start_date'],
        // NOT extended by a day. Frappe treats `end` as the inclusive last day and
        // stretches it internally; this platform stores it inclusive too. STFC had
        // both sides adding a day and the two errors hid each other for weeks.
        'end'         => $r['end_date'],
        'progress'    => (int)$r['is_approved'] === 1 ? 100 : 0,
        'episode'     => $r['episode'] ?? '',
        'step_id'     => (int)$r['step_id'],
        'step_name'   => $r['step_name'],
        'step_order'  => (int)$r['step_order'],
        'item_label'  => $item,
        'color'       => $r['color'] ?: '#4A90D9',
        'crew_id'     => $r['crew_id'] !== null ? (int)$r['crew_id'] : null,
        'crew_name'   => $r['crew_name'] ?? '',
        'is_approved' => (int)$r['is_approved'] === 1,
        'is_manual'   => (int)$r['is_manual'] === 1,
    ];
}
ksort($episodes);
$episodes = array_keys($episodes);

// --- the general chart: one bar per pipeline step ----------------------------
// Spans what is actually scheduled for that step, falling back to its planned
// dates when nothing is. A step with neither is left out rather than drawn as a
// zero-width sliver nobody can click.
$spanStmt = $pdo->prepare(
    'SELECT MIN(start_date) AS s, MAX(end_date) AS e FROM schedule WHERE step_id = ?'
);
$general = [];
foreach ($steps as $s) {
    $spanStmt->execute([(int)$s['id']]);
    $span = $spanStmt->fetch();
    $start = $span['s'] ?: ($s['start_date'] ?: null);
    $end   = $span['e'] ?: ($s['deadline'] ?: null);
    if (!$start || !$end) continue;
    if ($end < $start) $end = $start;
    $general[] = [
        'id'       => 'step-' . (int)$s['id'],
        'name'     => $s['name'] . ' · ' . applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null),
        'start'    => $start,
        'end'      => $end,
        'progress' => 0,
        'color'    => $s['color'] ?: '#4A90D9',
        'deadline' => $s['deadline'] ?: null,
        'late'     => ($s['deadline'] && $end > $s['deadline']) ? 1 : 0,
    ];
}

$crewList = $pdo->query(
    'SELECT id, name, role, is_placeholder FROM crew WHERE active = 1 ORDER BY is_placeholder, name'
)->fetchAll();

// Is what's drawn still what the setup implies?
//
// The saved schedule is a snapshot, and everything it was derived from keeps
// moving — asset types get merged, headcounts change, items are added. A stale
// plan looks exactly like a complete one: fewer bars, no warning, nothing to
// suggest the picture is out of date. So the plan is recomputed here purely to
// compare the COUNT against what is stored.
$fresh = build_schedule($pdo, $project);
$freshCount  = count($fresh['tasks']);
$storedCount = count($rows);
$stale = $freshCount !== $storedCount;

$pageTitle = 'Timeline · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>
<h2 class="wizard-title">Timeline</h2>

<?php if ($rows && $stale): ?>
  <div class="flash flash-warn">
    <strong>This timeline is out of date.</strong>
    It shows <?= $storedCount ?> saved task<?= $storedCount === 1 ? '' : 's' ?>, but the setup
    as it stands now works out to <strong><?= $freshCount ?></strong>.
    <?php if ($freshCount > $storedCount): ?>
      <?= $freshCount - $storedCount ?> task<?= ($freshCount - $storedCount) === 1 ? ' is' : 's are' ?>
      missing from the picture — usually because shots or assets were added, or an asset type
      was corrected, after this schedule was saved.
    <?php else: ?>
      <?= $storedCount - $freshCount ?> more <?= ($storedCount - $freshCount) === 1 ? 'is' : 'are' ?>
      drawn than the setup now calls for — usually because work was approved, marked not
      applicable, or items were removed.
    <?php endif; ?>
    <?php if (can_edit()): ?>Press <em>Regenerate</em> to bring it in line.<?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty-state">
    <h2>Nothing scheduled yet</h2>
    <p>The timeline draws the schedule that has been saved.</p>
    <p><a class="button" href="schedule.php?project=<?= $projectId ?>">Open the schedule →</a></p>
  </div>
<?php else: ?>

<section class="tl-section">
  <div class="tl-head">
    <h3>General · pipeline steps</h3>
    <div class="tl-controls">
      <span class="zoom-controls">
        <button type="button" class="secondary zoom-btn" onclick="zoomGeneral(-1)" title="Zoom out">−</button>
        <span id="generalZoomLabel" class="zoom-label">Week</span>
        <button type="button" class="secondary zoom-btn" onclick="zoomGeneral(1)" title="Zoom in">+</button>
      </span>
      <button type="button" class="secondary" onclick="downloadTimeline('generalGantt','general-timeline','png')">PNG</button>
      <button type="button" class="secondary" onclick="downloadTimeline('generalGantt','general-timeline','pdf')">PDF</button>
    </div>
  </div>
  <div id="generalGantt" class="tl-chart"></div>
</section>

<section class="tl-section">
  <div class="tl-head">
    <h3>Detailed schedule</h3>
    <div class="tl-controls">
      <?php if ($episodic && $episodes): ?>
        <label class="muted small">Episode
          <select id="episodeFilter">
            <option value="all">All episodes</option>
            <?php foreach ($episodes as $ep): ?>
              <option value="<?= e($ep) ?>"><?= e($ep) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <?php // A checkbox panel rather than <select multiple>, which needs ctrl+click. ?>
      <span class="muted small multiselect" id="stepFilterWrap">Step
        <button type="button" class="multiselect-toggle" id="stepFilterToggle"
                aria-haspopup="true" aria-expanded="false">
          <span id="stepFilterLabel">All steps</span> <span class="caret">▾</span>
        </button>
        <div class="multiselect-panel" id="stepFilterPanel" hidden>
          <label class="multiselect-opt"><input type="checkbox" id="stepFilterAll" checked>
            <strong>All steps</strong></label>
          <div class="multiselect-divider"></div>
          <?php foreach ($steps as $st): ?>
            <label class="multiselect-opt">
              <input type="checkbox" class="step-cb" value="<?= (int)$st['id'] ?>">
              <?= e($st['name']) ?>
              <span class="muted small"><?= e(applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </span>

      <label class="muted small"><input type="checkbox" id="hideCompleted"> Hide completed</label>

      <?php // Grouping, not just sorting: ordering by item puts every task for one
             // asset or shot together, which is how you read "what is left on Harry
             // Kane" rather than "what happens next week". ?>
      <label class="muted small">Order
        <select id="groupBy">
          <option value="date">By date</option>
          <option value="item">By asset / shot</option>
          <option value="step">By pipeline step</option>
        </select>
      </label>

      <span class="zoom-controls">
        <button type="button" class="secondary zoom-btn" onclick="zoomChange(-1)" title="Zoom out">−</button>
        <span id="zoomLabel" class="zoom-label">Week</span>
        <button type="button" class="secondary zoom-btn" onclick="zoomChange(1)" title="Zoom in">+</button>
      </span>

      <button type="button" class="secondary" onclick="downloadTimeline('detailedGantt','detailed-timeline','png')">PNG</button>
      <button type="button" class="secondary" onclick="downloadTimeline('detailedGantt','detailed-timeline','pdf')">PDF</button>
      <button type="button" class="secondary" onclick="downloadGan()"
              title="Download as a GanttProject file, honouring the filters above">.gan ↓</button>

      <?php if (can_edit()): ?>
        <form method="post" class="tl-inline"
              onsubmit="return confirm('Recalculate and save the schedule? Hand-locked tasks are preserved.');">
          <input type="hidden" name="regenerate" value="1">
          <button type="submit">Regenerate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <p class="muted small">
    <?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?> scheduled ·
    the name to the <strong>left</strong> of each bar is who is on it — a placeholder slot
    until somebody is hired · <span class="warn-text">amber outline</span> = hand-locked, the
    scheduler won't move it · click a bar to edit it, or drag it.
  </p>
  <div id="detailedGantt" class="tl-chart tl-chart-tall"></div>
</section>

<div id="taskEditor" class="tl-modal" style="display:none;">
  <div class="tl-modal-box">
    <h3>Edit task</h3>
    <p id="taskLabel" class="muted"></p>
    <form id="taskEditForm">
      <input type="hidden" id="editTaskId">
      <label>Starts <input type="date" id="editStart" required></label>
      <label>Finishes <input type="date" id="editEnd" required>
        <small>Inclusive — the last day worked.</small></label>
      <label>Assigned to
        <select id="editCrew">
          <option value="">— nobody —</option>
          <?php foreach ($crewList as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?php
              if ((int)$c['is_placeholder'] === 1) echo ' (slot)'; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="inline"><input type="checkbox" id="editManual">
        Lock this task — Regenerate won't move it</label>
      <div class="actions">
        <button type="button" class="secondary" onclick="closeTaskEditor()">Cancel</button>
        <button type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.css">
<script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
window.TL_PROJECT   = <?= (int)$projectId ?>;
window.TL_CAN_EDIT  = <?= can_edit() ? 'true' : 'false' ?>;
window.GENERAL_TASKS = <?= json_encode($general, JSON_UNESCAPED_UNICODE) ?>;
window.DETAILED_TASKS = <?= json_encode($tasks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/timeline.js<?= asset_ver('timeline.js') ?>"></script>

<?php endif; ?>

</div>
</body></html>
