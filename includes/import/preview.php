<?php
// Import screen 3 — exactly what will happen, before anything happens.
// Produced by the same function that performs the import, run with writes
// disabled, so the preview cannot promise something different from the result.

$parsed = plaky_read_csv($state['file']);
$report = plaky_run($pdo, $projectId, $parsed['rows'], $state['mapping'], true);

$willWrite = $report['shots_created'] + $report['shots_updated']
           + $report['assets_created'] + $report['assets_updated']
           + $report['progress_created'] + $report['progress_updated'];
?>
<p class="summary-line"><strong><?= e($state['name']) ?></strong> · nothing has been changed yet</p>

<h3>What this import will do</h3>
<table class="review-table">
  <tr><th>Records</th><th>Total</th><th>New</th><th>Already here, will be updated</th></tr>
  <tr>
    <td><strong>Shots</strong></td>
    <td><strong><?= (int)$report['shots_created'] + (int)$report['shots_updated'] ?></strong></td>
    <td><?= (int)$report['shots_created'] ?></td>
    <td><?= (int)$report['shots_updated'] ?></td>
  </tr>
  <tr>
    <td><strong>Assets</strong></td>
    <td><strong><?= (int)$report['assets_created'] + (int)$report['assets_updated'] ?></strong></td>
    <td><?= (int)$report['assets_created'] ?></td>
    <td><?= (int)$report['assets_updated'] ?></td>
  </tr>
  <tr>
    <td><strong>Progress records</strong>
      <div class="muted small">one per pipeline step on each shot or asset — the status,
        who's on it and any time logged</div></td>
    <td><strong><?= (int)$report['progress_created'] + (int)$report['progress_updated'] ?></strong></td>
    <td><?= (int)$report['progress_created'] ?></td>
    <td><?= (int)$report['progress_updated'] ?></td>
  </tr>
</table>
<p class="muted small">
  "Already here" is not a problem — shots and assets are matched by name, so anything you
  already created is updated in place rather than duplicated.
</p>

<?php
$skips = [
  'skipped_ignored_group' => 'in groups set to be ignored',
  'skipped_childless'     => 'items with no pipeline steps under them',
  'skipped_no_step'       => 'step records whose step is set to be skipped',
  'skipped_no_parent'     => 'step records whose parent item was not imported',
  'skipped_no_project_step' => 'whole-project items with no matching project step',
];
$anySkips = false;
foreach ($skips as $k => $_) if (!empty($report[$k])) $anySkips = true;
?>
<?php if ($anySkips): ?>
  <h3>Deliberately not imported</h3>
  <table class="review-table">
    <?php foreach ($skips as $key => $label): if (empty($report[$key])) continue; ?>
      <tr><td><strong><?= (int)$report[$key] ?></strong></td><td class="muted"><?= e($label) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">These are the consequences of the choices on the previous screen —
     go back if any of them look wrong.</p>
<?php endif; ?>

<?php if (!empty($report['unmatched_project_steps'])): ?>
  <div class="flash flash-warn">
    <strong><?= count($report['unmatched_project_steps']) ?></strong> whole-project
    <?= count($report['unmatched_project_steps']) === 1 ? 'item doesn\'t' : 'items don\'t' ?>
    match a project-level step in this pipeline, so they won't be imported:
    <?= e(implode(', ', array_keys($report['unmatched_project_steps']))) ?>.
    Add them on the <a href="wizard.php?project=<?= $projectId ?>&step=2">Pipeline</a> screen
    as "The whole project" steps, or set that group to be ignored.
  </div>
<?php endif; ?>

<?php if (!empty($report['types_normalised'])): ?>
  <div class="flash flash-ok">
    Board groups are plural; asset types here aren't. These will be filed under the spelling
    this project already uses, rather than creating a second type an "s" apart:
    <?php $bits = [];
      foreach ($report['types_normalised'] as $group => $type) $bits[] = "\"$group\" → \"$type\"";
      echo e(implode(', ', $bits));
    ?>.
  </div>
<?php endif; ?>

<?php if ($report['unmatched_people']): ?>
  <div class="flash flash-warn">
    <strong><?= count($report['unmatched_people']) ?></strong>
    <?= count($report['unmatched_people']) === 1 ? 'name' : 'names' ?> won't be linked to anyone.
    Their work still imports, and the raw name is kept so it can be matched later —
    but it won't appear in per-artist reporting until then.
    <br>
    <?php $bits = [];
      foreach (array_slice($report['unmatched_people'], 0, 10, true) as $n => $c) $bits[] = "$n ($c)";
      echo e(implode(', ', $bits)) . (count($report['unmatched_people']) > 10 ? ' …' : '');
    ?>
  </div>
<?php endif; ?>

<?php if ($report['unmapped_statuses']): ?>
  <div class="flash flash-warn">
    <strong><?= count($report['unmapped_statuses']) ?></strong> status
    <?= count($report['unmapped_statuses']) === 1 ? 'value has' : 'values have' ?> no meaning set,
    so those rows will count as neither delivered nor approved:
    <?php $bits = [];
      foreach (array_slice($report['unmapped_statuses'], 0, 10, true) as $n => $c) $bits[] = "$n ($c)";
      echo e(implode(', ', $bits)) . (count($report['unmapped_statuses']) > 10 ? ' …' : '');
    ?>
  </div>
<?php endif; ?>

<?php if ($willWrite === 0): ?>
  <div class="flash flash-err">This import would change nothing. Check the group mapping on
    the previous screen — if every group is set to "ignore", there is nothing to bring in.</div>
<?php endif; ?>

<form method="post" class="actions">
  <input type="hidden" name="action" value="apply">
  <button type="submit" <?= $willWrite === 0 ? 'disabled' : '' ?>>Import <?= (int)$willWrite ?> records</button>
  <a class="button-link" href="<?= e($selfUrl) ?>&step=map">← Back to mapping</a>
</form>

<form method="post" class="discard-form">
  <button type="submit" name="action" value="cancel" class="link-danger">Cancel this import</button>
</form>
