<?php
// Saying something about one task on the Board.
//
// David, 2026-10-22: "let's also add a comments section tab between details and
// activity" — then, when this was about to grow its own comment table, "those
// comments are the ones that currently live under my tasks, they're the exact
// same just displayed in this context too", and "remember to have the option to
// create threads".
//
// ⚠ IT RETURNS MARKUP, NOT JSON, for the same reason board_task.php does: the
// answer to a post is the comments pane, re-rendered by the very function that
// drew it a moment ago. A reply's indent, a highlighted mention and the empty
// state are written once, in board_comments_html(), and this endpoint is forty
// lines of plumbing rather than a second renderer in JavaScript.
//
// ⚠ ANYBODY WHO CAN LOG IN MAY POST HERE. Every other write on the board is
// Production's; this one is not, and that is deliberate — see board_task.php.
// What is still enforced: the show has to be one this account can see, and the
// step and item have to be that show's, both of which board_task_detail()
// settles before a word is stored.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/board.php';
require_once __DIR__ . '/includes/mailer.php';   // notify_mentioned_task()
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_POST['project_id'] ?? 0);
$stepId    = (int)($_POST['step_id'] ?? 0);
$itemKey   = trim((string)($_POST['item_key'] ?? ''));
$body      = (string)($_POST['body'] ?? '');
$parentId  = (int)($_POST['parent_id'] ?? 0) ?: null;

/** A line of red, on its own, when there is nothing to re-render. */
function board_comment_fail(string $why): void {
    echo '<p class="err">' . e($why) . '</p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') board_comment_fail('This endpoint only accepts POST.');

// Scoped like the board itself: a show this account cannot see is a show it
// cannot comment on either.
$allowed = false;
foreach (board_projects($pdo) as $p) if ((int)$p['id'] === $projectId) $allowed = true;

// Does this step really apply to this item on this show? board_task_detail()
// asks every one of those questions and returns null on the first no.
$detail = $allowed ? board_task_detail($pdo, $projectId, $stepId, $itemKey) : null;
if (!$detail) board_comment_fail('That task is not one this screen can comment on.');

$crew = board_assignees($pdo, $projectId);
$mayPost = !preview_blocks_saving();
if (!$mayPost) board_comment_fail('Nothing saves while you are previewing a role.');

$pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
$pq->execute([$projectId]);
$project = $pq->fetch() ?: ['code' => '', 'name' => ''];

$said = '';
$bad  = '';
try {
    [, $said] = board_comment_add(
        $pdo, $projectId, $stepId, $itemKey, $body, $parentId,
        (int)($_SESSION['user_id'] ?? 0),
        [
            'step_name'    => (string)$detail['step']['name'],
            'item_name'    => (string)$detail['item']['name'],
            'project_code' => (string)$project['code'],
            'project_name' => (string)$project['name'],
        ]
    );
} catch (Throwable $e) {
    $bad = $e->getMessage();
}
?>
<?php if ($bad !== ''): ?><p class="err"><?= e($bad) ?></p><?php endif; ?>
<?php if ($said !== ''): ?><p class="ok small"><?= e($said) ?></p><?php endif; ?>
<?= board_comments_html($pdo, $projectId, $stepId, $itemKey, $mayPost, $crew) ?>
