// The "log your time" pop-up (migration 080; includes/log_reminders.php).
//
// David, 2026-11-11: "add pop up reminders for everyone to log times at the end
// of the day and a button for admins to send and instant pop up for logging
// times as an alternative".
//
// Loaded on every page by includes/header.php, so it is small and does nothing
// until it has to: it asks log_reminder_api.php every few minutes whether there
// is something to show, and stops asking while the tab is hidden. The server
// decides everything (the time, the day, whether you have logged, whether an
// admin sent one); this only draws the card and posts the button presses. Its
// stylesheet is fetched the first time a card is drawn, not on every page.
(function () {
  'use strict';

  var me = document.currentScript;
  var API = 'log_reminder_api.php';
  var EVERY = 3 * 60 * 1000;          // three minutes between asks while visible
  var cssHref = me ? me.getAttribute('data-css') : '';
  var timer = null;
  var lastAsk = 0;
  var stopped = false;
  var card = null;
  var shownKey = '';

  function post(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(API, { method: 'POST', credentials: 'same-origin', body: body })
      .catch(function () { /* the next poll tries again */ });
  }

  function loadCss() {
    if (!cssHref || document.getElementById('logReminderCss')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = cssHref;
    l.id = 'logReminderCss';
    document.head.appendChild(l);
  }

  function hide() {
    if (card && card.parentNode) card.parentNode.removeChild(card);
    card = null;
    shownKey = '';
  }

  function onMyTasks() {
    return /(^|\/)mytasks\.php$/.test(location.pathname);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }

  function draw(r) {
    var key = r.kind + ':' + r.id;
    if (card && shownKey === key) return;
    hide();
    loadCss();

    card = el('section', 'logrem');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-labelledby', 'logremTitle');
    shownKey = key;

    var close = el('button', 'logrem-x');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '<svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true">'
      + '<path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    card.appendChild(close);

    card.appendChild(el('div', 'logrem-label', r.kind === 'instant' ? 'Reminder' : 'End of the day'));
    var h = el('h3', 'logrem-title', r.title);
    h.id = 'logremTitle';
    card.appendChild(h);
    card.appendChild(el('p', 'logrem-text', r.text));
    if (r.note) card.appendChild(el('p', 'logrem-note', r.note));

    var row = el('div', 'logrem-actions');
    var go = el('button', 'logrem-go', 'Log my time');
    go.type = 'button';
    var later = el('button', 'secondary logrem-later', 'Remind me in an hour');
    later.type = 'button';
    var away = el('button', 'linkish logrem-away', 'Dismiss');
    away.type = 'button';
    row.appendChild(go);
    row.appendChild(later);
    row.appendChild(away);
    card.appendChild(row);

    var dismissValue = r.kind === 'instant' ? String(r.id) : 'auto';
    function dismiss() { post({ dismiss: dismissValue }); hide(); }
    close.addEventListener('click', dismiss);
    away.addEventListener('click', dismiss);
    later.addEventListener('click', function () { post({ snooze: 60 }); hide(); });
    go.addEventListener('click', function () {
      // An admin's reminder has been acted on; the end-of-day one rests for
      // half an hour while the hours are typed, and stops by itself once
      // anything is logged today.
      var done = r.kind === 'instant' ? post({ dismiss: dismissValue }) : post({ snooze: 30 });
      hide();
      if (onMyTasks()) {
        var box = document.querySelector('.log-form input[name="hours"]:not([disabled])');
        if (box) { box.scrollIntoView({ block: 'center' }); box.focus(); }
        return;
      }
      Promise.resolve(done).then(function () { location.href = 'mytasks.php'; });
    });

    document.body.appendChild(card);
  }

  function ask() {
    if (stopped) return;
    lastAsk = Date.now();
    fetch(API, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (res.status === 401 || res.status === 403) { stop(); return null; }
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (!data) return;
        if (data.off) { stop(); hide(); return; }
        if (data.show) draw(data.show); else hide();
      })
      .catch(function () { /* offline or a hiccup: the next poll tries again */ });
  }

  function schedule() {
    clearInterval(timer);
    timer = null;
    if (stopped || document.hidden) return;
    timer = setInterval(ask, EVERY);
  }

  function stop() {
    stopped = true;
    clearInterval(timer);
    timer = null;
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && Date.now() - lastAsk > 60 * 1000) ask();
    schedule();
  });

  // A few seconds after the page settles, so it never competes with the page itself.
  setTimeout(function () { if (!document.hidden) ask(); schedule(); }, 4000);
})();
