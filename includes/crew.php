<?php
// Crew: real people and the placeholder slots that stand in for them.
//
// The governing idea, which is David's: a crew is never complete until the show
// nearly is. People arrive as their department starts. So planning cannot wait
// for names — it works against SLOTS, and a slot becomes a person the day one is
// hired, without anything that referenced it having to change.

require_once __DIR__ . '/functions.php';

/**
 * A short word for what a step covers, for telling two steps of the same name
 * apart: "Character", "Environment", "Shots", "Project".
 *
 * The asset type is used verbatim rather than abbreviated. "CH"/"PR"/"EN" is how
 * people say it out loud, but types are free text — a studio that adds "Crowd"
 * or "Matte painting" would need an abbreviation nobody agreed on, and a wrong
 * guess here ends up printed on a timeline bar.
 */
function step_scope_tag(array $step): string {
    $type = $step['applies_to_asset_type'] ?? '';
    switch ($step['applies_to'] ?? 'shot') {
        case 'asset':   return $type !== '' ? $type : 'Assets';
        case 'both':    return 'All';
        case 'project': return 'Project';
        default:        return 'Shots';
    }
}

/**
 * Step names that more than one step in the project uses.
 *
 * "Concept" is legitimately both a character step and an environment step — step
 * names have only had to be unique within their scope since migration 009. Slots
 * generated from them would otherwise both be called "Concept 01", which is
 * indistinguishable on this screen, on the schedule, and on every future
 * timeline bar.
 */
function ambiguous_step_names(array $steps): array {
    $seen = [];
    foreach ($steps as $s) {
        $k = mb_strtolower(trim($s['name']));
        $seen[$k] = ($seen[$k] ?? 0) + 1;
    }
    return array_filter($seen, function ($n) { return $n > 1; });
}

/**
 * The default name for slot N of a step: "Animation 02".
 *
 * Gains the scope only when the step name is shared with another step —
 * "Concept Character 01" — so the common case stays short and the ambiguous case
 * is readable rather than merely numbered.
 */
function placeholder_name(string $stepName, int $lane, int $laneCount, string $scopeTag = ''): string {
    $base = $scopeTag !== '' ? $stepName . ' ' . $scopeTag : $stepName;
    // A single slot needs no number — "Animatic 01" implies there is an 02.
    if ($laneCount <= 1) return $base;
    return $base . ' ' . str_pad((string)$lane, 2, '0', STR_PAD_LEFT);
}

/** How many slots a step's planned headcount asks for. */
function step_slot_count(array $step): int {
    $planned = $step['planned_headcount'] ?? null;
    if ($planned === null || (float)$planned <= 0) return 0;
    // Ceil, matching the scheduler: a half-time person still occupies a slot,
    // they just fill it more slowly.
    return max(1, (int)ceil((float)$planned));
}

/** Every lane slot on a project, with whoever fills it. */
function project_lanes(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT cl.*, c.name, c.role, c.is_placeholder, c.active, c.email,
                ps.name AS step_name, ps.color, ps.step_order,
                ps.applies_to, ps.applies_to_asset_type, ps.planned_headcount
           FROM crew_lanes cl
           JOIN crew c            ON c.id  = cl.crew_id
           JOIN pipeline_steps ps ON ps.id = cl.step_id
          WHERE cl.project_id = ?
          ORDER BY ps.step_order, ps.id, cl.lane_no'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** step_id => [lane_no => crew row], for the scheduler to assign from. */
function lane_crew_map(PDO $pdo, int $projectId): array {
    $map = [];
    foreach (project_lanes($pdo, $projectId) as $l) {
        $map[(int)$l['step_id']][(int)$l['lane_no']] = $l;
    }
    return $map;
}

/**
 * Create the slots a project's headcount implies, and report what it found.
 *
 * Re-runnable on purpose — headcount changes as a show is planned, so this is
 * run again rather than done once. It only ever ADDS.
 *
 * It never deletes a slot that is no longer needed, and that is deliberate: a
 * slot may already have scheduled tasks or logged progress against it, and
 * dropping headcount from 3 to 2 must not silently discard the third person's
 * work. Surplus slots are reported so a person decides.
 *
 * Returns ['created' => int, 'existing' => int, 'surplus' => [...], 'skipped' => [...]].
 */
function sync_project_lanes(PDO $pdo, int $projectId): array {
    $out = ['created' => 0, 'existing' => 0, 'surplus' => [], 'skipped' => [], 'renamed' => 0];

    $stmt = $pdo->prepare(
        'SELECT * FROM pipeline_steps WHERE project_id = ? ORDER BY step_order, id'
    );
    $stmt->execute([$projectId]);
    $steps = $stmt->fetchAll();
    if (!$steps) return $out;

    $existing  = lane_crew_map($pdo, $projectId);
    $ambiguous = ambiguous_step_names($steps);

    $insCrew = $pdo->prepare(
        'INSERT INTO crew (name, role, is_placeholder, active) VALUES (?, ?, 1, 1)'
    );
    // Only ever renames rows that are still placeholders. Once a slot has been
    // given a real person's name it is theirs, and nothing here may touch it.
    $renameCrew = $pdo->prepare(
        'UPDATE crew SET name = ?, role = ? WHERE id = ? AND is_placeholder = 1 AND name <> ?'
    );
    $insLane = $pdo->prepare(
        'INSERT INTO crew_lanes (project_id, step_id, lane_no, crew_id) VALUES (?, ?, ?, ?)'
    );
    $insMember = $pdo->prepare(
        'INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($steps as $step) {
            $stepId = (int)$step['id'];
            $want   = step_slot_count($step);

            if ($want === 0) {
                $out['skipped'][] = $step['name'];
                continue;
            }

            // Scope only appears in the name when two steps share a name, so
            // "Animatic" stays "Animatic" and the two Concepts become
            // "Concept Character" and "Concept Environment".
            $tag = isset($ambiguous[mb_strtolower(trim($step['name']))])
                 ? step_scope_tag($step) : '';
            $role = trim($step['name'] . ($tag !== '' ? ' · ' . $tag : ''));

            for ($lane = 1; $lane <= $want; $lane++) {
                if (isset($existing[$stepId][$lane])) {
                    $out['existing']++;
                    // A step that has just become ambiguous — because another
                    // step was added with the same name — leaves slots named as
                    // if it were still unique. Bring them up to date.
                    $wanted = placeholder_name($step['name'], $lane, $want, $tag);
                    $row = $existing[$stepId][$lane];
                    if ((int)$row['is_placeholder'] === 1 && $row['name'] !== $wanted) {
                        $renameCrew->execute([$wanted, $role, (int)$row['crew_id'], $wanted]);
                        $out['renamed'] += $renameCrew->rowCount();
                    }
                    continue;
                }

                $insCrew->execute([
                    placeholder_name($step['name'], $lane, $want, $tag),
                    $role,                   // role: what this slot does
                ]);
                $crewId = (int)$pdo->lastInsertId();
                $insLane->execute([$projectId, $stepId, $lane, $crewId]);
                $insMember->execute([$crewId, $projectId]);
                $out['created']++;
            }

            // More slots than the headcount now asks for. Kept, and named.
            foreach ($existing[$stepId] ?? [] as $laneNo => $row) {
                if ($laneNo > $want) $out['surplus'][] = $row;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/**
 * Turn a slot into a person: rename it and clear the placeholder flag.
 *
 * The crew row's id is untouched, which is the whole point — everything already
 * pointing at that slot (scheduled tasks, progress records, the alias layer)
 * keeps pointing at it, and what was PLANNED for a slot becomes what was DONE by
 * a person with no re-linking at all.
 */
function promote_placeholder(PDO $pdo, int $crewId, string $realName, ?string $email = null): void {
    $realName = trim($realName);
    if ($realName === '') throw new RuntimeException('Give the person a name.');

    $stmt = $pdo->prepare('SELECT * FROM crew WHERE id = ?');
    $stmt->execute([$crewId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('That crew slot no longer exists.');
    if ((int)$row['is_placeholder'] !== 1) {
        throw new RuntimeException('"' . $row['name'] . '" is already a real person.');
    }

    $pdo->prepare(
        'UPDATE crew SET name = ?, email = ?, is_placeholder = 0 WHERE id = ?'
    )->execute([$realName, $email !== null && $email !== '' ? $email : null, $crewId]);

    // The slot's old name is what an import or an old export may still call this
    // person, so it is kept as an alias rather than thrown away.
    $pdo->prepare(
        'INSERT IGNORE INTO crew_aliases (alias_name, crew_id) VALUES (?, ?)'
    )->execute([$row['name'], $crewId]);
}

/** Put a real person who already exists into a slot, replacing whoever is in it. */
function assign_crew_to_lane(PDO $pdo, int $projectId, int $laneId, int $crewId): void {
    $stmt = $pdo->prepare('SELECT * FROM crew_lanes WHERE id = ? AND project_id = ?');
    $stmt->execute([$laneId, $projectId]);
    $lane = $stmt->fetch();
    if (!$lane) throw new RuntimeException('That slot no longer exists.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE crew_lanes SET crew_id = ? WHERE id = ?')->execute([$crewId, $laneId]);
        $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
            ->execute([$crewId, $projectId]);

        // Move any work already scheduled against the old occupant of this slot.
        // Without this the timeline would still show the placeholder's name on
        // tasks the new person is actually doing.
        $pdo->prepare(
            'UPDATE schedule SET crew_id = ? WHERE step_id = ? AND lane = ? AND project_id = ?'
        )->execute([$crewId, (int)$lane['step_id'], (int)$lane['lane_no'], $projectId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Everyone available to put in a slot: real people first, then placeholders from
 * other projects are excluded — borrowing another show's empty slot would be
 * meaningless.
 */
function assignable_crew(PDO $pdo): array {
    return $pdo->query(
        'SELECT id, name, role, email, active, is_team
           FROM crew
          WHERE is_placeholder = 0 AND is_team = 0
          ORDER BY active DESC, name'
    )->fetchAll();
}

/** How many slots on a project are still unfilled placeholders. */
function unfilled_slot_count(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM crew_lanes cl JOIN crew c ON c.id = cl.crew_id
          WHERE cl.project_id = ? AND c.is_placeholder = 1'
    );
    $stmt->execute([$projectId]);
    return (int)$stmt->fetchColumn();
}
