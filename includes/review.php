<?php
// Reviews: the queue of work waiting on the person looking at it, and the two
// verdicts that move it on (David, 2026-10-27, from the roadmap's "Final
// internal review in Spell").
//
// TWO STAGES, BY STATUS NAME, settled with David the same day:
//
//   artist puts work up        → Pending review
//   a SUPERVISOR reviews it    → Ready for review   (or sends it back)
//   ADMINS / PRODUCTION review → Complete           (or send it back)
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

/**
 * The queue: every task in this account's "from" status, on the shows it can
 * see, with its current version. Newest version first, because the thing that
 * just arrived is the thing somebody is waiting to hear about.
 */
function review_queue(PDO $pdo, string $stage): array {
    $ids = review_project_ids($pdo);
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
            AND LOWER(TRIM(st.name)) = ?'
    );
    $q->execute([REVIEW_STAGES[$stage]['from']]);
    $rows = [];
    foreach ($q as $r) {
        $r['item_name'] = $r['shot_code'] !== null
            ? (trim((string)$r['episode']) !== '' ? $r['episode'] . ' ' : '') . $r['shot_code']
            : ($r['asset_name'] !== null ? (string)$r['asset_name'] : 'Whole project');
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
 * The statuses a task can be SENT BACK to: everything on the show except the
 * two this stage moves between and anything that counts as approved. The first
 * one that reads like a retake is offered first.
 */
function review_back_statuses(PDO $pdo, int $projectId, string $stage): array {
    $skip = [REVIEW_STAGES[$stage]['from'], REVIEW_STAGES[$stage]['to']];
    $out = [];
    foreach (project_statuses($pdo, $projectId) as $s) {
        if (in_array(review_norm((string)$s['name']), $skip, true)) continue;
        if (($s['maps_to'] ?? '') === 'approved') continue;
        $out[] = $s;
    }
    usort($out, function ($a, $b) {
        $score = fn($s) => preg_match('/retake|change|revis|fix|redo/i', (string)$s['name']) ? 0
                        : (preg_match('/in progress/i', (string)$s['name']) ? 1 : 2);
        return $score($a) <=> $score($b);
    });
    return $out;
}

/**
 * Approve a task, or send it back. Returns the sentence for the flash.
 *
 * Every id arrives from the browser, so all of it is checked here: the show is
 * one this account sees, the task is in THIS account's queue right now (so a
 * stale page cannot approve something somebody already moved), and the target
 * status is the show's own.
 */
function review_apply(PDO $pdo, int $progressId, string $verdict, string $note, int $backStatusId): string {
    $stage = review_stage();
    if (!$stage) throw new RuntimeException('Your account does not review work.');
    $cfg = REVIEW_STAGES[$stage];

    $q = $pdo->prepare('SELECT pr.*, st.name AS status_name FROM progress pr
                          LEFT JOIN statuses st ON st.id = pr.status_id WHERE pr.id = ?');
    $q->execute([$progressId]);
    $task = $q->fetch();
    if (!$task || !in_array((int)$task['project_id'], review_project_ids($pdo), true)) {
        throw new RuntimeException('That task is not one you can review.');
    }
    if (review_norm((string)$task['status_name']) !== $cfg['from']) {
        throw new RuntimeException('That task is no longer waiting for your review, somebody has already moved it.');
    }
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
    } elseif ($verdict === 'back') {
        if ($note === '') throw new RuntimeException('Say what needs changing: sending work back needs a note.');
        $target = null;
        foreach (review_back_statuses($pdo, $projectId, $stage) as $s) {
            if ((int)$s['id'] === $backStatusId) $target = $s;
        }
        if (!$target) throw new RuntimeException('Pick the status to send it back to.');
    } else {
        throw new RuntimeException('Approve or send back, nothing else.');
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
        : $taskName . ' sent back to ' . $target['name'] . ', with your note.';
}
