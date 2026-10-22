// The Board (board.php, 2026-10-22): expanding, searching, and saving a cell
// without reloading the page.
//
// ⚠ NAMED board-screen.js, NOT board.js, which is already taken — by the
// TASKS kanban that tasks.php loads ("the tasks board: drag between columns").
// Two different screens have a fair claim on the word, and the older one had
// it first; renaming that file would have touched a screen in daily use for
// the sake of a filename.
//
// ⚠ NOTHING HERE IS A PERMISSION. Every control this file touches was already
// drawn as editable by board.php, and board_api.php checks the same rules again
// on the way in. This only carries the value.
//
// ⚠ AND NOTHING SAVES SILENTLY. A board people live in all day has to say what
// it did, because the alternative — a status that looks changed and was not —
// is the failure this codebase keeps paying for (lesson 11). Every save marks
// the cell while it is in flight, confirms it, and on a refusal puts the old
// value back and says why.
(function () {
  'use strict';

  var root = document.querySelector('.board-grid-head');
  if (!root) return;

  // --- saying what happened -------------------------------------------------
  var note = document.createElement('div');
  note.className = 'board-note-bar';
  note.setAttribute('role', 'status');
  note.setAttribute('aria-live', 'polite');
  note.hidden = true;
  document.body.appendChild(note);

  var noteTimer = null;
  function say(text, bad) {
    note.textContent = text;
    note.classList.toggle('is-bad', !!bad);
    note.hidden = false;
    if (noteTimer) clearTimeout(noteTimer);
    // A refusal stays up longer: it has to be read, not glimpsed.
    noteTimer = setTimeout(function () { note.hidden = true; }, bad ? 7000 : 2600);
  }

  /**
   * Post one cell and report. `field` is the action, exactly as
   * artist_may_post() names it — the field being present IS the instruction.
   */
  function save(el, field, extra, onOk) {
    // ⚠ WHAT IT HELD BEFORE, CAPTURED HERE. The first version re-remembered the
    // cell straight after calling this, which ran before the answer came back —
    // so a refusal "restored" the value that had just been refused, and the
    // board showed a status the database did not hold. Taken at the moment the
    // request leaves, and only overwritten once the server has agreed.
    var was = el.getAttribute('data-was');
    var body = new URLSearchParams();
    body.set(field, '1');
    body.set('project_id', el.getAttribute('data-project'));
    body.set('step_id', el.getAttribute('data-step'));
    body.set('item_key', el.getAttribute('data-item'));
    Object.keys(extra).forEach(function (k) { body.set(k, extra[k]); });

    el.classList.add('is-saving');
    el.disabled = true;

    fetch('board_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () {
        // A login that expired returns the login page, not JSON. Saying so
        // beats "Unexpected token <".
        throw new Error('The server did not answer in a way this page understands. '
                      + 'Your session may have expired — reload the page.');
      });
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'That did not save.');
      el.classList.remove('is-saving');
      el.disabled = false;
      el.classList.add('is-saved');
      setTimeout(function () { el.classList.remove('is-saved'); }, 900);
      el.setAttribute('data-was', el.value);     // agreed: this is the value now
      if (onOk) onOk(data);
      if (data.message) say(data.message, false);
    }).catch(function (err) {
      el.classList.remove('is-saving');
      el.disabled = false;
      // Put back what was there. A cell showing a value the database does not
      // hold is the one outcome worse than a visible failure.
      if (was !== null && (el.tagName === 'SELECT' || el.tagName === 'INPUT')) {
        el.value = was;
        if (el.matches('.board-status')) {
          var opt = el.options[el.selectedIndex];
          var back = opt ? opt.getAttribute('data-color') : '';
          el.style.background = back ? tint(back) : '';
          el.style.color = back || '';
        }
      }
      say(err.message, true);
    });
  }

  // Remember what a control held, so a refusal can put it back.
  function remember(el) { el.setAttribute('data-was', el.value); }
  document.addEventListener('focusin', function (e) {
    if (e.target.matches('.board-status, .board-crew, .board-due, .board-text')) remember(e.target);
  });

  // --- status ----------------------------------------------------------------
  document.addEventListener('change', function (e) {
    var el = e.target;

    if (el.matches('.board-status')) {
      save(el, 'set_status', { status_id: el.value }, function (data) {
        // The pill follows the status it now holds, so the colour never
        // disagrees with the word next to it.
        el.style.background = data.color ? tint(data.color) : '';
        el.style.color = data.color || '';
        var item = el.closest('.board-item');
        if (item) redrawDerived(item);
      });
      return;
    }

    if (el.matches('.board-crew')) {
      save(el, 'set_crew', { crew_id: el.value });
      return;
    }

    if (el.matches('.board-due')) {
      save(el, 'set_due', { due: el.value }, function (data) {
        el.classList.toggle('is-late', !!data.late);
      });
      return;
    }
  });

  // A text box saves when it is left, not on every keystroke: a link is pasted
  // in one go, and a note is a sentence.
  document.addEventListener('blur', function (e) {
    var el = e.target;
    if (!el.matches || !el.matches('.board-text')) return;
    if (el.value === el.getAttribute('data-was')) return;
    save(el, 'set_text', { field: el.getAttribute('data-field'), value: el.value });
  }, true);

  /** A hex at the opacity the pills use. Mirrors board_tint() in PHP. */
  function tint(hex) {
    var h = String(hex).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    if (!/^[0-9a-f]{6}$/i.test(h)) return '';
    return 'rgba(' + parseInt(h.slice(0, 2), 16) + ',' + parseInt(h.slice(2, 4), 16)
         + ',' + parseInt(h.slice(4, 6), 16) + ',.15)';
  }

  /**
   * A shot's own status, redrawn from the tasks under it.
   *
   * ⚠ THE SAME THREE ANSWERS board_item_status() GIVES IN PHP, in the same
   * order. These two must move together: a derived figure that disagrees with
   * the page it was rendered on is worse than no figure.
   */
  function redrawDerived(item) {
    var cell = item.querySelector('.board-row-item .board-derived');
    if (!cell) return;
    var picks = item.querySelectorAll('.board-row-task .board-status');
    if (!picks.length) return;

    // Read from what each status MEANS, carried on the option by board.php —
    // never from its name, which every show spells its own way.
    var live = 0, done = 0, started = 0;
    Array.prototype.forEach.call(picks, function (sel) {
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      if (opt.getAttribute('data-excluded') === '1') return;   // N/A leaves the reckoning
      live++;
      if (opt.getAttribute('data-approved') === '1') done++;
      if (opt.value !== '' && opt.getAttribute('data-blank') !== '1') started++;
    });

    var name = 'Not started', color = 'var(--violet)';
    if (live > 0 && done === live) { name = 'Finished'; color = 'var(--ok)'; }
    else if (started > 0) { name = 'In progress'; color = 'var(--warn)'; }

    var dot = cell.querySelector('.board-dot');
    var text = cell.querySelector('span:last-child');
    if (dot) dot.style.background = color;
    if (text) { text.textContent = name; text.style.color = color; }
  }

  // --- expanding ------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var caret = e.target.closest ? e.target.closest('.board-caret') : null;
    if (!caret || caret.classList.contains('board-caret-blank')) return;

    var group = caret.closest('.board-group-head');
    if (group) {
      var section = caret.closest('.board-group');
      var shut = section.classList.toggle('is-shut');
      caret.classList.toggle('is-open', !shut);
      caret.setAttribute('aria-expanded', shut ? 'false' : 'true');
      return;
    }

    var item = caret.closest('.board-item');
    if (!item) return;
    var open = item.classList.toggle('is-open');
    caret.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  /**
   * Open or shut every shot in `scope` (the whole page, or one group).
   *
   * ⚠ OPENING ALSO UN-SHUTS THE GROUP. Expanding the shots inside a collapsed
   * group would change nothing anybody can see, which reads as a broken button.
   */
  function setAll(open, scope) {
    scope = scope || document;
    if (open) {
      Array.prototype.forEach.call(scope.querySelectorAll('.board-group'), unshutGroup);
      if (scope.classList && scope.classList.contains('board-group')) unshutGroup(scope);
    }
    Array.prototype.forEach.call(scope.querySelectorAll('.board-item'), function (item) {
      if (!item.querySelector('.board-row-task')) return;
      item.classList.toggle('is-open', open);
      var c = item.querySelector('.board-row-item .board-caret');
      if (c) c.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  function unshutGroup(section) {
    section.classList.remove('is-shut');
    var c = section.querySelector('.board-group-head .board-caret');
    if (c) { c.classList.add('is-open'); c.setAttribute('aria-expanded', 'true'); }
  }

  var openAll = document.getElementById('boardOpenAll');
  var shutAll = document.getElementById('boardShutAll');
  if (openAll) openAll.addEventListener('click', function () { setAll(true); });
  if (shutAll) shutAll.addEventListener('click', function () { setAll(false); });

  // --- the per-group menu ---------------------------------------------------
  function shutMenus(except) {
    Array.prototype.forEach.call(document.querySelectorAll('.board-group-pop'), function (pop) {
      if (pop === except) return;
      pop.hidden = true;
      var btn = pop.parentNode.querySelector('.board-group-menu-btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.board-group-menu-btn') : null;
    if (btn) {
      var pop = btn.parentNode.querySelector('.board-group-pop');
      var show = pop.hidden;
      shutMenus(show ? pop : null);
      pop.hidden = !show;
      btn.setAttribute('aria-expanded', show ? 'true' : 'false');
      return;
    }

    var act = e.target.closest ? e.target.closest('.board-group-pop button') : null;
    if (act) {
      setAll(act.getAttribute('data-act') === 'open', act.closest('.board-group'));
      shutMenus(null);
      return;
    }
    shutMenus(null);                       // a click anywhere else closes it
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') shutMenus(null);
  });

  // --- searching ------------------------------------------------------------
  //
  // In the browser, like every other filter on this platform, and for the same
  // reason: these rows hold unsaved typing, and a server-side search would
  // reload the page under somebody's hands.
  var search = document.getElementById('boardSearch');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      Array.prototype.forEach.call(document.querySelectorAll('.board-item'), function (item) {
        if (q === '') {
          item.hidden = false;
          Array.prototype.forEach.call(item.querySelectorAll('.board-row-task'), function (r) {
            r.hidden = false;
          });
          return;
        }
        var itemRow = item.querySelector('.board-row-item');
        var itemHit = itemRow && (itemRow.getAttribute('data-search') || '').indexOf(q) !== -1;
        var any = itemHit;
        Array.prototype.forEach.call(item.querySelectorAll('.board-row-task'), function (r) {
          var hit = itemHit || (r.getAttribute('data-search') || '').indexOf(q) !== -1;
          r.hidden = !hit;
          if (hit) any = true;
        });
        item.hidden = !any;
        // A match deep in a shut shot is no use hidden.
        if (any && !itemHit) item.classList.add('is-open');
      });
    });
  }
}());
