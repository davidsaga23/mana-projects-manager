<?php
// A call transcript in, tasks out.
//
// David, 2026-10-07: "we need to process the transcript through an API for
// Anthropic so you can summarize the task and ideally assign a potential user
// based on the transcript, when you have any questions leave the assignee empty
// and I'll assign everything."
//
// ⚠ THREE SCREENS, AND NOTHING IS WRITTEN UNTIL THE THIRD. Upload, read what it
// found, then create. That is the same shape as the Plaky importer and it is
// here for a harder version of the same reason: those cards were WRITTEN BY A
// MODEL from a recording of people talking. Every one of them is a guess about
// what somebody meant, and the only safe place for a guess is in front of a
// person before it becomes a row.
//
// The middle screen is therefore not a formality to click past. It is the whole
// feature: titles are editable, owners are a dropdown, and anything wrong is
// unticked rather than fixed afterwards on five separate cards.
//
// ⚠ CARDS LAND IN THE FIRST BOARD COLUMN, unassigned unless the transcript was
// plain about it. David: "when you have any questions leave the assignee empty
// and I'll assign everything." An empty owner is the expected answer, not a
// failure of the extraction.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/ai.php';
require_login();

// ⚠ A PROJECT IS OPTIONAL HERE, and that is the point (David, 2026-10-07: he
// went looking for this button on the cross-project Tasks screen, which is
// exactly where he had said he wanted it — "drop the transcript in that general
// task and then as we assign them we can put them in a project or the other").
//
// A meeting is rarely about one show, and which show each action item belongs to
// is often only decided while reading the list. So the file can be read with no
// project at all, and WHERE THE CARDS GO IS CHOSEN ON THE REVIEW SCREEN, after
// there is something to look at. Opened from a project's board, that project is
// simply preselected.
$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$project = null;
if ($projectId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        flash('err', 'That project no longer exists.');
        header('Location: alltasks.php');
        exit;
    }
}

$selfUrl  = 'transcript.php' . ($projectId ? '?project=' . $projectId : '');
$boardUrl = $projectId ? 'tasks.php?project=' . $projectId : 'alltasks.php';

// Every show a card could be filed against.
$projects = $pdo->query(
    "SELECT id, code, name FROM projects WHERE status <> 'archived'
      ORDER BY FIELD(status, 'active','setup','on_hold','wrapped'), name"
)->fetchAll();
$userId    = (int)($_SESSION['user_id'] ?? 0);
$ready     = column_exists($pdo, 'tickets', 'id');
// Who the model may name. Scoped to the project when there is one; studio-wide
// when there is not, because a meeting about three shows mentions people from
// all of them and a name it cannot match becomes nobody either way.
$assignees = [];
if ($ready) {
    $assignees = $projectId
        ? ticket_assignees($pdo, $projectId)
        : $pdo->query(
            'SELECT id, name, role FROM crew
              WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
              ORDER BY name'
          )->fetchAll();
}
/**
 * The column a new card lands in, for whichever project it is filed against.
 *
 * ⚠ Worked out PER PROJECT at the moment of writing, not once for the page: the
 * target is chosen on the review screen, and columns are a project's own
 * statuses, so a card filed against MSD must land in an MSD column.
 *
 * ticket_board_columns() returns a PAIR — the columns and whether they are a
 * fallback — and the columns are a plain list, not keyed by id.
 */
function transcript_first_column(PDO $pdo, int $projectId): array {
    [$cols, ] = ticket_board_columns($pdo, $projectId);
    return $cols ? [(int)$cols[0]['id'], (string)$cols[0]['name']] : [null, 'the first column'];
}

$found = [];      // what the model returned, held in the session between screens
$note  = '';

// --- read the file ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['read'])) {
    try {
        if (!can_edit()) throw new RuntimeException('Your account cannot create cards.');
        if (empty($_FILES['file']['tmp_name'])) throw new RuntimeException('No file was chosen.');
        $text = file_get_contents($_FILES['file']['tmp_name']);
        if ($text === false) throw new RuntimeException('That file could not be read.');

        $res = ai_extract_tasks($text, $assignees);
        if (!$res['ok']) throw new RuntimeException($res['error']);

        if (!$res['tasks']) {
            flash('warn', 'Nothing in that transcript looked like something somebody has to do. '
                        . 'Nothing was created.');
            header('Location: ' . $selfUrl);
            exit;
        }
        $_SESSION['transcript'][$projectId] = ['tasks' => $res['tasks'], 'note' => $res['usage'],
                                              'name' => $_FILES['file']['name'] ?? 'transcript.md'];
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

// --- create the ones that were ticked ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    try {
        if (!can_edit()) throw new RuntimeException('Your account cannot create cards.');
        // Where they go, decided on the review screen. Verified against the real
        // list rather than trusted: a posted id is not a project until it is.
        $to = (int)($_POST['to_project'] ?? 0);
        $ok = false;
        foreach ($projects as $pr) if ((int)$pr['id'] === $to) { $ok = true; $target = $pr; }
        if (!$ok) throw new RuntimeException('Pick which project these belong to.');

        [$firstStatus, ] = transcript_first_column($pdo, $to);

        $rows = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
        $made = 0;

        foreach ($rows as $r) {
            if (empty($r['keep'])) continue;            // unticked: not wanted
            $title = trim((string)($r['title'] ?? ''));
            if ($title === '') continue;                // emptied: the same answer

            $crewId = !empty($r['crew_id']) ? (int)$r['crew_id'] : null;
            $priority = in_array($r['priority'] ?? '', ticket_priorities(), true)
                      ? $r['priority'] : 'medium';
            $type = ($r['type'] ?? 'task') === 'ticket' ? 'ticket' : 'task';

            $seq = next_ticket_seq($pdo, $to);
            $pdo->prepare(
                'INSERT INTO tickets
                   (project_id, seq, type, title, description, status_id, priority,
                    assigned_crew_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $to, $seq, $type, mb_substr($title, 0, 255),
                trim((string)($r['description'] ?? '')) ?: null,
                $firstStatus, $priority, $crewId, $userId ?: null,
            ]);
            $made++;
        }

        unset($_SESSION['transcript'][$projectId]);
        flash($made ? 'ok' : 'warn', $made
            ? $made . ' card' . ($made === 1 ? '' : 's') . ' created on ' . $target['name']
              . ', waiting to be started.'
            : 'Nothing was ticked, so nothing was created.');
        header('Location: ' . ($made ? 'tasks.php?project=' . $to : $selfUrl));
        exit;
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        header('Location: ' . $selfUrl);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discard'])) {
    unset($_SESSION['transcript'][$projectId]);
    header('Location: ' . $selfUrl);
    exit;
}

$held  = $_SESSION['transcript'][$projectId] ?? null;
$found = $held['tasks'] ?? [];
$note  = $held['note'] ?? '';

$pageTitle = ($project ? $project['name'] . ' · ' : '') . 'Transcript';
require __DIR__ . '/includes/header.php';
?>

<?php // The project bar only when there is a project. Read from the
      // cross-project Tasks screen there is no one show to be "on". ?>
<?php if ($project) project_nav($project, 'tasks'); ?>

<h2 class="wizard-title">Read a transcript
  <span class="muted">action items out of a meeting, into the backlog</span>
</h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>This screen needs migration <code>017_tickets.sql</code>.</strong></p>
  </div>

<?php elseif (!ai_configured()): ?>
  <div class="flash flash-warn">
    <p><strong>No Anthropic API key is set on this server.</strong></p>
    <p class="small">Add <code>'anthropic_api_key' =&gt; 'sk-ant-…'</code> to <code>config.php</code>
       on the server. It is not in git and not in the deploy, so it has to be added by hand —
       <code>config.example.php</code> has the line to copy.</p>
  </div>

<?php elseif (!$found): ?>

  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="read" value="1">
    <label>Transcript (.md or .txt)
      <input type="file" name="file" accept=".md,.txt,.markdown,text/plain,text/markdown" required>
      <small>The whole transcript. It is read once, here, and not stored.</small>
    </label>
    <div class="actions">
      <button type="submit" <?= can_edit() ? '' : 'disabled' ?>>Read it →</button>
    </div>
  </form>

  <div class="flash flash-warn" style="margin-top:18px">
    <p><strong>This sends the transcript to Anthropic.</strong> It is the only thing in this
       platform that sends anything outside this server, so it is worth saying plainly: the text of
       the meeting leaves the building to be read, and it costs a small amount per use.</p>
    <p class="small">Nothing is created from it until you have seen the list and pressed the button.
       A long transcript takes <strong>up to a minute</strong> — the page will sit there while it reads.</p>
  </div>

<?php else: ?>

  <p class="muted small">
    <strong><?= e($held['name'] ?? 'transcript') ?></strong> · <?= e($note) ?>
  </p>

  <?php // ⚠ THE LIST IS THE FEATURE. Every line is editable and every line can be
        // unticked, because this is a model's reading of people talking and some
        // of it will be wrong. Fixing it here is one pass; fixing it afterwards is
        // five separate cards. ?>
  <form method="post">
    <input type="hidden" name="create" value="1">
    <div class="grid-scroll">
      <table class="grid-table">
        <thead>
          <tr><th>Create</th><th>Title</th><th>What was said</th><th>Owner</th>
              <th>Priority</th><th>Kind</th></tr>
        </thead>
        <tbody>
        <?php foreach ($found as $i => $t): $n = 't[' . $i . ']'; ?>
          <tr>
            <td class="num"><input type="checkbox" name="<?= $n ?>[keep]" value="1" checked></td>
            <td><input name="<?= $n ?>[title]" value="<?= e($t['title']) ?>" size="40" maxlength="255"></td>
            <td><textarea name="<?= $n ?>[description]" rows="2" cols="34"><?= e($t['description']) ?></textarea></td>
            <td>
              <?php // Nobody is the FIRST option and the default, because it is the
                    // expected answer whenever the transcript was not plain. ?>
              <select name="<?= $n ?>[crew_id]">
                <option value="">nobody yet</option>
                <?php foreach ($assignees as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= (int)$t['crew_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                    <?= e($a['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="<?= $n ?>[priority]">
                <?php foreach (ticket_priorities() as $p): ?>
                  <option value="<?= e($p) ?>" <?= $t['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="<?= $n ?>[type]">
                <option value="task"   <?= $t['type'] === 'task'   ? 'selected' : '' ?>>Task</option>
                <option value="ticket" <?= $t['type'] === 'ticket' ? 'selected' : '' ?>>Ticket</option>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php // ⚠ CHOSEN HERE, AFTER READING, not before uploading. Which show a
          // meeting's actions belong to is usually only clear once the list is
          // in front of you — which is why the file can be read with no project
          // at all. They all land on one show; a card that belongs elsewhere is
          // moved from the card itself, which takes a click. ?>
    <p class="muted small file-them">
      File them on
      <select name="to_project" required aria-label="Project to create these cards on">
        <?php if (!$projectId): ?><option value="">choose a project…</option><?php endif; ?>
        <?php foreach ($projects as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>" <?= $projectId === (int)$pr['id'] ? 'selected' : '' ?>>
            <?= e($pr['name']) ?> (<?= e($pr['code']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      in that board's first column. A card on the wrong show can be moved from the card
      itself afterwards.
    </p>

    <div class="actions">
      <button type="submit">Create the ticked ones</button>
      <button type="submit" name="discard" value="1" class="secondary">Throw this away</button>
    </div>
  </form>

<?php endif; ?>

</div>
</body></html>
