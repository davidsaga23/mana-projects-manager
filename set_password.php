<?php
// The page a "set your password" link opens. PUBLIC: no login needed, because
// the person arriving here does not have a working password yet. What they DO
// have is the link, and the token in it is the whole of the permission
// (includes/invites.php says how it is made, stored and retired).
//
// Deliberately says nothing about whose link a bad one was. An expired, used or
// replaced link gets a plain sentence and "ask production for a new one"; an
// unknown one gets the same treatment as a mistyped address. There is no "email
// me a link" box, so nothing here can be used to test whether an address or a
// username exists.
//
// On success they are logged straight in, the same way login.php does it, and
// land on the front page: the link was a way in, and making them type the
// password again a second later proves nothing.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/invites.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
[$state, $invite] = invite_lookup($pdo, $token);
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $state === 'ok') {
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    try {
        if ($pw !== $pw2) throw new RuntimeException('The two passwords are not the same, try again.');
        $user = invite_redeem($pdo, $token, $pw);

        // As login.php: a fresh session id, never a leftover preview.
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        try {
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        } catch (PDOException $e) {
            // Column arrives with migration 005, not worth failing over.
        }
        flash('ok', 'Your password is set and you are logged in. Next time, log in with "'
                  . $user['username'] . '" and that password.');
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        [$state, $invite] = invite_lookup($pdo, $token);
    }
}

$problem = [
    'used'     => 'This link has already been used to set a password. Log in with your username and that password, or ask production to send you a new link.',
    'replaced' => 'A newer link has been sent since this one, so this one no longer works. Use the link in the most recent email.',
    'expired'  => 'This link has expired; they last ' . INVITE_DAYS . ' days. Ask production to send you a new one.',
    'unknown'  => 'This link is not valid. Check that the whole address was copied from the email, or ask production to send you a new one.',
][$state] ?? null;
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Set your password · Anima | Project manager</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body class="login-page">
<div class="login-box">
  <div class="login-brand"><?= site_logo('site-logo site-logo-lg') ?></div>
  <h1>Anima <span class="muted">| Project manager</span></h1>
  <?php if ($problem !== null): ?>
    <p class="err"><?= e($problem) ?></p>
    <p><a href="login.php">Go to the login page</a></p>
  <?php else: ?>
    <p>Choose a password for <strong><?= e($invite['username']) ?></strong>.
       That is your username from now on.</p>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="set_password.php">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="text" name="username" value="<?= e($invite['username']) ?>" autocomplete="username"
             readonly hidden>
      <label>New password <input type="password" name="password" required minlength="8"
             autocomplete="new-password" autofocus></label>
      <label>The same again <input type="password" name="password2" required minlength="8"
             autocomplete="new-password"></label>
      <button type="submit">Set password and log in</button>
    </form>
    <p class="muted small">At least 8 characters. This link works once.</p>
  <?php endif; ?>
</div>
</body></html>
