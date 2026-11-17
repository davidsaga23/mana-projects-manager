<?php
// Sends the 9 am and 5 pm (Pacific) email summaries. See includes/digest.php.
//
// David, 2026-11-17: "instead of sending an email for each mention, new task or
// comment, let's send 2 instead. One at the beginning of the day (around 9 am
// PST) and one at the end (5 pm PST) with a summary of new stuff for that
// person."
//
// ⚠ RUN BY THE SERVER, NOT BY A PERSON. The host cannot keep a program running
// waiting for 9 am, so cPanel's Cron Jobs starts this every 15 minutes. Each
// time it asks one question: is a summary due and not yet sent? Almost always
// the answer is no and it stops at once. Inside the two windows (9 am to 1 pm
// and 5 pm to 10 pm, Pacific) it sends whatever has not gone out yet, and
// records it, so a late run or a doubled run never sends anybody the same
// summary twice. The cPanel line, set up once (docs/EMAIL_DIGEST.md):
//
//     */15 * * * *   /usr/local/bin/php /home/ACCOUNT/public_html/cron_digest.php >/dev/null 2>&1
//
// With no cron job at all, the bell's 30-second check sends them instead, 20
// minutes late (digest_web_fallback()). The cron job is the dependable way.
//
// ⚠ NOT A WEB PAGE. Opened in a browser it answers 403, unless config.php has
// a 'cron_key' and the address carries it (?key=...), for a host whose cron can
// only fetch a URL. Nothing here can be started by a visitor.
//
// For testing, from a terminal (SSH) on the server:
//     php cron_digest.php --dry-run            who would get what, nothing sent or recorded
//     php cron_digest.php --dry-run --slot=am  the same, as if it were the morning window now
//     php cron_digest.php --slot=pm            really send the end-of-day summaries now

$cli = PHP_SAPI === 'cli';
if ($cli) chdir(__DIR__);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/digest.php';

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

// Run from cron there is no web address to read the site's own address from,
// so every link would point nowhere. site_url in config.php says it.
if (trim((string)($config['site_url'] ?? '')) === '' && $cli) {
    fwrite(STDERR, "config.php has no 'site_url' (e.g. 'https://anima-mana.com'), so the links in the summaries would be broken. Nothing was sent.\n");
    exit(1);
}

$opt = ['budget' => $cli ? 240 : 20];
$args = $cli ? array_slice($argv, 1) : [];
if (!$cli && isset($_GET['dry'])) $args[] = '--dry-run';
foreach ($args as $a) {
    if ($a === '--dry-run') $opt['dry'] = true;
    elseif (preg_match('/^--slot=(am|pm)$/', $a, $m)) $opt['slot'] = $m[1];
}
@set_time_limit(300);

try {
    $res = digest_run($pdo, 'cron', $opt);
} catch (Throwable $e) {
    error_log('[Anima digest] cron: ' . $e->getMessage());
    echo 'The email summaries failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Quiet when there was nothing to do, so a cron job that mails its output does
// not send David a note every 15 minutes.
if ($res['sent'] || $res['failed'] || !empty($opt['dry']) || !empty($opt['slot'])) {
    echo $res['status'] . "\n";
    foreach ($res['lines'] as $l) echo '  ' . $l . "\n";
}
exit($res['failed'] ? 1 : 0);
