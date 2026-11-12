<?php
// Serves the image attached to one Realm chat message (migration 083).
//
// David, 2026-11-12: "let's also allow images ... in all chats". The file lives
// in the uploads folder, which refuses direct web access (includes/uploads.php),
// so this is the only way to it, exactly as attachment.php is for a ticket's.
//
// ⚠ AN IMAGE IS AS PRIVATE AS THE CONVERSATION IT WAS SENT IN. The message id in
// the address is only a number anybody can change, so the conversation is read
// from the message and checked with realm_channel_access(): a picture sent by
// raven is served to the two people in that raven and nobody else, an admin
// included, and a show's channel only to people who may see that show. Anybody
// else gets "not found", the same answer as for a message that does not exist.
//
// Not inside a preview, like the rest of the Realm.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_once __DIR__ . '/includes/realm_chat.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$uid = realm_user_id();
session_write_close();

$row = null;
if ($id > 0 && !in_preview() && realm_chat_available($pdo) && realm_chat_images($pdo)) {
    try {
        $q = $pdo->prepare('SELECT channel, image, image_name, image_mime FROM realm_messages WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch() ?: null;
        if ($row && !realm_channel_access($pdo, $uid, (string)$row['channel'], can_edit())['read']) $row = null;
    } catch (Throwable $e) {
        $row = null;
    }
}
$stored = (string)($row['image'] ?? '');
$path = upload_dir_path() . '/' . $stored;
// basename() guards against any path traversal sneaking in through the stored name.
if ($stored === '' || basename($stored) !== $stored || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = (string)($row['image_mime'] ?: 'application/octet-stream');
// Only ever shown in the page for a real picture; anything else would download.
$disposition = attachment_is_image($mime) ? 'inline' : 'attachment';
$safeName = str_replace(['"', "\r", "\n"], '', (string)($row['image_name'] ?: 'image'));

// The stored name is random and never reused, so the browser may keep the file.
header_remove('Pragma');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
