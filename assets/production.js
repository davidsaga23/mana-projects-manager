// The tracking grid: setting a cell's status, filtering by group, and opening a
// donut segment to see which items it stands for.
//
// Reads globals set by production.php: GRID_BY_STATUS, GRID_STATUS_NAMES,
// GRID_PROJECT, GRID_CAN_EDIT. All are `window.` assignments there — a top-level
// `const` does not become a window property, which cost the tasks board a day.

(function () {
  const canEdit = window.GRID_CAN_EDIT === true;
  const byStatus = window.GRID_BY_STATUS || {};
  const statusNames = window.GRID_STATUS_NAMES || {};

  // --- the status dropdown --------------------------------------------------
  const menu = document.getElementById('statusMenu');
  let openCell = null;

  function closeMenu() {
    if (!menu) return;
    menu.hidden = true;
    openCell = null;
  }

  function openMenu(cell) {
    if (!menu) return;
    openCell = cell;

    // Positioned against the document, not the cell's offset parent — the grid
    // scrolls horizontally inside its own container, so anything positioned
    // relative to that would drift as soon as the table was scrolled.
    const r = cell.getBoundingClientRect();
    menu.hidden = false;
    const mh = menu.offsetHeight;
    const below = window.innerHeight - r.bottom;
    // Flip above the cell when there isn't room below, so rows near the bottom
    // of a long grid don't open a menu off-screen.
    const top = (below < mh + 12 && r.top > mh)
      ? r.top + window.scrollY - mh - 4
      : r.bottom + window.scrollY + 4;

    menu.style.top = top + 'px';
    menu.style.left = Math.min(r.left + window.scrollX, window.scrollX + window.innerWidth - menu.offsetWidth - 12) + 'px';

    // Mark which option is current, so the menu says where you are as well as
    // where you can go.
    const current = cell.dataset.status || '';
    menu.querySelectorAll('.status-opt').forEach(function (opt) {
      opt.classList.toggle('is-current', (opt.dataset.status || '') === current);
    });
  }

  document.addEventListener('click', function (e) {
    const cell = e.target.closest ? e.target.closest('.cell-chip.is-editable') : null;
    if (cell) {
      e.stopPropagation();
      if (openCell === cell) { closeMenu(); return; }
      openMenu(cell);
      return;
    }
    if (menu && !menu.hidden && !(e.target.closest && e.target.closest('#statusMenu'))) closeMenu();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeMenu(); }
  });

  // Reposition rather than leaving a menu floating over the wrong cell.
  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);

  if (menu && canEdit) {
    menu.addEventListener('click', async function (e) {
      const opt = e.target.closest('.status-opt');
      if (!opt || !openCell) return;
      const cell = openCell;
      const statusId = opt.dataset.status || '';
      closeMenu();

      const before = { bg: cell.style.background, status: cell.dataset.status || '' };
      cell.classList.add('is-saving');

      const body = new URLSearchParams({
        project_id: String(window.GRID_PROJECT),
        step_id: cell.dataset.step,
        item_key: cell.dataset.item,
        status_id: statusId
      });

      try {
        const res = await fetch('production_api.php', { method: 'POST', body: body });
        const data = await res.json();
        if (!data.ok) {
          alert(data.error || 'That could not be saved.');
          cell.classList.remove('is-saving');
          return;
        }
        cell.dataset.status = statusId;
        cell.style.background = data.color || '';
        cell.classList.toggle('cell-none', !data.color);
        cell.title = data.name || 'Nothing recorded';
        cell.classList.remove('is-saving');
        cell.classList.add('just-saved');
        setTimeout(function () { cell.classList.remove('just-saved'); }, 900);

        // The counters, the donuts and the "ready" flags are all derived from
        // every cell on the page. Rather than reimplement that arithmetic here
        // and risk it disagreeing with the server's, mark the page stale and say
        // so — a wrong total is worse than an out-of-date one.
        markStale();
      } catch (err) {
        alert('Network error — nothing was saved. ' + err.message);
        cell.style.background = before.bg;
        cell.dataset.status = before.status;
        cell.classList.remove('is-saving');
      }
    });
  }

  let staleBanner = null;
  function markStale() {
    if (staleBanner) return;
    staleBanner = document.createElement('div');
    staleBanner.className = 'flash flash-warn stale-banner';
    staleBanner.innerHTML =
      'Cells changed. The totals, the charts and the "ready" marks still show the figures from ' +
      'when the page loaded. <a href="#" id="reloadGrid">Refresh to bring them up to date</a>.';
    const head = document.querySelector('.board-head');
    if (head && head.parentNode) head.parentNode.insertBefore(staleBanner, head.nextSibling);
    const link = document.getElementById('reloadGrid');
    if (link) link.addEventListener('click', function (e) { e.preventDefault(); window.location.reload(); });
  }

  // --- group filter ---------------------------------------------------------
  const filter = document.getElementById('groupFilter');
  if (filter) {
    filter.addEventListener('change', function () {
      const val = this.value;
      document.querySelectorAll('.track-group, .track-row').forEach(function (row) {
        row.style.display = (val === 'all' || row.dataset.group === val) ? '' : 'none';
      });
      document.querySelectorAll('.donut-card').forEach(function (card) {
        card.style.display = (val === 'all' || card.dataset.group === val) ? '' : 'none';
      });
    });
  }

  // --- donut segment -> which items ----------------------------------------
  // A segment reading "7 On Hold" is useless until it says which seven. Same
  // reasoning as the unmatched-names report: a number you cannot open is a
  // number nobody can act on.
  const modal = document.getElementById('sliceModal');
  document.querySelectorAll('.donut-card').forEach(function (card) {
    card.addEventListener('click', function (e) {
      const seg = e.target.closest('.donut-seg');
      if (!seg || !modal) return;
      const group = card.dataset.group;
      const key = seg.dataset.status || 'none';
      const entries = (byStatus[group] || {})[key] || [];
      const label = key === 'none' ? 'Not recorded' : (statusNames[key] || 'Status');

      document.getElementById('sliceTitle').textContent =
        label + ' — ' + group + ' (' + entries.length + ')';

      const body = document.getElementById('sliceBody');
      body.innerHTML = '';
      if (!entries.length) {
        body.innerHTML = '<p class="muted small">Nothing in this state.</p>';
      } else {
        entries.forEach(function (en) {
          const row = document.createElement('div');
          row.className = 'slice-row';
          const item = document.createElement('strong');
          item.textContent = en.item;
          const step = document.createElement('span');
          step.className = 'muted small';
          step.textContent = en.step;
          row.appendChild(item);
          row.appendChild(step);
          body.appendChild(row);
        });
      }
      modal.hidden = false;
    });
  });

  if (modal) {
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.hidden = true; });
  }
})();
