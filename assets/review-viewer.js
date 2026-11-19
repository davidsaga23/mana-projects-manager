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
// SEVERAL NOTES, PUBLISHED TOGETHER (David, 2026-11-19: "Right now if you paint
// on a frame and move to a different frame, it deletes the first one. We need
// to be able to leave several notes at once and then we can publish them all
// together including the text"). So a note is a DRAFT PER FRAME — its text, its
// strokes and its pin — kept while you move about, listed above the box, marked
// on the timeline, and copied into this browser's storage so a refresh does not
// lose them. "Publish N notes" sends each one as its own review, exactly as a
// single one always went, one after the other. A picture has one draft.
//
// ⚠ A PIN, LIKE FRAME.IO (same day, with a screenshot): a numbered teardrop on
// the picture with the matching #number on the note. Stored as a fraction of
// the width and height (migration 105, ticket_comments.pin_x / pin_y), so it
// lands on the same spot at any size. It is ALSO painted into the picture sent
// with the note, so the email and the Versions tab show where it was even
// before 105 runs, and anywhere this viewer is not.
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
  // Unpublished notes, per task, in this browser: anima.reviewDrafts:<project>:<step>:<item>.
  var DRAFT_KEY = 'anima.reviewDrafts:';
  var DRAFT_DAYS = 14;   // older than this and they are forgotten, not brought back

  var S = null;   // the open viewer's state; null while closed

  // ON A PHONE (David, 2026-11-19: "review on the go"). The same width as the
  // phone rules in assets/style.css. There the verdicts move from the top bar
  // to the bottom, under the review box, where a thumb reaches them.
  var PHONE = window.matchMedia ? window.matchMedia('(max-width: 700px)') : null;
  function isPhone() { return !!(PHONE && PHONE.matches); }
  // A finger, not a mouse: the pen starts off, so a finger pans and swipes
  // until ✎ is tapped. A pen started on would turn every swipe into a line.
  function isTouch() { return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); }

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
      // drafts[versionId][key]: one unpublished note per frame ('img' on a
      // picture): { frame, t, text, strokes, pin }. t is the frame's time, so
      // a change of frame rate can find the same picture again.
      drafts: {}, live: null, liveDraft: null, boxKey: null, published: [],
      pinSig: '', pendingFrame: null, busy: false, restored: 0,
      color: COLORS[0], size: SIZES[1].px, tool: (reviewForm && !isTouch()) ? 'pen' : null,
      touches: {}, pinch: null, swipe: null,
      fps: savedRate(), speed: 1, loop: true,
      actions: (opts && opts.actions) || []
    };
    S.restored = restoreDrafts();
    build();
    document.body.classList.add('rv-open');
    window.addEventListener('beforeunload', onLeave);
    load();
    changed();
  }

  function close(force) {
    if (!S) return;
    if (!force && pickedFiles().length
        && !window.confirm('Close the viewer? The files you picked have not been posted and will be dropped.')) return;
    var n = draftList().length;
    // ⚠ CLOSING DOES NOT THROW THE NOTES AWAY: they are in this browser and come
    // back when the viewer opens on this task again. Said, so nobody thinks
    // they went out.
    if (!force && n && !window.confirm(n + (n === 1 ? ' note is' : ' notes are') + ' not published yet. '
        + 'Close the viewer? They stay saved in this browser and come back when you open this task again.')) return;
    saveDrafts(true);
    stopMedia();
    if (S.stageWatch) S.stageWatch.disconnect();
    S.root.remove();
    document.body.classList.remove('rv-open');
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('beforeunload', onLeave);
    if (PHONE && PHONE.removeListener) PHONE.removeListener(placeVerdict);
    S = null;
  }

  /** Leaving the page (or refreshing) with notes not published: the browser asks first. */
  function onLeave(e) {
    if (!S || !draftList().length) return;
    saveDrafts(true);
    e.preventDefault();
    e.returnValue = '';
  }

  /** Is a review half written: a note on any frame, or files waiting? */
  function unposted() {
    if (!S) return false;
    return draftList().length > 0 || pickedFiles().length > 0;
  }

  // --- drafts: one unpublished note per frame ------------------------------------

  function curVid() { return S.versions[S.idx] ? S.versions[S.idx].id : ''; }
  function curKey() { return isVideo() ? String(curFrame()) : 'img'; }
  function versionIndex(vid) {
    for (var i = 0; i < S.versions.length; i++) if (S.versions[i].id === String(vid)) return i;
    return -1;
  }

  function draftAt(vid, key, make) {
    if (!S.drafts[vid]) { if (!make) return null; S.drafts[vid] = {}; }
    var all = S.drafts[vid];
    if (!all[key] && make) {
      var f = key === 'img' ? null : parseInt(key, 10);
      all[key] = { frame: f, t: f === null ? null : (f + 0.5) / S.fps, text: '', strokes: [], pin: null };
    }
    return all[key] || null;
  }

  function curDraft(make) { return draftAt(curVid(), curKey(), make); }

  function isEmptyDraft(d) { return !d || (!String(d.text || '').trim() && !d.strokes.length && !d.pin); }

  function pruneDrafts() {
    Object.keys(S.drafts).forEach(function (vid) {
      var all = S.drafts[vid];
      Object.keys(all).forEach(function (k) { if (isEmptyDraft(all[k]) && all[k] !== S.liveDraft) delete all[k]; });
      if (!Object.keys(all).length) delete S.drafts[vid];
    });
  }

  /** Every note waiting, the version on screen first, then by frame. */
  function draftList() {
    if (!S) return [];
    var out = [];
    Object.keys(S.drafts).forEach(function (vid) {
      Object.keys(S.drafts[vid]).forEach(function (k) {
        var d = S.drafts[vid][k];
        if (!isEmptyDraft(d)) out.push({ vid: vid, key: k, d: d, vi: versionIndex(vid) });
      });
    });
    var here = S.idx;
    out.sort(function (a, b) {
      var av = a.vi === here ? -1 : a.vi, bv = b.vi === here ? -1 : b.vi;
      if (av !== bv) return av - bv;
      return (a.d.frame || 0) - (b.d.frame || 0);
    });
    return out;
  }

  /** How many notes a version already has, as the pane drew them. */
  function publishedCount(vid) {
    return S.pane.querySelectorAll('#vr' + vid + ' .version-review').length;
  }

  /** The number a waiting note will get when it is published: after the ones there, in frame order. */
  function draftNo(vid, key) {
    var mine = draftList().filter(function (x) { return x.vid === String(vid); });
    for (var i = 0; i < mine.length; i++) if (mine[i].key === key) return publishedCount(vid) + i + 1;
    return publishedCount(vid) + mine.length + 1;
  }

  /** After any change to the notes: tidy, keep a copy, redraw what shows them. */
  function changed() {
    if (!S) return;
    pruneDrafts();
    saveDrafts(false);
    renderDrafts();
    marks();
    postLabel();
    drawState();
    paint();
  }

  // ⚠ A COPY IN THIS BROWSER, so a refresh, a crash or a closed tab does not
  // lose ten notes (David asked for exactly that). Per task, and only notes:
  // never the files picked, which a browser cannot keep.
  function draftStoreKey() {
    if (!S || !S.who) return null;
    return DRAFT_KEY + S.who.getAttribute('data-project') + ':' + S.who.getAttribute('data-step')
      + ':' + S.who.getAttribute('data-item');
  }

  function saveDrafts(now) {
    if (!S) return;
    if (S.saveTimer) { window.clearTimeout(S.saveTimer); S.saveTimer = null; }
    var write = function () {
      if (!S) return;
      var k = draftStoreKey();
      if (!k) return;
      try {
        if (!draftList().length) window.localStorage.removeItem(k);
        else window.localStorage.setItem(k, JSON.stringify({ at: Date.now(), drafts: S.drafts }));
      } catch (err) { /* private window or full: the notes are still on screen */ }
    };
    // Typing saves a moment after the last key, not on every key.
    if (now) write(); else S.saveTimer = window.setTimeout(write, 400);
  }

  /** The notes kept from last time, for versions still on the task. @return how many */
  function restoreDrafts() {
    var k = draftStoreKey(), n = 0;
    if (!k) return 0;
    try {
      var raw = window.localStorage.getItem(k);
      if (!raw) return 0;
      var data = JSON.parse(raw);
      if (!data || !data.drafts || Date.now() - (data.at || 0) > DRAFT_DAYS * 864e5) {
        window.localStorage.removeItem(k);
        return 0;
      }
      Object.keys(data.drafts).forEach(function (vid) {
        if (versionIndex(vid) === -1) return;   // that version was taken back
        var all = data.drafts[vid] || {};
        Object.keys(all).forEach(function (key) {
          var d = all[key] || {};
          var pin = d.pin && isFinite(d.pin.x) && isFinite(d.pin.y) ? { x: +d.pin.x, y: +d.pin.y } : null;
          var nd = {
            frame: d.frame === null || d.frame === undefined ? null : parseInt(d.frame, 10),
            t: isFinite(d.t) ? +d.t : null,
            text: String(d.text || ''),
            strokes: Array.isArray(d.strokes) ? d.strokes.filter(function (s) {
              return s && Array.isArray(s.pts) && s.pts.length;
            }) : [],
            pin: pin
          };
          if (isEmptyDraft(nd)) return;
          if (!S.drafts[vid]) S.drafts[vid] = {};
          S.drafts[vid][key] = nd;
          n++;
        });
      });
    } catch (err) { return 0; }
    return n;
  }

  /** A new frame rate moves every frame number: find each note's picture again by its time. */
  function rekeyDrafts() {
    Object.keys(S.drafts).forEach(function (vid) {
      var old = S.drafts[vid], fresh = {};
      Object.keys(old).forEach(function (key) {
        var d = old[key];
        if (d.frame !== null && d.t !== null) d.frame = frameAt(d.t);
        var k = d.frame === null ? 'img' : String(d.frame);
        if (fresh[k]) {   // two notes landed on one frame: keep both, as one
          fresh[k].text = [fresh[k].text, d.text].filter(function (x) { return x.trim(); }).join('\n');
          fresh[k].strokes = fresh[k].strokes.concat(d.strokes);
          fresh[k].pin = fresh[k].pin || d.pin;
        } else {
          fresh[k] = d;
        }
      });
      S.drafts[vid] = fresh;
    });
    S.boxKey = null;
  }

  /**
   * The review box shows the note of the frame on screen. While a video plays
   * it stays with the note it had; pausing on another frame swaps it over.
   */
  function bindBox() {
    if (!S || !S.box || S.busy) return;
    if (isVideo() && !S.media.paused) return;
    var k = curVid() + '|' + curKey();
    if (k === S.boxKey) return;
    S.boxKey = k;
    var d = curDraft(false);
    S.box.value = d ? d.text : '';
    renderDrafts();
  }

  /** What is in the box, into the note it belongs to. */
  function syncBox() {
    if (!S || !S.box) return;
    if (!S.boxKey) {
      if (S.box.value === '') return;
      S.boxKey = curVid() + '|' + curKey();
    }
    var at = S.boxKey.split('|');
    var d = draftAt(at[0], at[1], S.box.value.trim() !== '');
    if (d) d.text = S.box.value;
  }

  /** Go to a waiting note: its version, its frame, its text in the box. */
  function goToDraft(vid, key, focus) {
    var i = versionIndex(vid);
    if (i === -1) return;
    var f = key === 'img' ? null : parseInt(key, 10);
    if (i !== S.idx) {
      S.pendingFrame = f;
      go(i);
    } else if (f !== null && isVideo()) {
      pause();
      seekFrame(f);
    }
    bindBox();
    if (focus && S.box) S.box.focus();
  }

  function deleteDraft(vid, key) {
    var d = draftAt(vid, key, false);
    if (!d) return;
    if ((String(d.text).trim() || d.strokes.length > 2) && !window.confirm('Delete this note?')) return;
    delete S.drafts[vid][key];
    if (S.boxKey === vid + '|' + key) S.box.value = '';
    changed();
  }

  /** The list of notes waiting, above the box. */
  function renderDrafts() {
    if (!S || !S.draftsEl) return;
    var list = draftList();
    var box = S.draftsEl;
    box.innerHTML = '';
    box.hidden = !list.length;
    if (!list.length) return;

    var head = el('div', 'rv-drafts-head');
    head.appendChild(el('strong', '', list.length + (list.length === 1 ? ' note' : ' notes') + ' not published yet'));
    head.appendChild(el('span', 'muted small', S.restored
      ? ' · brought back from last time, saved in this browser'
      : ' · saved in this browser until you publish'));
    var clear = btn('rv-drafts-clear link-danger', 'Discard all', 'Delete every note not published yet', function () {
      if (!window.confirm('Delete all ' + list.length + ' notes not published yet? This cannot be undone.')) return;
      S.drafts = {};
      if (S.box) S.box.value = '';
      S.restored = 0;
      changed();
    });
    head.appendChild(clear);
    box.appendChild(head);

    var ol = el('ol', 'rv-drafts-list');
    list.forEach(function (x) {
      var v = S.versions[x.vi] || {};
      var li = el('li', 'rv-draft' + (S.boxKey === x.vid + '|' + x.key ? ' is-here' : ''));
      li.appendChild(el('span', 'rv-draft-no', '#' + draftNo(x.vid, x.key)));
      var at = x.d.frame !== null ? timecode(x.d.frame) : 'Picture';
      if (x.vi !== S.idx) at = v.label + ' · ' + at;
      li.appendChild(el('span', 'rv-draft-at', at));
      var what = String(x.d.text || '').trim();
      var bits = [];
      if (x.d.pin) bits.push('pin');
      if (x.d.strokes.length) bits.push('drawing');
      li.appendChild(el('span', 'rv-draft-text' + (what ? '' : ' muted'),
        what || '(' + (bits.join(' and ') || 'empty') + ', no text yet)'));
      if (what && bits.length) li.appendChild(el('span', 'rv-draft-bits muted', '+ ' + bits.join(' + ')));
      li.title = 'Go to this note';
      li.addEventListener('click', function () { goToDraft(x.vid, x.key, false); });
      li.appendChild(btn('rv-draft-btn', '✎', 'Edit this note', function (e) {
        e.stopPropagation();
        goToDraft(x.vid, x.key, true);
      }));
      li.appendChild(btn('rv-draft-btn rv-draft-del', '×', 'Delete this note', function (e) {
        e.stopPropagation();
        deleteDraft(x.vid, x.key);
      }));
      ol.appendChild(li);
    });
    box.appendChild(ol);
  }

  function postLabel() {
    if (!S || !S.post || S.busy) return;
    var n = draftList().length;
    S.post.textContent = n > 1 ? 'Publish ' + n + ' notes' : 'Post review';
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

    // DOWNLOAD the version on screen (David, 2026-11-18). A link, not a button: the
    // file comes from attachment.php with ?download=1, which sends it as a download
    // under its original name; load() keeps it pointing at the version shown.
    S.dl = el('a', 'rv-btn rv-download', 'Download');
    S.dl.title = 'Download this version';
    S.dl.setAttribute('download', '');
    top.appendChild(S.dl);

    // The notes column is hidden on a narrow screen (style.css, 900px); there
    // this button lays it over the picture instead. Not drawn wider than that.
    S.notesBtn = btn('rv-btn rv-notes-toggle', 'Notes', 'Show the review notes on this version', function () {
      var on = S.root.classList.toggle('rv-notes-open');
      S.notesBtn.classList.toggle('is-on', on);
    });
    top.appendChild(S.notesBtn);

    S.verdict = null;
    if (S.actions.length) {
      var verdict = el('div', 'rv-verdict');
      S.verdict = verdict;
      S.actions.forEach(function (a) {
        verdict.appendChild(btn('rv-verdict-btn ' + (a.cls || ''), a.label, a.title || '', function () {
          var n = draftList().length;
          if (n && !window.confirm(n + (n === 1 ? ' note is' : ' notes are') + ' not published yet, so the artist '
              + 'will not see ' + (n === 1 ? 'it' : 'them') + '. ' + a.label + ' anyway? (They stay saved in '
              + 'this browser.)')) return;
          if (!n && pickedFiles().length && !window.confirm('The files you picked have not been posted and will be '
              + 'dropped. ' + a.label + ' anyway?')) return;
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
    // The numbered pins, over the drawing: DOM, not canvas, so each one can be
    // hovered and clicked to find its note.
    S.pinLayer = el('div', 'rv-pins');
    S.frame.appendChild(S.pinLayer);
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
    // Where the verdicts go on a phone: the bottom edge, under the review box.
    S.bottom = el('div', 'rv-bottom');
    S.bottom.hidden = true;
    root.appendChild(S.bottom);

    document.body.appendChild(root);
    S.root = root;
    S.top = top;
    placeVerdict();
    if (PHONE && PHONE.addListener) PHONE.addListener(placeVerdict);
    setTool(S.tool);

    // Fingers on the picture: two pinch and pan, one pans (or, at fit size,
    // swipes to the next version) while the pen is off. Capture phase, so a
    // second finger is known before the canvas's penDown would start a line.
    ['pointerdown', 'pointermove', 'pointerup', 'pointercancel'].forEach(function (ev) {
      S.stage.addEventListener(ev, onTouch, true);
    });

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
    // The list of notes waiting grows and shrinks under the picture: refit,
    // or a fitted picture spills into scroll bars.
    if (window.ResizeObserver) {
      S.stageWatch = new ResizeObserver(function () { onResize(); });
      S.stageWatch.observe(S.stage);
    }
  }

  function buildTools() {
    var bar = el('div', 'rv-tools');
    bar.setAttribute('aria-label', 'Drawing tools');

    S.penBtn = btn('rv-tool is-on', '✎', 'Pen — draw on the version (P)', function () {
      setTool(S.tool === 'pen' ? null : 'pen');
    });
    bar.appendChild(S.penBtn);
    // THE PIN (David, 2026-11-19, "like Frame.io"): click the picture to drop a
    // numbered pin for this frame's note, then type the note.
    S.pinBtn = btn('rv-tool rv-pin-tool', '📍', 'Pin — click the picture to mark the spot, then write the note (N)',
                   function () { setTool(S.tool === 'pin' ? null : 'pin'); });
    bar.appendChild(S.pinBtn);
    bar.appendChild(el('span', 'rv-tools-gap'));

    S.swatches = [];
    COLORS.forEach(function (c) {
      var b = btn('rv-swatch' + (c === S.color ? ' is-on' : ''), undefined, 'Draw in this colour', function () {
        S.color = c;
        S.swatches.forEach(function (s) { s.classList.toggle('is-on', s === b); });
        setTool('pen');
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
        setTool('pen');
      });
      var dot = el('span', 'rv-size-dot');
      dot.style.width = dot.style.height = Math.min(sz.px + 2, 16) + 'px';
      b.appendChild(dot);
      S.sizeBtns.push(b);
      bar.appendChild(b);
    });
    bar.appendChild(el('span', 'rv-tools-gap'));

    bar.appendChild(btn('rv-tool', '↶', 'Undo the last line, then the pin (Ctrl+Z)', undoStroke));
    bar.appendChild(btn('rv-tool', '🗑', 'Clear the drawing and pin on this frame', function () {
      var d = curDraft(false);
      if (!d || (!d.strokes.length && !d.pin)) return;
      if (window.confirm('Clear the drawing and pin on this frame? Its text stays.')) clearDrawing();
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
    // Where the notes are, over the scrubber: published ones and the ones
    // waiting, each a click away (David, 2026-11-19).
    var track = el('div', 'rv-scrub-wrap');
    S.marks = el('div', 'rv-marks');
    track.appendChild(S.marks);
    track.appendChild(S.scrub);
    t.appendChild(track);

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
      // A note is kept by frame NUMBER, which a new rate would quietly move to
      // another picture; each note's time finds its picture again.
      syncBox();
      rekeyDrafts();
      frameMeta();
      tick();
      changed();
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
    S.draftsEl = el('div', 'rv-drafts');
    S.draftsEl.hidden = true;
    f.appendChild(S.draftsEl);
    S.box = el('textarea', 'rv-box board-review-box');
    S.box.rows = 2;
    S.box.placeholder = 'Write the note for this frame… paste an image straight in, type @ to name somebody';
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
    // Writing stops the video on the frame the note is about.
    S.box.addEventListener('focus', function () { if (isVideo() && !S.media.paused) pause(); });
    var typed = function () {
      if (S.busy) return;
      var was = draftList().length;
      syncBox();
      pruneDrafts();
      saveDrafts(false);
      if (draftList().length !== was) changed(); else { renderDrafts(); postLabel(); }
    };
    S.box.addEventListener('input', typed);
    S.box.addEventListener('change', typed);
    S.box.addEventListener('blur', function () { typed(); renderDrafts(); marks(); });

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
    if (S.dl) {
      S.dl.href = v.src + (v.src.indexOf('?') === -1 ? '?' : '&') + 'download=1';
      if (v.name) S.dl.setAttribute('download', v.name);
    }
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
      if (v.video) {
        frameMeta();
        // Opened from a note in the list: go to its frame.
        if (S.pendingFrame !== null) { var pf = S.pendingFrame; S.pendingFrame = null; seekFrame(pf); }
        tick();
      }
      bindBox();
      marks();
    };

    if (v.video) {
      m = document.createElement('video');
      m.className = 'rv-media';
      m.preload = 'auto';
      m.playsInline = true;
      // The attributes too: older iPhones read only these, and without them
      // a video jumps to the phone's own full-screen player on Play.
      m.setAttribute('playsinline', '');
      m.setAttribute('webkit-playsinline', '');
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
    S.pinSig = '';
    if (!v.video) S.pendingFrame = null;
    bindBox();
    renderDrafts();
    marks();
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
      readPublished();   // none: the last version's pins and marks go
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
    readPublished();
  }

  /**
   * The notes on this version as things with a place: their #number, their
   * frame and their pin (migration 105, drawn by board_versions_html() as
   * data-*). A note from before 105 still says its frame in its text, "Frame 12
   * (00:00:00:11) — …", which is read instead, so old notes jump to their frame too.
   */
  function readPublished() {
    S.published = [];
    S.pinSig = '';
    var n = 0;
    S.notes.querySelectorAll('.version-review').forEach(function (node) {
      n++;
      var no = parseInt(node.getAttribute('data-note-no'), 10) || n;
      var fa = node.getAttribute('data-frame');
      var f = fa !== null && fa !== '' ? parseInt(fa, 10) : null;
      if (f === null) {
        var body = node.querySelector('.comment-body');
        var m = /^\s*Frame (\d+) \(/.exec(body ? body.textContent : '');
        if (m) f = parseInt(m[1], 10) - 1;
      }
      var x = parseFloat(node.getAttribute('data-pin-x')), y = parseFloat(node.getAttribute('data-pin-y'));
      var p = { no: no, frame: f, x: isFinite(x) ? x : null, y: isFinite(y) ? y : null, el: node };
      S.published.push(p);
      node.classList.toggle('rv-note-jump', f !== null || p.x !== null);
      node.addEventListener('mouseenter', function () { highlight(no, true); });
      node.addEventListener('mouseleave', function () { highlight(no, false); });
      node.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a, button, img, video, input, textarea, select')) return;
        jumpTo(p);
      });
    });
    marks();
    paint();
  }

  /** A note's frame, with its pin lit. */
  function jumpTo(p) {
    if (p.frame !== null && isVideo()) {
      pause();
      seekFrame(p.frame);
    }
    S.pinSig = '';
    paint();
    highlight(p.no, true);
  }

  /** Light a note and its pin together, so either one finds the other. */
  function highlight(no, on) {
    if (!S) return;
    S.published.forEach(function (p) { p.el.classList.toggle('is-hl', on && p.no === no); });
    S.pinLayer.querySelectorAll('.rv-pin.is-pub').forEach(function (pin) {
      pin.classList.toggle('is-hl', on && pin.getAttribute('data-no') === String(no));
    });
  }

  /** The published notes' pins on the frame on screen, and the waiting note's. */
  function pins() {
    if (!S || !S.pinLayer) return;
    var list = [];
    var playing = isVideo() && !S.media.paused;
    if (!playing && S.w) {
      var f = isVideo() ? curFrame() : null;
      S.published.forEach(function (p) {
        if (p.x === null) return;
        if (isVideo() ? p.frame === f : true) list.push({ no: p.no, x: p.x, y: p.y, pub: true });
      });
      var d = curDraft(false);
      if (d && d.pin) list.push({ no: draftNo(curVid(), curKey()), x: d.pin.x, y: d.pin.y, pub: false });
    }
    var sig = JSON.stringify(list);
    if (sig === S.pinSig) return;
    S.pinSig = sig;
    S.pinLayer.innerHTML = '';
    list.forEach(function (p) {
      var pin = el('button', 'rv-pin ' + (p.pub ? 'is-pub' : 'is-draft'));
      pin.type = 'button';
      pin.style.left = (p.x * 100) + '%';
      pin.style.top = (p.y * 100) + '%';
      pin.setAttribute('data-no', String(p.no));
      pin.title = p.pub ? 'Note #' + p.no : 'Your note #' + p.no + ', not published yet';
      pin.appendChild(el('span', 'rv-pin-no', String(p.no)));
      if (p.pub) {
        pin.addEventListener('mouseenter', function () { highlight(p.no, true); });
        pin.addEventListener('mouseleave', function () { highlight(p.no, false); });
        pin.addEventListener('click', function () {
          highlight(p.no, true);
          var hit = S.published.filter(function (q) { return q.no === p.no; })[0];
          if (!hit) return;
          // On a narrow screen the notes are behind a button: open them.
          if (S.notesBtn && window.getComputedStyle(S.notesBtn).display !== 'none'
              && !S.root.classList.contains('rv-notes-open')) S.notesBtn.click();
          hit.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
      } else {
        pin.addEventListener('click', function () { if (S.box) S.box.focus(); });
      }
      S.pinLayer.appendChild(pin);
    });
  }

  /** Ticks over the scrubber: every note with a frame, published or waiting. */
  function marks() {
    if (!S || !S.marks) return;
    S.marks.innerHTML = '';
    if (!isVideo()) return;
    var last = lastFrame();
    if (!last) return;
    var add = function (frame, cls, label, title, fn) {
      var m = btn('rv-mark ' + cls, label, title, fn);
      var at = Math.max(0, Math.min(1, frame / last));
      // The thumb's own half-width (8px) each side, so a mark sits over the thumb at that frame.
      m.style.left = 'calc(8px + (100% - 16px) * ' + at.toFixed(5) + ')';
      S.marks.appendChild(m);
    };
    S.published.forEach(function (p) {
      if (p.frame === null) return;
      add(p.frame, 'is-pub', String(p.no), 'Note #' + p.no + ' · ' + timecode(p.frame), function () { jumpTo(p); });
    });
    var vid = curVid();
    draftList().forEach(function (x) {
      if (x.vid !== vid || x.d.frame === null) return;
      var no = draftNo(x.vid, x.key);
      add(x.d.frame, 'is-draft', String(no), 'Your note #' + no + ', not published · ' + timecode(x.d.frame),
          function () { goToDraft(x.vid, x.key, false); });
    });
  }

  function go(i) {
    if (!S || i < 0 || i >= S.versions.length || i === S.idx) { if (S) S.pick.value = String(S.idx); return; }
    // A note belongs to the version it was made on, and waits there: nothing
    // is lost by looking at another one.
    syncBox();
    S.live = null;
    S.liveDraft = null;
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
    marks();
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
    bindBox();
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

  /** The verdicts: in the top bar on a computer, along the bottom on a phone. */
  function placeVerdict() {
    if (!S || !S.verdict) return;
    var phone = isPhone();
    (phone ? S.bottom : S.top).appendChild(S.verdict);
    S.bottom.hidden = !phone;
  }

  // --- fingers ------------------------------------------------------------------------
  //
  // The stage has touch-action: none (style.css), so the browser leaves every
  // finger to this: the page itself never scrolls or zooms under the viewer,
  // and a line drawn with a finger stays a line. Mouse and pen-tablet input
  // never come here; they work exactly as before.

  var SWIPE_PX = 60;

  function onTouch(e) {
    if (!S || e.pointerType !== 'touch') return;
    var T = S.touches, id = e.pointerId;
    if (e.type === 'pointerdown') {
      T[id] = { x: e.clientX, y: e.clientY };
      var ids = Object.keys(T);
      // Keep hearing this finger when it slides off the picture, so its
      // lifting is never missed. Not a finger drawing a line: the canvas
      // holds on to that one itself.
      if (!(ids.length === 1 && S.tool && e.target === S.canvas)) {
        try { S.stage.setPointerCapture(id); } catch (err) { /* already gone */ }
      }
      if (ids.length === 2) {
        // A second finger: this is a pinch, not a line. Drop the line the
        // first finger had just started.
        if (S.live) {
          if (S.liveDraft) S.liveDraft.strokes.pop();
          S.live = null;
          S.liveDraft = null;
          changed();
        }
        S.swipe = null;
        if (!S.w) return;
        var a = T[ids[0]], b = T[ids[1]];
        var r = S.frame.getBoundingClientRect();
        var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
        S.pinch = {
          d: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)), scale: S.scale,
          // The point of the picture between the fingers, in its own pixels.
          fx: (mx - r.left) / S.scale, fy: (my - r.top) / S.scale
        };
      } else if (ids.length === 1 && !(S.tool && e.target === S.canvas)) {
        S.swipe = { id: id, x: e.clientX, y: e.clientY,
                    sl: S.stage.scrollLeft, st: S.stage.scrollTop };
      }
      return;
    }
    if (!T[id]) return;
    if (e.type === 'pointermove') {
      T[id] = { x: e.clientX, y: e.clientY };
      var keys = Object.keys(T);
      if (S.pinch && keys.length >= 2) {
        e.preventDefault();
        var p = T[keys[0]], q = T[keys[1]];
        var cx = (p.x + q.x) / 2, cy = (p.y + q.y) / 2;
        S.fitted = false;
        S.scale = Math.max(0.05, Math.min(8, S.pinch.scale * Math.hypot(p.x - q.x, p.y - q.y) / S.pinch.d));
        layout();
        // Keep that same point of the picture between the fingers.
        var nr = S.frame.getBoundingClientRect();
        S.stage.scrollLeft += nr.left + S.pinch.fx * S.scale - cx;
        S.stage.scrollTop += nr.top + S.pinch.fy * S.scale - cy;
      } else if (S.swipe && S.swipe.id === id && !S.fitted) {
        // Zoomed in: one finger moves the picture about.
        S.stage.scrollLeft = S.swipe.sl - (e.clientX - S.swipe.x);
        S.stage.scrollTop = S.swipe.st - (e.clientY - S.swipe.y);
      }
      return;
    }
    // pointerup / pointercancel
    delete T[id];
    if (S.pinch) {
      if (Object.keys(T).length < 2) {
        S.pinch = null;
        // Pinched smaller than the screen: back to fit, rather than a postage stamp.
        if (S.w && S.scale <= fitScale() + 0.001) { S.fitted = true; layout(); }
      }
      return;
    }
    var sw = S.swipe;
    S.swipe = null;
    if (!sw || sw.id !== id || e.type !== 'pointerup' || !S.fitted) return;
    var dx = e.clientX - sw.x, dy = e.clientY - sw.y;
    // At fit size a sideways swipe walks the versions, as the arrow keys do:
    // to the left for the older one, to the right for the newer one.
    if (Math.abs(dx) > SWIPE_PX && Math.abs(dx) > 1.5 * Math.abs(dy)) go(dx < 0 ? S.idx + 1 : S.idx - 1);
  }

  // --- the pen and the pin ----------------------------------------------------------

  /** 'pen', 'pin' or null (fingers pan and swipe). Only for somebody who may review. */
  function setTool(t) {
    if (!S || !S.canDraw) return;
    S.tool = t || null;
    if (S.penBtn) S.penBtn.classList.toggle('is-on', S.tool === 'pen');
    if (S.pinBtn) S.pinBtn.classList.toggle('is-on', S.tool === 'pin');
    S.canvas.classList.toggle('is-pen', S.tool === 'pen');
    S.canvas.classList.toggle('is-pin', S.tool === 'pin');
  }

  function toImage(e) {
    var r = S.canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) / r.width * S.canvas.width,
      (e.clientY - r.top) / r.height * S.canvas.height
    ];
  }

  function penDown(e) {
    if (!S || !S.tool || !S.w || e.button !== 0 || S.pinch || S.busy) return;
    e.preventDefault();
    // You draw on a still. Pressing on a playing video stops it on the frame
    // you were looking at, and that frame's note is the one drawn on —
    // a note on another frame is left exactly as it was.
    pause();
    bindBox();
    if (S.tool === 'pin') { placePin(e); return; }
    var d = curDraft(true);
    S.liveDraft = d;
    S.canvas.setPointerCapture(e.pointerId);
    S.live = { color: S.color, width: S.size / S.scale, pts: [toImage(e)] };
    d.strokes.push(S.live);
    paint();
  }

  /** One pin per note: a second click on the same frame moves it. */
  function placePin(e) {
    var p = toImage(e);
    var d = curDraft(true);
    var clamp = function (x) { return Math.max(0, Math.min(1, x)); };
    d.pin = { x: +clamp(p[0] / S.w).toFixed(5), y: +clamp(p[1] / S.h).toFixed(5) };
    changed();
    // Then the note itself. Not on a phone, where the keyboard would jump up
    // over the picture before the pin has even been seen.
    if (S.box && !isTouch()) S.box.focus();
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
    // A tenth of a pixel is plenty, and keeps the copy in this browser small.
    S.live.pts = S.live.pts.map(function (q) { return [Math.round(q[0] * 10) / 10, Math.round(q[1] * 10) / 10]; });
    S.live.width = Math.round(S.live.width * 100) / 100;
    S.live = null;
    S.liveDraft = null;
    changed();
  }

  /** The last line on this frame; once there are none, its pin. */
  function undoStroke() {
    var d = S && curDraft(false);
    if (!d) return;
    if (d.strokes.length) d.strokes.pop();
    else if (d.pin) d.pin = null;
    else return;
    changed();
  }

  /** This frame's drawing and pin. Its text stays. */
  function clearDrawing() {
    var d = curDraft(false);
    if (!d) return;
    d.strokes = [];
    d.pin = null;
    changed();
  }

  /** Strokes onto the canvas c. */
  function strokesOnto(c, strokes) {
    c.lineCap = 'round';
    c.lineJoin = 'round';
    strokes.forEach(function (s) {
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
   * A numbered teardrop with its point on (x, y), the way the viewer draws it,
   * painted into the picture that goes with the note. r is the round part's
   * radius in the picture's own pixels.
   */
  function pinOnto(c, x, y, no, r) {
    var cy = y - 1.5 * r;
    var a = Math.acos(1 / 1.5);   // where the sides meet the circle, from straight down
    c.save();
    c.beginPath();
    c.moveTo(x, y);
    c.arc(x, cy, r, Math.PI / 2 + a, Math.PI / 2 - a + Math.PI * 2);
    c.closePath();
    c.fillStyle = '#111111';
    c.fill();
    c.lineWidth = Math.max(2, r / 7);
    c.strokeStyle = '#ffffff';
    c.stroke();
    c.fillStyle = '#ffffff';
    c.font = 'bold ' + Math.round(r * 1.05) + 'px system-ui, sans-serif';
    c.textAlign = 'center';
    c.textBaseline = 'middle';
    c.fillText(String(no), x, cy + r * 0.05);
    c.restore();
  }

  /**
   * The overlay. On a video, a note's drawing shows only while the video is
   * paused on its frame — a circle round a hand means nothing three frames later.
   */
  function paint() {
    if (!S || !S.w) return;
    var c = S.canvas.getContext('2d');
    c.clearRect(0, 0, S.canvas.width, S.canvas.height);
    var playing = isVideo() && !S.media.paused;
    var d = playing ? null : curDraft(false);
    if (d) strokesOnto(c, d.strokes);
    pins();
  }

  function drawState() {
    if (!S || !S.drawSay) return;
    var list = draftList(), n = list.length;
    var video = isVideo() || (S.versions[S.idx] || {}).video;
    // Before database update 105 a pin cannot be kept as a pin: say where it goes instead.
    if (S.who && !S.who.hasAttribute('data-pins') && list.some(function (x) { return x.d.pin; })) {
      S.drawSay.textContent = 'Pins are painted into the picture that goes with each note. '
        + 'Clickable pins need database update 105.';
    } else if (n > 1) {
      S.drawSay.textContent = 'Each note goes as its own review, with its frame, drawing and pin. '
        + 'Attached files go with the first.';
    } else if (n) {
      S.drawSay.textContent = video
        ? 'Move to another frame to start the next note; this one waits in the list.'
        : 'Your drawing and pin go with the review as a picture.';
    } else {
      S.drawSay.textContent = video
        ? 'Pause on a frame, draw (✎) or drop a pin (📍), and write the note. Then the next frame.'
        : 'Draw (✎) or drop a pin (📍) on the version to show what to change.';
    }
  }

  // --- publishing -----------------------------------------------------------------------

  /**
   * The picture under a note, at full size: the version on screen when it is
   * that one (sought to the note's frame), otherwise a copy loaded off screen.
   * cb(source, width, height), or cb(null) if it would not load.
   */
  function grab(v, frame, cb) {
    if (v.id === curVid() && S.media && S.w) {
      if (!isVideo()) { cb(S.media, S.w, S.h); return; }
      var m = S.media;
      m.pause();
      if (curFrame() === frame && !m.seeking) { cb(m, S.w, S.h); return; }
      m.addEventListener('seeked', function once() { m.removeEventListener('seeked', once); cb(m, S.w, S.h); });
      m.currentTime = (frame + 0.5) / S.fps;
      return;
    }
    var done = false;
    var finish = function (src, w, h) {
      if (done) return;
      done = true;
      window.clearTimeout(timer);
      cb(src, w, h);
    };
    var timer = window.setTimeout(function () { finish(null); }, 20000);
    if (v.video) {
      var o = document.createElement('video');
      o.muted = true;
      o.preload = 'auto';
      o.playsInline = true;
      o.setAttribute('playsinline', '');
      o.addEventListener('loadeddata', function () {
        o.addEventListener('seeked', function () { finish(o, o.videoWidth, o.videoHeight); });
        o.currentTime = ((frame || 0) + 0.5) / S.fps;
      });
      o.addEventListener('error', function () { finish(null); });
      o.src = v.src;
      o.load();
    } else {
      var im = new Image();
      im.addEventListener('load', function () { finish(im, im.naturalWidth, im.naturalHeight); });
      im.addEventListener('error', function () { finish(null); });
      im.src = v.src;
    }
  }

  /** The frame (or picture) with the note's strokes and pin on top, as a JPEG at full size. */
  function flatten(src, w, h, d, no, done) {
    var out = document.createElement('canvas');
    out.width = w;
    out.height = h;
    var c = out.getContext('2d');
    c.drawImage(src, 0, 0, w, h);
    strokesOnto(c, d.strokes);
    if (d.pin) pinOnto(c, d.pin.x * w, d.pin.y * h, no, Math.max(14, Math.min(w, h) / 28));
    // JPEG, not PNG: a 4K paint-over as PNG is tens of megabytes for nothing
    // a reviewer can see. 0.9 keeps line edges clean.
    out.toBlob(done, 'image/jpeg', 0.9);
  }

  /**
   * PUBLISH EVERY NOTE WAITING (David, 2026-11-19), one review each, one after
   * the other: each is the same add_review a single note always was, so the
   * mentions, the bell and the email work for every one, and a failure halfway
   * leaves the rest waiting rather than half-saved. Attached files go with
   * the first.
   */
  function post() {
    if (!S || !S.who || S.busy) return;
    syncBox();
    pruneDrafts();
    var list = draftList();
    var files = pickedFiles();
    S.err.textContent = '';
    if (!list.length && !files.length) {
      S.box.focus();
      S.err.textContent = 'Write something, attach a file, draw or drop a pin first.';
      return;
    }
    // Only files: a review of their own, on the frame on screen, as before.
    if (!list.length) {
      var f0 = isVideo() ? curFrame() : null;
      list = [{ vid: curVid(), key: curKey(), only: true,
                d: { frame: f0, t: null, text: '', strokes: [], pin: null } }];
    }

    S.busy = true;
    S.post.disabled = true;
    var total = list.length, done = 0;
    var next = function () {
      if (!S) return;
      if (!list.length) { finished(total); return; }
      var it = list[0];
      S.post.textContent = total > 1 ? 'Publishing ' + (done + 1) + ' of ' + total + '…' : 'Posting…';
      sendNote(it, done === 0 ? files : [], function (err) {
        if (!S) return;
        if (err) { failed(err, done); return; }
        list.shift();
        if (!it.only && S.drafts[it.vid]) delete S.drafts[it.vid][it.key];
        if (S.boxKey === it.vid + '|' + it.key) { S.box.value = ''; }
        // The files went with the first; they must not go again.
        if (done === 0) {
          if (window.animaReviewFiles) window.animaReviewFiles.clear(S.form);
          else { var inp = S.form.querySelector('input[type="file"]'); if (inp) inp.value = ''; }
        }
        done++;
        pruneDrafts();
        saveDrafts(true);
        next();
      });
    };
    next();
  }

  /** One note, as one add_review. cb(null) when the server took it, cb(why) when not. */
  function sendNote(it, files, cb) {
    var vi = versionIndex(it.vid);
    var v = S.versions[vi];
    if (!v) { cb('That version is no longer on the task.'); return; }
    var d = it.d, frame = d.frame;
    // On a video the review says WHERE, in its text too, so the email and the
    // Comments tab say it without any of this.
    var where = frame !== null ? 'Frame ' + (frame + 1) + ' (' + timecode(frame) + ')' : '';
    var text = String(d.text || '').trim();
    var body = where ? (text ? where + ' — ' + text : where) : text;
    var no = publishedCount(v.id) + 1;

    var send = function (blob) {
      var data = new FormData();
      data.append('add_review', '1');
      data.append('version_id', v.id);
      data.append('body', body);
      data.append('project_id', S.who.getAttribute('data-project'));
      data.append('step_id', S.who.getAttribute('data-step'));
      data.append('item_key', S.who.getAttribute('data-item'));
      if (frame !== null) data.append('frame', String(frame));
      if (d.pin) {
        data.append('pin_x', String(d.pin.x));
        data.append('pin_y', String(d.pin.y));
      }
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
        if (xhr.status < 200 || xhr.status >= 300) { cb('The server refused that (HTTP ' + xhr.status + ').'); return; }
        cb(applyPane(xhr.responseText));
      });
      xhr.addEventListener('error', function () {
        cb('That did not go through. Check your connection and try again.');
      });
      xhr.send(data);
    };

    if (!d.strokes.length && !d.pin) { send(null); return; }
    grab(v, frame, function (src, w, h) {
      if (!S) return;
      if (!src) { cb('The picture under the note on ' + v.label + ' could not be loaded.'); return; }
      try {
        flatten(src, w, h, d, no, function (blob) {
          if (!blob) { cb('The drawing could not be saved as a picture.'); return; }
          send(blob);
        });
      } catch (err) {
        cb('The drawing could not be saved as a picture.');
      }
    });
  }

  /**
   * Put the pane the server sent back in place, keeping the version on screen.
   * @return null, or the server's own red line when it refused the note
   */
  function applyPane(html) {
    var pane = S.pane, here = curVid();
    pane.innerHTML = html;
    if (window.animaBoard) {
      window.animaBoard.paneReady('versions');
      window.animaBoard.timelineRefresh();
    }
    // The endpoint answers 200 with its own red line for anything it refused.
    var bad = pane.querySelector(':scope > p.err');
    S.who = pane.querySelector('.board-review-form') || S.who;
    var fresh = readVersions(pane);
    if (fresh.length) {
      S.versions = fresh;
      var i = versionIndex(here);
      if (i !== -1) S.idx = i;
    }
    showNotes();
    return bad ? bad.textContent : null;
  }

  function failed(why, done) {
    if (!S) return;
    S.busy = false;
    S.post.disabled = false;
    S.boxKey = null;
    bindBox();
    changed();
    S.err.textContent = (done ? done + ' published, then: ' : '') + why
      + ' The notes not published yet are still here.';
  }

  function finished(total) {
    S.busy = false;
    S.post.disabled = false;
    S.restored = 0;
    S.boxKey = null;
    bindBox();
    changed();
    S.drawSay.textContent = total > 1 ? total + ' notes published.' : 'Posted.';
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
    else if (k === 'p' || k === 'P') { setTool(S.tool === 'pen' ? null : 'pen'); }
    else if (k === 'n' || k === 'N') { setTool(S.tool === 'pin' ? null : 'pin'); }
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
    open(pane, t.getAttribute('data-review-open'), { actions: paneVerdicts(pane) });
  });

  /**
   * APPROVE AND RETAKE WHEN OPENED FROM A TASK (David, 2026-10-29), not only
   * from the Reviews screen: built from the marker includes/board.php puts in
   * the Versions pane for the reviewer whose turn it is (board_verdict_html()).
   * Each posts the verdict to board_version.php, the same rules as the Reviews
   * screen, and puts the pane it sends back in place.
   */
  function paneVerdicts(pane) {
    var mark = pane.querySelector('.board-verdict');
    if (!mark) return [];
    var out = [];
    var give = function (verdict) {
      var data = new FormData();
      data.append('add_review', '1');
      data.append('verdict', verdict);
      data.append('project_id', mark.getAttribute('data-project'));
      data.append('step_id', mark.getAttribute('data-step'));
      data.append('item_key', mark.getAttribute('data-item'));
      fetch('board_version.php', { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          pane.innerHTML = html;
          if (window.animaBoard) {
            window.animaBoard.paneReady('versions');
            window.animaBoard.timelineRefresh();
          }
          var said = pane.querySelector(':scope > p.err, :scope > p.ok');
          if (said) said.scrollIntoView({ block: 'nearest' });
        })
        .catch(function () {
          window.alert('That did not go through. Check your connection and try again.');
        });
    };
    var to = mark.getAttribute('data-to');
    var retake = mark.getAttribute('data-retake');
    if (to) out.push({ label: 'Approve', cls: 'primary', title: 'Approve: moves the task to ' + to,
                       run: function () { give('approve'); } });
    if (retake) out.push({ label: 'Retake', cls: 'secondary danger', title: 'Send it to ' + retake,
                           run: function () { give('retake'); } });
    return out;
  }

  window.animaReviewViewer = { open: open, close: close };
}());
