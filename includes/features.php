<?php
// ACCESS SWITCHES: what each role, and each person, can open (migration 077).
//
// David, 2026-11-04: "we should have a kind of feature toggle per user, like
// Reviews are active for supervisors and production, spell is not active for
// clients ... I think the feature toggle should live in the users tab." Settled
// on 2026-11-05 as ROLE DEFAULTS PLUS PER-PERSON CHANGES, with the list below and
// his own answers for Bookings, Historic quotas, Users, Bugs, Tour and View as.
//
// ⚠ ONE QUESTION, ASKED EVERYWHERE: feature_on('reviews'). The top bar asks it to
// draw a link, the project tabs ask it to draw a tab, and require_login() asks it
// before a page opens or a save is taken (feature_gate_request() below). Before
// this, hiding a link and closing the page were two separate decisions, and they
// disagreed: an artist could type dashboard.php?project=3 and read it.
//
// THE THREE LAYERS, in the order feature_on() reads them:
//   1. the answer written in FEATURES below (the studio's starting point);
//   2. role_features: an admin (or Production, for the roles below it) changed
//      that answer for every account of one role on Users → Access by role;
//   3. user_features: one person set On or Off against their role's answer.
// Only CHANGES are stored, so a feature added later needs no migration.
//
// ⚠ WHAT A SWITCH CAN NEVER DO, whatever the tables say:
//   - turn anything off for an ADMIN. Admins keep everything, so nobody can lock
//     the studio out of its own Users screen (David's must-have).
//   - reach MONEY. Finance management, Historic fees, rates and contracts are not
//     in this list at all; they stay behind can_see_money() = is_admin(), per the
//     money rules in CLAUDE.md. Email and Migrations stay admin-only the same way.
//   - give a role a power its screens were not built for. A 'locked' role in an
//     entry below always gets the answer written beside it, and the Users grid
//     draws it as fixed. "Bookings: edit" for an artist would need the Bookings
//     screen to learn to draw its controls for them first; until then the switch
//     is not offered rather than offered and broken.
//
// Keyed on user_role() and effective_user_id(), both of which follow "view as",
// so an admin or Production viewing as a person sees exactly that person's
// switches without this file knowing about previews.

/** The roles a switch can be set for. Admins are never switched. */
const FEATURE_ROLES = ['editor', 'supervisor', 'artist', 'client'];

/**
 * Every switch. Order is the order of the Users grid.
 *
 *   label   what the grid says
 *   group   the grid's section
 *   parent  drawn indented under this feature (the "see / edit" pairs)
 *   note    the small line under the label
 *   on      the starting answer for each role (1 / 0)
 *   locked  roles that always get their 'on' answer and cannot be switched
 *
 * ⚠ 'client' IS HERE BEFORE THE ROLE EXISTS. David, 2026-11-04: "spell is not
 * active for clients (we don't have those users yet)". The column lets him decide
 * what a client will get now; no login can hold the role until client logins are
 * built (they need a way to be tied to their own shows).
 */
const FEATURES = [
    // ---- every day -------------------------------------------------------
    'home'          => ['label' => 'Home', 'group' => 'Every day', 'note' => 'needs the login linked to a person',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'spell'         => ['label' => 'Spell: see the board', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'spell_comment' => ['label' => 'Comment on a task', 'group' => 'Every day', 'parent' => 'spell',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['client']],
    'spell_version' => ['label' => 'Put up a version', 'group' => 'Every day', 'parent' => 'spell',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['client']],
    // Production sets every status as part of editing, so this switch is only
    // for the people who may set their OWN: it would otherwise also stop
    // Production moving anybody's card.
    'own_status'    => ['label' => 'Set the status of my own task', 'group' => 'Every day', 'parent' => 'spell',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['editor', 'client']],
    'spell_edit'    => ['label' => 'Edit tasks: assign, dates, links', 'group' => 'Every day', 'parent' => 'spell',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'mytasks'       => ['label' => 'My tasks', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'time_log'      => ['label' => 'Timer and time logging', 'group' => 'Every day', 'parent' => 'mytasks',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['client']],
    // Artists are locked off: approving needs the second lock in review_apply(),
    // which knows supervisors and Production, not artists.
    'reviews'       => ['label' => 'Reviews', 'group' => 'Every day', 'note' => 'approve or retake work',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0], 'locked' => ['artist', 'client']],
    'bell'          => ['label' => 'Notifications bell', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    // David, 2026-11-11: the 5 pm "you haven't logged today" pop-up, and an
    // admin's instant one (includes/log_reminders.php). Anyone on a show gets
    // it; this is how one role or one person is let off.
    'log_reminders' => ['label' => 'Reminders to log time', 'group' => 'Every day', 'note' => 'the 5 pm pop-up, and one sent from Log reminders',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['client']],
    'bug_report'    => ['label' => 'Report a bug (the bug button)', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 1]],
    'bugs_list'     => ['label' => 'Bugs list', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'tour'          => ['label' => 'Tour', 'group' => 'Every day',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 1]],
    'own_statement' => ['label' => 'My own artist statement', 'group' => 'Every day', 'note' => 'what they should invoice; their own only',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0], 'locked' => ['client']],

    // ---- the whole studio --------------------------------------------------
    'studio_tasks'  => ['label' => 'Studio Tasks: see', 'group' => 'The whole studio',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'studio_tasks_edit' => ['label' => 'Create and edit cards', 'group' => 'The whole studio', 'parent' => 'studio_tasks',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'transcript'    => ['label' => 'Tasks from a transcript (AI)', 'group' => 'The whole studio', 'parent' => 'studio_tasks',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'artists'       => ['label' => 'Artists: see the directory', 'group' => 'The whole studio',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'artists_contacts' => ['label' => 'See contact details', 'group' => 'The whole studio', 'parent' => 'artists', 'note' => 'email and phone',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'artists_edit'  => ['label' => 'Add and edit', 'group' => 'The whole studio', 'parent' => 'artists',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    // David, 2026-11-05: Bookings for Admin, Production and Supervisors.
    'bookings'      => ['label' => 'Bookings: see', 'group' => 'The whole studio',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0]],
    'bookings_edit' => ['label' => 'Edit', 'group' => 'The whole studio', 'parent' => 'bookings',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    // David, 2026-11-12: the days-off calendar. Everybody sees it (an artist
    // only their own row, days_off.php decides); only Production enters days.
    'days_off'      => ['label' => 'Days off calendar: see', 'group' => 'The whole studio', 'note' => 'an artist sees only their own',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'days_off_edit' => ['label' => 'Enter and remove days off', 'group' => 'The whole studio', 'parent' => 'days_off',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    // David, 2026-11-05: Historic quotas for Admin and Production.
    'quotas'        => ['label' => 'Historic quotas', 'group' => 'The whole studio',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'invite'        => ['label' => 'Person page: create a login, resend the link', 'group' => 'The whole studio',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],

    // ---- inside a project (only the shows they are on) ---------------------
    'overview'      => ['label' => 'Overview', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 1]],
    'brief'         => ['label' => 'Brief', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 1]],
    'shots'         => ['label' => 'Shots: see', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'shots_edit'    => ['label' => 'Edit', 'group' => 'Inside a project', 'parent' => 'shots',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0], 'locked' => ['artist', 'client']],
    'production'    => ['label' => 'Production tracking', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 1]],
    'tasks'         => ['label' => 'Tasks', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 1, 'client' => 0]],
    'staffing'      => ['label' => 'Staffing: see', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0]],
    'staffing_hire' => ['label' => 'Press Hire', 'group' => 'Inside a project', 'parent' => 'staffing',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0], 'locked' => ['artist', 'client']],
    'staffing_edit' => ['label' => 'Edit', 'group' => 'Inside a project', 'parent' => 'staffing',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'timeline'      => ['label' => 'Timeline: see', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 1, 'artist' => 0, 'client' => 0]],
    'timeline_edit' => ['label' => 'Edit', 'group' => 'Inside a project', 'parent' => 'timeline',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'dashboard'     => ['label' => 'Dashboard', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'schedule'      => ['label' => 'Schedule', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'team'          => ['label' => 'Team performance', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'setup'         => ['label' => 'Setup, Import, Statuses, Names', 'group' => 'Inside a project',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],
    'export'        => ['label' => 'Export', 'group' => 'Inside a project', 'note' => 'a download of the whole show',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0]],

    // ---- running the studio --------------------------------------------------
    // David, 2026-11-05: Users and View as for Production too. What Production
    // may change on Users is narrower than what an admin may: see users_may_manage()
    // in users.php and feature_may_grant() below.
    'users'         => ['label' => 'Users', 'group' => 'Running the studio', 'note' => 'Production: Supervisors, Artists and Clients only',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    // Sending an instant "log your time" pop-up (David, 2026-11-11: "a button
    // for admins"). Production too by default, since they chase the numbers.
    'log_reminder_send' => ['label' => 'Send a reminder to log time', 'group' => 'Running the studio', 'note' => 'to everybody or chosen people, now',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
    'view_as'       => ['label' => 'View as', 'group' => 'Running the studio', 'note' => 'only people below your own role, never money',
                        'on' => ['editor' => 1, 'supervisor' => 0, 'artist' => 0, 'client' => 0], 'locked' => ['supervisor', 'artist', 'client']],
];

/**
 * Which screen belongs to which switch. The page is refused while the switch is
 * off, for a GET as much as for a save. A script not listed here is not gated by
 * a switch (it keeps whatever checks it already had).
 *
 * ⚠ THIS IS THE LIST THE DOORS READ. A new screen that belongs to a feature is
 * added here as well as to the nav; a screen left out is open, as before.
 */
const FEATURE_SCRIPTS = [
    'home.php'            => 'home',
    'spell.php'           => 'spell',
    'board_task.php'      => 'spell',
    'board_api.php'       => 'spell',
    'board_comment.php'   => 'spell',
    'board_version.php'   => 'spell',
    'mytasks.php'         => 'mytasks',
    'review.php'          => 'reviews',
    'alltasks.php'        => 'studio_tasks',
    'transcript.php'      => 'transcript',
    'artists.php'         => 'artists',
    'artists_api.php'     => 'artists',
    'export_artists.php'  => 'artists',
    'bookings.php'        => 'bookings',
    'days_off.php'        => 'days_off',
    'history_quotas.php'  => 'quotas',
    'users.php'           => 'users',
    'log_reminders.php'   => 'log_reminder_send',
    // Admins read anybody's; anybody else their own (statement.php decides whose).
    'statement.php'       => 'own_statement',
    // project tabs
    'project.php'         => 'overview',
    'brief.php'           => 'brief',
    'shots.php'           => 'shots',
    'shots_api.php'       => 'shots',
    'export_breakdown.php'=> 'shots',
    'production.php'      => 'production',
    'production_api.php'  => 'production',
    'item.php'            => 'production',
    'tasks.php'           => 'tasks',
    'tasks_api.php'       => 'tasks',
    'ticket.php'          => 'tasks',
    'crew.php'            => 'staffing',
    'hiring.php'          => 'staffing',
    'export_staffing.php' => 'staffing',
    'timeline.php'        => 'timeline',
    'timeline_gan.php'    => 'timeline',
    'timeline_import.php' => 'timeline',
    'dashboard.php'       => 'dashboard',
    'step.php'            => 'dashboard',
    'schedule.php'        => 'schedule',
    'schedule_api.php'    => 'schedule',
    'team.php'            => 'team',
    'wizard.php'          => 'setup',
    'import.php'          => 'setup',
    'statuses_edit.php'   => 'setup',
    'aliases.php'         => 'setup',
    'standards.php'       => 'setup',
    'export.php'          => 'export',
    'export_workflow.php' => 'export',
];

/**
 * Which SAVE needs which switch, beyond the screen's own. Read top to bottom per
 * script; the first line that matches decides:
 *   ['field', 'name', 'feature']    the POST carries that field
 *   ['action', '/regex/', 'feature'] the posted `action` matches
 *   ['*', '', 'feature']            any other POST to the script
 *
 * The who-may-write allow-lists in auth.php (artist_may_post(), the supervisor
 * lists) stay exactly as they were; this only ever REFUSES on top of them.
 */
const FEATURE_POSTS = [
    'board_api.php'     => [['field', 'set_status', 'own_status'], ['*', '', 'spell_edit']],
    'board_comment.php' => [['*', '', 'spell_comment']],
    'board_version.php' => [['field', 'add_review', 'reviews'], ['*', '', 'spell_version']],
    'mytasks.php'       => [['field', 'set_status', 'own_status'], ['field', 'set_ticket_status', 'own_status'],
                            ['field', 'log_time', 'time_log'], ['field', 'timer', 'time_log'],
                            ['field', 'delete_log', 'time_log'], ['field', 'overhead_timer', 'time_log'],
                            ['field', 'overhead_log', 'time_log']],
    'bugs.php'          => [['field', 'report_bug', 'bug_report'], ['*', '', 'bugs_list']],
    'alltasks.php'      => [['*', '', 'studio_tasks_edit']],
    'artists.php'       => [['*', '', 'artists_edit']],
    'artists_api.php'   => [['*', '', 'artists_edit']],
    'bookings.php'      => [['*', '', 'bookings_edit']],
    'days_off.php'      => [['*', '', 'days_off_edit']],
    'shots.php'         => [['*', '', 'shots_edit']],
    'shots_api.php'     => [['*', '', 'shots_edit']],
    'crew.php'          => [['action', '/^hire:\d+$/', 'staffing_hire'], ['*', '', 'staffing_edit']],
    'hiring.php'        => [['*', '', 'staffing_edit']],
    'timeline.php'      => [['*', '', 'timeline_edit']],
    'timeline_import.php' => [['*', '', 'timeline_edit']],
    'person.php'        => [['field', 'create_login', 'invite'], ['field', 'resend_link', 'invite'],
                            ['field', 'copy_link', 'invite']],
];

/**
 * Bug reports go through bugs.php, but filing one must not need the Bugs LIST
 * (David, 2026-11-05: the list is for Admin and Production; the bug button stays
 * for everyone). So the screen switch for bugs.php is decided per request.
 */
function feature_for_script(string $script): ?string {
    if ($script === 'bugs.php') {
        return ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_bug'])) ? 'bug_report' : 'bugs_list';
    }
    return FEATURE_SCRIPTS[$script] ?? null;
}

/** The switch a POST to this script needs, or null when only the screen's own applies. */
function feature_for_post(string $script): ?string {
    foreach (FEATURE_POSTS[$script] ?? [] as [$kind, $match, $feature]) {
        if ($kind === '*') return $feature;
        if ($kind === 'field' && isset($_POST[$match])) return $feature;
        if ($kind === 'action' && preg_match($match, (string)($_POST['action'] ?? '')) === 1) return $feature;
    }
    return null;
}

/** Have the tables arrived (migration 077)? Asked once per request; never throws. */
function features_available(?PDO $pdo = null): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!($pdo instanceof PDO)) return $ok = false;
    try {
        $pdo->query('SELECT 1 FROM role_features LIMIT 1');
        $pdo->query('SELECT 1 FROM user_features LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Is this role locked to its written answer for this feature? */
function feature_locked(string $key, string $role): bool {
    return in_array($role, FEATURES[$key]['locked'] ?? [], true);
}

/**
 * What every account of one ROLE gets: the written answer, or the changed one.
 * An unknown feature is OFF, so a typo in a gate closes a door rather than
 * opening one.
 */
function feature_role_default(string $key, string $role): bool {
    if (!isset(FEATURES[$key])) return false;
    if ($role === 'admin') return true;
    $written = (bool)(FEATURES[$key]['on'][$role] ?? 0);
    if (feature_locked($key, $role)) return $written;
    $changed = feature_role_changes();
    return isset($changed[$role][$key]) ? $changed[$role][$key] : $written;
}

/** Every stored role change, as [role][feature] => bool. Read once per request. */
function feature_role_changes(): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    $rows = [];
    if (!features_available()) return $rows;
    try {
        foreach ($GLOBALS['pdo']->query('SELECT role, feature, enabled FROM role_features') as $r) {
            $rows[(string)$r['role']][(string)$r['feature']] = (int)$r['enabled'] === 1;
        }
    } catch (Throwable $e) {
        $rows = [];
    }
    return $rows;
}

/** One person's changes, as feature => bool. Cached per user id. */
function feature_user_changes(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $out = [];
    if ($userId > 0 && features_available()) {
        try {
            $q = $GLOBALS['pdo']->prepare('SELECT feature, enabled FROM user_features WHERE user_id = ?');
            $q->execute([$userId]);
            foreach ($q->fetchAll() as $r) $out[(string)$r['feature']] = (int)$r['enabled'] === 1;
        } catch (Throwable $e) {
            $out = [];
        }
    }
    return $cache[$userId] = $out;
}

/** The answer for a given account and role (used by feature_on() and by the Users screen). */
function feature_for_user(string $key, int $userId, string $role): bool {
    if (!isset(FEATURES[$key])) return false;
    if ($role === 'admin') return true;
    if (feature_locked($key, $role)) return (bool)(FEATURES[$key]['on'][$role] ?? 0);
    $mine = feature_user_changes($userId);
    if (isset($mine[$key])) return $mine[$key];
    return feature_role_default($key, $role);
}

/**
 * THE question. Is this feature on for the account the app is behaving as (the
 * previewed person during "view as")?
 */
function feature_on(string $key): bool {
    // A ROLE preview ("as an artist", nobody in particular) answers for the role
    // alone: the admin's own row must not leak into "what does an artist see".
    $uid = (in_preview() && !in_user_preview()) ? 0 : effective_user_id();
    return feature_for_user($key, $uid, user_role());
}

/**
 * The same question for the REAL login, ignoring any preview. Asked by whatever
 * decides whether you may START or STOP a preview, and by what you may hand to
 * others on Users — a preview must never lend or remove those.
 */
function real_feature_on(string $key): bool {
    return feature_for_user($key, (int)($_SESSION['user_id'] ?? 0), real_role());
}

/**
 * May the real login give this switch to somebody? An admin may give anything.
 * Production may give only what Production itself has (David, 2026-11-05), so the
 * Users screen can never be a way for Production to hand out more than it holds.
 */
function feature_may_grant(string $key): bool {
    if (real_role() === 'admin') return true;
    return real_role() === 'editor' && real_feature_on($key);
}

/**
 * Called by require_login() and require_login_json() on every request. Returns
 * null when the request may go on, or [kind, sentence] when it may not. kind is
 * 'screen' (the page itself is closed: send them somewhere they can be) or
 * 'save' (the page is fine, this one save is not: send them back to it).
 *
 * Three doors, in this order:
 *   1. the screen's switch (FEATURE_SCRIPTS);
 *   2. for a save, the save's own switch (FEATURE_POSTS);
 *   3. for a project screen, whether this account is on that show.
 *
 * ⚠ Never throws: a fault in here must not take every page down. On a fault the
 * request goes on and the screen's own checks still apply.
 */
function feature_gate_request(PDO $pdo): ?array {
    try {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $feature = feature_for_script($script);
        if ($feature !== null && !feature_on($feature)) {
            return ['screen', "That screen isn't open to your account. Ask an administrator if you need it."];
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $need = feature_for_post($script);
            if ($need !== null && !feature_on($need)) {
                return ['save', "That isn't switched on for your account, so it was not saved. Ask an administrator if you need it."];
            }
        }
        $pid = feature_request_project($pdo, $script);
        if ($pid > 0 && !may_see_project($pdo, $pid)) {
            return ['screen', 'That project is not one of yours. You only see the shows you are staffed on.'];
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/**
 * The project a project screen is about, or 0 when it is not a project screen
 * (or names none). Read the way each screen reads it: ?project=, else ?id= on the
 * screens where id IS the project, else the posted project; and from the card on
 * the two screens addressed by a card.
 *
 * Gap 2 of the 2026-11-04 inventory: only project.php used to check this, so an
 * artist could read any show's Brief, Shots or Export by changing the number.
 *
 * ⚠ THE STUDIO IS NOT A SHOW. Its cards are everybody's overhead (Meetings, Desk
 * time), reached from My tasks, and nobody is "staffed" on it, so it is never
 * refused here.
 */
function feature_request_project(PDO $pdo, string $script): int {
    // Spell's own files check the show themselves (board_projects()).
    $byProject = [
        'project.php', 'brief.php', 'dashboard.php', 'crew.php', 'hiring.php', 'schedule.php',
        'schedule_api.php', 'timeline.php', 'timeline_gan.php', 'timeline_import.php', 'shots.php',
        'shots_api.php', 'production.php', 'production_api.php', 'tasks.php', 'team.php', 'wizard.php',
        'import.php', 'statuses_edit.php', 'aliases.php', 'export.php', 'export_breakdown.php',
        'export_staffing.php', 'export_workflow.php', 'step.php', 'item.php', 'standards.php',
        'transcript.php',
    ];
    // Where ?id= is the PROJECT (each of these reads `project ?? id`).
    $idIsProject = [
        'project.php', 'brief.php', 'dashboard.php', 'shots.php', 'production.php', 'tasks.php',
        'team.php', 'export_breakdown.php', 'export_staffing.php', 'transcript.php',
    ];
    $pid = 0;
    if (in_array($script, $byProject, true)) {
        $pid = (int)($_GET['project'] ?? 0);
        if ($pid <= 0 && in_array($script, $idIsProject, true)) $pid = (int)($_GET['id'] ?? 0);
        if ($pid <= 0) $pid = (int)($_POST['project'] ?? $_POST['project_id'] ?? 0);
    } elseif ($script === 'ticket.php' || $script === 'tasks_api.php') {
        $tid = $script === 'ticket.php' ? (int)($_GET['id'] ?? $_POST['id'] ?? 0) : (int)($_POST['id'] ?? 0);
        if ($tid > 0) {
            $q = $pdo->prepare('SELECT project_id, assigned_crew_id FROM tickets WHERE id = ?');
            $q->execute([$tid]);
            $t = $q->fetch() ?: [];
            $pid = (int)($t['project_id'] ?? 0);
            // A card ASSIGNED to you is yours to open, even on a show you are not
            // staffed on: My tasks lists it, and a link there that refuses would
            // be worse than the gap this closes.
            $me = effective_crew_id($pdo);
            if ($me && (int)($t['assigned_crew_id'] ?? 0) === $me) return 0;
        }
    }
    if ($pid > 0 && function_exists('studio_project_id') && $pid === studio_project_id($pdo)) return 0;
    return $pid;
}

/** Write one line to the access log. Best-effort: a missing table never stops the change itself. */
function access_log(PDO $pdo, string $what, ?string $old, ?string $new, ?int $targetUser = null,
                    ?string $targetRole = null, ?string $reason = null): void {
    if (!features_available($pdo)) return;
    try {
        $pdo->prepare(
            'INSERT INTO access_log (actor_user_id, target_user_id, target_role, what, old_value, new_value, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)($_SESSION['user_id'] ?? 0) ?: null, $targetUser, $targetRole, $what,
            $old, $new, ($reason !== null && trim($reason) !== '') ? substr(trim($reason), 0, 255) : null,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // migration 077 pending, or a write fault: the change stands, unrecorded.
    }
}
