/* The Realm: Slack's message actions (David, 2026-11-13, migration 096).

   David sent a screenshot of the small bar Slack floats over a message and said: "let's add these
   same features from slack so the transition is smoother". The Realm is meant to replace Slack for
   the studio, which only happens if it feels familiar. The rules are in includes/realm_msgact.php and
   are checked there; this file only offers them. Its own file, like assets/realm_react.js.

   WHAT IS HERE. The bar itself is drawn with each message by assets/realm.js (barHTML); its quick
   reactions and "add reaction" are worked by assets/realm_react.js, and "reply in thread" by
   assets/realm.js. This file does the rest of it:
   - FORWARD: pick a channel or raven I may write in, add a note if I like, send. The copy shows
     "Forwarded from <place> · <who>" above the original.
   - SAVE FOR LATER / REMIND ME: a menu with "Save for later" and four reminder times. A reminder rings
     Anima's bell; if the Realm is open it also shows a notice here. "Saved" in the side list opens my
     list. Agreed with David: "Save + reminders".
   - MORE (⋮): copy a link to the message, mark unread, and for my own messages edit and delete.
     Agreed with David: "Edit & delete my own, Copy link to message, Mark unread" (no pinning).
   - KEYBOARD: a message can be focused with Tab, the arrow keys move between messages, and Tab from
     a focused message goes into its bar. Escape closes a menu.
   - TOUCH: no pointer to hover with, so tapping a message shows its bar.
   - A LINK TO A MESSAGE (realm.php?msg=<id>, from "Copy link" or a reminder in the bell) opens the
     Realm at that message.

   Nothing that needs migration 096 is offered before it runs: the server says so in ST.chat.acts,
   and the bar leaves out forward and save. Copy link and mark unread need no migration. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.chat || !R.chatTake || !R.msgHTML || !document.getElementById('chatMsgs')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var CH = R.chat;
var SAVED = {};      // message id => reminder in words ('' none), for the conversation on screen
var EDIT = 0;        // the message being edited, which the 3-second poll must not redraw
function st() { return R.st ? R.st() : null; }
function acts() { var s = st(); return !!(s && s.chat && s.chat.acts); }
function toast(msg, chat) { if (R.toast) R.toast(msg, chat); }
function post(fields) {
  var f = new URLSearchParams(); Object.keys(fields).forEach(function (k) { f.append(k, fields[k]); });
  return fetch(R.api, {method:'POST', body:f, credentials:'same-origin', headers:{'Accept':'application/json'}}).then(function (r) { return r.json(); });
}
/* The fields every request about the open conversation carries, so its answer is drawn like a poll's. */
function here(fields) {
  fields.after = CH.last; fields.from = CH.first;
  if (CH.thread) fields.thread = CH.thread;
  if (CH.keep) fields.keep = 1;
  return fields;
}
function msgEl(id) { var box = $('chatMsgs'); return box ? box.querySelector('.rmsg[data-msg="'+(+id)+'"]') : null; }

/* ---------- answers about the open conversation (assets/realm.js, chatTake) ---------- */
R.onActs = function (j) {
  if (!j || !j.ok || j.channel !== CH.open) return;
  /* EDITED AND DELETED MESSAGES. Every one on screen comes with every answer; only one whose `v`
     changed is drawn again. The one I am editing is left alone until I finish. */
  (j.changed || []).forEach(function (m) {
    var el = msgEl(m.id); if (!el || el.getAttribute('data-v') === (m.v || '') || m.id === EDIT) return;
    var tmp = document.createElement('div'); tmp.innerHTML = R.msgHTML(m);
    var fresh = tmp.querySelector('.rmsg'); if (!fresh) return;
    var focused = document.activeElement === el;
    el.parentNode.replaceChild(fresh, el);
    if (focused) fresh.focus();
  });
  if (j.saved) { SAVED = j.saved; }
  paintSaved();
};
function paintSaved() {
  var box = $('chatMsgs'); if (!box) return;
  Array.prototype.forEach.call(box.querySelectorAll('.rmsg[data-msg]'), function (el) {
    var id = el.getAttribute('data-msg'), on = Object.prototype.hasOwnProperty.call(SAVED, id), mark = el.querySelector('[data-savedmark]');
    el.classList.toggle('is-saved', on);
    var words = on ? (SAVED[id] ? 'Saved · '+SAVED[id] : 'Saved') : '';
    if (mark && mark.textContent !== words) mark.textContent = words;
  });
}

/* ---------- the little menus (save, more) ---------- */
var menu = null, menuFor = 0;
function menuClose() { if (menu) menu.hidden = true; menuFor = 0; }
function menuOpen(btn, id, items) {
  if (!menu) {
    menu = document.createElement('div'); menu.className = 'rmenu'; menu.setAttribute('role', 'menu'); menu.hidden = true;
    document.body.appendChild(menu);
  }
  menuFor = id;
  menu.innerHTML = items.map(function (x) {
    return x.sep ? '<hr>' : '<button type="button" role="menuitem" data-menu="'+esc(x.k)+'"'+(x.warn?' class="is-warn"':'')+'>'+esc(x.t)+'</button>';
  }).join('');
  menu.hidden = false;
  var b = btn.getBoundingClientRect(), w = menu.offsetWidth, h = menu.offsetHeight;
  var x = Math.max(8, Math.min(window.innerWidth-w-8, b.right-w)), y = b.bottom+4;
  if (y+h > window.innerHeight-8) y = Math.max(8, b.top-h-4);
  menu.style.left = x+'px'; menu.style.top = y+'px';
  var first = menu.querySelector('button'); if (first) first.focus();
}
function saveMenu(btn, id) {
  var s = st(), times = (s && s.chat && s.chat.remind) || {}, saved = Object.prototype.hasOwnProperty.call(SAVED, String(id));
  var items = [{k:'save:', t: saved ? 'Saved, no reminder' : 'Save for later'}, {sep:1}];
  Object.keys(times).forEach(function (k) { items.push({k:'save:'+k, t:'Remind me: '+times[k].charAt(0).toLowerCase()+times[k].slice(1)}); });
  if (saved) items.push({sep:1}, {k:'unsave', t:'Remove from Saved'});
  menuOpen(btn, id, items);
}
function moreMenu(btn, id) {
  var el = msgEl(id), items = [{k:'link', t:'Copy link to message'}];
  if (!CH.thread) items.push({k:'unread', t:'Mark unread'});
  if (el && el.classList.contains('is-mine') && acts()) {
    items.push({sep:1});
    if (!el.hasAttribute('data-fwd')) items.push({k:'edit', t:'Edit message'});
    items.push({k:'delete', t:'Delete message…', warn:1});
  }
  menuOpen(btn, id, items);
}
function menuPick(k, id) {
  menuClose();
  if (k.indexOf('save:') === 0) return save(id, k.slice(5));
  if (k === 'unsave') return unsave(id);
  if (k === 'link') return copyLink(id);
  if (k === 'unread') return markUnread(id);
  if (k === 'edit') return editStart(id);
  if (k === 'delete') return askDelete(id);
}

/* ---------- save for later, and reminders ---------- */
function save(id, when) {
  post({save_msg:id, remind:when}).then(function (j) {
    if (!j || j.error || !j.ok) { toast((j && j.error) || 'That could not be saved. Try again.'); return; }
    SAVED[String(id)] = j.remind || ''; paintSaved();
    toast(j.remind ? 'Saved. Anima\'s bell will remind you '+j.remind+'.' : 'Saved for later. Find it under Saved in the side list.');
  }).catch(function () { toast('That could not be saved. Try again.'); });
}
function unsave(id) {
  post({unsave_msg:id}).then(function (j) {
    if (!j || !j.ok) { toast((j && j.error) || 'That could not be changed. Try again.'); return; }
    delete SAVED[String(id)]; paintSaved();
    if (dlg && dlg.open && dlg.getAttribute('data-kind') === 'saved') openSaved();
  }).catch(function () { toast('That could not be changed. Try again.'); });
}
/* A reminder rang on the heartbeat: a notice that opens the message, since this page has no bell. */
R.onReminders = function (list) {
  (list || []).forEach(function (r) {
    var t = document.createElement('div'); t.className = 'rtoast is-link'; t.setAttribute('role', 'button'); t.tabIndex = 0;
    t.setAttribute('data-gomsg', r.id);
    t.textContent = 'Reminder: '+(r.name === 'You' ? 'your message' : r.name+'\'s message')+'. Click here to see it.';
    (R.toastHome ? R.toastHome() : document.body).appendChild(t);
    setTimeout(function () { t.remove(); }, 60000);
  });
};

/* ---------- copy link, and opening the Realm at a message ---------- */
function linkTo(id) { return location.origin+location.pathname+'?msg='+(+id); }
function copyLink(id) {
  var url = linkTo(id), done = function () { toast('Link copied. Anybody who can read this conversation can open it.'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url) ? done() : toast(url); }); }
  else if (fallbackCopy(url)) done(); else toast(url);
}
function fallbackCopy(text) {
  var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
  var ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  ta.remove(); return ok;
}
/* Where is that message? The server answers only if I may read it, then it opens as a search result
   does: the conversation (or the thread) at that message, lit up for a moment. */
function goTo(id) {
  fetch(R.api+'?msg_where='+(+id), {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (!j || !j.ok) { toast((j && j.error) || 'That message could not be found.'); return; } R.findOpen(j.channel, j.id, j.thread); })
    .catch(function () { toast('That message could not be found.'); });
}

/* ---------- mark unread ---------- */
function markUnread(id) {
  post(here({unread_from:id})).then(function (j) {
    CH.keep = true; R.chatTake(j);
    if (j && j.ok && !j.error) toast('Marked unread from this message. It stays unread until you leave this conversation or write in it.');
  }).catch(function () { toast('That could not be changed. Try again.'); });
}

/* ---------- edit my own message, in place ---------- */
function editStart(id) {
  var el = msgEl(id); if (!el || EDIT) return;
  var body = el.querySelector(':scope > .rmsgbody'), text = body ? body.textContent : '';
  EDIT = +id; el.classList.add('is-editing');
  var form = document.createElement('form'); form.className = 'redit';
  form.innerHTML = '<textarea rows="3" maxlength="2000" aria-label="Edit your message"></textarea><div class="racts"><button type="button" class="rbtn rsmall" data-editcancel="1">Cancel</button><button class="rbtn rprimary rsmall">Save</button></div><small>Enter saves, Shift+Enter is a new line, Escape cancels.</small>';
  var ta = form.querySelector('textarea'); ta.value = text;
  if (body) { body.hidden = true; body.parentNode.insertBefore(form, body.nextSibling); }
  else { var head = el.querySelector(':scope > .rmsghead'); head.parentNode.insertBefore(form, head.nextSibling); }
  ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
  var stop = function () { EDIT = 0; form.remove(); if (body) body.hidden = false; el.classList.remove('is-editing'); };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var words = ta.value.trim();
    if (words === text.trim()) { stop(); return; }
    ta.disabled = true;
    post(here({edit_msg:id, body:words})).then(function (j) { stop(); R.chatTake(j); })
      .catch(function () { ta.disabled = false; toast('That change was not saved. Try again.'); });
  });
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', {cancelable:true})); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); stop(); el.focus(); }
  });
  form.querySelector('[data-editcancel]').addEventListener('click', function (e) { e.stopPropagation(); stop(); el.focus(); });
}

/* ---------- one dialog, for forward, delete and the saved list ---------- */
var dlg = null;
function dialog(kind, html) {
  if (!dlg) {
    dlg = document.createElement('dialog'); dlg.className = 'rmsgdlg'; dlg.innerHTML = '<div class="rmsgdlgbody"></div>'; document.body.appendChild(dlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  }
  /* ⚠ ONLY THE INNER BOX IS REWRITTEN. While a dialog is open, assets/realm.js moves the notices
     (#toasts) into it so they show above the veil; rewriting the whole dialog would delete them. */
  dlg.setAttribute('data-kind', kind); dlg.querySelector('.rmsgdlgbody').innerHTML = html;
  if (dlg.showModal) { if (!dlg.open) dlg.showModal(); } else dlg.setAttribute('open', '');
  return dlg;
}
function snippetOf(id) {
  var el = msgEl(id); if (!el) return '';
  var b = el.querySelector('.rmsgbody'), who = el.querySelector('.rmsghead b');
  var t = b ? b.textContent.replace(/\s+/g, ' ').trim() : (el.querySelector('.rimg') ? 'a picture' : '');
  if (t.length > 140) t = t.slice(0, 138)+'…';
  return (who ? who.textContent+': ' : '')+t;
}

function askDelete(id) {
  var d = dialog('delete', '<h2>Delete this message?</h2><p class="rmsgquote">'+esc(snippetOf(id))+'</p><p>Everyone in the conversation will see &ldquo;This message was deleted.&rdquo; instead. This cannot be undone.</p>'
    +'<div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Cancel</button><button type="button" class="rbtn rdanger" data-delgo="'+(+id)+'">Delete</button></div>');
  var go = d.querySelector('[data-delgo]'); if (go) go.focus();
}
function doDelete(id) {
  dlg.close();
  post(here({delete_msg:id})).then(R.chatTake).catch(function () { toast('That message was not deleted. Try again.'); });
}

/* FORWARD: every channel I may write in, then my ravens with each person, with a box to find one. */
var FW = {id:0, to:''};
function targets() {
  var s = st(), out = []; if (!s || !s.chat) return out;
  (s.chat.channels || []).forEach(function (c) { if (c.post) out.push({id:c.id, name:'#'+c.name, kind:'Channel'}); });
  (s.people || []).forEach(function (p) { if (!p.me) out.push({id:R.dmId(s.me, p.id), name:p.name, kind:'Raven'}); });
  return out;
}
function openForward(id) {
  FW = {id:+id, to:''};
  var d = dialog('forward', '<h2>Forward message</h2><p class="rmsgquote">'+esc(snippetOf(id))+'</p>'
    +'<label class="rfwdlabel" for="fwdFind">Send it to</label><input id="fwdFind" class="rinput" autocomplete="off" placeholder="Find a channel or person">'
    +'<div class="rfwdlist" id="fwdList" role="listbox" aria-label="Where to forward it"></div>'
    +'<label class="rfwdlabel" for="fwdNote">Add a note (optional)</label><input id="fwdNote" class="rinput" maxlength="2000" autocomplete="off">'
    +'<div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Cancel</button><button type="button" class="rbtn rprimary" id="fwdGo" disabled>Forward</button></div>');
  fwdList('');
  d.querySelector('#fwdFind').addEventListener('input', function () { fwdList(this.value); });
  d.querySelector('#fwdFind').focus();
}
function fwdList(q) {
  q = q.toLowerCase().trim();
  var list = targets().filter(function (t) { return !q || t.name.toLowerCase().indexOf(q) >= 0; });
  var box = $('fwdList'); if (!box) return;
  box.innerHTML = list.map(function (t) {
    return '<button type="button" role="option" class="rrow'+(t.id===FW.to?' is-on':'')+'" aria-selected="'+(t.id===FW.to)+'" data-fwdto="'+esc(t.id)+'"><span>'+esc(t.name)+'</span><small>'+t.kind+'</small></button>';
  }).join('') || '<small class="rfwdnone">Nobody and nothing by that name.</small>';
}
function doForward() {
  var id = FW.id, to = FW.to, note = $('fwdNote') ? $('fwdNote').value : '';
  if (!id || !to) return;
  $('fwdGo').disabled = true;
  post({forward:id, to:to, note:note}).then(function (j) {
    if (!j || !j.ok || j.error) { $('fwdGo').disabled = false; toast((j && j.error) || 'That message was not forwarded. Try again.'); return; }
    dlg.close();
    toast('Forwarded to '+(R.chanTitle ? R.chanTitle(to) : 'the conversation')+'. Click here to open it.', to);
  }).catch(function () { $('fwdGo').disabled = false; toast('That message was not forwarded. Try again.'); });
}

/* SAVED: my own list, reminders still to come first. */
function openSaved() {
  var d = dialog('saved', '<h2>Saved for later</h2><div class="rsavedlist" id="savedList"><p class="rempty">Looking…</p></div><div class="rdacts"><button type="button" class="rbtn rprimary" data-dlgclose="1">Close</button></div>');
  fetch(R.api+'?saved=1', {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) {
      var box = $('savedList'); if (!box) return;
      if (!j || !j.ok) { box.innerHTML = '<p class="rempty">'+esc((j && j.error) || 'Your saved messages could not be reached.')+'</p>'; return; }
      box.innerHTML = (j.list || []).map(function (m) {
        return '<div class="rsaveditem"><div class="rsavedhead"><b>'+esc(m.where)+'</b><small>'+esc(m.mine ? 'You' : m.name)+' &middot; '+esc(m.at)+'</small></div>'
          +'<div class="rsavedbody'+(m.deleted?' rdeleted':'')+'">'+esc(m.about)+'</div>'
          +(m.remind ? '<small class="rsavedwhen">Reminder '+esc(m.remind)+'</small>' : '')
          +'<div class="racts">'+(m.deleted ? '' : '<button type="button" class="rbtn rsmall rprimary" data-savedopen="'+m.id+'" data-ch="'+esc(m.channel)+'" data-th="'+(+m.thread)+'">Open</button>')
          +'<button type="button" class="rbtn rsmall" data-savedremove="'+m.id+'">Remove</button></div></div>';
      }).join('') || '<p class="rempty">Nothing saved yet. Use the clock in the bar over a message to save it for later, with or without a reminder.</p>';
    })
    .catch(function () { var box = $('savedList'); if (box) box.innerHTML = '<p class="rempty">Your saved messages could not be reached.</p>'; });
  return d;
}

/* ---------- clicks (taken before assets/realm.js sees them; only ours are stopped) ---------- */
var TOUCH = window.matchMedia && window.matchMedia('(hover: none)').matches;
document.addEventListener('click', function (e) {
  var t = e.target && e.target.closest ? e.target : (e.target && e.target.parentNode), b, m;
  if (!t || !t.closest) return;
  if ((b = t.closest('[data-menu]'))) { e.stopPropagation(); var mid = menuFor; menuPick(b.getAttribute('data-menu'), mid); return; }
  if ((b = t.closest('[data-act]')) && (m = b.closest('.rmsg[data-msg]'))) {
    e.stopPropagation();
    var id = +m.getAttribute('data-msg'), what = b.getAttribute('data-act');
    if (menu && !menu.hidden && menuFor === id && menu.getAttribute('data-for') === what) { menuClose(); return; }
    if (what === 'fwd') { menuClose(); openForward(id); }
    else if (what === 'save') { saveMenu(b, id); menu.setAttribute('data-for', what); }
    else if (what === 'more') { moreMenu(b, id); menu.setAttribute('data-for', what); }
    return;
  }
  if (t.closest('[data-savedlist]')) { e.stopPropagation(); openSaved(); return; }
  if ((b = t.closest('[data-savedopen]'))) { e.stopPropagation(); dlg.close(); R.findOpen(b.getAttribute('data-ch'), +b.getAttribute('data-savedopen'), +b.getAttribute('data-th')); return; }
  if ((b = t.closest('[data-savedremove]'))) { e.stopPropagation(); unsave(+b.getAttribute('data-savedremove')); return; }
  if ((b = t.closest('[data-gomsg]'))) { e.stopPropagation(); b.remove(); goTo(+b.getAttribute('data-gomsg')); return; }
  if ((b = t.closest('[data-fwdto]'))) { e.stopPropagation(); FW.to = b.getAttribute('data-fwdto'); fwdList($('fwdFind') ? $('fwdFind').value : ''); if ($('fwdGo')) $('fwdGo').disabled = false; return; }
  if (t.closest('#fwdGo')) { e.stopPropagation(); doForward(); return; }
  if ((b = t.closest('[data-delgo]'))) { e.stopPropagation(); doDelete(+b.getAttribute('data-delgo')); return; }
  if (t.closest('[data-dlgclose]')) { e.stopPropagation(); if (dlg) dlg.close(); return; }
  if (menu && !menu.hidden && !t.closest('.rmenu')) menuClose();
  /* TOUCH: a tap on a message (not on a link or button in it) shows its bar, and hides the others'. */
  if (TOUCH && (m = t.closest('.rmsg[data-msg]')) && !t.closest('button, a, form')) {
    var on = !m.classList.contains('is-bar');
    Array.prototype.forEach.call(document.querySelectorAll('.rmsg.is-bar'), function (x) { x.classList.remove('is-bar'); });
    m.classList.toggle('is-bar', on);
  }
}, true);

/* ---------- keyboard ---------- */
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && menu && !menu.hidden) { e.stopPropagation(); e.preventDefault(); var f = msgEl(menuFor); menuClose(); if (f) f.focus(); return; }
  if (menu && !menu.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && menu.contains(document.activeElement)) {
    e.preventDefault();
    var items = Array.prototype.slice.call(menu.querySelectorAll('button')), i = items.indexOf(document.activeElement);
    items[(i+(e.key === 'ArrowDown' ? 1 : items.length-1)) % items.length].focus();
    return;
  }
  var t = e.target;
  if (!t || !t.classList || !t.classList.contains('rmsg') || !t.hasAttribute('data-msg')) return;
  /* On a focused message: the arrow keys move to the one before or after it, as Slack's do. */
  if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
    e.preventDefault();
    var all = Array.prototype.slice.call($('chatMsgs').querySelectorAll('.rmsg[data-msg]')), k = all.indexOf(t);
    var next = all[k+(e.key === 'ArrowDown' ? 1 : -1)]; if (next) next.focus();
  } else if (e.key === 'Enter') {
    /* Enter goes into the bar, to its first button. */
    t.classList.add('is-bar');
    /* The first button that is shown (the reactions are hidden until the server offers them). */
    var first = Array.prototype.filter.call(t.querySelectorAll('.rmsgbar button'), function (b) { return b.offsetParent !== null; })[0];
    if (first) { e.preventDefault(); first.focus(); } else t.classList.remove('is-bar');
  }
}, true);
/* A bar opened from the keyboard (.is-bar) closes again when the focus leaves its message. */
document.addEventListener('focusout', function (e) {
  var m = e.target && e.target.closest && e.target.closest('.rmsg.is-bar'); if (!m || TOUCH) return;
  setTimeout(function () { if (!m.contains(document.activeElement) && !(menu && !menu.hidden && menuFor === +m.getAttribute('data-msg'))) m.classList.remove('is-bar'); }, 0);
});
/* Pressing Enter in the forward list's search box picks the first match. */
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' || !e.target || e.target.id !== 'fwdFind') return;
  e.preventDefault(); var first = document.querySelector('#fwdList [data-fwdto]');
  if (first) { FW.to = first.getAttribute('data-fwdto'); fwdList(e.target.value); if ($('fwdGo')) { $('fwdGo').disabled = false; $('fwdNote').focus(); } }
});
/* The menus stay beside their button: anything that moves the button closes them. */
var box = $('chatMsgs'); if (box) box.addEventListener('scroll', menuClose, {passive:true});
window.addEventListener('resize', menuClose);

/* ---------- a link to a message: realm.php?msg=<id> ---------- */
(function () {
  var m = /[?&]msg=(\d+)/.exec(location.search); if (!m) return;
  var s = st(); if (!s || !s.chat) return;
  try { history.replaceState(null, '', location.pathname); } catch (e) { /* The address keeps it; nothing else changes. */ }
  goTo(+m[1]);
})();
})();
