/* The Realm: channels people make themselves (David, 2026-11-13, migration 097).

   "Let's add the option to create new channels (public/private) and ask if it's linked to a specific
   project so they can be nested inside the general channels for each project."

   WHAT IS HERE.
   - The "+" beside CHANNELS (drawn by assets/realm.js, renderSide) opens a small "New channel"
     window: a name; public or private; "Is it for a show?" with the shows that have a channel, or
     "No, studio-wide"; and, for a private channel, who is in it.
   - In the head of an open channel somebody made, a "Channel" button opens its settings: rename it,
     choose who is in a private one, archive it, or leave a private one.
   Where the channels are listed (nested under their show, with a lock when private) is
   assets/realm.js, chanRows().

   ⚠ NOTHING HERE DECIDES WHO SEES WHAT. The server sends only the channels this person may read,
   and checks every change again (includes/realm_made.php). This file only shows and asks.
   Its own file, like assets/realm_react.js, so the map's script stays as it was. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.chat || !R.now || !document.getElementById('chat')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var LOCK = '<svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><rect x="2" y="5.2" width="8" height="5.8" rx="1.2" fill="currentColor"/><path d="M3.9 5.4V3.8a2.1 2.1 0 0 1 4.2 0v1.6" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>';

var INFO = {};   // channel id => what the server last said about it (name, private, members, ...)
R.chanName = function (id) { return INFO[id] ? INFO[id].name : ''; };

function chat() { var s = R.now(); return (s && s.chat) || null; }
function people() { var s = R.now(); return ((s && s.people) || []).slice().sort(function (a, b) { return a.name.localeCompare(b.name); }); }
function shows() { var c = chat(); return c ? c.channels.filter(function (x) { return /^project:\d+$/.test(x.id); }) : []; }
function toast(t) { if (R.toast) R.toast(t); }

/* One window, made the first time it is needed, for both the new form and the settings. */
function dlg() {
  var d = $('chanDlg');
  if (!d) {
    d = document.createElement('dialog'); d.id = 'chanDlg'; d.className = 'rchandlg'; d.setAttribute('aria-labelledby', 'chanTitle');
    document.body.appendChild(d);
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
  }
  return d;
}
function show(d) { if (d.showModal) { if (!d.open) d.showModal(); } else d.setAttribute('open', ''); }
function err(t) { var el = $('chanErr'); if (el) { el.textContent = t || ''; el.hidden = !t; } }

/* The list of people to tick: everybody who lives in the Realm. `on` is who is ticked, `fixed` who
   cannot be unticked (the one who made the channel). */
function picker(on, fixed) {
  return '<input type="search" class="rchanfind" id="chanFind" placeholder="Find somebody" aria-label="Find somebody">' +
    '<div class="rchanppl" id="chanPpl">' + people().map(function (p) {
      var me = !!p.me, tick = me || on.indexOf(p.id) >= 0, lock = me || fixed === p.id;
      return '<label class="rcheck" data-pname="' + esc(p.name.toLowerCase()) + '"><input type="checkbox" value="' + p.id + '"' + (tick ? ' checked' : '') + (lock ? ' disabled' : '') + '> <span>' + esc(me ? p.name + ' (you)' : p.name) + '</span></label>';
    }).join('') + '</div>';
}
function ticked() {
  return [].slice.call(document.querySelectorAll('#chanPpl input:checked')).map(function (i) { return +i.value; });
}

/* ---------- a new channel ---------- */
function openNew() {
  var c = chat();
  if (!c || !c.create) return;
  if (!c.make) { toast('New channels need a database update first. An administrator opens migrate.php once.'); return; }
  /* Opened while a show's channel (or one of its own) is open: that show is chosen already. */
  var open = R.chat.open || '', pre = 0, m = /^project:(\d+)$/.exec(open);
  if (m) pre = +m[1];
  else c.channels.forEach(function (x) { if (x.id === open && x.show) pre = x.show; });
  var d = dlg();
  d.innerHTML = '<h2 id="chanTitle">New channel</h2><form id="chanNew" class="rprofform rchanform" novalidate>' +
    '<label>Name<input name="name" maxlength="60" autocomplete="off" required placeholder="For example: fcm-animation"></label>' +
    '<fieldset class="rchanvis"><legend>Who can see it?</legend>' +
      '<label class="rcheck"><input type="radio" name="private" value="0" checked> <span><b># Public</b><small>Everybody in the Realm can read and write in it.</small></span></label>' +
      '<label class="rcheck"><input type="radio" name="private" value="1"> <span><b>' + LOCK + ' Private</b><small>Only the people you choose. Nobody else sees that it exists.</small></span></label>' +
    '</fieldset>' +
    '<label>Is it for a show?<select name="show"><option value="0">No, studio-wide</option>' +
      shows().map(function (s) { var id = +s.id.slice(8); return '<option value="' + id + '"' + (id === pre ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join('') +
    '</select><small class="rchanhint">A show\'s channel is listed under the show\'s own channel, and only people on that show can see it.</small></label>' +
    '<div class="rchanwho" id="chanWho" hidden><span>Who is in it?</span>' + picker([], 0) + '</div>' +
    '<p class="rchanerr" id="chanErr" role="alert" hidden></p>' +
    '<div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Cancel</button><button class="rbtn rprimary" id="chanGo">Create channel</button></div></form>';
  show(d);
  var f = $('chanNew');
  f.elements['name'].focus();
  [].forEach.call(f.querySelectorAll('input[name=private]'), function (r) {
    r.addEventListener('change', function () { $('chanWho').hidden = f.querySelector('input[name=private]:checked').value !== '1'; });
  });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = f.elements['name'].value.trim(), priv = f.querySelector('input[name=private]:checked').value === '1';
    if (!name) { err('Give the channel a name.'); f.elements['name'].focus(); return; }
    $('chanGo').disabled = true;
    post({channel:'new', name:name, private:priv ? '1' : '0', show:f.elements['show'].value, members:priv ? ticked().join(',') : ''}, function (j) {
      $('chanGo').disabled = false;
      if (j.error || !j.id) { err(j.error || 'The channel could not be made.'); return; }
      d.close();
      if (j.info) INFO[j.id] = j.info;
      if (R.openChat) R.openChat(j.id);
    }, function () { $('chanGo').disabled = false; });
  });
}

/* ---------- the settings of an open channel somebody made ---------- */
function showName(c) { var s = shows().filter(function (x) { return x.id === 'project:' + c.show; })[0]; return s ? s.name : ''; }
function openSettings() {
  var id = R.chat.open, c = INFO[id];
  if (!c) return;
  var d = dlg(), where = c.show ? 'For ' + (showName(c) || 'a show') : 'Studio-wide', mine = c.mine ? ' You made it.' : '';
  var h = '<h2 id="chanTitle" class="rchanhead">' + (c.private ? LOCK : '#') + ' ' + esc(c.name) + '</h2><form id="chanSet" class="rprofform rchanform" novalidate>' +
    '<p class="rchanabout">' + (c.private ? 'Private: only its members see it.' : 'Public: everybody in the Realm reads and writes in it.') + ' ' + esc(where) + '.' + esc(mine) + '</p>';
  if (c.manage) h += '<label>Name<span class="rchanrow"><input name="name" maxlength="60" autocomplete="off" value="' + esc(c.name) + '"><button type="button" class="rbtn" data-chando="rename">Save name</button></span></label>';
  /* PUBLIC OR PRIVATE, changeable afterwards (David, 2026-11-25: "can we also add the option to
     make it public/private"). */
  if (c.manage) h += '<div class="rchanrow rchanpriv"><span>' + (c.private ? 'Private' : 'Public') + '</span><button type="button" class="rbtn" data-chando="privacy">' + (c.private ? 'Make it public' : 'Make it private') + '</button></div>';
  if (c.private) {
    var ids = c.members.map(function (m) { return m.id; });
    if (c.manage) {
      /* The one who made it cannot be taken out (the server refuses it too). */
      h += '<div class="rchanwho"><span>Members (' + ids.length + ')</span>' + picker(ids, c.by) + '<button type="button" class="rbtn" data-chando="members">Save members</button></div>';
    } else {
      h += '<div class="rchanwho"><span>Members (' + ids.length + ')</span><ul class="rchanlist">' + c.members.map(function (m) { return '<li>' + esc(m.name) + '</li>'; }).join('') + '</ul></div>';
    }
  }
  h += '<p class="rchanerr" id="chanErr" role="alert" hidden></p><div class="rdacts rchanacts">' +
    (c.private && !c.mine ? '<button type="button" class="rbtn" data-chando="leave">Leave channel</button>' : '') +
    (c.manage ? '<button type="button" class="rbtn rdanger" data-chando="archive">Archive channel</button>' : '') +
    '<span class="rgrow"></span><button type="button" class="rbtn rprimary" data-dlgclose="1">Close</button></div></form>';
  d.innerHTML = h;
  show(d);
  $('chanSet').addEventListener('submit', function (e) { e.preventDefault(); });
}
function settingsDo(what, btn) {
  var id = R.chat.open, c = INFO[id], f = $('chanSet');
  if (!c || !f) return;
  var fields = {channel:what, id:id};
  if (what === 'rename') fields.name = f.elements['name'].value.trim();
  if (what === 'members') {
    var now = ticked(), was = c.members.map(function (m) { return m.id; });
    fields.add = now.filter(function (x) { return was.indexOf(x) < 0; }).join(',');
    /* A locked box (me, the one who made it) still counts as ticked, so it is never sent as removed. */
    fields.remove = was.filter(function (x) { return now.indexOf(x) < 0; }).join(',');
  }
  if (what === 'privacy') {
    fields.private = c.private ? '0' : '1';
    if (!window.confirm(c.private
        ? 'Make "' + c.name + '" public? Everybody in the Realm will see it and its past messages.'
        : 'Make "' + c.name + '" private? Only you and the person who made it stay in it at first: add the others under Members. Everybody else stops seeing it.')) return;
  }
  if (what === 'archive' && !window.confirm('Archive "' + c.name + '"? It leaves the channel list and nobody can write in it any more. Its messages are kept and can still be found with the search.')) return;
  if (what === 'leave' && !window.confirm('Leave "' + c.name + '"? It is private, so you will no longer see it. Somebody in it can add you back.')) return;
  btn.disabled = true;
  post(fields, function (j) {
    btn.disabled = false;
    if (j.error) { err(j.error); return; }
    if (what === 'archive' || what === 'leave') {
      dlg().close(); delete INFO[id];
      if (R.closeChat && R.chat.open === id) R.closeChat();
      toast(what === 'archive' ? 'Archived. Its messages are kept and can still be searched.' : 'You left the channel.');
      return;
    }
    if (j.info) { INFO[id] = j.info; head(id); }
    openSettings();
    toast(what === 'rename' ? 'Renamed.' : what === 'privacy' ? (fields.private === '1' ? 'Now private.' : 'Now public.') : 'Members saved.');
  }, function () { btn.disabled = false; });
}

/* Ask the server; the answer always carries the channel list again. */
function post(fields, done, fail) {
  var f = new URLSearchParams();
  Object.keys(fields).forEach(function (k) { f.append(k, fields[k]); });
  /* ⚠ THREE DIFFERENT FAILURES, THREE MESSAGES (David, 2026-11-25: "That could not be saved" on
     Save members). The server not answering, the server answering something that is not JSON
     (busy, or a PHP warning printed first), and the page stumbling while redrawing AFTER a save
     that worked used to share one sentence, so a saved change could read as a failed one. */
  fetch(R.api, {method:'POST', body:f, credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) {
          if (window.console) console.error('Realm channel save: not JSON (HTTP ' + r.status + ')', t.slice(0, 500));
          return {ok: false, error: r.status >= 500 || r.status === 0
            ? 'The server is busy (HTTP ' + r.status + '). Try again in a moment.'
            : 'The server gave an answer the page could not read (HTTP ' + r.status + '). Try again, and tell David if it keeps happening.'};
        }
      });
    }, function () { return {ok: false, error: 'The server could not be reached. Check the connection and try again.'}; })
    .then(function (j) {
      if (!j || !j.ok) { err((j && j.error) || 'That could not be saved. Try again in a moment.'); if (fail) fail(); return; }
      try {
        if (j.chat && R.takeChat) R.takeChat(j.chat);
        done(j);
      } catch (e) {
        /* Saved. Only the redraw failed: say so, and leave the details for whoever debugs it. */
        if (window.console) console.error('Realm channel: saved, but redrawing failed', e);
        if (fail) fail();
        toast('Saved. Reopen the channel to see the change.');
      }
    });
}

/* ---------- the head of the open conversation ---------- */
function head(id) {
  var btn = $('chanSetBtn'), c = INFO[id], title = $('chatTitle');
  if (!btn) {
    var close = document.querySelector('#chat [data-chatclose]');
    if (!close) return;
    btn = document.createElement('button'); btn.type = 'button'; btn.id = 'chanSetBtn'; btn.className = 'rbtn rsmall'; btn.setAttribute('data-chanset', '1');
    /* A real gear and the word, not an icon alone (David, 2026-11-25, looking for it: "I don't see
       any gear"). The first drawing, a ring with eight spokes, read as a sun. */
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px;margin-right:4px"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings';
    btn.title = 'Channel settings: name, members, archiving'; btn.setAttribute('aria-label', 'Channel settings');
    close.parentNode.insertBefore(btn, close);
  }
  var on = !!c && id === R.chat.open && !R.chat.thread;
  btn.hidden = !on;
  /* The lock in the title, and the right name after a rename (assets/realm.js wrote the title from the list). */
  if (on && title) {
    var want = (c.private ? LOCK + ' ' : '') + esc(c.name) + (c.archived ? ' <small class="rchanarch">archived</small>' : '');
    if (title.getAttribute('data-made') !== want) { title.innerHTML = want; title.setAttribute('data-made', want); }
  } else if (title) title.removeAttribute('data-made');
}

/* Every answer about the open conversation (assets/realm.js, chatTake). assets/realm_react.js uses the
   same hook, so whatever was there is kept and called first. */
var before = R.onChat;
R.onChat = function (j) {
  if (before) before(j);
  if (!j || !j.ok || j.channel !== R.chat.open) return;
  if (j.chan) INFO[j.channel] = j.chan;
  head(j.channel);
};

/* Clicks are taken before assets/realm.js sees them (capture), and only ours are stopped. */
document.addEventListener('click', function (e) {
  var t = e.target && e.target.closest ? e.target : (e.target && e.target.parentNode), b;
  if (!t || !t.closest) return;
  if (t.closest('[data-chanew]')) { e.stopPropagation(); openNew(); return; }
  if (t.closest('[data-chanset]')) { e.stopPropagation(); openSettings(); return; }
  if ((b = t.closest('[data-chando]'))) { e.stopPropagation(); settingsDo(b.getAttribute('data-chando'), b); return; }
  /* Another conversation opened from the list: the button belongs to the old one until its answer. */
  if (t.closest('[data-chat],[data-raven],[data-threads],[data-thread],[data-found]')) setTimeout(function () { head(R.chat.open); }, 0);
}, true);
/* Typing in "Find somebody" narrows the list of people. */
document.addEventListener('input', function (e) {
  if (!e.target || e.target.id !== 'chanFind') return;
  var q = e.target.value.trim().toLowerCase();
  [].forEach.call(document.querySelectorAll('#chanPpl [data-pname]'), function (l) { l.hidden = q !== '' && l.getAttribute('data-pname').indexOf(q) < 0; });
});
})();
