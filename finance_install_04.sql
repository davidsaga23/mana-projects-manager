-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema, part 4: what a candidate quoted, and whether the
-- contract came back signed.
--
-- Import into the SAME separate finance database as finance_install.sql, with
-- the same separate MySQL user. Not into the production database, and NEVER
-- through migrate.php — that runner only ever touches the production
-- connection, and pointing it at this file would put pay data in the database
-- specifically built to have no rights to it.
--
-- ⚠ RUN THIS ONCE. There is no schema_migrations table on this side — these
-- files are imported by hand through phpMyAdmin — so the ALTER statements at
-- the bottom will fail with "duplicate column" on a second run. That failure is
-- harmless and means it was already done.
--
-- Prerequisite: finance_install.sql (crew_terms, crew_terms_log).
--
-- WHY ANY OF THIS IS ON THE FINANCE SIDE
-- David's call, 2026-08-17: the hiring CONVERSATION is planning data and lives
-- in the production database, where any editor can see that a slot has somebody
-- lined up. The NUMBER is pay data and lives here, where an ordinary query
-- cannot reach it. That is the `crew` / `crew_terms` split applied one stage
-- earlier — before somebody is hired rather than after.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- --- what a candidate asked for ---------------------------------------------
--
-- hire_id points at `hires.id` in the PRODUCTION database. No foreign key —
-- different database, different user, so the constraint cannot exist and
-- claiming otherwise would be a comment that lies. The same arrangement, and
-- the same caveat, as crew_terms.crew_id and invoices.project_id.
--
-- ⚠ THE ID STABILITY RULE APPLIES HERE TOO (DECISIONS, 2026-08-12). A recycled
-- hires.id would attach one candidate's quote to another's name. `hires` rows
-- are never deleted by the application for exactly this reason — a candidate
-- who declined is marked declined, not removed.
--
-- candidate_name and project_id are copied so a row opened directly in this
-- database is readable on its own, and so an orphan is not an anonymous number.
-- Same reasoning as crew_terms.crew_name.
CREATE TABLE IF NOT EXISTS hire_quotes (
  hire_id INT NOT NULL PRIMARY KEY,
  candidate_name VARCHAR(120) DEFAULT NULL,
  project_id INT DEFAULT NULL,

  -- DECIMAL, never FLOAT. This is money, it gets compared against a budget, and
  -- binary floating point cannot represent 0.1 exactly — the same reasoning as
  -- crew_terms.day_rate and storing time in whole minutes.
  quoted_rate DECIMAL(10,2) DEFAULT NULL,
  rate_unit ENUM('day','hour','flat') NOT NULL DEFAULT 'day',
  rate_currency CHAR(3) NOT NULL DEFAULT 'USD',

  -- What WE were prepared to pay, when that differs. A negotiation has two
  -- numbers in it and recording only theirs loses the half that says whether
  -- the gap was ever closeable.
  offered_rate DECIMAL(10,2) DEFAULT NULL,

  notes TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,

  KEY idx_hire_quotes_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- has the contract come back? --------------------------------------------
--
-- crew_terms has carried contract_url since finance_install.sql, and a bare
-- link cannot answer the one question anybody asks about contracts, which is
-- "who hasn't signed". A link exists as soon as the document is drafted; it
-- says nothing about whether it went out or came back.
--
-- Deliberately an ENUM and not a free-text field: unlike a rate or a department
-- there is nothing studio-specific about it, and "who hasn't signed" has to be
-- a WHERE clause rather than a judgement about what somebody typed.
--
--   none      no contract yet — the default, and the honest state for most of
--             a project
--   draft     written, not sent
--   sent      out for signature, waiting
--   signed    back and executed
--   declined  they didn't sign, and this is not the same as `none`; it is a
--             hole in the plan somebody has to fill
ALTER TABLE crew_terms
  ADD COLUMN contract_status ENUM('none','draft','sent','signed','declined')
             NOT NULL DEFAULT 'none' AFTER contract_url;

-- Dates rather than a pair of booleans, for the reason invoices.paid_date is a
-- date: a boolean answers "is it signed" and can never answer "how long has
-- that been sitting with them", which is the question that actually chases a
-- contract.
ALTER TABLE crew_terms
  ADD COLUMN contract_sent_date DATE DEFAULT NULL AFTER contract_status;

ALTER TABLE crew_terms
  ADD COLUMN contract_signed_date DATE DEFAULT NULL AFTER contract_sent_date;

-- The log mirrors crew_terms so history covers the contract as well as the
-- rate. Append-only; nothing here is ever updated. Without these three the
-- audit trail would record that somebody changed the terms and quietly lose
-- what they changed them to.
ALTER TABLE crew_terms_log
  ADD COLUMN contract_status VARCHAR(10) DEFAULT NULL AFTER contract_url;

ALTER TABLE crew_terms_log
  ADD COLUMN contract_sent_date DATE DEFAULT NULL AFTER contract_status;

ALTER TABLE crew_terms_log
  ADD COLUMN contract_signed_date DATE DEFAULT NULL AFTER contract_sent_date;

-- "Who hasn't signed" across a whole project, without a table scan once there
-- are a few shows' worth of people on file.
ALTER TABLE crew_terms
  ADD INDEX idx_contract_status (contract_status);
