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
| Budget expense projections | **F** | rates, headcount | Mana already does this in a spreadsheet |
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

### THE URGENT PART — estimates are overwritten in place

`pipeline_steps.days_per_unit`, `coverage_pct` and `planned_headcount` are edited directly on
the Dates & headcount screen. Every edit destroys what was thought before it. So the single
most valuable number for improving estimates — **what we guessed at kickoff, next to what it
actually took** — is being lost on every project, silently, right now.

The fix is small and should not wait for the reporting screens:

- A `step_estimate_baseline` table (project_id, step_id, the estimate fields, `captured_at`,
  `reason`), written when the wizard's Review screen sets a project to `active`.
- Immutable afterwards. A deliberate **re-baseline** action can add a new row when a plan is
  genuinely replanned — so the history reads as "we thought 2 days, then revised to 3, and it
  took 3.4" rather than silently becoming "we always said 3".
- Cheap now; impossible to backfill. Exactly the `project_id`-on-day-one argument.

### What's already in place

| Piece | Status |
|---|---|
| Global step vocabulary (`step_library`) | Built (migration 002) — the join key |
| Step scope triple as identity | Built (migration 009) |
| Per-step estimates | Built, but **mutable** — see above |
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

## Caution: online version review is a different class of project

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
