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
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            try {
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            } catch (PDOException $e) {
                // Column arrives with migration 005 — not worth failing a login over.
            }
            header('Location: index.php');
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
<title>Login · Mana Production Platform</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body class="login-page">
<div class="login-box">
  <h1>Mana Production Platform</h1>
  <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
  <form method="post">
    <label>Username <input name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
  </form>
</div>
</body></html>
