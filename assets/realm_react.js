/* The Realm: reactions on chat messages (David, 2026-11-13, migration 093).

   "The most-used Slack feature, a thumbs up saves typing 'ok'." The rules are in
   includes/realm_chat.php and are checked there; this file only shows them.
   Its own file, like assets/realm_praise.js, so the map's script stays as it was. It borrows the
   open conversation (window.REALM.chat) and the one place an answer about it is taken in
   (window.REALM.chatTake) from assets/realm.js, which calls REALM.onChat with every answer.

   WHAT IS HERE.
   - A small "react" button on every message, shown while the pointer is over it (always, faintly,
     on a touch screen, which has no pointer to hover with). It opens a row of six reactions: a
     fixed set, not a full emoji picker, to keep it light and in keeping with the pixel look.
   - Under a message, a chip for each reaction given: the emoji and how many, lit when one of them
     is mine. Clicking a chip gives or takes back mine; hovering it says who.
   - Nothing at all until the server says reactions exist: an answer without `reactions` means
     migration 093 has not run yet, and the chat looks exactly as before.

   A REACTION IS NOT A MESSAGE: it does not move the unread count or ring the bell. The others see
   it within seconds because an open conversation already asks for news every 3 seconds. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.chat || !R.chatTake || !document.getElementById('chatMsgs')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

/* The set, in the order the row shows it. The keys are what the server keeps (REALM_REACTIONS in
   includes/realm_chat.php); a key added there must be added here too, or it is not drawn. */
var SET = [
  {k:'up',     e:'👍', n:'thumbs up'},
  {k:'heart',  e:'❤️', n:'heart'},
  {k:'laugh',  e:'😂', n:'laughing'},
  {k:'party',  e:'🎉', n:'party'},
  {k:'eyes',   e:'👀', n:'looking'},
  {k:'thanks', e:'🙏', n:'thanks'}
];
var BY = {}; SET.forEach(function (x) { BY[x.k] = x; });

var ON = false;     // has the server said reactions exist?
var NOW = {};       // message id => the list the server last sent for it
var HOLD = 0;       // until when a poll's (older) reactions are not drawn, after I reacted
var MINE = false;   // is the answer being taken the answer to my own reaction?
var CHAN = '';      // the conversation NOW belongs to

function list(who) {
  if (!who || !who.length) return '';
  if (who.length === 1) return who[0];
  return who.slice(0, -1).join(', ')+' and '+who[who.length-1];
}
function chipsHTML(rs) {
  if (!rs || !rs.length) return '';
  return rs.filter(function (r) { return BY[r.e] && r.n > 0; }).map(function (r) {
    var x = BY[r.e], say = list(r.who)+' reacted with '+x.n;
    return '<button type="button" class="rreact'+(r.mine?' is-mine':'')+'" data-react="'+esc(r.e)+'" aria-pressed="'+(r.mine?'true':'false')+'" title="'+esc(say)+'" aria-label="'+esc(say)+'"><span aria-hidden="true">'+x.e+'</span><b>'+(+r.n)+'</b></button>';
  }).join('');
}
var ADD = '<button type="button" class="rreactadd" data-reactadd="1" title="React" aria-label="React to this message"><svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true"><circle cx="7" cy="8" r="5.5" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="5.2" cy="6.8" r=".9" fill="currentColor"/><circle cx="8.8" cy="6.8" r=".9" fill="currentColor"/><path d="M4.8 9.6q2.2 2 4.4 0" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M13.5 1.5v4M11.5 3.5h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>';

/* Draw what is known onto every message on screen. Only a message whose reactions changed is
   touched, so a chip under the pointer is not replaced every 3 seconds for nothing. */
function paint() {
  var box = $('chatMsgs'); if (!box) return;
  Array.prototype.forEach.call(box.querySelectorAll('.rmsg[data-msg]'), function (el) {
    var add = el.querySelector(':scope > .rreactadd');
    if (!ON) { if (add) add.remove(); var old = el.querySelector(':scope > .rreacts'); if (old) old.remove(); return; }
    if (!add) el.insertAdjacentHTML('beforeend', ADD);
    var h = chipsHTML(NOW[el.getAttribute('data-msg')]), row = el.querySelector(':scope > .rreacts');
    if (row && row.getAttribute('data-h') === h) return;
    if (!h) { if (row) row.remove(); return; }
    if (!row) { row = document.createElement('div'); row.className = 'rreacts'; el.appendChild(row); }
    row.setAttribute('data-h', h); row.innerHTML = h;
  });
}

/* Every answer about the open conversation comes through here (assets/realm.js, chatTake). */
R.onChat = function (j) {
  if (!j || !j.ok || j.channel !== R.chat.open) return;
  if (!j.reactions) { ON = false; pickClose(); paint(); return; }
  ON = true;
  /* Another conversation: what I did in the last one holds nothing back here. */
  if (j.channel !== CHAN) { CHAN = j.channel; HOLD = 0; NOW = {}; }
  /* Just after I reacted, a poll that left before my click may still bring the old count back for
     a moment; it is not drawn. The answer to my own reaction always is. */
  if (MINE || Date.now() > HOLD) NOW = j.reactions;
  paint();
};

/* Give or take back my reaction. It shows at once, then the server's answer is drawn. */
function react(id, k) {
  var CH = R.chat; if (!CH.open || !BY[k]) return;
  var rs = (NOW[id] || []).map(function (r) { return {e:r.e, n:r.n, mine:r.mine, who:r.who.slice()}; }), had = null;
  rs.forEach(function (r) { if (r.e === k) had = r; });
  if (had && had.mine) { had.n--; had.mine = false; had.who = had.who.filter(function (w) { return w !== 'You'; }); }
  else if (had) { had.n++; had.mine = true; had.who.push('You'); }
  else rs.push({e:k, n:1, mine:true, who:['You']});
  NOW[id] = rs.filter(function (r) { return r.n > 0; }); paint();
  HOLD = Date.now()+4000;
  var f = new URLSearchParams(); f.append('react', id); f.append('emoji', k); f.append('after', CH.last); f.append('from', CH.first);
  // Inside a thread (migration 094) the answer must be about that thread, or realm.js sets it aside.
  if (CH.thread) f.append('thread', CH.thread);
  fetch(R.api, {method:'POST', body:f, credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { MINE = true; try { R.chatTake(j); } finally { MINE = false; } })
    .catch(function () { HOLD = 0; if (R.toast) R.toast('That reaction was not saved. Try again.'); });
}

/* ---------- the row of six ---------- */
var pick = null, pickFor = 0;
function pickClose() { if (pick) pick.hidden = true; pickFor = 0; }
function pickOpen(btn, id) {
  if (!pick) {
    pick = document.createElement('div'); pick.className = 'rreactpick'; pick.setAttribute('role', 'menu'); pick.hidden = true;
    pick.innerHTML = SET.map(function (x) { return '<button type="button" role="menuitem" data-reactpick="'+x.k+'" title="'+esc(x.n)+'" aria-label="'+esc(x.n)+'">'+x.e+'</button>'; }).join('');
    document.body.appendChild(pick);
  }
  pickFor = id; pick.hidden = false;
  var b = btn.getBoundingClientRect(), w = pick.offsetWidth, h = pick.offsetHeight;
  var x = Math.max(8, Math.min(window.innerWidth-w-8, b.right-w)), y = b.top-h-6;
  if (y < 8) y = b.bottom+6;
  pick.style.left = x+'px'; pick.style.top = y+'px';
  var first = pick.querySelector('button'); if (first) first.focus();
}

/* Clicks are taken before assets/realm.js sees them (capture), and only ours are stopped. */
document.addEventListener('click', function (e) {
  var t = e.target && e.target.closest ? e.target : (e.target && e.target.parentNode), b, m;
  if (!t || !t.closest) return;
  if ((b = t.closest('[data-reactpick]'))) {
    e.stopPropagation(); var id = pickFor; pickClose(); if (id) react(String(id), b.getAttribute('data-reactpick')); return;
  }
  if ((b = t.closest('[data-reactadd]')) && (m = b.closest('.rmsg[data-msg]'))) {
    e.stopPropagation();
    var mid = m.getAttribute('data-msg');
    if (pick && !pick.hidden && pickFor === mid) pickClose(); else pickOpen(b, mid);
    return;
  }
  if ((b = t.closest('[data-react]')) && (m = b.closest('.rmsg[data-msg]'))) {
    e.stopPropagation(); pickClose(); react(m.getAttribute('data-msg'), b.getAttribute('data-react')); return;
  }
  if (pick && !pick.hidden && !t.closest('.rreactpick')) pickClose();
}, true);
/* Escape closes the row first, before it closes anything else. */
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && pick && !pick.hidden) { e.stopPropagation(); e.preventDefault(); pickClose(); }
}, true);
/* The row stays beside its button: anything that moves the button closes it. */
var box = $('chatMsgs'); if (box) box.addEventListener('scroll', pickClose, {passive:true});
window.addEventListener('resize', pickClose);
})();
