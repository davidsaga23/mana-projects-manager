<?php
// The Realm — the studio as a village (stage 1).
//
// David, 2026-11-12: "an epic fantasy office space where we can hang out, visit
// other artist's offices, request meetings so they open up a voice chat ...
// something that mixes the fantasy world of magic the gathering/lord of the
// rings, with slack/discord." Agreed in stages; this is the first: the map,
// everybody's house, who is here, how each door stands, your character, and
// walking into a building to see who is inside. No chat, voice or mana yet.
//
// ⚠ ITS OWN FULL-SCREEN PAGE, WITHOUT includes/header.php. The Realm is a map
// that fills the window and has its own look (night, lit windows), so it does
// not wear Anima's bar; "Back to Anima" is the way out. Its colours are roles on
// :root in assets/realm.css, the same rule as assets/style.css, kept in a
// separate file so the tracker's palette and the Realm's never get mixed.
//
// ⚠ NO FONT OR SCRIPT IS LOADED FROM ANOTHER SITE. The clickable mock-up used a
// web font; the app talks to nobody outside, so this uses fonts already on the
// machine.
//
// ⚠ NOT INSIDE A PREVIEW ("view as"): it would stand the real person at their
// door when they are not there. See includes/realm.php.
//
// Rules and data: includes/realm.php. Drawing and walking: assets/realm.js.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_login();

$uid     = realm_user_id();
$ready   = realm_available($pdo);
$preview = in_preview();
$state   = null;
if ($ready && !$preview) {
    realm_enter($pdo, $uid);
    realm_sync_lands($pdo);
    $state = realm_state($pdo, $uid);
}
$hasHouse = false;
foreach (($state['people'] ?? []) as $p) {
    if (!empty($p['me'])) $hasHouse = true;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anima Realm</title>
<link rel="icon" href="assets/logo.webp<?= asset_ver('logo.webp') ?>" type="image/webp">
<link rel="stylesheet" href="assets/realm.css<?= asset_ver('realm.css') ?>">
</head>
<body>
<header class="rtop">
  <span class="rbrand">Anima Realm</span>
  <a class="rbtn" href="index.php?anima=1">Back to Anima</a>
  <span class="rgrow"></span>
  <?php if ($state): ?>
  <span class="rmeter">Your door
    <span class="rseg" id="doorSeg">
      <button type="button" data-door="open" aria-pressed="false">Open</button>
      <button type="button" data-door="ajar" aria-pressed="false">Ajar</button>
      <button type="button" data-door="sealed" aria-pressed="false">Sealed</button>
    </span>
  </span>
  <button type="button" class="rbtn" id="lookBtn">Your character</button>
  <?php endif; ?>
</header>

<?php if (!$ready): ?>
  <main class="rnote">
    <h1>The Realm is waiting for a database update</h1>
    <p>Nothing is wrong. The village needs two new tables before it can open.</p>
    <?php if (is_admin()): ?><p><a class="rbtn rprimary" href="migrate.php">Open the updates page</a></p>
    <?php else: ?><p>An administrator opens the updates page once, then this screen works.</p><?php endif; ?>
  </main>
<?php elseif ($preview): ?>
  <main class="rnote">
    <h1>The Realm is closed while you are viewing as somebody else</h1>
    <p>It would stand that person at their door when they are not there. Leave the preview, then come back.</p>
    <p><a class="rbtn rprimary" href="index.php?anima=1">Back to Anima</a></p>
  </main>
<?php else: ?>
  <div class="rapp">
    <nav class="rside" id="side" aria-label="People and places"></nav>
    <main class="rmain">
      <?php if (!$hasHouse): ?>
        <p class="rwarn">Every plot in the village is taken, so you have no house yet. You can still walk around. Tell David, more plots can be added.</p>
      <?php endif; ?>
      <div class="rmapbox" id="mapbox">
        <canvas id="mapcv"></canvas>
        <div id="overlay"></div>
        <div class="rzoom" id="zoom">
          <button type="button" class="rbtn rsmall" data-zoom="in" aria-label="Zoom in">+</button>
          <button type="button" class="rbtn rsmall" data-zoom="out" aria-label="Zoom out">&minus;</button>
          <button type="button" class="rbtn rsmall" data-zoom="village">Village</button>
          <button type="button" class="rbtn rsmall" data-zoom="full">Whole realm</button>
        </div>
        <div id="cardslot"></div>
        <div class="rinside" id="insideBar" hidden></div>
      </div>
      <div class="rlegend">
        <span><i class="rdot open"></i>Door open, walk in</span>
        <span><i class="rdot ajar"></i>Ajar, knock first</span>
        <span><i class="rdot sealed"></i>Sealed, leave them be</span>
        <span>A character at a door means that person is here now</span>
        <span>Walk with the arrow keys or click a building, Enter goes inside</span>
        <span>Drag the map, scroll to zoom</span>
      </div>
    </main>
  </div>
  <div id="toasts" aria-live="polite"></div>
  <dialog id="lookDlg" aria-labelledby="lookTitle">
    <h2 id="lookTitle">Your character and house</h2>
    <form id="lookForm" method="dialog"></form>
  </dialog>
  <script>
    window.REALM = {
      api: 'realm_api.php',
      state: <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
  </script>
  <script src="assets/realm.js<?= asset_ver('realm.js') ?>"></script>
<?php endif; ?>
</body>
</html>
