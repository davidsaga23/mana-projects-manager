<?php
// The platform's own status vocabulary.
//
// A project owns its statuses. Anything arriving from outside is TRANSLATED onto
// them through status_mapping — the external system never gets to be the source
// of truth. That is what makes dropping Plaky later a change of habit rather
// than a migration.

/**
 * Copy the global default statuses into a project. Returns how many landed.
 *
 * The column list is written out rather than using SELECT *, because the two
 * tables are not identical (statuses has project_id). Every column added to
 * status_templates has to be added HERE too, or it silently stops reaching new
 * projects — which is why the newer ones are copied conditionally rather than
 * assumed: this function has to keep working on an install whose migrations are
 * a step behind the code, exactly as current_user() does for roles.
 */
function seed_project_statuses(PDO $pdo, int $projectId): int {
    $optional = [];
    foreach (['is_excluded', 'on_ticket_board'] as $col) {
        if (column_exists($pdo, 'status_templates', $col) && column_exists($pdo, 'statuses', $col)) {
            $optional[] = $col;
        }
    }
    $extra = $optional ? ', ' . implode(', ', $optional) : '';

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO statuses (project_id, name, sort_order, maps_to, is_blocked, color' . $extra . ')
         SELECT ?, name, sort_order, maps_to, is_blocked, color' . $extra . ' FROM status_templates'
    );
    $stmt->execute([$projectId]);
    return $stmt->rowCount();
}

/** A project's statuses, in display order. */
function project_statuses(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare('SELECT * FROM statuses WHERE project_id = ? ORDER BY sort_order, name');
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** Status rows keyed by id, for quick lookup while rendering. */
function statuses_by_id(PDO $pdo, int $projectId): array {
    $out = [];
    foreach (project_statuses($pdo, $projectId) as $s) $out[(int)$s['id']] = $s;
    return $out;
}

/**
 * The statuses somebody who is not Production may move their own work to, by
 * name, compared ignoring case and spacing.
 *
 * David, 2026-10-21: artists "can change the status of their tasks only to In
 * Progress, Pending review". Everything past review (approved, final, retake) is
 * Production's call. By NAME because statuses are each show's own rows; a show
 * that spells these differently offers its artists nothing to pick, which reads
 * as "ask Production" rather than as a wrong status.
 *
 * ⚠ Applies to supervisors too: they carry every artist permission, and this is
 * the same status-of-my-own-work hole (artist_may_post()).
 */
const SELF_SERVE_STATUS_NAMES = ['in progress', 'pending review'];

/** May the current account move their own work INTO this status? */
function status_self_serve(?array $status): bool {
    if (can_edit()) return true;
    if (!$status) return false;
    $name = strtolower(trim(preg_replace('/\s+/', ' ', (string)$status['name'])));
    return in_array($name, SELF_SERVE_STATUS_NAMES, true);
}

/** The refusal both My tasks status handlers give, in one place. */
function status_self_serve_refusal(): string {
    return 'You can set your own work to In progress or Pending review. Any other status is set by Production.';
}

/**
 * Translate incoming external status text onto one of the project's statuses.
 *
 * Records every distinct value it sees, whether or not it maps — an unmapped
 * value is a row with status_id NULL, which is exactly what the unmatched report
 * reads. Nothing gets silently discarded.
 *
 * Returns the status_id, or null when the text has no mapping yet.
 */
function resolve_external_status(PDO $pdo, int $projectId, ?string $external): ?int {
    $external = trim((string)$external);
    if ($external === '') return null;

    $stmt = $pdo->prepare(
        'SELECT id, status_id FROM status_mapping WHERE project_id = ? AND external_status = ?'
    );
    $stmt->execute([$projectId, $external]);
    $row = $stmt->fetch();

    if ($row) {
        $pdo->prepare('UPDATE status_mapping SET times_seen = times_seen + 1 WHERE id = ?')
            ->execute([$row['id']]);
        return $row['status_id'] !== null ? (int)$row['status_id'] : null;
    }

    // First sighting. If a status of the same name already exists in the project,
    // map it automatically — that is not a guess, it is an exact match.
    $find = $pdo->prepare('SELECT id FROM statuses WHERE project_id = ? AND name = ?');
    $find->execute([$projectId, $external]);
    $statusId = $find->fetchColumn();
    $statusId = $statusId === false ? null : (int)$statusId;

    $pdo->prepare(
        'INSERT INTO status_mapping (project_id, external_status, status_id, times_seen)
         VALUES (?, ?, ?, 1)'
    )->execute([$projectId, $external, $statusId]);

    return $statusId;
}

/** External status values seen but not yet mapped to anything. */
function unmapped_statuses(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM status_mapping
          WHERE project_id = ? AND status_id IS NULL
          ORDER BY times_seen DESC, external_status'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/**
 * Re-apply the status mapping to everything already stored, then recompute the
 * delivered/approved flags from it.
 *
 * Same idea as re-resolving assignees: deciding later what "On Hold" means fixes
 * every record that already arrived with it, because `progress.status` kept the
 * raw text. Returns how many rows had their status re-pointed.
 */
function reapply_status_mapping(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare(
        'UPDATE progress pr
           JOIN status_mapping sm
             ON sm.project_id = pr.project_id AND sm.external_status = pr.status
            SET pr.status_id = sm.status_id
          WHERE pr.project_id = ?'
    );
    $stmt->execute([$projectId]);
    $n = $stmt->rowCount();

    // COALESCE because a row may still have no status at all, and the flag
    // columns are NOT NULL — an unmapped status simply counts as nothing.
    $pdo->prepare(
        "UPDATE progress pr
           LEFT JOIN statuses s ON s.id = pr.status_id
            SET pr.is_approved  = COALESCE(s.maps_to = 'approved', 0),
                pr.is_delivered = COALESCE(s.maps_to IN ('approved','delivered'), 0)
          WHERE pr.project_id = ?"
    )->execute([$projectId]);

    return $n;
}

/**
 * What a status means for progress flags.
 * Returns ['is_delivered' => int, 'is_approved' => int].
 * Approved implies delivered — something finished was necessarily handed over.
 */
function status_flags(?array $status): array {
    if (!$status) return ['is_delivered' => 0, 'is_approved' => 0];
    $approved  = $status['maps_to'] === 'approved' ? 1 : 0;
    $delivered = ($approved || $status['maps_to'] === 'delivered') ? 1 : 0;
    return ['is_delivered' => $delivered, 'is_approved' => $approved];
}
