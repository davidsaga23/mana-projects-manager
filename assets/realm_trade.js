/* The Realm: giving and swapping what was bought (David, 2026-11-13, migration 092).

   The rules are in includes/realm_trade.php and are checked there; this file only shows them.
   Its own file, like assets/realm_praise.js and assets/realm_shop.js. It borrows the notices and
   the one way of asking the server from assets/realm.js, and the names of the things and what I
   own from assets/realm_shop.js (window.REALM.shop).

   WHAT IS HERE.
   - A "Give or swap" button on the map, shown only while somebody else is standing where I am and
     I own something from the merchant. "Anything you paid for is exchangable" (David).
   - The window it opens: who, which of my things, and whether it is a gift or a swap.
   - The notices the two people answer. A gift: the other accepts or says no thanks. A swap: the
     other picks one of THEIR things to give back, and I agree or not. What somebody owns is
     private, so I never pick from their things; they choose.
   - How it ended, told to both.

   ONLY THE TWO PEOPLE SEE ANY OF THIS. Nothing is announced to the village. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.state || !R.send || !R.shop || !document.getElementById('mapbox')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var first = function (n) { return String(n).split(' ')[0]; };
var the = function (id) { var x = R.shop.item(id); return x ? x.the : 'that'; };
var name = function (id) { var x = R.shop.item(id); return x ? x.n : id; };
var cap = function (s) { return s.charAt(0).toUpperCase()+s.slice(1); };
/* Where a thing is found once it is mine. */
var where = function (id) { return R.shop.kind(id) === 'thing' ? 'Find it in your office, under Decorate.' : 'Put it on in Your character.'; };

var ST = R.state, READY = !!(ST.offers && ST.offers.ready);
function me() { for (var i = 0; i < ST.people.length; i++) if (ST.people[i].me) return ST.people[i]; return null; }
/* Who is standing where I am: in the same place, both inside or both outside, and not away. */
function here() {
  var m = me(); if (!m) return [];
  return ST.people.filter(function (p) { return !p.me && p.state !== 'away' && p.place === m.place && !!p.inside === !!m.inside; });
}

/* ---------- the button on the map ---------- */
var btn = document.createElement('button');
btn.type = 'button'; btn.id = 'tradeBtn'; btn.className = 'rbtn rtradebtn'; btn.hidden = true;
$('mapbox').appendChild(btn);
function renderBtn() {
  var list = here();
  btn.hidden = !READY || !list.length || !R.shop.owned().length;
  /* With no Praise button under it (no mana tables yet) it takes the corner itself. */
  btn.classList.toggle('is-low',!$('praiseBtn') || $('praiseBtn').hidden);
  if (!btn.hidden) btn.textContent = 'Give or swap with '+(list.length === 1 ? first(list[0].name) : 'somebody here');
}

/* ---------- the window ---------- */
var dlg = null, pick = {to:0,item:'',kind:'gift'};
function open() {
  if (!here().length) return;
  if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'tradeDlg'; dlg.setAttribute('aria-labelledby','tradeTitle');
    /* Drawn into a box of its own, never the window itself (see toastHome in assets/realm.js). */
    dlg.innerHTML = '<div id="tradeBody"></div>'; document.body.appendChild(dlg);
    dlg.addEventListener('click',function (e) { var t = e.target, b;
      if (t.closest('[data-tradeclose]')) dlg.close();
      else if ((b = t.closest('[data-tradeto]'))) { pick.to = +b.getAttribute('data-tradeto'); draw(); }
      else if ((b = t.closest('[data-tradeitem]'))) { pick.item = b.getAttribute('data-tradeitem'); draw(); }
      else if ((b = t.closest('[data-tradekind]'))) { pick.kind = b.getAttribute('data-tradekind'); draw(); } });
    dlg.addEventListener('submit',function (e) { e.preventDefault(); offer(); }); }
  if (dlg.open) return;
  draw(); dlg.showModal();
}
function draw() {
  var list = here(), own = R.shop.owned(), h = '<h2 id="tradeTitle">Give or swap</h2><form id="tradeForm">';
  var close = '<div class="rdacts"><button type="button" class="rbtn" data-tradeclose="1">Close</button></div></form>';
  if (!list.length) { $('tradeBody').innerHTML = h+'<p>Nobody is standing here with you any more. Things change hands face to face.</p>'+close; return; }
  if (!own.length) { $('tradeBody').innerHTML = h+'<p>You own nothing from the merchant yet. Only what was bought can be given or swapped.</p>'+close; return; }
  if (!list.some(function (q) { return q.id === pick.to; })) pick.to = list[0].id;
  if (own.indexOf(pick.item) < 0) pick.item = own[0];
  var who = first(list.filter(function (q) { return q.id === pick.to; })[0].name);
  h += '<div class="rpfield"><span>Who</span><div class="rchips">';
  list.forEach(function (q) { h += '<button type="button" class="rchip'+(q.id === pick.to ? ' is-on' : '')+'" data-tradeto="'+q.id+'" aria-pressed="'+(q.id === pick.to)+'">'+esc(q.name)+'</button>'; });
  h += '</div></div><div class="rpfield"><span>Which of your things</span><div class="rchips">';
  own.forEach(function (id) { h += '<button type="button" class="rchip'+(id === pick.item ? ' is-on' : '')+'" data-tradeitem="'+id+'" aria-pressed="'+(id === pick.item)+'">'+esc(name(id))+'</button>'; });
  h += '</div></div><div class="rpfield"><span>As</span><div class="rchips">'
    +'<button type="button" class="rchip'+(pick.kind === 'gift' ? ' is-on' : '')+'" data-tradekind="gift" aria-pressed="'+(pick.kind === 'gift')+'">A gift</button>'
    +'<button type="button" class="rchip'+(pick.kind === 'swap' ? ' is-on' : '')+'" data-tradekind="swap" aria-pressed="'+(pick.kind === 'swap')+'">A swap</button></div>'
    +'<small>'+(pick.kind === 'gift'
      ? esc(who)+' is asked whether to accept. If yes, '+esc(the(pick.item))+' is theirs and no longer yours.'
      : esc(who)+' chooses one of their own things to give back, and you then agree or not. Nothing moves until you both have.')+'</small></div>'
    +'<small>Only the two of you see this. It ends by itself after five minutes, or if one of you walks away.</small>'
    +'<div class="rdacts"><button type="button" class="rbtn" data-tradeclose="1">Cancel</button><button class="rbtn rprimary" id="tradeGo">Offer</button></div></form>';
  $('tradeBody').innerHTML = h;
}
function offer() {
  var go = $('tradeGo'); if (!go || go.disabled) return;
  go.disabled = true;
  /* send() shows whatever the server refuses, and takes in the new map, which carries the offer. */
  R.send({offer:pick.to,item:pick.item,kind:pick.kind}).then(function (j) {
    if (j && j.offered) { if (dlg.open) dlg.close(); } else if ($('tradeGo')) $('tradeGo').disabled = false;
    if (!j) R.toast('The offer could not be made. Try again in a moment.');
  });
}
btn.addEventListener('click',open);

/* ---------- the notices ---------- */
/* Each offer is told once per state it reaches. What had already ENDED when the page loaded is not
   news; an offer still waiting for me is, or I could never answer it after a reload. */
var seen = {}, firstNews = true, boxes = {};
function unbox(id) { if (boxes[id]) { boxes[id].remove(); delete boxes[id]; } }
function box(o, html) {
  unbox(o.id);
  var t = document.createElement('div'); t.className = 'rtoast roffer'; t.setAttribute('data-offerbox',o.id); t.innerHTML = html;
  R.toastHome().appendChild(t); boxes[o.id] = t;
  setTimeout(function () { if (boxes[o.id] === t) unbox(o.id); },300000);
}
function act(o, say, label, primary, item) { return '<button type="button" class="rbtn rsmall'+(primary ? ' rprimary' : '')+'" data-offeranswer="'+o.id+'" data-say="'+say+'"'+(item ? ' data-item="'+item+'"' : '')+'>'+esc(label)+'</button>'; }
function news(off) {
  if (!off) return;
  READY = !!off.ready;
  var ended = false;
  (off.list || []).forEach(function (o) {
    var key = o.id+':'+o.status, who = esc(o.name); if (seen[key]) return; seen[key] = true;
    var waiting = o.status === 'open' || o.status === 'answered';
    if (firstNews && !waiting) return;
    if (o.status === 'open' && !o.mine) {
      if (o.kind === 'gift') box(o,'<b>'+who+' wants to give you '+esc(the(o.give))+'</b><div class="racts">'+act(o,'accept','Accept',true)+act(o,'decline','No thanks')+'</div>');
      else { /* A swap: I choose what I give back, from my own things. */
        var mine = R.shop.owned().filter(function (id) { return id !== o.give; });
        box(o,'<b>'+who+' offers '+esc(the(o.give))+' for a swap</b>'+(mine.length
          ? '<span>Choose what you give back. '+who+' then agrees or not.</span><div class="racts">'+mine.map(function (id) { return act(o,'accept',name(id),true,id); }).join('')+act(o,'decline','No thanks')+'</div>'
          : '<span>You own nothing from the merchant to give back.</span><div class="racts">'+act(o,'decline','No thanks')+'</div>')); }
    }
    else if (o.status === 'open' && o.mine) { if (!firstNews) R.toast('Offered. Waiting for '+o.name+' to answer.'); }
    else if (o.status === 'answered' && o.mine) box(o,'<b>'+who+' offers '+esc(the(o.back))+' for your '+esc(name(o.give).toLowerCase())+'</b><div class="racts">'+act(o,'agree','Agree',true)+act(o,'cancel','No')+'</div>');
    else if (o.status === 'answered') { unbox(o.id); R.toast('Waiting for '+o.name+' to agree to the swap.'); }
    else { unbox(o.id); ended = true;
      if (o.status === 'done') {
        var got = o.mine ? o.back : o.give, gave = o.mine ? o.give : o.back;
        if (o.kind === 'gift') R.toast(o.mine ? o.name+' accepted. '+cap(the(o.give))+' is now '+o.name+'\'s.' : cap(the(o.give))+' is yours, from '+o.name+'. '+where(o.give));
        else R.toast('Swapped with '+o.name+'. '+cap(the(got))+' is yours, and '+the(gave)+' is now '+o.name+'\'s. '+where(got));
      }
      else if (o.status === 'declined') { if (o.mine) R.toast(o.name+' said no thanks. Nothing moved.'); }
      else if (o.status === 'cancelled') { if (!o.mine) R.toast('The offer from '+o.name+' ended. Nothing moved.'); }
      else if (o.status === 'gone') R.toast((o.mine ? 'Your offer to '+o.name : 'The offer from '+o.name)+' ran out. Nothing moved.');
    }
  });
  firstNews = false;
  /* Something changed hands, or did not: ask for the map again, so what I own, what I wear and my
     office are as the server now has them. */
  if (ended && R.refresh) R.refresh();
  renderBtn();
}
document.addEventListener('click',function (e) {
  var b = e.target.closest ? e.target.closest('[data-offeranswer]') : null; if (!b || b.disabled) return;
  var id = +b.getAttribute('data-offeranswer'), bx = b.closest('[data-offerbox]');
  if (bx) Array.prototype.forEach.call(bx.querySelectorAll('button'),function (x) { x.disabled = true; });
  R.send({offer_answer:id,say:b.getAttribute('data-say'),item:b.getAttribute('data-item') || ''}).then(function (j) {
    /* Refused (somebody already has one, or walked away): the buttons come back, unless it ended. */
    if (!j || !j.offered) { if (bx) Array.prototype.forEach.call(bx.querySelectorAll('button'),function (x) { x.disabled = false; }); }
    if (!j) R.toast('That could not be done. Try again in a moment.');
  });
});

/* The map brings my offers with every beat; the unread counts and an open conversation bring them
   every few seconds (R.onOffers, called by assets/realm.js). */
var prev = R.onState, hereSig = '';
R.onState = function (st) {
  if (prev) prev(st);
  ST = st; news(st.offers); renderBtn();
  var sig = here().map(function (q) { return q.id; }).join(',')+'|'+R.shop.owned().join(',');
  if (dlg && dlg.open && sig !== hereSig) draw();
  hereSig = sig;
};
R.onOffers = news;

news(ST.offers); renderBtn();
})();
