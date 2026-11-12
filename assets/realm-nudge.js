/* The Realm's nudge on every Anima page (David, 2026-11-12).
 *
 * He tried ravens with Renata and the count beside her name worked, "but I need to be on that
 * page". Nobody sits on the Realm all day; they are in Spell or My tasks. So the "Realm" button in
 * the top bar carries the number of unread ravens and channel messages, kept fresh without a
 * reload, and the browser tab's title carries it too for when Anima is in the background.
 *
 * Loaded by includes/header.php only for somebody who has the Realm. It asks realm_api.php one
 * small question every 20 seconds and touches nothing: it does not move the person's character or
 * make them look present in the village. Any failure is silent; the button is then just a button.
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
  function ask() {
    fetch('realm_api.php?unread=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        var n = 0, u = j.unread || {};
        Object.keys(u).forEach(function (k) { n += Number(u[k]) || 0; });
        show(n);
      })
      .catch(function () { /* the next ask may succeed */ });
  }
  ask();
  setInterval(ask, 20000);
})();
