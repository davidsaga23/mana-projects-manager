// Spell (spell.php, formerly the Board and board.php, 2026-10-22): expanding, searching, and saving a cell
// without reloading the page.
//
// ⚠ NAMED board-screen.js, NOT board.js, which is already taken — by the
// TASKS kanban that tasks.php loads ("the tasks board: drag between columns").
// Two different screens have a fair claim on the word, and the older one had
// it first; renaming that file would have touched a screen in daily use for
// the sake of a filename.
//
// ⚠ NOTHING HERE IS A PERMISSION. Every control this file touches was already
// drawn as editable by spell.php, and board_api.php checks the same rules again
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
    // A container's own note has no step: that is what distinguishes it from a
    // task's note, here and in board_api.php.
    if (el.getAttribute('data-step')) body.set('step_id', el.getAttribute('data-step'));
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
      mirror(el);                                // the row and the panel agree
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
        if (item) { redrawDerived(item); redrawBar(item); }
        redrawGroupBar(el.closest('.board-group'));
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
    if (el.classList.contains('board-item-text')) {
      save(el, 'set_item_note', { value: el.value });
      return;
    }
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

    // Read from what each status MEANS, carried on the option by spell.php —
    // never from its name, which every show spells its own way.
    var live = 0, done = 0, started = 0;
    Array.prototype.forEach.call(picks, function (sel) {
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      if (opt.getAttribute('data-excluded') === '1') return;   // N/A leaves the reckoning
      live++;
      if (opt.getAttribute('data-approved') === '1') done++;
      if (opt.getAttribute('data-begun') === '1') started++;
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
    noteOpen(item, open);
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
      noteOpen(item, open);
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

  // --- the bar on a shot ----------------------------------------------------
  //
  // ⚠ THE SAME ORDER board_item_bar() USES IN PHP, from the same facts carried
  // on each option: finished first, then delivered, then blocked, then started,
  // then nothing yet. These two must move together, or a bar would re-sort
  // itself the moment somebody touched a status.
  function rankOf(opt) {
    if (!opt || opt.value === '') return 4;
    if (opt.getAttribute('data-approved') === '1') return 0;
    if (opt.getAttribute('data-delivered') === '1') return 1;
    if (opt.getAttribute('data-blocked') === '1') return 2;
    return 3;
  }

  function redrawBar(item) {
    drawBar(item.querySelector('.board-row-item .board-bar'),
            item.querySelectorAll('.board-row-task .board-status'));
  }

  /**
   * The group's own bar, over every task in it.
   *
   * ⚠ EVERY TASK, not its shots' bars averaged — averaging percentages weights
   * a 2-task deliverable the same as an 8-task shot, and a phase would read
   * finished with half its work outstanding. board_item_bar() in PHP builds it
   * the same way.
   */
  function redrawGroupBar(section) {
    if (!section) return;
    drawBar(section.querySelector('.board-group-head .board-bar'),
            section.querySelectorAll('.board-status'));
  }

  function drawBar(bar, picks) {
    if (!bar || !picks || !picks.length) return;
    var track = bar.querySelector('.board-bar-track');
    var pop = bar.querySelector('.board-bar-pop');
    if (!track) return;

    var buckets = {}, total = 0, done = 0;
    Array.prototype.forEach.call(picks, function (sel) {
      var opt = sel.options[sel.selectedIndex];
      if (!opt || opt.getAttribute('data-excluded') === '1') return;   // N/A is not work
      total++;
      if (opt.getAttribute('data-approved') === '1') done++;
      var key = opt.value === '' ? '__none' : opt.value;
      if (!buckets[key]) {
        buckets[key] = {
          name: opt.value === '' ? 'Not started' : (opt.textContent || '').trim(),
          color: opt.getAttribute('data-color') || 'var(--border-strong)',
          rank: rankOf(opt),
          order: opt.index,
          n: 0
        };
      }
      buckets[key].n++;
    });
    if (!total) { track.innerHTML = ''; if (pop) pop.innerHTML = ''; return; }

    var segs = Object.keys(buckets).map(function (k) { return buckets[k]; });
    segs.sort(function (a, b) { return (a.rank - b.rank) || (a.order - b.order); });

    var sum = done + ' of ' + total + ' tasks finished';
    track.innerHTML = '';
    if (pop) pop.innerHTML = '';

    segs.forEach(function (seg) {
      var span = document.createElement('span');
      span.className = 'board-bar-seg';
      span.style.width = (seg.n / total * 100) + '%';
      span.style.background = seg.color;
      span.title = seg.name + ', ' + seg.n + ' of ' + total;
      track.appendChild(span);

      if (!pop) return;
      // Built as nodes, never as a string of HTML: a status is named by a
      // person, and this one is dropped straight into the page.
      var key = document.createElement('span');
      key.className = 'board-bar-key';
      var dot = document.createElement('i');
      dot.style.background = seg.color;
      key.appendChild(dot);
      key.appendChild(document.createTextNode(seg.name));
      var n = document.createElement('b');
      n.textContent = seg.n;
      key.appendChild(n);
      pop.appendChild(key);
    });

    if (pop) {
      var foot = document.createElement('span');
      foot.className = 'board-bar-sum';
      foot.textContent = sum;
      pop.appendChild(foot);
    }
    bar.setAttribute('aria-label', sum);
  }

  // --- searching and filtering ----------------------------------------------
  //
  // In the browser, like every other filter on this platform, and for the same
  // reason: these rows hold unsaved typing, and a server-side filter would
  // reload the page under somebody's hands.
  //
  // ⚠ A MATCH DEEP IN A SHUT SHOT IS NO USE HIDDEN, so a shot whose TASKS match
  // is opened. Its own open/shut state is put back the moment the filters are
  // cleared, so filtering never quietly rearranges somebody's board.
  var search = document.getElementById('boardSearch');
  var fStatus = document.getElementById('fStatus');
  var fCrew = document.getElementById('fCrew');
  var fDue = document.getElementById('fDue');
  var fDueOn = document.getElementById('fDueOn');
  var fDueOnDate = document.getElementById('fDueOnDate');
  var fDueRange = document.getElementById('fDueRange');
  var fDueFrom = document.getElementById('fDueFrom');
  var fDueTo = document.getElementById('fDueTo');
  var clearBtn = document.getElementById('boardClear');
  var countEl = document.getElementById('boardCount');
  var wasOpen = null;                 // what was expanded before filtering began

  /**
   * Somebody opened or shut a shot BY HAND while a filter was on.
   *
   * ⚠ THAT IS THE STATE TO COME BACK TO, not the one from before they started
   * filtering. Without this, filtering, pressing Collapse all and then Clear
   * re-opened everything — the board undoing a choice the person had just made.
   */
  function noteOpen(item, open) {
    if (wasOpen) wasOpen[item.getAttribute('data-item')] = open;
  }

  function today() { return new Date().toISOString().slice(0, 10); }
  function inDays(n) {
    var d = new Date();
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  }

  /**
   * Monday to Friday of the week we are in.
   *
   * ⚠ BUILT FROM LOCAL DATE PARTS, never by parsing or formatting through UTC.
   * This platform has been bitten by that before (the timeline's rule 2): a
   * 'YYYY-MM-DD' string handed to new Date() is read as UTC and comes back a
   * day early west of Greenwich, which here would quietly shift the whole week.
   */
  function weekMonFri() {
    var d = new Date();
    var dow = d.getDay();                         // 0 Sunday … 6 Saturday
    // Sunday belongs to the week that has just ended, which is how a studio
    // reads "this week" on a Sunday evening.
    var backToMonday = dow === 0 ? 6 : dow - 1;
    var mon = new Date(d.getFullYear(), d.getMonth(), d.getDate() - backToMonday);
    var fri = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + 4);
    return { from: ymd(mon), to: ymd(fri) };
  }

  function ymd(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function dueMatches(want, due) {
    if (want === '') return true;
    if (want === '__none') return due === '';
    if (due === '') return false;                 // every answer below needs a date
    if (want === 'set') return true;
    if (want === 'late') return due < today();
    if (want === 'soon') return due >= today() && due <= inDays(7);
    if (want === 'today') return due === today();
    if (want === 'yesterday') return due === inDays(-1);
    if (want === 'week') {
      var w = weekMonFri();
      return due >= w.from && due <= w.to;
    }
    if (want === 'on') {
      var on = fDueOnDate ? fDueOnDate.value : '';
      // Nothing picked yet filters nothing, rather than hiding the whole board.
      return on === '' ? true : due === on;
    }
    if (want === 'range') {
      var from = fDueFrom ? fDueFrom.value : '';
      var to = fDueTo ? fDueTo.value : '';
      if (from !== '' && due < from) return false;
      if (to !== '' && due > to) return false;
      return true;
    }
    return true;
  }

  function applyFilters() {
    var q = search ? search.value.trim().toLowerCase() : '';
    var st = fStatus ? fStatus.value : '';
    var cr = fCrew ? fCrew.value : '';
    var du = fDue ? fDue.value : '';

    // The extra boxes belong to their own choice and nothing else.
    if (fDueOn) fDueOn.hidden = du !== 'on';
    if (fDueRange) fDueRange.hidden = du !== 'range';

    var on = q !== '' || st !== '' || cr !== '' || du !== '';

    // Remember the board as the person left it, once, so Clear can restore it.
    if (on && wasOpen === null) {
      wasOpen = {};
      Array.prototype.forEach.call(document.querySelectorAll('.board-item'), function (item) {
        wasOpen[item.getAttribute('data-item')] = item.classList.contains('is-open');
      });
    }

    var shown = 0, all = 0;
    Array.prototype.forEach.call(document.querySelectorAll('.board-item'), function (item) {
      var itemRow = item.querySelector('.board-row-item');
      var itemHit = itemRow && (itemRow.getAttribute('data-search') || '').indexOf(q) !== -1;
      var tasks = item.querySelectorAll('.board-row-task');
      var any = false;

      Array.prototype.forEach.call(tasks, function (r) {
        all++;
        // The search matches a shot OR a task; the three dropdowns are about a
        // task alone, so a shot's name never satisfies them.
        var hit = (q === '' || itemHit || (r.getAttribute('data-search') || '').indexOf(q) !== -1)
               && (st === '' || r.getAttribute('data-status') === st)
               && (cr === '' || r.getAttribute('data-crew') === cr)
               && dueMatches(du, r.getAttribute('data-due') || '');
        r.hidden = on && !hit;
        if (hit) { any = true; shown++; }
      });

      if (!on) {
        item.hidden = false;
        if (wasOpen) item.classList.toggle('is-open', !!wasOpen[item.getAttribute('data-item')]);
        return;
      }
      // A row with no tasks at all (a whole-show deliverable) rides on its name.
      item.hidden = tasks.length ? !any : !itemHit;
      if (any) item.classList.add('is-open');
    });

    if (!on) wasOpen = null;
    if (clearBtn) clearBtn.hidden = !on;
    if (countEl) {
      countEl.textContent = on ? (shown + ' of ' + all + ' tasks match') : '';
      countEl.classList.toggle('is-empty', on && shown === 0);
    }
  }

  if (search) search.addEventListener('input', applyFilters);
  [fStatus, fCrew, fDue, fDueOnDate, fDueFrom, fDueTo].forEach(function (el) {
    if (el) el.addEventListener('change', applyFilters);
  });
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (search) search.value = '';
      [fStatus, fCrew, fDue, fDueOnDate, fDueFrom, fDueTo].forEach(function (el) {
        if (el) el.value = '';
      });
      applyFilters();
    });
  }

  // ⚠ A ROW CARRIES WHAT THE FILTERS READ, so a cell that has just been saved
  // has to update it — otherwise a task moved to Final stays visible under a
  // filter for In progress, and the board quietly disagrees with itself.
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.matches) return;
    var row = el.closest ? el.closest('.board-row-task') : null;
    if (!row) return;
    if (el.matches('.board-status')) {
      row.setAttribute('data-status', el.value === '' ? '__none' : el.value);
    } else if (el.matches('.board-crew')) {
      row.setAttribute('data-crew', el.value === '' ? '__none' : el.value);
    } else if (el.matches('.board-due')) {
      row.setAttribute('data-due', el.value);
    }
  });

  // --- the task panel -------------------------------------------------------
  //
  // David, 2026-10-22: the detailed view of a task, "instead of taking you to
  // another page it should just pop up in the same screen so you can open,
  // adjust, close and open another one".
  //
  // ⚠ THE PANEL'S CONTROLS ARE THE BOARD'S CONTROLS. board_task.php renders the
  // same helpers with the same classes and the same three data attributes, so
  // every handler above already saves them and nothing about saving is written
  // twice. What IS needed is keeping the two copies of a cell in step, which
  // mirror() below does.
  var panel = document.getElementById('boardPanel');
  var panelInner = document.getElementById('boardPanelInner');
  var veil = document.getElementById('boardVeil');
  var panelClose = document.getElementById('boardPanelClose');
  var cameFrom = null;              // the button that opened it, to return focus to

  function panelOpen(btn) {
    if (!panel) return;
    cameFrom = btn;
    panelInner.innerHTML = '<p class="muted small">Opening…</p>';
    panel.hidden = false;
    if (veil) veil.hidden = false;
    document.body.classList.add('has-board-panel');

    var url = 'board_task.php?project=' + encodeURIComponent(btn.getAttribute('data-project'))
            + '&step=' + encodeURIComponent(btn.getAttribute('data-step'))
            + '&item=' + encodeURIComponent(btn.getAttribute('data-item'));

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        panelInner.innerHTML = html;
        // Into the panel, so the keyboard is where the eye is.
        var first = panelInner.querySelector('select, input, button, a');
        if (first) first.focus();
        commentsReady();
      })
      .catch(function () {
        panelInner.innerHTML = '<p class="err">That did not open. Check your connection and try again.</p>';
      });
  }

  function panelShut() {
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    if (veil) veil.hidden = true;
    document.body.classList.remove('has-board-panel');
    panelInner.innerHTML = '';
    if (cameFrom) { cameFrom.focus(); cameFrom = null; }
  }

  document.addEventListener('click', function (e) {
    var open = e.target.closest ? e.target.closest('.board-open-task') : null;
    if (open) { panelOpen(open); return; }
    if (e.target === veil) panelShut();
    var tab = e.target.closest ? e.target.closest('.panel-tab') : null;
    if (tab) {
      Array.prototype.forEach.call(panelInner.querySelectorAll('.panel-tab'), function (t) {
        var on = t === tab;
        t.classList.toggle('is-on', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Array.prototype.forEach.call(panelInner.querySelectorAll('.panel-body'), function (b) {
        b.hidden = b.getAttribute('data-pane') !== tab.getAttribute('data-tab');
      });
    }
  });


  // --- the Comments tab -----------------------------------------------------
  //
  // David, 2026-10-22: "let's also add a comments section tab between details
  // and activity", then "those comments are the ones that currently live under
  // my tasks, they're the exact same just displayed in this context too", and
  // "remember to have the option to create threads".
  //
  // ⚠ THE SERVER RENDERS THE PANE, BEFORE AND AFTER. board_comment.php answers
  // a post with the whole comments pane, drawn by the same PHP function that
  // drew it when the panel opened — so nothing about a comment's shape, its
  // indent or its highlighted mentions is written a second time here. This is
  // only the three things a page reload would otherwise have done: send the
  // form, swap the pane, put the picker back on the new box.

  /** Re-arm a freshly rendered comments pane: the @ picker, and the count. */
  function commentsReady() {
    var pane = panelInner ? panelInner.querySelector('[data-pane="comments"]') : null;
    if (!pane) return;

    var box = pane.querySelector('.board-comment-box');
    var json = pane.querySelector('.board-comment-people');
    if (box && json && window.animaMentions) {
      var people = [];
      try { people = JSON.parse(json.textContent || '[]'); } catch (err) { people = []; }
      // The same picker the ticket screen uses, attached to a box that did not
      // exist when the page loaded. See assets/mentions.js.
      window.animaMentions.attach(box, people);
    }

    // "Comments · 4" on the tab, kept honest after a post without reloading
    // anything: the pane in front of us IS the count.
    var tab = panelInner.querySelector('.panel-tab[data-tab="comments"]');
    if (!tab) return;
    var n = pane.querySelectorAll('.comment').length;
    var badge = tab.querySelector('.muted');
    if (n === 0) { if (badge) badge.remove(); return; }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'muted';
      tab.appendChild(document.createTextNode(' '));
      tab.appendChild(badge);
    }
    badge.textContent = '· ' + n;
  }

  /** The hidden parent_id is what makes a comment a reply (migration 039). */
  function replyTo(form, id, who) {
    var field = form.querySelector('input[name="parent_id"]');
    var banner = form.querySelector('.reply-banner');
    var box = form.querySelector('.board-comment-box');
    if (field) field.value = id || '';
    if (banner) {
      banner.hidden = !id;
      var name = banner.querySelector('strong');
      if (name) name.textContent = who || 'that comment';
    }
    if (box) box.focus();
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var form = e.target.closest('.board-comment-form');

    var reply = e.target.closest('.comment-reply-btn');
    if (reply && panelInner && panelInner.contains(reply)) {
      // The button sits above the form, not inside it — one box per pane, the
      // same shape the ticket screen settled on.
      form = panelInner.querySelector('.board-comment-form');
      if (form) {
        replyTo(form, reply.getAttribute('data-reply-to'), reply.getAttribute('data-reply-name'));
        form.scrollIntoView({ block: 'nearest' });
      }
      return;
    }

    if (form && e.target.closest('[data-reply-cancel]')) replyTo(form, '', '');
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest ? e.target.closest('.board-comment-form') : null;
    if (!form) return;
    e.preventDefault();

    var pane = form.closest('[data-pane="comments"]');
    var btn = form.querySelector('button[type="submit"]');
    var box = form.querySelector('.board-comment-box');
    if (!pane || !box) return;
    if (!box.value.trim()) { box.focus(); return; }

    // ⚠ LOCKED WHILE IT IS IN FLIGHT. Naming somebody opens SMTP inline, so a
    // comment with an @ in it can take a second or two — long enough for an
    // impatient second click to post the same paragraph twice.
    if (btn) { btn.disabled = true; btn.textContent = 'Posting…'; }

    var data = new FormData(form);
    data.append('project_id', form.getAttribute('data-project'));
    data.append('step_id', form.getAttribute('data-step'));
    data.append('item_key', form.getAttribute('data-item'));

    fetch('board_comment.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        pane.innerHTML = html;
        commentsReady();
        var fresh = pane.querySelector('.board-comment-box');
        if (fresh) fresh.focus();
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Post'; }
        var warn = document.createElement('p');
        warn.className = 'err';
        warn.textContent = 'That did not post. Check your connection and try again — your '
                         + 'words are still in the box.';
        form.insertBefore(warn, form.firstChild);
      });
  });

  // --- ?open=<step>:<item> --------------------------------------------------
  //
  // What a mention email links to (notify_mentioned_task()): the task itself,
  // not a screen of four hundred rows with "somewhere in here" as the
  // instruction. The group it sits in has to be opened first, because the board
  // starts with every item collapsed.
  (function openFromUrl() {
    var want = new URLSearchParams(window.location.search).get('open');
    if (!want) return;
    var bits = want.split(':');
    if (bits.length < 2) return;
    var btn = document.querySelector('.board-open-task[data-step="' + CSS.escape(bits[0])
            + '"][data-item="' + CSS.escape(bits.slice(1).join(':')) + '"]');
    if (!btn) return;
    // Both wrappers, in the board's own vocabulary: a group is shut with
    // `is-shut`, a shot is opened with `is-open`. Un-shutting the group first,
    // for the reason setAll() gives — opening a row inside a collapsed group
    // changes nothing anybody can see.
    var group = btn.closest('.board-group');
    if (group) unshutGroup(group);
    var item = btn.closest('.board-item');
    if (item) {
      item.classList.add('is-open');
      var c = item.querySelector('.board-row-item .board-caret');
      if (c) c.setAttribute('aria-expanded', 'true');
    }
    btn.scrollIntoView({ block: 'center' });
    panelOpen(btn);
  }());

  if (panelClose) panelClose.addEventListener('click', panelShut);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel && !panel.hidden) panelShut();
  });

  /**
   * The same cell exists twice while the panel is open — once in the row behind
   * it, once in the panel. A save through either has to move both, or closing
   * the panel would reveal a row still showing the old value.
   */
  function mirror(el) {
    var step = el.getAttribute('data-step');
    var item = el.getAttribute('data-item');
    var field = el.getAttribute('data-field');
    if (!step || !item) return;

    // ⚠ MATCHED ON THE CONTROL'S KIND, never on its whole class string. The
    // first version compared className, and save() adds `is-saved` to the cell
    // it has just written one line before calling this — so the two copies
    // never looked alike and the row silently kept the old value.
    var KINDS = ['board-status', 'board-crew', 'board-due', 'board-text'];
    var kind = null;
    KINDS.forEach(function (k) { if (el.classList.contains(k)) kind = k; });
    if (!kind) return;

    var sel = '[data-step="' + step + '"][data-item="' + item + '"]';
    Array.prototype.forEach.call(document.querySelectorAll(sel), function (other) {
      if (other === el || !other.classList.contains(kind)) return;
      // A text cell is only the same cell when it is the same field.
      if (field && other.getAttribute('data-field') !== field) return;
      if (other.value === el.value) return;
      other.value = el.value;
      if (other.matches('.board-status')) {
        var opt = other.options[other.selectedIndex];
        var color = opt ? opt.getAttribute('data-color') : '';
        other.style.background = color ? tint(color) : '';
        other.style.color = color || '';
      }
    });

    // The row's own attributes feed the filters, and its shot's bar and derived
    // status are drawn from the row, so both are refreshed from whichever copy
    // was touched.
    var rowCtl = document.querySelector('.board-row-task ' + sel);
    var host = rowCtl ? rowCtl.closest('.board-row-task') : null;
    if (host) {
      var st = host.querySelector('.board-status');
      var cr = host.querySelector('.board-crew');
      var du = host.querySelector('.board-due');
      if (st) host.setAttribute('data-status', st.value === '' ? '__none' : st.value);
      if (cr) host.setAttribute('data-crew', cr.value === '' ? '__none' : cr.value);
      if (du) host.setAttribute('data-due', du.value);
      var item2 = host.closest('.board-item');
      if (item2) { redrawDerived(item2); redrawBar(item2); }
      redrawGroupBar(host.closest('.board-group'));
    }
  }

}());
