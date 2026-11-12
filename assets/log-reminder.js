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
//
// DESKTOP NOTIFICATIONS (David, 2026-11-11: "make sure anima ask for permissions
// to send notifications to the desktop too"). A pop-up only reaches somebody
// looking at the tab. So Anima asks once, with its own small card and a button
// (browsers only let a page ask from a click), and once allowed, each reminder
// is also sent to the desktop, and the asking carries on, more slowly, while
// the tab is in the background, which is exactly when the desktop one matters.
// "Not now" is remembered in this browser for two weeks; a "Block" in the
// browser is final and Anima never asks again.
(function () {
  'use strict';

  var me = document.currentScript;
  var API = 'log_reminder_api.php';
  var EVERY = 3 * 60 * 1000;          // three minutes between asks while visible
  var EVERY_HIDDEN = 5 * 60 * 1000;   // five while hidden, only once desktop notifications are allowed
  var ASK_LATER = 14 * 24 * 3600 * 1000;
  var canNotify = 'Notification' in window && window.isSecureContext !== false;
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

  // Browser storage can be missing or throw (private windows, blocked site
  // data); every use is wrapped, and losing it only means asking again.
  function store(k, v) {
    try {
      if (v === undefined) return localStorage.getItem(k);
      localStorage.setItem(k, v);
    } catch (e) { /* nothing to remember with */ }
    return null;
  }

  function allowed() {
    return canNotify && Notification.permission === 'granted';
  }

  // One desktop notification per reminder per day, however many tabs are
  // open: the tag makes the browser replace rather than stack, and the stored
  // key stops a reload from sending it again.
  function desktop(r) {
    // Somebody looking at this tab already has the card in front of them.
    if (!allowed() || (!document.hidden && document.hasFocus())) return;
    var key = 'animaLogremNotified';
    var mark = new Date().toDateString() + '|' + r.kind + ':' + r.id;
    if (store(key) === mark) return;
    store(key, mark);
    try {
      var n = new Notification(r.title, {
        body: r.text + (r.note ? '\n“' + r.note + '”' : ''),
        tag: 'anima-logrem-' + r.kind + '-' + r.id,
        icon: 'assets/logo.webp'
      });
      n.onclick = function () { window.focus(); n.close(); };
    } catch (e) { /* some browsers only notify from a service worker */ }
  }

  // The ask. Drawn only when the browser has not been asked yet, nothing else
  // is on screen, and "Not now" was not pressed in the last two weeks.
  function maybeAsk() {
    if (!canNotify || Notification.permission !== 'default' || card) return;
    if (Date.now() < Number(store('animaNotifyLater') || 0)) return;
    loadCss();
    card = el('section', 'logrem logrem-ask');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-labelledby', 'logremTitle');
    shownKey = 'ask';
    card.appendChild(el('div', 'logrem-label', 'Desktop notifications'));
    var h = el('h3', 'logrem-title', 'Get Anima reminders on your desktop?');
    h.id = 'logremTitle';
    card.appendChild(h);
    card.appendChild(el('p', 'logrem-text',
      'So the reminder to log your time reaches you even when Anima is in another tab. '
      + 'Your browser asks you to confirm.'));
    var row = el('div', 'logrem-actions');
    var yes = el('button', 'logrem-go', 'Turn on');
    yes.type = 'button';
    var no = el('button', 'linkish logrem-away', 'Not now');
    no.type = 'button';
    row.appendChild(yes);
    row.appendChild(no);
    card.appendChild(row);
    yes.addEventListener('click', function () {
      // Closing the browser's question without answering leaves it unasked;
      // wait a day before offering again rather than on the next poll.
      store('animaNotifyLater', String(Date.now() + 24 * 3600 * 1000));
      hide();
      var asked;
      try { asked = Notification.requestPermission(function () { schedule(); }); } catch (e) { asked = null; }
      if (asked && asked.then) asked.then(function () { schedule(); });
    });
    no.addEventListener('click', function () {
      store('animaNotifyLater', String(Date.now() + ASK_LATER));
      hide();
    });
    document.body.appendChild(card);
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
    desktop(r);
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
        if (data.show) { draw(data.show); return; }
        if (shownKey !== 'ask') hide();
        maybeAsk();
      })
      .catch(function () { /* offline or a hiccup: the next poll tries again */ });
  }

  function schedule() {
    clearInterval(timer);
    timer = null;
    if (stopped) return;
    if (document.hidden) {
      // Hidden: only worth asking if the answer can reach the desktop.
      if (allowed()) timer = setInterval(ask, EVERY_HIDDEN);
      return;
    }
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
