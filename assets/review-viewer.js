// The review viewer: a version full screen, with a pen to draw on it.
//
// David, 2026-10-27, showing Frame.io: "can we work on a very basic image
// editor to review new versions online? Something like the attached image
// might work." So: the version big in the middle, the versions of the task to
// step through at the top, zoom, a pen with a few colours and sizes down the
// left, the review notes on that version down the right, and a box to write a
// review in at the bottom.
//
// Then, the same day: "can you do a better preview for video? Like the option
// to go frame by frame, 2x, 3x, etc". So a video opens here too, with a
// transport under it: play, a frame back and forward, a scrubber, the frame
// number and timecode, speeds from a quarter to four times, and loop. The pen
// draws on a paused frame, and the review says which frame it was.
//
// ⚠ IT IS BUILT FROM THE VERSIONS PANE, NOT FROM ITS OWN ENDPOINT. Everything
// it shows is already in the pane the server drew (includes/board.php,
// board_versions_html()): each version's file, label and notes, the people a
// review can name, and — only for somebody allowed to review — the review
// form. So who may draw and post is decided where it always was, and a note
// can never look different here from how it looks on the tab.
//
// ⚠ A DRAWING IS POSTED AS A PICTURE ON A REVIEW. The frame and the strokes
// are flattened into one JPEG and sent as an attachment through the same
// add_review that the "Make a review" form uses (board_version.php), so the
// artist sees the paint-over wherever review notes already show: on the
// Versions tab, in the timeline, in the email. No new table, no new format to
// keep drawing forever. The cost is that a drawing cannot be edited after it
// is posted, which is also true of a supervisor's paint-over today.
//
// ⚠ THE FRAME RATE IS ASKED, NOT READ. A browser can play a video but will not
// say how many frames a second it has, and the server has no ffmpeg to ask.
// So it is a picker, 24 to start (Mana's shows are 24), remembered in this
// browser. A wrong rate makes a frame step land between frames, which is why
// it sits right beside the counter it affects.
//
// Strokes live on a transparent canvas laid over the picture, in the
// version's OWN pixels, not the screen's: zooming mid-drawing keeps them where
// they were drawn, and the export is at the version's full resolution.
(function () {
  'use strict';

  var COLORS = ['#ff4d4d', '#ffd400', '#26fe89', '#5fb2ff', '#ffffff', '#111111'];
  // On-screen thickness. Divided by the zoom when a stroke starts, so a line
  // looks as thick as the button promised at whatever zoom it was drawn.
  var SIZES = [{ px: 3, name: 'Thin' }, { px: 7, name: 'Medium' }, { px: 14, name: 'Thick' }];
  var ZOOMS = [0.1, 0.25, 0.35, 0.5, 0.75, 1, 1.5, 2, 3, 4];
  var SPEEDS = [0.25, 0.5, 1, 1.5, 2, 3, 4];
  var RATES = [12, 23.976, 24, 25, 29.97, 30, 48, 50, 60];
  var RATE_KEY = 'anima.reviewFps';

  var S = null;   // the open viewer's state; null while closed

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function btn(cls, text, title, fn) {
    var b = el('button', cls, text);
    b.type = 'button';
    if (title) b.title = title;
    if (fn) b.addEventListener('click', fn);
    return b;
  }

  function savedRate() {
    try {
      var r = parseFloat(window.localStorage.getItem(RATE_KEY));
      if (RATES.indexOf(r) !== -1) return r;
    } catch (err) { /* private window: the default is fine */ }
    return 24;
  }

  /** The pictures and videos in the pane, newest first, as the pane lists them. */
  function readVersions(pane) {
    var out = [];
    pane.querySelectorAll('li.version').forEach(function (li) {
      var img = li.querySelector('img.version-img');
      var vid = li.querySelector('video.version-vid');
      if (!img && !vid) return;   // a PDF: nothing to show here
      out.push({
        id: li.id.replace(/^v/, ''),
        label: (li.querySelector('.version-no') || {}).textContent || '',
        current: li.classList.contains('is-current'),
        video: !!vid,
        src: (img || vid).getAttribute('src'),
        name: (img ? img.getAttribute('alt') : vid.getAttribute('data-name')) || ''
      });
    });
    return out;
  }

  // --- open and close ---------------------------------------------------------

  /**
   * opts.actions, optional: verdict buttons for the top bar, each
   * { label, cls, title, run }. The Reviews screen passes Approve and Retake
   * (David, 2026-10-29), so a verdict can be given without leaving the
   * picture; Spell passes none. run() is the caller's: this file only says
   * when a review is still being written, and closes before it runs.
   */
  function open(pane, versionId, opts) {
    if (S) close(true);
    var versions = readVersions(pane);
    if (!versions.length) return;
    var idx = 0;
    versions.forEach(function (v, i) { if (v.id === String(versionId)) idx = i; });

    // The little inline players in the pane would go on talking underneath.
    pane.querySelectorAll('video').forEach(function (v) { v.pause(); });

    // Reviewing is offered exactly where the pane offered it: the review form
    // is only drawn for supervisors, Production and admins.
    var reviewForm = pane.querySelector('.board-review-form');

    S = {
      pane: pane, versions: versions, idx: idx, canDraw: !!reviewForm,
      who: reviewForm, media: null, w: 0, h: 0, scale: 1, fitted: true,
      strokes: [], live: null, drawFrame: null,
      color: COLORS[0], size: SIZES[1].px, pen: !!reviewForm,
      fps: savedRate(), speed: 1, loop: true,
      actions: (opts && opts.actions) || []
    };
    build();
    document.body.classList.add('rv-open');
    load();
  }

  function close(force) {
    if (!S) return;
    if (!force && unposted()
        && !window.confirm('Close the viewer? The review has not been posted and will be lost.')) return;
    stopMedia();
    S.root.remove();
    document.body.classList.remove('rv-open');
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', onResize);
    S = null;
  }

  /** Is a review half written: a drawing, text in the box, or files waiting? */
  function unposted() {
    if (!S) return false;
    return S.strokes.length > 0 || (S.box && S.box.value.trim() !== '') || pickedFiles().length > 0;
  }

  /** The files going with the review, kept by assets/review-files.js. */
  function pickedFiles() {
    if (!S || !S.form) return [];
    if (window.animaReviewFiles) return window.animaReviewFiles.files(S.form);
    var input = S.form.querySelector('input[type="file"]');
    return input ? Array.prototype.slice.call(input.files || []) : [];
  }

  /** Let go of the video, so it stops downloading as well as playing. */
  function stopMedia() {
    if (S && S.media && S.media.tagName === 'VIDEO') {
      S.media.pause();
      S.media.removeAttribute('src');
      S.media.load();
    }
  }

  // --- the frame --------------------------------------------------------------

  function build() {
    var root = el('div', 'rv');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Review viewer');

    // Top bar: close, the file, which version, zoom.
    var top = el('div', 'rv-top');
    top.appendChild(btn('rv-btn rv-close', '←', 'Back to the task (Esc)', function () { close(false); }));

    var title = el('div', 'rv-title');
    S.nameEl = el('strong', 'rv-name');
    title.appendChild(S.nameEl);
    top.appendChild(title);

    var nav = el('div', 'rv-nav');
    nav.appendChild(btn('rv-btn', '‹', 'Older version ([)', function () { go(S.idx + 1); }));
    S.pick = el('select', 'rv-pick');
    S.pick.setAttribute('aria-label', 'Which version');
    S.versions.forEach(function (v, i) {
      var o = el('option', '', v.label + (v.current ? ' (current)' : ''));
      o.value = String(i);
      S.pick.appendChild(o);
    });
    S.pick.addEventListener('change', function () { go(parseInt(S.pick.value, 10)); });
    nav.appendChild(S.pick);
    nav.appendChild(btn('rv-btn', '›', 'Newer version (])', function () { go(S.idx - 1); }));
    top.appendChild(nav);

    var zoom = el('div', 'rv-zoom');
    zoom.appendChild(btn('rv-btn', '−', 'Zoom out (-)', function () { zoomStep(-1); }));
    S.pct = el('span', 'rv-pct', '100%');
    zoom.appendChild(S.pct);
    zoom.appendChild(btn('rv-btn', '+', 'Zoom in (+)', function () { zoomStep(1); }));
    zoom.appendChild(btn('rv-btn rv-fit', 'Fit', 'Fit to the window (0)', function () { S.fitted = true; layout(); }));
    zoom.appendChild(btn('rv-btn rv-fit', '100%', 'Actual size (1)', function () { setScale(1); }));
    top.appendChild(zoom);

    if (S.actions.length) {
      var verdict = el('div', 'rv-verdict');
      S.actions.forEach(function (a) {
        verdict.appendChild(btn('rv-verdict-btn ' + (a.cls || ''), a.label, a.title || '', function () {
          if (unposted() && !window.confirm('The review you are writing has not been posted and will be lost. '
              + a.label + ' anyway?')) return;
          close(true);
          a.run();
        }));
      });
      top.appendChild(verdict);
    }

    root.appendChild(top);

    // Body: tools | the picture and its transport | the notes.
    var body = el('div', 'rv-body');
    if (S.canDraw) body.appendChild(buildTools());

    var center = el('div', 'rv-center');
    S.stage = el('div', 'rv-stage');
    S.frame = el('div', 'rv-frame');
    S.canvas = el('canvas', 'rv-canvas');
    S.frame.appendChild(S.canvas);
    S.stage.appendChild(S.frame);
    S.status = el('p', 'rv-loading muted', 'Loading…');
    S.stage.appendChild(S.status);
    center.appendChild(S.stage);
    S.transport = buildTransport();
    center.appendChild(S.transport);
    body.appendChild(center);

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

    S.penBtn = btn('rv-tool is-on', '✎', 'Pen — draw on the version (P)', function () { setPen(!S.pen); });
    bar.appendChild(S.penBtn);
    bar.appendChild(el('span', 'rv-tools-gap'));

    S.swatches = [];
    COLORS.forEach(function (c) {
      var b = btn('rv-swatch' + (c === S.color ? ' is-on' : ''), undefined, 'Draw in this colour', function () {
        S.color = c;
        S.swatches.forEach(function (s) { s.classList.toggle('is-on', s === b); });
        setPen(true);
      });
      b.style.background = c;
      b.setAttribute('aria-label', 'Colour ' + c);
      S.swatches.push(b);
      bar.appendChild(b);
    });
    bar.appendChild(el('span', 'rv-tools-gap'));

    S.sizeBtns = [];
    SIZES.forEach(function (sz) {
      var b = btn('rv-tool rv-size' + (sz.px === S.size ? ' is-on' : ''), undefined, sz.name + ' line', function () {
        S.size = sz.px;
        S.sizeBtns.forEach(function (s) { s.classList.toggle('is-on', s === b); });
        setPen(true);
      });
      var dot = el('span', 'rv-size-dot');
      dot.style.width = dot.style.height = Math.min(sz.px + 2, 16) + 'px';
      b.appendChild(dot);
      S.sizeBtns.push(b);
      bar.appendChild(b);
    });
    bar.appendChild(el('span', 'rv-tools-gap'));

    bar.appendChild(btn('rv-tool', '↶', 'Undo the last line (Ctrl+Z)', undoStroke));
    bar.appendChild(btn('rv-tool', '🗑', 'Clear the drawing', function () {
      if (!S.strokes.length) return;
      if (window.confirm('Clear the whole drawing?')) clearDrawing();
    }));
    return bar;
  }

  /** Play, step, scrub, speed, loop, frame rate. Hidden for a picture. */
  function buildTransport() {
    var t = el('div', 'rv-transport');
    t.hidden = true;

    S.scrub = el('input', 'rv-scrub');
    S.scrub.type = 'range';
    S.scrub.min = '0';
    S.scrub.step = '1';
    S.scrub.value = '0';
    S.scrub.setAttribute('aria-label', 'Frame');
    S.scrub.addEventListener('input', function () { pause(); seekFrame(parseInt(S.scrub.value, 10)); });
    t.appendChild(S.scrub);

    var row = el('div', 'rv-transport-row');
    row.appendChild(btn('rv-btn', '⏮', 'First frame (Home)', function () { pause(); seekFrame(0); }));
    row.appendChild(btn('rv-btn', '◀︎|', 'One frame back (← or ,)', function () { stepFrame(-1); }));
    S.playBtn = btn('rv-btn rv-play', '▶', 'Play / pause (Space)', togglePlay);
    row.appendChild(S.playBtn);
    row.appendChild(btn('rv-btn', '|▶︎', 'One frame forward (→ or .)', function () { stepFrame(1); }));
    row.appendChild(btn('rv-btn', '⏭', 'Last frame (End)', function () { pause(); seekFrame(lastFrame()); }));

    S.counter = el('span', 'rv-counter', '');
    row.appendChild(S.counter);

    row.appendChild(el('span', 'rv-transport-gap'));

    S.speedBtns = [];
    var speeds = el('div', 'rv-speeds');
    speeds.setAttribute('aria-label', 'Playback speed');
    SPEEDS.forEach(function (sp) {
      var b = btn('rv-speed' + (sp === S.speed ? ' is-on' : ''), (sp < 1 ? String(sp).replace(/^0/, '') : sp) + '×',
                  'Play at ' + sp + '×', function () { setSpeed(sp); });
      b.setAttribute('data-speed', String(sp));
      S.speedBtns.push(b);
      speeds.appendChild(b);
    });
    row.appendChild(speeds);

    S.loopBtn = btn('rv-btn rv-loop is-on', '⟲', 'Loop (L)', function () { setLoop(!S.loop); });
    row.appendChild(S.loopBtn);

    S.muteBtn = btn('rv-btn', '🔇', 'Sound on / off (M)', toggleMute);
    row.appendChild(S.muteBtn);

    var rate = el('label', 'rv-rate');
    S.rate = el('select', 'rv-pick');
    S.rate.title = 'Frames per second. The browser cannot read it from the file, so say it here.';
    RATES.forEach(function (r) {
      var o = el('option', '', String(r));
      o.value = String(r);
      if (r === S.fps) o.selected = true;
      S.rate.appendChild(o);
    });
    S.rate.addEventListener('change', function () {
      S.fps = parseFloat(S.rate.value);
      try { window.localStorage.setItem(RATE_KEY, String(S.fps)); } catch (err) { /* fine */ }
      // A drawing is pinned to a frame NUMBER, which a new rate would quietly
      // move to another picture.
      if (S.strokes.length) clearDrawing();
      frameMeta();
      tick();
    });
    rate.appendChild(S.rate);
    rate.appendChild(document.createTextNode(' fps'));
    row.appendChild(rate);

    t.appendChild(row);
    return t;
  }

  function buildCompose() {
    // ⚠ board-review-form / board-review-box / board-review-thumbs ARE
    // assets/review-files.js's hooks: pasting an image into the box, picking
    // or dropping files on the form, and the thumbnails with an ×, exactly as
    // the "Make a review" form in the panel does it (David, 2026-10-29: "the
    // option to attach images the same way the side panel works in spell").
    var f = el('form', 'rv-compose board-review-form');
    S.form = f;
    S.box = el('textarea', 'rv-box board-review-box');
    S.box.rows = 2;
    S.box.placeholder = 'Write your review… paste an image straight in, type @ to name somebody';
    f.appendChild(S.box);

    var thumbs = el('div', 'board-review-thumbs');
    thumbs.hidden = true;
    f.appendChild(thumbs);

    var row = el('div', 'rv-compose-row');
    var attach = el('label', 'rv-attach', 'Attach files');
    attach.title = 'Pictures or files to go with the review. You can also paste or drop them here.';
    var input = el('input');
    input.type = 'file';
    input.name = 'attachments[]';
    input.multiple = true;
    input.setAttribute('data-no-drop', '');   // review-files.js keeps the list, not dropzone.js
    attach.appendChild(input);
    row.appendChild(attach);
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
    stopMedia();
    if (S.media) S.media.remove();
    S.media = null;
    S.pick.value = String(S.idx);
    S.nameEl.textContent = v.name;
    S.nameEl.title = v.name;
    S.notesTitle.textContent = 'Review notes on ' + v.label;
    showNotes();
    S.status.hidden = false;
    S.status.textContent = 'Loading ' + v.label + '…';
    S.frame.hidden = true;
    S.transport.hidden = !v.video;

    var m;
    var ready = function (w, h) {
      if (!S || S.media !== m) return;   // moved on while it loaded
      S.w = w;
      S.h = h;
      S.canvas.width = w;
      S.canvas.height = h;
      S.frame.hidden = false;
      S.status.hidden = true;
      layout();
      paint();
      if (v.video) { frameMeta(); tick(); }
    };

    if (v.video) {
      m = document.createElement('video');
      m.className = 'rv-media';
      m.preload = 'auto';
      m.playsInline = true;
      m.muted = true;   // sound is one click away; a review room is often shared
      m.loop = S.loop;
      m.playbackRate = S.speed;
      m.addEventListener('loadedmetadata', function () { ready(m.videoWidth, m.videoHeight); });
      m.addEventListener('play', onPlayState);
      m.addEventListener('pause', onPlayState);
      m.addEventListener('seeked', tick);
      // Also while playing: requestVideoFrameCallback goes quiet in a tab the
      // browser is not drawing, and the counter must not freeze with it.
      m.addEventListener('timeupdate', function () { tick(); });
      m.addEventListener('ended', onPlayState);
      m.addEventListener('error', function () {
        if (S && S.media === m) {
          S.status.textContent = 'This browser cannot play that file. A .mov in ProRes is the usual '
            + 'reason: export it as an H.264 .mp4 and upload that.';
        }
      });
    } else {
      m = new Image();
      m.className = 'rv-media';
      m.alt = v.name;
      m.addEventListener('load', function () { ready(m.naturalWidth, m.naturalHeight); });
      m.addEventListener('error', function () {
        if (S && S.media === m) S.status.textContent = 'That version could not be loaded. It may be missing from the server.';
      });
    }
    S.media = m;
    S.frame.insertBefore(m, S.canvas);
    m.src = v.src;
    drawState();
    muteLabel();
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
    S.drawFrame = null;
    S.idx = i;
    S.fitted = true;
    load();
  }

  function isVideo() { return !!(S && S.media && S.media.tagName === 'VIDEO'); }

  // --- the transport --------------------------------------------------------------
  //
  // Frame N is the picture shown from N/fps to (N+1)/fps. A seek aims at the
  // MIDDLE of that span, because aiming at its first instant lands on the
  // previous frame as often as not once the float rounding is done.

  function frameAt(t) { return Math.max(0, Math.floor(t * S.fps + 0.001)); }
  function curFrame() { return isVideo() ? frameAt(S.media.currentTime) : 0; }
  function lastFrame() {
    var d = isVideo() ? S.media.duration : 0;
    return isFinite(d) && d > 0 ? Math.max(0, Math.ceil(d * S.fps) - 1) : 0;
  }

  function seekFrame(n) {
    if (!isVideo()) return;
    n = Math.max(0, Math.min(lastFrame(), n));
    S.media.currentTime = (n + 0.5) / S.fps;
    tick(n);
  }

  function stepFrame(d) {
    if (!isVideo()) return;
    pause();
    seekFrame(curFrame() + d);
  }

  function pause() { if (isVideo() && !S.media.paused) S.media.pause(); }

  function togglePlay() {
    if (!isVideo()) return;
    if (S.media.paused) {
      if (S.media.ended || curFrame() >= lastFrame()) S.media.currentTime = 0;
      var p = S.media.play();
      if (p && p.catch) p.catch(function () {});
    } else {
      S.media.pause();
    }
  }

  function setSpeed(sp) {
    S.speed = sp;
    if (isVideo()) S.media.playbackRate = sp;
    S.speedBtns.forEach(function (b) { b.classList.toggle('is-on', parseFloat(b.getAttribute('data-speed')) === sp); });
  }

  function setLoop(on) {
    S.loop = on;
    if (isVideo()) S.media.loop = on;
    S.loopBtn.classList.toggle('is-on', on);
  }

  function toggleMute() {
    if (!isVideo()) return;
    S.media.muted = !S.media.muted;
    muteLabel();
  }

  function muteLabel() {
    if (!S.muteBtn) return;
    var muted = !isVideo() || S.media.muted;
    S.muteBtn.textContent = muted ? '🔇' : '🔊';
    S.muteBtn.classList.toggle('is-on', !muted);
  }

  function onPlayState() {
    if (!S || !isVideo()) return;
    var playing = !S.media.paused && !S.media.ended;
    S.playBtn.textContent = playing ? '❚❚' : '▶';
    if (playing) loop(); else tick();
    paint();
  }

  /** While it plays, the counter follows the picture on screen, frame by frame. */
  function loop() {
    if (!S || !isVideo() || S.media.paused) return;
    var m = S.media;
    if (m.requestVideoFrameCallback) {
      m.requestVideoFrameCallback(function (now, meta) {
        if (!S || S.media !== m) return;
        tick(frameAt(meta.mediaTime));
        loop();
      });
    } else {
      tick();
      window.requestAnimationFrame(loop);
    }
  }

  function frameMeta() {
    if (!isVideo()) return;
    S.scrub.max = String(lastFrame());
  }

  /** HH:MM:SS:FF, the way an editor says it. */
  function timecode(n) {
    var fpsWhole = Math.round(S.fps);
    var secs = Math.floor(n / fpsWhole);
    var ff = n % fpsWhole;
    var pad = function (x) { return (x < 10 ? '0' : '') + x; };
    return pad(Math.floor(secs / 3600)) + ':' + pad(Math.floor(secs / 60) % 60) + ':' + pad(secs % 60) + ':' + pad(ff);
  }

  /** Draw the counter and scrubber for frame n (or wherever the video is). */
  function tick(n) {
    if (!S || !isVideo()) return;
    if (typeof n !== 'number') n = curFrame();
    var last = lastFrame();
    S.counter.textContent = 'Frame ' + (n + 1) + ' / ' + (last + 1) + '  ·  ' + timecode(n);
    if (document.activeElement !== S.scrub) S.scrub.value = String(n);
    paint();
  }

  // --- zoom -------------------------------------------------------------------------

  function fitScale() {
    var w = S.stage.clientWidth - 32, h = S.stage.clientHeight - 32;
    return Math.max(0.05, Math.min(w / S.w, h / S.h));
  }

  function layout() {
    if (!S || !S.w) return;
    if (S.fitted) S.scale = fitScale();
    var w = Math.round(S.w * S.scale) + 'px', h = Math.round(S.h * S.scale) + 'px';
    S.frame.style.width = w;
    S.frame.style.height = h;
    S.pct.textContent = Math.round(S.scale * 100) + '%';
  }

  function setScale(s) {
    if (!S || !S.w) return;
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
    if (!S || !S.w) return;
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
    if (!S || !S.pen || !S.w || e.button !== 0) return;
    e.preventDefault();
    if (isVideo()) {
      // You draw on a still. Pressing the pen on a playing video stops it on
      // the frame you were looking at.
      pause();
      var f = curFrame();
      if (S.strokes.length && S.drawFrame !== f) {
        if (!window.confirm('The drawing is on frame ' + (S.drawFrame + 1)
            + '. Start a new one on frame ' + (f + 1) + '?')) return;
        S.strokes = [];
      }
      S.drawFrame = f;
    }
    S.canvas.setPointerCapture(e.pointerId);
    S.live = { color: S.color, width: S.size / S.scale, pts: [toImage(e)] };
    S.strokes.push(S.live);
    paint();
  }

  function penMove(e) {
    if (!S || !S.live) return;
    var pts = S.live.pts, a = pts[pts.length - 1], b = toImage(e);
    pts.push(b);
    // Only the new piece of line, not every stroke again: redrawing them all
    // on each mouse move is what makes a pen lag behind the hand.
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
    if (!S.strokes.length) S.drawFrame = null;
    paint();
    drawState();
  }

  function clearDrawing() {
    S.strokes = [];
    S.drawFrame = null;
    paint();
    drawState();
  }

  /** Every stroke, onto the canvas c. */
  function strokesOnto(c) {
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

  /**
   * The overlay. On a video, a drawing shows only while the video is paused
   * on the frame it was drawn on — a circle round a hand means nothing three
   * frames later.
   */
  function paint() {
    if (!S || !S.w) return;
    var c = S.canvas.getContext('2d');
    c.clearRect(0, 0, S.canvas.width, S.canvas.height);
    if (isVideo() && S.drawFrame !== null && (!S.media.paused || curFrame() !== S.drawFrame)) return;
    strokesOnto(c);
  }

  function drawState() {
    if (!S || !S.drawSay) return;
    var n = S.strokes.length;
    if (n && S.drawFrame !== null) {
      S.drawSay.textContent = 'Your drawing on frame ' + (S.drawFrame + 1) + ' goes with the review as a picture.';
    } else if (n) {
      S.drawSay.textContent = 'Your drawing goes with the review as a picture.';
    } else {
      S.drawSay.textContent = isVideo() || (S.versions[S.idx] || {}).video
        ? 'Pause on a frame and draw on it with the pen to show what to change.'
        : 'Draw on the version with the pen to show what to change.';
    }
  }

  // --- posting ------------------------------------------------------------------------

  /** The frame (or picture) with the strokes on top, as a JPEG at full size. */
  function flatten(done) {
    var out = document.createElement('canvas');
    out.width = S.w;
    out.height = S.h;
    var c = out.getContext('2d');
    c.drawImage(S.media, 0, 0, S.w, S.h);
    strokesOnto(c);
    // JPEG, not PNG: a 4K paint-over as PNG is tens of megabytes for nothing
    // a reviewer can see. 0.9 keeps line edges clean.
    out.toBlob(done, 'image/jpeg', 0.9);
  }

  /** On a video, go back to the drawn frame first, so the picture under the lines is the right one. */
  function onDrawnFrame(then) {
    if (!isVideo() || S.drawFrame === null || (S.media.paused && curFrame() === S.drawFrame)) { then(); return; }
    var m = S.media;
    m.pause();
    m.addEventListener('seeked', function once() { m.removeEventListener('seeked', once); then(); });
    m.currentTime = (S.drawFrame + 0.5) / S.fps;
  }

  function post() {
    if (!S || !S.who) return;
    var text = S.box.value.trim();
    var drawn = S.strokes.length > 0;
    var files = pickedFiles();
    S.err.textContent = '';
    if (!text && !drawn && !files.length) {
      S.box.focus();
      S.err.textContent = 'Write something, attach a file or draw on the version first.';
      return;
    }

    S.post.disabled = true;
    S.post.textContent = 'Posting…';
    var v = S.versions[S.idx];

    // On a video the review says WHERE: the drawn frame, or the one paused on.
    var frame = null;
    if (isVideo()) frame = drawn ? S.drawFrame : curFrame();
    var where = frame !== null ? 'Frame ' + (frame + 1) + ' (' + timecode(frame) + ')' : '';
    var body = where ? (text ? where + ' — ' + text : where) : text;

    var send = function (blob) {
      var data = new FormData();
      data.append('add_review', '1');
      data.append('version_id', v.id);
      data.append('body', body);
      data.append('project_id', S.who.getAttribute('data-project'));
      data.append('step_id', S.who.getAttribute('data-step'));
      data.append('item_key', S.who.getAttribute('data-item'));
      files.forEach(function (file) { data.append('attachments[]', file, file.name); });
      if (blob) {
        var base = v.name.replace(/\.[^.]+$/, '') || v.label;
        if (frame !== null) base += '_f' + String(frame + 1).padStart(4, '0');
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
    onDrawnFrame(function () {
      flatten(function (blob) {
        if (!blob) { failed('The drawing could not be saved as a picture.'); return; }
        send(blob);
      });
    });
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
    S.box.value = '';
    if (window.animaReviewFiles) window.animaReviewFiles.clear(S.form);
    else S.form.reset();
    S.post.disabled = false;
    S.post.textContent = 'Post review';
    clearDrawing();
    showNotes();
  }

  // --- keys -------------------------------------------------------------------------

  function onKey(e) {
    if (!S) return;
    var tag = e.target && e.target.tagName;
    var typing = tag === 'TEXTAREA' || (tag === 'INPUT' && e.target.type !== 'range') || tag === 'SELECT';
    if (e.key === 'Escape') {
      // The lightbox over a note's picture closes first.
      if (document.querySelector('.lightbox.is-open')) return;
      e.preventDefault();
      close(false);
      return;
    }
    if (typing) return;
    if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); undoStroke(); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    var vid = isVideo();
    var k = e.key;
    // On a video the arrows walk frames, as in every review tool; on a
    // picture they walk versions. [ and ] walk versions on either.
    if (vid && (k === 'ArrowLeft' || k === ',')) { e.preventDefault(); stepFrame(-1); }
    else if (vid && (k === 'ArrowRight' || k === '.')) { e.preventDefault(); stepFrame(1); }
    else if (vid && k === ' ') { e.preventDefault(); togglePlay(); }
    else if (vid && k === 'Home') { e.preventDefault(); pause(); seekFrame(0); }
    else if (vid && k === 'End') { e.preventDefault(); pause(); seekFrame(lastFrame()); }
    else if (vid && (k === 'l' || k === 'L')) { setLoop(!S.loop); }
    else if (vid && (k === 'm' || k === 'M')) { toggleMute(); }
    else if (k === 'ArrowLeft' || k === '[') { e.preventDefault(); go(S.idx + 1); }
    else if (k === 'ArrowRight' || k === ']') { e.preventDefault(); go(S.idx - 1); }
    else if (k === '+' || k === '=') { e.preventDefault(); zoomStep(1); }
    else if (k === '-') { e.preventDefault(); zoomStep(-1); }
    else if (k === '0') { S.fitted = true; layout(); }
    else if (k === '1') { setScale(1); }
    else if (k === 'p' || k === 'P') { setPen(!S.pen); }
  }

  // --- the way in ---------------------------------------------------------------------

  // Clicking an image version, or a version's "Review & draw" button.
  // Delegated, because the pane is re-drawn after every post. Ctrl- and
  // middle-click still open the file on its own, the way the link underneath
  // always did.
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
