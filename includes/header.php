<?php
// Shared page chrome. Set $pageTitle before including.
$pageTitle = $pageTitle ?? 'Anima | Project manager';
// $pdo is in scope here because every page requires db.php before this file.
$pendingMigrations = (isset($pdo) && $pdo instanceof PDO && is_admin())
    ? pending_migration_count($pdo) : 0;
// The My tasks nav badge — how many active cards are mine, and whether any is
// new since I last looked. Drawn for whoever is signed in; cheap enough to run
// on every page (see mytasks_badge()).
// effective_user_id(), not the session id: while an admin is viewing AS a
// specific person (David, 2026-10-21) the badge counts THAT person's cards, so
// the whole top bar agrees about whose desk is on show.
$myTasksBadge = (isset($pdo) && $pdo instanceof PDO)
    ? mytasks_badge($pdo, effective_user_id())
    : ['count' => 0, 'new' => false];

// The Reviews button and its count (David, 2026-10-27: "at the top as a button,
// similar to the task button with a number of pending reviews"). Only for the
// people who review: supervisors, Production and admins. See includes/review.php.
require_once __DIR__ . '/review_badge.php';
$reviewStage = review_stage();
$reviewCount = ($reviewStage && isset($pdo) && $pdo instanceof PDO) ? review_count($pdo) : 0;

// --- the bell (David, 2026-10-22), migration 060 -----------------------------
//
// "you need to get a notification in a bell close to your name so you know
// someone assign, tag or changed a tasked you're subscribed to". So it lives in
// the top bar beside the name, on every screen, rather than on My tasks — the
// whole point is that it finds you wherever you are.
//
// Drawn for a login linked to a crew member and nobody else: `users` are logins
// and `crew` are people, and it is the person who is told. effective_crew_id(),
// like the My tasks badge above, so the whole bar agrees about whose desk is on
// show while an admin is viewing as somebody.
//
// Every function here answers an empty bell rather than throwing — see the note
// at the top of includes/notify.php. Chrome must not be able to take a page down.
require_once __DIR__ . '/notify.php';
$bell = ['on' => false, 'unseen' => 0, 'items' => []];
if (isset($pdo) && $pdo instanceof PDO && notify_available($pdo)) {
    $bellCrew = (int)(effective_crew_id($pdo) ?? 0);
    if ($bellCrew > 0) {
        $bell = [
            'on'     => true,
            'unseen' => notify_unseen($pdo, $bellCrew),
            'items'  => notify_recent($pdo, $bellCrew),
        ];
    }
}
// The Bug button (migration 062) and, for admins, how many reports are open.
require_once __DIR__ . '/bugs.php';
$openBugs = 0;
if (isset($pdo) && $pdo instanceof PDO && is_admin() && bugs_available($pdo)) {
    $openBugs = (int)$pdo->query("SELECT COUNT(*) FROM bug_reports WHERE status = 'open'")->fetchColumn();
}
// The first-time tour (2026-10-30): null unless it runs on this page. See includes/tour.php.
require_once __DIR__ . '/tour.php';
$tourBoot = tour_boot(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Anima | Project manager</title>
<?php // The square lockup is already a tab icon: dark field, centred mark. ?>
<link rel="icon" href="assets/logo.webp<?= asset_ver('logo.webp') ?>" type="image/webp">
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><a href="index.php" class="brand"><?= site_logo() ?><span class="brand-name">Anima <span class="muted">| Project manager</span></span></a></h1>
    <div class="topbar-right">
      <?php if ($bell['on']): ?>
        <div class="bell" id="bell">
          <button type="button" class="bell-btn" id="bellBtn" aria-haspopup="true" aria-expanded="false"
                  aria-label="<?= $bell['unseen']
                      ? $bell['unseen'] . ' unread notification' . ($bell['unseen'] === 1 ? '' : 's')
                      : 'Notifications, none unread' ?>">
            <?php // Inline, like site_logo(): an icon file is a second request
                  // and a sprite is a third thing to keep in step. ?>
            <svg viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false">
              <path fill="currentColor" d="M10 2a5 5 0 0 0-5 5v3.1l-1.3 2.2a.8.8 0 0 0 .7 1.2h11.2a.8.8 0 0 0 .7-1.2L15 10.1V7a5 5 0 0 0-5-5Zm0 15.5a2.3 2.3 0 0 0 2.2-1.7H7.8A2.3 2.3 0 0 0 10 17.5Z"/>
            </svg>
            <?php if ($bell['unseen']): ?>
              <span class="bell-count" id="bellCount"><?= $bell['unseen'] > 9 ? '9+' : (int)$bell['unseen'] ?></span>
            <?php endif; ?>
          </button>

          <div class="bell-panel" id="bellPanel" hidden>
            <div class="bell-head">Notifications<?php if ($bell['unseen']): ?>
              <span class="muted">· <?= (int)$bell['unseen'] ?> new</span><?php endif; ?></div>
            <?php if (!$bell['items']): ?>
              <p class="muted small bell-empty">Nothing yet. You are told here when somebody
                assigns you a task, names you in a comment, or moves something you are on.</p>
            <?php else: ?>
              <ul class="bell-list">
                <?php foreach ($bell['items'] as $n): ?>
                  <li class="<?= $n['seen_at'] === null ? 'is-new' : '' ?>">
                    <a href="<?= e(notify_link($n)) ?>">
                      <span class="bell-dot bell-<?= e($n['kind']) ?>" aria-hidden="true"></span>
                      <span class="bell-what"><strong><?= e($n['actor'] ?: 'Somebody') ?></strong>
                        <?= e(notify_verb((string)$n['kind'])) ?>
                        <?= e($n['summary'] ?: 'a task') ?></span>
                      <span class="bell-when muted"><?= e(date('j M', strtotime((string)$n['created_at']))) ?></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <span><?= e(current_user_display()) ?></span>
      <?php // Names the role being WORN, so the top bar and the app agree about
            // who you are while a preview is running. ?>
      <?php if (in_user_preview()): $pu = preview_user(); ?>
        <span class="role-badge role-badge-preview">as <?= e((string)($pu['display_name'] ?: $pu['username'])) ?> · <?= e(role_in_words(preview_role())) ?></span>
      <?php elseif (in_preview()): ?>
        <span class="role-badge role-badge-preview">as <?= e(role_in_words(preview_role())) ?></span>
      <?php elseif (!can_edit()): ?>
        <span class="role-badge"><?= is_supervisor() ? 'supervisor' : 'artist' ?></span>
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
      <?php // THE BOARD, first and drawn as a button (David, 2026-10-22: "a big
            // button at the top will give you access to the board where you can
            // switch between projects so you don't have one per project and
            // just one place to take a look at the whole thing").
            //
            // ⚠ NOT A PROJECT TAB, deliberately. It carries its own show picker,
            // which is the whole point: it is the one place to see the work,
            // rather than a screen you reach only after choosing a show. It is
            // the screen replacing Plaky, so it is the first thing in the bar
            // and the only one here that looks like a button. ?>
      <?php // HOME (David, 2026-10-28): one person's shows, logs, notes and
            // invoice information. Only for a login linked to a person, because
            // that is whose Home it is; effective_crew_id() is cached and never
            // throws, so this costs the bar nothing. ?>
      <?php if (isset($pdo) && $pdo instanceof PDO && (int)(effective_crew_id($pdo) ?? 0) > 0): ?>
      <a href="home.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'home.php' ? ' is-on' : '' ?>">Home</a>
      <?php endif; ?>
      <a href="spell.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'spell.php' ? ' is-on' : '' ?>">Spell</a>
      <?php // A button too, beside Spell (David, 2026-10-22): the two screens
            // people open first thing, so they are the two that stand out. ?>
      <a href="mytasks.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'mytasks.php' ? ' is-on' : '' ?>">My tasks<?php if ($myTasksBadge['count'] > 0): ?><span
          class="mytasks-badge<?= $myTasksBadge['new'] ? ' is-new' : '' ?>"
          title="<?= $myTasksBadge['new']
              ? 'You have a new task' : 'Your active tasks' ?>"><?= (int)$myTasksBadge['count'] ?></span><?php endif; ?></a>
      <?php if ($reviewStage): ?>
      <a href="review.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'review.php' ? ' is-on' : '' ?>">Reviews<?php if ($reviewCount > 0): ?><span
          class="mytasks-badge is-new" title="Waiting for your review"><?= $reviewCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <?php // TASKS, next to My tasks and not inside a project, because the
            // question it answers spans them: "what is open everywhere, and how
            // are we doing across the shows" (David, 2026-10-07). Same reasoning
            // as Artists and Bookings — a thing about ALL the work does not
            // belong behind a choice of one show.
            //
            // ⚠ "PROJECTS" WAS HERE AND IS GONE (David, same day: "we can delete
            // the projects menu next to my tasks, we can always go back with home
            // or changing projects"). The brand at the top left IS the link to
            // the project list — it always has been, on every screen — and the
            // breadcrumb inside a project says "Projects /" as well, so the list
            // had three ways to reach it and one of them was a word in a row of
            // four. Check the brand is still a link before adding a fourth item
            // back here. ?>
      · <a href="alltasks.php">Studio Tasks</a>
      <?php // ARTISTS is up here, not inside a project, because a freelancer is
            // not the property of the show that first spoke to them — the whole
            // value of writing them down is that the NEXT show can find them.
            // Same reasoning as the step library and the ideal quotas.
            //
            // A third link rather than a fourth: what is on my desk, the shows,
            // and the people. Everything about one show is still reached by
            // picking it from Projects. ?>
      · <a href="artists.php">Artists</a>
      <?php // BOOKINGS beside Artists (David, 2026-09-21): the same people, seen
            // across every show at once, who is needed where and when two shows
            // want the same person. Up here for the reason Artists is: it is
            // about no one show, so no show's tabs could hold it. ?>
      · <a href="bookings.php">Bookings</a>

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
      <?php // BUGS, for everyone: an admin reads every report here, anyone
            // else the ones they filed. Filing one is the 🐞 button at the
            // bottom right of every screen, not this link. ?>
      <?php if (isset($pdo) && $pdo instanceof PDO && bugs_available($pdo)): ?>
        · <a href="bugs.php">Bugs<?php if ($openBugs > 0): ?><span class="pending-badge"
             title="Open bug reports"><?= $openBugs ?></span><?php endif; ?></a>
      <?php endif; ?>
      · <a href="<?= e(tour_link()) ?>" title="Show the guided tour again">Tour</a>
      · <a href="logout.php">Log out</a>
    </div>
  </div>
<?php if ($bell['on']): ?><script src="assets/bell.js<?= asset_ver('bell.js') ?>"></script><?php endif; ?>
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
      <?php if (in_user_preview()): $pvu = preview_user(); ?>
      <strong>Viewing as <?= e((string)($pvu['display_name'] ?: $pvu['username'])) ?> (<?= e(role_in_words(preview_role())) ?>).</strong>
      This is their view: their My tasks, the shows they are on, and the screens and buttons they get.
      <strong>Changes save</strong>, as their work, and are recorded as made by you.
      <?php else: ?>
      <strong>Previewing as <?= e(role_in_words(preview_role())) ?>.</strong>
      This is what they see: the menu, the screens they can open, the buttons they get.
      <strong>Nothing saves</strong> while you are in here, whichever role you are wearing.
      <?php endif; ?>
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
  <?php if (is_supervisor()): ?>
  <div class="flash flash-warn">You can edit a project's Shots, hire a candidate on Staffing, and set
    your own work to In progress or Pending review. Everything else, the rest of Timeline and Staffing
    included, is read-only. Ask an administrator if you need more.</div>
  <?php else: ?>
  <div class="flash flash-warn">You have view-only access, everything is readable, nothing
    can be changed. Ask an administrator if you need to edit something.</div>
  <?php endif; ?>
<?php endif; ?>
<?php // .flash-msg marks NEWS - what just happened - as against the standing
      // banners above, which are drawn on every load. assets/keepplace.js
      // floats only these down to wherever the reader is after a save, and a
      // permanent "migrations are waiting" notice following somebody down the
      // page would be a nag rather than a message. ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-msg flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>

<?php // KEEPS YOUR PLACE when a save reloads the page (David, 2026-09-02).
      // Loaded here rather than per screen because every screen saves this way.
      // defer, so it never blocks the page it is trying to make less annoying;
      // it needs nothing from the DOM until DOMContentLoaded anyway. ?>
<script defer src="assets/keepplace.js<?= asset_ver('keepplace.js') ?>"></script>
<?php if (isset($pdo) && $pdo instanceof PDO) render_bug_button($pdo); ?>
<?php if ($tourBoot): ?>
<link rel="stylesheet" href="assets/tour.css<?= asset_ver('tour.css') ?>">
<script defer src="assets/tour.js<?= asset_ver('tour.js') ?>" id="tourScript"<?= tour_attrs($pdo, $tourBoot) ?>></script>
<?php endif; ?>
