<?php
/**
 * Ticket attachment handling. Ported from STFC's includes/uploads.php.
 *
 * Security notes — these matter because an upload directory on shared hosting is
 * otherwise a straightforward path to running code on the server:
 *   - extension AND detected MIME type are both whitelisted
 *   - stored filenames are random; the user's name is kept only in the database
 *   - the directory gets an .htaccess that disables PHP execution and denies
 *     direct web access
 *   - files are served through attachment.php (login-gated), never linked to
 *
 * uploads/ is excluded from git AND from the FTP deploy
 * (.github/workflows/deploy.yml). If that exclusion is ever removed, a deploy
 * deletes every attachment on the server.
 */

const UPLOAD_SUBDIR = 'uploads/tickets';

/** Allowed extension => the MIME types its contents may actually be. */
function allowed_upload_types(): array {
    return [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'mp4'  => ['video/mp4'],
        'mov'  => ['video/quicktime', 'video/mp4'],
        'webm' => ['video/webm'],
        'pdf'  => ['application/pdf'],
    ];
}

function upload_dir_path(): string {
    return dirname(__DIR__) . '/' . UPLOAD_SUBDIR;
}

/**
 * Create the upload directory and drop in the hardening .htaccess.
 * Called before the first write, so a fresh install needs no manual setup.
 */
function ensure_upload_dir(): string {
    $dir = upload_dir_path();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, implode("\n", [
            '# Never execute anything in here, these are user uploads.',
            'php_flag engine off',
            'RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps',
            'RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps',
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine Off',
            '</IfModule>',
            '# Deny direct access; files are served via attachment.php instead.',
            '<IfModule mod_authz_core.c>',
            '  Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '  Order allow,deny',
            '  Deny from all',
            '</IfModule>',
            '',
        ]));
    }
    return $dir;
}

function human_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Persist uploaded files against a ticket.
 *
 * Reports every file it refused and why. A file silently missing from a ticket
 * is the "nothing happened" failure this codebase keeps paying for — an upload
 * that drops a 40 MB video without a word looks exactly like a working one.
 *
 * @param array $files  re-shaped $_FILES entry (see normalize_files_array)
 * @return array{saved:int, errors:string[]}
 */
function save_ticket_attachments(PDO $pdo, array $files, int $ticketId, ?int $commentId, int $userId): array {
    return save_attachments($pdo, $files, 'ticket_attachments', 'ticket_id', $ticketId, $commentId, $userId);
}

/**
 * Save files against ANY owner — a ticket or a project.
 *
 * Generalised when the project brief needed attachments (migration 023). One
 * implementation on purpose: the validation below is the whole defence, and a
 * second copy of it is a second place for someone to leave out the MIME check.
 * Only the destination table and its foreign key differ.
 *
 * @param string $table    'ticket_attachments', 'project_attachments' or 'bug_attachments'
 * @param string $fkColumn the owning column in that table
 */
function save_attachments(PDO $pdo, array $files, string $table, string $fkColumn,
                          int $ownerId, ?int $commentId, int $userId): array {
    global $config;

    // Whitelisted, never interpolated from anything a request controls.
    if (!in_array($table, ['ticket_attachments', 'project_attachments', 'bug_attachments'], true)) {
        return ['saved' => 0, 'errors' => ['Unknown attachment type, nothing was saved.']];
    }
    $hasComment = $table === 'ticket_attachments';
    $maxBytes = (int)($config['upload_max_bytes'] ?? 25 * 1024 * 1024);
    $maxFiles = (int)($config['upload_max_files'] ?? 5);
    $allowed = allowed_upload_types();

    $dir = ensure_upload_dir();
    $errors = [];
    $saved = 0;

    // Count what is already attached at this level, so the cap covers the whole
    // ticket rather than each submission.
    $existing = 0;
    if ($commentId === null) {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$fkColumn} = ?"
             . ($hasComment ? ' AND comment_id IS NULL' : '');
        $c = $pdo->prepare($sql);
        $c->execute([$ownerId]);
        $existing = (int)$c->fetchColumn();
    }

    $ins = $pdo->prepare($hasComment
        ? "INSERT INTO {$table}
             ({$fkColumn}, comment_id, stored_name, original_name, mime_type, file_size, uploaded_by)
           VALUES (?, ?, ?, ?, ?, ?, ?)"
        : "INSERT INTO {$table}
             ({$fkColumn}, stored_name, original_name, mime_type, file_size, uploaded_by)
           VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($files as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

        if ($saved + $existing >= $maxFiles) {
            $errors[] = "Only {$maxFiles} files allowed, the rest were skipped.";
            break;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $f['name'] . ': upload failed (' . upload_error_text((int)$f['error']) . ').';
            continue;
        }
        if ($f['size'] > $maxBytes) {
            $errors[] = $f['name'] . ': too large (' . human_size((int)$f['size'])
                      . ', max ' . human_size($maxBytes) . ').';
            continue;
        }

        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            $errors[] = $f['name'] . ': that file type is not allowed.';
            continue;
        }
        // Trust the file's contents, not the name someone gave it.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']) ?: '';
        if (!in_array($mime, $allowed[$ext], true)) {
            $errors[] = $f['name'] . ": the contents don't match the file type ({$mime}).";
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            $errors[] = $f['name'] . ': could not be saved to disk.';
            continue;
        }

        $args = $hasComment
            ? [$ownerId, $commentId, $stored, mb_substr($f['name'], 0, 255), $mime, (int)$f['size'], $userId]
            : [$ownerId, $stored, mb_substr($f['name'], 0, 255), $mime, (int)$f['size'], $userId];
        $ins->execute($args);
        $saved++;
    }

    return ['saved' => $saved, 'errors' => $errors];
}

function upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'exceeds the server size limit',
        UPLOAD_ERR_PARTIAL    => 'only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'the server has no temp directory',
        UPLOAD_ERR_CANT_WRITE => 'the server could not write the file',
        UPLOAD_ERR_EXTENSION  => 'blocked by a server extension',
        default               => 'error code ' . $code,
    };
}

/**
 * PHP gives multi-file inputs as parallel arrays; flip that into a list of
 * per-file arrays so callers can just foreach it.
 */
function normalize_files_array(array $entry): array {
    if (!isset($entry['name'])) return [];
    if (!is_array($entry['name'])) return [$entry];
    $out = [];
    foreach (array_keys($entry['name']) as $i) {
        $out[] = [
            'name'     => $entry['name'][$i],
            'type'     => $entry['type'][$i] ?? '',
            'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error'    => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $entry['size'][$i] ?? 0,
        ];
    }
    return $out;
}

/** True when this attachment can be shown inline as an image. */
function attachment_is_image(?string $mime): bool {
    return $mime !== null && str_starts_with($mime, 'image/');
}

function attachment_is_video(?string $mime): bool {
    return $mime !== null && str_starts_with($mime, 'video/');
}
