<?php
// The pipeline workflow diagram as a file you can send someone.
//
// SVG rather than PNG, for three reasons that all matter here:
//   * the diagram is WIDER THAN THE SCREEN — it scrolls sideways on the setup
//     page, and the first thing anyone wants is to see all of it at once;
//   * it stays sharp at any size, so it survives being dropped into a deck,
//     printed at A3, or zoomed into on a phone;
//   * it needs no image library. This server has no guarantee of GD or
//     Imagick, and a download that 500s on the day it is needed is worse than
//     one format fewer.
//
// Every browser opens it, and Illustrator, Figma, Word and PowerPoint all place
// it directly.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
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

// Built inline, not via wizard_url(): that function lives in wizard.php, which
// is a PAGE and not an include, so calling it from here would be a fatal on the
// one path this file exists to serve. Step 3 is the Workflow screen.
$back = 'wizard.php?project=' . $projectId . '&step=3';

$steps = pipeline_steps($pdo, $projectId);
if (!$steps) {
    // Reported, never a broken download (lesson 7). An empty SVG would arrive
    // looking like the export was the thing that failed.
    flash('err', 'There are no pipeline steps on this project yet, so there is no workflow to export.');
    header('Location: ' . $back);
    exit;
}

$depsMap = step_dependencies_map($pdo, $projectId);

// Named so the file still says what it is a month later in a downloads folder.
$caption = $project['code'] . ' · ' . $project['name'] . ' — pipeline workflow, '
         . date('j M Y');

$svg = workflow_diagram_svg($steps, $depsMap, true, $caption);

// The code can hold anything a person typed, so it is reduced to characters a
// filename can carry on every operating system rather than trusted.
$slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$project['code']);
$slug = trim($slug, '-');
if ($slug === '') $slug = 'project';
$filename = $slug . '-workflow-' . date('Y-m-d') . '.svg';

header('Content-Type: image/svg+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($svg));
// A workflow changes whenever somebody re-ticks a box, so a cached copy is a
// wrong copy.
header('Cache-Control: no-store');
echo $svg;
