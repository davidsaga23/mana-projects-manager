<?php
// Import screen 4 — what actually happened, including what didn't.
// $report may be null if this page is revisited after the report was consumed.

if (!$report):
?>
  <p class="lead">That import has already been reported.</p>
  <p><a class="button" href="<?= e($selfUrl) ?>">Start another import</a></p>
<?php else: ?>

<div class="flash flash-ok">
  Import finished. Everything below was written in a single transaction, if any part had
  failed, none of it would have been applied.
</div>

<h3>What changed</h3>
<table class="review-table">
  <tr><th>Records</th><th>Total</th><th>New</th><th>Updated</th></tr>
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
      <div class="muted small">one per pipeline step on each shot or asset</div></td>
    <td><strong><?= (int)$report['progress_created'] + (int)$report['progress_updated'] ?></strong></td>
    <td><?= (int)$report['progress_created'] ?></td>
    <td><?= (int)$report['progress_updated'] ?></td>
  </tr>
</table>

<?php
$skips = [
  'skipped_ignored_group' => 'in groups set to be ignored',
  'skipped_childless'     => 'items with no pipeline steps under them',
  'skipped_no_step'       => 'step records whose step was set to be skipped',
  'skipped_no_parent'     => 'step records whose parent item was not imported',
  'skipped_no_project_step' => 'whole-project items with no matching project step',
];
$anySkips = false;
foreach ($skips as $k => $_) if (!empty($report[$k])) $anySkips = true;
if ($anySkips):
?>
  <h3>Not imported</h3>
  <table class="review-table">
    <?php foreach ($skips as $key => $label): if (empty($report[$key])) continue; ?>
      <tr><td><strong><?= (int)$report[$key] ?></strong></td><td class="muted"><?= e($label) ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php
// ⚠ THE SKIPS ARE LOOSE ENDS TOO. Until 2026-09-22 this test asked only about
// people and statuses, so an import that dropped a whole-project item finished
// with "Everything in the file matched something here, no loose ends" printed
// directly under a table saying it had skipped one. That is the exact failure
// this importer exists to prevent, on the screen whose job is reporting it.
$loose = $report['unmatched_people'] || $report['unmapped_statuses']
      || !empty($report['unmatched_project_steps']) || $anySkips;
?>
<?php if ($loose): ?>
  <h3>Still needs attention</h3>
  <?php // The section can be reached by the skip counters alone — a heading with
        // nothing under it would read as a glitch, so say what it is pointing at. ?>
  <?php if (!$report['unmatched_people'] && !$report['unmapped_statuses']
            && empty($report['unmatched_project_steps'])): ?>
    <p>Everything the file named was recognised, but some rows were not imported.
       The table above says how many and why. If a count there is a surprise, the
       mapping screen is where those choices are made.</p>
  <?php endif; ?>
  <?php // NAMED, not merely counted. preview.php has always listed these; the
        // result screen gave a number and left you to work out which two rows of
        // five hundred it meant. A whole-project item matches by NAME and has no
        // dropdown on the mapping screen, so the name is the only thing that
        // makes it fixable at all. ?>
  <?php if (!empty($report['unmatched_project_steps'])): ?>
    <p><strong>Whole-project items with no step of that name here.</strong>
       Their status was not imported, so the step still reads as untouched.
       Either their group was mapped to something other than <em>whole-project
       deliverables</em>, or this project's pipeline has no project-wide step
       spelled that way — check the names on
       <a href="wizard.php?project=<?= $projectId ?>&amp;step=3">Workflow</a>.</p>
    <table class="review-table">
      <tr><th>Title in the file</th><th>Records</th></tr>
      <?php foreach ($report['unmatched_project_steps'] as $name => $count): ?>
        <tr><td><?= e($name) ?></td><td class="muted"><?= (int)$count ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($report['unmatched_people']): ?>
    <p><strong>Names not linked to anyone.</strong> The work is imported and the raw name kept,
       so matching them later fills in the history, it doesn't need re-importing.</p>
    <table class="review-table">
      <tr><th>Name in the file</th><th>Records</th></tr>
      <?php foreach ($report['unmatched_people'] as $name => $count): ?>
        <tr><td><?= e($name) ?></td><td class="muted"><?= (int)$count ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($report['unmapped_statuses']): ?>
    <p><strong>Status values with no meaning set.</strong> These count as neither delivered nor
       approved until you say what they mean.</p>
    <table class="review-table">
      <tr><th>Status in the file</th><th>Records</th></tr>
      <?php foreach ($report['unmapped_statuses'] as $name => $count): ?>
        <tr><td><?= e($name) ?></td><td class="muted"><?= (int)$count ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($report['unmatched_people'] || $report['unmapped_statuses']): ?>
    <p><a class="button" href="aliases.php?project=<?= $projectId ?>">Sort these out →</a></p>
  <?php endif; ?>
<?php else: ?>
  <p class="ok">Everything in the file matched something here, no loose ends.</p>
<?php endif; ?>

<div class="actions">
  <a class="button" href="project.php?id=<?= $projectId ?>">Back to the project</a>
  <a class="button-link" href="<?= e($selfUrl) ?>">Import another file</a>
</div>

<?php endif; ?>
