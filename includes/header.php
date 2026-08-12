<?php
// Shared page chrome. Set $pageTitle before including.
$pageTitle = $pageTitle ?? 'Mana Production Platform';
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Mana Production Platform</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><a href="index.php">Mana Production Platform</a></h1>
    <div class="topbar-right">
      <span><?= e(current_user_display()) ?></span>
      <?php if (!can_edit()): ?><span class="role-badge">view only</span><?php endif; ?>
      <?php if (is_admin()): ?>
        · <a href="users.php">Users</a>
        · <a href="migrate.php">Migrations</a>
      <?php endif; ?>
      · <a href="logout.php">Log out</a>
    </div>
  </div>
<?php if (!can_edit()): ?>
  <div class="flash flash-warn">You have view-only access — everything is readable, nothing
    can be changed. Ask an administrator if you need to edit something.</div>
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
