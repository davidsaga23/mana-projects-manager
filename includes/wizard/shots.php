<?php
// Wizard step 4 — the shot breakdown.
//
// Two ways in, both useful: paste a list, or generate numbered shots per
// episode (the STFC pattern: SH010, SH020, …). CSV and Excel FILE import with
// column mapping is Phase 3; pasting covers the setup case today.
//
// Every add reports how many landed and how many were skipped as duplicates —
// a silent import that quietly drops rows is the exact failure mode this
// platform is meant to avoid.

/** Split one pasted line into [episode, shot_code, seconds|null], or null to skip. */
function wizard_parse_shot_line(string $line, string $defaultEpisode): ?array {
    $line = trim($line);
    if ($line === '') return null;
    $parts = preg_split('/[,;\t]+/', $line);
    $parts = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));
    if (!$parts) return null;

    if (count($parts) === 1) {
        return [$defaultEpisode, $parts[0], null];
    }
    if (count($parts) === 2) {
        return [$parts[0], $parts[1], null];
    }
    $seconds = is_numeric($parts[2]) ? (float)$parts[2] : null;
    return [$parts[0], $parts[1], $seconds];
}

function wizard_save_shots(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'continue';

    if (preg_match('/^del:(\d+)$/', $action, $m)) {
        $stmt = $pdo->prepare('SELECT shot_code FROM shots WHERE id = ? AND project_id = ?');
        $stmt->execute([(int)$m[1], $projectId]);
        $code = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM shots WHERE id = ? AND project_id = ?')->execute([(int)$m[1], $projectId]);
        flash('ok', $code ? "Removed shot $code." : 'That shot no longer exists.');
        return wizard_url($projectId, 4);
    }

    if ($action === 'paste') {
        $defaultEpisode = trim($_POST['paste_episode'] ?? '');
        $lines = preg_split('/\R/', $_POST['paste'] ?? '');
        $ins = $pdo->prepare(
            'INSERT INTO shots (project_id, episode, shot_code, placeholder_seconds) VALUES (?, ?, ?, ?)'
        );
        $added = 0; $dupes = []; $bad = 0;
        foreach ($lines as $line) {
            $parsed = wizard_parse_shot_line($line, $defaultEpisode);
            if ($parsed === null) { if (trim($line) !== '') $bad++; continue; }
            [$episode, $code, $seconds] = $parsed;
            if ($code === '') { $bad++; continue; }
            try {
                $ins->execute([$projectId, $episode, $code, $seconds]);
                $added++;
            } catch (PDOException $e) {
                $dupes[] = ($episode !== '' ? $episode . ' ' : '') . $code;
            }
        }
        if ($added)        flash('ok', "Added $added shot" . ($added === 1 ? '' : 's') . '.');
        if ($dupes)        flash('warn', count($dupes) . ' already existed and were skipped: ' .
                                          implode(', ', array_slice($dupes, 0, 12)) .
                                          (count($dupes) > 12 ? ' …' : ''));
        if ($bad)          flash('warn', "$bad line" . ($bad === 1 ? '' : 's') . ' could not be read and were skipped.');
        if (!$added && !$dupes && !$bad) flash('warn', 'Nothing was pasted, so nothing was added.');
        return wizard_url($projectId, 4);
    }

    if ($action === 'generate') {
        $episode   = trim($_POST['gen_episode'] ?? '');
        $count     = (int)($_POST['gen_count'] ?? 0);
        $prefix    = trim($_POST['gen_prefix'] ?? 'SH');
        $start     = (int)($_POST['gen_start'] ?? 10);
        $increment = (int)($_POST['gen_increment'] ?? 10);
        $pad       = (int)($_POST['gen_pad'] ?? 3);

        if ($count < 1 || $count > 2000) throw new RuntimeException('Choose how many shots to generate (1–2000).');
        if ($increment < 1)              throw new RuntimeException('The increment must be at least 1.');
        if ($pad < 1 || $pad > 6)        $pad = 3;

        $ins = $pdo->prepare('INSERT INTO shots (project_id, episode, shot_code) VALUES (?, ?, ?)');
        $added = 0; $dupes = 0;
        for ($i = 0; $i < $count; $i++) {
            $code = $prefix . str_pad((string)($start + $i * $increment), $pad, '0', STR_PAD_LEFT);
            try {
                $ins->execute([$projectId, $episode, $code]);
                $added++;
            } catch (PDOException $e) {
                $dupes++;
            }
        }
        flash('ok', "Generated $added shot" . ($added === 1 ? '' : 's') .
                    ($episode !== '' ? " in $episode" : '') . '.');
        if ($dupes) flash('warn', "$dupes already existed and were skipped.");
        return wizard_url($projectId, 4);
    }

    if ($action === 'seconds') {
        $total = (float)($_POST['total_seconds'] ?? 0);
        if ($total <= 0) throw new RuntimeException('Enter the total running time in seconds.');
        $count = (int)$pdo->query("SELECT COUNT(*) FROM shots WHERE project_id = $projectId")->fetchColumn();
        if ($count === 0) throw new RuntimeException('Add some shots first.');
        $per = round($total / $count, 3);
        $pdo->prepare('UPDATE shots SET placeholder_seconds = ? WHERE project_id = ?')
            ->execute([$per, $projectId]);
        flash('ok', "Spread {$total}s evenly across $count shots — {$per}s each. " .
                    'Replace individual values later as real timings come in.');
        return wizard_url($projectId, 4);
    }

    return wizard_next_url($projectId, 4);
}

function wizard_render_shots(PDO $pdo, ?array $project): void {
    $projectId = (int)$project['id'];
    $stmt = $pdo->prepare(
        'SELECT * FROM shots WHERE project_id = ? ORDER BY episode, shot_code'
    );
    $stmt->execute([$projectId]);
    $shots = $stmt->fetchAll();

    $totalSeconds = 0.0;
    $byEpisode = [];
    foreach ($shots as $s) {
        $byEpisode[$s['episode']][] = $s;
        $totalSeconds += (float)$s['placeholder_seconds'];
    }
    ?>
    <p class="lead">The shot list. Paste one in, or generate numbered shots per episode.
       You can come back and add more at any time.</p>

    <?php if ($shots): ?>
      <p class="summary-line">
        <strong><?= count($shots) ?></strong> shots
        <?php if ($totalSeconds > 0): ?>
          · <strong><?= number_format($totalSeconds, 1) ?></strong> seconds total
        <?php endif; ?>
        <?php if (count($byEpisode) > 1): ?>
          · <?= count($byEpisode) ?> episodes
        <?php endif; ?>
      </p>

      <form method="post">
        <button type="submit" name="action" value="continue" hidden></button>
        <?php foreach ($byEpisode as $ep => $rows): ?>
          <div class="chip-group">
            <h4><?= $ep === '' ? '<span class="muted">No episode</span>' : e($ep) ?>
                <span class="muted small">· <?= count($rows) ?> shots</span></h4>
            <div class="chips">
            <?php foreach ($rows as $s): ?>
              <span class="chip">
                <?= e($s['shot_code']) ?>
                <?php if ($s['placeholder_seconds'] > 0): ?>
                  <span class="muted"><?= rtrim(rtrim(number_format((float)$s['placeholder_seconds'], 2), '0'), '.') ?>s</span>
                <?php endif; ?>
                <button type="submit" name="action" value="del:<?= (int)$s['id'] ?>" title="Remove">✕</button>
              </span>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </form>
    <?php else: ?>
      <p class="muted">No shots yet.</p>
    <?php endif; ?>

    <div class="tool-grid">
      <form method="post" class="tool">
        <input type="hidden" name="action" value="paste">
        <strong>Paste a list</strong>
        <textarea name="paste" rows="6" placeholder="EP001, SH010, 3.5&#10;EP001, SH020, 2.0&#10;EP002, SH010"></textarea>
        <small>One shot per line. <code>EPISODE, SHOT, SECONDS</code> — episode and seconds
               optional. A line with just a shot code uses the episode below.</small>
        <label>Episode for lines without one
          <input name="paste_episode" placeholder="EP001">
        </label>
        <button type="submit" class="secondary">Add these shots</button>
      </form>

      <form method="post" class="tool">
        <input type="hidden" name="action" value="generate">
        <strong>Generate numbered shots</strong>
        <div class="row">
          <label>Episode <input name="gen_episode" placeholder="EP001"></label>
          <label>How many <input type="number" name="gen_count" min="1" max="2000" value="20"></label>
        </div>
        <div class="row">
          <label>Prefix <input name="gen_prefix" value="SH"></label>
          <label>Start at <input type="number" name="gen_start" value="10"></label>
          <label>Step by <input type="number" name="gen_increment" value="10"></label>
          <label>Digits <input type="number" name="gen_pad" value="3" min="1" max="6"></label>
        </div>
        <small>Those defaults give SH010, SH020, SH030 …</small>
        <button type="submit" class="secondary">Generate</button>
      </form>

      <?php if ($shots): ?>
      <form method="post" class="tool">
        <input type="hidden" name="action" value="seconds">
        <strong>Spread running time evenly</strong>
        <label>Total seconds across all <?= count($shots) ?> shots
          <input type="number" name="total_seconds" step="0.1" min="0" placeholder="574">
        </label>
        <small>A placeholder until real timings exist — steps measured in seconds need it
               to work out a quota. Overwrites every shot's current value.</small>
        <button type="submit" class="secondary">Spread evenly</button>
      </form>
      <?php endif; ?>
    </div>

    <form method="post" class="actions">
      <button type="submit" name="action" value="continue">Save and continue →</button>
    </form>
    <?php
}
