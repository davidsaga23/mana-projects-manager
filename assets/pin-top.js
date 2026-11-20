/* THE TOP BAR STAYS ON SCREEN (David, 2026-11-20: "when scrolling down let's keep
   this part at the top always"). The CSS makes .topbar and anything marked
   data-pin sticky (assets/style.css, by .topbar); this file does the two things
   CSS cannot:

   1. STACKS THEM. Each pinned row gets a `top` equal to the height of the rows
      above it, and the total goes to --pin-h on <html>, which every other
      page-level sticky (Spell's filters, the colour key, the bulk rates) uses
      as its `top`. Re-measured whenever a pinned row changes size: the bar wraps
      to two lines on a narrow window, and Spell's chips wrap with many shows.

   2. MARKS THE TABLES THAT SCROLL WITH THE PAGE. Every <th> is sticky, but a
      table inside its own scroll box (.table-scroll, .grid-scroll, …) sticks to
      THAT box and must keep top: 0, while a table that scrolls with the page
      must sit under the bar or its headings slide behind it. CSS cannot tell the
      two apart, so the page-level ones get .pin-under, which gives their headings
      --th-top = --pin-h.

   Loaded on every page from includes/header.php, so it is small and never throws. */
(function () {
  'use strict';
  var root = document.documentElement;

  function measure() {
    var y = 0;
    document.querySelectorAll('.topbar, [data-pin]').forEach(function (el) {
      if (getComputedStyle(el).position !== 'sticky') return;
      el.style.top = y + 'px';
      y += el.getBoundingClientRect().height;
    });
    root.style.setProperty('--pin-h', Math.round(y) + 'px');
  }

  function scrollsWithPage(table) {
    for (var p = table.parentElement; p && p !== document.body; p = p.parentElement) {
      var cs = getComputedStyle(p);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') return false;
    }
    return true;
  }

  function markTables() {
    document.querySelectorAll('table:not(.pin-under)').forEach(function (t) {
      if (scrollsWithPage(t)) t.classList.add('pin-under');
    });
  }

  function start() {
    try {
      measure();
      markTables();
      window.addEventListener('resize', measure);
      if (window.ResizeObserver) {
        var ro = new ResizeObserver(measure);
        document.querySelectorAll('.topbar, [data-pin]').forEach(function (el) { ro.observe(el); });
      }
    } catch (e) { /* the bar still sticks; only the stacking is lost */ }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
