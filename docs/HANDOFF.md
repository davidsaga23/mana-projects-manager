# Anima — Handoff / Current state

**If you are a new Claude conversation or a new account picking this project up cold, read this
file first.** It is written to be self-contained: everything you need to understand what the app
is, how it is built and deployed, what has shipped, what is pending, and what is still open —
without any chat history or external memory.

> **State as of 2026-10-21.** This is the freshest status in the repo. The older dated banners in
> `START_HERE.md` and the long build logs in `docs/ROADMAP.md` are kept for their *reasoning*, not
> their status. When two files disagree about what is live, this file and
> `docs/MIGRATIONS.md` win.

Audience note: the owner, **David Salinas Garfias**, is an executive producer, **not a
programmer**. Explain technical trade-offs in plain language, debate decisions rather than just
executing them, and flag anything destructive or irreversible before doing it.

---

## 1. What the app is

**Anima** (internally "mana-projects-manager", live as "Anima | Project manager") is a
**multi-project production tracking platform for a small animation studio** — Mana Animation
Studio. Mana runs several shows at once (recent ones: FCM / "FC Mobile", MSD, Doomsday, a 2D
motion comic). The app is in real daily use at **<https://mana.davidsaga.com>**.

It is, at heart, a **tracker**: it stores numbers, dates, names, statuses and files, and every
screen is a form, a table, a grid or a chart. It is deliberately **not** a media/video app — it
holds no renders or playback (see "What it is not", below).

What it covers, end to end — all ten steps of David's production cycle:

1. **Timeline / schedule** — a forward auto-scheduler (shot/asset counts + deadline + headcount →
   dates) and a reverse one (→ minimum quota per artist per day). Two Gantt charts (general per
   step, detailed per task), milestones, `.gan` round-trip with GanttProject, saved versions.
2. **Budget** — agreed amount, currency, terms, per project.
3. **Brief** — the ask, deliverables, references, attachments.
4. **Breakdown** — shots and assets, imported (CSV/Excel/Plaky) or typed in; assets-per-shot.
5. **Expense projection** — computed live from each project's own ideal quotas.
6. **Hiring / Staffing** — positions by department, candidates per position, hire links a person
   to a position.
7. **Detailed timeline** — per-task Gantt.
8. **Contracts** — status and dates per person.
9. **Invoices** — per position, paid/overdue.
10. **Budget reports** — budget vs projected vs invoiced.

Plus a **tracking grid** (shots/assets × steps, colour = status), **tickets/tasks** (a board plus
"My tasks" and "Studio Tasks"), **per-person and per-team performance**, an **artist database**,
**bookings** (who is needed on which show when, cross-show clashes), **historic quotas and fees**,
a **budget pool** (the show expressed in days), a **kick-off sign-off**, a **postmortem** state,
and overhead **studio-time** logging.

**The one thing deliberately missing from the cycle is the client loop** — recording what was
delivered to the client and what came back (version sent, notes, round 2). There is an "In client
review" status and retakes are tracked, but no delivery log. For an animation studio this is
usually where a schedule actually dies, so it is the most valuable gap.

### What it is not, on purpose
- **Not a video/review tool.** No version-file upload, no playback, no review playlists. Shared
  cPanel hosting is the wrong place for multi-GB media, and Frame.io / Dropbox Replay already do
  that job. If review is ever built, the recommended shape is a *review layer* (a version record
  pointing at a URL wherever the file already lives, plus timecoded notes feeding the same
  statuses) — not a media host. See `docs/ROADMAP.md` → "Online version review".
- **Not multi-tenant SaaS.** It is one studio, one database. There was a separate exploration about
  selling it to outside customers; see §8.

---

## 2. How it is built

- **Language/stack:** plain **PHP + MySQL**, no framework. Front end is hand-written HTML, CSS and
  vanilla JavaScript. **No build step, no package manager, no Composer, no npm dependencies.** The
  only third-party front-end library is **Frappe Gantt** (vendored) for the timeline.
- **Shape of the code:** ~56 PHP pages at the repo root (one per screen, e.g. `index.php`,
  `project.php`, `timeline.php`, `mytasks.php`, `finance.php`), shared logic in `includes/*.php`,
  front-end assets in `assets/*.{css,js}`, schema in `install.sql` + `migrations/`.
- **Charts are hand-rolled SVG** (donuts, trend lines, the workflow diagram). No chart library has
  been added beyond Frappe Gantt and none is needed.
- **Styling is tokenised.** Every colour is a role on `:root` in `assets/style.css` (e.g.
  `--surface`, `--border`, `--on-primary`); nothing outside `:root` holds a literal colour. The app
  is dark in Mana's colours (#101010, white, sky blue #5FB2FF, spring green #26FE89); `@media print`
  turns it light. **Never write a colour into a rule — add a role to `:root`.**

### Two databases
1. **Production DB** — everything above. Credentials in `config.php` (not in git).
2. **Finance DB — SEPARATE, with its own MySQL user the production user has no rights to.** Rates,
   contracts, budgets, invoices and hire quotes live here. **`includes/money.php` is the only place
   that second connection is ever opened, and only behind `is_admin()` / `can_see_money()`.** Keep
   it that way: it means a bug in any ordinary query physically cannot reach a pay rate. The finance
   DB is installed from the `finance_install*.sql` files, imported **by hand** through phpMyAdmin,
   **never** through `migrate.php`. See `docs/MIGRATIONS.md`.

### Roles (access levels)
From lowest to highest access:
- **artist** — read-only across most of the app; can set the status of work assigned to them (only
  to In progress or Pending review), log their own time on their own task cards, and log their own
  overhead (studio-time) hours.
- **supervisor** — artist permissions plus view-only Timeline and Staffing, editable Shots, and the
  Hire button on Staffing (every hire emails the admins).
- **editor** (displayed everywhere as **"Production"**) — full production editing.
- **admin** — everything, including the finance DB and user management.

`includes/auth.php` is the single source of truth for who can do what (`can_edit()`,
`can_see_money()`, `is_artist()`, `is_supervisor()`, `can_log_own_time()`, the `artist_may_post()`
allow-list, and the per-user project-visibility helpers). An admin can **preview the app as another
role or as a specific named person** (read-only) from the Users page.

### The one external call
`includes/ai.php` is the **only** outbound API call in the whole platform. It powers the
"transcript → tasks" feature and uses an Anthropic API key set in `config.php`. It is slow (says
so on screen), costs money per use, and can fail (reported honestly). Any future AI assistant
should reuse this shape. Nothing else in the app talks to the outside world.

---

## 3. How it is deployed — READ BEFORE TOUCHING DEPLOY

- **The default branch is `master`** (not `main`).
- **Merging to `master` auto-deploys to the live site in ~30 seconds**, via a GitHub Actions
  workflow that FTP-syncs changed files to `public_html/mana` on cPanel. A commit is **not** live
  until it is on `master`. A branch needs a pull request merged to `master` to go live.
- David deploys/merges himself (historically from GitHub Desktop); he reviews, then merges.
- **After a deploy that adds a migration, someone must open `migrate.php` once** in the browser to
  apply it. The app shows an "updates waiting" banner when migrations are pending. Finance
  (`finance_install*.sql`) files are imported by hand via phpMyAdmin instead.
- Full setup guide (FTP secrets, the two-folders trap, rollback): `docs/GIT_SETUP.md`.
- **App clock is `America/Los_Angeles`** (set 2026-10-16), configured in `config.php` → read by
  `includes/db.php`. (Earlier history ran on `America/Mexico_City`; see the open date-offset defect
  in §7.)

### ⚠ What the cloud dev environment CANNOT do
This repo is often worked on from a **cloud container that cannot reach the live site.** From the
cloud you **cannot**:
- reach the live server, the live `config.php`, or the live MySQL databases;
- change or even see the host's **Mod_Security / WAF** rules (which have blocked features before —
  e.g. a GET search was refused as "Not Acceptable" until changed to POST);
- **send email** (so every email feature must be confirmed on the live site, never from cloud).

You **can** stand up a throwaway test MySQL DB in the cloud for checking SQL, and you can run the
static checker (below). **Say plainly in commits and to David that PHP itself was not run** when
that is the case.

### Verifying work without a live server
There is no PHP binary on the dev machine, so no `php -l`. The gates are:
1. **`python tools/check_php_blocks.py`** — the closest thing to a linter. Seven passes, each
   earned by a bug that actually shipped (unbalanced `if:/endif;`, unterminated strings, a `<?php`
   opened while already in PHP mode, duplicate function names across files, a nested transaction,
   an apostrophe inside a single-quoted string, and `node --check` over `assets/*.js` where node is
   present). **Run it before handing anything over.** See `tools/README.md`.
2. For UI logic: build a static HTML page from the markup the PHP would print, with the real
   `assets/style.css` and the real script, serve it with `python -m http.server`, and drive it.
3. For data logic: replay it in Python line for line against the real spreadsheet/data.

---

## 4. Current live state (2026-10-21)

**Everything built through PR #26 and PR #12 is merged to `master` and live.** The per-PR plain-
language list is in **`docs/CHANGELOG.md`**. In short, the most recent batch (2026-10-17 → 10-21)
added: a Supervisor role; a Pomodoro timer; the kick-off sign-off flow; a Studio-time overhead
panel (and the Editor→Production rename); Freelance/Vendor collaborator type on the person;
snooze; the new-task email + My-tasks badge; the My-tasks Tasks/Tickets UI; artists & supervisors
logging their own time; the real "Last seen" fix; per-user project visibility; and "view the app
as a specific person".

### ⚠ Migrations are the main thing a new account must track
Code ships ahead of the database. Several migrations are **pending** (waiting for David to open
`migrate.php`), and features that need them stay **dormant and harmless** until they run — every
query guards on the new column existing. **`docs/MIGRATIONS.md` is the authoritative ledger** of
which migration unlocks what and which are still pending. Do not assume a migration is applied
because the code is merged.

---

## 5. Where to look in the repo

| You want to understand… | Read |
|---|---|
| This — the cold-start summary | `docs/HANDOFF.md` (this file) |
| What each migration does and which are pending | `docs/MIGRATIONS.md` |
| Every shipped feature, plain language, PR by PR | `docs/CHANGELOG.md` |
| The original vision, build order, and hard-won lessons | `START_HERE.md` |
| The schema and the scheduler, in depth | `docs/ARCHITECTURE.md` |
| Requested features and where each belongs | `docs/ROADMAP.md` |
| Every decision and **why** it was made | `docs/DECISIONS.md` |
| Git + FTP deploy setup for a non-programmer | `docs/GIT_SETUP.md` |
| The Plaky export format and its traps | `docs/PLAKY_EXPORT.md` |
| The migration rules and the static checker | `migrations/README.md`, `tools/README.md` |

**`docs/DECISIONS.md` is append-only and is the single most valuable file for not reopening
settled ground.** `START_HERE.md` and `docs/ROADMAP.md` carry a lot of reasoning but their dated
status banners are historical — trust `docs/HANDOFF.md` and `docs/MIGRATIONS.md` for "is it live".

---

## 6. How to work here (conventions that matter)

- **Commit on a branch, open a PR to `master`, let David merge** (merging is what deploys). The
  branch for a given task is told to you at the start of the session.
- **David's standing UI preferences** (said repeatedly): buttons, not text links; a row's actions
  on one aligned line; **commas, never dashes** in on-screen text; plain words rather than database
  vocabulary ("Nobody yet", not "placeholder"); saving keeps your place on the page
  (`assets/keepplace.js`). When he asks "what's the difference between X and Y", the answer is
  usually that one of them should go.
- **Cache-bust assets** with the `asset_ver()` helper — never ship a `.js`/`.css` change a browser
  will serve stale.
- **End dates are inclusive everywhere** (DB, API, UI). Convert only at the boundary of external
  tools (Frappe Gantt, GanttProject).
- **Run `tools/check_php_blocks.py` before handing over**, and say whether PHP/DB were actually
  run.
- Work one coherent area per conversation, not one tiny feature at a time.
- Update `docs/DECISIONS.md` when something is settled, and this file + `docs/MIGRATIONS.md` when
  state changes.

---

## 7. Known defects and boundaries a new account should know

- **⚠ Open date-offset defect (found 2026-10-09, not fixed).** PHP's timezone is set from
  `config.php` but MySQL's is not, so `CURDATE()`/`NOW()` (used in ~16 places) can disagree with
  PHP (~45 places) by several hours, filing late-evening work under the wrong day. The one-line fix
  and its two consequences are written up in `docs/ROADMAP.md` → "Open defect". David has seen it
  and not yet said to apply it.
- **Project visibility scopes the list and the project-hub door only** (PR #26). A scoped user no
  longer sees shows they are not staffed on in the list or via the hub, but **direct deep-links to
  sub-pages** (e.g. `shots.php?project=X`) are still reachable — this matches the app's existing
  "hide the tab, don't lock the door" posture for viewers. Locking every sub-page for scoped users
  was offered as a follow-up and not built. Prerequisite: every real login needs `users.crew_id`
  set (admin does this on the Users page) or a scoped user sees nothing.
- **Plaky still owns production status** (for now). A status hand-set in Anima is overwritten by the
  next Plaky import. Dropping Plaky is a decision, not a feature — it is the moment the import stops
  being the source of truth. Much of the "replace Plaky" wishlist is cheap to build but impossible
  to *adopt* until that call is made.
- **The static checker proves a file compiles, not that a screen works.** See §3.

---

## 8. Open / unbuilt — the backlog for the next account

Detail and reasoning for each is in `docs/ROADMAP.md`; the headlines:

- **Client loop / delivery log** — the missing production step; highest value.
- **Budget pool feedback** — the MVP needs a real show run through it; then logged-hours mode, a
  standard rate card per step, and earned value.
- **`crew_absences` + engagement dates in the scheduler** — a person is unavailable before they
  start, after they finish, and when away; same rule, not built.
- **Cross-project benchmarking** — estimated vs actual days per step across shows.
- **Postmortem notes capture** — the `postmortem` state exists (migration 044); the per-
  department/per-step note inputs do not yet.
- **Project kick-off project-level baseline** — the sign-off exists (migration 049); widening the
  frozen snapshot and deciding exactly which fields it captures is open.
- **Version counting** — the capture (`progress_log` retake classification) exists; blocked on
  Plaky still owning status.
- **"Passed over for a position" report** — the data exists (`hires.stage`); it is just a report to
  build.
- **Replace Plaky (full PM tool)** — mostly already in the schema; the genuinely new parts are
  version upload and review playlists, which are a different kind of software (media). See
  `docs/ROADMAP.md`.
- **In-app AI assistant** — Gemini or Claude, undecided. The plumbing (`includes/ai.php`) exists.
  The hard parts are permissions enforced before the model, fixed tools (never text-to-SQL), every
  answer citing its source screen, and the client-data policy question. Read the full ROADMAP entry
  before building.
- **MSD-11 vendor/studio flag**, renaming a project type, cleaning up unreferenced crew rows,
  locking scoped sub-pages, `hiring.php`'s fate (orphaned on purpose) — smaller open items.
- **Lock scoped project sub-pages** — see §7.

### Not part of the internal app: the "sell it to customers" exploration
There was a separate workstream about turning the tracker into a product sold to outside customers
(invite link → customer workspace → 20-day trial → Stripe annual payment). It was **planning only**
and its chat thread was later deleted. A plan document may still exist at
`/mnt/project-files/selling/selling-the-app-plan.md` (outside this repo). The key recommendation
was "one database per customer" rather than shared multi-tenant. **Do not expand this unless David
raises it** — it is a different product, not part of the internal studio tool.

---

## 9. The quickest possible orientation

If you only read one more thing after this, read **`docs/MIGRATIONS.md`** (so you know what is
actually live in the database) and **`docs/DECISIONS.md`** (so you don't reopen settled ground).
Then skim **`docs/CHANGELOG.md`** for what exists. Everything else is depth you can pull in when a
specific task needs it.
