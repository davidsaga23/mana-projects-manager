<?php
// One project's money: the agreed budget, what the plan will cost, and what has
// actually been invoiced. ADMIN ONLY.
//
// The three numbers this screen exists to put side by side are the four steps of
// the production cycle that had no home (ROADMAP): budget, project expense,
// invoices, and the report that is simply those three next to each other.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/finance.php';
require_admin();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: finance.php');
    exit;
}

$selfUrl = 'finance_project.php?project=' . $projectId;
$who = current_user_display();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_budget'])) {
            flash('ok', save_project_budget($projectId, $project, $_POST, $who));
        } elseif (isset($_POST['save_invoice'])) {
            flash('ok', save_invoice($_POST, $project, $who));
        } elseif (isset($_POST['delete_invoice'])) {
            flash('ok', delete_invoice((int)$_POST['delete_invoice']));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$configured = finance_configured();
$dbError = null;
$budget = null; $invoices = [];
if ($configured) {
    try {
        $budget   = project_budget($projectId);
        $invoices = project_invoices($projectId);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$proj    = expense_projection($pdo, $projectId);
$people  = project_people($pdo, $projectId);
$cur     = $budget['currency'] ?? 'USD';
$budgetAmount = $budget && $budget['budget_amount'] !== null ? (float)$budget['budget_amount'] : null;

$billed = 0.0; $paid = 0.0; $unpaid = 0.0; $overdue = 0;
$todayStr = date('Y-m-d');
foreach ($invoices as $iv) {
    $billed += (float)$iv['amount'];
    if ($iv['paid_date']) $paid += (float)$iv['amount'];
    else {
        $unpaid += (float)$iv['amount'];
        if ($iv['due_date'] && $iv['due_date'] < $todayStr) $overdue++;
    }
}
$margin = ($budgetAmount !== null && $proj['total'] > 0) ? $budgetAmount - $proj['total'] : null;

$pageTitle = 'Finance · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="finance.php">← All finance</a> ·
   <a href="project.php?id=<?= $projectId ?>">Open the project</a></p>
<h2 class="wizard-title">
  <?= e($project['name']) ?> <span class="muted">(<?= e($project['code']) ?>)</span>
  <span class="admin-only-tag">administrators only</span>
</h2>

<?php if ($dbError !== null): ?>
  <div class="flash flash-err"><?= e($dbError) ?></div>
<?php elseif (!$configured): ?>
  <div class="empty-state">
    <p><strong>The finance database isn't set up yet.</strong></p>
    <p class="muted">Import <code>finance_install.sql</code> and
       <code>finance_install_02.sql</code>, then add the <code>finance_db</code> block to
       <code>config.php</code>.</p>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<div class="dash-strip">
  <div><span class="muted small">Budget</span>
    <strong><?= $budgetAmount === null ? '—' : e(fmt_money_plain($budgetAmount, $cur)) ?></strong></div>
  <div><span class="muted small">Projected cost</span>
    <strong><?= $proj['total'] > 0 ? e(fmt_money_plain($proj['total'], $proj['currency'])) : '—' ?></strong>
    <?php if ($proj['unpriced_days'] > 0): ?>
      <span class="warn-text small"><?= e(fmt_num($proj['unpriced_days'], 1)) ?> days unpriced</span>
    <?php endif; ?></div>
  <div><span class="muted small">Margin</span>
    <strong class="<?= $margin !== null ? ($margin < 0 ? 'err' : 'ok') : '' ?>">
      <?= $margin === null ? '—' : e(fmt_money_plain($margin, $cur)) ?></strong></div>
  <div><span class="muted small">Invoiced</span><strong><?= e(fmt_money_plain($billed, $cur)) ?></strong>
    <span class="muted small"><?= count($invoices) ?> invoice(s)</span></div>
  <div><span class="muted small">Unpaid</span>
    <strong class="<?= $overdue ? 'err' : '' ?>"><?= e(fmt_money_plain($unpaid, $cur)) ?></strong>
    <?php if ($overdue): ?><span class="err small"><?= $overdue ?> overdue</span><?php endif; ?></div>
</div>

<?php if ($proj['mixed_currency']): ?>
  <div class="flash flash-warn">
    People on this show are paid in more than one currency, and these totals simply add the
    numbers together. <strong>Treat the projected cost as indicative until that is resolved</strong> —
    nothing here converts between currencies, and inventing an exchange rate would be worse than
    saying so.
  </div>
<?php endif; ?>

<h3>Budget</h3>
<form method="post" class="stack">
  <input type="hidden" name="save_budget" value="1">
  <div class="row">
    <label>Agreed amount
      <input name="budget_amount" inputmode="decimal" placeholder="e.g. 85000"
             value="<?= e($budgetAmount !== null ? fmt_num($budgetAmount, 2) : '') ?>">
    </label>
    <label>Currency
      <input name="currency" maxlength="3" style="text-transform:uppercase" value="<?= e($cur) ?>">
    </label>
    <label>Terms
      <input name="terms" placeholder="e.g. fixed fee, 50% on start" value="<?= e($budget['terms'] ?? '') ?>">
    </label>
  </div>
  <label>Notes
    <textarea name="notes" rows="2"><?= e($budget['notes'] ?? '') ?></textarea>
  </label>
  <div class="actions"><button type="submit">Save budget</button></div>
</form>
<?php if ($budget && !empty($budget['updated_at'])): ?>
  <p class="muted small">Last changed <?= e(substr((string)$budget['updated_at'], 0, 16)) ?><?php
    if (!empty($budget['updated_by'])): ?> by <?= e($budget['updated_by']) ?><?php endif; ?>.</p>
<?php endif; ?>

<h3>Expense projection</h3>
<p class="muted small">
  Laid out like <code>RTC_ExpenseProjection_v01.xlsx</code>: <strong>one row per person</strong>,
  grouped by department with subtotals. Two artists on a step are two rows, because they are two
  people who each get paid. Person-days come from the same <code>step_quota()</code> the dashboard
  and the CSV export use; the rate comes from each person's page.
  <strong>Computed every time this page loads</strong>, never stored, so it cannot disagree with the plan.
</p>

<?php if (!$proj['rows']): ?>
  <p class="muted">No pipeline steps yet.</p>
<?php else: ?>
  <div class="grid-scroll">
  <table class="proj-table">
    <thead>
      <tr>
        <th>Role / Position</th><th>Artist</th><th>Rate</th>
        <th class="num">Days</th><th class="num">Months</th><th class="num">Projected</th>
        <th class="num">Units</th><th class="num">Actual days</th><th class="num">Actual</th>
        <th class="num">Difference</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($proj['by_department'] as $dept => $group): ?>
      <tr class="track-group"><td colspan="10">
        <span class="group-badge"><?= e(strtoupper($dept)) ?></span>
        <span class="muted small"><?= count($group['rows']) ?> people ·
          <?= e(fmt_num($group['days'], 1)) ?> days</span>
      </td></tr>

      <?php foreach ($group['rows'] as $r): ?>
        <tr>
          <td class="wf-name">
            <span class="dot" style="background:<?= e($r['step']['color'] ?: '#ccc') ?>"></span><?= e($r['role']) ?>
          </td>
          <td>
            <?php if ($r['crew_id']): ?>
              <a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$r['crew_id'] ?>"><?= e($r['artist']) ?></a>
              <?php if ($r['is_slot']): ?><span class="slot-tag">slot</span><?php endif; ?>
            <?php else: ?>
              <span class="warn-text small">nobody assigned</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($r['rate'] === null): ?>
              <?php if ($r['crew_id']): ?>
                <a class="warn-text" href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$r['crew_id'] ?>"
                   title="Set a day rate on their page and it feeds straight back into this total">set a rate →</a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            <?php else: ?>
              <?= e(number_format($r['rate'], 2) . ' ' . $r['currency']) ?>
              <span class="muted">/ <?= e($r['rate_unit']) ?></span>
            <?php endif; ?>
          </td>
          <td class="num"><?= e(fmt_num($r['days'], 2)) ?></td>
          <td class="num muted"><?= e(fmt_num($r['months'], 2)) ?></td>
          <td class="num"><?= $r['cost'] === null ? '<span class="muted">—</span>' : e(number_format($r['cost'], 2)) ?></td>
          <td class="num muted"><?= e(fmt_num($r['units'], 0)) ?></td>
          <td class="num muted"><?= $r['actual_days'] > 0 ? e(fmt_num($r['actual_days'], 2)) : '—' ?></td>
          <td class="num"><?= $r['actual_cost'] === null || $r['actual_days'] <= 0
                ? '<span class="muted">—</span>' : e(number_format($r['actual_cost'], 2)) ?></td>
          <td class="num <?= $r['variance'] !== null && $r['actual_days'] > 0
                ? ($r['variance'] < 0 ? 'err' : 'ok') : '' ?>">
            <?= $r['variance'] === null || $r['actual_days'] <= 0
                ? '<span class="muted">—</span>' : e(number_format($r['variance'], 2)) ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <tr class="subtotal">
        <td colspan="5"><?= e($dept) ?> subtotal</td>
        <td class="num"><strong><?= e(number_format($group['cost'], 2)) ?></strong></td>
        <td></td><td></td>
        <td class="num"><?= $group['actual'] > 0 ? e(number_format($group['actual'], 2)) : '—' ?></td>
        <td class="num"><?= $group['actual'] > 0 ? e(number_format($group['cost'] - $group['actual'], 2)) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5">Total projected</th>
        <th class="num"><?= e(fmt_money_plain($proj['total'], $proj['currency'])) ?></th>
        <th></th><th></th>
        <th class="num"><?= $proj['actual_total'] > 0 ? e(number_format($proj['actual_total'], 2)) : '—' ?></th>
        <th class="num"><?= $proj['actual_total'] > 0
              ? e(number_format($proj['total'] - $proj['actual_total'], 2)) : '—' ?></th>
      </tr>
    </tfoot>
  </table>
  </div>

  <p class="muted small">
    <strong>Months</strong> is days ÷ 22, the divisor your own sheet uses.
    <strong>Actual</strong> comes from time logged against that person on that step — a dash means
    nobody logged any, which is not the same as it having taken no time.
    <strong>Difference</strong> is projected minus actual: green means it cost less than planned.
    A day is <?= e(fmt_num($proj['hours_per_day'], 1)) ?> hours on this project.
  </p>

  <?php if ($proj['unpriced_days'] > 0): ?>
    <p class="flash flash-warn">
      <strong><?= e(fmt_num($proj['unpriced_days'], 1)) ?> person-days have no rate behind them</strong> —
      mostly lanes still held by unfilled slots. Those days are <em>excluded</em> from the total
      rather than counted as free, so the real cost is higher than it says. Click any
      <em>set a rate</em> above to fix one; the total updates as soon as you save.
    </p>
  <?php endif; ?>
<?php endif; ?>

<h3>Invoices <span class="muted">· <?= count($invoices) ?></span></h3>

<?php if ($invoices): ?>
  <table>
    <thead>
      <tr><th>Who</th><th>Ref</th><th>Period</th><th class="num">Amount</th>
          <th>Issued</th><th>Due</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $iv):
      $late = !$iv['paid_date'] && $iv['due_date'] && $iv['due_date'] < $todayStr; ?>
      <tr class="<?= $late ? 'row-overdue' : '' ?>">
        <td><?= e($iv['crew_name'] ?: '—') ?></td>
        <td class="muted small">
          <?php if (!empty($iv['document_url'])): ?>
            <a href="<?= e($iv['document_url']) ?>" target="_blank" rel="noopener"><?= e($iv['invoice_ref'] ?: 'document') ?></a>
          <?php else: ?><?= e($iv['invoice_ref'] ?: '—') ?><?php endif; ?>
        </td>
        <td class="muted small"><?= e(($iv['period_start'] ?: '—') . ' → ' . ($iv['period_end'] ?: '—')) ?></td>
        <td class="num"><?= e(fmt_money_plain((float)$iv['amount'], $iv['currency'])) ?></td>
        <td class="muted small"><?= e($iv['issued_date'] ?: '—') ?></td>
        <td class="muted small <?= $late ? 'err' : '' ?>"><?= e($iv['due_date'] ?: '—') ?></td>
        <td><?= $iv['paid_date']
              ? '<span class="ok">paid ' . e($iv['paid_date']) . '</span>'
              : '<span class="' . ($iv['status'] === 'disputed' ? 'err' : 'warn-text') . '">' . e($iv['status']) . '</span>' ?></td>
        <td class="row-actions">
          <form method="post" onsubmit="return confirm('Remove this invoice?');">
            <button type="submit" name="delete_invoice" value="<?= (int)$iv['id'] ?>" class="link-danger">remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p class="muted">Nothing recorded yet.</p>
<?php endif; ?>

<h3>Record an invoice</h3>
<form method="post" class="stack">
  <input type="hidden" name="save_invoice" value="1">
  <div class="row">
    <label>Who
      <select name="crew_id" onchange="document.getElementById('invName').value = this.options[this.selectedIndex].dataset.name || '';">
        <option value="" data-name="">— not a crew member —</option>
        <?php foreach ($people as $pp): ?>
          <option value="<?= (int)$pp['id'] ?>" data-name="<?= e($pp['name']) ?>"><?= e($pp['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Name on the invoice
      <input name="crew_name" id="invName" placeholder="Copied from the picker, or type one">
    </label>
    <label>Their reference
      <input name="invoice_ref" placeholder="e.g. 2026-014">
    </label>
  </div>
  <div class="row">
    <label>Amount <input name="amount" inputmode="decimal" required placeholder="e.g. 2400"></label>
    <label>Currency <input name="currency" maxlength="3" value="<?= e($cur) ?>" style="text-transform:uppercase"></label>
    <label>Status
      <select name="status">
        <option value="received">received</option>
        <option value="approved">approved</option>
        <option value="paid">paid</option>
        <option value="disputed">disputed</option>
      </select>
    </label>
  </div>
  <div class="row">
    <label>Period from <input type="date" name="period_start"></label>
    <label>Period to <input type="date" name="period_end"></label>
    <label>Issued <input type="date" name="issued_date" value="<?= $todayStr ?>"></label>
    <label>Due <input type="date" name="due_date"></label>
    <label>Paid on <input type="date" name="paid_date"></label>
  </div>
  <label>Document link <span class="muted small">(Google Drive)</span>
    <input type="url" name="document_url" placeholder="https://drive.google.com/…">
  </label>
  <label>Notes <textarea name="notes" rows="2"></textarea></label>
  <div class="actions"><button type="submit">Record invoice</button></div>
</form>

<p class="muted small">
  The invoice document stays in Drive under Drive's own permissions; only the link is stored.
  <strong>The reference is theirs, not ours</strong> — nothing is generated here, because a second
  number guarantees the two disagree the moment somebody quotes one over email.
  Marking an invoice paid without a date fills in today: a status that cannot be checked later
  is not a record.
</p>

</div>
</body></html>
