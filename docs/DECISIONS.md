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
- **Crew days off** (`crew_absences`) — designed in `ROADMAP.md`. Now genuinely buildable,
  since scheduled tasks carry a `crew_id`: a slot's absences are as real a scheduling
  constraint as a public holiday. Worth doing with the timeline, so a bar visibly moves.
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
