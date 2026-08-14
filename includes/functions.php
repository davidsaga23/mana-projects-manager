<?php
// Shared helpers. Grows as phases land; keep scheduler maths in its own file later.

// HTML-escape shorthand.
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Cache-buster for EVERY js/css include — a stale cached timeline.js cost a
// whole debugging round on STFC. Echo the return value straight after the
// filename in an asset URL, e.g. href="assets/style.css" then asset_ver('style.css').
//
// Never write a closing PHP tag inside a comment here: PHP acts on it even in a
// //-comment, ending PHP mode and dumping the rest of the file to the browser.
function asset_ver(string $filename): string {
    $path = __DIR__ . '/../assets/' . $filename;
    $mtime = @filemtime($path);
    return '?v=' . ($mtime ?: time());
}

/**
 * The studio logo, if one has been supplied.
 *
 * Drop the artwork at assets/logo.svg (preferred — stays sharp at any size) or
 * assets/logo.png with a transparent background. Until then this falls back to
 * a plain wordmark, so the pages are never broken by a missing file and the
 * logo appears everywhere at once the moment it exists.
 */
function site_logo(string $class = 'site-logo'): string {
    foreach (['logo.svg', 'logo.png'] as $file) {
        if (is_file(__DIR__ . '/../assets/' . $file)) {
            return '<img src="assets/' . $file . asset_ver($file) . '" alt="Mana" class="' . e($class) . '">';
        }
    }
    return '<span class="' . e($class) . ' site-logo-text">MANA</span>';
}

/**
 * How many migration files haven't been applied yet.
 *
 * Read on every admin page view so the header can say "there's something to run"
 * without anyone having to go and look. One directory listing plus one small
 * query, cached for the request.
 *
 * Returns 0 rather than throwing when schema_migrations doesn't exist — that
 * means a fresh install mid-setup, which is not a state to raise an alarm about.
 */
function pending_migration_count(PDO $pdo): int {
    static $count = null;
    if ($count !== null) return $count;

    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    if (!$files) return $count = 0;

    try {
        $applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return $count = 0;
    }

    $done = array_flip($applied);
    $n = 0;
    foreach ($files as $f) {
        if (!isset($done[basename($f)])) $n++;
    }
    return $count = $n;
}

/**
 * Does a column exist yet?
 *
 * For screens that gained a field in a migration which may not have been applied
 * on this install. Locking a working page over a pending migration is worse than
 * the missing feature — the same call made for roles in current_user().
 *
 * NO PLACEHOLDER, deliberately. The first version of this ran
 * `SHOW COLUMNS FROM x LIKE ?` as a prepared statement, and db.php sets
 * PDO::ATTR_EMULATE_PREPARES => false — so that is a real server-side prepare,
 * and MySQL's prepared-statement protocol will not accept a parameter there. It
 * threw, the catch below swallowed it, and every column in the app was reported
 * missing. The visible symptom was the scheduler's save button quietly not being
 * drawn: a feature disabling itself with no message, which is the exact failure
 * class this codebase keeps paying for.
 *
 * Reads the whole table's column list in one query and caches all of it, so a
 * page asking about four columns still costs one round trip.
 */
function column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    // A table name can't be bound as a parameter, so it is whitelisted to the
    // characters an identifier can contain. Every caller passes a literal.
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM `' . $safe . '`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return $cache[$key] = false;    // table itself doesn't exist yet
    }
    foreach ($cols as $c) $cache[$table . '.' . $c] = true;
    return $cache[$key] = in_array($column, $cols, true);
}

// Show a number without trailing-zero noise: 2.50 -> "2.5", 3.00 -> "3".
// Empty string for null so it can fill a form field that means "not set".
function fmt_num($v, int $decimals = 2): string {
    if ($v === null || $v === '') return '';
    return rtrim(rtrim(number_format((float)$v, $decimals, '.', ''), '0'), '.');
}

// Messages that survive a redirect. Every save reports what it did, what it
// skipped and why — several STFC bugs looked like "nothing happened".
function flash(string $type, string $msg): void {   // $type: ok | warn | err
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// Working days between two dates, both INCLUSIVE — matching the platform-wide
// rule that end dates are inclusive everywhere.
// Every working day from $from to $to, both inclusive, as YYYY-MM-DD strings.
// Built by stepping a DateTime, never by parsing dates in JS — 'YYYY-MM-DD' read
// through JS's Date() is treated as UTC and walks backwards in a UTC−6 timezone.
//
// "Working" means Mon–Fri AND not in non_working_days — Mexican public holidays
// are not weekends, and counting them as workable overstated every deadline in
// the app. See includes/calendar.php. Before migration 010 has run that table
// doesn't exist, non_working_days() returns nothing, and these behave exactly as
// they always did.
require_once __DIR__ . '/calendar.php';

// The daily snapshot, called from require_login(). Loaded from HERE rather than
// from auth.php on purpose: every page does `require auth.php` and then
// `require functions.php` (plain, not _once), so anything auth.php pulls in
// early gets executed a second time by that line and every function in it is
// redeclared. functions.php is the last of the three, so it is the safe place to
// add to. require_login() is only ever CALLED after all three have loaded.
require_once __DIR__ . '/snapshots.php';

// The project tab bar. Loaded here for the same reason and with the same
// caveat: every page requires functions.php, so nothing has to remember to pull
// this in — and it must NOT be required from auth.php, which runs before it.
require_once __DIR__ . '/project_nav.php';

function working_days_list(string $from, string $to): array {
    $holidays = non_working_days();
    $f = new DateTime($from);
    $t = new DateTime($to);
    $days = [];
    while ($f <= $t) {
        $s = $f->format('Y-m-d');
        if ((int)$f->format('N') < 6 && !isset($holidays[$s])) $days[] = $s;
        $f->modify('+1 day');
    }
    return $days;
}

function working_days_between(string $from, string $to): int {
    $holidays = non_working_days();
    $f = new DateTime($from);
    $t = new DateTime($to);
    if ($t < $f) return 0;
    $count = 0;
    while ($f <= $t) {
        $s = $f->format('Y-m-d');
        if ((int)$f->format('N') < 6 && !isset($holidays[$s])) $count++;   // 1=Mon .. 7=Sun
        $f->modify('+1 day');
    }
    return $count;
}
