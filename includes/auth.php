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
