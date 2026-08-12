<?php
// Wizard step 7 — review, and the quota that comes out of the plan.
//
// The point of this screen: an unrealistic plan should be obvious HERE, not in
// week three. It shows, per step, the work required per day to hit the date and
// how many people that actually needs — the number David considers the single
// biggest driver of a project landing on time.

function wizard_save_review(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];

    if (($_POST['action'] ?? '') !== 'finish') {
        return wizard_url($projectId, 7);
    }

    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) {
        throw new RuntimeException('Add at least one pipeline step before finishing setup.');
    }

    $pdo->prepare('UPDATE projects SET status = ? WHERE id = ?')->execute(['active', $projectId]);
    $pdo->prepare('DELETE FROM project_settings WHERE project_id = ? AND setting_key = ?')
        ->execute([$projectId, 'wizard_step']);

    flash('ok', '"' . $project['name'] . '" is set up and now active. ' .
                'Everything here stays editable from the project screens.');
    return 'index.php';
}

function wizard_render_review(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $steps     = pipeline_steps($pdo, $projectId);
    $depsMap   = step_dependencies_map($pdo, $projectId);
    $stepsById = [];
    foreach ($steps as $s) $stepsById[(int)$s['id']] = $s;

    $shotCount  = (int)$pdo->query("SELECT COUNT(*) FROM shots  WHERE project_id = $projectId")->fetchColumn();
    $assetCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE project_id = $projectId")->fetchColumn();

    $blockers = [];
    if (!$steps) $blockers[] = 'There are no pipeline steps, so there is nothing to track.';

    $warnings = [];
    if (!$project['delivery_date']) $warnings[] = 'No delivery date is set for the project.';
    if ($shotCount === 0 && $assetCount === 0) {
        $est = project_estimates($pdo, $projectId);
        $warnings[] = ($est['seconds'] > 0 || $est['shots'] > 0 || $est['assets'] > 0)
            ? 'No shots or assets yet, so the figures below come from the early estimate. ' .
              'They recalculate from the real breakdown as soon as one exists.'
            : 'No shots or assets have been added yet.';
    }

    $quotas = $steps ? project_quotas($pdo, $projectId, $steps) : [];
    ?>
    <p class="lead">A last look before this becomes a live project. Nothing here is locked in —
       every screen stays editable afterwards.</p>

    <div class="review-grid">
      <div class="review-card">
        <h4>Project</h4>
        <dl>
          <dt>Name</dt><dd><?= e($project['name']) ?></dd>
          <dt>Code</dt><dd><code><?= e($project['code']) ?></code></dd>
          <dt>Type</dt><dd><?= e($project['type']) ?></dd>
          <dt>Runs</dt><dd><?= e($project['start_date'] ?: '—') ?> → <?= e($project['delivery_date'] ?: '—') ?></dd>
          <dt>FPS</dt><dd><?= (int)$project['fps'] ?></dd>
        </dl>
      </div>
      <div class="review-card">
        <h4>Content</h4>
        <dl>
          <dt>Shots</dt><dd><?= $shotCount ?></dd>
          <dt>Assets</dt><dd><?= $assetCount ?></dd>
          <dt>Pipeline steps</dt><dd><?= count($steps) ?></dd>
        </dl>
      </div>
    </div>

    <h3>Workflow</h3>
    <table class="review-table">
      <tr><th>Step</th><th>Applies to</th><th>Order</th><th>Dates</th></tr>
      <?php foreach ($steps as $s): ?>
      <tr>
        <td class="wf-name">
          <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span><?= e($s['name']) ?>
        </td>
        <td class="muted"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?></td>
        <td class="muted"><?= e(describe_dependencies($s, $depsMap, $stepsById)) ?></td>
        <td class="muted">
          <?= e($s['start_date'] ?: '—') ?> → <?= e($s['deadline'] ?: '—') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <h3>What this plan asks for</h3>
    <p class="muted small">The daily rate is what the whole team must finish to hit the date —
       it does not change when people are added. Only each person's share does.</p>

    <table class="review-table quota-table">
      <tr>
        <th>Step</th><th>Work</th><th>Working days</th>
        <th>Team per day</th><th>People</th><th>Each per day</th><th>Needs</th>
      </tr>
      <?php foreach ($quotas as $q): ?>
      <tr class="<?= $q['problems'] ? 'has-problem' : '' ?>">
        <td class="wf-name">
          <span class="dot" style="background:<?= e($q['step']['color'] ?: '#ccc') ?>"></span><?= e($q['step']['name']) ?>
        </td>
        <td><?= fmt_rate((float)$q['units']) ?> <span class="muted"><?= e($q['unit_label']) ?></span>
          <?php if (($q['coverage'] ?? 1) < 1): ?>
            <span class="muted small">(<?= (int)round(($q['coverage'] ?? 1) * 100) ?>% of <?= fmt_rate((float)$q['units_raw']) ?>)</span>
          <?php endif; ?>
          <?php if (!empty($q['estimated'])): ?>
            <span class="est-tag" title="From the early estimate on the Basics screen — no breakdown exists yet">est.</span>
          <?php endif; ?>
        </td>
        <td><?= $q['working_days'] === null ? '—' : (int)$q['working_days'] ?></td>
        <td><?= fmt_rate($q['team_per_day']) ?></td>
        <td><?= $q['headcount'] === null ? '—' : e(fmt_num($q['headcount'])) ?></td>
        <td><strong><?= fmt_rate($q['per_artist_day']) ?></strong></td>
        <td class="<?= $q['required_exact'] !== null && $q['headcount'] !== null
                      && $q['required_exact'] > $q['headcount'] + 0.0001 ? 'warn-text' : 'muted' ?>">
          <?= $q['required_headcount'] === null ? '—' : (int)$q['required_headcount'] . ' people' ?>
        </td>
      </tr>
      <?php if ($q['problems']): ?>
      <tr class="problem-row">
        <td></td>
        <td colspan="6" class="muted"><?= e(implode(' ', $q['problems'])) ?></td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </table>

    <?php foreach ($blockers as $b): ?>
      <div class="flash flash-err"><?= e($b) ?></div>
    <?php endforeach; ?>
    <?php foreach ($warnings as $w): ?>
      <div class="flash flash-warn"><?= e($w) ?> You can finish setup anyway and add it later.</div>
    <?php endforeach; ?>

    <form method="post" class="actions">
      <input type="hidden" name="action" value="finish">
      <button type="submit" <?= $blockers ? 'disabled' : '' ?>>Finish setup</button>
      <?php if ($blockers): ?>
        <small class="muted">Fix the item above to finish.</small>
      <?php endif; ?>
    </form>
    <?php
}
