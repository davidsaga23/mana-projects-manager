// Drag-to-reorder for the wizard's pipeline step table.
//
// Only the handle cell is draggable, so text in the name field stays selectable.
// Dropping submits the form immediately: a reorder that sits unsaved in the page
// is exactly the kind of silent loss this app tries not to have. The ↑ ↓ buttons
// remain the keyboard path and the fallback when this script doesn't run.

(function () {
  'use strict';

  var tbody = document.getElementById('step-rows');
  var orderField = document.getElementById('step-order');
  var submitButton = document.getElementById('reorder-submit');
  if (!tbody || !orderField || !submitButton) return;

  var dragging = null;

  tbody.addEventListener('dragstart', function (e) {
    var handle = e.target.closest('.drag-handle[draggable]');
    if (!handle) return;
    dragging = handle.closest('tr');
    if (!dragging) return;
    dragging.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag unless some data is set.
    e.dataTransfer.setData('text/plain', dragging.getAttribute('data-id') || '');
  });

  tbody.addEventListener('dragover', function (e) {
    if (!dragging) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    var over = e.target.closest('tr');
    if (!over || over === dragging || over.parentNode !== tbody) return;

    // Past the halfway point of the hovered row, insert below it instead of above.
    var box = over.getBoundingClientRect();
    var below = (e.clientY - box.top) > box.height / 2;
    tbody.insertBefore(dragging, below ? over.nextSibling : over);
  });

  tbody.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!dragging) return;
    finishDrag();
    saveOrder();
  });

  // Covers dropping outside the table, or pressing Escape mid-drag.
  tbody.addEventListener('dragend', function () {
    if (dragging) finishDrag();
  });

  function finishDrag() {
    dragging.classList.remove('dragging');
    dragging = null;
  }

  function saveOrder() {
    var ids = [];
    var rows = tbody.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) {
      ids.push(rows[i].getAttribute('data-id'));
    }
    orderField.value = ids.join(',');
    submitButton.click();
  }
})();

// "Select all" in an asset group — retyping fifty pasted assets shouldn't mean
// fifty clicks. Each toggle names the container it controls.
(function () {
  'use strict';

  var toggles = document.querySelectorAll('[data-check-all]');
  for (var i = 0; i < toggles.length; i++) {
    toggles[i].addEventListener('change', function (e) {
      var group = document.getElementById(e.target.getAttribute('data-check-all'));
      if (!group) return;
      var boxes = group.querySelectorAll('input[name="sel[]"]');
      for (var j = 0; j < boxes.length; j++) boxes[j].checked = e.target.checked;
    });
  }
})();

// Picking a remembered step name fills in the settings it had last time.
// Typing a brand new name leaves the fields alone.
(function () {
  'use strict';

  var dataEl = document.getElementById('step-library-data');
  var nameEl = document.getElementById('new-step-name');
  var appliesEl = document.getElementById('new-step-applies-to');
  var secondsEl = document.getElementById('new-step-seconds');
  if (!dataEl || !nameEl || !appliesEl || !secondsEl) return;

  var library;
  try {
    library = JSON.parse(dataEl.textContent || '{}');
  } catch (err) {
    return;
  }

  nameEl.addEventListener('input', function () {
    var match = library[nameEl.value.trim()];
    if (!match) return;
    appliesEl.value = match.applies_to;
    secondsEl.checked = match.tracks_seconds === 1;
  });
})();
