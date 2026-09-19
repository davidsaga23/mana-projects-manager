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
//
// VERSIONS (2026-09-18). "Save timeline's version" keeps a copy of what this
// page draws, and the Version picker opens one again: ?version=ID draws that
// copy instead of the live tables, on a tinted page, with every control that
// would change something taken away. includes/timeline_versions.php builds the
// drawing for both, so a version can only look the way this page did.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/scheduler.php';
require __DIR__ . '/includes/timeline_versions.php';
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

/**
 * A step id that really belongs to this project, or null.
 *
 * Never trusted from the form: an id from another show would attach a review to
 * a bar this chart cannot draw, and the milestone would then be invisible with
 * no way to see why.
 */
function ms_step_or_null(PDO $pdo, int $projectId, $raw): ?int {
    $id = (int)$raw;
    if ($id <= 0) return null;
    $q = $pdo->prepare('SELECT id FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $q->execute([$id, $projectId]);
    return $q->fetchColumn() === false ? null : $id;
}

// ---------------------------------------------------------------------------
// MILESTONES — dates the plan has to bend around: a client review, a delivery,
// a lock. Added by hand and never derived, so the scheduler cannot move them.
//
// Drawn as a line DOWN the chart rather than a bar on it, because the useful
// question about a review date is which bars cross it.
// ---------------------------------------------------------------------------
$msReady = column_exists($pdo, 'project_milestones', 'id');
// 029 adds the link to a pipeline step. Checked separately so the screen keeps
// working on a database where 028 has run and 029 has not.
$msSteps = $msReady && column_exists($pdo, 'project_milestones', 'step_id');

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
            // Verified against THIS project's steps rather than trusted: a step
            // id from another show would otherwise attach a review to a bar
            // this chart cannot draw.
            $stepId = ms_step_or_null($pdo, $projectId, $_POST['ms_step'] ?? '');
            if ($msSteps) {
                $pdo->prepare(
                    'INSERT INTO project_milestones (project_id, step_id, name, milestone_date, notes)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$projectId, $stepId, $name, $date,
                            $note !== '' ? mb_substr($note, 0, 255) : null]);
            } else {
                $pdo->prepare(
                    'INSERT INTO project_milestones (project_id, name, milestone_date, notes)
                     VALUES (?, ?, ?, ?)'
                )->execute([$projectId, $name, $date, $note !== '' ? mb_substr($note, 0, 255) : null]);
            }
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
            if ($msSteps) {
                $stepId = ms_step_or_null($pdo, $projectId, $_POST['ms_step'] ?? '');
                $upd = $pdo->prepare(
                    'UPDATE project_milestones SET name = ?, milestone_date = ?, notes = ?, step_id = ?
                      WHERE id = ? AND project_id = ?'
                );
                $upd->execute([mb_substr($name, 0, 120), $date,
                               $note !== '' ? mb_substr($note, 0, 255) : null, $stepId,
                               (int)$mm[1], $projectId]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE project_milestones SET name = ?, milestone_date = ?, notes = ?
                      WHERE id = ? AND project_id = ?'
                );
                $upd->execute([mb_substr($name, 0, 120), $date,
                               $note !== '' ? mb_substr($note, 0, 255) : null,
                               (int)$mm[1], $projectId]);
            }
            flash('ok', sprintf('Updated "%s", now on %s.', $name, $date));

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
            throw new RuntimeException('The plan came out empty, open the Schedule screen, ' .
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

// ---------------------------------------------------------------------------
// VERSIONS — a saved copy of what this page draws, to look back at later.
// Numbered per project, V00_09182026 onwards (includes/timeline_versions.php).
// ---------------------------------------------------------------------------
$tvReady = timeline_versions_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['version_action'])) {
    try {
        if (!$tvReady) {
            throw new RuntimeException(
                'The database update that keeps timeline versions has not been applied yet '
              . '(migration 035), so nothing was saved. An administrator can apply it from Migrations.'
            );
        }
        $v = save_timeline_version($pdo, $project, current_user_display());
        // What was kept, not just that something was: a version holding no
        // schedule reads very differently from one holding 400 tasks.
        flash('ok', sprintf(
            'Saved as %s: %d step bar%s, %d scheduled task%s and %d milestone%s. '
          . 'Pick it under Version to look at it again.',
            $v['label'],
            $v['steps'], $v['steps'] === 1 ? '' : 's',
            $v['tasks'], $v['tasks'] === 1 ? '' : 's',
            $v['milestones'], $v['milestones'] === 1 ? '' : 's'
        ));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: timeline.php?project=' . $projectId);
    exit;
}

// Which timeline this page draws: the live one, or a saved version.
$version   = null;
$versionId = (int)($_GET['version'] ?? 0);
if ($versionId > 0) {
    try {
        $version = $tvReady ? load_timeline_version($pdo, $projectId, $versionId) : null;
        if ($version === null) {
            throw new RuntimeException('That saved version is not on this project, so here is the '
                                     . 'current timeline instead.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        header('Location: timeline.php?project=' . $projectId);
        exit;
    }
}

// NOTHING ON A SAVED VERSION CAN BE CHANGED. Every control that writes asks
// this rather than can_edit(). The bars of an old version carry the ids of the
// live rows, so a drag there would save into the CURRENT plan, putting today's
// bar back where it sat last week, while the person dragging believes they are
// only looking. Nothing is quieter or worse than that.
$editable = can_edit() && $version === null;

// Progress recorded by hand on a step (migration 036). Until it has run the step
// editor shows its progress box switched off, with the reason, rather than
// offering a field that cannot save.
$progressReady = column_exists($pdo, 'pipeline_steps', 'progress_pct');

$steps = pipeline_steps($pdo, $projectId);
$pic   = $version !== null ? $version['picture'] : timeline_picture($pdo, $project, $steps);

$general     = $pic['general'];
$tasks       = $pic['tasks'];
$milestones  = $pic['milestones'];
$filterSteps = $pic['steps'];
$episodes    = $pic['episodes'];
$episodic    = !empty($pic['episodic']);
$undated     = $pic['undated'];

$versions = $tvReady ? timeline_versions($pdo, $projectId) : [];

// Only for the task editor, which a saved version does not draw.
$crewList = $editable ? $pdo->query(
    'SELECT id, name, role, is_placeholder FROM crew WHERE active = 1 ORDER BY is_placeholder, name'
)->fetchAll() : [];

// Is what's drawn still what the setup implies?
//
// The saved schedule is a snapshot, and everything it was derived from keeps
// moving — asset types get merged, headcounts change, items are added. A stale
// plan looks exactly like a complete one: fewer bars, no warning, nothing to
// suggest the picture is out of date. So the plan is recomputed here purely to
// compare the COUNT against what is stored.
//
// Live timeline only. A saved version is SUPPOSED to differ from today's setup;
// that is what it is for.
$stale       = false;
$freshCount  = 0;
$storedCount = count($tasks);
if ($version === null) {
    $fresh      = build_schedule($pdo, $project);
    $freshCount = count($fresh['tasks']);
    $stale      = $freshCount !== $storedCount;
}

$pageTitle = 'Timeline · ' . $project['name'] . ($version !== null ? ' · ' . $version['label'] : '');
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'timeline'); ?>
<h2 class="wizard-title">Timeline</h2>

<?php // A SAVED VERSION IS DRAWN ON WARM PAPER (.tl-page-old), from here to the
      // foot of the page, so it cannot be mistaken for the plan being worked on.
      // The live page gets the same wrapper with no tint, so the two share one
      // layout and only the colour changes. ?>
<?php // tl-readonly hides the bars' stretch and progress handles, which would
      // otherwise be offered to somebody whose drag can only snap back. ?>
<div class="tl-page<?= $version !== null ? ' tl-page-old' : '' ?><?= $editable ? '' : ' tl-readonly' ?>">

<?php // ---- versions ---------------------------------------------------------
      // One bar above both charts, because a version is both charts. On an old
      // version it becomes the banner that says so, with the way back beside
      // the picker rather than somewhere else on the page. ?>
<?php if ($tvReady): ?>
  <div class="tl-versions<?= $version !== null ? ' is-old' : '' ?>">
    <?php if ($version !== null): ?>
      <div class="tl-version-msg">
        <strong>You are looking at <?= e($version['label']) ?></strong>, saved on
        <?= e(substr($version['created_at'], 0, 10)) ?> at <?= e(substr($version['created_at'], 11, 5)) ?><?php
          if ($version['created_by'] !== ''): ?> by <?= e($version['created_by']) ?><?php endif; ?>.
        It is a saved copy, not the current timeline, so nothing on it can be moved or changed.
      </div>
    <?php endif; ?>

    <?php // A plain GET form, so picking a version is an ordinary page load
          // with its own address: it can be bookmarked, reloaded, or sent to
          // somebody, and it works even if the chart script does not. ?>
    <form method="get" class="tl-version-pick">
      <input type="hidden" name="project" value="<?= (int)$projectId ?>">
      <label for="tlVersion">Version</label>
      <select id="tlVersion" name="version" onchange="this.form.submit()">
        <option value="">Current timeline</option>
        <?php foreach ($versions as $v): ?>
          <option value="<?= (int)$v['id'] ?>"<?= $version !== null && $version['id'] === $v['id'] ? ' selected' : '' ?>><?=
            e($v['label']) ?> · <?= e($v['time']) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="secondary">Show</button></noscript>
    </form>

    <?php if ($version !== null): ?>
      <a class="button" href="timeline.php?project=<?= (int)$projectId ?>">See the current timeline</a>
    <?php elseif (can_edit()): ?>
      <?php // The action rides in a hidden field, not on the button: the button
            // is disabled as the form goes, so a double click cannot save two
            // identical versions, and a disabled button is not posted. ?>
      <form method="post" class="tl-version-save" onsubmit="this.querySelector('button').disabled = true;">
        <input type="hidden" name="version_action" value="save">
        <button type="submit">Save timeline's version</button>
      </form>
    <?php endif; ?>

    <span class="muted small">
      <?php if (!$versions): ?>
        No versions saved yet.
      <?php elseif ($version === null): ?>
        <?= count($versions) ?> saved, the latest is <?= e($versions[0]['label']) ?>.
      <?php else: ?>
        <?= count($versions) ?> saved.
      <?php endif; ?>
    </span>
  </div>
<?php elseif (can_edit()): ?>
  <p class="muted small">Saving versions of this timeline needs database update <strong>035</strong>.
    <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?></p>
<?php endif; ?>

<?php if ($tasks && $stale): ?>
  <div class="flash flash-warn">
    <strong>This timeline is out of date.</strong>
    It shows <?= $storedCount ?> saved task<?= $storedCount === 1 ? '' : 's' ?>, but the setup
    as it stands now works out to <strong><?= $freshCount ?></strong>.
    <?php if ($freshCount > $storedCount): ?>
      <?= $freshCount - $storedCount ?> task<?= ($freshCount - $storedCount) === 1 ? ' is' : 's are' ?>
      missing from the picture, usually because shots or assets were added, or an asset type
      was corrected, after this schedule was saved.
    <?php else: ?>
      <?= $storedCount - $freshCount ?> more <?= ($storedCount - $freshCount) === 1 ? 'is' : 'are' ?>
      drawn than the setup now calls for, usually because work was approved, marked not
      applicable, or items were removed.
    <?php endif; ?>
    <?php if ($editable): ?>Press <em>Regenerate</em> to bring it in line.<?php endif; ?>
  </div>
<?php endif; ?>

<?php // The GENERAL chart no longer needs a schedule to exist: it draws the
      // planned window from Dates & headcount. So the page is only empty when
      // there is neither a plan nor a schedule — it used to hide both charts
      // whenever nothing had been scheduled, which hid a perfectly good plan. ?>
<?php if (!$tasks && !$general): ?>
  <div class="empty-state">
    <?php if ($version !== null): ?>
      <h2>Nothing was drawn in this version</h2>
      <p>No step had dates and no schedule had been saved when it was taken.</p>
    <?php else: ?>
      <h2>Nothing planned or scheduled yet</h2>
      <p>The general chart draws each step's planned dates; the detailed one draws the
         saved schedule. Neither exists yet.</p>
      <p><a class="button" href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Set the dates →</a>
         <a class="button secondary" href="schedule.php?project=<?= $projectId ?>">Open the schedule →</a></p>
    <?php endif; ?>
  </div>
<?php else: ?>

<?php if ($undated && $version === null): ?>
  <div class="flash flash-warn">
    <?= count($undated) ?> step<?= count($undated) === 1 ? ' has' : 's have' ?> no start or
    deadline yet, so <?= count($undated) === 1 ? 'it is' : 'they are' ?> not drawn below:
    <strong><?= e(implode(', ', array_slice($undated, 0, 8))) ?></strong><?php
      if (count($undated) > 8): ?> and <?= count($undated) - 8 ?> more<?php endif; ?>.
    <a href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Set them on Dates &amp; headcount →</a>
  </div>
<?php elseif ($undated): ?>
  <p class="muted small">
    <?= count($undated) ?> step<?= count($undated) === 1 ? '' : 's' ?> had no dates when this
    version was saved, so <?= count($undated) === 1 ? 'it is' : 'they are' ?> not drawn:
    <strong><?= e(implode(', ', array_slice($undated, 0, 8))) ?></strong><?php
      if (count($undated) > 8): ?> and <?= count($undated) - 8 ?> more<?php endif; ?>.
  </p>
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
  <?php if ($editable): ?>
    <p class="muted small tl-note">
      Drag a bar to change that step's dates, it saves straight into
      <a href="<?= e('wizard.php?project=' . $projectId . '&step=4') ?>">Dates &amp; headcount</a>,
      so the two always agree. A moved bar keeps its <strong>working days</strong>: slid over a
      weekend or a studio holiday it stretches, rather than losing a day to it.
      <strong>Double-click</strong> a bar to type its dates, see how many working days it needs,
      and record its progress. Arrows show what the
      <a href="<?= e('wizard.php?project=' . $projectId . '&step=3') ?>">workflow</a> says waits
      for what; a <strong class="dep-legend">red one</strong> means you have planned a step to
      start before the thing it waits for has finished. That is allowed here, it is a warning,
      not a wall, but the schedule will not be able to honour it.
      <?php // A drag here writes to a screen you are not looking at, which is
            // exactly when a silent save feels like a lost one. Elsewhere in
            // this file saving is silent because the bar you moved is the thing
            // that changed; here it is not. ?>
      <span id="stepSaveNote" class="tl-saved" hidden></span>
    </p>
  <?php endif; ?>

  <?php // ---- milestones ------------------------------------------------- ?>
  <?php if (!$msReady && $version === null): ?>
    <p class="muted small">Milestones need database update <strong>028</strong>.
      <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?></p>
  <?php else: ?>
    <div class="ms-panel">
      <h4>Milestones <span class="muted">· client reviews, deliveries, locks</span></h4>
      <p class="muted small">
        A date the plan has to be ready for, drawn as a
        <span class="ms-flag">dashed line</span> down both charts. Typed by hand and never moved
        by the scheduler, the point of a review date is that the work bends around it, not the
        other way about.
        <?php if ($editable): ?>
          <br><strong>Drag a marker</strong> on the chart to move it, or
          <strong>double-click</strong> it to change it, the same gestures the task bars use.
          Grab it anywhere down the line, not just the diamond.
          <?php if ($msSteps): ?>
            <br>Tie one to a step and its marker sits on <strong>that step's row</strong> instead
            of at the top, and turns <strong class="err">red</strong> if the date falls before
            that step finishes, a review of work that will not exist yet.
          <?php endif; ?>
        <?php endif; ?>
      </p>

      <?php if ($milestones): ?>
        <table>
          <tr><th>Date</th><th>Milestone</th><th>Notes</th><th></th></tr>
          <?php foreach ($milestones as $m): ?>
            <?php // A LIST, not a form. Editing happens in one place now — the
                  // modal a double-click opens — and three input boxes per row
                  // repeating that was the clutter David asked to lose.
                  //
                  // The row still OPENS that modal, though, rather than being
                  // inert text: a milestone dated before the chart begins is not
                  // drawn, so the chart cannot always be the way in to one. ?>
            <tr>
              <td><?= e($m['date']) ?></td>
              <td><strong class="ms-flag"><?= e($m['name']) ?></strong></td>
              <td class="muted">
                <?php if (!empty($m['step_name'])): ?>
                  <span class="ms-onstep">reviews <?= e($m['step_name']) ?></span>
                  <?php if (!empty($m['early'])): ?>
                    <span class="err small">that step runs to <?= e($m['step_end']) ?></span>
                  <?php endif; ?>
                  <?= $m['notes'] !== '' ? ' · ' : '' ?>
                <?php endif; ?>
                <?= e($m['notes']) ?>
              </td>
              <td class="row-actions">
                <?php if ($editable): ?>
                  <?php // The id only — the modal reads the rest from the same
                        // array the chart draws from, so a name with a quote in
                        // it needs no escaping into an onclick. ?>
                  <button type="button" class="linkish ms-edit"
                          data-ms="<?= (int)$m['id'] ?>">Edit</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?>
        <p class="muted small"><?= $version !== null ? 'None when this version was saved.' : 'None yet.' ?></p>
      <?php endif; ?>

      <?php if ($editable): ?>
        <form method="post" class="inline-form">
          <input name="ms_name" placeholder="e.g. Client review 01" size="24" maxlength="120">
          <input type="date" name="ms_date" value="<?= e($project['delivery_date'] ?: '') ?>">
          <input name="ms_notes" placeholder="Notes (optional)" size="26" maxlength="255">
          <?php if ($msSteps): ?>
            <select name="ms_step">
              <option value="">not tied to a step</option>
              <?php // The scope, not just the name. A pipeline legitimately has
                    // Concept for characters, for environments and for props —
                    // three options reading "Concept" is a guess, and the same
                    // applies_to_phrase() the bars are labelled with settles it. ?>
              <?php foreach ($steps as $st): ?>
                <option value="<?= (int)$st['id'] ?>"><?= e($st['name'] . ' · '
                    . applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="secondary" name="milestone_action" value="add">Add milestone</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tasks): ?>
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

      <?php // A checkbox panel rather than <select multiple>, which needs ctrl+click.
            // The steps come with the drawing, so an old version offers the
            // pipeline it was drawn with, even a step deleted since. ?>
      <span class="muted small multiselect" id="stepFilterWrap">Step
        <button type="button" class="multiselect-toggle" id="stepFilterToggle"
                aria-haspopup="true" aria-expanded="false">
          <span id="stepFilterLabel">All steps</span> <span class="caret">▾</span>
        </button>
        <div class="multiselect-panel" id="stepFilterPanel" hidden>
          <label class="multiselect-opt"><input type="checkbox" id="stepFilterAll" checked>
            <strong>All steps</strong></label>
          <div class="multiselect-divider"></div>
          <?php foreach ($filterSteps as $st): ?>
            <label class="multiselect-opt">
              <input type="checkbox" class="step-cb" value="<?= (int)$st['id'] ?>">
              <?= e($st['name']) ?>
              <span class="muted small"><?= e($st['scope']) ?></span>
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
      <?php // The .gan files read and write the LIVE schedule, so neither is
            // offered on a saved version: a download there would hand over
            // today's plan under an old version's name. ?>
      <?php if ($version === null): ?>
        <button type="button" class="secondary" onclick="downloadGan()"
                title="Download as a GanttProject file, honouring the filters above">.gan ↓</button>
        <a class="tl-linkbtn" href="timeline_import.php?project=<?= $projectId ?>"
           title="Read a refined GanttProject file back into the schedule">.gan ↑</a>
      <?php endif; ?>

      <?php if ($editable): ?>
        <form method="post" class="tl-inline"
              onsubmit="return confirm('Recalculate and save the schedule? Hand-locked tasks are preserved.');">
          <input type="hidden" name="regenerate" value="1">
          <button type="submit">Regenerate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <p class="muted small">
    <?php if ($version !== null): ?>
      <?= count($tasks) ?> task<?= count($tasks) === 1 ? ' was' : 's were' ?> scheduled when this
      version was saved · the name to the <strong>left</strong> of each bar is who was on it ·
      <span class="warn-text">amber outline</span> = hand-locked at the time.
    <?php else: ?>
      <?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?> scheduled ·
      the name to the <strong>left</strong> of each bar is who is on it, a placeholder slot
      until somebody is hired · <span class="warn-text">amber outline</span> = hand-locked, the
      scheduler won't move it · double-click a bar to edit it, or drag it, and it keeps its
      working days.
    <?php endif; ?>
  </p>
  <div id="detailedGantt" class="tl-chart tl-chart-tall"></div>
</section>
<?php elseif ($general): ?>
<?php // NO SAVED SCHEDULE, SAID RATHER THAN LEFT BLANK (David, 2026-09-18: "why
      // does the MSD project doesn't have a detailed timeline like FCM?"). This
      // section used to vanish outright, taking Regenerate with it, so a show
      // whose schedule had never been saved looked as if the feature did not
      // exist, with no hint of the one button that makes it. ?>
<section class="tl-section">
  <div class="tl-head"><h3>Detailed schedule</h3></div>
  <?php if ($version !== null): ?>
    <p class="muted">This version has no detailed schedule: none had been saved for the project
      when it was taken.</p>
  <?php else: ?>
    <p class="tl-note">
      <strong>Not built yet.</strong> This chart draws the saved schedule, every shot and asset on
      its own bar, and none has been saved for this project. The chart above does not need one:
      it draws each step's planned dates.
      <?php if ($freshCount > 0): ?>
        The setup as it stands works out to
        <strong><?= $freshCount ?> task<?= $freshCount === 1 ? '' : 's' ?></strong>.
      <?php else: ?>
        The setup as it stands does not work out to any tasks yet, and the Schedule screen
        lists why.
      <?php endif; ?>
    </p>
    <?php if ($editable): ?>
      <?php // Two real buttons, the pairing every other screen uses. A link
            // styled as a button renders in the page font and a <button> in
            // the browser's, so side by side they came out 5px apart in height. ?>
      <div class="actions">
        <?php if ($freshCount > 0): ?>
          <form method="post" class="tl-inline"
                onsubmit="return confirm('Work out the schedule from the setup and save it? You can regenerate it at any time.');">
            <input type="hidden" name="regenerate" value="1">
            <button type="submit">Build the detailed schedule</button>
          </form>
        <?php endif; ?>
        <form method="get" action="schedule.php" class="tl-inline">
          <input type="hidden" name="project" value="<?= (int)$projectId ?>">
          <button type="submit" class="secondary">Preview it on the Schedule screen</button>
        </form>
      </div>
    <?php else: ?>
      <p class="muted small">An editor can build it from the Schedule screen.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>   <?php // closes: the detailed chart needs a saved schedule ?>

<?php // Opened by DOUBLE-CLICKING a milestone marker, the same gesture that
      // opens a task. Posts to this page rather than the JSON API: a drag must
      // not reload, but finishing an edit may, and reusing the server-side
      // validation that already exists beats a second copy of it. ?>
<?php if ($msReady && $editable): ?>
<div id="msEditor" class="tl-modal" style="display:none;">
  <div class="tl-modal-box">
    <h3>Edit milestone</h3>
    <form method="post">
      <?php // The action rides on whichever button is pressed, set by the JS
            // that opens this. A hidden field of the same name would also be
            // posted, and PHP takes the last occurrence — so which one won
            // would depend on document order rather than on what was clicked. ?>
      <label>Name <input name="ms_name" id="msName" maxlength="120" required></label>
      <label>Date <input type="date" name="ms_date" id="msDate" required></label>
      <label>Notes <input name="ms_notes" id="msNotes" maxlength="255"></label>
      <?php if ($msSteps): ?>
        <label>Reviews which step
          <select name="ms_step" id="msStep">
            <option value="">not tied to a step</option>
            <?php foreach ($steps as $st): ?>
              <option value="<?= (int)$st['id'] ?>"><?= e($st['name'] . ' · '
                  . applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) ?></option>
            <?php endforeach; ?>
          </select>
          <small>Puts the marker on that step's row, and warns if the date falls before
                 the step finishes.</small></label>
      <?php endif; ?>
      <div class="actions">
        <button type="button" class="secondary" onclick="closeMsEditor()">Cancel</button>
        <?php // formnovalidate so a half-typed name cannot block the delete. ?>
        <button type="submit" class="linkish link-danger" id="msRemove"
                name="milestone_action" value="" formnovalidate>Remove</button>
        <span class="spacer"></span>
        <button type="submit" id="msSave" name="milestone_action" value="">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($editable): ?>
<div id="taskEditor" class="tl-modal" style="display:none;">
  <div class="tl-modal-box">
    <h3>Edit task</h3>
    <p id="taskLabel" class="muted"></p>
    <form id="taskEditForm">
      <input type="hidden" id="editTaskId">
      <label>Starts <input type="date" id="editStart" required></label>
      <label>Finishes <input type="date" id="editEnd" required>
        <small>Inclusive, the last day worked.</small></label>
      <label>Assigned to
        <select id="editCrew">
          <option value="">nobody</option>
          <?php foreach ($crewList as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?php
              if ((int)$c['is_placeholder'] === 1) echo ' (slot)'; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="inline"><input type="checkbox" id="editManual">
        Lock this task, Regenerate won't move it</label>
      <div class="actions">
        <button type="button" class="secondary" onclick="closeTaskEditor()">Cancel</button>
        <button type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php // ---- the step editor ------------------------------------------------------
      // Opened by DOUBLE-CLICKING a bar on the general chart (David, 2026-09-18),
      // the gesture the task bars and the milestones already use: Frappe raises
      // its click on a double-click. The step's dates, how many working days it
      // needs at its settings (the Duration column of Dates & headcount), and its
      // progress, typed here or dragged with the handle under the bar.
      //
      // Drawn for everyone. Without the right to edit, and on a saved version, it
      // opens read only (assets/timeline.js), because reading the numbers is half
      // of what it is for; only the controls that write are left out here. ?>
<?php if ($general): ?>
<div id="stepEditor" class="tl-modal" style="display:none;">
  <div class="tl-modal-box tl-step-box" role="dialog" aria-modal="true" aria-labelledby="seTitle">
    <h3 id="seTitle">Step</h3>
    <p id="seScope" class="muted small se-scope"></p>
    <form id="stepEditForm">
      <div class="se-dates">
        <label>Starts <input type="date" id="seStart" required>
          <small id="seStartDay"></small></label>
        <label>Finishes <input type="date" id="seEnd" required>
          <small id="seEndDay"></small></label>
      </div>

      <div class="se-need">
        <div id="seWindow"></div>
        <div id="seNeed"></div>
        <small id="seNeedHow"></small>
        <?php if ($editable): ?>
          <div class="se-buttons">
            <button type="button" class="secondary" id="seFit" hidden></button>
            <?php // Where the duration's ingredients are changed: days per item,
                  // people, coverage. A real button in the same font as the one
                  // beside it, posting a GET form of its own, since a form cannot
                  // sit inside another. ?>
            <button type="submit" class="secondary" form="seToPlanning">Open Dates &amp; headcount</button>
          </div>
        <?php endif; ?>
      </div>

      <label for="seProgress">Progress</label>
      <div class="se-progress">
        <input type="range" id="seProgressRange" min="0" max="100" step="1" aria-label="Progress, percent">
        <input type="number" id="seProgress" min="0" max="100" step="1"> %
      </div>
      <?php if (!$progressReady && $editable): ?>
        <small class="warn-text">Recording progress needs database update <strong>036</strong>.
          <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?></small>
      <?php endif; ?>
      <small id="seProgressSource"></small>
      <div class="se-tracked">
        <small id="seTracked"></small>
        <?php if ($editable): ?>
          <button type="button" class="secondary" id="seUseTracked" hidden></button>
        <?php endif; ?>
      </div>

      <div class="actions">
        <button type="button" class="secondary" id="seCancel">Cancel</button>
        <span class="spacer"></span>
        <?php if ($editable): ?><button type="submit" id="seSave">Save</button><?php endif; ?>
      </div>
    </form>
    <?php if ($editable): ?>
      <form id="seToPlanning" method="get" action="wizard.php" hidden>
        <input type="hidden" name="project" value="<?= (int)$projectId ?>">
        <input type="hidden" name="step" value="4">
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

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
window.TL_CAN_EDIT  = <?= $editable ? 'true' : 'false' ?>;
<?php // The studio calendar, every non-working day as date => name. The charts
      // shade them, a dragged bar keeps its working days across them, and the
      // step editor counts with them: the same set calendar.php reads. An object
      // even when empty, which (object) makes it. ?>
window.TL_DAYS_OFF  = <?= json_encode((object)non_working_days($pdo), JSON_UNESCAPED_UNICODE) ?>;
window.TL_PROGRESS_READY = <?= $progressReady ? 'true' : 'false' ?>;
<?php // The saved version being shown, or null for the live timeline. Names the
      // PNG and PDF downloads, so a picture of V03 cannot pass for today's plan. ?>
window.TL_VERSION   = <?= json_encode($version !== null ? $version['label'] : null) ?>;
window.MILESTONES   = <?= json_encode($milestones, JSON_UNESCAPED_UNICODE) ?>;
window.GENERAL_TASKS = <?= json_encode($general, JSON_UNESCAPED_UNICODE) ?>;
window.DETAILED_TASKS = <?= json_encode($tasks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/timeline.js<?= asset_ver('timeline.js') ?>"></script>

<?php endif; ?>

</div>   <?php // closes .tl-page ?>

</div>
</body></html>
