<?php
// Session bootstrap + login gate. Include AFTER db.php.

// How long a login lasts. Configurable in config.php; defaults to 7 days.
// This is a ROLLING window — it resets on every page view, so you only get
// logged out after being genuinely idle for the whole period.
$sessionLifetime = (int)($config['session_lifetime'] ?? 604800); // 7 days

if (session_status() === PHP_SESSION_NONE) {
    // --- Private session storage -------------------------------------------
    // The real cause of premature logouts on shared hosting: every account on
    // the server writes sessions into one shared directory, and PHP's garbage
    // collector uses the gc_maxlifetime of whichever site happens to trigger
    // it. A neighbouring site with a 20-minute setting will delete OUR session
    // files early, no matter what we configure here. Giving the app its own
    // directory means only our settings apply.
    $sessDir = dirname(__DIR__) . '/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0700, true);
    }
    // Only take it over if it's actually usable — a non-writable path would
    // break logins entirely, which is far worse than expiring early.
    if (is_dir($sessDir) && is_writable($sessDir)) {
        $ht = $sessDir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        session_save_path($sessDir);
    }

    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    // lazy_write skips rewriting the session file when nothing changed, so the
    // file's mtime goes stale and GC can collect it while someone is still
    // actively browsing. Turning it off keeps the timestamp fresh.
    ini_set('session.lazy_write', '0');

    session_name($config['session_name']);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'secure'   => $config['session_secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Roll the cookie forward on each request so an active user's expiry keeps
    // moving; without this the cookie dies at a fixed time from login.
    if (!empty($_SESSION['user_id'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLifetime,
            'path'     => '/',
            'secure'   => $config['session_secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// Every page here renders live data, so a browser must never show a stored copy.
// Without this, going back to a screen can display names or numbers as they were
// before an edit — which looks exactly like the save having failed.
// Sent from here because it runs before any output. Assets are served by Apache
// directly and keep their own cache-busting through asset_ver().
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * The logged-in user, read fresh from the database once per request.
 *
 * Deliberately not cached in the session: a role change or a deactivation must
 * take effect on the user's very next page view, not whenever they next happen
 * to log out. One small query per request is a fair price for that.
 *
 * Returns null when nobody is logged in or the account has since been deleted.
 */
function current_user(): ?array {
    static $user = null;
    static $loaded = false;
    if ($loaded) return $user;
    $loaded = true;

    if (empty($_SESSION['user_id'])) return null;
    global $pdo;
    if (!($pdo instanceof PDO)) return null;

    try {
        $stmt = $pdo->prepare(
            'SELECT id, username, display_name, role, active FROM users WHERE id = ?'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        // Roles arrive in migration 005. Before it has run those columns don't
        // exist, and locking everyone out of a working site over a pending
        // migration would be far worse than the missing feature — so fall back
        // to the previous behaviour: everyone who can log in is an admin.
        $stmt = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if ($user) { $user['role'] = 'admin'; $user['active'] = 1; }
    }
    return $user;
}

/**
 * The role this account ACTUALLY has, ignoring any preview.
 *
 * Everything that decides whether you may ENTER or LEAVE a preview asks this,
 * never user_role() — otherwise previewing as an artist would take away the very
 * permission needed to stop previewing, and the only way out would be clearing
 * the session cookie.
 *
 * ⚠ 'viewer' IS READ HERE AND NOWHERE ELSE. The role was renamed to 'artist' on
 * 2026-09-28 (migration 037), and this is the one place that still knows the old
 * name — so the app behaves correctly on a database where 037 has not been
 * applied yet, and every other file can be written as though only 'artist' ever
 * existed. Delete the mapping once no install is behind.
 */
function real_role(): string {
    $u = current_user();
    $role = (string)($u['role'] ?? 'artist');
    return $role === 'viewer' ? 'artist' : $role;
}

/**
 * The specific PERSON being previewed, or null when not previewing a person.
 *
 * David, 2026-10-21: "can we admins see anima as specific users instead of
 * generic ones?" The role preview below answers "what does an artist see"; this
 * answers "what does THIS artist see" — it borrows a named account's role AND
 * its crew link (effective_crew_id() reads it) so My tasks and the scoped
 * projects list show exactly that person's world.
 *
 * ⚠ SAME TWO SAFEGUARDS AS preview_role(), re-checked on every call:
 *   - only an admin can be previewing anyone (real_role() must still be admin);
 *   - only ever DOWNWARD — the viewed account must be active and hold a role
 *     strictly below admin (editor / supervisor / artist). Viewing as another
 *     admin is refused, so this can never be a path to lateral snooping between
 *     administrators, and a tampered session id pointing at an admin resolves to
 *     "no preview" rather than borrowing those powers.
 *
 * Read fresh once per request, like current_user(), so demoting or deactivating
 * the viewed account ends the preview on the admin's very next page view.
 * Returns a row with id, username, display_name, role (normalised), crew_id.
 */
function preview_user(): ?array {
    static $loaded = false;
    static $u = null;
    if ($loaded) return $u;
    $loaded = true;

    if (real_role() !== 'admin') return $u = null;
    $id = (int)($_SESSION['preview_user_id'] ?? 0);
    if ($id <= 0) return $u = null;

    global $pdo;
    if (!($pdo instanceof PDO)) return $u = null;
    try {
        $stmt = $pdo->prepare(
            'SELECT id, username, display_name, role, active, crew_id FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        // Roles (005) or the crew link (017) may be missing on this install;
        // without them there is nothing to preview, so fall back to no preview
        // rather than locking the admin into a broken state.
        return $u = null;
    }
    if (!$row || (int)($row['active'] ?? 0) !== 1) return $u = null;

    $role = ((string)($row['role'] ?? '')) === 'viewer' ? 'artist' : (string)($row['role'] ?? '');
    if (!in_array($role, ['editor', 'supervisor', 'artist'], true)) return $u = null;
    $row['role'] = $role;
    return $u = $row;
}

/** The id of the person being previewed, or 0 when not previewing a person. */
function preview_user_id(): int {
    $p = preview_user();
    return $p ? (int)$p['id'] : 0;
}

/** The role being previewed, or '' when not previewing. */
function preview_role(): string {
    // ⚠ CHECKED AGAINST THE REAL ROLE ON EVERY CALL, not just when the preview
    // started. An admin demoted to editor mid-session stops previewing at once
    // rather than keeping a session key that outlives the authority to hold it.
    //
    // ⚠ AND ONLY EVER DOWNWARD. The whitelist is editor, supervisor and artist —
    // all strictly less than admin — so this can take permissions away and has no
    // path to granting one. A tampered session value is either a lesser role or
    // nothing at all.
    if (real_role() !== 'admin') return '';
    // Previewing a specific person wears THAT person's role; preview_user() has
    // already proved it is a lesser, active account.
    $pu = preview_user();
    if ($pu) return (string)$pu['role'];
    $p = (string)($_SESSION['preview_role'] ?? '');
    if ($p === 'viewer') $p = 'artist';        // a session started before 037
    return in_array($p, ['editor', 'supervisor', 'artist'], true) ? $p : '';
}

/** "an artist", "a supervisor", "Production": a role named in a sentence. */
function role_in_words(string $role): string {
    // ⚠ 'editor' READS "Production" since 2026-10-19 — the role David renamed. It
    // is a LABEL change only: the stored value stays 'editor' everywhere, so the
    // preview whitelist, the finance guard and every role check are untouched and
    // existing accounts keep working. The one place the old name still appears is
    // the database value itself. "as Production" is deliberate: a team name, not
    // "a Production", which does not read.
    return ['admin' => 'an admin', 'editor' => 'Production',
            'supervisor' => 'a supervisor', 'artist' => 'an artist'][$role] ?? $role;
}

/** Is an admin currently looking at the app as somebody else? */
function in_preview(): bool {
    return preview_role() !== '';
}

/** Is the current preview a specific PERSON (not merely a role)? */
function in_user_preview(): bool {
    return preview_user_id() > 0;
}

/**
 * The id of the account the app should behave AS — the previewed person when
 * one is being previewed, otherwise the logged-in account.
 *
 * The companion to user_role(): where that answers "which role am I behaving
 * as", this answers "whose world am I looking at". My tasks and the projects
 * scope read through here, so viewing as a person reuses every per-person path
 * that already existed without being taught about the preview separately.
 */
function effective_user_id(): int {
    $pu = preview_user_id();
    return $pu > 0 ? $pu : (int)($_SESSION['user_id'] ?? 0);
}

/**
 * The crew member the effective account is linked to, or null when it is linked
 * to nobody (or the crew link column is missing — migration 017). This is the
 * key that turns "who am I" into "which projects / tasks are mine".
 *
 * Read once per request. A login with no crew link resolves to null, which the
 * callers read as "sees nothing of their own" — the same answer the app already
 * gives such an account on My tasks.
 */
function effective_crew_id(PDO $pdo): ?int {
    static $cache = [];
    $uid = effective_user_id();
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    if ($uid <= 0) return $cache[$uid] = null;
    // The previewed person's row already carries crew_id; use it and save a query.
    $pu = preview_user();
    if ($pu && (int)$pu['id'] === $uid) {
        $c = $pu['crew_id'] !== null ? (int)$pu['crew_id'] : null;
        return $cache[$uid] = ($c ?: null);
    }
    try {
        $stmt = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $c = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return $cache[$uid] = null;   // migration 017 pending
    }
    return $cache[$uid] = ($c ? (int)$c : null);
}

/**
 * The role the app should behave as, which is the preview when one is running.
 *
 * ONE OVERRIDE POINT FOR THE WHOLE APP. is_admin(), can_edit(), can_see_money(),
 * require_admin(), the top bar and the read-only guard below all come through
 * here, so nothing has to be taught about previewing separately — and a screen
 * added next year is covered without anybody remembering.
 */
function user_role(): string {
    $preview = preview_role();
    return $preview !== '' ? $preview : real_role();
}

function is_admin(): bool {
    return user_role() === 'admin';
}

/** Can this account change anything at all? */
function can_edit(): bool {
    return in_array(user_role(), ['admin', 'editor'], true);
}

/** The read-mostly role: everything visible, almost nothing writable. */
function is_artist(): bool {
    return user_role() === 'artist';
}

/**
 * An artist who also SEES a project's Timeline and Staffing, and EDITS its Shots
 * (migration 047).
 *
 * David, 2026-10-16: "a supervisor role who has all the artist permissions but
 * adding the timeline, staffing, shots", then the same evening: "Supervisors
 * can't edit timeline or staffing, it's more for them to consult than anything
 * else." So Timeline and Staffing are tabs shown to them (project_nav.php) and
 * read-only like everything else; Shots is the one area they write to.
 *
 * ⚠ can_edit() STAYS FALSE FOR A SUPERVISOR. Everything outside Shots behaves
 * exactly as it does for an artist, because it asks can_edit() and gets the
 * artist's answer. Shots asks can_edit_area() instead.
 */
function is_supervisor(): bool {
    return user_role() === 'supervisor';
}

/**
 * May the current account log its OWN hours on My tasks — start a timer, type
 * hours in by hand, and remove a log entry it entered?
 *
 * David, 2026-10-21: "let's [make] that available for artists then, they should
 * be able to start timers, log manually their time and delete a log entry (same
 * for editors and supervisors)." So every working role may, which is what this
 * returns. It is deliberately a role list, NOT "anyone logged in": a read-only
 * role added later does not inherit time-logging by accident.
 *
 * ⚠ THIS SAYS WHO, NOT WHOSE. Every timer / log / delete handler in mytasks.php
 * already restricts the row to the viewer's own crew ($myCrewId and the assigned
 * checks; delete_time_log() refuses an entry the person did not enter unless they
 * are an admin). This gate only decides whether the controls are live at all.
 */
function can_log_own_time(): bool {
    return can_edit() || is_artist() || is_supervisor();
}

/**
 * The files a supervisor may SAVE through, by area. The complete answer to "what
 * can a supervisor write beyond an artist?" — same reasoning as artist_may_post():
 * a screen added next year is refused by default.
 *
 *   shots — the breakdown grid and the API it saves through
 *
 * NOT here, deliberately: Timeline and Staffing (David: to consult, not edit),
 * and any rate or quote — those go through money.php, which refuses everyone
 * but an admin whatever this list says. Adding an area here is the whole change
 * to let supervisors edit it, once its screen asks can_edit_area().
 */
const SUPERVISOR_SCRIPTS = [
    'shots' => ['shots.php', 'shots_api.php'],
];

/** May the current account change things in this area ('timeline', 'staffing', 'shots')? */
function can_edit_area(string $area): bool {
    return can_edit() || (is_supervisor() && isset(SUPERVISOR_SCRIPTS[$area]));
}

/** Is this POST a supervisor saving through one of their three areas? */
function supervisor_may_post(): bool {
    if (!is_supervisor()) return false;
    // SCRIPT_NAME, not REQUEST_URI — see artist_may_post().
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (SUPERVISOR_SCRIPTS as $scripts) {
        if (in_array($script, $scripts, true)) return true;
    }
    return false;
}

/**
 * THE ONE HOLE IN READ-ONLY: an artist setting the status of their own work.
 *
 * David, 2026-09-28: "the viewer permission settings should allow... the ability
 * to adjust or change their status on their tasks or tickets."
 *
 * ⚠ AN ALLOW-LIST OF EXACT POSTS, not a role check scattered through the
 * screens. It names the script AND the field, so this function is the complete
 * answer to "what can an artist write?" — and a screen added next year is
 * refused by default rather than let through by an oversight. The ownership
 * check is a SECOND lock, in mytasks.php, on the row itself: this says which
 * action is allowed, that says whose row it may touch. Neither is sufficient
 * alone and both are cheap.
 *
 * What is deliberately NOT here:
 *   set_crew   — that form takes any crew id, so letting an artist self-link is
 *                a way to claim to be somebody else and then change their work.
 *                An admin links the account on the Users screen, which checks
 *                the choice. See migration 037.
 *   ticket.php — its `update` rewrites the whole card, assignee and title
 *                included. The status-only path lives on My tasks instead.
 */
function artist_may_post(): bool {
    // A supervisor has every artist permission, this one included.
    if (!is_artist() && !is_supervisor()) return false;

    $allowed = [
        // set_status / set_ticket_status: the status of their own work (2026-09-28).
        //
        // overhead_timer / overhead_log: logging their own time against a standing
        // overhead category (Meetings, Desk time, Reviews for an artist), added
        // 2026-10-19. ⚠ THIS IS STILL AN ALLOW-LIST BY FIELD, NOT A TICKET THEY
        // NAME. The field is what is permitted here; mytasks.php maps the posted
        // category key to the Studio card itself and refuses any key the person's
        // role is not offered — so this cannot be bent into logging time against
        // an arbitrary card. An artist logging their own overhead hours is exactly
        // the writing this was asked to allow, and nothing wider.
        //
        // log_time / timer / delete_log: logging their own hours against their own
        // task cards, and removing an entry they made, added 2026-10-21 (David:
        // artists/supervisors "should be able to start timers, log manually their
        // time and delete a log entry"). ⚠ SAME SHAPE AS ABOVE — the FIELD is
        // permitted here; WHOSE row it may touch is a second lock in mytasks.php:
        // every one of those handlers checks the task/ticket is assigned to the
        // viewer's own crew ($myCrewId), and delete_time_log() refuses an entry the
        // person did not enter unless they are an admin. So this cannot log or
        // delete against somebody else's work. See can_log_own_time().
        'mytasks.php' => ['set_status', 'set_ticket_status', 'overhead_timer', 'overhead_log',
                          'log_time', 'timer', 'delete_log'],
    ];
    // SCRIPT_NAME is the file the server resolved, not anything the caller typed
    // — unlike REQUEST_URI, which the redirect below has to defend against.
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ($allowed[$script] ?? [] as $field) {
        if (isset($_POST[$field])) return true;
    }
    return false;
}

/**
 * Stamp "last seen" for the logged-in account, so the Users page can show when
 * someone was last ACTIVE rather than only when they last logged in.
 *
 * Called from the auth gates below, so every authenticated page view and API
 * call counts — which is the whole point: a login lasts a week as a rolling
 * window, so without this the time only ever moves when somebody signs in
 * afresh, and an everyday user's "last seen" drifts days behind reality (David's
 * own read three weeks stale on a day he'd used the app).
 *
 * THROTTLED to at most once every five minutes per session. The marker lives in
 * the session, so deciding whether to write needs no extra read: an active user
 * costs one small UPDATE now and then, not one on every single request. The
 * marker is set even when the write can't happen (column not there yet) so a
 * pending migration can't turn into a failing UPDATE on every page.
 */
function touch_last_seen(): void {
    if (empty($_SESSION['user_id'])) return;

    $throttle = 300;                       // 5 minutes
    $now  = time();
    $last = (int)($_SESSION['last_seen_touch'] ?? 0);
    if ($last !== 0 && ($now - $last) < $throttle) return;

    // Mark it tried straight away, whatever happens below — so a column that
    // isn't there yet (migration 055 pending) doesn't mean one failing UPDATE
    // per request until it's run.
    $_SESSION['last_seen_touch'] = $now;

    global $pdo;
    if (!($pdo instanceof PDO)) return;
    try {
        $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')
            ->execute([(int)$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // Column arrives with migration 055; never fail a page over it.
    }
}

function require_login(): void {
    if (!is_logged_in()) {
        // Remember where they were going, so a link in an email (a kick-off
        // sign-off) lands on its page after the login rather than on the front
        // door. Same-site paths only; login.php checks it again before use.
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['after_login'] = (string)($_SERVER['REQUEST_URI'] ?? '');
        }
        header('Location: login.php');
        exit;
    }

    $user = current_user();
    if (!$user || (int)($user['active'] ?? 1) !== 1) {
        // Deleted or deactivated while logged in.
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?disabled=1');
        exit;
    }

    // Record that this account is active right now (throttled — see the helper).
    touch_last_seen();

    // The daily progress snapshot, taken by whoever opens the app first that
    // day. Here because it is the one place every page already passes through —
    // the alternative, remembering to call it per screen, fails the first time
    // someone adds a page and forgets.
    //
    // GET only: a POST is somebody saving something, and recording history in
    // the middle of that would attribute the change to the wrong day. Never
    // throws; a failure is surfaced to admins rather than swallowed.
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && function_exists('maybe_take_snapshots')) {
        global $pdo;
        if ($pdo instanceof PDO) {
            $problem = maybe_take_snapshots($pdo);
            if ($problem !== null && is_admin() && function_exists('flash')) {
                flash('warn', $problem);
            }
        }
    }

    // Read-only is enforced HERE rather than page by page, so a screen added
    // later is covered without anyone remembering to add a check. Every write in
    // this app is a POST, so refusing POSTs is the whole of it.
    //
    // A PREVIEW IS ALWAYS READ-ONLY, whichever role is being worn. Previewing as
    // an editor would otherwise let real work be done by somebody who believes
    // they are only looking — and "I was just seeing what they see" is a poor
    // account of how a rate got changed. The controls all still DRAW, which is
    // the point of the preview; only the saving stops.
    //
    // ONE EXCEPTION, and it is a named list rather than a role: an artist may set
    // the status of work assigned to them. artist_may_post() says which POSTs
    // those are; mytasks.php checks the row is theirs. A preview still saves
    // nothing at all, artist or not — the rule above has no exceptions.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ((!can_edit() && !artist_may_post() && !supervisor_may_post()) || in_preview())) {
        if (function_exists('flash')) {
            flash('err', in_preview()
                ? 'Nothing saves while you are previewing as someone else, leave the preview first.'
                : (is_supervisor()
                    ? 'Your account can change Shots and the status of your own work, but not this.'
                    : 'Your account can change the status of your own work, but not this.'));
        }
        // Back to the same screen as a GET, so the reader sees the page and the
        // message rather than a dead POST.
        //
        // REQUEST_URI comes from the request line, so it is the CALLER's text,
        // not ours. "POST //evil.com/ HTTP/1.1" makes it "//evil.com/", and a
        // protocol-relative Location sends the browser off this host entirely —
        // an open redirect wearing the app's own domain in the link. Only a path
        // beginning with a single slash is ours to return to.
        $back   = (string)($_SERVER['REQUEST_URI'] ?? '');
        $second = $back[1] ?? '';
        // chr(92) is a backslash. Written this way because a literal one
        // inside a single-quoted PHP string is an escape, and getting that
        // wrong here would break the file rather than the check.
        if ($back === '' || $back[0] !== '/'
            || $second === '/' || $second === chr(92)) {
            $back = 'index.php';
        }
        header('Location: ' . $back);
        exit;
    }

    // A project waiting on its kick-off sign-off is frozen: see kickoff_frozen().
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($why = kickoff_frozen()) !== null) {
        if (function_exists('flash')) flash('err', $why);
        $back   = (string)($_SERVER['REQUEST_URI'] ?? '');
        $second = $back[1] ?? '';
        if ($back === '' || $back[0] !== '/' || $second === '/' || $second === chr(92)) {
            $back = 'index.php';
        }
        header('Location: ' . $back);
        exit;
    }
}

/**
 * The screens that edit what a kick-off freezes, and where each says which
 * project it is about. While a project's sign-off is WAITING, a save through
 * any of them is refused (David, 2026-10-16: "the project should be frozen so
 * no further changes are done until is signed off"). Asking for changes or
 * withdrawing unfreezes it. Cards, time and statuses are not part of the
 * kick-off, so My tasks and the boards keep working.
 */
const KICKOFF_FROZEN_SCRIPTS = [
    'brief.php', 'crew.php', 'hiring.php', 'schedule.php', 'schedule_api.php',
    'timeline.php', 'timeline_import.php', 'shots_api.php', 'wizard.php',
    'standards.php', 'step.php', 'finance_project.php', 'import.php',
];

/** Why this POST may not save, when it touches a frozen project; else null. */
function kickoff_frozen(): ?string {
    global $pdo;
    if (!($pdo instanceof PDO)) return null;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, KICKOFF_FROZEN_SCRIPTS, true)) return null;
    $pid = (int)($_GET['project'] ?? $_POST['project'] ?? $_POST['project_id'] ?? 0);
    if ($pid <= 0 && in_array($script, ['brief.php', 'finance_project.php'], true)) {
        $pid = (int)($_GET['id'] ?? 0);
    }
    if ($pid <= 0) return null;
    try {
        $q = $pdo->prepare("SELECT 1 FROM project_kickoffs WHERE project_id = ? AND state = 'pending' LIMIT 1");
        $q->execute([$pid]);
        if (!$q->fetchColumn()) return null;
    } catch (PDOException $e) {
        return null;   // migration 049 pending
    }
    return 'This project is frozen while its kick-off waits for sign-off, so nothing on it can be changed. '
         . 'To change something, withdraw the request on the Kick-off page, or wait for an answer.';
}

/** For pages only an admin may open. */
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        if (function_exists('flash')) {
            flash('err', 'That screen is for administrators only.');
        }
        header('Location: index.php');
        exit;
    }
}

// --- PER-USER PROJECT VISIBILITY -------------------------------------------
//
// David, 2026-10-21: "how can we assign a project to a user so they can only
// see the ones they're subscribed/linked to?" The answer the app already half
// had: a person is "on" a show when they are staffed onto it (crew_projects,
// written by includes/crew.php), and a login is tied to a person by its crew
// link (users.crew_id). So "linked" means "staffed", and no new table is
// needed — this is a filter over data the app already keeps.
//
// KEYED ON user_role() AND effective_crew_id(), both of which follow a preview.
// So an admin viewing AS a specific artist sees exactly that artist's projects,
// which is the whole point of feature 2 — the two features share this code
// rather than each carrying its own idea of "whose projects".

/**
 * The roles that see EVERY project, regardless of staffing. Admin and Production
 * (stored 'editor') run the studio, so they are never scoped. Overridable in
 * config.php via $config['project_visibility_see_all'] if David later decides
 * supervisors should also see the whole slate — admin is forced in whatever the
 * config says, because an admin who could not see a project could not fix it.
 */
function roles_see_all_projects(): array {
    global $config;
    $r = $config['project_visibility_see_all'] ?? ['admin', 'editor'];
    if (!is_array($r)) $r = ['admin', 'editor'];
    if (!in_array('admin', $r, true)) $r[] = 'admin';
    return $r;
}

/** Does the effective role see every project (so no scoping applies)? */
function sees_all_projects(): bool {
    return in_array(user_role(), roles_see_all_projects(), true);
}

/**
 * The project ids the effective user may see, or NULL meaning "all of them".
 *
 * null  — a see-all role (admin / Production), or the crew_projects table is
 *         missing on this install: do not scope, because locking people out of
 *         a working app over a half-applied database is worse than the feature.
 * []    — a scoped role with no crew link, or linked but staffed on nothing:
 *         they see no shows, which is the honest answer for such an account.
 * [ids] — a scoped role: exactly the shows they are staffed on.
 */
function visible_project_ids(PDO $pdo): ?array {
    static $cache = null;
    static $done = false;
    if ($done) return $cache;
    $done = true;

    if (sees_all_projects()) return $cache = null;

    $crewId = effective_crew_id($pdo);
    if (!$crewId) return $cache = [];

    try {
        $stmt = $pdo->prepare('SELECT project_id FROM crew_projects WHERE crew_id = ?');
        $stmt->execute([$crewId]);
        return $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return $cache = null;   // crew_projects absent — don't scope a broken DB
    }
}

/**
 * A SQL fragment that limits a projects query to what the effective user may
 * see, for splicing into a WHERE. Returns '' for a see-all role (no limit), or
 * "<col> IN (1,2,3)" / "<col> IN (0)" for a scoped one. The ids are cast to int
 * before being inlined, so there is nothing to bind and nothing to inject.
 *
 *   $alias — the projects table's id column, e.g. 'p.id' or 'id'.
 */
function visible_projects_sql(PDO $pdo, string $col): string {
    $ids = visible_project_ids($pdo);
    if ($ids === null) return '';
    if (!$ids) return $col . ' IN (0)';     // nothing — 0 is never a real id
    $safe = implode(',', array_map('intval', $ids));
    return $col . ' IN (' . $safe . ')';
}

/** May the effective user open this one project? See-all roles always may. */
function may_see_project(PDO $pdo, int $projectId): bool {
    $ids = visible_project_ids($pdo);
    if ($ids === null) return true;
    return in_array($projectId, $ids, true);
}

/**
 * Bounce a scoped user who reached a project they are not staffed on — the
 * "close the door" half that project_nav.php's tab-hiding deliberately leaves to
 * a guard. Called by the project hub; a see-all role sails straight through.
 */
function require_project_access(PDO $pdo, int $projectId): void {
    if (may_see_project($pdo, $projectId)) return;
    if (function_exists('flash')) {
        flash('err', 'That project is not one of yours. You only see the shows you are staffed on.');
    }
    header('Location: index.php');
    exit;
}

// For AJAX/API endpoints: never redirect to an HTML page, always respond
// with JSON so fetch() callers can show a real message instead of a
// "Unexpected token '<'" JSON-parse error.
function require_login_json(): void {
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please reload the page and log in again.']);
        exit;
    }
    // API calls are activity too — count them for "last seen" (throttled).
    touch_last_seen();
    // A preview saves nothing here either, the same rule require_login() keeps.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ((!can_edit() && !supervisor_may_post()) || in_preview())) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your account has view-only access.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($why = kickoff_frozen()) !== null) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $why]);
        exit;
    }
}

function current_user_display(): string {
    return $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User';
}
