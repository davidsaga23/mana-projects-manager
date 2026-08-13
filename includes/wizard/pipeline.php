<?php
// Wizard step 2 — the pipeline steps themselves.
//
// Templates are seed data that get COPIED into the project, so editing a step
// here never touches the template other projects will use.
//
// The step list is one form. Each row button carries its own action in its
// value ("up:12"), so edits in the text fields are saved by the same submit
// that moves or deletes a row — no nested forms, nothing silently lost.

/** Apply the text-field edits posted alongside whichever button was clicked. */
function wizard_pipeline_apply_edits(PDO $pdo, int $projectId): int {
    $names = $_POST['name'] ?? [];
    if (!is_array($names) || !$names) return 0;

    $existing = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) $existing[(int)$s['id']] = $s;

    $upd = $pdo->prepare(
        'UPDATE pipeline_steps
            SET name = ?, applies_to = ?, applies_to_asset_type = ?, tracks_seconds = ?, color = ?
          WHERE id = ? AND project_id = ?'
    );
    $changed = 0;
    foreach ($names as $id => $name) {
        $id = (int)$id;
        if (!isset($existing[$id])) continue;
        $name = trim($name);
        if ($name === '') {
            flash('warn', 'A step was left with a blank name, so its old name was kept.');
            continue;
        }
        $before = $existing[$id];
        $rawApplies = $_POST['applies_to'][$id]
            ?? applies_to_value($before['applies_to'], $before['applies_to_asset_type'] ?? null);
        [$appliesTo, $assetType] = parse_applies_to($rawApplies);
        $tracks = !empty($_POST['tracks_seconds'][$id]) ? 1 : 0;
        $color  = trim($_POST['color'][$id] ?? '') ?: null;

        if ($before['name'] === $name && $before['applies_to'] === $appliesTo
            && ($before['applies_to_asset_type'] ?? null) === $assetType
            && (int)$before['tracks_seconds'] === $tracks && ($before['color'] ?? '') === ($color ?? '')) {
            continue;
        }
        try {
            $upd->execute([$name, $appliesTo, $assetType, $tracks, $color, $id, $projectId]);
            $changed++;
        } catch (PDOException $e) {
            // Names only have to be unique within what they apply to, so this
            // means a clash with a step of the SAME scope.
            flash('warn', 'Could not rename "' . $before['name'] . '" to "' . $name .
                          '" — another step for ' . applies_to_phrase($appliesTo, $assetType) .
                          ' is already called that. The same name is fine on a different scope.');
        }
    }
    return $changed;
}

function wizard_save_pipeline(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'save';

    // Copy a template in. Only offered while the pipeline is empty.
    if ($action === 'template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $existing = pipeline_steps($pdo, $projectId);
        if ($existing) {
            throw new RuntimeException('This project already has steps, so a template was not applied.');
        }
        if ($templateId > 0) {
            $n = apply_pipeline_template($pdo, $projectId, $templateId);
            flash('ok', "Added $n steps from the template. Edit, add or remove any of them.");
        } else {
            flash('ok', 'Starting with an empty pipeline — add your steps below.');
        }
        return wizard_url($projectId, 2);
    }

    if ($action === 'add') {
        $name = trim($_POST['new_name'] ?? '');
        if ($name === '') throw new RuntimeException('Give the new step a name.');
        [$appliesTo, $assetType] = parse_applies_to($_POST['new_applies_to'] ?? 'shot');
        $tracks = !empty($_POST['new_tracks_seconds']) ? 1 : 0;

        $max = $pdo->prepare('SELECT COALESCE(MAX(step_order), 0) FROM pipeline_steps WHERE project_id = ?');
        $max->execute([$projectId]);
        $order = (int)$max->fetchColumn() + 1;

        try {
            $ins = $pdo->prepare(
                'INSERT INTO pipeline_steps
                    (project_id, name, step_order, tracks_seconds, applies_to, applies_to_asset_type)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$projectId, $name, $order, $tracks, $appliesTo, $assetType]);
            $newId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new RuntimeException('This project already has a step called "' . $name .
                                       '" for ' . applies_to_phrase($appliesTo, $assetType) .
                                       '. The same name is fine on a different scope.');
        }
        // Give it somewhere sensible in the graph; the workflow screen refines it.
        link_new_step($pdo, $projectId, $newId);
        step_library_remember($pdo, $name, $appliesTo, $tracks);
        flash('ok', 'Added the step "' . $name . '" at the end of the pipeline. ' .
                    'It will be suggested on future projects too.');
        return wizard_url($projectId, 2);
    }

    if ($action === 'save_template') {
        // Save the edits first, so the template captures what's on screen rather
        // than what was last written.
        wizard_pipeline_apply_edits($pdo, $projectId);
        $name = trim($_POST['template_name'] ?? '');
        $type = $_POST['template_type'] ?? $project['type'];
        $n = create_template_from_project($pdo, $projectId, $name, $type);
        flash('ok', "Saved this pipeline as the template \"$name\" — $n step" . ($n === 1 ? '' : 's') .
                    ', including which of them run in parallel. It will be offered on new projects.');
        return wizard_url($projectId, 2);
    }

    $changed = wizard_pipeline_apply_edits($pdo, $projectId);

    // Drag-and-drop posts the whole new order at once.
    if ($action === 'reorder') {
        $steps = pipeline_steps($pdo, $projectId);
        $valid = [];
        foreach ($steps as $s) $valid[(int)$s['id']] = true;

        $ordered = [];
        foreach (explode(',', $_POST['order'] ?? '') as $raw) {
            $id = (int)trim($raw);
            if (isset($valid[$id]) && !in_array($id, $ordered, true)) $ordered[] = $id;
        }
        // Anything the browser left out keeps its place at the end rather than
        // vanishing from the ordering.
        foreach ($steps as $s) {
            if (!in_array((int)$s['id'], $ordered, true)) $ordered[] = (int)$s['id'];
        }

        $upd = $pdo->prepare('UPDATE pipeline_steps SET step_order = ? WHERE id = ? AND project_id = ?');
        $n = 0;
        foreach ($ordered as $id) $upd->execute([++$n, $id, $projectId]);

        // Order is presentation only now that dependencies are set explicitly, so
        // rearranging this list can no longer disturb the workflow.
        flash('ok', 'Saved the new step order. The workflow is unaffected — order here is ' .
                    'just how the list reads.');
        return wizard_url($projectId, 2);
    }

    if (preg_match('/^(up|down|del):(\d+)$/', $action, $m)) {
        $verb = $m[1];
        $stepId = (int)$m[2];
        $steps = pipeline_steps($pdo, $projectId);
        $index = null;
        foreach ($steps as $i => $s) if ((int)$s['id'] === $stepId) $index = $i;

        if ($index === null) {
            flash('warn', 'That step no longer exists.');
            return wizard_url($projectId, 2);
        }

        if ($verb === 'del') {
            $name = $steps[$index]['name'];
            // Close the gap first: anything waiting on this step inherits what it
            // was waiting on, so nothing is left free to start on day one.
            heal_dependencies_before_delete($pdo, $projectId, $stepId);
            $pdo->prepare('DELETE FROM pipeline_steps WHERE id = ? AND project_id = ?')
                ->execute([$stepId, $projectId]);
            renumber_steps($pdo, $projectId);
            flash('ok', 'Removed the step "' . $name . '". Anything that waited for it now ' .
                        'waits for whatever it waited for.');
            return wizard_url($projectId, 2);
        }

        $swapWith = $verb === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($steps)) {
            flash('warn', 'That step is already at the ' . ($verb === 'up' ? 'top' : 'bottom') . '.');
            return wizard_url($projectId, 2);
        }
        $upd = $pdo->prepare('UPDATE pipeline_steps SET step_order = ? WHERE id = ?');
        $upd->execute([(int)$steps[$swapWith]['step_order'], $steps[$index]['id']]);
        $upd->execute([(int)$steps[$index]['step_order'], $steps[$swapWith]['id']]);
        renumber_steps($pdo, $projectId);
        flash('ok', 'Moved "' . $steps[$index]['name'] . '" ' . $verb . '.');
        return wizard_url($projectId, 2);
    }

    if ($changed > 0) {
        flash('ok', "Saved changes to $changed step" . ($changed === 1 ? '' : 's') . '.');
    } elseif ($action === 'save') {
        flash('ok', 'No changes to save.');
    }
    if ($action === 'continue') return wizard_next_url($projectId, 2);
    return wizard_url($projectId, 2);
}

function wizard_render_pipeline(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);
    $assetTypes = asset_types($pdo);

    if (!$steps) {
        $tpl = $pdo->prepare(
            'SELECT t.*, (SELECT COUNT(*) FROM pipeline_template_steps s WHERE s.template_id = t.id) AS step_count
               FROM pipeline_templates t ORDER BY t.project_type = ? DESC, t.name'
        );
        $tpl->execute([$project['type']]);
        $templates = $tpl->fetchAll();
        // Mixed and Other projects match no template, so preselect "start empty"
        // rather than leaving every option blank.
        $matched = false;
        foreach ($templates as $t) if ($t['project_type'] === $project['type']) $matched = true;
        ?>
        <p class="lead">Start from a template, then change anything. Templates are just
           starting points — editing these steps never affects other projects.</p>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="template">
          <div class="template-grid">
          <?php foreach ($templates as $t): ?>
            <label class="template-card">
              <input type="radio" name="template_id" value="<?= (int)$t['id'] ?>"
                     <?= $t['project_type'] === $project['type'] ? 'checked' : '' ?>>
              <strong><?= e($t['name']) ?></strong>
              <span class="muted"><?= (int)$t['step_count'] ?> steps · for <?= e($t['project_type']) ?> projects</span>
            </label>
          <?php endforeach; ?>
            <label class="template-card">
              <input type="radio" name="template_id" value="0" <?= $matched ? '' : 'checked' ?>>
              <strong>Start empty</strong>
              <span class="muted">Add every step by hand</span>
            </label>
          </div>
          <div class="actions"><button type="submit">Use this →</button></div>
        </form>
        <?php
        return;
    }
    ?>
    <p class="lead">These are the stages work passes through. The order here is just for
       reading; which steps wait for which is set on the next screen.</p>
    <p class="muted small">
      <strong>Applies to</strong> decides what a step is counted against. <em>Both</em> is for
      work that passes over shots <em>and</em> assets — 2D colour, where characters and shots
      are both coloured. <em>The whole project</em> is for one-off deliverables like an
      animatic, a music track or the final mix, which belong to no particular shot.
    </p>

    <form method="post">
      <?php /* First submit button in the form is the one Enter triggers — make that
               a plain save rather than whichever row button happens to come first. */ ?>
      <button type="submit" name="action" value="save" hidden></button>

      <input type="hidden" name="order" id="step-order" value="">
      <button type="submit" name="action" value="reorder" id="reorder-submit" hidden></button>

      <?php
      // Only offer filters for categories that actually appear, so the control
      // stays short and never promises a view that would come back empty.
      $present = [];
      foreach ($steps as $s) {
          $v = applies_to_value($s['applies_to'], $s['applies_to_asset_type'] ?? null);
          $present[$v] = ($present[$v] ?? 0) + 1;
      }
      ?>
      <?php if (count($present) > 1): ?>
      <div class="step-filter">
        <label class="inline"><strong>Show</strong>
          <select data-filter-for="step-rows" data-filter-attr="data-category"
                  data-filter-note="step-filter-note" data-filter-noun="steps">
            <option value="">everything (<?= count($steps) ?>)</option>
            <?php foreach ($present as $value => $count):
                  [$k, $t] = parse_applies_to($value); ?>
              <option value="<?= e($value) ?>"><?= e(ucfirst(applies_to_phrase($k, $t))) ?> (<?= $count ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <span class="muted small" id="step-filter-note">Filtering only changes what you see —
          saving still keeps every step.</span>
      </div>
      <?php endif; ?>

      <table class="step-table">
        <thead>
        <tr>
          <th></th><th>Step</th><th>Applies to</th>
          <th title="Progress measured in seconds of footage rather than a count of items">Measured in</th>
          <th>Colour</th><th></th>
        </tr>
        </thead>
        <tbody id="step-rows">
        <?php foreach ($steps as $i => $s): $id = (int)$s['id'];
              $catValue = applies_to_value($s['applies_to'], $s['applies_to_asset_type'] ?? null); ?>
        <tr data-id="<?= $id ?>" data-category="<?= e($catValue) ?>" class="cat-row cat-<?= e($s['applies_to']) ?>">
          <td class="drag-handle" draggable="true" title="Drag to reorder">
            <span class="grip">⠿</span><span class="muted"><?= $i + 1 ?></span>
          </td>
          <td>
            <input name="name[<?= $id ?>]" value="<?= e($s['name']) ?>">
            <span class="cat-chip cat-chip-<?= e($s['applies_to']) ?>"><?=
              e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null))
            ?></span>
          </td>
          <td>
            <select name="applies_to[<?= $id ?>]"><?=
              applies_to_options(applies_to_value($s['applies_to'], $s['applies_to_asset_type'] ?? null), $assetTypes)
            ?></select>
          </td>
          <td>
            <input type="hidden" name="tracks_seconds[<?= $id ?>]" value="0">
            <label class="inline">
              <input type="checkbox" name="tracks_seconds[<?= $id ?>]" value="1"
                     <?= $s['tracks_seconds'] ? 'checked' : '' ?>> seconds
            </label>
          </td>
          <td><input type="color" name="color[<?= $id ?>]" value="<?= e($s['color'] ?: '#4A90D9') ?>"></td>
          <td class="row-actions">
            <button type="submit" name="action" value="up:<?= $id ?>"   title="Move up">↑</button>
            <button type="submit" name="action" value="down:<?= $id ?>" title="Move down">↓</button>
            <button type="submit" name="action" value="del:<?= $id ?>"  title="Remove step" class="danger"
                    onclick="return confirm('Remove the step <?= e(addslashes($s['name'])) ?>?');">✕</button>
          </td>
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

    <?php
    // Every remembered step name, including ones already in this project: the
    // same name on a different scope is now legitimate ("Color" for shots and
    // "Color" for characters), so filtering out what's in use would hide exactly
    // the names most likely to be wanted again.
    $library = step_library($pdo);
    $libraryMap = [];
    foreach ($library as $l) {
        $libraryMap[$l['name']] = [
            'applies_to'     => $l['applies_to'],
            'tracks_seconds' => (int)$l['tracks_seconds'],
        ];
    }
    ?>
    <form method="post" class="add-row">
      <input type="hidden" name="action" value="add">
      <strong>Add a step</strong>
      <input name="new_name" id="new-step-name" placeholder="Step name"
             list="step-library-names" autocomplete="off" required>
      <datalist id="step-library-names">
        <?php foreach ($library as $l): ?>
          <option value="<?= e($l['name']) ?>"<?= $l['times_used'] > 0
              ? ' label="used before"' : '' ?>></option>
        <?php endforeach; ?>
      </datalist>
      <select name="new_applies_to" id="new-step-applies-to"><?= applies_to_options('shot', $assetTypes) ?></select>
      <label class="inline">
        <input type="checkbox" name="new_tracks_seconds" id="new-step-seconds" value="1"> measured in seconds
      </label>
      <button type="submit" class="secondary">Add</button>
    </form>
    <form method="post" class="add-row">
      <input type="hidden" name="action" value="save_template">
      <strong>Save this pipeline as a template</strong>
      <input name="template_name" placeholder="e.g. 3D — Mana house pipeline" required size="30">
      <select name="template_type">
        <?php foreach (['2D','3D','Mixed','Other'] as $t): ?>
          <option value="<?= $t ?>" <?= $project['type'] === $t ? 'selected' : '' ?>>for <?= $t ?> projects</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="secondary">Save as template</button>
      <small>Saves the steps, their settings and which of them run in parallel — but not
             dates or headcount, which belong to this project alone. New projects will be
             offered it alongside the built-in templates.</small>
    </form>

    <?php if ($library): ?>
      <p class="muted small">Start typing to see <?= count($library) ?> step
         <?= count($library) === 1 ? 'name' : 'names' ?> used on other projects — picking one
         fills in its usual settings. Anything new you add gets remembered the same way.</p>
    <?php endif; ?>

    <script type="application/json" id="step-library-data"><?=
        json_encode($libraryMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    ?></script>
    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
