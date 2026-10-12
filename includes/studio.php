<?php
// The Studio — one row in `projects` that is not a show.
//
// David, 2026-10-12, asked for the per-project task board on Studio Tasks, with
// create buttons, and for somewhere to file the cards that belong to no show.
// Migration 045 makes that a real project row; this file is everything that
// keeps it from behaving like one.
//
// ⚠ READ docs/DECISIONS.md 2026-10-07 FIRST. It rejected exactly this and was
// right at the time: Studio Tasks had no columns, so it needed no statuses, so a
// pseudo-project bought nothing. David has since asked for the columns. The
// entry named the shape of the requirement as the deciding factor and the
// requirement changed — but the COST it predicted has not, so it is paid here,
// in one place, deliberately.
//
// ⚠ WHY A PROJECT ROW AND NOT A SECOND KIND OF THING. A board needs a status
// vocabulary, a per-board sequence for "STUDIO-4", a create flow, an archive and
// a place in My tasks. `projects` already has every one of those, and tasks.php
// already draws the board. Building a parallel set for project_id IS NULL would
// be two implementations of one idea, which is the mistake this codebase keeps
// naming (STFC's shot_characters/shot_environments, the two-vocabulary trap in
// migration 017).
//
// ⚠ IT DOES NOT REPLACE A NULL PROJECT. Capture-first still works exactly as
// migration 041 built it: a card can arrive belonging to nothing, and that is
// still right when nobody knows where it goes. The Studio is a destination
// somebody CHOOSES. Nothing files a card here automatically.

require_once __DIR__ . '/functions.php';

/**
 * Has migration 045 run?
 *
 * Every function below degrades to "there is no studio" when it has not, so a
 * database one migration behind shows the screens it showed yesterday rather
 * than a fatal on a column that is not there. Same contract as
 * time_logs_available() and the rest.
 */
function studio_available(PDO $pdo): bool {
    return column_exists($pdo, 'projects', 'is_studio');
}

/**
 * The Studio project row, or null.
 *
 * Statically cached: half a dozen screens ask per request, and this is a lookup
 * on a UNIQUE column whose answer cannot change mid-page.
 */
function studio_project(PDO $pdo): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    if (!studio_available($pdo)) return $cached = null;

    // By the FLAG, not by the code. Somebody renaming 'STUDIO' on the Basics
    // screen would otherwise quietly detach every studio card from its own
    // board, and nothing would say so.
    $row = $pdo->query('SELECT * FROM projects WHERE is_studio = 1 ORDER BY id LIMIT 1')->fetch();
    return $cached = ($row ?: null);
}

/** The Studio's id, or 0 when there isn't one yet. */
function studio_project_id(PDO $pdo): int {
    $p = studio_project($pdo);
    return $p ? (int)$p['id'] : 0;
}

/** Is this project the Studio? Takes a row or an id. */
function is_studio_project($project, ?PDO $pdo = null): bool {
    if (is_array($project)) return !empty($project['is_studio']);
    if ($pdo === null) return false;
    return (int)$project > 0 && (int)$project === studio_project_id($pdo);
}

/**
 * A SQL fragment that keeps the Studio out of a list of projects.
 *
 * ⚠ THIS IS THE COST THE 2026-10-07 DECISION PREDICTED, AND THE LIST OF PLACES
 * THAT PAY IT IS WRITTEN DOWN HERE SO IT IS NOT REDISCOVERED ONE BUG AT A TIME.
 * Every query that LISTS or AGGREGATES projects must apply it:
 *
 *   index.php              the projects page, and its archived count
 *   finance.php            money across shows
 *   includes/project_nav.php   the project switcher
 *   includes/snapshots.php     the nightly progress snapshot
 *   includes/bookings.php      who is booked on what
 *   includes/history.php       historic quotas and fees
 *   includes/standards.php     workflow standards per show
 *   includes/project_types.php by type, and the unused-type check
 *
 * Two pickers DELIBERATELY INCLUDE it, because they are how a card reaches the
 * Studio in the first place:
 *
 *   ticket.php             "move to project" on a card
 *   transcript.php         filing a meeting's cards
 *
 * A single-project lookup (`WHERE id = ?`) needs nothing: you only arrive at the
 * Studio's id by choosing it.
 *
 * @param string $alias table alias used in the query, '' for none
 * @return string ' AND x.is_studio = 0', or '' when the column is not there yet
 */
function studio_exclude_sql(PDO $pdo, string $alias = 'p'): string {
    if (!studio_available($pdo)) return '';
    $a = $alias !== '' ? preg_replace('/[^A-Za-z0-9_]/', '', $alias) . '.' : '';
    return ' AND ' . $a . 'is_studio = 0';
}

/**
 * The same filter for a query that has no WHERE of its own yet.
 *
 * Kept separate rather than making the caller guess: concatenating ' AND ...'
 * onto a query with no WHERE is a syntax error that only fires on the one screen
 * nobody opened while testing, and concatenating ' WHERE ...' onto one that has
 * a WHERE is the same bug the other way up.
 *
 * @return string ' WHERE x.is_studio = 0', or '' when the column is not there
 */
function studio_where_sql(PDO $pdo, string $alias = 'p'): string {
    $frag = studio_exclude_sql($pdo, $alias);
    return $frag === '' ? '' : ' WHERE' . substr($frag, 4);
}

/**
 * How many cards are sitting with no project at all.
 *
 * Drawn on Studio Tasks so the offer to file them is only made when there is
 * something to file — an action that always claims "move 0 cards" teaches people
 * to ignore it.
 */
function unfiled_ticket_count(PDO $pdo): int {
    if (!column_exists($pdo, 'tickets', 'id')) return 0;
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM tickets WHERE project_id IS NULL AND archived = 0'
    )->fetchColumn();
}

/**
 * Move every unfiled card into the Studio.
 *
 * David, 2026-10-12: "how can I adjust the project of the current tasks without
 * no project to be the global/studio wide task?"
 *
 * ⚠ EACH CARD GOES THROUGH move_ticket_to_project(), ONE AT A TIME, rather than
 * one UPDATE over the lot. That function is what gives an unfiled card a `seq`
 * (so it stops reading as "#21" and becomes "STUDIO-4") and drops it into the
 * target's first board column (so it is not invisible on arrival). A bulk UPDATE
 * of project_id alone would move the rows and leave every one of them with
 * seq 0 and no status — on the board, in the wrong column, with the wrong
 * reference, and nothing to say why.
 *
 * ⚠ NO TRANSACTION HERE, and that is deliberate rather than an oversight.
 * move_ticket_to_project() opens its own, and MySQL cannot nest them — the inner
 * beginTransaction() throws, so wrapping the loop would have broken the one
 * action this function exists for. (Caught by tools/check_php_blocks.py, which
 * tests for exactly this, before it ever ran.)
 *
 * Nothing is lost by leaving it out, because the operation is IDEMPOTENT AND
 * RESUMABLE: each card's move is atomic on its own, and a card that has already
 * moved is no longer unfiled, so it is not picked up again. If the batch fails
 * halfway, the cards that moved are correctly filed and the rest are still
 * sitting there waiting, with the button still offering to move them.
 *
 * @return array [moved count, message]
 */
function file_unfiled_to_studio(PDO $pdo): array {
    // Required HERE rather than at the top of the file: nine screens include
    // this file only for studio_exclude_sql(), and none of them should drag the
    // whole ticket layer in to get a WHERE clause.
    require_once __DIR__ . '/tickets.php';

    $studioId = studio_project_id($pdo);
    if ($studioId <= 0) {
        throw new RuntimeException('There is no Studio yet — migration 045 has not been applied.');
    }

    $ids = $pdo->query(
        'SELECT id FROM tickets WHERE project_id IS NULL AND archived = 0 ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return [0, 'There are no unfiled cards to move.'];

    // Counted as they go, so a failure part way through can say how far it got
    // rather than claiming none of it happened.
    $n = 0;
    foreach ($ids as $id) {
        try {
            move_ticket_to_project($pdo, (int)$id, $studioId);
            $n++;
        } catch (Throwable $e) {
            throw new RuntimeException(
                $n . ' card' . ($n === 1 ? '' : 's') . ' moved before this stopped it: '
                . $e->getMessage() . ' The rest are still unfiled, so you can run it again.'
            );
        }
    }

    return [$n, $n . ' card' . ($n === 1 ? '' : 's') . ' moved to the Studio. '
             . ($n === 1 ? 'It now has' : 'They now have') . ' a reference and a place on the board.'];
}
