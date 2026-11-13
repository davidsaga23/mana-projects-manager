/* The Realm: praise, mana and gold (David, 2026-11-12 and 2026-11-13, migration 087).

   The rules are in includes/realm_praise.php and are checked there; this file only shows them.
   It is its own file so that the map's script (assets/realm.js) stays as it was: that one hands
   over four things on window.REALM, the notices (toast), the one way of asking the server (send),
   and three places where this file is asked in: onState (the map changed), openGold (the chest
   was clicked) and profExtra (a profile is being drawn).

   WHAT IS HERE.
   - A Praise button on the map, shown only while somebody else is standing where you are, inside
     the same room or at the same spot outside. "Trade offs ... should be face-to-face in the map"
     (David, 2026-11-12). The server asks the same question again.
   - The window it opens: who, which of the four kinds, and a line, "it could be the work, it could
     be just kudos from a default menu" (David, 2026-11-13).
   - What everybody sees when a praise is given: a notice, within one heartbeat. Praise is public.
   - The chest: your own mana, gold and run of logged days. Nobody else's is ever asked for.
   - The praise somebody has received, in their profile.

   The word on the screen is plain "praise". Anima's task board is called Spell, so a praise is not
   called a spell here. David, 2026-11-13: "Keep praise". */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.state || !R.send || !document.getElementById('mapbox')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var first = function (n) { return String(n).split(' ')[0]; };

/* The four kinds, the same ids as REALM_PRAISE_KINDS, each with the lines offered ready-made. */
var KINDS = [
  {id:'craft', n:'Craft', what:'Beautiful work', lines:['Beautiful work.','That shot is gorgeous.','The detail in this is something else.']},
  {id:'help',  n:'Help',  what:'Helping somebody out', lines:['Thank you for helping me out.','You made my day easier.','Thank you for explaining that.']},
  {id:'idea',  n:'Idea',  what:'A good idea', lines:['Great idea.','That solved it.','I would not have thought of that.']},
  {id:'rescue',n:'Rescue',what:'Saving the day', lines:['You saved the day.','Thank you for jumping in.','We made it because of you.']}
];
var kindOf = function (id) { for (var i = 0; i < KINDS.length; i++) if (KINDS[i].id === id) return KINDS[i]; return KINDS[0]; };

var ST = R.state, seen = {}, firstNews = true;
var P = function () { return ST.praise || {ready:false,mana:0,cap:5,recent:[]}; };
function me() { for (var i = 0; i < ST.people.length; i++) if (ST.people[i].me) return ST.people[i]; return null; }
/* Who is standing where I am: in the same place, both inside or both outside, and not away. */
function here() {
  var m = me(); if (!m) return [];
  return ST.people.filter(function (p) { return !p.me && p.state !== 'away' && p.place === m.place && !!p.inside === !!m.inside; });
}

/* ---------- the button on the map ---------- */
var btn = document.createElement('button');
btn.type = 'button'; btn.id = 'praiseBtn'; btn.className = 'rbtn rpraisebtn'; btn.hidden = true;
$('mapbox').appendChild(btn);
function renderBtn() {
  var p = P(), list = here();
  btn.hidden = !p.ready || !list.length;
  if (btn.hidden) return;
  btn.innerHTML = 'Praise '+esc(list.length === 1 ? first(list[0].name) : 'somebody here')+' <span class="rpips" aria-label="'+p.mana+' of '+p.cap+' mana">'+pips(p.mana,p.cap)+'</span>';
  btn.title = p.mana ? 'Costs 1 mana. You have '+p.mana+'.' : 'You have no mana. You earn one each working day you log your time.';
}
function pips(n, cap) { var h = ''; for (var i = 0; i < cap; i++) h += '<i class="rpip'+(i < n ? ' is-on' : '')+'"></i>'; return h; }

/* ---------- the window ---------- */
var dlg = null, pick = {to:0,kind:'craft'};
function openPraise() {
  var list = here(), p = P(); if (!list.length) return;
  if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'praiseDlg'; dlg.setAttribute('aria-labelledby','praiseTitle');
    /* Drawn into a box of its own, never the window itself: the notices live inside whichever window
       is open (toastHome in assets/realm.js), and rewriting the window would throw them away. */
    dlg.innerHTML = '<div id="praiseBody"></div>'; document.body.appendChild(dlg);
    dlg.addEventListener('click',function (e) { var t = e.target;
      if (t.closest('[data-praiseclose]')) dlg.close();
      else if (t.closest('[data-praisekind]')) { pick.kind = t.closest('[data-praisekind]').dataset.praisekind; draw(); }
      else if (t.closest('[data-praiseto]')) { pick.to = +t.closest('[data-praiseto]').dataset.praiseto; draw(); } });
    dlg.addEventListener('change',function (e) { if (e.target.id === 'praiseLine') { var own = $('praiseOwn'); own.hidden = e.target.value !== ''; if (!own.hidden) own.focus(); } });
    dlg.addEventListener('submit',function (e) { e.preventDefault(); give(); }); }
  if (dlg.open) return;
  if (!list.some(function (q) { return q.id === pick.to; })) pick.to = list[0].id;
  draw(); dlg.showModal();
}
function draw() {
  var list = here(), p = P(), k = kindOf(pick.kind), h = '<h2 id="praiseTitle">Praise somebody</h2><form id="praiseForm">';
  if (!list.length) { $('praiseBody').innerHTML = h+'<p>Nobody is standing here with you any more. Praise is given face to face.</p><div class="rdacts"><button type="button" class="rbtn" data-praiseclose="1">Close</button></div></form>'; return; }
  if (!list.some(function (q) { return q.id === pick.to; })) pick.to = list[0].id;
  h += '<div class="rpfield"><span>Who</span><div class="rchips">';
  list.forEach(function (q) { h += '<button type="button" class="rchip'+(q.id === pick.to ? ' is-on' : '')+'" data-praiseto="'+q.id+'" aria-pressed="'+(q.id === pick.to)+'">'+esc(q.name)+'</button>'; });
  h += '</div></div><div class="rpfield"><span>What for</span><div class="rchips">';
  KINDS.forEach(function (x) { h += '<button type="button" class="rchip is-'+x.id+(x.id === k.id ? ' is-on' : '')+'" data-praisekind="'+x.id+'" aria-pressed="'+(x.id === k.id)+'" title="'+esc(x.what)+'">'+esc(x.n)+'</button>'; });
  h += '</div><small>'+esc(k.what)+'</small></div><label class="rpfield"><span>What you say</span><select id="praiseLine">';
  k.lines.forEach(function (l) { h += '<option value="'+esc(l)+'">'+esc(l)+'</option>'; });
  h += '<option value="">Write my own, or name the work</option></select></label>'
    +'<input id="praiseOwn" maxlength="160" placeholder="For example: for saving the comp on shot 40" aria-label="Your own line" hidden>'
    +'<small>'+(p.mana ? 'A praise costs 1 mana, and you have '+p.mana+'. Everybody in the Realm sees it, and it stays in their profile.'
                       : 'You have no mana right now. You earn one each working day you log your time, up to '+p.cap+'.')+'</small>'
    +'<div class="rdacts"><button type="button" class="rbtn" data-praiseclose="1">Cancel</button><button class="rbtn rprimary" id="praiseGo"'+(p.mana ? '' : ' disabled')+'>Praise</button></div></form>';
  $('praiseBody').innerHTML = h;
}
function give() {
  var line = $('praiseLine'), own = $('praiseOwn'), go = $('praiseGo'); if (!line || !go || go.disabled) return;
  var note = line.value !== '' ? line.value : own.value.trim();
  if (!note) { R.toast('Say what the praise is for, in a line.'); own.focus(); return; }
  go.disabled = true;
  /* send() shows whatever the server refuses, and takes in the new map, which carries the praise. */
  R.send({praise:pick.to,kind:pick.kind,note:note}).then(function (j) {
    if (j && j.praised) { if (dlg.open) dlg.close(); } else if ($('praiseGo')) $('praiseGo').disabled = !P().mana;
    if (!j) R.toast('The praise could not be given. Try again in a moment.');
  });
}
btn.addEventListener('click',openPraise);

/* ---------- what everybody sees ---------- */
/* What was already true when the page loaded is not news, the same rule as a knock. */
function news() {
  (P().recent || []).forEach(function (r) {
    if (seen[r.id]) return; seen[r.id] = true; if (firstNews) return;
    var mine = r.from === ST.me, forMe = r.to === ST.me, t = document.createElement('div');
    t.className = 'rtoast rpraised is-'+kindOf(r.kind).id;
    t.innerHTML = '<b>'+esc(mine ? 'You' : first(r.from_name))+' praised '+esc(forMe ? 'you' : first(r.to_name))+'</b><span><i class="rkind is-'+kindOf(r.kind).id+'">'+esc(kindOf(r.kind).n)+'</i> '+esc(r.note)+'</span>'
      +(forMe ? '<small>It is kept in your profile, in the portrait in your house.</small>' : '');
    R.toastHome().appendChild(t); setTimeout(function () { t.remove(); },forMe ? 20000 : 10000);
  });
  firstNews = false;
}
/* The window is drawn again only when who is standing here changed, so a line being chosen or
   typed is not wiped by the heartbeat. */
var hereSig = '';
R.onState = function (st) {
  ST = st; renderBtn(); news();
  var sig = here().map(function (q) { return q.id; }).join(',');
  if (dlg && dlg.open && sig !== hereSig) draw();
  hereSig = sig;
};

/* ---------- the chest ---------- */
R.openGold = function (d, body) {
  var close = '<div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Close</button></div>';
  body.innerHTML = '<p>Opening the chest…</p>'+close; d.showModal();
  fetch(R.api+'?purse=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) {
      var p = j && j.purse;
      if (!p) { body.innerHTML = '<p>The chest could not be opened. Try again in a moment.</p>'+close; return; }
      if (!p.ready) { body.innerHTML = '<p class="rgold"><b>No gold yet</b></p><p>Mana and gold need a database update first. An administrator opens the updates page once.</p>'+close; return; }
      if (ST.praise) { ST.praise.mana = p.mana; renderBtn(); }
      var left = p.every-(p.streak % p.every);
      body.innerHTML = '<p class="rgold"><b>'+p.gold+' gold '+(p.gold === 1 ? 'coin' : 'coins')+'</b></p>'
        +'<p class="rmana"><span class="rpips" aria-hidden="true">'+pips(p.mana,p.cap)+'</span> '+p.mana+' of '+p.cap+' mana, for praising others</p>'
        +(p.linked
          ? '<p>'+(p.streak ? 'You have logged your time '+p.streak+' working '+(p.streak === 1 ? 'day' : 'days')+' in a row. '+left+' more '+(left === 1 ? 'day' : 'days')+' for the next coin.'
                            : 'Log your time today to start a run. Every working day you log gives 1 mana, and every '+p.every+' in a row gives 1 gold coin.')+'</p>'
          : '<p>Your login is not linked to a person yet, so there is no time log to earn from. An administrator links it on the Users page.</p>')
        +'<p>A day can be logged until noon of the next working day. Weekends, holidays and booked days off do not break a run. You also get a coin the first time each person praises you in a week.</p>'
        +'<p>There is nothing to buy yet: clothes, companions and things for your office are coming. Your gold is private, nobody else can see it, and it never has anything to do with pay.</p>'+close;
    })
    .catch(function () { body.innerHTML = '<p>The chest could not be opened. Try again in a moment.</p>'+close; });
};

/* ---------- the praise somebody has received, in their profile ---------- */
R.profExtra = function (p) {
  var pr = p.praise; if (!pr || !pr.ready) return '';
  var h = '<div class="rkeeps"><h3>Praise received</h3>';
  if (!pr.total) return h+'<p class="rprofbio is-none">'+(p.mine ? 'None yet.' : 'None yet. Stand where they are to give one.')+'</p></div>';
  h += '<div class="rchips">';
  KINDS.forEach(function (x) { var n = (pr.kinds || {})[x.id] || 0; if (n) h += '<span class="rkind is-'+x.id+'">'+esc(x.n)+' '+n+'</span>'; });
  h += '</div><ul>';
  (pr.latest || []).forEach(function (r) { h += '<li><i class="rkind is-'+kindOf(r.kind).id+'">'+esc(kindOf(r.kind).n)+'</i> <span>'+esc(r.note)+'</span> <small>'+esc(first(r.from_name))+', '+esc(r.on)+'</small></li>'; });
  return h+'</ul></div>';
};

renderBtn(); news();
})();
