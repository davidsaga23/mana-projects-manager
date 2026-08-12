<?php
// Shared page chrome. Set $pageTitle before including.
$pageTitle = $pageTitle ?? 'Anima | Project manager';
// $pdo is in scope here because every page requires db.php before this file.
$pendingMigrations = (isset($pdo) && $pdo instanceof PDO && is_admin())
    ? pending_migration_count($pdo) : 0;
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Anima | Project manager</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><a href="index.php" class="brand"><?= site_logo() ?><span class="brand-name">Anima <span class="muted">| Project manager</span></span></a></h1>
    <div class="topbar-right">
      <span><?= e(current_user_display()) ?></span>
      <?php if (!can_edit()): ?><span class="role-badge">view only</span><?php endif; ?>
      <?php if (is_admin()): ?>
        · <a href="users.php">Users</a>
        · <a href="migrate.php" class="<?= $pendingMigrations > 0 ? 'needs-action' : '' ?>">Migrations<?php
             if ($pendingMigrations > 0): ?><span class="pending-badge"><?= $pendingMigrations ?></span><?php endif;
           ?></a>
      <?php endif; ?>
      · <a href="logout.php">Log out</a>
    </div>
  </div>
<?php if ($pendingMigrations > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $pendingMigrations ?></strong> database update<?= $pendingMigrations === 1 ? '' : 's' ?>
    <?= $pendingMigrations === 1 ? 'is' : 'are' ?> waiting to be applied. New features usually
    need them, and nothing runs until you confirm.
    <a href="migrate.php">Review and apply →</a>
  </div>
<?php endif; ?>
<?php if (!can_edit()): ?>
  <div class="flash flash-warn">You have view-only access — everything is readable, nothing
    can be changed. Ask an administrator if you need to edit something.</div>
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
