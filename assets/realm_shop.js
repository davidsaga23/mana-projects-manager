/* The Realm: the merchant's counter (David, 2026-11-13, migration 091).

   The rules are in includes/realm_shop.php and are checked there; this file only shows them.
   Like assets/realm_praise.js it is its own file, so the map's script (assets/realm.js) stays
   small: that one draws the merchant in the Tavern and lays a see-through button over him, and
   hands over, on window.REALM, the notices (toast), the one way of asking the server (send), its
   two drawings (sprite, thingStamp) and shopOwned, which tells it what is now mine.

   WHAT IS HERE.
   - The counter: the window the merchant opens. Eight things, each with its picture, its price and
     a Buy button; my own coins at the top. "The shop is a merchant in the Tavern" (David).
   - Buying asks first: "This cannot be undone." There is no selling back, because a thing that
     could be sold back would let gold pass from one person to another through a gift.
   - What I own, for the chest (chestExtra, asked for by assets/realm_praise.js).
   Giving and swapping what was bought is assets/realm_trade.js.

   PRIVATE. My coins and my list are asked for with no id, and nobody else's ever is. Others see
   what I wear and what stands in my office, nothing more. Never linked to pay. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.state || !R.send || !R.sprite || !document.getElementById('mapbox')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

/* The same ids as REALM_SHOP, in the order they are laid out: cheapest first. `the` is how a
   sentence names it, `where` is where it is found once it is mine. The prices come from the server. */
var ITEMS = [
  {id:'trophy', n:'Shield and swords', the:'the shield and swords', what:'For your office wall'},
  {id:'crown',  n:'Crown',             the:'the crown',             what:'Headwear'},
  {id:'cap',    n:'Feathered cap',     the:'the feathered cap',     what:'Headwear, in the colour of your cloak'},
  {id:'orb',    n:'Crystal ball',      the:'the crystal ball',      what:'For your office'},
  {id:'fox',    n:'Fox',               the:'the fox',               what:'A companion'},
  {id:'starry', n:'Starry cloak',      the:'the starry cloak',      what:'A cloak, and the colour of your roof'},
  {id:'throne', n:'Throne',            the:'the throne',            what:'For your office'},
  {id:'dragon', n:'Small dragon',      the:'the small dragon',      what:'A companion'}
];
var itemOf = function (id) { for (var i = 0; i < ITEMS.length; i++) if (ITEMS[i].id === id) return ITEMS[i]; return null; };

var ST = R.state;
/* What is known of the shop. The map brings the prices and what I own; the counter adds my coins. */
var SHOP = {ready:false,items:{},owned:[],gold:null,asking:''};
function take(s) { if (!s) return; SHOP.ready = !!s.ready; SHOP.items = s.items || {}; SHOP.owned = s.owned || []; if (typeof s.gold === 'number') SHOP.gold = s.gold; }
take(ST.shop);
function me() { for (var i = 0; i < ST.people.length; i++) if (ST.people[i].me) return ST.people[i]; return null; }
function kind(id) { return (SHOP.items[id] || [])[0] || ''; }
function cost(id) { return +((SHOP.items[id] || [])[1] || 0); }
function mine(id) { return SHOP.owned.indexOf(id) >= 0; }
function coins(n) { return n+' '+(n === 1 ? 'coin' : 'coins'); }

/* ---------- the window ---------- */
var dlg = null;
function open() {
  if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'shopDlg'; dlg.setAttribute('aria-labelledby','shopTitle');
    /* Drawn into a box of its own, never the window itself: the notices live inside whichever window
       is open (toastHome in assets/realm.js), and rewriting the window would throw them away. */
    dlg.innerHTML = '<h2 id="shopTitle">The merchant</h2><div id="shopBody"></div>'; document.body.appendChild(dlg);
    dlg.addEventListener('click',function (e) { var t = e.target, b;
      if (t.closest('[data-shopclose]')) dlg.close();
      else if ((b = t.closest('[data-shopask]'))) { SHOP.asking = b.getAttribute('data-shopask'); draw(); var y = dlg.querySelector('[data-shopbuy]'); if (y) y.focus(); }
      else if (t.closest('[data-shopno]')) { SHOP.asking = ''; draw(); }
      else if ((b = t.closest('[data-shopbuy]'))) buy(b.getAttribute('data-shopbuy'),b); }); }
  if (dlg.open) return;
  SHOP.asking = ''; SHOP.gold = null; draw(); dlg.showModal();
  fetch(R.api+'?shop=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (j && j.shop) { take(j.shop); R.shopOwned(SHOP.owned); } else SHOP.gold = -1; draw(); })
    .catch(function () { SHOP.gold = -1; draw(); });
}
function draw() {
  var body = $('shopBody'); if (!body) return;
  var close = '<div class="rdacts"><button type="button" class="rbtn" data-shopclose="1">Close</button></div>';
  if (!SHOP.ready && SHOP.gold !== null) { body.innerHTML = '<p>The merchant is still unpacking. The shop needs a database update first: an administrator opens the updates page once.</p>'+close; return; }
  if (SHOP.gold === null) { body.innerHTML = '<p>The merchant is laying out the wares…</p>'+close; return; }
  if (SHOP.gold < 0) { body.innerHTML = '<p>The merchant could not be reached. Try again in a moment.</p>'+close; return; }
  var h = '<p class="rshopgold"><b>You have '+coins(SHOP.gold)+'.</b> <span>Nobody else can see this.</span></p><ul class="rshop">';
  ITEMS.forEach(function (x) {
    if (!SHOP.items[x.id]) return;
    var c = cost(x.id), own = mine(x.id), short = c-SHOP.gold, act;
    if (own) act = '<span class="rshopyours">Yours</span>';
    else if (SHOP.asking === x.id) act = '<span class="rshopask">Buy '+esc(x.the)+' for '+coins(c)+'? This cannot be undone.</span><span class="rshopbtns"><button type="button" class="rbtn rsmall" data-shopno="1">No</button><button type="button" class="rbtn rprimary rsmall" data-shopbuy="'+x.id+'">Yes, buy it</button></span>';
    else act = '<button type="button" class="rbtn rsmall'+(short > 0 ? '' : ' rprimary')+'" data-shopask="'+x.id+'"'+(short > 0 ? ' disabled title="'+coins(short)+' more to go"' : '')+'>Buy</button>';
    h += '<li class="rshopitem'+(own ? ' is-mine' : '')+(SHOP.asking === x.id ? ' is-asking' : '')+'"><canvas data-shoppic="'+x.id+'" width="120" height="120" role="img" aria-label="'+esc(x.n)+'"></canvas>'
      +'<span class="rshopname"><b>'+esc(x.n)+'</b><small>'+esc(x.what)+'</small></span><span class="rshopcost">'+(own ? '' : coins(c))+'</span><span class="rshopact">'+act+'</span></li>';
  });
  body.innerHTML = h+'</ul><small class="rshopnote">One of each. What you buy cannot be sold back, but it can be given to somebody or swapped, face to face. Office things are then in Decorate, clothes and companions in Your character.</small>'+close;
  pictures();
}
/* Each picture is the map's own drawing: a thing as it stands in an office, or my character
   wearing the piece. Painted at twice the size it is shown, so the dots stay sharp. */
function pictures() {
  var m = me() || {cloak:'mana',hat:'none',pet:'none'};
  Array.prototype.forEach.call(dlg.querySelectorAll('canvas[data-shoppic]'),function (cv) {
    var id = cv.getAttribute('data-shoppic'), k = kind(id), c = cv.getContext('2d');
    c.setTransform(1,0,0,1,0,0); c.clearRect(0,0,120,120); c.imageSmoothingEnabled = false;
    if (k === 'thing') { var st = R.thingStamp(id,m.cloak).cv, s = Math.max(1,Math.min(3,Math.floor(Math.min(112/st.width,112/st.height))));
      c.drawImage(st,Math.round((120-st.width*s)/2),Math.round((120-st.height*s)/2),st.width*s,st.height*s); return; }
    var L = {cloak:m.cloak,hat:m.hat,pet:k === 'pet' ? id : 'none'};
    if (k === 'hat') L.hat = id; else if (k === 'cloak') L.cloak = id;
    c.setTransform(2,0,0,2,0,0); R.sprite(c,k === 'pet' ? 22 : 30,58,L,0);
  });
}
function buy(id, btn) {
  var x = itemOf(id); if (!x || btn.disabled) return;
  btn.disabled = true;
  /* send() shows whatever the server refuses. The answer is the counter again, with my coins. */
  R.send({buy:id}).then(function (j) {
    SHOP.asking = '';
    if (!j) { R.toast('The merchant could not be reached. No coins were taken. Try again in a moment.'); draw(); return; }
    if (j.shop) { take(j.shop); R.shopOwned(SHOP.owned); }
    if (j.bought) R.toast(x.the.charAt(0).toUpperCase()+x.the.slice(1)+' is yours. '+(kind(id) === 'thing' ? 'Find it in your office, under Decorate.' : 'Put it on in Your character.'));
    draw();
  });
}
R.openShop = open;
/* What assets/realm_trade.js borrows: the names of the things, and what I own. */
R.shop = {item:itemOf, kind:kind, owned:function () { return SHOP.ready ? SHOP.owned.slice() : []; }};

/* The map says what I own with every beat; the window is drawn again only when that changed, so a
   question being answered is not wiped by the heartbeat. */
var prev = R.onState;
R.onState = function (st) {
  if (prev) prev(st);
  ST = st; var was = SHOP.owned.join(','); take(st.shop);
  if (dlg && dlg.open && was !== SHOP.owned.join(',')) draw();
};

/* ---------- the chest ---------- */
/* What I own, under my coins. Asked for by the chest in assets/realm_praise.js. */
R.chestExtra = function () {
  if (!SHOP.ready) return '';
  var names = ITEMS.filter(function (x) { return mine(x.id); }).map(function (x) { return x.n; });
  return '<p class="rowned"><b>What you own:</b> '+(names.length ? esc(names.join(', '))+'.' : 'nothing bought yet. The merchant in the Tavern sells things for your office, headwear, companions and a cloak.')+'</p>';
};
})();
