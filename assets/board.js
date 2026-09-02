// The tasks board: drag between columns, the filters, and the quick-edit modal.
// Ported from the inline script in STFC's kanban.php.
//
// Reads three globals set by tasks.php: CARDS, PROJECT_URL, CAN_EDIT.
//
// Dates are compared as 'YYYY-MM-DD' STRINGS throughout, never through
// new Date(). That format is parsed as UTC and read back local, which walks
// dates backwards in a UTC−6 timezone — the bug that cost STFC two days per
// edit cycle on the timeline (START_HERE, lesson 3). String comparison on that
// format sorts correctly and cannot drift.

(function () {
  const cards = window.CARDS || [];
  const boardUrl = window.PROJECT_URL || 'index.php';
  const canEdit = window.CAN_EDIT === true;

  // --- local dates, without Date() parsing ---------------------------------
  function todayStr() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  function plusDaysStr(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);   // walks the LOCAL calendar, so no UTC shift
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  // --- drag and drop --------------------------------------------------------
  let dragging = false;

  window.dragCard = function (e, id) {
    if (!canEdit) { e.preventDefault(); return; }
    dragging = true;
    e.dataTransfer.setData('text/plain', String(id));
    e.dataTransfer.effectAllowed = 'move';
  };

  document.addEventListener('dragend', () => {
    // A short delay, so the click that ends a drag doesn't also open the card.
    setTimeout(() => { dragging = false; }, 100);
  });

  window.dropCard = async function (e, statusId) {
    e.preventDefault();
    e.stopPropagation();
    if (!canEdit) return;

    const id = e.dataTransfer.getData('text/plain');
    if (!id) return;

    // Captured BEFORE the await: e.currentTarget is null once the handler yields.
    const target = e.currentTarget;

    const body = new URLSearchParams({ id: id, status_id: String(statusId) });
    try {
      const res = await fetch('tasks_api.php?action=move', { method: 'POST', body: body });
      const data = await res.json();
      if (!data.ok) { alert(data.error || 'The card could not be moved.'); return; }

      const card = document.querySelector('.board-card[data-id="' + id + '"]');
      if (card && target) {
        const from = card.parentElement;
        target.appendChild(card);
        // A card that just moved into a "done" column may be off-board no longer.
        const flag = card.querySelector('.off-board');
        if (flag) flag.remove();
        updateCount(from);
        updateCount(target);
      }
    } catch (err) {
      alert('Network error, the card was NOT moved: ' + err.message);
    }
  };

  function updateCount(container) {
    if (!container) return;
    const col = container.closest('.board-col');
    if (!col) return;
    const visible = container.querySelectorAll('.board-card:not(.filtered-out)').length;
    const badge = col.querySelector('.board-col-count');
    if (badge) badge.textContent = visible;
  }

  // --- filters --------------------------------------------------------------
  function applyFilters() {
    const typeEl = document.getElementById('filterType');
    const crewEl = document.getElementById('filterAssignee');
    const dueEl  = document.getElementById('filterDue');
    if (!typeEl || !crewEl || !dueEl) return;

    const type = typeEl.value, crew = crewEl.value, due = dueEl.value;
    const today = todayStr();
    const weekEnd = plusDaysStr(7);

    document.querySelectorAll('.board-card').forEach(card => {
      let show = true;

      if (type !== 'all' && card.dataset.type !== type) show = false;

      if (show && crew !== 'all') {
        show = (card.dataset.crew === crew);
      }

      if (show && due !== 'all') {
        const d = card.dataset.due;
        if (due === 'overdue')      show = !!d && d < today;
        else if (due === 'today')   show = d === today;
        else if (due === 'week')    show = !!d && d >= today && d <= weekEnd;
        else if (due === 'nodate')  show = !d;
      }

      card.classList.toggle('filtered-out', !show);
    });

    document.querySelectorAll('.board-cards').forEach(updateCount);
  }

  ['filterType', 'filterAssignee', 'filterDue'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', applyFilters);
  });

  // --- create modal ---------------------------------------------------------
  window.openCreate = function (type) {
    const modal = document.getElementById('createModal');
    if (!modal) return;
    const form = document.getElementById('createForm');
    if (form && form.resetTicketForm) form.resetTicketForm();

    document.getElementById('createType').value = type;
    document.getElementById('createTitle').textContent = type === 'ticket' ? 'New ticket' : 'New task';
    document.getElementById('createTitleField').placeholder = type === 'ticket'
      ? 'What is the problem?' : 'What needs doing?';
    document.getElementById('createDesc').placeholder = type === 'ticket'
      ? 'What happened, what you expected, how to see it…' : 'Details, links, notes…';

    document.querySelectorAll('.ticket-only').forEach(el => {
      el.style.display = type === 'ticket' ? '' : 'none';
    });

    // A ticket must be owned by somebody; a plain task can sit unassigned.
    const crewSel = document.getElementById('createCrew');
    if (crewSel) { crewSel.required = (type === 'ticket'); crewSel.setCustomValidity(''); }

    const err = document.getElementById('createError');
    if (err) err.style.display = 'none';
    modal.style.display = 'flex';
  };

  window.closeCreate = function () {
    const form = document.getElementById('createForm');
    if (form && form.resetTicketForm) form.resetTicketForm();
    const err = document.getElementById('createError');
    if (err) err.style.display = 'none';
    const modal = document.getElementById('createModal');
    if (modal) modal.style.display = 'none';
  };

  // A visible in-form message rather than the browser's validation tooltip,
  // which is easy to miss inside a modal. Capture phase, so this runs BEFORE the
  // uploader's submit handler and can stop it.
  const createForm = document.getElementById('createForm');
  if (createForm) {
    createForm.addEventListener('submit', function (e) {
      const crewSel = document.getElementById('createCrew');
      const err = document.getElementById('createError');
      const isTicket = document.getElementById('createType').value === 'ticket';
      if (isTicket && crewSel && !crewSel.value) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (err) {
          err.textContent = 'Choose who owns this ticket before creating it, an unowned ticket notifies nobody.';
          err.style.display = '';
        }
        crewSel.focus();
        return;
      }
      if (err) err.style.display = 'none';
    }, true);
  }

  // --- opening a card -------------------------------------------------------
  // A ticket opens its own page, because that is where the thread and the
  // attachments live. A plain task gets the lightweight modal.
  window.openCard = function (id) {
    if (dragging) return;
    const t = cards.find(x => String(x.id) === String(id));
    if (!t) { window.location.href = 'ticket.php?id=' + id; return; }
    if (t.type === 'ticket' || !canEdit) { window.location.href = 'ticket.php?id=' + id; return; }

    document.getElementById('editId').value = t.id;
    document.getElementById('editTitle').value = t.title;
    document.getElementById('editDesc').value = t.description || '';
    document.getElementById('editStatus').value = t.status_id || '';
    document.getElementById('editPriority').value = t.priority;
    document.getElementById('editCrew').value = t.assigned_crew_id || '';
    document.getElementById('editDue').value = t.due_date || '';
    document.getElementById('editOpenLink').href = 'ticket.php?id=' + t.id;
    const err = document.getElementById('editError');
    if (err) err.style.display = 'none';
    document.getElementById('editModal').style.display = 'flex';
  };

  const editForm = document.getElementById('editForm');
  if (editForm) {
    editForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = document.getElementById('editError');
      const body = new URLSearchParams({
        id: document.getElementById('editId').value,
        title: document.getElementById('editTitle').value,
        description: document.getElementById('editDesc').value,
        status_id: document.getElementById('editStatus').value,
        priority: document.getElementById('editPriority').value,
        assigned_crew_id: document.getElementById('editCrew').value,
        due_date: document.getElementById('editDue').value,
      });
      try {
        const res = await fetch('tasks_api.php?action=update', { method: 'POST', body: body });
        const data = await res.json();
        if (data.ok) {
          window.location.href = boardUrl;
        } else if (err) {
          err.textContent = data.error || 'That could not be saved.';
          err.style.display = '';
        }
      } catch (netErr) {
        if (err) {
          err.textContent = 'Network error, nothing was saved. ' + netErr.message;
          err.style.display = '';
        }
      }
    });
  }

  // Escape closes whichever modal is open, and clicking the backdrop does too.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal').forEach(m => { m.style.display = 'none'; });
  });
  document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('mousedown', (e) => {
      if (e.target === m) m.style.display = 'none';
    });
  });
})();
