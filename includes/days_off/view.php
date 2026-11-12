<?php
// The DAYS OFF tab's body, drawn by bookings.php after includes/days_off/prepare.php
// has worked out every variable used here. See that file for the why.
?>
<link rel="stylesheet" href="assets/days_off.css<?= asset_ver('days_off.css') ?>">

<p class="do-lead"><strong><?= e($first->format('F Y')) ?>.</strong>
  <?php if ($mayEdit): ?>
    Every person's days away, a month at a time. <strong>Drag across a person's days</strong> to add
    days off, or <strong>click a day off</strong> to change or remove it. Public holidays belong on the
    studio <a href="calendar.php">Calendar</a>, which closes the day for everyone; they show here in grey.
    The scheduler plans around these days <strong>the next time the schedule is run</strong>, and a
    day off is never counted as a missing time log.
  <?php elseif ($seeAll): ?>
    Every person's days away, a month at a time. Production enters them; ask them if one is missing
    or wrong.
  <?php else: ?>
    Your days away, a month at a time. A day off is never counted as a missing time log. Production
    enters them; ask them to add or change one.
  <?php endif; ?>
</p>

<?php if (!$available): ?>
  <div class="empty-state">
    <h2>Not switched on yet</h2>
    <p>The database update for days off (migration 060) has not been applied yet.
      <?php if (is_admin()): ?><a href="migrate.php">Apply it on Migrations</a>.<?php endif; ?></p>
  </div>
<?php else: ?>

<form method="get" action="bookings.php" class="do-toolbar">
  <input type="hidden" name="tab" value="days-off">
  <span class="do-nav">
    <a class="button secondary zoom-btn" href="<?= e($selfQuery(['m' => $prevMonth])) ?>" title="Previous month">‹</a>
    <a class="button secondary" href="<?= e($selfQuery(['m' => date('Y-m')])) ?>">Today</a>
    <a class="button secondary zoom-btn" href="<?= e($selfQuery(['m' => $nextMonth])) ?>" title="Next month">›</a>
    <input type="month" name="m" value="<?= e($month) ?>" aria-label="Month" onchange="this.form.submit()">
  </span>
  <?php if ($seeAll): ?>
    <label class="inline">Show
      <select name="show" onchange="this.form.submit()">
        <option value="any"<?= $show === 'any' ? ' selected' : '' ?>>Everybody on a show</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>"<?= $show === (string)$p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option>
        <?php endforeach; ?>
        <option value="all"<?= $show === 'all' ? ' selected' : '' ?>>Everybody in Anima</option>
      </select></label>
    <?php if ($hasDept): ?>
      <label class="inline">Department
        <select name="dept" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach (crew_departments($pdo) as $dname): ?>
            <option value="<?= e($dname) ?>"<?= $dept === $dname ? ' selected' : '' ?>><?= e($dname) ?></option>
          <?php endforeach; ?>
        </select></label>
    <?php endif; ?>
  <?php endif; ?>
  <noscript><button type="submit" class="secondary">Show</button></noscript>
  <span class="do-legend">
    <span class="do-key do-k-holiday"></span>Holiday
    <span class="do-key do-k-sick"></span>Sick
    <span class="do-key do-k-personal"></span>Personal
    <span class="do-key do-k-course"></span>Course
    <span class="do-key do-k-other"></span>Other
    <span class="do-key do-k-closed"></span>Studio closed
  </span>
</form>

<?php if (!$people): ?>
  <div class="empty-state">
    <?php if (!$seeAll): ?>
      <h2>No person linked to your login</h2>
      <p>Your login is not linked to a person in Anima yet, so there are no days off to show. Ask Production.</p>
    <?php else: ?>
      <h2>Nobody to show</h2>
      <p>Nobody matches these filters. Try <a href="<?= e($selfQuery(['show' => 'all', 'dept' => ''])) ?>">everybody in Anima</a>.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="do-wrap">
  <table class="do-grid<?= $mayEdit ? ' is-editable' : '' ?>" id="doGrid">
    <thead>
      <tr>
        <th class="do-name">Person</th>
        <?php foreach ($days as $d): ?>
          <th class="do-day<?= $d['weekend'] || $d['holiday'] ? ' is-closed' : '' ?><?= $d['today'] ? ' is-today' : '' ?>"
              <?= $d['holiday'] ? 'title="' . e($d['holiday']) . '"' : '' ?>>
            <span class="do-dow"><?= e($d['dow']) ?></span><?= $d['num'] ?>
          </th>
        <?php endforeach; ?>
        <th class="do-total num" title="Working days off this month">Off</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($people as $p):
        $pid = (int)$p['id'];
        // date => the absence covering it (the latest-starting one, if two overlap)
        $byDay = [];
        foreach ($absences[$pid] ?? [] as $a) {
            for ($d = new DateTime(max($a['from'], $monthStart)), $i = 0;
                 $i < 40 && $d->format('Y-m-d') <= min($a['to'], $monthEnd); $d->modify('+1 day'), $i++) {
                $byDay[$d->format('Y-m-d')] = $a;
            }
        }
        $offCount = 0;
    ?>
      <tr data-crew="<?= $pid ?>" data-name="<?= e($p['name']) ?>">
        <th class="do-name" scope="row">
          <a href="person.php?id=<?= $pid ?>#days-off"><?= e($p['name']) ?></a>
          <?php $sub = trim(($hasDept ? (string)($p['department'] ?? '') : '') . (($p['role'] ?? '') !== '' ? ' · ' . $p['role'] : ''), ' ·'); ?>
          <?php if ($sub !== ''): ?><span class="do-sub"><?= e($sub) ?></span><?php endif; ?>
        </th>
        <?php foreach ($days as $d):
            $a = $byDay[$d['iso']] ?? null;
            $closed = $d['weekend'] || $d['holiday'];
            if ($a && !$closed) $offCount++;
            $cls = 'do-cell';
            if ($closed) $cls .= ' is-closed';
            if ($d['today']) $cls .= ' is-today';
            $title = $d['iso'];
            if ($a) {
                $cls .= ' is-off do-k-' . days_off_kind($a['reason']);
                // Where a run starts and ends inside the month, so it reads as one block.
                if ($d['iso'] === max($a['from'], $monthStart)) $cls .= ' is-start';
                if ($d['iso'] === min($a['to'], $monthEnd))     $cls .= ' is-end';
                $title .= ' · ' . ($a['reason'] !== '' ? $a['reason'] : 'Day off')
                        . ' (' . $a['from'] . ($a['to'] !== $a['from'] ? ' to ' . $a['to'] : '') . ')';
            } elseif ($d['holiday']) {
                $title .= ' · ' . $d['holiday'];
            }
        ?>
          <td class="<?= $cls ?>" data-day="<?= $d['iso'] ?>" title="<?= e($title) ?>"<?php if ($a): ?>
              data-absence="<?= (int)$a['id'] ?>" data-from="<?= e($a['from']) ?>" data-to="<?= e($a['to']) ?>"
              data-reason="<?= e($a['reason']) ?>"<?php endif; ?>></td>
        <?php endforeach; ?>
        <td class="do-total num"><?= $offCount ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php // THE SAME DAYS AS A LIST, under the grid: readable without hovering,
      // and the way to change one without a mouse. ?>
<?php
  $list = [];
  foreach ($people as $p) {
      foreach ($absences[(int)$p['id']] ?? [] as $a) $list[] = $a + ['crew_id' => (int)$p['id'], 'name' => $p['name']];
  }
  usort($list, function ($x, $y) { return [$x['from'], $x['name']] <=> [$y['from'], $y['name']]; });
?>
<div class="person-panel do-list">
  <h3>This month</h3>
  <?php if (!$list): ?>
    <p class="muted small">No days off in <?= e($first->format('F')) ?>.</p>
  <?php else: ?>
    <table class="review-table">
      <thead><tr><?php if ($seeAll): ?><th>Person</th><?php endif; ?><th>From</th><th>To</th>
        <th class="num">Working days</th><th>Reason</th><?php if ($mayEdit): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <?php if ($seeAll): ?><td><?= e($a['name']) ?></td><?php endif; ?>
          <td><?= e($a['from']) ?></td>
          <td><?= e($a['to']) ?></td>
          <td class="num"><?= working_days_between($a['from'], $a['to']) ?></td>
          <td><span class="do-chip do-k-<?= days_off_kind($a['reason']) ?>"><?= e($a['reason'] !== '' ? $a['reason'] : 'Day off') ?></span></td>
          <?php if ($mayEdit): ?>
            <td class="do-row-actions">
              <button type="button" class="linkish" data-edit="<?= (int)$a['id'] ?>" data-crew="<?= (int)$a['crew_id'] ?>"
                      data-name="<?= e($a['name']) ?>" data-from="<?= e($a['from']) ?>" data-to="<?= e($a['to']) ?>"
                      data-reason="<?= e($a['reason']) ?>">Change</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove these days off?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="crew_id" value="<?= (int)$a['crew_id'] ?>">
                <input type="hidden" name="absence_id" value="<?= (int)$a['id'] ?>">
                <?= days_off_keep_fields($month, $show, $dept) ?>
                <button type="submit" class="linkish">Remove</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($mayEdit): ?>
    <?php // Without dragging: the same save as the grid, for somebody who would
          // rather type the dates, or a range longer than the month. ?>
    <form method="post" class="stack do-add">
      <input type="hidden" name="action" value="add">
      <?= days_off_keep_fields($month, $show, $dept) ?>
      <div class="row">
        <label>Person
          <select name="crew_id" required>
            <option value="">Choose…</option>
            <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select></label>
        <label>First day <input type="date" name="from_date" required></label>
        <label>Last day <input type="date" name="to_date"></label>
        <label>Reason <input name="reason" maxlength="120" list="doReasons" placeholder="e.g. Holiday"></label>
      </div>
      <p class="muted small">Leave the last day blank for a single day.</p>
      <div class="actions"><button type="submit">Add days off</button></div>
    </form>
  <?php endif; ?>
</div>

<?php if ($mayEdit): ?>
  <datalist id="doReasons">
    <option value="Holiday"><option value="Sick"><option value="Personal"><option value="Course">
  </datalist>

  <?php // The pop-up the grid opens: one form for add, change and remove. ?>
  <div class="do-pop" id="doPop" hidden role="dialog" aria-labelledby="doPopTitle">
    <form method="post" class="stack" id="doPopForm">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="crew_id" value="">
      <input type="hidden" name="absence_id" value="">
      <?= days_off_keep_fields($month, $show, $dept) ?>
      <h3 id="doPopTitle">Days off</h3>
      <div class="row">
        <label>First day <input type="date" name="from_date" required></label>
        <label>Last day <input type="date" name="to_date"></label>
      </div>
      <div class="do-reasons" role="group" aria-label="Reason">
        <button type="button" class="do-chip do-k-holiday" data-reason="Holiday">Holiday</button>
        <button type="button" class="do-chip do-k-sick" data-reason="Sick">Sick</button>
        <button type="button" class="do-chip do-k-personal" data-reason="Personal">Personal</button>
        <button type="button" class="do-chip do-k-course" data-reason="Course">Course</button>
      </div>
      <label>Reason <input name="reason" maxlength="120" list="doReasons" placeholder="e.g. Holiday"></label>
      <div class="actions">
        <button type="submit" id="doPopSave">Add days off</button>
        <button type="button" class="secondary" id="doPopCancel">Cancel</button>
        <button type="button" class="row-btn-danger linkish" id="doPopDelete" hidden>Remove</button>
      </div>
    </form>
  </div>
  <script src="assets/days_off.js<?= asset_ver('days_off.js') ?>"></script>
<?php endif; ?>

<?php endif; // people ?>
<?php endif; // available ?>
