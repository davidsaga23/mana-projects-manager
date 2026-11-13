/* The Realm, stage 1 (David, 2026-11-12): the village map, who is here, doors, your character, and
   walking into a building to see who is inside. Data and rules come from realm_api.php
   (includes/realm.php); this file only draws and moves.

   HOW IT IS DRAWN. "More detail, like an old handheld RPG ... houses you bump into, characters hidden
   behind them." The ground is painted once onto a bitmap as 16-unit tiles. Every tall thing (houses,
   pines, peaks, the volcano) is its own small picture, and each frame they are drawn back to front
   together with the characters, so whoever stands behind a house is covered by it. An SVG laid over
   the canvas carries only the labels and the click areas. All the art is drawn by this code and is our
   own; the studio's artists can replace any of it later.

   WHAT OTHER PEOPLE SEE OF YOU. At first only where you ARRIVED ("let's start only where they have
   arrived", 2026-11-12). Since 2026-11-13 they see you WALK, on the map and inside a room ("let's
   make see other people walk a feature across the board"): see "seeing each other walk" below, which
   also says what it costs the shared hosting and how that is kept small.

   A CHARACTER ON THE MAP MEANS THAT PERSON IS HERE NOW. Resting (dimmed, with a Z) after 25 minutes
   without touching the page; away (dark house, no character) once the page is closed. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.state) return;
var NS = 'http://www.w3.org/2000/svg';
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

/* ---------- paint ---------- */
var PAL = {out:'#141a28',grassA:'#2e6b4b',grassB:'#2a6446',tuft:'#43906a',tuftD:'#1f5038',flowerA:'#ffd97a',flowerB:'#ff9fc0',
  path:'#a98f63',pathD:'#8a7350',pathL:'#c4aa7a',water:'#2f6fb5',waterD:'#24589a',waterL:'#7ab8ee',deep:'#1b4379',
  ash:'#4b3b41',ashD:'#3d2f35',ashL:'#6d4c4a',lava:'#ff7a59',lavaL:'#ffc66b',hot:'#fff6d0',cone:'#614650',coneD:'#48333c',
  scree:'#4a5670',screeD:'#434e66',rock:'#66718f',rockD:'#485270',rockL:'#8d99b6',snow:'#eef2fb',snowD:'#c3cde6',
  forest:'#215a45',forestD:'#1a4a38',pine:'#1f6e4e',pineD:'#134b37',pineL:'#42986c',trunk:'#6b4a32',
  moor:'#3a5a52',moorD:'#33514a',moorL:'#9a7fc4',
  wall:'#d2c8b2',wallD:'#aba18d',stone:'#8d95aa',stoneD:'#666e86',wood:'#7a5638',woodD:'#573c27',marble:'#dfe3ee',marbleD:'#b9c0d4',
  cobble:'#7f8699',cobbleL:'#8f96a8',cobbleH:'#a4abbd',iron:'#3b4256',
  win:'#ffd36e',winD:'#2a3350',door:'#4a3020',skin:'#f3d3b0',cheek:'#f0a898',mouth:'#b06a58',hair:'#5a3a26',fur:'#9aa1b4',owl:'#a58b6a',owlL:'#c9b08a',boot:'#3a2a20',
  smoke:'#c9d1e0',glow:'#e2b35c',shadow:'rgba(0,0,0,.28)',mist:'rgba(176,186,206,.3)',dim:'rgba(0,0,0,.16)',lift:'rgba(255,255,255,.12)',veil:'#191919',ground:'#101010',
  text:'#ededed',textMuted:'rgba(255,255,255,.6)',primary:'#5fb2ff',primaryBorder:'rgba(95,178,255,.45)',plate:'rgba(16,16,16,.88)',ok:'#26fe89',warn:'#f0c33c',danger:'#ff6b6b'};
var TINT = {candle:'#ffc66b',mana:'#5fb2ff',spell:'#26fe89',ember:'#ff7a59',violet:'#b592ff',rose:'#ff8fb3',teal:'#4fd6c8',starry:'#3d4494'};
var CLOAKS = {candle:'Gold',mana:'Blue',spell:'Green',ember:'Ember',violet:'Violet',rose:'Rose',teal:'Teal',starry:'Starry'};
var HATS = {none:'Bare',hat:'Pointed hat',hood:'Hood',crown:'Crown',cap:'Feathered cap'};
var PETS = {none:'None',owl:'Owl',cat:'Cat',fox:'Fox',dragon:'Small dragon'};
/* WHAT THE MERCHANT SELLS TO WEAR (David, 2026-11-13, migration 092). The same ids as REALM_SHOP in
   includes/realm_shop.php, which holds the prices and is the lock: this list only tells the screen
   which of the choices above are bought, so that it never offers one as free. The three office
   things the merchant sells are marked gold in THINGS, further down. */
var PAID = {crown:1,cap:1,fox:1,dragon:1,starry:1};
var KINDS = {study:'Cottage',tower:'Tower',forge:'Forge'};
var DOORS = {open:'Door open',ajar:'Door ajar',sealed:'Sealed'};
var HIT = {tower:[22,122],study:[32,68],forge:[38,80],tavern:[58,80],hall:[58,80],tent:[30,62]};
var SIDE = {tower:38,study:46,forge:52};
var DOORX = {tower:0,study:12,forge:20,tavern:0,hall:0,tent:0};
var FULL = {x:0,y:0,w:1600,h:1000}, VILLAGE = {x:330,y:300,w:940,h:587.5};
var MTS = [[150,300,70],[515,304,62],[235,300,115],[440,300,100],[335,292,145]];

/* The five pieces of landscape. A show's war room is pitched at `camp`. */
var LANDS = {
  peaks:{title:'Peaks',camp:[330,345],label:[335,120],mist:[335,235,262,132],sign:[480,330]},
  wood:{title:'Wood',camp:[1290,345],label:[1300,100],mist:[1300,228,262,124],sign:[1120,330]},
  ash:{title:'Ashlands',camp:[1190,815],label:[1390,884],mist:[1345,790,267,147],sign:[1130,900]},
  lake:{title:'Lake',camp:[236,712],label:[290,800],mist:[290,800,177,88],sign:[290,896]},
  moor:{title:'Moor',camp:[800,262],label:[800,196],mist:[800,205,214,76],sign:[620,250]}
};
var STREETS = [500,628,756,884];
/* The village plots, nearest the square first, so the village grows outward. Must hold REALM_SLOTS
   (26) plots: 4 rows of 7, less the two the Council Hall and the Tavern stand on. */
var PLOTS = (function () {
  var out = [], rows = [462,590,718,846];
  for (var r = 0; r < 4; r++) for (var c = 0; c < 7; c++) { if (c === 3 && r < 2) continue; out.push({x:512+c*96,y:rows[r],street:STREETS[r]}); }
  out.sort(function (a, b) { return Math.hypot(a.x-800,a.y-600) - Math.hypot(b.x-800,b.y-600); });
  return out;
})();

/* ---------- state ---------- */
var ST = R.state;
var S = {view:{x:FULL.x,y:FULL.y,w:FULL.w,h:FULL.h},pos:{x:800,y:640},here:null,inside:null,sel:null,walk:null,frame:0,fade:0,touched:true,raf:0,lt:0,enter:null};
var B = {}, BLIST = [];

function person(id) { for (var i = 0; i < ST.people.length; i++) if (ST.people[i].id === id) return ST.people[i]; return null; }
function me() { return person(ST.me); }
/* What I own, and what a thing costs: ST.shop travels with the map and is about me alone. Before
   migration 092 I own nothing and no price is known. */
function owns(id) { return !!(ST.shop && ST.shop.owned && ST.shop.owned.indexOf(id) >= 0); }
function price(id) { var it = ST.shop && ST.shop.items && ST.shop.items[id]; return it ? +it[1] : 0; }
function firstName(n) { return String(n).split(' ')[0]; }

/* Every building on the map, by the same id the server uses for a place. */
function rebuildBuildings() {
  B = {}; BLIST = [];
  var add = function (b) { B[b.id] = b; BLIST.push(b); };
  add({id:'council',kind:'hall',x:800,y:462,tint:'candle',name:'Council Hall',lit:true,place:true});
  add({id:'tavern',kind:'tavern',x:800,y:590,tint:'candle',name:'The Tavern',lit:true,place:true});
  ST.lands.forEach(function (l) {
    var L = LANDS[l.land]; if (!L || !l.claimed) return;
    add({id:'land:'+l.land,kind:'tent',x:L.camp[0],y:L.camp[1],tint:{peaks:'mana',wood:'violet',ash:'ember',lake:'teal',moor:'rose'}[l.land],name:l.name+' war room',lit:true,place:true});
  });
  ST.people.forEach(function (p) {
    var pl = PLOTS[p.slot]; if (!pl) return;
    add({id:'user:'+p.id,kind:p.kind,x:pl.x,y:pl.y,tint:p.cloak,name:p.me?firstName(p.name)+' (you)':firstName(p.name),lit:p.state!=='away',door:p.door,owner:p,street:pl.street});
  });
}
function spot(id) {
  var o = B[id]; if (!o) return null;
  return {x:o.x-({tower:36,tavern:72,hall:72,tent:44}[o.kind]||52),y:o.y+4};
}
function title(id) {
  var o = B[id]; if (!o) return '';
  if (!o.owner) return o.name;
  return o.owner.me ? 'Your house' : o.owner.name + (/s$/i.test(o.owner.name) ? "' house" : "'s house");
}
/* May I go INSIDE? The server asks the same question again (realm_arrive). */
function mayEnter(id) {
  var o = B[id]; if (!o) return 'That place is gone.';
  if (!o.owner || o.owner.me) return '';
  if (o.owner.state === 'away') return 'Nobody is home.';
  if (o.owner.door === 'sealed') return 'The door is sealed. They are not to be disturbed.';
  if (o.owner.door !== 'open' && !PASS[o.owner.id]) return 'The door is ajar. Knock, and wait to be let in.';
  return '';
}

/* ---------- small drawing tools ---------- */
function shade(hex, a) { var n = parseInt(hex.slice(1),16), f = function (v) { return Math.max(0,Math.min(255,v+a)); }; return '#'+((1<<24)|(f(n>>16)<<16)|(f((n>>8)&255)<<8)|f(n&255)).toString(16).slice(1); }
function hsh(x, y, i) { var n = Math.imul(x+1,374761393)^Math.imul(y+1,668265263)^Math.imul(i+1,2246822519); n = Math.imul(n^(n>>>13),1274126177); return ((n^(n>>>16))>>>0)/4294967296; }
function inEl(x, y, cx, cy, rx, ry) { return Math.pow((x-cx)/rx,2)+Math.pow((y-cy)/ry,2) < 1; }
var g = null;
function P1(x, y, w, h, c) { g.fillStyle = c; g.fillRect(Math.round(x),Math.round(y),Math.round(w),Math.round(h)); }
/* A group of boxes with one dark outline round the lot. */
function parts(list) { var i; for (i = 0; i < list.length; i++) P1(list[i][0]-1,list[i][1]-1,list[i][2]+2,list[i][3]+2,PAL.out); for (i = 0; i < list.length; i++) P1(list[i][0],list[i][1],list[i][2],list[i][3],list[i][4]); }
function stamp(w, h, ox, oy, fn) { var c = document.createElement('canvas'); c.width = w; c.height = h; var prev = g; g = c.getContext('2d'); g.translate(ox,oy); fn(); g = prev; return {cv:c,ox:ox,oy:oy}; }

/* ---------- the ground ---------- */
/* g grass, p path, w water, b bridge, a ash, f forest floor, r rock, m moor. */
function tileMap() {
  var W = 100, H = 63, T = new Array(W*H), tx, ty, i;
  for (ty = 0; ty < H; ty++) for (tx = 0; tx < W; tx++) {
    var x = tx*16+8, y = ty*16+8;
    T[ty*W+tx] = inEl(x,y,290,800,165,76)?'w':inEl(x,y,1345,790,255,135)?'a':inEl(x,y,1300,228,250,112)?'f':inEl(x,y,335,235,250,120)?'r':inEl(x,y,800,205,200,62)?'m':'g';
  }
  var put = function (x, y, v) { var a = Math.floor(x/16), b = Math.floor(y/16); if (a<0||b<0||a>=W||b>=H) return; var k = b*W+a;
    if (v==='w') { if (T[k]!=='p'&&T[k]!=='b') T[k]='w'; } else T[k] = (T[k]==='w'||T[k]==='b')?'b':'p'; };
  var cubic = function (a, b, c, d) { for (var n = 0; n <= 120; n++) { var t = n/120, u = 1-t, x = u*u*u*a[0]+3*u*u*t*b[0]+3*u*t*t*c[0]+t*t*t*d[0], y = u*u*u*a[1]+3*u*u*t*b[1]+3*u*t*t*c[1]+t*t*t*d[1]; put(x,y,'w'); put(x+16,y,'w'); } };
  cubic([385,300],[450,400],[365,520],[405,620]); cubic([405,620],[445,720],[350,710],[330,738]);
  var line = function (x1, y1, x2, y2) { var n = Math.ceil(Math.hypot(x2-x1,y2-y1)/6); for (var k = 0; k <= n; k++) put(x1+(x2-x1)*k/n,y1+(y2-y1)*k/n,'p'); };
  var quad = function (a, b, c) { for (var n = 0; n <= 100; n++) { var t = n/100, u = 1-t; put(u*u*a[0]+2*u*t*b[0]+t*t*c[0],u*u*a[1]+2*u*t*b[1]+t*t*c[1],'p'); } };
  STREETS.forEach(function (sy) { line(480,sy,1120,sy); });
  line(656,500,656,884); line(944,500,944,884); line(800,468,800,500); line(800,596,800,628);
  BLIST.forEach(function (o) { if (o.owner) line(o.x,o.y+6,o.x,o.street); });
  quad([480,500],[420,440],[362,368]); quad([1120,500],[1190,440],[1262,368]); quad([1120,640],[1160,730],[1172,814]);
  quad([480,756],[400,740],[268,718]); line(656,500,656,330); quad([656,330],[680,285],[770,268]);
  /* A bridge spans the whole river, so there is always a way across on foot. */
  for (i = 0; i < W*H; i++) if (T[i]==='b') { var row = Math.floor(i/W), k;
    for (k = i-1; k >= row*W && T[k]==='w'; k--) T[k]='B'; for (k = i+1; k < (row+1)*W && T[k]==='w'; k++) T[k]='B'; }
  for (i = 0; i < W*H; i++) if (T[i]==='B') T[i]='b';
  return {W:W,H:H,T:T};
}
function bushArt(x, y, berry) { parts([[x-7,y-6,14,6,PAL.pine],[x-5,y-10,10,4,PAL.pine]]); P1(x-5,y-9,4,2,PAL.pineL); P1(x-6,y-5,3,2,PAL.pineL); P1(x+2,y-4,4,3,PAL.pineD); P1(x+1,y-8,2,1,PAL.pineL); if (berry) { P1(x+3,y-7,2,2,PAL.flowerB); P1(x-2,y-3,2,2,PAL.flowerB); } }
function boulderArt(x, y) { parts([[x-6,y-6,12,6,PAL.rock],[x-4,y-9,8,3,PAL.rock]]); P1(x-4,y-8,4,2,PAL.rockL); P1(x-5,y-5,3,1,PAL.rockL); P1(x+1,y-4,5,4,PAL.rockD); }
function signArt(x, y) { parts([[x-1,y-16,3,16,PAL.wood],[x-11,y-25,23,10,PAL.wallD]]); P1(x-8,y-22,17,1,PAL.woodD); P1(x-8,y-19,11,1,PAL.woodD); }
function pineArt() {
  parts([[-2,-7,4,7,PAL.trunk]]); P1(0,-7,2,7,PAL.woodD);
  [[-5,15,6,26],[-16,13,4,20],[-26,13,1,14]].forEach(function (t) {
    var base = t[0], h = t[1], wt = t[2], wb = t[3], r, hw = function (n) { return Math.max(1,Math.round((wt+(wb-wt)*n/(h-1))/2)); };
    for (r = 0; r < h; r++) P1(-hw(r)-1,base-h+r,hw(r)*2+2,1,PAL.out);
    P1(-hw(h-1),base,hw(h-1)*2,1,PAL.out);
    for (r = 0; r < h; r++) { var w = hw(r), yy = base-h+r, q = Math.max(1,Math.round(w*.45));
      P1(-w,yy,w*2,1,PAL.pine); P1(-w,yy,q,1,PAL.pineL); P1(w-q,yy,q,1,PAL.pineD);
      if (r%3===1&&w>3) P1(-w+q+Math.floor(hsh(r,base,1)*w),yy,2,1,PAL.pineD); }
  });
  P1(-1,-40,2,1,PAL.out);
}
function mountainArt(h) {
  var sl = Math.floor(h*.28), r, k, i;
  for (r = 0; r < h; r++) { var hw = Math.round(r*.7)+1, y = -h+r, top = r < sl; P1(-hw-1,y,hw*2+2,1,PAL.out); P1(-hw,y,hw,1,top?PAL.snow:PAL.rock); P1(0,y,hw,1,top?PAL.snowD:PAL.rockD); }
  var sw = Math.round(sl*.7);
  for (k = -sw; k < sw; k += 3) P1(k,-h+sl,2,2+Math.floor(hsh(h,k+99,1)*5),k<0?PAL.snow:PAL.snowD);
  for (i = 0; i < h/4; i++) { var rr = Math.floor(h*.34+hsh(h,i,1)*h*.6), w2 = Math.round(rr*.7),
    xl = -w2+3+Math.floor(hsh(h,i,2)*Math.max(1,w2-8)), xr = 3+Math.floor(hsh(h,i,3)*Math.max(1,w2-8)), j;
    for (j = 0; j < 5; j++) { P1(xl+j,-h+rr-j,1,2,PAL.rockL); P1(xr+j,-h+rr+j,1,2,shade(PAL.rockD,-22)); } }
}
function volcanoArt() {
  var r, xo = function (n) { return Math.round(4*Math.sin(n/9)+n/16); };
  for (r = 0; r < 140; r++) { var hw = 30+Math.round(r*.62); P1(-hw-1,r,hw*2+2,1,PAL.out); P1(-hw,r,hw,1,PAL.cone); P1(0,r,hw,1,PAL.coneD);
    if (hsh(7,r,1)<.5) P1(-hw+3+hsh(7,r,2)*hw*.8,r,4,1,PAL.ashL); if (hsh(7,r,3)<.4) P1(4+hsh(7,r,4)*hw*.8,r,4,1,PAL.ashD); }
  P1(-118,139,236,1,PAL.out);
  parts([[-28,-5,56,6,PAL.lava]]); P1(-18,-5,36,2,PAL.lavaL); P1(-8,-5,16,1,PAL.hot);
  for (r = 2; r < 96; r++) P1(xo(r)-4,r,8,1,PAL.out);
  for (r = 2; r < 95; r++) { P1(xo(r)-3,r,6,1,PAL.lava); P1(xo(r)-1,r,2,1,PAL.lavaL); }
}
function paintGround(ctx, TM) {
  g = ctx; var W = TM.W, H = TM.H, T = TM.T, tx, ty, i, q;
  var at = function (x, y) { return (x<0||y<0||x>=W||y>=H)?'g':T[y*W+x]; }, wet = function (v) { return v==='w'||v==='b'; }, road = function (v) { return v==='p'||v==='b'; };
  var BASE = {g:[PAL.grassA,PAL.grassB,PAL.tuft,PAL.tuftD],a:[PAL.ash,PAL.ashD,PAL.ashL,PAL.ashD],f:[PAL.forest,PAL.forestD,PAL.pineL,PAL.forestD],r:[PAL.scree,PAL.screeD,PAL.rockL,PAL.rockD],m:[PAL.moor,PAL.moorD,PAL.tuft,PAL.moorD]};
  for (ty = 0; ty < H; ty++) for (tx = 0; tx < W; tx++) {
    var t = at(tx,ty), x = tx*16, y = ty*16, up = at(tx,ty-1), dn = at(tx,ty+1), lf = at(tx-1,ty), rt = at(tx+1,ty);
    var r = (function (a, b) { return function (n) { return hsh(a,b,n); }; })(tx,ty);
    if (t==='w') {
      P1(x,y,16,16,PAL.water);
      if (!wet(up)) { P1(x,y,16,3,PAL.waterD); P1(x,y,16,1,PAL.deep); }
      if (!wet(lf)) P1(x,y,2,16,PAL.waterD); if (!wet(rt)) P1(x+14,y,2,16,PAL.waterD);
      for (i = 0; i < 2; i++) if (r(i)<.6) { var rx = x+2+Math.floor(r(i+2)*8), ry = y+5+i*6+Math.floor(r(i+4)*3); P1(rx,ry,5,1,PAL.waterL); P1(rx+1,ry+1,3,1,PAL.waterD); }
    } else if (t==='b') {
      P1(x,y,16,16,PAL.wood); for (i = 3; i < 16; i += 4) P1(x,y+i,16,1,PAL.woodD);
      P1(x,y,16,2,PAL.out); P1(x,y+14,16,2,PAL.out); P1(x+3,y+5,1,1,PAL.pathL); P1(x+11,y+9,1,1,PAL.pathL);
    } else if (t==='p') {
      P1(x,y,16,16,PAL.path);
      for (i = 0; i < 6; i++) P1(x+Math.floor(r(i)*15),y+Math.floor(r(i+6)*15),i%3?1:2,1,i%2?PAL.pathD:PAL.pathL);
      if (!road(up)) { P1(x,y,16,1,PAL.pathL); P1(x+Math.floor(r(13)*12),y,3,1,PAL.tuft); }
      if (!road(dn)) P1(x,y+14,16,2,PAL.pathD); if (!road(lf)) P1(x,y,1,16,PAL.pathD); if (!road(rt)) P1(x+15,y,1,16,PAL.pathD);
    } else {
      var b = BASE[t], blade = t==='g'||t==='m'||t==='f';
      for (q = 0; q < 4; q++) P1(x+(q&1)*8,y+(q>>1)*8,8,8,r(20+q)<.5?b[0]:b[1]);
      for (i = 0; i < 5; i++) { var sx = x+1+Math.floor(r(i)*13), sy = y+Math.floor(r(i+5)*13);
        if (blade) { P1(sx,sy,1,3,b[2]); P1(sx+1,sy+1,1,2,b[3]); P1(sx-1,sy+1,1,2,b[2]); } else { P1(sx,sy,2,1,b[2]); P1(sx,sy+1,2,1,b[3]); } }
      if (t==='g'&&r(11)<.07) { var fx = x+3+Math.floor(r(12)*8), fy = y+3+Math.floor(r(13)*8), fc = r(14)<.5?PAL.flowerA:PAL.flowerB;
        P1(fx,fy+2,1,2,PAL.tuftD); P1(fx,fy-1,1,1,fc); P1(fx-1,fy,3,1,fc); P1(fx,fy+1,1,1,fc); P1(fx,fy,1,1,PAL.hot); }
      if (t==='m'&&r(11)<.35) { P1(x+2+Math.floor(r(12)*10),y+2+Math.floor(r(13)*10),2,2,PAL.moorL); P1(x+2+Math.floor(r(14)*10),y+2+Math.floor(r(15)*10),1,2,PAL.moorL); }
      if (t==='a'&&r(11)<.06) { P1(x+3,y+8,5,1,PAL.lava); P1(x+7,y+9,3,1,PAL.lavaL); }
    }
  }
}
var TM = null, GROUND = null, OBJ = [], HOUSES = [], GRID = null, PINE = null, PEAKS = null, CONE = null;
function nearBuilding(x, y, pad) { for (var i = 0; i < BLIST.length; i++) { var o = BLIST[i]; if (Math.abs(x-o.x) < HIT[o.kind][0]+pad && y > o.y-HIT[o.kind][1]-pad && y < o.y+34) return true; } return false; }
/* Rebuilt only when somebody new gets a house or a show takes or leaves a land. */
function buildWorld() {
  TM = tileMap();
  if (!GROUND) { GROUND = document.createElement('canvas'); GROUND.width = 1600; GROUND.height = 1008; }
  paintGround(GROUND.getContext('2d'),TM);
  PINE = PINE || stamp(30,46,15,43,pineArt);
  PEAKS = PEAKS || MTS.map(function (m) { var hw = Math.round(m[2]*.7)+3; return {x:m[0],y:m[1],st:stamp(hw*2,m[2]+2,hw,m[2]+1,function () { mountainArt(m[2]); })}; });
  CONE = CONE || {x:1385,y:790,st:stamp(244,150,122,8,volcanoArt),lift:140};
  OBJ = PEAKS.concat([CONE]);
  var W = TM.W, H = TM.H, T = TM.T, tx, ty;
  for (ty = 0; ty < H; ty++) for (tx = 0; tx < W; tx++) {
    var t = T[ty*W+tx], x = tx*16+8, y = ty*16+14, wy = ty*16+8, r = hsh(tx,ty,9), edge = tx<1||tx>98||ty<1||ty>61, town = inEl(x,wy,800,660,400,290);
    if (t==='g') { if (edge?(tx+ty)%2===0:(!town&&r<.03&&!nearBuilding(x,y,24))) OBJ.push({x:x,y:y,st:PINE}); else if (r<(town?.02:.08)&&!nearBuilding(x,y,8)) bushArt(x,y,hsh(tx,ty,10)<.3); }
    else if (t==='f') { if (ty%2===0&&(tx+(ty>>1))%2===0&&!nearBuilding(x,y,40)) OBJ.push({x:x,y:y,st:PINE}); }
    else if (t==='r') { if (wy>308&&r<.08&&!nearBuilding(x,y,8)) boulderArt(x,y); }
    else if (t==='a') { if (r<.05&&!(x>1255&&x<1515&&wy>640&&wy<800)&&!nearBuilding(x,y,8)) boulderArt(x,y); }
    else if (t==='m') { if (r<.05&&!nearBuilding(x,y,8)) bushArt(x,y,true); }
  }
  /* Land with no show on it lies under a mist, with a signpost, so nobody takes it for an active one. */
  ST.lands.forEach(function (l) {
    var L = LANDS[l.land]; if (!L || l.claimed) return;
    var m = L.mist, x, y;
    for (y = m[1]-m[3]; y < m[1]+m[3]; y += 4) for (x = m[0]-m[2]; x < m[0]+m[2]; x += 4) if (inEl(x,y,m[0],m[1],m[2],m[3])&&((x>>2)+(y>>2))%2===0) P1(x,y,4,4,PAL.mist);
    signArt(L.sign[0],L.sign[1]);
  });
  /* What cannot be walked through: water (bridges are fine), the peaks, the volcano, the foot of every building. */
  var FOOT = {tower:18,study:26,forge:30,tavern:50,hall:54,tent:24}, blk = new Uint8Array(W*H);
  for (ty = 0; ty < H; ty++) for (tx = 0; tx < W; tx++) {
    var cx = tx*16+8, cy = ty*16+8, k = T[ty*W+tx]==='w', i;
    for (i = 0; i < MTS.length; i++) if (cy>MTS[i][1]-MTS[i][2]&&cy<=MTS[i][1]&&Math.abs(cx-MTS[i][0])<=(cy-(MTS[i][1]-MTS[i][2]))*.7) k = true;
    if (cy>650&&cy<=790&&Math.abs(cx-1385)<=30+(cy-650)*.62) k = true;
    for (i = 0; i < BLIST.length; i++) if (Math.abs(cx-BLIST[i].x)<=FOOT[BLIST[i].kind]+4&&cy>=BLIST[i].y-32&&cy<=BLIST[i].y-2) k = true;
    blk[ty*W+tx] = k?1:0;
  }
  GRID = {W:W,H:H,b:blk};
}

/* ---------- buildings ---------- */
function roofRow(cx, y, w, r, col) { var d = shade(col,-38), k; P1(cx-w-1,y,w*2+2,1,PAL.out); P1(cx-w,y,w*2,1,r%4===0?d:col);
  if (r%4!==0) for (k = cx-w+((r>>2)%2?3:0); k < cx+w; k += 6) P1(k,y,1,1,d);
  P1(cx-w,y,Math.min(2,w),1,shade(col,45)); if (w>4) P1(cx+w-3,y,3,1,d); }
function gable(cx, by, hw, h, col) { for (var r = 0; r < h; r++) roofRow(cx,by-1-r,Math.max(1,Math.round(hw*(1-r/h))),r,col); P1(cx-1,by-h-1,2,1,PAL.out); P1(cx-hw-2,by-1,hw*2+4,2,PAL.out); P1(cx-hw-1,by-1,hw*2+2,1,shade(col,-38)); }
function pane(x, y, w, h, lit) { parts([[x,y,w,h,lit?PAL.win:PAL.winD]]); if (lit) P1(x,y,w,2,PAL.hot); P1(x+(w>>1),y,1,h,PAL.wood); P1(x,y+(h>>1),w,1,PAL.wood); P1(x-2,y+h,w+4,2,PAL.woodD); }
function doorArt(x, y, w, h) { parts([[x,y,w,h,PAL.door]]); for (var k = x+3; k < x+w-1; k += 4) P1(k,y,1,h,PAL.woodD); P1(x,y,w,1,shade(PAL.door,30)); P1(x+w-4,y+(h>>1),2,2,PAL.win); }
function timber(x, y, w, h) { parts([[x,y,w,h,PAL.wall]]); for (var i = 0; i < 7; i++) P1(x+4+hsh(x,y,i)*(w-10),y+4+hsh(y,x,i)*(h-8),2,1,PAL.wallD);
  P1(x+w-8,y+2,6,h-4,PAL.dim); P1(x,y,2,h,PAL.wood); P1(x+w-2,y,2,h,PAL.wood); P1(x,y,w,2,PAL.wood); P1(x,y+h-2,w,2,PAL.wallD); }
/* The door shows the owner's status: standing open, ajar with a strip of light, or barred and sealed,
   with a lantern above it in the colour of the map key. Away is a shut door and no lantern. */
function entry(o, x, y, w, h) {
  var st = o.lit ? o.door : 'away', cx = x+(w>>1), i;
  if (st==='open') { parts([[x,y,w,h,PAL.glow],[x-5,y+2,4,h-2,PAL.door]]); P1(x,y,w,3,shade(PAL.glow,-45)); P1(x+2,y+h-4,w-4,4,PAL.hot); P1(x-5,y+2,1,h-2,shade(PAL.door,30)); P1(x-1,y+h,w+2,2,TINT.spell); }
  else if (st==='ajar') { doorArt(x,y,w,h); P1(x+w-3,y,3,h,PAL.glow); P1(x+w-4,y,1,h,PAL.out); P1(x+w-2,y+h-3,2,3,PAL.hot); }
  else { doorArt(x,y,w,h);
    if (st==='sealed') { var cy = y+(h>>1)-1; parts([[x-2,cy-1,w+4,3,PAL.stoneD]]); parts([[cx-1,cy-4,2,8,TINT.ember],[cx-3,cy-2,6,4,TINT.ember]]); P1(cx-1,cy-1,2,2,PAL.hot);
      for (i = 0; i < h+4; i += 3) { P1(x-3,y-3+i,1,1,TINT.ember); P1(x+w+2,y-3+i,1,1,TINT.ember); } for (i = 0; i < w+6; i += 3) P1(x-3+i,y-4,1,1,TINT.ember); } }
  if (st!=='away') { parts([[cx-2,y-9,4,4,st==='open'?TINT.spell:st==='ajar'?TINT.candle:TINT.ember]]); P1(cx-1,y-8,1,1,PAL.hot); }
}
/* A place to meet must not read as somebody's house: a paved square, tall banners, a name plate. */
function plaza(hw, d) { parts([[-hw,-6,hw*2,d,PAL.cobble]]); for (var yy = -6, row = 0; yy < d-7; yy += 6, row++) for (var xx = -hw+(row%2?4:0); xx < hw-6; xx += 8) { P1(xx,yy,7,5,PAL.cobbleL); P1(xx,yy,7,1,PAL.cobbleH); } }
function banner(x, col) { parts([[x,-72,2,72,PAL.wood],[x+2,-70,11,24,col]]); P1(x+2,-70,11,2,shade(col,45)); P1(x+2,-50,11,4,shade(col,-45)); P1(x+5,-63,5,5,PAL.hot); P1(x-1,-74,4,3,PAL.win); }
/* One building, its base centre at 0,0. */
function houseArt(o) {
  var tint = TINT[o.tint]||TINT.candle, k = o.kind, lit = o.lit, r, i;
  if (k==='tavern') { plaza(66,24); banner(-64,tint); } else if (k==='hall') { plaza(66,24); banner(-66,tint); banner(64,tint); } else if (k==='tent') plaza(38,22);
  else P1(-HIT[k][0]+2,0,HIT[k][0]*2-4,4,PAL.shadow);
  if (k==='tower') {
    parts([[-16,-76,32,76,PAL.stone],[-20,-84,40,8,PAL.stoneD]]);
    var m = shade(PAL.stone,-20);
    for (r = 0; r < 76; r += 6) { P1(-16,-76+r,32,1,m); for (i = 0; i < 4; i++) { var bx = -16+((r/6)%2?4:0)+i*9; if (bx<15) P1(bx,-76+r,1,6,m); } }
    P1(9,-76,7,76,PAL.dim); P1(-16,-76,2,76,PAL.lift); P1(-20,-84,40,1,PAL.rockL);
    gable(0,-84,24,34,tint); parts([[-2,-126,4,4,PAL.win]]);
    pane(-4,-66,8,12,lit); pane(-4,-42,8,12,lit); parts([[-8,-19,16,3,PAL.stoneD]]); entry(o,-6,-16,12,16);
  } else if (k==='study') {
    timber(-24,-34,48,34); gable(0,-34,31,28,tint); pane(-18,-26,12,12,lit);
    P1(-19,-11,14,2,PAL.woodD); P1(-17,-13,2,2,PAL.flowerB); P1(-13,-13,2,2,PAL.flowerA); P1(-9,-13,2,2,PAL.flowerB);
    entry(o,6,-18,12,18);
  } else if (k==='forge') {
    parts([[12,-74,12,40,PAL.stoneD],[10,-78,16,4,PAL.stone]]); for (r = 4; r < 40; r += 5) P1(12,-74+r,12,1,shade(PAL.stoneD,-22));
    timber(-28,-38,56,38); gable(0,-38,35,24,tint);
    parts([[-12,-26,24,4,PAL.stoneD],[-10,-22,20,22,lit?PAL.lava:PAL.winD]]);
    if (lit) { P1(-7,-15,14,15,PAL.lavaL); P1(-3,-8,6,8,PAL.hot); }
    parts([[-25,-5,8,5,PAL.iron],[-27,-8,12,3,PAL.iron]]); entry(o,16,-18,9,18);
  } else if (k==='tavern') {
    timber(-48,-46,96,46); P1(-17,-46,2,46,PAL.wood); P1(15,-46,2,46,PAL.wood); P1(-48,-24,96,2,PAL.wood);
    for (r = 0; r < 26; r++) roofRow(0,-47-r,54-Math.round(r*.7),r,tint);
    P1(-37,-73,74,1,PAL.out); P1(-56,-47,112,2,PAL.out);
    [-40,-27,18,31].forEach(function (d) { pane(d,-40,9,10,true); });
    doorArt(-8,-20,16,20); parts([[-15,-17,3,5,PAL.win]]);
    P1(50,-46,2,28,PAL.wood); parts([[52,-42,14,10,PAL.wood]]); P1(55,-39,8,1,PAL.win); P1(55,-36,6,1,PAL.win);
  } else if (k==='hall') {
    parts([[-52,-6,104,6,PAL.stoneD],[-48,-10,96,4,PAL.stone]]); parts([[-44,-46,88,36,PAL.glow]]); P1(-44,-46,88,8,shade(PAL.glow,-34));
    for (i = 0; i < 5; i++) { var cx = -44+i*20; parts([[cx,-46,8,36,PAL.marble]]); P1(cx+5,-46,3,36,PAL.snowD); P1(cx+2,-44,1,32,PAL.snowD); }
    parts([[-50,-54,100,8,PAL.marbleD]]); P1(-50,-48,100,2,shade(PAL.marbleD,-30));
    for (r = 0; r < 20; r++) { var w = Math.max(1,Math.round(52*(1-r/20))), y = -56-r; P1(-w-1,y,w*2+2,1,PAL.out); P1(-w,y,w*2,1,shade(PAL.marbleD,-14)); P1(-w,y,Math.min(3,w),1,PAL.marble); }
    P1(-1,-77,2,1,PAL.out); parts([[-3,-66,6,6,PAL.win]]);
  } else {
    var d2 = shade(tint,-42);
    for (r = 0; r < 44; r++) { var w3 = 1+Math.round(r*.6), y3 = -44+r; P1(-w3-1,y3,w3*2+2,1,PAL.out); P1(-w3,y3,w3*2,1,tint);
      for (i = -w3; i < w3; i++) if (((i+80)>>2)%2) P1(i,y3,1,1,d2);
      if (r>22) { var ow = Math.round((r-22)*.36)+1; P1(-ow-1,y3,ow*2+2,1,PAL.out); P1(-ow,y3,ow*2,1,PAL.glow); } }
    P1(-28,0,56,1,PAL.out); P1(-1,-58,2,14,PAL.wood); parts([[1,-58,10,6,tint]]); P1(1,-58,10,2,shade(tint,45));
  }
}
function buildHouses() { HOUSES = BLIST.map(function (o) { return {x:o.x,y:o.y,st:stamp(176,168,84,144,function () { houseArt(o); })}; }); }

/* ---------- characters ---------- */
/* One character, feet at x,y. Everybody shares the figure; cloak, headwear and companion differ. */
function sprite(c, x, y, L, bob) {
  var col = TINT[L.cloak]||TINT.mana, cd = shade(col,-55), cl = shade(col,45), o = PAL.out;
  var A = function (a, b, w, h, f) { c.fillStyle = f; c.fillRect(x+a,y+b-bob,w,h); };
  var PS = function (l) { var i; for (i = 0; i < l.length; i++) A(l[i][0]-1,l[i][1]-1,l[i][2]+2,l[i][3]+2,o); for (i = 0; i < l.length; i++) A(l[i][0],l[i][1],l[i][2],l[i][3],l[i][4]); };
  c.fillStyle = PAL.shadow; c.fillRect(x-10,y-2,20,4);
  var body = [[-6,-2,4,2,PAL.boot],[2,-2,4,2,PAL.boot],[-9,-12,18,10,col],[-7,-20,14,8,col],[-7,-33,14,13,PAL.skin]];
  if (L.hat==='hat') body.push([-12,-36,24,3,col],[-7,-42,14,6,col],[-5,-47,10,5,col],[-2,-52,6,5,col],[1,-55,3,3,col]);
  else if (L.hat==='hood') body.push([-8,-37,16,5,col],[-9,-33,3,14,col],[6,-33,3,14,col]);
  /* The merchant's two: a crown worn on the hair, and a cap in the cloak's colour with a feather. */
  else if (L.hat==='crown') body.push([-7,-36,14,4,PAL.hair],[-6,-40,12,4,TINT.candle],[-6,-43,2,3,TINT.candle],[-1,-44,2,4,TINT.candle],[4,-43,2,3,TINT.candle]);
  else if (L.hat==='cap') body.push([-8,-38,16,5,col],[-11,-34,15,2,col],[5,-45,2,7,PAL.hot],[7,-48,2,6,PAL.hot]);
  else body.push([-7,-36,14,4,PAL.hair]);
  PS(body);
  A(-9,-4,18,2,cd); A(-1,-20,2,16,cd); A(-7,-20,2,8,cl); A(-9,-12,2,8,cl); A(5,-18,2,6,cd); A(7,-10,2,6,cd); A(-1,-20,2,2,PAL.win);
  A(-4,-27,2,3,o); A(2,-27,2,3,o); A(-4,-27,1,1,PAL.hot); A(2,-27,1,1,PAL.hot); A(-6,-24,2,1,PAL.cheek); A(4,-24,2,1,PAL.cheek); A(-1,-23,2,1,PAL.mouth);
  if (L.hat==='hat') { A(-7,-38,14,2,PAL.win); A(-7,-42,2,4,cl); A(-5,-47,2,5,cl); A(-12,-36,24,1,cl); }
  else if (L.hat==='hood') { A(-8,-37,16,1,cl); A(-9,-33,1,14,cl); }
  else if (L.hat==='crown') { A(-7,-32,2,4,PAL.hair); A(5,-32,2,4,PAL.hair); A(-6,-40,12,1,PAL.hot); A(-6,-37,12,1,PAL.glow); A(-1,-39,2,2,TINT.ember); A(-5,-39,1,1,TINT.mana); A(4,-39,1,1,TINT.mana); }
  else if (L.hat==='cap') { A(-8,-38,16,1,cl); A(-8,-34,16,1,cd); A(5,-33,2,3,PAL.hair); A(6,-45,1,6,TINT.ember); A(8,-48,1,5,TINT.ember); }
  else { A(-7,-32,2,4,PAL.hair); A(5,-32,2,4,PAL.hair); A(-5,-36,4,1,shade(PAL.hair,35)); }
  /* The starry cloak is midnight blue with a few stars and a small moon on it. */
  if (L.cloak==='starry') { A(-6,-9,1,1,PAL.hot); A(4,-7,1,1,PAL.hot); A(-4,-16,1,1,PAL.hot); A(5,-15,1,1,PAL.hot); A(-3,-5,1,1,PAL.hot); A(2,-12,2,2,TINT.candle); A(3,-12,1,1,PAL.hot); }
  if (L.pet==='owl') { PS([[12,-15,11,14,PAL.owl],[12,-17,2,2,PAL.owl],[21,-17,2,2,PAL.owl]]); A(14,-6,7,4,PAL.owlL); A(13,-12,4,4,PAL.hot); A(18,-12,4,4,PAL.hot); A(14,-11,2,2,o); A(19,-11,2,2,o); A(17,-8,2,2,PAL.win); }
  else if (L.pet==='cat') { PS([[13,-9,12,8,PAL.fur],[12,-15,9,7,PAL.fur],[12,-18,3,3,PAL.fur],[18,-18,3,3,PAL.fur],[25,-16,2,9,PAL.fur]]); A(14,-13,2,2,PAL.win); A(18,-13,2,2,PAL.win); A(16,-11,1,1,PAL.cheek); A(15,-4,8,2,shade(PAL.fur,-30)); }
  /* The merchant's two companions. */
  else if (L.pet==='fox') { var fx = '#e8823a'; PS([[13,-9,11,8,fx],[11,-16,10,8,fx],[11,-19,3,3,fx],[18,-19,3,3,fx],[24,-14,4,13,fx]]);
    A(24,-14,4,3,PAL.wall); A(14,-11,4,3,PAL.wall); A(15,-11,2,1,o); A(13,-14,2,2,o); A(17,-14,2,2,o); A(15,-6,5,5,PAL.wall); A(12,-18,1,2,shade(fx,-60)); A(19,-18,1,2,shade(fx,-60)); }
  else if (L.pet==='dragon') { var dg = '#58b368', dl = shade(dg,55); PS([[22,-18,6,8,dl],[13,-10,10,9,dg],[12,-18,9,8,dg],[12,-21,2,3,PAL.wall],[19,-21,2,3,PAL.wall],[23,-5,5,3,dg]]);
    A(23,-17,1,6,dg); A(25,-17,1,5,dg); A(15,-7,6,6,PAL.lavaL); A(15,-5,6,1,PAL.glow); A(15,-3,6,1,PAL.glow); A(13,-15,2,2,PAL.win); A(18,-15,2,2,PAL.win); A(14,-15,1,2,o); A(19,-15,1,2,o); A(14,-12,1,1,o); A(18,-12,1,1,o); A(27,-5,2,2,TINT.ember); }
}
/* RESTING: THREE Z'S DRIFTING UP (David, 2026-11-13: an animation for when you "are idle (zzzz)").
   They rise, lean a little and fade, one after another, over about three seconds. The map is
   repainted every 260 ms anyway, so this costs nothing extra; with "reduce motion" set they simply
   stand still, as the single Z used to. */
function zed(c, x, y) {
  var t = Date.now()/3000, i;
  for (i = 0; i < 3; i++) {
    var p = calm ? .25+i*.25 : (t+i/3)%1, zx = Math.round(x+5+p*9+Math.sin(p*6.28)*2), zy = Math.round(y-58-p*20), w = 7+Math.round(p*3);
    c.globalAlpha = calm ? 1 : Math.min(1,p/.12)*Math.max(0,1-Math.max(0,(p-.55)/.45));
    c.fillStyle = PAL.out; c.fillRect(zx-1,zy-1,w+2,w+2);
    c.fillStyle = PAL.hot; c.fillRect(zx,zy,w,1); c.fillRect(zx,zy+w-1,w,1);
    for (var j = 0; j < w-2; j++) c.fillRect(zx+w-2-j,zy+1+j,2,1);
  }
  c.globalAlpha = 1;
}
/* ---------- the small animations (David, 2026-11-13) ----------
   "let's create some basic animations for when you: teleport, open a chest, access the book shelf,
   open your profile, get a praise, are idle (zzzz), talk with voice or in voice chat."
   Idle and talking belong to a character and are drawn with them (zed, voiceMark). The other four
   are moments, so they are kept here as a short-lived list and painted over whatever is on screen:
   fx(kind, x, y) starts one where something happened, in the units of the picture it belongs to
   (the map, or the room I am standing in: `room` is which, so a room's sparks never show on the
   map). While any of them is running, or anybody is speaking, the picture is repainted by the
   clock; when they are over it goes back to its usual slow beat.
   NOTHING MOVES FOR SOMEBODY WHO ASKED FOR LESS MOTION (prefers-reduced-motion): fx() does nothing
   at all then, as the rest of the Realm already does (`calm`). */
var FX = [], fxraf = 0;
var FXMS = {poof:460,land:460,chest:900,shelf:800,portrait:760,praise:1500};
function fx(kind, x, y, col) {
  if (calm || !FXMS[kind]) return;
  FX.push({k:kind,x:x,y:y,col:col||TINT.mana,t0:Date.now(),room:!!S.inside});
  fxWake();
}
/* The same, over a person wherever they are standing right now (LASTAT is filled as they are
   drawn). Used for praise, which happens to somebody, not at a spot. */
function fxAt(id, kind, tint) {
  var a = LASTAT[id]; if (!a || !!a.room !== !!S.inside) return;
  fx(kind,a.x,a.y,TINT[tint]||TINT.spell);
}
function fxWake() { if (!fxraf) fxraf = requestAnimationFrame(fxTick); }
function fxTick() {
  fxraf = 0; var now = Date.now();
  FX = FX.filter(function (e) { return now-e.t0 < FXMS[e.k]; });
  draw();
  if (FX.length || talkingNow()) fxWake();
}
function talkingNow() { var now = Date.now(); for (var k in VO.talk) if (VO.talk[k] > now) return true; return false; }
/* A four-pointed star, outlined like everything else in the Realm. */
function star(c, x, y, col) { c.fillStyle = PAL.out; c.fillRect(x-2,y-4,5,9); c.fillRect(x-4,y-2,9,5); c.fillStyle = col; c.fillRect(x-1,y-3,3,7); c.fillRect(x-3,y-1,7,3); }
function fxPaint(c, room) {
  if (!FX.length) return;
  var now = Date.now();
  FX.forEach(function (e) {
    if (!!e.room !== !!room) return;
    var k = (now-e.t0)/FXMS[e.k], i, p, px, py;
    if (k < 0 || k > 1) return;
    if (e.k==='poof' || e.k==='land') {
      /* Leaving: a ring of sparks thrown outwards and a beam going up. Arriving: the same ring
         closing in, so the two read as one journey. */
      var kk = e.k==='land' ? 1-k : k, r = 5+kk*24;
      c.globalAlpha = Math.max(0,1-k);
      for (i = 0; i < 8; i++) { var an = i*Math.PI/4;
        c.fillStyle = PAL.out; c.fillRect(Math.round(e.x+Math.cos(an)*r)-3,Math.round(e.y-26+Math.sin(an)*r*.55)-3,6,6);
        c.fillStyle = e.col; c.fillRect(Math.round(e.x+Math.cos(an)*r)-2,Math.round(e.y-26+Math.sin(an)*r*.55)-2,4,4); }
      c.fillStyle = PAL.hot; c.fillRect(Math.round(e.x)-2,Math.round(e.y-20-kk*34),4,Math.round(10+kk*24));
      c.globalAlpha = 1; return;
    }
    if (e.k==='chest') {
      /* The lid swings up and five coins hop out of it. */
      var lid = Math.min(1,k*3);
      c.globalAlpha = Math.max(0,1-Math.max(0,(k-.6)/.4));
      c.fillStyle = PAL.out; c.fillRect(Math.round(e.x-11),Math.round(e.y-16-lid*5),22,3);
      c.fillStyle = TINT.candle; c.fillRect(Math.round(e.x-10),Math.round(e.y-15-lid*5),20,1);
      for (i = 0; i < 5; i++) { p = Math.min(1,Math.max(0,k*1.5-i*.12)); if (p <= 0) continue;
        px = Math.round(e.x-9+i*5+Math.sin(p*6.28+i)*3); py = Math.round(e.y-8-Math.sin(p*3.14)*24);
        c.globalAlpha = Math.max(0,1-p);
        c.fillStyle = PAL.out; c.fillRect(px-1,py-1,6,6); c.fillStyle = TINT.candle; c.fillRect(px,py,4,4); c.fillStyle = PAL.hot; c.fillRect(px+1,py+1,1,1); }
      c.globalAlpha = 1; return;
    }
    if (e.k==='shelf') {
      /* A book slides out and its pages flutter up. */
      for (i = 0; i < 5; i++) { p = Math.min(1,Math.max(0,k*1.4-i*.1)); if (p <= 0) continue;
        px = Math.round(e.x-10+i*5+Math.sin(p*6.28+i*2)*4); py = Math.round(e.y-4-p*26);
        c.globalAlpha = Math.max(0,1-p);
        c.fillStyle = PAL.out; c.fillRect(px-1,py-1,6,7); c.fillStyle = PAL.win; c.fillRect(px,py,4,5); c.fillStyle = PAL.hot; c.fillRect(px,py+2,4,1); }
      c.globalAlpha = 1; return;
    }
    if (e.k==='portrait') {
      /* A light sweeps across the frame, the way a glass does when you step in front of it. */
      c.globalAlpha = Math.max(0,.85-Math.abs(k-.5)*1.3);
      c.fillStyle = PAL.hot;
      var bx = Math.round(e.x-14+k*28);
      for (i = 0; i < 12; i++) c.fillRect(bx-i,Math.round(e.y-24+i*2),3,2);
      c.globalAlpha = Math.max(0,1-k);
      for (i = 0; i < 3; i++) { var sa = k*6.28+i*2.1; star(c,Math.round(e.x+Math.cos(sa)*12),Math.round(e.y-12+Math.sin(sa)*12),TINT.violet); }
      c.globalAlpha = 1; return;
    }
    /* Praise: a fan of stars opening over the head, in the colour of the kind of praise. */
    for (i = 0; i < 7; i++) { p = Math.min(1,Math.max(0,k*1.3-i*.06)); if (p <= 0) continue;
      var a2 = -Math.PI/2+(i-3)*.36, d = 6+p*30;
      px = Math.round(e.x+Math.cos(a2)*d*1.2); py = Math.round(e.y-48+Math.sin(a2)*d);
      c.globalAlpha = Math.max(0,1-p*p);
      star(c,px,py,e.col); }
    c.globalAlpha = 1;
  });
}
/* Where everybody was last drawn, so a thing that happens TO somebody can be shown over them. */
var LASTAT = {};
function puff(c, x, y, f, big) { for (var i = 0; i < 3; i++) { var ph = (f+i*3)%9, s = (big?6:4)+Math.floor(ph/3)*2; c.globalAlpha = .75*(1-ph/9); c.fillStyle = PAL.smoke; c.fillRect(x+ph,y-ph*(big?6:4),s,s); } c.globalAlpha = 1; }
/* Who is INSIDE a building, as a row of small heads above its roof. */
function heads(c, o, list) {
  if (!list.length) return;
  /* Above the roof, except the Tavern: the Council Hall's name plate sits right over its roof, so the
     Tavern's row goes under its own plate. */
  var below = o.kind==='tavern', n = Math.min(list.length,5), w = n*13+5, x = Math.round(o.x-w/2), y = below ? o.y+49 : o.y-HIT[o.kind][1]-22, i;
  c.fillStyle = PAL.out; c.fillRect(x-1,y-1,w+2,18); c.fillStyle = PAL.veil; c.fillRect(x,y,w,16); c.fillStyle = PAL.out; if (!below) c.fillRect(o.x-2,y+17,4,3);
  for (i = 0; i < n; i++) { var p = list[i], hx = x+4+i*13;
    c.fillStyle = PAL.skin; c.fillRect(hx,y+5,10,8); c.fillStyle = p.hat==='none'?PAL.hair:(TINT[p.cloak]||TINT.mana); c.fillRect(hx-1,y+2,12,4);
    c.fillStyle = PAL.out; c.fillRect(hx+2,y+8,2,2); c.fillRect(hx+6,y+8,2,2);
    /* A green line under a head: that person is in the room's voice chat. */
    if (p.voice) { c.fillStyle = TINT.spell; c.fillRect(hx,y+14,10,1); } }
}
/* Where everybody but me stands on the map, and who is inside what. */
function whereabouts() {
  var out = [], inside = {}, queue = {};
  ST.people.forEach(function (p) {
    if (p.me || p.state==='away') return;
    var o = B[p.place]; if (!o) return;
    if (p.inside) { (inside[p.place] = inside[p.place]||[]).push(p); return; }
    if (p.place==='user:'+p.id) { out.push({x:o.x+SIDE[o.kind],y:o.y+3,p:p}); return; }
    var n = queue[p.place] = (queue[p.place]||0)+1, s = spot(p.place);
    out.push({x:s.x-n*26,y:s.y,p:p});
  });
  return {out:out,inside:inside};
}

/* ---------- inside a building ---------- */
/* The meeting places: one drawn picture each. A person's own house is drawn by homeArt() below, from
   what they have put in it. */
var ROOMS = {};
function roomArt(kind, tint) {
  var key = kind+tint; if (ROOMS[key]) return ROOMS[key];
  var col = TINT[tint]||TINT.candle, tent = kind==='tent', stonef = kind==='hall';
  ROOMS[key] = stamp(240,160,0,0,function () {
    var x, y, i;
    P1(0,0,240,160,PAL.out);
    for (y = 52; y < 150; y += 10) for (x = 10; x < 230; x += 22) { var off = ((y-52)/10)%2?11:0;
      P1(x,y,220-(x-10),10,stonef?PAL.cobble:tent?PAL.grassA:PAL.wood); }
    for (y = 52; y < 150; y += 10) { P1(10,y,220,1,stonef?PAL.stoneD:tent?PAL.grassB:PAL.woodD); for (x = 10+(((y-52)/10)%2?11:0); x < 230; x += 22) P1(x,y,1,10,stonef?PAL.stoneD:tent?PAL.grassB:PAL.woodD); }
    P1(10,10,220,42,tent?col:stonef?PAL.stone:PAL.wall);
    if (tent) for (x = 10; x < 230; x += 16) P1(x,10,8,42,shade(col,-42));
    else { P1(10,10,220,3,PAL.wood); P1(10,44,220,8,stonef?PAL.stoneD:PAL.wallD); P1(10,44,220,1,PAL.wood);
      [40,180].forEach(function (wx) { parts([[wx,18,20,18,PAL.winD]]); P1(wx+4,22,1,1,PAL.hot); P1(wx+13,29,1,1,PAL.hot); P1(wx+9,18,1,18,PAL.wood); P1(wx,26,20,1,PAL.wood); }); }
    parts([[84,84,72,34,col]]); P1(88,88,64,26,shade(col,-45)); P1(92,92,56,18,col);
    P1(108,150,24,4,PAL.glow);
    /* What stands on the floor (the bar, the barrels, the tables) is NOT painted here any more: see
       PROPS below. Only what hangs on the wall is. */
    if (kind==='hall') [[26,14],[204,14]].forEach(function (b) { parts([[b[0],b[1],10,30,col]]); P1(b[0]+3,b[1]+8,4,4,PAL.hot); });
  });
  return ROOMS[key];
}
/* THE FURNITURE OF A MEETING PLACE (David, 2026-11-13: "let's also have the option for the avatars to
   move freely inside each house"). It used to be part of the room's one picture. Now that people
   walk about, each piece is its own small picture, so that it can stop a character and so that
   somebody standing behind a barrel is covered by it, as a house covers them on the map.
   [what, x, y, width, height], the top left corner in the room's own units. */
var PROPS = {
  /* The stall is the merchant's (David, 2026-11-13: the shop is "a merchant in the Tavern"). */
  tavern:[['bar',24,46,192,20],['keg',28,100,22,26],['keg',190,100,22,26],['stall',158,64,36,34]],
  hall:[['slab',70,74,100,10]]
  /* A show's camp (the tent) has no row here since 2026-11-13: what stands in it is a list of
     things, as in a house (CAMP_FIX and everybody's one decoration), so that the same code keeps
     decorations off the fixtures. Its map table is the thing camp_table; the two crates left. */
};
function propArt(id) {
  var x;
  if (id==='bar') { parts([[0,8,192,12,PAL.wood]]); P1(0,8,192,2,shade(PAL.wood,35)); for (x = 10; x < 186; x += 22) { parts([[x,0,6,8,PAL.wall]]); P1(x,0,6,2,PAL.win); } }
  else if (id==='keg') { parts([[0,0,22,26,PAL.wood]]); P1(0,6,22,2,PAL.iron); P1(0,18,22,2,PAL.iron); }
  /* The merchant behind a small counter: a violet hat, a teal coat, and wares laid out in front. */
  else if (id==='stall') { parts([[11,13,14,10,TINT.teal],[12,3,12,11,PAL.skin],[10,0,16,5,TINT.violet]]);
    P1(10,0,16,1,shade(TINT.violet,45)); P1(17,1,2,3,TINT.candle); P1(14,8,2,3,PAL.out); P1(20,8,2,3,PAL.out); P1(14,8,1,1,PAL.hot); P1(20,8,1,1,PAL.hot); P1(13,11,2,1,PAL.cheek); P1(21,11,2,1,PAL.cheek); P1(15,12,6,3,PAL.wall); P1(17,15,2,7,shade(TINT.teal,-55)); P1(11,13,2,9,shade(TINT.teal,45));
    parts([[0,21,36,13,PAL.wood]]); P1(0,21,36,2,shade(PAL.wood,35)); P1(4,25,28,7,TINT.ember); P1(4,25,28,1,shade(TINT.ember,45)); P1(15,26,6,5,TINT.candle); P1(16,27,4,1,PAL.hot); P1(17,28,2,2,PAL.glow);
    parts([[3,15,4,6,TINT.spell]]); P1(4,13,2,2,PAL.wall); P1(3,15,1,3,PAL.hot); parts([[28,17,6,4,TINT.candle]]); P1(28,15,1,2,TINT.candle); P1(30,15,2,2,TINT.candle); P1(33,15,1,2,TINT.candle); P1(30,18,2,2,TINT.ember); }
  else if (id==='slab') { parts([[0,0,100,10,PAL.marble]]); P1(0,8,100,2,PAL.marbleD); }
}
var PSTAMP = {};
function propStamp(pr) { return PSTAMP[pr[0]] || (PSTAMP[pr[0]] = stamp(pr[3]+2,pr[4]+2,1,1,function () { propArt(pr[0]); })); }
/* SPEECH BUBBLES (David, 2026-11-12): "when two or more characters are in the same space inside a
   house or project, let's make the avatars 'say' the text they sent through chat in a dialogue
   bubble". BUB holds the last thing each person said in the room I am standing in, by user id, and
   when it stops showing. Only what is said WHILE I am in the room: walking in does not replay the
   history over everybody's heads. Up to three lines; the chat panel keeps the whole message. */
var BUB = {}, BUBBLE_MS = 7000;
/* Work out a bubble's lines and box, anchored above the point x,y. Nothing is painted here, so that
   every bubble in the room can be placed before any is drawn. */
function bubbleBox(c, x, y, text) {
  c.font = 'bold 7px "Courier New", monospace'; c.textBaseline = 'top';
  var words = String(text).replace(/\s+/g,' ').split(' '), lines = [], cur = '', w = 0;
  words.forEach(function (wd) { var t = cur ? cur+' '+wd : wd; if (cur && c.measureText(t).width > 104) { lines.push(cur); cur = wd; } else cur = t; });
  if (cur) lines.push(cur);
  if (lines.length > 3) { lines = lines.slice(0,3); lines[2] += '…'; }
  lines = lines.map(function (l) { while (l.length > 2 && c.measureText(l).width > 108) l = l.slice(0,-2)+'…'; return l; });
  lines.forEach(function (l) { w = Math.max(w,c.measureText(l).width); });
  w = Math.ceil(w)+8;
  var h = lines.length*9+5;
  return {x:x,y:y,w:w,h:h,lines:lines,bx:Math.max(12,Math.min(228-w,Math.round(x-w/2))),by:Math.round(y-h-4)};
}
/* BUBBLES MUST NOT COVER EACH OTHER (David, 2026-11-12, with a screenshot of two of them doing
   exactly that: "is there a way we can avoid overlapping bubbles?"). The newest keeps its place
   just above its speaker; an older one that would touch it is lifted clear, and keeps a line down
   to its own character so it is still plain who said it. */
function placeBubbles(boxes) {
  var placed = [];
  boxes.sort(function (a, b) { return b.until-a.until; }).forEach(function (b) {
    for (var guard = 0; guard < 12; guard++) {
      var hit = null;
      placed.forEach(function (p) { if (b.bx < p.bx+p.w+3 && p.bx < b.bx+b.w+3 && b.by < p.by+p.h+3 && p.by < b.by+b.h+3 && (!hit || p.by < hit.by)) hit = p; });
      /* Stop lifting at the top of the picture: four long speeches at once is the one case left. */
      if (!hit || hit.by-b.h-4 < -26) break;
      b.by = hit.by-b.h-4;
    }
    placed.push(b);
  });
  return placed;
}
function paintBubble(c, b) {
  var tail = b.y-(b.by+b.h);
  c.fillStyle = PAL.out; c.fillRect(b.bx-1,b.by-1,b.w+2,b.h+2); c.fillRect(b.x-2,b.by+b.h,5,Math.max(4,tail+1));
  c.fillStyle = PAL.hot; c.fillRect(b.bx,b.by,b.w,b.h); c.fillRect(b.x-1,b.by+b.h,3,Math.max(3,tail));
  c.fillStyle = PAL.out; b.lines.forEach(function (l, i) { c.fillText(l,b.bx+4,b.by+3+i*9); });
}
/* ---------- a person's own room (David, 2026-11-12, migration 086) ----------
   "Let's start with decorate my office. Let's all start with the same basic look and they can modify
   it from a library of free and opt to buy new stuff. All of them should have at least these: Book
   shelf: for tasks. Treasure chest: to check their gold. A portrait in the wall: so they can see
   theirs and others bio and profile from Anima, and update it."

   A house's room is no longer one fixed picture per kind of house. It is a list of THINGS and where
   each stands, plus three choices (wall, floor, rug), kept per person by includes/realm_room.php.
   Cottage, Tower and Forge are now only the outside. Everybody starts with ROOM_START.

   THREE THINGS ARE IN EVERY HOUSE and can be moved but never removed: the bookshelf (your tasks), the
   chest (your gold) and the portrait (the profile from Anima's Home). A see-through button is laid
   over each one every time the room is drawn (placeHot), because a canvas cannot be clicked piece by
   piece. The shelf and the chest answer only in YOUR OWN house; a portrait answers in anybody's.

   ⚠ THINGS IS ALSO IN includes/realm_room.php (REALM_ROOM_THINGS), which decides whether a room may
   be saved. The sizes MUST match. z is where it goes, y the height a wall thing hangs at, max how
   many one room may hold, req why it cannot be removed, gold that the merchant in the Tavern sells it
   (migration 092). A thing marked gold is shown in the library with its price, and can be placed
   only by somebody who owns it; the server asks the same (realm_room_save). */
var THINGS = {
  shelf:{n:'Bookshelf',z:'wall',w:40,h:32,y:14,max:1,req:'it holds your tasks'},
  portrait:{n:'Portrait',z:'wall',w:18,h:22,y:16,max:1,req:'it opens your profile'},
  window:{n:'Window',z:'wall',w:20,h:18,y:18,max:4},
  banner:{n:'Banner',z:'wall',w:12,h:28,y:13,max:3},
  clock:{n:'Clock',z:'wall',w:12,h:22,y:16,max:1},
  sconce:{n:'Wall candle',z:'wall',w:6,h:12,y:22,max:4},
  potions:{n:'Potion shelf',z:'wall',w:22,h:12,y:22,max:2},
  map:{n:'Map',z:'wall',w:26,h:18,y:18,max:1},
  hearth:{n:'Hearth',z:'wall',w:56,h:40,y:12,max:1},
  trophy:{n:'Shield and swords',z:'wall',w:22,h:22,y:16,max:1,gold:1},
  chest:{n:'Treasure chest',z:'floor',w:22,h:16,max:1,req:'it keeps your gold'},
  desk:{n:'Desk',z:'floor',w:46,h:22,max:1},
  chair:{n:'Armchair',z:'floor',w:18,h:22,max:2},
  table:{n:'Table',z:'floor',w:30,h:18,max:1},
  plant:{n:'Plant',z:'floor',w:12,h:20,max:4},
  barrel:{n:'Barrel',z:'floor',w:16,h:20,max:3},
  crate:{n:'Crate',z:'floor',w:16,h:14,max:3},
  candle:{n:'Candle stand',z:'floor',w:8,h:24,max:3},
  anvil:{n:'Anvil',z:'floor',w:32,h:14,max:1},
  telescope:{n:'Telescope',z:'floor',w:26,h:30,max:1},
  cauldron:{n:'Cauldron',z:'floor',w:22,h:20,max:1},
  orb:{n:'Crystal ball',z:'floor',w:14,h:22,max:1,gold:1},
  throne:{n:'Throne',z:'floor',w:22,h:34,max:1,gold:1},
  /* What is always in a show's camp (fix): never in the library, never moved. See CAMP_FIX. */
  camp_scroll:{n:'Brief',z:'wall',w:16,h:26,y:18,max:1,fix:1},
  camp_calendar:{n:'Timeline',z:'wall',w:22,h:24,y:20,max:1,fix:1},
  camp_board:{n:'Production tracking',z:'wall',w:44,h:30,y:16,max:1,fix:1},
  camp_table:{n:'Map table',z:'floor',w:48,h:16,max:1,fix:1}
};
var STYLES = {wall:{plaster:'Plaster',stone:'Stone',timber:'Timber'},floor:{planks:'Planks',flags:'Flagstones',checker:'Checker'},rug:{classic:'Square',round:'Round',none:'No rug'}};
/* The room everybody starts with. includes/realm_room.php holds the same one (realm_room_default). */
var ROOM_START = {wall:'plaster',floor:'planks',rug:'classic',items:[['window',36,18],['shelf',100,14],['portrait',152,16],['window',190,18],['desk',22,58],['chest',186,64]]};

/* One thing, its top left corner at 0,0. col is the owner's cloak colour, which a few things wear. */
function thingArt(id, col) {
  var i, x, W = PAL.wood, WD = PAL.woodD, I = PAL.iron;
  if (id==='shelf') { parts([[0,0,40,32,W]]); for (i = 0; i < 3; i++) { P1(2,4+i*10,36,7,WD); for (x = 0; x < 8; x++) P1(3+x*4,5+i*10,3,6,[TINT.mana,TINT.ember,TINT.spell,TINT.candle][(x+i)%4]); } P1(0,0,40,1,shade(W,35)); }
  else if (id==='portrait') { parts([[0,0,18,22,PAL.glow]]); P1(0,0,18,1,PAL.hot); P1(0,21,18,1,shade(PAL.glow,-45)); P1(2,2,14,18,PAL.winD);
    P1(4,13,10,7,col); P1(6,6,6,7,PAL.skin); P1(6,5,6,2,PAL.hair); P1(7,9,1,1,PAL.out); P1(10,9,1,1,PAL.out); }
  else if (id==='window') { parts([[0,0,20,18,PAL.winD]]); P1(4,4,1,1,PAL.hot); P1(13,11,1,1,PAL.hot); P1(16,3,1,1,PAL.hot); P1(9,0,1,18,W); P1(0,8,20,1,W); }
  else if (id==='banner') { parts([[0,0,12,2,W],[1,2,10,22,col],[1,24,4,4,col],[7,24,4,4,col]]); P1(1,2,10,2,shade(col,45)); P1(1,20,10,2,shade(col,-45)); P1(4,9,4,4,PAL.hot); P1(5,10,2,2,shade(col,-45)); }
  else if (id==='clock') { parts([[0,0,12,22,W]]); P1(0,0,12,1,shade(W,35)); P1(2,2,8,8,PAL.wall); P1(5,3,1,4,PAL.out); P1(5,6,3,1,PAL.out); P1(3,12,6,8,WD); P1(5,13,2,4,PAL.glow); P1(4,17,4,2,PAL.win); }
  else if (id==='sconce') { parts([[2,6,2,5,I],[0,10,6,2,I]]); parts([[2,2,2,4,PAL.wall]]); P1(2,0,2,2,PAL.win); P1(2,1,1,1,PAL.hot); }
  else if (id==='potions') { parts([[0,9,22,3,W]]); parts([[2,3,4,6,TINT.spell],[9,2,4,7,TINT.violet],[16,4,4,5,TINT.ember]]); P1(3,1,2,2,PAL.wall); P1(10,0,2,2,PAL.wall); P1(17,2,2,2,PAL.wall); P1(2,3,1,3,PAL.hot); P1(9,2,1,3,PAL.hot); P1(16,4,1,2,PAL.hot); }
  else if (id==='map') { parts([[0,0,26,18,PAL.wall]]); P1(0,0,26,2,W); P1(0,16,26,2,W); P1(3,4,9,6,PAL.grassA); P1(5,10,5,3,PAL.grassA); P1(14,7,8,6,PAL.grassA); P1(12,5,2,7,PAL.water); P1(16,4,3,2,PAL.rock); P1(6,6,1,1,PAL.out); P1(8,8,1,1,PAL.out); P1(10,9,1,1,PAL.out); P1(17,9,2,2,TINT.ember); }
  else if (id==='hearth') { parts([[0,0,56,40,PAL.stoneD],[10,12,36,28,PAL.lava]]); for (i = 5; i < 40; i += 6) { P1(0,i,10,1,shade(PAL.stoneD,-22)); P1(46,i,10,1,shade(PAL.stoneD,-22)); } P1(0,0,56,3,PAL.stone); P1(0,3,56,1,shade(PAL.stoneD,-22));
    P1(16,20,24,20,PAL.lavaL); P1(23,28,10,12,PAL.hot); P1(12,36,32,4,WD); P1(16,34,10,3,W); P1(30,35,10,2,W); }
  else if (id==='trophy') { for (i = 0; i < 20; i++) { P1(i,i,3,3,PAL.out); P1(19-i,i,3,3,PAL.out); } for (i = 0; i < 20; i++) { P1(i+1,i+1,1,1,PAL.marble); P1(20-i,i+1,1,1,PAL.marble); }
    parts([[5,4,12,10,TINT.candle],[7,14,8,3,TINT.candle],[9,17,4,2,TINT.candle]]); P1(5,4,12,2,PAL.hot); P1(10,4,2,15,TINT.ember); P1(5,9,12,2,TINT.ember); }
  else if (id==='chest') { parts([[0,5,22,11,W],[0,0,22,6,WD]]); P1(0,0,22,1,shade(W,35)); P1(3,0,2,16,PAL.glow); P1(17,0,2,16,PAL.glow); P1(0,6,22,1,PAL.out); P1(9,5,4,5,PAL.win); P1(10,7,2,2,PAL.out); P1(0,14,22,2,shade(W,-30)); }
  else if (id==='desk') { parts([[0,8,46,14,W]]); P1(0,8,46,2,shade(W,35)); P1(0,17,46,5,WD); P1(3,17,3,5,PAL.out); P1(40,17,3,5,PAL.out); parts([[6,2,3,6,PAL.wall]]); P1(6,0,3,2,PAL.win); P1(7,0,1,1,PAL.hot); parts([[20,5,14,5,PAL.wall]]); P1(22,7,8,1,PAL.wallD); }
  else if (id==='chair') { parts([[2,0,14,13,WD],[0,8,4,10,W],[14,8,4,10,W],[2,11,14,8,col],[1,18,3,4,WD],[14,18,3,4,WD]]); P1(4,2,10,9,shade(col,-45)); P1(2,11,14,2,shade(col,45)); P1(0,8,4,1,shade(W,35)); P1(14,8,4,1,shade(W,35)); }
  else if (id==='table') { parts([[12,7,6,9,WD],[7,15,16,3,WD],[0,0,30,8,W]]); P1(0,0,30,2,shade(W,35)); P1(0,6,30,2,WD); parts([[19,-4,5,5,PAL.wall]]); P1(20,-4,3,1,TINT.ember); }
  else if (id==='plant') { parts([[4,2,4,11,PAL.pine],[0,5,4,7,PAL.pine],[8,4,4,8,PAL.pine],[2,12,8,8,TINT.ember]]); P1(4,2,2,6,PAL.pineL); P1(0,5,2,3,PAL.pineL); P1(9,5,2,5,PAL.pineD); P1(2,12,8,2,shade(TINT.ember,45)); P1(8,14,2,6,shade(TINT.ember,-55)); }
  else if (id==='barrel') { parts([[0,0,16,20,W]]); P1(2,0,2,20,shade(W,30)); P1(11,0,4,20,WD); P1(0,4,16,2,I); P1(0,14,16,2,I); P1(0,0,16,1,shade(W,35)); }
  else if (id==='crate') { parts([[0,0,16,14,W]]); P1(0,0,16,2,WD); P1(0,12,16,2,WD); P1(0,0,2,14,WD); P1(14,0,2,14,WD); for (i = 0; i < 10; i++) P1(3+i,2+i,2,1,WD); }
  else if (id==='candle') { parts([[3,6,2,16,I],[0,21,8,3,I],[1,5,6,2,I]]); parts([[3,2,2,3,PAL.wall]]); P1(3,0,2,2,PAL.win); P1(3,1,1,1,PAL.hot); }
  else if (id==='anvil') { parts([[6,6,20,8,I],[0,0,32,6,I]]); P1(0,0,32,1,PAL.stoneD); P1(10,6,12,2,PAL.out); P1(6,12,20,2,shade(I,-20)); }
  else if (id==='telescope') { parts([[12,5,4,25,W],[6,27,16,3,WD],[0,0,26,6,I]]); P1(0,0,26,1,PAL.stoneD); P1(22,1,4,4,PAL.win); P1(23,2,2,2,PAL.hot); P1(12,5,1,22,shade(W,35)); }
  else if (id==='cauldron') { parts([[2,6,18,12,I],[0,4,22,3,I],[4,18,3,2,I],[15,18,3,2,I]]); P1(2,4,18,2,TINT.spell); P1(5,4,4,1,PAL.hot); P1(3,8,2,8,PAL.stoneD); parts([[6,0,2,2,TINT.spell]]); parts([[13,1,2,2,TINT.spell]]); }
  else if (id==='orb') { parts([[4,14,6,6,W],[1,19,12,3,WD]]); parts([[3,1,8,13,TINT.violet],[1,3,12,9,TINT.violet]]); P1(4,3,3,3,PAL.hot); P1(9,9,3,3,shade(TINT.violet,-55)); }
  else if (id==='camp_scroll') { parts([[0,0,16,3,W],[2,3,12,20,PAL.wall],[0,23,16,3,W]]); P1(0,0,16,1,shade(W,35)); for (i = 0; i < 5; i++) P1(4,6+i*3,i%2?6:8,1,PAL.wallD); P1(9,19,3,3,TINT.ember); P1(10,20,1,1,PAL.hot); }
  else if (id==='camp_calendar') { parts([[0,0,22,24,PAL.wall]]); P1(0,0,22,5,TINT.ember); P1(0,0,22,1,shade(TINT.ember,45)); parts([[4,-2,2,4,I],[16,-2,2,4,I]]);
    for (i = 0; i < 4; i++) for (x = 0; x < 5; x++) P1(2+x*4,7+i*4,3,3,(i===1&&x===2)?col:PAL.wallD); }
  else if (id==='camp_board') { parts([[0,0,44,30,W]]); P1(0,0,44,1,shade(W,35)); P1(2,2,40,26,PAL.winD);
    for (i = 0; i < 5; i++) for (x = 0; x < 7; x++) { var q = (i*3+x*5)%7; P1(5+x*5,4+i*5,3,3,x > 5-i ? PAL.stoneD : q < 4 ? TINT.spell : q===4 ? TINT.teal : q===5 ? TINT.ember : TINT.mana); } }
  else if (id==='camp_table') { parts([[4,10,4,6,WD],[40,10,4,6,WD],[0,0,48,12,W]]); P1(0,0,48,1,shade(W,35)); P1(0,10,48,2,WD); P1(4,2,40,7,PAL.wall); P1(8,4,7,3,PAL.grassA); P1(22,3,9,4,PAL.grassA); P1(17,3,2,5,PAL.water); P1(12,5,2,2,TINT.ember); P1(27,5,2,2,TINT.mana); P1(36,4,3,2,PAL.rock); }
  else if (id==='throne') { parts([[3,0,16,24,TINT.candle],[0,14,4,14,PAL.glow],[18,14,4,14,PAL.glow],[0,20,22,8,TINT.candle],[2,28,4,6,PAL.glow],[16,28,4,6,PAL.glow]]); P1(5,3,12,16,TINT.ember); P1(4,20,14,5,TINT.ember); P1(3,0,16,2,PAL.hot); P1(10,0,2,2,TINT.mana); }
}
/* Each thing is painted once per colour and kept. A table's cup stands 5 units above its box, so
   every picture has that much room above it. */
var TSTAMP = {};
function thingStamp(id, tint) { var k = id+'|'+tint, t = THINGS[id]; return TSTAMP[k] || (TSTAMP[k] = stamp(t.w+2,t.h+7,1,6,function () { thingArt(id,TINT[tint]||TINT.candle); })); }
/* The empty room: floor, back wall and rug, by the owner's three choices. */
var HOMES = {};
function homeArt(lay, tint) {
  var key = lay.wall+lay.floor+lay.rug+tint; if (HOMES[key]) return HOMES[key];
  var col = TINT[tint]||TINT.candle;
  HOMES[key] = stamp(240,160,0,0,function () {
    var x, y, r, fl = lay.floor, wl = lay.wall;
    P1(0,0,240,160,PAL.out);
    if (fl==='checker') { for (y = 52, r = 0; y < 150; y += 14, r++) for (x = 10; x < 230; x += 22) P1(x,y,22,Math.min(14,150-y),((x-10)/22+r)%2?PAL.marbleD:PAL.stoneD); }
    else { var base = fl==='flags'?PAL.cobble:PAL.wood, seam = fl==='flags'?PAL.stoneD:PAL.woodD; P1(10,52,220,98,base);
      for (y = 52, r = 0; y < 150; y += 10, r++) { P1(10,y,220,1,seam); for (x = 10+(r%2?11:0); x < 230; x += 22) P1(x,y,1,Math.min(10,150-y),seam); } }
    if (wl==='stone') { P1(10,10,220,42,PAL.stone); for (y = 16, r = 0; y < 44; y += 6, r++) { P1(10,y,220,1,shade(PAL.stone,-20)); for (x = 10+(r%2?9:0); x < 230; x += 18) P1(x,y,1,6,shade(PAL.stone,-20)); } P1(10,44,220,8,PAL.stoneD); }
    else if (wl==='timber') { P1(10,10,220,42,shade(PAL.wood,18)); for (x = 10; x < 230; x += 11) P1(x,10,1,34,PAL.woodD); P1(10,44,220,8,PAL.woodD); }
    else { P1(10,10,220,42,PAL.wall); P1(10,44,220,8,PAL.wallD); }
    P1(10,10,220,3,PAL.wood); P1(10,44,220,1,PAL.wood);
    if (lay.rug==='classic') { parts([[84,84,72,34,col]]); P1(88,88,64,26,shade(col,-45)); P1(92,92,56,18,col); }
    else if (lay.rug==='round') { for (y = 0; y < 38; y++) { var hw = Math.round(38*Math.sqrt(1-Math.pow((y-18.5)/19,2))); P1(120-hw-1,82+y,hw*2+2,1,PAL.out); }
      for (y = 0; y < 38; y++) { var h2 = Math.round(38*Math.sqrt(1-Math.pow((y-18.5)/19,2))), h3 = Math.max(0,h2-6); P1(120-h2,82+y,h2*2,1,col); if (y > 4 && y < 33) P1(120-h3,82+y,h3*2,1,shade(col,-45)); } }
    P1(108,150,24,4,PAL.glow);
  });
  return HOMES[key];
}
/* What is known of each person's room, by their user id. Asked when somebody walks in, and again
   with each heartbeat while standing in somebody else's house, so a change shows without leaving. */
var HOME = {};
function cleanLay(l) {
  if (!l || !l.items || !l.items.length) return ROOM_START;
  var ok = l.items.every(function (it) { return it && THINGS[it[0]]; });
  return ok ? {wall:STYLES.wall[l.wall]?l.wall:'plaster',floor:STYLES.floor[l.floor]?l.floor:'planks',rug:STYLES.rug[l.rug]?l.rug:'classic',items:l.items.map(function (it) { return [it[0],+it[1],+it[2]]; })} : ROOM_START;
}
function roomOf(id) { return (HOME[id] && HOME[id].lay) || ROOM_START; }
function roomTake(r) {
  if (!r || !r.owner) return;
  var old = HOME[r.owner]||{}, h = {lay:cleanLay(r.layout),ready:!!r.ready,src:r.portrait||'',pic:(old.src===(r.portrait||''))?old.pic:null};
  HOME[r.owner] = h;
  /* The owner's real picture goes in the frame, shrunk to the frame's 14 by 18 dots. */
  if (h.src && !h.pic) { var im = new Image(); im.onload = function () {
    var cv = document.createElement('canvas'); cv.width = 14; cv.height = 18;
    var sc = Math.max(14/im.width,18/im.height), w = im.width*sc, hh = im.height*sc; cv.getContext('2d').drawImage(im,(14-w)/2,(18-hh)*.3,w,hh); h.pic = cv; draw(); };
    im.src = h.src; }
  /* The room I am standing in changed: nothing may now be where my feet are. */
  if (S.inside==='user:'+r.owner) { roomSettle(); RM.near = roomNear(B[S.inside]); }
  draw();
}
function roomAsk(id) {
  fetch(R.api+'?room='+encodeURIComponent(id),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (j && j.room) { roomTake(j.room); if (S.inside) renderInside(); } })
    .catch(function () { /* The starting room is drawn until an answer comes. */ });
}
/* The same three rules includes/realm_room.php checks on save: inside the room, a wall thing clear of
   its neighbour, and no two floor things on the same ground (only the bottom 10 units of a thing
   count, so a tall one may rise behind a low one). */
function foot(it) { var t = THINGS[it[0]], f = Math.min(10,t.h); return [it[1],it[2]+t.h-f,t.w,f]; }
function clash(a, b) {
  var ta = THINGS[a[0]], tb = THINGS[b[0]]; if (ta.z!==tb.z) return false;
  if (ta.z==='wall') return a[1] < b[1]+tb.w+2 && b[1] < a[1]+ta.w+2;
  var p = foot(a), q = foot(b); return p[0] < q[0]+q[2] && q[0] < p[0]+p[2] && p[1] < q[1]+q[3] && q[1] < p[1]+p[3];
}
function inRoom(it) { var t = THINGS[it[0]]; return it[1] >= 12 && it[1] <= 228-t.w && (t.z==='wall' ? it[2]===t.y : (it[2]+t.h >= 60 && it[2]+t.h <= 148)); }
function fits(items, i) { if (!inRoom(items[i])) return false; for (var k = 0; k < items.length; k++) if (k!==i && clash(items[i],items[k])) return false; return true; }

/* The three ways in. [button, thing, only in my own house]. */
var HOT = [['shelfBtn','shelf',true],['chestBtn','chest',true],['portraitBtn','portrait',false],
  /* In a show's camp, for somebody on that show: the scroll, the calendar and the board. */
  ['scrollBtn','camp_scroll',false,true],['calBtn','camp_calendar',false,true],['boardBtn','camp_board',false,true]];
function placeHot(o, lay, s, ox, oy) {
  var k = Math.min(2,window.devicePixelRatio||1), open = (o && !o.owner && lay) ? campInfo(o.id) : null, sb = $('slayBtn');
  /* The slaying bar is not a thing in the room's list, so its button is laid over where it is painted. */
  if (sb) { sb.hidden = !open; if (open) { sb.style.left = (ox+30*s)/k+'px'; sb.style.top = oy/k+'px'; sb.style.width = 200*s/k+'px'; sb.style.height = 11*s/k+'px'; } }
  HOT.forEach(function (h) {
    var b = $(h[0]), it = null; if (!b) return;
    if (o && lay && (h[3] ? open : (o.owner && (o.owner.me || !h[2])))) lay.items.forEach(function (x) { if (x[0]===h[1]) it = x; });
    b.hidden = !it; b.classList.toggle('is-near',!!it && RM.near===h[0]); if (!it) return;
    var t = THINGS[h[1]];
    b.style.left = (ox+(it[1]-1)*s)/k+'px'; b.style.top = (oy+(it[2]-1)*s)/k+'px'; b.style.width = (t.w+2)*s/k+'px'; b.style.height = (t.h+2)*s/k+'px';
    if (h[1]==='portrait') { var say = o.owner.me ? 'Your profile' : firstName(o.owner.name), tag = $('portraitTag');
      if (tag && tag.textContent!==say) { tag.textContent = say; b.setAttribute('aria-label',o.owner.me?'Your profile, in the portrait':'The profile of '+o.owner.name+', in the portrait'); } }
  });
  /* The merchant's stall in the Tavern gets the same kind of button. */
  var mb = $('shopBtn'), pr = o ? stallOf(o) : null;
  if (mb) { mb.hidden = !pr; mb.classList.toggle('is-near',!!pr && RM.near==='shopBtn');
    if (pr) { mb.style.left = (ox+(pr[1]-1)*s)/k+'px'; mb.style.top = (oy+(pr[2]-1)*s)/k+'px'; mb.style.width = (pr[3]+2)*s/k+'px'; mb.style.height = (pr[4]+2)*s/k+'px'; } }
}
/* Where the merchant stands in the room `o`, or null: only a meeting place has one. */
function stallOf(o) { var l = (o && !o.owner && PROPS[o.kind]) || [], i; for (i = 0; i < l.length; i++) if (l[i][0]==='stall') return l[i]; return null; }
/* The middle of the bottom edge of a thing in the room I am standing in, in the room's own units,
   so an animation can be started where that thing is drawn. Null if it is not there. */
function thingSpot(name) {
  var o = B[S.inside]; if (!o) return null;
  var land = o.owner ? null : campOf(o.id), lay = o.owner ? roomOf(o.owner.id) : land ? campLay(o.id) : null;
  if (!lay) return null;
  var hit = null; lay.items.forEach(function (x) { if (x[0]===name) hit = x; });
  if (!hit) return null;
  var t = THINGS[name];
  return {x:hit[1]+t.w/2,y:hit[2]+t.h};
}
/* A thing was opened: its animation plays, and the window follows about half a second later so the
   animation is seen before the window covers it. With "reduce motion" set the window opens at once. */
function fxThing(name, open) {
  var p = thingSpot(name);
  if (!p || calm) { open(); return; }
  /* The pages leave from the middle of the shelf, above its name tag, not from its bottom edge. */
  fx(name==='chest'?'chest':name==='shelf'?'shelf':'portrait',p.x,name==='shelf'?p.y-14:p.y);
  setTimeout(open,420);
}
function paintThing(c, it, o) {
  /* In a camp a decoration carries who stood it there (it[3]) and wears that person's colour. */
  var who = it[3] ? person(it[3]) : null, st = thingStamp(it[0],who ? who.cloak : o.tint), h = o.owner ? HOME[o.owner.id] : null;
  c.drawImage(st.cv,it[1]-st.ox,it[2]-st.oy);
  if (it[0]==='portrait' && h && h.pic) c.drawImage(h.pic,it[1]+2,it[2]+2);
}
/* Where somebody stands when this page has not been told: the places people took before anybody
   could walk in a room. Mine is no longer one of them. */
var SEATS = [[120,132],[86,126],[154,126],[60,134],[180,134],[100,112],[140,112],[46,118],[194,118]];
var DECO = {on:false,lay:null,sel:-1,drag:null,bad:false}, RT = null;

/* ---------- walking inside a room (David, 2026-11-13) ----------
   "Let's also have the option for the avatars to move freely inside each house."

   Inside a room my character now walks as it does on the map: the arrow keys, or a click on the
   floor, which finds a way round the furniture. Everything is in the room's own units, 240 by 160,
   and a position is where the FEET are.

   WHAT IS SOLID. The walls, and the foot of every thing that stands on the floor: the same bottom
   10 units decorating already uses to decide whether two things are on the same ground (foot()). So
   a character can step BEHIND a tall thing and be covered by it, and never stands inside one. In a
   meeting place the solid things are PROPS. Things on the wall and the rug stop nobody.

   THE DOOR is the strip of light at the bottom of the room. Walking onto it leaves the room, as
   Escape and Leave room do. You come in just above it.

   WALKING UP TO SOMETHING. The bookshelf, the chest and the portrait still answer a click. Standing
   next to one lights its frame, and Enter opens it. */
var FLOOR = {x0:18,x1:222,y0:58,y1:148}, DOORWAY = {x0:110,x1:130,y1:156,out:152,come:[120,142]}, ROOM_SPEED = 80;
var RM = {x:120,y:142,walk:null,raf:0,lt:0,near:null,leaving:false};
/* What stands on the floor of the room `o`: its foot (x,y,w,h) and `by`, the line it is sorted by. */
function roomSolids(o) {
  var out = [];
  if (!o) return out;
  var lay = o.owner ? roomOf(o.owner.id) : campOf(o.id) ? campLay(o.id) : null;
  if (lay) lay.items.forEach(function (it) { var t = THINGS[it[0]]; if (t.z!=='floor') return; var ft = foot(it); out.push({x:ft[0],y:ft[1],w:ft[2],h:ft[3],by:it[2]+t.h}); });
  else (PROPS[o.kind]||[]).forEach(function (pr) { var d = Math.min(10,pr[4]); out.push({x:pr[1],y:pr[2]+pr[4]-d,w:pr[3],h:d,by:pr[2]+pr[4]}); });
  return out;
}
/* May a pair of feet stand at x,y? A character is 14 wide and 4 deep where it touches the ground. */
function roomFree(x, y, sol) {
  var door = x >= DOORWAY.x0 && x <= DOORWAY.x1 && y > FLOOR.y1 && y <= DOORWAY.y1;
  if (!door && (x < FLOOR.x0 || x > FLOOR.x1 || y < FLOOR.y0 || y > FLOOR.y1)) return false;
  for (var i = 0; i < sol.length; i++) { var q = sol[i]; if (x+7 > q.x && x-7 < q.x+q.w && y > q.y && y-4 < q.y+q.h) return false; }
  return true;
}
function roomClear(a, b, sol) { var n = Math.ceil(Math.hypot(b.x-a.x,b.y-a.y)/2), k; for (k = 1; k <= n; k++) if (!roomFree(a.x+(b.x-a.x)*k/n,a.y+(b.y-a.y)*k/n,sol)) return false; return true; }
/* The way on foot across the room, round whatever is solid, as a few straight stretches. A spot
   that cannot be stood on (the middle of a table) gives the way to the nearest spot that can, so a
   click on a thing walks up to it. Squares of 4 units; an empty list when there is nowhere to go. */
function roomRoute(from, to, sol) {
  var W = 60, H = 40, C = 4, ok = new Uint8Array(W*H), prev = new Int32Array(W*H), i, j, x, y, d, h;
  var cell = function (p) { return Math.max(0,Math.min(H-1,Math.floor(p.y/C)))*W+Math.max(0,Math.min(W-1,Math.floor(p.x/C))); };
  var mid = function (k) { return {x:(k%W)*C+2,y:Math.floor(k/W)*C+2}; };
  for (i = 0; i < W*H; i++) { prev[i] = -1; var m = mid(i); ok[i] = roomFree(m.x,m.y,sol) ? 1 : 0; }
  var s = cell(from), t = cell(to), q = [s], best = s, bd = 1e9, D = [[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]];
  prev[s] = s;
  for (h = 0; h < q.length && prev[t] < 0; h++) { i = q[h]; x = i%W; y = (i-x)/W;
    for (d = 0; d < 8; d++) { var nx = x+D[d][0], ny = y+D[d][1]; j = ny*W+nx;
      if (nx<0||ny<0||nx>=W||ny>=H||prev[j]>=0||!ok[j]) continue;
      /* No cutting a corner between two solid squares. */
      if (d > 3 && (!ok[y*W+nx] || !ok[ny*W+x])) continue;
      prev[j] = i; q.push(j); } }
  for (h = 0; h < q.length; h++) { var c = mid(q[h]), dd = Math.hypot(c.x-to.x,c.y-to.y); if (dd < bd) { bd = dd; best = q[h]; } }
  var exact = prev[t] >= 0 && roomFree(to.x,to.y,sol), end = exact ? t : best, path = exact ? [{x:to.x,y:to.y}] : [], k;
  if (!exact && end===s) return [];
  for (k = end; k!==s; k = prev[k]) path.push(mid(k));
  path.reverse();
  /* Straighten it: from where I am, head for the farthest point I can reach in a straight line. */
  var out = [], cur = from; i = 0;
  while (i < path.length) { j = path.length-1; while (j > i && !roomClear(cur,path[j],sol)) j--; out.push(path[j]); cur = path[j]; i = j+1; }
  return out;
}
/* Something solid is where I stand (the owner moved a table, or furnished the doorway): step to the
   nearest free spot, so nobody is ever shut inside a thing. */
function roomSettle() {
  if (!S.inside) return;
  var sol = roomSolids(B[S.inside]), best = null, bd = 1e9, x, y;
  if (roomFree(RM.x,RM.y,sol)) return;
  for (y = FLOOR.y0; y <= FLOOR.y1; y += 2) for (x = FLOOR.x0; x <= FLOOR.x1; x += 2) { var d = Math.hypot(x-RM.x,y-RM.y); if (d < bd && roomFree(x,y,sol)) { bd = d; best = [x,y]; } }
  if (best) { RM.x = best[0]; RM.y = best[1]; RM.walk = null; }
}
/* Which of the bookshelf, the chest and the portrait am I standing next to? The same rule as
   placeHot(): the shelf and the chest only in my own house, a portrait in anybody's. */
function roomNear(o) {
  var best = null, lay = !o || DECO.on ? null : o.owner ? roomOf(o.owner.id) : campInfo(o.id) ? campLay(o.id) : null;
  /* In the Tavern: am I standing at the merchant's counter? */
  var pr = (o && !DECO.on) ? stallOf(o) : null;
  if (pr && $('shopBtn')) { var sx = Math.max(pr[1]-RM.x,0,RM.x-pr[1]-pr[3]), sy = Math.max(pr[2]+pr[4]-10-RM.y,0,RM.y-pr[2]-pr[4]);
    if (sx <= 12 && sy <= 9) return 'shopBtn'; }
  if (!lay) return null;
  HOT.forEach(function (h) {
    /* A house answers with its three, a camp with its own three (h[3]). */
    if ((h[3] ? !!o.owner : (!o.owner || (!o.owner.me && h[2]))) || !$(h[0])) return;
    lay.items.forEach(function (it) {
      if (it[0]!==h[1]) return;
      var t = THINGS[it[0]], near;
      if (t.z==='wall') near = RM.y <= 70 && RM.x >= it[1]-6 && RM.x <= it[1]+t.w+6;
      else { var ft = foot(it), ax = Math.max(ft[0]-RM.x,0,RM.x-ft[0]-ft[2]), ay = Math.max(ft[1]-RM.y,0,RM.y-ft[1]-ft[3]); near = ax <= 12 && ay <= 9; }
      if (near) best = h[0];
    });
  });
  return best;
}
/* I have just come in: stand at the door. */
function roomCome() {
  RM.x = DOORWAY.come[0]; RM.y = DOORWAY.come[1]; RM.walk = null; RM.near = null; RM.leaving = false; held.clear(); roomSettle(); spotSpace();
  /* Said once on each computer, since nothing on the screen says a room can be walked in. */
  try { if (!localStorage.getItem('realmWalkTip')) { localStorage.setItem('realmWalkTip','1'); toast('You can walk around in here. Use the arrow keys, or click the floor. Walk onto the light at the bottom to leave.'); } } catch (e) { /* A private window may refuse; then it is simply not said. */ }
}
/* After every step: the door leaves the room, and the thing I am next to lights up. True when I left. */
function roomMoved() {
  if (RM.y >= DOORWAY.out) { RM.walk = null; leave(); return true; }
  RM.near = roomNear(B[S.inside]); spotDirty();
  return false;
}
function roomWalk(to) {
  if (!S.inside || DECO.on || RM.leaving) return;
  var path = roomRoute({x:RM.x,y:RM.y},to,roomSolids(B[S.inside]));
  if (!path.length) return;
  /* Somebody who asked for less motion is put there, as on the map. */
  if (calm) { var e = path[path.length-1]; RM.x = e.x; RM.y = e.y; RM.walk = null; if (!roomMoved()) draw(); return; }
  RM.walk = {path:path,i:0}; RM.lt = 0;
  if (!RM.raf) RM.raf = requestAnimationFrame(roomTick);
}
function roomTick(ts) {
  var w = RM.walk; if (!w || !S.inside || RM.leaving) { RM.raf = 0; RM.walk = null; return; }
  var st = RM.lt ? ROOM_SPEED*Math.min(.05,(ts-RM.lt)/1000) : 0; RM.lt = ts;
  while (st > 0 && w.i < w.path.length) { var p = w.path[w.i], dx = p.x-RM.x, dy = p.y-RM.y, d = Math.hypot(dx,dy);
    if (d<=st) { RM.x = p.x; RM.y = p.y; st -= d; w.i++; } else { RM.x += dx/d*st; RM.y += dy/d*st; st = 0; } }
  if (w.i >= w.path.length) RM.walk = null;
  if (roomMoved()) { RM.raf = 0; return; }
  draw();
  RM.raf = RM.walk ? requestAnimationFrame(roomTick) : 0;
}
/* One step with the arrow keys, sliding along whatever is in the way. */
function roomStep(dx, dy, dt) {
  if (DECO.on || RM.leaving || !B[S.inside]) return;
  var sol = roomSolids(B[S.inside]), n = Math.hypot(dx,dy), st = ROOM_SPEED*dt, nx = RM.x+dx/n*st, ny = RM.y+dy/n*st;
  RM.walk = null;
  if (roomFree(nx,ny,sol)) { RM.x = nx; RM.y = ny; } else if (dx && roomFree(nx,RM.y,sol)) RM.x = nx; else if (dy && roomFree(RM.x,ny,sol)) RM.y = ny;
  if (!roomMoved()) draw();
}
/* A click on the room: walk there. A click on the door walks out through it. */
if ($('mapbox')) $('mapbox').addEventListener('click',function (e) {
  if (!S.inside || DECO.on || !RT || RM.leaving || e.target!==$('mapcv')) return;
  var p = roomPoint(e); if (p.x < 0 || p.x > 240 || p.y < 0 || p.y > 164) return;
  if (p.y > 146 && p.x > 100 && p.x < 140) roomWalk({x:120,y:DOORWAY.out+2});
  else roomWalk({x:Math.max(FLOOR.x0,Math.min(FLOOR.x1,p.x)),y:Math.max(FLOOR.y0,Math.min(FLOOR.y1,p.y))});
});
/* Who is in the room `o` with me, the owner first: the order the seats are given in. */
function roomPeople(o) {
  var list = [];
  if (o.owner && !o.owner.me && o.owner.state!=='away' && o.owner.place===o.id) list.push(o.owner);
  ST.people.forEach(function (p) { if (!p.me && p.state!=='away' && p.inside && p.place===o.id && list.indexOf(p) < 0) list.push(p); });
  return list.slice(0,SEATS.length-1);
}
/* Where one of them stands: the spot they walked to, if they have walked since they came in. If
   not, they are still by the door, side by side so that nobody hides anybody. Before migration 087
   nothing is known, and everybody takes the old seats. */
function standOf(p, i) {
  var sp = SP.ready ? SP.at[p.id] : null, n = i+1;
  if (sp) return {x:sp[0],y:sp[1]};
  if (SP.ready) return {x:DOORWAY.come[0]+(n%2?-1:1)*Math.ceil(n/2)*22,y:DOORWAY.come[1]};
  return {x:SEATS[n][0],y:SEATS[n][1]};
}

/* ---------- seeing each other walk (David, 2026-11-13, migration 087) ----------
   Asked whether the others in a room should see you walk: "Yes". Then: "let's make see other people
   walk a feature across the board", so it is the same on the village map.

   SENDING. My page sends the spot I stand on (POST stand) when I stop, and at most once every 2
   seconds while I am on the way. Standing still sends nothing at all.

   RECEIVING costs no request of its own. Where the others stand comes back with what this page asks
   anyway: the unread counts, an open conversation, the voice beat, the heartbeat, and my own `stand`.
   The one change: the unread counts are asked every 3 seconds instead of every 8 while I share a
   room with somebody, or for 15 seconds after anybody I can see has moved. A quiet village asks
   exactly what it asked before.

   SHOWING. A spot arrives a second or three late and in jumps, so nobody is drawn AT the spot: each
   character walks from where this page last drew them to where they now are, round the furniture or
   the houses, as my own does (glide). They are therefore a little behind, and never teleport.

   A SPOT IS FOR SHOW. Nothing here or on the server uses one to decide what anybody may do; David
   kept "in the same room" as the rule for praise, trades and voice the same day. */
var SP = {ready:false,at:{},dirty:false,last:0,timer:0,sent:'',hot:0,sig:'',n:0};
var OTH = {}, oraf = 0, olt = 0;
/* What the server says about the people I can see: {at: the room I am in or '' for the map,
   who: [[id, x, y, place], ...]}, x and y null for somebody who has not walked since arriving. Null
   before migration 087; absent from an answer that does not carry it. */
function takeSpots(sp) {
  if (sp === undefined) return;
  if (!sp || !sp.who) { SP.ready = false; return; }
  SP.ready = true;
  /* An answer that was on its way while I walked through a door is about the other side of it. */
  if (sp.at !== (S.inside || '')) return;
  var at = {}, sig = [], changed = false;
  sp.who.forEach(function (r) {
    var p = person(r[0]); if (!p || p.me) return;
    at[p.id] = r[1] === null ? null : [+r[1],+r[2]]; sig.push(r.join(','));
    /* Somebody the map still has elsewhere has just walked in, or out: this is the newer word. */
    if (S.inside ? (p.place!==S.inside || !p.inside) : (!!r[3] && (p.inside || p.place!==r[3]))) { p.place = S.inside || r[3]; p.inside = !!S.inside; changed = true; }
    if (p.state==='away') { p.state = 'online'; changed = true; }
  });
  SP.at = at; sig = sig.sort().join(';');
  if (sig!==SP.sig) { SP.sig = sig; SP.hot = Date.now()+15000; }
  if (changed) { renderSide(); renderInside(); }
  draw();
}
/* Where to draw somebody whose spot is tx,ty in `space` ('map', or a room's id): on the way there
   from wherever this page drew them last. */
function glide(id, tx, ty, space) {
  var o = OTH[id];
  if (!o || o.space!==space || calm) o = OTH[id] = {x:tx,y:ty,tx:tx,ty:ty,path:null,i:0,v:0,space:space};
  else if (o.tx!==tx || o.ty!==ty) {
    var from = {x:o.x,y:o.y}, to = {x:tx,y:ty}, path = space==='map' ? route(from,to) : roomRoute(from,to,roomSolids(B[space])), len = 0, a = from, k;
    if (!path || !path.length) path = [to];
    /* Always end exactly where they are, even if this page thinks a table is there. */
    k = path[path.length-1]; if (k.x!==tx || k.y!==ty) path.push(to);
    for (k = 0; k < path.length; k++) { len += Math.hypot(path[k].x-a.x,path[k].y-a.y); a = path[k]; }
    /* At a walk, or faster when they are far behind: nobody takes more than 4 seconds to catch up. */
    o.tx = tx; o.ty = ty; o.path = path; o.i = 0; o.v = Math.max(space==='map'?230:ROOM_SPEED,len/4);
    if (!oraf) { olt = 0; oraf = requestAnimationFrame(othTick); }
  }
  o.n = SP.n;
  return o;
}
function othTick(ts) {
  var dt = olt ? Math.min(.1,(ts-olt)/1000) : 0, any = false; olt = ts;
  Object.keys(OTH).forEach(function (id) {
    var o = OTH[id]; if (!o.path) return;
    var st = o.v*dt;
    while (st > 0 && o.i < o.path.length) { var p = o.path[o.i], dx = p.x-o.x, dy = p.y-o.y, d = Math.hypot(dx,dy);
      if (d<=st) { o.x = p.x; o.y = p.y; st -= d; o.i++; } else { o.x += dx/d*st; o.y += dy/d*st; st = 0; } }
    if (o.i >= o.path.length) o.path = null; else any = true;
  });
  draw();
  oraf = any ? requestAnimationFrame(othTick) : 0;
}
/* I moved. The spot goes out by itself, at the pace described above. */
function spotDirty() {
  if (!SP.ready) return;
  /* The first word of a walk goes a second in, not at the first step, which says next to nothing. */
  if (!SP.dirty && Date.now()-SP.last > 2000) SP.last = Date.now()-1000;
  SP.dirty = true;
  if (!SP.timer) SP.timer = setTimeout(spotFlush,250);
}
function spotFlush() {
  SP.timer = 0; if (!SP.dirty || !SP.ready) return;
  var moving = S.inside ? (!!RM.walk || held.size > 0) : (!!S.walk || held.size > 0), wait = SP.last+(moving?2000:600)-Date.now();
  if (wait > 0 || RM.leaving || S.enter!==null) { SP.timer = setTimeout(spotFlush,250); return; }
  var x = Math.round(S.inside?RM.x:S.pos.x), y = Math.round(S.inside?RM.y:S.pos.y), key = (S.inside||'')+'|'+x+','+y, body = new URLSearchParams();
  SP.dirty = false; SP.last = Date.now();
  if (key===SP.sent) return;
  SP.sent = key; body.append('stand',x+','+y);
  fetch(R.api,{method:'POST',body:body,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) takeSpots(j.spots); })
    .catch(function () { /* The next step sends a newer one. */ });
}
/* I went through a door: what I knew of the other side of it is no use on this one. */
function spotSpace() { SP.at = {}; SP.sig = ''; SP.dirty = false; SP.sent = ''; }
function drawRoom(c, cw, ch) {
  var o = B[S.inside]; if (!o) { placeHot(null); return; }
  var home = !!o.owner, land = home ? null : campOf(o.id), deco = DECO.on && (land ? DECO.camp===o.id : (home && o.owner.me && !DECO.camp));
  var lay = deco ? DECO.lay : (home ? roomOf(o.owner.id) : land ? campLay(o.id) : null);
  /* While decorating, the library lies along the bottom, so the room is fitted into what is left. */
  var k = Math.min(2,window.devicePixelRatio||1), bar = deco ? $('decoBar') : null, pad = bar ? (bar.offsetHeight+12)*k : 0;
  /* In a camp the slaying bar is the top strip of the picture, and it is there to be seen at a
     glance: so the room is fitted BELOW the bar that names the room, however tall that has wrapped. */
  var ib = land ? $('insideBar') : null, top = ib && !ib.hidden ? (ib.offsetTop+ib.offsetHeight+6)*k : 0;
  var s = Math.max(1,Math.min(cw/240,(ch-Math.max(70,top+8*k)-pad)/160)), ox = Math.round((cw-240*s)/2), oy = Math.max(Math.round(top),Math.round((ch-pad-160*s)/2+30)), f = S.frame;
  RT = {s:s,ox:ox,oy:oy};
  placeHot(deco?null:o,lay,s,ox,oy);
  c.setTransform(s,0,0,s,ox,oy);
  /* Back to front: whatever stands lower on the floor is nearer, and covers what is behind it. */
  var order = [];
  if (!home) { c.drawImage(roomArt(o.kind,o.tint).cv,0,0); (PROPS[o.kind]||[]).forEach(function (pr) { order.push({y:pr[2]+pr[4],prop:pr}); }); }
  else c.drawImage(homeArt(lay,o.tint).cv,0,0);
  /* A house's things, or a camp's: its fixtures and everybody's one decoration. */
  if (lay) lay.items.forEach(function (it) { if (THINGS[it[0]].z==='wall') paintThing(c,it,o); else order.push({y:it[2]+THINGS[it[0]].h,it:it}); });
  if (land) slayPaint(c,land);
  /* The way out, wide enough to stand on. */
  c.fillStyle = PAL.glow; c.fillRect(106,150,28,8); c.fillStyle = PAL.hot; c.fillRect(110,155,20,3);
  /* THE DOORWAY SAYS "EXIT" (David, 2026-11-13, with a picture of the lit doorway at the bottom of
     his room: "add the exit name here in each room's door"). A small plate on the floor just inside
     it, in every room, painted before the people so somebody standing on it covers it. */
  c.font = 'bold 7px "Courier New", monospace'; c.textAlign = 'center'; c.textBaseline = 'alphabetic';
  c.fillStyle = PAL.plate; c.fillRect(107,139,26,10); c.lineWidth = .75; c.strokeStyle = PAL.primaryBorder; c.strokeRect(107.4,139.4,25.2,9.2);
  c.fillStyle = PAL.primary; c.fillText('Exit',120,146.5); c.textAlign = 'start';
  var my = me()||{cloak:'mana',hat:'none',pet:'none'}, who = [], walking = !!RM.walk || held.size > 0;
  roomPeople(o).forEach(function (p, i) { var at = standOf(p,i), gl = glide(p.id,at.x,at.y,o.id); who.push({p:p,x:Math.round(gl.x),y:Math.round(gl.y),bob:gl.path?(Math.floor(Date.now()/130)%2)*2:(f+i)%4===0?2:0}); });
  who.push({p:my,x:Math.round(RM.x),y:Math.round(RM.y),bob:walking?(Math.floor(Date.now()/130)%2)*2:0,mine:true});
  who.forEach(function (d) { order.push(d); });
  order.sort(function (a, b) { return (a.y-b.y) || ((a.p?1:0)-(b.p?1:0)); });
  order.forEach(function (d) {
    if (d.it) { c.setTransform(s,0,0,s,ox,oy); paintThing(c,d.it,o); return; }
    if (d.prop) { c.setTransform(s,0,0,s,ox,oy); var ps = propStamp(d.prop); c.drawImage(ps.cv,d.prop[1]-ps.ox,d.prop[2]-ps.oy); return; }
    var px = Math.round(d.x/.9), py = Math.round(d.y/.9), idle = !d.mine && d.p.state==='idle';
    c.setTransform(s*.9,0,0,s*.9,ox,oy);
    /* While decorating, people are faint, so the furniture behind them can be seen and picked. */
    c.globalAlpha = deco ? .3 : idle ? .6 : 1;
    /* The mark under my own feet, as on the map, so I can tell which one I am moving. */
    if (d.mine && !deco) { c.fillStyle = PAL.win; c.fillRect(px-13,py+2,26,2); c.fillRect(px-13,py,2,2); c.fillRect(px+11,py,2,2); }
    sprite(c,px,py,d.p,d.bob); c.globalAlpha = 1;
    if (idle && !deco) zed(c,px,py);
    LASTAT[d.p.id] = {x:d.x,y:d.y,room:true};
  });
  c.setTransform(s,0,0,s,ox,oy);
  /* Who is in voice, and who is speaking right now (voiceMark, in the voice section). Each mark is
     drawn where that person stands, so it goes with them when they walk. */
  var sw = shareWho();
  who.forEach(function (d) { if (d.mine ? VO.on : d.p.voice) { voiceMark(c,d.x,d.y,isTalking(d.mine?ST.me:d.p.id),d.mine?VO.muted:!!d.p.muted,f);
    /* A small screen on the other side of the head: this person is sharing theirs (shareMark). */
    if (sw && sw===(d.mine?ST.me:d.p.id)) shareMark(c,d.x,d.y); } });
  fxPaint(c,true);
  if (deco) {
    if (DECO.sel >= 0 && lay.items[DECO.sel]) { var it = lay.items[DECO.sel], t = THINGS[it[0]], x0 = it[1]-3, y0 = it[2]-3, w = t.w+6, h = t.h+6;
      c.fillStyle = DECO.bad ? TINT.ember : TINT.mana; c.fillRect(x0,y0,w,1); c.fillRect(x0,y0+h-1,w,1); c.fillRect(x0,y0,1,h); c.fillRect(x0+w-1,y0,1,h); }
    return;
  }
  /* A bubble sits over its speaker's head and follows them. Near the back wall it would leave the
     picture, so it is held just inside the top and may then cover the top of a hat. */
  var now = Date.now(), boxes = [];
  who.forEach(function (d) { var b = BUB[d.mine?ST.me:d.p.id]; if (b && b.until > now) { var box = bubbleBox(c,d.x,d.y-60,b.text); box.by = Math.max(-24,box.by); box.until = b.until; boxes.push(box); } });
  /* Lowest first, so a lifted bubble's line is drawn over nothing that matters. */
  var placed = placeBubbles(boxes);
  placed.sort(function (p, q) { return p.by-q.by; }).forEach(function (bx) { paintBubble(c,bx); });
  tagsUnderBubbles(c.canvas,placed,s,ox,oy);
}
/* WHAT SOMEBODY SAYS GOES IN FRONT OF THE NAME OF A THING (David, 2026-11-13, with a picture of
   "Your tasks" and "Your profile" sitting on top of a speech bubble: "let's make sure the names of
   the objects are behind the dialogues"). The bubbles are painted on the canvas; the names are tags
   on the see-through buttons laid over the bookshelf, the chest and the portrait, and anything on
   the page is always above anything painted. So a name that a bubble reaches is put away for as long
   as the bubble is up, which is what "behind it" looks like, and comes back when the bubble goes.
   The button itself stays where it is and still works. */
function tagsUnderBubbles(cv, placed, s, ox, oy) {
  if (!cv || !cv.getBoundingClientRect) return;
  var k = Math.min(2,window.devicePixelRatio||1), at = cv.getBoundingClientRect();
  HOT.concat([['shopBtn']]).forEach(function (h) {
    var b = $(h[0]), tag = b && b.querySelector('span'), under = false; if (!tag) return;
    if (!b.hidden) { var r = tag.getBoundingClientRect();
      placed.forEach(function (p) { var x = at.left+(ox+(p.bx-1)*s)/k, y = at.top+(oy+(p.by-1)*s)/k, w = (p.w+2)*s/k, hh = (p.h+2)*s/k;
        if (r.left < x+w && x < r.right && r.top < y+hh && y < r.bottom) under = true; }); }
    tag.style.visibility = under ? 'hidden' : '';
  });
}

/* ---------- decorating (David, 2026-11-12) ----------
   Only in your own house. The room on screen becomes a working copy: things are dragged (or picked
   and moved with the arrow keys), added from the library along the bottom, and removed. Nothing is
   sent until Save, and Cancel or leaving the house throws the copy away. A thing dropped where it
   does not fit goes back to where it was, so the copy can always be saved. */
function copyLay(l) { return {wall:l.wall,floor:l.floor,rug:l.rug,items:l.items.map(function (i) { return i.slice(); })}; }
function decoStart() {
  var m = me(), k = campInfo(S.inside); if (!m || DECO.on) return;
  if (k) { /* A show's camp: the working copy is the camp as it stands, and only my own thing in it can be picked. */
    if (!k.ready) { toast('Decorating a camp needs a database update first. An administrator opens migrate.php once.'); return; }
    DECO = {on:true,camp:S.inside,lay:campLay(S.inside),sel:-1,drag:null,bad:false}; DECO.sel = campMine();
  } else {
    if (S.inside!=='user:'+m.id) return;
    if (HOME[m.id] && !HOME[m.id].ready) { toast('Decorating needs a database update first. An administrator opens migrate.php once.'); return; }
    DECO = {on:true,lay:copyLay(roomOf(m.id)),sel:-1,drag:null,bad:false};
  }
  RM.walk = null; RM.near = null; held.clear();
  $('mapbox').classList.add('is-deco'); renderInside(); renderDeco(); draw();
}
function decoEnd() {
  if (!DECO.on) return;
  DECO.on = false; DECO.camp = null; DECO.drag = null; var b = $('decoBar'); if (b) { b.hidden = true; b.innerHTML = ''; }
  $('mapbox').classList.remove('is-deco'); roomSettle(); RM.near = roomNear(B[S.inside]); renderInside(); draw();
}
function decoCount(id) { return DECO.lay.items.filter(function (it) { return it[0]===id; }).length; }
function dseg(label, key) { var o = STYLES[key]; return '<span class="rdecopick"><span>'+label+'</span><span class="rseg">'+Object.keys(o).map(function (v) { return '<button type="button" data-decostyle="'+key+'" data-val="'+v+'" aria-pressed="'+(DECO.lay[key]===v)+'">'+o[v]+'</button>'; }).join('')+'</span></span>'; }
function decoThumbs(bar) {
  var m = me(), tint = m ? m.cloak : 'mana';
  Array.prototype.forEach.call(bar.querySelectorAll('canvas[data-thumb]'),function (cv) { var c = cv.getContext('2d'); var k = +cv.getAttribute('data-k'); c.imageSmoothingEnabled = false; c.setTransform(k,0,0,k,0,0); c.drawImage(thingStamp(cv.getAttribute('data-thumb'),tint).cv,0,0); });
}
function renderDeco() {
  var bar = $('decoBar'); if (!bar || !DECO.on) return;
  if (DECO.camp) { renderCampDeco(bar); return; }
  var it = DECO.lay.items[DECO.sel], t = it ? THINGS[it[0]] : null, keep = bar.querySelector('.rdecolib'), left = keep ? keep.scrollLeft : 0;
  var h = '<div class="rdecohead"><b>Decorating your office</b><small>Drag a thing to move it, or pick it and use the arrow keys.</small><span class="rgrow"></span>'
    +'<button type="button" class="rbtn rsmall" data-decoreset="1">Starting room</button><button type="button" class="rbtn rsmall" data-decocancel="1">Cancel</button><button type="button" class="rbtn rprimary rsmall" data-decosave="1">Save</button></div>'
    +'<div class="rdecorow">'+dseg('Wall','wall')+dseg('Floor','floor')+dseg('Rug','rug')+'</div>'
    +'<div class="rdecorow rdecosel"><button type="button" class="rbtn rsmall" data-deconext="1">Pick the next thing</button>'
    +(t ? '<span>Picked: <b>'+esc(t.n)+'</b></span>'+(t.req ? '<small>This one stays in every house, '+esc(t.req)+'.</small>' : '<button type="button" class="rbtn rsmall" data-decoremove="1">Remove it</button>') : '<small>Nothing is picked.</small>')+'</div>'
    +'<div class="rdecolib" role="group" aria-label="The library">';
  Object.keys(THINGS).forEach(function (id) {
    if (THINGS[id].fix) return;
    /* Small things are shown at twice their size, big ones at their own, so the row stays low. */
    var x = THINGS[id], n = decoCount(id), full = n >= x.max, k = (x.h > 22 || x.w > 32) ? 1 : 2;
    /* What the merchant sells shows its price until it is mine; then it is a thing like any other. */
    var sale = x.gold && !owns(id), cost = price(id);
    h += '<button type="button" class="rthing'+(sale?' is-gold':'')+'" data-decoadd="'+id+'"'+((sale||full)?' aria-disabled="true"':'')+' title="'+esc(sale?'The merchant in the Tavern sells this':full?(x.max===1?'Already in the room':'No more of these fit the rules'):'Add to the room')+'">'
      +'<canvas data-thumb="'+id+'" data-k="'+k+'" width="'+(x.w+2)*k+'" height="'+(x.h+7)*k+'" style="width:'+(x.w+2)*k+'px;height:'+(x.h+7)*k+'px"></canvas><span>'+esc(x.n)+'</span><small>'+(sale?(cost?cost+' coins, at the merchant':'At the merchant'):x.req?'In every house':(x.gold?'Yours, ':'')+n+' of '+x.max)+'</small></button>';
  });
  bar.innerHTML = h+'</div>'; bar.hidden = false;
  decoThumbs(bar);
  var lib = bar.querySelector('.rdecolib'); if (lib) lib.scrollLeft = left;
}
/* A new thing goes to the first free spot: along the wall, or row by row down the floor. */
function decoAdd(id) {
  var t = THINGS[id], items = DECO.lay.items, x, y; if (!t || t.fix) return;
  /* What the merchant sells stands only in the office of somebody who owns it, never in a camp. */
  if (t.gold && DECO.camp) { toast('What the merchant sells is for your own office, not for a camp.'); return; }
  if (t.gold && !owns(id)) { toast('The merchant in the Tavern sells the '+t.n.toLowerCase()+(price(id)?' for '+price(id)+' coins':'')+'. Once it is yours, it is here.'); return; }
  if (DECO.camp) { /* One each (David, 2026-11-13). The server refuses a second as well. */
    if (t.req) return;
    if (campMine() >= 0) { DECO.sel = campMine(); toast('You already have one thing in this camp. Take it out first to choose another.'); renderDeco(); draw(); return; } }
  else if (decoCount(id) >= t.max) { /* Already there: pick it, so it can be found and moved. */
    for (x = 0; x < items.length; x++) if (items[x][0]===id) { DECO.sel = x; break; } renderDeco(); draw(); return; }
  var it = [id,12,t.z==='wall'?t.y:0]; if (DECO.camp) it.push(ST.me); items.push(it);
  var ys = t.z==='wall' ? [t.y] : [78-t.h,100-t.h,122-t.h,144-t.h,66-t.h,90-t.h,112-t.h,134-t.h];
  for (y = 0; y < ys.length; y++) for (x = 12; x <= 228-t.w; x += 2) { it[1] = x; it[2] = ys[y]; if (fits(items,items.length-1)) { DECO.sel = items.length-1; renderDeco(); draw(); return; } }
  items.pop(); toast('There is no free spot for that. Move or remove something first.');
}
function decoRemove() {
  if (DECO.camp) DECO.sel = campMine();
  var it = DECO.lay.items[DECO.sel]; if (!it || THINGS[it[0]].req || THINGS[it[0]].fix) return;
  DECO.lay.items.splice(DECO.sel,1); DECO.sel = -1; renderDeco(); draw();
}
function decoSave() {
  var m = me(); if (!m) return;
  if (DECO.camp) { campSave(); return; }
  send({save_room:JSON.stringify(DECO.lay)}).then(function (j) {
    if (!j) { toast('Your office could not be saved. Try again in a moment.'); return; }
    if (j.room) roomTake(j.room);
    if (j.saved) { decoEnd(); toast('Your office is saved.'); }
  });
}
function roomPoint(e) { var cv = $('mapcv'), r = cv.getBoundingClientRect(), k = cv.width/r.width; return {x:((e.clientX-r.left)*k-RT.ox)/RT.s,y:((e.clientY-r.top)*k-RT.oy)/RT.s}; }
/* The thing under the pointer: a floor thing before a wall thing, the nearest floor thing first. */
function decoHit(p) {
  var best = -1, rank = -1;
  DECO.lay.items.forEach(function (it, i) { var t = THINGS[it[0]];
    if (DECO.camp && it[3]!==ST.me) return;   /* in a camp, only my own thing can be picked */
    if (p.x < it[1]-1 || p.x > it[1]+t.w+1 || p.y < it[2]-1 || p.y > it[2]+t.h+1) return;
    var d = t.z==='floor' ? 1000+it[2]+t.h : 1; if (d > rank) { rank = d; best = i; } });
  return best;
}
function decoMove(i, x, y) {
  var it = DECO.lay.items[i], t = THINGS[it[0]];
  it[1] = Math.max(12,Math.min(228-t.w,Math.round(x/2)*2));
  it[2] = t.z==='wall' ? t.y : Math.max(60-t.h,Math.min(148-t.h,Math.round(y/2)*2));
}
if ($('mapbox')) $('mapbox').addEventListener('pointerdown',function (e) {
  if (!DECO.on || !RT || (e.target.closest && e.target.closest('.rdeco,.rinside,button'))) return;
  var p = roomPoint(e), i = decoHit(p); DECO.sel = i; DECO.bad = false;
  if (i >= 0) { var it = DECO.lay.items[i]; DECO.drag = {i:i,dx:p.x-it[1],dy:p.y-it[2],from:[it[1],it[2]]}; e.preventDefault(); }
  renderDeco(); draw();
});
window.addEventListener('pointermove',function (e) {
  var d = DECO.drag; if (!DECO.on || !d) return;
  var p = roomPoint(e); decoMove(d.i,p.x-d.dx,p.y-d.dy); DECO.bad = !fits(DECO.lay.items,d.i); draw();
});
function decoDrop() {
  var d = DECO.drag; if (!d) return;
  if (DECO.bad) { var it = DECO.lay.items[d.i]; it[1] = d.from[0]; it[2] = d.from[1]; toast('Something is already there, so it went back to where it was.'); }
  DECO.drag = null; DECO.bad = false; draw();
}
window.addEventListener('pointerup',decoDrop);
window.addEventListener('pointercancel',decoDrop);
/* The keys while decorating: arrows move the picked thing two units (eight with Shift), Delete
   removes it, Escape leaves decorating without saving. A move that does not fit is not made. */
function decoKey(e) {
  if (e.key==='Escape') { e.preventDefault(); decoEnd(); toast('Nothing was changed.'); return; }
  if (DECO.camp) DECO.sel = campMine();
  var it = DECO.lay.items[DECO.sel]; if (!it) return;
  if (e.key==='Delete') { e.preventDefault(); decoRemove(); return; }
  var dx = e.key==='ArrowRight' ? 1 : e.key==='ArrowLeft' ? -1 : 0, dy = e.key==='ArrowDown' ? 1 : e.key==='ArrowUp' ? -1 : 0; if (!dx && !dy) return;
  e.preventDefault();
  var st = e.shiftKey ? 8 : 2, was = [it[1],it[2]]; decoMove(DECO.sel,it[1]+dx*st,it[2]+dy*st);
  if (!fits(DECO.lay.items,DECO.sel)) { it[1] = was[0]; it[2] = was[1]; }
  draw();
}

/* ---------- the chest and the portrait ---------- */
/* THE CHEST (David, 2026-11-12: "Treasure chest: to check their gold"). Gold is stage 4 and nothing
   is counted yet, so the chest says so and says what was agreed about gold that day, rather than
   showing a number nobody has earned. */
function openGold() {
  var d = $('goldDlg'); if (!d || d.open) return;
  /* Once assets/realm_praise.js is loaded the chest shows the real purse (migration 087). */
  if (R.openGold) { R.openGold(d,$('goldBody')); return; }
  $('goldBody').innerHTML = '<p class="rgold"><b>No gold yet</b></p><p>Gold has not arrived in the Realm. When it does, you will earn it from approved work, from praise you receive, and one coin for every full week of logged time.</p>'
    +'<p>It will buy clothes, accessories, companions and things for your office, which can be given away or swapped. Your gold is private, and it never has anything to do with pay.</p>'
    +'<div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Close</button></div>';
  d.showModal();
}
/* THE PORTRAIT (David, 2026-11-12: "so they can see theirs and others bio and profile from Anima, and
   update it"). The profile is Anima's own, the one on Home: nothing is copied. Somebody else's shows
   the name, role, bio and picture; your own also shows your email and phone, and can be changed. */
var PROF = null;
function profView() {
  var p = PROF, body = $('profBody'); if (!body || !p) return;
  $('profTitle').textContent = p.mine ? 'Your profile' : p.name;
  var h = '<div class="rprof">'+(p.photo ? '<img class="rprofpic" src="'+esc(p.photo)+'" alt="">' : '<span class="rprofpic" aria-hidden="true">'+esc(p.name.charAt(0).toUpperCase())+'</span>')
    +'<div class="rprofwho"><b>'+esc(p.name)+'</b>'+(p.role?'<small>'+esc(p.role)+'</small>':'')+(p.mine&&p.email?'<small>'+esc(p.email)+'</small>':'')+(p.mine&&p.phone?'<small>'+esc(p.phone)+'</small>':'')+'</div></div>'
    +(p.bio ? '<p class="rprofbio">'+esc(p.bio).replace(/\n/g,'<br>')+'</p>' : '<p class="rprofbio is-none">'+(p.mine?'You have not written a bio yet.':'No bio written yet.')+'</p>')
    +(p.mine ? '<small>'+(p.why ? esc(p.why) : 'Others see your name, role, bio and picture. Your email and phone are shown only to you here.')+'</small>' : '')
    +(R.profExtra ? R.profExtra(p) : '')
    +'<div class="rdacts">'+(p.can_edit?'<button type="button" class="rbtn" data-profedit="1">Edit your profile</button>':'')+'<button type="button" class="rbtn" data-dlgclose="1">Close</button></div>';
  body.innerHTML = h;
}
function profEdit() {
  var p = PROF, body = $('profBody'); if (!body || !p || !p.can_edit) return;
  body.innerHTML = '<form id="profForm" class="rprofform"><label>Phone<input name="phone" maxlength="50" value="'+esc(p.phone||'')+'" autocomplete="tel"></label>'
    +'<label>About you<textarea name="bio" rows="5" maxlength="2000">'+esc(p.bio||'')+'</textarea></label>'
    +'<label>Profile picture <small>JPG, PNG, GIF or WebP</small><input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp"></label>'
    +(p.photo?'<label class="rcheck"><input type="checkbox" name="remove_photo" value="1"> Remove the picture you have now</label>':'')
    +'<small>This is the same profile as on Home in Anima. Saving here changes it there too.</small>'
    +'<div class="rdacts"><button type="button" class="rbtn" data-profcancel="1">Cancel</button><button class="rbtn rprimary" id="profSave">Save</button></div></form>';
  $('profForm').addEventListener('submit',function (e) {
    e.preventDefault(); var fd = new FormData(this), btn = $('profSave'); fd.append('save_profile','1'); btn.disabled = true;
    fetch(R.api,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.error || !j.profile) { btn.disabled = false; toast((j && j.error) || 'Your profile could not be saved. Try again in a moment.'); return; }
        PROF = j.profile; profView(); toast(j.said || 'Profile saved.'); roomAsk(PROF.id);
      })
      .catch(function () { btn.disabled = false; toast('Your profile could not be saved. Try again in a moment.'); });
  });
}
function openProfile(id) {
  var d = $('profDlg'); if (!d || d.open || !id) return;
  PROF = null; $('profTitle').textContent = 'Profile'; $('profBody').innerHTML = '<p class="rprofbio is-none">Opening…</p><div class="rdacts"><button type="button" class="rbtn" data-dlgclose="1">Close</button></div>';
  d.showModal();
  fetch(R.api+'?profile='+encodeURIComponent(id),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (j && j.profile) { PROF = j.profile; profView(); } else { d.close(); toast((j && j.error) || 'That profile could not be opened. Try again in a moment.'); } })
    .catch(function () { d.close(); toast('That profile could not be opened. Try again in a moment.'); });
}

/* ---------- a show's camp (David, 2026-11-13, migration 091) ----------
   "For all the project camps, let's always have the following: A bar with the finished tasks and the
   progress for 'slaying' the project. A kind of papyrus on the wall so they can see live the brief of
   that project. A calendar to visualize the timeline. A checkbox list so they can see the production
   tracking, to see all the shots and their status in the checkbox. Decorate is also available but
   only one item per person."

   FOUR THINGS ARE ALWAYS THERE (CAMP_FIX, and the camp_ rows of THINGS): the scroll, the calendar and
   the board on the back wall, and the map table on the floor. They are things like any other, so the
   house's own code draws them, lays a see-through button over each (placeHot) and keeps decorations
   off them (fits). The slaying bar is painted over the back wall by slayPaint().

   EVERYTHING SHOWN IS ANIMA'S OWN AND READ ONLY. The scroll and the board ask realm_api.php when
   they open and again every minute while they stay open; the calendar is Anima's Timeline chart
   itself, in a frame, as it stands when opened; the bar and the decorations are asked
   every 45 seconds while I stand in the camp. Nothing is pushed: the hosting cannot hold a
   connection open. Nothing here can change a status, a date or the brief.

   WHO SEES WHAT is decided by the server on every request (includes/realm_camp.php): only somebody
   who may see the show; the scroll and the board only while their switch in Anima (Brief,
   Production tracking) is on for them; the calendar for everybody on the show (David's choice). This file only draws what came back.

   ⚠ CAMP_FIX IS ALSO IN includes/realm_camp.php (REALM_CAMP_FIXED). The numbers MUST match. */
var CAMP = {}, CAMP_FIX = [['camp_scroll',20,18],['camp_calendar',44,20],['camp_board',176,16],['camp_table',96,64]];
var CW = {kind:null,land:null,scope:'',data:null,timer:0,seq:0};
function campOf(id) { var m = /^land:(.+)$/.exec(id||''); return m ? m[1] : null; }
/* What the server said about the camp behind this building, or null when it is not a camp, not
   answered yet, or belongs to a show I am not on. */
function campInfo(id) { var k = CAMP[campOf(id)]; return k && !k.closed ? k : null; }
/* The camp as a list of things, the shape a house's room has: the fixtures first, then everybody's
   one decoration, each carrying whose it is. */
function campLay(id) {
  var k = campInfo(id), items = CAMP_FIX.map(function (f) { return f.slice(); });
  if (k) k.things.forEach(function (t) { items.push([t.thing,t.x,t.y,t.user]); });
  return {items:items};
}
/* Where my own thing is in the working copy while decorating, or -1. */
function campMine() { var at = -1; if (DECO.lay) DECO.lay.items.forEach(function (it, i) { if (it[3]===ST.me) at = i; }); return at; }
function campTake(k) {
  if (!k || !k.land) return;
  if (!k.closed) k.things = (k.things||[]).filter(function (t) { return THINGS[t.thing] && !THINGS[t.thing].fix && !THINGS[t.thing].req; });
  CAMP[k.land] = k;
  /* A thing somebody has just stood where I am: step clear of it, as in a house (roomSettle). */
  if (S.inside==='land:'+k.land) { if (!DECO.on) { roomSettle(); RM.near = roomNear(B[S.inside]); renderInside(); } draw(); }
  if (CW.kind==='slay' && CW.land===k.land && $('campDlg') && $('campDlg').open) campSlay();
}
function campAsk(id) {
  var land = campOf(id); if (!land) return;
  fetch(R.api+'?camp='+encodeURIComponent(land),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (j && j.camp) campTake(j.camp); })
    .catch(function () { /* The fixtures are drawn; the bar fills in when an answer comes. */ });
}
/* THE SLAYING BAR. Finishing a show is slaying it: the green is what has been finished, the red is
   what is left of the beast, whose head sits at the far end. The numbers are tasks, one shot or
   asset through one step, finished when approved, which is how Anima counts a whole show. */
var SLAY = null;
function slayPaint(c, land) {
  var k = CAMP[land]; if (!k || k.closed) return;
  if (!SLAY) SLAY = {sword:stamp(13,10,1,1,function () { parts([[0,3,2,2,PAL.glow],[2,1,2,6,PAL.glow],[4,3,7,2,PAL.marble]]); P1(4,3,7,1,PAL.hot); }),
    head:stamp(14,12,1,2,function () { parts([[3,1,8,6,TINT.ember],[0,3,4,4,TINT.ember]]); P1(8,-1,2,2,PAL.hot); P1(5,2,2,2,PAL.hot); P1(6,3,1,1,PAL.out); P1(1,4,1,1,PAL.out); P1(1,7,1,1,PAL.hot); P1(3,7,1,1,PAL.hot); P1(3,1,8,1,shade(TINT.ember,45)); })};
  var p = k.progress, x = 46, y = 1, w = 116, h = 8, cut = p ? Math.max(p.done > 0 ? 1 : 0,Math.round((w-2)*Math.min(1,p.done/p.total))) : 0, i;
  c.drawImage(SLAY.sword.cv,x-14,y-1);
  c.fillStyle = PAL.stoneD; c.fillRect(x,y,w,h);
  c.fillStyle = p ? shade(TINT.ember,-90) : PAL.winD; c.fillRect(x+1,y+1,w-2,h-2);
  if (cut > 0) { c.fillStyle = TINT.spell; c.fillRect(x+1,y+1,cut,h-2); c.fillStyle = PAL.hot; c.fillRect(x+1,y+1,cut,1); c.fillStyle = shade(TINT.spell,-80); c.fillRect(x+1,y+h-2,cut,1); }
  c.fillStyle = PAL.out; for (i = 1; i < 10; i++) c.fillRect(x+1+Math.round((w-2)*i/10),y+1,1,2);
  c.globalAlpha = p && p.done >= p.total ? .35 : 1; c.drawImage(SLAY.head.cv,x+w+1,y-2); c.globalAlpha = 1;
  c.font = 'bold 7px "Courier New", monospace'; c.textBaseline = 'top'; c.fillStyle = PAL.hot;
  c.fillText(p ? p.done+'/'+p.total : 'no tasks yet',x+w+16,y+1);
}

/* ---- one decoration each ----
   The house's decorating, pointed at a camp: the working copy is the camp as it stands, only my own
   thing can be picked, dragged or taken out, and the library offers one thing, not a roomful.
   Production and admins may also take out somebody else's (may_clear, asked again by the server). */
function renderCampDeco(bar) {
  var k = campInfo(DECO.camp)||{things:[]}, mi = campMine(), mine = mi >= 0 ? DECO.lay.items[mi] : null, keep = bar.querySelector('.rdecolib'), left = keep ? keep.scrollLeft : 0;
  var h = '<div class="rdecohead"><b>Your one thing in this camp</b><small>Everybody on the show may stand one thing here. Drag yours to move it, or use the arrow keys.</small><span class="rgrow"></span>'
    +'<button type="button" class="rbtn rsmall" data-decocancel="1">Cancel</button><button type="button" class="rbtn rprimary rsmall" data-decosave="1">Save</button></div>'
    +'<div class="rdecorow rdecosel">'+(mine ? '<span>Yours: <b>'+esc(THINGS[mine[0]].n)+'</b></span><button type="button" class="rbtn rsmall" data-decoremove="1">Take it out</button>' : '<small>You have nothing here yet. Choose one thing below.</small>');
  k.things.forEach(function (t) { if (t.mine) return;
    h += '<span class="rcampwho">'+esc(THINGS[t.thing].n)+', '+esc(firstName(t.name))+(k.may_clear ? '<button type="button" class="rbtn rsmall" data-campclear="'+(+t.user)+'" title="Take '+esc(firstName(t.name))+'&#39;s thing out of the camp">Take out</button>' : '')+'</span>'; });
  h += '</div><div class="rdecolib" role="group" aria-label="The library">';
  Object.keys(THINGS).forEach(function (id) {
    var x = THINGS[id]; if (x.fix || x.req) return;
    var has = mine && mine[0]===id, off = x.gold || (mine && !has), sc = (x.h > 22 || x.w > 32) ? 1 : 2;
    h += '<button type="button" class="rthing'+(x.gold?' is-gold':'')+'" data-decoadd="'+id+'"'+(off?' aria-disabled="true"':'')+' title="'+esc(x.gold?'What the merchant sells is for your own office':has?'This is your thing in the camp':mine?'You already have one thing here':'Stand it in the camp')+'">'
      +'<canvas data-thumb="'+id+'" data-k="'+sc+'" width="'+(x.w+2)*sc+'" height="'+(x.h+7)*sc+'" style="width:'+(x.w+2)*sc+'px;height:'+(x.h+7)*sc+'px"></canvas><span>'+esc(x.n)+'</span><small>'+(x.gold?'For an office only':has?'Yours':'Free')+'</small></button>';
  });
  bar.innerHTML = h+'</div>'; bar.hidden = false;
  decoThumbs(bar);
  var lib = bar.querySelector('.rdecolib'); if (lib) lib.scrollLeft = left;
}
function campSave() {
  var id = DECO.camp, land = campOf(id), k = campInfo(id), mi = campMine(), it = mi >= 0 ? DECO.lay.items[mi] : null;
  var old = k ? k.things.filter(function (t) { return t.mine; })[0] : null;
  if (!it && !old) { decoEnd(); return; }
  var done = function (j) {
    if (!j) { toast('The camp could not be saved. Try again in a moment.'); return; }
    if (j.camp) campTake(j.camp);
    if (j.saved) { decoEnd(); toast(it ? 'Your '+THINGS[it[0]].n.toLowerCase()+' is in the camp.' : 'Your thing is out of the camp.'); }
  };
  var place = function () { return send({camp_thing:land,thing:it[0],x:it[1],y:it[2]}).then(done); };
  /* A different thing than the one I had: the old one goes first, because the server keeps one each. */
  if (!it || (old && old.thing!==it[0])) send({camp_thing:land,remove:0}).then(function (j) { if (it && j && j.saved) place(); else done(j); });
  else place();
}
function campClear(uid) {
  var id = DECO.camp || S.inside, land = campOf(id); if (!land || !uid) return;
  send({camp_thing:land,remove:uid}).then(function (j) {
    if (!j || !j.camp) return;
    campTake(j.camp);
    if (DECO.on && DECO.camp===id) { /* Keep what I was doing with my own thing; redraw the rest from the answer. */
      var mi = campMine(), mine = mi >= 0 ? DECO.lay.items[mi] : null;
      DECO.lay = campLay(id); DECO.lay.items = DECO.lay.items.filter(function (it) { return it[3]!==ST.me; }); if (mine) DECO.lay.items.push(mine);
      DECO.sel = campMine(); renderDeco(); draw(); }
    if (j.saved) toast('Taken out of the camp.');
  });
}

/* ---- the four windows ---- */
/* Text from Anima, escaped, with its web addresses made into links that open in a new tab. */
function linked(t) {
  return esc(t).replace(/\bhttps?:\/\/(?:(?!&quot;|&#39;|&lt;|&gt;)[^\s<])+/g,function (u) {
    var tail = /[.,:!?)]+$/.exec(u), end = tail ? tail[0] : ''; if (end) u = u.slice(0,-end.length);
    return '<a href="'+u+'" target="_blank" rel="noopener noreferrer">'+u+'</a>'+end; });
}
/* The foot of a window: the way to the full screen in Anima (only an address of that screen is
   ever used), and Close. */
function campFoot(url, start, label) {
  return '<div class="rdacts">'+(url && String(url).indexOf(start)===0 ? '<a class="rbtn" href="'+esc(url)+'" target="_blank" rel="noopener">'+label+'</a>' : '')+'<button type="button" class="rbtn" data-dlgclose="1">Close</button></div>';
}
function campWin(kind) {
  var d = $('campDlg'), k = campInfo(S.inside); if (!d || d.open || !k) return;
  var sw = {brief:['brief','The Brief'],tracking:['production','Production tracking']}[kind];
  if (sw && !(k.can && k.can[sw[0]])) { toast(sw[1]+' is switched off for your account. Production can turn it on for you on the Users page.'); return; }
  CW.kind = kind; CW.land = k.land; CW.scope = ''; CW.data = null; clearInterval(CW.timer);
  d.className = 'rcamp is-'+kind;
  $('campTitle').textContent = {slay:'Slaying ',brief:'The brief of ',timeline:'The timeline of ',tracking:'Production tracking of '}[kind]+k.name;
  $('campBody').innerHTML = '<p class="rcnote">Opening…</p>'+campFoot('','','');
  d.showModal();
  if (kind==='slay') { campSlay(); return; }
  /* THE WHOLE TIMELINE (David, 2026-11-13, with a picture of the Timeline's general chart: "let's
     bring the whole timeline as the attachment, the whole widget"). The calendar is that very
     chart: Anima's Timeline page in a frame, in its embedded view (timeline.php?embed=1), which
     draws the chart and its milestones and lets nothing be moved. It is the same code as the
     Timeline, so it cannot differ from it. It is for everybody on the show, artists included
     (asked the same day: "Yes, show artists"); the page itself asks whether this person has the
     Realm and is on the show. Current when opened; not reloaded while open, because a reload
     would throw away the zoom and the place scrolled to. */
  if (kind==='timeline') {
    d.className = 'rcamp is-timeline';
    $('campBody').innerHTML = '<iframe class="rcframe" title="The timeline of '+esc(k.name)+'" src="timeline.php?project='+(+k.project_id)+'&amp;embed=1"></iframe>'
      +'<p class="rcnote">This is the show&#39;s Timeline as it stands in Anima. Nothing can be moved from here.</p>'
      +campFoot(k.timeline_page ? 'timeline.php?project='+(+k.project_id) : '','timeline.php?','Open the full Timeline in Anima');
    return;
  }
  campWinAsk();
  /* Current when opened, then asked again every minute while it stays open. Never while the tab
     is hidden. */
  CW.timer = setInterval(function () { if (d.open && !document.hidden) campWinAsk(); },60000);
}
function campWinAsk() {
  var kind = CW.kind, d = $('campDlg'), seq = ++CW.seq; if (!kind || kind==='slay' || kind==='timeline' || !d) return;
  var fail = function (why) { if (seq!==CW.seq || CW.kind!==kind || !d.open || CW.data) return; d.close(); toast(why || 'That could not be opened. Try again in a moment.'); };
  fetch(R.api+'?camp='+encodeURIComponent(CW.land)+'&'+kind+'=1'+(kind==='tracking' && CW.scope ? '&scope='+encodeURIComponent(CW.scope) : ''),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (seq!==CW.seq || CW.kind!==kind || !d.open) return;
      if (!j || !j[kind]) { fail(j && j.error); return; }
      CW.data = j[kind]; campWinDraw();
    })
    .catch(function () { fail(''); });
}
/* Draw the open window again from what came back, keeping where the reader had scrolled to. */
function campWinDraw() {
  var body = $('campBody'), sc = body.querySelector('.rcscroll'), top = sc ? sc.scrollTop : 0, left = sc ? sc.scrollLeft : 0;
  body.innerHTML = CW.kind==='brief' ? campBrief(CW.data) : campTracking(CW.data);
  sc = body.querySelector('.rcscroll'); if (sc) { sc.scrollTop = top; sc.scrollLeft = left; }
}
function campSlay() {
  var k = CAMP[CW.land], p = k && k.progress, h;
  if (!p) h = '<p class="rcnote">This show has nothing to count yet. Once it has shots or assets and a pipeline, the bar fills as their steps are approved.</p>';
  else h = '<p class="rslaypct">'+p.pct+'%<small> slain</small></p><div class="rslaybar" role="img" aria-label="'+p.pct+' percent finished"><i style="width:'+Math.max(0,Math.min(100,p.done/p.total*100)).toFixed(1)+'%"></i></div>'
    +'<p><b>'+p.done+'</b> of <b>'+p.total+'</b> tasks finished, '+(p.total-p.done > 0 ? '<b>'+(p.total-p.done)+'</b> to go.' : 'the show is slain.')+'</p>'
    +'<p class="rcnote">A task is one shot or asset through one step, and it is finished when it is approved. This is the same count as the show&#39;s progress in Anima.</p>';
  $('campBody').innerHTML = h+campFoot('','','');
}
/* THE SCROLL: the Brief as Anima holds it now. */
function campBrief(b) {
  var h = '';
  if (!b.ready) h = '<p class="rcnote">The Brief needs a database update that has not been applied yet.</p>';
  else if (b.empty) h = '<p class="rcnote">Nothing has been written in this show&#39;s Brief yet. Production fills it in on the Brief screen in Anima.</p>';
  else {
    h = '<div class="rpap rcscroll">'+(b.client ? '<p class="rpapclient">Client: <b>'+esc(b.client)+'</b></p>' : '');
    [['The ask',b.summary],['Deliverables',b.deliverables],['Reference',b.reference],['Notes',b.notes]].forEach(function (s) { if (s[1]) h += '<h3>'+s[0]+'</h3><p>'+linked(s[1])+'</p>'; });
    if (b.files && b.files.length) { h += '<h3>Files</h3><ul>';
      b.files.forEach(function (f) { if (String(f.url).indexOf('attachment.php?')===0) h += '<li><a href="'+esc(f.url)+'" target="_blank" rel="noopener">'+esc(f.name)+'</a>'+(f.size ? ' <small>'+esc(f.size)+'</small>' : '')+'</li>'; });
      h += '</ul>'; }
    h += '</div>'+(b.updated ? '<p class="rcnote">Last updated '+esc(b.updated)+'. Read only here.</p>' : '<p class="rcnote">Read only here.</p>');
  }
  return h+campFoot(b.url,'brief.php?','Open the full Brief in Anima');
}
/* THE BOARD: production tracking as Anima's own screen draws it. A row per shot (or asset), a small
   square per step in the status's colour, a dash where the step does not apply, and the DONE count.
   The legend is built from the show's own statuses, so it cannot drift from what the cells show.
   A cell arrives as a number: a status id, 0 for nothing recorded yet, -1 where the step does not
   apply. Read only: nothing here can be clicked to change a status. */
function campTracking(g) {
  var by = {}, noun = {asset:'asset',shot:'shot',project:'deliverable'}[g.scope]||'item', label = {asset:'Pre-production',shot:'Production',project:'Post-production'}, h, last = null, groups = {}, ng = 0;
  g.statuses.forEach(function (s) { by[s.id] = s; });
  g.rows.forEach(function (r) { if (!groups[r.g]) { groups[r.g] = 1; ng++; } });
  h = '<div class="rttabs" role="group" aria-label="Phase">';
  ['asset','shot','project'].forEach(function (k) { h += '<button type="button" class="rbtn rsmall" data-campscope="'+k+'" aria-pressed="'+(g.scope===k)+'">'+label[k]+' ('+(+g.counts[k]||0)+')</button>'; });
  h += '</div>';
  if (!g.rows.length || !g.steps.length) {
    h += '<p class="rcnote">'+(!g.rows.length ? 'This show has no '+noun+'s yet.' : 'No pipeline step belongs to '+label[g.scope]+' on this show.')+' Production sets that up in Anima.</p>';
    return h+campFoot(g.url,'production.php?','Open Production tracking in Anima');
  }
  h += '<p class="rcmeta"><b>'+(+g.done)+'</b> of <b>'+(+g.total)+'</b> cells approved, <b>'+(+g.ready)+'</b> of '+g.rows.length+' '+noun+(g.rows.length===1?'':'s')+' fully ready. Green means approved.</p>';
  h += '<div class="rtlegend"><b>How to read a cell</b>';
  g.statuses.forEach(function (s) { var c = hex(s.color);
    h += '<span><i class="rtchip"'+(c?' style="background:'+c+'"':'')+'></i>'+esc(s.name)+(s.ready?'<em class="is-ready">ready</em>':'')+(s.blocked?'<em class="is-blocked">blocked</em>':'')+(s.na?'<em>n/a</em>':'')+'</span>'; });
  h += '<span><i class="rtchip is-none"></i>Nothing recorded yet</span><span><i class="rtdash">&mdash;</i>Step does not apply</span></div>';
  h += '<div class="rtwrap rcscroll"><table class="rtrack"><thead><tr><th>'+noun.charAt(0).toUpperCase()+noun.slice(1)+'</th>';
  g.steps.forEach(function (s) { h += '<th class="rtstep" title="'+esc(s.of)+'">'+esc(s.name)+'</th>'; });
  h += '<th class="rtdone">Done</th></tr></thead><tbody>';
  g.rows.forEach(function (r) {
    if (ng > 1 && r.g!==last) { last = r.g; h += '<tr class="rtgroup"><td colspan="'+(g.steps.length+2)+'">'+esc(r.g)+'</td></tr>'; }
    h += '<tr'+(r.r?' class="is-ready"':'')+'><th scope="row">'+esc(r.n)+(r.r?'<em>ready</em>':'')+'</th>';
    r.c.forEach(function (v) { var s = v > 0 ? by[v] : null, c = s ? hex(s.color) : '';
      h += v < 0 ? '<td><i class="rtdash" title="Step does not apply">&mdash;</i></td>' : s ? '<td><i class="rtchip"'+(c?' style="background:'+c+'"':'')+' title="'+esc(s.name)+'"></i></td>' : '<td><i class="rtchip is-none" title="Nothing recorded yet"></i></td>'; });
    h += '<td class="rtdone">'+(+r.d)+'/'+(+r.t)+'</td></tr>';
  });
  h += '</tbody></table></div><p class="rcnote">Read only here. Statuses are set in Anima.</p>';
  return h+campFoot(g.url,'production.php?','Open Production tracking in Anima');
}

/* ---------- the frame ---------- */
/* THE NAMES ON THE MAP ARE PAINTED, NOT LAID OVER IT (David, 2026-11-13, with a picture of "Renata"
   and "The Tavern" written across the characters standing there: "these names should be behind the
   characters"). They used to be text in the SVG that sits over the canvas for clicking, and anything
   on the page is above anything painted, so a name always covered whoever stood on it. Now each
   name, its door mark and a place's plate are painted after the buildings, and draw() then paints
   again the part of any character that stands across one, so the character is in front.
   Each box returned is the patch a name covers. The colours are the roles of assets/realm.css. */
function mapLabels(c) {
  var out = [];
  c.textAlign = 'center'; c.textBaseline = 'alphabetic'; c.lineJoin = 'round';
  BLIST.forEach(function (o) {
    var hot = S.hot===o.id, tw;
    if (o.place) {
      c.font = 'bold 15px "Courier New", monospace'; tw = c.measureText(o.name).width;
      var w = Math.ceil(tw)+22, x = Math.round(o.x-w/2), y = o.y+24;
      c.fillStyle = PAL.plate; c.fillRect(x,y,w,21); c.lineWidth = 1.5; c.strokeStyle = hot ? PAL.primary : PAL.primaryBorder; c.strokeRect(x+.5,y+.5,w-1,20);
      c.fillStyle = PAL.primary; c.fillText(o.name,o.x,o.y+39);
      out.push({x:x-1,y:y-1,w:w+2,h:23}); return;
    }
    c.font = 'bold 14px "Courier New", monospace'; tw = c.measureText(o.name).width;
    var cx = o.x+4, half = tw/2+12, d = o.lit ? o.door : '', mx = cx-half, my = o.y+15;
    c.lineWidth = 3; c.strokeStyle = PAL.ground; c.strokeText(o.name,cx,o.y+20);
    c.fillStyle = hot ? PAL.primary : (o.lit ? PAL.text : PAL.textMuted); c.fillText(o.name,cx,o.y+20);
    if (d==='sealed') { c.save(); c.translate(mx,my); c.rotate(Math.PI/4); c.fillStyle = PAL.danger; c.fillRect(-3.5,-3.5,7,7); c.lineWidth = 1; c.strokeStyle = PAL.ground; c.strokeRect(-3.5,-3.5,7,7); c.restore(); }
    else if (d) { c.beginPath(); c.arc(mx,my,d==='open'?4:3.5,0,Math.PI*2); c.fillStyle = d==='open'?PAL.ok:PAL.ground; c.fill(); c.lineWidth = d==='open'?1:2; c.strokeStyle = d==='open'?PAL.ground:PAL.warn; c.stroke(); }
    out.push({x:mx-6,y:o.y+6,w:half+tw/2+9,h:20});
  });
  c.textAlign = 'start';
  return out;
}
function draw() {
  var cv = $('mapcv'); if (!cv || !GROUND) return;
  var dpr = Math.min(2,window.devicePixelRatio||1), cw = Math.round(cv.clientWidth*dpr), ch = Math.round(cv.clientHeight*dpr); if (!cw || !ch) return;
  if (cv.width!==cw || cv.height!==ch) { cv.width = cw; cv.height = ch; }
  var c = cv.getContext('2d'), v = S.view, s = Math.max(cw/v.w,ch/v.h), ox = (cw-v.w*s)/2-v.x*s, oy = (ch-v.h*s)/2-v.y*s, f = S.frame, i;
  c.setTransform(1,0,0,1,0,0); c.imageSmoothingEnabled = false; c.globalAlpha = 1; c.fillStyle = PAL.ground; c.fillRect(0,0,cw,ch);
  SP.n++;
  if (S.inside) { drawRoom(c,cw,ch); }
  else {
    placeHot(null);
    c.setTransform(s,0,0,s,ox,oy); c.drawImage(GROUND,0,0);
    var x0 = -ox/s-130, x1 = (cw-ox)/s+130, y0 = -oy/s-20, y1 = (ch-oy)/s+170, list = [], w = whereabouts(), my = me()||{cloak:'mana',hat:'none',pet:'none'};
    [OBJ,HOUSES].forEach(function (a) { for (var k = 0; k < a.length; k++) if (a[k].x>x0&&a[k].x<x1&&a[k].y>y0&&a[k].y<y1) list.push(a[k]); });
    /* Each of the others is drawn on the way to their spot (glide), or to where the map stands
       somebody who has not walked since arriving. */
    w.out.forEach(function (q, n) { var sp = SP.ready ? SP.at[q.p.id] : null, gl = glide(q.p.id,sp?sp[0]:q.x,sp?sp[1]:q.y,'map');
      list.push({x:Math.round(gl.x),y:Math.round(gl.y),who:q.p,bob:gl.path?(Math.floor(Date.now()/130)%2)*2:(f+n)%4===0?2:0,idle:q.p.state==='idle'}); });
    var mx = Math.round(S.pos.x), myy = Math.round(S.pos.y), moving = S.walk||held.size, en = S.enter;
    list.push({x:mx,y:myy,who:my,bob:moving?(Math.floor(Date.now()/130)%2)*2:0,mine:true});
    list.sort(function (a, b) { return a.y-b.y; });
    for (i = 0; i < list.length; i++) { var it = list[i];
      if (it.st) { c.drawImage(it.st.cv,it.x-it.st.ox,it.y-(it.lift||0)-it.st.oy); continue; }
      if (it.mine) { if (en) c.globalAlpha = Math.max(0,1-en); c.fillStyle = PAL.win; c.fillRect(mx-13,myy+2,26,2); c.fillRect(mx-13,myy,2,2); c.fillRect(mx+11,myy,2,2); }
      if (it.idle) c.globalAlpha = .6;
      sprite(c,it.x,it.y,it.who,it.bob); c.globalAlpha = 1;
      if (it.idle) zed(c,it.x,it.y);
      LASTAT[it.who.id] = {x:it.x,y:it.y,room:false};
    }
    /* Names go on after the buildings, then every character standing across one is painted again
       inside that name's patch only, so they are in front of the name and nothing else changes. */
    var labs = mapLabels(c);
    list.forEach(function (it) {
      if (it.st) return;
      labs.forEach(function (lb) {
        if (it.x+30 < lb.x || it.x-16 > lb.x+lb.w || it.y+4 < lb.y || it.y-60 > lb.y+lb.h) return;
        c.save(); c.beginPath(); c.rect(lb.x,lb.y,lb.w,lb.h); c.clip();
        if (it.idle) c.globalAlpha = .6; if (it.mine && en) c.globalAlpha = Math.max(0,1-en);
        sprite(c,it.x,it.y,it.who,it.bob); c.restore(); c.globalAlpha = 1;
      });
    });
    BLIST.forEach(function (o) {
      if (o.owner && o.owner.focus) puff(c,o.x+(o.kind==='forge'?16:0),o.y-(o.kind==='forge'?82:o.kind==='tower'?128:66),f,false);
      heads(c,o,w.inside[o.id]||[]);
    });
    puff(c,1380,636,f,true);
    fxPaint(c,false);
    /* The arrow is always on top, so you can find yourself when a house covers you. */
    if (!en) { var ay = myy-70-(f%2)*2; c.fillStyle = PAL.out; c.fillRect(mx-6,ay-1,12,5); c.fillRect(mx-4,ay+4,8,3); c.fillRect(mx-2,ay+7,4,2);
      c.fillStyle = PAL.win; c.fillRect(mx-5,ay,10,3); c.fillRect(mx-3,ay+3,6,2); c.fillRect(mx-1,ay+5,2,2); }
  }
  /* Whoever was not drawn this time has left what I can see; if they come back they are simply there. */
  Object.keys(OTH).forEach(function (id) { if (OTH[id].n!==SP.n) delete OTH[id]; });
  if (S.fade > 0) { c.setTransform(1,0,0,1,0,0); c.globalAlpha = Math.min(1,S.fade); c.fillStyle = PAL.ground; c.fillRect(0,0,cw,ch); c.globalAlpha = 1; }
}

/* ---------- labels and click areas ---------- */
function vb() { return [S.view.x,S.view.y,S.view.w,S.view.h].map(function (n) { return n.toFixed(1); }).join(' '); }
function overlay() {
  var h = '<svg id="mapsvg" viewBox="'+vb()+'" preserveAspectRatio="xMidYMid slice" role="group" aria-label="Map of the Realm">';
  ST.lands.forEach(function (l) {
    var L = LANDS[l.land]; if (!L) return;
    if (l.claimed) h += '<text class="region" x="'+L.label[0]+'" y="'+L.label[1]+'">'+esc(l.name)+'</text>';
    else h += '<ellipse class="freeland" cx="'+L.mist[0]+'" cy="'+L.mist[1]+'" rx="'+(L.mist[2]+2)+'" ry="'+(L.mist[3]+2)+'"/><text class="region free" x="'+L.label[0]+'" y="'+L.label[1]+'">The '+L.title+'</text><text class="sub" x="'+L.label[0]+'" y="'+(L.label[1]+20)+'">Unclaimed, waiting for a show</text>';
  });
  h += '<text class="region" x="800" y="952">Anima Village</text>';
  BLIST.forEach(function (o) {
    var hw = HIT[o.kind][0], ht = HIT[o.kind][1];
    if (o.place) {
      h += '<g class="bld" tabindex="0" role="button" data-sel="'+esc(o.id)+'" aria-label="'+esc(o.name)+', a place to meet"><rect class="hit" x="'+(o.x-hw-10)+'" y="'+(o.y-ht)+'" width="'+(hw*2+20)+'" height="'+(ht+50)+'" rx="4"/></g>';
      return; }
    var d = o.lit ? o.door : '';
    h += '<g class="bld" tabindex="0" role="button" data-sel="'+esc(o.id)+'" aria-label="'+esc(title(o.id))+(d?', '+DOORS[d]:', away')+'"><rect class="hit" x="'+(o.x-hw)+'" y="'+(o.y-ht)+'" width="'+(hw*2)+'" height="'+(ht+24)+'" rx="4"/></g>';
  });
  return h+'</svg>';
}
function whereWords(p) {
  if (p.state==='away') return 'Away';
  var own = p.place==='user:'+p.id, o = B[p.place], at = own ? '' : (o ? (p.inside?'In ':'At ')+(o.owner?firstName(o.owner.name)+"'s":o.name) : '');
  return (p.state==='idle'?'Resting':'') + (at ? (p.state==='idle'?', ':'')+(p.state==='idle'?at.charAt(0).toLowerCase()+at.slice(1):at) : (p.state==='idle'?'':DOORS[p.door]));
}
function renderSide() {
  var rank = {online:0,idle:1,away:2}, ppl = ST.people.slice().sort(function (a, b) { return (b.me-a.me) || (rank[a.state]-rank[b.state]) || a.name.localeCompare(b.name); });
  var h = '', un = unread();
  if (ST.chat) { h += '<div><h2>Channels</h2><div class="rrows">';
    ST.chat.channels.forEach(function (c) { var n = c.id===CH.open ? 0 : (un[c.id]||0);
      h += '<button type="button" class="rrow'+(CH.open===c.id?' is-on':'')+'" data-chat="'+esc(c.id)+'"><span class="rhash">#</span><span>'+esc(c.name)+'</span>'+(n?'<b class="rbadge">'+n+'</b>':'')+'</button>'; });
    h += '</div></div>'; }
  var ravens = function (p) { var id = dmId(ST.me,p.id); return (p.me || id===CH.open) ? 0 : (un[id]||0); };
  h += '<div><h2>People</h2><div class="rrows">';
  ppl.forEach(function (p) { h += '<button type="button" class="rrow'+(p.state==='away'?' is-away':'')+(S.sel==='user:'+p.id?' is-on':'')+'" data-sel="user:'+p.id+'" data-center="1"'+((ST.chat && !p.me)?' data-raven="'+dmId(ST.me,p.id)+'" title="Open your ravens with '+esc(firstName(p.name))+'"':'')+'><i class="rdot '+(p.state==='away'?'':p.door)+'"></i><span>'+esc(p.me?'You':p.name)+'</span>'+(ravens(p)?'<b class="rbadge" title="Unread ravens">'+ravens(p)+'</b>':'<small>'+esc(whereWords(p))+'</small>')+'</button>'; });
  if (!ppl.length) h += '<small style="padding:0 8px">Nobody has a house yet.</small>';
  h += '</div></div><div><h2>Places to meet</h2><div class="rrows">';
  var w = whereabouts();
  BLIST.forEach(function (o) { if (!o.place) return; var n = (w.inside[o.id]||[]).length + (S.inside===o.id?1:0);
    var nv = voiceOthers(o.id).length + (VO.on && S.inside===o.id?1:0);
    h += '<button type="button" class="rrow'+(S.sel===o.id?' is-on':'')+'" data-sel="'+esc(o.id)+'" data-center="1"><span>'+esc(o.name)+'</span>'+(n?'<small>'+n+' inside'+(nv?', '+nv+' in a call':'')+'</small>':'')+'</button>'; });
  $('side').innerHTML = h+'</div></div>';
}
function renderCard() {
  var slot = $('cardslot'), id = S.sel, o = B[id], h = '';
  if (S.walk) { h = '<div class="rcard"><b>Walking to '+esc(title(S.walk.id))+'…</b><div class="racts"><button type="button" class="rbtn rsmall" data-tele="'+esc(S.walk.id)+'">Teleport instead</button></div></div>'; }
  else if (o) {
    var why = mayEnter(id), sub = o.owner ? (o.owner.state==='away'?'Away':(o.owner.state==='idle'?'Resting. ':'')+DOORS[o.owner.door]+(o.owner.focus?', in a focus session':'')+(o.owner.me?'. Your tasks are on the bookshelf inside':'')) : 'A place to meet. Go inside to talk, in writing or in a call.';
    /* At an ajar door, with nobody having let me in yet, the button is Knock. */
    var ajar = o.owner && !o.owner.me && o.owner.state!=='away' && o.owner.door==='ajar' && !PASS[o.owner.id];
    var waiting = ajar && KN.mine && KN.mine.to===o.owner.id && KN.mine.status==='waiting';
    if (ajar) why = waiting ? 'Knocking. Waiting for '+firstName(o.owner.name)+' to answer.' : (KN.ready ? 'The door is ajar. Knock, and wait to be let in.' : 'The door is ajar. Knocking needs a database update first.');
    var btn = S.here===id && ajar ? '<button type="button" class="rbtn rprimary rsmall" data-knock="'+o.owner.id+'"'+((waiting||!KN.ready)?' disabled':'')+'>'+(waiting?'Knocking…':'Knock')+'</button>' :
      S.here===id ? '<button type="button" class="rbtn rprimary rsmall" data-enter="'+esc(id)+'"'+(why?' disabled':'')+'>Go inside</button>' : '<button type="button" class="rbtn rprimary rsmall" data-walk="'+esc(id)+'">Walk there</button><button type="button" class="rbtn rsmall" data-tele="'+esc(id)+'">Teleport</button>';
    h = '<div class="rcard"><b>'+esc(title(id))+'</b><small>'+esc(sub)+'</small>'+(S.here===id&&why?'<small>'+esc(why)+'</small>':'')+'<div class="racts">'+btn+(chanFor(id)?'<button type="button" class="rbtn rsmall" data-chat="'+esc(chanFor(id))+'">'+(o.owner?'Send a raven':'Open chat')+'</button>':'')+'<button type="button" class="rbtn rsmall" data-close="1">Close</button></div></div>';
  }
  slot.innerHTML = h;
}
function renderInside() {
  var bar = $('insideBar'), box = $('mapbox'), o = B[S.inside];
  box.classList.toggle('is-inside',!!o); bar.hidden = !o; if (!o) { shareShow(); return; }
  var names = [];
  if (o.owner && !o.owner.me && o.owner.state!=='away' && o.owner.place===o.id) names.push(o.owner.name);
  ST.people.forEach(function (p) { if (!p.me && p.state!=='away' && p.inside && p.place===o.id && names.indexOf(p.name) < 0) names.push(p.name); });
  /* A show's camp: its four fixtures for somebody on the show, one sentence for anybody else. */
  var land = campOf(o.id), k = land ? CAMP[land] : null, mayDeco = (o.owner && o.owner.me) || (k && !k.closed);
  var hint = (o.owner && o.owner.me) ? ' Walk up to the bookshelf, the chest or the portrait and press Enter, or click them.' : !k ? '' : k.closed ? ' This camp belongs to a show you are not on, so its boards are covered.' : ' Click the bar, the scroll, the calendar or the board, or walk up to one and press Enter.';
  if (stallOf(o)) hint = ' The merchant sells things for gold: walk up and press Enter, or click.';
  bar.innerHTML = '<h2>'+esc(title(o.id))+'</h2><span>'+(names.length?'Here with you: '+esc(names.join(', '))+(hint?'.':''):'Nobody else is here right now.')+hint+'</span><span class="rgrow"></span>'+voiceBar(o)+((mayDeco && !DECO.on)?'<button type="button" class="rbtn" data-deco="1">Decorate</button>':'')+'<button type="button" class="rbtn rprimary" data-leave="1">Leave room</button>';
  /* The shared screen sits under this bar, however many lines the bar takes. */
  shareShow();
}
function renderDoor() {
  var m = me(), seg = $('doorSeg'); if (!seg) return;
  Array.prototype.forEach.call(seg.querySelectorAll('button'),function (b) { b.setAttribute('aria-pressed',String(!!m && m.door===b.dataset.door)); b.disabled = !m; });
  var lb = $('lookBtn'); if (lb) lb.disabled = !m;
}
function renderAll() { $('overlay').innerHTML = overlay(); bindMap(); renderSide(); renderCard(); renderInside(); renderDoor(); badge(); draw(); }

/* NOTICES MUST STAY ON TOP OF AN OPEN WINDOW. A window opened with showModal() (the Focus desk, Your
   character, the picture viewer) is drawn above everything else on the page, so a notice left in
   the page sat behind it, dimmed and unclickable: found with the Focus desk open and somebody
   knocking (2026-11-12). The notices' box therefore lives inside whichever window is open, and
   goes back to the page when it shuts. Checked twice a second, because a window opening or
   shutting sends no event that reaches here, and a notice may already be showing when it does. */
function toastHome() {
  var box = $('toasts'), open = document.querySelector('dialog[open]'), want = open || document.body;
  if (box && box.parentNode !== want) want.appendChild(box);
  return box;
}
setInterval(toastHome,500);
/* With `chat`, the message is itself a button that opens that conversation. */
function toast(msg, chat) { var t = document.createElement('div'); t.className = 'rtoast'; t.textContent = msg;
  if (chat) { t.className = 'rtoast is-link'; t.setAttribute('data-chat',chat); t.setAttribute('role','button'); t.tabIndex = 0; } toastHome().appendChild(t); setTimeout(function () { t.remove(); },chat?15000:6000); }

/* ---------- taking in what the server says ---------- */
var SIG = {land:'',house:''};
function applyState(st) {
  if (!st || !st.people) return;
  var fresh = st.chat && st.chat.unread, was = ST && ST.chat && ST.chat.unread;
  if (st.chat && was) st.chat.unread = was;
  ST = st; rebuildBuildings();
  var land = ST.people.map(function (p) { return p.slot+p.kind; }).join(',')+'|'+ST.lands.map(function (l) { return l.claimed?1:0; }).join('');
  var house = BLIST.map(function (o) { return o.id+o.kind+o.tint+(o.lit?1:0)+(o.door||''); }).join(',');
  if (land!==SIG.land) { SIG.land = land; buildWorld(); }
  if (house!==SIG.house) { SIG.house = house; buildHouses(); }
  if (S.sel && !B[S.sel]) S.sel = null;
  if (S.inside && (!B[S.inside] || mayEnter(S.inside))) { voiceStop(true); S.inside = null; RM.walk = null; spotSpace(); var s = spot(S.here)||S.pos; S.pos = {x:s.x,y:s.y}; toast('The door closed, so you are back outside.'); send({arrive:S.here||('user:'+ST.me),inside:0}); }
  renderAll();
  /* Standing in somebody else's house: ask for their room again, so a change of theirs shows. */
  if (S.inside && B[S.inside] && B[S.inside].owner && !B[S.inside].owner.me) roomAsk(B[S.inside].owner.id);
  if (fresh) takeUnread(fresh);
  takeKnocks(st.knocks);
  /* Praise, mana and gold live in assets/realm_praise.js; it is told whenever the map changes. */
  if (R.onState) R.onState(ST);
  takeSpots(st.spots);
}
function send(fields) {
  var body = new URLSearchParams(); Object.keys(fields).forEach(function (k) { body.append(k,fields[k]); });
  return fetch(R.api,{method:'POST',body:body,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) { if (j && j.error) toast(j.error); if (j && j.people) applyState(j); return j; })
    .catch(function () { /* A missed beat is corrected by the next one. */ return null; });
}

/* ---------- moving ---------- */
function setView(v) { var w = Math.max(420,Math.min(1600,v.w)), h = w*.625; S.view = {x:Math.max(0,Math.min(1600-w,v.x)),y:Math.max(0,Math.min(1000-h,v.y)),w:w,h:h}; var s = $('mapsvg'); if (s) s.setAttribute('viewBox',vb()); draw(); }
function zoomAt(f, cx, cy) { var v = S.view, w = Math.max(420,Math.min(1600,v.w*f)), k = w/v.w; setView({x:cx-(cx-v.x)*k,y:cy-(cy-v.y)*k,w:w}); }
function follow() { var v = S.view, m = 60, x = v.x, y = v.y;
  if (S.pos.x<v.x+m) x = S.pos.x-m; else if (S.pos.x>v.x+v.w-m) x = S.pos.x-v.w+m;
  if (S.pos.y<v.y+m+30) y = S.pos.y-m-30; else if (S.pos.y>v.y+v.h-m) y = S.pos.y-v.h+m;
  if (x!==v.x || y!==v.y) setView({x:x,y:y,w:v.w}); }
/* THE CHARACTER STAYS ON THE MAP (David, 2026-11-13, with a picture of his character half off the top
   edge above The Moor: "right now the characters can go off the map, let's restrict that"). The
   feet used to be held only 40 units from the top, and a character is about 60 tall with the arrow
   above it, so the head and the arrow left the picture; on the right, a companion beside the
   character did the same. These are the limits for the FEET, so the whole figure stays in view. */
var EDGE = {left:16,right:1572,top:78,bottom:990};
function inEdge(x, y) { return x >= EDGE.left && x <= EDGE.right && y >= EDGE.top && y <= EDGE.bottom; }
function freeAt(x, y) { var tx = Math.floor(x/16), ty = Math.floor(y/16); return tx>=0&&ty>=0&&tx<GRID.W&&ty<GRID.H&&!GRID.b[ty*GRID.W+tx]; }
function canStand(x, y) { return freeAt(x-6,y)&&freeAt(x+6,y); }
/* The way on foot from one point to another, round whatever is solid. */
function route(from, to) {
  var W = GRID.W, H = GRID.H, ti = function (p) { return Math.max(0,Math.min(H-1,Math.floor(p.y/16)))*W+Math.max(0,Math.min(W-1,Math.floor(p.x/16))); };
  var s = ti(from), t = ti(to), prev = new Int32Array(W*H).fill(-1), q = [s], D = [[1,0],[-1,0],[0,1],[0,-1]], h, d; prev[s] = s;
  for (h = 0; h < q.length && prev[t] < 0; h++) { var i = q[h], x = i%W, y = (i-x)/W;
    for (d = 0; d < 4; d++) { var nx = x+D[d][0], ny = y+D[d][1], j = ny*W+nx; if (nx<0||ny<0||nx>=W||ny>=H||prev[j]>=0||((GRID.b[j]||!inEdge(nx*16+8,ny*16+8))&&j!==t)) continue; prev[j] = i; q.push(j); } }
  if (prev[t] < 0) return null;
  var path = [{x:to.x,y:to.y}]; for (var k = t; k!==s; k = prev[k]) path.push({x:(k%W)*16+8,y:Math.floor(k/W)*16+8});
  return path.reverse();
}
var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
/* I stopped at a building: now, and only now, the others are told where I am. */
function arrived(id) { S.walk = null; SP.dirty = false; var s = spot(id); S.pos = {x:s.x,y:s.y}; S.here = id; S.sel = id; renderCard(); renderSide(); draw(); send({arrive:id,inside:0}); }
function walkTo(id) {
  var t = spot(id); if (!t) return;
  var path = calm ? null : route(S.pos,t);
  if (!path) { teleport(id); return; }
  S.walk = {id:id,path:path,i:0}; S.sel = null; S.here = null; S.lt = 0; renderCard();
  if (!S.raf) S.raf = requestAnimationFrame(tick);
}
function tick(ts) {
  var w = S.walk; if (!w) { S.raf = 0; return; }
  var st = S.lt ? 260*Math.min(.05,(ts-S.lt)/1000) : 0; S.lt = ts;
  while (st > 0 && w.i < w.path.length) { var p = w.path[w.i], dx = p.x-S.pos.x, dy = p.y-S.pos.y, d = Math.hypot(dx,dy);
    if (d<=st) { S.pos = {x:p.x,y:p.y}; st -= d; w.i++; } else { S.pos = {x:S.pos.x+dx/d*st,y:S.pos.y+dy/d*st}; st = 0; } }
  if (w.i >= w.path.length) { S.raf = 0; arrived(w.id); return; }
  spotDirty(); follow(); draw(); S.raf = requestAnimationFrame(tick);
}
/* TELEPORTING (David, 2026-11-13): a ring of sparks and a beam where I was, the same closing in
   where I arrive, so the jump is seen and not just a cut. */
function teleport(id) { if (!spot(id)) return; var fx0 = {x:S.pos.x,y:S.pos.y}; fx('poof',fx0.x,fx0.y); arrived(id); fx('land',S.pos.x,S.pos.y); }
/* A short animation driven by the clock: fn gets 0..1, done runs at the end. */
function play(ms, fn, done) { if (calm) { fn(1); done(); return; } var t0 = 0; (function step(ts) { if (!t0) t0 = ts; var k = Math.min(1,(ts-t0)/ms); fn(k); draw(); if (k < 1) requestAnimationFrame(step); else done(); })(performance.now()); }
/* Walk in through the door, fade, and you are in the room. */
function goInside(id) {
  var o = B[id], why = mayEnter(id); if (!o) return; if (why) { toast(why); return; }
  var from = {x:S.pos.x,y:S.pos.y}, to = {x:o.x+DOORX[o.kind],y:o.y-2};
  S.sel = null; renderCard();
  play(420,function (k) { S.pos = {x:from.x+(to.x-from.x)*k,y:from.y+(to.y-from.y)*k}; S.enter = k*.9; },function () {
    play(220,function (k) { S.fade = k; },function () {
      S.enter = null; S.inside = id; S.pos = from; roomCome(); if (o.owner) roomAsk(o.owner.id); else if (campOf(id)) campAsk(id); renderInside(); renderSide(); if (chanFor(id)) openChat(chanFor(id));
      play(260,function (k) { S.fade = 1-k; },function () { S.fade = 0; draw(); });
      send({arrive:id,inside:1});
    });
  });
}
function leave() {
  if (!S.inside || RM.leaving) return; var id = S.inside; BUB = {}; decoEnd(); RM.leaving = true; RM.walk = null; held.clear();
  /* Voice is face to face: walking out of the room hangs up. */
  voiceStop(true);
  play(220,function (k) { S.fade = k; },function () {
    S.inside = null; RM.leaving = false; spotSpace(); var s = spot(id)||S.pos; S.pos = {x:s.x,y:s.y}; S.here = id; S.sel = id; renderInside(); renderSide(); renderCard();
    play(260,function (k) { S.fade = 1-k; },function () { S.fade = 0; draw(); });
    send({arrive:id,inside:0});
  });
}

/* ---------- input ---------- */
var drag = null, justDragged = false, held = new Set(), kraf = 0, klt = 0;
function bindMap() {
  var s = $('mapsvg'); if (!s) return;
  s.addEventListener('wheel',function (e) { e.preventDefault(); var p = s.createSVGPoint(); p.x = e.clientX; p.y = e.clientY; var q = p.matrixTransform(s.getScreenCTM().inverse()); zoomAt(e.deltaY>0?1.15:.87,q.x,q.y); },{passive:false});
  s.addEventListener('mouseover',function (e) { var b = e.target.closest && e.target.closest('.bld'), id = b ? b.getAttribute('data-sel') : null; if (id!==S.hot) { S.hot = id; draw(); } });
  s.addEventListener('mouseleave',function () { if (S.hot) { S.hot = null; draw(); } });
  s.addEventListener('pointerdown',function (e) { drag = {x:e.clientX,y:e.clientY,vx:S.view.x,vy:S.view.y,k:1/s.getScreenCTM().a,moved:false}; });
}
window.addEventListener('pointermove',function (e) { S.touched = true; if (!drag) return; var dx = e.clientX-drag.x, dy = e.clientY-drag.y; if (!drag.moved && Math.hypot(dx,dy) < 5) return; drag.moved = true; setView({x:drag.vx-dx*drag.k,y:drag.vy-dy*drag.k,w:S.view.w}); });
window.addEventListener('pointerup',function () { if (drag && drag.moved) { justDragged = true; setTimeout(function () { justDragged = false; },60); } drag = null; });
function select(id, center) { if (!B[id]) return; S.sel = id; if (center && !S.inside) { var o = B[id]; setView({x:o.x-S.view.w/2,y:o.y-40-S.view.h/2,w:S.view.w}); } renderCard(); renderSide(); }
/* AT A BUILDING IS ANYWHERE AROUND IT (David, 2026-11-13, standing just right of his own house:
   "right now only appears the buttons when you are on the left side of the buildings, is it possible
   to expand the trigger area?"). It used to be a circle 36 units round the one spot beside the door,
   on the building's left. Now it is a box round the whole building: its width plus NEAR.side each
   side, from a little above the roof to NEAR.below under the door. Where two boxes overlap (houses
   stand close), the building whose door is nearer wins. */
var NEAR = {side:34,above:14,below:44};
function nearest() {
  var best = null, bd = Infinity, x = S.pos.x, y = S.pos.y;
  BLIST.forEach(function (o) {
    var h = HIT[o.kind]; if (!h) return;
    if (x < o.x-h[0]-NEAR.side || x > o.x+h[0]+NEAR.side || y < o.y-h[1]-NEAR.above || y > o.y+NEAR.below) return;
    var d = Math.hypot(o.x-x,o.y-y); if (d < bd) { bd = d; best = o.id; }
  });
  return best;
}
function keyTick(ts) {
  if (!held.size) { kraf = 0; klt = 0; if (S.inside) draw(); return; }
  var dt = klt ? Math.min(.05,(ts-klt)/1000) : 0; klt = ts;
  var dx = (held.has('ArrowRight')?1:0)-(held.has('ArrowLeft')?1:0), dy = (held.has('ArrowDown')?1:0)-(held.has('ArrowUp')?1:0);
  /* Inside a room the same keys walk the room (roomStep), and nothing is said to the map. */
  if (S.inside) { if (dx || dy) roomStep(dx,dy,dt); kraf = requestAnimationFrame(keyTick); return; }
  if (dx || dy) { var n = Math.hypot(dx,dy), st = 230*dt, nx = Math.max(EDGE.left,Math.min(EDGE.right,S.pos.x+dx/n*st)), ny = Math.max(EDGE.top,Math.min(EDGE.bottom,S.pos.y+dy/n*st)), stuck = !canStand(S.pos.x,S.pos.y);
    S.pos = (stuck||canStand(nx,ny))?{x:nx,y:ny}:canStand(nx,S.pos.y)?{x:nx,y:S.pos.y}:canStand(S.pos.x,ny)?{x:S.pos.x,y:ny}:S.pos;
    spotDirty(); follow(); draw();
    var at = nearest(); if (at!==S.here) { S.here = at; S.sel = at; renderCard(); renderSide(); if (at) send({arrive:at,inside:0}); } }
  kraf = requestAnimationFrame(keyTick);
}
document.addEventListener('keydown',function (e) {
  S.touched = true;
  var t = e.target, is = function (q) { return t && t.matches && t.matches(q); };
  /* With the conversation laid over the map (the handle on its edge) the map is not on screen, so
     no key may walk the character, enter a house or leave a room where nobody can see it happen. */
  if (mapAway()) { held.clear(); return; }
  /* Going into a room puts the cursor in the message box, ready to type. While that box is empty
     the arrow keys have nothing to do in it, so they walk the character instead of doing nothing. */
  var arrow = e.key.indexOf('Arrow')===0, walkFromBox = arrow && S.inside && !DECO.on && is('#chatInput') && !t.value && !SUG.list.length;
  if ((is('input,select,textarea') && !walkFromBox) || document.querySelector('dialog[open]')) return;
  if (S.inside && DECO.on) { decoKey(e); return; }
  if (S.inside) {
    if (e.key==='Escape') leave();
    else if (arrow) { e.preventDefault(); if (!RM.leaving) { held.add(e.key); if (!kraf) kraf = requestAnimationFrame(keyTick); } }
    /* Enter beside the bookshelf, the chest or the portrait opens it, as a click on it does. */
    else if (e.key==='Enter' && RM.near && $(RM.near) && !is('button,a')) { e.preventDefault(); $(RM.near).click(); }
    return;
  }
  if (e.key.indexOf('Arrow')===0) { e.preventDefault(); if (S.walk) { S.walk = null; renderCard(); } held.add(e.key); if (!kraf) kraf = requestAnimationFrame(keyTick); }
  else if (e.key==='Enter' && is('.bld')) { e.preventDefault(); select(t.getAttribute('data-sel'),false); }
  else if (e.key==='Enter' && S.here && !is('button,a')) goInside(S.here);
});
document.addEventListener('keyup',function (e) { held.delete(e.key); });
window.addEventListener('blur',function () { held.clear(); });
document.addEventListener('click',function (e) {
  S.touched = true;
  var t = e.target.closest ? e.target : e.target.parentNode, b;
  musicWake(t); voiceWake();
  /* A name in the People list opens your ravens with that person (David, 2026-11-12: "clicking
     here should automatically open the ravens with Renata"), whether or not anything is unread and
     whether or not they are here, and still finds their house on the map. */
  if ((b = t.closest('[data-raven]'))) { openChat(b.getAttribute('data-raven')); if (!S.inside) select(b.getAttribute('data-sel'),true); }
  else if ((b = t.closest('.rimglink'))) { e.preventDefault(); showPicture(b.getAttribute('href'),(b.querySelector('img')||{}).alt); }
  else if (t.closest('[data-picclose]')) { if ($('picDlg')) $('picDlg').close(); }
  else if ((b = t.closest('[data-knock]'))) send({knock:b.getAttribute('data-knock')});
  else if ((b = t.closest('[data-knockanswer]'))) { send({answer_knock:b.getAttribute('data-knockanswer'),let:b.getAttribute('data-let')}); var box = b.closest('[data-knockbox]'); if (box) box.remove(); }
  else if (t.closest('#chatAttach')) { if ($('chatFile')) $('chatFile').click(); }
  else if (t.closest('[data-unpick]')) setPick(null);
  else if ((b = t.closest('[data-tag]'))) sugPick(+b.getAttribute('data-tag'));
  else if (t.closest('#chatWide')) chatWide(!CH.wide);
  else if ((b = t.closest('[data-chat]'))) { openChat(b.getAttribute('data-chat')); if (b.classList.contains('rtoast')) b.remove(); }
  else if (t.closest('[data-chatclose]')) closeChat();
  else if ((b = t.closest('[data-sel]'))) { if (justDragged) return; if (mapAway()) chatWide(false); select(b.getAttribute('data-sel'),b.hasAttribute('data-center')); }
  else if ((b = t.closest('[data-walk]'))) walkTo(b.getAttribute('data-walk'));
  else if ((b = t.closest('[data-tele]'))) teleport(b.getAttribute('data-tele'));
  else if ((b = t.closest('[data-enter]'))) goInside(b.getAttribute('data-enter'));
  else if (t.closest('[data-close]')) { S.sel = null; renderCard(); renderSide(); }
  else if ((b = t.closest('[data-voice]'))) { var vw = b.getAttribute('data-voice'); if (vw==='join') voiceJoin(); else if (vw==='mute') voiceMute(); else voiceStop(true); }
  else if ((b = t.closest('[data-share]'))) { var sa = b.getAttribute('data-share');
    if (sa==='start') shareStart(); else if (sa==='stop') shareStop(); else if (sa==='full') shareFull();
    else { SH.hide = sa==='hide'; renderInside(); } }
  else if (t.closest('[data-leave]')) leave();
  else if (t.closest('#chestBtn')) fxThing('chest',openGold);
  /* The merchant's counter is assets/realm_shop.js. */
  else if (t.closest('#shopBtn')) { if (R.openShop) R.openShop(); }
  else if (t.closest('#portraitBtn')) { var ho = B[S.inside]; if (ho && ho.owner) fxThing('portrait',function () { openProfile(ho.owner.id); }); }
  else if (t.closest('#slayBtn')) campWin('slay');
  else if (t.closest('#scrollBtn')) campWin('brief');
  else if (t.closest('#calBtn')) campWin('timeline');
  else if (t.closest('#boardBtn')) campWin('tracking');
  else if ((b = t.closest('[data-campscope]'))) { CW.scope = b.getAttribute('data-campscope'); campWinAsk(); }
  else if ((b = t.closest('[data-campclear]'))) campClear(+b.getAttribute('data-campclear'));
  else if ((b = t.closest('[data-dlgclose]'))) { var dl = b.closest('dialog'); if (dl) dl.close(); }
  else if (t.closest('[data-profedit]')) profEdit();
  else if (t.closest('[data-profcancel]')) profView();
  else if (t.closest('[data-deco]')) decoStart();
  else if (t.closest('[data-decosave]')) decoSave();
  else if (t.closest('[data-decocancel]')) decoEnd();
  else if (t.closest('[data-decoreset]')) { DECO.lay = copyLay(ROOM_START); DECO.sel = -1; renderDeco(); draw(); }
  else if (t.closest('[data-decoremove]')) decoRemove();
  else if (t.closest('[data-deconext]')) { DECO.sel = (DECO.sel+1)%DECO.lay.items.length; renderDeco(); draw(); }
  else if ((b = t.closest('[data-decostyle]'))) { DECO.lay[b.getAttribute('data-decostyle')] = b.getAttribute('data-val'); renderDeco(); draw(); }
  else if ((b = t.closest('[data-decoadd]'))) decoAdd(b.getAttribute('data-decoadd'));
  else if ((b = t.closest('[data-zoom]'))) { var z = b.getAttribute('data-zoom'), v = S.view, cx = v.x+v.w/2, cy = v.y+v.h/2; if (z==='in') zoomAt(.72,cx,cy); else if (z==='out') zoomAt(1.39,cx,cy); else setView(z==='village'?VILLAGE:FULL); }
  else if ((b = t.closest('[data-door]'))) send({set_door:b.getAttribute('data-door')});
  else if (t.closest('#lookBtn')) openLook();
  else if (t.closest('#shelfBtn')) fxThing('shelf',openFocus);
  else if (t.closest('#focusBtn')) openFocus();
  else if ((b = t.closest('[data-focusstart]'))) focusDo({focus:'start',task:b.getAttribute('data-focusstart')});
  else if (t.closest('[data-focusstop]')) focusDo({focus:'stop'});
  else if (t.closest('[data-focusclose]')) $('focusDlg').close();
  else if ((b = t.closest('[data-pick]'))) { LOOK[b.getAttribute('data-pick')] = b.getAttribute('data-val'); renderLook(); }
  else if (t.closest('[data-lookcancel]')) $('lookDlg').close();
});

/* ---------- chat (stage 2, migration 082) ----------
   Channels are read from anywhere ("the Discord way", agreed 2026-11-12); a raven is a conversation
   between two people. An open conversation asks for anything new every 3 seconds and stops asking
   while the window is hidden; unread counts for everything else ride on the 25-second heartbeat.
   ST.chat is null until the migration has run, and then none of this draws. */
/* first: the first message on screen, so the reactions asked for are those of the messages shown. */
var CH = {open:null,last:0,first:0,timer:0,busy:false,seen:false,wide:false};
/* THE CONVERSATION OVER THE MAP (David, 2026-11-12: "an arrow pointing left at the middle of the
   chat bar so there's an option to extend it over the map"). The handle on the conversation's left
   edge lays it over the map, for room to read and write, and brings it back. The choice is kept
   for the next conversation and the next visit. assets/realm.css does the laying out (and does
   nothing on a narrow screen, where the panels are stacked); this keeps the handle's words right
   and repaints the map, whose canvas takes its size from its box.
   Picking a house or a place in the lists brings the map back: that click is about the map. */
try { CH.wide = localStorage.getItem('realmChatWide')==='1'; } catch (e) { /* A private window may refuse; the normal width stands. */ }
function chatWide(on) {
  var app = $('chat') && $('chat').parentNode, btn = $('chatWide'), box = $('chatMsgs'); if (!app) return;
  var stick = box && box.scrollHeight-box.scrollTop-box.clientHeight < 60, say = on ? 'Make the conversation narrower' : 'Make the conversation wider';
  CH.wide = !!on; app.classList.toggle('chat-wide',CH.wide); held.clear();
  if (btn) { btn.setAttribute('aria-expanded',String(CH.wide)); btn.setAttribute('aria-label',say); btn.title = say; }
  try { localStorage.setItem('realmChatWide',CH.wide?'1':'0'); } catch (e) { /* Then it is simply not remembered. */ }
  if (stick) box.scrollTop = box.scrollHeight;
  draw();
}
/* True while the map is not on screen because the conversation lies over it. Asked of the page, not
   of CH.wide: on a narrow screen the wide setting changes nothing and the map is still there. */
function mapAway() { var m = $('mapbox'); return !!(CH.open && CH.wide && m && !m.offsetWidth); }
function unread() { return (ST.chat && ST.chat.unread) || {}; }
function dmId(a, b) { return 'dm:'+Math.min(a,b)+':'+Math.max(a,b); }
function chanTitle(id) {
  var m = /^dm:(\d+):(\d+)$/.exec(id);
  if (m) { var other = person(+m[1]===ST.me ? +m[2] : +m[1]); return other ? 'Ravens with '+firstName(other.name) : 'Ravens'; }
  var c = ((ST.chat && ST.chat.channels)||[]).filter(function (x) { return x.id===id; })[0];
  return c ? c.name : 'Chat';
}
/* The conversation that belongs to a building: a place's channel, or ravens with a house's owner. */
function chanFor(placeId) {
  if (!ST.chat) return null;
  if (placeId==='tavern' || placeId==='council') return placeId;
  var m = /^land:(.+)$/.exec(placeId);
  if (m) { var l = ST.lands.filter(function (x) { return x.land===m[1]; })[0]; return l && l.project_id ? 'project:'+l.project_id : null; }
  m = /^user:(\d+)$/.exec(placeId);
  return m && +m[1]!==ST.me ? dmId(ST.me,+m[1]) : null;
}
/* New unread counts arrived. A raven that was not there a moment ago is announced, because a small
   number appearing beside a name is easy to miss (David, 2026-11-12: "not working atm"). */
function takeUnread(u) {
  if (!ST.chat || !u) return;
  var old = ST.chat.unread || {};
  Object.keys(u).forEach(function (k) {
    var m = /^dm:(\d+):(\d+)$/.exec(k);
    if (!m || k===CH.open || u[k] <= (old[k]||0)) return;
    var who = person(+m[1]===ST.me ? +m[2] : +m[1]);
    toast('A raven from '+(who ? firstName(who.name) : 'somebody')+'. Click here to read it.',k);
  });
  ST.chat.unread = u; renderSide(); badge();
}
function askUnread() {
  if (!ST.chat || CH.open || document.hidden) return;
  fetch(R.api+'?unread=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { takeUnread(j.unread); takeKnocks(j.knocks); if (R.onOffers) R.onOffers(j.offers); if (!VO.on) takeVoiceRoom(j.voice); takeSpots(j.spots); } }).catch(function () {});
}
/* Is this conversation the talk of the room I am standing in? The room's own channel, or, in MY
   house, my ravens with a visitor who is inside it: the owner must see a guest speak too. */
function spokenHere(ch) {
  if (!S.inside) return false;
  if (ch===chanFor(S.inside)) return true;
  var m = /^dm:(\d+):(\d+)$/.exec(ch);
  if (!m || S.inside!=='user:'+ST.me) return false;
  var o = person(+m[1]===ST.me ? +m[2] : +m[1]);
  return !!(o && o.inside && o.place===S.inside && o.state!=='away');
}
/* KNOCKING (David, 2026-11-12: "when a door is ajar, let's open the option to knock"), migration
   084. I knock at an ajar door I am standing at; the owner gets a notice with Let in and Not now;
   being let in walks me inside. PASS is who has let me in; KN.mine is my own latest knock, and
   KN.shown the knocks on my door I have already been told about. */
var PASS = {}, KN = {ready:false,mine:null,shown:{},first:true};
function knockNotice(q) {
  var t = document.createElement('div'); t.className = 'rtoast is-link'; t.setAttribute('data-knockbox',q.id);
  t.innerHTML = '<b>'+esc(firstName(q.name))+' is knocking at your door</b><div class="racts"><button type="button" class="rbtn rprimary rsmall" data-knockanswer="'+q.id+'" data-let="1">Let in</button><button type="button" class="rbtn rsmall" data-knockanswer="'+q.id+'" data-let="0">Not now</button></div>';
  toastHome().appendChild(t); setTimeout(function () { t.remove(); },120000);
}
function takeKnocks(k) {
  if (!k) return;
  KN.ready = !!k.ready;
  (k.for_me || []).forEach(function (q) { if (!KN.shown[q.id]) { KN.shown[q.id] = true; knockNotice(q); } });
  var m = k.mine || null, was = KN.mine, changed = !!m && (!was || was.to!==m.to || was.status!==m.status);
  KN.mine = m;
  if (m && m.status==='in') PASS[m.to] = true;
  /* What was already true when the page loaded is not news: no message, and no walking in by itself. */
  if (changed && !KN.first) {
    var o = person(m.to), nm = o ? firstName(o.name) : 'They';
    if (m.status==='in') { toast(nm+' opened the door.'); if (S.here==='user:'+m.to && !S.inside) goInside('user:'+m.to); }
    else if (m.status==='no') toast(nm+' cannot talk right now.');
    else if (m.status==='gone') toast('No answer from '+nm+'.');
  }
  KN.first = false;
  if (changed || (was && !m)) renderCard();
}
/* A PICTURE OPENS INSIDE THE REALM (David, 2026-11-12: "the visualization of the images in a chat
   should happen inside the realm not in a blank tab"). One viewer, made the first time it is needed. */
function showPicture(src, alt) {
  var d = $('picDlg');
  if (!d) { d = document.createElement('dialog'); d.id = 'picDlg'; d.className = 'rpic'; d.innerHTML = '<img id="picImg" alt=""><div class="rdacts"><button type="button" class="rbtn rprimary" data-picclose="1">Close</button></div>'; document.body.appendChild(d);
    d.addEventListener('click',function (e) { if (e.target===d) d.close(); }); }
  $('picImg').src = src; $('picImg').alt = alt || 'Picture';
  if (d.showModal) { if (!d.open) d.showModal(); } else window.open(src,'_blank');
}
function badge() { var u = unread(), n = 0; Object.keys(u).forEach(function (k) { if (k!==CH.open) n += u[k]; }); document.title = (n?'('+n+') ':'')+'Anima Realm'; }
/* The words of a message, with every tagged name ("@Renata Ruiz") made to stand out, mine most of
   all. One pass over the text with the longest names first, so "@Renata Ruiz" is never cut short
   at "@Renata". The text is escaped BEFORE anything is added to it. */
function rich(body) {
  var h = esc(body), ppl = ST.people.slice().sort(function (a, b) { return b.name.length-a.name.length; });
  if (!ppl.length || h.indexOf('@') < 0) return h;
  var mine = me(), re = new RegExp('@('+ppl.map(function (p) { return esc(p.name).replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }).join('|')+')','gi');
  return h.replace(re,function (all, n) { return '<b class="rtag'+(mine && esc(mine.name).toLowerCase()===n.toLowerCase()?' is-me':'')+'">'+all+'</b>'; });
}
function msgHTML(m) {
  var pic = m.image ? '<a class="rimglink" href="realm_image.php?id='+m.id+'" target="_blank" rel="noopener"><img class="rimg" src="realm_image.php?id='+m.id+'" alt="Picture from '+esc(m.name)+'" loading="lazy"></a>' : '';
  return '<div class="rmsg'+(m.mine?' is-mine':'')+'" data-msg="'+(+m.id)+'"><div class="rmsghead"><b>'+esc(m.mine?'You':m.name)+'</b><small>'+esc(m.at)+'</small></div>'+(m.body?'<div class="rmsgbody">'+rich(m.body)+'</div>':'')+pic+'</div>';
}
function chatTake(j) {
  CH.busy = false;
  if (!j) return;
  if (j.error) toast(j.error);
  if (!j.ok) { closeChat(); return; }
  if (j.channel!==CH.open) return;
  var box = $('chatMsgs'), stick = box.scrollHeight-box.scrollTop-box.clientHeight < 60, fresh = j.messages.filter(function (m) { return m.id > CH.last; });
  if (fresh.length && CH.last && spokenHere(j.channel)) {
    fresh.forEach(function (m) { BUB[m.user] = {text:m.body || '(a picture)',until:Date.now()+BUBBLE_MS}; });
    draw(); setTimeout(draw,BUBBLE_MS+100);
  }
  if (fresh.length) { if (!CH.last) { box.innerHTML = ''; CH.first = fresh[0].id; } box.insertAdjacentHTML('beforeend',fresh.map(msgHTML).join('')); CH.last = fresh[fresh.length-1].id; if (stick || !CH.seen || CH.jump) box.scrollTop = box.scrollHeight; }
  if (CH.jump && j.messages) { CH.jump = false; box.scrollTop = box.scrollHeight; }
  else if (!CH.last && !CH.seen) box.innerHTML = '<p class="rempty">Nothing here yet. Be the first to write.</p>';
  if (FIND.jump) findLand(fresh);
  CH.seen = true;
  /* Reactions on the messages (migration 093) are drawn by assets/realm_react.js. */
  if (R.onChat) R.onChat(j);
  $('chatInput').disabled = !j.can_post; $('chatSend').disabled = !j.can_post;
  if ($('chatAttach')) $('chatAttach').hidden = !(j.can_post && ST.chat && ST.chat.images); $('chatInput').placeholder = j.can_post ? 'Write a message' : 'Only Production writes here';
  if (j.unread) takeUnread(j.unread);
  takeKnocks(j.knocks);
  /* Offers of a gift or a swap ride on the same answers as knocks (assets/realm_trade.js). */
  if (R.onOffers) R.onOffers(j.offers);
  /* While I am in voice my own once-a-second request brings a fresher list than this one. */
  if (!VO.on) takeVoiceRoom(j.voice);
  takeSpots(j.spots);
}
function chatAsk() {
  if (!CH.open || CH.busy || document.hidden) return; CH.busy = true;
  fetch(R.api+'?chat='+encodeURIComponent(CH.open)+'&after='+CH.last+'&from='+CH.first+(FIND.jump && !CH.last ? '&at='+FIND.jump : ''),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(chatTake).catch(function () { CH.busy = false; });
}
function openChat(id) {
  if (!ST.chat || !id || !$('chat')) return;
  if (CH.open===id) { $('chatInput').focus(); return; }
  CH.open = id; CH.last = 0; CH.first = 0; CH.seen = false; CH.busy = false; setPick(null); sugClose();
  $('chat').hidden = false; $('chat').parentNode.classList.add('with-chat'); chatWide(CH.wide); $('chatTitle').textContent = chanTitle(id); $('chatMsgs').innerHTML = '';
  clearInterval(CH.timer); CH.timer = setInterval(chatAsk,3000); chatAsk(); renderSide(); badge(); draw(); $('chatInput').focus();
}
function closeChat() { CH.open = null; clearInterval(CH.timer); $('chat').hidden = true; $('chat').parentNode.classList.remove('with-chat'); renderSide(); badge(); draw(); }
/* A PICTURE IN A MESSAGE (David, 2026-11-12: "let's also allow images ... in all chats"), migration
   083. One per message, up to 5 MB, chosen with the + button or pasted straight into the box. The
   server checks it again; this only saves a wasted upload. */
var PICK = null;
function setPick(f) {
  var el = $('chatPicked'); PICK = f || null;
  if ($('chatFile') && !PICK) $('chatFile').value = '';
  if (!el) return;
  el.hidden = !PICK;
  el.innerHTML = PICK ? '<span>Picture to send: '+esc(PICK.name || 'pasted image')+'</span><button type="button" class="rbtn rsmall" data-unpick="1">Remove</button>' : '';
}
function offerPick(f) {
  if (!f) return;
  if (!(ST.chat && ST.chat.images)) { toast('Pictures need a database update first.'); return; }
  if (!/^image\/(png|jpeg|gif|webp)$/.test(f.type)) { toast('Only pictures can be sent in chat: PNG, JPG, GIF or WebP.'); return; }
  if (f.size > 5*1024*1024) { toast('That picture is larger than 5 MB. Send a smaller one, or a link to it.'); return; }
  setPick(f); $('chatInput').focus();
}
/* TAGGING (the same request): type @ and pick a name. What the picker writes is exactly the name
   the server looks for, so somebody who can be picked is somebody whose bell will ring. */
var SUG = {list:[],at:0,i:0};
function sugClose() { SUG.list = []; var el = $('chatSuggest'); if (el) { el.hidden = true; el.innerHTML = ''; } }
function sugDraw() {
  var el = $('chatSuggest'); if (!el) return;
  el.hidden = false;
  el.innerHTML = SUG.list.map(function (p, i) { return '<button type="button" class="rrow'+(i===SUG.i?' is-on':'')+'" data-tag="'+i+'">@'+esc(p.name)+'</button>'; }).join('');
}
function sugUpdate() {
  var inp = $('chatInput'), upto = inp.value.slice(0,inp.selectionStart), m = /(^|\s)@([^\s@]*)$/.exec(upto);
  if (!m || !CH.open) { sugClose(); return; }
  var q = m[2].toLowerCase();
  SUG.at = upto.length-m[2].length-1; SUG.i = 0;
  SUG.list = ST.people.filter(function (p) { var n = p.name.toLowerCase(); return !p.me && n.split(' ').concat([n]).some(function (w) { return w.indexOf(q)===0; }); }).slice(0,6);
  if (SUG.list.length) sugDraw(); else sugClose();
}
function sugPick(i) {
  var p = SUG.list[i], inp = $('chatInput'); if (!p) return;
  var at = SUG.at, end = inp.selectionStart, pos = at+p.name.length+2;
  inp.value = inp.value.slice(0,at)+'@'+p.name+' '+inp.value.slice(end);
  sugClose(); inp.focus(); inp.setSelectionRange(pos,pos);
}
if ($('chatInput')) {
  $('chatInput').addEventListener('input',sugUpdate);
  $('chatInput').addEventListener('keydown',function (e) {
    if (!SUG.list.length) return;
    if (e.key==='ArrowDown' || e.key==='ArrowUp') { e.preventDefault(); SUG.i = (SUG.i+(e.key==='ArrowDown'?1:SUG.list.length-1))%SUG.list.length; sugDraw(); }
    else if (e.key==='Enter' || e.key==='Tab') { e.preventDefault(); sugPick(SUG.i); }
    else if (e.key==='Escape') { e.stopPropagation(); sugClose(); }
  });
  $('chatInput').addEventListener('paste',function (e) {
    var fs = e.clipboardData && e.clipboardData.files;
    if (fs && fs.length && /^image\//.test(fs[0].type)) { e.preventDefault(); offerPick(fs[0]); }
  });
}
if ($('chatFile')) $('chatFile').addEventListener('change',function () { offerPick(this.files[0]); });
/* Guarded: a page drawn before chat existed has no form, and must not stop the map over it. */
if ($('chatForm')) $('chatForm').addEventListener('submit',function (e) {
  e.preventDefault();
  var inp = $('chatInput'), body = inp.value.trim(), pic = PICK; if ((!body && !pic) || !CH.open) return;
  inp.value = ''; CH.busy = true; sugClose(); setPick(null);
  /* SENDING TAKES YOU TO THE BOTTOM (David, 2026-11-13: "when hit enter it should take you to the end
     of the conversation (the bottom) so we can see what's the last thing we sent"). Before, the list
     only followed new messages if you were already near the bottom, so after scrolling up to read,
     your own message landed out of sight. Now it jumps down at once, and again when the answer with
     your message arrives (chatTake reads CH.jump). */
  CH.jump = true; var mb = $('chatMsgs'); if (mb) mb.scrollTop = mb.scrollHeight;
  /* A picture travels as a file, so the message goes as a form with parts; words alone go as before. */
  var f = pic ? new FormData() : new URLSearchParams(); f.append('say',CH.open); f.append('body',body); f.append('after',CH.last); f.append('from',CH.first);
  if (pic) f.append('image',pic,pic.name || 'pasted-image.png');
  fetch(R.api,{method:'POST',body:f,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(chatTake).catch(function () { CH.busy = false; CH.jump = false; inp.value = body; setPick(pic); toast('That message was not sent. Try again.'); });
});

/* ---------- search (David, 2026-11-13: "all channels and dms") ----------
   The studio is on Slack's free plan and loses everything older than 90 days; every Realm message is
   kept, so it can all be found again. A box at the top of the side panel: typing a word or two lists
   the matching messages, newest first, in place of the lists below it; clicking one opens that
   conversation at that message, lit up for a moment. Escape, or emptying the box, brings the lists
   back.
   ⚠ WHICH CONVERSATIONS ARE SEARCHED IS DECIDED ON THE SERVER (includes/realm_search.php), from the
   real login: this only sends the words. Nothing here can widen it.
   The box and the results live outside #side, which is redrawn every few seconds. */
var FIND = {q:'',timer:0,seq:0,list:[],more:false,busy:false,jump:0};
function findMarks(text) {
  /* Escaped first; then every searched word is marked in one pass, so a mark is never put inside
     another one or inside the escaping. */
  var h = esc(text), words = FIND.q.toLowerCase().split(/\s+/).filter(function (w) { return w.length; })
    .map(function (w) { return esc(w).replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); });
  if (!words.length) return h;
  words.sort(function (a, b) { return b.length-a.length; });
  return h.replace(new RegExp('('+words.join('|')+')','gi'),'<mark>$1</mark>');
}
function findDraw(note) {
  var box = $('searchRes'), h = '';
  if (!box) return;
  if (FIND.list.length) {
    h += '<div class="rfoundlist">'+FIND.list.map(function (m) {
      return '<button type="button" class="rfound" data-found="'+esc(m.channel)+'" data-mid="'+m.id+'"><span class="rfoundhead"><b>'+esc(m.mine?'You':m.name)+'</b><small>'+esc(m.at)+'</small></span><span class="rfoundwhere">'+esc(m.where)+'</span>'+
        '<span class="rfoundbody">'+(m.snippet ? findMarks(m.snippet) : '<i>A picture</i>')+(m.image && m.snippet ? ' <i>(with a picture)</i>' : '')+'</span></button>';
    }).join('')+'</div>';
    if (FIND.more) h += '<button type="button" class="rbtn rsmall rfoundmore" data-findmore="1"'+(FIND.busy?' disabled':'')+'>'+(FIND.busy?'Looking…':'Older results')+'</button>';
  } else if (!note) note = FIND.busy ? 'Looking…' : 'Nothing found. Every conversation you can read was searched.';
  if (note) h += '<p class="rfoundnote">'+esc(note)+'</p>';
  box.innerHTML = '<h2>Messages</h2>'+h;
}
function findShow(on) {
  if (!$('searchRes')) return;
  $('searchRes').hidden = !on; $('side').hidden = on;
}
function findAsk(older) {
  var q = FIND.q, seq = ++FIND.seq, before = older && FIND.list.length ? FIND.list[FIND.list.length-1].id : 0;
  FIND.busy = true; if (older) findDraw(); else { FIND.list = []; FIND.more = false; findDraw(); }
  fetch(R.api+'?search='+encodeURIComponent(q)+(before?'&before='+before:''),{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); })
    .then(function (j) {
      /* An answer to words that have since been changed is dropped: only the latest counts. */
      if (seq!==FIND.seq) return;
      FIND.busy = false;
      if (!j || !j.ok) { FIND.list = []; FIND.more = false; findDraw((j && j.error) || 'The search could not be run. Try again in a moment.'); return; }
      FIND.list = (older ? FIND.list : []).concat(j.results || []); FIND.more = !!j.more; findDraw(j.note || '');
    })
    .catch(function () { if (seq!==FIND.seq) return; FIND.busy = false; findDraw('The search could not be run. Try again in a moment.'); });
}
function findTyped() {
  var q = $('searchInput').value.trim();
  clearTimeout(FIND.timer);
  if (q===FIND.q && q) return;
  FIND.q = q;
  if (!q) { FIND.seq++; FIND.list = []; findShow(false); return; }
  findShow(true);
  if (q.replace(/\s+/g,'').length < 2) { FIND.seq++; FIND.list = []; FIND.more = false; findDraw('Type at least two letters.'); return; }
  /* A short pause after the last key, so a word is not searched for letter by letter. */
  FIND.timer = setTimeout(function () { findAsk(false); },300);
}
function findClear() { if (!$('searchInput')) return; $('searchInput').value = ''; findTyped(); }
/* Open the conversation a result is in, at that message. A conversation that is already open is
   opened again, so it starts at the found message rather than wherever it was. */
function findOpen(channel, id) {
  FIND.jump = id;
  if (CH.open===channel) CH.open = null;
  openChat(channel);
}
/* The conversation opened from a result has drawn its first page: bring the found message into
   view and light it up. When it is not on that page (it may have been in a reply, or removed), the
   conversation simply stays where it opened. */
function findLand(fresh) {
  var id = FIND.jump, box = $('chatMsgs'), i = -1, el;
  FIND.jump = 0;
  fresh.forEach(function (m, k) { if (m.id===id) i = k; });
  /* Each message carries its id (added with reactions, PR 238). Failing that, the page was drawn
     from empty, so its messages are the box's children in the same order. */
  el = box.querySelector('.rmsg[data-msg="'+id+'"]') || (i >= 0 && box.children.length===fresh.length ? box.children[i] : null);
  if (!el) { toast('That message could not be shown in the conversation.'); return; }
  el.classList.add('is-found');
  el.scrollIntoView({block:'center'});
  setTimeout(function () { el.classList.remove('is-found'); },2600);
}
if ($('search') && ST.chat) {
  $('search').hidden = false;
  $('searchInput').addEventListener('input',findTyped);
  $('searchInput').addEventListener('keydown',function (e) {
    if (e.key==='Escape') { e.preventDefault(); findClear(); this.blur(); }
  });
  /* Enter searches at once, without the pause. */
  $('search').addEventListener('submit',function (e) {
    e.preventDefault(); clearTimeout(FIND.timer);
    var q = $('searchInput').value.trim(); if (q.replace(/\s+/g,'').length < 2) return;
    FIND.q = q; findShow(true); findAsk(false);
  });
  $('searchRes').addEventListener('click',function (e) {
    var b = e.target.closest('[data-found]');
    if (b) { findOpen(b.getAttribute('data-found'),+b.getAttribute('data-mid')); return; }
    if (e.target.closest('[data-findmore]') && !FIND.busy) findAsk(true);
  });
}

/* ---------- your character ---------- */
var LOOK = {};
/* WHAT THE MERCHANT SELLS CAN BE TRIED ON (David, 2026-11-13: the things for sale are shown here
   with their price). A choice that is not mine yet is marked with its price; picking it shows it on
   the character, says where it is sold, and Save waits until it is put back. The server refuses it
   as well (realm_set_look). */
function lookSale(key, v) { return key!=='house_kind' && PAID[v] && !owns(v); }
function seg(label, key, opts) { return '<div class="rpick"><span>'+label+'</span><span class="rseg">'+Object.keys(opts).map(function (v) { var sale = lookSale(key,v); return '<button type="button"'+(sale?' class="is-sale" title="The merchant in the Tavern sells this"':'')+' data-pick="'+key+'" data-val="'+v+'" aria-pressed="'+(LOOK[key]===v)+'">'+opts[v]+(sale?' <i>'+(price(v)||'gold')+'</i>':'')+'</button>'; }).join('')+'</span></div>'; }
function renderLook() {
  var names = {cloak:CLOAKS,hat:HATS,pet:PETS}, tried = ['cloak','hat','pet'].filter(function (k) { return lookSale(k,LOOK[k]); });
  var note = tried.length ? '<small class="rtry">You are trying on '+tried.map(function (k) { var v = LOOK[k]; return 'the '+esc(names[k][v].toLowerCase())+(k==='cloak'?' cloak':'')+(price(v)?' ('+price(v)+' coins)':''); }).join(' and ')+'. The merchant in the Tavern sells '+(tried.length > 1?'them':'it')+'. Pick something of yours to save.</small>' : '';
  $('lookForm').innerHTML = '<div class="rlook"><canvas id="charcv" class="rchar" width="120" height="120" role="img" aria-label="Your character"></canvas><div class="rpicks">'+seg('Cloak','cloak',CLOAKS)+seg('Head','hat',HATS)+seg('Companion','pet',PETS)+seg('House','house_kind',KINDS)+'</div></div><small>Your roof takes the colour of your cloak, so your house and your character match.</small>'+note+'<div class="rdacts"><button type="button" class="rbtn" data-lookcancel="1">Cancel</button><button class="rbtn rprimary"'+(tried.length?' disabled':'')+'>Save</button></div>';
  var c = $('charcv').getContext('2d'); c.setTransform(1,0,0,1,0,0); c.clearRect(0,0,120,120); c.setTransform(2,0,0,2,0,0); sprite(c,22,58,LOOK,0);
}
function openLook() { var m = me(); if (!m) return; LOOK = {cloak:m.cloak,hat:m.hat,pet:m.pet,house_kind:m.kind}; renderLook(); $('lookDlg').showModal(); }
$('lookForm').addEventListener('submit',function (e) { e.preventDefault(); if (['cloak','hat','pet'].some(function (k) { return lookSale(k,LOOK[k]); })) return; $('lookDlg').close(); send({set_look:1,cloak:LOOK.cloak,hat:LOOK.hat,pet:LOOK.pet,house_kind:LOOK.house_kind}); });

/* ---------- the focus desk ----------
   David, 2026-11-12: "how can I change to in focus time? I think we should play a soft music while
   on focus and maybe list all the pending tasks you have in anima? With the option to start the
   clock."

   THERE IS NO CLOCK IN THIS FILE. Start and Stop ask the server, which starts and stops Anima's own
   timer (includes/realm_focus.php, includes/timelog.php), the one My tasks shows. What runs here is
   only the readout: the server says how many seconds are left, this counts them down between
   answers, and asks again every minute so the two never drift far. When a focus block reaches zero
   the page asks the server to settle it (log the block, begin the break) ONCE; if the page is shut
   instead, My tasks settles it the next time anybody looks, so no minutes depend on this tab.

   THE WAY IN IS THE BOOKSHELF in your own house (placeHot, above). The top bar shows a button only
   while a clock is running: it reads "Focus 18:42", so the time is in sight, and can be stopped,
   from anywhere in the village. Somebody with no house yet has no shelf, so for them the button
   stays, reading "Your tasks". */
var FO = {data:null,at:0,busy:false,seq:0,settled:'',zero:false,err:'',force:false};
function clockWords(s) { s = Math.max(0,Math.floor(s)); var h = Math.floor(s/3600), m = Math.floor(s%3600/60), x = s%60; return (h?h+':'+(m<10?'0':'')+m:m)+':'+(x<10?'0':'')+x; }
function focusNow() { return FO.data && FO.data.ready ? FO.data.now : null; }
function onBreak(n) { return !!n && (n.phase==='short_break' || n.phase==='long_break'); }
/* Seconds left in the phase, or, for a plain timer with no end, seconds run so far. */
function focusLeft() { var n = focusNow(); if (!n) return 0; var gone = Math.max(0,Math.floor((Date.now()-FO.at)/1000)); return n.remaining===null ? n.elapsed+gone : Math.max(0,n.remaining-gone); }
function focusTask(key) { var l = (FO.data && FO.data.tasks)||[]; for (var i = 0; i < l.length; i++) if (l[i].key===key) return l[i]; return null; }
/* WHAT EACH TASK SHOWS (David, 2026-11-12, with a picture of the Production tasks table on My tasks:
   "I'd like to expand what we see for each task and include status, editable in this context, and
   due date"). A row is the title, then the show, the due date (red, and the row tinted, once it has
   passed, as on My tasks), the hours already logged and a way to open it in Anima; beside them its
   status in the status's own colour, and Start.

   THE STATUS IS A CONTROL ONLY WHEN IT CAN BE CHANGED. The server sends, per show, the statuses and
   which of them this account may choose (`open`): every one for Production, In progress and Pending
   review for anybody else, exactly as My tasks offers them. A task with nothing to choose (the
   switch is off, or the show spells those two differently) shows its status as plain words. The
   list is only what is DRAWN: the server asks the same rules again when a change arrives
   (includes/own_status.php, the code My tasks runs), so nothing here is a permission.

   A status's colour is data from the show's own list, so it is written into a style only when it is
   a plain six-digit hex; anything else falls back to the frame's own colours. */
function hex(c) { return /^#[0-9a-f]{6}$/i.test(c||'') ? c : ''; }
function statusCell(k, d) {
  var may = d.may||{}, all = (d.statuses||{})[k.pid]||[], c = hex(k.color), none = k.key.charAt(0)==='p'?'Not started':'No status', pick = 0, seen = false, o = '';
  all.forEach(function (s) { var now = s.id===k.sid; if (now) seen = true; if (!s.open && !now) return; if (s.open && !now) pick++;
    o += '<option value="'+s.id+'"'+(now?' selected':'')+(s.open?'':' disabled')+'>'+esc(s.name)+'</option>'; });
  if (may.clear && k.sid!==null) pick++;
  if (!may.status || !pick) return '<span class="rfchip"'+(c?' style="color:'+c+';border-color:'+c+'66;background:'+c+'1a"':'')+'>'+esc(k.status||none)+'</span>';
  o = (k.sid===null || may.clear ? '<option value=""'+(k.sid===null?' selected':'')+(may.clear?'':' disabled')+'>'+none+'</option>' : '')
    + (k.sid!==null && !seen ? '<option value="'+k.sid+'" selected disabled>'+esc(k.status)+'</option>' : '') + o;
  return '<select class="rfsel" data-focusstatus="'+esc(k.key)+'" aria-label="Status of '+esc(k.title)+'"'+(c?' style="color:'+c+';border-color:'+c+'66"':'')+'>'+o+'</select>';
}
function renderFocus(force) {
  var top = $('focusNow'), list = $('focusList'); if (!top) return;
  var d = FO.data, n = focusNow(), brk = onBreak(n), left = focusLeft(), h = '';
  $('focusMusic').hidden = !(d && d.ready && (window.AudioContext || window.webkitAudioContext));
  if (!d || !d.ready) { top.innerHTML = '<p class="rfidle">'+esc(d ? d.why : (FO.err || 'Fetching your tasks…'))+'</p>'; list.innerHTML = ''; return; }
  if (n) {
    var t = focusTask(n.key), hint = n.phase==='focus' ? (left ? 'Your door is sealed until this block ends. Stopping early logs the minutes so far.' : 'This block is over.')
      : brk ? (left ? 'A break is not logged. Your door is open again.' : 'The break is over. Start the next block when you are ready.')
      : 'Counting up. Stopping logs the time.';
    h = '<div class="rfnow '+(brk?'is-break':'is-focus')+'"><small class="rfphase">'+esc(n.label)+(n.since?', since '+esc(n.since):'')+'</small><b>'+esc(t?t.title:'Work started in My tasks')+'</b>'+(t&&t.show?'<small>'+esc(t.show)+'</small>':'')
      +'<span class="rfclock" id="focusClock">'+clockWords(left)+'</span><small>'+hint+'</small><div class="racts">'
      +(brk ? '<button type="button" class="rbtn rprimary rsmall" data-focusstart="'+esc(n.key)+'">Start the next focus block</button><button type="button" class="rbtn rsmall" data-focusstop="1">End the cycle</button>'
            : '<button type="button" class="rbtn rprimary rsmall" data-focusstop="1">Stop and log</button>')+'</div></div>';
  } else h = '<p class="rfidle">No clock is running. Press Start on something below. It starts Anima\'s own timer, the one My tasks shows, and seals your door until the focus block ends.</p>';
  top.innerHTML = h;
  /* The minute's refresh must not close a status list somebody has open. A change they made, or a
     refusal, always redraws (force). */
  var at = document.activeElement, box = list.querySelector('.rflist'), y = box ? box.scrollTop : 0;
  if (!force && at && at.getAttribute && at.getAttribute('data-focusstatus') && list.contains(at)) return;
  h = '<h3 class="rfhead">Your open work</h3>';
  if (!d.tasks.length) h += '<p class="rfidle">Nothing is assigned to you right now. Tasks and cards given to you in Anima appear here.</p>';
  else { h += '<div class="rflist">';
    d.tasks.forEach(function (k) { var mine = !!n && n.key===k.key, card = k.key.charAt(0)==='t';
      h += '<div class="rftask'+(mine?' is-on':'')+(k.late?' is-late':'')+'"><div class="rfwhat"><b title="'+esc(k.title)+'">'+esc(k.title)+'</b><small class="rfmeta">'
        +'<span>'+esc([k.ref,k.show].filter(Boolean).join(', '))+'</span>'
        +'<span class="rfdue'+(k.late?' is-late':k.due?'':' is-none')+'">'+(k.due ? 'Due '+esc(k.due)+(k.late?', overdue':'') : 'No due date')+'</span>'
        +(k.logged?'<span>'+esc(k.logged)+' logged</span>':'')
        +(k.link?'<a href="'+esc(k.link)+'" target="_blank" rel="noopener">'+(card?'Open the card':'Open in Spell')+'</a>':'')+'</small></div>'
        +'<div class="rfstat">'+statusCell(k,d)+(k.blocked?'<small class="rfblocked">blocked</small>':'')+'</div>'
        +(mine ? '<small class="rfrun">'+(brk?'On a break':'Running')+'</small>' : '<button type="button" class="rbtn rsmall" data-focusstart="'+esc(k.key)+'"'+(n?' title="This stops and logs the one already running"':'')+'>Start</button>')+'</div>'; });
    h += '</div>'; }
  list.innerHTML = h;
  box = list.querySelector('.rflist'); if (box) box.scrollTop = y;
}
/* Once a second: the readout in the desk and on the top bar, the music, and the settle at zero. */
function focusTick() {
  var n = focusNow(), left = focusLeft(), btn = $('focusBtn'), ck = $('focusClock');
  var word = n ? (onBreak(n)?'Break ':n.phase==='focus'?'Focus ':'Timer ')+clockWords(left) : 'Your tasks', tag = $('shelfTag');
  if (tag) tag.textContent = word;
  if (btn) { btn.textContent = word; btn.hidden = !n && !!me();
    btn.classList.toggle('is-focus',!!n && !onBreak(n)); btn.classList.toggle('is-break',onBreak(n)); }
  if (ck) ck.textContent = clockWords(left);
  musicSync();
  if (!n || n.remaining===null || left > 0) return;
  if (!FO.zero) { FO.zero = true; renderFocus(); }
  /* ONCE per block: `settled` remembers which block was handed in, so a server that could not log it
     is not asked again every second. It is cleared when the server next reports time left. */
  var sig = n.key+'|'+n.since;
  if (n.phase==='focus' && FO.settled!==sig && !FO.busy) { FO.settled = sig; focusDo({focus:'settle'}); }
}
function focusTake(j, seq, acted) {
  if (seq!==FO.seq) return;
  FO.busy = false;
  var force = FO.force; FO.force = false;
  if (!j) { if (!FO.data) FO.err = 'Your tasks could not be reached. Try again in a moment.'; if (!FO.data || force) renderFocus(true); return; }
  if (j.said) toast(j.said);
  if (j.error) { toast(j.error); if (!FO.data) FO.err = j.error; }
  if (j.focus) {
    var was = focusNow();
    FO.data = j.focus; FO.at = Date.now(); FO.zero = false;
    var n = focusNow();
    if (!n || n.phase!=='focus' || n.remaining > 0) FO.settled = '';
    if (was && was.phase==='focus' && onBreak(n)) musicChime();
  }
  renderFocus(force); focusTick();
  /* My own door has just sealed or opened: fetch the map again so it is redrawn now, not at the next beat. */
  if (acted) send({ping:1,active:1});
}
function focusAsk() {
  if (FO.busy) return; var seq = ++FO.seq;
  fetch(R.api+'?focus=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { focusTake(j,seq,false); }).catch(function () { focusTake(null,seq,false); });
}
function focusDo(fields) {
  if (FO.busy) return; FO.busy = true; FO.force = true; var seq = ++FO.seq, body = new URLSearchParams();
  /* A status change moves no door, so the map is not asked for again after it. */
  var door = !('task_status' in fields);
  Object.keys(fields).forEach(function (k) { body.append(k,fields[k]); });
  fetch(R.api,{method:'POST',body:body,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { focusTake(j,seq,door); })
    .catch(function () { focusTake(null,seq,false); toast('That did not reach Anima. Try again.'); });
}
function openFocus() { var d = $('focusDlg'); if (!d || d.open) return; renderFocus(true); d.showModal(); focusAsk(); }
/* Somebody picked a status. The server decides (the rules My tasks runs) and answers with the whole
   list again, so a refused change snaps back, and a task that is now approved leaves the list as it
   leaves My tasks. Until the answer comes the control is held, so it cannot be changed twice. */
function focusStatus(sel) {
  if (FO.busy) { toast('One moment, Anima is still answering.'); renderFocus(true); return; }
  sel.disabled = true; focusDo({task_status:sel.value,task:sel.getAttribute('data-focusstatus')});
}

/* THE MUSIC (the same request: "a soft music while on focus"). Made here, by the browser's own sound
   engine: no audio file, nothing to license, nothing loaded from another site (the rule at the top
   of realm.php). What it plays is described at LOFI, below.

   IT PLAYS ONLY DURING A FOCUS BLOCK, and only with the switch on: it fades in when one starts and
   out when it stops or becomes a break, where two bell notes mark the end. Browsers refuse sound
   until the person has clicked something, so the sound engine is only ever woken inside a click
   (musicWake); a block already running when the page opens starts playing at the first click.
   The switch and the volume are remembered on this computer. */
var MU = {on:true,vol:.5,ctx:null,rig:null};
try { if (localStorage.getItem('realmMusic')==='0') MU.on = false; var mv = parseFloat(localStorage.getItem('realmMusicVol')); if (mv >= 0 && mv <= 1) MU.vol = mv; } catch (e) { /* A private window may refuse; the defaults stand. */ }
function musicSave() { try { localStorage.setItem('realmMusic',MU.on?'1':'0'); localStorage.setItem('realmMusicVol',String(MU.vol)); } catch (e) { /* Then it is simply not remembered. */ } }
/* LOFI (David, 2026-11-12, after hearing the first version, a slow beatless chord: "I don't like the
   current music, can we try something more lofi?"). A four-bar loop at 72 beats a minute: mellow
   jazz chords and a soft bass, a soft kick, snare and hat with a little swing, a stray high note
   now and then, the whole thing dulled and made to waver slightly in pitch like an old tape. Every
   sound is still built here from plain waves and noise.

   NO RECORD CRACKLE (David, 2026-11-12: "there's a bit of noise in the lo-fi music, it sounds like
   if the speaker was broken"). The first lofi version played "record crackle" underneath: about
   thirty sharp clicks a second, nearly as loud as the loudest note, and sent round the dull filter
   so nothing softened them. Measured, it was the only thing in the mix making sudden jumps, and
   that is exactly what a torn speaker does. It is gone, not turned down: a sound that reads as a
   fault has no place under somebody's work. The kick drum was also more than twice as loud as the
   chords, a low thump a laptop speaker rattles on, so it is now quieter and does not dip as low.

   WHAT KEEPS IT CLEAN, in the order the sound travels (musicStart): the dull filter; a filter that
   removes the rumble below what small speakers can play; a limiter, which is only a safety net and
   normally does nothing; then the volume. With the volume last, no setting of the slider can push
   the sound past full scale: at the top it peaks at about half of it. Every note and drum hit
   swells from silence and ends at silence (musicTone, musicHiss), because a sound that starts or
   stops part way up is heard as a click.

   Notes are booked on the sound engine's own clock a couple of seconds ahead (musicPlan), because a
   browser slows the timers of a tab that is not in front, and a beat driven straight by a timer
   would stumble whenever the Realm was behind another window. */
var BPM = 72, BEAT = 60/BPM, MUSIC_TOP = .8;
/* E minor 9, A 13, D major 9, B minor 11: bass note, then the four notes of the chord. */
var CHORDS = [[82.41,196,246.94,293.66,369.99],[110,196,246.94,277.18,369.99],[73.42,185,220,277.18,329.63],[123.47,185,220,293.66,329.63]];
var BELLS = [587.33,659.25,739.99,880,987.77];
/* The slider, squared, because ears hear loudness in ratios: half way along sounds about half as loud. */
function musicLevel() { return MU.vol*MU.vol*MUSIC_TOP; }
/* Called inside a click, and nowhere else. */
function musicWake(t) {
  var AC = window.AudioContext || window.webkitAudioContext, n = focusNow();
  if (!AC || !MU.on || !((n && n.phase==='focus') || t.closest('[data-focusstart]'))) return;
  try { if (!MU.ctx) MU.ctx = new AC(); if (MU.ctx.state==='suspended') MU.ctx.resume(); } catch (e) { MU.ctx = null; }
}
/* One note: a wave that swells in `attack` seconds and dies away over `dur`. `wow` is the tape wobble. */
function musicTone(out, type, f, at, dur, loud, attack, wow) {
  var ctx = MU.ctx, o = ctx.createOscillator(), v = ctx.createGain();
  o.type = type; o.frequency.value = f; if (wow) wow.connect(o.detune);
  /* The fade is a curve that can never reach zero, so it is aimed at next to nothing and the last step to silence is a straight one. */
  v.gain.setValueAtTime(0,at); v.gain.linearRampToValueAtTime(loud,at+attack); v.gain.exponentialRampToValueAtTime(.0001,at+dur); v.gain.linearRampToValueAtTime(0,at+dur+.03);
  o.connect(v); v.connect(out); o.start(at); o.stop(at+dur+.05); o.onended = function () { v.disconnect(); };
  return o;
}
function musicBell(out, f, loud) { musicTone(out,'sine',f,MU.ctx.currentTime,3.6,loud,.04,null); }
/* A drum made of hiss: a snare (a band of it) or a hat (only the top of it). */
function musicHiss(rig, at, dur, loud, kind, freq) {
  var ctx = MU.ctx, src = ctx.createBufferSource(), fl = ctx.createBiquadFilter(), v = ctx.createGain();
  src.buffer = rig.noise; fl.type = kind; fl.frequency.value = freq;
  v.gain.setValueAtTime(0,at); v.gain.linearRampToValueAtTime(loud,at+.004); v.gain.exponentialRampToValueAtTime(.0001,at+dur); v.gain.linearRampToValueAtTime(0,at+dur+.02);
  src.connect(fl); fl.connect(v); v.connect(rig.bus); src.start(at,Math.random()*.8,dur+.05); src.onended = function () { v.disconnect(); };
}
/* One half-beat of the loop: n counts 0 to 31 across the four bars. */
function musicStep(rig, n, t) {
  var bar = n>>3, s = n&7, ch = CHORDS[bar], at = t+(s&1 ? BEAT*.1 : 0), i;
  if (s===0 || s===5) for (i = 1; i < 5; i++) musicTone(rig.bus,'triangle',ch[i],at+i*.03,s?BEAT*1.3:BEAT*3.6,s?.035:.07,.02,rig.wow);
  if (s===0 || s===5) musicTone(rig.bus,'triangle',ch[0],at,s?BEAT*.9:BEAT*1.5,s?.13:.18,.02,rig.wow);
  if (s===0 || s===5 || (s===3 && (bar&1))) { var k = musicTone(rig.bus,'sine',110,at,.28,.24,.008,null); k.frequency.setValueAtTime(110,at); k.frequency.exponentialRampToValueAtTime(52,at+.1); }
  if (s===2 || s===6) musicHiss(rig,at,.14,.075,'bandpass',1300);
  musicHiss(rig,at,.04,s&1?.02:.035,'highpass',6500);
  if (s && Math.random() < .1) musicTone(rig.bus,'sine',BELLS[Math.floor(Math.random()*BELLS.length)],at,BEAT*1.6,.05,.01,rig.wow);
}
function musicPlan(rig) {
  var ctx = MU.ctx;
  /* A tab that slept long enough to fall behind picks the beat up from now, not in a rush of late notes. */
  if (rig.next < ctx.currentTime) rig.next = ctx.currentTime+.05;
  while (rig.next < ctx.currentTime+2.4) { musicStep(rig,rig.step,rig.next); rig.step = (rig.step+1)%32; rig.next += BEAT/2; }
}
function musicStart() {
  var ctx = MU.ctx; if (!ctx || MU.rig || ctx.state!=='running') return;
  var t = ctx.currentTime, rig = {live:[],step:0,next:t+.15,plan:0}, i;
  rig.master = ctx.createGain(); rig.master.gain.setValueAtTime(0,t); rig.master.gain.setTargetAtTime(musicLevel(),t,1.2); rig.master.connect(ctx.destination);
  /* The safety net: it leaves the music alone and only holds back a moment when several notes land
     together. It sits BEFORE the volume, so what the volume receives is already bounded. */
  var lim = ctx.createDynamicsCompressor(); lim.threshold.value = -8; lim.knee.value = 4; lim.ratio.value = 12; lim.attack.value = .004; lim.release.value = .2; lim.connect(rig.master);
  /* Below about 38 Hz there is nothing to hear, only work for a small speaker. */
  var hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 38; hp.Q.value = .7; hp.connect(lim);
  /* Everything goes through one dull filter: that, more than anything, is the "lo" in lofi. */
  rig.bus = ctx.createBiquadFilter(); rig.bus.type = 'lowpass'; rig.bus.frequency.value = 2400; rig.bus.Q.value = .7; rig.bus.connect(hp);
  var lfo = ctx.createOscillator(); rig.wow = ctx.createGain(); lfo.frequency.value = .45; rig.wow.gain.value = 9; lfo.connect(rig.wow); lfo.start(); rig.live.push(lfo);
  rig.noise = ctx.createBuffer(1,ctx.sampleRate,ctx.sampleRate); var d = rig.noise.getChannelData(0); for (i = 0; i < d.length; i++) d[i] = Math.random()*2-1;
  musicPlan(rig); rig.plan = setInterval(function () { musicPlan(rig); },400);
  MU.rig = rig;
}
function musicStop() {
  var rig = MU.rig, ctx = MU.ctx; if (!rig) return; MU.rig = null;
  clearInterval(rig.plan);
  var t = ctx.currentTime; rig.master.gain.cancelScheduledValues(t); rig.master.gain.setTargetAtTime(0,t,.35);
  /* Notes already booked play out unheard behind the faded master, which is then unplugged. */
  setTimeout(function () { rig.live.forEach(function (o) { try { o.stop(); } catch (e) { /* already stopped */ } }); rig.master.disconnect(); },2800);
}
/* The block is over: two bell notes, falling. Only where the music has been playing. */
function musicChime() {
  var ctx = MU.ctx; if (!MU.on || !ctx || ctx.state!=='running') return;
  var out = ctx.createGain(); out.gain.value = musicLevel(); out.connect(ctx.destination);
  musicBell(out,880,.16); setTimeout(function () { musicBell(out,587.33,.16); },450); setTimeout(function () { out.disconnect(); },5200);
}
function musicSync() {
  var n = focusNow(), want = MU.on && !!n && n.phase==='focus' && focusLeft() > 0;
  if (want && !MU.rig) musicStart(); else if (!want && MU.rig) musicStop();
}
if ($('focusBody')) {
  $('focusBody').innerHTML = '<div id="focusNow"></div><div id="focusList"></div>'
    +'<div class="rmusic" id="focusMusic" hidden><label><input type="checkbox" id="musicOn"> Music while you focus</label><label>Volume <input type="range" id="musicVol" min="0" max="100" step="1"></label><small>A soft sound made by this page. It plays only during a focus block.</small></div>'
    +'<div class="rdacts"><a class="rbtn" href="mytasks.php">Open My tasks</a><button type="button" class="rbtn" data-focusclose="1">Close</button></div>';
  $('musicOn').checked = MU.on; $('musicVol').value = Math.round(MU.vol*100);
  $('focusBody').addEventListener('change',function (e) { if (e.target.getAttribute && e.target.getAttribute('data-focusstatus')) focusStatus(e.target); });
  $('musicOn').addEventListener('change',function () { MU.on = this.checked; musicSave(); musicWake(this); musicSync(); });
  $('musicVol').addEventListener('input',function () { MU.vol = this.value/100; musicSave(); if (MU.rig) MU.rig.master.gain.setTargetAtTime(musicLevel(),MU.ctx.currentTime,.1); });
}

/* ---------- voice (migration 085) ----------
   David, 2026-11-12: "let's start working on the voice chats". The people INSIDE the same room can
   talk, up to five ("I think is fine 3-5 people talking informally"), in every room. It is face to
   face ("the avatars should be close or in that person's office"): leaving the room leaves the
   voice chat, and so does a door closing on a visitor.

   NOBODY IS HEARD BY SURPRISE. Walking in never opens the microphone. Each person presses Join
   call (the button read "Join voice" until David asked for "Join call" on 2026-11-13; the code
   keeps the word voice); the browser then asks for the microphone, once per browser.

   THE SOUND GOES STRAIGHT FROM BROWSER TO BROWSER (WebRTC), one direct line to each other person in
   voice, which is fine for five. It never passes through Anima. To find each other across different
   networks each browser asks an address-lookup service (STUN) what its own internet address is:
   Google's free one, agreed by David that day, named by the server in ST.voice.ice. There is no
   relay, so two people on strict networks may fail to connect; that is tried twice and then said.

   THE HOSTING CANNOT HOLD A CONNECTION OPEN, so the set-up notes two browsers exchange (an offer,
   an answer, network addresses) go through realm_api.php: while I am in voice, and only then, this
   page asks once a second (voiceBeat). That one request says I am still here, hands over my notes,
   and brings back the notes for me and the list of who is in voice in the room.

   WHO CALLS WHOM. For any two people the one with the SMALLER user id makes the offer and the other
   waits for it, so two browsers never call each other at the same moment. Every Join gets a fresh
   number from the server (`sid`); when somebody's number changes they have rejoined, and the line
   to them is rebuilt. Every line has its own tag (`cid`), so a late note for an earlier attempt is
   dropped instead of confusing the current one.

   VO.peers is one entry per other person: {pc the line, ok whether sound can flow, el the hidden
   player their voice comes out of, an what measures their loudness for the speaking mark}. */
var VO = {on:false,joining:false,sid:0,place:null,stream:null,ctx:null,mine:null,peers:{},out:[],busy:0,timer:0,meter:0,flush:0,
  muted:false,talk:{},talkSig:'',tries:{},dead:{},seen:{},misses:0,cid:0,asked:0,blocked:false};
function voiceReady() { return !!(ST.voice && ST.voice.ready); }
function voiceCan() { return !!(window.RTCPeerConnection && navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }
function voicePost(fields) {
  var body = new URLSearchParams(); Object.keys(fields).forEach(function (k) { body.append(k,fields[k]); });
  return fetch(R.api,{method:'POST',body:body,credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function (r) { return r.json(); });
}
/* Is this person in voice, as far as this page knows? Myself I know for certain. */
function inVoice(p) { return p.me || p.id===ST.me ? VO.on : !!p.voice; }
function isMuted(p) { return p.me || p.id===ST.me ? VO.muted : !!p.muted; }
function isTalking(id) { return (VO.talk[id]||0) > Date.now(); }
/* The others in voice in the room `id`. */
function voiceOthers(id) { return ST.people.filter(function (p) { return !p.me && p.voice && p.inside && p.place===id && p.state!=='away'; }); }
/* Fresh word on who is in voice in the room I am in: every second from my own voice request, or
   every few seconds with the unread counts and chat. The map's own copy is up to 25 seconds old. */
function takeVoiceRoom(list) {
  if (!list || !S.inside) return;
  var idx = {}, changed = false, sharer = 0;
  list.forEach(function (r) { idx[r.id] = r; if (r.share) sharer = r.id; });
  /* Who holds the screen (migration 088). A new sharer's screen is shown even to somebody who
     had pressed "Back to the room" on the one before. */
  if (sharer!==SH.who) { SH.who = sharer; SH.hide = false; changed = true; }
  ST.people.forEach(function (p) {
    if (p.me) return;
    var r = idx[p.id];
    /* Somebody the map still has elsewhere has just walked in: the list is the newer word. */
    if (r && (p.place!==S.inside || !p.inside)) { p.place = S.inside; p.inside = true; if (p.state==='away') p.state = 'online'; changed = true; }
    if (p.place!==S.inside) return;
    var v = !!r, m = !!(r && r.muted);
    if (!!p.voice!==v || !!p.muted!==m) { p.voice = v; p.muted = m; changed = true; }
  });
  if (changed) { renderInside(); renderSide(); draw(); }
}
function micWords(e) {
  var n = e && e.name;
  if (n==='NotAllowedError' || n==='SecurityError') return 'The microphone is blocked for this site. Click the padlock beside the address, allow the microphone, then press Join call again.';
  if (n==='NotFoundError' || n==='OverconstrainedError') return 'No microphone was found. Plug one in, then press Join call again.';
  return 'The microphone could not be opened. Close any other app that is using it, then press Join call again.';
}
function voiceJoin() {
  if (VO.on || VO.joining || !S.inside) return;
  if (!voiceReady()) { toast('Calls need a database update first. An administrator opens migrate.php once.'); return; }
  if (!voiceCan()) { toast('This browser cannot join a call. Use a current Chrome, Edge, Firefox or Safari.'); return; }
  var place = S.inside, mine = ++VO.asked; VO.joining = true; renderInside();
  var fail = function (msg) { VO.joining = false; voiceQuiet(); if (msg) toast(msg); renderInside(); };
  navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false}).then(function (stream) {
    /* This answer is for an earlier press (they walked out and pressed again): let it go. */
    if (mine!==VO.asked || VO.on) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
    VO.stream = stream;
    /* They walked out while the browser was asking about the microphone. */
    if (S.inside!==place) { fail(''); return; }
    voicePost({voice:'join',place:place}).then(function (j) {
      var v = j && j.voice;
      if (mine!==VO.asked) { if (v && v.in) voicePost({voice:'leave',sid:v.sid}).catch(function () {}); return; }
      if (!j || !j.ok || !v || !v.in) { fail((j && j.error) || 'The call could not be reached. Try again in a moment.'); return; }
      if (S.inside!==place) { voicePost({voice:'leave',sid:v.sid}).catch(function () {}); fail(''); return; }
      VO.joining = false; VO.on = true; VO.sid = v.sid; VO.place = place; VO.muted = false; VO.misses = 0; VO.busy = 0; VO.out = [];
      VO.tries = {}; VO.dead = {}; VO.seen = {}; VO.talk = {};
      voiceEars();
      VO.timer = setInterval(voiceBeat,1000); VO.meter = setInterval(voiceMeter,150);
      voiceTake(j); renderInside(); renderSide(); draw();
    }).catch(function () { if (mine===VO.asked) fail('The call could not be reached. Try again in a moment.'); });
  },function (e) { if (mine===VO.asked) fail(micWords(e)); });
}
/* Let go of everything on this computer: the lines, the microphone, the timers. Tells nobody. */
function voiceQuiet() {
  /* A shared screen belongs to the call: leaving the call, by any road, ends it. */
  shareQuiet();
  clearInterval(VO.timer); clearInterval(VO.meter); clearTimeout(VO.flush); VO.timer = VO.meter = VO.flush = 0;
  Object.keys(VO.peers).forEach(function (k) { voiceDrop(+k); });
  if (VO.stream) VO.stream.getTracks().forEach(function (t) { t.stop(); });
  if (VO.ctx) { try { VO.ctx.close(); } catch (e) { /* already closed */ } }
  VO.on = false; VO.sid = 0; VO.place = null; VO.stream = null; VO.ctx = null; VO.mine = null; VO.out = []; VO.busy = 0; VO.talk = {}; VO.talkSig = ''; VO.muted = false;
}
/* Leave voice. `tell` is false when the server is the one that said so. */
function voiceStop(tell) {
  if (!VO.on && !VO.joining) return;
  if (VO.on && tell) voicePost({voice:'leave',sid:VO.sid}).catch(function () { /* the server notices within 15 seconds anyway */ });
  VO.joining = false; VO.asked++; voiceQuiet(); renderInside(); renderSide(); draw();
}
/* Queue a note for somebody, and send it within a fifth of a second instead of at the next beat:
   that is what makes a line come up in about two seconds, not five. */
function voiceOut(to, kind, body) {
  VO.out.push({to:to,kind:kind,body:JSON.stringify(body)});
  if (!VO.flush) VO.flush = setTimeout(function () { VO.flush = 0; voiceBeat(); },200);
}
/* The once-a-second request. It keeps running while the window is hidden: a call goes on when
   somebody switches to another tab, and browsers do not slow a page that holds a live call. */
function voiceBeat() {
  if (!VO.on) return;
  /* One at a time; but a request that never comes back must not stop the beat for good. */
  if (VO.busy && Date.now()-VO.busy < 8000) return;
  var out = VO.out, sid = VO.sid, sh = SH.want; VO.out = []; VO.busy = Date.now();
  var missed = function () { VO.busy = 0; VO.out = out.concat(VO.out).slice(-40);
    if (++VO.misses >= 20) { voiceStop(false); toast('The call lost touch with Anima, so you left it. Check your connection, then press Join call again.'); } };
  voicePost({voice:'poll',sid:sid,muted:VO.muted?1:0,share:sh?1:0,out:JSON.stringify(out)}).then(function (j) {
    if (!VO.on || VO.sid!==sid) return;
    if (!j || !j.ok || !j.voice) { missed(); return; }
    VO.busy = 0; VO.misses = 0; voiceTake(j,sh); if (VO.on) takeSpots(j.spots);
    if (VO.on && VO.out.length) voiceBeat();
  }).catch(function () { if (VO.on && VO.sid===sid) missed(); });
}
/* What the server answered: am I still in, who else is, and the notes for me. The list is read
   BEFORE the notes, so a line to somebody who rejoined is taken down before their new offer lands.
   `asked` is true when the request this answers said "I am sharing my screen" (shareTake). */
function voiceTake(j, asked) {
  var v = j.voice;
  if (!v.in) { voiceStop(false); if (v.why) toast(v.why); return; }
  var idx = {};
  (v.people||[]).forEach(function (r) { idx[r.id] = r; });
  Object.keys(VO.peers).forEach(function (k) { if (!idx[k]) voiceDrop(+k); });
  Object.keys(VO.seen).forEach(function (k) { if (!idx[k]) { delete VO.seen[k]; delete VO.tries[k]; delete VO.dead[k]; } });
  (v.people||[]).forEach(function (r) {
    if (r.id===ST.me) return;
    var P = VO.peers[r.id];
    if (P && P.sid!==r.sid) { voiceDrop(r.id); P = null; }
    if (VO.seen[r.id]!==r.sid) { VO.seen[r.id] = r.sid; VO.tries[r.id] = 0; delete VO.dead[r.id]; }
    if (!P && ST.me < r.id && !VO.dead[r.id]) voiceCall(r);
  });
  takeVoiceRoom(v.people||[]);
  shareTake(v.people||[],!!asked);
  (v.signals||[]).forEach(voiceSignal);
}
/* A line to one person. Whether sound can flow is watched here: a line that fails, or that drops
   and does not come back in six seconds, or that never comes up in twenty, is taken down. */
function voicePeer(id, sid, cid) {
  var pc = new RTCPeerConnection({iceServers:(ST.voice && ST.voice.ice)||[]});
  var P = {id:id,sid:sid,cid:cid,pc:pc,pend:[],set:false,ok:false,el:null,src:null,an:null,buf:null,wait:0};
  VO.peers[id] = P;
  VO.stream.getTracks().forEach(function (t) { pc.addTrack(t,VO.stream); });
  pc.onicecandidate = function (e) { if (e.candidate && VO.peers[id]===P) voiceOut(id,'ice',{c:cid,d:e.candidate}); };
  pc.ontrack = function (e) { if (VO.peers[id]===P) voiceHear(P,(e.streams && e.streams[0]) || new MediaStream([e.track])); };
  var watch = function () {
    if (VO.peers[id]!==P) return;
    var s = pc.connectionState || pc.iceConnectionState;
    if (s==='connected' || s==='completed') { clearTimeout(P.wait); P.wait = 0; if (!P.ok) { P.ok = true; VO.tries[id] = 0; renderInside(); } }
    else if (s==='failed') voiceFail(P);
    else if (s==='disconnected' && P.ok) { P.ok = false; renderInside(); clearTimeout(P.wait); P.wait = setTimeout(function () { voiceFail(P); },6000); }
  };
  pc.onconnectionstatechange = watch; pc.oniceconnectionstatechange = watch;
  P.wait = setTimeout(function () { voiceFail(P); },20000);
  renderInside();
  return P;
}
/* I have the smaller id, so I make the offer. */
function voiceCall(r) {
  var P = voicePeer(r.id,r.sid,ST.me+'.'+(++VO.cid)+'.'+Date.now());
  P.pc.createOffer().then(function (o) { return P.pc.setLocalDescription(o); })
    .then(function () { if (VO.peers[r.id]===P) voiceOut(r.id,'offer',{c:P.cid,d:P.pc.localDescription.sdp}); })
    .catch(function () { voiceFail(P); });
}
function voicePend(P) { P.pend.forEach(function (d) { P.pc.addIceCandidate(d).catch(function () { /* one address fewer */ }); }); P.pend = []; }
/* A note from somebody in the room. The server has already checked they are in voice here. */
function voiceSignal(s) {
  var b; try { b = JSON.parse(s.body); } catch (e) { return; }
  if (!b || !b.c || !VO.on) return;
  /* soffer, sanswer, sice: the notes for a shared screen's own line. */
  if (String(s.kind).charAt(0)==='s') { shareSignal(s,b); return; }
  var P = VO.peers[s.from];
  if (s.kind==='offer') {
    /* Offers only ever come from the smaller id (see WHO CALLS WHOM); anything else is ignored. */
    if (s.from > ST.me || typeof b.d!=='string') return;
    if (P) voiceDrop(s.from);
    delete VO.dead[s.from];
    P = voicePeer(s.from,s.sid,b.c);
    P.pc.setRemoteDescription({type:'offer',sdp:b.d}).then(function () { P.set = true; voicePend(P); return P.pc.createAnswer(); })
      .then(function (a) { return P.pc.setLocalDescription(a); })
      .then(function () { if (VO.peers[s.from]===P) voiceOut(s.from,'answer',{c:P.cid,d:P.pc.localDescription.sdp}); })
      .catch(function () { voiceFail(P); });
    return;
  }
  if (!P || P.cid!==b.c || P.sid!==s.sid) return;
  if (s.kind==='answer') { if (P.set || typeof b.d!=='string') return; P.pc.setRemoteDescription({type:'answer',sdp:b.d}).then(function () { P.set = true; voicePend(P); }).catch(function () { voiceFail(P); }); }
  else if (s.kind==='ice' && b.d) { if (P.set) P.pc.addIceCandidate(b.d).catch(function () { /* one address fewer */ }); else P.pend.push(b.d); }
}
/* Their voice arrived. It plays through a hidden player (a browser stays silent otherwise), and a
   meter listens to it for the speaking mark. */
function voiceHear(P, stream) {
  if (!P.el) { P.el = document.createElement('audio'); P.el.autoplay = true; }
  P.el.srcObject = stream;
  var pr = P.el.play();
  if (pr && pr.catch) pr.catch(function () { if (!VO.blocked) { VO.blocked = true; toast('Click anywhere on the page to hear the others.'); } });
  if (VO.ctx && !P.an) { try { P.src = VO.ctx.createMediaStreamSource(stream); P.an = VO.ctx.createAnalyser(); P.an.fftSize = 512; P.src.connect(P.an); } catch (e) { P.an = null; } }
}
function voiceDrop(id) {
  var P = VO.peers[id]; if (!P) return;
  delete VO.peers[id]; delete VO.talk[id]; clearTimeout(P.wait);
  P.pc.onicecandidate = P.pc.ontrack = P.pc.onconnectionstatechange = P.pc.oniceconnectionstatechange = null;
  try { P.pc.close(); } catch (e) { /* already closed */ }
  if (P.el) { try { P.el.pause(); } catch (e) { /* nothing was playing */ } P.el.srcObject = null; }
  if (P.src) { try { P.src.disconnect(); } catch (e) { /* the sound engine is already shut */ } }
}
/* A line could not be made or was lost. The first time it is simply made again (by whoever has the
   smaller id, at the next beat); the second time the screen says so and stops trying, until one of
   the two leaves voice and joins again. */
function voiceFail(P) {
  if (VO.peers[P.id]!==P) return;
  var id = P.id, who = person(id), n = VO.tries[id] = (VO.tries[id]||0)+1;
  voiceDrop(id);
  if (n >= 2 && !VO.dead[id]) { VO.dead[id] = true;
    toast('Your call could not connect with '+(who?firstName(who.name):'somebody')+'. One of you is probably on a network that blocks direct calls. Try another network, or use a video call.'); }
  renderInside();
}
/* The meters: my own microphone and each voice that arrives, for the speaking mark only. Nothing is
   recorded and nothing is sent anywhere. */
function voiceEars() {
  var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
  try { VO.ctx = new AC(); if (VO.ctx.state==='suspended') VO.ctx.resume();
    var src = VO.ctx.createMediaStreamSource(VO.stream), an = VO.ctx.createAnalyser(); an.fftSize = 512; src.connect(an); VO.mine = {src:src,an:an,buf:null};
  } catch (e) { VO.ctx = null; VO.mine = null; }
}
function voiceLoud(m) {
  if (!m || !m.an) return 0;
  if (!m.buf) m.buf = new Uint8Array(m.an.fftSize);
  m.an.getByteTimeDomainData(m.buf);
  for (var i = 0, t = 0; i < m.buf.length; i++) { var d = (m.buf[i]-128)/128; t += d*d; }
  return Math.sqrt(t/m.buf.length);
}
function voiceMeter() {
  if (!VO.on) return;
  var now = Date.now(), sig = '';
  if (!VO.muted && voiceLoud(VO.mine) > .02) VO.talk[ST.me] = now+450;
  Object.keys(VO.peers).forEach(function (k) { if (voiceLoud(VO.peers[k]) > .02) VO.talk[k] = now+450; });
  Object.keys(VO.talk).forEach(function (k) { if (VO.talk[k] > now) sig += k+','; });
  if (sig!==VO.talkSig) { VO.talkSig = sig; draw(); }
  if (sig && !calm) fxWake();
}
function voiceMute() {
  if (!VO.on) return;
  VO.muted = !VO.muted;
  VO.stream.getAudioTracks().forEach(function (t) { t.enabled = !VO.muted; });
  if (VO.muted) delete VO.talk[ST.me];
  renderInside(); draw();
}
/* A browser that refused to play sound until a click: this is the click. */
function voiceWake() {
  if (!VO.on) return;
  if (VO.ctx && VO.ctx.state==='suspended') { try { VO.ctx.resume(); } catch (e) { /* the marks stay still, the voices still play */ } }
  if (!VO.blocked) return; VO.blocked = false;
  Object.keys(VO.peers).forEach(function (k) { var el = VO.peers[k].el; if (el) { var pr = el.play(); if (pr && pr.catch) pr.catch(function () { VO.blocked = true; }); } });
}
/* The voice part of the room's bar: Join call, or Mute and Leave call, and who is talking. */
function voiceBar(o) {
  if (!ST.voice) return '';
  if (!ST.voice.ready) return '<span class="rvoice"><button type="button" class="rbtn rsmall" disabled>Join call</button><small>Calls need a database update first.</small></span>';
  var others = voiceOthers(o.id), cap = ST.voice.cap||5, h = '<span class="rvoice">';
  if (VO.on) {
    var names = others.map(function (p) { var P = VO.peers[p.id]; return firstName(p.name)+(VO.dead[p.id]?' (could not connect)':(P && P.ok)?'':' (connecting…)'); });
    /* The screen (migration 088): mine, somebody else's, or the button to share. One at a time,
       so the button is not offered while somebody holds it. */
    var sw = shareWho(), sb = '';
    if (SH.want) sb = '<button type="button" class="rbtn rsmall" data-share="stop">Stop sharing</button>';
    else if (sw) sb = SH.hide ? '<button type="button" class="rbtn rsmall" data-share="show">Show screen</button>' : '';
    else if (shareReady() && shareCan()) sb = '<button type="button" class="rbtn rsmall" data-share="start"'+(SH.asking?' disabled':'')+'>Share screen</button>';
    h += '<i class="rlive'+(VO.muted?' is-muted':'')+'"></i><small>'+esc(others.length ? 'In a call with '+names.join(', ')+'.' : 'In a call. Nobody else has joined yet.')+(VO.muted?' You are muted.':'')
      +esc(SH.want ? ' You are sharing your screen.' : sw ? ' '+shareName(sw)+' is sharing a screen.' : '')+'</small>'
      +'<button type="button" class="rbtn rsmall" data-voice="mute" aria-pressed="'+VO.muted+'">'+(VO.muted?'Unmute':'Mute')+'</button>'+sb
      +'<button type="button" class="rbtn rsmall" data-voice="leave">Leave call</button>';
  } else {
    var full = others.length >= cap, who = others.map(function (p) { return firstName(p.name); });
    /* Somebody in the room who has not joined is told a screen is up, and how to see it. */
    var sees = others.filter(function (p) { return p.id===SH.who; })[0];
    h += (others.length ? '<small>'+esc((full ? 'The call is full, '+cap+' people are talking.' : who.join(', ')+(who.length>1?' are':' is')+' in a call.')
      +(sees ? ' '+firstName(sees.name)+' is sharing a screen'+(full?'.':', join the call to see it.') : ''))+'</small>' : '')
      +'<button type="button" class="rbtn rsmall" data-voice="join"'+((VO.joining||full)?' disabled':'')+'>'+(VO.joining?'Joining…':'Join call')+'</button>';
  }
  return h+'</span>';
}
/* The mark on a character who is in voice, in the room's own units: a small microphone beside the
   head, struck through in red while muted, and green with a ring at the feet while they speak. */
function voiceMark(c, x, y, talking, muted, f) {
  var bx = x-20, by = y-46, col = muted ? PAL.smoke : talking ? TINT.spell : PAL.hot, i;
  /* SPEAKING IS ALIVE (David, 2026-11-13: an animation for when you "talk with voice or in voice
     chat"): two rings leave the microphone and fade, and the ring at the feet breathes. While
     anybody speaks the picture is repainted by the clock (fxTick), so this is smooth. */
  if (talking) { var t = calm ? .5 : (Date.now()%900)/900, br = calm ? 0 : Math.round(Math.sin(t*6.28)*2), j;
    c.fillStyle = TINT.spell; c.fillRect(x-12-br,y+1,24+br*2,2); c.fillRect(x-12-br,y-1,2,2); c.fillRect(x+10+br,y-1,2,2);
    for (j = 0; j < 2; j++) { var p = (t+j/2)%1, r = 3+p*6;
      c.globalAlpha = Math.max(0,1-p); c.fillStyle = TINT.spell;
      c.fillRect(bx-Math.round(r),by+4-Math.round(r*.8),1,Math.round(r*1.6)+1);
      c.fillRect(bx-Math.round(r)+1,by+4-Math.round(r*.8)-1,1,1); c.fillRect(bx-Math.round(r)+1,by+5+Math.round(r*.8),1,1); }
    c.globalAlpha = 1; }
  c.fillStyle = PAL.out; c.fillRect(bx,by,9,9); c.fillStyle = PAL.veil; c.fillRect(bx+1,by+1,7,7);
  c.fillStyle = col; c.fillRect(bx+3,by+1,3,4); c.fillRect(bx+2,by+4,1,2); c.fillRect(bx+6,by+4,1,2); c.fillRect(bx+2,by+6,5,1); c.fillRect(bx+4,by+7,1,1);
  if (muted) { c.fillStyle = TINT.ember; for (i = 0; i < 7; i++) c.fillRect(bx+1+i,by+7-i,1,1); }
}
/* ---------- sharing a screen in a call (migration 088) ----------
   David, 2026-11-13: "let's discuss the option to share your screen while in a voice chat". What he
   settled that day, each answered in the conversation:
     * IT IS FOR LOOKING AT A STILL THING TOGETHER, 2 to 5 people: a board, a paused frame, a
       timeline, a file. Footage that has to play goes to a Reviews link, a big meeting to Meet.
     * ONE SHARER AT A TIME ("One at a time"). The server keeps it to one (realm_voice_poll).
     * A LARGE PANEL OVER THE ROOM, with "Back to the room" and the call's bar still showing.
     * ONLY THE PEOPLE IN THE CALL SEE IT, which by the face to face rule means people in the room.
       Somebody who joins the call later sees it at once. It ends when the sharer presses Stop,
       leaves the call, leaves the room or closes the page.
     * WHAT IS SHARED IS THE SHARER'S CHOICE ("They can share the whole window if they want"): the
       browser's own picker asks every time, a tab, a window or the whole screen. A page cannot
       answer that picker or skip it.
     * A MARKER THE SHARER CANNOT MISS ("yes to the marker"): the red band across the top.
     * NO SOUND FROM THE SCREEN, and nothing is recorded.

   THE PICTURE GOES STRAIGHT FROM THE SHARER'S BROWSER TO EACH VIEWER'S, like the sound, and never
   through Anima. ⚠ IT TRAVELS ON ITS OWN LINES, NOT ON THE SOUND'S. For each viewer the sharer
   opens a second, one-way line that carries only the picture, set up with its own kinds of note
   (soffer, sanswer, sice) through the same once-a-second request. Two reasons: a screen that
   cannot get through must never cost anybody the sound, and the sound's lines are made once and
   never changed (WHO CALLS WHOM above), while here the SHARER always makes the offer.

   THE COST IS THE SHARER'S UPLOAD: one copy per viewer, four in a full call. So the picture is
   kept sharp and slow, right for reading text: at most 1280 by 720, 5 pictures a second, about
   1 megabit per viewer (shareCap). Moving footage will look poor; that is the trade agreed.

   SH.want: I have picked a screen and am asking for the room's one place. SH.on: the server gave
   it to me. SH.peers: my line to each viewer. SH.who: who holds the screen, from the room's list
   (takeVoiceRoom). SH.view: the line somebody else's screen reaches me on. SH.hide: I pressed
   "Back to the room". Phones cannot share a screen (the browser has no picker there), so they get
   no button, but they can watch. */
var SH = {asking:false,want:false,on:false,stream:null,peers:{},tries:{},dead:{},seen:{},who:0,view:null,hide:false,cid:0,box:null,band:null};
function shareReady() { return !!(ST.voice && ST.voice.share); }
function shareCan() { return !!(window.RTCPeerConnection && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia); }
/* Who holds the screen in the room I am in, or 0. Myself I know for certain. */
function shareWho() { return SH.on ? ST.me : (SH.who && SH.who!==ST.me ? SH.who : 0); }
function shareName(id) { var p = person(id); return p ? firstName(p.name) : 'Somebody'; }
function shareStart() {
  if (!VO.on || SH.want || SH.asking) return;
  if (shareWho()) { toast(shareName(shareWho())+' is sharing a screen. One person shares at a time.'); return; }
  var sid = VO.sid; SH.asking = true; renderInside();
  /* The browser's own picker opens here, and only because this runs inside a click. */
  navigator.mediaDevices.getDisplayMedia({video:{width:{max:1280},height:{max:720},frameRate:{max:5}},audio:false}).then(function (stream) {
    SH.asking = false;
    var t = stream.getVideoTracks()[0], late = !VO.on || VO.sid!==sid, taken = !late && shareWho();
    /* They left the call while the picker was open, or somebody else got there first. */
    if (!t || late || taken) { stream.getTracks().forEach(function (x) { x.stop(); }); if (taken) toast(shareName(taken)+' started sharing first, so your screen was not shared.'); renderInside(); return; }
    /* "detail" asks the browser to keep text sharp and give up smoothness first. */
    try { t.contentHint = 'detail'; } catch (e) { /* an older browser: it still shares */ }
    /* The browser's own "Stop sharing" button, or the shared window being closed. */
    t.onended = function () { if (SH.stream===stream) shareStop(); };
    SH.stream = stream; SH.want = true; SH.on = false; SH.tries = {}; SH.dead = {}; SH.seen = {};
    renderInside(); voiceBeat();
  },function (e) {
    SH.asking = false; renderInside();
    var n = e && e.name, m = String((e && e.message)||'');
    /* Closing the picker is not an error. A computer that forbids it is. */
    if (n==='NotAllowedError' && /system/i.test(m)) toast('This computer is not letting the browser show your screen. On a Mac, open System Settings, Privacy and Security, Screen Recording, and allow this browser.');
    else if (n!=='NotAllowedError' && n!=='AbortError') toast('The screen could not be shared. Try again, or pick a different window.');
  });
}
/* Let go of my own share on this computer: the capture and the lines to the viewers. */
function shareMine() {
  var st = SH.stream;
  SH.want = false; SH.on = false; SH.asking = false; SH.stream = null;
  Object.keys(SH.peers).forEach(function (k) { shareDrop(+k); });
  SH.tries = {}; SH.dead = {}; SH.seen = {};
  if (st) st.getTracks().forEach(function (t) { t.onended = null; t.stop(); });
}
/* Everything about screens on this computer, mine or the one I am watching. Tells nobody. */
function shareQuiet() { shareMine(); shareViewDrop(); SH.hide = false; shareShow(); }
/* I stop sharing. The next request, sent at once, tells the server, and the others' panels close. */
function shareStop() {
  if (!SH.want && !SH.asking) return;
  shareMine(); renderInside(); draw(); voiceBeat();
}
/* The room's list has arrived. `asked` is true when the request it answers said I am sharing:
   only then is "the screen is not mine" a refusal, and not just an answer that crossed my click. */
function shareTake(people, asked) {
  var who = 0, idx = {}, was = SH.on;
  people.forEach(function (r) { idx[r.id] = r; if (r.share) who = r.id; });
  if (SH.want) {
    if (who===ST.me) { if (!SH.on) { SH.on = true; renderInside(); draw(); } }
    else if (asked) {
      shareMine();
      toast(was ? 'Your screen is no longer being shared.' : who ? shareName(who)+' started sharing first, so your screen was not shared.' : 'Your screen could not be shared. Try again in a moment.');
      renderInside(); draw();
    }
  }
  if (SH.on) {
    Object.keys(SH.peers).forEach(function (k) { if (!idx[k]) shareDrop(+k); });
    Object.keys(SH.seen).forEach(function (k) { if (!idx[k]) { delete SH.seen[k]; delete SH.tries[k]; delete SH.dead[k]; } });
    people.forEach(function (r) {
      if (r.id===ST.me) return;
      var P = SH.peers[r.id];
      /* They left the call and came back: a fresh line, and a fresh two tries. */
      if (P && P.sid!==r.sid) { shareDrop(r.id); P = null; }
      if (SH.seen[r.id]!==r.sid) { SH.seen[r.id] = r.sid; SH.tries[r.id] = 0; delete SH.dead[r.id]; }
      /* Everybody on the list gets the screen, so a late joiner does too, with no click from me. */
      if (!P && !SH.dead[r.id]) shareCall(r);
    });
  }
  /* The screen I was watching is over, or its owner rejoined and will send a new one. */
  var V = SH.view;
  if (V && (V.id!==who || idx[V.id].sid!==V.sid)) { shareViewDrop(); shareShow(); }
}
/* Sharp and slow. Asked again once the line is up, because some browsers only take it then. */
function shareCap(sender) {
  try {
    var p = sender.getParameters();
    if (!p.encodings || !p.encodings.length) return;
    p.encodings[0].maxBitrate = 1000000; p.encodings[0].maxFramerate = 5; p.degradationPreference = 'maintain-resolution';
    var pr = sender.setParameters(p); if (pr && pr.catch) pr.catch(function () { /* the browser keeps its own limits */ });
  } catch (e) { /* the browser keeps its own limits */ }
}
/* My line to one viewer. I always make the offer, whichever of us has the smaller id. */
function shareCall(r) {
  var id = r.id, cid = 's'+ST.me+'.'+(++SH.cid)+'.'+Date.now();
  var pc = new RTCPeerConnection({iceServers:(ST.voice && ST.voice.ice)||[]});
  var P = {id:id,sid:r.sid,cid:cid,pc:pc,pend:[],set:false,ok:false,wait:0};
  SH.peers[id] = P;
  var sender = pc.addTrack(SH.stream.getVideoTracks()[0],SH.stream);
  pc.onicecandidate = function (e) { if (e.candidate && SH.peers[id]===P) voiceOut(id,'sice',{c:cid,d:e.candidate}); };
  var watch = function () {
    if (SH.peers[id]!==P) return;
    var s = pc.connectionState || pc.iceConnectionState;
    if (s==='connected' || s==='completed') { clearTimeout(P.wait); P.wait = 0; if (!P.ok) { P.ok = true; SH.tries[id] = 0; shareCap(sender); } }
    else if (s==='failed') shareFail(P);
    else if (s==='disconnected' && P.ok) { P.ok = false; clearTimeout(P.wait); P.wait = setTimeout(function () { shareFail(P); },6000); }
  };
  pc.onconnectionstatechange = watch; pc.oniceconnectionstatechange = watch;
  P.wait = setTimeout(function () { shareFail(P); },20000);
  pc.createOffer().then(function (o) { return pc.setLocalDescription(o); })
    .then(function () { if (SH.peers[id]===P) { shareCap(sender); voiceOut(id,'soffer',{c:cid,d:pc.localDescription.sdp}); } })
    .catch(function () { shareFail(P); });
}
function shareDrop(id) {
  var P = SH.peers[id]; if (!P) return;
  delete SH.peers[id]; clearTimeout(P.wait);
  P.pc.onicecandidate = P.pc.onconnectionstatechange = P.pc.oniceconnectionstatechange = null;
  try { P.pc.close(); } catch (e) { /* already closed */ }
}
/* My screen did not reach somebody. Once more at the next beat; the second time I am told, and
   the others go on seeing it. The sound is on other lines and is not touched. */
function shareFail(P) {
  if (SH.peers[P.id]!==P) return;
  var id = P.id, n = SH.tries[id] = (SH.tries[id]||0)+1;
  shareDrop(id);
  if (n >= 2 && !SH.dead[id]) { SH.dead[id] = true; toast('Your screen could not reach '+shareName(id)+'. One of you is probably on a network that blocks direct connections. The others can still see it.'); }
}
function shareViewDrop() {
  var V = SH.view; if (!V) return;
  SH.view = null;
  V.pc.onicecandidate = V.pc.ontrack = V.pc.onconnectionstatechange = V.pc.oniceconnectionstatechange = null;
  try { V.pc.close(); } catch (e) { /* already closed */ }
}
function shareIce(L, d) { if (L.set) L.pc.addIceCandidate(d).catch(function () { /* one address fewer */ }); else L.pend.push(d); }
function sharePend(L) { L.set = true; L.pend.forEach(function (d) { L.pc.addIceCandidate(d).catch(function () { /* one address fewer */ }); }); L.pend = []; }
/* A note about a screen. The server has already checked the sender is in this room's call. */
function shareSignal(s, b) {
  if (s.kind==='soffer') {
    /* ⚠ ONLY FROM THE ONE THE SERVER SAYS HOLDS THE SCREEN. Anybody else's picture is not taken. */
    if (s.from===ST.me || s.from!==SH.who || typeof b.d!=='string') return;
    shareViewDrop();
    var pc = new RTCPeerConnection({iceServers:(ST.voice && ST.voice.ice)||[]});
    var V = {id:s.from,sid:s.sid,cid:b.c,pc:pc,pend:[],set:false,ok:false,bad:false,stream:null};
    SH.view = V;
    pc.onicecandidate = function (e) { if (e.candidate && SH.view===V) voiceOut(V.id,'sice',{c:V.cid,d:e.candidate}); };
    pc.ontrack = function (e) { if (SH.view!==V) return; V.stream = (e.streams && e.streams[0]) || new MediaStream([e.track]); shareShow(); };
    var watch = function () {
      if (SH.view!==V) return;
      var st = pc.connectionState || pc.iceConnectionState;
      if (st==='connected' || st==='completed') { V.ok = true; V.bad = false; }
      else if (st==='failed') { V.ok = false; V.bad = true; }
      else if (st==='disconnected') V.ok = false;
      shareShow();
    };
    pc.onconnectionstatechange = watch; pc.oniceconnectionstatechange = watch;
    pc.setRemoteDescription({type:'offer',sdp:b.d}).then(function () { sharePend(V); return pc.createAnswer(); })
      .then(function (a) { return pc.setLocalDescription(a); })
      .then(function () { if (SH.view===V) voiceOut(V.id,'sanswer',{c:V.cid,d:pc.localDescription.sdp}); })
      .catch(function () { if (SH.view===V) { V.bad = true; shareShow(); } });
    shareShow();
    return;
  }
  /* An answer or an address: for my line to that viewer if I am sharing, else for the line I watch on. */
  var P = SH.peers[s.from], W = SH.view;
  if (s.kind==='sanswer') {
    if (!P || P.cid!==b.c || P.sid!==s.sid || P.set || typeof b.d!=='string') return;
    P.pc.setRemoteDescription({type:'answer',sdp:b.d}).then(function () { sharePend(P); }).catch(function () { shareFail(P); });
  } else if (s.kind==='sice' && b.d) {
    if (P && P.cid===b.c && P.sid===s.sid) shareIce(P,b.d);
    else if (W && W.id===s.from && W.cid===b.c) shareIce(W,b.d);
  }
}
/* The two things on screen: the panel a viewer watches in, laid over the room under its bar, and
   the band across the very top that tells the sharer, all the time, that their screen is out. */
function shareDom() {
  if (SH.box || !$('mapbox')) return;
  var d = document.createElement('div'); d.className = 'rshare'; d.id = 'sharePanel'; d.hidden = true;
  d.innerHTML = '<div class="rsharehead"><b id="shareTitle"></b><small id="shareNote"></small><span class="rgrow"></span>'
    +'<button type="button" class="rbtn rsmall" data-share="full">Full screen</button><button type="button" class="rbtn rsmall" data-share="hide">Back to the room</button></div>'
    +'<video id="shareVideo" autoplay playsinline muted></video>';
  $('mapbox').appendChild(d); SH.box = d;
  var b = document.createElement('div'); b.className = 'rsharing'; b.id = 'shareBand'; b.hidden = true; b.setAttribute('role','status');
  b.innerHTML = '<i></i><b>You are sharing your screen</b><span>The people in this call can see what you picked.</span><span class="rgrow"></span><button type="button" class="rbtn rsmall" data-share="stop">Stop sharing</button>';
  document.body.insertBefore(b,document.body.firstChild); SH.band = b;
}
function shareShow() {
  shareDom(); if (!SH.box) return;
  var who = shareWho(), V = SH.view, vid = $('shareVideo');
  var watching = VO.on && !!S.inside && !!who && who!==ST.me && !SH.hide, want = (watching && V && V.stream) || null;
  /* The band is up from the moment the browser starts capturing, not from when the others see it. */
  SH.band.hidden = !SH.want;
  SH.box.hidden = !watching;
  if ((vid.srcObject||null)!==want) { vid.srcObject = want; if (want) { var pr = vid.play(); if (pr && pr.catch) pr.catch(function () { /* it starts at the next click */ }); } }
  if (!watching) { if (document.fullscreenElement===vid && document.exitFullscreen) { try { document.exitFullscreen(); } catch (e) { /* it had already closed */ } } return; }
  var bar = $('insideBar'); SH.box.style.top = (bar.offsetTop+bar.offsetHeight+8)+'px';
  $('shareTitle').textContent = shareName(who)+'’s screen';
  $('shareNote').textContent = (V && V.ok && V.stream) ? '' : (V && V.bad) ? 'This screen could not reach you. One of you is probably on a network that blocks direct connections.' : 'Connecting…';
}
function shareFull() {
  var v = $('shareVideo'), go = v && (v.requestFullscreen || v.webkitRequestFullscreen);
  if (!go) { toast('This browser cannot show it full screen.'); return; }
  try { var pr = go.call(v); if (pr && pr.catch) pr.catch(function () { /* the browser said no */ }); } catch (e) { /* the browser said no */ }
}
/* The mark on a character who is sharing: a small screen beside the head, on the other side from
   the microphone. */
function shareMark(c, x, y) {
  var bx = x+11, by = y-46;
  c.fillStyle = PAL.out; c.fillRect(bx,by,11,8); c.fillRect(bx+4,by+8,3,1); c.fillRect(bx+2,by+9,7,1);
  c.fillStyle = TINT.mana; c.fillRect(bx+1,by+1,9,6);
  c.fillStyle = PAL.veil; c.fillRect(bx+2,by+5,7,1);
}
/* Closing the page hangs up at once instead of after the server's 15 seconds. */
window.addEventListener('pagehide',function () {
  if (!VO.on) return;
  try { var b = new URLSearchParams(); b.append('voice','leave'); b.append('sid',VO.sid); if (navigator.sendBeacon) navigator.sendBeacon(R.api,b); } catch (e) { /* the server notices by itself */ }
  voiceQuiet();
});

/* ---------- start ---------- */
/* What assets/realm_praise.js borrows: the notices and the one way of asking the server. */
R.toast = toast; R.toastHome = toastHome; R.send = send;
/* What assets/realm_shop.js borrows: the two drawings, so the counter shows the very pictures the
   map uses, and a way to say "this is now mine", so the library and Your character know at once. */
R.sprite = sprite; R.thingStamp = thingStamp;
R.shopOwned = function (list) { if (ST.shop && list) { ST.shop.owned = list; if (DECO.on) renderDeco(); } };
/* What assets/realm_trade.js borrows: after a thing has changed hands, ask for the map again (what
   I own and what everybody wears) and, standing in my own office, for my room (a thing I gave away
   has been taken out of it). */
/* What assets/realm_praise.js borrows: the fan of stars over somebody who has just been praised. */
R.fxAt = fxAt;
/* What assets/realm_react.js borrows: the open conversation (which one, what is on screen) and the
   one place an answer about it is taken in, so a reaction's answer is drawn like any other. */
R.chat = CH; R.chatTake = chatTake;
R.refresh = function () { send({ping:1,active:1}); var m = me(); if (m && S.inside==='user:'+m.id && !DECO.on) roomAsk(m.id); };
['pointerdown','wheel','touchstart'].forEach(function (ev) { window.addEventListener(ev,function () { S.touched = true; },{passive:true}); });
rebuildBuildings();
var mine = me(), home = (mine && B[mine.place]) ? mine.place : (mine ? 'user:'+mine.id : 'tavern'), hs = spot(home);
if (hs) { S.pos = {x:hs.x,y:hs.y}; S.here = home; }
applyState(ST);
/* A RELOAD KEEPS YOU WHERE YOU WERE (David, 2026-11-13: "is it possible when you reload to be kept
   in the same spot or house where you left?"). The page used to start you outside the door of the
   building the server last saw you arrive at, so a reload threw you out of a room and undid any
   walking. Where I stand is now written down in this browser every few seconds and as the page
   closes, and read back here: the room I was in and my place in it, or my place on the map.
   - Only for the same person on this computer, and only for 12 hours: the next morning you start
     at your own door again, as before.
   - A room is entered again only if its door still lets me in (mayEnter). An ajar door I was let
     through does not after a reload, so I am then stood outside it, where I walked in from.
   - It runs after applyState() because the ground and the buildings must exist to test the spot. */
var KEEP = 'realmSpot';
function keepSpot() {
  if (!mine || S.enter!==null) return;
  try { localStorage.setItem(KEEP,JSON.stringify({u:mine.id,t:Date.now(),inside:(!RM.leaving && S.inside) || null,x:Math.round(S.pos.x),y:Math.round(S.pos.y),rx:Math.round(RM.x),ry:Math.round(RM.y)})); } catch (e) { /* A private window may refuse; then a reload starts at the door, as it used to. */ }
}
(function () {
  var k = null; try { k = JSON.parse(localStorage.getItem(KEEP) || 'null'); } catch (e) { k = null; }
  if (!k || !mine || k.u!==mine.id || !(Date.now()-k.t < 12*3600000)) return;
  var num = function (v) { return typeof v==='number' && isFinite(v); };
  try {
    if (k.inside && B[k.inside] && !mayEnter(k.inside) && spot(k.inside)) {
      var o = B[k.inside], d = spot(k.inside);
      S.pos = {x:d.x,y:d.y}; S.here = k.inside; S.sel = null; S.inside = k.inside; roomCome();
      if (num(k.rx) && num(k.ry) && roomFree(k.rx,k.ry,roomSolids(o))) { RM.x = k.rx; RM.y = k.ry; }
      if (o.owner) roomAsk(o.owner.id); else if (campOf(k.inside)) campAsk(k.inside);
      renderCard(); renderInside(); renderSide(); if (chanFor(k.inside)) openChat(chanFor(k.inside));
      draw(); send({arrive:k.inside,inside:1}); spotDirty();
      return;
    }
    if (num(k.x) && num(k.y) && inEdge(k.x,k.y) && canStand(k.x,k.y)) {
      S.pos = {x:k.x,y:k.y}; var at = nearest(); S.here = at; S.sel = at;
      follow(); renderCard(); renderSide(); draw();
      if (at && at!==home) send({arrive:at,inside:0});
      spotDirty();
    }
  } catch (e) { /* Whatever was written down is no use: the door I started at stands. */ }
})();
window.addEventListener('pagehide',keepSpot);
setInterval(keepSpot,5000);
/* The heartbeat. One small request every 25 seconds says I am still here and brings back where
   everybody else is. `active` is whether I touched the page since the last one. */
setInterval(function () { var a = S.touched ? 1 : 0; S.touched = false; send({ping:1,active:a}); },25000);
/* With no conversation open, ask for unread counts every 8 seconds. An open conversation already
   brings them every 3. Not asked while the window is hidden.
   The same answer says where the people I can see are standing (takeSpots), so it is asked every 3
   seconds instead while that is worth watching: I share a room with somebody, or somebody I can
   see has moved in the last 15 seconds. Voice brings its own every second, and then this waits. */
var beatN = 0;
setInterval(function () {
  beatN++;
  var watch = SP.ready && !VO.on && ((S.inside && B[S.inside] && roomPeople(B[S.inside]).length > 0) || Date.now() < SP.hot);
  if (beatN%(watch?3:8)===0) askUnread();
},1000);
/* The focus desk: the readout every second, the server's word every minute and whenever the window
   comes back into view. On a page with no desk in it, none of this runs. */
/* The chest and the portrait: see-through buttons over the drawn things, like the shelf below. */
if ($('mapbox')) [['chestBtn','chestTag','Your gold','Your gold, in the chest'],['portraitBtn','portraitTag','Profile','A profile, in the portrait'],['shopBtn','shopTag','Merchant','The merchant, who sells things for gold']].forEach(function (h) {
  var b = document.createElement('button'); b.type = 'button'; b.id = h[0]; b.className = 'rshelf'; b.hidden = true;
  b.setAttribute('aria-label',h[3]); b.innerHTML = '<span id="'+h[1]+'">'+h[2]+'</span>'; $('mapbox').appendChild(b);
});
/* A show's camp: the same see-through buttons over the bar, the scroll, the calendar and the board.
   While I stand in a camp it is asked again every 45 seconds (three small queries for the bar, one
   for the decorations), so the bar moves and a colleague's new thing shows without leaving. */
if ($('mapbox')) { [['slayBtn','','','How much of the show is finished, on the slaying bar'],['scrollBtn','scrollTag','Brief','The brief of this show, on the scroll'],['calBtn','calTag','Timeline','The timeline of this show, on the calendar'],['boardBtn','boardTag','Tracking','The production tracking of this show, on the board']].forEach(function (h) {
    var b = document.createElement('button'); b.type = 'button'; b.id = h[0]; b.className = 'rshelf'; b.hidden = true;
    b.setAttribute('aria-label',h[3]); if (h[1]) b.innerHTML = '<span id="'+h[1]+'">'+h[2]+'</span>'; $('mapbox').appendChild(b);
  });
  setInterval(function () { if (S.inside && campOf(S.inside) && !DECO.on && !document.hidden) campAsk(S.inside); },45000);
  /* The window shut. (If it was opened again in the same breath, it is a new window and is left alone.) */
  if ($('campDlg')) $('campDlg').addEventListener('close',function () { if ($('campDlg').open) return; clearInterval(CW.timer); CW.kind = null; CW.data = null; });
}
if ($('focusBtn') && $('focusNow')) {
  var shelf = document.createElement('button'); shelf.type = 'button'; shelf.id = 'shelfBtn'; shelf.className = 'rshelf'; shelf.hidden = true;
  shelf.setAttribute('aria-label','Your tasks, on the bookshelf'); shelf.innerHTML = '<span id="shelfTag">Your tasks</span>'; $('mapbox').appendChild(shelf);
  focusAsk(); setInterval(focusTick,1000); setInterval(function () { if (!document.hidden) focusAsk(); },60000);
  document.addEventListener('visibilitychange',function () { if (!document.hidden) focusAsk(); });
}
if (!calm) setInterval(function () { S.frame++; draw(); },260);
window.addEventListener('resize',function () { draw(); shareShow(); });
})();
