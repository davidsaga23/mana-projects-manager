# Requested features — where each one belongs

## The production cycle, and what the platform covers

David's own process for a new project, given 2026-08-16, mapped against what exists. **This is
the list to check a new feature against** — anything that doesn't move a row here toward "built"
is probably not the next thing.

| # | Step | Coverage | Where |
|---|---|---|---|
| 1 | Timeline | **Built** | `schedule.php`, `timeline.php`, `.gan` both directions, saved versions to look back at, a step editor with progress, drags that keep working days (all 2026-09-18) |
| 2 | Budget | **Built** 2026-08-18 | `finance_project.php` — agreed amount, currency, terms |
| 3 | New brief | **Built** 2026-08-18 | `brief.php` — the ask, deliverables, reference, files |
| 4 | Breakdown | **Built** | Wizard (paste) + Plaky import |
| 5 | Project expense | **Built** 2026-08-18 | Expense projection, computed live in the shape of `RTC_ExpenseProjection_v01.xlsx`, costed from **each project's own ideal quotas** (2026-09-17), which can be copied from another project or matched to an approved sheet |
| 6 | Hiring | **Built** 2026-08-17, **reworked 2026-09-02/03** | **Staffing** (`crew.php`) — positions by department, candidates on each position with stage and Hire, the artist database as the source, every past candidate kept (2026-09-18). Hiring LINKS a person to a position. `hiring.php` is orphaned, see the last section |
| 7 | Detailed timeline | **Built** | Per-task Gantt, drag, regenerate |
| 8 | Contracts | **Built** 2026-08-17 | Status and dates per person, "who hasn't signed" per project, the Drive link as before |
| 9 | Invoices | **Built** 2026-08-18 | Per position, with paid/overdue and unattached handling |
| 10 | Budget reports | **Built** 2026-08-18 | Budget vs projected vs invoiced, per project and across all of them |
| — | **Client loop** | **Missing** | The step the list itself does not have — see below |

### What that table says (revised 2026-08-17)

**All ten numbered steps are built.** The money chain closed in a day on 18 August because the
production half of every money screen already existed; hiring and contracts closed for the same
reason, one layer up. Slots, lanes, person-days and unpriced days were all already computed — what
was missing was the record of the conversation that turns a slot into a person, and that turned out
to be one table, one screen and a hand-off to `promote_placeholder()`.

**The chain is now complete end to end.** Slots → hiring → contracts → invoices: a headcount
generates a slot, a slot gets candidates, a candidate agrees a rate, hiring renames the slot so all
its planned work becomes theirs, the agreed rate lands on their terms so their days stop counting as
unpriced, a contract is chased to signature, and invoices settle against the position. Every link is
joined on ids that never move.

*(Since 2026-09-02 hiring does not rename the slot: it points the position at the person through
`assign_crew_to_lane()`, which moves the planned work across. Renaming made a new copy of an artist
for every position they were hired into. The rest of the chain is unchanged.)*

**What is deliberately still missing inside it:** the scheduler does not read engagement dates. A
person's start and end dates are recorded and compared against the plan, but nothing moves a bar —
see the 2026-08-17 entry in `DECISIONS.md`, and build it with `crew_absences`, which is the same
rule wearing a different hat.

### The step missing from the list itself

**The client loop.** *(First piece built 2026-08-31: **milestones** on the timeline — migration
028 — so a client review DATE can be put on the chart and the plan built around it. That is the
"when is it due" half. What was sent and what came back is still missing, and is the larger
part.)* All ten steps are internal. Nothing records a DELIVERY — version X sent on
date Y, notes back on Z, round 2 sent. There is an "In client review" status and retakes are
tracked, but not what was sent or what came back. For an animation studio that is usually where
a schedule actually dies: not in the work, but in three unacknowledged review rounds. The
"online version review" item below is the ambitious version of this; a plain delivery log would
carry most of the value.

---

## What appears in each shot — linking assets to the shot list

**BUILT 2026-09-02** (`shots.php`, `includes/breakdown.php`): assets per shot as chips in a
column per asset type, frames per shot, and an export. `shot_assets` now has a writer, so the
scheduler's per-shot asset rule can fire. **Re-run the schedule on a show once its breakdown is
filled in** — a saved schedule does not improve on its own. What follows is the reasoning it was
built on, kept for why.

David, 2026-08-17: *"we need to link props, vehicles, characters, environments and any other
asset to the shot list so the production team knows what is required to feature in each shot."*

**This needs no migration, and it is not only a convenience — the scheduler is already worse
without it.** That is the part to understand before planning the work.

### What exists

| Piece | Status |
|---|---|
| `shot_assets (shot_id, asset_id)` | **Built, in `install.sql`, since v1 (2026-08-11)** |
| The scheduler reading it | **Built** — `scheduler_shot_assets()`, `includes/scheduler.php` |
| Anything that WRITES a row into it | **Missing entirely.** Zero inserts in the codebase |

One join table rather than STFC's `shot_characters` + `shot_environments` was a deliberate v1
decision, and it is what makes David's *"and any other asset"* free: props, vehicles, FX, crowds
and anything invented later are already the same shape. Adding Props to STFC needed a schema
change; here it needs nothing.

### What it costs today — the reason this is not merely a nice-to-have

`includes/scheduler.php` resolves how long a shot must wait for the asset work it depends on, in
three steps, most specific first:

1. the same item in the step it waits for;
2. **failing that, for a shot waiting on an ASSET step, the assets that shot actually uses
   (`shot_assets`);**
3. failing that, the whole step.

Because nothing populates `shot_assets`, **step 2 never fires and every shot falls through to
step 3.** So every shot waits for the *entire* asset step to finish — every shot on Return to Club
waits for the last character to be modelled and rigged before its animation can start, including
shots with no characters in them.

That error is always in the **pessimistic** direction, which is the opposite of the studio-calendar
bug (2026-08-12) but the same category: a scheduler input that silently is not there. It makes the
plan longer than it needs to be, which reads as "we need more people" rather than as missing data.
Filling this in is likely to *shorten* the critical path on a real show.

### What it unlocks besides the schedule

- **The production question David actually asked** — open a shot, see what has to be in it. That is
  a call-sheet, and nothing in the platform can print one.
- **The reverse, which is as useful** — open an asset and see which shots need it. "This prop is in
  two shots" is the difference between building it properly and not building it at all.
- **A real answer for "ready for production"** (2026-08-14). A shot is ready when the assets it
  uses are approved. Today "all green" is computed per item against its own steps; with this it can
  finally mean *this shot can start*.
- **Coverage stops being a guess for some steps.** The `coverage_pct` band (2026-08-12) exists
  because nobody knows WHICH shots have FX. Where the answer is an asset link, it becomes known.

### The reference implementation, and what does not port

STFC has `shot_assoc_api.php` plus the association UI on its shots screen. Read it first, per the
2026-08-14 rule. **Two things about it must NOT come across:**

- It stores **names**, not ids (`shot_characters.char_name`). That is the alias problem the
  platform was built to avoid — `RomulanOfficer01` vs `Romulan Officer` is lesson 6. Here it is
  `asset_id`, and the alias layer already exists for import.
- It hardcodes **two kinds** in the API (`type must be character or environment`). The platform's
  single join table is the whole reason "any other asset" needs no code change.

The interaction model is worth keeping: add and remove one at a time, saved immediately, no
staging. It is the same reasoning the tracking grid uses.

### Where it would live

- **The item page (`item.php`)** is the natural home for both directions — it already opens one
  shot or one asset, and this is one more panel on it.
- **Bulk entry matters more than the single case.** 188 shots × several assets each is not a job
  for one-at-a-time clicking. The paste and grid pattern now used by hiring and invoices applies
  directly: paste `SHOT, asset, asset, asset` per line, resolve names through the alias layer,
  report what matched and what did not — which is exactly the unmatched-names report that already
  exists.
- **The Plaky import should populate it** if the board carries the association; worth checking a
  real export before assuming it does not (`docs/PLAKY_EXPORT.md`).
- After it is populated, **re-run the schedule** — saved schedules are stored snapshots and will
  not improve on their own (the same caveat migration 014 carried).



David's list, 2026-08-12, annotated with which side of the money boundary it
falls on, what it depends on, and anything that changes its size.

Not a commitment to an order — `../START_HERE.md` holds the actual build order.
This is for deciding what to pick up next without re-deriving the dependencies.

**P** = production platform (this codebase) · **F** = finance app (separate,
separate database — see `DECISIONS.md` 2026-08-12)

| Feature | Side | Depends on | Notes |
|---|---|---|---|
| Project setup page | P | — | **Done** (Phase 2) |
| Crew management — name, position, days off | P | — | Small. Extends `crew`, which already exists |
| Crew management — time log | P | Phase 3 | **Done** — `time_logs` (migration 022), entered from My tasks |
| Crew management — invoice upload + status | **F** | time log | Documents and money; not this codebase |
| Budget expense projections | **F** | rates, headcount | **Verified against a real sheet — see below.** Production half exports from here; money stays finance-side |
| Step departments (pre-prod / production / post) | P | — | **Done** — `pipeline_steps.department` (migration 015); groups the projection and Staffing |
| Budget vs actual at project end | **F** | time log, rates | Needs real logged hours to mean anything |
| Budget simulator — "what if we add people?" | **F** | scheduler | Production already computes the hard half |
| Payments / invoice tracking hub | **F** | invoice upload | Which invoices are paid |
| Cross-project benchmarking — estimated vs actual per step | P | estimate baseline, time log | **Read the section below.** The capture half is urgent; the reporting half isn't |
| **What appears in each shot** — link assets to shots | P | — | **Done 2026-09-02** — `shots.php`. See the section above |

### Deliberately last (decided 2026-08-12)

Both of these wait until everything above is working. Neither blocks anything else,
and both are large enough to stall the rest if started early.

| Feature | Side | Depends on | Notes |
|---|---|---|---|
| Online version review (Frame.io / Dropbox Replay style) | P | media hosting | **Largest item. Read the caution below** |
| Own tracking system — replacing Plaky | P | Phase 3 | The status grid, plus artist logins if artists update their own work. Phase 3 deliberately builds the status model so this becomes screens, not schema. **The grid half is now specified below and is Phase 8** |

---

## Phase 8 — the tracking grid and the Production tab

Specified 2026-08-14 from David's description of what made STFC's Pre-prod screen work. Ported
from `preprod.php`, `preprod_api.php`, `assets/preprod.js` in
`D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\`.

**Read the three `2026-08-14` tracking-grid entries in `DECISIONS.md` first.** They settle the
one thing that cannot port unchanged and say why.

### No migration
Everything it reads exists. `progress` holds `(project_id, step_id, shot_id/asset_id, item_key,
status_id)`; `pipeline_steps.applies_to` and `applies_to_asset_type` say which steps cover which
items; `statuses` carries `name`, `color`, `maps_to`, `is_blocked`, `is_excluded`. STFC needed
three tables of its own; here it is screens plus one JSON endpoint. This is the payoff the
2026-08-11 status-model decision was bought for.

### The screen, top to bottom

| Piece | Behaviour | Ported from |
|---|---|---|
| **Colour legend** | Every status in the project, its colour and its name. Built from `statuses`, so it cannot drift from the cells | New — a checkbox needed no key, eleven colours do |
| **Overall bar** | "N / M cells done", percentage, "across X assets and Y steps" | STFC, unchanged |
| **Per-step counters** | One per step: done / applicable | STFC, unchanged |
| **Pies per asset type** | One chart per type, statuses within that type. **Only for types that exist** — no empty placeholders. Clicking a segment lists the items in that type with that status | New |
| **The grid** | Rows = items grouped by type with subtotals, columns = steps. Cell = a small block in the status's colour. Click opens a dropdown of the project's statuses | STFC's shape, cell redesigned |
| **Type filter** | Show one asset type at a time | STFC, unchanged |

### The rules that carry meaning

- **Green = `maps_to = 'approved'`.** On STFC a box was ticked only for Plaky's *Completed* or
  *Final* — meaning **ready for the next department to pick up**. That definition ports exactly
  onto `approved`, so it does not need restating in code.
- **A dash means the step does not apply**, computed from `applies_to` /
  `applies_to_asset_type`, or from an `is_excluded` status on that specific cell. STFC's dash
  only meant "Plaky didn't send this"; here it can be structural, which is better.
- **"All green" is a state, not a look.** An item whose every *applicable* step is approved is
  **ready for production** and reads green wherever it is listed. Computing "applicable"
  correctly is load-bearing: a character with no rig must still be able to go green.
- **Shots too.** The same grid serves shots × steps, driven by `applies_to`. STFC had assets
  only because it had no shot pipeline in that screen.

### Renaming
The **Shots** tab becomes the **Production** tab, and shows ready-for-production items in green.
One label in `includes/project_nav.php`, plus the green state above.

### Phase 8b — the item detail view — **BUILT 2026-08-15** (`item.php`, migration 020)

Everything below was the spec; it was built as written, with these settlements:
- **Version is free text** (David's call). The cost is stated in migration 020's header: nothing
  can sort or compare versions, because nothing can know v10 follows v9 rather than v1. Nothing
  in the codebase does, and nothing should start without deciding a format first.
- **`version` and `notes` are on `progress`**, not on the item — they describe a step's work on
  a thing, not the thing. The importer never touches either, so a person's typing survives.
- **Dates are shown but not edited here.** They come from `schedule`; the timeline is where they
  are dragged, and it marks what it touches `is_manual = 1`. A second writer without that marker
  would have the scheduler silently overwrite them.
- One form, one Save, for the whole table — a save-per-row means five reloads to update one shot
  after a review.

Original spec follows.



Clicking an asset or a shot in the tracking grid opens **that item's own page**: every step it
passes through as a row, and per row the assignee, the due date, the status, a version number
and notes.

Today the grid answers "where is everything"; this answers "what is going on with *this* one",
which is the question asked in a review when somebody stops on one shot. The grid gives it a
natural front door — the item name in the first column is already the obvious thing to click.

**What exists and what does not:**

| Column | Source | Status |
|---|---|---|
| Step | `pipeline_steps` | There |
| Status | `progress.status_id` | There |
| Assignee | `progress.crew_id` + `progress_crew` for the second person | There |
| Due date | `schedule.end_date` for that (step, item) | There — inclusive, per the global rule |
| Notes | — | **Missing.** `shots.notes` and `assets.notes` are per ITEM, not per step |
| Version number | — | **Missing entirely** |

So it is mostly a reading screen over data already held, plus **one small migration** for the
two missing fields. Both belong on `progress`, because they describe a step's work on an item
rather than the item itself: `progress.version` and `progress.notes`.

Worth settling before building: whether a version is a free string ("v003", "A_02") or an
integer the app increments. Free text matches how the schema treats asset types and departments,
and studios name versions differently — but an integer is what makes "which is newer" answerable.
Leaning free text with the same reasoning as `asset_type`: a fixed rule is what forced a schema
change on STFC when Props arrived.

The related open item is **tickets**: a card can already name a shot, an asset and a step
(`tickets.step_id`), so this page should list the open tickets against that item — which makes it
the first screen where the ticket system and the tracking data meet.

### Open questions for the build
- Does clicking a cell write `progress.status_id` directly, or stage changes for a save? STFC
  wrote immediately (`preprod_api.php`), which suits a grid. Immediate is probably right, but
  every write must also recompute `is_delivered` / `is_approved` the way `statuses_edit.php`
  already does — those flags are what the dashboard counts.
- **Plaky still owns status** (2026-08-11). A hand-set status here is overwritten by the next
  import, exactly as STFC's "toggle manually until next CSV upload" warned. Say so on screen.
- Pie rendering: hand-rolled SVG, consistent with the workflow diagram, rather than a chart
  library. No external dependency has been added to this codebase yet and none is needed for
  a pie.

---

## Crew management straddles the boundary

It reads as one screen and it can't be one screen. Name, position, days off and
logged time are production data everyone's work depends on. Invoice files and
payment status are money.

Same person, two records, joined on `crew.id` — the finance app reads the name
from production and holds the invoices privately. This is exactly the split the
separation decision exists to enforce, and it's worth expecting so it doesn't
feel like an obstacle when it appears.

## Days off are a scheduler input, not an HR note

`working_days_between()` currently counts Mon–Fri and nothing else. If someone
is away for two weeks, every quota and every scheduled date that assumes they
aren't is wrong — quietly, in the optimistic direction.

So days off should land as a table the scheduler reads (`crew_absences`:
crew_id, from, to, reason), not as a text field on a person. Worth building
*with* the scheduler rather than as a standalone HR feature, so it's wired in
from the start. Public holidays belong in the same mechanism — Mexican holidays
are not weekends and the current maths treats them as working days.

## The budget simulator is half-built already

"What happens if we add two more artists?" is the reverse-quota maths the wizard
already does — `required_headcount` on the review screen answers exactly this,
without money attached. The simulator is that calculation crossed with rates.

Which means it lives on the finance side (rates), but the production platform
should expose the quota numbers cleanly enough for it to consume. Worth
remembering when the scheduler is built: keep that maths in one reusable place
(`includes/quota.php` already is), not buried in a page.

---

## Cross-project benchmarking — "how long does Concept actually take?"

David, 2026-08-13: *"I want to compare the same tasks along different projects so when I'm
creating a new one, my starting estimates get better and better."*

This is the payoff that makes the platform worth more than a spreadsheet, and the reason
`step_library` was built global rather than per project. It is a **reporting** feature, but it
has a **data-capture** prerequisite that is urgent, for the same reason `daily_snapshots` was:
what isn't recorded as it happens cannot be reconstructed afterwards.

### The identity to compare on

Not the step name alone. "Concept" for characters and "Concept" for environments are different
jobs with different day rates, and migration 009 already treats them as different steps. The
comparison key is the triple that migration made unique:

```
(name, applies_to, applies_to_asset_type)   e.g. ("Concept", asset, "Character")
```

`step_library` already normalises names across shows, so it is the natural spine. It would need
to carry that full triple rather than just `name` + `applies_to`.

### The capture half — DONE 2026-08-13

`pipeline_steps.days_per_unit`, `coverage_pct` and `planned_headcount` were edited directly on
the Dates & headcount screen, so every edit destroyed what was thought before it. That is now
fixed: `step_estimate_baseline` (migration 013, `includes/baseline.php`) freezes every step's
estimate when the wizard turns a project `active`, and a deliberate **Re-baseline** button adds
a revision rather than replacing one. The screen shows which steps have drifted from the
recorded estimate. See `DECISIONS.md`, 2026-08-13.

**Live projects have no baseline yet, on purpose.** Their current numbers have already been
edited, so recording them as an "original" estimate would be a lie — and a wrong baseline is
worse than a missing one, because a missing one is visibly missing. Return to Club will get its
first row the next time someone presses Re-baseline, labelled as a revision.

### What's already in place

| Piece | Status |
|---|---|
| Global step vocabulary (`step_library`) | Built (migration 002) — the join key |
| Step scope triple as identity | Built (migration 009) |
| Estimate baseline (`step_estimate_baseline`) | Built 2026-08-13 — frozen at kickoff, append-only |
| Daily progress history (`daily_snapshots`) | Built 2026-08-13, recording from now on |
| Planned dates per task (`schedule`) | Built (Phase 4) |
| Actual effort per task | **Missing.** `progress.time_spent_minutes` is one running total and nearly empty; needs the `time_logs` table |
| Per-step figures as CSV (`export.php`) | Built — a stopgap for doing this in a spreadsheet today |

### What the analysis eventually answers

Per step identity, across every project that has run it:

- **Estimated vs actual days per item** — the headline. "We plan Character Concept at 2 days;
  across four shows it has averaged 3.1."
- **Estimate drift by project type and scale.** A 2D short and a 3D series are not the same
  show; comparing them without splitting by `projects.type` would average away the signal.
- **Which steps are reliably estimated and which are not.** A step that is always 10% over is a
  calibration fix; one that swings between 0.5× and 3× is a scoping problem, and they need
  different responses.
- **Retake allowance actually observed** vs the percentage set on the Basics screen.
- **Feeding it forward:** when a new project picks a step from the library, offer the observed
  median as the default `days_per_unit`, labelled as coming from N previous shows. That is the
  loop closing — the estimate improves because the tool remembers, not because someone
  remembers. *(Since 2026-09-17 days per item IS each project's ideal quota, so the median is
  offered as a copy into one project, with `days_source` saying where it came from — the same
  shape as "Copy from another project" on the Ideal quotas tab. Never a shared number: that is
  what per-project quotas were for.)*

**Scale caveat worth designing for:** two or three finished projects is not a sample. Show the
number of observations next to any figure, and never present a median drawn from one previous
show as though it were a benchmark. An over-confident average is worse than no average, because
people plan against it.

---

## Expense projection — verified against a real sheet

David built `RTC_ExpenseProjection_v07.xlsx` by hand from what the platform already holds, and
confirmed it reconciles: **35 artist rows** against the 36 crew slots the platform generated,
**184.43 projected person-days**, grand total $36,935.20. That makes it a specification rather
than a wish, so the mapping is written down here while it is known to be accurate.

**The boundary still holds.** Rates and costs stay in the separate finance application with its
own database and logins (`DECISIONS.md`, 2026-08-12). What belongs in *this* platform is
emitting the production half cleanly — `export.php` already does part of it.

### Column by column

| Sheet column | Comes from | Status |
|---|---|---|
| A · Department | **Nothing.** Steps have no phase grouping | **The one real gap — see below** |
| B · Role / Position | `pipeline_steps` name + scope — "Concept (Character)", "Color (Shots)" | Built. The `(name, applies_to, applies_to_asset_type)` triple, exactly |
| C · Artist Name | `crew` slot per lane — "Animator 01", "Env Layout Artist 02" | Built (migration 012). His hand-written names and the generated ones are the same idea |
| D · Rate Type (day / flat) | — | Finance side |
| E · Day Rate | — | Finance side |
| F · Total Days | Per-slot duration: work × days-per-item × retakes ÷ lanes | Built — the Duration column, 2026-08-13 |
| G · Total Months | F ÷ 22 | Derived, finance side |
| H · Projected Cost | E × F | Finance side |
| I · Total units count | `work_units` — 13 characters, 6 environments | Built; also frozen in `step_estimate_baseline` |
| J–M · Actuals and variance | Planned vs actual | Needs `time_logs`; the estimate half is the baseline table |
| N · Monthly cost (ref) | E × 22 | Finance side |

Note rows 25–28: Tie-down across four artists comes out 7.59 / 7.59 / 7.59 / **7.61**. That
uneven remainder is exactly what the scheduler's lane packing produces, which is a good sign
the two models agree.

### The gap: steps have no department

"Above the line / Pre-production / Production / Post-production" is the sheet's top-level
grouping and its subtotal boundary, and the platform cannot express it. `step_order` and the
dependency graph say what runs when, not which *phase* a step belongs to.

This is a **production** concept, not a financial one — it is how a crew list, a call sheet and
a Gantt are all organised — so it belongs in this codebase. Small: one column on
`pipeline_steps` (plus the templates), a field on the Pipeline wizard screen, and it groups the
timeline as well as any export. Worth doing before, or with, the timeline phase so bars can be
banded by phase.

### What to build here, eventually

A projection **export**, not a projection screen: one row per crew slot, carrying department,
role, slot name, unit count and total days — everything above except the money. The finance app
joins rates to it. `export.php` is already the right shape and already deliberately carries no
rates; this widens it to per-slot rows and adds the department column.

Everything else on this list is forms, tables and arithmetic. Review-and-approve
is video: upload, storage, playback across devices, versions side by side,
comments pinned to a timecode, approval state.

Two problems specific to Mana's situation:

**Shared hosting is the wrong place for video.** The current host already flaps
under normal load with no video on it. Animation review files are hundreds of
megabytes; a dozen artists uploading dailies would exhaust disk quota and
bandwidth quickly, and cPanel PHP upload limits (typically 64–256 MB) sit below
a single render.

**Frame.io and Dropbox Replay are mature products** with transcoding pipelines,
CDNs and mobile apps behind them. Matching that is not a phase; matching the
*useful 20%* might be.

A realistic middle path worth considering before committing: keep the **files**
wherever they already live (Dropbox, Drive, a render server) and build only the
**review layer** in the platform — a version record per shot pointing at a URL,
timecoded comments, approve/retake state feeding the same `statuses` everything
else uses. That gets the thing that actually matters, which is review state
being connected to production tracking rather than scattered across email and
WhatsApp, without the platform becoming a video host.

Decide this deliberately when it comes up. It is the one item on the list that
could quietly become bigger than everything above it combined.

---

## The Hiring screen: move what is left, or retire it

**Status as of 2026-09-02: orphaned on purpose. Nothing in the app links to it.**

Crew and Hiring were merged into one screen, **Staffing**, on David's call: "I
was asking to merge both of them and do all the staffing and hiring in the same
page." A position now carries its own candidates, their stage, and the Hire
button, so the whole loop happens on the row.

`hiring.php` still exists and still works if its address is typed. It is not
deleted, and it is not linked from anywhere: not the project tabs, not the
finance bar, not a person's page, not the "View details" button. David,
2026-09-02: "just leave it there but nothing should point to that, add it to the
roadmap to see later on what to do with it."

**Why it could not simply be deleted.** It is a second list of the same
positions, which is why it had to stop being reachable, but it is also the only
home of five things Staffing does not carry:

- **quoted rates** per candidate, admin only, in the finance database on
  `hire_quotes`;
- **contract states** — sent, signed — per person;
- **engagement dates**, when somebody starts and finishes on a show;
- **stage history**, the append-only `hire_stage_log`, which answers "how long
  has that offer been out";
- **adding candidates in bulk**, and **candidates tied to no position at all**
  (`hires.lane_id IS NULL`), which is somebody met at a festival.

**The decision to make.** Each of those five either moves somewhere it belongs or
justifies keeping a screen for itself:

- rates and contracts are money and arguably belong on **Finance management**,
  beside the expense projection they price;
- engagement dates belong with the **crew_absences** work already on this list,
  since both answer "is this person available";
- stage history belongs on the **artist record**, which is already where a
  person's facts live;
- bulk add belongs on **Artists**, which already has a five-row grid for it;
- candidates tied to no position belong on **Artists** too, since that is
  exactly what an artist with no approach is.

If all five move, the screen deletes itself. That is the likely answer, but it is
five separate pieces of work and none of them is urgent while the screen still
works for anybody who knows the address.

**Do not link to it again without deciding this.** A screen that is reachable
from two places and shows the same positions as another one is what this whole
run of work removed.

---

## Asked for on 2026-10-05

Four requests, in the order David gave them. Two of them are mostly **reporting on data the
platform already captures**; the other two need a decision before they can be built at all.

### Action items out of a call transcript, into the backlog

> *"add a transcript auto task generation from the tasks and tickets tab. It should read an md file
> and extract action items or to-dos from a call transcript and add them all to the backlog so we
> can classify them and assign them"*

**Where:** `tasks.php`, as a second way in beside the card form — the board already owns creating
tickets, their statuses are the project's own, and `includes/uploads.php` already takes a file.

**⚠ THE EXTRACTION IS THE WHOLE QUESTION, and it is not a small one.** "Read an md file and extract
action items" is either naive parsing or a language model, and they are very different builds:

1. **Parse a structured list.** The file arrives already shaped — markdown checkboxes or bullets,
   one line per item, optionally `@name` and a date. Small, predictable, no credentials, nothing
   leaves the studio. The transcript is turned into that list somewhere else, which today means
   Claude in a separate window.
2. **Send the transcript to a model from inside the platform.** Reads a raw transcript, which is
   what was asked for. It needs an API key stored on the server, an outbound call during a page
   load, a cost per use, and a decision about **client call transcripts leaving the building** —
   which is a studio policy question, not an engineering one. Nothing in this platform calls
   anything external today.

**Whichever is chosen, it imports like everything else imports.** Upload → see exactly what would be
created → confirm. The Plaky importer's whole shape exists for this reason and the lesson behind it
is dear-bought (DECISIONS, 2026-10-03: a screen that says "no loose ends" while dropping work). A
transcript reader that writes fifteen tickets straight onto the board would be the same mistake with
a different input.

**Open:** which of the two, and the policy answer if it is the second.

### How many versions a task took

> *"Once we migrate plaky to anima (or a separate platform) we need to count the number of versions
> a task took"*

**⚠ THE CAPTURE HALF IS ALREADY BUILT.** `progress_log` (migration 021) classifies every status move,
and one of its classifications is **`retake`** — defined there as "went BACKWARDS: was delivered or
approved, now is neither", and explicitly **a structural test rather than a name match, so it catches
a retake whatever the show happens to call it**. A "version" in David's sense is one of those.

So this is not blocked on schema and never was. It is blocked on exactly what he says: while Plaky
owns the statuses, the platform only sees the end state at import time, and a shot that went to
review and back three times in between arrives looking like one that went once. **The log can only
count what passes through this platform.**

**What it unlocks is bigger than a number.** The retake allowance is a percentage somebody types in
(DECISIONS, 2026-08-12 — retakes inflate days-per-item and never work units). Counting real versions
turns that guess into a measurement, per step and per show, which is the same argument the
cross-project benchmarking section above makes for duration.

**Where it would live:** the step page already lists per-item history; a versions column belongs
beside it, and a per-step average belongs with the benchmarking work.

### How many people were passed over for a position

> *"Add a way to count how many people passed or weren't hire for a particular position"*

**⚠ THIS DATA IS ALREADY THERE, in both shapes.** `hires.stage` carries `declined` (they said no) and
`passed` (we said no) as **separate values on purpose** — DECISIONS, 2026-08-12: "they are identical
in a count and opposite in meaning, and which one a pipeline is full of is the difference between
paying too little and looking in the wrong place". And `hire_stage_log` (migration 024) records every
move between stages with its date, so the *history* is there as well as the current state.

Nothing needs capturing. This is a **report**, and a small one: group `hires` by lane and by stage.

**Where:** the Staffing screen already draws each position with its candidates, so a line per
position — *"5 approached · 2 declined · 1 passed · 1 hired"* — reads naturally there. A cross-show
version belongs beside Historic fees and Historic quotas, which already answer "what does this
position usually cost" and would then also answer "how hard is it usually to fill".

**One thing to decide:** per position on one show, per position across every show, or both. The
second is the one that tells you Compositors take four conversations and Layout artists take one.

### Kick-off dates, and a warning when a delay runs past 15%

> *"Register the kick off start and due date dates. So if a task gets delayed there's a way to get a
> notification when the delay exceeds 15% of either the start or due dates."*

**The baseline already exists.** `step_estimate_baseline` (DECISIONS, 2026-08-13: "the starting
estimate is frozen, and never overwritten") is precisely a record of what was agreed at kick-off, and
`schedule` holds what the plan says today. The comparison has both sides.

**⚠ "15%" NEEDS PINNING DOWN BEFORE ANY OF IT CAN BE BUILT**, because the obvious readings give very
different answers on the same task:

- **15% of the task's own duration** — a four-day step warns at 0.6 of a day. Short steps shout,
  long ones stay quiet through a week of slippage.
- **15% of the time from kick-off to the date in question** — a step due three months out tolerates
  two weeks; one due on Friday tolerates hours. Probably closer to what is meant.
- **15% of the whole show's length** — one threshold for everything, which is the simplest to explain
  and the least sensitive.

**And delivery is its own question.** `notify_assignee()` sends mail inline, and DECISIONS already
records that this **blocks the save** until SMTP answers — so a per-task warning cannot hang off the
moment a date moves without making every date change slower. The honest options are an on-screen
flag (free, and the dashboard already shows overdue steps), or a daily digest, which needs something
to run once a day — and snapshots are deliberately taken on page load rather than by cron
(DECISIONS, 2026-08-13), so a digest would be the first scheduled job this platform has.

**Open:** which percentage of what, and whether a warning on screen is enough before anything is
emailed.

## Waiting on a decision, or offered and not built (2026-09-18)

Everything here came up in the 2 to 18 September run of work. None of it is urgent, and each
one is a choice David has not made yet rather than work anybody forgot.

### Should a timeline bar carry the steps downstream of it?

Dragging a bar on the general timeline moves **every step that waits for it** by the same
amount, and saves them all. That is Frappe Gantt's own behaviour, and it has always worked this
way here. It was never chosen, and it sits awkwardly with the 2026-08-31 rule that dependencies
are drawn, not enforced. It surfaced on 2026-09-18, when a drag the library refused still moved
MSD's Comp · shots. The refusal is fixed; the carrying is not changed.

- **Keep it:** shifting Animatic shifts the whole show, which is sometimes exactly the point.
- **Stop it:** every bar moves alone and the red arrows say what the move broke. Small to
  build, the same way the refusal was lifted: `freeBarsFromDependencies()` in
  `assets/timeline.js` shows how to change one library behaviour for the general chart only.

### Backgrounds: work counted per background, not per environment

Doomsday has 4 environments and needs 2 to 3 times as many backgrounds. David
asked whether coverage could go above 100%. **Advised against** (2026-09-16): the percentage only
reaches the planning maths and the budget, while the calendar, the dashboard and progress count
real items, so they would go on counting 4 environments and call Layout finished after 4.

The recommendation is to track **Background** as an asset type of its own, since that is what
is made, reviewed and delivered one at a time: environments keep their once-per-location steps,
and Layout and BG paint move to Background. Two ways to plan it before the list exists:

1. **Placeholders now, no build:** paste `BG 01, Background` … on the Assets screen and rename
   them when the animatic settles. The calendar, dashboard and budget all count the same items.
2. **A "roughly how many" estimate per asset type**, used by `step_work_units()` until real
   items of that type exist, exactly as `estimated_assets` already stands in for untyped steps.
   Small; it would sit in the Work cell of Dates & headcount.

David has not chosen, nor said which steps happen once per background.

### Renaming a project type

Not built. It would have to rewrite `projects.type` and `pipeline_templates.project_type`
together. The workaround: create the right name, move each project on its Basics screen, re-save
any template for the new type, then remove the old type from the Basics panel.

### Crew records nothing points at

Offered 2026-09-03, not built: a clean-up for `crew` rows left behind by the old
rename-the-slot hiring, which no position, task, ticket or login refers to any more. The merge
tool (`people_merge.php`) handles the duplicates that ARE referred to.
