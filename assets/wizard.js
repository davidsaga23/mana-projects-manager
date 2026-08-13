// Drag-to-reorder for the wizard's pipeline step table.
//
// Only the handle cell is draggable, so text in the name field stays selectable.
// Dropping submits the form immediately: a reorder that sits unsaved in the page
// is exactly the kind of silent loss this app tries not to have. The ↑ ↓ buttons
// remain the keyboard path and the fallback when this script doesn't run.

(function () {
  'use strict';

  var tbody = document.getElementById('step-rows');
  var orderField = document.getElementById('step-order');
  var submitButton = document.getElementById('reorder-submit');
  if (!tbody || !orderField || !submitButton) return;

  var dragging = null;

  tbody.addEventListener('dragstart', function (e) {
    var handle = e.target.closest('.drag-handle[draggable]');
    if (!handle) return;
    dragging = handle.closest('tr');
    if (!dragging) return;
    dragging.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag unless some data is set.
    e.dataTransfer.setData('text/plain', dragging.getAttribute('data-id') || '');
  });

  tbody.addEventListener('dragover', function (e) {
    if (!dragging) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    var over = e.target.closest('tr');
    if (!over || over === dragging || over.parentNode !== tbody) return;

    // Past the halfway point of the hovered row, insert below it instead of above.
    var box = over.getBoundingClientRect();
    var below = (e.clientY - box.top) > box.height / 2;
    tbody.insertBefore(dragging, below ? over.nextSibling : over);
  });

  tbody.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!dragging) return;
    finishDrag();
    saveOrder();
  });

  // Covers dropping outside the table, or pressing Escape mid-drag.
  tbody.addEventListener('dragend', function () {
    if (dragging) finishDrag();
  });

  function finishDrag() {
    dragging.classList.remove('dragging');
    dragging = null;
  }

  function saveOrder() {
    var ids = [];
    var rows = tbody.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) {
      ids.push(rows[i].getAttribute('data-id'));
    }
    orderField.value = ids.join(',');
    submitButton.click();
  }
})();

// Live "is that enough?" check on the dates & headcount screen.
//
// Mirrors includes/quota.php exactly. If that maths ever changes, change it here
// too — a check that disagrees with the review screen is worse than no check.
(function () {
  'use strict';

  var table = document.getElementById('planning-table');
  if (!table) return;
  var today = table.getAttribute('data-today');

  // NEVER new Date('2026-08-05') — that parses as UTC and reads back a day
  // earlier in a UTC-6 timezone. Building from parts gives local midnight.
  function parseDate(s) {
    if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
    var p = s.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  // Mon–Fri between two dates, both ends INCLUSIVE — the platform-wide rule.
  function workingDays(fromStr, toStr) {
    var d = parseDate(fromStr), end = parseDate(toStr);
    if (!d || !end || end < d) return 0;
    var n = 0, guard = 0;
    while (d <= end && guard++ < 20000) {
      var dow = d.getDay();               // 0 = Sunday, 6 = Saturday
      if (dow !== 0 && dow !== 6) n++;
      d.setDate(d.getDate() + 1);
    }
    return n;
  }

  function fmtRate(v) {
    if (v >= 10) return v.toFixed(0);
    if (v >= 1) return v.toFixed(1);
    return v.toFixed(2);
  }

  function trimNum(v) {
    return parseFloat(v.toFixed(2)).toString();
  }

  function recalc(row) {
    var cell = row.querySelector('.verdict');
    if (!cell) return;

    var unitsRaw = parseFloat(row.getAttribute('data-units')) || 0;
    var label = row.getAttribute('data-label') || 'items';
    var estimated = row.getAttribute('data-estimated') === '1';
    // Found by field name rather than position — adding a column shouldn't
    // silently start reading the wrong value.
    function field(prefix) {
      return row.querySelector('input[name^="' + prefix + '["]');
    }
    var startEl = field('start_date'), deadlineEl = field('deadline');
    var perUnitEl = field('days_per_unit'), headEl = field('planned_headcount');
    var coverageEl = field('coverage_pct');

    var start = startEl ? startEl.value : '';
    var deadline = deadlineEl ? deadlineEl.value : '';
    var perUnit = parseFloat(perUnitEl ? perUnitEl.value : '') || 0;
    var headRaw = headEl ? headEl.value.trim() : '';
    var head = headRaw === '' ? null : (parseFloat(headRaw) || 0);

    // Coverage is applied here, not baked into data-units, so moving the slider
    // updates the answer without a round trip. Matches step_work_units() in PHP.
    var coverage = coverageEl ? (parseFloat(coverageEl.value) || 0) / 100 : 1;
    if (coverage < 0) coverage = 0;
    var units = unitsRaw * coverage;

    // Keep the Work column honest as coverage changes — it's the number every
    // figure to its right is derived from.
    var workCell = row.querySelector('.work-cell');
    if (workCell) {
      var workHtml = '<strong>' + fmtRate(units) + '</strong> <span class="muted">' + label + '</span>';
      if (coverage < 1) workHtml += '<div class="muted small">of ' + fmtRate(unitsRaw) + '</div>';
      if (estimated) workHtml += '<div><span class="est-tag">est.</span></div>';
      workCell.innerHTML = workHtml;
    }

    function say(html, cls) {
      cell.innerHTML = html;
      cell.className = 'verdict' + (cls ? ' ' + cls : '');
    }

    if (unitsRaw > 0 && units <= 0) return say('<span class="muted">covers 0% — nothing to do</span>');
    if (units <= 0)  return say('<span class="muted">nothing to schedule yet</span>');
    if (!deadline)   return say('<span class="muted">set a deadline</span>');

    var from = (start && start > today) ? start : today;
    if (deadline < from) return say('<span class="warn-text">deadline has passed</span>');

    var days = workingDays(from, deadline);
    if (days <= 0) return say('<span class="warn-text">no working days left</span>');

    var teamPerDay = units / days;
    var required = teamPerDay * (perUnit > 0 ? perUnit : 1);
    var needed = Math.ceil(required);

    var line1, cls = '';
    if (head === null || head <= 0) {
      line1 = 'needs about <strong>' + needed + '</strong> ' + (needed === 1 ? 'person' : 'people');
    } else if (required > head + 0.0001) {
      line1 = '<strong>' + needed + '</strong> needed, ' + trimNum(head) + ' planned';
      cls = 'verdict-short';
    } else {
      line1 = '<strong>' + trimNum(head) + '</strong> is enough (needs ' + fmtRate(required) + ')';
      cls = 'verdict-ok';
    }

    // The Work column already states the amount, so this line covers the rates.
    var line2 = fmtRate(teamPerDay) + ' ' + label + '/day across the team · '
              + days + ' working days';
    if (head !== null && head > 0) {
      line2 = fmtRate(teamPerDay / head) + ' ' + label + '/day each · ' + line2;
    }
    if (estimated) line2 += ' · from the pre-production estimate';

    say(line1 + '<br><span class="muted small">' + line2 + '</span>', cls);
  }

  function recalcAll() {
    var rows = table.querySelectorAll('tbody tr');
    for (var i = 0; i < rows.length; i++) recalc(rows[i]);
  }

  table.addEventListener('input', function (e) {
    var row = e.target.closest('tr');
    if (row) recalc(row);
  });
  table.addEventListener('change', function (e) {
    var row = e.target.closest('tr');
    if (row) recalc(row);
  });

  recalcAll();
})();

// Generic list filter, used by the pipeline, shots and assets screens.
//
// A <select data-filter-for="containerId" data-filter-attr="data-x"> hides any
// element inside that container carrying the attribute, unless its value matches.
//
// Hiding, never removing: every hidden row's inputs stay in the form, so saving
// while filtered can't drop what you can't currently see. That matters — a
// filter that silently narrowed a save would be the worst kind of bug here.
(function () {
  'use strict';

  var selects = document.querySelectorAll('select[data-filter-for]');

  function apply(sel) {
    var container = document.getElementById(sel.getAttribute('data-filter-for'));
    if (!container) return;

    var attr = sel.getAttribute('data-filter-attr') || 'data-filter-value';
    var noun = sel.getAttribute('data-filter-noun') || 'items';
    var items = container.querySelectorAll('[' + attr + ']');
    var want = sel.value;
    var shown = 0;

    for (var i = 0; i < items.length; i++) {
      var match = !want || items[i].getAttribute(attr) === want;
      items[i].style.display = match ? '' : 'none';
      if (match) shown++;
    }

    var note = document.getElementById(sel.getAttribute('data-filter-note') || '');
    if (note) {
      note.textContent = want
        ? 'Showing ' + shown + ' of ' + items.length + ' ' + noun + '. Saving still keeps every one.'
        : 'Filtering only changes what you see — saving still keeps everything.';
    }

    // Reordering a filtered list would post an order missing the hidden rows, so
    // dragging is switched off until the full list is back.
    var handles = container.querySelectorAll('.drag-handle');
    for (var j = 0; j < handles.length; j++) {
      if (want) handles[j].removeAttribute('draggable');
      else handles[j].setAttribute('draggable', 'true');
    }
  }

  for (var k = 0; k < selects.length; k++) {
    (function (sel) {
      sel.addEventListener('change', function () { apply(sel); });
    })(selects[k]);
  }
})();

// "Select all" in an asset group — retyping fifty pasted assets shouldn't mean
// fifty clicks. Each toggle names the container it controls.
(function () {
  'use strict';

  var toggles = document.querySelectorAll('[data-check-all]');
  for (var i = 0; i < toggles.length; i++) {
    toggles[i].addEventListener('change', function (e) {
      var group = document.getElementById(e.target.getAttribute('data-check-all'));
      if (!group) return;
      var boxes = group.querySelectorAll('input[name="sel[]"]');
      for (var j = 0; j < boxes.length; j++) boxes[j].checked = e.target.checked;
    });
  }
})();

// Picking a remembered step name fills in the settings it had last time.
// Typing a brand new name leaves the fields alone.
(function () {
  'use strict';

  var dataEl = document.getElementById('step-library-data');
  var nameEl = document.getElementById('new-step-name');
  var appliesEl = document.getElementById('new-step-applies-to');
  var secondsEl = document.getElementById('new-step-seconds');
  if (!dataEl || !nameEl || !appliesEl || !secondsEl) return;

  var library;
  try {
    library = JSON.parse(dataEl.textContent || '{}');
  } catch (err) {
    return;
  }

  nameEl.addEventListener('input', function () {
    var match = library[nameEl.value.trim()];
    if (!match) return;
    appliesEl.value = match.applies_to;
    secondsEl.checked = match.tracks_seconds === 1;
  });
})();
