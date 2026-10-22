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

$pageTitle = 'Board';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$projects): ?>
  <div class="empty-state">
    <h2>No show to put on a board</h2>
    <p>The board reads shows that are in setup, active or on hold.
      <?php if (!$mayEdit): ?>You see the shows you are staffed on.<?php endif; ?></p>
  </div>
<?php else: ?>

<div class="board-head">
  <div class="board-shows" role="group" aria-label="Show">
    <?php foreach ($projects as $p): ?>
      <a class="board-chip <?= (int)$p['id'] === $projectId ? 'is-on' : '' ?>"
         href="board.php?project=<?= (int)$p['id'] ?>"><?= e($p['code'] ?: $p['name']) ?></a>
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
    <button type="button" class="secondary" id="boardOpenAll">Expand all</button>
    <button type="button" class="secondary" id="boardShutAll">Collapse all</button>
  </div>
</div>

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
    <div class="board-filter-count" id="boardCount"></div>
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
    <div>
      <select id="fDue" aria-label="Filter by due date">
        <option value="">Any date</option>
        <option value="late">Overdue</option>
        <option value="soon">Due in the next 7 days</option>
        <option value="set">Has a date</option>
        <option value="__none">No date</option>
      </select>
    </div>
    <div></div>
    <div></div>
    <div><button type="button" class="board-filter-clear" id="boardClear" hidden>Clear</button></div>
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
              <?= board_status_cell($self, $board['statuses'], $projectId, $maySetAny,
                                    $myCrewId, $mayEdit) ?>
              <?= board_crew_cell($self, $crew, $projectId, $mayEdit) ?>
              <?= board_due_cell($self, $projectId, $mayEdit) ?>
              <div class="board-cell num muted">—</div>
              <?= board_text_cell($self, 'link', $projectId, $mayEdit && $linkReady, 'https://…') ?>
              <?= board_text_cell($self, 'notes', $projectId, $mayEdit, 'Notes') ?>
            <?php else: ?>
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
              <div class="board-cell"></div>
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
                 data-due="<?= e((string)($t['due'] ?? '')) ?>">
              <div class="board-cell-name board-task-name">
                <span class="board-dot board-dot-step" style="background:<?= e($t['step_color'] ?: 'var(--step-default)') ?>"></span>
                <span><?= e($t['step']) ?></span>
              </div>
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

<p class="muted small board-foot">
  Shots, tasks and statuses are the same rows the rest of Anima reads. Frames come from the
  <a href="shots.php?project=<?= $projectId ?>">breakdown</a>, a shot's own status is worked out
  from the tasks under it, and a due date typed here is the date the
  <a href="timeline.php?project=<?= $projectId ?>">timeline</a> draws.
</p>

<?php endif; ?>

<script src="assets/board-screen.js<?= asset_ver('board-screen.js') ?>"></script>
<?php endif; ?>

</div>
</body></html>
