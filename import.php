<?php
// Import a Plaky board export into a project.
//
// Four screens: upload, map, preview, result. Nothing is written until the
// preview has been seen and confirmed, and the preview is produced by the same
// code that does the real thing — so it cannot promise something different.
//
// Choices made on the mapping screen are remembered (status_mapping,
// crew_aliases, name_aliases), so the second import of the same board is
// almost entirely automatic.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require __DIR__ . '/includes/names.php';
require __DIR__ . '/includes/plaky_import.php';
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

$selfUrl = 'import.php?project=' . $projectId;
$state = &$_SESSION['import'][$projectId];

/** Where uploaded files wait between screens. Never web-reachable. */
function import_dir(): string {
    $dir = __DIR__ . '/uploads/imports';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function import_clear(int $projectId): void {
    if (!empty($_SESSION['import'][$projectId]['file'])) {
        @unlink($_SESSION['import'][$projectId]['file']);
    }
    unset($_SESSION['import'][$projectId]);
}

$step = $_GET['step'] ?? 'upload';

// ---------------------------------------------------------------- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'cancel') {
            import_clear($projectId);
            flash('ok', 'Import cancelled. Nothing was changed.');
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $codes = [
                    UPLOAD_ERR_INI_SIZE  => 'The file is larger than the server allows.',
                    UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form allows.',
                    UPLOAD_ERR_PARTIAL   => 'The upload was interrupted — try again.',
                    UPLOAD_ERR_NO_FILE   => 'Choose a file first.',
                ];
                throw new RuntimeException($codes[$_FILES['file']['error'] ?? -1] ?? 'The upload failed.');
            }
            $orig = $_FILES['file']['name'];
            if (!preg_match('/\.csv$/i', $orig)) {
                throw new RuntimeException('Export the board as CSV — this importer reads .csv files. ' .
                                           'In Plaky: board menu → Export → CSV.');
            }
            $dest = import_dir() . '/' . bin2hex(random_bytes(12)) . '.csv';
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                throw new RuntimeException('The file could not be saved on the server. ' .
                                           'Check that the uploads folder is writable.');
            }
            // Parse now so a bad file is rejected here rather than three screens later.
            $parsed = plaky_read_csv($dest);

            import_clear($projectId);
            $_SESSION['import'][$projectId] = [
                'file'      => $dest,
                'name'      => $orig,
                'time_unit' => ($_POST['time_unit'] ?? 'minutes') === 'hours' ? 'hours' : 'minutes',
                'rows'      => count($parsed['rows']),
            ];
            flash('ok', 'Read ' . count($parsed['rows']) . ' rows from ' . $orig . '.');
            header('Location: ' . $selfUrl . '&step=map');
            exit;
        }

        if (!$state || empty($state['file'])) {
            throw new RuntimeException('The import session expired. Please upload the file again.');
        }

        if ($action === 'map') {
            $parsed = plaky_read_csv($state['file']);
            $analysis = plaky_analyse($pdo, $projectId, $parsed['rows']);

            // Form keys are positional with the external value in a hidden field,
            // rather than the value itself being the key — names like
            // "Animator.wasi" or anything with a bracket would not survive that.
            $groups = [];
            foreach (($_POST['group_key'] ?? []) as $i => $g) {
                $kind = $_POST['group_val'][$i] ?? 'ignore';
                $groups[$g] = in_array($kind, ['episode', 'asset_type', 'ignore'], true) ? $kind : 'ignore';
            }

            // Steps: an existing step id, "new" to create one, or 0 to skip.
            $steps = [];
            $createdSteps = 0;
            $stepChoices = [];
            foreach (($_POST['step_key'] ?? []) as $i => $name) {
                $stepChoices[$name] = ['choice' => $_POST['step_val'][$i] ?? '0',
                                       'applies' => $_POST['step_applies'][$i] ?? 'shot'];
            }
            foreach ($analysis['steps'] as $s) {
                $choice = $stepChoices[$s['external']]['choice'] ?? '0';
                if ($choice === 'new') {
                    $max = $pdo->prepare('SELECT COALESCE(MAX(step_order),0) FROM pipeline_steps WHERE project_id = ?');
                    $max->execute([$projectId]);
                    [$applies, $assetType] = parse_applies_to($stepChoices[$s['external']]['applies'] ?? 'shot');
                    try {
                        $pdo->prepare(
                            'INSERT INTO pipeline_steps
                                (project_id, name, step_order, applies_to, applies_to_asset_type)
                             VALUES (?, ?, ?, ?, ?)'
                        )->execute([$projectId, $s['external'], (int)$max->fetchColumn() + 1, $applies, $assetType]);
                        $steps[$s['external']] = (int)$pdo->lastInsertId();
                        step_library_remember($pdo, $s['external'], $applies, 0);
                        $createdSteps++;
                    } catch (PDOException $e) {
                        throw new RuntimeException('Could not create the step "' . $s['external'] . '".');
                    }
                } else {
                    $id = (int)$choice;
                    $steps[$s['external']] = $id;
                    // Remember the spelling so the next import matches it by itself.
                    if ($id > 0) {
                        $stmtN = $pdo->prepare('SELECT name FROM pipeline_steps WHERE id = ? AND project_id = ?');
                        $stmtN->execute([$id, $projectId]);
                        $canonical = $stmtN->fetchColumn();
                        if ($canonical && mb_strtolower($canonical) !== mb_strtolower($s['external'])) {
                            remember_name_alias($pdo, $projectId, 'step', $s['external'], $canonical);
                        }
                    }
                }
            }
            if ($createdSteps) {
                resync_workflow($pdo, $projectId);
                flash('ok', "Created $createdSteps new pipeline step" . ($createdSteps === 1 ? '' : 's') .
                            '. Check the workflow screen so they sit in the right order.');
            }

            // Statuses: remembered in status_mapping, so this is asked once.
            $statusChoices = [];
            foreach (($_POST['status_key'] ?? []) as $i => $name) {
                $statusChoices[$name] = (int)($_POST['status_val'][$i] ?? 0);
            }
            $statuses = [];
            foreach ($analysis['statuses'] as $s) {
                $id = $statusChoices[$s['external']] ?? 0;
                $statuses[$s['external']] = $id;
                $pdo->prepare(
                    'INSERT INTO status_mapping (project_id, external_status, status_id, times_seen)
                     VALUES (?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE status_id = ?'
                )->execute([$projectId, $s['external'], $id ?: null, $id ?: null]);
            }

            // People: an existing crew id, "new" to create, or 0 to leave unmatched.
            $peopleChoices = [];
            foreach (($_POST['person_key'] ?? []) as $i => $name) {
                $peopleChoices[$name] = $_POST['person_val'][$i] ?? '0';
            }
            $people = [];
            $createdPeople = 0;
            foreach ($analysis['people'] as $p) {
                $choice = $peopleChoices[$p['name']] ?? '0';
                if ($choice === 'new') {
                    $pdo->prepare('INSERT INTO crew (name, active) VALUES (?, ?)')
                        ->execute([$p['name'], $p['inactive'] ? 0 : 1]);
                    $cid = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
                        ->execute([$cid, $projectId]);
                    $people[$p['name']] = $cid;
                    $createdPeople++;
                } else {
                    $cid = (int)$choice;
                    $people[$p['name']] = $cid;
                    if ($cid > 0) {
                        $stmtN = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
                        $stmtN->execute([$cid]);
                        $canonical = $stmtN->fetchColumn();
                        if ($canonical && mb_strtolower($canonical) !== mb_strtolower($p['name'])) {
                            remember_crew_alias($pdo, $p['name'], $cid);
                        }
                        $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
                            ->execute([$cid, $projectId]);
                    }
                }
            }
            if ($createdPeople) {
                flash('ok', "Added $createdPeople new " . ($createdPeople === 1 ? 'person' : 'people') .
                            ' to the crew list.');
            }

            $state['mapping'] = [
                'groups'         => $groups,
                'steps'          => $steps,
                'statuses'       => $statuses,
                'people'         => $people,
                'time_unit'      => $state['time_unit'],
                'skip_childless' => !empty($_POST['skip_childless']),
            ];
            header('Location: ' . $selfUrl . '&step=preview');
            exit;
        }

        if ($action === 'apply') {
            if (empty($state['mapping'])) throw new RuntimeException('Nothing has been mapped yet.');
            $parsed = plaky_read_csv($state['file']);
            $report = plaky_run($pdo, $projectId, $parsed['rows'], $state['mapping'], false);
            $_SESSION['import_report'][$projectId] = $report;
            import_clear($projectId);
            header('Location: ' . $selfUrl . '&step=done');
            exit;
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        header('Location: ' . $selfUrl . '&step=' . ($step === 'upload' ? 'upload' : $step));
        exit;
    }
}

// ----------------------------------------------------------------- GET -----
$pageTitle = 'Import · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>
<p class="crumb"><a href="project.php?id=<?= $projectId ?>">← Back to <?= e($project['name']) ?></a></p>
<h2 class="wizard-title">Import progress <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php
if ($step === 'done') {
    $report = $_SESSION['import_report'][$projectId] ?? null;
    unset($_SESSION['import_report'][$projectId]);
    include __DIR__ . '/includes/import/done.php';
} elseif ($state && !empty($state['file']) && $step === 'preview') {
    include __DIR__ . '/includes/import/preview.php';
} elseif ($state && !empty($state['file']) && $step === 'map') {
    include __DIR__ . '/includes/import/map.php';
} else {
    include __DIR__ . '/includes/import/upload.php';
}
?>
</div>
</body></html>
