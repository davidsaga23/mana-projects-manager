<?php
// Wizard step 3 — what waits for what.
//
// Rewritten 2026-08-12. The first version asked one question per step — "runs at
// the same time as the step above" — which reads nicely but can only ever
// express dependencies within a single chain. Real pipelines cross: crowd
// animation waits on shot layout, a shot can't be assembled before its
// characters are rigged. So each step now names its prerequisites outright.
//
// Parallelism is still never set by hand: two steps waiting on the same things
// run side by side. That falls out of the graph, which is the point of storing
// one.

function wizard_save_workflow(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) {
        flash('warn', 'There are no pipeline steps yet, so there is no workflow to set.');
        return wizard_url($projectId, 2);
    }

    // Saving a template from THIS screen, added 2026-08-17. It shares its
    // handler and its form with the Pipeline screen (template_save_from_post()
    // and template_save_form(), both in includes/pipeline.php) rather than
    // carrying a second copy — two copies would eventually disagree about
    // `template_overwrite`, which is the tick that stops somebody replacing the
    // house pipeline while believing they are creating a new one.
    //
    // The dependencies on screen are saved FIRST, so the template captures the
    // graph as it is being looked at rather than as it was last written. That is
    // the same reason the Pipeline screen applies its edits before saving one.
    if (($_POST['action'] ?? '') === 'save_template') {
        $wanted = [];
        foreach ($steps as $s) {
            $id = (int)$s['id'];
            $wanted[$id] = array_map('intval', (array)($_POST['deps'][$id] ?? []));
        }
        save_dependencies($pdo, $projectId, $wanted);   // throws on a cycle; saves nothing
        flash('ok', template_save_from_post($pdo, $projectId, $project));
        return wizard_url($projectId, 3);
    }

    $wanted = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $wanted[$id] = array_map('intval', (array)($_POST['deps'][$id] ?? []));
    }

    save_dependencies($pdo, $projectId, $wanted);   // throws on a cycle; saves nothing

    $edges = 0;
    foreach ($wanted as $deps) $edges += count($deps);
    $starters = count(array_filter($wanted, function ($d) { return !$d; }));

    // A CHANGE MADE ON THE DIAGRAM says what it did, in step names. The generic
    // count below is fine after ticking a dozen boxes; after drawing ONE line it
    // tells somebody nothing about whether the line went the way they meant,
    // and direction is the thing most likely to be wrong with a drag.
    //
    // Ids, not text, arrive from the page, and are looked up here: the sentence
    // is built from the step table, never from anything posted.
    $said = '';
    // Same rule as the hint beside the pointer, so the sentence before letting
    // go and the sentence after saving are the same sentence. A name used by
    // more than one step gets its scope in the words the diagram prints.
    $count = [];
    foreach ($steps as $s) {
        $k = mb_strtolower(trim((string)$s['name']));
        $count[$k] = ($count[$k] ?? 0) + 1;
    }
    $names = [];
    foreach ($steps as $s) {
        $n = (string)$s['name'];
        $names[(int)$s['id']] = $count[mb_strtolower(trim($n))] > 1
            ? $n . ' (' . applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null) . ')'
            : $n;
    }
    foreach (['wf_add' => 'now waits for', 'wf_remove' => 'no longer waits for'] as $key => $verb) {
        if (!preg_match('/^(\d+)-(\d+)$/', (string)($_POST[$key] ?? ''), $m)) continue;
        $source = (int)$m[1]; $target = (int)$m[2];
        if (isset($names[$source], $names[$target])) {
            $said = $names[$target] . ' ' . $verb . ' ' . $names[$source] . '. ';
        }
    }

    flash('ok', $said . "Saved the workflow, $edges link" . ($edges === 1 ? '' : 's') . ', ' .
                "$starters step" . ($starters === 1 ? '' : 's') . ' able to start immediately.');

    return wizard_next_url($projectId, 3);
}

function wizard_render_workflow(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);

    if (!$steps) {
        ?>
        <p class="lead">No pipeline steps yet.</p>
        <p><a href="<?= e(wizard_url($projectId, 2)) ?>">← Add the pipeline steps first</a></p>
        <?php
        return;
    }

    $depsMap = step_dependencies_map($pdo, $projectId);
    $stepsById = [];
    foreach ($steps as $s) $stepsById[(int)$s['id']] = $s;
    $levels = workflow_levels($steps, $depsMap);
    ?>
    <p class="lead">For each step, tick what has to finish before it can start. Steps waiting
       on the same things run <strong>side by side</strong>, you never set that directly,
       it's simply what the picture shows.</p>

    <div class="wf-diagram-head">
      <h3>The shape of it</h3>
      <?php // The diagram is WIDER THAN THE SCREEN and scrolls sideways, so the
            // first thing anyone wants is all of it at once — to print, to put
            // in a deck, or to send to somebody who does not have a login.
            // SVG because it stays sharp at any size and needs no image
            // library on the server. ?>
      <a href="export_workflow.php?project=<?= $projectId ?>" class="wf-export">
        Download the diagram ↓
      </a>
    </div>
    <div class="wf-diagram-wrap">
      <?= workflow_diagram_svg($steps, $depsMap) ?>
    </div>
    <?php if (can_edit()): ?>
      <?php // SAID ON THE PICTURE, where the gesture is, rather than in the lead
            // paragraph above it: somebody looking at a diagram does not scroll up
            // to find out whether it can be touched. ?>
      <p class="muted small wf-howto">
        <strong>Drag from the dot on the right of a box</strong> onto another box to make that
        second step wait for the first. <strong>Click a line</strong> to remove it. Every change
        saves at once and redraws the picture; the ticks below change with it.
      </p>
    <?php endif; ?>
    <p class="muted small">Left to right: everything in the first column can start on day one,
       and each column waits for the one before it. Steps stacked in the same column run at the
       same time. This redraws when you save.</p>

    <h3>What waits for what</h3>
    <form method="post">
      <table class="deps-table">
        <thead>
          <tr><th>Step</th><th>Can't start until these have finished</th></tr>
        </thead>
        <tbody>
        <?php foreach ($steps as $s): $id = (int)$s['id']; $mine = $depsMap[$id] ?? []; ?>
          <tr>
            <td class="wf-name">
              <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span>
              <?= e($s['name']) ?>
              <div class="muted small">
                <?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?>
                · <?= empty($mine) ? 'starts immediately' : 'column ' . (($levels[$id] ?? 0) + 1) ?>
              </div>
            </td>
            <?php // ⚠ THE FLEX BOX IS INSIDE THE CELL, NOT THE CELL ITSELF. This <td>
                  // used to carry display:flex, and a table cell given display:flex
                  // stops being a table cell: the browser wraps it in an anonymous
                  // one and draws its borders at its own height rather than the
                  // row's. That is why every line in this column sat higher than
                  // the matching line in the Step column (David, 2026-09-14). ?>
            <td class="dep-picks-cell">
              <div class="dep-picks">
              <?php foreach ($steps as $other): $oid = (int)$other['id']; if ($oid === $id) continue; ?>
                <label class="inline dep-pick">
                  <input type="checkbox" name="deps[<?= $id ?>][]" value="<?= $oid ?>"
                         <?= in_array($oid, $mine, true) ? 'checked' : '' ?>>
                  <span class="dot" style="background:<?= e($other['color'] ?: '#ccc') ?>"></span><?= e($other['name']) ?>
                </label>
              <?php endforeach; ?>
              <?php if (count($steps) === 1): ?>
                <span class="muted">Nothing else to wait for yet.</span>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions">
        <button type="submit" name="stay" value="1" class="secondary">Save and stay here</button>
        <span class="hint">redraws the diagram above without moving on</span>
        <span class="spacer"></span>
        <button type="submit">Save and continue →</button>
      </div>

      <?php
      // INSIDE the dependency form, deliberately — see template_save_form().
      // The ticks above are checkboxes, and unchecked boxes are not posted at
      // all, so a separate form would carry no dependencies and saving would
      // wipe the graph. Here the action rides on the button and the ticks come
      // with it, which is also what makes the promise below true.
      template_save_form($pdo, $project,
          'Saves the steps AND this dependency graph, what waits for what, and therefore what '
        . 'runs in parallel. Not dates or headcount, which belong to this project alone. The '
        . 'ticks above are saved first, so the template captures the shape on screen rather than '
        . 'the last one saved. New projects will be offered it alongside the built-in templates.',
          true);
      ?>
    </form>

    <p class="muted small">Ticking nothing means a step can begin on day one. A loop, two steps
       each waiting for the other, is refused with nothing saved, so the scheduler can never be
       handed one.</p>

    <?php // The diagram editor. A view-only account gets the picture without the
          // handles: offering a gesture that cannot save would be a control that
          // silently does nothing. window.WF_EDITABLE rather than a top-level
          // const, because a const does not become a window property - the trap
          // that cost the tasks board a day. ?>
    <?php if (can_edit()): ?>
      <script>window.WF_EDITABLE = true;</script>
      <script src="assets/workflow-edit.js<?= asset_ver('workflow-edit.js') ?>"></script>
    <?php endif; ?>
    <?php
}
