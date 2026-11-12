<?php
// LOG REMINDERS: who has logged today, and a button to remind them now.
//
// David, 2026-11-11: "add pop up reminders for everyone to log times at the end
// of the day and a button for admins to send and instant pop up for logging
// times as an alternative". The end-of-day pop-up needs nobody to press
// anything (includes/log_reminders.php); this screen is the alternative, for
// the days somebody needs the numbers now.
//
// Admins, and Production while "Send a reminder to log time" is on for them
// (Users → Access; require_login() checks the switch, FEATURE_SCRIPTS). Every
// reminder sent is recorded here: who sent it, when, to whom, whether it was
// emailed, and how many have put it away.
//
// ⚠ NO MONEY ON THIS SCREEN. It shows hours logged today and nothing else: no
// rate, no cost, no budget. Hours are production facts (Home and My tasks show
// them to the artists themselves).

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/log_reminders.php';
require_login();
// The second lock: the switch is locked off for every role below Production in
// FEATURES, and this keeps the screen to those two roles whatever it says.
if (!is_admin() && !can_edit()) {
    flash('err', 'That screen is for administrators and Production.');
    header('Location: index.php');
    exit;
}

$today = date('Y-m-d');
$ready = log_reminders_available($pdo);
$people = log_reminder_people($pdo, $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    try {
        // Only people this screen listed: an id typed into the form for somebody
        // with no active login, or nobody at all, is dropped.
        $chosen = array_values(array_filter(array_map('intval', (array)($_POST['crew'] ?? [])),
                                            fn($id) => isset($people[$id])));
        $onShow = array_keys(array_filter($people, fn($p) => $p['on_show'] && !$p['away']));
        sort($onShow);
        $sorted = $chosen;
        sort($sorted);
        $toAll = $onShow && $sorted === $onShow;
        flash('ok', log_reminder_send($pdo, $chosen, $toAll, !empty($_POST['email']),
                                      (string)($_POST['note'] ?? ''),
                                      (int)$_SESSION['user_id'], current_user_display()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: log_reminders.php');
    exit;
}

$history = log_reminder_history($pdo);
$working = is_working_day($today);
$after = (int)date('G') >= LOG_REMINDER_HOUR;
$counts = ['owe' => 0, 'logged' => 0];
foreach ($people as $p) {
    if ($p['on_show'] && !$p['away'] && $working) {
        $p['logged'] > 0 ? $counts['logged']++ : $counts['owe']++;
    }
}
$roleName = fn(string $r): string => ['admin' => 'Admin', 'editor' => 'Production', 'supervisor' => 'Supervisor',
                                      'artist' => 'Artist', 'client' => 'Client'][$r] ?? $r;

$pageTitle = 'Log reminders';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Log reminders</h2>

<p class="lead">Everyone on a show gets a pop-up at <?= LOG_REMINDER_HOUR > 12 ? (LOG_REMINDER_HOUR - 12) . ' pm' : LOG_REMINDER_HOUR . ' am' ?>
  on working days if they haven't logged that day. To remind people now instead, tick them and press Send.</p>

<?php if (!$ready): ?>
  <div class="flash flash-warn">
    Sending a reminder needs <strong>migration 080</strong>. The 5 pm pop-up already works without it.
    <?= is_admin() ? '<a href="migrate.php">Review and apply it →</a>' : 'Ask an administrator to run it.' ?>
  </div>
<?php endif; ?>

<div class="dash-strip">
  <div><span class="muted small">Today</span><strong><?= e(date('D j M')) ?></strong></div>
  <?php if ($working): ?>
    <div class="<?= $counts['owe'] ? 'tile-pending' : '' ?>"><span class="muted small">On a show, nothing logged</span><strong><?= $counts['owe'] ?></strong></div>
    <div><span class="muted small">On a show, logged</span><strong><?= $counts['logged'] ?></strong></div>
    <div><span class="muted small">5 pm pop-up</span><strong><?= $after ? 'Showing' : 'Not yet' ?></strong></div>
  <?php else: ?>
    <div><span class="muted small">5 pm pop-up</span><strong>Not today</strong></div>
  <?php endif; ?>
</div>
<?php if (!$working): ?>
  <p class="muted small">Today is a weekend or a studio holiday, so nobody is reminded automatically. You can still send one.</p>
<?php endif; ?>

<form method="post" id="logremForm">
  <input type="hidden" name="action" value="send">
  <p class="small">
    Tick:
    <button type="button" class="linkish" data-pick="owe">nothing logged yet</button> ·
    <button type="button" class="linkish" data-pick="show">everybody on a show</button> ·
    <button type="button" class="linkish" data-pick="none">nobody</button>
  </p>
  <table class="review-table">
    <thead>
      <tr><th style="width:2em;"></th><th>Person</th><th>Role</th><th>Today</th><th>Logged today</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($people as $p):
        $owes = $p['on_show'] && !$p['away'] && $working && $p['logged'] <= 0;
        if ($p['away'])          $state = 'Day off';
        elseif (!$p['on_show'])  $state = 'No show today';
        else                     $state = 'On a show';
      ?>
        <tr>
          <td><input type="checkbox" name="crew[]" value="<?= (int)$p['crew_id'] ?>"
                     aria-label="Remind <?= e($p['name']) ?>"
                     data-owe="<?= $owes ? 1 : 0 ?>" data-show="<?= ($p['on_show'] && !$p['away']) ? 1 : 0 ?>"
                     <?= $owes ? 'checked' : '' ?> <?= $ready ? '' : 'disabled' ?>></td>
          <td><strong><?= e($p['name']) ?></strong></td>
          <td class="muted small"><?= e($roleName($p['role'])) ?></td>
          <td class="small <?= $p['on_show'] && !$p['away'] ? '' : 'muted' ?>"><?= e($state) ?></td>
          <td class="small <?= $owes ? 'warn-text' : ($p['logged'] > 0 ? '' : 'muted') ?>"><?= $p['logged'] > 0 ? e(fmt_hours($p['logged'])) : 'Nothing' ?></td>
          <td class="muted small">
            <?php if (!$p['switch_on']): ?>Reminders switched off for them (Users → Access)<?php endif; ?>
            <?php if ($p['email'] === ''): ?><?= !$p['switch_on'] ? ' · ' : '' ?>No email on file<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$people): ?>
        <tr><td colspan="6" class="muted">No active logins are linked to a person yet (Users → Is which person).</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:14px;">
    <label for="logremNote" class="small muted" style="display:block;">A line from you (optional)</label>
    <input type="text" id="logremNote" name="note" maxlength="255" style="width:min(520px,100%);"
           placeholder="e.g. Jorik needs this week's MSD days by tonight" <?= $ready ? '' : 'disabled' ?>>
  </div>
  <label class="small" style="display:inline-flex;gap:6px;align-items:center;margin-top:10px;">
    <input type="checkbox" name="email" value="1" style="width:auto;" <?= $ready ? '' : 'disabled' ?>>
    Also email them (slower: the page waits for the mail server)
  </label>
  <div>
    <button type="submit" <?= $ready ? '' : 'disabled' ?>>Send reminder now</button>
  </div>
  <p class="muted small">It pops up on their open Anima pages within a few minutes, and waits in their bell
    for when they next open it. People switched off in Users → Access never see the pop-up.</p>
</form>

<h3>Sent</h3>
<?php if (!$history): ?>
  <p class="muted small">No reminders sent yet.</p>
<?php else: ?>
  <table class="review-table">
    <thead><tr><th>When</th><th>By</th><th>To</th><th>Emailed</th><th>Put away</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="small"><?= e(date('D j M, H:i', strtotime((string)$h['sent_at']))) ?></td>
          <td><?= e($h['sent_by_name'] ?: '—') ?></td>
          <td class="small">
            <?= !empty($h['to_all']) ? '<strong>Everybody on a show</strong> · ' : '' ?><?= (int)$h['recipients'] ?>
            <?= (int)$h['recipients'] === 1 ? 'person' : 'people' ?>
            <div class="muted"><?= e((string)$h['names']) ?></div>
            <?php if (!empty($h['note'])): ?><div class="muted">“<?= e((string)$h['note']) ?>”</div><?php endif; ?>
          </td>
          <td class="small"><?= !empty($h['emailed']) ? 'Yes' : 'No' ?></td>
          <td class="small"><?= (int)$h['dismissed'] ?> of <?= (int)$h['recipients'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<script>
// The three "Tick:" shortcuts. Nothing is sent until Send is pressed.
document.querySelectorAll('#logremForm [data-pick]').forEach(function (b) {
  b.addEventListener('click', function () {
    var pick = b.getAttribute('data-pick');
    document.querySelectorAll('#logremForm input[name="crew[]"]:not([disabled])').forEach(function (c) {
      c.checked = pick === 'owe' ? c.getAttribute('data-owe') === '1'
                : pick === 'show' ? c.getAttribute('data-show') === '1' : false;
    });
  });
});
</script>

</div>
</body></html>
