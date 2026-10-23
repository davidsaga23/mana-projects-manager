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

    // ⚠ AN OWNERLESS ATTACHMENT IS LEGAL ON A COMMENT, SINCE MIGRATION 061, and
    // only there. A comment on a Board task has no ticket (migration 059), but
    // it does have an id, and comment_id is the link that matters — the file
    // belongs to what somebody said, not to a card. Without a comment there is
    // nothing for the row to hang off at all, which is a bug, not a file.
    if ($ownerId <= 0 && !($hasComment && $commentId !== null)) {
        return ['saved' => 0, 'errors' => ['Nothing to attach that file to, so it was not saved.']];
    }
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
        $ok = accept_upload($f, $dir, $errors);
        if ($ok === null) continue;

        $owner = $ownerId > 0 ? $ownerId : null;      // NULL on a board comment
        $args = $hasComment
            ? [$owner, $commentId, $ok['stored'], $ok['name'], $ok['mime'], $ok['size'], $userId]
            : [$owner, $ok['stored'], $ok['name'], $ok['mime'], $ok['size'], $userId];
        $ins->execute($args);
        $saved++;
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Check ONE uploaded file and move it into the uploads folder.
 *
 * ⚠ THIS IS THE WHOLE DEFENCE, and it is why there is one copy of it. It was
 * inline in save_attachments() until versions needed the same checks
 * (migration 063); a second copy is a second place for somebody to leave out
 * the MIME test, which is the one that stops a .jpg that is really a .php.
 *
 * The size and type rules come from config, so a studio can widen them in one
 * place. The stored name is random — never the name the uploader chose, which
 * is a path, a duplicate and a collision all at once.
 *
 * @param array $errors appended to with a sentence per refusal, by reference
 * @return array|null ['stored','name','mime','size'] or null if refused
 */
function accept_upload(array $f, string $dir, array &$errors): ?array {
    global $config;
    $maxBytes = (int)($config['upload_max_bytes'] ?? 25 * 1024 * 1024);
    $allowed = allowed_upload_types();

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = $f['name'] . ': upload failed (' . upload_error_text((int)$f['error']) . ').';
        return null;
    }
    if ($f['size'] > $maxBytes) {
        $errors[] = $f['name'] . ': too large (' . human_size((int)$f['size'])
                  . ', max ' . human_size($maxBytes) . ').';
        return null;
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        $errors[] = $f['name'] . ': that file type is not allowed.';
        return null;
    }
    // Trust the file's contents, not the name someone gave it.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']) ?: '';
    if (!in_array($mime, $allowed[$ext], true)) {
        $errors[] = $f['name'] . ": the contents don't match the file type ({$mime}).";
        return null;
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        $errors[] = $f['name'] . ': could not be saved to disk.';
        return null;
    }

    return [
        'stored' => $stored,
        'name'   => mb_substr($f['name'], 0, 255),
        'mime'   => $mime,
        'size'   => (int)$f['size'],
    ];
}

/** "v01". The number is the record; the padding is presentation. */
function version_label(int $n): string {
    return 'v' . str_pad((string)max(1, $n), 2, '0', STR_PAD_LEFT);
}

/**
 * Put one or more new versions up against a task (migration 063).
 *
 * David, 2026-10-22: "versions will start at v01 and increment by 1 every time
 * a new version is up". So the caller never picks a number — this does, from
 * what is already there.
 *
 * ⚠ THE NUMBER IS READ AND WRITTEN INSIDE ONE TRANSACTION, and the table
 * carries UNIQUE (progress_id, version_no). Two people uploading in the same
 * second therefore collide loudly instead of quietly sharing v04, which is the
 * bargain next_ticket_seq() makes for a ticket's reference.
 *
 * Several files in one go become several versions, in the order they were
 * picked: six takes of a shot are six versions, not one version with six files.
 *
 * @return array{saved:int, errors:string[], latest:int}
 */
function save_task_versions(PDO $pdo, array $files, int $progressId, int $userId,
                            string $note = ''): array {
    global $config;
    if ($progressId <= 0) {
        return ['saved' => 0, 'errors' => ['There is no task to put that against.'], 'latest' => 0];
    }
    $maxFiles = (int)($config['upload_max_files'] ?? 5);
    $dir = ensure_upload_dir();
    $errors = [];
    $saved = 0;

    $q = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM progress_versions WHERE progress_id = ?');
    $q->execute([$progressId]);
    $latest = (int)$q->fetchColumn();

    $ins = $pdo->prepare(
        'INSERT INTO progress_versions
           (progress_id, version_no, stored_name, original_name, mime_type, file_size, note, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $cleanNote = trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;

    foreach ($files as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($saved >= $maxFiles) {
            $errors[] = "Only {$maxFiles} files at a time, the rest were skipped.";
            break;
        }
        $ok = accept_upload($f, $dir, $errors);
        if ($ok === null) continue;

        try {
            $pdo->beginTransaction();
            $n = $pdo->prepare(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM progress_versions WHERE progress_id = ?'
            );
            $n->execute([$progressId]);
            $no = (int)$n->fetchColumn();
            $ins->execute([$progressId, $no, $ok['stored'], $ok['name'], $ok['mime'],
                           $ok['size'], $cleanNote, $userId ?: null]);
            $pdo->commit();
            $latest = max($latest, $no);
            $saved++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // The row failed, so the file on disk belongs to nothing. Remove it
            // rather than leave a megabyte nobody can ever reach.
            @unlink($dir . '/' . $ok['stored']);
            $errors[] = $ok['name'] . ': that version could not be recorded.';
        }
    }

    // The label everything else reads. Derived now, never typed.
    if ($saved > 0) {
        $pdo->prepare('UPDATE progress SET version = ? WHERE id = ?')
            ->execute([version_label($latest), $progressId]);
    }

    return ['saved' => $saved, 'errors' => $errors, 'latest' => $latest];
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

/**
 * One attachment, as a thumbnail, a player or a file link.
 *
 * ⚠ MOVED HERE FROM ticket.php ON 2026-10-22, when David asked for files on
 * Board comments too ("allow comments to attach files"). It was page-scoped
 * while one page drew attachments; two screens later it is shared, and the
 * alternative was a second copy that would have drifted the first time one of
 * them learned a new media type.
 *
 * $deleteControl is the markup for removing it, because removing one is the
 * only part each screen does differently: the ticket posts a form to itself,
 * the board sends a button to its own endpoint. An empty string draws none.
 */
function render_attachment(array $a, bool $canDelete, string $deleteControl = ''): void {
    $url  = 'attachment.php?id=' . (int)$a['id'];
    $name = e($a['original_name']);
    echo '<div class="attachment">';
    if (attachment_is_image($a['mime_type'])) {
        // The href is real and still works — ctrl-click, middle-click and a
        // browser with JavaScript off all open the file directly. The click
        // handler in tickets.js intercepts an ordinary click and shows the image
        // full size over this page instead, so looking at a reference does not
        // cost you the ticket you were reading.
        echo '<a href="' . $url . '" class="att-zoom" data-name="' . $name . '"'
           . ' target="_blank" rel="noopener"><img src="' . $url . '" alt="' . $name . '"></a>';
    } elseif (attachment_is_video($a['mime_type'])) {
        echo '<video src="' . $url . '" controls preload="metadata"></video>';
    } else {
        echo '<a href="' . $url . '" class="file-link" target="_blank" rel="noopener">📄 ' . $name . '</a>';
    }
    echo '<div class="attachment-meta"><span title="' . $name . '">' . $name . '</span>';
    echo '<span class="muted small">' . human_size((int)$a['file_size']) . '</span>';
    if ($canDelete) {
        echo $deleteControl !== '' ? $deleteControl
           : '<form method="post" style="display:inline;" onsubmit="return confirm(\'Remove this attachment?\');">'
             . '<button type="submit" name="delete_attachment" value="' . (int)$a['id'] . '" class="link-danger">×</button>'
             . '</form>';
    }
    echo '</div></div>';
}
