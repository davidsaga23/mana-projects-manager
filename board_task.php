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

// ?pane=timeline: the Timeline alone, re-drawn after somebody posts a comment,
// a review or a version, so it is current without reopening the task (David,
// 2026-10-22: "I just left a comment and it didn't update in the timeline").
// Same checks as the whole panel above; nothing else is rendered or stamped.
if (($_GET['pane'] ?? '') === 'timeline') {
    echo board_timeline_html($pdo, $projectId, $stepId, $itemKey);
    exit;
}

// ?pane=versions: the Versions pane alone, for the Reviews screen (David,
// 2026-10-29: "Once a supervisor clicks the image, they will see the same
// review view as in spell"). assets/review-viewer.js is built from this pane,
// so Reviews borrows it rather than drawing a second one. Same checks as above,
// and the same rule as the panel's own tab for who may post.
if (($_GET['pane'] ?? '') === 'versions') {
    echo '<div class="panel-body" data-pane="versions">'
       . board_versions_html($pdo, $projectId, $stepId, $itemKey, !preview_blocks_saving())
       . '</div>';
    exit;
}

$t     = $detail['task'];
$item  = $detail['item'];
$crew  = board_assignees($pdo, $projectId);
$mayEdit   = can_edit();
$myCrewId  = (int)(effective_crew_id($pdo) ?? 0);
$mine      = $t['crew_id'] !== null && $t['crew_id'] === $myCrewId;
$linkReady = column_exists($pdo, 'progress', 'link');

// ⚠ ANYBODY WHO CAN LOG IN MAY COMMENT, which is wider than every other control
// on this panel and is the point. Setting a status, moving a date or assigning
// work is Production's; SAYING SOMETHING is how a freelancer tells Production
// that the brief is wrong, and a board where only Production can talk would
// push that conversation straight back into WhatsApp. A preview still writes
// nothing, the rule require_login() keeps everywhere.
$mayComment = !preview_blocks_saving();
$progressId = board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
$commentCount = $progressId
    ? count(board_task_comments($pdo, $progressId)['comments']) : 0;
$versionCount = $progressId ? count(board_task_versions($pdo, $progressId)) : 0;

// A mention stops being new when the person OPENS the task it is on, which is
// this request — not when they glance at the list on My tasks, which is a
// pointer rather than the thing itself (migration 040 says why). The twin of
// the stamp in ticket.php, and written on a GET for the same reason: it is a
// fact about somebody having looked, and there is no form to submit.
if ($progressId && $myCrewId > 0 && ticket_mentions_available($pdo)
    && column_exists($pdo, 'ticket_mentions', 'seen_at')) {
    $pdo->prepare(
        'UPDATE ticket_mentions m
           JOIN ticket_comments c ON c.id = m.comment_id
            SET m.seen_at = NOW()
          WHERE c.progress_id = ? AND m.crew_id = ? AND m.seen_at IS NULL'
    )->execute([$progressId, $myCrewId]);
}
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
  <?php // What the task IS, what people SAID, what was DELIVERED and said about
        // it, and last the TIMELINE of all of it (David, 2026-10-22: "let's change
        // the order of timeline to the end"). ?>
  <button type="button" class="panel-tab" role="tab" aria-selected="false" data-tab="comments">Comments<?php
    if ($commentCount): ?> <span class="muted">· <?= $commentCount ?></span><?php endif; ?></button>
  <button type="button" class="panel-tab" role="tab" aria-selected="false" data-tab="versions">Versions and notes<?php
    if ($versionCount): ?> <span class="muted">· <?= $versionCount ?></span><?php endif; ?></button>
  <?php // TIMELINE, once Activity (David, 2026-10-22): everything that happened
        // to the task, newest first. Drawn once here; the count is its entries.
        $timelineHtml = board_timeline_html($pdo, $projectId, $stepId, $itemKey);
        $timelineCount = substr_count($timelineHtml, 'class="tl-entry'); ?>
  <button type="button" class="panel-tab" role="tab" aria-selected="false" data-tab="activity">Timeline<?php
    if ($timelineCount): ?> <span class="muted">· <?= $timelineCount ?></span><?php endif; ?></button>
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
  <?php // ⚠ READ, NOT TYPED, SINCE 2026-10-22. David: "Version should not be
        // typed in but got from the number of files uploaded". It is still
        // `progress.version` and the board column still reads it — what
        // changed is that save_task_versions() writes it, so nobody can put
        // "v07" on a task that has three versions. board_api.php refuses the
        // field now, so a hand-made POST cannot either. ?>
  <label class="panel-field">
    <span>Current version</span>
    <?php if (trim((string)($t['version'] ?? '')) !== ''): ?>
      <p class="panel-read"><?= e((string)$t['version']) ?></p>
    <?php else: ?>
      <p class="panel-read muted">Nothing up yet</p>
    <?php endif; ?>
    <small class="muted">Counted from what is on the <button type="button"
      class="linkish panel-tab-link" data-tab="versions">Versions and notes</button> tab.</small>
  </label>
  <label class="panel-field">
    <span>Notes</span>
    <?= board_text_cell($t, 'notes', $projectId, $mayEdit, 'Anything worth saying about this task') ?>
  </label>

</div>

<?php // ⚠ THE SAME ROWS THE TICKET SCREEN SHOWS (migration 059). David,
      // 2026-10-22: "those comments are the ones that currently live under my
      // tasks, they're the exact same just displayed in this context too." So
      // this is one conversation seen from a second place, not a second
      // conversation — and board_comment.php re-renders this very pane through
      // the same function after a post. ?>
<div class="panel-body" data-pane="comments" hidden>
  <?php // Who a comment can NAME is wider than who can be assigned: anybody
        // with a login, so producers who are on no show's crew can be tagged. ?>
  <?= board_comments_html($pdo, $projectId, $stepId, $itemKey, $mayComment, board_mentionable($pdo, $projectId)) ?>
</div>

<?php // One renderer for the pane, shared with board_version.php so an upload
      // re-draws it through the same code that drew it. ?>
<div class="panel-body" data-pane="versions" hidden>
  <?= board_versions_html($pdo, $projectId, $stepId, $itemKey, $mayComment) ?>
</div>

<?php // The pane keeps its old key, "activity", so nothing that already opens it
      // has to change; what it shows is the whole timeline. ?>
<div class="panel-body" data-pane="activity" hidden>
  <?= $timelineHtml ?>
</div>
