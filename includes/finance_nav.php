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
        // THE SAME SHOW IN DAYS (David, 2026-10-16), after the three money tabs
        // because it is read second: you come here once the money has raised a
        // question, to find out how many days that question is worth.
        ['key' => 'pool',       'label' => 'Budget pool',
         'href' => 'finance_project.php' . $p . '&tab=pool'],
        // KICK-OFF, after the budget (David, 2026-10-23: "let's add the tab in
        // Finance Management after Budget pool"). What the show was signed off
        // as, against what it is now — which is a money question as often as a
        // production one: the answer to "that is not what we agreed" is on this
        // page, and until now nothing linked to it between a project leaving
        // setup and reaching its postmortem.
        //
        // ⚠ A DIFFERENT PAGE, NOT A finance_project.php TAB, so it carries
        // &section=finance the way the Staffing link does — that is what tells
        // kickoff.php to draw THIS bar, so the tab that was clicked is the tab
        // that lights up when it lands. Without it the page would come back
        // wearing the production bar and the click would look like it had gone
        // somewhere else entirely.
        ['key' => 'kickoff',    'label' => 'Kick-off',
         'href' => 'kickoff.php' . $p . '&section=finance&view=compare'],
        // The SAME screen the project side links to, not a copy of it. Hiring is
        // one conversation whether it is opened from the plan or from the
        // budget, and two copies of it would drift — the money on it is already
        // admin-only inside includes/money.php, so nothing needs hiding here.
        // &section=finance tells that screen which bar to draw, so the tab that
        // was clicked is the tab that lights up when it lands.
        // ⚠ NO HIRING TAB (David, 2026-09-02: "just leave it there but nothing
        // should point to that"). hiring.php still works if its address is
        // typed, and still draws this bar when it is reached with
        // &section=finance, but it is a second list of the positions Staffing
        // already shows and nothing in the app sends anybody to it. What it
        // uniquely holds - quoted rates, contract states, bulk add, stage
        // history - is on the roadmap to be moved or retired.
        // ⚠ NO IDEAL QUOTAS TAB since 2026-10-16 (David chose "Remove it"). It
        // edited the step's days per item, the very number Dates & headcount
        // edits, so it was one value on two screens under two names. The
        // approved-projection match it carried moved to Dates & headcount, and
        // standards.php now only forwards old bookmarks there.
    ];
}

/** The tabs this file knows about, for validating a ?tab= value. */
function finance_tabs(): array {
    return ['overview', 'projection', 'invoices', 'pool'];
}

/** Render the bar. $current is the key of the tab drawing it. */
function finance_nav(array $project, string $current = ''): void {
    $projectId = (int)$project['id'];
    ?>
    <?php // proj-bar-finance tints the lit section and the current tab with the
          // administrators-only red, so the money side of a project is never
          // mistaken at a glance for another production screen. ?>
    <div class="proj-bar proj-bar-finance">
      <?php // The identity line, the crumb home, and the switch back to the
            // production side of the same show all come from the shared
            // renderer in project_nav.php — the finance tabs used to carry
            // their own copy, and an "Open the project →" link tucked at the
            // end of a sentence is a poorer way to say "you are on the money
            // side of this project" than a lit button that says so. ?>
      <?php project_section_nav($project, 'finance', $current); ?>
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
