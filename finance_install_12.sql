-- finance_install_12 — a contract per POSITION, not only per person.
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
-- Until it is imported, nothing changes: every position a person holds is
-- priced at their one rate in crew_terms, exactly as before, and the person
-- page says this file is needed before a position can have its own contract.
--
-- David, 2026-10-29, looking at Angelys Brunetti's Home: she holds CH Layout,
-- Cleanup, Color and Lighting on FC Mobile and "should be able to see two
-- different contracts one for CH layout and other for the rest". crew_terms is
-- keyed on crew_id alone, so one person had one rate everywhere.
--
-- THE SHAPE. crew_terms stays what it is: the person's DEFAULT contract, used
-- for every position that has none of its own. A row here is an exception for
-- one position on one show: (crew_id, project_id, step_id). step_id follows the
-- invoices convention (DECISIONS 2026-10-26): a pipeline step's id, or MINUS a
-- supervision position's id. Nothing here is a foreign key — different database,
-- different user — so the id-stability rule on crew.id covers these too.
--
-- position_name is the step's or position's name when it was saved, so this
-- table still reads sensibly in phpMyAdmin after a step is renamed.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS position_terms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  position_name VARCHAR(150) DEFAULT NULL,
  day_rate DECIMAL(10,2) DEFAULT NULL,
  rate_unit ENUM('day','hour','flat','second') NOT NULL DEFAULT 'day',
  rate_currency CHAR(3) NOT NULL DEFAULT 'USD',
  contract_url VARCHAR(500) DEFAULT NULL,
  contract_status VARCHAR(10) DEFAULT NULL,
  contract_sent_date DATE DEFAULT NULL,
  contract_signed_date DATE DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uniq_position (crew_id, project_id, step_id),
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only history, the twin of crew_terms_log: who changed a position's
-- rate or contract, and when. `removed` = 1 marks the position going back to
-- the person's default contract.
CREATE TABLE IF NOT EXISTS position_terms_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  position_name VARCHAR(150) DEFAULT NULL,
  day_rate DECIMAL(10,2) DEFAULT NULL,
  rate_unit VARCHAR(10) DEFAULT NULL,
  rate_currency CHAR(3) DEFAULT NULL,
  contract_url VARCHAR(500) DEFAULT NULL,
  contract_status VARCHAR(10) DEFAULT NULL,
  contract_sent_date DATE DEFAULT NULL,
  contract_signed_date DATE DEFAULT NULL,
  removed TINYINT(1) NOT NULL DEFAULT 0,
  changed_by VARCHAR(100) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_position (crew_id, project_id, step_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
