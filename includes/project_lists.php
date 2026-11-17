<?php
// The lists behind the numbers on the projects list (index.php).
//
// David, 2026-11-17, circling Departments, Assets and Head count on the home
// page: "can we make these clickable to show the list of that count?". Shots
// opens the same way. The same idea as the Overview's progress counts
// (project_step_items.php) and the Dashboard's Who is carrying what
// (dashboard_crew_tasks.php): click a number, see what it counts.
//
// ⚠ HEAD COUNT HAS A RULE, so the rule lives here once. head_count_where_sql()
// is the WHERE index.php groups by project to draw the number AND the WHERE
// head_count_people() lists with, so the list is always as long as the number.
// Departments, shots and assets are plain COUNT(*)s of their tables for the
// project; their lists read the same tables with the same project filter.

require_once __DIR__ . '/functions.php';

/**
 * The head-count test over crew_projects cp JOIN crew c: real, distinct people
 * (not a placeholder slot, not a team), active in the studio, whose engagement on
 * the show has not ended. The end-date part only when migration 024's column is
 * there, as index.php has always done.
 */
function head_count_where_sql(PDO $pdo): string {
    $end = column_exists($pdo, 'crew_projects', 'end_date')
        ? ' AND (cp.end_date IS NULL OR cp.end_date >= CURDATE())'
        : '';
    return "COALESCE(c.is_placeholder, 0) = 0
            AND COALESCE(c.is_team, 0) = 0
            AND c.active = 1{$end}";
}

/**
 * The people behind one show's head count, by name: their positions on the show
 * (the departments of the crew slots they fill; their own role when they fill
 * none) and, when migration 024 is in, their engagement dates.
 *
 * @return array[] keys: id, name, role, positions, start_date, end_date
 */
function head_count_people(PDO $pdo, int $projectId): array {
    $dates = column_exists($pdo, 'crew_projects', 'end_date');
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.role,
                ' . ($dates ? 'cp.start_date, cp.end_date' : 'NULL AS start_date, NULL AS end_date') . ',
                (SELECT GROUP_CONCAT(DISTINCT ps.name ORDER BY ps.step_order SEPARATOR \', \')
                   FROM crew_lanes cl JOIN pipeline_steps ps ON ps.id = cl.step_id
                  WHERE cl.project_id = cp.project_id AND cl.crew_id = c.id) AS positions
           FROM crew_projects cp
           JOIN crew c ON c.id = cp.crew_id
          WHERE cp.project_id = ? AND ' . head_count_where_sql($pdo) . '
          ORDER BY c.name'
    );
    try {
        $stmt->execute([$projectId]);
    } catch (PDOException $e) {
        // crew_lanes arrives in migration 012: without it, no positions column.
        $stmt = $pdo->prepare(
            'SELECT c.id, c.name, c.role, NULL AS positions,
                    ' . ($dates ? 'cp.start_date, cp.end_date' : 'NULL AS start_date, NULL AS end_date') . '
               FROM crew_projects cp
               JOIN crew c ON c.id = cp.crew_id
              WHERE cp.project_id = ? AND ' . head_count_where_sql($pdo) . '
              ORDER BY c.name'
        );
        $stmt->execute([$projectId]);
    }
    return $stmt->fetchAll();
}

/** A show's departments in pipeline order: the rows index.php counts as Departments. */
function project_departments_list(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT id, name, color, applies_to, applies_to_asset_type, deadline
           FROM pipeline_steps WHERE project_id = ? ORDER BY step_order, id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** A show's shots, episode then shot code: the rows index.php counts as Shots. */
function project_shots_list(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT id, episode, shot_code, frames, placeholder_seconds
           FROM shots WHERE project_id = ? ORDER BY episode, shot_code, id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** A show's assets, type then name: the rows index.php counts as Assets. */
function project_assets_list(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT id, name, asset_type
           FROM assets WHERE project_id = ? ORDER BY asset_type, name, id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}
