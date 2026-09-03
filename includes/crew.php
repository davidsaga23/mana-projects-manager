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
                ps.name AS step_name, ps.color, ps.step_order, ps.department,
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

            // Surplus is worked out BEFORE the no-headcount short-circuit below.
            // Clearing a step's headcount leaves every one of its slots above
            // what is asked for, and skipping the check meant the sync reported
            // none while the screen highlighted them all.
            foreach ($existing[$stepId] ?? [] as $laneNo => $row) {
                if ($laneNo > $want) $out['surplus'][] = $row;
            }

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

        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** Is this lane above what its step's headcount now asks for? */
function lane_is_surplus(array $lane): bool {
    return (int)$lane['lane_no'] > step_slot_count($lane);
}

/**
 * Remove every slot above the current headcount, in one go.
 *
 * Two things it will not do, both for the same reason — a slot is only litter
 * once nothing depends on it:
 *
 *   * A slot with scheduled tasks is left alone and reported. Deleting it would
 *     orphan real planned work; re-running the schedule moves that work onto the
 *     remaining slots first, and then it goes.
 *   * A slot holding a REAL person releases the slot but never deletes the
 *     person. They belong to the studio, not to one show's headcount estimate.
 *
 * Returns ['removed' => int, 'blocked' => [['name','tasks'], ...], 'kept_people' => int].
 */
function remove_surplus_lanes(PDO $pdo, int $projectId): array {
    $out = ['removed' => 0, 'blocked' => [], 'kept_people' => 0];

    $used = $pdo->prepare(
        'SELECT COUNT(*) FROM schedule WHERE step_id = ? AND lane = ? AND project_id = ?'
    );
    $delLane = $pdo->prepare('DELETE FROM crew_lanes WHERE id = ? AND project_id = ?');
    // Only ever removes the crew row when it is an unfilled placeholder that
    // nothing else points at. Same guard the single-slot remove uses.
    $delCrew = $pdo->prepare(
        'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
           AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)'
    );

    $pdo->beginTransaction();
    try {
        foreach (project_lanes($pdo, $projectId) as $l) {
            if (!lane_is_surplus($l)) continue;

            $used->execute([(int)$l['step_id'], (int)$l['lane_no'], $projectId]);
            $n = (int)$used->fetchColumn();
            if ($n > 0) {
                $out['blocked'][] = ['name' => $l['name'], 'tasks' => $n];
                continue;
            }

            $delLane->execute([(int)$l['id'], $projectId]);
            $delCrew->execute([(int)$l['crew_id']]);
            if ((int)$l['is_placeholder'] !== 1) $out['kept_people']++;
            $out['removed']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/**
 * ⚠ crew_view_switch() WAS HERE AND LASTED ONE COMMIT (2026-09-02).
 *
 * It offered "By position" and "By stage" as two views of one tab, which was a
 * misreading of what David asked for: "I was asking to merge both of them and
 * do all the staffing and hiring in the same page." Two views behind one tab is
 * still two pages, and it still made somebody choose an arrangement before they
 * could do anything.
 *
 * A position and the conversations to fill it are one-to-many, and one-to-many
 * belongs NESTED. The candidates, their stage and the hire itself now sit on the
 * position row in crew.php, so there is one list rather than two views of two.
 */


/**
 * ⚠ promote_placeholder() WAS HERE AND IS GONE (2026-09-02). Do not bring it
 * back without reading this.
 *
 * It renamed a slot's own crew row into the person hired for it and cleared the
 * placeholder flag. The id never changed, so everything already planned for the
 * slot became theirs with nothing moved, and for a single slot that was exactly
 * right. Across slots it was a data bug: every slot has its OWN crew row, and
 * nothing checked whether a person of that name already existed, so an artist
 * hired into three slots became three people, each holding its own scheduled
 * work, with no way to tell them apart in a picker and no way to join them up.
 *
 * Filling a slot now goes through assign_crew_to_lane() in every path: the
 * person is found or created once, and the slot is re-pointed at them. A slot
 * is a position, a person is a person, and filling one is a link between them.
 *
 * The clean-up for what the old way left is people_merge.php.
 */


/**
 * Put a real person who already exists into a slot, replacing whoever is in it.
 *
 * ⚠ SAFE TO CALL FROM INSIDE ANOTHER TRANSACTION, and that is not decoration.
 * hire_candidate() wraps the whole hire in one, and this is called from the
 * middle of it. MySQL has no nested transactions: a second beginTransaction()
 * throws "There is already an active transaction" and the hire fails outright,
 * which is exactly what David hit trying to hire Luka Bromley (2026-09-02).
 *
 * So it opens one only when it is the outermost caller, and commits only what
 * it opened. Nested, an exception simply propagates and the CALLER's rollback
 * undoes the lot - which is the behaviour you want anyway: a hire that half
 * happened is worse than one that did not.
 */
function assign_crew_to_lane(PDO $pdo, int $projectId, int $laneId, int $crewId): void {
    $stmt = $pdo->prepare('SELECT * FROM crew_lanes WHERE id = ? AND project_id = ?');
    $stmt->execute([$laneId, $projectId]);
    $lane = $stmt->fetch();
    if (!$lane) throw new RuntimeException('That slot no longer exists.');

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
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

        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        // Only roll back what this call started. Rolling back somebody else's
        // transaction from in here would silently undo work the caller has not
        // finished describing, and leave them committing nothing.
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Everyone available to put in a slot: real people first, then placeholders from
 * other projects are excluded — borrowing another show's empty slot would be
 * meaningless.
 */
/**
 * Take the person out of a slot, and leave the SLOT standing.
 *
 * A different thing from removing the slot, and the difference is the whole
 * point. Removing a slot says the plan no longer needs this job done; taking the
 * assignee out says it does, and somebody else will do it. One is a change to
 * the plan, the other to the casting.
 *
 * Done by putting a fresh PLACEHOLDER back in the lane, which is what the slot
 * held before anybody was hired into it, rather than by nulling crew_lanes.crew_id
 * — that column is NOT NULL, and a lane with no crew row would have no name to
 * draw and nothing for the scheduler to assign against.
 *
 * The scheduled work MOVES WITH THE SLOT, because assign_crew_to_lane() carries
 * it. That is the behaviour wanted: the days were planned for this job, not for
 * this person, and they should still be there for whoever comes next.
 *
 * The person is never deleted. They belong to the studio, not to one show —
 * the same rule the "remove slot" path has always followed.
 */
function unassign_lane(PDO $pdo, int $projectId, int $laneId): string {
    $stmt = $pdo->prepare(
        'SELECT cl.*, c.name AS person, c.is_placeholder, ps.name AS step_name,
                ps.applies_to, ps.applies_to_asset_type, ps.planned_headcount
           FROM crew_lanes cl
           JOIN crew c ON c.id = cl.crew_id
           JOIN pipeline_steps ps ON ps.id = cl.step_id
          WHERE cl.id = ? AND cl.project_id = ?'
    );
    $stmt->execute([$laneId, $projectId]);
    $lane = $stmt->fetch();
    if (!$lane) throw new RuntimeException('That slot no longer exists.');
    if ((int)$lane['is_placeholder'] === 1) {
        throw new RuntimeException('That slot has nobody in it yet, so there is nobody to take out.');
    }

    // Named exactly as sync_project_lanes() would have named it, so a slot
    // emptied by hand is indistinguishable from one that was never filled.
    $name = placeholder_name(
        (string)$lane['step_name'], (int)$lane['lane_no'],
        step_slot_count($lane), step_scope_tag([
            'applies_to' => $lane['applies_to'],
            'applies_to_asset_type' => $lane['applies_to_asset_type'] ?? null,
        ])
    );

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO crew (name, role, is_placeholder, active) VALUES (?, NULL, 1, 1)')
            ->execute([$name]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Its own transaction, and it carries the scheduled work across.
    assign_crew_to_lane($pdo, $projectId, $laneId, $newId);

    return sprintf('%s is no longer in "%s". The slot is still there, and everything scheduled '
                 . 'on it is waiting for whoever takes it next. %s stays on the studio crew list.',
                   $lane['person'], $name, $lane['person']);
}

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

/**
 * Tie a LOGIN to a PERSON, creating the crew row if there isn't one.
 *
 * `users` are logins and `crew` are people; a ticket is assigned to a person, so
 * an account with no crew row cannot own anything and has no "My tasks" list.
 * This is the one operation that fixes that, shared by users.php (an admin doing
 * it for someone) and mytasks.php (someone doing it for themselves) so the two
 * cannot drift into behaving differently.
 *
 * An EXACT name match reuses the existing row rather than creating a second copy
 * of the same person — the same rule the status mapping and the alias layer
 * follow. Anything less exact is left alone: two spellings are a job for the
 * alias screen, not for a silent merge here.
 *
 * @return array{0:int, 1:string}  [crew id, a sentence saying what happened]
 */
function link_login_to_crew(PDO $pdo, int $userId, string $personName): array {
    $personName = trim($personName);
    if ($personName === '') {
        throw new RuntimeException('That account has no name to create a crew member from.');
    }

    $find = $pdo->prepare('SELECT id FROM crew WHERE name = ? LIMIT 1');
    $find->execute([$personName]);
    $crewId = $find->fetchColumn();

    if ($crewId) {
        $pdo->prepare('UPDATE users SET crew_id = ? WHERE id = ?')->execute([(int)$crewId, $userId]);
        return [(int)$crewId, "\"$personName\" was already in the crew list, so the account was linked "
                            . 'to that person rather than a second copy being made.'];
    }

    $pdo->prepare('INSERT INTO crew (name, role, active, is_team) VALUES (?, NULL, 1, 0)')
        ->execute([$personName]);
    $crewId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE users SET crew_id = ? WHERE id = ?')->execute([$crewId, $userId]);

    return [$crewId, "Added \"$personName\" to the crew list and linked the account. Tickets can now "
                   . 'be assigned to them on any project. Add an email address on a project Crew '
                   . 'screen if they should be notified.'];
}
