-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema.
--
-- Import this into a SEPARATE MySQL database, with a SEPARATE MySQL user.
-- Not into the production database, and never through migrate.php — that runner
-- only ever touches the production connection.
--
-- Setup, once (cPanel → MySQL Databases):
--   1. Create a database, e.g. davideei_manafinance
--   2. Create a user, e.g. davideei_finance, with a DIFFERENT password
--   3. Add that user to that database with ALL PRIVILEGES
--   4. Do NOT give the production user (davideei_PM) any rights here, and do NOT
--      give this user any rights on the production database. That mutual
--      ignorance is the entire point.
--   5. Import this file into the new database (phpMyAdmin → Import)
--   6. Add the `finance_db` block to config.php — see config.example.php
--
-- WHY A SEPARATE DATABASE AT ALL, when the same app reads both:
-- The production connection has no rights to these tables. So a bug in any
-- ordinary query on any ordinary screen — a bad WHERE, an injection, a join
-- somebody wrote at midnight — cannot reach a rate, because the connection it
-- runs on is not permitted to. Only code that deliberately opens the second
-- connection can, and there is exactly one such place: includes/money.php,
-- behind an is_admin() check.
--
-- This is weaker than the original 2026-08-12 design, which gave the production
-- app no finance credential whatsoever. It is stronger than a column on `crew`
-- guarded by an if. That trade was made knowingly on 2026-08-15 — see
-- docs/DECISIONS.md.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- Pay terms, one row per person.
--
-- crew_id points at `crew.id` in the PRODUCTION database. There is deliberately
-- NO foreign key: the tables are in different databases with different users, so
-- the constraint cannot exist and pretending otherwise would be a comment that
-- lies. What makes the link safe is the rule from 2026-08-12 — **crew ids must
-- stay stable and must never be reused**. A recycled id would silently attach
-- one person's hours to another's rate.
--
-- crew_name is stored alongside, and is not redundant. It is what makes a row
-- readable if somebody opens this database directly, and what stops an orphaned
-- row (person deleted in production) from being an anonymous number. Same
-- reasoning as progress.assignee_raw and the estimate baseline copying step names.
CREATE TABLE IF NOT EXISTS crew_terms (
  crew_id INT NOT NULL PRIMARY KEY,
  crew_name VARCHAR(100) DEFAULT NULL,
  day_rate DECIMAL(10,2) DEFAULT NULL,
  rate_unit ENUM('day','hour','flat') NOT NULL DEFAULT 'day',
  rate_currency CHAR(3) NOT NULL DEFAULT 'USD',
  contract_url VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An audit trail, because this is the one kind of data where "who changed that,
-- and when" is a question that actually gets asked. Append-only; nothing here is
-- ever updated. Cheap now, impossible to reconstruct later — the same reasoning
-- as the daily snapshots and the estimate baseline.
CREATE TABLE IF NOT EXISTS crew_terms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  day_rate DECIMAL(10,2) DEFAULT NULL,
  rate_unit VARCHAR(10) DEFAULT NULL,
  rate_currency CHAR(3) DEFAULT NULL,
  contract_url VARCHAR(500) DEFAULT NULL,
  changed_by VARCHAR(100) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_crew (crew_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
