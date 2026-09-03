<?php
// One person, recorded more than once, put back together.
//
// David, 2026-09-02: James Douglas Murphy three times in the "already on the
// books" picker, Agustin Scavino twice. See includes/people_merge.php for how
// they got there and why this is the one screen in the app that destroys a
// crew.id.
//
// ADMIN ONLY, and not as seniority. The safety checks run through
// can_see_money(), which IS is_admin(): the finance database keys crew_terms on
// crew.id with no foreign key between the two databases, so a merge that cannot
// look at the money side must not happen. Same rule as deleting a project.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/people_merge.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $keep = (int)($_POST['keep'] ?? 0);
        $lose = array_map('intval', (array)($_POST['lose'] ?? []));
        // Typing the name, not clicking OK. This deletes records that hold
        // scheduled work, and a confirm dialog is dismissed by reflex. Same
        // reasoning as typing a project code to delete a project.
        $typed = trim((string)($_POST['confirm_name'] ?? ''));
        $expect = trim((string)($_POST['expect_name'] ?? ''));
        if ($expect === '' || mb_strtolower($typed) !== mb_strtolower($expect)) {
            throw new RuntimeException(sprintf(
                'Nothing was merged. To merge these records, type the name, %s, in the box.',
                $expect !== '' ? $expect : 'exactly as it is written'));
        }
        flash('ok', merge_crew($pdo, $keep, $lose, current_user_display()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: people_merge.php');
    exit;
}

$groups = duplicate_crew_groups($pdo);

$pageTitle = 'Duplicate people';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">&larr; Projects</a></p>
<h2 class="wizard-title">Duplicate people <span class="admin-only-tag">administrators only</span></h2>

<p class="lead">
  One person recorded more than once in the studio crew list. Merging keeps one record and folds
  the others into it, so every slot, scheduled task, ticket, timesheet and hiring record they
  hold ends up on the same person.
</p>

<?php if (!$groups): ?>
  <div class="empty-state">
    <h2>Nobody is recorded twice</h2>
    <p class="muted">Every person in the studio crew list has exactly one record.</p>
  </div>
<?php else: ?>

<?php // WHY THIS HAPPENED, said once and only while there is something to fix.
      // Somebody arriving here needs to know it is not their filing that went
      // wrong, and that the cause is already closed. ?>
<div class="flash flash-warn">
  <strong>These came from the old <em>Register hire</em> button on the Crew screen.</strong>
  Every slot owns its own record, and registering a hire renamed that record into the person
  without checking whether the name already existed, so the same artist put into three slots
  became three people. That button is gone as of 2 September 2026, so no new ones are being
  made. What is below is the ones already here.
</div>

<?php foreach ($groups as $key => $members): ?>
  <?php
    // Everything about each record, gathered before anything is drawn, so the
    // page can suggest which one to keep rather than leaving it to guesswork.
    $rows = [];
    $best = null; $bestScore = -1;
    foreach ($members as $m) {
        $id = (int)$m['id'];
        $use = crew_usage($pdo, $id);
        $money = crew_money_footprint($id);
        // The record worth keeping is the one carrying the most that would
        // otherwise have to move. Logins and a day rate count for more than a
        // slot: one is somebody's way into the app, the other is what they cost.
        $score = $use['logins'] * 100 + $money['terms'] * 60 + $money['invoices'] * 20
               + $use['time_logs'] * 5 + $use['tickets'] * 3 + $use['scheduled']
               + $use['slots'] + $use['hires'] * 2;
        $rows[] = ['crew' => $m, 'use' => $use, 'money' => $money, 'score' => $score];
        if ($score > $bestScore) { $bestScore = $score; $best = $id; }
    }
    $name = (string)$members[0]['name'];
    $moneyBlocked = false;
    $withTerms = 0;
    foreach ($rows as $r) {
        if (!$r['money']['reachable']) $moneyBlocked = true;
        if ($r['money']['terms'] > 0) $withTerms++;
    }
  ?>
  <h3 class="history-head"><?= e($name) ?>
    <span class="muted small"><?= count($members) ?> records</span>
  </h3>

  <form method="post">
    <input type="hidden" name="expect_name" value="<?= e($name) ?>">
    <table class="grid-table merge-table">
      <thead><tr>
        <th class="num">Keep</th><th>Record</th><th class="num">Slots</th>
        <th class="num">Scheduled</th><th class="num">Tickets</th><th class="num">Time logs</th>
        <th class="num">Hiring</th><th>Login</th><th>Money</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $m = $r['crew']; $id = (int)$m['id']; $u = $r['use']; ?>
          <tr>
            <td class="num">
              <?php // A radio, not a checkbox: exactly one record survives, and
                    // the control should not be able to say otherwise. ?>
              <input type="radio" name="keep" value="<?= $id ?>"
                     <?= $id === $best ? 'checked' : '' ?>
                     aria-label="Keep record <?= $id ?>">
              <input type="hidden" name="lose[]" value="<?= $id ?>">
            </td>
            <td>
              <strong><?= e($m['name']) ?></strong>
              <div class="muted small">
                #<?= $id ?><?= $m['email'] ? ' &middot; ' . e($m['email']) : '' ?>
                <?= $m['role'] ? ' &middot; ' . e($m['role']) : '' ?>
                <?= (int)$m['active'] === 0 ? ' &middot; inactive' : '' ?>
                &middot; added <?= e(substr((string)$m['created_at'], 0, 10)) ?>
              </div>
            </td>
            <td class="num"><?= $u['slots'] ?: '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= $u['scheduled'] ?: '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= $u['tickets'] ?: '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= $u['time_logs'] ?: '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= $u['hires'] ?: '<span class="muted">&mdash;</span>' ?></td>
            <td class="small"><?= $u['logins']
                  ? '<strong>yes</strong>' : '<span class="muted">&mdash;</span>' ?></td>
            <td class="small">
              <?php if (!$r['money']['reachable']): ?>
                <span class="err">could not be read</span>
              <?php elseif ($r['money']['terms'] > 0): ?>
                <strong>day rate</strong><?php if ($r['money']['invoices']): ?>
                  <span class="muted">&middot; <?= (int)$r['money']['invoices'] ?> inv</span>
                <?php endif; ?>
              <?php elseif ($r['money']['invoices']): ?>
                <span class="muted"><?= (int)$r['money']['invoices'] ?> invoices</span>
              <?php else: ?>
                <span class="muted">&mdash;</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php // THE TWO REASONS A MERGE IS REFUSED, said before the button rather
          // than after pressing it. ?>
    <?php if ($moneyBlocked): ?>
      <div class="flash flash-err">
        The finance database would not answer for one of these records, so this merge cannot be
        made. That is the safe answer to a question that could not be asked, not a fault in the
        records.
      </div>
    <?php elseif ($withTerms > 1): ?>
      <div class="flash flash-warn">
        <strong>More than one of these carries a day rate</strong>, so merging is refused rather
        than one of them being picked for you. Two rates for one person is a real disagreement
        about what they cost. Decide which is right under Finance management, clear the other,
        then come back.
      </div>
    <?php else: ?>
      <div class="inline-form merge-confirm">
        <label class="inline">Type <strong><?= e($name) ?></strong> to confirm
          <input name="confirm_name" size="24" autocomplete="off"
                 aria-label="Type the name to confirm the merge"></label>
        <button type="submit" class="danger">Merge into the one ticked</button>
      </div>
      <p class="muted small">
        The records not ticked are deleted, and their names are kept as aliases of the one that
        survives, so an import that still uses them finds the right person instead of making a
        fourth copy. This cannot be undone.
      </p>
    <?php endif; ?>
  </form>
<?php endforeach; ?>

<?php endif; ?>

</div>
</body></html>
