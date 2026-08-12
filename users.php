<?php
// Who can log in, and what they may do.
//
// Roles are enforced centrally in includes/auth.php: view-only accounts have
// every POST refused there, so a screen built later is covered without anyone
// remembering to add a check.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

$currentUserId = (int)$_SESSION['user_id'];
const USER_ROLES = ['admin', 'editor', 'viewer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $display  = trim($_POST['display_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'viewer';

            if ($username === '')        throw new RuntimeException('A username is required.');
            if (strlen($password) < 8)   throw new RuntimeException('The password must be at least 8 characters.');

            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $chk->execute([$username]);
            if ($chk->fetch()) throw new RuntimeException('That username is already taken.');

            $pdo->prepare(
                'INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)'
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display ?: $username, $role]);

            flash('ok', "Created the account \"$username\" as $role. Send the password through " .
                        'something private — a password manager or a direct message, not email.');
        }

        if ($action === 'set_role') {
            $id   = (int)($_POST['user_id'] ?? 0);
            $role = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'viewer';

            // Losing the last admin would leave nobody able to manage accounts,
            // and no way back in short of editing the database by hand.
            if ($id === $currentUserId && $role !== 'admin') {
                $others = (int)$pdo->query(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> $currentUserId"
                )->fetchColumn();
                if ($others === 0) {
                    throw new RuntimeException('You are the only administrator — promote someone else first.');
                }
            }
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash('ok', 'Role updated. It applies on their next page view.');
        }

        if ($action === 'set_active') {
            $id = (int)($_POST['user_id'] ?? 0);
            $on = !empty($_POST['active']) ? 1 : 0;
            if ($id === $currentUserId && !$on) {
                throw new RuntimeException("You can't switch off your own account.");
            }
            $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$on, $id]);
            flash('ok', $on ? 'Account switched back on.'
                            : 'Account switched off. They are logged out on their next page view.');
        }

        if ($action === 'password') {
            $id = (int)($_POST['user_id'] ?? 0);
            $new = $_POST['new_password'] ?? '';
            if (strlen($new) < 8) throw new RuntimeException('The password must be at least 8 characters.');
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            flash('ok', 'Password changed.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === $currentUserId) {
                throw new RuntimeException("You can't delete your own account while logged in.");
            }
            $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('ok', $name ? "Deleted the account \"$name\"." : 'That account no longer exists.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: users.php');
    exit;
}

$users = $pdo->query(
    'SELECT id, username, display_name, role, active, created_at, last_login
       FROM users ORDER BY active DESC, created_at ASC'
)->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Users</h2>

<p class="lead">Who can log in, and what they're allowed to do.</p>

<table class="review-table">
  <thead>
    <tr><th>Username</th><th>Name</th><th>Role</th><th>Active</th><th>Last seen</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): $id = (int)$u['id']; $isSelf = $id === $currentUserId; ?>
    <tr class="<?= $u['active'] ? '' : 'row-inactive' ?>">
      <td>
        <strong><?= e($u['username']) ?></strong>
        <?php if ($isSelf): ?><span class="muted small">(you)</span><?php endif; ?>
      </td>
      <td><?= e($u['display_name'] ?: '—') ?></td>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="set_role">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <select name="role" onchange="this.form.submit()">
            <option value="admin"  <?= $u['role']==='admin'  ? 'selected':'' ?>>Admin</option>
            <option value="editor" <?= $u['role']==='editor' ? 'selected':'' ?>>Editor</option>
            <option value="viewer" <?= $u['role']==='viewer' ? 'selected':'' ?>>View only</option>
          </select>
        </form>
      </td>
      <td>
        <?php if ($isSelf): ?>
          <span class="muted">—</span>
        <?php else: ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="set_active">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <input type="hidden" name="active" value="<?= $u['active'] ? '0' : '1' ?>">
            <button type="submit" class="secondary"><?= $u['active'] ? 'Switch off' : 'Switch on' ?></button>
          </form>
        <?php endif; ?>
      </td>
      <td class="muted small"><?= e($u['last_login'] ? substr($u['last_login'], 0, 16) : 'never') ?></td>
      <td class="row-actions">
        <details class="inline-actions">
          <summary>Set password</summary>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <input type="password" name="new_password" placeholder="At least 8 characters" required minlength="8">
            <button type="submit" class="secondary">Change</button>
          </form>
        </details>
        <?php if (!$isSelf): ?>
          <form method="post" class="inline-form"
                onsubmit="return confirm('Delete <?= e(addslashes($u['username'])) ?> permanently? Switching the account off is usually better.');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <button type="submit" class="danger">Delete</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h3>What each role can do</h3>
<table class="review-table role-table">
  <tr><th>Role</th><th>Can</th><th>Cannot</th></tr>
  <tr>
    <td><strong>Admin</strong></td>
    <td>Everything, including this screen</td>
    <td class="muted">—</td>
  </tr>
  <tr>
    <td><strong>Editor</strong></td>
    <td>Set up projects, import, edit statuses, fix aliases</td>
    <td class="muted">Add or change user accounts</td>
  </tr>
  <tr>
    <td><strong>View only</strong></td>
    <td>Open and read every project screen</td>
    <td class="muted">Change anything at all</td>
  </tr>
</table>
<p class="muted small">
  View-only is enforced in one place rather than screen by screen, so it also covers
  screens built later. Money, contracts and rates aren't in this application at all —
  they live in a separate system with its own accounts, and no role here reaches them.
</p>

<h3>Add someone</h3>
<form method="post" class="stack">
  <input type="hidden" name="action" value="add">
  <div class="row">
    <label>Username <input name="username" required placeholder="e.g. renata"></label>
    <label>Display name <input name="display_name" placeholder="e.g. Renata Ruiz"></label>
  </div>
  <div class="row">
    <label>Password <input type="password" name="password" required minlength="8" placeholder="At least 8 characters"></label>
    <label>Role
      <select name="role">
        <option value="viewer" selected>View only — can look, can't change</option>
        <option value="editor">Editor — full production access</option>
        <option value="admin">Admin — full access, can manage users</option>
      </select>
    </label>
  </div>
  <div class="actions"><button type="submit">Create account</button></div>
</form>
<p class="muted small">
  Send the password through something private — a password manager or a direct message.
  Ask them to change it once they're in.
</p>

</div>
</body></html>
