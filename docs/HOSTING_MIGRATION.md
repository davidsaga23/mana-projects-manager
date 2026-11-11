# Moving Anima to KnownHost (anima-mana.com)

The runbook for moving Anima off the old shared cPanel account (`davideei`, `mana.davidsaga.com`)
to KnownHost at **anima-mana.com**. Only Anima moves; STFC and anything else on the old account
stay where they are. Agreed with David on 2026-11-09. Tick the boxes as you go.

**The rule for the two-week transition:** from the cut-over on, the new site is the only place
anybody works. The old copy stays untouched, its data frozen, as a fallback, and a redirect sends
every old link to the new site. Never run both at once: two databases cannot be merged back.

---

## What Anima needs from the host

- **PHP 8.1+** (8.0 is the floor) with `pdo_mysql`, `mbstring`, `fileinfo` and `openssl`, plus
  `curl` (call-transcript AI, exchange rates) and `zip` (Excel downloads).
- **Limits:** `upload_max_filesize` ≥ 30M, `post_max_size` ≥ 130M (five 25 MB versions at once),
  `max_execution_time` ≥ 90, `memory_limit` ≥ 128M.
- **MySQL 8 / MariaDB 10.6+**, InnoDB, utf8mb4. **Two databases, two users**, neither able to read
  the other's database (the finance separation is deliberate).
- **A web server that honours `.htaccess`** (Apache or LiteSpeed). `includes/`, `migrations/`,
  `uploads/` and `sessions/` are kept private only by those files.
- **HTTPS** (AutoSSL) on anima-mana.com: logins require it.
- **A mailbox to send from** on the same server (e.g. `hi@anima-mana.com`) with SPF and DKIM on.
- **No cron.** Snapshots and archiving run on page load.
- **Deploy:** FTPS, as today. SSH (KnownHost "jail shell", on request) is optional for later.

`preflight.php` checks all of this on the host itself (see step 2).

---

## 1. Before (David)

- [ ] Every open PR merged or parked. Both databases up to date on the old host: `migrate.php`
      shows nothing pending; every `finance_install_NN.sql` imported (up to 16).
- [ ] KnownHost account, **anima-mana.com** added, **AutoSSL** issued.
- [ ] cPanel → MultiPHP Manager: **PHP 8.1+** for the domain. Select PHP Version → extensions above.
      MultiPHP INI Editor: the limits above.
- [ ] cPanel → MySQL Databases: **two databases** (main, finance) and **two users**, each user
      added to **its own database only**, ALL PRIVILEGES. Keep the passwords out of chat.
- [ ] cPanel → Email Accounts: the sending mailbox. Email Deliverability: SPF and DKIM valid.
- [ ] cPanel → FTP Accounts: an FTP account whose home is the site's document root.
- [ ] Note the sizes on the old host (cPanel → Disk Usage): `uploads/` and both databases.
- [ ] Tell the team the cut-over time (they will be logged out and cannot save during it).

## 2. Rehearsal (nothing changes for anybody)

- [ ] Put the code on KnownHost by hand: GitHub → Code → Download ZIP of `master`, then cPanel
      File Manager → Upload → Extract into the site's folder. (Deploys keep going to the old host
      until the secrets change in step 3.)
- [ ] On KnownHost, write `config.php` from `config.example.php`:
      new `db_*` and `finance_db` values, the new SMTP mailbox, `timezone` as before,
      `site_url` => `'https://anima-mana.com'`, a long random `preflight_key`,
      `bug_export_key` as before (or new, then update `tools/.bug_key`).
- [ ] Old host phpMyAdmin → Export both databases (SQL, with structure and data).
      KnownHost phpMyAdmin → Import each into its new database.
- [ ] Copy `uploads/` from the old host to the new one (File Manager → Compress → download →
      upload → Extract). Include the hidden `.htaccess` files.
- [ ] Open `https://anima-mana.com/preflight.php?key=…`. Everything green or explained.
- [ ] Log in. Check: Spell, upload a video version, the review viewer, Reviews, Finance (each tab),
      an Excel download, Bookings, Home. Admin → `smtp_test.php`: send yourself a test.

## 3. Cut-over (about two hours, evening or weekend)

- [ ] Old host `config.php`: `'maintenance' => true`. Every page now says "back shortly".
- [ ] Take the **final** exports of both databases and the final copy of `uploads/` (step 2 again,
      overwriting the rehearsal data on KnownHost).
- [ ] Deploys already go to both hosts (the `deploy-knownhost` job, from 2026-11-11, using the
      `KH_FTP_SERVER`, `KH_FTP_USERNAME`, `KH_FTP_PASSWORD` secrets). At the cut-over, remove the
      old `deploy` job from `.github/workflows/deploy.yml` so only KnownHost gets new code, and
      check the next deploy lands there.
- [ ] `preflight.php` again: all green, **Kept private** included. `migrate.php`: nothing pending.
- [ ] Log in and run the step-2 checks once more on the real data.
- [ ] Old host: upload `tools/hosting/old-host-redirect.htaccess` as `.htaccess` in
      `public_html/mana`. Check that `https://mana.davidsaga.com/spell.php?project=1` lands on the
      same page at anima-mana.com.
- [ ] Tell the team the new address.

## 4. The two weeks after

- [ ] Leave the old host alone: data frozen, redirect on. **Rollback** if something is badly wrong:
      remove the redirect and the maintenance line on the old host, point the GitHub secrets back.
      Anything saved on the new site in between would be lost, so decide early.
- [ ] Set up **backups** on KnownHost: nightly exports of both databases plus `uploads/`
      (there were none on the old host; GitHub only ever held the code).
- [ ] Clear `preflight_key` in config.php.
- [ ] Update the docs: `CLAUDE.md`, `START_HERE.md`, `docs/HANDOFF.md`, `docs/GIT_SETUP.md`
      (new URL, host, FTP). `tools/pull_bugs.py`: default `ANIMA_URL` to `https://anima-mana.com`.
- [ ] After the two weeks: change the redirect to `R=301`, cancel nothing on the old account that
      STFC still needs, and remove Anima's databases and files from it.
