<?php
// Kick-off: the warning, the sign-off, and the postmortem's "how it started
// against how it ended".
//
// David, 2026-10-16. Choosing "active" for a project in setup (on the project
// list, or Finish in the setup wizard) lands HERE instead of switching. This page
// is the warning; confirming it freezes the setup and emails the people who sign
// off. When all of them approve, the project turns active.
//
//   ?project=N               the sign-off: send it, answer it, see what was frozen
//   ?project=N&view=compare  kick-off against now, for the postmortem
//   ?project=N&view=people   who signs off (administrators)
//
// The work is in includes/kickoff.php; this file is the screen.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kickoff.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$view    = (string)($_GET['view'] ?? '');
$selfUrl = 'kickoff.php?project=' . $projectId;
$ready   = kickoff_ready($pdo);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back   = $selfUrl;
    try {
        $kick = kickoff_current($pdo, $projectId);
        if ($action === 'send') {
            if (empty($_POST['understood'])) {
                throw new RuntimeException('Tick the box to confirm you have read the warning.');
            }
            foreach (kickoff_request($pdo, $project) as $m) flash('ok', $m);
            flash('ok', 'The setup is frozen and waiting for sign-off. The project stays in setup until everybody has approved.');

        } elseif ($action === 'approve' || $action === 'changes') {
            if (!$kick || (int)$kick['id'] !== (int)($_POST['kickoff_id'] ?? 0)) {
                throw new RuntimeException('This sign-off has been replaced by a newer one. Look at the page again before answering.');
            }
            $msgs = kickoff_decide($pdo, $project, $kick, $action === 'approve' ? 'approved' : 'changes',
                                   (string)($_POST['note'] ?? ''));
            foreach ($msgs as $m) flash('ok', $m);

        } elseif ($action === 'withdraw') {
            if (!$kick) throw new RuntimeException('There is nothing open to withdraw.');
            kickoff_withdraw($pdo, $kick);
            flash('ok', 'Withdrawn. The project stays in setup, and nobody is waiting on it.');

        } elseif ($action === 'people') {
            if (!is_admin()) throw new RuntimeException('Choosing who signs off is an administrator action.');
            $ticks  = array_map('intval', (array)($_POST['signs'] ?? []));
            $emails = (array)($_POST['email'] ?? []);
            $users  = $pdo->query("SELECT id, role FROM users WHERE active = 1")->fetchAll();
            $up = $pdo->prepare('UPDATE users SET signs_off_kickoff = ?, email = ? WHERE id = ?');
            foreach ($users as $u) {
                $id = (int)$u['id'];
                $email = trim((string)($emails[$id] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('"' . $email . '" is not an email address. Nothing was saved.');
                }
                // Only people who can save may sign off: approving is a POST,
                // and an artist's POST is refused before this page sees it.
                $signs = in_array($id, $ticks, true) && in_array($u['role'], ['admin', 'editor'], true);
                $up->execute([$signs ? 1 : 0, $email !== '' ? $email : null, $id]);
            }
            flash('ok', 'Saved who signs off kick-offs.');
            $back = $selfUrl . '&view=people';
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        if ($action === 'people') $back = $selfUrl . '&view=people';
    }
    header('Location: ' . $back);
    exit;
}

$pageTitle = 'Kick-off · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'overview'); ?>
<h2 class="wizard-title">Kick-off <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>The kick-off sign-off needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>049_kickoff_signoff.sql</code> creates the tables this screen reads.</p>
    <?php if (is_admin()): ?><p><a class="button" href="migrate.php">Review and apply it →</a></p><?php endif; ?>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<div class="kickoff-tabs">
  <a class="button<?= $view === '' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl) ?>">Sign-off</a>
  <a class="button<?= $view === 'compare' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl) ?>&amp;view=compare">Kick-off vs now</a>
  <?php if (is_admin()): ?>
    <a class="button<?= $view === 'people' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl) ?>&amp;view=people">Who signs off</a>
  <?php endif; ?>
</div>

<?php
// ===========================================================================
// WHO SIGNS OFF
// ===========================================================================
if ($view === 'people'):
    if (!is_admin()): ?>
      <p class="muted">Only an administrator chooses who signs off.</p>
    <?php else:
    $users = $pdo->query(
        "SELECT u.id, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.role,
                u.email, u.signs_off_kickoff, c.email AS crew_email
           FROM users u LEFT JOIN crew c ON c.id = u.crew_id
          WHERE u.active = 1 ORDER BY name"
    )->fetchAll(); ?>
  <p class="lead">The people ticked here are asked to approve every project before it goes from setup to
    active, and the project only turns active once all of them have. It applies to every project, not just
    this one.</p>
  <form method="post">
    <input type="hidden" name="action" value="people">
    <table class="data kickoff-people">
      <thead><tr><th>Signs off</th><th>Person</th><th>Role</th><th>Email for sign-off requests</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $canSign = in_array($u['role'], ['admin', 'editor'], true); ?>
        <tr>
          <td><input type="checkbox" name="signs[]" value="<?= (int)$u['id'] ?>"
                     <?= (int)$u['signs_off_kickoff'] === 1 ? 'checked' : '' ?> <?= $canSign ? '' : 'disabled' ?>
                     aria-label="<?= e($u['name']) ?> signs off kick-offs"></td>
          <td><?= e($u['name']) ?> <span class="muted small"><?= e($u['username']) ?></span></td>
          <td><?= e($u['role']) ?><?php if (!$canSign): ?><div class="muted small">Can't sign off: this role can't save.</div><?php endif; ?></td>
          <td><input type="email" name="email[<?= (int)$u['id'] ?>]" value="<?= e((string)$u['email']) ?>"
                     placeholder="<?= e($u['crew_email'] ? $u['crew_email'] . ' (from Staffing)' : 'none') ?>" size="32"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">With no email typed here, the address of the crew member linked to the login on
      Users is used, shown greyed in the box.</p>
    <button type="submit">Save</button>
  </form>
    <?php endif;

// ===========================================================================
// KICK-OFF VS NOW
// ===========================================================================
elseif ($view === 'compare'):
    $kick = kickoff_approved($pdo, $projectId);
    if (!$kick): ?>
  <div class="empty-state">
    <p><strong>This project has no signed-off kick-off to compare with.</strong></p>
    <p class="muted">Projects that went active before the sign-off existed have no record of how they started,
      and one cannot be made now without passing today's numbers off as the original plan. The starting
      estimate per step, where one was recorded, is on <a href="history_quotas.php">Historic quotas</a>.</p>
  </div>
    <?php else:
    $was = json_decode((string)$kick['snapshot'], true) ?: ['sections' => []];
    $now = kickoff_snapshot($pdo, $project);
    $finWas = kickoff_finance_load((int)$kick['id']);
    $finNow = can_see_money() ? kickoff_finance_snapshot($pdo, $project) : null;
    $signs  = kickoff_signoffs($pdo, (int)$kick['id']); ?>
  <p class="lead">How <?= e($project['name']) ?> was set up when it was signed off, against how it stands
    today. Only what moved is listed first; whatever is unchanged is folded underneath.</p>
  <p class="muted small">Signed off <?= e(date('j M Y', strtotime((string)$kick['decided_at']))) ?> by
    <?= e(implode(' and ', array_map(fn($s) => $s['signer_name'], $signs))) ?>, as sent by
    <?= e((string)$kick['requested_by_name']) ?> on <?= e(date('j M Y', strtotime((string)$kick['requested_at']))) ?>.</p>
  <?php foreach (KICKOFF_SECTIONS as $key => $title):
      if ($key === 'finance'):
          if (!can_see_money()) continue;
          if (!$finWas || empty($finWas['configured'])): ?>
            <section class="kickoff-section"><h3><?= e($title) ?></h3>
              <p class="muted">No money side was stored at kick-off<?= $finWas === null && finance_configured() ? ', or the finance database could not be read just now' : '' ?>.</p></section>
          <?php continue; endif;
          kickoff_render_section($title, $finWas, $finNow && !empty($finNow['configured']) ? $finNow : ['figures' => [], 'texts' => [], 'lists' => []],
                                 (string)($finWas['currency'] ?? 'USD'));
          continue;
      endif;
      if (!isset($was['sections'][$key])) continue;
      kickoff_render_section($title, $was['sections'][$key], $now['sections'][$key]);
  endforeach; ?>
    <?php endif;

// ===========================================================================
// THE SIGN-OFF
// ===========================================================================
else:
    $kick  = kickoff_current($pdo, $projectId);
    $me    = current_user();
    $signs = $kick ? kickoff_signoffs($pdo, (int)$kick['id']) : [];
    $mine  = null;
    foreach ($signs as $s) if ((int)$s['user_id'] === (int)($me['id'] ?? 0)) $mine = $s;
    $signers = kickoff_signers($pdo);
    $isOpen  = $kick && in_array($kick['state'], ['pending', 'changes_requested'], true);
?>

  <?php if ($project['status'] === 'setup' && !$isOpen): ?>
    <?php // ---- THE WARNING ------------------------------------------------ ?>
    <div class="flash flash-warn kickoff-warning">
      <p><strong>Going from setup to active is an important change.</strong></p>
      <p>Sending this freezes how <?= e($project['name']) ?> is set up today and asks
        <?= $signers ? e(implode(' and ', array_map(fn($s) => $s['name'], $signers))) : 'the people who sign off' ?>
        to approve it. The project stays in setup until all of them have approved, then turns active by itself.
        What is frozen is what the postmortem will compare the finished show against, so check it is right first.</p>
      <p>Frozen: the Brief, Staffing, Schedule, Timeline, Shots, the Setup (name, type, pipeline, workflow, shots,
        assets, dates and head count) and Finance management (expense projection, invoices, budget pool).</p>
    </div>
    <?php if (!$signers): ?>
      <p class="err">Nobody is set to sign off kick-offs yet.
        <?= is_admin() ? '<a href="' . e($selfUrl) . '&amp;view=people">Choose who signs off →</a>'
                       : 'Ask an administrator to choose who signs off.' ?></p>
    <?php else: ?>
      <ul class="kickoff-signers">
        <?php foreach ($signers as $s): ?>
          <li><?= e($s['name']) ?> <span class="muted"><?= $s['email'] ? e($s['email']) : 'no email on file, will see it here only' ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($why = kickoff_finance_problem()): ?>
        <p class="err"><?= e($why) ?></p>
      <?php elseif (can_edit()): ?>
        <form method="post" class="kickoff-send"
              onsubmit="this.querySelector('button').disabled = true;">
          <input type="hidden" name="action" value="send">
          <label><input type="checkbox" name="understood" value="1" required>
            I've checked the setup and want to freeze it and send it for sign-off</label>
          <button type="submit">Freeze and send for sign-off</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <p><a href="index.php">← Back to the projects, leaving it in setup</a></p>

  <?php elseif (!$kick): ?>
    <p class="muted">This project was not sent for sign-off: it went <?= e(str_replace('_', ' ', $project['status'])) ?>
      before the sign-off existed, so there is no frozen kick-off for it.</p>

  <?php else: ?>
    <?php // ---- WHERE THE SIGN-OFF STANDS ---------------------------------- ?>
    <?php $stateText = [
        'pending'           => 'Waiting for sign-off',
        'approved'          => 'Signed off',
        'changes_requested' => 'Changes asked for',
    ][$kick['state']] ?? $kick['state']; ?>
    <p class="kickoff-state kickoff-state-<?= e($kick['state']) ?>"><strong><?= e($stateText) ?></strong>
      <span class="muted">· sent by <?= e((string)$kick['requested_by_name']) ?>,
        <?= e(date('j M Y, H:i', strtotime((string)$kick['requested_at']))) ?></span></p>

    <table class="data kickoff-signoffs">
      <thead><tr><th>Who</th><th>Answer</th><th>When</th><th>Email</th></tr></thead>
      <tbody>
      <?php foreach ($signs as $s): ?>
        <tr>
          <td><?= e($s['signer_name']) ?></td>
          <td><?= $s['decision'] === 'approved' ? 'Approved'
                : ($s['decision'] === 'changes' ? 'Asked for changes' : '<span class="muted">Not yet</span>') ?>
            <?php if ($s['note']): ?><div class="kickoff-prose small"><?= e($s['note']) ?></div><?php endif; ?></td>
          <td><?= $s['decided_at'] ? e(date('j M Y, H:i', strtotime((string)$s['decided_at']))) : '' ?></td>
          <td class="muted small"><?= e((string)$s['email_result']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($kick['state'] === 'pending' && $mine && $mine['decision'] === null): ?>
      <div class="kickoff-answer">
        <p><strong>You are asked to sign this off.</strong> Below is exactly what was frozen when it was sent.
          The project's own tabs show it live, which may have moved since.</p>
        <form method="post" class="inline-form">
          <input type="hidden" name="kickoff_id" value="<?= (int)$kick['id'] ?>">
          <button type="submit" name="action" value="approve"
                  onclick="return confirm('Approve how <?= e(addslashes($project['name'])) ?> is set up?');">Approve</button>
        </form>
        <details class="kickoff-changes">
          <summary>Ask for changes</summary>
          <form method="post">
            <input type="hidden" name="kickoff_id" value="<?= (int)$kick['id'] ?>">
            <input type="hidden" name="action" value="changes">
            <label>What should change<br>
              <textarea name="note" rows="4" cols="60" required></textarea></label>
            <button type="submit">Send back for changes</button>
          </form>
        </details>
      </div>
    <?php endif; ?>

    <?php if ($isOpen && can_edit() && $project['status'] === 'setup'): ?>
      <div class="kickoff-resend">
        <?php if ($kick['state'] === 'changes_requested'): ?>
          <p>Make the changes on the project's tabs, then send it again. Sending again freezes the setup as it is
            then and asks everybody afresh.</p>
        <?php else: ?>
          <p class="muted small">Changed the setup since sending? Send it again: that freezes it as it is now and
            asks everybody afresh, clearing any approvals already given.</p>
        <?php endif; ?>
        <?php if ($why = kickoff_finance_problem()): ?>
          <p class="err"><?= e($why) ?></p>
        <?php else: ?>
          <form method="post" class="inline-form" onsubmit="this.querySelector('button').disabled = true;">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="understood" value="1">
            <button type="submit" class="secondary">Freeze today's setup and send again</button>
          </form>
        <?php endif; ?>
        <form method="post" class="inline-form"
              onsubmit="return confirm('Withdraw the sign-off request? The project stays in setup.');">
          <input type="hidden" name="action" value="withdraw">
          <button type="submit" class="secondary">Withdraw</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($kick['state'] === 'approved'): ?>
      <p><a class="button" href="<?= e($selfUrl) ?>&amp;view=compare">Compare the kick-off with now →</a></p>
    <?php endif; ?>

    <h3>What was frozen</h3>
    <?php
    $snap = json_decode((string)$kick['snapshot'], true) ?: ['sections' => []];
    $fin  = kickoff_finance_load((int)$kick['id']);
    foreach (KICKOFF_SECTIONS as $key => $title) {
        if ($key === 'finance') {
            if (!can_see_money()) {
                echo '<section class="kickoff-section"><h3>' . e($title) . '</h3><p class="muted">'
                   . 'Stored with the kick-off, and shown to administrators only.</p></section>';
            } elseif ($fin && !empty($fin['configured'])) {
                kickoff_render_section($title, $fin, null, (string)($fin['currency'] ?? 'USD'));
            } else {
                echo '<section class="kickoff-section"><h3>' . e($title) . '</h3><p class="muted">'
                   . 'Finance is not set up on this server, so no money was frozen.</p></section>';
            }
            continue;
        }
        if (isset($snap['sections'][$key])) kickoff_render_section($title, $snap['sections'][$key], null);
    }
    ?>
  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
