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

        // Group by how deep into the graph each step sits. Everything at the same
        // depth genuinely runs at the same time, so it shares a slot — and this
        // works across chains, which per-chain clustering could not.
        $levels = workflow_levels($steps, $depsMap);
        $byLevel = [];
        foreach ($steps as $s) $byLevel[$levels[(int)$s['id']] ?? 0][] = $s;
        ksort($byLevel);

        $touched = 0;
        $n = count($days);
        $c = max(1, count($byLevel));
        $i = 0;
        foreach ($byLevel as $group) {
            $startIdx = (int)floor($n * $i / $c);
            $endIdx   = max($startIdx, (int)floor($n * ($i + 1) / $c) - 1);
            foreach ($group as $step) {
                $upd->execute([$days[$startIdx], $days[$endIdx], $step['id'], $projectId]);
                $touched++;
            }
            $i++;
        }
        flash('ok', "Spread $touched steps across $c stage" . ($c === 1 ? '' : 's') .
                    " between $start and $end. Steps that run at the same time share dates. " .
                    'Adjust anything below.');
        return wizard_url($projectId, 6);
    }

    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps
            SET start_date = ?, deadline = ?, days_per_unit = ?, coverage_pct = ?, planned_headcount = ?
          WHERE id = ? AND project_id = ?'
    );
    $saved = 0; $warnings = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $start    = trim($_POST['start_date'][$id] ?? '') ?: null;
        $deadline = trim($_POST['deadline'][$id] ?? '') ?: null;
        $dpu      = (float)($_POST['days_per_unit'][$id] ?? 1);
        // A whole-project step has no coverage input, so it keeps whatever it has.
        $coverage = isset($_POST['coverage_pct'][$id])
            ? min(100, max(0, (float)$_POST['coverage_pct'][$id]))
            : (float)($s['coverage_pct'] ?? 100);
        $headRaw  = trim($_POST['planned_headcount'][$id] ?? '');
        // Fractional on purpose: someone splitting a week across two shows is 0.5.
        $head     = $headRaw === '' ? null : max(0, (float)$headRaw);

        if ($dpu <= 0) $dpu = 1.0;
        if ($start && $deadline && $deadline < $start) {
            $warnings[] = $s['name'] . ': the deadline is before the start date, so it was left unchanged.';
            continue;
        }
        if ($deadline && $project['delivery_date'] && $deadline > $project['delivery_date']) {
            $warnings[] = $s['name'] . ': the deadline is after the project delivery date.';
        }
        $upd->execute([$start, $deadline, $dpu, $coverage, $head, $id, $projectId]);
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

    <?php $estimates = project_estimates($pdo, $projectId); ?>
    <form method="post">
      <button type="submit" name="action" value="save" hidden></button>
      <table class="planning-table" id="planning-table" data-today="<?= e(date('Y-m-d')) ?>">
        <thead>
        <tr>
          <th>Step</th>
          <th title="How much of its scope this step actually touches. FX might only land on a third of the running time.">Covers</th>
          <th>Starts</th><th>Deadline</th>
          <th title="How long one shot or asset takes one person. Decimals are fine — 0.5 is half a day.">Days per item</th>
          <th title="How many people are on this step. Decimals are fine — 2.5 means someone is half-time.">People</th>
          <th>Is that enough?</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($steps as $s): $id = (int)$s['id'];
              $work = step_work_units($pdo, $projectId, $s, $estimates); ?>
        <?php
        // data-units is the FULL scope; coverage is applied by the live check
        // from the input beside it, so the two never double up. Keep the closing
        // tag on its own line: a closing PHP tag written inside a one-line
        // comment still ends PHP mode (lesson 9 in START_HERE).
        ?>
        <tr data-units="<?= e(fmt_num($work['units_raw'] ?? $work['units'], 3)) ?>"
            data-label="<?= e($work['label']) ?>"
            data-estimated="<?= !empty($work['estimated']) ? '1' : '0' ?>">
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span>
            <?= e($s['name']) ?>
            <span class="muted small"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?><?= $s['tracks_seconds'] ? ' · seconds' : '' ?></span>
          </td>
          <td>
            <?php if ($s['applies_to'] === 'project'): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="pct-field">
                <input type="number" name="coverage_pct[<?= $id ?>]" min="0" max="100" step="any"
                       value="<?= e(fmt_num($s['coverage_pct'] ?? 100)) ?>">%
              </span>
            <?php endif; ?>
          </td>
          <td><input type="date" name="start_date[<?= $id ?>]" value="<?= e($s['start_date'] ?? '') ?>"></td>
          <td><input type="date" name="deadline[<?= $id ?>]" value="<?= e($s['deadline'] ?? '') ?>"></td>
          <?php /* step="any" rather than a fixed increment — real estimates land on
                   awkward numbers like 1.5 days or 2.5 people, and the browser
                   should not reject them. */ ?>
          <td><input type="number" name="days_per_unit[<?= $id ?>]" step="any" min="0.01"
                     value="<?= e(fmt_num($s['days_per_unit'] ?? 1)) ?>"></td>
          <td><input type="number" name="planned_headcount[<?= $id ?>]" min="0" step="any"
                     value="<?= e(fmt_num($s['planned_headcount'])) ?>"
                     placeholder="—"></td>
          <td class="verdict"><span class="muted">—</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions">
        <button type="submit" name="action" value="save" class="secondary">Save and stay here</button>
        <span class="hint">keeps your changes, leaves you on this screen</span>
        <span class="spacer"></span>
        <button type="submit" name="action" value="continue">Save and continue →</button>
      </div>
    </form>

    <p class="muted small">
      The check updates as you type — nothing is saved until you press a button. The daily
      rate is what the <em>whole team</em> must finish to hit the date; it doesn't fall when
      you add people, only each person's share does.
    </p>

    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
