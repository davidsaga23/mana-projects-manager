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
require_once __DIR__ . '/includes/mailer.php';   // notify_mentioned_task()
require_once __DIR__ . '/includes/naming.php';   // the show's file naming convention (067)
require_once __DIR__ . '/includes/activity.php'; // log_status_change_by_id(), for the move to Pending review
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

/**
 * AN ASSIGNEE'S NEW VERSION SENDS THE TASK TO PENDING REVIEW (David, 2026-10-29:
 * "when a new version is uploaded by the artist assign to that task, let's
 * automatically change the status to pending review").
 *
 * Only when the uploader is named on the task (the primary assignee or a second
 * name in progress_crew), so a supervisor or producer putting a file up does
 * not move somebody else's work. The status is the show's own row called
 * "Pending review", matched by name as REVIEW_STAGES and the self-serve list
 * match it, because statuses are each show's data.
 *
 * ⚠ NEVER OUT OF AN APPROVED STATUS. Approved work is what seconds and
 * statements are paid on; a file put up afterwards is left for a person to
 * decide about rather than quietly un-approving it.
 *
 * Saved, logged and notified exactly as a status change on the board is
 * (board_api.php): the same flags, the same bell, the same progress_log row.
 *
 * @return string a sentence to add to the upload's confirmation, or ''
 */
function board_version_to_review(PDO $pdo, int $projectId, array $step, string $itemKey,
                                 int $progressId, string $taskName): string {
    $me = (int)(effective_crew_id($pdo) ?? 0);
    if ($me <= 0 || $progressId <= 0) return '';
    $q = $pdo->prepare('SELECT id, crew_id, status_id FROM progress WHERE id = ?');
    $q->execute([$progressId]);
    $row = $q->fetch();
    if (!$row) return '';
    $mine = (int)$row['crew_id'] === $me;
    if (!$mine) {
        try {
            $c = $pdo->prepare('SELECT 1 FROM progress_crew WHERE progress_id = ? AND crew_id = ?');
            $c->execute([$progressId, $me]);
            $mine = (bool)$c->fetchColumn();
        } catch (PDOException $e) {
            $mine = false;      // progress_crew is optional
        }
    }
    if (!$mine) return '';

    $map = statuses_by_id($pdo, $projectId);
    $target = null;
    foreach ($map as $st) {
        if (strtolower(trim(preg_replace('/\s+/', ' ', (string)$st['name']))) === 'pending review') { $target = $st; break; }
    }
    if (!$target) return '';

    $was = $row['status_id'] !== null ? (int)$row['status_id'] : null;
    if ($was === (int)$target['id']) return '';
    $cur = $was !== null ? ($map[$was] ?? null) : null;
    if ($cur && status_flags($cur)['is_approved'] === 1) {
        return 'The task stays ' . $cur['name'] . ': it is approved, so its status was not changed.';
    }

    $flags = status_flags($target);
    $pdo->prepare('UPDATE progress SET status_id = ?, is_delivered = ?, is_approved = ? WHERE id = ?')
        ->execute([(int)$target['id'], $flags['is_delivered'], $flags['is_approved'], $progressId]);

    notify_add($pdo, notify_subscribers($pdo, $progressId), [
        'kind'        => 'status',
        'actor'       => current_user_display(),
        'summary'     => $taskName . ' → ' . $target['name'],
        'project_id'  => $projectId,
        'step_id'     => (int)$step['id'],
        'item_key'    => $itemKey,
        'progress_id' => $progressId,
    ], $me);

    $kind = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    log_status_change_by_id($pdo, $projectId, $step, $itemKey, $was, (int)$target['id'], [
        'item_name'  => null,
        'shot_id'    => $kind === 's' ? $itemId : null,
        'asset_id'   => $kind === 'a' ? $itemId : null,
        'source'     => 'version',
        'changed_by' => current_user_display(),
    ]);
    return 'Status set to ' . $target['name'] . '.';
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

// --- make a review ------------------------------------------------------------
//
// David, 2026-10-22: supervisors, Production and admins review a version rather
// than put one up. A review is a comment on this task that names its version
// (migration 065), so board_comment_add() does the saving, the mentions, the
// bell and the email, and save_ticket_attachments() takes the pictures — pasted
// or picked, they arrive as the same files[] field.
if (isset($_POST['add_review'])) {
    if (!board_is_reviewer()) {
        $bad = 'Reviews are made by supervisors, Production and admins.';
    } elseif (!board_reviews_available($pdo)) {
        $bad = 'Reviews need database update 065.';
    } else {
        $files = isset($_FILES['attachments']) ? normalize_files_array($_FILES['attachments']) : [];
        $hasFiles = (bool)array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        $pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
        $pq->execute([$projectId]);
        $project = $pq->fetch() ?: ['code' => '', 'name' => ''];
        try {
            [$commentId, $said] = board_comment_add(
                $pdo, $projectId, $stepId, $itemKey, (string)($_POST['body'] ?? ''), null, $userId,
                [
                    'step_name'    => (string)$detail['step']['name'],
                    'item_name'    => (string)$detail['item']['name'],
                    'project_code' => (string)$project['code'],
                    'project_name' => (string)$project['name'],
                ],
                $hasFiles, (int)($_POST['version_id'] ?? 0) ?: null
            );
            if ($hasFiles) {
                $res = save_ticket_attachments($pdo, $files, 0, $commentId, $userId);
                if ($res['saved'])  $said .= ' ' . $res['saved'] . ' file(s) attached.';
                if ($res['errors']) $said .= ' ' . implode(' ', $res['errors']);
            }
        } catch (Throwable $e) {
            $bad = $e->getMessage();
        }
    }
// --- take the latest version back -------------------------------------------
//
// ⚠ THE LATEST ONLY, and that is a rule rather than an oversight. Versions are
// quoted at each other ("looks good on v03"), so removing one from the middle
// would leave a hole in a sequence people are using as a shared reference. The
// top one is the only one nobody has had time to refer to yet.
} elseif (isset($_POST['delete_version'])) {
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
    $link = trim((string)($_POST['link'] ?? ''));

    // THE SHOW'S NAMING CONVENTION (migration 067, set on the Brief). Every
    // file must follow it and carry the next version number, or nothing is
    // saved — David: "otherwise it block the ability to upload that file". The
    // form checks the same rule as files are picked; this is the lock behind it.
    $namingBad = [];
    $rule = naming_rule($pdo, $projectId, $stepId, $itemKey);
    if ($rule && $any) {
        $pidNow = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
        $top = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM progress_versions WHERE progress_id = ?');
        $top->execute([$pidNow]);
        $names = [];
        foreach ($files as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $names[] = (string)$f['name'];
        }
        $namingBad = naming_check($rule, $names, (int)$top->fetchColumn() + 1,
                                  naming_initials(current_user_display()));
    }

    if (!$any) {
        $bad = 'Pick a file first — a version is a file.';
    } elseif (trim((string)($_POST['note'] ?? '')) === '') {
        // Required since 2026-10-22, here as well as on the form: a browser
        // without the `required` check, or a script, must not slip one past.
        $bad = 'Say what changed — a version needs a note so the reviewer knows what to look at.';
    } elseif ($link !== '' && !preg_match('~^https?://~i', $link)) {
        // Only a web address: anything else in an href is either a typo or a
        // javascript: link waiting for a click.
        $bad = 'The link has to be a web address starting with https:// — nothing was saved.';
    } elseif ($namingBad) {
        $bad = 'Nothing was uploaded. ' . implode(' ', $namingBad);
    } else {
        // The progress row is created if the importer never made one, exactly
        // as a comment or a first status does.
        $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey, true);
        $res = save_task_versions($pdo, $files, $pid, $userId, (string)($_POST['note'] ?? ''));
        if ($res['saved'] > 0 && $link !== '' && board_version_links_available($pdo)) {
            // The link belongs to what this upload just made: the top $saved
            // versions. Copied to the task's own Link (migration 057) as well, so
            // the board column shows where the latest files are.
            $pdo->prepare('UPDATE progress_versions SET link = ? WHERE progress_id = ? AND version_no > ?')
                ->execute([mb_substr($link, 0, 500), $pid, $res['latest'] - $res['saved']]);
            if (column_exists($pdo, 'progress', 'link')) {
                $pdo->prepare('UPDATE progress SET link = ? WHERE id = ?')->execute([mb_substr($link, 0, 500), $pid]);
            }
        }
        if ($res['saved'] > 0) {
            $label = version_label($res['latest']);
            $said = $res['saved'] === 1
                ? ($label . ' sent for review.')
                : ($res['saved'] . ' versions sent for review, now at ' . $label . '.');

            // The assignee's upload moves the task to Pending review (2026-10-29).
            $moved = board_version_to_review($pdo, $projectId, $detail['step'], $itemKey, $pid,
                trim((string)$detail['step']['name'] . ' on ' . (string)$detail['item']['name'], ' on'));
            if ($moved !== '') $said .= ' ' . $moved;

            // --- tell anybody the note named -------------------------------
            //
            // David, 2026-10-22: "make sure we can tag people in the versions".
            // The same promise a comment makes, through the same two doors: the
            // bell first (always there next time they open Anima), the email
            // second (a courtesy that may fail, and says so). Once per upload,
            // however many files it carried — it is one request for review.
            $note = trim((string)($_POST['note'] ?? ''));
            $named = $note !== '' ? ticket_parse_mentions($note, board_mentionable($pdo, $projectId)) : [];
            if ($named) {
                $pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
                $pq->execute([$projectId]);
                $project = $pq->fetch() ?: ['code' => '', 'name' => ''];
                $stepName = (string)$detail['step']['name'];
                $itemName = (string)$detail['item']['name'];

                notify_add($pdo, array_keys($named), [
                    'kind'        => 'mention',
                    'actor'       => current_user_display(),
                    'summary'     => $label . ' of ' . trim($stepName . ' on ' . $itemName, ' on '),
                    'project_id'  => $projectId,
                    'step_id'     => $stepId,
                    'item_key'    => $itemKey,
                    'progress_id' => $pid,
                ], (int)(effective_crew_id($pdo) ?? 0));

                [, $more] = notify_mentioned_task($pdo, array_keys($named),
                    $label . ' is up for review: ' . $note, current_user_display(), [
                        'project_id'   => $projectId, 'step_id' => $stepId, 'item_key' => $itemKey,
                        'step_name'    => $stepName,  'item_name' => $itemName,
                        'project_code' => (string)$project['code'],
                        'project_name' => (string)$project['name'],
                    ]);
                if ($more !== '') $said .= ' ' . $more;
            }
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
