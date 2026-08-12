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

---

## Still open

- Hosting: the current shared host flaps (suspected cPanel 508 resource limits). Resolve
  before putting a second production tool on it.
- Platform URL / target directory — needed for `FTP_TARGET_DIR`.
- Whether STFC's historical data is imported later, or the dashboard simply retires.
- Whether Plaky remains the source of truth for the new project, or the platform owns
  the data directly.
