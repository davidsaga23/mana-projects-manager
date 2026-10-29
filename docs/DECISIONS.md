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

## 2026-09-22 — Anima wears Mana's colours, and the app is dark
**Asked by David:** *"based on this page (https://www.mana.tv/) please try to match the colors so
they match the visual identity of Mana."* Previewed as two options on a canvas first, at his ask;
he chose the dark one, the studio's own.

**The palette is not a guess.** mana.tv declares it in its stylesheet: `--black #101010`,
`--white`, `--light-sky-blue #5FB2FF`, `--spring-green #26FE89`, set in Aeonik with Space Grotesk.
Aeonik is licensed per site, so no typeface changed here; the colours did.

**Every colour became a TOKEN first.** About 210 literals in `assets/style.css` were converted to
roles in one pass (`--surface`, `--border`, `--muted`, `--on-primary`, and so on), the role decided
by the property AND by the rule's own background: a white label inside a block that fills itself
blue is "ink on primary", not a surface. `:root` then says what each role looks like. Nothing
outside that block holds a colour any more, which is what made the theme a block rather than a
rewrite, and what makes a second theme cheap.

**Settled while doing it:**
- **The ink on Mana's two accents is black.** Sky blue and spring green are bright: white text on
  them fails, black passes, and that is the pairing the site itself uses. Hence the `--on-*` tokens.
- **Danger and warning keep their own hues**, lightened for a black ground (#FF6B6B, #F0C33C).
  Mana's palette has no red or amber, and a studio with no colour for "this is late" still needs one.
- **`color-scheme: dark` is the one line that is not ours to draw.** Date pickers, the list a
  `<select>` drops down, scrollbars and autofill are the browser's; no rule reaches them. Fields also
  had no colours of their own, which was right by accident on a white page and a torch on a black one.
- **Frappe Gantt paints its own grid white** from the CDN, after this stylesheet: rows, header,
  ticks and today-highlight are all answered now, carrying `.tl-chart` to win on specificity rather
  than with `!important`. Shading drawn OVER a chart (weekends, a bar's finished part) flipped from
  dark-on-light to light-on-dark: `--shade`.
- **The workflow diagram keeps literal colours**, in the dark values, because it is downloaded and
  dropped into decks: a stylesheet does not travel with an SVG. Its comment says so.
- **On paper the palette turns back over.** `@media print` redefines every token light, with the
  darkened accents that pass 4.5:1 on white: a printer given a black page wastes a cartridge and
  prints white letters. The PNG and PDF buttons are not this, they photograph the screen, and a dark
  export is the studio's own look to send.
- Step, status and crew colours are still each show's data, untouched. Only the app around them moved.

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

## 2026-10-07 — A card can exist before anybody says which show it is for

David, reading the first real transcript: *"we should be able to add them without a project so we
can organize them later."*

He is right, and the transcript is the proof. A production meeting covers three shows and a dozen
things that belong to none of them yet. Forcing a project at the moment of capture means guessing,
and **a card filed against the wrong show is harder to find than one filed against nothing** — the
wrong one looks handled.

So `tickets.project_id` is nullable (migration 041).

### ⚠ THE ROADMAP RECOMMENDED A PSEUDO-PROJECT AND THAT RECOMMENDATION WAS OVERTURNED

An "Inbox" project would have given these cards statuses, a board, a sequence and a place in the
switcher for free. The argument was good: the thing a general board needs most is to look and behave
exactly like a board, and that is what a project already is.

What overturned it was not a better argument but **noticing the requirement was different**. David
did not ask for a board without a project. He asked to capture work before deciding where it goes,
and to see everything open across every show in one list. Studio Tasks is a read-only list with
filters — it has no columns, so it needs no statuses, so option 1's entire advantage bought nothing
while its cost stayed: an inbox appearing as a show with no delivery date and 0% progress on the
projects page, the dashboard, finance, the switcher and in bookings, forever.

**The lesson is about the shape of the question.** "A board with no project" and "a card with no
project" sound like the same feature and are not. One needs a status vocabulary invented for it; the
other needs a column to accept NULL.

### What a NULL project means, said once so it is not rediscovered

* **No board.** `tasks.php` filters on `project_id`, so an unfiled card is on no board at all.
  Studio Tasks is the only screen that shows it — which is the whole reason Studio Tasks exists.
* **No status.** Columns are a project's own statuses, so there is no column to sit in. `status_id`
  stays NULL, which the ticket page already tolerated.
* **No reference.** `seq` is a per-project counter; unfiled cards carry 0, and `ticket_ref()` already
  fell back to `#id`. `uniq_project_seq` permits many `(NULL, 0)` rows because MySQL does not compare
  NULLs as equal.
* **No shot, asset or step, and no time.** All belong to a show. The first three are *hidden* on the
  ticket page rather than drawn empty; time logging is *refused*, because hours costed to no show are
  hours quietly missing from every finance total.

### ⚠ THE EXPENSIVE PART WAS THE INNER JOINS, AND NONE OF THEM WOULD HAVE ANNOUNCED ITSELF

Six queries joined `projects` on a ticket's `project_id`. Left alone, every one would have made an
unfiled card *invisible or denied* rather than visibly broken:

* `ticket_select_sql()` — the shared query. An inner join would have hidden unfiled cards from the
  board, Studio Tasks **and** My tasks at once. Silently.
* `move_ticket_to_project()` — would have answered *"that card no longer exists"* for the one card
  the function exists to file.
* The mention mailer — would have returned *"that ticket no longer exists, so nobody was emailed"*
  for a card that does exist.
* My tasks' status save — would have told the owner of an unfiled card it *"is not assigned to you"*.

Two were left inner on purpose: `item.php` is scoped to a shot or asset (an unfiled card has
neither), and `person.php` filters `project_id = ?`, which is never NULL.

This is **Lesson 11 in a new place**: a screen that quietly renders empty is the failure this codebase
keeps paying for, and a nullable foreign key is a machine for producing them.

### The foreign key changed direction, which is a real behaviour change

It was `ON DELETE CASCADE`: deleting a project took its cards, which was right when a card could not
exist without one. It is now `ON DELETE SET NULL` — deleting a show leaves its cards on Studio Tasks.
The safer direction: a card nobody wants is one click to delete, and a card destroyed by a cascade
nobody saw is simply gone.

### Where it shows up

The transcript reader creates unfiled rows instead of refusing them, and its report names the split
(*"3 cards created (1 on FCM, 1 with no project yet)"*) plus where to find the unfiled ones. Studio
Tasks prints **"no project"** in words rather than leaving the cell blank — a blank cell reads as a
bug, and the Project filter is built from the text in the cells, so an empty one could not be picked.
Filing one is a click from the card itself, which takes the next number in its new home, maps the
status by name, and starts a previously statusless card in the target's first column.

---

## 2026-10-07 — A call transcript, read by Claude, into cards
**Asked by David:** *"we need to process the transcript through an API for Anthropic so you can
summarize the task and ideally assign a potential user based on the transcript, when you have any
questions leave the assignee empty and I'll assign everything."* — choosing the second of the two
options the roadmap had carried since 2026-10-05.

### ⚠ The first thing here that talks to anything outside itself
Everything else reads this database, or the finance one, or a file somebody uploaded. Three kinds of
failure arrive with this and none existed before: **it costs money per use**, **it is slow** (tens of
seconds, and that is a page load sitting there), and **it can be down, rate-limited or time out**.
Each is reported with what actually happened — never "no tasks found", which is what a failure and an
empty transcript would otherwise look like. Lesson 11 in a new place.

**The key is a line somebody adds to `config.php` on purpose.** Without it the screen says so, the
button does not appear on the board, and nothing else is affected. That a transcript LEAVES THE
BUILDING is a fact about the studio rather than about the code, so it is opt-in by construction.

### ⚠ The reply is data, never instructions
A transcript is a file somebody uploaded; a model's reading of it is a guess about that file. Both
are untrusted. Nothing that comes back is executed or written without a person seeing it, and **every
field is validated**: the title must be non-empty and is cut to the column width, the priority and
type must be one of the known values or they fall to the default, the count is capped at 40, and
**the owner is resolved to a crew id by exact name against this project's own people**. A name that
does not match becomes nobody. The system prompt also tells it that instructions inside the
transcript are a recording of a meeting, not a request to it.

Checked rather than assumed: a reply naming somebody who is not on the project, an invented priority,
an invented type, a missing title, an element that is not an object at all, sixty items, a 400-
character title, and a transcript trying to talk the model into assigning everything — all ten land
where they should.

### ⚠ An empty owner is the right answer, not a failure
David's own instruction, and it is worth more than a good guess: **a wrong owner is work sitting on
the wrong person's list looking assigned; an empty one is visibly waiting for him.** So the prompt
says to leave it empty whenever there is any doubt, the validator only accepts an exact match against
the project's crew, and "nobody yet" is the first option and the default in the review table.

### Three screens, and nothing is written until the third
Upload, read what it found, create. The same shape as the Plaky importer, for a harder version of the
same reason: these cards were written by a model from a recording of people talking. **The middle
screen is not a formality** — titles are editable, owners are a dropdown, and anything wrong is
unticked rather than fixed afterwards on five separate cards.

Cards land in the board's **first column**, on the project the screen was opened from. A card on the
wrong show is moved from the card itself, which was built the same day and is what makes processing a
transcript anywhere workable.

**Sonnet rather than Opus:** the work is extraction rather than reasoning, and it is markedly cheaper
and faster on a transcript that may run to thousands of words.

### ⚠ It belongs on the cross-project screen, and that was the point all along (same day)
Shipped with the button on a project's Tasks board only. David went looking for it on the
cross-project **Tasks** screen and could not find it — and he was right to look there: *"we need a
menu on top that says Tasks ... so we can drop the transcript in that general task"* is what he had
asked for in the first place. **A meeting is rarely about one show**, so the natural place to drop
its transcript is the screen that spans them.

So a project is now **optional**, and **which show the cards are filed against is chosen on the
review screen, after reading** — because which show each action item belongs to is usually only clear
once the list is in front of you. Opened from a project's board, that project is simply preselected.

Two things follow from having no project while reading:
- **The names offered to the model are studio-wide** rather than one show's crew. A meeting about
  three shows mentions people from all of them, and a name that does not match becomes nobody either
  way.
- **The first board column is worked out per project at the moment of writing**, not once for the
  page. Columns are a project's own statuses, so a card filed against MSD must land in an MSD column.
  The posted project id is checked against the real list before anything is written.

**Not run in PHP** — there is none on this machine, and no call was made to Anthropic from here. All
seven checks pass; the validation was replayed in Python over the ten cases above.

## 2026-10-07 — A Tasks screen across every show, and Projects leaves the top bar
**Asked by David:** *"I still want the general task menu so we can see all the open tasks with and
without project to see the progress across multiple projects."*

**⚠ THAT SENTENCE CHANGED THE BUILD.** The earlier reading of "a general Tasks area" was a place
cards would LIVE before being sorted into shows — which needs a board with no project, which needs
statuses with no project, which is a schema argument (ROADMAP). What was actually wanted is to **see
across** the shows. That needs nothing new: the cards already exist, each on its own project, and
`alltasks.php` reads them together.

**No migration, nothing stored.** A card on this page is the same row the project's own board draws,
because `ticket_select_sql()` is the one place either of them asks.

**It carries no controls, deliberately.** A status picker here would be a second way to do a thing
the board already does, and the board is where the columns mean something. The page reads, and says
so at the bottom.

**"Open" is a property of the status, not a guess from its name** — the same `maps_to = 'approved'`
test the dashboard and My tasks use, so a card is open here exactly when it is open there. Matching
on the word "done" would work until the first show that says "Final". Finished work is one click
away rather than hidden, because "what did we get through" is a fair question too.

**Project, Type and Status are dropdowns; the rest are boxes.** Each is a short closed list, and
picking "MSD" is the question the page exists to answer. Built from the values actually present, so a
show with nothing open is not offered as a filter that finds nothing.

**⚠ "Without project" shows nothing, and the page says why rather than drawing an empty bucket.**
Every ticket has a project — `tickets.project_id` is NOT NULL since migration 017. What makes the
sorting workflow possible is **moving** a card between shows, built the same day.

### Projects left the top bar
David, same day: *"we can delete the projects menu next to my tasks, we can always go back with home
or changing projects."* He is right, and it was checked before removing: **the brand at the top left
has always been the link to the project list**, on every screen, and a project's breadcrumb says
"Projects /" as well. Three ways to one list, one of them a word in a row of four. **Tasks** takes
its place, beside My tasks and not inside a project, for the reason Artists and Bookings are there:
a thing about ALL the work does not belong behind a choice of one show.

**Not run in PHP** — there is none on this machine. The table was driven in a browser with the real
`style.css` and `gridtools.js`, running the page's own script: Project narrowing six cards to the
three on MSD, Type to the two tickets, priority sorting by urgency rather than the alphabet, dates
chronological, the Anima Dev chip surviving, and no sideways scroll.

## 2026-10-06 (later) — Both halves of the notification: a list, and an email on request
**Asked by David**, when told a mention notified nobody: *"both"* — the list for everyone, email only
when asked.

Migration **040** adds `ticket_mentions.seen_at`.

### The list
**Mentioned** sits above the two work lists on My tasks, unread first, with a count in the strip at
the top. It is the only thing on that page somebody else put there — the rest is work already
assigned — so it goes first, and it is drawn only when there is something in it. A permanent
"0 mentions" heading is furniture.

**⚠ OPENING THE TICKET IS WHAT MARKS A MENTION READ**, not seeing it on the list. A count on My
tasks is a pointer; clearing it because somebody glanced at the screen would destroy the one signal
telling them where to go. It is stamped when they open the ticket it is on, which is the first moment
they could actually have read it.

That write happens on a **GET**, deliberately, and is the second thing here to do so — the daily
snapshot is the first (2026-08-13). Both are the same shape: a fact about somebody having looked,
with no form to submit.

### The email, on a tick
**Unticked by default, and the label says what ticking costs.** The mailer opens SMTP inline, so the
page waits on the mail server before it returns (up to the 20-second timeout). Sending on every
mention would make every comment with an `@` in it slow, including the ones that are a note for
later. The tick is the author saying *this one is worth the wait*; everyone named sees it on My tasks
either way.

**⚠ IT NAMES WHO IT COULD NOT REACH.** Somebody with no email address on file, a team, an unconfigured
SMTP — each gets its own sentence rather than a silence. A mention that quietly goes nowhere is worse
than none at all, because the author has already moved on believing it landed. That is the same rule
`notify_assignee()` follows for the same reason.

**Still outstanding, and now affecting one more path:** the mailer blocks the save. The roadmap
already carries it; this adds a third caller. Moving it off the save path would fix ticket creation,
status changes and now mentions at once.

**Not run in PHP** — there is none on this machine. The message the author sees was replayed in
Python over six cases: no mention, a mention left unticked, a tick with an address, a tick with no
address on file, one reachable and one not, and a tick with SMTP unconfigured. Each says what
happened rather than nothing.

## 2026-10-06 — Naming somebody in a comment, and replying to one
**Asked by David:** *"can we also have the option to tag people in the ticket/task detail view and
create threads if needed?"* — with a screenshot of the comment box containing a lone `@`, which is
the clearest possible statement of what the control should be.

Migration **039**: `ticket_comments.parent_id`, and a `ticket_mentions` table.

### Threads, one level deep
A reply's parent is always a **top-level** comment; replying to a reply attaches to the same thread
rather than starting a third tier. The column could express deeper nesting and the code deliberately
does not: a ticket is a conversation about one problem, not a forum, and past the first indent the
indent stops carrying meaning in a column this narrow. A reply whose parent has been deleted is shown
at the top level rather than dropped — losing somebody's words to a missing parent is worse than an
odd position.

**One box, not one per comment.** Reply sets a hidden field on the single comment form and says who
it is answering, so there is one draft, one set of attachment controls, and one place to look.

### ⚠ The text is not the record
"@Ana Ruiz" in a comment is a string; a person is a crew id. `ticket_mentions` stores the id **and
the name as written** — the id is who it means, the name is what it said, and a later rename must not
rewrite history. Parsing the body at read time would re-resolve names on every page load and quietly
lose the link the day somebody is renamed, which is the alias problem this platform exists to avoid
(DECISIONS, 2026-08-11: ids, not names). It also makes "what was I mentioned in?" a query rather than
a scan of every comment on every show.

**⚠ Matched against the known list, longest name first, never by a pattern.** A name is not a word:
`@\w+` would read "@Ana Ruiz" as a mention of Ana and leave "Ruiz" lying in the text. Longest-first
also stops "@Ana Ruiz" being taken for "@Ana" when both people exist. The picker offers exactly the
list the parser matches against, so a name it suggests can always be resolved — a picker offering
somebody the parser cannot match would be a quiet lie.

**⚠ Escaped first, marked second.** The whole body is escaped once and the highlight is applied to
already-safe text with an already-safe name. Building the markup into the raw body and escaping after
would escape our own tags; escaping per fragment is how an XSS gets in.

### ⚠ Nothing is emailed, and the screen says so
A mention notifies nobody. That is not an oversight — `notify_assignee()` opens SMTP **inline**, so
posting a comment would wait on the mail server (DECISIONS: up to the 20-second timeout) before the
page came back. Making every comment slow to deliver a notification nobody asked for yet is the wrong
trade.

So the flash after posting **names who was tagged and says they were not told**. Somebody who types
@Ana and sees nothing will reasonably assume Ana has been told; this is the one case where saying
less would be a lie. The honest next step is mentions appearing on **My tasks**, which is the screen
an artist already opens — no mail server, no delay, and `ticket_mentions` is already the query.

**Not run in PHP** — there is none on this machine. The parser was replayed in Python over nine cases
(a bare name, a name with a space, mixed case, two mentions in one comment, a long name and a short
one that is a prefix of it, an email address, an unknown name) and the threading over a reply to a
reply and an orphan. The picker and the thread were then driven in a browser with the real
`mentions.js` and stylesheet: typing `@` opens it, a name with a space stays reachable after the
space is typed, picking inserts the full name and closes, a second mention in the same comment works,
`me@example.com` opens nothing, Reply targets the right comment and cancels cleanly, and neither a
system line nor a reply offers a Reply button.

## 2026-10-05 — Sortable headings on My tasks, and a date bug in the shared sorter
**Asked by David:** *"sort by the header in this section"* — the Tickets list on My tasks.

`gridtools.js` already did this for Artists and Shots, so it was a call rather than a build. What it
needed was a **`sortOnly`** mode: sortable headings and nothing else, no filter boxes, no count row,
no Clear. Those two lists are one person's own work, short enough to read whole, and the heading was
the whole request. Both tables on the screen get it, since they are the same shape.

**Priority sorts by urgency, not the alphabet.** Left to the default it read High, Low, Medium,
Urgent. The rank comes from `ticket_priorities()`' own order, read off the dot's class.

### ⚠ The bug it uncovered: dates never sorted, anywhere
The default sort key strips everything that is not a digit, a dot or a minus before reading a number
— so `2026-10-05` became the **number 2026**. Every date in a column tied, the sort was stable, and
the rows did not move at all. It looked like it worked on My tasks only because the list already
arrives ordered by date.

This was in the shared module, so it was true of **every date column on every grid** that has used it
since 2026-09-29. ISO dates sort correctly as plain text — which is the whole reason the platform
stores them that way — so they are now matched and returned as strings before the numeric branch.

Worth remembering as a shape: a sorter that "works" on a column that was already in order is
indistinguishable from one that does nothing.

**Not run in PHP** — there is none on this machine, and this touches almost none. Driven in a browser
against a copy of the Tickets table with the real `gridtools.js`: every heading sortable except Log
time, no filters or count row drawn, priority in urgency order both ways, and dates in true
chronological order both ways — the case that was broken.

## 2026-10-05 — "Projected cost" becomes "Current forecast cost"
**Asked by David:** *"In Finance Management → Overview, rename Projected Cost to Current Forecast
Cost. This label more clearly communicates that the number is the latest expected project cost based
on current staffing, rates, workload, and changes."*

He is right about what it is. The figure is **recomputed on every page load** from the current
pipeline, the lanes staffed on it and each person's rate — it is never stored, precisely so it cannot
go stale when a headcount changes. "Projected" reads like something agreed once at kick-off, which is
the one thing it is not.

**Renamed on the hub as well as the Overview**, which is one step past what was asked and deliberate:
`finance.php` shows the *same figure* as a column for every project. Renaming only the Overview would
have given one number two names on adjacent screens, at exactly the moment somebody is comparing
them. Say so and revert the hub if that is wrong.

**Sentence case, not Title Case.** David wrote it capitalised, but every other label on that strip is
sentence case — Budget, Margin, Invoiced, Unpaid — and one capitalised label would read as a proper
noun.

**Two places deliberately NOT renamed:**
- **`export_projection.php`** — the column heading in the exported sheet. That file is matched
  against `RTC_ExpenseProjection_v01.xlsx` and may be read by something outside this platform;
  renaming a spreadsheet column has consequences that do not stop at our own screens. A separate
  decision.
- **`person.php`** — "Projected cost on this project" is a different figure at a different scope: one
  person, not the show. "Forecast" there would promise more than the number knows.

## 2026-10-05 — The contract state and its link, on the staffing row
**Asked by David:** *"in the staffing section please add the contract status in line and the contract
link as a hyperlink (no editable in this context) for convenience. The contract link could be
truncated instead of the whole string."*

A filled slot now carries a line under the name: a state chip — **signed, sent, draft, declined, no
contract** — and the contract itself as a link. **Read only, as asked.** Changing a contract stays on
the Hiring screen, which has the dates, the history and the rate beside it; a state picker here would
be a fourth control on a row whose other three are staffing decisions.

**Signed is the only quiet colour.** It is the one state nobody has to do anything about. Sent is
amber and carries **how many days it has been out** — free, because `contract_days_waiting()` already
works it out, and it is the number that actually chases a contract.

**⚠ ADMINISTRATORS ONLY, and absent rather than greyed.** `crew_terms` lives in the finance database,
and `crew_terms()` returns an empty array for anybody who cannot see money — so an editor's page has
no contract line at all, the same way the day rate is simply missing from the artist grid rather than
shown disabled.

**⚠ ONE CALL FOR THE WHOLE PAGE.** The terms are fetched once for every filled slot's crew id before
the table is drawn. Thirty-seven slots must not be thirty-seven connections to a second database for
one line of text each — the same reasoning as `$laneWork` beside it.

**⚠ TRUNCATED IN CSS, NOT IN PHP**, which is the rule the artist grid's reel column already follows
and the reason it is written there too: the whole address stays in the page, selectable and copyable,
and the `title` carries it in full. Cutting the string server-side would hand somebody half a URL
they cannot paste anywhere. Checked: the full address is in the DOM, the ellipsis is the browser's.

### ⚠ Shipped silent, and that was the bug (same day)
David: *"I still don't see the contract status in this view."* It was deployed. The line only drew
when `crew_terms` held a row for that person — so everybody who had never had a rate or a contract
entered, **which on a freshly staffed show is everybody**, looked exactly like a feature that had not
shipped.

Worse, three different situations produced the same blank row: nobody has started the contract, the
`contract_status` column does not exist because `finance_install_04.sql` was never imported, or the
finance database is unreachable. **That is lesson 11**, and `hiring.php` already carries a comment
saying precisely this three hundred lines away: *"'no contract on file' and 'the finance database is
unreachable' look identical otherwise"*. Written down, and still rebuilt.

Two changes:
- **A filled slot always shows a state**, defaulting to **no contract** when there is no record at
  all. "Nobody has started this one" is the most actionable thing the line can say, so it is the one
  state it must not stay silent about.
- **The page says why when it cannot show state at all** — finance not configured, `04` not
  imported, or an error, each with its own sentence. When only the state is unavailable the **link**
  still shows, because `contract_url` lives on `crew_terms` whether or not `04` has run.

**Not run in PHP** — there is none on this machine. The row was drawn in a browser with the real
stylesheet across all five contract states plus a slot with no terms and a placeholder: the chips
take their colours from the theme's own tokens, the link truncates while keeping its full text and
title, a placeholder gets no line at all, and the page does not scroll sideways. The condition was
then replayed in Python over seven cases — including the one that was broken, a filled slot with no
terms row, and the two where only the link can be shown.

## 2026-10-05 — The import stops asking what unit time is in
**Asked by David:** *"let's delete the time spent part of the import, every single project in plaky
will be in hours from now on."*

The upload screen used to open with a pair of radio buttons — minutes or hours — to be answered on
every import, with a warning to **check this every time**. That was right while Plaky boards
disagreed with each other. Now they all log in hours, and **a question with one correct answer is a
question somebody eventually gets wrong**: it defaulted to minutes, so the single most likely slip
was leaving it alone.

**⚠ THE RISK DID NOT GO AWAY, IT WAS DECIDED.** Reading hours as minutes is a 60× error landing
straight in the figures used to check invoices — the reason the question existed at all. So the
assumption is still **stated**, on the upload screen and again on the mapping screen, rather than
buried in `plaky_run()`. If a board ever logs in minutes again, `960` would import as 960 hours, and
that sentence is the only thing on screen that would have warned anybody.

**The default flipped with it.** `plaky_run()` fell back to `'minutes'` when the mapping carried no
unit; it now falls back to `'hours'`. Which way round a missing value falls matters here: the old
fallback would quietly divide every imported figure by sixty rather than merely being unhelpful.

**`plaky_time_to_minutes()` keeps its `minutes` branch** even though no screen can now reach it. The
platform stores minutes whatever the file says (DECISIONS, 2026-08-12), so this function is where the
two meet; the cost of keeping the branch is one comparison, and the cost of needing it back is
rediscovering why every figure is sixty times too small.

An import already part-way through when this deploys keeps the answer it was given — the session
value still wins, and only a session with no answer at all gets today's rule.

**Not run in PHP** — there is none on this machine. The unit resolution was replayed in Python for a
mapping with the key set either way, absent, and holding nonsense; and the conversion over 16, 1.5,
0.25, 8, 960, blank, zero and a non-number. The 60× trap was checked explicitly in both directions
on the same input: `16` is now 960 minutes, where the old default made it 16.

## 2026-10-03 — Switching project keeps you on the same screen
**Asked by David:** *"I'd like to add a button at the top of the page so I can switch between
projects. Let's say I'm navigating through project expenses and I want to see how they're setup in
another project. Currently I have to go to home, click the project I want and then navigate till I
get there."*

**The name you are already reading IS the control.** A separate "switch project" button beside it
would be a second thing meaning the same thing. The project name in the breadcrumb line is now a
picker, drawn as the heading it replaced rather than as a form field — no box until you point at it,
because a permanent bordered dropdown in a title line reads as something to fill in rather than as
where you are. With one project it stays plain text; a dropdown with one entry is furniture.

It lives in `project_section_nav()`, which **both** navs already call, so the production side and the
money side got it from one change — and the money side was the half that was asked for.

### ⚠ Built from the TAB, not from the current address
The obvious implementation — take the URL in the address bar and swap the project number — is wrong
on four screens. `step.php`, `ticket.php`, `person.php` and `item.php` all draw this bar while their
`id` holds a step, a ticket, a person or an item. None of those exist on the project being switched
to, so the swap would land on somebody else's record or on nothing.

`project_switch_href()` asks which **tab** is lit and looks that tab's href up for the other project.
Every screen then does the right thing without a list of special cases: the shots list goes to the
shots list, the expense projection to the expense projection, and one ticket goes to the other show's
**board** rather than to a ticket id belonging to a different project. Those four screens already
pass a tab key to `project_nav()` — `step.php` says 'dashboard', `ticket.php` says 'tasks' — so the
answer was already in the code and only needed asking for.

It also inherits the artist rules for nothing: `project_nav_items()` already drops the tabs an artist
may not see, so a tab they do not have cannot be reached by switching project while standing on it —
it falls back to that project's front door.

**Archived shows are listed deliberately.** Looking up how a finished one was set up is a reason to
switch, and it is close to the reason David gave.

**Not run in PHP** — there is none on this machine. `project_switch_href()` was replayed in Python
against the real tab tables for both sections, the four id-holding screens, and an artist whose tab
is hidden on the far side; the bar was then drawn in a browser with the real stylesheet, on the
production and finance variants and the single-project case.

## 2026-10-02 — An artist can be deleted, person-wide, from the book
**Asked by David:** *"we also need to be able to delete a record."* Raised the day before as a gap:
the only delete that existed was per-candidate on a project's Staffing screen, which could not reach
anybody imported from the freelancer spreadsheet at all — they belong to no project, so they appear
on no Staffing screen. That was most of the database.

**PERSON-WIDE, BECAUSE THIS SCREEN IS.** `hires` holds one row per APPROACH, so somebody put up for
three jobs is three rows; the Artists screen merges them into one line and `artists_save_person()`
writes an edit across all of them. Deleting one row would leave a person who still appears, now
missing part of their history. `artists_delete_person()` matches rows **the same way the edit does**,
deliberately, so the two can never disagree about who "this person" is.

**Two calls, and the first writes nothing.** It returns the server's own count, the shows involved,
and any refusal; THAT count is what goes in the question. Asking "remove 3 records?" from a number
the page had guessed would be a confirmation of the wrong thing. The refusals arrive before anybody
is asked to agree to anything.

**Refused for anyone hired**, naming the show. That record is how the crew member on a slot got
there, and removing it would leave somebody on a show with no explanation of how — the same rule, and
the same reason, as the per-candidate Remove on `hiring.php`. Passed keeps the history instead.

**The rate goes first, and outside the transaction.** It lives in the other database, keyed to a hire
id with no foreign key, so there is no one transaction covering both. That order is the recoverable
one: a failure between the two leaves the person intact with their rate gone. The other way round
leaves a rate attached to an id that no longer exists and nothing to find it by. While finance is
configured this makes the whole action **administrator-only** — an editor who cannot see the other
half must not delete the half they can — and says so rather than half-doing it.

`hire_stage_log` is `ON DELETE CASCADE`, so a person's stage history goes with them rather than
being left pointing at nothing.

**The button is quiet until pointed at.** A row of red crosses down a list of three hundred people
reads as a page full of errors, and this is the one control on the screen that cannot be undone.

### What it uncovered
`gridtools.js` captures its rows once at attach, so after a delete the count still said eleven of a
list of ten and a filter still measured a node nobody could see. It now drops rows the page has
removed. Worth knowing generally: anything that equips a table and remembers its rows has to cope
with the page changing them.

**Not run in PHP** — there is none on this machine. Driven in a browser against a copy of the grid
with the real `artists.js` and a stubbed endpoint: declining the question sends the dry run and
nothing else and removes nobody; accepting sends exactly two calls, the second carrying `confirm=1`,
and the row and the count both follow; a hired person is refused with the reason on screen and their
row still there; and the column filters agree with what is left ("3 of 10", not "3 of 11").

## 2026-10-02 — "Worked with us" was missing from the add grid, so nobody ever arrived with it
**Asked by David:** *"in the artist add section we need to see the worked with them before check
box."*

**The field was never the problem; the form was.** `worked_with_us` is in `ARTIST_FIELDS`,
`artists_values()` has always read it, and the editable grid above has carried the column since
migration 025. Only the five blank rows of **Add artists** left it out — so every person added there
was written with `worked_with_us = 0`, whoever they were, and the only way to correct it was to find
them again in the grid above and tick it. Silent, and wrong in the direction that loses information.

Added last, as it is in the grid above, so the two tables read the same way, and gated on the same
`$has('worked_with_us')` as everything else, so the screen still degrades one feature at a time on a
database without migration 025.

**One thing it uncovered.** The rule keeping a checkbox its own size was written
`.artist-grid input[type=checkbox]`. The editable grid carries BOTH classes (`artist-table
artist-grid`); the add table carries only the first — so the new box was stretched to the full column
width by the global `input { width: 100% }`. The selector is now `.artist-table`, which covers both.
Worth noting as a shape: a rule scoped to the more specific of two class names on the same element
works until the day something else wears only the general one.

**Not run in PHP** — there is none on this machine. The header and body conditionals were replayed in
Python across **all 128 combinations** of the seven optional columns and agree in every one; the
table was then drawn in a browser with the real stylesheet — thirteen headers, thirteen cells, Worked
last, the checkbox 13px rather than a stretched field, and no sideways scroll on the page.

## 2026-10-02 — Progress is drawn as a bar inside the bar
**Asked by David:** *"in the timeline whenever there's progress registered, add an overlay color or
an inside bar that shows the progress."*

**It was already drawn, and that was the problem.** Frappe's own `.bar-progress` rect was tinted at
32% over the FULL height of the bar, so it read as a slightly different shade of the same block — and
at 100% it tinted the whole bar, which looked exactly like a step with nothing recorded at all. The
one state most worth announcing was the one that disappeared.

`paintProgress()` in `timeline.js` re-lays that rect as an **inset meter**: shorter than its bar top
and bottom, held off the left and right edges, with rounded ends. The step's own colour frames it at
every percentage, 100 included, and the figure is written just past the end of the bar. The inset is
a share of the bar's HEIGHT rather than a fixed number of pixels, so it holds its proportions at
every zoom.

**At 0% nothing is drawn.** Most bars on most shows have no progress recorded, and a meter on every
one of them would be noise; the chart only changes where there is something to say.

**The step's label is moved back on top.** Frappe draws it before the progress rect, and SVG has no
`z-index` — painting order IS document order — so an opaque meter covered the name of the step it
belonged to, worst at 100%. The label is re-appended last and outlined in the background colour so it
survives both the bar and the lighter part inside it.

**⚠ GENERAL CHART ONLY.** On the detailed chart `progress` means something else entirely — 100 for an
approved task, 0 otherwise — and `.tl-done .bar-progress` already paints that green. Two meanings,
one class name: the caller says which chart it is rather than the function guessing. Checked: the
detailed chart's rects keep their full height and gain no figure.

### One thing the harness caught
An old version is drawn on a panel the code calls "paper", so the first fix flipped `--shade` dark
there, reasoning that paper is cream. **It is not** — `--paper` is warm DARK on the dark theme
(`#1b1813`) and only turns cream in `@media print`, where `--shade` already flips with every other
token. The override made the meter invisible on the one screen it was meant to fix. Reverted; what
stayed is `--bg: var(--paper-surface)` on that panel, so the label's outline blends into the paper
behind it rather than into the page.

**Not run in PHP** — there is none on this machine, and this touches none. Driven in a browser
against the timeline harness with the real `timeline.js` and `style.css`, over bars at 0, 7, 25, 60
and 100%: the inner widths measure to the percentage they claim, 0% draws nothing, the figures
appear only where there is one, the label paints last, a zoom redraw rebuilds all of it identically,
the detailed chart is untouched, and a saved version on the paper panel shows its meter too.

## 2026-10-01 — The breakdown's filters become dropdowns, and the count moves into the table
**Asked by David:** *"instead of a text box, let's create a dropdown menu with all the options
available (the same way all the options of that column are presented when linking them per shot).
Additionally, I didn't see the total count of shots that complied with the selected filter... below
the headers I want to see the total shot count."*

### Everything that COULD be there, not only what already is
An asset column's filter now lists **every asset of that type on the project**, from `window.BD_ASSETS`
— the same array, in the same order, that the per-shot picker offers. The two agree by construction
rather than by two people remembering to keep them the same. A character who is in the project but
not yet in any shot is still offered, and picking them correctly finds nothing, which is itself an
answer.

**A chip cell is a LIST, and is matched item by item.** A shot's Prop cell reads "Boots, SoccerBall";
picking SoccerBall has to find it, and must not also match a prop called "Ball" for being inside its
name. `cellList()` hands gridtools the chips and the comparison is against each one, whole.

**"(nothing)" is offered wherever a column has an empty cell to find** — which shots have no
character, which artists have no reel. Worth having: it is a question the old text box could not ask
at all.

**Ep, Shot, Frames and Notes keep a box, deliberately.** A dropdown of forty-five shot codes is
slower than typing three characters, and you cannot type half a dropdown to see the nine shots
beginning SH0. Ep would get one if the project had episodes; on a flat show there is nothing to
offer, so it stays a box.

### The count moved into the table
It was a line **above** the table, which scrolls away with the first few rows — exactly when a list
of forty-five makes the number worth having. It is now a second row in `<thead>`, sticky, sitting
under the filters that produced it, with **Clear column filters** beside it.

Its `top` is **measured** from the heading row and re-measured on resize, because the head's height
changes with the font, the window width and whether a heading wraps. That measurement is the reason
the filters themselves were put inside the one `<th>` rather than in a second row; a count row earns
it because it has nothing to line up with horizontally. Set on the **cell**, not the row: `sticky`
applies to the `<td>` under `border-collapse`, and a `top` on the `<tr>` is ignored.

**Three numbers, three jobs**, so none of them is one you have to work out which set it belongs to:
- the line **above** the table — the whole episode, as PHP printed it, now left alone so there is
  always a total to compare against;
- the row **under the headings** — what is on screen now, with its frames and seconds;
- the **Total** row at the foot — the same, beside the column it sums.

**Not run in PHP** — there is none on this machine, and this touches none. Driven in a browser
against static copies of both grids: Prop filtered to **SoccerBall gives "2 of 45 shots · 95 frames
· 3.959s"** with the foot agreeing and the line above unchanged; "(nothing)" finds the 43 shots with
no prop; a character who is in no shot gives 0 and reddens; the count row is sticky at the measured
offset on both grids; and the artist grid was re-run whole.

## 2026-09-28 — A new candidate starts at Lead, not Approached
**Asked by David:** *"in the staffing section let's make sure the default option is lead instead of
approached."*

**Lead, because adding somebody to a slot is a note that they might do it — not a claim that anyone
has written to them yet.** Starting at Approached made the board say a conversation had happened the
moment a name was typed, and the one number a producer reads off this screen is how many people are
actually waiting on a reply.

**The schema already agreed.** `hires.stage` has defaulted to `'lead'` since migration 024, and both
fallbacks in `includes/hiring.php` chose it too. Only the two ADD paths overrode it, and they did it
twice, in two files: `add_known_candidate()` for somebody picked out of the artist database, and the
typed-name branch of `addcand` in `crew.php`. So the answer depended on which door was used, and
neither door was the schema's.

Now `HIRE_STAGE_NEW` holds it once and all four read it — the two ways in and the two fallbacks.

**⚠ "Line up again" is deliberately NOT changed.** It has its own button, its own message
("...again, at Approached") and its own decision from 2026-09-18: somebody talked to before goes back
onto the same record rather than getting a second, empty copy. That is a named transition, not a
default, and moving it is a separate question — raised with David rather than changed quietly.

**Existing rows keep the stage they have.** A default decides where new records start; it does not
rewrite old ones, and nothing here does. The candidates already sitting at Approached stay there
until somebody moves them, which is a data decision.

**Not run in PHP** — there is none on this machine. All seven checks pass, and the only remaining
write of `'approached'` in the codebase is the "Line up again" branch above.

## 2026-09-30 — The same column tools on the shot breakdown, and one copy of them
**Asked by David:** *"do the same for the shot tab."*

**Moved to `assets/gridtools.js` rather than copied.** A second implementation is where the two start
disagreeing about what "empty sorts last" means, and this codebase has paid for that shape before
(one `effective_days_per_unit()`, one `step_applicable_count()`, one `fmt_secs()`). `artists.js` lost
its copy and calls the module; `shots.js` calls it too and passes only what a breakdown does
differently. Both pages load `gridtools.js` first, because both call into it as they load.

Nothing about the columns is declared. The module reads what each one actually holds: a column of
`<select>`s or checkboxes becomes a dropdown of the values in use, everything else a box to type in.

### What a breakdown needed that an artist list did not
**An asset cell is chips, not a field.** Read with the default reader it comes back
`"Mbappe × Bellingham × +"` — the remove buttons and the picker's `+` included. `shots.js` passes a
reader that collects the chip names, so filtering **Character** for `wonderkid` finds the shots that
have Wonderkid in them, and sorting that column orders by the list of names with empty cells last.

**⚠ THE TOTALS HAD TO FOLLOW THE FILTER.** The line above the table and the Total row under it are
printed by PHP for the whole episode. Leaving them alone while five of forty-five shots are on screen
is a number that is wrong in the most expensive direction, on the screen the expense projection
prices. They now recompute from what is VISIBLE — **by adding up the figures PHP already put in the
rows**, never by re-deriving them, so there is no second copy of frames-to-seconds to drift. With no
filter on, the server's own text is put back character for character, which is checked.

The running time (`0:49.8`) is deliberately **not** recomputed: it is formatted by `fmt_secs()` in
PHP, and a second formatter here is exactly the copy this entry is about. Seconds are shown instead.

**A picker open over a row the filter has just hidden** would float there pointing at nothing, so the
filter pass closes it. That needed the function handed out through a `var`: `function closePicker()`
is a DECLARATION, and declarations are block-scoped in strict mode, so it is not visible outside the
editing half of the file.

**The edit gate moved down `shots.js`.** It used to return early for anyone who could not edit, which
was right while everything in the file WROTE something. Filtering and sorting read — an artist, who
cannot change a breakdown, still has forty-five rows to find something in. The editing half is now
wrapped in that test and the column tools are not.

**Not run in PHP** — there is none on this machine, and this touches almost none. Driven in a browser
against static copies of BOTH grids with the real CSS and the real scripts. The breakdown: controls
on all nine columns, a chip-column filter finding the two shots with Wonderkid, the totals following
to *2 of 45 shots · 95 frames · 3.959s* and restoring exactly on Clear, frames sorting numerically
with blanks last both ways, a chip column sorting by its list, the foot never sorted into the body,
hidden rows keeping their fields, and the picker closing on a filter and reopening after. The artist
grid was re-run whole after the extraction and behaves as before.

One thing the harness taught rather than the product: with the browser pane hidden the window reports
**0×0**, so the picker's own "close when the button scrolls out of sight" fired on every open. Not a
bug — but a reminder that a test environment that is not being painted will report a viewport that
does not exist, and that a check against `window.innerWidth` is the kind that notices.

## 2026-09-29 — The artist grid gets column filters, sorting, and a count
**Asked by David:** *"in the headers add a filter, a sort order and so we know how many results that
filter produced."*

Every column heading is now a **sort button** with a **filter** under it, and a line above the table
says **"N of M people match the column filters"**.

**⚠ ENTIRELY IN THE BROWSER, and that is correctness rather than speed.** The search box at the top
of this screen is a GET and reloads the page. This grid holds EDITS and saves as you go, so a
server-side filter would reload under whatever somebody had half-typed — the one thing a screen like
this must never do. It also means the two compose: the search narrows who is on the page, these
narrow what you look at within that.

**⚠ HIDDEN ROWS STILL SAVE.** Rows are hidden with a class, never removed, so their fields stay in
the DOM and in the form. Edit somebody, then filter them off the screen, and the edit still reaches
the server — checked, not assumed. Sorting moves the `<tr>`s, which carries their fields with them; a
save already in flight holds the row itself rather than its position, and a row edited after being
sorted from first place to last still saved under its own id.

**The controls are built by `artists.js`, not printed by `artists.php`**, because they are made of
what the table actually holds. A column whose cells are `<select>`s or checkboxes gets a **dropdown
of the values in use**; everything else gets a box to type in. A dropdown means *exactly this one*, a
typed box means *contains*. JavaScript is already required to save this grid at all (stated on the
screen since 2026-08-31), so nothing is lost by building them there.

**What a column sorts on is not always what it shows.** A `<select>` sorts by the position of the
chosen option, so **Seniority reads Junior, Mid, Senior, Lead** — the order the app itself put them
in — rather than alphabetically, and Rating sorts by stars rather than by the length of a string of
them. Numbers sort as numbers. **An empty cell always sinks**, in both directions: "nothing recorded"
does not belong at either extreme.

Both controls live inside the **one `<th>`**, stacked, rather than in a second header row: the head
is already sticky, and a second row would need its own `top` measured against the height of the
first — a number that changes with the font and goes wrong quietly.

The count says *"11 people on this page"* with no filter and turns red at zero, because somebody who
filters down to nothing should not be left wondering whether the page broke. A **Clear column
filters** button appears beside it, and its visibility is decided inside the filter pass rather than
by a listener — pressing Clear sets the boxes from script, which fires no `input` event, so a
listener left the button offering to clear what it had just cleared.

**Not run in PHP** — there is none on this machine, and this change touches none. Driven in a browser
against a static copy of the grid with the real `style.css` and the real `artists.js`: the controls
built per column (dropdowns only where the cells are dropdowns, nothing on the status column),
contains-matching, two filters composing, a zero match reddening, Clear restoring, exact-match
dropdowns, name sort both ways with the arrow and `aria-sort`, seniority in its own order, day rate
numeric with blanks last, the sticky head still pinned, and the two save tests above. The harness
also caught the sort arrows: written as CSS escapes they arrived as a control character, so they are
written as the characters themselves now.

## 2026-09-28 — The read-only role becomes 'artist', and gains exactly one write
**Asked by David:** *"there's one thing that the viewer permission settings should allow and it's the
ability to adjust or change their status on their tasks or tickets. Let's also change the user
permission group to artist please."*

**The name follows the job.** `viewer` described a limit; `artist` describes the person holding the
account. Migration **037** renames it in three statements — add the new value, move the rows, drop
the old one — so no row ever holds a value its own column disallows. One statement would have asked
MySQL to rewrite every row against an ENUM that no longer contained what they held, and on a
non-strict server `'viewer'` becomes `''`.

`real_role()` maps a stored `'viewer'` onto `'artist'` and is **the only place that still knows the
old name**, so the app is correct on a database where 037 has not run yet and every other file reads
as though only `'artist'` ever existed.

### The hole in read-only, and its shape
Every write in this app is a POST, and `require_login()` refuses them all for anyone who cannot
edit. That stays. What changes is one named exception:

`artist_may_post()` is **an allow-list of exact POSTs** — script *and* field — not a role check
sprinkled through the screens. It is the complete answer to "what can an artist write?", and a screen
added next year is refused by default rather than let through by an oversight. Today it holds two
entries, both on `mytasks.php`: `set_status` and `set_ticket_status`.

**Two locks, not one.** The allow-list says which ACTION is permitted; `mytasks.php` says whose ROW
it may touch, with the same ownership test the production-task path already used (`crew_id`, or a
`progress_crew` row; for a ticket, `assigned_crew_id`). Without the second, the form's hidden id is a
way to move anybody's work. Neither is sufficient alone and both are cheap.

**A preview still saves nothing**, artist or not. That rule has no exceptions and did not grow one.

### What is deliberately NOT allowed
- **`set_crew`** — the "which of the crew are you?" form takes any crew id, so letting an artist
  self-link is a way to claim to be somebody else and then change their work. An **admin** links the
  account on the Users screen, which validates the choice against the crew list. An artist who is
  not linked yet sees a message naming who to ask, rather than a control that would be refused.
  Guarded twice as well: the allow-list omits it, and the branch in `mytasks.php` now tests
  `can_edit()`.
- **`log_time`** — status was asked for; logging hours was not. The controls stay disabled.
- **`ticket.php`'s `update`** — it rewrites the whole card, assignee and title included, so an
  artist saying "ready for review" could hand the ticket to somebody else in the same request. A
  status-only path was added to My tasks instead, which writes the change into the card's own thread
  and calls `sync_ticket_completion()`, so a card closed from either screen behaves the same.

A ticket's status was a **read-only chip** on My tasks and is now the same picker the production
tasks carry. Two things fell out of that: `$statusesByProject` was built from the tasks loop only, so
a ticket on a project with no tasks assigned would have drawn an empty list — invisible while the
chip was read-only; and the chip is kept for anyone who cannot change it, because a disabled `select`
reads as broken rather than as read-only.

### A checker that had learned the lesson in one pass and not the others
Renaming an option's text removed an apostrophe from `users.php`, and `check_php_blocks.py` began
reporting an unterminated string eighty lines away, in the plain-HTML sentence *"Ask them to change
it once they're in."*

The cause was not the rename. **PHP ends a one-line `//` comment on `?>` and leaves PHP mode there**,
and `users.php` has a `<?php // … ?>` comment block that relies on it. The FIRST state machine in the
checker models this and carries a comment explaining why (it is lesson 9 in `START_HERE`); the
`unterminated_string()` pass skipped blindly to the newline, swallowed the tag, and read the HTML
below as code — where an apostrophe opened a string that never closed. `can't` on the line I edited
had been closing it again by pure coincidence.

Three state machines, one rule: when one of them is taught something, check the others. Fixed, and
checked against three cases — a `?>`-in-comment file with apostrophes now passes, and two files with
genuinely unclosed strings are still caught at the right line.

**Not run in PHP** — there is none on this machine. All seven checks pass, and the guard was replayed
in Python across sixteen cases: every role, the allowed pair, the refused neighbours (`set_crew`,
`log_time`, `ticket.php`, `tasks.php`, `users.php`, `shots_api.php`), a stored `'viewer'` on a
database behind 037, and a preview refusing to save whichever role it wears.

## 2026-09-28 — A viewer gets five project tabs, not fifteen
**Asked by David**, naming all ten: *"we need to hide some tabs for only viewers... Dashboard,
Staffing, Timeline, Schedule, Team performance, Setup, Import, Statuses, Names, Export. We only leave
the rest."*

**A viewer keeps Overview, Brief, Shots, Production tracking and Tasks** — what the show contains and
how far it has got. What goes is everything about how it is RUN (the pace, who is on it, when, how
they are doing) and every screen for setting up or repairing data. With the data group gone entirely,
the separator that divided the bar is gone too, which falls out of the filter rather than being a
second rule.

`PROJECT_TABS_HIDDEN_FROM_VIEWERS` lives beside the tab list in `includes/project_nav.php`, and
`project_nav_items()` applies it, so anything that ever reads that list gets the same answer the bar
draws. `hidden_from_viewers()` is keyed on `can_edit()` rather than on the role name, so it follows
the admin's **preview-as-viewer** without being taught about it — which is also how to check it.

**Overview's own links went with the tabs.** It is a viewer's landing page, and it carried five doors
into screens they no longer have tabs for: *"See the pace behind this →"* (Dashboard), *"Review them
→"* (Names), the Schedule and Setup links in the two empty-state sentences, and the three **Export**
buttons — which are the Export tab by another name. Hiding a tab and then opening the same screen
from a link on the first page they see would be tidying nothing.

Where a link sat inside a sentence, **the fact stayed and the instruction went**: a viewer reads
"Nothing scheduled yet." rather than a pointer to a screen they cannot use. The count of unmapped
statuses stays for the same reason — hiding a fact is a larger decision than hiding a way to act on
it, and was not what was asked.

### ⚠ THIS HIDES A TAB, IT DOES NOT CLOSE A DOOR
Nothing here refuses a page. A viewer who types `dashboard.php?project=1`, follows a bookmark, or
follows one of the links still sitting on Shots, Production tracking and Tasks — eleven of them,
all "go and fix this data" prompts aimed at somebody who can — still arrives. **Said plainly to David
rather than implied**, because a half-measure that looks like a permission is worse than no measure.

If those screens must be genuinely out of reach, the guard belongs in `require_login()` where the
read-only rule already lives, and it should read `PROJECT_TABS_HIDDEN_FROM_VIEWERS` so the two can
never disagree. That is a behaviour change — bookmarks stop working, and those eleven links need
hiding too — so it waits for a decision rather than arriving unasked.

The top bar is untouched: Artists, Bookings, Migrations and the rest were not on the list.

**Not run in PHP** — there is none on this machine. `check_php_blocks.py` passes all seven, and the
filter was replayed in Python for all three roles: admin and editor keep fifteen tabs and one
separator, a viewer gets five and none, and the ten that disappear are the ten that were named.

## 2026-09-23 — An empty reel was dressed as a link and did nothing
**Asked by David**, circling the Reel column on Artists: *"how can I update these fields?"*

**The answer was always "double-click the cell"** — that column is the one cell on the grid that
shows a LINK rather than a field, because a reel is something you open far more often than you
retype, and an input cannot be clicked through to the site. Every other column is an input, so the
one that isn't looks like the one that can't be edited.

**The cell made that worse for exactly the rows that needed the hint.** An empty reel rendered
`href="#"` — a real anchor, so it took a link's blue and a link's pointer, and then did nothing at
all when clicked. The dash advertised a single click, ignored it, and gave no sign that a double one
would work. An `<a>` with **no href** is not a link: plain text, no pointer, not focusable, which is
what an empty cell should have been all along. Now it is faint, carries a text cursor, says
*"Double-click to add a reel"*, and its cell lights up under the mouse — the only visible cue that
the gesture exists. The title on a filled one reads *"<address> — double-click to edit"* rather than
starting with a stray comma.

`artists.js` matches on the way back out, removing the href when the box is emptied instead of
writing `'#'`, so a cleared reel and a reloaded page agree.

**Still open, and NOT fixed here:** the field is unreachable by keyboard. The input is `hidden`
until a double-click, and `hidden` is not focusable, so tabbing through the grid skips the reel
entirely. Opening it on F2, or on Enter when the anchor has no href, is the fix; it is a bigger
change than the question asked for.

**Not run in PHP.** Driven in a browser against a static copy of the column with the real CSS and
the real `artists.js`: the empty cell faint with no href, the filled one blue with `target`/`rel`,
double-click opening and focusing the box, typing an address turning it back into a working link,
clearing one turning it back into a dash, and the row writing itself — three saves with the right
`portfolio_url`, the cleared one included.

## 2026-09-22 — The import result screen could say "no loose ends" while dropping work
**Asked by David:** *"I've just imported this csv and the animatic still appears as pending but it's
marked as complete."*

**The result screen's reassurance ignored half the report.** `done.php` decided whether to print
*"Everything in the file matched something here, no loose ends"* by looking at unmatched PEOPLE and
unmapped STATUSES only. A whole-project item that matched no step — counted all along as
`skipped_no_project_step` — did not enter that test, so the screen could print the green line
directly underneath its own table saying it had skipped one. **That is the precise failure this
importer exists to prevent**, on the screen whose entire job is reporting it. The test now includes
the skip counters and the unmatched project steps.

**And it counted them without naming them.** `preview.php` has always listed which whole-project
items failed to match; `done.php` gave a number and left you to work out which two rows of five
hundred it meant. A whole-project deliverable matches **by name**, and unlike a subitem step it has
**no dropdown on the mapping screen** — so the name is the only thing that makes it fixable. It is
now listed, with a pointer to Workflow, where the project's step names live.

### The column that wasn't there
The same export carried a heading spelled **`Asignee`**, one "s". `Assignee` is not a required
column, so the file passed every check, imported cleanly, and attached **nobody** to any of its 510
records — and `unmatched_people` was empty, because nothing had been read to be unmatched. That
emptiness then helped print "no loose ends".

`plaky_read_csv()` now canonicalises headings: a name already right is left alone, a different case
is accepted, and a **known** misspelling is read as what it means. `PLAKY_COLUMN_ALIASES` is an
**explicit list, not an edit distance** — a fuzzy match on a heading means reading a column the file
never offered, which is the "let the file dictate meaning" failure this importer is built against. A
spelling earns its place by turning up in a real export, and every rename is **reported** on the
mapping and preview screens rather than applied quietly, the same deal `plaky_canonical_asset_type()`
makes with asset types.

`PLAKY_OPTIONAL_COLUMNS` also names what each missing column COSTS — "no shot will get a frame count
from this file" — because "Total frames not found" means nothing to somebody who did not write the
export. This file has no `Total frames` either.

**What this does NOT settle:** why FC Mobile's Animatic reads as untouched. Three things could cause
it and the code cannot tell them apart without the project's own rows — the "Pre-prodcution" group
mapped to something other than *whole-project deliverables*, no project-wide step spelled
"Animatic", or "Completed" mapped to a status that is not *approved*. The third is unlikely, since
three characters share that status and read correctly. **The point of this change is that the next
import says which**, instead of finishing green.

## 2026-09-22 — The importer stamped its group name in as an episode (bug)
**Asked by David:** *"what's the deal with the additional shots at the end of the shotlist? Where are
they coming from?"* FC Mobile counted 45 shots where the list is 42, and the three extras carried no
frame count.

**They were made by the Plaky importer, and they are identifiable because of the bug that made
them.** In `plaky_import.php`, a group mapped as `shots` — a flat shot list, no episodes — computed
`$episode = ''` and looked shots up on that key, then INSERTED `$p['Group']` instead: the group's
own name. FC Mobile's group is called "Shots", so anything the importer created landed with
`episode = 'Shots'` while everything typed or pasted in has `episode = ''`. The line sat directly
under a comment saying the opposite — *"stamping the group name in as an episode would invent
structure that isn't there"* — which is exactly what it did.

**Three consequences, all of them visible before anyone knew why:**
- Anything the importer created **never matched again**, because the lookup key and the stored row
  disagreed. A re-import of the same item makes another row rather than updating the first.
- `wizard_shots_episodic()` infers episodic from `COUNT(*) WHERE episode <> ''`, so **three rows
  nobody meant to have an episode made the whole project episodic** — which is why the breakdown
  grew an **Ep** column reading "—" on every real shot.
- The scheduler counts them: 45 shots of animation planned against 42 that exist.

Fixed by writing `$episode`. **The three existing rows are left alone** — deleting production rows
on David's behalf is not a migration's job, and they are easy to find precisely because they are the
only ones under "Shots": wizard step 4 groups by episode, so they sit under their own heading with a
✕ each. Removing them cascades to their progress, asset links and schedule rows, and drops the
project back to non-episodic on its own.

**Not caught by a test, caught by a question.** Nothing in the codebase compares what the importer
looked up with what it wrote; the only symptom was a count three too high on one screen. Worth
remembering the shape: a key built one way and a row written another is silent until something
counts.

## 2026-09-22 — The shot breakdown's headings stick, and so does its total
**Asked by David:** *"let's do a sticky header at the top of the shot tab so we can see them when I
scroll down."* Forty-five rows by nine columns, and once the headings scroll off, a cell of chips
does not say whether it is Prop or FX.

**`th` was ALREADY sticky — it just had nothing to stick to.** The blanket `th { position: sticky;
top: 0 }` has been in `style.css` since the start. `.bd-scroll` carried `overflow-x: auto` for wide
projects, which makes it a scroll container (the Overflow spec forces `overflow-y` away from
`visible` the moment `overflow-x` is anything else), and sticky resolves against the nearest scroll
container rather than the page. With no height bound that box was exactly as tall as its table, so it
never scrolled vertically and the heading had nothing to pin against: sticky in name, motionless in
practice. **The fix is the height bound, not the sticky.** `.grid-scroll` already carries the same
diagnosis in a long comment — this is the second table to hit it.

`max-height: max(300px, calc(100vh - 210px))` rather than `.grid-scroll`'s flat 78vh: this screen
has more above it (nav, the breakdown warning, the totals line). **`max()`, not a `min-height`** — a
min-height would hold the box 300px tall for a five-shot project and leave a band of empty grey under
the last row.

**The totals row sticks to the bottom** for the same money, so the frame count stays on screen while
the list is read. Its rule is an inset shadow, not `border-top`: a sticky cell leaves its border
behind under `border-collapse: collapse`, which is why the heading draws its own line too.

**In print both come off** — a printer handed a scrolling div prints one screenful and drops the
rest — and `thead` repeats per page by itself, which is what it is for.

**One consequence that had to be handled:** a cell can now leave the visible area while the page
itself has not moved, and the asset picker tracks its button faithfully. It followed one straight off
the top of the window. The picker now **closes when its button scrolls out of sight**, measured
against the scroll box and the window, since either can hide it.

**Not run in PHP.** Driven in a browser against a static copy of the page with the real CSS and the
real `shots.js`: 45 rows, the heading pinned to the top of the box and the total to the bottom at
every scroll position, a short table ending where it ends with no scrollbar and no empty band, the
picker tracking a small scroll and closing on a large one, and reopening correctly afterwards.

## 2026-09-22 — The breakdown picks assets with tick boxes, and reads in production order
**Asked by David:** *"when selecting the players in the dropdown menu, I'm wondering if it's best to
have each character with a check box so I can click and add several assets at the same time. This
applies to all the assets."* And, in the same sitting: *"let's also change the order of the columns
to EP, Shot, Frames, Character, Environment, BG, Prop, FX, Notes."*

**The dropdown is gone.** Each cell now shows a `+` button that opens ONE panel of tick boxes — the
project's assets of that column's type, with the ones already in the shot ticked. FC Mobile has
twenty-five characters and a shot carries five of them, so a dropdown meant five separate trips
through the same list. Ticking links, unticking unlinks, **each as its own request, sent as it
happens**, which is the rule the rest of this screen already follows: no Done button to forget, and a
failure names the one asset that failed instead of losing the batch. Both halves roll back together
on a failure — the chip in the grid and the tick in the list — so the panel and the row under it can
never disagree.

**One panel, in `<body>`, not one list per cell.** Forty rows by five columns would be thousands of
checkboxes in the page, nearly all never looked at; and `.bd-scroll` clips its contents, so a panel
drawn inside a cell is cut off at the edge of the table. It is `position: fixed` and repositioned on
scroll and resize, so it stays under its own `+` when the table is scrolled sideways, flips above the
button near the bottom of the window, and is clamped inside the right edge. The filter box appears
only for lists of eight or more: a list of three props is read, not searched.

The asset names are sent once, as `window.BD_ASSETS`, rather than repeated in every cell — the same
twenty-five names would otherwise be written into the page two hundred times.

**The columns are ordered the way a breakdown is read**, not alphabetically: who is in it, where it
happens, what is behind them, what they hold, what happens on top. Alphabetical put BG in front of
Character and FX between Environment and Prop, which is nobody's order.

**⚠ It is a RANKING, not a list of allowed columns.** `BREAKDOWN_TYPE_ORDER` in
`includes/breakdown.php` gives the five named types a rank; anything else still appears, immediately,
alphabetically after them — a new type called Vehicle needs no code, which is the v1 decision this
screen was bought with (2026-08-11, *asset types are a dropdown built from data*). Names are matched
loosely, ignoring case, spaces and a trailing "s", so Props and Prop are one column's worth of
ordering. Add a name there only to move it up.

`breakdown_asset_types()` is the single source, so **the CSV export reorders with the screen** and
the two cannot drift.

**Not run in PHP** — there is no PHP on the machine. The ordering was replayed line for line in
Python against FC Mobile's five types, and the picker was driven in a browser against a static copy
of the page with the real `style.css` and the real `shots.js`: open, filter, tick, untick, a failing
link rolled back, outside click, Escape, the same button toggling shut, a second cell reading its own
ticks, the flip above the button, and the clamp at the right edge. That harness also caught the
panel's shadow being drawn in `var(--shade)`, which is **`#ffffff` on the dark theme** — a white glow
round the panel. Every other shadow in `style.css` is a literal `rgba(0,0,0,…)`; this one is now too.

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

## 2026-10-08 — "The work is finished" and "we are finished with the show" are two different facts
**Asked by David:** *"let's add a project status for 'postmortem' which is a state after finished
while it waits the postmortem meeting and inputs before the oficial wrap up."*

Migration **044**: `projects.status` gains `'postmortem'`, between `'wrapped'` and `'archived'`.

**Decision:** the lifecycle is now `setup → active → on_hold → wrapped → postmortem → archived`,
and **archiving is the official wrap-up** rather than the moment production stops.

**Why:** the app had one word for the end and it was doing two jobs. `'wrapped'` means delivered —
production has stopped. `'archived'`, since migration 027, means out of the way — the front door
stops showing it. There was nothing between them, so a show waiting on its postmortem meeting had
to either sit in `'wrapped'` looking identical to a show nobody owes anything on, or be archived
*before* the meeting that archiving is supposed to follow. Now the state says which it is.

**Nothing was reclassified.** No existing row becomes `'postmortem'`; the migration only widens the
ENUM. A migration that declared every wrapped show to be "waiting on a postmortem" would be
inventing an obligation nobody recorded, and the team would have to un-set it show by show.

### ⚠ A postmortem show stays on the front door, and that is the point
Only archiving takes a show off `index.php`. A show in postmortem is a show somebody still owes
something on, so it stays where it can be chased. Hiding it would have dropped it off every list in
the app at once — the quietly-empty screen this codebase keeps paying for.

Set it on the project list, from the same status dropdown as every other status, gated by
`enum_has_value()` so the option simply does not appear until migration 044 has run.

### What a postmortem show still does, and what it no longer does
Decided one list at a time rather than by a blanket rule, because the lists are asking different
questions:

- **On the front door, the project switcher, the finance list and the copy-a-setup picker** — yes,
  unchanged. These answer "which shows exist", and it does.
- **On the card pickers** (`ticket.php` move-to-project, `transcript.php`) — yes. Notes that come out
  of a postmortem meeting are exactly what somebody files as a card, so the state that exists to
  hold that meeting must not be the state you cannot file against.
- **Daily snapshots** (`includes/snapshots.php`) — no. A snapshot answers "where did this show stand
  today" so a chart can show work advancing. A postmortem show reached that state *through*
  `'wrapped'`, so every further snapshot would rewrite the same numbers and end the chart with a
  tail of identical days. The snapshots taken while it was active are the history and they stay.
- **My tasks' production steps** (`mytasks.php`) — no, and this one was genuinely arguable. A show in
  postmortem may still have cards to close. But postmortem comes strictly *after* `'wrapped'`, and
  `'wrapped'` already drops production steps off that screen — so including postmortem would make
  tasks that vanished at wrap-up **reappear a step later**, which is stranger and harder to trust
  than them staying gone. If unfinished work is the reason a show cannot be called done, the honest
  move is to leave it `'active'`. The **tickets** half of My tasks is not filtered by project status
  at all, so a card raised in the meeting still reaches its assignee.
- **Bookings** (`includes/bookings.php`) — no. The production work is finished, so the dated step
  positions the show still holds describe days that have already happened; including them would
  report clashes against a show nobody can be booked onto.

### ⚠ Every FIELD() list had to be edited by hand, and one of them was a real bug waiting
`FIELD()` returns **0** for a value it does not list, and 0 sorts **first** ascending. So the two
card pickers — which filter on `status <> 'archived'` and therefore *include* postmortem — would have
floated every postmortem show above the live ones had the list not been extended. Reproduced in a
SQLite replay rather than reasoned about.

The position inside the ENUM itself is **for readability, not for correctness**: MySQL orders an ENUM
positionally, but nothing in the app sorts by it. Checked on 2026-10-08 — every project-status
ordering is an explicit `FIELD(...)` list, and `includes/standards.php` sorts on
`p.status = 'archived'` as a boolean (so a postmortem show sorts among the live ones there, which is
right: a nearly-finished show is good evidence of how to set one up).

**Deliberately not built:** the notes themselves — a text input per department/pipeline step plus a
general one — and the prompt on archiving a show with no postmortem. This entry is the lifecycle
state only; the capture is still on `docs/ROADMAP.md`.

**PHP WAS NOT RUN.** Verified by `tools/check_php_blocks.py` (all seven passes) and a SQLite replay
of every status query shape with a `'postmortem'` row present.

---

## 2026-10-08 — Hours are measured by a server-side stopwatch, not remembered
**Decision:** a timer can be started on any task or card on My tasks. It lives in the
`time_timers` table, one row per person, and writes to `time_logs` only when it stops.
**Why:** every hour in the platform was typed from memory into a box, so it arrived rounded
to halves, generous, and missing the short jobs entirely — and those hours are what the
expense projection prices and what the stop-cost panel reads. The obvious build, a
`setInterval` and a number in `localStorage`, loses the afternoon the first time a tab closes
or a laptop sleeps, silently and unrecoverably. Stored server-side the timer survives the tab,
and it can be started at the studio and stopped at home.
**Consequence:** `crew_id` is the primary key, so one running timer per person is enforced by
the schema rather than by remembering to check — nobody works on two things in the same
minute, and two concurrent timers would bill the same hour to two projects. Starting a second
timer stops the first, logs it, and says so, because the alternative (refusing the second)
makes people leave the first running while they move on, which is the one outcome that
actually corrupts the figures. A timer running over 24 hours is refused rather than truncated:
it means somebody forgot it on Friday, not that they worked a 24-hour day.
**Not built (built since, 2026-10-16, see below):** the pomodoro rhythm David also mentioned. A pomodoro arranges attention in
fixed blocks; what the figures need is a measurement. The stopwatch is the primitive, and a
chime can sit on top of it later without the stored shape changing — whereas building the
blocks first would have left every hour rounded to twenty-five minutes nobody worked.

## 2026-10-08 — "Nothing of this kind in this shot" is a fact, not an empty cell
**Decision:** the shot breakdown gets an N/A mark, stored per shot AND per asset type in a
new `shot_type_na` table (migration 042). A cell is either a list of assets, or N/A, never
both: linking an asset clears the mark, and the picker refuses to set it while chips are
there.
**Why:** the grid could not tell "nobody has filled this in yet" from "this shot genuinely
has no character in it", and both readers of the breakdown got it wrong. The warning above
the grid nagged for ever about shots that were already settled, and — the expensive half —
`scheduler_item_earliest()` fell through to its last rule and made those shots wait for the
WHOLE asset step. A shot with no characters in it waited for the last character in the show
to be finished before its animation could start. Always pessimistic, and pessimism in a
schedule reads as "we need more people" rather than as missing data, which is how it gets
believed. David, 2026-10-08: "let's add an N/A option for all the projects because it might
be that some of them we don't need anything."
**Consequence:** per cell rather than per shot, because that is the question actually being
asked — SH160 needing no Character says nothing about its Environment, and only the finer
fact is usable by the scheduler. The new rule 2b is deliberately narrow: it releases a
dependency only when the step is scoped to ONE NAMED asset type that the shot has marked
N/A. A step with no type filter covers every type, so a single mark proves nothing about it
and the wait stands. That asymmetry is on purpose — a pessimistic plan is annoying, an
optimistic plan is a missed delivery.
**Consequence:** `asset_type` is stored as the type NAME, matching the generated-column
design the breakdown has used since day one. Renaming an asset type therefore orphans its
N/A rows, and the cells read as unmarked again. That is degradation in the safe direction:
the warning comes back and the scheduler returns to waiting, so nothing is ever claimed that
is not true. Keying on an asset-type id would have been the schema change that design was
bought to avoid.
**Consequence:** the "broken down" count still treats ONE link or ONE mark as enough, which
is the same loose rule it has always used. The strict version — every column either filled
or N/A — is a better number and a bigger change, and doing it here would have moved the
figure on every project on the day it shipped with no way to tell whether the work or the
rule had changed.

## 2026-10-12 — The money strip is seven figures about cost, and revenue is not one of them
**Decision:** the Finance Overview strip shows Approved production budget, Current forecast cost,
Budget variance, Invoiced, Paid, Unpaid and Left to spend. Revenue, added on 2026-10-06, is
removed from the screen; `project_budgets.revenue_amount` stays in the finance database, unread
and unwritten.
**Why:** David, 2026-10-12, listing the seven he wanted. Revenue took part in no sum on a panel
where every other figure is a cost, and a client number sitting among costs gets read as profit
by anybody skimming — which is the same failure that made the old *Margin* label worth renaming.
The question "did the show make money" has a different audience from "are we inside what Jorik
approved", and one strip answering both answers neither.
**Why the column survives:** dropping it needs a hand-run statement in phpMyAdmin (migrate.php
cannot reach the finance database) and would throw away the figure already typed there. Leaving
it costs nothing and keeps that number recoverable if the question comes back.

## 2026-10-12 — "Left to spend", not "Balance"
**Decision:** the seventh figure — current forecast cost minus everything invoiced, paid or not —
is labelled **Left to spend**, with *estimate to complete* named in the note beneath it.
**Why:** David asked for it as "Balance" and asked whether there was a better name. There is, and
the reason is on the same strip: *Unpaid* already is a balance in the sense most people reach for
first — money owed to suppliers. Two figures called balance, side by side, meaning different
things, is a number waiting to be misread in front of a client. *Estimate to complete* is the
formal name on a film cost report and maps onto what a financier expects to see; "Left to spend"
says the same thing without the glossary.
**Consequence:** it is measured against the FORECAST, not the approved budget. Those are two
different comparisons, and running it off the budget would have duplicated Budget variance while
hiding the case that matters — invoices outrunning a forecast nobody ever approved. It is null,
not zero, while nothing is costed: 0 minus 0 is 0, which would read as "nothing left to spend" on
a show that has not been priced at all.

## 2026-10-12 — Final balance: approved budget against real money, and what "final" means
**Supersedes** the "Left to spend, not Balance" entry above, the same day. The log is
append-only, so that entry stands as what was decided and this one as what replaced it.
**Decision:** the seventh and eighth tiles are **Final balance — invoiced** (approved budget
less everything billed) and **Final balance — paid** (approved budget less money that has
actually gone). The forecast-based "Left to spend" is removed.
**Why:** David overruled the forecast basis: "let's do it against the approved budget and let's
call it final balance", and, asked which side to subtract, "show both". He is right for the
audience. Jorik approved a number, and the question a client takes away is how much of *that*
number was spent — not how it compares to a forecast Anima recalculates on every page load.
Budget variance still carries the forward-looking comparison, so the strip now reads
plan-against-approval on the left and actual-against-approval on the right.
**Why two:** they answer the same question at different distances from the bank. Invoiced is the
conservative one mid-show, counting money committed before it leaves; paid is the true one at
the end. They converge when the last invoice is settled, which is the moment the figure stops
moving.

## 2026-10-12 — "Nothing unpaid" is not the same as "finished paying"
**Decision:** the Final balance tiles are amber until **all three** hold — something has been
invoiced, nothing is outstanding, and every staffing slot with a real person in it has an
invoice against it — and green only then. Placeholder slots are skipped.
**Why:** MSD today has no invoices at all, so Unpaid is 0.00. A rule that went green on
"nothing unpaid" would have declared the show finished and the balance final on a project
nobody has billed a penny against — Lesson 11 in its most expensive form, since this is the
figure that goes in front of the client. David's own phrasing carried the answer: "green once
the last invoice has been paid (linked to each slot)".
**Why placeholders are skipped:** an unfilled slot is work nobody did, so no invoice will ever
arrive for it. Requiring one would put "final" permanently out of reach on any show planned
more generously than it was staffed.
**Consequence:** when it is not final the tile says what is blocking it — "nothing invoiced
yet", "3 positions not invoiced yet", or "waiting on the last payment" — rather than only
withholding the green. The amber is the same `--warn-tint` the pending staffing slots use, so
"not settled yet" reads identically on both screens.

## 2026-10-12 — The Studio is a project row, reversing 2026-10-07
**This overturns "A card can exist before anybody says which show it is for" in part.** That
entry rejected a pseudo-project for unfiled cards and was right at the time. Read it first; it
is not wrong, it answered a different question.
**Decision:** migration 045 adds `projects.is_studio` and one row, code `STUDIO`, with its own
statuses copied from the templates. Its board is `tasks.php`, the same board every show uses.
**Why the reversal:** the 2026-10-07 entry turned on one observation — *"Studio Tasks is a
read-only list with filters — it has no columns, so it needs no statuses, so option 1's entire
advantage bought nothing while its cost stayed."* On 2026-10-12 David asked for the columns:
"let's mimic the per project task/ticket UI to the Studio Tasks tab. We should be able to create
new tasks from there". A board needs a status vocabulary, a per-board sequence and a create
flow. `projects` already has all three and `tasks.php` already draws it. Building a parallel set
for `project_id IS NULL` would be two implementations of one idea — the mistake this codebase
names repeatedly (STFC's `shot_characters`/`shot_environments`; the two-vocabulary trap in
migration 017).
**The lesson stands, and it is the same one:** that entry said the deciding factor was the shape
of the requirement, not the strength of the argument. The requirement changed, so the answer
changed. It was not wrong then.

## 2026-10-12 — The price of the pseudo-project, paid in one place
**Decision:** `includes/studio.php` holds `studio_exclude_sql()` / `studio_where_sql()` and, in
its docblock, the enumerated list of every screen that filters the Studio out: the projects
page and its archived count, finance, the project switcher, the nightly snapshot, bookings,
historic quotas and fees, workflow standards, and project types twice. Two pickers include it
deliberately — the ticket page's "move to project" and the transcript's — because those are how
a card reaches it.
**Why:** the 2026-10-07 entry predicted the cost exactly: "an inbox appearing as a show with no
delivery date and 0% progress on the projects page, the dashboard, finance, the switcher and in
bookings, forever." That prediction was correct and is the reason this is not free. Writing the
list down in the helper, rather than leaving nine scattered `AND is_studio = 0` clauses to be
rediscovered one bug at a time, is what makes the cost payable once.
**Consequence:** every filtered query degrades to its old behaviour when migration 045 has not
run, because the fragment returns `''`. Both states were parsed before shipping — the splice
broke `snapshots.php` on the first attempt by opening a string it never closed, which is exactly
why both are tested.

## 2026-10-12 — Filing unfiled cards is an offer, never automatic
**Decision:** Studio Tasks shows a button to move every card with no project into the Studio,
and only when there is at least one. Nothing moves on its own; migration 045 touches no
existing row.
**Why:** capture-first is still right, and a NULL project still means what the 2026-10-07 entry
said it means. The Studio is a destination somebody chooses, not a bucket that unfiled cards
fall into — if it were automatic, "I have not decided yet" would become indistinguishable from
"this belongs to no show".
**Consequence:** the move runs card by card through `move_ticket_to_project()`, not as one
UPDATE, because that function is what grants a `seq` (so a card stops reading as "#21") and
drops it into the target's first column (so it is visible on arrival). It deliberately opens no
transaction of its own: `move_ticket_to_project()` already opens one and MySQL cannot nest them
— `tools/check_php_blocks.py` caught that before it ran. Nothing is lost, because the operation
is idempotent and resumable: a card that has moved is no longer unfiled, so a failed batch can
simply be run again.

## 2026-10-12 — Filing is per card, from a dropdown, not one bulk button
**Supersedes "Filing unfiled cards is an offer, never automatic"** earlier the same day. The
offer stands; the control was wrong.
**Decision:** every row on Studio Tasks with no project carries a project `<select>` that files
that one card. The bulk "move them all to the Studio" button and its two helpers are removed.
**Why:** David, 2026-10-12: "instead of clicking for the ones that don't have a project, make
sure a dropdown is always available in this view for the tasks that don't have a project
assigned yet." His own list is the argument — "Confirm actual FCM/FIFA deadline with Corey" and
"Address staffing gaps on FCM project" are FCM's work. One button would have swept both into the
Studio, and *a card filed against the wrong show is harder to find than one filed against
nothing: the wrong one looks handled.* That sentence is from the 2026-10-07 entry, and it argues
for per-card choice.
**Why this screen may carry a control at all,** when its own header says it carries none on
purpose: that rule exists because a status picker here would duplicate the board. A PROJECT
picker on an UNFILED card duplicates nothing — a card with no project is on no board, so this
list is the only place it can be acted on. Filed rows keep plain text and get no picker.
**Consequence:** the selected option must read "no project". `gridtools.js` takes a cell's
filter and sort value from a `<select>`'s chosen option text, so that wording is what keeps
"no project" available in the Project filter — the one filter that finds these cards.
`alltasks.php` passes its own `options:` callback, which read `textContent` and would have
returned "no projectSTUDIOMSDFCM"; it now uses `GridTools.cellText`.

## 2026-10-14 — A model's output shape is a preference, not a guarantee
**Decision:** `ai_task_items()` accepts five shapes of reply — a bare array, a fenced array, an
object wrapping one under `tasks`/`items`/`action_items`, a single unwrapped item, and one
object per line — instead of only `[ ... ]`.
**Why:** David uploaded a real transcript on 2026-10-14 and lost the whole meeting. The reply
was `{"type":"ticket","title":"Investigate and fix: asset file from Donovan cannot be opened in
Maya"...}` — a perfectly good action item returned as a bare object because the meeting produced
only one. The extractor looked for the first `[` and the last `]`, found neither, and refused
everything. The system prompt *does* ask for an array, and it came back like this anyway.
**The lesson:** being strict about a model's output FORMAT costs somebody their meeting, while
being strict about its CONTENT costs nothing. Every field was already validated to death —
known values only, cut to column widths, an owner that must match somebody really on the show —
so accepting more shapes adds no risk: an object with no usable title is dropped by the same
loop that drops a bad one inside an array. The prompt now also states the array rule explicitly
for the single-item case, as belt and braces rather than as the fix.
**Also:** "the reply was cut off" and "that is not a list" were one message and are now two.
Anthropic reports `stop_reason: max_tokens`, so a long meeting is told to be split in half
rather than sent looking for a fault that is not there. `max_tokens` went from 8,000 to 16,000;
a cap costs nothing unless it is reached.

## 2026-10-16 — A card can be created from Studio Tasks, and there is still one create
**Decision:** Studio Tasks gets a **+ New task** dialog of its own. The ninety lines that
made a card were lifted out of `tasks.php` into `create_ticket()` in `includes/tickets.php`,
and both screens call it.
**Why:** David, 2026-10-16: "ok but I still can't create task from this view." The 2026-10-12
build gave the tab a button through to the Studio's board, which answered a question he had
not asked. A cross-project list is where a card belonging to no show is thought of, and
sending somebody to a board to write it down is the friction that keeps cards in notebooks.
**Why extracted rather than copied:** a second copy would have been two answers to "what is a
new card" — the seq race inside the transaction, the ticket-needs-an-owner rule, the
platform-versus-step split, the unmatched-name reporting — and they would drift the first time
one of them was fixed. `create_ticket()` takes a NULLABLE project id, which is what lets a
cross-project screen call it at all: capture-first (migration 041) says a card may exist before
anybody decides which show it is for, so everything project-shaped is skipped rather than faked.

## 2026-10-16 — Studio Tasks creates tasks, not tickets
**Decision:** the dialog on Studio Tasks makes plain tasks only. Tickets stay on a project's
board, and the dialog says so and links there.
**Why:** a ticket is a problem attached to a shot, an asset or a pipeline step on ONE show, and
those three pickers cannot be drawn until a project is chosen. A project selector that rebuilt
three other selectors beneath it IS the board, and the board already exists.
**Consequence:** the status is chosen by the server, not offered on the form. Board columns are
a project's own vocabulary, so a status picker would have to be rebuilt every time the project
dropdown changed. A new card starts in its project's first column — what dragging it onto the
board would have done anyway — and an unfiled card gets no status, because there is no
vocabulary it could belong to.
**Also:** the dialog and the list of destinations sit OUTSIDE the "this page has rows" branch.
Both were inside it at first, which left the button rendering on an empty Studio Tasks with no
dialog behind it — and an empty list is exactly when somebody wants to add the first card.

## 2026-10-16 — The budget pool is derived, never typed
**Decision:** a Budget pool tab under Finance management shows the show in days. The pool is
each pipeline step's headcount multiplied by the working days between its start date and its
deadline, computed on every page load in `budget_pool()`. There is no table behind it.
**Why:** David, 2026-10-16: "Days should come from the headcount & days, this is what the math
(quota, start/end dates, and headcount) tells us we need in days." A stored copy would be a
fourth number claiming to describe the same plan, beside the scheduler, the quota and the
expense projection — and this codebase has already paid for figures that drift apart.
**Consequence:** fixing a date on Dates & headcount moves this screen, with nothing to re-enter
and nothing to re-approve. The MVP therefore needed no migration at all.

## 2026-10-16 — Days and seconds are two pools, and the unit belongs to the person
**Decision:** people paid per approved second get a pool measured in seconds, which neither
adds to nor subtracts from the day pool. The split is made per LANE, not per step.
**Why:** David: "for those departments based on a per second the pool will have to be in
seconds too... I think those pipeline steps won't add or subtract days to the total days pool."
A second is not a day and summing them makes a number that is not of anything — the rule
`project_stop_cost()` already follows for currencies.
**Why per lane:** `crew_terms` is keyed by `crew_id`, so the rate unit belongs to the PERSON.
MSD's Color (Environment) holds Surendra by the day and Artie by the second at once. "A seconds
step" is not a thing that exists, so one step can appear in both pools.
**Consequence:** the seconds cards carry no pace line. A per-second artist is paid for seconds
*approved*, so spend and progress are the same measurement and comparing them would always read
"level".

## 2026-10-16 — Spend comes from the plan until somebody logs an hour, and says which
**Decision:** used days are the same dated window measured up to today, counting only slots with
a real person in them. If any hours have been logged through `time_logs`, those are used
instead. The figure always carries its source on screen.
**Why:** there are no time logs yet, and David asked how spend could possibly be subtracted
without them. A slot that started three weeks ago has consumed those person-days whether or not
anybody recorded them — which is exactly as true of the money. What is not acceptable is
showing the two sources interchangeably without saying which is on screen.
**Consequence, deliberate and asymmetric:** the pool counts every planned slot, filled or not,
because the plan is the plan; spend counts only real people, because an empty slot burns
nothing. An understaffed step therefore looks like it is under budget when it is really heading
for late, so the card says "1 of 2 slots filled" whenever the two disagree. Without that line
the screen would quietly reward understaffing.

## 2026-10-16 — A pace badge that cannot mean anything says so instead of going red
**Decision:** days-against-progress is left uncoloured, with the reason in words, when a step
has fewer than three items or has used under 15% of its pool.
**Why:** the mockup exposed both. MSD's Color Script is a whole-project step with ONE item, so
it reads 0% done for its entire life and 100% on the last day — it would have sat red from
start to finish and gone green an hour before delivery. And every step consumes setup days
before its first shot lands, so new steps skew red for a structural reason rather than a real
one. Five of seven cards came out red in the mockup, and a wall of red carries no signal.
**Consequence:** the thresholds are two named constants at the top of `includes/budgetpool.php`,
because they are a judgement about how twitchy the warning should be and will want tuning once
David has run a show on it.

## 2026-10-16 — The Ideal quotas tab is removed; days per item lives on Dates & headcount
**Decision, David's** (he asked *"is the Ideal quotas still ideal? I think these ones are the same
that are in the Headcount & dates right?"*, then chose "Remove it"). They were the same: since
2026-09-17 both screens read and wrote `pipeline_steps.days_per_unit`, one value per step, so the
tab was that number under a second name on a second screen. "Ideal" also stopped meaning anything
once there was no estimate left for it to be ideal against.

**What changed.**
- **No Ideal quotas tab** in Finance management (`includes/finance_nav.php`).
- **The approved-expense-projection match moved to Dates & headcount**, above the table it fills
  in: `ideal_match_post()` and `ideal_match_render()` in `includes/ideal_match.php`, posted to the
  wizard's step 6. Same preview-then-apply flow, same admin-only rule. "Copy from another project"
  was already there.
- **`standards.php` only forwards**, to Dates & headcount with a one-line note, so old bookmarks
  and links land somewhere. Every link that pointed at it now points at Dates & headcount.
- `save_project_ideals()` is gone with the only screen that called it.

**Given up:** typing the "where the number came from" note by hand. Dates & headcount still
shows it under each box and still writes it on its own ("Set by … on …", "Copied from …",
"Matched to …"); only free-text editing of it went with the tab.

## 2026-10-16 — Start on My tasks runs pomodoro cycles; only focus time is logged
**Decision:** Start begins a 25-minute focus block instead of an open stopwatch. When it ends
the 25 minutes are logged to the card and a 5-minute break starts (15 after every fourth
block). Breaks are never logged. The next focus block waits for a click. Stopping a focus block
early logs the minutes it actually ran. Migration 048 adds `phase` and `focus_round` to the
same `time_timers` row, so one timer per person is still enforced by the schema.
**Why:** David asked for the rhythm ("let's create pomodoro cycles instead of just letting the
time run"). The 2026-10-08 entry deferred it so the hours would stay measured rather than
rounded to blocks; that still holds, because a block stopped early logs real minutes, and the
only figure the cycle caps is an overrun nobody was at the desk for.
**Consequence:** the server settles a finished block (logs it, starts the break) whenever My
tasks loads or a timer button is pressed, so a closed tab loses nothing; the browser is only
the alarm (chime, desktop notification, countdown in the tab title). A block that ends while
nobody is there logs exactly 25 minutes. Each block is its own `time_logs` row. A timer that
was already running when 048 was applied stays an open stopwatch until it is stopped.
**Overruns stay capped, by David's choice:** "if they forget to start the next cycle they can
log time manually. If done the other way around they might step away or forget but the log
will be made regardless."

## 2026-10-16 — One clock: MySQL is told PHP's offset, and the studio runs on Pacific time
**Decision:** `includes/db.php` runs `SET time_zone = '<PHP's current UTC offset>'` on every
connection, and `config.example.php` names `America/Los_Angeles` (David: "the clock should be
based in PST and it should allow you to log hours even after 6 pm").
**Why:** PHP followed config.php while MySQL followed the host clock, six hours apart. In the
evening MySQL's NOW() was already tomorrow, so `log_time()` refused timer and pomodoro hours as
"in the future". An offset rather than a zone name because shared hosts rarely load MySQL's
timezone tables; it is recomputed per request, so daylight saving moves both clocks together.
**Consequence:** TIMESTAMP columns (created_at etc.) now display in local time. DATETIME values
written with NOW() before the change keep the host time they were written with. The live zone
is whatever the server's config.php says; changing it to Pacific is an edit there, not here.

## 2026-10-16 — Setup to active needs a sign-off, and the kick-off is kept for the postmortem
**Decision:** choosing *active* for a project in setup (list dropdown, or Finish in the wizard) opens
`kickoff.php`, which warns, then freezes the setup and emails everyone ticked under *Who signs off*
(Tim and Jorik). The project stays in setup until all of them approve, then turns active by itself and
writes the per-step baseline. Either can *Ask for changes* with a note. *Kick-off vs now* compares the
frozen picture with today, linked from the list once a show is in postmortem. Migration 049,
finance_install_08.sql. David asked for this in those words the same day.
**Settled with David the same day:** (1) who signs off is chosen in a pop-up when sending, pre-ticked
from *Who signs off* (a tick on a login, admins and editors only); (2) the email link needs a login,
and the login now returns you to the page you were sent to; (3) the snapshot is taken when it is SENT,
and **the project is frozen while it waits**: every save on its setup screens is refused
(`KICKOFF_FROZEN_SCRIPTS`, includes/auth.php), and renaming it on the list too. *Ask for changes*
unfreezes it; sending again takes a new snapshot, which replaces the old one as the kick-off;
(4) either signer can ask for changes with a note; (5) an administrator can approve on behalf of
whoever has not answered, with a reason, and everybody involved is emailed that the original state
is being set that way; (6) the money is frozen in the finance database, so only an administrator can
send a project for sign-off, since only they can read it.
**Why a snapshot and not copied tables:** the snapshot is built by one function, `kickoff_snapshot()`,
which the comparison runs again today, so both sides are measured the same way. Copied tables would
break the first time a column is added. Cost: a figure nobody put in the snapshot in 2026 cannot be
compared later. **No planning status**, unlike the 6 October plan: David's message keeps it setup
until signed off. Projects already active (MSD, FCM) have no kick-off and the screen says so, rather
than recording today's numbers as an original.

## 2026-10-16 — A Supervisor role: an artist who consults Timeline and Staffing, edits Shots
**Decision:** a fourth role, `supervisor` (migration 047). Everything an artist can do, plus
sees a project's Timeline and Staffing tabs (read-only) and edits its Shots. David: "a
supervisor role who has all the artist permissions but adding the timeline, staffing, shots",
then "Supervisors can't edit timeline or staffing, it's more for them to consult." Artists
and Bookings were also asked for; both were already open to every logged-in account to read.
**Why this shape:** `can_edit()` stays false for a supervisor, so every other screen keeps
the artist's answer without being touched. Shots asks `can_edit_area()` instead (Timeline and Staffing ask it too, and get no),
and the write guard in `require_login()` / `require_login_json()` lets a supervisor's POST
through only for the files named in `SUPERVISOR_SCRIPTS` (includes/auth.php). Same
allow-list idea as `artist_may_post()`: a screen added later is refused by default.
**Consequence:** no money for supervisors. Rates and quotes on Staffing go through
`money.php`, which stays admin-only. Schedule, Dashboard and a crew member's own page stay
artist-level until somebody asks. Letting supervisors edit an area later is one line in
`SUPERVISOR_SCRIPTS`, for any screen that already asks `can_edit_area()`. The JSON write guard now also refuses during a preview,
which it should always have done (the HTML guard already did).

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

## 2026-10-16 — Every card list has a Project dropdown, filed or not
**Supersedes the "Filed rows keep plain text and get no picker" part of "Filing is per card,
from a dropdown, not one bulk button" (2026-10-12).** The per-card choice stands.
**Decision:** the Project cell on Studio Tasks and on My tasks' Tickets table is a dropdown on
every row an editor sees, filed or not, with the Studio listed first. Picking a show files an
unfiled card or moves a filed one. The dropdown, the list of destinations and the handler live
once in `includes/tickets.php` (`ticket_project_picker()`, `ticket_file_targets()`,
`refile_ticket_from_post()`), and both screens call them.
**Why:** David, 2026-10-16, pointing at "#38 Explore method to quantify team workloads" reading
"no project" as plain text on My tasks: "for the tasks we need to always have a drop down menu
available so we can classify the tasks to any project or studio wise." My tasks had no way to
file at all, and an unfiled card there also has no status, Log box or timer, because all three
need a show. Filing from the row is what makes it usable.
**Why filed rows get one too:** the 2026-10-12 entry kept them read-only because they belong to
a board. But a card filed on the wrong show is the mistake that entry warned about, and it
should be fixable from the same list that filed it, not only from the card.
**Consequence:** moving a FILED card asks first, because it renumbers the card and clears its
shot, asset and step; filing an unfiled one does not, because it loses nothing. There is no way
back to "no project" from the dropdown: `move_ticket_to_project()` only moves onto a show.
Artists still see plain text, matching the card page's Move box, which is editors-only.
Every move is written into the card's own thread, as the card page already did.

## 2026-10-17 — The Plaky import no longer reads "Time spent"
**Supersedes "The import stops asking what unit time is in" (2026-10-05)** and the "`Time spent`
is wanted" answer in `PLAKY_EXPORT.md`.
**Asked by David:** *"let's delete the time spent column from the system, we're not going to use
it anymore"*, pointing at the import's warning "This file has no Time spent column".
**Decision:** the importer ignores the column whether or not the file carries it. It is gone
from `PLAKY_OPTIONAL_COLUMNS` (so a file without it is no longer warned about), from
`plaky_run()` (which no longer writes `progress.time_spent_minutes` on insert or update), and
from the upload and mapping screens ("time read as hours", the hours-not-minutes note).
`plaky_time_to_minutes()` and the `time_unit` session/mapping key went with it.
**Why:** time is logged in Anima now, per day, into `time_logs` (the Log box and the Pomodoro
timer). A second, Plaky-fed running total was a second source for one number.
**What stays:** `progress.time_spent_minutes` and whatever earlier imports wrote into it. The
import neither fills nor clears it, so the screens that read it (dashboard "Time logged",
person, team, item, My tasks' "imported" note) keep showing those old figures. Dropping the
column or hiding those figures is a separate choice, not taken here.

## 2026-10-17 — Freelance or Vendor is a fact about the person, kept in the artist database

**Asked by David:** *"in the staffing section we need to include two types of collaborators:
Freelance (by default) and Vendor, for whenever we bring vendors to the projects."* Then, after a
first cut stored it per position (migration 050): *"I think the type belongs to the person so we
can identify that properly... include the column for freelance and vendor in the artist database
and add freelance to all the current registries. The type in staffing should appear as a capsule
inline same as the contract and you can edit it in the detailed view."*
**Decision:** migration `051` adds `hires.collaborator_type` (`freelance` default, so every
existing record reads Freelance), carries over anyone marked Vendor under 050, and drops
`crew_lanes.collaborator_type`. Edited on the **Artists** grid (a Collaborator column, saved
across every record of the person like the other fields) and on the **person page** (Details →
Collaborator). Staffing shows it as the first capsule on the contract line of a filled position,
to everyone, since it is not money. The Staffing export writes "(Vendor)" after the name; the
Artists export adds a 13th column, Collaborator, which the importer reads when present.
**Matching a crew member to their artist record:** by `hires.crew_id`, or by name. Somebody with
no record (a studio put in a slot from the crew list) reads as Freelance; choosing Vendor on their
page creates a project-less "lead" entry for them in the artist database to hold it.
**Given up:** a vendor on one show is a vendor on every show; an artist booked through a vendor
once and directly later is switched by hand.

## 2026-10-19 — The Editor role is displayed everywhere as "Production"
**Decision:** the role stored as `editor` now reads "Production" in every UI label. The stored
name, the permission checks and the access are unchanged — this is a display rename only.
**Why:** David's team calls that function Production, not Editor. Renaming the stored role would
have touched every `can_edit()`-shaped check for no benefit and real risk.
**Consequence:** code and migrations still say `editor`; anyone reading the database sees `editor`;
only on-screen text says Production. (Shipped with migration 052.)

## 2026-10-19 — Artists & supervisors may log overhead (studio) time
**Decision:** the Studio-time panel lets artists and supervisors log their own overhead hours
(meetings, desk, reviews) against their role's categories, a deliberate narrow opening of otherwise
view-only access.
**Why:** David wanted the leadership and the crew to be able to record where non-task time goes,
per role, without making artists editors.
**Consequence:** each role sees only its own buckets; supervisors' "Planning" merges into the shared
"Planning & scheduling" bucket (David confirmed). Needs migration 052; dormant until run.

## 2026-10-19 — A task set to "Final" archives instantly
**Decision:** setting a card's status to a status literally named "Final" archives it immediately;
other statuses keep the existing timed archive.
**Why:** David wanted finished work off the active boards at once, not after a delay.
**Consequence:** keyed on the status *name* "Final"; reverting the status un-archives. Fires
everywhere status is set. No migration.

## 2026-10-20 — Snooze is a separate wake-up date, not a change to the due date
**Decision:** snoozing a card sets `tickets.snoozed_until`, a wake-up DATE that hides the card from
every active list until that day. It does **not** move the due date.
**Why:** David's stated default — a snooze is "deal with this later", not "it's due later". The two
are different facts and conflating them would quietly rewrite deadlines.
**Consequence:** Production (editor) + admins only. A card snoozed to today/past is shown; only a
future date hides it. The one place that lists which active lists hide snoozed cards is
`snooze_exclude_sql()` in `includes/tickets.php` (mirrors `overhead_exclude_sql()`). Needs migration
054. **Open alternative:** make snooze move the due date instead, if David asks. Originally numbered
053, renumbered to 054 after colliding with `053_mytasks_seen.sql`.

## 2026-10-20 — The My-tasks badge counts active cards; "new" means created since last view
**Decision:** the top-bar "My tasks" shows a count of the viewer's active (owned, not finished, not
archived) cards, and the badge changes colour when a card is new **since they last opened My
tasks**, clearing when they open it. Transcript/task creation emails **one summary per assigned
person**, not one per card.
**Why:** David wanted a nudge when something new lands, and one tidy email rather than a flood.
**Consequence:** "new" keys off card *creation*, so reassigning an old card does not light the
badge (can extend if asked). The count works without a migration; only the "new" colour needs
`053_mytasks_seen`. Emails can't be verified from the cloud env — confirm on the live site.

## 2026-10-21 — Project visibility reuses staffing; it scopes the list and the hub, not sub-pages
**Decision:** a non-admin sees only the shows they are **staffed on** (`crew_projects` +
`users.crew_id`); admins and Production see all; supervisors and artists are scoped. "See-all" is
overridable via `config.php` (`project_visibility_see_all`), with admins always forced in. The guard
is applied to the project **list** and the project-**hub** door only.
**Why:** David asked that people only see their own shows. Reusing staffing means no new "who sees
what" table and no data to keep in sync.
**Consequence — a deliberate known boundary:** direct deep-links to sub-pages (`shots.php?project=X`
etc.) remain reachable, matching the app's existing "hide the tab, don't lock the door" posture for
viewers. Locking every sub-page was offered and not built. **Prerequisite:** every real login needs
`users.crew_id` set on the Users page, or a scoped user sees nothing. No migration for the feature
itself.

## 2026-10-21 — "View the app as a specific person" extends preview; read-only; audit best-effort
**Decision:** an admin can borrow a named person's role + crew link for the session and see their
world — My tasks, nav badge, scoped project list — a step beyond the existing "preview as a role".
It is **read-only** (the POST guard is unchanged, `in_preview()` stays true), **admin-only**, and
never another admin / an inactive user / oneself. Each start writes a `preview_audit` row.
**Why:** David: "can we admins see Anima as specific users instead of" just a generic role — to
check exactly what one person sees.
**Consequence:** the seen-at stamp is skipped during a preview, so an admin peek never clears
anyone's "new" badge or last-seen. The audit insert is **best-effort** — the feature works whether
or not migration 056 (the audit table) has run. Originally numbered 055, renumbered to 056 after
colliding with `055_last_seen.sql`.

## 2026-10-21 — "Last seen" tracks real activity, not last login
**Decision:** add `users.last_seen`, stamped on every authenticated page load and API call via the
shared auth gates (`touch_last_seen()`), throttled to once per ~5 minutes per session. The Users
page shows `COALESCE(last_seen, last_login)`.
**Why:** the column had shown `last_login`, which only refreshes on a fresh sign-in; a session lasts
a week, so an active user's date sat stale for days.
**Consequence:** old values self-heal the next time each person opens a page after migration 055
runs; until then a logged-in user falls back to their login date (so nobody active reads "never").
Needs migration 055; dormant and harmless until run.

## 2026-10-21 — Repo documentation is the single source of truth for handoff
**Decision:** the repository's Markdown docs are kept current enough that a fresh Claude account
with no chat memory can pick the project up from the repo alone. A handoff pack was added —
`docs/HANDOFF.md` (current state), `docs/MIGRATIONS.md` (the migration ledger + what's pending), and
`docs/CHANGELOG.md` (features, PR by PR) — linked from the top of `START_HERE.md`.
**Why:** David is moving development to a different Claude premium account and needs all relevant
context in the repo, not in a conversation's memory.
**Consequence:** when a status banner in `START_HERE.md` or a build log in `docs/ROADMAP.md`
disagrees with `docs/HANDOFF.md`/`docs/MIGRATIONS.md` about what is live, the handoff pack wins; the
older files are kept for their reasoning. Keep `docs/DECISIONS.md`, `docs/HANDOFF.md` and
`docs/MIGRATIONS.md` current as state changes.

## 2026-10-21 — Supervisors may press Hire; every hire emails the administrators
**Decision:** a supervisor sees and presses **Hire** on Staffing, and nothing else there: the stage
dropdown, adding candidates and filling or emptying a slot stay with Production. Allowed through a
new `SUPERVISOR_ACTIONS` allow-list in `includes/auth.php` (script + the posted `action` pattern,
`crew.php` / `hire:N`), read by `supervisor_may_post()`; the button asks `can_hire()`. **Every hire,
whoever presses it**, then emails the active administrators (not the person who pressed it) who
hired whom, into which position, on which show, with a link to Staffing:
`notify_admins_of_hire()` in `includes/mailer.php`, called from `hire_candidate()` after the commit.
**Why:** David, using Anima as a supervisor: they "should be able to see the hiring button and click
hire. That should trigger an email for the admins stating who and whom hired the slot."
**Consequence:** somebody who cannot see rates never reaches `seed_terms_from_quote()`, so a quoted
rate is NOT carried onto the new person's terms when a supervisor (or Production) hires; the email
says so and points at Finance management. Admin addresses are the login's own email (migration 049)
falling back to the linked crew member's; an admin with neither is named on screen as not reached.
A mail failure never undoes a hire. Staffing deep-links are not locked per project (the existing
posture), so a supervisor could hire on a show they can only reach by URL.

## 2026-10-21 — Outside Production, own work moves only to In progress or Pending review
**Decision:** on My tasks, artists and supervisors may set their own tasks and tickets only to a
status named **In progress** or **Pending review** (matched by name, ignoring case and spacing:
`SELF_SERVE_STATUS_NAMES` / `status_self_serve()` in `includes/statuses.php`). The dropdown offers
just those, plus the current status greyed so the box still says where the work stands; both save
handlers refuse anything else, so a hand-made POST cannot get round it.
**Why:** David: artists "can change the status of their tasks only to In Progress, Pending review".
Approving, finals and retakes are Production's call.
**Consequence:** by name because statuses are each show's own rows; a show that spells them
differently offers its artists nothing to pick until the names match. Applied to supervisors too,
because they carry every artist permission. Moving back to "not started" is Production's.

## 2026-10-21 — Production removes any time entry on their own list
**Decision:** a Production user may remove any entry on their own My tasks time list, including one
somebody else typed for them; `delete_time_log()` takes the asker's crew id for this. Artists and
supervisors still remove only entries they typed themselves; other people's hours stay an admin's.
**Why:** David asked to enable removing logged time for Production, and chose "only on their own
list" over a new screen of everybody's entries.
**Consequence:** no new screen; My tasks remains the only list of time entries.

## 2026-10-21 — Dates & headcount shows the days between a step's dates
**Decision:** a **Days** column beside Dates: the calendar days from start to deadline, both ends
counted, with the working days (weekends and studio holidays left out) under it. *(Same day, after
it went live, David: it "should only include business days". Renamed **Business days**; it now shows
the working-day count only, and the calendar count is gone.)* Computed live in
`assets/wizard.js` from the same `workingDays()` the verdict uses; it took its width from "Is that
enough?".
**Why:** David asked for "a column that extracts the total days in between the start and end dates".
**Consequence:** shown blank when a date is missing or the dates are the wrong way round (that row
is already marked).

## 2026-10-21 — My tasks Tasks/Tickets gets column filters
**Decision:** the Tasks/Tickets list on My tasks gets the shared `assets/gridtools.js` filters on
the five columns David marked (Project, Type, Status, Priority, Due), each a dropdown of the values
on the list, plus the count row. Ref and Title keep a sort button and no filter, through a new
`noFilter` option in gridtools. The production-task and Mentioned lists stay sort-only.
**Why:** David: "make sure this section is converted in filters so we can filter". This reverses
the 2026-10-05 "sort only" call for this one list.
**Consequence:** the Due cell also holds the Snooze date box, so it is read from its `.due-date`
span; a dash reads as no due date ("(nothing)").

## 2026-10-21 — Viewing as a person saves; previewing a role stays read-only
**Decision:** while an admin views the app as a specific person, saves go through. They act as
that person, within that person's role (the same role checks the person would get), on that
person's own work. The session's real user id and display name are never swapped, so every record
names the admin as the one who did it (`entered_by`, ticket threads, stage logs). A **role** preview
("as an artist", nobody in particular) still saves nothing. One gate decides this:
`preview_blocks_saving()` in `includes/auth.php`, read by `require_login()` and
`require_login_json()`. Linking a login to a crew member on My tasks is refused during a person
preview, because it writes to the real admin's login.
**Why:** David: "allow admins to make changes when they're seeing anima as other users". This
reverses the 2026-10-21 "read-only" part of the view-as decision, and the 2026-09-02 "a preview is
always read-only" rule for person previews.
**Consequence:** admin-only powers stay off during a person preview, because `is_admin()` answers
for the borrowed role, and no money screen opens. Starting a timer or logging time while viewing as
someone starts or logs it for that person, recorded as entered by the admin. The banner now says
"Changes save, as their work, and are recorded as made by you."

## 2026-10-21 — The expense projection counts whole days, rounded up, on every project
**Decision:** each row's days (one person's share of a step) are rounded UP to a whole day in
`expense_projection()`, on every project. Months, the projected cost of day- and hour-rate rows,
department subtotals, the grand total, the xlsx export, the person page and the kick-off snapshot
all follow, because they all read that one function. The unrounded share is kept as `days_exact`
and shown when you hover over the Days cell.
**Why:** David: "all these days need to be rounded up in the expense projections ... in all
projects as a rule". Nobody is booked or paid for 2.75 days.
**Consequence:** projected costs go UP on almost every project, by up to one day's rate per person
per step. Per-second rows are unchanged because they are priced on footage. A project signed off
at kick-off before today will show the rounding as a difference on "Kick-off vs now", because its
snapshot stored the fractional days. Only the projection rounds: Dates & headcount, the schedule
and the budget pool still work in fractional days.

## 2026-10-21 — Budget pool: interactive donuts, unused days, and a status pie per step
**Decision:** on the Budget pool tab the days are drawn as a donut of **used** against **unused**,
both as segments you can point at, for the whole show at the top and for each step in its detail.
Hovering a segment puts its figure in the middle of the ring ("10 days unused"); each segment also
has a `<title>` for touch screens and screen readers. "Left" now reads **Unused** across the tab.
Beside a step's days donut sits a **status pie**: that step's shots or assets by status, with a
legend. It counts exactly the rows the cards count, through the new shared `progress_in_scope_sql()`
(moved out of `dashboard_steps()`) and `step_applicable_count()`. N/A items are left out, and
anything without a status reads "not started". The helpers are `pool_donut_svg()`,
`pool_days_donut_svg()` and `pool_status_slices()` in `includes/budgetpool.php`; they replace
`pool_ring_svg()`.
**Why:** David: "make sure we show the remaining days unused and those pie charts should be
dynamic/interactive. When you hover the mouse they should say how many days", then "add all the
assets or shots in a pie chart next to the days used that show how many assets/shots are in which
status".
**Consequence:** the status pie is per step only. A pie for the whole show would add up items from
different steps, which are not the same work.

**Same day, layout (David: "make sure the pie charts are aligned ... the status pie chart should be
to the right of the days used"):** a step's detail now reads, left to right: the days donut, its
words, then the status donut with its colour key beside it rather than under it. Both rings sit
on one line (`.pool-pair` in `assets/style.css`).

## 2026-10-21 — Budget pool step detail lists who is on it, against their share of the pool
**Decision:** under the donuts in a step's detail, a table with one row per person on the step:
- day rate;
- budget: their slot's window in working days, and that times their rate;
- used, unused, and what they have invoiced against the position;
- their assigned items done;
- a reading: "over budget by N days" in red, otherwise the card's own days-against-progress
  comparison (`pool_pace()`) on their items.
Worst first, unfilled slots last. Totals row underneath. Built by `pool_step_people()` in
`includes/budgetpool.php`, from the same lanes, windows and logged-or-plan switch as the card, so
the rows add up to it.
**Why:** David: "below the pie charts we should see a list to show who is working on it and how
much has been spent on that person vs. budget, so management can spot who is over/under budget at
a glance".
**Consequence:** while a show has no logged time, used days come from the plan (their window up
to today), which can never pass their budget. So "over budget" only appears once hours are logged,
and the note under the table says which source is in use. Per-second people are left out, because
they have no day budget. Two slots held by one person are one row.

## 2026-10-22 — The Board: one studio-wide screen, built as a view over rows that already exist
**Decision:** `board.php` is a new top-level screen (a button in the top bar, before My tasks) with
its own show picker, not a tab inside a project. David: "a big button at the top will give you
access to the board where you can switch between projects so you don't have one per project and
just one place to take a look at the whole thing."

It draws Plaky's shape onto what this schema has held since v1: a Plaky **group** is an episode, an
asset type, or a department of whole-show steps; an **item** is a `shots`/`assets` row; a
**subitem** is a `progress` row (`UNIQUE KEY uniq_step_item`); **status** is the project's own
`statuses`; **assignee** is `progress.crew_id`; **due date** is `schedule.end_date`; **frames** are
`shots.frames`; **link** is new (migration 057). So nothing migrates on the day Plaky is switched
off — the importer stops and people set these statuses by hand, which is exactly what the
2026-08-11 status-model decision was bought for.

**Settled with it:**
- **One due date, not two** (David: "let's use the built in one"). Typing a date on the board
  writes `schedule.end_date` and sets `is_manual = 1`, the same thing dragging its bar does, so the
  board and the timeline cannot disagree and Regenerate will not overwrite it. Plaky's due date
  agrees with nothing.
- **A shot's own status is DERIVED** (`board_item_status()`), never stored: finished when every
  applicable task is approved, in progress when any has started, otherwise not started. N/A rows
  leave the reckoning. Plaky makes somebody maintain that cell by hand.
- **Frames are read-only here**, edited on the breakdown. One writer per number.
- **Artists get the same two statuses as My tasks**, through the same `status_self_serve()`, with
  the rest listed and disabled so the show's vocabulary stays visible. Enforced three times: the
  option is disabled, `artist_may_post()` allows only `set_status` on `board_api.php`, and the API
  refuses a row the asker is not named on.
- **`require_login_json()` now consults `artist_may_post()`**, which it never had to before: no API
  an artist could reach existed. A JSON gate that disagreed with `require_login()` would allow
  somebody through a form and refuse them through fetch().
- **The whole show renders, collapsed above 40 items.** Every task row is in the page and hidden by
  class, so expanding fetches nothing; a 188-shot show is ~1,500 rows, which is where lazy loading
  starts to earn its keep.
**Consequence:** `assets/board.js` was already taken by the tasks kanban, so this screen's script is
`assets/board-screen.js`. The readiness view that folds in the Production tracking grid, the task
detail panel and comments on a task are **not** in this first cut.

## 2026-10-22 — Two bugs the board harness caught, worth keeping
**`display: grid` beats `[hidden]`.** The search set `hidden` on a row and the row stayed on screen,
because an author rule (`.board-row { display: grid }`) outranks the user agent's
`[hidden] { display: none }`. Anything given a `display` needs its own `[hidden]` rule beside it.
**A rollback must capture the old value before the request, not after.** The first cut re-remembered
each cell immediately after calling save(), which ran before the answer came back — so a refused
status "rolled back" to the value that had just been refused, leaving the board showing something
the database did not hold. `save()` now takes the previous value as the request leaves and only
overwrites it once the server has agreed.

## 2026-10-22 — Board groups are the studio's three phases, not "Shots" and an asset type
**Decision:** a group on the Board is a DEPARTMENT — Pre-production, Production, Post-production —
with the episode or asset type shown beside it as a chip rather than instead of it. David: "these
names should be the departments Anima already uses like Pre-Production, production and
post-production." Assets are pre-production, shots are production, and a whole-show step takes the
department it names (falling back to post-production), which is the same three-way split the
tracking grid has used since 2026-08-14 and the same subtotal boundary the expense projection uses.
`board_dept_name()` normalises whatever case somebody typed; `board_dept_rank()` orders them.
**Why:** one vocabulary across the board, the grid and the budget, so the three read a show the same
way round.
**Consequence:** ⚠ **an item takes a PHASE, never a step's department.** A shot passes through eight
departments and cannot belong to one, so only a whole-show step — which really is one piece of work
— reads `pipeline_steps.department`. The episode stays as a chip because a 188-shot show collapsed
into one Production group would lose the division that makes it readable.

## 2026-10-22 — Shots start closed, and each group carries its own expand/collapse menu
**Decision:** every shot and asset renders collapsed (David, on the live site: "let's have the items
... always closed by default"), and each group header gets a menu offering expand or collapse for
that group alone. The whole-board buttons stay.
**Why:** opened, MSD alone is 150 task rows, and the screen is opened to find ONE shot. The group's
own arrow hides a group but cannot open the tasks inside it, which on an episodic show is the thing
actually wanted.
**Consequence:** expanding through the menu also un-shuts the group, because expanding rows inside a
collapsed group changes nothing visible and reads as a broken button. Every task row is still
rendered and hidden by class, so expanding fetches nothing; that is where a 188-shot show would
start to want its tasks loaded on demand.

## 2026-10-22 — A shot carries a bar of its tasks' status colours
**Decision:** each shot and asset row on the Board shows a stacked bar made of its own tasks'
status colours, in the space its name leaves. David: "the more complete/approved the more the bar
is going to be filled with that color."
**It fills from the LEFT, most finished first** — approved, then delivered, then blocked, then
merely started, then nothing recorded — so it reads as a progress bar rather than a chart to
decode. The order comes from each status's STRUCTURE (`maps_to`, `is_blocked`), never its name.
N/A leaves the bar entirely: a step an item does not pass through is not work waiting to be done,
and counting it would leave the shot looking unfinished for ever. `board_item_bar()` builds it and
`redrawBar()` in board-screen.js rebuilds it from the same facts when a status changes — the two
must move together or a bar would re-sort itself the moment somebody touched a status.

## 2026-10-22 — The Board's headings stick, and three columns filter
**Decision:** the column headings and a filter row ride at the top of the page, and Status,
Assignee and Due each carry a dropdown. Frames belongs to the shot, and Link and Notes are filled
on 6 and 2 rows of MSD's 147, so a dropdown on either would be furniture. A count says how many
tasks match, and Clear appears only while something is filtering.
**Why:** 21 shots expanded is 170 rows; by the time you have scrolled to SH150 the column you are
reading has no heading on it and the filter that got you there has gone too.
**Consequence:** the filters read DATA ATTRIBUTES ON THE ROW, not the controls inside it, so a row
filters the same whether its cells are editable selects or plain text for an artist — and every
save updates them, or a task moved to Final would sit on under a filter for In progress. A shot
whose tasks match is opened, and **the board's own expand state is restored when the filters are
cleared**, including any shot somebody opened or collapsed by hand while filtering: without that,
filtering, pressing Collapse all and then Clear re-opened everything, undoing a choice the person
had just made.

## 2026-10-22 — A status says whether work has BEGUN; maps_to never could
**Decision:** `statuses` gains `counts_as_started` (migration 058), and a shot on the Board reads
**In progress** as soon as one of its tasks carries a status marked begun. David's own rule, after
seeing SH160 read "Not started" while its BG Layout said In progress: in progress "when any of the
tasks inside has other status than Waiting to Start or Ready to Start".
**Why the first version was wrong:** it asked `maps_to`, which says whether a status counts as
delivered or approved — not whether anybody has touched it. Waiting to start, Ready to start, In
progress and Retake all map to `none`, so all four read as untouched and a shot stayed "Not
started" until something was finished. **The database could not answer the question**, so the
vocabulary gains the fact, exactly as it gained `is_blocked` and `is_excluded` before it.
**Consequence:** `status_has_begun()` in `includes/statuses.php` is the one reader. Until 058 runs
it falls back to `STATUS_NOT_BEGUN_NAMES`, an explicit list of spellings (the rule
PLAKY_COLUMN_ALIASES follows), so **every board is right today** and the migration only makes it
editable. Replayed over MSD's seven statuses, the answer is identical before and after. The column
defaults to 1, because almost every status a studio invents means work is under way; the exceptions
are the one or two at the start, which the migration seeds. A show whose opening state is spelled
some other way reads as started until somebody unticks it — the safe direction, since a shot saying
In progress early is a smaller lie than one saying Not started while three people work on it.

## 2026-10-22 — The bars: on the groups too, interactive, and lined up
**Decision:** three refinements to the Board's progress bars, all David's, same day.
- **A bar on every group header**, built from EVERY task in the group rather than its shots' bars
  averaged — averaging percentages weights a 2-task deliverable the same as an 8-task shot, and a
  phase would read finished with half its work outstanding.
- **A panel on hover**, listing each status in the bar with its count and a total. ⚠ A segment's own
  tooltip is not enough: the bar is 7px tall and a slice holding one task of eight is a few pixels
  wide, so hovering it exactly is a game and the native tooltip waits a second before saying
  anything. The bar is focusable, so the panel is reachable from the keyboard; the per-segment
  titles stay for touch, where nothing hovers. The bar also thickens on hover.
- **The bars line up down the column**, because the label beside them is a fixed width
  (`.board-item-label`). Left to itself a bar starts wherever the name ends, so a column holding
  FutsalInt01 and MadridStreetExt03 had its bars stepping in and out. A longer name is clipped and
  keeps its full text in a tooltip.
**Consequence:** `board_bar_html()` renders both bars from one place, and `drawBar()` rebuilds both
parts when a status changes — the panel is built as nodes, never as a string of HTML, because a
status is named by a person and goes straight into the page. Alignment is off below 900px, where
the row stacks and there is no column to line up down.

## 2026-10-22 — Phase 2: the task panel opens OVER the board, and shares its controls
**Decision:** clicking a task's step name opens `board_task.php` in a panel over the board, never a
page. David: "instead of taking you to another page it should just pop up in the same screen so you
can open, adjust, close and open another one."
**⚠ IT RETURNS MARKUP, NOT JSON.** Every control in the panel is built by the same helpers the board
row uses — `board_status_cell()`, `board_crew_cell()`, `board_due_cell()`, `board_text_cell()` —
with the same classes and the same three data attributes. So the delegated handlers already in
`board-screen.js` save them, nothing about saving is written twice, and an artist gets the same two
statuses there as on the row, because it is literally the same code.
**The cost, and what pays it:** a cell now exists twice while the panel is open. `mirror()` keeps
them in step after every save, and refreshes the row's filter attributes, its shot's bar, its
derived status and the group's bar from whichever copy was touched.
**The panel adds what the board has no column for:** the version label, hours logged, and the task's
own history from `progress_log` — which names its SOURCE, because "the importer moved 140 shots"
and "somebody moved 140 shots by hand" look identical in a list otherwise.
**Consequence:** it is a real dialog — `aria-modal`, focus moves in on open and back to the button
on close, Escape and the veil close it. Comments and @mentions are phase 3 and are not here.

## 2026-10-22 — Never compare a whole className to decide two elements are the same cell
`mirror()` first matched the row's control to the panel's by comparing `className`. `save()` adds
`is-saved` to the cell it has just written, one line before calling `mirror()` — so the two copies
never looked alike, and the row silently kept the old value while the panel showed the new one.
Matched on the control's KIND now (`board-status`, `board-crew`, `board-due`, `board-text`), which
is what actually identifies it. **A class string is a set, not an identity.**

## 2026-10-22 — Every bar on the Board is the same bar, in the same column
**Decision:** a group's bar and a shot's bar start at the same x and are the same width. David:
"make sure you align all bars, department bars and container bars."
**Three things were making them disagree**, each worth knowing because each is a trap:
- **A stray `margin-left: 4px`** on the group bar — exactly how far out of line they sat.
- **A 1px border.** The rows sit inside a bordered panel and the group header does not, so
  everything in the header began one pixel to the left. The header now carries the same border in
  `transparent`, which lines it up and draws nothing.
- **`flex-grow` in two different layouts.** A group's bar lives in a flex row spanning the page, a
  shot's in a grid cell, so "grow to fit" gave 320px against 146px. Both take a fixed basis now and
  are allowed to shrink together.
**Consequence:** the label beside the bar is one fixed width for both rows (`.board-item-label`,
`.board-group-label`), so changing one means changing the other — said in the stylesheet. The name
gives way first and the count and chip never wrap, which is what pushed "26 items" onto two lines
and shoved that group's bar right. The other columns were trimmed to what their contents need so
the whole board still fits a 1280 laptop without scrolling sideways.

## 2026-10-22 — The Board's Due filter answers the questions a producer actually asks
**Decision:** Overdue, Today, Yesterday, This week (Mon to Fri), Due in the next 7 days, a specific
date, a range, Has a date, No date. David's list.
**Consequence:** the two date boxes appear ONLY when their own choice is picked — two empty boxes
sitting in the filter bar read as something you must fill in before the board will answer. A
specific date with nothing picked filters nothing rather than hiding the whole board, and a range
with only one end set is open at the other.
⚠ **The week is built from local date parts, never through UTC.** A 'YYYY-MM-DD' handed to
new Date() is read as UTC and comes back a day early west of Greenwich (the timeline's rule 2),
which here would quietly shift the whole week. Sunday belongs to the week that has just ended,
which is how a studio reads "this week" on a Sunday evening.

## 2026-10-22 — A global button margin is what breaks a toolbar's alignment, not its heights
The bulk-rate bar's select, rate box and two buttons were all exactly 34px tall and still sat on
different lines — David drew a line along their bottom edges to show it. The cause was
`button { margin-top: 16px }`, written for the stacked forms this stylesheet grew up with. On a
flex row that margin is neither collapsed nor ignored: it pushed each button 8px below the fields
beside it and made the Apply wrapper 50px tall. `margin: 0` on the bar's controls fixes it.
**The general shape:** when controls of equal height do not line up, look at their MARGINS before
touching their padding or height — equal heights prove nothing about where a box sits.

## 2026-10-22 — A bar for the whole show, notes on the containers, and the buttons that stay put
Three more from using the Board, all David's.
- **A full-width bar under the title**, over every task on the show: the same reckoning a group's
  bar makes, one level up, answering the same hover. Thicker than the row bars, because it is the
  one read from across the room.
- **A shot or asset carries its OWN note.** ⚠ `shots.notes` and `assets.notes` have been in the
  schema since v1 and describe the THING — "the client hates this angle" — while `progress.notes`
  describes one step's work on it. The board had a column for the second and nowhere for the first.
  **The absence of a step id is what tells the two apart**, in the markup and in board_api.php,
  which handles the container's note before it reads a step, because there is none. Production
  only. A whole-show deliverable has no note of its own: its row IS a task, so the task's note is
  the note.
- **Expand all and Collapse all moved into the sticky header**, beside the filters. They sat above
  it, so on a long board they scrolled away exactly when a wall of open shots made them wanted.
  They span the three columns that have nothing to filter.

## 2026-10-22 — The Board went down: a batch edit that failed, and the edit after it that did not
**What happened:** `board.php` returned a white page the moment the project-wide bar shipped.
`board_data()` ended with `board_item_bar($allTasks, $statusMap)` and **nothing anywhere declared
`$allTasks`** — PHP passed null to an `array` parameter and the request died. Neither
`check_php_blocks.py` nor `php -l` can see that: the file parses perfectly.
**How it got in, and this is the part worth keeping:** one scripted edit made six replacements and
threw on the fifth, so it wrote NOTHING — correctly. A second script then applied the pieces that
CONSUMED those six, and that one succeeded. Each script behaved properly on its own; together they
shipped a consumer with no producer.
⚠ **A failed batch edit does not fail safely if a later edit assumes it worked.** When one aborts,
re-verify every change it was supposed to make before writing anything that depends on it —
lesson 14 says assert every replacement and lesson 16 says assert what came OUT; this adds: assert
it is still there before you build on it.
**What now catches it:** `tools/check_php_blocks.py` gained an eighth pass — variables read inside
a file that nothing ever assigns. It is crude and biased towards false alarms (a typed parameter in
a multi-line signature can trip it), because a name a human glances at costs seconds and a white
page costs an afternoon. It reports `$allTasks` on the broken file and nothing on the fixed one.

## 2026-10-22 — A timeline bar moves alone; the arrows stay
**Decision (David):** dragging a bar on the general timeline moves that step only. Steps that wait
for it stay where they are, and the dependency arrows stay drawn, turning red where the move broke
one.
**Why:** carrying every downstream step was Frappe Gantt's own behaviour, never a choice, and it sat
awkwardly with the 2026-08-31 rule that dependencies are drawn, not enforced. A drag that silently
re-dates six other steps, and saves them, is a bigger edit than the gesture looks like.
**How:** `dragBarsAlone()` replaces the chart instance's `get_all_dependent_tasks()` with one that
returns nothing, so the library's drag collects just the bar under the cursor. Instance-level, so
the detailed chart is untouched. Shifting a whole tail of the show now means dragging each bar, or
re-running the schedule.

## 2026-10-22 — Days off and engagement dates move the plan, per person, at the next run
**Decision:** a person's days off live in `crew_absences` (migration 060), one row per range, per
PERSON rather than per show, because a holiday is a holiday on every show. The scheduler reads them
together with the engagement dates on `crew_projects` (migration 024 left those unread on purpose
until this): a lane's tasks skip the days its person cannot work, and each item now goes to the lane
that FINISHES it soonest rather than the lane free soonest.
**Why that lane rule:** with nobody away the two are identical (ties break on free-soonest, then lane
number), so every existing plan comes out the same. With somebody away, "free soonest" would park a
shot on a lane whose artist is on holiday while a colleague sits idle.
**Past the end of an engagement:** when everyone on a step has finished on the show, the item is still
planned, on the lane that finishes first, and the step reports it. Dropping it would hide the work;
the person page already flags work outside somebody's dates.
**Deliberately not done:** the quota, budget pool and bookings still treat every working day as
available to everyone; a band (coverage below 100%) ignores absences; the timeline does not shade
them. Nothing moves until the schedule is re-run, and hand-placed bars never move.

## 2026-10-22 — The Board is called Spell
**Decision (David):** the screen that replaces Plaky is named **Spell**, in the top bar and on the
page, and lives at `spell.php`. `board.php` is kept as a 301 redirect carrying the query string,
because mention emails already sent link to `board.php?project=…&open=…`. Internal names
(`includes/board.php`, `board_api.php`, `board_task.php`, CSS classes) are unchanged: renaming
them changes nothing anybody sees and risks a missed reference.

## 2026-10-26 — Supervisors a show pays for are positions with dates, not steps
**Decision (David):** "positions like anim sup or cg sup ... won't have days per item or people, we
just need to add the start and end dates so they're reflected in the staffing and expense
projections." A new table, `supervision_positions` (migration 068), read by Dates & headcount,
Staffing and `expense_projection()` only (`includes/supervision.php`).
**Why not a whole-project step:** that worked as a stopgap but a step is read by the scheduler, the
quota, the Budget pool and the timeline, so a supervisor became a block of work on the timeline and
a pool card reading 0% done for the life of the show.
**Costing:** working days between the two dates, both counted, holidays out, times the person's rate;
a per-second rate is a mismatch and left unpriced. An empty position holds a placeholder crew row
named after it, so it can carry a rate before anyone is in it, exactly as a slot can.
**Unchanged:** the 2026-10-07 rule that the studio's own leadership is recovered by markup, not as a
line on each show. This is for a supervisor the show itself hires.
**Not built yet:** candidates on Hiring for these positions, invoices attaching to them (needs a
finance DB change), the staffing export.

## 2026-10-26 — A supervision invoice carries the position as a negative step id
**Decision:** an invoice for a supervision position stores `step_id = -<position id>` in the finance
database, with the title in `step_name`. Every reader already finds an invoice's row by
"crew + step", step ids are never negative, and `invoices.step_id` has no foreign key (it cannot,
across two databases), so the position needed no finance import. `project_positions()` in
`includes/finance.php` is the one list of what an invoice (or a rate on the Finance screen) may
name: slots and supervision positions together.
**Hiring:** `hires.supervision_id` (migration 069), the twin of `lane_id`; a record holds one or the
other. Hiring a candidate goes through the same `hire_candidate()`, which puts them in the position
via `supervision_assign()` (now safe inside the hire's transaction).
**Not changed:** an artist statement's "due" for a day rate is still days LOGGED, so a supervisor who
logs no time shows nothing due; flat-fee supervisors read correctly.

## 2026-10-27 — Setup seconds drive the money; breakdown frames are the breakdown's

**Asked by David:** *"Setup frame count should be used for expense projections, frame counts in the
breakdown should only apply to the breakdown."* He had the setup total at 63.7 s against 53 s on the
breakdown and could not tell which shots had moved, because `breakdown_set_frames()` wrote
`placeholder_seconds` (frames ÷ fps) on every frame edit and nothing recorded the old value.

**Decided:** `shots.placeholder_seconds` is set on shot setup only and is what the projection,
quotas, dashboard, scheduler and statements read. `shots.frames` is set on the breakdown (and by the
Plaky import) and is read by the breakdown, its export, Spell and the kick-off sheet. The breakdown
shows frames ÷ fps, computed, never stored. Existing values were left as they are: whatever the
breakdown wrote before today stays in the setup seconds until setup changes it.

## 2026-10-27 — Reviews run on two named statuses, and approving is the status move

**Asked by David:** the final internal review in Spell, as a top-bar button "similar to the task
button with a number of pending reviews". Who reviews what: *"Admins and production only versions
with their tasks as 'Ready for review', this will change to 'Complete'. Supervisors will review
'Pending review' and will change to 'Ready for review'."* Approve means the task becomes approved,
not a second sign-off.

**Decided:** `REVIEW_STAGES` in `includes/review_badge.php` names the two stages. Statuses are matched
by name (case and spacing ignored), as `SELF_SERVE_STATUS_NAMES` already is, because they are each
show's own rows. Approving writes `status_id` with `status_flags()`, notifies subscribers and logs
to `progress_log` with source `review`, the same as a board status change. A note is a review
comment on the current version (migration 065). Supervisors may post `approve|back` to `review.php`
only (`SUPERVISOR_ACTIONS`); `review_apply()` checks the task is in their stage's status on a show
they see. Whether "Complete" counts as approved for the charts is whatever the Statuses screen says.


## 2026-10-28 — The studio's money rules, stated once

**Asked by David,** at the start of the October development push: rules "we already know will be
true", written down so every session builds on them instead of rediscovering them.

**Decided:**
1. **Anima tracks budget against cost, not profit.** The finance screens already do (Margin became
   Budget variance; revenue left the screen on 2026-10-12). Nothing new should compute profit.
2. **The production budget pays for everyone who works on the show**, freelancers and salaried
   people alike (David, Tim, Renata, Junior). Salaried time on a project is cost, at an internal day
   rate. Diego is paid per job, like a freelancer. *Open: how a salary becomes an internal day rate
   and how salaried time reaches a project. Nothing in the code knows "salaried" yet; today a person
   costs only if they have a `crew_terms` rate.*
3. **Freelancers are paid for the days they work.** Their statement (logged time for the month, an
   invoice summary, their contracts and shows) is how they get paid; it will get its own screen.
4. **Everyone is paid in US dollars.** One currency for pay; no GBP. *Open: David asked how and when
   amounts become euros when rates change daily.* The code already stores a currency per rate,
   budget, invoice and expense, and flags mixed currencies without converting (2026-08-18).
5. **Only Jorik can raise a budget.** The budget is fixed once the kick-off is signed off; after
   that only he changes it, by hand. Being built on its own branch (see CHANGELOG when it ships).
6. **Artists log their time every day.** Statements, actual cost and the scheduler may rely on daily
   logs, and a missing day is a gap to chase, not zero work.

## 2026-10-28 — The money rules' open questions, answered by Tim and Jorik

**Answered by Tim** (with Jorik), replying to the rules recorded earlier today.

**Decided:**
- **No personal salaries in Anima.** Jorik supplies **one blended internal day rate** for all
  salaried staff (total salaried cost ÷ number of people ÷ ~22 days). Every salaried person's
  project time costs that one rate. Anima stores the rate, never a salary.
- **Freelancers send one bill per person per month** to accounting@mana.tv, with a line per project
  (e.g. "FCM CH Layout 5 days, MSD BG Color 3 days"), paid once. Anima splits that bill across the
  projects, so the split-bill feature (Studio board ticket IN4) must allow lines from different
  projects. Today an `invoices` row belongs to one project and one position.
- **USD is the default for every rate, bill and total;** almost all contracts are in USD (US
  accounts, or Wise). No conversion for those.
- **A rare contract in another currency** (e.g. Lewis in £) is entered in that currency. Anima saves
  that day's exchange rate automatically when the project is set up / signed off, uses that fixed
  rate for the whole project, and shows both: "$520/day (£400)".
- **No daily rates, no Wise fees, no small currency differences in Anima.** The bank and the books
  hold the exact amounts. This replaces "flagged, never converted" (2026-08-18) for rates only:
  a project-fixed rate is a known conversion, not a guess.
- **Budget changes after sign-off** save the old amount, new amount, who, when and a short reason.

## 2026-10-28 — Invoices arrive by email and file themselves (design agreed, not built)

**Asked by David:** *"we also need to setup a way to store and manage all the invoices through the
accounting@mana.tv account. The idea is that all the invoices are sent directly there, it will check
against the needed information, it will identify the project, download it and upload it to drive.
Ideally it will also update the invoice section in the financial management tab."*

**Decided:**
- **mana.tv is on Google Workspace.** The mailbox and Drive side runs as a Google Apps Script inside
  the studio's account (no mailbox password on the Anima server). Anima does the checking and the
  finance update, reached by the script with a shared key in a header (the `pull_bugs.py` pattern).
- **Only invoices from a known artist's email address are taken in;** anything else stays in the
  inbox untouched.
- **Gemini reads the PDF** (David: no additional fees). This is a second AI provider next to the
  Claude transcript reader in `includes/ai.php`. *To confirm before building:* Gemini's free API tier
  may let Google use what it is sent to improve its products; the paid tier does not. Invoices carry
  bank details and addresses.
- **Each invoice is checked** against what Anima expects (days logged per project × rate, the same
  figures the artist's Home shows) and against the required fields David will supply.
- **PDFs are filed in Drive per project.**
- **A mismatch is flagged to David first.** Nothing emails the artist automatically, and nothing is
  ever marked paid automatically; Jorik pays.
- **It updates Finance management:** each line lands on its project's Invoices section as received,
  linked to the PDF in Drive.
- **Depends on IN4** (one invoice split across projects): today an `invoices` row belongs to one
  project.

## 2026-10-28 — The budget lock: fixed at sign-off, changed only by a ticked admin, with a reason

**Asked by David:** *"Only Jorik can raise a budget. I can make the budget static after the project
sign off but he'll need to change it manually. Let's implement this already."* Tim and Jorik: a
change after sign-off saves the old amount, new amount, who, when and a short reason.

**Decided:**
- **The lock starts when a kick-off is approved** (`project_kickoffs.state = 'approved'`); before
  that the budget is edited as it always was, and the pending-sign-off freeze is unchanged.
- **Who may change it is a tick on an account, `users.changes_budget` (migration 070)**, not
  Jorik's id in the code, mirroring `signs_off_kickoff`. It is ticked on Kick-off → Who signs off,
  and **only administrators can hold it**, because only they can see money (`can_see_money()`).
  Any administrator can tick it, as with the sign-off list; the tick is the policy, not a security
  boundary against other admins.
- **"Raise" is read as "change":** a cut after sign-off is as much a change to what was approved,
  so both need the tick and a reason.
- **Amount, currency, terms and notes are all locked**; a change records old/new amount and
  currency and names which of terms/notes changed too (`budget_changes`, finance_install_10).
- **A change that cannot be recorded is not made:** without migration 070 or finance_install_10
  nobody can change a signed-off budget, and the screen says which is missing.
- The check runs in `save_project_budget()` as well as on the form; a previewing admin never
  borrows the tick (the real session user is checked).

## 2026-10-28 — New logins from the person page, by one-time link, never by password

**Asked by David:** *"We need to have a quick way to create the slots profiles from the staffing
section, to actual users with user name and password. I was thinking that you might add a 'Create
user' from the detailed view in case that hire doesn't have an account created and a 'Resend link'
for old users that need to log in again to anima for a new project."*

**Decided:**
- **Production and admins** (`can_edit()`) may create a login and send links, because they are the
  people who staff a show. Create always makes an **artist**; a different role is set on Users.
- **A link, never a password, goes by email.** Random 64-character token, stored only as its sha256
  in `user_invites`, single use, 7 days, and sending a new one retires the older unused ones.
- **Who may send a link for whom:** Production for artist and supervisor logins; an admin also for
  Production logins; **nobody for an admin login** from this page. Whoever sends a link can see it
  on screen when the email fails, so it must never let somebody into an account stronger than their
  own.
- **Resend switches a deactivated login back on**, says so, and records it on the link's row.
- **Every link sent is logged** in `user_invites`: who, when, to which address, for which show,
  whether the email went, and when it was used.
- **The public page reveals nothing.** It only takes a link; there is no "email me a link" form, so
  nothing can be used to test whether an address or username exists.
- **Seeing the show:** being staffed (`crew_projects`) is what lets an artist see a show. Where the
  person is on the team page but not staffed, the form offers a ticked box to add them. David,
  2026-10-28: keep the ticked box rather than adding them silently, so production can untick it.

## 2026-10-28 — How the project-fixed exchange rate works

**Built** from Tim and Jorik's answer above ("a rare contract in another currency ... Anima saves
that day's exchange rate automatically when the project is set up / signed off").

**Decided (in the build; David to confirm the timing):**
- **One row per project per foreign currency** in the finance database (`project_fx_rates`,
  finance_install_11): dollars per unit, the date the rate is for, its source, who saved it.
  Every change goes to `project_fx_rates_log` with the old rate and a required reason.
- **When it is fixed: at set-up.** The first time a project's Finance page meets a currency with
  no rate, it fetches the ECB reference rate (frankfurter, free, no key) and saves it; kick-off
  sign-off fetches any still missing, before the money snapshot. *Asked David whether he would
  rather fix it at sign-off only; switching is a one-block change in `finance_project.php`.*
- **Never blocks anything.** A failed fetch is said on screen, the rate can be typed by hand,
  and the same project and currency are not retried for ten minutes. Nothing in
  `includes/header.php` fetches.
- **What converts:** rates (through the projection's costs), other expenses and invoices.
  Amounts round to cents in their own currency first, then convert and round again. The rate box
  on the projection stays in the contract's own currency, because it writes back to `crew_terms`.
- **The budget is not converted:** it is approved in dollars. The budget pool tab still prices in
  the first rate's currency, as before.
- **A currency without a fixed rate** behaves exactly as since 2026-08-18: flagged, never added
  up with dollars.

## 2026-10-28 — Home is a person's own page, and artists land on it

**Asked by David:** one screen an artist opens to see their work, contract and invoice information
across every show, instead of My tasks plus `statement.php` one show at a time.

**Decided:**
- **Home (`home.php`) is read-only and links to My tasks for editing.** Statuses and time logging stay
  on My tasks and Spell, so two screens never change the same thing.
- **The crew id is never a parameter, for anybody.** Home is always `effective_crew_id()`; an admin
  sees a person's Home only by viewing as them. Money is read only through `own_crew_term()`,
  `own_invoices_all()` (new, same rule as `own_invoices()`) and `own_project_fx_rates()`.
- **Artists and supervisors land on Home after login** (when their login is linked to a person).
  Admins and Production still land on the project list. The project list stays the "one home where
  you can see all the projects" of 2026-08-17; this Home is a person's desk.
- **Invoice information is per project and step for one month**, counted like the statement: time
  logged in the month and inside the person's dates on the show; a day is the show's hours per day.
  Not an invoice: the freelancer sends their own, one per month, to accounting@mana.tv.
- **A note is "waiting" until the person replies on the task or puts up a new version.**
- **A missing log day** is a working day, not a day off, inside their dates on at least one show,
  with nothing logged. Last week and this week, up to yesterday.
- **Salaried is a tick on the Users page** (David, 2026-10-28: "let's add that attribute in the users
  tab"): `users.is_salaried`, migration 072, read only by `home_is_salaried()`. A person is salaried
  when any login linked to them is ticked; their role does not decide it. Until 072 runs, admin and
  Production logins count as salaried.
- **Terminology:** "invoice", never "bill", on this page and in its code.

## 2026-10-29 — One look for the whole app, taken from Spell

**Asked by David:** "without changing the content, can we do an aesthetic pass on the whole
platform? I really liked the way Spell UI works".

**What makes Spell look the way it does** (written down so the next screen copies it, not guesses):
- **Few radii, used consistently:** 7px small buttons and chips, 8–9px controls, 14px panels.
- **Panels, not boxes:** a group of rows is one `--surface` panel with a `--border-soft` hairline
  and a 14px radius; inside it, rows are divided by hairlines, never by boxes.
- **Labels whisper:** column heads and field labels are 11–11.5px, weight 600, uppercase,
  `letter-spacing: .04em`, in `--faint`/`--muted`. Headings are tightened (`-.02em`).
- **Controls sit flat until used:** `--surface-2` fill, transparent or hairline border, firmer on
  hover; the row lifts to `--surface-2` on hover.
- **Colour only where it means something:** blue for the one thing to press or the current item;
  status colours as tints. Navigation is neutral ink.
- **Depth only on what floats:** `0 12px 34px rgba(0,0,0,.55)` on menus, pop-ups and the panel.
- **Numbers in tabular figures**, so columns of them line up.

**Decided:**
- **Tokens in `:root`** (`--radius-sm/--radius/--radius-md/--radius-lg`, `--shadow-pop`, `--ease`)
  and the shared rules changed **in place**, not overridden in a block at the end: an override
  after `.row-btn` would have beaten `.row-btn-danger` and turned every red Remove button blue.
- **Control padding and font sizes stay as they were.** Several rows (the budget form, filter bars)
  were lined up to a field's measured height; a pass about looks must not move them.
- **Hover on fields uses `:where()`**, so it weighs nothing and Spell's deliberately flat controls
  keep their own hover.
- **Three PRs:** (a) tokens and shared components, (b) project screens, (c) finance, people and
  admin screens, each checked by David on the live site before the next.

## 2026-10-29 — A contract per position, with the person's terms as the default

**Asked by David,** looking at Angelys Brunetti's Home: she *"should be able to see two different
contracts one for CH layout and other for the rest"*. Until now `crew_terms` was keyed on the person
alone, so one rate priced every position she held on every show.

**Decided:**
- **`crew_terms` stays the person's default contract.** A new finance table, `position_terms`
  (`finance_install_13`), holds an exception for one position on one show, keyed (crew, project,
  step). The step id follows the invoices convention: a pipeline step, or minus a supervision
  position's id.
- **One rule, read everywhere:** a position's own contract if it has one, otherwise the default.
  The projection, bulk rates, budget pool, stop cost, Staffing's contract line, the statement and
  Home all follow it, so a person with no exceptions is priced exactly as before.
- **The statement splits by contract.** Time and approved seconds on a position's step are paid at
  its rate; the rest at the default, or at the person's own supervision contract when they have one
  (a supervisor logs no production steps).
- **Set on the person page, admins only**, like every pay term. The projection's rate box edits the
  position's contract when the row has one, and bulk rates do the same; a position contract changes
  that show only, so it needs no "every show" confirmation.
- **Not changed:** historic fees (`includes/history.php`) still read each person's default rate, as
  they span several shows.
