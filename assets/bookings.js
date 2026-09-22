// Bookings: every show's positions on one chart (bookings.php, 2026-09-21).
//
// Two views of window.BK, which includes/bookings.php builds:
//
//   BY PERSON  one row per person, each booking a bar in its show's colour,
//              stacked when two cross, and the clash itself shaded red.
//   BY SHOW    every show's general timeline stacked, a row per dated step, the
//              people on it named on the bar, red where one of them is needed on
//              another show at the same time.
//
// Plain positioned HTML, not Frappe Gantt: Frappe draws one bar per row, and a
// person's shows side by side on ONE row is the whole point of the first view.
//
// Dates stay 'YYYY-MM-DD' strings, which compare correctly as text, and only
// ever become a Date built from their parts. new Date('2026-09-21') is read as
// UTC and comes back a day early in Mexico City (the timeline's rule 2).
(function () {
  'use strict';
  var BK = window.BK;
  if (!BK) return;
  var $ = function (id) { return document.getElementById(id); };

  // A colour per show, in the order the page lists them. Chosen to stay apart
  // from each other and from the red that means a clash.
  var PALETTE = ['#5FB2FF', '#F0A45F', '#5FD08A', '#B98CE8', '#4FD1C5', '#E8C55F', '#9AA7B3', '#F080A8'];
  // Pixels per day, widest span first, like the timeline's ladder.
  var ZOOM = [
    { w: 1.5, label: 'Months' }, { w: 3, label: 'Months' }, { w: 5, label: 'Weeks' },
    { w: 9, label: 'Weeks' }, { w: 16, label: 'Days' }
  ];
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var LABEL_W = 220;
  var BAR_H = 20, LANE_H = 24, ROW_PAD = 5;

  function parseYmd(s) {
    var p = String(s).split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }
  function fmtDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-'
         + String(d.getDate()).padStart(2, '0');
  }
  // Rounded, so a day that is 23 or 25 hours long around a clock change still
  // counts as one.
  function daysBetween(a, b) { return Math.round((parseYmd(b) - parseYmd(a)) / 86400000); }
  function shortDate(s) { var d = parseYmd(s); return d.getDate() + ' ' + MONTHS[d.getMonth()]; }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function clamp(v, lo, hi) { return isNaN(v) ? lo : Math.max(lo, Math.min(hi, v)); }

  // --- remembered per browser --------------------------------------------------
  // A preference about one person's screen, like the timeline's zoom. Wrapped:
  // storage throws outright in some privacy modes.
  function load(key, fallback) {
    try { var v = window.localStorage.getItem('anima.bk.' + key); return v === null ? fallback : v; }
    catch (e) { return fallback; }
  }
  function save(key, v) {
    try { window.localStorage.setItem('anima.bk.' + key, String(v)); } catch (e) { /* ignore */ }
  }

  var view = load('view', 'person') === 'show' ? 'show' : 'person';
  var zoomIdx = clamp(parseInt(load('zoom', '2'), 10), 0, ZOOM.length - 1);
  var off = {};               // project id -> true while its chip is switched off
  var firstDraw = true;

  var projectById = {}, colorOf = {}, bookingByLane = {};
  BK.projects.forEach(function (p, i) {
    projectById[p.id] = p;
    colorOf[p.id] = PALETTE[i % PALETTE.length];
  });
  BK.bookings.forEach(function (b) { bookingByLane[b.lane_id] = b; });

  function shown(pid) { return !off[pid]; }
  function code(pid) { return (projectById[pid] || {}).code || '?'; }
  function stepLabel(b) { return code(b.project_id) + ' · ' + b.step; }
  function span(x) { return shortDate(x.start) + ' to ' + shortDate(x.end); }

  /** The clashes between two shows that are both switched on. */
  function visibleClashes() {
    return BK.clashes.filter(function (c) {
      var a = bookingByLane[c.a], b = bookingByLane[c.b];
      return a && b && shown(a.project_id) && shown(b.project_id);
    });
  }

  /** "Also on DOOM · Layout, 12 Oct to 30 Oct, 15 working days", for a tooltip. */
  function clashLine(c, from) {
    var mine = bookingByLane[from], other = bookingByLane[c.a === from ? c.b : c.a];
    if (!mine || !other) return '';
    return 'Also on ' + stepLabel(other) + ', ' + shortDate(c.from) + ' to ' + shortDate(c.to)
         + ', ' + c.days + ' working day' + (c.days === 1 ? '' : 's');
  }

  // --- the calendar across the top ------------------------------------------------
  function rangeOf(items) {
    var lo = BK.today, hi = BK.today;
    items.forEach(function (x) {
      if (x.start < lo) lo = x.start;
      if (x.end > hi) hi = x.end;
    });
    // A week of air either side, then out to whole weeks, Monday to Sunday, so the
    // week lines fall on Mondays.
    var s = parseYmd(lo); s.setDate(s.getDate() - 7);
    while (s.getDay() !== 1) s.setDate(s.getDate() - 1);
    var e = parseYmd(hi); e.setDate(e.getDate() + 7);
    while (e.getDay() !== 0) e.setDate(e.getDate() + 1);
    var start = fmtDate(s), end = fmtDate(e);
    return { start: start, end: end, days: daysBetween(start, end) + 1 };
  }

  function xOf(r, date, w) { return daysBetween(r.start, date) * w; }
  function widthOf(x, w) { return Math.max(3, (daysBetween(x.start, x.end) + 1) * w); }

  function headHtml(r, w) {
    var html = '';
    var d = parseYmd(r.start);
    for (var i = 0; i < r.days; i++) {
      var first = d.getDate() === 1;
      // A month's name where it begins; the part month at the left edge gets one
      // too when there is room before the next begins.
      if (first || (i === 0 && d.getDate() <= 20)) {
        html += '<span class="bk-month" style="left:' + (i * w) + 'px">'
              + MONTHS[d.getMonth()] + ' ' + d.getFullYear() + '</span>';
      }
      if (d.getDay() === 1 && w >= 3) {
        html += '<span class="bk-week" style="left:' + (i * w) + 'px">'
              + (w >= 5 ? d.getDate() : '') + '</span>';
      }
      d.setDate(d.getDate() + 1);
    }
    return '<div class="bk-row bk-head"><div class="bk-label">'
         + (view === 'person' ? 'Person' : 'Show and step') + '</div>'
         + '<div class="bk-track" style="width:' + (r.days * w) + 'px">' + html + '</div></div>';
  }

  function trackOpen(r, w, height, extraClass) {
    return '<div class="bk-track' + (extraClass || '') + '" style="width:' + (r.days * w)
         + 'px;height:' + height + 'px;background-size:' + (7 * w) + 'px 100%">';
  }
  function todayHtml(r, w) {
    return '<div class="bk-today" style="left:' + xOf(r, BK.today, w) + 'px"></div>';
  }
  function barHtml(r, w, x, top, color, label, title, href, cls) {
    return '<a class="bk-bar' + (cls ? ' ' + cls : '') + '" href="' + escapeHtml(href) + '"'
         + ' title="' + escapeHtml(title) + '"'
         + ' style="left:' + xOf(r, x.start, w) + 'px;width:' + widthOf(x, w) + 'px;top:' + top
         + 'px;background:' + color + '">' + escapeHtml(label) + '</a>';
  }
  function bandHtml(r, w, c, title) {
    return '<div class="bk-band" title="' + escapeHtml(title) + '" style="left:'
         + xOf(r, c.from, w) + 'px;width:' + widthOf({ start: c.from, end: c.to }, w) + 'px"></div>';
  }
  function timelineHref(pid) { return 'timeline.php?project=' + encodeURIComponent(pid); }

  // --- BY PERSON ----------------------------------------------------------------------
  function drawByPerson(onlyClashes) {
    var clashes = visibleClashes();
    var clashBy = {};
    clashes.forEach(function (c) { (clashBy[c.person_id] = clashBy[c.person_id] || []).push(c); });

    var people = {}, order = [];
    BK.bookings.forEach(function (b) {
      if (!shown(b.project_id)) return;
      if (!people[b.person_id]) {
        people[b.person_id] = { id: b.person_id, name: b.person, dept: b.dept, bookings: [] };
        order.push(b.person_id);
      }
      people[b.person_id].bookings.push(b);
    });
    var rows = order.map(function (id) { return people[id]; })
      .filter(function (p) { return !onlyClashes || clashBy[p.id]; })
      .sort(function (a, b) { return a.name.localeCompare(b.name); });
    if (!rows.length) {
      return { html: '', empty: onlyClashes ? 'Nobody is on two shows at once.'
                                            : 'Nobody holds a position on a dated step of these shows yet.' };
    }

    var all = [];
    rows.forEach(function (p) { all = all.concat(p.bookings); });
    var r = rangeOf(all), w = ZOOM[zoomIdx].w;
    var html = headHtml(r, w);

    rows.forEach(function (p) {
      // Stacked into as few lanes as will hold them: a booking goes on the first
      // lane whose last booking ended before this one starts.
      var sorted = p.bookings.slice().sort(function (a, b) { return a.start < b.start ? -1 : (a.start > b.start ? 1 : 0); });
      var laneEnd = [], laneOf = {};
      sorted.forEach(function (b) {
        var l = 0;
        while (l < laneEnd.length && laneEnd[l] >= b.start) l++;
        laneEnd[l] = b.end;
        laneOf[b.lane_id] = l;
      });
      var height = laneEnd.length * LANE_H + ROW_PAD * 2 - (LANE_H - BAR_H);
      var mine = clashBy[p.id] || [];
      var inClash = {};
      mine.forEach(function (c) { inClash[c.a] = true; inClash[c.b] = true; });

      html += '<div class="bk-row"><div class="bk-label"><strong>' + escapeHtml(p.name) + '</strong>'
            + (mine.length ? '<span class="bk-badge" title="Needed on two shows at once">'
                             + mine.length + ' clash' + (mine.length === 1 ? '' : 'es') + '</span>' : '')
            + (p.dept ? '<small class="muted">' + escapeHtml(p.dept) + '</small>' : '')
            + '</div>' + trackOpen(r, w, height);
      mine.forEach(function (c) {
        html += bandHtml(r, w, c, p.name + ' is needed on two shows, ' + shortDate(c.from) + ' to '
                                  + shortDate(c.to) + ', ' + c.days + ' working day' + (c.days === 1 ? '' : 's'));
      });
      sorted.forEach(function (b) {
        var lines = [stepLabel(b) + ', ' + b.scope, span(b) + ', position ' + b.lane];
        mine.forEach(function (c) {
          if (c.a === b.lane_id || c.b === b.lane_id) lines.push(clashLine(c, b.lane_id));
        });
        lines.push('Click to open ' + code(b.project_id) + '’s timeline');
        html += barHtml(r, w, b, ROW_PAD + laneOf[b.lane_id] * LANE_H, colorOf[b.project_id],
                        stepLabel(b), lines.join('\n'), timelineHref(b.project_id),
                        inClash[b.lane_id] ? 'is-clash' : '');
      });
      html += todayHtml(r, w) + '</div></div>';
    });
    return { html: html, range: r, w: w };
  }

  // --- BY SHOW -------------------------------------------------------------------------
  function drawByShow(onlyClashes) {
    var clashes = visibleClashes();
    var clashByStep = {};
    clashes.forEach(function (c) {
      [c.a, c.b].forEach(function (lane) {
        var b = bookingByLane[lane];
        if (b) (clashByStep[b.step_id] = clashByStep[b.step_id] || []).push({ c: c, lane: lane });
      });
    });
    var peopleByStep = {};
    BK.bookings.forEach(function (b) {
      (peopleByStep[b.step_id] = peopleByStep[b.step_id] || []).push(b);
    });

    var groups = [];
    BK.projects.forEach(function (p) {
      if (!shown(p.id)) return;
      var steps = BK.steps.filter(function (s) {
        return s.project_id === p.id && (!onlyClashes || clashByStep[s.id]);
      });
      if (steps.length) groups.push({ project: p, steps: steps });
    });
    if (!groups.length) {
      return { html: '', empty: onlyClashes ? 'No step has anybody who is needed on another show at the same time.'
                                            : 'None of these shows has a dated step yet.' };
    }

    var all = [];
    groups.forEach(function (g) {
      all = all.concat(g.steps);
      if (g.project.delivery) all.push({ start: g.project.delivery, end: g.project.delivery });
    });
    var r = rangeOf(all), w = ZOOM[zoomIdx].w;
    var html = headHtml(r, w);
    var stepH = BAR_H + ROW_PAD * 2;

    groups.forEach(function (g) {
      var p = g.project;
      html += '<div class="bk-row bk-group"><div class="bk-label"><span class="bk-swatch" style="background:'
            + colorOf[p.id] + '"></span> <strong>' + escapeHtml(p.code) + '</strong> '
            + '<span class="muted">' + escapeHtml(p.name) + '</span></div>' + trackOpen(r, w, 26);
      if (p.delivery) {
        html += '<span class="bk-delivery" style="left:' + xOf(r, p.delivery, w) + 'px" title="Delivery, '
              + escapeHtml(shortDate(p.delivery)) + '">◆ delivery ' + escapeHtml(shortDate(p.delivery)) + '</span>';
      }
      html += todayHtml(r, w) + '</div></div>';

      g.steps.forEach(function (s) {
        var people = (peopleByStep[s.id] || []).map(function (b) { return b.person; });
        var unfilled = +(BK.unfilled[s.id] || 0);
        var hits = clashByStep[s.id] || [];
        var who = people.length ? people.join(', ') : (unfilled ? 'nobody hired yet' : 'no positions');
        var label = s.name + ' · ' + who;
        var lines = [code(s.project_id) + ' · ' + s.name + ', ' + s.scope, span(s),
                     people.length ? 'On it: ' + people.join(', ') : 'Nobody holds a position on it yet'];
        if (unfilled) lines.push(unfilled + ' position' + (unfilled === 1 ? '' : 's') + ' not filled yet');
        hits.forEach(function (h) {
          var b = bookingByLane[h.lane];
          lines.push(b.person + ': ' + clashLine(h.c, h.lane).replace(/^Also/, 'also'));
        });
        lines.push('Click to open ' + code(s.project_id) + '’s timeline');

        html += '<div class="bk-row"><div class="bk-label">' + escapeHtml(s.name)
              + '<small class="muted">' + escapeHtml(s.scope) + '</small></div>' + trackOpen(r, w, stepH);
        hits.forEach(function (h) {
          html += bandHtml(r, w, h.c, bookingByLane[h.lane].person + ' is needed on two shows here');
        });
        html += barHtml(r, w, s, ROW_PAD, s.color, label, lines.join('\n'), timelineHref(s.project_id),
                        hits.length ? 'is-clash' : '');
        html += todayHtml(r, w) + '</div></div>';
      });
    });
    return { html: html, range: r, w: w };
  }

  // --- the list above the chart ----------------------------------------------------------
  function drawSummary() {
    var el = $('bkSummary');
    if (!el) return;
    var cs = visibleClashes().slice().sort(function (x, y) {
      var a = bookingByLane[x.a], b = bookingByLane[y.a];
      return (a.person.localeCompare(b.person)) || (x.from < y.from ? -1 : (x.from > y.from ? 1 : 0));
    });
    if (!BK.bookings.length) {
      el.innerHTML = '<p class="muted">Nobody holds a position on a dated step of these shows yet.</p>';
      return;
    }
    if (!cs.length) {
      el.innerHTML = '<p class="ok">✓ Nobody is needed on two shows at once, on the shows shown.</p>';
      return;
    }
    var people = {};
    cs.forEach(function (c) { people[c.person_id] = true; });
    var n = Object.keys(people).length;
    var html = '<h3>' + n + ' ' + (n === 1 ? 'person is' : 'people are') + ' needed on two shows at once</h3><ul>';
    cs.forEach(function (c) {
      var a = bookingByLane[c.a], b = bookingByLane[c.b];
      html += '<li><strong>' + escapeHtml(a.person) + '</strong>: ' + escapeHtml(stepLabel(a))
            + ' (' + escapeHtml(span(a)) + ') and ' + escapeHtml(stepLabel(b)) + ' (' + escapeHtml(span(b))
            + ') cross for <strong>' + c.days + ' working day' + (c.days === 1 ? '' : 's') + '</strong>, '
            + escapeHtml(shortDate(c.from)) + ' to ' + escapeHtml(shortDate(c.to)) + '.</li>';
    });
    el.innerHTML = html + '</ul>';
  }

  // --- the chips, one per show -------------------------------------------------------------
  function drawChips() {
    var el = $('bkShows');
    if (!el) return;
    el.innerHTML = BK.projects.map(function (p) {
      return '<label class="bk-chip' + (off[p.id] ? ' is-off' : '') + '">'
           + '<input type="checkbox" data-pid="' + p.id + '"' + (off[p.id] ? '' : ' checked') + '>'
           + '<span class="bk-swatch" style="background:' + colorOf[p.id] + '"></span>'
           + '<strong>' + escapeHtml(p.code) + '</strong> <span class="muted">' + escapeHtml(p.name)
           + '</span></label>';
    }).join('');
  }

  // --- drawing it all ------------------------------------------------------------------------
  function render() {
    var chart = $('bkChart');
    if (!chart) return;
    var wrap = chart.parentNode;
    var only = !!($('bkOnlyClashes') && $('bkOnlyClashes').checked);

    // Hold the date at the left edge across a zoom or a filter, rather than
    // jumping back to the start.
    var was = chart._bk || null;
    var leftDate = null;
    if (was && wrap.scrollLeft > 0) {
      var d = parseYmd(was.range.start);
      d.setDate(d.getDate() + Math.round(wrap.scrollLeft / was.w));
      leftDate = fmtDate(d);
    }

    var out = view === 'person' ? drawByPerson(only) : drawByShow(only);
    chart.innerHTML = out.html || '<div class="bk-empty">' + escapeHtml(out.empty || '') + '</div>';
    chart._bk = out.html ? { range: out.range, w: out.w } : null;

    if (out.html) {
      if (leftDate) {
        wrap.scrollLeft = Math.max(0, xOf(out.range, leftDate, out.w));
      } else if (firstDraw) {
        // Open on the week before today: what is coming, with a little of what
        // has just been.
        var lastWeek = parseYmd(BK.today); lastWeek.setDate(lastWeek.getDate() - 7);
        wrap.scrollLeft = Math.max(0, xOf(out.range, fmtDate(lastWeek), out.w));
      }
    }
    firstDraw = false;

    $('bkZoomLabel').textContent = ZOOM[zoomIdx].label;
    document.querySelectorAll('.bk-views button').forEach(function (b) {
      b.classList.toggle('is-on', b.getAttribute('data-view') === view);
      b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false');
    });
    $('bkOnlyLabel').textContent = view === 'person'
      ? 'Only people on two shows at once'
      : 'Only steps with somebody on two shows at once';
    drawSummary();
  }

  // --- controls --------------------------------------------------------------------------------
  document.querySelectorAll('.bk-views button').forEach(function (b) {
    b.addEventListener('click', function () {
      view = b.getAttribute('data-view') === 'show' ? 'show' : 'person';
      save('view', view);
      var chart = $('bkChart');
      if (chart) chart._bk = null;          // a different chart: no place to keep
      firstDraw = true;
      render();
    });
  });
  if ($('bkOnlyClashes')) $('bkOnlyClashes').addEventListener('change', render);
  $('bkZoomIn').addEventListener('click', function () {
    zoomIdx = clamp(zoomIdx + 1, 0, ZOOM.length - 1); save('zoom', zoomIdx); render();
  });
  $('bkZoomOut').addEventListener('click', function () {
    zoomIdx = clamp(zoomIdx - 1, 0, ZOOM.length - 1); save('zoom', zoomIdx); render();
  });
  if ($('bkShows')) {
    $('bkShows').addEventListener('change', function (e) {
      var pid = e.target && e.target.getAttribute('data-pid');
      if (!pid) return;
      if (e.target.checked) delete off[pid]; else off[pid] = true;
      drawChips();
      render();
    });
  }

  drawChips();
  render();
})();
