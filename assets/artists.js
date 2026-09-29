// The Artists grid: every field editable in the row, and SAVED AS YOU GO.
//
// One row per request, which is what makes PHP's max_input_vars a non-issue
// rather than something to work around: the ceiling is a thousand values and a
// row is thirteen. A page-wide form post of three hundred and seventy-seven
// people would have been five thousand, and PHP drops the tail of that with no
// error at all — a save that looks like it worked and writes half.
//
// It also keeps the page still. A form post reloads, and a reload on a filtered,
// scrolled list loses your place every time you correct an email.
//
// There is no Save button: every row writes itself, and each says so in its own
// status cell. The <form> remains because the field names hang off it and
// because Enter can still submit one — cancelled below.
//
// The cost, stated plainly: with JavaScript off, this grid cannot be saved.
(function () {
  'use strict';

  var form = document.getElementById('artistGrid');
  if (!form) return;

  var counter = document.getElementById('dirtyCount');
  var pending = Object.create(null);          // row key -> timer
  var inflight = 0;

  function rowOf(el) { return el.closest ? el.closest('tr') : null; }

  /** A row's key is the id in its field names — rows[123][email]. */
  function keyOf(row) {
    var input = row.querySelector('[name^="rows["]');
    if (!input) return null;
    var m = /^rows\[(\d+)\]/.exec(input.getAttribute('name'));
    return m ? m[1] : null;
  }

  /**
   * The page-level line, shown ONLY when something went wrong.
   *
   * There is no permanent status text any more — each row reports itself in its
   * own cell, which is better anyway because it names WHICH row. But a failure
   * needs to say why, and "not saved" in a cell cannot carry a reason. So this
   * stays hidden until there is one, then holds it until the next success.
   */
  function say(msg) {
    if (!counter) return;
    counter.textContent = msg || '';
    counter.hidden = !msg;
  }

  function status(row, state, text) {
    var cell = row.querySelector('.a-status');
    if (cell) {
      cell.className = 'a-status ' + state;
      cell.textContent = text;
    }
    row.classList.toggle('is-saving', state === 'saving');
    row.classList.toggle('is-saved', state === 'saved');
    row.classList.toggle('is-failed', state === 'failed');
  }

  /**
   * Send ONE row.
   *
   * A row at a time is what makes max_input_vars a non-issue: the ceiling is a
   * thousand values and a row is thirteen. It also keeps the page still — a form
   * post would reload a filtered, scrolled list of hundreds every time an email
   * is corrected.
   */
  function save(row) {
    var key = keyOf(row);
    if (!key) return;

    var body = new URLSearchParams();
    body.append('id', key);
    Array.prototype.forEach.call(row.querySelectorAll('[name^="rows["]'), function (f) {
      var m = /^rows\[\d+\]\[([a-z_]+)\]$/.exec(f.getAttribute('name'));
      if (!m) return;
      // An unchecked checkbox posts nothing, which is how "no" is spelt.
      if (f.type === 'checkbox') { if (f.checked) body.append(m[1], f.value); return; }
      body.append(m[1], f.value);
    });

    inflight++;
    status(row, 'saving', 'saving…');

    fetch('artists_api.php?action=save_row', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || 'failed');
        status(row, 'saved', d.warning ? 'saved, rate not' : 'saved');
        say(d.warning || '');
        // The name may now differ from what the next save must match on.
        var orig = row.querySelector('[name$="[orig_name]"]');
        var name = row.querySelector('.a-name');
        if (orig && name) orig.value = name.value;
      })
      .catch(function (err) {
        // Left visible and NOT cleared: an edit that failed silently is an edit
        // somebody believes they made.
        status(row, 'failed', 'not saved');
        say('Could not save ' + (row.querySelector('.a-name') || {}).value + ', ' + err.message);
      })
      .then(function () { inflight--; });
  }

  /** Saved shortly after you stop typing, and at once when you leave the field. */
  function queue(row, delay) {
    var key = keyOf(row);
    if (!key) return;
    clearTimeout(pending[key]);
    pending[key] = setTimeout(function () {
      delete pending[key];
      save(row);
    }, delay);
  }

  form.addEventListener('input', function (ev) {
    var row = rowOf(ev.target);
    if (row) queue(row, 900);
  });
  // change covers leaving a text field, and fires at once for a tick or a pick.
  form.addEventListener('change', function (ev) {
    var row = rowOf(ev.target);
    if (row) queue(row, 0);
  });

  // There is no Save button any more, but a browser can still submit a form on
  // Enter. Cancelled, because the rows have already written themselves and a
  // reload would only lose the reader's place in a list of hundreds.
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
  });

  // Anything still queued goes before the page does.
  window.addEventListener('beforeunload', function (ev) {
    if (!inflight && !Object.keys(pending).length) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // --- the reel cell ----------------------------------------------------------
  //
  // Shows a link, because a reel is something you OPEN far more often than you
  // retype, and an input cannot be clicked through to the site. Double-clicking
  // the cell swaps in the field — the same gesture that edits a milestone.
  //
  // The input is hidden, never disabled, so its value posts either way: a row
  // saved without ever touching this cell keeps its address.
  document.addEventListener('dblclick', function (ev) {
    var cell = ev.target.closest ? ev.target.closest('[data-reel]') : null;
    if (!cell) return;
    ev.preventDefault();
    var link = cell.querySelector('.reel-link');
    var box  = cell.querySelector('.reel-input');
    if (!link || !box) return;
    link.hidden = true;
    box.hidden = false;
    box.focus();
    box.select();
  });

  // Back to a link on the way out, with the address it now holds — so what you
  // see is what will be saved rather than what was there when the page loaded.
  document.addEventListener('focusout', function (ev) {
    var box = ev.target;
    if (!box.classList || !box.classList.contains('reel-input')) return;
    var cell = box.closest('[data-reel]');
    var link = cell ? cell.querySelector('.reel-link') : null;
    if (!link) return;
    var url = box.value.trim();
    link.textContent = url || '—';
    // The href is REMOVED when the box is emptied, not set to '#'. An <a> with
    // no href is plain text: no pointer, no blue, not focusable — which is what
    // an empty cell should look like. Matches what artists.php prints, so a
    // cleared reel and a reloaded page agree.
    if (url) {
      link.setAttribute('href', url);
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener');
      link.setAttribute('title', url + ' \u2014 double-click to edit');
    } else {
      link.removeAttribute('href');
      link.removeAttribute('target');
      link.removeAttribute('rel');
      link.setAttribute('title', 'Double-click to add a reel');
    }
    box.hidden = true;
    link.hidden = false;
  });

  // --- see all ----------------------------------------------------------------
  var posAll = document.getElementById('posAll');
  if (posAll) {
    var boxes = document.querySelectorAll('.pos-grid input[type=checkbox]');
    function syncAll() {
      var on = 0;
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) on++; });
      // Unticked means "no filter", which shows everyone — so an empty panel and
      // a full one mean the same thing, and the box reflects that rather than
      // pretending they differ.
      posAll.checked = (on === 0 || on === boxes.length);
      posAll.indeterminate = (on > 0 && on < boxes.length);
    }
    posAll.addEventListener('change', function () {
      // Clearing them all is what "see all" means: no filter at all, rather than
      // every box ticked, which would send sixty values to say "no filter".
      Array.prototype.forEach.call(boxes, function (b) { b.checked = false; });
      posAll.indeterminate = false;
      posAll.checked = true;
    });
    Array.prototype.forEach.call(boxes, function (b) {
      b.addEventListener('change', syncAll);
    });
    syncAll();
  }

  // Leaving with unsaved edits is worth one question. The browser decides the
  // wording; all that matters is that it asks.
  window.addEventListener('beforeunload', function (ev) {
    if (!Object.keys(dirty).length) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // --- column filters, sorting, and a live count ------------------------------
  //
  // David, 2026-09-29: "in the headers add a filter, a sort order and so we know
  // how many results that filter produced."
  //
  // ⚠ ENTIRELY IN THE BROWSER, and that is correctness rather than speed. The
  // search box at the top of this page is a GET and reloads; this grid holds
  // EDITS. Filtering or sorting server-side would reload the page under whatever
  // somebody had half-typed, which is the one thing a screen that saves as you go
  // must never do. It also means the two filters compose: the server narrows who
  // is on the page, these narrow what you look at within that.
  //
  // ⚠ HIDDEN ROWS STILL SAVE. Rows are hidden with a class, not removed, so their
  // fields stay in the DOM and in the form — an edit made before a filter was
  // typed is not silently dropped by the filter.
  //
  // The controls are built HERE rather than printed by artists.php because they
  // are made of what the table actually holds: a column of <select>s is offered
  // as a dropdown of the values in use, everything else as a box to type in.
  var table = document.querySelector('.artist-table');
  var tbody = table ? table.tBodies[0] : null;
  if (!table || !tbody || !table.tHead) return;

  var headRow = table.tHead.rows[0];
  var rows = Array.prototype.slice.call(tbody.rows);
  var sortCol = -1, sortDir = 1;
  var clear = null;                      // built below, read by applyFilters()

  /**
   * What a cell SAYS, whatever it is made of.
   *
   * Order matters: the name cell leads with a hidden field and the reel cell's
   * box is `hidden` by attribute rather than by type, so its address is read even
   * while the anchor is what shows.
   */
  function cellText(td) {
    if (!td) return '';
    var box = td.querySelector('input[type=checkbox]');
    if (box) return box.checked ? 'yes' : 'no';
    var sel = td.querySelector('select');
    if (sel) return sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text.trim() : '';
    var f = td.querySelector('input:not([type=hidden]), textarea');
    if (f) return (f.value || '').trim();
    return (td.textContent || '').trim();
  }

  /**
   * The key a column SORTS on, which is not always what it shows.
   *
   * A <select> sorts by the position of the chosen option, so Seniority reads
   * Junior, Mid, Senior, Lead — the order the app itself put them in — rather
   * than alphabetically, and Rating sorts by stars rather than by the width of
   * a string of them. Numbers sort as numbers. Everything else is text.
   * An empty cell always sinks, whichever direction is asked for, because
   * "nothing recorded" is not a value that belongs at either extreme.
   */
  function sortKey(td) {
    var box = td && td.querySelector('input[type=checkbox]');
    if (box) return [0, box.checked ? 1 : 0];
    var sel = td && td.querySelector('select');
    if (sel) return sel.selectedIndex > 0 ? [0, sel.selectedIndex] : [1, 0];
    var v = cellText(td);
    if (v === '' || v === '—') return [1, 0];
    var n = parseFloat(v.replace(/[^0-9.\-]/g, ''));
    if (!isNaN(n) && /^[^A-Za-z]*$/.test(v)) return [0, n];
    return [0, v.toLowerCase()];
  }

  function isChoice(col) {
    var td = rows.length ? rows[0].cells[col] : null;
    return !!(td && (td.querySelector('select') || td.querySelector('input[type=checkbox]')));
  }

  /** Every value in use in one column, in the order the column itself implies. */
  function choices(col) {
    var seen = Object.create(null), out = [];
    rows.forEach(function (tr) {
      var td = tr.cells[col];
      var v = cellText(td);
      if (v === '' || seen[v]) return;
      seen[v] = true;
      var sel = td.querySelector('select');
      out.push({ text: v, at: sel ? sel.selectedIndex : out.length });
    });
    out.sort(function (a, b) { return a.at - b.at; });
    return out.map(function (o) { return o.text; });
  }

  // --- the count --------------------------------------------------------------
  var countLine = document.createElement('p');
  countLine.className = 'muted small col-count';
  var scroll = table.closest('.grid-scroll');
  if (scroll && scroll.parentNode) scroll.parentNode.insertBefore(countLine, scroll);

  function filters() {
    return Array.prototype.slice.call(headRow.querySelectorAll('.col-filter'));
  }

  function applyFilters() {
    var active = filters().filter(function (f) { return f.value !== ''; });
    var shown = 0;
    rows.forEach(function (tr) {
      var hit = active.every(function (f) {
        var col = +f.getAttribute('data-col');
        var want = f.value.toLowerCase();
        var got = cellText(tr.cells[col]).toLowerCase();
        // A dropdown means "exactly this one"; a typed box means "contains".
        return f.tagName === 'SELECT' ? got === want : got.indexOf(want) !== -1;
      });
      tr.classList.toggle('col-hidden', !hit);
      if (hit) shown++;
    });
    filters().forEach(function (f) { f.classList.toggle('on', f.value !== ''); });
    // Decided HERE, not in a listener on the header: pressing Clear sets the
    // boxes from script, which fires no `input` event, so a listener left the
    // button offering to clear what it had just cleared.
    if (clear) clear.hidden = active.length === 0;

    countLine.textContent = active.length
      ? shown + ' of ' + rows.length + (rows.length === 1 ? ' person' : ' people') +
        ' match' + (shown === 1 ? 'es' : '') + ' the column filters'
      : rows.length + (rows.length === 1 ? ' person' : ' people') + ' on this page';
    countLine.classList.toggle('col-count-on', active.length > 0);
    // Said out loud as well as shown: somebody who filters to nothing should not
    // be left wondering whether the page broke.
    countLine.classList.toggle('err', active.length > 0 && shown === 0);
  }

  // --- sorting ----------------------------------------------------------------
  function applySort() {
    if (sortCol < 0) return;
    var order = rows.slice();
    order.sort(function (a, b) {
      var ka = sortKey(a.cells[sortCol]), kb = sortKey(b.cells[sortCol]);
      if (ka[0] !== kb[0]) return ka[0] - kb[0];        // empties last, always
      if (ka[1] < kb[1]) return -1 * sortDir;
      if (ka[1] > kb[1]) return 1 * sortDir;
      return 0;
    });
    // Reattaching a <tr> MOVES it; the fields and their values come with it, and
    // a save already in flight holds the row itself rather than its position.
    var frag = document.createDocumentFragment();
    order.forEach(function (tr) { frag.appendChild(tr); });
    tbody.appendChild(frag);

    Array.prototype.forEach.call(headRow.cells, function (th) {
      var b = th.querySelector('.col-sort');
      if (!b) return;
      var on = +th.cellIndex === sortCol;
      b.setAttribute('data-dir', on ? (sortDir > 0 ? 'up' : 'down') : '');
      th.setAttribute('aria-sort', on ? (sortDir > 0 ? 'ascending' : 'descending') : 'none');
    });
  }

  // --- build the headers ------------------------------------------------------
  Array.prototype.forEach.call(headRow.cells, function (th) {
    var label = (th.textContent || '').trim();
    if (label === '') return;                 // the per-row status column
    var col = th.cellIndex;
    th.textContent = '';

    var sort = document.createElement('button');
    sort.type = 'button';
    sort.className = 'col-sort';
    sort.textContent = label;
    sort.title = 'Sort by ' + label;
    sort.addEventListener('click', function () {
      sortDir = (sortCol === col) ? -sortDir : 1;
      sortCol = col;
      applySort();
    });
    th.appendChild(sort);

    var f;
    if (isChoice(col)) {
      f = document.createElement('select');
      f.appendChild(new Option('any', ''));
      choices(col).forEach(function (v) { f.appendChild(new Option(v, v)); });
    } else {
      f = document.createElement('input');
      f.type = 'search';
      f.placeholder = 'filter';
    }
    f.className = 'col-filter';
    f.setAttribute('data-col', col);
    f.setAttribute('aria-label', 'Filter by ' + label);
    // `input` rather than `change`, so the list narrows as it is typed. These
    // are not form fields of the grid and carry no name, so nothing here is ever
    // posted with a row.
    f.addEventListener('input', applyFilters);
    th.appendChild(f);
  });

  // A filter and a sort are worth clearing together, and one control says so.
  clear = document.createElement('button');
  clear.type = 'button';
  clear.className = 'secondary small col-clear';
  clear.textContent = 'Clear column filters';
  clear.hidden = true;
  clear.addEventListener('click', function () {
    filters().forEach(function (f) { f.value = ''; });
    applyFilters();
  });
  countLine.parentNode.insertBefore(clear, countLine.nextSibling);

  applyFilters();
}());
