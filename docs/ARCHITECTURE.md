# Mana Production Platform — Architecture

Draft v1 · 2026-08-11 · **proposal, not yet built**

Read `../START_HERE.md` first for context and decisions.

---

## Design principles

1. **Everything is scoped to a project.** Every production table carries `project_id`.
2. **The pipeline is data.** Steps and their dependencies are rows, not code. No project
   type is special-cased.
3. **Crew is global.** People exist once; project membership is a relationship.
4. **Nothing fails silently.** Imports and exports report what they did and skipped.
5. **Names get an alias layer.** Any name arriving from outside is matched through it.

---

## Schema

### Projects

```sql
projects
  id, code (unique), name,
  type ENUM('2D','3D','Mixed','Other'),
  status ENUM('setup','active','on_hold','wrapped'),
  fps INT DEFAULT 24,
  start_date DATE, delivery_date DATE,
  created_at
```

Per-project configuration lives in `project_settings (project_id, key, value)` — the same
flexible pattern STFC used, but scoped. Truly global config (SMTP, upload limits) stays in
`config.php`.

### Pipeline — the important part

```sql
pipeline_steps
  id, project_id, name, step_order,
  tracks_seconds TINYINT,        -- seconds-based vs shot-count based
  applies_to ENUM('shot','asset','both'),
  deadline DATE,
  show_in_dashboard TINYINT,
  color VARCHAR(7)

step_dependencies
  step_id, depends_on_step_id     -- composite PK
```

**Why a dependency table rather than order alone.** Parallelism becomes a property of the
data: two steps that share a dependency run side by side, and a step depending on both waits
for the later one. This replaces STFC's hardcoded `$overlapsWithPrevious = ['render']`.

STFC's real pipeline expressed in this model:

```
Assembly ──┬──> Lighting ──┐
           └──> Render  ───┴──> Comp
```

The scheduler walks the graph; it needs no knowledge of any specific step name.

*Guard to implement:* reject cycles when saving dependencies, or the scheduler will hang.

### Shots and assets

```sql
shots
  id, project_id, episode, shot_code,
  frames INT, placeholder_seconds DECIMAL,
  notes TEXT, external_id VARCHAR    -- e.g. Plaky item id
  UNIQUE (project_id, episode, shot_code)

assets
  id, project_id, name, asset_type,  -- Character / Environment / Prop / …
  notes TEXT, external_id VARCHAR
  UNIQUE (project_id, name)

shot_assets
  shot_id, asset_id                  -- replaces STFC's split
                                     -- shot_characters / shot_environments
```

`shot_assets` is a single join table rather than one per asset type — STFC needed a schema
change to add Props, which this avoids.

### Progress

```sql
progress
  id, project_id, step_id,
  shot_id NULL, asset_id NULL,       -- exactly one is set
  status VARCHAR,                    -- raw external status text
  is_delivered TINYINT, is_approved TINYINT,
  crew_id NULL,                      -- resolved assignee
  assignee_raw VARCHAR,              -- what the source actually said
  updated_at
```

One progress table for shots *and* assets, because pipeline steps can apply to either.
Keeping `assignee_raw` alongside the resolved `crew_id` is what makes unmatched-name
reporting possible — a lesson from STFC, where the raw value was discarded.

### Crew (global)

```sql
crew
  id, name, role, email, is_team TINYINT, active TINYINT

crew_projects
  crew_id, project_id, allocation_pct INT DEFAULT 100

crew_skills
  crew_id, step_id                   -- step_id is project-scoped

crew_aliases
  alias_name (unique), crew_id       -- external name -> person
```

`is_team` marks non-person assignees such as "Animation" — valid for tickets, but excluded
from scheduling and per-artist reporting.

### Scheduling

```sql
schedule
  id, project_id, step_id, shot_id NULL, asset_id NULL,
  start_date, end_date,              -- both INCLUSIVE (decide once, honour everywhere)
  crew_id NULL, is_manual TINYINT,
  UNIQUE (step_id, shot_id, asset_id)

headcount_log
  project_id, step_id, log_date, headcount, notes

daily_snapshots
  project_id, step_id, snapshot_date,
  shots_delivered_cum, shots_approved_cum,
  seconds_delivered_cum, seconds_approved_cum
```

**End dates are inclusive everywhere** — database, API and UI. Convert only at the boundary
of external tools (Frappe Gantt, GanttProject). STFC lost days to two layers each applying
their own ±1.

### Name aliases

```sql
name_aliases
  id, project_id, alias, canonical_name,
  kind ENUM('asset','crew','step')
```

Plus a standing **"unmatched names"** report: any incoming name that resolves to nothing,
with a usage count and a one-click mapping control. Build this with the importer, not later.

---

## The scheduler

Two directions, both required.

### Reverse (quota)

```
remaining        = total_work − approved_work
working_days     = working days from today to step deadline
team_per_day     = remaining ÷ working_days          # independent of headcount
per_artist_day   = team_per_day ÷ headcount          # falls as the team grows
```

`team_per_day` deliberately does not depend on headcount — the work required to hit a date is
fixed. Only the per-artist share moves. This confused people on STFC; label it clearly in the UI.

### Forward (auto-schedule)

For each shot/asset, walk the dependency graph in topological order:

1. Skip approved work.
2. Preserve `is_manual = 1` tasks (hand-planned or `.gan`-imported).
3. Earliest start = day after **all** dependencies complete for that item.
4. Duration = `work_units ÷ per_artist_quota`, minimum 1 day.
5. Concurrency = `ceil(remaining_tasks × days_per_task ÷ working_days_available)`,
   floored at headcount. **Never default this to 1** — that bug turned a 3-week schedule
   into 9 months on STFC.
6. Assign crew round-robin from the step's skill pool, excluding `is_team` rows.
7. If anything still lands past the deadline, **say so in the UI** rather than silently
   scheduling into next year.

### Round trip

`.gan` export → refine in GanttProject → re-import. Imports match on
project + episode + shot code + step name, mark rows `is_manual = 1`, and default to a
**dry-run preview** showing what would change. Working code for both directions exists in
STFC's `timeline_gan.php` and `timeline_import.php`.

---

## Setup wizard (the New Project flow)

Steps, each saving as it goes so the flow can be resumed:

1. **Basics** — name, code, type, delivery date, fps
2. **Pipeline** — pick a template (2D / 3D / Mixed) then add, rename, reorder or delete
   steps. Templates are seed data, not hardcoded logic.
3. **Workflow** — set dependencies. Start with a simple "runs in parallel with the previous
   step" checkbox, which covers most real cases; a visual graph editor can come later.
4. **Shots** — import CSV/Excel *or* add rows in-page. Both, in any order.
5. **Assets** — same.
6. **Dates & headcount** — per step; seeds the first-pass schedule.
7. **Review** — show the derived quota per artist per day *before* committing, so an
   unrealistic plan is visible at setup rather than discovered in week three.

---

## Deliberately deferred

- Visual drag-and-drop workflow editor (checkbox model first)
- Cross-project resource levelling (report first, optimisation later)
- Client-facing views
- Migrating STFC's historical data

---

## Reference implementation

STFC's dashboard has working versions of much of this — CSV import, tickets with attachments
and email, `.gan` round trip, per-artist reporting, PNG/PDF export:

```
D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\
```

Reuse the patterns; do **not** copy the single-project schema.
