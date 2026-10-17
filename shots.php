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
      <a href="wizard.php?project=<?= $projectId ?>&amp;step=5">Setup</a> screen or through
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
    <?php // Said HERE because this banner is the only place the problem is
          // visible, and a producer reading it is holding exactly the shot the
          // answer applies to. Hidden when migration 042 has not been applied,
          // rather than advertising a tick that is not there. ?>
    <?php if (breakdown_na_available($pdo)): ?>
      If a shot genuinely needs none of something, open that column and tick
      <strong>N/A</strong> instead of leaving it blank: an empty cell reads as
      "not decided yet" and keeps the wait, <strong>N/A</strong> clears it.
    <?php endif; ?>
    <a href="schedule.php?project=<?= $projectId ?>">Re-run the schedule</a> afterwards;
    a saved plan is a snapshot and will not improve by itself.
  </div>
<?php else: ?>
  <p class="muted small">
    All <?= $progress['total'] ?> shots are broken down, <?= $progress['links'] ?>
    asset<?= $progress['links'] === 1 ? '' : 's' ?> placed<?php
      // Said rather than folded into the count above: a cell marked N/A is a
      // decision somebody made, not an asset they placed.
      $naCount = (int)($progress['na'] ?? 0);
      if ($naCount > 0) {
          echo ', ' . $naCount . ' cell' . ($naCount === 1 ? '' : 's') . ' marked N/A';
      }
    ?>.
    <a href="schedule.php?project=<?= $projectId ?>">Re-run the schedule</a> to use it:
    a saved plan is a snapshot and does not update itself.
  </p>
<?php endif; ?>

<?php
// FRAMES AND FOOTAGE, TOTALLED (David, 2026-09-22), above the table and again
// under it: the list runs to forty rows and more, and the number people come
// here for is the sum.
//
// Counted over the rows SHOWN, so the episode filter narrows it too, and shots
// with no frame count are NAMED rather than counted as zero: a total that
// quietly leaves five shots out is worse than one that says it did.
$totalFrames = 0;
$totalSecs   = 0.0;
$noFrames    = 0;
foreach ($rows as $r) {
    if ($r['frames'] === null) { $noFrames++; continue; }
    $totalFrames += (int)$r['frames'];
    $totalSecs   += (float)($r['seconds'] ?? 0);
}
$shotWord = count($rows) === 1 ? 'shot' : 'shots';
// The running time only once there is a minute of it: "0:04.08" for a four
// second show is arithmetic nobody asked for.
$runningTime = $totalSecs >= 60 ? ' · ' . fmt_secs($totalSecs) : '';
$missingNote = $noFrames > 0
    ? ', <span class="warn-text">' . $noFrames . ' without a frame count yet</span>'
    : '';
?>
<p class="muted small bd-totals">
  <strong><?= e(number_format(count($rows))) ?></strong> <?= $shotWord ?><?= $episode !== ''
    ? ' in ' . e($episode) : '' ?> ·
  <strong><?= e(number_format($totalFrames)) ?></strong> frames ·
  <strong><?= e(fmt_num($totalSecs, 3)) ?>s</strong><?= e($runningTime) ?>
  at <?= (int)$project['fps'] ?> fps<?= $missingNote ?>
</p>

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
          <?php if (can_edit_area('shots')): ?>
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
                <?php if (can_edit_area('shots')): ?>
                  <button type="button" class="chip-x" title="Take <?= e($a['name']) ?> out of this shot"
                          aria-label="Remove <?= e($a['name']) ?>">×</button>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
            <?php // ⚠ "NOTHING OF THIS KIND IN THIS SHOT", drawn as a chip so it
                  // reads as something somebody DECIDED rather than as a cell
                  // that happens to be empty — which is the whole distinction
                  // migration 042 exists to make. It takes the same × to remove
                  // as an asset chip does, so there is no second gesture to
                  // learn. ?>
            <?php if (!empty($r['na'][$t])): ?>
              <span class="chip chip-na" data-na="1">
                N/A
                <?php if (can_edit_area('shots')): ?>
                  <button type="button" class="chip-x"
                          title="<?= e($r['shot_code']) ?> does need <?= e($t !== '' ? $t : 'untyped asset') ?> work after all"
                          aria-label="Clear N/A">×</button>
                <?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if (can_edit_area('shots')): ?>
              <?php // A BUTTON that opens a list of tick boxes, not a dropdown
                    // (David, 2026-09-22: "have each character with a check box
                    // so I can click and add several assets at the same time").
                    // A dropdown adds one thing per trip through the list, and a
                    // shot of FC Mobile carries five players out of twenty-five.
                    // The list itself is built by assets/shots.js from the types
                    // below: forty rows by five columns of tick boxes would be
                    // thousands of inputs in the page, nearly all never looked
                    // at. ?>
              <button type="button" class="bd-add"
                      aria-label="Add <?= e($t !== '' ? $t : 'an asset') ?> to <?= e($r['shot_code']) ?>"
                      aria-haspopup="true">+</button>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>

        <?php // Typed here since 2026-09-22: it was the one column on this screen
              // that nothing but an importer could fill. ?>
        <td class="bd-notes">
          <?php if (can_edit_area('shots')): ?>
            <textarea class="bd-note-input" rows="1" placeholder="—"
                      aria-label="Note on <?= e($r['shot_code']) ?>"><?= e($r['notes']) ?></textarea>
          <?php else: ?>
            <span class="muted small"><?= e($r['notes']) ?: '—' ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php // The same totals under the table, the frames sitting in the frames
        // column so the sum lines up with what it adds. ?>
  <tfoot>
    <tr>
      <?php if (count($episodes) > 1): ?><td></td><?php endif; ?>
      <td><strong>Total</strong></td>
      <td class="num bd-frames">
        <strong><?= e(number_format($totalFrames)) ?></strong>
        <div class="bd-secs muted small"><?= e(fmt_num($totalSecs, 3)) ?>s<?= e($runningTime) ?></div>
      </td>
      <td class="muted small" colspan="<?= count($types) + 1 ?>">
        <?= e(number_format(count($rows))) ?> <?= $shotWord ?><?= $episode !== ''
          ? ' in ' . e($episode) : '' ?><?= $missingNote ?>
      </td>
    </tr>
  </tfoot>
</table>
</div>

<p class="muted small" id="bdStatus" hidden></p>

<script>
  window.BD_PROJECT = <?= $projectId ?>;
  window.BD_CAN_EDIT = <?= can_edit_area('shots') ? 'true' : 'false' ?>;
  <?php // ⚠ WITHOUT MIGRATION 042 THE PICKER MUST NOT OFFER N/A. Offering a
        // tick that always fails is worse than not offering it: the producer
        // believes the cell is settled and the scheduler carries on waiting for
        // every asset of that type. ?>
  window.BD_NA_OK = <?= breakdown_na_available($pdo) ? 'true' : 'false' ?>;
  <?php // Every asset this project has, by type, for the picker. Sent once
        // rather than repeated in every cell: the same twenty-five names would
        // otherwise be written into the page two hundred times. ?>
  window.BD_ASSETS = <?= json_encode(array_map(function ($list) {
      return array_map(function ($a) {
          return ['id' => (int)$a['id'], 'name' => (string)$a['name']];
      }, $list);
  }, $byType), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<?php // gridtools first: shots.js calls into it as it loads. ?>
<script src="assets/gridtools.js<?= asset_ver('gridtools.js') ?>"></script>
<script src="assets/shots.js<?= asset_ver('shots.js') ?>"></script>

<?php endif; ?>

</div>
</body></html>
