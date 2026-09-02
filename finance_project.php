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
    // The one home, not the finance report — a project that is gone is gone from
    // both sides of the app, and the list of the ones that remain is here.
    header('Location: index.php');
    exit;
}

// Back to the SAME TAB after a save. The form has no action, so it posts to the
// current URL and ?tab= is still in it — which is how a redirect can know where
// the reader was. Without this every save landed on Overview, and the inline
// rate boxes would have thrown you out of the table on every single one.
$selfUrl = 'finance_project.php?project=' . $projectId;
$fromTab = (string)($_GET['tab'] ?? '');
if (in_array($fromTab, finance_tabs(), true)) $selfUrl .= '&tab=' . $fromTab;

$who = current_user_display();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['set_rate'])) {
            // A DAY RATE, SET FROM THE PROJECTION. Previously this meant leaving
            // for person.php and coming back for each one — a dozen round trips
            // to fill in a dozen slots.
            //
            // ⚠ MERGED OVER WHAT IS ALREADY STORED, never sent alone.
            // save_crew_terms() writes every column through ON DUPLICATE KEY
            // UPDATE ... = VALUES(...), so a payload carrying only day_rate
            // would blank contract_url and reset contract_status to 'none' —
            // silently deleting the record that somebody signed.

            // WHO, checked against the lanes on this show rather than trusted.
            // crew_id arrives from the browser and crew_terms has no foreign key
            // to reach it with — the two live in different databases — so a
            // mistyped or tampered id would otherwise write a pay rate against
            // somebody who does not exist, in a table nothing would ever clean up.
            //
            // project_lanes() is the same source the table itself is drawn from,
            // so every row on screen passes by construction and nothing else can.
            $crewId = (int)($_POST['crew_id'] ?? 0);
            $name = '';
            foreach (project_lanes($pdo, $projectId) as $l) {
                if ((int)$l['crew_id'] === $crewId) { $name = (string)$l['name']; break; }
            }
            if ($crewId <= 0 || $name === '') {
                throw new RuntimeException('That person is not on this project, so no rate was saved.');
            }

            // SELECT * from crew_terms, so the keys here are the column names
            // save_crew_terms() reads — contract_url, contract_status and the two
            // contract dates come back out exactly as they went in. Only the rate
            // is overwritten. The unit and currency are NOT posted at all: this
            // column edits one number, and a hidden field carrying the other two
            // would be a second copy of them, free to drift.
            $existing = crew_term($crewId) ?? [];
            $merged = array_merge($existing, ['day_rate' => $_POST['day_rate'] ?? '']);
            flash('ok', save_crew_terms($crewId, $name, $merged, $who));

        } elseif (isset($_POST['save_budget'])) {
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
                flash('warn', 'Nothing changed, every step is already in the phase shown.');
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
        'label'     => $l['name'] . ', ' . $l['step_name'],
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

// Needed by two tabs — Overview draws the phase breakdown, Projection edits the
// phases — so it is worked out once here rather than inside whichever block
// happens to run.
$hasDeptCol = column_exists($pdo, 'pipeline_steps', 'department');

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
    A <strong>task</strong> is one item through one step, every shot and every asset multiplied by
    the steps that apply to it, so <?= (int)$progress['applicable'] ?> is the whole show as a
    count of things to finish, not a count of shots. The same figures the
    <a href="dashboard.php?project=<?= $projectId ?>">production dashboard</a> draws, from the same
    calculation, so the two screens cannot disagree about how far along the show is.
    <?php // Worth saying here because the projection above can legitimately show a
          // bigger unit count: step_quota() falls back to project_settings.estimated_*
          // when a step has no real items yet, and labels those "est." — this panel
          // only ever counts rows that exist. ?>
    Where the expense projection shows more units than this, those are <em>estimates</em> standing
    in for a breakdown that has not been entered yet.
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
    numbers together. <strong>Treat the projected cost as indicative until that is resolved</strong>,
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

  <?php if (!$hasDeptCol): ?>
    <p class="muted small warn-text">
      Phases need migration <strong>015</strong>, which hasn't been applied, so everything groups
      as <em>Unassigned</em>.
    </p>
  <?php elseif (can_edit()): ?>
    <p class="muted small">
      Which phase each step belongs to is set at the top of the
      <a href="finance_project.php?project=<?= $projectId ?>&amp;tab=projection">Expense
      projection</a>, beside the table it regroups.
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

<?php
// The phase editor sits HERE rather than on the Overview, at David's request.
// It belongs beside the thing it regroups: this table's department bands, its
// subtotals and the export built from them all key on pipeline_steps.department,
// so the question "should Comp be Post-production?" is asked while looking at
// this page and answered without leaving it.
//
// Still a production fact, not a financial one — it is written on the ordinary
// connection and it regroups the crew list and the timeline too.
?>
<?php if ($hasDeptCol && can_edit()): ?>
  <details class="inline-actions">
    <summary>Which phase each step belongs to</summary>
    <p class="muted small">
      A step's phase is what groups it here, on the expense projection, and in the crew list,
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
    Phases need migration <strong>015</strong>, which hasn't been applied, so every row below
    groups as <em>Unassigned</em>.
  </p>
<?php endif; ?>


<?php
// ---- ABOVE the table, deliberately ------------------------------------------
//
// This sat below the projection until 2026-08-17, and David could not find it:
// the table it followed runs to forty-odd rows on a real show, so the one block
// that says whether the budget is DELIVERABLE was permanently past the fold.
//
// It belongs first. The order a budget is actually built in is: can the plan be
// done at all, then what does it cost. Reading the cost of a plan nobody can
// deliver is answering the second question having skipped the first.
?>
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
// ⚠ THE MINIMUM COMES FROM step_quota(), NOT FROM dashboard_steps().
//
// This used the dashboard's figure until David noticed the two screens giving
// different answers for the same step: Dates & headcount said 0.85 a day for
// CH sheet, this table said 1.86. Both were arithmetically correct and they were
// answering different questions.
//
//   step_quota()      applies coverage_pct — 39% of 13 characters is 5.1 items —
//                     and counts working days from the step's own START DATE.
//   dashboard_steps() ignores coverage by design (2026-08-12: real N/A markings
//                     beat the coverage estimate) and counts from TODAY.
//
// The dashboard's reading is right for a show in flight, where items have been
// marked N/A and what matters is the pace from here. It is wrong for THIS table,
// which asks whether the plan as drawn can be delivered — where coverage is the
// only statement of scope there is, and a step that has not started yet has its
// own start date rather than beginning this morning. Counting all 13 characters
// through a step that touches 5 overstates the required pace by two and a half
// times, and counting from today rather than the start quietly adds a day.
//
// So it now uses exactly what the Dates & headcount screen uses, and the two
// screens agree — which is the rule this codebase keeps having to relearn.
$quotaRows = [];
$quotaOver = 0;
if (standards_available($pdo)) {
    $sheet = standards_for_type($pdo, (string)$project['type']);
    if ($sheet) {
        try {
            $qEstimates = project_estimates($pdo, $projectId);
            $qRetake    = project_retake_pct($pdo, $projectId);

            foreach (pipeline_steps($pdo, $projectId) as $st) {
                $std = $sheet[standard_key($st['name'], $st['applies_to'] ?? 'shot',
                                           $st['applies_to_asset_type'] ?? '')] ?? null;
                if ($std === null) continue;                 // no standard to compare against

                $q = step_quota($pdo, $projectId, $st, null, $qEstimates, $qRetake);

                $idealDays    = (float)$std['days_per_unit'];
                $idealPerDay  = $idealDays > 0 ? 1 / $idealDays : null;
                $minPerArtist = isset($q['per_artist_day']) && $q['per_artist_day'] !== null
                              ? (float)$q['per_artist_day'] : null;

                $over = ($minPerArtist !== null && $idealPerDay !== null && $idealPerDay > 0)
                      ? $minPerArtist / $idealPerDay : null;
                if ($over !== null && $over > 1.05) $quotaOver++;

                $quotaRows[] = [
                    'q' => $q, 'step' => $st, 'std' => $std,
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
  one person per day, remaining work over the working days left, divided by the people on it. It
  is arithmetic, <strong>not editable</strong>, and it climbs on its own every time a date gets
  closer or work slips. <strong>Ideal</strong> is what the studio says that work actually takes,
  from the <a href="standards.php?from=<?= $projectId ?>&amp;type=<?= urlencode((string)$project['type']) ?>">base sheet</a>
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
      <a href="standards.php?from=<?= $projectId ?>&amp;type=<?= urlencode((string)$project['type']) ?>">base sheet</a> and
      this comparison fills in. It is the one thing that can tell you a budget is buying a plan
      nobody can deliver.</p>
  </div>
<?php else: ?>

  <?php if ($quotaOver > 0): ?>
    <div class="flash flash-err">
      <strong><?= $quotaOver ?> step<?= $quotaOver === 1 ? '' : 's' ?></strong>
      <?= $quotaOver === 1 ? 'demands' : 'demand' ?> a pace above the studio standard. That is not
      an ambitious plan, it is one the people on it are not expected to hit. The three fixes are
      more people, more time, or less work, and all three land on this page.
    </div>
  <?php endif; ?>

  <?php
  // ⚠ NO .grid-scroll WRAPPER — that is what stopped the header sticking.
  // overflow-x:auto makes the div the nearest scrolling ancestor, and
  // position:sticky sticks to THAT box, not the page. The box itself never
  // scrolls vertically, so the header looked frozen rather than stuck. Same
  // fix as standards.php the same day: drop the wrapper, give the table a
  // colgroup so it lays out in the page width without needing one.
  ?>
  <table class="grid-table quota-compare-table">
    <colgroup>
      <col style="width:16%"> <?php // Step ?>
      <col style="width:12%"> <?php // Covers ?>
      <col style="width:10%"> <?php // Remaining ?>
      <col style="width:8%">  <?php // People ?>
      <col style="width:12%"> <?php // Minimum / person / day ?>
      <col style="width:12%"> <?php // Ideal / person / day ?>
      <col style="width:30%"> <?php // Verdict ?>
    </colgroup>
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
      $qq = $q['q']; $over = $q['over'];
      $cls = ($over === null) ? '' : ($over > 1.05 ? 'err' : ($over < 0.95 ? 'ok' : 'muted')); ?>
      <tr class="<?= $over !== null && $over > 1.05 ? 'has-problem' : '' ?>">
        <td class="wf-name">
          <span class="dot" style="background:<?= e($q['step']['color'] ?: '#ccc') ?>"></span><?= e($q['step']['name']) ?>
        </td>
        <td class="muted small">
          <?= e(applies_to_phrase($q['step']['applies_to'], $q['step']['applies_to_asset_type'] ?? null)) ?>
        </td>
        <?php // The work this step actually covers, after coverage — the same
              // figure the Dates & headcount screen shows in its Work column. ?>
        <td class="num"><?= e(fmt_num((float)($qq['units'] ?? 0), 1)) ?>
          <div class="muted small"><?= e($qq['unit_label'] ?? '') ?></div></td>
        <td class="num muted"><?= !empty($qq['headcount']) ? e(fmt_num((float)$qq['headcount'], 1)) : '—' ?></td>
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
            <span class="ok">Comfortable, <?= e(fmt_num((1 - $over) * 100, 0)) ?>% under the standard.</span>
          <?php else: ?>
            <span class="muted">Right at the standard.</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted small">
    Steps with no ideal recorded are left out rather than shown blank, a comparison against
    nothing is not a row. The minimum here is the same figure the
    <a href="dashboard.php?project=<?= $projectId ?>">production dashboard</a> shows as
    <em>required</em>, from the same calculation, so the two cannot disagree.
  </p>

<?php endif; ?>

<?php
// WHERE THE DAYS CAME FROM. The budget is built on the IDEAL quota — the studio
// standard for this kind of project — and falls back to what this project was
// planned at only where no standard exists. Said here in one line rather than
// left to be worked out from the per-row marks below.
$onIdeal    = (int)($proj['rows_on_ideal'] ?? 0);
$onEstimate = (int)($proj['rows_on_estimate'] ?? 0);
?>
<?php if (!($proj['standards_ready'] ?? false)): ?>
  <div class="flash flash-warn">
    <strong>Ideal quotas are not available</strong> (migration 026 has not been applied), so every
    figure below is costed from this project's own estimates instead of the studio standard.
  </div>
<?php elseif ($onEstimate > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $onEstimate ?></strong> of <?= $onIdeal + $onEstimate ?> rows have
    <strong>no studio standard</strong> for <?= e($proj['project_type'] ?? '') ?> projects, so they
    are costed from what this project was planned at. Those are marked
    <em>est.</em> below.
    <a href="standards.php?from=<?= $projectId ?>&amp;type=<?= urlencode((string)($proj['project_type'] ?? '')) ?>">Set the
    ideal quotas →</a>
  </div>
<?php elseif ($onIdeal > 0): ?>
  <p class="muted small ok">
    Every row below is costed from the studio's ideal quota for
    <?= e($proj['project_type'] ?? '') ?> projects.
  </p>
<?php endif; ?>

<p class="page-actions">
  <a class="button" href="export_projection.php?project=<?= $projectId ?>">Download as Excel ↓</a>
  <span class="muted small">
    Built live from the numbers below, in the fifteen-column shape of
    <code>RTC_ExpenseProjection_v01.xlsx</code>, department bands, subtotals, grand total and the
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
  <br><strong>A rate belongs to the PERSON, not to this row or this project.</strong> Type one in the
  Rate column and it saves against them everywhere, the other rows they appear on here, and every
  future show they are booked on. A rate set on a <em>slot</em> is the exception: a slot exists only
  on this project, so its rate goes when the slot does.
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
        <?php // Says WHOLE STEP because it is the one column here that is not
              // per-row: Days, Months and Projected are all one person's share,
              // and reading this as theirs too is what made the export charge
              // every animator for all of the footage. ?>
        <th class="num" title="The step's total. Days, Months and Projected are one person's share of it.">Units
          <span class="muted">(step)</span></th>
        <th class="num">Actual days</th><th class="num">Actual</th>
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
          <?php // EDITABLE HERE. A rate belongs to a PERSON, not to this row —
                // crew_terms is keyed on crew_id alone — so setting it here sets
                // it everywhere that person appears, on this project and every
                // other. The note under the table says so, because two rows for
                // the same artist changing together would otherwise look like a
                // bug.
                //
                // It posts and reloads rather than saving in the background: the
                // Projected column, the subtotals, the grand total and the margin
                // all move with it, and every one of them comes from
                // expense_projection(). Recomputing them in the browser would be
                // a second implementation of the money, free to disagree with
                // the first. ?>
          <td class="small">
            <?php if ($r['crew_id']): ?>
              <form method="post" class="rate-edit">
                <input type="hidden" name="set_rate" value="1">
                <input type="hidden" name="crew_id" value="<?= (int)$r['crew_id'] ?>">
                <?php // Saves on leaving the field, the same submit-on-change the
                      // role picker and the status dropdown use. ?>
                <input name="day_rate" inputmode="decimal" size="6" onchange="this.form.submit()"
                       value="<?= $r['rate'] !== null ? e(fmt_num((float)$r['rate'], 2)) : '' ?>"
                       placeholder="set" aria-label="Day rate for <?= e((string)$r['artist']) ?>"
                       class="<?= $r['rate'] === null ? 'rate-unset' : '' ?>">
                <span class="muted"><?= e($r['currency'] ?: 'USD') ?> / <?= e($r['rate_unit'] ?: 'day') ?></span>
              </form>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="num"><?= e(fmt_num($r['days'], 2)) ?>
            <?php // Which number produced these days. An unmarked row rests on a
                  // studio decision; an "est." row rests on one project's guess,
                  // and the difference matters the moment a budget is queried. ?>
            <?php if (!empty($r['rate_mismatch'])): ?>
              <?php // A per-second rate on a step that is not measured in seconds.
                    // There is nothing to multiply it by, so the row is unpriced
                    // rather than costed against an item count — which would be a
                    // confident wrong number nothing on screen would question. ?>
              <div class="err small">rate is per second, this step is not</div>
            <?php endif; ?>
            <?php if (($r['days_source'] ?? '') === 'estimate'): ?>
              <span class="est-tag" title="No studio standard for this step, so this row is costed from the project's own estimate">est.</span>
            <?php endif; ?>
          </td>
          <td class="num muted"><?= e(fmt_num($r['months'], 2)) ?></td>
          <td class="num"><?= $r['cost'] === null ? '<span class="muted">—</span>' : e(number_format($r['cost'], 2)) ?></td>
          <?php // 1dp, not 0. Whole numbers still print clean because fmt_num()
                // trims the trailing zero, but 40.5 seconds of footage was
                // rendering as "41" — overstating the show and disagreeing with
                // the export, which writes the column at full precision. ?>
          <td class="num muted" title="The whole step. The Days column beside it is this row's share of it."><?=
                e(fmt_num($r['units'], 1)) ?></td>
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
        <th colspan="5">Total projected, people</th>
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
    <strong>Actual cost comes from the invoices</strong> attached to that position, not from days
    times a rate, which would be a second estimate rather than a fact. A dash in either means
    nothing was recorded, which is not the same as it having been free.
    <strong>Difference</strong> is projected minus actual: green means it cost less than planned.
    <?php if ($unattached > 0): ?>
      The actual total here counts only invoices attached to a position, so it is
      <?= e(fmt_money_plain($unattached, $cur)) ?> lower than the <em>Invoiced</em> figure at the
      top of the page. That gap is the unattached invoices, and it is a real difference rather
      than a rounding error, the money is spent either way, it just belongs to no row.
    <?php endif; ?>
    A day is <?= e(fmt_num($proj['hours_per_day'], 1)) ?> hours on this project.
  </p>

  <?php if ($proj['unpriced_days'] > 0): ?>
    <p class="flash flash-warn">
      <strong><?= e(fmt_num($proj['unpriced_days'], 1)) ?> person-days have no rate behind them</strong>,
      mostly lanes still held by unfilled slots. Those days are <em>excluded</em> from the total
      rather than counted as free, so the real cost is higher than it says. Type one into the
      <strong>Rate</strong> column above and every figure on this page moves with it.
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($unattached > 0): ?>
  <p class="flash flash-warn">
    <strong><?= e(fmt_money_plain($unattached, $cur)) ?> of invoices name no position</strong>,
    a kill fee, a one-off, or simply nobody picked one. That money counts against the budget but
    cannot appear on a row above, because spreading it across rows would put a figure into a
    subtotal that nobody agreed to. Edit those invoices to attach them, or leave them as they are.
  </p>
<?php endif; ?>

<h3>Other expenses <span class="muted">· <?= count($proj['other'] ?? []) ?></span></h3>

<p class="muted small">
  Costs with no lane behind them, software seats, render or cloud time, a music licence, stock,
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
    finance database yet. Until it is, this section cannot record anything, and
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
        <strong>All three statuses count toward the projection</strong>, which is the point, a
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
  One row per invoice, five at a time, a month's invoices arrive as a batch, and one form submit
  each was six page loads to do one afternoon's bookkeeping. Only the <strong>amount</strong> is
  required; blank rows are ignored. A row with details but no amount is <em>not</em> ignored, it
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
                <option value="">no particular position</option>
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
    Choosing a <strong>position</strong> puts that invoice on that row of the projection above,
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
// exactly one field on the page, with five rows it would have written every
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
  <strong>The reference is theirs, not ours</strong>, nothing is generated here, because a second
  number guarantees the two disagree the moment somebody quotes one over email.
  Marking an invoice paid without a date fills in today: a status that cannot be checked later
  is not a record.
</p>

<?php endif; ?>
<?php // ---- end INVOICES --------------------------------------------------- ?>

</div>
</body></html>
