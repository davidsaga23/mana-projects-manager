<?php
// The base sheet — ideal quotas, one sheet per kind of project.
//
// STUDIO-WIDE, not per project. That is the point of it: a 3D series and a 2D
// short are different work, but every 3D series is the same work, and what
// Character Concept actually takes is a fact about the studio rather than about
// one show. Same reasoning as step_library and the department vocabulary being
// global.
//
// ⚠ THIS IS NOT THE REQUIRED QUOTA. The dashboard's "required per day" is
// derived from the deadline and moves whenever the date or the progress moves;
// nobody types it. This is what the work SHOULD take, typed from experience, and
// the difference between the two is the thing worth looking at — see
// includes/standards.php for all three numbers side by side.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/standards.php';
require_login();

$ready = standards_available($pdo);
$types = standard_project_types($pdo);

$type = (string)($_GET['type'] ?? $_POST['project_type'] ?? '');
if (!in_array($type, $types, true)) $type = $types[0] ?? '3D';

$selfUrl = 'standards.php?type=' . urlencode($type);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$ready) {
            throw new RuntimeException(
                'The database updates this screen needs have not been applied yet (migration 026), '
                . 'so nothing was saved.'
            );
        }
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $r = save_standards($pdo, $type, $rows, current_user_display());

        if ($r['saved'])   flash('ok', sprintf('Saved %d standard%s for %s projects.',
                                 $r['saved'], $r['saved'] === 1 ? '' : 's', $type));
        if ($r['removed']) flash('warn', sprintf('%d standard%s cleared — a blank removes it rather '
                                 . 'than storing zero days, which nothing could plan against.',
                                 $r['removed'], $r['removed'] === 1 ? ' was' : 's were'));
        foreach ($r['errors'] as $e) flash('err', $e);
        if (!$r['saved'] && !$r['removed'] && !$r['errors']) {
            flash('warn', 'Nothing had a step name in it, so nothing was saved.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$standards = $ready ? standards_list($pdo, $type) : [];

// Step identities already in use on projects of THIS type that have no standard
// yet — the list of rows worth filling in next. Built from real pipelines rather
// than from a fixed list, exactly as every other vocabulary here is.
$missing = [];
if ($ready) {
    $have = [];
    foreach ($standards as $s) {
        $have[standard_key($s['step_name'], $s['applies_to'], $s['applies_to_asset_type'])] = true;
    }
    $stmt = $pdo->prepare(
        'SELECT ps.name, ps.applies_to, COALESCE(ps.applies_to_asset_type, \'\') AS asset_type,
                COUNT(DISTINCT ps.project_id) AS projects,
                ROUND(AVG(NULLIF(ps.days_per_unit, 0)), 2) AS avg_days
           FROM pipeline_steps ps
           JOIN projects p ON p.id = ps.project_id
          WHERE p.type = ?
          GROUP BY ps.name, ps.applies_to, COALESCE(ps.applies_to_asset_type, \'\')
          ORDER BY ps.name'
    );
    $stmt->execute([$type]);
    foreach ($stmt as $r) {
        if (!isset($have[standard_key($r['name'], $r['applies_to'], $r['asset_type'])])) {
            $missing[] = $r;
        }
    }
}

$pageTitle = 'Ideal quotas · ' . $type;
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Ideal quotas <span class="muted">· base sheet for <?= e($type) ?> projects</span></h2>

<p class="lead">
  What the work <strong>should</strong> take when it is done properly — one sheet per kind of
  project, written from experience and true across shows until experience changes it.
</p>

<div class="flash flash-warn">
  <strong>This is not the quota on the dashboard.</strong> That one is the <em>minimum</em>: it is
  derived from what is left to do and how many working days remain, so it rises every time a
  deadline gets closer or work slips, and nobody types it. This is the opposite kind of number —
  what a step actually takes, independent of any deadline. Their disagreement is the useful part:
  a required pace far above the ideal is not an ambitious plan, it is one that cannot be delivered
  by the people on it.
</div>

<?php if (!$ready): ?>
  <div class="flash flash-err">
    This screen needs database updates that haven't been applied (migration <strong>026</strong>).
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <p class="page-actions">
    <?php foreach ($types as $t): ?>
      <a class="proj-tab <?= $t === $type ? 'current' : '' ?>"
         href="standards.php?type=<?= urlencode($t) ?>"><?= e($t) ?></a>
    <?php endforeach; ?>
  </p>

  <?php if (can_edit()): ?>
  <form method="post">
    <input type="hidden" name="project_type" value="<?= e($type) ?>">
    <div class="grid-scroll">
      <table class="grid-table">
        <thead>
          <tr>
            <th>Step</th><th>Covers</th><th>Asset type</th>
            <th>Days per item</th><th>Which is</th>
            <th>Typical headcount</th><th>Where the number came from</th><th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php
        // Existing standards first, then a row per step identity used on this
        // type of project that has none yet, then five blanks. The middle group
        // is the useful one: it is the studio saying "you run this step and have
        // never written down what it takes".
        $r = 0;
        foreach ($standards as $s): $n = 'rows[' . $r++ . ']'; ?>
          <tr>
            <td><input name="<?= $n ?>[step_name]" value="<?= e($s['step_name']) ?>" size="18"></td>
            <td>
              <select name="<?= $n ?>[applies_to]">
                <?php foreach (['shot' => 'shots', 'asset' => 'assets', 'both' => 'both',
                                'project' => 'whole project'] as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= $s['applies_to'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input name="<?= $n ?>[applies_to_asset_type]"
                       value="<?= e($s['applies_to_asset_type']) ?>" size="12"
                       placeholder="any"></td>
            <td><input name="<?= $n ?>[days_per_unit]" inputmode="decimal" size="6"
                       value="<?= e(fmt_num((float)$s['days_per_unit'], 2)) ?>"></td>
            <td class="muted small"><?= e(fmt_num(1 / max(0.001, (float)$s['days_per_unit']), 2)) ?> a day</td>
            <td><input name="<?= $n ?>[typical_headcount]" inputmode="decimal" size="5"
                       value="<?= $s['typical_headcount'] !== null ? e(fmt_num((float)$s['typical_headcount'], 2)) : '' ?>"></td>
            <td><input name="<?= $n ?>[source]" value="<?= e($s['source'] ?? '') ?>" size="24"
                       placeholder="e.g. measured on RTC + two shorts"></td>
            <td><input name="<?= $n ?>[notes]" value="<?= e($s['notes'] ?? '') ?>" size="22"></td>
          </tr>
        <?php endforeach; ?>

        <?php foreach ($missing as $m): $n = 'rows[' . $r++ . ']'; ?>
          <tr class="has-problem">
            <td><input name="<?= $n ?>[step_name]" value="<?= e($m['name']) ?>" size="18"></td>
            <td>
              <select name="<?= $n ?>[applies_to]">
                <?php foreach (['shot' => 'shots', 'asset' => 'assets', 'both' => 'both',
                                'project' => 'whole project'] as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= $m['applies_to'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input name="<?= $n ?>[applies_to_asset_type]"
                       value="<?= e($m['asset_type']) ?>" size="12"></td>
            <td><input name="<?= $n ?>[days_per_unit]" inputmode="decimal" size="6" placeholder=""></td>
            <td class="muted small">
              planned at <?= e(fmt_num((float)$m['avg_days'], 2)) ?> on
              <?= (int)$m['projects'] ?> project<?= (int)$m['projects'] === 1 ? '' : 's' ?>
            </td>
            <td><input name="<?= $n ?>[typical_headcount]" inputmode="decimal" size="5"></td>
            <td><input name="<?= $n ?>[source]" size="24" placeholder="no standard yet"></td>
            <td><input name="<?= $n ?>[notes]" size="22"></td>
          </tr>
        <?php endforeach; ?>

        <?php for ($k = 0; $k < 5; $k++): $n = 'rows[' . $r++ . ']'; ?>
          <tr>
            <td><input name="<?= $n ?>[step_name]" size="18" placeholder="A step"></td>
            <td>
              <select name="<?= $n ?>[applies_to]">
                <option value="shot">shots</option>
                <option value="asset">assets</option>
                <option value="both">both</option>
                <option value="project">whole project</option>
              </select>
            </td>
            <td><input name="<?= $n ?>[applies_to_asset_type]" size="12" placeholder="any"></td>
            <td><input name="<?= $n ?>[days_per_unit]" inputmode="decimal" size="6"></td>
            <td class="muted small">—</td>
            <td><input name="<?= $n ?>[typical_headcount]" inputmode="decimal" size="5"></td>
            <td><input name="<?= $n ?>[source]" size="24"></td>
            <td><input name="<?= $n ?>[notes]" size="22"></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <div class="actions"><button type="submit">Save the <?= e($type) ?> sheet</button></div>
    <p class="muted small">
      <strong>Days per item</strong> is the number; the column beside it shows the same fact the
      other way round, because "half a day each" and "two a day" are the same standard and people
      say whichever suits the step.
      <strong>Clearing the days removes that standard</strong> rather than storing zero — zero days
      per item is not a slow standard, it is a step that takes no time, and nothing could plan
      against it.
      <strong>Where the number came from</strong> earns its place: a figure somebody guessed and a
      figure measured across four finished shows are both worth having and must never be mistaken
      for each other.
    </p>
  </form>
  <?php else: ?>
    <table class="grid-table">
      <thead><tr><th>Step</th><th>Covers</th><th>Ideal</th><th>Typical headcount</th><th>Source</th></tr></thead>
      <tbody>
      <?php foreach ($standards as $s): ?>
        <tr>
          <td><?= e($s['step_name']) ?></td>
          <td class="muted small"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?: null)) ?></td>
          <td><?= e(fmt_standard((float)$s['days_per_unit'])) ?></td>
          <td class="muted small"><?= $s['typical_headcount'] !== null ? e(fmt_num((float)$s['typical_headcount'], 2)) : '—' ?></td>
          <td class="muted small"><?= e($s['source'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($missing && can_edit()): ?>
    <p class="muted small warn-text">
      <?= count($missing) ?> step identit<?= count($missing) === 1 ? 'y is' : 'ies are' ?>
      already in use on <?= e($type) ?> projects with no standard recorded — highlighted above,
      showing what they have actually been planned at so far. That average is a starting point to
      argue from, not a measurement: it is what somebody estimated, not what the work took.
    </p>
  <?php endif; ?>

  <p class="muted small">
    <strong>Later, this becomes measured rather than believed.</strong> The cross-project benchmark
    designed in <code>docs/ROADMAP.md</code> compares estimate against actual on exactly the same
    step identity used here, so when it exists it can propose an observed median for each row —
    written into <em>where the number came from</em>, never applied silently. Until then these are
    the studio's stated standards, which is already more than lived in anyone's head before.
  </p>

<?php endif; ?>

</div>
</body></html>
