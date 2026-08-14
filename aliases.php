<?php
// Names & aliases — the standing "what didn't match" report, and the place to
// fix it.
//
// The lesson this exists for: on STFC, Plaky assignees and asset names silently
// dropped into an unassigned bucket for weeks. Here nothing is guessed and
// nothing is hidden — and because the raw values are kept, fixing a match now
// repairs every record that already arrived under it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/statuses.php';
require __DIR__ . '/includes/names.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}
$selfUrl = 'aliases.php?project=' . $projectId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'match_people') {
            $linked = 0; $created = 0;
            foreach (($_POST['raw_key'] ?? []) as $i => $rawName) {
                $choice = $_POST['raw_val'][$i] ?? '0';
                if ($choice === '0' || $choice === '') continue;
                if ($choice === 'new') {
                    $pdo->prepare('INSERT INTO crew (name) VALUES (?)')->execute([$rawName]);
                    $crewId = (int)$pdo->lastInsertId();
                    $created++;
                } else {
                    $crewId = (int)$choice;
                    if ($crewId <= 0) continue;
                }
                remember_crew_alias($pdo, $rawName, $crewId);
                $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
                    ->execute([$crewId, $projectId]);
                $linked++;
            }
            if ($linked === 0) throw new RuntimeException('Nothing was selected, so nothing changed.');

            $fixed = reresolve_assignees($pdo, $projectId);
            flash('ok', "Linked $linked name" . ($linked === 1 ? '' : 's') .
                        ($created ? ", adding $created new " . ($created === 1 ? 'person' : 'people') : '') .
                        ". $fixed existing record" . ($fixed === 1 ? '' : 's') . ' now have someone attached.');
            header('Location: ' . $selfUrl);
            exit;
        }

        if ($action === 'map_statuses') {
            $mapped = 0;
            foreach (($_POST['status_key'] ?? []) as $i => $external) {
                $statusId = (int)($_POST['status_val'][$i] ?? 0);
                if ($statusId <= 0) continue;
                $pdo->prepare(
                    'INSERT INTO status_mapping (project_id, external_status, status_id, times_seen)
                     VALUES (?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE status_id = ?'
                )->execute([$projectId, $external, $statusId, $statusId]);
                $mapped++;
            }
            if ($mapped === 0) throw new RuntimeException('Nothing was selected, so nothing changed.');

            $touched = reapply_status_mapping($pdo, $projectId);
            flash('ok', "Set the meaning of $mapped status value" . ($mapped === 1 ? '' : 's') .
                        ". $touched existing record" . ($touched === 1 ? '' : 's') . ' were updated.');
            header('Location: ' . $selfUrl);
            exit;
        }

        if (preg_match('/^del_crew_alias:(\d+)$/', $action, $m)) {
            $pdo->prepare('DELETE FROM crew_aliases WHERE id = ?')->execute([(int)$m[1]]);
            $fixed = reresolve_assignees($pdo, $projectId);
            flash('ok', "Alias removed. $fixed record" . ($fixed === 1 ? '' : 's') . ' still have someone attached.');
            header('Location: ' . $selfUrl);
            exit;
        }

        if (preg_match('/^del_name_alias:(\d+)$/', $action, $m)) {
            $pdo->prepare('DELETE FROM name_aliases WHERE id = ? AND project_id = ?')
                ->execute([(int)$m[1], $projectId]);
            flash('ok', 'Alias removed. It will no longer be applied on the next import.');
            header('Location: ' . $selfUrl);
            exit;
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        header('Location: ' . $selfUrl);
        exit;
    }
}

$unmatched   = unmatched_assignees($pdo, $projectId);
$unmapped    = unmapped_statuses($pdo, $projectId);
$crew        = $pdo->query('SELECT id, name, active FROM crew ORDER BY active DESC, name')->fetchAll();
$statuses    = project_statuses($pdo, $projectId);
$crewAliases = $pdo->query(
    'SELECT ca.id, ca.alias_name, c.name AS crew_name
       FROM crew_aliases ca JOIN crew c ON c.id = ca.crew_id
      ORDER BY c.name, ca.alias_name'
)->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM name_aliases WHERE project_id = ? ORDER BY kind, alias');
$stmt->execute([$projectId]);
$nameAliases = $stmt->fetchAll();

$pageTitle = 'Names & aliases · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'aliases'); ?>
<h2 class="wizard-title">Names &amp; aliases <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php if (!$unmatched && !$unmapped): ?>
  <div class="flash flash-ok">Everything imported so far matched something here. Nothing needs attention.</div>
<?php endif; ?>

<?php if ($unmatched): ?>
  <h3>Names that didn't match anyone</h3>
  <p class="lead">These arrived on imported work but aren't linked to a person, so that work is
     missing from per-artist reporting. Linking one now repairs every record it already
     appears on — nothing needs re-importing.</p>
  <form method="post">
    <input type="hidden" name="action" value="match_people">
    <table class="review-table">
      <tr><th>Name as it arrived</th><th>Records</th><th>Is</th></tr>
      <?php foreach ($unmatched as $i => $u): ?>
      <tr>
        <td><strong><?= e($u['assignee_raw']) ?></strong>
          <input type="hidden" name="raw_key[<?= $i ?>]" value="<?= e($u['assignee_raw']) ?>"></td>
        <td class="muted"><?= (int)$u['times_seen'] ?></td>
        <td>
          <select name="raw_val[<?= $i ?>]">
            <option value="0">— leave for now —</option>
            <?php foreach ($crew as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= $c['active'] ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
            <option value="new">+ add as a new person</option>
          </select>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="actions"><button type="submit">Link these names</button></div>
  </form>
  <p class="muted small">A field holding two people is split before matching, so a shared task
     shows each name separately here.</p>
<?php endif; ?>

<?php if ($unmapped): ?>
  <h3>Status values with no meaning</h3>
  <p class="lead">These arrived from an import but nothing says what they count as, so they
     currently count as neither delivered nor approved.</p>
  <form method="post">
    <input type="hidden" name="action" value="map_statuses">
    <table class="review-table">
      <tr><th>Value as it arrived</th><th>Times seen</th><th>Means</th></tr>
      <?php foreach ($unmapped as $i => $u): ?>
      <tr>
        <td><strong><?= e($u['external_status']) ?></strong>
          <input type="hidden" name="status_key[<?= $i ?>]" value="<?= e($u['external_status']) ?>"></td>
        <td class="muted"><?= (int)$u['times_seen'] ?></td>
        <td>
          <select name="status_val[<?= $i ?>]">
            <option value="0">— leave for now —</option>
            <?php foreach ($statuses as $s): ?>
              <option value="<?= (int)$s['id'] ?>">
                <?= e($s['name']) ?><?= $s['maps_to'] !== 'none' ? ' (' . e($s['maps_to']) . ')' : '' ?><?= $s['is_blocked'] ? ' (blocked)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="actions"><button type="submit">Set these meanings</button></div>
  </form>
  <p class="muted small">Need a state that doesn't exist yet? Add it on the
     <a href="statuses_edit.php?project=<?= $projectId ?>">statuses screen</a> first.</p>
<?php endif; ?>

<h3>Known people aliases</h3>
<?php if (!$crewAliases): ?>
  <p class="muted">None yet. They're created automatically whenever you link a name here or
     during an import.</p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Name as it arrives</th><th>Is</th><th></th></tr>
    <?php foreach ($crewAliases as $a): ?>
    <tr>
      <td><?= e($a['alias_name']) ?></td>
      <td><strong><?= e($a['crew_name']) ?></strong></td>
      <td class="row-actions">
        <form method="post" onsubmit="return confirm('Remove this alias?');">
          <button type="submit" name="action" value="del_crew_alias:<?= (int)$a['id'] ?>" class="danger">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small">Aliases are shared across every project — a person's handle is the
     same wherever they work.</p>
<?php endif; ?>

<h3>Known step and asset aliases</h3>
<?php if (!$nameAliases): ?>
  <p class="muted">None yet. One is created whenever an import maps a differently-spelled name
     onto one of this project's steps.</p>
<?php else: ?>
  <table class="review-table">
    <tr><th>Kind</th><th>Name as it arrives</th><th>Means</th><th></th></tr>
    <?php foreach ($nameAliases as $a): ?>
    <tr>
      <td class="muted"><?= e($a['kind']) ?></td>
      <td><?= e($a['alias']) ?></td>
      <td><strong><?= e($a['canonical_name']) ?></strong></td>
      <td class="row-actions">
        <form method="post" onsubmit="return confirm('Remove this alias?');">
          <button type="submit" name="action" value="del_name_alias:<?= (int)$a['id'] ?>" class="danger">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</div>
</body></html>
