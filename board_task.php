<?php
// One task, as the panel that opens over the Board.
//
// David, 2026-10-22, asking where Plaky's subitem pop-up had gone: "the
// detailed view of that task". It opens over the board rather than taking you
// to a page, so you can open one, adjust it, close it and open the next — which
// is the thing a producer does for twenty minutes at a time.
//
// ⚠ IT RETURNS MARKUP, NOT JSON, on purpose. Every control in here is the same
// control the board row draws, down to its class and its three data
// attributes — so assets/board-screen.js saves it through the handlers that
// already exist, and nothing about saving is written twice. Rendering it in
// PHP also keeps the escaping where the rest of this codebase keeps it.
//
// Read by everybody who can log in; the controls obey exactly the rules the
// board obeys, because they are built by the same helpers.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/board.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/timelog.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_GET['project'] ?? 0);
$stepId    = (int)($_GET['step'] ?? 0);
$itemKey   = trim((string)($_GET['item'] ?? ''));

// Scoped like the board itself: a show this account cannot see is a show it
// cannot open a task on either.
$allowed = false;
foreach (board_projects($pdo) as $p) if ((int)$p['id'] === $projectId) $allowed = true;

$detail = $allowed ? board_task_detail($pdo, $projectId, $stepId, $itemKey) : null;

if (!$detail) {
    http_response_code(404);
    echo '<div class="panel-empty"><p>That task is not one this screen can open.</p></div>';
    exit;
}

$t     = $detail['task'];
$item  = $detail['item'];
$crew  = board_assignees($pdo, $projectId);
$mayEdit   = can_edit();
$myCrewId  = (int)(effective_crew_id($pdo) ?? 0);
$mine      = $t['crew_id'] !== null && $t['crew_id'] === $myCrewId;
$linkReady = column_exists($pdo, 'progress', 'link');
?>
<div class="panel-head">
  <p class="panel-crumbs">
    <?= e($item['group']) ?> <span class="panel-sep">›</span>
    <?php if ($item['kind'] === 'p'): ?>
      <span><?= e($item['name']) ?></span>
    <?php else: ?>
      <a href="item.php?project=<?= $projectId ?>&amp;item=<?= e(urlencode($itemKey)) ?>"><?= e($item['name']) ?></a>
    <?php endif; ?>
    <?php if ($item['frames'] !== null): ?>
      <span class="panel-sep">·</span> <?= e(number_format($item['frames'])) ?> frames
    <?php endif; ?>
  </p>
  <h2><?= e($t['step']) ?></h2>
</div>

<div class="panel-tabs" role="tablist">
  <button type="button" class="panel-tab is-on" role="tab" aria-selected="true" data-tab="details">Details</button>
  <button type="button" class="panel-tab" role="tab" aria-selected="false" data-tab="activity">Activity<?php
    if ($detail['activity']): ?> <span class="muted">· <?= count($detail['activity']) ?></span><?php endif; ?></button>
</div>

<div class="panel-body" data-pane="details">
  <?php // ⚠ THE SAME CONTROLS THE ROW CARRIES, built by the same helpers, so
        // they save through the handlers that already exist and obey the same
        // rules — an artist gets two statuses here as well, and nothing else. ?>
  <label class="panel-field">
    <span>Status</span>
    <?= board_status_cell($t, $detail['statuses'], $projectId, $mayEdit || $mine, $myCrewId, $mayEdit) ?>
  </label>
  <label class="panel-field">
    <span>Assignee</span>
    <?= board_crew_cell($t, $crew, $projectId, $mayEdit) ?>
  </label>
  <label class="panel-field">
    <span>Due date</span>
    <?= board_due_cell($t, $projectId, $mayEdit) ?>
    <small class="muted">The date the timeline draws. Changing it moves that bar and marks the task hand-planned.</small>
  </label>
  <label class="panel-field">
    <span>Link</span>
    <?= board_text_cell($t, 'link', $projectId, $mayEdit && $linkReady, 'https://…') ?>
    <?php if (!$linkReady): ?><small class="warn-text">Needs database update 057.</small><?php endif; ?>
  </label>
  <label class="panel-field">
    <span>Version</span>
    <?= board_text_cell($t, 'version', $projectId, $mayEdit, 'v01') ?>
  </label>
  <label class="panel-field">
    <span>Notes</span>
    <?= board_text_cell($t, 'notes', $projectId, $mayEdit, 'Anything worth saying about this task') ?>
  </label>

  <p class="panel-foot muted small">
    <?php if ($detail['logged'] !== null && $detail['logged'] > 0): ?>
      <?= e(fmt_num($detail['logged'], 1)) ?> h logged ·
    <?php endif; ?>
    <?php if ($item['frames'] !== null): ?>
      Frames come from the <a href="shots.php?project=<?= $projectId ?>">breakdown</a>
    <?php else: ?>
      Edited here and on the board, the same row either way
    <?php endif; ?>
  </p>
</div>

<div class="panel-body" data-pane="activity" hidden>
  <?php if (!$detail['activity']): ?>
    <p class="muted small">Nothing has been recorded against this task yet. Every status change from
      now on appears here, with who made it and where from.</p>
  <?php else: ?>
    <ul class="panel-activity">
      <?php foreach ($detail['activity'] as $a): ?>
        <li>
          <span class="panel-act-dot panel-act-<?= e($a['change_type']) ?>"></span>
          <span class="panel-act-what">
            <?php if ($a['old_status_name']): ?>
              <?= e($a['old_status_name']) ?> <span class="panel-sep">→</span>
            <?php endif; ?>
            <strong><?= e($a['new_status_name'] ?: 'nothing recorded') ?></strong>
          </span>
          <span class="panel-act-who muted">
            <?= e($a['changed_by'] ?: $a['crew_name'] ?: 'somebody') ?>
            <?php // Where it came from matters: "the importer moved 140 shots"
                  // and "somebody moved 140 shots by hand" look identical here
                  // otherwise, and mean completely different things. ?>
            <?php if (($a['source'] ?? '') === 'import'): ?>
              <span class="panel-src">imported</span>
            <?php endif; ?>
          </span>
          <span class="panel-act-when muted"><?= e(date('j M', strtotime((string)$a['log_date']))) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
