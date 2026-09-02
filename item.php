<?php
// One shot, asset or whole-show deliverable, opened up.
//
// The tracking grid answers "where is everything". This answers "what is going
// on with THIS one" — the question asked in a review when somebody stops on a
// shot. Every step it passes through, with who is on it, what state it is in,
// which version, and any notes.
//
// It is also the first screen where the three halves of the platform meet: the
// tracking data, the schedule, and the ticket system.
//
// DATES ARE SHOWN BUT NOT EDITED HERE. They come from `schedule`, which the
// forward pass generates and the timeline lets you drag — and the timeline marks
// what it touches `is_manual = 1` so a re-run leaves it alone. A second place to
// write dates without that marker would have the scheduler quietly overwrite
// them (DECISIONS, 2026-08-12).

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/timelog.php';
require_once __DIR__ . '/includes/grid.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/activity.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$itemKey   = trim($_GET['item'] ?? '');

$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$item = load_grid_item($pdo, $projectId, $itemKey);
if (!$item) {
    flash('err', 'That item is not part of this project.');
    header('Location: production.php?project=' . $projectId);
    exit;
}

$selfUrl = 'item.php?project=' . $projectId . '&item=' . urlencode($itemKey);
$backUrl = 'production.php?project=' . $projectId . '&scope=' . $item['scope'];
$hasDetail = column_exists($pdo, 'progress', 'version');

// --- save every row at once -------------------------------------------------
// One form, one Save, the same convention as the wizard and the statuses screen.
// A save-per-row would mean five reloads to update one shot after a review.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $statusMap = statuses_by_id($pdo, $projectId);
    $changed = 0; $problems = [];

    foreach (($_POST['step'] ?? []) as $stepId => $fields) {
        $stepId = (int)$stepId;

        $chk = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
        $chk->execute([$stepId, $projectId]);
        $step = $chk->fetch();
        if (!$step) continue;
        if (!step_covers_item($step, $item, $item['scope'])) continue;

        $statusId = !empty($fields['status_id']) ? (int)$fields['status_id'] : null;
        if ($statusId !== null && !isset($statusMap[$statusId])) $statusId = null;
        $status = $statusId ? $statusMap[$statusId] : null;
        $flags  = status_flags($status);

        $crewId = !empty($fields['crew_id']) ? (int)$fields['crew_id'] : null;
        $version = $hasDetail ? mb_substr(trim($fields['version'] ?? ''), 0, 40) : null;
        $notes   = $hasDetail ? (trim($fields['notes'] ?? '') ?: null) : null;

        $cols = 'project_id, step_id, shot_id, asset_id, item_key, status_id,
                 is_delivered, is_approved, crew_id' . ($hasDetail ? ', version, notes' : '');
        $vals = '?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hasDetail ? ', ?, ?' : '');
        $upd  = 'status_id = VALUES(status_id), is_delivered = VALUES(is_delivered),
                 is_approved = VALUES(is_approved), crew_id = VALUES(crew_id)'
              . ($hasDetail ? ', version = VALUES(version), notes = VALUES(notes)' : '');

        $args = [
            $projectId, $stepId,
            $item['scope'] === 'shot'  ? $item['id'] : null,
            $item['scope'] === 'asset' ? $item['id'] : null,
            $item['item_key'], $statusId, $flags['is_delivered'], $flags['is_approved'], $crewId,
        ];
        if ($hasDetail) { $args[] = $version !== '' ? $version : null; $args[] = $notes; }

        // Read before write — see production_api.php for why.
        $was = $pdo->prepare('SELECT status_id FROM progress WHERE step_id = ? AND item_key = ?');
        $was->execute([$stepId, $item['item_key']]);
        $oldStatusId = $was->fetchColumn();
        $oldStatusId = $oldStatusId === false || $oldStatusId === null ? null : (int)$oldStatusId;

        try {
            $q = $pdo->prepare(
                "INSERT INTO progress ({$cols}) VALUES ({$vals}) ON DUPLICATE KEY UPDATE {$upd}"
            );
            $q->execute($args);
            if ($q->rowCount() > 0) $changed++;

            log_status_change_by_id($pdo, $projectId, $step, $item['item_key'],
                $oldStatusId, $statusId, [
                    'item_name'  => $item['name'],
                    'shot_id'    => $item['scope'] === 'shot'  ? $item['id'] : null,
                    'asset_id'   => $item['scope'] === 'asset' ? $item['id'] : null,
                    'source'     => 'item',
                    'changed_by' => current_user_display(),
                ]);
        } catch (PDOException $e) {
            $problems[] = $step['name'] . ': ' . $e->getMessage();
        }
    }

    if ($problems) flash('err', implode(' ', $problems));
    flash('ok', $changed > 0
        ? "Saved $changed step" . ($changed === 1 ? '' : 's') . ' on ' . $item['name'] . '.'
        : 'No changes to save.');
    header('Location: ' . $selfUrl);
    exit;
}

$rows     = item_steps($pdo, $projectId, $item);
$statuses = statuses_by_id($pdo, $projectId);
$people   = project_people($pdo, $projectId);

// Everyone already named on this item, so somebody who has left the project
// still appears rather than being silently unassigned by the next save.
$known = array_map(fn($p) => (int)$p['id'], $people);
foreach ($rows as $r) {
    $cid = (int)($r['progress']['crew_id'] ?? 0);
    if ($cid && !in_array($cid, $known, true)) {
        $people[] = ['id' => $cid, 'name' => ($r['who'] ?: 'crew #' . $cid) . ' (not on this project)',
                     'is_placeholder' => 0];
        $known[] = $cid;
    }
}

$applicable = count($rows);
$approved = 0; $blocked = 0; $minutes = 0;
foreach ($rows as $r) {
    if ($r['done']) $approved++;
    if ($r['status'] && (int)($r['status']['is_blocked'] ?? 0) === 1) $blocked++;
    $minutes += (int)($r['minutes'] ?? 0);
}
$countable = count(array_filter($rows, fn($r) => !$r['excluded']));
$ready = $countable > 0 && $approved >= $countable;

// Open tickets naming this item — a shot, an asset, or either via its step.
$tickets = [];
if (column_exists($pdo, 'tickets', 'id')) {
    $col = $item['scope'] === 'shot' ? 't.shot_id' : ($item['scope'] === 'asset' ? 't.asset_id' : null);
    if ($col !== null) {
        $t = $pdo->prepare(
            "SELECT t.id, t.seq, t.title, t.priority, t.due_date, p.code AS project_code,
                    c.name AS crew_name, ps.name AS step_name,
                    st.name AS status_name, st.color AS status_color
               FROM tickets t
               JOIN projects p ON p.id = t.project_id
               LEFT JOIN crew c ON c.id = t.assigned_crew_id
               LEFT JOIN pipeline_steps ps ON ps.id = t.step_id
               LEFT JOIN statuses st ON st.id = t.status_id
              WHERE {$col} = ? AND t.archived = 0
                AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
              ORDER BY t.due_date IS NULL, t.due_date, t.seq"
        );
        $t->execute([$item['id']]);
        $tickets = $t->fetchAll();
    }
}

$pageTitle = $item['name'] . ' · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'production'); ?>
<p class="crumb"><a href="<?= e($backUrl) ?>">← Back to Production tracking</a></p>

<h2 class="wizard-title">
  <?= e($item['name']) ?>
  <?php if ($ready): ?><span class="ready-tag">ready</span><?php endif; ?>
  <span class="muted">· <?= e($project['name']) ?></span>
</h2>

<p class="summary-line">
  <?php if ($item['scope'] === 'asset'): ?>
    <span class="ctx-chip ctx-asset"><?= e($item['asset_type'] ?: 'Untyped') ?></span>
  <?php elseif ($item['scope'] === 'shot'): ?>
    <span class="ctx-chip ctx-shot"><?= e($item['group']) ?></span>
    <?php if ($item['seconds'] !== null): ?> · <?= e(fmt_num((float)$item['seconds'], 2)) ?> seconds<?php endif; ?>
  <?php else: ?>
    <span class="ctx-chip ctx-step">Whole-show deliverable</span>
  <?php endif; ?>
  · <strong><?= $approved ?></strong> of <?= $countable ?> steps approved
  <?php if ($blocked): ?> · <span class="warn-text"><?= $blocked ?> blocked</span><?php endif; ?>
  <?php if ($minutes): ?> · <?= e(fmt_hours($minutes)) ?> logged<?php endif; ?>
</p>

<?php if (!empty($item['notes'])): ?>
  <p class="muted small"><strong>About this <?= e($item['scope']) ?>:</strong> <?= e($item['notes']) ?></p>
<?php endif; ?>

<?php if (!$hasDetail): ?>
  <div class="flash flash-warn">
    Version and notes per step need migration <code>020</code>, which hasn't been applied yet.
    Everything else on this page works.
    <?php if (is_admin()): ?><a href="migrate.php">Review and apply it →</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty-state">
    <p><strong>No pipeline step applies to this <?= e($item['scope']) ?>.</strong></p>
    <p class="muted">A step's scope is set on the Setup screen. Nothing is broken, there is
       simply no work defined for it.</p>
  </div>
<?php else: ?>

<form method="post">
  <input type="hidden" name="save" value="1">
  <table class="item-table">
    <thead>
      <tr>
        <th>Step</th><th>Status</th><th>Who</th>
        <?php if ($hasDetail): ?><th>Version</th><?php endif; ?>
        <th>Due</th><th class="num">Time</th>
        <?php if ($hasDetail): ?><th>Notes</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $st = $r['step']; $sid = (int)$st['id']; ?>
      <tr class="<?= $r['done'] ? 'is-done' : '' ?> <?= $r['excluded'] ? 'is-na' : '' ?>">
        <td class="wf-name">
          <span class="dot" style="background:<?= e($st['color'] ?: '#ccc') ?>"></span>
          <?= e($st['name']) ?>
          <?php if (!empty($st['department'])): ?>
            <span class="step-scope"><?= e($st['department']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <select name="step[<?= $sid ?>][status_id]" <?= can_edit() ? '' : 'disabled' ?>>
            <option value="">nothing recorded</option>
            <?php foreach ($statuses as $stid => $s): ?>
              <option value="<?= (int)$stid ?>" <?= (int)($r['status']['id'] ?? 0) === (int)$stid ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$r['status'] && !empty($r['progress']['raw_status'])): ?>
            <small class="warn-text">imported as "<?= e($r['progress']['raw_status']) ?>", unmapped</small>
          <?php endif; ?>
        </td>
        <td>
          <select name="step[<?= $sid ?>][crew_id]" <?= can_edit() ? '' : 'disabled' ?>>
            <option value="">nobody</option>
            <?php foreach ($people as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)($r['progress']['crew_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?><?= (int)($p['is_placeholder'] ?? 0) === 1 ? ' (slot)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($r['others']): ?>
            <small class="muted">with <?= e(implode(', ', $r['others'])) ?></small>
          <?php elseif (!$r['progress']['crew_id'] && !empty($r['progress']['assignee_raw'])): ?>
            <small class="warn-text">"<?= e($r['progress']['assignee_raw']) ?>" unmatched</small>
          <?php endif; ?>
        </td>
        <?php if ($hasDetail): ?>
          <td><input name="step[<?= $sid ?>][version]" value="<?= e($r['version'] ?? '') ?>"
                     placeholder="v001" maxlength="40" class="ver-input" <?= can_edit() ? '' : 'disabled' ?>></td>
        <?php endif; ?>
        <td class="muted small">
          <?= e($r['due'] ?: '—') ?>
          <?php if ($r['manual']): ?><span class="slot-tag" title="Hand-planned; the scheduler leaves it alone">fixed</span><?php endif; ?>
        </td>
        <td class="num muted"><?= $r['minutes'] ? e(fmt_hours((int)$r['minutes'])) : '—' ?></td>
        <?php if ($hasDetail): ?>
          <td><textarea name="step[<?= $sid ?>][notes]" rows="1" placeholder="—"
                        class="notes-input" <?= can_edit() ? '' : 'disabled' ?>><?= e($r['notes'] ?? '') ?></textarea></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (can_edit()): ?>
    <div class="actions"><button type="submit">Save all steps</button></div>
  <?php endif; ?>
</form>

<p class="muted small">
  <strong>Dates come from the schedule</strong> and are changed on the
  <a href="timeline.php?project=<?= $projectId ?>">Timeline</a>, which marks what you drag so a
  re-run of the scheduler leaves it alone. Editing them here as well would give the scheduler a
  second set of dates it didn't know to preserve.
  <strong>Version is free text</strong>, "v003", "A_02", whatever the show uses. Nothing sorts
  or compares them, because nothing can know that v10 follows v9 rather than v1.
  <strong>Plaky still overwrites status</strong> on the next import; version and notes are never
  touched by it.
</p>
<?php endif; ?>

<?php if ($tickets): ?>
  <h3>Open tickets <span class="muted">· <?= count($tickets) ?></span></h3>
  <table>
    <thead><tr><th>Ref</th><th>Title</th><th>Step</th><th>Owner</th><th>Status</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $tk): $c = $tk['status_color'] ?: '#8a97a3'; ?>
      <tr>
        <td><a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e($tk['project_code'] . '-' . (int)$tk['seq']) ?></a></td>
        <td><a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e($tk['title']) ?></a></td>
        <td class="muted"><?= e($tk['step_name'] ?: '—') ?></td>
        <td class="muted"><?= e($tk['crew_name'] ?: '—') ?></td>
        <td><span class="status-chip" style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
            <?= e($tk['status_name'] ?? 'no status') ?></span></td>
        <td class="muted small"><?= e($tk['due_date'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</div>
</body></html>
