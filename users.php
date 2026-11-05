<?php
// Who can log in, and what they may do.
//
// Roles are enforced centrally in includes/auth.php: view-only accounts have
// every POST refused there, so a screen built later is covered without anyone
// remembering to add a check.
//
// THREE TABS SINCE 2026-11-05 (migration 077, David: "I think the feature toggle
// should live in the users tab"):
//   People          the accounts, as before, with an Access column;
//   Access by role  one switch per feature per role (includes/features.php);
//   Access log      who changed whose access, when, and why.
// One person's own changes, and their responsibilities, open from People
// (?tab=person&id=N).
//
// ⚠ PRODUCTION CAN OPEN THIS SCREEN TOO (David, 2026-11-05: "Users: For admin,
// Production"), with less in reach than an admin:
//   - only Supervisor and Artist accounts (and the Client column) are theirs to
//     change; Admin and Production rows are drawn read-only — users_may_manage();
//   - nobody can be given a role above Supervisor by them;
//   - they can switch on only what Production itself has (feature_may_grant());
//   - responsibilities (salaried, kick-off sign-off, budget changes) stay with
//     administrators, as everything touching money does.
// Without those limits, Production could make itself an admin from here and so
// reach money, which CLAUDE.md's money rules forbid.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/budget_lock.php';
// The "users" switch is checked by require_login() (features.php). This second
// lock keeps the screen to the two roles it was designed for, whatever a switch
// says: it is locked off for every other role in FEATURES anyway.
require_login();
if (!is_admin() && !can_edit()) {
    flash('err', 'That screen is for administrators and Production.');
    header('Location: index.php');
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isAdmin = is_admin();
const USER_ROLES = ['admin', 'editor', 'supervisor', 'artist'];

// The roles this account may hand out or change. See the header.
$manageableRoles = $isAdmin ? USER_ROLES : ['supervisor', 'artist'];

/** May the current account change this account at all? */
function users_may_manage(array $u, bool $isAdmin): bool {
    if ($isAdmin) return true;
    $role = ($u['role'] ?? '') === 'viewer' ? 'artist' : (string)($u['role'] ?? '');
    return in_array($role, ['supervisor', 'artist'], true);
}

/** One account's row, or an exception that says so. */
function users_target(PDO $pdo, int $id): array {
    $q = $pdo->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u) throw new RuntimeException('That account no longer exists.');
    if ($u['role'] === 'viewer') $u['role'] = 'artist';
    return $u;
}

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

// SALARIED STAFF (David, 2026-10-28: "let's add that attribute in the users
// tab"). Salaried people cost one blended day rate and never invoice, so Home
// shows them no invoice information (includes/home.php, home_is_salaried()).
// Migration 072; the column is hidden until it has run.
$hasSalaried = column_exists($pdo, 'users', 'is_salaried');

// The kick-off ticks (049, 070), shown here beside Salaried as a person's
// RESPONSIBILITIES. They are still ticked on the Kick-off page too.
$hasSignsOff  = column_exists($pdo, 'users', 'signs_off_kickoff');
$hasBudgetTik = budget_lock_ready($pdo);

// The switches can only be CHANGED once migration 077 has run; until then the
// answers written in includes/features.php apply and the grid says so.
$hasFeatures = features_available($pdo);

$tab = (string)($_GET['tab'] ?? 'people');
if (!in_array($tab, ['people', 'roles', 'log', 'person'], true)) $tab = 'people';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $backTo = 'users.php';
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $display  = trim($_POST['display_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = in_array($_POST['role'] ?? '', $manageableRoles, true) ? $_POST['role'] : 'artist';
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
            // A new account has no changes of its own: it gets its role's
            // switches, which is the safe default for somebody nobody has
            // thought about yet.
            access_log($pdo, 'account', null, 'created as ' . $role, (int)$pdo->lastInsertId());

            flash('ok', "Created the account \"$username\" as $role. Send the password through " .
                        'something private, a password manager or a direct message, not email.');
        }

        if ($action === 'set_role') {
            $id   = (int)($_POST['user_id'] ?? 0);
            $target = users_target($pdo, $id);
            if (!users_may_manage($target, $isAdmin)) {
                throw new RuntimeException('Only an administrator can change an admin or a Production account.');
            }
            if (!in_array($_POST['role'] ?? '', $manageableRoles, true)) {
                throw new RuntimeException('Your account cannot give that role.');
            }
            $role = (string)$_POST['role'];
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
            if ($target['role'] !== $role) access_log($pdo, 'role', $target['role'], $role, $id);
            flash('ok', 'Role updated. It applies on their next page view.');
        }

        if ($action === 'set_salaried') {
            if (!$isAdmin) throw new RuntimeException('Salaried is an administrator setting.');
            if (!$hasSalaried) {
                throw new RuntimeException('Salaried needs migration 072, run it on the Migrations screen first.');
            }
            $id = (int)($_POST['user_id'] ?? 0);
            $on = !empty($_POST['salaried']) ? 1 : 0;
            $pdo->prepare('UPDATE users SET is_salaried = ? WHERE id = ?')->execute([$on, $id]);
            access_log($pdo, 'salaried', null, $on ? 'yes' : 'no', $id);
            flash('ok', $on ? 'Marked as salaried staff: their Home shows no invoice information.'
                            : 'Marked as a freelancer: their Home shows their invoice information.');
        }

        if ($action === 'set_active') {
            $id = (int)($_POST['user_id'] ?? 0);
            $target = users_target($pdo, $id);
            if (!users_may_manage($target, $isAdmin)) {
                throw new RuntimeException('Only an administrator can switch off an admin or a Production account.');
            }
            $on = !empty($_POST['active']) ? 1 : 0;
            if ($id === $currentUserId && !$on) {
                throw new RuntimeException("You can't switch off your own account.");
            }
            $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$on, $id]);
            access_log($pdo, 'active', null, $on ? 'on' : 'off', $id);
            flash('ok', $on ? 'Account switched back on.'
                            : 'Account switched off. They are logged out on their next page view.');
        }

        if ($action === 'password') {
            $id = (int)($_POST['user_id'] ?? 0);
            $target = users_target($pdo, $id);
            if (!users_may_manage($target, $isAdmin)) {
                throw new RuntimeException("Only an administrator can set an admin's or Production's password.");
            }
            $new = $_POST['new_password'] ?? '';
            if (strlen($new) < 8) throw new RuntimeException('The password must be at least 8 characters.');
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            access_log($pdo, 'password', null, 'set by hand', $id);
            flash('ok', 'Password changed.');
        }

        if ($action === 'set_crew' && $hasCrewLink) {
            $id = (int)($_POST['user_id'] ?? 0);
            $choice = $_POST['crew_id'] ?? '';

            $u = users_target($pdo, $id);
            if (!users_may_manage($u, $isAdmin)) {
                throw new RuntimeException('Only an administrator can relink an admin or a Production account.');
            }
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
            access_log($pdo, 'person link', null, (string)$choice, $id);
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
            if (!users_may_manage(users_target($pdo, $id), $isAdmin)) {
                throw new RuntimeException("Only an administrator can change an admin's or Production's email.");
            }
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
            $target = users_target($pdo, $id);
            if (!users_may_manage($target, $isAdmin)) {
                throw new RuntimeException('Only an administrator can delete an admin or a Production account.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            access_log($pdo, 'account', $target['role'], 'deleted (' . $target['username'] . ')', null);
            flash('ok', 'Deleted the account "' . $target['username'] . '".');
        }

        // ---- the access switches (migration 077) ---------------------------

        if ($action === 'role_feature') {
            $backTo = 'users.php?tab=roles';
            if (!$hasFeatures) throw new RuntimeException('The access switches need migration 077, run it on the Migrations screen first.');
            $role = (string)($_POST['role'] ?? '');
            $key  = (string)($_POST['feature'] ?? '');
            $on   = !empty($_POST['enabled']);
            if (!in_array($role, FEATURE_ROLES, true) || !isset(FEATURES[$key])) {
                throw new RuntimeException('That is not a switch.');
            }
            if (feature_locked($key, $role)) throw new RuntimeException('That switch is fixed for this role.');
            if (!$isAdmin && $role === 'editor') {
                throw new RuntimeException("Only an administrator changes Production's switches.");
            }
            if ($on && !feature_may_grant($key)) {
                throw new RuntimeException('You can only switch on what your own account has.');
            }
            $was = feature_role_default($key, $role);
            $pdo->prepare(
                'INSERT INTO role_features (role, feature, enabled, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
            )->execute([$role, $key, $on ? 1 : 0, $currentUserId, date('Y-m-d H:i:s')]);
            if ($was !== $on) access_log($pdo, $key, $was ? 'on' : 'off', $on ? 'on' : 'off', null, $role);
            flash('ok', FEATURES[$key]['label'] . ' is now ' . ($on ? 'on' : 'off') . ' for every '
                      . ['editor' => 'Production', 'supervisor' => 'Supervisor', 'artist' => 'Artist', 'client' => 'Client'][$role]
                      . ' account, unless a person has their own change.');
        }

        if ($action === 'user_features') {
            $id = (int)($_POST['user_id'] ?? 0);
            $backTo = 'users.php?tab=person&id=' . $id;
            if (!$hasFeatures) throw new RuntimeException('The access switches need migration 077, run it on the Migrations screen first.');
            $target = users_target($pdo, $id);
            if ($target['role'] === 'admin') throw new RuntimeException('Admins have everything; there is nothing to switch.');
            if (!users_may_manage($target, $isAdmin)) {
                throw new RuntimeException("Only an administrator changes a Production account's access.");
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            $want   = (array)($_POST['f'] ?? []);
            $mine   = feature_user_changes($id);
            $changed = 0;
            $del = $pdo->prepare('DELETE FROM user_features WHERE user_id = ? AND feature = ?');
            $put = $pdo->prepare(
                'INSERT INTO user_features (user_id, feature, enabled, reason, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), reason = VALUES(reason),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
            );
            foreach (FEATURES as $key => $f) {
                if (!isset($want[$key]) || feature_locked($key, $target['role'])) continue;
                $choice = (string)$want[$key];                       // role | on | off
                $before = array_key_exists($key, $mine) ? ($mine[$key] ? 'on' : 'off') : 'role';
                if (!in_array($choice, ['role', 'on', 'off'], true) || $choice === $before) continue;
                if ($choice === 'on' && !feature_may_grant($key)) {
                    throw new RuntimeException('You can only switch on what your own account has ("' . $f['label'] . '").');
                }
                if ($choice === 'role') {
                    $del->execute([$id, $key]);
                } else {
                    $put->execute([$id, $key, $choice === 'on' ? 1 : 0, $reason !== '' ? substr($reason, 0, 255) : null,
                                   $currentUserId, date('Y-m-d H:i:s')]);
                }
                access_log($pdo, $key, $before, $choice, $id, null, $reason);
                $changed++;
            }
            flash('ok', $changed ? 'Saved ' . $changed . ' change' . ($changed === 1 ? '' : 's')
                                 . '. They apply on their next page view.'
                                 : 'Nothing was different, so nothing changed.');
        }

        if ($action === 'responsibilities') {
            $id = (int)($_POST['user_id'] ?? 0);
            $backTo = 'users.php?tab=person&id=' . $id;
            if (!$isAdmin) throw new RuntimeException('Responsibilities are an administrator setting.');
            $target = users_target($pdo, $id);
            $sets = [];
            if ($hasSalaried) $sets['is_salaried'] = !empty($_POST['is_salaried']) ? 1 : 0;
            if ($hasSignsOff) $sets['signs_off_kickoff'] = !empty($_POST['signs_off_kickoff']) ? 1 : 0;
            // Only an administrator's tick means anything (only admins see a
            // budget), the rule the Kick-off page keeps too.
            if ($hasBudgetTik) $sets['changes_budget'] = ($target['role'] === 'admin' && !empty($_POST['changes_budget'])) ? 1 : 0;
            $cur = $pdo->prepare('SELECT ' . ($sets ? implode(', ', array_keys($sets)) : '1') . ' FROM users WHERE id = ?');
            $cur->execute([$id]);
            $now = $cur->fetch() ?: [];
            foreach ($sets as $col => $val) {
                if ((int)($now[$col] ?? 0) === $val) continue;
                $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([$val, $id]);
                access_log($pdo, $col, (int)($now[$col] ?? 0) ? 'yes' : 'no', $val ? 'yes' : 'no', $id);
            }
            flash('ok', 'Responsibilities saved.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $backTo);
    exit;
}

$users = $pdo->query(
    'SELECT u.id, u.username, u.display_name, u.role, u.active, u.created_at, '
    . ($hasLastSeen ? 'COALESCE(u.last_seen, u.last_login)' : 'u.last_login') . ' AS last_seen'
    . ($hasCrewLink ? ', u.crew_id, c.name AS crew_name, c.email AS crew_email' : '')
    . ($hasSalaried ? ', u.is_salaried' : '')
    . ($hasSignsOff ? ', u.signs_off_kickoff' : '')
    . ($hasBudgetTik ? ', u.changes_budget' : '')
    . ' FROM users u'
    . ($hasCrewLink ? ' LEFT JOIN crew c ON c.id = u.crew_id' : '')
    . ' ORDER BY u.active DESC, u.created_at ASC'
)->fetchAll();
foreach ($users as &$uu) { if ($uu['role'] === 'viewer') $uu['role'] = 'artist'; }
unset($uu);

// How many changes of their own each person has, for the Access column.
$changeCount = [];
if ($hasFeatures) {
    foreach ($pdo->query('SELECT user_id, COUNT(*) AS n FROM user_features GROUP BY user_id') as $r) {
        $changeCount[(int)$r['user_id']] = (int)$r['n'];
    }
}

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

$roleName = fn(string $r): string => ['admin' => 'Admin', 'editor' => 'Production', 'supervisor' => 'Supervisor',
                                      'artist' => 'Artist', 'client' => 'Client'][$r] ?? $r;
$lockSvg = '<svg viewBox="0 0 12 12" aria-hidden="true"><path fill="currentColor" d="M3 5V4a3 3 0 0 1 6 0v1h.5a.5.5 0 0 1 .5.5v5a.5.5 0 0 1-.5.5h-7a.5.5 0 0 1-.5-.5v-5a.5.5 0 0 1 .5-.5zm1.3 0h3.4V4a1.7 1.7 0 0 0-3.4 0z"/></svg>';

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Users</h2>

<p class="lead">Who can log in, and what each of them can open.</p>

<nav class="utabs" aria-label="Users sections">
  <a href="users.php" class="<?= in_array($tab, ['people', 'person'], true) ? 'current' : '' ?>">People</a>
  <a href="users.php?tab=roles" class="<?= $tab === 'roles' ? 'current' : '' ?>">Access by role</a>
  <a href="users.php?tab=log" class="<?= $tab === 'log' ? 'current' : '' ?>">Access log</a>
</nav>

<?php if (!$hasFeatures && $tab !== 'people'): ?>
  <div class="flash flash-warn">
    The access switches can't be changed until <strong>migration 077</strong> has run.
    Until then everyone gets the answers shown below.
    <?= $isAdmin ? '<a href="migrate.php">Review and apply it →</a>' : 'Ask an administrator to run it.' ?>
  </div>
<?php endif; ?>

<?php if ($tab === 'people'): ?>

<?php if ($hasCrewLink && $unlinked > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $unlinked ?></strong> active account<?= $unlinked === 1 ? '' : 's' ?>
    <?= $unlinked === 1 ? 'is' : 'are' ?> not linked to a crew member yet.
    <strong>A ticket is assigned to a person, not to a login</strong>, so until an account is
    linked, that individual cannot be picked as an owner and has no "My tasks" list.
    Use the <em>Is which person</em> column below.
  </div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <p class="muted small">You can change Supervisor and Artist accounts. Admin and Production accounts are
    shown for reference; an administrator changes those.</p>
<?php endif; ?>

<table class="review-table users-table">
  <thead>
    <tr><th>Username</th><th>Name</th><?php if ($hasCrewLink): ?><th>Is which person</th><th>Email for notifications</th><?php endif; ?><th>Role</th><th>Access</th><?php if ($hasSalaried && $isAdmin): ?><th title="Salaried staff never invoice: their Home shows no invoice information">Salaried</th><?php endif; ?><th>Active</th><th>Last seen</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): $id = (int)$u['id']; $isSelf = $id === $currentUserId;
        $mine = users_may_manage($u, $isAdmin); ?>
    <tr class="<?= $u['active'] ? '' : 'row-inactive' ?>">
      <td>
        <strong><?= e($u['username']) ?></strong>
        <?php if ($isSelf): ?><span class="muted small">(you)</span><?php endif; ?>
      </td>
      <td><?= e($u['display_name'] ?: '—') ?></td>
      <?php if ($hasCrewLink): ?>
      <td>
        <?php if ($mine): ?>
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
        <?php else: ?>
          <?= e($u['crew_name'] ?? '') ?: '<span class="muted">not linked</span>' ?>
        <?php endif; ?>
      </td>
      <td>
        <?php if (empty($u['crew_id'])): ?>
          <span class="muted small">link a person first</span>
        <?php elseif (!$mine): ?>
          <?= e($u['crew_email'] ?? '') ?: '<span class="muted small">none</span>' ?>
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
        <?php if ($mine): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="set_role">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <select name="role" onchange="this.form.submit()">
            <?php if ($isAdmin): ?>
            <option value="admin"  <?= $u['role']==='admin'  ? 'selected':'' ?>>Admin</option>
            <option value="editor" <?= $u['role']==='editor' ? 'selected':'' ?>>Production</option>
            <?php endif; ?>
            <?php if ($hasSupervisor): ?>
            <option value="supervisor" <?= $u['role']==='supervisor' ? 'selected':'' ?>>Supervisor</option>
            <?php endif; ?>
            <option value="artist" <?= $u['role']==='artist' ? 'selected':'' ?>>Artist</option>
          </select>
        </form>
        <?php else: ?>
          <?= e($roleName((string)$u['role'])) ?>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($u['role'] === 'admin'): ?>
          <span class="pill">Admin, everything</span>
        <?php else: $n = $changeCount[$id] ?? 0; ?>
          <a href="users.php?tab=person&amp;id=<?= $id ?>" class="pill<?= $n ? ' chg' : '' ?>"
             title="Open their access"><?= e($roleName((string)$u['role'])) ?><?= $n ? ' + ' . $n . ' change' . ($n === 1 ? '' : 's') : '' ?></a>
        <?php endif; ?>
      </td>
      <?php if ($hasSalaried && $isAdmin): ?>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="set_salaried">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <input type="hidden" name="salaried" value="0">
          <input type="checkbox" name="salaried" value="1" onchange="this.form.submit()"
                 aria-label="Salaried" <?= !empty($u['is_salaried']) ? 'checked' : '' ?>>
        </form>
      </td>
      <?php endif; ?>
      <td>
        <?php if ($isSelf || !$mine): ?>
          <span class="muted"><?= $isSelf ? '—' : ($u['active'] ? 'on' : 'off') ?></span>
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
        <?php if ($mine): ?>
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
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="muted small">
  What each role can open is on <a href="users.php?tab=roles">Access by role</a>; one person's own
  changes open from the Access column. <strong>Money is admin-only</strong> whatever a switch says:
  rates, budgets, invoices and contracts live in a separate database with its own login, and the
  connection to it is never even opened for any other account.
</p>

<?php // ---- see it for yourself ------------------------------------------------
      // David, 2026-09-02: "I'd like to see what the others have access to."
      // A table saying what a role can do is a claim; wearing the role is the
      // proof. It sits here because this is where the claim is made.
      //
      // Admins, and since 2026-11-05 Production while "View as" is on for them;
      // each only ever DOWNWARD (preview_roles_allowed() in auth.php). It is left
      // from the banner that then follows you across every page — necessarily,
      // since the borrowed role cannot open this screen to find its way back. ?>
<?php $canPreview = preview_roles_allowed(); ?>
<?php if ($canPreview): ?>
<div class="preview-cols">
<div>
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
      <?php if (in_array('editor', $canPreview, true)): ?><option value="editor">Production</option><?php endif; ?>
      <option value="supervisor">a Supervisor</option>
      <option value="artist">an Artist</option>
    </select>
  </label>
  <button class="button secondary" type="submit">Start preview</button>
</form>
</div>
<div>

<?php // ---- see it as a specific person -----------------------------------------
      // David, 2026-10-21: "can we admins see anima as specific users instead of
      // generic ones? that will help us see their assigned tasks and what's
      // available to them specifically." The role preview above answers "what
      // does an artist see"; this answers "what does THIS person see" — it wears
      // their role AND their crew link, so My tasks and the projects list show
      // exactly their world. Every start is written to the preview_audit table
      // (migration 056) and to the access log (077).
      //
      // Eligible = active, a role below yours, and not yourself.
      $previewable = array_values(array_filter($users, function ($u) use ($currentUserId, $canPreview) {
          return (int)$u['active'] === 1
              && in_array((string)$u['role'], $canPreview, true)
              && (int)$u['id'] !== $currentUserId;
      }));
?>
<?php if ($previewable): ?>
<h3>See the app as a specific person</h3>
<p class="muted small">
  Pick someone and the app becomes theirs — their My tasks, the shows they are staffed on, the
  screens and buttons they get. <strong>Changes you make save</strong>, as their work, and are
  recorded as made by you. Their money (rates, invoices) is never shown<?= $isAdmin ? ' to anyone but an administrator' : '' ?>.
  Each time you do this is recorded. If someone isn't linked to a crew member yet (set that above),
  their tasks and projects will show empty.
</p>
<form method="post" action="preview.php" class="inline-form filter-bar">
  <input type="hidden" name="start_user" value="1">
  <input type="hidden" name="back" value="/users.php">
  <label class="inline">
    See Anima as
    <select name="user_id">
      <?php foreach ($previewable as $u): ?>
      <option value="<?= (int)$u['id'] ?>"><?= e((string)($u['display_name'] ?: $u['username'])) ?> — <?= e(role_in_words((string)$u['role'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button secondary" type="submit">View as this person</button>
</form>
<?php endif; ?>
</div>
</div>
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
        <option value="artist" selected>Artist, their own work, the shows they are on</option>
        <?php if ($hasSupervisor): ?>
        <option value="supervisor">Supervisor, an artist who also reviews, reads Timeline and Staffing, and edits Shots</option>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <option value="editor">Production, full access to set up and run shows</option>
        <option value="admin">Admin, everything, including money</option>
        <?php endif; ?>
      </select>
    </label>
  </div>
  <div class="actions"><button type="submit">Create account</button></div>
</form>
<p class="muted small">
  A new account gets its role's switches from Access by role, and nothing of its own. Send the
  password through something private, a password manager or a direct message. Ask them to change it
  once they're in.
</p>

<?php elseif ($tab === 'roles'): ?>

<p class="muted small" style="margin-top:0">What each role gets <strong>by default</strong>. A switch that is off
  hides the link <strong>and</strong> closes the screen: typing its address shows "That screen isn't open to your
  account". One person can still be given their own change from the People tab.</p>
<div class="acc-legend muted small">
  <span><span class="always">✓</span> admins keep everything, so nobody can lock the studio out</span>
  <span><span class="lock"><?= $lockSvg ?>fixed</span> not offered for this role</span>
  <?php if (!$isAdmin): ?><span>Production's column is changed by an administrator; you can switch on only what you have yourself.</span><?php endif; ?>
</div>

<table class="acc-grid">
  <thead><tr><th>Feature</th><th>Admin</th>
    <?php foreach (FEATURE_ROLES as $r): ?>
      <th<?= $r === 'client' ? ' class="newrole"' : '' ?>><?= e($roleName($r)) ?><?php if ($r === 'client'): ?><span class="sub">no client logins yet</span><?php endif; ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php $lastGroup = null; foreach (FEATURES as $key => $f): ?>
    <?php if ($f['group'] !== $lastGroup): $lastGroup = $f['group']; ?>
      <tr class="grp"><td colspan="<?= 2 + count(FEATURE_ROLES) ?>"><?= e($f['group']) ?><?= $f['group'] === 'Inside a project' ? ' <span class="grp-note">(only the shows they are on)</span>' : '' ?></td></tr>
    <?php endif; ?>
    <tr class="<?= !empty($f['parent']) ? 'child' : '' ?>">
      <td><?= e($f['label']) ?><?php if (!empty($f['note'])): ?><span class="sub"><?= e($f['note']) ?></span><?php endif; ?></td>
      <td><span class="always" title="Admins keep everything">✓</span></td>
      <?php foreach (FEATURE_ROLES as $r): $on = feature_role_default($key, $r); ?>
        <td>
          <?php if (feature_locked($key, $r)): ?>
            <span class="lock" title="Fixed for this role"><?= $lockSvg ?><?= $on ? 'on' : 'off' ?></span>
          <?php else:
              $may = $hasFeatures && ($isAdmin || $r !== 'editor') && ($on || feature_may_grant($key)); ?>
            <?php if ($may): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="role_feature">
              <input type="hidden" name="role" value="<?= e($r) ?>">
              <input type="hidden" name="feature" value="<?= e($key) ?>">
              <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
              <button type="submit" class="tg<?= $on ? ' on' : '' ?>" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>"
                      aria-label="<?= e($f['label'] . ' for ' . $roleName($r)) ?>" title="<?= $on ? 'On: click to switch off' : 'Off: click to switch on' ?>"></button>
            </form>
            <?php else: ?>
              <span class="tg ro<?= $on ? ' on' : '' ?>" role="img" aria-label="<?= $on ? 'on' : 'off' ?>"></span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
    <tr class="grp"><td colspan="<?= 2 + count(FEATURE_ROLES) ?>">Fixed</td></tr>
    <tr><td>Finance management, Historic fees</td><td><span class="always">✓</span></td>
        <td colspan="<?= count(FEATURE_ROLES) ?>"><span class="lock"><?= $lockSvg ?>money: admins only, always</span></td></tr>
    <tr><td>Email, Migrations</td><td><span class="always">✓</span></td>
        <td colspan="<?= count(FEATURE_ROLES) ?>"><span class="lock"><?= $lockSvg ?>admins only</span></td></tr>
  </tbody>
</table>

<?php elseif ($tab === 'person'):
    $pid = (int)($_GET['id'] ?? 0);
    $person = null;
    foreach ($users as $u) if ((int)$u['id'] === $pid) $person = $u;
?>
  <?php if (!$person): ?>
    <p class="muted">That account no longer exists. <a href="users.php">Back to People</a></p>
  <?php else:
      $prole = (string)$person['role'];
      $pname = (string)($person['display_name'] ?: $person['username']);
      $mine  = users_may_manage($person, $isAdmin);
      $own   = feature_user_changes($pid);
      // Their changes first, then the rest in the grid's order.
      $keys = array_keys(FEATURES);
      usort($keys, function ($a, $b) use ($own, $keys) {
          $ca = isset($own[$a]) ? 0 : 1; $cb = isset($own[$b]) ? 0 : 1;
          return $ca <=> $cb ?: array_search($a, $keys, true) <=> array_search($b, $keys, true);
      });
  ?>
  <p class="muted small"><a href="users.php">← People</a></p>
  <div class="acc-cols">
  <div class="acc-card">
    <h3><?= e($pname) ?> <span class="muted">· <?= e($roleName($prole)) ?></span></h3>
    <?php if ($prole === 'admin'): ?>
      <p class="muted">Admins keep everything, so there is nothing to switch for them.</p>
    <?php else: ?>
      <p class="muted small">"Role" means the same as every <?= e($roleName($prole)) ?>, as set on
        <a href="users.php?tab=roles">Access by role</a>. Their own changes are listed first.
        <?= $mine ? '' : 'Only an administrator changes a Production account; this is for reference.' ?></p>
      <form method="post">
        <input type="hidden" name="action" value="user_features">
        <input type="hidden" name="user_id" value="<?= $pid ?>">
        <table class="acc-grid">
          <thead><tr><th>Feature</th><th><?= e($roleName($prole)) ?></th><th><?= e($pname) ?></th></tr></thead>
          <tbody>
          <?php foreach ($keys as $key): $f = FEATURES[$key];
                $roleOn = feature_role_default($key, $prole);
                $cur = array_key_exists($key, $own) ? ($own[$key] ? 'on' : 'off') : 'role';
                $locked = feature_locked($key, $prole); ?>
            <tr class="<?= $cur !== 'role' && !$locked ? 'ov' : '' ?>">
              <td><?= e($f['label']) ?><?php if (!empty($f['parent'])): ?><span class="sub"><?= e(FEATURES[$f['parent']]['label']) ?></span><?php endif; ?></td>
              <td class="muted"><?= $roleOn ? 'on' : 'off' ?></td>
              <td>
                <?php if ($locked): ?>
                  <span class="lock"><?= $lockSvg ?>fixed</span>
                <?php elseif (!$mine || !$hasFeatures): ?>
                  <span class="muted"><?= $cur === 'role' ? 'Role' : ucfirst($cur) ?></span>
                <?php else: ?>
                  <span class="seg" role="radiogroup" aria-label="<?= e($f['label']) ?>">
                    <?php foreach (['role' => 'Role', 'on' => 'On', 'off' => 'Off'] as $v => $lab):
                          $grantable = $v !== 'on' || feature_may_grant($key); ?>
                      <label class="seg-<?= $v ?><?= $grantable ? '' : ' is-off' ?>"><input type="radio" name="f[<?= e($key) ?>]" value="<?= $v ?>"
                        <?= $cur === $v ? 'checked' : '' ?> <?= $grantable ? '' : 'disabled' ?>><span><?= $lab ?></span></label>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($mine && $hasFeatures): ?>
          <label class="acc-why muted small">Reason for this change (saved in the access log)
            <input name="reason" maxlength="255" placeholder="e.g. covering schedule reviews this month"></label>
          <div class="actions"><button type="submit">Save <?= e($pname) ?>'s access</button></div>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($isAdmin && ($hasSalaried || $hasSignsOff || $hasBudgetTik)): ?>
    <div class="acc-card">
      <h3>Responsibilities</h3>
      <p class="muted small">What this person IS, rather than what they can open. Administrators only.
        Sign-off and budget are also ticked on each project's Kick-off page.</p>
      <form method="post">
        <input type="hidden" name="action" value="responsibilities">
        <input type="hidden" name="user_id" value="<?= $pid ?>">
        <?php if ($hasSalaried): ?>
          <label class="inline"><input type="checkbox" name="is_salaried" value="1" <?= !empty($person['is_salaried']) ? 'checked' : '' ?>>
            Salaried (no invoices)</label><br>
        <?php endif; ?>
        <?php if ($hasSignsOff): ?>
          <label class="inline"><input type="checkbox" name="signs_off_kickoff" value="1" <?= !empty($person['signs_off_kickoff']) ? 'checked' : '' ?>>
            Signs off kick-offs</label><br>
        <?php endif; ?>
        <?php if ($hasBudgetTik): ?>
          <label class="inline"><input type="checkbox" name="changes_budget" value="1" <?= !empty($person['changes_budget']) ? 'checked' : '' ?>
              <?= $prole === 'admin' ? '' : 'disabled' ?>>
            Changes a signed-off budget <span class="muted small"><?= $prole === 'admin' ? '' : '(admins only)' ?></span></label><br>
        <?php endif; ?>
        <div class="actions"><button type="submit" class="secondary">Save responsibilities</button></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if (in_array($prole, preview_roles_allowed(), true) && (int)$person['active'] === 1 && $pid !== $currentUserId): ?>
    <div class="acc-card">
      <h3>Check it</h3>
      <p class="muted small">See exactly what <?= e($pname) ?> sees, with these switches.</p>
      <form method="post" action="preview.php">
        <input type="hidden" name="start_user" value="1">
        <input type="hidden" name="user_id" value="<?= $pid ?>">
        <input type="hidden" name="back" value="/users.php">
        <button class="button secondary" type="submit">View the app as <?= e($pname) ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  </div>
  <?php endif; ?>

<?php else: /* log */ ?>

<?php
$log = [];
if ($hasFeatures) {
    $log = $pdo->query(
        "SELECT l.*, COALESCE(NULLIF(a.display_name, ''), a.username) AS actor,
                COALESCE(NULLIF(t.display_name, ''), t.username) AS target
           FROM access_log l
           LEFT JOIN users a ON a.id = l.actor_user_id
           LEFT JOIN users t ON t.id = l.target_user_id
          ORDER BY l.created_at DESC, l.id DESC
          LIMIT 300"
    )->fetchAll();
}
$what = function (string $w): string {
    if (isset(FEATURES[$w])) {
        $f = FEATURES[$w];
        return (!empty($f['parent']) ? FEATURES[$f['parent']]['label'] . ' › ' : '') . $f['label'];
    }
    return ['role' => 'Role', 'active' => 'Account switched', 'salaried' => 'Salaried', 'is_salaried' => 'Salaried',
            'signs_off_kickoff' => 'Signs off kick-offs', 'changes_budget' => 'Changes a signed-off budget',
            'view_as' => 'Viewed the app as', 'password' => 'Password', 'account' => 'Account',
            'person link' => 'Linked to a person'][$w] ?? $w;
};
?>
<?php if (!$log): ?>
  <p class="muted">Nothing recorded yet. Every change to a role, an account, a switch or a responsibility,
    and every "view as", is written here from now on.</p>
<?php else: ?>
  <table class="review-table">
    <thead><tr><th>When</th><th>Who</th><th>Whose</th><th>What</th><th>From</th><th>To</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr>
        <td class="muted small"><?= e(substr((string)$l['created_at'], 0, 16)) ?></td>
        <td><?= e($l['actor'] ?: '—') ?></td>
        <td><?= $l['target_role'] ? 'every ' . e($roleName((string)$l['target_role'])) : e($l['target'] ?: '—') ?></td>
        <td><?= e($what((string)$l['what'])) ?></td>
        <td class="muted"><?= e((string)($l['old_value'] ?? '')) ?></td>
        <td><?= e((string)($l['new_value'] ?? '')) ?></td>
        <td class="muted small"><?= e((string)($l['reason'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php endif; ?>

</div>
</body></html>
