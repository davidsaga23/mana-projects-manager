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

  var ZOOM_LEVELS = ['Day', 'Week', 'Month', 'Year'];
  var zoomIdx = 1;                       // Week
  var detailedGantt = null;

  var $ = function (id) { return document.getElementById(id); };

  // --- general chart ---------------------------------------------------------
  function renderGeneral() {
    var el = $('generalGantt');
    if (!el || !window.GENERAL_TASKS || !window.GENERAL_TASKS.length) return;
    el.innerHTML = '';
    new Gantt(el, window.GENERAL_TASKS, {
      view_mode: 'Week',
      bar_height: 22,
      bar_corner_radius: 3,
      language: 'en',
      popup_trigger: 'mouseover',
      custom_popup_html: function (t) {
        var src = window.GENERAL_TASKS.find(function (x) { return x.id === t.id; }) || {};
        var late = src.late ? '<br><span style="color:#8a6d1a">past its deadline of '
                            + src.deadline + '</span>' : '';
        return '<div class="tl-popup"><strong>' + escapeHtml(t.name) + '</strong><br>'
             + t.start + ' → ' + t.end + late + '</div>';
      }
    });
    setTimeout(function () { paintBars(el, window.GENERAL_TASKS); }, 60);
  }

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
    return window.DETAILED_TASKS.filter(function (t) {
      if (ep !== 'all' && t.episode !== ep) return false;
      if (steps.length && steps.indexOf(String(t.step_id)) === -1) return false;
      if (hide && t.is_approved) return false;
      return true;
    });
  }

  function renderDetailed() {
    var el = $('detailedGantt');
    if (!el) return;
    var view = ZOOM_LEVELS[zoomIdx];
    $('zoomLabel').textContent = view;

    var tasks = filtered();
    if (!tasks.length) {
      el.innerHTML = '<p class="muted" style="padding:20px">Nothing matches the current filters.</p>';
      return;
    }
    el.innerHTML = '';

    detailedGantt = new Gantt(el, tasks, {
      view_mode: view,
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

    setTimeout(function () { decorate(); stickyHeader(el); }, 80);
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
  function stickyHeader(container) {
    var svg = container.querySelector('svg.gantt');
    if (!svg) return;
    var head = svg.querySelector('.grid-header');
    if (!head) return;
    var headH = parseFloat(head.getAttribute('height')) || 60;

    var els = [head]
      .concat(Array.prototype.slice.call(svg.querySelectorAll('.upper-text')))
      .concat(Array.prototype.slice.call(svg.querySelectorAll('.lower-text')));
    svg.querySelectorAll('.tick').forEach(function (t) {
      if (parseFloat(t.getAttribute('y1')) < headH + 5) els.push(t);
    });

    container.addEventListener('scroll', function () {
      var y = container.scrollTop;
      els.forEach(function (n) { n.setAttribute('transform', 'translate(0,' + y + ')'); });
    });
  }

  // --- zoom -------------------------------------------------------------------
  window.zoomChange = function (delta) {
    // + means zoom IN, which is a smaller unit, which is a lower index.
    zoomIdx = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, zoomIdx - delta));
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
