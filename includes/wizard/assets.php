<?php
// Wizard step 5 — the asset breakdown (characters, environments, props, …).
//
// Same shape as the shots step: paste a list, see exactly what was added and
// what was skipped. Asset TYPES are free text rather than a fixed list —
// STFC needed a schema change to add Props, which this avoids.

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

    if ($action === 'paste') {
        $defaultType = trim($_POST['paste_type'] ?? '');
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
        if ($added) flash('ok', "Added $added asset" . ($added === 1 ? '' : 's') . '.');
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
        <?php foreach ($byType as $type => $rows): ?>
          <div class="chip-group">
            <h4><?= $type === '' ? '<span class="muted">No type</span>' : e($type) ?>
                <span class="muted small">· <?= count($rows) ?></span></h4>
            <div class="chips">
            <?php foreach ($rows as $a): ?>
              <span class="chip">
                <?= e($a['name']) ?>
                <button type="submit" name="action" value="del:<?= (int)$a['id'] ?>" title="Remove">✕</button>
              </span>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </form>
    <?php else: ?>
      <p class="muted">No assets yet.</p>
    <?php endif; ?>

    <div class="tool-grid">
      <form method="post" class="tool">
        <input type="hidden" name="action" value="paste">
        <strong>Paste a list</strong>
        <textarea name="paste" rows="6" placeholder="Romulan Officer, Character&#10;Bridge, Environment&#10;Phaser, Prop"></textarea>
        <small>One asset per line. <code>NAME, TYPE</code> — type optional.</small>
        <label>Type for lines without one
          <input name="paste_type" placeholder="Character">
        </label>
        <button type="submit" class="secondary">Add these assets</button>
      </form>
    </div>

    <form method="post" class="actions">
      <button type="submit" name="action" value="continue">Save and continue →</button>
    </form>
    <?php
}
