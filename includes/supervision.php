<?php
// Supervision positions — Anim Sup, CG Sup and the like. Migration 068.
//
// David, 2026-10-26: a supervisor gives feedback and plans; they carry no quota
// and do no hands-on work, but some shows hire one and the budget has to say so.
// "They won't have days per item or people, we just need to add the start and
// end dates so they're reflected in the staffing and expense projections."
//
// ⚠ READ IN THREE PLACES AND NO OTHERS, and that is the design:
//
//   includes/wizard/planning.php   Dates & headcount — where a position is set
//   crew.php                       Staffing — who is in it
//   includes/finance.php           expense_projection() — what it costs
//
// The scheduler, the quota, the Budget pool and the timeline never see these.
// A supervisor produces no items, so a pipeline step standing in for one put a
// block of work on the timeline and a card in the pool reading 0% done for its
// whole life. If a fourth screen ever needs them, it reads them from here.
//
// ⚠ THE OVERHEAD RULE STILL STANDS (ROADMAP, 2026-10-07): the studio's own
// leadership is recovered by markup, not as a line on each show. A position here
// is for a supervisor THIS show pays for, which is a different thing.

require_once __DIR__ . '/functions.php';

/** Has migration 068 been applied? */
function supervision_available(PDO $pdo): bool {
    return column_exists($pdo, 'supervision_positions', 'crew_id');
}

/** Every supervision position on a project, in order, with whoever holds it. */
function supervision_positions(PDO $pdo, int $projectId): array {
    if (!supervision_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT sp.*, c.name, c.is_placeholder, c.active
           FROM supervision_positions sp
           JOIN crew c ON c.id = sp.crew_id
          WHERE sp.project_id = ?
          ORDER BY sp.sort_order, sp.id'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/**
 * The working days a position covers, both ends counted, weekends and studio
 * holidays left out — the same count the steps on Dates & headcount use, so a
 * supervisor from the first day to delivery costs the days the show actually has.
 * 0 until both dates are set, which leaves the row unpriced rather than free.
 */
function supervision_days(array $pos): int {
    $s = (string)($pos['start_date'] ?? '');
    $e = (string)($pos['end_date'] ?? '');
    if ($s === '' || $e === '') return 0;
    return working_days_between($s, $e);
}

/**
 * Save the Supervision table on Dates & headcount: edit the existing rows, remove
 * the ticked ones, add the new one if it has a title.
 *
 * ⚠ A NEW POSITION GETS ITS OWN PLACEHOLDER crew row, named after it, the way a
 * slot does (sync_project_lanes()). That is what lets the Finance screen hold a
 * rate for "CG Sup" before anybody is hired, and what Staffing re-points when
 * somebody is. Renaming a position renames its placeholder too, while it is one;
 * a real person's name is never touched.
 *
 * @return string[] what to tell the user
 */
function save_supervision(PDO $pdo, array $project, array $in, string $who = ''): array {
    if (!supervision_available($pdo)) {
        throw new RuntimeException('Supervision positions need database update 068, which has not '
                                 . 'been applied yet. Nothing was saved.');
    }
    $projectId = (int)$project['id'];
    $said = []; $warn = [];

    $existing = [];
    foreach (supervision_positions($pdo, $projectId) as $p) $existing[(int)$p['id']] = $p;

    $check = function (string $title, ?string $start, ?string $end) use ($project, &$warn): bool {
        if ($start !== null && $end !== null && $end < $start) {
            $warn[] = "\"$title\" ends before it starts, so it was not saved. Swap the two dates.";
            return false;
        }
        // Outside the show is allowed, a supervisor can start in prep before the
        // project's own start date, but said, because it is usually a typo.
        $ps = $project['start_date'] ?? null; $pe = $project['delivery_date'] ?? null;
        if (($start !== null && $ps && $start < $ps) || ($end !== null && $pe && $end > $pe)) {
            $warn[] = "\"$title\" runs outside the project's own dates ($ps to $pe). Saved as typed.";
        }
        return true;
    };
    $date = function ($v): ?string {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };

    $pdo->beginTransaction();
    try {
        $remove = array_map('intval', array_keys((array)($in['remove'] ?? [])));
        $upd = $pdo->prepare(
            'UPDATE supervision_positions SET title = ?, start_date = ?, end_date = ?
              WHERE id = ? AND project_id = ?'
        );
        $renamePh = $pdo->prepare(
            'UPDATE crew SET name = ?, role = ? WHERE id = ? AND is_placeholder = 1'
        );
        $edited = 0; $removed = 0;

        foreach ($existing as $id => $p) {
            if (in_array($id, $remove, true)) {
                supervision_delete($pdo, $projectId, $id);
                $removed++;
                continue;
            }
            if (!isset($in['title'][$id])) continue;
            $title = trim((string)$in['title'][$id]);
            if ($title === '') $title = (string)$p['title'];
            $start = $date($in['start_date'][$id] ?? '');
            $end   = $date($in['end_date'][$id] ?? '');
            if (!$check($title, $start, $end)) continue;

            if ($title === $p['title'] && $start === ($p['start_date'] ?: null)
                && $end === ($p['end_date'] ?: null)) continue;
            $upd->execute([mb_substr($title, 0, 120), $start, $end, $id, $projectId]);
            if ($title !== $p['title']) $renamePh->execute([$title, $title, (int)$p['crew_id']]);
            $edited++;
        }

        $newTitle = trim((string)($in['new_title'] ?? ''));
        if ($newTitle !== '') {
            $start = $date($in['new_start'] ?? '');
            $end   = $date($in['new_end'] ?? '');
            if ($check($newTitle, $start, $end)) {
                $newTitle = mb_substr($newTitle, 0, 120);
                $pdo->prepare('INSERT INTO crew (name, role, is_placeholder, active) VALUES (?, ?, 1, 1)')
                    ->execute([$newTitle, $newTitle]);
                $crewId = (int)$pdo->lastInsertId();
                $next = $pdo->prepare(
                    'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM supervision_positions WHERE project_id = ?'
                );
                $next->execute([$projectId]);
                $pdo->prepare(
                    'INSERT INTO supervision_positions
                       (project_id, title, crew_id, start_date, end_date, sort_order, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$projectId, $newTitle, $crewId, $start, $end,
                            (int)$next->fetchColumn(), $who ?: null]);
                // On the show, the same as a slot's placeholder is.
                $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
                    ->execute([$crewId, $projectId]);
                $said[] = "Added the supervision position \"$newTitle\". Put somebody in it on Staffing.";
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($edited)  $said[] = "Saved $edited supervision position" . ($edited === 1 ? '' : 's') . '.';
    if ($removed) $said[] = "Removed $removed supervision position" . ($removed === 1 ? '' : 's')
                          . '. Anybody who held one stays on the studio crew list.';
    return ['ok' => $said, 'warn' => $warn];
}

/**
 * Remove a position. Its placeholder goes with it when nothing else holds it; a
 * real person never does, they belong to the studio, the rule every slot follows.
 * Safe inside the caller's transaction.
 */
function supervision_delete(PDO $pdo, int $projectId, int $id): void {
    $stmt = $pdo->prepare(
        'SELECT sp.crew_id, c.is_placeholder FROM supervision_positions sp
           JOIN crew c ON c.id = sp.crew_id WHERE sp.id = ? AND sp.project_id = ?'
    );
    $stmt->execute([$id, $projectId]);
    $row = $stmt->fetch();
    if (!$row) return;
    $pdo->prepare('DELETE FROM supervision_positions WHERE id = ? AND project_id = ?')
        ->execute([$id, $projectId]);
    if ((int)$row['is_placeholder'] === 1) supervision_drop_placeholder($pdo, (int)$row['crew_id']);
}

/**
 * Delete an empty placeholder that nothing points at any more. Guarded the same
 * way the slot removals are, plus the positions table, so it can never take a
 * crew row somebody else still holds.
 */
function supervision_drop_placeholder(PDO $pdo, int $crewId): void {
    $pdo->prepare(
        'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
           AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)
           AND NOT EXISTS (SELECT 1 FROM supervision_positions WHERE crew_id = crew.id)'
    )->execute([$crewId]);
}

/**
 * Put a person into a position: one already on the studio crew list by id, or a
 * name typed in. A typed name that EXACTLY matches somebody on the list is that
 * person rather than a second copy of them, the rule link_login_to_crew() keeps.
 *
 * @return string what happened, in a sentence
 */
function supervision_assign(PDO $pdo, int $projectId, int $posId, int $crewId, string $typed = ''): string {
    $stmt = $pdo->prepare(
        'SELECT sp.*, c.is_placeholder FROM supervision_positions sp
           JOIN crew c ON c.id = sp.crew_id WHERE sp.id = ? AND sp.project_id = ?'
    );
    $stmt->execute([$posId, $projectId]);
    $pos = $stmt->fetch();
    if (!$pos) throw new RuntimeException('That supervision position no longer exists.');

    $typed = trim($typed);
    $made = false;
    // A typed name wins over the picker, which arrives pre-selected on whoever
    // holds the position now.
    if ($typed !== '') {
        $find = $pdo->prepare('SELECT id FROM crew WHERE name = ? AND is_placeholder = 0 LIMIT 1');
        $find->execute([$typed]);
        $crewId = (int)$find->fetchColumn();
        if ($crewId <= 0) {
            $pdo->prepare('INSERT INTO crew (name, role, active, is_team) VALUES (?, ?, 1, 0)')
                ->execute([mb_substr($typed, 0, 100), $pos['title']]);
            $crewId = (int)$pdo->lastInsertId();
            $made = true;
        }
    }
    if ($crewId <= 0) throw new RuntimeException('Pick somebody, or type a name, to put in that position.');

    $who = $pdo->prepare('SELECT name, is_placeholder FROM crew WHERE id = ?');
    $who->execute([$crewId]);
    $person = $who->fetch();
    if (!$person || (int)$person['is_placeholder'] === 1) {
        throw new RuntimeException('That is not somebody on the studio crew list.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE supervision_positions SET crew_id = ? WHERE id = ?')->execute([$crewId, $posId]);
        // On the show: it is what lets a supervisor log in and find it.
        $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
            ->execute([$crewId, $projectId]);
        if ((int)$pos['is_placeholder'] === 1) supervision_drop_placeholder($pdo, (int)$pos['crew_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return sprintf('%s is now %s on this show%s.', $person['name'], $pos['title'],
                   $made ? ', and was added to the studio crew list' : '');
}

/**
 * Take the person out and leave the position, with a fresh placeholder in it —
 * what unassign_lane() does for a slot, for the same reason: the show still
 * needs a supervisor, somebody else will be it.
 */
function supervision_unassign(PDO $pdo, int $projectId, int $posId): string {
    $stmt = $pdo->prepare(
        'SELECT sp.*, c.name AS person, c.is_placeholder FROM supervision_positions sp
           JOIN crew c ON c.id = sp.crew_id WHERE sp.id = ? AND sp.project_id = ?'
    );
    $stmt->execute([$posId, $projectId]);
    $pos = $stmt->fetch();
    if (!$pos) throw new RuntimeException('That supervision position no longer exists.');
    if ((int)$pos['is_placeholder'] === 1) {
        throw new RuntimeException('Nobody is in that position yet, so there is nobody to take out.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO crew (name, role, is_placeholder, active) VALUES (?, ?, 1, 1)')
            ->execute([$pos['title'], $pos['title']]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE supervision_positions SET crew_id = ? WHERE id = ?')->execute([$newId, $posId]);
        $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
            ->execute([$newId, $projectId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return sprintf('%s is no longer %s. The position is still there, and %s stays on the studio crew list.',
                   $pos['person'], $pos['title'], $pos['person']);
}
