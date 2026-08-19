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

  // Retakes scale how long each item takes, so they scale the crew a date needs.
  // Must match project_retake_pct() / retake_factor() in includes/quota.php.
  //
  // Read from the FIELD, not once from the table, because the allowance is now
  // editable on this screen — a value captured at load would leave every figure
  // below describing the old percentage while the box showed the new one, which
  // is the worst kind of stale: it looks live.
  var retakeInput = document.getElementById('retake-pct');
  function retakePct() {
    if (retakeInput) {
      var v = parseFloat(retakeInput.value);
      if (!isNaN(v)) return Math.min(200, Math.max(0, v));   // the same cap PHP applies
    }
    return parseFloat(table.getAttribute('data-retake')) || 0;
  }
  function retakeFactor() { return 1 + (retakePct() / 100); }

  // NEVER new Date('2026-08-05') — that parses as UTC and reads back a day
  // earlier in a UTC-6 timezone. Building from parts gives local midnight.
  function parseDate(s) {
    if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
    var p = s.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  // The studio calendar, handed over by planning.php in data-holidays.
  //
  // ⚠ THIS SET IS WHY THE FUNCTION BELOW AGREES WITH THE SERVER. Without it the
  // live check counted every weekday, including the days the studio is closed,
  // so a window spanning a holiday showed more available days here than
  // working_days_between() would allow — "1 is enough" on a plan the scheduler
  // thinks is short. Always in the OPTIMISTIC direction, which the 2026-08-12
  // calendar entry names as the worst one for a deadline. (Fixed 2026-08-17.)
  var holidays = {};
  (function () {
    var raw = (table.getAttribute('data-holidays') || '').split(',');
    for (var i = 0; i < raw.length; i++) {
      var d = raw[i].trim();
      if (d) holidays[d] = true;
    }
  })();

  function ymd(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  // Mon–Fri MINUS the studio calendar, both ends INCLUSIVE — the platform-wide
  // rule, and the same one working_days_between() applies in PHP. These two must
  // move together; a screen that disagrees with the scheduler about how many
  // days there are is worse than one that shows nothing.
  function workingDays(fromStr, toStr) {
    var d = parseDate(fromStr), end = parseDate(toStr);
    if (!d || !end || end < d) return 0;
    var n = 0, guard = 0;
    while (d <= end && guard++ < 20000) {
      var dow = d.getDay();               // 0 = Sunday, 6 = Saturday
      if (dow !== 0 && dow !== 6 && !holidays[ymd(d)]) n++;
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

    // How long the step takes at these settings, as opposed to how long it has.
    // "Is that enough?" answers whether the people fit the date; this answers
    // how many days the work itself needs, which is the number you compare
    // against the gap between Starts and Deadline.
    var durationCell = row.querySelector('.duration');
    function sayDuration(html, cls) {
      if (!durationCell) return;
      durationCell.innerHTML = html;
      durationCell.className = 'duration' + (cls ? ' ' + cls : '');
    }

    function updateDuration(availableDays) {
      if (!durationCell) return;
      if (units <= 0) return sayDuration('<span class="muted">—</span>');

      // Retakes lengthen each item, exactly as in step_quota(). A duration that
      // ignored them would disagree with the verdict in the next column.
      var personDays = units * (perUnit > 0 ? perUnit : 1) * retakeFactor();

      if (head === null || head <= 0) {
        return sayDuration(
          '<span class="muted">' + fmtRate(personDays) + ' person-days</span>'
          + '<div class="muted small">set people to get a duration</div>'
        );
      }

      // What the allowance costs THIS step, in days. A percentage is easy to
      // nod at; the days it becomes are what somebody has to find room for.
      var bare = units * (perUnit > 0 ? perUnit : 1);
      var retakeDays = personDays - bare;
      var retakeCell = row.querySelector('.retake-days');
      if (retakeCell) {
        if (retakeDays <= 0.0001) {
          retakeCell.innerHTML = '<span class="muted">none</span>';
        } else {
          retakeCell.innerHTML = '<strong>' + fmtRate(retakeDays) + '</strong>'
            + '<div class="muted small">person-days'
            + (head > 0 ? ' · ' + fmtRate(retakeDays / head) + ' on the duration' : '')
            + '</div>';
        }
      }

      var elapsed = personDays / head;
      var whole = Math.max(1, Math.ceil(elapsed - 0.0001));
      var html = '<strong>' + whole + '</strong> ' + (whole === 1 ? 'day' : 'days');
      var cls = '';
      if (availableDays > 0) {
        if (whole > availableDays) {
          html += '<div class="warn-text small">' + (whole - availableDays)
                + ' more than the dates allow</div>';
          cls = 'verdict-short';
        } else {
          html += '<div class="muted small">' + (availableDays - whole) + ' days spare</div>';
        }
      }
      sayDuration(html, cls);
    }

    function say(html, cls) {
      cell.innerHTML = html;
      cell.className = 'verdict' + (cls ? ' ' + cls : '');
    }

    // Duration needs neither a deadline nor a valid window — work divided by
    // people is knowable as soon as both exist — so it is filled on every path
    // out of here, not just the one where everything is set.
    if (unitsRaw > 0 && units <= 0) {
      updateDuration(0);
      return say('<span class="muted">covers 0% — nothing to do</span>');
    }
    if (units <= 0) {
      updateDuration(0);
      return say('<span class="muted">nothing to schedule yet</span>');
    }
    if (!deadline) {
      updateDuration(0);
      return say('<span class="muted">set a deadline</span>');
    }

    var from = (start && start > today) ? start : today;
    if (deadline < from) {
      updateDuration(0);
      return say('<span class="warn-text">deadline has passed</span>');
    }

    var days = workingDays(from, deadline);
    if (days <= 0) {
      updateDuration(0);
      return say('<span class="warn-text">no working days left</span>');
    }

    updateDuration(days);

    var teamPerDay = units / days;
    var required = teamPerDay * (perUnit > 0 ? perUnit : 1) * retakeFactor();
    var needed = Math.ceil(required);

    // ---- the minimum, and how it compares with the studio ideal ------------
    //
    // Both are ITEMS PER PERSON PER DAY, which is the only comparison that
    // means anything: the ideal describes one artist working on one item, so
    // the team rate has to be divided by the people on it first.
    //
    // The minimum is arithmetic — work left, over working days left, over
    // people — and nothing on this screen can edit it. That is exactly what
    // makes it worth measuring the ideal against.
    // What the typed days-per-item actually means, said the other way round.
    // The field is days / item, so 0.25 is a quarter of a day each — which reads
    // as "four days" to most people until something says otherwise.
    var dpuHint = row.querySelector('.dpu-hint');
    if (dpuHint) {
      dpuHint.textContent = (perUnit > 0)
        ? '= ' + fmtRate(1 / perUnit) + ' ' + label + '/day'
        : '';
    }

    var minPerArtist = (head !== null && head > 0) ? (teamPerDay / head) : null;
    var minCell = row.querySelector('.minimum');
    if (minCell) {
      if (minPerArtist === null) {
        minCell.innerHTML = '<span class="muted">set people</span>';
      } else {
        minCell.innerHTML = '<strong>' + fmtRate(minPerArtist) + '</strong>'
          + '<div class="muted small">' + label + '/day each</div>';
      }
    }

    // The ideal is stored as DAYS PER ITEM; its pace is the reciprocal. Red when
    // the studio's pace cannot keep up with what the deadline demands — the plan
    // is then asking for more per person per day than the studio says the work
    // allows, and no amount of goodwill closes that. More people, more time, or
    // less work.
    var idealDays = parseFloat(row.getAttribute('data-ideal'));
    var idealCell = row.querySelector('.ideal-cell');
    if (idealCell) {
      idealCell.classList.remove('quota-short');
      if (!(idealDays > 0)) {
        idealCell.innerHTML = '<span class="muted">no standard</span>';
      } else {
        var idealPerDay = 1 / idealDays;
        // Written in EXACTLY the shape the Minimum cell uses — same formatter,
        // same label, same two lines — because the two columns exist to be read
        // against each other and any difference in presentation reads as a
        // difference in kind.
        idealCell.innerHTML = '<strong>' + fmtRate(idealPerDay) + '</strong>'
          + '<div class="muted small">' + label + '/day each</div>';
        if (minPerArtist !== null && idealPerDay < minPerArtist - 0.0001) {
          idealCell.classList.add('quota-short');
        }
      }
    }

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
    if (retakeFactor() > 1) {
      line2 += ' · includes ' + fmtRate(retakePct()) + '% retakes';
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

  // The allowance applies to every row, so editing it re-runs all of them
  // rather than the one that happens to be focused.
  if (retakeInput) {
    retakeInput.addEventListener('input', recalcAll);
    retakeInput.addEventListener('change', recalcAll);
  }

  recalcAll();
})();

// Import mapping: the "what would a new step apply to" picker is only meaningful
// when "create a step" is actually selected, so keep it out of sight otherwise.
// It also clears the needs-attention highlight once a row has been answered.
(function () {
  'use strict';

  var choices = document.querySelectorAll('select.step-choice[data-scope-for]');

  function sync(sel) {
    var scope = document.getElementById(sel.getAttribute('data-scope-for'));
    if (scope) scope.style.display = sel.value === 'new' ? '' : 'none';

    var row = sel.closest('tr');
    if (row) row.classList.toggle('has-problem', sel.value === '0');
  }

  for (var i = 0; i < choices.length; i++) {
    (function (sel) {
      sel.addEventListener('change', function () { sync(sel); });
      sync(sel);
    })(choices[i]);
  }
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
  // Optional: only present once migration 015 has added the column.
  var deptEl = document.getElementById('new-step-department');
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
    // Only fills a BLANK department: something already typed is a deliberate
    // choice and must not be overwritten by what the library happens to know.
    if (deptEl && match.department && !deptEl.value.trim()) {
      deptEl.value = match.department;
    }
  });
})();
