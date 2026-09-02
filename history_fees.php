<?php
// Historic fees — what each position on each show has cost, by department and
// pipeline step.
//
// David, 2026-09-01: "I'll need from time to time, to check the records and see
// what we used to charge certain slots or quotas."
//
// ADMIN ONLY. Every figure here is a rate, and rates live in the finance
// database behind includes/money.php — the one place that connection is opened.
//
// ⚠ READ THE NOTE ON THE PAGE BEFORE TRUSTING A NUMBER. Only some of this is
// genuinely history:
//
//   a SLOT's rate    is history. A placeholder is created for one project and
//                    dies with it, so the rate on "Rough Animation 01" is what
//                    that show budgeted for that seat, permanently.
//   an INVOICE       is history. It records a payment, on a date, for a
//                    position — the only ground truth on this screen.
//   a PERSON's rate  is NOT. crew_terms is keyed on crew_id alone, with no
//                    project and no date, so a named artist shows what they
//                    cost TODAY on every show they have ever been on.
//
// That last one cannot be fixed by presenting it differently, so the screen
// says it plainly and marks which rows are which. Inventing a per-project rate
// column to "fix" it would be a schema change that quietly rewrites what the
// existing rows mean.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/finance.php';
require_once __DIR__ . '/includes/history.php';
require_admin();

$q         = trim((string)($_GET['q'] ?? ''));
$proj      = (int)($_GET['project'] ?? 0);
$slotsOnly = !empty($_GET['slots']);

$configured = finance_configured();
$dbError = null;
$groups  = [];
if ($configured) {
    try {
        $groups = fee_history($pdo, $q, $proj, $slotsOnly);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
$projects = history_projects($pdo);

require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Historic fees</h2>

<?php if (!$configured): ?>
  <div class="flash flash-warn">
    <p><strong>The finance database isn't set up yet.</strong></p>
    <p class="small">Rates live in a separate database with its own login. See
       <code>finance_install.sql</code>.</p>
  </div>
<?php elseif ($dbError): ?>
  <div class="flash flash-err">
    <strong>The finance database could not be read.</strong>
    <div class="small"><?= e($dbError) ?></div>
  </div>
<?php else: ?>

<form method="get" class="inline-form filter-bar">
  <input name="q" value="<?= e($q) ?>" size="26" placeholder="Search step, person, slot or project">
  <select name="project" onchange="this.form.submit()">
    <option value="0">All projects</option>
    <?php foreach ($projects as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $proj === (int)$p['id'] ? 'selected' : '' ?>>
        <?= e($p['code'] . ' · ' . $p['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php // Slots only, because a slot's rate is the one that is genuinely tied to
        // a project — see the note below. This is the filter that turns the
        // screen into an honest historical record. ?>
  <label class="inline">
    <input type="checkbox" name="slots" value="1" onchange="this.form.submit()"
           <?= $slotsOnly ? 'checked' : '' ?>>
    Slots only
  </label>
  <button class="button secondary" type="submit">Search</button>
  <?php if ($q !== '' || $proj > 0 || $slotsOnly): ?>
    <a class="button secondary" href="history_fees.php">Clear</a>
  <?php endif; ?>
</form>

<div class="flash flash-warn">
  <p><strong>Two of these three columns are history. One is not.</strong></p>
  <p class="small">
    A <strong>slot</strong>, <em>Rough Animation 01</em> and the like, belongs to one show and
    dies with it, so its rate is what that show budgeted for that seat and stays true.
    <strong>Invoiced</strong> is what was actually billed against the position, on a date: the
    ground truth. But a <strong>named artist</strong> has one rate for the whole studio, with no
    project and no date attached, so their row shows what they cost <em>today</em> on every show
    they have ever worked on, not what they were paid at the time. Tick <em>Slots only</em> for
    the part of this you can quote from.
  </p>
</div>

<?php if (!$groups): ?>
  <p class="muted">
    <?= $q !== '' || $proj > 0 || $slotsOnly
        ? 'Nothing matches that.'
        : 'No positions have been created yet. They appear once a project has a pipeline with '
          . 'headcount on it.' ?>
  </p>
<?php else: ?>
  <?php // Already in phase order, sorted in fee_history() — see the note there.
        // Steps inside a department keep pipeline order, which is the order the
        // SQL returned them in. ?>
  <?php foreach ($groups as $dept => $steps): ?>
    <h3 class="history-dept"><?= e($dept) ?></h3>
    <?php foreach ($steps as $label => $rows): ?>
      <?php $s = fee_spread($rows); ?>
      <h4 class="history-head"><?= e($label) ?>
        <span class="muted small">
          <?= count($rows) ?> position<?= count($rows) === 1 ? '' : 's' ?>
          <?php if ($s['n'] > 0): ?>
            · typically <strong><?= e(number_format($s['mid'], 2)) ?></strong>
            <?php if ($s['low'] !== $s['high']): ?>
              · <?= e(number_format($s['low'], 2)) ?>–<?= e(number_format($s['high'], 2)) ?>
            <?php endif; ?>
            <?php // The median, and it says so. An average would be dragged
                  // somewhere no real position ever sat by one outlier. ?>
            <span title="The median of <?= (int)$s['n'] ?> priced day rates, not the average">
              (median)</span>
          <?php else: ?>
            · <span class="warn-text">no rates on file</span>
          <?php endif; ?>
        </span>
      </h4>
      <table class="grid-table history-table h-fee">
        <thead><tr>
          <th>Project</th><th>Position</th>
          <th class="num">Rate</th><th class="num">Invoiced</th><th>Ran</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <a href="finance_project.php?project=<?= $r['project_id'] ?>&amp;tab=projection"
                   title="<?= e($r['project_name']) ?>"><?= e($r['project_code']) ?></a>
                <span class="status status-<?= e($r['project_status']) ?>"><?=
                      e(str_replace('_', ' ', $r['project_status'])) ?></span>
              </td>
              <td>
                <a href="person.php?project=<?= $r['project_id'] ?>&amp;crew=<?= $r['crew_id'] ?>"><?=
                      e($r['crew_name']) ?></a>
                <?php if ($r['is_slot']): ?>
                  <span class="slot-tag" title="A placeholder seat on this show only, so its rate is this show's">slot</span>
                <?php else: ?>
                  <span class="muted small" title="One studio-wide rate, this is what they cost today, not at the time">person</span>
                <?php endif; ?>
              </td>
              <td class="num">
                <?php if ($r['rate'] === null): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <?= e(number_format($r['rate'], 2)) ?>
                  <div class="muted small"><?= e($r['currency']) ?> / <?= e($r['rate_unit']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num">
                <?php if ($r['invoiced'] === null): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <strong><?= e(number_format($r['invoiced'], 2)) ?></strong>
                  <div class="muted small"><?= (int)$r['invoice_count'] ?>
                    invoice<?= (int)$r['invoice_count'] === 1 ? '' : 's' ?><?=
                    $r['last_invoice'] ? ' · to ' . e($r['last_invoice']) : '' ?></div>
                <?php endif; ?>
              </td>
              <td class="small muted">
                <?= $r['started'] ? e($r['started']) : '—' ?> →
                <?= $r['delivered'] ? e($r['delivered']) : '—' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

</div>
</body></html>
