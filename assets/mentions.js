// Naming somebody in a ticket comment, and replying to one.
//
// David, 2026-10-06: "can we also have the option to tag people in the
// ticket/task detail view and create threads if needed?" He had already typed
// "@" into the box hoping it would do something, which is the clearest possible
// statement of what the control should be.
//
// Reads window.TICKET_PEOPLE, set by ticket.php: the same list the owner
// dropdown offers, so the names that can be typed are exactly the names that can
// be resolved to a person when the comment is saved. A picker offering somebody
// the parser cannot match would be a quiet lie.
(function () {
  'use strict';

  var box = document.getElementById('commentBody');
  var people = window.TICKET_PEOPLE || [];

  // --- replying ---------------------------------------------------------------
  //
  // The reply target is a hidden field on the one comment form rather than a
  // second form under each comment: one box, one set of attachment controls, one
  // place the draft lives. Pressing Reply moves the cursor to it and says who.
  var replyTo = document.getElementById('replyTo');
  var banner = document.getElementById('replyBanner');
  var replyName = document.getElementById('replyName');
  var cancel = document.getElementById('replyCancel');

  if (replyTo && banner) {
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest ? ev.target.closest('.comment-reply-btn') : null;
      if (!btn) return;
      replyTo.value = btn.getAttribute('data-reply-to') || '';
      replyName.textContent = btn.getAttribute('data-reply-name') || 'that comment';
      banner.hidden = false;
      if (box) { box.focus(); }
      banner.scrollIntoView({ block: 'center' });
    });

    if (cancel) {
      cancel.addEventListener('click', function () {
        replyTo.value = '';
        banner.hidden = true;
        if (box) box.focus();
      });
    }
  }

  // --- naming somebody --------------------------------------------------------
  if (!box || !people.length) return;

  var panel = null, list = null;
  var at = -1;            // where the "@" that opened this sits, or -1
  var active = 0;         // the row Enter would take

  function build() {
    panel = document.createElement('div');
    panel.className = 'mention-panel';
    panel.hidden = true;
    list = document.createElement('div');
    list.className = 'mention-list';
    panel.appendChild(list);
    document.body.appendChild(panel);
    // mousedown, not click: the textarea must not lose focus and close the panel
    // before the choice is registered.
    panel.addEventListener('mousedown', function (ev) {
      var row = ev.target.closest('.mention-row');
      if (!row) return;
      ev.preventDefault();
      choose(row.getAttribute('data-name') || '');
    });
  }

  function close() {
    at = -1;
    if (panel) panel.hidden = true;
  }

  /** Put the panel under the caret's line. Good enough, and never off-screen. */
  function place() {
    var r = box.getBoundingClientRect();
    var h = panel.offsetHeight;
    var top = r.bottom + 4;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
    panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - panel.offsetWidth - 8)) + 'px';
    panel.style.top = top + 'px';
  }

  function choose(name) {
    if (at < 0) return;
    var before = box.value.slice(0, at);
    var after = box.value.slice(box.selectionStart);
    // One trailing space, so the next word is not stuck to the name.
    box.value = before + '@' + name + ' ' + after;
    var caret = (before + '@' + name + ' ').length;
    box.focus();
    box.setSelectionRange(caret, caret);
    close();
  }

  function show(query) {
    var q = query.toLowerCase();
    var hits = people.filter(function (p) {
      return q === '' || String(p.name).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 8);

    if (!hits.length) { close(); return; }

    list.textContent = '';
    hits.forEach(function (p) {
      var row = document.createElement('div');
      row.className = 'mention-row';
      row.setAttribute('data-name', p.name);
      row.textContent = p.name;
      if (p.role) {
        var r = document.createElement('span');
        r.className = 'muted small';
        r.textContent = ' · ' + p.role;
        row.appendChild(r);
      }
      list.appendChild(row);
    });
    panel.hidden = false;
    // The first match is live from the moment the list appears, so Enter works
    // without pressing an arrow first — which is the whole point of typing a
    // name rather than reaching for the mouse.
    mark(0);
    place();
  }

  /** Which row Enter would take. Kept in view when the arrows run past the edge. */
  function mark(i) {
    var rows = list.querySelectorAll('.mention-row');
    if (!rows.length) return;
    active = Math.max(0, Math.min(rows.length - 1, i));
    Array.prototype.forEach.call(rows, function (r, n) {
      r.classList.toggle('is-active', n === active);
    });
    rows[active].scrollIntoView({ block: 'nearest' });
  }

  function move(delta) {
    var rows = list.querySelectorAll('.mention-row');
    if (!rows.length) return;
    // Wraps, because a list of five with the cursor at the bottom should not
    // need four presses to get back to the top.
    mark((active + delta + rows.length) % rows.length);
  }

  function take() {
    var row = list.querySelectorAll('.mention-row')[active];
    if (row) choose(row.getAttribute('data-name') || '');
  }

  /**
   * What is being typed after the nearest "@" before the caret.
   *
   * ⚠ A NAME CAN CONTAIN SPACES, so the search does not stop at one — it stops
   * after three words, which is longer than any name in the book and short
   * enough that an "@" typed in the middle of a sentence stops matching once the
   * sentence moves on. Stopping at the first space would have made "Ana Ruiz"
   * unreachable from the picker that is supposed to offer her.
   */
  function query() {
    var upto = box.value.slice(0, box.selectionStart);
    var i = upto.lastIndexOf('@');
    if (i === -1) return null;
    var tail = upto.slice(i + 1);
    if (tail.indexOf('\n') !== -1) return null;
    if (tail.split(' ').length > 3) return null;
    return { at: i, text: tail };
  }

  box.addEventListener('input', function () {
    if (!panel) build();
    var q = query();
    if (!q) { close(); return; }
    at = q.at;
    show(q.text);
  });

  // ⚠ ONLY WHILE THE LIST IS OPEN. Enter in a textarea makes a new line, and it
  // must go on doing that the rest of the time — a comment box that cannot take
  // a paragraph break would be a worse trade than reaching for the mouse. So
  // every key here is handled only when the panel is showing, and passed
  // straight through otherwise.
  box.addEventListener('keydown', function (ev) {
    if (!panel || panel.hidden) return;
    if (ev.key === 'Escape')     { ev.preventDefault(); close(); return; }
    if (ev.key === 'ArrowDown')  { ev.preventDefault(); move(1);  return; }
    if (ev.key === 'ArrowUp')    { ev.preventDefault(); move(-1); return; }
    // Tab as well as Enter: it is what a picker like this does elsewhere, and it
    // would otherwise jump focus out of the box mid-name.
    if (ev.key === 'Enter' || ev.key === 'Tab') { ev.preventDefault(); take(); }
  });
  box.addEventListener('blur', function () { setTimeout(close, 120); });
  window.addEventListener('resize', function () { if (panel && !panel.hidden) place(); });
  window.addEventListener('scroll', function () { if (panel && !panel.hidden) place(); }, true);
}());
