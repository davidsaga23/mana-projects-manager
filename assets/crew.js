// Closing the slot popovers.
//
// <details> gives the fold for free and needs no JavaScript to open — which is
// why it is used — but it only closes by clicking its own summary again. On a
// panel that looks like a popover that is not discoverable: it looks like
// something you dismiss, so it should dismiss like one.
//
// Three ways out, because people reach for different ones: the ×, a click
// anywhere outside, and Escape. None of them is required for the page to work,
// so a browser without JavaScript still opens and closes by the button.
(function () {
  'use strict';

  function openPanels() {
    return document.querySelectorAll('details.slot-change[open]');
  }

  function closeAll(except) {
    Array.prototype.forEach.call(openPanels(), function (d) {
      if (d !== except) d.removeAttribute('open');
    });
  }

  // A click ANYWHERE that is not inside an open panel shuts it. Capture phase,
  // so it still fires when something inside stops propagation.
  document.addEventListener('click', function (ev) {
    var inside = ev.target.closest ? ev.target.closest('details.slot-change') : null;
    // The × sets its own panel closed; anything else inside is a control being
    // used and must not dismiss the thing it belongs to.
    if (ev.target.closest && ev.target.closest('.popover-close')) {
      var own = ev.target.closest('details.slot-change');
      if (own) own.removeAttribute('open');
      closeAll();
      return;
    }
    closeAll(inside);
  }, true);

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var open = openPanels();
    if (!open.length) return;
    closeAll();
    // Focus goes back to the button that opened it, or Escape leaves the reader
    // nowhere — the usual reason a keyboard user gets stranded in a dialog.
    var summary = open[0].querySelector('summary');
    if (summary) summary.focus();
  });

  // One at a time. Two open pickers overlapping each other is how somebody
  // saves the wrong row.
  document.addEventListener('toggle', function (ev) {
    var d = ev.target;
    if (!d.matches || !d.matches('details.slot-change')) return;
    if (d.open) closeAll(d);
  }, true);
}());
