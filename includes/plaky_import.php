<?php
// Reading a Plaky board export.
//
// The file's shape is documented in docs/PLAKY_EXPORT.md — read that first; it
// records the traps found in a real export rather than the ones imagined.
//
// The rule throughout: translate onto rows the platform owns, never let the file
// dictate meaning, and report everything that didn't match instead of dropping it.

require_once __DIR__ . '/names.php';
require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/pipeline.php';   // plaky_analyse matches against pipeline_steps

const PLAKY_REQUIRED_COLUMNS = ['Group', 'Item Parent Id', 'Item/Subitem Id', 'Title', 'Status'];

/**
 * Read the CSV into associative rows.
 * Throws with a readable explanation rather than returning something half-formed.
 */
function plaky_read_csv(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('That file could not be read.');
    }
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('That file could not be opened.');

    $headers = fgetcsv($fh);
    if (!$headers) {
        fclose($fh);
        throw new RuntimeException('That file appears to be empty.');
    }
    // Excel and some exporters prepend a byte-order mark, which would otherwise
    // become part of the first column's name and break every lookup on it.
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map('trim', $headers);

    $missing = array_diff(PLAKY_REQUIRED_COLUMNS, $headers);
    if ($missing) {
        fclose($fh);
        throw new RuntimeException(
            'This does not look like a Plaky board export. Missing column' .
            (count($missing) === 1 ? '' : 's') . ': ' . implode(', ', $missing) .
            '. Found: ' . implode(', ', $headers) . '.'
        );
    }

    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) === 1 && trim((string)$data[0]) === '') continue;   // blank line
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        $rows[] = $row;
    }
    fclose($fh);

    if (!$rows) throw new RuntimeException('That file has a header row but no data.');
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Split the flat file into its real structure.
 *
 * A row is a parent when Item Parent Id is empty — that single test separates
 * shots and assets (parents) from pipeline steps on them (subitems).
 */
function plaky_split_rows(array $rows): array {
    $parents = [];      // plaky id => row
    $subitems = [];     // list of rows
    foreach ($rows as $row) {
        if (($row['Item Parent Id'] ?? '') === '') {
            $parents[$row['Item/Subitem Id']] = $row;
        } else {
            $subitems[] = $row;
        }
    }

    $childCount = [];
    foreach ($subitems as $s) {
        $pid = $s['Item Parent Id'];
        $childCount[$pid] = ($childCount[$pid] ?? 0) + 1;
    }

    return ['parents' => $parents, 'subitems' => $subitems, 'child_count' => $childCount];
}

/** Convert the file's time value into whole minutes, honouring the declared unit. */
function plaky_time_to_minutes(string $raw, string $unit): ?int {
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) return null;
    $v = (float)$raw;
    if ($v <= 0) return null;
    return $unit === 'hours' ? (int)round($v * 60) : (int)round($v);
}

/**
 * Everything the mapping screen needs to know about the file, matched against
 * what the project already has.
 */
function plaky_analyse(PDO $pdo, int $projectId, array $rows): array {
    $split = plaky_split_rows($rows);

    // Groups, with what they contain.
    $groups = [];
    foreach ($split['parents'] as $p) {
        $g = $p['Group'];
        $groups[$g] = ($groups[$g] ?? 0) + 1;
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    // Step names, resolved through the alias layer then matched to real steps.
    $stepRows = pipeline_steps($pdo, $projectId);
    $stepsByName = [];
    foreach ($stepRows as $s) $stepsByName[mb_strtolower($s['name'])] = $s;

    $steps = [];
    foreach ($split['subitems'] as $s) {
        $title = $s['Title'];
        if ($title === '') continue;
        $steps[$title] = ($steps[$title] ?? 0) + 1;
    }
    $stepInfo = [];
    foreach ($steps as $title => $count) {
        $canonical = resolve_name_alias($pdo, $projectId, 'step', $title);
        $match = $stepsByName[mb_strtolower($canonical)] ?? null;
        $stepInfo[] = [
            'external'  => $title,
            'canonical' => $canonical,
            'count'     => $count,
            'step_id'   => $match ? (int)$match['id'] : null,
            'step_name' => $match['name'] ?? null,
        ];
    }
    usort($stepInfo, function ($a, $b) { return $b['count'] <=> $a['count']; });

    // Status values.
    $statusCounts = [];
    foreach ($rows as $r) {
        $v = $r['Status'] ?? '';
        if ($v === '') continue;
        $statusCounts[$v] = ($statusCounts[$v] ?? 0) + 1;
    }
    arsort($statusCounts);
    $projectStatuses = project_statuses($pdo, $projectId);
    $byName = [];
    foreach ($projectStatuses as $s) $byName[mb_strtolower($s['name'])] = $s;

    $existingMap = [];
    $stmt = $pdo->prepare('SELECT external_status, status_id FROM status_mapping WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt as $row) $existingMap[$row['external_status']] = $row['status_id'];

    $statusInfo = [];
    foreach ($statusCounts as $value => $count) {
        $statusId = $existingMap[$value] ?? null;
        if ($statusId === null && isset($byName[mb_strtolower($value)])) {
            $statusId = (int)$byName[mb_strtolower($value)]['id'];   // exact name, not a guess
        }
        $statusInfo[] = [
            'external'  => $value,
            'count'     => $count,
            'status_id' => $statusId !== null ? (int)$statusId : null,
        ];
    }

    // People. Names are normalised and split before matching, so "Mayara Sampaio
    // (inactive)" and a two-person field resolve to the individuals they mean.
    $peopleCounts = [];
    $inactive = [];
    foreach ($rows as $r) {
        foreach (split_assignees($r['Assignee'] ?? '') as $person) {
            $peopleCounts[$person['name']] = ($peopleCounts[$person['name']] ?? 0) + 1;
            if ($person['inactive']) $inactive[$person['name']] = true;
        }
    }
    arsort($peopleCounts);
    $peopleInfo = [];
    foreach ($peopleCounts as $name => $count) {
        $crewId = resolve_crew($pdo, $name);
        $peopleInfo[] = [
            'name'     => $name,
            'count'    => $count,
            'crew_id'  => $crewId,
            'inactive' => isset($inactive[$name]),
        ];
    }

    // Parents with no steps under them are reliably not shots or assets —
    // on the reference export these are a supervision-time bucket and two
    // pre-production deliverables.
    $childless = [];
    foreach ($split['parents'] as $id => $p) {
        if (($split['child_count'][$id] ?? 0) === 0) {
            $childless[] = ['id' => $id, 'group' => $p['Group'], 'title' => $p['Title']];
        }
    }

    return [
        'parent_count'  => count($split['parents']),
        'subitem_count' => count($split['subitems']),
        'groups'        => $groups,
        'steps'         => $stepInfo,
        'statuses'      => $statusInfo,
        'people'        => $peopleInfo,
        'childless'     => $childless,
        'project_steps' => $stepRows,
        'project_statuses' => $projectStatuses,
    ];
}

/**
 * Do the import — or work out what it would do.
 *
 * $dryRun true counts everything and writes nothing, which is what the preview
 * screen shows. The same code path produces both, so the preview cannot drift
 * away from what actually happens.
 */
function plaky_run(PDO $pdo, int $projectId, array $rows, array $mapping, bool $dryRun): array {
    $split = plaky_split_rows($rows);
    $timeUnit = ($mapping['time_unit'] ?? 'minutes') === 'hours' ? 'hours' : 'minutes';
    $groupKinds = $mapping['groups'] ?? [];
    $stepMap = $mapping['steps'] ?? [];        // external title => step_id (0/absent = skip)
    $statusMap = $mapping['statuses'] ?? [];   // external status => status_id (0/absent = unmapped)
    $crewMap = $mapping['people'] ?? [];       // person name => crew_id (0/absent = unmatched)
    $skipChildless = !empty($mapping['skip_childless']);

    $report = [
        'shots_created' => 0, 'shots_updated' => 0,
        'assets_created' => 0, 'assets_updated' => 0,
        'progress_created' => 0, 'progress_updated' => 0,
        'skipped_ignored_group' => 0, 'skipped_childless' => 0,
        'skipped_no_step' => 0, 'skipped_no_parent' => 0,
        'unmatched_people' => [], 'unmapped_statuses' => [],
        'notes' => [],
    ];

    // Load what exists once, rather than querying per row.
    $shotIds = [];
    $stmt = $pdo->prepare('SELECT id, episode, shot_code FROM shots WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt as $r) $shotIds[mb_strtolower($r['episode'] . '|' . $r['shot_code'])] = (int)$r['id'];

    $assetIds = [];
    $stmt = $pdo->prepare('SELECT id, name FROM assets WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt as $r) $assetIds[mb_strtolower($r['name'])] = (int)$r['id'];

    $progressIds = [];
    $stmt = $pdo->prepare('SELECT id, step_id, item_key FROM progress WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt as $r) $progressIds[$r['step_id'] . '|' . $r['item_key']] = (int)$r['id'];

    if (!$dryRun) $pdo->beginTransaction();
    try {
        $insShot = $pdo->prepare(
            'INSERT INTO shots (project_id, episode, shot_code, frames, external_id) VALUES (?, ?, ?, ?, ?)'
        );
        $updShot = $pdo->prepare(
            'UPDATE shots SET frames = COALESCE(?, frames), external_id = COALESCE(?, external_id) WHERE id = ?'
        );
        $insAsset = $pdo->prepare(
            'INSERT INTO assets (project_id, name, asset_type, external_id) VALUES (?, ?, ?, ?)'
        );
        $updAsset = $pdo->prepare(
            'UPDATE assets SET asset_type = COALESCE(?, asset_type), external_id = COALESCE(?, external_id) WHERE id = ?'
        );

        // --- parents: shots and assets ---------------------------------------
        $parentTarget = [];   // plaky parent id => ['kind' => 'shot'|'asset', 'id' => int]
        foreach ($split['parents'] as $plakyId => $p) {
            $kind = $groupKinds[$p['Group']] ?? 'ignore';
            if ($kind === 'ignore') { $report['skipped_ignored_group']++; continue; }
            if ($skipChildless && ($split['child_count'][$plakyId] ?? 0) === 0) {
                $report['skipped_childless']++;
                continue;
            }
            $title = $p['Title'];
            if ($title === '') continue;
            $frames = is_numeric($p['Total frames'] ?? '') ? (int)$p['Total frames'] : null;

            if ($kind === 'episode') {
                $key = mb_strtolower($p['Group'] . '|' . $title);
                if (isset($shotIds[$key])) {
                    if (!$dryRun) $updShot->execute([$frames, $plakyId, $shotIds[$key]]);
                    $report['shots_updated']++;
                    $parentTarget[$plakyId] = ['kind' => 'shot', 'id' => $shotIds[$key]];
                } else {
                    if ($dryRun) {
                        $report['shots_created']++;
                        $parentTarget[$plakyId] = ['kind' => 'shot', 'id' => 0];
                    } else {
                        $insShot->execute([$projectId, $p['Group'], $title, $frames, $plakyId]);
                        $newId = (int)$pdo->lastInsertId();
                        $shotIds[$key] = $newId;
                        $report['shots_created']++;
                        $parentTarget[$plakyId] = ['kind' => 'shot', 'id' => $newId];
                    }
                }
            } else {   // asset_type
                $key = mb_strtolower($title);
                if (isset($assetIds[$key])) {
                    if (!$dryRun) $updAsset->execute([$p['Group'], $plakyId, $assetIds[$key]]);
                    $report['assets_updated']++;
                    $parentTarget[$plakyId] = ['kind' => 'asset', 'id' => $assetIds[$key]];
                } else {
                    if ($dryRun) {
                        $report['assets_created']++;
                        $parentTarget[$plakyId] = ['kind' => 'asset', 'id' => 0];
                    } else {
                        $insAsset->execute([$projectId, $title, $p['Group'], $plakyId]);
                        $newId = (int)$pdo->lastInsertId();
                        $assetIds[$key] = $newId;
                        $report['assets_created']++;
                        $parentTarget[$plakyId] = ['kind' => 'asset', 'id' => $newId];
                    }
                }
            }
        }

        // --- subitems: progress ----------------------------------------------
        $statusRows = statuses_by_id($pdo, $projectId);

        $insProg = $pdo->prepare(
            'INSERT INTO progress
                (project_id, step_id, shot_id, asset_id, item_key, status, status_id,
                 is_delivered, is_approved, crew_id, assignee_raw, time_spent_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // COALESCE on the incoming value: a blank cell must never wipe something
        // already recorded. An import adds knowledge, it doesn't erase it.
        $updProg = $pdo->prepare(
            'UPDATE progress SET
                status = ?, status_id = ?, is_delivered = ?, is_approved = ?,
                crew_id = COALESCE(?, crew_id),
                assignee_raw = COALESCE(?, assignee_raw),
                time_spent_minutes = COALESCE(?, time_spent_minutes)
              WHERE id = ?'
        );
        $delCrew = $pdo->prepare('DELETE FROM progress_crew WHERE progress_id = ?');
        $insCrew = $pdo->prepare('INSERT IGNORE INTO progress_crew (progress_id, crew_id) VALUES (?, ?)');

        foreach ($split['subitems'] as $s) {
            $parent = $parentTarget[$s['Item Parent Id']] ?? null;
            if (!$parent) { $report['skipped_no_parent']++; continue; }

            $stepId = (int)($stepMap[$s['Title']] ?? 0);
            if ($stepId <= 0) { $report['skipped_no_step']++; continue; }

            $statusText = $s['Status'] ?? '';
            $statusId = (int)($statusMap[$statusText] ?? 0) ?: null;
            if ($statusText !== '' && $statusId === null) {
                $report['unmapped_statuses'][$statusText] = ($report['unmapped_statuses'][$statusText] ?? 0) + 1;
            }
            $flags = status_flags($statusId ? ($statusRows[$statusId] ?? null) : null);

            $people = split_assignees($s['Assignee'] ?? '');
            $crewIds = [];
            foreach ($people as $person) {
                $cid = (int)($crewMap[$person['name']] ?? 0);
                if ($cid > 0) {
                    $crewIds[] = $cid;
                } else {
                    $report['unmatched_people'][$person['name']] =
                        ($report['unmatched_people'][$person['name']] ?? 0) + 1;
                }
            }
            $primaryCrew = $crewIds[0] ?? null;
            $assigneeRaw = ($s['Assignee'] ?? '') !== '' ? $s['Assignee'] : null;
            $minutes = plaky_time_to_minutes($s['Time spent'] ?? '', $timeUnit);

            $itemKey = ($parent['kind'] === 'shot' ? 's' : 'a') . $parent['id'];
            $existingId = $progressIds[$stepId . '|' . $itemKey] ?? null;

            if ($dryRun) {
                // A parent created in this same dry run has id 0, so its progress
                // rows are necessarily new too.
                if ($parent['id'] > 0 && $existingId) $report['progress_updated']++;
                else $report['progress_created']++;
                continue;
            }

            if ($existingId) {
                $updProg->execute([
                    $statusText, $statusId, $flags['is_delivered'], $flags['is_approved'],
                    $primaryCrew, $assigneeRaw, $minutes, $existingId,
                ]);
                $report['progress_updated']++;
                $progressId = $existingId;
            } else {
                $insProg->execute([
                    $projectId, $stepId,
                    $parent['kind'] === 'shot'  ? $parent['id'] : null,
                    $parent['kind'] === 'asset' ? $parent['id'] : null,
                    $itemKey, $statusText, $statusId,
                    $flags['is_delivered'], $flags['is_approved'],
                    $primaryCrew, $assigneeRaw, $minutes,
                ]);
                $progressId = (int)$pdo->lastInsertId();
                $progressIds[$stepId . '|' . $itemKey] = $progressId;
                $report['progress_created']++;
            }

            // Rewrite the full assignee set — the file is authoritative about who
            // is on a task, so someone removed there is removed here.
            if ($people) {
                $delCrew->execute([$progressId]);
                foreach ($crewIds as $cid) $insCrew->execute([$progressId, $cid]);
            }
        }

        if (!$dryRun) $pdo->commit();
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    arsort($report['unmatched_people']);
    arsort($report['unmapped_statuses']);
    return $report;
}
