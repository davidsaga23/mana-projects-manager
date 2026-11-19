<?php
// SHEET BACKUP — the Google Sheet copy of Spell, and bringing its edits back.
//
// David, 2026-11-19, after a morning he could not open Anima: a spreadsheet
// copy of Spell, refreshed every hour, for the studio to work from when Anima
// is down. includes/sheet_backup.php explains the design; this is its screen.
//
// Production only (can_edit()). Two jobs:
//
//   1. SAY WHETHER IT IS WORKING: is it set up, when did the hourly copy last
//      run, did it fail, which shows' tabs have edits waiting. A backup nobody
//      checks is found broken on the day it is needed.
//
//   2. THE CATCH-UP after an outage. For one show: every Status or Assignee
//      somebody changed in the sheet, e.g. "SH040 Comp: In progress → Pending
//      review", with a warning where Anima ALSO changed that cell since the copy
//      was written. Production ticks what to apply; it goes through Spell's own
//      save (includes/board_write.php), so history and bells read normally.
//      ⚠ NOTHING IS EVER APPLIED WITHOUT A TICK. Then "Finish" rewrites the
//      show's tab from Anima, and the hourly copy carries on.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sheet_backup.php';
require_login();

if (!can_edit()) {
    flash('err', 'The Sheet backup screen is for Production.');
    header('Location: index.php');
    exit;
}

$ready   = sheet_backup_available($pdo);
$problem = gsheet_setup_problem();
$projectId = (int)($_GET['project'] ?? $_POST['project_id'] ?? 0);
$here = 'sheet_backup.php' . ($projectId ? '?project=' . $projectId : '');

// Only the shows Spell offers can be reviewed here, the same list the copy writes.
$projects = board_projects($pdo);
$byId = [];
foreach ($projects as $p) $byId[(int)$p['id']] = $p;
if ($projectId && !isset($byId[$projectId])) $projectId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready && $problem === null) {
    @set_time_limit(300);
    try {
        if (isset($_POST['copy_now'])) {
            // The same run the cron job makes: shows with edits waiting are left alone.
            $res = sheet_backup_run($pdo, ['via' => 'screen']);
            flash($res['ok'] ? 'ok' : 'err', $res['status'] . ($res['lines'] ? ' ' . implode(' ', $res['lines']) : ''));
        } elseif (isset($_POST['apply']) && $projectId) {
            $ticked = array_map('strval', (array)($_POST['change'] ?? []));
            if (!$ticked) {
                flash('warn', 'Nothing was ticked, so nothing was applied.');
            } else {
                $res = sheet_backup_apply($pdo, $projectId, $ticked);
                if ($res['applied']) {
                    flash('ok', 'Applied ' . count($res['applied']) . ' change' . (count($res['applied']) === 1 ? '' : 's')
                        . ' to Anima: ' . implode('; ', $res['applied']) . '.');
                }
                if ($res['skipped']) flash('warn', 'Not applied: ' . implode('; ', $res['skipped']));
            }
        } elseif (isset($_POST['finish']) && $projectId) {
            $res = sheet_backup_finish($pdo, $projectId, current_user_display());
            flash($res['ok'] ? 'ok' : 'err', $res['ok']
                ? 'Review finished. The show\'s tab now shows Anima again, and the hourly copy carries on.'
                : $res['status'] . ' ' . implode(' ', $res['lines']));
            $here = 'sheet_backup.php';
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $here);
    exit;
}

$states = $ready ? sheet_backup_states($pdo) : [];
$runs   = $ready ? sheet_backup_recent_runs($pdo, 5) : [];
$sheetUrl = gsheet_backup_id() !== '' ? 'https://docs.google.com/spreadsheets/d/' . rawurlencode(gsheet_backup_id()) . '/edit' : '';

// The review, read from Google now. A failure is said on the page rather than
// thrown: the person needs to see why, not a blank screen.
$review = null;
$reviewError = null;
if ($projectId && $ready && $problem === null) {
    try {
        $review = sheet_backup_review($pdo, $projectId);
        $reviewError = $review['error'];
    } catch (Throwable $e) {
        $reviewError = $e->getMessage();
    }
}

$pageTitle = 'Sheet backup';
require __DIR__ . '/includes/header.php';
?>

<h2>Sheet backup</h2>
<p class="muted">Every hour Anima copies Spell into a Google Sheet, one tab per show, so the studio can
  keep working from it if Anima is down. In the sheet people change only Status and Assignee. Those
  changes come back into Anima only when somebody reviews and ticks them here.</p>

<?php if (!$ready): ?>
  <div class="flash flash-warn">The sheet backup needs database update <code>105</code>, which has not been applied yet.
    <?php if (is_admin()): ?><a href="migrate.php">Review and apply it →</a><?php else: ?>Ask an administrator to apply it.<?php endif; ?></div>
<?php endif; ?>
<?php if ($problem !== null): ?>
  <div class="flash flash-warn"><?= e($problem) ?></div>
<?php endif; ?>

<?php if ($ready && $problem === null): ?>
  <p>
    <?php if ($sheetUrl !== ''): ?><a href="<?= e($sheetUrl) ?>" target="_blank" rel="noopener">Open the backup sheet ↗</a> ·<?php endif; ?>
    <span class="muted small">Anima writes it as <?= e(gsheet_service_email()) ?></span>
  </p>

  <?php $last = $runs[0] ?? null; ?>
  <?php if (!$last): ?>
    <div class="flash flash-warn">The copy has never run. Press <strong>Copy now</strong> to try it, and add the
      hourly cron job in cPanel (docs/SHEET_BACKUP.md, step 6).</div>
  <?php elseif (!(int)$last['ok'] && $last['finished_at'] !== null): ?>
    <div class="flash flash-err">The last copy, <?= e(sheet_backup_when($last['started_at'])) ?>, failed:
      <?= e((string)$last['note']) ?></div>
  <?php elseif (strtotime((string)$last['started_at']) < time() - 2 * 3600): ?>
    <div class="flash flash-warn">The last copy was <?= e(sheet_backup_when($last['started_at'])) ?>, more than two
      hours ago. Is the hourly cron job still in cPanel?</div>
  <?php else: ?>
    <p class="muted small">Last copied <?= e(sheet_backup_when($last['started_at'])) ?>:
      <?= e((string)$last['note']) ?></p>
  <?php endif; ?>

  <form method="post" action="sheet_backup.php">
    <button type="submit" name="copy_now" value="1" class="secondary">Copy now</button>
    <span class="muted small">Does what the hourly job does. Shows with changes waiting are left alone.</span>
  </form>

  <h3>Shows</h3>
  <table>
    <thead><tr><th>Show</th><th>Last written to the sheet</th><th>Changes made in the sheet</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($projects as $p): $st = $states[(int)$p['id']] ?? null; ?>
      <tr>
        <td><strong><?= e($p['code'] ?: $p['name']) ?></strong> <span class="muted small"><?= e($p['name']) ?></span></td>
        <td><?= $st && $st['written_at'] ? e(sheet_backup_when($st['written_at'])) : '<span class="muted">Not yet</span>' ?></td>
        <td>
          <?php if ($st && $st['paused_at']): ?>
            <span class="err"><?= (int)$st['paused_edits'] ?> waiting for review</span>
            <span class="muted small">· tab not refreshed since <?= e(sheet_backup_when($st['written_at'])) ?></span>
          <?php elseif ($st): ?>
            <span class="muted">None at the last copy</span>
          <?php endif; ?>
          <?php if ($st && $st['reviewed_at']): ?>
            <br><span class="muted small">Last reviewed <?= e(sheet_backup_when($st['reviewed_at'])) ?> by <?= e((string)$st['reviewed_by']) ?></span>
          <?php endif; ?>
        </td>
        <td><?php if ($st && $st['written_at']): ?>
          <a href="sheet_backup.php?project=<?= (int)$p['id'] ?>#review"><?= $st['paused_at'] ? 'Review changes' : 'Check the sheet' ?></a>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($projectId && $ready && $problem === null): $proj = $byId[$projectId]; ?>
  <h3 id="review">Review: <?= e($proj['code'] ?: $proj['name']) ?></h3>
  <?php if ($reviewError !== null): ?>
    <div class="flash flash-err"><?= e($reviewError) ?></div>
  <?php else:
    $changes = $review['changes'];
    $todo = array_values(array_filter($changes, fn($c) => !$c['already']));
    $done = array_values(array_filter($changes, fn($c) => $c['already'])); ?>
    <p class="muted">Compared with what Anima wrote to the sheet on <?= e(sheet_backup_when($review['written_at'])) ?>.
      Only the Status and Assignee columns are read.
      <?php if ($review['missing']): ?><?= (int)$review['missing'] ?> task row<?= $review['missing'] === 1 ? ' is' : 's are' ?>
        no longer in the sheet; a deleted row changes nothing in Anima.<?php endif; ?></p>

    <?php if ($review['twice']): ?>
      <div class="flash flash-warn">These rows appear more than once in the sheet with different values, so neither
        copy is offered. Set them on Spell: <?= e(implode(', ', $review['twice'])) ?>.</div>
    <?php endif; ?>

    <?php if (!$todo): ?>
      <div class="flash flash-ok">Nothing to bring back: the sheet has no changes that Anima does not already have.</div>
    <?php else: ?>
      <p>Tick the changes to apply. <strong>Both changed</strong> means Anima was also changed since the copy, so
        applying the sheet's value would replace what Anima says now. Those start unticked; look at them first.</p>
      <form method="post" action="sheet_backup.php?project=<?= $projectId ?>#review">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <table>
          <thead><tr>
            <th><input type="checkbox" id="tickAll" aria-label="Tick every change that can be applied"></th>
            <th>Shot / asset</th><th>Department</th><th>Column</th><th>Change made in the sheet</th>
            <th>Anima now</th><th>Note</th>
          </tr></thead>
          <tbody>
          <?php foreach ($todo as $c): $can = $c['problem'] === null; ?>
            <tr>
              <td><input type="checkbox" name="change[]" value="<?= e($c['id']) ?>" class="sheet-change"
                         <?= $can && !$c['conflict'] ? 'checked' : '' ?> <?= $can ? '' : 'disabled' ?>
                         aria-label="Apply <?= e($c['item'] . ' ' . $c['dept'] . ' ' . $c['field']) ?>"></td>
              <td><strong><?= e($c['item']) ?></strong></td>
              <td><?= e($c['dept']) ?></td>
              <td><?= e($c['field']) ?></td>
              <td><?= $c['old'] !== '' ? e($c['old']) : '<span class="muted">empty</span>' ?> →
                  <strong><?= $c['new'] !== '' ? e($c['new']) : '<span class="muted">empty</span>' ?></strong>
                  <span class="muted small">(row <?= (int)$c['row'] ?>)</span></td>
              <td><?= $c['now'] !== '' ? e($c['now']) : '<span class="muted">empty</span>' ?></td>
              <td>
                <?php if (!$can): ?><span class="err"><?= e($c['problem']) ?></span>
                <?php elseif ($c['conflict']): ?><span class="err">Both changed:</span> Anima said
                  <?= $c['old'] !== '' ? e($c['old']) : 'empty' ?> when copied and says
                  <?= $c['now'] !== '' ? e($c['now']) : 'empty' ?> now.
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p><button type="submit" name="apply" value="1">Apply ticked changes</button></p>
      </form>
    <?php endif; ?>

    <?php if ($done): ?>
      <p class="muted small"><?= count($done) ?> other change<?= count($done) === 1 ? '' : 's' ?> in the sheet already
        match<?= count($done) === 1 ? 'es' : '' ?> Anima, so there is nothing to apply:
        <?= e(implode(', ', array_map(fn($c) => $c['item'] . ' ' . $c['dept'] . ' ' . $c['field'] . ' → '
            . ($c['new'] !== '' ? $c['new'] : 'empty'), $done))) ?>.</p>
    <?php endif; ?>

  <?php endif; ?>

  <h4>When you are done</h4>
  <p class="muted">Finishing rewrites this show's tab from Anima, so the sheet is current again and the hourly
    copy carries on. Any change still in the sheet that you did not apply is overwritten.</p>
  <form method="post" action="sheet_backup.php?project=<?= $projectId ?>"
        onsubmit="return confirm('Rewrite this show\'s tab from Anima? Changes in the sheet you did not apply will be overwritten.');">
    <input type="hidden" name="project_id" value="<?= $projectId ?>">
    <button type="submit" name="finish" value="1" class="secondary">Finish review and refresh the tab</button>
  </form>
<?php endif; ?>

<script>
// "Tick all" ticks every change that can be applied, including the ones marked
// Both changed: a deliberate second click, after reading the list.
(function () {
  var all = document.getElementById('tickAll');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('input.sheet-change:not(:disabled)').forEach(function (b) { b.checked = all.checked; });
  });
})();
</script>

</div>
</body></html>
