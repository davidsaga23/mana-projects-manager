<?php
// Wizard step 5 — the asset breakdown (characters, environments, props, …).
//
// Asset TYPES stay free text in the schema — STFC needed a schema change to add
// Props, which this avoids — but the UI offers them as a dropdown built from a
// few sensible defaults plus every type already used on any project. So types
// accumulate naturally and stay consistently spelled, without ever being a
// fixed list in code.
//
// Types can also be corrected in bulk after the fact: paste fifty assets under
// the wrong type and it is a two-click fix, not fifty deletions.

/** Type choices: built-in starters plus everything already used anywhere. */
function wizard_asset_types(PDO $pdo): array {
    $defaults = ['Character', 'Environment', 'Prop', 'Vehicle', 'FX'];
    $used = $pdo->query(
        "SELECT DISTINCT asset_type FROM assets
          WHERE asset_type IS NOT NULL AND asset_type <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $all = array_unique(array_merge($defaults, $used));
    natcasesort($all);
    return array_values($all);
}

/** Render <option> tags for a type dropdown. */
function wizard_asset_type_options(array $types, string $selected = '', string $blankLabel = '— no type —'): string {
    $html = '<option value="">' . e($blankLabel) . '</option>';
    foreach ($types as $t) {
        $html .= '<option value="' . e($t) . '"' . ($t === $selected ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $html;
}

/** A typed-in new type wins over the dropdown, so adding one needs no extra step. */
function wizard_asset_chosen_type(string $selectField, string $newField): string {
    $new = trim($_POST[$newField] ?? '');
    return $new !== '' ? $new : trim($_POST[$selectField] ?? '');
}

function wizard_save_assets(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'continue';

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
        $stmt = $pdo->prepare(
            "UPDATE assets SET asset_type = ? WHERE project_id = ? AND id IN ($in)"
        );
        $stmt->execute(array_merge([$newType ?: null, $projectId], $ids));
        $n = $stmt->rowCount();

        flash('ok', $newType === ''
            ? "Cleared the type on " . count($ids) . ' asset' . (count($ids) === 1 ? '' : 's') . '.'
            : "Moved " . count($ids) . ' asset' . (count($ids) === 1 ? '' : 's') . " to \"$newType\".");
        if ($n === 0 && count($ids) > 0) {
            flash('warn', 'Those assets already had that type, so nothing changed.');
        }
        return wizard_url($projectId, 5);
    }

    if ($action === 'paste') {
        $defaultType = wizard_asset_chosen_type('paste_type', 'paste_type_new');
        $lines = preg_split('/\R/', $_POST['paste'] ?? '');
        $ins = $pdo->prepare('INSERT INTO assets (project_id, name, asset_type) VALUES (?, ?, ?)');
        $added = 0; $dupes = []; $bad = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t]+/', $line)),
                                               function ($p) { return $p !== ''; }));
            if (!$parts) { $bad++; continue; }
            $name = $parts[0];
            $type = $parts[1] ?? $defaultType;
            try {
                $ins->execute([$projectId, $name, $type ?: null]);
                $added++;
            } catch (PDOException $e) {
                $dupes[] = $name;
            }
        }
        if ($added) flash('ok', "Added $added asset" . ($added === 1 ? '' : 's') .
                                ($defaultType !== '' ? " as \"$defaultType\"." : '.'));
        if ($dupes) flash('warn', count($dupes) . ' already existed and were skipped: ' .
                                   implode(', ', array_slice($dupes, 0, 12)) .
                                   (count($dupes) > 12 ? ' …' : ''));
        if ($bad)   flash('warn', "$bad line" . ($bad === 1 ? '' : 's') . ' could not be read and were skipped.');
        if (!$added && !$dupes && !$bad) flash('warn', 'Nothing was pasted, so nothing was added.');
        return wizard_url($projectId, 5);
    }

    return wizard_next_url($projectId, 5);
}

function wizard_render_assets(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $stmt = $pdo->prepare('SELECT * FROM assets WHERE project_id = ? ORDER BY asset_type, name');
    $stmt->execute([$projectId]);
    $assets = $stmt->fetchAll();
    $types = wizard_asset_types($pdo);

    $byType = [];
    foreach ($assets as $a) $byType[$a['asset_type'] ?? ''][] = $a;

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
      <p class="summary-line"><strong><?= count($assets) ?></strong> assets
        <?php if (count($byType) > 1): ?>· <?= count($byType) ?> types<?php endif; ?>
      </p>

      <form method="post">
        <button type="submit" name="action" value="continue" hidden></button>
        <?php $g = 0; foreach ($byType as $type => $rows): $g++; $groupId = 'asset-group-' . $g; ?>
          <div class="chip-group">
            <h4><?= $type === '' ? '<span class="muted">No type</span>' : e($type) ?>
                <span class="muted small">· <?= count($rows) ?></span>
                <label class="inline check-all">
                  <input type="checkbox" data-check-all="<?= $groupId ?>"> select all
                </label>
            </h4>
            <div class="chips" id="<?= $groupId ?>">
            <?php foreach ($rows as $a): ?>
              <span class="chip">
                <input type="checkbox" name="sel[]" value="<?= (int)$a['id'] ?>"
                       title="Select to change its type">
                <?= e($a['name']) ?>
                <button type="submit" name="action" value="del:<?= (int)$a['id'] ?>" title="Remove">✕</button>
              </span>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="retype-bar">
          <strong>Fix a type</strong>
          <span class="muted small">Tick assets above, then set what they should be:</span>
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
        <small>One asset per line. Add <code>, TYPE</code> after a name to override the
               type for that line.</small>
        <label>Type for these assets
          <select name="paste_type"><?= wizard_asset_type_options($types) ?></select>
        </label>
        <label>Or a new type
          <input name="paste_type_new" placeholder="Creature">
        </label>
        <button type="submit" class="secondary">Add these assets</button>
      </form>
    </div>

    <form method="post" class="actions">
      <button type="submit" name="action" value="continue">Save and continue →</button>
    </form>

    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
