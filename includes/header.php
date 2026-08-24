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
      <?php
      // ONE HOME, settled with David 2026-08-17. This bar used to offer
      // "Project management" and "Finance management" side by side, and both led
      // to a list of the same projects — so the first decision the app asked of
      // you was one you could not answer yet: which lens do you want, before you
      // have even said which show.
      //
      // The split is real, but it belongs one level down. Pick the project here;
      // choose the lens inside it, on the section switcher project_nav() and
      // finance_nav() both draw. So the top bar carries what is on MY desk, the
      // one way to ALL the shows, and the housekeeping behind a separator.
      ?>
      · <a href="mytasks.php">My work</a>
      · <a href="index.php">Projects</a>

      <?php
      // The setup and housekeeping screens, behind a separator. Opened
      // occasionally rather than daily — the same split project_nav() makes
      // between its work tabs and its data tabs.
      //
      // Nothing about a PROJECT belongs down here. Everything that is about one
      // show is reached by picking it from Projects and choosing a section
      // inside it; what is left in this group is the application itself —
      // accounts, mail, the database.
      ?>
      <span class="nav-sep" aria-hidden="true">|</span>
      <?php if (is_admin()): ?>
        <a href="users.php">Users</a>
        · <a href="smtp_test.php">Email</a>
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
