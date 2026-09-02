<?php
// Historic quotas — what every show was planned at, kept per project.
//
// David, 2026-09-01: "I'll need from time to time, to check the records and see
// what we used to charge certain slots or quotas."
//
// The data has been accumulating since migration 013 and had nowhere to be
// read. capture_baseline() writes a row per step every time somebody records a
// baseline on Dates & headcount; until now the only thing that ever read those
// rows back was baseline_drift(), which compares a project against ITSELF.
//
// STUDIO-WIDE and READ-ONLY. A baseline is a record of what was believed at a
// moment, so nothing on this screen edits one — re-baselining a project is done
// on that project's own planning screen, deliberately, with a reason attached.
//
// NOT BEHIND can_see_money(). A quota is production data — days per item, how
// many people, how much work — and carries no rate. The fees half of this pair
// is admin-only for exactly the opposite reason.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/history.php';
require_login();

$ready = history_quotas_available($pdo);
$q     = trim((string)($_GET['q'] ?? ''));
$view  = ($_GET['view'] ?? 'project') === 'step' ? 'step' : 'project';

// THREE MULTIPLE-CHOICE FILTERS (David, 2026-09-02: several shows at once, the
// project type, and a step). Each arrives as an array of checkbox values, and
// each is cast on the way in rather than trusted: everything below builds SQL
// from it.
//
// ⚠ NOTHING TICKED MEANS EVERYTHING, in all three. A set of empty checkboxes
// that showed an empty page would look broken on arrival, and "all" is the
// honest reading of "I have not narrowed this yet".
$selProjects = array_values(array_filter(array_map('intval', (array)($_GET['project'] ?? [])),
                                         fn($i) => $i > 0));
$selTypes    = array_values(array_filter(array_map('strval', (array)($_GET['type'] ?? [])),
                                         fn($t) => $t !== ''));
$selSteps    = array_values(array_filter(array_map('strval', (array)($_GET['step'] ?? [])),
                                         fn($t) => $t !== ''));
$filtered    = $q !== '' || $selProjects || $selTypes || $selSteps;

$batches  = $ready ? quota_history($pdo, $q, $selProjects, $selTypes, $selSteps) : [];
$byStep   = $view === 'step' ? quota_history_by_step($batches) : [];
$projects = history_projects($pdo);
$allSteps = $ready ? history_steps($pdo) : [];

// How many baselines each project has, so the picker can say which shows have
// anything to show rather than letting somebody pick an empty one.
$counts = [];
if ($ready) {
    foreach ($pdo->query('SELECT project_id, COUNT(*) n FROM step_estimate_baseline GROUP BY project_id')
                 ->fetchAll() as $c) {
        $counts[(int)$c['project_id']] = (int)$c['n'];
    }
}

// The types that actually have records behind them, so the filter cannot offer
// a choice that returns nothing.
$typeOptions = [];
foreach ($projects as $pr) {
    if (($counts[(int)$pr['id']] ?? 0) > 0 && ($pr['type'] ?? '') !== '') {
        $typeOptions[(string)$pr['type']] = true;
    }
}
$typeOptions = array_keys($typeOptions);
sort($typeOptions);

require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Historic quotas</h2>

<?php if (!$ready): ?>
  <div class="flash flash-err">
    This screen needs migration <strong>013</strong>, which creates the baseline table.
    <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?>
  </div>
<?php else: ?>

<form method="get" class="inline-form filter-bar">
  <input name="q" value="<?= e($q) ?>" size="26" placeholder="Search step, project or reason">
  <?php // Submits on change, like the role picker and the status dropdown. ?>
  <select name="view" onchange="this.form.submit()">
    <option value="project" <?= $view === 'project' ? 'selected' : '' ?>>By project</option>
    <option value="step"    <?= $view === 'step'    ? 'selected' : '' ?>>By step, across shows</option>
  </select>
  <button class="button secondary" type="submit">Search</button>
  <?php if ($filtered): ?>
    <a class="button secondary" href="history_quotas.php">Clear</a>
  <?php endif; ?>

  <?php // ---- the three tick-lists ------------------------------------------
        // Checkboxes rather than a dropdown, because the question is "these
        // three shows against each other", which a single-choice control cannot
        // ask at all. They submit on change like every other filter in the app.
        //
        // label.inline is the existing idiom and it is load-bearing: the global
        // `input, select { width: 100% }` would otherwise stretch every
        // checkbox across its own line. ?>
  <div class="filter-checks">
    <span class="fc-label">Shows</span>
    <?php foreach ($projects as $p): $n = $counts[(int)$p['id']] ?? 0; ?>
      <?php // A show with no baseline is still SHOWN, disabled. Hiding it would
            // leave somebody wondering where their project went; this says it
            // exists and has nothing recorded yet. ?>
      <label class="inline <?= $n === 0 ? 'muted' : '' ?>">
        <input type="checkbox" name="project[]" value="<?= (int)$p['id'] ?>"
               <?= in_array((int)$p['id'], $selProjects, true) ? 'checked' : '' ?>
               <?= $n === 0 ? 'disabled' : '' ?>
               onchange="this.form.submit()">
        <?= e($p['code']) ?>
        <span class="muted small"><?= $n === 0 ? 'no baseline yet' : '(' . $n . ')' ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <?php // Only when there is more than one, since a filter that can only be set
        // one way is not a choice. ?>
  <?php if (count($typeOptions) > 1): ?>
    <div class="filter-checks">
      <span class="fc-label">Type</span>
      <?php foreach ($typeOptions as $t): ?>
        <label class="inline">
          <input type="checkbox" name="type[]" value="<?= e($t) ?>"
                 <?= in_array($t, $selTypes, true) ? 'checked' : '' ?>
                 onchange="this.form.submit()">
          <?= e($t) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($allSteps): ?>
    <?php // FOLDED, unlike the other two. A studio has a handful of shows and
          // two or three project types, but one baseline per step per capture
          // means this list is as long as the pipeline, several times over.
          // Open it and it stays open while you tick, because ticking reloads
          // the page and the [open] attribute is rendered from the state. ?>
    <details class="filter-fold" <?= $selSteps ? 'open' : '' ?>>
      <summary class="row-btn">Steps<?= $selSteps
            ? ': ' . count($selSteps) . ' of ' . count($allSteps) : ': all' ?></summary>
      <div class="filter-checks">
        <?php foreach ($allSteps as $st): $key = step_identity_key($st); ?>
          <label class="inline">
            <input type="checkbox" name="step[]" value="<?= e($key) ?>"
                   <?= in_array($key, $selSteps, true) ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <?= e($st['step_name']) ?>
            <span class="muted small"><?= e(step_scope_tag($st)) ?> (<?= (int)$st['n'] ?>)</span>
          </label>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
</form>

<?php
// ⚠ ONE HEADER AND ONE ROW, DRAWN BY BOTH VIEWS (David, 2026-09-02:
// "let's homogenize the columns when checking by project and by step, across
// shows"). The two views differ in ONE cell, which thing is being named, and
// are identical after it.
//
// Written as functions rather than as two tables that happen to match, because
// two tables that happen to match stop matching the first time a column is
// added to one of them. That is exactly how they came to be different in the
// first place: one grew "Coverage" and "Dates" and the other did not.
//
// Both read the RAW baseline row, which is why quota_history_by_step() now
// carries the whole row instead of a renamed subset of it.

/** The column headings. $first names whatever the identity column holds. */
function quota_head(string $first): void { ?>
  <thead><tr>
    <th><?= e($first) ?></th>
    <th class="num">Asset/shot count</th>
    <th class="num">Days per item</th>
    <?php // THE TWO QUOTAS, from opposite directions. Explained on the heading
          // rather than in a paragraph above the table, because the paragraph
          // is the thing David asked to take away. ?>
    <th class="num" title="Deliveries per person per day at the days per item this plan was priced at. This is the rate the expense projection costs the work with.">Ideal / day</th>
    <th class="num" title="What the dates demanded of one person per day: the work in this step spread over the working days between its start and its deadline, divided by the people planned on it. A minimum above the ideal means the plan asked for a pace the studio does not believe in.">Minimum / day</th>
    <th class="num">Coverage</th>
    <th class="num">People</th>
    <th class="num">Retakes</th>
    <th>Dates</th>
  </tr></thead>
<?php }

/**
 * One baseline row. $identity is the finished HTML of the first cell, the only
 * thing the two views disagree about.
 */
function quota_row(array $r, string $identity): void {
    $q = baseline_quota($r);
    $d = (float)$r['days_per_unit'];
    // Over the ideal is the finding, so it is the one thing on the row that
    // carries a colour. Under it is not praise, and is left plain.
    $over = ($q['minimum'] !== null && $q['ideal'] !== null && $q['ideal'] > 0)
          ? $q['minimum'] / $q['ideal'] : null;
    ?>
    <tr>
      <td><?= $identity ?></td>
      <td class="num"><?= e(fmt_num((float)$r['work_units'], 1)) ?>
          <span class="muted small"><?= e($r['work_label']) ?></span>
          <?php if ((int)$r['units_estimated'] === 1): ?><span class="est-tag">est.</span><?php endif; ?></td>
      <td class="num"><strong><?= e(fmt_num($d, 3)) ?></strong></td>
      <td class="num"><?= $q['ideal'] === null ? '<span class="muted">&mdash;</span>'
            : e(fmt_num($q['ideal'], 2)) ?></td>
      <td class="num<?= $over !== null && $over > 1.05 ? ' err' : '' ?>"
          <?php if ($over !== null): ?>title="<?= e(fmt_num($over * 100, 0)) ?>% of the ideal pace"<?php endif; ?>>
        <?php // Null is not zero. A step with no dates, no work or no headcount
              // recorded cannot have a minimum, and a printed 0 would read as
              // "nothing was asked of anybody". ?>
        <?= $q['minimum'] === null ? '<span class="muted">&mdash;</span>'
            : e(fmt_num($q['minimum'], 2)) ?>
      </td>
      <td class="num"><?= e(fmt_num((float)$r['coverage_pct'], 0)) ?>%</td>
      <td class="num"><?= $r['planned_headcount'] === null
            ? '<span class="muted">&mdash;</span>' : e(fmt_num((float)$r['planned_headcount'], 2)) ?></td>
      <td class="num muted"><?= e(fmt_num((float)$r['retake_pct'], 1)) ?>%</td>
      <td class="small muted">
        <?= $r['start_date'] ? e($r['start_date']) : '&mdash;' ?> &rarr;
        <?= $r['deadline'] ? e($r['deadline']) : '&mdash;' ?>
      </td>
    </tr>
<?php }
?>

<?php if (!$batches): ?>
  <p class="muted">
    <?= $filtered
        ? 'Nothing matches that.'
        : 'No baseline has been recorded yet. One is written every time somebody presses '
          . '<em>Record today\'s estimates as a new baseline</em> on a project\'s Dates &amp; headcount screen.' ?>
  </p>

<?php elseif ($view === 'step'): ?>
  <?php // ---- across shows ----------------------------------------------------
        // The question a per-project list cannot answer: what do we NORMALLY
        // allow for this step? Grouped on the same identity the baseline index
        // uses, so "Concept · Character" never averages with "Concept ·
        // Environment" — different jobs that happen to share a name. ?>
  <?php foreach ($byStep as $label => $rows): ?>
    <?php
      $days = array_map('floatval', array_column($rows, 'days_per_unit'));
      sort($days);
      $n = count($days);
      $mid = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;
      // ⚠ WHICH KINDS OF SHOW THIS TYPICAL FIGURE IS MADE OF. A 2D day and a 3D
      // day are not the same day, so a median over both is an average of two
      // things that were never comparable. The block is not split on type,
      // because sometimes the mix is exactly what you want to see, but it says
      // so rather than letting the number be read as one studio norm. The Type
      // filter above is the way to separate them.
      $blockTypes = array_values(array_unique(array_filter(array_column($rows, 'project_type'))));
      sort($blockTypes);
    ?>
    <h3 class="history-head"><?= e($label) ?>
      <span class="muted small">
        <?= $n ?> capture<?= $n === 1 ? '' : 's' ?> ·
        typically <strong><?= e(fmt_num($mid, 3)) ?></strong> d/item
        <?php if (count($blockTypes) === 1): ?>
          · <?= e($blockTypes[0]) ?>
        <?php elseif (count($blockTypes) > 1): ?>
          · <span class="warn-text">mixes <?= e(implode(' and ', $blockTypes)) ?></span>
        <?php endif; ?>
        <?php if ($n > 1 && $days[0] !== $days[$n - 1]): ?>
          · <?= e(fmt_num($days[0], 3)) ?>–<?= e(fmt_num($days[$n - 1], 3)) ?>
        <?php endif; ?>
      </span>
    </h3>
    <table class="grid-table history-table">
      <?php quota_head('Project'); ?>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php // The capture date sits UNDER the project code, where the other
                // view puts the step's scope tag, so every column after the
                // first lines up between the two. ?>
          <?php quota_row($r, sprintf(
                '<a href="wizard.php?project=%d&amp;step=4">%s</a>%s<div class="muted small">%s</div>',
                (int)$r['project_id'], e($r['project_code']),
                $r['project_type'] ? ' <span class="type-tag">' . e($r['project_type']) . '</span>' : '',
                e(substr((string)$r['captured_at'], 0, 10)))); ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

<?php else: ?>
  <?php // ---- per project, the way it was asked for --------------------------- ?>
  <?php foreach ($batches as $b): ?>
    <h3 class="history-head">
      <?php // Straight to the screen that produced this record — step 4 is Dates
            // & headcount, where a new capture is taken. ?>
      <a href="wizard.php?project=<?= $b['project_id'] ?>&amp;step=4"><?= e($b['project_code']) ?></a>
      <span class="muted"><?= e($b['project_name']) ?></span>
      <?php if (!empty($b['project_type'])): ?>
        <span class="type-tag"><?= e($b['project_type']) ?></span>
      <?php endif; ?>
      <span class="status status-<?= e($b['project_status']) ?>"><?=
            e(str_replace('_', ' ', $b['project_status'])) ?></span>
      <span class="muted small">
        · captured <?= e(substr($b['captured_at'], 0, 16)) ?> · <?= e($b['reason']) ?>
        · <?= count($b['rows']) ?> step<?= count($b['rows']) === 1 ? '' : 's' ?>
      </span>
    </h3>
    <table class="grid-table history-table">
      <?php quota_head('Step'); ?>
      <tbody>
        <?php foreach ($b['rows'] as $r): ?>
          <?php quota_row($r, sprintf('<strong>%s</strong><div class="muted small">%s</div>',
                e($r['step_name']), e(step_scope_tag($r)))); ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

</div>
</body></html>
