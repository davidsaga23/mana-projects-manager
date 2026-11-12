// Days off calendar (days_off.php): drag across a person's days to add days
// off, click a day off to change or remove it. Loaded only for Production and
// admins; everything it does is an ordinary form POST the page also offers
// without it (the list and the "Add days off" form under the grid), so the
// server is where every rule lives, never here.
(function () {
  'use strict';
  var grid = document.getElementById('doGrid');
  var pop = document.getElementById('doPop');
  if (!grid || !pop) return;
  var form = document.getElementById('doPopForm');
  var title = document.getElementById('doPopTitle');
  var save = document.getElementById('doPopSave');
  var del = document.getElementById('doPopDelete');
  var f = form.elements;

  // ---- the pop-up ----------------------------------------------------------
  function openPop(opts, x, y) {
    f.action.value = opts.absence ? 'edit' : 'add';
    f.crew_id.value = opts.crew;
    f.absence_id.value = opts.absence || '';
    f.from_date.value = opts.from;
    f.to_date.value = opts.to;
    f.reason.value = opts.reason || '';
    title.textContent = (opts.absence ? 'Change days off · ' : 'Days off · ') + opts.name;
    save.textContent = opts.absence ? 'Save' : 'Add days off';
    del.hidden = !opts.absence;
    markReason();
    pop.hidden = false;
    // Beside the pointer, kept on screen.
    var w = pop.offsetWidth, h = pop.offsetHeight;
    var left = Math.min(Math.max(8, x + 12), window.innerWidth - w - 8);
    var top = y + 12 + h > window.innerHeight ? Math.max(8, y - h - 12) : y + 12;
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    f.reason.focus();
  }
  function closePop() { pop.hidden = true; clearPick(); }

  function markReason() {
    var v = f.reason.value.trim().toLowerCase();
    pop.querySelectorAll('[data-reason]').forEach(function (b) {
      b.classList.toggle('is-on', b.getAttribute('data-reason').toLowerCase() === v);
    });
  }
  pop.querySelectorAll('[data-reason]').forEach(function (b) {
    b.addEventListener('click', function () {
      f.reason.value = b.getAttribute('data-reason');
      markReason();
    });
  });
  f.reason.addEventListener('input', markReason);
  document.getElementById('doPopCancel').addEventListener('click', closePop);
  del.addEventListener('click', function () {
    if (!confirm('Remove these days off?')) return;
    f.action.value = 'delete';
    form.submit();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) closePop(); });
  document.addEventListener('mousedown', function (e) {
    if (!pop.hidden && !pop.contains(e.target) && !grid.contains(e.target)
        && !(e.target.closest && e.target.closest('[data-edit]'))) closePop();
  });

  // ---- dragging out a run of days on one person's row ----------------------
  var pick = null;   // {row, start, end} while the button is down

  function cellAt(x, y) {
    var el = document.elementFromPoint(x, y);
    return el && el.closest ? el.closest('td.do-cell') : null;
  }
  function clearPick() {
    grid.querySelectorAll('.is-picking').forEach(function (c) { c.classList.remove('is-picking'); });
  }
  function paintPick() {
    clearPick();
    var cells = pick.row.querySelectorAll('td.do-cell');
    var a = Math.min(pick.start, pick.end), b = Math.max(pick.start, pick.end);
    for (var i = a; i <= b; i++) cells[i].classList.add('is-picking');
  }
  function indexOf(cell) {
    return Array.prototype.indexOf.call(cell.parentNode.querySelectorAll('td.do-cell'), cell);
  }

  grid.addEventListener('pointerdown', function (e) {
    if (e.button !== 0) return;
    var cell = e.target.closest('td.do-cell');
    if (!cell) return;
    var row = cell.parentNode;
    e.preventDefault();
    if (cell.hasAttribute('data-absence')) {
      // An existing day off: change or remove it, with its real dates even
      // when it runs into the next month.
      openPop({
        crew: row.getAttribute('data-crew'), name: row.getAttribute('data-name'),
        absence: cell.getAttribute('data-absence'),
        from: cell.getAttribute('data-from'), to: cell.getAttribute('data-to'),
        reason: cell.getAttribute('data-reason')
      }, e.clientX, e.clientY);
      return;
    }
    pop.hidden = true;
    pick = { row: row, start: indexOf(cell), end: indexOf(cell) };
    paintPick();
  });
  document.addEventListener('pointermove', function (e) {
    if (!pick) return;
    var cell = cellAt(e.clientX, e.clientY);
    // Only along the row it started on: a day off is one person's.
    if (!cell || cell.parentNode !== pick.row) return;
    var i = indexOf(cell);
    if (i !== pick.end) { pick.end = i; paintPick(); }
  });
  document.addEventListener('pointerup', function (e) {
    if (!pick) return;
    var cells = pick.row.querySelectorAll('td.do-cell');
    var a = Math.min(pick.start, pick.end), b = Math.max(pick.start, pick.end);
    var row = pick.row;
    pick = null;
    openPop({
      crew: row.getAttribute('data-crew'), name: row.getAttribute('data-name'),
      from: cells[a].getAttribute('data-day'), to: cells[b].getAttribute('data-day')
    }, e.clientX, e.clientY);
  });

  // ---- "Change" in the list under the grid ---------------------------------
  document.querySelectorAll('[data-edit]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var r = b.getBoundingClientRect();
      openPop({
        crew: b.getAttribute('data-crew'), name: b.getAttribute('data-name'),
        absence: b.getAttribute('data-edit'),
        from: b.getAttribute('data-from'), to: b.getAttribute('data-to'),
        reason: b.getAttribute('data-reason')
      }, r.left - 300, r.bottom);
    });
  });
})();
