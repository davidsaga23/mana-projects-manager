<?php
// THE BOARD — the screen that replaces Plaky.
//
// David, 2026-10-22: "we need a new screen that looks like Plaky or Monday
// where we and the artists can see everything", reached from "a big button at
// the top ... so you don't have one per project and just one place to take a
// look at the whole thing". So this is a top-bar destination with a show
// picker, NOT a tab inside one project.
//
// ⚠ IT IS A VIEW OVER ROWS THAT ALREADY EXIST. includes/board.php says which,
// and why that matters on the day Plaky is switched off. Nothing here is a
// second copy of a shot, a step or a status.
//
// Everything saves in place through board_api.php. Not one form on this page
// posts: a board people live in all day cannot reload between every status.
//
// Read by everybody who can log in. An artist sees the shows they are staffed
// on (board_projects() scopes it) and may set the status of their own work to
// In progress or Pending review — the same limit My tasks keeps, enforced in
// includes/statuses.php and again in the API.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/board.php';
require_login();

$projects = board_projects($pdo);

// Which show. A remembered choice would be one more thing to be wrong, so the
// address carries it and the first show is the default.
$projectId = (int)($_GET['project'] ?? 0);
$project = null;
foreach ($projects as $p) if ((int)$p['id'] === $projectId) $project = $p;
if (!$project && $projects) $project = $projects[0];
$projectId = $project ? (int)$project['id'] : 0;

$board = $projectId ? board_data($pdo, $projectId) : ['groups' => [], 'statuses' => [], 'counts' => []];
$crew  = $projectId ? board_assignees($pdo, $projectId) : [];
$linkReady = column_exists($pdo, 'progress', 'link');

// WHO MAY CHANGE WHAT. Written once and read by every control below, so the
// screen and the API cannot disagree about who is allowed.
$mayEdit   = can_edit();
$myCrewId  = (int)(effective_crew_id($pdo) ?? 0);
$maySetAny = $mayEdit;

// ⚠ EVERY SHOT STARTS SHUT (David, 2026-10-22, seeing it on the live site:
// "let's have the items ... always closed by default"). Opened, MSD alone is
// 150 task rows, and the thing somebody opens this screen to do is find ONE
// shot — not read every task on the show. The list of shots is the index;
// opening one is the question being asked.
//
// Every task row is still RENDERED and hidden by class, so expanding fetches
// nothing. That is cheap at MSD's size and is where a 188-shot show would start
// to want its tasks loaded on demand instead.
$openByDefault = false;

$pageTitle = 'Spell';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$projects): ?>
  <div class="empty-state">
    <h2>No show to put on Spell</h2>
    <p>The board reads shows that are in setup, active or on hold.
      <?php if (!$mayEdit): ?>You see the shows you are staffed on.<?php endif; ?></p>
  </div>
<?php else: ?>

<div class="board-head">
  <div class="board-shows" role="group" aria-label="Show">
    <?php foreach ($projects as $p): ?>
      <a class="board-chip <?= (int)$p['id'] === $projectId ? 'is-on' : '' ?>"
         href="spell.php?project=<?= (int)$p['id'] ?>"><?= e($p['code'] ?: $p['name']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="board-title">
  <div>
    <h2><?= e($project['name']) ?></h2>
    <p class="muted small">
      <?= (int)($board['counts']['items'] ?? 0) ?> shots and assets ·
      <?= (int)($board['counts']['tasks'] ?? 0) ?> tasks ·
      <?= (int)($board['counts']['done'] ?? 0) ?> finished
      <?php if (!$mayEdit): ?> · you can set your own work to In progress or Pending review<?php endif; ?>
    </p>
  </div>
  <div class="board-tools">
    <?php // aria-label rather than a hidden <span>: this codebase has no
          // visually-hidden class, and inventing one for a single box is how a
          // stylesheet grows a rule nobody else ever uses. ?>
    <span class="board-search">
      <input type="search" id="boardSearch" aria-label="Search this board"
             placeholder="Search shots, tasks, people">
    </span>
  </div>
</div>

<?php // THE WHOLE SHOW IN ONE BAR (David, 2026-10-22), the full width of the
      // screen: the same reckoning a group's bar makes, one level up. It reads
      // the same way and answers the same hover. ?>
<?= board_bar_html($board['bar'] ?? [], 'board-bar-project') ?>

<?php if (!$linkReady): ?>
  <p class="flash flash-warn">
    The Link column needs database update <code>057</code>, which has not been applied yet.
    Everything else on this board works.
    <?php if (is_admin()): ?><a href="migrate.php">Review and apply it →</a><?php endif; ?>
  </p>
<?php endif; ?>

<?php if (!$board['groups']): ?>
  <div class="empty-state">
    <h2>Nothing to show yet</h2>
    <p>This show has no shots or assets, or no pipeline step covers them.
      <a href="shots.php?project=<?= $projectId ?>">Add the breakdown</a> and they appear here.</p>
  </div>
<?php else: ?>

<?php // ⚠ THE HEADINGS AND THE FILTERS STICK TOGETHER (David, 2026-10-22). On a
      // 21-shot show expanded this is 170 rows; by the time you have scrolled to
      // SH150 the column you are reading has no heading on it, and the filter
      // that got you there has scrolled away too. Both ride at the top. ?>
<div class="board-sticky">
  <div class="board-grid-head">
    <div>Shot and task</div>
    <?php // Unlabelled on purpose: the button says what it does, and "URL" over
          // a column of buttons reading "Copy URL" is the same word twice. ?>
    <div></div>
    <div>Version</div>
    <div class="board-head-center">Notes</div>
    <div>Status</div>
    <div>Assignee</div>
    <div>Due</div>
    <div class="num">Frames</div>
    <div>Link</div>
    <div>Notes</div>
  </div>

  <?php // Filters on the three columns worth filtering. Frames belongs to the
        // shot, and Link and Notes are filled on 6 and 2 rows of MSD's 147, so
        // a dropdown on either would be furniture. ?>
  <div class="board-filters">
    <?php // ⚠ THE FIRST CELL CARRIES THREE THINGS, because the filter bar
          // lines up ONLY while its children land in the same grid tracks as
          // the headings above. Step and Ready match no column — they narrow
          // which rows are shown rather than filter one column — so they ride
          // here under "Shot and task" instead of taking cells of their own,
          // which is what pushed Due under Link when they were first added. ?>
    <div class="board-filter-lead">
      <div class="board-filter-count" id="boardCount"></div>
      <?php // A supervisor's two (David, during the design: "it's still useful
            // if I'm a supervisor taking a look at what shots/assets are ready
            // for my department"). Step picks the department; Ready narrows to
            // the work that can actually start today. ?>
      <select id="fStep" aria-label="Filter by pipeline step">
        <option value="">Any step</option>
        <?php foreach ($board['steps'] as $st): ?>
          <option value="<?= (int)$st['id'] ?>"><?= e($st['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fReady" aria-label="Filter by whether the work can start">
        <option value="">All tasks</option>
        <option value="1">Ready to start</option>
      </select>
    </div>
    <?php // Copy URL, Version and Notes have nothing to filter. ?>
    <div></div>
    <div></div>
    <div></div>
    <div>
      <select id="fStatus" aria-label="Filter by status">
        <option value="">Any status</option>
        <option value="__none">Not started</option>
        <?php foreach ($board['statuses'] as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <select id="fCrew" aria-label="Filter by who it is assigned to">
        <option value="">Anybody</option>
        <option value="__none">Nobody yet</option>
        <?php foreach ($crew as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="board-due-filter">
      <select id="fDue" aria-label="Filter by due date">
        <option value="">Any date</option>
        <option value="late">Overdue</option>
        <option value="today">Today</option>
        <option value="yesterday">Yesterday</option>
        <option value="week">This week, Mon to Fri</option>
        <option value="soon">Due in the next 7 days</option>
        <option value="on">A specific date…</option>
        <option value="range">A range…</option>
        <option value="set">Has a date</option>
        <option value="__none">No date</option>
      </select>
      <?php // ⚠ ONLY SHOWN ONCE THEY ARE ASKED FOR. Two date boxes sitting
            // empty in the filter bar read as something you have to fill in
            // before the board will answer. ?>
      <span class="board-due-dates" id="fDueOn" hidden>
        <input type="date" id="fDueOnDate" aria-label="Due on this date">
      </span>
      <span class="board-due-dates" id="fDueRange" hidden>
        <input type="date" id="fDueFrom" aria-label="Due from">
        <input type="date" id="fDueTo" aria-label="Due until">
      </span>
    </div>
    <?php // ⚠ EXPAND AND COLLAPSE RIDE WITH THE FILTERS (David, 2026-10-22).
          // They sat above the sticky block, so on a long board they scrolled
          // away exactly when a wall of open shots made them wanted. They span
          // the three columns that have nothing to filter. ?>
    <div class="board-filter-actions">
      <button type="button" id="boardOpenAll">Expand all</button>
      <button type="button" id="boardShutAll">Collapse all</button>
      <button type="button" class="board-filter-clear" id="boardClear" hidden>Clear</button>
    </div>
  </div>
</div>

<?php foreach ($board['groups'] as $g): ?>
  <section class="board-group" data-group="<?= e($g['key']) ?>">
    <div class="board-group-head">
      <button type="button" class="board-caret is-open" aria-expanded="true"
              aria-label="Collapse <?= e($g['name']) ?>">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      </button>
      <span class="board-group-label">
        <span class="board-dot" style="background:<?= e($g['color']) ?>"></span>
        <strong><?= e($g['name']) ?></strong>
        <?php // The episode, or the asset type: what divides this phase, beside
              // the phase rather than instead of it. ?>
        <?php if (($g['detail'] ?? '') !== ''): ?>
          <span class="board-group-detail"><?= e($g['detail']) ?></span>
        <?php endif; ?>
        <span class="muted small"><?= count($g['items']) ?> item<?= count($g['items']) === 1 ? '' : 's' ?></span>
      </span>

      <?php // The whole phase at a glance, in the same colours and the same
            // order as the shots underneath it, built from every task in the
            // group rather than its shots' bars averaged.
            echo board_bar_html($g['bar'] ?? [], 'board-bar-group'); ?>

      <?php // ⚠ PER GROUP, BESIDE THE WHOLE-BOARD BUTTONS (David's choice,
            // 2026-10-22). The arrow to the left hides the group; it cannot
            // open the TASKS of the shots inside it, and on a show with
            // episodes that is the thing you actually want — every shot of
            // EP02 open, the rest of the board left alone. ?>
      <span class="board-group-menu">
        <button type="button" class="board-group-menu-btn" aria-haspopup="true" aria-expanded="false"
                aria-label="Expand or collapse everything in <?= e(trim($g['name'] . ' ' . ($g['detail'] ?? ''))) ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
        </button>
        <span class="board-group-pop" hidden>
          <button type="button" data-act="open">Expand everything in <?= e(trim($g['name'] . ' ' . ($g['detail'] ?? ''))) ?></button>
          <button type="button" data-act="shut">Collapse everything in <?= e(trim($g['name'] . ' ' . ($g['detail'] ?? ''))) ?></button>
        </span>
      </span>
    </div>

    <div class="board-rows">
      <?php foreach ($g['items'] as $item): ?>
        <?php
        // A whole-show deliverable IS its own task row, so it carries the
        // controls itself and has nothing nested under it.
        $self = $item['self'];
        $open = $openByDefault && $item['tasks'];
        ?>
        <div class="board-item<?= $open ? ' is-open' : '' ?>" data-item="<?= e($item['item_key']) ?>">
          <div class="board-row board-row-item"
               data-search="<?= e(strtolower($item['name'])) ?>">
            <div class="board-cell-name">
              <?php if ($item['tasks']): ?>
                <button type="button" class="board-caret" aria-expanded="<?= $open ? 'true' : 'false' ?>"
                        aria-label="Show the tasks on <?= e($item['name']) ?>">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
              <?php else: ?>
                <span class="board-caret board-caret-blank" aria-hidden="true"></span>
              <?php endif; ?>
              <?php // ⚠ ONE FIXED-WIDTH BLOCK FOR THE NAME AND ITS COUNT, so the
                    // bars line up down the column however long the names are
                    // (David, 2026-10-22: MadridStreetExt03 pushed its bar half
                    // an inch right of FutsalInt01's). A name longer than the
                    // block is clipped with an ellipsis and keeps its full text
                    // in the title. ?>
              <span class="board-item-label">
                <?php if ($item['kind'] === 'project'): ?>
                  <span class="board-name" title="<?= e($item['name']) ?>"><?= e($item['name']) ?></span>
                <?php else: ?>
                  <a class="board-name" title="<?= e($item['name']) ?>"
                     href="item.php?project=<?= $projectId ?>&amp;item=<?= e(urlencode($item['item_key'])) ?>"><?= e($item['name']) ?></a>
                <?php endif; ?>
                <span class="muted small board-item-scope"><?= e($item['scope']) ?></span>
              </span>

              <?php // HOW FAR ALONG, in the colours of its own tasks' statuses
                    // (David, 2026-10-22). Sits in the space the shot's name
                    // leaves, fills from the left as work finishes, and says how
                    // many of each on hover. A deliverable has no bar: it is one
                    // task, and a bar of one segment says nothing a status does not.
                    echo board_bar_html($item['bar'] ?? []); ?>
            </div>

            <?php if ($self): ?>
              <?php // The deliverable's own controls, the same ones a task row carries. ?>
              <?= board_copy_cell($self, $projectId, (string)$item['name']) ?>
              <?= board_version_cell($self) ?>
              <?= board_notes_cell($self, $projectId, (string)$item['name']) ?>
              <?= board_status_cell($self, $board['statuses'], $projectId, $maySetAny,
                                    $myCrewId, $mayEdit) ?>
              <?= board_crew_cell($self, $crew, $projectId, $mayEdit) ?>
              <?= board_due_cell($self, $projectId, $mayEdit) ?>
              <div class="board-cell num muted">—</div>
              <?= board_text_cell($self, 'link', $projectId, $mayEdit && $linkReady, 'https://…') ?>
              <?= board_text_cell($self, 'notes', $projectId, $mayEdit, 'Notes') ?>
            <?php else: ?>
              <?php // A shot is not a task and has no task URL. Its own page is
                    // a click away on its name, which is a link already. ?>
              <div class="board-cell"></div>
              <div class="board-cell"></div>
              <div class="board-cell"></div>
              <?php // Read-only, so it is text with a dot and never a control. ?>
              <div class="board-cell">
                <span class="board-derived">
                  <span class="board-dot" style="background:<?= e($item['status']['color']) ?>"></span>
                  <span style="color:<?= e($item['status']['color']) ?>"><?= e($item['status']['name']) ?></span>
                </span>
              </div>
              <div class="board-cell"></div>
              <div class="board-cell"></div>
              <div class="board-cell num">
                <?= $item['frames'] !== null ? e(number_format($item['frames'])) : '<span class="muted">—</span>' ?>
              </div>
              <div class="board-cell"></div>
              <?php // The shot's or asset's OWN note, about the thing itself
                    // rather than about one step's work on it. ?>
              <?= board_item_note_cell($item, $projectId, $mayEdit) ?>
            <?php endif; ?>
          </div>

          <?php foreach ($item['tasks'] as $t): ?>
            <?php $mine = $t['crew_id'] !== null && $t['crew_id'] === $myCrewId; ?>
            <?php // What the filters read. On the ROW, not dug out of the
                  // controls inside it, so a row filters the same whether its
                  // cells are editable selects or plain text for an artist. ?>
            <div class="board-row board-row-task"
                 data-search="<?= e(strtolower($item['name'] . ' ' . $t['step'] . ' ' . ($t['person'] ?? ''))) ?>"
                 data-status="<?= $t['status_id'] !== null ? (int)$t['status_id'] : '__none' ?>"
                 data-crew="<?= $t['crew_id'] !== null ? (int)$t['crew_id'] : '__none' ?>"
                 data-step-id="<?= (int)$t['step_id'] ?>"
                 <?php // Worked out in pipeline order by board_data(): nothing
                       // before it outstanding, and nobody started it yet. ?>
                 data-ready="<?= !empty($t['ready']) ? '1' : '0' ?>"
                 data-due="<?= e((string)($t['due'] ?? '')) ?>">
              <div class="board-cell-name board-task-name">
                <span class="board-dot board-dot-step" style="background:<?= e($t['step_color'] ?: 'var(--step-default)') ?>"></span>
                <?php // ⚠ A REAL BUTTON, not a div that listens for clicks: it
                      // is reached by Tab, pressed with the keyboard and read
                      // out as a button. It opens the panel over the board. ?>
                <?php // ⚠ IT WEARS THE STEP'S OWN COLOUR, the one set on the
                      // pipeline in setup and drawn on the timeline (David,
                      // 2026-10-22). Without a style of its own it inherited the
                      // app's filled blue button, so every task name on the
                      // board was an identical blue blob — and the one thing
                      // this column could say at a glance, which department a
                      // row belongs to, was thrown away.
                      //
                      // Flattening it to plain text fixed the blobs and lost the
                      // button: "bring back the buttons, what you need to change
                      // are the colors of the buttons not remove them". So it is
                      // tinted exactly as the status beside it is, and the two
                      // read as a pair.
                      //
                      // ⚠ THE COLOUR IS WRITTEN HERE AND NOT IN THE STYLESHEET,
                      // the one exception lesson 21 allows and the same one
                      // board_status_cell() takes: a step's colour is per
                      // project, so no rule could ever know it.
                      $sc = trim((string)($t['step_color'] ?? '')); ?>
                <button type="button" class="board-open-task"<?php if ($sc !== ''): ?>
                        style="color:<?= e($sc) ?>;background:<?= e(board_tint($sc)) ?>;border-color:<?= e(board_tint($sc, 0.4)) ?>"<?php endif; ?>
                        data-project="<?= $projectId ?>" data-step="<?= (int)$t['step_id'] ?>"
                        data-item="<?= e($t['item_key']) ?>"
                        aria-label="Open <?= e($t['step']) ?> on <?= e($item['name']) ?>"><?= e($t['step']) ?></button>
              </div>
              <?= board_copy_cell($t, $projectId, (string)$item['name']) ?>
              <?= board_version_cell($t) ?>
              <?= board_notes_cell($t, $projectId, (string)$item['name']) ?>
              <?= board_status_cell($t, $board['statuses'], $projectId, $mayEdit || $mine,
                                    $myCrewId, $mayEdit) ?>
              <?= board_crew_cell($t, $crew, $projectId, $mayEdit) ?>
              <?= board_due_cell($t, $projectId, $mayEdit) ?>
              <div class="board-cell num muted">—</div>
              <?= board_text_cell($t, 'link', $projectId, $mayEdit && $linkReady, 'https://…') ?>
              <?= board_text_cell($t, 'notes', $projectId, $mayEdit, 'Notes') ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<?php // THE PANEL. One on the page, filled from board_task.php when something
      // is opened — so there is nothing to keep in step with 150 rows, and the
      // markup for a task lives in one file. ?>
<div class="board-panel-veil" id="boardVeil" hidden></div>
<aside class="board-panel" id="boardPanel" role="dialog" aria-modal="true"
       aria-label="Task details" hidden>
  <button type="button" class="board-panel-close" id="boardPanelClose" aria-label="Close">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.1" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
  </button>
  <div class="board-panel-inner" id="boardPanelInner"></div>
</aside>

<p class="muted small board-foot">
  A shot's status comes from its tasks. Due dates set here also move the
  <a href="timeline.php?project=<?= $projectId ?>">Timeline</a>.
</p>

<?php endif; ?>

<?php // The @ picker, shared with the ticket screen: the Comments tab in the
      // task panel uses the same one rather than a second of its own. ?>
<script src="assets/mentions.js<?= asset_ver('mentions.js') ?>"></script>
<?php // "Drop files here" over every native file picker in the task panel
      // (David, 2026-10-23). It enhances the real input and leaves what the
      // form submits exactly as it was. ?>
<script src="assets/dropzone.js<?= asset_ver('dropzone.js') ?>"></script>
<?php // ⚠ FOR THE LIGHTBOX AND NOTHING ELSE. assets/tickets.js carries two
      // things: an uploader that only wakes for form[data-ticket-form] (none
      // here), and the attachment lightbox, which is delegated from `document`
      // and so works on a version thumbnail that arrives with the panel. David
      // asked to "open a preview"; this is the preview the ticket screen has
      // had since the start, rather than a second one written for this tab. ?>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<script src="assets/board-screen.js<?= asset_ver('board-screen.js') ?>"></script>
<script src="assets/review-files.js<?= asset_ver('review-files.js') ?>"></script>
<?php // The review viewer: an image version full screen with a pen (David,
      // 2026-10-27). Built from the Versions pane board-screen.js keeps fresh. ?>
<script src="assets/review-viewer.js<?= asset_ver('review-viewer.js') ?>"></script>
<?php endif; ?>

</div>
</body></html>
