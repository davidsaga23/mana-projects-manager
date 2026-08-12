<?php
// Shared helpers. Grows as phases land; keep scheduler maths in its own file later.

// HTML-escape shorthand.
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Cache-buster for EVERY js/css include — a stale cached timeline.js cost a
// whole debugging round on STFC. Usage: <script src="assets/app.js<?= asset_ver('app.js') ?>">
function asset_ver(string $filename): string {
    $path = __DIR__ . '/../assets/' . $filename;
    $mtime = @filemtime($path);
    return '?v=' . ($mtime ?: time());
}

// Working days (Mon–Fri) between two dates, both INCLUSIVE — matching the
// platform-wide rule that end dates are inclusive everywhere.
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
