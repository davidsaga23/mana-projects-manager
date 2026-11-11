<?php
// Pre-flight: is this host ready to run Anima?
//
// David, 2026-11-09, moving Anima from the old shared cPanel account to
// KnownHost at anima-mana.com. Every requirement the app has of its host,
// checked on the host itself and answered in one page of green, amber and red:
// PHP and its extensions, the upload and time limits a video version needs,
// the folders Anima writes, both databases, that the folders which must never
// be public are not, and that the outside services it calls can be reached.
//
// ⚠ STANDALONE ON PURPOSE. It loads config.php and nothing that needs a
// working database or a login, because those are among the things it checks:
// a page that died on "Database connection failed" could not tell you why.
// So it is not behind require_admin(). It is behind a key instead
// ('preflight_key' in config.php, compared in constant time), and with no key
// set it answers 404 like a page that does not exist. Clear the key once the
// move is done.
//
// ⚠ READ-ONLY, apart from two things it cleans up after itself: it makes the
// uploads and sessions folders if they are missing (as the app would on first
// use), and it writes and deletes one small probe file in each to test that a
// browser cannot read it. It never reads or prints a password.

$config = require __DIR__ . '/config.php';

$want = (string)($config['preflight_key'] ?? '');
$got  = (string)($_GET['key'] ?? '');
if ($want === '' || !hash_equals($want, $got)) {
    http_response_code(404);
    exit('Not found');
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
date_default_timezone_set($config['timezone'] ?? 'UTC');
require_once __DIR__ . '/includes/uploads.php';   // ensure_upload_dir(), the app's own

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$checks = [];   // [section, label, 'ok'|'warn'|'bad', detail]
$add = function (string $section, string $label, string $state, string $detail = '') use (&$checks) {
    $checks[] = [$section, $label, $state, $detail];
};

/** "128M" → bytes; -1 / 0 stay as they are (no limit). */
$bytes = function ($v): int {
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') return -1;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
};
$mb = fn(int $b) => $b < 0 ? 'no limit' : round($b / 1048576) . ' MB';

// --- PHP --------------------------------------------------------------------
$v = PHP_VERSION;
$add('PHP', 'Version ' . $v,
    version_compare($v, '8.1', '>=') ? 'ok' : (version_compare($v, '8.0', '>=') ? 'warn' : 'bad'),
    version_compare($v, '8.0', '<') ? 'Anima needs PHP 8.0 or newer. Pick 8.1+ in cPanel → MultiPHP Manager.'
        : (version_compare($v, '8.1', '<') ? 'Works, but 8.0 is out of support. Pick 8.1+ if offered.' : ''));

foreach (['pdo_mysql' => 'the databases', 'mbstring' => 'text everywhere',
          'fileinfo' => 'checking what an upload really is', 'openssl' => 'sending email'] as $ext => $why) {
    $add('PHP', 'Extension ' . $ext, extension_loaded($ext) ? 'ok' : 'bad',
         extension_loaded($ext) ? '' : 'Required, for ' . $why . '. Enable it in cPanel → Select PHP Version → Extensions.');
}
foreach (['curl' => 'the call-transcript AI reader and exchange rates', 'zip' => 'Excel downloads'] as $ext => $why) {
    $add('PHP', 'Extension ' . $ext, extension_loaded($ext) ? 'ok' : 'warn',
         extension_loaded($ext) ? '' : 'Needed for ' . $why . '; the rest of Anima works without it.');
}

// --- limits a video version needs --------------------------------------------
$perFile  = (int)($config['upload_max_bytes'] ?? 25 * 1048576);
$perPost  = $perFile * (int)($config['upload_max_files'] ?? 5);
$umf = $bytes(ini_get('upload_max_filesize'));
$pms = $bytes(ini_get('post_max_size'));
$met = (int)ini_get('max_execution_time');
$mem = $bytes(ini_get('memory_limit'));
$add('Limits', 'upload_max_filesize ' . $mb($umf), ($umf < 0 || $umf >= $perFile) ? 'ok' : 'bad',
     ($umf < 0 || $umf >= $perFile) ? '' : 'Needs at least ' . $mb($perFile) . ' (one video version).');
$add('Limits', 'post_max_size ' . $mb($pms), ($pms < 0 || $pms >= $perPost) ? 'ok' : ($pms >= $perFile ? 'warn' : 'bad'),
     ($pms < 0 || $pms >= $perPost) ? '' : 'Five files at once need ' . $mb($perPost) . '; below that, big multi-file uploads fail.');
$add('Limits', 'max_execution_time ' . ($met === 0 ? 'no limit' : $met . ' s'), ($met === 0 || $met >= 90) ? 'ok' : 'warn',
     ($met === 0 || $met >= 90) ? '' : 'Home\'s invoice tab asks for 90 s on a person with many shows.');
$add('Limits', 'memory_limit ' . $mb($mem), ($mem < 0 || $mem >= 128 * 1048576) ? 'ok' : 'warn',
     ($mem < 0 || $mem >= 128 * 1048576) ? '' : '128 MB or more is safer for Excel downloads.');
$add('Limits', 'file_uploads', ini_get('file_uploads') ? 'ok' : 'bad', ini_get('file_uploads') ? '' : 'Uploads are switched off.');

// --- this request -----------------------------------------------------------
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$add('Site', 'HTTPS', $https ? 'ok' : 'bad', $https ? '' : 'Logins need HTTPS (session_secure). Turn on AutoSSL for the domain.');
$add('Site', 'Time zone ' . ($config['timezone'] ?? '(none)') . ', now ' . date('Y-m-d H:i T'),
     in_array($config['timezone'] ?? '', timezone_identifiers_list(), true) ? 'ok' : 'bad');
$siteUrl = trim((string)($config['site_url'] ?? ''));
$add('Site', 'site_url ' . ($siteUrl !== '' ? $siteUrl : '(not set)'), $siteUrl !== '' ? 'ok' : 'warn',
     $siteUrl !== '' ? '' : 'Set it to https://anima-mana.com so every emailed link points at the new address.');
$add('Site', 'Maintenance', empty($config['maintenance']) ? 'ok' : 'warn',
     empty($config['maintenance']) ? 'Off: the site is open.' : 'ON: every page except this one shows "back shortly".');

// --- folders Anima writes ----------------------------------------------------
$uploadDir = ensure_upload_dir();
$sessDir   = __DIR__ . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
if (is_dir($sessDir) && !file_exists($sessDir . '/.htaccess')) {
    // The same file includes/auth.php writes on first use.
    @file_put_contents($sessDir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
}
foreach (['Uploads (' . UPLOAD_SUBDIR . ')' => $uploadDir, 'Sessions (sessions/)' => $sessDir,
          'System temp (Excel downloads)' => sys_get_temp_dir()] as $label => $dir) {
    $ok = is_dir($dir) && is_writable($dir);
    $add('Folders', $label, $ok ? 'ok' : 'bad', $ok ? '' : 'Not writable by PHP: ' . $dir);
}
$files = is_dir($uploadDir) ? count(glob($uploadDir . '/*') ?: []) : 0;
$add('Folders', 'Uploaded files present: ' . $files, $files > 0 ? 'ok' : 'warn',
     $files > 0 ? '' : 'Empty. Fine before the copy; after it, the old uploads/ folder has not arrived.');

// --- the databases ----------------------------------------------------------
$main = null;
try {
    $main = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'), $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $ver = (string)$main->query('SELECT VERSION()')->fetchColumn();
    $add('Main database', 'Connects: ' . $config['db_name'] . ' on ' . $config['db_host'] . ' (' . $ver . ')', 'ok');
    $main->exec("SET time_zone = '" . date('P') . "'");
    $skew = abs(strtotime((string)$main->query('SELECT NOW()')->fetchColumn()) - time());
    $add('Main database', 'Same clock as PHP', $skew < 120 ? 'ok' : 'bad', $skew < 120 ? '' : "MySQL is {$skew} s off PHP.");
    $tables = (int)$main->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    $add('Main database', $tables . ' tables', $tables > 0 ? 'ok' : 'warn', $tables > 0 ? '' : 'Empty: the copy has not been imported yet.');
    if ($tables > 0) {
        try {
            $ran = $main->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $onDisk = count(glob(__DIR__ . '/migrations/*.sql') ?: []);
            $add('Main database', $ran . ' of ' . $onDisk . ' migrations recorded', (int)$ran >= $onDisk ? 'ok' : 'warn',
                 (int)$ran >= $onDisk ? '' : 'Open migrate.php to run the rest.');
            $users = (int)$main->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();
            $add('Main database', $users . ' active logins', $users > 0 ? 'ok' : 'warn');
        } catch (Throwable $e) {
            $add('Main database', 'Anima\'s tables', 'warn', 'Tables are there but schema_migrations or users is not.');
        }
    }
} catch (Throwable $e) {
    $add('Main database', 'Connects', 'bad', 'Could not connect. Check db_host, db_name, db_user and db_pass in config.php, '
         . 'and that the user is added to the database with ALL PRIVILEGES in cPanel → MySQL Databases.');
}

$f = $config['finance_db'] ?? null;
if (!is_array($f) || empty($f['db_name'])) {
    $add('Finance database', 'Configured', 'bad', 'No finance_db block in config.php.');
} else {
    try {
        $fin = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', $f['db_host'] ?? 'localhost', $f['db_name'],
            $f['db_charset'] ?? 'utf8mb4'), $f['db_user'], $f['db_pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ft = (int)$fin->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $add('Finance database', 'Connects: ' . $f['db_name'] . ', ' . $ft . ' tables', $ft > 0 ? 'ok' : 'warn',
             $ft > 0 ? '' : 'Empty: the copy has not been imported yet.');
        // The two users must not reach each other's database (finance_install.sql).
        if ($main) {
            try {
                $main->query('SELECT 1 FROM `' . str_replace('`', '', $f['db_name']) . '`.project_budgets LIMIT 1');
                $add('Finance database', 'Kept apart from the main user', 'bad',
                     'The main database user can read the finance database. Remove that user from it in cPanel → MySQL Databases.');
            } catch (Throwable $e) {
                $add('Finance database', 'Kept apart from the main user', 'ok');
            }
        }
    } catch (Throwable $e) {
        $add('Finance database', 'Connects', 'bad', 'Could not connect. Check the finance_db block in config.php.');
    }
}

// --- folders a browser must never read -----------------------------------------
// Asked over HTTP, of this very site: the only real test that the host honours
// the .htaccess files (Apache and LiteSpeed do; plain nginx ignores them).
$base = $siteUrl !== '' ? rtrim($siteUrl, '/')
      : ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
// $self: a request to THIS site. It goes straight to this server's own address
// and does not insist on a trusted certificate. On a new host the certificate is
// self-signed until AutoSSL runs, and the server's DNS may still remember the
// old host; neither has anything to do with whether a folder is private, and
// both used to turn these four checks into "could not ask" (2026-11-10). The
// outside services below are asked normally, certificate checked.
$fetchStatus = function (string $url, bool $self = false): ?int {
    $host = (string)parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443;
    $ip   = (string)($_SERVER['SERVER_ADDR'] ?? '');
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        $opts = [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false];
        if ($self) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            if ($ip !== '' && $host !== '') $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
        }
        curl_setopt_array($c, $opts);
        curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $code ?: null;
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8],
        'ssl' => $self ? ['verify_peer' => false, 'verify_peer_name' => false] : []]);
    @file_get_contents($url, false, $ctx);
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) return (int)$m[1];
    return null;
};
$probes = [
    'includes/ (code and settings)' => ['includes/db.php', null],
    'migrations/'                   => ['migrations/README.md', null],
    'uploads/ (everybody\'s files)' => [UPLOAD_SUBDIR . '/preflight-probe.txt', $uploadDir . '/preflight-probe.txt'],
    'sessions/ (who is logged in)'  => ['sessions/preflight-probe.txt', $sessDir . '/preflight-probe.txt'],
];
foreach ($probes as $label => [$path, $file]) {
    if ($file) @file_put_contents($file, 'probe');
    $code = $fetchStatus($base . '/' . $path, true);
    if ($file) @unlink($file);
    if ($code === null) {
        $add('Kept private', $label, 'warn', 'Could not ask ' . $base . ' from the server itself. Check from a browser that this folder answers Forbidden.');
    } else {
        $closed = in_array($code, [401, 403, 404], true);
        $add('Kept private', $label . ': HTTP ' . $code, $closed ? 'ok' : 'bad',
             $closed ? '' : 'A browser can read this folder. The host is not honouring .htaccess; ask KnownHost to allow AllowOverride, or block it in the server config.');
    }
}

// --- outside services -------------------------------------------------------
foreach (['Exchange rates (frankfurter)' => 'https://api.frankfurter.dev/v1/latest?base=USD',
          'Call-transcript AI (Anthropic)' => 'https://api.anthropic.com/v1/messages'] as $label => $url) {
    $code = $fetchStatus($url);
    $add('Outside services', $label . ($code ? ': HTTP ' . $code : ''), $code ? 'ok' : 'warn',
         $code ? 'Reachable (an error code from Anthropic without a key is expected).' : 'Not reachable from this server.');
}
$add('Outside services', 'Email', 'warn',
     'Not tested here. Log in as an admin and open smtp_test.php, then send yourself a test.');

$count = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($checks as $c) $count[$c[2]]++;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anima · pre-flight</title>
<style>
  :root { --bg:#101010; --surface:#191919; --border:#2b2b2b; --text:#ededed; --muted:rgba(255,255,255,.6);
          --ok:#3ecf8e; --warn:#f0c33c; --bad:#ef5350; }
  body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,sans-serif; padding:24px 16px; }
  main { max-width:880px; margin:0 auto; }
  h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:15px; margin:24px 0 8px; color:var(--muted); }
  .sum span { margin-right:16px; font-weight:600; }
  table { width:100%; border-collapse:collapse; background:var(--surface); border:1px solid var(--border); border-radius:8px; }
  td { padding:8px 12px; border-top:1px solid var(--border); vertical-align:top; }
  tr:first-child td { border-top:0; }
  td.s { width:22px; font-weight:700; } td.d { color:var(--muted); }
  .ok { color:var(--ok); } .warn { color:var(--warn); } .bad { color:var(--bad); }
</style></head><body><main>
<h1>Anima pre-flight</h1>
<p class="sum"><span class="ok"><?= $count['ok'] ?> ready</span><span class="warn"><?= $count['warn'] ?> to look at</span><span class="bad"><?= $count['bad'] ?> blocking</span></p>
<?php $section = null; foreach ($checks as [$sec, $label, $state, $detail]):
    if ($sec !== $section): if ($section !== null): ?></table><?php endif; $section = $sec; ?>
<h2><?= $h($sec) ?></h2><table>
<?php endif; ?>
  <tr><td class="s <?= $state ?>"><?= $state === 'ok' ? '✓' : ($state === 'warn' ? '!' : '✕') ?></td>
      <td><?= $h($label) ?></td><td class="d"><?= $h($detail) ?></td></tr>
<?php endforeach; if ($section !== null): ?></table><?php endif; ?>
<p class="d" style="color:var(--muted);margin-top:24px">Read-only. Clear <code>preflight_key</code> in config.php when the move is done.</p>
</main></body></html>
