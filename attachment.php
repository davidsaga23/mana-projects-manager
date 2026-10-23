<?php
/**
 * Serves a ticket attachment to logged-in users only.
 *
 * The uploads directory denies direct web access (see includes/uploads.php), so
 * this is the only route to the files — which keeps attachments behind the same
 * login as everything else instead of sitting on a guessable public URL.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/uploads.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

// Three kinds of owner, one serving route. ?kind=project reads the brief's
// table, ?kind=bug a bug report's (migration 062); anything else reads a
// ticket's. Whitelisted rather than interpolated — the table name comes from
// this file, never from the request.
$kind = (string)($_GET['kind'] ?? '');
$table = ['project' => 'project_attachments', 'bug' => 'bug_attachments'][$kind] ?? 'ticket_attachments';

$q = $pdo->prepare("SELECT stored_name, original_name, mime_type, uploaded_by FROM {$table} WHERE id = ?");
$q->execute([$id]);
$a = $q->fetch();
if (!$a) { http_response_code(404); exit('Not found'); }
// A bug's screenshot may show anything on the reporter's screen, a rate
// included, so it is for the person who filed it and the admins who read the
// reports — the same people bugs.php shows the report to.
if ($table === 'bug_attachments' && !is_admin()
    && (int)$a['uploaded_by'] !== (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(404);
    exit('Not found');
}

$path = upload_dir_path() . '/' . $a['stored_name'];
// basename() guards against any path traversal sneaking in through stored_name.
if (basename($a['stored_name']) !== $a['stored_name'] || !is_file($path)) {
    http_response_code(404);
    exit('That file is missing from the server.');
}

$mime = $a['mime_type'] ?: 'application/octet-stream';
// Shown in the page for media the browser can render; downloaded otherwise.
$disposition = (attachment_is_image($mime) || attachment_is_video($mime)) ? 'inline' : 'attachment';
$safeName = str_replace(['"', "\r", "\n"], '', $a['original_name']);
$size = filesize($path);

// auth.php sends no-store for HTML pages. An attachment is immutable — its
// stored name is random and never reused — so it is safe and much faster to let
// the browser keep it, which matters for video.
header_remove('Pragma');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');

// Range support, so a video can be scrubbed instead of downloaded whole.
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = $m[1] === '' ? 0 : (int)$m[1];
    $end   = $m[2] === '' ? $size - 1 : (int)$m[2];
    if ($start > $end || $end >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    header('Content-Length: ' . ($end - $start + 1));
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}

header('Accept-Ranges: bytes');
header('Content-Length: ' . $size);
readfile($path);
