<?php
// Joining two records of the same person back into one.
//
// David, 2026-09-02, on finding James Douglas Murphy three times in the "already
// on the books" picker: "is there a way to combine hiring and crew".
//
// WHY THERE ARE DUPLICATES AT ALL. Every crew slot owns its own `crew` row -
// sync_project_lanes() creates one per lane. Until 2026-09-02 the Crew screen's
// "Register hire" RENAMED that row into the person, and nothing checked whether
// the name already existed. Three slots, three records. That door is now shut,
// but nothing existed to clean up what came through it, and each copy holds its
// own scheduled work.
//
// ⚠ THIS IS THE ONE OPERATION IN THE APP THAT DESTROYS A crew.id, and that id is
// load-bearing in a way no foreign key enforces: the FINANCE database keys
// crew_terms on it, with no constraint between the two databases because there
// cannot be one. Deleting a crew row that finance still points at leaves a day
// rate belonging to nobody, in a database this connection is not even allowed to
// open unless an administrator is asking. Hence:
//
//   * ADMIN ONLY, not as seniority but as capability - the checks below run
//     through can_see_money(), which IS is_admin(), and a merge that cannot look
//     at the money side must not happen. Same rule as deleting a project.
//   * FINANCE MOVES FIRST. The two databases are separate connections and there
//     is no transaction across them, so one side can land and the other fail.
//     If finance lands and production does not, the keeper has picked up a rate
//     it will show on screen: wrong, visible, fixable. If production lands
//     first and finance fails, money records point at an id that no longer
//     exists: invisible, and nothing left says whose rate it was. Only one of
//     those two orders is recoverable.
//   * A CLASH IS REFUSED, NEVER GUESSED. If both records carry a day rate,
//     picking one is a money decision and this is not the screen for it.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/money.php';

/**
 * Every name that more than one crew record answers to.
 *
 * Matched on the LOWERCASED, TRIMMED name, the same rule known_candidates(), the
 * alias layer and the artist book all use. Deliberately not fuzzy: this screen
 * offers to destroy a record, and a near-match it invented is the last thing
 * that should be on it. "Luka Bromley" and "Luka Bromely" will not be offered
 * together, and that is correct - they may be two people.
 *
 * Placeholders are excluded. An unfilled slot is legitimately called "CH sheet
 * 01" on every show that has one, and those are not duplicates of each other.
 */
function duplicate_crew_groups(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id, name, role, email, active, is_team, created_at
           FROM crew
          WHERE is_placeholder = 0
          ORDER BY name, id"
    )->fetchAll();

    $groups = [];
    foreach ($rows as $r) {
        $key = mb_strtolower(trim((string)$r['name']));
        if ($key === '') continue;
        $groups[$key][] = $r;
    }
    // Only the ones that are actually a problem.
    $out = [];
    foreach ($groups as $key => $members) {
        if (count($members) > 1) $out[$key] = $members;
    }
    ksort($out);
    return $out;
}

/**
 * What a crew record is carrying, so nobody merges blind.
 *
 * Every table in the app that points at crew.id, counted. The list is written
 * out rather than discovered, because a merge that silently missed a table
 * would orphan exactly the rows nobody thought to look for - and the failure
 * would be invisible until somebody wondered where a timesheet went.
 */
function crew_usage(PDO $pdo, int $crewId): array {
    $one = function (string $sql) use ($pdo, $crewId): int {
        try {
            $q = $pdo->prepare($sql);
            $q->execute([$crewId]);
            return (int)$q->fetchColumn();
        } catch (PDOException $e) {
            return 0;          // a table from a migration that has not run yet
        }
    };
    return [
        'slots'     => $one('SELECT COUNT(*) FROM crew_lanes WHERE crew_id = ?'),
        'projects'  => $one('SELECT COUNT(*) FROM crew_projects WHERE crew_id = ?'),
        'scheduled' => $one('SELECT COUNT(*) FROM schedule WHERE crew_id = ?'),
        'progress'  => $one('SELECT COUNT(*) FROM progress_crew WHERE crew_id = ?'),
        'tickets'   => $one('SELECT COUNT(*) FROM tickets WHERE assigned_crew_id = ?'),
        'time_logs' => $one('SELECT COUNT(*) FROM time_logs WHERE crew_id = ?'),
        'hires'     => $one('SELECT COUNT(*) FROM hires WHERE crew_id = ?'),
        'logins'    => $one('SELECT COUNT(*) FROM users WHERE crew_id = ?'),
        'aliases'   => $one('SELECT COUNT(*) FROM crew_aliases WHERE crew_id = ?'),
        'skills'    => $one('SELECT COUNT(*) FROM crew_skills WHERE crew_id = ?'),
    ];
}

/**
 * What the FINANCE database holds against this person.
 *
 * 'reachable' false means the question could not be asked, which is not the same
 * as "nothing there" and must never be treated as it. A merge refuses on it.
 */
function crew_money_footprint(int $crewId): array {
    $out = ['terms' => 0, 'invoices' => 0, 'log' => 0, 'total' => 0,
            'reachable' => true, 'day_rate' => null];

    if (!finance_configured()) return $out;           // definitively nothing there
    if (!can_see_money())      { $out['reachable'] = false; return $out; }
    $db = finance_db();
    if (!$db)                  { $out['reachable'] = false; return $out; }

    try {
        if (finance_column_exists('crew_terms', 'crew_id')) {
            $q = $db->prepare('SELECT day_rate FROM crew_terms WHERE crew_id = ?');
            $q->execute([$crewId]);
            $row = $q->fetch();
            if ($row) {
                $out['terms'] = 1;
                $out['day_rate'] = $row['day_rate'];
            }
        }
        if (finance_column_exists('crew_terms_log', 'crew_id')) {
            $q = $db->prepare('SELECT COUNT(*) FROM crew_terms_log WHERE crew_id = ?');
            $q->execute([$crewId]);
            $out['log'] = (int)$q->fetchColumn();
        }
        if (finance_column_exists('invoices', 'crew_id')) {
            $q = $db->prepare('SELECT COUNT(*) FROM invoices WHERE crew_id = ?');
            $q->execute([$crewId]);
            $out['invoices'] = (int)$q->fetchColumn();
        }
    } catch (PDOException $e) {
        $out['reachable'] = false;
        return $out;
    }

    $out['total'] = $out['terms'] + $out['invoices'] + $out['log'];
    return $out;
}

/**
 * Join one or more crew records into a keeper, then delete them.
 *
 * Returns a sentence saying what moved. Throws, having changed nothing, if the
 * merge is not safe to make.
 *
 * @param int   $keepId  the record that survives
 * @param int[] $loseIds the records folded into it
 */
function merge_crew(PDO $pdo, int $keepId, array $loseIds, string $who = ''): string {
    $loseIds = array_values(array_unique(array_filter(array_map('intval', $loseIds),
        fn($i) => $i > 0 && $i !== $keepId)));
    if ($keepId <= 0 || !$loseIds) {
        throw new RuntimeException('Pick the record to keep and at least one to fold into it.');
    }

    // ---- everybody involved must exist and be a real person -----------------
    $in = implode(',', array_merge([$keepId], $loseIds));
    $rows = $pdo->query("SELECT id, name, is_placeholder FROM crew WHERE id IN ($in)")->fetchAll();
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    foreach (array_merge([$keepId], $loseIds) as $id) {
        if (!isset($byId[$id])) throw new RuntimeException('One of those records no longer exists.');
        if ((int)$byId[$id]['is_placeholder'] === 1) {
            // A placeholder IS a slot. Merging one would delete a position out
            // of somebody's plan, which is not what this screen is for.
            throw new RuntimeException(
                'One of those is an empty slot, not a person, so nothing was merged. '
              . 'Remove the slot on the Crew screen if it is not needed.');
        }
    }

    // ---- the money side, asked BEFORE anything is written -------------------
    $keepMoney = crew_money_footprint($keepId);
    if (!$keepMoney['reachable']) {
        throw new RuntimeException(
            'The finance database is configured but would not open, so there is no way to tell '
          . 'what these records are worth on that side. Nothing was merged, which is the safe '
          . 'answer to a question that could not be asked.');
    }

    $moveTerms = [];       // loser ids whose day rate should move to the keeper
    foreach ($loseIds as $id) {
        $m = crew_money_footprint($id);
        if (!$m['reachable']) {
            throw new RuntimeException('The finance database would not answer for one of those '
                                     . 'records, so nothing was merged.');
        }
        if ($m['terms'] > 0) {
            if ($keepMoney['terms'] > 0 || $moveTerms) {
                // ⚠ REFUSED, NOT GUESSED. Two rates for one person is a real
                // disagreement about what they cost, and resolving it by
                // picking whichever row this loop met first would silently
                // reprice somebody's work.
                throw new RuntimeException(sprintf(
                    'Both records carry a day rate, so nothing was merged. Open %s under Finance '
                  . 'management, decide which rate is right and clear the other, then merge.',
                    $byId[$keepId]['name']));
            }
            $moveTerms[] = $id;
        }
    }

    // ---- FINANCE FIRST. See the header for why the order is not arbitrary. --
    $moved = ['terms' => 0, 'invoices' => 0, 'log' => 0];
    if (finance_configured() && ($db = finance_db())) {
        try {
            if ($moveTerms && finance_column_exists('crew_terms', 'crew_id')) {
                $up = $db->prepare('UPDATE crew_terms SET crew_id = ? WHERE crew_id = ?');
                foreach ($moveTerms as $id) { $up->execute([$keepId, $id]); $moved['terms']++; }
            }
            foreach (['crew_terms_log' => 'log', 'invoices' => 'invoices'] as $table => $key) {
                if (!finance_column_exists($table, 'crew_id')) continue;
                $up = $db->prepare('UPDATE ' . $table . ' SET crew_id = ? WHERE crew_id = ?');
                foreach ($loseIds as $id) { $up->execute([$keepId, $id]); $moved[$key] += $up->rowCount(); }
            }
        } catch (PDOException $e) {
            throw new RuntimeException('The finance side of the merge failed, so nothing was '
                                     . 'removed on this side either: ' . $e->getMessage());
        }
    }

    // ---- then production, in one transaction --------------------------------
    $pdo->beginTransaction();
    try {
        // Plain re-points: nothing here has a unique key involving crew_id.
        $plain = [
            ['crew_lanes',   'crew_id'],
            ['schedule',     'crew_id'],
            ['tickets',      'assigned_crew_id'],
            ['time_logs',    'crew_id'],
            ['hires',        'crew_id'],
            ['users',        'crew_id'],
            ['crew_aliases', 'crew_id'],
            ['progress_log', 'crew_id'],
        ];
        foreach ($plain as [$table, $col]) {
            try {
                $up = $pdo->prepare("UPDATE $table SET $col = ? WHERE $col = ?");
                foreach ($loseIds as $id) $up->execute([$keepId, $id]);
            } catch (PDOException $e) {
                // A table whose migration has not been applied on this install.
                // Skipping is right; failing would block a merge over a feature
                // the studio is not using.
            }
        }

        // ⚠ COMPOSITE KEYS. progress_crew is PRIMARY KEY (progress_id, crew_id),
        // crew_projects is (crew_id, project_id), crew_skills is (crew_id,
        // step_id) - so if the keeper ALREADY has that row, re-pointing would
        // collide. UPDATE IGNORE moves the ones that fit; the DELETE clears the
        // ones that collided, which are rows the keeper already has.
        foreach (['progress_crew', 'crew_projects', 'crew_skills'] as $table) {
            try {
                $up = $pdo->prepare("UPDATE IGNORE $table SET crew_id = ? WHERE crew_id = ?");
                $del = $pdo->prepare("DELETE FROM $table WHERE crew_id = ?");
                foreach ($loseIds as $id) { $up->execute([$keepId, $id]); $del->execute([$id]); }
            } catch (PDOException $e) {
                // as above
            }
        }

        // The old records' names become aliases of the keeper, so an import or
        // an old export that still calls them that resolves to the right person
        // instead of creating a fourth copy.
        $alias = $pdo->prepare('INSERT IGNORE INTO crew_aliases (alias_name, crew_id) VALUES (?, ?)');
        foreach ($loseIds as $id) {
            try { $alias->execute([$byId[$id]['name'], $keepId]); } catch (PDOException $e) {}
        }

        $pdo->prepare('DELETE FROM crew WHERE id IN (' . implode(',', $loseIds) . ')')->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('Nothing was merged on this side: ' . $e->getMessage()
            . ($moved['terms'] || $moved['invoices'] || $moved['log']
               ? ' The finance records had already moved to the record you kept, which is '
               . 'harmless and visible: they now belong to the person you meant.' : ''));
    }

    $bits = [];
    if ($moved['terms'])    $bits[] = 'a day rate';
    if ($moved['invoices']) $bits[] = $moved['invoices'] . ' invoice'
                                    . ($moved['invoices'] === 1 ? '' : 's');
    return sprintf('Merged %d record%s into "%s". Everything they held, slots, scheduled work, '
                 . 'tickets, logged time and hiring records, is now theirs%s. The old names are '
                 . 'kept as aliases, so an import that still uses them finds the right person.',
                   count($loseIds), count($loseIds) === 1 ? '' : 's', $byId[$keepId]['name'],
                   $bits ? ', including ' . implode(' and ', $bits) : '');
}
