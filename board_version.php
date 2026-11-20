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
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/digest.php';   // digest_told_sentence(): who will hear of an @
require_once __DIR__ . '/includes/naming.php';   // the show's file naming convention (067)
require_once __DIR__ . '/includes/review.php';   // review_apply(), for Approve / Send back in the viewer
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
 * A NEW VERSION SENDS THE TASK TO PENDING REVIEW, WHOEVER PUT IT UP.
 *
 * David, 2026-10-29: "when a new version is uploaded by the artist assign to
 * that task, let's automatically change the status to pending review" - first
 * built for the assignee only, so a producer's upload left the status alone.
 * David, 2026-11-06, widened it: "change the status to pending review whoever
 * uploads a new version". Production and admins put files up for artists too,
 * and a version waiting in a status nobody reviews is a version nobody sees.
 * The status is the show's own row called
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
    // Who did it, only so their own bell stays quiet. An admin with no person
    // linked is 0, which notify_add() reads as "tell everybody".
    $me = (int)(effective_crew_id($pdo) ?? 0);
    if ($progressId <= 0) return '';
    $q = $pdo->prepare('SELECT id, crew_id, status_id FROM progress WHERE id = ?');
    $q->execute([$progressId]);
    $row = $q->fetch();
    if (!$row) return '';

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
//
// APPROVE / RETAKE from the viewer opened on a task (David, 2026-10-29) arrive
// here as add_review with a `verdict`: the status moves through review_apply(),
// the same rules and checks as the Reviews screen, and the Versions pane comes
// back redrawn. A note or drawing sent with it is posted as the review on the
// version being looked at; the viewer's buttons send none.
if (isset($_POST['add_review'])) {
    $verdict = (string)($_POST['verdict'] ?? '');
    $verdictSaid = '';
    if ($verdict !== '') {
        try {
            if (!in_array($verdict, ['approve', 'retake'], true)) throw new RuntimeException('Approve or Retake, nothing else.');
            $vpid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
            // No note here: any text is posted below, on the version on screen.
            $verdictSaid = review_apply($pdo, $vpid, $verdict, '');
        } catch (Throwable $e) {
            $bad = $e->getMessage();
        }
    }
    $files0 = isset($_FILES['attachments']) ? normalize_files_array($_FILES['attachments']) : [];
    $hasAny = trim((string)($_POST['body'] ?? '')) !== ''
           || (bool)array_filter($files0, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if ($bad !== '') {
        // the verdict was refused: nothing else is posted
    } elseif ($verdict !== '' && !$hasAny) {
        $said = $verdictSaid;
    } elseif (!board_is_reviewer()) {
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
            // WHICH FRAME, AND WHERE THE PIN IS (David, 2026-11-19: several
            // notes at once, each on its frame, with a numbered pin "like
            // Frame.io"). The viewer sends each note of a batch as its own
            // add_review, so this is one note's frame and pin. The pin is a
            // fraction of the picture, 0 to 1 each way; anything else is
            // dropped rather than stored. Before migration 105 there is nowhere
            // to keep them: the frame is still in the text, the pin still
            // painted into the picture, so nothing is lost but the clickable pin.
            // ⚠ NEVER A FAILURE: the note is saved by now, and a red line here
            // would make the viewer keep it and send it a second time.
            if (board_review_pins_available($pdo)) try {
                $frame = (string)($_POST['frame'] ?? '');
                $px = (string)($_POST['pin_x'] ?? '');
                $py = (string)($_POST['pin_y'] ?? '');
                $frame = ctype_digit($frame) ? (int)$frame : null;
                $pin = is_numeric($px) && is_numeric($py)
                    && (float)$px >= 0 && (float)$px <= 1 && (float)$py >= 0 && (float)$py <= 1;
                if ($frame !== null || $pin) {
                    $pdo->prepare('UPDATE ticket_comments SET review_frame = ?, pin_x = ?, pin_y = ? WHERE id = ?')
                        ->execute([$frame, $pin ? round((float)$px, 5) : null, $pin ? round((float)$py, 5) : null,
                                   $commentId]);
                }
            } catch (Throwable $e) {
                // the note stands without its clickable pin; the picture still shows it
            }
            if ($hasFiles) {
                $res = save_ticket_attachments($pdo, $files, 0, $commentId, $userId);
                if ($res['saved'])  $said .= ' ' . $res['saved'] . ' file(s) attached.';
                if ($res['errors']) $said .= ' ' . implode(' ', $res['errors']);
            }
            if ($verdictSaid !== '') $said = $verdictSaid . ' ' . $said;
        } catch (Throwable $e) {
            $bad = $e->getMessage();
        }
    }
// --- reply to a review note ---------------------------------------------------
//
// David, 2026-11-20: "can we implement a reply to this note so we can keep the
// conversation going inside the review of any version?" A reply is a comment
// whose parent is the note (migration 039) and which names no version, so it is
// never counted as a note of its own; board_versions_html() draws it under the
// note, and the Comments tab threads it there too.
//
// ⚠ ANYBODY WHO CAN OPEN THE TASK MAY REPLY, as anybody may comment
// (board_comment.php): the artist answering "push the pose" is who this is for.
// What is checked: the note is a REVIEW NOTE ON THIS TASK. The request already
// proved the task is on a show this account can see; a note id from another
// show matches nothing here.
//
// Who hears: the note's author, everybody who has replied to it, and whoever
// put the version up — the people in that conversation — minus the writer and
// anybody the reply @names, who has just had their mention bell. Rung as a
// 'review', the bell kind that already leads to the version's notes.
} elseif (isset($_POST['add_reply'])) {
    $noteId = (int)($_POST['reply_to'] ?? 0);
    $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $note = null;
    if (ticket_threads_available($pdo) && board_reviews_available($pdo) && $pid) {
        $q = $pdo->prepare('SELECT id, user_id, version_id FROM ticket_comments
                             WHERE id = ? AND progress_id = ? AND version_id IS NOT NULL');
        $q->execute([$noteId, $pid]);
        $note = $q->fetch() ?: null;
    }
    if (!ticket_threads_available($pdo)) {
        $bad = 'Replies need database update 039.';
    } elseif (!$note) {
        $bad = 'That review note is not on this task any more.';
    } else {
        $files = isset($_FILES['attachments']) ? normalize_files_array($_FILES['attachments']) : [];
        $hasFiles = (bool)array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        $pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
        $pq->execute([$projectId]);
        $project = $pq->fetch() ?: ['code' => '', 'name' => ''];
        $ctx = [
            'step_name'    => (string)$detail['step']['name'],
            'item_name'    => (string)$detail['item']['name'],
            'project_code' => (string)$project['code'],
            'project_name' => (string)$project['name'],
        ];
        try {
            [$commentId, $said] = board_comment_add(
                $pdo, $projectId, $stepId, $itemKey, (string)($_POST['body'] ?? ''), (int)$note['id'],
                $userId, $ctx, $hasFiles, null
            );
            if ($hasFiles) {
                $res = save_ticket_attachments($pdo, $files, 0, $commentId, $userId);
                if ($res['saved'])  $said .= ' ' . $res['saved'] . ' file(s) attached.';
                if ($res['errors']) $said .= ' ' . implode(' ', $res['errors']);
            }

            // The bell for the people in the conversation. ⚠ NEVER A FAILURE:
            // the reply is saved by now, and a red line would make it look lost.
            try {
                $userIds = [(int)$note['user_id']];
                $rq = $pdo->prepare('SELECT DISTINCT user_id FROM ticket_comments WHERE parent_id = ?');
                $rq->execute([(int)$note['id']]);
                foreach ($rq->fetchAll(PDO::FETCH_COLUMN) as $u) $userIds[] = (int)$u;
                $vq = $pdo->prepare('SELECT uploaded_by FROM progress_versions WHERE id = ?');
                $vq->execute([(int)$note['version_id']]);
                $userIds[] = (int)$vq->fetchColumn();
                $userIds = array_values(array_unique(array_filter($userIds)));
                $tell = [];
                if ($userIds) {
                    $cq = $pdo->prepare('SELECT crew_id FROM users WHERE crew_id IS NOT NULL AND id IN ('
                        . implode(',', array_fill(0, count($userIds), '?')) . ')');
                    $cq->execute($userIds);
                    foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $c) $tell[(int)$c] = true;
                }
                $me = (int)(effective_crew_id($pdo) ?? 0);
                unset($tell[$me]);
                $mq = $pdo->prepare('SELECT crew_id FROM ticket_mentions WHERE comment_id = ?');
                try {
                    $mq->execute([$commentId]);
                    foreach ($mq->fetchAll(PDO::FETCH_COLUMN) as $c) unset($tell[(int)$c]);
                } catch (Throwable $e) {
                    // before the mentions table: nobody was named, nobody to skip
                }
                if ($tell) {
                    notify_add($pdo, array_keys($tell), [
                        'kind'        => 'review',
                        'actor'       => current_user_display(),
                        'summary'     => trim($ctx['step_name'] . ' on ' . $ctx['item_name'], ' on '),
                        'project_id'  => $projectId,
                        'step_id'     => $stepId,
                        'item_key'    => $itemKey,
                        'progress_id' => $pid,
                        'comment_id'  => $commentId,
                    ], $me);
                }
            } catch (Throwable $e) {
                error_log('[Anima bell] review reply: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            $bad = $e->getMessage();
        }
    }
// --- take a reply back --------------------------------------------------------
//
// Yours, or an admin's: the rule board_comment.php keeps for every comment, and
// the same refusal when somebody else has answered it (only reachable from the
// Comments tab, which threads replies to replies). Its files leave the server
// first, while their stored names are still readable.
} elseif (isset($_POST['delete_reply'])) {
    $rid = (int)$_POST['delete_reply'];
    $pid = (int)board_task_progress_id($pdo, $projectId, $stepId, $itemKey);
    $q = $pdo->prepare('SELECT id, user_id FROM ticket_comments
                         WHERE id = ? AND progress_id = ? AND version_id IS NULL AND parent_id IS NOT NULL');
    try {
        $q->execute([$rid, $pid]);
        $rp = $q->fetch();
    } catch (Throwable $e) {
        $rp = false;   // before migration 039 there are no replies to delete
    }
    if (!$rp || !$pid) {
        $bad = 'That reply is already gone.';
    } elseif (!is_admin() && ((int)$rp['user_id'] !== $userId || $userId <= 0)) {
        $bad = 'You can only delete replies you wrote yourself.';
    } else {
        $kq = $pdo->prepare('SELECT id, user_id FROM ticket_comments WHERE parent_id = ?');
        $kq->execute([$rid]);
        $kids = $kq->fetchAll();
        if (array_filter($kids, fn($k) => (int)$k['user_id'] !== $userId) && !is_admin()) {
            $bad = 'Somebody has answered this reply, so it can only be deleted by an administrator.';
        } else {
            $ids = array_merge([$rid], array_map(fn($k) => (int)$k['id'], $kids));
            try {
                $fq = $pdo->prepare('SELECT stored_name FROM ticket_attachments WHERE comment_id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ')');
                $fq->execute($ids);
                foreach ($fq->fetchAll(PDO::FETCH_COLUMN) as $stored) {
                    @unlink(upload_dir_path() . '/' . basename((string)$stored));
                }
            } catch (Throwable $e) {
                // no attachments table: no files to remove
            }
            $pdo->prepare('DELETE FROM ticket_comments WHERE id = ?')->execute([$rid]);
            $said = 'Reply deleted.';
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

            // Any upload moves the task to Pending review (2026-10-29, widened 2026-11-06).
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

                // The email is the twice-a-day summary now, which reads the bell
                // just rung (David, 2026-11-17; includes/digest.php).
                $more = digest_told_sentence($pdo, array_diff(array_keys($named), [(int)(effective_crew_id($pdo) ?? 0)]));
                if ($more !== '') $said .= ' ' . $more;
            }

            // A version put up while the task was ALREADY in review (or approved)
            // moved no status, so the bell above rang nobody. Whoever subscribed
            // to the task on Spell still wants to know (David, 2026-11-20,
            // migration 108). When the status did move, they were rung with it.
            if ($moved === '') {
                require_once __DIR__ . '/includes/task_follow.php';
                task_follow_ring($pdo, $projectId, $stepId, $itemKey, $pid,
                    trim((string)$detail['step']['name'] . ' on ' . (string)$detail['item']['name'], ' on'),
                    $label . ' put up', array_keys($named));
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
