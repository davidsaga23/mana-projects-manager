/* The Realm, stage 1 (David, 2026-11-12): the village map, who is here, doors, your character, and
   walking into a building to see who is inside. Data and rules come from realm_api.php
   (includes/realm.php); this file only draws and moves.

   HOW IT IS DRAWN. "More detail, like an old handheld RPG ... houses you bump into, characters hidden
   behind them." The ground is painted once onto a bitmap as 16-unit tiles. Every tall thing (houses,
   pines, peaks, the volcano) is its own small picture, and each frame they are drawn back to front
   together with the characters, so whoever stands behind a house is covered by it. An SVG laid over
   the canvas carries only the labels and the click areas. All the art is drawn by this code and is our
   own; the studio's artists can replace any of it later.

   WHAT OTHER PEOPLE SEE OF YOU. Only where you ARRIVED ("let's start only where they have arrived"):
   walking is yours alone and nothing is sent until you stop at a building or go inside. That keeps the
   load on the shared hosting to one small request per person every 25 seconds.

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
  smoke:'#c9d1e0',glow:'#e2b35c',shadow:'rgba(0,0,0,.28)',mist:'rgba(176,186,206,.3)',dim:'rgba(0,0,0,.16)',lift:'rgba(255,255,255,.12)',veil:'#191919',ground:'#101010'};
var TINT = {candle:'#ffc66b',mana:'#5fb2ff',spell:'#26fe89',ember:'#ff7a59',violet:'#b592ff',rose:'#ff8fb3',teal:'#4fd6c8'};
var CLOAKS = {candle:'Gold',mana:'Blue',spell:'Green',ember:'Ember',violet:'Violet',rose:'Rose',teal:'Teal'};
var HATS = {none:'Bare',hat:'Pointed hat',hood:'Hood'};
var PETS = {none:'None',owl:'Owl',cat:'Cat'};
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
  else body.push([-7,-36,14,4,PAL.hair]);
  PS(body);
  A(-9,-4,18,2,cd); A(-1,-20,2,16,cd); A(-7,-20,2,8,cl); A(-9,-12,2,8,cl); A(5,-18,2,6,cd); A(7,-10,2,6,cd); A(-1,-20,2,2,PAL.win);
  A(-4,-27,2,3,o); A(2,-27,2,3,o); A(-4,-27,1,1,PAL.hot); A(2,-27,1,1,PAL.hot); A(-6,-24,2,1,PAL.cheek); A(4,-24,2,1,PAL.cheek); A(-1,-23,2,1,PAL.mouth);
  if (L.hat==='hat') { A(-7,-38,14,2,PAL.win); A(-7,-42,2,4,cl); A(-5,-47,2,5,cl); A(-12,-36,24,1,cl); }
  else if (L.hat==='hood') { A(-8,-37,16,1,cl); A(-9,-33,1,14,cl); }
  else { A(-7,-32,2,4,PAL.hair); A(5,-32,2,4,PAL.hair); A(-5,-36,4,1,shade(PAL.hair,35)); }
  if (L.pet==='owl') { PS([[12,-15,11,14,PAL.owl],[12,-17,2,2,PAL.owl],[21,-17,2,2,PAL.owl]]); A(14,-6,7,4,PAL.owlL); A(13,-12,4,4,PAL.hot); A(18,-12,4,4,PAL.hot); A(14,-11,2,2,o); A(19,-11,2,2,o); A(17,-8,2,2,PAL.win); }
  else if (L.pet==='cat') { PS([[13,-9,12,8,PAL.fur],[12,-15,9,7,PAL.fur],[12,-18,3,3,PAL.fur],[18,-18,3,3,PAL.fur],[25,-16,2,9,PAL.fur]]); A(14,-13,2,2,PAL.win); A(18,-13,2,2,PAL.win); A(16,-11,1,1,PAL.cheek); A(15,-4,8,2,shade(PAL.fur,-30)); }
}
/* Resting: a small Z over the head. */
function zed(c, x, y) { c.fillStyle = PAL.out; c.fillRect(x+7,y-63,9,9); c.fillStyle = PAL.hot; c.fillRect(x+8,y-62,7,1); c.fillRect(x+8,y-56,7,1); for (var i = 0; i < 5; i++) c.fillRect(x+13-i,y-61+i,2,1); }
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
    c.fillStyle = PAL.out; c.fillRect(hx+2,y+8,2,2); c.fillRect(hx+6,y+8,2,2); }
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
var ROOMS = {};
function roomArt(kind, tint) {
  var key = kind+tint; if (ROOMS[key]) return ROOMS[key];
  var col = TINT[tint]||TINT.candle, tent = kind==='tent', stonef = kind==='hall'||kind==='tower';
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
    if (kind==='study') { parts([[100,14,40,32,PAL.wood]]); for (i = 0; i < 3; i++) { P1(102,18+i*10,36,7,PAL.woodD); for (x = 0; x < 8; x++) P1(103+x*4,19+i*10,3,6,[TINT.mana,TINT.ember,TINT.spell,TINT.candle][(x+i)%4]); }
      parts([[24,62,46,14,PAL.wood]]); parts([[30,54,3,8,PAL.wall]]); P1(30,51,3,3,PAL.win); parts([[44,58,14,5,PAL.wall]]); }
    else if (kind==='tower') { [[90,14],[124,14]].forEach(function (b) { parts([[b[0],b[1],26,32,PAL.wood]]); for (i = 0; i < 3; i++) for (x = 0; x < 5; x++) P1(b[0]+2+x*5,b[1]+3+i*10,4,7,[TINT.violet,TINT.mana,TINT.rose][(x+i)%3]); });
      parts([[188,60,4,26,PAL.wood],[176,56,26,6,PAL.iron]]); P1(198,57,4,4,PAL.win); }
    else if (kind==='forge') { parts([[66,14,22,32,PAL.wood]]); for (i = 0; i < 3; i++) { P1(68,18+i*10,18,7,PAL.woodD); for (x = 0; x < 4; x++) P1(69+x*4,19+i*10,3,6,[TINT.ember,TINT.candle,TINT.mana,TINT.spell][(x+i)%4]); }
      parts([[92,12,56,40,PAL.stoneD],[102,24,36,28,PAL.lava]]); P1(108,32,24,20,PAL.lavaL); P1(115,40,10,12,PAL.hot);
      parts([[40,104,20,8,PAL.iron],[34,98,32,6,PAL.iron]]); parts([[184,96,20,22,PAL.wood]]); P1(184,102,20,2,PAL.iron); P1(184,112,20,2,PAL.iron); }
    else if (kind==='tavern') { parts([[24,54,192,12,PAL.wood]]); P1(24,54,192,2,shade(PAL.wood,35)); for (x = 34; x < 210; x += 22) { parts([[x,46,6,8,PAL.wall]]); P1(x,46,6,2,PAL.win); }
      [[28,100],[190,100]].forEach(function (t) { parts([[t[0],t[1],22,26,PAL.wood]]); P1(t[0],t[1]+6,22,2,PAL.iron); P1(t[0],t[1]+18,22,2,PAL.iron); }); }
    else if (kind==='hall') { parts([[70,74,100,10,PAL.marble]]); P1(70,82,100,2,PAL.marbleD); [[26,14],[204,14]].forEach(function (b) { parts([[b[0],b[1],10,30,col]]); P1(b[0]+3,b[1]+8,4,4,PAL.hot); }); }
    else { parts([[96,70,48,12,PAL.wood]]); P1(100,72,40,8,PAL.wall); P1(108,74,8,2,TINT.ember); P1(122,75,10,2,TINT.mana); parts([[28,110,18,14,PAL.wood],[196,112,16,12,PAL.wood]]); }
  });
  return ROOMS[key];
}
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
/* THE LIBRARY (David, 2026-11-12, circling the bookshelf in his own house: "let's change the focus
   button to this library where all our 'tasks' will be. If you click on that shelf you'll see all
   your tasks and you can decide which one to work on"). The shelf in YOUR OWN house is the way in
   to your tasks; nobody else's shelf does anything. Where the books stand in each kind of house, in
   the room's own units: the canvas cannot be clicked piece by piece, so a see-through button is
   laid exactly over the shelf each time the room is drawn. */
var SHELF = {study:[99,13,42,34],tower:[89,13,62,34],forge:[65,13,24,34]};
function placeShelf(o, s, ox, oy) {
  var b = $('shelfBtn'); if (!b) return;
  var r = o && o.owner && o.owner.me ? SHELF[o.kind] : null;
  b.hidden = !r; if (!r) return;
  var k = Math.min(2,window.devicePixelRatio||1);
  b.style.left = (ox+r[0]*s)/k+'px'; b.style.top = (oy+r[1]*s)/k+'px'; b.style.width = r[2]*s/k+'px'; b.style.height = r[3]*s/k+'px';
}
var SEATS = [[120,132],[86,126],[154,126],[60,134],[180,134],[100,112],[140,112],[46,118],[194,118]];
function drawRoom(c, cw, ch) {
  var o = B[S.inside]; if (!o) { placeShelf(null); return; }
  var rm = roomArt(o.kind,o.tint), s = Math.max(1,Math.min(cw/240,(ch-70)/160)), ox = Math.round((cw-240*s)/2), oy = Math.round((ch-160*s)/2+30), f = S.frame;
  placeShelf(o,s,ox,oy);
  c.setTransform(s,0,0,s,ox,oy); c.drawImage(rm.cv,0,0);
  var list = [];
  if (o.owner && !o.owner.me && o.owner.state!=='away' && o.owner.place===o.id) list.push(o.owner);
  ST.people.forEach(function (p) { if (!p.me && p.state!=='away' && p.inside && p.place===o.id && list.indexOf(p) < 0) list.push(p); });
  var my = me()||{cloak:'mana',hat:'none',pet:'none'};
  c.setTransform(s*.9,0,0,s*.9,ox,oy);
  list.slice(0,SEATS.length-1).forEach(function (p, i) { var st = SEATS[i+1]; if (p.state==='idle') c.globalAlpha = .6; sprite(c,Math.round(st[0]/.9),Math.round(st[1]/.9),p,(f+i)%4===0?2:0); c.globalAlpha = 1; if (p.state==='idle') zed(c,Math.round(st[0]/.9),Math.round(st[1]/.9)); });
  sprite(c,Math.round(SEATS[0][0]/.9),Math.round(SEATS[0][1]/.9),my,0);
  c.setTransform(s,0,0,s,ox,oy);
  var now = Date.now(), boxes = [], say = function (uid, st) { var b = BUB[uid]; if (b && b.until > now) { var box = bubbleBox(c,st[0],st[1]-60,b.text); box.until = b.until; boxes.push(box); } };
  list.slice(0,SEATS.length-1).forEach(function (p, i) { say(p.id,SEATS[i+1]); });
  say(ST.me,SEATS[0]);
  /* Lowest first, so a lifted bubble's line is drawn over nothing that matters. */
  placeBubbles(boxes).sort(function (p, q) { return p.by-q.by; }).forEach(function (bx) { paintBubble(c,bx); });
  return list;
}

/* ---------- the frame ---------- */
function draw() {
  var cv = $('mapcv'); if (!cv || !GROUND) return;
  var dpr = Math.min(2,window.devicePixelRatio||1), cw = Math.round(cv.clientWidth*dpr), ch = Math.round(cv.clientHeight*dpr); if (!cw || !ch) return;
  if (cv.width!==cw || cv.height!==ch) { cv.width = cw; cv.height = ch; }
  var c = cv.getContext('2d'), v = S.view, s = Math.max(cw/v.w,ch/v.h), ox = (cw-v.w*s)/2-v.x*s, oy = (ch-v.h*s)/2-v.y*s, f = S.frame, i;
  c.setTransform(1,0,0,1,0,0); c.imageSmoothingEnabled = false; c.globalAlpha = 1; c.fillStyle = PAL.ground; c.fillRect(0,0,cw,ch);
  if (S.inside) { drawRoom(c,cw,ch); }
  else {
    placeShelf(null);
    c.setTransform(s,0,0,s,ox,oy); c.drawImage(GROUND,0,0);
    var x0 = -ox/s-130, x1 = (cw-ox)/s+130, y0 = -oy/s-20, y1 = (ch-oy)/s+170, list = [], w = whereabouts(), my = me()||{cloak:'mana',hat:'none',pet:'none'};
    [OBJ,HOUSES].forEach(function (a) { for (var k = 0; k < a.length; k++) if (a[k].x>x0&&a[k].x<x1&&a[k].y>y0&&a[k].y<y1) list.push(a[k]); });
    w.out.forEach(function (q, n) { list.push({x:q.x,y:q.y,who:q.p,bob:(f+n)%4===0?2:0,idle:q.p.state==='idle'}); });
    var mx = Math.round(S.pos.x), myy = Math.round(S.pos.y), moving = S.walk||held.size, en = S.enter;
    list.push({x:mx,y:myy,who:my,bob:moving?(Math.floor(Date.now()/130)%2)*2:0,mine:true});
    list.sort(function (a, b) { return a.y-b.y; });
    for (i = 0; i < list.length; i++) { var it = list[i];
      if (it.st) { c.drawImage(it.st.cv,it.x-it.st.ox,it.y-(it.lift||0)-it.st.oy); continue; }
      if (it.mine) { if (en) c.globalAlpha = Math.max(0,1-en); c.fillStyle = PAL.win; c.fillRect(mx-13,myy+2,26,2); c.fillRect(mx-13,myy,2,2); c.fillRect(mx+11,myy,2,2); }
      if (it.idle) c.globalAlpha = .6;
      sprite(c,it.x,it.y,it.who,it.bob); c.globalAlpha = 1;
      if (it.idle) zed(c,it.x,it.y);
    }
    BLIST.forEach(function (o) {
      if (o.owner && o.owner.focus) puff(c,o.x+(o.kind==='forge'?16:0),o.y-(o.kind==='forge'?82:o.kind==='tower'?128:66),f,false);
      heads(c,o,w.inside[o.id]||[]);
    });
    puff(c,1380,636,f,true);
    /* The arrow is always on top, so you can find yourself when a house covers you. */
    if (!en) { var ay = myy-70-(f%2)*2; c.fillStyle = PAL.out; c.fillRect(mx-6,ay-1,12,5); c.fillRect(mx-4,ay+4,8,3); c.fillRect(mx-2,ay+7,4,2);
      c.fillStyle = PAL.win; c.fillRect(mx-5,ay,10,3); c.fillRect(mx-3,ay+3,6,2); c.fillRect(mx-1,ay+5,2,2); }
  }
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
    var hw = HIT[o.kind][0], ht = HIT[o.kind][1], half = o.name.length*4.4+10, mark = '';
    if (o.place) { var w = o.name.length*9.2+20;
      h += '<g class="bld" tabindex="0" role="button" data-sel="'+esc(o.id)+'" aria-label="'+esc(o.name)+', a place to meet"><rect class="hit" x="'+(o.x-hw-10)+'" y="'+(o.y-ht)+'" width="'+(hw*2+20)+'" height="'+(ht+50)+'" rx="4"/><rect class="plate" x="'+(o.x-w/2)+'" y="'+(o.y+24)+'" width="'+w+'" height="21" rx="3"/><text class="lbl place" x="'+o.x+'" y="'+(o.y+39)+'">'+esc(o.name)+'</text></g>';
      return; }
    var d = o.lit ? o.door : '';
    if (d==='open') mark = '<circle cx="'+(o.x-half)+'" cy="'+(o.y+15)+'" r="4" style="fill:var(--spell);stroke:var(--night)"/>';
    else if (d==='ajar') mark = '<circle cx="'+(o.x-half)+'" cy="'+(o.y+15)+'" r="3.5" style="fill:var(--night);stroke:var(--candle);stroke-width:2"/>';
    else if (d==='sealed') mark = '<rect x="'+(o.x-half-3.5)+'" y="'+(o.y+11.5)+'" width="7" height="7" transform="rotate(45 '+(o.x-half)+' '+(o.y+15)+')" style="fill:var(--ember);stroke:var(--night)"/>';
    h += '<g class="bld" tabindex="0" role="button" data-sel="'+esc(o.id)+'" aria-label="'+esc(title(o.id))+(d?', '+DOORS[d]:', away')+'"><rect class="hit" x="'+(o.x-hw)+'" y="'+(o.y-ht)+'" width="'+(hw*2)+'" height="'+(ht+24)+'" rx="4"/>'+mark+'<text class="lbl'+(o.lit?'':' away')+'" x="'+(o.x+4)+'" y="'+(o.y+20)+'">'+esc(o.name)+'</text></g>';
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
    h += '<button type="button" class="rrow'+(S.sel===o.id?' is-on':'')+'" data-sel="'+esc(o.id)+'" data-center="1"><span>'+esc(o.name)+'</span>'+(n?'<small>'+n+' inside</small>':'')+'</button>'; });
  $('side').innerHTML = h+'</div></div>';
}
function renderCard() {
  var slot = $('cardslot'), id = S.sel, o = B[id], h = '';
  if (S.walk) { h = '<div class="rcard"><b>Walking to '+esc(title(S.walk.id))+'…</b><div class="racts"><button type="button" class="rbtn rsmall" data-tele="'+esc(S.walk.id)+'">Teleport instead</button></div></div>'; }
  else if (o) {
    var why = mayEnter(id), sub = o.owner ? (o.owner.state==='away'?'Away':(o.owner.state==='idle'?'Resting. ':'')+DOORS[o.owner.door]+(o.owner.focus?', in a focus session':'')+(o.owner.me?'. Your tasks are on the bookshelf inside':'')) : 'A place to meet. Chat and voice arrive in the next stages.';
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
  box.classList.toggle('is-inside',!!o); bar.hidden = !o; if (!o) return;
  var names = [];
  if (o.owner && !o.owner.me && o.owner.state!=='away' && o.owner.place===o.id) names.push(o.owner.name);
  ST.people.forEach(function (p) { if (!p.me && p.state!=='away' && p.inside && p.place===o.id && names.indexOf(p.name) < 0) names.push(p.name); });
  bar.innerHTML = '<h2>'+esc(title(o.id))+'</h2><span>'+(names.length?'Here with you: '+esc(names.join(', ')):'Nobody else is here right now.')+(o.owner && o.owner.me?' Your tasks are on the bookshelf.':'')+'</span><span class="rgrow"></span><button type="button" class="rbtn rprimary" data-leave="1">Leave</button>';
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
  if (S.inside && (!B[S.inside] || mayEnter(S.inside))) { S.inside = null; var s = spot(S.here)||S.pos; S.pos = {x:s.x,y:s.y}; toast('The door closed, so you are back outside.'); send({arrive:S.here||('user:'+ST.me),inside:0}); }
  renderAll();
  if (fresh) takeUnread(fresh);
  takeKnocks(st.knocks);
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
function freeAt(x, y) { var tx = Math.floor(x/16), ty = Math.floor(y/16); return tx>=0&&ty>=0&&tx<GRID.W&&ty<GRID.H&&!GRID.b[ty*GRID.W+tx]; }
function canStand(x, y) { return freeAt(x-6,y)&&freeAt(x+6,y); }
/* The way on foot from one point to another, round whatever is solid. */
function route(from, to) {
  var W = GRID.W, H = GRID.H, ti = function (p) { return Math.max(0,Math.min(H-1,Math.floor(p.y/16)))*W+Math.max(0,Math.min(W-1,Math.floor(p.x/16))); };
  var s = ti(from), t = ti(to), prev = new Int32Array(W*H).fill(-1), q = [s], D = [[1,0],[-1,0],[0,1],[0,-1]], h, d; prev[s] = s;
  for (h = 0; h < q.length && prev[t] < 0; h++) { var i = q[h], x = i%W, y = (i-x)/W;
    for (d = 0; d < 4; d++) { var nx = x+D[d][0], ny = y+D[d][1], j = ny*W+nx; if (nx<0||ny<0||nx>=W||ny>=H||prev[j]>=0||(GRID.b[j]&&j!==t)) continue; prev[j] = i; q.push(j); } }
  if (prev[t] < 0) return null;
  var path = [{x:to.x,y:to.y}]; for (var k = t; k!==s; k = prev[k]) path.push({x:(k%W)*16+8,y:Math.floor(k/W)*16+8});
  return path.reverse();
}
var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
/* I stopped at a building: now, and only now, the others are told where I am. */
function arrived(id) { S.walk = null; var s = spot(id); S.pos = {x:s.x,y:s.y}; S.here = id; S.sel = id; renderCard(); renderSide(); draw(); send({arrive:id,inside:0}); }
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
  follow(); draw(); S.raf = requestAnimationFrame(tick);
}
function teleport(id) { if (!spot(id)) return; arrived(id); }
/* A short animation driven by the clock: fn gets 0..1, done runs at the end. */
function play(ms, fn, done) { if (calm) { fn(1); done(); return; } var t0 = 0; (function step(ts) { if (!t0) t0 = ts; var k = Math.min(1,(ts-t0)/ms); fn(k); draw(); if (k < 1) requestAnimationFrame(step); else done(); })(performance.now()); }
/* Walk in through the door, fade, and you are in the room. */
function goInside(id) {
  var o = B[id], why = mayEnter(id); if (!o) return; if (why) { toast(why); return; }
  var from = {x:S.pos.x,y:S.pos.y}, to = {x:o.x+DOORX[o.kind],y:o.y-2};
  S.sel = null; renderCard();
  play(420,function (k) { S.pos = {x:from.x+(to.x-from.x)*k,y:from.y+(to.y-from.y)*k}; S.enter = k*.9; },function () {
    play(220,function (k) { S.fade = k; },function () {
      S.enter = null; S.inside = id; S.pos = from; renderInside(); renderSide(); if (chanFor(id)) openChat(chanFor(id));
      play(260,function (k) { S.fade = 1-k; },function () { S.fade = 0; draw(); });
      send({arrive:id,inside:1});
    });
  });
}
function leave() {
  if (!S.inside) return; var id = S.inside; BUB = {};
  play(220,function (k) { S.fade = k; },function () {
    S.inside = null; var s = spot(id)||S.pos; S.pos = {x:s.x,y:s.y}; S.here = id; S.sel = id; renderInside(); renderSide(); renderCard();
    play(260,function (k) { S.fade = 1-k; },function () { S.fade = 0; draw(); });
    send({arrive:id,inside:0});
  });
}

/* ---------- input ---------- */
var drag = null, justDragged = false, held = new Set(), kraf = 0, klt = 0;
function bindMap() {
  var s = $('mapsvg'); if (!s) return;
  s.addEventListener('wheel',function (e) { e.preventDefault(); var p = s.createSVGPoint(); p.x = e.clientX; p.y = e.clientY; var q = p.matrixTransform(s.getScreenCTM().inverse()); zoomAt(e.deltaY>0?1.15:.87,q.x,q.y); },{passive:false});
  s.addEventListener('pointerdown',function (e) { drag = {x:e.clientX,y:e.clientY,vx:S.view.x,vy:S.view.y,k:1/s.getScreenCTM().a,moved:false}; });
}
window.addEventListener('pointermove',function (e) { S.touched = true; if (!drag) return; var dx = e.clientX-drag.x, dy = e.clientY-drag.y; if (!drag.moved && Math.hypot(dx,dy) < 5) return; drag.moved = true; setView({x:drag.vx-dx*drag.k,y:drag.vy-dy*drag.k,w:S.view.w}); });
window.addEventListener('pointerup',function () { if (drag && drag.moved) { justDragged = true; setTimeout(function () { justDragged = false; },60); } drag = null; });
function select(id, center) { if (!B[id]) return; S.sel = id; if (center && !S.inside) { var o = B[id]; setView({x:o.x-S.view.w/2,y:o.y-40-S.view.h/2,w:S.view.w}); } renderCard(); renderSide(); }
function nearest() { var best = null, bd = 36; BLIST.forEach(function (o) { var t = spot(o.id), d = Math.hypot(t.x-S.pos.x,t.y-S.pos.y); if (d < bd) { bd = d; best = o.id; } }); return best; }
function keyTick(ts) {
  if (!held.size || S.inside) { kraf = 0; klt = 0; return; }
  var dt = klt ? Math.min(.05,(ts-klt)/1000) : 0; klt = ts;
  var dx = (held.has('ArrowRight')?1:0)-(held.has('ArrowLeft')?1:0), dy = (held.has('ArrowDown')?1:0)-(held.has('ArrowUp')?1:0);
  if (dx || dy) { var n = Math.hypot(dx,dy), st = 230*dt, nx = Math.max(20,Math.min(1580,S.pos.x+dx/n*st)), ny = Math.max(40,Math.min(990,S.pos.y+dy/n*st)), stuck = !canStand(S.pos.x,S.pos.y);
    S.pos = (stuck||canStand(nx,ny))?{x:nx,y:ny}:canStand(nx,S.pos.y)?{x:nx,y:S.pos.y}:canStand(S.pos.x,ny)?{x:S.pos.x,y:ny}:S.pos;
    follow(); draw();
    var at = nearest(); if (at!==S.here) { S.here = at; S.sel = at; renderCard(); renderSide(); if (at) send({arrive:at,inside:0}); } }
  kraf = requestAnimationFrame(keyTick);
}
document.addEventListener('keydown',function (e) {
  S.touched = true;
  var t = e.target, is = function (q) { return t && t.matches && t.matches(q); };
  if (is('input,select,textarea') || $('lookDlg').open || ($('picDlg') && $('picDlg').open) || ($('focusDlg') && $('focusDlg').open)) return;
  if (S.inside) { if (e.key==='Escape') leave(); return; }
  if (e.key.indexOf('Arrow')===0) { e.preventDefault(); if (S.walk) { S.walk = null; renderCard(); } held.add(e.key); if (!kraf) kraf = requestAnimationFrame(keyTick); }
  else if (e.key==='Enter' && is('.bld')) { e.preventDefault(); select(t.getAttribute('data-sel'),false); }
  else if (e.key==='Enter' && S.here && !is('button,a')) goInside(S.here);
});
document.addEventListener('keyup',function (e) { held.delete(e.key); });
window.addEventListener('blur',function () { held.clear(); });
document.addEventListener('click',function (e) {
  S.touched = true;
  var t = e.target.closest ? e.target : e.target.parentNode, b;
  musicWake(t);
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
  else if ((b = t.closest('[data-chat]'))) { openChat(b.getAttribute('data-chat')); if (b.classList.contains('rtoast')) b.remove(); }
  else if (t.closest('[data-chatclose]')) closeChat();
  else if ((b = t.closest('[data-sel]'))) { if (justDragged) return; select(b.getAttribute('data-sel'),b.hasAttribute('data-center')); }
  else if ((b = t.closest('[data-walk]'))) walkTo(b.getAttribute('data-walk'));
  else if ((b = t.closest('[data-tele]'))) teleport(b.getAttribute('data-tele'));
  else if ((b = t.closest('[data-enter]'))) goInside(b.getAttribute('data-enter'));
  else if (t.closest('[data-close]')) { S.sel = null; renderCard(); renderSide(); }
  else if (t.closest('[data-leave]')) leave();
  else if ((b = t.closest('[data-zoom]'))) { var z = b.getAttribute('data-zoom'), v = S.view, cx = v.x+v.w/2, cy = v.y+v.h/2; if (z==='in') zoomAt(.72,cx,cy); else if (z==='out') zoomAt(1.39,cx,cy); else setView(z==='village'?VILLAGE:FULL); }
  else if ((b = t.closest('[data-door]'))) send({set_door:b.getAttribute('data-door')});
  else if (t.closest('#lookBtn')) openLook();
  else if (t.closest('#focusBtn') || t.closest('#shelfBtn')) openFocus();
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
var CH = {open:null,last:0,timer:0,busy:false,seen:false};
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
    .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { takeUnread(j.unread); takeKnocks(j.knocks); } }).catch(function () {});
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
  return '<div class="rmsg'+(m.mine?' is-mine':'')+'"><div class="rmsghead"><b>'+esc(m.mine?'You':m.name)+'</b><small>'+esc(m.at)+'</small></div>'+(m.body?'<div class="rmsgbody">'+rich(m.body)+'</div>':'')+pic+'</div>';
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
  if (fresh.length) { if (!CH.last) box.innerHTML = ''; box.insertAdjacentHTML('beforeend',fresh.map(msgHTML).join('')); CH.last = fresh[fresh.length-1].id; if (stick || !CH.seen) box.scrollTop = box.scrollHeight; }
  else if (!CH.last && !CH.seen) box.innerHTML = '<p class="rempty">Nothing here yet. Be the first to write.</p>';
  CH.seen = true;
  $('chatInput').disabled = !j.can_post; $('chatSend').disabled = !j.can_post;
  if ($('chatAttach')) $('chatAttach').hidden = !(j.can_post && ST.chat && ST.chat.images); $('chatInput').placeholder = j.can_post ? 'Write a message' : 'Only Production writes here';
  if (j.unread) takeUnread(j.unread);
  takeKnocks(j.knocks);
}
function chatAsk() {
  if (!CH.open || CH.busy || document.hidden) return; CH.busy = true;
  fetch(R.api+'?chat='+encodeURIComponent(CH.open)+'&after='+CH.last,{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(chatTake).catch(function () { CH.busy = false; });
}
function openChat(id) {
  if (!ST.chat || !id || !$('chat')) return;
  if (CH.open===id) { $('chatInput').focus(); return; }
  CH.open = id; CH.last = 0; CH.seen = false; CH.busy = false; setPick(null); sugClose();
  $('chat').hidden = false; $('chat').parentNode.classList.add('with-chat'); $('chatTitle').textContent = chanTitle(id); $('chatMsgs').innerHTML = '';
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
  /* A picture travels as a file, so the message goes as a form with parts; words alone go as before. */
  var f = pic ? new FormData() : new URLSearchParams(); f.append('say',CH.open); f.append('body',body); f.append('after',CH.last);
  if (pic) f.append('image',pic,pic.name || 'pasted-image.png');
  fetch(R.api,{method:'POST',body:f,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(chatTake).catch(function () { CH.busy = false; inp.value = body; setPick(pic); toast('That message was not sent. Try again.'); });
});

/* ---------- your character ---------- */
var LOOK = {};
function seg(label, key, opts) { return '<div class="rpick"><span>'+label+'</span><span class="rseg">'+Object.keys(opts).map(function (v) { return '<button type="button" data-pick="'+key+'" data-val="'+v+'" aria-pressed="'+(LOOK[key]===v)+'">'+opts[v]+'</button>'; }).join('')+'</span></div>'; }
function renderLook() {
  $('lookForm').innerHTML = '<div class="rlook"><canvas id="charcv" class="rchar" width="120" height="120" role="img" aria-label="Your character"></canvas><div class="rpicks">'+seg('Cloak','cloak',CLOAKS)+seg('Head','hat',HATS)+seg('Companion','pet',PETS)+seg('House','house_kind',KINDS)+'</div></div><small>Your roof takes the colour of your cloak, so your house and your character match.</small><div class="rdacts"><button type="button" class="rbtn" data-lookcancel="1">Cancel</button><button class="rbtn rprimary">Save</button></div>';
  var c = $('charcv').getContext('2d'); c.setTransform(1,0,0,1,0,0); c.clearRect(0,0,120,120); c.setTransform(2,0,0,2,0,0); sprite(c,22,58,LOOK,0);
}
function openLook() { var m = me(); if (!m) return; LOOK = {cloak:m.cloak,hat:m.hat,pet:m.pet,house_kind:m.kind}; renderLook(); $('lookDlg').showModal(); }
$('lookForm').addEventListener('submit',function (e) { e.preventDefault(); $('lookDlg').close(); send({set_look:1,cloak:LOOK.cloak,hat:LOOK.hat,pet:LOOK.pet,house_kind:LOOK.house_kind}); });

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

   THE WAY IN IS THE BOOKSHELF in your own house (placeShelf, above). The top bar shows a button only
   while a clock is running: it reads "Focus 18:42", so the time is in sight, and can be stopped,
   from anywhere in the village. Somebody with no house yet has no shelf, so for them the button
   stays, reading "Your tasks". */
var FO = {data:null,at:0,busy:false,seq:0,settled:'',zero:false,err:''};
function clockWords(s) { s = Math.max(0,Math.floor(s)); var h = Math.floor(s/3600), m = Math.floor(s%3600/60), x = s%60; return (h?h+':'+(m<10?'0':'')+m:m)+':'+(x<10?'0':'')+x; }
function focusNow() { return FO.data && FO.data.ready ? FO.data.now : null; }
function onBreak(n) { return !!n && (n.phase==='short_break' || n.phase==='long_break'); }
/* Seconds left in the phase, or, for a plain timer with no end, seconds run so far. */
function focusLeft() { var n = focusNow(); if (!n) return 0; var gone = Math.max(0,Math.floor((Date.now()-FO.at)/1000)); return n.remaining===null ? n.elapsed+gone : Math.max(0,n.remaining-gone); }
function focusTask(key) { var l = (FO.data && FO.data.tasks)||[]; for (var i = 0; i < l.length; i++) if (l[i].key===key) return l[i]; return null; }
function renderFocus() {
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
  h = '<h3 class="rfhead">Your open work</h3>';
  if (!d.tasks.length) h += '<p class="rfidle">Nothing is assigned to you right now. Tasks and cards given to you in Anima appear here.</p>';
  else { h += '<div class="rflist">';
    d.tasks.forEach(function (k) { var mine = !!n && n.key===k.key, sub = [k.show,k.due?'due '+k.due:''].filter(Boolean).join(', ');
      h += '<div class="rftask'+(mine?' is-on':'')+'"><div><b>'+esc(k.title)+'</b>'+(sub?'<small>'+esc(sub)+'</small>':'')+'</div>'
        +(mine ? '<small>'+(brk?'On a break':'Running')+'</small>' : '<button type="button" class="rbtn rsmall" data-focusstart="'+esc(k.key)+'"'+(n?' title="This stops and logs the one already running"':'')+'>Start</button>')+'</div>'; });
    h += '</div>'; }
  list.innerHTML = h;
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
  if (!j) { if (!FO.data) { FO.err = 'Your tasks could not be reached. Try again in a moment.'; renderFocus(); } return; }
  if (j.said) toast(j.said);
  if (j.error) { toast(j.error); if (!FO.data) FO.err = j.error; }
  if (j.focus) {
    var was = focusNow();
    FO.data = j.focus; FO.at = Date.now(); FO.zero = false;
    var n = focusNow();
    if (!n || n.phase!=='focus' || n.remaining > 0) FO.settled = '';
    if (was && was.phase==='focus' && onBreak(n)) musicChime();
  }
  renderFocus(); focusTick();
  /* My own door has just sealed or opened: fetch the map again so it is redrawn now, not at the next beat. */
  if (acted) send({ping:1,active:1});
}
function focusAsk() {
  if (FO.busy) return; var seq = ++FO.seq;
  fetch(R.api+'?focus=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { focusTake(j,seq,false); }).catch(function () { focusTake(null,seq,false); });
}
function focusDo(fields) {
  if (FO.busy) return; FO.busy = true; var seq = ++FO.seq, body = new URLSearchParams();
  Object.keys(fields).forEach(function (k) { body.append(k,fields[k]); });
  fetch(R.api,{method:'POST',body:body,credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function (r) { return r.json(); }).then(function (j) { focusTake(j,seq,true); })
    .catch(function () { focusTake(null,seq,false); toast('That did not reach Anima. Try again.'); });
}
function openFocus() { var d = $('focusDlg'); if (!d || d.open) return; renderFocus(); d.showModal(); focusAsk(); }

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
   jazz chords and a soft bass, a dusty kick, snare and hat with a little swing, a stray high note
   now and then, record crackle underneath, the whole thing dulled and made to waver slightly in
   pitch like an old tape. Every sound is still built here from plain waves and noise.

   Notes are booked on the sound engine's own clock a couple of seconds ahead (musicPlan), because a
   browser slows the timers of a tab that is not in front, and a beat driven straight by a timer
   would stumble whenever the Realm was behind another window. */
var BPM = 72, BEAT = 60/BPM;
/* E minor 9, A 13, D major 9, B minor 11: bass note, then the four notes of the chord. */
var CHORDS = [[82.41,196,246.94,293.66,369.99],[110,196,246.94,277.18,369.99],[73.42,185,220,277.18,329.63],[123.47,185,220,293.66,329.63]];
var BELLS = [587.33,659.25,739.99,880,987.77];
function musicLevel() { return MU.vol*MU.vol*.9; }
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
  v.gain.setValueAtTime(0,at); v.gain.linearRampToValueAtTime(loud,at+attack); v.gain.exponentialRampToValueAtTime(.0001,at+dur);
  o.connect(v); v.connect(out); o.start(at); o.stop(at+dur+.05); o.onended = function () { v.disconnect(); };
  return o;
}
function musicBell(out, f, loud) { musicTone(out,'sine',f,MU.ctx.currentTime,3.6,loud,.04,null); }
/* A drum made of hiss: a snare (a band of it) or a hat (only the top of it). */
function musicHiss(rig, at, dur, loud, kind, freq) {
  var ctx = MU.ctx, src = ctx.createBufferSource(), fl = ctx.createBiquadFilter(), v = ctx.createGain();
  src.buffer = rig.noise; fl.type = kind; fl.frequency.value = freq;
  v.gain.setValueAtTime(loud,at); v.gain.exponentialRampToValueAtTime(.0001,at+dur);
  src.connect(fl); fl.connect(v); v.connect(rig.bus); src.start(at,Math.random()*.8,dur+.05); src.onended = function () { v.disconnect(); };
}
/* One half-beat of the loop: n counts 0 to 31 across the four bars. */
function musicStep(rig, n, t) {
  var bar = n>>3, s = n&7, ch = CHORDS[bar], at = t+(s&1 ? BEAT*.1 : 0), i;
  if (s===0 || s===5) for (i = 1; i < 5; i++) musicTone(rig.bus,'triangle',ch[i],at+i*.03,s?BEAT*1.3:BEAT*3.6,s?.035:.07,.02,rig.wow);
  if (s===0 || s===5) musicTone(rig.bus,'triangle',ch[0],at,s?BEAT*.9:BEAT*1.5,s?.13:.18,.02,rig.wow);
  if (s===0 || s===5 || (s===3 && (bar&1))) { var k = musicTone(rig.bus,'sine',120,at,.3,.5,.005,null); k.frequency.setValueAtTime(120,at); k.frequency.exponentialRampToValueAtTime(45,at+.12); }
  if (s===2 || s===6) musicHiss(rig,at,.14,.14,'bandpass',1700);
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
  /* Everything musical goes through one dull filter: that, more than anything, is the "lo" in lofi. */
  rig.bus = ctx.createBiquadFilter(); rig.bus.type = 'lowpass'; rig.bus.frequency.value = 2400; rig.bus.Q.value = .7; rig.bus.connect(rig.master);
  var lfo = ctx.createOscillator(); rig.wow = ctx.createGain(); lfo.frequency.value = .45; rig.wow.gain.value = 9; lfo.connect(rig.wow); lfo.start(); rig.live.push(lfo);
  rig.noise = ctx.createBuffer(1,ctx.sampleRate,ctx.sampleRate); var d = rig.noise.getChannelData(0); for (i = 0; i < d.length; i++) d[i] = Math.random()*2-1;
  /* Record crackle: two seconds of near silence with a faint hiss and a few clicks, played round and round. */
  var dust = ctx.createBuffer(1,ctx.sampleRate*2,ctx.sampleRate), c = dust.getChannelData(0); for (i = 0; i < c.length; i++) c[i] = (Math.random()*2-1)*(Math.random() < .0006 ? .9 : .012);
  var spin = ctx.createBufferSource(), hp = ctx.createBiquadFilter(), dv = ctx.createGain(); spin.buffer = dust; spin.loop = true; hp.type = 'highpass'; hp.frequency.value = 900; dv.gain.value = .5;
  spin.connect(hp); hp.connect(dv); dv.connect(rig.master); spin.start(); rig.live.push(spin);
  musicPlan(rig); rig.plan = setInterval(function () { musicPlan(rig); },400);
  MU.rig = rig;
}
function musicStop() {
  var rig = MU.rig, ctx = MU.ctx; if (!rig) return; MU.rig = null;
  clearInterval(rig.plan);
  var t = ctx.currentTime; rig.master.gain.cancelScheduledValues(t); rig.master.gain.setTargetAtTime(0,t,.5);
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
  $('musicOn').addEventListener('change',function () { MU.on = this.checked; musicSave(); musicWake(this); musicSync(); });
  $('musicVol').addEventListener('input',function () { MU.vol = this.value/100; musicSave(); if (MU.rig) MU.rig.master.gain.setTargetAtTime(musicLevel(),MU.ctx.currentTime,.1); });
}

/* ---------- start ---------- */
['pointerdown','wheel','touchstart'].forEach(function (ev) { window.addEventListener(ev,function () { S.touched = true; },{passive:true}); });
rebuildBuildings();
var mine = me(), home = (mine && B[mine.place]) ? mine.place : (mine ? 'user:'+mine.id : 'tavern'), hs = spot(home);
if (hs) { S.pos = {x:hs.x,y:hs.y}; S.here = home; }
applyState(ST);
/* The heartbeat. One small request every 25 seconds says I am still here and brings back where
   everybody else is. `active` is whether I touched the page since the last one. */
setInterval(function () { var a = S.touched ? 1 : 0; S.touched = false; send({ping:1,active:a}); },25000);
/* With no conversation open, ask for unread counts every 8 seconds. An open conversation already
   brings them every 3. Not asked while the window is hidden. */
setInterval(askUnread,8000);
/* The focus desk: the readout every second, the server's word every minute and whenever the window
   comes back into view. On a page with no desk in it, none of this runs. */
if ($('focusBtn') && $('focusNow')) {
  var shelf = document.createElement('button'); shelf.type = 'button'; shelf.id = 'shelfBtn'; shelf.className = 'rshelf'; shelf.hidden = true;
  shelf.setAttribute('aria-label','Your tasks, on the bookshelf'); shelf.innerHTML = '<span id="shelfTag">Your tasks</span>'; $('mapbox').appendChild(shelf);
  focusAsk(); setInterval(focusTick,1000); setInterval(function () { if (!document.hidden) focusAsk(); },60000);
  document.addEventListener('visibilitychange',function () { if (!document.hidden) focusAsk(); });
}
if (!calm) setInterval(function () { S.frame++; draw(); },260);
window.addEventListener('resize',function () { draw(); });
})();
