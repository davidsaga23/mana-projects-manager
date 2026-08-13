<?php
// Pipeline graph helpers.
//
// The workflow is a dependency graph, not a flat order: two steps sharing a
// dependency run in parallel, and a step depending on both waits for the later
// one. Everything here operates on that graph so no step name is ever special-
// cased (STFC hardcoded $overlapsWithPrevious = ['render']).

/** All steps for a project, in display order. */
function pipeline_steps(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM pipeline_steps WHERE project_id = ? ORDER BY step_order, id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** step_id => [depends_on_step_id, ...] for every step in the project. */
function step_dependencies_map(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT d.step_id, d.depends_on_step_id
           FROM step_dependencies d
           JOIN pipeline_steps s ON s.id = d.step_id
          WHERE s.project_id = ?'
    );
    $stmt->execute([$projectId]);
    $map = [];
    foreach ($stmt as $row) {
        $map[(int)$row['step_id']][] = (int)$row['depends_on_step_id'];
    }
    return $map;
}

/** The chains a project's workflow is derived over, in the order they're shown. */
const PIPELINE_CHAINS = ['shot', 'asset', 'project'];

/**
 * What a step applies to, in words. Defined once so the four categories cannot
 * drift apart between the wizard, the importer and the review screens.
 *
 * 'both' is the 2D colouring case: colour passes over characters AND over shots,
 * so the step genuinely belongs to both chains.
 * 'project' is the animatic / music / final mix case: one deliverable for the
 * whole show, attached to no shot and no asset.
 */
const APPLIES_TO_LABELS = [
    'shot'    => 'Shots',
    'asset'   => 'Assets',
    'both'    => 'Both shots and assets',
    'project' => 'The whole project',
];

/**
 * Asset types that can be picked from: five sensible starters plus every type
 * actually in use anywhere. Built from data so adding a kind of asset never
 * needs a code change — but the starters mean the list is useful on a brand new
 * project that has no assets yet, which is the normal case at setup time.
 */
function asset_types(PDO $pdo): array {
    $defaults = ['Character', 'Environment', 'Prop', 'Vehicle', 'FX'];
    $used = $pdo->query(
        "SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL AND asset_type <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $scoped = $pdo->query(
        "SELECT DISTINCT applies_to_asset_type FROM pipeline_steps
          WHERE applies_to_asset_type IS NOT NULL AND applies_to_asset_type <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $all = array_unique(array_merge($defaults, $used, $scoped));
    natcasesort($all);
    return array_values($all);
}

/**
 * Asset types that are almost certainly the same type spelled two ways.
 *
 * Free-text types were a deliberate choice — a fixed list in code is what forced
 * a schema change on STFC to add Props — and the decision log predicted the cost:
 * "a typo becomes a new type". What it didn't predict is how QUIETLY it lands.
 * A step scoped to "Environment" against six assets typed "Environments" counts
 * one, reports "1.0 Environment assets", and looks entirely healthy. Every quota
 * and every scheduled date derived from it is then wrong by a factor of seven,
 * with nothing on screen suggesting a problem.
 *
 * Matching ignores case, surrounding space, and a trailing "s", which covers the
 * three ways this actually happens. It deliberately does NOT merge anything by
 * itself: "Prop" and "Props" being one type is a safe guess, but guessing is how
 * the alias layer got built rather than assumed.
 *
 * Returns [normalised => ['Type' => count, ...]] for groups with more than one
 * spelling in use.
 */
function similar_asset_types(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(asset_type, '') AS t, COUNT(*) AS n
           FROM assets WHERE project_id = ? GROUP BY asset_type"
    );
    $stmt->execute([$projectId]);

    $groups = [];
    foreach ($stmt as $row) {
        if ($row['t'] === '') continue;
        $key = mb_strtolower(trim($row['t']));
        $key = preg_replace('/\s+/', ' ', $key);
        $key = preg_replace('/s$/', '', $key);      // Environment / Environments
        $groups[$key][$row['t']] = (int)$row['n'];
    }
    return array_filter($groups, function ($spellings) { return count($spellings) > 1; });
}

/**
 * The spelling a group should settle on.
 *
 * NOT simply the most-used one. The case this exists for had six assets typed
 * "Environments" and one typed "Environment", but the pipeline step was scoped
 * to "Environment" — so the popular spelling was the one that made the step
 * count nothing. What a step points at wins, because that is the spelling which
 * makes the plan work; then the built-in starters, so studios converge on the
 * same vocabulary across shows; then sheer weight of numbers.
 *
 * $anchors is the list that outranks the count: types pipeline steps reference,
 * plus the built-in starters.
 */
function preferred_asset_type(array $spellings, array $anchors = []): string {
    $anchorSet = [];
    foreach ($anchors as $a) {
        if ($a !== null && $a !== '') $anchorSet[mb_strtolower(trim($a))] = $a;
    }
    foreach ($spellings as $name => $n) {
        if (isset($anchorSet[mb_strtolower(trim((string)$name))])) return (string)$name;
    }

    $best = null; $bestN = -1;
    foreach ($spellings as $name => $n) {
        if ($n > $bestN || ($n === $bestN && mb_strlen((string)$name) < mb_strlen((string)$best))) {
            $best = $name; $bestN = $n;
        }
    }
    return (string)$best;
}

/** Types the project's own pipeline steps are scoped to — the strongest anchor. */
function step_scoped_asset_types(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT applies_to_asset_type FROM pipeline_steps
          WHERE project_id = ? AND applies_to_asset_type IS NOT NULL AND applies_to_asset_type <> ''"
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * A step's scope as a single form value: 'shot', 'asset', 'asset:Character',
 * 'both' or 'project'. One dropdown is far easier to reason about than two
 * controls where the second only sometimes applies.
 */
function applies_to_value(string $appliesTo, ?string $assetType): string {
    return ($appliesTo === 'asset' && $assetType !== null && $assetType !== '')
        ? 'asset:' . $assetType
        : $appliesTo;
}

/**
 * Split that form value back into [applies_to, asset_type].
 *
 * The type is '' rather than null when there isn't one, because it forms part of
 * the uniqueness rule for step names and MySQL unique indexes ignore NULLs —
 * with NULLs the constraint would silently permit the duplicates it exists to
 * prevent.
 */
function parse_applies_to(string $raw): array {
    $parts = explode(':', $raw, 2);
    $kind = $parts[0];
    if (!isset(APPLIES_TO_LABELS[$kind])) $kind = 'shot';
    // Only 'asset' narrows to a type. 'both' deliberately means all shots and
    // all assets — a step that is somehow both cross-type and type-specific
    // would be a puzzle, not a feature.
    $type = '';
    if ($kind === 'asset' && isset($parts[1]) && trim($parts[1]) !== '') {
        $type = trim($parts[1]);
    }
    return [$kind, $type];
}

/** <option> tags for an "applies to" dropdown, including per-asset-type choices. */
function applies_to_options(string $selectedValue, array $assetTypes = []): string {
    $html = '';
    foreach (APPLIES_TO_LABELS as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ($value === $selectedValue ? ' selected' : '')
               . '>' . e($label) . '</option>';
        if ($value === 'asset') {
            foreach ($assetTypes as $t) {
                $v = 'asset:' . $t;
                $html .= '<option value="' . e($v) . '"' . ($v === $selectedValue ? ' selected' : '')
                       . '>' . e('Assets — ' . $t . ' only') . '</option>';
            }
        }
    }
    return $html;
}

/** How a step's scope reads in a sentence. */
function applies_to_phrase(string $appliesTo, ?string $assetType = null): string {
    switch ($appliesTo) {
        case 'both':    return 'shots and assets';
        case 'project': return 'the whole project';
        case 'asset':   return ($assetType !== null && $assetType !== '')
                             ? $assetType . ' assets' : 'assets';
        default:        return 'shots';
    }
}

/**
 * The steps that make up one chain, in order.
 *
 * Used for grouping and for giving a newly added step a sensible starting
 * prerequisite. Dependencies themselves are NOT confined to a chain — they are
 * set explicitly and cross freely, because real pipelines do (crowd animation
 * waits on shot layout; a shot waits on its characters being rigged).
 */
function chain_steps(array $steps, string $kind): array {
    return array_values(array_filter($steps, function ($s) use ($kind) {
        if ($kind === 'project') return $s['applies_to'] === 'project';
        return $s['applies_to'] === $kind || $s['applies_to'] === 'both';
    }));
}


/** Depth-first cycle check. A cycle would make the scheduler loop forever. */
function pipeline_has_cycle(array $depsMap): bool {
    $state = [];   // 0/unset = unvisited, 1 = in progress, 2 = done
    $visit = function ($id) use (&$visit, &$state, $depsMap) {
        if (($state[$id] ?? 0) === 1) return true;    // back edge
        if (($state[$id] ?? 0) === 2) return false;
        $state[$id] = 1;
        foreach ($depsMap[$id] ?? [] as $dep) {
            if ($visit($dep)) return true;
        }
        $state[$id] = 2;
        return false;
    };
    foreach (array_keys($depsMap) as $id) {
        if ($visit($id)) return true;
    }
    return false;
}


/**
 * Save an explicit dependency graph: step_id => [ids it waits for].
 *
 * Replaces the old "parallel with the step above" model, which could only ever
 * express dependencies WITHIN one chain — so an asset step could never wait for
 * a shot step, however the boxes were ticked. Real pipelines do that: crowd
 * animation waits on layout, a shot can't be assembled until its characters are
 * rigged.
 *
 * Parallelism is still not something you set: two steps waiting on the same
 * things simply run side by side. That falls out of the graph, as it should.
 *
 * Throws without saving anything if the result would contain a cycle.
 */
function save_dependencies(PDO $pdo, int $projectId, array $wanted): void {
    $valid = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) $valid[(int)$s['id']] = true;

    $clean = [];
    foreach ($wanted as $stepId => $deps) {
        $stepId = (int)$stepId;
        if (!isset($valid[$stepId])) continue;
        $clean[$stepId] = [];
        foreach ((array)$deps as $d) {
            $d = (int)$d;
            // A step waiting for itself is a typo, not an instruction.
            if ($d === $stepId || !isset($valid[$d])) continue;
            $clean[$stepId][$d] = true;
        }
        $clean[$stepId] = array_keys($clean[$stepId]);
    }
    foreach (array_keys($valid) as $id) {
        if (!isset($clean[$id])) $clean[$id] = [];
    }

    if (pipeline_has_cycle($clean)) {
        throw new RuntimeException(
            'That would make steps wait for each other in a loop, so nothing was saved. ' .
            'Check the step you just changed.'
        );
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE d FROM step_dependencies d
               JOIN pipeline_steps s ON s.id = d.step_id
              WHERE s.project_id = ?'
        )->execute([$projectId]);
        $ins = $pdo->prepare(
            'INSERT INTO step_dependencies (step_id, depends_on_step_id) VALUES (?, ?)'
        );
        foreach ($clean as $stepId => $deps) {
            foreach ($deps as $d) $ins->execute([$stepId, $d]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Give a newly added step a sensible starting dependency: the last step of its
 * own chain. Without one the scheduler would happily start it on day one.
 *
 * Only ever touches a step that has no dependencies at all, so it can never
 * disturb a graph someone has deliberately set up.
 */
function link_new_step(PDO $pdo, int $projectId, int $stepId): void {
    $depsMap = step_dependencies_map($pdo, $projectId);
    if (!empty($depsMap[$stepId])) return;

    $steps = pipeline_steps($pdo, $projectId);
    $self = null;
    foreach ($steps as $s) if ((int)$s['id'] === $stepId) $self = $s;
    if (!$self) return;

    $kind = $self['applies_to'] === 'both' ? 'shot' : $self['applies_to'];
    $previous = null;
    foreach (chain_steps($steps, $kind) as $s) {
        if ((int)$s['id'] === $stepId) break;
        $previous = (int)$s['id'];
    }
    if ($previous !== null) {
        $pdo->prepare(
            'INSERT IGNORE INTO step_dependencies (step_id, depends_on_step_id) VALUES (?, ?)'
        )->execute([$stepId, $previous]);
    }
}

/**
 * Close the gap before a step is deleted: anything waiting on it inherits what
 * IT was waiting on. Deleting B from A -> B -> C leaves A -> C rather than
 * leaving C with no prerequisite and free to start immediately.
 *
 * Must be called BEFORE the delete, while the graph still describes reality.
 */
function heal_dependencies_before_delete(PDO $pdo, int $projectId, int $stepId): void {
    $depsMap = step_dependencies_map($pdo, $projectId);
    $inherited = $depsMap[$stepId] ?? [];

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO step_dependencies (step_id, depends_on_step_id) VALUES (?, ?)'
    );
    foreach ($depsMap as $dependentId => $deps) {
        if ($dependentId === $stepId) continue;
        if (!in_array($stepId, $deps, true)) continue;
        foreach ($inherited as $d) {
            if ($d !== $dependentId) $ins->execute([$dependentId, $d]);
        }
    }
    // The step's own rows go with it via ON DELETE CASCADE.
}

/** Copy a template's steps and dependencies into a project. */
function apply_pipeline_template(PDO $pdo, int $projectId, int $templateId): int {
    $stmt = $pdo->prepare(
        // Shot steps first: it reads as the main production line, with asset
        // work listed after it rather than alphabetically ahead of it.
        "SELECT * FROM pipeline_template_steps WHERE template_id = ?
          ORDER BY FIELD(applies_to, 'shot', 'both', 'asset'), step_order, id"
    );
    $stmt->execute([$templateId]);
    $tplSteps = $stmt->fetchAll();
    if (!$tplSteps) return 0;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO pipeline_steps
                (project_id, name, step_order, tracks_seconds, applies_to, applies_to_asset_type,
                 days_per_unit, coverage_pct, color)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $idMap = [];       // template step id => new step id
        $order = 0;
        foreach ($tplSteps as $t) {
            $ins->execute([
                $projectId, $t['name'], ++$order, $t['tracks_seconds'],
                $t['applies_to'], $t['applies_to_asset_type'] ?? null,
                $t['days_per_unit'] ?? 1.0, $t['coverage_pct'] ?? 100, $t['color'],
            ]);
            $idMap[(int)$t['id']] = (int)$pdo->lastInsertId();
            // Remember template names too, so a step that arrived via a template
            // is suggested on the next project just like a hand-typed one.
            step_library_remember($pdo, $t['name'], $t['applies_to'], (int)$t['tracks_seconds'], $t['color']);
        }

        $ids = implode(',', array_map('intval', array_keys($idMap)));
        $deps = $pdo->query(
            "SELECT step_id, depends_on_step_id FROM pipeline_template_deps
              WHERE step_id IN ($ids)"
        )->fetchAll();
        $insDep = $pdo->prepare(
            'INSERT INTO step_dependencies (step_id, depends_on_step_id) VALUES (?, ?)'
        );
        foreach ($deps as $d) {
            $from = $idMap[(int)$d['step_id']] ?? null;
            $to   = $idMap[(int)$d['depends_on_step_id']] ?? null;
            if ($from && $to) $insDep->execute([$from, $to]);
        }
        $pdo->commit();
        return count($tplSteps);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Remember a step name for future projects.
 *
 * Called when a step is added by hand, so the next project suggests it with the
 * settings it had last time. Purely a suggestion list — it never constrains what
 * a project's pipeline can contain.
 */
function step_library_remember(PDO $pdo, string $name, string $appliesTo, int $tracksSeconds, ?string $color = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO step_library (name, applies_to, tracks_seconds, color, times_used, last_used)
         VALUES (?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
            times_used = times_used + 1,
            last_used = NOW(),
            applies_to = ?,
            tracks_seconds = ?'
    );
    $stmt->execute([$name, $appliesTo, $tracksSeconds, $color, $appliesTo, $tracksSeconds]);
}

/** Remembered step names, most-used first. */
function step_library(PDO $pdo): array {
    return $pdo->query(
        'SELECT * FROM step_library ORDER BY times_used DESC, last_used DESC, name'
    )->fetchAll();
}

/**
 * Save a project's pipeline as a reusable template — the reverse of
 * apply_pipeline_template().
 *
 * Copies the steps AND the dependency graph, so parallelism survives: a template
 * saved from a pipeline where Lighting and Render run side by side recreates
 * exactly that on the next project.
 *
 * Deliberately does not copy dates, headcount or deadlines — those belong to one
 * project and would be actively misleading carried into another.
 *
 * Returns how many steps were saved.
 */
function create_template_from_project(PDO $pdo, int $projectId, string $name, string $projectType): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Give the template a name.');
    if (!in_array($projectType, ['2D', '3D', 'Mixed', 'Other'], true)) $projectType = 'Other';

    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) throw new RuntimeException('There are no pipeline steps to save yet.');

    $dup = $pdo->prepare('SELECT id FROM pipeline_templates WHERE name = ?');
    $dup->execute([$name]);
    if ($dup->fetch()) {
        throw new RuntimeException('A template called "' . $name . '" already exists. ' .
                                   'Pick another name — templates are never overwritten silently.');
    }

    $depsMap = step_dependencies_map($pdo, $projectId);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO pipeline_templates (name, project_type) VALUES (?, ?)')
            ->execute([$name, $projectType]);
        $templateId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO pipeline_template_steps
                (template_id, name, step_order, tracks_seconds, applies_to, applies_to_asset_type,
                 days_per_unit, coverage_pct, color)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $idMap = [];   // project step id => template step id
        foreach ($steps as $s) {
            $ins->execute([
                $templateId, $s['name'], (int)$s['step_order'], (int)$s['tracks_seconds'],
                $s['applies_to'], $s['applies_to_asset_type'] ?? null,
                $s['days_per_unit'] ?? 1.0, $s['coverage_pct'] ?? 100, $s['color'],
            ]);
            $idMap[(int)$s['id']] = (int)$pdo->lastInsertId();
        }

        $insDep = $pdo->prepare(
            'INSERT INTO pipeline_template_deps (step_id, depends_on_step_id) VALUES (?, ?)'
        );
        foreach ($depsMap as $stepId => $deps) {
            foreach ($deps as $d) {
                if (isset($idMap[$stepId], $idMap[$d])) {
                    $insDep->execute([$idMap[$stepId], $idMap[$d]]);
                }
            }
        }

        $pdo->commit();
        return count($steps);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Renumber step_order 1..n in current display order (keeps reordering simple). */
function renumber_steps(PDO $pdo, int $projectId): void {
    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare('UPDATE pipeline_steps SET step_order = ? WHERE id = ?');
    $n = 0;
    foreach ($steps as $s) $upd->execute([++$n, $s['id']]);
}


/**
 * How far into the pipeline each step sits: 0 for anything that can start
 * immediately, otherwise one past the latest thing it waits for.
 *
 * This is what makes a picture possible — steps at the same level genuinely run
 * at the same time, so a level is a column.
 */
function workflow_levels(array $steps, array $depsMap): array {
    $byId = [];
    foreach ($steps as $s) $byId[(int)$s['id']] = $s;

    $level = [];
    $resolve = function ($id) use (&$resolve, &$level, $depsMap, $byId) {
        if (isset($level[$id])) return $level[$id];
        $level[$id] = 0;                       // also stops a cycle looping forever
        $max = -1;
        foreach ($depsMap[$id] ?? [] as $d) {
            if (isset($byId[$d])) $max = max($max, $resolve($d));
        }
        return $level[$id] = $max + 1;
    };
    foreach (array_keys($byId) as $id) $resolve($id);
    return $level;
}

/**
 * Draw the workflow as a left-to-right diagram: one column per level, curves
 * from each prerequisite to the steps that wait for it.
 *
 * Plain SVG built server-side — no library, no script, and it prints. A list
 * saying "waits for X" is accurate but you can't see the shape of a pipeline in
 * it, which is the whole reason to look.
 */
function workflow_diagram_svg(array $steps, array $depsMap): string {
    if (!$steps) return '';

    $levels = workflow_levels($steps, $depsMap);
    $columns = [];
    foreach ($steps as $s) $columns[$levels[(int)$s['id']] ?? 0][] = $s;
    ksort($columns);

    $boxW = 158; $boxH = 40; $colGap = 54; $rowGap = 14; $padX = 8; $padY = 10;
    $pos = [];
    $maxRows = 0;
    foreach ($columns as $col => $rows) {
        $maxRows = max($maxRows, count($rows));
        foreach (array_values($rows) as $row => $s) {
            $pos[(int)$s['id']] = [
                'x' => $padX + $col * ($boxW + $colGap),
                'y' => $padY + $row * ($boxH + $rowGap),
                'step' => $s,
            ];
        }
    }
    $width  = $padX * 2 + count($columns) * $boxW + (count($columns) - 1) * $colGap;
    $height = $padY * 2 + $maxRows * $boxH + max(0, $maxRows - 1) * $rowGap;

    $svg  = '<svg class="wf-diagram" viewBox="0 0 ' . $width . ' ' . $height . '" '
          . 'width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg" '
          . 'role="img" aria-label="Pipeline workflow diagram">';

    // Edges first so boxes sit on top of them.
    foreach ($depsMap as $stepId => $deps) {
        if (!isset($pos[$stepId])) continue;
        $to = $pos[$stepId];
        foreach ($deps as $d) {
            if (!isset($pos[$d])) continue;
            $from = $pos[$d];
            $x1 = $from['x'] + $boxW; $y1 = $from['y'] + $boxH / 2;
            $x2 = $to['x'];           $y2 = $to['y'] + $boxH / 2;
            $mid = max(18, ($x2 - $x1) / 2);
            $svg .= '<path d="M' . $x1 . ',' . $y1
                  . ' C' . ($x1 + $mid) . ',' . $y1 . ' ' . ($x2 - $mid) . ',' . $y2
                  . ' ' . $x2 . ',' . $y2 . '" class="wf-edge" />';
        }
    }

    foreach ($pos as $id => $p) {
        $s = $p['step'];
        $color = $s['color'] ?: '#8a97a3';
        $name = $s['name'];
        // SVG has no text wrapping; trim rather than let it run over the box.
        $label = mb_strlen($name) > 20 ? mb_substr($name, 0, 19) . '…' : $name;
        $scope = applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null);

        $svg .= '<g class="wf-node">'
              . '<title>' . e($name . ' · ' . $scope) . '</title>'
              . '<rect x="' . $p['x'] . '" y="' . $p['y'] . '" width="' . $boxW . '" height="' . $boxH
              . '" rx="5" class="wf-box" />'
              . '<rect x="' . $p['x'] . '" y="' . $p['y'] . '" width="4" height="' . $boxH
              . '" rx="2" fill="' . e($color) . '" />'
              . '<text x="' . ($p['x'] + 13) . '" y="' . ($p['y'] + 17) . '" class="wf-label">'
              . e($label) . '</text>'
              . '<text x="' . ($p['x'] + 13) . '" y="' . ($p['y'] + 31) . '" class="wf-sub">'
              . e($scope) . '</text>'
              . '</g>';
    }

    return $svg . '</svg>';
}

/** Human-readable "waits for X and Y" for the review screen. */
function describe_dependencies(array $step, array $depsMap, array $stepsById): string {
    $deps = $depsMap[(int)$step['id']] ?? [];
    if (!$deps) return 'starts the pipeline';
    $names = [];
    foreach ($deps as $d) {
        if (isset($stepsById[$d])) $names[] = $stepsById[$d]['name'];
    }
    sort($names);
    return 'waits for ' . implode(' and ', $names);
}
