<?php
// The tasks and tickets board for one project.
//
// Ported from STFC's kanban.php, which is the version the team opens every day.
// What changed on the way across:
//
//   * COLUMNS ARE THE PROJECT'S STATUSES, not a hardcoded five-value ENUM. See
//     migrations/017_tickets.sql for why, and the Statuses screen for which ones
//     appear here.
//   * The board is scoped to a project, and the reference on a card is
//     "RTC-12" — per project, so two shows don't interleave their numbering.
//   * Assignees are this project's crew, including unfilled SLOTS and teams.
//   * A ticket can name a shot, an asset, a pipeline STEP, or any combination.
//
// One rule STFC has that is deliberately NOT ported: there, non-admin
// submissions were forced into Backlog for triage. This platform's roles are
// admin / editor / artist, and an artist cannot POST to this screen at all
// (enforced once, in require_login(); artist_may_post() names the only writes
// they have, and all of them are on My tasks). So there is nobody here who may
// file a ticket but should not be trusted to place it, and a rule guarding
// against a role that doesn't exist is just a surprise waiting for whoever
// hits it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/tickets.php';
require_once __DIR__ . '/includes/ai.php';   // ai_configured(), for the transcript button
require_login();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'tasks.php?project=' . $projectId;
$userId  = (int)($_SESSION['user_id'] ?? 0);

// The whole feature arrives in migration 017. Detect it rather than assuming, and
// SAY SO if it is missing — a screen that quietly renders empty is the failure
// this codebase keeps paying for (START_HERE, lesson 11).
$ready = column_exists($pdo, 'tickets', 'id');

// --- create -----------------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    // ⚠ THE WORK MOVED TO create_ticket() ON 2026-10-16, and this page now asks
    // for it like anybody else. It was ninety lines inline here; Studio Tasks
    // needed the same rules, and copying them would have meant two answers to
    // "what is a new card" that drift apart the first time one is fixed.
    try {
        $made = create_ticket(
            $pdo, $projectId, $_POST,
            !empty($_FILES['attachments']) ? normalize_files_array($_FILES['attachments']) : [],
            $userId
        );
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        finish_post($selfUrl);
    }

    flash('ok', ucfirst($made['type']) . ' ' . $made['ref'] . ' created. '
              . trim(implode(' ', $made['notes'])));
    // A new ticket opens its own page — that is where its thread and attachments
    // are, and it is the link people paste to each other. A plain task goes back
    // to the board.
    finish_post($made['type'] === 'ticket' ? 'ticket.php?id=' . $made['id'] : $selfUrl);
}

// --- restore from the archive ----------------------------------------------
// Clears the flag and restarts the completion clock, so the card isn't swept
// straight back on the next page load.
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore'])) {
    $rid = (int)$_POST['restore'];
    $pdo->prepare('UPDATE tickets SET archived = 0, completed_at = NOW() WHERE id = ? AND project_id = ?')
        ->execute([$rid, $projectId]);
    flash('ok', 'Card put back on the board.');
    header('Location: ' . $selfUrl);
    exit;
}

// --- un-snooze (migration 053) ----------------------------------------------
// Wakes a snoozed card early, from the board's Snoozed view. Editors only, and
// scoped to this project. Snoozing itself is done from the card (ticket.php) and
// from My tasks; the board just needs a way to reach a hidden card and wake it.
if ($ready && can_edit() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsnooze'])) {
    $sid = (int)$_POST['unsnooze'];
    $own = $pdo->prepare('SELECT 1 FROM tickets WHERE id = ? AND project_id = ?');
    $own->execute([$sid, $projectId]);
    if ($own->fetchColumn()) {
        flash('ok', snooze_ticket($pdo, $sid, null, $userId));
    }
    header('Location: ' . $selfUrl . '&snoozed=1');
    exit;
}

// --- delete -----------------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $delId = (int)$_POST['delete'];
    $owner = $pdo->prepare('SELECT created_by FROM tickets WHERE id = ? AND project_id = ?');
    $owner->execute([$delId, $projectId]);
    $createdBy = (int)$owner->fetchColumn();

    if (is_admin() || $createdBy === $userId) {
        $res = delete_ticket_with_files($pdo, $delId);
        flash($res['deleted'] ? 'ok' : 'warn', $res['deleted']
            ? 'Deleted' . ($res['files'] ? ", and removed {$res['files']} attached file(s)." : '.')
            : 'Nothing was deleted, that card is already gone.');
    } else {
        flash('err', 'Only an administrator, or whoever raised it, can delete this card.');
    }
    header('Location: ' . $selfUrl . (!empty($_POST['from_archive']) ? '&archived=1' : ''));
    exit;
}

// --- load -------------------------------------------------------------------
$columns = []; $usedFallback = false; $cards = []; $justArchived = 0;
$archivedCount = 0; $archiveDays = 3; $showArchived = false;
$snoozedCount = 0; $showSnoozed = false;
$assignees = []; $steps = []; $shotOptions = []; $assetOptions = [];
$episodic = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';

if ($ready) {
    [$columns, $usedFallback] = ticket_board_columns($pdo, $projectId);

    $justArchived  = archive_stale_done($pdo, $projectId);
    $archiveDays   = (int)project_setting($pdo, $projectId, 'archive_done_after_days', '3');
    $showArchived  = !empty($_GET['archived']);
    $ac = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE project_id = ? AND archived = 1');
    $ac->execute([$projectId]);
    $archivedCount = (int)$ac->fetchColumn();

    // Snoozed cards (migration 053): a count for the toggle, and a view that lists
    // them so a hidden card can be found and woken. Archive takes precedence if
    // both are asked for in the URL — they are separate shelves, never both.
    if (column_exists($pdo, 'tickets', 'snoozed_until')) {
        $showSnoozed = !$showArchived && !empty($_GET['snoozed']);
        $sc = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE project_id = ? AND archived = 0
                               AND snoozed_until IS NOT NULL AND snoozed_until > CURDATE()');
        $sc->execute([$projectId]);
        $snoozedCount = (int)$sc->fetchColumn();
    }

    if ($showSnoozed) {
        // Same column grouping board_tickets() builds, so the Snoozed view reads
        // like the board it came from.
        $cards = [];
        foreach (snoozed_tickets_for_project($pdo, $projectId) as $r) {
            $cards[(int)($r['status_id'] ?? 0)][] = $r;
        }
    } else {
        $cards = board_tickets($pdo, $projectId, $showArchived);
    }
    $assignees = ticket_assignees($pdo, $projectId);

    $stepsQ = $pdo->prepare('SELECT id, name FROM pipeline_steps WHERE project_id = ? ORDER BY step_order, name');
    $stepsQ->execute([$projectId]);
    $steps = $stepsQ->fetchAll();

    $shotsQ = $pdo->prepare('SELECT episode, shot_code FROM shots WHERE project_id = ? ORDER BY episode, shot_code');
    $shotsQ->execute([$projectId]);
    foreach ($shotsQ->fetchAll() as $s) {
        $label = shot_label($s['episode'], $s['shot_code']);
        if ($label !== '') $shotOptions[] = $label;
    }

    $assetsQ = $pdo->prepare('SELECT name FROM assets WHERE project_id = ? ORDER BY name');
    $assetsQ->execute([$projectId]);
    $assetOptions = $assetsQ->fetchAll(PDO::FETCH_COLUMN);
}

$crewColors = crew_color_map($assignees);
$today = date('Y-m-d');

$pageTitle = 'Tasks · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'tasks'); ?>

<?php if (!$ready): ?>
  <h2 class="wizard-title">Tasks <span class="muted">· <?= e($project['name']) ?></span></h2>
  <div class="empty-state">
    <p><strong>The tasks board needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>017_tickets.sql</code> creates the tables this screen reads.
       Nothing is broken, the feature simply isn't switched on until it runs.</p>
    <?php if (is_admin()): ?>
      <p><a class="button" href="migrate.php">Review and apply it →</a></p>
    <?php else: ?>
      <p class="muted">Ask an administrator to open the Migrations screen.</p>
    <?php endif; ?>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<div class="board-head">
  <h2 class="wizard-title" style="margin:0;">
    <?= $showArchived ? 'Archive' : ($showSnoozed ? 'Snoozed' : 'Tasks') ?> <span class="muted">· <?= e($project['name']) ?></span>
  </h2>
  <div class="board-tools">
    <label class="inline muted small">Show
      <select id="filterType">
        <option value="all">Everything</option>
        <option value="task">Tasks only</option>
        <option value="ticket">Tickets only</option>
      </select>
    </label>
    <label class="inline muted small">Owner
      <select id="filterAssignee">
        <option value="all">Anyone</option>
        <option value="none">Nobody</option>
        <?php foreach ($assignees as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="inline muted small">Due
      <select id="filterDue">
        <option value="all">Any date</option>
        <option value="overdue">Overdue</option>
        <option value="today">Today</option>
        <option value="week">Next 7 days</option>
        <option value="nodate">No due date</option>
      </select>
    </label>
    <?php if ($showArchived || $showSnoozed): ?>
      <a class="button" href="<?= e($selfUrl) ?>">← Back to the board</a>
    <?php else: ?>
      <?php if ($snoozedCount > 0): ?>
        <a class="button" href="<?= e($selfUrl) ?>&amp;snoozed=1">Snoozed (<?= $snoozedCount ?>)</a>
      <?php endif; ?>
      <?php if ($archivedCount > 0): ?>
        <a class="button" href="<?= e($selfUrl) ?>&amp;archived=1">Archive (<?= $archivedCount ?>)</a>
      <?php endif; ?>
      <?php if (can_edit()): ?>
        <?php // Only offered where it would work. A button that opens a screen
              // saying "no API key" is a button that teaches people to ignore
              // buttons; without a key this one simply is not there, and the
              // screen explains itself to anybody who reaches it by address. ?>
        <?php if (function_exists('ai_configured') && ai_configured()): ?>
          <a class="button secondary" href="transcript.php?project=<?= $projectId ?>"
             title="Read a meeting transcript and turn the action items into cards">Tasks from transcript</a>
        <?php endif; ?>
        <button type="button" class="secondary" onclick="openCreate('task')">+ New task</button>
        <button type="button" onclick="openCreate('ticket')">+ New ticket</button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($showSnoozed): ?>
  <p class="muted small">
    <strong><?= $snoozedCount ?></strong> snoozed card(s), hidden from the board until the date each
    shows. <strong>Nothing is deleted</strong> — a card returns on its own that day, or use Un-snooze
    to bring one back now.
  </p>
<?php elseif ($showArchived): ?>
  <p class="muted small">
    <strong><?= $archivedCount ?></strong> archived card(s). Finished work lands here
    <?= $archiveDays ?> day<?= $archiveDays === 1 ? '' : 's' ?> after it reaches a status that counts
    as approved. <strong>Nothing is deleted</strong>, use Restore to bring one back.
  </p>
<?php elseif ($justArchived > 0): ?>
  <p class="muted small">
    <?= $justArchived ?> finished card(s) just moved to the
    <a href="<?= e($selfUrl) ?>&amp;archived=1">archive</a>.
  </p>
<?php endif; ?>

<?php if (!$columns): ?>
  <div class="empty-state">
    <p><strong>This project has no statuses yet, so the board has no columns.</strong></p>
    <p class="muted">Board columns are the project's own statuses, the same vocabulary the
       shots and assets use, rather than a second set of words for the same ideas.</p>
    <p><a class="button" href="statuses_edit.php?project=<?= $projectId ?>">Set up statuses →</a></p>
  </div>
<?php else: ?>

<?php if ($usedFallback): ?>
  <p class="flash flash-warn">
    No status is ticked as a board column, so these <?= count($columns) ?> were chosen automatically,
    one per meaning. Pick the ones you actually want on the
    <a href="statuses_edit.php?project=<?= $projectId ?>">Statuses screen</a>.
  </p>
<?php endif; ?>

<?php
// Cards whose status was deleted out from under them. They belong nowhere, so
// they go in the first column with a marker rather than disappearing.
$orphans = $cards[0] ?? [];
$columnIds = array_map(fn($c) => (int)$c['id'], $columns);
// A card sitting in a status that exists but is NOT a board column would be
// invisible too — collect those the same way.
foreach ($cards as $sid => $list) {
    if ($sid !== 0 && !in_array((int)$sid, $columnIds, true)) {
        $orphans = array_merge($orphans, $list);
    }
}
?>

<?php if ($orphans): ?>
  <p class="flash flash-warn">
    <?= count($orphans) ?> card(s) are in a status that isn't one of the columns below, so they are
    shown in the first column marked <em>off-board</em>. Tick that status on the
    <a href="statuses_edit.php?project=<?= $projectId ?>">Statuses screen</a>, or drag them somewhere else.
  </p>
<?php endif; ?>

<div class="board" style="grid-template-columns: repeat(<?= count($columns) ?>, minmax(200px, 1fr));">
  <?php foreach ($columns as $i => $col):
    $sid = (int)$col['id'];
    $list = $cards[$sid] ?? [];
    if ($i === 0) $list = array_merge($orphans, $list);
    $colColor = $col['color'] ?: '#8a97a3';
  ?>
    <div class="board-col" data-status-id="<?= $sid ?>">
      <div class="board-col-head">
        <span class="board-dot" style="background:<?= e($colColor) ?>"></span>
        <span class="board-col-name"><?= e($col['name']) ?></span>
        <?php if ((int)$col['is_blocked'] === 1): ?>
          <span class="board-col-flag" title="Work that cannot proceed, this column is an escalation, not a queue">blocked</span>
        <?php endif; ?>
        <span class="board-col-count"><?= count($list) ?></span>
      </div>
      <div class="board-cards"
           ondragover="event.preventDefault(); this.classList.add('drag-over')"
           ondragleave="this.classList.remove('drag-over')"
           ondrop="dropCard(event, <?= $sid ?>); this.classList.remove('drag-over')">
        <?php foreach ($list as $t):
          $isTicket  = ($t['type'] ?? 'task') === 'ticket';
          $isOverdue = $t['due_date'] && $t['due_date'] < $today && ($t['status_maps_to'] ?? '') !== 'approved';
          $offBoard  = (int)($t['status_id'] ?? 0) !== $sid;
          $crewColor = $t['assigned_crew_id'] ? ($crewColors[(int)$t['assigned_crew_id']] ?? '#8a97a3') : '';
          $shot = shot_label($t['episode'] ?? '', $t['shot_code'] ?? '');
        ?>
          <div class="board-card priority-<?= e($t['priority']) ?> <?= $isOverdue ? 'overdue' : '' ?> <?= $isTicket ? 'is-ticket' : '' ?>"
               <?= can_edit() ? 'draggable="true"' : '' ?>
               data-id="<?= (int)$t['id'] ?>"
               data-type="<?= $isTicket ? 'ticket' : 'task' ?>"
               data-crew="<?= $t['assigned_crew_id'] ? (int)$t['assigned_crew_id'] : 'none' ?>"
               data-due="<?= e($t['due_date'] ?? '') ?>"
               ondragstart="dragCard(event, <?= (int)$t['id'] ?>)"
               onclick="openCard(<?= (int)$t['id'] ?>)">
            <?php if ($crewColor): ?>
              <div class="card-crew-bar" style="background:<?= e($crewColor) ?>;"></div>
            <?php endif; ?>
            <div class="card-ref">
              <span class="<?= $isTicket ? 'ref-ticket' : 'ref-task' ?>"><?= e(ticket_ref($t)) ?></span>
              <?php if ($offBoard): ?>
                <span class="off-board" title="This card's status is not one of the board columns"><?= e($t['status_name'] ?? 'no status') ?></span>
              <?php endif; ?>
            </div>
            <div class="card-title"><?= e($t['title']) ?></div>
            <?php if (!empty($t['description'])): ?>
              <div class="card-desc"><?= e(mb_strimwidth($t['description'], 0, 90, '…')) ?></div>
            <?php endif; ?>
            <?php if ($shot !== '' || !empty($t['asset_name']) || !empty($t['step_name'])
                      || ($t['about'] ?? '') === TICKET_ABOUT_PLATFORM): ?>
              <div class="card-context">
                <?php if ($shot !== ''): ?><span class="ctx-chip ctx-shot"><?= e($shot) ?></span><?php endif; ?>
                <?php if (!empty($t['asset_name'])): ?><span class="ctx-chip ctx-asset"><?= e($t['asset_name']) ?></span><?php endif; ?>
                <?php if (!empty($t['step_name'])): ?><span class="ctx-chip ctx-step"><?= e($t['step_name']) ?></span><?php endif; ?>
                <?php // Where the step chip would be, because it answers the same
                      // question: what is this card about? ?>
                <?php if (($t['about'] ?? '') === TICKET_ABOUT_PLATFORM): ?>
                  <span class="ctx-chip ctx-platform"><?= e(ticket_about_label(TICKET_ABOUT_PLATFORM)) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="card-meta">
              <?php if (!empty($t['crew_name'])): ?>
                <span class="card-owner" style="background:<?= e($crewColor) ?>1a;color:<?= e($crewColor) ?>;border-color:<?= e($crewColor) ?>44;">
                  <?= e($t['crew_name']) ?><?= (int)($t['is_placeholder'] ?? 0) === 1 ? ' ·' : '' ?>
                </span>
              <?php endif; ?>
              <?php if ($t['due_date']): ?>
                <span class="card-due <?= $isOverdue ? 'is-overdue' : '' ?>"><?= date('M j', strtotime($t['due_date'])) ?></span>
              <?php endif; ?>
              <?php if ((int)($t['attachment_count'] ?? 0) > 0): ?>
                <span class="card-badge" title="Attachments">📎<?= (int)$t['attachment_count'] ?></span>
              <?php endif; ?>
              <?php if ((int)($t['comment_count'] ?? 0) > 0): ?>
                <span class="card-badge" title="Comments">💬<?= (int)$t['comment_count'] ?></span>
              <?php endif; ?>
              <span class="prio-dot priority-<?= e($t['priority']) ?>" title="<?= e(ucfirst($t['priority'])) ?> priority"></span>
            </div>
            <?php if ($showArchived): ?>
              <div class="card-archive-row" onclick="event.stopPropagation();">
                <span class="muted small">Finished <?= $t['completed_at'] ? date('M j', strtotime($t['completed_at'])) : '—' ?></span>
                <span>
                  <?php if (can_edit()): ?>
                  <form method="post" style="display:inline;">
                    <button type="submit" name="restore" value="<?= (int)$t['id'] ?>" class="secondary">Restore</button>
                  </form>
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('Delete this permanently? Its comments and attachments go too.');">
                    <input type="hidden" name="from_archive" value="1">
                    <button type="submit" name="delete" value="<?= (int)$t['id'] ?>" class="danger">Delete</button>
                  </form>
                  <?php endif; ?>
                </span>
              </div>
            <?php elseif ($showSnoozed): ?>
              <div class="card-archive-row" onclick="event.stopPropagation();">
                <span class="muted small">Back <?= ($t['snoozed_until'] ?? null) ? date('M j, Y', strtotime($t['snoozed_until'])) : '—' ?></span>
                <?php if (can_edit()): ?>
                <span>
                  <form method="post" style="display:inline;">
                    <button type="submit" name="unsnooze" value="<?= (int)$t['id'] ?>" class="secondary">Un-snooze</button>
                  </form>
                </span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="muted small">
  Columns are this project's own statuses, so the words on this board are the same ones the
  shots and assets use. Change which appear on the
  <a href="statuses_edit.php?project=<?= $projectId ?>">Statuses screen</a>.
  Finished cards archive themselves after <?= $archiveDays ?> day<?= $archiveDays === 1 ? '' : 's' ?>.
</p>

<?php if (can_edit() && $columns): ?>
<!-- New task / ticket -->
<div id="createModal" class="modal" style="display:none;">
  <div class="modal-box">
    <h3 id="createTitle">New task</h3>
    <form method="post" enctype="multipart/form-data" id="createForm"
          data-ticket-form data-redirect="<?= e($selfUrl) ?>">
      <input type="hidden" name="create" value="1">
      <input type="hidden" name="type" id="createType" value="task">

      <label>Title
        <input name="title" id="createTitleField" required placeholder="What needs doing?">
      </label>
      <label>Description
        <textarea name="description" id="createDesc" rows="3" placeholder="Details, links, notes…"></textarea>
      </label>

      <!-- What the card is about. Shown for tickets; a plain task rarely needs it. -->
      <div class="ticket-only" style="display:none;">
        <div class="modal-row">
          <label>Shot <span class="muted small">(type to search)</span>
            <input name="shot_label" list="shotList" autocomplete="off"
                   placeholder="<?= $episodic ? 'e.g. EP002_SH050' : 'e.g. SH050' ?>">
            <datalist id="shotList">
              <?php foreach ($shotOptions as $s): ?><option value="<?= e($s) ?>"></option><?php endforeach; ?>
            </datalist>
          </label>
          <label>Asset <span class="muted small">(type to search)</span>
            <input name="asset_label" list="assetList" autocomplete="off" placeholder="e.g. Romulan Officer">
            <datalist id="assetList">
              <?php foreach ($assetOptions as $a): ?><option value="<?= e($a) ?>"></option><?php endforeach; ?>
            </datalist>
          </label>
        </div>
        <label>Pipeline step <span class="muted small">(which part of the pipeline this is about)</span>
          <?php // One control, two meanings — a step id, or "the app itself".
                // ticket_step_options() draws it for this form and for the ticket
                // page, so the two cannot drift about what is on offer. ?>
          <select name="step_id">
            <?= ticket_step_options($steps, null, '', ticket_about_available($pdo)) ?>
          </select>
        </label>
      </div>

      <div class="modal-row">
        <label>Status
          <select name="status_id">
            <?php foreach ($columns as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
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
        <label>Owner <span class="req-mark ticket-only" style="display:none;">*</span>
          <select name="assigned_crew_id" id="createCrew">
            <option value="">nobody</option>
            <?php foreach ($assignees as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e(assignee_label($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Due date
          <input type="date" name="due_date">
        </label>
      </div>

      <label>Attachments
        <input type="file" name="attachments[]" multiple data-filepicker
               accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,video/webm,application/pdf">
        <small>Up to <?= (int)($config['upload_max_files'] ?? 5) ?> files · images, video or PDF ·
               max <?= human_size((int)($config['upload_max_bytes'] ?? 26214400)) ?> each</small>
      </label>
      <div class="file-list" data-filelist></div>

      <div class="upload-progress" data-progress style="display:none;">
        <div class="progress-track"><div class="progress-fill"></div></div>
        <div class="progress-label muted small">Preparing…</div>
      </div>

      <p class="form-error" id="createError" style="display:none;"></p>

      <div class="actions">
        <button type="submit">Create</button>
        <button type="button" class="secondary" onclick="closeCreate()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Quick edit, for plain tasks. Tickets open their own page, where the thread lives. -->
<div id="editModal" class="modal" style="display:none;">
  <div class="modal-box">
    <h3>Edit task</h3>
    <form id="editForm">
      <input type="hidden" id="editId">
      <label>Title <input id="editTitle" required></label>
      <label>Description <textarea id="editDesc" rows="3"></textarea></label>
      <div class="modal-row">
        <label>Status
          <select id="editStatus">
            <?php foreach ($columns as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Priority
          <select id="editPriority">
            <?php foreach (ticket_priorities() as $p): ?>
              <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="modal-row">
        <label>Owner
          <select id="editCrew">
            <option value="">nobody</option>
            <?php foreach ($assignees as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e(assignee_label($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Due date <input type="date" id="editDue"></label>
      </div>
      <p class="form-error" id="editError" style="display:none;"></p>
      <div class="actions">
        <button type="submit">Save</button>
        <button type="button" class="secondary" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
        <span class="spacer"></span>
        <a id="editOpenLink" class="muted small" href="#">Open the full page →</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Every card on screen, so the quick-edit modal can fill itself without a round
// trip. Built from the same rows the board rendered, so the two cannot disagree.
//
// JSON_HEX_TAG matters and is not decoration: a card description holding a
// closing script tag would otherwise end this block, and everything after it
// would be parsed as page text. Titles and descriptions are typed by people, and
// one of them will eventually paste markup. The other three flags close the same
// door for quotes and ampersands.
//
// NOTE FOR ANYONE EDITING THIS COMMENT: do not write that tag out literally,
// even inside a // comment. The HTML parser looks for the character sequence and
// does not care that JavaScript considers it commented out, it ends the script
// element on the spot. This exact comment shipped with the tag spelled out and
// dumped the rest of the page to the screen as text. Identical in kind to
// lesson 9 about '?>' inside a PHP comment: the parser acts on the characters,
// not on your intent.
// EVERY ONE OF THESE IS `window.x =`, NOT `const x =`. A top-level `const` (or
// `let`) does NOT become a property of window, only `var` and a plain
// assignment do. board.js reads them as window.CARDS / window.CAN_EDIT, so
// declaring them with `const` left it seeing undefined: canEdit came out false,
// dragCard bailed on its first line, and the board silently would not drag.
// Nothing errored, which is what made it hard to see. Shipped that way once.
<?php $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
window.CARDS = <?= json_encode(array_values(array_merge(...array_values($cards ?: [[]]))), $jsonFlags) ?>;
window.PROJECT_URL = <?= json_encode($selfUrl, $jsonFlags) ?>;
window.CAN_EDIT = <?= can_edit() ? 'true' : 'false' ?>;
// Read by tickets.js as it initialises, so these MUST be set before that file
// loads, not after it, which is a silent no-op that falls back to the defaults.
window.TICKET_MAX_FILES = <?= (int)($config['upload_max_files'] ?? 5) ?>;
window.TICKET_MAX_BYTES = <?= (int)($config['upload_max_bytes'] ?? 26214400) ?>;
</script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<script src="assets/board.js<?= asset_ver('board.js') ?>"></script>
</div>
</body></html>
