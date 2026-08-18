-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema, part 5: costs that are not somebody's day rate.
--
-- Import into the SAME separate finance database as finance_install.sql, with
-- the same separate MySQL user. Not into the production database, and NEVER
-- through migrate.php — that runner only touches the production connection.
--
-- ⚠ RUN THIS ONCE. There is no schema_migrations table on this side, so a second
-- import fails with "table already exists". That failure is harmless and means
-- it was already done.
--
-- WHY IT EXISTS. Until now the only cost the platform could express was a person
-- on a pipeline step: the expense projection is COMPUTED from steps, lanes and
-- rates, so nothing without a lane could appear in it at all. Everything else a
-- show actually spends money on — software seats, render or cloud time, a music
-- licence, stock, a hard drive couriered somewhere, a translator, contingency —
-- had nowhere to live.
--
-- The consequence was quiet and bad: **Margin was budget minus projected LABOUR**
-- and was read as headroom. On Return to Club that was 1,720.65 on a 35,000
-- budget — 4.9% — and two or three non-labour lines would have erased it with
-- nothing on screen changing colour. (David, 2026-08-17: "how can I currently add
-- any other expenses that might not be tied to a specific pipeline step task?"
-- The honest answer was: you cannot.)
--
-- WHERE THE LINE FALLS BETWEEN THIS AND `invoices`, because they overlap and
-- somebody will eventually record the same thing twice:
--
--     invoices          a PERSON billing the show. Has a crew member, may name
--                       a position, and is the ACTUAL cost of planned labour.
--     project_expenses  everything that is not a person's time. A vendor, a
--                       licence, a machine, a trip.
--
-- Stated that way the two never compete for the same row, and the screen says
-- it in as many words.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS project_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Points at `projects.id` in the PRODUCTION database. No foreign key —
  -- different database, different user, so the constraint cannot exist and
  -- claiming otherwise would be a comment that lies. Safe because ids are
  -- stable and never reused (DECISIONS, 2026-08-12). project_code is copied so
  -- a row opened directly in this database is readable on its own, the same
  -- reasoning as crew_terms.crew_name.
  project_id INT NOT NULL,
  project_code VARCHAR(20) DEFAULT NULL,

  -- The grouping, and the thing David asked for by name. FREE TEXT with
  -- suggestions built from what every project has already used, exactly as
  -- asset types (2026-08-11), departments (2026-08-14) and hiring positions
  -- (2026-08-17) work. An ENUM here would need a schema change the first time a
  -- show spends money on something nobody anticipated, which is every show.
  --
  -- Deliberately NOT scoped to a project: the vocabulary is global so the
  -- categories a studio actually uses become visible across shows and can be
  -- compared. That is the same reason step_library has no project_id.
  category VARCHAR(60) NOT NULL DEFAULT 'Other',

  description VARCHAR(255) NOT NULL,
  vendor VARCHAR(120) DEFAULT NULL,

  -- DECIMAL, never FLOAT. It is money and it is summed against a budget —
  -- binary floating point cannot represent 0.1 exactly. Same rule as
  -- crew_terms.day_rate, invoices.amount and project_budgets.budget_amount.
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',

  -- planned    budgeted, may not have been ordered yet
  -- committed  ordered or agreed; the money is spoken for
  -- paid       settled
  --
  -- ALL THREE COUNT TOWARD THE PROJECTION, which is the point: a projection is
  -- what the show will cost, not what it has cost so far. The status separates
  -- "what is left to spend" from "what has gone" without needing a second table.
  status ENUM('planned','committed','paid') NOT NULL DEFAULT 'planned',

  -- When the cost lands, which is often not when it was entered. Nullable: a
  -- licence renewal with no date yet is a real budget line.
  incurred_date DATE DEFAULT NULL,
  -- NULL means unpaid. A date rather than a boolean, for the reason
  -- invoices.paid_date is a date: a boolean answers "is it paid" and can never
  -- answer "when did that go out".
  paid_date DATE DEFAULT NULL,

  -- The receipt or quote stays in Drive, under Drive's own permissions.
  document_url VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,

  KEY idx_expense_project (project_id, category),
  KEY idx_expense_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
