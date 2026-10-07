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
 * Production phases to suggest: the four a production office starts with, plus
 * every phase already in use on any project.
 *
 * Global on purpose, like step_library — a studio's phases are the studio's, and
 * seeing what other shows called things is how the vocabulary converges instead
 * of drifting. Suggestions only; the field is free text underneath.
 */
function department_options(PDO $pdo): array {
    $defaults = ['Above the line', 'Pre-production', 'Production', 'Post-production'];
    try {
        $used = $pdo->query(
            "SELECT DISTINCT department FROM pipeline_steps WHERE department <> ''"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $used = [];                       // migration 015 pending
    }
    $all = array_values(array_unique(array_merge($defaults, $used)));
    // Not sorted alphabetically: these are a sequence, and "Above the line,
    // Post-production, Pre-production, Production" is nobody's pipeline.
    return $all;
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
                       . '>' . e('Assets, ' . $t . ' only') . '</option>';
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
        // The template's own department, falling back to whatever the library
        // remembers for that step name — so a template written before departments
        // existed still lands them on a new project.
        $hasDept = column_exists($pdo, 'pipeline_steps', 'department');
        $known   = $hasDept ? step_departments_known($pdo) : [];

        $ins = $pdo->prepare(
            'INSERT INTO pipeline_steps
                (project_id, name, step_order, tracks_seconds, applies_to, applies_to_asset_type,
                 days_per_unit, coverage_pct, color'
            . ($hasDept ? ', department' : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hasDept ? ', ?' : '') . ')'
        );
        $idMap = [];       // template step id => new step id
        $order = 0;
        foreach ($tplSteps as $t) {
            $dept = trim((string)($t['department'] ?? '')) ?: ($known[$t['name']] ?? '');
            $args = [
                $projectId, $t['name'], ++$order, $t['tracks_seconds'],
                $t['applies_to'], $t['applies_to_asset_type'] ?? null,
                $t['days_per_unit'] ?? 1.0, $t['coverage_pct'] ?? 100, $t['color'],
            ];
            if ($hasDept) $args[] = $dept;
            $ins->execute($args);
            $idMap[(int)$t['id']] = (int)$pdo->lastInsertId();
            // Remember template names too, so a step that arrived via a template
            // is suggested on the next project just like a hand-typed one.
            step_library_remember($pdo, $t['name'], $t['applies_to'], (int)$t['tracks_seconds'],
                                  $t['color'], $dept);
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
function step_library_remember(PDO $pdo, string $name, string $appliesTo, int $tracksSeconds,
                               ?string $color = null, string $department = ''): void {
    if (!column_exists($pdo, 'step_library', 'department')) {
        // Migration 016 pending — remember everything else rather than nothing.
        $stmt = $pdo->prepare(
            'INSERT INTO step_library (name, applies_to, tracks_seconds, color, times_used, last_used)
             VALUES (?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                times_used = times_used + 1, last_used = NOW(),
                applies_to = ?, tracks_seconds = ?'
        );
        $stmt->execute([$name, $appliesTo, $tracksSeconds, $color, $appliesTo, $tracksSeconds]);
        return;
    }

    // department uses COALESCE(NULLIF(...)) rather than plain assignment: a step
    // saved with the field left blank must not ERASE the department the library
    // already knows. Forgetting on a blank is how a remembered answer quietly
    // stops being remembered.
    $stmt = $pdo->prepare(
        'INSERT INTO step_library (name, department, applies_to, tracks_seconds, color, times_used, last_used)
         VALUES (?, ?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
            times_used = times_used + 1,
            last_used = NOW(),
            applies_to = ?,
            tracks_seconds = ?,
            department = COALESCE(NULLIF(VALUES(department), \'\'), department)'
    );
    $stmt->execute([$name, $department, $appliesTo, $tracksSeconds, $color,
                    $appliesTo, $tracksSeconds]);
}

/** name => department, for prefilling. Empty when migration 016 is pending. */
function step_departments_known(PDO $pdo): array {
    if (!column_exists($pdo, 'step_library', 'department')) return [];
    return $pdo->query(
        "SELECT name, department FROM step_library WHERE department <> ''"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
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
/**
 * Move steps between phases — "Comp belongs to Post-production".
 *
 * `pipeline_steps.department` is the phase, and until now it could only be set
 * inside the setup wizard. That is the wrong place to be when the question
 * arises: it arises reading a budget, where the phase subtotals are, and walking
 * back into a seven-step wizard to fix one dropdown is enough friction that the
 * grouping just stays wrong. (David, 2026-08-17.)
 *
 * ⚠ NOT financial, despite being called from the Finance screen. `department`
 * is production data — how a crew list, a call sheet and a Gantt are all
 * organised — and the 2026-08-15 entry is explicit that routing it through the
 * money door "would only make it look like it was". It is written on the
 * ORDINARY production connection.
 *
 * Each change also calls step_library_remember(), exactly as the wizard does, so
 * the answer is given once and every later project starts with it filled in.
 * Two writers that remembered differently would make the library depend on which
 * screen somebody happened to use.
 *
 * @param array $wanted  step id => department
 * @return array ['changed' => int, 'moved' => string[]]
 */
function save_step_departments(PDO $pdo, int $projectId, array $wanted): array {
    $out = ['changed' => 0, 'moved' => []];
    if (!column_exists($pdo, 'pipeline_steps', 'department')) {
        throw new RuntimeException(
            'Phases need migration 015, which has not been applied yet.'
        );
    }

    $steps = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) $steps[(int)$s['id']] = $s;

    $upd = $pdo->prepare('UPDATE pipeline_steps SET department = ? WHERE id = ? AND project_id = ?');

    foreach ($wanted as $id => $dept) {
        $id = (int)$id;
        if (!isset($steps[$id])) continue;           // not this project's step
        $dept = trim((string)$dept);
        $before = trim((string)($steps[$id]['department'] ?? ''));
        if ($dept === $before) continue;

        $upd->execute([$dept, $id, $projectId]);
        $out['changed']++;
        $out['moved'][] = sprintf('%s: %s → %s',
            $steps[$id]['name'],
            $before !== '' ? $before : 'no phase',
            $dept !== '' ? $dept : 'no phase');

        step_library_remember(
            $pdo, $steps[$id]['name'], $steps[$id]['applies_to'],
            (int)($steps[$id]['tracks_seconds'] ?? 0), $steps[$id]['color'] ?? null, $dept
        );
    }
    return $out;
}

/**
 * Handle a posted "save as template", and return the sentence to flash.
 *
 * ⚠ SHARED BY TWO WIZARD STEPS, and that is the whole reason it exists here
 * rather than inside one of them. The Pipeline screen has offered this since
 * 2026-08-14; the Workflow screen wanted it too (David, 2026-08-17) because
 * that is where parallelism is actually set, and a template records the
 * dependency graph. Each wizard step file is loaded on its own — step 3 never
 * sees step 2's code — so the alternative was a second copy of the handler and
 * a second copy of the form.
 *
 * Two copies would drift, and the thing they would drift on is the one that
 * matters: `template_overwrite`. The 2026-08-14 decision is that replacing a
 * template is allowed but must be DELIBERATE, and a copy that quietly lost the
 * tick would start overwriting the house pipeline for people who thought they
 * were creating a new one.
 *
 * The caller decides where to redirect afterwards, because that differs per step.
 */
function template_save_from_post(PDO $pdo, int $projectId, array $project): string {
    $name = trim($_POST['template_name'] ?? '');
    $type = $_POST['template_type'] ?? $project['type'];
    $overwrite = !empty($_POST['template_overwrite']);

    $n = create_template_from_project($pdo, $projectId, $name, $type, $overwrite);

    return ($overwrite ? "Replaced the template \"$name\"" : "Saved this pipeline as the template \"$name\"")
         . ", $n step" . ($n === 1 ? '' : 's')
         . ', including which of them run in parallel'
         . (column_exists($pdo, 'pipeline_template_steps', 'department')
            ? ' and which department each belongs to' : '')
         . '. It will be offered on new projects.';
}

/**
 * The "save as template" form. Rendered identically on Pipeline and Workflow.
 *
 * $note lets each screen say what is worth saying THERE — the pipeline screen
 * talks about steps, the workflow screen about the graph — without the controls
 * themselves being duplicated.
 *
 * ⚠ $inline EXISTS BECAUSE OF A REAL FOOTGUN, not for tidiness.
 *
 * On the Pipeline screen this is its own form, and that is safe: the step edits
 * live in a different form, and wizard_pipeline_apply_edits() returns 0 when its
 * fields are absent, so saving a template simply does not touch them.
 *
 * On the Workflow screen the equivalent would be catastrophic. The dependency
 * ticks are checkboxes, and unchecked boxes are not submitted at all — so a
 * SEPARATE form posting `save_template` carries no `deps` key whatsoever, which
 * is indistinguishable from "the user unticked everything". Saving that would
 * DELETE THE ENTIRE DEPENDENCY GRAPH, silently, while appearing to save a
 * template.
 *
 * So on that screen the controls sit INSIDE the dependency form and the action
 * travels in the submit button's value. That is the pattern the wizard already
 * uses for its row buttons, for exactly this reason: "a single form saves
 * pending edits and performs the action together — HTML forms cannot nest, and
 * losing typed edits to a row button would be its own silent failure"
 * (DECISIONS, 2026-08-11).
 *
 * The name field drops `required` when inline, because a required field inside
 * the main form would block "Save and continue" for anyone not saving a
 * template. create_template_from_project() refuses an empty name anyway, and
 * wizard.php turns that into a flash message.
 */
function template_save_form(PDO $pdo, array $project, string $note = '', bool $inline = false): void {
    $tag = $inline ? 'div' : 'form';
    ?>
    <<?= $tag ?><?= $inline ? '' : ' method="post"' ?> class="add-row">
      <?php if (!$inline): ?>
        <input type="hidden" name="action" value="save_template">
      <?php endif; ?>
      <strong>Save this pipeline as a template</strong>
      <input name="template_name" list="template-names"
             placeholder="e.g. 3D, Mana house pipeline" <?= $inline ? '' : 'required' ?> size="30">
      <?php /* Existing names offered so replacing one is a pick rather than a
               retype — a near-miss spelling would create a second template
               instead of updating the one meant. */ ?>
      <datalist id="template-names">
        <?php foreach ($pdo->query('SELECT name FROM pipeline_templates ORDER BY name') as $t): ?>
          <option value="<?= e($t['name']) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <?php // Escaped now: since 2026-09-16 a type is a name somebody typed, not
            // one of four literals. ?>
      <select name="template_type">
        <?php foreach (project_type_options($pdo, (string)$project['type']) as $t): ?>
          <option value="<?= e($t) ?>" <?= $project['type'] === $t ? 'selected' : '' ?>>for <?= e($t) ?> projects</option>
        <?php endforeach; ?>
      </select>
      <label class="inline" title="Only needed when the name already exists">
        <input type="checkbox" name="template_overwrite" value="1"> replace it if the name exists
      </label>
      <button type="submit" <?= $inline ? 'name="action" value="save_template"' : '' ?>
              class="secondary">Save as template</button>
      <small><?= $note !== '' ? e($note) : 'Saves the steps, their settings and which of them run in parallel, but not
             dates or headcount, which belong to this project alone. New projects will be
             offered it alongside the built-in templates.' ?></small>
    </<?= $tag ?>>
    <?php
}

function create_template_from_project(PDO $pdo, int $projectId, string $name, string $projectType,
                                      bool $overwrite = false): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Give the template a name.');
    // One of the studio's types, or this project's own, which the picker always
    // offers. Refused rather than rewritten to Other: a template filed under a
    // type nobody picked is offered first to the wrong projects.
    $own = $pdo->prepare('SELECT type FROM projects WHERE id = ?');
    $own->execute([$projectId]);
    if (!in_array($projectType, project_type_options($pdo, (string)$own->fetchColumn()), true)) {
        throw new RuntimeException(sprintf('There is no project type called "%s" any more, so the '
                                         . 'template was not saved. Pick one from the list.', $projectType));
    }

    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) throw new RuntimeException('There are no pipeline steps to save yet.');

    $dup = $pdo->prepare('SELECT id FROM pipeline_templates WHERE name = ?');
    $dup->execute([$name]);
    $existingId = $dup->fetchColumn();

    if ($existingId !== false && !$overwrite) {
        throw new RuntimeException('A template called "' . $name . '" already exists. ' .
                                   'Tick "replace it" to overwrite, or pick another name, ' .
                                   'a template is never replaced by accident.');
    }

    $depsMap = step_dependencies_map($pdo, $projectId);

    $pdo->beginTransaction();
    try {
        if ($existingId !== false) {
            // Replace the CONTENTS, keeping the template row and therefore its id.
            // Deleting and recreating would hand out a new id, and anything that
            // ever references a template by id — a "made from" record, a report —
            // would silently point at nothing.
            $pdo->prepare('DELETE FROM pipeline_template_steps WHERE template_id = ?')
                ->execute([(int)$existingId]);
            $pdo->prepare('UPDATE pipeline_templates SET project_type = ? WHERE id = ?')
                ->execute([$projectType, (int)$existingId]);
            $templateId = (int)$existingId;
        } else {
            $pdo->prepare('INSERT INTO pipeline_templates (name, project_type) VALUES (?, ?)')
                ->execute([$name, $projectType]);
            $templateId = (int)$pdo->lastInsertId();
        }

        // Departments travel into the template too — a template that dropped
        // them would hand the blanks straight back on the next project.
        $hasDept = column_exists($pdo, 'pipeline_template_steps', 'department');
        $ins = $pdo->prepare(
            'INSERT INTO pipeline_template_steps
                (template_id, name, step_order, tracks_seconds, applies_to, applies_to_asset_type,
                 days_per_unit, coverage_pct, color'
            . ($hasDept ? ', department' : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hasDept ? ', ?' : '') . ')'
        );
        $idMap = [];   // project step id => template step id
        foreach ($steps as $s) {
            $args = [
                $templateId, $s['name'], (int)$s['step_order'], (int)$s['tracks_seconds'],
                $s['applies_to'], $s['applies_to_asset_type'] ?? null,
                $s['days_per_unit'] ?? 1.0, $s['coverage_pct'] ?? 100, $s['color'],
            ];
            if ($hasDept) $args[] = (string)($s['department'] ?? '');
            $ins->execute($args);
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
 * A step's name, with its scope when the name alone does not say which step.
 *
 * Step names only have to be unique within their scope, so a real pipeline
 * carries two Concepts and two Colors, and "Concept" in a message does not say
 * which. A repeated name gets its scope in the words the workflow diagram prints
 * under it: "Concept (Environment assets)". A unique name is left alone.
 *
 * ONE implementation for every message that names a step. It was written inline
 * in the workflow save first, then needed again for the studio-ideal message on
 * Dates & headcount; the JS in assets/workflow-edit.js applies the same rule.
 */
function step_display_name(array $step, array $allSteps): string {
    $name = (string)($step['name'] ?? '');
    $key = mb_strtolower(trim($name));
    $same = 0;
    foreach ($allSteps as $o) {
        if (mb_strtolower(trim((string)($o['name'] ?? ''))) === $key) $same++;
    }
    return $same > 1
        ? $name . ' (' . applies_to_phrase($step['applies_to'] ?? 'shot', $step['applies_to_asset_type'] ?? null) . ')'
        : $name;
}

/**
 * Whether a step's deadline may be saved, and what to say about it.
 *
 * ⚠ ONE IMPLEMENTATION OF THE RULES, for the two places a deadline is edited:
 * the Dates & headcount screen, which sets every step at once, and the step
 * page, which sets one (David, 2026-09-14: "we need to have a deadline menu like
 * the one in STFC"). Two screens editing one column with their own copies of
 * the rules would quietly accept a date on one that the other refuses.
 *
 *   before the step's own start  REFUSED. The window would be negative, and
 *                                 there is no quota that describes that.
 *   after the project delivery    ALLOWED, with a warning. It can be a real
 *                                 decision - a step allowed to run past the
 *                                 cut - but it should never happen unnoticed.
 *
 * @return array{refuse: ?string, warn: ?string}
 */
function step_deadline_check(string $stepName, ?string $start, ?string $deadline,
                             ?string $delivery): array {
    if ($start && $deadline && $deadline < $start) {
        return ['refuse' => $stepName . ': the deadline is before the start date, so it was left unchanged.',
                'warn'   => null];
    }
    if ($deadline && $delivery && $deadline > $delivery) {
        return ['refuse' => null,
                'warn'   => $stepName . ': the deadline is after the project delivery date.'];
    }
    return ['refuse' => null, 'warn' => null];
}

/**
 * Set, or clear, one step's deadline.
 *
 * An EMPTY value clears it, which is not the same as "no deadline": a step with
 * none of its own is measured against the project's delivery date, the fallback
 * dashboard_steps() applies. That is what "use the project's delivery date"
 * means on the step page, and saying so is the difference between a clear
 * button and a delete button.
 *
 * Returns [message, warning-or-null]. Throws, having written nothing, when the
 * date is refused or is not a date.
 */
function set_step_deadline(PDO $pdo, array $project, int $stepId, string $raw): array {
    // The deadline alone, for the "use the project's delivery date" button. NULL
    // for the start means LEAVE IT ALONE, which is not the same as '' (clear it).
    return set_step_dates($pdo, $project, $stepId, null, $raw);
}

/**
 * Set a step's start date, its deadline, or both, from one save.
 *
 * David, 2026-10-07: "let's also add the start date aside of the deadline so we
 * can know and adjust it from this view too if needed."
 *
 * Each date takes three kinds of value, and the difference matters:
 *   null  leave it exactly as it is — the field was not on the form
 *   ''    clear it
 *   date  set it
 *
 * ⚠ THE PAIR IS VALIDATED TOGETHER, AGAINST THE VALUES THAT WILL BE STORED,
 * not against what is in the row now. Moving a start past an existing deadline
 * and moving a deadline before an existing start are the same mistake, and
 * checking each against the old copy of the other would wave one of them
 * through. Nothing is written unless both survive the check.
 *
 * Returns [message, warning-or-null]. Throws, having written nothing, when a
 * date is refused or is not a date.
 */
function set_step_dates(PDO $pdo, array $project, int $stepId,
                        ?string $startRaw, ?string $deadlineRaw): array {
    $stmt = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $stmt->execute([$stepId, (int)$project['id']]);
    $step = $stmt->fetch();
    if (!$step) throw new RuntimeException('That step is not part of this project.');

    // Strict: a date input posts Y-m-d, and anything else is refused rather than
    // handed to strtotime(), which would read "next friday" as a date.
    $parse = function (?string $raw, string $what) use ($step) {
        if ($raw === null) return ['keep', null];
        $raw = trim($raw);
        if ($raw === '') return ['set', null];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new RuntimeException('"' . $raw . '" is not a date, so the '
                                     . $what . ' was left unchanged.');
        }
        return ['set', $raw];
    };

    [$startMode, $start]       = $parse($startRaw, 'start date');
    [$deadlineMode, $deadline] = $parse($deadlineRaw, 'deadline');

    // What the row will hold once this save lands — the only pair worth checking.
    $finalStart    = $startMode    === 'keep' ? ($step['start_date'] ?: null) : $start;
    $finalDeadline = $deadlineMode === 'keep' ? ($step['deadline'] ?: null)   : $deadline;

    $check = step_deadline_check((string)$step['name'], $finalStart, $finalDeadline,
                                 $project['delivery_date'] ?: null);
    if ($check['refuse']) throw new RuntimeException($check['refuse']);

    $sets = [];
    $args = [];
    if ($startMode === 'set')    { $sets[] = 'start_date = ?'; $args[] = $finalStart; }
    if ($deadlineMode === 'set') { $sets[] = 'deadline = ?';   $args[] = $finalDeadline; }
    if ($sets) {
        $args[] = $stepId;
        $args[] = (int)$project['id'];
        $pdo->prepare('UPDATE pipeline_steps SET ' . implode(', ', $sets)
                    . ' WHERE id = ? AND project_id = ?')->execute($args);
    }

    // Said date by date, and only for what actually moved. "Saved" on a form with
    // two fields leaves you wondering which of them it meant.
    $parts = [];
    if ($startMode === 'set' && ($step['start_date'] ?: null) !== $finalStart) {
        $parts[] = $finalStart === null
            ? 'has no start date now'
            : 'starts ' . $finalStart . ($step['start_date'] ? ', moved from ' . $step['start_date'] : '');
    }
    if ($deadlineMode === 'set' && ($step['deadline'] ?: null) !== $finalDeadline) {
        $parts[] = $finalDeadline === null
            ? ($project['delivery_date']
                ? 'now works to the project delivery date, ' . $project['delivery_date']
                : 'has no deadline now, and the project has no delivery date either')
            : 'is due ' . $finalDeadline . ($step['deadline'] ? ', moved from ' . $step['deadline'] : '');
    }
    $said = $parts
        ? $step['name'] . ' ' . implode(' and ', $parts) . '.'
        : $step['name'] . ': nothing changed, those are the dates it already had.';

    return [$said, $check['warn']];
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
/**
 * $standalone emits a FILE rather than a fragment: an XML prolog, the four
 * style rules the diagram needs carried inside it, and a white ground.
 *
 * The on-page version inherits .wf-edge / .wf-box / .wf-label / .wf-sub from
 * assets/style.css. A downloaded copy has no stylesheet to inherit from, so
 * without this it opens as black text on transparency with no boxes and no
 * connectors — technically the right shapes, unreadable as a picture.
 */
function workflow_diagram_svg(array $steps, array $depsMap,
                              bool $standalone = false, string $caption = ''): string {
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

    // The body is accumulated on its own so a standalone file can shift it down
    // to make room for a caption without every coordinate above being redone.
    $svg = '';

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
            // Which link this is, on the page only. The diagram can be edited by
            // dragging (assets/workflow-edit.js), and a line has to know what it
            // joins to be removed. Left off the downloaded file, which is a
            // picture and has no use for either.
            $ids = $standalone ? '' : ' data-from="' . (int)$d . '" data-to="' . (int)$stepId . '"';
            $svg .= '<path d="M' . $x1 . ',' . $y1
                  . ' C' . ($x1 + $mid) . ',' . $y1 . ' ' . ($x2 - $mid) . ',' . $y2
                  . ' ' . $x2 . ',' . $y2 . '" class="wf-edge"' . $ids . ' />';
        }
    }

    foreach ($pos as $id => $p) {
        $s = $p['step'];
        $color = $s['color'] ?: '#8a97a3';
        $name = $s['name'];
        // SVG has no text wrapping; trim rather than let it run over the box.
        $label = mb_strlen($name) > 20 ? mb_substr($name, 0, 19) . '…' : $name;
        $scope = applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null);

        $svg .= '<g class="wf-node"'
              . ($standalone ? '' : ' data-step="' . (int)$id . '" data-name="' . e($name)
                                  . '" data-scope="' . e($scope) . '"')
              . '>'
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

    if (!$standalone) {
        return '<svg class="wf-diagram" viewBox="0 0 ' . $width . ' ' . $height . '" '
             . 'width="' . $width . '" height="' . $height . '" '
             . 'xmlns="http://www.w3.org/2000/svg" role="img" '
             . 'aria-label="Pipeline workflow diagram">' . $svg . '</svg>';
    }

    // ---- the downloadable file ------------------------------------------
    // Copied from assets/style.css rather than read from it: a file that has
    // left the building cannot follow a stylesheet, and four rules inlined here
    // are cheaper than a build step. If those rules change, change these.
    $css = '.wf-edge{fill:none;stroke:#3a3a3a;stroke-width:1.6}'
         . '.wf-box{fill:#1f1f1f;stroke:#2b2b2b;stroke-width:1}'
         . '.wf-label{font:600 12px -apple-system,"Segoe UI",Roboto,sans-serif;fill:#ededed}'
         . '.wf-sub{font:400 10.5px -apple-system,"Segoe UI",Roboto,sans-serif;fill:rgba(255,255,255,.6)}'
         . '.wf-cap{font:600 13px -apple-system,"Segoe UI",Roboto,sans-serif;fill:rgba(255,255,255,.78)}';

    $head = $caption !== '' ? 36 : 0;
    $full = $height + $head;

    $out = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<svg viewBox="0 0 ' . $width . ' ' . $full . '" '
         . 'width="' . $width . '" height="' . $full . '" '
         . 'xmlns="http://www.w3.org/2000/svg" role="img" '
         . 'aria-label="Pipeline workflow diagram">'
         . '<title>' . e($caption !== '' ? $caption : 'Pipeline workflow') . '</title>'
         . '<style>' . $css . '</style>'
         // Painted, not assumed. A transparent SVG dropped into a dark slide
         // deck loses every label it has.
         . '<rect x="0" y="0" width="' . $width . '" height="' . $full . '" fill="#191919"/>';

    if ($caption !== '') {
        $out .= '<text x="' . $padX . '" y="22" class="wf-cap">' . e($caption) . '</text>';
    }

    return $out . '<g transform="translate(0,' . $head . ')">' . $svg . '</g></svg>';
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
