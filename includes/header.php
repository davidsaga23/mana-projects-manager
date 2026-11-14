<?php
// Shared page chrome. Set $pageTitle before including.
$pageTitle = $pageTitle ?? 'Anima | Project manager';
// $pdo is in scope here because every page requires db.php before this file.
$pendingMigrations = (isset($pdo) && $pdo instanceof PDO && is_admin())
    ? pending_migration_count($pdo) : 0;
// ...plus the FINANCE database's updates, which apply from migrate.php too
// since 2026-11-11. Admins only, counted inside includes/money.php (the only
// file that may open that connection), kept in the session for ten minutes so
// it is not a second database connection on every page, and never able to
// throw: any trouble counts as 0 (see finance_pending_count()).
$pendingFinance = 0;
if (is_admin()) {
    try {
        require_once __DIR__ . '/money.php';
        $pendingFinance = finance_pending_count();
    } catch (Throwable $e) {
        $pendingFinance = 0;
    }
}
$pendingMigrations += $pendingFinance;
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
$reviewStage = review_stage();   // null while "Reviews" is switched off (migration 077)
$reviewCount = ($reviewStage && isset($pdo) && $pdo instanceof PDO) ? review_count($pdo) : 0;

// The Invoices button and its count (David, 2026-11-11: "an invoice payment
// tab/button at the top next to reviews for Jorik"): how many invoices have
// arrived and wait to be paid, on every show. Admins only, because invoices are
// money. Kept in the session for two minutes and never able to throw, like the
// finance count above: any trouble counts as 0. See includes/payments.php.
$paymentsCount = 0;
if (is_admin()) {
    try {
        require_once __DIR__ . '/payments.php';
        $paymentsCount = payments_waiting_count();
    } catch (Throwable $e) {
        $paymentsCount = 0;
    }
}

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
// fa(): is this switch on (migration 077)? True when features.php is not
// loaded, so the bar draws as it always did rather than losing every link.
$fa = fn(string $k): bool => !function_exists('feature_on') || feature_on($k);
if (isset($pdo) && $pdo instanceof PDO && $fa('bell') && notify_available($pdo)) {
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
$tourBoot = $fa('tour') ? tour_boot(isset($pdo) && $pdo instanceof PDO ? $pdo : null) : null;
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
      <?php // Every link below is drawn only while its switch is on (Users →
            // Access, migration 077), and the page itself refuses the same way,
            // so the bar and the door cannot disagree. ?>
      <?php if ($fa('home') && isset($pdo) && $pdo instanceof PDO && (int)(effective_crew_id($pdo) ?? 0) > 0): ?>
      <a href="home.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'home.php' ? ' is-on' : '' ?>">Home</a>
      <?php endif; ?>
      <?php if ($fa('spell')): ?>
      <a href="spell.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'spell.php' ? ' is-on' : '' ?>">Spell</a>
      <?php endif; ?>
      <?php // The Realm (David, 2026-11-12). Its own full-screen page, so this is
            // only the way in; drawn while the switch is on, like the rest. ?>
      <?php if (function_exists('feature_on') && feature_on('realm')): ?>
      <a href="realm.php" class="nav-board" id="navRealm">Realm</a>
      <?php endif; ?>
      <?php // A button too, beside Spell (David, 2026-10-22): the two screens
            // people open first thing, so they are the two that stand out. ?>
      <?php if ($fa('mytasks')): ?>
      <a href="mytasks.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'mytasks.php' ? ' is-on' : '' ?>">My tasks<?php if ($myTasksBadge['count'] > 0): ?><span
          class="mytasks-badge<?= $myTasksBadge['new'] ? ' is-new' : '' ?>"
          title="<?= $myTasksBadge['new']
              ? 'You have a new task' : 'Your active tasks' ?>"><?= (int)$myTasksBadge['count'] ?></span><?php endif; ?></a>
      <?php endif; ?>
      <?php if ($reviewStage): ?>
      <a href="review.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'review.php' ? ' is-on' : '' ?>">Reviews<?php if ($reviewCount > 0): ?><span
          class="mytasks-badge is-new" title="Waiting for your review"><?= $reviewCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
      <a href="payments.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'payments.php' ? ' is-on' : '' ?>"
         title="Invoice payments">Invoices<?php if ($paymentsCount > 0): ?><span
          class="mytasks-badge is-new" title="Invoices waiting to be paid"><?= (int)$paymentsCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <?php // COMMERCIAL, the CRM (David, 2026-11-12): leads, proposals and
            // quotes. The prototype put it in a sidebar; Anima has none, so it is
            // a button here and its six views are tabs inside. Its own switch,
            // admins only to begin with. ?>
      <?php if (function_exists('feature_on') && feature_on('commercial') && can_edit()): ?>
      <a href="commercial.php" class="nav-board<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'commercial.php' ? ' is-on' : '' ?>">Commercial</a>
      <?php endif; ?>
      <?php
      // ⚠ THE ADMIN MENU (David, 2026-11-13: "let's create a button that says
      // admin, inside that let's include all these tabs so we don't have such a
      // busy top bar"). The plain links that used to trail the buttons are now
      // one dropdown. Only the PRESENTATION moved: every link below keeps the
      // exact switch and permission check it had as a bare link, so the menu
      // offers precisely what the bar used to, person by person.
      //
      // Three groups, divided by a line: the studio pages, the admin tools, and
      // you (Tour, Log out). Built as a list first so an empty group draws no
      // divider and an empty menu draws no button at all.
      //
      // "Admin" only for an admin. Anybody else gets the same menu with only
      // their links, called "More": an artist has Tour and Log out, and a
      // button saying Admin over those would promise something it cannot keep.
      $navHere = basename($_SERVER['SCRIPT_NAME'] ?? '');
      $navGroups = [[], [], []];

      // TASKS, not inside a project, because the question it answers spans
      // them: "what is open everywhere, and how are we doing across the shows"
      // (David, 2026-10-07). ⚠ "PROJECTS" WAS ONCE BESIDE IT AND IS GONE (same
      // day): the brand at the top left IS the link to the project list, on
      // every screen. Check it still is before adding a Projects link back.
      if ($fa('studio_tasks')) $navGroups[0][] = ['href' => 'alltasks.php', 'label' => 'Studio Tasks'];
      // ARTISTS, not inside a project, because a freelancer is not the property
      // of the show that first spoke to them: the value of writing them down is
      // that the NEXT show can find them.
      if ($fa('artists')) $navGroups[0][] = ['href' => 'artists.php', 'label' => 'Artists pool'];
      // BOOKINGS (David, 2026-09-21): the same people across every show at once.
      if ($fa('bookings')) $navGroups[0][] = ['href' => 'bookings.php', 'label' => 'Bookings'];
      // THE RECORDS (David, 2026-09-01: "check the records and see what we used
      // to charge certain slots or quotas"). Two links, because a quota is a
      // production question and a fee a money one. Fees are admin-only, like
      // every screen carrying a rate; quotas are production facts.
      if ($fa('quotas')) $navGroups[0][] = ['href' => 'history_quotas.php', 'label' => 'Historic quotas'];
      if (is_admin()) $navGroups[0][] = ['href' => 'history_fees.php', 'label' => 'Historic fees'];

      // The application itself: accounts, mail, the database, the reports.
      // USERS for Production too (David, 2026-11-05), through its switch.
      // Email and Migrations stay admin-only and are not switches.
      if ($fa('users') && (is_admin() || can_edit())) $navGroups[1][] = ['href' => 'users.php', 'label' => 'Users'];
      if (is_admin()) {
          $navGroups[1][] = ['href' => 'smtp_test.php', 'label' => 'Email'];
          $navGroups[1][] = ['href' => 'migrate.php', 'label' => 'Migrations',
              'badge' => (int)$pendingMigrations, 'badgeTitle' => 'Database updates waiting',
              'warn' => $pendingMigrations > 0];
      }
      // BUGS: an admin reads every report here, anyone else the ones they
      // filed. Filing one is the 🐞 button at the bottom right, not this link.
      if ($fa('bugs_list') && isset($pdo) && $pdo instanceof PDO && bugs_available($pdo)) {
          $navGroups[1][] = ['href' => 'bugs.php', 'label' => 'Bugs',
              'badge' => (int)$openBugs, 'badgeTitle' => 'Open bug reports'];
      }

      // Tour carries this page's own address (?tour=0), so it is never marked
      // as the current page even though its file name matches.
      if ($fa('tour')) $navGroups[2][] = ['href' => tour_link(), 'label' => 'Tour',
          'title' => 'Show the guided tour again', 'noHere' => true];
      $navGroups[2][] = ['href' => 'logout.php', 'label' => 'Log out'];

      $navGroups = array_values(array_filter($navGroups));
      // What the CLOSED button still owes you: the counts that used to sit in
      // the bar (pending migrations, open bugs), and whether you are on one of
      // its pages, so "where am I" survives the links being tucked away.
      $navBadge = 0; $navOn = false; $navBadgeTitles = [];
      foreach ($navGroups as $g) {
          foreach ($g as $it) {
              if (!empty($it['badge'])) {
                  $navBadge += $it['badge'];
                  $navBadgeTitles[] = $it['badge'] . ' ' . strtolower($it['badgeTitle']);
              }
              if (empty($it['noHere']) && basename((string)parse_url($it['href'], PHP_URL_PATH)) === $navHere) $navOn = true;
          }
      }
      ?>
      <?php if ($navGroups): ?>
      <div class="nav-menu" id="navMenu">
        <button type="button" class="nav-board nav-menu-btn<?= $navOn ? ' is-on' : '' ?>" id="navMenuBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="navMenuPanel"><?= is_admin() ? 'Admin' : 'More' ?><?php
          if ($navBadge > 0): ?><span class="pending-badge" title="<?= e(implode(', ', $navBadgeTitles)) ?>"><?= (int)$navBadge ?></span><?php endif;
          ?><svg class="nav-menu-caret" viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" focusable="false"><path fill="currentColor" d="M1.5 3.5h7L5 7.5z"/></svg></button>
        <div class="nav-menu-panel" id="navMenuPanel" hidden>
          <?php foreach ($navGroups as $gi => $g): ?>
            <?php if ($gi > 0): ?><div class="nav-menu-sep" role="separator"></div><?php endif; ?>
            <?php foreach ($g as $it):
                $itOn = empty($it['noHere']) && basename((string)parse_url($it['href'], PHP_URL_PATH)) === $navHere; ?>
              <a href="<?= e($it['href']) ?>" class="nav-menu-item<?= $itOn ? ' is-on' : '' ?><?= !empty($it['warn']) ? ' needs-action' : '' ?>"<?=
                 $itOn ? ' aria-current="page"' : '' ?><?= !empty($it['title']) ? ' title="' . e($it['title']) . '"' : '' ?>><?= e($it['label']) ?><?php
                 if (!empty($it['badge'])): ?><span class="pending-badge" title="<?= e($it['badgeTitle']) ?>"><?= (int)$it['badge'] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php if ($bell['on']): ?><script src="assets/bell.js<?= asset_ver('bell.js') ?>"></script><?php endif; ?>
<?php // The Admin / More menu above: open, close, keys. Tiny, no library. ?>
<script defer src="assets/nav-menu.js<?= asset_ver('nav-menu.js') ?>"></script>
<?php // The unread count on the Realm button, kept fresh (David, 2026-11-12: "I need to be
      // on that page"). Only for somebody who has the Realm; see assets/realm-nudge.js. ?>
<?php if (function_exists('feature_on') && feature_on('realm')): ?><script defer src="assets/realm-nudge.js<?= asset_ver('realm-nudge.js') ?>"></script><?php endif; ?>
<?php // THE "LOG YOUR TIME" POP-UP (David, 2026-11-11; includes/log_reminders.php).
      // Only a script tag here: it asks log_reminder_api.php every few minutes
      // and the server decides the rest, so the page pays nothing more. Not
      // while viewing as somebody: their reminder is theirs to put away. ?>
<?php if ($fa('log_reminders') && !in_preview() && isset($pdo) && $pdo instanceof PDO && (int)(effective_crew_id($pdo) ?? 0) > 0): ?>
<script defer src="assets/log-reminder.js<?= asset_ver('log-reminder.js') ?>" data-css="assets/log-reminder.css<?= asset_ver('log-reminder.css') ?>"></script>
<?php endif; ?>
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
<?php if ($fa('bug_report') && isset($pdo) && $pdo instanceof PDO) render_bug_button($pdo); ?>
<?php if ($tourBoot): ?>
<link rel="stylesheet" href="assets/tour.css<?= asset_ver('tour.css') ?>">
<script defer src="assets/tour.js<?= asset_ver('tour.js') ?>" id="tourScript"<?= tour_attrs($pdo, $tourBoot) ?>></script>
<?php endif; ?>
