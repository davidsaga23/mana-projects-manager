<?php
// Everything assigned to one person, across every show.
//
// The thing STFC could not do at all, and the clearest thing multi-project buys:
// somebody booked on two productions has ONE list of what is waiting for them,
// rather than two boards they have to remember to open.
//
// `users` are logins and `crew` are people; nothing joined them until migration
// 017 added users.crew_id. That link is asked for once, here, with an exact
// name match pre-selected — never guessed at silently, because attaching one
// person's work to another's login is the quiet mis-mapping the alias layer
// exists to prevent.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/crew.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$ready  = column_exists($pdo, 'tickets', 'id') && column_exists($pdo, 'users', 'crew_id');

// --- claim a crew row -------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_crew'])) {
    try {
        if ($_POST['set_crew'] === 'new') {
            // The case that made this screen a dead end: on a project whose crew
            // is all placeholder slots, or before anyone has been added at all,
            // the picker had nothing to offer and no way forward. Producers and
            // coordinators are exactly the people who own tickets and are
            // exactly the people not in an artist list.
            $me = current_user();
            [, $msg] = link_login_to_crew($pdo, $userId, (string)($me['display_name'] ?: $me['username']));
            flash('ok', $msg);
        } else {
            $crewId = (int)$_POST['set_crew'] ?: null;
            $pdo->prepare('UPDATE users SET crew_id = ? WHERE id = ?')->execute([$crewId, $userId]);
            flash('ok', $crewId
                ? 'Linked. This list now shows what is assigned to you.'
                : 'Unlinked from a crew member.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: mytasks.php');
    exit;
}

$me = current_user();

// Read crew_id with its own query rather than adding it to current_user().
// That function's SELECT is wrapped in a catch which, if the query fails,
// falls back to treating everyone as an admin — so adding a column there that
// migration 017 may not have created yet would silently hand admin rights to
// every account on any install a step behind. Cheap query, no such risk.
$myCrewId = 0;
if ($ready) {
    $mc = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
    $mc->execute([$userId]);
    $myCrewId = (int)$mc->fetchColumn();
}

$tickets = [];
$candidates = [];
if ($ready) {
    if ($myCrewId) {
        $tickets = tickets_for_crew($pdo, $myCrewId);
    } else {
        // Real people only — a login belongs to a person, never to a team row or
        // an unfilled planning slot.
        $candidates = $pdo->query(
            'SELECT id, name, role FROM crew
              WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
              ORDER BY name'
        )->fetchAll();
    }
}

$myCrewName = '';
if ($myCrewId) {
    $n = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
    $n->execute([$myCrewId]);
    $myCrewName = (string)$n->fetchColumn();
}

$today = date('Y-m-d');
$overdue = 0; $dueSoon = 0;
$weekEnd = date('Y-m-d', strtotime('+7 days'));
foreach ($tickets as $t) {
    if (!$t['due_date']) continue;
    if ($t['due_date'] < $today)      $overdue++;
    elseif ($t['due_date'] <= $weekEnd) $dueSoon++;
}

$pageTitle = 'My work';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← All projects</a></p>
<h2 class="wizard-title">My work</h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>This screen needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>017_tickets.sql</code> creates the tasks tables and the link
       between a login and a person.</p>
    <?php if (is_admin()): ?>
      <p><a class="button" href="migrate.php">Review and apply it →</a></p>
    <?php endif; ?>
  </div>

<?php elseif (!$myCrewId): ?>
  <p class="lead">Tickets are assigned to <strong>crew members</strong>, and logins are separate
     from people — so this screen needs to know which of the crew you are. It is asked once.</p>

  <?php if (!$candidates): ?>
    <div class="empty-state">
      <p><strong>Nobody is in the crew list yet — but you can add yourself.</strong></p>
      <p class="muted">The crew list holds real people. Planning slots ("Animation 01") and
         teams don't count, which is why this can be empty on a project that is fully planned.</p>
      <form method="post">
        <button type="submit" name="set_crew" value="new">
          Add <?= e($me['display_name'] ?: $me['username']) ?> to the crew
        </button>
      </form>
    </div>
  <?php else: ?>
    <?php
    // An exact display-name match is not a guess, so it is pre-selected — but it
    // still needs the click, because two people can share a name and the cost of
    // getting this wrong is seeing somebody else's work as your own.
    $guess = 0;
    foreach ($candidates as $c) {
        if (strcasecmp(trim($c['name']), trim((string)($me['display_name'] ?? ''))) === 0) {
            $guess = (int)$c['id'];
            break;
        }
    }
    ?>
    <form method="post" class="inline-form">
      <label>Which of the crew are you?
        <select name="set_crew" required>
          <option value="">— choose —</option>
          <?php foreach ($candidates as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $guess === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['role'] ? ' · ' . e($c['role']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Save</button>
      <?php if ($guess): ?>
        <small>Matched to your account name. Change it if that isn't you.</small>
      <?php endif; ?>
    </form>
    <?php if (!$guess): ?>
      <p class="muted small">Not in that list? Add yourself:</p>
      <form method="post">
        <button type="submit" name="set_crew" value="new" class="secondary">
          Add <?= e($me['display_name'] ?: $me['username']) ?> to the crew
        </button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>
  <p class="lead">
    Open tickets and tasks assigned to <strong><?= e($myCrewName) ?></strong>, across every project.
    <?php if ($overdue || $dueSoon): ?>
      <?= $overdue ?> overdue<?= $dueSoon ? ', ' . $dueSoon . ' due in the next 7 days' : '' ?>.
    <?php endif; ?>
  </p>

  <?php if (!$tickets): ?>
    <div class="empty-state">
      <p><strong>Nothing open is assigned to you.</strong></p>
      <p class="muted">Cards appear here until they reach a status that counts as approved.</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Ref</th><th>Title</th><th>Project</th><th>About</th>
          <th>Status</th><th>Priority</th><th>Due</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t):
          $isOverdue = $t['due_date'] && $t['due_date'] < $today;
          $shot = shot_label($t['episode'] ?? '', $t['shot_code'] ?? '');
          $color = $t['status_color'] ?: '#8a97a3';
        ?>
          <tr class="<?= $isOverdue ? 'row-overdue' : '' ?>">
            <td><a href="ticket.php?id=<?= (int)$t['id'] ?>" class="<?= $t['type'] === 'ticket' ? 'ref-ticket' : 'ref-task' ?>"><?= e(ticket_ref($t)) ?></a></td>
            <td><a href="ticket.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a></td>
            <td><a href="tasks.php?project=<?= (int)$t['project_id'] ?>" class="muted"><?= e($t['project_name']) ?></a></td>
            <td>
              <?php if ($shot !== ''): ?><span class="ctx-chip ctx-shot"><?= e($shot) ?></span><?php endif; ?>
              <?php if (!empty($t['asset_name'])): ?><span class="ctx-chip ctx-asset"><?= e($t['asset_name']) ?></span><?php endif; ?>
              <?php if (!empty($t['step_name'])): ?><span class="ctx-chip ctx-step"><?= e($t['step_name']) ?></span><?php endif; ?>
              <?php if ($shot === '' && empty($t['asset_name']) && empty($t['step_name'])): ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td>
              <span class="status-chip" style="background:<?= e($color) ?>1a;color:<?= e($color) ?>;border:1px solid <?= e($color) ?>44;">
                <?= e($t['status_name'] ?? 'no status') ?>
              </span>
              <?php if ((int)($t['status_blocked'] ?? 0) === 1): ?>
                <span class="status-chip" style="background:#fff3cd;color:#8a6d1a;border:1px solid #e0c98a;">blocked</span>
              <?php endif; ?>
            </td>
            <td><span class="prio-dot priority-<?= e($t['priority']) ?>"></span> <?= ucfirst($t['priority']) ?></td>
            <td class="<?= $isOverdue ? 'err' : '' ?>">
              <?= $t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '<span class="muted">—</span>' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" class="inline-form" style="margin-top:22px;">
    <input type="hidden" name="set_crew" value="0">
    <small>Showing work for <strong><?= e($myCrewName) ?></strong>.</small>
    <button type="submit" class="secondary">That isn't me — unlink</button>
  </form>
<?php endif; ?>

</div>
</body></html>
