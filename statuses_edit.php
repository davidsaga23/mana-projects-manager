<?php
// Manage a project's statuses.
//
// The team can call a state whatever it likes; "counts as" is what reporting
// reads. Keeping those two separate is what lets a show use its own vocabulary
// without every report needing to know about it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/statuses.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'statuses_edit.php?project=' . $projectId;

// "Not applicable" arrives with the scheduler in migration 010. Until that has
// run the column isn't there, so the control is hidden rather than the screen
// breaking — same reasoning as the role fallback in current_user().
$hasExcluded = column_exists($pdo, 'statuses', 'is_excluded');

// "Show as a column on the tasks board" arrives with the tickets phase in
// migration 017. Same treatment: hide the control rather than break the screen.
$hasBoard = column_exists($pdo, 'statuses', 'on_ticket_board');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'add') {
            $name = trim($_POST['new_name'] ?? '');
            if ($name === '') throw new RuntimeException('Give the new status a name.');
            $mapsTo = in_array($_POST['new_maps_to'] ?? '', ['none','delivered','approved'], true)
                    ? $_POST['new_maps_to'] : 'none';
            $blocked  = !empty($_POST['new_is_blocked'])  ? 1 : 0;
            $excluded = !empty($_POST['new_is_excluded']) ? 1 : 0;
            $onBoard  = !empty($_POST['new_on_board'])    ? 1 : 0;
            $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM statuses WHERE project_id = ?');
            $max->execute([$projectId]);
            try {
                $cols = 'project_id, name, sort_order, maps_to, is_blocked, color'
                      . ($hasExcluded ? ', is_excluded' : '')
                      . ($hasBoard ? ', on_ticket_board' : '');
                $vals = '?, ?, ?, ?, ?, ?' . ($hasExcluded ? ', ?' : '') . ($hasBoard ? ', ?' : '');
                $args = [$projectId, $name, (int)$max->fetchColumn() + 1, $mapsTo, $blocked,
                         trim($_POST['new_color'] ?? '') ?: null];
                if ($hasExcluded) $args[] = $excluded;
                if ($hasBoard)    $args[] = $onBoard;
                $pdo->prepare("INSERT INTO statuses ($cols) VALUES ($vals)")->execute($args);
            } catch (PDOException $e) {
                throw new RuntimeException('This project already has a status called "' . $name . '".');
            }
            flash('ok', 'Added the status "' . $name . '".');
            header('Location: ' . $selfUrl);
            exit;
        }

        if (preg_match('/^del:(\d+)$/', $action, $m)) {
            $id = (int)$m[1];
            $used = $pdo->prepare('SELECT COUNT(*) FROM progress WHERE status_id = ?');
            $used->execute([$id]);
            $n = (int)$used->fetchColumn();
            if ($n > 0) {
                throw new RuntimeException(
                    "That status is in use on $n tracked task" . ($n === 1 ? '' : 's') .
                    '. Change those first, or keep the status.'
                );
            }
            // Board cards point at statuses too. The foreign key is ON DELETE SET
            // NULL, so removing one used by a ticket would not fail — it would
            // leave cards with no status, which the board can only draw in its
            // first column with a warning. Refuse instead, and say how many.
            if (column_exists($pdo, 'tickets', 'status_id')) {
                $usedT = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE status_id = ?');
                $usedT->execute([$id]);
                $nt = (int)$usedT->fetchColumn();
                if ($nt > 0) {
                    throw new RuntimeException(
                        "That status is on $nt card" . ($nt === 1 ? '' : 's') .
                        ' on the tasks board. Move those first, or keep the status.'
                    );
                }
            }
            $nameStmt = $pdo->prepare('SELECT name FROM statuses WHERE id = ? AND project_id = ?');
            $nameStmt->execute([$id, $projectId]);
            $name = $nameStmt->fetchColumn();
            $pdo->prepare('DELETE FROM statuses WHERE id = ? AND project_id = ?')->execute([$id, $projectId]);
            flash('ok', $name ? "Removed the status \"$name\"." : 'That status no longer exists.');
            header('Location: ' . $selfUrl);
            exit;
        }

        // Save all rows.
        $names = $_POST['name'] ?? [];
        $existing = statuses_by_id($pdo, $projectId);
        $upd = $pdo->prepare(
            'UPDATE statuses SET name = ?, maps_to = ?, is_blocked = ?, '
            . ($hasExcluded ? 'is_excluded = ?, ' : '')
            . ($hasBoard ? 'on_ticket_board = ?, ' : '')
            . 'color = ?, sort_order = ?
              WHERE id = ? AND project_id = ?'
        );
        $changed = 0; $order = 0;
        foreach ($names as $id => $name) {
            $id = (int)$id;
            if (!isset($existing[$id])) continue;
            $order++;
            $name = trim($name);
            if ($name === '') {
                flash('warn', 'A status was left with a blank name, so its old name was kept.');
                continue;
            }
            $mapsTo = in_array($_POST['maps_to'][$id] ?? '', ['none','delivered','approved'], true)
                    ? $_POST['maps_to'][$id] : 'none';
            $blocked  = !empty($_POST['is_blocked'][$id])  ? 1 : 0;
            $excluded = !empty($_POST['is_excluded'][$id]) ? 1 : 0;
            $onBoard  = !empty($_POST['on_board'][$id])    ? 1 : 0;
            $color = trim($_POST['color'][$id] ?? '') ?: null;
            try {
                $args = [$name, $mapsTo, $blocked];
                if ($hasExcluded) $args[] = $excluded;
                if ($hasBoard)    $args[] = $onBoard;
                array_push($args, $color, $order, $id, $projectId);
                $upd->execute($args);
                if ($upd->rowCount() > 0) $changed++;
            } catch (PDOException $e) {
                flash('warn', 'Could not rename "' . $existing[$id]['name'] . '" — that name is already used.');
            }
        }

        // Changing what a status means changes what counts as done, so the flags
        // stored on every affected task have to be recomputed. Doing it here
        // rather than at read time keeps reporting queries simple and fast.
        $sync = $pdo->prepare(
            'UPDATE progress pr JOIN statuses s ON s.id = pr.status_id
                SET pr.is_approved  = (s.maps_to = ?),
                    pr.is_delivered = (s.maps_to IN (?, ?))
              WHERE pr.project_id = ?'
        );
        $sync->execute(['approved', 'approved', 'delivered', $projectId]);

        flash('ok', $changed > 0
            ? "Saved $changed status" . ($changed === 1 ? '' : 'es') . '. Progress flags were recalculated.'
            : 'No changes to save.');
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$statuses = project_statuses($pdo, $projectId);
$counts = [];
$stmt = $pdo->prepare('SELECT status_id, COUNT(*) AS n FROM progress WHERE project_id = ? GROUP BY status_id');
$stmt->execute([$projectId]);
foreach ($stmt as $row) $counts[(int)$row['status_id']] = (int)$row['n'];

$pageTitle = 'Statuses · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'statuses'); ?>
<h2 class="wizard-title">Statuses <span class="muted">· <?= e($project['name']) ?></span></h2>

<p class="lead">Call a state whatever your team calls it. <strong>Counts as</strong> is what
   reporting reads, so the numbers keep working whatever the names are.</p>

<form method="post">
  <button type="submit" name="action" value="save" hidden></button>
  <table class="status-table">
    <thead>
      <tr>
        <th>Status</th>
        <th title="What this state means for delivered/approved reporting">Counts as</th>
        <th title="Work that cannot proceed, as opposed to work not yet started">Blocked</th>
        <?php if ($hasExcluded): ?>
          <th title="This item does not pass through this step at all — the scheduler plans no time for it">Not applicable</th>
        <?php endif; ?>
        <?php if ($hasBoard): ?>
          <th title="Show this status as a column on the tasks board">Board column</th>
        <?php endif; ?>
        <th>Colour</th><th>In use</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($statuses as $s): $id = (int)$s['id']; $n = $counts[$id] ?? 0; ?>
      <tr>
        <td><input name="name[<?= $id ?>]" value="<?= e($s['name']) ?>"></td>
        <td>
          <select name="maps_to[<?= $id ?>]">
            <option value="none"      <?= $s['maps_to']==='none'      ? 'selected':'' ?>>nothing yet</option>
            <option value="delivered" <?= $s['maps_to']==='delivered' ? 'selected':'' ?>>delivered</option>
            <option value="approved"  <?= $s['maps_to']==='approved'  ? 'selected':'' ?>>approved</option>
          </select>
        </td>
        <td>
          <input type="hidden" name="is_blocked[<?= $id ?>]" value="0">
          <label class="inline">
            <input type="checkbox" name="is_blocked[<?= $id ?>]" value="1" <?= $s['is_blocked'] ? 'checked' : '' ?>>
            blocked
          </label>
        </td>
        <?php if ($hasExcluded): ?>
        <td>
          <input type="hidden" name="is_excluded[<?= $id ?>]" value="0">
          <label class="inline">
            <input type="checkbox" name="is_excluded[<?= $id ?>]" value="1" <?= !empty($s['is_excluded']) ? 'checked' : '' ?>>
            not applicable
          </label>
        </td>
        <?php endif; ?>
        <?php if ($hasBoard): ?>
        <td>
          <input type="hidden" name="on_board[<?= $id ?>]" value="0">
          <label class="inline">
            <input type="checkbox" name="on_board[<?= $id ?>]" value="1" <?= !empty($s['on_ticket_board']) ? 'checked' : '' ?>>
            column
          </label>
        </td>
        <?php endif; ?>
        <td><input type="color" name="color[<?= $id ?>]" value="<?= e($s['color'] ?: '#95A5A6') ?>"></td>
        <td class="muted"><?= $n ?: '—' ?></td>
        <td class="row-actions">
          <button type="submit" name="action" value="del:<?= $id ?>" class="danger" title="Remove"
                  onclick="return confirm('Remove the status <?= e(addslashes($s['name'])) ?>?');">✕</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions">
    <button type="submit" name="action" value="save">Save statuses</button>
  </div>
</form>

<form method="post" class="add-row">
  <input type="hidden" name="action" value="add">
  <strong>Add a status</strong>
  <input name="new_name" placeholder="Status name" required>
  <select name="new_maps_to">
    <option value="none">counts as nothing yet</option>
    <option value="delivered">counts as delivered</option>
    <option value="approved">counts as approved</option>
  </select>
  <label class="inline"><input type="checkbox" name="new_is_blocked" value="1"> blocked</label>
  <?php if ($hasExcluded): ?>
    <label class="inline"><input type="checkbox" name="new_is_excluded" value="1"> not applicable</label>
  <?php endif; ?>
  <?php if ($hasBoard): ?>
    <label class="inline"><input type="checkbox" name="new_on_board" value="1"> board column</label>
  <?php endif; ?>
  <input type="color" name="new_color" value="#95A5A6">
  <button type="submit" class="secondary">Add</button>
</form>

<p class="muted small">
  <strong>Blocked</strong> means work that can't proceed — waiting on a client, a dependency
  or a decision — rather than work nobody has started. Both count as unfinished, but blocked
  work needs someone to unblock it, so it is reported separately.
</p>

<?php if ($hasExcluded): ?>
<p class="muted small">
  <strong>Not applicable</strong> is different again: the work doesn't exist. A shot with no
  FX, a prop that needs no rig. It is what <code>N/A</code> has always meant, and the
  scheduler reads it — an item marked this way gets no time planned for it, rather than
  sitting in the plan forever as something nobody will ever finish. It is also how a step
  whose coverage is still a guess ("FX is about 30% of the show") turns into real per-shot
  dates: mark the shots it doesn't touch, and the estimate stops being needed.
</p>
<?php endif; ?>

<?php if ($hasBoard): ?>
<p class="muted small">
  <strong>Board column</strong> decides which of these appear as columns on the
  <a href="tasks.php?project=<?= $projectId ?>">tasks board</a>. Tickets use this same
  vocabulary rather than a second set of words for the same ideas — but eleven columns is not
  a board anyone can read, so pick the five or six a card really moves between. A status that
  isn't a column still works: a card can sit in it, and the board shows those in its first
  column marked <em>off-board</em> rather than hiding them.
</p>
<?php endif; ?>

</div>
</body></html>
