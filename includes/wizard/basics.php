<?php
// Wizard step 1 — project basics. Creates the project row on first save.

// The type picks the ideal quotas sheet the plan runs on, so saving a changed
// one reports what moved, and the New project type panel is drawn from there.
require_once __DIR__ . '/../standards.php';

function wizard_save_basics(PDO $pdo, ?array $project): string {
    $name     = trim($_POST['name'] ?? '');
    $codeRaw  = trim($_POST['code'] ?? '');
    $type     = trim((string)($_POST['type'] ?? '3D'));
    $fps      = (int)($_POST['fps'] ?? 24);
    $start    = trim($_POST['start_date'] ?? '');
    $delivery = trim($_POST['delivery_date'] ?? '');

    // A code becomes part of file names and exports, so keep it plain.
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeRaw));

    if ($name === '')                       throw new RuntimeException('The project needs a name.');
    if (strlen($code) < 2)                  throw new RuntimeException('The project code needs at least 2 letters or numbers.');
    if (strlen($code) > 20)                 throw new RuntimeException('The project code is too long (20 characters maximum).');
    // ⚠ REFUSED, NOT REWRITTEN TO OTHER, which is what this line used to do. The
    // type picks the ideal quotas sheet, so a quiet rewrite moves the plan and
    // the budget onto numbers nobody chose. It only happens when a type is
    // removed while this page is open. The project's own type always passes,
    // listed or not, because the picker always offers it.
    if (!in_array($type, project_type_options($pdo, (string)($project['type'] ?? '')), true)) {
        throw new RuntimeException(sprintf('There is no project type called "%s" any more, so nothing '
                                         . 'was saved. Pick one from the list.', $type));
    }
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
        $id = (int)$project['id'];
        $wasType = (string)$project['type'];
        if ($wasType !== $type) {
            flash('ok', sprintf('Saved the project details, and moved it from %s to %s.', $wasType, $type));
            // What the move did to the plan, step by step. Said on the save that
            // did it, because nothing else on this screen shows the plan.
            foreach (project_type_change_report($pdo, $id, $wasType, $type) as [$kind, $msg]) {
                flash($kind, $msg);
            }
        } else {
            flash('ok', 'Saved the project details.');
        }
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

    // Retake allowance — capped at 200% because beyond that it is almost
    // certainly a typo, and a silent 1000% would quietly triple the crew.
    if (isset($_POST['retake_pct'])) {
        $r = trim($_POST['retake_pct']);
        set_project_setting($pdo, $id, 'retake_pct',
            (string)min(200, max(0, $r === '' ? 0 : (float)$r)));
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
    $retakePct = $project ? project_retake_pct($pdo, (int)$project['id']) : 0.0;
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

      <?php
      // A type created from the panel below comes back PICKED and not saved, and
      // says so: a picker showing a type the project does not have yet looks
      // exactly like one showing the type it has.
      $typeOptions = project_type_options($pdo, (string)($project['type'] ?? ''));
      $picked = (string)($_GET['pick_type'] ?? '');
      if (!$project || !in_array($picked, $typeOptions, true)) $picked = '';
      $shownType = $picked !== '' ? $picked : (string)$p['type'];
      // A new project defaults to 3D; if 3D has been removed, the first type.
      if (!in_array($shownType, $typeOptions, true)) $shownType = (string)($typeOptions[0] ?? '');
      $canAddType = $project && is_admin() && project_types_available($pdo);
      ?>
      <label>Type
        <select name="type">
          <?php foreach ($typeOptions as $t): ?>
            <option value="<?= e($t) ?>" <?= $shownType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($picked !== '' && $picked !== (string)$project['type']): ?>
          <small class="warn-text"><strong><?= e($picked) ?> is picked, not saved.</strong> This project
            stays <?= e((string)$project['type']) ?> until you save.</small>
        <?php endif; ?>
        <small>Decides which ideal quotas sheet every step plans on, and which pipeline template is
          offered next. Changing it later can move the dates and the budget, and saving says which
          steps moved.</small>
      </label>
      <?php if ($canAddType): ?>
        <?php project_type_new_form($pdo, (string)$project['type'],
                                    ['return' => 'basics:' . (int)$project['id']], 'new-type-form'); ?>
      <?php endif; ?>

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
          At the start of a project the runtime is usually known long before the shot count is,
          that only settles once the animatic is cut and labelled. Put what you know here and
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

      <fieldset class="unit-choice">
        <legend>Expected retakes</legend>
        <p class="muted small" style="margin-top:0">
          Work that comes back and is done again. It doesn't change how much there is to
          make, 22 assets are still 22 assets, it changes how long each one takes, so it
          scales the days per item and therefore the crew a deadline needs.
          Mana's projections have used <strong>10%</strong>.
        </p>
        <div class="row">
          <label>Retake allowance
            <span class="pct-field">
              <input type="number" name="retake_pct" step="any" min="0" max="200"
                     value="<?= e(fmt_num($retakePct)) ?>" placeholder="10">%
            </span>
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
    <?php if ($canAddType): ?>
      <?php // What the New project type panel beside Type posts through. Empty on
            // purpose: forms cannot nest, so its controls sit inside the form above
            // and name this one with the form attribute. See project_type_new_form(). ?>
      <form id="new-type-form" method="post" action="standards.php"></form>
    <?php endif; ?>
    <?php
}
