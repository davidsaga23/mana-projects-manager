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
