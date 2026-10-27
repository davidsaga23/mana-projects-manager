// The review viewer: an image version full screen, with a pen to draw on it.
//
// David, 2026-10-27, showing Frame.io: "can we work on a very basic image
// editor to review new versions online? Something like the attached image
// might work." So: the version big in the middle, the versions of the task to
// step through at the top, zoom, a pen with a few colours and sizes down the
// left, the review notes on that version down the right, and a box to write a
// review in at the bottom.
//
// ⚠ IT IS BUILT FROM THE VERSIONS PANE, NOT FROM ITS OWN ENDPOINT. Everything
// it shows is already in the pane the server drew (includes/board.php,
// board_versions_html()): each version's picture, label and notes, the people
// a review can name, and — only for somebody allowed to review — the review
// form. So who may draw and post is decided where it always was, and a note
// can never look different here from how it looks on the tab.
//
// ⚠ A DRAWING IS POSTED AS A PICTURE ON A REVIEW. The version and the strokes
// are flattened into one JPEG and sent as an attachment through the same
// add_review that the "Make a review" form uses (board_version.php), so the
// artist sees the paint-over wherever review notes already show: on the
// Versions tab, in the timeline, in the email. No new table, no new format to
// keep drawing forever. The cost is that a drawing cannot be edited after it
// is posted, which is also true of a supervisor's paint-over today.
//
// Strokes are kept in the IMAGE's own pixels, not the screen's, so zooming
// mid-drawing keeps them where they were drawn and the export is at the
// version's full resolution.
(function () {
  'use strict';

  var COLORS = ['#ff4d4d', '#ffd400', '#26fe89', '#5fb2ff', '#ffffff', '#111111'];
  // On-screen thickness. Divided by the zoom when a stroke starts, so a line
  // looks as thick as the button promised at whatever zoom it was drawn.
  var SIZES = [{ px: 3, name: 'Thin' }, { px: 7, name: 'Medium' }, { px: 14, name: 'Thick' }];
  var ZOOMS = [0.1, 0.25, 0.35, 0.5, 0.75, 1, 1.5, 2, 3, 4];

  var S = null;   // the open viewer's state; null while closed

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  /** The image versions in the pane, newest first, as the pane lists them. */
  function readVersions(pane) {
    var out = [];
    pane.querySelectorAll('li.version').forEach(function (li) {
      var img = li.querySelector('img.version-img');
      if (!img) return;   // a video or a PDF: not drawable, not listed here
      out.push({
        id: li.id.replace(/^v/, ''),
        label: (li.querySelector('.version-no') || {}).textContent || '',
        current: li.classList.contains('is-current'),
        src: img.getAttribute('src'),
        name: img.getAttribute('alt') || ''
      });
    });
    return out;
  }

  // --- open and close ---------------------------------------------------------

  function open(pane, versionId) {
    if (S) close(true);
    var versions = readVersions(pane);
    var idx = 0;
    versions.forEach(function (v, i) { if (v.id === String(versionId)) idx = i; });
    if (!versions.length) return;

    // Reviewing is offered exactly where the pane offered it: the review form
    // is only drawn for supervisors, Production and admins.
    var reviewForm = pane.querySelector('.board-review-form');

    S = {
      pane: pane, versions: versions, idx: idx, canDraw: !!reviewForm,
      who: reviewForm, img: null, scale: 1, fitted: true,
      strokes: [], live: null, color: COLORS[0], size: SIZES[1].px, pen: !!reviewForm
    };
    build();
    document.body.classList.add('rv-open');
    load();
  }

  function close(force) {
    if (!S) return;
    if (!force && S.strokes.length
        && !window.confirm('Close the viewer? The drawing has not been posted and will be lost.')) return;
    S.root.remove();
    document.body.classList.remove('rv-open');
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', onResize);
    S = null;
  }

  // --- the frame --------------------------------------------------------------

  function build() {
    var root = el('div', 'rv');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Review viewer');

    // Top bar: close, the file, which version, zoom.
    var top = el('div', 'rv-top');
    var x = el('button', 'rv-btn rv-close', '←');
    x.type = 'button';
    x.title = 'Back to the task (Esc)';
    x.addEventListener('click', function () { close(false); });
    top.appendChild(x);

    var title = el('div', 'rv-title');
    S.nameEl = el('strong', 'rv-name');
    title.appendChild(S.nameEl);
    top.appendChild(title);

    var nav = el('div', 'rv-nav');
    var prev = el('button', 'rv-btn', '‹');
    prev.type = 'button';
    prev.title = 'Older version (←)';
    prev.addEventListener('click', function () { step(1); });
    S.pick = el('select', 'rv-pick');
    S.pick.setAttribute('aria-label', 'Which version');
    S.versions.forEach(function (v, i) {
      var o = el('option', '', v.label + (v.current ? ' (current)' : ''));
      o.value = String(i);
      S.pick.appendChild(o);
    });
    S.pick.addEventListener('change', function () { go(parseInt(S.pick.value, 10)); });
    var next = el('button', 'rv-btn', '›');
    next.type = 'button';
    next.title = 'Newer version (→)';
    next.addEventListener('click', function () { step(-1); });
    nav.appendChild(prev); nav.appendChild(S.pick); nav.appendChild(next);
    top.appendChild(nav);

    var zoom = el('div', 'rv-zoom');
    var out = el('button', 'rv-btn', '−'); out.type = 'button'; out.title = 'Zoom out (-)';
    out.addEventListener('click', function () { zoomStep(-1); });
    S.pct = el('span', 'rv-pct', '100%');
    var inn = el('button', 'rv-btn', '+'); inn.type = 'button'; inn.title = 'Zoom in (+)';
    inn.addEventListener('click', function () { zoomStep(1); });
    var fit = el('button', 'rv-btn rv-fit', 'Fit'); fit.type = 'button'; fit.title = 'Fit to the window (0)';
    fit.addEventListener('click', function () { S.fitted = true; layout(); });
    var full = el('button', 'rv-btn rv-fit', '100%'); full.type = 'button'; full.title = 'Actual size (1)';
    full.addEventListener('click', function () { setScale(1); });
    zoom.appendChild(out); zoom.appendChild(S.pct); zoom.appendChild(inn);
    zoom.appendChild(fit); zoom.appendChild(full);
    top.appendChild(zoom);

    root.appendChild(top);

    // Body: tools | the picture | the notes.
    var body = el('div', 'rv-body');

    if (S.canDraw) body.appendChild(buildTools());

    S.stage = el('div', 'rv-stage');
    S.canvas = el('canvas', 'rv-canvas');
    S.stage.appendChild(S.canvas);
    S.status = el('p', 'rv-loading muted', 'Loading…');
    S.stage.appendChild(S.status);
    body.appendChild(S.stage);

    var side = el('aside', 'rv-side');
    S.notesTitle = el('h3', 'rv-side-title', 'Review notes');
    side.appendChild(S.notesTitle);
    S.notes = el('div', 'rv-notes');
    side.appendChild(S.notes);
    body.appendChild(side);

    root.appendChild(body);

    if (S.canDraw) root.appendChild(buildCompose());

    document.body.appendChild(root);
    S.root = root;

    S.canvas.addEventListener('pointerdown', penDown);
    S.canvas.addEventListener('pointermove', penMove);
    S.canvas.addEventListener('pointerup', penUp);
    S.canvas.addEventListener('pointercancel', penUp);
    // Ctrl + wheel zooms, plain wheel scrolls the picture as any page would.
    S.stage.addEventListener('wheel', function (e) {
      if (!e.ctrlKey) return;
      e.preventDefault();
      zoomStep(e.deltaY < 0 ? 1 : -1);
    }, { passive: false });
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', onResize);
  }

  function buildTools() {
    var bar = el('div', 'rv-tools');
    bar.setAttribute('aria-label', 'Drawing tools');

    S.penBtn = el('button', 'rv-tool is-on', '✎');
    S.penBtn.type = 'button';
    S.penBtn.title = 'Pen — draw on the version (P)';
    S.penBtn.addEventListener('click', function () { setPen(!S.pen); });
    bar.appendChild(S.penBtn);

    bar.appendChild(el('span', 'rv-tools-gap'));

    S.swatches = [];
    COLORS.forEach(function (c) {
      var b = el('button', 'rv-swatch' + (c === S.color ? ' is-on' : ''));
      b.type = 'button';
      b.style.background = c;
      b.title = 'Draw in this colour';
      b.setAttribute('aria-label', 'Colour ' + c);
      b.addEventListener('click', function () {
        S.color = c;
        S.swatches.forEach(function (s) { s.classList.toggle('is-on', s === b); });
        setPen(true);
      });
      S.swatches.push(b);
      bar.appendChild(b);
    });

    bar.appendChild(el('span', 'rv-tools-gap'));

    S.sizeBtns = [];
    SIZES.forEach(function (sz) {
      var b = el('button', 'rv-tool rv-size' + (sz.px === S.size ? ' is-on' : ''));
      b.type = 'button';
      b.title = sz.name + ' line';
      var dot = el('span', 'rv-size-dot');
      dot.style.width = dot.style.height = Math.min(sz.px + 2, 16) + 'px';
      b.appendChild(dot);
      b.addEventListener('click', function () {
        S.size = sz.px;
        S.sizeBtns.forEach(function (s) { s.classList.toggle('is-on', s === b); });
        setPen(true);
      });
      S.sizeBtns.push(b);
      bar.appendChild(b);
    });

    bar.appendChild(el('span', 'rv-tools-gap'));

    var undo = el('button', 'rv-tool', '↶');
    undo.type = 'button';
    undo.title = 'Undo the last line (Ctrl+Z)';
    undo.addEventListener('click', undoStroke);
    bar.appendChild(undo);

    var clear = el('button', 'rv-tool', '🗑');
    clear.type = 'button';
    clear.title = 'Clear the drawing';
    clear.addEventListener('click', function () {
      if (!S.strokes.length) return;
      if (window.confirm('Clear the whole drawing?')) { S.strokes = []; paint(); drawState(); }
    });
    bar.appendChild(clear);
    return bar;
  }

  function buildCompose() {
    var f = el('form', 'rv-compose');
    S.box = el('textarea', 'rv-box');
    S.box.rows = 2;
    S.box.placeholder = 'Write your review… type @ to name somebody';
    f.appendChild(S.box);

    var row = el('div', 'rv-compose-row');
    S.drawSay = el('span', 'muted small rv-draw-say', '');
    row.appendChild(S.drawSay);
    S.err = el('span', 'err small rv-err', '');
    row.appendChild(S.err);
    S.post = el('button', 'primary rv-post', 'Post review');
    S.post.type = 'submit';
    row.appendChild(S.post);
    f.appendChild(row);

    f.addEventListener('submit', function (e) { e.preventDefault(); post(); });

    // The same @ picker as every other box on the task, offered the same people.
    var json = S.pane.querySelector('.board-comment-people');
    if (json && window.animaMentions) {
      var people = [];
      try { people = JSON.parse(json.textContent || '[]'); } catch (err) { people = []; }
      window.animaMentions.attach(S.box, people);
    }
    return f;
  }

  // --- the version on screen ----------------------------------------------------

  function load() {
    var v = S.versions[S.idx];
    S.pick.value = String(S.idx);
    S.nameEl.textContent = v.name;
    S.nameEl.title = v.name;
    S.notesTitle.textContent = 'Review notes on ' + v.label;
    showNotes();
    S.status.hidden = false;
    S.status.textContent = 'Loading ' + v.label + '…';
    S.canvas.hidden = true;

    var img = new Image();
    img.onload = function () {
      if (!S || S.versions[S.idx] !== v) return;   // moved on while it loaded
      S.img = img;
      S.canvas.width = img.naturalWidth;
      S.canvas.height = img.naturalHeight;
      S.canvas.hidden = false;
      S.status.hidden = true;
      layout();
      paint();
    };
    img.onerror = function () {
      if (S) S.status.textContent = 'That version could not be loaded. It may be missing from the server.';
    };
    img.src = v.src;
    drawState();
  }

  /** The notes on this version, as the pane drew them. */
  function showNotes() {
    var v = S.versions[S.idx];
    var src = S.pane.querySelector('#vr' + v.id);
    S.notes.innerHTML = '';
    if (!src) {
      S.notes.appendChild(el('p', 'muted small', 'No review notes on ' + v.label + ' yet.'));
      return;
    }
    var copy = src.cloneNode(true);
    copy.hidden = false;
    copy.removeAttribute('id');
    var t = copy.querySelector('.version-reviews-title');
    if (t) t.remove();
    // Ids belong to the pane's copy; two of each would confuse a #link.
    copy.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
    S.notes.appendChild(copy);
    S.notes.scrollTop = S.notes.scrollHeight;
  }

  function go(i) {
    if (!S || i < 0 || i >= S.versions.length || i === S.idx) { if (S) S.pick.value = String(S.idx); return; }
    // A drawing belongs to the version it was drawn on.
    if (S.strokes.length
        && !window.confirm('Switch version? The drawing on this one has not been posted and will be lost.')) {
      S.pick.value = String(S.idx);
      return;
    }
    S.strokes = [];
    S.idx = i;
    S.fitted = true;
    load();
  }

  /** +1 is older (further down the list), -1 is newer. */
  function step(d) { go(S.idx + d); }

  // --- zoom -------------------------------------------------------------------------

  function fitScale() {
    var w = S.stage.clientWidth - 32, h = S.stage.clientHeight - 32;
    return Math.max(0.05, Math.min(w / S.img.naturalWidth, h / S.img.naturalHeight));
  }

  function layout() {
    if (!S || !S.img) return;
    if (S.fitted) S.scale = fitScale();
    S.canvas.style.width = Math.round(S.img.naturalWidth * S.scale) + 'px';
    S.canvas.style.height = Math.round(S.img.naturalHeight * S.scale) + 'px';
    S.pct.textContent = Math.round(S.scale * 100) + '%';
  }

  function setScale(s) {
    if (!S || !S.img) return;
    // Keep the middle of what you were looking at in the middle.
    var st = S.stage;
    var cx = (st.scrollLeft + st.clientWidth / 2) / S.scale;
    var cy = (st.scrollTop + st.clientHeight / 2) / S.scale;
    S.fitted = false;
    S.scale = Math.max(0.05, Math.min(8, s));
    layout();
    st.scrollLeft = cx * S.scale - st.clientWidth / 2;
    st.scrollTop = cy * S.scale - st.clientHeight / 2;
  }

  function zoomStep(d) {
    if (!S || !S.img) return;
    var cur = S.scale, nextZ = cur;
    if (d > 0) { for (var i = 0; i < ZOOMS.length; i++) if (ZOOMS[i] > cur + 0.001) { nextZ = ZOOMS[i]; break; } }
    else { for (var j = ZOOMS.length - 1; j >= 0; j--) if (ZOOMS[j] < cur - 0.001) { nextZ = ZOOMS[j]; break; } }
    setScale(nextZ);
  }

  function onResize() { if (S && S.fitted) layout(); }

  // --- the pen ------------------------------------------------------------------------

  function setPen(on) {
    if (!S || !S.canDraw) return;
    S.pen = on;
    if (S.penBtn) S.penBtn.classList.toggle('is-on', on);
    S.canvas.classList.toggle('is-pen', on);
  }

  function toImage(e) {
    var r = S.canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) / r.width * S.canvas.width,
      (e.clientY - r.top) / r.height * S.canvas.height
    ];
  }

  function penDown(e) {
    if (!S || !S.pen || !S.img || e.button !== 0) return;
    e.preventDefault();
    S.canvas.setPointerCapture(e.pointerId);
    S.live = { color: S.color, width: S.size / S.scale, pts: [toImage(e)] };
    S.strokes.push(S.live);
    paint();
  }

  function penMove(e) {
    if (!S || !S.live) return;
    var pts = S.live.pts, a = pts[pts.length - 1], b = toImage(e);
    pts.push(b);
    // Only the new piece of line, not the whole picture again: repainting a 4K
    // version on every mouse move is what makes a pen lag behind the hand.
    var c = S.canvas.getContext('2d');
    c.strokeStyle = S.live.color;
    c.lineWidth = S.live.width;
    c.lineCap = 'round';
    c.beginPath();
    c.moveTo(a[0], a[1]);
    c.lineTo(b[0], b[1]);
    c.stroke();
  }

  function penUp() {
    if (!S || !S.live) return;
    S.live = null;
    drawState();
  }

  function undoStroke() {
    if (!S || !S.strokes.length) return;
    S.strokes.pop();
    paint();
    drawState();
  }

  /** The version, then every stroke on top, at the version's own resolution. */
  function paint() {
    if (!S || !S.img) return;
    var c = S.canvas.getContext('2d');
    c.drawImage(S.img, 0, 0);
    c.lineCap = 'round';
    c.lineJoin = 'round';
    S.strokes.forEach(function (s) {
      c.strokeStyle = s.color;
      c.fillStyle = s.color;
      c.lineWidth = s.width;
      if (s.pts.length === 1) {   // a click is a dot, not nothing
        c.beginPath();
        c.arc(s.pts[0][0], s.pts[0][1], s.width / 2, 0, Math.PI * 2);
        c.fill();
        return;
      }
      c.beginPath();
      c.moveTo(s.pts[0][0], s.pts[0][1]);
      for (var i = 1; i < s.pts.length; i++) c.lineTo(s.pts[i][0], s.pts[i][1]);
      c.stroke();
    });
  }

  function drawState() {
    if (!S || !S.drawSay) return;
    var n = S.strokes.length;
    S.drawSay.textContent = n
      ? 'Your drawing goes with the review as a picture.'
      : 'Draw on the version with the pen to show what to change.';
  }

  // --- posting ------------------------------------------------------------------------

  function post() {
    if (!S || !S.who) return;
    var text = S.box.value.trim();
    var drawn = S.strokes.length > 0;
    S.err.textContent = '';
    if (!text && !drawn) { S.box.focus(); S.err.textContent = 'Write something or draw on the version first.'; return; }

    S.post.disabled = true;
    S.post.textContent = 'Posting…';
    var v = S.versions[S.idx];

    var send = function (blob) {
      var data = new FormData();
      data.append('add_review', '1');
      data.append('version_id', v.id);
      data.append('body', text);
      data.append('project_id', S.who.getAttribute('data-project'));
      data.append('step_id', S.who.getAttribute('data-step'));
      data.append('item_key', S.who.getAttribute('data-item'));
      if (blob) {
        var base = v.name.replace(/\.[^.]+$/, '') || v.label;
        data.append('attachments[]', blob, base + '_review.jpg');
      }

      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'board_version.php', true);
      xhr.addEventListener('load', function () {
        if (!S) return;
        if (xhr.status < 200 || xhr.status >= 300) {
          failed('The server refused that (HTTP ' + xhr.status + ').');
          return;
        }
        posted(xhr.responseText, v.id);
      });
      xhr.addEventListener('error', function () {
        failed('That did not go through. Check your connection and try again.');
      });
      xhr.send(data);
    };

    if (!drawn) { send(null); return; }
    // JPEG, not PNG: a 4K paint-over as PNG is tens of megabytes for nothing
    // a reviewer can see. 0.9 keeps line edges clean.
    S.canvas.toBlob(function (blob) {
      if (!blob) { failed('The drawing could not be saved as a picture.'); return; }
      send(blob);
    }, 'image/jpeg', 0.9);
  }

  function failed(why) {
    if (!S) return;
    S.post.disabled = false;
    S.post.textContent = 'Post review';
    S.err.textContent = why + ' Your text and drawing are still here.';
  }

  /** Put the pane the server sent back in place, then show this version's new notes. */
  function posted(html, versionId) {
    var pane = S.pane;
    pane.innerHTML = html;
    if (window.animaBoard) {
      window.animaBoard.paneReady('versions');
      window.animaBoard.timelineRefresh();
    }
    // The endpoint answers 200 with its own red line for anything it refused.
    var bad = pane.querySelector(':scope > p.err');
    if (bad) { failed(bad.textContent); return; }

    S.who = pane.querySelector('.board-review-form');
    S.versions = readVersions(pane);
    S.versions.forEach(function (x, i) { if (x.id === String(versionId)) S.idx = i; });
    S.strokes = [];
    S.box.value = '';
    S.post.disabled = false;
    S.post.textContent = 'Post review';
    paint();
    drawState();
    showNotes();
  }

  // --- keys -------------------------------------------------------------------------

  function onKey(e) {
    if (!S) return;
    var typing = e.target && (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT'
                              || e.target.tagName === 'SELECT');
    if (e.key === 'Escape') {
      // The lightbox over a note's picture closes first.
      var lb = document.querySelector('.lightbox.is-open');
      if (lb) return;
      e.preventDefault();
      close(false);
      return;
    }
    if (typing) return;
    if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); undoStroke(); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); step(1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); step(-1); }
    else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomStep(1); }
    else if (e.key === '-') { e.preventDefault(); zoomStep(-1); }
    else if (e.key === '0') { S.fitted = true; layout(); }
    else if (e.key === '1') { setScale(1); }
    else if (e.key === 'p' || e.key === 'P') { setPen(!S.pen); }
  }

  // --- the way in ---------------------------------------------------------------------

  // Clicking an image version, or its "Review" button. Delegated, because the
  // pane is re-drawn after every post. Ctrl- and middle-click still open the
  // file on its own, the way the link underneath always did.
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-review-open]') : null;
    if (!t || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    var pane = t.closest('[data-pane]');
    if (!pane) return;
    e.preventDefault();
    open(pane, t.getAttribute('data-review-open'));
  });

  window.animaReviewViewer = { open: open, close: close };
}());
