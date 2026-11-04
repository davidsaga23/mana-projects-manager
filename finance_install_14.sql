-- finance_install_14 — a position can be marked FINISHED on the Expense projection.
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
-- Until it is imported the Done column shows, but its ticks say this file is
-- needed and nothing is saved.
--
-- David, 2026-11-04, on the Expense projection: "add another column with a check
-- mark to state if the artist or account has already finished worked and so we
-- can say the difference between actual vs projected is final".
--
-- One row = that position is finished. Unticking deletes the row; the log keeps
-- who ticked or unticked it and when. step_id follows the invoices convention
-- (DECISIONS 2026-10-26): a pipeline step's id, or MINUS a supervision
-- position's id. No foreign keys: different database.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS position_done (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  position_name VARCHAR(150) DEFAULT NULL,
  done_by VARCHAR(100) DEFAULT NULL,
  done_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_position (crew_id, project_id, step_id),
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS position_done_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  position_name VARCHAR(150) DEFAULT NULL,
  done TINYINT(1) NOT NULL,
  changed_by VARCHAR(100) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_position (crew_id, project_id, step_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
