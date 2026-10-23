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
  // A supervisor's two: which department, and what can start today.
  var fStep = document.getElementById('fStep');
  var fReady = document.getElementById('fReady');
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
    var sp = fStep ? fStep.value : '';
    var rd = fReady ? fReady.value : '';

    // The extra boxes belong to their own choice and nothing else.
    if (fDueOn) fDueOn.hidden = du !== 'on';
    if (fDueRange) fDueRange.hidden = du !== 'range';

    var on = q !== '' || st !== '' || cr !== '' || du !== '' || sp !== '' || rd !== '';

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
               && (sp === '' || r.getAttribute('data-step-id') === sp)
               && (rd === '' || r.getAttribute('data-ready') === rd)
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
  [fStatus, fCrew, fStep, fReady, fDue, fDueOnDate, fDueFrom, fDueTo].forEach(function (el) {
    if (el) el.addEventListener('change', applyFilters);
  });
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (search) search.value = '';
      [fStatus, fCrew, fStep, fReady, fDue, fDueOnDate, fDueFrom, fDueTo].forEach(function (el) {
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
  var panelWants = null;            // a tab and a row asked for by the URL, once

  // tab: open on that tab rather than Details. reviewsOf: a version id whose
  // review notes to show — the Notes column leads straight to what it counts.
  function panelOpen(btn, tab, reviewsOf) {
    if (!panel) return;
    cameFrom = btn;
    tlModal = null;      // a pop-up from the last task went with its contents
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
        paneReady('comments');
        paneReady('versions');
        if (window.animaDropzone) window.animaDropzone.attachAll(panelInner);

        // A link from Slack says which tab and which row. Done once, and
        // cleared, so closing and reopening a task by hand behaves normally.
        if (panelWants) {
            var wants = panelWants;
            panelWants = null;
            if (wants.tab) showTab(wants.tab);
            if (wants.frag) {
                var target = panelInner.querySelector('#' + CSS.escape(wants.frag));
                if (target) {
                    target.scrollIntoView({ block: 'center' });
                    // Said, not just scrolled to: in a long thread "it is the
                    // one in the middle of your screen" is not obvious.
                    target.classList.add('is-linked');
                    setTimeout(function () { target.classList.remove('is-linked'); }, 2400);
                }
            }
        }
        if (tab) showTab(tab);
        if (reviewsOf) {
          var thread = panelInner.querySelector('#vr' + CSS.escape(String(reviewsOf)));
          var opener = panelInner.querySelector('[data-version-reviews="vr' + CSS.escape(String(reviewsOf)) + '"]');
          if (thread) { thread.hidden = false; thread.scrollIntoView({ block: 'start' }); }
          if (opener) opener.setAttribute('aria-expanded', 'true');
        }
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
    tlModal = null;      // went with the panel's contents
    panelInner.innerHTML = '';
    if (cameFrom) { cameFrom.focus(); cameFrom = null; }
  }

  document.addEventListener('click', function (e) {
    var open = e.target.closest ? e.target.closest('.board-open-task') : null;
    if (open) { panelOpen(open); return; }
    // The Notes column: straight to that version's review notes, shown.
    var notesBtn = e.target.closest ? e.target.closest('.board-notes-btn') : null;
    if (notesBtn) { panelOpen(notesBtn, 'versions', notesBtn.getAttribute('data-version')); return; }
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

  // What each refreshable pane is made of: where it posts, and what one of its
  // rows looks like for the count on its tab. Two entries, one piece of code —
  // the versions pane arrived (2026-10-22) and needed none of this written
  // again.
  var PANES = {
    comments: { url: 'board_comment.php', row: '.comment' },
    versions: { url: 'board_version.php', row: '.version' }
  };

  /** Re-arm a freshly rendered pane: the @ picker where there is one, and the count. */
  function paneReady(which) {
    var spec = PANES[which];
    var pane = panelInner ? panelInner.querySelector('[data-pane="' + which + '"]') : null;
    if (!spec || !pane) return;

    var box = pane.querySelector('.board-comment-box');
    var json = pane.querySelector('.board-comment-people');
    if (box && json && window.animaMentions) {
      var people = [];
      try { people = JSON.parse(json.textContent || '[]'); } catch (err) { people = []; }
      // The same picker the ticket screen uses, attached to a box that did not
      // exist when the page loaded. See assets/mentions.js.
      window.animaMentions.attach(box, people);
    }

    // "Drop files here" over the native picker, on whatever this pane just
    // rendered. See assets/dropzone.js — it enhances the real input and the
    // form still submits exactly what it submitted before.
    if (window.animaDropzone) window.animaDropzone.attachAll(pane);

    // "Comments · 4" on the tab, kept honest after a post without reloading
    // anything: the pane in front of us IS the count.
    var tab = panelInner.querySelector('.panel-tab[data-tab="' + which + '"]');
    if (!tab) return;
    var n = pane.querySelectorAll(spec.row).length;
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

  /** Show one tab, as if its button had been pressed. */
  function showTab(which) {
    if (!panelInner) return;
    var tab = panelInner.querySelector('.panel-tab[data-tab="' + which + '"]');
    if (tab) tab.click();
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

    // A version's own button (2026-10-22): its review notes, shown or hidden in
    // place, or — where there are none and you review — the form, set to it.
    var notes = e.target.closest('.version-reviews-btn');
    if (notes && panelInner && panelInner.contains(notes)) {
      var target = notes.getAttribute('data-version-reviews');
      if (target) {
        var thread = panelInner.querySelector('#' + CSS.escape(target));
        if (thread) {
          thread.hidden = !thread.hidden;
          notes.setAttribute('aria-expanded', thread.hidden ? 'false' : 'true');
        }
      } else {
        var rf = panelInner.querySelector('.board-review-form');
        if (rf) {
          var pick = rf.querySelector('select[name="version_id"]');
          if (pick) pick.value = notes.getAttribute('data-review-version');
          rf.scrollIntoView({ block: 'nearest' });
          var rb = rf.querySelector('.board-review-box');
          if (rb) rb.focus();
        }
      }
      return;
    }

    // Jumping to the Versions tab from the Current version line on Details.
    var jump = e.target.closest('.panel-tab-link');
    if (jump && panelInner && panelInner.contains(jump)) {
      e.preventDefault();
      showTab(jump.getAttribute('data-tab') || 'versions');
      return;
    }

    // Taking a file back — a comment's attachment, or the latest version.
    // Asked first, because both delete the file from the server, not just a row.
    var del = e.target.closest('.board-att-del, .board-version-del');
    if (del && panelInner && panelInner.contains(del)) {
      e.preventDefault();
      var isVersion = del.classList.contains('board-version-del');
      if (!window.confirm(isVersion
            ? 'Remove this version? The file is deleted from the server.'
            : 'Remove this file? It is deleted from the server.')) return;

      var pane = del.closest('[data-pane]');
      if (!pane) return;
      var which = pane.getAttribute('data-pane');
      var spec = PANES[which];
      var host = pane.querySelector('form[data-project]');
      if (!spec || !host) return;
      del.disabled = true;

      var gone = new FormData();
      gone.append(isVersion ? 'delete_version' : 'delete_attachment',
                  del.getAttribute(isVersion ? 'data-version' : 'data-att'));
      gone.append('project_id', host.getAttribute('data-project'));
      gone.append('step_id', host.getAttribute('data-step'));
      gone.append('item_key', host.getAttribute('data-item'));

      fetch(spec.url, { method: 'POST', body: gone, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { pane.innerHTML = html; paneReady(which); timelineRefresh(); })
        .catch(function () { del.disabled = false; });
    }
  });

  /**
   * Re-draw the Timeline tab after anything was posted or taken back in the
   * panel (David, 2026-10-22: "I just left a comment and it didn't update in
   * the timeline"). The task is read off any control in the panel that names
   * it; the count on the tab follows. Quiet on failure: the post itself worked,
   * and reopening the task shows the timeline as it is.
   */
  function timelineRefresh() {
    if (!panelInner) return;
    var pane = panelInner.querySelector('[data-pane="activity"]');
    var who = panelInner.querySelector('[data-project][data-step][data-item]');
    if (!pane || !who) return;
    var url = 'board_task.php?pane=timeline&project=' + encodeURIComponent(who.getAttribute('data-project'))
            + '&step=' + encodeURIComponent(who.getAttribute('data-step'))
            + '&item=' + encodeURIComponent(who.getAttribute('data-item'));
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (html === null || !panelInner.contains(pane)) return;
        pane.innerHTML = html;
        var tab = panelInner.querySelector('.panel-tab[data-tab="activity"]');
        if (!tab) return;
        var n = pane.querySelectorAll('.tl-entry').length;
        var badge = tab.querySelector('.muted');
        if (n === 0) { if (badge) badge.remove(); return; }
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'muted';
          tab.appendChild(document.createTextNode(' '));
          tab.appendChild(badge);
        }
        badge.textContent = '· ' + n;
      })
      .catch(function () {});
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest
      ? e.target.closest('.board-comment-form, .board-version-form') : null;
    if (!form) return;
    e.preventDefault();

    var pane = form.closest('[data-pane]');
    if (!pane) return;
    var which = pane.getAttribute('data-pane');
    var spec = PANES[which];
    if (!spec) return;

    var btn = form.querySelector('button[type="submit"]');
    var box = form.querySelector('.board-comment-box');
    var picker = form.querySelector('input[type="file"]');

    // A comment needs words or a file; a version IS a file, so it needs one.
    // A review, like a comment, needs words or a picture.
    if (which === 'comments' || form.classList.contains('board-review-form')) {
      var hasFile = picker && picker.files && picker.files.length > 0;
      if ((!box || !box.value.trim()) && !hasFile) { if (box) box.focus(); return; }
    } else if (form.getAttribute('data-naming-bad') === '1') {
      return;   // a file that breaks the naming convention: listed under the picker
    } else if (!picker || !picker.files || !picker.files.length) {
      picker.focus();
      return;
    }

    // ⚠ LOCKED WHILE IT IS IN FLIGHT. Naming somebody opens SMTP inline and a
    // version can be tens of megabytes — either is long enough for an impatient
    // second click to send the same thing twice.
    var was = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }

    var data = new FormData(form);
    data.append('project_id', form.getAttribute('data-project'));
    data.append('step_id', form.getAttribute('data-step'));
    data.append('item_key', form.getAttribute('data-item'));

    fetch(spec.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        pane.innerHTML = html;
        paneReady(which);
        timelineRefresh();
        var fresh = pane.querySelector('.board-comment-box');
        if (fresh) fresh.focus();
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = was; }
        var warn = document.createElement('p');
        warn.className = 'err';
        warn.textContent = 'That did not go through. Check your connection and try again — '
                         + 'nothing in this box has been lost.';
        form.insertBefore(warn, form.firstChild);
      });
  });

  // --- ?open=<step>:<item> --------------------------------------------------
  //
  // What a mention email links to (notify_mentioned_task()): the task itself,
  // not a screen of four hundred rows with "somewhere in here" as the
  // instruction. The group it sits in has to be opened first, because the board
  // starts with every item collapsed.
  // --- the files going with a review --------------------------------------------
  //
  // David, 2026-10-22: "make sure you can just copy paste images directly into
  // the text field", then "small vignettes of the pasted or attached files with
  // a cross in the corner so you can easily delete them".
  //
  // ⚠ ONE LIST PER FORM, KEPT HERE, WRITTEN BACK TO THE INPUT. A file input
  // REPLACES its files every time somebody picks, so a pasted screenshot would
  // vanish the moment a file was chosen after it. So every paste and every pick
  // adds to form._reviewFiles, every × takes one out, and the input is rewritten
  // from that list each time — the files still travel as the input's own, one
  // uploader and one MIME check on the server.
  function reviewInput(form) {
    return form ? form.querySelector('input[type="file"][name="attachments[]"]') : null;
  }

  function reviewSync(form) {
    var input = reviewInput(form);
    var list = form._reviewFiles || [];
    if (input && typeof DataTransfer !== 'undefined') {
      var dt = new DataTransfer();
      list.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
    }
    var strip = form.querySelector('.board-review-thumbs');
    if (!strip) return;
    // Object URLs from the last drawing are let go, or each redraw leaks images.
    (strip._urls || []).forEach(function (u) { URL.revokeObjectURL(u); });
    strip._urls = [];
    strip.innerHTML = '';
    strip.hidden = list.length === 0;
    list.forEach(function (f, i) {
      var tile = document.createElement('div');
      tile.className = 'review-thumb';
      tile.title = f.name;
      if (/^image\//.test(f.type)) {
        var url = URL.createObjectURL(f);
        strip._urls.push(url);
        var img = document.createElement('img');
        img.src = url;
        img.alt = f.name;
        tile.appendChild(img);
      } else {
        var name = document.createElement('span');
        name.className = 'review-thumb-name';
        name.textContent = f.name;
        tile.appendChild(name);
      }
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'review-thumb-x';
      x.setAttribute('data-thumb', String(i));
      x.setAttribute('aria-label', 'Remove ' + f.name);
      x.textContent = '×';
      tile.appendChild(x);
      strip.appendChild(tile);
    });
  }

  document.addEventListener('paste', function (e) {
    var box = e.target.closest ? e.target.closest('.board-review-box') : null;
    if (!box || !e.clipboardData) return;
    var form = box.closest('form');
    if (!reviewInput(form) || typeof DataTransfer === 'undefined') return;

    var images = [];
    Array.prototype.forEach.call(e.clipboardData.items || [], function (it) {
      if (it.kind === 'file' && /^image\//.test(it.type)) {
        var f = it.getAsFile();
        if (f) images.push(f);
      }
    });
    if (!images.length) return;     // text pastes as text, untouched
    e.preventDefault();

    form._reviewFiles = form._reviewFiles || [];
    images.forEach(function (f) {
      // Screenshots all arrive as "image.png"; numbered so three are three.
      var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
      var n = form._reviewFiles.length + 1;
      form._reviewFiles.push(new File([f], 'pasted-' + n + '.' + ext, { type: f.type }));
    });
    reviewSync(form);
  });

  // Picked files join the list instead of replacing it.
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input.matches || !input.matches('.board-review-form input[type="file"]')) return;
    var form = input.closest('form');
    form._reviewFiles = (form._reviewFiles || []).concat(Array.prototype.slice.call(input.files || []));
    reviewSync(form);
  });

  // Files DROPPED anywhere on the review form join the same list (2026-10-23),
  // now that the drop zone leaves this form's input to it (data-no-drop).
  ['dragenter', 'dragover'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var form = e.target.closest ? e.target.closest('.board-review-form') : null;
      if (!form) return;
      e.preventDefault();
      form.classList.add('is-drop-over');
    });
  });
  document.addEventListener('dragleave', function (e) {
    var form = e.target.closest ? e.target.closest('.board-review-form') : null;
    if (form && !form.contains(e.relatedTarget)) form.classList.remove('is-drop-over');
  });
  document.addEventListener('drop', function (e) {
    var form = e.target.closest ? e.target.closest('.board-review-form') : null;
    if (!form) return;
    e.preventDefault();
    form.classList.remove('is-drop-over');
    var files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) return;
    form._reviewFiles = (form._reviewFiles || []).concat(Array.prototype.slice.call(files));
    reviewSync(form);
  });

  document.addEventListener('click', function (e) {
    var x = e.target.closest ? e.target.closest('.review-thumb-x') : null;
    if (!x) return;
    var form = x.closest('form');
    if (!form || !form._reviewFiles) return;
    form._reviewFiles.splice(parseInt(x.getAttribute('data-thumb'), 10), 1);
    reviewSync(form);
  });

  // --- the show's naming convention, checked as files are picked -------------
  //
  // David, 2026-10-22: the Brief carries the convention, and an upload whose
  // file does not match it is blocked. The rule rides on the form as
  // data-naming (includes/naming.php built it); each picked file is checked
  // here, the result is listed under the picker, and Send for review stays off
  // until every one passes. board_version.php refuses the same files anyway.
  function namingPad(n, width) {
    var s = String(n);
    while (s.length < width) s = '0' + s;
    return s;
  }

  // ⚠ ON TWO EVENTS. A file PICKED fires 'change'; a file DROPPED or taken off
  // the drop zone's list fires only 'anima:files' (assets/dropzone.js), because
  // writing input.files raises nothing. Listening to 'change' alone let a
  // wrongly named dropped file straight through (David, 2026-10-23).
  function namingCheck(e) {
    var input = e.target;
    if (!input.matches || !input.matches('.board-version-form[data-naming] input[name="versions[]"]')) return;
    var form = input.closest('form');
    var rule;
    try { rule = JSON.parse(form.getAttribute('data-naming')); } catch (err) { return; }
    var re = new RegExp(rule.re, 'i');
    var out = form.querySelector('.naming-check');
    var btn = form.querySelector('button[type="submit"]');
    var bad = 0;
    if (out) out.innerHTML = '';

    Array.prototype.forEach.call(input.files || [], function (f, i) {
      var base = f.name.replace(/\.[^.]*$/, '');
      var expect = rule.next + i - 1 + rule.first;
      var m = re.exec(base);
      var why = '';
      if (!m) why = 'does not follow the naming convention';
      else if (parseInt(m[1], 10) !== expect) {
        why = 'is numbered ' + parseInt(m[1], 10) + ', but this is version ' + namingPad(expect, rule.pad);
      }
      if (why) bad++;
      if (!out) return;
      var row = document.createElement('div');
      row.className = 'naming-row ' + (why ? 'is-bad' : 'is-ok');
      row.textContent = (why ? '✗ ' : '✓ ') + f.name + (why ? ' ' + why + '.' : '');
      out.appendChild(row);
    });

    if (out) {
      out.hidden = !input.files || !input.files.length;
      if (bad) {
        var tip = document.createElement('div');
        tip.className = 'naming-row muted';
        tip.textContent = 'Rename it to follow ' + rule.example + ' and pick it again.';
        out.appendChild(tip);
      }
    }
    form.setAttribute('data-naming-bad', bad ? '1' : '0');
    if (btn) btn.disabled = bad > 0;
  }
  document.addEventListener('change', namingCheck);
  document.addEventListener('anima:files', namingCheck);

  // --- the task's Timeline: every entry opens in a pop-up --------------------
  //
  // David, 2026-10-22: "in every scroll I can access virtually anything that
  // will open with a pop up so I don't have to leave the timeline. Everything
  // should be interactive." Each entry carries its whole content in a
  // <template>; clicking the entry shows it over the panel. Links, thumbnails
  // and buttons INSIDE an entry keep their own job — a vignette expands in the
  // lightbox, a link opens — so only a click on the entry itself opens this.
  var tlModal = null;

  function tlClose() {
    if (!tlModal) return;
    tlModal.remove();
    tlModal = null;
  }

  function tlOpen(entry) {
    var tpl = entry.querySelector('template.tl-pop');
    if (!tpl || !panelInner) return;
    tlClose();
    tlModal = document.createElement('div');
    tlModal.className = 'tl-modal-veil';
    var box = document.createElement('div');
    box.className = 'tl-modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'tl-modal-close';
    x.setAttribute('aria-label', 'Close');
    x.textContent = '×';
    box.appendChild(x);
    box.appendChild(tpl.content.cloneNode(true));
    tlModal.appendChild(box);
    // Inside the panel, so the panel's own handlers (the jump to a tab) see
    // what is clicked in it.
    panelInner.appendChild(tlModal);
    x.focus();
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    if (tlModal) {
      // A button that goes to another tab has already done so (the panel's
      // handler runs first); the pop-up has nothing left to show.
      if (e.target === tlModal || e.target.closest('.tl-modal-close') || e.target.closest('.tl-go')) {
        tlClose();
      }
      return;
    }
    var entry = e.target.closest('.tl-entry');
    if (!entry || e.target.closest('a, button, video, input, select, textarea')) return;
    tlOpen(entry);
  });

  document.addEventListener('keydown', function (e) {
    var entry = e.target.closest ? e.target.closest('.tl-entry') : null;
    if (entry && !tlModal && (e.key === 'Enter' || e.key === ' ') && e.target === entry) {
      e.preventDefault();
      tlOpen(entry);
    }
  });

  // Escape closes the pop-up and nothing else — not the panel behind it. A
  // picture expanded from inside the pop-up closes first, by its own handler.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !tlModal) return;
    if (document.body.classList.contains('lightbox-lock')) return;
    e.stopImmediatePropagation();
    tlClose();
  }, true);

  (function openFromUrl() {
    var q = new URLSearchParams(window.location.search);
    var want = q.get('open');
    if (!want) return;
    // Which tab, and which row inside it — what board_task_path() writes.
    // "timeline" is what the tab is CALLED; "activity" is what its data-tab
    // has always been, and renaming that would break every link already
    // pasted into Slack. So the URL says the friendly word and this maps it.
    var tabWanted = q.get('tab') || '';
    if (tabWanted === 'timeline') tabWanted = 'activity';
    var fragWanted = (window.location.hash || '').replace(/^#/, '');
    panelWants = { tab: tabWanted, frag: fragWanted };
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

  // --- Copy URL --------------------------------------------------------------
  //
  // David asked for this in four places on 2026-10-23 — the board's own
  // column, each comment, each version and review, and the timeline — so
  // there is one handler and one button class for all of them. The element
  // carries a PATH; the absolute address is the browser's to build, because
  // PHP guessing the host is how "http://localhost" ends up in Slack.
  function copied(btn, ok, url) {
    var was = btn.textContent;
    btn.textContent = ok ? 'Copied' : 'Copy failed';
    btn.classList.toggle('is-copied', ok);
    setTimeout(function () {
      btn.textContent = was;
      btn.classList.remove('is-copied');
    }, ok ? 1400 : 2000);
    // ⚠ NEVER A DEAD END. Both clipboard routes can be refused — an insecure
    // origin, a browser policy, a window that does not have focus — and a
    // button that just says "failed" leaves somebody with no way to get the
    // link at all. The prompt is ugly and it is selectable, which is the whole
    // point: Ctrl-C works from it.
    if (!ok && url) window.prompt('Copy this link:', url);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.board-copy-url') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();          // never also opens the row behind it

    var url = new URL(btn.getAttribute('data-copy') || '', window.location.href).href;

    // navigator.clipboard needs a secure context; the fallback is for a studio
    // running this over plain http on the LAN, where it would otherwise fail
    // silently and look like a broken button.
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url)
        .then(function () { copied(btn, true); })
        .catch(function () { copied(btn, false, url); });
      return;
    }
    var box = document.createElement('textarea');
    box.value = url;
    box.setAttribute('readonly', '');
    box.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(box);
    box.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    document.body.removeChild(box);
    copied(btn, ok, url);
  });

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
