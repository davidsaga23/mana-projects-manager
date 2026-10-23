<?php
// Putting a new version up against one task, and taking the latest one back.
//
// David, 2026-10-22: "Versions will start at v01 and increment by 1 every time
// a new version is up". Nobody picks the number here either — the request
// carries files and an optional note, and save_task_versions() works out what
// they are called.
//
// ⚠ IT RETURNS MARKUP, NOT JSON, like board_task.php and board_comment.php: the
// answer to an upload is the Versions pane, re-rendered by the same function
// that drew it. One renderer, so a thumbnail and a version label cannot drift.
//
// ⚠ ANYBODY WHO CAN LOG IN MAY PUT A VERSION UP, for the reason comments are
// open to everybody: the artist doing the work is the person with the file, and
// a review loop that only Production can feed is not a review loop. What stays
// enforced is that the show is one this account can see and the step and item
// are that show's — board_task_detail() settles both before anything is stored.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/board.php';
require_once __DIR__ . '/includes/uploads.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$projectId = (int)($_POST['project_id'] ?? 0);
$stepId    = (int)($_POST['step_id'] ?? 0);
$itemKey   = trim((string)($_POST['item_key'] ?? ''));
$userId    = (int)($_SESSION['user_id'] ?? 0);

/** A line of red, on its own, when there is nothing to re-render. */
function board_version_fail(string $why): void {
    echo '<p class="err">' . e($why) . '</p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') board_version_fail('This endpoint only accepts POST.');

$allowed = false;
foreach (board_projects($pdo) as $p) if ((int)$p['id'] === $projectId) $allowed = true;

$detail = $allowed ? board_task_detail($pdo, $projectId, $stepId, $itemKey) : null;
if (!$detail) board_version_fail('That task is not one this screen can put a version on.');

$mayPost = !preview_blocks_saving();
if (!$mayPost) board_version_fail('Nothing saves while you are previewing a role.');
if (!board_versions_available($pdo)) board_version_fail('Versions need database update 063.');

$said = '';
$bad  = '';

// --- take the latest version back -------------------------------------------
//
// ⚠ THE LATEST ONLY, and that is a rule rather than an oversight. Versions are
// quoted at each other ("looks good on v03"), so removing one from the middle
// would leave a hole in a sequence people are using as a shared reference. The
// top one is the only one nobody has had time to refer to yet.
if (isset($_POST['delete_version'])) {
    $vid = (int)$_POST['delete_version'];
    $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $q = $pdo->prepare(
        'SELECT id, version_no, stored_name, uploaded_by FROM progress_versions
          WHERE id = ? AND progress_id = ?'
    );
    $q->execute([$vid, $pid]);
    $v = $q->fetch();

    $top = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM progress_versions WHERE progress_id = ?');
    $top->execute([$pid]);
    $latest = (int)$top->fetchColumn();

    if (!$v) {
        $bad = 'That version is already gone.';
    } elseif ((int)$v['version_no'] !== $latest) {
        $bad = 'Only the latest version can be removed — the ones below it have been quoted.';
    } elseif (!is_admin() && (int)$v['uploaded_by'] !== $userId) {
        $bad = 'You can only remove a version you put up yourself.';
    } else {
        // The file first, while its stored name is still readable, then the row.
        @unlink(upload_dir_path() . '/' . basename((string)$v['stored_name']));
        $pdo->prepare('DELETE FROM progress_versions WHERE id = ?')->execute([$vid]);
        // The label follows the rows down, so "current version" never names one
        // that is not there any more.
        $now = $latest - 1;
        $pdo->prepare('UPDATE progress SET version = ? WHERE id = ?')
            ->execute([$now > 0 ? version_label($now) : null, $pid]);
        $said = version_label($latest) . ' removed.';
    }
} else {
    // --- put one up ---------------------------------------------------------
    $files = isset($_FILES['versions']) ? normalize_files_array($_FILES['versions']) : [];
    $any = (bool)array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if (!$any) {
        $bad = 'Pick a file first — a version is a file.';
    } else {
        // The progress row is created if the importer never made one, exactly
        // as a comment or a first status does.
        $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey, true);
        $res = save_task_versions($pdo, $files, $pid, $userId, (string)($_POST['note'] ?? ''));
        if ($res['saved'] > 0) {
            $said = $res['saved'] === 1
                ? (version_label($res['latest']) . ' is up.')
                : ($res['saved'] . ' versions up, now at ' . version_label($res['latest']) . '.');
        }
        // Reported, never swallowed: a file that silently vanishes looks exactly
        // like one that worked.
        if ($res['errors']) $bad = implode(' ', $res['errors']);
    }
}
?>
<?php if ($bad !== ''): ?><p class="err"><?= e($bad) ?></p><?php endif; ?>
<?php if ($said !== ''): ?><p class="ok small"><?= e($said) ?></p><?php endif; ?>
<?= board_versions_html($pdo, $projectId, $stepId, $itemKey, $mayPost) ?>
