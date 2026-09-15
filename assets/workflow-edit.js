// Draw and remove workflow links on the diagram, alongside the tick boxes.
//
// David, 2026-09-14: "is it possible to add drag and drop arrows to re-arrange
// this workflow map in addition to the already working check boxes?"
//
// ⚠ THE TICK BOXES STAY THE ONE SOURCE OF TRUTH. Nothing here writes to the
// server by any route of its own. A drag ticks exactly the box that says the
// same thing and submits the SAME form the "Save and stay here" button submits,
// so save_dependencies() is still the only thing that decides what is stored,
// and its refusal of a loop still applies. Two ways to set one graph that did
// their saving separately would disagree the first time one of them had a bug.
//
// ⚠ WHY LINKS ARE DRAWN AND BOXES ARE NOT DRAGGED. The columns are not a layout
// anybody chose: a step's column is one past the latest thing it waits for
// (workflow_levels()). Dropping a box into column three would not say WHICH
// step in column two it waits for, and there is usually more than one. A line
// from one box to another says exactly that, and the boxes rearrange
// themselves when it saves.
//
// ⚠ DIRECTION. Dragging from A onto B makes B wait for A - the same left-to-
// right direction every existing line is drawn in. A drag is the one gesture
// where getting the direction backwards is easy, so the hint beside the pointer
// says the sentence in step names BEFORE you let go, and the saved message
// repeats it after.
(function () {
  'use strict';

  if (!window.WF_EDITABLE) return;
  var svg = document.querySelector('.wf-diagram');
  var table = document.querySelector('.deps-table');
  var form = table ? table.closest('form') : null;
  if (!svg || !form) return;

  var NS = 'http://www.w3.org/2000/svg';
  var wrap = svg.closest('.wf-diagram-wrap') || svg.parentNode;
  var nodes = Array.prototype.slice.call(svg.querySelectorAll('.wf-node[data-step]'));
  if (nodes.length < 2) return;   // nothing to connect to anything

  // --- reading the graph, from the tick boxes -----------------------------
  // The boxes rather than the picture, because the boxes can hold changes the
  // picture has not been redrawn for yet.
  function tick(target, source) {
    return form.querySelector('input[type="checkbox"][name="deps[' + target + '][]"]'
                              + '[value="' + source + '"]');
  }

  function waitsFor(id) {
    var out = [];
    var boxes = form.querySelectorAll('input[type="checkbox"][name="deps[' + id + '][]"]');
    for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) out.push(boxes[i].value);
    return out;
  }

  /** Does `from` already wait for `to`, directly or through anything else? */
  function reaches(from, to) {
    var seen = {};
    var stack = [from];
    while (stack.length) {
      var cur = stack.pop();
      if (cur === to) return true;
      if (seen[cur]) continue;
      seen[cur] = true;
      var next = waitsFor(cur);
      for (var i = 0; i < next.length; i++) if (!seen[next[i]]) stack.push(next[i]);
    }
    return false;
  }

  // ⚠ A NAME ON ITS OWN IS NOT ENOUGH. Step names only have to be unique within
  // their scope, so a real pipeline carries two Concepts and two Colors, and
  // "Color will wait for Color Script" would not say which one - on the one
  // gesture where saying exactly what will happen is the whole point. A
  // repeated name gets its scope, in the same words the diagram prints under
  // it: "Color (shots)".
  var nameCount = {};
  nodes.forEach(function (g) {
    var n = (g.getAttribute('data-name') || '').trim().toLowerCase();
    nameCount[n] = (nameCount[n] || 0) + 1;
  });

  function nameOf(id) {
    var g = svg.querySelector('.wf-node[data-step="' + id + '"]');
    if (!g) return 'that step';
    var n = g.getAttribute('data-name');
    return nameCount[(n || '').trim().toLowerCase()] > 1
      ? n + ' (' + g.getAttribute('data-scope') + ')'
      : n;
  }

  /**
   * What dropping `source` onto `target` would mean, before it is done.
   *
   * The loop is checked HERE so a drag that cannot be saved says so while the
   * pointer is still over the box, not after a round trip to the server. The
   * server checks again regardless; this is courtesy, not authority.
   */
  function verdict(source, target) {
    if (!target || target === source) return { ok: false, text: '' };
    var box = tick(target, source);
    if (!box) return { ok: false, text: 'Those two cannot be linked here.' };
    if (box.checked) {
      return { ok: false, text: nameOf(target) + ' already waits for ' + nameOf(source) + '.' };
    }
    if (reaches(source, target)) {
      return { ok: false, text: 'That would make a loop: ' + nameOf(source)
                              + ' already waits for ' + nameOf(target) + '.' };
    }
    return { ok: true, text: nameOf(target) + ' will wait for ' + nameOf(source) };
  }

  // --- geometry -----------------------------------------------------------
  // Through the screen matrix rather than subtracting a bounding box, because
  // the diagram scrolls sideways inside its wrapper and a subtraction would be
  // wrong by exactly the scroll.
  function toSvg(evt) {
    var p = svg.createSVGPoint();
    p.x = evt.clientX;
    p.y = evt.clientY;
    return p.matrixTransform(svg.getScreenCTM().inverse());
  }

  function boxOf(g) {
    var r = g.querySelector('.wf-box');
    return {
      x: parseFloat(r.getAttribute('x')), y: parseFloat(r.getAttribute('y')),
      w: parseFloat(r.getAttribute('width')), h: parseFloat(r.getAttribute('height'))
    };
  }

  function nodeAt(pt) {
    for (var i = 0; i < nodes.length; i++) {
      var b = boxOf(nodes[i]);
      if (pt.x >= b.x && pt.x <= b.x + b.w && pt.y >= b.y && pt.y <= b.y + b.h) return nodes[i];
    }
    return null;
  }

  function el(name, attrs) {
    var e = document.createElementNS(NS, name);
    for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
    return e;
  }

  // --- saving -------------------------------------------------------------
  var saving = false;

  /**
   * Submit the same form "Save and stay here" submits, with the change named.
   *
   * form.submit() rather than requestSubmit(): the template fields share this
   * form, and a future `required` on one of them would make requestSubmit()
   * silently refuse to send anything. assets/keepplace.js wraps submit(), so the
   * page still comes back to where the diagram was.
   *
   * Any box ticked or unticked by hand and not yet saved goes with it. That is
   * the same form, and the same thing pressing either save button would do.
   */
  function save(field, source, target) {
    if (saving) return;
    saving = true;
    var add = function (name, value) {
      var i = document.createElement('input');
      i.type = 'hidden';
      i.name = name;
      i.value = value;
      form.appendChild(i);
    };
    add('stay', '1');
    add(field, source + '-' + target);
    wrap.classList.add('wf-saving');
    form.submit();
  }

  // --- handles: where a line is drawn FROM --------------------------------
  // Added here rather than in the SVG the server draws, so the downloadable
  // diagram stays a clean picture with no controls printed on it.
  nodes.forEach(function (g) {
    var b = boxOf(g);
    var cx = b.x + b.w, cy = b.y + b.h / 2;
    // A generous invisible target around a small visible dot: a 5px circle is
    // what reads as a handle, and nobody can reliably land a finger on one.
    var hit = el('circle', { cx: cx, cy: cy, r: 13, class: 'wf-handle-hit' });
    var dot = el('circle', { cx: cx, cy: cy, r: 5, class: 'wf-handle' });
    hit.setAttribute('data-source', g.getAttribute('data-step'));
    g.appendChild(dot);
    g.appendChild(hit);
  });

  // --- drawing a new line -------------------------------------------------
  var hint = document.createElement('div');
  hint.className = 'wf-hint';
  hint.hidden = true;
  document.body.appendChild(hint);

  var drag = null;       // { source, x1, y1, line, over }
  var selected = null;   // { from, to, path, badge }

  function clearTargets() {
    nodes.forEach(function (g) { g.classList.remove('wf-target-ok', 'wf-target-bad'); });
  }

  function endDrag() {
    if (!drag) return;
    if (drag.line.parentNode) drag.line.parentNode.removeChild(drag.line);
    svg.classList.remove('wf-dragging');
    clearTargets();
    hint.hidden = true;
    drag = null;
  }

  svg.addEventListener('pointerdown', function (evt) {
    var handle = evt.target.closest ? evt.target.closest('.wf-handle-hit') : null;
    if (!handle || saving) return;
    evt.preventDefault();
    deselect();
    var source = handle.getAttribute('data-source');
    var x1 = parseFloat(handle.getAttribute('cx'));
    var y1 = parseFloat(handle.getAttribute('cy'));
    var line = el('path', { class: 'wf-drag-line', d: 'M' + x1 + ',' + y1 });
    svg.appendChild(line);
    drag = { source: source, x1: x1, y1: y1, line: line, over: null };
    svg.classList.add('wf-dragging');
    // Captured, so the drag keeps following the pointer when it leaves the box
    // it started in - which is the only way it can reach another one.
    // Guarded: capture throws on a pointer the browser no longer considers
    // active (a pen lifted mid-press, some touch edge cases), and losing the
    // capture must never cost somebody the drag itself.
    try {
      if (handle.setPointerCapture) handle.setPointerCapture(evt.pointerId);
    } catch (e) { /* the drag still works; it just stops following off-box */ }
  });

  svg.addEventListener('pointermove', function (evt) {
    if (!drag) return;
    var pt = toSvg(evt);
    var mid = Math.max(18, (pt.x - drag.x1) / 2);
    drag.line.setAttribute('d', 'M' + drag.x1 + ',' + drag.y1
                              + ' C' + (drag.x1 + mid) + ',' + drag.y1
                              + ' ' + (pt.x - mid) + ',' + pt.y + ' ' + pt.x + ',' + pt.y);

    var g = nodeAt(pt);
    var target = g ? g.getAttribute('data-step') : null;
    clearTargets();
    drag.over = null;
    var v = verdict(drag.source, target);
    if (g && target !== drag.source) {
      g.classList.add(v.ok ? 'wf-target-ok' : 'wf-target-bad');
      if (v.ok) drag.over = target;
    }

    if (v.text) {
      hint.textContent = v.text;
      hint.className = 'wf-hint ' + (v.ok ? 'is-ok' : 'is-bad');
      hint.style.left = (evt.clientX + 14) + 'px';
      hint.style.top = (evt.clientY + 14) + 'px';
      hint.hidden = false;
    } else {
      // Still say which way a drag goes while it is over empty space, since
      // that is exactly when somebody is deciding where to let go.
      hint.textContent = 'Drop on the step that should wait for ' + nameOf(drag.source);
      hint.className = 'wf-hint';
      hint.style.left = (evt.clientX + 14) + 'px';
      hint.style.top = (evt.clientY + 14) + 'px';
      hint.hidden = false;
    }
  });

  svg.addEventListener('pointerup', function () {
    if (!drag) return;
    var source = drag.source, target = drag.over;
    endDrag();
    if (!target) return;
    var box = tick(target, source);
    if (!box) return;
    box.checked = true;
    save('wf_add', source, target);
  });

  svg.addEventListener('pointercancel', endDrag);

  // --- removing a line ----------------------------------------------------
  // Two steps, select then remove, rather than one click on a line. The lines
  // are thin and pass under the handles, so a single click would take one out
  // by accident; and on a touchscreen there is no hover to warn first.
  var edges = Array.prototype.slice.call(svg.querySelectorAll('.wf-edge[data-from]'));
  edges.forEach(function (path) {
    var hit = el('path', {
      d: path.getAttribute('d'), class: 'wf-edge-hit',
      'data-from': path.getAttribute('data-from'), 'data-to': path.getAttribute('data-to')
    });
    // ⚠ IMMEDIATELY BEFORE ITS OWN LINE, not gathered in one place. The hover
    // highlight is `.wf-edge-hit:hover + .wf-edge`, which only reaches the very
    // next sibling; parked together before the first box, every hit path's next
    // sibling would have been another hit path and nothing would ever light up.
    // Lines are all drawn before the boxes, so a box still wins a click over a
    // line passing under it.
    path.parentNode.insertBefore(hit, path);
  });

  function deselect() {
    if (!selected) return;
    selected.path.classList.remove('is-selected');
    if (selected.badge.parentNode) selected.badge.parentNode.removeChild(selected.badge);
    selected = null;
  }

  function select(hitPath) {
    deselect();
    var from = hitPath.getAttribute('data-from'), to = hitPath.getAttribute('data-to');
    var path = svg.querySelector('.wf-edge[data-from="' + from + '"][data-to="' + to + '"]');
    if (!path) return;
    path.classList.add('is-selected');

    var len = path.getTotalLength();
    var p = path.getPointAtLength(len / 2);
    var badge = el('g', { class: 'wf-edge-x', transform: 'translate(' + p.x + ',' + p.y + ')' });
    var title = el('title', {});
    title.textContent = 'Remove: ' + nameOf(to) + ' would no longer wait for ' + nameOf(from);
    badge.appendChild(title);
    badge.appendChild(el('circle', { r: 10 }));
    var x = el('text', { 'text-anchor': 'middle', dy: '4.5' });
    x.textContent = '×';
    badge.appendChild(x);
    svg.appendChild(badge);   // last, so it is on top of everything
    selected = { from: from, to: to, path: path, badge: badge };
  }

  function removeSelected() {
    if (!selected || saving) return;
    var box = tick(selected.to, selected.from);
    if (!box) return;
    box.checked = false;
    save('wf_remove', selected.from, selected.to);
  }

  svg.addEventListener('click', function (evt) {
    if (evt.target.closest && evt.target.closest('.wf-edge-x')) { removeSelected(); return; }
    var hit = evt.target.closest ? evt.target.closest('.wf-edge-hit') : null;
    if (hit) { select(hit); return; }
    deselect();
  });

  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape') { endDrag(); deselect(); return; }
    // Delete removes the selected line - but never while somebody is typing in
    // a field, where Backspace means a character.
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (selected && (evt.key === 'Delete' || evt.key === 'Backspace')
        && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
      evt.preventDefault();
      removeSelected();
    }
  });
}());
