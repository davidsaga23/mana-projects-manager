-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema, part 2: budgets and invoices.
--
-- Import into the SAME separate finance database as finance_install.sql, with
-- the same separate MySQL user. Not into the production database, and never
-- through migrate.php — that runner only touches the production connection.
--
-- This completes four of the ten steps in David's production cycle (ROADMAP):
-- budget, project expense, invoices, budget reports. They are one chain rather
-- than four features — a budget is what you were paid, the expense projection is
-- what it will cost, invoices are what has actually been billed, and the report
-- is the three side by side.
--
-- WHAT IS **NOT** HERE, deliberately: the expense projection itself.
-- It is COMPUTED, every time, from production data — pipeline steps, person-days,
-- headcount and lane assignments — multiplied by the rates in crew_terms. Storing
-- it would create a second number that drifts the moment somebody changes a
-- headcount, and the whole reason production and finance are joined on stable ids
-- is so the money side can read the current truth rather than a copy of an old one.
-- The only thing stored is what cannot be derived: what the client agreed to pay,
-- and what has actually been invoiced.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- What the client agreed to pay for a project.
--
-- project_id points at `projects.id` in the PRODUCTION database. No foreign key
-- — different database, different user, so the constraint cannot exist and
-- claiming otherwise would be a comment that lies. The link is safe because ids
-- are stable and never reused (DECISIONS, 2026-08-12). project_code and
-- project_name are copied for the same reason crew_name is on crew_terms: a row
-- opened directly in this database has to be readable on its own.
CREATE TABLE IF NOT EXISTS project_budgets (
  project_id INT NOT NULL PRIMARY KEY,
  project_code VARCHAR(20) DEFAULT NULL,
  project_name VARCHAR(255) DEFAULT NULL,

  -- DECIMAL, never FLOAT. Binary floating point cannot represent 0.1 exactly,
  -- and these get summed against real invoices.
  budget_amount DECIMAL(12,2) DEFAULT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',

  -- What the client is billed against — a fixed fee, a day rate, a retainer.
  -- Free text: every deal is shaped differently and an ENUM here would need a
  -- schema change on the first unusual one.
  terms VARCHAR(200) DEFAULT NULL,
  notes TEXT DEFAULT NULL,

  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Artist invoices. One row per invoice, not per person — somebody bills monthly
-- and each bill is its own fact with its own dates.
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  project_code VARCHAR(20) DEFAULT NULL,

  -- Points at production `crew.id`. Copied name for the same reason as above:
  -- an invoice must stay readable after somebody leaves the crew list.
  crew_id INT DEFAULT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,

  -- The artist's own reference, as printed on their document. Not generated
  -- here: it is their number, and inventing a second one guarantees the two
  -- disagree when somebody quotes one over email.
  invoice_ref VARCHAR(60) DEFAULT NULL,

  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',

  -- What period the work covers, which is often not when it was billed. Both
  -- nullable: a flat-fee invoice covers no particular week.
  period_start DATE DEFAULT NULL,
  period_end DATE DEFAULT NULL,

  issued_date DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  -- NULL means unpaid. A date is the only honest record of payment: a boolean
  -- would answer "is it paid" and never "how late were we".
  paid_date DATE DEFAULT NULL,

  status ENUM('received','approved','paid','disputed') NOT NULL DEFAULT 'received',

  -- The document itself stays in Drive, under Drive's own permissions — the one
  -- part of the original separate-app guarantee that survives intact.
  document_url VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,

  KEY idx_project (project_id, issued_date),
  KEY idx_crew (crew_id),
  KEY idx_unpaid (paid_date, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
