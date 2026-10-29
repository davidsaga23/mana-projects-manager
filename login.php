<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$err = null;
if (isset($_GET['disabled'])) {
    $err = 'That account has been switched off. Ask an administrator to switch it back on.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT id, username, password_hash, display_name FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Roles arrive in migration 005; before it has run, every account is
        // simply active — checking separately keeps login working either way.
        $active = true;
        try {
            $chk = $pdo->prepare('SELECT active FROM users WHERE id = ?');
            $chk->execute([$user['id']]);
            $active = (int)$chk->fetchColumn() === 1;
        } catch (PDOException $e) {
            $active = true;
        }

        if (!$active) {
            $err = 'That account has been switched off. Ask an administrator to switch it back on.';
        } else {
            session_regenerate_id(true);
            // A LOGIN NEVER INHERITS A PREVIEW. session_regenerate_id() carries
            // $_SESSION across, so signing in over a session that was previewing
            // as an artist would drop the new arrival straight into somebody
            // else's preview — harmless, since a preview only ever reduces what
            // you can do, but baffling.
            unset($_SESSION['preview_role']);
            // Where require_login() was sent from, if anywhere. Only a path on
            // this site: one leading slash, not two and not a backslash, which
            // would otherwise send the browser to another host.
            $next = (string)($_SESSION['after_login'] ?? '');
            unset($_SESSION['after_login']);
            $second = $next[1] ?? '';
            if ($next === '' || $next[0] !== '/' || $second === '/' || $second === chr(92)) {
                $next = 'index.php';
                // ARTISTS AND SUPERVISORS LAND ON HOME (2026-10-28): their own
                // shows, logs, notes and invoice information, rather than the
                // list of every project. Only with a crew link, since Home is a
                // person's; Production and admins keep the project list. Read
                // straight from the row: current_user() may already have
                // cached "nobody" for this request.
                try {
                    $lq = $pdo->prepare('SELECT role, crew_id FROM users WHERE id = ?');
                    $lq->execute([$user['id']]);
                    $lr = $lq->fetch() ?: [];
                    if (in_array((string)($lr['role'] ?? ''), ['artist', 'viewer', 'supervisor'], true)
                        && (int)($lr['crew_id'] ?? 0) > 0) {
                        $next = 'home.php';
                    }
                } catch (PDOException $e) {
                    // roles (005) or the crew link (017) not there yet: the project list
                }
            }
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            try {
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            } catch (PDOException $e) {
                // Column arrives with migration 005 — not worth failing a login over.
            }
            header('Location: ' . $next);
            exit;
        }
    } else {
        $err = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Anima | Project manager</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body class="login-page">
<div class="login-box">
  <div class="login-brand"><?= site_logo('site-logo site-logo-lg') ?></div>
  <h1>Anima <span class="muted">| Project manager</span></h1>
  <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
  <form method="post">
    <label>Username <input name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
  </form>
</div>
</body></html>
