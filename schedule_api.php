<?php
// Editing a single scheduled task: dragging a bar, resizing it, or saving the
// editor modal. JSON in, JSON out.
//
// Every write here sets is_manual = 1. Moving a bar by hand IS the statement
// that the scheduler should stop deciding this one — if a re-run then shoved it
// back, the drag would look like it hadn't saved, which is the silent-failure
// pattern this app keeps stamping out.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login_json();

header('Content-Type: application/json');

function api_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// PROGRESS — a step's percentage, typed on the step editor or dragged with the
// bar's progress handle (migration 036, 2026-09-18). A number somebody
// recorded, not a count: the tracking grid's count is shown beside it on the
// editor and is never written from here.
// ---------------------------------------------------------------------------

/** 0 to 100 from the request, or a refusal saying what was wrong. */
function progress_pct_from_post(): int {
    $raw = trim((string)($_POST['progress_pct'] ?? ''));
    if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) {
        api_fail('Progress has to be a number from 0 to 100.');
    }
    return (int)round((float)$raw);
}

/** "Set by David Salinas on 2026-09-18": the same shape as days_source. */
function progress_set_by(): string {
    $who = trim(current_user_display());
    return mb_substr(sprintf('Set by %s on %s', $who !== '' ? $who : 'somebody', date('Y-m-d')), 0, 120);
}

const PROGRESS_NEEDS_036 = 'Recording progress needs database update 036, so nothing was saved. '
                         . 'An administrator can apply it from Migrations.';

if ($action === 'step_progress') {
    $stepId    = (int)($_POST['step_id'] ?? 0);
    $projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
    if ($stepId <= 0 || $projectId <= 0) api_fail('No step given.');
    if (!column_exists($pdo, 'pipeline_steps', 'progress_pct')) api_fail(PROGRESS_NEEDS_036);
    $pct = progress_pct_from_post();

    $chk = $pdo->prepare(
        'SELECT name, progress_pct, progress_source FROM pipeline_steps WHERE id = ? AND project_id = ?'
    );
    $chk->execute([$stepId, $projectId]);
    $row = $chk->fetch();
    if (!$row) api_fail('That step is not on this project.');

    // Only a CHANGED number is re-attributed, so "set by" always names whoever
    // last actually moved it.
    $source = (string)($row['progress_source'] ?? '');
    if ($pct !== (int)$row['progress_pct']) {
        $source = progress_set_by();
        $pdo->prepare(
            'UPDATE pipeline_steps SET progress_pct = ?, progress_source = ? WHERE id = ? AND project_id = ?'
        )->execute([$pct, $source, $stepId, $projectId]);
    }
    echo json_encode([
        'ok'       => true,
        'step'     => $row['name'],
        'progress' => $pct,
        'progress_source' => $source,
        'note'     => $row['name'] . ': ' . $pct . '% done',
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// STEP DATES — the general timeline's bars, which ARE Dates & headcount.
//
// A different table from everything below: this writes pipeline_steps, not
// schedule. It lives here anyway because it is the same screen's save endpoint,
// and one door with two actions is easier to keep honest than two doors that
// have to agree about auth, scoping and error shape.
//
// Deliberately NOT dependency-checked. David plans the general timeline to the
// dates and accepts that a dependency-respecting schedule may run longer
// (2026-08-31); a violated arrow is drawn red in the browser and that is all.
// Refusing the drag here would be a wall where a warning was asked for.
// ---------------------------------------------------------------------------
// step_edit is the step editor's Save: the same dates, plus the progress when
// the editor sends one. One request, so the two can never half-save.
if ($action === 'step_dates' || $action === 'step_edit') {
    $stepId    = (int)($_POST['step_id'] ?? 0);
    $projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
    if ($stepId <= 0 || $projectId <= 0) api_fail('No step given.');

    $start = trim((string)($_POST['start_date'] ?? ''));
    $end   = trim((string)($_POST['end_date'] ?? ''));
    // Y-m-d and a real calendar date, not just the right shape: 2026-02-31
    // matches any regex and is not a day.
    foreach ([$start, $end] as $d) {
        $parts = explode('-', $d);
        if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            api_fail('That is not a date this app can store.');
        }
    }
    if ($end < $start) api_fail('A step cannot end before it starts.');

    // Before 036 the editor sends no progress and says why; a request that
    // sends one anyway is refused whole rather than saving only the dates.
    $progressReady = column_exists($pdo, 'pipeline_steps', 'progress_pct');
    $pct = null;
    if ($action === 'step_edit' && array_key_exists('progress_pct', $_POST)) {
        if (!$progressReady) api_fail(PROGRESS_NEEDS_036);
        $pct = progress_pct_from_post();
    }

    // Scoped to the project the caller names, exactly as the task update below
    // is — a step id from another show must not be writable from this page.
    $chk = $pdo->prepare(
        'SELECT name' . ($progressReady ? ', progress_pct, progress_source' : '')
      . ' FROM pipeline_steps WHERE id = ? AND project_id = ?'
    );
    $chk->execute([$stepId, $projectId]);
    $row = $chk->fetch();
    if (!$row) api_fail('That step is not on this project.');
    $name = $row['name'];

    $set  = 'start_date = ?, deadline = ?';
    $args = [$start, $end];
    $progress = $progressReady ? (int)$row['progress_pct'] : 0;
    $source   = $progressReady ? (string)($row['progress_source'] ?? '') : '';
    // Only a CHANGED number is re-attributed: saving new dates must not stamp
    // "set by" on a progress nobody touched.
    if ($pct !== null && $pct !== $progress) {
        $progress = $pct;
        $source   = progress_set_by();
        $set     .= ', progress_pct = ?, progress_source = ?';
        $args[]   = $progress;
        $args[]   = $source;
    }
    $args[] = $stepId;
    $args[] = $projectId;
    $pdo->prepare('UPDATE pipeline_steps SET ' . $set . ' WHERE id = ? AND project_id = ?')
        ->execute($args);

    echo json_encode([
        'ok'    => true,
        'step'  => $name,
        'start' => $start,
        'end'   => $end,
        'progress' => $progress,
        'progress_source' => $source,
        // Said back so the browser can show what it actually changed rather than
        // assuming the write matched the drag.
        'note'  => $name . ': ' . $start . ' to ' . $end
                 . ($pct !== null ? ', ' . $progress . '% done' : ''),
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// MILESTONE DATE — a dragged marker on the general chart.
//
// Date only. The name and notes are edited in the modal, which posts to
// timeline.php and reloads; a drag must not reload, or the gesture would end
// with the chart jumping back to the top at a different scroll and zoom.
// ---------------------------------------------------------------------------
if ($action === 'milestone_date') {
    $msId      = (int)($_POST['id'] ?? 0);
    $projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
    if ($msId <= 0 || $projectId <= 0) api_fail('No milestone given.');

    $date  = trim((string)($_POST['date'] ?? ''));
    $parts = explode('-', $date);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        api_fail('That is not a date this app can store.');
    }

    // Scoped to the project named, exactly as the two actions below are: an id
    // from another show must not be writable from this page.
    $upd = $pdo->prepare(
        'UPDATE project_milestones SET milestone_date = ? WHERE id = ? AND project_id = ?'
    );
    $upd->execute([$date, $msId, $projectId]);
    if ($upd->rowCount() === 0) {
        // Either it is gone or it belongs elsewhere. Both are "not yours to
        // move", and saying which would tell a caller what exists.
        api_fail('That milestone is not on this project.');
    }
    echo json_encode(['ok' => true, 'date' => $date]);
    exit;
}

if ($action !== 'update') api_fail('Unknown action.');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) api_fail('No task given.');

// Scoped to the project the caller says it is editing. Without this, a task id
// from any project would be writable from any other project's page — everything
// else in this app is scoped that way and an endpoint that isn't would be the
// hole that outlasts the habit.
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
if ($projectId <= 0) api_fail('No project given.');

$stmt = $pdo->prepare(
    'SELECT sc.*, ps.name AS step_name
       FROM schedule sc JOIN pipeline_steps ps ON ps.id = sc.step_id
      WHERE sc.id = ? AND sc.project_id = ?'
);
$stmt->execute([$id, $projectId]);
$task = $stmt->fetch();
if (!$task) api_fail('That task no longer exists, reload the page.', 404);

$start = trim($_POST['start_date'] ?? '');
$end   = trim($_POST['end_date'] ?? '');
foreach ([$start, $end] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) api_fail('Dates must be YYYY-MM-DD.');
}
// Caught here as well as in the browser: the fetch can be replayed, and an
// inverted range would store a task that renders as a negative-width bar.
if ($end < $start) api_fail('The end date cannot be before the start date.');

// crew_id: ABSENT means unchanged, an empty string means nobody. The editor
// always sends the field, its blank option included, so it can clear an
// assignment; a drag sends only the dates.
//
// ⚠ Until 2026-09-18 a missing field was read as "nobody", so every task ever
// dragged on the detailed chart lost its person or slot, silently, and the lock
// the same drag set kept Regenerate from ever giving it back. An absent field is
// not an empty one.
$crewId = $task['crew_id'] !== null ? (int)$task['crew_id'] : null;
if (array_key_exists('crew_id', $_POST)) {
    $rawCrew = trim((string)$_POST['crew_id']);
    $crewId  = null;
    if ($rawCrew !== '') {
        $crewId = (int)$rawCrew;
        $check = $pdo->prepare('SELECT id FROM crew WHERE id = ?');
        $check->execute([$crewId]);
        if (!$check->fetch()) api_fail('That person is not on the crew list.');
    }
}

// is_manual defaults to 1 for the reason in the header comment, but the editor
// can explicitly hand a task back to the scheduler by unticking the lock.
$isManual = array_key_exists('is_manual', $_POST) ? ((int)$_POST['is_manual'] === 1 ? 1 : 0) : 1;

try {
    // source follows is_manual in BOTH directions. Letting it keep saying
    // 'manual' after a task was unlocked left the row in a state that neither
    // the scheduler's delete nor its hand-planned lookup matched — see the
    // comment on the DELETE in commit_schedule().
    $pdo->prepare(
        "UPDATE schedule
            SET start_date = ?, end_date = ?, crew_id = ?, is_manual = ?,
                source = CASE WHEN ? = 1 THEN 'manual' ELSE 'auto' END
          WHERE id = ?"
    )->execute([$start, $end, $crewId, $isManual, $isManual, (int)$task['id']]);
} catch (Throwable $e) {
    api_fail('Could not save: ' . $e->getMessage(), 500);
}

echo json_encode([
    'ok'         => true,
    'id'         => $id,
    'start_date' => $start,
    'end_date'   => $end,
    'crew_id'    => $crewId,
    'is_manual'  => $isManual,
]);
