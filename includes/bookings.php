<?php
// Who is booked where, across every show.
//
// David, 2026-09-21: "is there a way I can see the overlapping of artists across
// projects and general timelines?" The reason crew was made global on day one
// (DECISIONS, 2026-08-11: "people work across shows ... per-project crew makes
// cross-project workload invisible"), and until now nothing read it that way.
//
// A BOOKING is a real person holding a position on a step whose general-timeline
// dates are set: they are needed on that show from the step's start to its
// deadline. Read from crew_lanes (who holds which position, filled by Staffing)
// and pipeline_steps (the dates the general timeline draws), so it says what the
// plans say, not what anybody has typed about availability.
//
// A CLASH is one person booked on two DIFFERENT shows on the same working days.
// Two steps of one show at once are left out on purpose: that is usually one job,
// a department carrying on, and the question asked was about shows.
//
// Read only. No migration: everything here was already stored.

require_once __DIR__ . '/pipeline.php';

/**
 * The shows a booking can come from: in setup, active or on hold. Wrapped and
 * archived shows are over, and their positions would only add clashes nobody
 * can do anything about.
 *
 * ⚠ 'postmortem' (migration 044) is EXCLUDED for exactly that reason, decided
 * 2026-10-08: the production work is finished, so the dated step positions it
 * still holds describe days that have already happened. Including them would
 * report clashes against a show nobody can be booked onto. Note the FIELD()
 * list below names only the three statuses the WHERE lets through, so it does
 * not need 'postmortem' either.
 */
function bookings_projects(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, code, name, status, delivery_date FROM projects
          WHERE status IN ('setup', 'active', 'on_hold')
          ORDER BY FIELD(status, 'active', 'setup', 'on_hold'), name"
    )->fetchAll();
}

/**
 * Every position on those shows, filled or not, with its step's dates.
 *
 * Returns ['bookings' => real people on dated steps, 'undated' => real people
 * on steps with no dates yet (reported, never silently dropped), 'unfilled' =>
 * step id => positions still held by a placeholder].
 */
function bookings_positions(PDO $pdo, array $projectIds): array {
    $out = ['bookings' => [], 'undated' => [], 'unfilled' => []];
    $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
    if (!$projectIds) return $out;

    $hasDept = column_exists($pdo, 'crew', 'department');
    // Integers only, cast above, so they can be written into the list directly.
    $in = implode(',', $projectIds);
    $rows = $pdo->query(
        'SELECT cl.id AS lane_id, cl.lane_no, cl.crew_id, c.name AS person,
                c.is_placeholder, c.is_team' . ($hasDept ? ', c.department' : '') . ',
                cl.project_id, ps.id AS step_id, ps.name AS step_name,
                ps.applies_to, ps.applies_to_asset_type, ps.start_date, ps.deadline
           FROM crew_lanes cl
           JOIN crew c            ON c.id  = cl.crew_id
           JOIN pipeline_steps ps ON ps.id = cl.step_id
          WHERE cl.project_id IN (' . $in . ')
          ORDER BY c.name, ps.start_date, ps.step_order, cl.lane_no'
    )->fetchAll();

    foreach ($rows as $r) {
        if ((int)$r['is_placeholder'] === 1) {
            $out['unfilled'][(int)$r['step_id']] = ($out['unfilled'][(int)$r['step_id']] ?? 0) + 1;
            continue;
        }
        // A team ("Animation") is a valid ticket owner but nobody who can be
        // double-booked.
        if ((int)$r['is_team'] === 1) continue;

        $b = [
            'lane_id'    => (int)$r['lane_id'],
            'person_id'  => (int)$r['crew_id'],
            'person'     => $r['person'],
            'dept'       => $hasDept ? (string)($r['department'] ?? '') : '',
            'project_id' => (int)$r['project_id'],
            'step_id'    => (int)$r['step_id'],
            'step'       => $r['step_name'],
            'scope'      => applies_to_phrase($r['applies_to'], $r['applies_to_asset_type'] ?? null),
            'lane'       => (int)$r['lane_no'],
            'start'      => $r['start_date'] ?: null,
            'end'        => $r['deadline'] ?: null,
        ];
        if (!$b['start'] || !$b['end']) { $out['undated'][] = $b; continue; }
        // A backwards pair would draw as nothing; clamped, as the timeline does.
        if ($b['end'] < $b['start']) $b['end'] = $b['start'];
        $out['bookings'][] = $b;
    }
    return $out;
}

/** Every dated step of those shows, for the By show view, in pipeline order. */
function bookings_steps(PDO $pdo, array $projectIds): array {
    $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
    if (!$projectIds) return [];
    $rows = $pdo->query(
        'SELECT id, project_id, name, applies_to, applies_to_asset_type, start_date, deadline, color
           FROM pipeline_steps
          WHERE project_id IN (' . implode(',', $projectIds) . ')
            AND start_date IS NOT NULL AND deadline IS NOT NULL
          ORDER BY project_id, step_order, id'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $end = $r['deadline'] < $r['start_date'] ? $r['start_date'] : $r['deadline'];
        $out[] = [
            'id'         => (int)$r['id'],
            'project_id' => (int)$r['project_id'],
            'name'       => $r['name'],
            'scope'      => applies_to_phrase($r['applies_to'], $r['applies_to_asset_type'] ?? null),
            'start'      => $r['start_date'],
            'end'        => $end,
            'color'      => $r['color'] ?: '#4A90D9',
        ];
    }
    return $out;
}

/**
 * Every pair of one person's bookings on DIFFERENT shows whose dates cross.
 *
 * The shared stretch runs from the later start to the earlier end, and counts
 * only when it holds a working day: a step ending on a Friday and another
 * starting that Saturday share nothing anybody works (working_days_between(),
 * the studio calendar, holidays included).
 *
 * Returns a list of ['person_id', 'a' => lane id, 'b' => lane id, 'from', 'to',
 * 'days'], by person and then by date.
 */
function bookings_clashes(array $bookings): array {
    $byPerson = [];
    foreach ($bookings as $b) $byPerson[$b['person_id']][] = $b;

    $out = [];
    foreach ($byPerson as $personId => $list) {
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if ($a['project_id'] === $b['project_id']) continue;
                $from = max($a['start'], $b['start']);
                $to   = min($a['end'], $b['end']);
                if ($from > $to) continue;
                $days = working_days_between($from, $to);
                if ($days <= 0) continue;
                $out[] = ['person_id' => (int)$personId, 'a' => $a['lane_id'], 'b' => $b['lane_id'],
                          'from' => $from, 'to' => $to, 'days' => $days];
            }
        }
    }
    usort($out, function ($x, $y) {
        return [$x['person_id'], $x['from']] <=> [$y['person_id'], $y['from']];
    });
    return $out;
}
