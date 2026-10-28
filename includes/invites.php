<?php
// Turning a staffed person into an Anima login, and the one-time link that lets
// them choose their own password.
//
// David, 2026-10-28: "We need to have a quick way to create the slots profiles
// from the staffing section, to actual users with user name and password. I was
// thinking that you might add a 'Create user' from the detailed view in case that
// hire doesn't have an account created and a 'Resend link' for old users that
// need to log in again to anima for a new project."
//
// ⚠ A PASSWORD IS NEVER EMAILED. Until now an admin typed one on Users and was
// told to pass it on "through something private", which in practice meant a
// WhatsApp message nobody deletes. Instead the person is emailed a LINK that is
// good once, for INVITE_DAYS, and lets them pick their own. The link's token is
// stored HASHED (sha256), so a copy of the database is not a pile of working
// links; only the email holds the real one.
//
// Every link sent is a row in user_invites (migration 071) and that row is also
// the log the ticket asked for: who sent it, when, to which address, for which
// show, and whether it was used. Sending a new link retires the older unused
// ones, so only the latest email works.
//
// Who may press the buttons: Production and admins (can_edit()), the same people
// who staff the show. A link can only ever be sent for an ARTIST or SUPERVISOR
// login by Production; an admin may also send one for a Production login; NOBODY
// may send one for an admin login from here. The reasoning: whoever can send a
// link can, in the worst case, read it off the screen when the email fails (see
// invite_issue()), so it must never let somebody take over an account more
// powerful than their own. Admin passwords stay on Users, by hand.

require_once __DIR__ . '/mailer.php';

const INVITE_DAYS = 7;

/** Has migration 071 run? Every screen asks this first and explains when not. */
function invites_available(PDO $pdo): bool {
    return column_exists($pdo, 'user_invites', 'token_hash')
        && column_exists($pdo, 'users', 'crew_id');
}

/**
 * The login linked to this person, or null. Prefers an active one when, through
 * old hand-linking on Users, there is more than one.
 */
function crew_login(PDO $pdo, int $crewId): ?array {
    if (!column_exists($pdo, 'users', 'crew_id')) return null;
    $seen = column_exists($pdo, 'users', 'last_seen') ? 'COALESCE(last_seen, last_login)' : 'last_login';
    try {
        $q = $pdo->prepare(
            "SELECT id, username, display_name, role, active, {$seen} AS last_seen
               FROM users WHERE crew_id = ? ORDER BY active DESC, id ASC LIMIT 1"
        );
        $q->execute([$crewId]);
        $u = $q->fetch();
    } catch (PDOException $e) {
        return null;   // last_login arrives with migration 005
    }
    if (!$u) return null;
    if ($u['role'] === 'viewer') $u['role'] = 'artist';   // see real_role()
    return $u;
}

/** May the current account send a link for a login with this role? See the header. */
function may_send_invite_for(string $role): bool {
    if ($role === 'admin') return false;
    if ($role === 'editor') return is_admin();
    return can_edit();
}

/**
 * A username to offer for a new login: the part of the email before the @,
 * else the person's first name, cleaned to letters, digits, dot, dash and
 * underscore, lower-case, and made unique by adding a number. Only a suggestion:
 * the form shows it in an editable box.
 */
function suggest_username(PDO $pdo, string $name, string $email): string {
    $base = $email !== '' && strpos($email, '@') !== false ? strstr($email, '@', true) : '';
    if ($base === '' || $base === false) {
        $parts = preg_split('/\s+/', trim($name));
        $base  = (string)($parts[0] ?? '');
    }
    // Accents to plain letters where iconv can, so "Renée" offers "renee"
    // rather than "rene".
    $plain = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) : $base;
    $base  = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($plain !== false ? $plain : $base)));
    $base  = substr($base, 0, 40);
    if ($base === '') $base = 'artist';

    $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $try = $base;
    for ($n = 2; $n < 100; $n++) {
        $chk->execute([$try]);
        if (!$chk->fetchColumn()) return $try;
        $try = $base . $n;
    }
    return $base . bin2hex(random_bytes(2));
}

/** The address a link goes to: the login's own email (migration 049), else the person's. */
function invite_address(PDO $pdo, int $userId, string $crewEmail): string {
    if (column_exists($pdo, 'users', 'email')) {
        $q = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $q->execute([$userId]);
        $own = trim((string)$q->fetchColumn());
        if ($own !== '') return $own;
    }
    return trim($crewEmail);
}

/** Absolute URL of the public page a link opens, built like ticket_url(). */
function invite_url(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'mana.davidsaga.com';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return "{$scheme}://{$host}{$dir}/set_password.php?t=" . rawurlencode($token);
}

/** The site's front door, for the email: the same host and folder, no page. */
function invite_site_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'mana.davidsaga.com';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return "{$scheme}://{$host}{$dir}/";
}

/**
 * Create the login for a staffed person: an ARTIST, active, linked to them,
 * with a password nobody knows (a random one, hashed and thrown away) until
 * they set theirs from the link. Returns the new user id.
 *
 * Throws with a sentence for the screen when the username is taken or empty,
 * or the person already has a login.
 */
function create_login_for_crew(PDO $pdo, int $crewId, string $displayName, string $username): int {
    $username = trim($username);
    if ($username === '') throw new RuntimeException('A username is required.');
    if (strlen($username) > 50) throw new RuntimeException('That username is too long, 50 characters at most.');
    if (!preg_match('/^[A-Za-z0-9._@-]+$/', $username)) {
        throw new RuntimeException('A username may only use letters, numbers, dots, dashes and underscores, no spaces.');
    }
    if (crew_login($pdo, $crewId) !== null) {
        throw new RuntimeException('This person already has a login, use Resend link instead.');
    }
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $chk->execute([$username]);
    if ($chk->fetchColumn()) throw new RuntimeException('The username "' . $username . '" is already taken, pick another.');

    $pdo->prepare(
        "INSERT INTO users (username, password_hash, display_name, role, active, crew_id)
         VALUES (?, ?, ?, 'artist', 1, ?)"
    )->execute([$username, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                $displayName !== '' ? $displayName : $username, $crewId]);
    return (int)$pdo->lastInsertId();
}

/**
 * Make a fresh one-time link for a login, retire its older unused ones, and
 * email it. Returns [sent?, sentence for the screen, the link or ''].
 *
 * The link comes back ONLY when the email did not go out, so the sender can pass
 * it on some other way instead of the whole thing being wasted. That is also why
 * may_send_invite_for() refuses anyone more powerful than the sender.
 *
 * @param string $kind 'create' or 'resend', recorded in the log
 */
function invite_issue(PDO $pdo, array $user, string $email, string $kind,
                      array $project, string $byWho, bool $reactivated): array {
    global $config;
    $token   = bin2hex(random_bytes(32));
    $now     = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + INVITE_DAYS * 86400);

    $pdo->prepare('UPDATE user_invites SET replaced_at = ? WHERE user_id = ? AND used_at IS NULL AND replaced_at IS NULL')
        ->execute([$now, (int)$user['id']]);
    $pdo->prepare(
        'INSERT INTO user_invites (user_id, token_hash, kind, project_id, sent_to, created_by,
                                   created_by_user_id, created_at, expires_at, reactivated)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([(int)$user['id'], hash('sha256', $token), $kind === 'create' ? 'create' : 'resend',
                (int)$project['id'] ?: null, $email !== '' ? $email : null, $byWho,
                (int)($_SESSION['user_id'] ?? 0) ?: null, $now, $expires, $reactivated ? 1 : 0]);
    $inviteId = (int)$pdo->lastInsertId();
    $url = invite_url($token);

    if ($email === '') {
        return [false, 'There is no email address for them, so nothing was sent. Copy the link below '
                     . 'and send it to them privately; it works once, for ' . INVITE_DAYS . ' days.', $url];
    }
    if (!SmtpMailer::isConfigured($config)) {
        return [false, 'Email is not set up on this server, so nothing was sent. Copy the link below '
                     . 'and send it to them privately; it works once, for ' . INVITE_DAYS . ' days.', $url];
    }

    $name     = (string)($user['display_name'] ?: $user['username']);
    $show     = (string)($project['name'] ?? '');
    $site     = invite_site_url();
    $until    = date('j M Y', strtotime($expires));
    $esc      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $subject  = $show !== '' ? "Your Anima login for {$show}" : 'Your Anima login';
    $opening  = $kind === 'create'
        ? 'Mana Animation Studio has made you a login for Anima, the studio\'s production tracker'
        : 'Here is a fresh link to get back into Anima, Mana Animation Studio\'s production tracker';
    $opening .= $show !== '' ? ", for {$show}." : '.';

    $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
        . '<p>Hi ' . $esc($name) . ',</p>'
        . '<p>' . $esc($opening) . '</p>'
        . '<p>Your username is <strong>' . $esc($user['username']) . '</strong>. Choose your password here:</p>'
        . '<p style="margin:16px 0;"><a href="' . $esc($url) . '" '
        . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
        . 'Set my password</a></p>'
        . '<p style="color:#5a6a7a;">The link works once and until ' . $esc($until) . '. After that, log in at '
        . '<a href="' . $esc($site) . '">' . $esc($site) . '</a> with your username and the password you chose.</p>'
        . '<p style="color:#5a6a7a;">If you were not expecting this, you can ignore it.</p>'
        . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
        . '</div>';
    $text = "Hi {$name},\n\n{$opening}\n\nYour username is {$user['username']}. Choose your password here:\n{$url}\n\n"
        . "The link works once and until {$until}. After that, log in at {$site} with your username "
        . "and the password you chose.\n\nIf you were not expecting this, you can ignore it.\n";

    try {
        (new SmtpMailer($config))->send([['email' => $email, 'name' => $name]], $subject, $html, $text);
        try {
            $pdo->prepare('UPDATE user_invites SET email_sent = 1 WHERE id = ?')->execute([$inviteId]);
        } catch (PDOException $e) {
            // Only the log's tick; the email has gone either way.
        }
        return [true, "Emailed {$email} a link to set their password. It works once, for " . INVITE_DAYS . ' days.', ''];
    } catch (Throwable $e) {
        error_log('[Anima invite mail] ' . $e->getMessage());
        return [false, 'The email to ' . $email . ' failed (' . $e->getMessage() . '). Copy the link below and '
                     . 'send it to them privately; it works once, for ' . INVITE_DAYS . ' days.', $url];
    }
}

/** The latest link sent for a login, for the person page's "last sent" line. */
function last_invite(PDO $pdo, int $userId): ?array {
    try {
        $q = $pdo->prepare('SELECT * FROM user_invites WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$userId]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * What a token from a link is: [state, invite row + username or null].
 *   ok       — good to use
 *   used     — already used to set a password
 *   replaced — a newer link was sent since
 *   expired  — past INVITE_DAYS
 *   unknown  — not a link this site sent (or the login was deleted)
 *
 * ⚠ The page that calls this says nothing about WHOSE link a bad one was, and
 * there is no "email me a link" form anywhere, so nothing here can be used to
 * find out whether an address or a username exists.
 */
function invite_lookup(PDO $pdo, string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return ['unknown', null];
    try {
        $q = $pdo->prepare(
            'SELECT i.*, u.username, u.display_name, u.active
               FROM user_invites i JOIN users u ON u.id = i.user_id
              WHERE i.token_hash = ?'
        );
        $q->execute([hash('sha256', $token)]);
        $row = $q->fetch();
    } catch (PDOException $e) {
        return ['unknown', null];   // migration 071 pending
    }
    if (!$row) return ['unknown', null];
    if ($row['used_at'] !== null) return ['used', $row];
    if ($row['replaced_at'] !== null) return ['replaced', $row];
    if (strtotime((string)$row['expires_at']) < time()) return ['expired', $row];
    return ['ok', $row];
}

/**
 * Use a link: set the password and burn the link, in one transaction. The
 * UPDATE claims the link only while it is still unused, so two tabs submitting
 * at once cannot both succeed. Returns the user row to log in as.
 */
function invite_redeem(PDO $pdo, string $token, string $password): array {
    [$state, $row] = invite_lookup($pdo, $token);
    if ($state !== 'ok') throw new RuntimeException('This link can no longer be used.');
    if ((int)$row['active'] !== 1) {
        throw new RuntimeException('Your account has been switched off since this link was sent. Ask production to send a new one.');
    }
    if (strlen($password) < 8) throw new RuntimeException('The password must be at least 8 characters.');

    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE user_invites SET used_at = ? WHERE id = ? AND used_at IS NULL AND replaced_at IS NULL');
        $claim->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
        if ($claim->rowCount() !== 1) throw new RuntimeException('This link can no longer be used.');
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['id' => (int)$row['user_id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
}
