// The Artists screen's one editor.
//
// Every field on a person is edited here rather than inline: that table already
// scrolls sideways, and turning twelve columns into twelve inputs would put the
// last of them somewhere nobody scrolls to.
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }

  function set(id, value) {
    var el = $(id);
    if (el) el.value = value === null || value === undefined ? '' : String(value);
  }

  // Delegated, and the record is read from a data attribute the server already
  // JSON-encoded — so a name with a quote in it needs no escaping into an
  // inline handler.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.artist-edit') : null;
    if (!btn) return;

    var a;
    try { a = JSON.parse(btn.getAttribute('data-artist')); } catch (e) { return; }
    var box = $('artistEditor');
    if (!box) return;

    // orig_name is what the UPDATE matches on, so it must be the name as it is
    // STORED, not whatever gets typed into the box next.
    set('aOrig', a.candidate_name);
    set('aHireId', a.id);
    set('aName', a.candidate_name);
    set('aPosition', a.position);
    set('aSeniority', a.seniority);
    set('aLocation', a.location);
    set('aType', a.project_type);
    set('aEmail', a.email);
    set('aPhone', a.phone);
    set('aReel', a.portfolio_url);
    set('aReelPw', a.portfolio_password);
    set('aSource', a.source);
    set('aRating', a.rating);
    set('aNotes', a.notes);

    var worked = $('aWorked');
    if (worked) worked.checked = !!Number(a.worked_with_us);

    // The rate comes from the OTHER database and is only present for an admin.
    var q = a.quote || {};
    set('aRate', q.quoted_rate);
    set('aUnit', q.rate_unit || 'day');
    set('aCur', q.rate_currency || 'USD');

    var scope = $('artistScope');
    if (scope) {
      scope.textContent = 'Changes here apply to every record of this person, on every '
                        + 'project they have been put up for.';
    }

    box.style.display = 'flex';
    var name = $('aName');
    if (name) name.focus();
  });

  window.closeArtistEditor = function () {
    var box = $('artistEditor');
    if (box) box.style.display = 'none';
  };
}());
