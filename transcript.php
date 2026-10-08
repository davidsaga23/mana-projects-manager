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

// Every show a card could be filed against. Postmortem shows included: notes
// that come out of a postmortem meeting are exactly the kind of thing somebody
// files as a card, so the state that exists to hold that meeting must not be
// the state you cannot file against (migration 044).
//
// ⚠ 'postmortem' is named in the FIELD list for the same reason it is on
// ticket.php: FIELD() returns 0 for an unlisted value and 0 sorts first, so
// omitting it would put these shows above the active ones.
$projects = $pdo->query(
    "SELECT id, code, name FROM projects WHERE status <> 'archived'
      ORDER BY FIELD(status, 'active','setup','on_hold','wrapped','postmortem'), name"
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

        $res = ai_extract_tasks($text, $assignees, $projects);
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
        // ⚠ A PROJECT PER ROW (David, 2026-10-07: "not all of these will land on
        // the same project"). One meeting covers several shows, which is exactly
        // why reading it with no project at all is the normal case.
        $known = [];
        foreach ($projects as $pr) $known[(int)$pr['id']] = $pr;

        // The first column of each show it files against, worked out once per
        // project rather than once per row: twenty cards on two shows is two
        // lookups, not twenty.
        $firstCol = [];

        $rows = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
        $made = 0;
        $byProject = [];      // code => how many, for the report
        $unfiled = 0;         // cards created with no project at all
        $lastProject = 0;     // the one show to send him to, if there was only one

        foreach ($rows as $r) {
            if (empty($r['keep'])) continue;            // unticked: not wanted
            $title = trim((string)($r['title'] ?? ''));
            if ($title === '') continue;                // emptied: the same answer

            // ⚠ NO PROJECT IS AN ANSWER, NOT AN ERROR (David, 2026-10-07: "we
            // should be able to add them without a project so we can organize them
            // later"). An unfiled card is created with project_id NULL — no board,
            // no status, no reference — and lives on Studio Tasks until somebody
            // files it. What is NOT done is guess a show: a card quietly filed on
            // whichever happened to be first is the mistake this screen exists to
            // stop, and migration 041 is what makes the honest answer possible.
            $to = (int)($r['project_id'] ?? 0);
            if (!isset($known[$to])) $to = 0;
            if ($to && !array_key_exists($to, $firstCol)) {
                [$firstCol[$to], ] = transcript_first_column($pdo, $to);
            }

            $crewId = !empty($r['crew_id']) ? (int)$r['crew_id'] : null;
            $priority = in_array($r['priority'] ?? '', ticket_priorities(), true)
                      ? $r['priority'] : 'medium';
            $type = ($r['type'] ?? 'task') === 'ticket' ? 'ticket' : 'task';

            $seq = next_ticket_seq($pdo, $to);     // 0 when there is no project
            $pdo->prepare(
                'INSERT INTO tickets
                   (project_id, seq, type, title, description, status_id, priority,
                    assigned_crew_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $to ?: null, $seq, $type, mb_substr($title, 0, 255),
                trim((string)($r['description'] ?? '')) ?: null,
                $to ? $firstCol[$to] : null, $priority, $crewId, $userId ?: null,
            ]);
            $made++;
            if ($to) {
                $code = $known[$to]['code'];
                $byProject[$code] = ($byProject[$code] ?? 0) + 1;
                $lastProject = $to;
            } else {
                $unfiled++;
            }
        }

        // ⚠ THE SPLIT IS SAID OUT LOUD, including where the unfiled ones went.
        // "20 cards created" and then only fourteen on the board is the kind of
        // quiet shortfall this codebase keeps paying for — so the sentence names
        // the shows, the count with no show, and where to find those.
        $said = [];
        if ($made) {
            $parts = [];
            foreach ($byProject as $code => $n) $parts[] = $n . ' on ' . $code;
            if ($unfiled) $parts[] = $unfiled . ' with no project yet';
            $said[] = $made . ' card' . ($made === 1 ? '' : 's') . ' created ('
                    . implode(', ', $parts) . '), waiting to be started.';
        }
        if ($unfiled) {
            $said[] = 'The unfiled ' . ($unfiled === 1 ? 'one is' : 'ones are')
                    . ' on Studio Tasks — open ' . ($unfiled === 1 ? 'it' : 'each one')
                    . ' and pick a project when you know which show ' . ($unfiled === 1 ? 'it' : 'they')
                    . ' belong' . ($unfiled === 1 ? 's' : '') . ' to.';
        }
        if (!$made) $said[] = 'Nothing was ticked, so nothing was created.';

        // Everything that was ticked is now a card, so the list has done its job.
        if ($made) unset($_SESSION['transcript'][$projectId]);

        flash($made ? 'ok' : 'warn', implode(' ', $said));
        // One show and nothing unfiled: its board. Anything else — several shows,
        // or cards with no show — and only Studio Tasks shows all of them at once.
        $where = $selfUrl;
        if ($made) {
            $where = (count($byProject) === 1 && !$unfiled)
                ? 'tasks.php?project=' . $lastProject
                : 'alltasks.php';
        }
        header('Location: ' . $where);
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
          <tr><th>Create</th><th>Title</th><th>What was said</th><th>Project</th>
              <th>Owner</th><th>Priority</th><th>Kind</th></tr>
        </thead>
        <tbody>
        <?php foreach ($found as $i => $t): $n = 't[' . $i . ']'; ?>
          <tr>
            <td class="num"><input type="checkbox" name="<?= $n ?>[keep]" value="1" checked></td>
            <td><input name="<?= $n ?>[title]" value="<?= e($t['title']) ?>" size="40" maxlength="255"></td>
            <td><textarea name="<?= $n ?>[description]" rows="2" cols="34"><?= e($t['description']) ?></textarea></td>
            <td>
              <?php // ⚠ PER ROW (David, 2026-10-07: "not all of these will land on the
                    // same project"). The model suggests one from the codes it was
                    // given and leaves it empty when unsure, on the same terms as
                    // the owner; what it suggested is preselected and is a guess
                    // until somebody looks at it. ?>
              <select name="<?= $n ?>[project_id]" class="t-project">
                <option value="">no project yet</option>
                <?php foreach ($projects as $pr): ?>
                  <option value="<?= (int)$pr['id'] ?>" <?=
                    (int)($t['project_id'] ?? 0) === (int)$pr['id'] ? 'selected'
                      : ($projectId === (int)$pr['id'] && empty($t['project_id']) ? 'selected' : '') ?>>
                    <?= e($pr['code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
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
          // meeting's actions belong to is usually only clear once the list is in
          // front of you — which is why the file can be read with no project at
          // all, and why a row can be created without one. ?>
    <?php // A convenience, not the control: most transcripts are mostly one show,
          // so setting every row at once and then correcting two of them beats
          // picking twenty times. The row is still what decides. ?>
    <p class="muted small file-them">
      Set every row to
      <select id="setAll" aria-label="Set every row's project">
        <option value="" disabled selected>—</option>
        <?php // "No project" is a real choice here, not just the absence of one: a
              // meeting that covered nothing filed yet is twenty rows to clear. ?>
        <option value="0">no project yet</option>
        <?php foreach ($projects as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?> (<?= e($pr['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
 — each card lands in that board's first column. A row left on
      <em>no project yet</em> is still created — it waits on
      <a href="alltasks.php">Studio Tasks</a> with no board or status until you file it,
      which takes one click from the card.
    </p>
    <script>
      document.getElementById('setAll').addEventListener('change', function () {
        if (this.value === '') return;
        // The helper says "0" for no project; the row selects say "" for it. Mapped
        // here rather than giving the rows a "0" option, because "" is what an
        // unchosen select posts and the write path already reads that as unfiled.
        var v = this.value === '0' ? '' : this.value;
        document.querySelectorAll('.t-project').forEach(function (s) { s.value = v; });
      });
    </script>

    <div class="actions">
      <button type="submit">Create the ticked ones</button>
      <button type="submit" name="discard" value="1" class="secondary">Throw this away</button>
    </div>
  </form>

<?php endif; ?>

</div>
</body></html>
