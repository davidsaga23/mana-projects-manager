// Shared behaviour for any form that attaches files: a removable list of chosen
// files, a real upload progress bar, and a reset. Used by the create modal on
// tasks.php and the comment form on ticket.php.
//
// Ported from STFC's assets/tickets.js.
//
// Files are tracked in a JS array rather than relying on the native input,
// because <input type="file" multiple> gives no way to remove ONE file from a
// selection — you can only replace the whole set. Picking six files and wanting
// five of them otherwise means starting again.

(function () {
  const MAX_FILES = window.TICKET_MAX_FILES || 5;
  const MAX_BYTES = window.TICKET_MAX_BYTES || 25 * 1024 * 1024;

  function humanSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }

  // " · about 3 min left" — the same estimate as Spell's uploads
  // (assets/board-screen.js), silent for the first two seconds.
  function timeLeft(loaded, total, started) {
    const secs = (Date.now() - started) / 1000;
    if (secs < 2 || loaded <= 0) return '';
    const left = Math.round((total - loaded) / (loaded / secs));
    if (left < 60) return ' · ' + Math.max(left, 1) + ' s left';
    if (left < 3600) return ' · about ' + Math.round(left / 60) + ' min left';
    return ' · about ' + Math.floor(left / 3600) + ' h ' + Math.round((left % 3600) / 60) + ' min left';
  }

  /**
   * Wire up one form. It needs:
   *   input[type=file][data-filepicker]  the native picker (kept as a fallback)
   *   [data-filelist]                    where the chosen files are listed
   *   [data-progress]                    the progress bar wrapper
   *   button[type=submit]                disabled while uploading
   */
  window.initTicketForm = function (form) {
    if (!form || form.dataset.wired) return;
    form.dataset.wired = '1';

    const picker    = form.querySelector('[data-filepicker]');
    const listEl    = form.querySelector('[data-filelist]');
    const progress  = form.querySelector('[data-progress]');
    const bar       = progress ? progress.querySelector('.progress-fill') : null;
    const label     = progress ? progress.querySelector('.progress-label') : null;
    const submitBtn = form.querySelector('button[type=submit]');

    let files = [];

    function render() {
      if (!listEl) return;
      listEl.innerHTML = '';
      files.forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'file-chip';
        const tooBig = f.size > MAX_BYTES;
        if (tooBig) row.classList.add('too-big');

        const name = document.createElement('span');
        name.className = 'file-chip-name';
        name.title = f.name;
        name.textContent = (f.type.startsWith('video/') ? '🎬 ' : f.type === 'application/pdf' ? '📄 ' : '🖼 ') + f.name;

        const size = document.createElement('span');
        size.className = 'file-chip-size';
        size.textContent = humanSize(f.size) + (tooBig ? ', too large' : '');

        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'file-chip-remove';
        rm.title = 'Remove';
        rm.textContent = '×';
        rm.addEventListener('click', () => { files.splice(i, 1); render(); });

        row.appendChild(name);
        row.appendChild(size);
        row.appendChild(rm);
        listEl.appendChild(row);
      });
      if (files.length) {
        const note = document.createElement('div');
        note.className = 'muted small';
        note.textContent = files.length + ' of ' + MAX_FILES + ' file(s) selected';
        listEl.appendChild(note);
      }
    }

    if (picker) {
      picker.addEventListener('change', () => {
        for (const f of picker.files) {
          if (files.length >= MAX_FILES) {
            alert('You can attach at most ' + MAX_FILES + ' files.');
            break;
          }
          // Skip exact duplicates (same name and size) from repeated picks.
          if (files.some(x => x.name === f.name && x.size === f.size)) continue;
          files.push(f);
        }
        // Clear the native input, so re-picking the same file still fires change.
        picker.value = '';
        render();
      });
    }

    // Used when a modal is opened or cancelled, so a previous attempt doesn't
    // linger in the fields.
    form.resetTicketForm = function () {
      form.reset();
      files = [];
      render();
      if (progress) progress.style.display = 'none';
      if (bar) bar.style.width = '0%';
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = submitBtn.dataset.label || submitBtn.textContent;
      }
    };

    form.addEventListener('submit', (e) => {
      const oversized = files.find(f => f.size > MAX_BYTES);
      if (oversized) {
        e.preventDefault();
        alert('"' + oversized.name + '" is ' + humanSize(oversized.size) +
              ', over the ' + humanSize(MAX_BYTES) + ' limit. Remove it or compress it first.');
        return;
      }

      e.preventDefault();

      const fd = new FormData(form);
      // Replace whatever the native input contributed with the managed list.
      fd.delete('attachments[]');
      files.forEach(f => fd.append('attachments[]', f, f.name));

      if (submitBtn) {
        submitBtn.dataset.label = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = files.length ? 'Uploading…' : 'Saving…';
      }
      if (progress) {
        progress.style.display = '';
        if (label) label.textContent = files.length ? 'Preparing upload…' : 'Saving…';
      }

      const xhr = new XMLHttpRequest();
      const started = Date.now();
      xhr.open('POST', form.getAttribute('action') || window.location.href, true);
      // Tells the server this is the uploader, not a browsing click. Without it
      // the server answers with a redirect, XHR silently follows it, and the
      // page it fetches consumes the flash message the user was meant to read —
      // so "3 files attached" or "no shot by that name" would vanish. See
      // is_xhr_request() in includes/tickets.php.
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', (ev) => {
        if (!ev.lengthComputable || !bar) return;
        const pct = Math.round((ev.loaded / ev.total) * 100);
        bar.style.width = pct + '%';
        if (label) {
          label.textContent = pct < 100
            ? 'Uploading… ' + pct + '% (' + humanSize(ev.loaded) + ' of ' + humanSize(ev.total) + ')'
              + timeLeft(ev.loaded, ev.total, started)
            : 'Processing on the server…';
        }
      });

      xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 400) {
          // The server names where to go — a new ticket opens its own page, a
          // task goes back to the board. data-redirect is the fallback for a
          // reply that isn't the JSON we expect.
          let target = form.dataset.redirect || window.location.pathname;
          try {
            const data = JSON.parse(xhr.responseText);
            if (data && data.redirect) target = data.redirect;
          } catch (parseErr) { /* not JSON; the fallback is correct */ }
          window.location.href = target;
        } else {
          if (label) label.textContent = 'Upload failed (HTTP ' + xhr.status + ').';
          alert('Upload failed (HTTP ' + xhr.status + '). Nothing was saved, try again.');
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset.label; }
        }
      });

      xhr.addEventListener('error', () => {
        if (label) label.textContent = 'Network error.';
        alert('Network error, nothing was saved. Check your connection and try again.');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset.label; }
      });

      xhr.send(fd);
    });

    render();
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-ticket-form]').forEach(window.initTicketForm);
  });
})();

// ---------------------------------------------------------------------------
// Attachment lightbox.
//
// Clicking a reference image used to open it in a new tab, which takes you away
// from the ticket you were reading — and a browser cannot be made to open a tab
// in the BACKGROUND from script, so "new tab but stay here" is not something the
// web platform offers. Showing the image full size over the page is the version
// that actually keeps you where you are, and it closes with Escape.
//
// The underlying link is left intact and functional: ctrl-click, middle-click,
// "open in new tab" from the context menu, and a browser with scripting off all
// still go straight to the file. This only intercepts a plain left click.
// ---------------------------------------------------------------------------
(function () {
  let box = null;

  function build() {
    if (box) return box;
    box = document.createElement('div');
    box.className = 'lightbox';
    box.innerHTML =
      '<div class="lightbox-bar">' +
        '<span class="lightbox-name"></span>' +
        '<a class="lightbox-open" target="_blank" rel="noopener">Open in a new tab</a>' +
        '<button type="button" class="lightbox-close" title="Close (Esc)">&times;</button>' +
      '</div>' +
      '<img alt="">';
    document.body.appendChild(box);

    // Clicking the backdrop closes; clicking the image itself does not, so
    // zooming in on a detail doesn't dismiss the thing you're looking at.
    box.addEventListener('click', (e) => {
      if (e.target === box) close();
    });
    box.querySelector('.lightbox-close').addEventListener('click', close);
    return box;
  }

  function open(src, name) {
    const el = build();
    el.querySelector('img').src = src;
    el.querySelector('img').alt = name || '';
    el.querySelector('.lightbox-name').textContent = name || '';
    el.querySelector('.lightbox-open').href = src;
    el.classList.add('is-open');
    document.body.classList.add('lightbox-lock');
  }

  function close() {
    if (!box) return;
    box.classList.remove('is-open');
    document.body.classList.remove('lightbox-lock');
    // Drop the source so a large image isn't held in memory behind the page.
    box.querySelector('img').src = '';
  }

  document.addEventListener('click', (e) => {
    const link = e.target.closest ? e.target.closest('a.att-zoom') : null;
    if (!link) return;
    // Leave every modified click alone — those are deliberate "open elsewhere"
    // gestures and hijacking them is exactly the rudeness this is fixing.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();
    open(link.getAttribute('href'), link.dataset.name || '');
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
})();
