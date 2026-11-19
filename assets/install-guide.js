// The iPhone install guide for the Reviews app.
//
// David, 2026-11-19, after trying the first version on his iPhone: "the button needs
// to do all by its own, user still needs to give permissions but the button should
// walk the user through the whole process".
//
// ⚠ WHAT A WEB PAGE CAN AND CANNOT DO HERE. The app comes as a configuration profile
// (review_app.php says why). Safari downloads it on a tap, but iOS then insists the
// person approves it in the Settings app, and no web page can open Settings or tap
// Install there. So the button does every part it can (the download, the timing, the
// re-download when the profile has expired) and shows each of iOS's own screens as a
// small drawing with the place to tap lit up, one step at a time.
//
// It moves on by itself: the person leaves Safari for Settings, and when they come
// back the guide is already on the next step (visibilitychange). The step is kept in
// this browser for 20 minutes, so a reload on the way back resumes it too.
//
// Its own file and its own styles (injected once), so the shared stylesheet stays out
// of it. review.php calls window.animaInstallGuide.open() from the Install button.
(function () {
  'use strict';

  var KEY = 'anima.installGuide';
  var DONE_KEY = 'anima.rqInstallHidden';   // the same key review.php's × uses
  var KEEP_MS = 20 * 60 * 1000;
  var PROFILE = 'review_app.php';

  var CSS = ''
    + '.ig-veil{position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center}'
    + '.ig{width:100%;max-width:520px;max-height:92dvh;overflow-y:auto;background:var(--surface,#1b1d22);color:var(--text,#eee);'
    + 'border-radius:18px 18px 0 0;padding:18px 18px calc(18px + env(safe-area-inset-bottom));box-shadow:0 -10px 40px rgba(0,0,0,.5)}'
    + '.ig-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}'
    + '.ig-steps{display:flex;gap:6px;flex:1}.ig-steps i{flex:1;height:4px;border-radius:2px;background:var(--border,#333)}'
    + '.ig-steps i.on{background:var(--primary,#5fb2ff)}'
    + '.ig-x{margin:0;width:40px;height:40px;padding:0;border-radius:10px;background:none;border:1px solid var(--border,#333);color:var(--muted,#999);font-size:20px}'
    + '.ig-n{margin:10px 0 2px;font-size:13px;color:var(--muted,#999);font-weight:600;letter-spacing:.02em;text-transform:uppercase}'
    + '.ig h3{margin:0 0 8px;font-size:21px;line-height:1.25}'
    + '.ig p{margin:0 0 10px;font-size:15px;line-height:1.5;color:var(--text-2,#ccc)}'
    + '.ig ol{margin:0 0 12px;padding-left:22px;font-size:15px;line-height:1.55;color:var(--text-2,#ccc)}.ig ol li{margin-bottom:4px}'
    + '.ig strong{color:var(--text,#fff)}'
    + '.ig-go{display:block;width:100%;min-height:52px;margin:6px 0 0;font-size:17px;font-weight:600;border-radius:12px}'
    + '.ig-alt{display:block;width:100%;min-height:44px;margin:8px 0 0;background:none;border:0;color:var(--primary,#5fb2ff);font-size:15px}'
    + '.ig-note{font-size:13px!important;color:var(--muted,#999)!important}'
    /* The drawings of iOS's own screens: plain boxes, iOS-ish, the place to tap ringed. */
    + '.ig-mock{margin:6px 0 14px;padding:14px;border-radius:14px;background:#2c2c2e;color:#fff;font:15px/1.35 -apple-system,system-ui,sans-serif}'
    + '.ig-mock small{display:block;color:#aaa;font-size:13px;margin-top:4px}'
    + '.ig-row{display:flex;align-items:center;justify-content:space-between;gap:10px}'
    + '.ig-btns{display:flex;justify-content:flex-end;gap:22px;margin-top:12px;color:#0a84ff;font-size:16px}'
    + '.ig-tap{position:relative;font-weight:600;color:#0a84ff;padding:2px 8px;border-radius:8px;box-shadow:0 0 0 3px #ffd400;animation:ig-pulse 1.4s ease-in-out infinite}'
    + '@keyframes ig-pulse{50%{box-shadow:0 0 0 6px rgba(255,212,0,.35)}}'
    + '.ig-pill{display:block;margin-top:12px;padding:10px;border-radius:12px;text-align:center;background:#0a84ff;color:#fff;font-weight:600}'
    + '.ig-list{border-radius:12px;background:#1c1c1e;overflow:hidden}.ig-list div{padding:11px 12px;border-bottom:1px solid #333}'
    + '.ig-list div:last-child{border-bottom:0}.ig-list .ig-tap{display:block;color:#fff;border-radius:0;box-shadow:inset 0 0 0 3px #ffd400;animation:none}'
    + '.ig-icon{width:56px;height:56px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:28px;background:#8e8e93;margin:0 auto 6px}'
    + '.ig-icon img{width:56px;height:56px;border-radius:13px}'
    + '.ig-center{text-align:center}.ig-red{color:#ff453a;font-size:13px}';

  var state = null;   // { step, at } while the guide is going
  var veil = null, waitingForReturn = false, leftAt = 0;

  function save() {
    try {
      if (state) window.localStorage.setItem(KEY, JSON.stringify(state));
      else window.localStorage.removeItem(KEY);
    } catch (e) { /* private window: no resume, the guide still works */ }
  }
  function load() {
    try {
      var s = JSON.parse(window.localStorage.getItem(KEY) || 'null');
      if (s && typeof s.step === 'number' && Date.now() - s.at < KEEP_MS) return s;
    } catch (e) { /* nothing kept */ }
    return null;
  }

  function styles() {
    if (document.getElementById('igCss')) return;
    var st = document.createElement('style');
    st.id = 'igCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  /** Fetch the profile: Safari then shows "…trying to download a configuration profile". */
  function download() {
    state = { step: 1, at: Date.now() };
    save();
    window.location.href = PROFILE;
    // The page stays where it is (the profile is a download), so the guide moves on.
    window.setTimeout(function () { show(1); }, 400);
  }

  function finish(installed) {
    state = null;
    save();
    if (installed) {
      try { window.localStorage.setItem(DONE_KEY, String(Date.now())); } catch (e) { /* fine */ }
      var box = document.getElementById('rqInstall');
      if (box) box.hidden = true;
    }
    close();
  }

  function close() {
    if (veil) veil.remove();
    veil = null;
    waitingForReturn = false;
  }

  var ICON = '<img src="assets/icon-192.png" alt="">';

  // Each step: [heading, body html, buttons]. The total is the bar of dashes at the top.
  var STEPS = [
    // 0 — before anything
    function () {
      return ['Put Reviews on your home screen',
        '<p>About a minute. iPhone asks you to approve a new app three times, and this guide '
        + 'shows you exactly where to tap each time.</p>'
        + '<div class="ig-mock ig-center"><div class="ig-icon">' + ICON + '</div>Reviews'
        + '<small>Opens full screen, like any app</small></div>',
        [['Start', download]]];
    },
    // 1 — Safari's two boxes
    function () {
      return ['Tap “Allow”, then “Close”',
        '<p>Safari asks if it may download the app’s settings file. Tap <strong>Allow</strong>:</p>'
        + '<div class="ig-mock">“anima-mana.com” is trying to download a configuration profile. '
        + 'Do you want to allow this?<div class="ig-btns"><span>Ignore</span><span class="ig-tap">Allow</span></div></div>'
        + '<p>Then it says the profile was downloaded. Tap <strong>Close</strong>:</p>'
        + '<div class="ig-mock"><strong>Profile Downloaded</strong><small>Review the profile in the Settings app '
        + 'if you want to install it.</small><span class="ig-pill ig-tap" style="color:#fff">Close</span></div>',
        [['Done, next step', function () { show(2); }],
         ['Nothing appeared? Try again', download, true]]];
    },
    // 2 — Settings (read now, done there)
    function () {
      return ['Now approve it in Settings',
        '<p>Leave Safari and open the <strong>Settings</strong> app. There:</p>'
        + '<ol><li>At the very top, under your name, tap <strong>Profile Downloaded</strong>.</li>'
        + '<li>Tap <strong>Install</strong> at the top right and enter your passcode.</li>'
        + '<li>Tap <strong>Install</strong> again, then <strong>Done</strong>.</li></ol>'
        + '<div class="ig-mock"><div class="ig-list"><div>Your name<small>Apple Account, iCloud…</small></div>'
        + '<div class="ig-tap">Profile Downloaded&nbsp;&nbsp;›</div></div></div>'
        + '<div class="ig-mock"><div class="ig-row"><span style="color:#0a84ff">Cancel</span><strong>Install Profile</strong>'
        + '<span class="ig-tap">Install</span></div><small style="margin-top:10px">Anima Reviews · Mana Animation Studio</small>'
        + '<span class="ig-red">Not Verified</span> <small style="display:inline">← this is normal, it still installs</small></div>'
        + '<p class="ig-note">Then come back to Safari: this guide will be on the last step.</p>',
        [['I’ve installed it', function () { show(3); }],
         ['I can’t find “Profile Downloaded”', function () { show(4); }, true]]];
    },
    // 3 — done
    function () {
      return ['Open Reviews from your home screen',
        '<div class="ig-mock ig-center"><div class="ig-icon">' + ICON + '</div>Reviews</div>'
        + '<p>Tap the <strong>Reviews</strong> icon on your home screen. It opens full screen; sign in once '
        + 'inside it and it remembers you.</p>',
        [['Finished', function () { finish(true); }],
         ['The icon isn’t there', function () { show(4); }, true]]];
    },
    // 4 — trouble
    function () {
      return ['If it isn’t there',
        '<ol><li>iPhone throws a downloaded profile away after about <strong>8 minutes</strong>. '
        + 'Tap <strong>Download it again</strong> below and go straight to Settings.</li>'
        + '<li>If “Profile Downloaded” is not at the top of Settings, look in <strong>General → '
        + 'VPN &amp; Device Management</strong>.</li>'
        + '<li>Already installed once? Settings → General → VPN &amp; Device Management → '
        + '<strong>Anima Reviews</strong> shows it.</li></ol>',
        [['Download it again', download],
         ['Back to the steps', function () { show(2); }, true]]];
    }
  ];
  var DOTS = 4;   // the progress dashes: steps 0–3

  function show(i) {
    styles();
    state = { step: i, at: (state && state.at) || Date.now() };
    if (i > 0) save();
    var parts = STEPS[i]();
    if (!veil) {
      veil = document.createElement('div');
      veil.className = 'ig-veil';
      veil.addEventListener('click', function (e) { if (e.target === veil) close(); });
      document.body.appendChild(veil);
    }
    var dots = '';
    for (var d = 0; d < DOTS; d++) dots += '<i class="' + (d <= Math.min(i, DOTS - 1) ? 'on' : '') + '"></i>';
    veil.innerHTML = '<div class="ig" role="dialog" aria-modal="true" aria-labelledby="igTitle">'
      + '<div class="ig-head"><div class="ig-steps" aria-hidden="true">' + dots + '</div>'
      + '<button type="button" class="ig-x" aria-label="Close the guide">×</button></div>'
      + (i < DOTS ? '<div class="ig-n">Step ' + (i + 1) + ' of ' + DOTS + '</div>' : '')
      + '<h3 id="igTitle">' + parts[0] + '</h3>' + parts[1] + '<div class="ig-actions"></div></div>';
    veil.querySelector('.ig-x').addEventListener('click', close);
    var actions = veil.querySelector('.ig-actions');
    parts[2].forEach(function (b) {
      var el = document.createElement('button');
      el.type = 'button';
      el.className = b[2] ? 'ig-alt' : 'ig-go';
      el.textContent = b[0];
      el.addEventListener('click', b[1]);
      actions.appendChild(el);
    });
    // Leaving for Settings (step 1 or 2) and coming back means that part is done.
    waitingForReturn = i === 1 || i === 2;
  }

  // Back from Settings: on to the step after it, without a tap.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { leftAt = Date.now(); return; }
    if (!veil || !waitingForReturn || !state) return;
    // A glance away of under two seconds is the download box, not a trip to Settings.
    if (Date.now() - leftAt < 2000) return;
    show(3);
  });

  /** Chrome, Firefox… on an iPhone: only Safari can take a profile. */
  function notSafari() {
    styles();
    if (!veil) {
      veil = document.createElement('div');
      veil.className = 'ig-veil';
      veil.addEventListener('click', function (e) { if (e.target === veil) close(); });
      document.body.appendChild(veil);
    }
    veil.innerHTML = '<div class="ig" role="dialog" aria-modal="true"><div class="ig-head"><div class="ig-steps"></div>'
      + '<button type="button" class="ig-x" aria-label="Close the guide">×</button></div>'
      + '<h3>Open this page in Safari</h3><p>iPhone only installs apps like this one from <strong>Safari</strong>. '
      + 'Copy the link, open Safari, paste it in the address bar, and tap <strong>Install Reviews as an app</strong> there.</p>'
      + '<button type="button" class="ig-go" id="igCopy">Copy the link</button></div>';
    veil.querySelector('.ig-x').addEventListener('click', close);
    veil.querySelector('#igCopy').addEventListener('click', function () {
      var b = this;
      var url = window.location.href;
      var ok = function () { b.textContent = 'Copied: now open Safari and paste it'; };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(ok, function () { b.textContent = url; });
      else b.textContent = url;
    });
  }

  window.animaInstallGuide = {
    /** From the Install button. safari: false for Chrome and friends on an iPhone. */
    open: function (safari) {
      if (!safari) { notSafari(); return; }
      var kept = load();
      state = kept;
      show(kept ? kept.step : 0);
    },
    /** On page load: carry on where the person was, if they were part-way. */
    resume: function () {
      var kept = load();
      if (!kept || kept.step < 1) return;
      state = kept;
      // Back from Settings with the page reloaded: the step after the one they left on.
      show(kept.step === 2 ? 3 : kept.step === 1 ? 2 : kept.step);
    }
  };
}());
