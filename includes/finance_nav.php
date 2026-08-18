<?php
// The tab bar for the business side of one project.
//
// Deliberately the same shape as project_nav(), because it answers the same kind
// of question one level along: that bar moves between the screens a show is RUN
// from, this one between the screens it is PAID for. A producer and a production
// accountant look at the same project and need different tabs; giving the
// finance side its own bar is what stops one long page trying to be both.
//
// Defined in one place for the reason project_nav() is: a nav copied into each
// screen is one more place to forget when a screen is added, which is exactly
// how the scheduler ended up reachable from nowhere for an afternoon.

/**
 * The tabs, in the order the money is actually thought about: what was agreed,
 * what it will cost, what has been billed, and who still has to be found.
 */
function finance_nav_items(int $projectId, string $projectType = ''): array {
    $p = '?project=' . $projectId;
    return [
        ['key' => 'overview',   'label' => 'Overview',
         'href' => 'finance_project.php' . $p . '&tab=overview'],
        ['key' => 'projection', 'label' => 'Expense projection',
         'href' => 'finance_project.php' . $p . '&tab=projection'],
        ['key' => 'invoices',   'label' => 'Invoices',
         'href' => 'finance_project.php' . $p . '&tab=invoices'],
        // The SAME screen the project side links to, not a copy of it. Hiring is
        // one conversation whether it is opened from the plan or from the
        // budget, and two copies of it would drift — the money on it is already
        // admin-only inside includes/money.php, so nothing needs hiding here.
        ['key' => 'hiring',     'label' => 'Hiring', 'href' => 'hiring.php' . $p],
        // The IDEAL QUOTA sheet — the number this project's budget is built on.
        //
        // It lives inside Finance management because that is where it is decided
        // (David, 2026-08-17): the production head sets what the work should
        // take, and every cost on the projection is computed from it. Reached
        // from here pre-filtered to THIS project's type, because that is the
        // sheet that priced the budget you are looking at.
        //
        // Studio-wide underneath, so it is not per-project data — but arriving
        // at it from the project whose budget it produced is how somebody
        // actually gets there.
        ['key' => 'standards',  'label' => 'Ideal quotas',
         'href' => 'standards.php' . ($projectType !== '' ? '?type=' . urlencode($projectType) : '')],
    ];
}

/** The tabs this file knows about, for validating a ?tab= value. */
function finance_tabs(): array {
    return ['overview', 'projection', 'invoices'];
}

/** Render the bar. $current is the key of the tab drawing it. */
function finance_nav(array $project, string $current = ''): void {
    $projectId = (int)$project['id'];
    ?>
    <div class="proj-bar">
      <div class="proj-bar-title">
        <a href="finance.php" class="muted">All finance</a>
        <span class="muted">/</span>
        <a href="finance_project.php?project=<?= $projectId ?>"><strong><?= e($project['name']) ?></strong></a>
        <span class="muted">(<?= e($project['code']) ?>)</span>
        <span class="admin-only-tag">administrators only</span>
        <?php // The way back to the production side of the same show. Without it
              // the finance tabs are a cul-de-sac, which is the complaint the
              // "set a rate" links already earned once. ?>
        <span class="muted">·</span>
        <a href="project.php?id=<?= $projectId ?>" class="muted">Open the project →</a>
      </div>
      <nav class="proj-tabs">
        <?php foreach (finance_nav_items($projectId, (string)($project['type'] ?? '')) as $it): ?>
          <a href="<?= e($it['href']) ?>"
             class="proj-tab <?= $it['key'] === $current ? 'current' : '' ?> tab-work"
             <?= $it['key'] === $current ? 'aria-current="page"' : '' ?>><?= e($it['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <?php
}
