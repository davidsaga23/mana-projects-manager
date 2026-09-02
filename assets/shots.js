// The shot breakdown grid: adding and removing assets, and setting frame counts.
//
// Every change saves immediately, one per request. A breakdown is dozens of tiny
// decisions and a Save button would either be pressed after each one anyway or
// batch the lot and lose them together. Same reasoning as the tracking grid.
//
// Reads globals set by shots.php: BD_PROJECT, BD_CAN_EDIT. Both are `window.`
// assignments there — a top-level `const` does not become a window property,
// which cost the tasks board a day.
(function () {
  'use strict';

  var projectId = window.BD_PROJECT;
  var canEdit = window.BD_CAN_EDIT === true;
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

  // --- adding ---------------------------------------------------------------
  table.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.classList || !sel.classList.contains('bd-add')) return;
    var assetId = sel.value;
    if (!assetId) return;

    var cell = sel.closest('.bd-cell');
    var shotId = shotOf(sel);
    var name = sel.options[sel.selectedIndex].textContent;

    // Already there? Say so and reset, rather than sending a request whose only
    // effect would be nothing. INSERT IGNORE would accept it silently, and a
    // second identical chip appearing would be worse than either.
    if (cell.querySelector('.chip[data-asset="' + assetId + '"]')) {
      sel.value = '';
      say(name + ' is already in this shot.', false);
      return;
    }

    // Drawn BEFORE the request answers, then taken back if it fails. Forty of
    // these in a row is the actual job, and waiting on a round trip for each one
    // is what makes a grid feel broken.
    var chip = makeChip(assetId, name);
    cell.insertBefore(chip, sel);
    sel.value = '';
    sel.disabled = true;

    var body = new URLSearchParams();
    body.append('action', 'link');
    body.append('shot_id', shotId);
    body.append('asset_id', assetId);
    post(body)
      .then(function () { say(''); })
      .catch(function (err) {
        chip.remove();
        say(err.message, true);
      })
      .then(function () { sel.disabled = false; });
  });

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
}());
