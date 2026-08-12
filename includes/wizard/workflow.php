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

    $wanted = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $wanted[$id] = array_map('intval', (array)($_POST['deps'][$id] ?? []));
    }

    save_dependencies($pdo, $projectId, $wanted);   // throws on a cycle; saves nothing

    $edges = 0;
    foreach ($wanted as $deps) $edges += count($deps);
    $starters = count(array_filter($wanted, function ($d) { return !$d; }));

    flash('ok', "Saved the workflow — $edges link" . ($edges === 1 ? '' : 's') . ', ' .
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
       on the same things run <strong>side by side</strong> — you never set that directly,
       it's simply what the picture shows.</p>

    <h3>The shape of it</h3>
    <div class="wf-diagram-wrap">
      <?= workflow_diagram_svg($steps, $depsMap) ?>
    </div>
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
            <td class="dep-picks">
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
    </form>

    <p class="muted small">Ticking nothing means a step can begin on day one. A loop — two steps
       each waiting for the other — is refused with nothing saved, so the scheduler can never be
       handed one.</p>
    <?php
}
