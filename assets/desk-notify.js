/* Desktop notifications, with a sound (David, 2026-11-16).
 *
 * His wish, 2026-11-12: "let's enable desktop notifications so we can send sounds and reminders",
 * and "for the desktop notifications we can ask them to install the webpage as an app in windows
 * so it always opens on the startup". On 2026-11-16 he chose "desktop notifications with sound".
 * The point: somebody working in Maya, Toon Boom or Photoshop still notices a raven, a tag, a
 * knock on their door, a call starting in the room they are in, or a reminder.
 *
 * This file is shared by every Anima page (loaded by includes/header.php, beside the bell) and by
 * the Realm (realm.php, which has its own top bar). It asks the server NOTHING by itself on an
 * Anima page: it is handed what the polls that already run bring back (assets/bell.js every 30
 * seconds, assets/realm-nudge.js every 20, the Realm's heartbeat every 25), and decides whether
 * that is worth a notification. In the Realm, which has no bell, it asks notify_poll.php itself,
 * on each 25-second heartbeat and only while notifications are on.
 *
 * What was decided, and is relied on below:
 *
 *   * NEVER ASKED ON PAGE LOAD. The browser's "Allow notifications?" question appears only after
 *     somebody presses "Turn on desktop notifications" in the bell (or the Realm's top bar), with
 *     a sentence saying what they will get. A site that asks the moment it opens is a site people
 *     click "Block" on, and a block is very hard for them to undo.
 *
 *   * THE CHOICES LIVE ON THIS COMPUTER (localStorage), not in the database: on, the sound, and
 *     each kind (ravens, tags, knocks, calls, reminders, tasks). The browser's permission is per
 *     computer anyway, so a switch kept on the server would say "on" on a machine that cannot
 *     show anything. No migration.
 *
 *   * ONLY WHEN YOU ARE NOT LOOKING AT ANIMA. While an Anima tab (or the installed Anima window)
 *     is in front of you, the page's own signs do the job: the bell's count, the Realm's notices,
 *     the number on the Realm button. A desktop notification and a sound on top of the page you
 *     are reading is a double. The moment you click into Maya, they take over. Whatever arrived
 *     while you were looking is counted as told, so it does not pop up later as old news.
 *
 *   * ONE TAB SPEAKS. Several Anima tabs see the same news on their own polls; the first to see it
 *     writes it down in localStorage (under a Web Lock where the browser has them), and the others
 *     find it written down and stay quiet. The notification's `tag` is the same key, so even a race
 *     would replace one notification with its twin rather than show two.
 *
 *   * WHAT, NOT WHAT WAS SAID. A raven's notification says who sent it, never its words, as the bell
 *     already decided (includes/realm_chat.php, realm_raven_bell): a notification can appear on a
 *     screen somebody else is looking at.
 *
 *   * THE SOUND IS OUR OWN, made here with the Web Audio API (two soft notes, under half a second);
 *     nothing is loaded from another site. A browser lets a page play sound only after the person
 *     has clicked or typed in it at least once; a tab nobody has touched since it opened cannot, so
 *     then the notification is left to make Windows' own notification sound instead. Muted means
 *     neither.
 *
 * Honest limits, said to David in the PR: it rides on polls, so a notification arrives up to about
 * 30 seconds after the event (a background tab is slowed by the browser to about once a minute
 * after five minutes), and nothing arrives while every Anima tab is closed. That is what installing
 * Anima as an app and opening it at Windows start-up is for. True push (a service worker and Web
 * Push keys) would remove both limits and is proposed as a follow-up, not built here.
 *
 * Never throws into the page: every entry point is wrapped, and a browser without notifications
 * simply has no button.
 */
(function () {
  'use strict';
  if (window.AnimaNotify) return;

  var KEY_PREFS = 'anima.dn.prefs', KEY_SEEN = 'anima.dn.seen', KEY_FRONT = 'anima.dn.front',
      KEY_LEAD = 'anima.dn.lead', KEY_BELL = 'anima.dn.bellmax';
  var TAB = Math.random().toString(36).slice(2);
  var KINDS = [
    ['ravens', 'Ravens sent to you'],
    ['mentions', 'Tags: somebody wrote @your name in a channel'],
    ['knocks', 'A knock on your door in the Realm'],
    ['calls', 'A call starting in the room you are in'],
    ['reminders', 'Reminders: saved messages, and logging your time'],
    ['tasks', 'Your tasks: assigned to you, moved, review notes, mentions']
  ];
  var supported = 'Notification' in window;

  function ls(k, v) {
    try {
      if (v === undefined) { var s = localStorage.getItem(k); return s ? JSON.parse(s) : null; }
      if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v));
    } catch (e) { /* private window or storage full: behaves as "nothing saved" */ }
    return null;
  }
  function prefs() {
    var p = ls(KEY_PREFS) || {};
    var out = { on: !!p.on, sound: p.sound !== false, kinds: {} };
    KINDS.forEach(function (k) { out.kinds[k[0]] = !(p.kinds && p.kinds[k[0]] === false); });
    return out;
  }
  function save(p) { ls(KEY_PREFS, p); }
  function perm() { return supported ? Notification.permission : 'unsupported'; }
  function ready() { return supported && perm() === 'granted' && prefs().on; }

  // ---- am I looking at Anima? ------------------------------------------------------------------
  // Each page says so in localStorage while it is in front and has the keyboard, and takes it back
  // when it loses it. Freshness matters: a laptop that went to sleep with Anima in front must not
  // silence the notifications for ever, so the mark counts for 25 seconds and is renewed every 10.
  function frontHere() { return !document.hidden && document.hasFocus(); }
  function markFront() {
    if (frontHere()) ls(KEY_FRONT, { tab: TAB, at: Date.now() });
    else { var f = ls(KEY_FRONT); if (f && f.tab === TAB) ls(KEY_FRONT, null); }
  }
  function lookingAtAnima() {
    if (frontHere()) return true;
    var f = ls(KEY_FRONT);
    return !!(f && f.tab !== TAB && Date.now() - f.at < 25000);
  }
  window.addEventListener('focus', markFront);
  window.addEventListener('blur', markFront);
  window.addEventListener('pagehide', function () { var f = ls(KEY_FRONT); if (f && f.tab === TAB) ls(KEY_FRONT, null); });
  document.addEventListener('visibilitychange', markFront);
  setInterval(function () { if (frontHere()) markFront(); }, 10000);
  markFront();

  // ---- one tab asks while hidden ----------------------------------------------------------------
  // The bell stops asking in a tab nobody is looking at (that was right before this file: nobody
  // was being told anything). With notifications on, ONE hidden tab keeps asking, so five Anima tabs
  // in the background are not five times the requests. It holds a 70-second lease and renews it
  // each time it asks; if it closes, another takes over within a poll or two.
  function lead() {
    if (!ready()) return false;
    var l = ls(KEY_LEAD), now = Date.now();
    if (l && l.tab !== TAB && l.until > now) return false;
    ls(KEY_LEAD, { tab: TAB, until: now + 70000 });
    return true;
  }
  window.addEventListener('pagehide', function () { var l = ls(KEY_LEAD); if (l && l.tab === TAB) ls(KEY_LEAD, null); });

  // ---- told already? ------------------------------------------------------------------------------
  // key => when. Kept for two days, which is longer than any knock, call or unread bell line lasts.
  function withLock(fn) {
    if (navigator.locks && navigator.locks.request) {
      navigator.locks.request('anima-desk-notify', function () { fn(); }).catch(function () {});
    } else fn();
  }
  function claim(key) {
    var seen = ls(KEY_SEEN) || {}, now = Date.now();
    if (seen[key]) return false;
    Object.keys(seen).forEach(function (k) { if (now - seen[k] > 172800000) delete seen[k]; });
    seen[key] = now;
    ls(KEY_SEEN, seen);
    return true;
  }

  // ---- the sound ------------------------------------------------------------------------------------
  // Two soft notes, a fifth apart (A5 then E6), each fading out: a "ding-dong" quiet enough to sit
  // under music. The AudioContext is made on the first click or key press anywhere on the page,
  // because a browser will not let it make sound before somebody has touched the page.
  var AC = null;
  function audio() {
    try {
      if (!AC) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return null; AC = new C(); }
      if (AC.state === 'suspended') AC.resume();
    } catch (e) { return null; }
    return AC;
  }
  ['pointerdown', 'keydown'].forEach(function (ev) {
    window.addEventListener(ev, function wake() { window.removeEventListener(ev, wake, true); if (ready() && prefs().sound) audio(); }, true);
  });
  function touched() {
    // Chrome and Edge know whether the page has ever been clicked; elsewhere, whether our context runs.
    if (navigator.userActivation) return navigator.userActivation.hasBeenActive;
    return !!(AC && AC.state === 'running');
  }
  function chime() {
    var c = audio();
    if (!c) return false;
    try {
      var t = c.currentTime + 0.02;
      [[880, 0], [1318.5, 0.16]].forEach(function (n) {
        var o = c.createOscillator(), g = c.createGain();
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t + n[1]);
        g.gain.exponentialRampToValueAtTime(0.18, t + n[1] + 0.015);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.38);
        o.connect(g); g.connect(c.destination);
        o.start(t + n[1]); o.stop(t + n[1] + 0.4);
      });
      return true;
    } catch (e) { return false; }
  }

  // ---- showing one ------------------------------------------------------------------------------------
  // n: {key, kind, title, body, href, data}. `test` skips the "are you looking" and "told already"
  // checks, for the Send a test button.
  function show(n, test) {
    var p = prefs();
    var sound = p.sound && touched() && chime();
    try {
      var note = new Notification(n.title, {
        body: n.body || '',
        tag: n.key,
        icon: 'assets/icon-192.png',
        // Our own chime played: Windows' sound would be a second one. Muted: no sound at all.
        silent: !p.sound || sound,
        requireInteraction: n.kind === 'knocks' || n.kind === 'calls'
      });
      note.onclick = function () {
        try { window.focus(); } catch (e) { /* the browser decides */ }
        note.close();
        if (test) return;
        if (A.onClick && A.onClick(n) === true) return;
        if (n.href) location.href = n.href;
      };
    } catch (e) { /* a browser that refuses: the page's own signs still work */ }
  }
  function ring(n) {
    try {
      if (!ready()) return;
      var p = prefs();
      if (n.kind && p.kinds[n.kind] === false) return;
      withLock(function () {
        // Counted as told even when nothing is shown because you are looking at Anima: news you
        // have seen in the page must not pop up later when you switch to Maya.
        if (!claim(n.key)) return;
        if (lookingAtAnima()) return;
        show(n);
      });
    } catch (e) { /* never into the page */ }
  }

  // ---- what the bell brings (assets/bell.js, and the Realm's own ask) -----------------------------------
  // Each new line of the bell, once. The first answer after notifications are turned on only sets
  // the mark: lines that were there before are not news.
  var KIND_OF = { raven: 'ravens', tag: 'mentions', later: 'reminders', reminder: 'reminders',
                  mention: 'tasks', assigned: 'tasks', status: 'tasks', review: 'tasks' };
  function words(i) {
    var who = i.actor || 'Somebody';
    switch (i.kind) {
      case 'raven':    return [who + ' sent you a raven', 'Open the Realm to read it.'];
      case 'tag':      return [who + ' tagged you', 'In ' + (i.summary || 'the Realm') + '.'];
      case 'later':    return ['Reminder', 'About ' + (i.summary || 'a saved message') + '.'];
      case 'reminder': return ['Log your time', who + ' ' + (i.verb || 'asked you to log') + ' ' + (i.summary || '') + '.'];
      default:         return [who + ' ' + (i.verb || 'changed') , i.summary || 'a task'];
    }
  }
  function bell(res) {
    try {
      if (!res || !res.ok || !ready()) return;
      var items = res.items || [], top = 0;
      items.forEach(function (i) { if (+i.id > top) top = +i.id; });
      withLock(function () {
        var mark = ls(KEY_BELL);
        if (mark === null || mark === undefined) { ls(KEY_BELL, top); return; }
        if (top > mark) ls(KEY_BELL, top);
        items.filter(function (i) { return i['new'] && +i.id > mark; }).reverse().forEach(function (i) {
          var w = words(i);
          ring({ key: 'bell:' + i.id, kind: KIND_OF[i.kind] || 'tasks', title: w[0], body: w[1], href: i.href, data: i });
        });
      });
    } catch (e) { /* never into the page */ }
  }
  // In the Realm, which has no bell: ask notify_poll.php on the heartbeat (every 25 seconds).
  var lastAsk = 0;
  function bellCheck() {
    // 20 seconds, not 25: the heartbeat is itself 25, and a gap equal to it skips every other beat.
    if (!ready() || !window.fetch || Date.now() - lastAsk < 20000) return;
    if (document.hidden && !lead()) return;
    lastAsk = Date.now();
    fetch('notify_poll.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(bell).catch(function () {});
  }
  // Knocks on my door: [{id, name}], from realm-nudge.js on Anima pages and the Realm's own answers.
  function knocks(list) {
    (list || []).forEach(function (q) {
      if (!q || !q.id) return;
      ring({ key: 'knock:' + q.id, kind: 'knocks', title: (q.name || 'Somebody') + ' is knocking at your door',
             body: 'In the Realm. Click to answer.', href: 'realm.php', data: q });
    });
  }

  // ---- the settings window -----------------------------------------------------------------------------
  var dlg = null;
  function css() {
    if (document.getElementById('dnCss')) return;
    var s = document.createElement('style'); s.id = 'dnCss';
    s.textContent =
      '#dnDlg{max-width:440px;width:calc(100% - 32px);border:1px solid #3a3a3a;border-radius:10px;padding:18px 20px;background:#191919;color:#ededed;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.45)}' +
      '#dnDlg::backdrop{background:rgba(0,0,0,.45)}' +
      '#dnDlg h2{margin:0 0 6px;font-size:17px;font-weight:600}' +
      '#dnDlg p{margin:0 0 10px;color:#c9c6bf}' +
      '#dnDlg .dn-row{display:flex;align-items:flex-start;gap:8px;margin:6px 0;cursor:pointer}' +
      '#dnDlg .dn-row input{margin-top:3px;width:auto;height:auto;flex:none}' +
      '#dnDlg fieldset{border:1px solid #2b2b2b;border-radius:8px;margin:10px 0;padding:6px 12px 8px}' +
      '#dnDlg fieldset[disabled]{opacity:.5}' +
      '#dnDlg legend{padding:0 4px;color:#9aa0ab;font-size:12px;text-transform:uppercase;letter-spacing:.05em}' +
      '#dnDlg .dn-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}' +
      '#dnDlg button{font:inherit;border-radius:6px;padding:6px 12px;border:1px solid #3a3a3a;background:#242424;color:#ededed;cursor:pointer;width:auto;height:auto;margin:0}' +
      '#dnDlg button.dn-main{background:#5fb2ff;border-color:#5fb2ff;color:#101010;font-weight:600}' +
      '#dnDlg .dn-note{font-size:12.5px;color:#9aa0ab}' +
      '#dnDlg details{margin-top:10px}#dnDlg summary{cursor:pointer;color:#5fb2ff}' +
      '#dnDlg ol{margin:6px 0 0;padding-left:20px;color:#c9c6bf}';
    document.head.appendChild(s);
  }
  var installEvt = null;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); installEvt = e; if (dlg && dlg.open) paint(); });
  function installed() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  }
  function paint() {
    var p = prefs(), pm = perm(), h = '<h2>Desktop notifications</h2>';
    if (pm === 'unsupported') {
      h += '<p>This browser cannot show desktop notifications. Chrome or Edge on Windows can.</p>';
    } else {
      h += '<p>Get a small pop-up in the corner of the screen, with a soft sound, when something needs you while you are working in another program: a raven, a tag, a knock on your door, a call in your room, a reminder. Nothing pops up while you are looking at Anima itself.</p>';
      if (pm === 'denied') {
        h += '<p><b>This browser is blocking notifications from Anima.</b> To allow them: click the padlock (or the icon) at the left of the address bar, set Notifications to Allow, then reload the page.</p>';
      } else if (pm === 'default' || !p.on) {
        h += '<div class="dn-btns"><button type="button" class="dn-main" data-dn="on">Turn on desktop notifications</button></div>';
        if (pm === 'default') h += '<p class="dn-note">Your browser will then ask whether Anima may show notifications. Choose Allow.</p>';
      } else {
        h += '<label class="dn-row"><input type="checkbox" data-dn="master" checked> <span><b>On</b> on this computer</span></label>';
      }
      var off = pm !== 'granted' || !p.on ? ' disabled' : '';
      h += '<fieldset' + off + '><legend>Tell me about</legend>' + KINDS.map(function (k) {
        return '<label class="dn-row"><input type="checkbox" data-dnkind="' + k[0] + '"' + (p.kinds[k[0]] ? ' checked' : '') + '> <span>' + k[1] + '</span></label>';
      }).join('') + '</fieldset>';
      h += '<label class="dn-row"' + (off ? ' style="opacity:.5"' : '') + '><input type="checkbox" data-dn="sound"' + (p.sound ? ' checked' : '') + off + '> <span>Play a sound</span></label>';
      if (pm === 'granted' && p.on) h += '<div class="dn-btns"><button type="button" data-dn="test">Send a test</button></div>';
      h += '<p class="dn-note">These choices are kept on this computer only. Notifications arrive while at least one Anima tab or window is open, up to about half a minute after the event.</p>';
    }
    h += '<details><summary>Keep Anima open: install it as an app</summary>';
    if (installed()) h += '<p>Anima is open as an installed app. In Edge, to open it whenever Windows starts: open edge://apps, click the three dots on Anima, and turn on <b>Start automatically on device login</b>.</p>';
    else {
      h += (installEvt ? '<div class="dn-btns"><button type="button" class="dn-main" data-dn="install">Install Anima as an app</button></div>' : '') +
        '<ol><li>In Edge or Chrome, click the <b>Install</b> icon at the right of the address bar (a small screen with an arrow), or the three-dot menu, then Apps (Edge) or Cast, save and share (Chrome), then <b>Install Anima</b>.</li>' +
        '<li>Anima opens in its own window and gets an icon in the Start menu and taskbar.</li>' +
        '<li>To open it whenever Windows starts, in Edge: open edge://apps, click the three dots on Anima, turn on <b>Start automatically on device login</b>. In Chrome: open chrome://apps, right-click Anima, <b>Start app when you sign in</b>.</li></ol>';
    }
    h += '</details><div class="dn-btns"><button type="button" data-dn="close">Close</button></div>';
    dlg.innerHTML = h;
  }
  function open() {
    try {
      css();
      if (!dlg) {
        dlg = document.createElement('dialog'); dlg.id = 'dnDlg'; dlg.setAttribute('aria-label', 'Desktop notifications');
        document.body.appendChild(dlg);
        dlg.addEventListener('click', act);
        dlg.addEventListener('change', act);
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
      }
      paint();
      if (dlg.showModal) { if (!dlg.open) dlg.showModal(); } else dlg.setAttribute('open', '');
    } catch (e) { /* no window, no harm */ }
  }
  function act(e) {
    var t = e.target, w = t.getAttribute && t.getAttribute('data-dn'), k = t.getAttribute && t.getAttribute('data-dnkind'), p = prefs();
    if (e.type === 'click' && w === 'on') {
      // The browser's question comes from this click, never from page load.
      audio();
      var go = function (r) {
        if (r === 'granted') { p.on = true; save(p); ls(KEY_BELL, null); paintButtons(); paint(); show({ key: 'dn-hello', title: 'Desktop notifications are on', body: 'This is how Anima will tell you when something needs you.' }, true); }
        else paint();
      };
      // Older browsers answer through the callback, newer through the promise; some do both.
      var done = false, once = function (r) { if (!done) { done = true; go(r); } };
      var q = Notification.requestPermission(once);
      if (q && q.then) q.then(once);
    } else if (e.type === 'change' && w === 'master') {
      p.on = t.checked; save(p); paintButtons(); paint();
    } else if (e.type === 'change' && w === 'sound') {
      p.sound = t.checked; save(p); if (p.sound) chime();
    } else if (e.type === 'change' && k) {
      p.kinds[k] = t.checked; save(p);
    } else if (e.type === 'click' && w === 'test') {
      show({ key: 'dn-test', title: 'Anima', body: 'A test notification. This is what a raven or a knock will look like.' }, true);
    } else if (e.type === 'click' && w === 'install' && installEvt) {
      installEvt.prompt(); installEvt = null;
    } else if (e.type === 'click' && w === 'close') {
      dlg.close();
    }
  }
  // Every button with data-desknotify opens the window, and says whether notifications are on.
  function paintButtons() {
    var on = ready();
    Array.prototype.forEach.call(document.querySelectorAll('[data-desknotify]'), function (b) {
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      // "short" is the Realm's crowded top bar.
      b.textContent = b.getAttribute('data-desknotify') === 'short'
        ? (on ? 'Notifications: on' : 'Notifications: off')
        : (on ? 'Desktop notifications: on' : 'Turn on desktop notifications');
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-desknotify]');
    if (b) { e.preventDefault(); e.stopPropagation(); open(); }
  }, true);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paintButtons); else paintButtons();
  // Another tab turned them on or off: this one's buttons say so too.
  window.addEventListener('storage', function (e) { if (e.key === KEY_PREFS) paintButtons(); });

  var A = window.AnimaNotify = {
    ready: ready, lead: lead, ring: ring, bell: bell, bellCheck: bellCheck, knocks: knocks, open: open,
    // The Realm sets this, to open a raven or a message without reloading the page (which would end
    // a call). Returning true means it was handled.
    onClick: null
  };
}());
