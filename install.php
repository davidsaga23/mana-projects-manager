<?php
// -----------------------------------------------------------------------------
// One-time installer.
// Run ONCE after importing install.sql. Creates the admin user.
// DELETE THIS FILE from the server afterwards (security).
//
// Unlike STFC's installer this seeds no shots — projects are created through
// the setup wizard.
// -----------------------------------------------------------------------------

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$done = false; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $display  = trim($_POST['display_name'] ?? '');
    try {
        if ($username === '' || strlen($password) < 8) {
            throw new Exception('Username required, password min 8 chars.');
        }
        // Check if already installed (users exist)
        $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            throw new Exception('Users already exist. Delete install.php now, installer is closed.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?, ?, ?)');
        $stmt->execute([$username, $hash, $display ?: $username]);
        $done = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · Anima | Project manager</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body class="login-page">
<div class="login-box">
  <div class="login-brand"><?= site_logo('site-logo site-logo-lg') ?></div>
  <h1>Anima <span class="muted">| Project manager</span> · Installer</h1>
  <?php if ($done): ?>
    <p class="ok">✓ Admin user created.</p>
    <p><strong>Now delete <code>install.php</code> from the server for security.</strong></p>
    <p>Then open <a href="migrate.php">migrate.php</a> once to apply any schema
       migrations added since the baseline.</p>
    <p><a href="login.php">Go to login →</a></p>
  <?php else: ?>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <p>Create the first admin account. Password must be at least 8 characters.</p>
    <form method="post">
      <label>Username <input name="username" required></label>
      <label>Display name <input name="display_name" placeholder="David"></label>
      <label>Password <input type="password" name="password" required minlength="8"></label>
      <button type="submit">Create admin</button>
    </form>
  <?php endif; ?>
</div>
</body></html>
