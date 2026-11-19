# The Google Sheet backup of Spell

**What it is.** Every hour Anima copies Spell into one Google Sheet: a tab per show and a row per
task (one department's work on one shot or asset). The row shows the status, the assignee, the latest
version, the due date, frames, notes and link. If Anima is ever down, the studio opens the sheet and
keeps working there. When Anima is back, Production reviews what changed in the sheet and ticks what
to bring back. **Nothing comes back by itself.**

Asked for by David on 2026-11-19, after a morning he could not open Anima. The reasons for the design
are in `docs/DECISIONS.md` (2026-11-19).

---

## Set it up (once, about 30 minutes)

You need: your Mana Google account (david@mana.tv), cPanel for anima-mana.com, and Anima open as an
admin. In each step, **bold words** are what you click.

### 1. Make a Google Cloud project (the account the sheet is written by)

1. Go to **console.cloud.google.com** and sign in with your Mana account. Accept the terms if asked.
2. At the top left, next to "Google Cloud", click the project picker, then **New project**.
   Name it `Anima backup`. Leave Organization as mana.tv. Click **Create**, then select it in the
   picker so it is the one open.
3. Turn on the Sheets API. In the search bar at the top type `Google Sheets API`, open it and
   click **Enable**. No other API is needed.

### 2. Make the "service account" and download its key

A service account is a Google login for a program instead of a person. Anima uses it to write the
sheet. It can only open what you share with it.

1. Menu (☰) → **IAM & Admin** → **Service Accounts** → **+ Create service account**.
2. Name: `anima-sheet-backup`. Click **Create and continue**. Skip the roles and the
   user access: click **Continue**, then **Done**.
3. Click the new account in the list. **Copy its email address**, which looks like
   `anima-sheet-backup@anima-backup-123456.iam.gserviceaccount.com`. You need it in step 3.
4. Open its **Keys** tab → **Add key** → **Create new key** → **JSON** → **Create**. A `.json` file
   downloads. **This file is a password**: anyone who has it can edit the backup sheet. Don't email
   it or put it in Drive. You upload it to the server in step 4, then delete the downloaded copy.

> **If Google says "Service account key creation is disabled":** Google Workspace switches this off
> by default for organisations created since 2024. Someone who is a **Super Admin** of mana.tv turns it
> off for this one project:
> Menu (☰) → **IAM & Admin** → **Organization Policies** → search `Disable service account key
> creation` → **Manage policy** → **Override parent's policy** → add a rule → **Off** →
> **Set policy**. If the button is greyed out, the Super Admin also needs the "Organization Policy
> Administrator" role (IAM & Admin → IAM, at the organisation level). Then repeat step 2.4.

### 3. Make the Drive folder and the sheet

1. In **Google Drive**, make a folder called `Anima backup`.
2. Inside it, **New** → **Google Sheets** → **Blank spreadsheet**. Name it `Anima backup — Spell`.
   Leave it empty. Anima adds the tabs.
3. Share the **folder** with the service account: right-click the folder → **Share** → paste the
   email from step 2.3 → choose **Editor** → untick **Notify people** → **Share**.
   If Drive warns that the address is outside Mana, confirm. If your Workspace blocks sharing
   outside the organisation, a Workspace admin has to allow it for this address (Admin console →
   Apps → Google Workspace → Drive and Docs → Sharing settings).
4. Share the folder with the team too (everyone who should work from it during an outage), as
   **Editor**. The easiest is **General access** → **Mana** → **Editor**.
5. Open the sheet and copy its address from the browser. It looks like
   `https://docs.google.com/spreadsheets/d/1AbC…XyZ/edit`. You need it in step 5.

### 4. Put the key on the server

1. cPanel → **Files** → **File Manager**. You start in your home folder (the one that *contains*
   `public_html`). Don't go into `public_html`: everything in there can be downloaded by a browser.
2. **+ Folder** → name it `private` → **Create New Folder**. Open it.
3. **Upload** → choose the `.json` file from step 2.4. Go back to the folder when it finishes.
4. Right-click the file → **Rename** → `anima-sheet-backup.json`.
5. Right-click it → **Change Permissions** → tick only the two **User** boxes Read and Write
   (it reads `0600`) → **Change Permissions**.
6. Note the full path shown at the top of File Manager, e.g. `/home/ACCOUNT/private`. ACCOUNT is
   your cPanel username. The key's path is `/home/ACCOUNT/private/anima-sheet-backup.json`.
7. Delete the downloaded copy from your computer's Downloads folder.

### 5. Tell Anima where they are (`config.php`)

In File Manager open `public_html/config.php` → **Edit**. Just above the line `'maintenance' => …`,
add these two lines, with your own path and sheet address:

```php
    'google_service_account_json' => '/home/ACCOUNT/private/anima-sheet-backup.json',
    'google_backup_sheet_id' => 'https://docs.google.com/spreadsheets/d/1AbC…XyZ/edit',
```

Keep the quotes and the comma at the end of each line. **Save Changes**.

### 6. Apply the database update and try it

1. In Anima, open **Migrations** (admin menu) and apply **105 sheet_backup**.
2. Open the menu → **Sheet backup** → **Copy now**. After a few seconds it should say
   "Copied N shows to the sheet". If it shows an error instead, it says what is wrong; the usual
   two are "not shared with the service account" (step 3.3) and "Sheets API not enabled" (step 1.3).
3. Click **Open the backup sheet ↗** and check a show you know.

### 7. Add the hourly cron job in cPanel

1. cPanel → **Advanced** → **Cron Jobs**.
2. *Add New Cron Job* → *Common Settings*: **Once Per Hour (`0 * * * *`)**, then change the
   **Minute** box to `5`. Five past the hour, so it does not run at the same moment as other jobs.
3. *Command*, with your cPanel username for ACCOUNT:

   ```
   /usr/local/bin/php /home/ACCOUNT/public_html/cron_sheet_backup.php >/dev/null 2>&1
   ```

4. **Add New Cron Job**. An hour later the Sheet backup screen should say "Last copied" a few
   minutes past the hour. If it says the last copy was more than two hours ago, the cron job isn't
   running.

To test from a terminal (cPanel → **Terminal**): `php ~/public_html/cron_sheet_backup.php --dry-run`
reads the sheet and says what it would write, without writing anything.

### 8. Know when Anima is down (recommended, not part of Anima)

The sheet only helps if you know to open it. A free uptime monitor checks the site every
5 minutes and emails you when it stops answering. Anima can't do this itself, because when it's down
it can't send anything.

1. Make a free account at **uptimerobot.com**.
2. **+ New monitor** → type **HTTP(s)** → URL `https://anima-mana.com/login.php` → interval
   5 minutes → alert contact: your email (add Tim's too if you like).
3. Save. It emails you when the site goes down and again when it is back.

---

## How the team uses it during an outage

1. **Anima won't open.** Someone posts the backup sheet's link in the team chat. Bookmark it now so
   it's handy on the day.
2. **Find your show's tab** along the bottom. Row 2 says when Anima last copied it. That's how
   current it is: up to an hour old.
3. **Change only the two yellow columns, Status and Assignee**, using their dropdowns. Leave Status
   empty for "Not started". Sorting and filtering are fine: Anima finds each row by the **Anima ID**
   in column A, so never edit or delete that column.
4. **Grey columns are read-only.** Google warns if you type in one, and anything typed there is
   ignored. A new shot, a due date, a note or a new version waits until Anima is back.
5. **Nothing is lost when Anima comes back.** The hourly copy notices the tab has changes and
   leaves it alone. Row 2 then says "Not refreshed since …: N changes made here are waiting for
   Production to review".

## When Anima is back (Production)

1. Open the menu → **Sheet backup**. The menu shows a count when shows have changes waiting.
2. Click **Review changes** on a show. Each change made in the sheet is listed, e.g.
   *SH040 · Comp · Status: In progress → Pending review*, with what Anima says now.
3. **Both changed** means someone also changed that task in Anima since the copy, so applying the
   sheet would replace it. These start unticked. Look at both and decide.
4. A change Anima can't use (a status name the show doesn't have, a person it doesn't know) is
   shown with the reason and can't be ticked. Set it on Spell by hand if it matters.
5. Tick what to apply and click **Apply ticked changes**. Each one is saved exactly as if it were
   set on Spell: it appears in the task's history, and the people following it get the usual bell
   (and their next email summary).
6. Click **Finish review and refresh the tab**. The show's tab is rewritten from Anima and the
   hourly copy carries on. **Any change still in the sheet that you didn't apply is overwritten**,
   and the screen asks first.

---

## What it does not do (on purpose)

- **No automatic copying back.** A sort that leaves a column behind, a fill-down or a typo would
  silently rewrite real statuses. Edits made in both places would overwrite each other unseen. And
  when Anima is down its cron is down too. So copying back is always a person's decision.
- **Only Status and Assignee come back.** Everything else in the sheet is for reading.
- **One assignee per task:** the main one, as Spell's Assignee column shows. A second person on a
  task (set in the task panel) isn't in the sheet.
- **Shows that are wrapped or archived** aren't copied, the same as Spell. Their old tabs stay in the
  sheet until someone deletes them.
- **No new rows.** A row added in the sheet is ignored.

## For a developer

- `includes/google_sheets.php`: signs in as the service account (a JWT signed RS256 with openssl,
  traded for an access token) and calls the Sheets API with plain cURL. There's no Google library,
  because the project has no Composer.
- `includes/sheet_backup.php`: builds rows from `board_data()` (what Spell draws), writes the tabs,
  keeps the snapshot (`sheet_backup_tabs`, migration 105), and does the comparison and apply.
- `includes/board_write.php`: `board_set_status()` / `board_set_crew()`, Spell's own save, shared
  by `board_api.php` and the review screen.
- `cron_sheet_backup.php`: the hourly entry point (CLI, or `?key=` with `cron_key`).
- `sheet_backup.php`: the screen (Production, `can_edit()`).
