# Anima — Migrations ledger

**This is the authoritative record of which database change unlocks which feature, and which are
still pending.** Code ships to `master` (and goes live) ahead of the database; a feature that needs
a migration stays **dormant and harmless** until the migration runs, because every query guards on
the new column/table existing. **Do not assume a migration is applied just because the code is
merged.**

## Two kinds of database change

1. **Production DB migrations** — the numbered `migrations/NNN_name.sql` files. Applied by opening
   **`migrate.php`** in the browser (login required). It lists what is pending, runs nothing until
   the button is clicked, records each in `schema_migrations`, and reports applied/skipped/failed.
   Rules (see `migrations/README.md`): numbered ascending and never reused; **additive only** (never
   DROP data); each statement written to survive being re-run (`IF NOT EXISTS`, `INSERT IGNORE`,
   `UPDATE … WHERE x IS NULL`).
2. **Finance DB imports** — the `finance_install*.sql` files, imported **by hand through phpMyAdmin
   into the SEPARATE finance database.** `migrate.php` does **not** and must **not** touch the
   finance DB. **Run each finance file exactly once** — there is no `schema_migrations` on that side,
   so a second import fails on "duplicate column" (that failure is harmless and just means it was
   already done).

The app shows an **"updates waiting"** banner when production migrations are pending.

---

## ⚠ Pending right now (as of 2026-10-21) — David to run

**Production (open `migrate.php`):** `047, 048, 049, 050, 051, 052, 053, 054, 055, 056, 057, 058, 059, 060 (both `060_crew_absences` and `060_notifications`), 061, 062, 064`. *(063 is being used by another branch in progress.)*

**Finance (import by hand into the finance DB via phpMyAdmin):** `finance_install_08.sql` (the
money side of the kick-off sign-off, needed together with migration 049).

Each is described below. Until they run, the matching features are switched off but cause no harm.

### ⚠ Renumbering history — so no one repeats a collision
Two migrations were renumbered because two branches grabbed the same number in parallel. If you see
these referenced by their old numbers anywhere, this is why:
- **Snooze was originally `053`**, collided with `053_mytasks_seen.sql`, and was renumbered to
  **`054_ticket_snooze.sql`** (via PR #22).
- **The view-as audit trail was originally `055`**, collided with `055_last_seen.sql`, and was
  renumbered to **`056_view_as_audit.sql`**.
- **To avoid this in future: always `git fetch origin master` and check the highest existing
  migration number before adding a new one.**

---

## Full ledger

Applied status is "✅ applied" up to and including 046 (confirmed live), and "⏳ pending" for
047–058. Finance imports 01–07 are applied; 08 is pending.

### v1 baseline + early schema (001–016)
| # | File | What it unlocks |
|---|---|---|
| — | `install.sql` | ✅ The v1 baseline. Fresh install only; **never run against a live DB — it drops every table.** |
| 001 | step_planning_fields | ✅ Planning fields on pipeline steps (days per unit, coverage, headcount). |
| 002 | step_library | ✅ Global step vocabulary (`step_library`) — the spine for cross-project comparison. |
| 003 | fractional_headcount | ✅ Fractional headcount/precision on planning. |
| 004 | status_model | ✅ The platform's own status vocabulary (`statuses`, `status_mapping`) + `maps_to`. |
| 005 | user_roles | ✅ User roles on logins. |
| 006 | project_level_steps | ✅ Steps that apply to the whole project (SFX, mix, master). |
| 007 | step_asset_type | ✅ A step can target one asset type. |
| 008 | step_coverage | ✅ `coverage_pct` on steps. |
| 009 | step_name_scope | ✅ A step's identity is `(name, applies_to, applies_to_asset_type)`. |
| 010 | scheduler | ✅ Scheduler tables + `statuses.is_excluded` (the board's N/A). |
| 011 | snapshot_history | ✅ `daily_snapshots` get a writer (progress history, can't be backfilled). |
| 012 | crew_placeholders | ✅ Crew slots/lanes — named positions the scheduler assigns to. |
| 013 | estimate_baseline | ✅ `step_estimate_baseline` — the estimate frozen at kick-off, append-only. |
| 014 | us_holiday_calendar | ✅ `non_working_days` so the scheduler + quotas skip holidays. |
| 015 | step_departments | ✅ `pipeline_steps.department` (pre-prod / production / post grouping). |
| 016 | step_library_department | ✅ Department on the global step library. |

### Tickets, finance split, time, hiring, project lifecycle (017–046)
| # | File | What it unlocks |
|---|---|---|
| 017 | tickets | ✅ Tickets/tasks board on the project's own statuses, threaded comments, attachments. |
| 018 | crew_department_and_terms | ✅ (Superseded by 019) briefly put rates on crew. |
| 019 | terms_move_to_finance_db | ✅ Removes rates from the production DB — money moves to the finance DB. |
| 020 | progress_version_and_notes | ✅ Per-step version (free text) + notes on an item. |
| 021 | progress_log | ✅ The status-change log + **retake classification** (backwards move). |
| 022 | time_logs | ✅ Per-day effort logging, entered from My tasks. |
| 023 | project_brief | ✅ The Brief screen. |
| 024 | hiring | ✅ Candidates per position, `hire_stage_log`. |
| 025 | hire_freelancer_fields | ✅ Bulk candidate paste in the freelancer-sheet column order. |
| 026 | quota_standards | ✅ Per-type ideal-quota sheets (kept but unread once 034 applies). |
| 027 | project_archived | ✅ Project status enum (`setup/active/on_hold/wrapped/archived`). |
| 028 | milestones | ✅ Client-review milestones on the timeline. |
| 029 | milestone_step | ✅ A milestone tied to a step's row. |
| 030 | artists_no_project | ✅ Artists in the database with no project yet. |
| 031 | portfolio_password | ✅ Reel/portfolio password on an artist. |
| 032 | project_types | ✅ Project type as an editable label, not a fixed enum. |
| 033 | step_days_precision | ✅ Precision for per-step days-per-unit. |
| 034 | ideal_quotas_per_project | ✅ Ideal quotas become **per project** (read via `effective_days_per_unit()`). |
| 035 | timeline_versions | ✅ "Save timeline's version" — a frozen drawing of both charts + milestones. |
| 036 | step_progress | ✅ Recorded progress on a step (the general timeline's fill). |
| 037 | role_artist | ✅ The read-only **artist** role (one write: status of their own assigned work). |
| 038 | ticket_about | ✅ A ticket can name a shot/asset/step. |
| 039 | ticket_threads_mentions | ✅ Ticket threads + @mentions. |
| 040 | mention_seen | ✅ "Seen" state on mentions. |
| 041 | ticket_no_project | ✅ **Nullable `tickets.project_id`** — capture a card before deciding its show (Studio Tasks). |
| 042 | shot_type_na | ✅ Mark a breakdown cell N/A when a shot needs none of an asset type. |
| 043 | time_timers | ✅ The live stopwatch on My tasks rows. |
| 044 | project_postmortem | ✅ A `postmortem` status between `wrapped` and `archived`. |
| 045 | studio_project | ✅ "The Studio" — one project row that is not a show, for cards belonging to none. |
| 046 | status_cbb | ✅ A CBB ("could be better") status. |

### The pending batch (047–058) — code merged, database change NOT yet run
| # | File | What it unlocks (dormant until run) |
|---|---|---|
| 047 | role_supervisor | ⏳ The **Supervisor** role — artist perms + view-only Timeline/Staffing + editable Shots. |
| 048 | pomodoro | ⏳ The My-tasks timer runs in **Pomodoro** cycles. |
| 049 | kickoff_signoff | ⏳ **Kick-off sign-off:** `setup` → `active` only once named signers approve; what they approved is kept. **Also needs `finance_install_08.sql` imported by hand into the finance DB.** |
| 050 | collaborator_type | ⏳ (Superseded by 051) Freelance/Vendor on the staffing *position*. |
| 051 | collaborator_type_on_artist | ⏳ Freelance/Vendor moves to the **person** (`hires.collaborator_type`, Freelance default), edited on Artists + person page, shown as a capsule in Staffing. Drops the 050 column. |
| 052 | overhead_time_categories | ⏳ The **Studio-time** overhead panel on My tasks (each role sees its own buckets). Ships with the **Editor → "Production"** display rename (no access change). |
| 053 | mytasks_seen | ⏳ A "last looked at My tasks" time, so the nav badge can tell **NEW** from merely open. (The count works without it; only the "new" colour needs it.) |
| 054 | ticket_snooze | ⏳ **Snooze** a card to a future wake-up date (`tickets.snoozed_until`); hidden from active lists until then. *(Was 053 — renumbered after a collision.)* |
| 055 | last_seen | ⏳ "Last seen" becomes real last-**activity** (`users.last_seen`, stamped per page view, throttled), not stale last-login. |
| 056 | view_as_audit | ⏳ Audit trail for "view the app as a specific person". **Audit only — the view-as feature itself works whether or not this runs.** *(Was 055 — renumbered after a collision.)* |
| 058 | status_begun | ⏳ `statuses.counts_as_started` — which of a show's statuses mean somebody has picked the work up, so a shot reads **In progress** as soon as one task does. **The Board is already right without it** (it falls back to an explicit list of spellings); this makes it editable per show on the Statuses screen. |
| 060 | crew_absences | ⏳ **Days off per person** (`crew_absences`), entered on the person page. The scheduler plans each person's lane around them, and around their engagement dates on the show, the next time the schedule is run. Without it, engagement dates already count; only days off are switched off. |
| 065 | review_version | ⏳ **Reviews on versions** (`ticket_comments.version_id`) and **a link to the final files** on each version (`progress_versions.link`). Until it runs, reviewers see the plain upload form, the Notes column stays empty and the link field is hidden. |
| 066 | task_events | ⏳ **The task Timeline's row changes** (`progress_events`): reassignments, due dates, and edits to a task's note and link, logged from now on. The Timeline works without it, showing statuses, versions, comments and reviews. |
| 067 | naming_convention | ⏳ **The show's file naming convention** on the Brief (`project_brief.naming_*`), checked on every version upload. Until it runs, the section is hidden and nothing is checked. |
| 068 | supervision_positions | ⏳ **Supervision positions** (`supervision_positions`): Anim Sup, CG Sup and the like, with a start and end date and no quota, set on Dates & headcount, filled on Staffing, costed on the expense projection. Until it runs, the Supervision section says it needs this update and nothing else changes. |
| 069 | hire_supervision | ⏳ **Candidates for a supervision position** (`hires.supervision_id`): line people up for Anim Sup / CG Sup on Staffing and hire them. Until it runs, those rows say they need it; the rest of supervision (068) works without it. |
| 057 | progress_link | ⏳ **The Board's Link column** (`progress.link`) — a Replay or LucidLink address per task. The Board itself works without it; only that one column is switched off. |
| 060 | notifications | ⏳ **The bell** beside your name (shares the number 060 with crew_absences; both run, they are recorded by filename). |
| 061 | comment_files | ⏳ **Files on a Board comment.** |
| 062 | bug_reports | ⏳ **Bug reports** (`bug_reports`, `bug_attachments`): the 🐞 button on every screen and the Bugs page. Hidden entirely until run. |
| 064 | bug_needs_info | ⏳ **Bug reports wait for David** (`needs_info`, `flag_reason`, `admin_notes`, `fix_note` on `bug_reports`): video reports are flagged for a description, admins write notes for Claude, and the export records how each was fixed. Needs 062 first. |

### Finance DB imports (by hand, phpMyAdmin, once each)
| File | What it unlocks |
|---|---|
| finance_install.sql … _03 | ✅ The finance DB baseline (rates, budgets, invoices). |
| finance_install_04 | ✅ Hiring quotes + contract status. |
| finance_install_05 | ✅ Other (non-labour) expenses. |
| finance_install_06 | ✅ Per-second rates. |
| finance_install_07 | ✅ Finance Overview's seven cost figures (incl. an unused `revenue_amount` column, kept not dropped). |
| finance_install_08 | ⏳ **The money side of the kick-off sign-off — import with migration 049.** |
| finance_install_09 | ⏳ **Invoice status becomes Expected / Pending / Paid.** Received, approved and disputed become pending; a disputed one keeps "Disputed." at the front of its note. Until it is imported, the dropdown offers the old four. |
| finance_install_11 | ✅ One exchange rate per project per foreign currency (`project_fx_rates`, `project_fx_rates_log`). Imported by David 2026-10-28. |

---

## Doing a fresh install (if ever needed)
1. Import `install.sql` (production DB) — baseline only.
2. Open `migrate.php` and apply every pending migration in order.
3. Import each `finance_install*.sql` by hand into the finance DB, in order, once each.
4. Create `config.php` from `config.example.php` on the server (DB creds, SMTP, the finance_db
   block, timezone `America/Los_Angeles`, and the Anthropic key if the transcript/AI feature is
   wanted). `config.php` lives only on the server and is never in git or deployed.
