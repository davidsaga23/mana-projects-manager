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
require_once __DIR__ . '/includes/realm_chat.php';
require_once __DIR__ . '/includes/realm_praise.php';
require_login();

$uid     = realm_user_id();
$ready   = realm_available($pdo);
$preview = in_preview();
$state   = null;
if ($ready && !$preview) {
    realm_enter($pdo, $uid);
    realm_sync_lands($pdo);
    $state = realm_state($pdo, $uid);
    // Chat (stage 2). Null until migration 082 has run, and the page then draws no chat at all.
    $state['chat'] = realm_chat_summary($pdo, $uid, can_edit());
    // Mana, gold and praise (migration 090). Opening the Realm is when the time log is counted.
    realm_purse($pdo, $uid);
    $state['praise'] = realm_praise_news($pdo, $uid);
    // The merchant (migration 092): the prices, and what this person owns.
    $state['shop'] = realm_shop_state($pdo, $uid);
    // Giving and swapping: this person's own offers, waiting or just ended.
    $state['offers'] = realm_offer_news($pdo, $uid);
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
<?php // THE TOP BAR IS ANIMA'S (David, 2026-11-12: "it should feel like we're in the same
      // universe"): the same mark, "Anima | Realm" where every other screen says "Anima | Project
      // manager", and the same navigation buttons in the same order, each drawn only while its
      // switch is on, as includes/header.php does. The Realm's own controls sit to the right.
      // includes/header.php itself is not loaded: it brings Anima's whole stylesheet, which styles
      // bare buttons and fields in a way a map screen cannot wear (see assets/realm.css). ?>
<?php $rfa = fn(string $k): bool => !function_exists('feature_on') || feature_on($k); ?>
<header class="rtop">
  <a href="index.php?anima=1" class="rbrand"><?= site_logo() ?><span>Anima <span class="rmuted">| Realm</span></span></a>
  <nav class="rnav" aria-label="Anima">
    <?php if ($rfa('home') && (int)(effective_crew_id($pdo) ?? 0) > 0): ?><a href="home.php" class="rnavbtn">Home</a><?php endif; ?>
    <?php if ($rfa('spell')): ?><a href="spell.php" class="rnavbtn">Spell</a><?php endif; ?>
    <a href="realm.php" class="rnavbtn is-on" aria-current="page">Realm</a>
    <?php if ($rfa('mytasks')): ?><a href="mytasks.php" class="rnavbtn">My tasks</a><?php endif; ?>
  </nav>
  <span class="rgrow"></span>
  <?php if ($state): ?>
  <span class="rmeter">Your door
    <span class="rseg" id="doorSeg">
      <button type="button" data-door="open" aria-pressed="false">Open</button>
      <button type="button" data-door="ajar" aria-pressed="false">Ajar</button>
      <button type="button" data-door="sealed" aria-pressed="false">Sealed</button>
    </span>
  </span>
  <?php /* Your tasks are on the bookshelf in your own house (David, 2026-11-12). This button shows
           only while a clock is running, or for somebody with no house and so no shelf. */ ?>
  <button type="button" class="rbtn" id="focusBtn"<?= $hasHouse ? ' hidden' : '' ?>>Your tasks</button>
  <button type="button" class="rbtn" id="lookBtn">Your character</button>
  <?php endif; ?>
  <span class="rwho"><?= e(current_user_display()) ?></span>
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
    <nav class="rside" aria-label="People and places">
      <?php /* Search across every conversation (David, 2026-11-13: "all channels and dms").
               Outside #side, because #side is redrawn every few seconds and would lose what
               is being typed. assets/realm.js shows it once chat exists. */ ?>
      <form class="rsearch" id="search" role="search" hidden>
        <input type="search" id="searchInput" autocomplete="off" maxlength="200" placeholder="Search messages" aria-label="Search all your conversations">
      </form>
      <div class="rsearchres" id="searchRes" hidden aria-live="polite"></div>
      <div class="rsidelists" id="side"></div>
    </nav>
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
        <?php /* The library, along the bottom of your own room while you decorate it (David,
                 2026-11-12: "they can modify it from a library"). Filled by assets/realm.js. */ ?>
        <div class="rdeco" id="decoBar" hidden></div>
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
    <aside class="rchat" id="chat" aria-label="Conversation" hidden>
      <?php /* The handle on the conversation's left edge (David, 2026-11-12: "an arrow pointing left
               at the middle of the chat bar so there's an option to extend it over the map"). It
               lays the conversation over the map and back; assets/realm.js keeps its label, and
               which way the arrow points is assets/realm.css. */ ?>
      <button type="button" class="rwide" id="chatWide" aria-expanded="false" aria-label="Make the conversation wider" title="Make the conversation wider"><svg viewBox="0 0 10 16" width="10" height="16" aria-hidden="true"><path d="M8 2 2 8l6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      <div class="rchathead"><button type="button" class="rbtn rsmall rback" id="chatBack" hidden aria-label="Back" title="Back">&larr;</button><h2 id="chatTitle"></h2><span class="rgrow"></span><button type="button" class="rbtn rsmall" data-chatclose="1">Close</button></div>
      <div class="rmsgs" id="chatMsgs" aria-live="polite"></div>
      <div class="rsuggest" id="chatSuggest" hidden></div>
      <div class="rpicked" id="chatPicked" hidden></div>
      <?php // Threads (migration 095): a reply may also go to the channel, as in Slack (David, 2026-11-13). ?>
      <label class="ralso" id="chatAlsoRow" hidden><input type="checkbox" id="chatAlso"> <span id="chatAlsoText">Also send to the channel</span></label>
      <form class="rcomposer" id="chatForm"><input type="file" id="chatFile" accept="image/png,image/jpeg,image/gif,image/webp" hidden><button type="button" class="rbtn" id="chatAttach" title="Add a picture, up to 5 MB" aria-label="Add a picture" hidden>+</button><input id="chatInput" autocomplete="off" maxlength="2000" aria-label="Message"><button class="rbtn rprimary" id="chatSend">Send</button></form>
    </aside>
  </div>
  <div id="toasts" aria-live="polite"></div>
  <dialog id="focusDlg" aria-labelledby="focusTitle">
    <h2 id="focusTitle">Your library</h2>
    <div id="focusBody"></div>
  </dialog>
  <?php /* The chest and the portrait in a house (David, 2026-11-12): "Treasure chest: to check their
           gold. A portrait in the wall: so they can see theirs and others bio and profile from
           Anima, and update it." Both are filled by assets/realm.js. */ ?>
  <dialog id="goldDlg" aria-labelledby="goldTitle">
    <h2 id="goldTitle">Your treasure</h2>
    <div id="goldBody"></div>
  </dialog>
  <dialog id="profDlg" aria-labelledby="profTitle">
    <h2 id="profTitle">Profile</h2>
    <div id="profBody"></div>
  </dialog>
  <?php /* What is always in a show's camp (David, 2026-11-13): "A bar with the finished tasks and the
           progress for 'slaying' the project. A kind of papyrus on the wall so they can see live the
           brief of that project. A calendar to visualize the timeline. A checkbox list so they can
           see the production tracking". One window for all four, filled by assets/realm.js from
           realm_api.php?camp=, read only. */ ?>
  <dialog id="campDlg" class="rcamp" aria-labelledby="campTitle">
    <h2 id="campTitle">Camp</h2>
    <div id="campBody"></div>
  </dialog>
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
  <?php /* Praise, mana and gold (migration 090): its own file, so the map's script stays as it was. */ ?>
  <script src="assets/realm_praise.js<?= asset_ver('realm_praise.js') ?>"></script>
  <?php /* The merchant in the Tavern (migration 092): the counter where gold is spent. */ ?>
  <script src="assets/realm_shop.js<?= asset_ver('realm_shop.js') ?>"></script>
  <?php /* Giving and swapping what was bought, face to face (the same migration). */ ?>
  <script src="assets/realm_trade.js<?= asset_ver('realm_trade.js') ?>"></script>
  <?php /* Emoji reactions on chat messages (migration 093): its own file, like the three above. */ ?>
  <script src="assets/realm_react.js<?= asset_ver('realm_react.js') ?>"></script>
<?php endif; ?>
</body>
</html>
