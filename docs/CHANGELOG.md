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

## 2026-10-29 — Aesthetic pass, phases (b) and (c): project, finance, people and admin screens *(no migration)*

- **Still nothing about content or behaviour**, and still only `assets/style.css`: no screen's PHP
  was edited, the finance screens included.
- **Every panel, card, pop-up and small control** on those screens now uses the same rounded corners
  as Spell, instead of each screen's own. Pop-ups (step filter, status menu, @ picker, shot
  picker, hire popover, saved-place toast) float with Spell's shadow.
- **Tasks, Studio Tasks and My tasks boards**: columns are Spell panels with hairline dividers;
  cards are flat, with the priority stripe and crew bar unchanged.
- **Every table row lifts slightly under the pointer**, like Spell's rows. A tinted row (late,
  short, filled, a heat-map cell) keeps its colour and only gets a shade lighter.
- **Number columns line up digit under digit.**

## 2026-10-29 — Monthly invoices: one freelancer invoice split across projects (IN4) *(finance_install_12, imported by hand)*

- **Finance → Monthly invoices** (`finance_invoice.php`, linked from Studio finances): pick a month
  and see everybody who logged time on a show, what the log says they should invoice, and whether
  their invoice is in. Salaried people are listed apart: they send none.
- **One person's invoice** has its number, received date, status, paid date, the total printed on
  it, a PDF link, notes and a "needs a look" flag with a reason. Its **lines** (project + position +
  days or hours + rate + amount) come **filled in from the days logged that month**, the same
  figures the artist's Home tells them to invoice; untick, correct or add lines before saving.
- **Each line is an ordinary invoice on its own project's Invoices tab**, so project totals, actual
  cost, statements and Final balance count it with nothing else changed. The row shows a "monthly
  invoice →" link back to it.
- **Mark paid** pays the invoice and every line on every project at once.
- **The artist's Home** shows a monthly invoice as one row with its projects listed.
- **Invoices recorded before this** are untouched: one-project invoices, as before.
- Until `finance_install_12.sql` is imported, the page shows the log figures and saves nothing.

## 2026-10-29 — Copy link beside Resend link on the person page *(no migration)*

- **Copy link** makes a fresh one-time login link and shows it on the page, with a Copy button,
  instead of emailing it, for sending on WhatsApp or Slack. Same rules as Resend link: who may press
  it, older unused links stop working, and it is logged ("copied by hand, not emailed"). A link that
  was already emailed cannot be shown again, because Anima only stores a hash of it.

## 2026-10-29 — Aesthetic pass, phase (a): every screen borrows Spell's look *(no migration)*

- **Nothing about content or behaviour changed**: the same words, fields, columns and buttons in
  the same order. Only the look moved, in `assets/style.css`.
- **The same few rounded corners everywhere**, the ones Spell uses: buttons, fields, tabs and
  messages slightly rounder; panels, tiles and cards rounder still. Hairline rules instead of
  boxes, and a shadow only on what floats (pop-ups).
- **Table headings read like Spell's column heads**: small capitals in a quieter ink, so the eye
  goes to the rows. Row dividers are a shade softer.
- **Project tabs**: the tab you are on is shown by its underline alone, and pointing at one no
  longer turns it blue. The Project / Finance switcher sits on a neutral strip.
- **Top bar**: the plain links (Studio Tasks, Artists, …) are quiet ink, so Home, Spell, My tasks
  and Reviews are the only blue up there. Migrations still turns amber when one is waiting.
- Fields have a softer outline that firms up under the pointer; thin dark scrollbars.
- Phases (b) project screens and (c) finance, people and admin screens follow as their own PRs.

## 2026-10-29 — Files on a new task or ticket, from Studio Tasks and My tasks *(no migration)*

- **The New task / New ticket dialog on Studio Tasks and My tasks takes attachments**: pick them,
  drop them on the zone, or paste a screenshot anywhere in the form. Images, video or PDF, within
  the usual upload limits; saved through `create_ticket()` like the project board's form.

## 2026-10-29 — Person / slot page in two columns *(no migration)*

- The four panels on a person's page — **Details**, **Anima login**, **Days off** and **Terms and
  contract** — now sit in **two columns** instead of one, so the page is about one screen instead
  of four and the right half of a wide screen is no longer empty.
- **The tables above stay full width.** Pace step-by-step and Open tickets are five columns wide;
  halving them would be worse than a long page.
- Each panel is a card, and a panel somebody does not get (Terms is administrators-only, Days off
  is hidden on a planning slot) leaves **no empty box** where it would have been.
- One column again below 900px, where two genuinely do not fit.

## 2026-10-28 — Home: an artist's shows, logs, notes and invoice information on one page *(no migration)*

- **New Home page (`home.php`)**, a **Home** button in the top bar for anybody whose login is linked
  to a person. **Artists and supervisors now land on it after signing in**; Production and admins
  still land on the project list.
- **Your shows:** each show you're on, your position, your start and end dates, working days left,
  the show's supervisor(s) (from supervision positions), the time you've logged on it, and links to
  Spell, the Brief, Standards and (freelancers) your statement.
- **Missing log days:** "You haven't logged Tuesday or Wednesday", for last week and this week up to
  yesterday, leaving out weekends, studio holidays, your days off and days outside your dates. Links
  to My tasks to log.
- **What's next:** overdue, due this week, then what's lined up after (tasks and cards). Editing
  stays on My tasks and Spell.
- **Notes waiting on you:** notes on your Spell tasks that you haven't answered with a reply or a new
  version, and tasks sent back (a retake status) with no note yet.
- **Your latest versions** put up in Spell.
- **Freelancers only:** your contract rate ("$520/day (£400)" once a show has a fixed exchange rate),
  **invoice information** for the month (one line per project and step: rate, days or hours, line
  total, month total, with a link to the previous month), and **your invoices** with their status
  (not received yet / received, not paid yet / paid) and dates. Information to put on your own
  invoice, not a ready-made invoice. The panel says plainly how to send it (Tim, 2026-10-28): one
  invoice a month, one line per project, to accounting@mana.tv and nowhere else (not to producers
  or supervisors); Jorik pays it once.
- **Supervisors** also get **Your team**: each person on the shows they supervise, the days they
  haven't logged this week and how many notes are waiting on them. No money.
- **Salaried staff** see no invoice information, amounts or invoice status. **A new Salaried tick on
  the Users page** says who they are (migration 072, `users.is_salaried`). Until 072 runs, admin and
  Production logins count as salaried.
- Always your own: there is no way to ask for somebody else's Home. No budgets, other people's
  rates, forecasts or comparisons appear anywhere on it.

## 2026-10-28 — A contract in another currency is converted at one rate fixed per project *(finance DB: finance_install_11)*

- **USD stays the default for everything.** A rare contract in another currency (Lewis in £) is
  still entered in that currency, and is now costed in dollars at **one exchange rate fixed for the
  project**, shown both ways: `531.52 USD (400.00 GBP)`.
- **The rate is saved automatically**: the day's European Central Bank reference rate (through
  frankfurter, free) the first time a project's Finance page meets the currency, and at sign-off
  for any still missing. It never moves after that unless an admin changes it, with a reason;
  every change is kept (old, new, who, when, why).
- **If the fetch fails, nothing is blocked**: the Overview's new *Exchange rates* table says so and
  takes the rate by hand. Each rate shows its date and its source.
- Converted at that rate: the expense projection and its totals, other expenses, Invoiced / Paid /
  Final balance, Cost to date, invoice totals, the Finance hub, artist statements (the artist's own
  too), the person page, the Excel export and the kick-off snapshot.
- A currency **without** a fixed rate keeps the old "more than one currency" warning, now naming it.
- Needs **`finance_install_11.sql`** in the finance database (imported by David, 2026-10-28).

## 2026-10-28 — Create user / Resend link from the person page *(migration 071)*

- **On a person's page (Team performance → a person), Production and admins get an "Anima login"
  section.** A person with no login gets **Create user**: an **artist** login linked to them, with a
  suggested username (from their email, else their first name) that can be changed.
- **Nobody types or emails a password.** The person is emailed a **one-time link** to choose their
  own. It works once, for 7 days, and only the newest link for a login works. The email names the
  show, the site address and their username.
- **A person who already has a login** shows it (username, role, last seen, when the last link was
  sent, by whom and whether it was used) and gets **Resend link**, e.g. for an old hire back on a new
  show. If the login had been switched off, sending the link switches it back on and says so.
- **If they are on the team page but not staffed on the show**, a ticked box also lets them see it,
  so they do not log in to an empty list.
- **If the email cannot go out** (no address, mail not set up, or a send error), the link is shown
  once on the page to pass on privately.
- **Never for an admin login.** Only an admin can send a link for a Production login.
- The public page is `set_password.php`; after setting the password they are logged straight in.
- Until migration 071 runs, the section says it needs it and logins are made on Users as before.

## 2026-10-28 — A signed-off show's budget is fixed; only a ticked admin changes it *(migration 070 + finance_install_10)*

- **Once a show's kick-off is signed off, its approved production budget is read-only** on the
  finance Overview, with a 🔒 line saying when it was fixed and who can still change it. Before
  sign-off nothing changes.
- **Who can change it is a tick, "Changes budget"**, on the Kick-off page under *Who signs off*,
  beside the sign-off tick. Only administrators can be ticked (nobody else can see a budget).
- **Every change after sign-off needs a short reason** and is recorded: old amount, new amount, who,
  when, why. The list sits under the budget box as *Changes since sign-off*.
- Needs **migration 070** (the tick) and **`finance_install_10.sql`** imported by hand into the
  finance DB (the history). Until both are in, a signed-off budget cannot be changed by anybody,
  and the box says which one is missing.

## 2026-10-27 — Videos open in the review viewer: frame by frame, 0.25× to 4× *(no migration)*

- **Video versions get the Review & draw / Open viewer button too**, and open full screen with a
  transport under the picture: play/pause, one frame back and forward, first and last frame, a
  scrubber, and **Frame N / total · HH:MM:SS:FF**.
- **Speeds .25× .5× 1× 1.5× 2× 3× 4×**, loop on by default, sound off by default (one click on).
- **The frame rate is a picker beside the counter (24 to start)**, remembered in your browser. The
  browser cannot read it from the file, and a wrong rate makes frame steps land between frames.
- **Reviewers draw on a paused frame.** The drawing shows only on that frame. The review is posted
  as "Frame 18 (00:00:00:17) — your note", with that frame and the drawing as a JPEG
  (`…_f0018_review.jpg`). Posting without drawing still records the frame you're paused on.
- Keys on a video: Space plays, ← → (or , .) step frames, Home/End, L loops, M sound. [ and ]
  switch version.
- A file the browser cannot play (usually a ProRes .mov) says so and asks for an H.264 .mp4.

## 2026-10-27 — A review viewer with a pen, for image versions *(no migration)*

- **Clicking an image version on Spell's Versions tab opens it full screen** (or its "Review & draw" /
  "Open viewer" button). Step through the task's versions at the top (‹ ›, the picker or the arrow
  keys), zoom in and out, fit or 100%, and read that version's review notes down the right.
- **Supervisors, Production and admins get a pen**: six colours, three thicknesses, undo (Ctrl+Z)
  and clear, and a box to write the review in. Posting sends the text, plus the version with the
  drawing on top as one JPEG at full resolution, as an ordinary review on that version. So the
  paint-over shows wherever review notes already do: the tab, the timeline, the email.
- Everyone else can open the viewer to look and read, without the pen. Videos and PDFs open as before.
- Ctrl-click on a version still opens the file on its own.
- The **Review & draw** / **Open viewer** button sits at the top of each image version, next to its
  review notes button (moved there the same day, at David's ask).

## 2026-10-27 — Reviews: a queue and a verdict for supervisors and Production *(no migration)*

- **A Reviews button in the top bar**, beside My tasks, with the number of tasks waiting on you.
  Shown to supervisors, Production and admins only.
- **Two stages, by status name.** Artists put work up as **Pending review**; a supervisor's
  queue is Pending review, and Approve moves it to **Ready for review**. Production's and admins'
  queue is Ready for review, and Approve moves it to **Complete**. Either can **Send back** to a
  status they pick, which needs a note.
- **Each card shows the current version** (thumbnail for images, the file otherwise), who did it,
  the version note, the final-files link, and how many review notes it already has. A note typed
  there is posted as a review on that version, so it sits in the task's own conversation in Spell.
- **Open in Spell** and **Copy URL** on every card; the URL lands on that version and opens for
  anyone signed in to Anima.
- A show without a "Ready for review" or "Complete" status is named on the screen, and Approve is
  refused there until the status is added on the Statuses screen.

## 2026-10-27 — Last import date on the Import screen *(no migration)*

- **The Import screen shows the last import**: date and time, who ran it, the file name and how many
  rows it had. Recorded when an import is applied (a cancelled one does not count), as a project
  setting. Imports before 27 Oct 2026 were never recorded, so a project shows "none recorded yet"
  until its next one.

## 2026-10-27 — Breakdown frames no longer change the setup seconds *(no migration)*

- **Frame counts on the shot breakdown belong to the breakdown only.** Typing one used to rewrite
  that shot's seconds on shot setup as well, which moved the expense projection, the per-second
  quotas and the artist statements. Now only shot setup sets the seconds the money reads; the
  breakdown's seconds are its own frames ÷ fps, worked out for display and its export.
- **"Spread evenly" asks before it overwrites** every shot's seconds.
- Shots with no frame count are yellow on the breakdown.

## 2026-10-26 — Export button on the planning table *(no migration)*

- **Export ↓ on the Dates & headcount planning table** (right of the Retake allowance bar) downloads
  the table as a CSV that opens in Excel: the retake allowance on top, then one row per step with
  every column, step and scope, start and deadline split into their own columns. It is read off the
  screen as it stands, so unsaved edits and the live Minimum / Retakes / Duration / verdict / Ideal
  figures are all in it.

## 2026-10-26 — Artist statements: totals row and pinned header *(no migration)*

- **A Total row under the Artist statements list**: days planned, hours and days logged, approved
  seconds, and due / invoiced / left to invoice. Money adds up per currency, one line each, since
  USD and GBP cannot be summed.
- **The list scrolls in its own box**, so the column headings stay at the top and the totals stay
  at the bottom however long the cast is. Prints straight through.

## 2026-10-26 — Supervision positions: candidates, invoices, staffing export *(migration 069)*

- **Line up candidates for a supervision position on Staffing**, exactly as for a slot: from the
  artist database or a new name, move their stage, press Hire. Hiring puts them in the position.
  "Talked to before" and "Also lined up" work the same. The Hiring screen lists them under
  Supervision. Needs migration 069.
- **Invoices can name a supervision position** ("Name, Anim Sup (supervision)" in the position
  picker, adding and editing). Its Actual and Difference columns on the expense projection fill in
  from those invoices. No finance-database import needed.
- **Rates can be set on supervision rows** on the Finance screen (the first version refused them as
  "not on this project").
- **The staffing export has a Supervision band first**, with each position's dates and working days.
- **Artist statements include supervisors**, with their position's dates as the window.

## 2026-10-26 — Supervision positions: Anim Sup, CG Sup, costed from their dates *(migration 068)*

- **Dates & headcount has a Supervision section** under the steps: a position title, a start and an
  end date, nothing else. No days per item, no headcount, never scheduled.
- **Staffing shows them first**, under "Supervision": put somebody from the crew list in, or type a
  new name; take them out again without losing the position.
- **The expense projection costs them**: a "Supervision" band right after Above the line, at the
  working days between the two dates (weekends and studio holidays left out) times the person's
  rate (per day, per hour at the show's hours per day, or flat). The Excel export, the person page
  and the kick-off snapshot carry the same rows.
- Not yet: lining up candidates for a supervision position on Hiring, invoices attached to one,
  and the staffing export. The studio's own leadership still goes in the quote's markup.

## 2026-10-26 — Invoices: Expected / Pending / Paid, and editable in place *(finance_install_09)*

- **An invoice's status is now Expected, Pending or Paid** (expected: not arrived yet; pending:
  arrived, waiting to be paid). Needs `finance_install_09.sql` imported into the finance database;
  until then the dropdown keeps the old four. Expected invoices do not count as "invoiced" on an
  artist's statement.
- **Every recorded invoice can be edited in place**: "edit" on its row opens all its fields under
  it (position, name, reference, amount, currency, status, period, dates, link, notes).

## 2026-10-26 — Artist statements: what each person should invoice *(no migration)*

- **A new Finance management tab, Artist statements**: everybody with a position, a task or logged
  time on the show, with their contract dates, rate, days planned, time logged, seconds approved,
  what is due so far, what they have invoiced and what is left to invoice.
- **Each person's full statement** (statement.php): the same figures with where the plan has them
  and their invoices, printable or saved as PDF.
- **Counted over each person's contract dates** from the person page, or the dates of their steps
  where none are set. Days come from logged time (÷ the show's hours per day), seconds only from
  **approved** shots (split evenly when shared), and the amount follows the rate unit (day, hour,
  second or flat).
- **Artists see their own** from a *My statement* button on My tasks, and nobody else's: the money
  is read through two new readers in `includes/money.php` that only return a record to an admin or
  to the person it belongs to.

## 2026-10-26 — Timeline bars fill themselves from what has been delivered *(no migration)*

- **Each step's bar on the general timeline now shows its progress automatically**: the share of
  its shots or assets delivered (approved counts), or of its seconds for a step measured in footage,
  with N/A items left out. The same figure the dashboard counts.
- **Setting it by hand still works and overrides it**: drag the bar's progress handle or type a
  percentage in the step editor. Hand-set numbers carry a ✎ after the percentage.
- **"Back to automatic"** in the step editor hands a bar back to the count. Progress set by hand
  before today stays as it was until then.

## 2026-10-23 — Invoice notes: a Notes column you can type in *(no migration)*

- **A project's Finance management → Invoices list has a Notes column**, and each row's note is a
  box you can type in: press Enter or click away and it saves (only the note; nothing else on the
  invoice changes). Empty the box to clear it. So a note can go on an invoice recorded weeks ago.
- Same day, first pass (#86): the list printed notes as a grey line under the name, but only for
  invoices that already had one, and there was no way to add one afterwards, so on most screens
  nothing appeared. The Record invoices form always saved a Notes box and the finance database
  always had the column; the list just never showed it.
- The Notes box in Record invoices is the last one on the right of the grid; it now has a
  placeholder and the help text above the form says where it is.

## 2026-10-23 — Upload progress bar on Spell's task panel *(no migration)*

- **Uploading a version, a review or a comment with files now shows a progress bar** with the
  percentage, "X MB of Y MB" and the time left ("about 3 min left"), and the button counts up
  with it. The ticket, task and Brief upload bars show the time left too. Once the bytes are up it says
  "Saving on the server…" until the reply is back. Tickets, tasks and the Brief already had one.
- Goes with raising the host's PHP limits to 512 MB a file (cPanel → MultiPHP INI Editor:
  `upload_max_filesize` 512M, `post_max_size` 600M, `max_input_time` 900) and `upload_max_bytes`
  in the live `config.php` to match.
- If the host itself refuses an upload (too large, the firewall), the panel says so with the HTTP
  code instead of pouring the host's error page into the pane.

## 2026-10-23 — Naming convention on the Brief, enforced on every upload *(needs migration 067)*

- **A Naming convention section on the Brief**: one template for shots and one for assets, written
  with placeholders ({PROJECT}, {EPISODE}, {SHOT}, {TYPE}, {ASSET}, {STEP}, {VERSION}, {INITIALS}),
  with one-click presets matching Mana's MSD, FCM and STFC2 documents.
- **Step and asset type codes** (FX, AN, CH, EN…) are set per show, guessed from the names until
  typed. Whether the first version is V00 or V01 is set per show too.
- **On Spell's upload form** the expected name is shown before anything is picked, every picked file
  is checked straight away, and Send for review stays off until each one matches and carries the
  next version number. The server refuses the same files, so nothing gets around it.
- Matching ignores capitals and the file extension. Whole-show deliverables are not checked.

## 2026-10-22 — Spell: a task's Activity is now its Timeline *(migration 066 for reassignments, dates and notes)*

- **The Activity tab is now Timeline**: everything that happened to the task on one vertical line,
  newest at the top, each with a small date. Status changes, new versions (with a thumbnail that
  expands when clicked), comments, review notes, and, once migration 066 runs, reassignments, due
  date changes and edits to the task's note and link.
- **Every entry opens in a pop-up** over the panel: the full-size version with what changed and its
  final-files link, the whole comment or review with its files, or the before and after of a
  change. Buttons in the pop-up jump to Comments or Versions and notes. Escape closes it.

## 2026-10-22 — Spell: reviews on versions, a Notes column, links to final files *(needs migration 065)*

- **Make a review** (supervisors, Production, admins): on the Versions tab, pick which version it
  is about, write notes, and paste images straight into the text or attach files. Naming somebody
  rings their bell and emails them. Uploading a new version is still there, one click away.
- **Every version has a button at its top**: *Review notes · N* to show its notes, or *Make a
  review* to start one with that version already picked. Reviews also appear on the Comments tab,
  marked "review of v02".
- **The Comments column is now Notes**: a button with how many review notes the current version
  has, opening the task straight to them. The Version column is a button too.
- **Link to the final files** (Drive or LucidLink) can be left with a version. It shows as *Open
  final files* on that version and fills the task's Link column.

## 2026-10-22 — Spell: Version and Comments columns; a note on every version *(no migration)*

- **Version column:** each task shows its current version (v03). Click it to open the latest
  file: an image opens full size over the board, a video or PDF in a new tab.
- **Comments column:** a circle with the number of comments on the task. Click it to open the task
  on its Comments tab. Empty when nobody has said anything.
- **"What changed" is now required** when sending a version for review, and the form is headed
  **Upload new version**, in larger text.

## 2026-10-22 — Spell: send a version for review, and name who should look *(no migration)*

- **The Versions tab's button now says "Send for review"**, and the form sits at the top of the
  tab, above the versions, instead of under every preview.
- **Type @ in "What changed" to name a reviewer**, with the same picker as comments. They get a
  bell notification and an email, and their name is highlighted on the version.
- **The task panel opens to half the screen** (at least 480px wide), so previews have room.

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
