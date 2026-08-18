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
require_once __DIR__ . '/includes/finance_nav.php';
require_once __DIR__ . '/includes/standards.php';
require_once __DIR__ . '/includes/dashboard.php';
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
        } elseif (isset($_POST['save_departments'])) {
            // Writes pipeline_steps.department on the ORDINARY production
            // connection. It is a production fact, not a financial one — see
            // save_step_departments().
            $wanted = is_array($_POST['department'] ?? null) ? $_POST['department'] : [];
            $r = save_step_departments($pdo, $projectId, $wanted);
            if ($r['changed']) {
                flash('ok', sprintf('Moved %d step%s: %s. Every figure below regroups immediately, '
                                  . 'and the answer is remembered for future projects.',
                      $r['changed'], $r['changed'] === 1 ? '' : 's', implode('; ', $r['moved'])));
            } else {
                flash('warn', 'Nothing changed — every step is already in the phase shown.');
            }

        } elseif (isset($_POST['save_expenses'])) {
            $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
            $r = save_expenses_bulk($rows, $project, $who);
            if ($r['added']) {
                flash('ok', sprintf('Recorded %d expense%s.', $r['added'], $r['added'] === 1 ? '' : 's'));
            }
            foreach ($r['errors'] as $e) flash('err', $e);
            if (!$r['added'] && !$r['errors']) {
                flash('warn', 'No row had anything in it, so nothing was recorded.');
            }

        } elseif (isset($_POST['delete_expense'])) {
            flash('ok', delete_expense((int)$_POST['delete_expense']));

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
// Budget minus what the show ACTUALLY COSTS — labour plus everything else.
//
// This used to be budget minus $proj['total'], which is labour alone, and it was
// read as headroom because that is what a figure called Margin means. On Return
// to Club it showed 1,720.65 against a 35,000 budget while nothing non-labour
// could be recorded at all. (2026-08-17.)
$grandTotal = $proj['grand_total'] ?? $proj['total'];
$margin = ($budgetAmount !== null && $grandTotal > 0) ? $budgetAmount - $grandTotal : null;

// Invoices naming no position still count against the budget, but cannot appear
// on a projection row. Reported rather than quietly dropped.
$unattached = 0.0;
foreach ($invoices as $iv) {
    if (empty($iv['step_id']) || empty($iv['crew_id'])) $unattached += (float)$iv['amount'];
}

// Which tab. One file rather than four, because every tab needs the same
// expense_projection() and the same POST handlers, and four files would each
// have to rebuild that context — three more places for the money to come out
// slightly differently. The tabs choose what RENDERS, never what is computed.
$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab, finance_tabs(), true)) $tab = 'overview';

$pageTitle = 'Finance · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php finance_nav($project, $tab); ?>

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

<?php // ---- OVERVIEW ------------------------------------------------------ ?>
<?php if ($tab === 'overview'): ?>

<?php
// How the SHOW is going, on the money screen. The same figures the production
// dashboard draws, out of the same dashboard_overall() — not a second count of
// anything. A budget read without knowing whether the work is half done is a
// budget read blind, and walking to another tab to find out is how nobody
// checks.
$progress = null; $dashSteps = [];
try {
    $dashSteps = dashboard_steps($pdo, $projectId, $project['delivery_date'] ?? null);
    $progress  = dashboard_overall($dashSteps, $project['delivery_date'] ?? null, null);
} catch (Throwable $e) {
    // A progress panel that cannot be worked out must not take the budget down
    // with it — the same rule the Finance hub learned when one project's
    // projection stopped the whole table drawing.
    $progress = null;
}
?>

<?php if ($progress && $progress['applicable'] > 0): ?>
  <h3>Where the show is</h3>
  <div class="dash-strip">
    <div><span class="muted small">Approved</span>
      <strong><?= e(fmt_num($progress['approved'], 0)) ?></strong>
      <span class="muted small">of <?= e(fmt_num($progress['applicable'], 0)) ?></span></div>
    <div><span class="muted small">Delivered</span>
      <strong><?= e(fmt_num($progress['delivered'], 0)) ?></strong>
      <span class="muted small"><?= $progress['pct_delivered'] !== null
            ? e(fmt_num($progress['pct_delivered'], 0)) . '%' : '' ?></span></div>
    <div><span class="muted small">Remaining</span>
      <strong><?= e(fmt_num($progress['remaining'], 0)) ?></strong></div>
    <div><span class="muted small">Blocked</span>
      <strong class="<?= $progress['blocked'] ? 'warn-text' : '' ?>"><?= e(fmt_num($progress['blocked'], 0)) ?></strong></div>
    <div><span class="muted small">Working days left</span>
      <strong class="<?= !empty($progress['overdue']) ? 'err' : '' ?>">
        <?= $progress['days_left'] !== null ? (int)$progress['days_left'] : '—' ?></strong>
      <?php if (!empty($progress['overdue'])): ?><span class="err small">past delivery</span><?php endif; ?></div>
    <div><span class="muted small">Delivery</span>
      <strong><?= e($project['delivery_date'] ?: '—') ?></strong></div>
  </div>
  <p class="muted small">
    The same counts the <a href="dashboard.php?project=<?= $projectId ?>">production dashboard</a>
    draws, from the same calculation — so the two screens cannot disagree about how far along the
    show is.
  </p>
<?php endif; ?>

<h3>The money</h3>
<div class="dash-strip">
  <div><span class="muted small">Budget</span>
    <strong><?= $budgetAmount === null ? '—' : e(fmt_money_plain($budgetAmount, $cur)) ?></strong></div>
  <div><span class="muted small">Projected cost</span>
    <strong><?= $grandTotal > 0 ? e(fmt_money_plain($grandTotal, $proj['currency'])) : '—' ?></strong>
    <?php if (!empty($proj['other_total'])): ?>
      <span class="muted small">
        <?= e(number_format($proj['total'], 2)) ?> people
        + <?= e(number_format($proj['other_total'], 2)) ?> other
      </span>
    <?php endif; ?>
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

<?php
// The phase breakdown. Deliberately built to ADD UP to Projected cost above:
// every labour department, plus the non-labour total, equals the grand total.
// A breakdown whose parts do not reconcile with the headline is worse than no
// breakdown, because somebody will trust one of the two and there is no way to
// tell from the screen which.
$phaseOrder = ['Above the line' => 1, 'Pre-production' => 2, 'Production' => 3, 'Post-production' => 4];
$phases = $proj['by_department'];
uksort($phases, function ($a, $b) use ($phaseOrder) {
    $oa = $phaseOrder[$a] ?? 9; $ob = $phaseOrder[$b] ?? 9;
    return $oa !== $ob ? $oa <=> $ob : strcasecmp($a, $b);
});
$hasDeptCol = column_exists($pdo, 'pipeline_steps', 'department');
?>

<?php if ($phases || !empty($proj['other_total'])): ?>
  <h3>Cost by phase</h3>
  <div class="dash-strip">
    <?php foreach ($phases as $dept => $group):
      $pct = $grandTotal > 0 ? ($group['cost'] / $grandTotal) * 100 : 0; ?>
      <div>
        <span class="muted small"><?= e($dept) ?></span>
        <strong><?= e(number_format($group['cost'], 2)) ?></strong>
        <span class="muted small">
          <?= e(fmt_num($pct, 0)) ?>% · <?= e(fmt_num($group['days'], 1)) ?> days
          · <?= count($group['rows']) ?> <?= count($group['rows']) === 1 ? 'person' : 'people' ?>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if (!empty($proj['other_total'])):
      $pct = $grandTotal > 0 ? ($proj['other_total'] / $grandTotal) * 100 : 0; ?>
      <div>
        <span class="muted small">Other expenses</span>
        <strong><?= e(number_format($proj['other_total'], 2)) ?></strong>
        <span class="muted small">
          <?= e(fmt_num($pct, 0)) ?>% · not somebody's time
        </span>
      </div>
    <?php endif; ?>
    <div>
      <span class="muted small">Total</span>
      <strong><?= e(fmt_money_plain($grandTotal, $proj['currency'])) ?></strong>
      <span class="muted small">matches Projected cost above</span>
    </div>
  </div>

  <?php if (isset($phases['Unassigned'])): ?>
    <p class="muted small warn-text">
      <strong><?= e(number_format($phases['Unassigned']['cost'], 2)) ?></strong> sits under
      <em>Unassigned</em> because those steps have no phase set. Put them in one below and the
      subtotals stop lying by omission.
    </p>
  <?php endif; ?>

  <?php if (can_edit() && $hasDeptCol): ?>
    <details class="inline-actions">
      <summary>Which phase each step belongs to</summary>
      <p class="muted small">
        A step's phase is what groups it here, on the expense projection, and in the crew list —
        so moving <em>Comp</em> to Post-production regroups every one of them at once. It is a
        <strong>production</strong> fact rather than a financial one, and it is stored with the
        step, not with the money.
        <br>
        The answer is also remembered against the step's <strong>name</strong>, so every future
        project starts with it filled in rather than asking again. Free text: type a phase that
        isn't listed and it becomes one.
      </p>
      <form method="post">
        <input type="hidden" name="save_departments" value="1">
        <table class="review-table">
          <thead><tr><th>Step</th><th>Covers</th><th>Phase</th></tr></thead>
          <tbody>
          <?php foreach (pipeline_steps($pdo, $projectId) as $st): ?>
            <tr>
              <td class="wf-name">
                <span class="dot" style="background:<?= e($st['color'] ?: '#ccc') ?>"></span><?= e($st['name']) ?>
              </td>
              <td class="muted small">
                <?= e(applies_to_phrase($st['applies_to'], $st['applies_to_asset_type'] ?? null)) ?>
              </td>
              <td>
                <input name="department[<?= (int)$st['id'] ?>]" list="phaseOptions"
                       value="<?= e($st['department'] ?? '') ?>" placeholder="no phase set" size="20">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <datalist id="phaseOptions">
          <?php foreach (department_options($pdo) as $d): ?><option value="<?= e($d) ?>"></option><?php endforeach; ?>
        </datalist>
        <div class="actions"><button type="submit">Save phases</button></div>
      </form>
    </details>
  <?php elseif (!$hasDeptCol): ?>
    <p class="muted small warn-text">
      Phases need migration <strong>015</strong>, which hasn't been applied — so everything groups
      as <em>Unassigned</em>.
    </p>
  <?php endif; ?>
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

<?php endif; ?>
<?php // ---- end OVERVIEW -------------------------------------------------- ?>

<?php // ---- EXPENSE PROJECTION -------------------------------------------- ?>
<?php if ($tab === 'projection'): ?>

<h3>Expense projection</h3>

<p class="page-actions">
  <a class="button" href="export_projection.php?project=<?= $projectId ?>">Download as Excel ↓</a>
  <span class="muted small">
    Built live from the numbers below, in the fifteen-column shape of
    <code>RTC_ExpenseProjection_v01.xlsx</code> — department bands, subtotals, grand total and the
    summary block. Because it comes out of the same calculation this page draws, the file and the
    screen cannot disagree.
  </span>
</p>

<?php
// The retake allowance is the single reason a generated projection reads lower
// than a sheet built by hand: the reference sheet applies "+10% RTK" to every
// day-rate line and this project has no allowance set, so nothing is uplifted.
// Said here rather than left to be discovered by comparing two files column by
// column — which is how it was found.
$retakePct = project_retake_pct($pdo, $projectId);
if ($retakePct <= 0):
?>
  <div class="flash flash-warn">
    <strong>No retake allowance is set on this project</strong>, so every figure below is the
    work as estimated with nothing added for revisions. A hand-built sheet carrying
    <em>+10% RTK</em> will read about 10% higher on every day-rate line, and that difference is
    this setting rather than a disagreement about the maths.
    <a href="wizard.php?project=<?= $projectId ?>&amp;step=1">Set it on the Basics screen →</a>
  </div>
<?php else: ?>
  <p class="muted small">
    A retake allowance of <strong>+<?= e(fmt_num($retakePct, 0)) ?>%</strong> is applied to days
    per item, so these figures already include revision time.
  </p>
<?php endif; ?>
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
        <th colspan="5">Total projected — people</th>
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

<?php
// ---- MINIMUM vs IDEAL -------------------------------------------------------
//
// The question this answers, and nothing else in the platform could: IS THIS
// BUDGET BUYING A PLAN THAT CAN BE DELIVERED?
//
// The two numbers come from opposite directions and neither is an opinion about
// the other:
//
//   MINIMUM  what the deadline demands of one person per day. Derived from work
//            remaining over working days left over headcount. Nobody types it
//            and nobody can edit it — it is arithmetic on the dates and the
//            progress, and it rises on its own every time either slips.
//   IDEAL    what the studio says that work actually takes, for this kind of
//            project. Typed on the base sheet from experience.
//
// A minimum ABOVE the ideal is the finding. It does not mean somebody is lazy;
// it means the plan requires a pace the studio does not believe in, and the only
// honest fixes are more people, more time, or less work. Reading it beside the
// budget is the point — those three fixes all cost money, which is the screen
// you are on.
$quotaRows = [];
$quotaOver = 0;
if (standards_available($pdo)) {
    $sheet = standards_for_type($pdo, (string)$project['type']);
    if ($sheet) {
        try {
            foreach (dashboard_steps($pdo, $projectId, $project['delivery_date'] ?? null) as $ds) {
                $st  = $ds['step'];
                $std = $sheet[standard_key($st['name'], $st['applies_to'] ?? 'shot',
                                           $st['applies_to_asset_type'] ?? '')] ?? null;
                if ($std === null) continue;                 // no standard to compare against

                $idealDays    = (float)$std['days_per_unit'];
                $idealPerDay  = $idealDays > 0 ? 1 / $idealDays : null;
                $minPerArtist = $ds['per_artist'] !== null ? (float)$ds['per_artist'] : null;

                $over = ($minPerArtist !== null && $idealPerDay !== null && $idealPerDay > 0)
                      ? $minPerArtist / $idealPerDay : null;
                if ($over !== null && $over > 1.05) $quotaOver++;

                $quotaRows[] = [
                    'ds' => $ds, 'step' => $st, 'std' => $std,
                    'ideal_per_day' => $idealPerDay,
                    'min_per_artist' => $minPerArtist,
                    'over' => $over,
                ];
            }
        } catch (Throwable $e) {
            $quotaRows = [];
        }
    }
}
?>

<h3>Minimum against ideal</h3>

<p class="muted small">
  Two numbers from opposite directions. <strong>Minimum</strong> is what the deadline demands of
  one person per day — remaining work over the working days left, divided by the people on it. It
  is arithmetic, <strong>not editable</strong>, and it climbs on its own every time a date gets
  closer or work slips. <strong>Ideal</strong> is what the studio says that work actually takes,
  from the <a href="standards.php?type=<?= urlencode((string)$project['type']) ?>">base sheet</a>
  for <?= e($project['type']) ?> projects.
</p>

<?php if (!standards_available($pdo)): ?>
  <p class="muted small warn-text">
    Ideal quotas need migration <strong>026</strong>, which hasn't been applied, so there is
    nothing to compare against yet.
  </p>
<?php elseif (!$quotaRows): ?>
  <div class="empty-state">
    <p>No step on this project has an ideal recorded for <?= e($project['type']) ?> projects yet.</p>
    <p class="muted">Write them down on the
      <a href="standards.php?type=<?= urlencode((string)$project['type']) ?>">base sheet</a> and
      this comparison fills in. It is the one thing that can tell you a budget is buying a plan
      nobody can deliver.</p>
  </div>
<?php else: ?>

  <?php if ($quotaOver > 0): ?>
    <div class="flash flash-err">
      <strong><?= $quotaOver ?> step<?= $quotaOver === 1 ? '' : 's' ?></strong>
      <?= $quotaOver === 1 ? 'demands' : 'demand' ?> a pace above the studio standard. That is not
      an ambitious plan — it is one the people on it are not expected to hit. The three fixes are
      more people, more time, or less work, and all three land on this page.
    </div>
  <?php endif; ?>

  <div class="grid-scroll">
  <table class="grid-table">
    <thead>
      <tr>
        <th>Step</th><th>Covers</th>
        <th class="num">Remaining</th>
        <th class="num">People</th>
        <th class="num">Minimum / person / day</th>
        <th class="num">Ideal / person / day</th>
        <th>Verdict</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($quotaRows as $q):
      $ds = $q['ds']; $over = $q['over'];
      $cls = ($over === null) ? '' : ($over > 1.05 ? 'err' : ($over < 0.95 ? 'ok' : 'muted')); ?>
      <tr class="<?= $over !== null && $over > 1.05 ? 'has-problem' : '' ?>">
        <td class="wf-name">
          <span class="dot" style="background:<?= e($q['step']['color'] ?: '#ccc') ?>"></span><?= e($q['step']['name']) ?>
        </td>
        <td class="muted small">
          <?= e(applies_to_phrase($q['step']['applies_to'], $q['step']['applies_to_asset_type'] ?? null)) ?>
        </td>
        <td class="num"><?= e(fmt_num((float)$ds['remaining'], 1)) ?></td>
        <td class="num muted"><?= $ds['headcount'] ? e(fmt_num((float)$ds['headcount'], 1)) : '—' ?></td>
        <td class="num <?= $cls ?>">
          <?= $q['min_per_artist'] !== null ? e(fmt_num($q['min_per_artist'], 2)) : '<span class="muted">—</span>' ?>
        </td>
        <td class="num"><?= $q['ideal_per_day'] !== null ? e(fmt_num($q['ideal_per_day'], 2)) : '—' ?></td>
        <td class="small">
          <?php if ($over === null): ?>
            <span class="muted">No deadline on this step, so there is no pace to be behind.</span>
          <?php elseif ($over > 1.05): ?>
            <span class="err">Asks for <?= e(fmt_num(($over - 1) * 100, 0)) ?>% more than the standard.</span>
          <?php elseif ($over < 0.95): ?>
            <span class="ok">Comfortable — <?= e(fmt_num((1 - $over) * 100, 0)) ?>% under the standard.</span>
          <?php else: ?>
            <span class="muted">Right at the standard.</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p class="muted small">
    Steps with no ideal recorded are left out rather than shown blank — a comparison against
    nothing is not a row. The minimum here is the same figure the
    <a href="dashboard.php?project=<?= $projectId ?>">production dashboard</a> shows as
    <em>required</em>, from the same calculation, so the two cannot disagree.
  </p>

<?php endif; ?>

<h3>Other expenses <span class="muted">· <?= count($proj['other'] ?? []) ?></span></h3>

<p class="muted small">
  Costs with no lane behind them — software seats, render or cloud time, a music licence, stock,
  equipment, travel. The projection above is computed from pipeline steps and can only ever contain
  people; <strong>these are the rest of what a show spends</strong>, and they count toward the
  Projected cost and Margin at the top of this page.
  <br>
  <strong>An invoice is a person billing you; an expense is everything that is not somebody's
  time.</strong> Put a freelancer's bill in Invoices below and a vendor's in here, and the two can
  never double-count the same money.
</p>

<?php if (expenses_need_upgrade()): ?>
  <div class="flash flash-warn">
    Other expenses need <code>finance_install_05.sql</code>, which hasn't been imported into the
    finance database yet. Until it is, this section cannot record anything — and
    <strong>Projected cost and Margin above are people only</strong>.
  </div>
<?php else: ?>

  <?php if (!empty($proj['other'])): ?>
    <div class="grid-scroll">
    <table class="grid-table">
      <thead>
        <tr>
          <th>Description</th><th>Vendor</th><th class="num">Amount</th>
          <th>Status</th><th>Incurred</th><th>Paid</th><th>Document</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($proj['by_category'] as $cat => $group): ?>
        <tr class="phase-head">
          <td colspan="8"><strong><?= e($cat) ?></strong>
            <span class="muted small">
              <?= count($group['rows']) ?> item<?= count($group['rows']) === 1 ? '' : 's' ?>
            </span>
          </td>
        </tr>
        <?php foreach ($group['rows'] as $ex): ?>
          <tr>
            <td><?= e($ex['description']) ?>
              <?php if (!empty($ex['notes'])): ?>
                <div class="muted small"><?= e($ex['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= e($ex['vendor'] ?: '—') ?></td>
            <td class="num"><?= e(number_format((float)$ex['amount'], 2)) ?>
              <span class="muted small"><?= e($ex['currency']) ?></span></td>
            <td class="small">
              <span class="<?= $ex['status'] === 'paid' ? 'ok' : ($ex['status'] === 'planned' ? 'muted' : '') ?>">
                <?= e($ex['status']) ?></span>
            </td>
            <td class="muted small"><?= e($ex['incurred_date'] ?: '—') ?></td>
            <td class="muted small"><?= e($ex['paid_date'] ?: '—') ?></td>
            <td class="small">
              <?php if (!empty($ex['document_url'])): ?>
                <a href="<?= e($ex['document_url']) ?>" target="_blank" rel="noopener">open →</a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if (can_edit()): ?>
                <form method="post" onsubmit="return confirm('Remove this expense?');">
                  <button type="submit" name="delete_expense" value="<?= (int)$ex['id'] ?>"
                          class="link-danger">remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr class="subtotal">
          <td colspan="2"><?= e($cat) ?> subtotal</td>
          <td class="num"><strong><?= e(number_format($group['amount'], 2)) ?></strong></td>
          <td colspan="5" class="muted small">
            <?= $group['paid'] > 0 ? e(number_format($group['paid'], 2)) . ' already paid' : '' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="2">Total other expenses</th>
          <th class="num"><?= e(fmt_money_plain($proj['other_total'], $proj['currency'])) ?></th>
          <th colspan="5" class="muted small">
            <?= $proj['other_paid'] > 0 ? e(number_format($proj['other_paid'], 2)) . ' paid so far' : '' ?>
          </th>
        </tr>
      </tfoot>
    </table>
    </div>

    <p class="summary-line">
      <strong><?= e(number_format($proj['total'], 2)) ?></strong> people
      + <strong><?= e(number_format($proj['other_total'], 2)) ?></strong> other
      = <strong><?= e(fmt_money_plain($grandTotal, $proj['currency'])) ?></strong> projected
      <?php if ($budgetAmount !== null): ?>
        against a <?= e(fmt_money_plain($budgetAmount, $cur)) ?> budget
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="muted">Nothing recorded yet. Anything the show pays for that is not somebody's day
       rate belongs here.</p>
  <?php endif; ?>

  <?php if (can_edit()): ?>
    <h4>Add expenses</h4>
    <form method="post">
      <input type="hidden" name="save_expenses" value="1">
      <div class="grid-scroll">
        <table class="grid-table" id="expenseGrid">
          <thead>
            <tr>
              <th>Category</th><th>Description</th><th>Vendor</th><th>Amount</th>
              <th>Currency</th><th>Status</th><th>Incurred</th><th>Paid on</th>
              <th>Document link</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($r = 0; $r < 5; $r++): $n = 'rows[' . $r . ']'; ?>
              <tr>
                <td><input name="<?= $n ?>[category]" list="expenseCategories" size="14"
                           placeholder="e.g. Software"></td>
                <td><input name="<?= $n ?>[description]" size="22" placeholder="What it is"></td>
                <td><input name="<?= $n ?>[vendor]" size="14" placeholder="Who from"></td>
                <td><input name="<?= $n ?>[amount]" inputmode="decimal" size="8" placeholder="1200"></td>
                <td><input name="<?= $n ?>[currency]" maxlength="3" size="3"
                           style="text-transform:uppercase" value="<?= e($cur) ?>"></td>
                <td>
                  <select name="<?= $n ?>[status]">
                    <option value="planned">planned</option>
                    <option value="committed">committed</option>
                    <option value="paid">paid</option>
                  </select>
                </td>
                <td><input type="date" name="<?= $n ?>[incurred_date]"></td>
                <td><input type="date" name="<?= $n ?>[paid_date]"></td>
                <td><input type="url" name="<?= $n ?>[document_url]" size="16"
                           placeholder="https://drive.google.com/…"></td>
                <td><input name="<?= $n ?>[notes]" size="18"></td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <datalist id="expenseCategories">
        <?php foreach (expense_categories() as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
      </datalist>
      <div class="actions">
        <button type="submit">Record expenses</button>
        <button type="button" class="secondary" onclick="expenseAddRows()">Add 5 more rows</button>
      </div>
      <p class="muted small">
        <strong>All three statuses count toward the projection</strong>, which is the point — a
        projection is what the show will cost, not what it has cost so far. <em>Planned</em> is
        budgeted, <em>committed</em> is ordered, <em>paid</em> is settled. Marking one paid without
        a date fills in today. The category list grows as you use it and is shared across every
        project, so the same words mean the same thing on the next show.
      </p>
    </form>

    <script>
    function expenseAddRows() {
      var body = document.querySelector('#expenseGrid tbody');
      if (!body) { return; }
      for (var k = 0; k < 5; k++) {
        var n = body.rows.length;
        var copy = body.rows[n - 1].cloneNode(true);
        var fields = copy.querySelectorAll('input, select');
        for (var i = 0; i < fields.length; i++) {
          var f = fields[i];
          f.name = f.name.replace(/rows\[\d+\]/, 'rows[' + n + ']');
          if (f.tagName === 'SELECT') { f.selectedIndex = 0; }
          else if (!/\[currency\]$/.test(f.name)) { f.value = ''; }
        }
        body.appendChild(copy);
      }
    }
    </script>
  <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
<?php // ---- end EXPENSE PROJECTION ----------------------------------------- ?>

<?php // ---- INVOICES ------------------------------------------------------ ?>
<?php if ($tab === 'invoices'): ?>

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

<?php endif; ?>
<?php // ---- end INVOICES --------------------------------------------------- ?>

</div>
</body></html>
