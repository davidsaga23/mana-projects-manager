<?php
// The tracking grid — items × pipeline steps, one status per cell.
//
// Ported from STFC's preprod.php, the screen that answered one question at a
// glance: is this asset finished, so the next department can pick it up? There,
// a row of green checkboxes said yes. Here a cell carries a STATUS instead —
// docs/DECISIONS.md, the three 2026-08-14 tracking-grid entries, says why a
// checkbox cannot port unchanged — but GREEN MEANS WHAT IT MEANT THERE:
// maps_to = 'approved', which on STFC was Plaky's "Completed" or "Final".
//
// What this screen adds over STFC's:
//   * a colour legend, because eleven colours need a key where a tick did not
//   * shots as well as assets, driven by applies_to
//   * a donut per group — status spread around it, "ready" count in the middle,
//     drawn only for groups that exist
//   * clicking a donut segment lists exactly which items report that status
//
// No migration: everything here reads `progress` and `pipeline_steps`, which the
// importer has been filling since Phase 3.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/grid.php';
require_once __DIR__ . '/includes/crew.php';   // ambiguous_step_names(), step_scope_tag()
require_login();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'production.php?project=' . $projectId;

// THE THREE PHASES, named as the studio names them (David, 2026-08-15).
//
// Underneath they are the three item scopes the schema already has, which is why
// this needed no new data: assets are what gets built before shots can be made,
// shots are the show itself, and a whole-project step is a deliverable for the
// finished film — an animatic, a music track, the mix, the master.
//
// The scope names stay 'asset' / 'shot' / 'project' everywhere in the code. Only
// the LABELS are the studio's. A step's `department` column (migration 015) is
// the more precise long-term answer — see ROADMAP — but it is free text, blank on
// every existing project, and driving these tabs from it today would show three
// empty screens.
$phases = [
    'asset'   => ['label' => 'Pre-production', 'noun' => 'asset',
                  'blurb' => 'Characters, environments, props — everything built before a shot can be made.'],
    'shot'    => ['label' => 'Production',     'noun' => 'shot',
                  'blurb' => 'The shots themselves, through the pipeline.'],
    'project' => ['label' => 'Post-production','noun' => 'deliverable',
                  'blurb' => 'Whole-show deliverables — SFX, music, the mix, the master. One row, because each of these is one piece of work for the whole film rather than one per shot.'],
];
$scope = $_GET['scope'] ?? 'asset';
if (!isset($phases[$scope])) $scope = 'asset';
$phase = $phases[$scope];

$grid     = build_grid($pdo, $projectId, $scope);
$statuses = $grid['statuses'];
$episodic = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';

// How much each phase has to show, so a tab can say so rather than opening an
// empty screen with no explanation.
$counts = [];
foreach (['shot' => 'shots', 'asset' => 'assets'] as $k => $table) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE project_id = ?");
    $q->execute([$projectId]);
    $counts[$k] = (int)$q->fetchColumn();
}
// Post-production is counted in STEPS, not items: there is always exactly one
// "show", so the useful number is how many whole-show deliverables exist.
$q = $pdo->prepare("SELECT COUNT(*) FROM pipeline_steps WHERE project_id = ? AND applies_to = 'project'");
$q->execute([$projectId]);
$counts['project'] = (int)$q->fetchColumn();

// Two steps can share a name and differ only in scope — "Concept (Characters)"
// and "Concept (Environments)". Only then is the tag worth the clutter.
$ambiguous = ambiguous_step_names($grid['steps']);

$readyTotal = 0;
foreach ($grid['rows'] as $r) if ($r['ready']) $readyTotal++;

$pageTitle = 'Production tracking · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'production'); ?>

<div class="board-head">
  <h2 class="wizard-title" style="margin:0;">
    Production tracking <span class="muted">· <?= e($project['name']) ?></span>
  </h2>
  <div class="board-tools">
    <?php foreach ($phases as $key => $ph): ?>
      <a class="button<?= $scope === $key ? '' : ' secondary-link' ?>"
         href="<?= e($selfUrl) ?>&amp;scope=<?= e($key) ?>"><?= e($ph['label']) ?> (<?= (int)$counts[$key] ?>)</a>
    <?php endforeach; ?>
  </div>
</div>

<p class="lead">
  <?= e($phase['blurb']) ?>
  <strong>Green means approved</strong> — finished, and ready for the next department to pick up.
  A dash means the step doesn't apply. Click any cell to set its status.
</p>

<?php if (!$grid['steps'] || !$grid['rows']): ?>
  <div class="empty-state">
    <?php if (!$grid['rows']): ?>
      <p><strong>This project has no <?= e($phase['noun']) ?>s yet.</strong></p>
      <p class="muted">Add them on the Setup screen, or bring them in from Plaky on the Import screen.</p>
      <p><a class="button" href="wizard.php?project=<?= $projectId ?>&amp;step=<?= $scope === 'shot' ? 4 : 5 ?>">Open Setup →</a></p>
    <?php else: ?>
      <p><strong>No pipeline step belongs to <?= e($phase['label']) ?> on this project.</strong></p>
      <p class="muted">
        <?php if ($scope === 'project'): ?>
          A whole-show deliverable is a step whose scope is <em>the project</em> rather than shots
          or assets — an animatic, a music track, the mix, the final master. Set that on the
          Setup screen and it will appear here.
        <?php else: ?>
          A step's scope is set on the Setup screen — applies to shots, to assets, or to one kind
          of asset. Nothing here is broken; there is simply nothing to draw.
        <?php endif; ?>
      </p>
      <p><a class="button" href="wizard.php?project=<?= $projectId ?>&amp;step=2">Open the pipeline →</a></p>
    <?php endif; ?>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<section class="panel">
  <div class="board-head" style="margin:0 0 10px;">
    <h3 style="margin:0;">Overall</h3>
    <span class="muted small"><?= $grid['done_cells'] ?> / <?= $grid['total_cells'] ?> cells approved</span>
  </div>
  <div class="bar-outer"><div class="bar-inner" style="width:<?= number_format($grid['pct'], 1) ?>%"></div></div>
  <p class="muted small" style="margin:6px 0 0;">
    <?= number_format($grid['pct'], 1) ?>% across <?= count($grid['rows']) ?>
    <?= e($phase['noun']) ?><?= count($grid['rows']) === 1 ? '' : 's' ?> and <?= count($grid['steps']) ?> steps ·
    <strong><?= $readyTotal ?></strong> fully ready for production
  </p>

  <div class="step-counters">
    <?php foreach ($grid['steps'] as $st):
      $t = $grid['per_step'][(int)$st['id']]['total'] ?? 0;
      $d = $grid['per_step'][(int)$st['id']]['done'] ?? 0;
      $pct = $t > 0 ? ($d / $t) * 100 : 0;
      $cls = $t === 0 ? 'muted' : ($pct >= 100 ? 'ok' : ($pct >= 60 ? '' : ($pct > 0 ? 'warn-text' : 'err')));
    ?>
      <div>
        <div class="counter-label"><?= e($st['name']) ?></div>
        <div class="counter-value <?= $cls ?>"><?= $d ?><span class="counter-of">/<?= $t ?></span></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php // A donut per group, ONLY for groups that exist — an empty "Vehicles: 0"
      // chart implies a gap that isn't there. ?>
<section class="panel">
  <h3 style="margin-top:0;">By <?= $scope === 'shot' ? ($episodic ? 'episode' : 'group') : ($scope === 'project' ? 'deliverable' : 'asset type') ?></h3>
  <p class="muted small">The ring is how the work is spread; the middle is how many are finished.
     <strong>Click a segment</strong> to see exactly which ones report that status.</p>
  <div class="donut-row">
    <?php foreach ($grid['per_group'] as $group => $g):
      $slices = grid_slices($grid, $group); ?>
      <figure class="donut-card" data-group="<?= e($group) ?>">
        <?= grid_donut_svg($slices, (int)$g['ready'], (int)$g['items']) ?>
        <figcaption>
          <strong><?= e($group) ?></strong>
          <span class="muted small"><?= (int)$g['items'] ?>
            <?= e($phase['noun']) ?><?= (int)$g['items'] === 1 ? '' : 's' ?>
            · <?= (int)$g['done'] ?>/<?= (int)$g['total'] ?> cells</span>
        </figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
</section>

<?php // The legend sits HERE, immediately above the grid it explains, rather than
      // at the top of the page (moved 2026-08-15). A key three panels away from
      // the thing it decodes is a key nobody uses. A tick needed none; a palette
      // does — and it is built from the project's own statuses, so it can never
      // drift from what the cells show. ?>
<section class="panel legend-panel">
  <strong class="legend-title">How to read a cell</strong>
  <div class="legend">
    <?php foreach ($statuses as $s): ?>
      <span class="legend-item">
        <span class="cell-chip" style="background:<?= e($s['color'] ?: '#8a97a3') ?>"></span>
        <?= e($s['name']) ?>
        <?php if (($s['maps_to'] ?? '') === 'approved'): ?><span class="legend-tag">ready</span><?php endif; ?>
        <?php if ((int)($s['is_blocked'] ?? 0) === 1): ?><span class="legend-tag warn">blocked</span><?php endif; ?>
        <?php if ((int)($s['is_excluded'] ?? 0) === 1): ?><span class="legend-tag muted-tag">n/a</span><?php endif; ?>
      </span>
    <?php endforeach; ?>
    <span class="legend-item">
      <span class="cell-chip cell-none"></span> Nothing recorded yet
    </span>
    <span class="legend-item">
      <span class="cell-dash">—</span> Step doesn't apply
    </span>
  </div>
</section>

<section class="panel">
  <div class="board-head" style="margin:0 0 10px;">
    <h3 style="margin:0;"><?= e($phase['label']) ?> tracking</h3>
    <label class="inline muted small">Show
      <select id="groupFilter">
        <option value="all">Everything</option>
        <?php foreach ($grid['per_group'] as $group => $g): ?>
          <option value="<?= e($group) ?>"><?= e($group) ?> (<?= (int)$g['ready'] ?>/<?= (int)$g['items'] ?> ready)</option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="grid-scroll">
    <table class="track-table">
      <thead>
        <tr>
          <th><?= e(ucfirst($phase['noun'])) ?></th>
          <?php foreach ($grid['steps'] as $st):
            // "Concept" appearing twice — once for characters, once for
            // environments — is two different jobs under one word. Show the scope
            // underneath, but only where the name really is repeated: tagging
            // every column would bury the ones that matter.
            $repeated = isset($ambiguous[mb_strtolower(trim($st['name']))]);
          ?>
            <th class="track-step" title="<?= e(applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) ?>">
              <?= e($st['name']) ?>
              <?php if ($repeated): ?>
                <span class="step-scope"><?= e(step_scope_tag($st)) ?></span>
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th class="track-done">Done</th>
        </tr>
      </thead>
      <tbody>
      <?php $lastGroup = null; foreach ($grid['rows'] as $row): ?>
        <?php if ($row['group'] !== $lastGroup): $lastGroup = $row['group']; $g = $grid['per_group'][$lastGroup]; ?>
          <tr class="track-group" data-group="<?= e($lastGroup) ?>">
            <td colspan="<?= count($grid['steps']) + 2 ?>">
              <span class="group-badge"><?= e(strtoupper($lastGroup)) ?></span>
              <span class="muted small"><?= (int)$g['items'] ?> ·
                <?= (int)$g['done'] ?>/<?= (int)$g['total'] ?> cells approved ·
                <strong><?= (int)$g['ready'] ?> ready</strong></span>
            </td>
          </tr>
        <?php endif; ?>
        <tr class="track-row <?= $row['ready'] ? 'is-ready' : '' ?>" data-group="<?= e($row['group']) ?>">
          <td class="track-name">
            <?= e($row['name']) ?>
            <?php if ($row['ready']): ?><span class="ready-tag" title="Every step that applies is approved">ready</span><?php endif; ?>
          </td>
          <?php foreach ($grid['steps'] as $st): $cell = $row['cells'][(int)$st['id']]; ?>
            <td class="track-cell">
              <?php if ($cell['kind'] === 'n/a'): ?>
                <span class="cell-dash" title="<?= $cell['reason'] === 'excluded'
                      ? 'Marked not applicable for this item'
                      : e(applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) . ' — this step does not cover it' ?>">—</span>
              <?php else:
                $s = $cell['status'];
                $title = ($s['name'] ?? 'Nothing recorded')
                       . ($cell['who'] ? ' · ' . $cell['who'] : '')
                       . ($cell['raw'] && !$s ? ' · imported as "' . $cell['raw'] . '"' : '');
              ?>
                <button type="button"
                        class="cell-chip <?= $s ? '' : 'cell-none' ?> <?= can_edit() ? 'is-editable' : '' ?>"
                        <?= $s ? 'style="background:' . e($s['color'] ?: '#8a97a3') . '"' : '' ?>
                        data-item="<?= e($row['item_key']) ?>"
                        data-step="<?= (int)$st['id'] ?>"
                        data-status="<?= $s ? (int)$s['id'] : '' ?>"
                        title="<?= e($title) ?>"
                        <?= can_edit() ? '' : 'disabled' ?>></button>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="track-done muted"><?= $row['done'] ?>/<?= $row['total'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<p class="muted small">
  <strong>Plaky is still the source of truth for status.</strong> A status set here is overwritten
  by the next import that covers the same cell — the same caveat STFC's screen carried. It is a
  way to correct or nudge something between uploads, not a second place to record work.
  <a href="import.php?project=<?= $projectId ?>">Import screen →</a>
</p>

<?php // The dropdown, one instance moved to whichever cell was clicked. ?>
<div id="statusMenu" class="status-menu" hidden>
  <?php foreach ($statuses as $s): ?>
    <button type="button" class="status-opt" data-status="<?= (int)$s['id'] ?>">
      <span class="cell-chip" style="background:<?= e($s['color'] ?: '#8a97a3') ?>"></span>
      <?= e($s['name']) ?>
      <?php if (($s['maps_to'] ?? '') === 'approved'): ?><span class="legend-tag">ready</span><?php endif; ?>
    </button>
  <?php endforeach; ?>
  <button type="button" class="status-opt" data-status="">
    <span class="cell-chip cell-none"></span> Clear — nothing recorded
  </button>
</div>

<div id="sliceModal" class="modal" hidden>
  <div class="modal-box">
    <h3 id="sliceTitle">Items</h3>
    <div id="sliceBody" class="slice-list"></div>
    <div class="actions">
      <button type="button" class="secondary" onclick="document.getElementById('sliceModal').hidden = true">Close</button>
    </div>
  </div>
</div>

<script>
window.GRID_BY_STATUS = <?= json_encode($grid['by_status'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.GRID_STATUS_NAMES = <?= json_encode(array_map(fn($s) => $s['name'], $statuses),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.GRID_PROJECT = <?= (int)$projectId ?>;
window.GRID_CAN_EDIT = <?= can_edit() ? 'true' : 'false' ?>;
</script>
<script src="assets/production.js<?= asset_ver('production.js') ?>"></script>
</div>
</body></html>
