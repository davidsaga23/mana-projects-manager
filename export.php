<?php
// Export a project's setup as CSV.
//
// Built to feed an expense projection. The reference sheet
// (MSF_SBND_ExpenseProjection_v02.xlsx) works one row per role per artist, and
// its Notes column shows the arithmetic behind each line:
//
//     "20 shots × 2 d/u + 10% RTK = 44 d ÷ 3 artists"
//
// Every part of that except the retake allowance is already known here, so this
// export hands over the finished sums — work units, days per item, total
// person-days, headcount, days per person — rather than making anyone rebuild
// them from the shot list.
//
// It deliberately carries NO rates and NO money. Those live in the separate
// finance application with its own database and logins (docs/DECISIONS.md,
// 2026-08-12). This file is the bridge between the two: production numbers out,
// costs applied on the other side.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/quota.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

// `items` is the original combined file and stays exactly as it was — the
// Export tab links to it and somebody may have the URL. `shots` and `assets`
// are the same data split, added 2026-09-02 so the Overview can offer them as
// separate buttons rather than one link called "the shot and asset list".
$what = $_GET['what'] ?? 'steps';
if (!in_array($what, ['steps', 'items', 'shots', 'assets'], true)) $what = 'steps';

$stamp = date('Y-m-d');
$name  = preg_replace('/[^A-Za-z0-9_-]/', '', $project['code']) ?: 'project';
// Capitalised to match the other exports: RTC_Steps_2026-08-31.csv.
$file  = $name . '_' . ucfirst($what) . '_' . $stamp . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');

$out = fopen('php://output', 'w');
// Excel opens UTF-8 CSV as the local codepage unless there is a byte-order mark,
// which mangles accented names like "Santiago Bernabéu" and "Ousmane Dembelé".
fwrite($out, "\xEF\xBB\xBF");

if (in_array($what, ['items', 'shots', 'assets'], true)) {
    // The breakdown itself — one row per shot or asset.
    //
    // The COMBINED file keeps its original columns unchanged. The two split
    // files get their own, because a shot-only sheet with an empty "Type" column
    // and an asset-only sheet with empty "Seconds" and "Frames" would be a sheet
    // half made of columns that can never be filled.
    $wantShots  = ($what !== 'assets');
    $wantAssets = ($what !== 'shots');

    if ($what === 'items') {
        fputcsv($out, ['Project', 'Code', 'Kind', 'Episode / Type', 'Name', 'Seconds', 'Frames']);
    } elseif ($what === 'shots') {
        fputcsv($out, ['Project', 'Code', 'Episode', 'Shot', 'Seconds', 'Frames']);
    } else {
        fputcsv($out, ['Project', 'Code', 'Type', 'Asset']);
    }

    if ($wantShots) {
        $stmt = $pdo->prepare('SELECT * FROM shots WHERE project_id = ? ORDER BY episode, shot_code');
        $stmt->execute([$projectId]);
        foreach ($stmt as $r) {
            $secs   = $r['placeholder_seconds'] !== null ? fmt_num($r['placeholder_seconds'], 3) : '';
            $frames = $r['frames'] !== null ? (int)$r['frames'] : '';
            fputcsv($out, $what === 'items'
                ? [$project['name'], $project['code'], 'Shot', $r['episode'], $r['shot_code'], $secs, $frames]
                : [$project['name'], $project['code'], $r['episode'], $r['shot_code'], $secs, $frames]);
        }
    }
    if ($wantAssets) {
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE project_id = ? ORDER BY asset_type, name");
        $stmt->execute([$projectId]);
        foreach ($stmt as $r) {
            fputcsv($out, $what === 'items'
                ? [$project['name'], $project['code'], 'Asset', $r['asset_type'] ?? '', $r['name'], '', '']
                : [$project['name'], $project['code'], $r['asset_type'] ?? '', $r['name']]);
        }
    }
    fclose($out);
    exit;
}

// --- steps: the sheet that feeds a projection -------------------------------
$steps = pipeline_steps($pdo, $projectId);
$quotas = project_quotas($pdo, $projectId, $steps);

// A working month, so "total months" can be worked out the same way the
// reference sheet does it.
const WORKING_DAYS_PER_MONTH = 22;

$retakePct = project_retake_pct($pdo, $projectId);
$retake    = retake_factor($retakePct);

fputcsv($out, [
    'Project', 'Code', 'Step', 'Applies to', 'Measured in',
    'Work units', 'Unit', 'Covers %', 'Full scope units',
    'Days per unit', 'Retakes %', 'Base person-days', 'Total person-days',
    'Total person-months',
    'Planned people', 'Days per person', 'People needed for the date',
    'Starts', 'Deadline', 'Working days available',
    'Figures from', 'Notes',
]);

foreach ($quotas as $q) {
    $s = $q['step'];
    $units      = (float)$q['units'];
    // The number the plan runs on, not the raw column — this file feeds an
    // expense projection and must agree with the one on screen.
    $perUnit    = effective_days_per_unit($pdo, $projectId, $s);
    $baseDays   = $units * $perUnit;
    $personDays = $baseDays * $retake;   // retakes lengthen each item, see quota.php
    $head       = $q['headcount'];
    $coverage   = (float)($q['coverage'] ?? 1);

    // The same sentence the reference sheet keeps in its Notes column, so a
    // reader can check the arithmetic without opening this app.
    $note = fmt_num($units, 2) . ' ' . $q['unit_label'] . ' × ' . fmt_num($perUnit, 2) . ' d/u'
          . ($coverage < 1 ? ' (' . round($coverage * 100) . '% of ' . fmt_num((float)$q['units_raw'], 2) . ')' : '')
          . ($retakePct > 0 ? ' + ' . fmt_num($retakePct) . '% RTK' : '')
          . ' = ' . fmt_num($personDays, 2) . ' person-days'
          . ($head ? ' ÷ ' . fmt_num($head) . ' people' : '');

    fputcsv($out, [
        $project['name'],
        $project['code'],
        $s['name'],
        applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? ''),
        $s['tracks_seconds'] ? 'seconds' : 'items',
        fmt_num($units, 2),
        $q['unit_label'],
        fmt_num($coverage * 100),
        fmt_num((float)($q['units_raw'] ?? $units), 2),
        fmt_num($perUnit, 2),
        fmt_num($retakePct),
        fmt_num($baseDays, 2),
        fmt_num($personDays, 2),
        fmt_num($personDays / WORKING_DAYS_PER_MONTH, 3),
        $head !== null ? fmt_num($head) : '',
        ($head && $head > 0) ? fmt_num($personDays / $head, 2) : '',
        $q['required_headcount'] !== null ? (int)$q['required_headcount'] : '',
        $s['start_date'] ?? '',
        $s['deadline'] ?? '',
        $q['working_days'] !== null ? (int)$q['working_days'] : '',
        !empty($q['estimated']) ? 'pre-production estimate' : 'actual breakdown',
        $note,
    ]);
}

// A trailing total, because the first question anyone asks a projection is
// "what does the whole thing come to?".
$baseTotal = 0.0;
foreach ($quotas as $q) {
    $baseTotal += (float)$q['units'] * effective_days_per_unit($pdo, $projectId, $q['step']);
}
$totalDays = $baseTotal * $retake;
fputcsv($out, []);
fputcsv($out, [
    $project['name'], $project['code'], 'TOTAL', '', '', '', '', '', '', '',
    fmt_num($retakePct),
    fmt_num($baseTotal, 2),
    fmt_num($totalDays, 2),
    fmt_num($totalDays / WORKING_DAYS_PER_MONTH, 3),
    '', '', '', $project['start_date'] ?? '', $project['delivery_date'] ?? '', '',
    '',
    'Rates and costs are deliberately not in this file, they live in the finance system.',
]);

fclose($out);
