# Decision log

Append-only. Each entry records what was decided **and why**, so a future conversation
doesn't reopen settled ground or unknowingly contradict it.

---

## 2026-08-11 — Fresh build rather than retrofitting STFC
**Decision:** build the platform as a new codebase; STFC stays where it is.
**Why:** STFC is live and mid-production with an end-of-month deadline. Adding `project_id`
across ~12 tables on a tool the team uses daily is unacceptable risk right now.
**Consequence:** two codebases while STFC runs. Fixes found in either get applied to both
until that show wraps.

## 2026-08-11 — Multi-project from day one, not a copy per project
**Decision:** the new platform supports many projects in one installation, even though STFC
won't move into it.
**Why:** copying the codebase per project means N diverging versions and a fix-it-twice tax
that erodes in practice. `project_id` is nearly free now and expensive to add later against
live data — exactly the position STFC is in.
**Consequence:** two codebases now, shrinking to one once STFC retires.

## 2026-08-11 — Crew is global, membership is per project
**Decision:** one org-wide `crew` table; `crew_projects` records assignment.
**Why:** people work across shows. Per-project crew duplicates them and makes cross-project
workload invisible.
**Consequence:** `crew_skills` references project-scoped steps, so skills are effectively
per project. Acceptable.

## 2026-08-11 — Pipeline dependencies are data
**Decision:** a `step_dependencies` table defines the workflow graph.
**Why:** STFC hardcoded parallelism (`$overlapsWithPrevious = ['render']`). Making it data
lets the same scheduler serve any pipeline shape, and directly delivers the "workflow map"
requirement.
**Consequence:** the scheduler walks a graph rather than a flat order, and must reject
cycles on save.

## 2026-08-11 — End dates are inclusive everywhere
**Decision:** `end_date` always means the last working day inclusive, in DB, API and UI.
Convert only at the boundary with external tools.
**Why:** STFC had PHP adding a day and Frappe Gantt adding another, two compensating errors
that hid each other and cost days of debugging.

## 2026-08-11 — Git with automated FTP deploy
**Decision:** private GitHub repo; GitHub Actions FTP-syncs changed files on push.
David uses GitHub Desktop, not the CLI.
**Why:** manual uploading caused repeated production bugs on STFC — a front-end file shipping
without its backend counterpart, and a cached JS file that made a fix appear not to work.
**Consequence:** `config.php`, `uploads/` and `sessions/` must stay excluded from both git and
the deploy, or credentials get overwritten and attachments deleted.

## 2026-08-11 — Frozen baseline + additive migrations
**Decision:** `install.sql` is the frozen v1 schema (fresh installs only, destructive);
all later changes are numbered files in `migrations/`, applied through `migrate.php`,
tracked in a `schema_migrations` table. Fresh install = import `install.sql`, then run
`migrate.php` to catch up.
**Why:** keeping `install.sql` in sync with every migration by hand is exactly the kind of
silent-drift chore that gets forgotten. Freezing it removes the chore. `migrate.php` shows
a dry-run list first and reports every file as applied/skipped/failed — nothing silent.
**Consequence:** migrations must be additive only; a table drop never appears in one.

## 2026-08-11 — item_key uniqueness for progress and schedule rows (app-maintained)
**Decision:** `progress` and `schedule` reference *either* a shot or an asset via nullable
`shot_id`/`asset_id`. Uniqueness per (step, item) is enforced through an `item_key` column
('s'+shot_id or 'a'+asset_id) with `UNIQUE (step_id, item_key)`, **set by the application
on every insert**.
**Why:** MySQL unique indexes ignore NULLs, so `UNIQUE (step_id, shot_id, asset_id)` would
silently allow duplicate rows whenever one column is NULL. First attempt used a stored
generated column, but the live server rejected it (error 1215): MySQL/MariaDB forbid ON
DELETE CASCADE foreign keys on the base columns of a stored generated column, and the
cascade clean-up is worth more than auto-computing the key.
**Consequence:** every code path inserting into these tables must set `item_key`; the
UNIQUE index still catches any row that forgets or miscomputes it.

## 2026-08-11 — Pipeline templates are seed rows the wizard copies
**Decision:** `pipeline_templates` / `pipeline_template_steps` / `pipeline_template_deps`
hold the 2D and 3D starting pipelines. The setup wizard copies a template into the new
project's `pipeline_steps` / `step_dependencies`, where it becomes fully editable.
**Why:** templates as data, not code — same principle as the dependency graph. Copying
(rather than referencing) means editing a template never disturbs existing projects.
**Consequence:** the baseline seeds a 3D template matching STFC's shape (Lighting ∥ Render
after Assembly, Comp waits for both) and a generic 2D template; both are editable in the DB
or, later, in a template editor.

## 2026-08-11 — A project exists from wizard step 1, as status 'setup'
**Decision:** the wizard creates the `projects` row on the first save and leaves it at
status `setup` until the review screen is finished. Progress is remembered in
`project_settings.wizard_step`, and the project list offers "Resume setup".
**Why:** the flow has seven steps and captures a lot; losing it to a closed tab or a
shared-hosting session expiry would be infuriating. Saving as it goes also means each step
validates against real stored data rather than a growing pile of session state.
**Consequence:** unfinished drafts appear in the project list. Every wizard screen offers
"Discard this draft", which is the only delete path and only works on `setup` projects.

## 2026-08-11 — Parallelism is one checkbox, stored as a graph
**Decision:** the workflow screen asks a single question per step — "runs at the same time
as the step above" — and converts the answers into `step_dependencies` rows. It converts
back the other way too: a step is shown as parallel when it depends on exactly the same
things as its predecessor.
**Why:** the architecture calls for a real dependency graph, but nobody should have to think
in graphs to set one up. The checkbox covers the real cases (STFC's Lighting ∥ Render, Comp
waiting for both) and round-trips a graph it didn't create — so a template's shape, or a
future visual editor's, still reads correctly here.
**Consequence:** the scheduler only ever sees the graph, so a richer editor can replace this
screen without touching it. Cycles are checked before any save; a failing check saves nothing.

## 2026-08-11 — Wizard saves are POST → redirect → GET, with flash messages
**Decision:** every wizard action posts, saves, sets a flash message and redirects.
**Why:** refreshing never resubmits, and each save gets to report what it did — how many
rows landed, how many were skipped as duplicates, and why. Several STFC bugs presented as
"nothing happened".
**Consequence:** row buttons (move, delete) carry their action in the submit button's
value, so a single form saves pending edits and performs the action together — HTML forms
cannot nest, and losing typed edits to a row button would be its own silent failure.

## 2026-08-11 — Wizard ships with paste-based entry; file import is Phase 3
**Decision:** the shots and assets screens accept a pasted list and (for shots) generate
numbered codes. CSV/Excel FILE upload with column mapping and alias resolution stays in
Phase 3 as the build order has it.
**Why:** keeps Phase 2 shippable end to end. Real file import needs the alias layer and the
unmatched-names report to be worth having, and that is Phase 3's whole subject.
**Consequence:** a project can be fully set up today by pasting a breakdown out of a
spreadsheet, which is how the data usually arrives anyway.

## 2026-08-11 — Step names are remembered globally in `step_library`
**Decision:** adding a pipeline step by hand records its name, `applies_to` and
`tracks_seconds` in a global `step_library` table. The wizard's "Add a step" field suggests
those names and fills in the settings they had last time. Seeded from the built-in templates
and from steps already in real projects (migration 002).
**Why:** a step typed fresh on every show is a step spelled differently on every show —
the same class of problem as the crew/asset alias layer, caught before it starts. The
library also makes a studio's real vocabulary visible rather than living in one person's head.
**Consequence:** it is a suggestion list only, never a constraint: projects still own their
own `pipeline_steps` rows and any name can be typed. Deliberately global (no `project_id`) —
cross-project reuse is the entire point. A typo entered once will linger as a suggestion;
a small management screen for pruning the list is worth adding if that becomes annoying.

## 2026-08-11 — Drag-to-reorder saves immediately
**Decision:** pipeline steps can be dragged by a handle; dropping submits the form and
saves the new order right away. The ↑ ↓ buttons stay.
**Why:** an order change sitting unsaved in the page is exactly the silent loss this app
tries to avoid, and it would be lost by navigating to the next wizard screen. The buttons
remain because HTML5 drag and drop has no keyboard equivalent.
**Consequence:** each drag costs a page reload. Reordering also re-derives the dependency
graph, so a warning is shown when the project has parallel steps that may have been
disturbed.

## 2026-08-11 — Episodes are a per-project setting, not an assumption
**Decision:** a project carries an `episodic` flag in `project_settings`. The shots screen
follows it: episode fields disappear when off, and a pasted line is read as
`SHOT, SECONDS` rather than `EPISODE, SHOT, SECONDS`. Before the toggle is ever used, the
answer is inferred from the data — a project whose shots already carry episodes is episodic.
**Why:** not every project is a series. A commercial or short is one flat shot list, and an
episode column on that screen is noise that implies a required field.
**Consequence:** turning episodes off hides them but never deletes them, and says so. The
same flag will decide grouping in the timeline and reports later.

## 2026-08-11 — Asset types are a dropdown built from data, still free text underneath
**Decision:** `assets.asset_type` stays free text. The wizard offers a dropdown built from
five built-in starters plus every type already used on any project, with a "new type" field
beside it. Assets can be selected and retyped in bulk after the fact.
**Why:** a fixed list in code is what forced a schema change on STFC to add Props; free text
alone invites "Character" / "character" / "Char". Building the list from data gives
consistency without a constraint, and matches the step library's approach.
**Consequence:** a typo becomes a new "type" — hence the bulk retype control, which makes
fixing it a two-click job rather than a reason to delete and re-paste.

## 2026-08-11 — Headcount is fractional
**Decision:** `pipeline_steps.planned_headcount` and `headcount_log.headcount` are
`DECIMAL(5,2)`; the planning inputs accept any decimal (migration 003).
**Why:** someone splitting a week between two shows is 0.5 on each, and rounding that to a
whole number makes every quota derived from it wrong. `crew_projects.allocation_pct` already
recognised part-time allocation; this makes the estimate side agree.
**Consequence:** the "needs N people" warning now compares against the *unrounded*
requirement, so 2.2 people needed against 2.5 planned no longer raises a false alarm.
The wizard checks the column's TYPE (not just its existence) before running, because MySQL
would otherwise round 2.5 to 3 on save without a word.

## 2026-08-11 — The platform owns the production data; Plaky is not the source of truth
> **Superseded the same day — see "Stay on Plaky for now" below.** The reasoning here still
> holds and is the intended destination; only the timing changed. Don't act on this entry
> alone.

**Decision:** new projects are tracked in the platform itself. Status, assignee and progress
are entered here, not exported from Plaky. Plaky is stepped away from rather than integrated
with.
**Why:** David's call. Every day spent exporting a CSV is a day the data is stale, and the
whole class of name-matching bugs on STFC (`Animator.wasi` vs `Wasi Ali Khan`,
`RomulanOfficer01` vs `Romulan Officer`) exists only because two systems had to be
reconciled. Owning the data also means status, schedule and quota finally read from one place.
**Consequence — this reshapes the build order.** The daily import layer is no longer the next
phase; a tracking surface is. Specifically:
- **Statuses become data owned by the platform.** STFC mapped Plaky's ten statuses onto
  none/delivered/approved via `status_mapping`. The platform needs its own `statuses` table
  with the same `maps_to` idea, editable per project.
- `progress.status` stops being "raw external status text" and becomes a reference to one of
  those statuses. `assignee_raw` and `external_id` stay — they cost nothing and are still
  wanted for one-time imports.
- **The alias layer survives but demotes.** It is no longer a daily necessity; it matters for
  the one-time import of an existing breakdown or spreadsheet. Build it with that importer,
  not before.
- **Something has to replace what Plaky does for people.** Deciding who updates status —
  a coordinator centrally, or each artist for their own work — is the scope fork for the
  tracking phases. See the build order in `../START_HERE.md`.

## 2026-08-11 (later) — Stay on Plaky for now, but build the schema for owning the data
**Decision:** reverses the entry above, same day. Projects keep using Plaky as the working
tool and the platform imports from it. The schema, however, is built *as if* the platform
owned the data — so switching later is a change of habit, not a migration.
**Why:** replacing a tool a team already relies on daily is a big lift, and a half-built
tracker is worse than the thing it replaces. Deferring costs almost nothing **provided the
shape is right from the start** — which is exactly the lesson STFC taught in reverse, where
retrofitting `project_id` onto live data became the expensive scenario we are avoiding.

**Consequence — the structural move that makes the switch cheap.** The platform's own status
vocabulary gets built *with* the importer, not after it:

- `statuses` (project-scoped, carrying `maps_to` none/delivered/approved) is the platform's
  own list from day one. It is the thing `progress` points at.
- `status_mapping` translates incoming Plaky text onto those statuses. This is STFC's exact
  pattern, with one crucial difference: the mapping feeds platform-owned rows instead of
  *being* the source of truth.
- `progress.status_id` references a status; `progress.status` (raw incoming text) and
  `assignee_raw` stay, so an import can always report what actually arrived.
- On the day the team switches, **the importer simply stops running** and people set the same
  statuses by hand. No schema change, no data migration, no reporting rewrite.

**Also:** the alias layer is load-bearing again. Two systems have to be reconciled daily, so
`crew_aliases`, `name_aliases` and the unmatched-names report go back into the next phase —
build them *with* the importer, exactly as the STFC lessons say.

## 2026-08-11 — Hosting stays as it is for now
**Decision:** keep both tools on the current shared host; no migration, no further
investigation for the moment.
**Why:** it is working, and the platform currently carries one user and no traffic.
**Consequence:** the 508 flapping is unexplained rather than resolved — the cPanel has no
Resource Usage panel, so only the host can say whether limits are being hit. Revisit if
outages recur or once the team is actually using the platform daily.

## 2026-08-12 — A step can have several people on it
**Decision:** add `progress_crew (progress_id, crew_id)`. `progress.crew_id` stays as the
primary/first assignee so existing queries keep working; the join table is the full answer.
`assignee_raw` still keeps the original string exactly as it arrived.
**Why:** the live Plaky board already does this — `Júnior Fonseca,Michal Pilch` on 22 rows —
and per-artist reporting that silently drops the second name is worse than useless. Cheap
now, expensive against live data later, which is the whole reason this platform exists.
**Consequence:** per-artist reporting must decide how to split credit for a shared task
(halve it, or count it for both). Flag it when reporting is built; counting for both is
probably right for "who touched this" and wrong for "how much did each person deliver".

## 2026-08-12 — Statuses carry `is_blocked`; "On Hold" means blocked, not idle
**Decision:** the `statuses` table gains `is_blocked TINYINT`. `On Hold` maps to
`maps_to='none'` **and** `is_blocked=1`.
**Why:** David's description — work that *cannot* proceed, not work that merely hasn't
started. Those are the same for "is it finished?" but completely different for planning: a
blocked shot is a thing someone must unblock, not a thing an artist can pick up tomorrow.
Collapsing both into "none" throws that away.
**Consequence:** blocked work still counts in remaining work — it has to happen eventually
and hiding it would make a plan look achievable when it isn't — but it is reported
separately, so "14 shots blocked" reads as the escalation it is. Whether the scheduler
should decline to *assign* blocked items is a question for that phase.

## 2026-08-12 — Time is shown in hours, stored in minutes, imported with a declared unit
**Decision:** **hours** are the unit everywhere a person sees or types a time** —** it is what
artists work in and invoice in. Storage stays `progress.time_spent_minutes` as a whole-number
integer, converted at the edges (960 → "16 h", "7.5 h" → 450).
**Why store minutes then:** an integer count of minutes is exact. Hours as a decimal quietly
rounds — 20 minutes is 0.333…, and a column of those summed against an invoice drifts. The
column name carries its unit so nobody has to guess, which is the cheapest possible defence
against the classic unit bug.

**The import must be told which unit the file is in.** Today's export is minutes, inferred
from the values: every entry but one is a multiple of 30, and as minutes they read 3 h–17 h
per task (Concept 7–17 h, Extra Anim & Polish 3–6.5 h) — credible; as seconds they would be
3–17 minutes per task — not. The outlier 3,340 is 55.7 h of accumulated supervision on a
"CG Sup" item.

**But David intends to change how time is logged.** The moment Plaky emits `16` meaning
16 hours where it used to emit `960`, an importer with minutes hardcoded would record 16
minutes and say nothing — and the error lands specifically in the numbers meant for checking
invoices. So the import screen asks for the source unit (minutes or hours), shows a worked
example of the first few values in both readings, and records what it used. A unit change
becomes a deliberate choice rather than a silent corruption.
**Why it matters beyond the import:** David wants this for two real jobs — checking artist
invoices against logged time, and comparing the planned start/due dates against the time a
task actually took. That is the feedback loop that makes future estimates better, and it is
the first requirement that touches cost.
**Consequence:**
- Only 20 of 1,918 rows carry it today, so Plaky's time data is nearly empty. The value
  arrives when the platform owns time entry — a `time_logs` table (crew, task, date,
  minutes) rather than one running total per task. Import the total now; build the log later.
- Invoice checking needs a rate per person (`crew.rate` + its unit). Not built yet, and it
  makes `crew` hold pay data — worth deciding who may see that screen before building it.
- Projected-vs-actual needs `schedule` (planned dates) and `time_spent` side by side; both
  will exist, so this is a reporting job, not a schema one.

## 2026-08-12 — Money lives in a separate application and a separate database
**Decision:** contracts, budgets, rates, invoices and anything else financial are **not** in
this platform. They live in a separate application with its own logins, backed by its own
MySQL database. The two communicate, but only in one direction: the finance side may read
production data (who did what, hours logged); the production platform can read nothing
financial.
**Why:** David's requirement — this is admin/executive information and no one else may see
it. A role check inside one application is a single `if` away from failing, and the thing it
protects is people's pay. Splitting it at the **database credential** level means the
production platform *cannot* leak rates even if it has a bug, because its MySQL user has no
rights to those tables at all. That is a guarantee an application can't give about itself.

**Consequence:**
- **`crew` does not gain a `rate` column.** This supersedes the note in the time-tracking
  entry above, which assumed rates would live there.
- Invoice-versus-time reporting is a **finance-side** screen. It reads
  `progress.time_spent_minutes`, `schedule` and `crew.name` from the production database and
  joins them to rates it holds privately.
- The join key is `crew.id` / `projects.id`. **Those ids must stay stable and must never be
  reused** — a recycled id would silently attach one person's hours to another's rate.
- Enforced by two MySQL users: the finance app gets a SELECT-only user on the production
  database, limited to the tables it needs. The production app gets nothing on the finance
  database. Neither `config.php` contains the other's credentials.
- Separate login, not a role in the existing `users` table. Different app, different accounts,
  different session cookie.
- Cheap to honour now, near-impossible to retrofit once budget data is sitting in shared
  tables — the same reasoning as `project_id` on day one.

---

## Still open

- **What Plaky is actually relied on for** day to day — notifications, comments, phone
  access, or just the board. Worth listing before the eventual switch, so the platform
  replaces what matters rather than guessing.
- **Who will update status** once the platform does own the data — a coordinator centrally,
  or every artist logging in. The second needs logins linked to crew, roles and permissions.
  Not urgent, but it decides how big that phase is.
- **Where the finance app lives** — recommendation is its own subdomain rather than a folder,
  because a different origin means a bug in the production platform cannot read the finance
  app's pages or act as its logged-in user. A folder shares one origin, so the browser
  offers no protection between them. Not yet decided.
- **Splitting credit on shared tasks** — when two people are on one step, does per-artist
  reporting count it for both, or halve it? Probably both for "who worked on this" and
  halved for "how much did each deliver". Settle it when reporting is built.
- Whether STFC's historical data is imported later, or the dashboard simply retires.

### Settled earlier
- ~~Hosting~~ — staying put for now (see above).
- ~~Platform URL~~ — `mana.davidsaga.com`, served from `public_html/mana`.
- ~~Plaky as source of truth~~ — yes for now, with the schema built so that changing our
  mind later is additive. See the two paired entries above.
