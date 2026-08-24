<?php
// Finance — budgets, expense projections and invoices, across every project.
//
// ADMIN ONLY. This is the "admin / executive production" screen: the four steps
// of the production cycle that are money (ROADMAP), in one place, reading
// production numbers from this database and money from a separate one whose
// credential ordinary screens do not hold.
//
// The hub: every project as one row, budget against projected cost against what
// has actually been invoiced. finance_project.php is the detail.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/finance.php';
require_admin();

$projects = $pdo->query(
    "SELECT * FROM projects
      ORDER BY FIELD(status, 'active','setup','on_hold','wrapped'), created_at DESC"
)->fetchAll();

$ids = array_map(fn($p) => (int)$p['id'], $projects);
$configured = finance_configured();
$dbError = null;
$budgets = []; $invoiced = [];

if ($configured) {
    try {
        $budgets  = project_budgets($ids);
        $invoiced = invoice_totals($ids);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$pageTitle = 'Finance';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← Projects</a></p>
<h2 class="wizard-title">Finance across all projects <span class="admin-only-tag">administrators only</span></h2>

<?php // A REPORT, not a home (David, 2026-08-17). This screen used to be a
      // top-bar destination, which made it a second list of the same projects
      // competing with index.php — you had to pick a lens before you had picked
      // a show. One project's money is now reached by opening the project and
      // switching to Finance management. What is genuinely only possible here is
      // the comparison ACROSS projects, so that is what it is for. ?>

<?php // NO "Ideal quotas" button here, deliberately (David, 2026-08-17). The
      // sheet is reached from INSIDE a project — a project → Finance management
      // → the Ideal quotas tab — because that is the moment it matters: you are
      // looking at a budget and want to know, or change, the number that priced
      // it. ?>

<p class="lead">
  Budgets, what the plan will cost, and what has been billed. Rates and invoices live in a
  <strong>separate database</strong> whose credential ordinary screens do not hold — so a bug in
  any normal query cannot reach them. Editors and view-only accounts cannot open this page at all.
</p>

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
    <p class="muted">Import <code>finance_install.sql</code> and <code>finance_install_02.sql</code>
       into a separate database with its own MySQL user, then add the <code>finance_db</code>
       block to <code>config.php</code>. Both files document the cPanel steps.</p>
  </div>

<?php else: ?>

<table>
  <thead>
    <tr><th>Project</th><th>Status</th><th class="num">Budget</th><th class="num">Projected cost</th>
        <th class="num">Margin</th><th class="num">Invoiced</th><th class="num">Paid</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($projects as $p): $pid = (int)$p['id'];
    $b = $budgets[$pid] ?? null;
    $inv = $invoiced[$pid] ?? null;
    $budget = $b && $b['budget_amount'] !== null ? (float)$b['budget_amount'] : null;
    $cur = $b['currency'] ?? 'USD';

    // Computed live rather than stored — see includes/finance.php for why.
    //
    // Wrapped per project, because it was not: a missing finance column threw on
    // the FIRST row, and with display_errors off the page simply stopped after
    // the table header. It looked like "there are no projects" rather than "one
    // query failed", which is the most expensive kind of wrong. One bad project
    // now shows its own error and the rest of the table still draws.
    $projError = null; $proj = null; $cost = 0.0;
    try {
        $proj = expense_projection($pdo, $pid);
        // grand_total, not total: labour PLUS other expenses. The project page
        // draws its margin against the same figure, and a hub that disagreed
        // with the page it links to would be worse than no hub.
        $cost = $proj['grand_total'] ?? $proj['total'];
    } catch (Throwable $e) {
        $projError = $e->getMessage();
    }
    $margin = ($budget !== null && $cost > 0) ? $budget - $cost : null;
  ?>
    <tr>
      <td><a href="finance_project.php?project=<?= $pid ?>"><?= e($p['name']) ?></a>
          <small class="muted"><?= e($p['code']) ?></small></td>
      <td><span class="status status-<?= e($p['status']) ?>"><?= e(str_replace('_', ' ', $p['status'])) ?></span></td>
      <td class="num"><?= $budget === null ? '<span class="muted">—</span>' : e(fmt_money_plain($budget, $cur)) ?></td>
      <td class="num">
        <?php if ($projError !== null): ?>
          <span class="err small" title="<?= e($projError) ?>">could not be worked out</span>
        <?php else: ?>
          <?= $cost > 0 ? e(fmt_money_plain($cost, $proj['currency'])) : '<span class="muted">—</span>' ?>
          <?php if ($proj['unpriced_days'] > 0): ?>
            <small class="warn-text"><?= e(fmt_num($proj['unpriced_days'], 1)) ?> days unpriced</small>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td class="num <?= $margin !== null ? ($margin < 0 ? 'err' : 'ok') : '' ?>">
        <?= $margin === null ? '<span class="muted">—</span>' : e(fmt_money_plain($margin, $cur)) ?>
      </td>
      <td class="num"><?= $inv ? e(fmt_money_plain((float)$inv['billed'], $cur)) : '<span class="muted">—</span>' ?></td>
      <td class="num muted"><?= $inv ? e(fmt_money_plain((float)$inv['paid'], $cur)) : '—' ?></td>
      <td class="row-actions"><a href="finance_project.php?project=<?= $pid ?>">Open →</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="muted small">
  <strong>Projected cost is computed every time this page loads</strong>, from the current
  pipeline: person-days per step, the lanes staffed on it, and each person's rate. It is never
  stored, so it cannot go stale when somebody changes a headcount.
  <strong>"Days unpriced"</strong> is the part of the plan held by people with no rate on file —
  usually unfilled slots. Those days are counted and reported rather than treated as free, since
  a total that looks low because half of it is missing is worse than no total at all.
  <strong>Margin</strong> is budget minus projected cost, and means nothing until both exist.
</p>

<?php endif; ?>

</div>
</body></html>
