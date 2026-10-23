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
    // A drop-in replacement is shown exactly as it is: an svg or png put here
    // is assumed to be a tight mark already, and nothing below should crop it.
    foreach (['logo.svg', 'logo.png'] as $file) {
        if (is_file(__DIR__ . '/../assets/' . $file)) {
            return '<img src="assets/' . $file . asset_ver($file) . '" alt="Anima" class="' . e($class) . '">';
        }
    }

    // ⚠ THE ARTWORK IS ONE SQUARE IMAGE HOLDING TWO THINGS: the A, and the
    // word ANIMA under it (David, 2026-10-23: "let's use this as the platform
    // official logo"). Only the A is ever shown, and the crop is CSS — see
    // .site-logo-crop, whose fractions were MEASURED off this exact file.
    //
    // The wordmark is dropped because every place a logo appears in this app
    // already says the name in words beside it or under it: "Anima | Project
    // manager" in the top bar, and the same again as the login heading. A
    // lockup there would say Anima twice, and at 24px its wordmark would be
    // four pixels tall and unreadable anyway.
    //
    // Cropping rather than shipping a second cropped file keeps ONE image to
    // replace when the artwork changes — and if it does change, those
    // fractions have to be measured again.
    $lockup = 'logo.webp';
    if (is_file(__DIR__ . '/../assets/' . $lockup)) {
        return '<span class="' . e($class) . ' site-logo-crop">'
             . '<img src="assets/' . $lockup . asset_ver($lockup) . '" alt="Anima"></span>';
    }

    return '<span class="' . e($class) . ' site-logo-text">ANIMA</span>';
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
 * Has this migration file been applied, all of it?
 *
 * For a change where the CODE must wait for the DATA, which column_exists()
 * cannot tell: a column exists after a migration's first statement, while
 * migrate.php records the file only once every statement has succeeded. Ideal
 * quotas becoming per project (034) is the case. The code must not read a step's
 * own days per item as the plan until 034 has copied the sheets' numbers into it,
 * or every budget would move to the old, ignored values for as long as the
 * update sat waiting.
 */
function migration_applied(PDO $pdo, string $filename): bool {
    static $applied = null;
    if ($applied === null) {
        try {
            $applied = array_flip($pdo->query('SELECT filename FROM schema_migrations')
                                      ->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $applied = [];                 // a fresh install mid-setup
        }
    }
    return isset($applied[$filename]);
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

/**
 * Does this column accept NULL?
 *
 * Same job as column_exists() and enum_has_value(): a migration may not have
 * run, and a screen that offers something the column would reject is a save
 * that fails for no visible reason. Used where a feature depends on a column
 * having been WIDENED rather than added — migration 030 makes hires.project_id
 * nullable so an artist can exist before any show wants them.
 */
function column_is_nullable(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . $safe . '`') as $row) {
            if (($row['Field'] ?? '') === $column) {
                return $cache[$key] = (strtoupper((string)($row['Null'] ?? '')) === 'YES');
            }
        }
    } catch (PDOException $e) {
        return $cache[$key] = false;
    }
    return $cache[$key] = false;
}

/**
 * Does an ENUM column accept this value yet?
 *
 * Same job as column_exists() one level finer, and for the same reason: a
 * migration that widens an ENUM may not have been run, and a screen that offers
 * a value the column would reject is a save that fails for no visible reason.
 * MySQL in strict mode rejects it outright; without strict mode it silently
 * stores the empty string, which is worse.
 *
 * Cached per table.column, so a list drawing one dropdown per row costs one
 * query, not one per row.
 */
function enum_has_value(PDO $pdo, string $table, string $column, string $value): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        // Whitelisted for the same reason column_exists() does it: an identifier
        // cannot be bound as a parameter. Every caller passes a literal.
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $cache[$key] = [];
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $safe . '`') as $row) {
                if (($row['Field'] ?? '') !== $column) continue;
                if (preg_match_all("/'((?:[^']|'')*)'/", (string)($row['Type'] ?? ''), $m)) {
                    $cache[$key] = array_map(function ($v) {
                        return str_replace("''", "'", $v);
                    }, $m[1]);
                }
                break;
            }
        } catch (PDOException $e) {
            $cache[$key] = [];          // table itself does not exist yet
        }
    }
    return in_array($value, $cache[$key], true);
}

/* ---------------------------------------------------------------------------
 * PROJECT TYPES
 *
 * David, 2026-09-16: a 2D motion comic needed ideal quotas of its own without
 * changing the 2D sheet, and the four types were fixed in the schema. Since
 * migration 032 they are rows an administrator adds on the Ideal quotas screen.
 *
 * ⚠ THE ONE LIST. Five places carried their own copy of the four: the Basics
 * picker and its check, the template picker and its check, and the Ideal quotas
 * tabs. A copy left behind is how a type added in one place gets refused, or
 * quietly rewritten to Other, in another.
 * ------------------------------------------------------------------------- */

/** The four types the schema shipped with, and all an install before 032 accepts. */
const PROJECT_TYPES_BUILTIN = ['2D', '3D', 'Mixed', 'Other'];

/** Has migration 032 been applied? */
function project_types_available(PDO $pdo): bool {
    return column_exists($pdo, 'project_types', 'name');
}

/**
 * Every project type, in the order screens list them.
 *
 * Before 032 the answer is the four, which is also exactly what the ENUM columns
 * still accept, so nothing can offer a type the database would refuse.
 *
 * $fresh skips the per-request cache, for code about to add or remove one.
 */
function project_types(PDO $pdo, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    if (!project_types_available($pdo)) return $cache = PROJECT_TYPES_BUILTIN;

    $names = $pdo->query('SELECT name FROM project_types ORDER BY sort_order, id')
                 ->fetchAll(PDO::FETCH_COLUMN);
    // Never an empty list: a picker with no options posts nothing, and every
    // project needs a type. Removing the last one is refused, so an empty table
    // was emptied by hand, and the four are the honest fallback.
    return $cache = $names ? array_map('strval', $names) : PROJECT_TYPES_BUILTIN;
}

/**
 * The types to offer in a picker that already holds $current.
 *
 * ⚠ $current IS ADDED WHEN IT IS MISSING. A <select> whose value has no option
 * shows its FIRST option instead, so the next save of an unrelated field would
 * move the project to 2D, onto another ideal quotas sheet and another budget,
 * without anybody choosing it.
 */
function project_type_options(PDO $pdo, string $current = ''): array {
    $types = project_types($pdo);
    if ($current !== '' && !in_array($current, $types, true)) $types[] = $current;
    return $types;
}

// Show a number without trailing-zero noise: 2.50 -> "2.5", 3.00 -> "3".
// Empty string for null so it can fill a form field that means "not set".
/**
 * A number with its pointless trailing zeros removed: 2.50 -> "2.5", 2.00 -> "2".
 *
 * ⚠ THE strpos GUARD IS LOAD-BEARING. Without it the trims run on a string that
 * has no decimal point to stop them, and they eat SIGNIFICANT digits:
 *
 *     fmt_num(0,   0)  ->  ""      (an empty cell where a zero belongs)
 *     fmt_num(10,  0)  ->  "1"
 *     fmt_num(100, 0)  ->  "1"
 *
 * That is the dangerous shape of wrong: not a blank page or an error, a
 * PLAUSIBLE number. "1 of 38 approved" reads perfectly and is a tenth of the
 * truth. It surfaced on the finance Overview, where Approved / Delivered /
 * Blocked all rendered empty because they were legitimately zero (2026-08-17).
 *
 * At one decimal place or more the behaviour is unchanged, because
 * number_format() always emits a '.' there and the '.' is what stopped the trim
 * — which is why this survived from the beginning without being noticed: every
 * caller until this week used the default of 2.
 */
function fmt_num($v, int $decimals = 2): string {
    if ($v === null || $v === '') return '';
    $s = number_format((float)$v, $decimals, '.', '');
    return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
}

/**
 * Seconds as a running time: 233.62 → "3:53.62".
 *
 * Footage is quoted in minutes and seconds by everyone who works on it, and
 * printing "233.62 sec" leaves them doing the arithmetic.
 *
 * Here rather than in includes/dashboard.php, where it was written, since
 * 2026-09-22: the shot breakdown totals footage too, and pulling the whole
 * dashboard into that screen to reach one formatter was the wrong way round.
 * One formatter shared, because two would drift the first time either was
 * corrected.
 */
function fmt_secs(float $s): string {
    if ($s < 0) $s = 0;
    $m = (int)floor($s / 60);
    $r = $s - $m * 60;
    return $m . ':' . str_pad(number_format($r, 2, '.', ''), 5, '0', STR_PAD_LEFT);
}

/**
 * The label for a shot, following the project's own episode setting.
 *
 * STFC could hardcode EP002_SH050 because that show is a series. Here `episodic`
 * is a per-project flag (DECISIONS, 2026-08-11) and a commercial has one flat
 * shot list — prefixing those with an empty episode would produce "_SH010".
 */
function shot_label(?string $episode, ?string $shotCode): string {
    $episode  = trim((string)$episode);
    $shotCode = trim((string)$shotCode);
    if ($shotCode === '') return '';
    return $episode !== '' ? $episode . '_' . $shotCode : $shotCode;
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

/**
 * The My tasks nav badge: how many active cards this login owns, and whether any
 * of them is NEW since they last opened the list.
 *
 * David, 2026-10-20: a number under "My tasks", and a different COLOUR when
 * there is something new. Drawn in the top bar on EVERY page, so it has to be
 * cheap — one indexed query (idx_assignee covers assigned_crew_id + archived),
 * no per-card work.
 *
 * "Active" is counted exactly as the My tasks Tickets list counts it
 * (tickets_for_crew): the login's own cards, not archived, status not approved.
 * "New" means created after users.mytasks_seen_at, which opening My tasks stamps
 * to NOW() — so the colour clears the moment they look.
 *
 * Degrades safely before migration 053: with no mytasks_seen_at column the count
 * still shows and 'new' is simply always false. With no tickets table, or no
 * crew link, it returns zero and the caller draws nothing.
 *
 * @return array{count:int, new:bool}
 */
function mytasks_badge(PDO $pdo, int $userId): array {
    $none = ['count' => 0, 'new' => false];
    if ($userId <= 0) return $none;
    if (!column_exists($pdo, 'tickets', 'id') || !column_exists($pdo, 'users', 'crew_id')) {
        return $none;
    }
    $hasSeen = column_exists($pdo, 'users', 'mytasks_seen_at');

    // A ticket is new when it was created after the login last opened My tasks.
    // COALESCE to an old date so a never-looked login (NULL) treats what it has
    // as new — correct for someone opening the screen for the first time.
    $newExpr = $hasSeen
        ? "SUM(CASE WHEN t.created_at > COALESCE(u.mytasks_seen_at, '1970-01-01') THEN 1 ELSE 0 END)"
        : '0';

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n, {$newExpr} AS new_n
               FROM users u
               JOIN tickets t         ON t.assigned_crew_id = u.crew_id
               LEFT JOIN statuses st  ON st.id = t.status_id
              WHERE u.id = ? AND u.crew_id IS NOT NULL
                AND t.archived = 0
                AND (st.maps_to IS NULL OR st.maps_to <> 'approved')"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return $none;
        return ['count' => (int)$row['n'], 'new' => ((int)$row['new_n']) > 0];
    } catch (Throwable $e) {
        // A missing statuses table or similar should never break the chrome.
        return $none;
    }
}
