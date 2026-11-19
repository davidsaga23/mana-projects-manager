<?php
// Reviews — the work waiting on you, and a verdict on each in one click.
//
// David, 2026-10-23 (roadmap) and 2026-10-27: a reviewer "can easily see what's
// for review, give review notes, approve directly or copy the URL of that
// version to bring the conversation to slack". Reached from the Reviews button
// in the top bar, beside My tasks, which carries the count.
//
// Supervisors and Production see Pending review and approve to Ready for
// review; admins (Jorik, Tim) see both stages (2026-10-29), each card saying
// which, and approve each to its next status. Anyone may send it to Retake instead. The rules are
// in includes/review.php.
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
if (!$stages) {
    flash('err', 'Reviews are for supervisors, Production and admins.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', review_apply($pdo, (int)($_POST['progress_id'] ?? 0),
            $action, (string)($_POST['note'] ?? '')));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: review.php' . (isset($_POST['project']) && $_POST['project'] !== ''
        ? '?project=' . (int)$_POST['project'] : ''));
    exit;
}

$queue = review_queue($pdo);

// One chip per show that has something waiting, so a long queue can be cut down
// to the show you are on today. All of them by default.
$shows = [];
foreach ($queue as $t) {
    $pid = (int)$t['project_id'];
    $shows[$pid] = ($shows[$pid] ?? ['code' => $t['project_code'], 'n' => 0]);
    $shows[$pid]['n']++;
}
$only = (int)($_GET['project'] ?? 0);
if ($only && !isset($shows[$only])) $only = 0;
$shown = $only ? array_values(array_filter($queue, fn($t) => (int)$t['project_id'] === $only)) : $queue;

// The two statuses the verdicts move to, per show, worked out once rather than
// per card. A show missing one gets a warning and that button off, rather than
// a button that is only refused after the click.
// Approve is per stage: keyed [stage][show].
$noApprove = [];
$noRetake = [];
foreach ($queue as $t) {
    $pid = (int)$t['project_id'];
    $to = REVIEW_STAGES[$t['stage']]['to'];
    if (!isset($noApprove[$t['stage']][$pid]) && !review_status_named($pdo, $pid, $to)) {
        $noApprove[$t['stage']][$pid] = $shows[$pid]['code'];
    }
    if (!isset($noRetake[$pid]) && !review_status_named($pdo, $pid, REVIEW_RETAKE)) {
        $noRetake[$pid] = $shows[$pid]['code'];
    }
}
// How the stages read in the sentences below: "Pending review → Ready For Review".
$stageLine = fn(string $k) => '<strong>' . e(ucwords(REVIEW_STAGES[$k]['from'])) . '</strong> → <strong>'
                            . e(ucwords(REVIEW_STAGES[$k]['to'])) . '</strong>';

$pageTitle = 'Reviews';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title"><?= count($stages) > 1 ? 'Reviews' : e(REVIEW_STAGES[$stages[0]]['label']) ?>
  <span class="muted">· <?= count($queue) ?> waiting on you</span></h2>
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
     ['meta', { name: 'apple-mobile-web-app-title', content: 'Reviews' }],
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

<p class="lead">
  Everything waiting for your review on the shows you can see, newest version first.
  <strong>Approve</strong> moves it on: <?= implode('; ', array_map($stageLine, $stages)) ?>.
  <strong>Retake</strong> moves it to <strong>Retake</strong>. Click the picture to open it in the review
  viewer, the same one as in Spell: step through it, draw on it, write notes and attach images. Approve and
  Retake are also at the top of the viewer.
</p>

<?php
$warnings = [];
foreach ($noApprove as $k => $missing) $warnings[] = [$missing, ucwords(REVIEW_STAGES[$k]['to']), 'Approve'];
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
  <?php // ON A PHONE THE CHIPS FOLD BEHIND ONE BUTTON (David, 2026-11-19: "a mobile app
        // version of the review tab to review on the go"). The button is only drawn at
        // phone width (assets/style.css); on a desktop the chips show as they always did. ?>
  <button type="button" class="secondary rq-filter-toggle" aria-expanded="false" aria-controls="rqShows">
    Show: <?= $only ? e($shows[$only]['code']) . ' (' . $shows[$only]['n'] . ')' : 'All shows (' . count($queue) . ')' ?> ▾
  </button>
  <p class="board-projects review-shows" id="rqShows">
    <a class="board-chip<?= $only ? '' : ' is-on' ?>" href="review.php">All shows (<?= count($queue) ?>)</a>
    <?php foreach ($shows as $pid => $s): ?>
      <a class="board-chip<?= $only === $pid ? ' is-on' : '' ?>" href="review.php?project=<?= $pid ?>"><?= e($s['code']) ?> (<?= $s['n'] ?>)</a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<?php if (!$shown): ?>
  <div class="empty-state"><p>Nothing is waiting for your review. Tasks arrive here when they are set to
    <?= implode(' or ', array_map(fn($k) => '<strong>' . e(ucwords(REVIEW_STAGES[$k]['from'])) . '</strong>', $stages)) ?>.</p></div>
<?php else: ?>
  <ul class="rq-grid">
  <?php foreach ($shown as $t):
      $pid = (int)$t['project_id'];
      $to = ucwords(REVIEW_STAGES[$t['stage']]['to']);
      $offApprove = isset($noApprove[$t['stage']][$pid]);
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
        <?php if (count($stages) > 1): ?>
          <?php // Which stage, when this page shows both: the status it is in now. ?>
          <span class="rq-stage is-<?= e($t['stage']) ?>"><?= e((string)$t['status_name']) ?></span>
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
        <input type="hidden" name="project" value="<?= $only ?: '' ?>">
        <div class="rq-buttons">
          <button type="submit" name="action" value="approve"
                  title="<?= $offApprove ? e($t['project_code'] . ' has no ' . $to . ' status') : 'Moves it to ' . e($to) ?>"
                  <?= $offApprove ? 'disabled' : '' ?>>Approve</button>
          <button type="submit" name="action" value="retake" class="secondary danger"
                  title="<?= isset($noRetake[$pid]) ? e($t['project_code'] . ' has no Retake status') : 'Moves it to Retake' ?>"
                  <?= isset($noRetake[$pid]) ? 'disabled' : '' ?>>Retake</button>
        </div>
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
// The show chips' fold-out button, on a phone.
(function () {
  var b = document.querySelector('.rq-filter-toggle');
  var chips = document.getElementById('rqShows');
  if (!b || !chips) return;
  b.addEventListener('click', function () {
    var open = chips.classList.toggle('is-open');
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}());
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
