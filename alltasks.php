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
// Changing a card still happens on the card. This page carries no controls on
// purpose: a status picker here would be a second way to do a thing the board
// already does, and the board is where the columns mean something.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/ai.php';   // ai_configured(), for the transcript button
require_once __DIR__ . '/includes/studio.php';
require_login();

// --- file one unfiled card onto a project -----------------------------------
//
// David, 2026-10-12: "instead of clicking for the ones that don't have a project,
// make sure a dropdown is always available in this view for the tasks that don't
// have a project assigned yet."
//
// ⚠ THIS REPLACED A BULK "MOVE THEM ALL TO THE STUDIO" BUTTON, and David is
// right that it was the wrong tool. His own list proves it: "Confirm actual
// FCM/FIFA deadline with Corey" and "Address staffing gaps on FCM project" are
// FCM's work, not the studio's. A single button would have filed them under the
// Studio in one press, and a card filed against the wrong show is harder to find
// than one filed against nothing — the wrong one looks handled. That sentence is
// from the 2026-10-07 decision and it argues for per-card choice here.
//
// ⚠ AND IT IS THE ONE CONTROL THIS SCREEN IS ALLOWED. The header says this page
// carries no controls on purpose, because a status picker here would duplicate
// the board. A PROJECT picker on an UNFILED card duplicates nothing: a card with
// no project is on no board at all, so this list is the only place it can be
// acted on. Filed rows keep plain text and get no picker — those belong to a
// board, and the board is where they are changed.
if (can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_ticket_project'])) {
    try {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $pid = (int)($_POST['project_id'] ?? 0);
        if ($tid <= 0 || $pid <= 0) throw new RuntimeException('Pick a project to file it on.');
        // move_ticket_to_project() is what grants the card a seq, so it stops
        // reading as "#21", and drops it into the target's first column so it is
        // visible the moment it arrives.
        // ⚠ IT REPORTS FAILURE, IT DOES NOT THROW. move_ticket_to_project()
        // catches its own exception and returns ['ok' => false, 'said' => ...].
        // Reading only the message and flashing 'ok' would have told somebody
        // their card was filed when it was not — the failure class this codebase
        // keeps paying for, on the one action this screen exists to perform.
        $r = move_ticket_to_project($pdo, $tid, $pid);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['said'] ?? 'The card was not moved.'));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    // Read straight from the query string: $showDone is assigned further down,
    // AFTER this handler, so using it here would be an undefined variable and
    // would silently drop the "include finished work" view on every save.
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



  <?php
  // Where an unfiled card can go. Archived shows are left out — filing today's
  // work onto a finished show is almost always a mis-click — and the Studio is
  // put FIRST because it is the reason this picker exists: it is the home for the
  // cards that belong to no show at all. Read once for the whole table.
  $fileTargets = [];
  if (can_edit()) {
      $order = studio_available($pdo) ? 'is_studio DESC, code' : 'code';
      $fileTargets = $pdo->query(
          "SELECT id, code, name FROM projects WHERE status <> 'archived' ORDER BY " . $order
      )->fetchAll();
  }
  ?>

  <?php // ⚠ CARDS WITH NO PROJECT LIVE HERE, and nowhere else. A project's board
        // filters on project_id, so an unfiled card is on no board at all — this
        // list is the only screen that shows it, which is why it reads "no project"
        // in words rather than leaving the cell blank: a blank cell looks like a
        // bug, and the Project filter is built from the words in the cells, so an
        // empty one could not be picked. David, 2026-10-07: "we should be able to
        // add them without a project so we can organize them later." Filing one is
        // a click from the card. ?>

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
          <?php // ⚠ THE SELECTED OPTION READS "no project", which is not cosmetic.
                // gridtools.js takes a cell's filter and sort value from its
                // <select>'s chosen option TEXT when there is one (assets/gridtools.js),
                // so the Project column's filter keeps offering "no project" as a
                // choice exactly as it did when this cell was a span. Changing that
                // first option's wording silently drops a filter value. ?>
          <td><?php if (($r['project_code'] ?? '') !== ''): ?>
                <?= e($r['project_code']) ?>
              <?php elseif ($fileTargets): ?>
                <form method="post" class="inline-form" style="margin:0;">
                  <input type="hidden" name="set_ticket_project" value="1">
                  <input type="hidden" name="ticket_id" value="<?= (int)$r['id'] ?>">
                  <select name="project_id" onchange="this.form.submit()"
                          class="warn-text proj-pick"
                          title="File this card on a show, or on the Studio">
                    <option value="" selected>no project</option>
                    <?php foreach ($fileTargets as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= e($t['code']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <span class="warn-text">no project</span>
              <?php endif; ?></td>
          <td class="muted small"><?= $r['type'] === 'ticket' ? 'Ticket' : 'Task' ?></td>
          <td><span class="status-chip" style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
              <?= e($r['status_name'] ?? 'no status') ?></span></td>
          <td class="muted small"><?= e($r['crew_name'] ?: '—') ?></td>
          <td><span class="prio-dot priority-<?= e($r['priority']) ?>"></span> <?= ucfirst($r['priority']) ?></td>
          <td class="muted small <?= $late ? 'err' : '' ?>"><?= e($r['due_date'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted small">
    Cards are changed on the card itself, and the columns on a project's board are that
    project's own statuses. This list only reads.
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

</div>
</body></html>
