-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema, part 6: paying by the SECOND.
--
-- Import into the SAME separate finance database as finance_install.sql, with
-- the same separate MySQL user. Not into the production database, and NEVER
-- through migrate.php.
--
-- ⚠ RUN THIS ONCE. There is no schema_migrations table on this side, so a second
-- import fails with "duplicate column" or an identical ENUM. Harmless, and it
-- means it was already done.
--
-- WHY. Animation steps measured in footage — rough animation, tie-down, cleanup —
-- are quoted per second of screen time, not per day. The platform already thinks
-- in seconds for those steps: `units` is total seconds and `days_per_unit` is days
-- PER SECOND, so person-days and the even split across lanes already come out
-- right. The only thing missing was a way to say the RATE is per second, which
-- meant those artists were entered as day rates and costed as days x day-rate.
-- (David, 2026-08-17.)
--
-- ⚠ RETAKES DO NOT INFLATE A PER-SECOND COST, and this is the part to understand
-- before changing anything. The standing rule (2026-08-12) is that a retake
-- allowance multiplies DAYS PER ITEM and never the work units: 22 assets are
-- still 22 assets after a revision, they just take longer. Applied here that
-- means the DAYS rise with the allowance and the SECONDS do not — 380 seconds of
-- footage is 380 seconds however many times it is revised — so the cost of a
-- per-second deal stays flat while its duration grows.
--
-- That is not an oversight, it is what buying by the second MEANS: the price is
-- capped and the revision risk sits with the artist. Confirmed by David when the
-- consequence was put to him. If a contract ever bills retakes on top, that is a
-- different deal and wants its own flag rather than a change to this rule.

SET NAMES utf8mb4;

-- The rate a person is paid at. 'second' joins day / hour / flat.
--
-- An ENUM widened rather than replaced: the existing three values keep their
-- meaning and every stored row stays valid. MySQL rewrites the table for this,
-- which on a few dozen crew rows is instant.
ALTER TABLE crew_terms
  MODIFY COLUMN rate_unit ENUM('day','hour','flat','second') NOT NULL DEFAULT 'day';

-- The same on what a candidate quoted, so a per-second quote can be recorded
-- during hiring and carried onto their terms when they are taken on.
ALTER TABLE hire_quotes
  MODIFY COLUMN rate_unit ENUM('day','hour','flat','second') NOT NULL DEFAULT 'day';

-- crew_terms_log.rate_unit is already VARCHAR(10) and needs no change — the
-- history simply starts recording the new word.
