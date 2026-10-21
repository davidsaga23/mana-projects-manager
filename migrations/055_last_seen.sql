-- 055 — "Last seen" becomes a real last-ACTIVITY time, not just last LOGIN.
--
-- David, 2026-10-21: the Users page "Last seen" column showed stale dates (his
-- own read 2026-10-03) even though he, Tim and Renata had all used Anima that
-- day. The column was displaying users.last_login, and a login lasts seven days
-- as a rolling window — so someone who stays signed in refreshes it only on the
-- rare occasion they log in again, never on the pages they actually open.
--
-- The fix needs a second fact the database did not hold: when this login was
-- last ACTIVE, not when it last authenticated. includes/auth.php now stamps this
-- column on each authenticated page view, throttled to at most once every five
-- minutes per session so an active user costs one cheap UPDATE now and then, not
-- one on every request. The Users page reads COALESCE(last_seen, last_login), so
-- it shows the truer value and falls back to the login date until the first
-- activity touch upgrades it.
--
-- One column on users, the login (not crew): the column is shown per account on
-- the Users screen, which lists logins.
--
-- Left NULL for existing rows ON PURPOSE (unlike migration 053). The display
-- COALESCEs to last_login, so nobody reads "never" who has in fact logged in;
-- and the stored value stays honestly empty until real activity writes it, which
-- self-heals the moment each person next opens a page.

ALTER TABLE users
  ADD COLUMN last_seen DATETIME DEFAULT NULL AFTER last_login;
