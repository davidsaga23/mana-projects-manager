# Requested features — where each one belongs

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
| Crew management — time log | P | Phase 3 | Per-day entries, not one total per task |
| Crew management — invoice upload + status | **F** | time log | Documents and money; not this codebase |
| Budget expense projections | **F** | rates, headcount | **Verified against a real sheet — see below.** Production half exports from here; money stays finance-side |
| Step departments (pre-prod / production / post) | P | — | Small, and the expense projection's grouping depends on it. Also bands the timeline |
| Budget vs actual at project end | **F** | time log, rates | Needs real logged hours to mean anything |
| Budget simulator — "what if we add people?" | **F** | scheduler | Production already computes the hard half |
| Payments / invoice tracking hub | **F** | invoice upload | Which invoices are paid |
| Cross-project benchmarking — estimated vs actual per step | P | estimate baseline, time log | **Read the section below.** The capture half is urgent; the reporting half isn't |

### Deliberately last (decided 2026-08-12)

Both of these wait until everything above is working. Neither blocks anything else,
and both are large enough to stall the rest if started early.

| Feature | Side | Depends on | Notes |
|---|---|---|---|
| Online version review (Frame.io / Dropbox Replay style) | P | media hosting | **Largest item. Read the caution below** |
| Own tracking system — replacing Plaky | P | Phase 3 | The status grid, plus artist logins if artists update their own work. Phase 3 deliberately builds the status model so this becomes screens, not schema |

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
  remembers.

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
