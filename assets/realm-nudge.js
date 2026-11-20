/* The Realm's nudge on every Anima page (David, 2026-11-12).
 *
 * He tried ravens with Renata and the count beside her name worked, "but I need to be on that
 * page". Nobody sits on the Realm all day; they are in Spell or My tasks. So the "Realm" button in
 * the top bar carries the number of unread ravens and channel messages, kept fresh without a
 * reload, and the browser tab's title carries it too for when Anima is in the background.
 *
 * Loaded by includes/header.php only for somebody who has the Realm. Since 2026-11-20 it rides on
 * the bell's poll (one request every 30 seconds for both; see below) and asks by itself only on a
 * page with no bell. It touches nothing: it does not move the person's character or make them look
 * present in the village. Any failure is silent; the button is then just a button.
 */
(function () {
  'use strict';
  var link = document.getElementById('navRealm');
  if (!link || !window.fetch) return;
  var title = document.title, mark = null;
  function show(n) {
    if (n > 0) {
      if (!mark) { mark = document.createElement('span'); mark.className = 'mytasks-badge is-new'; mark.title = 'Unread in the Realm'; link.appendChild(mark); }
      mark.textContent = n > 9 ? '9+' : String(n);
    } else if (mark) { link.removeChild(mark); mark = null; }
    document.title = (n > 0 ? '(' + n + ') ' : '') + title;
  }
  function count(j) {
    var n = 0, u = j.unread || {};
    Object.keys(u).forEach(function (k) { n += Number(u[k]) || 0; });
    // Somebody knocking at your door in the Realm counts too (migration 084).
    if (j.knocks && j.knocks.for_me) n += j.knocks.for_me.length;
    return n;
  }
  function take(j) {
    if (!j || !j.ok) return;
    show(count(j));
    // A knock as a desktop notification too (David, 2026-11-16; assets/desk-notify.js).
    // Ravens and tags come through the bell, which knows who sent them.
    if (window.AnimaNotify && j.knocks) window.AnimaNotify.knocks(j.knocks.for_me);
  }

  // ONE REQUEST, NOT TWO (KnownHost, 2026-11-19: the plan's limit of ten PHP requests at once was
  // being reached). Where the bell is on the page, its poll (assets/bell.js, every 30 seconds)
  // brings this count too, and this file only listens. The answer is also kept on this computer
  // for a minute, so moving from page to page shows the number at once without asking again.
  var KEY = 'anima.realmNudge';
  function remember(j) { try { localStorage.setItem(KEY, JSON.stringify({ at: Date.now(), j: j })); } catch (e) { /* private window */ } }
  function recall() {
    try { var c = JSON.parse(localStorage.getItem(KEY) || 'null'); if (c && Date.now() - c.at < 60000) return c.j; } catch (e) { /* nothing kept */ }
    return null;
  }
  var kept = recall();
  if (kept) show(count(kept));

  if (window.AnimaPulse) {
    window.AnimaPulse.realm(function (j) { remember(j); take(j); });
    if (!kept) window.AnimaPulse.now();
    return;
  }

  // No bell on this page (it can be switched off): ask realm_api.php by ourselves, at the bell's
  // pace, never while the tab is hidden, and backing off when the server does not answer.
  var EVERY = 30000, fails = 0, quietUntil = 0, lastAsk = 0;
  function ask() {
    if (document.hidden || Date.now() < quietUntil) return;
    lastAsk = Date.now();
    fetch('realm_api.php?unread=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { fails = 0; quietUntil = 0; remember(j); take(j); })
      .catch(function () { fails = Math.min(fails + 1, 4); quietUntil = Date.now() + Math.min(EVERY * Math.pow(2, fails), 300000); });
  }
  if (!kept) ask();
  setInterval(ask, EVERY);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastAsk > 10000) ask(); });
})();
