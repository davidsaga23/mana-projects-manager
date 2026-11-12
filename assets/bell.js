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

  function open() {
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');

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
    if (sig === drawn) return;
    drawn = sig;

    var head = panel.querySelector('.bell-head');
    if (head) head.innerHTML = 'Notifications' + (n ? ' <span class="muted">· ' + n + ' new</span>' : '');
    if (!items.length) return;
    var empty = panel.querySelector('.bell-empty');
    if (empty) empty.remove();
    var list = panel.querySelector('.bell-list');
    if (!list) {
      list = document.createElement('ul');
      list.className = 'bell-list';
      panel.appendChild(list);
    }
    list.innerHTML = items.map(function (i) {
      return '<li class="' + (i['new'] ? 'is-new' : '') + '"><a href="' + esc(i.href) + '">'
        + '<span class="bell-dot bell-' + esc(i.kind) + '" aria-hidden="true"></span>'
        + '<span class="bell-what"><strong>' + esc(i.actor) + '</strong> ' + esc(i.verb) + ' ' + esc(i.summary) + '</span>'
        + '<span class="bell-when muted">' + esc(i.when) + '</span></a></li>';
    }).join('');
  }

  function poll() {
    // A page nobody is looking at does not ask; it catches up when it is looked at.
    if (document.hidden || !window.fetch) return;
    fetch('notify_poll.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(paint)
      // Silent, like the clearing above: the bell simply stays as the page drew it.
      .catch(function () {});
  }

  setInterval(poll, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

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
