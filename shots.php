<?php
// The shot breakdown — what appears in each shot, and how long each shot is.
//
// David, 2026-09-02, with a screenshot of the STFC2 dashboard: rows of shots,
// a column per kind of asset, chips you add and remove.
//
// ⚠ THE COLUMNS ARE GENERATED, not listed. STFC hardcoded characters and
// environments and needed a schema change when Props arrived; here the columns
// come from whatever asset types the project has, so "vehicles, props, graphics,
// or any other asset" arrives by typing it on an asset, with no code touched.
//
// See includes/breakdown.php for why this matters beyond the call sheet: the
// scheduler has always read shot_assets and nothing has ever written it, so
// every shot has been waiting for the entire asset step instead of the assets it
// actually uses.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/breakdown.php';
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

$episodes = breakdown_episodes($pdo, $projectId);
$episode  = (string)($_GET['episode'] ?? '');
if ($episode !== '' && !in_array($episode, $episodes, true)) $episode = '';

$types    = breakdown_asset_types($pdo, $projectId);
$byType   = breakdown_assets($pdo, $projectId);
$rows     = breakdown_rows($pdo, $projectId, $episode);
$progress = breakdown_progress($pdo, $projectId);

// Only the columns worth drawing. A project with one asset type gets one column
// rather than a row of empty ones, and a project with no assets at all gets the
// message below instead of an empty grid.
$hasAssets = !empty($byType);

$pageTitle = $project['name'] . ' · Shots';
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'shots'); ?>

<h2 class="wizard-title">Shots
  <span class="muted">what appears in each one</span>
</h2>

<?php if (!$hasAssets): ?>
  <div class="flash flash-warn">
    <p><strong>This project has no assets yet.</strong></p>
    <p class="small">Characters, environments, props and the rest are added on the
      <a href="wizard.php?project=<?= $projectId ?>&amp;step=3">Setup</a> screen or through
      <a href="import.php?project=<?= $projectId ?>">Import</a>. Once they exist, this screen
      grows a column for each kind.</p>
  </div>
<?php elseif (!$rows): ?>
  <p class="muted">No shots<?= $episode !== '' ? ' in that episode' : ' yet' ?>.</p>
<?php else: ?>

<form method="get" class="inline-form filter-bar">
  <input type="hidden" name="project" value="<?= $projectId ?>">
  <?php if (count($episodes) > 1): ?>
    <label class="inline">Episode
      <select name="episode" onchange="this.form.submit()">
        <option value="">All episodes</option>
        <?php foreach ($episodes as $ep): if ($ep === '') continue; ?>
          <option value="<?= e($ep) ?>" <?= $episode === $ep ? 'selected' : '' ?>><?= e($ep) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <a class="button secondary" href="export_breakdown.php?project=<?= $projectId ?><?php
     if ($episode !== '') echo '&amp;episode=' . urlencode($episode); ?>">Export breakdown ↓</a>
</form>

<?php // WHY THIS SCREEN CHANGES THE PLAN, said once and only while it is true.
      // A producer filling this in should know it shortens the schedule rather
      // than just tidying a document — and that the saved schedule will not
      // catch up on its own. ?>
<?php if ($progress['empty'] > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $progress['empty'] ?></strong> of <?= $progress['total'] ?>
    shot<?= $progress['total'] === 1 ? '' : 's' ?> have nothing linked yet.
    Until a shot lists what is in it, the scheduler makes it wait for
    <em>every</em> asset on the show before its animation can start, so breaking
    shots down usually pulls the delivery date <strong>in</strong>, not out.
    <a href="schedule.php?project=<?= $projectId ?>">Re-run the schedule</a> afterwards;
    a saved plan is a snapshot and will not improve by itself.
  </div>
<?php else: ?>
  <p class="muted small">
    All <?= $progress['total'] ?> shots are broken down, <?= $progress['links'] ?>
    asset<?= $progress['links'] === 1 ? '' : 's' ?> placed.
    <a href="schedule.php?project=<?= $projectId ?>">Re-run the schedule</a> to use it:
    a saved plan is a snapshot and does not update itself.
  </p>
<?php endif; ?>

<div class="bd-scroll">
<table class="grid-table breakdown-table">
  <thead>
    <tr>
      <?php if (count($episodes) > 1): ?><th class="bd-ep">Ep</th><?php endif; ?>
      <th class="bd-shot">Shot</th>
      <?php // FRAMES, not seconds. Frames are what an editor knows; the seconds
            // underneath are derived from them and the project's fps, and they
            // are what the expense projection prices. See breakdown_set_frames(). ?>
      <th class="bd-frames num" title="Type the frame count; the footage under it is worked out from the project's <?= (int)$project['fps'] ?> fps and is what the expense projection prices">Frames</th>
      <?php foreach ($types as $t): ?>
        <th><?= e($t !== '' ? $t : 'No type set') ?></th>
      <?php endforeach; ?>
      <th class="bd-notes">Notes</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-shot="<?= $r['id'] ?>">
        <?php if (count($episodes) > 1): ?>
          <td class="muted small"><?= e($r['episode']) ?: '—' ?></td>
        <?php endif; ?>
        <td><strong><?= e($r['shot_code']) ?></strong></td>
        <td class="num bd-frames">
          <?php if (can_edit()): ?>
            <input class="bd-frame-input" inputmode="numeric" size="5"
                   value="<?= $r['frames'] !== null ? (int)$r['frames'] : '' ?>"
                   aria-label="Frames in <?= e($r['shot_code']) ?>">
          <?php else: ?>
            <?= $r['frames'] !== null ? (int)$r['frames'] : '<span class="muted">—</span>' ?>
          <?php endif; ?>
          <div class="bd-secs muted small"><?= $r['seconds'] !== null
                ? e(fmt_num($r['seconds'], 3)) . 's' : '' ?></div>
        </td>

        <?php foreach ($types as $t): ?>
          <td class="bd-cell" data-type="<?= e($t) ?>">
            <?php foreach ($r['assets'][$t] ?? [] as $a): ?>
              <span class="chip" data-asset="<?= $a['id'] ?>">
                <?= e($a['name']) ?>
                <?php if (can_edit()): ?>
                  <button type="button" class="chip-x" title="Take <?= e($a['name']) ?> out of this shot"
                          aria-label="Remove <?= e($a['name']) ?>">×</button>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
            <?php if (can_edit()): ?>
              <?php // A <select> rather than a search box: an asset list is tens
                    // of items, not thousands, and a picker that needs no typing
                    // is faster for the same name repeated across forty shots. ?>
              <select class="bd-add" aria-label="Add <?= e($t !== '' ? $t : 'an asset') ?> to <?= e($r['shot_code']) ?>">
                <option value="">+</option>
                <?php foreach ($byType[$t] ?? [] as $a): ?>
                  <option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>

        <td class="bd-notes muted small"><?= e($r['notes']) ?: '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="muted small" id="bdStatus" hidden></p>

<script>
  window.BD_PROJECT = <?= $projectId ?>;
  window.BD_CAN_EDIT = <?= can_edit() ? 'true' : 'false' ?>;
</script>
<script src="assets/shots.js<?= asset_ver('shots.js') ?>"></script>

<?php endif; ?>

</div>
</body></html>
