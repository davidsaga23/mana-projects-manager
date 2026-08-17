<?php
// One person: what they carry, how it is going, and — for admins only — their
// terms and contract.
//
// The pay half is deliberately walled off. docs/DECISIONS.md, 2026-08-12, kept
// money out of this database entirely; on 2026-08-15 David decided the rate and
// the contract link belong here after all. That reversal is recorded in
// migration 018 and in the decision log. What replaces the old guarantee is
// includes/money.php — the ONE door those columns are read through, which
// refuses for anyone who is not an admin. This screen never names them itself.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/timelog.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/money.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/finance.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$crewId    = (int)($_GET['crew'] ?? $_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$hasDept = column_exists($pdo, 'crew', 'department');
$q = $pdo->prepare('SELECT id, name, role, email, is_team, is_placeholder, active'
    . ($hasDept ? ', department' : '') . ' FROM crew WHERE id = ?');
$q->execute([$crewId]);
$person = $q->fetch();
if (!$person) {
    flash('err', 'That person is no longer in the crew list.');
    header('Location: team.php?project=' . $projectId);
    exit;
}

$selfUrl = 'person.php?project=' . $projectId . '&crew=' . $crewId;

// --- save the non-financial details ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_details'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('err', 'A name is required — nothing was saved.');
    } else {
        $sql = 'UPDATE crew SET name = ?, role = ?, email = ?' . ($hasDept ? ', department = ?' : '') . ' WHERE id = ?';
        $args = [$name, trim($_POST['role'] ?? '') ?: null, trim($_POST['email'] ?? '') ?: null];
        if ($hasDept) $args[] = trim($_POST['department'] ?? '');
        $args[] = $crewId;
        $pdo->prepare($sql)->execute($args);
        flash('ok', 'Details saved.');
    }
    header('Location: ' . $selfUrl);
    exit;
}

// --- save the terms (admin only; the guard lives in money.php) --------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_terms'])) {
    try {
        // Writes to the FINANCE database, not this one. money.php is the only
        // place that connection is ever opened.
        flash('ok', save_crew_terms($crewId, (string)$person['name'], $_POST, current_user_display()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$summary = person_summary($pdo, $projectId, $crewId);
$pace    = person_pace($pdo, $projectId, $crewId, $summary['steps']);
// Reads the finance database. Null for a non-admin, for an unconfigured
// install, or simply for somebody with no rate on file — the screen tells those
// three apart rather than showing one blank either way.
$terms = null; $termsError = null;
try {
    $terms = crew_term($crewId);
} catch (Throwable $e) {
    $termsError = $e->getMessage();
}
$hours   = hours_per_day($pdo, $projectId);
$isSlot  = (int)($person['is_placeholder'] ?? 0) === 1;

// What this person is projected to cost on THIS project: the days the plan asks
// of them, times the rate saved below. Their rows out of the same
// expense_projection() the Finance screen draws, so the two cannot disagree —
// this is a slice of that table, not a second calculation of it.
$myRows = []; $myDays = 0.0; $myCost = 0.0; $myUnpriced = false;
if (can_see_money()) {
    try {
        foreach (expense_projection($pdo, $projectId)['rows'] as $r) {
            if ((int)($r['crew_id'] ?? 0) !== $crewId) continue;
            $myRows[] = $r;
            $myDays += (float)$r['days'];
            if ($r['cost'] === null) $myUnpriced = true;
            else $myCost += (float)$r['cost'];
        }
    } catch (Throwable $e) {
        // The projection is a convenience here, not the point of the page.
        $myRows = [];
    }
}

// Open tickets owned by this person on this project — the first screen where the
// ticket system and the tracking data meet.
$tickets = [];
if (column_exists($pdo, 'tickets', 'id')) {
    $t = $pdo->prepare(
        "SELECT t.id, t.seq, t.title, t.priority, t.due_date, p.code AS project_code,
                st.name AS status_name, st.color AS status_color
           FROM tickets t
           JOIN projects p ON p.id = t.project_id
           LEFT JOIN statuses st ON st.id = t.status_id
          WHERE t.assigned_crew_id = ? AND t.project_id = ? AND t.archived = 0
            AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
          ORDER BY t.due_date IS NULL, t.due_date, t.seq"
    );
    $t->execute([$crewId, $projectId]);
    $tickets = $t->fetchAll();
}

$pageTitle = $person['name'] . ' · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'team'); ?>
<p class="crumb"><a href="team.php?project=<?= $projectId ?>">← Back to the team</a></p>

<h2 class="wizard-title">
  <?= e($person['name']) ?>
  <?php if ($isSlot): ?><span class="slot-tag">planning slot</span><?php endif; ?>
  <span class="muted">· <?= e($project['name']) ?></span>
</h2>

<p class="summary-line">
  <?= e($person['department'] ?? '') ?: 'No department' ?> ·
  <?= e($person['role'] ?? '') ?: 'No role recorded' ?> ·
  <?= $person['email'] ? e($person['email']) : '<span class="warn-text">no email — cannot be notified</span>' ?>
</p>

<?php if ($isSlot): ?>
  <div class="flash flash-warn">
    This is a planning slot, not a person. The figures below are what the plan asks of whoever
    fills it. Renaming the slot on the <a href="crew.php?project=<?= $projectId ?>">Crew screen</a>
    makes all of this theirs — the id never changes, so nothing has to be moved.
  </div>
<?php endif; ?>

<div class="dash-strip">
  <div><span class="muted small">Assigned</span><strong><?= $summary['assigned'] ?></strong></div>
  <div><span class="muted small">Approved</span><strong class="ok"><?= $summary['approved'] ?></strong>
    <span class="muted small"><?= $summary['pct'] === null ? '' : fmt_num($summary['pct'], 1) . '%' ?></span></div>
  <div><span class="muted small">Remaining</span><strong><?= $summary['remaining'] ?></strong></div>
  <div><span class="muted small">Blocked</span>
    <strong class="<?= $summary['blocked'] ? 'warn-text' : '' ?>"><?= $summary['blocked'] ?></strong></div>
  <div><span class="muted small">Time logged</span><strong><?= e(fmt_hours($summary['minutes'])) ?></strong>
    <span class="muted small"><?= $summary['with_time'] ?> of <?= $summary['assigned'] ?> tasks report any</span></div>
  <div><span class="muted small">Planned until</span>
    <strong><?= e($summary['schedule']['last_end'] ?? '—') ?></strong>
    <span class="muted small"><?= (int)$summary['schedule']['remaining'] ?> task(s) still ahead</span></div>
</div>

<h3>Pace, step by step</h3>
<p class="muted small">
  <strong>Required</strong> is the minimum one person must finish each day for that step's
  deadline to hold — the platform's central number, and the same one the dashboard shows.
  <strong>Recent</strong> is approximate: history is recorded per step, not per person, so this
  counts their approved tasks touched in the last 7 working days. A re-import that touches old
  rows inflates it; a week nobody records inflates nothing at all. Read it as a direction, not a
  measurement.
</p>

<?php if (!$summary['steps']): ?>
  <div class="empty-state">
    <p>Nothing is assigned to <?= e($person['name']) ?> on this project yet.</p>
  </div>
<?php else: ?>
<table>
  <thead>
    <tr><th>Step</th><th>Scope</th><th class="num">Assigned</th><th class="num">Approved</th>
        <th class="num">Blocked</th><th class="num">Required /day</th><th class="num">Recent /day</th>
        <th>Deadline</th><th class="num">Time</th></tr>
  </thead>
  <tbody>
  <?php foreach ($summary['steps'] as $row): $pc = $pace[(int)$row['step_id']] ?? null; ?>
    <tr>
      <td class="wf-name">
        <span class="dot" style="background:<?= e($row['color'] ?: '#ccc') ?>"></span><?= e($row['step_name']) ?>
      </td>
      <td class="muted small"><?= e(applies_to_phrase($row['applies_to'], $row['applies_to_asset_type'] ?? null)) ?></td>
      <td class="num"><?= (int)$row['assigned'] ?></td>
      <td class="num ok"><?= (int)$row['approved'] ?></td>
      <td class="num <?= (int)$row['blocked'] ? 'warn-text' : 'muted' ?>"><?= (int)$row['blocked'] ?: '—' ?></td>
      <td class="num"><?= $pc && $pc['required'] !== null ? e(fmt_num((float)$pc['required'], 2)) : '<span class="muted">—</span>' ?></td>
      <td class="num <?= $pc && $pc['on_track'] === false ? 'err' : ($pc && $pc['on_track'] === true ? 'ok' : '') ?>">
        <?= $pc ? e(fmt_num($pc['actual'], 2)) : '—' ?>
      </td>
      <td class="muted small"><?= e($row['deadline'] ?: '—') ?></td>
      <td class="num muted"><?= (int)$row['minutes'] ? e(fmt_hours((int)$row['minutes'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="muted small">A dash under <strong>Required</strong> means that step has no deadline, so
   there is no pace to be behind. A day is <?= e(fmt_num($hours, 1)) ?> hours on this project.</p>
<?php endif; ?>

<?php if ($tickets): ?>
  <h3>Open tickets <span class="muted">· <?= count($tickets) ?></span></h3>
  <table>
    <thead><tr><th>Ref</th><th>Title</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $tk): $c = $tk['status_color'] ?: '#8a97a3'; ?>
      <tr>
        <td><a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e($tk['project_code'] . '-' . (int)$tk['seq']) ?></a></td>
        <td><a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e($tk['title']) ?></a></td>
        <td><span class="status-chip" style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
            <?= e($tk['status_name'] ?? 'no status') ?></span></td>
        <td><?= e(ucfirst($tk['priority'])) ?></td>
        <td class="muted small"><?= e($tk['due_date'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if (can_edit()): ?>
<h3>Details</h3>
<form method="post" class="stack">
  <input type="hidden" name="save_details" value="1">
  <div class="row">
    <label>Name <input name="name" value="<?= e($person['name']) ?>" required></label>
    <label>Role <input name="role" value="<?= e($person['role'] ?? '') ?>" placeholder="e.g. Compositor"></label>
  </div>
  <div class="row">
    <?php if ($hasDept): ?>
      <label>Department
        <input name="department" list="deptList" value="<?= e($person['department'] ?? '') ?>"
               placeholder="e.g. Production">
        <datalist id="deptList">
          <?php foreach (crew_departments($pdo) as $d): ?><option value="<?= e($d) ?>"></option><?php endforeach; ?>
        </datalist>
      </label>
    <?php endif; ?>
    <label>Email <input type="email" name="email" value="<?= e($person['email'] ?? '') ?>"
           placeholder="Needed for ticket notifications"></label>
  </div>
  <div class="actions"><button type="submit">Save details</button></div>
</form>
<?php endif; ?>

<?php // ---- ADMIN ONLY. money.php returns nothing at all for anyone else, so a
      // missing check here would render an empty box rather than leak. ---- ?>
<?php // ---- ADMIN ONLY, and the data lives in a DIFFERENT DATABASE.
      // includes/money.php is the only place that connection is opened, and it
      // returns nothing at all for a non-admin — so a missing check here would
      // render an empty box rather than leak. ---- ?>
<?php if (can_see_money()): ?>
  <h3>Terms and contract <span class="admin-only-tag">administrators only</span></h3>

  <?php if ($termsError !== null): ?>
    <p class="flash flash-err"><?= e($termsError) ?></p>

  <?php elseif (!finance_configured()): ?>
    <div class="empty-state">
      <p><strong>The finance database isn't set up yet.</strong></p>
      <p class="muted">Rates and contracts live in their own database with their own MySQL
         user, so that this application's ordinary connection has no rights to them at all —
         a bug in any normal query cannot reach a rate, because the connection it runs on
         isn't permitted to see one.</p>
      <p class="muted">Import <code>finance_install.sql</code> into a new database, then add
         the <code>finance_db</code> block to <code>config.php</code>. Both are documented in
         that file.</p>
    </div>

  <?php else: ?>
    <p class="muted small">
      Visible to administrators only — <?= e(current_user_display()) ?> is one. Editors and
      view-only accounts never see this section and the data never reaches their browser.
      It is stored in a <strong>separate database</strong>, and the contract itself stays in
      Google Drive under Drive's own permissions.
    </p>
    <form method="post" class="stack">
      <input type="hidden" name="save_terms" value="1">
      <div class="row">
        <label>Rate
          <input name="day_rate" inputmode="decimal" placeholder="e.g. 350.00"
                 value="<?= e($terms && $terms['day_rate'] !== null ? fmt_num((float)$terms['day_rate'], 2) : '') ?>">
        </label>
        <label>Per
          <select name="rate_unit">
            <?php foreach (['day' => 'day', 'hour' => 'hour', 'flat' => 'flat fee'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($terms['rate_unit'] ?? 'day') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Currency
          <input name="rate_currency" maxlength="3" style="text-transform:uppercase"
                 value="<?= e($terms['rate_currency'] ?? 'USD') ?>">
        </label>
      </div>
      <label>Contract link <span class="muted small">(Google Drive)</span>
        <input type="url" name="contract_url" placeholder="https://drive.google.com/…"
               value="<?= e($terms['contract_url'] ?? '') ?>">
      </label>
      <?php if (!empty($terms['contract_url'])): ?>
        <p><a href="<?= e($terms['contract_url']) ?>" target="_blank" rel="noopener">Open the contract →</a></p>
      <?php endif; ?>
      <div class="actions"><button type="submit">Save terms</button></div>
    </form>

    <?php if ($myRows): ?>
      <h4 style="margin:22px 0 6px;">Projected cost on this project</h4>
      <p class="muted small">
        The days the plan asks of <?= e($person['name']) ?> on this show, at the rate above.
        Sliced out of the same projection the <a href="finance_project.php?project=<?= $projectId ?>">Finance
        screen</a> draws, so the two cannot disagree. Save a new rate and this moves with it.
      </p>
      <table class="review-table">
        <thead><tr><th>Position</th><th class="num">Days</th><th class="num">Months</th><th class="num">Projected</th></tr></thead>
        <tbody>
        <?php foreach ($myRows as $r): ?>
          <tr>
            <td class="wf-name">
              <span class="dot" style="background:<?= e($r['step']['color'] ?: '#ccc') ?>"></span><?= e($r['role']) ?>
            </td>
            <td class="num"><?= e(fmt_num($r['days'], 2)) ?></td>
            <td class="num muted"><?= e(fmt_num($r['months'], 2)) ?></td>
            <td class="num">
              <?= $r['cost'] === null
                    ? '<span class="warn-text">no rate yet</span>'
                    : e(number_format($r['cost'], 2)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>Total</th>
            <th class="num"><?= e(fmt_num($myDays, 2)) ?></th>
            <th class="num"><?= e(fmt_num($myDays / DAYS_PER_MONTH, 2)) ?></th>
            <th class="num">
              <?= $myCost > 0
                    ? e(fmt_money_plain($myCost, $terms['rate_currency'] ?? 'USD'))
                    : '<span class="muted">—</span>' ?>
            </th>
          </tr>
        </tfoot>
      </table>
      <?php if ($myUnpriced): ?>
        <p class="muted small warn-text">
          Some positions above have no rate, so the total is lower than the real cost. It counts
          only what it can price rather than treating the rest as free.
        </p>
      <?php endif; ?>
      <p class="muted small">
        Months is days ÷ <?= (int)DAYS_PER_MONTH ?>, the divisor the expense sheet uses.
        These are <strong>planned</strong> days from the pipeline, not hours logged — actual cost
        comes from the invoices attached to each position.
      </p>
    <?php elseif (can_see_money() && $isSlot): ?>
      <p class="muted small" style="margin-top:16px;">
        This slot holds no position on the current plan, so there is nothing to project a cost
        against yet.
      </p>
    <?php endif; ?>

    <?php if (empty($terms)): ?>
      <p class="muted small">Nothing on file for <?= e($person['name']) ?> yet.</p>
    <?php else: ?>
      <p class="muted small">
        Last changed <?= e(substr((string)$terms['updated_at'], 0, 16)) ?><?php
          if (!empty($terms['updated_by'])): ?> by <?= e($terms['updated_by']) ?><?php endif; ?>.
      </p>
    <?php endif; ?>

    <?php $history = crew_terms_history($crewId, 5); if (count($history) > 1): ?>
      <details>
        <summary class="muted small">Recent changes (<?= count($history) ?>)</summary>
        <table class="review-table">
          <tr><th>When</th><th>Rate</th><th>By</th></tr>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="muted small"><?= e(substr((string)$h['changed_at'], 0, 16)) ?></td>
              <td><?= e(fmt_money($h['day_rate'] === null ? null : (float)$h['day_rate'],
                                  $h['rate_unit'] ?: 'day', $h['rate_currency'] ?: 'USD')) ?></td>
              <td class="muted"><?= e($h['changed_by'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </details>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
