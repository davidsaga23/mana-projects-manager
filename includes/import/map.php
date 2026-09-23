<?php
// Import screen 2 — say what the file's own vocabulary means here.
// Every choice is remembered, so a second import of the same board is nearly
// automatic. Variables in scope: $pdo, $project, $projectId, $selfUrl, $state.

$parsed = plaky_read_csv($state['file']);
$analysis = plaky_analyse($pdo, $projectId, $parsed['rows']);

$crew = $pdo->query('SELECT id, name, active FROM crew ORDER BY active DESC, name')->fetchAll();
$unmatchedSteps  = count(array_filter($analysis['steps'],  function ($s) { return $s['step_id'] === null; }));
$unmatchedPeople = count(array_filter($analysis['people'], function ($p) { return $p['crew_id'] === null; }));
$unmatchedStatus = count(array_filter($analysis['statuses'], function ($s) { return $s['status_id'] === null; }));
?>
<p class="summary-line">
  <strong><?= e($state['name']) ?></strong> ·
  <?= (int)$analysis['parent_count'] ?> items ·
  <?= (int)$analysis['subitem_count'] ?> step records ·
  time read as <strong><?= e($state['time_unit']) ?></strong>
</p>

<?php // THE COLUMNS, said before the import rather than discovered after it.
      // A heading this file does not carry costs whole fields without failing
      // anything: FC Mobile's board spells it "Asignee", and 510 records came in
      // with nobody attached and nothing said. Shown here, where it can still be
      // fixed by renaming the column in Plaky and exporting again. ?>
<?php if (!empty($parsed['renamed_columns']) || !empty($parsed['missing_columns'])): ?>
  <div class="flash flash-warn">
    <?php foreach ($parsed['renamed_columns'] as $from => $to): ?>
      <p><strong><?= e($from) ?></strong> read as <strong><?= e($to) ?></strong>
         — a known misspelling of that heading.</p>
    <?php endforeach; ?>
    <?php if (!empty($parsed['missing_columns'])): ?>
      <p><strong>This file has no
        <?php $names = array_keys($parsed['missing_columns']);
              echo e(count($names) === 1 ? $names[0] . ' column' : implode(', ', $names) . ' columns'); ?>.</strong>
        The import will still run:
        <?= e(implode('; ', $parsed['missing_columns'])) ?>.
        If that is wrong, add the column in Plaky and export again — nothing here
        can invent it.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($unmatchedSteps || $unmatchedPeople || $unmatchedStatus): ?>
  <div class="flash flash-warn">
    Some values in this file aren't known here yet:
    <?php $bits = [];
      if ($unmatchedSteps)  $bits[] = "$unmatchedSteps step name" . ($unmatchedSteps === 1 ? '' : 's');
      if ($unmatchedStatus) $bits[] = "$unmatchedStatus status value" . ($unmatchedStatus === 1 ? '' : 's');
      if ($unmatchedPeople) $bits[] = "$unmatchedPeople " . ($unmatchedPeople === 1 ? 'person' : 'people');
      echo e(implode(', ', $bits));
    ?>. Set them below, what you choose is remembered for next time.
  </div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="action" value="map">

  <h3>Groups</h3>
  <p class="muted small">Plaky uses one "Group" column for two different things, episodes of
     shots, and types of asset. Only you can say which is which.</p>
  <table class="review-table">
    <tr><th>Group</th><th>Items</th><th>Treat as</th></tr>
    <?php $gi = 0; foreach ($analysis['groups'] as $g => $info):
      $guess = $info['guess'];   // suggested, never applied silently
    ?>
    <tr>
      <td><strong><?= e($g) ?></strong>
        <?php if ($info['project_step_matches'] === $info['count'] && $info['count'] > 0): ?>
          <div class="muted small">every item here names a whole-project step</div>
        <?php endif; ?>
        <input type="hidden" name="group_key[<?= $gi ?>]" value="<?= e($g) ?>"></td>
      <td class="muted"><?= (int)$info['count'] ?></td>
      <td>
        <select name="group_val[<?= $gi ?>]">
          <option value="shots"        <?= $guess === 'shots'        ? 'selected' : '' ?>>shots (no episodes)</option>
          <option value="episode"      <?= $guess === 'episode'      ? 'selected' : '' ?>>an episode of shots</option>
          <option value="asset_type"   <?= $guess === 'asset_type'   ? 'selected' : '' ?>>a type of asset</option>
          <option value="project_step" <?= $guess === 'project_step' ? 'selected' : '' ?>>whole-project steps</option>
          <option value="ignore">ignore this group</option>
        </select>
      </td>
    </tr>
    <?php $gi++; endforeach; ?>
  </table>
  <p class="muted small">
    <strong>Shots (no episodes)</strong> is for a single film or commercial, the shots go in
    as one flat list rather than an episode named after the group.
    <strong>Whole-project steps</strong> is for groups like Pre-production or Post-production,
    where each item (an animatic, a music track) <em>is</em> a step for the whole show rather
    than something with steps beneath it.
  </p>

  <?php if ($analysis['childless']): ?>
    <label class="inline checkbox-note">
      <input type="checkbox" name="skip_childless" value="1" checked>
      Skip the <?= count($analysis['childless']) ?> item<?= count($analysis['childless']) === 1 ? '' : 's' ?>
      with no pipeline steps under them
    </label>
    <p class="muted small">
      <?php $names = array_map(function ($c) { return $c['title'] . ' (' . $c['group'] . ')'; }, $analysis['childless']); ?>
      <?= e(implode(', ', array_slice($names, 0, 8))) ?><?= count($names) > 8 ? ' …' : '' ?>.
      An item with no steps beneath it is usually not a shot or an asset, a time bucket or a
      one-off deliverable. Untick to import them anyway.
    </p>
  <?php endif; ?>

  <h3>Pipeline steps</h3>
  <p class="muted small">Subitem names in the file, matched to this project's steps,
     listed <strong>per group</strong>, because the same name can mean different steps.
     "Concept" under Characters and "Concept" under Environments are two different things,
     so each gets its own choice.</p>
  <table class="review-table">
    <tr><th>Group</th><th>In the file</th><th>Rows</th><th>Is this project's step</th></tr>
    <?php $si = 0; $lastGroup = null; foreach ($analysis['steps'] as $s): ?>
    <tr class="<?= $s['step_id'] === null ? 'has-problem' : '' ?>">
      <td class="muted"><?= $s['group'] === $lastGroup ? '' : e($s['group']) ?><?php $lastGroup = $s['group']; ?></td>
      <td><strong><?= e($s['external']) ?></strong>
        <?php if ($s['canonical'] !== $s['external']): ?>
          <span class="muted small">→ <?= e($s['canonical']) ?> (existing alias)</span>
        <?php endif; ?>
        <input type="hidden" name="step_key[<?= $si ?>]" value="<?= e($s['key']) ?>">
        <input type="hidden" name="step_name[<?= $si ?>]" value="<?= e($s['external']) ?>"></td>
      <td class="muted"><?= (int)$s['count'] ?></td>
      <td>
        <select name="step_val[<?= $si ?>]" class="step-choice" data-scope-for="step-scope-<?= $si ?>">
          <option value="0">skip these rows</option>
          <?php foreach ($analysis['project_steps'] as $ps): ?>
            <?php // Whole-project steps have no shot or asset to attach to, so a
                  // subitem could never belong to one. Leaving them out prevents
                  // a mapping that would silently produce nothing.
                  if ($ps['applies_to'] === 'project') continue; ?>
            <option value="<?= (int)$ps['id'] ?>" <?= $s['step_id'] === (int)$ps['id'] ? 'selected' : '' ?>>
              <?= e($ps['name']) ?>
              <?php if (!empty($ps['applies_to_asset_type'])): ?>· <?= e($ps['applies_to_asset_type']) ?><?php endif; ?>
            </option>
          <?php endforeach; ?>
          <option value="new">+ create a step called "<?= e($s['external']) ?>"</option>
        </select>
        <?php /* Only relevant when "create a step" is chosen. Rendered visible so
                 it still works without JavaScript; the script hides it until it
                 actually applies. */ ?>
        <?php if ($s['step_id'] === null): ?>
          <span class="step-scope" id="step-scope-<?= $si ?>">
            <span class="muted small">new step applies to</span>
            <select name="step_applies[<?= $si ?>]">
              <?= applies_to_options('shot', asset_types($pdo)) ?>
            </select>
          </span>
        <?php endif; ?>
      </td>
    </tr>
    <?php $si++; endforeach; ?>
  </table>

  <h3>Statuses</h3>
  <p class="muted small">What each value in the file means here. Anything left unset is
     imported as raw text and reported afterwards, never guessed at.</p>
  <table class="review-table">
    <tr><th>In the file</th><th>Rows</th><th>Means</th></tr>
    <?php $ci = 0; foreach ($analysis['statuses'] as $s): ?>
    <tr class="<?= $s['status_id'] === null ? 'has-problem' : '' ?>">
      <td><strong><?= e($s['external']) ?></strong>
        <input type="hidden" name="status_key[<?= $ci ?>]" value="<?= e($s['external']) ?>"></td>
      <td class="muted"><?= (int)$s['count'] ?></td>
      <td>
        <select name="status_val[<?= $ci ?>]">
          <option value="0">leave unmapped</option>
          <?php foreach ($analysis['project_statuses'] as $ps): ?>
            <option value="<?= (int)$ps['id'] ?>" <?= $s['status_id'] === (int)$ps['id'] ? 'selected' : '' ?>>
              <?= e($ps['name']) ?> <?= $ps['maps_to'] !== 'none' ? '(' . e($ps['maps_to']) . ')' : '' ?><?= $ps['is_blocked'] ? ' (blocked)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
    </tr>
    <?php $ci++; endforeach; ?>
  </table>

  <h3>People</h3>
  <p class="muted small">Names as the file writes them. "(inactive)" has already been stripped,
     and fields holding two people have been split.</p>
  <table class="review-table">
    <tr><th>In the file</th><th>Rows</th><th>Is</th></tr>
    <?php $pi = 0; foreach ($analysis['people'] as $p): ?>
    <tr class="<?= $p['crew_id'] === null ? 'has-problem' : '' ?>">
      <td><strong><?= e($p['name']) ?></strong>
        <?php if ($p['inactive']): ?><span class="muted small"> · marked inactive</span><?php endif; ?>
        <input type="hidden" name="person_key[<?= $pi ?>]" value="<?= e($p['name']) ?>"></td>
      <td class="muted"><?= (int)$p['count'] ?></td>
      <td>
        <select name="person_val[<?= $pi ?>]">
          <option value="0">leave unmatched</option>
          <?php foreach ($crew as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $p['crew_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['active'] ? '' : ' (inactive)' ?>
            </option>
          <?php endforeach; ?>
          <option value="new" <?= $p['crew_id'] === null ? 'selected' : '' ?>>+ add "<?= e($p['name']) ?>" to the crew</option>
        </select>
      </td>
    </tr>
    <?php $pi++; endforeach; ?>
  </table>

  <div class="actions">
    <button type="submit">Continue to preview →</button>
    <button type="submit" name="action" value="cancel" class="secondary" formnovalidate>Cancel import</button>
  </div>
</form>

<script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
