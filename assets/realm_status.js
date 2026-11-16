/* The Realm: setting your status (David, 2026-11-16, migration 098).

   "Status messages: Let's also create a diner so people's avatars go immediately there when they set
   up their status 'Lunch'". Slack's status: an emoji and a short line beside your name, from a few
   ready-made ones (Lunch, In review, Focusing, Out sick, Off today) or your own words and emoji, and
   when it clears by itself (30 minutes, an hour, today, never).

   Opened from "Status" in the top bar, or from the status line in "Your character". This file is
   only the window: the server checks and keeps it (includes/realm_status.php, which also moves the
   character to the Diner for Lunch), and assets/realm.js shows everybody's and follows the move. */
(function () {
'use strict';
var R = window.REALM;
if (!R || !R.state || !R.send || !document.getElementById('statusDlg')) return;
var $ = function (id) { return document.getElementById(id); };
var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var CLEAR = {'30m':'30 minutes','1h':'1 hour','today':'Today','never':'Never'};
/* What the window is about to save. kind is a ready-made one or 'custom'. */
var F = {kind:'',emoji:'',text:'',clear:'',grid:false};

function menu() { var st = R.now(); return (st && st.statuses) || {ready:false,presets:[],emoji:[],clear:[],max:80}; }
/* A status stays a ready-made one while it wears that one's emoji: "Lunch with Tim" with the burger
   is still Lunch (and still goes to the Diner), but a new emoji makes it the person's own. */
function kindNow() { var p = preset(F.kind); return p && p.emoji===F.emoji ? p.kind : ''; }
function preset(kind) { var l = menu().presets, i; for (i = 0; i < l.length; i++) if (l[i].kind===kind) return l[i]; return null; }

/* The top bar's button says what my status is, or offers to set one. */
function label() {
  var b = $('statusBtn'); if (!b) return;
  var s = R.stOf(R.me());
  b.innerHTML = s ? '<span class="rstatbtn-e">'+esc(s.emoji)+'</span><span class="rstatbtn-t">'+esc(s.text)+'</span>' : 'Set a status';
  b.title = s ? 'Your status: '+s.text+(R.stUntil(s)?', '+R.stUntil(s):'')+'. Click to change it.' : 'Set a status, as in Slack';
  b.disabled = !R.me();
}

function render() {
  var m = menu(), max = m.max || 80, s = R.stOf(R.me());
  if (!m.ready) {
    $('statusForm').innerHTML = '<p class="rstatnote">Statuses need a database update first. An administrator opens migrate.php once, then this works.</p><div class="rdacts"><button type="button" class="rbtn" data-statuscancel="1">Close</button></div>';
    return;
  }
  var h = '<div class="rstatrow"><button type="button" class="rbtn rstatemoji" data-statusgrid="1" aria-expanded="'+F.grid+'" aria-label="Choose an emoji">'+esc(F.emoji||'💬')+'</button>'
    + '<input id="statusText" maxlength="'+max+'" autocomplete="off" placeholder="What is your status?" aria-label="Your status" value="'+esc(F.text)+'"></div>';
  if (F.grid) h += '<div class="rstatgrid" role="group" aria-label="Emoji">'+m.emoji.map(function (e) { return '<button type="button" data-statusemoji="'+esc(e)+'" aria-pressed="'+(F.emoji===e)+'">'+esc(e)+'</button>'; }).join('')+'</div>';
  h += '<div class="rstatpresets" role="group" aria-label="Ready-made statuses">'+m.presets.map(function (p) {
    return '<button type="button" class="rstatpreset'+(F.kind===p.kind?' is-on':'')+'" data-statuspreset="'+esc(p.kind)+'"><span>'+esc(p.emoji)+'</span><b>'+esc(p.text)+'</b><small>'+esc(p.kind==='lunch'?'Takes you to the Diner':CLEAR[p.clear]||'')+'</small></button>';
  }).join('')+'</div>';
  h += '<div class="rpick"><span>Clear after</span><span class="rseg">'+m.clear.map(function (c) { return '<button type="button" data-statusclear="'+esc(c)+'" aria-pressed="'+(F.clear===c)+'">'+esc(CLEAR[c]||c)+'</button>'; }).join('')+'</span></div>';
  if (F.kind==='lunch') h += '<p class="rstatnote">Your character goes to the Diner straight away, and back home when lunch ends.</p>';
  h += '<div class="rdacts">'+(s?'<button type="button" class="rbtn" data-statusclearnow="1">Clear status</button>':'')+'<span class="rgrow"></span><button type="button" class="rbtn" data-statuscancel="1">Cancel</button><button class="rbtn rprimary" id="statusSave"'+(F.text.trim()?'':' disabled')+'>Save</button></div>';
  $('statusForm').innerHTML = h;
}

function open() {
  var s = R.stOf(R.me());
  F = s ? {kind:s.kind,emoji:s.emoji,text:s.text,clear:'',grid:false} : {kind:'',emoji:'',text:'',clear:'1h',grid:false};
  /* "Clear after" shows what is left of the one set, as near as the four choices go. */
  if (s) F.clear = s.left===null || s.left===undefined ? 'never' : s.left <= 1800 ? '30m' : s.left <= 3600 ? '1h' : 'today';
  render();
  if ($('lookDlg') && $('lookDlg').open) $('lookDlg').close();
  $('statusDlg').showModal();
  var t = $('statusText'); if (t) t.focus();
}

document.addEventListener('click', function (e) {
  var t = e.target, b;
  if (!t.closest) return;
  if (t.closest('#statusBtn') || t.closest('[data-statusopen]')) { e.preventDefault(); open(); return; }
  if (!t.closest('#statusDlg')) return;
  if ((b = t.closest('[data-statuspreset]'))) {
    var p = preset(b.getAttribute('data-statuspreset')); if (!p) return;
    F.kind = p.kind; F.emoji = p.emoji; F.text = p.text; F.clear = p.clear; F.grid = false; render();
  } else if ((b = t.closest('[data-statusemoji]'))) { F.emoji = b.getAttribute('data-statusemoji'); F.grid = false; F.kind = kindNow(); render(); }
  else if (t.closest('[data-statusgrid]')) { F.grid = !F.grid; render(); }
  else if ((b = t.closest('[data-statusclear]'))) { F.clear = b.getAttribute('data-statusclear'); render(); }
  else if (t.closest('[data-statuscancel]')) $('statusDlg').close();
  else if (t.closest('[data-statusclearnow]')) { $('statusDlg').close(); R.send({set_status:''}).then(label); }
});
document.addEventListener('input', function (e) {
  if (!e.target || e.target.id!=='statusText') return;
  F.text = e.target.value;
  /* Emptied by hand: it is no longer the ready-made one. */
  if (!F.text.trim()) F.kind = '';
  var sv = $('statusSave'); if (sv) sv.disabled = !F.text.trim();
});
$('statusForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var text = F.text.trim(); if (!text) return;
  $('statusDlg').close();
  R.send({set_status:kindNow()||'custom',emoji:F.emoji||'💬',text:text,clear:F.clear||'today'}).then(label);
});

/* Keep the button true: every heartbeat brings the map, and a status may have run out. */
var was = R.onState;
R.onState = function (st) { if (was) was(st); label(); };
label();
setInterval(label, 30000);
})();
