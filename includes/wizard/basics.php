<?php
// Wizard step 1 — project basics. Creates the project row on first save.

function wizard_save_basics(PDO $pdo, ?array $project): string {
    $name     = trim($_POST['name'] ?? '');
    $codeRaw  = trim($_POST['code'] ?? '');
    $type     = $_POST['type'] ?? '3D';
    $fps      = (int)($_POST['fps'] ?? 24);
    $start    = trim($_POST['start_date'] ?? '');
    $delivery = trim($_POST['delivery_date'] ?? '');

    // A code becomes part of file names and exports, so keep it plain.
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeRaw));

    if ($name === '')                       throw new RuntimeException('The project needs a name.');
    if (strlen($code) < 2)                  throw new RuntimeException('The project code needs at least 2 letters or numbers.');
    if (strlen($code) > 20)                 throw new RuntimeException('The project code is too long (20 characters maximum).');
    if (!in_array($type, ['2D','3D','Mixed','Other'], true)) $type = 'Other';
    if ($fps < 1 || $fps > 120)             throw new RuntimeException('Frames per second should be between 1 and 120.');
    if ($start !== '' && $delivery !== '' && $delivery < $start) {
        throw new RuntimeException('The delivery date is before the start date.');
    }

    $dup = $pdo->prepare('SELECT id FROM projects WHERE code = ? AND id <> ?');
    $dup->execute([$code, $project['id'] ?? 0]);
    if ($dup->fetch()) {
        throw new RuntimeException('Another project already uses the code "' . $code . '". Codes must be unique.');
    }

    if ($project) {
        $stmt = $pdo->prepare(
            'UPDATE projects SET name = ?, code = ?, type = ?, fps = ?, start_date = ?, delivery_date = ?
              WHERE id = ?'
        );
        $stmt->execute([$name, $code, $type, $fps, $start ?: null, $delivery ?: null, $project['id']]);
        flash('ok', 'Saved the project details.');
        $id = (int)$project['id'];
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO projects (code, name, type, status, fps, start_date, delivery_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$code, $name, $type, 'setup', $fps, $start ?: null, $delivery ?: null]);
        $id = (int)$pdo->lastInsertId();
        // Every project owns its own copy of the statuses, editable from the
        // project screen. Copied, not referenced — same as pipeline templates.
        seed_project_statuses($pdo, $id);
        flash('ok', 'Created "' . $name . '". Next, choose the pipeline steps.');
    }

    // Early estimates, used by the quota only until a real breakdown exists.
    foreach (['estimated_seconds', 'estimated_shots', 'estimated_assets'] as $key) {
        $v = trim($_POST[$key] ?? '');
        set_project_setting($pdo, $id, $key, $v === '' ? '0' : (string)max(0, (float)$v));
    }

    if ($codeRaw !== $code) {
        flash('warn', 'The project code was tidied up to "' . $code . '" (letters and numbers only).');
    }
    return wizard_next_url($id, 1);
}

function wizard_render_basics(PDO $pdo, ?array $project): void {
    $p = $project ?: ['name'=>'', 'code'=>'', 'type'=>'3D', 'fps'=>24, 'start_date'=>'', 'delivery_date'=>''];
    $est = $project ? project_estimates($pdo, (int)$project['id'])
                    : ['seconds'=>0, 'shots'=>0, 'assets'=>0];
    ?>
    <p class="lead">The essentials. Everything here can be changed later.</p>
    <form method="post" class="stack">
      <label>Project name
        <input name="name" value="<?= e($p['name']) ?>" required autofocus
               placeholder="Star Trek Fleet Command S02">
      </label>

      <label>Project code
        <input name="code" value="<?= e($p['code']) ?>" required maxlength="20"
               placeholder="STFC2">
        <small>A short tag used in exports and file names. Letters and numbers only.</small>
      </label>

      <label>Type
        <select name="type">
          <?php foreach (['2D','3D','Mixed','Other'] as $t): ?>
            <option value="<?= $t ?>" <?= $p['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
        <small>Decides which pipeline template is offered next. It doesn't lock anything in.</small>
      </label>

      <div class="row">
        <label>Start date
          <input type="date" name="start_date" value="<?= e($p['start_date']) ?>">
        </label>
        <label>Delivery date
          <input type="date" name="delivery_date" value="<?= e($p['delivery_date']) ?>">
        </label>
        <label>Frames per second
          <input type="number" name="fps" value="<?= (int)$p['fps'] ?>" min="1" max="120">
        </label>
      </div>

      <fieldset class="unit-choice">
        <legend>Pre-production estimate</legend>
        <p class="muted small" style="margin-top:0">
          At the start of a project the runtime is usually known long before the shot count is
          — that only settles once the animatic is cut and labelled. Put what you know here and
          the review screen can still tell you how many people the deadline needs.
          <strong>These are used only until real shots or assets exist</strong>, then they're
          ignored in favour of the actual counts.
        </p>
        <div class="row">
          <label>Total runtime (seconds)
            <input type="number" name="estimated_seconds" step="any" min="0"
                   value="<?= e(fmt_num($est['seconds'] ?? 0)) ?>" placeholder="574">
          </label>
          <label>Roughly how many shots
            <input type="number" name="estimated_shots" step="any" min="0"
                   value="<?= e(fmt_num($est['shots'] ?? 0)) ?>" placeholder="188">
          </label>
          <label>Roughly how many assets
            <input type="number" name="estimated_assets" step="any" min="0"
                   value="<?= e(fmt_num($est['assets'] ?? 0)) ?>" placeholder="55">
          </label>
        </div>
      </fieldset>

      <div class="actions">
        <?php if ($project): ?>
          <button type="submit" name="stay" value="1" class="secondary">Save and stay here</button>
          <span class="hint">keeps your changes, leaves you on this screen</span>
          <span class="spacer"></span>
        <?php endif; ?>
        <button type="submit">Save and continue →</button>
      </div>
    </form>
    <?php
}
