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

    $stmt = $pdo->prepare('SELECT id, name, asset_type, notes FROM assets WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $existing = [];
    foreach ($stmt as $row) $existing[(int)$row['id']] = $row;

    // assets.notes has existed since install.sql and nothing has ever written to
    // it. Surfaced 2026-08-31 at David's request: the breakdown is where you
    // know a thing about an asset -- "hero prop, needs a rig", "reuse from ep2"
    // -- and until now there was nowhere to put it.
    $upd = $pdo->prepare(
        'UPDATE assets SET name = ?, asset_type = ?, notes = ? WHERE id = ? AND project_id = ?'
    );
    $changed = 0;
    foreach ($names as $id => $name) {
        $id = (int)$id;
        if (!isset($existing[$id])) continue;
        $name = trim($name);
        if ($name === '') {
            flash('warn', 'An asset was left with a blank name, so its old name was kept.');
            continue;
        }
        $type  = trim($_POST['type'][$id] ?? '');
        // Absent, not blank, when the field was never on screen: only rows drawn
        // with a notes box post one, so a screen rendered before this feature
        // must not read as "the note was cleared".
        $notes = array_key_exists($id, (array)($_POST['notes'] ?? []))
               ? trim((string)$_POST['notes'][$id])
               : (string)($existing[$id]['notes'] ?? '');
        $before = $existing[$id];
        if ($before['name'] === $name && ($before['asset_type'] ?? '') === $type
            && (string)($before['notes'] ?? '') === $notes) continue;

        try {
            $upd->execute([$name, $type !== '' ? $type : null,
                           $notes !== '' ? $notes : null, $id, $projectId]);
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
        $ins = $pdo->prepare(
            'INSERT INTO assets (project_id, name, asset_type, notes) VALUES (?, ?, ?, ?)'
        );
        $added = 0; $dupes = []; $bad = 0; $invented = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // NAME, TYPE, NOTE -- and the note keeps any commas of its own.
            //
            // The limit of 3 is the whole trick: "Phaser, Prop, hero prop needs
            // a rig, reuse the ep2 model" has to leave the note intact rather
            // than shredding it into fields nothing reads.
            //
            // Split POSITIONALLY, and NOT on runs -- the pattern deliberately
            // has no +. With a third column the position IS the meaning, so
            // an empty middle field has to stay empty: "Bridge,,reuse from
            // ep2" means no type and a note, and collapsing the pair of
            // commas would promote the note to a type and invent a junk
            // asset type from it.
            //
            // It is also how a spreadsheet actually pastes: copy three
            // columns out of Excel with the middle cell blank and you get
            // exactly "Name<tab><tab>Note".
            $line  = ltrim($line, ", ;\t");
            $parts = array_map('trim', preg_split('/[,;\t]/', $line, 3));
            $name  = $parts[0] ?? '';
            if ($name === '') { $bad++; continue; }
            $type  = ($parts[1] ?? '') !== '' ? $parts[1] : $defaultType;
            $note  = $parts[2] ?? '';
            try {
                $ins->execute([$projectId, $name, $type !== '' ? $type : null,
                               $note !== '' ? $note : null]);
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

    // Merge two spellings of one type. Offered only where the screen has already
    // pointed the pair out, and it moves ASSETS onto the winning spelling rather
    // than renaming anything on the pipeline — a step's scope is what the plan is
    // built against, so it is the thing to converge on, not the thing to change.
    if (preg_match('/^merge_types$/', $action)) {
        $into = trim($_POST['merge_into'] ?? '');
        $from = (array)($_POST['merge_from'] ?? []);
        if ($into === '' || !$from) {
            throw new RuntimeException('Nothing to merge.');
        }
        $in = implode(',', array_fill(0, count($from), '?'));
        $stmt = $pdo->prepare(
            "UPDATE assets SET asset_type = ? WHERE project_id = ? AND asset_type IN ($in)"
        );
        $stmt->execute(array_merge([$into, $projectId], array_values($from)));
        flash('ok', sprintf(
            'Moved %d asset%s onto "%s". Any step scoped to that type now counts them.',
            $stmt->rowCount(), $stmt->rowCount() === 1 ? '' : 's', $into
        ));
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
    // Two spellings of one type is the quietest way to break a plan: a step
    // scoped to "Environment" counts none of six assets typed "Environments",
    // reports a healthy-looking number, and every date derived from it is wrong.
    $similar = similar_asset_types($pdo, $projectId);
    $anchors = array_merge(step_scoped_asset_types($pdo, $projectId),
                           ['Character', 'Environment', 'Prop', 'Vehicle', 'FX']);
    ?>
    <p class="lead">Characters, environments, props — anything built once and reused across
       shots. Skip this if the project doesn't track assets separately.</p>

    <?php foreach ($similar as $spellings):
        $into  = preferred_asset_type($spellings, $anchors);
        $from  = array_keys(array_diff_key($spellings, [$into => true]));
        $moving = 0;
        foreach ($from as $f) $moving += $spellings[$f];
        $parts = [];
        foreach ($spellings as $name => $n) $parts[] = '"' . $name . '" (' . $n . ')';
    ?>
      <div class="flash flash-warn">
        <strong>Two spellings of the same type:</strong> <?= e(implode(' and ', $parts)) ?>.
        These count as completely different types — a pipeline step scoped to one of them
        counts none of the other, and every quota and date derived from it is wrong without
        anything looking broken.
        <?php if (can_edit()): ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="merge_into" value="<?= e($into) ?>">
            <?php foreach ($from as $f): ?>
              <input type="hidden" name="merge_from[]" value="<?= e($f) ?>">
            <?php endforeach; ?>
            <button type="submit" name="action" value="merge_types" class="secondary">
              Move all <?= $moving ?> onto "<?= e($into) ?>"
            </button>
            <small>Picked because a pipeline step is scoped to it.</small>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

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
              <th>Name</th><th>Type</th><th>Notes</th><th></th>
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
              <?php // One line, not a textarea: this is a grid, and a box that
                    // grows would push every other row out of alignment. Long
                    // notes still store in full -- the column is TEXT. ?>
              <td><input name="notes[<?= $id ?>]" class="asset-note"
                         value="<?= e($a['notes'] ?? '') ?>"
                         placeholder="anything worth knowing"></td>
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
          <span class="hint">keeps your name, type and note edits, leaves you on this screen</span>
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
        <textarea name="paste" rows="6" placeholder="Romulan Officer, Character&#10;Bridge, Environment, reuse from ep2&#10;Phaser, Prop, hero prop, needs a rig"></textarea>
        <small><strong>One asset per line.</strong> Commas split a line into
               <em>name, type, note</em> — so <code>Phaser, Prop, hero prop</code> sets all three,
               and anything after the second comma stays in the note, commas and all.
               A comma is never a second asset: put each name on its own line.</small>
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
