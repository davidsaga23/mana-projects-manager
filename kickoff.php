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
require_once __DIR__ . '/includes/budget_lock.php';
// fmt_money_plain(), for the old and new amount of a budget version.
require_once __DIR__ . '/includes/finance.php';
// Reached from the Finance tabs as well as the production side since
// 2026-10-23, and it draws whichever bar it was reached from. ⚠ REQUIRED, NOT
// LEFT TO function_exists(): without this the finance branch below would fail
// its guard silently and draw the PRODUCTION bar, so a finance tab would look
// like it had thrown you back to the other side of the project.
require_once __DIR__ . '/includes/finance_nav.php';
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

$view     = (string)($_GET['view'] ?? '');
$selfUrl  = 'kickoff.php?project=' . $projectId;
$ready    = kickoff_ready($pdo);
// Set to active on the list (or Finish in the wizard) lands here with send=1, so
// the signer pop-up opens on arrival instead of after a second click.
$autoSend = isset($_GET['send']);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back   = $selfUrl;
    try {
        $kick = kickoff_current($pdo, $projectId);
        if ($action === 'send') {
            if (empty($_POST['understood'])) {
                throw new RuntimeException('Tick the box to confirm you have read the warning.');
            }
            foreach (kickoff_request($pdo, $project, (array)($_POST['signers'] ?? [])) as $m) flash('ok', $m);
            flash('ok', $project['status'] === 'setup'
                ? 'The setup is frozen and waiting for sign-off. The project stays in setup until everybody has approved.'
                : 'The re-plan is waiting for sign-off. The project carries on and can still be edited; once everybody '
                  . 'has approved, the plan as it was sent becomes the next kick-off version.');

        } elseif ($action === 'approve' || $action === 'changes') {
            if (!$kick || (int)$kick['id'] !== (int)($_POST['kickoff_id'] ?? 0)) {
                throw new RuntimeException('This sign-off has been replaced by a newer one. Look at the page again before answering.');
            }
            $msgs = kickoff_decide($pdo, $project, $kick, $action === 'approve' ? 'approved' : 'changes',
                                   (string)($_POST['note'] ?? ''));
            foreach ($msgs as $m) flash('ok', $m);

        } elseif ($action === 'override') {
            if (!$kick || (int)$kick['id'] !== (int)($_POST['kickoff_id'] ?? 0)) {
                throw new RuntimeException('This sign-off has been replaced by a newer one. Look at the page again.');
            }
            foreach (kickoff_override($pdo, $project, $kick, (string)($_POST['note'] ?? '')) as $m) flash('ok', $m);

        } elseif ($action === 'withdraw') {
            if (!$kick) throw new RuntimeException('There is nothing open to withdraw.');
            kickoff_withdraw($pdo, $kick);
            flash('ok', $project['status'] === 'setup'
                ? 'Withdrawn. The project stays in setup, and nobody is waiting on it.'
                : 'Withdrawn. The current kick-off version stays, and nobody is waiting on the re-plan.');

        } elseif ($action === 'reopen') {
            if (!$kick) throw new RuntimeException('There is nothing open to reopen.');
            foreach (kickoff_reopen($pdo, $project, $kick) as $m) flash('ok', $m);

        } elseif ($action === 'people') {
            if (!is_admin()) throw new RuntimeException('Choosing who signs off is an administrator action.');
            $ticks  = array_map('intval', (array)($_POST['signs'] ?? []));
            $emails = (array)($_POST['email'] ?? []);
            $users  = $pdo->query("SELECT id, role FROM users WHERE active = 1")->fetchAll();
            $up = $pdo->prepare('UPDATE users SET signs_off_kickoff = ?, email = ? WHERE id = ?');
            // WHO CHANGES A SIGNED-OFF BUDGET (David, 2026-10-28: "Only Jorik
            // can raise a budget"), ticked beside who signs off because it is
            // the other half of the same decision. Administrators only: nobody
            // else can see a budget. Skipped until migration 070 has run.
            $budgetTicks = budget_lock_ready($pdo) ? array_map('intval', (array)($_POST['budget'] ?? [])) : null;
            $upBudget = $budgetTicks !== null ? $pdo->prepare('UPDATE users SET changes_budget = ? WHERE id = ?') : null;
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
                if ($upBudget) {
                    $upBudget->execute([in_array($id, $budgetTicks, true) && $u['role'] === 'admin' ? 1 : 0, $id]);
                }
            }
            flash('ok', $upBudget ? 'Saved who signs off kick-offs and who changes signed-off budgets.'
                                  : 'Saved who signs off kick-offs.');
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

<?php // ⚠ WHICHEVER BAR IT WAS REACHED FROM. This page hangs off both sides of
      // a project: the Projects list and the sign-off email lead here from the
      // production side, and since 2026-10-23 the Finance tabs do too. Drawing
      // one fixed bar would have sent somebody who clicked a finance tab back
      // to the production side without saying so. Same page, same data; only
      // the bar above it differs, exactly as crew.php does. ?>
<?php // is_admin(), the same rule project_section_nav() uses to offer the
      // finance side at all — so a hand-typed &section=finance cannot put a
      // bar of money tabs in front of somebody who has no door to them.
      ?>
<?php if (($_GET['section'] ?? '') === 'finance' && is_admin()): ?>
  <?php finance_nav($project, 'kickoff'); ?>
<?php else: ?>
  <?php project_nav($project, 'overview'); ?>
<?php endif; ?>
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

<?php // Carried on every link inside the page, so moving between its views from
      // the Finance tab keeps the Finance bar instead of dropping back to the
      // production one. ?>
<?php $sec = (($_GET['section'] ?? '') === 'finance' && is_admin()) ? '&section=finance' : ''; ?>
<div class="kickoff-tabs">
  <a class="button<?= $view === '' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl . $sec) ?>">Sign-off</a>
  <a class="button<?= $view === 'compare' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl . $sec) ?>&amp;view=compare">Kick-off vs now</a>
  <?php if (is_admin()): ?>
    <a class="button<?= $view === 'people' ? ' is-current' : ' secondary' ?>" href="<?= e($selfUrl . $sec) ?>&amp;view=people">Who signs off</a>
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
    $budgetCol = budget_lock_ready($pdo);
    $users = $pdo->query(
        "SELECT u.id, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.role,
                u.email, u.signs_off_kickoff, c.email AS crew_email"
        . ($budgetCol ? ', u.changes_budget' : ', 0 AS changes_budget') . "
           FROM users u LEFT JOIN crew c ON c.id = u.crew_id
          WHERE u.active = 1 ORDER BY name"
    )->fetchAll(); ?>
  <p class="lead">The people ticked here are pre-ticked in the pop-up that sends a project for sign-off, on every
    project. Whoever sends it can change the choice there. Each person is emailed at the address below.</p>
  <form method="post">
    <input type="hidden" name="action" value="people">
    <table class="data kickoff-people">
      <thead><tr><th>Signs off</th><th>Person</th><th>Role</th><th>Email for sign-off requests</th>
        <th title="May change a show's budget after its kick-off is signed off">Changes budget</th></tr></thead>
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
          <td><input type="checkbox" name="budget[]" value="<?= (int)$u['id'] ?>"
                     <?= (int)$u['changes_budget'] === 1 ? 'checked' : '' ?> <?= $budgetCol && $u['role'] === 'admin' ? '' : 'disabled' ?>
                     aria-label="<?= e($u['name']) ?> changes signed-off budgets"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">With no email typed here, the address of the crew member linked to the login on
      Users is used, shown greyed in the box.</p>
    <p class="muted small"><strong>Changes budget:</strong> once a show's kick-off is signed off, its approved
      production budget is fixed, and only the administrators ticked here can change it, each time with a
      reason that is kept beside the budget (David, 2026-10-28).<?php if (!$budgetCol): ?>
      <span class="warn-text">This tick needs migration 070 (open migrate.php); until then nobody can change
      a signed-off budget.</span><?php endif; ?></p>
    <button type="submit">Save</button>
  </form>
    <?php endif;

// ===========================================================================
// KICK-OFF VS NOW
// ===========================================================================
elseif ($view === 'compare'):
    // WHAT IS COMPARED WITH WHAT (David, 2026-11-06).
    //   default        the LATEST approved version against now ("'Kick-off vs
    //                  now' always compares with the latest one he approved")
    //   &with=now      the ORIGINAL (v1) against now
    //   &with=<id>     the original against another recorded version ("a
    //                  dropdown to see how the original kick off compares to
    //                  another one recorded"; he chose "Original vs one pick")
    // Versions are listed by kickoff_versions(): the sign-off, then one per
    // approved budget request or re-plan.
    //
    // David, 2026-11-11: "let's include in the top a start version and another
    // one (later) to compare it to". Two pickers replace the fixed pairs:
    //   &from=<id>     any recorded version on the left
    //   &to=<id>|now   a LATER version, or now, on the right (an earlier or
    //                  unknown one falls back to now)
    // The old &with= links above still open what they used to.
    $versions = kickoff_versions($pdo, $projectId);
    $kick = $versions ? $versions[count($versions) - 1] : null;
    $with = (string)($_GET['with'] ?? '');
    $right = null;   // null = now, else a stored version
    $byId = [];
    foreach ($versions as $v) $byId[(int)$v['id']] = $v;
    $fromId = (int)($_GET['from'] ?? 0);
    if ($fromId && isset($byId[$fromId])) {
        $kick = $byId[$fromId];
        $toId = (int)($_GET['to'] ?? 0);
        if ($toId && isset($byId[$toId]) && (int)$byId[$toId]['n'] > (int)$kick['n']) $right = $byId[$toId];
    } elseif ($versions && $with !== '') {
        if ($with === 'now') {
            $kick = $versions[0];
        } else {
            foreach ($versions as $v) {
                if ((int)$v['id'] === (int)$with && (int)$v['n'] > 1) { $kick = $versions[0]; $right = $v; }
            }
        }
    }
    if (!$kick): ?>
  <div class="empty-state">
    <p><strong>This project has no signed-off kick-off to compare with.</strong></p>
    <p class="muted">Projects that went active before the sign-off existed have no record of how they started,
      and one cannot be made now without passing today's numbers off as the original plan. The starting
      estimate per step, where one was recorded, is on <a href="history_quotas.php">Historic quotas</a>.</p>
  </div>
    <?php else:
    $was    = json_decode((string)$kick['snapshot'], true) ?: ['sections' => []];
    $finWas = kickoff_finance_load((int)$kick['id']);
    if ($right === null) {
        $now    = kickoff_snapshot($pdo, $project);
        $finNow = can_see_money() ? kickoff_finance_snapshot($pdo, $project) : null;
    } else {
        $now    = json_decode((string)$right['snapshot'], true) ?: ['sections' => []];
        $finNow = kickoff_finance_load((int)$right['id']);
    }
    $vName = fn($v) => 'v' . (int)$v['n'] . ', ' . date('j M Y', strtotime((string)$v['decided_at']));
    // The last two are the short names over the paired columns of a list.
    kickoff_labels([$vName($kick), $right === null ? 'Now' : $vName($right),
                    'v' . (int)$kick['n'], $right === null ? 'Now' : 'v' . (int)$right['n']]);
    $latestN = count($versions);
    // One line per version: what made it, who approved it, and for a budget
    // change the amounts and the reason (administrators only: it is money).
    $describe = function (array $v) use ($pdo): string {
        $names = implode(' and ', array_map(fn($s) => $s['signer_name'], kickoff_signoffs($pdo, (int)$v['id'])));
        if ($v['kind'] === 'budget_change') {
            $line = 'budget change approved by ' . ($names ?: (string)$v['requested_by_name']);
            $fv = can_see_money() ? (kickoff_finance_load((int)$v['id'])['version'] ?? null) : null;
            if (is_array($fv)) {
                $line .= ': ' . ($fv['old_amount'] === null ? 'none' : fmt_money_plain((float)$fv['old_amount'], (string)$fv['old_currency']))
                       . ' → ' . ($fv['new_amount'] === null ? 'none' : fmt_money_plain((float)$fv['new_amount'], (string)$fv['new_currency']))
                       . ', “' . $fv['reason'] . '”';
            }
            return $line;
        }
        return ($v['kind'] === 'replan' ? 're-plan sent by ' : 'kick-off sent by ') . $v['requested_by_name']
             . ', signed off by ' . $names;
    }; ?>
  <?php if ($latestN > 1): ?>
    <form method="get" class="inline-form kickoff-pick-compare">
      <input type="hidden" name="project" value="<?= (int)$projectId ?>">
      <input type="hidden" name="view" value="compare">
      <?php if ($sec): ?><input type="hidden" name="section" value="finance"><?php endif; ?>
      <label>From
        <select name="from" onchange="this.form.submit()">
          <?php foreach ($versions as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === (int)$kick['id'] ? 'selected' : '' ?>><?= e($vName($v)) ?><?= (int)$v['n'] === 1 ? ' (original)' : ((int)$v['n'] === $latestN ? ' (latest)' : '') ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>to
        <select name="to" onchange="this.form.submit()">
          <?php foreach ($versions as $v): if ((int)$v['n'] <= (int)$kick['n']) continue; ?>
            <option value="<?= (int)$v['id'] ?>" <?= $right && (int)$right['id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($vName($v)) ?><?= (int)$v['n'] === $latestN ? ' (latest)' : '' ?></option>
          <?php endforeach; ?>
          <option value="now" <?= $right === null ? 'selected' : '' ?>>Now</option>
        </select></label>
      <noscript><button type="submit">Show</button></noscript>
    </form>
  <?php endif; ?>
  <p class="lead">How <?= e($project['name']) ?> stood in kick-off <?= e($vName($kick)) ?><?= (int)$kick['n'] === $latestN ? ' (the latest approved)' : ((int)$kick['n'] === 1 ? ' (the original)' : '') ?>,
    against <?= $right === null ? 'how it stands today' : 'kick-off ' . e($vName($right)) ?>.
    Only what moved is listed first; whatever is unchanged is folded underneath.</p>
  <?php if ($latestN > 1): ?>
    <details class="kickoff-versions-list">
      <summary class="muted small">All <?= $latestN ?> kick-off versions</summary>
      <ol class="kickoff-versions">
        <?php foreach ($versions as $v): ?>
          <li><strong><?= e($vName($v)) ?></strong>: <?= e($describe($v)) ?></li>
        <?php endforeach; ?>
      </ol>
    </details>
  <?php else: ?>
    <p class="muted small"><?= e($vName($kick)) ?>: <?= e($describe($kick)) ?>.</p>
  <?php endif; ?>
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
      // ?? because a stored version on the right may predate a section.
      kickoff_render_section($title, $was['sections'][$key],
                             $now['sections'][$key] ?? ['figures' => [], 'texts' => [], 'lists' => []]);
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
    $candidates = kickoff_candidates($pdo);
    $isOpen  = $kick && in_array($kick['state'], ['pending', 'changes_requested'], true);

    // THE POP-UP that sends it (David, 2026-10-16: "a pop up window choosing
    // from the users who should sign off"). Pre-ticked with whoever was asked
    // last time, else with the defaults under Who signs off.
    $askedBefore = array_map(fn($s) => (int)$s['user_id'], $signs);
    $sendDialog = function (string $buttonLabel, string $buttonClass, bool $replan = false) use ($candidates, $askedBefore, $project) {
        if ($why = kickoff_finance_problem()) { echo '<p class="err">' . e($why) . '</p>'; return; }
        if (!can_edit()) return; ?>
        <button type="button" class="<?= e($buttonClass) ?>"
                onclick="document.getElementById('kickoff-send-dialog').showModal()"><?= e($buttonLabel) ?></button>
        <dialog id="kickoff-send-dialog" class="kickoff-dialog">
          <form method="post" class="kickoff-send" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
            <input type="hidden" name="action" value="send">
            <h3>Who should sign off <?= e($project['name']) ?><?= $replan ? "'s re-plan" : '' ?>?</h3>
            <p class="muted small"><?= $replan
                ? 'The plan as it is now becomes the next kick-off version once everybody ticked here has approved.'
                : 'The project turns active once everybody ticked here has approved.' ?> Each is emailed a
              link; they log in to Anima to answer.</p>
            <?php if (!$candidates): ?>
              <p class="err">There is nobody with an admin or editor login to ask.</p>
            <?php endif; ?>
            <?php foreach ($candidates as $c):
                $on = $askedBefore ? in_array((int)$c['id'], $askedBefore, true) : (int)$c['is_default'] === 1; ?>
              <label class="kickoff-pick"><input type="checkbox" name="signers[]" value="<?= (int)$c['id'] ?>" <?= $on ? 'checked' : '' ?>>
                <?= e($c['name']) ?> <span class="muted small"><?= $c['email'] ? e($c['email']) : 'no email on file, will only see it in Anima' ?></span></label>
            <?php endforeach; ?>
            <hr>
            <label class="kickoff-pick"><input type="checkbox" name="understood" value="1" required>
              <?= $replan ? "I've checked the new plan and want to send it for sign-off"
                          : "I've checked the setup and want to freeze it and send it for sign-off" ?></label>
            <div class="inline-form">
              <button type="submit"><?= $replan ? 'Send for sign-off' : 'Freeze and send' ?></button>
              <button type="button" class="secondary" onclick="this.closest('dialog').close()">Cancel</button>
            </div>
          </form>
        </dialog>
    <?php };
?>

  <?php if ($project['status'] === 'setup' && !$isOpen): ?>
    <?php // ---- THE WARNING ------------------------------------------------ ?>
    <div class="flash flash-warn kickoff-warning">
      <p><strong>Going from setup to active is an important change.</strong></p>
      <p>Sending this freezes how <?= e($project['name']) ?> is set up today and asks the people you choose to
        approve it. Until they answer, nothing on the project's setup can be changed. It turns active by itself once
        all of them have approved. What is frozen is what the postmortem will compare the finished show against,
        so check it is right first.</p>
      <p>Frozen: the Brief, Staffing, Schedule, Timeline, Shots, the Setup (name, type, pipeline, workflow, shots,
        assets, dates and head count) and Finance management (expense projection, invoices, budget pool).</p>
    </div>
    <?php $sendDialog('Choose who signs off and send…', ''); ?>
    <?php if ($autoSend): ?>
      <script>
        (function () {
          var d = document.getElementById('kickoff-send-dialog');
          if (d && typeof d.showModal === 'function' && !d.open) d.showModal();
        })();
      </script>
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
    <?php $kickKind = kickoff_kind($kick); ?>
    <p class="kickoff-state kickoff-state-<?= e($kick['state']) ?>"><strong><?= e($stateText) ?></strong>
      <span class="muted">· <?= $kickKind === 'budget_change' ? 'new version saved when the budget was changed by'
                               : ($kickKind === 'replan' ? 're-plan sent by' : 'sent by') ?>
        <?= e((string)$kick['requested_by_name']) ?>,
        <?= e(date('j M Y, H:i', strtotime((string)$kick['requested_at']))) ?></span></p>
    <?php if ($kickKind !== 'signoff' && $kick['state'] === 'approved'): ?>
      <p class="muted small">An approved budget change or re-plan saves the show as a new kick-off version, and
        <a href="<?= e($selfUrl . $sec) ?>&amp;view=compare">Kick-off vs now</a> compares with the latest one.
        Earlier versions, the original sign-off among them, are listed there.</p>
    <?php endif; ?>
    <?php if ($kick['state'] === 'pending' && $kickKind === 'replan'): ?>
      <div class="flash">This re-plan is waiting for sign-off. The project is not frozen: it carries on, and its
        current kick-off version stays the one compared with until everybody has approved.</div>
    <?php elseif ($kick['state'] === 'pending'): ?>
      <div class="flash flash-warn">The project is frozen until this is answered: its setup screens refuse
        every save. Asking for changes, or withdrawing, unfreezes it.</div>
    <?php endif; ?>

    <table class="data kickoff-signoffs">
      <thead><tr><th>Who</th><th>Answer</th><th>When</th><th>Email</th></tr></thead>
      <tbody>
      <?php foreach ($signs as $s): ?>
        <tr>
          <td><?= e($s['signer_name']) ?></td>
          <td><?= $s['decision'] === 'approved'
                    ? ($s['approved_by_override'] ? 'Approved by ' . e($s['approved_by_override']) . ' on their behalf' : 'Approved')
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
        <p><strong>You are asked to sign this off.</strong> Below is exactly what was <?= $kickKind === 'replan'
          ? 'sent. It is a re-plan, so the project was not frozen: its own tabs may have moved on since'
          : 'frozen when it was sent, which is also what the project\'s own tabs show, since nothing can change until you answer' ?>.</p>
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

    <?php if ($kick['state'] === 'pending' && is_admin()
              && array_filter($signs, fn($s) => $s['decision'] === null && (int)$s['user_id'] !== (int)($me['id'] ?? 0))): ?>
      <details class="kickoff-resend">
        <summary>Somebody away? Approve on their behalf</summary>
        <p class="muted small">Approves for everybody who has not answered yet, turns the project active, and emails
          everybody involved that its original state is being set without their sign-off. The reason goes in the
          record and the email.</p>
        <form method="post" onsubmit="return confirm('Approve on behalf of everybody who has not answered?');">
          <input type="hidden" name="kickoff_id" value="<?= (int)$kick['id'] ?>">
          <input type="hidden" name="action" value="override">
          <label>Why it can't wait for them<br><textarea name="note" rows="3" cols="60" required></textarea></label>
          <button type="submit" class="secondary">Approve on their behalf</button>
        </form>
      </details>
    <?php endif; ?>

    <?php if ($isOpen && can_edit() && $project['status'] !== 'setup' && $kickKind === 'replan'): ?>
      <?php // A RE-PLAN is not frozen, so there is nothing to reopen: it is
            // withdrawn, or sent again with the plan as it is by then. ?>
      <div class="kickoff-resend">
        <p>Changed the plan again since sending it? Send it again: that replaces this request with the plan as it
          is now and asks everybody afresh. Or withdraw it.</p>
        <?php $sendDialog('Send the re-plan again…', '', true); ?>
        <form method="post" class="inline-form"
              onsubmit="return confirm('Withdraw the re-plan sign-off request? The current kick-off version stays.');">
          <input type="hidden" name="action" value="withdraw">
          <button type="submit" class="secondary">Withdraw</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($isOpen && can_edit() && $project['status'] === 'setup'): ?>
      <div class="kickoff-resend">
        <?php if ($kick['state'] === 'pending'): ?>
          <p>Need to change something while this is waiting? Reopen it for editing. That cancels this request,
            unfreezes the project, and emails the people on the sign-off list that it is open again and to wait for
            a new request. Make your changes, then send it for sign-off again.</p>
          <form method="post" class="inline-form"
                onsubmit="return confirm('Reopen <?= e(addslashes($project['name'])) ?> for editing? This cancels the sign-off request and tells the people on it to wait for a new one.');">
            <input type="hidden" name="action" value="reopen">
            <button type="submit">Reopen edits</button>
          </form>
        <?php else: // changes_requested — already unfrozen, the normal path is to re-send ?>
          <p>The project is unfrozen. Make the changes on its tabs, then send it again: that freezes the setup as it
            is then, replaces this kick-off, and asks everybody afresh.</p>
          <?php $sendDialog('Freeze the new setup and send again…', ''); ?>
          <form method="post" class="inline-form"
                onsubmit="return confirm('Withdraw the sign-off request? The project stays in setup, unfrozen.');">
            <input type="hidden" name="action" value="withdraw">
            <button type="submit" class="secondary">Withdraw</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>


    <?php if ($kick['state'] === 'approved'): ?>
      <p><a class="button" href="<?= e($selfUrl . $sec) ?>&amp;view=compare">Compare the kick-off with now →</a></p>
    <?php endif; ?>
    <?php // SEND A RE-PLAN (David, 2026-11-06). On a signed-off project with
          // nothing waiting: the plan as it is now goes to the people chosen,
          // and their approval makes it the next kick-off version. Not frozen. ?>
    <?php if (!$isOpen && $project['status'] !== 'setup' && kickoff_approved($pdo, $projectId) && can_edit()): ?>
      <div class="kickoff-resend">
        <p><strong>Re-planned the show?</strong> Send it for sign-off again. Once everybody you choose has approved,
          the plan as it is when you send it becomes kick-off version <?= count(kickoff_versions($pdo, $projectId)) + 1 ?>,
          and Kick-off vs now compares with it. The project carries on and can be edited while they look.</p>
        <?php $sendDialog('Send a re-plan for sign-off…', '', true); ?>
      </div>
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
