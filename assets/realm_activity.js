/* The Realm: Activity, everything that is FOR YOU in one list (David, 2026-11-16, migration 098).

   As in Slack, so nothing gets lost when channels are busy: the messages that tag you (@your name,
   @here, @channel), replies in threads you started or replied in, reactions to your messages, and
   ravens sent to you. Newest first, the last 30 days. The "Activity" row at the top of the CHANNELS
   list opens it in the chat panel, as "Threads" does; its number is how many rows are unread.

   WHAT IS DECIDED ON THE SERVER (includes/realm_activity.php), never here: which rows there are
   (only conversations this person may read, private channels and ravens included only for their
   own people) and which are read. A row is read once its conversation or thread has been read in
   the chat, or once it was clicked here, or after "Mark all as read".

   Its own file, like assets/realm_msgact.js. It borrows from assets/realm.js the open panel
   (REALM.chat: CH.open is REALM.ACTIVITY while the list shows), opening a conversation at one
   message (REALM.findOpen, as search results do), conversation names (REALM.chanTitle), the
   reaction pictures (REALM.reactSet, assets/realm_react.js) and the news every list answer carries
   (REALM.takeNews). The panel asks again every 3 seconds while the list is open (REALM.actAsk);
   the number on the row rides on the polling the Realm already does. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.chat || !R.findOpen || !document.getElementById('chatMsgs')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var CH = R.chat;
var A = {kind: '', list: [], mark: true, days: 30, seq: 0, drawn: ''};

/* The filters, in Slack's order. '' is everything. */
var KINDS = [['', 'All'], ['mention', 'Mentions'], ['thread', 'Threads'], ['reaction', 'Reactions'], ['raven', 'Ravens']];
var ICON = {mention: '@', thread: '&#8627;', reaction: '&#9786;', raven: '&#9993;'};

function get(url) { return fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }); }
function post(fields) {
  var b = new URLSearchParams(); Object.keys(fields).forEach(function (k) { b.append(k, fields[k]); });
  return fetch(R.api, {method: 'POST', body: b, credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); });
}
function open() { return CH.open === R.ACTIVITY; }

/* What happened, in a few words after the name. */
function what(a) {
  if (a.kind === 'mention') return 'mentioned you';
  if (a.kind === 'thread') return a.mine_thread ? 'replied to your thread' : 'replied in a thread you follow';
  if (a.kind === 'raven') return a.fwd ? 'forwarded you a message' : 'sent you a raven';
  var x = (R.reactSet || []).filter(function (s) { return s.k === a.emoji; })[0];
  return 'reacted <span class="ractemoji" title="' + esc(x ? x.n : a.emoji) + '">' + (x ? x.e : '') + '</span> to your message';
}
function where(a) {
  var name = R.chanTitle ? R.chanTitle(a.channel) : 'Chat', dm = /^dm:/.test(a.channel);
  if (a.thread) return dm ? name.replace(/^Ravens/, 'Thread in ravens') : 'Thread in #' + name;
  return dm ? name : '#' + name;
}
function rowHTML(a) {
  return '<button type="button" class="ractrow' + (a.unread ? ' is-new' : '') + '" data-arow="' + esc(a.key) + '" data-ach="' + esc(a.channel) + '" data-aid="' + (+a.id) + '" data-ath="' + (+a.thread || 0) + '">'
    + '<span class="ractico ract-' + esc(a.kind) + '" aria-hidden="true">' + ICON[a.kind] + '</span>'
    + '<span class="ractmain"><span class="racthead"><b>' + esc(a.who) + '</b> <span>' + what(a) + '</span><small>' + esc(a.at) + '</small></span>'
    + '<span class="ractwhere">' + esc(where(a)) + '</span>'
    + '<span class="ractbody">' + esc(a.about) + '</span></span>'
    + (a.unread ? '<i class="ractdot" title="Unread" aria-label="Unread"></i>' : '') + '</button>';
}
function draw() {
  if (!open()) return;
  var box = $('chatMsgs'), unread = A.list.filter(function (a) { return a.unread; }).length;
  var bar = '<div class="ractbar"><div class="ractfilters" role="group" aria-label="Show">'
    + KINDS.map(function (k) { return '<button type="button" class="ractf" data-afilter="' + k[0] + '" aria-pressed="' + (A.kind === k[0]) + '">' + k[1] + '</button>'; }).join('')
    + '</div><button type="button" class="rbtn rsmall" data-aall="1"' + (!A.mark ? ' disabled title="Needs a database update first"' : (unread || R.now().chat.activity ? '' : ' disabled')) + '>Mark all as read</button></div>';
  var empty = {'': 'Nothing for you in the last ' + A.days + ' days. When somebody tags you, replies in your threads, reacts to your messages or sends you a raven, it shows here.',
               mention: 'Nobody has tagged you in the last ' + A.days + ' days.', thread: 'No replies in your threads in the last ' + A.days + ' days.',
               reaction: 'No reactions to your messages in the last ' + A.days + ' days.', raven: 'No ravens in the last ' + A.days + ' days.'};
  var h = bar + (A.list.length ? '<div class="ractlist">' + A.list.map(rowHTML).join('') + '</div>' : '<p class="rempty">' + esc(empty[A.kind]) + '</p>');
  /* Drawn again only when something changed, so a 3-second poll does not undo the scroll or focus. */
  if (h === A.drawn && box.firstChild) return;
  var top = box.scrollTop; box.innerHTML = h; box.scrollTop = top; A.drawn = h;
}
function take(j, seq) {
  CH.busy = false;
  if (!j) return;
  if (!j.ok) { if (j.error) R.toast(j.error); if (open() && R.closeChat) R.closeChat(); return; }
  if (j.error) R.toast(j.error);
  if (seq !== A.seq || !open()) { R.takeNews(j); return; }
  A.list = j.list || []; A.mark = !!j.actmark; A.days = j.days || A.days;
  /* The news first, so the count the bar reads is this answer's. */
  R.takeNews(j);
  draw();
}
/* Called by assets/realm.js every 3 seconds while the list is open, and once when it opens. */
R.actAsk = function () {
  var seq = A.seq;
  if (!CH.seen) { A.drawn = ''; $('chatMsgs').innerHTML = '<p class="rempty">Looking…</p>'; }
  CH.seen = true;
  get(R.api + '?activity=1&kind=' + encodeURIComponent(A.kind)).then(function (j) { take(j, seq); }).catch(function () { CH.busy = false; });
};
function filter(kind) {
  A.kind = kind; A.seq++; A.list = []; A.drawn = '';
  var seq = A.seq; CH.busy = true;
  draw();
  get(R.api + '?activity=1&kind=' + encodeURIComponent(kind)).then(function (j) { take(j, seq); }).catch(function () { CH.busy = false; });
}
function markAll() {
  A.seq++; var seq = A.seq; CH.busy = true;
  A.list.forEach(function (a) { a.unread = false; }); draw();
  post({activity_read: 'all', kind: A.kind}).then(function (j) { take(j, seq); }).catch(function () { CH.busy = false; R.toast('That could not be saved. Try again.'); });
}
/* A row opens its conversation (or thread) at that message, lit up as a search result is, and
   counts as read. The read mark is sent first and not waited for: opening the conversation reads
   it anyway for a message, and a reaction's mark only needs to arrive before the next count. */
function openRow(b) {
  var key = b.getAttribute('data-arow'), row = A.list.filter(function (a) { return a.key === key; })[0];
  if (row && row.unread && A.mark) {
    row.unread = false;
    var s = R.now(); if (s && s.chat && s.chat.activity > 0) { s.chat.activity--; if (R.renderSide) R.renderSide(); }
    post({activity_read: key}).catch(function () { /* It stays unread a little longer. */ });
  }
  R.findOpen(b.getAttribute('data-ach'), +b.getAttribute('data-aid'), +b.getAttribute('data-ath'), R.ACTIVITY);
}
$('chatMsgs').addEventListener('click', function (e) {
  if (!open()) return;
  var b;
  if ((b = e.target.closest('[data-afilter]'))) { e.stopPropagation(); if (b.getAttribute('data-afilter') !== A.kind) filter(b.getAttribute('data-afilter')); return; }
  if (e.target.closest('[data-aall]')) { e.stopPropagation(); markAll(); return; }
  if ((b = e.target.closest('[data-arow]'))) { e.stopPropagation(); openRow(b); }
});
})();
