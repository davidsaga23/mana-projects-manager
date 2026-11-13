/* COMMERCIAL: the CRM screens (commercial.php), migration 087.
 *
 * Ported from David's prototype (claude.ai artifact, 2026-11-12): the six
 * views, the drawers, the CSV import and export, and their wording, kept as
 * they were. What changed is where the data lives. The prototype kept it in
 * claude.ai's page runtime; here every save goes to commercial_api.php and the
 * page reloads the data after it. The RULES (stage history, proposal Sent →
 * Proposal stage, quote Approved → deal value, Q-YYYY-NNN numbering, import
 * grouping and duplicate skipping) are applied on the server, in
 * includes/crm.php, so this file only asks for them.
 *
 * Every amount is USD. Filters, folded groups and the outreach period are
 * remembered in this browser, per login. */
(function () {
"use strict";
const ROOT = document.getElementById("crm");
if (!ROOT) return;

const STAGES = [
  { k: "contacted", label: "Contacted", p: 0.05 },
  { k: "interested", label: "Interested", p: 0.25 },
  { k: "proposal", label: "Proposal", p: 0.5 },
  { k: "negotiation", label: "Negotiation", p: 0.75 },
  { k: "won", label: "Won", p: 1 },
  { k: "lost", label: "Lost", p: 0 },
];
const STAGE = Object.fromEntries(STAGES.map(s => [s.k, s]));
const STAGE_IDX = Object.fromEntries(STAGES.map((s, i) => [s.k, i]));
const OPEN = new Set(["contacted", "interested", "proposal", "negotiation"]);
const PIPE = ["interested", "proposal", "negotiation", "won", "lost"];
const PIPE_OPEN = new Set(["interested", "proposal", "negotiation"]);
const SOURCES = ["Email outreach", "Referral", "Inbound", "Event", "Partner", "LinkedIn", "Other"];
const ACT_TYPES = ["Email", "Call", "Networking", "Meeting", "Note"];
const TOUCH_TYPES = ["Email", "Call", "Networking", "Meeting"];
const CHANNELS = ["Email", "Phone", "Networking", "LinkedIn", "Referral", "Other"];
const P_STATUS = [{ k: "todo", label: "To do" }, { k: "progress", label: "In progress" }, { k: "review", label: "Internal review" }, { k: "sent", label: "Sent" }, { k: "accepted", label: "Accepted" }, { k: "declined", label: "Declined" }];
const PS = Object.fromEntries(P_STATUS.map(x => [x.k, x]));
const P_COLOR = { todo: "contacted", progress: "interested", review: "proposal", sent: "negotiation", accepted: "won", declined: "lost" };
const P_DONE = new Set(["sent", "accepted", "declined"]);
const Q_STATUS = [{ k: "draft", label: "Draft" }, { k: "sent", label: "Sent" }, { k: "approved", label: "Approved" }, { k: "rejected", label: "Rejected" }];
const QS = Object.fromEntries(Q_STATUS.map(x => [x.k, x]));
const Q_COLOR = { draft: "contacted", sent: "negotiation", approved: "won", rejected: "lost" };
const VIEWS = ["prospects", "pipeline", "followups", "clients", "proposals", "quotes"];

const $ = s => ROOT.querySelector(s);
const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const money = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 });
const moneyC = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", notation: "compact", maximumFractionDigits: 1 });
const pad = n => String(n).padStart(2, "0");
const ymd = d => d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
const today = () => ymd(new Date());
const parseYmd = s => { const [y, m, d] = s.split("-").map(Number); return new Date(y, m - 1, d); };
const addDays = (s, n) => { const d = parseYmd(s); d.setDate(d.getDate() + n); return ymd(d); };
const dayDiff = s => { const t = new Date(); t.setHours(0, 0, 0, 0); return Math.round((parseYmd(s) - t) / 864e5); };
const fmtDay = s => parseYmd(s).toLocaleDateString("en-US", { month: "short", day: "numeric" });
const fmtWhen = iso => { const d = new Date(iso); return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: d.getFullYear() === new Date().getFullYear() ? undefined : "numeric" }) + " · " + d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" }); };
const ago = iso => { if (!iso) return ""; const days = Math.floor((Date.now() - new Date(iso)) / 864e5); return days <= 0 ? "Today" : days === 1 ? "Yesterday" : days < 30 ? `${days}d ago` : new Date(iso).toLocaleDateString("en-US", { month: "short", day: "numeric" }); };
const rid = () => Math.random().toString(36).slice(2, 10);
const sumVal = list => list.reduce((s, l) => s + (+l.value || 0), 0);

const me = { id: ROOT.dataset.me && ROOT.dataset.me !== "0" ? ROOT.dataset.me : null };
const readOnly = ROOT.dataset.readonly === "1";
// Remembered per login, so two people sharing a browser keep their own filters.
const KEY = k => `anima.crm.${me.id || "anon"}.${k}`;
const store = { get(k, d) { try { const v = localStorage.getItem(KEY(k)); return v ? JSON.parse(v) : d; } catch { return d; } }, set(k, v) { try { localStorage.setItem(KEY(k), JSON.stringify(v)); } catch {} } };

let leads = [], proposals = [], quotes = [], people = {}, owners = [], loaded = false, loadError = "", view = "pipeline";
let sorts = { pipeline: { key: "value", dir: "desc" }, prospects: { key: "createdAt", dir: "desc" } };
const EMPTY_FILTERS = { q: "", stage: "", type: "", source: "", owner: "", min: "", max: "", due: "" };
let filters = Object.assign({}, EMPTY_FILTERS, store.get("filters", {}));
let collapsed = new Set(store.get("collapsed", ["won", "lost"]));
let selected = new Set(), bulkConfirm = false;
let drawer = null, imp = null, toastTimer = null, busy = false;
let outreachDays = store.get("outreach", 30);

/* ---------- talking to the server ---------- */
async function api(action, payload) {
  let res, data;
  try {
    res = await fetch("commercial_api.php", { method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "anima-crm" },
      body: JSON.stringify(Object.assign({ action }, payload || {})) });
    data = await res.json();
  } catch (e) {
    throw new Error("Couldn’t save. Check your connection and try again.");
  }
  if (!data || !data.ok) throw new Error((data && data.error) || "Couldn’t save. Try again.");
  return data;
}
/** Run a save, say what happened, and reload. Returns the response, or null when it failed. */
async function save(action, payload, quiet) {
  if (!writable()) { toast("You have view-only access to these leads."); return null; }
  try {
    const r = await api(action, payload);
    if (!quiet && r.message) toast(r.message);
    await reload();
    return r;
  } catch (e) { toast(e.message); return null; }
}
async function reload() {
  try {
    const res = await fetch("commercial_api.php?action=load", { credentials: "same-origin", headers: { "X-Requested-With": "anima-crm" } });
    const d = await res.json();
    if (!d || !d.ok) throw new Error((d && d.error) || "Couldn’t load the leads.");
    leads = d.leads.map(l => Object.assign(l, { contacts: l.contacts || [], activities: l.activities || [] }));
    proposals = d.proposals; quotes = d.quotes; people = d.people || {}; owners = d.owners || [];
    loaded = true; loadError = ""; setStatus("");
  } catch (e) {
    loaded = true; loadError = e.message || "Couldn’t load the leads.";
    setStatus(loadError + " Reload the page to try again.");
  }
  render();
  if (drawer && drawer.kind === "client") renderClientDrawer();
  else if (drawer && drawer.kind === "lead" && !drawer.isNew) {
    if (!leads.some(l => l.id === drawer.id)) { closeDrawer(); toast("This lead was deleted."); }
    else { renderTimeline(); renderLeadDocs(); }
  }
}

/* ---------- helpers ---------- */
function toast(msg) { const t = $("#toast"); t.textContent = msg; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => t.hidden = true, 2800); }
function setStatus(msg) { const s = $("#status"); s.textContent = msg || ""; s.hidden = !msg; }
function writable() { return loaded && !loadError && !readOnly; }
function dueInfo(l) {
  if (!l.followUp || !OPEN.has(l.stage)) return null;
  const d = dayDiff(l.followUp);
  if (d < 0) return { cls: "over", text: `Overdue ${-d}d`, d };
  if (d === 0) return { cls: "today", text: "Due today", d };
  if (d <= 7) return { cls: "soon", text: d === 1 ? "Tomorrow" : fmtDay(l.followUp), d };
  return { cls: "", text: fmtDay(l.followUp), d };
}
const initials = n => String(n || "?").split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join("") || "?";
function avatar(uid) {
  if (!uid) return `<span class="av-empty" title="Unassigned"></span>`;
  const p = people[uid];
  if (!p) return `<span class="av"></span>`;
  return p.avatar ? `<img class="av" src="${esc(p.avatar)}" alt="" title="${esc(p.name)}">` : `<span class="av" title="${esc(p.name)}">${esc(initials(p.name))}</span>`;
}
const personName = uid => uid ? ((people[uid] && people[uid].name) || "Someone") : "Unassigned";
const distinct = key => [...new Set(leads.map(l => (l[key] || "").trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
const dotC = k => `<span class="cdot" style="--c: var(--s-${k})"></span>`;

/* ---------- contacts ---------- */
const blankContact = () => ({ id: "n" + rid(), name: "", title: "", email: "", phone: "", channel: "Email", contactedAt: today(), lastContactAt: null });
const lastTouch = l => [l.lastContactAt, ...l.contacts.map(c => c.lastContactAt)].filter(Boolean).sort().pop() || null;
const emailsOf = list => [...new Set(list.flatMap(l => l.contacts.map(c => (c.email || "").trim()).filter(Boolean)))];
function copyText(txt, label) {
  const fallback = () => { const ta = document.createElement("textarea"); ta.value = txt; ta.style.position = "fixed"; ta.style.opacity = "0"; document.body.appendChild(ta); ta.select(); let ok = false; try { ok = document.execCommand("copy"); } catch {} ta.remove(); toast(ok ? label : "Couldn’t copy. Select the text and copy it by hand."); };
  try { navigator.clipboard.writeText(txt).then(() => toast(label), fallback); } catch { fallback(); }
}
function syncContacts() {
  if (!drawer || !drawer.draft || !$("#contacts-ed")) return;
  drawer.draft.contacts.forEach((c, i) => ["name", "title", "email", "phone", "channel", "contactedAt"].forEach(k => { const el = $(`#c-${k}-${i}`); if (el) c[k] = el.value.trim(); }));
}
function contactFields() {
  syncContacts();
  return { contacts: drawer.draft.contacts.filter(c => c.name || c.email || c.phone || c.title) };
}
function renderContactsEd() {
  const el = $("#contacts-ed"); if (!el) return;
  const ro = !writable(), cs = drawer.draft.contacts;
  const f = (i, k, label, val, type = "text") => `<div class="field"><label for="c-${k}-${i}">${label}</label><input id="c-${k}-${i}" type="${type}" value="${esc(val)}" ${ro ? "readonly" : ""}></div>`;
  el.innerHTML = cs.length ? cs.map((c, i) => `<div class="ct-ed"><div class="ct-h"><strong>${i === 0 ? "Primary contact" : "Contact " + (i + 1)}</strong>${c.lastContactAt ? `<span class="small muted">Last touch ${esc(ago(c.lastContactAt))}</span>` : ""}<span class="sp"></span>${!ro && i > 0 ? `<button type="button" class="btn sm link" data-c-primary="${i}">Make primary</button>` : ""}${!ro ? `<button type="button" class="btn sm link bad" data-c-del="${i}">Remove</button>` : ""}</div>
    <div class="grid2">${f(i, "name", "Name", c.name)}${f(i, "title", "Role / title", c.title)}${f(i, "email", "Email", c.email, "email")}${f(i, "phone", "Phone", c.phone, "tel")}
    <div class="field"><label for="c-channel-${i}">How we reached them</label><select id="c-channel-${i}" ${ro ? "disabled" : ""}>${CHANNELS.map(ch => `<option ${ch === c.channel ? "selected" : ""}>${ch}</option>`).join("")}</select></div>${f(i, "contactedAt", "First contacted", c.contactedAt || "", "date")}</div></div>`).join("")
    : `<p class="small muted">No contacts yet.</p>`;
}

/* ---------- filtering ---------- */
function applyFilters(list, ignoreStage) {
  const f = filters, q = f.q.trim().toLowerCase();
  const min = f.min === "" ? null : +f.min, max = f.max === "" ? null : +f.max;
  return list.filter(l => {
    if (q && ![l.company, ...l.contacts.flatMap(c => [c.name, c.title, c.email]), l.projectType, l.nextStep, (l.tags || []).join(" ")].join(" ").toLowerCase().includes(q)) return false;
    if (!ignoreStage && f.stage) { if (f.stage === "open" ? !OPEN.has(l.stage) : l.stage !== f.stage) return false; }
    if (f.type && (l.projectType || "") !== f.type) return false;
    if (f.source && (l.source || "") !== f.source) return false;
    if (f.owner === "me" ? l.ownerId !== me.id : f.owner === "none" ? !!l.ownerId : f.owner && l.ownerId !== f.owner) return false;
    const v = +l.value || 0;
    if (min != null && v < min) return false;
    if (max != null && v > max) return false;
    if (f.due) {
      const d = l.followUp ? dayDiff(l.followUp) : null;
      if (f.due === "overdue" && !(d != null && d < 0 && OPEN.has(l.stage))) return false;
      if (f.due === "week" && !(d != null && d >= 0 && d <= 7)) return false;
      if (f.due === "none" && l.followUp) return false;
    }
    return true;
  });
}
const activeFilterCount = () => Object.keys(EMPTY_FILTERS).filter(k => filters[k] !== "").length;
function stageOptionsFor(v) {
  if (v === "pipeline") return [["", "All pipeline stages"], ["open", "Open deals only"], ...PIPE.map(k => [k, STAGE[k].label])];
  if (v === "followups") return [["", "All open stages"], ...[...OPEN].map(k => [k, STAGE[k].label])];
  if (v === "clients") return [["", "Any status"], ["open", "Has open deals"], ...STAGES.map(s => [s.k, `Has ${s.label.toLowerCase()} deals`])];
  return null;
}
function fillSelect(el, opts, value) {
  const html = opts.map(([v, t]) => `<option value="${esc(v)}">${esc(t)}</option>`).join("");
  if (el.dataset.html !== html) { el.innerHTML = html; el.dataset.html = html; }
  el.value = opts.some(o => o[0] === value) ? value : "";
  el.classList.toggle("is-on", el.value !== "");
}
function renderFilters() {
  const docV = view === "proposals" || view === "quotes";
  $("#flt-source").hidden = docV; $("#flt-due").hidden = docV; $(".filters .range").hidden = docV; $("#flt-owner").hidden = view === "quotes";
  $("#flt-q").placeholder = docV ? "Proposal, quote or client" : "Client, contact, email or tag";
  const so = stageOptionsFor(view), st = $("#flt-stage");
  st.hidden = !so;
  if (so) { if (!so.some(o => o[0] === filters.stage)) filters.stage = ""; fillSelect(st, so, filters.stage); }
  else filters.stage = "";
  const types = distinct("projectType");
  if (filters.type && !types.includes(filters.type)) types.push(filters.type);
  fillSelect($("#flt-type"), [["", "All project types"], ...types.map(t => [t, t])], filters.type);
  const srcs = [...new Set([...SOURCES, ...distinct("source")])];
  fillSelect($("#flt-source"), [["", "All sources"], ...srcs.map(s => [s, s])], filters.source);
  const ownerIds = [...new Set([...leads.map(l => l.ownerId), ...proposals.map(p => p.ownerId)].filter(id => id && id !== me.id))];
  const oo = [["", "Any owner"], ...(me.id ? [["me", "Mine"]] : []), ["none", "Unassigned"], ...ownerIds.map(id => [id, personName(id)])];
  if (filters.owner && !oo.some(o => o[0] === filters.owner)) oo.push([filters.owner, personName(filters.owner)]);
  fillSelect($("#flt-owner"), oo, filters.owner);
  const fd = $("#flt-due"); fd.value = filters.due; fd.classList.toggle("is-on", !!filters.due);
  const q = $("#flt-q"); if (document.activeElement !== q) q.value = filters.q; q.classList.toggle("is-on", !!filters.q);
  ["min", "max"].forEach(k => { const el = $("#flt-" + k); if (document.activeElement !== el) el.value = filters[k]; el.classList.toggle("is-on", filters[k] !== ""); });
  $("#flt-clear").hidden = !activeFilterCount();
}
function setFilter(k, v) { filters[k] = v; store.set("filters", filters); render(); }

/* ---------- writes ---------- */
async function moveStage(id, stage) {
  const l = leads.find(x => x.id === id); if (!l || l.stage === stage) return;
  await save("lead_stage", { id, stage });
}
async function reschedule(id, days) { await save("lead_snooze", { id, days }); }
async function bulk(action, extra) {
  const ids = [...selected];
  selected.clear(); bulkConfirm = false;
  if (!await save(action, Object.assign({ ids }, extra || {}))) render();
}

/* ---------- rendering ---------- */
function render() {
  ROOT.querySelectorAll(".crm-tab").forEach(a => a.setAttribute("aria-current", a.dataset.view === view ? "page" : "false"));
  $("#view-title").textContent = VIEW_TITLES[view];
  const w = writable();
  const docV = view === "proposals" || view === "quotes";
  $("#new-lead").hidden = !w || docV; $("#import").hidden = !w || docV;
  $("#new-prop").hidden = !w || view !== "proposals"; $("#new-quote").hidden = !w || view !== "quotes";
  $("#export").hidden = !leads.length || docV;
  $("#c-clients").textContent = loaded ? new Set(leads.map(l => (l.company || "").trim().toLowerCase()).filter(Boolean)).size : "";
  $("#c-proposals").textContent = loaded ? proposals.filter(p => !["accepted", "declined"].includes(p.status)).length : "";
  $("#c-quotes").textContent = loaded ? quotes.filter(q => q.status === "draft" || q.status === "sent").length : "";
  $("#c-prospects").textContent = loaded ? leads.filter(l => l.stage === "contacted").length : "";
  $("#c-pipeline").textContent = loaded ? leads.filter(l => PIPE_OPEN.has(l.stage)).length : "";
  const overdue = leads.filter(l => { const d = dueInfo(l); return d && d.d <= 0; }).length;
  const c = $("#c-followups"); c.textContent = overdue; c.hidden = !overdue; c.classList.add("bad");
  renderFilters(); renderKpis();
  const ids = new Set(leads.map(l => l.id)); [...selected].forEach(id => ids.has(id) || selected.delete(id));

  if (loadError && !leads.length) { $("#view").innerHTML = emptyState("Leads can’t load", loadError, false); return; }
  if (view === "proposals") { renderProposals(); return; }
  if (view === "quotes") { renderQuotes(); return; }
  if (!leads.length) {
    $("#view").innerHTML = loaded ? emptyState("No leads yet", "Import your outreach list as a CSV or add a lead by hand. New leads start as Contacted in Prospects and move to the Pipeline once they’re Interested.", w)
      : `<div class="empty"><p>Loading leads…</p></div>`;
    return;
  }
  if (view === "pipeline") renderPipeline();
  else if (view === "prospects") renderProspects();
  else if (view === "clients") renderClients();
  else renderFollowups();
}
function emptyState(h, p, btn) {
  return `<div class="empty"><h2>${esc(h)}</h2><p>${esc(p)}</p>${btn ? `<div class="crm-actions"><button type="button" class="btn primary" data-act="import">Import CSV</button><button type="button" class="btn" data-act="new">+ Add a lead</button></div>` : ""}</div>`;
}
function noMatch(what) {
  return `<div class="empty"><h2>No ${what} match these filters</h2><p>Try a wider value range or another status.</p><div class="crm-actions"><button type="button" class="btn" data-act="clear-filters">Clear filters</button></div></div>`;
}

function renderKpis() {
  const base = applyFilters(leads, true);
  const prospects = base.filter(l => l.stage === "contacted");
  const recent = prospects.filter(l => { const t = lastTouch(l) || l.createdAt; return t && Date.now() - new Date(t) < 7 * 864e5; }).length;
  const open = base.filter(l => PIPE_OPEN.has(l.stage));
  const weighted = open.reduce((s, l) => s + (+l.value || 0) * STAGE[l.stage].p, 0);
  const now = new Date(), qStart = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
  const wonQ = base.filter(l => l.stage === "won" && l.closedAt && new Date(l.closedAt) >= qStart);
  const won = base.filter(l => l.stage === "won").length, lost = base.filter(l => l.stage === "lost").length;
  const overdue = base.filter(l => OPEN.has(l.stage) && l.followUp && dayDiff(l.followUp) < 0).length;
  const dueToday = base.filter(l => OPEN.has(l.stage) && l.followUp && dayDiff(l.followUp) === 0).length;
  const conv = base.length ? Math.round(base.filter(l => l.stage !== "contacted").length / base.length * 100) : 0;
  $("#kpis").innerHTML = `
    <div class="kpi"><div class="lbl">Prospects</div><div class="val">${prospects.length}</div><div class="csub">${(n => `${n} contact${n === 1 ? "" : "s"}`)(prospects.reduce((n, l) => n + l.contacts.length, 0))} · ${recent} touched this week</div></div>
    <div class="kpi"><div class="lbl">Open pipeline</div><div class="val">${moneyC.format(sumVal(open))}</div><div class="csub">${open.length} interested or further · ${conv}% of all leads</div></div>
    <div class="kpi"><div class="lbl">Weighted forecast</div><div class="val">${moneyC.format(weighted)}</div><div class="csub">Value × stage probability</div></div>
    <div class="kpi"><div class="lbl">Won Q${Math.floor(now.getMonth() / 3) + 1}</div><div class="val">${moneyC.format(sumVal(wonQ))}</div><div class="csub">${wonQ.length} deal${wonQ.length === 1 ? "" : "s"} · win rate ${won + lost ? Math.round(won / (won + lost) * 100) + "%" : "—"}</div></div>
    <div class="kpi"><div class="lbl">Follow-ups</div><div class="val ${overdue ? "bad" : ""}">${overdue}</div><div class="csub">overdue · ${dueToday} due today</div></div>`;
  const n = activeFilterCount() - (filters.stage ? 1 : 0);
  const note = $("#kpi-note"); note.hidden = !n; note.textContent = n ? `Totals reflect ${n} active filter${n === 1 ? "" : "s"}.` : "";
}

/* columns */
const stageChip = k => `<span class="cchip stage">${dotC(k)}${esc(STAGE[k].label)}</span>`;
const stageCell = l => writable()
  ? `<select class="stage-sel" data-stage-for="${esc(l.id)}" aria-label="Status for ${esc(l.company)}">${STAGES.map(s => `<option value="${s.k}" ${s.k === l.stage ? "selected" : ""}>${s.label}</option>`).join("")}</select>`
  : stageChip(l.stage);
const COL = {
  company: { label: "Client", sort: l => (l.company || "").toLowerCase(), cell: l => { const p = l.contacts[0], more = l.contacts.length - 1; return `<div class="co">${esc(l.company || "Untitled lead")}</div>${p ? `<div class="csub">${esc([p.name || p.email, p.title].filter(Boolean).join(" · "))}${more > 0 ? ` · +${more} more` : ""}</div>` : ""}`; } },
  type: { label: "Project type", sort: l => (l.projectType || "￿").toLowerCase(), cell: l => esc(l.projectType || "—") },
  stage: { label: "Status", sort: l => STAGE_IDX[l.stage], cell: stageCell },
  value: { label: "Value", r: true, sort: l => +l.value || 0, cell: l => `<span class="num">${l.value ? money.format(+l.value) : "—"}</span>` },
  owner: { label: "Owner", sort: l => personName(l.ownerId).toLowerCase(), cell: l => `<span class="person">${avatar(l.ownerId)}<span>${esc(personName(l.ownerId))}</span></span>` },
  source: { label: "Source", sort: l => (l.source || "￿").toLowerCase(), cell: l => esc(l.source || "—") },
  touch: { label: "Last touch", sort: l => lastTouch(l) || l.createdAt || "", cell: l => `<span class="small">${esc(ago(lastTouch(l) || l.createdAt) || "—")}</span>` },
  followUp: { label: "Follow-up", sort: l => l.followUp || "9999", cell: l => { const d = dueInfo(l); return d ? `<span class="cchip ${d.cls}">${esc(d.text)}</span>` : l.followUp ? `<span class="small muted">${fmtDay(l.followUp)}</span>` : `<span class="muted">—</span>`; } },
  nextStep: { label: "Next step", wrap: true, sort: l => (l.nextStep || "￿").toLowerCase(), cell: l => l.nextStep ? esc(l.nextStep) : `<span class="muted">—</span>` },
  createdAt: { label: "Added", sort: l => l.createdAt || "", cell: l => `<span class="small muted">${l.createdAt ? new Date(l.createdAt).toLocaleDateString("en-US", { month: "short", day: "numeric" }) : "—"}</span>` },
  updatedAt: { label: "Updated", sort: l => l.updatedAt || "", cell: l => `<span class="small muted">${esc(ago(l.updatedAt) || "—")}</span>` },
};
const VIEW_COLS = {
  pipeline: ["company", "type", "stage", "value", "owner", "followUp", "nextStep", "updatedAt"],
  prospects: ["company", "type", "source", "owner", "touch", "followUp", "stage", "createdAt"],
};
function sortList(list, v) {
  const s = sorts[v], c = COL[s.key];
  return list.slice().sort((a, b) => { const x = c.sort(a), y = c.sort(b); return (x < y ? -1 : x > y ? 1 : 0) * (s.dir === "asc" ? 1 : -1); });
}
function tableHtml(list, v, groups) {
  const w = writable(), cols = VIEW_COLS[v].map(k => [k, COL[k]]), span = cols.length + (w ? 1 : 0), s = sorts[v];
  const allSel = list.length && list.every(l => selected.has(l.id));
  const head = `<tr>${w ? `<th class="sel"><input type="checkbox" data-sel-all="${v}" aria-label="Select all shown" ${allSel ? "checked" : ""}></th>` : ""}${cols.map(([k, c]) =>
    `<th class="${c.r ? "r" : ""}" aria-sort="${s.key === k ? (s.dir === "asc" ? "ascending" : "descending") : "none"}"><button type="button" data-sort="${k}">${c.label}${s.key === k ? (s.dir === "asc" ? " ↑" : " ↓") : ""}</button></th>`).join("")}</tr>`;
  const row = l => `<tr data-id="${esc(l.id)}" tabindex="0" class="${selected.has(l.id) ? "is-sel" : ""}">${w ? `<td class="sel"><input type="checkbox" data-sel="${esc(l.id)}" ${selected.has(l.id) ? "checked" : ""} aria-label="Select ${esc(l.company)}"></td>` : ""}${cols.map(([, c]) => `<td class="${c.r ? "r" : ""} ${c.wrap ? "wrapcell" : ""}">${c.cell(l)}</td>`).join("")}</tr>`;
  const body = groups
    ? groups.map(g => { const col = collapsed.has(g.k); return `<tbody><tr class="cgrp"><td colspan="${span}"><button type="button" class="cgrp-btn" data-grp="${g.k}" aria-expanded="${!col}"><span class="ccaret">${col ? "▸" : "▾"}</span>${dotC(g.k)}${STAGE[g.k].label}<span class="n">${g.items.length}</span><span class="m num">${money.format(sumVal(g.items))}</span></button></td></tr>${col ? "" : (g.items.map(row).join("") || `<tr class="none"><td colspan="${span}">No leads in ${STAGE[g.k].label}</td></tr>`)}</tbody>`; }).join("")
    : `<tbody>${list.map(row).join("")}</tbody>`;
  const valIdx = VIEW_COLS[v].indexOf("value");
  const foot = `<tfoot><tr><td colspan="${span}" class="small muted">${list.length} lead${list.length === 1 ? "" : "s"}${valIdx >= 0 ? ` · total ${money.format(sumVal(list))}` : ""}</td></tr></tfoot>`;
  return bulkHtml() + `<div class="crm-scroll"><table><thead>${head}</thead>${body}${foot}</table></div>`;
}
function bulkHtml() {
  if (!writable() || !selected.size) return "";
  const n = selected.size;
  return `<div class="bulk"><strong>${n} selected</strong>
    <label class="small">Move to <select id="bulk-stage">${STAGES.map(s => `<option value="${s.k}">${s.label}</option>`).join("")}</select></label><button type="button" class="btn sm" data-act="bulk-move">Move</button>
    ${me.id && owners.includes(me.id) ? `<button type="button" class="btn sm" data-act="bulk-me">Assign to me</button>` : ""}<button type="button" class="btn sm" data-act="bulk-copy">Copy emails</button>
    ${bulkConfirm ? `<span class="confirm small">Delete ${n} lead${n === 1 ? "" : "s"}?<button type="button" class="btn sm bad solid" data-act="bulk-del-yes">Delete</button><button type="button" class="btn sm" data-act="bulk-del-no">Keep</button></span>` : `<button type="button" class="btn sm bad" data-act="bulk-del">Delete</button>`}
    <span class="sp"></span><button type="button" class="btn sm link" data-act="bulk-clear">Clear selection</button></div>`;
}

function renderPipeline() {
  const base = applyFilters(leads, true), list = applyFilters(leads).filter(l => l.stage !== "contacted");
  const funnel = `<div class="funnel">${STAGES.filter(s => s.k !== "lost").map(s => {
    const items = base.filter(l => l.stage === s.k);
    const on = s.k === "contacted" ? false : filters.stage === s.k;
    return `<button type="button" class="fstep ${on ? "is-on" : ""}" data-funnel="${s.k}" style="--c: var(--s-${s.k})"><span class="t">${s.label}${s.k === "contacted" ? " · Prospects" : ""}</span><span class="n">${items.length}</span><span class="m">${s.k === "contacted" ? "Open the Prospects tab" : moneyC.format(sumVal(items))}</span></button>`;
  }).join("")}</div>`;
  if (!list.length) { $("#view").innerHTML = funnel + (activeFilterCount() ? noMatch("deals") : `<div class="empty"><h2>No interested leads yet</h2><p>When a prospect replies with interest, set its status to Interested and it appears here.</p></div>`); return; }
  const sorted = sortList(list, "pipeline");
  const groups = PIPE.filter(k => !filters.stage || filters.stage === k || (filters.stage === "open" && PIPE_OPEN.has(k))).map(k => ({ k, items: sorted.filter(l => l.stage === k) }));
  $("#view").innerHTML = funnel + tableHtml(sorted, "pipeline", groups);
}
function renderProspects() {
  const list = sortList(applyFilters(leads).filter(l => l.stage === "contacted"), "prospects");
  if (!list.length) {
    $("#view").innerHTML = activeFilterCount() ? outreachHtml() + noMatch("prospects")
      : outreachHtml() + `<div class="empty"><h2>No prospects waiting</h2><p>Every company you reach out to starts here as Contacted, with all the people you emailed listed under it. Import an outreach list or add one by hand.</p>${writable() ? `<div class="crm-actions"><button type="button" class="btn primary" data-act="import">Import CSV</button><button type="button" class="btn" data-act="new">+ Add a lead</button></div>` : ""}</div>`;
    return;
  }
  const w = writable(), s = sorts.prospects, emails = emailsOf(list), nC = list.reduce((n, l) => n + l.contacts.length, 0);
  const th = (k, label) => COL[k] ? `<th aria-sort="${s.key === k ? (s.dir === "asc" ? "ascending" : "descending") : "none"}"><button type="button" data-sort="${k}">${label}${s.key === k ? (s.dir === "asc" ? " ↑" : " ↓") : ""}</button></th>` : `<th>${label}</th>`;
  const allSel = list.every(l => selected.has(l.id));
  const head = `<tr>${w ? `<th class="sel"><input type="checkbox" data-sel-all="prospects" ${allSel ? "checked" : ""} aria-label="Select all shown"></th>` : ""}${th("company", "Client / contact")}${th("_r", "Role")}${th("_e", "Email")}${th("_p", "Phone")}${th("touch", "Last touch")}${th("followUp", "Follow-up")}${th("type", "Project type")}${th("owner", "Owner")}${th("stage", "Status")}</tr>`;
  const selCell = l => w ? `<td class="sel"><input type="checkbox" data-sel="${esc(l.id)}" ${selected.has(l.id) ? "checked" : ""} aria-label="Select ${esc(l.company)}"></td>` : "";
  const body = list.map(l => {
    const co = `<tr class="co-row ${selected.has(l.id) ? "is-sel" : ""}" data-id="${esc(l.id)}" tabindex="0">${selCell(l)}
      <td colspan="4"><span class="co">${esc(l.company || "Untitled lead")}</span> <span class="small muted">· ${l.contacts.length} contact${l.contacts.length === 1 ? "" : "s"}</span></td>
      <td>${COL.touch.cell(l)}</td><td>${COL.followUp.cell(l)}</td><td>${COL.type.cell(l)}</td><td>${COL.owner.cell(l)}</td><td>${stageCell(l)}</td></tr>`;
    const cs = l.contacts.length ? l.contacts.map((c, i) => `<tr class="ct-row" data-id="${esc(l.id)}">${w ? `<td class="sel"></td>` : ""}
      <td class="ct-name">${esc(c.name || "—")} <span class="cchip">${esc(c.channel)}</span>${i === 0 && l.contacts.length > 1 ? ` <span class="cchip">Primary</span>` : ""}</td><td class="small">${esc(c.title || "—")}</td>
      <td class="nowrap">${c.email ? `<span class="copyable">${esc(c.email)}</span><button type="button" class="btn sm link" data-copy="${esc(c.email)}" aria-label="Copy ${esc(c.email)}">Copy</button>` : `<span class="muted">—</span>`}</td>
      <td class="small nowrap">${esc(c.phone || "—")}</td><td class="small muted">${esc(c.lastContactAt ? ago(c.lastContactAt) : "—")}</td><td colspan="4"></td></tr>`).join("")
      : `<tr class="ct-row" data-id="${esc(l.id)}">${w ? `<td class="sel"></td>` : ""}<td colspan="9" class="ct-name small muted">No contacts yet. Open the lead to add people.</td></tr>`;
    return `<tbody class="co-group">${co}${cs}</tbody>`;
  }).join("");
  $("#view").innerHTML = outreachHtml() + bulkHtml() + `<div class="tbl-tools"><span class="small muted">${list.length} compan${list.length === 1 ? "y" : "ies"} · ${nC} contact${nC === 1 ? "" : "s"}</span>${emails.length ? `<button type="button" class="btn sm" data-act="copy-shown">Copy ${emails.length} email${emails.length === 1 ? "" : "s"}</button>` : ""}</div><div class="crm-scroll"><table class="grouped"><thead>${head}</thead>${body}</table></div>`;
}
function renderFollowups() {
  const open = applyFilters(leads).filter(l => OPEN.has(l.stage));
  const groups = [
    { t: "Overdue", f: l => l.followUp && dayDiff(l.followUp) < 0 },
    { t: "Today", f: l => l.followUp && dayDiff(l.followUp) === 0 },
    { t: "Next 7 days", f: l => l.followUp && dayDiff(l.followUp) > 0 && dayDiff(l.followUp) <= 7 },
    { t: "Later", f: l => l.followUp && dayDiff(l.followUp) > 7 },
    { t: "No follow-up set", f: l => !l.followUp },
  ];
  const w = writable();
  $("#view").innerHTML = `<div class="fu">${groups.map(g => {
    const items = open.filter(g.f).sort((a, b) => (a.followUp || "").localeCompare(b.followUp || ""));
    if (!items.length) return "";
    return `<section><h3>${g.t}<span class="n">${items.length}</span></h3><div class="fu-list">${items.map(l => { const due = dueInfo(l); return `<div class="fu-row">
      <div><div class="co" data-open="${esc(l.id)}" tabindex="0" role="button">${esc(l.company || "Untitled lead")}</div><div class="small muted">${esc([l.contacts[0]?.name, STAGE[l.stage].label, l.projectType].filter(Boolean).join(" · "))}</div></div>
      <div class="step">${l.nextStep ? esc(l.nextStep) : `<span class="small muted">No next step written</span>`}</div>
      <div>${due ? `<span class="cchip ${due.cls}">${esc(due.text)}</span>` : `<span class="cchip">Not set</span>`}</div>
      <div class="acts">${w ? `<button type="button" class="btn sm" data-snooze="1" data-id="${esc(l.id)}" title="Move follow-up 1 day later">+1d</button><button type="button" class="btn sm" data-snooze="7" data-id="${esc(l.id)}" title="Move follow-up 1 week later">+1w</button>` : ""}<button type="button" class="btn sm" data-open="${esc(l.id)}">Log</button></div>
    </div>`; }).join("")}</div></section>`;
  }).join("") || (activeFilterCount() ? noMatch("follow-ups") : `<div class="empty"><h2>Nothing to chase</h2><p>Open leads with a follow-up date show up here, overdue ones first.</p></div>`)}</div>`;
}

/* ---------- clients ---------- */
const VIEW_TITLES = { pipeline: "Pipeline", prospects: "Prospects", followups: "Follow-ups", clients: "Clients", proposals: "Proposals", quotes: "Quotes" };
const clientKey = l => (l.company || "").trim().toLowerCase();
function relationship(ls) {
  if (ls.some(l => l.stage === "won")) return { t: "Client", k: "won" };
  if (ls.some(l => PIPE_OPEN.has(l.stage))) return { t: "In pipeline", k: "proposal" };
  if (ls.some(l => l.stage === "contacted")) return { t: "Prospect", k: "contacted" };
  return { t: "Lost", k: "lost" };
}
function clientGroups(list) {
  const m = new Map();
  for (const l of list) { const k = clientKey(l); if (!k) continue; if (!m.has(k)) m.set(k, { key: k, name: l.company.trim(), leads: [] }); m.get(k).leads.push(l); }
  return [...m.values()].map(g => ({ ...g,
    contacts: [...new Set(g.leads.flatMap(l => l.contacts.map(c => c.name || c.email)).filter(Boolean))],
    types: [...new Set(g.leads.map(l => l.projectType).filter(Boolean))],
    open: sumVal(g.leads.filter(l => PIPE_OPEN.has(l.stage))), won: sumVal(g.leads.filter(l => l.stage === "won")),
    last: g.leads.map(l => l.lastContactAt || l.updatedAt || "").sort().pop(), rel: relationship(g.leads) }));
}
function renderClients() {
  const groups = clientGroups(applyFilters(leads)).sort((a, b) => (b.won + b.open) - (a.won + a.open) || a.name.localeCompare(b.name));
  if (!groups.length) { $("#view").innerHTML = activeFilterCount() ? noMatch("clients") : `<div class="empty"><h2>No clients yet</h2><p>Every company you add as a lead shows up here with all its deals in one place.</p></div>`; return; }
  $("#view").innerHTML = `<div class="crm-scroll"><table><thead><tr><th>Client</th><th>Contacts</th><th>Project types</th><th>Relationship</th><th class="r">Deals</th><th class="r">Open pipeline</th><th class="r">Won</th><th>Last activity</th></tr></thead><tbody>${groups.map(g => `
    <tr data-client="${esc(g.key)}" tabindex="0"><td><div class="co">${esc(g.name)}</div></td>
    <td class="wrapcell small">${esc(g.contacts.join(", ") || "—")}</td><td class="wrapcell small">${esc(g.types.join(", ") || "—")}</td>
    <td><span class="cchip stage">${dotC(g.rel.k)}${g.rel.t}</span></td>
    <td class="r num">${g.leads.length}</td><td class="r num">${g.open ? money.format(g.open) : "—"}</td><td class="r num">${g.won ? money.format(g.won) : "—"}</td>
    <td class="small muted">${esc(ago(g.last) || "—")}</td></tr>`).join("")}</tbody>
    <tfoot><tr><td colspan="8" class="small muted">${groups.length} client${groups.length === 1 ? "" : "s"} · ${money.format(groups.reduce((s, g) => s + g.won, 0))} won · ${money.format(groups.reduce((s, g) => s + g.open, 0))} open</td></tr></tfoot></table></div>`;
}
function openClient(key) { drawer = { kind: "client", client: key }; renderClientDrawer(true); }
function renderClientDrawer(focus) {
  const g = clientGroups(leads.filter(l => clientKey(l) === drawer.client))[0];
  if (!g) { closeDrawer(); return; }
  const ls = g.leads.slice().sort((a, b) => STAGE_IDX[a.stage] - STAGE_IDX[b.stage]);
  const ppl = [...new Map(g.leads.flatMap(l => l.contacts).filter(c => c.name || c.email).map(c => [(c.email || c.name).toLowerCase(), c])).values()];
  $("#drawer-root").innerHTML = `<div class="scrim" data-act="close"></div>
  <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="d-title">
    <div class="d-head"><h2 id="d-title">${esc(g.name)}</h2><span class="cchip stage">${dotC(g.rel.k)}${g.rel.t}</span><button type="button" class="x" data-act="close" aria-label="Close">×</button></div>
    <div class="d-body">
      <div class="sum"><span><b>${g.leads.length}</b> deal${g.leads.length === 1 ? "" : "s"}</span><span>Open <b>${money.format(g.open)}</b></span><span>Won <b>${money.format(g.won)}</b></span></div>
      <div class="sec"><h4>Deals</h4><div class="fu-list">${ls.map(l => `<div class="fu-row">
        <div><div class="co" data-open="${esc(l.id)}" tabindex="0" role="button">${esc(l.projectType || l.nextStep || "Deal")}</div><div class="small muted">${esc([l.contacts.map(c => c.name).filter(Boolean).join(", "), ago(l.updatedAt)].filter(Boolean).join(" · "))}</div></div>
        <div class="step small">${esc(l.nextStep || "")}</div>
        <div>${stageChip(l.stage)}</div>
        <div class="num">${l.value ? money.format(+l.value) : "—"}</div></div>`).join("")}</div></div>
      <div class="sec"><h4>Contacts</h4>${ppl.length ? `<ul class="timeline">${ppl.map(c => `<li><div class="ty">Contact</div><div><div class="txt">${esc(c.name || c.email)}${c.title ? ` · ${esc(c.title)}` : ""}</div><div class="meta">${esc([c.email, c.phone].filter(Boolean).join(" · ") || "No email or phone saved")}</div></div></li>`).join("")}</ul>` : `<p class="small muted">No contacts saved yet.</p>`}</div>
    </div>
    <div class="d-foot"><span class="sp"></span><button type="button" class="btn" data-act="close">Close</button>${writable() ? `<button type="button" class="btn primary" data-act="client-deal">+ New deal for ${esc(g.name)}</button>` : ""}</div>
  </aside>`;
  if (focus) setTimeout(() => $(".x")?.focus(), 0);
}

/* ---------- outreach ---------- */
function outreachHtml() {
  const since = outreachDays ? Date.now() - outreachDays * 864e5 : 0;
  const ls = applyFilters(leads, true);
  const ppl = ls.flatMap(l => l.contacts.map(c => ({ c, l }))).filter(({ c }) => c.contactedAt && parseYmd(c.contactedAt).getTime() >= since);
  const by = ch => ppl.filter(x => x.c.channel === ch).length;
  const companies = new Set(ppl.map(x => x.l.id)).size;
  const acts = ls.flatMap(l => l.activities || []).filter(a => TOUCH_TYPES.includes(a.type) && new Date(a.at).getTime() >= since);
  const at = t => acts.filter(a => a.type === t).length;
  const other = ppl.length - by("Email") - by("Phone") - by("Networking");
  return `<section class="outreach" aria-label="Outreach"><div class="o-head"><h3>Outreach</h3><select id="o-period" aria-label="Period">${[[7, "Last 7 days"], [30, "Last 30 days"], [90, "Last 90 days"], [0, "All time"]].map(([v, t]) => `<option value="${v}" ${v === outreachDays ? "selected" : ""}>${t}</option>`).join("")}</select><span class="small muted">Counts every lead, including ones that moved on to the Pipeline.</span></div>
  <div class="o-grid">
    <div class="o-tile"><span class="lbl">People contacted</span><span class="val">${ppl.length}</span><span class="csub">at ${companies} compan${companies === 1 ? "y" : "ies"}</span></div>
    <div class="o-tile"><span class="lbl">By email</span><span class="val">${by("Email")}</span></div>
    <div class="o-tile"><span class="lbl">By phone</span><span class="val">${by("Phone")}</span></div>
    <div class="o-tile"><span class="lbl">Networking</span><span class="val">${by("Networking")}</span></div>
    <div class="o-tile"><span class="lbl">Other channels</span><span class="val">${other}</span><span class="csub">LinkedIn, referral, other</span></div>
    <div class="o-tile"><span class="lbl">Touches logged</span><span class="val">${acts.length}</span><span class="csub">${at("Email")} emails · ${at("Call")} calls · ${at("Networking")} networking · ${at("Meeting")} meetings</span></div>
  </div></section>`;
}

/* ---------- proposals & quotes ---------- */
const leadOf = id => id ? leads.find(l => l.id === id) : null;
const leadLabel = id => leadOf(id)?.company || (id ? "Deleted client" : "No client");
const statusChip = (M, C, k) => `<span class="cchip stage">${dotC(C[k])}${esc(M[k]?.label || k)}</span>`;
function quoteTotals(q) {
  const sub = (q.items || []).reduce((s, i) => s + (+i.qty || 0) * (+i.rate || 0), 0);
  const disc = sub * (+q.discountPct || 0) / 100, tax = (sub - disc) * (+q.taxPct || 0) / 100;
  return { sub, disc, tax, total: sub - disc + tax };
}
function docMatch(title, leadId, ownerId, useOwner) {
  const l = leadOf(leadId), q = filters.q.trim().toLowerCase();
  if (q && !`${title || ""} ${l?.company || ""}`.toLowerCase().includes(q)) return false;
  if (filters.type && (l?.projectType || "") !== filters.type) return false;
  if (useOwner && (filters.owner === "me" ? ownerId !== me.id : filters.owner === "none" ? !!ownerId : filters.owner && ownerId !== filters.owner)) return false;
  return true;
}
function pDue(p) {
  if (!p.dueDate) return null;
  if (P_DONE.has(p.status)) return { cls: "", text: fmtDay(p.dueDate) };
  const d = dayDiff(p.dueDate);
  return d < 0 ? { cls: "over", text: `Overdue ${-d}d` } : d === 0 ? { cls: "today", text: "Due today" } : d <= 7 ? { cls: "soon", text: d === 1 ? "Due tomorrow" : "Due " + fmtDay(p.dueDate) } : { cls: "", text: "Due " + fmtDay(p.dueDate) };
}
const safeUrl = u => { u = String(u || "").trim(); if (!u) return ""; if (!/^https?:\/\//i.test(u)) u = "https://" + u; try { const x = new URL(u); return /^https?:$/.test(x.protocol) ? x.href : ""; } catch { return ""; } };
const latestQuote = pid => quotes.filter(q => q.proposalId === pid).sort((a, b) => (b.updatedAt || "").localeCompare(a.updatedAt || ""))[0];

function renderProposals() {
  const w = writable();
  if (!proposals.length) { $("#view").innerHTML = `<div class="empty"><h2>No proposals yet</h2><p>Track each deck or creative proposal as a card: which client it’s for, who’s on it, when it’s due, a link to the deck, and where it stands.</p>${w ? `<div class="crm-actions"><button type="button" class="btn primary" data-act="new-prop">+ New proposal</button></div>` : ""}</div>`; return; }
  const list = proposals.filter(p => docMatch(p.title, p.leadId, p.ownerId, true));
  $("#view").innerHTML = `<div class="board-scroll"><div class="crm-board">${P_STATUS.map(s => {
    const items = list.filter(p => p.status === s.k).sort((a, b) => (a.dueDate || "9999").localeCompare(b.dueDate || "9999"));
    return `<section class="col" data-pstatus="${s.k}" aria-label="${s.label}"><div class="col-head"><div class="t">${dotC(P_COLOR[s.k])}${s.label}<span class="n">${items.length}</span></div></div>
      <div class="col-body">${items.map(pCard).join("") || `<div class="col-empty">${w ? "Drag a proposal here" : "Nothing here"}</div>`}</div></section>`;
  }).join("")}</div></div>`;
}
function pCard(p) {
  const due = pDue(p), url = safeUrl(p.deckUrl), q = latestQuote(p.id);
  return `<article class="pcard" draggable="${writable() ? "true" : "false"}" data-pid="${esc(p.id)}" tabindex="0" aria-label="${esc(p.title)}">
    <div class="co">${esc(p.title || "Untitled proposal")}</div><div class="who">${esc(leadLabel(p.leadId))}</div>
    <div class="card-foot">${due ? `<span class="cchip ${due.cls}">${esc(due.text)}</span>` : ""}${url ? `<a class="cchip link-chip" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Deck ↗</a>` : ""}${q ? `<span class="cchip">${moneyC.format(quoteTotals(q).total)} · ${esc(QS[q.status].label)}</span>` : ""}${avatar(p.ownerId)}</div></article>`;
}
async function setProposalStatus(id, status) {
  const p = proposals.find(x => x.id === id); if (!p || p.status === status) return;
  await save("proposal_status", { id, status });
}
function leadOptions(sel) {
  const ls = leads.slice().sort((a, b) => (a.company || "").localeCompare(b.company || ""));
  return `<option value="">Choose a client…</option>` + ls.map(l => `<option value="${esc(l.id)}" ${l.id === sel ? "selected" : ""}>${esc(l.company || "Untitled")} · ${STAGE[l.stage].label}</option>`).join("");
}
function proposalOptions(leadId, sel) {
  const ps = proposals.filter(p => !leadId || p.leadId === leadId);
  return `<option value="">Not linked to a proposal</option>` + ps.map(p => `<option value="${esc(p.id)}" ${p.id === sel ? "selected" : ""}>${esc(p.title || "Untitled")}</option>`).join("");
}
function docRows(ps, qs) {
  return `${ps.map(p => `<div class="doc-row" data-open-p="${esc(p.id)}" tabindex="0" role="button"><span class="small muted">Proposal</span><span class="grow">${esc(p.title || "Untitled")}</span>${statusChip(PS, P_COLOR, p.status)}</div>`).join("")}${qs.map(q => `<div class="doc-row" data-open-q="${esc(q.id)}" tabindex="0" role="button"><span class="small muted">${esc(q.number || "Quote")}</span><span class="grow">${esc(q.title || "Quote")}</span><span class="num">${money.format(quoteTotals(q).total)}</span>${statusChip(QS, Q_COLOR, q.status)}</div>`).join("")}`;
}
function renderLeadDocs() {
  const el = $("#lead-docs"); if (!el || !drawer) return;
  const ps = proposals.filter(p => p.leadId === drawer.id), qs = quotes.filter(q => q.leadId === drawer.id);
  el.innerHTML = (ps.length || qs.length ? `<div class="fu-list">${docRows(ps, qs)}</div>` : `<p class="small muted">No proposals or quotes for this lead yet.</p>`)
    + (writable() ? `<div class="row-btns"><button type="button" class="btn sm" data-act="lead-new-p">+ Proposal</button><button type="button" class="btn sm" data-act="lead-new-q">+ Quote</button></div>` : "");
}
function renderDocFoot() {
  const f = $("#d-foot"); if (!f) return;
  if (!writable()) { f.innerHTML = `<span class="small muted">View only</span><span class="sp"></span><button type="button" class="btn" data-act="close">Close</button>`; return; }
  if (drawer.confirmDelete) { f.innerHTML = `<div class="confirm">Delete this ${drawer.kind}?<button type="button" class="btn sm bad solid" data-act="doc-del-yes">Delete</button><button type="button" class="btn sm" data-act="doc-del-no">Keep</button></div>`; return; }
  f.innerHTML = `${drawer.isNew ? "" : `<button type="button" class="btn bad" data-act="doc-del">Delete</button>`}<span class="sp"></span><button type="button" class="btn" data-act="close">Cancel</button><button type="button" class="btn primary" data-act="doc-save">${drawer.isNew ? (drawer.kind === "proposal" ? "Add proposal" : "Create quote") : "Save changes"}</button>`;
}
function drawerShell(title, body, chip) {
  $("#drawer-root").innerHTML = `<div class="scrim" data-act="close"></div><aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="d-title">
    <div class="d-head"><h2 id="d-title">${title}</h2>${chip || ""}<button type="button" class="x" data-act="close" aria-label="Close">×</button></div>
    <form class="d-body" autocomplete="off">${body}</form><div class="d-foot" id="d-foot"></div></aside>`;
}
function openProposal(id, prefill) {
  const isNew = !id;
  const src = isNew ? { title: "", leadId: "", status: "todo", dueDate: addDays(today(), 7), deckUrl: "", ownerId: owners.includes(me.id) ? me.id : null, notes: "", ...prefill } : proposals.find(p => p.id === id);
  if (!src) return;
  drawer = { kind: "proposal", id: isNew ? null : id, isNew, draft: JSON.parse(JSON.stringify(src)), confirmDelete: false, picking: false };
  const d = drawer.draft, ro = !writable() ? "readonly" : "", dis = !writable() ? "disabled" : "", url = safeUrl(d.deckUrl);
  const qs = isNew ? [] : quotes.filter(q => q.proposalId === drawer.id);
  drawerShell(isNew ? "New proposal" : esc(d.title || "Untitled proposal"), `
    <div class="sec"><h4>Proposal</h4><div class="grid2">
      <div class="field full"><label for="p-title">Name *</label><input id="p-title" type="text" value="${esc(d.title)}" placeholder="e.g. Holiday campaign concept" ${ro}></div>
      <div class="field full"><label for="p-lead">Client</label><select id="p-lead" ${dis}>${leadOptions(d.leadId)}</select></div>
      <div class="field"><label for="p-status">Status</label><select id="p-status" ${dis}>${P_STATUS.map(x => `<option value="${x.k}" ${x.k === d.status ? "selected" : ""}>${x.label}</option>`).join("")}</select></div>
      <div class="field"><label for="p-due">Due date</label><input id="p-due" type="date" value="${esc(d.dueDate || "")}" ${ro}></div>
      <div class="field full"><label for="p-deck">Deck link</label><input id="p-deck" type="url" value="${esc(d.deckUrl || "")}" placeholder="Paste a Google Slides, Pitch, Figma or PDF link" ${ro}>${url ? `<a class="small" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Open deck ↗</a>` : ""}</div>
      <div class="field full"><label>Owner</label><div class="owner" id="owner"></div></div>
      <div class="field full"><label for="p-notes">Notes</label><textarea id="p-notes" rows="4" ${ro} placeholder="Brief, creative direction, feedback…">${esc(d.notes || "")}</textarea></div>
    </div></div>
    ${isNew ? `<p class="small muted">Sending a proposal moves its client to the Proposal stage and logs it on the client’s timeline.</p>` : `<div class="sec"><h4>Quotes for this proposal</h4>${qs.length ? `<div class="fu-list">${docRows([], qs)}</div>` : `<p class="small muted">No quote yet.</p>`}${writable() ? `<div class="row-btns"><button type="button" class="btn sm" data-act="p-new-quote">+ New quote</button></div>` : ""}</div>`}`,
    isNew ? "" : statusChip(PS, P_COLOR, d.status));
  renderOwner(); renderDocFoot();
  setTimeout(() => (isNew ? $("#p-title") : $(".x"))?.focus(), 0);
}
async function saveProposal() {
  const data = { title: $("#p-title").value.trim(), leadId: $("#p-lead").value, status: $("#p-status").value, dueDate: $("#p-due").value, deckUrl: $("#p-deck").value.trim(), ownerId: drawer.draft.ownerId || null, notes: $("#p-notes").value };
  if (!data.title) { toast("Give the proposal a name."); $("#p-title").focus(); return; }
  if (data.deckUrl && !safeUrl(data.deckUrl)) { toast("That deck link doesn’t look like a web address."); $("#p-deck").focus(); return; }
  if (await save("proposal_save", { id: drawer.id || 0, proposal: data })) closeDrawer();
}

/* quotes */
function renderQuotes() {
  const w = writable();
  if (!quotes.length) { $("#view").innerHTML = `<div class="empty"><h2>No quotes yet</h2><p>A quote is the budget for a proposal: line items, quantities and rates, with discount and tax. When a quote is approved, its total becomes the deal value.</p>${w ? `<div class="crm-actions"><button type="button" class="btn primary" data-act="new-quote">+ New quote</button></div>` : ""}</div>`; return; }
  const list = quotes.filter(q => docMatch(`${q.number || ""} ${q.title || ""}`, q.leadId, null, false)).sort((a, b) => (b.updatedAt || "").localeCompare(a.updatedAt || ""));
  const strip = `<div class="funnel">${Q_STATUS.map(s => { const it = list.filter(q => q.status === s.k); return `<div class="fstep static" style="--c: var(--s-${Q_COLOR[s.k]})"><span class="t">${s.label}</span><span class="n">${it.length}</span><span class="m">${money.format(it.reduce((t, q) => t + quoteTotals(q).total, 0))}</span></div>`; }).join("")}</div>`;
  if (!list.length) { $("#view").innerHTML = strip + noMatch("quotes"); return; }
  const cellStatus = q => w ? `<select class="stage-sel" data-qstatus-for="${esc(q.id)}" aria-label="Status for ${esc(q.number)}">${Q_STATUS.map(s => `<option value="${s.k}" ${s.k === q.status ? "selected" : ""}>${s.label}</option>`).join("")}</select>` : statusChip(QS, Q_COLOR, q.status);
  $("#view").innerHTML = strip + `<div class="crm-scroll"><table><thead><tr><th>Quote</th><th>Client</th><th>Proposal</th><th>Status</th><th class="r">Total</th><th>Valid until</th><th>Updated</th></tr></thead><tbody>${list.map(q => {
    const p = proposals.find(x => x.id === q.proposalId), exp = q.validUntil && q.status === "sent" && dayDiff(q.validUntil) < 0;
    return `<tr data-qid="${esc(q.id)}" tabindex="0"><td><div class="co">${esc(q.number || "—")}</div>${q.title ? `<div class="csub">${esc(q.title)}</div>` : ""}</td>
      <td>${esc(leadLabel(q.leadId))}</td><td class="small wrapcell">${esc(p?.title || "—")}</td><td>${cellStatus(q)}</td><td class="r num">${money.format(quoteTotals(q).total)}</td>
      <td>${q.validUntil ? `<span class="cchip ${exp ? "over" : ""}">${exp ? "Expired " : ""}${fmtDay(q.validUntil)}</span>` : `<span class="muted">—</span>`}</td><td class="small muted">${esc(ago(q.updatedAt) || "—")}</td></tr>`;
  }).join("")}</tbody><tfoot><tr><td colspan="7" class="small muted">${list.length} quote${list.length === 1 ? "" : "s"} · ${money.format(list.reduce((t, q) => t + quoteTotals(q).total, 0))}</td></tr></tfoot></table></div>`;
}
function openQuote(id, prefill) {
  const isNew = !id;
  const src = isNew ? { title: "", leadId: "", proposalId: "", status: "draft", validUntil: addDays(today(), 30), items: [{ id: rid(), desc: "", qty: 1, rate: "" }], discountPct: "", taxPct: "", notes: "", ...prefill } : quotes.find(q => q.id === id);
  if (!src) return;
  drawer = { kind: "quote", id: isNew ? null : id, isNew, draft: JSON.parse(JSON.stringify(src)), confirmDelete: false };
  if (!drawer.draft.items.length) drawer.draft.items.push({ id: rid(), desc: "", qty: 1, rate: "" });
  const d = drawer.draft, ro = !writable() ? "readonly" : "", dis = !writable() ? "disabled" : "";
  drawerShell(isNew ? "New quote" : esc(d.number || "Quote"), `
    <div class="sec"><h4>Quote</h4><div class="grid2">
      <div class="field full"><label for="q-title">What it’s for</label><input id="q-title" type="text" value="${esc(d.title || "")}" placeholder="e.g. Production budget, phase 1" ${ro}></div>
      <div class="field"><label for="q-lead">Client</label><select id="q-lead" ${dis}>${leadOptions(d.leadId)}</select></div>
      <div class="field"><label for="q-proposal">Proposal</label><select id="q-proposal" ${dis}>${proposalOptions(d.leadId, d.proposalId)}</select></div>
      <div class="field"><label for="q-status">Status</label><select id="q-status" ${dis}>${Q_STATUS.map(x => `<option value="${x.k}" ${x.k === d.status ? "selected" : ""}>${x.label}</option>`).join("")}</select></div>
      <div class="field"><label for="q-valid">Valid until</label><input id="q-valid" type="date" value="${esc(d.validUntil || "")}" ${ro}></div>
    </div>${isNew ? `<p class="small muted" style="margin:8px 0 0">The quote number (Q-${new Date().getFullYear()}-…) is given when you create it.</p>` : ""}</div>
    <div class="sec"><h4>Line items (USD)</h4><div class="li-wrap"><table class="li"><thead><tr><th>Description</th><th style="width:80px">Qty</th><th style="width:120px">Rate</th><th class="r" style="width:110px">Amount</th><th style="width:36px"></th></tr></thead><tbody id="li-body"></tbody></table></div>
      ${writable() ? `<button type="button" class="btn sm" data-act="li-add" style="margin-top:6px">+ Add line</button>` : ""}
      <div class="adj"><div class="field"><label for="q-disc">Discount %</label><input id="q-disc" type="number" min="0" max="100" step="0.5" value="${esc(d.discountPct)}" ${ro}></div><div class="field"><label for="q-tax">Tax %</label><input id="q-tax" type="number" min="0" max="100" step="0.5" value="${esc(d.taxPct)}" ${ro}></div></div>
      <div class="totals" id="q-totals"></div>
      ${writable() ? `<div class="row-btns" style="justify-content:flex-end"><button type="button" class="btn sm" data-act="q-deal">Set as the client’s deal value</button></div>` : ""}
    </div>
    <div class="sec"><div class="field"><label for="q-notes">Notes and terms</label><textarea id="q-notes" rows="3" ${ro} placeholder="Payment terms, what’s included, assumptions…">${esc(d.notes || "")}</textarea></div></div>
    <p class="small muted">When a quote is approved, its total becomes the client’s deal value automatically.</p>`,
    isNew ? "" : statusChip(QS, Q_COLOR, d.status));
  renderItems(); renderDocFoot();
  setTimeout(() => (isNew ? $("#q-title") : $(".x"))?.focus(), 0);
}
function renderItems() {
  const ro = !writable() ? "readonly" : "";
  $("#li-body").innerHTML = drawer.draft.items.map((it, i) => `<tr>
    <td><input id="li-desc-${i}" type="text" value="${esc(it.desc)}" placeholder="e.g. Creative direction" aria-label="Description" ${ro}></td>
    <td><input id="li-qty-${i}" type="number" min="0" step="any" value="${esc(it.qty)}" aria-label="Quantity" ${ro}></td>
    <td><input id="li-rate-${i}" type="number" min="0" step="any" value="${esc(it.rate)}" aria-label="Rate in USD" ${ro}></td>
    <td class="amt" id="li-amt-${i}"></td>
    <td>${writable() && drawer.draft.items.length > 1 ? `<button type="button" class="x" data-li-del="${i}" aria-label="Remove line">×</button>` : ""}</td></tr>`).join("");
  updateTotals();
}
function syncItems() {
  if (!drawer || drawer.kind !== "quote" || !$("#li-body")) return;
  drawer.draft.items.forEach((it, i) => { const g = k => $(`#li-${k}-${i}`); if (g("desc")) { it.desc = g("desc").value; it.qty = g("qty").value === "" ? "" : +g("qty").value; it.rate = g("rate").value === "" ? "" : +g("rate").value; } });
  drawer.draft.discountPct = $("#q-disc").value === "" ? "" : +$("#q-disc").value;
  drawer.draft.taxPct = $("#q-tax").value === "" ? "" : +$("#q-tax").value;
}
function updateTotals() {
  const d = drawer.draft, t = quoteTotals(d);
  d.items.forEach((it, i) => { const el = $(`#li-amt-${i}`); if (el) el.textContent = money.format((+it.qty || 0) * (+it.rate || 0)); });
  $("#q-totals").innerHTML = `<span>Subtotal</span><span class="r">${money.format(t.sub)}</span>${t.disc ? `<span>Discount (${+d.discountPct}%)</span><span class="r">−${money.format(t.disc)}</span>` : ""}${t.tax ? `<span>Tax (${+d.taxPct}%)</span><span class="r">${money.format(t.tax)}</span>` : ""}<span class="grand">Total</span><span class="r grand">${money.format(t.total)}</span>`;
}
function readQuoteForm() {
  syncItems();
  const d = drawer.draft;
  return { title: $("#q-title").value.trim(), leadId: $("#q-lead").value, proposalId: $("#q-proposal").value, status: $("#q-status").value, validUntil: $("#q-valid").value,
    items: d.items.filter(i => String(i.desc).trim() || +i.rate).map(i => ({ desc: String(i.desc).trim(), qty: +i.qty || 0, rate: +i.rate || 0 })),
    discountPct: +d.discountPct || 0, taxPct: +d.taxPct || 0, notes: $("#q-notes").value };
}
async function saveQuote() {
  const data = readQuoteForm();
  if (!data.items.length) { toast("Add at least one line item."); $("#li-desc-0")?.focus(); return; }
  if (await save("quote_save", { id: drawer.id || 0, quote: data })) closeDrawer();
}
async function setQuoteStatus(id, status) {
  const q = quotes.find(x => x.id === id); if (!q || q.status === status) return;
  await save("quote_status", { id, status });
}

/* ---------- lead drawer ---------- */
function blankLead() {
  return { company: "", contacts: [blankContact()], website: "", projectType: "", source: "Email outreach", stage: "contacted", value: "", ownerId: owners.includes(me.id) ? me.id : null, nextStep: "", followUp: addDays(today(), 3), tags: [], notes: "", lostReason: "", activities: [] };
}
function openDrawer(id, prefill) {
  const isNew = !id;
  const src = isNew ? Object.assign(blankLead(), prefill || {}) : leads.find(l => l.id === id);
  if (!src) return;
  drawer = { kind: "lead", id: isNew ? null : id, isNew, draft: JSON.parse(JSON.stringify(src)), confirmDelete: false, picking: false };
  $("#types-dl").innerHTML = distinct("projectType").map(t => `<option value="${esc(t)}">`).join("");
  const ro = !writable(), d = drawer.draft;
  const opt = (arr, v) => arr.map(o => typeof o === "string" ? `<option ${o === v ? "selected" : ""}>${esc(o)}</option>` : `<option value="${o.k}" ${o.k === v ? "selected" : ""}>${esc(o.label)}</option>`).join("");
  const fld = (id, label, val, type = "text", cls = "", extra = "") => `<div class="field ${cls}"><label for="${id}">${label}</label><input id="${id}" type="${type}" value="${esc(val)}" ${ro ? "readonly" : ""} ${extra}></div>`;
  const srcs = [...new Set([...SOURCES, ...(d.source ? [d.source] : [])])];
  $("#drawer-root").innerHTML = `<div class="scrim" data-act="close"></div>
  <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="d-title">
    <div class="d-head"><h2 id="d-title">${isNew ? "New lead" : esc(d.company || "Untitled lead")}</h2><button type="button" class="x" data-act="close" aria-label="Close">×</button></div>
    <form class="d-body" id="lead-form" autocomplete="off">
      <div class="sec"><h4>Client</h4><div class="grid2">
        ${fld("f-company", "Client / company *", d.company, "text", "full")}
        ${fld("f-website", "Website", d.website, "text", "full")}
      </div></div>
      <div class="sec"><h4>People at this company</h4><div id="contacts-ed" class="ct-list"></div>${ro ? "" : `<button type="button" class="btn sm" data-act="c-add" style="margin-top:10px">+ Add contact</button>`}</div>
      <div class="sec"><h4>Deal</h4><div class="grid2">
        <div class="field"><label for="f-stage">Status</label><select id="f-stage" ${ro ? "disabled" : ""}>${opt(STAGES, d.stage)}</select></div>
        <div class="field"><label for="f-value">Proposal value (USD)</label><input id="f-value" type="number" min="0" step="100" inputmode="numeric" value="${esc(d.value)}" ${ro ? "readonly" : ""}><span class="small muted" id="weighted"></span></div>
        ${fld("f-type", "Project type", d.projectType, "text", "", 'list="types-dl" placeholder="e.g. Retainer, Licensing"')}
        <div class="field"><label for="f-source">Source</label><select id="f-source" ${ro ? "disabled" : ""}><option value="">—</option>${opt(srcs, d.source)}</select></div>
        ${fld("f-tags", "Tags (comma-separated)", (d.tags || []).join(", "), "text", "full")}
        <div class="field full" id="lost-wrap" ${d.stage === "lost" ? "" : "hidden"}><label for="f-lost">Why was it lost?</label><input id="f-lost" type="text" value="${esc(d.lostReason)}" ${ro ? "readonly" : ""}></div>
        <div class="field full"><label>Owner</label><div class="owner" id="owner"></div></div>
      </div></div>
      <div class="sec"><h4>Next step</h4><div class="grid2">
        ${fld("f-next", "What happens next", d.nextStep, "text", "full")}
        <div class="field"><label for="f-follow">Follow-up date</label><input id="f-follow" type="date" value="${esc(d.followUp)}" ${ro ? "readonly" : ""}></div>
        ${ro ? "" : `<div class="field"><label>Quick set</label><div class="owner"><button type="button" class="btn sm" data-fu="1">Tomorrow</button><button type="button" class="btn sm" data-fu="3">+3 days</button><button type="button" class="btn sm" data-fu="7">Next week</button></div></div>`}
      </div></div>
      <div class="sec"><div class="field"><label for="f-notes">Background notes</label><textarea id="f-notes" rows="3" ${ro ? "readonly" : ""}>${esc(d.notes)}</textarea></div></div>
      ${isNew ? "" : `<div class="sec"><h4>Proposals & quotes</h4><div id="lead-docs"></div></div>`}
      <div class="sec"><h4>Activity</h4>
        ${isNew ? `<p class="small muted">Save the lead first, then log emails, calls and meetings here.</p>` : (ro ? "" : `<div class="log-add">
          <select id="a-type" aria-label="Activity type">${ACT_TYPES.map(t => `<option>${t}</option>`).join("")}</select>
          <select id="a-with" aria-label="With contact"><option value="">Whole company</option>${(d.contacts || []).filter(c => !String(c.id).startsWith("n")).map(c => `<option value="${esc(c.id)}">${esc(c.name || c.email || "Unnamed contact")}</option>`).join("")}</select>
          <textarea id="a-text" rows="1" placeholder="What happened? e.g. Sent follow-up email with deck"></textarea>
          <button type="button" class="btn" data-act="log">Log</button></div>`)}
        <ul class="timeline" id="timeline"></ul>
      </div>
    </form>
    <div class="d-foot" id="d-foot"></div>
  </aside>`;
  renderOwner(); renderContactsEd(); renderLeadDocs(); renderTimeline(); renderFoot(); updateWeighted();
  setTimeout(() => (isNew ? $("#f-company") : $(".x")).focus(), 0);
}
function closeDrawer() { drawer = null; $("#drawer-root").innerHTML = ""; }
function updateWeighted() {
  const v = +$("#f-value").value || 0, st = $("#f-stage").value;
  $("#weighted").textContent = v ? `Weighted: ${money.format(v * STAGE[st].p)} at ${Math.round(STAGE[st].p * 100)}%` : "";
  $("#lost-wrap").hidden = st !== "lost";
}
/* The owner picker: the people who have Commercial (anyone else could not open the lead they were given). */
function renderOwner() {
  if (!drawer) return;
  const el = $("#owner"), uid = drawer.draft.ownerId, ro = !writable();
  el.innerHTML = `${avatar(uid)}<span class="nm">${esc(personName(uid))}</span>` + (ro ? "" :
    (drawer.picking
      ? `<div class="picker"><input id="owner-q" type="text" placeholder="Search people by name…" aria-label="Search people"><ul id="owner-list"></ul></div><button type="button" class="btn sm" data-act="pick-cancel">Cancel</button>`
      : `<button type="button" class="btn sm" data-act="pick">Change</button>${me.id && uid !== me.id && owners.includes(me.id) ? `<button type="button" class="btn sm" data-act="assign-me">Assign to me</button>` : ""}${uid ? `<button type="button" class="btn sm" data-act="unassign">Unassign</button>` : ""}`));
  if (drawer.picking) { $("#owner-q").focus(); searchPeople(""); }
}
function searchPeople(q) {
  const ul = $("#owner-list"); if (!ul) return;
  const s = q.trim().toLowerCase();
  const hits = owners.filter(id => !s || personName(id).toLowerCase().includes(s)).sort((a, b) => personName(a).localeCompare(personName(b)));
  ul.innerHTML = hits.length
    ? hits.map(id => `<li><button type="button" data-pick="${esc(id)}">${avatar(id)}<span>${esc(personName(id))}${id === me.id ? " (you)" : ""}</span></button></li>`).join("")
    : `<li class="none">${s ? "No one matches that name." : "Nobody else has Commercial yet."}</li>`;
}
function renderTimeline() {
  if (!drawer || !$("#timeline")) return;
  const live = leads.find(l => l.id === drawer.id);
  const src = live || drawer.draft, acts = src.activities || [];
  const cname = id => { const c = (src.contacts || []).find(x => x.id === id); return c ? (c.name || c.email || "contact") : "a removed contact"; };
  $("#timeline").innerHTML = acts.length ? acts.map(a => `<li><div class="ty ${esc(a.type)}">${esc(a.type)}</div><div><div class="txt">${esc(a.text)}</div><div class="meta">${esc(fmtWhen(a.at))}${a.contactId ? " · with " + esc(cname(a.contactId)) : ""}${a.by ? " · " + esc(personName(a.by)) : ""}</div></div></li>`).join("")
    : (drawer.isNew ? "" : `<li><div></div><div class="small muted">No activity logged yet.</div></li>`);
}
function renderFoot() {
  if (!drawer) return;
  const f = $("#d-foot");
  if (!writable()) { f.innerHTML = `<span class="small muted">View only</span><span class="sp"></span><button type="button" class="btn" data-act="close">Close</button>`; return; }
  if (drawer.confirmDelete) { f.innerHTML = `<div class="confirm">Delete this lead and its history?<button type="button" class="btn sm bad solid" data-act="del-yes">Delete</button><button type="button" class="btn sm" data-act="del-no">Keep</button></div>`; return; }
  f.innerHTML = `${drawer.isNew ? "" : `<button type="button" class="btn bad" data-act="del">Delete</button>`}<span class="sp"></span><button type="button" class="btn" data-act="close">Cancel</button><button type="button" class="btn primary" data-act="save">${drawer.isNew ? "Add lead" : "Save changes"}</button>`;
}
function readForm() {
  return {
    company: $("#f-company").value.trim(), website: $("#f-website").value.trim(), ...contactFields(),
    stage: $("#f-stage").value, value: $("#f-value").value === "" ? "" : Math.max(0, +$("#f-value").value || 0),
    projectType: $("#f-type").value.trim(), source: $("#f-source").value,
    tags: $("#f-tags").value.split(",").map(t => t.trim()).filter(Boolean),
    lostReason: $("#f-lost").value.trim(), ownerId: drawer.draft.ownerId || null, nextStep: $("#f-next").value.trim(),
    followUp: $("#f-follow").value, notes: $("#f-notes").value,
  };
}
async function saveDrawer() {
  const data = readForm();
  if (!data.company) { toast("Add a client name to save the lead."); $("#f-company").focus(); return; }
  if (await save("lead_save", { id: drawer.id || 0, lead: data })) closeDrawer();
}
async function logActivity() {
  const text = $("#a-text").value.trim(); if (!text) { $("#a-text").focus(); return; }
  const type = $("#a-type").value, cid = $("#a-with").value;
  if (await save("lead_log", { id: drawer.id, type, text, contactId: cid || null }) && $("#a-text")) $("#a-text").value = "";
}

/* ---------- CSV ---------- */
function parseCsv(text) {
  text = text.replace(/^﻿/, "");
  const first = text.split(/\r?\n/, 1)[0] || "";
  const cnt = ch => first.split(ch).length;
  const delim = cnt("\t") > cnt(",") && cnt("\t") > cnt(";") ? "\t" : cnt(";") > cnt(",") ? ";" : ",";
  const rows = []; let row = [], f = "", q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) { if (c === '"') { if (text[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += c; }
    else if (c === '"' && f === "") q = true;
    else if (c === delim) { row.push(f); f = ""; }
    else if (c === "\n" || c === "\r") { if (c === "\r" && text[i + 1] === "\n") i++; row.push(f); rows.push(row); row = []; f = ""; }
    else f += c;
  }
  if (f !== "" || row.length) { row.push(f); rows.push(row); }
  return rows.filter(r => r.some(x => x.trim() !== ""));
}
const IMPORT_FIELDS = [
  { k: "company", label: "Client / company", req: true, alias: ["company", "client", "company name", "client name", "organization", "organisation", "account", "business", "brand"] },
  { k: "contact", label: "Contact name", alias: ["contact", "contact name", "name", "full name", "person"] },
  { k: "firstName", label: "First name", alias: ["first name", "firstname", "given name"] },
  { k: "lastName", label: "Last name", alias: ["last name", "lastname", "surname", "family name"] },
  { k: "title", label: "Role / title", alias: ["title", "role", "job title", "position"] },
  { k: "email", label: "Email", alias: ["email", "e mail", "email address", "mail"] },
  { k: "phone", label: "Phone", alias: ["phone", "phone number", "mobile", "tel", "telephone"] },
  { k: "website", label: "Website", alias: ["website", "url", "domain", "site", "web"] },
  { k: "projectType", label: "Project type", alias: ["project type", "type", "project", "service", "category", "deal type"] },
  { k: "source", label: "Lead source", alias: ["source", "lead source"] },
  { k: "channel", label: "Contact channel", alias: ["channel", "medium", "contact method", "outreach channel"] },
  { k: "contactedAt", label: "Contacted on", alias: ["contacted on", "date contacted", "first contact", "contact date", "contacted", "first contacted"] },
  { k: "stage", label: "Status", alias: ["status", "stage", "lead status", "deal stage"] },
  { k: "value", label: "Proposal value", alias: ["value", "proposal value", "deal value", "amount", "budget", "proposal", "price", "deal size"] },
  { k: "followUp", label: "Follow-up date", alias: ["follow up", "follow up date", "followup", "next follow up", "due", "due date", "next contact"] },
  { k: "nextStep", label: "Next step", alias: ["next step", "next action", "action", "next steps"] },
  { k: "tags", label: "Tags", alias: ["tags", "labels", "tag"] },
  { k: "notes", label: "Notes", alias: ["notes", "note", "comments", "comment", "description"] },
];
const normH = s => String(s).toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
function guessMap(headers) {
  const H = headers.map(normH), map = {}, used = new Set();
  for (const pass of [0, 1]) for (const f of IMPORT_FIELDS) {
    if (map[f.k] != null) continue;
    const idx = H.findIndex((h, i) => !used.has(i) && h && f.alias.some(a => pass === 0 ? h === a : (h.includes(a) && a.length > 3)));
    if (idx >= 0) { map[f.k] = idx; used.add(idx); }
  }
  return map;
}
function csvStage(v) {
  const s = normH(v);
  if (!s) return null;
  if (/^(won|closed won|signed|customer|client)$/.test(s)) return "won";
  if (/^(lost|closed lost|dead|not interested|declined|no)$/.test(s)) return "lost";
  if (/negotiat/.test(s)) return "negotiation";
  if (/proposal|quote|quoted|pitch/.test(s)) return "proposal";
  if (/interest|qualified|warm|replied|meeting/.test(s)) return "interested";
  if (/contact|new|lead|prospect|outreach|sent|cold/.test(s)) return "contacted";
  return null;
}
function csvChannel(v) {
  const s = normH(v); if (!s) return "";
  if (/mail/.test(s)) return "Email";
  if (/phone|call|tel/.test(s)) return "Phone";
  if (/network|event|conference|meetup|fair/.test(s)) return "Networking";
  if (/linkedin/.test(s)) return "LinkedIn";
  if (/referr|intro/.test(s)) return "Referral";
  return "Other";
}
function csvValue(v) {
  const s = String(v || "").trim().toLowerCase(); if (!s) return "";
  const m = s.match(/(-?[\d.,]+)\s*([km])?/); if (!m) return "";
  let n = parseFloat(m[1].replace(/,/g, "")); if (isNaN(n)) return "";
  if (m[2] === "k") n *= 1e3; if (m[2] === "m") n *= 1e6;
  return Math.max(0, Math.round(n));
}
function csvDate(v) {
  const s = String(v || "").trim(); if (!s) return "";
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
  const d = new Date(s); return isNaN(d) ? "" : ymd(d);
}
/* The preview, and what is sent: rows grouped by company, one lead per new
   company, new people added to a company already here, duplicates skipped.
   The server checks the same things again against what is saved when it runs. */
function buildImport() {
  const { rows, map } = imp, cell = (r, k) => map[k] != null ? (r[map[k]] || "").trim() : "";
  const ckey = c => c.email ? "e:" + c.email.toLowerCase() : c.name ? "n:" + c.name.toLowerCase() : "";
  const existing = new Map();
  leads.slice().sort((a, b) => (a.updatedAt || "").localeCompare(b.updatedAt || "")).forEach(l => existing.set(clientKey(l), l));
  const groups = new Map(); let blank = 0;
  for (const r of rows) {
    const company = cell(r, "company"); if (!company) { blank++; continue; }
    const k = company.toLowerCase();
    if (!groups.has(k)) groups.set(k, { company, rows: [] });
    groups.get(k).rows.push(r);
  }
  const out = { creates: [], adds: [], dupContacts: 0, blank, newContacts: 0, unchanged: 0 };
  for (const [k, g] of groups) {
    const first = key => { for (const r of g.rows) { const v = cell(r, key); if (v) return v; } return ""; };
    const seen = new Set(), contacts = [];
    for (const r of g.rows) {
      const name = cell(r, "contact") || [cell(r, "firstName"), cell(r, "lastName")].filter(Boolean).join(" ");
      const c = { name, title: cell(r, "title"), email: cell(r, "email"), phone: cell(r, "phone"), channel: csvChannel(cell(r, "channel")) || imp.channel, contactedAt: csvDate(cell(r, "contactedAt")) || today() };
      const ck = ckey(c); if (!ck) continue;
      if (seen.has(ck)) { out.dupContacts++; continue; }
      seen.add(ck); contacts.push(c);
    }
    const ex = existing.get(k);
    if (ex) {
      const have = new Set(ex.contacts.map(ckey)), fresh = contacts.filter(c => !have.has(ckey(c)));
      out.dupContacts += contacts.length - fresh.length;
      if (fresh.length) { out.adds.push({ id: ex.id, company: ex.company, stage: ex.stage, projectType: ex.projectType, value: ex.value, contacts: fresh }); out.newContacts += fresh.length; } else out.unchanged++;
      continue;
    }
    out.creates.push({ company: g.company, website: first("website"), projectType: first("projectType"), source: first("source") || imp.source,
      value: csvValue(first("value")), stage: csvStage(first("stage")) || imp.stage,
      followUp: csvDate(first("followUp")) || (imp.followDays ? addDays(today(), imp.followDays) : ""), nextStep: first("nextStep"),
      tags: [...new Set(g.rows.flatMap(r => cell(r, "tags").split(/[;,]/).map(t => t.trim()).filter(Boolean)))],
      notes: g.rows.map(r => cell(r, "notes")).filter(Boolean).join("\n"), contacts });
    out.newContacts += contacts.length;
  }
  return out;
}
function openImport() { if (!writable()) return; imp = { step: "pick", stage: "contacted", source: "Email outreach", channel: "Email", assignMe: owners.includes(me.id), followDays: 3 }; renderImport(); }
function closeImport() { if (imp && imp.step === "run") return; imp = null; $("#modal-root").innerHTML = ""; }
function loadImportText(text, name) {
  const rows = parseCsv(text);
  if (rows.length < 2) { toast("That file needs a header row and at least one lead."); return; }
  imp.headers = rows[0].map(h => h.trim()); imp.rows = rows.slice(1); imp.name = name || "Pasted rows";
  imp.map = guessMap(imp.headers); imp.step = "map"; renderImport();
}
function renderImport() {
  const root = $("#modal-root");
  if (!imp) { root.innerHTML = ""; return; }
  let body = "", foot = "";
  if (imp.step === "pick") {
    body = `<div class="drop" id="drop"><strong>Drop a CSV file here</strong><span class="small muted">Exports from Google Sheets, Excel, HubSpot, Apollo or any outreach tool work. The first row must be column names.</span>
      <label class="btn primary" for="imp-file">Choose file</label><input id="imp-file" type="file" accept=".csv,.tsv,.txt,text/csv" hidden></div>
      <div class="field"><label for="imp-paste">Or paste rows copied from a spreadsheet (include the header row)</label><textarea id="imp-paste" class="paste" placeholder="Company,Contact,Email,Project type,Value&#10;Acme Inc,Jane Doe,jane@acme.com,Retainer,25000"></textarea></div>`;
    foot = `<span class="sp"></span><button type="button" class="btn" data-act="imp-close">Cancel</button><button type="button" class="btn primary" data-act="imp-paste">Use pasted rows</button>`;
  } else if (imp.step === "map") {
    const b = buildImport();
    const hopt = sel => `<option value="">— Don’t import —</option>` + imp.headers.map((h, i) => `<option value="${i}" ${sel === i ? "selected" : ""}>${esc(h || `Column ${i + 1}`)}</option>`).join("");
    const prev = [...b.creates.slice(0, 5), ...b.adds.slice(0, 3)], nAct = b.creates.length + b.adds.length;
    body = `<p class="small muted" style="margin:0">${esc(imp.name)} · ${imp.rows.length} row${imp.rows.length === 1 ? "" : "s"}. Match each field to a column in your file. We matched what we could.</p>
      <div class="sec"><h4>Columns</h4><div class="map">${IMPORT_FIELDS.map(f => `<div class="field ${f.req ? "req" : ""}"><label for="m-${f.k}">${f.label}</label><select id="m-${f.k}" data-map="${f.k}">${hopt(imp.map[f.k])}</select></div>`).join("")}</div></div>
      <div class="sec"><h4>Defaults</h4><div class="opts">
        <label>Status when the file has none <select id="imp-stage">${STAGES.map(s => `<option value="${s.k}" ${s.k === imp.stage ? "selected" : ""}>${s.label}</option>`).join("")}</select></label>
        <label>How people were contacted, when the file doesn’t say <select id="imp-channel">${CHANNELS.map(c => `<option ${c === imp.channel ? "selected" : ""}>${c}</option>`).join("")}</select></label>
        <label>Source when the file has none <select id="imp-source">${SOURCES.map(s => `<option ${s === imp.source ? "selected" : ""}>${s}</option>`).join("")}</select></label>
        <label>Follow-up when the file has none <select id="imp-follow"><option value="0" ${!imp.followDays ? "selected" : ""}>No date</option><option value="1" ${imp.followDays === 1 ? "selected" : ""}>Tomorrow</option><option value="3" ${imp.followDays === 3 ? "selected" : ""}>In 3 days</option><option value="7" ${imp.followDays === 7 ? "selected" : ""}>In a week</option></select></label>
        ${owners.includes(me.id) ? `<label><input type="checkbox" id="imp-me" ${imp.assignMe ? "checked" : ""}> Assign imported leads to me</label>` : ""}
        <p class="small muted" style="margin:0">Rows with the same client become one lead with several contacts. If the client is already in the CRM, new people are added to it, and anyone already saved (same email, or same name when there’s no email) is skipped.</p>
      </div></div>
      <div class="sec"><h4>Preview</h4>
        <div class="sum"><span><b>${b.creates.length}</b> new compan${b.creates.length === 1 ? "y" : "ies"}</span>${b.adds.length ? `<span><b>${b.adds.length}</b> existing compan${b.adds.length === 1 ? "y gets" : "ies get"} new contacts</span>` : ""}<span><b>${b.newContacts}</b> contact${b.newContacts === 1 ? "" : "s"} to add</span>${b.dupContacts ? `<span><b>${b.dupContacts}</b> duplicate contact${b.dupContacts === 1 ? "" : "s"} skipped</span>` : ""}${b.blank ? `<span><b>${b.blank}</b> row${b.blank === 1 ? "" : "s"} without a client skipped</span>` : ""}</div>
        ${prev.length ? `<div class="preview" style="margin-top:8px"><table><thead><tr><th>Client</th><th>Contacts</th><th>Project type</th><th>Status</th><th class="r">Value</th><th>What happens</th></tr></thead><tbody>${prev.map(l => `<tr><td>${esc(l.company)}</td><td>${esc(l.contacts.map(c => c.name || c.email).join(", ") || "—")}</td><td>${esc(l.projectType || "—")}</td><td>${STAGE[l.stage].label}</td><td class="r num">${l.value ? money.format(+l.value) : "—"}</td><td class="small">${l.id ? `Add ${l.contacts.length} to existing lead` : "New lead"}</td></tr>`).join("")}</tbody></table></div>` : `<p class="small muted">${imp.map.company == null ? "Choose which column holds the client name." : "Nothing new to import."}</p>`}
      </div>`;
    foot = `<button type="button" class="btn" data-act="imp-back">Back</button><span class="sp"></span><button type="button" class="btn" data-act="imp-close">Cancel</button><button type="button" class="btn primary" data-act="imp-run" ${nAct ? "" : "disabled"}>${b.creates.length ? `Import ${b.creates.length} compan${b.creates.length === 1 ? "y" : "ies"}` : `Add ${b.newContacts} contact${b.newContacts === 1 ? "" : "s"}`}</button>`;
  } else if (imp.step === "run") {
    const pct = imp.total ? Math.round(imp.done / imp.total * 100) : 0;
    body = `<p>Importing ${imp.done} of ${imp.total}…</p><div class="bar-prog"><i style="width:${pct}%"></i></div><p class="small muted">Keep this page open until it finishes.</p>`;
  } else {
    body = `<p><strong>Added ${imp.made} new compan${imp.made === 1 ? "y" : "ies"}${imp.added ? ` and ${imp.added} contact${imp.added === 1 ? "" : "s"} to existing ones` : ""}.</strong> ${imp.error ? esc(imp.error) : ""}</p><p class="small muted">Contacted leads are in the Prospects tab; the rest are in the Pipeline.</p>`;
    foot = `<span class="sp"></span><button type="button" class="btn primary" data-act="imp-done">Done</button>`;
  }
  const scrollTop = root.querySelector(".d-body")?.scrollTop || 0;
  root.innerHTML = `<div class="scrim" data-act="imp-close"></div><div class="crm-modal" role="dialog" aria-modal="true" aria-labelledby="imp-title">
    <div class="d-head"><h2 id="imp-title">Import leads from CSV</h2>${imp.step !== "run" ? `<button type="button" class="x" data-act="imp-close" aria-label="Close">×</button>` : ""}</div>
    <div class="d-body">${body}</div>${foot ? `<div class="d-foot">${foot}</div>` : ""}</div>`;
  const nb = root.querySelector(".d-body"); if (nb) nb.scrollTop = scrollTop;
}
/* Sent in batches of 20 companies, so the progress bar moves and one bad row
   cannot lose the whole file. A batch that fails stops the run; importing the
   file again skips what already arrived. */
async function runImport() {
  const b = buildImport(), total = b.creates.length + b.adds.length; if (!total) return;
  Object.assign(imp, { step: "run", total, done: 0, made: 0, added: 0, error: "" }); renderImport();
  const jobs = [...b.creates.map(c => ({ create: c })), ...b.adds.map(a => ({ add: { id: a.id, contacts: a.contacts } }))];
  try {
    for (let i = 0; i < jobs.length; i += 20) {
      const chunk = jobs.slice(i, i + 20);
      const r = await api("import", { name: imp.name, assignMe: !!imp.assignMe,
        creates: chunk.filter(j => j.create).map(j => j.create), adds: chunk.filter(j => j.add).map(j => j.add) });
      imp.made += r.made || 0; imp.added += r.added || 0; imp.done += chunk.length;
      renderImport();
    }
  } catch (e) {
    imp.error = (e && e.message ? e.message + " " : "") + "Import the file again; anything already added is skipped.";
  }
  imp.step = "done"; renderImport();
  await reload();
}

/* One row per contact, with the headers the importer recognises. A normal file
   download, of the leads the current filters show. */
function exportCsv() {
  const head = ["Company", "Contact", "Title", "Email", "Phone", "Channel", "Contacted on", "Website", "Project type", "Status", "Value", "Owner", "Source", "Tags", "Next step", "Follow-up", "Lost reason", "Notes", "Created", "Updated"];
  const cell = v => { const s = String(v ?? ""); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const rows = [];
  for (const l of applyFilters(leads)) for (const c of (l.contacts.length ? l.contacts : [{}]))
    rows.push([l.company, c.name, c.title, c.email, c.phone, c.channel, c.contactedAt, l.website, l.projectType, STAGE[l.stage].label, l.value, l.ownerId ? personName(l.ownerId) : "", l.source, (l.tags || []).join("; "), l.nextStep, l.followUp, l.lostReason, l.notes, l.createdAt, l.updatedAt].map(cell).join(","));
  const blob = new Blob(["﻿" + [head.join(","), ...rows].join("\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob); a.download = `leads-${today()}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/* ---------- events ---------- */
const INTERACTIVE = "input,select,textarea,label,button,a,.copyable";
ROOT.addEventListener("click", async e => {
  const t = e.target.closest("[data-act],[data-sort],[data-open],[data-snooze],[data-pick],[data-fu],[data-grp],[data-funnel],[data-copy],[data-c-del],[data-c-primary],[data-open-p],[data-open-q],[data-li-del]");
  if (!t) {
    const card = e.target.closest(".pcard[data-pid]"); if (card && !e.target.closest(INTERACTIVE)) { openProposal(card.dataset.pid); return; }
    const qtr = e.target.closest("tr[data-qid]"); if (qtr && !e.target.closest(INTERACTIVE)) { openQuote(qtr.dataset.qid); return; }
    const tr = e.target.closest("tbody tr[data-id],tbody tr[data-client]");
    if (tr && !e.target.closest(INTERACTIVE) && !tr.closest(".preview")) tr.dataset.client ? openClient(tr.dataset.client) : openDrawer(tr.dataset.id);
    return;
  }
  const act = t.dataset.act;
  if (t.dataset.sort) { const k = t.dataset.sort, s = sorts[view]; sorts[view] = { key: k, dir: s.key === k && s.dir === "desc" ? "asc" : "desc" }; render(); return; }
  if (t.dataset.grp) { const k = t.dataset.grp; collapsed.has(k) ? collapsed.delete(k) : collapsed.add(k); store.set("collapsed", [...collapsed]); render(); return; }
  if (t.dataset.funnel) { const k = t.dataset.funnel; if (k === "contacted") { location.hash = "prospects"; return; } collapsed.delete(k); setFilter("stage", filters.stage === k ? "" : k); return; }
  if (t.dataset.openP) { openProposal(t.dataset.openP); return; }
  if (t.dataset.openQ) { openQuote(t.dataset.openQ); return; }
  if (t.dataset.liDel) { syncItems(); drawer.draft.items.splice(+t.dataset.liDel, 1); renderItems(); return; }
  if (t.dataset.copy) { copyText(t.dataset.copy, "Email copied"); return; }
  if (t.dataset.cDel) { syncContacts(); drawer.draft.contacts.splice(+t.dataset.cDel, 1); renderContactsEd(); return; }
  if (t.dataset.cPrimary) { syncContacts(); const cs = drawer.draft.contacts; cs.unshift(cs.splice(+t.dataset.cPrimary, 1)[0]); renderContactsEd(); return; }
  if (t.dataset.snooze) { if (!busy) { busy = true; await reschedule(t.dataset.id, +t.dataset.snooze); busy = false; } return; }
  if (t.dataset.open) { openDrawer(t.dataset.open); return; }
  if (t.dataset.pick) { drawer.draft.ownerId = t.dataset.pick; drawer.picking = false; renderOwner(); return; }
  if (t.dataset.fu) { $("#f-follow").value = addDays(today(), +t.dataset.fu); return; }
  switch (act) {
    case "new": if (writable()) openDrawer(null); break;
    case "new-prop": if (writable()) openProposal(null); break;
    case "new-quote": if (writable()) openQuote(null); break;
    case "client-deal": { const l = leads.find(x => clientKey(x) === drawer.client); if (l) openDrawer(null, { company: l.company, contacts: l.contacts.map(c => Object.assign({}, c, { id: "n" + rid(), lastContactAt: null })), website: l.website, source: l.source || "Referral", stage: "interested" }); break; }
    case "import": openImport(); break;
    case "lead-new-p": openProposal(null, { leadId: drawer.id }); break;
    case "lead-new-q": openQuote(null, { leadId: drawer.id }); break;
    case "p-new-quote": openQuote(null, { leadId: $("#p-lead").value, proposalId: drawer.id, title: drawer.draft.title }); break;
    case "doc-save": t.disabled = true; await (drawer.kind === "proposal" ? saveProposal() : saveQuote()); if (t.isConnected) t.disabled = false; break;
    case "doc-del": drawer.confirmDelete = true; renderDocFoot(); break;
    case "doc-del-no": drawer.confirmDelete = false; renderDocFoot(); break;
    case "doc-del-yes": { const kind = drawer.kind; if (await save(kind === "proposal" ? "proposal_delete" : "quote_delete", { id: drawer.id })) closeDrawer(); break; }
    case "li-add": syncItems(); drawer.draft.items.push({ id: rid(), desc: "", qty: 1, rate: "" }); renderItems(); $(`#li-desc-${drawer.draft.items.length - 1}`)?.focus(); break;
    case "q-deal": { syncItems(); const l = leadOf($("#q-lead").value); if (!l) { toast("Link the quote to a client first."); break; } const tot = Math.round(quoteTotals(readQuoteForm()).total); await save("lead_value", { id: l.id, value: tot, quoteNumber: drawer.draft.number || "" }); break; }
    case "c-add": { syncContacts(); drawer.draft.contacts.push(blankContact()); renderContactsEd(); $(`#c-name-${drawer.draft.contacts.length - 1}`)?.focus(); break; }
    case "copy-shown": { const em = emailsOf(applyFilters(leads).filter(l => l.stage === "contacted")); copyText(em.join(", "), `Copied ${em.length} email${em.length === 1 ? "" : "s"}`); break; }
    case "bulk-copy": { const em = emailsOf(leads.filter(l => selected.has(l.id))); if (!em.length) { toast("The selected leads have no emails saved."); break; } copyText(em.join(", "), `Copied ${em.length} email${em.length === 1 ? "" : "s"}`); break; }
    case "close": closeDrawer(); break;
    case "save": t.disabled = true; await saveDrawer(); if (t.isConnected) t.disabled = false; break;
    case "log": t.disabled = true; await logActivity(); if (t.isConnected) t.disabled = false; break;
    case "del": drawer.confirmDelete = true; renderFoot(); break;
    case "del-no": drawer.confirmDelete = false; renderFoot(); break;
    case "del-yes": { const id = drawer.id; closeDrawer(); await save("leads_delete", { ids: [id] }); break; }
    case "pick": drawer.picking = true; renderOwner(); break;
    case "pick-cancel": drawer.picking = false; renderOwner(); break;
    case "assign-me": drawer.draft.ownerId = me.id; renderOwner(); break;
    case "unassign": drawer.draft.ownerId = null; renderOwner(); break;
    case "clear-filters": filters = { ...EMPTY_FILTERS }; store.set("filters", filters); render(); break;
    case "bulk-move": { const st = $("#bulk-stage").value; await bulk("leads_stage", { stage: st }); break; }
    case "bulk-me": await bulk("leads_mine"); break;
    case "bulk-del": bulkConfirm = true; render(); break;
    case "bulk-del-no": bulkConfirm = false; render(); break;
    case "bulk-del-yes": await bulk("leads_delete"); break;
    case "bulk-clear": selected.clear(); bulkConfirm = false; render(); break;
    case "imp-close": closeImport(); break;
    case "imp-done": imp = null; renderImport(); break;
    case "imp-back": imp.step = "pick"; renderImport(); break;
    case "imp-paste": { const v = $("#imp-paste").value; if (!v.trim()) { toast("Paste some rows first."); return; } loadImportText(v, "Pasted rows"); break; }
    case "imp-run": runImport(); break;
  }
});
$("#new-lead").addEventListener("click", () => writable() && openDrawer(null));
$("#import").addEventListener("click", openImport);
$("#new-prop").addEventListener("click", () => writable() && openProposal(null));
$("#new-quote").addEventListener("click", () => writable() && openQuote(null));
$("#export").addEventListener("click", exportCsv);
$("#flt-clear").addEventListener("click", () => { filters = { ...EMPTY_FILTERS }; store.set("filters", filters); render(); });
let qTimer = null;
ROOT.addEventListener("input", e => {
  const id = e.target.id;
  if (id === "flt-q" || id === "flt-min" || id === "flt-max") { clearTimeout(qTimer); const k = id.slice(4); const v = e.target.value; qTimer = setTimeout(() => setFilter(k, v), 150); return; }
  if (id === "f-value") updateWeighted();
  if (id.startsWith("li-") || id === "q-disc" || id === "q-tax") { syncItems(); updateTotals(); }
  if (id === "owner-q") searchPeople(e.target.value);
});
ROOT.addEventListener("change", e => {
  const el = e.target, id = el.id;
  if (["flt-stage", "flt-type", "flt-source", "flt-owner", "flt-due"].includes(id)) { setFilter(id.slice(4), el.value); return; }
  if (id === "f-stage") updateWeighted();
  if (el.dataset.stageFor) { moveStage(el.dataset.stageFor, el.value); return; }
  if (el.dataset.sel) { el.checked ? selected.add(el.dataset.sel) : selected.delete(el.dataset.sel); bulkConfirm = false; render(); return; }
  if (el.dataset.selAll) {
    const v = el.dataset.selAll, shown = applyFilters(leads).filter(l => v === "prospects" ? l.stage === "contacted" : l.stage !== "contacted" && (v !== "pipeline" || !collapsed.has(l.stage)));
    shown.forEach(l => el.checked ? selected.add(l.id) : selected.delete(l.id)); bulkConfirm = false; render(); return;
  }
  if (el.dataset.map) { imp.map[el.dataset.map] = el.value === "" ? null : +el.value; renderImport(); return; }
  if (id === "imp-stage") { imp.stage = el.value; renderImport(); }
  if (id === "imp-source") { imp.source = el.value; renderImport(); }
  if (id === "imp-channel") { imp.channel = el.value; renderImport(); }
  if (id === "o-period") { outreachDays = +el.value; store.set("outreach", outreachDays); render(); return; }
  if (el.dataset.qstatusFor) { setQuoteStatus(el.dataset.qstatusFor, el.value); return; }
  if (id === "q-lead") { $("#q-proposal").innerHTML = proposalOptions(el.value, ""); return; }
  if (id === "imp-follow") { imp.followDays = +el.value; renderImport(); }
  if (id === "imp-me") { imp.assignMe = el.checked; }
  if (id === "imp-file" && el.files[0]) { const f = el.files[0]; f.text().then(t => loadImportText(t, f.name)); }
});
document.addEventListener("keydown", e => {
  if (e.key === "Escape") { if (imp) closeImport(); else if (drawer) { if (drawer.picking) { drawer.picking = false; renderOwner(); } else closeDrawer(); } }
  if (!ROOT.contains(e.target)) return;
  if (e.key === "Enter" && e.target.matches(".pcard[data-pid]")) { e.preventDefault(); openProposal(e.target.dataset.pid); return; }
  if (e.key === "Enter" && e.target.matches("tr[data-qid]")) { e.preventDefault(); openQuote(e.target.dataset.qid); return; }
  if (e.key === "Enter" && e.target.matches("[data-open-p],[data-open-q]")) { e.preventDefault(); e.target.dataset.openP ? openProposal(e.target.dataset.openP) : openQuote(e.target.dataset.openQ); return; }
  if (e.key === "Enter" && e.target.matches("tbody tr[data-client]")) { e.preventDefault(); openClient(e.target.dataset.client); return; }
  if (e.key === "Enter" && e.target.matches("tbody tr[data-id],[data-open]")) { e.preventDefault(); openDrawer(e.target.dataset.id || e.target.dataset.open); }
  if (e.key === "Enter" && e.target.id === "a-text" && (e.metaKey || e.ctrlKey)) logActivity();
});
ROOT.addEventListener("submit", e => { e.preventDefault(); if (!drawer || !writable()) return; if (drawer.kind === "lead") saveDrawer(); else if (drawer.kind === "proposal") saveProposal(); else if (drawer.kind === "quote") saveQuote(); });
let dragPid = null;
ROOT.addEventListener("dragstart", e => { const c = e.target.closest?.(".pcard[data-pid]"); if (!c || !writable()) return; dragPid = c.dataset.pid; c.classList.add("is-dragging"); e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", dragPid); });
ROOT.addEventListener("dragend", e => { e.target.closest?.(".pcard")?.classList.remove("is-dragging"); ROOT.querySelectorAll(".col.over").forEach(c => c.classList.remove("over")); dragPid = null; });
ROOT.addEventListener("dragover", e => { const col = e.target.closest?.(".col[data-pstatus]"); if (!col || !dragPid) return; e.preventDefault(); ROOT.querySelectorAll(".col.over").forEach(c => c !== col && c.classList.remove("over")); col.classList.add("over"); });
ROOT.addEventListener("drop", e => { const col = e.target.closest?.(".col[data-pstatus]"); if (!col || !dragPid) return; e.preventDefault(); col.classList.remove("over"); setProposalStatus(dragPid, col.dataset.pstatus); });
ROOT.addEventListener("dragover", e => { const d = e.target.closest?.("#drop"); if (d) { e.preventDefault(); d.classList.add("over"); } });
ROOT.addEventListener("dragleave", e => { const d = e.target.closest?.("#drop"); if (d) d.classList.remove("over"); });
ROOT.addEventListener("drop", e => { const d = e.target.closest?.("#drop"); if (!d) return; e.preventDefault(); d.classList.remove("over"); const f = e.dataTransfer.files[0]; if (f) f.text().then(t => loadImportText(t, f.name)); });

function readHash() { const h = location.hash.slice(1); view = VIEWS.includes(h) ? h : "pipeline"; selected.clear(); bulkConfirm = false; render(); }
window.addEventListener("hashchange", readHash);

/* ---------- boot ---------- */
readHash();
reload();
})();
