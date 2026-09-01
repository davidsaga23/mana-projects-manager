<?php
// A project's crew — the slots its headcount implies, and who is in them.
//
// The idea this screen exists for: a crew is never complete until the show
// nearly is, because people arrive as their department starts. So the plan is
// built against SLOTS, and a slot becomes a person the day one is hired —
// without anything that already referenced it having to move.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/hiring.php';
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

$selfUrl = 'crew.php?project=' . $projectId;
$schemaReady = column_exists($pdo, 'crew', 'is_placeholder');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!$schemaReady) {
            throw new RuntimeException(
                'The database updates this screen needs have not been applied yet, so nothing ' .
                'was saved. An administrator can apply them from the Migrations screen.'
            );
        }

        if ($action === 'generate') {
            $r = sync_project_lanes($pdo, $projectId);
            if ($r['created'] === 0 && $r['existing'] === 0) {
                flash('warn', 'No slots were created — no pipeline step has a headcount yet. ' .
                              'Set one on the Dates & headcount screen and come back.');
            } else {
                flash('ok', sprintf(
                    'Created %d new slot%s; %d already existed.',
                    $r['created'], $r['created'] === 1 ? '' : 's', $r['existing']
                ));
            }
            if (!empty($r['renamed'])) {
                flash('ok', sprintf(
                    'Renamed %d slot%s so two steps sharing a name can be told apart — ' .
                    'they now carry what they apply to, e.g. "Concept Character 01". ' .
                    'Only unfilled slots were touched.',
                    $r['renamed'], $r['renamed'] === 1 ? '' : 's'
                ));
            }
            if ($r['skipped']) {
                flash('warn', 'No headcount set, so no slots were made for: ' .
                              implode(', ', $r['skipped']) . '.');
            }
            // ONE message, not one per slot. Lowering a headcount from 7 to 2
            // produces five surplus slots at a stroke, and five near-identical
            // warnings pushed everything else off the screen — including the two
            // lines saying what had actually been done. The slots themselves are
            // marked in the table below, which is where they can be acted on.
            if ($r['surplus']) {
                $n = count($r['surplus']);
                flash('warn', sprintf(
                    '%d slot%s beyond what the current headcount asks for. %s kept, not deleted — ' .
                    '%s may already have work planned against %s. They are marked below.',
                    $n, $n === 1 ? ' is' : 's are', $n === 1 ? 'It was' : 'They were',
                    $n === 1 ? 'it' : 'they', $n === 1 ? 'it' : 'them'
                ));
            }

        } elseif ($action === 'clean_surplus') {
            $r = remove_surplus_lanes($pdo, $projectId);
            if ($r['removed'] === 0 && !$r['blocked']) {
                flash('warn', 'Nothing to clean up — every slot matches its step\'s headcount.');
            } else {
                flash('ok', sprintf(
                    'Removed %d surplus slot%s.%s',
                    $r['removed'], $r['removed'] === 1 ? '' : 's',
                    $r['kept_people'] > 0
                        ? ' ' . $r['kept_people'] . ' of them held a real person — they were taken off '
                          . 'this step but remain on the studio crew list.'
                        : ''
                ));
            }
            if ($r['blocked']) {
                $bits = [];
                foreach ($r['blocked'] as $b) $bits[] = $b['name'] . ' (' . $b['tasks'] . ')';
                flash('warn', sprintf(
                    '%d slot%s left alone because %s still %s scheduled work on %s: %s. ' .
                    'Re-run the schedule so that work moves onto the remaining slots, then clean up again.',
                    count($r['blocked']), count($r['blocked']) === 1 ? ' was' : 's were',
                    count($r['blocked']) === 1 ? 'it' : 'they',
                    count($r['blocked']) === 1 ? 'has' : 'have',
                    count($r['blocked']) === 1 ? 'it' : 'them',
                    implode(', ', $bits)
                ));
            }

        } elseif (preg_match('/^promote:(\d+)$/', $action, $m)) {
            promote_placeholder(
                $pdo, (int)$m[1],
                $_POST['real_name'][$m[1]] ?? '',
                $_POST['real_email'][$m[1]] ?? null
            );
            flash('ok', 'That slot is now a person. Everything already planned for it — ' .
                        'scheduled tasks, logged progress — belongs to them, with nothing moved.');

        } elseif (preg_match('/^assign:(\d+)$/', $action, $m)) {
            $crewId = (int)($_POST['assign_crew'][$m[1]] ?? 0);
            if ($crewId <= 0) throw new RuntimeException('Pick somebody to put in that slot.');
            assign_crew_to_lane($pdo, $projectId, (int)$m[1], $crewId);
            flash('ok', 'Slot filled. Any work already scheduled on that track moved with it.');

        } elseif (preg_match('/^unassign:(\d+)$/', $action, $m)) {
            // Empties the slot without removing it — see unassign_lane() for why
            // that is a placeholder going back in rather than a NULL.
            flash('ok', unassign_lane($pdo, $projectId, (int)$m[1]));

        } elseif (preg_match('/^remove_work:(\d+)$/', $action, $m)) {
            // THE WAY THROUGH. remove: refuses while a slot carries scheduled
            // work, and for two of the three reasons it can be blocked there is
            // no re-run that would ever clear it — see the refusal below. Left
            // at that, the slot could not be removed at all.
            //
            // So this removes the slot AND the work on it, in one transaction,
            // and the button that reaches it says how many tasks that is and
            // how many are hand-locked. Destructive and named as such: auto
            // tasks come back on the next Regenerate, hand-planned dates do not.
            $laneId = (int)$m[1];
            $stmt = $pdo->prepare(
                'SELECT cl.*, c.is_placeholder, c.name FROM crew_lanes cl
                   JOIN crew c ON c.id = cl.crew_id
                  WHERE cl.id = ? AND cl.project_id = ?'
            );
            $stmt->execute([$laneId, $projectId]);
            $lane = $stmt->fetch();
            if (!$lane) throw new RuntimeException('That slot no longer exists.');

            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare(
                    'DELETE FROM schedule WHERE project_id = ? AND step_id = ? AND lane = ?'
                );
                $del->execute([$projectId, (int)$lane['step_id'], (int)$lane['lane_no']]);
                $gone = $del->rowCount();

                $pdo->prepare('DELETE FROM crew_lanes WHERE id = ?')->execute([$laneId]);
                if ((int)$lane['is_placeholder'] === 1) {
                    $pdo->prepare(
                        'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
                          AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)'
                    )->execute([(int)$lane['crew_id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash('ok', sprintf('Removed the slot "%s" and %d scheduled task%s from it. '
                              . 'Regenerate to replan that work across the slots that remain.',
                                $lane['name'], $gone, $gone === 1 ? '' : 's'));

        } elseif (preg_match('/^remove:(\d+)$/', $action, $m)) {
            // Only ever removes the SLOT, never the person: a real crew member
            // belongs to the studio, not to one show.
            $laneId = (int)$m[1];
            $stmt = $pdo->prepare(
                'SELECT cl.*, c.is_placeholder, c.name, ps.planned_headcount
                   FROM crew_lanes cl
                   JOIN crew c ON c.id = cl.crew_id
                   JOIN pipeline_steps ps ON ps.id = cl.step_id
                  WHERE cl.id = ? AND cl.project_id = ?'
            );
            $stmt->execute([$laneId, $projectId]);
            $lane = $stmt->fetch();
            if (!$lane) throw new RuntimeException('That slot no longer exists.');

            // WHY THIS REFUSAL HAD TO LEARN THE CASES (David, 2026-08-31).
            //
            // It used to say "re-run the schedule first so that work moves
            // elsewhere" for every blocked slot. That advice is only true for
            // one of the three, and sends you round a loop in the other two:
            //
            //   * a HAND-LOCKED task survives every re-run by design —
            //     commit_schedule only deletes is_manual = 0 — so re-running
            //     can never clear it, however many times you try;
            //   * a lane WITHIN the headcount is rebuilt by the next run, which
            //     allocates max(1, ceil(headcount)) lanes. Lane 01 therefore
            //     always comes back, so that advice could never work for it;
            //   * only a SURPLUS lane carrying auto tasks is actually freed by
            //     re-running, which is the case the old message described.
            $used = $pdo->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(is_manual), 0) AS locked
                   FROM schedule WHERE step_id = ? AND lane = ? AND project_id = ?'
            );
            $used->execute([(int)$lane['step_id'], (int)$lane['lane_no'], $projectId]);
            $u = $used->fetch();
            $n = (int)($u['n'] ?? 0);
            $locked = (int)($u['locked'] ?? 0);

            if ($n > 0) {
                $isSurplus = (int)$lane['lane_no'] > step_slot_count($lane);
                $why = $locked > 0
                    ? ($locked === $n ? 'Re-running the schedule will not move it: it is hand-locked, '
                                      . 'and a re-run leaves locked tasks exactly where they are.'
                                      : $locked . ' of them are hand-locked, and a re-run leaves those '
                                      . 'exactly where they are.')
                    : (!$isSurplus
                        ? 'Re-running the schedule will not help either: this slot is still within the '
                        . 'headcount planned for the step, so the next run fills it again. Lower the '
                        . 'headcount on Dates & headcount first if the slot is not wanted.'
                        : 'Re-running the schedule would move this work elsewhere, since the slot is '
                        . 'beyond the planned headcount.');
                throw new RuntimeException(
                    "\"{$lane['name']}\" has $n scheduled task" . ($n === 1 ? '' : 's') .
                    ' on it. ' . $why .
                    ' To remove the slot and that work together, use "Remove slot and its work".'
                );
            }
            $pdo->prepare('DELETE FROM crew_lanes WHERE id = ?')->execute([$laneId]);
            // An empty placeholder that belongs to nothing else is just litter.
            if ((int)$lane['is_placeholder'] === 1) {
                $pdo->prepare(
                    'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
                      AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)'
                )->execute([(int)$lane['crew_id']]);
            }
            flash('ok', 'Removed the slot "' . $lane['name'] . '".');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$lanes    = $schemaReady ? project_lanes($pdo, $projectId) : [];

// How much scheduled work sits on each slot, and how much of it is hand-locked.
// ONE grouped query rather than one per row: thirty-seven slots would otherwise
// be thirty-seven round trips to draw a button label.
//
// Needed on the way IN, not just when a removal is refused: whether "Remove
// slot" will work at all is exactly what you want to know before pressing it.
$laneWork = [];
if ($schemaReady) {
    $lw = $pdo->prepare(
        'SELECT step_id, lane, COUNT(*) AS n, COALESCE(SUM(is_manual), 0) AS locked
           FROM schedule WHERE project_id = ? GROUP BY step_id, lane'
    );
    $lw->execute([$projectId]);
    foreach ($lw as $r) {
        $laneWork[(int)$r['step_id'] . '|' . (int)$r['lane']] =
            ['n' => (int)$r['n'], 'locked' => (int)$r['locked']];
    }
}
$people   = $schemaReady ? assignable_crew($pdo) : [];
$unfilled = $schemaReady ? unfilled_slot_count($pdo, $projectId) : 0;

// Who is lined up for the empty slots. An unfilled slot with three candidates in
// play and one with nobody at all look identical on this screen otherwise, and
// they are completely different problems.
$candidatesByLane = hiring_available($pdo) ? hires_by_lane($pdo, $projectId) : [];

// Steps with a headcount but no slots yet — the reason to press Generate.
$stmt = $pdo->prepare(
    'SELECT ps.id, ps.name, ps.planned_headcount
       FROM pipeline_steps ps WHERE ps.project_id = ? ORDER BY ps.step_order, ps.id'
);
$stmt->execute([$projectId]);
$steps = $stmt->fetchAll();

$haveByStep = [];
foreach ($lanes as $l) $haveByStep[(int)$l['step_id']] = ($haveByStep[(int)$l['step_id']] ?? 0) + 1;
$missing = 0;
foreach ($steps as $s) {
    $want = step_slot_count($s);
    $missing += max(0, $want - ($haveByStep[(int)$s['id']] ?? 0));
}

// Slots above what their step's headcount now asks for. Counted on every page
// view, not just after a sync: a flash message is gone on the next reload, and
// this is a state the project is IN rather than something that just happened.
$surplusCount = 0;
foreach ($lanes as $l) {
    if ((int)$l['lane_no'] > step_slot_count($l)) $surplusCount++;
}

// Grouped by step ID, not step name. Two steps can legitimately share a name —
// "Concept" for characters and "Concept" for environments — and keying by name
// merged them into one block showing "Concept 01" twice.
$byStep = [];
foreach ($lanes as $l) $byStep[(int)$l['step_id']][] = $l;

$pageTitle = 'Crew · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'crew'); ?>

<h2 class="wizard-title">Crew <span class="muted">· <?= e($project['name']) ?></span></h2>

<p class="lead">
  A crew is never complete until a show nearly is — people arrive as their department starts.
  So the plan is built against <strong>slots</strong>, one per person the headcount asks for,
  and a slot becomes a real person the day someone is hired into it. Everything already
  planned for that slot becomes their work, with nothing moved.
</p>

<?php if (!$schemaReady): ?>
  <div class="flash flash-err">
    This screen needs database updates that haven't been applied.
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <div class="summary-line">
    <?php if ($lanes): ?>
      <strong><?= count($lanes) ?></strong> slot<?= count($lanes) === 1 ? '' : 's' ?>
      · <strong><?= count($lanes) - $unfilled ?></strong> filled
      · <strong><?= $unfilled ?></strong> still a placeholder
      <?php if ($surplusCount): ?>
        · <span class="warn-text"><strong><?= $surplusCount ?></strong> beyond the headcount</span>
      <?php endif; ?>
    <?php else: ?>
      No slots yet.
    <?php endif; ?>
  </div>

  <?php if ($surplusCount): ?>
    <div class="flash flash-warn">
      <strong><?= $surplusCount ?></strong> slot<?= $surplusCount === 1 ? '' : 's' ?>
      <?= $surplusCount === 1 ? 'is' : 'are' ?> above what the headcount now asks for —
      lowering a step's headcount never deletes people, because work may already be planned
      against them. <?= $surplusCount === 1 ? 'It is' : 'They are' ?> highlighted below.
      Re-run the <a href="schedule.php?project=<?= $projectId ?>">schedule</a> first so that
      work moves onto the remaining slots, then remove the empty ones.
    </div>
  <?php endif; ?>

  <?php if (can_edit()): ?>
    <?php
    // Both buttons are always drawn, each disabled when it has nothing to do, so
    // the pair reads as "the slots and the headcount can be brought into line"
    // rather than as controls that appear and vanish. The title says WHY a
    // disabled button is disabled — a greyed-out control with no explanation is
    // indistinguishable from a broken one.
    ?>
    <div class="inline-form toggle-form slot-actions">
      <form method="post">
        <input type="hidden" name="action" value="generate">
        <button type="submit" <?= $missing === 0 ? 'disabled' : '' ?>
                title="<?= $missing === 0
                    ? 'Every step already has the slots its headcount asks for.'
                    : e($missing . ' slot(s) the headcount asks for are missing.') ?>">
          <?= $lanes ? 'Add missing slots' : 'Create slots from the headcount' ?><?php
            if ($missing > 0): ?> (<?= $missing ?>)<?php endif; ?>
        </button>
      </form>

      <form method="post"
            onsubmit="return confirm('Remove <?= $surplusCount ?> slot(s) above the current headcount? Any holding a real person keep the person on the studio crew list; any with scheduled work are left alone.');">
        <input type="hidden" name="action" value="clean_surplus">
        <button type="submit" class="secondary" <?= $surplusCount === 0 ? 'disabled' : '' ?>
                title="<?= $surplusCount === 0
                    ? 'No slot is above what its step\'s headcount asks for.'
                    : e($surplusCount . ' slot(s) are above the current headcount.') ?>">
          Remove surplus slots<?php if ($surplusCount > 0): ?> (<?= $surplusCount ?>)<?php endif; ?>
        </button>
      </form>

      <small>
        Adding never deletes anything. Removing only takes out slots above the headcount, and
        skips any that still have scheduled work on them.
      </small>
    </div>
  <?php endif; ?>

  <?php if (!$lanes): ?>
    <div class="empty-state">
      <h2>No crew slots yet</h2>
      <?php if ($missing > 0): ?>
        <p>Create them from the headcount you already entered, and the scheduler will start
           assigning work to them by name.</p>
      <?php else: ?>
        <p>Set a headcount per step on the <a href="wizard.php?project=<?= $projectId ?>&step=6">Dates
           &amp; headcount</a> screen first — that is what says how many people each step needs.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <?php foreach ($byStep as $rows): $first = $rows[0]; ?>
      <h3 class="crew-step-head">
        <span class="dot" style="background:<?= e($first['color'] ?: '#ccc') ?>"></span>
        <?= e($first['step_name']) ?>
        <span class="muted">· <?= e(applies_to_phrase($first['applies_to'],
                                     $first['applies_to_asset_type'] ?? null)) ?></span>
      </h3>
      <?php
      // Only two facts per row: which position, and who is in it. The step name
      // and what it applies to are in the heading above — repeating them on
      // every row made three columns that all said the same thing, and made
      // "Slot" and "Who" look like duplicates of each other.
      ?>
      <table class="review-table">
        <tr><th class="col-num">#</th><th>Who</th><th></th></tr>
        <?php foreach ($rows as $l):
          $isPh = (int)$l['is_placeholder'] === 1;
          // Beyond what this step's headcount now asks for. Shown on the row
          // rather than only in a flash message, so it is still visible on the
          // next page view and sits next to the button that removes it.
          $isSurplus = (int)$l['lane_no'] > step_slot_count($l);
        ?>
        <tr class="<?= $isSurplus ? 'has-problem' : '' ?>">
          <td class="col-num muted"><?= str_pad((string)(int)$l['lane_no'], 2, '0', STR_PAD_LEFT) ?></td>
          <td>
            <?php // Straight to that person's page — their workload, their pace
                  // and their days. The name was the one thing on this row that
                  // read like a link and was not one (David, 2026-08-31).
                  //
                  // Placeholders link too. person.php knows what a slot is and
                  // says so, and team.php has always listed them the same way —
                  // "what is Rough 02 carrying?" is a fair question before
                  // anyone is hired into it. ?>
            <strong><a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$l['crew_id'] ?>"><?= e($l['name']) ?></a></strong>
            <?php if ($isPh): ?>
              <span class="est-tag">placeholder</span>
            <?php elseif (!$l['active']): ?>
              <span class="muted small">(inactive)</span>
            <?php endif; ?>
            <?php if ($isSurplus): ?>
              <div class="warn-text small">
                Beyond the <?= e(fmt_num($l['planned_headcount'])) ?> now planned for this step.
              </div>
            <?php endif; ?>
            <?php if ($isPh && $candidatesByLane):
              $live = array_filter($candidatesByLane[(int)$l['id']] ?? [], function ($h) {
                  return in_array($h['stage'], HIRE_STAGES_OPEN, true);
              });
              // Names, not just a count. "2 candidates" sends somebody to another
              // screen to find out who; the names answer it here.
              $names = array_map(function ($h) {
                  return $h['candidate_name'] . ' (' . strtolower(hire_stage_label($h['stage'])) . ')';
              }, $live);
            ?>
              <div class="muted small">
                <?php if ($names): ?>
                  Lined up: <?= e(implode(', ', $names)) ?> ·
                  <a href="hiring.php?project=<?= $projectId ?>">Hiring →</a>
                <?php else: ?>
                  <span class="warn-text">Nobody lined up.</span>
                  <a href="hiring.php?project=<?= $projectId ?>">Start looking →</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <?php // THREE BUTTONS ON ONE LINE, right-aligned (David, 2026-08-31).
                // They stacked before, because <details> is a block and the task
                // count sat under it — three controls in a column reading as a
                // list of unrelated things rather than the row's actions.
                //
                // The count moves under the row: still said BEFORE the click,
                // which is the whole reason it is on screen, but out of the line
                // the buttons make. ?>
          <td class="slot-actions">
            <?php $work = $laneWork[(int)$l['step_id'] . '|' . (int)$l['lane_no']]
                        ?? ['n' => 0, 'locked' => 0]; ?>
            <div class="slot-row">
            <?php // A BUTTON, matching the filled rows. An empty slot used to
                  // offer "Someone has been hired for this" as a line of text,
                  // so the two states of the same row looked like two different
                  // screens. "Register hire" is what it does (David,
                  // 2026-08-31), and it is the only action an empty slot has
                  // besides removing it. ?>
            <?php if (can_edit() && $isPh): ?>
              <details class="slot-change">
                <summary class="slot-btn">Register hire</summary>
                <div class="slot-change-bar slot-hire">
                  <?php // TWO WAYS IN, because there are two situations. Somebody
                        // new to the studio is typed; somebody already on the
                        // books is picked, and picking carries their existing
                        // record rather than making a second one under the same
                        // name. ?>
                  <form method="post" class="hire-row">
                    <input name="real_name[<?= (int)$l['crew_id'] ?>]" placeholder="Their name" required>
                    <input name="real_email[<?= (int)$l['crew_id'] ?>]" type="email" placeholder="Email (optional)">
                    <button type="submit" name="action" value="promote:<?= (int)$l['crew_id'] ?>">Register</button>
                  </form>
                  <form method="post" class="hire-row">
                    <select name="assign_crew[<?= (int)$l['id'] ?>]">
                      <option value="">— or somebody already on the books —</option>
                      <?php foreach ($people as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?></option>
                      <?php endforeach; ?>
                    </select>
                      <button type="submit" class="secondary" name="action" value="assign:<?= (int)$l['id'] ?>">Put them in</button>
                  </form>
                  <button type="button" class="popover-close" aria-label="Close">&times;</button>
                </div>
              </details>
            <?php elseif (can_edit()): ?>
              <?php // BUTTONS, not a line of small print (David, 2026-08-31).
                    // These are the two things done to a filled slot, and they
                    // were previously a disclosure summary and a link — both
                    // reading as text on a row where everything else is text.
                    //
                    // The picker stays folded until asked for. A select naming
                    // every person on the books, drawn open on thirty-five rows,
                    // is a wall; behind a button it is one click when wanted and
                    // invisible otherwise. <details> does that with no
                    // JavaScript, which is why the summary IS the button rather
                    // than a script toggling a panel. ?>
              <details class="slot-change">
                <summary class="slot-btn">Change assignee</summary>
                <form method="post" class="inline-form slot-change-bar">
                  <select name="assign_crew[<?= (int)$l['id'] ?>]">
                    <option value="">— pick somebody —</option>
                    <?php foreach ($people as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$l['crew_id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="secondary" name="action" value="assign:<?= (int)$l['id'] ?>">Save</button>
                  <?php // type=button, so it dismisses rather than submitting the
                        // form it happens to sit inside. ?>
                  <button type="button" class="popover-close" aria-label="Close">&times;</button>
                </form>
              </details>

              <?php // TAKING THE PERSON OUT IS NOT REMOVING THE SLOT, and the two
                    // sit side by side, so the difference has to be in the words
                    // rather than left to be discovered. Removing a slot says the
                    // plan no longer needs this job done; taking the assignee out
                    // says it does, and somebody else will do it.
                    //
                    // The slot stays, its scheduled work stays on it, and the
                    // person stays on the studio crew list. Only shown where
                    // there is somebody to take out. ?>
              <form method="post"
                    onsubmit="return confirm('Take <?= e(addslashes($l['name'])) ?> out of this slot? The slot stays, its scheduled work stays on it, and they stay on the studio crew list.');">
                <button type="submit" class="secondary danger" name="action"
                        value="unassign:<?= (int)$l['id'] ?>">Remove assignee</button>
              </form>
            <?php endif; ?>
            <?php // ONE LABEL, "Remove slot". It always takes the work with it —
                  // that is what removing a slot means — so spelling it out in
                  // the button only made the row longer. The CONFIRMATION still
                  // says exactly what will go, including how much of it cannot
                  // come back from a Regenerate, which is where that detail is
                  // actually read. ?>
            <?php if (can_edit()): ?>
              <form method="post" class="slot-remove"
                    onsubmit="return confirm('<?= $work['n'] === 0
                        ? 'Remove this slot? The person stays on the studio crew list.'
                        : 'Remove this slot AND delete the ' . (int)$work['n'] . ' task'
                          . ($work['n'] === 1 ? '' : 's') . ' scheduled on it?'
                          . ($work['locked'] > 0
                              ? ' ' . (int)$work['locked'] . ' of them are hand-planned and will not come back from a Regenerate.'
                              : ' They are auto-scheduled, so a Regenerate will replan them across the slots that remain.') ?>');">
                <button type="submit" class="secondary danger" name="action"
                        value="<?= $work['n'] === 0 ? 'remove:' : 'remove_work:' ?><?= (int)$l['id'] ?>">Remove slot</button>
              </form>
            <?php endif; ?>
            </div>

            <?php // Under the buttons rather than among them, so the line of
                  // three stays a line of three. Still before the click. ?>
            <?php if ($work['n'] > 0): ?>
              <div class="muted small slot-note">
                <?= (int)$work['n'] ?> scheduled task<?= $work['n'] === 1 ? '' : 's' ?><?php
                  if ($work['locked'] > 0): ?>, <?= (int)$work['locked'] ?> hand-locked<?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>


  <?php endif; ?>
<?php endif; ?>

<?php // Closing the popovers: an ×, a click outside, or Escape. The opening is
      // plain <details> and needs nothing, so a browser without this file still
      // works — it just closes by pressing the button again. ?>
<script src="assets/crew.js<?= asset_ver('crew.js') ?>"></script>

</div>
</body></html>
