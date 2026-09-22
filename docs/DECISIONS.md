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

## 2026-08-12 — Early planning uses a project estimate, not placeholder shots
**Decision:** a project carries `estimated_seconds`, `estimated_shots` and `estimated_assets`
in `project_settings`. The quota uses them **only when no real shots or assets exist**, and
labels any figure derived from them "est.". The moment a breakdown arrives, real counts win
and the estimate is ignored.
**Why:** at the start of a project nobody knows the shot count — that settles when the
animatic is cut and labelled — but the runtime is usually contracted. Seconds are the honest
early number, which is why STFC tracked `total_target_seconds` from day one.

The alternative, generating placeholder shots, is actively harmful: they inflate every quota
with work that may not exist, and they *look* like real data. When the real breakdown lands
with different codes, the import creates the genuine rows alongside the fakes and every
figure is quietly wrong until someone notices and prunes them. An estimate can't be mistaken
for a breakdown; a fake shot can.
**Consequence:** the recommended project start order is Plaky board first (the breakdown is
entered once, there), then the platform wizard for basics/pipeline/workflow, then the import,
then dates and headcount. Only Plaky → platform is automated; there is no export the other
way. See `ROADMAP.md` if that becomes a nuisance.

## 2026-08-12 — Three login roles, with read-only enforced in one place
**Decision:** `users` gains `role` (admin / editor / viewer), `active` and `last_login`
(migration 005). Admin manages accounts and runs migrations; editor does all production work;
viewer can read everything and change nothing. Existing accounts became admins.
**Why:** STFC deliberately had no read-only accounts — everyone who could log in could edit
everything. Fine for three people who all ran the show; wrong the moment an assistant needs
to see the numbers without being able to move a deadline.
**Consequence — the enforcement point matters more than the roles.** View-only is applied
inside `require_login()`, which every page already calls, by refusing any POST from an
account that can't edit. Every write in this app is a POST, so that single check covers the
whole surface *including screens built later* — nobody has to remember to add a guard. The
alternative, a check per page, fails the first time someone forgets one, and fails silently.
- The role is read from the database on every request rather than cached in the session, so a
  demotion or a switch-off takes effect on the next page view instead of the next login.
- `current_user()` falls back to treating everyone as an admin if the role columns don't
  exist yet, because locking a working site out over a pending migration would be worse than
  the missing feature.
- **None of this touches money.** Contracts, budgets and rates are in a separate application
  with separate accounts and a separate database; no role here reaches them.

## 2026-08-12 — Working days mean Mon–Fri **minus a studio calendar**
**Decision:** a `non_working_days` table (studio-wide, not per project) holds public holidays
and shutdowns. `working_days_between()` and `working_days_list()` read it, so every quota
already on the review screen changed the day migration 010 was applied — this was never only
a scheduler concern.
**Why:** the maths counted Mon–Fri and nothing else, so every Mexican public holiday was
treated as a day somebody could finish a shot on. That error is always in the *optimistic*
direction, which is the worst one for a deadline.
**Consequence:**
- Studio-wide, with `day` as the primary key. A nullable `project_id` plus
  `UNIQUE (project_id, day)` was rejected for the reason `item_key` exists: MySQL unique
  indexes ignore NULLs, so the same holiday could be stored twice.
- The migration seeds **statutory days only** (Ley Federal del Trabajo art. 74, 2026–2027).
  Semana Santa, the 24th and the 31st are offered as one-click suggestions on the calendar
  screen instead — they are near-universal but they are Mana's choice, and seeding a day off
  that the studio actually works would pad every schedule silently.
- `crew_absences` is **deliberately not built yet**, though `ROADMAP.md` pairs it with this
  work. The scheduler assigns no real people (see below), so there is nothing for a person's
  days off to act on. Build it with crew assignment, or it is a table nothing reads.

## 2026-08-12 — Statuses gain `is_excluded`: "this item doesn't pass through this step"
**Decision:** a third flag beside `maps_to` and `is_blocked`. `N/A` carries it by default.
**Why:** `is_blocked` already separates "cannot proceed" from "not started". This is the case
neither covers — the work does not exist. A shot with no FX, a prop that needs no rig.
Without it the scheduler has to plan time for work nobody will ever do, and the item sits in
the plan forever looking unfinished.
**Consequence:** it is also what lets `coverage_pct` stop being a guess. Coverage says *how
much* of a step there is before anyone knows *which* items; these rows say which, once they
do. Real data wins over the estimate, exactly as a real shot count already beats
`project_settings.estimated_shots`.

## 2026-08-12 — A step whose coverage is still a guess is drawn as one band
**Decision:** when `coverage_pct < 100` and nothing has been marked not-applicable yet, the
forward pass writes **one** `schedule` row for the whole step (`item_key = 'band'`, both item
columns NULL) rather than per-item rows. It splits into real per-item dates the moment
coverage reaches 100% or some items are marked N/A.
**Why:** David's call, asked directly. "FX covers 30% of the show" gives the right start
date, finish date and headcount, but naming *which* 56 shots would be invention — a
supervisor would see FX planned on a shot that has none. The alternative, giving all 188
shots a bar shortened to 30%, is worse: every individual bar is then a fiction.
**Consequence:** the same shape is used for a project with no breakdown at all, drawn from
`project_settings.estimated_*`, and for whole-project deliverables (`item_key = 'p<id>'`, the
convention the importer already uses). A band can never be mistaken for a breakdown because
it has no item names in it.

## 2026-08-12 — Lanes, with placeholder names — the scheduler assigns no real people
**Decision:** `schedule.lane` records which parallel track a task sits in. `crew_id` is left
NULL. The name shown is derived from the step and the lane number — "Compositing 01",
"Compositing 02" — at display time, never stored.
**Why:** David's call. There is no record yet of who does which discipline (`crew_skills`
exists but nothing populates it), so round-robin assignment would confidently put a
compositor on rigging, and a real name on a task reads as a real assignment. A numbered lane
says the useful thing — *this step needs two compositors* — without claiming who they are.
**Consequence:** deriving the label rather than storing it means renaming a step renames its
lanes, with no stale copy left behind. When the crew screen exists, a lane maps to a person
and `crew_id` fills in underneath without the schedule changing shape.

## 2026-08-12 — Headcount is used as planned, even when it isn't enough
**Decision:** where `planned_headcount` is set, that is the number of lanes — full stop. The
scheduler does **not** quietly add people until the deadline is met. Falling short shows up
as a late finish, reported per step and for the project. Where headcount is *not* set, the
lane count is derived from the deadline and never defaulted to 1.
**Why:** the two failure modes are opposite and both bad. Defaulting to 1 is STFC lesson 5 —
it serialised 188 shots into nine months. But inventing lanes to hit the date draws a
schedule for a team that doesn't exist and hides the single fact a producer needs. Using the
real team and reporting the overrun is the only version that is true.
**Consequence:** the derived figure is capped at the number of items — more lanes than there
are things to work on is meaningless, and the cap is also what stops an already-passed
deadline asking for four thousand artists.

## 2026-08-12 — The scheduler plans blocked work, and says how much
**Decision:** settles the question left open by the `is_blocked` entry above. Blocked items
are scheduled like any other, counted separately, and flagged in the plan.
**Why:** consistent with what was already decided for reporting — blocked work still has to
happen, and leaving it out would make a plan look achievable when it isn't. It is surfaced
separately so it reads as the escalation it is rather than disappearing into a total.

## 2026-08-12 — Schedule maths is fractional; only the dates are whole days
**Decision:** the forward pass works in fractional working-day *indices* on an axis anchored
at the project start, and converts to real dates only at the end. `schedule.source` records
what wrote a row so a re-run replaces its own work and nothing else.
**Why:** the planning screen explicitly invites "0.5 days per item". Rounding each task up to
a whole day would double the length of any step made of them. Working fractionally lets two
half-day tasks share a date, which is what actually happens. Handover between steps still
moves in whole days — a shot passes to the next step the following morning, not mid-
afternoon.
**Consequence:** both dates stay INCLUSIVE, as everywhere else. `is_manual = 1` rows are read
(they gate what follows them) but never rewritten, and the pass skips any (step, item) that
already has one — so nothing it writes can collide with a hand-planned row.

## 2026-08-12 — Retake allowance is a project-level percentage on days-per-item
**Decision:** `project_settings.retake_pct` (no migration — the table is key/value). It
multiplies **days per item**, never the work units. Set on the wizard's Basics screen,
applied by `step_quota()`, the live check on Dates & headcount, and the CSV export.
**Why:** every projection Mana writes carries one — the reference sheet applies "+10% RTK"
to each line. Retakes are a predictable cost, and planning without them plans for a world
where nothing is ever revised.
**Why days-per-item and not work units:** 22 assets are still 22 assets after a retake. What
changes is how long each one takes, and therefore the crew a date needs. Scaling the units
instead would corrupt every count on the progress screens.
**Consequence:** the figure is duplicated in `assets/wizard.js` for the live check, as the
quota maths already is — both carry a comment saying they must move together. Capped at 200%
on save, because a mistyped 1000 would silently triple the crew.

## 2026-08-12 — CSV export of the setup, deliberately without money
**Decision:** `export.php` emits one row per pipeline step — work units, coverage, days per
unit, retakes, base and total person-days, person-months, planned and required headcount,
dates. Plus `?what=items` for the plain shot and asset list.
**Why:** it feeds an expense projection. The reference sheet's own Notes column
("20 shots × 2 d/u + 10% RTK = 44 d ÷ 3 artists") is arithmetic the platform already holds,
so the export hands over finished sums rather than raw data to be re-derived. The Notes
column is reproduced in the same sentence form, so the numbers can be checked without
opening the app.
**Consequence:** it carries no rates and no costs — those belong to the separate finance
application. This file is the bridge between the two: production numbers out, money applied
on the other side. Written with a UTF-8 byte-order mark, or Excel mangles names like
"Santiago Bernabéu".

## 2026-08-13 — Daily snapshots are taken on page load, not by cron
**Decision:** `daily_snapshots` finally gets a writer (`includes/snapshots.php`), called from
`require_login()` on GET requests. The first person to open the app on a given day records
every active project's position. Migration 011 adds `items_*` (steps apply to assets too, not
just shots), `items_total`, `blocked_count` and an index on `snapshot_date`.
**Why:** David's call — no cPanel cron to configure, and it starts working the moment the
code is deployed. The urgency is the point: **a report screen can be built whenever, but
history cannot be backfilled.** The table had existed since v1 with nothing writing to it, so
every day was being lost permanently.
**Consequence:**
- On a day nobody opens the app, no snapshot is taken. That gap is *reported*
  (`snapshot_gaps()`) rather than hidden, because an unrecorded week and a flat week look
  identical on a chart otherwise.
- `items_total` is stored, not derived. The breakdown grows — a shot added in October would
  otherwise make every September percentage recompute against a total that didn't exist yet,
  silently rewriting history.
- A row is written for **every** step, including ones with nothing logged. Zeroes are data; a
  line that just begins partway through reads as "work started here".
- Loaded from `functions.php`, never from `auth.php`. Every page does `require auth.php` then
  `require functions.php` **plain, not `_once`** — so anything auth.php pulls in early gets
  executed twice and fatals on redeclaration.

## 2026-08-13 — Crew is planned as SLOTS; a placeholder becomes a person by being renamed
**Decision:** reverses the Phase 4 position that the scheduler assigns nobody. A step's
`planned_headcount` generates named slots — "Animation 01", "Animation 02" — as rows in
`crew` with `is_placeholder = 1`, tied to a lane by `crew_lanes (project_id, step_id,
lane_no, crew_id)` (migration 012). The scheduler assigns `schedule.crew_id` to them.
**Why:** David's correction, and it exposes a wrong assumption. Phase 4 treated crew as
something that arrives from Plaky once the team exists. **A crew is never complete until the
show nearly is** — people arrive as their department starts, so waiting for real names means
never being able to plan at all. Planning against slots is how a production office actually
works.
**Consequence — the part that makes it worth doing:** hiring someone *renames the slot* and
clears the flag. The crew row's **id never changes**, so every scheduled task, every progress
record and all the history follow automatically — no re-linking, no migration of assignments.
The old slot name is kept as a `crew_aliases` entry, so an import still saying "Animation 02"
resolves to the right person.
- Slots are rows in `crew`, not a parallel table: `schedule.crew_id`, `progress.crew_id`,
  `progress_crew` and `crew_projects` all point at `crew` already, and `is_team` established
  the precedent that it holds rows which aren't a person. A separate table would force every
  future report to understand two kinds of assignee.
- `sync_project_lanes()` only ever **adds**. Dropping headcount from 3 to 2 never deletes the
  third slot, which may already have work planned against it — surplus is reported and a
  person decides.
- No unique constraint on `crew.name`: "Animation 01" legitimately exists on two shows at
  once, and forcing a project prefix would put "RTC · Animation 01" on every timeline bar.
  Which project a slot belongs to is `crew_lanes.project_id`'s job.
- This supersedes the Phase 4 note that lane names are derived at display time. They are only
  derived as a **fallback**, for a project with no slots generated yet.

## 2026-08-13 — Near-duplicate asset types are detected and offered, never auto-merged
**Decision:** `similar_asset_types()` flags types that differ only by case, spacing or a
trailing "s". The Assets screen shows the pair with a one-click merge; the Dates & headcount
screen warns when a step is scoped to one spelling while assets carry another. Nothing merges
by itself.
**Why:** found on real data — six assets typed "Environments", one typed "Environment", and a
pipeline step scoped to the singular. The step counted **1 of 7**, reported "1.0 Environment
assets", and looked completely healthy. Every quota, headcount and scheduled date derived
from it was short by a factor of seven with nothing on screen suggesting a problem.
The 2026-08-11 free-text-types entry predicted "a typo becomes a new type" and answered it
with the bulk retype control — which is the right fix, but only helps someone who has already
noticed. This is the noticing.
**Consequence:**
- The winning spelling is **the one a pipeline step points at**, then a built-in starter,
  then sheer count. Not the most common: in the case that prompted this, the popular spelling
  was precisely the one that made the step count nothing.
- Merging moves *assets*, never a step's scope. A step's scope is what the plan is built
  against, so it is the thing to converge on rather than the thing to change.
- Detection only, never automatic. "Prop"/"Props" being one type is a safe guess, but guessing
  silently is the habit the alias layer exists to avoid.

**Where the plurals actually came from — the importer, not a person.** `plaky_import.php`
wrote `asset_type = $p['Group']` verbatim, and Plaky groups are plural ("Environments")
because a group holds many things. So every imported asset got a type one "s" away from the
one the wizard's built-in list offers. The file already contained
`plaky_group_matches_type()`, whose docblock says *"Boards are pluralised, asset types
usually aren't"* — it was applied when READING a group to find a step, and never when
writing. `plaky_canonical_asset_type()` now applies it on write, preferring a step's scope,
then a spelling the project already uses, then a built-in starter. **Without this, merging the
data by hand is undone by the next import**, silently. The preview screen reports every
group→type resolution, because an importer that renames things without saying so is the same
failure as one that duplicates them without saying so.

## 2026-08-13 — The starting estimate is frozen, and never overwritten
**Decision:** `step_estimate_baseline` (migration 013) copies every step's estimate —
`days_per_unit`, `coverage_pct`, `planned_headcount`, the retake %, and the work scale it was
estimated against — at the moment the wizard turns a project `active`. Append-only. A
deliberate **Re-baseline** on the Dates & headcount screen adds a new row with a reason;
nothing ever updates one.
**Why:** those fields are edited in place, so every adjustment destroyed what was believed
before it. "What we guessed at kickoff, next to what it actually took" is the number that makes
the next project's estimate better, and it was being lost on every show as it ran
(`ROADMAP.md`, "Cross-project benchmarking"). Same asymmetry as the daily snapshots: cheap to
capture as it happens, impossible to reconstruct afterwards.
**Consequence:**
- **The step's identity is copied into the row, not just referenced.** `step_id` is nullable
  with `ON DELETE SET NULL`, and `step_name` / `applies_to` / `applies_to_asset_type` are
  stored alongside. Steps get renamed and pipelines tidied after a show wraps; a baseline that
  could only say "step 412" would be worthless by the time anyone wanted it. Same reasoning as
  `progress.assignee_raw`.
- **`work_units` is stored too.** "2 days per asset" means nothing later without knowing it was
  22 assets or 200 — estimates degrade with scale, and comparing across shows without it would
  be comparing noise.
- **Not backfilled from today's `pipeline_steps`.** Those values have already been edited, so
  recording them as an "original" estimate would be a lie. A wrong baseline is worse than a
  missing one: the missing one is visibly missing, the wrong one quietly poisons every average
  built on it. Existing projects get their first row when someone re-baselines, labelled as
  such.
- Re-baselining is never automatic. A baseline that followed every edit would say nothing.
- The screen shows **drift** — which steps now differ from the baseline and by how much. Not an
  error; plans move. But it should be something someone decided rather than something that
  happened.

## 2026-08-13 — The studio calendar is US, not Mexican
**Corrects the "Working days mean Mon–Fri minus a studio calendar" entry of 2026-08-12.**
Migration 010 seeded Mexico's statutory holidays (Ley Federal del Trabajo art. 74). Wrong:
Mana works to **US client calendars**. Migration 014 removes those rows and seeds the American
days almost every company closes for — New Year's Day, Memorial Day, Independence Day, Labor
Day, Thanksgiving and the day after, Christmas. The rest of the federal list (MLK Day,
Presidents' Day, Juneteenth, Columbus Day, Veterans Day) is offered as tick-boxes on the
calendar screen, because studios genuinely differ on those.
**Why the mistake was worth a migration rather than a note:** the wrong calendar is wrong in
*both* directions. It marked days the team works as days off, and left the days they really do
take counted as workable — and both feed straight into every quota and every scheduled date.
**Consequence:**
- The DELETE matches on **date and name together**, so a row somebody has since renamed or
  added themselves is left alone. Only untouched seed rows go.
- Seeded dates are the **observed** day, not the nominal one: 4 July 2026 is a Saturday and the
  day actually taken is Friday the 3rd. A holiday landing at a weekend costs nothing anyway,
  since `working_days_between()` already skips weekends — so a nominal-date row would be a row
  that changes no answer.
- **Any schedule saved before 014 ran was computed against the wrong calendar.** Screens
  recalculate on sight; a saved schedule is a stored snapshot and must be re-run and re-saved.
- The lesson generalises: the calendar is a *studio* fact, not a *country* fact. A studio's
  location says nothing about which holidays it keeps when its clients are elsewhere.

## 2026-08-14 — STFC is the reference implementation, not just a predecessor
**Decision:** David's instruction — *"everything we build from now on should also be informed
by the STFC2 project; that's the MVP and we can add on top of it."* Before designing a screen
here, read the equivalent one in
`D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\`.
**Why:** proven in anger. The timeline was first built here as hand-rolled server-side SVG and
was worse than STFC's in every way that mattered; porting STFC's version — Frappe Gantt, the
labels either side of each bar, the filters, the zoom — took less time and produced something
usable the same day. Its bug-fix comments are paid-for knowledge (`end` is inclusive, never
`toISOString()`), and they came across with the code.
**Consequence:** the port is a starting point, not a copy. This codebase is multi-project, has
assets as well as shots, optional episodes, crew slots and a `statuses` vocabulary — STFC has
none of those, so every ported screen needs those four checked.

## 2026-08-14 — Departments are a column, not a prefix on the step name
**Decision:** `pipeline_steps.department` (migration 015), free text with the four usual phases
suggested. Remembered per step NAME in `step_library` (migration 016), so it is answered once
and every later project starts filled in. A "fill in N blank departments" button maps an
existing project's steps from what other projects already say.
**Why:** the obvious shortcut is to call the step "Pre-prod / Concept". That breaks two things
that already key on the name: `(name, applies_to, applies_to_asset_type)` is the identity used
to compare a step across projects, so two shows spelling the phase differently stop matching
and the benchmark quietly halves its sample; and `step_library` is a *global* vocabulary, which
prefixing fills with one entry per phase per step — the naming drift the alias layer exists to
prevent, self-inflicted.
**Consequence:** saving a step with the field blank never ERASES a remembered department
(`COALESCE(NULLIF(...))`). Forgetting on a blank is how a remembered answer quietly stops being
remembered. Departments travel into and out of pipeline templates, so a new project inherits
them with the steps.

## 2026-08-14 — A pipeline template can be replaced, but only on purpose
**Decision:** `create_template_from_project()` takes `$overwrite`. The screen has a "replace it
if the name exists" tick and offers existing names as a datalist. Without the tick the old
refusal still fires.
**Why:** the original rule said templates are "never overwritten silently" — the load-bearing
word was *silently*, and it was read as a ban. Refining a house pipeline and having to invent
"3D v2", "3D v3" is worse than the accident it prevents.
**Consequence:** the template ROW survives and only its steps are replaced, so the template id
is stable — deleting and recreating would issue a new id and anything referencing a template by
id would point at nothing. `pipeline_template_deps` cascades off the old steps.

## 2026-08-14 — Logged time was already imported; only the reading was missing
**Decision:** no new import work. `plaky_time_to_minutes()` has been writing
`progress.time_spent_minutes` against the right (step, item) since Phase 3, with the unit
declared on the upload screen. `includes/timelog.php` is the reading half, shown on the
dashboard per step and per person.
**Why:** worth recording because it looked like a missing feature and was a missing *screen*.
The data had been arriving correctly and going nowhere — zero references outside the importer.
**Consequence:**
- Comparing estimated DAYS with logged MINUTES needs an hours-per-day figure. It is
  `project_settings.hours_per_day` (default 8), and every figure derived from it says so.
- A step shows what proportion of its tasks have any time against them at all. Without that,
  three of forty tasks reported looks wildly under budget rather than barely reported.
- A task with two people counts its hours for **both** — right for "who worked on this", wrong
  for "how much did each deliver". Still the open question below; surfaced on screen rather
  than silently picked.
- `time_logs` (per-day entries) is still not built. Until it is, "actual" means one running
  total per task, which is enough to compare against an estimate and not enough to bill from.

## 2026-08-14 — Ticket states are the project's OWN status vocabulary, not a second ENUM
**Decision:** `tickets.status_id` references `statuses`. Which of them appear as board
columns is a per-project tick, `statuses.on_ticket_board` (migration 017), set on the
Statuses screen.
**Why:** STFC hardcoded five states — backlog / todo / in_progress / review / done. This
project already owns a per-project vocabulary with `maps_to`, `is_blocked` and `is_excluded`,
and it maps onto those five almost exactly (Waiting to start / Ready to start / In progress /
Pending review / Completed) while also covering two the ENUM cannot: **On Hold**, which
already means *blocked* here rather than idle, and **Retake**. A second vocabulary would mean
a producer holding two mental models for one idea, and every report learning both. Same
principle as "pipeline workflow is data, not code": a vocabulary is rows.
**The problem that creates, and the answer:** eleven statuses is eleven columns, which is not
a board anybody can read. Hence the tick. Six are seeded — the five STFC uses plus On Hold,
which earns its place because it is the only column that reads as an escalation rather than a
queue. N/A and Retake are deliberately *not* columns: as columns they invite a card to be
parked instead of resolved.
**Consequence:**
- A card in a status that isn't a column is **shown anyway**, in the first column, marked
  *off-board*, with a line above the board saying how many. Hiding it would make a real
  ticket invisible, which is the failure this codebase keeps paying for.
- The detail page's status dropdown offers **every** status, not only the columns — a card
  can legitimately sit in "Retake", and hiding it there would make the next save silently
  rewrite it.
- Deleting a status now refuses if cards are on it, and says how many. The foreign key is
  `ON DELETE SET NULL`, so without the check the delete would succeed and leave cards with no
  status at all.
- `ticket_board_columns()` falls back to one status per meaning if nothing is ticked, and
  **says on screen that it did** — rather than rendering a board with nowhere to put a card.
- `seed_project_statuses()` copies the flag, so new projects inherit it. It now checks each
  newer column exists on both tables before copying it: this function has to keep working on
  an install whose migrations are a step behind the code, as `current_user()` does for roles.

## 2026-08-14 — Tickets are their own table and never write to `progress`
**Decision:** board cards live in `tickets`. A card can name a shot, an asset and a pipeline
**step**, but closing one changes no production status.
**Why:** two separate reasons, and the second is the decisive one.
- A ticket is not an item passing through a pipeline step. If a ticket in "Completed" counted
  toward the project's approved work, every dashboard figure would be wrong.
- **Plaky is still the source of truth for progress** (2026-08-11). A ticket that set a step
  back to "Retake" would have that overwritten by the next import, silently — the worst
  possible version of the feature, because the loop would appear to work.
**Consequence:** `tickets.step_id` exists anyway. It costs nothing now and is expensive later
(the `project_id` lesson), it is what surfaces open tickets on the Phase 6 step page, and it
is the hook the retake loop needs on the day the platform owns progress directly. Nothing
about that day requires a schema change — only a decision.

## 2026-08-14 — The ticket reference is per project, and derived rather than stored
**Decision:** `tickets.seq` is a per-project counter; the reference people quote — `RTC-12` —
is built from `projects.code` at display time and never stored.
**Why:** STFC used the row id globally (`TICKET-042`), which on a multi-project install would
number one show's tickets 3, 17, 40 as other shows interleave. And a stored string goes stale:
correcting a project code should re-label its tickets rather than leave the old prefix on
them. Same reasoning as derived lane names (2026-08-12).
**Note the asymmetry with the estimate baseline (2026-08-13), which copies identity IN.** A
baseline is a historical record that must outlive the step it describes; a ticket reference is
a live pointer to a live project. Historical records copy; live pointers derive.
**Consequence:** `seq` is read and written inside the same transaction as the insert, and the
table carries `UNIQUE (project_id, seq)` — so two people filing at once collide loudly instead
of quietly sharing a number somebody has already pasted into a chat.

## 2026-08-14 — A login can say which crew member it is (`users.crew_id`)
**Decision:** nullable `users.crew_id` (migration 017). `mytasks.php` asks once, with an exact
display-name match pre-selected; an admin can correct it on the users screen.
**Why:** "everything assigned to me, across all shows" is the clearest thing multi-project
buys — somebody booked on two productions gets one list instead of two boards to remember. It
was unanswerable: `users` are logins, `crew` are people, and nothing joined them.
**Why not matched by name automatically:** two people can share a display name, and attaching
one person's work to another's login is precisely the quiet mis-mapping the alias layer exists
to prevent. The match is *offered*, not applied — the same distinction as detecting duplicate
asset types and never merging them (2026-08-13).
**Consequence:** anyone can claim any crew row, which is not a privilege escalation here (every
role already reads everything) — it only changes their own filter. This is also the link the
"artists update their own work" phase needs, so it is worth a column rather than a name lookup
at every call site.

## 2026-08-14 — Status and owner changes are written into the ticket's own thread
**Decision:** `ticket_comments.kind` is `user` or `system`. Every status, owner, priority and
due-date change writes a system line into the same thread, from the detail page and from the
board's drag-and-drop alike.
**Why:** STFC titles that section "Activity" but only ever shows what people typed — so "who
moved this to Done, and when" is nowhere, which is the single question anybody asks about a
card that changed behind their back. One column, no second table, no second query, and the
section becomes honestly named.
**Consequence:** a dragged card writes history too, which is the case that would otherwise be
invisible — dragging is exactly the change nobody remembers making.

## 2026-08-14 — The uploader's XHR was eating every flash message (bug found in the port)
**Decision:** forms that upload send `X-Requested-With`, and the server answers those with
`{ok, redirect}` JSON instead of a redirect.
**Why — this is a bug inherited from STFC, not a preference.** The attachment forms submit
through `XMLHttpRequest` to get a progress bar, and the server answers a save with
POST-redirect-GET. **XHR follows that redirect and fetches the page itself** — which reads and
clears the flash — and only then does the browser navigate, to a page with nothing left to
say. Every "3 files attached", every "no shot by that name", every "email not configured" was
being swallowed by the request meant to deliver it. It is STFC lesson 7 turned inside out: not
a failure that forgot to report itself, but a report the transport destroyed.
**Consequence:** the server also gets to choose the destination, so creating a ticket opens its
own page while a plain task returns to the board. Worth back-porting to STFC while it runs.

## 2026-08-14 — No forced-triage rule, because the role that needs it doesn't exist here
**Decision:** STFC forces non-admin submissions into Backlog. Not ported.
**Why:** STFC's roles are admin/member. This platform's are admin/editor/viewer, and a viewer
cannot POST at all — enforced once, inside `require_login()`. So there is nobody here who may
file a ticket but should not be trusted to place it. A rule guarding against a role that does
not exist is a surprise waiting for whoever hits it.

## 2026-08-14 — A ticket may be owned by a slot or a team, and the silence is reported
**Decision:** the assignee list is this project's crew plus teams and unfilled slots. Assigning
to one is allowed; `notify_assignee()` returns a sentence explaining why no mail went out, and
the detail page repeats it as a banner.
**Why:** planning against slots is how a production office works (2026-08-13), so refusing to
let a ticket name one would be wrong. But a slot has no email because nobody is in the job yet,
and "assigned and notified" must never look the same as "assigned, nobody told". A team fans
out to active crew whose role names it, and reports it when that finds nobody.
**Consequence:** every notification path returns `[sent, message]` and every caller shows the
message. There is no path that silently sends nothing.

## 2026-08-14 — Email sends as `hi@davidsaga.com`, replies go to `@mana.tv`
**Decision:** the platform uses **the same mailbox and the same settings as STFC** — sending as
`hi@davidsaga.com`, a cPanel mailbox on this server, with `mail_reply_to` pointing at
`david@mana.tv`. `config.example.php` now carries those values; only `smtp_pass` is blank, and
only `mail_from_name` differs between the two apps (it is what recipients see as the sender).
**Why not send as `@mana.tv` directly, which is what you would expect:** that needs a Google App
Password, which needs 2-Step Verification, which is disabled org-wide by the Workspace admin.
Sending from this server *claiming* to be `@mana.tv` fails SPF, carries no DKIM signature for
that domain, and gets spam-foldered or rejected under DMARC. Sending as a mailbox that genuinely
lives here means SPF and DKIM align, so the mail arrives; Reply-To then puts replies where they
belong. **If 2-Step Verification is ever enabled at Mana, this indirection can be removed.**
**Consequence:** setting the platform up is copying one password across from STFC's `config.php`,
not creating anything. Both apps authenticate as the same mailbox, which is fine — SMTP has no
per-application state to collide over.

## 2026-08-14 — The email diagnostic is ported too, and it is admin-only
**Decision:** `smtp_test.php`, ported from STFC's `smtp-diagnose.php`, with a real test send
added. It probes seven host/port/encryption combinations and prints which authenticates, then
sends a message through `SmtpMailer` — **the same class the notifications use**, because a test
that sends by another route proves nothing about whether tickets work.
**Why it earns its place:** "email doesn't work" has about six causes that look identical from
the outside — blocked outbound SMTP, wrong port/encryption pairing, wrong password, a
`disable_functions` entry, an unaligned From address. The page separates them by reporting the
*stage* each attempt reached, which turns a day of guessing into a minute of reading.
**Consequence:** STFC used `require_login()` for it; here it is `require_admin()`, because this
codebase has viewer and editor roles that STFC does not and neither needs to see the sending
address or be able to send mail. It also states plainly when `stream_socket_client` is disabled
by the host — otherwise every row fails at "connect" with no explanation, which reads like
wrong settings rather than an unwinnable fight.

## 2026-08-14 — The tracking grid: a status COLOUR block, not a checkbox
**Decision:** the Phase 8 tracking grid (ported from STFC's `preprod.php`) draws each cell as a
small block filled with the status's own colour, not as a checkbox. Clicking one opens a
dropdown of the project's statuses. A colour legend sits at the top of the screen.
**Why the checkbox worked on STFC and cannot port unchanged:** David's own account of why it was
good — *"it was clear and easy to read, is ready or not. If the row was all green then the asset
was ready for production."* That reading is worth preserving, and it survives: green still means
the same thing. But `preprod_progress.is_done` is literally a boolean there, whereas here a cell
is a `status_id` pointing at an eleven-value vocabulary. A checkbox has **no inverse** in that
model: ticking it could write "Completed", but unticking it has no defined destination —
"Waiting to start"? "In progress"? "Retake"? — and whichever was chosen would silently destroy
what the cell actually said. It would also flatten the two distinctions the dashboard already
reports separately: On Hold is *blocked*, not merely unfinished, and Retake is neither.
**Consequence:**
- **Green keeps its exact STFC meaning.** There, a box was ticked only when Plaky said
  *Completed* or *Final* — i.e. **ready for the next department to pick up**. Here that is
  precisely `statuses.maps_to = 'approved'`, so the definition ports without being restated.
- **A legend is mandatory, not decoration.** A checkbox needs no key; eleven colours do. It goes
  at the top of the screen, built from the project's own `statuses` rows so it can never drift
  from what the cells show.
- The grid gains what a checkbox could not show: blocked and retake work is visible *as itself*
  at a glance, rather than being indistinguishable from "not started".

## 2026-08-14 — "All green" is a property worth surfacing outside the grid
**Decision:** an item every one of whose applicable steps is `approved` is **ready for
production**, and reads green wherever it is listed — starting with the Shots screen, which is
renamed the **Production** tab.
**Why:** this is what the STFC grid was really for. The row of ticks was a means; "this asset is
finished and the next department can start on it" was the end, and on STFC you had to read the
row to work it out. Making it a first-class state means the answer is available on any screen
that lists items, not only on the grid.
**Consequence:** "applicable" has to be computed, not assumed — a step counts for an item only
when `applies_to` (and `applies_to_asset_type`) covers it and the item is not marked with an
`is_excluded` status. Otherwise a character with no rig would never go green, and the one honest
signal on the screen would be wrong in a way nobody could see.

## 2026-08-14 — A pie per asset type, drawn only for types that exist
**Decision:** the grid screen carries one pie chart per asset type, showing the spread of
statuses within that type. A type with no assets gets **no chart at all** — no empty placeholder.
Clicking a segment opens a list of the items in that type reporting that status.
**Why:** David's framing — *"so we can easily visualize how many assets are missing"*. The
per-step counters answer "how much of Rigging is done"; the pies answer the question a
supervisor actually asks, which is "what state are the characters in". Rendering only the types
that exist follows the same rule as everything else built from data here: a "Vehicles: 0" chart
on a show with no vehicles is noise implying a gap that does not exist.
**Consequence:** the click-through is what stops a chart being decorative — a segment reading
"7 On Hold" is useless until it says *which seven*. Same principle as the unmatched-names report:
a number that cannot be opened is a number nobody can act on.

## 2026-08-15 — Rates and contracts DO live here after all, behind one door
> **Superseded the same day — see "Pay data gets its own database, read through one door"
> below.** Migration 018 was applied before the change of mind, and migration 019 removes what
> it added. The reasoning here is still worth reading: it is what the middle path was chosen
> *instead of*. Don't act on this entry alone.

**Reverses "Money lives in a separate application and a separate database" (2026-08-12).**
That entry said in terms: *"`crew` does not gain a `rate` column."* Migration 018 adds
`crew.day_rate`, `rate_unit`, `rate_currency` and `contract_url`.
**Why the reversal:** David's call, asked directly and with the consequence stated. The finance
application does not exist and has no date. One screen showing the whole picture of a person —
what they carry, how they are doing, what they cost — is worth more today than a guarantee about
an app nobody has built. It is his studio's data and his risk to take.
**What was actually given up.** The old decision's strength was never the tidiness; it was that a
separate MySQL user with no rights to those tables made a leak *impossible* rather than merely
unlikely — a guarantee an application cannot give about itself, because a role check is one `if`
away from failing. That is gone and cannot be got back by being careful.
**What replaces it, since something had to:**
- **`includes/money.php` is the only door.** Every read and write of those four columns goes
  through it, and it returns an empty array for anyone who is not an admin — not a partial row,
  not nulls. A caller that forgets to check renders nothing, which is the right failure.
  Verified by grep: no other file in the codebase names those columns.
- Same shape as the read-only enforcement in `require_login()`, and for the same reason: a check
  per screen fails the first time somebody forgets one, and fails silently.
- The **contract stays in Google Drive** and only its link is stored. Drive enforces its own
  permissions, so that half of the original guarantee survives intact — a link is useless to
  somebody Drive will not let in.
- `crew.department` is in the same migration and is deliberately **not** behind the guard. It is
  not financial, and routing it through a money door would only make it look like it was.
**Consequence:** if the finance app is ever built, these four columns move there and migration
018's header is what explains why they were here. `DECIMAL`, never `FLOAT` — a rate is money and
gets summed against an invoice, the same reasoning as storing time in whole minutes.

## 2026-08-15 (later) — Pay data gets its own database, read through one door
**Decision, settling the day's third position and the one that stands.** Rates and contract
links live in a **separate MySQL database with a separate user**. The production user has no
rights to it. The platform holds that second credential and opens it in exactly one place —
`includes/money.php` — behind `is_admin()`. `finance_install.sql` creates the schema;
migration 019 removes the columns 018 had added.
**Why not either extreme:**
- *Columns on `crew` with an app-level check* (what 018 did) leaves pay data readable by every
  query the app runs. The guard holds only as long as nobody ever writes `SELECT day_rate`
  somewhere else, and that is a promise about future people rather than a property of the system.
- *A wholly separate application* (2026-08-12) is the strongest answer and remains the
  destination, but it does not exist and has no date — and David wants the person page to carry
  the full context in one screen, which is a real requirement rather than a convenience.
**What the middle path actually buys, stated precisely so nobody over-trusts it:** a bug in any
ordinary query — a bad `WHERE`, an injection, a join written at midnight — **cannot** reach a
rate, because the connection it runs on is not permitted to see one. That is a property of the
database server and holds for screens written years from now by people who never read this. It
is **not** the 2026-08-12 guarantee: this app does hold a finance credential in the same
`config.php`, so anyone who can read that file, or run code as this app, can reach the rates.
What is defended against is the far likelier thing — an ordinary mistake in ordinary code.
**Consequence:**
- The two databases are joined on `crew.id`, with **no foreign key** — different databases,
  different users, so the constraint cannot exist and claiming otherwise would be a comment that
  lies. What keeps it safe is the 2026-08-12 rule that ids stay stable and are never reused.
- `crew_terms.crew_name` is stored alongside the id, so a row opened directly in the finance
  database is readable and an orphan is not an anonymous number. Same reasoning as
  `progress.assignee_raw` and the estimate baseline copying step names.
- **`crew_terms_log` is append-only.** Who changed a rate and when is the one question this kind
  of data reliably attracts, and it cannot be reconstructed afterwards — the same asymmetry as
  the daily snapshots.
- The person page tells apart three states that would otherwise all render blank: not an admin,
  finance not configured, and nobody has a rate on file. A connection failure is reported, never
  swallowed (lesson 11).
- **`crew.department` stayed** in migration 018 and is deliberately outside the money door. It
  is not financial, and routing it through one would only make it look like it was.

## 2026-08-15 — Per-person pace is approximate, and says so
**Decision:** the person and team screens report assignments, approvals and logged time as
facts, and anything about PACE as an approximation, labelled on screen.
**Why:** `daily_snapshots` records history **per step, not per person**, so "how much did Ana
approve last week" has no history to read. The only per-person time signal is
`progress.updated_at`, which is when a row last changed *for any reason* — a status edit, a
re-import, a reassignment. Counting approved rows touched inside a window over-reports after a
bulk import and under-reports when work goes unrecorded for a week.
**Why publish it anyway:** the direction of travel against the required quota is the question a
producer actually asks, and refusing to answer until a perfect measure exists would leave the
most useful screen unbuilt. What is not acceptable is presenting it as measurement — a number in
a table reads as a fact unless the page says otherwise, so the page says otherwise.
**Consequence:** a per-person history table is what would make it real. Same shape as the gap
`time_logs` fills for effort, and worth building at the same time — one writer, two questions
answered.

## 2026-08-18 — The money side is built, in the studio's own spreadsheet shape
**Decision:** the four money steps of the production cycle — budget, project expense, invoices,
budget reports — are `finance.php` (hub) and `finance_project.php` (detail), **admin only**,
reading production numbers from this database and money from the separate finance one.
`RTC_ExpenseProjection_v01.xlsx` is the specification; it was read and matched rather than
approximated.
**What the sheet dictated, and why each choice is not arbitrary:**
- **A row is a PERSON, not a step.** "Layout (Environment)" with two artists is two rows,
  because they are two people who each get paid, and a step with three animators costs three
  times what one does. The first version had one row per step with people nested — readable, but
  it does not add up the way a budget meeting reads.
- **Grouped by department with subtotals**, in the sheet's order: Above the line, Pre-production,
  Production, Post-production. From `pipeline_steps.department`, which migration 015 added for
  exactly this.
- **Months = days ÷ 22.** Derived from the sheet's own numbers (4 days → 0.1818), not invented.
- Role reads "Concept (Character)" — step name plus scope, matching its column B.

## 2026-08-18 — The expense projection is COMPUTED, never stored
**Decision:** nothing about the projection is written down. Every page load recomputes it from
the current pipeline: person-days per step from the same `step_quota()` the dashboard and CSV
export use, times the rate of whoever holds each lane.
**Why:** storing it creates a second number that goes stale the moment somebody changes a
headcount, and the whole reason production and finance are joined on stable ids is so the money
side reads the CURRENT truth rather than a copy of an old one. Only what cannot be derived is
stored — the agreed budget, and what has actually been invoiced.
**The consequence, which is deliberate and visible:** a lane held by an unfilled slot has no rate
to multiply, so it is counted as **unpriced**, not free. The screen says "14.5 person-days have no
rate behind them" instead of quietly totalling the part it could price. A cost that looks low
because half of it is missing is worse than no cost at all — and it puts the hiring gap in money
terms, which is where it will actually be felt.
**Related:** mixed currencies are flagged, never converted. Inventing an exchange rate would be
worse than saying so.

## 2026-08-18 — Actual cost comes from INVOICES, not days × rate
**Decision, David's correction and it was right.** The first version computed actual cost as
logged days times the rate. That is a second estimate wearing an actual's clothes; only an
invoice records what was really billed. `Actual days` still comes from `time_logs`; `Actual cost`
comes from invoices attached to that position.
**What it required:** an invoice has to name a **position**, not just a person. Somebody holding
lanes on both Layout and Color is two positions at possibly two rates, and "who" alone cannot say
which a payment was for. Hence `invoices.step_id` (finance_install_03.sql) — crew_id + step_id
together are the lane, which is exactly a projection row.
**The case that is handled rather than forced:** an invoice with no position — a kill fee, a
one-off, a bulk settlement, or work from an earlier stage of the show (David's sheet has two:
Iana on Animatic, Go Gap on CH designs). Those are real. They count in the project's invoiced
total, are marked **unattached**, and are excluded from projection rows — because spreading them
across rows would put a figure into a department subtotal nobody agreed to. The page reconciles
the two totals out loud rather than letting the gap look like a bug.

## 2026-08-18 — Per-person projected cost is a SLICE of the same projection
**Decision:** the person page shows what that individual is projected to cost on the project —
their positions, days, months, total — computed by filtering `expense_projection()` rather than
by multiplying rate × days again.
**Why:** two implementations of the same multiplication disagree within a week. This is the same
rule as `step_quota()` being the single source for person-days: the Finance screen and the person
page cannot show different numbers because there is only one calculation.
**Consequence:** type a rate, save, and the number moves — on both screens at once. Every
unpriced row on the projection links straight to the page where its rate is set.

## 2026-08-18 — Three parse-level bugs, and the checker now catches all of them
**Context:** there is no PHP binary on the workstation, so `php -l` is unavailable and
`tools/check_php_blocks.py` is the only automated gate. It has now been extended twice in one
week, each time after a bug it could not see:
1. **Unbalanced `if:/endif;`** — the original purpose.
2. **Duplicate function names** — a redeclaration is fatal, but only on a page that loads both
   files. `money.php` defined `fmt_rate()` for a PAY rate; `quota.php` already had one for a WORK
   rate. Cost `person.php` its first load.
3. **Unterminated strings** — a string opened with `"` and closed with `'` is a PARSE error, so
   the file never compiles and *every* page requiring it 500s. Took the whole Finance section
   down; it presented as "there are no projects", because output stopped after the table header.
**The narrower lesson, which is the useful one:** that bug was introduced by a scripted string
replacement that silently did not match. **Assert every replacement.** The batch that broke it had
asserts on every edit but one, and that was the one that failed.
**Also fixed:** the Finance hub now wraps each project's projection in its own try/catch. One
project that cannot be priced shows an error in its own cell; the rest of the table still draws.
A single row taking down a whole page silently is the exact failure this codebase keeps writing
rules against.

## 2026-08-17 — Hiring: the conversation is planning data, the number is not
**Decision, David's call asked directly.** The hiring record splits across the boundary. Who was
approached, at what stage, from what source, and their contact details live in the **production**
database (`hires`, migration 024) where any editor sees them. What they **quoted** lives in the
**finance** database (`hire_quotes`, `finance_install_04.sql`) behind `includes/money.php` and
`is_admin()`, like every other rate.
**Why not put it all behind the money door,** which would have been the simpler boundary: a
producer looking at the Crew screen has to be able to see that a slot already has somebody lined
up, or they will chase a job that is already three conversations deep. That fact is not financial.
**Why not put it all in production,** which would have been simpler still: a quoted day rate is
pay data by any reading, and the whole 2026-08-15 arrangement exists so that an ordinary query on
an ordinary screen cannot reach one.
**Consequence:** this is exactly the `crew` / `crew_terms` split applied one stage earlier — before
somebody is hired rather than after. The join key is `hires.id`, with no foreign key (different
databases), so **the id-stability rule applies here too**: the application never deletes a `hires`
row. A candidate who said no is marked `declined`, not removed — which is also the record worth
having three weeks later when the other one falls through.

## 2026-08-17 — Hiring RENAMES the slot; it never creates a person
**Decision:** `hire_candidate()` hands off to `promote_placeholder()` rather than doing anything
itself. With a slot, hiring renames it; the crew row's id never changes.
**Why it matters enough to write down:** this is the moment the 2026-08-13 slot model pays off, and
it would be very easy to build the obvious version instead — create a crew row for the new hire,
then reassign the slot's work to them. That version has to move every scheduled task, every
progress record and every history row, and it silently loses anything it forgets. Renaming moves
nothing because nothing needs moving.
**Consequence:**
- A candidate is deliberately **not** a `crew` row while the conversation is happening. Giving them
  one early would put them in every assignee dropdown in the app and leave the ones who declined in
  the studio's permanent people list for ever.
- Hiring into a slot that already holds a real person **refuses**, and says who is in it. Silently
  replacing them would move their planned work onto somebody else with nobody asking for it.
- A candidate with no slot gets a plain crew row and is added to the project, with the screen saying
  they are not in a slot yet. An exact name match reuses the existing person rather than making a
  second copy — the same rule the alias layer and `link_login_to_crew()` follow.
- The finance write happens **outside** the production transaction, because one transaction cannot
  span two connections. The hire commits first; if carrying the rate across fails, that is reported
  and the hire stands. The reverse — rolling back a hire because a rate could not be copied — would
  be much worse.

## 2026-08-17 — An agreed rate is carried onto the person automatically, but never over an existing one
**Decision:** `seed_terms_from_quote()` copies the quoted rate into `crew_terms` at the moment
somebody is hired. If they already have a rate on file it does **nothing** and says so.
**Why:** this is the loop the whole phase exists to close. The expense projection reports unfilled
slots as *unpriced days* rather than as free (2026-08-18); agreeing a rate during hiring is
precisely the fact that prices them. Making somebody retype it on the person page afterwards would
guarantee the two numbers eventually disagree, which is the same reasoning that keeps the projection
computed rather than stored.
**Why it never overwrites:** somebody who has worked three shows has terms that were negotiated. A
quote from one job silently replacing them would change what the studio pays a person, from a screen
that is about something else.

## 2026-08-17 — Contract state is an ENUM, and it gets its own writer
**Decision:** `crew_terms` gains `contract_status` (none / draft / sent / signed / declined) with
sent and signed dates. `set_contract_state()` is a **separate function** from `save_crew_terms()`.
**Why an ENUM** when this codebase turns almost every vocabulary into rows: unlike a status or a
department there is nothing studio-specific about it, and "who hasn't signed" has to be a `WHERE`
clause rather than a judgement about what somebody typed. `invoices.status` and `tickets.priority`
are already ENUMs on the same grounds.
**Why its own writer, which is the part worth remembering:** `save_crew_terms()` writes the whole
row. Hand it a form that carries only contract fields and it stores the missing rate as NULL —
so chasing a signature would silently **unprice** somebody, and the expense projection would start
reporting their days as unpriced with nothing on screen to explain why. A partial write needs its
own function. Reusing the full one and trusting every caller to send every field is the shape of
bug this codebase keeps writing rules against.
**Consequence:** `declined` is deliberately not the same as `none` — one is a hole in the plan
somebody has to fill, the other is the ordinary state of most of a project. Dates rather than
booleans, for the reason `invoices.paid_date` is a date: a boolean can never answer "how long has
that been sitting with them", which is the question that actually chases a contract.

## 2026-08-17 — Engagement dates are recorded, and the scheduler does NOT read them yet
**Decision:** `crew_projects` gains `start_date` and `end_date` (David's request: start and end
dates on the person card). The scheduler ignores them. `engagement_conflicts()` compares them
against `schedule` and the person page reports the difference.
**Why on `crew_projects` and not on `crew`:** an engagement is per SHOW. The same compositor is on
Return to Club from September and on the next thing from January, and a single pair of dates on the
person would have to be wrong about one of them.
**Why the scheduler is left alone, deliberately:** making a start date move a bar changes every date
on every project the moment somebody types one. That is a phase of its own and it belongs with
`crew_absences`, which has been designed in `ROADMAP.md` since 2026-08-12 and pairs with exactly
this work.
**What is NOT acceptable about deferring it, and what was done instead:** a column that looks like
it is being obeyed and is not is the precise failure lesson 11 is about — a feature that quietly
does nothing is indistinguishable from one that was never built. So the gap is **reported**: the
person page says "the plan has 6 tasks for them before they start", and the form says in as many
words that the scheduler does not use these dates yet. Recording the fact is worth having on its
own; pretending it constrains anything would not be.

## 2026-08-17 — The first screen since August 14 with no STFC reference
**Worth recording because it suspends a standing rule.** Since 2026-08-14 every screen has been
designed by reading the equivalent one in the STFC dashboard first, and that rule has earned its
place — the timeline port took less time than the version invented from scratch and came out
better. **There is no equivalent here.** STFC tracks a show that was already crewed; it has no
hiring, no candidates and no contracts, confirmed by search rather than assumed.
**Consequence:** this phase has no proven-in-anger version behind it, which is worth knowing when
something about it turns out to be wrong. The parts that *are* borrowed are borrowed from this
codebase rather than that one: dry-run-then-save, POST-redirect-GET with flash messages, a
vocabulary that reports what it could not do, and one writer per kind of write.

## 2026-08-17 (later) — The freelancer fields go on `hires`, and what that costs
**Decision, David's call with the alternative on the table.** `Mana_Freelancer_DataBase.xlsx` —
377 people and 12 studios — is to merge into the platform eventually, so a candidate row now
carries the sheet's columns: location, position, seniority, project type, worked-with-us, rating
(migration 025).
**The alternative that was offered and declined:** a studio-wide `freelancers` table with `hires`
pointing into it — one row per human, which is the shape the sheet already has and the shape `crew`
was given in 2026-08-11 for the identical reason ("crew is global, membership is per project").
**What was chosen costs this, stated plainly rather than discovered later:** `hires` is one row per
candidate PER SLOT. Approaching the same animator for three slots stores their country, seniority
and portfolio three times, and fixing a typo fixes one of the three. When the sheet is merged in,
those 377 people arrive as candidate rows rather than as a pool that exists whether or not a show
currently needs anybody.
**Why it is recorded and not argued with:** it is his studio's data and his risk, the same standing
as the 2026-08-15 money reversal. **And it is not a trap:** the escape is a `freelancers` table plus
a pass that collapses matching rows into it. That is more work than doing it now and it is not a
migration of live production data in the dangerous sense, because `hires` would keep its own columns
as the record of what was true when somebody was approached.

## 2026-08-17 — "Hired" is in the stage list, and choosing it does the real thing
**Decision, David's correction.** The first version kept `hired` out of the stage dropdown and
behind a dedicated button, on the grounds that hiring is not a label change. He pointed out the case
that breaks: *"sometimes I might have already hired them"* — somebody is entered into the platform
after they were taken on, and being unable to say so is worse than the tidiness was worth.
**What it does NOT mean:** `hired` is not a label. `set_hire_stage()` delegates to
`hire_candidate()`, so picking it from the list runs the real hire — the slot gets their name and
everything planned for it becomes theirs. A record reading "hired" beside a slot still showing a
placeholder would be the worst of both, and nothing on either screen would explain the mismatch.
**Consequence:** the bulk grid creates the row at a non-final stage FIRST and hires as a second
step, so a hire that fails — the slot is already taken — leaves an honest record and a reported
reason rather than losing the typed row or claiming somebody is on the show who is not.

**Two corrections the same day, both from the retroactive case biting again:**
- **Filled slots are LISTED in the slot dropdown, marked `(filled)`.** They were filtered out on
  the grounds that nobody puts a candidate forward for a job somebody already holds. Wrong in both
  directions: a person hired before they were ever entered here has to be recordable against the
  slot they already occupy — which is precisely what "Hired" in the stage list is for — and a
  producer looking for a replacement needs that slot too. Worse, the slot was simply absent with
  nothing on screen to say why, which reads as a bug and was reported as one.
- **Hiring somebody into a slot THEY ALREADY HOLD is not a clash.** `hire_candidate()` compared
  `is_placeholder` and refused anything else. It now checks the name: the same person means the
  record just points at them, nothing is renamed and nothing moves. A *different* person is still
  refused, and now says to use the Crew screen — the screen that moves planned work along with the
  change, and therefore the right place for that decision.

## 2026-08-17 — A spreadsheet paste is read in the SHEET's column order, discarded columns included
**Decision:** `HIRE_SHEET_COLUMNS` lists all twelve columns of the freelancer sheet, including
`_password` (position 8) and `_rate` (position 11), which are parsed and then thrown away.
**Why the two throwaways have to be in the list, which is the whole point:** somebody pasting from
Excel selects whole rows. Leaving Password out would not skip it — it would shift Worked-with-us,
Notes and Rating one column to the LEFT and store a password as somebody's availability, silently.
Naming a column you intend to discard is what keeps the rest aligned.
**Two related details:**
- **Tabs are split singly, not with `+`.** The wizard's paste helpers use `/[,;\t]+/`, which is
  right for three fields and wrong for twelve: Excel emits an empty cell as two adjacent tabs, and
  the `+` swallows the pair and shifts everything after it.
- **Neither discarded column vanishes quietly.** A paste containing passwords says so and says why
  they were not stored; a paste containing rates names the people and points at where a rate is
  actually recorded. A silent discard is the failure this codebase keeps writing rules against.
**On the password column specifically:** twelve rows of the sheet carry a code for viewing a private
reel. No `password` column was created. A field of that name in a shared database gets reused for a
real credential by somebody who never read the migration, and it would sit there in plain text.

## 2026-08-17 — The block checker was itself wrong about lesson 9
**Found by the checker reporting a healthy file as broken**, which is the failure mode that matters
most for the only automated gate this project has.
**What was wrong:** `php_code_only()` treated a `//` comment as running to the end of the line. PHP
does not — it acts on `?>` even inside a comment, ends PHP mode there, and reads the rest as HTML.
That is lesson 9, and the checker written to catch lesson-9-shaped bugs did not model it.
**How it surfaced:** `<?php // ---- paste box ---- ?>` followed by HTML. The checker stayed in PHP
mode, read the markup as code, met an apostrophe in ordinary prose, opened a string on it and
swallowed eighty lines — including the `endif` in the middle of them. Every earlier use of that
comment form in this codebase happens to be followed by more PHP, which is why it had never shown.
**Why it was fixed rather than worked around** by changing the comment style: a false alarm on the
only gate is worse than no gate, because it teaches people to ignore the one thing that catches
real parse errors. The same blind spot could equally have hidden a genuine mismatch.
**Consequence:** the checker now leaves PHP mode at `?>` inside a line comment, and all 74 files
still pass — so the fix removed a false positive without introducing a false negative.

## 2026-08-17 — The entry grid looks people up, because the chosen shape cannot
**Decision, and it is the mitigation the "all fields on `hires`" call needs to be safe.** The
name field in the entry grid is a lookup over `known_candidates()` — every person ever recorded
on any project, deduplicated to one row each, most recent details winning. Picking a name fills
the rest of the row.
**Why it is not optional:** David hit it within minutes of using the grid — *"I need to make sure
I can select from the database, otherwise I might repeat the same candidate on another entry."*
`hires` is one row per candidate PER SLOT, so nothing in the table knows that three rows are one
human. Without a deduplicated view, the same person gets typed in twice under two spellings and
the two records never meet again. The `freelancers` table that was declined would have made this
free; this function is what buys it back.
**Consequence, and the three rules it follows:**
- **Autofill only ever writes into EMPTY fields.** Overwriting something already typed would leave
  a row that looks like the typist's and holds last month's details, with nothing saying a value
  had been replaced.
- **Matching is exact on the lowercased name, never fuzzy.** Same rule as the alias layer and the
  asset-type merge: near-matches are detected and offered, never resolved silently (2026-08-13).
- **A true duplicate is refused, not merged.** Same project, same slot, same name or same email →
  the row is skipped and the existing record is named, with its stage. In a bulk paste those are
  counted as duplicates rather than errors, because pasting a block that overlaps one already
  entered is an ordinary thing to do and forty red lines would bury the rows that did land.
**Still true, and worth keeping in view:** this makes the duplication survivable, not absent. The
same person on two projects is still two rows carrying two copies of their details, and correcting
one corrects one.

## 2026-08-17 — A screen that sends you somewhere to fix something brings you back
**Decision:** the expense projection's "set a rate →" links carry `from=finance`. The person page
then shows "← Back to the expense projection", its Save button reads *Save terms and go back*, and
saving returns straight to the projection.
**Why:** David, on the real screen — the projection had fourteen unpriced rows, each one a link to
a different person's page, and every one of them was a dead end. Filling them in meant walking
Finance → the project → scroll to the row, fourteen times.
**The general rule, which is the part worth keeping:** this codebase deliberately sends people off
to fix things — unpriced rows to a person page, unmatched names to the alias screen, surplus slots
to the schedule. **A link that names a problem should also lead back to where the problem was
visible**, or the screen that diagnosed it becomes the screen you have to keep re-finding. Landing
back on the projection also shows the figure appearing in the row it came from, which is the
confirmation that actually matters — more than a flash message saying "saved".

## 2026-08-17 — Bulk entry is now a pattern, and an empty row is not an incomplete one
**Decision:** the invoice form on `finance_project.php` became a five-row grid, matching the
hiring one. `save_invoices_bulk()` mirrors `save_hires_bulk()`: one bad row never stops the rest,
and every outcome is reported rather than inferred.
**Why:** David, on the real screen. A month's invoices arrive as a batch, and one form submit each
was six page loads to do one afternoon's bookkeeping. The same argument that produced the hiring
grid, on the screen next door.
**The rule worth keeping, which is not obvious:** a blank row and a half-filled row are different
facts and are treated differently. A row with nothing in it is one of the five that was not needed
— skipped in silence, correctly. A row with a NAME and no AMOUNT is somebody who started typing and
was interrupted, and skipping that quietly would lose work they believed they had saved. It is
reported against its row number. Deciding what counts as typed matters too: currency, status and
the issued date carry defaults on every row, so they are deliberately excluded from that test.
**One thing the grid fixed rather than copied:** the old single form filled the name from the
position picker with `document.getElementById`, which addresses exactly one field on the page —
across five rows every pick would have written into the first row's name box. And the position is
now re-resolved SERVER-side from the picker's crewId:stepId value rather than trusted from three
hidden inputs, so a name and an id can never disagree about which lane an invoice belongs to.

## 2026-08-17 — Margin was budget minus LABOUR, and was being read as headroom
**The bug behind the feature, and it is the part worth remembering.** David asked *"how can I
currently add any other expenses that might not be tied to a specific pipeline step task?"* The
honest answer was: you cannot. The expense projection is computed from pipeline steps, lanes and
rates, so nothing without a lane could enter it — and **Margin was `budget − projected labour`**
while being displayed under a word that means the money left over.

On Return to Club that read **1,720.65 against a 35,000 budget** — 4.9% — with software, render
time, licences, stock and travel all necessarily absent. Two or three ordinary non-labour lines
would have erased it and nothing on the screen would have changed colour. That is the same class as
the studio-calendar bug: not a wrong number, a number answering a narrower question than its label
claims.

**Decision:** `project_expenses` in the finance database (`finance_install_05.sql`) — one row per
non-labour cost, with a free-text **category** as the grouping David asked for.
**Consequence, and the four things that make it hold:**
- **Folded into `expense_projection()`, not added on by each screen.** That function is the single
  source the hub, the project page and the person page all read. A total assembled separately in
  three places is three totals that disagree within a week — the same rule that keeps the projection
  computed rather than stored.
- **`total` still means LABOUR; `grand_total` is what a margin is drawn against.** The projection
  table's footer sums the rows above it, and a footer larger than its own rows would be exactly the
  quietly-wrong figure this file exists to prevent. Both the hub and the project page moved to
  `grand_total` in the same change, or they would have contradicted each other.
- **The line against `invoices` is stated on screen:** an invoice is a PERSON billing the show; an
  expense is everything that is not somebody's time. Without saying that, the same render bill gets
  entered as both, in different totals, and neither looks wrong.
- **All three statuses — planned, committed, paid — count toward the projection**, because a
  projection is what the show will cost and not what it has cost so far. `paid_date` is a date and
  not a boolean, for the reason `invoices.paid_date` is: a boolean can never answer "when did that
  go out".

**The category vocabulary is global, not per project** — no `project_id` on the suggestion list,
same as `step_library`. Which categories a studio actually spends against is worth comparing across
shows, and a list rebuilt per project would make every new show start from nothing.

## 2026-08-17 — "Save as template" on the Workflow screen, and the unchecked-box trap
**Decision:** the Workflow step offers "Save as template" as well as the Pipeline step (David's
request). Both share one handler and one form — `template_save_from_post()` and
`template_save_form()`, in `includes/pipeline.php` — because each wizard step file is loaded on its
own and step 3 never sees step 2's code.
**Why shared rather than copied:** two copies would drift, and the field they would drift on is
`template_overwrite`. The 2026-08-14 decision is that replacing a template is allowed but must be
deliberate; a copy that quietly lost that tick would start overwriting the house pipeline for
people who believed they were creating a new one.

**The trap this turned up, which is the part worth keeping.** The first version put the template
form on the Workflow screen as its own `<form>`, mirroring the Pipeline screen. On the Pipeline
screen that is safe — the step edits are in a different form and `wizard_pipeline_apply_edits()`
returns 0 when its fields are absent, so saving a template simply does not touch them.

On the Workflow screen the same shape would have been **destructive**. Dependencies are checkboxes,
and **unchecked boxes are not submitted at all** — so a separate form posting `save_template`
carries no `deps` key whatsoever, which is byte-for-byte identical to "the user unticked
everything". `save_dependencies()` would have **deleted the entire graph**, silently, while
reporting that a template had been saved. On Return to Club that is every dependency on a
seventeen-step pipeline.

**The fix is a pattern this codebase already had:** the controls sit INSIDE the dependency form and
the action rides on the submit button's `name`/`value`. That is precisely what the 2026-08-11
entry describes for wizard row buttons — *"a single form saves pending edits and performs the
action together — HTML forms cannot nest, and losing typed edits to a row button would be its own
silent failure."* The same reasoning, one screen along.
**Consequence:** `template_save_form()` takes `$inline`. Inline drops `required` from the name
field, because a required field inside the main form would block "Save and continue" for anyone not
saving a template; `create_template_from_project()` refuses an empty name server-side and
`wizard.php` turns that into a flash message, so nothing is lost by moving the check.
**The generalisable version:** *an absent checkbox array and a deliberately emptied one look
identical in `$_POST`.* Any handler that writes a full set from checkboxes must be reached only by
the form those checkboxes live in.

## 2026-08-17 — Cost by phase, and phases become editable where they are read
**Decision:** the Finance project screen gains a **Cost by phase** strip — every labour department
plus the non-labour total — and a panel that moves any step to a different phase without going
back into the setup wizard. Both David's request.

**No schema for either.** `pipeline_steps.department` has existed since migration 015 and
`expense_projection()` has returned `by_department` since the money side was built; this is the
reading half and an editing surface, which is the same shape as "logged time was already imported;
only the reading was missing" (2026-08-14).

**The rule the breakdown is built to:** it must ADD UP to the Projected cost above it — every
department, plus other expenses, equals the grand total, and the strip says so with a Total cell.
A breakdown whose parts do not reconcile with the headline is worse than no breakdown, because
somebody will trust one of the two and nothing on screen says which. `Unassigned` is therefore
shown rather than hidden, with a line saying what it means: steps with no phase set, which is the
one case where the grouping lies by omission.

**Why the editor belongs on the Finance screen and not only in the wizard:** the question "should
Comp be Post-production?" arises while reading a budget, which is where the phase subtotals are.
Walking back into a seven-step setup wizard to change one dropdown is enough friction that the
grouping simply stays wrong — and it groups the crew list and the timeline as well as the money.
**Consequence:**
- `save_step_departments()` writes on the **ordinary production connection**. `department` is a
  production fact — how a crew list, a call sheet and a Gantt are all organised — and the
  2026-08-15 entry is explicit that routing it through the money door "would only make it look
  like it was". The Finance screen happens to be where it is edited; that does not make it money.
- It calls `step_library_remember()` exactly as the wizard does, so the answer is given once and
  every later project starts with it filled in. Two writers that remembered differently would make
  the global library depend on which screen somebody happened to use.
- Free text with the four usual phases suggested, per `department_options()` — a studio that wants
  a fifth phase types it, and it joins the list. Same rule as asset types and hiring positions.

## 2026-08-17 — The projection exports as a real .xlsx, written without a library
**Decision:** `export_projection.php` builds an actual Excel file in the fifteen-column shape of
`RTC_ExpenseProjection_v01.xlsx` — department bands, subtotals, grand total, summary block.
`includes/xlsx.php` writes it: an .xlsx is a ZIP of XML parts and PHP ships both, so no dependency
was added to a codebase that still has none.
**Why not CSV**, which `export.php` already emits: David asked for a file *like the attached one*,
and the attached one has bold headers, department bands, subtotals and money formatting. CSV has
none of those.
**What the writer deliberately refuses to grow into:** no formulas, no charts, no merged cells, no
shared-string table — values are written as INLINE strings, which removes a second XML part that
has to stay in sync with the sheet and is the classic way a hand-rolled xlsx becomes unopenable.
Eight fixed styles, named by constant; a ninth means editing `styles.xml` on purpose. A general
style engine is how this file would become the library it exists to avoid.
**Verified as far as it can be here:** there is no PHP binary on the workstation, so the XML parts
were extracted from the source and parsed independently, including checking that every `count`
attribute in `styles.xml` matches its number of children. A mismatch there is exactly what makes
Excel offer to repair a file instead of opening it.
**ZipArchive can be absent on a hardened host**, so the endpoint checks and says so rather than
streaming a corrupt download. A file that will not open is the worst version of "nothing failed
visibly".

## 2026-08-17 — Why the platform's projection read lower than the hand-built sheet
**Diagnosed, not guessed** — the two files were compared row by row.

**It is the retake allowance, and nothing else.** Every day-rate line in
`RTC_ExpenseProjection_v01.xlsx` is exactly **1.1×** the platform's, on all ten rows that could be
compared:

| Row | Platform | Sheet |
|---|---|---|
| Animatic | 4 days | 4.4 |
| Color Script | 5 | 5.5 |
| Concept (Character) | 3.9 | 4.29 |
| Character sheet | 5.07 | 5.58 |
| Prop design | 0.4 | 0.44 |
| FX design | 2 | 2.2 |
| Concept (Environment) | 3 | 3.3 |
| Layout (Environment) ×2 | 6 | 6.6 |
| Color (Environment) ×2 | 9 | 9.9 |
| Layout (Character) | 4.29 | 4.72 |

The sheet carries "+10% RTK"; `project_settings.retake_pct` on Return to Club is **0**, so
`step_quota()` applies no uplift. The maths agrees perfectly — one side simply has a setting the
other does not. Set it on the wizard's Basics screen and the day figures match exactly.

**The second difference is scope, not arithmetic.** The sheet has an *Above the line* department —
Producer / Production, and an Art Director at 4,000 — which has no pipeline steps behind it in the
platform, so the projection cannot see it. Those belong either as steps or, more likely, as **Other
expenses**. The sheet's two "(old)" rows (Iana on Animatic 2,100, Go Gap on CH designs 200) are the
same shape and are already entered that way.

**Consequence:** the Finance screen now says on the page when no retake allowance is set, rather
than leaving somebody to find it by comparing two spreadsheets column by column — which is how it
was found. The exported file states the allowance it used, for the same reason: a reader holding
both files needs to know which side applied it.

## 2026-08-17 — The IDEAL quota: a base sheet per kind of project
**Superseded 2026-09-17:** ideal quotas are per project now, see that entry. Kept for why they began as sheets.
**Decision, David's request:** `quota_standards` (migration 026) holds what the work *should* take
for each kind of project, edited on `standards.php` — a studio-wide reference, not a project
setting. *"The ideal quotas, not the minimal quotas we currently have that are based on the head
count and available time."*

**There are now THREE numbers, and confusing them makes all three useless:**

| | What it is | Who sets it |
|---|---|---|
| **Required** | what the deadline demands — remaining work ÷ working days left | Nobody. `step_quota()` derives it, and it moves when the date or the progress moves |
| **Estimated** | what THIS project was planned at (`pipeline_steps.days_per_unit`) | Typed on Dates & headcount, frozen at kickoff into `step_estimate_baseline` |
| **Ideal** | what the studio believes the work takes when done properly, for this KIND of project | Typed once on the base sheet, true across shows |

**The ideal is the only one that can contradict the other two, and that is its entire value.** A
required pace far above the ideal is not an ambitious plan, it is one the people on it cannot
deliver — and until now that was discoverable only in week three. START_HERE has always said the
review screen exists so "an unrealistic plan is visible at setup"; this is the number that makes
that a judgement rather than a feeling.

**Consequence — the design choices, each with a reason:**
- **Keyed on `(project_type, name, applies_to, applies_to_asset_type)`.** The step-identity triple
  ROADMAP names as the cross-project comparison key, plus the type. **Not on `step_library`**,
  which is `UNIQUE (name)` alone — "Concept" for characters and "Concept" for environments are one
  row there, and they are different jobs with different day rates. ROADMAP names that gap; storing
  an ideal against a key that cannot tell them apart would average two unrelated numbers.
- **Per project type, because ROADMAP already settled it:** comparing a 2D short with a 3D series
  "would average away the signal". `standard_for_step()` therefore does NOT fall back to another
  type — a borrowed standard is a number nobody agreed to.
- **Stored as days per unit, shown both ways.** Everything it feeds is already in days per unit; a
  stored reciprocal would need converting at every boundary. "Half a day each" and "two a day" are
  the same fact and people say whichever suits the step.
- **Nothing is applied automatically.** The planning screen shows the ideal beside the estimate and
  flags an estimate that is *faster* than the standard — slower is somebody being careful and needs
  no warning. A standard that quietly overwrote an estimate would stop being a reference and start
  being a decision nobody made. Detection, never silent correction — the asset-type rule of
  2026-08-13.
- **`source` is a first-class column.** A figure somebody guessed and a figure measured across four
  finished shows are both worth having and must never be mistaken for each other. The seed rows say
  in as many words that they came from existing plans and are "not yet reviewed" — the same care
  migration 013 takes in refusing to backfill a baseline from numbers that had already been edited.
- **`quota_standards_log` is append-only.** "What did we think this took last year, and were we
  right?" cannot be answered by a column that gets overwritten. Same asymmetry as the daily
  snapshots and the estimate baseline.

**Where it goes next, and it is already designed:** the cross-project benchmark in `ROADMAP.md`
compares estimate against actual on exactly this step identity. When it exists it can propose an
observed median for each row — written into `source`, offered, never applied. That is the loop
David described closing: *"informed by experience and later on by comparing other projects too."*
The prerequisite is real effort per step, which is `time_logs`, which now exists.

## 2026-08-17 — Three ways in, and the finance side gets its own tabs
**Decision, David's:** the top bar becomes **My work · Project management · Finance management**,
and the business side of a project gains a tab bar of its own — Overview, Expense projection,
Invoices, Hiring — mirroring `project_nav()`.
**Why:** the bar had grown into a flat list of seven links where a project screen and a database
migration read as equally likely destinations, which none of them are. Everything the studio does
is one of three things: what is on my desk, how the shows are going, how the business is going.
Each of the three now leads somewhere that has its own tabs, so the top bar stops trying to be a
menu for the whole application. The setup and housekeeping screens sit behind a separator — the
same split `project_nav()` already makes between its work tabs and its data tabs.
**A bug fixed on the way:** "Ideal quotas" had been placed inside the `is_admin()` block. The
screen itself is `require_login()` because it is production planning data, not money — so an
editor could reach it and could not see the link to it.

**One file, four tabs, not four files.** `finance_project.php` takes `?tab=`. Every tab needs the
same `expense_projection()` and the same POST handlers, and four files would each have to rebuild
that context — three more places for the money to come out slightly differently. The tabs choose
what RENDERS, never what is computed.
**The Hiring tab is the SAME screen the project side links to, not a copy.** Hiring is one
conversation whether it is opened from the plan or from the budget, and the money on it is already
admin-only inside `money.php`, so nothing needed hiding. Two copies would drift.
**The Overview leads with progress, from `dashboard_overall()`** — the production dashboard's own
figures, not a second count. A budget read without knowing whether the work is half done is a
budget read blind, and walking to another tab to find out is how nobody checks.
**Consequence:** the "set a rate" return from the person page now lands on `&tab=projection`
specifically. Returning to the Overview instead would have reintroduced exactly the friction that
round trip was built to remove.

## 2026-08-17 — Minimum against ideal: does the budget buy a deliverable plan?
**Decision:** the Expense projection tab carries a comparison between the **minimum** quota and the
**ideal** one, per step.
**Why it belongs on the money screen rather than the dashboard:** the two numbers come from
opposite directions and neither is an opinion about the other. The minimum is arithmetic on the
dates and the progress — remaining work over working days left over headcount — and **cannot be
edited**, which is the property that makes it worth comparing against. The ideal is what the studio
says the work takes. A minimum ABOVE the ideal does not mean anybody is slow; it means the plan
requires a pace the studio does not believe in, and the only honest fixes are more people, more
time, or less work. **All three cost money**, which is the screen you are standing on.
**Consequence:**
- Steps with no ideal recorded are left OUT rather than shown blank — a comparison against nothing
  is not a row, and a table of dashes trains people to skim past the rows that matter.
- The minimum shown is the same figure the production dashboard calls *required*, from the same
  `dashboard_steps()`, so the two screens cannot disagree.
- Both figures are per PERSON per day, which is the only comparison that means anything: the ideal
  is one artist working on one item, so the minimum has to be divided by headcount before the two
  can be set beside each other.

## 2026-08-17 (later) — The budget is computed from the IDEAL quota, not the project's estimate
**Decision, David's, and it changes how money is worked out.** *"Ideal is what the studio or
production head determines will budget the project based on."* `expense_projection()` now reaches
for `quota_standards.days_per_unit` first, and falls back to `pipeline_steps.days_per_unit` only
where no standard exists for that step identity.

**This supersedes the arrangement of 2026-08-18**, where person-days came from the project's own
estimate. The estimate is still typed, still frozen into `step_estimate_baseline`, and still drives
the SCHEDULE — it simply no longer prices anything where a standard exists.

**Why a fallback rather than refusing to cost the row:** a step with no standard recorded is a step
nobody has written a rule for yet, not a step that takes no time. Costing it at zero would quietly
shrink the budget, which is the failure this file exists to prevent.

**Nothing about which source was used is left implicit**, because two steps of the same size can
now cost differently and a reader is owed the reason:
- the projection carries `days_source`, `ideal_days` and `estimate_days` on every row;
- rows resting on a project estimate are tagged **est.** in the Days column;
- a banner above the table counts them and links to the sheet that would fix it;
- when every row is on a standard, the screen says that too.

**⚠ THE SCHEDULE DOES NOT FOLLOW, and that is deliberate rather than forgotten.** Dates still come
from `pipeline_steps.days_per_unit` through the scheduler. Changing that would move every bar on
every timeline of every project the moment a standard was typed, which is a phase of its own. Where
the two differ, the money and the calendar are answering with different day counts — the projection
says which number it used, and reconciling them is a decision for whoever notices.

## 2026-08-17 — Ideal quotas live INSIDE Finance management
**Decision:** the base sheet is no longer a top-bar destination of its own. It is a tab on every
project's finance bar — pre-filtered to that project's type — and a panel on the finance hub. The
top bar's business group is one link again: **Finance management**.
**Why:** it is where the number is decided and it is what the number prices. Every cost on every
projection is now computed from it, so reaching it from the project whose budget it produced is how
somebody actually gets there. A separate top-level link made it look like a settings screen.
**Consequence:** it is still studio-wide data underneath — one sheet per project TYPE, not per
project — so the tab is a link out rather than a `?tab=` of `finance_project.php`, and
`finance_tabs()` deliberately does not list it. **Editing stays admin-only**, enforced server-side;
reading stays open, because the dashboard and every step detail show the ideal beside the pace the
deadline demands, and a number a producer cannot see is a number they cannot plan against.

## 2026-08-17 (final) — The plan runs on the IDEAL, through exactly one function
**Decision, David's:** *"Let's plan with the ideal quotas."* `quota_standards.days_per_unit` is now
days-per-item everywhere — the quota, the scheduler, the budget, the time-log comparison, the CSV
export and the estimate baseline. The value typed on Dates & headcount is the fallback for steps the
base sheet has no answer for.

**The bug this closes, which is the reason it was worth doing.** The budget was switched to the
ideal on its own earlier the same day, and everything else kept reading
`pipeline_steps.days_per_unit`. That is precisely how Dates & headcount came to say 0.85 a day for
CH sheet while the expense projection said 1.86 — two screens, two numbers, both arithmetically
correct, describing different plans.

**`effective_days_per_unit()` is the single reader.** There were SIX before it:
`includes/quota.php`, `includes/scheduler.php`, `includes/finance.php`, `includes/timelog.php`,
`export.php` and `includes/baseline.php`, plus the live check in `assets/wizard.js`. Every one of
them now calls the helper, and the JS applies the same rule so the screen cannot describe a plan the
server would not build.
**Consequence, and the details that matter:**
- **The helper is NOT retake-adjusted.** Callers apply the allowance themselves, because they do not
  all apply it the same way — a per-second COST must not be inflated by retakes while the DAYS must
  be. Folding it in would make that impossible to express.
- **Two lookups are memoised** (`standards_cached()`, `project_type_of()`), because the helper runs
  once per step inside loops that already run per step.
- **The estimate baseline records the EFFECTIVE number**, not the raw column. Freezing a figure the
  plan never used would make "what we believed at kickoff versus what happened" meaningless.
- **The typed field says when it is not in charge.** Where a standard exists the Days per item box
  reads *"not in use — planning on the studio ideal of N d"*. A field that silently does nothing is
  worse than one that admits it.
- **Template copying still reads the raw column** (`includes/pipeline.php`), correctly: that is
  moving data between a template and a project, not planning with it.

## 2026-09-16 — Dates & headcount edits the number the plan uses, the studio ideal included
**Superseded 2026-09-17:** the box still holds the number the plan uses, but that number is the project's own, see that entry.
**Decision, David's:** *"It makes no sense to have an editable field that's not used in this
context. Please switch them for the studio ideal, so it just brings the same variable here to be
edited if needed."*

**What prompted it.** Concept (Environment assets) had 2 typed in Days per item and showed a
3-day duration. The plan was running on the studio ideal of 0.5 — 4 × 0.5 × 1.1 retakes = 2.2,
rounded up to 3 — exactly as the 2026-08-17 decision below says it should. The box said "not in use"
in small type, and the last column simultaneously flagged the ignored 2 as "planned 300% slower".
An editable number the plan ignored, beside a warning about that same number.

**What changed.** The Days per item box now holds whatever the plan runs on:
- **a studio ideal exists** → the box IS the ideal. An administrator edits it in place; an editor
  sees it read-only, because editing the base sheet is admin-only everywhere.
- **no ideal** → the box is the project's own estimate, as before.
A line under each box says whose number it is. The **Ideal days** column is gone — it repeated the
box and carried the contradiction.

**This reverses one line of 2026-08-17.** The planning screen said the ideal was "read-only here on
purpose: a number that could be changed from two places would stop being a standard." That worry is
answered rather than ignored: it is the SAME record edited from two screens, not a copy, through ONE
writer (`update_standard_days()` → `save_standards()`), logged in `quota_standards_log` the same way,
admin-only in both places.

**Consequences, and the traps closed on the way:**
- **It is studio-wide.** Ideals are one sheet per project TYPE, so a change made on FC Mobile changes
  every 2D project's plan and budget. The page asks before saving when an ideal moved, and the saved
  message says how many projects of the type it reaches.
- **A merge, not a plain save.** `save_standards()` writes every column of a standard; called with
  only the days it would have wiped typical headcount, source and notes on the studio sheet.
- **A blank never removes a studio ideal from this screen.** On the Ideal quotas sheet a blank
  deletes the standard, which is right there. From one project's table it would delete a studio-wide
  number as a side effect of clearing a box.
- **Full precision, and a tolerance.** The box shows the ideal's three decimals and the server writes
  only a change of a thousandth or more, so re-saving an untouched page never rewrites a studio number
  through rounding.
- **The project's own column is kept, not overwritten**, on steps with an ideal. It is still the
  fallback should the ideal ever be removed, and it is what a pipeline template copies.

## 2026-09-16 — Project types are a list an administrator adds to
**Decision, David's:** *"we should have a way to manually create new project types then because
right now it doesn't offer that possibility."* Prompted by a 2D motion comic: technically 2D, but its
steps and quotas differ, so its budget does too, and ideal quotas are one sheet per type. The only
choices with four fixed types were changing the 2D sheet for every 2D show, or filing the comic under
Other.

**What changed.**
- **Migration 032** adds `project_types` (seeded with the four, plus any type an ideal quotas sheet
  already used) and turns `projects.type` and `pipeline_templates.project_type` from ENUMs into
  VARCHAR(20), the size `quota_standards.project_type` already was.
- **One list.** `project_types()` in `functions.php` replaces the five copies of the four: the Basics
  picker and its check, the template picker and its check, and the Ideal quotas tabs. Before 032 it
  answers with the four, which is exactly what the ENUMs still accept.
- **Added on Ideal quotas**, with "+ New project type" beside the tabs, and from the same panel beside
  Type on Basics, which returns with the new type picked but not saved. Administrators only, like the
  sheets. Names are 1 to 20 characters and unique ignoring case, the way MySQL compares them.
- **The new sheet starts as a copy of another type's by default**, preselected to the type being
  looked at. Copied rows keep their source behind "Copied from 2D:". Moving a project onto a copy
  changes nothing until a number on the new sheet changes, so the comic starts as 2D and parts from it
  one step at a time. An empty sheet is still offered, and its save message warns what it means.
- **Changing a project's type now says what moved.** Saving Basics compares every step's days per item
  under the old sheet and the new one, and names the steps that changed and the steps that lost a
  studio ideal. The type decides the plan and the budget from a screen that looks like it edits a label.
- **Removing a type** is offered only when no project (archived ones included) and no template uses it,
  and never the last one. Its sheet goes with it, cleared through `save_standards()`, so the history log
  keeps what it said. Otherwise `standard_project_types()` would bring the type back as a tab.

**Two silent rewrites removed on the way.** Basics and "save as template" both turned an unrecognised
type into Other. With types that can be removed, that would move a project onto another sheet without
anybody choosing it. Both now refuse, with a message. Every type picker also always offers the value it
already holds, because a `<select>` with no matching option shows its first one, and the next unrelated
save would store it.

**Not built: renaming a type.** It would have to rewrite projects, templates, the sheet and its log in
step. The workaround: create the right name as a copy of the wrong one, move the projects, then remove
the wrong one.

**Superseded the next day** for everything about sheets: see 2026-09-17 below. Types stay a list;
they no longer own a sheet, so creating one is a name.

## 2026-09-17 — Ideal quotas belong to each project
**Decision, David's:** *"the ideal quotas that impact the budget should remain the same because
between project (even if they're the same type) might have different ideal quotas and that will change
my budget so this needs to be per project. We can start copying another one but if I change something
it shouldn't affect other projects at all."*

**This reverses the per-type sheet** of 2026-08-17 and what was built on it: the 2026-09-16 Days per
item box that edited a studio ideal from one project, and the same day's sheet copy when creating a
project type. The motion comic made the cost visible: two shows of one type can differ, and a sheet
shared by type moves one show's budget whenever another's number is corrected.

**What changed.**
- **One number per step per project.** `pipeline_steps.days_per_unit` IS the project's ideal quota.
  `effective_days_per_unit()` returns it and is still the only reader, so the schedule, the quotas, the
  budget, baselines and exports all moved together.
- **Nothing moved on the switch.** Migration 034 copies each type sheet's number into every step that
  was planning on it, and into template steps from the sheet of the type they were saved for, after
  keeping the values it overwrites in `days_per_unit_before_034`. The code switches when 034 is
  **recorded as applied** (`migration_applied()`), not when a column exists, so no budget can move to the
  old ignored values while the update waits.
- **Three decimals.** Migration 033 widens days per item from DECIMAL(5,2) to (6,3), the sheets' own
  precision: copying 0.435 into the old column would have stored 0.44, a budget change nobody typed.
  033 also adds `days_source`, where a step's number came from. A separate file because ADD COLUMN
  cannot run twice, and 034 must be safe to re-run.
- **Edited in two places, stored once:** the project's **Ideal quotas** tab, now a Finance management
  tab like the others with a note per step, and the **Days per item** box on Dates & headcount.
  Administrators only, as ideal quotas always were. A changed number whose note was left alone gets
  "Set by (who) on (date)", so "Copied from FC Mobile" never outlives the copy.
- **Copy from another project**, offered on both screens: matched by step name, scope and asset type;
  steps the other project lacks keep their number and are named. A copy, never a link.
- **Types are labels.** A type files a project and puts its templates first. "+ New project type" lives
  on Basics only, takes a name, and lists the unused types with a Remove each (`project_types.php`).
  Changing a project's type changes no number.
- **The type sheets stay in `quota_standards`**, untouched and unread, with their log.

**Given up:** typical headcount and notes per standard, which the per-project sheet does not carry
(planned headcount per step already lives on Dates & headcount). And the studio-wide statement "this is
what Concept takes on a 2D show": it now lives in whichever project you copy from.

**Before 034 is applied** the old rule stands, the boxes are read-only, and both screens say why.

## 2026-09-17 — A project's ideal quotas can be matched to its approved expense projection
**Asked by David:** *"can you re-adjust the budget for FCM to match this approved project expense?
Comparing the current budget for that project is what made me realize something was changing."* The
approved sheet is `RTC_ExpenseProjection_v01.xlsx`, which the platform matched on 2026-08-17 apart from
the retake allowance. Since then FCM planned on the 2D sheet, edited from other projects, and drifted.

**Built as a tool on the Ideal quotas tab, not typed in by hand**, because the numbers cannot be worked
out anywhere but the live project: every footage row in the sheet leaves its count blank, and the
runtime lives in FCM's own shots. Upload the approved sheet, see a preview, apply.
- **Each role's person-days** (its artist rows added up) become days per item for the matching step:
  days ÷ (this project's count after coverage × retake factor), the same counts `step_quota()` and the
  projection use. Snapped to the plainest number within the sheet's own rounding, half a hundredth per
  row, so 5.58 days over 5.577 items is 1, not 1.001.
- **Preview, always.** Roles typed by hand do not always name a step the way the platform does
  ("Character Sheet" against "CH sheet"), so every match is shown, can be changed, and can be previewed
  again. Roles are matched by the export's own "Name (scope)" label, else by a name only one step has.
- **Days per item only.** Rates are compared and differences noted, never changed. Flat fees (their cost
  does not depend on days) and rows without days (Art Director, the "(old)" rows) are named and left.
  After applying, the projection's new total is shown beside the sheet's, and any gap is attributed to
  rates or other expenses, since the days now agree.
- Each matched step's note reads "Matched to (file) by (who) on (date)".

**Checked against the real file** by replaying the reader, the grouping and the snapping in Python (no
PHP on the workstation): 20 roles with days, 2 flat fees, 4 rows without days, total 43,442.40. Where
the sheet states a count, days per item came out 4, 5, 0.3, 1, 0.2, 2, 0.5, 2, 3, 0.33 and 1, each
reproducing the sheet's days to the hundredth. Those are the numbers FCM planned on in August.

## 2026-09-18 — Staffing keeps everybody lined up for a position
**Asked by David:** *"is there a way we can keep the lined up artists in the staffing tab? It happens
that they leave the project and I need to see who I talked to and what other options I have."*

**What was hiding them.** Nothing was deleted: every candidate stays attached to the position they
were lined up for. The Staffing screen simply listed only the open stages, and only on empty
positions. Hiring somebody took every other name off the row; declining or passing took that name off
for good; and the person hired kept a record reading "hired", so when they left, they vanished too.

**What changed.**
- **Empty position:** the people in the running as before, then a fold, **Talked to before**, with
  everybody whose conversation ended (declined, passed, hired and since left), how it ended and when.
  It opens by itself when nobody is in the running, because that is the moment it is the answer.
- **Filled position:** a folded **Also lined up for this position** with the backups: the open ones
  with their stage in place (no Hire while somebody holds the position), and the ended ones.
- **Line up again** puts any of them back at Approached on the same record, so each person keeps one
  history per position and the stage log still says how it went the first time.
- **Leaving is recorded.** "Remove assignee" and "Change assignee" write a *left* line into the hired
  person's stage log. The record itself keeps reading "hired", because that is what happened; "has
  left" is DERIVED (the record's person is no longer the position's), so nothing has to be kept in
  step, and the log only supplies the date. A hired record may now be lined up again once its person
  has left the position, and is refused while they still hold it, as before.

No schema change: `hire_stage_log.to_stage` was already free text, and `hires.stage` did not need a
new value.

## 2026-09-18 — On the general timeline a bar moves wherever it is dragged
**Asked by David:** *"do you know why I can't move the File Prep bar in this MSD timeline?"*

**The chart library, not the plan.** Frappe Gantt 0.6.1 refuses to place a bar left of a step it
depends on ("child task must not go before parent", in `update_bar_position()`), and through a quirk
of that check compares only with the LAST dependency listed. File prep waits for two colour steps
that start in October while it is planned in September, so every position it was dragged to was
refused, silently. The decision of 2026-08-31 is the opposite: dependencies are drawn, a violated
one turns red, the bar stays where it is dropped. So the library's method now runs with the task's
dependency list emptied for the length of the call (patched once on the Bar prototype, because every
re-render builds new bars). Reproduced before and after with the real library on MSD's shape.

**Found on the way, both fixed:**
- **A refused drag was not harmless.** The library still moved, and the page SAVED, every step
  downstream of the bar that would not move: trying to move File prep shifted Comp · shots and wrote
  its new dates. Anybody who tried to move File prep may have moved Comp.
- **Hovering any bar that waits for another threw an error**, so those bars had no popup at all.
  Frappe turns the dependency list into an array in place; the popup still split it as text. The
  popup also names two steps that share a name in full ("Color · shots"), not "Color, Color".

**Still true, and David's call:** dragging a bar carries every step downstream of it along, and saves
them, as it always has on this chart.

## 2026-09-18 — Timeline versions: a saved copy of the page, read only
**Asked by David:** *"Timeline already has persistent memory and stores the timeline as I adjust it.
However, I need to check the historic changes."* A **Save timeline's version** button, a dropdown of
previous versions, names `V00_09182026`, `V01_09182026`, `V02_09192026` and on, a subtle new
background on an old one, and a button back to the current timeline. Migration 035,
`includes/timeline_versions.php`, `timeline.php`.

**What a version is.** A copy of what the page drew when the button was pressed: both charts and the
milestones, with every name, colour, lock and approval as they were. Stored as the drawing itself
(JSON in `timeline_versions.payload`), **not as ids** into `pipeline_steps` and `schedule`: those rows
keep moving, and a step renamed or a shot deleted next month would otherwise quietly rewrite what an
old version shows. **One builder,** `timeline_picture()`, is both what the page draws from and what a
version freezes, moved out of `timeline.php` unchanged. So a version can only look the way the page
did; a second copy of that code for the save would have drifted within a month.

**Settled in building it:**
- **The name is derived, never stored:** `V` + the number, two digits, + `_` + MMDDYYYY, from `seq`
  and `created_at`, like ticket references. The number is **per project and runs on across days**
  (V02 then V03 the next morning, not V00 again), so it alone says which came first. `created_at` is
  written by PHP in the studio's timezone, not defaulted by MySQL, whose clock may be elsewhere; the
  name's date is cut from that text, never converted.
- **Read only, and enforced by the page, not the browser.** On an old version `$editable` is false:
  no Save, Regenerate, task editor, milestone editing or add form, and `TL_CAN_EDIT` is false so a
  drag snaps back. It matters more than it looks: an old version's bars carry the LIVE rows' ids, so
  a drag there would save into today's plan while the person believes they are only looking.
  Verified by dragging on both charts in a harness: nothing was sent, while the same drag on the live
  copy saved four steps.
- **The .gan buttons are hidden on an old version.** Both read or write the live schedule, so a
  download there would hand over today's plan under an old version's name. PNG and PDF stay, named
  after the version (`MSD_GeneralTimeline_V01_09182026.png`) instead of today's date, and they keep
  the tint. Keeping the tint was the outcome, not the first plan: html2canvas copies an SVG's computed
  colours inline WHILE cloning, so taking the class off in `onclone` arrived too late (measured). A
  picture of an old plan that looks old is the right result anyway.
- **The picker is a plain GET form** (`?project=12&version=5`), so a version has its own address and
  opens even if the chart script fails. Newest first, "Current timeline" at the top.
- **Warm paper** (`.tl-page-old`), a colour used nowhere else, including the chart rows, which are
  Frappe's own fills and would otherwise stay white exactly where you read.
- **The staleness check is skipped on an old version.** It exists to say the saved schedule no longer
  matches the setup; an old version is supposed to differ.
- Saving refuses an empty timeline, reports what it kept ("6 step bars, 212 scheduled tasks and 2
  milestones"), and checks the size against `max_allowed_packet` first, because that failure
  otherwise reads "MySQL server has gone away". No transaction: one INSERT, with UNIQUE
  (project_id, seq) and a retry for two saves in the same second.

**Not built, on purpose:** restoring a version onto the live plan (overwrites what is there, so it is
David's call first), deleting versions, a note per version, and comparing two versions.

## 2026-09-18 — A project with no saved schedule says so on the timeline
**Asked by David:** *"why does the MSD project doesn't have a detailed timeline like FCM?"* Because
none had been saved. The general chart draws each step's planned dates and needs nothing else; the
detailed chart draws the saved `schedule` rows, which exist only once somebody presses **Save this
schedule** on the Schedule tab or Regenerate. The section used to vanish entirely when there were no
rows, taking the Regenerate button with it, so nothing on the page hinted that a schedule was
missing, let alone how to make one. The same shape as lesson 11: a feature that is absent without
saying why looks exactly like one that was never built.

Now the section stays and says **Not built yet**, with the task count the setup works out to (the
page already computes it for the staleness check), **Build the detailed schedule** (the same POST
Regenerate makes, behind a confirm) and **Preview it on the Schedule screen**. When the setup yields
no tasks, only the preview is offered, since that screen lists why.

## 2026-09-18 — Double-click a step's bar: its dates, the days it needs, and its progress
**Asked by David:** *"open a display menu when you double click a bar or a milestone so we can
manually change the start/end date, reflect the duration (minimum days to finish) from the
dates&headcount and register the progress of that task manually."* Migration 036, the step editor in
`timeline.php` and `assets/timeline.js`, `step_edit` / `step_progress` in `schedule_api.php`.

**Milestones and task bars already did this.** Frappe 0.6.1 raises its click event on a DOUBLE-click
(read in its `setup_click_event()`), so the detailed chart's task editor has always opened that way,
and milestones open theirs on a double-click too. The page used to say "click a bar to edit it"; it
now says double-click. What had no editor was the general chart's step bars, and that is what this
adds.

**What the editor holds:**
- **The two dates**, each with its weekday underneath, and "a day off" (with the holiday's name) when
  it falls on one.
- **The working days between them**, saying what it left out ("not counting 4 weekend days and
  Independence Day"), against **what the step needs at its settings**: the Duration column of Dates &
  headcount, in the same words ("needs 9 working days, so 6 spare", or "4 more than these dates
  allow"), and the sentence it is made of ("45 shots at 0.5 days each, plus 10% retakes, shared by 3
  people"). **Finish after 9 working days** sets the end to fit; **Open Dates & headcount** goes where
  the ingredients are changed.
- **Progress**, a slider and a box, 0 to 100, who set it and when, and beside it **what the tracking
  grid says** ("3 of 45 approved on the tracking grid, 7%") with a button to use that figure.

**Settled in building it:**
- **The duration is computed in PHP by `step_duration()`** (`includes/quota.php`), which mirrors
  `updateDuration()` in `assets/wizard.js`: the arithmetic lives twice, in the browser for the live
  check and on the server for this, and each now names the other. It was replayed against David's
  own screenshot of Dates & headcount (5, 2, 6, 8, 1, 9 and 16 days) and matched every row.
- **Progress is a percentage somebody types, not a count** (`pipeline_steps.progress_pct`,
  `progress_source`). For a step that is one deliverable, the grid can only say 0% or 100% until the
  day it is approved; this is the producer's own reading, and it sits next to the grid's figure so
  the two are never taken for each other. Drawn as Frappe's own progress fill, in a darker shade of
  the step's colour; dragging the little handle under a bar changes it too (Frappe draws that handle
  only between 1% and 99%, so a step's first progress is typed). **Only a changed number is
  re-attributed**: saving new dates does not stamp "set by" on a progress nobody touched.
- **Dates and progress save in ONE request** (`step_edit`), so the two cannot half-save.
- **The editor opens for everybody**, read only without the right to edit and on a saved version,
  where it shows the numbers as they were: they travel with the bar in `timeline_picture()`, so a
  version saved from today on keeps them. Versions saved earlier carry no duration or grid figure,
  and the editor leaves those lines empty rather than inventing them.
- Before 036 is applied, the progress box is switched off with the reason, and the server refuses a
  request carrying a progress rather than saving only its dates.

**Not built:** a history of progress (only the latest number is kept, though every saved timeline
version freezes the numbers of its day), and a marker on the bar for where the minimum duration ends.

## 2026-09-18 — A dragged bar keeps its working days
**Asked by David:** *"when I move around the bars in the timeline, the total/minimum duration is
respected when moved through the weekends which are days off? Otherwise, the bar needs to adjust by
subtracting those days and extending the bar when a weekend interferes."*

**It was not.** Frappe moves a bar by calendar days, so a bar slid so that a weekend or a holiday fell
inside it, where none had before, silently lost a working day, and gained one the other way. Both
charts. The scheduler had always counted in working days (`add_working_days()`); a drag was the one
place a bar could change length without anybody saying so.

Now, on both charts (`landOnWorkingDays()` in `assets/timeline.js`):
- **Moved** (both ends travelled): it keeps the working days it held, starting on the first working
  day on or after the drop and stretching over any weekend or holiday on the way.
- **Stretched from the left**: it starts on the first working day on or after the drop.
- **Stretched from the right**: it finishes on the last working day on or before it, so a deadline
  dragged onto a Saturday lands on the Friday.
- A bar holding no working day at all keeps its calendar length, having nothing else to keep.

The calendar is the studio's own, `non_working_days`, handed to the page as `TL_DAYS_OFF`, and the
helpers mirror `includes/calendar.php` (`is_working_day()`, `next_working_day()`,
`working_days_between()`, `add_working_days()`), plus one that steps back to the previous working
day, which only a stretched end needs. Holidays are now **shaded** on
both charts in Day view, like weekends, which the old comment there had been waiting for: a bar
stretching over a holiday stretches over a shaded column. The chart is redrawn once per drag, only
when a bar had to move from where it was dropped, and **keeps its scroll position** (milestone drags
and editor saves too; every redraw used to throw the view back to the start of the show).

Verified in a harness with the real Frappe 0.6.1: Layout moved 4 days kept its 9 working days and
landed Friday to Wednesday over a weekend, and the three steps it carried kept theirs across the 2 and
16 November holidays, all four on dates counted by hand beforehand.

## 2026-09-18 — A drag on the detailed chart was unassigning the task
**Found while building the above.** Dragging a task bar posts only its dates, and `schedule_api.php`
read the missing `crew_id` as "nobody", so **every task ever dragged on the detailed chart lost its
person or slot**, silently. The same drag locks the task, so Regenerate, which leaves locked tasks
alone, never gave it back. The chart hid it until the next reload, because the browser kept the old
name in memory.

Fixed: an ABSENT `crew_id` now means unchanged, and only an empty one means nobody (the task editor
always sends the field, its blank option included, so it can still clear an assignment). **Tasks
already dragged are still unassigned**: they show no name to the left of their bar. Each still
carries its lane, and `crew_lanes` says who holds that lane now, so a one-off repair could put the
names back. Offered to David, not run: it would also fill any task somebody deliberately set to
"nobody" in the editor.

## 2026-09-21 — Backgrounds are assets of their own type
**David:** *"I'm about to create a new asset type called Backgrounds, should I create a pipeline step
first or populate the asset link first? Remember that we might have 4 environments but out of those
we can have several and different backgrounds based on that environment's concept."* This settles
half of the backgrounds question left open on 2026-09-16 (START_HERE §0, decision 4b): one asset per
background, of a type of its own, rather than an estimate box. Still open: which steps each goes
through.

**The assets come first, and the code decides it, not taste.** A step's "Applies to" dropdown offers
only the types `asset_types()` knows: the five starters (Character, Environment, Prop, Vehicle, FX)
plus every type an asset or a step already uses, on any project. The pipeline screen has no box for
a new type; the Assets screen does ("Or a new type"). So the first backgrounds create the type, and
only then can a step be scoped to "Assets, Background only". The same moment gives the Shots screen a
Background column, generated from the types the project has.

**Advised with it:**
- **"Background", singular**, like Character, Environment and Prop, so steps read "Color · Background
  assets". The platform matches the spelling exactly, and a step scoped to one spelling counts
  nothing of the other (the Assets screen flags Background/Backgrounds as one type spelled twice).
- **Name each background after its environment** ("Forest BG01") and say so in its note. That is the
  only place the relation lives today.
- **Before adding them, check for steps that apply to every asset** ("Assets", no type): those start
  counting the backgrounds the moment they exist, and their work, duration and budget grow with them.
- **Regenerate afterwards**: the saved schedule does not pick new items up by itself.

**What the platform cannot do yet: the environment a background comes from.** `assets` has no parent,
so a background step that waits for the environments' concept waits for the WHOLE of that step, all
four environments, not the one it was painted from: `scheduler_item_earliest()` matches the same
item, or for a shot the assets it uses, and otherwise falls back to the whole step. Offered, not
built: a "from environment" link on each background (one nullable column on `assets`, a picker on the
Assets screen), and a fourth rule in that function, an asset waiting on an asset step waits for its
parent there. It would also let a shot's backgrounds pull in their environment.

## 2026-09-21 — Bookings: who is needed where, across every show
**Asked by David:** *"is there a way I can see the overlapping of artists across projects and
general timelines?"* There was not. It is the reason crew was made global on 2026-08-11 ("per-project
crew makes cross-project workload invisible"), and every people screen since has looked inside one
show. `bookings.php` (top bar, beside Artists), `includes/bookings.php`, `assets/bookings.js`. No
migration.

**What a booking is.** A real person holding a position on a step whose dates are set on its show's
general timeline: needed from the step's start to its deadline. Read from `crew_lanes` (filled by
Staffing) and `pipeline_steps`, so it says what the plans say. Placeholder slots book nobody, but
the By show view counts them ("2 positions not filled yet"); a team is never booked; positions on
undated steps are listed under the chart rather than dropped. Shows in setup, active or on hold;
wrapped and archived ones are over and would only add clashes nobody can act on.

**What a clash is.** One person booked on two DIFFERENT shows whose dates cross on at least one
working day (`bookings_clashes()`, with `working_days_between()`, so the studio's holidays count):
a step ending on a Friday and another starting that Saturday is no clash. Two steps of the same show
at once are left out on purpose: that is usually one job, a department carrying on, and the question
was about shows.

**Two views of the same facts**, buttons at the top:
- **By person**: a row per person, each booking a bar in its show's colour, stacked when two cross,
  the clash shaded red across the row and both bars outlined, with a count beside the name.
- **By show**: every show's general timeline stacked, a row per dated step, who holds it named on the
  bar, red where one of them is needed on another show then, the delivery date on the show's row.

Above both, the clashes in sentences ("Ana López: MSD · Animation (5 Oct to 6 Nov) and DOOM · Layout
(12 Oct to 30 Oct) cross for 15 working days"). A chip per show switches it off, and its clashes
with it; "only clashes" narrows either view; zoom keeps the date at the left edge; a bar opens its
show's timeline.

**Drawn as positioned HTML, not with Frappe Gantt,** because Frappe puts one bar on a row and a
person's shows side by side on ONE row is the whole point of the first view. The clash arithmetic is
in PHP, so it was replayed line for line in Python on a sample built to catch the edges: a clash
across a holiday (4 working days, not 5), two shows that share only a Saturday, and two steps of one
show at once. The drawing was checked in a browser against those numbers, pixel for pixel.

**Not built:** the detailed schedule as a second source (the task days rather than the step window),
engagement dates and part-time allocation (`crew_projects.start_date`, `end_date`, `allocation_pct`:
someone on two shows at 50% each is not really double-booked), and clashes inside one show.

## 2026-09-22 — N/A leaves a step's count, rather than counting as done
**Asked by David:** *"there are some exceptional occasions where I can list all the assets/shots but
not all of them need to go through that task/department. In this case for FCM only 4 characters
require concept. How can we adjust that? Easiest solution might be to count N/A as
completed/approved."*

**Nothing to build: that is what N/A already does**, and it does the better half of it. An item whose
status is marked *not applicable* (`statuses.is_excluded`, seeded as "N/A" in migration 004 and
flagged in 010) leaves the step's denominator through `step_applicable_count()`, so FCM's Concept
reads 3.0 of 4.0 rather than 3.0 of 27. Counting those items as approved instead would have read
26 of 27: the same colour on the card, but approved feeds the pace, the percentage, the burn-up and
the project's overall figure, so the team would have been credited with 23 pieces of work that never
existed. The advice was to set it in Plaky and let the import carry it, which is what David did.

**Worth knowing, and it caught him for a day:** the platform REMEMBERS what it decided about an
incoming status text the first time it sees one (`resolve_external_status()`). A text with no
matching status is stored with no meaning and imported as a row with no status, and every later
import reuses that decision, so re-importing the same word changes nothing. The Names tab lists those
under "Status values with no meaning", and giving one a meaning repairs the rows already stored
(`reapply_status_mapping()`), no second import.

**Still true and not reconciled:** N/A marks change the TRACKING side (the dashboard, the grid, and
the scheduler, which stops making tasks for them). The PLANNING side, the duration, the quota and the
budget, reads the step's coverage percentage instead (`step_work_units()`). FCM's Concept had
coverage at 12.5% already, so the two agree; nothing keeps them in step by itself. Offered, not
built: once items are marked, the real count could replace the coverage guess. It would move budget
figures, so it is David's call.

## 2026-09-22 — A finished step goes green
**Asked by David:** *"Can we change the card and bar color to green when it's finished that
department?"* On the dashboard, a step whose applicable work is all approved now draws its card and
its bar green (`#1e7e34`, the green the rest of the app means "done" with), and the pace block, which
for a finished step says "0.00/day each across 2, 0 working days left", becomes **✓ Finished**.

**It needs work to have existed.** `target > 0` as well as nothing to go: a step with everything
marked N/A, or with no items yet, also has "0 to go", and green there would claim work was completed
where there was none. Those cards stay as they were. The bar keeps the step's own colour until it is
finished, so the timeline and the dashboard still agree about which step is which.

## 2026-09-22 — A shot's note can be typed where it is read
**Asked by David:** *"how can I edit these notes?"* You could not. A shot's note arrived with an
import and stayed whatever the spreadsheet said: the breakdown printed it, the setup screen has no
field for it, and the item page shows it read only. An asset's note has been editable since the
wizard was built, which is what made the gap hard to see.

The Notes column on the shot breakdown is now a box, saved on leaving the field, exactly as the
Frames box beside it works (`breakdown_set_note()`, `action=notes`). Empty clears the note so the
column reads as a dash again; a note over 2,000 characters is cut, and the cut is reported rather
than left to be discovered.

## 2026-09-22 — The shot breakdown totals its frames and its footage
**Asked by David:** *"let's add frame and second count in this page (both top and bottom)."* A line
above the table and a footer row under it: shots, frames, seconds, the running time once there is a
minute of it, and the project's fps. The footer keeps the frame sum in the frames column, under the
numbers it adds.

**Counted over the rows SHOWN**, so the episode filter narrows the totals too, and **shots with no
frame count are named** ("3 without a frame count yet") rather than counted as zero: a total quietly
missing five shots is worse than one that says what it left out. It is also the number the expense
projection prices, so an understated one is money.

`fmt_secs()` moved from `includes/dashboard.php` to `functions.php` on the way: this screen needed
it, and pulling the whole dashboard into it to reach one formatter was the wrong way round. One
formatter, because two would drift the first time either was corrected.

## 2026-08-17 — Paying by the second, and why retakes do not touch it
**Decision:** `rate_unit` gains `'second'` (`finance_install_06.sql`) for footage steps — rough
animation, tie-down, cleanup — quoted per second of screen time.
**Almost none of it was arithmetic.** The platform already thought in seconds for those steps:
`units` IS total seconds and `days_per_unit` is days PER SECOND, so person-days and the even split
across lanes were already right. The only gap was a way to say the RATE was per second, so those
artists were entered as day rates and costed as days × day-rate.

**⚠ RETAKES INFLATE THE DAYS AND NOT THE SECONDS — David, asked directly, and it follows from the
standing rule.** A retake allowance multiplies days-per-item and never work units (2026-08-12): 22
assets are still 22 assets after a revision. Applied here, 380 seconds of footage is 380 seconds
however often it is revised. So a per-second cost is **flat** while its duration grows.

That is not a gap in the model, it is what buying by the second *means*: the price is capped and the
revision risk sits with the artist. If a contract ever bills retakes on top, that is a different deal
and wants its own flag rather than a change to this rule.
**Consequence:**
- **Guarded on the step actually tracking seconds.** On a step measured in shots, `units` is an item
  count — a per-second rate times 41 shots would be a confident wrong number that nothing on screen
  would question. Those rows are reported as a mismatch and counted with the unpriced days, never as
  free.
- The Excel formula becomes `E×I` (rate × units) rather than `E×F` (rate × days), so a per-second row
  stays live and editable in the sheet.
- `crew_terms_log.rate_unit` is already VARCHAR and needed no change; the history simply starts
  recording the new word.

## 2026-08-17 — One home; the two managements move INSIDE a project
**Decision:** the top bar carries **My work · Projects** and nothing else about a show.
"Project management" and "Finance management" stop being top-level destinations and become a
**section switcher** drawn on every project screen, above the tab bar. `finance.php` survives as
a report — *Finance across all projects* — reached from the project list, not as a rival to it.

**Why:** both top-bar links led to a list of the same projects, so the first thing the app asked
of you was a question you could not answer yet: which lens, before you had said which show.
Worse, the two lists looked alike, so which one you were on was a guess. David, looking at it:
*"There should be only one 'home' where you can see all the projects."*

**How "you are here" is answered**, since one bar could not do it:
- the **switcher** says which side of the project you are on — run it, or pay for it;
- the **tabs** under it say which screen;
- both mark themselves, and the finance side tints its lit section and current tab with the
  administrators-only red, so the money side never reads as another production screen.

**Consequences:**
- `project_section_nav()` in `includes/project_nav.php` is the single renderer of the identity
  line and the switcher. `project_nav()` and `finance_nav()` both call it, so the 21 screens
  calling the former needed no change at all.
- The switcher is **only drawn when there is a choice** — an editor has no finance credential
  (`includes/money.php`), so they see the tabs alone rather than a lit button that leads nowhere.
- `hiring.php` is on both bars deliberately, and now takes `&section=finance` so the tab that was
  clicked is the tab that lights up when it lands. Carried through its save redirect.
- `standards.php` is studio-wide, so it cannot carry a project's nav; it takes `&from=<project>`
  and draws a crumb back instead — to the finance screen for an admin, to the project for an
  editor, because the sheet is *not* admin-only and must not point anyone at a door that would
  turn them away. Carried through saves and type switches.
- Missing-project redirects on `finance_project.php` and `export_projection.php` now land on
  `index.php`. A project that is gone is gone from both sides.

**What it cost:** a fatal. The `standards.php` crumb was written with its own `<?php` in a region
that was still executing PHP — a parse error that would have 500'd the screen, and that none of
the three existing checks could see. `tools/check_php_blocks.py` gained a fourth pass for it, and
the pass was verified by reintroducing the bug and watching it fire.

## 2026-08-18 — Money is rounded to cents at the point it is computed
**Decision:** `expense_projection()` rounds every lane cost to 2 decimals as it works it out,
and both live Excel formulas wrap in `ROUND(...,2)` to match. The sum of the line items IS the
budget.

**Why:** a lane cost is a rate times a fractional number of days, so it lands on fractions of a
cent — 230 x 2.45025 = 563.5575. Everything DISPLAYED 563.56 and every total SUMMED 563.5575,
so a projection did not add up: six departments showed rows totalling 14,922.05 under a grand
total of 14,922.02. David found it comparing a real export against a hand calculation.

**Why it mattered enough to fix:** the error is per ROW, not per amount — roughly half a cent a
row — so it scales with the size of the sheet rather than the size of the budget. Three cents on
seventeen rows; a whole unit on two hundred. *"I could live with it but with bigger numbers this
variation might be problematic."*

**Consequences:**
- Rounded once, at the single point a cost is worked out, so the screen, the hub, the person
  page, the export and the margin agree by construction rather than by each rounding to taste.
- The Excel formulas had to round too. Without it Excel recomputes the raw product on open —
  `fullCalcOnLoad` — and silently puts the fractions back, so the sheet would disagree with
  itself again the moment it was opened.
- There is no truer total hiding behind the rounding: nobody can be paid 563.5575.

---

## Still open

- **Whether to put back the people on tasks dragged before 2026-09-18.** A drag on the detailed chart
  unassigned the task until that day (see the entry above). Each such task keeps its lane, and the
  lane's current holder could be written back in one pass. Not run: it would also fill any task
  somebody deliberately set to "nobody", so it is David's call.
- **Waiting on David, from the 2–18 September run** (the detail is in `docs/ROADMAP.md`, last
  section): whether dragging a timeline bar should carry every step downstream of it, as Frappe
  does today; which steps backgrounds go through, now that they are their own asset type
  (2026-09-21), and whether to build the link from each background to its environment; what
  becomes of `hiring.php`; and whether MSD's File prep really waits for the colour steps.
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
- **Whether closing a ticket should set production status** — designed for but deliberately
  not built (see the tickets entry above). It becomes a small job the day the platform owns
  progress instead of importing it; until then the next Plaky import would silently undo it.
- **Notifications block the save.** `notify_assignee()` opens an SMTP connection inline, so
  creating a ticket waits on the mail server (up to the 20-second timeout) once SMTP is
  configured. STFC behaves the same way and nobody has complained, but a queue or a shorter
  timeout is the fix if it starts to bite.
- **A ticket has one owner.** `progress` learned in Phase 3 that real work has several people
  on it (`progress_crew`); the board has not. Fine while a ticket means "one person is
  responsible for this", worth revisiting if people start listing two names in the title.
- **Crew days off** (`crew_absences`) — designed in `ROADMAP.md`. Now genuinely buildable,
  since scheduled tasks carry a `crew_id`: a slot's absences are as real a scheduling
  constraint as a public holiday. Worth doing with the timeline, so a bar visibly moves.
  **Engagement dates joined this queue on 2026-08-17** — `crew_projects.start_date` /
  `end_date` exist and are reported against the plan, but nothing schedules around them.
  One phase should teach the scheduler both at once: a person is unavailable before they
  start, after they finish, and on the days they are away, and those are the same rule.
- **Whether a hire should notify anybody.** Tickets email their assignee; hiring emails
  nobody. Probably right — the conversation happens by email already, and a notification
  saying what somebody just typed is noise. Revisit if a coordinator starts doing the
  hiring and a producer wants telling.
- **Whether a candidate can be in the frame for two slots at once.** Today they are one
  row per slot, so the same person approached for Layout and for Color is two records that
  do not know about each other. Fine while hiring is per-slot; worth a thought if somebody
  starts tracking generalists.
- ~~**Which lane is which person**~~ — settled 2026-08-13 by the slot model above.
- ~~**Capturing an estimate baseline**~~ — built 2026-08-13, see above.
- **What "actual" means for the comparison.** The baseline records the estimate; the other half
  needs real effort per step. `progress.time_spent_minutes` is one running total and nearly
  empty, so the honest measure today is elapsed dates (`schedule` vs `daily_snapshots`), not
  person-days. A `time_logs` table is what makes it effort rather than duration.
- **Whether a slot should carry its own headcount fraction.** `planned_headcount` of 2.5
  currently rounds up to 3 slots. Making the third slot half-time would be more honest, but
  needs an `allocation_pct` per slot (`crew_projects` already has one for real people).
- Whether STFC's historical data is imported later, or the dashboard simply retires.

### Settled earlier
- ~~Hosting~~ — staying put for now (see above).
- ~~Platform URL~~ — `mana.davidsaga.com`, served from `public_html/mana`.
- ~~Plaky as source of truth~~ — yes for now, with the schema built so that changing our
  mind later is additive. See the two paired entries above.
