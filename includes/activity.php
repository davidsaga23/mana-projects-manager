<?php
/**
 * The status-change log: writing it, and reading it back as "what moved today".
 *
 * There is ONE writer, called from the three places a status can change:
 *   includes/plaky_import.php  — the import, which is most of the traffic
 *   production_api.php         — a cell clicked on the tracking grid
 *   item.php                   — the item page's Save
 *
 * One function rather than three copies because they must agree about what a
 * change MEANS. `change_type` is worked out from the project's own status
 * vocabulary — `maps_to` and `is_blocked` — never from the words in a status
 * name, so a show that calls its retake state "Kickback" is still understood.
 *
 * History cannot be backfilled (migration 021). Nothing before that migration
 * ran exists, and the screens say so rather than showing a convincing blank.
 */

require_once __DIR__ . '/statuses.php';

/**
 * What a move from one status to another means.
 *
 * The interesting case is `retake`, and it is a STRUCTURAL test: work that had
 * reached delivered or approved and is now neither has gone backwards, whatever
 * the new status is called. STFC matched on the word "Retake"; this catches the
 * same event on a project that never uses that word, and does not misfire on a
 * status that merely has it in the name.
 */
function change_type_for(?array $old, ?array $new): string {
    $oldMaps = $old['maps_to'] ?? 'none';
    $newMaps = $new['maps_to'] ?? 'none';

    if ($newMaps === 'approved')  return 'approved';
    if ($newMaps === 'delivered') return 'delivered';
    if (in_array($oldMaps, ['delivered', 'approved'], true)) return 'retake';
    if ($new !== null && (int)($new['is_blocked'] ?? 0) === 1) return 'blocked';
    return 'other';
}

/**
 * Record one status change. Silent no-op when nothing actually changed, or when
 * migration 021 hasn't run.
 *
 * Never throws: a logging failure must not stop an import or a save. It is the
 * one place in this codebase where swallowing is right — the thing being
 * protected is the user's actual work, and the log is a record of it, not the
 * point of it. A failure still reaches the error log.
 */
function log_status_change(PDO $pdo, array $in): void {
    if ((int)($in['old_status_id'] ?? 0) === (int)($in['new_status_id'] ?? 0)) return;

    static $available = null;
    if ($available === null) $available = column_exists($pdo, 'progress_log', 'id');
    if (!$available) return;

    try {
        $pdo->prepare(
            'INSERT INTO progress_log
               (project_id, step_id, step_name, item_key, item_name, shot_id, asset_id,
                old_status_id, old_status_name, new_status_id, new_status_name,
                change_type, crew_id, crew_name, source, changed_by, log_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        )->execute([
            (int)$in['project_id'],
            $in['step_id'] ?? null,
            $in['step_name'] ?? null,
            (string)$in['item_key'],
            $in['item_name'] ?? null,
            $in['shot_id'] ?? null,
            $in['asset_id'] ?? null,
            $in['old_status_id'] ?? null,
            $in['old_status_name'] ?? null,
            $in['new_status_id'] ?? null,
            $in['new_status_name'] ?? null,
            change_type_for($in['old_status'] ?? null, $in['new_status'] ?? null),
            $in['crew_id'] ?? null,
            $in['crew_name'] ?? null,
            $in['source'] ?? 'import',
            $in['changed_by'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[Anima progress_log] ' . $e->getMessage());
    }
}

/** Convenience: look the two statuses up, then log. */
function log_status_change_by_id(PDO $pdo, int $projectId, array $step, string $itemKey,
                                 ?int $oldId, ?int $newId, array $extra = []): void {
    if ((int)$oldId === (int)$newId) return;
    $map = statuses_by_id($pdo, $projectId);
    $old = $oldId ? ($map[$oldId] ?? null) : null;
    $new = $newId ? ($map[$newId] ?? null) : null;

    log_status_change($pdo, $extra + [
        'project_id'      => $projectId,
        'step_id'         => (int)$step['id'],
        'step_name'       => $step['name'] ?? null,
        'item_key'        => $itemKey,
        'old_status_id'   => $oldId,
        'old_status_name' => $old['name'] ?? null,
        'new_status_id'   => $newId,
        'new_status_name' => $new['name'] ?? null,
        'old_status'      => $old,
        'new_status'      => $new,
    ]);
}

/** Is the log switched on? For telling "nothing changed" from "nothing is recorded". */
function activity_available(PDO $pdo): bool {
    return column_exists($pdo, 'progress_log', 'id');
}

/**
 * Everything that changed on one day, grouped into the three phases.
 *
 * The phases are the same ones Production tracking uses, and derived the same
 * way — from the step's scope, not from a label. Assets are pre-production,
 * shots are production, whole-show deliverables are post.
 *
 * Returns ['asset' => rows, 'shot' => rows, 'project' => rows].
 */
function activity_for_day(PDO $pdo, int $projectId, string $day): array {
    $out = ['asset' => [], 'shot' => [], 'project' => []];
    if (!activity_available($pdo)) return $out;

    $stmt = $pdo->prepare(
        "SELECT pl.*, ps.applies_to, ps.color AS step_color,
                s.episode, s.shot_code, s.placeholder_seconds,
                a.name AS asset_name, a.asset_type
           FROM progress_log pl
           LEFT JOIN pipeline_steps ps ON ps.id = pl.step_id
           LEFT JOIN shots  s ON s.id = pl.shot_id
           LEFT JOIN assets a ON a.id = pl.asset_id
          WHERE pl.project_id = ? AND pl.log_date = ?
          ORDER BY ps.step_order, s.episode, s.shot_code, a.name, pl.created_at"
    );
    $stmt->execute([$projectId, $day]);

    foreach ($stmt->fetchAll() as $r) {
        // The item's name is read live where the row still exists, and falls back
        // to the copy taken at the time — which is the whole reason it is copied.
        $shot = shot_label($r['episode'] ?? '', $r['shot_code'] ?? '');
        $r['display_name'] = $shot !== '' ? $shot : ($r['asset_name'] ?: ($r['item_name'] ?: $r['item_key']));

        $kind = substr((string)$r['item_key'], 0, 1);
        $bucket = $kind === 'a' ? 'asset' : ($kind === 'p' ? 'project' : 'shot');
        $out[$bucket][] = $r;
    }
    return $out;
}

/** Which days have anything recorded, newest first — for the day picker. */
function activity_days(PDO $pdo, int $projectId, int $limit = 30): array {
    if (!activity_available($pdo)) return [];
    $limit = max(1, min(120, $limit));
    $stmt = $pdo->prepare(
        "SELECT log_date, COUNT(*) AS n FROM progress_log
          WHERE project_id = ? GROUP BY log_date ORDER BY log_date DESC LIMIT {$limit}"
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** The colour a change type reads in. */
function change_type_class(string $type): string {
    return [
        'approved'  => 'ok',
        'delivered' => 'warn-text',
        'retake'    => 'err',
        'blocked'   => 'warn-text',
    ][$type] ?? 'muted';
}
