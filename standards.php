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
require_once __DIR__ . '/includes/ideal_match.php';
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
    $action = (string)($_POST['action'] ?? 'save');
    $to = $self;
    try {
        if (!is_admin()) {
            throw new RuntimeException('Ideal quotas are set on the business side, so only an '
                                     . 'administrator can change them. Everyone can read them.');
        }
        if ($action === 'copy_ideals') {
            copy_ideals_flash(copy_project_ideals($pdo, $projectId, (int)($_POST['copy_from_project'] ?? 0),
                                                  current_user_display()));

        } elseif ($action === 'match_preview') {
            // MATCHING AN APPROVED EXPENSE PROJECTION, step one: read the sheet and
            // hold it for the preview. Nothing is written until match_apply. See
            // includes/ideal_match.php.
            if (!$perProject) {
                throw new RuntimeException('Matching an approved sheet needs database update 034, which '
                                         . 'has not been applied yet, so nothing was read.');
            }
            $f = $_FILES['approved'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose the approved expense projection, an .xlsx file, to match.');
            }
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('That is not an .xlsx file. If it is an older .xls, open it in '
                                         . 'Excel and save it as .xlsx first.');
            }
            $sheet = approved_projection_read((string)$f['tmp_name']);
            // Held in the session rather than re-uploaded: the preview may be
            // adjusted and previewed again before anything is applied, and the
            // file is gone the moment this request ends.
            $_SESSION['ideal_match'][$projectId] = [
                'file'    => basename((string)$f['name']),
                'at'      => time(),
                'title'   => $sheet['title'],
                'total'   => $sheet['total'],
                'roles'   => $sheet['roles'],
                'mapping' => approved_auto_match($sheet['roles'], pipeline_steps($pdo, $projectId)),
            ];
            $to = $self . '&match=1#match';

        } elseif ($action === 'match_remap' || $action === 'match_apply') {
            $held = $_SESSION['ideal_match'][$projectId] ?? null;
            if (!$held || time() - (int)$held['at'] > 3600) {
                unset($_SESSION['ideal_match'][$projectId]);
                throw new RuntimeException('That preview has expired, so nothing was changed. Upload the '
                                         . 'approved sheet again.');
            }
            $mapping = approved_posted_mapping($held['roles'], $_POST['map'] ?? [],
                                               pipeline_steps($pdo, $projectId));
            if ($action === 'match_remap') {
                $_SESSION['ideal_match'][$projectId]['mapping'] = $mapping;
                $to = $self . '&match=1#match';
            } else {
                $proposal = approved_match_proposal($pdo, $projectId, $held['roles'], $mapping);
                $r = approved_match_apply($pdo, $projectId, $proposal, (string)$held['file'],
                                          current_user_display());
                unset($_SESSION['ideal_match'][$projectId]);

                $n = count($r['changed']);
                if ($n > 0) {
                    flash('ok', sprintf('Matched to %s: %d step%s changed, %s. No other project changed.',
                                        $held['file'], $n, $n === 1 ? '' : 's', ideal_list($r['changed'], 30)));
                } else {
                    flash('ok', sprintf('Every matched step already had the days per item %s works out to, '
                                      . 'so nothing changed.', $held['file']));
                }
                if ($r['no_count']) {
                    flash('warn', sprintf('Nothing on this project to count %s by yet (no shots, assets or '
                                        . 'runtime), so %s left as %s.', ideal_list($r['no_count']),
                                          count($r['no_count']) === 1 ? 'it was' : 'they were',
                                          count($r['no_count']) === 1 ? 'it was' : 'they were'));
                }
                $skipped = [];
                foreach ($held['roles'] as $i => $role) {
                    if ($role['rows'] > 0 && (int)($mapping[$i] ?? 0) === 0) $skipped[] = $role['label'];
                }
                if ($skipped) {
                    flash('warn', sprintf('Not matched to any step, so not applied: %s.', ideal_list($skipped)));
                }
                // The figure somebody will check first, said here rather than left
                // to be found on another tab.
                $now = approved_match_rates($pdo, $projectId)['total'];
                if ($now !== null && $held['total'] !== null) {
                    $gap = $now - (float)$held['total'];
                    if (abs($gap) < 0.005) {
                        flash('ok', sprintf('The expense projection now totals %s, the same as the approved sheet.',
                                            number_format($now, 2)));
                    } else {
                        flash('warn', sprintf('The expense projection now totals %s and the approved sheet %s, '
                                            . '%s apart. The days per item match the sheet, so the gap is in '
                                            . 'rates or other expenses.', number_format($now, 2),
                                              number_format((float)$held['total'], 2),
                                              number_format(abs($gap), 2)));
                    }
                }
            }

        } elseif ($action === 'match_cancel') {
            unset($_SESSION['ideal_match'][$projectId]);

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
    header('Location: ' . $to);
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

  <?php
  // An approved expense projection being matched, held from the upload until it
  // is applied or cancelled. An hour is plenty to read a preview; past that the
  // project may have moved on under it.
  $held = $canEdit ? ($_SESSION['ideal_match'][$projectId] ?? null) : null;
  if ($held && time() - (int)$held['at'] > 3600) {
      unset($_SESSION['ideal_match'][$projectId]);
      $held = null;
  }
  $showMatch = $held !== null && isset($_GET['match']);
  ?>

  <?php if ($canEdit): ?>
    <?php project_ideals_copy_form($pdo, $project); ?>

    <?php if (!$showMatch): ?>
      <form method="post" enctype="multipart/form-data" class="inline-form toggle-form ideals-match">
        <input type="hidden" name="action" value="match_preview">
        <label class="inline">Match an approved expense projection
          <input type="file" name="approved" accept=".xlsx" required>
        </label>
        <button type="submit" class="secondary">Preview</button>
        <small>
          Upload the approved sheet, in the shape <em>Download as Excel</em> produces. Each role's
          days are worked back into days per item for its step, using this project's own counts and
          retake allowance, so the projection carries the approved days. Nothing changes until you
          have seen the result and confirmed it.
        </small>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($showMatch):
    $mapping  = $held['mapping'];
    $proposal = approved_match_proposal($pdo, $projectId, $held['roles'], $mapping);
    $rates    = approved_match_rates($pdo, $projectId);
    // Every step offered under the label the platform's own export gives it, which
    // is what a role in the sheet was written against.
    $stepOptions = [];
    foreach ($steps as $st) $stepOptions[(int)$st['id']] = $st['name'] . ' (' . step_scope_tag($st) . ')';
    $rolesByStep = [];
    foreach ($held['roles'] as $i => $role) {
        $sid = (int)($mapping[$i] ?? 0);
        if ($sid > 0) $rolesByStep[$sid][] = $role['label'];
    }
    $factorPct = fmt_num((float)$proposal['retake'], 2);
  ?>
    <section class="match-preview" id="match">
      <h3>Matching <?= e((string)$held['file']) ?></h3>
      <p class="muted small">
        <?= $held['title'] !== '' ? '<strong>' . e((string)$held['title']) . '</strong>. ' : '' ?>
        Days per item are worked back from each role's days with this project's own counts and its
        retake allowance of <strong><?= e($factorPct) ?>%</strong>. If the sheet was built with a
        different allowance, change it on Dates &amp; headcount first and preview again.
        <?php if ($held['total'] !== null): ?>
          Approved total in the sheet: <strong><?= e(number_format((float)$held['total'], 2)) ?></strong>.
        <?php endif; ?>
        <?php if ($rates['total'] !== null): ?>
          This project's expense projection today: <strong><?= e(number_format($rates['total'], 2)) ?></strong>.
        <?php endif; ?>
      </p>

      <form method="post">
        <?php // Enter previews again rather than applying: the first submit button
              // is the one a keypress triggers. ?>
        <button type="submit" name="action" value="match_remap" hidden></button>
        <table class="grid-table match-table">
          <colgroup>
            <col style="width:17%"> <?php // Approved role ?>
            <col style="width:8%">  <?php // Person-days ?>
            <col style="width:21%"> <?php // Step on this project ?>
            <col style="width:12%"> <?php // Counted here ?>
            <col style="width:9%">  <?php // Days per item now ?>
            <col style="width:9%">  <?php // Becomes ?>
            <col style="width:24%"> <?php // Notes ?>
          </colgroup>
          <thead>
            <tr>
              <th>Approved role</th><th class="num">Person-days</th><th>Step on this project</th>
              <th class="num">Counted here</th><th class="num">Days per item now</th>
              <th class="num">Becomes</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($held['roles'] as $i => $role):
            $sid = (int)($mapping[$i] ?? 0);
            $m = $sid > 0 ? ($proposal['steps'][$sid] ?? null) : null;
            $notes = [];
            if ($role['flat']) {
                $notes[] = 'A flat fee: its cost does not depend on days, so it is left alone.';
            } elseif ($role['rows'] === 0) {
                $notes[] = 'No days in the sheet. A fixed cost like this belongs in Other expenses, not in '
                         . 'days per item.';
            } elseif ($m === null) {
                $notes[] = 'No step matched. Pick one, or this role is skipped.';
            } else {
                if ($m['new'] === null) {
                    $notes[] = 'Nothing on this project to count it by yet, so it cannot be worked out.';
                }
                if ($m['sheet_units'] !== null && abs($m['sheet_units'] - $m['units']) >= 0.5) {
                    $notes[] = sprintf('The sheet counts %s, this project %s.', fmt_num($m['sheet_units'], 2),
                                       fmt_num($m['units'], 2));
                }
                if (count($rolesByStep[$sid] ?? []) > 1) {
                    $notes[] = 'Added together with ' . implode(', ', array_diff($rolesByStep[$sid], [$role['label']]))
                             . ', which match the same step.';
                }
                $here = $rates['by_step'][$sid] ?? null;
                if ($here !== null && $role['rates']) {
                    // Either way round: a sheet rate missing here, or a rate here
                    // the sheet never had, both mean the cost will not come out equal.
                    if ($here['day'] && (array_diff($role['rates'], $here['day'])
                                         || array_diff($here['day'], $role['rates']))) {
                        $notes[] = sprintf('Rate in the sheet %s a day, on this project %s a day.',
                                           implode(', ', $role['rates']), implode(', ', $here['day']));
                    }
                    if ($here['unpriced'] > 0) {
                        $notes[] = sprintf('%d position%s on this step %s no rate yet.', $here['unpriced'],
                                           $here['unpriced'] === 1 ? '' : 's', $here['unpriced'] === 1 ? 'has' : 'have');
                    }
                }
            }
            $problem = $role['rows'] > 0 && ($m === null || $m['new'] === null); ?>
            <tr class="<?= $problem ? 'has-problem' : '' ?>">
              <td>
                <?= e($role['label']) ?>
                <?php if ($role['rows'] > 1): ?>
                  <div class="muted small"><?= (int)$role['rows'] ?> artist rows</div>
                <?php endif; ?>
              </td>
              <td class="num"><?= $role['rows'] > 0 ? e(fmt_num($role['days'], 2)) : '<span class="muted">&mdash;</span>' ?></td>
              <td>
                <?php if ($role['rows'] > 0): ?>
                  <select name="map[<?= (int)$i ?>]" aria-label="Step for <?= e($role['label']) ?>">
                    <option value="0">not matched, skip</option>
                    <?php foreach ($stepOptions as $optId => $optLabel): ?>
                      <option value="<?= (int)$optId ?>" <?= $optId === $sid ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <span class="muted small">not days per item</span>
                <?php endif; ?>
              </td>
              <td class="num small">
                <?php if ($m !== null): ?>
                  <?= e(fmt_num($m['units'], 2)) ?> <span class="muted"><?= e($m['unit_label']) ?></span>
                <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
              </td>
              <td class="num"><?= $m !== null ? e(fmt_num($m['was'], 3)) : '<span class="muted">&mdash;</span>' ?></td>
              <td class="num">
                <?php if ($m !== null && $m['new'] !== null): ?>
                  <strong class="<?= abs($m['new'] - $m['was']) >= 0.0005 ? 'match-changes' : '' ?>"><?= e(fmt_num($m['new'], 3)) ?></strong>
                <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
              </td>
              <td class="small"><?= $notes ? e(implode(' ', $notes)) : '<span class="muted">&mdash;</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php
        // Steps no role in the sheet reached. Left exactly as they are, and named,
        // so a step the approved budget never costed is noticed rather than assumed.
        $untouched = [];
        foreach ($steps as $st) {
            if (!isset($proposal['steps'][(int)$st['id']])) $untouched[] = step_display_name($st, $steps);
        }
        $changing = count(array_filter($proposal['steps'],
                                       fn($x) => $x['new'] !== null && abs($x['new'] - $x['was']) >= 0.0005));
        ?>
        <?php if ($untouched): ?>
          <p class="muted small">
            Not in the approved sheet, so left as they are: <?= e(implode(', ', $untouched)) ?>.
          </p>
        <?php endif; ?>

        <div class="actions">
          <button type="submit" name="action" value="match_apply"
                  onclick="return confirm(<?= e(json_encode(sprintf("Set days per item on %d step%s of this project?\n\nIt changes this project's schedule and budget, and nothing on any other project.", $changing, $changing === 1 ? '' : 's'))) ?>);">Set these days per item</button>
          <button type="submit" name="action" value="match_remap" class="secondary">Preview again with these matches</button>
          <button type="submit" name="action" value="match_cancel" class="secondary" formnovalidate>Cancel</button>
        </div>
        <p class="muted small">
          Only days per item change. Rates, positions and other expenses stay as they are: the notes
          point out where they differ from the sheet. Changed a match above? The numbers are worked
          out again from it when you apply, or preview again first to see them.
        </p>
      </form>
    </section>
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
