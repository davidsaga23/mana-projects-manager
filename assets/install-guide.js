// The iPhone install guide for the Reviews app, drawn after the real iOS 26 screens.
//
// David, 2026-11-19. First "the button should walk the user through the whole
// process", then "do the same but with the share button instead", then, with a screen
// recording of the install on his iPhone: "can we modify the tutorial so it better
// reflects the graphic icons and everything to match the actual and current iPhone UI?"
//
// ⚠ A WEBSITE CANNOT INSTALL ITSELF ON AN IPHONE. Safari has no install API and no
// install prompt (Android's beforeinstallprompt does not exist there): every web app
// on an iPhone is added by the person through the Share sheet. Checked 2026-11-19
// against WebKit's Safari 26 notes. So this guide draws each screen they will see,
// with the place to tap ringed in yellow.
//
// THE SCREENS, as recorded on David's iPhone (iOS 26, Safari, 2026-11-19):
//   1. the bar at the bottom: ‹ · [≡  anima-mana.com  ↻] · tabs. Tap ≡.
//   2. a floating menu: Translate, Share, Add to Bookmarks, … Tap Share.
//   3. the share sheet: people, apps, then four round buttons: Copy, Add to Bookmarks,
//      Add to Reading List, View More. Tap View More.
//   4. a list: Add Bookmark to…, Add to Favorites, Add to Quick Note, Find on Page,
//      Add to Home Screen. Tap Add to Home Screen.
//   5. ⓧ · Add to Home Screen · (Add): the icon, the name, the address, Open as Web
//      App switched on. Tap Add.
// Redraw these if Apple moves them again. The people and apps row of the share sheet
// is left out on purpose: it is somebody's own contacts.
//
// The name ("Anima Reviews"), the icon and the start page (review.php) come from
// manifest-review.json, which includes/header.php names on this page on a phone.
//
// Its own file and its own styles (injected once), so the shared stylesheet stays out
// of it. review.php calls window.animaInstallGuide.open() from the Install button.
(function () {
  'use strict';

  var DONE_KEY = 'anima.rqInstallHidden';   // the same key review.php's × uses

  var CSS = ''
    + '.ig-veil{position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center}'
    + '.ig{width:100%;max-width:520px;max-height:94dvh;overflow-y:auto;background:var(--surface,#1b1d22);color:var(--text,#eee);'
    + 'border-radius:18px 18px 0 0;padding:16px 16px calc(12px + env(safe-area-inset-bottom));box-shadow:0 -10px 40px rgba(0,0,0,.5)}'
    + '.ig-head{display:flex;align-items:flex-start;gap:10px}'
    + '.ig h3{flex:1;margin:4px 0 4px;font-size:20px;line-height:1.25}'
    + '.ig-x{margin:0;width:40px;height:40px;flex-shrink:0;padding:0;border-radius:10px;background:none;border:1px solid var(--border,#333);color:var(--muted,#999);font-size:20px}'
    + '.ig-sub{margin:0 0 12px;font-size:14px;color:var(--muted,#999)}'
    + '.ig-step{display:flex;gap:10px;align-items:flex-start;margin:0 0 12px}'
    + '.ig-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:var(--primary,#5fb2ff);color:#000;'
    + 'display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;margin-top:1px}'
    + '.ig-body{flex:1;min-width:0}.ig-body p{margin:0 0 6px;font-size:15px;line-height:1.4}'
    + '.ig strong{color:var(--text,#fff)}'
    + '.ig-go{display:block;width:100%;min-height:50px;margin:4px 0 0;font-size:17px;font-weight:600;border-radius:12px}'
    + '.ig-note{margin:8px 0 0;font-size:13px;color:var(--muted,#999);text-align:center}'
    // iOS 26's look: dark glass, round controls, SF-ish type.
    + '.ig-ios{font:14px/1.3 -apple-system,BlinkMacSystemFont,"SF Pro Text",system-ui,sans-serif;color:#fff}'
    + '.ig-ios svg{display:block;width:18px;height:18px;flex-shrink:0}'
    + '.ig-tap{box-shadow:0 0 0 3px #ffd400;animation:ig-pulse 1.4s ease-in-out infinite}'
    + '@keyframes ig-pulse{50%{box-shadow:0 0 0 6px rgba(255,212,0,.35)}}'
    // 1: Safari's bar
    + '.ig-bar{display:flex;align-items:center;gap:8px;padding:10px;border-radius:16px;background:#1c1c1e}'
    + '.ig-circ{width:38px;height:38px;border-radius:50%;background:#2c2c2e;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}'
    + '.ig-pill{flex:1;min-width:0;display:flex;align-items:center;gap:8px;height:38px;padding:0 6px;border-radius:19px;background:#2c2c2e}'
    + '.ig-pill .ig-url{flex:1;text-align:center;font-size:15px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'
    + '.ig-ham{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center}'
    // 2: the floating menu
    + '.ig-menu{width:78%;padding:6px;border-radius:22px;background:#2c2c2e}'
    + '.ig-mi{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:12px;font-size:15px}'
    + '.ig-mi.ig-dim{color:#8e8e93}.ig-sep{height:1px;margin:3px 10px;background:#3a3a3c}'
    // 3: the share sheet's round buttons
    + '.ig-sheet{padding:12px 8px;border-radius:22px;background:#1c1c1e}'
    + '.ig-acts{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;text-align:center;font-size:11px;color:#ddd}'
    + '.ig-rb{width:46px;height:46px;margin:0 auto 5px;border-radius:50%;background:#2c2c2e;display:flex;align-items:center;justify-content:center}'
    + '.ig-acts .ig-dim{opacity:.55}'
    // 4: the list under View More
    + '.ig-list{padding:4px;border-radius:18px;background:#2c2c2e}'
    + '.ig-li{display:flex;align-items:center;gap:12px;padding:10px;border-radius:12px;font-size:15px;color:#8e8e93}'
    + '.ig-li.ig-tap{color:#fff;font-weight:600;box-shadow:inset 0 0 0 3px #ffd400;animation:none}'
    // 5: Add to Home Screen
    + '.ig-add{border-radius:20px;background:#1c1c1e;padding:10px}'
    + '.ig-addtop{display:flex;align-items:center;justify-content:space-between;font-weight:600}'
    + '.ig-addbtn{padding:7px 16px;border-radius:18px;background:#0a84ff;color:#fff;font-weight:600}'
    + '.ig-addrow{display:flex;align-items:center;gap:12px;margin-top:10px;padding:8px;border-radius:12px;background:#2c2c2e}'
    + '.ig-addrow img{width:44px;height:44px;border-radius:10px}.ig-addrow small{display:block;color:#8e8e93;font-size:12px;margin-top:2px}'
    + '.ig-toggle{display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding:8px;border-radius:12px;background:#2c2c2e}'
    + '.ig-sw{width:44px;height:26px;border-radius:13px;background:#30d158;position:relative;flex-shrink:0}'
    + '.ig-sw:after{content:"";position:absolute;right:2px;top:2px;width:22px;height:22px;border-radius:50%;background:#fff}'
    // The home screen icon at the end
    + '.ig-home{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px;border-radius:16px;'
    + 'background:linear-gradient(160deg,#1d3557,#0b132b);font-size:12px}.ig-home img{width:58px;height:58px;border-radius:14px}'
    // A nudge at the bottom edge, towards Safari's own bar below the page.
    + '.ig-down{text-align:center;color:#ffd400;font-size:22px;line-height:1;margin-top:6px;animation:ig-bob 1s ease-in-out infinite}'
    + '@keyframes ig-bob{50%{transform:translateY(5px)}}';

  // Line icons in the spirit of SF Symbols, 20×20, drawn with currentColor.
  function svg(d, extra) {
    return '<svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" '
      + 'stroke-linecap="round" stroke-linejoin="round"' + (extra || '') + '>' + d + '</svg>';
  }
  var I = {
    back: svg('<path d="M12.5 4 6.5 10l6 6"/>'),
    menu: svg('<path d="M4 6h12M4 10h12M4 14h12"/>'),
    reload: svg('<path d="M15.5 10a5.5 5.5 0 1 1-1.6-3.9"/><path d="M14.5 2.8v3.6h-3.6"/>'),
    tabs: svg('<rect x="3" y="6" width="10" height="10" rx="2.5"/><path d="M7 3.5h7a2.5 2.5 0 0 1 2.5 2.5v7"/>'),
    translate: svg('<path d="M3 4h7M6.5 3v1.5M4.5 4c.5 3 2.5 5 5 6M9 4c-.6 3-2.6 5.4-5.5 6.5"/><path d="M10 17l3-8 3 8M11 14.5h4"/>'),
    share: svg('<path d="M10 2.5v10M6.5 6 10 2.5 13.5 6"/><path d="M7 8.5H5.5a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-7a1 1 0 0 0-1-1H13"/>'),
    bookmark: svg('<path d="M6 3h8a1 1 0 0 1 1 1v13l-5-3.5L5 17V4a1 1 0 0 1 1-1Z"/>'),
    book: svg('<path d="M10 5.5C8.5 4.3 6 4 3 4.3v11c3-.3 5.5 0 7 1.2 1.5-1.2 4-1.5 7-1.2v-11c-3-.3-5.5 0-7 1.2ZM10 5.5v11"/>'),
    copy: svg('<rect x="6.5" y="6.5" width="10" height="11" rx="2"/><path d="M4 13.5V4.5a2 2 0 0 1 2-2h7"/>'),
    glasses: svg('<circle cx="5.5" cy="12" r="3"/><circle cx="14.5" cy="12" r="3"/><path d="M8.5 12h3M2.5 11l1.5-4M17.5 11 16 7"/>'),
    chevron: svg('<path d="m5 8 5 5 5-5"/>'),
    star: svg('<path d="m10 2.8 2.2 4.6 5 .6-3.7 3.4 1 5-4.5-2.5-4.5 2.5 1-5L2.8 8l5-.6L10 2.8Z"/>'),
    note: svg('<rect x="3" y="4" width="14" height="12" rx="2"/><path d="M6 12.5c1.5-2 2.5-2 3.5 0s2 2 4 0"/>'),
    find: svg('<path d="M11 2.5H5.5a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V7Z"/><circle cx="9.5" cy="11" r="2.5"/><path d="m11.4 12.9 1.8 1.8"/>'),
    plus: svg('<rect x="3" y="3" width="14" height="14" rx="3.5"/><path d="M10 6.5v7M6.5 10h7"/>'),
    x: svg('<path d="M5.5 5.5l9 9M14.5 5.5l-9 9"/>')
  };

  var veil = null;

  function styles() {
    if (document.getElementById('igCss')) return;
    var st = document.createElement('style');
    st.id = 'igCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function close() {
    if (veil) veil.remove();
    veil = null;
  }

  function finish() {
    try { window.localStorage.setItem(DONE_KEY, String(Date.now())); } catch (e) { /* fine */ }
    var box = document.getElementById('rqInstall');
    if (box) box.hidden = true;
    close();
  }

  function step(n, text, drawing) {
    return '<div class="ig-step"><div class="ig-num">' + n + '</div><div class="ig-body"><p>' + text + '</p>'
      + drawing + '</div></div>';
  }
  function mi(icon, label, cls) {
    return '<div class="ig-mi' + (cls ? ' ' + cls : '') + '">' + icon + '<span>' + label + '</span></div>';
  }
  function li(icon, label, tap) {
    return '<div class="ig-li' + (tap ? ' ig-tap' : '') + '">' + icon + '<span>' + label + '</span></div>';
  }
  function rb(icon, label, cls) {
    return '<div class="' + (cls || '') + '"><div class="ig-rb' + (cls === 'ig-tap-wrap' ? ' ig-tap' : '') + '">'
      + icon + '</div>' + label + '</div>';
  }

  var URL_SHOWN = (window.location.host || 'anima-mana.com');

  // The last two screens, the same in Safari and in Chrome.
  function lastSteps(from) {
    return step(from, 'Tap <strong>Add to Home Screen</strong>.',
        '<div class="ig-ios ig-list">'
        + li(I.book, 'Add Bookmark to…') + li(I.star, 'Add to Favorites') + li(I.note, 'Add to Quick Note')
        + li(I.find, 'Find on Page') + li(I.plus, 'Add to Home Screen', true) + '</div>')
      + step(from + 1, 'Tap <strong>Add</strong>. The name and the icon are already filled in.',
        '<div class="ig-ios ig-add"><div class="ig-addtop"><div class="ig-circ">' + I.x + '</div>'
        + '<span>Add to Home Screen</span><span class="ig-addbtn ig-tap">Add</span></div>'
        + '<div class="ig-addrow"><img src="assets/icon-192.png" alt=""><div>Anima Reviews'
        + '<small>https://' + URL_SHOWN + '/review.php</small></div></div>'
        + '<div class="ig-toggle">Open as Web App<span class="ig-sw" aria-hidden="true"></span></div></div>');
  }

  /**
   * chrome: Chrome (or another browser) on an iPhone, where Share sits at the top
   * right beside the address instead of behind Safari's ≡ menu at the bottom.
   */
  function open(chrome) {
    styles();
    close();
    var steps = chrome
      ? step(1, 'Tap <strong>Share</strong> at the top right, beside the address.',
          '<div class="ig-ios ig-bar"><div class="ig-pill"><span class="ig-url">' + URL_SHOWN + '</span></div>'
          + '<div class="ig-circ ig-tap">' + I.share + '</div></div>')
        + step(2, 'Tap <strong>View More</strong>.',
          '<div class="ig-ios ig-sheet"><div class="ig-acts">' + rb(I.copy, 'Copy', 'ig-dim')
          + rb(I.bookmark, 'Add to Bookmarks', 'ig-dim') + rb(I.glasses, 'Add to Reading List', 'ig-dim')
          + rb(I.chevron, 'View More', 'ig-tap-wrap') + '</div></div>')
        + lastSteps(3)
      : step(1, 'Tap <strong>≡</strong> at the left of the address, in the bar at the bottom of the screen.',
          '<div class="ig-ios ig-bar"><div class="ig-circ">' + I.back + '</div>'
          + '<div class="ig-pill"><span class="ig-ham ig-tap">' + I.menu + '</span><span class="ig-url">'
          + URL_SHOWN + '</span>' + I.reload + '</div><div class="ig-circ">' + I.tabs + '</div></div>')
        + step(2, 'Tap <strong>Share</strong>.',
          '<div class="ig-ios ig-menu">' + mi(I.translate, 'Translate', 'ig-dim') + '<div class="ig-sep"></div>'
          + mi(I.share, 'Share', 'ig-tap') + mi(I.bookmark, 'Add to Bookmarks', 'ig-dim')
          + mi(I.book, 'Add Bookmark to…', 'ig-dim') + '</div>')
        + step(3, 'Below the people and apps, tap <strong>View More</strong>.',
          '<div class="ig-ios ig-sheet"><div class="ig-acts">' + rb(I.copy, 'Copy', 'ig-dim')
          + rb(I.bookmark, 'Add to Bookmarks', 'ig-dim') + rb(I.glasses, 'Add to Reading List', 'ig-dim')
          + rb(I.chevron, 'View More', 'ig-tap-wrap') + '</div></div>')
        + lastSteps(4);
    veil = document.createElement('div');
    veil.className = 'ig-veil';
    veil.addEventListener('click', function (e) { if (e.target === veil) close(); });
    veil.innerHTML = '<div class="ig" role="dialog" aria-modal="true" aria-labelledby="igTitle">'
      + '<div class="ig-head"><h3 id="igTitle">Put Reviews on your home screen</h3>'
      + '<button type="button" class="ig-x" aria-label="Close">×</button></div>'
      + '<p class="ig-sub">Tap where the yellow rings are. It then opens full screen from its own icon, like any app.</p>'
      + steps
      + '<div class="ig-step"><div class="ig-num">✓</div><div class="ig-body"><p>Open it from your home screen and '
      + 'sign in once.</p><div class="ig-ios ig-home"><img src="assets/icon-192.png" alt="">Anima Reviews</div></div></div>'
      + '<button type="button" class="ig-go">Done, it’s on my home screen</button>'
      + (chrome ? '' : '<div class="ig-down" aria-hidden="true">↓</div>')
      + '</div>';
    veil.querySelector('.ig-x').addEventListener('click', close);
    veil.querySelector('.ig-go').addEventListener('click', finish);
    document.body.appendChild(veil);
  }

  window.animaInstallGuide = { open: open };
}());
