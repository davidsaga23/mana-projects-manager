<?php
// Reviews — the work waiting on you, and a verdict on each in one click.
//
// David, 2026-10-23 (roadmap) and 2026-10-27: a reviewer "can easily see what's
// for review, give review notes, approve directly or copy the URL of that
// version to bring the conversation to slack". Reached from the Reviews button
// in the top bar, beside My tasks, which carries the count.
//
// THREE TABS (David, 2026-11-20): Pending approvals (Ready for approval, the last
// sign-off, for Jorik and Tim), Supervisor approvals (Pending review) and Approved
// (Approved or Final, with a department filter and a sort). Admins and Production
// see all three, supervisors the last two; Production only looks at Pending
// approvals. Who sees which is review_tabs(), who may approve is review_stages(),
// both in includes/review_badge.php; review_apply() in includes/review.php checks
// the verdict again. Anyone who may approve may send to Retake instead.
//
// A GRID OF CARDS, AND THE PICTURE OPENS SPELL'S VIEWER (David, 2026-10-29: "a
// grid of cards instead of one item per line/box ... Once a supervisor clicks
// the image, they will see the same review view as in spell, they can leave
// notes directly there or have the option to attached images"). The viewer is
// assets/review-viewer.js, built from the task's Versions pane, which this page
// fetches from board_task.php?pane=versions when a picture is clicked. So the
// notes, the drawing and the files go through the same add_review as in Spell,
// and who may post is decided where it always was. Approve and Retake ride
// along into the viewer's top bar.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/uploads.php';   // version_label(), attachment_is_image()
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/digest.php';    // digest_told_sentence(), for @ in a note
require_login();

$stages = review_stages();
$tabs = review_tabs();
if (!$stages || !$tabs) {
    flash('err', 'Reviews are for supervisors, Production and admins.');
    header('Location: index.php');
    exit;
}

// THE TAB (David, 2026-11-20; who sees which is review_tabs()). The first one
// opens where this account has work to approve: Pending approvals for an admin,
// Supervisor approvals for everybody else.
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? '');
if (!in_array($tab, $tabs, true)) $tab = in_array('final', $stages, true) ? 'final' : 'supervisor';
$onApproved = $tab === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', review_apply($pdo, (int)($_POST['progress_id'] ?? 0),
            $action, (string)($_POST['note'] ?? '')));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    // Back to the tab and the list as it was filtered: several shows, or all but some.
    $back = http_build_query(['tab' => $tab]);
    $proj = filter_query('project', filter_get('project', $_POST));
    header('Location: review.php?' . $back . ($proj !== '' ? '&' . $proj : ''));
    exit;
}

$queue = review_queue($pdo, review_tab_names($tab));
// The count on each tab. The open one is the list itself; the others are one COUNT each.
$tabCount = [];
foreach ($tabs as $k) $tabCount[$k] = $k === $tab ? count($queue) : review_tab_count($pdo, review_tab_names($k));

// One chip per show that has something on this tab, so a long list can be cut
// down to the show you are on today. All of them by default.
$shows = [];
foreach ($queue as $t) {
    $pid = (int)$t['project_id'];
    $shows[$pid] = ($shows[$pid] ?? ['code' => $t['project_code'], 'n' => 0]);
    $shows[$pid]['n']++;
}
// ⚠ SEVERAL SHOWS, OR ALL BUT SOME (David, 2026-11-20: "Multiple selection …
// Reverse selection"). One dropdown of the shows, drawn by assets/multi-filter.js
// (a sheet along the bottom on a phone). ?project=12 from an older link still
// means just that show; a show with nothing waiting is dropped.
$only = filter_get('project');
$only['in'] = array_values(array_unique(array_map(fn($v) => (string)(int)$v,
                 array_filter($only['in'], fn($v) => isset($shows[(int)$v])))));
$only['on'] = $only['in'] !== [];
if (!$only['on']) $only['not'] = false;
$shown = array_values(array_filter($queue, fn($t) => filter_hit($only, (string)(int)$t['project_id'])));

// APPROVED: A DEPARTMENT FILTER AND A SORT (David, 2026-11-20: "We need to have a
// filter (single or multiple selection) by department and to have a sort button
// (alphabetical, by date, etc) so they're organized properly"). Departments by
// NAME, so "CH Layout" on two shows is one chip. Both ride in the address, so a
// filtered list can be bookmarked or sent.
$depts = [];       // name => how many, on the show(s) shown
$pickedDepts = [];
$sort = 'new';
$sorts = [
    'new'   => 'Newest first',
    'old'   => 'Oldest first',
    'az'    => 'Shot / asset A → Z',
    'za'    => 'Shot / asset Z → A',
    'dept'  => 'Department',
    'show'  => 'Show',
];
if ($onApproved) {
    foreach ($shown as $t) {
        $n = (string)$t['step_name'];
        $depts[$n] = ($depts[$n] ?? 0) + 1;
    }
    uksort($depts, 'strnatcasecmp');
    $pickedDepts = array_values(array_filter(array_map('strval', (array)($_GET['dept'] ?? [])),
        fn($d) => isset($depts[$d])));
    if ($pickedDepts) {
        $shown = array_values(array_filter($shown, fn($t) => in_array((string)$t['step_name'], $pickedDepts, true)));
    }
    $sort = isset($sorts[(string)($_GET['sort'] ?? '')]) ? (string)$_GET['sort'] : 'new';
    $when = fn($t) => (string)($t['version']['created_at'] ?? $t['updated_at']);
    $byName = fn($a, $b) => strnatcasecmp((string)$a['item_name'], (string)$b['item_name']);
    usort($shown, match ($sort) {
        'old'  => fn($a, $b) => strcmp($when($a), $when($b)),
        'az'   => fn($a, $b) => $byName($a, $b) ?: strnatcasecmp((string)$a['step_name'], (string)$b['step_name']),
        'za'   => fn($a, $b) => $byName($b, $a) ?: strnatcasecmp((string)$a['step_name'], (string)$b['step_name']),
        'dept' => fn($a, $b) => strnatcasecmp((string)$a['step_name'], (string)$b['step_name']) ?: $byName($a, $b),
        'show' => fn($a, $b) => strnatcasecmp((string)$a['project_code'], (string)$b['project_code']) ?: $byName($a, $b),
        default => fn($a, $b) => strcmp($when($b), $when($a)),
    });
}

/** This page's address with some of its settings changed (null drops one). */
$here = function (array $change = []) use ($tab, $only, $pickedDepts, $sort): string {
    // The show filter rides as project[] (+ project_not), as the dropdown posts it.
    $q = ['tab' => $tab, 'project' => $only['on'] ? $only['in'] : null,
          'project_not' => $only['not'] ? '1' : null, 'dept' => $pickedDepts ?: null,
          'sort' => $sort !== 'new' ? $sort : null];
    foreach ($change as $k => $v) $q[$k] = $v;
    return 'review.php?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== [] && $v !== ''));
};

// The two statuses the verdicts move to, per show, worked out once rather than
// per card. A show missing one gets a warning and that button off, rather than
// a button that is only refused after the click. Only for cards this account
// can give a verdict on (a 'stage' it reviews).
// Approve is per stage: keyed [stage][show].
$noApprove = [];
$noRetake = [];
foreach ($queue as $t) {
    if (!$t['stage']) continue;
    $pid = (int)$t['project_id'];
    $to = REVIEW_STAGES[$t['stage']]['to'];
    if (!isset($noApprove[$t['stage']][$pid]) && !review_status_named($pdo, $pid, $to)) {
        $noApprove[$t['stage']][$pid] = $shows[$pid]['code'];
    }
    if (!isset($noRetake[$pid]) && !review_status_named($pdo, $pid, REVIEW_RETAKE)) {
        $noRetake[$pid] = $shows[$pid]['code'];
    }
}
// The statuses as they read on screen since David's rename (2026-11-20, migration 107).
$statusWord = ['ready for review' => 'Ready for approval', 'complete' => 'Approved', 'pending review' => 'Pending review'];

$pageTitle = 'Reviews';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Reviews</h2>

<div class="panel-tabs rq-tabs" role="tablist" aria-label="Reviews">
  <?php foreach ($tabs as $k): ?>
    <a class="panel-tab<?= $k === $tab ? ' is-on' : '' ?>" role="tab" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>"
       href="review.php?tab=<?= e($k) ?>"><?= e(REVIEW_TABS[$k]) ?><?php if ($tabCount[$k] > 0): ?> <span
       class="<?= $k === 'approved' ? 'mytasks-badge' : 'mytasks-badge is-new' ?>"><?= (int)$tabCount[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>
<?php // INSTALL ON A PHONE (David, 2026-11-19: "add a install mobile app in the review
      // screen and it should save the page as if it was an app in both android and iPhone
      // ... ideally only visible if you're opening it in a mobile device"). Drawn hidden;
      // the script at the bottom shows it only on a phone or tablet that has not installed
      // it yet. Then: "the install button needs to do the actual install, not just tell the
      // user how to do it". On Android it does: the browser's own install window, made
      // possible by review-sw.js. An iPhone gives a website no way to install itself
      // (no API, no prompt), so there it opens a drawn guide to Share → Add to Home
      // Screen → Add ("do the same but with the share button", David, 2026-11-19). ?>
<div class="rq-install" id="rqInstall" hidden>
  <button type="button" class="rq-install-btn" id="rqInstallBtn">
    <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 1.5h8A1.5 1.5 0 0 1 15.5 3v14a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 17V3A1.5 1.5 0 0 1 6 1.5Zm0 1.5v14h8V3H6Zm4 2.5a.75.75 0 0 1 .75.75v4.19l1.22-1.22a.75.75 0 1 1 1.06 1.06l-2.5 2.5a.75.75 0 0 1-1.06 0l-2.5-2.5a.75.75 0 1 1 1.06-1.06l1.22 1.22V6.25A.75.75 0 0 1 10 5.5Z"/></svg>
    <span id="rqInstallLabel">Install Reviews as an app</span>
  </button>
  <button type="button" class="rq-install-x" id="rqInstallX" aria-label="Hide the install button" title="Hide this for a month">×</button>
  <div class="rq-install-help" id="rqInstallHelp" hidden></div>
</div>
<script src="assets/install-guide.js<?= asset_ver('install-guide.js') ?>"></script>
<script>
// Run here, not at the bottom: the manifest has to be swapped, and the browser's
// install event caught, before the page finishes loading.
(function () {
  'use strict';
  var box = document.getElementById('rqInstall');
  if (!box) return;
  var ua = navigator.userAgent || '';
  var ios = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var android = /Android/i.test(ua);
  if (!ios && !android && !/Mobile/i.test(ua)) return;   // a computer: never shown

  // NO PINCH-ZOOMING THE PAGE ON A PHONE (David, 2026-11-19: "don't allow zoom in or out
  // with the fingers"; the page only, not a picture in the review viewer, which keeps
  // its own pinch in assets/review-viewer.js). So it handles like an app, not a web page.
  // Android reads the viewport; Safari ignores that, so its own pinch gesture is stopped.
  try {
    var vp = document.querySelector('meta[name="viewport"]');
    if (vp) vp.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no');
    document.documentElement.classList.add('rq-app');
    ['gesturestart', 'gesturechange'].forEach(function (g) {
      document.addEventListener(g, function (e) { e.preventDefault(); }, { passive: false });
    });
  } catch (e) { /* zoom stays allowed: nothing breaks */ }

  // The do-nothing service worker Android needs before it offers to install (see the file).
  try {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('review-sw.js', { scope: './' }).catch(function () {});
  } catch (e) { /* the menu steps still work */ }
  var installed = false;
  try {
    installed = navigator.standalone === true
      || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  } catch (e) { /* treat as not installed */ }
  if (installed) return;

  // THE APP OPENS ON REVIEWS. On a phone this page points at manifest-review.json
  // ("Anima Reviews", starting at review.php) instead of the site's manifest.json,
  // which starts at the home page. Only on a phone, so installing Anima on a
  // computer from this page still installs the whole of Anima.
  try {
    var link = document.querySelector('link[rel="manifest"]');
    if (link) link.setAttribute('href', 'manifest-review.json');
    // The iPhone's own tags: its home-screen icon, its name under the icon, and
    // opening without Safari's bars around it.
    [['link', { rel: 'apple-touch-icon', href: 'assets/icon-192.png' }],
     ['meta', { name: 'apple-mobile-web-app-capable', content: 'yes' }],
     ['meta', { name: 'mobile-web-app-capable', content: 'yes' }],
     ['meta', { name: 'apple-mobile-web-app-title', content: 'Anima Reviews' }],
     ['meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' }]
    ].forEach(function (t) {
      var n = document.createElement(t[0]);
      Object.keys(t[1]).forEach(function (k) { n.setAttribute(k, t[1][k]); });
      document.head.appendChild(n);
    });
  } catch (e) { /* the button still explains the taps */ }

  var KEY = 'anima.rqInstallHidden';
  try {
    var hiddenAt = parseInt(window.localStorage.getItem(KEY), 10);
    if (hiddenAt && Date.now() - hiddenAt < 30 * 864e5) return;   // hidden for a month
  } catch (e) { /* private window: show it */ }

  var btn = document.getElementById('rqInstallBtn');
  var label = document.getElementById('rqInstallLabel');
  var help = document.getElementById('rqInstallHelp');
  var LABEL = label.textContent;
  var evt = null, wantedAt = 0;

  function say(html) { help.innerHTML = html; help.hidden = false; }

  /** The browser's own "Install app" window. */
  function install(e) {
    evt = null;
    help.hidden = true;
    var retry = function () { evt = e; label.textContent = 'Tap again to install'; };
    try {
      var p = e.prompt();
      if (p && p.catch) p.catch(retry);   // refused without a fresh tap: one more tap does it
    } catch (err) { retry(); return; }
    if (e.userChoice && e.userChoice.then) {
      e.userChoice.then(function (c) {
        if (c && c.outcome === 'accepted') box.hidden = true;
        else label.textContent = LABEL;
      }, function () {});
    }
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    evt = e;
    // Tapped before the browser was ready: install now, while that tap still counts
    // (a browser lets an install window open only within a few seconds of a tap).
    if (wantedAt) {
      var fresh = Date.now() - wantedAt < 4500;
      wantedAt = 0;
      if (fresh) install(e); else label.textContent = 'Tap again to install';
    }
  });
  window.addEventListener('appinstalled', function () { box.hidden = true; });
  box.hidden = false;

  btn.addEventListener('click', function () {
    if (evt) { install(evt); return; }
    if (ios) {
      // iPhone: no website can install itself there, so the button opens a guide to
      // the three taps, Share → Add to Home Screen → Add (assets/install-guide.js).
      if (window.animaInstallGuide) window.animaInstallGuide.open(/CriOS|FxiOS|EdgiOS|OPiOS|GSA\//.test(ua));
      return;
    }
    if (wantedAt) return;
    // Android, but the browser has not offered yet: wait a moment for it.
    wantedAt = Date.now();
    label.textContent = 'Getting the install ready…';
    setTimeout(function () {
      if (!wantedAt) return;
      wantedAt = 0;
      label.textContent = LABEL;
      say('This browser did not offer to install. If <strong>Reviews</strong> is already on your home '
        + 'screen, open it from there. Otherwise open the browser menu <strong>⋮</strong> (top right) and '
        + 'tap <strong>Install app</strong> or <strong>Add to Home screen</strong>.');
    }, 8000);
  });
  document.getElementById('rqInstallX').addEventListener('click', function () {
    box.hidden = true;
    try { window.localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* fine */ }
  });
}());
</script>

<?php // What each tab is, in a line. ?>
<p class="lead rq-lead">
  <?php if ($tab === 'final'): ?>
    Work the supervisors have approved, now <strong>Ready for approval</strong>: the last sign-off, for
    Jorik and Tim. <strong>Approve</strong> moves it to <strong>Approved</strong>, <strong>Retake</strong> to
    <strong>Retake</strong>.<?php if (!in_array('final', $stages, true)): ?> You can open and comment on
    these; Jorik and Tim approve them.<?php endif; ?>
  <?php elseif ($tab === 'supervisor'): ?>
    New versions in <strong>Pending review</strong>, waiting for a supervisor. <strong>Approve</strong> moves it to
    <strong>Ready for approval</strong>, <strong>Retake</strong> to <strong>Retake</strong>.
  <?php else: ?>
    Everything <strong>Approved</strong> or <strong>Final</strong> on the shows you can see. Pick departments and
    a sort below.
  <?php endif; ?>
  Click a picture to open it in the review viewer.
</p>

<?php
$warnings = [];
foreach ($noApprove as $k => $missing) $warnings[] = [$missing, $statusWord[REVIEW_STAGES[$k]['to']] ?? ucwords(REVIEW_STAGES[$k]['to']), 'Approve'];
$warnings[] = [$noRetake, 'Retake', 'Retake'];
foreach ($warnings as [$missing, $named, $button]):
    if (!$missing) continue; ?>
  <p class="flash flash-warn">
    <?= e(implode(', ', $missing)) ?> <?= count($missing) === 1 ? 'has' : 'have' ?> no status called
    <strong><?= e($named) ?></strong>, so <?= e($button) ?> is off there until it is added on the
    Statuses screen.
  </p>
<?php endforeach; ?>

<?php if (count($shows) > 1): ?>
  <?php // ONE DROPDOWN OF THE SHOWS (2026-11-20), in place of a chip per show:
        // pick several, or Exclude to hide some. On a phone it opens as a sheet
        // along the bottom, which is what the chips' fold-out button did
        // (David, 2026-11-19: "a mobile app version of the review tab"). It keeps
        // the tab and the sort; the departments start again, as the chips did. ?>
  <form method="get" class="rq-show-form">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($sort !== 'new'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
    <select name="project" onchange="this.form.submit()" aria-label="Filter by show"<?= filter_select_attrs($only, 'Show') ?>>
      <option value="">All shows (<?= count($queue) ?>)</option>
      <?php foreach ($shows as $pid => $s): ?>
        <option value="<?= (int)$pid ?>"<?= filter_sel($only, (string)$pid) ?>><?= e($s['code']) ?> (<?= $s['n'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="secondary">Show</button></noscript>
  </form>
<?php endif; ?>

<?php if ($onApproved && $depts): ?>
  <?php // DEPARTMENTS, any number of them: a chip toggles itself in or out of the
        // list; "All departments" clears it. Then the sort. Plain links and a plain
        // form, so it all works before any script and the address keeps the choice. ?>
  <div class="rq-approved-tools">
    <p class="board-projects rq-depts" aria-label="Departments">
      <a class="board-chip<?= $pickedDepts ? '' : ' is-on' ?>" href="<?= e($here(['dept' => null])) ?>">All departments</a>
      <?php foreach ($depts as $d => $n):
          $on = in_array($d, $pickedDepts, true);
          $next = $on ? array_values(array_diff($pickedDepts, [$d])) : array_merge($pickedDepts, [$d]); ?>
        <a class="board-chip<?= $on ? ' is-on' : '' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
           href="<?= e($here(['dept' => $next ?: null])) ?>"><?= $on ? '✓ ' : '' ?><?= e($d) ?> (<?= (int)$n ?>)</a>
      <?php endforeach; ?>
    </p>
    <form method="get" class="rq-sort">
      <input type="hidden" name="tab" value="approved">
      <?php foreach ($only['in'] as $op): ?><input type="hidden" name="project[]" value="<?= e($op) ?>"><?php endforeach; ?>
      <?php if ($only['not']): ?><input type="hidden" name="project_not" value="1"><?php endif; ?>
      <?php foreach ($pickedDepts as $d): ?><input type="hidden" name="dept[]" value="<?= e($d) ?>"><?php endforeach; ?>
      <label>Sort
        <select name="sort" onchange="this.form.submit()">
          <?php foreach ($sorts as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="secondary">Sort</button></noscript>
    </form>
  </div>
<?php endif; ?>

<?php if (!$shown): ?>
  <div class="empty-state"><p>
    <?php if ($tab === 'final'): ?>Nothing is waiting for the last sign-off. Tasks arrive here when a supervisor approves them (<strong>Ready for approval</strong>).
    <?php elseif ($tab === 'supervisor'): ?>Nothing is waiting for a supervisor. Tasks arrive here when an artist puts a version up (<strong>Pending review</strong>).
    <?php elseif ($pickedDepts): ?>Nothing approved in those departments. <a href="<?= e($here(['dept' => null])) ?>">Show every department</a>.
    <?php else: ?>Nothing is Approved or Final yet on the shows you can see.<?php endif; ?>
  </p></div>
<?php else: ?>
  <ul class="rq-grid">
  <?php foreach ($shown as $t):
      $pid = (int)$t['project_id'];
      // Can this account give the verdict on this card? Only on a stage it reviews
      // (review_stages()): never on Approved, and Production only looks at Pending approvals.
      $canAct = !empty($t['stage']);
      $to = $canAct ? ($statusWord[REVIEW_STAGES[$t['stage']]['to']] ?? ucwords(REVIEW_STAGES[$t['stage']]['to'])) : '';
      $offApprove = $canAct && isset($noApprove[$t['stage']][$pid]);
      $v = $t['version'];
      $openPath = board_task_path($pid, (int)$t['step_id'], (string)$t['item_key'], 'versions', $v ? 'v' . (int)$v['id'] : '');
      $mime = $v ? (string)($v['mime_type'] ?? '') : '';
      $isImage = $v && attachment_is_image($mime);
      $isVideo = $v && attachment_is_video($mime);
      $fileUrl = $v ? 'attachment.php?kind=version&amp;id=' . (int)$v['id'] : '';
      $vName = $v ? e((string)$v['original_name']) : '';
      // The version and its note count, laid over the corners of the picture.
      $tags = '';
      if ($v) $tags .= '<span class="version-tag">' . e(version_label((int)$v['version_no'])) . '</span>';
      if ($t['notes'] > 0) {
          $tags .= '<span class="review-notes-count" title="Review notes already on this version">'
                 . (int)$t['notes'] . ' note' . ($t['notes'] === 1 ? '' : 's') . '</span>';
      }
  ?>
    <li class="rq-card">
      <?php if ($isImage || $isVideo): ?>
        <?php // The way into the viewer: which task, and which version to open on. ?>
        <button type="button" class="rq-media" title="Open in the review viewer"
                data-rq-open="<?= (int)$v['id'] ?>" data-project="<?= $pid ?>"
                data-step="<?= (int)$t['step_id'] ?>" data-item="<?= e((string)$t['item_key']) ?>">
          <?php if ($isImage): ?>
            <img src="<?= $fileUrl ?>" alt="<?= $vName ?>" loading="lazy">
          <?php else: ?>
            <?php // A frame just in, as the thumbnail; preload="metadata" fetches only the start until it is opened. ?>
            <video src="<?= $fileUrl ?>#t=0.1" preload="metadata" muted playsinline></video>
            <span class="rq-play" aria-hidden="true">▶</span>
          <?php endif; ?>
          <?= $tags ?>
        </button>
      <?php elseif ($v): ?>
        <?php // A PDF or anything else the viewer cannot show: the file itself. ?>
        <a class="rq-media is-file" href="<?= $fileUrl ?>" target="_blank" rel="noopener"><?= $vName ?><?= $tags ?></a>
      <?php else: ?>
        <?php // David, 2026-11-06: "make these clickable to open that shot and task specifically
              // in the side bar as the behavior in spell", and then: open it HERE, "instead of
              // staying in the review tab and only opening the side panel". Spell's own task
              // panel, borrowed the way My tasks borrows it: the same button class and data
              // attributes, opened by assets/board-screen.js on its Versions tab. ?>
        <button type="button" class="rq-media is-empty board-open-task" data-tab="versions"
                data-project="<?= $pid ?>" data-step="<?= (int)$t['step_id'] ?>"
                data-item="<?= e((string)$t['item_key']) ?>" title="Open this task's panel">No version uploaded yet</button>
      <?php endif; ?>

      <div class="rq-info">
        <div class="rq-title">
          <span class="dot" style="background:<?= e($t['step_color'] ?: '#ccc') ?>"></span>
          <button type="button" class="rq-task board-open-task" data-tab="versions"
                  data-project="<?= $pid ?>" data-step="<?= (int)$t['step_id'] ?>"
                  data-item="<?= e((string)$t['item_key']) ?>"
                  title="Open <?= e($t['item_name'] . ' · ' . $t['step_name']) ?>"><strong><?= e($t['item_name']) ?> · <?= e($t['step_name']) ?></strong></button>
          <span class="muted small rq-show" title="<?= e((string)$t['project_name']) ?>"><?= e($t['project_code']) ?></span>
        </div>
        <?php if ($onApproved): ?>
          <?php // Approved or Final: which one. ?>
          <span class="rq-stage is-done"><?= e((string)$t['status_name']) ?></span>
        <?php endif; ?>
        <div class="muted small rq-meta">
          <?= $t['crew_name'] ? e($t['crew_name']) : 'Nobody assigned' ?>
          <?php if ($v): ?>· put up <?= e(date('j M · H:i', strtotime((string)$v['created_at']))) ?><?php endif; ?>
        </div>
        <?php if ($v && trim((string)($v['note'] ?? '')) !== ''): ?>
          <div class="muted small rq-meta" title="<?= e((string)$v['note']) ?>">“<?= e((string)$v['note']) ?>”</div>
        <?php endif; ?>
        <?php if ($v && !empty($v['link'])): ?>
          <div class="small"><a href="<?= e((string)$v['link']) ?>" target="_blank" rel="noopener">Final files ↗</a></div>
        <?php endif; ?>
      </div>

      <form method="post" class="rq-verdict">
        <input type="hidden" name="progress_id" value="<?= (int)$t['id'] ?>">
        <?php foreach ($only['in'] as $op): ?><input type="hidden" name="project[]" value="<?= e($op) ?>"><?php endforeach; ?>
        <?php if ($only['not']): ?><input type="hidden" name="project_not" value="1"><?php endif; ?>
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($canAct): ?>
        <div class="rq-buttons">
          <button type="submit" name="action" value="approve"
                  title="<?= $offApprove ? e($t['project_code'] . ' has no ' . $to . ' status') : 'Moves it to ' . e($to) ?>"
                  <?= $offApprove ? 'disabled' : '' ?>>Approve</button>
          <button type="submit" name="action" value="retake" class="secondary danger"
                  title="<?= isset($noRetake[$pid]) ? e($t['project_code'] . ' has no Retake status') : 'Moves it to Retake' ?>"
                  <?= isset($noRetake[$pid]) ? 'disabled' : '' ?>>Retake</button>
        </div>
        <?php elseif ($tab === 'final'): ?>
          <p class="muted small rq-lookonly">Jorik and Tim approve these.</p>
        <?php endif; ?>
        <div class="rq-links">
          <a href="<?= e($openPath) ?>">Open in Spell</a>
          <button type="button" class="secondary board-copy-url" data-copy="<?= e($openPath) ?>"
                  title="A link to this version. It opens for anyone signed in to Anima, not publicly.">Copy URL</button>
        </div>
      </form>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php // Where the clicked task's Versions pane is put for the viewer to read. ?>
<div id="rqPane" hidden></div>

<?php // SPELL'S TASK PANEL, borrowed as My tasks borrows it (David, 2026-11-06): the
      // same markup spell.php draws, filled from board_task.php by board-screen.js, so
      // versions, notes and comments work here exactly as they do in Spell. ?>
<div class="board-panel-veil" id="boardVeil" hidden></div>
<aside class="board-panel" id="boardPanel" role="dialog" aria-modal="true"
       aria-label="Task details" hidden>
  <button type="button" class="board-panel-close" id="boardPanelClose" aria-label="Close">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.1" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
  </button>
  <div class="board-panel-inner" id="boardPanelInner"></div>
</aside>
<?php // The @ picker in the viewer's box, the lightbox (tickets.js) for a picture
      // in somebody's note, the files going with a review, and the viewer. ?>
<script src="assets/mentions.js<?= asset_ver('mentions.js') ?>"></script>
<script src="assets/dropzone.js<?= asset_ver('dropzone.js') ?>"></script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
<?php // Opens the panel, and handles Copy URL for the whole page. ?>
<script src="assets/board-screen.js<?= asset_ver('board-screen.js') ?>"></script>
<script src="assets/review-files.js<?= asset_ver('review-files.js') ?>"></script>
<script src="assets/review-viewer.js<?= asset_ver('review-viewer.js') ?>"></script>
<script>
// A picture opens Spell's review viewer. The viewer is built from the task's
// Versions pane, so that pane is fetched first (board_task.php?pane=versions,
// the same checks as the task panel), and the card's own Approve and Retake
// go into the viewer's top bar: pressing one there presses it here.
document.addEventListener('click', function (e) {
  var t = e.target.closest ? e.target.closest('[data-rq-open]') : null;
  if (!t || t.classList.contains('is-loading')) return;
  e.preventDefault();
  var card = t.closest('.rq-card');
  var spell = card.querySelector('.rq-links a');
  var host = document.getElementById('rqPane');
  var url = 'board_task.php?pane=versions&project=' + encodeURIComponent(t.getAttribute('data-project'))
          + '&step=' + encodeURIComponent(t.getAttribute('data-step'))
          + '&item=' + encodeURIComponent(t.getAttribute('data-item'));
  t.classList.add('is-loading');
  fetch(url, { credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(function (html) {
      t.classList.remove('is-loading');
      host.innerHTML = html;
      var pane = host.querySelector('[data-pane]');
      if (!pane || !window.animaReviewViewer || !pane.querySelector('li.version')) throw new Error('no pane');
      var actions = [];
      card.querySelectorAll('.rq-buttons button').forEach(function (b) {
        if (b.disabled) return;
        actions.push({ label: b.textContent.trim(), cls: b.className, title: b.title,
                       run: function () { b.click(); } });
      });
      window.animaReviewViewer.open(pane, t.getAttribute('data-rq-open'), { actions: actions });
    })
    .catch(function () {
      // Whatever went wrong, the task in Spell still has everything.
      t.classList.remove('is-loading');
      if (spell) window.location.href = spell.href;
    });
});
// A status changed or a version put up in the panel moves the task on this
// list (or off it), so the list is reloaded when the panel closes after
// anything was saved in it. The same as My tasks.
(function () {
  var panel = document.getElementById('boardPanel');
  if (!panel || !window.MutationObserver) return;
  var touched = false;
  ['change', 'submit'].forEach(function (ev) {
    panel.addEventListener(ev, function () { touched = true; }, true);
  });
  new MutationObserver(function () {
    if (panel.hidden && touched) location.reload();
  }).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
}());
</script>

</div>
</body></html>
