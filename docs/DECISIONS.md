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

## 2026-08-11 — Generated-column uniqueness for progress and schedule rows
**Decision:** `progress` and `schedule` reference *either* a shot or an asset via nullable
`shot_id`/`asset_id`, and uniqueness per (step, item) is enforced through a stored generated
column `item_key` ('s'+shot_id or 'a'+asset_id) with `UNIQUE (step_id, item_key)`.
**Why:** MySQL unique indexes ignore NULLs, so `UNIQUE (step_id, shot_id, asset_id)` would
silently allow duplicate rows whenever one column is NULL. The generated column makes the
constraint real at the database level instead of relying on application code.
**Consequence:** requires MySQL 5.7+ / MariaDB 10.2+ (any current cPanel host qualifies).
Application inserts still set exactly one of the two ids.

## 2026-08-11 — Pipeline templates are seed rows the wizard copies
**Decision:** `pipeline_templates` / `pipeline_template_steps` / `pipeline_template_deps`
hold the 2D and 3D starting pipelines. The setup wizard copies a template into the new
project's `pipeline_steps` / `step_dependencies`, where it becomes fully editable.
**Why:** templates as data, not code — same principle as the dependency graph. Copying
(rather than referencing) means editing a template never disturbs existing projects.
**Consequence:** the baseline seeds a 3D template matching STFC's shape (Lighting ∥ Render
after Assembly, Comp waits for both) and a generic 2D template; both are editable in the DB
or, later, in a template editor.

---

## Still open

- Hosting: the current shared host flaps (suspected cPanel 508 resource limits). Resolve
  before putting a second production tool on it.
- Platform URL / target directory — needed for `FTP_TARGET_DIR`.
- Whether STFC's historical data is imported later, or the dashboard simply retires.
- Whether Plaky remains the source of truth for the new project, or the platform owns
  the data directly.
