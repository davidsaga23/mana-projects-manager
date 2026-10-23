# Anima — Feature changelog

Plain-language record of what shipped, newest first, so a new account can see what already exists
before proposing it again. Each entry notes whether it needed a database migration (see
`docs/MIGRATIONS.md` for which migrations are still pending — a few of the newest features are
**merged and live in code but dormant until David runs their migration**).

For the deeper reasoning behind any of these, see `docs/DECISIONS.md` (append-only, dated) and the
build logs in `docs/ROADMAP.md`. This file is the "what", those are the "why".

> **PR numbers** are the GitHub pull-request numbers as merged to `master`. A handful of the
> commit-message `(#NN)` tags differ from their merge-PR number because branches were merged out of
> order; where that happens the date and description are the reliable part.

---

## 2026-10-22 — Bug reports reach Claude; videos wait for David's description *(needs migration 064)*

- **The Section list on the 🐞 form only offers screens the person can open.** An artist no longer
  sees Historic fees, Finance, Users, Dashboard, Staffing and the other screens hidden from them.
- **A report filed with a video is marked 🎥 and flagged "Waiting for your description"**, because
  Claude can read screenshots but cannot watch a video. A new **Needs your description** filter on
  the Bugs page lists them, and admins write **notes for Claude** on any report; saving a note
  clears the flag.
- **Claude can pull every open report, screenshots included, and close it after fixing it.**
  `bugs_export.php` answers only requests carrying the secret `bug_export_key` from `config.php`;
  `tools/pull_bugs.py` downloads into `bug-inbox/`; the `/bugs` command works through them, opens
  a PR per fix, marks the report Fixed with the PR number, and flags anything unclear back to David.
- **Setup once:** put a long random `bug_export_key` in the server's `config.php` and the same value
  in `tools/.bug_key` on the machine that runs Claude.

## 2026-10-22 — A 🐞 Report a bug button on every screen *(needs migration 062)*

- **A Report a bug button, bottom left of every screen**, for everyone, artists and supervisors
  included. It asks for the **section** (preselected from the screen you are on), **what went
  wrong**, and **screenshots, GIFs or videos**. Your **name, your role and the date** are added
  automatically, along with the page you were on.
- **A Bugs link in the top bar** opens `bugs.php`: admins see every report (with a count of the
  open ones) and can mark each **Fixed**, **Won't fix** or reopen it; everyone else sees their own.
- Oversized files are refused in the form before sending, so the description is never lost.
- The button stays hidden until migration 062 is applied.

## 2026-10-22 — The Board is now called Spell *(no migration)*

- **The Board button and screen are renamed Spell**, and the page moved from `board.php` to
  `spell.php`. `board.php` redirects there, so old bookmarks and mention emails still open it.

## 2026-10-22 — Days off, and the schedule plans around them *(needs migration 060)*

- **A Days off section on every person page**: a first and last day and a reason. It counts on
  every show the person is on.
- **The scheduler now plans around each person's days off and their start and finish dates on the
  show.** Their tasks skip those days, and work goes to whoever on the step can finish it first.
  Re-run the schedule to see it; saved plans and hand-placed bars do not move by themselves.

## 2026-10-22 — Timeline: a dragged bar moves alone *(no migration)*

- **Dragging a step on the general timeline moves only that step.** The steps that wait for it no
  longer come along. The dependency arrows are still drawn and turn red where the move broke one.

## 2026-10-22 — The Board: a task panel *(no migration)*

- **Clicking a task opens it in a panel over the board**, so you can open one, adjust it, close it
  and open the next without leaving the page. Escape or the backdrop closes it.
- It carries the same Status, Assignee, Due, Link and Notes controls as the row — edit either, both
  follow — plus the **version label**, **hours logged**, and the task's own **history**, which says
  who changed what and whether it came from a person or the Plaky importer.

## 2026-10-22 — The Board: the screen that replaces Plaky *(needs migration 057 for the Link column only)*

- **A new Board**, reached from a button at the top of every page, with a show picker — one screen
  for the whole studio rather than a tab inside each show.
- Shots and assets grouped by episode or asset type, each opening to show its pipeline steps.
  Whole-show deliverables (an animatic, a colour script) sit in their own group with no sub-rows,
  exactly as they do in Plaky.
- **Status, assignee, due date, link and notes all save in place**, with no page reload. A refusal
  puts the old value back and says why.
- **A shot's own status is worked out from its tasks**, so nobody has to keep it up to date.
- **Frames are shown, never edited** — they come from the breakdown.
- **A due date typed here is the timeline's own date.** Setting one moves that bar and marks the
  task hand-planned, so re-running the schedule will not overwrite it.
- **Artists** see the shows they are staffed on and may set their own work to In progress or
  Pending review, the same limit My tasks keeps. The other statuses are listed but greyed.
- Search, expand-all and collapse-all across the board, plus a menu on each group for that
  group alone. Shots and assets start collapsed.
- **A bar across the top for the whole show**, and one on **each shot and each group** made of its tasks' own status colours,
  filling from the left as work finishes. Hovering one lists how many tasks are in each status.
  The bars line up down the column whatever the shots are called.
- **A shot or asset can carry its own note**, about the thing itself rather than about one
  step's work on it.
- **The headings stick to the top**, with filters on Status, Assignee and Due, a count of what
  matches, and a Clear button. Due filters by overdue, today, yesterday, this week (Mon to Fri),
  the next 7 days, a specific date, a range, has a date, or no date.
- **A shot reads In progress as soon as one of its tasks has been picked up**, rather than waiting
  for something to be finished. Which statuses mean that is now a tick on the Statuses screen
  *(migration 058 — the board is already right without it)*.
- Groups are named for the studio's phases — Pre-production, Production, Post-production —
  with the episode or asset type beside the name.

## 2026-10-21 — Budget pool: who is on each step *(no migration)*

- **A step's detail on the Budget pool lists everyone on it.** For each person: rate, budget in
  days and money, used, unused, invoiced, work done, and a plain reading. Over budget shows in
  red, worst first.

## 2026-10-21 — Budget pool donuts *(no migration)*

- **The Budget pool shows used and unused days as interactive donuts**, for the whole show and per
  step. Hovering a part says how many days it is. "Left" now reads "Unused".
- **Each step's detail has a status pie** beside the days: its shots or assets by status, with a
  legend, and hovering works there too.

## 2026-10-21 — Expense projection in whole days *(no migration)*

- **Every row's days are rounded up to a whole day on every project's expense projection.** Months,
  cost, subtotals, the total and the Excel export follow. Hover over a Days cell to see the
  unrounded figure. Projected costs rise slightly as a result.

## 2026-10-21 — Admins can make changes while viewing as a person *(no migration)*

- **"View the app as a specific person" now saves.** The admin acts as that person, with that
  person's permissions and on that person's own work, and everything is recorded as done by the
  admin. Previewing a generic role is still read-only.

## 2026-10-21 — My tasks: filters on Tasks/Tickets *(no migration)*

- **Tasks/Tickets on My tasks has column filters** on Project, Type, Status, Priority and Due, each a
  dropdown of what is on the list (Due also offers "(nothing)" for cards with no due date), with a
  live "N of M cards" count and a Clear button. Ref and Title keep their sort buttons only.

## 2026-10-21 — supervisor hiring, artist statuses, time removal, step days (PR #29 + follow-up, merged)

All four need **no migration**.

- **Supervisors can press Hire on Staffing**, and only Hire (the stage dropdown, adding candidates
  and filling slots stay with Production). **Every hire now emails the administrators** who hired
  whom, into which position, on which show. A hire by someone who cannot see rates does not carry a
  quoted rate across; the email says so.
- **Artists and supervisors set their own work only to In progress or Pending review** on My tasks.
  Anything else is Production's.
- **Production can remove any time entry on their own My tasks list**, including ones somebody else
  typed for them. Artists still remove only what they typed.
- **Dates & headcount has a Business days column**: the business days from start to deadline, both
  counted, weekends and studio holidays left out. (First shipped as calendar days plus working days,
  changed the same day.)

## 2026-10-21 — the latest batch (all merged to `master`, live)

- **PR #26 — Per-user project visibility + "view the app as a specific person".** *(needs migration
  056, for the audit trail only — the feature works without it.)*
  Two linked things. (1) Non-admin users now see only the shows they are **staffed on**; admins and
  Production still see everything; supervisors and artists are scoped. Reuses existing staffing data
  (`crew_projects` + `users.crew_id`) — no new "who sees what" table. (2) An admin can **preview the
  app as a specific named person** (read-only) from the Users page — their My tasks, their nav
  badge, their scoped project list — a step beyond the existing "preview as a role".
  **Known boundary:** this scopes the project *list* and the project-*hub* door only; direct
  deep-links to sub-pages are still reachable (matches the app's "hide the tab, don't lock the
  door" posture). **Prerequisite:** every real login needs `users.crew_id` set on the Users page, or
  a scoped user sees nothing.
- **PR #12 — Freelance/Vendor collaborator type lives on the person.** *(needs migrations 050 + 051.)*
  Each collaborator is Freelance (default) or Vendor. The type belongs to the **person** (in the
  artist database), edited on the Artists grid and the person page, and shown as a capsule inline in
  Staffing. (An earlier cut, migration 050, stored it per position and was superseded.)
- **PR #25 — "Last seen" shows real activity, not stale login.** *(needs migration 055.)*
  The Users-page "Last seen" column used to show each person's last *login*, which only refreshes on
  a fresh sign-in (a session lasts a week), so it read as stale. Now the app stamps a real last-seen
  on every authenticated page load, throttled to once per ~5 minutes per person. Old values
  self-heal the next time each person opens a page.
- **PR #24 — Artists & supervisors can log their own time on their own cards.** *(no migration.)*
  On My tasks, artists and supervisors can now start the timer, log hours by hand, and delete their
  own log entry on their own task cards — the same controls editors already had. Ownership is
  unchanged (still only your own rows; only admins can delete across people). A user needs a crew
  link to see these controls.
- **PR #23 — My tasks "Tasks/Tickets" UI polish.** *(no migration.)*
  Renamed the section heading to "Tasks/Tickets", added a Type column (Task vs Ticket), and moved
  the per-row Snooze control inline to match the Log button. Same inline styling applied to Studio
  Tasks for consistency.

## 2026-10-20

- **PR #21 — Snooze a card to a future date.** *(needs migration 054.)*
  Production (editor) and admins can snooze a task/ticket to a wake-up **date**. While that date is
  in the future the card drops off every active list (My tasks, board, Studio Tasks, step page) and
  returns on its own that day. Snooze is **separate from the due date** and does not move it.
- **PR #20 — New-task email + My-tasks nav badge.** *(the count works without a migration; the "new"
  colour needs migration 053.)*
  (a) Creating cards from the transcript / tasks flow now sends **one summary email per assigned
  person** (not one per card), and the screen reports who was emailed and who had no address.
  (b) The top-bar "My tasks" shows a count of your active cards, and the badge changes colour when a
  card is **new since you last opened My tasks**, clearing when you open it.

## 2026-10-19

- **PR #18 — Studio-time overhead panel on My tasks.** *(needs migration 052.)*
  A "Studio time" panel at the top of My tasks. Each person sees only their role's overhead
  categories, each with a Pomodoro Start button and a quick "log Xh" box, so leadership can log
  meetings/desk/reviews. **This batch also renames the "Editor" role to display as "Production"
  everywhere** (role unchanged underneath, no access change). Artists & supervisors can log their
  own overhead here.
- **PR #19 — Artist database search fix.** *(no migration.)*
  Artist search now **POSTs** the term (a GET with a URL in it tripped the host's firewall as "Not
  Acceptable"), and search now actually checks stored portfolio URLs, so pasting an ArtStation/reel
  link finds the artist. Same POST fix applied to the "Download as Excel" button. **Confirm on the
  live site** — the WAF isn't reproducible from the cloud dev environment.
- **PR #17 — Kick-off sign-off behaviour.** *(no migration — reuses the 049 tables.)*
  (a) Setting a project to Active opens the signer pop-up straight away, but only on the **first**
  activation. (b) A "Reopen edits" button withdraws a pending sign-off request and unfreezes the
  project, emailing each signer to wait for a new request. (c) A signed-off project can't go back to
  Setup (use On hold); the dropdown shows Setup greyed as "(signed off)".
- **PR #16 — Auto-archive at "Final".** *(no migration.)*
  Setting a task's status to "Final" archives it instantly (findable under the project's Archive /
  "Show archived"; revert the status to un-archive). Only "Final" is instant; other statuses still
  archive on the usual timer.
- **PR #15 — "+ New ticket" on both My tasks and Studio Tasks.** *(no migration.)*
  A Type dropdown (task vs ticket). A ticket must have an owner (My tasks defaults to you; Studio
  Tasks you pick). Shot/asset/pipeline pickers stay on the board.
- **PR #14 — Task detail "← Back" returns to My tasks for your own cards.** *(no migration.)*
  When the card is the viewer's own, the back link reads "← Back to my tasks"; others keep going to
  the board.
- **PR #13 — "+ New task" on My tasks (editors/admins).** *(no migration.)*
  Create a task without leaving the screen, via the shared `create_ticket()`; owner defaults to you;
  project optional/unfiled. Also fixed a latent bug: the create path now always loads the mailer.

## 2026-10-17 (and the first batch of this run)

- **PR #2 — Supervisor role.** *(needs migration 047.)* Artist permissions plus view-only Timeline
  and Staffing, and editable Shots. Artists/Bookings readable by all (rates stay admin-only).
- **PR #3 — Pomodoro timer + clock fix.** *(needs migration 048.)* The My-tasks running timer works
  in Pomodoro cycles; overruns cap at the block length and extra time is logged manually.
- **PR #4 / #17 — Kick-off sign-off.** *(needs migration 049 + `finance_install_08.sql` by hand.)*
  A project in `setup` becomes `active` only once the named signers (Tim and Jorik, pre-ticked)
  approve; what they approved is kept as a snapshot. The postmortem's "Kick-off vs now" screen
  compares that snapshot with today. (Behaviour refinements shipped as PR #17, above.)
- **PR #1 — Project switcher dropdown.** The project name in the top bar switches you to the same
  screen on another show.
- **PR #5 — Ideal quotas tab removed.** Days-per-item now lives only on Dates & headcount (the
  Finance "Ideal quotas" tab showed the same number and was dropped).
- **PR #6 — Plaky import stops reading "Time spent".** Time is logged in Anima now, so the importer
  ignores that column whether or not the file carries it. Old figures already imported are left
  untouched.
- **PR #7 — Bulk rates on the expense projection.** Set one rate on many rows at once.
- **PR #8 — Editable status dropdown on Studio Tasks.** Change a card's status straight from the
  list.
- **PR #9 — Freelance/Vendor type (position-based).** *(Superseded by PR #12, which moved the type to
  the person.)*
- **PR #10 — Removed the old Plaky "time logged" display screens** from dashboard, person, team, item
  and My tasks.
- **PR #11 — Narrowed the Status column** on the projects list to fit its content.

---

## Earlier milestones (before this run)

These were built and deployed earlier; the detail and reasoning live in `docs/ROADMAP.md` and
`docs/DECISIONS.md`. Headlines:

- **2026-10-07 → 10-16:** unfiled cards (capture before filing), the stop-cost panel
  ("what has this show cost us so far?"), a rebuilt Finance Overview with seven cost figures and a
  Final balance, N/A on the shot breakdown, a stopwatch on My tasks, the `postmortem` project state,
  "The Studio" (a project row that is not a show), and the **Budget pool** (the show in days). An
  AI transcript-to-tasks reader (`includes/ai.php`, the app's one external call).
- **2026-09:** the app went dark in Mana's colours (tokenised CSS), per-project ideal quotas,
  Bookings (cross-show clashes), Staffing merging Crew + Hiring, the artist database grid, the
  read-only artist role, timeline versions, step-progress editing, the Studio Tasks view.
- **2026-08:** the core was built phase by phase — schema + migrations, the setup wizard, Plaky
  import + the status model, the forward/reverse scheduler, the timeline UI (ported from STFC),
  reporting, tickets/tasks with attachments and email, the tracking grid, people/performance, the
  whole money side in its separate database, and hiring/contracts.

For the full origin story, the build order, and the ~20 hard-won engineering lessons, read
`START_HERE.md`.
