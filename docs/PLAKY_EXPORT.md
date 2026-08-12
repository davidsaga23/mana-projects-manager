# The Plaky export — what's actually in it

Analysed 2026-08-12 from `STFC2_Main table_08-12-2026_10-42-10-am.csv`
(1,918 rows). Written for whoever builds the importer, so the shape and its
traps don't have to be rediscovered.

---

## Shape: one board, parents and subitems

Columns:

```
Group, Creation Date, Created By, Item/Subitem Link, Item Parent Id,
Item/Subitem Id, Title, Time spent, Status, Notes, Assignee, Timeline, Total frames
```

**A row is a parent when `Item Parent Id` is empty.** That single test splits the
file cleanly:

| | Count | What it is | Maps to |
|---|---|---|---|
| Parents | 243 | A shot or an asset | `shots` / `assets` |
| Subitems | 1,675 | One pipeline step on its parent | `progress` |

- Parent `Title` → shot code (`SH010`) or asset name (`KemociteCanister`)
- Subitem `Title` → **step name** (`Assembly`, `Modeling`)
- `Item/Subitem Id` → `external_id` (stable, use it to match on re-import)
- `Item/Subitem Link` → deep link back into Plaky, if ever wanted

The arithmetic checks out exactly: 8 shot steps × 188 shots = 1,504, plus 171
asset step rows = 1,675 subitems. Nothing is missing or duplicated.

---

## `Group` does two different jobs

| Group | Parents | Meaning |
|---|---|---|
| EP001 … EP009 | 188 total | **episode** |
| Characters | 22 | **asset type** |
| Environments | 16 | asset type |
| Props | 9 | asset type |
| Ships | 6 | asset type |
| Pre Production | 2 | asset type (loosely) |

Per-episode counts — EP001 25, EP002 21, EP003 22, EP004 13, EP005 15, EP006 28,
EP007 16, EP008 22, EP009 26 — match STFC's seeded 188 exactly.

**So the importer cannot infer shot-vs-asset from the row alone.** Don't pattern
match on `^EP\d+`: it works today and breaks the first time a group is called
"Season 2" or "Pilot". Make group mapping an explicit step in the import UI —
each group shown with its row count and a choice of *episode of shots* / *asset
type* / *ignore*. Explicit beats clever, and it doubles as the preview that
proves the file was read correctly.

---

## Traps — every one of these is a real defect waiting to happen

**1. `Lightning` is a typo for `Lighting`.** The board has spelled it that way
188 times; STFC's database says `Lighting`. A step alias
(`name_aliases`, `kind='step'`) is needed on day one — this is the exact case the
alias layer exists for, sitting in the data already.

**2. `(inactive)` is appended to deactivated people.** Six of them:
`Mayara Sampaio (inactive)`, `Erfan Abdi (inactive)`, `Doug Silva (inactive)`,
`Marcos Elias (inactive)`, `Yowel.velasco (inactive)`, `Michael Abarca (inactive)`.
Strip the suffix before matching or the same person becomes two crew records —
and the suffix is itself useful: it means `crew.active = 0`.

**3. One field can hold several assignees.** `Júnior Fonseca,Michal Pilch`
appears on 22 rows, comma-separated *inside* the field. **`progress.crew_id` holds
one person, so this needs a decision** (see Open questions below). Whatever is
chosen, `assignee_raw` must keep the original string.

**4. Handles, not names.** `Oswaldoanubis`, `Animator.wasi`, `Rashid.animator`,
`Mauricioasa`, `Apolo`, `Arina`. Straight into `crew_aliases`.

**5. `On Hold` is a status STFC never mapped.** The board uses nine statuses;
STFC's `status_mapping` seeds ten and `On Hold` is not among them, so today it
silently falls through to "counts as nothing". Exactly what the unmatched report
is for.

| Status | Rows | Suggested `maps_to` |
|---|---|---|
| Waiting to start | 1,004 | none |
| Completed | 554 | approved |
| In progress | 145 | none |
| Ready to start | 116 | none |
| N/A | 47 | none |
| Final | 30 | approved |
| Pending review | 15 | delivered |
| On Hold | 5 | none ← **new, confirm with David** |
| Retake | 2 | none |

STFC also mapped `Ready for review` and `In client review` (both → delivered);
neither appears in this export but both should stay in the seed list.

**6. Dates are `DD/MM/YYYY`.** `05/08/2026` is 5 August, not 8 May. Parse it
explicitly — never hand it to a generic date parser and hope.

**7. `Total frames` is entered inconsistently** — 43 on parents, 41 on subitems,
84 of 243 shots in total. Pick one rule (parent wins, fall back to any subitem)
and *report* how many shots ended with no frame count rather than leaving zeros
to be discovered later.

---

## Sparse by design

Only 84 of 243 parents have `Total frames`; `Timeline` is filled on 113 rows of
1,918; `Time spent` on 20; `Notes` on 268. Every one of these is optional — the
importer must never treat a blank as an error or overwrite good data with it.

Asset pipelines are genuinely ragged, unlike shots:

| Step | Rows | | Step | Rows |
|---|---|---|---|---|
| Body MoCap | 188 | | Modeling | 52 |
| Face MoCap | 188 | | LookDev | 46 |
| 3D Layout | 188 | | Rigging | 36 |
| Extra Anim & Polish | 188 | | Blendshapes | 13 |
| Assembly | 188 | | Concept | 13 |
| Lightning *(sic)* | 188 | | Crowd Animation | 11 |
| Render | 188 | | | |
| Comp | 188 | | | |

Every shot carries all 8 steps; assets carry only what applies (55 assets, but
Modeling appears 52 times). **Never assume a full grid** — build `progress` rows
from what the file contains, not from every step × every item.

`Body MoCap` and `Face MoCap` are tracked in Plaky but were hidden on STFC's
dashboard; that's what `pipeline_steps.show_in_dashboard` is for.

`Timeline` holds a single date, never a range — 0 of 113 values contain a range
separator. Likely a due date.

`Time spent` values look like `960`, `1020`. Units unknown; ask before using it
for anything.

---

## Three parents are not assets

Only 3 of 243 parents have no steps beneath them, and none of them is a real asset:

| Group | Title | Why it exists |
|---|---|---|
| Characters | CG Sup | A supervision-time bucket — 3,340 minutes logged against it |
| Pre Production | Storyboard | A production deliverable, not an asset |
| Pre Production | Animatic | Same |

A childless parent is therefore a reliable signal of "this isn't a shot or an
asset". The importer should list them and let them be skipped, rather than
creating three assets that no pipeline step will ever touch.

---

## Answered 2026-08-12 (was: open questions)

**Multiple assignees — yes, build the join table.** `progress_crew (progress_id,
crew_id)`, with `progress.crew_id` kept as the primary assignee. See `DECISIONS.md`.

**`On Hold` means blocked, not idle.** It maps to `maps_to='none'` *and*
`is_blocked=1`. It doesn't count toward completed/approved seconds, but it is
distinct from "not started": someone has to unblock it. Reported separately from
remaining work.

**`Time spent` is wanted — shown in hours, stored in minutes.** It serves two
jobs David named: checking artist invoices against logged time, and comparing
planned dates against actual effort. Hours are what everyone reads and types;
`progress.time_spent_minutes` holds whole minutes underneath, because decimal
hours drift when summed (20 min = 0.333…) and these numbers get compared against
money.

*This file's* values are minutes — inferred, not documented. Every value but one
is a multiple of 30, and as minutes they read 3 h–17 h per task (Concept 7–17 h,
Extra Anim & Polish 3–6.5 h), which is credible; as seconds they would be 3–17
minutes per task, which is not. The outlier 3,340 is 55.7 h of accumulated
supervision on the `CG Sup` item. **Worth confirming against one task you
remember before trusting it against an invoice.**

> ⚠️ **The source unit is going to change.** David intends to log time in hours.
> When that happens Plaky will emit `16` where it used to emit `960`, both
> meaning 16 hours. An importer with "minutes" hardcoded would silently store 16
> minutes — a 60× error landing exactly in the figures used to check invoices.
>
> So **the import screen must ask which unit the file is in**, show the first few
> values read both ways so the right answer is obvious, and record what it used.
> Never infer it per-file at runtime: the inference above works on this export and
> would quietly fail on a mixed or short one.

Note that only 20 of 1,918 rows carry any time at all — Plaky's time data is
effectively empty today, so this column's real value arrives when the platform
owns time entry (a per-day `time_logs` table rather than one total per task).
