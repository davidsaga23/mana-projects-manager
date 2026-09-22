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
  if (!projectId || !canEdit) return;

  var table = document.querySelector('.breakdown-table');
  if (!table) return;
  var statusLine = document.getElementById('bdStatus');

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
      '<div class="bd-picker-list"></div>' +
      '<p class="bd-picker-note err small" hidden></p>' +
      '<p class="bd-picker-count muted small"></p>';
    document.body.appendChild(picker);
    pkList   = picker.querySelector('.bd-picker-list');
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
    countTicked();
    button.setAttribute('aria-expanded', 'true');
    picker.hidden = false;
    placePicker();
    if (!pkFilter.hidden) pkFilter.focus();
  }

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
}());
