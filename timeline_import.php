<?php
// Read a GanttProject (.gan) file back into the schedule — the other half of
// timeline_gan.php.
//
// Adjust dates in GanttProject, bring them back here. Everything an import
// touches is marked is_manual = 1, so a later Regenerate leaves it alone rather
// than silently undoing an afternoon of hand-planning.
//
// Dry run by default. Nothing is written until "preview only" is unticked —
// the same convention as the Plaky importer and the migration runner.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require_login();

$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

/**
 * Split "Camp Nou - Concept" into its two halves.
 *
 * Accepts the ASCII hyphen the exporter writes and the "·" an older file may
 * carry. Splits on the LAST separator, not the first: an item legitimately
 * contains a hyphen ("Estadio - grada norte") while a step name rarely does, so
 * splitting from the right is the reading that survives real names.
 */
function gan_split_name(string $name): ?array {
    $name = trim($name);
    if (!preg_match('/^(.*)\s+[-·]\s+(.+)$/u', $name, $m)) return null;
    $item = trim($m[1]);
    $step = trim($m[2]);
    if ($item === '' || $step === '') return null;
    return [$item, $step];
}

$report = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ganfile'])) {
    try {
        if (!can_edit()) throw new RuntimeException('Your account has view-only access.');
        if ($_FILES['ganfile']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . $_FILES['ganfile']['error'] . ').');
        }
        $dryRun = !empty($_POST['dry_run']);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($_FILES['ganfile']['tmp_name']);
        if ($xml === false) {
            throw new RuntimeException('That file could not be read as XML, is it a GanttProject .gan?');
        }

        // --- resources -> crew ------------------------------------------------
        // Through the alias layer as well as the name, so a resource renamed in
        // GanttProject still lands on the right person.
        $crewByName = [];
        foreach ($pdo->query('SELECT id, name FROM crew') as $c) {
            $crewByName[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        }
        try {
            foreach ($pdo->query('SELECT alias_name, crew_id FROM crew_aliases') as $a) {
                $crewByName[mb_strtolower(trim($a['alias_name']))] = (int)$a['crew_id'];
            }
        } catch (PDOException $e) { /* alias table optional */ }

        $resourceCrew = [];
        if (isset($xml->resources->resource)) {
            foreach ($xml->resources->resource as $res) {
                $n = mb_strtolower(trim((string)$res['name']));
                if (isset($crewByName[$n])) $resourceCrew[(string)$res['id']] = $crewByName[$n];
            }
        }
        $taskCrew = [];
        if (isset($xml->allocations->allocation)) {
            foreach ($xml->allocations->allocation as $al) {
                $rid = (string)$al['resource-id'];
                if (isset($resourceCrew[$rid])) $taskCrew[(string)$al['task-id']] = $resourceCrew[$rid];
            }
        }

        // --- what this project contains ---------------------------------------
        $shots = [];   // "EP01|SH010" and "SH010" => id
        $stmt = $pdo->prepare('SELECT id, episode, shot_code FROM shots WHERE project_id = ?');
        $stmt->execute([$projectId]);
        foreach ($stmt as $s) {
            $code = mb_strtoupper(trim($s['shot_code']));
            $shots[$code] = (int)$s['id'];
            if ($s['episode']) $shots[mb_strtoupper($s['episode']) . '|' . $code] = (int)$s['id'];
        }

        $assets = [];  // lowercased name => ['id','type']
        $stmt = $pdo->prepare('SELECT id, name, asset_type FROM assets WHERE project_id = ?');
        $stmt->execute([$projectId]);
        foreach ($stmt as $a) {
            $assets[mb_strtolower(trim($a['name']))] = ['id' => (int)$a['id'], 'type' => $a['asset_type']];
        }

        // Steps by name — a LIST, because a name is only unique within its scope
        // (migration 009). "Concept" is legitimately both a character step and an
        // environment step, so the item decides which one a row belongs to.
        $stepsByName = [];
        foreach (pipeline_steps($pdo, $projectId) as $s) {
            $stepsByName[mb_strtolower(trim($s['name']))][] = $s;
        }

        // Rows with no shot and no asset — a coverage band or a whole-project
        // deliverable. Keyed by step so the importer can find the item_key it
        // must write rather than guessing between 'band' and 'p<id>'.
        $stepOnlyKey = [];
        $stmt = $pdo->prepare(
            'SELECT step_id, item_key FROM schedule
              WHERE project_id = ? AND shot_id IS NULL AND asset_id IS NULL'
        );
        $stmt->execute([$projectId]);
        foreach ($stmt as $r) $stepOnlyKey[(int)$r['step_id']] = $r['item_key'];

        // --- walk the file ------------------------------------------------------
        $parsed = [];
        $unmatched = [];
        $parents = 0;

        $resolveStep = function (string $stepName, ?array $asset, bool $isShot) use ($stepsByName) {
            $cands = $stepsByName[mb_strtolower(trim($stepName))] ?? [];
            if (!$cands) return null;
            if (count($cands) === 1) return $cands[0];
            // More than one step shares this name, so the item picks the winner.
            foreach ($cands as $c) {
                if ($isShot && in_array($c['applies_to'], ['shot', 'both'], true)) return $c;
                if ($asset && in_array($c['applies_to'], ['asset', 'both'], true)) {
                    $t = $c['applies_to_asset_type'] ?? '';
                    if ($t === '' || $t === $asset['type']) return $c;
                }
            }
            return null;   // genuinely ambiguous — reported rather than guessed
        };

        $walk = function ($node, $depth = 0) use (
            &$walk, &$parsed, &$unmatched, &$parents,
            $shots, $assets, $stepsByName, $stepOnlyKey, $taskCrew, $resolveStep, $projectId
        ) {
            foreach ($node->task as $task) {
                $name = trim((string)$task['name']);
                if (isset($task->task) && count($task->task) > 0) {
                    $parents++;
                    $walk($task, $depth + 1);   // a grouping row, not a task
                    continue;
                }

                $split = gan_split_name($name);
                if (!$split) { $unmatched[] = $name . ', name is not "item - step"'; continue; }
                [$itemName, $stepName] = $split;

                $shotId = $shots[mb_strtoupper($itemName)] ?? null;
                if ($shotId === null && strpos($itemName, ' ') !== false) {
                    // "EP01 sh_0010" as the exporter writes an episodic shot.
                    $shotId = $shots[str_replace(' ', '|', mb_strtoupper($itemName))] ?? null;
                }
                $asset = $assets[mb_strtolower($itemName)] ?? null;

                $step = $resolveStep($stepName, $asset, $shotId !== null);
                if (!$step) { $unmatched[] = $name . ', no step called "' . $stepName . '" applies here'; continue; }
                $stepId = (int)$step['id'];

                if ($shotId !== null)      { $itemKey = 's' . $shotId; $assetId = null; }
                elseif ($asset !== null)   { $itemKey = 'a' . $asset['id']; $assetId = $asset['id']; $shotId = null; }
                elseif (isset($stepOnlyKey[$stepId])) {
                    // A band or a whole-project deliverable: the item name is the
                    // step's own name, and the stored row says which key it uses.
                    $itemKey = $stepOnlyKey[$stepId]; $assetId = null; $shotId = null;
                } else {
                    $unmatched[] = $name . ', no shot or asset called "' . $itemName . '"';
                    continue;
                }

                $start = trim((string)$task['start']);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    $unmatched[] = $name . ', start date is not a date'; continue;
                }
                $dur = max(1, (int)$task['duration']);
                // GanttProject counts duration in working days INCLUSIVE of the
                // start, which is the same thing working_days_between() counts —
                // so this is the exact inverse of what the exporter wrote.
                $end = add_working_days($start, $dur - 1);

                $parsed[] = [
                    'step_id' => $stepId, 'item_key' => $itemKey,
                    'shot_id' => $shotId, 'asset_id' => $assetId,
                    'label'   => $itemName . ' · ' . $step['name'],
                    'start'   => $start, 'end' => $end,
                    'crew_id' => $taskCrew[(string)$task['id']] ?? null,
                ];
            }
        };
        if (isset($xml->tasks)) $walk($xml->tasks);

        if (!$parsed && !$unmatched) throw new RuntimeException('No tasks were found in that file.');

        // --- compare with what is stored ----------------------------------------
        $existing = [];
        $stmt = $pdo->prepare(
            'SELECT step_id, item_key, start_date, end_date, crew_id FROM schedule WHERE project_id = ?'
        );
        $stmt->execute([$projectId]);
        foreach ($stmt as $r) $existing[$r['step_id'] . '|' . $r['item_key']] = $r;

        $changes = []; $unchanged = 0; $newRows = 0;
        foreach ($parsed as $p) {
            $cur = $existing[$p['step_id'] . '|' . $p['item_key']] ?? null;
            if (!$cur) { $newRows++; $changes[] = $p + ['was' => null]; continue; }
            $sameDates = ($cur['start_date'] === $p['start'] && $cur['end_date'] === $p['end']);
            $sameCrew  = ($p['crew_id'] === null) || ((int)$cur['crew_id'] === (int)$p['crew_id']);
            if ($sameDates && $sameCrew) { $unchanged++; continue; }
            $changes[] = $p + ['was' => $cur];
        }

        // --- apply ---------------------------------------------------------------
        $applied = 0;
        if (!$dryRun && $changes) {
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare(
                    "INSERT INTO schedule
                        (project_id, step_id, shot_id, asset_id, item_key,
                         start_date, end_date, crew_id, is_manual, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'manual')
                     ON DUPLICATE KEY UPDATE
                        start_date = VALUES(start_date),
                        end_date   = VALUES(end_date),
                        -- COALESCE, not overwrite: a file with no resource on a
                        -- task means "unspecified", not "nobody". Clearing an
                        -- assignment because GanttProject didn't mention it would
                        -- lose work the schedule had already done.
                        crew_id    = COALESCE(VALUES(crew_id), crew_id),
                        is_manual  = 1,
                        source     = 'manual'"
                );
                foreach ($changes as $c) {
                    $up->execute([$projectId, $c['step_id'], $c['shot_id'], $c['asset_id'],
                                  $c['item_key'], $c['start'], $c['end'], $c['crew_id']]);
                    $applied++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        $report = ['dry_run' => $dryRun, 'parsed' => count($parsed), 'changes' => $changes,
                   'unchanged' => $unchanged, 'new' => $newRows, 'applied' => $applied,
                   'unmatched' => $unmatched, 'parents' => $parents];
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$pageTitle = 'Import .gan · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'timeline'); ?>
<p class="crumb"><a href="timeline.php?project=<?= $projectId ?>">← Back to the timeline</a></p>

<h2 class="wizard-title">Import a GanttProject file</h2>

<p class="lead">
  The round trip for the <strong>.gan</strong> export: refine the plan in GanttProject, bring
  it back here. Tasks are matched on the shot or asset name and the step name, so keep those
  intact, renaming a task in GanttProject means it won't be found. Anything imported is
  marked <strong>locked</strong>, so a later Regenerate won't undo it.
</p>

<?php if ($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="toggle-form">
  <input type="hidden" name="project" value="<?= $projectId ?>">
  <label>GanttProject file
    <input type="file" name="ganfile" accept=".gan,.xml" required></label>
  <label class="inline">
    <input type="checkbox" name="dry_run" value="1" checked>
    Preview only, show what would change without saving</label>
  <div class="actions"><button type="submit">Upload</button></div>
</form>

<p class="muted small">
  <strong>One thing GanttProject doesn't know about:</strong> the studio calendar. It skips
  weekends but not the holidays on <a href="calendar.php">your calendar</a>, so a task spanning
  one may come back a day or two different from how it looked there. The dates stored here are
  always measured against the studio calendar, which is the one the schedule is built on.
</p>

<?php if ($report): ?>
  <h3><?= $report['dry_run'] ? 'Preview' : 'Import complete' ?></h3>

  <?php if (!$report['dry_run']): ?>
    <div class="flash flash-ok">Applied <?= (int)$report['applied'] ?> change<?= $report['applied'] === 1 ? '' : 's' ?>.
      Those tasks are now locked, Regenerate will schedule around them.</div>
  <?php elseif (!$report['changes']): ?>
    <div class="flash flash-ok">Nothing to change, the file already matches the saved schedule.</div>
  <?php else: ?>
    <div class="flash flash-warn">
      Nothing has been saved. <strong><?= count($report['changes']) ?></strong>
      task<?= count($report['changes']) === 1 ? '' : 's' ?> would change, untick
      <em>Preview only</em> and upload again to apply.
    </div>
  <?php endif; ?>

  <div class="dash-strip">
    <div><span class="muted small">Tasks read</span><strong><?= (int)$report['parsed'] ?></strong></div>
    <div><span class="muted small">Would change</span><strong><?= count($report['changes']) ?></strong>
      <span class="muted small"><?= (int)$report['new'] ?> new</span></div>
    <div><span class="muted small">Already matching</span><strong><?= (int)$report['unchanged'] ?></strong></div>
    <div><span class="muted small">Not matched</span>
      <strong class="<?= $report['unmatched'] ? 'warn-text' : '' ?>"><?= count($report['unmatched']) ?></strong></div>
  </div>

  <?php if ($report['changes']): ?>
    <div class="table-scroll">
      <table class="review-table">
        <tr><th>Task</th><th>Starts</th><th>Finishes</th><th></th></tr>
        <?php foreach (array_slice($report['changes'], 0, 300) as $c): ?>
        <tr>
          <td><?= e($c['label']) ?></td>
          <td><?php if ($c['was'] && $c['was']['start_date'] !== $c['start']): ?>
                <span class="muted"><s><?= e($c['was']['start_date']) ?></s></span> →
                <strong><?= e($c['start']) ?></strong>
              <?php else: ?><?= e($c['start']) ?><?php endif; ?></td>
          <td><?php if ($c['was'] && $c['was']['end_date'] !== $c['end']): ?>
                <span class="muted"><s><?= e($c['was']['end_date']) ?></s></span> →
                <strong><?= e($c['end']) ?></strong>
              <?php else: ?><?= e($c['end']) ?><?php endif; ?></td>
          <td class="muted small"><?= $c['was'] ? '' : 'new row' ?><?= $c['crew_id'] ? ' · assignee set' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php if (count($report['changes']) > 300): ?>
      <p class="muted small">Showing the first 300 of <?= count($report['changes']) ?>. All of them
         are applied when you save, this listing is trimmed to keep the page readable.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($report['unmatched']): ?>
    <h3>Not matched</h3>
    <p class="muted small">
      Skipped, and named rather than dropped quietly, usually a task renamed in GanttProject,
      or a shot, asset or step that doesn't exist on this project.
    </p>
    <ul class="plain-list">
      <?php foreach (array_slice($report['unmatched'], 0, 60) as $u): ?>
        <li class="small"><?= e($u) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($report['unmatched']) > 60): ?>
      <p class="muted small">…and <?= count($report['unmatched']) - 60 ?> more.</p>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
