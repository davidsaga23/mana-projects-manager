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

// Working days (Mon–Fri) between two dates, both INCLUSIVE — matching the
// platform-wide rule that end dates are inclusive everywhere.
// Every working day from $from to $to, both inclusive, as YYYY-MM-DD strings.
// Built by stepping a DateTime, never by parsing dates in JS — 'YYYY-MM-DD' read
// through JS's Date() is treated as UTC and walks backwards in a UTC−6 timezone.
function working_days_list(string $from, string $to): array {
    $f = new DateTime($from);
    $t = new DateTime($to);
    $days = [];
    while ($f <= $t) {
        if ((int)$f->format('N') < 6) $days[] = $f->format('Y-m-d');
        $f->modify('+1 day');
    }
    return $days;
}

function working_days_between(string $from, string $to): int {
    $f = new DateTime($from);
    $t = new DateTime($to);
    if ($t < $f) return 0;
    $count = 0;
    while ($f <= $t) {
        $dow = (int)$f->format('N'); // 1=Mon .. 7=Sun
        if ($dow < 6) $count++;
        $f->modify('+1 day');
    }
    return $count;
}
