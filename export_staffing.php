<?php
// The staffing position of a show, as a sheet to send other supervisors.
//
// David, 2026-09-02: "maybe we also need an export button to download an excel
// file or something I can copy paste to let the other supervisors know about
// these staffing decisions."
//
// ⚠ IT LEADS WITH THE DATES, and that is the difference between a list and a
// message worth sending. "Lighting 03 is empty" is a fact somebody can read and
// do nothing about. "Lighting 03 is empty and the step starts on the 14th" is a
// deadline with somebody's name missing from it. The screen does not need this
// column because you are already looking at the plan; a sheet leaving the
// building does, because the person reading it is not.
//
// Grouped by department, because that is who it is being sent TO: a lighting
// supervisor should be able to find their band and stop reading.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/artists.php';
require_once __DIR__ . '/includes/xlsx.php';
require_login();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$back = 'crew.php?project=' . $projectId;

if (!xlsx_available()) {
    flash('err', 'This server has no ZIP support in PHP, so an Excel file cannot be built.');
    header('Location: ' . $back);
    exit;
}

$lanes = column_exists($pdo, 'crew', 'is_placeholder') ? project_lanes($pdo, $projectId) : [];
if (!$lanes) {
    flash('warn', 'There are no positions to export yet.');
    header('Location: ' . $back);
    exit;
}

$hiringReady = hiring_available($pdo);
$byLane = $hiringReady ? hires_by_lane($pdo, $projectId) : [];
// Freelance or Vendor per person, from the artist database (migration 051).
$filledCrew = [];
foreach ($lanes as $l) {
    if ((int)$l['is_placeholder'] !== 1) $filledCrew[(int)$l['crew_id']] = ['id' => (int)$l['crew_id'], 'name' => (string)$l['name']];
}
$collabTypes = crew_collaborator_types($pdo, array_values($filledCrew));

// How much work is already planned on each track, which is what turns an empty
// position from untidy into urgent.
$laneWork = [];
$lw = $pdo->prepare(
    'SELECT step_id, lane, COUNT(*) AS n FROM schedule WHERE project_id = ? GROUP BY step_id, lane'
);
$lw->execute([$projectId]);
foreach ($lw as $r) $laneWork[(int)$r['step_id'] . '|' . (int)$r['lane']] = (int)$r['n'];

// ---- group exactly the way the screen does ---------------------------------
// Same shape, same order, same "Unassigned last" rule. A sheet that sorted its
// departments differently from the screen it came from would have people
// checking one against the other line by line.
$byStep = [];
foreach ($lanes as $l) $byStep[(int)$l['step_id']][] = $l;

$byDept = [];
foreach ($byStep as $stepId => $rows) {
    $dept = trim((string)($rows[0]['department'] ?? '')) ?: 'Unassigned';
    $byDept[$dept][$stepId] = $rows;
}
uasort($byDept, function ($a, $b) {
    $first = function (array $steps) {
        $min = PHP_INT_MAX;
        foreach ($steps as $rows) $min = min($min, (int)$rows[0]['step_order']);
        return $min;
    };
    return $first($a) <=> $first($b);
});
if (isset($byDept['Unassigned'])) {
    $tail = $byDept['Unassigned'];
    unset($byDept['Unassigned']);
    $byDept['Unassigned'] = $tail;
}

/** The three states the screen paints, in words a sheet can carry. */
function staffing_state(array $lane, array $live): array {
    if ((int)$lane['is_placeholder'] !== 1) return ['Filled', false];
    return $live ? ['Being filled', false] : ['NOBODY YET', true];
}

$out = [];
$put = function (array $cells) use (&$out) { $out[] = $cells; };

$title = $project['name'] . ' (' . $project['code'] . ') · Staffing';
$titleRow = [['v' => $title, 's' => XLSX_TITLE]];
for ($i = 1; $i < 8; $i++) $titleRow[] = ['v' => '', 's' => XLSX_TITLE];
$put($titleRow);
$put([['v' => 'As it stood on ' . date('Y-m-d') . '. One row per position the plan needs.',
       's' => XLSX_NOTE]]);
$put([]);

// ---- the headline, before the detail ---------------------------------------
// Somebody sent this to read one number: how many people are we short. It goes
// at the top, not computed from the rows below by whoever opens it.
$totAll = 0; $totOpen = 0; $totNobody = 0;
foreach ($lanes as $l) {
    $totAll++;
    if ((int)$l['is_placeholder'] !== 1) continue;
    $totOpen++;
    $live = $hiringReady ? array_filter($byLane[(int)$l['id']] ?? [],
        fn($h) => in_array($h['stage'], HIRE_STAGES_OPEN, true)) : [];
    if (!$live) $totNobody++;
}
$put([['v' => 'Positions', 's' => XLSX_SUB_LABEL], ['v' => $totAll, 's' => XLSX_SUB_NUM],
      ['v' => 'Filled', 's' => XLSX_SUB_LABEL], ['v' => $totAll - $totOpen, 's' => XLSX_SUB_NUM],
      ['v' => 'Still to fill', 's' => XLSX_SUB_LABEL], ['v' => $totOpen, 's' => XLSX_SUB_NUM],
      ['v' => 'Nobody in the frame', 's' => XLSX_SUB_LABEL],
      // The one number that should be read as a problem, painted as one.
      ['v' => $totNobody, 's' => $totNobody > 0 ? XLSX_WARN : XLSX_SUB_NUM]]);
$put([]);

// ---- department by department ----------------------------------------------
foreach ($byDept as $deptName => $deptSteps) {
    $open = 0; $nobody = 0; $count = 0;
    foreach ($deptSteps as $rows) {
        foreach ($rows as $l) {
            $count++;
            if ((int)$l['is_placeholder'] !== 1) continue;
            $open++;
            $live = $hiringReady ? array_filter($byLane[(int)$l['id']] ?? [],
                fn($h) => in_array($h['stage'], HIRE_STAGES_OPEN, true)) : [];
            if (!$live) $nobody++;
        }
    }
    $band = $deptName . '  ·  ' . $count . ' position' . ($count === 1 ? '' : 's')
          . ($open > 0 ? ', ' . $open . ' still to fill' : ', all filled')
          . ($nobody > 0 ? ', ' . $nobody . ' with nobody in the frame' : '');
    $bandRow = [['v' => $band, 's' => XLSX_BAND]];
    for ($i = 1; $i < 8; $i++) $bandRow[] = ['v' => '', 's' => XLSX_BAND];
    $put($bandRow);

    $put([['v' => 'Step', 's' => XLSX_HEAD], ['v' => '#', 's' => XLSX_HEAD],
          ['v' => 'State', 's' => XLSX_HEAD], ['v' => 'Who', 's' => XLSX_HEAD],
          ['v' => 'Stage', 's' => XLSX_HEAD],
          ['v' => 'Needed from', 's' => XLSX_HEAD], ['v' => 'Needed by', 's' => XLSX_HEAD],
          ['v' => 'Tasks waiting', 's' => XLSX_HEAD]]);

    $shaded = false;
    foreach ($deptSteps as $rows) {
        foreach ($rows as $l) {
            $shaded = !$shaded;
            $live = $hiringReady ? array_filter($byLane[(int)$l['id']] ?? [],
                fn($h) => in_array($h['stage'], HIRE_STAGES_OPEN, true)) : [];
            [$state, $bad] = staffing_state($l, $live);

            // WHO: the person if filled, otherwise everybody in the frame. A
            // supervisor asking "what is happening about Lighting 03" is asking
            // exactly this, and a count would send them back to the app.
            if ((int)$l['is_placeholder'] === 1) {
                $names = array_map(fn($h) => (string)$h['candidate_name'], $live);
                $who = $names ? implode(', ', $names) : 'nobody yet';
                $stages = [];
                foreach ($live as $h) $stages[] = strtolower(hire_stage_label($h['stage']));
                $stage = $stages ? implode(', ', array_unique($stages)) : '';
            } else {
                $who = (string)$l['name'];
                // Vendor is said in the sheet as on the screen; Freelance, the
                // default, is not, so the column stays a list of names.
                if (($collabTypes[(int)$l['crew_id']] ?? '') === 'vendor') $who .= ' (Vendor)';
                $stage = 'hired';
            }

            $tasks = $laneWork[(int)$l['step_id'] . '|' . (int)$l['lane_no']] ?? 0;
            $txt = xlsx_alt(XLSX_TEXT, $shaded);
            $put([
                ['v' => $l['step_name'] . ' (' . step_scope_tag($l) . ')', 's' => $txt],
                ['v' => (int)$l['lane_no'], 's' => xlsx_alt(XLSX_INT, $shaded)],
                // ⚠ THE WHOLE STATE CELL GOES RED when nobody is in the frame.
                // This sheet is read in a hurry by somebody who did not make the
                // plan, and the screen's colour is the only part of it that
                // survives being sent as a file.
                ['v' => $state, 's' => $bad ? XLSX_WARN : $txt],
                ['v' => $who, 's' => $bad ? XLSX_WARN : $txt],
                ['v' => $stage, 's' => xlsx_alt(XLSX_NOTE, $shaded)],
                ['v' => $l['step_start'] ?: '', 's' => xlsx_alt(XLSX_NOTE, $shaded)],
                ['v' => $l['step_deadline'] ?: '', 's' => xlsx_alt(XLSX_NOTE, $shaded)],
                ['v' => $tasks ?: '', 's' => xlsx_alt(XLSX_INT, $shaded)],
            ]);
        }
    }
    $put([]);
}

$put([['v' => '"Needed from" and "Needed by" are the step\'s own dates, so an empty position with a '
           . 'near date is the one to chase first. "Tasks waiting" is how much is already scheduled '
           . 'on that track and has nobody to do it.', 's' => XLSX_NOTE]]);

$widths = [0 => 30, 1 => 5, 2 => 14, 3 => 34, 4 => 16, 5 => 13, 6 => 13, 7 => 13];

try {
    $bytes = xlsx_build('Staffing', $out, $widths);
} catch (Throwable $e) {
    flash('err', 'The Excel file could not be built, so nothing was downloaded: ' . $e->getMessage());
    header('Location: ' . $back);
    exit;
}

xlsx_send($bytes, $project['code'] . '_Staffing_' . date('Y-m-d') . '.xlsx');
