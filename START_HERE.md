# Anima — Start Here

**Read this first.** It carries everything decided so far so a new conversation can begin
without re-deriving context.

- **Owner:** David Salinas Garfias (Mana Animation Studio) — *not a developer; explain
  technical trade-offs in plain terms and flag anything destructive before doing it.*
- **Created:** 2026-08-11
- **Repo:** `mana-projects-manager` (private, GitHub) — local copy at `D:\Work\Mana\mana-platform`
- **Status (2026-09-21):** in real use at <https://mana.davidsaga.com> on several shows, among
  them **FCM** (budgeted on the Return to Club sheet, `RTC_ExpenseProjection_v01.xlsx`), **MSD**
  and **Doomsday**, plus a 2D motion comic. Built and deployed: setup,
  Plaky import, scheduler, **Staffing** (positions + hiring on one page), timeline with
  milestones, dashboard, per-step detail, tickets, the tracking grid, item detail, the shot
  breakdown, team and person performance, time logs, the brief, the whole money side, the artist
  database, historic quotas and fees, and **ideal quotas per project**. **All ten steps of David's
  production cycle are covered** — see the table at the top of `docs/ROADMAP.md`, the thing to
  check new work against. The one thing left is the **client loop**, never on that list.
  **§0 below says what changed from 1 to 21 September and what is waiting on David.**
- **⚠ Deploy state (2026-09-18):** everything up to **034 is applied**, confirmed by David on
  2026-09-18, so ideal quotas are per project on the live site. **035** (timeline versions) and
  **036** (progress on a step) were added the same day and are **not confirmed applied**: until
  they are, the Timeline says so instead of offering "Save timeline's version", and the step
  editor's progress box is switched off with the reason. Open `migrate.php` and look before
  assuming, rather than trusting this line: it is only ever as fresh as the last person who
  edited it.
- **⚠ The finance database is SEPARATE and has its own install files** — `finance_install.sql`,
  `finance_install_02.sql`, `finance_install_03.sql`, **`finance_install_04.sql`** and
  **`finance_install_05.sql`** and **`finance_install_06.sql`**, imported by hand via phpMyAdmin, never through `migrate.php` (that
  runner only touches the production connection). **✅ All six are imported as of 2026-08-31**,
  confirmed by David — 01–03 by 18 August, then 04 (hiring quotes + contract status),
  05 (other expenses) and 06 (per-second rates). So Projected cost and Margin now count
  non-labour costs too, and every finance feature is live. Its credential lives in the
  `finance_db` block of `config.php`, and `includes/money.php` is the **only** place that
  connection is ever opened.
  **⚠ Run each finance file ONCE** — there is no `schema_migrations` table on that side, so a
  second import fails on "duplicate column". That failure is harmless and means it was done.
- **Email is live** (confirmed 2026-08-14): sending as `hi@davidsaga.com` through
  `mana.davidsaga.com:465/ssl`, with Reply-To `david@mana.tv`. Same mailbox STFC uses — see
  the 2026-08-14 email entries in `docs/DECISIONS.md` for why it does not send as `@mana.tv`.
  `smtp_test.php` (top bar → **Email**, admin only) probes the settings and sends a real
  test through the same code the notifications use.
- **⚠ There are two folders called `mana` on the server.** The live one is
  `/home2/davideei/public_html/mana`. A stray `/home2/davideei/mana/public_html/mana` holds an
  abandoned 11 August copy from the first deploy, with no `config.php` — editing files there
  changes nothing and has already cost an afternoon. `smtp_test.php` prints the absolute path
  of the config file the app actually loaded, which settles it in one look.
- **The reference implementation is STFC.** Settled 2026-08-14: *"everything we build from now
  on should also be informed by the STFC2 project — that's the MVP and we can add on top of
  it."* Read the equivalent screen there before designing a new one here. It is at
  `D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\`. The timeline was
  ported that way and came out far better than the version invented from scratch.
- **Deploy:** push to `master` in GitHub Desktop; GitHub Actions FTP-syncs to
  `public_html/mana` within ~30s. After deploying a change that adds a migration, open
  `migrate.php` once.
- **Predecessor:** the STFC S02 dashboard (see “Lessons” below) — a working, single-project
  tool that this platform generalises.

---

## 0. Where things stand (2026-09-21)

A long run of work since the last update, almost all of it asked for by David screen by
screen. `docs/DECISIONS.md` has an entry for every item below; read those before changing one.

### Built, 1–21 September

- **The app is dark, in Mana's own colours** (2026-09-22): #101010, white, sky blue #5FB2FF and
  spring green #26FE89, the four mana.tv declares. Every colour in `assets/style.css` is now a
  token in `:root` and nothing outside it holds a literal, so the theme is one block; `@media
  print` turns it back to light, because a printer given a black page wastes a cartridge.
  **Never write a colour into a rule again**, add a role to `:root` (lesson 21).
- **A finished step is green** on the dashboard (2026-09-22): card, bar, and **✓ Finished** in place
  of a pace for work that no longer exists. A step with nothing applicable is not finished, it is
  empty, and stays as it was.
- **A shot's note is typed on the breakdown** (2026-09-22). Nothing edited one before; it arrived
  with an import and stayed. Saved on leaving the box, like the frames beside it.
- **The breakdown totals frames and footage** (2026-09-22), above the table and in its foot, over
  the rows shown, naming any shot with no frame count instead of counting it as zero.
- **N/A is the way to say "this item skips this step"** (2026-09-22, nothing built): it leaves the
  step's count, rather than counting as approved. FCM's Concept reads 3.0 of 4.0 that way. The
  planning side still reads coverage %, so keep the two in step; DECISIONS says why and what was
  offered.
- **Bookings** (2026-09-21, top bar beside Artists, no migration): who is needed on which show
  and when, across every show in setup, active or on hold, read from positions (Staffing) and
  each step's general-timeline dates. **By person** (a row each, red where one person is needed on
  two shows on the same working days) and **By show** (every general timeline stacked, the people
  named on each step). `includes/bookings.php`, `assets/bookings.js`.
- **Timeline versions** (2026-09-18, migration 035): **Save timeline's version** keeps a copy of
  both charts and the milestones, named `V00_09182026`, `V01_09182026` and on (a number per
  project, then the date saved). The **Version** picker opens one on warm paper, read only, with
  **See the current timeline** beside it. Stored as the drawing, not as ids, so a later rename or
  deletion cannot rewrite an old version. `includes/timeline_versions.php`; DECISIONS says why.
- **A project with no saved schedule says so** on the timeline, with **Build the detailed
  schedule**. That is why MSD had a general chart and no detailed one: none had ever been saved.
- **Double-click a step's bar** (2026-09-18, migration 036): its dates with their weekdays, the
  working days between them against the days it needs at its settings (the Duration column of
  Dates & headcount, now also `step_duration()` in PHP), **Finish after N working days**, and its
  **progress**, typed or dragged with the handle under the bar and drawn as the bar's darker part,
  beside what the tracking grid has approved. Read only for viewers and on saved versions.
- **A dragged bar keeps its working days** (2026-09-18), on both charts: slid over a weekend or a
  studio holiday it stretches instead of losing a day; a stretched end lands on a working day.
  Holidays are shaded like weekends, and a redraw keeps your scroll position.
- **Fixed: a drag on the detailed chart unassigned the task** (2026-09-18). Tasks dragged before
  the fix still have nobody on them, see Waiting on David.
- **Timeline:** milestones for client reviews (drag, double-click to edit, tied to a step's row),
  shaded weekends, remembered zoom, whole-chart exports. **Bars move wherever they are dragged**
  (2026-09-18): Frappe refused to place a bar before a step it depends on, which contradicted the
  "drawn, not enforced" rule of 2026-08-31; the popup on dependent bars was also broken.
- **Staffing (`crew.php`)** replaced the separate Crew and Hiring tabs (2026-09-02/03): positions
  grouped by department, each carrying its candidates, their stage and the Hire button; the
  artist database offered wherever a position is filled; a loud cue for unfilled positions; an
  Excel sheet for the other supervisors. **Hiring now LINKS a person to a position**
  (`assign_crew_to_lane()`), instead of renaming the slot, which is where every duplicate person
  came from; `people_merge.php` folds the duplicates back together. **Every candidate stays on
  the position** (2026-09-18): filled positions keep their backups, and "Talked to before" lists
  declined, passed and whoever left, with **Line up again**. `hiring.php` still works but nothing
  links to it — ROADMAP says what to do with it.
- **Artists** — the artist database as a screen: edit in the row, bulk add, import/export the
  freelancer sheet, reel password (migration 031), artists with no project yet (030).
- **Shots** — the shot breakdown: assets per shot, frames per shot, an export. This is also the
  scheduler fix ROADMAP had flagged: `shot_assets` finally has a writer.
- **Ideal quotas are PER PROJECT** (2026-09-17, migrations 033/034). A step's own
  `days_per_unit` is the number the schedule, the quotas and the budget run on; it is edited on
  the project's **Ideal quotas** tab (Finance management) or on Dates & headcount, and it reaches
  no other project. Numbers can be **copied from another project**, or **matched to an approved
  expense projection** (.xlsx upload, preview, apply). The per-type sheets of 2026-08-17 are kept
  in `quota_standards`, unread. **Project types** (032) are now labels a person can add.
- **Minimum against ideal** (Finance) judges each step over its own dates, exactly like Dates &
  headcount; the dashboard keeps the "from today" reading.
- Smaller: buttons instead of links and one line of row actions everywhere; commas instead of
  dashes in the text; a progress capsule per project on the front door; **the page keeps its
  scroll position after a save** (`assets/keepplace.js`); workflow links drawn by dragging on
  the diagram, and a PNG download; a Deadline card on each step page; Historic quotas (both
  quotas, several shows, type and step filters) and Historic fees beside Artists in the top bar;
  an administrator can borrow the editor or view-only role from Users to see what others see.
- `tools/check_php_blocks.py` has a **seventh pass**: nested transactions (lesson 18).

### Waiting on David

1. **Push** (GitHub Desktop), then **`migrate.php`** for **035** (timeline versions) and **036**
   (progress on a step), whichever is still pending.
2. **FCM:** 034 is applied, so: Finance management → Ideal quotas → *Match an approved expense
   projection* with `RTC_ExpenseProjection_v01.xlsx`; "Character Sheet" probably has to be pointed
   at the CH sheet step by hand. Then **change the Rough Animation slots' rate from "per second"
   to "per day"** (200 per second is a day rate with the wrong unit: 2,700 per artist instead of
   1,486) and check the other footage rows. The projection should then read **45,742.40**: the
   sheet's 43,442.40 plus the two "(old)" costs, which were entered as Other expenses in August.
3. **MSD:** check **Comp · shots**' dates — every failed attempt to drag File prep moved and saved
   Comp. Also whether File prep really waits for the colour steps (its bar is red because of it).
   Then **build its detailed schedule** (Timeline, *Build the detailed schedule*, or the Schedule
   tab): none has ever been saved, which is why it has no detailed chart.
4. **Decisions:** (a) should dragging a timeline bar carry every step downstream of it along, as it
   does today, or move alone? (b) **backgrounds: half settled 2026-09-21**, they are assets of
   their own type, Background, one per background (DECISIONS). Still open: which steps each one
   goes through, and whether to build the link to the environment it comes from (offered, see
   DECISIONS); (c) what becomes of `hiring.php` (ROADMAP); (d) **tasks dragged on the
   detailed chart before 2026-09-18 lost their person** and show no name left of the bar: run a
   one-off repair giving each back the current holder of its lane? Not run yet, because it would
   also fill a task somebody set to "nobody" on purpose.
5. **Housekeeping** carried over: rotate the two database passwords (§4 item 14); delete
   `install.php` from the live server; re-import the freelancer sheet with "update people already
   here" ticked.

### Not built, on purpose or not yet

- For timeline versions: restoring one onto the live plan (it overwrites what is there, so it is
  David's call first), deleting them, a note per version, and comparing two.
- For progress: a history of it (only the latest number is kept, though each saved timeline
  version freezes the numbers of its day), and a mark on the bar where its minimum duration ends.
- For Bookings: the detailed schedule as a second source (task days, not step windows),
  engagement dates and part-time allocation (50% on two shows is not a double booking), and
  clashes inside one show.
- Renaming a project type (create the right one, move the projects, remove the wrong one).
- A clean-up for crew records nothing points at (offered, not built).
- Coverage above 100% was asked about and **advised against** (2026-09-16): the calendar, the
  dashboard and progress count real items, so the percentage would change the budget alone.
  Backgrounds as their own asset type is the recommended route (decision 4b above).

---

## 1. What we're building

A **multi-project production tracking platform** for a small animation studio. Mana runs
several shows at once; data, reporting and tracking are the core of how they operate.

The centrepiece is a **New Project setup flow** that captures everything needed to stand up
a project, then drives scheduling and reporting from it:

| Input | Notes |
|---|---|
| Project name | |
| Project code | Short slug, e.g. `STFC2` |
| Type | 2D / 3D / Mixed / Other, plus any added since migration 032. A label: it files the project and puts matching templates first, and decides no number |
| Asset breakdown | Import (CSV/Excel) **and** create in-page — both must work |
| Shot breakdown | Import **and** create in-page |
| Pipeline steps | Differ by project type; must be creatable on the fly |
| Pipeline workflow | Order **and parallelism** — a real dependency graph, not a flat list |
| Estimated dates | Per step; seeds the first-pass timeline, editable later |
| Estimated headcount | Per step |

### The scheduling model (the thing that makes it valuable)

It must work in **both directions**, as the STFC dashboard already does:

- **Forward:** auto-generate a first-pass timeline → export `.gan` → refine in GanttProject
  → re-import as the definitive schedule.
- **Reverse:** from shot/asset counts + deadline + headcount, derive the **minimum quota per
  artist per day**. David considers this the single biggest driver of project success.

---

## 2. Decisions already made

### 2.1 Fresh build, not a retrofit — but ONE platform, not a copy per project
STFC is live and mid-production with an end-of-month deadline; schema surgery on it is too
risky. So the platform is built fresh.

**Important refinement David agreed to:** the new platform is **multi-project from day one**,
even though STFC will never move into it. New projects become rows in *one* system rather
than new folders. This avoids ending up maintaining N diverging codebases.

- STFC stays on the old dashboard, effectively frozen — bug fixes only.
- Bug fixes discovered anywhere get applied to the platform **and** back-ported to STFC
  while that show is running.
- After STFC wraps: optionally import it as a completed project, or retire it.

`project_id` in the schema from the start is nearly free; adding it later against live data
is the expensive scenario we're deliberately avoiding.

### 2.2 Crew is global, assigned per project
One org-wide crew list; a join table records who is on which project. This enables
cross-project workload views (e.g. one artist booked on two shows the same week), which was
impossible before and considered valuable. **Built 2026-09-21 as Bookings** (§0).

### 2.3 Pipeline workflow is data, not code
In STFC, step parallelism was hardcoded (`$overlapsWithPrevious = ['render']` — Lighting and
Render run in parallel, Comp waits for both). In the platform this becomes a
`step_dependencies` table. Parallelism then falls out naturally: two steps sharing a
dependency run side by side, and the scheduler needs no special cases. **This is the highest-
value structural change** — it makes the scheduler work for any pipeline shape, 2D or 3D.

### 2.4 Git + automated FTP deploy
Manual file-by-file uploading caused repeated production bugs on STFC (see Lessons). The
platform uses a private GitHub repo with a GitHub Actions workflow that FTP-syncs changed
files to cPanel on push. David is non-technical and will use **GitHub Desktop** (GUI), not
the command line. Scaffolding already exists in this folder — see `docs/GIT_SETUP.md`.

---

## 3. Lessons from STFC — read before designing

Hard-won, all from real incidents:

1. **Partial deploys are the #1 source of bugs.** A front-end change shipping without its
   matching backend file caused a filter to silently do nothing. Automated deploy exists
   specifically to kill this class of bug.
2. **Cache-bust every asset.** Only `style.css` was versioned; a fixed `timeline.js` sat
   cached in the browser for a whole debugging round. Use an `asset_ver()`-style helper on
   **all** JS/CSS from day one.
3. **Never parse `YYYY-MM-DD` with `new Date()` in JS.** It parses as UTC, reads back in
   local time (project runs `America/Mexico_City`, UTC−6) and silently walks dates backwards.
   Timeline bars lost 2 days per edit cycle until this was found.
4. **Agree on inclusive vs exclusive end dates once, globally.** Frappe Gantt treats `end` as
   the inclusive last day and extends it internally; the PHP was adding a day on top, so
   every bar rendered one day too long. Two compensating errors hid each other for weeks.
5. **Don't conflate headcount with concurrency.** The scheduler used headcount as "tasks in
   flight"; steps with no headcount logged defaulted to 1, serialising 188 shots into ~9
   months. Concurrency must be derived from the deadline:
   `ceil(remaining_tasks × days_per_task ÷ working_days_available)`.
6. **Name matching between systems needs an alias layer from day one.** Plaky assignees
   (`Animator.wasi` vs `Wasi Ali Khan`) and asset names (`RomulanOfficer01` vs
   `Romulan Officer`) silently dropped data into an "unassigned" bucket. Build the alias
   table *and* an "unmatched names" report at the start.
7. **Surface silent failures.** Several bugs looked like "nothing happened". Every import,
   export and notification should report what it did, what it skipped, and why.
8. **Shared-hosting sessions expire early.** Neighbouring accounts' garbage collection
   deletes your session files. Use a private `sessions/` directory. (Note: STFC also sets
   `session.lazy_write=0`, which increases disk I/O — see Open Questions.)
9. **Never write a closing PHP tag inside a comment.** PHP acts on `?>` even in a
   `//` comment — it ends PHP mode, so the rest of the file is dumped to the browser as
   text and every function below it goes undefined. Cost the platform's first install
   attempt. (Found during the Phase 1 build, not on STFC.)
10. **PHP `mail()` cannot authenticate.** Send via authenticated SMTP. Match port to
   encryption: **465 = SSL, 587 = STARTTLS** — mixing them hangs. cPanel mail servers usually
   need `verify_peer = false` (self-signed certs).
11. **Never put a placeholder in a `SHOW` statement, and never let a `catch` decide a
   feature is missing.** `config.php` sets `PDO::ATTR_EMULATE_PREPARES => false`, so
   `$pdo->prepare('SHOW COLUMNS FROM x LIKE ?')` is a real server-side prepare and MySQL's
   protocol rejects the parameter. Use `$pdo->query()` with a literal, as `wizard.php`
   already did. The damage wasn't the failed query — it was the `catch (PDOException)`
   turning it into "that column doesn't exist", which made the scheduler's save button
   quietly not draw itself. **A feature that disables itself on a swallowed exception is
   indistinguishable from a feature that was never built.** If a catch decides something is
   unavailable, it has to say so on screen. (Phase 4 build, 2026-08-12.)
12. **Never write a closing script tag inside an inline `<script>` block — not even in a
   `//` comment.** The HTML parser scans for that character sequence and ends the script
   element on the spot; it has no idea JavaScript considers the line commented out.
   Everything after it renders on the page as text. This is lesson 9's twin — there PHP acts
   on `?>` inside a `//` comment, here the HTML parser acts on `</` + `script>` — and both
   teach the same thing: **a parser acts on characters, not on your intent.** Cost the tasks
   board its first load, in a comment that was explaining why `JSON_HEX_TAG` is needed to
   prevent exactly that. Write it escaped as `<\/script>`, or say "a closing script tag" in
   prose. Only a hazard *inline*: inside a real `.js` file loaded with `<script src=…>` the
   sequence is harmless. (Phase 7 build, 2026-08-14.)
13. **Two functions with the same name is a fatal, and only on the page that loads both.**
   PHP has no namespaces here, so a second `function fmt_rate()` kills any request where both
   files are included — while every other page keeps working, which makes it look like a bug
   in the new screen rather than a collision with an old one. Cost `person.php` its first
   load: `money.php` defined `fmt_rate()` for a PAY rate, `quota.php` already had one for a
   WORK rate. Same English word, two meanings, one 500. **`tools/check_php_blocks.py` now
   detects this** — it reports every duplicated function name across the codebase, which is
   the closest thing to a linter available without a PHP binary. Run it before handing over.
   (Phase 9 build, 2026-08-15.)
16. **`rtrim` on a formatted number eats significant digits when there is no decimal point.**
   `fmt_num()` ended with `rtrim(rtrim(number_format(...), '0'), '.')`, which turns "2.50" into
   "2.5" and "2.00" into "2" — correct, and correct for four months, because every caller used the
   default of two decimals and `number_format()` always emits a `.` there. **The `.` was the only
   thing stopping the trim.** Called with 0 decimals it strips real zeros:
   `fmt_num(0, 0)` → `""`, `fmt_num(10, 0)` → `"1"`, `fmt_num(100, 0)` → `"1"`.
   It surfaced as blank Approved / Delivered / Blocked cells on the finance Overview, because those
   were legitimately zero. **The blanks were the lucky part** — a project with 10 approved would
   have read "1 of 38", which is a plausible sentence and a tenth of the truth. Fixed with a
   `strpos($s, '.')` guard, which changes nothing at one decimal place or more.
   **The general shape:** a formatting helper that is right for its original caller can be silently
   wrong for the next one, and numbers are the worst place for that because a wrong one still looks
   like a number. (2026-08-17.)

15. **A checker that is wrong about the language is worse than no checker.**
   `tools/check_php_blocks.py` reported a healthy file as broken, because it treated a `//`
   comment as running to the end of the line. PHP does not: it acts on `?>` even inside a
   comment — that is lesson 9 — ending PHP mode there and reading the rest as HTML. So
   `<?php // ---- a note ---- ?>` followed by markup left the checker in PHP mode, where an
   apostrophe in ordinary prose opened a string and swallowed eighty lines, `endif` included.
   Every earlier use of that comment form in this codebase happens to be followed by more PHP,
   which is why it had never shown. **Fixed in the tool rather than avoided in the code:** a
   false alarm on the only gate this project has teaches people to ignore the one thing that
   catches real parse errors, and the same blind spot could equally have hidden a genuine
   mismatch. All 74 files still pass after the fix, so it removed a false positive without
   adding a false negative. (2026-08-17.)

14. **A mismatched quote is a PARSE error, so it takes down every page that requires the file.**
   `"UPDATE … WHERE id = ?'` opened with a double quote and closed with a single one.
   `includes/finance.php` stopped compiling, and Finance, the project page and the person page
   all 500'd together — which is the signature of a broken *include*, not a broken feature. It
   presented as "there are no projects", because output stopped after the table header.
   **`tools/check_php_blocks.py` now detects unterminated strings**, alongside unbalanced
   `if:/endif;` and duplicate function names.
   **The narrower lesson is the useful one:** it was introduced by a scripted string replacement
   that silently did not match. **Assert every replacement.** That batch asserted on every edit
   but one, and that was the one that failed. (2026-08-18.)

16. **An apostrophe inside a single-quoted string is a PARSE ERROR the balance checks cannot
   see.** `. 'opens the artist's own showreel page'` closes at "artist" and leaves `s own
   showreel page` sitting in the file as code. The quotes re-pair further down, so the file
   looks balanced and the unterminated-string pass says nothing — but PHP will not compile it.
   Took `artists.php` and `hiring.php` down together on 2026-08-31 while every other page
   stayed up, which is the signature: **two pages 500, the shared includes fine.** Bisect by
   curling several pages, not by reading.
   **The narrower lesson, and the one that matters:** it came from a scripted edit whose
   escaping was mangled in transit, and the assertion guarding that edit checked THE ANCHOR
   MATCHED, not THE RESULT WAS SOUND. Lesson 14 said assert every replacement; this says assert
   what came out, not what went in. `tools/check_php_blocks.py` gained a sixth pass for it,
   verified by putting the bug back and watching it fire. **Where a string needs an apostrophe,
   reword it** — "their own showreel" needs no escape and cannot be mangled. (2026-08-31.)

15. **A PHP file is mostly HTML, so you cannot tell by looking whether a line is in PHP mode.**
   A comment block was pasted above `$pageTitle = …` in `standards.php` complete with its own
   `<?php` — but the file had opened PHP ninety lines earlier and had not closed it. `<?php`
   inside PHP is not a tag, it is the characters `<` and `?` and a word, and the file stops
   compiling. Same signature as lesson 14: every page requiring it 500s, not just the screen
   being worked on. None of the other three checks could see it — braces balanced, strings
   closed, no duplicate names. **`tools/check_php_blocks.py` gained a fourth pass**, and the
   pass was verified by putting the bug back and watching it fire, because a check nobody has
   seen fail is a check nobody knows works. **Before adding a block, look up the file for the
   nearest unmatched `<?php` or `?>` — not at the indentation.** (2026-08-17.)

17. **A number shared across projects reaches further than the screen it is edited on.** Ideal
   quotas were one sheet per project TYPE, and from 2026-09-16 the Dates & headcount box edited
   that sheet directly. Correcting a step on one 2D show moved the budget of every other 2D show,
   including FCM, whose approved budget stopped matching its sheet — which is how David noticed.
   A confirm dialog saying "this reaches every 2D project" did not make it safe, because the
   person saving is looking at one show. **Ideal quotas became per project** (2026-09-17); sharing
   is now a copy somebody asks for, never a link. **When a number is shared, the question is not
   whether the screen warns — it is whether anybody editing it can see everything it moves.**

18. **MySQL has no nested transactions.** `hire_candidate()` wraps the hire in a transaction; it
   was changed to fill the position through `assign_crew_to_lane()`, which opened its own. The
   second `beginTransaction()` threw "There is already an active transaction" and the hire failed
   the first time David pressed Hire (2026-09-03). Nothing was half-written, but only because the
   inner function rolled back before rethrowing. **A function that opens a transaction must work
   when called inside one**: open only when outermost, commit only what it opened, and never roll
   back a transaction it did not start. `tools/check_php_blocks.py` has checked this statically
   ever since (the seventh pass).

19. **A library can enforce a rule you decided against, silently.** The general timeline was
   settled on 2026-08-31 as "dependencies are drawn, not enforced". Frappe Gantt 0.6.1 enforces
   them anyway: it refuses to place a bar before a step it depends on, and says nothing. MSD's
   File prep, planned before the colour steps it was wired to wait for, could not be moved at all
   — and each refused drag still MOVED AND SAVED the step downstream of it. Found only by reading
   the library's source (`update_bar_position()`) and reproducing with the real library in a
   browser. **When a chart or widget "just won't do" something, read the library before the app,**
   and check what else it does on the same gesture. (2026-09-18.)

21. **A colour written into a rule is a decision you cannot re-make.** `assets/style.css` held
   about 210 colour literals, each one right where it stood and none of them saying what it was
   FOR, so "the app should match Mana" meant editing every rule that mentioned a shade of grey.
   Converted to roles (`--surface`, `--border`, `--on-primary`) the same change became one block,
   and printing became a second one. Two traps the conversion found: the same hex meant different
   things in different rules (white is a card, and also the label on a coloured button, and those
   two must move in opposite directions), and the browser draws things no rule reaches, so a dark
   page needs `color-scheme: dark` for its date pickers, dropdown lists and scrollbars. **Add a
   role to `:root`; never write a colour into a rule.** (2026-09-22.)

20. **An absent field is not an empty one.** `schedule_api.php` read a missing `crew_id` as
   "nobody". Right for the task editor, whose blank option sends an empty string on purpose; wrong
   for a drag, which sends only the dates. So every task ever dragged on the detailed chart lost its
   person, and the lock the same drag set kept Regenerate from giving it back. It stayed hidden
   because the browser kept the old name on screen until the next reload, so the drag LOOKED
   harmless. **When an endpoint serves several callers, decide what a missing field means apart
   from what an empty one means, and check every caller against both.** (2026-09-18.)

---

## 4. Suggested build order

Settled 2026-08-11: Plaky stays the working tool for now, and the platform imports from it —
**but the schema is built as though the platform owned the data**, so switching later is a
change of habit rather than a migration. Read the two paired Plaky entries in
`docs/DECISIONS.md` before designing anything here.

1. ~~**Schema + migrations**~~ — done, live
2. ~~**Project setup wizard**~~ — done (paste-based entry)
3. ~~**Import + the status model**~~ — **done and proven on real data** 2026-08-12:
   the "Return to Club" board imported from Plaky (1 shot, 22 assets, 71 progress
   records including 4 whole-project deliverables).
   `migrations/004_status_model.sql`, `import.php`, `statuses_edit.php`, `aliases.php`,
   `project.php`, and the helpers in `includes/{statuses,names,plaky_import}.php`.
   These shipped together:
   **Read `docs/PLAKY_EXPORT.md` first** — a real export has been analysed and the
   traps in it are written down (a `Lightning` typo, `(inactive)` name suffixes,
   multi-assignee fields, `DD/MM/YYYY` dates, an unmapped `On Hold` status):
   - `statuses` — the platform's OWN vocabulary, project-scoped, carrying STFC's
     `maps_to` none/delivered/approved. `progress` points at these rows.
   - `status_mapping` — translates incoming Plaky text onto those statuses.
   - CSV/Excel upload with column mapping, for shots, assets and progress.
   - The alias layer (`crew_aliases`, `name_aliases`) **and** the unmatched-names report,
     built alongside the importer, never after it.
4. ~~**Scheduler**~~ — **built 2026-08-12, not yet run against the real show.** The reverse
   quota maths already existed (`includes/quota.php`, shown on the wizard's review screen);
   this phase added the forward pass. `migrations/010_scheduler.sql`, `includes/scheduler.php`,
   `includes/calendar.php`, `schedule.php`, `calendar.php`. Read the seven `2026-08-12`
   entries in `docs/DECISIONS.md` before changing any of it. In short:
   - **A studio calendar.** `working_days_between()` counted Mon–Fri and nothing else, so
     every Mexican public holiday was a day someone could finish a shot on. It now reads
     `non_working_days`, which means **the quota figures on the review screen changed too** —
     this was never only a scheduler fix.
   - **Lanes, not people.** A step with two people gets two parallel tracks named
     "Compositing 01 / 02". `crew_id` stays NULL: nothing records who does which discipline,
     and a real name on a task reads as a real assignment.
   - **Headcount is used as planned, even when it's short** — the overrun is reported rather
     than papered over with lanes that don't exist. With no headcount it is derived from the
     deadline, never defaulted to 1 (lesson 5).
   - **A step whose coverage is a guess is drawn as one band**, not as invented per-shot rows.
     Marking items `N/A` (the new `statuses.is_excluded`) turns it into real per-item dates.
   - Dry-run preview first, then save — same convention as the importer and `migrate.php`.
     `is_manual = 1` rows are read but never rewritten.
4b. **Crew slots + snapshot history** — built 2026-08-13, alongside Phase 4.
   `migrations/011_snapshot_history.sql`, `012_crew_placeholders.sql`,
   `includes/{snapshots,crew}.php`, `crew.php`.
   - **Slots, not people.** A crew is never complete until the show nearly is — people
     arrive as their department starts — so headcount generates named slots
     ("Animation 01/02") that the scheduler assigns work to. Hiring someone **renames the
     slot**; the crew row's id never changes, so all planned work becomes theirs with
     nothing moved.
   - **Daily snapshots now actually get written**, on page load. `daily_snapshots` had
     existed since v1 with no writer. History can't be backfilled, which is why this
     jumped the queue.
5. **Timeline UI** — **mostly built 2026-08-13.** `timeline.php`, `assets/timeline.js`,
   `timeline_gan.php`, `schedule_api.php`.
   - **Ported from STFC's timeline rather than invented**, because that is the version the
     team actually used. Two charts (general per step, detailed per task), episode and
     step filters, hide-completed, zoom, PNG/PDF, `.gan` export, click-to-edit and drag,
     Regenerate. Frappe Gantt draws it, as it did on STFC.
   - The layout detail that matters: **the assignee name is drawn to the left of each bar
     and the item · step to the right**, both travelling with the bar. That is what keeps
     it readable at any zoom, instead of a fixed gutter that scrolls out of view.
   - A first attempt used hand-rolled server-side SVG (`includes/timeline.php`, since
     deleted). It was replaced rather than kept: two chart implementations would drift,
     and Frappe gives zoom, drag and popups for free.
   - **`.gan` import done** (`timeline_import.php`): dry-run first, matched on item name +
     step name, ambiguity resolved by scope, imported rows locked. `add_working_days()` is
     the exact inverse of `working_days_between()`, which is what makes an untouched file
     round-trip onto the same dates — change one and change the other.
   - Watch lesson 4 throughout: `end_date` is inclusive here, and Frappe's `end` is
     inclusive too — so **nothing is added or subtracted** at that boundary. GanttProject's
     `duration` is a count of working days, converted in exactly one place
     (`gan_duration()`). STFC lost weeks to two layers each applying their own ±1.
6. ~~**Reporting**~~ — **built 2026-08-14.** `dashboard.php`, `step.php`,
   `includes/{dashboard,timelog}.php`.
   - Every figure is a **comparison** — approved *of* total, finish *against* delivery,
     actual pace *against* required pace. A bare number was the thing to avoid.
   - Per-step cards carry the minimum quota per day, and each opens a detail page.
   - **Time logged** reads `progress.time_spent_minutes`, which the Plaky importer had been
     writing correctly all along and nothing had ever displayed.
   - Two things it states rather than decides: the days↔hours comparison assumes an
     8-hour day (`project_settings.hours_per_day`), and a task with two people counts its
     hours for both.
   - Still thin: the pace section needs a few days of `daily_snapshots` before it says
     anything, and refuses to answer below two readings.
7. ~~**Tickets / tasks**~~ — **built 2026-08-14, not yet run against the real show.**
   `migrations/017_tickets.sql`, `includes/{tickets,uploads,mailer}.php`, `tasks.php`,
   `ticket.php`, `tasks_api.php`, `attachment.php`, `mytasks.php`,
   `assets/{tickets,board}.js`. Ported from STFC's `kanban.php` / `ticket.php` /
   `kanban_api.php`. Read the nine `2026-08-14` tickets entries in `docs/DECISIONS.md`
   before changing any of it. In short:
   - **Board columns are the project's own `statuses`**, not a hardcoded ENUM — with a
     "board column" tick on the Statuses screen, because eleven statuses is not a board.
     A card in a status that isn't a column is shown anyway, marked *off-board*.
   - **Tickets never write to `progress`.** Plaky still owns production status, so the next
     import would silently undo it. `tickets.step_id` exists so that becomes a small job
     later; today it surfaces open tickets on the step page.
   - **References are per project and derived** — `RTC-12`, built from `projects.code`
     at display time, never stored.
   - **`users.crew_id`** links a login to a person, which is what makes `mytasks.php`
     ("everything assigned to me, across all shows") possible. Asked once, never guessed.
   - **Status and owner changes write into the ticket's own thread**, dragging included —
     STFC's "Activity" only ever held typed comments.
   - **A bug came across with the port and was fixed here:** the attachment forms upload by
     XHR, XHR follows the server's redirect, and the page it fetches consumed the flash
     message — so every "3 files attached" and "no shot by that name" was swallowed.
     **Worth back-porting to STFC while that show runs.**
   - **`activity.php` was not ported.** It is STFC's per-artist productivity report reading
     `shot_activity_log`, not a ticket screen — that ground is Phase 6's dashboard and
     `includes/timelog.php`. It is listed here only because the original build order
     misfiled it.

8. ~~**Tracking grid — the Production tracking tab**~~ — **built and working 2026-08-15.**
   `includes/grid.php`, `production.php`, `production_api.php`, `assets/production.js`.
   Ported from STFC's `preprod.php`. **No migration** — it reads `progress` and
   `pipeline_steps`, exactly what the 2026-08-11 status-model decision was bought for.
   Read the three `2026-08-14` tracking-grid entries in `docs/DECISIONS.md` before changing
   any of it. In short:
   - **A cell is a status colour, not a checkbox**, because a checkbox has no inverse against
     an eleven-value vocabulary. **Green still means what it meant on STFC** — `approved`,
     i.e. ready for the next department.
   - **Three phases**, named as the studio names them and driven by the scopes the schema
     already has: Pre-production = assets, Production = shots, **Post-production =
     `applies_to = 'project'` steps** (SFX, music, mix, master — one row, one deliverable
     each). `pipeline_steps.department` is the more precise long-term answer but is blank on
     every existing project; see ROADMAP.
   - **A dash is structural**, from `applies_to` / `applies_to_asset_type`, or an
     `is_excluded` status on that cell. A character with no rig can still read as ready.
   - A donut per group: status spread around it, "N ready" in the middle, drawn only for
     groups that exist. Clicking a segment lists exactly which items report that status.
   - Writing a cell **also recomputes `is_delivered` / `is_approved`** — those flags are what
     every dashboard figure counts. Writing `status_id` alone would move the colour and leave
     every number unchanged, which looks like it worked.
   - Totals do not live-update after an edit; the page says so and offers a refresh. An
     out-of-date total is recoverable, a wrong one is not.
   - The tab order and the Overview screen were tidied the same day: the nine hub cards are
     gone, having duplicated the tab bar exactly.

9. **People and performance** — **built 2026-08-15.** `migrations/018_…sql`,
   `includes/{people,money}.php`, `team.php`, `person.php`, plus the trend chart on
   `step.php`. A **Team performance** tab lists everyone by unfinished work; a person page
   shows their load per step, required vs recent pace, logged time and open tickets.
   - **⚠ Pay data lives in a SEPARATE DATABASE.** Migration 018 briefly put rates on `crew`;
     019 removes them again. Rates and contract links are in their own MySQL database with
     their own user, which the production user has **no rights to** — so a bug in any ordinary
     query cannot reach one. `includes/money.php` is the only place that second connection is
     ever opened, and only behind `is_admin()`. **Keep it that way**, or the guarantee is gone.
     **Set up and live on the server as of 2026-08-15** — its own database, its own user, its
     own password. On a fresh install use `finance_install.sql` + the `finance_db` block in
     `config.php`; until that exists the person page says so rather than showing a blank.
     Read the three `2026-08-15` money entries in `docs/DECISIONS.md` before touching any of it.
   - **Pace per person is approximate and labelled as such.** History is recorded per step,
     not per person. Don't remove the caveats without building the history that would justify
     removing them.
   - The trend chart is hand-rolled SVG, like the workflow diagram and the donuts. **No chart
     library has been added and none is needed.** It stays nearly empty until enough
     `daily_snapshots` accumulate — those only began on 13 August.

10. **Money, the brief, and the change log** — **built 2026-08-15 → 18.** This closed seven of
   the ten steps in David's production cycle (`docs/ROADMAP.md`, top of file — **read that table
   first; it is what a new feature should be checked against**).
   - **`brief.php`** (migration 023) — step 3, which had no home at all. Prose fields plus file
     attachments. Its own table, not columns on `projects`: that row is loaded on every page just
     to draw the nav bar.
   - **`finance.php` / `finance_project.php`** — budget, expense projection, invoices, and the
     report that is those three side by side. **ADMIN ONLY.**
   - **`item.php`** (migration 020) — one shot or asset opened up, version and notes per step.
   - **`includes/activity.php`** (migration 021) — the status-change log, written by the Plaky
     import, the tracking grid and the item page. **Cannot be backfilled.**
   - **`time_logs`** (migration 022) — per-day effort, entered from **My tasks**, which now carries
     production tasks as well as tickets.

   **The four things to know before touching any of it:**
   - **The expense projection is COMPUTED, never stored** — every load, from the live pipeline.
     Storing it creates a number that goes stale the moment a headcount changes. Unpriced lanes
     are reported as unpriced, never treated as free.
   - **Actual cost comes from INVOICES**, not days × rate. An invoice names a *position*
     (crew + step), which is why `invoices.step_id` exists.
   - **`RTC_ExpenseProjection_v01.xlsx` is the spec** for that screen's shape: one row per person,
     grouped by department, months = days ÷ 22. It was read, not approximated.
   - **`includes/money.php` is the only door to the finance database.** Keep it that way.

11. ~~**Hiring and contracts**~~ — **built 2026-08-17.** *(⚠ Two things below were changed on
   2026-09-02/03 and the rest stands: hiring no longer RENAMES the slot, it links a person to a
   position through `assign_crew_to_lane()`; and Crew and Hiring became one screen, **Staffing**
   (`crew.php`), with `hiring.php` kept but linked from nowhere. See §0 and ROADMAP.)*
   `migrations/024_hiring.sql`,
   `finance_install_04.sql`, `includes/hiring.php`, `hiring.php`, plus additions to
   `includes/money.php`, `crew.php` and `person.php`. This closed the last two numbered steps of
   the production cycle. Read the six `2026-08-17` entries in `docs/DECISIONS.md` before changing
   any of it. In short:
   - **⚠ There was NO STFC reference for this phase** — searched for, not assumed. STFC tracks a
     show that was already crewed. Every screen since 14 August had a proven version to port from;
     this one does not, which is worth knowing when something turns out to be wrong.
   - **The conversation is planning data; the quote is not.** Candidates, stages and contacts are
     in the production database where any editor sees them — a producer must be able to tell that a
     slot already has somebody lined up. What they asked for is in the finance database behind
     `money.php`, like every other rate. David's call, asked directly.
   - **Hiring RENAMES the slot.** `hire_candidate()` hands off to `promote_placeholder()`, so the
     crew id never changes and everything already planned for that slot becomes the new person's
     with nothing moved. This is the moment the 2026-08-13 slot model pays off. Hiring into a slot
     that already holds somebody refuses and says who.
   - **A candidate is not a `crew` row** until they are hired — otherwise everyone ever approached
     would sit in every assignee dropdown for ever. Records are never deleted: `hires.id` is the
     join key to the finance side, and somebody who declined is the record worth having when the
     other one falls through.
   - **The agreed rate carries across on hire** and stops those days counting as unpriced on the
     expense projection — but **never over a rate already on file**, and it says so when it
     declines to.
   - **`set_contract_state()` is a separate writer from `save_crew_terms()`** on purpose: the full
     one writes the whole row, so chasing a signature through it would have stored a missing rate
     as NULL and silently unpriced somebody.
   - **The entry screen is a GRID, plus a paste box** (migration 025, added 2026-08-17 after
     David asked for it). Several candidates at once, in the column order of
     `Mana_Freelancer_DataBase.xlsx`, so rows copied out of that sheet paste straight in. The
     sheet's **Password** and **Rate** columns are read and deliberately discarded — naming them
     is what keeps every later column from shifting one to the left — and the screen reports
     both rather than dropping them quietly. Rates belong in the finance database; a plain-text
     password column does not belong anywhere.
   - **⚠ The freelancer fields are on `hires`, which is one row per candidate PER SLOT.** David's
     call, with the alternative (a studio-wide `freelancers` table) offered and declined. The cost
     is duplication when the same person is approached for several slots — see the 2026-08-17
     entry in `docs/DECISIONS.md` for what the escape route looks like if it starts to bite.
   - **Engagement dates are recorded and the scheduler ignores them.** `crew_projects.start_date`
     / `end_date`, on the person card as David asked. Deferring the scheduling half is deliberate —
     it belongs with `crew_absences` — but the gap is **reported**, not hidden: the person page
     says how many tasks fall outside somebody's dates.

12. **⚠ THE PLAN AND THE BUDGET BOTH RUN ON THE IDEAL QUOTA. Read this before touching
    either.** Settled 2026-08-17, and it replaces the split that existed for part of that day.

   **`effective_days_per_unit()` in `includes/standards.php` is the ONLY reader of
   days-per-item.** Since 2026-09-17 it returns the project's own
   `pipeline_steps.days_per_unit`, because ideal quotas are per project (migration 034, see
   DECISIONS). Until 034 is recorded as applied it keeps the old rule: the type sheet's standard,
   falling back to the step's own number. Six separate readers were folded
   into it — the quota, the scheduler, the budget, the time-log comparison, the CSV export
   and the estimate baseline — plus the live check in `assets/wizard.js`, which applies the
   same rule so the screen cannot describe a plan the server would not build.
   **If you add a seventh caller, route it through the helper.** Two readers is how Dates &
   headcount and the expense projection came to report 0.85 and 1.86 for the same step.
   It is deliberately NOT retake-adjusted — callers apply the allowance, because a
   per-second cost must not be inflated by it while the days must be.

   **Per-second rates are live** (`finance_install_06.sql`). Footage steps can be paid by the
   second, and **retakes lengthen the days without adding seconds**, so those costs are flat
   while their durations grow. That is what buying by the second means. A per-second rate on
   a step not measured in seconds is reported as a mismatch and counted unpriced — never
   multiplied by an item count.

   **Settled, and no longer open:**
   - **Retakes do not inflate a per-second cost** (David, asked directly, 2026-08-17): the
     seconds delivered do not change, so the cost is flat while the days grow.
   - **The plan and the budget run on ONE number** — the ideal, through the one reader, since
     2026-08-17 (final) — and **per project since 2026-09-17**. The per-type sheets
     (`quota_standards`) are kept but unread once migration 034 is applied.
   - **A per-second rate is priced as rate × the step's seconds ÷ its positions**, never × days.
     Easy to set by mistake: FCM's Rough Animation slots carried "200 per second" where the
     approved sheet pays 200 per day (2,700 per artist instead of 1,486).

13. **Next.** In rough order of value:
   - ~~**What appears in each shot**~~ — **built 2026-09-02** (`shots.php`, `includes/breakdown.php`):
     `shot_assets` finally has a writer, so the scheduler's per-shot asset dependency can fire.
     **Re-run the schedule on any show whose breakdown has been filled in** — a saved schedule is a
     stored snapshot and will not improve on its own.
   - **The client loop** — the step missing from David's own list, and now the only numbered gap
     left in the cycle. Nothing records a delivery: version sent, notes back, round two. Usually
     where a schedule actually dies.
   - **`crew_absences` + engagement dates in the scheduler** — one phase, not two. A person is
     unavailable before they start, after they finish, and on the days they are away, and those
     are the same rule. The dates now exist and are visibly not being obeyed, which is the right
     kind of pressure to build it.
   - **Cross-project benchmarking** — estimated vs actual per step. Unblocked now that `time_logs`
     exists; it is the reason the estimate baseline was captured.
   - **Where hires come from** — `hires.source` is being recorded from now on. After a couple of
     shows it answers "which of our sources actually produces people we keep", which nothing has
     ever been able to say. Nothing to build yet; it needs the data first, like every other
     history table here.

14. **Then: run it all against the real show.** The specific things still to watch:
   - ~~Apply migration 024 and import `finance_install_04.sql`~~ — done by 2026-08-31. The live
     list of what is pending is §0 and `migrate.php`, not this line.
   - Check the seeded board columns suit how the team actually works, on the Statuses screen.
   - `crew_absences` — now genuinely buildable and paired with the timeline in `ROADMAP.md`.
   - `time_logs` — the per-day table that turns "actual" from elapsed duration into effort.
   - **Rotate the two database passwords** (`davideei_PM`, `davideei_stfc`) — both were pasted
     into a chat transcript on 14 August. cPanel → MySQL Databases, then update each app's
     `config.php` in the same sitting. **The finance user's password too**, if it was ever
     pasted anywhere.
   - STFC's `mail_from_name` was changed to "Anima | Project manager" by accident on
     14 August. Put it back to "STFC Production Dashboard" or that show's ticket emails are
     signed with the wrong product.

**A separate track — the finance side.** Rates, contracts, budgets and invoices live in a
**separate MySQL database with its own user**, which the production user has no rights to.
`includes/money.php` is the only place that connection is ever opened, always behind
`is_admin()`. **This is the middle path, settled 2026-08-15** — not the original 2026-08-12
design, which kept money in a wholly separate application this codebase held no credential for.
That remains the destination and has no date. What is defended against today is an ordinary
mistake in ordinary code, not somebody who can read `config.php`. Don't add a rate column to
`crew` "just for now" — migration 018 did exactly that and 019 took it back out.

**Later, when Plaky is dropped:** a tracking grid (shots/assets × steps, status and assignee
set in place) and — if artists update their own work rather than a coordinator doing it —
logins linked to the crew table, with roles. Because step 3 already owns the status
vocabulary, that phase adds screens; it does not change the schema underneath.

Ship each phase end-to-end before starting the next.

---

## 5. Open questions

*Settled since this list was first written: hosting stays where it is; the platform lives at
`mana.davidsaga.com` (`public_html/mana`); Plaky stays the source of truth for now, with the
schema built as though the platform owned the data. See "Settled earlier" at the foot of
`docs/DECISIONS.md`.*

- **Does STFC data get imported later,** or does that dashboard simply retire?
- **The five decisions waiting on David** in §0: timeline drag carrying downstream steps,
  which steps backgrounds go through and whether they get an environment link, the fate of
  `hiring.php`, whether MSD's File prep really waits for
  colour, and whether to give dragged tasks back their person.
- **The longer-running ones** are in the "Still open" list of `docs/DECISIONS.md`: who updates
  status once Plaky goes, where the finance app lives, credit on shared tasks, one owner per
  ticket, and more.

---

## 6. How to work in the new chat

- Start by reading this file and `docs/ARCHITECTURE.md`.
- **Run `python tools/check_php_blocks.py` before handing anything over.** There is no PHP
  binary on the dev machine, so no `php -l`; this is the only gate. It makes seven passes,
  each earned by a bug that actually shipped: unbalanced `endif;`/`endforeach;`, unterminated
  strings, a `<?php` opened while already in PHP mode, duplicate function names, a function
  that opens a transaction and calls another that opens one (lesson 18), and — where
  `node` is installed — `node --check` over `assets/*.js`. Four of those are outright parse
  errors: the file never compiles, so *every* page requiring it 500s, not just the feature
  being worked on. A broken .js is quieter and worse — the page renders, the script never
  runs, and the feature simply does nothing. The remaining pass catches **English prose that
  escaped a string** — an apostrophe inside `'...'` closing it early — which the
  unterminated-string pass cannot see, because the quotes re-pair further down the file. Counting braces with grep is *not* a substitute
  and produced false alarms on healthy files; `tools/README.md` says why.
- **The checker proves a file compiles, not that a screen works.** With no PHP here, the rest
  is verified the way this whole run of work was: **build a static HTML page from the markup
  the PHP would print, with the REAL `assets/style.css` and the real script, serve it with
  `python -m http.server`, and drive it in the built-in browser** — measuring positions,
  capturing what a form would post (override `fetch` or listen for `submit`), and comparing the
  current script against the changed one. Where logic has to be checked against real data (an
  uploaded spreadsheet, say), **replay it in Python line for line**. Say plainly in the commit
  and to David that PHP itself was not run.
- **David's standing preferences for any screen** (said repeatedly, 2026-09-02 on): **buttons,
  not text links**; a row's actions **on one aligned line**; **commas, never dashes**, in any text
  on screen; plain words rather than the database's vocabulary ("Nobody yet", not
  "placeholder"); saving keeps your place on the page. When he asks "what's the difference
  between X and Y", the answer is usually that one of them should go.
- **Commit locally; David pushes.** He deploys from GitHub Desktop himself, so a commit is not
  live until he pushes, and a migration is not applied until he opens `migrate.php`.
- Work **one phase per conversation**, not one feature — related work should share context.
- Use `/compact` when a chat gets long rather than starting over.
- Update `docs/DECISIONS.md` whenever something is settled, so it survives context loss.
- STFC's code is a useful reference for working patterns:
  `D:\Work\Mana\STFC2\stfc-dashboard\stfc-dashboard_v58\stfc-dashboard\`
