<?php
// Project list — the platform's front door. Grows a real dashboard in later
// phases; for now it proves the install works and shows every project.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$projects = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM shots s WHERE s.project_id = p.id)  AS shot_count,
            (SELECT COUNT(*) FROM assets a WHERE a.project_id = p.id) AS asset_count,
            (SELECT COUNT(*) FROM crew_projects cp WHERE cp.project_id = p.id) AS crew_count
     FROM projects p
     ORDER BY p.status = 'active' DESC, p.created_at DESC"
)->fetchAll();
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Projects · Mana Production Platform</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1>Mana Production Platform</h1>
    <div class="topbar-right">
      <span><?= e(current_user_display()) ?></span>
      · <a href="migrate.php">Migrations</a>
      · <a href="logout.php">Log out</a>
    </div>
  </div>

  <?php if (!$projects): ?>
    <div class="empty-state">
      <h2>No projects yet</h2>
      <p>The New Project setup wizard arrives in the next phase. The database is
         installed and ready for it.</p>
    </div>
  <?php else: ?>
    <table>
      <tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th>
          <th>Shots</th><th>Assets</th><th>Crew</th><th>Delivery</th></tr>
      <?php foreach ($projects as $p): ?>
      <tr>
        <td><code><?= e($p['code']) ?></code></td>
        <td><?= e($p['name']) ?></td>
        <td><?= e($p['type']) ?></td>
        <td><span class="status status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td><?= (int)$p['shot_count'] ?></td>
        <td><?= (int)$p['asset_count'] ?></td>
        <td><?= (int)$p['crew_count'] ?></td>
        <td><?= e($p['delivery_date'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</body></html>
