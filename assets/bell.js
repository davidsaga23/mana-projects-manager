// The bell beside your name.
//
// David, 2026-10-22: "you need to get a notification in a bell close to your
// name so you know someone assign, tag or changed a tasked you're subscribed
// to". The list is rendered by includes/header.php on every page load, and kept
// fresh after that by asking notify_poll.php (2026-11-12, further down). This
// file is the opening, the closing, the one POST that says it has been read, and
// that refresh.
(function () {
  'use strict';

  var bell = document.getElementById('bell');
  var btn = document.getElementById('bellBtn');
  var panel = document.getElementById('bellPanel');
  if (!bell || !btn || !panel) return;

  var cleared = false;

  function shut() {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  }

  // THUMBNAILS (David, 2026-11-17: "get the latest version as a thumbnail in the
  // notifications"). They are drawn with data-src so a page that never opens the
  // bell never fetches them; the first opening does. A film shows its first frame.
  function wake() {
    Array.prototype.forEach.call(panel.querySelectorAll('.bell-thumb [data-src]'), function (m) {
      var src = m.getAttribute('data-src');
      m.removeAttribute('data-src');
      if (m.tagName === 'VIDEO') { m.preload = 'metadata'; m.src = src + '#t=0.1'; }
      else m.src = src;
    });
  }

  function open() {
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    wake();

    // ⚠ ONCE PER PAGE. Opening the bell marks everything in it read, and the
    // count disappears immediately rather than on the next page load — a badge
    // still showing "3" over a list you are reading is the kind of small lie
    // that makes people stop trusting the number. The rows keep their unread
    // tint until the page is reloaded, so you can still see which three they
    // were.
    if (cleared) return;
    cleared = true;

    fetch('notify_seen.php', { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) return;
        var count = document.getElementById('bellCount');
        if (count) count.remove();
        var head = panel.querySelector('.bell-head .muted');
        if (head) head.remove();
      })
      // Silent: a count that fails to clear is a small annoyance, and an error
      // shouted into the top bar over it would be a larger one. It clears on
      // the next page load anyway.
      .catch(function () { cleared = false; });
  }

  // ---- kept fresh without a reload -------------------------------------------
  // David, 2026-11-12: "Can we change that behavior to be more real-time and get
  // those notifications?" Every 30 seconds, and the moment the page comes back
  // to the front, ask notify_poll.php for the count and the list and redraw
  // them. Asking marks nothing read; only opening the bell does.
  //
  // ⚠ NEVER REDRAWN WHILE IT IS OPEN. A list that reshuffles under the cursor
  // sends a click to the wrong task. The count still moves; the rows wait until
  // the bell is shut.
  var drawn = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function paint(res) {
    if (!res || !res.ok) return;
    var n = Number(res.unseen) || 0;
    var count = document.getElementById('bellCount');
    if (n > 0) {
      if (!count) {
        count = document.createElement('span');
        count.className = 'bell-count';
        count.id = 'bellCount';
        btn.appendChild(count);
      }
      count.textContent = n > 9 ? '9+' : String(n);
      // Something new since the bell was last opened: opening it again must
      // mark that read too.
      cleared = false;
    } else if (count) {
      count.remove();
    }
    btn.setAttribute('aria-label', n
      ? n + ' unread notification' + (n === 1 ? '' : 's')
      : 'Notifications, none unread');

    if (!panel.hidden) return;
    var items = res.items || [];
    var sig = n + '|' + items.map(function (i) { return i.id + (i['new'] ? 'n' : 's'); }).join(',');
    sig += '|' + items.map(function (i) { return i.opened ? 'o' : ''; }).join('');
    sig += '|' + items.map(function (i) { return i.thumb ? i.thumb.url : ''; }).join(',');
    if (sig === drawn) return;
    drawn = sig;

    var head = panel.querySelector('.bell-head');
    if (head) head.innerHTML = 'Notifications' + (n ? ' <span class="muted">· ' + n + ' new</span>' : '');
    if (!items.length) return;
    var empty = panel.querySelector('.bell-empty');
    if (empty) empty.remove();
    // CLICKED ROWS FOLD AWAY (Tim and David, 2026-11-17): not opened on top, opened
    // under "Earlier", the same two parts includes/header.php draws. Whether
    // Earlier was left open is kept across a redraw.
    // The same thumbnail includes/header.php draws (notify_thumb_html()), or none.
    var thumb = function (t) {
      if (!t || !t.url) return '';
      return '<span class="bell-thumb" aria-hidden="true">'
        + (t.video ? '<video data-src="' + esc(t.url) + '" muted playsinline preload="none"></video>'
                   : '<img data-src="' + esc(t.url) + '" alt="">')
        + '</span>';
    };
    var row = function (i) {
      var th = thumb(i.thumb);
      return '<li class="' + (i['new'] ? 'is-new' : '') + '"><a href="' + esc(i.href) + '" data-notify="' + Number(i.id) + '"'
        + (th ? ' class="has-thumb"' : '') + '>'
        + '<span class="bell-dot bell-' + esc(i.kind) + '" aria-hidden="true"></span>' + th
        + '<span class="bell-what"><strong>' + esc(i.actor) + '</strong> ' + esc(i.verb) + ' ' + esc(i.summary) + '</span>'
        + '<span class="bell-when muted">' + esc(i.when) + '</span></a></li>';
    };
    var now = items.filter(function (i) { return !i.opened; }), old = items.filter(function (i) { return i.opened; });
    var was = panel.querySelector('.bell-earlier'), wasOpen = !!(was && was.open);
    Array.prototype.forEach.call(panel.querySelectorAll('.bell-list, .bell-earlier, .bell-empty-now'), function (el) { el.remove(); });
    var html = '<ul class="bell-list">' + now.map(row).join('') + '</ul>'
      + (now.length ? '' : '<p class="muted small bell-empty-now">Nothing new. Everything you have opened is under Earlier.</p>')
      + (old.length ? '<details class="bell-earlier"' + (wasOpen ? ' open' : '') + '><summary>Earlier <span class="muted">(' + old.length + ')</span></summary>'
        + '<ul class="bell-list">' + old.map(row).join('') + '</ul></details>' : '');
    // Above the "Desktop notifications" button, which stays at the foot.
    var foot = panel.querySelector('.bell-foot');
    if (foot) foot.insertAdjacentHTML('beforebegin', html); else panel.insertAdjacentHTML('beforeend', html);
  }

  // A clicked row is recorded as opened, so it waits under Earlier from now on.
  // A beacon, because the click goes on to another page and an ordinary request
  // would be cut off. The link itself is followed as normal.
  panel.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-notify]') : null;
    if (!a) return;
    var id = a.getAttribute('data-notify');
    try {
      var body = new FormData(); body.append('id', id);
      if (navigator.sendBeacon) navigator.sendBeacon('notify_open.php', body);
      else fetch('notify_open.php', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
    } catch (err) { /* the row simply stays on top */ }
    drawn = null;
  });

  // Desktop notifications (David, 2026-11-16; assets/desk-notify.js) are handed every answer.
  var desk = window.AnimaNotify || null;

  // ---- one request for the whole top bar (KnownHost, 2026-11-19) ----------------------------
  // The hosting plan runs at most ten PHP requests at once, and Anima was reaching that limit:
  // pages spun and logins looked stuck. Every page used to poll twice, this bell and the Realm
  // button's count (assets/realm-nudge.js, every 20 seconds). Now this poll carries both: with the
  // Realm button on the page it asks notify_poll.php?realm=1 and hands the Realm part to whoever
  // registered with AnimaPulse.realm(). One request every 30 seconds, nothing while hidden.
  //
  // BACKING OFF. A request that fails (the server busy, a time-out) doubles the wait before the
  // next one, up to five minutes, and the first success puts it back to 30 seconds: a server that
  // is struggling is not hammered by every open page at once.
  var realmLink = document.getElementById('navRealm');
  var realmTold = [];
  var lastAsk = 0, fails = 0, quietUntil = 0, asking = false;
  var EVERY = 30000;

  function poll(force) {
    if (!window.fetch || asking) return;
    // A page nobody is looking at does not ask; it catches up when it is looked at. EXCEPT, once
    // desktop notifications are on, ONE hidden Anima tab keeps asking (desk.lead() decides which),
    // because a notification is for exactly when nobody is looking at Anima.
    if (document.hidden && !(desk && desk.lead())) return;
    if (!force && Date.now() < quietUntil) return;
    asking = true; lastAsk = Date.now();
    fetch('notify_poll.php' + (realmLink ? '?realm=1' : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (res) {
        asking = false; fails = 0; quietUntil = 0;
        paint(res); if (desk) desk.bell(res);
        if (res && res.realm) realmTold.forEach(function (fn) { try { fn(res.realm); } catch (e) { /* the bell goes on */ } });
      })
      // Silent, like the clearing above: the bell simply stays as the page drew it.
      .catch(function () {
        asking = false; fails = Math.min(fails + 1, 4);
        quietUntil = Date.now() + Math.min(EVERY * Math.pow(2, fails), 300000);
      });
  }

  window.AnimaPulse = {
    // assets/realm-nudge.js: called with {unread, knocks} after every poll that brought them.
    realm: function (fn) { realmTold.push(fn); },
    // Ask now, once, unless a poll went out in the last few seconds.
    now: function () { if (Date.now() - lastAsk > 5000) poll(true); }
  };

  setInterval(poll, EVERY);
  // Back in front: one request at once, unless the last one was a moment ago.
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastAsk > 10000) poll(); });

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (panel.hidden) open(); else shut();
  });

  // Anywhere else, and Escape. A dropdown that needs its own button pressed
  // again to go away is a dropdown people leave open by accident.
  document.addEventListener('click', function (e) {
    if (!panel.hidden && !bell.contains(e.target)) shut();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { shut(); btn.focus(); }
  });
}());
