// The Artists grid: every field is editable in the row, and ONLY the rows you
// actually touched are sent.
//
// That is correctness, not economy. PHP's max_input_vars defaults to 1000 and
// silently discards everything past it — no error, no warning, just a POST that
// arrives half empty. This grid is about thirteen fields a row, so seventy-odd
// rows would reach the limit, and a save over a full list would quietly write
// nothing for most of it.
//
// Disabling the untouched rows before submitting means an ordinary edit posts a
// few dozen fields instead of five thousand. The form's trailing sentinel is the
// backstop: if PHP still cut the tail, the server sees it missing and refuses
// rather than writing whatever survived.
(function () {
  'use strict';

  var form = document.getElementById('artistGrid');
  if (!form) return;

  var counter = document.getElementById('dirtyCount');
  var dirty = Object.create(null);

  function rowOf(el) {
    return el.closest ? el.closest('tr') : null;
  }

  /** A row's key is the id in its field names — rows[123][email]. */
  function keyOf(row) {
    var input = row.querySelector('[name^="rows["]');
    if (!input) return null;
    var m = /^rows\[(\d+)\]/.exec(input.getAttribute('name'));
    return m ? m[1] : null;
  }

  function count() {
    if (!counter) return;
    var n = Object.keys(dirty).length;
    counter.textContent = n === 0
      ? 'nothing changed yet'
      : n + (n === 1 ? ' person changed' : ' people changed') + ' — Save to write them';
  }

  // One listener on the form rather than one per input: five thousand listeners
  // is how a page becomes slow to open.
  function mark(ev) {
    var row = rowOf(ev.target);
    if (!row) return;
    var k = keyOf(row);
    if (!k || dirty[k]) return;
    dirty[k] = true;
    row.classList.add('is-dirty');
    count();
  }
  form.addEventListener('input', mark);
  form.addEventListener('change', mark);

  form.addEventListener('submit', function (ev) {
    if (!Object.keys(dirty).length) {
      // Nothing to say to the server. Stopping here also avoids a reload that
      // would look like a save.
      ev.preventDefault();
      if (counter) counter.textContent = 'nothing changed, so nothing was sent';
      return;
    }
    // Disabled fields are not submitted, which is exactly what is wanted — and
    // it is reversible, so a failed submit leaves the page usable.
    Array.prototype.forEach.call(form.querySelectorAll('tbody tr'), function (row) {
      var k = keyOf(row);
      if (k && dirty[k]) return;
      Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (f) {
        f.disabled = true;
      });
    });
  });

  // Leaving with unsaved edits is worth one question. The browser decides the
  // wording; all that matters is that it asks.
  window.addEventListener('beforeunload', function (ev) {
    if (!Object.keys(dirty).length) return;
    ev.preventDefault();
    ev.returnValue = '';
  });
}());
