<?php
// Ideal quotas: one project's.
//
// David, 2026-09-17: "between projects (even if they're the same type) might have
// different ideal quotas and that will change my budget so this needs to be per
// project. We can start copying another one but if I change something it
// shouldn't affect other projects at all."
//
// Each step's days per item on THIS project: what its schedule, its quotas and its
// budget run on. The same number the Dates & headcount screen shows, stored once
// on the step and editable from either screen. Nothing here reaches another
// project, and nothing on another project reaches here, except a copy somebody
// asks for.
//
// A Finance management tab, because that is where these numbers were put on
// 2026-08-17: the budget is built on them. Until 2026-09-17 this screen was one
// studio-wide sheet per project TYPE, reached from a project but belonging to none.
// See includes/standards.php for what became of those sheets.
//
// ⚠ THIS IS NOT THE REQUIRED QUOTA. The dashboard's "required per day" is derived
// from the deadline and moves whenever the date or the progress moves; nobody
// types it. This is what the work SHOULD take, and the gap between the two is the
// thing worth looking at.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/finance_nav.php';
require_once __DIR__ . '/includes/standards.php';
require_login();

// ?project= like every other project screen. ?from= is what links carried while
// this was a studio-wide sheet that had to be told which project to lead back to,
// and a bookmark may still carry it.
$projectId = (int)($_GET['project'] ?? $_GET['from'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'Ideal quotas belong to each project now. Open them from a project, under Finance '
               . 'management.');
    header('Location: index.php');
    exit;
}

$perProject = ideals_per_project($pdo);
$canEdit = $perProject && is_admin();
$self = 'standards.php?project=' . $projectId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_admin()) {
            throw new RuntimeException('Ideal quotas are set on the business side, so only an '
                                     . 'administrator can change them. Everyone can read them.');
        }
        if (($_POST['action'] ?? '') === 'copy_ideals') {
            copy_ideals_flash(copy_project_ideals($pdo, $projectId, (int)($_POST['copy_from_project'] ?? 0),
                                                  current_user_display()));
        } else {
            $r = save_project_ideals($pdo, $projectId,
                                     is_array($_POST['days'] ?? null) ? $_POST['days'] : [],
                                     is_array($_POST['source'] ?? null) ? $_POST['source'] : [],
                                     current_user_display());
            $n = count($r['changed']);
            if ($n > 0) {
                flash('ok', sprintf('Saved %d ideal quota%s for this project: %s. No other project changed.',
                                    $n, $n === 1 ? '' : 's', ideal_list($r['changed'])));
            }
            if ($r['notes'] > 0) {
                flash('ok', sprintf('Updated where the number came from on %d step%s.',
                                    $r['notes'], $r['notes'] === 1 ? '' : 's'));
            }
            foreach ($r['errors'] as $msg) flash('err', $msg);
            if ($n === 0 && $r['notes'] === 0 && !$r['errors']) {
                flash('ok', 'Nothing had changed, so nothing was saved.');
            }
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $self);
    exit;
}

$steps = pipeline_steps($pdo, $projectId);

$pageTitle = 'Ideal quotas · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php // Inside the project's Finance management bar for administrators, like the
      // other money tabs. Anybody else cannot open Finance management at all, so
      // they get the production bar of the same show rather than a dead end. ?>
<?php if (is_admin()): ?>
  <?php finance_nav($project, 'standards'); ?>
<?php else: ?>
  <?php project_nav($project, ''); ?>
<?php endif; ?>

<h2 class="wizard-title">Ideal quotas <span class="muted">· this project only</span></h2>

<p class="lead">
  What each step of <?= e($project['name']) ?> <strong>should</strong> take when it is done
  properly, as days per item. Its schedule, its quotas and its budget run on these numbers, and
  changing one here changes nothing on any other project.
</p>

<div class="flash flash-warn">
  <strong>This is not the quota on the dashboard.</strong> That one is the <em>minimum</em>: it is
  derived from what is left to do and how many working days remain, so it rises every time a
  deadline gets closer or work slips, and nobody types it. This is the opposite kind of number,
  what a step actually takes, independent of any deadline. Their disagreement is the useful part:
  a required pace far above the ideal is not an ambitious plan, it is one that cannot be delivered
  by the people on it.
</div>

<?php if (!$perProject): ?>
  <div class="flash flash-err">
    <strong>Ideal quotas become per project with database update 034</strong>, which has not been
    applied yet. Until it is, these numbers still come from the <?= e((string)$project['type']) ?>
    sheet shared by every <?= e((string)$project['type']) ?> project, and nothing here can be changed.
    <?= is_admin() ? '<a href="migrate.php">Open the database updates →</a>' : 'Ask an administrator to apply it.' ?>
  </div>
<?php endif; ?>

<?php if (!$steps): ?>
  <div class="empty-state">
    <h2>No pipeline steps yet</h2>
    <p class="muted">Ideal quotas are kept per step.
      <a href="wizard.php?project=<?= $projectId ?>&amp;step=2">Add the pipeline steps first →</a></p>
  </div>
<?php else: ?>

  <?php if ($canEdit): ?>
    <?php project_ideals_copy_form($pdo, $project); ?>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="save">
    <?php
    // ⚠ NO .grid-scroll WRAPPER, which is what keeps the heading sticky: an
    // overflow box becomes the thing `position: sticky` sticks to, and that box
    // never scrolls vertically. The colgroup makes the table fit without one.
    ?>
    <table class="grid-table standards-table">
      <colgroup>
        <col style="width:30%"> <?php // Step ?>
        <col style="width:14%"> <?php // Days per item ?>
        <col style="width:14%"> <?php // Which is ?>
        <col style="width:42%"> <?php // Where the number came from ?>
      </colgroup>
      <thead>
        <tr>
          <th>Step</th>
          <th>Days per item</th>
          <th>Which is</th>
          <th>Where the number came from</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($steps as $s):
        $id = (int)$s['id'];
        $days = effective_days_per_unit($pdo, $projectId, $s);
        $note = $perProject ? ideal_source_note($s)
                            : (string)$project['type'] . ' sheet, until update 034'; ?>
        <tr>
          <?php // Drawn the way Dates & headcount draws a step, colour, name and scope
                // beneath, because the two screens show the same number and should
                // look like they describe the same thing. ?>
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span><?= e($s['name']) ?>
            <div class="muted small step-scope"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?><?= $s['tracks_seconds'] ? ' · per second' : '' ?></div>
          </td>
          <?php if ($canEdit): ?>
            <?php // THREE decimals, the column's own precision: shown rounded, 0.125
                  // would post back as 0.13 and saving the page would rewrite a
                  // number nobody touched. ?>
            <td><input type="number" name="days[<?= $id ?>]" step="any" min="0.001" required
                       value="<?= e(fmt_num($days, 3)) ?>"
                       aria-label="Days per item for <?= e($s['name']) ?>"></td>
            <td class="muted small"><?= e(fmt_num(1 / max(0.001, $days), 2)) ?> a day</td>
            <td><input name="source[<?= $id ?>]" maxlength="120" value="<?= e($note) ?>"
                       placeholder="e.g. measured on FC Mobile"
                       aria-label="Where the number for <?= e($s['name']) ?> came from"></td>
          <?php else: ?>
            <td><strong><?= e(fmt_num($days, 3)) ?></strong></td>
            <td class="muted small"><?= e(fmt_num(1 / max(0.001, $days), 2)) ?> a day</td>
            <td class="muted small"><?= $note !== '' ? e($note) : '&mdash;' ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canEdit): ?>
      <div class="actions"><button type="submit">Save this project's ideal quotas</button></div>
    <?php endif; ?>
  </form>

  <p class="muted small">
    <strong>Days per item</strong> is the number, and the column beside it says the same thing the
    other way round, because "half a day each" and "two a day" are one standard. It is the same
    number as the Days per item box on
    <a href="wizard.php?project=<?= $projectId ?>&amp;step=6">Dates &amp; headcount</a>, editable from
    either screen. <strong>Where the number came from</strong> earns its place: a figure somebody
    guessed and a figure measured on a finished show are both worth having and must never be
    mistaken for each other. Change a number without touching its note, and the note becomes who
    changed it and when.
  </p>

<?php endif; ?>

</div>
</body></html>
