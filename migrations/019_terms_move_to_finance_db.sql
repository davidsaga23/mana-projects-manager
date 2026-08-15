-- Phase 9, corrected — pay terms move OUT of this database.
--
-- Migration 018 put day_rate / rate_unit / rate_currency / contract_url on
-- `crew`, reversing the 2026-08-12 "money lives elsewhere" decision. Later the
-- same day David chose the middle path instead: money in its own database with
-- its own MySQL user, read through one admin-gated door. See the 2026-08-15
-- entries in docs/DECISIONS.md.
--
-- These columns therefore have to GO, not merely stop being used. The whole
-- value of the separate database is that the production MySQL user has no rights
-- to pay data — and a column sitting in `crew` is a column this app's ordinary
-- connection can read. Leaving them "unused but harmless" would keep every risk
-- and deliver none of the benefit, and would leave a trap for whoever finds
-- `crew.day_rate` in six months and reasonably assumes it is the right place.
--
-- ⚠ THIS IS A DELIBERATE EXCEPTION TO THE ADDITIVE-ONLY RULE in
-- migrations/README.md, and the only one in the project. It is safe here for a
-- specific, checkable reason rather than as a matter of taste:
--
--   The ONLY code that could ever write these columns was save_crew_terms(),
--   which lives on person.php. person.php returned a fatal error on every single
--   request from the moment it was deployed until after this decision was made —
--   a duplicate fmt_rate() (START_HERE lesson 13). No request ever reached the
--   form. The columns cannot contain anything.
--
-- If you are running this against an install where that was NOT true, check
-- first: SELECT id, name, day_rate, contract_url FROM crew WHERE day_rate IS NOT
-- NULL OR contract_url IS NOT NULL;  Copy anything it returns into the finance
-- database before applying this.
--
-- `crew.department` STAYS. It is not financial, it belongs with the person, and
-- the team screens read it.

ALTER TABLE crew
  DROP COLUMN day_rate;

ALTER TABLE crew
  DROP COLUMN rate_unit;

ALTER TABLE crew
  DROP COLUMN rate_currency;

ALTER TABLE crew
  DROP COLUMN contract_url;
