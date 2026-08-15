<?php
// Team performance — everyone on the show, and what they are carrying.
//
// The list; person.php is the detail. Ordered by unfinished work, because the
// people carrying the most are who a producer is looking for when they open this.
//
// What the numbers mean is set out in includes/people.php: assignments, time
// logged and approval counts are solid; anything about PACE is approximate,
// because history is recorded per step and not per person. That caveat is on
// screen as well as in the code, since a number in a table reads as a fact.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/timelog.php';
require_once __DIR__ . '/includes/people.php';
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

$team  = team_overview($pdo, $projectId);
$hours = hours_per_day($pdo, $projectId);

$totAssigned = 0; $totApproved = 0; $totMinutes = 0; $totBlocked = 0;
foreach ($team as $p) {
    $totAssigned += $p['summary']['assigned'];
    $totApproved += $p['summary']['approved'];
    $totMinutes  += $p['summary']['minutes'];
    $totBlocked  += $p['summary']['blocked'];
}
$placeholders = count(array_filter($team, fn($p) => (int)($p['is_placeholder'] ?? 0) === 1));

$pageTitle = 'Team performance · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'team'); ?>

<h2 class="wizard-title">Team performance <span class="muted">· <?= e($project['name']) ?></span></h2>

<p class="lead">
  Everyone with work on this show, ordered by how much of it is still unfinished.
  Click a name for their own page.
</p>

<?php if (!$team): ?>
  <div class="empty-state">
    <p><strong>Nobody has work on this project yet.</strong></p>
    <p class="muted">People appear here once they are on the crew, hold a planning slot, or
       have progress recorded against them by an import.</p>
    <p><a class="button" href="crew.php?project=<?= $projectId ?>">Open the Crew screen →</a></p>
  </div>
<?php else: ?>

<div class="dash-strip">
  <div><span class="muted small">People</span><strong><?= count($team) ?></strong>
    <?php if ($placeholders): ?><span class="muted small"><?= $placeholders ?> still a slot</span><?php endif; ?></div>
  <div><span class="muted small">Assigned tasks</span><strong><?= $totAssigned ?></strong></div>
  <div><span class="muted small">Approved</span><strong><?= $totApproved ?></strong>
    <span class="muted small">of <?= $totAssigned ?></span></div>
  <div><span class="muted small">Blocked</span>
    <strong class="<?= $totBlocked ? 'warn-text' : '' ?>"><?= $totBlocked ?></strong></div>
  <div><span class="muted small">Time logged</span><strong><?= e(fmt_hours($totMinutes)) ?></strong></div>
</div>

<table>
  <thead>
    <tr>
      <th>Person</th><th>Department</th><th>Role</th>
      <th class="num">Assigned</th><th class="num">Approved</th><th class="num">Remaining</th>
      <th class="num">Blocked</th><th class="num">Time logged</th><th>Planned to</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($team as $p): $s = $p['summary']; $isSlot = (int)($p['is_placeholder'] ?? 0) === 1; ?>
    <tr class="<?= $isSlot ? 'row-slot' : '' ?>">
      <td>
        <a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
        <?php if ($isSlot): ?><span class="slot-tag" title="A planning slot — nobody fills it yet">slot</span><?php endif; ?>
        <?php if ((int)($p['active'] ?? 1) !== 1): ?><span class="muted small">inactive</span><?php endif; ?>
      </td>
      <td class="muted"><?= e($p['department'] ?? '') ?: '<span class="muted">—</span>' ?></td>
      <td class="muted"><?= e($p['role'] ?? '') ?: '<span class="muted">—</span>' ?></td>
      <td class="num"><?= $s['assigned'] ?></td>
      <td class="num ok"><?= $s['approved'] ?></td>
      <td class="num"><?= $s['remaining'] ?></td>
      <td class="num <?= $s['blocked'] ? 'warn-text' : 'muted' ?>"><?= $s['blocked'] ?: '—' ?></td>
      <td class="num muted"><?= $s['minutes'] ? e(fmt_hours($s['minutes'])) : '—' ?></td>
      <td class="muted small"><?= e($s['schedule']['last_end'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="muted small">
  <strong>Assigned</strong> counts progress records naming this person, including tasks shared
  with somebody else — a shared task counts for <em>both</em>, which is right for "what is this
  person involved in" and generous for "how much did they deliver". That distinction is still
  open in <code>docs/DECISIONS.md</code> and is surfaced rather than quietly picked.
  <strong>Time logged</strong> comes from the Plaky import and is nearly empty until time is
  entered here directly; a day is <?= e(fmt_num($hours, 1)) ?> hours on this project.
</p>
<?php endif; ?>

</div>
</body></html>
