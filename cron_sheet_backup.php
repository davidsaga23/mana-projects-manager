<?php
// Copies Spell into the Google Sheet backup, once an hour. See
// includes/sheet_backup.php for why it only goes this way on its own, and
// docs/SHEET_BACKUP.md for setting it up.
//
// David, 2026-11-19: a spreadsheet "especially for Spell", updated hourly, for
// the studio to work from on a day Anima will not open.
//
// ⚠ RUN BY THE SERVER, NOT BY A PERSON. cPanel's Cron Jobs starts it at five
// past every hour. The cPanel line, set up once:
//
//     5 * * * *   /usr/local/bin/php /home/ACCOUNT/public_html/cron_sheet_backup.php >/dev/null 2>&1
//
// A show whose tab has changes nobody has reviewed is left alone (its second
// line says so), so a run after an outage never overwrites the outage's work.
//
// ⚠ NOT A WEB PAGE. Opened in a browser it answers 403, unless config.php has a
// 'cron_key' and the address carries it (?key=...), for a host whose cron can
// only fetch a URL — the same rule as cron_digest.php.
//
// For testing, from a terminal (SSH) on the server:
//     php cron_sheet_backup.php --dry-run     what it would write; reads the sheet, writes nothing
//     php cron_sheet_backup.php --show=12     only the show with id 12
// The Sheet backup screen in Anima has a "Copy now" button that does the same.

$cli = PHP_SAPI === 'cli';
if ($cli) chdir(__DIR__);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sheet_backup.php';

if (!$cli) {
    $key = (string)($config['cron_key'] ?? '');
    if ($key === '' || strlen($key) < 16 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "This runs from the server's cron job, not from a browser.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$opt = ['via' => 'cron'];
$args = $cli ? array_slice($argv, 1) : [];
if (!$cli && isset($_GET['dry'])) $args[] = '--dry-run';
foreach ($args as $a) {
    if ($a === '--dry-run') $opt['dry'] = true;
    elseif (preg_match('/^--show=(\d+)$/', $a, $m)) $opt['only'] = [(int)$m[1]];
}
@set_time_limit(300);

// Not set up yet: say nothing and stop, so a cron job added before the Google
// side is ready does not mail David every hour. --dry-run says why.
if (gsheet_setup_problem() !== null && empty($opt['dry'])) exit(0);

try {
    $res = sheet_backup_run($pdo, $opt);
} catch (Throwable $e) {
    error_log('[Anima sheet backup] cron: ' . $e->getMessage());
    echo 'The Google Sheet backup failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Quiet when all went well, so a cron job that mails its output does not send
// David a note every hour. A failure, or a dry run, is printed.
if (!$res['ok'] || !empty($opt['dry'])) {
    echo $res['status'] . "\n";
    foreach ($res['lines'] as $l) echo '  ' . $l . "\n";
}
if (!$res['ok']) error_log('[Anima sheet backup] ' . $res['status'] . ' ' . implode(' | ', $res['lines']));
exit($res['ok'] ? 0 : 1);
