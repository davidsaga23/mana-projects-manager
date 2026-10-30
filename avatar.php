<?php
/**
 * A person's profile picture, to logged-in users only (migration 074).
 *
 * David, 2026-10-30: a profile picture on Home's General info tab. The uploads
 * directory denies direct web access (see includes/uploads.php), so this is the
 * route to the file, the same way attachment.php is for every other upload. A
 * picture is not private within the studio, so anybody signed in may see one.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/uploads.php';
require_login();

$crewId = (int)($_GET['crew'] ?? 0);
if ($crewId <= 0 || !column_exists($pdo, 'crew', 'photo_stored')) { http_response_code(404); exit('Not found'); }

$q = $pdo->prepare('SELECT photo_stored, photo_mime FROM crew WHERE id = ?');
$q->execute([$crewId]);
$p = $q->fetch();
$stored = (string)($p['photo_stored'] ?? '');
// basename() guards against any path traversal sneaking in through the name.
if ($stored === '' || basename($stored) !== $stored) { http_response_code(404); exit('Not found'); }
$path = upload_dir_path() . '/' . $stored;
if (!is_file($path)) { http_response_code(404); exit('Not found'); }

$mime = (string)($p['photo_mime'] ?? '');
if (!attachment_is_image($mime)) { http_response_code(404); exit('Not found'); }

// A new picture gets a new random name, so the old address is never reused and
// the browser may keep it.
header_remove('Pragma');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
