<?php
// Reviews: the queue of work waiting on the person looking at it, and the two
// verdicts that move it on (David, 2026-10-27, from the roadmap's "Final
// internal review in Spell").
//
// TWO STAGES, BY STATUS NAME, settled with David the same day:
//
//   artist puts work up        → Pending review
//   a SUPERVISOR reviews it    → Ready for review   (or Retake)
//   ADMINS / PRODUCTION review → Complete           (or Retake)
//
// So the queue is not "delivered and not approved": it is one named status per
// reviewer, and approving moves the task to the next named status. Names, not
// ids, because statuses are each show's own rows — the same choice
// SELF_SERVE_STATUS_NAMES makes in includes/statuses.php, compared the same way
// (case and spacing ignored). A show that spells them differently has an empty
// queue and says so rather than guessing.
//
// ⚠ APPROVING IS THE STATUS MOVE AND NOTHING ELSE (David: "Task becomes
// Approved"). No second sign-off table: Complete is whatever the Statuses
// screen says it is, and is_delivered / is_approved follow from status_flags()
// exactly as they do when the status is set on the board, so every chart and
// every artist statement moves with it.
//
// A review note is the SAME comment row a "Make a review" posts (migration
// 065), on the task's current version, so the conversation about a shot stays
// in one place.

require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/review_badge.php';

// REVIEW_STAGES, review_norm(), review_stage(), review_project_ids() and
// review_count() live in includes/review_badge.php, which the top bar loads.

/** The show's status row called $name (already normalised), or null when it has none. */
function review_status_named(PDO $pdo, int $projectId, string $name): ?array {
    foreach (project_statuses($pdo, $projectId) as $s) {
        if (review_norm((string)$s['name']) === $name) return $s;
    }
    return null;
}

/** Which of this account's stages a status name puts a task in, or null. */
function review_stage_of(string $statusName): ?string {
    $name = review_norm($statusName);
    foreach (review_stages() as $k) {
        if (REVIEW_STAGES[$k]['from'] === $name) return $k;
    }
    return null;
}

/**
 * The queue: every task in one of this account's "from" statuses (both stages
 * for admins and Production, review_stages()), on the shows it can see, with
 * its current version. Each row carries 'stage', the REVIEW_STAGES key its
 * status puts it in. Newest version first, because the thing that
 * just arrived is the thing somebody is waiting to hear about.
 */
function review_queue(PDO $pdo): array {
    $from = review_from_names();
    $ids = $from ? review_project_ids($pdo) : [];
    if (!$ids) return [];
    $q = $pdo->prepare(
        'SELECT pr.id, pr.project_id, pr.step_id, pr.item_key, pr.status_id, pr.updated_at,
                p.code AS project_code, p.name AS project_name,
                ps.name AS step_name, ps.color AS step_color,
                sh.shot_code, sh.episode, a.name AS asset_name,
                c.name AS crew_name, st.name AS status_name
           FROM progress pr
           JOIN statuses st       ON st.id = pr.status_id
           JOIN projects p        ON p.id  = pr.project_id
           JOIN pipeline_steps ps ON ps.id = pr.step_id
           LEFT JOIN shots sh     ON sh.id = pr.shot_id
           LEFT JOIN assets a     ON a.id  = pr.asset_id
           LEFT JOIN crew c       ON c.id  = pr.crew_id
          WHERE pr.project_id IN (' . implode(',', $ids) . ')
            AND LOWER(TRIM(st.name)) IN (' . implode(',', array_fill(0, count($from), '?')) . ')'
    );
    $q->execute($from);
    $rows = [];
    foreach ($q as $r) {
        $r['item_name'] = $r['shot_code'] !== null
            ? (trim((string)$r['episode']) !== '' ? $r['episode'] . ' ' : '') . $r['shot_code']
            : ($r['asset_name'] !== null ? (string)$r['asset_name'] : 'Whole project');
        $r['stage'] = review_stage_of((string)$r['status_name']);
        $r['version'] = null;
        $r['notes'] = 0;
        $rows[(int)$r['id']] = $r;
    }
    if (!$rows) return [];

    // The current version of each, and how many review notes it already has.
    if (board_versions_available($pdo)) {
        $in = implode(',', array_keys($rows));
        $v = $pdo->query(
            "SELECT v.* FROM progress_versions v
               JOIN (SELECT progress_id, MAX(version_no) AS m FROM progress_versions
                      WHERE progress_id IN ($in) GROUP BY progress_id) x
                 ON x.progress_id = v.progress_id AND x.m = v.version_no"
        );
        $byVersion = [];
        foreach ($v as $vr) {
            $rows[(int)$vr['progress_id']]['version'] = $vr;
            $byVersion[(int)$vr['id']] = (int)$vr['progress_id'];
        }
        if ($byVersion && board_reviews_available($pdo)) {
            $n = $pdo->query(
                'SELECT version_id, COUNT(*) AS n FROM ticket_comments
                  WHERE version_id IN (' . implode(',', array_keys($byVersion)) . ')
                  GROUP BY version_id'
            );
            foreach ($n as $nr) $rows[$byVersion[(int)$nr['version_id']]]['notes'] = (int)$nr['n'];
        }
    }

    $rows = array_values($rows);
    usort($rows, function ($a, $b) {
        $ta = $a['version']['created_at'] ?? $a['updated_at'];
        $tb = $b['version']['created_at'] ?? $b['updated_at'];
        return strcmp((string)$tb, (string)$ta);
    });
    return $rows;
}

/**
 * RETAKE IS ONE NAMED STATUS TOO (David, 2026-10-29: "two buttons: 1. Approve
 * ... 2. Retake: It will change the status from pending review to Retake. Not
 * sure what the send back is for."). The old "Send back" offered every status
 * on the show in a dropdown; nobody used it for anything but Retake, so the
 * reviewer now has the two verdicts and nothing to pick. Matched by name like
 * REVIEW_STAGES, so a show that has no "Retake" says so instead of guessing.
 */
const REVIEW_RETAKE = 'retake';

/**
 * Approve a task, or send it to Retake. Returns the sentence for the flash.
 *
 * Every id arrives from the browser, so all of it is checked here: the show is
 * one this account sees, the task is in THIS account's queue right now (so a
 * stale page cannot approve something somebody already moved), and the target
 * status is the show's own.
 */
function review_apply(PDO $pdo, int $progressId, string $verdict, string $note = ''): string {
    if (!review_stages()) throw new RuntimeException('Your account does not review work.');

    $q = $pdo->prepare('SELECT pr.*, st.name AS status_name FROM progress pr
                          LEFT JOIN statuses st ON st.id = pr.status_id WHERE pr.id = ?');
    $q->execute([$progressId]);
    $task = $q->fetch();
    if (!$task || !in_array((int)$task['project_id'], review_project_ids($pdo), true)) {
        throw new RuntimeException('That task is not one you can review.');
    }
    // The stage is the one the task is in NOW, among those this account
    // reviews: a supervisor's list has only the first, so this is also what
    // keeps a supervisor off the final stage.
    $stage = review_stage_of((string)$task['status_name']);
    if (!$stage) {
        throw new RuntimeException('That task is no longer waiting for your review, somebody has already moved it.');
    }
    $cfg = REVIEW_STAGES[$stage];
    $projectId = (int)$task['project_id'];
    $stepId = (int)$task['step_id'];
    $itemKey = (string)$task['item_key'];

    $note = trim($note);
    if ($verdict === 'approve') {
        $target = review_status_named($pdo, $projectId, $cfg['to']);
        if (!$target) {
            throw new RuntimeException('This show has no status called "' . ucwords($cfg['to'])
                . '". Add it on the Statuses screen first.');
        }
    } elseif ($verdict === 'retake') {
        // ⚠ NO NOTE NEEDED (the open question to David, 2026-10-29). The notes
        // are written in the review viewer, on the version, before the verdict;
        // a note typed with the verdict is still posted as one.
        $target = review_status_named($pdo, $projectId, REVIEW_RETAKE);
        if (!$target) {
            throw new RuntimeException('This show has no status called "Retake". Add it on the Statuses screen first.');
        }
    } else {
        throw new RuntimeException('Approve or Retake, nothing else.');
    }

    $detail = board_task_detail($pdo, $projectId, $stepId, $itemKey);
    if (!$detail) throw new RuntimeException('That task could not be found.');

    // The note first: it is the part that matters to the artist, and the same
    // review comment the task panel shows, on the current version.
    if ($note !== '' && board_comments_available($pdo)) {
        $versionId = null;
        if (board_reviews_available($pdo) && board_versions_available($pdo)) {
            $vq = $pdo->prepare('SELECT id FROM progress_versions WHERE progress_id = ?
                                  ORDER BY version_no DESC LIMIT 1');
            $vq->execute([$progressId]);
            $versionId = (int)$vq->fetchColumn() ?: null;
        }
        $pq = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
        $pq->execute([$projectId]);
        $project = $pq->fetch() ?: ['code' => '', 'name' => ''];
        board_comment_add($pdo, $projectId, $stepId, $itemKey, $note, null, (int)($_SESSION['user_id'] ?? 0), [
            'step_name'    => (string)$detail['step']['name'],
            'item_name'    => (string)$detail['item']['name'],
            'project_code' => (string)$project['code'],
            'project_name' => (string)$project['name'],
        ], false, $versionId);
    }

    // The status, through the same flags and the same log the board writes,
    // so the dashboard, the statements and the timeline all move with it.
    $flags = status_flags($target);
    $pdo->prepare('UPDATE progress SET status_id = ?, is_delivered = ?, is_approved = ? WHERE id = ?')
        ->execute([(int)$target['id'], $flags['is_delivered'], $flags['is_approved'], $progressId]);

    $taskName = $detail['item']['name'] !== ''
        ? $detail['step']['name'] . ' on ' . $detail['item']['name'] : (string)$detail['step']['name'];
    notify_add($pdo, notify_subscribers($pdo, $progressId), [
        'kind'        => 'status',
        'actor'       => current_user_display(),
        'summary'     => $taskName . ' → ' . $target['name'],
        'project_id'  => $projectId,
        'step_id'     => $stepId,
        'item_key'    => $itemKey,
        'progress_id' => $progressId,
    ], (int)(effective_crew_id($pdo) ?? 0));

    $kind = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    log_status_change_by_id($pdo, $projectId, $detail['step'], $itemKey,
        $task['status_id'] !== null ? (int)$task['status_id'] : null, (int)$target['id'], [
            'item_name'  => null,
            'shot_id'    => $kind === 's' ? $itemId : null,
            'asset_id'   => $kind === 'a' ? $itemId : null,
            'source'     => 'review',
            'changed_by' => current_user_display(),
        ]);

    return $verdict === 'approve'
        ? $taskName . ' approved, now ' . $target['name'] . '.'
        : $taskName . ' sent to ' . $target['name'] . ($note !== '' ? ', with your note.' : '.');
}
