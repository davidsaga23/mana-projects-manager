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
 * The tabs, in the order they are used rather than the order they were built.
 *
 * 'group' splits the daily screens from the ones you open occasionally to set
 * something up or fix some data — a flat list of ten reads as ten equally
 * likely destinations, which none of them are.
 */
function project_nav_items(int $projectId): array {
    $p = '?project=' . $projectId;
    // Ordered by how a day actually runs, settled with David 2026-08-15: where
    // the show stands, who is on it, when things happen, then the two screens
    // work is tracked in. The setup and data-fixing screens follow behind the
    // separator — opened occasionally, not daily.
    return [
        ['key' => 'overview',   'label' => 'Overview',            'href' => 'project.php?id=' . $projectId, 'group' => 'work'],
        ['key' => 'dashboard',  'label' => 'Dashboard',           'href' => 'dashboard.php' . $p,          'group' => 'work'],
        ['key' => 'crew',       'label' => 'Crew',                'href' => 'crew.php' . $p,               'group' => 'work'],
        ['key' => 'schedule',   'label' => 'Schedule',            'href' => 'schedule.php' . $p,           'group' => 'work'],
        ['key' => 'timeline',   'label' => 'Timeline',            'href' => 'timeline.php' . $p,           'group' => 'work'],
        ['key' => 'production', 'label' => 'Production tracking', 'href' => 'production.php' . $p,         'group' => 'work'],
        ['key' => 'tasks',      'label' => 'Tasks',               'href' => 'tasks.php' . $p,              'group' => 'work'],
        ['key' => 'setup',      'label' => 'Setup',               'href' => 'wizard.php' . $p . '&step=1', 'group' => 'data'],
        ['key' => 'import',     'label' => 'Import',              'href' => 'import.php' . $p,             'group' => 'data'],
        ['key' => 'statuses',   'label' => 'Statuses',            'href' => 'statuses_edit.php' . $p,      'group' => 'data'],
        ['key' => 'aliases',    'label' => 'Names',               'href' => 'aliases.php' . $p,            'group' => 'data'],
        // A download rather than a screen, marked so nobody expects a page.
        ['key' => 'export',     'label' => 'Export ↓',            'href' => 'export.php' . $p,             'group' => 'data'],
    ];
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
      <div class="proj-bar-title">
        <a href="index.php" class="muted">All projects</a>
        <span class="muted">/</span>
        <a href="project.php?id=<?= $projectId ?>"><strong><?= e($project['name']) ?></strong></a>
        <span class="muted">(<?= e($project['code']) ?>)</span>
        <span class="status status-<?= e($project['status']) ?>"><?= e(str_replace('_', ' ', $project['status'])) ?></span>
      </div>
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
