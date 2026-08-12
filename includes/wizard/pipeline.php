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
        'UPDATE pipeline_steps SET name = ?, applies_to = ?, tracks_seconds = ?, color = ?
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
        $appliesTo = $_POST['applies_to'][$id] ?? $existing[$id]['applies_to'];
        if (!in_array($appliesTo, ['shot','asset','both'], true)) $appliesTo = 'shot';
        $tracks = !empty($_POST['tracks_seconds'][$id]) ? 1 : 0;
        $color  = trim($_POST['color'][$id] ?? '') ?: null;

        $before = $existing[$id];
        if ($before['name'] === $name && $before['applies_to'] === $appliesTo
            && (int)$before['tracks_seconds'] === $tracks && ($before['color'] ?? '') === ($color ?? '')) {
            continue;
        }
        try {
            $upd->execute([$name, $appliesTo, $tracks, $color, $id, $projectId]);
            $changed++;
        } catch (PDOException $e) {
            flash('warn', 'Could not rename "' . $before['name'] . '" to "' . $name .
                          '" — another step already has that name.');
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
        $appliesTo = $_POST['new_applies_to'] ?? 'shot';
        if (!in_array($appliesTo, ['shot','asset','both'], true)) $appliesTo = 'shot';
        $tracks = !empty($_POST['new_tracks_seconds']) ? 1 : 0;

        $max = $pdo->prepare('SELECT COALESCE(MAX(step_order), 0) FROM pipeline_steps WHERE project_id = ?');
        $max->execute([$projectId]);
        $order = (int)$max->fetchColumn() + 1;

        try {
            $ins = $pdo->prepare(
                'INSERT INTO pipeline_steps (project_id, name, step_order, tracks_seconds, applies_to)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$projectId, $name, $order, $tracks, $appliesTo]);
        } catch (PDOException $e) {
            throw new RuntimeException('This project already has a step called "' . $name . '".');
        }
        resync_workflow($pdo, $projectId);
        step_library_remember($pdo, $name, $appliesTo, $tracks);
        flash('ok', 'Added the step "' . $name . '" at the end of the pipeline. ' .
                    'It will be suggested on future projects too.');
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

        // Parallelism is stored as shared dependencies, so it is read relative to
        // the order. Moving a step can therefore change which steps run together.
        $depsBefore = step_dependencies_map($pdo, $projectId);
        $hadParallel = false;
        foreach (['shot', 'asset'] as $kind) {
            $chain = chain_steps(pipeline_steps($pdo, $projectId), $kind);
            if (array_filter(derive_parallel_flags($chain, $depsBefore))) $hadParallel = true;
        }

        resync_workflow($pdo, $projectId);
        flash('ok', 'Saved the new step order.');
        if ($hadParallel) {
            flash('warn', 'Some steps run in parallel — check the Workflow screen still ' .
                          'shows what you expect after moving things around.');
        }
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
            $pdo->prepare('DELETE FROM pipeline_steps WHERE id = ? AND project_id = ?')
                ->execute([$stepId, $projectId]);
            renumber_steps($pdo, $projectId);
            resync_workflow($pdo, $projectId);
            flash('ok', 'Removed the step "' . $name . '".');
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
        resync_workflow($pdo, $projectId);
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

    <form method="post">
      <?php /* First submit button in the form is the one Enter triggers — make that
               a plain save rather than whichever row button happens to come first. */ ?>
      <button type="submit" name="action" value="save" hidden></button>

      <input type="hidden" name="order" id="step-order" value="">
      <button type="submit" name="action" value="reorder" id="reorder-submit" hidden></button>

      <table class="step-table">
        <thead>
        <tr>
          <th></th><th>Step</th><th>Applies to</th>
          <th title="Progress measured in seconds of footage rather than a count of items">Measured in</th>
          <th>Colour</th><th></th>
        </tr>
        </thead>
        <tbody id="step-rows">
        <?php foreach ($steps as $i => $s): $id = (int)$s['id']; ?>
        <tr data-id="<?= $id ?>">
          <td class="drag-handle" draggable="true" title="Drag to reorder">
            <span class="grip">⠿</span><span class="muted"><?= $i + 1 ?></span>
          </td>
          <td><input name="name[<?= $id ?>]" value="<?= e($s['name']) ?>"></td>
          <td>
            <select name="applies_to[<?= $id ?>]">
              <option value="shot"  <?= $s['applies_to']==='shot'  ? 'selected':'' ?>>Shots</option>
              <option value="asset" <?= $s['applies_to']==='asset' ? 'selected':'' ?>>Assets</option>
              <option value="both"  <?= $s['applies_to']==='both'  ? 'selected':'' ?>>Both</option>
            </select>
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
        <button type="submit" name="action" value="continue">Save and continue →</button>
        <button type="submit" name="action" value="save" class="secondary">Save</button>
      </div>
    </form>

    <?php
    // Steps used on earlier projects, offered as suggestions. Anything already in
    // this project is dropped from the list — it can't be added twice.
    $used = [];
    foreach ($steps as $s) $used[mb_strtolower($s['name'])] = true;
    $library = array_values(array_filter(step_library($pdo), function ($l) use ($used) {
        return !isset($used[mb_strtolower($l['name'])]);
    }));
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
      <select name="new_applies_to" id="new-step-applies-to">
        <option value="shot">Shots</option>
        <option value="asset">Assets</option>
        <option value="both">Both</option>
      </select>
      <label class="inline">
        <input type="checkbox" name="new_tracks_seconds" id="new-step-seconds" value="1"> measured in seconds
      </label>
      <button type="submit" class="secondary">Add</button>
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
