<?php
/**
 * The bug reports, for Claude to work through (David, 2026-10-22: "how can we
 * make sure we pull all the bug reports so we can process them in claude?").
 *
 * tools/pull_bugs.py reads this from David's machine and the /bugs command
 * (.claude/skills/bugs) works the result. Three things it does:
 *
 *   GET                 the reports as JSON. ?status=open (default) or all.
 *   GET  ?file=<id>     one bug attachment's bytes, so the screenshots come too.
 *   POST action=…       close or flag a report once Claude has dealt with it:
 *                       fixed / wontfix (with a note naming the PR), open, or
 *                       flag (with the question David needs to answer).
 *
 * ⚠ NO LOGIN, A KEY INSTEAD. A script has no browser session, so this file
 * deliberately does not load includes/auth.php. It answers only a request
 * carrying the key set as 'bug_export_key' in config.php, sent in an
 * X-Bug-Key header, never in the URL, where it would end up in server logs and
 * browser history. With no key configured the whole file is switched off.
 * config.php is outside git and outside the deploy, so the key is never in the
 * repository.
 *
 * Bugs only. It cannot read a ticket's attachment, a user or a rate; the one
 * table it writes is bug_reports.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/bugs.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function bug_export_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ---- the key ---------------------------------------------------------------
$key = (string)($config['bug_export_key'] ?? '');
// 32 characters at least, so a placeholder or a short word cannot switch it on.
if (strlen($key) < 32) bug_export_fail(404, 'Not found');
$given = (string)($_SERVER['HTTP_X_BUG_KEY'] ?? '');
if ($given === '' || !hash_equals($key, $given)) bug_export_fail(403, 'Wrong or missing key.');

if (!bugs_available($pdo)) bug_export_fail(409, 'Bug reports need database update 062.');
$flags = bugs_flags_available($pdo);

// ---- one attachment --------------------------------------------------------
if (isset($_GET['file'])) {
    $q = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM bug_attachments WHERE id = ?');
    $q->execute([(int)$_GET['file']]);
    $a = $q->fetch();
    $path = $a ? upload_dir_path() . '/' . $a['stored_name'] : '';
    // basename() guards against a path sneaking in through stored_name, as in
    // attachment.php.
    if (!$a || basename($a['stored_name']) !== $a['stored_name'] || !is_file($path)) {
        bug_export_fail(404, 'That file is missing.');
    }
    header('Content-Type: ' . ($a['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="'
         . str_replace(['"', "\r", "\n"], '', $a['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---- closing or flagging a report -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['bug_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 300));
    $exists = $pdo->prepare('SELECT 1 FROM bug_reports WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) bug_export_fail(404, "There is no bug #{$id}.");

    if (in_array($action, ['fixed', 'wontfix'], true)) {
        $pdo->prepare('UPDATE bug_reports SET status = ?, closed_at = NOW() WHERE id = ?')->execute([$action, $id]);
        if ($flags) {
            $pdo->prepare('UPDATE bug_reports SET fix_note = ?, needs_info = 0 WHERE id = ?')
                ->execute([$note === '' ? null : $note, $id]);
        }
    } elseif ($action === 'open') {
        $pdo->prepare('UPDATE bug_reports SET status = \'open\', closed_at = NULL WHERE id = ?')->execute([$id]);
    } elseif ($action === 'flag') {
        if (!$flags) bug_export_fail(409, 'Flagging needs database update 064.');
        if ($note === '') bug_export_fail(400, 'Say what David needs to describe.');
        $pdo->prepare('UPDATE bug_reports SET needs_info = 1, flag_reason = ? WHERE id = ?')->execute([$note, $id]);
    } else {
        bug_export_fail(400, 'action must be fixed, wontfix, open or flag.');
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'bug_id' => $id, 'action' => $action]);
    exit;
}

// ---- the list --------------------------------------------------------------
$all = ($_GET['status'] ?? 'open') === 'all';
$bugs = $pdo->query('SELECT * FROM bug_reports' . ($all ? '' : " WHERE status = 'open'")
                  . ' ORDER BY created_at, id')->fetchAll();

$files = [];
if ($bugs) {
    $ids = implode(',', array_map(fn($b) => (int)$b['id'], $bugs));
    foreach ($pdo->query("SELECT id, bug_id, original_name, mime_type, file_size
                            FROM bug_attachments WHERE bug_id IN ({$ids}) ORDER BY id") as $f) {
        $files[(int)$f['bug_id']][] = [
            'id'    => (int)$f['id'],
            'name'  => $f['original_name'],
            'mime'  => $f['mime_type'],
            'bytes' => (int)$f['file_size'],
            'kind'  => attachment_is_video($f['mime_type']) ? 'video'
                     : (attachment_is_image($f['mime_type']) ? 'image' : 'file'),
        ];
    }
}

$out = [];
foreach ($bugs as $b) {
    $id = (int)$b['id'];
    $f = $files[$id] ?? [];
    $out[] = [
        'id'          => $id,
        'status'      => $b['status'],
        'section'     => $b['section'],
        'description' => $b['description'],
        'reporter'    => $b['reporter_name'],
        'role'        => $b['role_at_report'],
        'reported_at' => $b['created_at'],
        'page_url'    => $b['page_url'],
        'browser'     => $b['user_agent'],
        'has_video'   => (bool)array_filter($f, fn($x) => $x['kind'] === 'video'),
        'needs_info'  => $flags ? (bool)$b['needs_info'] : false,
        'flag_reason' => $flags ? $b['flag_reason'] : null,
        'admin_notes' => $flags ? $b['admin_notes'] : null,
        'fix_note'    => $flags ? $b['fix_note'] : null,
        'files'       => $f,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'generated_at' => date('c'), 'flags_available' => $flags,
                  'bugs' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
