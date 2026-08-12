<?php
// Wizard step 6 — estimated dates and headcount per step.
//
// This is what the review screen turns into a quota, and what the scheduler
// will later use for its first pass. Two numbers matter most:
//
//   days per item  — how long ONE shot or asset takes one person
//   headcount      — how many people are on that step
//
// Headcount is an estimate of the team, never "how many things run at once".
// Conflating the two on STFC serialised 188 shots into nine months.

function wizard_save_planning(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'save';

    if ($action === 'spread') {
        $start = $project['start_date'] ?: date('Y-m-d');
        $end   = $project['delivery_date'];
        if (!$end) {
            throw new RuntimeException('Set a delivery date on the Basics screen first — ' .
                                       'there is nothing to spread the steps across.');
        }
        if ($end < $start) throw new RuntimeException('The delivery date is before the start date.');

        $days = working_days_list($start, $end);
        if (!$days) throw new RuntimeException('There are no working days between those dates.');

        $steps   = pipeline_steps($pdo, $projectId);
        $depsMap = step_dependencies_map($pdo, $projectId);
        $upd = $pdo->prepare('UPDATE pipeline_steps SET start_date = ?, deadline = ? WHERE id = ? AND project_id = ?');

        $touched = 0;
        foreach (['shot', 'asset'] as $kind) {
            $chain = chain_steps($steps, $kind);
            if (!$chain) continue;
            $clusters = sequential_clusters($chain, $depsMap);
            $n = count($days);
            $c = count($clusters);
            foreach ($clusters as $i => $cluster) {
                $startIdx = (int)floor($n * $i / $c);
                $endIdx   = max($startIdx, (int)floor($n * ($i + 1) / $c) - 1);
                foreach ($cluster as $step) {
                    $upd->execute([$days[$startIdx], $days[$endIdx], $step['id'], $projectId]);
                    $touched++;
                }
            }
        }
        flash('ok', "Spread $touched steps evenly between $start and $end. " .
                    'Steps running in parallel share the same dates. Adjust anything below.');
        return wizard_url($projectId, 6);
    }

    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps SET start_date = ?, deadline = ?, days_per_unit = ?, planned_headcount = ?
          WHERE id = ? AND project_id = ?'
    );
    $saved = 0; $warnings = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $start    = trim($_POST['start_date'][$id] ?? '') ?: null;
        $deadline = trim($_POST['deadline'][$id] ?? '') ?: null;
        $dpu      = (float)($_POST['days_per_unit'][$id] ?? 1);
        $headRaw  = trim($_POST['planned_headcount'][$id] ?? '');
        $head     = $headRaw === '' ? null : max(0, (int)$headRaw);

        if ($dpu <= 0) $dpu = 1.0;
        if ($start && $deadline && $deadline < $start) {
            $warnings[] = $s['name'] . ': the deadline is before the start date, so it was left unchanged.';
            continue;
        }
        if ($deadline && $project['delivery_date'] && $deadline > $project['delivery_date']) {
            $warnings[] = $s['name'] . ': the deadline is after the project delivery date.';
        }
        $upd->execute([$start, $deadline, $dpu, $head, $id, $projectId]);
        $saved++;
    }

    flash('ok', "Saved dates and headcount for $saved step" . ($saved === 1 ? '' : 's') . '.');
    foreach ($warnings as $w) flash('warn', $w);

    if ($action === 'continue') return wizard_next_url($projectId, 6);
    return wizard_url($projectId, 6);
}

function wizard_render_planning(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);

    if (!$steps) {
        ?>
        <p class="lead">No pipeline steps yet.</p>
        <p><a href="<?= e(wizard_url($projectId, 2)) ?>">← Add the pipeline steps first</a></p>
        <?php
        return;
    }
    ?>
    <p class="lead">Rough estimates are fine — this seeds the first timeline and the quota on
       the next screen. Everything stays editable once the project is running.</p>

    <p class="summary-line">
      Project runs <strong><?= e($project['start_date'] ?: 'not set') ?></strong>
      to <strong><?= e($project['delivery_date'] ?: 'not set') ?></strong>
      <?php if ($project['start_date'] && $project['delivery_date']): ?>
        · <?= working_days_between($project['start_date'], $project['delivery_date']) ?> working days
      <?php endif; ?>
    </p>

    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="spread">
      <button type="submit" class="secondary">Spread the steps evenly across those dates</button>
      <small>A starting point: each stage gets an equal slice, and parallel steps share dates.</small>
    </form>

    <form method="post">
      <button type="submit" name="action" value="save" hidden></button>
      <table class="planning-table">
        <tr>
          <th>Step</th><th>Starts</th><th>Deadline</th>
          <th title="How long one shot or asset takes one person">Days per item</th>
          <th title="How many people are on this step">People</th>
        </tr>
        <?php foreach ($steps as $s): $id = (int)$s['id']; ?>
        <tr>
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span>
            <?= e($s['name']) ?>
            <span class="muted small"><?= $s['tracks_seconds'] ? ' · seconds' : '' ?></span>
          </td>
          <td><input type="date" name="start_date[<?= $id ?>]" value="<?= e($s['start_date'] ?? '') ?>"></td>
          <td><input type="date" name="deadline[<?= $id ?>]" value="<?= e($s['deadline'] ?? '') ?>"></td>
          <td><input type="number" name="days_per_unit[<?= $id ?>]" step="0.25" min="0.25"
                     value="<?= e(rtrim(rtrim(number_format((float)($s['days_per_unit'] ?? 1), 2), '0'), '.')) ?>"></td>
          <td><input type="number" name="planned_headcount[<?= $id ?>]" min="0"
                     value="<?= $s['planned_headcount'] === null ? '' : (int)$s['planned_headcount'] ?>"
                     placeholder="—"></td>
        </tr>
        <?php endforeach; ?>
      </table>

      <div class="actions">
        <button type="submit" name="action" value="continue">Save and continue →</button>
        <button type="submit" name="action" value="save" class="secondary">Save</button>
      </div>
    </form>
    <?php
}
