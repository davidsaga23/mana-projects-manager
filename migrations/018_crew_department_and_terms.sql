-- Phase 9 — the person page: department, day rate, contract link.
--
-- ⚠ THIS REVERSES A DELIBERATE DECISION. Read this before using these columns.
--
-- docs/DECISIONS.md, 2026-08-12, "Money lives in a separate application and a
-- separate database", said in terms: "`crew` does not gain a `rate` column." The
-- point was not tidiness. It was that a separate MySQL user, with no rights to
-- the financial tables at all, is a guarantee an application cannot give about
-- itself — a role check inside one app is a single `if` away from failing, and
-- the thing it protects is people's pay.
--
-- David's call, 2026-08-15, asked directly and with that consequence stated: put
-- the rate and the contract here anyway, because the finance application does
-- not exist and one screen showing the whole picture is worth more today than a
-- guarantee about an app nobody has built. That is the owner's decision about
-- his own studio's data, and it is recorded rather than argued with.
--
-- WHAT WE DO INSTEAD, since the strong guarantee is gone:
--   * Every read of these two columns goes through includes/money.php, which
--     refuses for anyone who is not an admin. One choke point rather than a
--     check per screen — the same shape as the read-only enforcement in
--     require_login(), and for the same reason: a check per screen fails the
--     first time somebody forgets one, and fails silently.
--   * `department` is NOT financial and carries no such restriction.
--
-- If the finance app is ever built, these two columns move there and this
-- migration's reasoning is what explains why they were here.

-- --- department -------------------------------------------------------------
-- Which part of the studio a person belongs to. Free text with the same
-- suggested list as pipeline_steps.department (migration 015), so a person and
-- the steps they work on can be compared without a translation table. NOT an
-- ENUM, for the reason every other vocabulary here is free text: adding a
-- department must never need a schema change.
ALTER TABLE crew
  ADD COLUMN department VARCHAR(60) NOT NULL DEFAULT '' AFTER role;

ALTER TABLE crew
  ADD INDEX idx_crew_department (department);

-- --- terms (ADMIN-ONLY — see includes/money.php) -----------------------------
-- DECIMAL, never FLOAT: a rate is money, and binary floating point cannot
-- represent 0.1 exactly. The same reasoning as storing time in whole minutes
-- (2026-08-12) — a column of these gets summed against an invoice.
ALTER TABLE crew
  ADD COLUMN day_rate DECIMAL(10,2) DEFAULT NULL AFTER department;

-- The unit the rate is quoted in. A flat fee for a whole job is not a day rate
-- divided by anything, and treating it as one would silently misprice every
-- projection built on it.
ALTER TABLE crew
  ADD COLUMN rate_unit ENUM('day','hour','flat') NOT NULL DEFAULT 'day' AFTER day_rate;

-- Mana bills US clients and pays several artists abroad, so the currency has to
-- be recorded rather than assumed. Three letters, ISO 4217, defaulting to USD.
ALTER TABLE crew
  ADD COLUMN rate_currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER rate_unit;

-- A link, not a file. The contract lives in Google Drive and Drive enforces its
-- own permissions — which is the one part of the original guarantee that
-- survives intact, because a link is useless to somebody Drive won't let in.
ALTER TABLE crew
  ADD COLUMN contract_url VARCHAR(500) DEFAULT NULL AFTER rate_currency;
