<?php
// Wizard step 3 — which steps wait for which.
//
// One checkbox per step: "runs at the same time as the step above". That covers
// the real cases (STFC's Lighting ∥ Render, with Comp waiting for both) without
// asking anyone to think in graphs. The stored result IS a dependency graph, so
// a richer editor can replace this screen later without touching the scheduler.

function wizard_save_workflow(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) {
        flash('warn', 'There are no pipeline steps yet, so there is no workflow to set.');
        return wizard_url($projectId, 2);
    }

    $posted = $_POST['parallel'] ?? [];
    $flags = [];
    foreach ($steps as $s) $flags[(int)$s['id']] = !empty($posted[(int)$s['id']]);

    save_workflow($pdo, $projectId, $flags);   // throws on a cycle; nothing saved

    $parallelCount = count(array_filter($flags));
    flash('ok', $parallelCount === 0
        ? 'Saved: every step waits for the one before it.'
        : "Saved the workflow — $parallelCount step" . ($parallelCount === 1 ? '' : 's') .
          ' set to run alongside the step above.');

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

    $depsMap   = step_dependencies_map($pdo, $projectId);
    $stepsById = [];
    foreach ($steps as $s) $stepsById[(int)$s['id']] = $s;
    ?>
    <p class="lead">By default each step waits for the one above it. Tick a step to run it
       <strong>at the same time</strong> as the step above — the next step down then waits
       for both to finish.</p>

    <form method="post">
    <?php
    $rendered = [];
    foreach ([
        'shot'    => 'Shot pipeline',
        'asset'   => 'Asset pipeline',
        'project' => 'Whole-project steps',
    ] as $kind => $heading):
        $chain = chain_steps($steps, $kind);
        if (!$chain) continue;
        $flags = derive_parallel_flags($chain, $depsMap);
    ?>
      <h3><?= e($heading) ?></h3>
      <table class="workflow-table">
        <?php foreach ($chain as $i => $s): $id = (int)$s['id']; ?>
        <tr>
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span>
            <?= e($s['name']) ?>
            <?php if ($s['applies_to'] !== $kind || !empty($s['applies_to_asset_type'])): ?>
              <span class="muted"> · <?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?></span>
            <?php endif; ?>
          </td>
          <td class="wf-toggle">
            <?php if ($i === 0): ?>
              <span class="muted">starts this chain</span>
            <?php elseif (isset($rendered[$id])): ?>
              <span class="muted">set in the shot pipeline above</span>
            <?php else: ?>
              <label class="inline">
                <input type="checkbox" name="parallel[<?= $id ?>]" value="1" <?= !empty($flags[$id]) ? 'checked' : '' ?>>
                runs at the same time as the step above
              </label>
            <?php endif; ?>
          </td>
          <td class="wf-deps muted"><?= e(describe_dependencies($s, $depsMap, $stepsById)) ?></td>
        </tr>
        <?php $rendered[$id] = true; endforeach; ?>
      </table>
    <?php endforeach; ?>

      <div class="actions">
        <button type="submit">Save and continue →</button>
        <button type="submit" name="stay" value="1" class="secondary">Save</button>
      </div>
    </form>

    <p class="muted small">The right-hand column shows what is stored now; it updates when you save.</p>
    <?php
}
