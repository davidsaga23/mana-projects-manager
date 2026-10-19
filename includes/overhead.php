<?php
// Overhead time categories — the standing buckets people log their day against
// when the work is not a shot, an asset or a card: Meetings, Desk time and the
// rest. Migration 052.
//
// David, 2026-10-19, with a list per permission role. The point is leadership
// (and everyone else) logging where the week actually went — meetings, planning,
// recruiting — so it adds up beside the hours that land on shows.
//
// ⚠ THIS FILE IS THE ONE LIST OF WHAT EXISTS AND WHO MAY USE IT. The categories
// and the roles each is offered to are kept here, in code, the same way
// SUPERVISOR_SCRIPTS and pomodoro_minutes() keep a rule-set in one readable
// place rather than scattering it. Adding a category, or offering an existing
// one to another role, is an edit to OVERHEAD_CATEGORIES and nothing else — the
// card for a new category is created on first use (overhead_cards()), so there
// is no second migration to run.
//
// ⚠ A CATEGORY IS A HIDDEN CARD UNDER THE STUDIO. See migration 052 for why: the
// timer and the time log both cost their minutes to a ticket or a progress row,
// and an overhead category reuses the ticket the timer already understands. The
// card never shows on a board because it carries overhead_key (the board and
// Studio Tasks exclude it, through overhead_exclude_sql()).

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studio.php';

/**
 * Every overhead category, in display order, with the roles that may log against
 * it. The order is David's own Admin list (the superset); each lesser role shows
 * the same categories in the same order, minus the ones not offered to it.
 *
 * ⚠ ROLES ARE THE STORED VALUES, not the labels. 'editor' is the role the app
 * calls "Production" on screen since 2026-10-19 — the rename is a label only
 * (role_in_words(), users.php), so the value here stays 'editor'.
 *
 * ⚠ SUPERVISOR'S "Planning" IS THIS ONE "Planning & scheduling". David wrote the
 * supervisor line shorter; it is the same bucket, kept as one so "where did the
 * week go" does not split a supervisor's planning away from an admin's. If it
 * should ever be two, that is a new key here and nothing else changes.
 */
const OVERHEAD_CATEGORIES = [
    'meetings'     => ['label' => 'Meetings',             'roles' => ['admin', 'editor', 'supervisor', 'artist']],
    'desk_time'    => ['label' => 'Desk time',            'roles' => ['admin', 'editor', 'supervisor', 'artist']],
    'client_comms' => ['label' => 'Client & comms',       'roles' => ['admin', 'editor', 'supervisor']],
    'planning'     => ['label' => 'Planning & scheduling','roles' => ['admin', 'editor', 'supervisor']],
    'recruiting'   => ['label' => 'Recruiting',           'roles' => ['admin', 'supervisor']],
    'biz_dev'      => ['label' => 'Business development',  'roles' => ['admin']],
    'admin'        => ['label' => 'Admin',                 'roles' => ['admin']],
    'reviews'      => ['label' => 'Reviews',               'roles' => ['admin', 'supervisor', 'artist']],
];

/**
 * Is the feature usable at all? The column (migration 052) AND the Studio
 * (migration 045) both have to be there, because a category card has to cost its
 * hours to a real project. Degrades to "no overhead" otherwise, exactly like
 * timers_available() and studio_available() — a database a migration behind shows
 * yesterday's My tasks, not a fatal.
 */
function overhead_available(PDO $pdo): bool {
    return column_exists($pdo, 'tickets', 'overhead_key') && studio_project_id($pdo) > 0;
}

/** The label for a category key, or '' if it is not one we know. */
function overhead_label(string $key): string {
    return OVERHEAD_CATEGORIES[$key]['label'] ?? '';
}

/** Is this category offered to this role? The server's copy of the on-screen filter. */
function overhead_key_for_role(string $key, string $role): bool {
    $cat = OVERHEAD_CATEGORIES[$key] ?? null;
    return $cat !== null && in_array($role, $cat['roles'], true);
}

/**
 * The categories this role may log against, in display order, as key => label.
 * The one list the panel on My tasks draws and the POST handler validates
 * against, so what a role is shown and what it is allowed to save can never
 * drift apart.
 */
function overhead_categories_for_role(string $role): array {
    $out = [];
    foreach (OVERHEAD_CATEGORIES as $key => $cat) {
        if (in_array($role, $cat['roles'], true)) $out[$key] = $cat['label'];
    }
    return $out;
}

/**
 * The hidden Studio card for every category, as key => ticket row, creating any
 * that do not exist yet.
 *
 * ⚠ LAZY, ON LOAD, rather than seeded by the migration — the same pattern as the
 * nightly snapshot (maybe_take_snapshots) and the board's archive sweep. It means
 * the migration never has to know whether the Studio existed when it ran, and a
 * category added to OVERHEAD_CATEGORIES later simply gets its card the next time
 * My tasks opens.
 *
 * ⚠ THE SEQ RACE IS HANDLED THE WAY create_ticket() HANDLES IT. seq is per
 * project and UNIQUE(project_id, seq); two page loads creating the same missing
 * card at once would collide — one on that key, one on UNIQUE(overhead_key) — so
 * the INSERT is tried and, on any failure, the card is simply re-read. Nobody
 * sees an error for opening a page.
 *
 * Returns only the categories that exist in code; a stray card whose key is no
 * longer a category is left alone and ignored.
 */
function overhead_cards(PDO $pdo): array {
    if (!overhead_available($pdo)) return [];
    $studioId = studio_project_id($pdo);

    $existing = [];
    foreach ($pdo->query(
        'SELECT * FROM tickets WHERE overhead_key IS NOT NULL'
    ) as $row) {
        $existing[(string)$row['overhead_key']] = $row;
    }

    foreach (OVERHEAD_CATEGORIES as $key => $cat) {
        if (isset($existing[$key])) continue;
        try {
            // seq inside the same read-write so a collision fails loudly rather
            // than two cards quietly sharing a number. 'task', no owner: a
            // category belongs to everyone and to no one.
            $pdo->beginTransaction();
            $n = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM tickets WHERE project_id = ?');
            $n->execute([$studioId]);
            $seq = (int)$n->fetchColumn();
            $pdo->prepare(
                "INSERT INTO tickets (project_id, seq, type, title, priority, overhead_key, created_at)
                 VALUES (?, ?, 'task', ?, 'medium', ?, NOW())"
            )->execute([$studioId, $seq, $cat['label'], $key]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Another load won the race, or something else is wrong — either way
            // the card is read below rather than retried, so a page load never
            // turns into an error over seeding a category.
        }
    }

    // Re-read once so the map reflects whatever is actually there now, including
    // cards another request created while this one ran.
    $cards = [];
    foreach ($pdo->query(
        'SELECT * FROM tickets WHERE overhead_key IS NOT NULL'
    ) as $row) {
        $key = (string)$row['overhead_key'];
        if (isset(OVERHEAD_CATEGORIES[$key])) $cards[$key] = $row;
    }
    return $cards;
}

/**
 * A SQL fragment that keeps overhead cards out of a list of tickets.
 *
 * ⚠ THE PLACES THAT MUST APPLY IT, written here so they are not rediscovered one
 * bug at a time (the same discipline studio_exclude_sql() keeps):
 *
 *   includes/tickets.php  board_tickets()  — the Studio's own board
 *   alltasks.php          the cross-project Studio Tasks list
 *
 * tickets_for_crew() (My tasks' own card list) needs nothing: an overhead card
 * has no assignee, so it is never anyone's to show. A single-ticket load by id
 * needs nothing either.
 *
 * @param string $alias table alias used in the query, '' for none
 * @return string ' AND t.overhead_key IS NULL', or '' when the column is absent
 */
function overhead_exclude_sql(PDO $pdo, string $alias = 't'): string {
    if (!column_exists($pdo, 'tickets', 'overhead_key')) return '';
    $a = $alias !== '' ? preg_replace('/[^A-Za-z0-9_]/', '', $alias) . '.' : '';
    return ' AND ' . $a . 'overhead_key IS NULL';
}
