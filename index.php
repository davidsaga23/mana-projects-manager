<?php
// Project list — the platform's front door.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$projects = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM shots s WHERE s.project_id = p.id)  AS shot_count,
            (SELECT COUNT(*) FROM assets a WHERE a.project_id = p.id) AS asset_count,
            (SELECT COUNT(*) FROM pipeline_steps ps WHERE ps.project_id = p.id) AS step_count,
            (SELECT COUNT(*) FROM crew_projects cp WHERE cp.project_id = p.id) AS crew_count,
            (SELECT setting_value FROM project_settings st
              WHERE st.project_id = p.id AND st.setting_key = 'wizard_step') AS wizard_step
     FROM projects p
     ORDER BY FIELD(p.status, 'active','setup','on_hold','wrapped'), p.created_at DESC"
)->fetchAll();

$pageTitle = 'Projects';
require __DIR__ . '/includes/header.php';
?>

<div class="page-actions">
  <a class="button" href="wizard.php">+ New project</a>
  <a href="mytasks.php" style="margin-left:12px;">My work across all projects →</a>
</div>

<?php if (!$projects): ?>
  <div class="empty-state">
    <h2>No projects yet</h2>
    <p>Set up your first one — it takes about five minutes and drives the schedule,
       the quotas and the reporting from there on.</p>
    <p><a class="button" href="wizard.php">Start a new project</a></p>
  </div>
<?php else: ?>
  <table>
    <tr>
      <th>Code</th><th>Name</th><th>Type</th><th>Status</th>
      <th>Steps</th><th>Shots</th><th>Assets</th><th>Crew</th><th>Delivery</th><th></th>
    </tr>
    <?php foreach ($projects as $p): ?>
    <tr>
      <td><code><?= e($p['code']) ?></code></td>
      <td><a href="project.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
      <td><?= e($p['type']) ?></td>
      <td><span class="status status-<?= e($p['status']) ?>"><?= e(str_replace('_', ' ', $p['status'])) ?></span></td>
      <td><?= (int)$p['step_count'] ?></td>
      <td><?= (int)$p['shot_count'] ?></td>
      <td><?= (int)$p['asset_count'] ?></td>
      <td><?= (int)$p['crew_count'] ?></td>
      <td><?= e($p['delivery_date'] ?? '—') ?></td>
      <td class="row-actions">
        <?php if ($p['status'] === 'setup'): ?>
          <a href="wizard.php?project=<?= (int)$p['id'] ?>&step=<?= max(1, (int)($p['wizard_step'] ?: 1)) ?>">Resume setup →</a>
        <?php else: ?>
          <a href="project.php?id=<?= (int)$p['id'] ?>">Open →</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</div>
</body></html>
