<?php
// Project list — the platform's front door.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

// The values the `projects.status` ENUM allows. Listed once and used by both the
// form and the check that reads it back, because a dropdown offering a value the
// column would reject is a save that fails for no visible reason.
//
// 'archived' arrives with migration 027 and is offered only once that has run —
// the screen keeps working on an un-migrated database instead of writing a value
// the column would refuse.
$canArchive = enum_has_value($pdo, 'projects', 'status', 'archived');
$statuses   = ['setup', 'active', 'on_hold', 'wrapped'];
if ($canArchive) $statuses[] = 'archived';

// Archived projects are OUT of the list rather than greyed in it — the point of
// archiving is that the front door stops showing them. ?archived=1 brings them
// back, and the count below is always drawn so nothing is hidden silently.
$showArchived = isset($_GET['archived']);

// ---------------------------------------------------------------------------
// EDITING A PROJECT FROM THE LIST — status, name, and deletion.
//
// Here rather than on a settings screen because these are the three things you
// want when looking at all the shows at once: this one has wrapped, that one is
// misspelt, that one was a test (David, 2026-08-31).
//
// No read-only check in this block: require_login() refuses a POST from a
// view-only account for every page, so by the time this runs the caller can
// write. See includes/auth.php.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['project_id'] ?? 0);
    try {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) throw new RuntimeException('That project no longer exists.');

        if ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, $statuses, true)) {
                throw new RuntimeException('That is not a status this app recognises.');
            }
            $pdo->prepare('UPDATE projects SET status = ? WHERE id = ?')->execute([$status, $id]);
            flash('ok', sprintf('"%s" is now %s.',
                                $project['name'], str_replace('_', ' ', $status)));

        } elseif ($action === 'rename') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('A project needs a name.');
            if (mb_strlen($name) > 255) {
                throw new RuntimeException('That name is longer than the 255 characters the column holds.');
            }
            // The CODE is deliberately not editable here. It is stamped into
            // exported filenames and spreadsheets that are already out with
            // people, so changing it renames the show's history rather than the
            // show. Rare enough to be worth doing deliberately elsewhere.
            $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash('ok', sprintf('Renamed to "%s".', $name));

        } elseif ($action === 'delete') {
            // ---- THE DESTRUCTIVE ONE -------------------------------------
            //
            // Admin only, and not merely as a matter of seniority: the finance
            // check below runs through can_see_money(), which IS is_admin(). An
            // editor could not verify what they were about to orphan, and a
            // delete that cannot check is a delete that must not happen.
            if (!is_admin()) {
                throw new RuntimeException('Deleting a project is an administrator action.');
            }

            // Typing the code, not clicking OK. A confirm dialog is dismissed by
            // reflex; a code has to be read off the row you meant.
            $typed = trim((string)($_POST['confirm_code'] ?? ''));
            if ($typed === '' || strcasecmp($typed, (string)$project['code']) !== 0) {
                throw new RuntimeException(sprintf(
                    'Nothing was deleted. To delete "%s", type its code — %s — in the box.',
                    $project['name'], $project['code']));
            }

            // Loaded here rather than at the top of the file: finance.php pulls
            // in five more modules, and the front door should not pay for that
            // on every page view to support one rare action.
            require_once __DIR__ . '/includes/finance.php';
            $fin = project_finance_footprint($id);

            if (!$fin['reachable']) {
                throw new RuntimeException(
                    'The finance database is configured but would not open, so there is no way to '
                  . 'tell whether this project has a budget or invoices on that side. Nothing was '
                  . 'deleted — that is the safe answer to a question that could not be asked.');
            }

            if ($fin['total'] > 0) {
                $bits = [];
                if ($fin['budget'])   $bits[] = 'an agreed budget';
                if ($fin['invoices']) $bits[] = $fin['invoices'] . ' invoice'
                                              . ($fin['invoices'] === 1 ? '' : 's');
                if ($fin['expenses']) $bits[] = $fin['expenses'] . ' recorded expense'
                                              . ($fin['expenses'] === 1 ? '' : 's');
                if ($fin['quotes'])   $bits[] = $fin['quotes'] . ' hiring quote'
                                              . ($fin['quotes'] === 1 ? '' : 's');
                throw new RuntimeException(sprintf(
                    '"%s" still has %s in the finance database, which lives on a separate '
                  . 'connection this delete cannot reach. Those rows would outlive the project '
                  . 'and nothing left would explain them. Clear them under Finance management '
                  . 'first, or set the project to archived and keep the record.',
                    $project['name'], implode(', ', $bits)));
            }

            // Everything in the production database goes with it: all twenty
            // tables carrying project_id are ON DELETE CASCADE, so shots,
            // assets, steps, schedule, crew slots, tickets and time logs are
            // removed by the database rather than by a list here that could
            // fall behind the schema.
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            flash('ok', sprintf('Deleted "%s" (%s) and everything recorded against it.',
                                $project['name'], $project['code']));

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: index.php');
    exit;
}

// A literal built from a boolean, never from input.
$whereArchived = ($canArchive && !$showArchived) ? "WHERE p.status <> 'archived'" : '';

$projects = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM shots s WHERE s.project_id = p.id)  AS shot_count,
            (SELECT COUNT(*) FROM assets a WHERE a.project_id = p.id) AS asset_count,
            (SELECT COUNT(*) FROM pipeline_steps ps WHERE ps.project_id = p.id) AS step_count,
            (SELECT COUNT(*) FROM crew_projects cp WHERE cp.project_id = p.id) AS crew_count,
            (SELECT setting_value FROM project_settings st
              WHERE st.project_id = p.id AND st.setting_key = 'wizard_step') AS wizard_step
     FROM projects p
     {$whereArchived}
     ORDER BY FIELD(p.status, 'active','setup','on_hold','wrapped','archived'),
              p.created_at DESC"
)->fetchAll();

// Drawn whether it is zero or not, so "there are none" and "they are hidden" are
// never the same picture.
$archivedCount = $canArchive
    ? (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'archived'")->fetchColumn()
    : 0;

$pageTitle = 'Projects';
require __DIR__ . '/includes/header.php';
?>

<?php // THE one front door (David, 2026-08-17). Finance used to have a rival
      // list of these same projects reached from the top bar, so the first
      // choice the app asked for was one you could not answer yet. Both
      // managements now live INSIDE a project, on the switcher project_nav()
      // and finance_nav() share; what is left here is the portfolio-wide money
      // report, which is a report and not a second home. ?>
<?php // ONE PRIMARY, THREE SECONDARIES (David, 2026-09-02: buttons, not links).
      // Starting a show is the thing this screen is for; the other three are
      // ways of looking at what is already here. Four solid blue buttons would
      // say all four are equally the point, which is how a row of buttons stops
      // meaning anything. ?>
<div class="page-actions button-row">
  <a class="button" href="wizard.php">+ New project</a>
  <a class="button secondary" href="mytasks.php">My tasks</a>
  <?php if (is_admin()): ?>
    <a class="button secondary" href="finance.php">Finance across all projects</a>
  <?php endif; ?>
  <?php // Always drawn once there is anything archived, so a project that has
        // left the list can always be found again. A hidden thing with no way
        // back is indistinguishable from a deleted one. ?>
  <?php if ($archivedCount > 0 || $showArchived): ?>
    <a class="button secondary" href="index.php<?= $showArchived ? '' : '?archived=1' ?>"><?=
      $showArchived
        ? 'Hide archived'
        : 'Show archived (' . (int)$archivedCount . ')' ?></a>
  <?php endif; ?>
</div>

<?php if (!$projects): ?>
  <div class="empty-state">
    <?php // "None" and "all of them are archived" are different sentences, and
          // saying the first when the second is true reads as data loss. ?>
    <?php if ($archivedCount > 0 && !$showArchived): ?>
      <h2>Nothing active</h2>
      <p>Every project is archived. Nothing has been deleted — they are just out of the way.</p>
      <p><a class="button" href="index.php?archived=1">Show the <?= (int)$archivedCount ?> archived</a></p>
    <?php else: ?>
      <h2>No projects yet</h2>
      <p>Set up your first one — it takes about five minutes and drives the schedule,
         the quotas and the reporting from there on.</p>
      <p><a class="button" href="wizard.php">Start a new project</a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <table>
    <tr>
      <th>Code</th><th>Name</th><th>Type</th><th>Status</th>
      <th>Steps</th><th>Shots</th><th>Assets</th><th>Crew</th><th>Delivery</th><th></th>
    </tr>
    <?php foreach ($projects as $p): $pid = (int)$p['id']; ?>
    <tr>
      <td><code><?= e($p['code']) ?></code></td>
      <td><a href="project.php?id=<?= $pid ?>"><?= e($p['name']) ?></a></td>
      <td><?= e($p['type']) ?></td>
      <td>
        <?php // The dropdown KEEPS the badge's colour rather than becoming a
              // plain grey control: the list is read down the status column at a
              // glance, and turning four coloured pills into four identical
              // boxes would cost more than the click it saves. Same
              // submit-on-change pattern as the role picker on users.php. ?>
        <?php if (can_edit()): ?>
          <form method="post" class="status-form">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="project_id" value="<?= $pid ?>">
            <select name="status" onchange="this.form.submit()"
                    class="status-select status-<?= e($p['status']) ?>"
                    title="Change the status of <?= e($p['name']) ?>">
              <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $p['status'] === $s ? 'selected' : '' ?>>
                  <?= e(str_replace('_', ' ', $s)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php else: ?>
          <span class="status status-<?= e($p['status']) ?>"><?= e(str_replace('_', ' ', $p['status'])) ?></span>
        <?php endif; ?>
      </td>
      <td><?= (int)$p['step_count'] ?></td>
      <td><?= (int)$p['shot_count'] ?></td>
      <td><?= (int)$p['asset_count'] ?></td>
      <td><?= (int)$p['crew_count'] ?></td>
      <td><?= e($p['delivery_date'] ?? '—') ?></td>
      <td class="row-actions">
        <?php // A FLEX ROW, not three inline links with a space between them.
              // Buttons touching each other read as one segmented control, and
              // the gap is what says they are three separate decisions. ?>
        <div class="proj-actions">
        <?php if ($p['status'] === 'setup'): ?>
          <a class="row-btn row-btn-go" href="wizard.php?project=<?= $pid ?>&step=<?= max(1, (int)($p['wizard_step'] ?: 1)) ?>">Resume setup</a>
        <?php else: ?>
          <a class="row-btn row-btn-go" href="project.php?id=<?= $pid ?>">Open</a>
          <?php // Straight to the money side of the same show, for the admin who
                // came here to look at a budget. Opening the project first and
                // then switching works too — this saves the hop, it does not
                // replace the switcher. ?>
          <?php if (is_admin()): ?>
            <a class="row-btn" href="finance_project.php?project=<?= $pid ?>&tab=overview">Finance</a>
          <?php endif; ?>
        <?php endif; ?>

        <?php // Renaming and deleting sit behind a disclosure, not out on the
              // row. They are occasional, and one of them is irreversible —
              // putting a delete control next to "Open" is how a row gets
              // removed by a misplaced click. ?>
        <?php if (can_edit()): ?>
          <details class="inline-actions project-edit">
            <summary class="row-btn">Edit</summary>

            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="project_id" value="<?= $pid ?>">
              <input name="name" value="<?= e($p['name']) ?>" size="26" required
                     aria-label="Project name">
              <button type="submit" class="secondary">Rename</button>
            </form>

            <?php if (is_admin()): ?>
              <form method="post" class="inline-form danger-form"
                    onsubmit="return confirm('Delete <?= e(addslashes($p['name'])) ?> and everything recorded against it? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="project_id" value="<?= $pid ?>">
                <input name="confirm_code" size="10" placeholder="<?= e($p['code']) ?>"
                       autocomplete="off" aria-label="Type the project code to confirm deletion">
                <button type="submit" class="danger">Delete this project</button>
                <small class="muted">
                  Type <code><?= e($p['code']) ?></code> to confirm. Removes
                  <?= (int)$p['shot_count'] ?> shots, <?= (int)$p['asset_count'] ?> assets,
                  <?= (int)$p['step_count'] ?> steps and everything tracked against them.
                  Refused while the project still has anything in the finance database —
                  <strong>set the status to archived instead</strong> to take it off this list
                  without destroying anything.
                </small>
              </form>
            <?php endif; ?>
          </details>
        <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</div>
</body></html>
