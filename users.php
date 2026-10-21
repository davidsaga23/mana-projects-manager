<?php
// Who can log in, and what they may do.
//
// Roles are enforced centrally in includes/auth.php: view-only accounts have
// every POST refused there, so a screen built later is covered without anyone
// remembering to add a check.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crew.php';
require_admin();

$currentUserId = (int)$_SESSION['user_id'];
const USER_ROLES = ['admin', 'editor', 'supervisor', 'artist'];

// The column only accepts 'supervisor' once migration 047 has run. Before that a
// strict server refuses the save, and a lax one quietly stores an empty role,
// which locks the person into read-only with no status changes either. So the
// role is neither offered nor accepted until the column can hold it.
$hasSupervisor = migration_applied($pdo, '047_role_supervisor.sql');

// Which PERSON a login is. Arrives with the tickets phase (migration 017);
// hidden rather than breaking the screen if that hasn't run.
//
// Why this matters here rather than being a curiosity: a ticket is assigned to a
// `crew` row, because that is what this schema means by a person. `users` are
// logins. So until a login is tied to a crew row, that individual cannot own a
// ticket and cannot have a "My tasks" list — which is exactly the state David and
// Renata were in.
$hasCrewLink = column_exists($pdo, 'users', 'crew_id');

// "Last seen" is a last-ACTIVITY time written on each authenticated page view
// (migration 055, stamped by includes/auth.php). Until that migration runs the
// column isn't there, so fall back to last_login — which is all this screen used
// to show, and the reason the dates looked stale: a login lasts a week, so it
// only moved when someone signed in afresh. COALESCE shows the truer value and
// keeps the login date as a fallback until the first activity touch upgrades it.
$hasLastSeen = column_exists($pdo, 'users', 'last_seen');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $display  = trim($_POST['display_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'artist';
            if ($role === 'supervisor' && !$hasSupervisor) {
                throw new RuntimeException('The Supervisor role needs migration 047, run it on the Migrations screen first.');
            }

            if ($username === '')        throw new RuntimeException('A username is required.');
            if (strlen($password) < 8)   throw new RuntimeException('The password must be at least 8 characters.');

            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $chk->execute([$username]);
            if ($chk->fetch()) throw new RuntimeException('That username is already taken.');

            $pdo->prepare(
                'INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)'
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display ?: $username, $role]);

            flash('ok', "Created the account \"$username\" as $role. Send the password through " .
                        'something private, a password manager or a direct message, not email.');
        }

        if ($action === 'set_role') {
            $id   = (int)($_POST['user_id'] ?? 0);
            $role = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'artist';
            if ($role === 'supervisor' && !$hasSupervisor) {
                throw new RuntimeException('The Supervisor role needs migration 047, run it on the Migrations screen first.');
            }

            // Losing the last admin would leave nobody able to manage accounts,
            // and no way back in short of editing the database by hand.
            if ($id === $currentUserId && $role !== 'admin') {
                $others = (int)$pdo->query(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> $currentUserId"
                )->fetchColumn();
                if ($others === 0) {
                    throw new RuntimeException('You are the only administrator, promote someone else first.');
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

        if ($action === 'set_crew' && $hasCrewLink) {
            $id = (int)($_POST['user_id'] ?? 0);
            $choice = $_POST['crew_id'] ?? '';

            $nameStmt = $pdo->prepare('SELECT display_name, username FROM users WHERE id = ?');
            $nameStmt->execute([$id]);
            $u = $nameStmt->fetch();
            if (!$u) throw new RuntimeException('That account no longer exists.');
            $personName = trim($u['display_name'] ?: $u['username']);

            if ($choice === 'new') {
                // Shared with mytasks.php, where someone links themselves. One
                // implementation so an admin doing it and a person doing it for
                // themselves cannot end up behaving differently.
                [, $msg] = link_login_to_crew($pdo, $id, $personName);
                flash('ok', $msg);
            } elseif ($choice === '' || $choice === '0') {
                $pdo->prepare('UPDATE users SET crew_id = NULL WHERE id = ?')->execute([$id]);
                flash('ok', 'Unlinked. Their "My tasks" list will ask again who they are.');
            } else {
                $crewId = (int)$choice;
                $chk = $pdo->prepare('SELECT name FROM crew WHERE id = ? AND is_team = 0');
                $chk->execute([$crewId]);
                $crewName = $chk->fetchColumn();
                if ($crewName === false) {
                    throw new RuntimeException('That is not a person in the crew list.');
                }
                $pdo->prepare('UPDATE users SET crew_id = ? WHERE id = ?')->execute([$crewId, $id]);
                flash('ok', "Linked the account to \"$crewName\".");
            }
        }

        if ($action === 'set_crew_email' && $hasCrewLink) {
            // The email lives on the CREW row, not on the login, because that is
            // what a notification is addressed to — a person, not an account.
            //
            // It is editable here because there was nowhere else for it: crew.php
            // manages a project's lanes, and its only email field appears when a
            // planning SLOT is promoted to a real person. Somebody added straight
            // to the crew list — a producer, a coordinator, anyone who owns
            // tickets without being booked on a show — has no lane and so never
            // appears there at all.
            $id = (int)($_POST['user_id'] ?? 0);
            $email = trim($_POST['crew_email'] ?? '');

            $lookup = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
            $lookup->execute([$id]);
            $crewId = (int)$lookup->fetchColumn();
            if (!$crewId) throw new RuntimeException('Link the account to a person first.');

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('"' . $email . '" is not a valid email address, nothing was saved.');
            }
            $pdo->prepare('UPDATE crew SET email = ? WHERE id = ?')
                ->execute([$email !== '' ? $email : null, $crewId]);
            flash('ok', $email !== ''
                ? 'Email saved. Notifications about their tickets will go to ' . $email . '.'
                : 'Email cleared. They will no longer be notified about their tickets.');
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
    'SELECT u.id, u.username, u.display_name, u.role, u.active, u.created_at, '
    . ($hasLastSeen ? 'COALESCE(u.last_seen, u.last_login)' : 'u.last_login') . ' AS last_seen'
    . ($hasCrewLink ? ', u.crew_id, c.name AS crew_name, c.email AS crew_email' : '')
    . ' FROM users u'
    . ($hasCrewLink ? ' LEFT JOIN crew c ON c.id = u.crew_id' : '')
    . ' ORDER BY u.active DESC, u.created_at ASC'
)->fetchAll();

// Real people only — a login belongs to a person, never to a team row or an
// unfilled planning slot.
$crewPeople = [];
if ($hasCrewLink) {
    $crewPeople = $pdo->query(
        'SELECT id, name, role FROM crew
          WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
          ORDER BY name'
    )->fetchAll();
}
$unlinked = $hasCrewLink
    ? count(array_filter($users, fn($u) => (int)$u['active'] === 1 && empty($u['crew_id'])))
    : 0;

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Users</h2>

<p class="lead">Who can log in, and what they're allowed to do.</p>

<?php if ($hasCrewLink && $unlinked > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $unlinked ?></strong> active account<?= $unlinked === 1 ? '' : 's' ?>
    <?= $unlinked === 1 ? 'is' : 'are' ?> not linked to a crew member yet.
    <strong>A ticket is assigned to a person, not to a login</strong>, so until an account is
    linked, that individual cannot be picked as an owner and has no "My tasks" list.
    Use the <em>Is which person</em> column below.
  </div>
<?php endif; ?>

<table class="review-table users-table">
  <thead>
    <tr><th>Username</th><th>Name</th><?php if ($hasCrewLink): ?><th>Is which person</th><th>Email for notifications</th><?php endif; ?><th>Role</th><th>Active</th><th>Last seen</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): $id = (int)$u['id']; $isSelf = $id === $currentUserId; ?>
    <tr class="<?= $u['active'] ? '' : 'row-inactive' ?>">
      <td>
        <strong><?= e($u['username']) ?></strong>
        <?php if ($isSelf): ?><span class="muted small">(you)</span><?php endif; ?>
      </td>
      <td><?= e($u['display_name'] ?: '—') ?></td>
      <?php if ($hasCrewLink): ?>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="set_crew">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <select name="crew_id" onchange="this.form.submit()">
            <option value="0" <?= empty($u['crew_id']) ? 'selected' : '' ?>>not linked</option>
            <option value="new">+ Add "<?= e($u['display_name'] ?: $u['username']) ?>" to the crew</option>
            <?php foreach ($crewPeople as $cp): ?>
              <option value="<?= (int)$cp['id'] ?>" <?= (int)($u['crew_id'] ?? 0) === (int)$cp['id'] ? 'selected' : '' ?>>
                <?= e($cp['name']) ?><?= $cp['role'] ? ' · ' . e($cp['role']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <?php if (empty($u['crew_id'])): ?>
          <span class="muted small">link a person first</span>
        <?php else: ?>
          <form method="post" class="inline-form email-form">
            <input type="hidden" name="action" value="set_crew_email">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <input type="email" name="crew_email" value="<?= e($u['crew_email'] ?? '') ?>"
                   placeholder="none, so no notifications">
            <button type="submit" class="secondary">Save</button>
          </form>
          <?php if (empty($u['crew_email'])): ?>
            <small class="warn-text">Without one, tickets can be assigned but nobody is told.</small>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <?php endif; ?>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="set_role">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <select name="role" onchange="this.form.submit()">
            <option value="admin"  <?= $u['role']==='admin'  ? 'selected':'' ?>>Admin</option>
            <option value="editor" <?= $u['role']==='editor' ? 'selected':'' ?>>Production</option>
            <?php if ($hasSupervisor): ?>
            <option value="supervisor" <?= $u['role']==='supervisor' ? 'selected':'' ?>>Supervisor</option>
            <?php endif; ?>
            <option value="artist" <?= $u['role']==='artist' ? 'selected':'' ?>>Artist</option>
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
      <td class="muted small"><?= e($u['last_seen'] ? substr($u['last_seen'], 0, 16) : 'never') ?></td>
      <td class="row-actions">
        <?php // The same flex row the project list uses, and the same
              // <summary>-as-a-button: two controls on one line, and the panel
              // drops below only when it is opened. ?>
        <div class="cell-btns">
          <details class="inline-actions pw-edit">
            <summary class="row-btn">Set password</summary>
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
        </div>
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
    <td><strong>Production</strong></td>
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
  screens built later. <strong>Money is admin-only</strong>: rates, budgets, invoices and
  contracts live in a separate database with its own login, and the connection to it is
  never even opened for a Production or a view-only account.
</p>

<?php // ---- see it for yourself ------------------------------------------------
      // David, 2026-09-02: "I'd like to see what the others have access to."
      // A table saying what a role can do is a claim; wearing the role is the
      // proof. It sits here because this is where the claim is made.
      //
      // Reached only from this screen, which is admin-only, so a preview can
      // only ever be STARTED by an administrator. It is left from the banner
      // that then follows you across every page — necessarily, since the
      // borrowed role cannot open this screen to find its way back. ?>
<h3>See the app as they see it</h3>
<p class="muted small">
  Borrow one of these roles for a moment. The menu, the screens you can open and the
  buttons you get all become theirs. <strong>Nothing saves while you are in there</strong>,
  whichever role you wear, so it is safe to click about, and a banner across the top
  brings you back.
</p>
<form method="post" action="preview.php" class="inline-form filter-bar">
  <input type="hidden" name="start" value="1">
  <input type="hidden" name="back" value="/users.php">
  <label class="inline">
    See Anima as
    <select name="role">
      <option value="editor">Production</option>
      <option value="supervisor">a Supervisor</option>
      <option value="artist">an Artist</option>
    </select>
  </label>
  <button class="button secondary" type="submit">Start preview</button>
</form>

<?php // ---- see it as a specific person -----------------------------------------
      // David, 2026-10-21: "can we admins see anima as specific users instead of
      // generic ones? that will help us see their assigned tasks and what's
      // available to them specifically." The role preview above answers "what
      // does an artist see"; this answers "what does THIS person see" — it wears
      // their role AND their crew link, so My tasks and the projects list show
      // exactly their world. Read-only like the role preview, and every start is
      // written to the preview_audit table (migration 056).
      //
      // Eligible = active, a lesser role (never another admin), and not yourself.
      $meId = (int)(current_user()['id'] ?? 0);
      $previewable = array_values(array_filter($users, function ($u) use ($meId) {
          $role = ($u['role'] ?? '') === 'viewer' ? 'artist' : (string)($u['role'] ?? '');
          return (int)$u['active'] === 1
              && in_array($role, ['editor', 'supervisor', 'artist'], true)
              && (int)$u['id'] !== $meId;
      }));
?>
<?php if ($previewable): ?>
<h3>See the app as a specific person</h3>
<p class="muted small">
  Pick someone and the app becomes theirs — their My tasks, the shows they are staffed on, the
  screens and buttons they get. <strong>Nothing saves while you are in there</strong>, and a banner
  across the top brings you back. Each time you do this is recorded. If someone isn't linked to a
  crew member yet (set that above), their tasks and projects will show empty.
</p>
<form method="post" action="preview.php" class="inline-form filter-bar">
  <input type="hidden" name="start_user" value="1">
  <input type="hidden" name="back" value="/users.php">
  <label class="inline">
    See Anima as
    <select name="user_id">
      <?php foreach ($previewable as $u): ?>
      <option value="<?= (int)$u['id'] ?>"><?= e((string)($u['display_name'] ?: $u['username'])) ?> — <?= e(role_in_words(($u['role'] ?? '') === 'viewer' ? 'artist' : (string)$u['role'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button secondary" type="submit">View as this person</button>
</form>
<?php endif; ?>

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
        <option value="artist" selected>Artist, reads everything, sets the status of their own work</option>
        <?php if ($hasSupervisor): ?>
        <option value="supervisor">Supervisor, an artist who also sees Timeline and Staffing, and edits Shots</option>
        <?php endif; ?>
        <option value="editor">Production, full access to set up and run shows</option>
        <option value="admin">Admin, full access, can manage users</option>
      </select>
    </label>
  </div>
  <div class="actions"><button type="submit">Create account</button></div>
</form>
<p class="muted small">
  Send the password through something private, a password manager or a direct message.
  Ask them to change it once they're in.
</p>

</div>
</body></html>
