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
$proj  = (int)($_GET['project'] ?? 0);
$view  = ($_GET['view'] ?? 'project') === 'step' ? 'step' : 'project';

$batches  = $ready ? quota_history($pdo, $q, $proj) : [];
$byStep   = $view === 'step' ? quota_history_by_step($batches) : [];
$projects = history_projects($pdo);

// How many baselines each project has, so the picker can say which shows have
// anything to show rather than letting somebody pick an empty one.
$counts = [];
if ($ready) {
    foreach ($pdo->query('SELECT project_id, COUNT(*) n FROM step_estimate_baseline GROUP BY project_id')
                 ->fetchAll() as $c) {
        $counts[(int)$c['project_id']] = (int)$c['n'];
    }
}

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
  <select name="project" onchange="this.form.submit()">
    <option value="0">All projects</option>
    <?php foreach ($projects as $p): $n = $counts[(int)$p['id']] ?? 0; ?>
      <option value="<?= (int)$p['id'] ?>" <?= $proj === (int)$p['id'] ? 'selected' : '' ?>
              <?= $n === 0 ? 'disabled' : '' ?>>
        <?= e($p['code'] . ' · ' . $p['name']) ?><?= $n === 0 ? ', no baseline yet' : ' (' . $n . ')' ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="view" onchange="this.form.submit()">
    <option value="project" <?= $view === 'project' ? 'selected' : '' ?>>By project</option>
    <option value="step"    <?= $view === 'step'    ? 'selected' : '' ?>>By step, across shows</option>
  </select>
  <button class="button secondary" type="submit">Search</button>
  <?php if ($q !== '' || $proj > 0): ?>
    <a class="button secondary" href="history_quotas.php">Clear</a>
  <?php endif; ?>
</form>

<?php // What a baseline IS, said once. Somebody arriving here from the menu has
      // no reason to know the difference between this and the live plan, and a
      // record that gets read as the current plan is worse than no record. ?>
<p class="muted small">
  Each block is one <strong>capture</strong>, every step of a project as it stood the moment
  somebody recorded a baseline on <em>Dates &amp; headcount</em>. Captures are never overwritten,
  so a project that was re-baselined appears more than once and the older rows are what it
  originally committed to. Days per item is what the plan actually ran on: the studio ideal
  where one existed, the project's own estimate where it did not.
</p>

<?php if (!$batches): ?>
  <p class="muted">
    <?= $q !== '' || $proj > 0
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
      $days = array_column($rows, 'days');
      sort($days);
      $n = count($days);
      $mid = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;
    ?>
    <h3 class="history-head"><?= e($label) ?>
      <span class="muted small">
        <?= $n ?> capture<?= $n === 1 ? '' : 's' ?> ·
        typically <strong><?= e(fmt_num($mid, 3)) ?></strong> d/item
        <?php if ($n > 1 && $days[0] !== $days[$n - 1]): ?>
          · <?= e(fmt_num($days[0], 3)) ?>–<?= e(fmt_num($days[$n - 1], 3)) ?>
        <?php endif; ?>
      </span>
    </h3>
    <table class="grid-table history-table h-step">
      <thead><tr>
        <th>Project</th><th>Captured</th>
        <th class="num">Days per item</th><th class="num">Work</th>
        <th class="num">People</th><th class="num">Retakes</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="wizard.php?project=<?= (int)$r['project_id'] ?>&amp;step=4"><?=
                  e($r['project_code']) ?></a></td>
            <td class="muted small"><?= e(substr((string)$r['captured_at'], 0, 10)) ?></td>
            <td class="num"><strong><?= e(fmt_num($r['days'], 3)) ?></strong></td>
            <td class="num"><?= e(fmt_num($r['units'], 1)) ?>
                <span class="muted small"><?= e($r['label']) ?></span>
                <?php if ($r['estimated']): ?><span class="est-tag">est.</span><?php endif; ?></td>
            <td class="num"><?= $r['headcount'] === null ? '—' : e(fmt_num($r['headcount'], 2)) ?></td>
            <td class="num muted"><?= e(fmt_num($r['retake_pct'], 1)) ?>%</td>
          </tr>
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
      <span class="status status-<?= e($b['project_status']) ?>"><?=
            e(str_replace('_', ' ', $b['project_status'])) ?></span>
      <span class="muted small">
        · captured <?= e(substr($b['captured_at'], 0, 16)) ?> · <?= e($b['reason']) ?>
        · <?= count($b['rows']) ?> step<?= count($b['rows']) === 1 ? '' : 's' ?>
      </span>
    </h3>
    <table class="grid-table history-table h-cap">
      <thead><tr>
        <th>Step</th><th class="num">Work</th>
        <th class="num">Days per item</th><th class="num">= per day</th>
        <th class="num">Coverage</th><th class="num">People</th>
        <th class="num">Retakes</th><th>Dates</th>
      </tr></thead>
      <tbody>
        <?php foreach ($b['rows'] as $r): ?>
          <?php $d = (float)$r['days_per_unit']; ?>
          <tr>
            <td><strong><?= e($r['step_name']) ?></strong>
                <div class="muted small"><?= e(step_scope_tag($r)) ?></div></td>
            <td class="num"><?= e(fmt_num((float)$r['work_units'], 1)) ?>
                <span class="muted small"><?= e($r['work_label']) ?></span>
                <?php if ((int)$r['units_estimated'] === 1): ?><span class="est-tag">est.</span><?php endif; ?></td>
            <td class="num"><strong><?= e(fmt_num($d, 3)) ?></strong></td>
            <?php // The same number the other way up. The field is days per item,
                  // so 0.25 reads as "four days" to most people until something
                  // says otherwise — the same reason the planning screen prints it. ?>
            <td class="num muted"><?= $d > 0 ? e(fmt_num(1 / $d, 2)) . ' /day' : '—' ?></td>
            <td class="num"><?= e(fmt_num((float)$r['coverage_pct'], 0)) ?>%</td>
            <td class="num"><?= $r['planned_headcount'] === null
                  ? '<span class="muted">—</span>' : e(fmt_num((float)$r['planned_headcount'], 2)) ?></td>
            <td class="num muted"><?= e(fmt_num((float)$r['retake_pct'], 1)) ?>%</td>
            <td class="small muted">
              <?= $r['start_date'] ? e($r['start_date']) : '—' ?> →
              <?= $r['deadline'] ? e($r['deadline']) : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

</div>
</body></html>
