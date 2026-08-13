<?php
// Wizard step 5 — the asset breakdown (characters, environments, props, …).
//
// Asset TYPES stay free text in the schema — STFC needed a schema change to add
// Props, which this avoids — but the UI offers them as a dropdown built from a
// few sensible defaults plus every type already used on any project. So types
// accumulate naturally and stay consistently spelled, without ever being a
// fixed list in code.
//
// Everything is editable after the fact: names and types row by row, types in
// bulk for a batch pasted wrongly. Pasting is easy to get slightly wrong, so
// correcting it has to be easier than deleting and starting over.

/**
 * Type choices. Shares asset_types() with the pipeline screen deliberately: a
 * step can be scoped to "Character assets", so the two lists must never diverge.
 */
function wizard_asset_types(PDO $pdo): array {
    return asset_types($pdo);
}

/** Render <option> tags for a type dropdown. */
function wizard_asset_type_options(array $types, string $selected = '', string $blankLabel = '— no type —'): string {
    $html = '<option value="">' . e($blankLabel) . '</option>';
    $found = false;
    foreach ($types as $t) {
        $isSel = ($t === $selected);
        if ($isSel) $found = true;
        $html .= '<option value="' . e($t) . '"' . ($isSel ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    // A type that exists only on this row must still be selectable, or saving the
    // row would silently change it to something else.
    if ($selected !== '' && !$found) {
        $html .= '<option value="' . e($selected) . '" selected>' . e($selected) . '</option>';
    }
    return $html;
}

/** A typed-in new type wins over the dropdown, so adding one needs no extra step. */
function wizard_asset_chosen_type(string $selectField, string $newField): string {
    $new = trim($_POST[$newField] ?? '');
    return $new !== '' ? $new : trim($_POST[$selectField] ?? '');
}

/** Apply the name and type edits posted alongside whichever button was clicked. */
function wizard_assets_apply_edits(PDO $pdo, int $projectId): int {
    $names = $_POST['name'] ?? [];
    if (!is_array($names) || !$names) return 0;

    $stmt = $pdo->prepare('SELECT id, name, asset_type FROM assets WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $existing = [];
    foreach ($stmt as $row) $existing[(int)$row['id']] = $row;

    $upd = $pdo->prepare('UPDATE assets SET name = ?, asset_type = ? WHERE id = ? AND project_id = ?');
    $changed = 0;
    foreach ($names as $id => $name) {
        $id = (int)$id;
        if (!isset($existing[$id])) continue;
        $name = trim($name);
        if ($name === '') {
            flash('warn', 'An asset was left with a blank name, so its old name was kept.');
            continue;
        }
        $type = trim($_POST['type'][$id] ?? '');
        $before = $existing[$id];
        if ($before['name'] === $name && ($before['asset_type'] ?? '') === $type) continue;

        try {
            $upd->execute([$name, $type !== '' ? $type : null, $id, $projectId]);
            $changed++;
        } catch (PDOException $e) {
            flash('warn', 'Could not rename "' . $before['name'] . '" to "' . $name .
                          '" — this project already has an asset with that name.');
        }
    }
    return $changed;
}

function wizard_save_assets(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'continue';

    if ($action === 'paste') {
        $defaultType = wizard_asset_chosen_type('paste_type', 'paste_type_new');
        // Captured before inserting, so a type this paste invents stands out.
        $knownTypes = [];
        foreach (wizard_asset_types($pdo) as $t) $knownTypes[mb_strtolower($t)] = true;

        $lines = preg_split('/\R/', $_POST['paste'] ?? '');
        $ins = $pdo->prepare('INSERT INTO assets (project_id, name, asset_type) VALUES (?, ?, ?)');
        $added = 0; $dupes = []; $bad = 0; $invented = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t]+/', $line)),
                                               function ($p) { return $p !== ''; }));
            if (!$parts) { $bad++; continue; }
            $name = $parts[0];
            $type = $parts[1] ?? $defaultType;
            try {
                $ins->execute([$projectId, $name, $type !== '' ? $type : null]);
                $added++;
                if ($type !== '' && !isset($knownTypes[mb_strtolower($type)])) {
                    $invented[$type] = true;
                }
            } catch (PDOException $e) {
                $dupes[] = $name;
            }
        }
        if ($added) flash('ok', "Added $added asset" . ($added === 1 ? '' : 's') .
                                ($defaultType !== '' ? " as \"$defaultType\"." : '.'));
        // The classic paste mistake is several names on one line: the second name
        // becomes a type. Naming the new types makes that obvious immediately.
        if ($invented) {
            flash('warn', 'This created ' . (count($invented) === 1 ? 'a new type' : 'new types') .
                          ': "' . implode('", "', array_keys($invented)) . '". If that was not ' .
                          'intended — putting two names on one line does it — tick those assets ' .
                          'and use "Fix a type" below.');
        }
        if ($dupes) flash('warn', count($dupes) . ' already existed and were skipped: ' .
                                   implode(', ', array_slice($dupes, 0, 12)) .
                                   (count($dupes) > 12 ? ' …' : ''));
        if ($bad)   flash('warn', "$bad line" . ($bad === 1 ? '' : 's') . ' could not be read and were skipped.');
        if (!$added && !$dupes && !$bad) flash('warn', 'Nothing was pasted, so nothing was added.');
        return wizard_url($projectId, 5);
    }

    // Everything below shares the edit form, so pending edits are saved first
    // rather than being thrown away by clicking a row button.
    $changed = wizard_assets_apply_edits($pdo, $projectId);

    if (preg_match('/^del:(\d+)$/', $action, $m)) {
        $stmt = $pdo->prepare('SELECT name FROM assets WHERE id = ? AND project_id = ?');
        $stmt->execute([(int)$m[1], $projectId]);
        $name = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM assets WHERE id = ? AND project_id = ?')->execute([(int)$m[1], $projectId]);
        flash('ok', $name ? "Removed \"$name\"." : 'That asset no longer exists.');
        return wizard_url($projectId, 5);
    }

    if ($action === 'retype') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['sel'] ?? []))));
        if (!$ids) {
            throw new RuntimeException('Tick the assets whose type you want to change first.');
        }
        $newType = wizard_asset_chosen_type('retype_type', 'retype_new');

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE assets SET asset_type = ? WHERE project_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$newType !== '' ? $newType : null, $projectId], $ids));

        flash('ok', $newType === ''
            ? 'Cleared the type on ' . count($ids) . ' asset' . (count($ids) === 1 ? '' : 's') . '.'
            : 'Moved ' . count($ids) . ' asset' . (count($ids) === 1 ? '' : 's') . " to \"$newType\".");
        return wizard_url($projectId, 5);
    }

    if ($changed > 0) {
        flash('ok', "Saved changes to $changed asset" . ($changed === 1 ? '' : 's') . '.');
    } elseif ($action === 'save') {
        flash('ok', 'No changes to save.');
    }

    if ($action === 'continue') return wizard_next_url($projectId, 5);
    return wizard_url($projectId, 5);
}

function wizard_render_assets(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $stmt = $pdo->prepare('SELECT * FROM assets WHERE project_id = ? ORDER BY asset_type IS NULL, asset_type, name');
    $stmt->execute([$projectId]);
    $assets = $stmt->fetchAll();
    $types = wizard_asset_types($pdo);

    $typeCounts = [];
    foreach ($assets as $a) {
        $key = $a['asset_type'] ?? '';
        $typeCounts[$key] = ($typeCounts[$key] ?? 0) + 1;
    }

    $hasAssetSteps = false;
    foreach (pipeline_steps($pdo, $projectId) as $s) {
        if (in_array($s['applies_to'], ['asset', 'both'], true)) { $hasAssetSteps = true; break; }
    }
    ?>
    <p class="lead">Characters, environments, props — anything built once and reused across
       shots. Skip this if the project doesn't track assets separately.</p>

    <?php if (!$hasAssetSteps && $assets): ?>
      <div class="flash flash-warn">No pipeline step applies to assets, so these won't be
        scheduled or tracked. Add an asset step on the
        <a href="<?= e(wizard_url($projectId, 2)) ?>">Pipeline</a> screen if they should be.</div>
    <?php endif; ?>

    <?php if ($assets): ?>
      <p class="summary-line"><strong><?= count($assets) ?></strong> assets ·
        <?php $bits = [];
              foreach ($typeCounts as $t => $n) $bits[] = ($t === '' ? 'no type' : $t) . ' ' . $n;
              echo e(implode(' · ', $bits)); ?>
      </p>

      <?php if (count($typeCounts) > 1): ?>
      <div class="step-filter">
        <label class="inline"><strong>Show</strong>
          <select data-filter-for="asset-rows" data-filter-attr="data-type"
                  data-filter-note="asset-filter-note" data-filter-noun="assets">
            <option value="">all types (<?= count($assets) ?>)</option>
            <?php foreach ($typeCounts as $t => $n): ?>
              <option value="<?= e($t) ?>"><?= $t === '' ? 'No type' : e($t) ?> (<?= $n ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <span class="muted small" id="asset-filter-note">Filtering only changes what you see —
          saving still keeps everything.</span>
      </div>
      <?php endif; ?>

      <form method="post">
        <button type="submit" name="action" value="save" hidden></button>

        <table class="asset-table">
          <thead>
            <tr>
              <th><label class="inline"><input type="checkbox" data-check-all="asset-rows"></label></th>
              <th>Name</th><th>Type</th><th></th>
            </tr>
          </thead>
          <tbody id="asset-rows">
          <?php foreach ($assets as $a): $id = (int)$a['id']; ?>
            <tr data-type="<?= e($a['asset_type'] ?? '') ?>">
              <td><input type="checkbox" name="sel[]" value="<?= $id ?>" title="Select for a bulk type change"></td>
              <td><input name="name[<?= $id ?>]" value="<?= e($a['name']) ?>"></td>
              <td>
                <select name="type[<?= $id ?>]">
                  <?= wizard_asset_type_options($types, $a['asset_type'] ?? '') ?>
                </select>
              </td>
              <td class="row-actions">
                <button type="submit" name="action" value="del:<?= $id ?>" class="danger" title="Remove"
                        onclick="return confirm('Remove <?= e(addslashes($a['name'])) ?>?');">✕</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <div class="actions">
          <button type="submit" name="action" value="save" class="secondary">Save and stay here</button>
          <span class="hint">keeps your name and type edits, leaves you on this screen</span>
          <span class="spacer"></span>
          <button type="submit" name="action" value="continue">Save and continue →</button>
        </div>

        <div class="retype-bar">
          <strong>Change several at once</strong>
          <span class="muted small">Tick rows above, then set what they should be:</span>
          <select name="retype_type"><?= wizard_asset_type_options($types) ?></select>
          <input name="retype_new" placeholder="or a new type" size="14">
          <button type="submit" name="action" value="retype" class="secondary">Change type</button>
        </div>
      </form>
    <?php else: ?>
      <p class="muted">No assets yet.</p>
    <?php endif; ?>

    <div class="tool-grid">
      <form method="post" class="tool">
        <input type="hidden" name="action" value="paste">
        <strong>Paste a list</strong>
        <textarea name="paste" rows="6" placeholder="Romulan Officer&#10;Bridge&#10;Phaser"></textarea>
        <small><strong>One asset per line.</strong> A comma on a line means the part after it
               is the <em>type</em>, not another asset — so put each name on its own line.</small>
        <label>Type for these assets
          <select name="paste_type"><?= wizard_asset_type_options($types) ?></select>
        </label>
        <label>Or a new type
          <input name="paste_type_new" placeholder="Creature">
        </label>
        <button type="submit" class="secondary">Add these assets</button>
      </form>
    </div>

    <?php if (!$assets): ?>
    <form method="post" class="actions">
      <button type="submit" name="action" value="continue">Save and continue →</button>
    </form>
    <?php endif; ?>

    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
