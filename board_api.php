<?php
// Saving one cell of the Board, in place, without a page reload.
//
// The board is the screen that replaces Plaky, so the thing it has to be good
// at is the thing everybody does all day: moving a status. The MSD export says
// so plainly — Status is filled on all 147 task rows, Assignee on 71, Due on
// 69, Link on 6 and Notes on 2. Everything here is built around that ranking.
//
// ⚠ THE ACTION IS A FIELD NAME, not a value, and that is deliberate: it is the
// shape artist_may_post() in includes/auth.php reads. An artist may post
// `set_status` here and nothing else, which is enforced in ONE place rather
// than re-argued per branch below.
//
// ⚠ A WRITE TO `progress` MUST ALSO RECOMPUTE is_delivered / is_approved, the
// flags every dashboard figure counts. production_api.php learned this: writing
// status_id alone moves the colour and leaves every number unchanged, which is
// worse than not writing at all because it looks like it worked.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/grid.php';
require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/activity.php';
require_login_json();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('This endpoint only accepts POST.');
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $stepId    = (int)($_POST['step_id'] ?? 0);
    $itemKey   = trim((string)($_POST['item_key'] ?? ''));

    // ---- a shot's or an asset's OWN note -----------------------------------
    //
    // ⚠ HANDLED BEFORE THE STEP IS READ, because it has none. `shots.notes` and
    // `assets.notes` describe the THING ("the client hates this angle") while
    // `progress.notes` describes one step's work on it, and the absence of a
    // step id is exactly what tells the two apart. Production only: an artist
    // may set the status of their own work and nothing else.
    if (isset($_POST['set_item_note'])) {
        if (!can_edit()) throw new RuntimeException('Your account cannot change this.');
        if (!$projectId || $itemKey === '') {
            throw new RuntimeException('A project and an item are both required.');
        }
        $kind = substr($itemKey, 0, 1);
        $itemId = (int)substr($itemKey, 1);
        if (!in_array($kind, ['s', 'a'], true) || $itemId <= 0) {
            // A whole-show deliverable IS a task, so its note is the task's.
            throw new RuntimeException('That item has no note of its own.');
        }
        $table = $kind === 's' ? 'shots' : 'assets';
        $own = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND project_id = ?");
        $own->execute([$itemId, $projectId]);
        if (!$own->fetchColumn()) throw new RuntimeException('That item is not part of this project.');

        $value = trim((string)($_POST['value'] ?? ''));
        if (mb_strlen($value) > 2000) {
            throw new RuntimeException('That is longer than this field holds (2000 characters).');
        }
        $pdo->prepare("UPDATE {$table} SET notes = ? WHERE id = ? AND project_id = ?")
            ->execute([$value === '' ? null : $value, $itemId, $projectId]);
        echo json_encode(['ok' => true, 'value' => $value, 'message' => 'Saved.']);
        exit;
    }

    if (!$projectId || !$stepId || $itemKey === '') {
        throw new RuntimeException('A project, a step and an item are all required.');
    }

    // ---- the row named must really be this project's -----------------------
    //
    // Every id below arrives from the browser. The board greys out what does
    // not apply, but the screen is a convenience and this is the guard.
    $chk = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $chk->execute([$stepId, $projectId]);
    $step = $chk->fetch();
    if (!$step) throw new RuntimeException('That step is not part of this project.');

    $kind   = substr($itemKey, 0, 1);
    $itemId = (int)substr($itemKey, 1);
    if (!in_array($kind, ['s', 'a', 'p'], true) || $itemId <= 0) {
        throw new RuntimeException('That item reference is not one this screen can set.');
    }
    $scope   = ['s' => 'shot', 'a' => 'asset', 'p' => 'project'][$kind];
    $itemRow = ['asset_type' => null];

    if ($kind === 'p') {
        if ($itemId !== $projectId) {
            throw new RuntimeException('That deliverable belongs to a different project.');
        }
    } else {
        $table = $kind === 's' ? 'shots' : 'assets';
        $own = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND project_id = ?");
        $own->execute([$itemId, $projectId]);
        if (!$own->fetchColumn()) throw new RuntimeException('That item is not part of this project.');
        if ($kind === 'a') {
            $t = $pdo->prepare('SELECT asset_type FROM assets WHERE id = ?');
            $t->execute([$itemId]);
            $itemRow['asset_type'] = $t->fetchColumn() ?: null;
        }
    }

    if (!step_covers_item($step, $itemRow, $scope)) {
        throw new RuntimeException('"' . $step['name'] . '" does not apply to that item.');
    }

    // The cell as it stands. Read once, before anything is written: a log that
    // cannot say what a thing changed FROM is half a log.
    $cur = $pdo->prepare('SELECT * FROM progress WHERE step_id = ? AND item_key = ?');
    $cur->execute([$stepId, $itemKey]);
    $cell = $cur->fetch() ?: null;

    /**
     * Is this task the asker's own? The second lock, beside artist_may_post():
     * that says which action is allowed, this says whose row it may touch.
     * Production and admins pass; everybody else must be named on the row.
     */
    $isMine = function () use ($pdo, $cell): bool {
        if (can_edit()) return true;
        $mine = effective_crew_id($pdo);
        if (!$mine || !$cell) return false;
        if ((int)$cell['crew_id'] === (int)$mine) return true;
        $q = $pdo->prepare('SELECT 1 FROM progress_crew WHERE progress_id = ? AND crew_id = ?');
        $q->execute([(int)$cell['id'], (int)$mine]);
        return (bool)$q->fetchColumn();
    };

    /** Write the cell, creating it when the importer never made one. */
    $upsert = function (array $set) use ($pdo, $projectId, $stepId, $itemKey, $kind, $itemId) {
        $cols = array_keys($set);
        $place = implode(', ', array_fill(0, count($cols), '?'));
        $dupes = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols));
        $sql = 'INSERT INTO progress (project_id, step_id, shot_id, asset_id, item_key, '
             . implode(', ', $cols) . ') VALUES (?, ?, ?, ?, ?, ' . $place . ')'
             . ' ON DUPLICATE KEY UPDATE ' . $dupes;
        $pdo->prepare($sql)->execute(array_merge([
            $projectId, $stepId,
            $kind === 's' ? $itemId : null,
            $kind === 'a' ? $itemId : null,
            $itemKey,
        ], array_values($set)));
    };

    // ---- the status, the one everybody uses --------------------------------
    if (isset($_POST['set_status'])) {
        $raw = $_POST['status_id'] ?? '';
        $statusId = ($raw === '' || $raw === null) ? null : (int)$raw;

        $map = statuses_by_id($pdo, $projectId);
        if ($statusId !== null && !isset($map[$statusId])) {
            throw new RuntimeException('That status is not part of this project.');
        }
        $status = $statusId !== null ? $map[$statusId] : null;

        // ⚠ THE SAME TWO-STATUS LIMIT MY TASKS KEEPS (David, 2026-10-21).
        // Written once in includes/statuses.php so an artist gets one answer
        // wherever they are, and a hand-made POST cannot get round the greyed
        // options the board draws.
        if (!status_self_serve($status)) throw new RuntimeException(status_self_serve_refusal());
        if (!$isMine()) throw new RuntimeException('That task is not assigned to you.');

        $was = $cell && $cell['status_id'] !== null ? (int)$cell['status_id'] : null;
        $flags = status_flags($status);
        $upsert([
            'status_id'    => $statusId,
            'is_delivered' => $flags['is_delivered'],
            'is_approved'  => $flags['is_approved'],
        ]);

        log_status_change_by_id($pdo, $projectId, $step, $itemKey, $was, $statusId, [
            'item_name'  => null,
            'shot_id'    => $kind === 's' ? $itemId : null,
            'asset_id'   => $kind === 'a' ? $itemId : null,
            'source'     => 'board',
            'changed_by' => current_user_display(),
        ]);

        echo json_encode([
            'ok'       => true,
            'name'     => $status['name'] ?? null,
            'color'    => $status ? ($status['color'] ?: '') : '',
            'approved' => $flags['is_approved'] === 1,
            'excluded' => $status !== null && (int)($status['is_excluded'] ?? 0) === 1,
            'message'  => $status ? ('Set to ' . $status['name'] . '.') : 'Status cleared.',
        ]);
        exit;
    }

    // Everything past here is Production's. require_login_json() has already
    // refused the POST for anybody else; this is the second lock, so the reason
    // sits beside the code it protects.
    if (!can_edit()) throw new RuntimeException('Your account cannot change this.');

    // ---- who it belongs to -------------------------------------------------
    if (isset($_POST['set_crew'])) {
        $raw = trim((string)($_POST['crew_id'] ?? ''));
        $crewId = $raw === '' ? null : (int)$raw;
        if ($crewId !== null) {
            // A real person, never a slot: "Animation 02" is a position, and a
            // task cannot be told it belongs to one.
            $q = $pdo->prepare(
                'SELECT name FROM crew
                  WHERE id = ? AND COALESCE(is_placeholder, 0) = 0 AND COALESCE(is_team, 0) = 0'
            );
            $q->execute([$crewId]);
            $name = $q->fetchColumn();
            if (!$name) throw new RuntimeException('That is not somebody a task can be given to.');
        }
        $upsert(['crew_id' => $crewId]);
        echo json_encode([
            'ok'      => true,
            'crew_id' => $crewId,
            'person'  => $crewId !== null ? $name : null,
            'message' => $crewId !== null ? ('Assigned to ' . $name . '.') : 'Nobody on it now.',
        ]);
        exit;
    }

    // ---- the due date, which is the timeline's own -------------------------
    //
    // ⚠ ONE DATE, NOT TWO (David, 2026-10-22: "let's use the built in one").
    // This writes schedule.end_date, the date the Gantt draws, and marks the
    // row hand-planned exactly as dragging its bar does — so Regenerate will
    // not quietly overwrite what somebody typed here. Plaky's due date agrees
    // with nothing; this one moves the plan.
    if (isset($_POST['set_due'])) {
        $due = trim((string)($_POST['due'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new RuntimeException('That is not a date this screen can read.');
        }

        $q = $pdo->prepare('SELECT * FROM schedule WHERE step_id = ? AND item_key = ?');
        $q->execute([$stepId, $itemKey]);
        $row = $q->fetch() ?: null;

        if ($due === '') {
            // Clearing a date removes the hand-planned row rather than storing
            // an empty one: schedule.end_date is NOT NULL, and a task with no
            // dates is a task the scheduler may plan again.
            if ($row) $pdo->prepare('DELETE FROM schedule WHERE id = ?')->execute([(int)$row['id']]);
            echo json_encode(['ok' => true, 'due' => null, 'message' => 'Date cleared.']);
            exit;
        }

        // A start date is required by the table. Keep the one the plan already
        // had; failing that the step's own start; failing that the due date, a
        // one-day task. Never after the end — that would draw backwards.
        $start = $row['start_date'] ?? ($step['start_date'] ?: null);
        if ($start === null || $start > $due) $start = $due;

        if ($row) {
            $pdo->prepare(
                "UPDATE schedule SET start_date = ?, end_date = ?, is_manual = 1, source = 'manual'
                  WHERE id = ?"
            )->execute([$start, $due, (int)$row['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO schedule (project_id, step_id, shot_id, asset_id, item_key,
                                       start_date, end_date, is_manual, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'manual')"
            )->execute([
                $projectId, $stepId,
                $kind === 's' ? $itemId : null,
                $kind === 'a' ? $itemId : null,
                $itemKey, $start, $due,
            ]);
        }

        echo json_encode([
            'ok'      => true,
            'due'     => $due,
            'late'    => $due < date('Y-m-d'),
            'message' => 'Due ' . date('j M', strtotime($due)) . ', and the timeline moved with it.',
        ]);
        exit;
    }

    // ---- the free text: a link, a note, a version label --------------------
    if (isset($_POST['set_text'])) {
        $field = (string)($_POST['field'] ?? '');
        $limits = ['link' => 500, 'notes' => 2000, 'version' => 40];
        if (!isset($limits[$field])) throw new RuntimeException('That is not a field this screen sets.');
        if (!column_exists($pdo, 'progress', $field)) {
            throw new RuntimeException($field === 'link'
                ? 'Links need database update 057, which has not been applied yet.'
                : 'That field is not in this database yet.');
        }

        $value = trim((string)($_POST['value'] ?? ''));
        if ($value !== '' && mb_strlen($value) > $limits[$field]) {
            throw new RuntimeException('That is longer than this field holds ('
                . $limits[$field] . ' characters).');
        }
        // A link is followed by people, so only the two schemes a browser should
        // open are stored. "javascript:" in an href is the reason this check is
        // here and not merely on the way out.
        if ($field === 'link' && $value !== '' && !preg_match('#^https?://#i', $value)) {
            throw new RuntimeException('A link has to start with http:// or https://.');
        }

        $upsert([$field => $value === '' ? null : $value]);
        echo json_encode(['ok' => true, 'value' => $value, 'message' => 'Saved.']);
        exit;
    }

    throw new RuntimeException('Nothing to save.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
