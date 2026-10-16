-- finance_install_08 — the money side of a project's kick-off sign-off.
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
--
-- David, 2026-10-16: at sign-off the project stores its finance management as
-- it stood (expense projection, invoices, budget pool), so the postmortem can
-- compare it with how it ended. Migration 049 keeps the rest of the snapshot in
-- the production database; this table keeps the money here, where the
-- production database's user cannot read it.
--
-- One row per sign-off request, keyed by project_kickoffs.id in the other
-- database. No foreign key: MySQL cannot point one across databases, and the
-- two are reached through different users on purpose.

CREATE TABLE IF NOT EXISTS kickoff_finance_snapshots (
  kickoff_id INT NOT NULL PRIMARY KEY,
  project_id INT NOT NULL,
  project_code VARCHAR(20) DEFAULT NULL,
  snapshot LONGTEXT NOT NULL,
  captured_at DATETIME NOT NULL,
  captured_by VARCHAR(100) DEFAULT NULL,
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
