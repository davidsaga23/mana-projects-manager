<?php
// Every open card, on every show, in one list.
//
// David, 2026-10-07: "I still want the general task menu so we can see all the
// open tasks with and without project to see the progress across multiple
// projects."
//
// ⚠ THIS IS A VIEW, NOT A CONTAINER, and the distinction decided the whole
// build. The earlier reading of "a general Tasks area" was a place cards LIVE
// before they are sorted into shows — that needs a board with no project, which
// needs statuses with no project, which is a schema argument (ROADMAP). What was
// actually wanted is to SEE ACROSS the shows, and that needs nothing new at all:
// the cards already exist, each on its own project, and this reads them together.
//
// So there is no migration here, nothing is stored, and a card shown on this
// page is the same row the project's own board draws. The two cannot disagree,
// because ticket_select_sql() is the one place either of them asks.
//
// Most changes still happen on the card. Status is the exception (David,
// 2026-10-17: "make this status column editable in this context"): each row
// offers its own project's statuses, saved through set_ticket_status(), the
// same function the board's drag uses, so the two cannot disagree.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/mailer.php';   // create_ticket() emails the assignee when a card is owned
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/ai.php';   // ai_configured(), for the transcript button
require_once __DIR__ . '/includes/studio.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);

// Where a card can go: every live show, the Studio first. ticket_file_targets()
// is the one list the Project dropdown on every card list reads.
//
// ⚠ READ ONCE, UP HERE, FOR BOTH CONTROLS. The per-row Project picker and the
// New task dialog are the same choice asked in two places, and a second query
// for the second one could order them differently or include an archived show in
// one and not the other. It is also out of the "this page has rows" branch on
// purpose: the create dialog has to exist on an EMPTY Studio Tasks, which is
// exactly when somebody wants to add the first card.
$fileTargets = can_edit() ? ticket_file_targets($pdo) : [];

// --- create a card from here ------------------------------------------------
//
// David, 2026-10-16: "ok but I still can't create task from this view." The
// Studio Tasks tab had a button through to the Studio's board and nothing else,
// which answered a question he had not asked. A cross-project list is where a
// card that belongs to no show is thought of in the first place.
//
// ⚠ TASKS ONLY, DELIBERATELY. A ticket is a problem attached to a shot, an
// asset or a pipeline step on ONE show, and those pickers cannot be drawn until
// a project is chosen — a project selector that rebuilt three other selectors
// under it is the board, and the board already exists. The dialog says so and
// links there.
//
// The card is created through the same create_ticket() the board uses, so the
// seq race, the status rules and the assignee notification cannot drift between
// the two screens.
if (can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $newProject = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;

    // ⚠ THE STATUS IS CHOSEN HERE, NOT ON THE FORM. Board columns are a
    // project's OWN vocabulary, so a status picker would have to be rebuilt
    // every time the project dropdown changed. A new card starts in its
    // project's first column, which is what dragging it onto the board would
    // have done anyway; an unfiled card gets no status because there is no
    // vocabulary it could belong to.
    $startStatus = null;
    if ($newProject) {
        [$cols] = ticket_board_columns($pdo, $newProject);
        if ($cols) $startStatus = (int)$cols[0]['id'];
    }

    try {
        $made = create_ticket($pdo, $newProject, [
            'title'             => $_POST['title'] ?? '',
            'description'       => $_POST['description'] ?? '',
            'type'              => 'task',
            'status_id'         => $startStatus,
            'priority'          => $_POST['priority'] ?? 'medium',
            'assigned_crew_id'  => $_POST['assigned_crew_id'] ?? null,
            'due_date'          => $_POST['due_date'] ?? null,
        ], [], $userId);
        flash('ok', 'Task ' . $made['ref'] . ' created. ' . trim(implode(' ', $made['notes'])));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: alltasks.php');
    exit;
}


// --- file or move one card ----------------------------------------------------
//
// David, 2026-10-12: "make sure a dropdown is always available in this view for
// the tasks that don't have a project assigned yet." And 2026-10-16: "for the
// tasks we need to always have a drop down menu available so we can classify
// the tasks to any project or studio wise." So every row carries one now, filed
// or not: a card filed on the wrong show has to be movable from the same list
// that filed it, or the mistake costs a trip to the card.
//
// ⚠ PER CARD, NEVER A BULK BUTTON. "Confirm actual FCM/FIFA deadline with Corey"
// is FCM's work, not the studio's, and a card filed against the wrong show is
// harder to find than one filed against nothing — the wrong one looks handled.
// The handler and the dropdown live in includes/tickets.php, shared with My tasks.
if (can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_ticket_project'])) {
    refile_ticket_from_post($pdo, $userId);
    // Read straight from the query string: $showDone is assigned further down,
    // AFTER this handler, so using it here would be an undefined variable and
    // would silently drop the "include finished work" view on every save.
    header('Location: alltasks.php' . ((($_GET['done'] ?? '') === '1') ? '?done=1' : ''));
    exit;
}

// The Status dropdown. Same rights as dragging a card on the board.
if (can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_ticket_status'])) {
    try {
        $t = load_ticket($pdo, (int)($_POST['ticket_id'] ?? 0));
        $sid = (int)($_POST['status_id'] ?? 0);
        if (!$t || $sid <= 0) throw new RuntimeException('Pick a status for the card.');
        $st = set_ticket_status($pdo, $t, $sid, $userId);
        $said = ticket_ref($t) . ' moved to ' . $st['name'] . '.';
        // A finished card leaves this list (it shows open work), so say where it went.
        if (ticket_status_is_done($st) && ($_GET['done'] ?? '') !== '1') {
            $said .= ' It is finished, so it is now under "include finished work".';
        }
        flash('ok', $said);
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: alltasks.php' . ((($_GET['done'] ?? '') === '1') ? '?done=1' : ''));
    exit;
}

$ready = column_exists($pdo, 'tickets', 'id');

// Finished work is OFF by default and one click away. "Open tasks" is what was
// asked for, and a list that silently included everything ever closed would
// answer a different question — but "what did we get through" is a fair question
// too, and hiding it entirely would be answering only half.
$showDone = ($_GET['done'] ?? '') === '1';

$rows = [];
if ($ready) {
    // ⚠ "OPEN" IS A PROPERTY OF THE STATUS, not a guess from its name. A status
    // counts as finished when the project said it maps to approved — the same
    // test the dashboard and My tasks use, so a card is open here exactly when it
    // is open there. Projects name their statuses differently and always will;
    // matching on the word "done" would work until the first show that says
    // "Final".
    $where = 't.archived = 0';
    if (!$showDone) $where .= " AND (st.maps_to IS NULL OR st.maps_to <> 'approved')";

    $rows = $pdo->query(
        ticket_select_sql() . "
          WHERE {$where}
          ORDER BY t.due_date IS NULL, t.due_date ASC,
                   FIELD(t.priority, 'urgent','high','medium','low'),
                   p.code, t.seq"
    )->fetchAll();
}

// Each show's own statuses, read once per project on the page, for the Status
// dropdowns. Only for people who can change a card; everyone else sees chips.
$statusesByProject = [];
if (can_edit()) {
    foreach ($rows as $r) {
        $pid = $r['project_id'] !== null ? (int)$r['project_id'] : 0;
        if ($pid && !isset($statusesByProject[$pid])) $statusesByProject[$pid] = project_statuses($pdo, $pid);
    }
}

$open = 0; $overdue = 0; $unowned = 0;
$today = date('Y-m-d');
$byProject = [];
// ⚠ COUNTED SEPARATELY, since migration 041. An unfiled card has no code, and
// a null array key becomes "" in PHP — so without this the unfiled ones would all
// pile into one nameless bucket and be counted as a show. They are not a show;
// they are work that has not been given one.
$unfiled = 0;
foreach ($rows as $r) {
    if (($r['project_code'] ?? '') === '' || $r['project_code'] === null) {
        $unfiled++;
    } else {
        $byProject[$r['project_code']] = ($byProject[$r['project_code']] ?? 0) + 1;
    }
    if (!empty($r['due_date']) && $r['due_date'] < $today) $overdue++;
    if (empty($r['assigned_crew_id'])) $unowned++;
}

$pageTitle = 'Studio Tasks';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <?php // "Studio Tasks", not "Tasks" (David, 2026-10-07). Next to "My tasks"
        // the shorter name read as a subset of it; this one says whose they are,
        // which is everybody's. ?>
  <h2 class="wizard-title">Studio Tasks
    <span class="muted">everything open, across every show</span>
  </h2>
  <?php // HERE as well as on a project's board, because this is where David went
        // looking for it — and he was right: a meeting is rarely about one show,
        // so the natural place to drop its transcript is the screen that spans
        // them. Which project the cards land on is chosen after reading. ?>
  <?php if (can_edit() && ai_configured()): ?>
    <a class="button secondary" href="transcript.php"
       title="Read a meeting transcript and turn its action items into cards">Tasks from transcript</a>
  <?php endif; ?>
  <?php // ⚠ THE BOARD IS tasks.php, NOT A SECOND ONE BUILT HERE. David asked to
        // "mimic the per project task/ticket UI" on this screen, and the honest
        // way to do that is to send him to the real one: the Studio is a project
        // row (migration 045), so its board already has the columns, the filters,
        // the archive and both create buttons, drawn by the code every other show
        // uses. A copy of that board living in this file would be the second
        // implementation this codebase keeps refusing to build — and the one that
        // would quietly drift.
        //
        // This page stays what its own header says it is: a view ACROSS the shows.
        $studioId = studio_project_id($pdo); ?>
  <?php if (can_edit()): ?>
    <button type="button" class="primary" onclick="openStudioCreate()">+ New task</button>
  <?php endif; ?>
  <?php if ($studioId): ?>
    <a class="button" href="tasks.php?project=<?= $studioId ?>"
       title="The studio's own board, with columns and New task / New ticket">Studio board →</a>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>This screen needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>017_tickets.sql</code> creates the tasks tables.</p>
    <?php if (is_admin()): ?><p><a class="button" href="migrate.php">Review and apply it →</a></p><?php endif; ?>
  </div>

<?php elseif (!$rows): ?>
  <div class="empty-state">
    <h2><?= $showDone ? 'No cards at all yet' : 'Nothing open' ?></h2>
    <p class="muted"><?= $showDone
        ? 'Tasks and tickets are raised on a project\'s Tasks board.'
        : 'Every card on every show is finished or archived.' ?></p>
    <?php if (!$showDone): ?>
      <p><a class="button secondary" href="alltasks.php?done=1">Include finished work</a></p>
    <?php endif; ?>
  </div>

<?php else: ?>

  <p class="muted small">
    <strong><?= count($rows) ?></strong> card<?= count($rows) === 1 ? '' : 's' ?>
    across <strong><?= count($byProject) ?></strong>
    show<?= count($byProject) === 1 ? '' : 's' ?><?php
      if ($unfiled): ?> · <span class="warn-text"><?= $unfiled ?> with no project yet</span><?php endif; ?><?php
      if ($overdue): ?> · <span class="err"><?= $overdue ?> past its date</span><?php endif; ?><?php
      if ($unowned): ?> · <span class="warn-text"><?= $unowned ?> with nobody on <?= $unowned === 1 ? 'it' : 'them' ?></span><?php endif; ?>
    ·
    <a href="alltasks.php<?= $showDone ? '' : '?done=1' ?>"><?= $showDone
        ? 'hide finished work' : 'include finished work' ?></a>
  </p>



  <?php // ⚠ CARDS WITH NO PROJECT LIVE HERE, and nowhere else. A project's board
        // filters on project_id, so an unfiled card is on no board at all — this
        // list is the only screen that shows it, which is why it reads "no project"
        // in words rather than leaving the cell blank: a blank cell looks like a
        // bug, and the Project filter is built from the words in the cells, so an
        // empty one could not be picked. David, 2026-10-07: "we should be able to
        // add them without a project so we can organize them later." Filing one is
        // the dropdown in its Project cell. ?>

  <div class="grid-scroll">
    <table class="grid-table alltasks-table">
      <thead>
        <tr>
          <th>Ref</th><th>Title</th><th>Project</th><th>Type</th>
          <th>Status</th><th>Owner</th><th>Priority</th><th>Due</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $late = !empty($r['due_date']) && $r['due_date'] < $today;
        $c = $r['status_color'] ?: '#8a97a3';
      ?>
        <tr class="<?= $late ? 'row-overdue' : '' ?>">
          <td><a href="ticket.php?id=<?= (int)$r['id'] ?>"
                 class="<?= $r['type'] === 'ticket' ? 'ref-ticket' : 'ref-task' ?>"><?= e(ticket_ref($r)) ?></a></td>
          <td><a href="ticket.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a>
            <?php if (($r['about'] ?? '') === TICKET_ABOUT_PLATFORM): ?>
              <span class="ctx-chip ctx-platform"><?= e(ticket_about_label(TICKET_ABOUT_PLATFORM)) ?></span>
            <?php endif; ?>
          </td>
          <?php // The dropdown keeps "no project" as an unfiled card's chosen
                // option, which is what the Project filter reads (see
                // ticket_project_picker()). Without edit rights it is plain text. ?>
          <td><?php if ($fileTargets): ?>
                <?= ticket_project_picker($r, $fileTargets) ?>
              <?php elseif (($r['project_code'] ?? '') !== ''): ?>
                <?= e($r['project_code']) ?>
              <?php else: ?>
                <span class="warn-text">no project</span>
              <?php endif; ?></td>
          <td class="muted small"><?= $r['type'] === 'ticket' ? 'Ticket' : 'Task' ?></td>
          <td><?php if (!empty($statusesByProject[(int)$r['project_id']])): ?>
                <?= ticket_status_picker($r, $statusesByProject[(int)$r['project_id']]) ?>
              <?php else: ?>
                <span class="status-chip" style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
                <?= e($r['status_name'] ?? 'no status') ?></span>
              <?php endif; ?></td>
          <td class="muted small"><?= e($r['crew_name'] ?: '—') ?></td>
          <td><span class="prio-dot priority-<?= e($r['priority']) ?>"></span> <?= ucfirst($r['priority']) ?></td>
          <td class="muted small <?= $late ? 'err' : '' ?>"><?= e($r['due_date'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted small">
    Cards are changed on the card itself, apart from their project and status, which change
    here too. Each card's Status offers its own project's columns, because every show names
    them its own way. A card moved to a finished status leaves this list.
  </p>

  <?php // The same filter, sort and count the Artists and Shots grids carry.
        // Filtering by Project is what turns "everything" into "this show and
        // that one", which is the question this page exists to answer. ?>
  <script src="assets/gridtools.js<?= asset_ver('gridtools.js') ?>"></script>
  <script>
    (function () {
      if (!window.GridTools) return;
      var RANK = { urgent: 0, high: 1, medium: 2, low: 3 };
      var table = document.querySelector('.alltasks-table');
      // Project, Type and Status are DROPDOWNS, not boxes: each is a short closed
      // list, and picking "MSD" is the question this page exists to answer.
      // Built from the values actually present, so a show with nothing open is
      // not offered as a filter that finds nothing.
      var PICK = { 2: 1, 3: 1, 4: 1 };
      window.GridTools.attach(table, {
        noun: ['card', 'cards'],
        options: function (col) {
          if (!PICK[col]) return null;
          var seen = {}, out = [];
          Array.prototype.forEach.call(table.tBodies[0].rows, function (tr) {
            // ⚠ cellText(), NOT textContent. Since the Project column gained a
            // <select> on unfiled rows, textContent returns every option run
            // together, "no projectSTUDIOMSDFCM", which would have filled this
            // filter with one nonsense entry and quietly LOST "no project" as a
            // choice, on the very column that exists to find those cards.
            // GridTools.cellText reads a select's chosen option instead.
            var v = window.GridTools.cellText(tr.cells[col]);
            if (v === '' || seen[v]) return;
            seen[v] = true;
            out.push({ value: v, label: v });
          });
          out.sort(function (a, b) { return a.label.localeCompare(b.label); });
          return out.length ? out : null;
        },
        // Priority reads Urgent, High, Medium, Low rather than alphabetically.
        sortKey: function (td, text) {
          var dot = td ? td.querySelector('.prio-dot') : null;
          if (dot) {
            var m = /priority-(\w+)/.exec(dot.className || '');
            if (m && RANK[m[1]] !== undefined) return [0, RANK[m[1]]];
          }
          return window.GridTools.sortKey(td, text);
        }
      });
    }());
  </script>

<?php endif; ?>

<?php // THE CREATE DIALOG. Outside every branch above, so it exists on an
      // EMPTY Studio Tasks too — the moment somebody most wants to add a
      // card is when there are none. It reads the same $fileTargets the
      // per-row Project pickers do, so the two cannot offer different shows.
      ?>
<?php if (can_edit()): ?>
<div id="studioCreate" class="modal" style="display:none;">
  <div class="modal-box">
    <h3>New task</h3>
    <form method="post">
      <input type="hidden" name="create" value="1">

      <label>Title
        <input name="title" id="studioCreateTitle" required placeholder="What needs doing?">
      </label>
      <label>Description
        <textarea name="description" rows="3" placeholder="Details, links, notes…"></textarea>
      </label>

      <div class="modal-row">
        <?php // ⚠ THE STUDIO IS FIRST AND SELECTED, because this is the Studio
              // Tasks screen and a card thought of here usually belongs to the
              // studio rather than to a show. "No project yet" is kept as a real
              // choice: capture-first (migration 041) says a card may exist
              // before anybody decides, and removing the option would quietly
              // undo that. ?>
        <label>Project
          <select name="project_id">
            <?php foreach ($fileTargets as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['code']) ?> <?= e($t['name']) ?></option>
            <?php endforeach; ?>
            <option value="">no project yet</option>
          </select>
        </label>
        <label>Priority
          <select name="priority">
            <?php foreach (ticket_priorities() as $p): ?>
              <option value="<?= $p ?>" <?= $p === 'medium' ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="modal-row">
        <?php // Everybody active, not one show's crew: the project is not chosen
              // until the dropdown above is, and a card can be handed to somebody
              // before it has a show at all. Same list ticket.php falls back to
              // for an unfiled card. ?>
        <label>Owner
          <select name="assigned_crew_id">
            <option value="">nobody</option>
            <?php foreach ($pdo->query('SELECT id, name, role, email, is_team, is_placeholder
                                          FROM crew WHERE active = 1 ORDER BY name') as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e(assignee_label($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Due
          <input type="date" name="due_date">
        </label>
      </div>

      <p class="muted small">
        It starts in the first column of whichever board you picked — no status if you
        leave it unfiled. Need a <strong>ticket</strong> instead, against a shot, an asset or a
        pipeline step? Those live on a show's own board
        <?php if ($studioId): ?>
          (<a href="tasks.php?project=<?= $studioId ?>">the Studio's is here</a>)<?php endif; ?>,
        where the pickers for them can exist.
      </p>

      <div class="modal-actions">
        <button type="button" class="secondary"
                onclick="document.getElementById('studioCreate').style.display='none';">Cancel</button>
        <button type="submit">Create task</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Small and local on purpose: assets/board.js owns the board's modals and is
  // not loaded here, and pulling it in for one dialog would bring the drag and
  // drop with it.
  function openStudioCreate() {
    var m = document.getElementById('studioCreate');
    m.style.display = 'flex';
    var t = document.getElementById('studioCreateTitle');
    if (t) t.focus();
  }
  // Escape and a click on the backdrop close it, matching the board's modals so
  // the two behave the same way.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('studioCreate').style.display = 'none';
  });
  document.getElementById('studioCreate').addEventListener('mousedown', function (e) {
    if (e.target === this) this.style.display = 'none';
  });
</script>
<?php endif; ?>

</div>
</body></html>
