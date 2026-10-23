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
require_once __DIR__ . '/includes/stopcost.php';
require_once __DIR__ . '/includes/budgetpool.php';
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

        } elseif (isset($_POST['bulk_rates'])) {
            // ONE RATE, MANY ROWS. Ticked in the projection table, applied here
            // one person at a time through the same merge-and-save the single
            // box above uses, so contracts, unit and currency survive exactly
            // as they do there.
            //
            // Everybody is checked against this project's lanes, same as the
            // single box: an id the table did not draw cannot be written to.
            $onShow = [];
            foreach (project_lanes($pdo, $projectId) as $l) {
                $onShow[(int)$l['crew_id']] = [
                    'name' => (string)$l['name'],
                    'slot' => (int)($l['is_placeholder'] ?? 0) === 1,
                ];
            }
            $ids = array_unique(array_map('intval', (array)($_POST['crew_ids'] ?? [])));
            $ids = array_values(array_filter($ids, fn($id) => isset($onShow[$id])));
            if (!$ids) {
                throw new RuntimeException('No ticked row is on this project, so no rate was saved.');
            }

            // A blank here would WIPE every ticked rate, which is never what a
            // bulk action is for. Clearing stays a one-row-at-a-time job.
            $rate = trim((string)($_POST['day_rate'] ?? ''));
            if ($rate === '') {
                throw new RuntimeException('Type a rate before applying it, nothing was saved.');
            }
            $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $rate));
            if ($clean === '' || !is_numeric($clean) || (float)$clean < 0) {
                throw new RuntimeException('"' . $rate . '" is not a rate, nothing was saved.');
            }

            // REAL PEOPLE need a second yes. Their rate is theirs on every show,
            // so a tick here rewrites what other projects are costed at too.
            // The browser asks; this refuses if it did not, so a page with
            // scripts off cannot do it silently.
            $people = [];
            foreach ($ids as $id) {
                if (!$onShow[$id]['slot']) $people[] = $onShow[$id]['name'];
            }
            if ($people && ($_POST['confirm_people'] ?? '') !== '1') {
                throw new RuntimeException('The selection includes real people ('
                    . implode(', ', $people) . '), whose rate changes on every show. '
                    . 'Nothing was saved; apply again and confirm.');
            }

            foreach ($ids as $id) {
                $existing = crew_term($id) ?? [];
                save_crew_terms($id, $onShow[$id]['name'],
                    array_merge($existing, ['day_rate' => $rate]), $who);
            }
            $n = count($ids);
            $said = sprintf('Rate %s applied to %d row%s.', $clean, $n, $n === 1 ? '' : 's');
            if ($people) {
                $said .= ' Changed on every show for: ' . implode(', ', $people) . '.';
            }
            flash('ok', $said);

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
        // Carried for the Final balance test below: a PLACEHOLDER is a slot
        // nobody filled, and nobody can invoice for work that was never done.
        'is_placeholder' => !empty($l['is_placeholder']),
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
// could be recorded at all. (2026-08-17.) The name itself went on 2026-10-06 —
// see below — for the same reason the bug was possible: it read as profit.
$grandTotal = $proj['grand_total'] ?? $proj['total'];

// BUDGET VARIANCE: approved production budget minus what the show is now
// forecast to cost. Positive is under, negative is over.
//
// ⚠ REVENUE IS DELIBERATELY ABSENT FROM THIS LINE. David, 2026-10-06: "this
// should only compare the production budget against the current forecast. Do
// not add client revenue or profit here." The two numbers answer different
// questions — "are we inside what Jorik approved" and "did the show make money"
// — and one figure that quietly mixed them would answer neither.
//
// The arithmetic is unchanged from the old Margin; only its name and how it
// reads on screen are new. It was renamed because "Margin" means profit to
// everybody who reads it, and this has never been profit.
$variance = ($budgetAmount !== null && $grandTotal > 0) ? $budgetAmount - $grandTotal : null;
// ⚠ WHY there is no variance, since the two reasons are not the same thing and
// "no approved budget yet" under a screen that plainly shows one would be a lie.
$varianceWhy = $budgetAmount === null
    ? 'no approved budget yet'
    : ($grandTotal > 0 ? '' : 'nothing is costed yet, so there is nothing to compare');
// FINAL BALANCE — the approved production budget against money that has really
// changed hands. David, 2026-10-12: "let's do it against the approved budget and
// let's call it final balance", and, asked which side to subtract, "show both".
//
// So there are two, and they answer the same question at different distances
// from the bank:
//   — vs invoiced: approved less everything billed, settled or not. The
//     conservative one; it counts money committed even before it leaves.
//   — vs paid:     approved less money that has actually gone. The optimistic
//     one mid-show, and the true one at the end.
// They converge the moment the last invoice is settled, which is the point at
// which the figure stops moving and deserves the word "final".
//
// ⚠ AGAINST THE APPROVED BUDGET, NOT THE FORECAST. This replaces the "Left to
// spend" tile built earlier the same day, which compared invoices against the
// current forecast cost. David overruled that, and he is right for the audience:
// Jorik approved a number, and the question a client takes away is how much of
// THAT number was spent. Budget variance above still carries the forward-looking
// comparison (approved against forecast), so nothing was lost — the strip now
// reads plan-versus-approval at the left and actual-versus-approval at the right.
//
// Null when no budget has been approved: there is nothing to be a balance of,
// and 0 would read as "spent exactly to the penny".
$finalVsInvoiced = $budgetAmount !== null ? $budgetAmount - $billed : null;
$finalVsPaid     = $budgetAmount !== null ? $budgetAmount - $paid   : null;

// ⚠ WHAT MAKES IT "FINAL", AND WHY IT IS NOT JUST "NOTHING UNPAID". MSD today
// has no invoices at all, so $unpaid is 0.00 — and a green "final" panel on a
// show where nobody has billed anything yet would be the most confident kind of
// wrong this codebase keeps paying for. David's own words carried the answer:
// "green once the last invoice has been paid (LINKED TO EACH SLOT)".
//
// So three conditions, all of which have to hold:
//   1. something has actually been invoiced;
//   2. nothing is outstanding;
//   3. every staffing slot with a REAL PERSON in it has an invoice against it.
//
// Placeholder slots are skipped on purpose. An unfilled slot is work nobody did,
// so no invoice will ever arrive for it, and requiring one would put "final" out
// of reach for ever on any show that was planned more generously than it was
// staffed.
$invoicedSlots = [];
foreach ($invoices as $iv) {
    if (!empty($iv['crew_id']) && !empty($iv['step_id'])) {
        $invoicedSlots[(int)$iv['crew_id'] . '|' . (int)$iv['step_id']] = true;
    }
}
$slotsAwaiting = 0;
foreach ($positions as $pos) {
    if ($pos['is_placeholder']) continue;
    if (!isset($invoicedSlots[$pos['crew_id'] . '|' . $pos['step_id']])) $slotsAwaiting++;
}
// Money is rounded to cents everywhere in this codebase, so "nothing left" is a
// comparison against half a cent rather than against exact zero — a float sum of
// a dozen invoices does not reliably land on 0.0.
$isFinal = $billed > 0 && $unpaid < 0.005 && $slotsAwaiting === 0;

// ⚠ AND IT SAYS WHAT IS STILL MISSING. "Not final" with no reason is a dead end
// on a screen somebody is using to answer a question from the boss; naming the
// blocker turns it into the next thing to do. Most-blocking first.
$finalWhy = '';
if (!$isFinal) {
    if ($billed <= 0) {
        $finalWhy = 'nothing invoiced yet';
    } elseif ($slotsAwaiting > 0) {
        $finalWhy = $slotsAwaiting . ' position'
                  . ($slotsAwaiting === 1 ? '' : 's') . ' not invoiced yet';
    } else {
        $finalWhy = 'waiting on the last payment';
    }
}

// WHAT THIS SHOW HAS COST SO FAR, and what the plan still has booked. Worked
// out here with the rest of the money rather than inside the Overview block,
// for the reason stated at the tab switch below: the tabs choose what renders,
// never what is computed.
$stop = project_stop_cost($pdo, $projectId, $invoices);

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
  <?php // SEVEN FIGURES, IN THE ORDER DAVID LISTED THEM on 2026-10-12, and they
        // are all about COST. What we may spend, what we now expect to spend,
        // whether that is inside the approval; then what has actually been
        // billed to us, how much of that is settled, how much is not, and what
        // is still to come.
        //
        // Revenue was here for a week and was removed with this change. It took
        // part in no sum, which is exactly why it had to go: a client figure
        // sitting on a panel of costs gets read as profit by somebody skimming. ?>
  <div><span class="muted small">Approved production budget</span>
    <strong><?= $budgetAmount === null ? '—' : e(fmt_money_plain($budgetAmount, $cur)) ?></strong>
    <?php // Both of these are TYPED IN, and the box is far down the page — further
          // since the Cost to date panel went in above it. A figure you can set
          // should say where it is set. ?>
    <a class="muted small" href="#budget-form">what we may spend · set it ↓</a></div>
  <?php // RENAMED 2026-10-05 (David): "Projected cost" sounded like a figure
         // agreed once at the start. It is recomputed on every page load from
         // the current pipeline, staffing and rates, so the name now says which
         // of the two it is. Sentence case, like every other label on this strip.
         ?>
  <div><span class="muted small">Current forecast cost</span>
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
  <?php // ⚠ THE SIGN IS SPELLED OUT, NOT LEFT TO A MINUS. "-2,000.00 USD"
        // against a budget is read at a glance as "2,000 left" by anybody not
        // looking closely, and this is the figure the whole panel exists for.
        // The absolute amount plus the word is unambiguous either way up. ?>
  <div><span class="muted small">Budget variance</span>
    <strong class="<?= $variance !== null ? ($variance < 0 ? 'err' : 'ok') : '' ?>">
      <?= $variance === null ? '—' : e(fmt_money_plain(abs($variance), $cur)) ?></strong>
    <?php if ($variance !== null): ?>
      <?php // Dead level is its own answer. "0.00 UNDER BUDGET" is true and reads
            // as a near miss; spending the budget exactly is neither under nor
            // over, and saying so costs one branch. ?>
      <span class="<?= $variance < 0 ? 'err' : 'ok' ?> small">
        <?= $variance < 0 ? 'OVER BUDGET' : ($variance > 0 ? 'UNDER BUDGET' : 'ON BUDGET') ?></span>
    <?php else: ?>
      <span class="muted small"><?= e($varianceWhy) ?></span>
    <?php endif; ?></div>
  <div><span class="muted small">Invoiced</span><strong><?= e(fmt_money_plain($billed, $cur)) ?></strong>
    <span class="muted small"><?= count($invoices) ?> invoice(s)</span></div>
  <?php // Paid and Unpaid add up to Invoiced, deliberately shown as three tiles
        // rather than one with a split underneath: "how much have we actually
        // parted with" is its own question, and it was only answerable from the
        // Cost to date panel further down until now. ?>
  <div><span class="muted small">Paid</span>
    <strong><?= e(fmt_money_plain($paid, $cur)) ?></strong>
    <span class="muted small">money that has left</span></div>
  <div><span class="muted small">Unpaid</span>
    <strong class="<?= $overdue ? 'err' : '' ?>"><?= e(fmt_money_plain($unpaid, $cur)) ?></strong>
    <?php if ($overdue): ?><span class="err small"><?= $overdue ?> overdue</span><?php endif; ?></div>
  <?php // ⚠ THE SIGN IS SPELLED OUT, exactly as it is on Budget variance and for
        // the same reason: a bare minus in front of money is read as "less" by
        // anybody not looking closely, and these two are the figures somebody
        // carries into a meeting.
        //
        // The TINT is on these two tiles and nowhere else (David, 2026-10-12:
        // the colour goes on "just the final balance number"). Yellow is the
        // same --warn-tint the pending staffing slots use, so "not settled yet"
        // reads identically on both screens; green only once it is really over.
        //
        // $finalClass is computed once rather than twice: two tiles that could
        // disagree about whether the show is finished would be worse than
        // either answer. ?>
  <?php $finalClass = $isFinal ? 'tile-final' : 'tile-pending'; ?>
  <div class="<?= $finalClass ?>"><span class="muted small">Final balance — invoiced</span>
    <strong class="<?= $finalVsInvoiced !== null && $finalVsInvoiced < 0 ? 'err' : '' ?>">
      <?= $finalVsInvoiced === null ? '—' : e(fmt_money_plain(abs($finalVsInvoiced), $cur)) ?></strong>
    <?php if ($finalVsInvoiced === null): ?>
      <span class="muted small">no approved budget yet</span>
    <?php elseif ($finalVsInvoiced < 0): ?>
      <span class="err small">OVER BUDGET</span>
    <?php else: ?>
      <span class="muted small">approved less everything billed</span>
    <?php endif; ?></div>
  <div class="<?= $finalClass ?>"><span class="muted small">Final balance — paid</span>
    <strong class="<?= $finalVsPaid !== null && $finalVsPaid < 0 ? 'err' : '' ?>">
      <?= $finalVsPaid === null ? '—' : e(fmt_money_plain(abs($finalVsPaid), $cur)) ?></strong>
    <?php if ($finalVsPaid === null): ?>
      <span class="muted small">no approved budget yet</span>
    <?php elseif ($finalVsPaid < 0): ?>
      <span class="err small">OVER BUDGET</span>
    <?php else: ?>
      <span class="<?= $isFinal ? 'ok' : 'muted' ?> small">
        <?= $isFinal ? 'FINAL' : e($finalWhy) ?></span>
    <?php endif; ?></div>
</div>

<?php // Said once, under the strip, because the pair invites exactly this
      // question and David asked it himself: the figure is not final until the
      // last invoice is in AND settled. ?>
<p class="muted small">
  <strong>Final balance</strong> is the approved production budget less what the show has
  actually cost — against everything <em>invoiced</em>, and against what has actually been
  <em>paid</em>. The two meet when the last invoice is settled. Both tiles stay
  <span class="tile-pending" style="padding:1px 5px;border-radius:3px;">amber</span> until every
  staffed position has been invoiced and nothing is outstanding, then turn
  <span class="tile-final" style="padding:1px 5px;border-radius:3px;">green</span>.
  <?php if (!$isFinal && $billed > 0 && $slotsAwaiting > 0): ?>
    Right now <?= (int)$slotsAwaiting ?> staffed position<?= $slotsAwaiting === 1 ? ' has' : 's have' ?>
    no invoice against <?= $slotsAwaiting === 1 ? 'it' : 'them' ?> — the
    <a href="?project=<?= $projectId ?>&amp;tab=invoices">Invoices tab</a> is where they go.
  <?php endif; ?>
</p>


<?php if ($proj['mixed_currency']): ?>
  <div class="flash flash-warn">
    People on this show are paid in more than one currency, and these totals simply add the
    numbers together. <strong>Treat the current forecast cost as indicative until that is resolved</strong>,
    nothing here converts between currencies, and inventing an exchange rate would be worse than
    saying so.
  </div>
<?php endif; ?>

<?php // ---- COST TO DATE -------------------------------------------------
      //
      // David, 2026-10-06, the day FCM went on standby: his boss needs a figure
      // for the client. ⚠ FOUR NUMBERS AND NOT ONE BLEND, in descending order
      // of how defensible each is, because a single figure invites exactly one
      // question — "how did you get that?" — and a blend cannot survive it.
      // The arithmetic for each is on the screen beside it for the same reason.
      ?>
<h3>Cost to date <span class="muted">— what has been spent, and what is still booked</span></h3>

<div class="dash-strip">
  <div><span class="muted small">Invoiced and paid</span>
    <strong><?= e(stop_cost_amounts($stop['paid'])) ?></strong>
    <span class="muted small">money that has left</span></div>
  <div><span class="muted small">Invoiced, unpaid</span>
    <strong><?= e(stop_cost_amounts($stop['unpaid'])) ?></strong>
    <span class="muted small">billed, document exists</span></div>
  <div><span class="muted small">Worked, not invoiced</span>
    <strong><?= e(stop_cost_amounts($stop['worked']['totals'])) ?></strong>
    <span class="warn-text small">estimate — <?= e(fmt_num($stop['worked']['hours'], 1)) ?> h logged</span></div>
  <div><span class="muted small">Still booked after today</span>
    <strong><?= e(stop_cost_amounts($stop['booked']['totals'])) ?></strong>
    <span class="warn-text small">the plan, not a contract</span></div>
</div>

<?php if ($stop['mixed']): ?>
  <div class="flash flash-warn">
    <strong>This show pays in more than one currency, so these are listed side by side and
    never added up.</strong> Nothing in this platform converts between currencies, and inventing
    an exchange rate on the screen that goes to a client would be the worst possible place to
    do it. Convert them yourself, at a rate you can name and date.
  </div>
<?php endif; ?>

<?php // ⚠ THE LIMIT OF THIS SCREEN, said on the screen rather than in a doc
      // nobody opens. "What do we owe if the show never restarts" needs a
      // contract start, an end, a notice period or a guaranteed minimum, and
      // crew_terms holds none of those four. Somebody reading this panel is
      // about to quote a number to a client; they need to know which question
      // it answers. ?>
<div class="flash flash-warn">
  <p style="margin:0 0 6px;"><strong>What this cannot tell you: what is contractually owed if the
     show never restarts.</strong></p>
  <p class="small" style="margin:0;">
    Rates are on file; contract start and end dates, notice periods and guaranteed minimums are
    not. <em>Still booked after today</em> is what the <strong>plan</strong> has people down for
    — the right order of magnitude, and not the same thing as an obligation. The contracts
    themselves are the only source for that, and the
    <a href="crew.php?project=<?= (int)$projectId ?>">Staffing screen</a> links each one.
  </p>
</div>

<?php if ($stop['why'] !== ''): ?>
  <div class="flash flash-warn"><?= e($stop['why']) ?></div>
<?php endif; ?>

<?php // ⚠ PEOPLE WITH HOURS AND NO RATE ARE NAMED, NEVER COUNTED AS ZERO. A
      // silent nought makes the total look complete while understating what is
      // owed to a real person — the same failure as a screen that renders
      // empty, pointed at the one number somebody is about to send a client. ?>
<?php if ($stop['unpriced']): ?>
  <div class="flash flash-err">
    <p style="margin:0 0 6px;"><strong>These people have logged hours and no rate on file, so they
       are missing from <em>Worked, not invoiced</em> entirely.</strong></p>
    <p class="small" style="margin:0;">
      <?php $bits = [];
            foreach ($stop['unpriced'] as $u) {
                $bits[] = e($u['name']) . ' (' . e(fmt_num($u['hours'], 1)) . ' h)';
            }
            echo implode(', ', $bits); ?>
      — set a rate on the <a href="crew.php?project=<?= (int)$projectId ?>">Staffing screen</a>
      and this figure will include them.
    </p>
  </div>
<?php endif; ?>

<?php if ($stop['flat']): ?>
  <div class="flash flash-warn">
    <strong>Paid a flat fee, so their hours cannot price the work:</strong>
    <?php $bits = [];
          foreach ($stop['flat'] as $f) {
              $bits[] = e($f['name']) . ' (' . e(fmt_money_plain((float)$f['amount'], $f['currency']))
                      . ', ' . e(fmt_num($f['hours'], 1)) . ' h logged)';
          }
          echo implode(', ', $bits); ?>.
    Add the fee itself where it belongs rather than reading it off the hours.
  </div>
<?php endif; ?>

<?php if ($stop['worked']['rows'] || $stop['booked']['rows']): ?>
  <details class="stop-detail">
    <summary>Show the arithmetic, person by person</summary>

    <?php if ($stop['worked']['rows']): ?>
      <h4>Worked, not yet invoiced</h4>
      <p class="muted small">
        Hours from the time log — the day the work <em>happened</em>, not the day it was typed
        — at each person's own rate. An hourly rate multiplies the hours; a day rate divides
        them by <?= e(fmt_num(hours_per_day($pdo, $projectId), 1)) ?> hours first.
        <strong>This is an estimate.</strong> An invoice is the only record of what was really
        billed, which is why it is kept apart from the two figures above it.
      </p>
      <table>
        <thead><tr><th>Who</th><th class="num">Hours</th><th>Rate</th><th class="num">Estimated</th></tr></thead>
        <tbody>
        <?php foreach ($stop['worked']['rows'] as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="num"><?= e(fmt_num($r['hours'], 1)) ?></td>
            <td class="muted small"><?= e(fmt_money_plain($r['rate'], $r['currency'])) ?>
                / <?= e($r['unit']) ?></td>
            <td class="num"><?= e(fmt_money_plain($r['cost'], $r['currency'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($stop['booked']['rows']): ?>
      <h4>Still booked after today</h4>
      <p class="muted small">
        Working days left in each step's window, from today to its deadline, for every named
        person holding a lane on it. Weekends and the holiday calendar are already taken out.
        Placeholders are excluded — an unfilled slot costs nothing yet.
        <strong>This is the plan, not an obligation.</strong>
      </p>
      <table>
        <thead><tr><th>Who</th><th>Step</th><th>Remaining window</th>
                   <th class="num">Days</th><th class="num">At their rate</th></tr></thead>
        <tbody>
        <?php foreach ($stop['booked']['rows'] as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="muted small"><?= e($r['step']) ?></td>
            <td class="muted small"><?= e($r['from']) ?> → <?= e($r['to']) ?></td>
            <td class="num"><?= (int)$r['days'] ?></td>
            <td class="num"><?= $r['cost'] === null
                  ? '<span class="warn-text small">no day rate</span>'
                  : e(fmt_money_plain($r['cost'], $r['currency'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </details>
<?php endif; ?>

<?php
// The phase breakdown. Deliberately built to ADD UP to the forecast above:
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
      <span class="muted small">matches the current forecast cost above</span>
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

<h3 id="budget-form">Approved production budget</h3>
<p class="muted small">
  Typed in here and nowhere else — it is the one figure on this screen that does not come out
  of Anima. Everything beside it on the strip above is computed: the forecast from the pipeline,
  the staffing and the rates, and the invoice figures from the Invoices tab.
</p>
<form method="post" class="money-form">
  <input type="hidden" name="save_budget" value="1">
  <div class="row">
    <?php // Named exactly as the strip names it, because two names for one box
          // is how the client fee came to be typed into it in the first place. ?>
    <label>Approved production budget
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
//   IDEAL    what this project says that work actually takes, per step, on its
//            Days per item on Dates & headcount. Typed from experience, or copied from another
//            project and changed from there.
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
//   step_quota()      applies coverage_pct — 39% of 13 characters is 5.1 items.
//   dashboard_steps() ignores coverage by design (2026-08-12: real N/A markings
//                     beat the coverage estimate) and counts from TODAY.
//
// The dashboard's reading is right for a show in flight, where items have been
// marked N/A and what matters is the pace from here. It is wrong for THIS table,
// which asks whether the plan as drawn can be delivered — where coverage is the
// only statement of scope there is. Counting all 13 characters through a step
// that touches 5 overstates the required pace by two and a half times.
//
// ⚠ AND THE WINDOW IS THE STEP'S OWN DATES, START TO DEADLINE, passed to
// step_quota() as its "today". Left to itself step_quota() counts from today once
// a step has started, and that is a different question. David, 2026-09-17: on its
// deadline day the Animatic read 0.5 a day here, the whole deliverable over the one
// day left, while Dates & headcount read 0.13, the same deliverable over the four
// working days it was planned in, and the verdict called a finished plan 50% over
// the ideal. The planning window is what Dates & headcount has used since
// 2026-09-01, for the reason written at `var from` in assets/wizard.js: a plan must
// not rot while you look at it. A step with no start date is counted from today,
// exactly as that screen counts it.
//
// So this table and Dates & headcount give the same minimum for every step, by
// construction. "Can we still make it from today" is the dashboard's question.
$quotaRows = [];
$quotaOver = 0;
try {
    $qEstimates = project_estimates($pdo, $projectId);
    $qRetake    = project_retake_pct($pdo, $projectId);

    foreach (pipeline_steps($pdo, $projectId) as $st) {
        $planFrom = !empty($st['start_date']) ? (string)$st['start_date'] : null;
        $q = step_quota($pdo, $projectId, $st, $planFrom, $qEstimates, $qRetake);
        // Measured from its own start, a deadline before the start is not one that
        // "has already passed", which is how step_quota() words it. Said as it is.
        if ($planFrom !== null && !empty($st['deadline']) && $st['deadline'] < $planFrom) {
            array_unshift($q['problems'], 'The deadline is before the start date.');
        }

        // The number the budget below is built on, from its one reader: this
        // project's own ideal since 2026-09-17. Every step has one, so every step
        // is a row; until then only steps the type sheet covered were.
        $idealDays    = effective_days_per_unit($pdo, $projectId, $st);
        $idealPerDay  = $idealDays > 0 ? 1 / $idealDays : null;
        $minPerArtist = isset($q['per_artist_day']) && $q['per_artist_day'] !== null
                      ? (float)$q['per_artist_day'] : null;

        $over = ($minPerArtist !== null && $idealPerDay !== null && $idealPerDay > 0)
              ? $minPerArtist / $idealPerDay : null;
        if ($over !== null && $over > 1.05) $quotaOver++;

        $quotaRows[] = [
            'q' => $q, 'step' => $st,
            'ideal_per_day' => $idealPerDay,
            'min_per_artist' => $minPerArtist,
            'over' => $over,
        ];
    }
} catch (Throwable $e) {
    $quotaRows = [];
}
?>

<h3>Minimum against ideal</h3>

<p class="muted small">
  Two numbers from opposite directions. <strong>Minimum</strong> is what the plan demands of one
  person per day: the step's work spread over the working days from its start to its deadline,
  divided by the people on it, the same figure Dates &amp; headcount shows. It is arithmetic and
  <strong>not editable</strong>. <strong>Ideal</strong> is what this project says that work actually
  takes, its days per item on
  <a href="wizard.php?project=<?= $projectId ?>&amp;step=6">Dates &amp; headcount</a>.
</p>

<?php if (!$quotaRows): ?>
  <div class="empty-state">
    <p>This project has no pipeline steps yet, so there is nothing to compare.</p>
  </div>
<?php else: ?>

  <?php if ($quotaOver > 0): ?>
    <div class="flash flash-err">
      <strong><?= $quotaOver ?> step<?= $quotaOver === 1 ? '' : 's' ?></strong>
      <?= $quotaOver === 1 ? 'demands' : 'demand' ?> a pace above this project's ideal. That is not
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
      <col style="width:10%"> <?php // Work ?>
      <col style="width:8%">  <?php // People ?>
      <col style="width:12%"> <?php // Minimum / person / day ?>
      <col style="width:12%"> <?php // Ideal / person / day ?>
      <col style="width:30%"> <?php // Verdict ?>
    </colgroup>
    <thead>
      <tr>
        <th>Step</th><th>Covers</th>
        <th class="num">Work</th>
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
            <?php // Whatever stopped the pace being worked out: no deadline, no
                  // people, a deadline before the start. Said as the quota says it,
                  // not as one guess that was only right for the first of those. ?>
            <span class="muted"><?= e((string)($qq['problems'][0] ?? 'There is no pace to compare yet.')) ?></span>
          <?php elseif ($over > 1.05): ?>
            <span class="err">Asks for <?= e(fmt_num(($over - 1) * 100, 0)) ?>% more than the ideal.</span>
          <?php elseif ($over < 0.95): ?>
            <span class="ok">Comfortable, <?= e(fmt_num((1 - $over) * 100, 0)) ?>% under the ideal.</span>
          <?php else: ?>
            <span class="muted">Right at the ideal.</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted small">
    The minimum here is the one on
    <a href="wizard.php?project=<?= $projectId ?>&amp;step=6">Dates &amp; headcount</a>, over each
    step's own dates, so the two cannot disagree. The
    <a href="dashboard.php?project=<?= $projectId ?>">production dashboard</a> asks the other
    question, what the work still left needs from today, and on a step that is running late that
    is the number that goes red first.
  </p>

<?php endif; ?>

<?php
// WHERE THE DAYS CAME FROM, said in one line rather than left to be worked out
// from the per-row marks below. Since 2026-09-17 every row is costed from this
// project's own ideal quotas. Before database update 034 the old rule stands: the
// type sheet's standard, falling back to what this project was planned at.
$onIdeal    = (int)($proj['rows_on_ideal'] ?? 0);
$onEstimate = (int)($proj['rows_on_estimate'] ?? 0);
?>
<?php if (ideals_per_project($pdo)): ?>
  <p class="muted small ok">
    Every row below is costed from this project's own days per item, set on
    <a href="wizard.php?project=<?= $projectId ?>&amp;step=6">Dates &amp; headcount</a>, which no other project shares.
  </p>
<?php elseif (!($proj['standards_ready'] ?? false)): ?>
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
    <a href="wizard.php?project=<?= $projectId ?>&amp;step=6">See the days per item →</a>
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
  <br><strong>Many at once:</strong> tick rows (or a whole department, or a whole role), type one
  rate in the bar and apply. If the ticks include real people you are asked first, because of the
  rule above.
</p>

<?php if (!$proj['rows']): ?>
  <p class="muted">No pipeline steps yet.</p>
<?php else: ?>
  <?php // BULK RATES. Its own form, outside the table, because every row already
        // carries a single-rate form and forms cannot nest. The tick boxes in
        // the table join this form through their form="" attribute. Roles are
        // listed once each, in the order the table draws them.
        $bulkRoles = [];
        foreach ($proj['rows'] as $r) {
            if ($r['crew_id']) $bulkRoles[(string)$r['role']] = true;
        } ?>
  <form method="post" id="bulk-rates" class="bulk-rates">
    <input type="hidden" name="bulk_rates" value="1">
    <input type="hidden" name="confirm_people" value="0">
    <strong>Set a rate for many rows</strong>
    <span class="muted small" id="bulk-count">0 selected</span>
    <label class="small">Full departments selection
      <select id="bulk-role">
        <option value="">choose…</option>
        <?php foreach (array_keys($bulkRoles) as $role): ?>
          <option value="<?= e($role) ?>"><?= e($role) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="button" class="secondary" id="bulk-clear">Unselect all</button>
    <span class="bulk-apply">
      <input name="day_rate" inputmode="decimal" size="6" placeholder="rate" aria-label="Rate for the selected rows">
      <button type="submit">Apply to selection</button>
    </span>
    <span class="muted small">Each row keeps its own currency and unit (day or second).</span>
  </form>

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
        <label class="bulk-tick" title="Tick every row in <?= e($dept) ?>">
          <input type="checkbox" class="bulk-dept-all" data-dept="<?= e($dept) ?>"
                 aria-label="Tick every row in <?= e($dept) ?>"></label>
        <span class="group-badge"><?= e(strtoupper($dept)) ?></span>
        <span class="muted small"><?= count($group['rows']) ?> people ·
          <?= e(fmt_num($group['days'], 1)) ?> days</span>
      </td></tr>

      <?php foreach ($group['rows'] as $r): ?>
        <tr>
          <td class="wf-name">
            <?php if ($r['crew_id']): ?>
              <input type="checkbox" class="bulk-row" form="bulk-rates" name="crew_ids[]"
                     value="<?= (int)$r['crew_id'] ?>" data-dept="<?= e($dept) ?>"
                     data-role="<?= e((string)$r['role']) ?>" data-slot="<?= $r['is_slot'] ? '1' : '0' ?>"
                     data-name="<?= e((string)$r['artist']) ?>"
                     aria-label="Tick <?= e((string)$r['artist']) ?> for a bulk rate">
            <?php endif; ?>
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
          <td class="num" title="Rounded up to whole days, from <?= e(fmt_num((float)($r['days_exact'] ?? $r['days']), 2)) ?>"><?= e(fmt_num($r['days'], 2)) ?>
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

  <script>
  // Bulk rates: ticking by department or role, the running count, and the
  // warning before a real person's rate is rewritten on every show. The server
  // refuses people without confirm_people=1, so this is the ask, not the guard.
  (function () {
    var form = document.getElementById('bulk-rates');
    if (!form) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('.bulk-row'));
    var count = document.getElementById('bulk-count');
    function refresh() {
      var n = rows.filter(function (b) { return b.checked; }).length;
      count.textContent = n + ' selected';
      document.querySelectorAll('.bulk-dept-all').forEach(function (all) {
        var mine = rows.filter(function (b) { return b.dataset.dept === all.dataset.dept; });
        var on = mine.filter(function (b) { return b.checked; }).length;
        all.checked = mine.length > 0 && on === mine.length;
        all.indeterminate = on > 0 && on < mine.length;
      });
    }
    rows.forEach(function (b) { b.addEventListener('change', refresh); });
    document.querySelectorAll('.bulk-dept-all').forEach(function (all) {
      all.addEventListener('change', function () {
        rows.forEach(function (b) { if (b.dataset.dept === all.dataset.dept) { b.checked = all.checked; } });
        refresh();
      });
    });
    document.getElementById('bulk-role').addEventListener('change', function () {
      var role = this.value;
      if (role !== '') {
        rows.forEach(function (b) { if (b.dataset.role === role) { b.checked = true; } });
      }
      this.value = '';
      refresh();
    });
    document.getElementById('bulk-clear').addEventListener('click', function () {
      rows.forEach(function (b) { b.checked = false; });
      refresh();
    });
    form.addEventListener('submit', function (ev) {
      var picked = rows.filter(function (b) { return b.checked; });
      if (!picked.length) { ev.preventDefault(); alert('Tick at least one row first.'); return; }
      if (form.day_rate.value.trim() === '') { ev.preventDefault(); alert('Type the rate to apply.'); return; }
      var people = [];
      picked.forEach(function (b) {
        if (b.dataset.slot !== '1' && people.indexOf(b.dataset.name) < 0) { people.push(b.dataset.name); }
      });
      form.confirm_people.value = '0';
      if (people.length) {
        if (!confirm('These are real people, not slots:\n\n  ' + people.join('\n  ')
            + '\n\nA person\'s rate is theirs everywhere, so this changes it on every show '
            + 'they are booked on, not just this one. Apply anyway?')) {
          ev.preventDefault();
          return;
        }
        form.confirm_people.value = '1';
      }
    });
    refresh();
  }());
  </script>

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
  Current forecast cost and Budget variance at the top of this page.
  <br>
  <strong>An invoice is a person billing you; an expense is everything that is not somebody's
  time.</strong> Put a freelancer's bill in Invoices below and a vendor's in here, and the two can
  never double-count the same money.
</p>

<?php if (expenses_need_upgrade()): ?>
  <div class="flash flash-warn">
    Other expenses need <code>finance_install_05.sql</code>, which hasn't been imported into the
    finance database yet. Until it is, this section cannot record anything, and
    <strong>Current forecast cost and Budget variance above are people only</strong>.
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
        <?php // Notes as a quiet line under the name, the way the expense table
              // shows its own, rather than a tenth column: they are rare, can
              // run to a sentence, and a column would be empty on most rows and
              // squeeze the dates on the rest. They were always saved by the form
              // below; until 2026-10-23 this table simply never printed them, so
              // a note typed in was only ever seen again in phpMyAdmin. ?>
        <td><?= e($iv['crew_name'] ?: '—') ?>
          <?php if (!empty($iv['notes'])): ?>
            <div class="muted small"><?= e($iv['notes']) ?></div>
          <?php endif; ?>
        </td>
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
    <?php // ⚠ THE TOTAL ROW THIS TABLE NEVER HAD. Until 2026-10-06 answering
          // "what have we invoiced on this show" meant adding the column up by
          // hand, which is slow and, on a long list, wrong. Split paid from
          // unpaid because those are two different facts, and kept per currency
          // because nothing here converts between them. ?>
    <tfoot>
      <?php foreach (array_unique(array_merge(array_keys($stop['paid']), array_keys($stop['unpaid']))) as $c): ?>
        <tr>
          <th colspan="4">Total<?= count($stop['currencies']) > 1 ? ' in ' . e($c) : '' ?></th>
          <th class="num"><?= e(fmt_money_plain(
                ($stop['paid'][$c] ?? 0) + ($stop['unpaid'][$c] ?? 0), $c)) ?></th>
          <th colspan="2"><?= e(fmt_money_plain($stop['paid'][$c] ?? 0, $c)) ?> paid</th>
          <th colspan="2"><?= ($stop['unpaid'][$c] ?? 0) > 0
                  ? '<span class="warn-text">' . e(fmt_money_plain($stop['unpaid'][$c], $c)) . ' outstanding</span>'
                  : 'nothing outstanding' ?></th>
        </tr>
      <?php endforeach; ?>
    </tfoot>
  </table>
  <?php if ($stop['mixed']): ?>
    <p class="muted small">
      One total per currency, never added together — nothing here converts between them.
    </p>
  <?php endif; ?>
<?php else: ?>
  <p class="muted">Nothing recorded yet.</p>
<?php endif; ?>

<h3>Record invoices</h3>

<p class="muted small">
  One row per invoice, five at a time, a month's invoices arrive as a batch, and one form submit
  each was six page loads to do one afternoon's bookkeeping. Only the <strong>amount</strong> is
  required; blank rows are ignored. A row with details but no amount is <em>not</em> ignored, it
  is reported, because that is somebody who was interrupted mid-typing rather than a row nobody
  wanted. <strong>Notes</strong> is the last box on the right (scroll the grid sideways); whatever
  goes there shows under the name in the list above.
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
            <td><input name="<?= $n ?>[notes]" size="18" placeholder="Shown under the name above"></td>
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

<?php // ---- BUDGET POOL ----------------------------------------------------
      //
      // David, 2026-10-16: the show in days rather than money, so a producer can
      // steer by the pool draining. Everything is computed in budget_pool(); this
      // block only draws it, which is the same split every other tab here keeps.
      ?>
<?php if ($tab === 'pool'): ?>
<?php $pool = budget_pool($pdo, $project); $pcur = $pool['currency']; ?>

<h3>The pool <span class="muted">— the show in days, not money</span></h3>

<?php if (!$pool['days']['rows'] && !$pool['seconds']['rows']): ?>
  <?php // ⚠ AN EMPTY SCREEN HAS TO SAY WHY. Three different things produce no
        // pool at all, and "nothing here" would send somebody hunting through
        // four screens to find out which. ?>
  <div class="flash flash-warn">
    <p><strong>There is no pool to show yet.</strong> It is worked out from each step's
      headcount and its dates, so it needs both:</p>
    <ul class="small">
      <li>somebody standing in each step — set on
        <a href="crew.php?project=<?= $projectId ?>">Staffing</a>, slots count as well as people;</li>
      <li>a start date and a deadline on the step — set on
        <a href="production.php?project=<?= $projectId ?>">Dates &amp; headcount</a>.</li>
    </ul>
    <p class="small">Nothing is typed in here. Fix those and this screen fills itself in.</p>
  </div>
<?php else: ?>

<div class="dash-strip">
  <div><span class="muted small">Day pool</span>
    <strong><?= e(fmt_num($pool['days']['pool'], 1)) ?> days</strong>
    <span class="muted small">headcount &times; each step's window</span></div>
  <div><span class="muted small">Used</span>
    <strong><?= e(fmt_num($pool['days']['used'], 1)) ?> days</strong>
    <?php // ⚠ THE SOURCE IS ON THE FIGURE, not in a footnote. Two very
          // different numbers can sit here — what the plan says has been
          // consumed, and what somebody actually recorded — and a reader who
          // does not know which is looking at is worse off than one who has no
          // number at all. ?>
    <span class="<?= $pool['source'] === 'plan' ? 'warn-text' : 'muted' ?> small">
      <?= $pool['source'] === 'plan' ? 'from the plan, not logged hours' : 'from logged hours' ?></span></div>
  <div><span class="muted small">Unused</span>
    <strong class="<?= $pool['days']['left'] < 0 ? 'err' : '' ?>">
      <?= e(fmt_num(abs($pool['days']['left']), 1)) ?> days</strong>
    <span class="<?= $pool['days']['left'] < 0 ? 'err' : 'muted' ?> small">
      <?= $pool['days']['left'] < 0 ? 'OVERDRAWN'
          : e(fmt_num(100 - $pool['days']['pct_used'], 0)) . '% of the pool' ?></span></div>
  <div><span class="muted small">Work done</span>
    <strong><?= e(fmt_num($pool['work']['done'], 0)) ?> of <?= e(fmt_num($pool['work']['total'], 0)) ?></strong>
    <span class="muted small">shots and assets</span></div>
  <div><span class="muted small">Pace</span>
    <strong class="<?= e($pool['days']['pace_class']) ?>">
      <?= e(fmt_num($pool['days']['pct_used'], 0)) ?>% / <?= e(fmt_num($pool['work']['pct'], 0)) ?>%</strong>
    <span class="<?= e($pool['days']['pace_class']) ?> small"><?= e($pool['days']['pace_text']) ?></span></div>
</div>

<?php // THE WHOLE SHOW AS ONE DONUT, used against unused (David, 2026-10-21:
      // "show the remaining days unused", and hovering says how many days). ?>
<div class="pool-detail pool-overall">
  <?= pool_days_donut_svg((float)$pool['days']['used'], (float)$pool['days']['pool']) ?>
  <div>
    <p><strong><?= e(fmt_num($pool['days']['used'], 1)) ?> days used</strong>
      <span class="muted">of <?= e(fmt_num($pool['days']['pool'], 1)) ?></span></p>
    <p class="<?= $pool['days']['left'] < 0 ? 'err' : 'muted' ?>">
      <?= $pool['days']['left'] < 0
          ? e(fmt_num(abs($pool['days']['left']), 1)) . ' days over the pool'
          : e(fmt_num($pool['days']['left'], 1)) . ' days still unused' ?></p>
    <p class="muted small">Point at the donut to see each part in days.</p>
  </div>
</div>

<p class="muted small">
  Every figure above is in <strong>days</strong>; money appears only inside a step, next to the
  rate that converts it. The pool is <em>not typed in</em> — it is each step's headcount
  multiplied by the working days between its start date and its deadline, so a date changed on
  <a href="production.php?project=<?= $projectId ?>">Dates &amp; headcount</a> moves this screen
  with it.
  <?php if ($pool['source'] === 'plan'): ?>
    Used days come from that same window up to today, because nothing has been logged yet:
    a slot that started three weeks ago has consumed those person-days whether or not anybody
    recorded them, which is just as true of the money. The moment hours are logged — by hand or
    with the timer on <a href="mytasks.php">My tasks</a> — this switches to them and says so.
  <?php else: ?>
    Used days are real logged hours divided by this project's
    <?= e(fmt_num($pool['hours_per_day'], 1)) ?>-hour day.
  <?php endif; ?>
</p>

<?php if ($pool['undated']): ?>
  <?php // ⚠ NAMED, NOT DROPPED. A step with people on it but no dates cannot be
        // measured, and leaving it out silently makes the show look smaller and
        // healthier than it is — the exact shape of failure Lesson 11 records. ?>
  <div class="flash flash-warn">
    <strong><?= count($pool['undated']) ?>
      step<?= count($pool['undated']) === 1 ? '' : 's' ?> could not be counted</strong>
    because <?= count($pool['undated']) === 1 ? 'it has' : 'they have' ?> people assigned but no
    start date or deadline: <?= e(implode(', ', $pool['undated'])) ?>.
    The totals above are missing <?= count($pool['undated']) === 1 ? 'it' : 'them' ?>.
    <a href="production.php?project=<?= $projectId ?>">Set the dates</a> and
    <?= count($pool['undated']) === 1 ? 'it joins' : 'they join' ?> the pool.
  </div>
<?php endif; ?>

<?php if ($pool['days']['rows']): ?>
<h3>By pipeline step</h3>
<div class="step-cards">
  <?php foreach ($pool['days']['rows'] as $r): ?>
    <?php
    // The bar carries the step's own colour until the comparison has something
    // to say, then it carries the verdict — the same rule the production
    // dashboard's cards follow, so the two read as one family.
    $bar = $r['pace_class'] === 'ok' ? 'var(--ok)'
         : ($r['pace_class'] === 'err' ? 'var(--danger)'
            : ($r['color'] ?: 'var(--step-default)'));
    ?>
    <article class="step-card">
      <div class="step-card-head">
        <h4><a href="?project=<?= $projectId ?>&amp;tab=pool&amp;step=<?= (int)$r['id'] ?>#step-<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></h4>
        <span class="unit-badge"><?= (int)$r['slots'] ?> slot<?= $r['slots'] === 1 ? '' : 's' ?></span>
      </div>
      <div class="mini-bar"><span style="width:<?= e(fmt_num(min(100, $r['pct_used']), 1)) ?>%;background:<?= e($bar) ?>"></span></div>
      <div class="mini-num"><?= e(fmt_num($r['used'], 1)) ?><span class="mini-total">/<?= e(fmt_num($r['pool'], 1)) ?> days</span></div>
      <div class="muted small">
        <?= $r['left'] < 0 ? e(fmt_num(abs($r['left']), 1)) . ' days over'
                           : e(fmt_num($r['left'], 1)) . ' days unused' ?>
        <?php if ($r['rate_min'] !== null): ?>
          · <?= $r['rate_same']
                ? e(fmt_money_plain($r['rate_min'], $pcur)) . ' a day'
                : e(fmt_money_plain($r['rate_min'], $pcur)) . '—' . e(fmt_money_plain($r['rate_max'], $pcur)) . ' a day' ?>
        <?php endif; ?>
      </div>
      <div class="mini-pace">
        <div><strong><?= e(fmt_unit($r['done'], $r['unit'])) ?></strong>
          of <?= e(fmt_unit($r['total'], $r['unit'])) ?> done</div>
        <div class="<?= e($r['pace_class']) ?> small"><?= e($r['pace_text']) ?></div>
        <?php // ⚠ UNDERSTAFFING LOOKS LIKE UNDERSPENDING, and this is the line
              // that stops it reading as good news. The pool counts the slots the
              // plan plans for; only people who exist burn it. ?>
        <?php if ($r['filled'] < $r['slots']): ?>
          <div class="warn-text small"><?= (int)$r['filled'] ?> of <?= (int)$r['slots'] ?> slots filled
            — the pool assumes <?= (int)$r['slots'] ?></div>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pool['seconds']['rows']): ?>
  <h3>Paid by the second <span class="muted">a separate pool, in seconds</span></h3>
  <p class="muted small">
    ⚠ These add nothing to the day pool above and take nothing from it — a second is not a day,
    and the two are never summed. They carry no pace line either: a per-second artist is paid for
    seconds <em>approved</em>, so what has been spent and what has been delivered are the same
    measurement, and comparing them would always say "level".
  </p>
  <div class="step-cards">
    <?php foreach ($pool['seconds']['rows'] as $r): ?>
      <article class="step-card">
        <div class="step-card-head">
          <h4><?= e($r['name']) ?></h4>
          <span class="unit-badge">per second</span>
        </div>
        <div class="mini-bar"><span style="width:<?= e(fmt_num(min(100, $r['pct_used']), 1)) ?>%;background:<?= e($r['color'] ?: 'var(--step-default)') ?>"></span></div>
        <div class="mini-num"><?= e(fmt_num($r['used'], 3)) ?><span class="mini-total">/<?= e(fmt_num($r['pool'], 3)) ?>s approved</span></div>
        <div class="muted small">
          <?= e(fmt_num($r['left'], 3)) ?>s to go
          <?php if ($r['rate'] !== null): ?>
            · <?= e(fmt_money_plain($r['rate'], $pcur)) ?> a second
            · <?= e(fmt_money_plain($r['rate'] * $r['pool'], $pcur)) ?> the lot
          <?php endif; ?>
        </div>
        <?php if (!$r['tracks_seconds']): ?>
          <div class="warn-text small">This step is tracked in items, not seconds, so the figures
            above are item counts. Set it to track seconds for a real seconds pool.</div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="muted small">
    <?= e(fmt_num($pool['seconds']['used'], 3)) ?>s approved of
    <?= e(fmt_num($pool['seconds']['pool'], 3)) ?>s across
    <?= count($pool['seconds']['rows']) ?> step<?= count($pool['seconds']['rows']) === 1 ? '' : 's' ?>.
  </p>
<?php endif; ?>

<?php
// ---- one step in detail ----------------------------------------------------
// Reached by clicking a card. Rendered underneath rather than on its own page:
// the comparison that matters is between this step and the pool it came out of,
// and a separate page would put them a navigation apart.
$openId = (int)($_GET['step'] ?? 0);
$open = null;
foreach ($pool['days']['rows'] as $r) if ((int)$r['id'] === $openId) $open = $r;
?>
<?php if ($open): ?>
  <h3 id="step-<?= (int)$open['id'] ?>"><?= e($open['name']) ?>
    <span class="muted">— the detail behind the card</span></h3>
  <div class="dash-strip">
    <div><span class="muted small">Rate on this step</span>
      <strong><?= $open['rate_min'] === null ? '—'
            : e(fmt_money_plain($open['rate_min'], $pcur)) ?></strong>
      <?php // Which steps already agree on one rate is the map of how far the
            // studio is from the standard rate card David wants. Said per step,
            // because that is where it will be set. ?>
      <?php if ($open['rate_min'] === null): ?>
        <span class="warn-text small">no rate on file for anybody here</span>
      <?php elseif ($open['rate_same']): ?>
        <span class="ok small">every slot agrees — already standard</span>
      <?php else: ?>
        <span class="warn-text small">slots differ: <?= e(fmt_money_plain($open['rate_min'], $pcur)) ?>
          to <?= e(fmt_money_plain($open['rate_max'], $pcur)) ?></span>
      <?php endif; ?></div>
    <div><span class="muted small">Pool in days</span>
      <strong><?= e(fmt_num($open['pool'], 1)) ?> days</strong>
      <span class="muted small"><?= (int)$open['slots'] ?> slot<?= $open['slots'] === 1 ? '' : 's' ?> across its window</span></div>
    <div><span class="muted small">Pool in money</span>
      <strong><?= $open['rate_min'] === null ? '—'
            : e(fmt_money_plain($open['pool'] * $open['rate_min'], $pcur)) ?></strong>
      <span class="muted small">at the rate beside it</span></div>
    <div><span class="muted small">Used</span>
      <strong><?= e(fmt_num($open['used'], 1)) ?> days</strong>
      <span class="muted small"><?= $pool['source'] === 'plan' ? 'from the plan' : 'from logged hours' ?></span></div>
    <div><span class="muted small">Used in money</span>
      <strong><?= $open['rate_min'] === null ? '—'
            : e(fmt_money_plain($open['used'] * $open['rate_min'], $pcur)) ?></strong>
      <span class="muted small">same rate, so it tracks the days</span></div>
    <div><span class="muted small">Unused</span>
      <strong class="<?= $open['left'] < 0 ? 'err' : '' ?>"><?= e(fmt_num(abs($open['left']), 1)) ?> days</strong>
      <span class="<?= $open['left'] < 0 ? 'err' : 'muted' ?> small">
        <?= $open['left'] < 0 ? 'OVERDRAWN' : e(fmt_num(100 - $open['pct_used'], 0)) . '% still to use' ?></span></div>
  </div>

  <?php // TWO DONUTS ON ONE LINE (David, 2026-10-21): the days, used against
        // unused, with what they say beside them; then the step's shots or assets
        // by status, "to the right of the days used", with its colour key beside
        // it rather than under it. Both rings sit on the same line, captioned the
        // same way, so they read as a pair. Both answer on hover. ?>
  <?php $spread = pool_status_slices($pdo, $projectId, (int)$open['id']); ?>
  <div class="pool-detail pool-pair">
    <figure class="pool-fig">
      <?= pool_days_donut_svg((float)$open['used'], (float)$open['pool']) ?>
      <figcaption class="muted small">Days, used and unused</figcaption>
    </figure>
    <div class="pool-pair-text">
      <p class="<?= e($open['pace_class']) ?>"><strong><?= e($open['pace_text']) ?></strong></p>
      <p class="muted small">
        <?= e(fmt_unit($open['done'], $open['unit'])) ?> of
        <?= e(fmt_unit($open['total'], $open['unit'])) ?> approved.
        <?php if ($open['filled'] < $open['slots']): ?>
          Only <?= (int)$open['filled'] ?> of <?= (int)$open['slots'] ?> slots are filled, so the
          days are being used more slowly than the plan assumes — this step is not under budget,
          it is under-staffed.
        <?php endif; ?>
      </p>
      <p class="muted small">
        <a href="step.php?project=<?= $projectId ?>&amp;step=<?= (int)$open['id'] ?>">Open the step</a>
        · <a href="?project=<?= $projectId ?>&amp;tab=pool">close this</a>
      </p>
    </div>
    <div class="pool-status">
      <figure class="pool-fig">
        <?= pool_donut_svg($spread['slices'], (string)$spread['total'], $spread['noun']) ?>
        <figcaption class="muted small">By status</figcaption>
      </figure>
      <?php if ($spread['slices']): ?>
        <ul class="pool-legend">
          <?php foreach ($spread['slices'] as $sl): ?>
            <li><span class="dot" style="background:<?= e($sl['color']) ?>"></span><?= e($sl['small']) ?>
              <span class="muted"><?= e($sl['big']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <?php // WHO IS ON IT, AND HOW EACH ONE STANDS (David, 2026-10-21: "a list to show
        // who is working on it and how much has been spent on that person vs.
        // budget, so management can spot who is over/under budget at a glance").
        // Worst first; the reading column carries the verdict in words and colour.
        // pool_step_people() says where every figure comes from. ?>
  <?php $who = pool_step_people($pdo, $project, (int)$open['id'], $pool['source']);
        $wcur = $who['currency']; ?>
  <h4>Who is on it <span class="muted small">— each person against their share of the pool</span></h4>
  <?php if (!$who['rows']): ?>
    <p class="muted small">Nobody paid by the day is on this step.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="pool-people">
      <thead><tr>
        <th>Person</th><th class="num">Rate</th>
        <th class="num">Budget</th><th class="num">Used</th><th class="num">Unused</th>
        <th class="num">Invoiced</th><th class="num">Work done</th><th>Reading</th>
      </tr></thead>
      <tbody>
      <?php foreach ($who['rows'] as $p): ?>
        <tr class="<?= $p['reading_class'] === 'err' ? 'pool-over' : '' ?>">
          <td><?php if ($p['is_slot']): ?>
                <span class="muted"><?= e($p['name']) ?></span> <span class="unit-badge">slot</span>
              <?php else: ?>
                <a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$p['crew_id'] ?>"><?= e($p['name']) ?></a>
              <?php endif; ?></td>
          <td class="num"><?= $p['rate'] !== null ? e(fmt_money_plain($p['rate'], $wcur)) . '<span class="muted small"> / day</span>'
                                                  : '<span class="warn-text small">no rate</span>' ?></td>
          <td class="num"><?= e(fmt_num($p['budget_days'], 1)) ?> d
            <?php if ($p['budget_money'] !== null): ?><div class="muted small"><?= e(fmt_money_plain($p['budget_money'], $wcur)) ?></div><?php endif; ?></td>
          <td class="num <?= $p['reading_class'] === 'err' ? 'err' : '' ?>"><?= e(fmt_num($p['used_days'], 1)) ?> d
            <?php if ($p['used_money'] !== null): ?><div class="muted small"><?= e(fmt_money_plain($p['used_money'], $wcur)) ?></div><?php endif; ?></td>
          <td class="num <?= $p['left_days'] < 0 ? 'err' : '' ?>"><?= $p['left_days'] < 0 ? '−' : '' ?><?= e(fmt_num(abs($p['left_days']), 1)) ?> d
            <?php if ($p['left_money'] !== null): ?><div class="muted small"><?= $p['left_money'] < 0 ? '−' : '' ?><?= e(fmt_money_plain(abs($p['left_money']), $wcur)) ?></div><?php endif; ?></td>
          <td class="num"><?= $p['invoiced'] !== null ? e(fmt_money_plain($p['invoiced'], $wcur)) : '<span class="muted">—</span>' ?></td>
          <td class="num"><?= $p['items'] > 0 ? (int)$p['done'] . ' of ' . (int)$p['items'] : '<span class="muted">—</span>' ?></td>
          <td class="<?= e($p['reading_class']) ?> small"><?= e($p['reading']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php $tt = $who['totals']; ?>
      <tfoot><tr>
        <th>All</th><th></th>
        <th class="num"><?= e(fmt_num($tt['budget_days'], 1)) ?> d
          <?php if ($tt['budget_money'] > 0): ?><div class="muted small"><?= e(fmt_money_plain($tt['budget_money'], $wcur)) ?><?= $tt['priced'] ? '' : ' +' ?></div><?php endif; ?></th>
        <th class="num"><?= e(fmt_num($tt['used_days'], 1)) ?> d
          <?php if ($tt['used_money'] > 0): ?><div class="muted small"><?= e(fmt_money_plain($tt['used_money'], $wcur)) ?><?= $tt['priced'] ? '' : ' +' ?></div><?php endif; ?></th>
        <th class="num"><?= e(fmt_num($tt['budget_days'] - $tt['used_days'], 1)) ?> d</th>
        <th class="num"><?= $tt['invoiced'] > 0 ? e(fmt_money_plain($tt['invoiced'], $wcur)) : '' ?></th>
        <th></th><th></th>
      </tr></tfoot>
    </table>
    </div>
    <p class="muted small">
      Budget is each person's slot window in working days, times their day rate. Used is
      <?= $pool['source'] === 'plan' ? 'the plan up to today, because no time has been logged on this show yet'
                                    : 'the days they logged on this step' ?>.
      Invoiced is what they have billed against this position.
      <?php if (!$tt['priced']): ?>A "+" on a total means somebody here has no day rate, so money is missing from it.<?php endif; ?>
      People paid by the second are left out, because they have no day budget.
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
<script>
  // The pool donuts answer on hover: the middle of the ring shows the part under
  // the pointer, in days or items, and goes back to its own figure on leaving.
  // Each segment's <title> carries the same words for touch and screen readers.
  (function () {
    function say(svg, big, small) {
      var b = svg.querySelector('.pool-donut-big'), s = svg.querySelector('.pool-donut-small');
      if (b) b.textContent = big !== null ? big : b.getAttribute('data-rest');
      if (s) s.textContent = small !== null ? small : s.getAttribute('data-rest');
    }
    Array.prototype.forEach.call(document.querySelectorAll('.pool-donut'), function (svg) {
      Array.prototype.forEach.call(svg.querySelectorAll('.pool-seg'), function (seg) {
        seg.addEventListener('mouseenter', function () {
          say(svg, seg.getAttribute('data-big'), seg.getAttribute('data-small'));
        });
        seg.addEventListener('mouseleave', function () { say(svg, null, null); });
      });
    });
  }());
</script>
<?php endif; ?>
<?php // ---- end BUDGET POOL ------------------------------------------------ ?>

</div>
</body></html>
