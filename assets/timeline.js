// Timeline: two Frappe Gantt charts, filters, zoom, click-to-edit, PNG/PDF.
//
// Ported from STFC's version, which is the one people actually used. Its two
// hard-won date rules are carried over verbatim and are the first thing to
// re-read if a bar ever looks a day out:
//
//   1. Frappe's `end` is the INCLUSIVE last day — it extends a date-only value
//      to 23:59:59 itself. This platform stores end dates inclusive too, so
//      nothing is added or subtracted at this boundary. STFC had the PHP adding
//      a day and Frappe adding another; two compensating errors that hid each
//      other for weeks.
//   2. Never toISOString(). It converts to UTC first, which reads back a day
//      earlier in a UTC−6 timezone. fmtDate() below builds from local parts.

(function () {
  'use strict';
  if (!window.DETAILED_TASKS) return;

  // Zoom is a ladder of (view mode, column width) rather than view mode alone.
  // Frappe only has four sensible modes and jumping Week → Month → Year moves
  // the scale by roughly 4× a click, which overshoots what you were looking at.
  // Varying the column width between mode changes turns that into eight gentle
  // steps. Ordered widest span (most zoomed OUT) to narrowest.
  var ZOOM = [
    { mode: 'Year',        w: 120, label: 'Year' },
    { mode: 'Month',       w: 120, label: 'Month' },
    { mode: 'Week',        w: 60,  label: 'Week' },
    { mode: 'Week',        w: 100, label: 'Week' },
    { mode: 'Week',        w: 150, label: 'Week' },
    { mode: 'Day',         w: 24,  label: 'Day' },
    { mode: 'Day',         w: 38,  label: 'Day' },
    { mode: 'Half Day',    w: 38,  label: 'Half day' }
  ];
  // --- remembering the zoom ---------------------------------------------------
  //
  // Kept in localStorage rather than the database: this is a preference about
  // one person's screen, not a fact about the show. Two people looking at the
  // same project should not be changing each other's view, and it needs no
  // round trip to apply.
  //
  // Keyed PER PROJECT as well as per chart, because the right zoom follows the
  // length of the thing: a six-week short reads at Day, a two-year series does
  // not. Coming back to a project should restore the view you left it in.
  //
  // Every access is wrapped: localStorage throws outright in some privacy modes
  // rather than returning null, and a view preference must never be the reason
  // the timeline fails to draw.
  function zoomKey(chart) {
    return 'anima.tl.zoom.' + (window.TL_PROJECT || 0) + '.' + chart;
  }
  function loadZoom(chart, fallback) {
    try {
      var v = window.localStorage.getItem(zoomKey(chart));
      if (v === null) return fallback;
      var n = parseInt(v, 10);
      // Clamped, not trusted: the ladder can gain or lose a rung between
      // releases and a stored 8 would then be off the end of it.
      return (isNaN(n) || n < 0 || n >= ZOOM.length) ? fallback : n;
    } catch (e) { return fallback; }
  }
  function saveZoom(chart, idx) {
    try { window.localStorage.setItem(zoomKey(chart), String(idx)); } catch (e) { /* ignore */ }
  }

  var zoomIdx = loadZoom('detailed', 3);         // Week, comfortable default
  var generalZoomIdx = loadZoom('general', 2);
  var detailedGantt = null;
  var generalGantt = null;

  var $ = function (id) { return document.getElementById(id); };

  /**
   * Apply a zoom level's column width.
   *
   * Frappe recomputes column_width from the view mode inside change_view_mode(),
   * so it cannot simply be passed in the constructor — it gets overwritten. It
   * CAN be set afterwards and re-rendered, which is what this does. Guarded:
   * if a future Frappe version drops render(), the chart still draws, just at
   * the library's own width.
   */
  function applyWidth(g, w) {
    if (!g || !g.options || typeof g.render !== 'function') return;
    try {
      g.options.column_width = w;
      g.render();
    } catch (e) { /* leave it at the default width */ }
  }

  /**
   * A "today" line, drawn ourselves.
   *
   * Frappe only paints its today-highlight band in some view modes, so at Month
   * or Year the one reference point everybody looks for silently disappears.
   * The x maths here is Frappe's own: hours from the chart's start, divided by
   * the step, times the column width.
   */
  function addTodayLine(g, container) {
    if (!g || !g.gantt_start || !g.options) return;
    var svg = container.querySelector('svg.gantt');
    if (!svg) return;
    svg.querySelectorAll('.tl-today').forEach(function (n) { n.remove(); });

    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var hours = (today - g.gantt_start) / 3600000;
    if (hours < 0) return;                       // today is before the chart starts
    var x = (hours / g.options.step) * g.options.column_width;

    var h = svg.getAttribute('height') || svg.getBoundingClientRect().height;
    var head = svg.querySelector('.grid-header');
    // Starts BELOW the header rather than at zero. Running it to the top would
    // put a red line through the month labels — and since the header now floats
    // above everything while scrolling, the line would be hidden there anyway.
    var top = head ? (parseFloat(head.getAttribute('height')) || 60) : 0;
    var ns = 'http://www.w3.org/2000/svg';

    var line = document.createElementNS(ns, 'line');
    line.setAttribute('class', 'tl-today');
    line.setAttribute('x1', x); line.setAttribute('x2', x);
    line.setAttribute('y1', top); line.setAttribute('y2', h);
    svg.appendChild(line);

    var text = document.createElementNS(ns, 'text');
    text.setAttribute('class', 'tl-today-label');
    text.setAttribute('x', x + 4);
    text.setAttribute('y', top + 12);
    text.textContent = 'today';
    svg.appendChild(text);
  }

  // --- the calendar header: weekends, and what day each column is --------------
  //
  // A row of numbers does not tell you which of them are Saturdays, and on an
  // animation schedule that is the first thing you want: whether a bar that
  // "takes five days" is really crossing a weekend and landing on the Tuesday.
  //
  // Both only make sense per-day, so both are skipped in Week, Month and Year
  // view where a column is not a day. The letters are skipped again on a narrow
  // column, where M T W T F S S would simply overlap into a smear.
  //
  // Weekend shading only. Studio holidays are known -- the wizard collects them
  // -- but they are not on this page yet, and shading only some non-working days
  // would be worse than shading none: it would read as "these are the days off".
  function decorateCalendar(g, container) {
    if (!g || !g.dates || !g.options) return;
    var svg = container.querySelector('svg.gantt');
    if (!svg) return;
    svg.querySelectorAll('.tl-weekend, .tl-dow').forEach(function (n) { n.remove(); });

    var mode = g.options.view_mode;
    if (mode !== 'Day' && mode !== 'Half Day') return;

    var ns   = 'http://www.w3.org/2000/svg';
    var cw   = g.options.column_width;
    var head = svg.querySelector('.grid-header');
    var top  = head ? (parseFloat(head.getAttribute('height')) || 60) : 0;
    var bg   = svg.querySelector('.grid-background');
    var h    = bg ? parseFloat(bg.getAttribute('height'))
                  : parseFloat(svg.getAttribute('height') || 0);

    // Into the grid group, so the shading sits over the row stripes and UNDER
    // the bars and arrows, which are drawn in later groups. Appended to the svg
    // instead it would paint over the work.
    var grid = svg.querySelector('.grid') || svg;
    var dateLayer = svg.querySelector('.date') || svg;

    // Where Frappe put the day numbers, so the letters sit directly above them
    // rather than at a height guessed from the header.
    var lower = svg.querySelector('.lower-text');
    var ly    = lower ? parseFloat(lower.getAttribute('y')) : top - 8;
    var showLetters = cw >= 20;

    var LETTERS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    g.dates.forEach(function (d, i) {
      var dow = d.getDay();
      var x = i * cw;

      if (dow === 0 || dow === 6) {
        var r = document.createElementNS(ns, 'rect');
        r.setAttribute('class', 'tl-weekend');
        r.setAttribute('x', x);
        r.setAttribute('y', top);
        r.setAttribute('width', cw);
        r.setAttribute('height', Math.max(0, h - top));
        grid.appendChild(r);
      }

      // In Half Day view every second column is noon; the letter belongs on the
      // one that starts the day, not on both.
      if (!showLetters || d.getHours() !== 0) return;
      var t = document.createElementNS(ns, 'text');
      t.setAttribute('class', 'tl-dow' + (dow === 0 || dow === 6 ? ' tl-dow-end' : ''));
      t.setAttribute('x', x + cw / 2);
      t.setAttribute('y', ly - 11);
      t.textContent = LETTERS[dow];
      // INTO THE DATE LAYER, not onto the svg. stickyHeader() runs after this
      // and MOVES the opaque .grid-header rect and the .date group into a
      // tl-sticky group appended last, so anything left on the svg root ends up
      // painted over by that header — which is exactly what happened to these
      // letters, and the same reason the today line starts below the header.
      //
      // In the date layer they travel with it, so they also stay put while the
      // chart scrolls, which is what you want from a column heading anyway.
      dateLayer.appendChild(t);
    });
  }

  // --- milestones -------------------------------------------------------------
  //
  // Client reviews, deliveries, locks: dates the plan has to be ready for. Drawn
  // as a line DOWN the chart rather than a bar on it, because the useful question
  // about a review date is which bars cross it.
  //
  // Same geometry as the today line above, on BOTH charts -- a review date is as
  // relevant to a shot as it is to a department.
  function addMilestones(g, container) {
    if (!g || !g.gantt_start || !g.options) return;
    if (!window.MILESTONES || !window.MILESTONES.length) return;
    var svg = container.querySelector('svg.gantt');
    if (!svg) return;
    svg.querySelectorAll('.tl-ms-g').forEach(function (n) { n.remove(); });

    var h    = parseFloat(svg.getAttribute('height')) || svg.getBoundingClientRect().height;
    var head = svg.querySelector('.grid-header');
    var top  = head ? (parseFloat(head.getAttribute('height')) || 60) : 0;
    var ns   = 'http://www.w3.org/2000/svg';
    var cw   = g.options.column_width;
    var step = g.options.step;

    // x <-> date, both directions. The forward one is the today line's; the
    // inverse is what a drag needs, and it SNAPS TO A WHOLE DAY -- in Month or
    // Year view a column is not a day, so without rounding a milestone would
    // land on an hour nobody chose.
    function xOf(d) {
      return (((d - g.gantt_start) / 3600000) / step) * cw;
    }
    function dayAt(x) {
      var days = Math.round(((x / cw) * step) / 24);
      var d = new Date(g.gantt_start.getFullYear(), g.gantt_start.getMonth(),
                       g.gantt_start.getDate() + days);
      return d;
    }
    function iso(d) {
      return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    // STACKED SO THE LABELS STOP COLLIDING. Two reviews four days apart wrote
    // their names over each other into an unreadable smear.
    //
    // Automatic rather than a position you have to set: the only thing a manual
    // up/down would decide is which of two labels sits higher, and that is a
    // question the chart can answer for itself every time the dates change --
    // whereas a stored row would have to be re-tidied by hand after every drag.
    //
    // Each marker takes the highest tier whose last label has already ended
    // before this one begins, so a lone milestone stays at the top and only a
    // genuine overlap pushes anything down. Widths are MEASURED, not guessed
    // from the character count: "Animatic review" and "WWW" do not occupy the
    // same space in a proportional font.
    var tierEnd = [];               // tier -> x where its last label finished
    var gapEnd  = {};               // step row -> x where its last GAP label ended
    var TIER_H  = 20;

    // WHERE A LINKED MILESTONE SITS: on the row of the step it reviews.
    //
    // Read off the rendered bars rather than recomputed from bar_height and
    // padding, so it stays right if either changes. On the DETAILED chart the
    // bar ids are schedule rows, not steps, so nothing matches and every
    // milestone falls back to stacking at the top — which is correct there,
    // since a step has no single row on that chart.
    var rowY = {}, rowBar = {};
    (g.bars || []).forEach(function (b) {
      var id = b && b.task ? b.task.id : null;
      var el = b && b.$bar ? b.$bar : null;
      if (!id || !el) return;
      rowY[String(id)] = parseFloat(el.getAttribute('y'));
      // Kept so a label can tell whether it is about to land ON this bar, where
      // the bar's own name already is.
      rowBar[String(id)] = {
        x: parseFloat(el.getAttribute('x')),
        y: parseFloat(el.getAttribute('y')),
        w: parseFloat(el.getAttribute('width')),
        h: parseFloat(el.getAttribute('height'))
      };
    });

    // Date order, so "the previous label" means the one to the left. The array
    // arrives sorted from PHP, but a drag rewrites a date in place.
    var msSorted = window.MILESTONES.slice().sort(function (a, b) {
      return String(a.date) < String(b.date) ? -1 : (String(a.date) > String(b.date) ? 1 : 0);
    });

    msSorted.forEach(function (m) {
      var p = String(m.date).split('-');
      if (p.length !== 3) return;
      var d = new Date(+p[0], +p[1] - 1, +p[2]);
      d.setHours(0, 0, 0, 0);
      if (d < g.gantt_start) return;                 // before the chart starts
      var x = xOf(d);

      var grp = document.createElementNS(ns, 'g');
      grp.setAttribute('class', 'tl-ms-g');
      grp.setAttribute('data-id', m.id);

      // A WIDE INVISIBLE HANDLE over the whole line, not just the diamond.
      // The diamond sits near the top and scrolls out of view on a long chart;
      // the line is what stays on screen, so the line is what you grab.
      var hit = document.createElementNS(ns, 'rect');
      hit.setAttribute('class', 'tl-ms-hit');
      hit.setAttribute('x', x - 7); hit.setAttribute('y', top);
      hit.setAttribute('width', 14);
      hit.setAttribute('height', Math.max(0, h - top));
      grp.appendChild(hit);

      var line = document.createElementNS(ns, 'line');
      line.setAttribute('class', 'tl-ms');
      line.setAttribute('x1', x); line.setAttribute('x2', x);
      line.setAttribute('y1', top); line.setAttribute('y2', h);
      grp.appendChild(line);

      // Bigger than it was: 20px across rather than 10, so it reads as a marker
      // you can take hold of rather than a tick you might have imagined.
      var dia = document.createElementNS(ns, 'polygon');
      dia.setAttribute('class', 'tl-ms tl-ms-dia');
      grp.appendChild(dia);

      var text = document.createElementNS(ns, 'text');
      text.setAttribute('class', 'tl-ms-label');
      text.setAttribute('x', x + 14);
      text.textContent = m.name;
      grp.appendChild(text);

      var title = document.createElementNS(ns, 'title');
      title.textContent = m.name + ' · ' + m.date
                        + (m.step_name ? ' · reviews ' + m.step_name : '')
                        + (m.early ? ' · WARNING: ' + m.step_name + ' does not finish until '
                                     + m.step_end : '')
                        + (m.notes ? ' · ' + m.notes : '')
                        + (window.TL_CAN_EDIT ? ' · drag to move, double-click to edit' : '');
      grp.appendChild(title);
      svg.appendChild(grp);

      // Measured only once it is in the document — getComputedTextLength()
      // returns 0 on a detached node.
      var w = 0;
      try { w = text.getComputedTextLength(); } catch (e) { w = m.name.length * 6; }

      // On its step's row when it has one and that step is drawn; stacked at
      // the top when it is a whole-project date, or when the step has no dates
      // and therefore no bar to sit beside.
      var ty, labelY, onRow = m.step_id ? rowY['step-' + m.step_id] : undefined;
      if (onRow !== undefined && !isNaN(onRow)) {
        // Centred on the bar rather than above it, so the marker reads as
        // belonging to that row and not to the one above.
        ty = onRow - 3;
        grp.classList.add('on-row');

        // THE LABEL STEPS OUT OF THE BAR'S WAY, and only when it has to.
        //
        // A review at the END of a step -- which most are -- has clear space to
        // its right and stays beside its diamond. One landing mid-bar would sit
        // on top of the bar's own name: "Layout review I" written across
        // "Layout · Environment assets", two labels in one place.
        //
        // So that one alone drops into the gap beneath the bar. The diamond
        // stays put, so which row it belongs to is never in doubt; only the
        // name moves, by about the height of one line.
        //
        // Conditional rather than always-below, because moving every label down
        // would separate the common case from its marker for no gain.
        var bar = rowBar['step-' + m.step_id];
        if (bar) {
          var lx = x + 14, lr = lx + w;
          var key = 'step-' + m.step_id;
          if (lx < bar.x + bar.w && lr > bar.x) {
            // The strip under a bar is one line tall — Frappe's row padding —
            // so a second label cannot simply go below the first. Where two
            // would collide down there, the later one stays beside its diamond
            // instead: over the bar, but readable on its white outline, and
            // one label over a bar beats two written across each other.
            if ((gapEnd[key] || -Infinity) < lx - 4) {
              labelY = bar.y + bar.h + 11;
              gapEnd[key] = lr + 8;
            }
          }
        }
      } else {
        var tier = 0;
        while (tier < tierEnd.length && tierEnd[tier] > x - 12) tier++;
        tierEnd[tier] = x + 14 + w + 8;       // 8px of air before the next one
        ty = top + tier * TIER_H;
      }

      // A review dated before the step it reviews has finished: the thing being
      // reviewed will not exist yet. Same kind of warning as a red dependency
      // arrow — said, not enforced.
      if (m.early) grp.classList.add('is-early');
      dia.setAttribute('points', [
        x + ',' + (ty + 1), (x + 10) + ',' + (ty + 11),
        x + ',' + (ty + 21), (x - 10) + ',' + (ty + 11)
      ].join(' '));
      text.setAttribute('y', labelY !== undefined ? labelY : ty + 15);
      if (labelY !== undefined) grp.classList.add('label-below');

      if (!window.TL_CAN_EDIT) return;
      grp.classList.add('is-draggable');

      grp.addEventListener('dblclick', function (ev) {
        ev.preventDefault();
        openMsEditor(m);
      });

      // --- drag ---------------------------------------------------------
      // Snapped to a day and previewed by translating the whole group, so the
      // line, diamond and label move together and nothing is rebuilt per pixel.
      grp.addEventListener('mousedown', function (ev) {
        if (ev.button !== 0) return;
        ev.preventDefault();
        var startX = ev.clientX, moved = 0, snapped = 0;
        grp.classList.add('is-dragging');

        function onMove(e) {
          moved = e.clientX - startX;
          // Snap as it goes, so what you see is where it will land.
          snapped = Math.round(moved / (cw * 24 / step)) * (cw * 24 / step);
          grp.setAttribute('transform', 'translate(' + snapped + ',0)');
        }
        function onUp() {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          grp.classList.remove('is-dragging');
          if (!snapped) { grp.removeAttribute('transform'); return; }

          var newDate = iso(dayAt(x + snapped));
          saveMilestoneDate(m.id, newDate).then(function () {
            m.date = newDate;                 // keep the in-memory copy honest
            renderGeneral();
            renderDetailed();
          }).catch(function () {
            grp.removeAttribute('transform');
          });
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
  }

  function openMsEditor(m) {
    var box = $('msEditor');
    if (!box) return;
    $('msName').value  = m.name  || '';
    $('msDate').value  = m.date  || '';
    $('msNotes').value = m.notes || '';
    // Absent until migration 029 has run, so never assumed.
    var sel = $('msStep');
    if (sel) sel.value = m.step_id ? String(m.step_id) : '';
    $('msSave').value   = 'edit:' + m.id;
    $('msRemove').value = 'remove:' + m.id;
    box.style.display = 'flex';
    $('msName').focus();
  }
  window.closeMsEditor = function () {
    var box = $('msEditor');
    if (box) box.style.display = 'none';
  };

  // The Edit buttons in the panel below the chart open the SAME modal, so there
  // is one editor rather than two that could disagree. Delegated from the
  // document because the panel is ordinary server-rendered HTML that never
  // re-renders — and looked up by id, so a milestone named O'Brien needs no
  // escaping into an inline handler.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.ms-edit') : null;
    if (!btn) return;
    var id = String(btn.getAttribute('data-ms'));
    var m = (window.MILESTONES || []).filter(function (x) {
      return String(x.id) === id;
    })[0];
    if (m) openMsEditor(m);
  });

  function saveMilestoneDate(id, date) {
    var body = new URLSearchParams();
    body.append('id', id);
    body.append('date', date);
    return fetch('schedule_api.php?action=milestone_date&project=' + window.TL_PROJECT,
                 { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { alert(d.error || 'Save failed.'); throw new Error(d.error || 'failed'); }
        return d;
      })
      .catch(function (err) {
        if (err instanceof SyntaxError) alert('Save failed, try reloading the page.');
        throw err;
      });
  }

  // --- dependencies on the general chart --------------------------------------
  //
  // DRAWN, NOT ENFORCED. The arrows come from the workflow graph so the shape of
  // the pipeline is visible while planning; a violated one turns red and the bar
  // stays exactly where it was dropped. David plans the general timeline to the
  // dates and accepts that a dependency-respecting schedule may run longer
  // (2026-08-31) -- so this is a warning, never a wall.

  function generalById(id) {
    if (!window.GENERAL_TASKS) return null;
    for (var i = 0; i < window.GENERAL_TASKS.length; i++) {
      if (String(window.GENERAL_TASKS[i].id) === String(id)) return window.GENERAL_TASKS[i];
    }
    return null;
  }

  /** Names of the steps this one is planned to start before the finish of. */
  function violationsFor(id) {
    var t = generalById(id);
    if (!t || !t.dependencies) return [];
    var out = [];
    t.dependencies.split(',').forEach(function (depId) {
      depId = depId.trim();
      if (!depId) return;
      var d = generalById(depId);
      // Compared as YYYY-MM-DD strings, which sort correctly as text and avoid
      // constructing Date objects entirely -- see rule 2 at the top of this file.
      if (d && t.start <= d.end) out.push(String(d.name).split(' · ')[0]);
    });
    return out;
  }

  /**
   * Colour the arrows. Frappe 0.6.1 keeps them on gantt.arrows, each with the
   * two tasks it joins and its own <path>. Reading a library's internals is a
   * liability, so every step of it is guarded: a version that renames this
   * simply draws no red rather than throwing and taking the chart with it.
   */
  function paintDependencies() {
    if (!generalGantt || !generalGantt.arrows) return;
    generalGantt.arrows.forEach(function (a) {
      if (!a || !a.element || !a.from_task || !a.to_task) return;
      var from = a.from_task.task || a.from_task;
      var to   = a.to_task.task   || a.to_task;
      if (!from || !to) return;
      // from = the dependency, to = the step that waits for it.
      var f = generalById(from.id), t = generalById(to.id);
      var broken = !!(f && t && t.start <= f.end);
      a.element.classList.toggle('dep-broken', broken);
    });
  }

  var stepSaveTimer = null;
  /** Says what was written, in the words the server used, then fades. */
  function stepSaved(msg) {
    var el = $('stepSaveNote');
    if (!el) return;
    el.textContent = 'Saved, ' + msg;
    el.hidden = false;
    clearTimeout(stepSaveTimer);
    stepSaveTimer = setTimeout(function () { el.hidden = true; }, 4000);
  }

  function saveStepDates(stepId, start, end) {
    var body = new URLSearchParams();
    body.append('step_id', stepId);
    body.append('start_date', start);
    body.append('end_date', end);
    return fetch('schedule_api.php?action=step_dates&project=' + window.TL_PROJECT,
                 { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { alert(d.error || 'Save failed.'); throw new Error(d.error || 'failed'); }
        return d;
      })
      .catch(function (err) {
        if (err instanceof SyntaxError) alert('Save failed, try reloading the page.');
        throw err;
      });
  }

  // --- general chart ---------------------------------------------------------
  function renderGeneral() {
    var el = $('generalGantt');
    if (!el || !window.GENERAL_TASKS || !window.GENERAL_TASKS.length) return;
    var z = ZOOM[generalZoomIdx];
    if ($('generalZoomLabel')) $('generalZoomLabel').textContent = z.label;
    el.innerHTML = '';
    generalGantt = new Gantt(el, window.GENERAL_TASKS, {
      view_mode: z.mode,
      bar_height: 22,
      bar_corner_radius: 3,
      language: 'en',
      popup_trigger: 'mouseover',
      custom_popup_html: function (t) {
        var src = generalById(t.id) || {};
        var late = src.late ? '<br><span style="color:#8a6d1a">past the delivery date of '
                            + src.delivery + '</span>' : '';
        var brk = violationsFor(t.id);
        var dep = brk.length
          ? '<br><span style="color:#b02a37">starts before ' + escapeHtml(brk.join(', '))
            + ' finishes</span>'
          : '';
        return '<div class="tl-popup"><strong>' + escapeHtml(t.name) + '</strong><br>'
             + t.start + ' → ' + t.end + late + dep + '</div>';
      },
      // THE BAR IS THE PLAN. These are pipeline_steps.start_date / deadline --
      // the same two fields Dates & headcount edits -- so dragging here edits
      // that screen graphically. Nothing is checked against the dependency
      // arrows: they are drawn as a warning, and a violated one turns red
      // rather than snapping the bar back (David, 2026-08-31).
      on_date_change: function (task, start, end) {
        if (!window.TL_CAN_EDIT) { renderGeneral(); return; }
        var src = generalById(task.id);
        if (!src) return;
        var s = fmtDate(start), e = fmtDate(end);
        var wasStart = src.start, wasEnd = src.end;
        // Optimistic, so the arrows recolour under the cursor rather than after
        // the round trip; rolled back below if the save is refused.
        src.start = s; src.end = e;
        paintDependencies();
        saveStepDates(src.step_id, s, e).then(function (d) {
          stepSaved(d.note || 'Saved.');
        }).catch(function () {
          src.start = wasStart; src.end = wasEnd;
          renderGeneral();
        });
      }
    });
    applyWidth(generalGantt, z.w);
    setTimeout(function () {
      paintBars(el, window.GENERAL_TASKS);
      paintDependencies();
      addTodayLine(generalGantt, el);
      addMilestones(generalGantt, el);
      decorateCalendar(generalGantt, el);
      // This chart scrolls too — nineteen steps overflow its height — so its
      // header has to float as well, or the months scroll away and the bars
      // below have nothing to be read against.
      stickyHeader(el);
    }, 60);
  }

  window.zoomGeneral = function (delta) {
    generalZoomIdx = Math.max(0, Math.min(ZOOM.length - 1, generalZoomIdx + delta));
    saveZoom('general', generalZoomIdx);
    renderGeneral();
  };

  // --- detailed chart --------------------------------------------------------
  function selectedStepIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.step-cb:checked'))
      .map(function (cb) { return cb.value; });
  }

  function filtered() {
    var epEl = $('episodeFilter');
    var ep = epEl ? epEl.value : 'all';
    var steps = selectedStepIds();
    var hide = $('hideCompleted') && $('hideCompleted').checked;
    var out = window.DETAILED_TASKS.filter(function (t) {
      if (ep !== 'all' && t.episode !== ep) return false;
      if (steps.length && steps.indexOf(String(t.step_id)) === -1) return false;
      if (hide && t.is_approved) return false;
      return true;
    });

    // Grouping is just ordering — Frappe draws a flat list, and rows sharing a
    // key end up adjacent, which is all "group by asset" needs to mean here.
    var mode = $('groupBy') ? $('groupBy').value : 'date';
    var cmp = {
      // Every task on one asset or shot together, in pipeline order: what is
      // left to do on Harry Kane, rather than what happens next week.
      item: function (a, b) {
        return a.item_label.localeCompare(b.item_label)
            || a.step_order - b.step_order
            || a.start.localeCompare(b.start);
      },
      step: function (a, b) {
        return a.step_order - b.step_order
            || a.start.localeCompare(b.start)
            || a.item_label.localeCompare(b.item_label);
      },
      date: function (a, b) {
        return a.start.localeCompare(b.start)
            || a.step_order - b.step_order
            || a.item_label.localeCompare(b.item_label);
      }
    }[mode] || null;
    if (cmp) out = out.slice().sort(cmp);
    return out;
  }

  function renderDetailed() {
    var el = $('detailedGantt');
    if (!el) return;
    var z = ZOOM[zoomIdx];
    $('zoomLabel').textContent = z.label;

    var tasks = filtered();
    if (!tasks.length) {
      el.innerHTML = '<p class="muted" style="padding:20px">Nothing matches the current filters.</p>';
      return;
    }
    el.innerHTML = '';

    detailedGantt = new Gantt(el, tasks, {
      view_mode: z.mode,
      bar_height: 18,
      bar_corner_radius: 2,
      language: 'en',
      popup_trigger: 'mouseover',
      custom_popup_html: function (t) {
        var o = byId(t.id) || {};
        return '<div class="tl-popup"><strong>' + escapeHtml(t.name) + '</strong>'
             + (o.is_manual ? ' · locked' : '') + '<br>' + t.start + ' → ' + t.end
             + '<br><span class="muted">' + escapeHtml(o.crew_name || 'nobody assigned')
             + '</span></div>';
      },
      on_click: function (task) { if (window.TL_CAN_EDIT) openEditor(task); },
      on_date_change: function (task, start, end) {
        if (!window.TL_CAN_EDIT) { renderDetailed(); return; }
        // See rule 1 at the top: `end` already IS the inclusive last day.
        var s = fmtDate(start), e = fmtDate(end);
        saveTask(task.id, { start_date: s, end_date: e, is_manual: 1 }).then(function () {
          // Keep the in-memory copy in step with what was stored, or the editor
          // would reopen showing the pre-drag dates and write those stale values
          // back on save — snapping the bar backwards.
          var o = byId(task.id);
          if (o) { o.start = s; o.end = e; o.is_manual = true; }
          decorate();
        }).catch(function () { renderDetailed(); });
      }
    });

    applyWidth(detailedGantt, z.w);
    setTimeout(function () {
      decorate();
      addTodayLine(detailedGantt, el);
      addMilestones(detailedGantt, el);
      decorateCalendar(detailedGantt, el);
      // Last, so the floating header paints over the bars and the today line
      // rather than under them.
      stickyHeader(el);
    }, 80);
  }

  function byId(id) {
    return window.DETAILED_TASKS.find(function (x) { return String(x.id) === String(id); });
  }

  // --- per-step colours + the lock outline -----------------------------------
  // Frappe colours every bar identically; the step colour is what makes a
  // thousand-row chart readable at a glance.
  function paintBars(container, source) {
    container.querySelectorAll('.bar-wrapper').forEach(function (w) {
      var o = source.find(function (x) { return String(x.id) === String(w.getAttribute('data-id')); });
      if (!o) return;
      var bar = w.querySelector('.bar');
      if (bar && o.color) bar.style.fill = o.color;
      if (o.is_manual) w.classList.add('tl-locked');
      if (o.is_approved) w.classList.add('tl-done');
    });
  }

  // --- the assignee name, left of the bar ------------------------------------
  // The detail that makes this chart readable: the name travels with the bar
  // instead of sitting in a column that scrolls out of view.
  function decorate() {
    var el = $('detailedGantt');
    if (!el) return;
    paintBars(el, window.DETAILED_TASKS);

    el.querySelectorAll('.bar-wrapper').forEach(function (w) {
      var old = w.querySelector('.tl-crew-label');
      if (old) old.remove();

      var o = byId(w.getAttribute('data-id'));
      if (!o || !o.crew_name) return;
      var bar = w.querySelector('.bar');
      if (!bar) return;

      var x = parseFloat(bar.getAttribute('x'));
      var y = parseFloat(bar.getAttribute('y'));
      var h = parseFloat(bar.getAttribute('height'));

      var label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      label.setAttribute('class', 'tl-crew-label');
      label.setAttribute('x', x - 6);
      label.setAttribute('y', y + h / 2 + 4);
      label.setAttribute('text-anchor', 'end');
      label.textContent = o.crew_name;
      w.appendChild(label);
    });
  }

  // --- keep the date header visible while scrolling vertically ---------------
  //
  // SVG has no z-index: things paint in document order, and Frappe draws the
  // header near the START, before the bars. Translating it down on scroll
  // therefore slid it UNDER every bar it passed, which is why the month and day
  // numbers appeared tangled up with the tasks.
  //
  // The fix is to move the header elements into a group appended LAST, so they
  // paint on top, and translate that one group instead of each element.
  function stickyHeader(container) {
    var svg = container.querySelector('svg.gantt');
    if (!svg) return;

    var head = svg.querySelector('.grid-header');
    if (!head) return;
    var headH = parseFloat(head.getAttribute('height')) || 60;

    var ns = 'http://www.w3.org/2000/svg';
    var g = document.createElementNS(ns, 'g');
    g.setAttribute('class', 'tl-sticky');

    // appendChild MOVES a node rather than copying it, which is exactly what is
    // wanted: the header leaves the grid layer and joins the top of the stack.
    g.appendChild(head);
    var dates = svg.querySelector('.date');
    if (dates) g.appendChild(dates);
    // Only the ticks that sit inside the header band — the full-height grid
    // lines must stay where they are, behind the bars.
    Array.prototype.slice.call(svg.querySelectorAll('.tick')).forEach(function (t) {
      if (parseFloat(t.getAttribute('y1')) < headH + 5) g.appendChild(t);
    });
    svg.appendChild(g);

    // The container survives re-renders (only its contents are replaced), so a
    // fresh listener every time would stack up and translate a detached group.
    if (container._tlScroll) container.removeEventListener('scroll', container._tlScroll);
    container._tlScroll = function () {
      g.setAttribute('transform', 'translate(0,' + container.scrollTop + ')');
    };
    container.addEventListener('scroll', container._tlScroll);
    container._tlScroll();
  }

  // --- zoom -------------------------------------------------------------------
  window.zoomChange = function (delta) {
    // The ladder runs widest-span first, so + (zoom in) moves UP the index.
    zoomIdx = Math.max(0, Math.min(ZOOM.length - 1, zoomIdx + delta));
    saveZoom('detailed', zoomIdx);
    renderDetailed();
  };

  // --- editor -----------------------------------------------------------------
  function openEditor(task) {
    var o = byId(task.id);
    if (!o) return;
    $('taskLabel').textContent = o.item_label + ' · ' + o.step_name;
    $('editTaskId').value = o.id;
    $('editStart').value = o.start;
    $('editEnd').value = o.end;          // already the inclusive last day
    $('editCrew').value = o.crew_id || '';
    $('editManual').checked = !!o.is_manual;
    $('taskEditor').style.display = 'flex';
  }
  window.closeTaskEditor = function () { $('taskEditor').style.display = 'none'; };

  var form = $('taskEditForm');
  if (form) form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var id = $('editTaskId').value;
    var payload = {
      start_date: $('editStart').value,
      end_date: $('editEnd').value,
      crew_id: $('editCrew').value || '',
      is_manual: $('editManual').checked ? 1 : 0
    };
    if (payload.end_date < payload.start_date) {
      alert('The end date cannot be before the start date.');
      return;
    }
    saveTask(id, payload).then(function () {
      var o = byId(id);
      if (o) {
        o.start = payload.start_date;
        o.end = payload.end_date;
        o.crew_id = payload.crew_id ? parseInt(payload.crew_id, 10) : null;
        var sel = $('editCrew').selectedOptions[0];
        o.crew_name = payload.crew_id ? sel.text.replace(/ \(slot\)$/, '') : '';
        o.is_manual = !!payload.is_manual;
      }
      closeTaskEditor();
      renderDetailed();
    }).catch(function () { /* saveTask already reported it; leave the modal open */ });
  });

  function saveTask(id, payload) {
    var body = new URLSearchParams();
    body.append('id', id);
    for (var k in payload) body.append(k, payload[k] === null ? '' : payload[k]);
    return fetch('schedule_api.php?action=update&project=' + window.TL_PROJECT,
                 { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // The response is inspected rather than assumed: a rejection that nobody
        // reads leaves the bar showing a change that was never stored.
        if (!d.ok) { alert(d.error || 'Save failed.'); throw new Error(d.error || 'failed'); }
        return d;
      })
      .catch(function (err) {
        if (err instanceof SyntaxError) alert('Save failed, try reloading the page.');
        throw err;
      });
  }

  // Local parts only. See rule 2 at the top.
  function fmtDate(d) {
    return d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' +
      String(d.getDate()).padStart(2, '0');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  // --- .gan download, honouring the on-screen filters ------------------------
  window.downloadGan = function () {
    var qs = new URLSearchParams();
    qs.set('project', window.TL_PROJECT);
    var epEl = $('episodeFilter');
    if (epEl && epEl.value && epEl.value !== 'all') qs.set('ep', epEl.value);
    var steps = selectedStepIds();
    if (steps.length) qs.set('steps', steps.join(','));
    window.location.href = 'timeline_gan.php?' + qs.toString();
  };

  // --- PNG / PDF ---------------------------------------------------------------
  window.downloadTimeline = function (elementId, base, format) {
    var el = $(elementId);
    if (!el) return;
    var btn = window.event && window.event.target ? window.event.target.closest('button') : null;
    var original = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = 'Rendering…'; }

    // THE WHOLE CHART, not the window onto it.
    //
    // .tl-chart is `overflow: auto; max-height: 420px`, so what you see is a
    // porthole over a much taller and wider drawing. Passing width/height to
    // html2canvas only sizes the CANVAS, the element itself is still cropped by
    // its own CSS, which is why exports arrived with the first and last steps
    // sliced off and a field of white where the rest should have been.
    //
    // The fix is to un-crop it, but in the CLONE html2canvas renders rather than
    // on the live page: touching the real element would make the chart jump out
    // of its box and shove the page around for the second it takes to render.
    var svg   = el.querySelector('svg');
    var box   = svg ? svg.getBoundingClientRect() : null;
    var fullW = Math.max(el.scrollWidth,  box ? Math.ceil(box.width)  : 0);
    var fullH = Math.max(el.scrollHeight, box ? Math.ceil(box.height) : 0);

    // Browsers cap a canvas at about 16384px a side and will hand back a blank
    // one past that, with no error. A wide chart loses resolution rather than
    // the export; only below 1x is it worth saying so.
    var scale = 2;
    while (scale > 0.5 && (fullW * scale > 16000 || fullH * scale > 16000)) scale -= 0.25;
    if (scale < 1 && !confirm(
          'This chart is ' + fullW + '×' + fullH + ' pixels, which is near the largest image a '
        + 'browser can make. It will be exported at reduced sharpness. Continue?')) {
      if (btn) { btn.disabled = false; btn.textContent = original; }
      return;
    }

    html2canvas(el, {
      backgroundColor: '#ffffff',
      scale: scale,
      useCORS: true,
      scrollX: 0, scrollY: 0,
      x: 0, y: 0,
      windowWidth: fullW,
      windowHeight: fullH,
      width: fullW,
      height: fullH,
      onclone: function (doc) {
        var c = doc.getElementById(elementId);
        if (!c) return;
        c.style.overflow  = 'visible';
        c.style.maxHeight = 'none';
        c.style.height    = fullH + 'px';
        c.style.width     = fullW + 'px';
      }
    }).then(function (canvas) {
      // Built from local parts for the same reason as every other date here.
      var d = new Date();
      var stamp = fmtDate(d);
      // CODE _ WHAT _ WHEN — "RTC_GeneralTimeline_2026-08-31.png" (David,
      // 2026-08-31). The code comes first because a downloads folder sorts by
      // name: every export for one show lands together, and the descriptive
      // part is identical across projects so the date still sorts within it.
      var code = window.TL_CODE ? window.TL_CODE + '_' : '';
      var filename = code + base + '_' + stamp + '.' + format;

      if (format === 'png') {
        var link = document.createElement('a');
        link.download = filename;
        link.href = canvas.toDataURL('image/png');
        link.click();
      } else {
        var jsPDF = window.jspdf.jsPDF;
        var orientation = canvas.width >= canvas.height ? 'landscape' : 'portrait';
        var pdf = new jsPDF({
          orientation: orientation, unit: 'px',
          format: [canvas.width, canvas.height], hotfixes: ['px_scaling']
        });
        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, canvas.width, canvas.height);
        pdf.save(filename);
      }
    }).catch(function (err) {
      alert('Download failed: ' + err.message);
    }).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = original; }
    });
  };

  // --- filters -----------------------------------------------------------------
  if ($('episodeFilter')) $('episodeFilter').addEventListener('change', renderDetailed);
  if ($('hideCompleted')) $('hideCompleted').addEventListener('change', renderDetailed);
  if ($('groupBy'))       $('groupBy').addEventListener('change', renderDetailed);

  (function stepFilter() {
    var toggle = $('stepFilterToggle'), panel = $('stepFilterPanel');
    var label = $('stepFilterLabel'), allCb = $('stepFilterAll');
    if (!toggle || !panel) return;
    var cbs = function () {
      return Array.prototype.slice.call(document.querySelectorAll('.step-cb'));
    };

    function syncLabel() {
      var picked = cbs().filter(function (cb) { return cb.checked; });
      allCb.checked = picked.length === 0;
      if (picked.length === 0) label.textContent = 'All steps';
      else if (picked.length === 1) label.textContent = picked[0].parentElement.textContent.trim();
      else label.textContent = picked.length + ' steps';
    }

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !panel.hidden;
      panel.hidden = open;
      toggle.setAttribute('aria-expanded', String(!open));
    });
    // "All steps" is simply the empty selection.
    allCb.addEventListener('change', function () {
      if (allCb.checked) cbs().forEach(function (cb) { cb.checked = false; });
      syncLabel(); renderDetailed();
    });
    cbs().forEach(function (cb) {
      cb.addEventListener('change', function () { syncLabel(); renderDetailed(); });
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () {
      if (!panel.hidden) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) {
        panel.hidden = true; toggle.setAttribute('aria-expanded', 'false');
      }
    });
    syncLabel();
  })();

  renderGeneral();
  renderDetailed();
})();
