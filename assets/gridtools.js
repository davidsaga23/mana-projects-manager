// Column filters, sorting and a live count, for any table on this platform.
//
// David asked for it on the Artists grid (2026-09-29: "in the headers add a
// filter, a sort order and so we know how many results that filter produced")
// and for the same thing on the shot breakdown the next day. It lives here
// rather than twice because the second copy is where the two start disagreeing
// about what "empty sorts last" means — the mistake this codebase keeps paying
// for elsewhere (see includes/quota.php's one reader, and step_applicable_count).
//
// ⚠ ENTIRELY IN THE BROWSER, and on these two screens that is CORRECTNESS, not
// speed. Both grids hold EDITS and save as you go, so a server-side filter would
// reload the page under whatever somebody had half-typed. It also lets the two
// compose: a server-side search decides who is on the page, these decide what
// you look at within that.
//
// ⚠ HIDDEN ROWS STILL SAVE. Rows are hidden with a class, never removed, so
// their fields stay in the DOM and in the form. An edit made before a filter was
// typed is not silently dropped by the filter.
//
// The controls are built from what a column ACTUALLY HOLDS rather than from a
// description passed in: a column of <select>s or checkboxes is offered as a
// dropdown of the values in use, everything else as a box to type in.
(function () {
  'use strict';

  /**
   * What a cell SAYS, whatever it is made of.
   *
   * Order matters. A checkbox reads yes/no so it can be filtered like a word; a
   * <select> reads its option's TEXT, which is what somebody sees; and the field
   * lookup excludes type=hidden but not the `hidden` ATTRIBUTE, so the artist
   * grid's reel cell yields its address while the anchor is what shows.
   */
  function defaultText(td) {
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
   * A <select> sorts by the position of the chosen option, so a seniority column
   * reads Junior, Mid, Senior, Lead — the order the app itself put them in —
   * rather than alphabetically, and a rating sorts by stars rather than by the
   * length of a string of them. Numbers sort as numbers.
   *
   * ⚠ AN EMPTY CELL ALWAYS SINKS, whichever direction is asked for. "Nothing
   * recorded" is not a value and does not belong at either extreme; a reversed
   * sort that floats forty blanks to the top has buried the answer.
   */
  function defaultKey(td, text) {
    var box = td && td.querySelector('input[type=checkbox]');
    if (box) return [0, box.checked ? 1 : 0];
    var sel = td && td.querySelector('select');
    if (sel) return sel.selectedIndex > 0 ? [0, sel.selectedIndex] : [1, 0];
    var v = text;
    if (v === '' || v === '—') return [1, 0];
    var n = parseFloat(v.replace(/[^0-9.\-]/g, ''));
    if (!isNaN(n) && /^[^A-Za-z]*$/.test(v)) return [0, n];
    return [0, v.toLowerCase()];
  }

  /**
   * @param table   the <table> to equip
   * @param opts    cellText(td)     -> string, overriding defaultText
   *                cellList(td)     -> array of values, or null. A cell holding
   *                                    SEVERAL things (the breakdown's chips) is
   *                                    matched as "contains this one" rather
   *                                    than as a whole string.
   *                options(col)     -> [{value,label}], or null to use the values
   *                                    found in the column. This is how a filter
   *                                    offers everything that COULD be there
   *                                    rather than only what already is.
   *                sortKey(td,text) -> [rank, value], overriding defaultKey
   *                skip(th, index)  -> true to leave a column without controls
   *                noun             ['person','people'], for the count line
   *                onChange(shown, all, filtering)  after every filter pass
   */
  function attach(table, opts) {
    opts = opts || {};
    if (!table || !table.tHead || !table.tBodies.length) return null;
    var tbody = table.tBodies[0];
    var headRow = table.tHead.rows[0];
    var rows = Array.prototype.slice.call(tbody.rows);
    if (!rows.length) return null;

    var text = opts.cellText || defaultText;
    var list = opts.cellList || function () { return null; };
    var key = opts.sortKey || defaultKey;
    // The value a dropdown uses to mean "this cell holds nothing". A real name
    // could never collide with it: it is not a name, it is a sentence.
    var NONE = '\u0000none';
    var noun = opts.noun || ['row', 'rows'];
    var sortCol = -1, sortDir = 1;
    var clear = null;

    function filters() {
      return Array.prototype.slice.call(headRow.querySelectorAll('.col-filter'));
    }

    function isChoice(col) {
      var td = rows[0].cells[col];
      return !!(td && (td.querySelector('select') || td.querySelector('input[type=checkbox]')));
    }

    /**
     * What a column's dropdown offers.
     *
     * The caller gets first refusal, because it often knows a better list than
     * the table does: the breakdown's Character filter offers EVERY character on
     * the show, the same list and the same order the per-shot picker uses, so a
     * name that is in the project but not yet in any shot is still there to pick
     * — and picking it correctly finds nothing. A list built from the table
     * could only ever offer what is already linked.
     *
     * Failing that, the values in use, in the order the column itself implies.
     */
    function choices(col) {
      var given = opts.options ? opts.options(col) : null;
      if (given) return given;
      var seen = Object.create(null), out = [];
      rows.forEach(function (tr) {
        var td = tr.cells[col];
        if (!td) return;
        var v = text(td);
        if (v === '' || seen[v]) return;
        seen[v] = true;
        var sel = td.querySelector('select');
        out.push({ text: v, at: sel ? sel.selectedIndex : out.length });
      });
      out.sort(function (a, b) { return a.at - b.at; });
      return out.map(function (o) { return { value: o.text, label: o.text }; });
    }

    // --- the count ----------------------------------------------------------
    //
    // ⚠ A SECOND ROW IN <thead>, not a line above the table (David, 2026-10-01:
    // "I didn't see the total count... below the headers I want to see the total
    // shot count"). Above the table it scrolls away with the first few rows,
    // which on a list of forty-five is exactly when the number starts mattering.
    // In the head it is sticky, so it stays under the filters that produced it.
    //
    // Its `top` is MEASURED from the heading row rather than guessed, and
    // re-measured on resize: the head's height changes with the font, the window
    // width and whether a heading wraps, and a hard-coded offset is the kind of
    // number that goes wrong quietly. This is the reason the filters were put
    // inside the one <th> in the first place; a count row is worth the
    // measurement because it has nothing to line up with horizontally.
    var countRow = table.tHead.insertRow(-1);
    countRow.className = 'col-count-row';
    var countCell = countRow.insertCell(0);
    countCell.colSpan = headRow.cells.length;
    var countLine = document.createElement('span');
    countLine.className = 'col-count';
    countCell.appendChild(countLine);

    function placeCountRow() {
      // Set on the CELL, not the row: `position: sticky` applies to the <td>
      // under border-collapse, and a `top` on the <tr> is simply ignored.
      countCell.style.top = headRow.getBoundingClientRect().height + 'px';
    }
    window.addEventListener('resize', placeCountRow);
    if (window.ResizeObserver) new ResizeObserver(placeCountRow).observe(headRow);

    function applyFilters() {
      var active = filters().filter(function (f) { return f.value !== ''; });
      var shown = [];
      rows.forEach(function (tr) {
        var hit = active.every(function (f) {
          var col = +f.getAttribute('data-col');
          var td = tr.cells[col];
          var many = list(td);

          // A CELL THAT HOLDS SEVERAL THINGS is matched item by item. A shot's
          // Prop cell reads "Boots, SoccerBall"; picking SoccerBall has to find
          // it, and must not also find a prop called "Ball" by being a substring
          // of it. So the comparison is against each chip, whole.
          if (many) {
            if (f.value === NONE) return many.length === 0;
            var want = f.value.toLowerCase();
            if (f.tagName === 'SELECT') {
              return many.some(function (v) { return v.toLowerCase() === want; });
            }
            return many.some(function (v) { return v.toLowerCase().indexOf(want) !== -1; });
          }

          var got = text(td).toLowerCase();
          if (f.value === NONE) return got === '' || got === '\u2014';
          // A dropdown means "exactly this one"; a typed box means "contains".
          return f.tagName === 'SELECT' ? got === f.value.toLowerCase() : got.indexOf(f.value.toLowerCase()) !== -1;
        });
        tr.classList.toggle('col-hidden', !hit);
        if (hit) shown.push(tr);
      });

      var word = function (n) { return n === 1 ? noun[0] : noun[1]; };
      countLine.textContent = active.length
        ? shown.length + ' of ' + rows.length + ' ' + word(rows.length) +
          ' match' + (shown.length === 1 ? 'es' : '') + ' the column filters'
        : rows.length + ' ' + word(rows.length) + ' on this page';
      countLine.classList.toggle('col-count-on', active.length > 0);
      // Said out loud as well as shown: somebody who filters to nothing should
      // not be left wondering whether the page broke.
      countLine.classList.toggle('err', active.length > 0 && shown.length === 0);

      filters().forEach(function (f) { f.classList.toggle('on', f.value !== ''); });
      // Decided HERE, not in a listener on the header: pressing Clear sets the
      // boxes from script, which fires no `input` event, so a listener left the
      // button offering to clear what it had just cleared.
      if (clear) clear.hidden = active.length === 0;

      if (opts.onChange) opts.onChange(shown, rows, active.length > 0);
    }

    // --- sorting ------------------------------------------------------------
    function applySort() {
      if (sortCol < 0) return;
      var order = rows.slice();
      order.sort(function (a, b) {
        var ta = text(a.cells[sortCol]), tb = text(b.cells[sortCol]);
        var ka = key(a.cells[sortCol], ta), kb = key(b.cells[sortCol], tb);
        if (ka[0] !== kb[0]) return ka[0] - kb[0];        // empties last, always
        if (ka[1] < kb[1]) return -1 * sortDir;
        if (ka[1] > kb[1]) return 1 * sortDir;
        return 0;
      });
      // Reattaching a <tr> MOVES it; the fields and their values come with it,
      // and a save already in flight holds the row itself, not its position.
      var frag = document.createDocumentFragment();
      order.forEach(function (tr) { frag.appendChild(tr); });
      tbody.appendChild(frag);

      Array.prototype.forEach.call(headRow.cells, function (th) {
        var b = th.querySelector('.col-sort');
        if (!b) return;
        var on = th.cellIndex === sortCol;
        b.setAttribute('data-dir', on ? (sortDir > 0 ? 'up' : 'down') : '');
        th.setAttribute('aria-sort', on ? (sortDir > 0 ? 'ascending' : 'descending') : 'none');
      });
    }

    // --- build the headers --------------------------------------------------
    Array.prototype.forEach.call(headRow.cells, function (th) {
      var label = (th.textContent || '').trim();
      var col = th.cellIndex;
      if (label === '') return;                       // a spacer or status column
      if (opts.skip && opts.skip(th, col)) return;

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
      var opened = choices(col);
      if (isChoice(col) || (opts.options && opts.options(col))) {
        f = document.createElement('select');
        f.appendChild(new Option('any', ''));
        // "Nothing here" is a question worth being able to ask: which shots have
        // no character in them, which artists have no reel. It is offered on any
        // dropdown whose column actually has an empty cell to find.
        if (rows.some(function (tr) {
              var td = tr.cells[col], many = list(td);
              return many ? many.length === 0 : (text(td) === '' || text(td) === '\u2014');
            })) {
          f.appendChild(new Option('(nothing)', NONE));
        }
        opened.forEach(function (o) { f.appendChild(new Option(o.label, o.value)); });
      } else {
        f = document.createElement('input');
        f.type = 'search';
        f.placeholder = 'filter';
      }
      f.className = 'col-filter';
      f.setAttribute('data-col', col);
      f.setAttribute('aria-label', 'Filter by ' + label);
      // `input` rather than `change`, so the list narrows as it is typed. These
      // carry no name attribute, so nothing here is ever posted with a row.
      f.addEventListener('input', applyFilters);
      th.appendChild(f);
    });

    // A filter is worth clearing in one go, and one control says so.
    clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'secondary small col-clear';
    clear.textContent = 'Clear column filters';
    clear.hidden = true;
    clear.addEventListener('click', function () {
      filters().forEach(function (f) { f.value = ''; });
      applyFilters();
    });
    countCell.appendChild(clear);

    applyFilters();
    placeCountRow();
    return { apply: applyFilters, countLine: countLine, rows: rows, place: placeCountRow };
  }

  window.GridTools = { attach: attach, cellText: defaultText, sortKey: defaultKey };
}());
