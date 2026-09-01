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

// ---------------------------------------------------------------------------
// MILESTONES — dates the plan has to bend around: a client review, a delivery,
// a lock. Added by hand and never derived, so the scheduler cannot move them.
//
// Drawn as a line DOWN the chart rather than a bar on it, because the useful
// question about a review date is which bars cross it.
// ---------------------------------------------------------------------------
$msReady = column_exists($pdo, 'project_milestones', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['milestone_action'])) {
    try {
        if (!$msReady) {
            throw new RuntimeException(
                'The database update that adds milestones has not been applied yet (migration '
              . '028), so nothing was saved. An administrator can apply it from Migrations.'
            );
        }
        $act = (string)$_POST['milestone_action'];

        if ($act === 'add') {
            $name = trim((string)($_POST['ms_name'] ?? ''));
            $date = trim((string)($_POST['ms_date'] ?? ''));
            $note = trim((string)($_POST['ms_notes'] ?? ''));
            if ($name === '') throw new RuntimeException('Give the milestone a name.');
            if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
            // A real calendar date, not just the right shape: 2026-02-31 matches
            // any pattern and is not a day.
            $parts = explode('-', $date);
            if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                throw new RuntimeException('Give the milestone a date.');
            }
            $pdo->prepare(
                'INSERT INTO project_milestones (project_id, name, milestone_date, notes)
                 VALUES (?, ?, ?, ?)'
            )->execute([$projectId, $name, $date, $note !== '' ? mb_substr($note, 0, 255) : null]);
            flash('ok', sprintf('Added "%s" on %s.', $name, $date));

        } elseif (preg_match('/^edit:(\d+)$/', $act, $mm)) {
            $name = trim((string)($_POST['ms_name'] ?? ''));
            $date = trim((string)($_POST['ms_date'] ?? ''));
            $note = trim((string)($_POST['ms_notes'] ?? ''));
            if ($name === '') throw new RuntimeException('A milestone needs a name.');
            $parts = explode('-', $date);
            if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                throw new RuntimeException('A milestone needs a date.');
            }
            $upd = $pdo->prepare(
                'UPDATE project_milestones SET name = ?, milestone_date = ?, notes = ?
                  WHERE id = ? AND project_id = ?'
            );
            $upd->execute([mb_substr($name, 0, 120), $date,
                           $note !== '' ? mb_substr($note, 0, 255) : null,
                           (int)$mm[1], $projectId]);
            flash('ok', sprintf('Updated "%s" — now on %s.', $name, $date));

        } elseif (preg_match('/^remove:(\d+)$/', $act, $mm)) {
            $del = $pdo->prepare('DELETE FROM project_milestones WHERE id = ? AND project_id = ?');
            $del->execute([(int)$mm[1], $projectId]);
            flash('ok', $del->rowCount() ? 'Milestone removed.' : 'That milestone no longer exists.');

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: timeline.php?project=' . $projectId);
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
//
// THE PLAN, NOT THE SCHEDULE (David, 2026-08-31). This used to span
// MIN/MAX(schedule) for the step and fall back to its planned dates only when
// nothing was scheduled. That made the bar a DERIVED value, which is why
// dragging it never stuck: there was nothing to write to, and a reload
// recomputed it from the scheduled items.
//
// Now the bar IS pipeline_steps.start_date / deadline — the same two fields the
// Dates & headcount screen edits. Drag it and you are editing that screen
// graphically; edit that screen and the bar moves. One number, two ways in.
//
// The detailed chart below still shows where work actually landed, which is the
// half this one deliberately no longer answers.
$depsMap = step_dependencies_map($pdo, $projectId);

$general = [];
$undated = [];          // steps with no window yet: reported, never silently dropped
foreach ($steps as $s) {
    $start = $s['start_date'] ?: null;
    $end   = $s['deadline'] ?: null;
    if (!$start || !$end) { $undated[] = $s['name']; continue; }
    // A backwards pair would draw as a negative-width bar. The planning screen
    // already flags it in red; here it is simply clamped so the chart still draws.
    if ($end < $start) $end = $start;

    // DEPENDENCIES ARE DRAWN, NOT ENFORCED. The arrows come from the workflow
    // graph so the shape is visible while you drag, but nothing here blocks a
    // move and nothing snaps back — David plans the general timeline to the
    // dates, and accepts that a dependency-respecting schedule may run longer.
    // A violated arrow turns red in the browser and that is the whole of it.
    $depIds = [];
    foreach ($depsMap[(int)$s['id']] ?? [] as $d) $depIds[] = 'step-' . (int)$d;

    $general[] = [
        'id'         => 'step-' . (int)$s['id'],
        'step_id'    => (int)$s['id'],
        'name'       => $s['name'] . ' · ' . applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null),
        'start'      => $start,
        'end'        => $end,
        'progress'   => 0,
        'color'      => $s['color'] ?: '#4A90D9',
        // Frappe 0.6.1 reads this as a comma-separated list of task ids.
        'dependencies' => implode(',', $depIds),
        // Late now means "past the DELIVERY date". It used to mean "the schedule
        // ran past the step's own deadline", which cannot happen any more: the
        // bar and the deadline are the same field.
        'delivery'   => $project['delivery_date'] ?: null,
        'late'       => ($project['delivery_date'] && $end > $project['delivery_date']) ? 1 : 0,
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

// The milestones themselves, for the charts and the panel below them.
$milestones = [];
if ($msReady) {
    $ms = $pdo->prepare(
        'SELECT id, name, milestone_date, notes FROM project_milestones
          WHERE project_id = ? ORDER BY milestone_date, id'
    );
    $ms->execute([$projectId]);
    foreach ($ms as $m) {
        $milestones[] = [
            'id'    => (int)$m['id'],
            'name'  => $m['name'],
            'date'  => $m['milestone_date'],
            'notes' => $m['notes'] ?? '',
        ];
    }
}

$pageTitle = 'Timeline · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'timeline'); ?>
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

<?php // The GENERAL chart no longer needs a schedule to exist: it draws the
      // planned window from Dates & headcount. So the page is only empty when
      // there is neither a plan nor a schedule — it used to hide both charts
      // whenever nothing had been scheduled, which hid a perfectly good plan. ?>
<?php if (!$rows && !$general): ?>
  <div class="empty-state">
    <h2>Nothing planned or scheduled yet</h2>
    <p>The general chart draws each step's planned dates; the detailed one draws the
       saved schedule. Neither exists yet.</p>
    <p><a class="button" href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Set the dates →</a>
       <a class="button secondary" href="schedule.php?project=<?= $projectId ?>">Open the schedule →</a></p>
  </div>
<?php else: ?>

<?php if ($undated): ?>
  <div class="flash flash-warn">
    <?= count($undated) ?> step<?= count($undated) === 1 ? ' has' : 's have' ?> no start or
    deadline yet, so <?= count($undated) === 1 ? 'it is' : 'they are' ?> not drawn below:
    <strong><?= e(implode(', ', array_slice($undated, 0, 8))) ?></strong><?php
      if (count($undated) > 8): ?> and <?= count($undated) - 8 ?> more<?php endif; ?>.
    <a href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Set them on Dates &amp; headcount →</a>
  </div>
<?php endif; ?>

<?php if ($general): ?>
<section class="tl-section">
  <div class="tl-head">
    <h3>General · pipeline steps</h3>
    <div class="tl-controls">
      <span class="zoom-controls">
        <button type="button" class="secondary zoom-btn" onclick="zoomGeneral(-1)" title="Zoom out">−</button>
        <span id="generalZoomLabel" class="zoom-label">Week</span>
        <button type="button" class="secondary zoom-btn" onclick="zoomGeneral(1)" title="Zoom in">+</button>
      </span>
      <button type="button" class="secondary" onclick="downloadTimeline('generalGantt','GeneralTimeline','png')">PNG</button>
      <button type="button" class="secondary" onclick="downloadTimeline('generalGantt','GeneralTimeline','pdf')">PDF</button>
    </div>
  </div>
  <div id="generalGantt" class="tl-chart"></div>
  <?php if (can_edit()): ?>
    <p class="muted small tl-note">
      Drag a bar to change that step's dates — it saves straight into
      <a href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Dates &amp; headcount</a>,
      so the two always agree. Arrows show what the
      <a href="<?= e('wizard.php?project=' . $projectId . '&step=3') ?>">workflow</a> says waits
      for what; a <strong class="dep-legend">red one</strong> means you have planned a step to
      start before the thing it waits for has finished. That is allowed here — it is a warning,
      not a wall — but the schedule will not be able to honour it.
      <?php // A drag here writes to a screen you are not looking at, which is
            // exactly when a silent save feels like a lost one. Elsewhere in
            // this file saving is silent because the bar you moved is the thing
            // that changed; here it is not. ?>
      <span id="stepSaveNote" class="tl-saved" hidden></span>
    </p>
  <?php endif; ?>

  <?php // ---- milestones ------------------------------------------------- ?>
  <?php if (!$msReady): ?>
    <p class="muted small">Milestones need database update <strong>028</strong>.
      <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?></p>
  <?php else: ?>
    <div class="ms-panel">
      <h4>Milestones <span class="muted">· client reviews, deliveries, locks</span></h4>
      <p class="muted small">
        A date the plan has to be ready for, drawn as a
        <span class="ms-flag">dashed line</span> down both charts. Typed by hand and never moved
        by the scheduler — the point of a review date is that the work bends around it, not the
        other way about.
      </p>

      <?php if ($milestones): ?>
        <table>
          <tr><th>Date</th><th>Milestone</th><th>Notes</th><th></th></tr>
          <?php foreach ($milestones as $m): ?>
            <?php // Edited IN THE ROW rather than behind a disclosure. There
                  // are only ever a handful of these, and the whole reason to
                  // open the panel is to move a date — asking for a click before
                  // the field appears would be a click on every visit. ?>
            <tr>
              <?php if (can_edit()): ?>
                <td colspan="4">
                  <form method="post" class="inline-form">
                    <input type="date" name="ms_date" value="<?= e($m['date']) ?>">
                    <input name="ms_name" value="<?= e($m['name']) ?>" size="22" maxlength="120" required>
                    <input name="ms_notes" value="<?= e($m['notes']) ?>" size="24"
                           maxlength="255" placeholder="Notes (optional)">
                    <button type="submit" class="secondary" name="milestone_action"
                            value="edit:<?= (int)$m['id'] ?>">Save</button>
                    <button type="submit" class="linkish link-danger" name="milestone_action"
                            value="remove:<?= (int)$m['id'] ?>"
                            onclick="return confirm('Remove the milestone <?= e(addslashes($m['name'])) ?>?');"
                            formnovalidate>Remove</button>
                  </form>
                </td>
              <?php else: ?>
                <td><?= e($m['date']) ?></td>
                <td><strong class="ms-flag"><?= e($m['name']) ?></strong></td>
                <td class="muted"><?= e($m['notes']) ?></td>
                <td></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?>
        <p class="muted small">None yet.</p>
      <?php endif; ?>

      <?php if (can_edit()): ?>
        <form method="post" class="inline-form">
          <input name="ms_name" placeholder="e.g. Client review 01" size="24" maxlength="120">
          <input type="date" name="ms_date" value="<?= e($project['delivery_date'] ?: '') ?>">
          <input name="ms_notes" placeholder="Notes (optional)" size="26" maxlength="255">
          <button type="submit" class="secondary" name="milestone_action" value="add">Add milestone</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($rows): ?>
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

      <button type="button" class="secondary" onclick="downloadTimeline('detailedGantt','DetailedTimeline','png')">PNG</button>
      <button type="button" class="secondary" onclick="downloadTimeline('detailedGantt','DetailedTimeline','pdf')">PDF</button>
      <button type="button" class="secondary" onclick="downloadGan()"
              title="Download as a GanttProject file, honouring the filters above">.gan ↓</button>
      <a class="tl-linkbtn" href="timeline_import.php?project=<?= $projectId ?>"
         title="Read a refined GanttProject file back into the schedule">.gan ↑</a>

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
<?php endif; ?>   <?php // closes: the detailed chart needs a saved schedule ?>

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
<?php // The project code, for naming downloads. Reduced to characters a filename
      // can carry on every operating system rather than trusted — it is typed by
      // a person and could hold a slash or a colon. ?>
window.TL_CODE      = <?= json_encode(
    trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$project['code']), '-') ?: 'project'
) ?>;
window.TL_CAN_EDIT  = <?= can_edit() ? 'true' : 'false' ?>;
window.MILESTONES   = <?= json_encode($milestones, JSON_UNESCAPED_UNICODE) ?>;
window.GENERAL_TASKS = <?= json_encode($general, JSON_UNESCAPED_UNICODE) ?>;
window.DETAILED_TASKS = <?= json_encode($tasks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/timeline.js<?= asset_ver('timeline.js') ?>"></script>

<?php endif; ?>

</div>
</body></html>
