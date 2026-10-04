# Git + automatic deployment — setup guide

Written for someone who hasn't used git before. No command line required.

**What you get:** you change a file, click one button, and ~30 seconds later the site is
updated — only the files that actually changed. Plus a full history you can roll back to.

---

## Part 1 — What git actually is (60 seconds)

Think of it as **save points for a whole folder**.

- A **commit** is a save point: "here's what changed and why"
- **Push** sends your save points to GitHub (the backup + the trigger for deployment)
- **Revert** rolls back to an earlier save point

That's the whole daily model. The concepts below (branches, merges) exist but you won't need
them for this workflow.

**Why bother:** it replaces the `v52 / v53 / v54…` folders. Instead of guessing what changed
between two zips, git shows you exactly — line by line. And it removes the manual
file-picking that has caused most of the deployment bugs on STFC.

---

## Part 2 — One-time setup

### 1. Install GitHub Desktop
<https://desktop.github.com> — free, and the only app you'll need.
Sign in (create a GitHub account if you don't have one).

### 2. Create the repository
In GitHub Desktop: **File → Add local repository** → choose
`D:\Work\Mana\mana-platform` → it will offer to create a repository there → accept.

Then **Publish repository**. **Tick "Keep this code private."**

> The repo contains no passwords — `config.php` is excluded by `.gitignore` — but keep it
> private anyway.

### 3. Get your FTP details
cPanel → **FTP Accounts**. Either use an existing account or create one scoped to the
deploy folder. You need:

- **Server** — e.g. `ftp.davidsaga.com`
- **Username** — the full FTP username
- **Password**
- **Target directory** — where the site lives. For this install it is
  `/public_html/mana/` *(must end with a slash)*

> **⚠ There are two folders called `mana` on this account.** The live site is
> `/home2/davideei/public_html/mana`. A stray `/home2/davideei/mana/public_html/mana` holds the
> abandoned first deploy of 11 August — it has a `config.example.php` but no `config.php`, and
> nothing serves it. Typing `mana` into cPanel File Manager's "Go" box lands you in the wrong
> one, because that box reads paths relative to your home directory. Type `public_html/mana`.
> When in doubt, open `smtp_test.php` in the app: it prints the absolute path of the config
> file the running application actually loaded.

### 4. Store them as GitHub secrets
On github.com, open the repo → **Settings → Secrets and variables → Actions →
New repository secret**. Add four:

| Name | Value |
|---|---|
| `FTP_SERVER` | `ftp.davidsaga.com` |
| `FTP_USERNAME` | your FTP username |
| `FTP_PASSWORD` | your FTP password |
| `FTP_TARGET_DIR` | ⚠ **depends on the FTP account — see below** |

Secrets are write-only — GitHub never displays them again, and they don't appear in logs. That
cuts both ways: once set, nobody can read the target directory back out of GitHub to check it.

> **⚠ THIS TABLE USED TO CARRY `/public_html/tools/ManaProjects/PLATFORM/`, and it was wrong.**
> It was written on day one, before anything had been deployed, and never corrected — while the
> paragraph above it, added later from the real server, says the live site is
> `/home2/davideei/public_html/mana`. Two values, one of them a guess from before the fact, and
> nothing on screen to say which. Found on 2026-10-03, when the repo moved accounts and the
> secrets had to be typed in again from this page.
>
> **Read it off the running application rather than trusting any document, including this one.**
> Open `smtp_test.php` while signed in as an administrator: it prints the absolute path of the
> `config.php` the live app actually loaded. The directory holding that file, written relative to
> the FTP account's home and ending in a slash, is `FTP_TARGET_DIR`.
>
> **⚠ KNOWING WHERE THE APP LIVES IS ONLY HALF OF IT.** `server-dir` is not an absolute path on
> the server — it is the path from **wherever the FTP account lands when it logs in**. So the same
> folder has two different correct answers depending on which account the deploy uses:
>
> | FTP account's Directory (cPanel → FTP Accounts) | `FTP_TARGET_DIR` |
> |---|---|
> | `/home2/davideei` (the main account) | `/public_html/mana/` |
> | `/home2/davideei/public_html/mana` (scoped to the app) | `/` |
>
> **Match the username in `FTP_USERNAME` to a row on that page and read ITS Directory.** Not any
> row — there are several, and they land in different places.
>
> **This is what the stray folder above is.** `/home2/davideei/mana/public_html/mana` has exactly
> the shape of an account homed in one place plus a target repeating part of it, and getting it
> wrong a second time would quietly build a third copy rather than failing. The deploy creates
> what it cannot find.
>
> Written on 2026-10-03, after confidently recording `/public_html/mana/` here as "verified" on
> the strength of the config path alone. The config path says where the APP is; it says nothing
> about where the ACCOUNT starts, and those are the same thing only for the main account.
>
> Getting it wrong does not delete anything — the deploy would simply build a second copy of the
> app somewhere nobody serves, which is how the abandoned folder described above came to exist in
> the first place.

### 5. Do a dry run first
Before the first real deploy, open `.github/workflows/deploy.yml`, set `dry-run: false` to
`dry-run: true`, and push. Then on github.com → **Actions** → open the run and read the log.
It lists every file it *would* transfer without touching the server.

Confirm the paths look right, then set it back to `false`.

---

## Part 3 — Daily workflow

1. Edit files (or I do)
2. Open GitHub Desktop — changed files are listed, with the exact lines highlighted
3. Type a short summary, e.g. *"Fix quota rounding on step detail"*
4. Click **Commit to main**
5. Click **Push origin**
6. Wait ~30 seconds — the site is live

To check a deploy: github.com → repo → **Actions**. Green tick = done, red X = something
failed, and the log says what.

---

## Part 4 — Rolling back

Something broke in production:

1. GitHub Desktop → **History** tab
2. Right-click the bad commit → **Revert changes in commit**
3. Push

That creates a new commit undoing the change and redeploys the previous state — typically
under a minute, and nothing is lost.

---

## Safety notes

**Files that must never deploy** (already excluded in `deploy.yml` and `.gitignore`):

| Path | Why |
|---|---|
| `config.php` | Live DB + SMTP passwords. Deploying would overwrite them and take the site down. |
| `uploads/` | Ticket attachments. A mirroring sync could **delete real files**. |
| `sessions/` | Live login sessions. |
| `docs/`, `*.md` | Documentation — no reason to publish it. |

**`config.php` lives only on the server.** Set it up once by hand. When a new setting is
added, `config.example.php` documents it and you add the line to the live file manually.

**Never run `install.sql` against a live database** — it drops every table. It exists only
for a fresh install.

---

## If something goes wrong

| Symptom | Likely cause |
|---|---|
| Action fails, "530 Login incorrect" | Wrong FTP secret; re-enter all four |
| Action succeeds but the site is unchanged | `FTP_TARGET_DIR` points at the wrong folder |
| Site 500s right after a deploy | A PHP file was mid-transfer, or has a syntax error — check the Actions log, then revert |
| "Permission denied" on some files | FTP account lacks write access to that directory |
