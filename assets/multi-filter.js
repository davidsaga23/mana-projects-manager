// Pick-several filter dropdowns, with an Include / Exclude switch.
//
// David, 2026-11-20: "for all the drop down menus, we need a way to do all
// these things: 1. Multiple selection: be able to pick one or more options from
// those menus. 2. Reverse selection: exclude from filtering the selected
// options."
//
// ONE CONTROL FOR EVERY FILTER ON THE PLATFORM. A page writes an ordinary
// <select> with `data-multi` on it; this file hides it and draws a chip that
// looks like the dropdown it replaces ("Status: In progress, Pending review",
// "Status: 3 selected", "Status: not Final"). Opening the chip shows ticks, a
// search box when the list is long, an Include / Exclude switch and Clear.
//
// ⚠ THE <select> STAYS THE SOURCE OF TRUTH. The ticks are its selected options
// and Exclude is its `data-exclude` attribute, so:
//   - a form still posts it (the name gains `[]`, and a hidden `<name>_not=1`
//     rides along while Exclude is on — includes/filters.php reads both, and
//     an old single-value link like ?status=3 still means "status 3");
//   - a page's own script reads it with MultiFilter.state() / matches(), at any
//     time, whether or not the chip has been drawn yet.
//
// ⚠ WHEN THE PAGE HEARS ABOUT A CHANGE:
//   - A select inside a <form> that has a name (a server-side filter, usually
//     `onchange="this.form.submit()"`) hears ONE `change` when the list is
//     closed, and only if something changed. Reloading the page on every tick
//     would take the list away from under the person ticking it.
//   - Any other select (a filter that runs in the browser) hears `input` and
//     `change` on every tick, so the rows narrow as you go.
//   `data-multi-apply="close"` or `"live"` overrides the guess.
//
// The first option with an empty value ("Any status", "Anybody") is the
// "nothing picked" wording and is never offered as a tick.
//
// ⚠ LOADED ON EVERY PAGE by includes/header.php, before the page's own
// scripts, so keep it small and never let it throw.
(function () {
  'use strict';

  var LONG = 8;               // more options than this, and the list gets a search box
  var PHONE = '(max-width: 640px)';
  var open = null;            // the one list showing, { sel, panel, before }
  var uid = 0;

  function arr(x) { return Array.prototype.slice.call(x || []); }

  /** The options a person can tick: everything but the "nothing picked" one. */
  function choices(sel) {
    return arr(sel.options).filter(function (o) { return o.value !== ''; });
  }

  /** What is picked: { values: [...], exclude: bool }. Works on any select. */
  function state(sel) {
    if (!sel) return { values: [], exclude: false };
    var values = arr(sel.options)
      .filter(function (o) { return o.selected && o.value !== ''; })
      .map(function (o) { return o.value; });
    return { values: values, exclude: sel.getAttribute('data-exclude') === '1' };
  }

  /** Is anything being filtered? Exclude with nothing ticked filters nothing. */
  function active(sel) { return state(sel).values.length > 0; }

  /**
   * Does a row with this value pass the filter? `got` may be one value or a list
   * of them (a cell holding several things): a list passes Include when ANY of
   * its values is ticked, and Exclude when NONE is. `same(a, b)` compares, when
   * the page needs a looser test than ===.
   */
  function matches(sel, got, same) {
    var s = state(sel);
    if (!s.values.length) return true;
    var many = Array.isArray(got) ? got : [got];
    var eq = same || function (a, b) { return a === b; };
    var hit = many.some(function (g) {
      return s.values.some(function (v) { return eq(v, g); });
    });
    return s.exclude ? !hit : hit;
  }

  /** Set the ticks (and, if given, Exclude) from a script. Fires no event. */
  function set(sel, values, exclude) {
    if (!sel) return;
    if (!Array.isArray(values)) values = (values === '' || values == null) ? [] : [String(values)];
    values = values.map(String);
    if (values.length > 1 && !sel.multiple) sel.multiple = true;
    arr(sel.options).forEach(function (o) {
      o.selected = o.value !== '' && values.indexOf(o.value) !== -1;
    });
    if (typeof exclude === 'boolean') {
      if (exclude) sel.setAttribute('data-exclude', '1'); else sel.removeAttribute('data-exclude');
    }
    sync(sel);
  }

  function clear(sel) { set(sel, [], false); }

  // --- the chip --------------------------------------------------------------

  function label(sel) {
    var l = sel.getAttribute('data-multi-label');
    if (l !== null) return l;
    var a = sel.getAttribute('aria-label') || '';
    a = a.replace(/^filter by\s+/i, '');
    return a ? a.charAt(0).toUpperCase() + a.slice(1) : '';
  }

  function emptyText(sel) {
    var o = arr(sel.options).filter(function (o) { return o.value === ''; })[0];
    return o ? o.text : 'Any';
  }

  /** "In progress, Pending review", "3 selected", "not Final", "all but 3". */
  function summary(sel) {
    var s = state(sel);
    if (!s.values.length) return { text: emptyText(sel), on: false };
    var names = choices(sel)
      .filter(function (o) { return o.selected; })
      .map(function (o) { return o.text.trim(); });
    var what = names.length <= 2 ? names.join(', ') : names.length + ' selected';
    if (s.exclude) what = names.length <= 2 ? 'not ' + names.join(', ') : 'all but ' + names.length;
    var l = label(sel);
    return { text: (l ? l + ': ' : '') + what, on: true, exclude: s.exclude, full: names.join(', ') };
  }

  /** Redraw the chip, the posted name and the hidden Exclude field. */
  function sync(sel) {
    var chip = sel._mfChip;
    if (chip) {
      var sm = summary(sel);
      chip.querySelector('.mf-text').textContent = sm.text;
      chip.title = sm.on ? (sm.exclude ? 'Everything except: ' : 'Only: ') + sm.full : sm.text;
      chip.classList.toggle('is-on', sm.on);
      chip.classList.toggle('is-not', !!(sm.on && sm.exclude));
      // A page that hides its dropdown (the CRM hides Owner on Quotes) hides the chip.
      chip.hidden = sel.hidden;
    }
    // ⚠ A FORM POSTS THE EXCLUDE ONLY WHILE IT MEANS SOMETHING, so a page that
    // reloads with nothing ticked does not carry `_not=1` into every link.
    var form = sel.form, name = sel.getAttribute('name');
    if (form && name && sel._mfEnhanced) {
      var base = name.replace(/\[\]$/, '');
      var hid = sel._mfNot;
      var want = state(sel).exclude && active(sel);
      if (want && !hid) {
        hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = base + '_not';
        hid.value = '1';
        sel.parentNode.insertBefore(hid, sel.nextSibling);
        sel._mfNot = hid;
      } else if (!want && hid) {
        hid.parentNode && hid.parentNode.removeChild(hid);
        sel._mfNot = null;
      }
    }
  }

  function applyMode(sel) {
    var m = sel.getAttribute('data-multi-apply');
    if (m === 'live' || m === 'close') return m;
    return sel.form && sel.getAttribute('name') ? 'close' : 'live';
  }

  function fire(sel) {
    sel.dispatchEvent(new Event('input', { bubbles: true }));
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function key(sel) { var s = state(sel); return (s.exclude ? '!' : '') + s.values.join('\u0001'); }

  /** Something was ticked, unticked, switched or cleared in the open list. */
  function changed(sel) {
    sync(sel);
    if (applyMode(sel) === 'live') fire(sel);
  }

  // --- the list ----------------------------------------------------------------

  function build(sel) {
    var panel = document.createElement('div');
    panel.className = 'mf-panel';
    panel.id = 'mf-panel-' + (++uid);
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', (label(sel) || 'Filter') + ' filter');

    // Include / Exclude, said as what they do.
    var s = state(sel);
    var mode = document.createElement('div');
    mode.className = 'mf-mode';
    mode.setAttribute('role', 'group');
    mode.setAttribute('aria-label', 'Show or hide the ticked ones');
    [['0', 'Include', 'Show only the ticked ones'],
     ['1', 'Exclude', 'Show everything except the ticked ones']].forEach(function (m) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'mf-mode-btn';
      b.textContent = m[1];
      b.title = m[2];
      b.setAttribute('data-x', m[0]);
      b.setAttribute('aria-pressed', String((m[0] === '1') === s.exclude));
      b.addEventListener('click', function () {
        if (m[0] === '1') sel.setAttribute('data-exclude', '1'); else sel.removeAttribute('data-exclude');
        arr(mode.children).forEach(function (x) {
          x.setAttribute('aria-pressed', String(x === b));
        });
        changed(sel);
      });
      mode.appendChild(b);
    });
    panel.appendChild(mode);

    var opts = choices(sel);
    var search = null;
    if (opts.length > LONG) {
      search = document.createElement('input');
      search.type = 'search';
      search.className = 'mf-search';
      search.placeholder = 'Search…';
      search.setAttribute('aria-label', 'Search the list');
      panel.appendChild(search);
    }

    var list = document.createElement('div');
    list.className = 'mf-list';
    var lastGroup = null;
    opts.forEach(function (o) {
      var g = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode : null;
      if (g && g !== lastGroup) {
        var h = document.createElement('div');
        h.className = 'mf-group';
        h.textContent = g.label;
        list.appendChild(h);
      }
      lastGroup = g;
      var row = document.createElement('label');
      row.className = 'mf-opt';
      var box = document.createElement('input');
      box.type = 'checkbox';
      box.checked = o.selected;
      box.disabled = o.disabled;
      var val = o.value;
      box.addEventListener('change', function () {
        if (!sel.multiple) sel.multiple = true;
        // ⚠ FOUND BY VALUE, NOT HELD: a page that redraws its options on every
        // change (Bookings' person list) has replaced `o` by the second tick.
        arr(sel.options).forEach(function (x) { if (x.value === val) x.selected = box.checked; });
        changed(sel);
      });
      var t = document.createElement('span');
      t.textContent = o.text;
      row.appendChild(box);
      row.appendChild(t);
      row.setAttribute('data-q', o.text.toLowerCase());
      list.appendChild(row);
    });
    if (!opts.length) {
      var none = document.createElement('p');
      none.className = 'mf-none';
      none.textContent = 'Nothing to pick yet.';
      list.appendChild(none);
    }
    panel.appendChild(list);

    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        arr(list.querySelectorAll('.mf-opt')).forEach(function (r) {
          r.hidden = q !== '' && r.getAttribute('data-q').indexOf(q) === -1;
        });
        // A heading over nothing it heads is hidden with its rows.
        arr(list.querySelectorAll('.mf-group')).forEach(function (h) {
          var n = h.nextElementSibling, any = false;
          while (n && !n.classList.contains('mf-group')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
          h.hidden = !any;
        });
      });
      // Enter in the search ticks the one row left, the quick way to filter.
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          var shown = arr(list.querySelectorAll('.mf-opt')).filter(function (r) { return !r.hidden; });
          if (shown.length === 1) shown[0].querySelector('input').click();
        } else if (e.key === 'ArrowDown') {
          e.preventDefault();
          focusBox(list, 0, 1);
        }
      });
    }

    var foot = document.createElement('div');
    foot.className = 'mf-foot';
    var clr = document.createElement('button');
    clr.type = 'button';
    clr.className = 'mf-clear';
    clr.textContent = 'Clear';
    clr.addEventListener('click', function () {
      clear(sel);
      arr(list.querySelectorAll('input[type=checkbox]')).forEach(function (b) { b.checked = false; });
      arr(mode.children).forEach(function (x) {
        x.setAttribute('aria-pressed', String(x.getAttribute('data-x') === '0'));
      });
      if (applyMode(sel) === 'live') fire(sel);
    });
    var done = document.createElement('button');
    done.type = 'button';
    done.className = 'mf-done';
    done.textContent = 'Done';
    done.addEventListener('click', function () { shut(true); });
    foot.appendChild(clr);
    foot.appendChild(done);
    panel.appendChild(foot);

    // Up and down walk the ticks, the way they walk a dropdown.
    list.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var boxes = visibleBoxes(list);
      var at = boxes.indexOf(document.activeElement);
      if (at === -1) return;
      e.preventDefault();
      if (e.key === 'ArrowUp' && at === 0 && search) { search.focus(); return; }
      focusBox(list, at, e.key === 'ArrowDown' ? 1 : -1);
    });

    return panel;
  }

  function visibleBoxes(list) {
    return arr(list.querySelectorAll('.mf-opt')).filter(function (r) { return !r.hidden; })
      .map(function (r) { return r.querySelector('input'); })
      .filter(function (b) { return !b.disabled; });
  }
  function focusBox(list, from, step) {
    var boxes = visibleBoxes(list);
    if (!boxes.length) return;
    var i = Math.max(0, Math.min(boxes.length - 1, from + (document.activeElement === boxes[from] ? step : 0)));
    boxes[i].focus();
  }

  /**
   * ⚠ THE LIST HANGS OFF <body>, fixed, not inside the filter bar: Spell's bar
   * is sticky and the grids' heads sit in scrolling boxes, and either would
   * clip a list drawn inside them. On a phone it is a sheet along the bottom.
   */
  function place() {
    if (!open) return;
    var p = open.panel;
    if (window.matchMedia && window.matchMedia(PHONE).matches) {
      p.classList.add('is-sheet');
      p.style.left = p.style.top = p.style.width = '';
      return;
    }
    p.classList.remove('is-sheet');
    var r = open.chip.getBoundingClientRect();
    var w = Math.max(220, Math.min(340, r.width));
    p.style.width = w + 'px';
    var left = Math.min(r.left, window.innerWidth - w - 8);
    p.style.left = Math.max(8, left) + 'px';
    var below = window.innerHeight - r.bottom;
    var h = p.offsetHeight;
    p.style.top = (below < h + 8 && r.top > below ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
  }

  function show(sel) {
    if (open && open.sel === sel) return;
    shut(false);
    var chip = sel._mfChip;
    var panel = build(sel);
    document.body.appendChild(panel);
    open = { sel: sel, chip: chip, panel: panel, before: key(sel) };
    chip.setAttribute('aria-expanded', 'true');
    chip.setAttribute('aria-controls', panel.id);
    document.documentElement.classList.toggle('mf-sheet-open',
      !!(window.matchMedia && window.matchMedia(PHONE).matches));
    place();
    var first = panel.querySelector('.mf-search') || visibleBoxes(panel.querySelector('.mf-list'))[0]
      || panel.querySelector('.mf-done');
    if (first) first.focus();
  }

  /** Close the list; `back` puts the keyboard back on the chip. */
  function shut(back) {
    if (!open) return;
    var o = open;
    open = null;
    o.panel.parentNode && o.panel.parentNode.removeChild(o.panel);
    o.chip.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('mf-sheet-open');
    if (back) o.chip.focus();
    if (applyMode(o.sel) === 'close' && key(o.sel) !== o.before) fire(o.sel);
  }

  document.addEventListener('mousedown', function (e) {
    if (!open) return;
    if (open.panel.contains(e.target) || open.chip.contains(e.target)) return;
    shut(false);
  }, true);
  document.addEventListener('touchstart', function (e) {
    if (!open) return;
    if (open.panel.contains(e.target) || open.chip.contains(e.target)) return;
    shut(false);
  }, { capture: true, passive: true });
  document.addEventListener('keydown', function (e) {
    if (open && e.key === 'Escape') { e.preventDefault(); shut(true); }
  });
  // Tabbing out of the list closes it, as leaving a dropdown does.
  document.addEventListener('focusin', function (e) {
    if (!open) return;
    if (open.panel.contains(e.target) || open.chip === e.target) return;
    shut(false);
  });
  window.addEventListener('resize', place);
  window.addEventListener('scroll', function (e) {
    // The list's own scrolling is not the page moving under it.
    if (open && !open.panel.contains(e.target)) place();
  }, true);

  // --- turning a <select> into the chip ---------------------------------------

  function enhance(sel) {
    if (!sel || sel._mfEnhanced || sel.tagName !== 'SELECT') return sel;
    try {
      sel._mfEnhanced = true;
      // A form posts every tick, so PHP needs the brackets to read a list.
      var name = sel.getAttribute('name');
      if (name && !/\[\]$/.test(name)) sel.setAttribute('name', name + '[]');
      // ⚠ MULTIPLE FROM THE START, so a script setting two values is not
      // quietly cut to one. The "nothing picked" option is never left selected.
      var picked = state(sel).values;
      sel.multiple = true;
      arr(sel.options).forEach(function (o) { o.selected = o.value !== '' && picked.indexOf(o.value) !== -1; });

      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'mf-chip ' + (sel.getAttribute('data-multi-class') || '');
      chip.setAttribute('aria-haspopup', 'dialog');
      chip.setAttribute('aria-expanded', 'false');
      var al = sel.getAttribute('aria-label');
      if (al) chip.setAttribute('aria-label', al);
      if (sel.id) {
        var lab = document.querySelector('label[for="' + sel.id + '"]');
        if (lab && !al) chip.setAttribute('aria-label', lab.textContent.trim());
      }
      var t = document.createElement('span');
      t.className = 'mf-text';
      var caret = document.createElement('span');
      caret.className = 'mf-caret';
      caret.setAttribute('aria-hidden', 'true');
      chip.appendChild(t);
      chip.appendChild(caret);
      chip.addEventListener('click', function () {
        if (open && open.sel === sel) shut(true); else show(sel);
      });
      chip.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && !(open && open.sel === sel)) { e.preventDefault(); show(sel); }
      });

      sel._mfChip = chip;
      sel.classList.add('mf-native');
      sel.setAttribute('tabindex', '-1');
      sel.setAttribute('aria-hidden', 'true');
      // Before the select, so a <label> wrapped round both hands its clicks to
      // the chip (the first thing in it a label can point at).
      sel.parentNode.insertBefore(chip, sel);

      // A page that fills or rewrites the options later (the CRM's owners, the
      // statements' positions) gets its chip redrawn; so does a page that sets
      // the value from its own script and then says so with an event.
      if (window.MutationObserver) {
        new MutationObserver(function () { sync(sel); })
          .observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
      }
      sel.addEventListener('change', function () { sync(sel); });
      sel.addEventListener('input', function () { sync(sel); });
      sync(sel);
    } catch (err) {
      // Better a plain dropdown than a missing one.
      if (window.console) console.warn('multi-filter:', err);
    }
    return sel;
  }

  function enhanceAll(root) {
    arr((root || document).querySelectorAll('select[data-multi]')).forEach(enhance);
  }

  window.MultiFilter = {
    enhance: enhance, enhanceAll: enhanceAll, state: state, active: active,
    matches: matches, set: set, clear: clear, refresh: sync
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enhanceAll(); });
  } else {
    enhanceAll();
  }
}());
