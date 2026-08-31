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
  var zoomIdx = 3;                       // Week, comfortable default
  var generalZoomIdx = 2;
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
    el.textContent = 'Saved — ' + msg;
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
        if (err instanceof SyntaxError) alert('Save failed — try reloading the page.');
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
      // This chart scrolls too — nineteen steps overflow its height — so its
      // header has to float as well, or the months scroll away and the bars
      // below have nothing to be read against.
      stickyHeader(el);
    }, 60);
  }

  window.zoomGeneral = function (delta) {
    generalZoomIdx = Math.max(0, Math.min(ZOOM.length - 1, generalZoomIdx + delta));
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
        if (err instanceof SyntaxError) alert('Save failed — try reloading the page.');
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

    html2canvas(el, {
      backgroundColor: '#ffffff',
      scale: 2,
      useCORS: true,
      scrollX: 0, scrollY: 0,
      windowWidth: el.scrollWidth,
      width: el.scrollWidth,
      height: el.scrollHeight
    }).then(function (canvas) {
      // Built from local parts for the same reason as every other date here.
      var d = new Date();
      var stamp = fmtDate(d);
      var filename = base + '_' + stamp + '.' + format;

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
