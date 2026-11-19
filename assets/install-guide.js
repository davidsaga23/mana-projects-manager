// The iPhone install guide for the Reviews app: Share → Add to Home Screen → Add.
//
// David, 2026-11-19. First "the button should walk the user through the whole
// process", which was a configuration-profile install with approvals in Settings;
// then, having tried it: "that's still too complicated, do the same but with the
// share button instead".
//
// ⚠ A WEBSITE CANNOT INSTALL ITSELF ON AN IPHONE. Safari has no install API and no
// install prompt (Android's beforeinstallprompt does not exist there): every web app
// on an iPhone is added by the person, through Share → Add to Home Screen. Checked
// again 2026-11-19 against WebKit's Safari 26 notes. Since iOS 26 whatever is added
// opens as a full-screen web app by default, and review.php already gives the page the
// name ("Reviews"), the icon and the start address (manifest-review.json), so those
// three taps are all it takes. This guide shows them, drawn, on one screen.
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
    + '.ig-sub{margin:0 0 10px;font-size:14px;color:var(--muted,#999)}'
    + '.ig-step{display:flex;gap:10px;align-items:flex-start;margin:0 0 10px}'
    + '.ig-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:var(--primary,#5fb2ff);color:#000;'
    + 'display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;margin-top:2px}'
    + '.ig-body{flex:1;min-width:0}.ig-body p{margin:0 0 6px;font-size:15px;line-height:1.4}'
    + '.ig strong{color:var(--text,#fff)}'
    + '.ig-go{display:block;width:100%;min-height:50px;margin:4px 0 0;font-size:17px;font-weight:600;border-radius:12px}'
    + '.ig-note{margin:8px 0 0;font-size:13px;color:var(--muted,#999);text-align:center}'
    /* The drawings of iOS's own screens: plain boxes, iOS-ish, the place to tap ringed. */
    + '.ig-mock{padding:10px 12px;border-radius:12px;background:#2c2c2e;color:#fff;font:14px/1.35 -apple-system,system-ui,sans-serif}'
    + '.ig-bar{display:flex;align-items:center;gap:8px}'
    + '.ig-circ{width:30px;height:30px;border-radius:50%;background:#3a3a3c;display:flex;align-items:center;justify-content:center;color:#ddd;flex-shrink:0}'
    + '.ig-pill{flex:1;display:flex;align-items:center;gap:8px;height:30px;padding:0 10px;border-radius:15px;background:#3a3a3c;color:#ddd;min-width:0}'
    + '.ig-pill span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'
    + '.ig-tap{border-radius:8px;box-shadow:0 0 0 3px #ffd400;animation:ig-pulse 1.4s ease-in-out infinite}'
    + '@keyframes ig-pulse{50%{box-shadow:0 0 0 6px rgba(255,212,0,.35)}}'
    + '.ig-rows{border-radius:10px;background:#1c1c1e;overflow:hidden;padding:0}'
    + '.ig-rows div{display:flex;justify-content:space-between;padding:9px 12px;border-bottom:1px solid #333;color:#ddd}'
    + '.ig-rows div:last-child{border-bottom:0}.ig-rows .ig-tap{color:#fff;font-weight:600;box-shadow:inset 0 0 0 3px #ffd400;animation:none;border-radius:0}'
    + '.ig-top{display:flex;justify-content:space-between;align-items:center}.ig-top b{font-weight:600}'
    + '.ig-blue{color:#0a84ff}.ig-top .ig-tap{color:#0a84ff;font-weight:700;padding:2px 8px}'
    + '.ig-app{display:flex;align-items:center;gap:10px;margin-top:8px}.ig-app img{width:40px;height:40px;border-radius:9px}'
    + '.ig-toggle{display:flex;justify-content:space-between;align-items:center;margin-top:8px;color:#ddd}'
    + '.ig-sw{width:38px;height:22px;border-radius:11px;background:#30d158;position:relative}'
    + '.ig-sw:after{content:"";position:absolute;right:2px;top:2px;width:18px;height:18px;border-radius:50%;background:#fff}'
    /* A nudge at the bottom edge, towards Safari's own bar below the page. */
    + '.ig-down{text-align:center;color:#ffd400;font-size:22px;line-height:1;margin-top:6px;animation:ig-bob 1s ease-in-out infinite}'
    + '@keyframes ig-bob{50%{transform:translateY(5px)}}';

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

  var SHARE = '<svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" style="vertical-align:-2px">'
    + '<path fill="currentColor" d="M10 1.5 6.2 5.3l1.06 1.06L9.25 4.4V12h1.5V4.4l2 1.96 1.05-1.06L10 1.5ZM4 8h2.5v1.5H4.5v8h11v-8h-2V8H16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/></svg>';

  /**
   * chrome: Chrome (or another browser) on an iPhone, where Share sits at the top
   * right beside the address instead of behind Safari's menu at the bottom.
   */
  function open(chrome) {
    styles();
    close();
    var step1 = chrome
      ? '<p>Tap <strong>Share</strong> ' + SHARE + ' at the top right, beside the address.</p>'
        + '<div class="ig-mock ig-bar"><div class="ig-pill"><span>anima-mana.com</span></div>'
        + '<div class="ig-circ ig-tap">' + SHARE + '</div></div>'
      : '<p>Tap the <strong>⋯</strong> button beside the address at the bottom of the screen, then '
        + '<strong>Share</strong> ' + SHARE + '.</p>'
        + '<div class="ig-mock ig-bar"><div class="ig-circ">‹</div><div class="ig-pill"><b class="ig-tap" '
        + 'style="padding:0 4px">⋯</b><span>anima-mana.com</span></div><div class="ig-circ">⧉</div></div>'
        + '<p style="margin-top:6px;font-size:13px;color:#999">On an older iPhone, Share ' + SHARE
        + ' is right in the bar at the bottom.</p>';
    veil = document.createElement('div');
    veil.className = 'ig-veil';
    veil.addEventListener('click', function (e) { if (e.target === veil) close(); });
    veil.innerHTML = '<div class="ig" role="dialog" aria-modal="true" aria-labelledby="igTitle">'
      + '<div class="ig-head"><h3 id="igTitle">Put Reviews on your home screen</h3>'
      + '<button type="button" class="ig-x" aria-label="Close">×</button></div>'
      + '<p class="ig-sub">Three taps. It then opens full screen from its own icon, like any app.</p>'
      + '<div class="ig-step"><div class="ig-num">1</div><div class="ig-body">' + step1 + '</div></div>'
      + '<div class="ig-step"><div class="ig-num">2</div><div class="ig-body">'
      + '<p>Scroll down the list and tap <strong>Add to Home Screen</strong>.</p>'
      + '<div class="ig-mock ig-rows"><div>Copy</div><div>Add to Favorites</div>'
      + '<div class="ig-tap">Add to Home Screen <span>⊞</span></div></div></div></div>'
      + '<div class="ig-step"><div class="ig-num">3</div><div class="ig-body">'
      + '<p>Tap <strong>Add</strong> at the top right.</p>'
      + '<div class="ig-mock"><div class="ig-top"><span class="ig-blue">Cancel</span><b>Add to Home Screen</b>'
      + '<span class="ig-tap">Add</span></div><div class="ig-app"><img src="assets/icon-192.png" alt="">Reviews</div>'
      + '<div class="ig-toggle">Open as Web App<span class="ig-sw" aria-hidden="true"></span></div></div></div></div>'
      + '<button type="button" class="ig-go">Done, it’s on my home screen</button>'
      + '<p class="ig-note">Open <strong>Reviews</strong> from your home screen and sign in once.</p>'
      + (chrome ? '' : '<div class="ig-down" aria-hidden="true">↓</div>')
      + '</div>';
    veil.querySelector('.ig-x').addEventListener('click', close);
    veil.querySelector('.ig-go').addEventListener('click', finish);
    document.body.appendChild(veil);
  }

  window.animaInstallGuide = { open: open };
}());
