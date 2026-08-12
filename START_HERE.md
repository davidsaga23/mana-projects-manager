# Mana Production Platform — Start Here

**Read this first.** It carries everything decided so far so a new conversation can begin
without re-deriving context.

- **Owner:** David Salinas Garfias (Mana Animation Studio) — *not a developer; explain
  technical trade-offs in plain terms and flag anything destructive before doing it.*
- **Created:** 2026-08-11
- **Repo:** `mana-projects-manager` (private, GitHub) — local copy at `D:\Work\Mana\mana-platform`
- **Status:** Phase 1 **live** at <https://mana.davidsaga.com> — schema installed, login
  working, deploys automatic. Phase 2 (setup wizard) built 2026-08-11, pending first real
  use. Next: Phase 3 (shot/asset file import + the alias layer).
- **Deploy:** push to `master` in GitHub Desktop; GitHub Actions FTP-syncs to
  `public_html/mana` within ~30s. After deploying a change that adds a migration, open
  `migrate.php` once.
- **Predecessor:** the STFC S02 dashboard (see “Lessons” below) — a working, single-project
  tool that this platform generalises.

---

## 1. What we're building

A **multi-project production tracking platform** for a small animation studio. Mana runs
several shows at once; data, reporting and tracking are the core of how they operate.

The centrepiece is a **New Project setup flow** that captures everything needed to stand up
a project, then drives scheduling and reporting from it:

| Input | Notes |
|---|---|
| Project name | |
| Project code | Short slug, e.g. `STFC2` |
| Type | 2D / 3D / Mixed / Other |
| Asset breakdown | Import (CSV/Excel) **and** create in-page — both must work |
| Shot breakdown | Import **and** create in-page |
| Pipeline steps | Differ by project type; must be creatable on the fly |
| Pipeline workflow | Order **and parallelism** — a real dependency graph, not a flat list |
| Estimated dates | Per step; seeds the first-pass timeline, editable later |
| Estimated headcount | Per step |

### The scheduling model (the thing that makes it valuable)

It must work in **both directions**, as the STFC dashboard already does:

- **Forward:** auto-generate a first-pass timeline → export `.gan` → refine in GanttProject
  → re-import as the definitive schedule.
- **Reverse:** from shot/asset counts + deadline + headcount, derive the **minimum quota per
  artist per day**. David considers this the single biggest driver of project success.

---

## 2. Decisions already made

### 2.1 Fresh build, not a retrofit — but ONE platform, not a copy per project
STFC is live and mid-production with an end-of-month deadline; schema surgery on it is too
risky. So the platform is built fresh.

**Important refinement David agreed to:** the new platform is **multi-project from day one**,
even though STFC will never move into it. New projects become rows in *one* system rather
than new folders. This avoids ending up maintaining N diverging codebases.

- STFC stays on the old dashboard, effectively frozen — bug fixes only.
- Bug fixes discovered anywhere get applied to the platform **and** back-ported to STFC
  while that show is running.
- After STFC wraps: optionally import it as a completed project, or retire it.

`project_id` in the schema from the start is nearly free; adding it later against live data
is the expensive scenario we're deliberately avoiding.

### 2.2 Crew is global, assigned per project
One org-wide crew list; a join table records who is on which project. This enables
cross-project workload views (e.g. one artist booked on two shows the same week) — currently
impossible and considered valuable.

### 2.3 Pipeline workflow is data, not code
In STFC, step parallelism was hardcoded (`$overlapsWithPrevious = ['render']` — Lighting and
Render run in parallel, Comp waits for both). In the platform this becomes a
`step_dependencies` table. Parallelism then falls out naturally: two steps sharing a
dependency run side by side, and the scheduler needs no special cases. **This is the highest-
value structural change** — it makes the scheduler work for any pipeline shape, 2D or 3D.

### 2.4 Git + automated FTP deploy
Manual file-by-file uploading caused repeated production bugs on STFC (see Lessons). The
platform uses a private GitHub repo with a GitHub Actions workflow that FTP-syncs changed
files to cPanel on push. David is non-technical and will use **GitHub Desktop** (GUI), not
the command line. Scaffolding already exists in this folder — see `docs/GIT_SETUP.md`.

---

## 3. Lessons from STFC — read before designing

Hard-won, all from real incidents:

1. **Partial deploys are the #1 source of bugs.** A front-end change shipping without its
   matching backend file caused a filter to silently do nothing. Automated deploy exists
   specifically to kill this class of bug.
2. **Cache-bust every asset.** Only `style.css` was versioned; a fixed `timeline.js` sat
   cached in the browser for a whole debugging round. Use an `asset_ver()`-style helper on
   **all** JS/CSS from day one.
3. **Never parse `YYYY-MM-DD` with `new Date()` in JS.** It parses as UTC, reads back in
   local time (project runs `America/Mexico_City`, UTC−6) and silently walks dates backwards.
   Timeline bars lost 2 days per edit cycle until this was found.
4. **Agree on inclusive vs exclusive end dates once, globally.** Frappe Gantt treats `end` as
   the inclusive last day and extends it internally; the PHP was adding a day on top, so
   every bar rendered one day too long. Two compensating errors hid each other for weeks.
5. **Don't conflate headcount with concurrency.** The scheduler used headcount as "tasks in
   flight"; steps with no headcount logged defaulted to 1, serialising 188 shots into ~9
   months. Concurrency must be derived from the deadline:
   `ceil(remaining_tasks × days_per_task ÷ working_days_available)`.
6. **Name matching between systems needs an alias layer from day one.** Plaky assignees
   (`Animator.wasi` vs `Wasi Ali Khan`) and asset names (`RomulanOfficer01` vs
   `Romulan Officer`) silently dropped data into an "unassigned" bucket. Build the alias
   table *and* an "unmatched names" report at the start.
7. **Surface silent failures.** Several bugs looked like "nothing happened". Every import,
   export and notification should report what it did, what it skipped, and why.
8. **Shared-hosting sessions expire early.** Neighbouring accounts' garbage collection
   deletes your session files. Use a private `sessions/` directory. (Note: STFC also sets
   `session.lazy_write=0`, which increases disk I/O — see Open Questions.)
9. **Never write a closing PHP tag inside a comment.** PHP acts on `?>` even in a
   `//` comment — it ends PHP mode, so the rest of the file is dumped to the browser as
   text and every function below it goes undefined. Cost the platform's first install
   attempt. (Found during the Phase 1 build, not on STFC.)
10. **PHP `mail()` cannot authenticate.** Send via authenticated SMTP. Match port to
   encryption: **465 = SSL, 587 = STARTTLS** — mixing them hangs. cPanel mail servers usually
   need `verify_peer = false` (self-signed certs).

---

## 4. Suggested build order

Settled 2026-08-11: Plaky stays the working tool for now, and the platform imports from it —
**but the schema is built as though the platform owned the data**, so switching later is a
change of habit rather than a migration. Read the two paired Plaky entries in
`docs/DECISIONS.md` before designing anything here.

1. ~~**Schema + migrations**~~ — done, live
2. ~~**Project setup wizard**~~ — done (paste-based entry)
3. **Import + the status model** — built 2026-08-12, pending first real use.
   `migrations/004_status_model.sql`, `import.php`, `statuses_edit.php`, `aliases.php`,
   `project.php`, and the helpers in `includes/{statuses,names,plaky_import}.php`.
   These shipped together:
   **Read `docs/PLAKY_EXPORT.md` first** — a real export has been analysed and the
   traps in it are written down (a `Lightning` typo, `(inactive)` name suffixes,
   multi-assignee fields, `DD/MM/YYYY` dates, an unmapped `On Hold` status):
   - `statuses` — the platform's OWN vocabulary, project-scoped, carrying STFC's
     `maps_to` none/delivered/approved. `progress` points at these rows.
   - `status_mapping` — translates incoming Plaky text onto those statuses.
   - CSV/Excel upload with column mapping, for shots, assets and progress.
   - The alias layer (`crew_aliases`, `name_aliases`) **and** the unmatched-names report,
     built alongside the importer, never after it.
4. **Scheduler** — forward pass + reverse quota maths, driven by the dependency graph
5. **Timeline UI** — Gantt view, `.gan` export/import round trip
6. **Reporting** — per-artist productivity, cross-project workload
7. **Tickets / tasks** — port from STFC (it works well; see that codebase)

**A separate track — the finance side.** Contracts, budgets, rates and invoices are
*deliberately not in this codebase*. They belong to a separate application with its own
logins and its own database, which may read production data but never the reverse. Don't add
a rate column to `crew` "just for now" — see `docs/DECISIONS.md`, 2026-08-12.

**Later, when Plaky is dropped:** a tracking grid (shots/assets × steps, status and assignee
set in place) and — if artists update their own work rather than a coordinator doing it —
logins linked to the crew table, with roles. Because step 3 already owns the status
vocabulary, that phase adds screens; it does not change the schema underneath.

Ship each phase end-to-end before starting the next.

---

## 5. Open questions

- **Hosting.** STFC's shared host (`md-13.webhostbox.net`) currently flaps on and offline —
  suspected cPanel resource limits (508). Worth resolving *before* putting a second
  production tool on the same box. Check cPanel → Metrics → Resource Usage.
- **Where does the platform live?** URL/subdirectory not yet decided; needed for the deploy
  config (`docs/GIT_SETUP.md`).
- **Does STFC data get imported later,** or does that dashboard simply retire?
- **Is Plaky still the source of truth** for the new project, or does the platform own the
  data directly? STFC's whole import layer assumes daily Plaky CSV exports.

---

## 6. How to work in the new chat

- Start by reading this file and `docs/ARCHITECTURE.md`.
- Work **one phase per conversation**, not one feature — related work should share context.
- Use `/compact` when a chat gets long rather than starting over.
- Update `docs/DECISIONS.md` whenever something is settled, so it survives context loss.
- STFC's code is a useful reference for working patterns:
  `D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\`
