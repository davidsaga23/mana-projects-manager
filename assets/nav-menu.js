// The Admin / More menu at the right of the top bar.
//
// David, 2026-11-13: "let's create a button that says admin, inside that let's
// include all these tabs so we don't have such a busy top bar". The links are
// drawn by includes/header.php, already filtered person by person; this file is
// only the opening and closing.
//
// Opens on a CLICK, not on hover, so it works on a tablet. Shuts on Escape (focus
// goes back to the button), on a click anywhere outside it, and when focus tabs
// out of it. Arrow keys move between the links once it is open; Tab works too,
// because they are ordinary links in reading order.
(function () {
  'use strict';

  var box = document.getElementById('navMenu');
  var btn = document.getElementById('navMenuBtn');
  var panel = document.getElementById('navMenuPanel');
  if (!box || !btn || !panel) return;

  function links() {
    return Array.prototype.slice.call(panel.querySelectorAll('a'));
  }

  function isOpen() { return !panel.hidden; }

  function open(focusFirst) {
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    if (focusFirst) {
      var first = panel.querySelector('a.is-on') || links()[0];
      if (first) first.focus();
    }
  }

  function shut(backToButton) {
    if (!isOpen()) return;
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    if (backToButton) btn.focus();
  }

  btn.addEventListener('click', function () {
    if (isOpen()) shut(false); else open(false);
  });

  // ArrowDown on the closed button opens it at the first link, the way a
  // select does; Enter and Space are the button's own click.
  btn.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); open(true); }
  });

  panel.addEventListener('keydown', function (ev) {
    var all = links();
    var i = all.indexOf(document.activeElement);
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      var step = ev.key === 'ArrowDown' ? 1 : -1;
      var next = all[(i + step + all.length) % all.length];
      if (next) next.focus();
    } else if (ev.key === 'Home' || ev.key === 'End') {
      ev.preventDefault();
      var end = ev.key === 'Home' ? all[0] : all[all.length - 1];
      if (end) end.focus();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && isOpen()) shut(box.contains(document.activeElement));
  });

  document.addEventListener('click', function (ev) {
    if (isOpen() && !box.contains(ev.target)) shut(false);
  });

  // Tabbing past the last link leaves the menu, and the menu should not stay
  // hanging open over the page behind it.
  box.addEventListener('focusout', function (ev) {
    if (isOpen() && ev.relatedTarget && !box.contains(ev.relatedTarget)) shut(false);
  });
})();
