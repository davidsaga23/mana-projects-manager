// The lists behind the Overview's progress counts (David, 2026-11-16: "when the
// cursor clicks those numbers we can see the list of shots or assets that that
// number is counting").
//
// A count is a button (.psi-count). Clicking it opens one row under its line of
// the table and fills it from project_step_items.php, which renders the list in
// PHP; clicking it again, or the ×, closes it. One list open at a time, so the
// table never grows into a wall. The items in it are .board-open-task buttons,
// which assets/board-screen.js opens in Spell's panel on its own.
(function () {
  'use strict';

  var openBtn = null;   // the count whose list is showing
  var openRow = null;   // the <tr> holding it

  function shut() {
    if (openRow && openRow.parentNode) openRow.parentNode.removeChild(openRow);
    if (openBtn) {
      openBtn.setAttribute('aria-expanded', 'false');
      openBtn.classList.remove('is-open');
    }
    openRow = null;
    openBtn = null;
  }

  function show(btn) {
    var tr = btn.closest('tr');
    if (!tr) return;
    shut();
    var cols = tr.children.length || 1;
    var row = document.createElement('tr');
    row.className = 'psi-row';
    var td = document.createElement('td');
    td.colSpan = cols;
    var box = document.createElement('div');
    box.className = 'psi-box';
    box.setAttribute('aria-live', 'polite');
    box.textContent = 'Loading…';
    td.appendChild(box);
    row.appendChild(td);
    tr.parentNode.insertBefore(row, tr.nextSibling);
    openRow = row;
    openBtn = btn;
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('is-open');

    var url = 'project_step_items.php?project=' + encodeURIComponent(btn.getAttribute('data-project'))
            + '&step=' + encodeURIComponent(btn.getAttribute('data-step'))
            + '&what=' + encodeURIComponent(btn.getAttribute('data-what'));
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        if (openRow !== row) return;   // another count was clicked meanwhile
        box.innerHTML = html;
      })
      .catch(function () {
        if (openRow !== row) return;
        box.innerHTML = '<p class="err">That list did not load. Check your connection and try again.</p>';
      });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('.psi-count');
    if (btn) {
      if (btn === openBtn) shut(); else show(btn);
      return;
    }
    if (e.target.closest('.psi-close')) shut();
  });
}());
