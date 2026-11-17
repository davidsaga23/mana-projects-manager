/*
 * Hover, tap and keyboard read-out for the server-drawn line charts.
 *
 * David, 2026-11-16: "let's make this graph wider and interactive". Used by the
 * Dashboard's "Approved over time" (dashboard_burnup_svg) and the department
 * page's Trend (step_trend_svg), so both charts behave the same way.
 *
 * The PHP draws the whole chart; this only adds the moving parts. Each chart is
 * a .chart-hover element carrying its days as JSON in data-chart:
 *   [{ x: 0-100 (% of the hover area's width), ys: [px from the area's top, ...],
 *      title: "Mon 16 Nov 2026", lines: ["118 approved · 81% of 145", ...] }, ...]
 * and a .ch-area inside it that the positions are measured against. Without
 * this file the chart is still complete; nothing here is needed to read it.
 *
 * No library, on purpose: the codebase has none for charts, and a guide line, a
 * dot and a box do not justify the first one.
 */
(function () {
  'use strict';

  function setup(root) {
    if (root.getAttribute('data-ch-ready')) return;   // the script may be included twice
    root.setAttribute('data-ch-ready', '1');
    var pts;
    try { pts = JSON.parse(root.getAttribute('data-chart') || '[]'); } catch (e) { return; }
    var area = root.querySelector('.ch-area');
    if (!area || !pts.length) return;

    var guide = document.createElement('div');
    guide.className = 'ch-guide';
    var tip = document.createElement('div');
    tip.className = 'ch-tip';
    // Read aloud for keyboard and screen-reader users; the tooltip is for eyes.
    var live = document.createElement('div');
    live.className = 'ch-live';
    live.setAttribute('aria-live', 'polite');
    area.appendChild(guide);
    area.appendChild(tip);
    root.appendChild(live);

    var dots = [];
    var current = -1;

    function hide() {
      current = -1;
      root.classList.remove('ch-on');
    }

    function show(i) {
      if (i < 0) i = 0;
      if (i > pts.length - 1) i = pts.length - 1;
      var p = pts[i];
      current = i;
      root.classList.add('ch-on');
      guide.style.left = p.x + '%';

      // One dot per series at this day; the first series is the main one.
      while (dots.length < p.ys.length) {
        var d = document.createElement('div');
        d.className = 'ch-dot' + (dots.length ? ' ch-dot-' + dots.length : '');
        area.appendChild(d);
        dots.push(d);
      }
      dots.forEach(function (d, k) {
        if (k < p.ys.length) {
          d.style.display = '';
          d.style.left = p.x + '%';
          d.style.top = p.ys[k] + 'px';
        } else {
          d.style.display = 'none';
        }
      });

      tip.textContent = '';
      var t = document.createElement('div');
      t.className = 'ch-tip-title';
      t.textContent = p.title;
      tip.appendChild(t);
      p.lines.forEach(function (line, k) {
        var l = document.createElement('div');
        l.className = 'ch-tip-line ch-tip-line-' + k;
        l.textContent = line;
        tip.appendChild(l);
      });

      // Beside the guide, on whichever side has room, so it never covers the
      // point being read.
      if (p.x < 55) {
        tip.style.left = 'calc(' + p.x + '% + 12px)';
        tip.style.right = 'auto';
      } else {
        tip.style.left = 'auto';
        tip.style.right = 'calc(' + (100 - p.x) + '% + 12px)';
      }

      live.textContent = p.title + ': ' + p.lines.join(', ');
    }

    function nearest(clientX) {
      var r = area.getBoundingClientRect();
      if (!r.width) return 0;
      var at = (clientX - r.left) / r.width * 100;
      var best = 0, gap = Infinity;
      for (var i = 0; i < pts.length; i++) {
        var g = Math.abs(pts[i].x - at);
        if (g < gap) { gap = g; best = i; }
      }
      return best;
    }

    // Pointer events cover mouse, pen and touch in one path: a tap shows the
    // nearest day and a drag along the chart follows the finger.
    root.addEventListener('pointermove', function (ev) { show(nearest(ev.clientX)); });
    root.addEventListener('pointerdown', function (ev) { show(nearest(ev.clientX)); });
    root.addEventListener('pointerleave', function (ev) {
      if (ev.pointerType === 'mouse' && document.activeElement !== root) hide();
    });

    root.addEventListener('focus', function () { if (current < 0) show(pts.length - 1); });
    root.addEventListener('blur', hide);
    root.addEventListener('keydown', function (ev) {
      var i = current < 0 ? pts.length - 1 : current;
      if (ev.key === 'ArrowLeft' || ev.key === 'ArrowDown') i--;
      else if (ev.key === 'ArrowRight' || ev.key === 'ArrowUp') i++;
      else if (ev.key === 'Home') i = 0;
      else if (ev.key === 'End') i = pts.length - 1;
      else if (ev.key === 'Escape') { hide(); return; }
      else return;
      ev.preventDefault();
      show(i);
    });
  }

  function init() {
    var charts = document.querySelectorAll('.chart-hover[data-chart]');
    for (var i = 0; i < charts.length; i++) setup(charts[i]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
