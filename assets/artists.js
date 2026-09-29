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
  // how many results that filter produced." The same thing was wanted on the
  // shot breakdown the next day, so the machinery lives in assets/gridtools.js
  // and both screens call it — a second copy is where the two would start
  // disagreeing about what "empty sorts last" means.
  //
  // Nothing is passed about the columns: gridtools reads what each one actually
  // holds. Seniority and Rating are <select>s, so they get dropdowns in their own
  // order; Worked is a checkbox, so it reads yes/no; everything else is a box to
  // type in. The reel cell yields its address rather than the dash, because the
  // default reader prefers a field to the text around it.
  if (window.GridTools) {
    window.GridTools.attach(document.querySelector('.artist-table'), {
      noun: ['person', 'people']
    });
  }
}());
