<?php
// The bar that sits under the page title on every project screen.
//
// Before this, each screen had a single "← back to the project" link, so moving
// between the timeline and the crew list meant going up and back down every
// time. The hub page is still there and still useful as a landing page; this
// makes it optional rather than a toll gate.
//
// Defined in one place because ten pages carrying their own copy of a nav is
// ten places to forget when a screen is added — which is exactly how the
// scheduler ended up reachable from nowhere for an afternoon.

/**
 * The tabs a VIEWER is not offered (David, 2026-09-28, naming all ten).
 *
 * A viewer keeps Overview, Brief, Shots, Production tracking and Tasks — what
 * the show contains and how far it has got. What goes is everything about how it
 * is RUN: the pace, who is on it, when, how they are doing, and every screen for
 * setting up or repairing data.
 *
 * ⚠ THIS HIDES A TAB, IT DOES NOT CLOSE A DOOR. Nothing here refuses the page.
 * A viewer who follows a bookmark, or a link from a screen they still have, still
 * reaches it — project.php's own "See the pace behind this" is one such link, and
 * is hidden alongside these for that reason. If these screens must be genuinely
 * out of reach rather than merely out of the way, the guard belongs in
 * require_login() where the read-only rule already lives, and this list is what
 * it should read so the two can never disagree.
 *
 * Keyed on can_edit() rather than on the role name, so it follows the admin's
 * preview-as-viewer without being taught about it separately.
 */
const PROJECT_TABS_HIDDEN_FROM_VIEWERS = [
    'dashboard',   // Dashboard
    'crew',        // Staffing
    'timeline',    // Timeline
    'schedule',    // Schedule
    'team',        // Team performance
    'setup',       // Setup
    'import',      // Import
    'statuses',    // Statuses
    'aliases',     // Names
    'export',      // Export
];

/** Is this tab, or a link to it, one a viewer should not be shown? */
function hidden_from_viewers(string $key): bool {
    return !can_edit() && in_array($key, PROJECT_TABS_HIDDEN_FROM_VIEWERS, true);
}

/**
 * The tabs, in the order they are used rather than the order they were built.
 *
 * 'group' splits the daily screens from the ones you open occasionally to set
 * something up or fix some data — a flat list of ten reads as ten equally
 * likely destinations, which none of them are.
 *
 * Filtered HERE rather than at the point of drawing, so anything that ever reads
 * this list gets the same answer as the bar does.
 */
function project_nav_items(int $projectId): array {
    $p = '?project=' . $projectId;
    // Ordered by how a day actually runs, settled with David 2026-08-15: where
    // the show stands, who is on it, when things happen, then the two screens
    // work is tracked in. The setup and data-fixing screens follow behind the
    // separator — opened occasionally, not daily.
    $items = [
        ['key' => 'overview',   'label' => 'Overview',            'href' => 'project.php?id=' . $projectId, 'group' => 'work'],
        ['key' => 'brief',      'label' => 'Brief',               'href' => 'brief.php' . $p,              'group' => 'work'],
        ['key' => 'dashboard',  'label' => 'Dashboard',           'href' => 'dashboard.php' . $p,          'group' => 'work'],
        // ONE TAB, TWO VIEWS (David, 2026-09-02: "I feel like they're used for
        // the same thing"). Crew and Hiring were separate tabs and he was
        // right - they are one activity, staffing this show, split along a line
        // that exists in the database rather than in anybody's day. The screens
        // stay separate files; what was a choice of tab is now a choice of view
        // inside one, drawn by crew_view_switch(). Both mark this tab.
        ['key' => 'crew',       'label' => 'Staffing',            'href' => 'crew.php' . $p,               'group' => 'work'],
        ['key' => 'schedule',   'label' => 'Schedule',            'href' => 'schedule.php' . $p,           'group' => 'work'],
        ['key' => 'timeline',   'label' => 'Timeline',            'href' => 'timeline.php' . $p,           'group' => 'work'],
        // Directly before Production tracking, because it is the same list one
        // step earlier: Shots says what is IN each shot, Production tracking says
        // how far each one has got.
        ['key' => 'shots',      'label' => 'Shots',               'href' => 'shots.php' . $p,              'group' => 'work'],
        ['key' => 'production', 'label' => 'Production tracking', 'href' => 'production.php' . $p,         'group' => 'work'],
        ['key' => 'tasks',      'label' => 'Tasks',               'href' => 'tasks.php' . $p,              'group' => 'work'],
        ['key' => 'team',       'label' => 'Team performance',    'href' => 'team.php' . $p,               'group' => 'work'],
        ['key' => 'setup',      'label' => 'Setup',               'href' => 'wizard.php' . $p . '&step=1', 'group' => 'data'],
        ['key' => 'import',     'label' => 'Import',              'href' => 'import.php' . $p,             'group' => 'data'],
        ['key' => 'statuses',   'label' => 'Statuses',            'href' => 'statuses_edit.php' . $p,      'group' => 'data'],
        ['key' => 'aliases',    'label' => 'Names',               'href' => 'aliases.php' . $p,            'group' => 'data'],
        // A download rather than a screen, marked so nobody expects a page.
        ['key' => 'export',     'label' => 'Export ↓',            'href' => 'export.php' . $p,             'group' => 'data'],
    ];

    return array_values(array_filter($items, function ($it) {
        return !hidden_from_viewers($it['key']);
    }));
}

/**
 * The identity line and the SECTION SWITCHER, drawn above the tabs on every
 * screen that belongs to a project — production and finance alike.
 *
 * Settled with David 2026-08-17. The two managements used to be top-bar links,
 * and each led to its own list of the same projects; you had to choose a lens
 * before you had chosen a show. Now the top bar has one way in, and the choice
 * lives here, where you have already said which project you are looking at.
 *
 * Two levels of "you are here", because one bar could not carry both: the
 * switcher says which SIDE of the project you are on (run it / pay for it), the
 * tabs under it say which SCREEN. Both mark themselves.
 *
 * $section is 'project' or 'finance'.
 */
function project_section_nav(array $project, string $section): void {
    $projectId = (int)$project['id'];
    $sections = [
        ['key' => 'project', 'label' => 'Project management',
         'href' => 'project.php?id=' . $projectId],
    ];
    // Finance opens a database ordinary screens hold no credential for — see
    // includes/money.php — so an editor is not offered a door that would only
    // turn them away.
    if (is_admin()) {
        $sections[] = ['key' => 'finance', 'label' => 'Finance management',
                       'href' => 'finance_project.php?project=' . $projectId . '&tab=overview'];
    }
    ?>
    <div class="proj-bar-title">
      <a href="index.php" class="muted">Projects</a>
      <span class="muted">/</span>
      <strong><?= e($project['name']) ?></strong>
      <span class="muted">(<?= e($project['code']) ?>)</span>
      <?php if (isset($project['status'])): ?>
        <span class="status status-<?= e($project['status']) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
      <?php endif; ?>
      <?php if ($section === 'finance'): ?>
        <span class="admin-only-tag">administrators only</span>
      <?php endif; ?>
    </div>
    <?php // Only drawn when there is a choice to make. A viewer sees one
          // section, and a lone highlighted button that leads nowhere is
          // furniture, not navigation. ?>
    <?php if (count($sections) > 1): ?>
      <nav class="proj-sections" aria-label="Project section">
        <?php foreach ($sections as $s): ?>
          <a href="<?= e($s['href']) ?>"
             class="proj-section <?= $s['key'] === $section ? 'current' : '' ?>"
             <?= $s['key'] === $section ? 'aria-current="true"' : '' ?>><?= e($s['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php
}

/**
 * Render the bar. $current is the key of the page drawing it — step.php passes
 * 'dashboard' because it is a drill-down of that screen, not a tab of its own.
 */
function project_nav(array $project, string $current = ''): void {
    $projectId = (int)$project['id'];
    $items = project_nav_items($projectId);
    ?>
    <div class="proj-bar">
      <?php project_section_nav($project, 'project'); ?>
      <nav class="proj-tabs">
        <?php $lastGroup = null; foreach ($items as $it): ?>
          <?php if ($lastGroup !== null && $it['group'] !== $lastGroup): ?>
            <span class="proj-tab-sep" aria-hidden="true"></span>
          <?php endif; $lastGroup = $it['group']; ?>
          <a href="<?= e($it['href']) ?>"
             class="proj-tab <?= $it['key'] === $current ? 'current' : '' ?> tab-<?= e($it['group']) ?>"
             <?= $it['key'] === $current ? 'aria-current="page"' : '' ?>><?= e($it['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <?php
}
