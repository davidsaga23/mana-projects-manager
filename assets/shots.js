// The shot breakdown grid: adding and removing assets, and setting frame counts.
//
// Every change saves immediately, one per request. A breakdown is dozens of tiny
// decisions and a Save button would either be pressed after each one anyway or
// batch the lot and lose them together. Same reasoning as the tracking grid.
//
// Reads globals set by shots.php: BD_PROJECT, BD_CAN_EDIT, BD_ASSETS. All are
// `window.` assignments there — a top-level `const` does not become a window
// property, which cost the tasks board a day.
(function () {
  'use strict';

  var projectId = window.BD_PROJECT;
  var canEdit = window.BD_CAN_EDIT === true;
  var byType = window.BD_ASSETS || {};
  // False until migration 042 is applied. The N/A row is then never drawn,
  // rather than drawn and failing on every tick.
  var naOk = window.BD_NA_OK === true;
  // ⚠ Handed out through a var, because `function closePicker() {}` is a
  // DECLARATION and declarations are block-scoped in strict mode — it is not
  // visible outside the editing block below, and the column filters need it.
  var shutPicker = null;

  var table = document.querySelector('.breakdown-table');
  if (!table) return;
  var statusLine = document.getElementById('bdStatus');

  // ⚠ THE EDIT CHECK MOVED DOWN (2026-09-30). It used to return here, which was
  // right while everything in this file WROTE something. Filtering and sorting
  // read: an artist, who cannot change a breakdown, still has forty-five rows to
  // find something in. So the editing half is now wrapped in this test and the
  // column tools at the foot of the file are not.
  if (projectId && canEdit) {

  /**
   * The page-level line, shown ONLY when something failed.
   *
   * A successful add is already visible — the chip is there. A failure is not
   * visible at all unless it is said, and a change somebody believes they made
   * is the worst outcome on a screen the scheduler reads.
   */
  function say(msg, bad) {
    if (!statusLine) return;
    statusLine.textContent = msg || '';
    statusLine.hidden = !msg;
    statusLine.className = 'muted small' + (bad ? ' err' : '');
  }

  function post(body) {
    body.append('project_id', projectId);
    return fetch('shots_api.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || 'That did not save.');
        return d;
      });
  }

  function shotOf(el) {
    var tr = el.closest ? el.closest('tr[data-shot]') : null;
    return tr ? tr.getAttribute('data-shot') : null;
  }

  /** A chip, built to match what shots.php renders server-side. */
  function makeChip(assetId, name) {
    var chip = document.createElement('span');
    chip.className = 'chip';
    chip.setAttribute('data-asset', assetId);
    chip.appendChild(document.createTextNode(name + ' '));
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'chip-x';
    x.title = 'Take ' + name + ' out of this shot';
    x.setAttribute('aria-label', 'Remove ' + name);
    x.textContent = '×';
    chip.appendChild(x);
    return chip;
  }

  /**
   * The "nothing of this kind in this shot" chip, matching what shots.php
   * renders server-side. Marked with data-na so the removal handler can tell it
   * from an asset chip ⚠ it has no asset to unlink.
   */
  function makeNaChip(type) {
    var chip = document.createElement('span');
    chip.className = 'chip chip-na';
    chip.setAttribute('data-na', '1');
    chip.appendChild(document.createTextNode('N/A '));
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'chip-x';
    x.title = 'This shot does need ' + (type || 'untyped asset') + ' work after all';
    x.setAttribute('aria-label', 'Clear N/A');
    x.textContent = '\u00d7';
    chip.appendChild(x);
    return chip;
  }

  /** Chips stay in the server's order (by name) however they were ticked. */
  function insertChip(cell, chip, name) {
    var at = null;
    var chips = cell.querySelectorAll('.chip');
    for (var i = 0; i < chips.length; i++) {
      var other = chips[i].textContent.replace(/\s*\u00d7\s*$/, '').trim();
      if (other.localeCompare(name, undefined, { sensitivity: 'base' }) > 0) { at = chips[i]; break; }
    }
    cell.insertBefore(chip, at || cell.querySelector('.bd-add'));
  }

  // --- the picker -----------------------------------------------------------
  //
  // A list of tick boxes instead of a dropdown (David, 2026-09-22: "have each
  // character with a check box so I can click and add several assets at the same
  // time"). A dropdown is one trip through twenty-five names per player, and an
  // FC Mobile shot carries five of them.
  //
  // ONE panel, moved from cell to cell, living in <body> rather than inside the
  // cell it belongs to: .bd-scroll clips its contents, so a list drawn in a cell
  // would be cut off at the edge of the table.
  //
  // Each tick saves on its own, immediately — the same rule as the rest of this
  // screen. No Done button to forget, and a failure names the one asset that
  // failed instead of losing the batch.
  var picker = null, pkList = null, pkFilter = null, pkTitle = null, pkCount = null, pkNote = null;
  var pkNa = null, pkNaBox = null, pkNaWhy = null;
  var pkCell = null, pkButton = null;

  function buildPicker() {
    picker = document.createElement('div');
    picker.className = 'bd-picker';
    picker.hidden = true;
    picker.setAttribute('role', 'dialog');
    picker.innerHTML =
      '<div class="bd-picker-head">' +
        '<strong class="bd-picker-title"></strong>' +
        '<button type="button" class="bd-picker-close" aria-label="Close">\u00d7</button>' +
      '</div>' +
      '<input type="search" class="bd-picker-filter" placeholder="Type to narrow the list" ' +
             'aria-label="Filter the list">' +
      // ⚠ OUTSIDE .bd-picker-list, deliberately. countTicked() counts every
      // checkbox inside that list and applyFilter() hides every .bd-picker-row
      // in it, so an N/A box living there would turn "0 of 18 ticked" into
      // "0 of 19" and vanish whenever somebody typed in the filter. It is a
      // different kind of answer from the names below it, and it sits apart.
      '<div class="bd-picker-na" hidden>' +
        '<label><input type="checkbox" class="bd-na-box"> ' +
          'Nothing of this kind in this shot</label>' +
        '<span class="bd-na-why muted small" hidden></span>' +
      '</div>' +
      '<div class="bd-picker-list"></div>' +
      '<p class="bd-picker-note err small" hidden></p>' +
      '<p class="bd-picker-count muted small"></p>';
    document.body.appendChild(picker);
    pkList   = picker.querySelector('.bd-picker-list');
    pkNa     = picker.querySelector('.bd-picker-na');
    pkNaBox  = picker.querySelector('.bd-na-box');
    pkNaWhy  = picker.querySelector('.bd-na-why');
    pkFilter = picker.querySelector('.bd-picker-filter');
    pkTitle  = picker.querySelector('.bd-picker-title');
    pkCount  = picker.querySelector('.bd-picker-count');
    pkNote   = picker.querySelector('.bd-picker-note');

    pkFilter.addEventListener('input', applyFilter);
    picker.querySelector('.bd-picker-close').addEventListener('click', function () { closePicker(true); });
    picker.addEventListener('change', onTick);
    // Clicks inside must not reach the document handler that closes it.
    picker.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
  }

  /**
   * Set the N/A row from the cell, every time the picker opens.
   *
   * ⚠ REFUSED WHILE THE CELL HAS ASSETS IN IT, and shown as a disabled box with
   * the reason beside it rather than hidden. A control that silently disappears
   * when it would be useful teaches nobody why. The server refuses the same
   * case, so this is a courtesy, not the guard.
   */
  function naBoxState(cell) {
    if (!pkNa) return;
    if (!naOk || !cell) { pkNa.hidden = true; return; }
    pkNa.hidden = false;

    var hasAssets = !!cell.querySelector('.chip[data-asset]:not([hidden])');
    pkNaBox.checked = !!cell.querySelector('.chip-na:not([hidden])');
    pkNaBox.disabled = hasAssets;
    pkNaWhy.textContent = hasAssets
      ? 'Take the chips out of this cell first.'
      : '';
    pkNaWhy.hidden = !hasAssets;
  }

  /**
   * Drop the N/A chip because something was just linked into the cell.
   *
   * breakdown_link() deletes the row server-side, so this only keeps the screen
   * honest ⚠ without it the cell would show "N/A" beside the asset somebody just
   * added, which is the contradiction the whole design is built to prevent.
   */
  function clearNaLocally(cell) {
    if (!cell) return;
    var chip = cell.querySelector('.chip-na');
    if (chip) chip.remove();
    if (pkCell === cell) naBoxState(cell);
  }

  function pickerSay(msg) {
    pkNote.textContent = msg || '';
    pkNote.hidden = !msg;
  }

  function countTicked() {
    var boxes = pkList.querySelectorAll('input[type=checkbox]');
    var on = 0;
    for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) on++;
    pkCount.textContent = on + ' of ' + boxes.length + ' ticked';
  }

  function applyFilter() {
    var q = pkFilter.value.trim().toLowerCase();
    var rows = pkList.querySelectorAll('.bd-picker-row'), shown = 0;
    for (var i = 0; i < rows.length; i++) {
      var hit = !q || rows[i].getAttribute('data-name').indexOf(q) !== -1;
      rows[i].hidden = !hit;
      if (hit) shown++;
    }
    if (rows.length) pickerSay(shown === 0 ? 'Nothing here matches that.' : '');
  }

  function placePicker() {
    if (!picker || picker.hidden || !pkButton) return;
    var r = pkButton.getBoundingClientRect();

    // ⚠ CLOSE WHEN THE BUTTON SCROLLS OUT OF SIGHT. The table got its own
    // scroll box on 2026-09-22 so the headings could stick, which means a cell
    // can now leave the visible area while the page itself has not moved. The
    // panel tracks its button faithfully, so without this it followed one
    // straight off the top of the window — or, worse, hung over an unrelated
    // part of the page pointing at a cell nobody can see. Measured against the
    // scroll box AND the window, since either can hide it.
    var box = pkButton.closest('.bd-scroll');
    var top = 0, bottom = window.innerHeight, left = 0, right = window.innerWidth;
    if (box) {
      var b = box.getBoundingClientRect();
      top = Math.max(top, b.top); bottom = Math.min(bottom, b.bottom);
      left = Math.max(left, b.left); right = Math.min(right, b.right);
    }
    if (r.bottom <= top || r.top >= bottom || r.right <= left || r.left >= right) {
      closePicker(false);
      return;
    }

    // Fixed, so a table scrolled sideways carries the panel with the button
    // rather than leaving it behind. Flipped above, or pulled left, when it
    // would otherwise run off the bottom or the right edge of the window.
    var w = picker.offsetWidth, h = picker.offsetHeight;
    var left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
    var top = r.bottom + 4;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
    picker.style.left = left + 'px';
    picker.style.top = top + 'px';
  }

  function openPicker(button) {
    if (!picker) buildPicker();
    if (pkButton === button && !picker.hidden) { closePicker(true); return; }

    var cell = button.closest('.bd-cell');
    if (!cell) return;
    pkCell = cell;
    pkButton = button;

    var type = cell.getAttribute('data-type') || '';
    var list = byType[type] || [];
    var row = button.closest('tr[data-shot]');
    var code = row && row.querySelector('strong') ? row.querySelector('strong').textContent : '';
    pkTitle.textContent = (type !== '' ? type : 'No type set') + (code ? ' \u00b7 ' + code : '');

    // Rebuilt on every open rather than cached per type: what is ticked differs
    // by shot, and twenty-five rows of HTML cost nothing to write.
    pkList.textContent = '';
    for (var i = 0; i < list.length; i++) {
      var a = list[i];
      var line = document.createElement('label');
      line.className = 'bd-picker-row';
      line.setAttribute('data-name', String(a.name).toLowerCase());
      var box = document.createElement('input');
      box.type = 'checkbox';
      box.value = a.id;
      box.checked = !!cell.querySelector('.chip[data-asset="' + a.id + '"]');
      line.appendChild(box);
      line.appendChild(document.createTextNode(' ' + a.name));
      pkList.appendChild(line);
    }
    pkFilter.hidden = list.length < 8; // a short list is read, not searched
    pickerSay(list.length ? '' : 'This project has no ' +
      (type !== '' ? type : 'untyped') + ' assets yet. Add them on Setup.');

    pkFilter.value = '';
    applyFilter();
    naBoxState(cell);
    countTicked();
    button.setAttribute('aria-expanded', 'true');
    picker.hidden = false;
    placePicker();
    if (!pkFilter.hidden) pkFilter.focus();
  }

  shutPicker = closePicker;
  function closePicker(focusBack) {
    if (!picker || picker.hidden) return;
    picker.hidden = true;
    if (pkButton) {
      pkButton.setAttribute('aria-expanded', 'false');
      if (focusBack) pkButton.focus();
    }
    pkCell = null;
    pkButton = null;
  }

  /** Tick = link, untick = unlink. One request each, sent as it happens. */
  function onTick(ev) {
    var box = ev.target;
    if (!box.matches || !box.matches('input[type=checkbox]')) return;
    var cell = pkCell;
    if (!cell) return;
    // The N/A box lives in the same panel and so reaches the same listener, but
    // it saves a different fact through a different action.
    if (box.classList && box.classList.contains('bd-na-box')) { onNaTick(box); return; }

    var assetId = box.value;
    var name = box.parentNode.textContent.trim();
    var shotId = shotOf(cell);
    var adding = box.checked;
    var existing = cell.querySelector('.chip[data-asset="' + assetId + '"]');

    // Drawn BEFORE the request answers and taken back if it fails. Ticking five
    // players in a row is the actual job, and waiting on a round trip for each
    // one is what makes a grid feel broken.
    var chip = null;
    if (adding) {
      if (existing) { countTicked(); return; }
      chip = makeChip(assetId, name);
      insertChip(cell, chip, name);
    } else {
      if (!existing) { countTicked(); return; }
      existing.hidden = true;
    }
    countTicked();
    box.disabled = true;

    var body = new URLSearchParams();
    body.append('action', adding ? 'link' : 'unlink');
    body.append('shot_id', shotId);
    body.append('asset_id', assetId);
    post(body)
      .then(function () {
        if (!adding && existing) existing.remove();
        // The server cleared this cell's N/A as part of the link, so the grid
        // has to agree without waiting for a reload.
        if (adding) clearNaLocally(cell);
        pickerSay('');
        say('');
      })
      .catch(function (err) {
        // Both halves go back: the chip in the grid and the tick in the list,
        // so the panel and the row underneath never disagree.
        if (adding) { if (chip) chip.remove(); }
        else if (existing) existing.hidden = false;
        box.checked = !adding;
        countTicked();
        pickerSay(err.message);
        say(name + ': ' + err.message, true);
      })
      .then(function () { box.disabled = false; });
  }

  /**
   * Tick = "this shot needs none of these", untick = "it is undecided again".
   *
   * Same optimistic-then-roll-back shape as onTick(): the chip is drawn before
   * the request answers and taken back if it fails, so the panel and the row
   * underneath can never end up disagreeing.
   */
  function onNaTick(box) {
    var cell = pkCell;
    if (!cell) return;

    var shotId = shotOf(cell);
    var type = cell.getAttribute('data-type') || '';
    var turningOn = box.checked;
    var existing = cell.querySelector('.chip-na');

    var chip = null;
    if (turningOn) {
      if (existing) { existing.hidden = false; return; }
      chip = makeNaChip(type);
      cell.insertBefore(chip, cell.querySelector('.bd-add'));
    } else {
      if (!existing) return;
      existing.hidden = true;
    }
    box.disabled = true;

    var body = new URLSearchParams();
    body.append('action', 'na');
    body.append('shot_id', shotId);
    body.append('type', type);
    body.append('on', turningOn ? '1' : '0');
    post(body)
      .then(function () {
        if (!turningOn && existing) existing.remove();
        pickerSay('');
        say('');
      })
      .catch(function (err) {
        if (turningOn) { if (chip) chip.remove(); }
        else if (existing) existing.hidden = false;
        box.checked = !turningOn;
        pickerSay(err.message);
        say(err.message, true);
      })
      .then(function () { box.disabled = false; });
  }

  // --- adding ---------------------------------------------------------------
  table.addEventListener('click', function (ev) {
    var button = ev.target.closest ? ev.target.closest('.bd-add') : null;
    if (!button) return;
    ev.preventDefault();
    openPicker(button);
  });

  // Outside click, Escape, and anything that moves the page under the panel.
  document.addEventListener('mousedown', function (ev) {
    if (!picker || picker.hidden) return;
    if (ev.target.closest && ev.target.closest('.bd-add')) return; // its own handler
    closePicker(false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closePicker(true);
  });
  window.addEventListener('resize', placePicker);
  window.addEventListener('scroll', placePicker, true);

  // --- removing -------------------------------------------------------------
  table.addEventListener('click', function (ev) {
    var x = ev.target.closest ? ev.target.closest('.chip-x') : null;
    if (!x) return;
    var chip = x.closest('.chip');
    var shotId = shotOf(chip);

    // ⚠ THE N/A CHIP IS NOT A LINK, so there is no asset_id to unlink. Sent
    // through the same action that set it, with on=0.
    if (chip.hasAttribute('data-na')) {
      var naCell = chip.closest('.bd-cell');
      chip.hidden = true;
      var naBody = new URLSearchParams();
      naBody.append('action', 'na');
      naBody.append('shot_id', shotId);
      naBody.append('type', naCell ? (naCell.getAttribute('data-type') || '') : '');
      naBody.append('on', '0');
      post(naBody)
        .then(function () {
          chip.remove();
          if (pkCell === naCell) naBoxState(naCell);
          say('');
        })
        .catch(function (err) { chip.hidden = false; say(err.message, true); });
      return;
    }

    var assetId = chip.getAttribute('data-asset');

    // Hidden rather than removed, so it can come back in place if the delete
    // fails — appending it again would put it at the end of the cell, out of the
    // alphabetical order the server returns.
    chip.hidden = true;

    var body = new URLSearchParams();
    body.append('action', 'unlink');
    body.append('shot_id', shotId);
    body.append('asset_id', assetId);
    post(body)
      .then(function () { chip.remove(); say(''); })
      .catch(function (err) {
        chip.hidden = false;
        say(err.message, true);
      });
  });

  // --- frames ---------------------------------------------------------------
  //
  // On `change`, so it saves when you leave the field or press Enter — not on
  // every keystroke, which would send "1", "12", "120" for one shot.
  table.addEventListener('change', function (ev) {
    var input = ev.target;
    if (!input.classList || !input.classList.contains('bd-frame-input')) return;

    var cell = input.closest('.bd-frames');
    var secs = cell ? cell.querySelector('.bd-secs') : null;
    var shotId = shotOf(input);

    var body = new URLSearchParams();
    body.append('action', 'frames');
    body.append('shot_id', shotId);
    body.append('frames', input.value);
    input.disabled = true;
    post(body)
      .then(function (d) {
        // The seconds come back from the server rather than being divided here:
        // one rounding, in the place that also wrote the value, so the line under
        // the box always says what the database now holds.
        if (secs) secs.textContent = d.label || '';
        say('');
      })
      .catch(function (err) { say(err.message, true); })
      .then(function () { input.disabled = false; });
  });

  // --- notes -----------------------------------------------------------------
  //
  // Same `change` rule as the frames box: it saves when you leave the field, not
  // on every keystroke. Enter inside a textarea makes a new line rather than
  // saving, which is what somebody typing a note expects, so leaving the field
  // is the only moment that means "done".
  table.addEventListener('change', function (ev) {
    var box = ev.target;
    if (!box.classList || !box.classList.contains('bd-note-input')) return;

    var body = new URLSearchParams();
    body.append('action', 'notes');
    body.append('shot_id', shotOf(box));
    body.append('notes', box.value);
    box.disabled = true;
    post(body)
      .then(function (d) {
        // What was stored, not what was typed: trimmed, and cut if it was very
        // long, so the box shows the note the rest of the app will read.
        box.value = d.notes || '';
        say(d.message || '', !!d.message);
      })
      .catch(function (err) { say(err.message, true); })
      .then(function () { box.disabled = false; });
  });

  }   // end of the editing half

  // --- column filters, sorting, and live totals -------------------------------
  //
  // David, 2026-09-30: "do the same for the shot tab." The machinery is
  // assets/gridtools.js, shared with the Artists grid; what is here is the two
  // things a breakdown needs and an artist list does not.
  //
  // 1. AN ASSET CELL IS CHIPS, not a field. Read as-is it would come back
  //    "Mbappe × Bellingham × +", so the chips are collected and the buttons
  //    left out.
  // 2. THE TOTALS HAVE TO FOLLOW THE FILTER. The line above the table and the
  //    Total row under it are printed by PHP for the whole episode; leaving them
  //    alone while five of forty-five shots are on screen is a number that is
  //    wrong in the most expensive direction, on the screen the expense
  //    projection prices. They are recomputed from what is VISIBLE, by adding up
  //    the figures PHP already put in the rows — summing what is shown, never
  //    re-deriving it, so there is no second copy of frames-to-seconds to drift.
  if (window.GridTools && table.tHead) {
    var rows0 = table.tBodies[0] ? table.tBodies[0].rows[0] : null;
    var foot = table.tFoot ? table.tFoot.rows[0] : null;
    var footFrames = foot ? foot.querySelector('.bd-frames strong') : null;
    var footSecs = foot ? foot.querySelector('.bd-frames .bd-secs') : null;
    var footNote = foot ? foot.querySelector('td.muted') : null;
    // What the server said, kept so an unfiltered table shows ITS numbers rather
    // than a client-side re-run of them.
    var asPrinted = {
      frames: footFrames ? footFrames.textContent : null,
      secs: footSecs ? footSecs.textContent : null,
      note: footNote ? footNote.innerHTML : null
    };

    function chipText(td) {
      return Array.prototype.map.call(td.querySelectorAll('.chip'), function (c) {
        return c.textContent.replace(/\s*×\s*$/, '').trim();
      }).join(', ');
    }

    var grid = null;
    grid = window.GridTools.attach(table, {
      noun: ['shot', 'shots'],
      cellText: function (td) {
        if (td && td.classList && td.classList.contains('bd-cell')) return chipText(td);
        return window.GridTools.cellText(td);
      },
      // A chip cell is a LIST, so a filter matches one item of it whole rather
      // than a substring of the joined line — picking SoccerBall must not also
      // match a prop called "Ball" for being inside its name.
      cellList: function (td) {
        if (!td || !td.classList || !td.classList.contains('bd-cell')) return null;
        return Array.prototype.map.call(td.querySelectorAll('.chip'), function (c) {
          return c.textContent.replace(/\s*×\s*$/, '').trim();
        });
      },
      // ⚠ EVERY ASSET OF THAT TYPE, not only the ones already linked (David,
      // 2026-10-01: "a dropdown menu with all the options available, the same way
      // all the options of that column are presented when linking them per
      // shot"). BD_ASSETS is the same list, in the same order, that the per-shot
      // picker offers — so the two agree by construction rather than by two
      // people remembering to keep them the same. A character in the project but
      // not yet in any shot is still offered, and picking it correctly finds
      // nothing, which is itself an answer.
      //
      // Ep gets one too: a handful of values, and nobody wants to type EP001.
      // Shot, Frames and Notes keep a box — a dropdown of forty-five shot codes
      // is slower than typing three characters, and you cannot type half a
      // dropdown to see the nine shots beginning SH0.
      options: function (col) {
        var th = table.tHead.rows[0].cells[col];
        var body = rows0 ? rows0.cells[col] : null;
        if (body && body.classList && body.classList.contains('bd-cell')) {
          var type = body.getAttribute('data-type') || '';
          var all = (window.BD_ASSETS || {})[type] || [];
          return all.map(function (a) { return { value: a.name, label: a.name }; });
        }
        if (th && th.classList && th.classList.contains('bd-ep')) {
          var seen = Object.create(null), out = [];
          Array.prototype.forEach.call(table.tBodies[0].rows, function (tr) {
            var v = (tr.cells[col].textContent || '').trim();
            if (v === '' || v === '—' || seen[v]) return;
            seen[v] = true;
            out.push({ value: v, label: v });
          });
          return out.length ? out : null;
        }
        return null;
      },
      onChange: function (shown, all, filtering) {
        // A picker open over a row that has just been filtered away would float
        // there pointing at nothing; it is closed rather than left hanging.
        if (shutPicker) shutPicker(false);

        // THREE PLACES, THREE JOBS, so none of them is ever a number you have to
        // work out which set it belongs to:
        //   the line above the table  — the whole episode, as PHP printed it,
        //                               left alone so there is always a total to
        //                               compare against;
        //   the row under the headings — what is on screen now;
        //   the Total row at the foot  — the same, beside the column it sums.
        var frames = 0, secs = 0, noFrames = 0;
        shown.forEach(function (tr) {
          var box = tr.querySelector('.bd-frame-input');
          var raw = box ? box.value.trim() : (tr.cells[2] ? tr.cells[2].textContent.trim() : '');
          if (raw === '' || raw === '\u2014') { noFrames++; return; }
          frames += parseInt(raw, 10) || 0;
          var sc = tr.querySelector('.bd-secs');
          if (sc) secs += parseFloat((sc.textContent || '').replace('s', '')) || 0;
        });

        // Agrees with the TOTAL, not the count: "1 of 45 shots", never "1 of 45
        // shot". The number the noun belongs to is the one after "of".
        var word = all.length === 1 ? 'shot' : 'shots';
        var missing = noFrames ? ', ' + noFrames + ' without a frame count yet' : '';
        var head = filtering
          ? shown.length + ' of ' + all.length + ' ' + word
          : all.length + ' ' + word;
        var line = head + ' \u00b7 ' + frames.toLocaleString() + ' frames \u00b7 ' +
                   secs.toFixed(3) + 's' + missing;

        // ⚠ The running time (0:49.8) is deliberately NOT recomputed. fmt_secs()
        // formats it in PHP and a second formatter here is the kind of copy that
        // drifts. Seconds are shown instead, which is what this screen is about.
        if (grid && grid.countLine) grid.countLine.textContent = line;

        if (!filtering) {
          if (footFrames) footFrames.textContent = asPrinted.frames;
          if (footSecs) footSecs.textContent = asPrinted.secs;
          if (footNote && asPrinted.note !== null) footNote.innerHTML = asPrinted.note;
          return;
        }
        if (footFrames) footFrames.textContent = frames.toLocaleString();
        if (footSecs) footSecs.textContent = secs.toFixed(3) + 's';
        if (footNote) footNote.textContent = shown.length + ' of ' + all.length + ' ' + word + missing;
      }
    });
    // onChange fires once inside attach(), before `grid` has been assigned, so
    // the first pass could not reach the count row. Run it again now that it can.
    if (grid) grid.apply();
  }
}());
