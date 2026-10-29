-- finance_install_12 — one freelancer invoice with lines on several projects (Studio board IN4).
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
-- Until it is imported, Finance → Monthly invoices says so and saves nothing;
-- every invoice already recorded keeps working exactly as before.
--
-- Tim and Jorik, 2026-10-28: a freelancer sends ONE invoice per month to
-- accounting@mana.tv, with a line per project and position ("FCM CH Layout
-- 5 days, MSD BG Color 3 days"), and Jorik pays it once. Anima splits it
-- across the projects.
--
-- HOW IT IS SPLIT: THE LINES ARE THE INVOICE ROWS WE ALREADY HAVE. Each line
-- of a monthly invoice is an ordinary `invoices` row on its own project and
-- position, exactly as one entered on that project's Invoices tab, and the new
-- `invoice_headers` row only groups them (invoices.header_id). Every screen
-- that already reads invoices (project totals, actual cost per position, the
-- statements, Final balance, the hub) reads the lines with no change, and every
-- invoice recorded before this has header_id NULL and is simply a one-project
-- invoice, as it always was. Nothing to move by hand.
--
-- The header carries what belongs to the invoice as a whole: the person, the
-- month, their invoice number, the total printed on it, when it arrived, when
-- it was paid and a link to the PDF. Saving the header copies its number,
-- dates, status and link down onto its lines, so the project pages show the
-- same facts; "Mark paid" pays every line at once.
--
-- Room for the invoice intake from accounting@mana.tv (DECISIONS 2026-10-28,
-- not built yet): `source` says where a header came from ('manual' or
-- 'email'), `needs_review` + `review_reason` are the "needs a look" flag for
-- David, and `document_url` is the Drive link.

CREATE TABLE IF NOT EXISTS invoice_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- Points at production `crew.id`; the name is copied so the invoice stays
  -- readable after somebody leaves the crew list (as on `invoices`).
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  -- The month the work was done in, 'YYYY-MM'. One invoice per person per
  -- month is the rule, kept by the screen rather than a unique key, so a
  -- people merge or a rare second invoice can never make an import fail.
  month CHAR(7) NOT NULL,
  -- Their own number, as printed on their document.
  invoice_ref VARCHAR(60) DEFAULT NULL,
  -- The total printed on the invoice. The lines are what counts; this is kept
  -- to compare against them, and is NULL until somebody types it.
  total DECIMAL(12,2) DEFAULT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  -- The same values as invoices.status (expected / pending / paid since
  -- finance_install_09). A word rather than an ENUM so it can never refuse a
  -- value the lines accept.
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  received_date DATE DEFAULT NULL,
  paid_date DATE DEFAULT NULL,
  document_url VARCHAR(500) DEFAULT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  review_reason VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  -- Written by PHP: the MySQL clock and the app's timezone differ (2026-10-16).
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  updated_by VARCHAR(100) DEFAULT NULL,
  KEY idx_person_month (crew_id, month),
  KEY idx_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The lines. All nullable: an invoice entered on a project page has none of
-- them, and that is still a whole invoice.
ALTER TABLE invoices
  ADD COLUMN header_id INT DEFAULT NULL AFTER id,
  -- How many days (or hours) the line is for, in what unit, at what rate.
  ADD COLUMN quantity DECIMAL(10,2) DEFAULT NULL AFTER amount,
  ADD COLUMN quantity_unit VARCHAR(10) DEFAULT NULL AFTER quantity,
  ADD COLUMN unit_rate DECIMAL(12,2) DEFAULT NULL AFTER quantity_unit,
  ADD KEY idx_header (header_id);
