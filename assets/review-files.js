// The files going with a review: pasted, picked or dropped, as thumbnails with
// an × each.
//
// Moved out of assets/board-screen.js (2026-10-29) because two boxes outside
// Spell's panel now take a review too: the review viewer's (assets/review-viewer.js),
// on Spell and on the Reviews screen, and Reviews has no task panel for
// board-screen.js to wake up for. One copy, keyed on the same classes it always
// was: a form.board-review-form, its textarea.board-review-box, its
// input[name="attachments[]"] and its .board-review-thumbs strip.
(function () {
  'use strict';

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

  // For the viewer, which posts by hand and has to empty the list after.
  window.animaReviewFiles = {
    files: function (form) { return (form && form._reviewFiles) || []; },
    clear: function (form) { if (form) { form._reviewFiles = []; reviewSync(form); } }
  };
}());
