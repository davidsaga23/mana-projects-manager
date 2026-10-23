<?php
// The changes to a task's row that nothing else records: who it is handed to,
// when it is due, its note and its link (migration 066). Written by
// board_api.php beside each save; read by the task's Timeline tab, which merges
// them with statuses (progress_log), versions and comments.
//
// Kept in a file of its own, apart from includes/board.php, because the saving
// endpoint needs only this and the board's renderer is large.

require_once __DIR__ . '/functions.php';

/** Has migration 066 run? Until it has, nothing is logged and nothing fails. */
function task_events_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'progress_events', 'kind');
    return $ok;
}

/**
 * Record one change. Silent when nothing changed (an edit that saved the same
 * value is not an event) or before the migration — a save must never fail
 * because its history could not be written.
 */
function task_event_log(PDO $pdo, int $progressId, string $kind, ?string $old, ?string $new): void {
    if ($progressId <= 0 || !task_events_available($pdo)) return;
    $old = ($old === null || trim($old) === '') ? null : mb_substr(trim($old), 0, 2000);
    $new = ($new === null || trim($new) === '') ? null : mb_substr(trim($new), 0, 2000);
    if ($old === $new) return;
    try {
        $pdo->prepare(
            'INSERT INTO progress_events (progress_id, kind, old_value, new_value, actor, user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $progressId, $kind, $old, $new,
            function_exists('current_user_display') ? mb_substr(current_user_display(), 0, 120) : null,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
        ]);
    } catch (PDOException $e) {
        // History is a courtesy here; the save it describes already happened.
    }
}
