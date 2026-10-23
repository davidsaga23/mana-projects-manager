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
require_once __DIR__ . '/includes/uploads.php';  // files on a comment (migration 061)
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

$crew = board_mentionable($pdo, $projectId);   // who a comment can name
$mayPost = !preview_blocks_saving();
if (!$mayPost) board_comment_fail('Nothing saves while you are previewing a role.');

$pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
$pq->execute([$projectId]);
$project = $pq->fetch() ?: ['code' => '', 'name' => ''];

$userId = (int)($_SESSION['user_id'] ?? 0);
$said = '';
$bad  = '';

// --- take one file off a comment -------------------------------------------
//
// Scoped by the COMMENT it hangs on, and that comment by the task this request
// already proved belongs to a show you can see — so an attachment id from
// another show matches nothing. Yours to remove, or an admin's, the same rule
// the ticket screen keeps.
if (isset($_POST['delete_attachment'])) {
    $aid = (int)$_POST['delete_attachment'];
    $pid = board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $q = $pdo->prepare(
        'SELECT a.id, a.stored_name, a.uploaded_by
           FROM ticket_attachments a
           JOIN ticket_comments c ON c.id = a.comment_id
          WHERE a.id = ? AND c.progress_id = ?'
    );
    $q->execute([$aid, (int)$pid]);
    $att = $q->fetch();
    if (!$att) {
        $bad = 'That file is already gone.';
    } elseif (!is_admin() && (int)$att['uploaded_by'] !== $userId) {
        $bad = 'You can only remove files you attached yourself.';
    } else {
        // The file first, while its stored name is still readable, then the row.
        @unlink(upload_dir_path() . '/' . basename((string)$att['stored_name']));
        $pdo->prepare('DELETE FROM ticket_attachments WHERE id = ?')->execute([$aid]);
        $said = 'File removed.';
    }
    ?>
    <?php if ($bad !== ''): ?><p class="err"><?= e($bad) ?></p><?php endif; ?>
    <?php if ($said !== ''): ?><p class="ok small"><?= e($said) ?></p><?php endif; ?>
    <?= board_comments_html($pdo, $projectId, $stepId, $itemKey, $mayPost, $crew) ?>
    <?php
    exit;
}

$files = isset($_FILES['attachments']) ? normalize_files_array($_FILES['attachments']) : [];
$hasFiles = (bool)array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

try {
    [$commentId, $said] = board_comment_add(
        $pdo, $projectId, $stepId, $itemKey, $body, $parentId, $userId,
        [
            'step_name'    => (string)$detail['step']['name'],
            'item_name'    => (string)$detail['item']['name'],
            'project_code' => (string)$project['code'],
            'project_name' => (string)$project['name'],
        ],
        $hasFiles
    );

    // ⚠ AFTER THE COMMENT, because the file hangs off it — and with no ticket,
    // which migration 061 made legal. save_ticket_attachments() reports every
    // file it refused and why: a 40 MB video that silently vanishes looks
    // exactly like one that worked.
    if ($hasFiles) {
        $res = save_ticket_attachments($pdo, $files, 0, $commentId, $userId);
        if ($res['saved'])  $said .= ' ' . $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $said .= ' ' . implode(' ', $res['errors']);
    }
} catch (Throwable $e) {
    $bad = $e->getMessage();
}
?>
<?php if ($bad !== ''): ?><p class="err"><?= e($bad) ?></p><?php endif; ?>
<?php if ($said !== ''): ?><p class="ok small"><?= e($said) ?></p><?php endif; ?>
<?= board_comments_html($pdo, $projectId, $stepId, $itemKey, $mayPost, $crew) ?>
