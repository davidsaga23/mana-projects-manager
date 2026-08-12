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

/**
 * The steps that make up one chain, in order.
 *
 * Shots and assets are separate production chains (Layout -> Animation vs
 * Concept -> Modeling), so dependencies are derived per chain. A step marked
 * 'both' belongs to each chain — uncommon, but it keeps "applies to both" from
 * meaning something different in the workflow than it does everywhere else.
 */
function chain_steps(array $steps, string $kind): array {
    return array_values(array_filter($steps, function ($s) use ($kind) {
        return $s['applies_to'] === $kind || $s['applies_to'] === 'both';
    }));
}

/**
 * Which steps run in parallel with the one before them, read back from the
 * stored graph. A step is parallel with its predecessor when the two depend on
 * exactly the same things — which is precisely what makes them run side by
 * side. Lets the checkbox UI round-trip a graph it didn't create (a template's,
 * or a later graph editor's).
 *
 * Returns step_id => bool for the given chain.
 */
function derive_parallel_flags(array $chain, array $depsMap): array {
    $flags = [];
    $prevDeps = null;
    foreach ($chain as $i => $step) {
        $id = (int)$step['id'];
        $deps = $depsMap[$id] ?? [];
        sort($deps);
        $flags[$id] = ($i > 0 && $prevDeps !== null && $deps === $prevDeps);
        $prevDeps = $deps;
    }
    return $flags;
}

/**
 * Turn "runs in parallel with the previous step" checkboxes back into a graph.
 *
 * Walking the chain, steps accumulate into a parallel cluster. A step that is
 * NOT parallel closes the current cluster and depends on all of it; a step that
 * IS parallel joins the current cluster and copies its dependencies. STFC's
 * pipeline falls out of this exactly:
 *
 *   Assembly -> Lighting, Render (both parallel, both depend on Assembly)
 *            -> Comp (not parallel, depends on Lighting AND Render)
 *
 * Returns step_id => [dep ids]; edges only ever point backwards, so this cannot
 * produce a cycle. pipeline_has_cycle() still verifies before anything is saved.
 */
function derive_dependencies(array $chain, array $parallelFlags): array {
    $deps = [];
    $prevCluster = [];
    $cluster = [];
    foreach ($chain as $i => $step) {
        $id = (int)$step['id'];
        $isParallel = $i > 0 && !empty($parallelFlags[$id]);
        if ($isParallel) {
            $deps[$id] = $prevCluster;
            $cluster[] = $id;
        } else {
            $prevCluster = $cluster;
            $cluster = [$id];
            $deps[$id] = $prevCluster;
        }
    }
    return $deps;
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
 * Replace a project's whole dependency graph from the wizard's checkboxes.
 * $parallelFlags is step_id => bool across both chains.
 *
 * Throws if the result would contain a cycle, leaving the stored graph
 * untouched — the scheduler must never be handed one.
 */
function save_workflow(PDO $pdo, int $projectId, array $parallelFlags): void {
    $steps = pipeline_steps($pdo, $projectId);
    $newDeps = [];
    foreach (['shot', 'asset'] as $kind) {
        $chain = chain_steps($steps, $kind);
        foreach (derive_dependencies($chain, $parallelFlags) as $stepId => $deps) {
            foreach ($deps as $d) {
                $newDeps[$stepId][$d] = true;   // union across chains, deduped
            }
            $newDeps[$stepId] = $newDeps[$stepId] ?? [];
        }
    }

    $check = [];
    foreach ($newDeps as $stepId => $set) $check[$stepId] = array_keys($set);
    if (pipeline_has_cycle($check)) {
        throw new RuntimeException('That workflow would loop back on itself. Nothing was saved.');
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare(
            'DELETE d FROM step_dependencies d
               JOIN pipeline_steps s ON s.id = d.step_id
              WHERE s.project_id = ?'
        );
        $del->execute([$projectId]);
        $ins = $pdo->prepare(
            'INSERT INTO step_dependencies (step_id, depends_on_step_id) VALUES (?, ?)'
        );
        foreach ($check as $stepId => $deps) {
            foreach ($deps as $d) $ins->execute([$stepId, $d]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Rebuild the graph from the parallelism already stored in it.
 *
 * Called after steps are added, deleted or reordered: existing parallel
 * clusters are preserved, and a newly added step links into the chain in
 * sequence. Without this a new step would sit with no dependencies at all and
 * the scheduler would happily start it on day one.
 */
function resync_workflow(PDO $pdo, int $projectId): void {
    $steps   = pipeline_steps($pdo, $projectId);
    $depsMap = step_dependencies_map($pdo, $projectId);
    $flags = [];
    foreach (['shot', 'asset'] as $kind) {
        $chain = chain_steps($steps, $kind);
        foreach (derive_parallel_flags($chain, $depsMap) as $id => $f) {
            $flags[$id] = ($flags[$id] ?? false) || $f;
        }
    }
    save_workflow($pdo, $projectId, $flags);
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
                (project_id, name, step_order, tracks_seconds, applies_to, days_per_unit, color)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $idMap = [];       // template step id => new step id
        $order = 0;
        foreach ($tplSteps as $t) {
            $ins->execute([
                $projectId, $t['name'], ++$order, $t['tracks_seconds'],
                $t['applies_to'], $t['days_per_unit'] ?? 1.0, $t['color'],
            ]);
            $idMap[(int)$t['id']] = (int)$pdo->lastInsertId();
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

/** Renumber step_order 1..n in current display order (keeps reordering simple). */
function renumber_steps(PDO $pdo, int $projectId): void {
    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare('UPDATE pipeline_steps SET step_order = ? WHERE id = ?');
    $n = 0;
    foreach ($steps as $s) $upd->execute([++$n, $s['id']]);
}

/**
 * Group a chain into the clusters that actually run one after another.
 *
 * Steps running in parallel land in the same cluster, so the number of clusters
 * is how many sequential slots the calendar has to accommodate — which is what
 * spreading deadlines across a project needs to know.
 */
function sequential_clusters(array $chain, array $depsMap): array {
    $flags = derive_parallel_flags($chain, $depsMap);
    $clusters = [];
    foreach ($chain as $i => $step) {
        if ($i === 0 || empty($flags[(int)$step['id']])) {
            $clusters[] = [$step];
        } else {
            $clusters[count($clusters) - 1][] = $step;
        }
    }
    return $clusters;
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
