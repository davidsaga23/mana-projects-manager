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

        } elseif (preg_match('/^remove:(\d+)$/', $action, $m)) {
            // Only ever removes the SLOT, never the person: a real crew member
            // belongs to the studio, not to one show.
            $laneId = (int)$m[1];
            $stmt = $pdo->prepare(
                'SELECT cl.*, c.is_placeholder, c.name FROM crew_lanes cl
                   JOIN crew c ON c.id = cl.crew_id
                  WHERE cl.id = ? AND cl.project_id = ?'
            );
            $stmt->execute([$laneId, $projectId]);
            $lane = $stmt->fetch();
            if (!$lane) throw new RuntimeException('That slot no longer exists.');

            $used = $pdo->prepare(
                'SELECT COUNT(*) FROM schedule WHERE step_id = ? AND lane = ? AND project_id = ?'
            );
            $used->execute([(int)$lane['step_id'], (int)$lane['lane_no'], $projectId]);
            $n = (int)$used->fetchColumn();
            if ($n > 0) {
                throw new RuntimeException(
                    "\"{$lane['name']}\" has $n scheduled task" . ($n === 1 ? '' : 's') .
                    ' on it. Re-run the schedule first so that work moves elsewhere, then remove the slot.'
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
$people   = $schemaReady ? assignable_crew($pdo) : [];
$unfilled = $schemaReady ? unfilled_slot_count($pdo, $projectId) : 0;

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

<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← <?= e($project['name']) ?></a></p>

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

  <?php if ($missing > 0 && can_edit()): ?>
    <form method="post" class="inline-form toggle-form">
      <input type="hidden" name="action" value="generate">
      <button type="submit"><?= $lanes ? 'Add the missing slots' : 'Create slots from the headcount' ?></button>
      <small>
        <?= $missing ?> slot<?= $missing === 1 ? '' : 's' ?> the headcount asks for
        <?= $missing === 1 ? 'is' : 'are' ?> missing. Slots are named after their step
        — "Animation 01", "Animation 02" — and can be renamed at any time.
        Nothing is ever deleted by this.
      </small>
    </form>
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
            <strong><?= e($l['name']) ?></strong>
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
          </td>
          <td>
            <?php if (can_edit() && $isPh): ?>
              <details class="inline-actions">
                <summary>Someone has been hired for this</summary>
                <form method="post" class="inline-form">
                  <input name="real_name[<?= (int)$l['crew_id'] ?>]" placeholder="Their real name" required>
                  <input name="real_email[<?= (int)$l['crew_id'] ?>]" type="email" placeholder="Email (optional)">
                  <button type="submit" name="action" value="promote:<?= (int)$l['crew_id'] ?>">Name them</button>
                </form>
                <form method="post" class="inline-form">
                  <select name="assign_crew[<?= (int)$l['id'] ?>]">
                    <option value="">— or pick someone already on the books —</option>
                    <?php foreach ($people as $p): ?>
                      <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="secondary" name="action" value="assign:<?= (int)$l['id'] ?>">Put them in this slot</button>
                </form>
              </details>
            <?php elseif (can_edit()): ?>
              <details class="inline-actions">
                <summary>Change who is in this slot</summary>
                <form method="post" class="inline-form">
                  <select name="assign_crew[<?= (int)$l['id'] ?>]">
                    <option value="">— pick somebody —</option>
                    <?php foreach ($people as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$l['crew_id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="secondary" name="action" value="assign:<?= (int)$l['id'] ?>">Save</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if (can_edit()): ?>
              <form method="post" class="inline-form"
                    onsubmit="return confirm('Remove this slot? The person stays on the studio crew list.');">
                <button type="submit" class="linkish" name="action" value="remove:<?= (int)$l['id'] ?>">Remove slot</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>

    <p class="muted small">
      Naming a slot keeps its identity: the crew record's id never changes, so every scheduled
      task and every progress record already pointing at it follows automatically. The old slot
      name is kept as an alias, so an import that still says "Animation 02" resolves to the
      right person.
      <?php if ($unfilled): ?>
        Re-run the <a href="schedule.php?project=<?= $projectId ?>">schedule</a> after filling
        slots so the names on the plan are up to date.
      <?php endif; ?>
    </p>

  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
