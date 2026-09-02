<?php
/**
 * The tracking grid — items × pipeline steps, one status per cell.
 *
 * Ported from STFC's preprod.php, which is the screen David used to answer one
 * question at a glance: is this asset finished, so the next department can pick
 * it up? On STFC a row of green checkboxes said yes. Here a cell carries a
 * STATUS rather than a boolean — see the three 2026-08-14 tracking-grid entries
 * in docs/DECISIONS.md for why a checkbox cannot port unchanged — but green
 * means exactly what it meant there: `maps_to = 'approved'`.
 *
 * NO NEW TABLES. `progress` already holds (project_id, step_id, shot_id/asset_id,
 * item_key, status_id) and `pipeline_steps` already knows which steps cover which
 * items. This file is the reading half of what the 2026-08-11 status-model
 * decision was bought for.
 */

require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/pipeline.php';

/**
 * Does this step apply to this item at all?
 *
 * The single answer to a question quota.php, scheduler.php and dashboard.php
 * each work out inline. Consolidated here because the grid needs it per CELL
 * rather than per step, and three subtly different copies of this rule is how a
 * character with no rig ends up unable to ever read as finished.
 *
 * 'project' steps are excluded: an animatic or a final mix is one deliverable
 * for the show, not a column against every shot (migration 006).
 */
function step_covers_item(array $step, array $item, string $scope): bool {
    $appliesTo = $step['applies_to'] ?? 'shot';

    // Whole-show deliverables — an animatic, a music track, the mix, the master
    // (migration 006). They are their own view rather than a column against
    // every shot, which is the mistake that migration existed to undo.
    if ($scope === 'project') return $appliesTo === 'project';
    if ($appliesTo === 'project') return false;

    if ($scope === 'shot') {
        return in_array($appliesTo, ['shot', 'both'], true);
    }

    if (!in_array($appliesTo, ['asset', 'both'], true)) return false;

    // A step scoped to one kind of asset covers only that kind. NULL or empty
    // means every type, which is what every row meant before migration 007.
    $want = trim((string)($step['applies_to_asset_type'] ?? ''));
    if ($want === '') return true;
    return strcasecmp($want, trim((string)($item['asset_type'] ?? ''))) === 0;
}

/** The steps that can appear as columns for one scope, in pipeline order. */
function grid_steps(PDO $pdo, int $projectId, string $scope): array {
    if ($scope === 'project') {
        $stmt = $pdo->prepare(
            'SELECT * FROM pipeline_steps
              WHERE project_id = ? AND applies_to = \'project\'
              ORDER BY step_order, name'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM pipeline_steps
          WHERE project_id = ? AND applies_to <> \'project\'
          ORDER BY step_order, name'
    );
    $stmt->execute([$projectId]);

    // A step that covers nothing in this scope is not a column. Dropping it here
    // rather than drawing a column of dashes keeps the grid as narrow as the
    // work actually is — the difference between 5 columns and 18 on a mixed show.
    return array_values(array_filter($stmt->fetchAll(), function ($s) use ($scope) {
        $appliesTo = $s['applies_to'] ?? 'shot';
        return $scope === 'shot'
            ? in_array($appliesTo, ['shot', 'both'], true)
            : in_array($appliesTo, ['asset', 'both'], true);
    }));
}

/**
 * The rows: assets grouped by type, or shots grouped by episode.
 *
 * Each carries `item_key` in the form progress uses ('a12' / 's34'), so the
 * caller never has to rebuild it and can never build it differently.
 */
function grid_items(PDO $pdo, int $projectId, string $scope): array {
    if ($scope === 'project') {
        // One row: the show itself. `item_key = 'p<id>'` is the convention the
        // Plaky importer already writes for whole-project deliverables, so this
        // view reads exactly the rows the import creates rather than a parallel
        // set of its own.
        $stmt = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        return [[
            'id'         => $projectId,
            'item_key'   => 'p' . $projectId,
            'name'       => (string)$stmt->fetchColumn(),
            'group'      => 'Whole show',
            'asset_type' => null,
        ]];
    }

    if ($scope === 'shot') {
        $stmt = $pdo->prepare(
            'SELECT id, episode, shot_code, placeholder_seconds
               FROM shots WHERE project_id = ? ORDER BY episode, shot_code'
        );
        $stmt->execute([$projectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'       => (int)$r['id'],
                'item_key' => 's' . (int)$r['id'],
                'name'     => shot_label($r['episode'], $r['shot_code']),
                // Episodes are the shots' grouping, standing in for asset type.
                // A non-episodic project has one empty group, which reads as a
                // plain list — the 2026-08-11 episodes decision, honoured here.
                'group'    => trim((string)$r['episode']) !== '' ? $r['episode'] : 'Shots',
                'asset_type' => null,
            ];
        }
        return $out;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, asset_type FROM assets WHERE project_id = ? ORDER BY asset_type, name'
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $type = trim((string)$r['asset_type']) !== '' ? $r['asset_type'] : 'Untyped';
        $out[] = [
            'id'         => (int)$r['id'],
            'item_key'   => 'a' . (int)$r['id'],
            'name'       => $r['name'],
            'group'      => $type,
            'asset_type' => $r['asset_type'],
        ];
    }
    return $out;
}

/**
 * Every progress row for the project, keyed [item_key][step_id].
 *
 * One query for the whole grid. The per-cell alternative is items × steps
 * queries — 55 assets by 5 steps is 275 round trips for a screen that is meant
 * to be glanced at.
 */
function grid_progress(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT pr.item_key, pr.step_id, pr.status_id, pr.status AS raw_status,
                pr.crew_id, pr.assignee_raw, c.name AS crew_name
           FROM progress pr
           LEFT JOIN crew c ON c.id = pr.crew_id
          WHERE pr.project_id = ?'
    );
    $stmt->execute([$projectId]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(string)$r['item_key']][(int)$r['step_id']] = $r;
    }
    return $out;
}

/**
 * Build the whole grid: cells, totals, and which items are ready.
 *
 * Returns everything the screen draws, computed once, so the template does no
 * arithmetic. A cell is one of:
 *   'n/a'   — the step does not cover this item, or its status is is_excluded
 *   'none'  — the step covers it and nothing has been recorded yet
 *   status  — a row from `statuses`
 */
function build_grid(PDO $pdo, int $projectId, string $scope): array {
    $steps    = grid_steps($pdo, $projectId, $scope);
    $items    = grid_items($pdo, $projectId, $scope);
    $progress = grid_progress($pdo, $projectId);
    $statuses = statuses_by_id($pdo, $projectId);

    $rows = [];
    $perStep = [];      // step_id => ['done'=>n,'total'=>n]
    $perGroup = [];     // group   => ['done'=>n,'total'=>n,'items'=>n,'ready'=>n]
    $byStatus = [];     // group   => status_id|'none' => [ ['item'=>..,'step'=>..], .. ]
    $totalCells = 0; $doneCells = 0;

    foreach ($items as $item) {
        $group = $item['group'];
        $perGroup[$group] = $perGroup[$group] ?? ['done' => 0, 'total' => 0, 'items' => 0, 'ready' => 0];
        $perGroup[$group]['items']++;

        $cells = [];
        $rowDone = 0; $rowTotal = 0; $ready = true;

        foreach ($steps as $step) {
            $stepId = (int)$step['id'];
            $perStep[$stepId] = $perStep[$stepId] ?? ['done' => 0, 'total' => 0];

            if (!step_covers_item($step, $item, $scope)) {
                $cells[$stepId] = ['kind' => 'n/a', 'status' => null, 'reason' => 'step'];
                continue;
            }

            $row = $progress[$item['item_key']][$stepId] ?? null;
            $status = ($row && $row['status_id']) ? ($statuses[(int)$row['status_id']] ?? null) : null;

            // "This item doesn't pass through this step" — the N/A the scheduler
            // already honours. It is not unfinished work, so it counts nowhere
            // and never keeps an item from reading as ready.
            if ($status && (int)($status['is_excluded'] ?? 0) === 1) {
                $cells[$stepId] = ['kind' => 'n/a', 'status' => $status, 'reason' => 'excluded'];
                continue;
            }

            $isDone = $status !== null && ($status['maps_to'] ?? '') === 'approved';

            $cells[$stepId] = [
                'kind'   => $status ? 'status' : 'none',
                'status' => $status,
                'raw'    => $row['raw_status'] ?? null,
                'who'    => $row['crew_name'] ?? ($row['assignee_raw'] ?? null),
                'done'   => $isDone,
            ];

            $rowTotal++; $totalCells++;
            $perStep[$stepId]['total']++;
            $perGroup[$group]['total']++;
            if ($isDone) {
                $rowDone++; $doneCells++;
                $perStep[$stepId]['done']++;
                $perGroup[$group]['done']++;
            } else {
                $ready = false;
            }

            $key = $status ? (int)$status['id'] : 'none';
            $byStatus[$group][$key][] = ['item' => $item['name'], 'step' => $step['name']];
        }

        // An item with no applicable steps at all is not "ready" — there is
        // nothing to be ready about, and calling it finished would be a lie that
        // reads green.
        if ($rowTotal === 0) $ready = false;
        if ($ready) $perGroup[$group]['ready']++;

        $rows[] = $item + [
            'cells' => $cells, 'done' => $rowDone, 'total' => $rowTotal, 'ready' => $ready,
        ];
    }

    return [
        'scope'       => $scope,
        'steps'       => $steps,
        'rows'        => $rows,
        'statuses'    => $statuses,
        'per_step'    => $perStep,
        'per_group'   => $perGroup,
        'by_status'   => $byStatus,
        'total_cells' => $totalCells,
        'done_cells'  => $doneCells,
        'pct'         => $totalCells > 0 ? ($doneCells / $totalCells) * 100 : 0.0,
    ];
}

/**
 * A donut for one group: status spread around the edge, readiness in the middle.
 *
 * Hand-rolled SVG rather than a charting library, matching workflow_diagram_svg()
 * — no external dependency has been added to this codebase yet and a pie does
 * not justify the first one.
 *
 * The two numbers answer two different questions and both were asked for: the
 * segments are how the work is spread ("what state are the characters in"), the
 * centre is how much is finished ("how many are missing").
 */
function grid_donut_svg(array $slices, int $ready, int $itemCount, int $size = 132): string {
    $total = 0;
    foreach ($slices as $s) $total += $s['count'];
    $r  = $size / 2;
    $ro = $r - 4;          // outer radius
    $ri = $r * 0.60;       // inner radius — the hole

    $svg = '<svg class="donut" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size
         . '" height="' . $size . '" role="img">';

    if ($total === 0) {
        $svg .= '<circle cx="' . $r . '" cy="' . $r . '" r="' . $ro
              . '" fill="none" stroke="#e4e8ec" stroke-width="' . ($ro - $ri) . '"></circle>';
    } else {
        $angle = -M_PI / 2;   // start at twelve o'clock
        foreach ($slices as $s) {
            $frac = $s['count'] / $total;
            $end  = $angle + $frac * 2 * M_PI;

            // A full circle cannot be drawn as one arc — the start and end points
            // coincide and the path collapses to nothing. Draw a ring instead.
            if ($frac >= 0.9999) {
                $svg .= '<circle cx="' . $r . '" cy="' . $r . '" r="' . (($ro + $ri) / 2)
                      . '" fill="none" stroke="' . e($s['color']) . '" stroke-width="' . ($ro - $ri)
                      . '" data-status="' . e((string)$s['key']) . '" class="donut-seg"><title>'
                      . e($s['label'] . ', ' . $s['count']) . '</title></circle>';
                break;
            }

            $large = ($end - $angle) > M_PI ? 1 : 0;
            $x1 = $r + $ro * cos($angle);  $y1 = $r + $ro * sin($angle);
            $x2 = $r + $ro * cos($end);    $y2 = $r + $ro * sin($end);
            $x3 = $r + $ri * cos($end);    $y3 = $r + $ri * sin($end);
            $x4 = $r + $ri * cos($angle);  $y4 = $r + $ri * sin($angle);

            $d = sprintf('M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z',
                $x1, $y1, $ro, $ro, $large, $x2, $y2, $x3, $y3, $ri, $ri, $large, $x4, $y4);

            $svg .= '<path class="donut-seg" d="' . $d . '" fill="' . e($s['color']) . '"'
                  . ' data-status="' . e((string)$s['key']) . '"><title>'
                  . e($s['label'] . ', ' . $s['count'] . ' cell' . ($s['count'] === 1 ? '' : 's'))
                  . '</title></path>';
            $angle = $end;
        }
    }

    $svg .= '<text class="donut-num" x="' . $r . '" y="' . ($r - 1) . '" text-anchor="middle">'
          . $ready . '/' . $itemCount . '</text>';
    $svg .= '<text class="donut-cap" x="' . $r . '" y="' . ($r + 14) . '" text-anchor="middle">ready</text>';
    $svg .= '</svg>';
    return $svg;
}

/** Turn one group's cells into donut slices, ordered as the project orders its statuses. */
function grid_slices(array $grid, string $group): array {
    $counts = [];
    foreach ($grid['by_status'][$group] ?? [] as $key => $entries) {
        $counts[$key] = count($entries);
    }
    $slices = [];
    foreach ($grid['statuses'] as $sid => $s) {
        if (empty($counts[$sid])) continue;
        $slices[] = ['key' => $sid, 'label' => $s['name'],
                     'color' => $s['color'] ?: '#8a97a3', 'count' => $counts[$sid]];
    }
    if (!empty($counts['none'])) {
        // Deliberately last and deliberately grey: "nothing recorded" is not a
        // status the team chose, it is the absence of one, and colouring it like
        // the others would imply somebody set it.
        $slices[] = ['key' => 'none', 'label' => 'Not recorded',
                     'color' => '#d7dde3', 'count' => $counts['none']];
    }
    return $slices;
}

/**
 * One item, and every step that applies to it — the row the grid draws, opened up.
 *
 * Returns null when the key names nothing in this project. `item_key` is the
 * convention the importer and scheduler already use: 's'+shot id, 'a'+asset id,
 * 'p'+project id for a whole-show deliverable.
 */
function load_grid_item(PDO $pdo, int $projectId, string $itemKey): ?array {
    $kind = substr($itemKey, 0, 1);
    $id   = (int)substr($itemKey, 1);
    if (!in_array($kind, ['s', 'a', 'p'], true) || $id <= 0) return null;

    if ($kind === 'p') {
        if ($id !== $projectId) return null;
        $q = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
        $q->execute([$projectId]);
        $name = $q->fetchColumn();
        if ($name === false) return null;
        return ['id' => $projectId, 'item_key' => $itemKey, 'name' => (string)$name,
                'scope' => 'project', 'group' => 'Whole show', 'asset_type' => null,
                'episode' => null, 'seconds' => null, 'notes' => null];
    }

    if ($kind === 's') {
        $q = $pdo->prepare('SELECT * FROM shots WHERE id = ? AND project_id = ?');
        $q->execute([$id, $projectId]);
        $r = $q->fetch();
        if (!$r) return null;
        return ['id' => $id, 'item_key' => $itemKey,
                'name' => shot_label($r['episode'], $r['shot_code']),
                'scope' => 'shot',
                'group' => trim((string)$r['episode']) !== '' ? $r['episode'] : 'Shots',
                'asset_type' => null, 'episode' => $r['episode'],
                'seconds' => $r['placeholder_seconds'], 'notes' => $r['notes'] ?? null];
    }

    $q = $pdo->prepare('SELECT * FROM assets WHERE id = ? AND project_id = ?');
    $q->execute([$id, $projectId]);
    $r = $q->fetch();
    if (!$r) return null;
    return ['id' => $id, 'item_key' => $itemKey, 'name' => $r['name'], 'scope' => 'asset',
            'group' => trim((string)$r['asset_type']) !== '' ? $r['asset_type'] : 'Untyped',
            'asset_type' => $r['asset_type'], 'episode' => null,
            'seconds' => null, 'notes' => $r['notes'] ?? null];
}

/**
 * The rows of the item page: one per applicable step, with everything known
 * about that step's work on that item.
 *
 * The due date comes from `schedule`, which is the plan. It is shown but not
 * edited here — dates are generated by the forward pass and hand-changed on the
 * timeline, which marks them `is_manual = 1` so a re-run leaves them alone. A
 * second place to write dates, with no such marker, would have the scheduler
 * quietly overwrite them (DECISIONS, 2026-08-12).
 */
function item_steps(PDO $pdo, int $projectId, array $item): array {
    $steps = grid_steps($pdo, $projectId, $item['scope']);
    $statuses = statuses_by_id($pdo, $projectId);

    $hasDetail = column_exists($pdo, 'progress', 'version');
    $cols = 'pr.id, pr.step_id, pr.status_id, pr.status AS raw_status, pr.crew_id,'
          . ' pr.assignee_raw, pr.time_spent_minutes, pr.updated_at'
          . ($hasDetail ? ', pr.version, pr.notes' : '');

    $q = $pdo->prepare(
        "SELECT {$cols}, c.name AS crew_name,
                sc.start_date, sc.end_date, sc.is_manual
           FROM progress pr
           LEFT JOIN crew c ON c.id = pr.crew_id
           LEFT JOIN schedule sc ON sc.step_id = pr.step_id AND sc.item_key = pr.item_key
          WHERE pr.project_id = ? AND pr.item_key = ?"
    );
    $q->execute([$projectId, $item['item_key']]);
    $byStep = [];
    foreach ($q->fetchAll() as $r) $byStep[(int)$r['step_id']] = $r;

    // Extra assignees. progress.crew_id is the primary; progress_crew is the
    // complete answer (DECISIONS, 2026-08-12) and dropping the second name is
    // the thing that decision exists to prevent.
    $extra = [];
    $ids = array_map(fn($r) => (int)$r['id'], $byStep);
    if ($ids) {
        $list = implode(',', array_map('intval', $ids));
        foreach ($pdo->query(
            "SELECT pc.progress_id, c.id AS crew_id, c.name
               FROM progress_crew pc JOIN crew c ON c.id = pc.crew_id
              WHERE pc.progress_id IN ({$list})"
        ) as $r) {
            $extra[(int)$r['progress_id']][] = $r;
        }
    }

    $out = [];
    foreach ($steps as $step) {
        if (!step_covers_item($step, $item, $item['scope'])) continue;
        $stepId = (int)$step['id'];
        $row = $byStep[$stepId] ?? null;
        $status = ($row && $row['status_id']) ? ($statuses[(int)$row['status_id']] ?? null) : null;

        $others = [];
        if ($row && !empty($extra[(int)$row['id']])) {
            foreach ($extra[(int)$row['id']] as $o) {
                if ((int)$o['crew_id'] !== (int)($row['crew_id'] ?? 0)) $others[] = $o['name'];
            }
        }

        $out[] = [
            'step'      => $step,
            'progress'  => $row,
            'status'    => $status,
            'done'      => $status !== null && ($status['maps_to'] ?? '') === 'approved',
            'excluded'  => $status !== null && (int)($status['is_excluded'] ?? 0) === 1,
            'version'   => $row['version'] ?? null,
            'notes'     => $row['notes'] ?? null,
            'who'       => $row['crew_name'] ?? ($row['assignee_raw'] ?? null),
            'others'    => $others,
            'due'       => $row['end_date'] ?? null,
            'manual'    => (int)($row['is_manual'] ?? 0) === 1,
            'minutes'   => $row['time_spent_minutes'] ?? null,
            'updated'   => $row['updated_at'] ?? null,
        ];
    }
    return $out;
}
