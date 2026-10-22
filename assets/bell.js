// The bell beside your name.
//
// David, 2026-10-22: "you need to get a notification in a bell close to your
// name so you know someone assign, tag or changed a tasked you're subscribed
// to". The list itself is rendered by includes/header.php on every page load —
// this is only the opening, the closing, and the one POST that says it has been
// read.
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
