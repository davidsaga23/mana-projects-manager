<?php
// The forward pass: turn a pipeline graph, a breakdown and a set of estimates
// into dated tasks.
//
// The reverse direction (work + deadline + headcount -> quota per artist per day)
// already lives in quota.php and is what the wizard's review screen shows. This
// is the other half: the same inputs, walked forward, producing actual dates.
//
// Four things it must never do, all of them lessons paid for on STFC:
//
//   * Never assume one thing at a time. Headcount is the size of a TEAM, not
//     "tasks in flight". Treating them as the same serialised 188 shots into
//     nine months. Where headcount is unknown, the number of parallel lanes is
//     derived from the deadline instead — never defaulted to 1.
//   * Never silently schedule past a deadline. Landing in February is a fact to
//     report, not a plan to draw quietly.
//   * Never invent per-item detail it doesn't have. A step covering "about 30%
//     of the show" is drawn as one band, not as 56 named shots picked at random.
//   * Never touch a hand-planned row. is_manual = 1 is somebody's decision.
//
// build_schedule() computes and returns; commit_schedule() writes. They are
// separate so the screen can show a full preview of what WOULD happen, which is
// the same dry-run-first convention the importer and the migration runner use.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/calendar.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/quota.php';

/** How a lane is named until real people are assigned to it: "Compositing 02". */
function lane_label(string $stepName, int $lane, int $laneCount): string {
    // A single lane needs no number — "Animatic 01" reads like there is an 02.
    if ($laneCount <= 1) return $stepName;
    return $stepName . ' ' . str_pad((string)$lane, 2, '0', STR_PAD_LEFT);
}

/**
 * Work out the whole schedule without writing anything.
 *
 * Returns:
 *   'tasks'    => the rows that would be written, in date order
 *   'steps'    => per-step summary: lanes, span, lateness, what was skipped
 *   'problems' => things that make the plan unworkable or unknowable
 *   'notes'    => assumptions it had to make, in plain language
 *   'kept'     => hand-planned rows left exactly as they are
 */
function build_schedule(PDO $pdo, array $project, ?string $today = null): array {
    $projectId = (int)$project['id'];
    $today     = $today ?: date('Y-m-d');
    $holidays  = non_working_days($pdo);

    $out = ['tasks' => [], 'steps' => [], 'problems' => [], 'notes' => [],
            'kept' => [], 'anchor' => null, 'end' => null];

    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) {
        $out['problems'][] = 'This project has no pipeline steps, so there is nothing to schedule.';
        return $out;
    }

    $depsMap = step_dependencies_map($pdo, $projectId);
    if (pipeline_has_cycle($depsMap)) {
        $out['problems'][] = 'The workflow has steps waiting for each other in a loop. ' .
                             'Fix it on the Workflow screen — nothing can be scheduled until then.';
        return $out;
    }

    // Work can't be done in the past, and a project that hasn't started yet
    // shouldn't be planned from today either.
    $anchor = $today;
    if (!empty($project['start_date']) && $project['start_date'] > $today) {
        $anchor = $project['start_date'];
    }
    $anchor = next_working_day($anchor, $holidays);
    $axis   = working_day_axis($anchor, $holidays);
    $out['anchor'] = $anchor;

    // The date every step falls back to when it has no deadline of its own.
    $projectDeadline = $project['delivery_date'] ?: null;

    $items      = scheduler_item_pool($pdo, $projectId);
    $progress   = scheduler_progress_map($pdo, $projectId);
    $manual     = scheduler_manual_rows($pdo, $projectId);
    $shotAssets = scheduler_shot_assets($pdo, $projectId);

    // Steps in dependency order. A step's level is one past everything it waits
    // for, so ordering by level is a valid topological order and the graph never
    // needs sorting twice.
    $levels = workflow_levels($steps, $depsMap);
    usort($steps, function ($a, $b) use ($levels) {
        $la = $levels[(int)$a['id']] ?? 0;
        $lb = $levels[(int)$b['id']] ?? 0;
        if ($la !== $lb) return $la <=> $lb;
        return ((int)$a['step_order'] <=> (int)$b['step_order']) ?: ((int)$a['id'] <=> (int)$b['id']);
    });

    $stepEnd  = [];   // step id => last working-day index anything in it finishes
    $itemEnd  = [];   // step id => [item_key => last working-day index]
    $estimates = project_estimates($pdo, $projectId);

    foreach ($steps as $step) {
        $stepId  = (int)$step['id'];
        $summary = scheduler_schedule_step(
            $pdo, $project, $step, $axis, $anchor, $projectDeadline,
            $items, $progress, $manual, $shotAssets, $depsMap,
            $stepEnd, $itemEnd, $estimates
        );

        $out['steps'][$stepId] = $summary;
        foreach ($summary['tasks'] as $t) $out['tasks'][] = $t;
        foreach ($summary['kept']  as $k) $out['kept'][]  = $k;

        $itemEnd[$stepId] = $summary['item_end'];
        $stepEnd[$stepId] = $summary['end_index'];
    }

    if (!empty($axis['exhausted'])) {
        $out['problems'][] = 'The plan ran past the end of the calendar this tool will build ' .
                             '(about twelve years). That normally means a step has far more work ' .
                             'than one lane can absorb — check the headcount and days-per-item on ' .
                             'the Dates & headcount screen.';
    }

    // Order the whole thing by date so the preview reads as a timeline rather
    // than as pipeline order.
    usort($out['tasks'], function ($a, $b) {
        return [$a['start_date'], $a['end_date'], $a['step_id'], $a['sort']]
           <=> [$b['start_date'], $b['end_date'], $b['step_id'], $b['sort']];
    });

    $ends = array_column($out['tasks'], 'end_date');
    $out['end'] = $ends ? max($ends) : null;

    if ($out['end'] && $projectDeadline && $out['end'] > $projectDeadline) {
        $over = working_days_after($projectDeadline, $out['end']);
        $out['problems'][] = sprintf(
            'The plan finishes on %s, which is %d working day%s past the delivery date of %s.',
            $out['end'], $over, $over === 1 ? '' : 's', $projectDeadline
        );
    }
    if (!$projectDeadline) {
        $out['notes'][] = 'This project has no delivery date, so steps without a deadline of ' .
                          'their own were sized from their own workload rather than from a date.';
    }
    if (!$holidays) {
        $out['notes'][] = 'No non-working days are recorded, so every weekday counts as workable — ' .
                          'including public holidays.';
    }

    return $out;
}

/** Every shot and asset in the project, once, in a stable order. */
function scheduler_item_pool(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT id, episode, shot_code, placeholder_seconds
           FROM shots WHERE project_id = ? ORDER BY episode, shot_code, id'
    );
    $stmt->execute([$projectId]);
    $shots = [];
    foreach ($stmt as $r) {
        $shots[] = [
            'kind' => 'shot', 'id' => (int)$r['id'],
            'key'  => 's' . (int)$r['id'],
            'name' => trim(($r['episode'] ? $r['episode'] . ' ' : '') . $r['shot_code']),
            'seconds' => (float)$r['placeholder_seconds'],
            'type' => null,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, asset_type FROM assets WHERE project_id = ? ORDER BY asset_type, name, id'
    );
    $stmt->execute([$projectId]);
    $assets = [];
    foreach ($stmt as $r) {
        $assets[] = [
            'kind' => 'asset', 'id' => (int)$r['id'],
            'key'  => 'a' . (int)$r['id'],
            'name' => $r['name'],
            'seconds' => 0.0,
            'type' => $r['asset_type'],
        ];
    }
    return ['shots' => $shots, 'assets' => $assets];
}

/**
 * What is already known about each (step, item): finished, not applicable, or
 * blocked. Keyed 'step_id|item_key'.
 *
 * is_excluded is the one that changes what gets scheduled at all — it is the
 * board's 'N/A', meaning this item does not pass through this step. Blocked work
 * IS scheduled: it still has to happen, and hiding it would make a plan look
 * achievable when it isn't. It is counted separately so it reads as the
 * escalation it is.
 */
function scheduler_progress_map(PDO $pdo, int $projectId): array {
    try {
        $stmt = $pdo->prepare(
            'SELECT pr.step_id, pr.item_key, pr.is_approved,
                    COALESCE(s.is_blocked, 0)  AS is_blocked,
                    COALESCE(s.is_excluded, 0) AS is_excluded
               FROM progress pr
               LEFT JOIN statuses s ON s.id = pr.status_id
              WHERE pr.project_id = ?'
        );
        $stmt->execute([$projectId]);
    } catch (PDOException $e) {
        // is_excluded arrives in migration 010. Without it, fall back to the
        // flags that do exist rather than failing to schedule at all.
        $stmt = $pdo->prepare(
            'SELECT pr.step_id, pr.item_key, pr.is_approved,
                    COALESCE(s.is_blocked, 0) AS is_blocked, 0 AS is_excluded
               FROM progress pr
               LEFT JOIN statuses s ON s.id = pr.status_id
              WHERE pr.project_id = ?'
        );
        $stmt->execute([$projectId]);
    }

    $map = [];
    foreach ($stmt as $r) {
        $map[$r['step_id'] . '|' . $r['item_key']] = [
            'approved' => (int)$r['is_approved'] === 1,
            'blocked'  => (int)$r['is_blocked'] === 1,
            'excluded' => (int)$r['is_excluded'] === 1,
        ];
    }
    return $map;
}

/** Hand-planned rows, which the pass reads but never rewrites. */
function scheduler_manual_rows(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM schedule WHERE project_id = ? AND is_manual = 1'
    );
    $stmt->execute([$projectId]);
    $map = [];
    foreach ($stmt as $r) $map[$r['step_id'] . '|' . $r['item_key']] = $r;
    return $map;
}

/** shot id => [asset ids], for dependencies that cross from assets to shots. */
function scheduler_shot_assets(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT sa.shot_id, sa.asset_id
           FROM shot_assets sa JOIN shots s ON s.id = sa.shot_id
          WHERE s.project_id = ?'
    );
    $stmt->execute([$projectId]);
    $map = [];
    foreach ($stmt as $r) $map[(int)$r['shot_id']][] = (int)$r['asset_id'];
    return $map;
}

/**
 * Schedule one step. Called in dependency order, so everything it waits for has
 * already been placed.
 */
function scheduler_schedule_step(
    PDO $pdo, array $project, array $step, array &$axis, string $anchor, ?string $projectDeadline,
    array $items, array $progress, array $manual, array $shotAssets, array $depsMap,
    array $stepEnd, array $itemEnd, array $estimates
): array {
    $stepId    = (int)$step['id'];
    $stepName  = $step['name'];
    $projectId = (int)$project['id'];
    $dpu       = (float)($step['days_per_unit'] ?: 1.0);
    if ($dpu <= 0) $dpu = 1.0;
    $coverage  = max(0.0, min(100.0, (float)($step['coverage_pct'] ?? 100))) / 100;

    $s = [
        'step' => $step, 'tasks' => [], 'kept' => [], 'item_end' => [],
        'mode' => 'items', 'lanes' => 1, 'lane_source' => '',
        'items_total' => 0, 'scheduled' => 0,
        'done' => 0, 'excluded' => 0, 'blocked' => 0, 'manual' => 0,
        // null, not 0: a step that placed nothing must impose no wait on what
        // follows it. Zero would read as "finished on day one" and push every
        // downstream item back a day for no reason.
        'start_date' => null, 'end_date' => null, 'end_index' => null,
        'deadline' => $step['deadline'] ?: $projectDeadline,
        'deadline_own' => !empty($step['deadline']),
        'late_days' => 0, 'notes' => [], 'work_days' => 0.0,
    ];

    // --- which items pass through this step ---------------------------------
    $pool = [];
    if ($step['applies_to'] === 'project') {
        $s['mode'] = 'project';
        $pool = [['kind' => 'project', 'id' => null, 'key' => 'p' . $projectId,
                  'name' => $stepName, 'seconds' => 0.0, 'type' => null]];
    } else {
        if (in_array($step['applies_to'], ['shot', 'both'], true)) {
            $pool = array_merge($pool, $items['shots']);
        }
        if (in_array($step['applies_to'], ['asset', 'both'], true)) {
            $type = $step['applies_to_asset_type'] ?? '';
            foreach ($items['assets'] as $a) {
                if ($type !== '' && $a['type'] !== $type) continue;
                $pool[] = $a;
            }
        }
    }
    $s['items_total'] = count($pool);

    // --- drop what shouldn't be planned -------------------------------------
    $todo = [];
    foreach ($pool as $item) {
        $p = $progress[$stepId . '|' . $item['key']] ?? null;
        if ($p && $p['excluded']) { $s['excluded']++; continue; }
        if ($p && $p['approved']) { $s['done']++;     continue; }
        if (isset($manual[$stepId . '|' . $item['key']])) {
            $row = $manual[$stepId . '|' . $item['key']];
            $s['manual']++;
            $s['kept'][] = ['step' => $stepName, 'item' => $item['name'],
                            'start_date' => $row['start_date'], 'end_date' => $row['end_date']];
            // A hand-planned row still gates what comes after it.
            $idx = axis_index($axis, $row['end_date']);
            if ($idx !== null) $s['item_end'][$item['key']] = $idx;
            continue;
        }
        $item['blocked'] = $p ? $p['blocked'] : false;
        if (!empty($item['blocked'])) $s['blocked']++;
        $todo[] = $item;
    }

    // --- nothing broken down yet? plan from the estimate ---------------------
    // A project in setup has a contracted runtime and no shot list — that is the
    // normal state at the point when planning matters most. step_work_units()
    // already knows how to fall back to project_settings.estimated_*, and says
    // so; a band built from it is the honest shape of that knowledge. It can
    // never be mistaken for a breakdown because it has no item names in it.
    $fromEstimate = false;
    if (!$todo && $s['items_total'] === 0 && $s['mode'] !== 'project') {
        $work = step_work_units($pdo, $projectId, $step, $estimates);
        if (!empty($work['estimated']) && $work['units'] > 0) {
            $fromEstimate = true;
            $s['mode'] = 'band';
            $s['estimated'] = true;
            $s['estimate_units'] = $work['units'];
            $s['estimate_label'] = $work['label'];
        }
    }

    if (!$todo && !$fromEstimate) {
        // Nothing left to plan is a fine outcome, not a failure — but the summary
        // has to say which of the reasons it was.
        if ($s['item_end']) $s['end_index'] = max($s['item_end']);
        if ($s['items_total'] === 0) {
            $s['notes'][] = 'No ' . applies_to_phrase($step['applies_to'], $step['applies_to_asset_type'] ?? null)
                          . ' exist yet and no estimate is set, so there is nothing to place.';
        } elseif ($s['done'] === $s['items_total']) {
            $s['notes'][] = 'Everything here is already approved.';
        } elseif ($s['excluded'] === $s['items_total']) {
            $s['notes'][] = 'Every item here is marked as not applicable.';
        }
        return $s;
    }

    // --- one band, or one row per item? -------------------------------------
    // Coverage is an estimate made before anyone knows WHICH items are involved.
    // Once some are marked N/A that guess has been replaced by facts, and the
    // remaining list is the truth — the same rule that makes a real shot count
    // beat project_settings.estimated_shots.
    $useBand = $fromEstimate;
    if (!$fromEstimate && $s['mode'] !== 'project' && $coverage < 1.0) {
        if ($s['excluded'] > 0) {
            $s['notes'][] = sprintf(
                'Covers %s%% by estimate, but %d item%s already marked as not applicable — ' .
                'the real list is used and the percentage ignored.',
                fmt_num($coverage * 100), $s['excluded'], $s['excluded'] === 1 ? ' is' : 's are'
            );
        } else {
            $useBand = true;
            $s['mode'] = 'band';
        }
    }

    // --- how long each item takes -------------------------------------------
    // A step tracked in seconds measures footage, so a twelve-second shot is six
    // times the work of a two-second one. Everything else is one item, one job.
    $duration = function (array $item) use ($step, $dpu): float {
        if ($step['tracks_seconds'] && $item['kind'] === 'shot' && $item['seconds'] > 0) {
            return max(0.05, $item['seconds'] * $dpu);
        }
        return max(0.05, $dpu);
    };

    $totalWork = 0.0;
    if ($fromEstimate) {
        // step_work_units() has already applied coverage to the estimate, so
        // applying it again here would shrink the work twice.
        $totalWork = $s['estimate_units'] * $dpu;
    } else {
        foreach ($todo as $item) $totalWork += $duration($item);
        if ($useBand) $totalWork *= $coverage;
    }
    $s['work_days'] = $totalWork;

    // --- when can this step start? ------------------------------------------
    $earliestStep = 0;
    if (!empty($step['start_date'])) {
        $idx = axis_index($axis, $step['start_date']);
        if ($idx !== null) $earliestStep = $idx;
    }

    // --- how many lanes? -----------------------------------------------------
    [$lanes, $laneSource, $laneNotes] = scheduler_lane_count(
        $step, $totalWork, $earliestStep, $s['deadline'], $axis,
        $fromEstimate ? (int)ceil($s['estimate_units']) : count($todo), $anchor
    );
    $s['lanes'] = $lanes;
    $s['lane_source'] = $laneSource;
    foreach ($laneNotes as $n) $s['notes'][] = $n;

    // --- place the work ------------------------------------------------------
    // Each item's earliest start comes from its dependencies; then it takes the
    // lane that frees up soonest. Sorting by that earliest start first is what
    // stops one late-arriving item from pushing everything behind it.
    $entries = [];
    foreach ($todo as $i => $item) {
        $entries[] = [
            'item'     => $item,
            'earliest' => scheduler_item_earliest(
                $item, $step, $depsMap, $stepEnd, $itemEnd, $shotAssets, $earliestStep
            ),
            'order'    => $i,
        ];
    }
    usort($entries, function ($a, $b) {
        return [$a['earliest'], $a['order']] <=> [$b['earliest'], $b['order']];
    });

    if ($useBand) {
        // One bar for the whole step. Its length is the work spread across the
        // lanes, starting as soon as everything it waits for is done.
        $earliest = $entries
            ? min(array_column($entries, 'earliest'))
            : scheduler_item_earliest(
                ['kind' => 'band', 'id' => null, 'key' => 'band'],
                $step, $depsMap, $stepEnd, $itemEnd, $shotAssets, $earliestStep
              );
        $span  = $totalWork / max(1, $lanes);
        $scope = applies_to_phrase($step['applies_to'], $step['applies_to_asset_type'] ?? null);

        $label = $fromEstimate
            ? sprintf('%s — %s estimated %s', $stepName,
                      fmt_rate((float)$s['estimate_units']), $s['estimate_label'])
            : sprintf('%s — %s%% of %d %s', $stepName,
                      fmt_num($coverage * 100), count($todo), $scope);

        $task = scheduler_make_task(
            $axis, $projectId, $step, null, 'band', $label,
            $earliest, $span, 1, $lanes, false, 0
        );
        if ($task) {
            $s['tasks'][] = $task;
            $s['scheduled'] = 1;
            $s['end_index'] = $task['end_index'];
        }
        $s['notes'][] = $fromEstimate
            ? 'Drawn as one band from the project estimate, because no ' . $scope .
              ' have been added yet. It becomes real per-item dates as soon as a breakdown exists.'
            : sprintf(
                'Drawn as one band: %s%% coverage says how MUCH of this step there is, not which %s. ' .
                'Mark the ones it doesn\'t touch as not applicable, or set coverage to 100%%, and this ' .
                'splits into real per-item dates.',
                fmt_num($coverage * 100),
                $step['applies_to'] === 'asset' ? 'assets' : 'shots'
              );
    } else {
        $laneFree = array_fill(0, $lanes, 0.0);
        foreach ($entries as $n => $entry) {
            $item = $entry['item'];
            $lane = 0;
            for ($i = 1; $i < $lanes; $i++) {
                if ($laneFree[$i] < $laneFree[$lane]) $lane = $i;
            }
            $start = max($laneFree[$lane], (float)$entry['earliest'], (float)$earliestStep);
            $len   = $duration($item);

            $task = scheduler_make_task(
                $axis, $projectId, $step, $item, $item['key'], $item['name'],
                $start, $len, $lane + 1, $lanes, !empty($item['blocked']), $n
            );
            if ($task === null) break;   // ran off the end of the calendar

            $laneFree[$lane] = $start + $len;
            $s['tasks'][] = $task;
            $s['item_end'][$item['key']] = $task['end_index'];
            $s['end_index'] = $s['end_index'] === null
                ? $task['end_index'] : max($s['end_index'], $task['end_index']);
        }
        $s['scheduled'] = count($s['tasks']);
    }

    // --- did it land on time? ------------------------------------------------
    if ($s['tasks']) {
        $starts = array_column($s['tasks'], 'start_date');
        $ends   = array_column($s['tasks'], 'end_date');
        $s['start_date'] = min($starts);
        $s['end_date']   = max($ends);

        if ($s['deadline'] && $s['end_date'] > $s['deadline']) {
            $s['late_days'] = working_days_after($s['deadline'], $s['end_date']);
        }
    }
    if (!$s['deadline']) {
        $s['notes'][] = 'No deadline on this step and none on the project, so there is nothing ' .
                        'to check the finish date against.';
    }

    return $s;
}

/**
 * How many lanes run side by side.
 *
 * Headcount is used AS PLANNED when it is set, even when it isn't enough. The
 * alternative — quietly adding lanes until the date is met — draws a schedule
 * for a team that doesn't exist, and hides the one fact the producer needs.
 * Falling short shows up as a late finish and is reported as such.
 *
 * With no headcount, the number is derived from the deadline. It is NEVER
 * defaulted to 1: that assumption is what turned a three-week schedule into nine
 * months on STFC.
 */
function scheduler_lane_count(
    array $step, float $totalWork, int $earliestStep, ?string $deadline,
    array &$axis, int $itemCount, string $anchor
): array {
    $notes = [];
    $planned = $step['planned_headcount'] !== null ? (float)$step['planned_headcount'] : null;

    if ($planned !== null && $planned > 0) {
        $lanes = max(1, (int)ceil($planned));
        if (abs($lanes - $planned) > 0.001) {
            $notes[] = sprintf(
                '%s people planned, drawn as %d lanes — a part-time person still occupies a lane, ' .
                'they just fill it more slowly.',
                fmt_num($planned), $lanes
            );
        }
        return [$lanes, 'planned', $notes];
    }

    // More lanes than there are items is meaningless, and it is also the guard
    // that stops an impossible date asking for four thousand compositors.
    $cap = max(1, $itemCount);

    if ($deadline && $deadline >= $anchor) {
        $endIdx = axis_index($axis, $deadline);
        if ($endIdx !== null) {
            $available = max(1, $endIdx - $earliestStep + 1);
            $lanes = max(1, (int)ceil($totalWork / $available));
            if ($lanes > $cap) {
                $notes[] = sprintf(
                    'Hitting %s would take more than %d people working in parallel, which is more ' .
                    'than there are items to work on. Capped at %d — the finish date below is the ' .
                    'earliest this step can manage, not the deadline.',
                    $deadline, $cap, $cap
                );
                return [$cap, 'capped', $notes];
            }
            $notes[] = sprintf(
                'No headcount set, so %d %s assumed — the number needed to finish %s days of work ' .
                'in the %d working day%s before %s.',
                $lanes, $lanes === 1 ? 'person is' : 'people are',
                fmt_rate($totalWork), $available, $available === 1 ? '' : 's', $deadline
            );
            return [$lanes, 'deadline', $notes];
        }
    }

    if ($deadline && $deadline < $anchor) {
        $notes[] = 'The deadline (' . $deadline . ') has already passed, so it can\'t say how many ' .
                   'people are needed. One person is assumed — set a headcount to get real dates.';
        return [1, 'fallback', $notes];
    }

    $notes[] = 'No headcount and no deadline, so one person is assumed. That is almost ' .
               'certainly wrong — set either on the Dates & headcount screen.';
    return [1, 'fallback', $notes];
}

/**
 * The earliest working-day index an item can start: the day after everything it
 * waits for has finished.
 *
 * Dependencies are resolved as specifically as the data allows:
 *   1. the same item in the step it waits for — a shot's comp waits for that
 *      shot's lighting, not for all lighting;
 *   2. failing that, for a shot waiting on an asset step, the assets that shot
 *      actually uses — assembly waits for ITS characters to be rigged;
 *   3. failing that, the whole step — which is right for a band, for a
 *      whole-project deliverable, and whenever nothing finer is known.
 */
function scheduler_item_earliest(
    array $item, array $step, array $depsMap, array $stepEnd, array $itemEnd,
    array $shotAssets, int $earliestStep
): float {
    $earliest = (float)$earliestStep;

    foreach ($depsMap[(int)$step['id']] ?? [] as $depId) {
        $depId = (int)$depId;
        $end = null;

        if (isset($itemEnd[$depId][$item['key']])) {
            $end = $itemEnd[$depId][$item['key']];                       // 1
        } elseif ($item['kind'] === 'shot' && !empty($shotAssets[$item['id']])) {
            foreach ($shotAssets[$item['id']] as $assetId) {             // 2
                $k = 'a' . $assetId;
                if (isset($itemEnd[$depId][$k])) {
                    $end = $end === null ? $itemEnd[$depId][$k] : max($end, $itemEnd[$depId][$k]);
                }
            }
        }
        if ($end === null && isset($stepEnd[$depId])) {
            $end = $stepEnd[$depId];                                     // 3
        }
        // A step that produced nothing at all (everything approved, everything
        // N/A) imposes no wait, which is correct: there is nothing to wait for.
        if ($end === null) continue;

        // "The day after it finishes" — handover happens between days, not
        // mid-afternoon, even when a task itself is half a day long.
        $earliest = max($earliest, floor($end) + 1);
    }

    return $earliest;
}

/**
 * Turn a fractional span on the working-day axis into a row with real dates.
 *
 * The internal maths is fractional on purpose: half-day tasks are something the
 * planning screen explicitly invites ("0.5 is half a day"). Rounding each one up
 * to a whole day would double the length of any step made of them. Two half-day
 * tasks share a date here, which is what actually happens.
 *
 * Both dates are INCLUSIVE, matching the rule everywhere else in the platform.
 * Returns null if the span runs past the end of the calendar.
 */
function scheduler_make_task(
    array &$axis, int $projectId, array $step, ?array $item, string $itemKey, string $label,
    float $start, float $length, int $lane, int $laneCount, bool $blocked, int $sort
): ?array {
    $startIdx = (int)floor($start);
    // Exclusive end, so a task of length 1 starting at 0 covers day 0 only.
    $endIdx   = max($startIdx, (int)ceil($start + $length) - 1);

    $startDate = axis_date($axis, $startIdx);
    $endDate   = axis_date($axis, $endIdx);
    if ($startDate === null || $endDate === null) return null;

    return [
        'project_id' => $projectId,
        'step_id'    => (int)$step['id'],
        'step_name'  => $step['name'],
        'color'      => $step['color'],
        'shot_id'    => ($item && $item['kind'] === 'shot')  ? $item['id'] : null,
        'asset_id'   => ($item && $item['kind'] === 'asset') ? $item['id'] : null,
        'item_key'   => $itemKey,
        'label'      => $label,
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'lane'       => $lane,
        'lane_label' => lane_label($step['name'], $lane, $laneCount),
        'blocked'    => $blocked,
        'end_index'  => $endIdx,
        'sort'       => $sort,
    ];
}

/**
 * Write a computed plan.
 *
 * Replaces only what a previous run wrote (source = 'auto', is_manual = 0).
 * Hand-planned and .gan-imported rows are never in that set, so they survive
 * untouched — and because the pass skips any (step, item) that already has a
 * manual row, nothing it writes can collide with one.
 *
 * Returns ['deleted' => int, 'written' => int].
 */
function commit_schedule(PDO $pdo, int $projectId, array $plan): array {
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare(
            "DELETE FROM schedule WHERE project_id = ? AND is_manual = 0 AND source = 'auto'"
        );
        $del->execute([$projectId]);
        $deleted = $del->rowCount();

        $ins = $pdo->prepare(
            "INSERT INTO schedule
                (project_id, step_id, shot_id, asset_id, item_key, start_date, end_date,
                 crew_id, lane, is_manual, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 0, 'auto')"
        );
        $written = 0;
        foreach ($plan['tasks'] as $t) {
            // crew_id stays NULL on purpose: there is no record yet of who does
            // what discipline, and a name on a task reads as a real assignment.
            // The lane carries a placeholder name instead ("Compositing 02"),
            // derived from the step at display time so renaming the step renames
            // the lanes with it.
            $ins->execute([
                $projectId, $t['step_id'], $t['shot_id'], $t['asset_id'], $t['item_key'],
                $t['start_date'], $t['end_date'], $t['lane'],
            ]);
            $written++;
        }
        $pdo->commit();
        return ['deleted' => $deleted, 'written' => $written];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** The schedule as stored, for the "what's saved" view. */
function stored_schedule(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT sc.*, ps.name AS step_name, ps.color, ps.applies_to,
                sh.shot_code, sh.episode, a.name AS asset_name,
                c.name AS crew_name
           FROM schedule sc
           JOIN pipeline_steps ps ON ps.id = sc.step_id
           LEFT JOIN shots  sh ON sh.id = sc.shot_id
           LEFT JOIN assets a  ON a.id  = sc.asset_id
           LEFT JOIN crew   c  ON c.id  = sc.crew_id
          WHERE sc.project_id = ?
          ORDER BY sc.start_date, sc.end_date, ps.step_order, sc.id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}
