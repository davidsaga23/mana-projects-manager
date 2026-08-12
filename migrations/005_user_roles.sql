-- Logins gain a role, so people can be let in without being able to change things.
--
-- STFC deliberately had no read-only accounts — everyone who could log in could
-- edit everything. That was fine for three people who all ran the show; it stops
-- being fine the moment an assistant, a client or a new coordinator needs to see
-- the numbers without being able to move a deadline.
--
--   admin   — everything, including managing these accounts
--   editor  — all production work; cannot add or change users
--   viewer  — read-only, enforced centrally in includes/auth.php
--
-- NOTE: this is about the PRODUCTION platform only. Money, contracts and rates
-- live in a separate application with its own logins and its own database —
-- see docs/DECISIONS.md, 2026-08-12. No role here grants access to any of that.

ALTER TABLE users
  ADD COLUMN role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'editor' AFTER display_name;

ALTER TABLE users
  ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

-- Useful to an admin deciding whether an account is still in use.
ALTER TABLE users
  ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL;

-- Everyone who could already log in was, in effect, an admin. Anything else
-- would lock the only existing account out of user management.
UPDATE users SET role = 'admin';
