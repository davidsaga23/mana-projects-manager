<?php
// The shot breakdown as an Excel file.
//
// David, 2026-09-02: "once everything's linked, we can export as an excel file
// (button for that) and it should look something similar to the STFC2 dashboard
// and include vehicles, props, graphics, or any other asset needed."
//
// The columns are the project's OWN asset types, generated the same way the
// screen generates them — breakdown_asset_types() is the single source, so the
// sheet and the grid can never disagree about which columns exist or what order
// they come in. A studio that invents "Crowd" next month gets a Crowd column in
// both, by typing it on an asset.
//
// TWO SHEETS' WORTH OF THE SAME FACT, in one file, because the breakdown is
// asked in both directions:
//   the grid      open a shot, see what has to be in it — a call sheet.
//   the index     open an asset, see which shots need it — "this prop is in two
//                 shots" is the difference between building it properly and not
//                 building it at all.
// Written one after the other on a single sheet rather than as two sheets:
// xlsx_build() writes one worksheet, and a second one is not worth the risk to a
// hand-rolled writer for a divider and a heading.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/breakdown.php';
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

$back = 'shots.php?project=' . $projectId;

// ZipArchive can be absent on a hardened host. Said plainly rather than emitting
// a file that will not open — the worst version of "nothing failed visibly".
if (!xlsx_available()) {
    flash('err', 'This server has no ZIP support in PHP, so an Excel file cannot be built. '
               . 'The CSV exports on the Overview screen still work.');
    header('Location: ' . $back);
    exit;
}

$episode = (string)($_GET['episode'] ?? '');
$episodes = breakdown_episodes($pdo, $projectId);
if ($episode !== '' && !in_array($episode, $episodes, true)) $episode = '';

$types = breakdown_asset_types($pdo, $projectId);
$rows  = breakdown_rows($pdo, $projectId, $episode);
if (!$rows) {
    flash('warn', 'There are no shots to export.');
    header('Location: ' . $back);
    exit;
}

$fps = (int)($project['fps'] ?? 24) ?: 24;
$showEpisode = count($episodes) > 1;

$out = [];
$put = function (array $cells) use (&$out) { $out[] = $cells; };

// ---- the title bar ----------------------------------------------------------
$title = $project['name'] . ' (' . $project['code'] . ') · Shot breakdown';
if ($episode !== '') $title .= ' · ' . $episode;
$titleRow = [['v' => $title, 's' => XLSX_TITLE]];
// The band has to be painted across every column or it stops mid-sheet — there
// are no merged cells in this writer, by design.
$width = ($showEpisode ? 1 : 0) + 4 + count($types);
for ($i = 1; $i < $width; $i++) $titleRow[] = ['v' => '', 's' => XLSX_TITLE];
$put($titleRow);

$put([['v' => 'Generated ' . date('Y-m-d') . '. Seconds are frames ÷ ' . $fps
           . ' fps and are what the expense projection prices.', 's' => XLSX_NOTE]]);
$put([]);

// ---- the grid ---------------------------------------------------------------
$head = [];
if ($showEpisode) $head[] = ['v' => 'Ep', 's' => XLSX_HEAD];
$head[] = ['v' => 'Shot', 's' => XLSX_HEAD];
$head[] = ['v' => 'Frames', 's' => XLSX_HEAD];
$head[] = ['v' => 'Seconds', 's' => XLSX_HEAD];
foreach ($types as $t) {
    $head[] = ['v' => $t !== '' ? $t : 'No type set', 's' => XLSX_HEAD];
}
$head[] = ['v' => 'Notes', 's' => XLSX_HEAD];
$put($head);

$shaded = false;
$totalFrames = 0; $totalSeconds = 0.0;
foreach ($rows as $r) {
    $shaded = !$shaded;   // banded rows, like the projection sheet
    $cells = [];
    if ($showEpisode) $cells[] = ['v' => $r['episode'], 's' => xlsx_alt(XLSX_TEXT, $shaded)];
    $cells[] = ['v' => $r['shot_code'], 's' => xlsx_alt(XLSX_TEXT, $shaded)];
    $cells[] = ['v' => $r['frames'] !== null ? (int)$r['frames'] : '',
                's' => xlsx_alt(XLSX_INT, $shaded)];
    $cells[] = ['v' => $r['seconds'] !== null ? round((float)$r['seconds'], 3) : '',
                's' => xlsx_alt(XLSX_NUM, $shaded)];

    foreach ($types as $t) {
        // Comma-separated in one cell, which is how a breakdown is read across.
        // One column per ASSET would make the sheet as wide as the cast list and
        // mostly empty.
        $names = [];
        foreach ($r['assets'][$t] ?? [] as $a) $names[] = $a['name'];

        // ⚠ "N/A" IS NOT THE SAME AS BLANK, and the sheet has to say which it
        // is. Blank means nobody has broken this cell down yet; N/A means
        // somebody looked and there is nothing of this kind in the shot. The
        // person reading the export is usually the one who has to chase the
        // first and must not chase the second.
        //
        // Styled as a note rather than as text, so a column of them stays quiet
        // beside the real asset names — same reasoning as the chip on screen.
        if (!$names && !empty($r['na'][$t])) {
            $cells[] = ['v' => 'N/A', 's' => xlsx_alt(XLSX_NOTE, $shaded)];
        } else {
            $cells[] = ['v' => implode(', ', $names), 's' => xlsx_alt(XLSX_TEXT, $shaded)];
        }
    }
    $cells[] = ['v' => $r['notes'], 's' => xlsx_alt(XLSX_NOTE, $shaded)];
    $put($cells);

    if ($r['frames'] !== null)  $totalFrames  += (int)$r['frames'];
    if ($r['seconds'] !== null) $totalSeconds += (float)$r['seconds'];
}

// ---- the total --------------------------------------------------------------
$tot = [];
if ($showEpisode) $tot[] = ['v' => '', 's' => XLSX_SUB_LABEL];
$tot[] = ['v' => count($rows) . ' shots', 's' => XLSX_SUB_LABEL];
$tot[] = ['v' => $totalFrames ?: '', 's' => XLSX_SUB_NUM];
$tot[] = ['v' => round($totalSeconds, 3), 's' => XLSX_SUB_NUM];
foreach ($types as $t) $tot[] = ['v' => '', 's' => XLSX_SUB_LABEL];
$tot[] = ['v' => '', 's' => XLSX_SUB_LABEL];
$put($tot);

// ---- the same thing, the other way round ------------------------------------
$put([]);
$put([['v' => 'Every asset, and the shots it appears in', 's' => XLSX_BAND],
      ['v' => '', 's' => XLSX_BAND], ['v' => '', 's' => XLSX_BAND],
      ['v' => '', 's' => XLSX_BAND]]);
$put([['v' => 'Type', 's' => XLSX_HEAD], ['v' => 'Asset', 's' => XLSX_HEAD],
      ['v' => 'Shots', 's' => XLSX_HEAD], ['v' => 'Appears in', 's' => XLSX_HEAD]]);

$shaded = false;
foreach (breakdown_by_asset($pdo, $projectId) as $a) {
    $shaded = !$shaded;
    $n = count($a['shots']);
    $put([
        ['v' => $a['type'] !== '' ? $a['type'] : 'No type set', 's' => xlsx_alt(XLSX_TEXT, $shaded)],
        ['v' => $a['name'], 's' => xlsx_alt(XLSX_TEXT, $shaded)],
        // ⚠ AN ASSET IN NO SHOTS IS FLAGGED, not left blank. It is either work
        // nobody has broken down yet or work nobody needs, and both are worth a
        // producer's attention — a silent empty cell is neither.
        ['v' => $n, 's' => $n === 0 ? XLSX_WARN : xlsx_alt(XLSX_INT, $shaded)],
        ['v' => $n ? implode(', ', $a['shots']) : 'not in any shot yet',
         's' => $n === 0 ? XLSX_WARN : xlsx_alt(XLSX_NOTE, $shaded)],
    ]);
}

// Column widths: the asset columns hold comma-separated lists and need room.
$widths = [];
$i = 0;
if ($showEpisode) $widths[$i++] = 8;
$widths[$i++] = 12;    // Shot
$widths[$i++] = 9;     // Frames
$widths[$i++] = 9;     // Seconds
foreach ($types as $t) $widths[$i++] = 30;
$widths[$i] = 34;      // Notes

try {
    $bytes = xlsx_build('Shot breakdown', $out, $widths);
} catch (Throwable $e) {
    flash('err', 'The Excel file could not be built, so nothing was downloaded: ' . $e->getMessage());
    header('Location: ' . $back);
    exit;
}

$suffix = $episode !== '' ? '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $episode) : '';
xlsx_send($bytes, $project['code'] . '_ShotBreakdown' . $suffix . '_' . date('Y-m-d') . '.xlsx');
