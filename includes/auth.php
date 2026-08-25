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

function user_role(): string {
    $u = current_user();
    return $u['role'] ?? 'viewer';
}

function is_admin(): bool {
    return user_role() === 'admin';
}

/** Can this account change anything at all? */
function can_edit(): bool {
    return in_array(user_role(), ['admin', 'editor'], true);
}

function require_login(): void {
    if (!is_logged_in()) {
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !can_edit()) {
        if (function_exists('flash')) {
            flash('err', 'Your account has view-only access, so that change was not saved.');
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !can_edit()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your account has view-only access.']);
        exit;
    }
}

function current_user_display(): string {
    return $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User';
}
