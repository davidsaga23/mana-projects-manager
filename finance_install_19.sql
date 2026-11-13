-- finance_install_19 — a record of every invoice moved from one project to another.
--
-- FINANCE DATABASE. Applied from migrate.php → Finance database updates (or by
-- hand in phpMyAdmin, into the finance database, once).
--
-- David, 2026-11-13: "can we add a new button in the edit to change this
-- invoice to another project?" A freelancer's invoice (or one line of their
-- monthly invoice) is sometimes recorded on the wrong show. Moving it changes
-- the invoice row IN PLACE (project, position), so every page that reads
-- invoices by project reads the new answer with no further change; nothing is
-- deleted and recreated.
--
-- Each move is also written into the invoice's own notes, where it shows on the
-- Invoices tab. This table is the record that survives somebody editing that
-- note: who moved which invoice, when, from which project and position to which,
-- and for how much. It is only ever added to.
--
-- Until this is imported a move still works and is noted on the invoice; the
-- page says the log could not be written.

CREATE TABLE IF NOT EXISTS invoice_moves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  -- The monthly invoice it is a line of (invoice_headers.id), NULL for one
  -- entered on a project's Invoices tab.
  header_id INT DEFAULT NULL,
  crew_id INT DEFAULT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  from_project_id INT NOT NULL,
  from_project_code VARCHAR(20) DEFAULT NULL,
  from_step_id INT DEFAULT NULL,
  from_step_name VARCHAR(100) DEFAULT NULL,
  from_position_crew_id INT DEFAULT NULL,
  to_project_id INT NOT NULL,
  to_project_code VARCHAR(20) DEFAULT NULL,
  to_step_id INT DEFAULT NULL,
  to_step_name VARCHAR(100) DEFAULT NULL,
  to_position_crew_id INT DEFAULT NULL,
  -- The invoice's own amount and currency, which a move never changes.
  amount DECIMAL(12,2) DEFAULT NULL,
  currency CHAR(3) DEFAULT NULL,
  -- Written by PHP: the MySQL clock and the app's timezone differ (2026-10-16).
  moved_at DATETIME DEFAULT NULL,
  moved_by VARCHAR(100) DEFAULT NULL,
  KEY idx_invoice (invoice_id),
  KEY idx_from (from_project_id),
  KEY idx_to (to_project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
