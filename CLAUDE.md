# Anima — house rules for every Claude session

Anima is Mana Animation Studio's production tracker: plain PHP + MySQL, no framework, no build
step. The owner is **David Salinas Garfias**, not a developer: explain trade-offs in plain terms,
and flag anything destructive before doing it. Deep context is in `START_HERE.md` and `docs/`
(read `docs/HANDOFF.md` first); this file is the short version.

## Deploy — read before merging anything

- **Merging to `master` deploys to the live site (https://mana.davidsaga.com) in ~30 s**, via
  `.github/workflows/deploy.yml` (FTP to cPanel). There is no staging; David tests on live.
- The workflow runs **`php -l` on every tracked `.php` file first**; a syntax error stops the
  deploy. It runs on pull requests too, so check the PR's checks before merging.
- **Small fixes** (a bug, a label, alignment): branch, PR, merge, deploy.
  **Features**: branch, PR, then **wait for David's go-ahead** before merging.
- Never commit `config.php`, `uploads/`, `sessions/`, `tools/.bug_key` or anything in
  `bug-inbox/`. The deploy excludes them; keep it that way.

## Working alongside other sessions

- Several sessions may run at once. **Work in your own git worktree** on your own branch cut from
  `origin/master`; check `git worktree list` and `git status` before touching the main checkout.
- Hot files that most features touch: `assets/style.css`, `includes/board.php`,
  `finance_project.php`, `includes/auth.php`, `includes/header.php`. Keep edits there small and
  rebase on `origin/master` right before the PR.
- **Docs entries go in the LAST commit**, after updating from master: `docs/CHANGELOG.md`
  (newest first), `docs/DECISIONS.md` (append at the end), `docs/ROADMAP.md`, and
  `docs/MIGRATIONS.md` when there is a migration. These conflict when written early.

## Database

- **Main DB:** numbered files in `migrations/` (`NNN_name.sql`), applied by David opening
  `migrate.php`. Code ships before the migration runs, so **guard new columns/tables**
  (`column_exists()`, try/catch) and degrade with a message rather than a fatal.
- **Take the next migration number only when merging** (check `migrations/` on `origin/master`);
  two sessions both claiming `070` is the usual collision.
- **Finance DB is separate:** reached only through `includes/money.php`; changes are
  `finance_install_NN.sql` files David imports by hand in phpMyAdmin. Say so explicitly.
- Never change real data (rates, frame counts, statuses) to "fix" a report unless David asks.

## Money rules (David, 2026-10-28 — detail in `docs/DECISIONS.md`)

- Anima tracks **budget against cost, never profit**.
- The budget pays for **everyone** on the show: freelancers, and salaried staff (David, Tim, Renata,
  Junior) at **one blended internal day rate** Jorik supplies. Never store personal salaries.
  Diego is paid per job, like a freelancer.
- Freelancers are paid for the days they work: **one invoice per person per month**, a line per
  project, split across projects by Anima. Always say **"invoice"**, never "bill" (UI, code, docs).
- **USD by default for every rate, invoice and total.** A rare non-USD contract is entered in its own
  currency and converted at **one exchange rate fixed per project** at set-up/sign-off, shown as
  "$520/day (£400)". No daily rates, no Wise fees.
- **Only Jorik can change a budget** after the kick-off sign-off; save old, new, who, when, reason.
- Artists log their time **every day**.

## Code conventions

- Match the surrounding code: long explanatory comments that say WHY and quote David with a date,
  `e()` for every echoed value, prepared statements, `require_once` of `includes/*`.
- Permissions: `can_edit()` (Production), `is_admin()`, `is_supervisor()`, artists. A non-editor
  POST must pass `artist_may_post()` / `supervisor_may_post()` in `includes/auth.php` (an
  allow-list) AND a second ownership check in the handler.
- Anything included by `includes/header.php` loads on every page: keep it light and never let it
  throw.
- No PHP on this machine. Before pushing, syntax-check with a parser if one is set up, and read
  your diff carefully; the deploy gate is the backstop, not the plan.

## Git

- Default branch `master`. Commit messages end with the attribution line the session gives you.
- PR descriptions say what changed, any migration or finance SQL David must run, and what was
  NOT verified (you cannot log in to the live site).

## Tickets and bugs

- 🐞 bug reports: the `/bugs` skill (`tools/pull_bugs.py`).
- Development tickets live on the Studio board in Anima; questions for Tim or Jorik are asked
  there with @mentions, not guessed.
