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
        } elseif (isset($_POST['save_invoices'])) {
            // The picker sends "crewId:stepId"; the map turns that back into the
            // four fields an invoice stores. Rebuilt here rather than trusted
            // from the browser — see save_invoices_bulk().
            $posMap = [];
            foreach (project_lanes($pdo, $projectId) as $l) {
                $posMap[(int)$l['crew_id'] . ':' . (int)$l['step_id']] = [
                    'crew_id'   => (int)$l['crew_id'],
                    'step_id'   => (int)$l['step_id'],
                    'crew_name' => (string)$l['name'],
                    'step_name' => (string)$l['step_name'],
                ];
            }
            $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
            $r = save_invoices_bulk($rows, $project, $posMap, $who);
            if ($r['added']) {
                flash('ok', sprintf('Recorded %d invoice%s.', $r['added'], $r['added'] === 1 ? '' : 's'));
            }
            foreach ($r['errors'] as $e) flash('err', $e);
            if (!$r['added'] && !$r['errors']) {
                flash('warn', 'No row had an amount in it, so nothing was recorded.');
            }

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

// The positions an invoice can be attached to: every lane on the show, which is
// exactly the set of rows the projection draws. Built from the same
// project_lanes() the projection uses, so the picker and the table can never
// offer different things.
$positions = [];
foreach (project_lanes($pdo, $projectId) as $l) {
    $positions[] = [
        'crew_id'   => (int)$l['crew_id'],
        'step_id'   => (int)$l['step_id'],
        'crew_name' => (string)$l['name'],
        'step_name' => (string)$l['step_name'],
        'label'     => $l['name'] . ' — ' . $l['step_name'],
    ];
}
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

// Invoices naming no position still count against the budget, but cannot appear
// on a projection row. Reported rather than quietly dropped.
$unattached = 0.0;
foreach ($invoices as $iv) {
    if (empty($iv['step_id']) || empty($iv['crew_id'])) $unattached += (float)$iv['amount'];
}

$pageTitle = 'Finance · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="finance.php">← All finance</a> ·
   <a href="project.php?id=<?= $projectId ?>">Open the project</a></p>
<h2 class="wizard-title">
  <?= e($project['name']) ?> <span class="muted">(<?= e($project['code']) ?>)</span>
  <span class="admin-only-tag">administrators only</span>
</h2>

<?php if (function_exists('invoices_need_upgrade') && invoices_need_upgrade()): ?>
  <div class="flash flash-warn">
    <strong>The finance database needs <code>finance_install_03.sql</code>.</strong>
    Until it is imported, an invoice cannot say which position it pays for, so actual costs
    cannot appear on the expense projection. Everything else works. Import it into the finance
    database via phpMyAdmin.
  </div>
<?php endif; ?>

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
<form method="post" class="money-form">
  <input type="hidden" name="save_budget" value="1">
  <div class="row">
    <label>Agreed amount
      <input name="budget_amount" inputmode="decimal" placeholder="e.g. 85000"
             value="<?= e($budgetAmount !== null ? fmt_num($budgetAmount, 2) : '') ?>">
    </label>
    <label style="flex:0 1 90px;">Currency
      <input name="currency" maxlength="3" style="text-transform:uppercase" value="<?= e($cur) ?>">
    </label>
    <label>Terms
      <input name="terms" placeholder="e.g. fixed fee, 50% on start" value="<?= e($budget['terms'] ?? '') ?>">
    </label>
    <?php // Still a textarea, not an input: notes here can run to a paragraph
          // about how a deal is structured, and switching to a single-line field
          // would flatten any newlines already saved the next time somebody
          // pressed Save. It is sized to match the inputs and drags taller. ?>
    <label style="flex:2 1 220px;">Notes
      <textarea name="notes" rows="1"><?= e($budget['notes'] ?? '') ?></textarea>
    </label>
    <div class="row-save"><button type="submit">Save budget</button></div>
  </div>
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
              <a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$r['crew_id'] ?>&amp;from=finance"><?= e($r['artist']) ?></a>
              <?php if ($r['is_slot']): ?><span class="slot-tag">slot</span><?php endif; ?>
            <?php else: ?>
              <span class="warn-text small">nobody assigned</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($r['rate'] === null): ?>
              <?php if ($r['crew_id']): ?>
                <a class="warn-text" href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$r['crew_id'] ?>&amp;from=finance"
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
    <strong>Actual days</strong> comes from time logged against that person on that step.
    <strong>Actual cost comes from the invoices</strong> attached to that position — not from days
    times a rate, which would be a second estimate rather than a fact. A dash in either means
    nothing was recorded, which is not the same as it having been free.
    <strong>Difference</strong> is projected minus actual: green means it cost less than planned.
    <?php if ($unattached > 0): ?>
      The actual total here counts only invoices attached to a position, so it is
      <?= e(fmt_money_plain($unattached, $cur)) ?> lower than the <em>Invoiced</em> figure at the
      top of the page. That gap is the unattached invoices, and it is a real difference rather
      than a rounding error — the money is spent either way, it just belongs to no row.
    <?php endif; ?>
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

<?php if ($unattached > 0): ?>
  <p class="flash flash-warn">
    <strong><?= e(fmt_money_plain($unattached, $cur)) ?> of invoices name no position</strong> —
    a kill fee, a one-off, or simply nobody picked one. That money counts against the budget but
    cannot appear on a row above, because spreading it across rows would put a figure into a
    subtotal that nobody agreed to. Edit those invoices to attach them, or leave them as they are.
  </p>
<?php endif; ?>

<h3>Invoices <span class="muted">· <?= count($invoices) ?></span></h3>

<?php if ($invoices): ?>
  <table>
    <thead>
      <tr><th>Who</th><th>Position</th><th>Ref</th><th>Period</th><th class="num">Amount</th>
          <th>Issued</th><th>Due</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $iv):
      $late = !$iv['paid_date'] && $iv['due_date'] && $iv['due_date'] < $todayStr; ?>
      <tr class="<?= $late ? 'row-overdue' : '' ?>">
        <td><?= e($iv['crew_name'] ?: '—') ?></td>
        <td class="muted small"><?= e($iv['step_name'] ?: '') ?: '<span class="warn-text">unattached</span>' ?></td>
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

<h3>Record invoices</h3>

<p class="muted small">
  One row per invoice, five at a time — a month's invoices arrive as a batch, and one form submit
  each was six page loads to do one afternoon's bookkeeping. Only the <strong>amount</strong> is
  required; blank rows are ignored. A row with details but no amount is <em>not</em> ignored — it
  is reported, because that is somebody who was interrupted mid-typing rather than a row nobody
  wanted.
</p>

<form method="post">
  <input type="hidden" name="save_invoices" value="1">
  <div class="grid-scroll">
    <table class="grid-table" id="invoiceGrid">
      <thead>
        <tr>
          <th>Position <span class="muted small">(what it pays for)</span></th>
          <th>Name on the invoice</th>
          <th>Their reference</th>
          <th>Amount</th>
          <th>Currency</th>
          <th>Status</th>
          <th>Period from</th>
          <th>Period to</th>
          <th>Issued</th>
          <th>Due</th>
          <th>Paid on</th>
          <th>Document link</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($r = 0; $r < 5; $r++): $n = 'rows[' . $r . ']'; ?>
          <tr>
            <td>
              <select name="<?= $n ?>[position]" onchange="invPosition(this)">
                <option value="">— no particular position —</option>
                <?php foreach ($positions as $ps): ?>
                  <option value="<?= (int)$ps['crew_id'] ?>:<?= (int)$ps['step_id'] ?>"
                          data-crewname="<?= e($ps['crew_name']) ?>"><?= e($ps['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input name="<?= $n ?>[crew_name]" size="16" placeholder="Filled in by the picker"></td>
            <td><input name="<?= $n ?>[invoice_ref]" size="10" placeholder="2026-014"></td>
            <td><input name="<?= $n ?>[amount]" inputmode="decimal" size="8" placeholder="2400"></td>
            <td><input name="<?= $n ?>[currency]" maxlength="3" size="3"
                       style="text-transform:uppercase" value="<?= e($cur) ?>"></td>
            <td>
              <select name="<?= $n ?>[status]">
                <option value="received">received</option>
                <option value="approved">approved</option>
                <option value="paid">paid</option>
                <option value="disputed">disputed</option>
              </select>
            </td>
            <td><input type="date" name="<?= $n ?>[period_start]"></td>
            <td><input type="date" name="<?= $n ?>[period_end]"></td>
            <td><input type="date" name="<?= $n ?>[issued_date]" value="<?= $todayStr ?>"></td>
            <td><input type="date" name="<?= $n ?>[due_date]"></td>
            <td><input type="date" name="<?= $n ?>[paid_date]"></td>
            <td><input type="url" name="<?= $n ?>[document_url]" size="16" placeholder="https://drive.google.com/…"></td>
            <td><input name="<?= $n ?>[notes]" size="18"></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <div class="actions">
    <button type="submit">Record invoices</button>
    <button type="button" class="secondary" onclick="invAddRows()">Add 5 more rows</button>
  </div>
  <p class="muted small">
    Choosing a <strong>position</strong> puts that invoice on that row of the projection above —
    crew and step together are the lane, which is why "who" alone cannot say what a payment was
    for. Leaving it blank is a real answer: a kill fee or a one-off counts in the project total and
    is listed as unattached, rather than being forced onto a row nobody agreed to.
    Marking one <strong>paid</strong> without a date fills in today.
  </p>
</form>

<script>
// Fill the visible name from the position picker, within THIS row only.
//
// The old single form did this with document.getElementById, which addresses
// exactly one field on the page — with five rows it would have written every
// pick into the first row's name box. The id that actually matters is not sent
// from here at all: the server re-resolves the position itself, so a name and
// an id can never disagree about which lane an invoice belongs to.
function invPosition(sel) {
  var row = sel.closest('tr');
  if (!row) { return; }
  var name = row.querySelector('[name$="[crew_name]"]');
  var picked = sel.options[sel.selectedIndex];
  if (name && picked && (name.value === '' || name.dataset.auto === '1')) {
    name.value = picked.dataset.crewname || '';
    name.dataset.auto = '1';
  }
}

function invAddRows() {
  var body = document.querySelector('#invoiceGrid tbody');
  if (!body) { return; }
  for (var k = 0; k < 5; k++) {
    var n = body.rows.length;
    var copy = body.rows[n - 1].cloneNode(true);
    var fields = copy.querySelectorAll('input, select');
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      f.name = f.name.replace(/rows\[\d+\]/, 'rows[' + n + ']');
      // Currency and the issued date are defaults worth keeping on a new row;
      // everything else starts empty.
      var keep = /\[(currency|issued_date)\]$/.test(f.name);
      if (f.tagName === 'SELECT') { f.selectedIndex = 0; }
      else if (!keep) { f.value = ''; delete f.dataset.auto; }
    }
    body.appendChild(copy);
  }
}
</script>

<p class="muted small">
  The invoice document stays in Drive under Drive's own permissions; only the link is stored.
  <strong>The reference is theirs, not ours</strong> — nothing is generated here, because a second
  number guarantees the two disagree the moment somebody quotes one over email.
  Marking an invoice paid without a date fills in today: a status that cannot be checked later
  is not a record.
</p>

</div>
</body></html>
