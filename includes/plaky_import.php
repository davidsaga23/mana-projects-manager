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
require_once __DIR__ . '/activity.php';   // the status-change log (migration 021)

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

/**
 * Does a Plaky group name mean the same thing as an asset type?
 *
 * Boards are pluralised ("Characters"), asset types usually aren't
 * ("Character"). Comparing both with a trailing "s" trimmed covers that without
 * pretending to know English grammar.
 */
function plaky_group_matches_type(string $group, string $assetType): bool {
    $g = mb_strtolower(trim($group));
    $t = mb_strtolower(trim($assetType));
    if ($g === '' || $t === '') return false;
    return $g === $t || rtrim($g, 's') === rtrim($t, 's');
}

/**
 * The spelling an imported asset's type should be written as.
 *
 * The board says "Environments". The project already calls that "Environment",
 * and its pipeline steps are scoped to the singular. Writing the group name
 * verbatim — which is what this importer did — creates a SECOND type differing
 * only by an "s", and a step scoped to one then counts none of the other while
 * reporting a perfectly healthy-looking number.
 *
 * plaky_group_matches_type() above already encodes that boards are pluralised
 * and types aren't. It was only ever applied when READING a group to find a
 * step. This applies the same knowledge when writing, which is where it was
 * missing.
 *
 * Order matters: what a pipeline step is scoped to wins, because that is the
 * spelling the plan is built against and therefore the one that makes the step
 * count these assets at all.
 */
function plaky_canonical_asset_type(PDO $pdo, int $projectId, string $group): string {
    static $cache = [];
    $group = trim($group);
    $key = $projectId . '|' . mb_strtolower($group);
    if (isset($cache[$key])) return $cache[$key];
    if ($group === '') return $cache[$key] = '';

    // 1. A type a pipeline step is scoped to.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT applies_to_asset_type FROM pipeline_steps
          WHERE project_id = ? AND applies_to_asset_type <> ''"
    );
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (plaky_group_matches_type($group, (string)$t)) return $cache[$key] = (string)$t;
    }

    // 2. A spelling this project's assets already use.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT asset_type FROM assets
          WHERE project_id = ? AND asset_type IS NOT NULL AND asset_type <> ''"
    );
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (plaky_group_matches_type($group, (string)$t)) return $cache[$key] = (string)$t;
    }

    // 3. A built-in starter, so different shows converge on one vocabulary.
    foreach (['Character', 'Environment', 'Prop', 'Vehicle', 'FX'] as $t) {
        if (plaky_group_matches_type($group, $t)) return $cache[$key] = $t;
    }

    // 4. Genuinely new. The group name becomes the type, as it always did.
    return $cache[$key] = $group;
}

/**
 * Pick the best project step for a name arriving under a given group.
 *
 * The name has to match. Beyond that, a step scoped to the asset type this group
 * represents wins over an unscoped one — which is what tells "Concept · Character"
 * apart from "Concept · Environment" when the file just says "Concept".
 *
 * Returns null when nothing matches, or when two equally good candidates exist:
 * guessing between them would be worse than asking.
 */
function plaky_best_step(array $steps, string $name, string $group): ?array {
    $needle = mb_strtolower(trim($name));
    $scoped = [];
    $unscoped = [];
    foreach ($steps as $s) {
        if (mb_strtolower($s['name']) !== $needle) continue;
        $type = $s['applies_to_asset_type'] ?? '';
        if ($type !== '' && plaky_group_matches_type($group, $type)) $scoped[] = $s;
        elseif ($type === '') $unscoped[] = $s;
    }
    if (count($scoped) === 1) return $scoped[0];
    if (!$scoped && count($unscoped) === 1) return $unscoped[0];
    return null;
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

    // Project-level steps, matched by name — used below to spot a group that is
    // really a set of whole-project deliverables rather than shots or assets.
    $projectStepsByName = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) {
        if ($s['applies_to'] === 'project') $projectStepsByName[mb_strtolower($s['name'])] = $s;
    }

    // Groups, with enough detail to suggest what each one is.
    $groups = [];
    foreach ($split['parents'] as $plakyId => $p) {
        $g = $p['Group'];
        if (!isset($groups[$g])) {
            $groups[$g] = ['count' => 0, 'childless' => 0, 'project_step_matches' => 0];
        }
        $groups[$g]['count']++;
        if (($split['child_count'][$plakyId] ?? 0) === 0) $groups[$g]['childless']++;
        $canonical = resolve_name_alias($pdo, $projectId, 'step', $p['Title']);
        if (isset($projectStepsByName[mb_strtolower($canonical)])) $groups[$g]['project_step_matches']++;
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    // A suggestion per group, never applied silently — it arrives pre-selected in
    // a dropdown the user still has to look at.
    foreach ($groups as $name => $info) {
        if ($info['count'] > 0 && $info['project_step_matches'] === $info['count']) {
            $guess = 'project_step';           // every item names a whole-project step
        } elseif (preg_match('/^(ep|episode)\s*\d+/i', $name)) {
            $guess = 'episode';
        } elseif (preg_match('/^shots?$/i', trim($name))) {
            $guess = 'shots';                  // a group literally called "Shots"
        } else {
            $guess = 'asset_type';
        }
        $groups[$name]['guess'] = $guess;
    }

    // Step names, keyed by the GROUP they appear under as well as the name.
    //
    // "Concept" under Characters and "Concept" under Environments are different
    // steps with different scopes, so one dropdown per name could never map both.
    // The parent's group is what distinguishes them.
    $stepRows = pipeline_steps($pdo, $projectId);

    $seen = [];
    foreach ($split['subitems'] as $s) {
        $title = $s['Title'];
        if ($title === '') continue;
        $parent = $split['parents'][$s['Item Parent Id']] ?? null;
        $group = $parent['Group'] ?? '';
        $key = $group . "\x1F" . $title;
        if (!isset($seen[$key])) {
            $seen[$key] = ['group' => $group, 'external' => $title, 'count' => 0];
        }
        $seen[$key]['count']++;
    }

    $stepInfo = [];
    foreach ($seen as $key => $row) {
        $canonical = resolve_name_alias($pdo, $projectId, 'step', $row['external']);
        $match = plaky_best_step($stepRows, $canonical, $row['group']);
        $stepInfo[] = [
            'key'       => $key,
            'group'     => $row['group'],
            'external'  => $row['external'],
            'canonical' => $canonical,
            'count'     => $row['count'],
            'step_id'   => $match ? (int)$match['id'] : null,
            'step_name' => $match['name'] ?? null,
        ];
    }
    // Grouped in the order the groups appear, then commonest first inside each.
    usort($stepInfo, function ($a, $b) {
        return [$a['group'], -$a['count']] <=> [$b['group'], -$b['count']];
    });

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

    // step id => name, for the change log. A log entry saying "step 412" is
    // worthless once somebody renames or deletes that step, which is precisely
    // when a log gets read (migration 021).
    $stepNames = [];
    $sn = $pdo->prepare('SELECT id, name FROM pipeline_steps WHERE project_id = ?');
    $sn->execute([$projectId]);
    foreach ($sn as $r) $stepNames[(int)$r['id']] = $r['name'];

    $report = [
        'shots_created' => 0, 'shots_updated' => 0,
        'assets_created' => 0, 'assets_updated' => 0,
        'progress_created' => 0, 'progress_updated' => 0,
        'skipped_ignored_group' => 0, 'skipped_childless' => 0,
        'skipped_no_step' => 0, 'skipped_no_parent' => 0,
        'skipped_no_project_step' => 0,
        'unmatched_people' => [], 'unmapped_statuses' => [], 'unmatched_project_steps' => [],
        // Board group name => the asset type it was written as, where the two
        // differ. Reported rather than done quietly: an importer that renames
        // things without saying so is the same silent-change problem as one that
        // creates duplicates without saying so.
        'types_normalised' => [],
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

    // id AND current status. The status is what makes the change log possible:
    // once the UPDATE has run, what the row said before is gone forever, so it
    // has to be read before the write, not after.
    $progressIds = [];
    $progressWas = [];
    $stmt = $pdo->prepare('SELECT id, step_id, item_key, status_id FROM progress WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt as $r) {
        $k = $r['step_id'] . '|' . $r['item_key'];
        $progressIds[$k] = (int)$r['id'];
        $progressWas[$k] = $r['status_id'] !== null ? (int)$r['status_id'] : null;
    }

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

        // Whole-project steps, for groups mapped to 'project_step'.
        $projectStepsByName = [];
        foreach (pipeline_steps($pdo, $projectId) as $s) {
            if ($s['applies_to'] === 'project') $projectStepsByName[mb_strtolower($s['name'])] = $s;
        }
        $statusRowsEarly = statuses_by_id($pdo, $projectId);
        $projectItemKey = 'p' . $projectId;   // one row per project step, by definition

        // --- parents: shots, assets and whole-project deliverables ------------
        $parentTarget = [];   // plaky parent id => ['kind' => 'shot'|'asset', 'id' => int]
        foreach ($split['parents'] as $plakyId => $p) {
            $kind = $groupKinds[$p['Group']] ?? 'ignore';
            if ($kind === 'ignore') { $report['skipped_ignored_group']++; continue; }

            $title = $p['Title'];
            if ($title === '') continue;

            // A whole-project deliverable IS the step — an animatic or a music
            // track isn't a shot with steps beneath it, it's one piece of work
            // for the show. So the item's own status becomes that step's progress.
            if ($kind === 'project_step') {
                $canonical = resolve_name_alias($pdo, $projectId, 'step', $title);
                $step = $projectStepsByName[mb_strtolower($canonical)] ?? null;
                if (!$step) {
                    $report['skipped_no_project_step']++;
                    $report['unmatched_project_steps'][$title] =
                        ($report['unmatched_project_steps'][$title] ?? 0) + 1;
                    continue;
                }
                $stepId = (int)$step['id'];
                $statusText = $p['Status'] ?? '';
                $statusId = (int)($statusMap[$statusText] ?? 0) ?: null;
                if ($statusText !== '' && $statusId === null) {
                    $report['unmapped_statuses'][$statusText] = ($report['unmapped_statuses'][$statusText] ?? 0) + 1;
                }
                $flags = status_flags($statusId ? ($statusRowsEarly[$statusId] ?? null) : null);
                $minutes = plaky_time_to_minutes($p['Time spent'] ?? '', $timeUnit);
                $assigneeRaw = ($p['Assignee'] ?? '') !== '' ? $p['Assignee'] : null;
                $crewIds = [];
                foreach (split_assignees($p['Assignee'] ?? '') as $person) {
                    $cid = (int)($crewMap[$person['name']] ?? 0);
                    if ($cid > 0) $crewIds[] = $cid;
                    else $report['unmatched_people'][$person['name']] =
                        ($report['unmatched_people'][$person['name']] ?? 0) + 1;
                }
                $existingId = $progressIds[$stepId . '|' . $projectItemKey] ?? null;

                if ($dryRun) {
                    if ($existingId) $report['progress_updated']++; else $report['progress_created']++;
                    continue;
                }
                if ($existingId) {
                    $pdo->prepare(
                        'UPDATE progress SET status = ?, status_id = ?, is_delivered = ?, is_approved = ?,
                            crew_id = COALESCE(?, crew_id), assignee_raw = COALESCE(?, assignee_raw),
                            time_spent_minutes = COALESCE(?, time_spent_minutes)
                          WHERE id = ?'
                    )->execute([$statusText, $statusId, $flags['is_delivered'], $flags['is_approved'],
                                $crewIds[0] ?? null, $assigneeRaw, $minutes, $existingId]);
                    $report['progress_updated']++;
                } else {
                    $pdo->prepare(
                        'INSERT INTO progress
                            (project_id, step_id, shot_id, asset_id, item_key, status, status_id,
                             is_delivered, is_approved, crew_id, assignee_raw, time_spent_minutes)
                         VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$projectId, $stepId, $projectItemKey, $statusText, $statusId,
                                $flags['is_delivered'], $flags['is_approved'],
                                $crewIds[0] ?? null, $assigneeRaw, $minutes]);
                    $progressIds[$stepId . '|' . $projectItemKey] = (int)$pdo->lastInsertId();
                    $report['progress_created']++;
                }
                continue;
            }

            if ($skipChildless && ($split['child_count'][$plakyId] ?? 0) === 0) {
                $report['skipped_childless']++;
                continue;
            }
            $frames = is_numeric($p['Total frames'] ?? '') ? (int)$p['Total frames'] : null;

            if ($kind === 'episode' || $kind === 'shots') {
                // 'shots' is the same thing without an episode: a single film or
                // commercial has one flat shot list, and stamping the group name
                // in as an episode would invent structure that isn't there.
                $episode = $kind === 'episode' ? $p['Group'] : '';
                $key = mb_strtolower($episode . '|' . $title);
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
                // Resolve "Environments" onto the "Environment" this project
                // already uses, rather than creating a second type an "s" apart.
                $assetType = plaky_canonical_asset_type($pdo, $projectId, (string)$p['Group']);
                if ($assetType !== trim((string)$p['Group'])) {
                    $report['types_normalised'][trim((string)$p['Group'])] = $assetType;
                }
                if (isset($assetIds[$key])) {
                    if (!$dryRun) $updAsset->execute([$assetType, $plakyId, $assetIds[$key]]);
                    $report['assets_updated']++;
                    $parentTarget[$plakyId] = ['kind' => 'asset', 'id' => $assetIds[$key]];
                } else {
                    if ($dryRun) {
                        $report['assets_created']++;
                        $parentTarget[$plakyId] = ['kind' => 'asset', 'id' => 0];
                    } else {
                        $insAsset->execute([$projectId, $title, $assetType, $plakyId]);
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

            // Keyed by group AND name: the same subitem name under Characters and
            // under Environments maps to two different steps.
            $parentRow = $split['parents'][$s['Item Parent Id']] ?? null;
            $mapKey = ($parentRow['Group'] ?? '') . "\x1F" . $s['Title'];
            $stepId = (int)($stepMap[$mapKey] ?? 0);
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
                // Logged BEFORE the update, while the old status is still readable.
                // A no-op when the status is unchanged, which is most rows on most
                // imports — the log is for what moved, not for what was re-sent.
                $wasId = $progressWas[$stepId . '|' . $itemKey] ?? null;
                if ((int)$wasId !== (int)$statusId) {
                    log_status_change($pdo, [
                        'project_id'      => $projectId,
                        'step_id'         => $stepId,
                        'step_name'       => $stepNames[$stepId] ?? null,
                        'item_key'        => $itemKey,
                        'item_name'       => $parent['name'] ?? null,
                        'shot_id'         => $parent['kind'] === 'shot'  ? $parent['id'] : null,
                        'asset_id'        => $parent['kind'] === 'asset' ? $parent['id'] : null,
                        'old_status_id'   => $wasId,
                        'old_status_name' => $wasId ? ($statusRows[$wasId]['name'] ?? null) : null,
                        'new_status_id'   => $statusId,
                        'new_status_name' => $statusId ? ($statusRows[$statusId]['name'] ?? null) : null,
                        'old_status'      => $wasId ? ($statusRows[$wasId] ?? null) : null,
                        'new_status'      => $statusId ? ($statusRows[$statusId] ?? null) : null,
                        'crew_id'         => $primaryCrew,
                        'crew_name'       => $assigneeRaw,
                        'source'          => 'import',
                    ]);
                }
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
