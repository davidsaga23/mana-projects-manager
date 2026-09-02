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
      <?php // Names the role being WORN, so the top bar and the app agree about
            // who you are while a preview is running. ?>
      <?php if (in_preview()): ?>
        <span class="role-badge role-badge-preview">as <?= e(preview_role() === 'viewer' ? 'view only' : 'editor') ?></span>
      <?php elseif (!can_edit()): ?>
        <span class="role-badge">view only</span>
      <?php endif; ?>
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
      · <a href="mytasks.php">My tasks</a>
      · <a href="index.php">Projects</a>
      <?php // ARTISTS is up here, not inside a project, because a freelancer is
            // not the property of the show that first spoke to them — the whole
            // value of writing them down is that the NEXT show can find them.
            // Same reasoning as the step library and the ideal quotas.
            //
            // A third link rather than a fourth: what is on my desk, the shows,
            // and the people. Everything about one show is still reached by
            // picking it from Projects. ?>
      · <a href="artists.php">Artists</a>

      <?php // THE RECORDS. David, 2026-09-01: "I'll need from time to time, to
            // check the records and see what we used to charge certain slots or
            // quotas." Both sit beside Artists rather than inside a project for
            // the same reason it does — the value of a record is that the NEXT
            // show can read it, and neither is about any one show.
            //
            // Two links rather than one History page with tabs, because they are
            // reached for separately: a quota is a production question asked
            // while planning, a fee is a money question asked while budgeting.
            //
            // Fees are admin-only, like every other screen carrying a rate.
            // Quotas are not: days per item and headcount are production facts. ?>
      · <a href="history_quotas.php">Historic quotas</a>
      <?php if (is_admin()): ?>
        · <a href="history_fees.php">Historic fees</a>
      <?php endif; ?>

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
<?php if (in_preview()): ?>
  <?php
  // ⚠ DRAWN OUTSIDE EVERY is_admin() CHECK ON THIS PAGE. While a preview is
  // running is_admin() answers for the BORROWED role, so anything gated on it
  // is hidden — including, if this were gated too, the only way out.
  //
  // The form posts to preview.php, which does its own auth precisely so that
  // this button still works while wearing a role that cannot POST anywhere.
  ?>
  <div class="flash flash-preview">
    <?php // The whole message in ONE element. The banner is a flex row, and left
          // loose the sentence became four flex items with a 14px gap opening up
          // mid-clause after every <strong>. Two items: what is happening, and
          // the way out. ?>
    <div class="preview-msg">
      <strong>Previewing as <?= preview_role() === 'viewer' ? 'a view-only account' : 'an editor' ?>.</strong>
      This is what they see: the menu, the screens they can open, the buttons they get.
      <strong>Nothing saves</strong> while you are in here, whichever role you are wearing.
    </div>
    <form method="post" action="preview.php" class="preview-exit">
      <input type="hidden" name="stop" value="1">
      <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '/index.php')) ?>">
      <button class="button" type="submit">Leave preview</button>
    </form>
  </div>
<?php endif; ?>
<?php if ($pendingMigrations > 0): ?>
  <div class="flash flash-warn">
    <strong><?= $pendingMigrations ?></strong> database update<?= $pendingMigrations === 1 ? '' : 's' ?>
    <?= $pendingMigrations === 1 ? 'is' : 'are' ?> waiting to be applied. New features usually
    need them, and nothing runs until you confirm.
    <a href="migrate.php">Review and apply →</a>
  </div>
<?php endif; ?>
<?php // Suppressed during a preview: the banner above already says both that
      // nothing saves and why, and two warnings stacked about the same thing
      // read as two separate problems. ?>
<?php if (!can_edit() && !in_preview()): ?>
  <div class="flash flash-warn">You have view-only access, everything is readable, nothing
    can be changed. Ask an administrator if you need to edit something.</div>
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
