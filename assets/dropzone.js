// "Drop files here" — a file picker people can actually use.
//
// David, 2026-10-23, pointing at the native control: "let's change all of
// these for a nice drop files here kind of UI". `Choose Files / No file chosen`
// is the browser's own widget: it cannot be styled, it says nothing about what
// is allowed, and once six files are picked there is no way to remove one —
// <input type="file" multiple> only lets you replace the whole selection.
//
// ⚠ IT ENHANCES, IT DOES NOT REPLACE. The real <input type="file"> stays in the
// form and stays the thing that is submitted; this draws a zone over it and
// keeps input.files in step through a DataTransfer. So a browser that does not
// run this still gets a working, if plain, file picker, and every server-side
// path — board_comment.php, board_version.php, the MIME check in
// accept_upload() — is untouched and unaware.
//
// Kept apart from assets/board-screen.js because it is not about the board:
// the ticket screen has the same native control and can be given this later.
// It is NOT a second copy of the uploader in assets/tickets.js — that one owns
// an XHR submit and a progress bar, which this deliberately does not.
(function () {
  'use strict';

  function humanSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }

  /**
   * Upgrade one <input type="file">.
   *
   * The label text comes from the input's own `data-drop` when it has one, so
   * a version zone can say something different from a comment zone without
   * this file knowing what either of them is.
   */
  function attach(input) {
    // data-no-drop: an input whose form already keeps its own list of files
    // (Spell's review form, with pasting and thumbnails). Two lists of one input
    // overwrite each other.
    // ⚠ data-filepicker BELONGS TO assets/tickets.js, which keeps its own list
    // of files for its progress bar and its chips. Two components holding two
    // lists of one input is the bug that put data-no-drop on the review form;
    // that uploader grew its own zone on 2026-10-23 instead.
    if (!input || input.getAttribute('data-drop-on') || input.hasAttribute('data-no-drop')
        || input.hasAttribute('data-filepicker')) return;
    // DataTransfer is how a chosen list is written back to the input. Without
    // it the remove buttons would be a lie, so the native control stays.
    if (typeof DataTransfer === 'undefined') return;
    input.setAttribute('data-drop-on', '1');

    var multiple = input.hasAttribute('multiple');
    var says = input.getAttribute('data-drop') || 'Drop files here';
    var picked = [];

    var zone = document.createElement('div');
    zone.className = 'dropzone';
    zone.innerHTML =
      '<div class="dropzone-say">' +
        '<strong></strong>' +
        '<span class="muted small">or <span class="dropzone-link">choose from your computer</span></span>' +
      '</div>' +
      '<ul class="dropzone-list"></ul>';
    zone.querySelector('strong').textContent = says;

    input.parentNode.insertBefore(zone, input);
    zone.appendChild(input);           // the real input rides inside, hidden

    var list = zone.querySelector('.dropzone-list');

    function sync() {
      var dt = new DataTransfer();
      picked.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;

      list.textContent = '';
      picked.forEach(function (f, i) {
        var li = document.createElement('li');
        var name = document.createElement('span');
        name.className = 'dropzone-name';
        name.textContent = f.name;
        var size = document.createElement('span');
        size.className = 'muted small';
        size.textContent = humanSize(f.size);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'link-danger';
        rm.textContent = '×';
        rm.title = 'Take ' + f.name + ' off this list';
        rm.addEventListener('click', function (e) {
          e.stopPropagation();
          picked.splice(i, 1);
          sync();
        });
        li.appendChild(name);
        li.appendChild(size);
        li.appendChild(rm);
        list.appendChild(li);
      });
      zone.classList.toggle('has-files', picked.length > 0);

      // ⚠ TELL THE PAGE. Writing input.files fires no event, so a drop or a
      // removal was invisible to anything checking the files — the naming
      // convention check on Spell let a wrongly named DROPPED file through
      // (David, 2026-10-23). Not a 'change' event: this file listens for those
      // itself and would add the same files twice.
      input.dispatchEvent(new CustomEvent('anima:files', { bubbles: true }));
    }

    function add(files) {
      for (var i = 0; i < files.length; i++) {
        if (!multiple) picked = [];
        picked.push(files[i]);
      }
      sync();
    }

    // Clicking anywhere in the zone opens the picker — except on a remove
    // button, which has already stopped the event.
    // ⚠ A LABEL ALREADY DOES THIS. These zones sit inside <label>Attach
    // files … <input></label>, and a click anywhere in a label activates its
    // control — so calling click() as well opened the file chooser TWICE:
    // you pick a file, and it asks again. Measured 2026-10-23.
    var inLabel = !!(zone.closest('label') && zone.closest('label').contains(input));
    zone.addEventListener('click', function (e) {
      if (e.target === input || inLabel) return;
      input.click();
    });

    // The input's own change is still the source of truth for a normal pick.
    input.addEventListener('change', function () {
      if (input.files && input.files.length) add(input.files);
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        e.stopPropagation();
        // dragleave fires when crossing a child, so only let go on the way out.
        if (ev === 'dragleave' && zone.contains(e.relatedTarget)) return;
        zone.classList.remove('is-over');
      });
    });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        add(e.dataTransfer.files);
      }
    });

    // A form that has been submitted and re-rendered starts over; one that is
    // reset should too.
    var form = input.closest('form');
    if (form) form.addEventListener('reset', function () { picked = []; sync(); });
  }

  /** Upgrade every file input inside `root` that has not been done already. */
  function attachAll(root) {
    (root || document).querySelectorAll('input[type="file"]').forEach(attach);
  }

  window.animaDropzone = { attach: attach, attachAll: attachAll };
}());
