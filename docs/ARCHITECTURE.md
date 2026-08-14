# Anima — Architecture

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
  status_id NULL,                    -- -> statuses (the platform's own; see below)
  is_delivered TINYINT, is_approved TINYINT,
  crew_id NULL,                      -- primary assignee
  assignee_raw VARCHAR,              -- what the source actually said
  time_spent_minutes INT NULL,       -- stored in MINUTES, shown/entered in HOURS.
                                     -- Integer minutes are exact; decimal hours drift
                                     -- when summed against an invoice.
  updated_at

progress_crew                        -- a task can have several people on it
  progress_id, crew_id               -- composite PK
```

`progress_crew` exists because the live board really does assign two people to one step
(`Júnior Fonseca,Michal Pilch`). `crew_id` stays as the primary so simple queries keep
working; the join table is the complete answer.

One progress table for shots *and* assets, because pipeline steps can apply to either.
Keeping `assignee_raw` alongside the resolved `crew_id` is what makes unmatched-name
reporting possible — a lesson from STFC, where the raw value was discarded.

> **Revised 2026-08-11 — the status model.** Plaky stays the working tool for now, but the
> platform defines its own statuses from the start, so that dropping Plaky later changes
> habits rather than schema (see the paired Plaky entries in `DECISIONS.md`):
>
> ```sql
> statuses                          -- the platform's OWN vocabulary
>   id, project_id, name, sort_order,
>   maps_to ENUM('none','delivered','approved'),
>   is_blocked TINYINT,             -- cannot proceed, vs simply not started
>   is_excluded TINYINT,            -- doesn't pass through this step at all ('N/A');
>                                   -- added migration 010, read by the scheduler
>   color VARCHAR(7)
>
> status_mapping                    -- incoming external text -> one of the above
>   id, project_id, external_status, status_id
> ```
>
> `is_blocked` exists because "On Hold" and "Waiting to start" both mean *not finished* but
> mean opposite things for planning: one needs someone to unblock it, the other needs an
> artist. Blocked work still counts as remaining — it has to happen eventually — but is
> reported separately so it reads as the escalation it is.
>
> `maps_to` is STFC's idea kept intact: a show can call a state anything it likes, and
> reporting still knows whether it counts as delivered or approved. Seed each project with a
> default set during setup, editable afterwards.
>
> `progress.status_id` references a `statuses` row. `progress.status` keeps the raw incoming
> text, and `assignee_raw` the raw name — both are what make "here is what arrived and here
> is what we could not match" reportable.
>
> **Why it matters:** the importer writes to exactly the same rows a person would. The day
> Plaky is dropped, the importer stops running and people set those statuses by hand. No
> migration, no reporting rewrite.

### Crew (global)

```sql
crew
  id, name, role, email, is_team TINYINT, active TINYINT
  -- NO pay rates, day rates or contract terms. Money lives in a separate
  -- application and database that this one has no credentials for.
  -- See DECISIONS.md, 2026-08-12.

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
  item_key,                          -- 's<id>' / 'a<id>' / 'p<project_id>' / 'band'
  start_date, end_date,              -- both INCLUSIVE (decide once, honour everywhere)
  crew_id NULL,                      -- stays NULL until a crew screen exists
  lane SMALLINT,                     -- which parallel track; names the placeholder artist
  is_manual TINYINT,                 -- hand-planned; the auto pass never rewrites these
  source VARCHAR(20),                -- 'auto' — what a re-run is allowed to replace
  UNIQUE (step_id, item_key)

non_working_days                     -- studio-wide; public holidays and shutdowns.
  day (PK), name                     -- read by working_days_between(), so it moves the
                                     -- quota figures too, not just the schedule

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

### Tickets and tasks (Phase 7, built 2026-08-14)

```sql
tickets
  id, project_id, seq,               -- seq is per project; "RTC-12" is DERIVED from
                                     -- projects.code at display time, never stored
  type ENUM('task','ticket'),
  title, description,
  status_id NULL,                    -- -> statuses. NOT a separate ENUM: the board's
                                     -- columns are the project's own vocabulary
  priority ENUM('low','medium','high','urgent'),
  assigned_crew_id NULL,             -- may be a person, a team, or an unfilled SLOT
  due_date,
  shot_id NULL, asset_id NULL, step_id NULL,   -- what it is about; any combination
  created_by, created_at, updated_at,
  completed_at NULL,                 -- when it first reached an approved status;
                                     -- updated_at can't serve, it bumps on any edit
  archived TINYINT,
  UNIQUE (project_id, seq)

ticket_comments
  id, ticket_id, user_id, kind ENUM('user','system'), body, created_at
  -- 'system' lines record status/owner/priority/due changes in the SAME thread,
  -- so "who moved this to Done" is answerable. STFC's Activity section showed
  -- only typed comments.

ticket_attachments
  id, ticket_id, comment_id NULL,    -- NULL = attached to the ticket, not a reply
  stored_name (unique), original_name, mime_type, file_size, uploaded_by, created_at

statuses.on_ticket_board TINYINT     -- which statuses are columns on the board
users.crew_id NULL                   -- which person a login is; makes "assigned to me,
                                     -- across all projects" answerable at all
```

**Why the board reuses `statuses`.** The project already owns a vocabulary with `maps_to`,
`is_blocked` and `is_excluded`, and it covers a ticket's states better than a five-value ENUM
does — including On Hold (blocked, not idle) and Retake. A second vocabulary would mean two
mental models for one idea. Eleven statuses is too many columns, hence `on_ticket_board`.

**Tickets never write to `progress`.** A ticket in a "Completed" status must not count toward
approved work, and Plaky still owns production status — a retake ticket that set a step back
would be silently overwritten by the next import. `step_id` is the link that makes closing
that loop cheap on the day the platform owns progress directly.

**Attachments** live in `uploads/tickets/`, excluded from git *and* from the FTP deploy. Files
are served only through `attachment.php` behind the login; the directory denies direct access
and disables PHP execution. Extension and detected MIME type are both whitelisted, and stored
names are random.

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

**Built 2026-08-12** — `includes/scheduler.php`. `build_schedule()` computes and returns;
`commit_schedule()` writes. They are separate so the screen shows a full dry-run first.

Steps are walked in dependency order (a step's `workflow_levels()` depth is already a valid
topological order). Within a step:

1. Skip approved work, and work marked **not applicable** (`statuses.is_excluded` — the
   board's `N/A`, added in migration 010).
2. Preserve `is_manual = 1` tasks (hand-planned or `.gan`-imported). They are read — what
   follows still waits for them — but never rewritten, and the pass skips any (step, item)
   that already has one, so nothing it writes can collide.
3. Earliest start = day after **all** dependencies complete *for that item*, resolved as
   specifically as the data allows: the same item in the step it waits for; failing that, for
   a shot waiting on an asset step, the assets that shot actually uses (`shot_assets`);
   failing that, the whole step.
4. Duration = `days_per_unit` per item — times the shot's `placeholder_seconds` on a step
   tracked in seconds, since that step measures footage, not items.
5. **Lanes**, not concurrency-as-a-number. Where `planned_headcount` is set, that is the lane
   count, *even when it isn't enough* — the shortfall shows up as a late finish and is
   reported, rather than being papered over with people who don't exist. Where it isn't set,
   the count is derived from the deadline: `ceil(work ÷ working_days_available)`, capped at
   the number of items and **never defaulted to 1** — that bug turned a 3-week schedule into
   9 months on STFC. Each item takes the lane that frees up soonest.
6. **No crew assignment.** `crew_id` stays NULL and `schedule.lane` carries the track number;
   the name shown is derived from the step ("Compositing 01", "Compositing 02"). Nothing
   records who does which discipline yet, and a real name reads as a real assignment.
7. Anything past a deadline is **reported per step and for the project**, never scheduled
   quietly into next year.

Two things are drawn as a single band (`item_key = 'band'`, both item columns NULL) rather
than as invented per-item rows: a step whose `coverage_pct` is still a guess, and a project
with no breakdown yet (from `project_settings.estimated_*`). Both become real per-item dates
as soon as the underlying facts exist. Whole-project steps use `item_key = 'p<project_id>'`,
the convention the importer already set.

The maths runs on a **fractional** working-day axis anchored at the project start, converted
to dates only at the end — so two half-day tasks share a date instead of each being rounded
up to a full day. Handover between steps still moves in whole days.

### Working days

`working_days_between()` and `working_days_list()` read `non_working_days` (studio-wide;
`includes/calendar.php`). Before this they counted Mon–Fri only, treating every Mexican
public holiday as workable — an error always in the optimistic direction, and one that
affected the **quota** figures as much as the schedule.

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
