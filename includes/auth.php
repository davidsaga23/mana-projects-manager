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

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
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
}

function current_user_display(): string {
    return $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User';
}
