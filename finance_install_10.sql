-- finance_install_10 — the history of a budget changed after kick-off sign-off.
--
-- FINANCE DATABASE. Import by hand in phpMyAdmin, into the finance database,
-- once — never through migrate.php, which only knows the production database.
--
-- David, 2026-10-28: "Only Jorik can raise a budget. I can make the budget
-- static after the project sign off but he'll need to change it manually."
-- Tim and Jorik, same day: a change after sign-off saves "the old amount, new
-- amount, who, when and a short reason".
--
-- Once a show's kick-off is approved, its budget box on the finance Overview is
-- read-only except for the people ticked "Changes budget" (users.changes_budget,
-- migration 070). Every save they make writes one row here, and the rows are
-- listed under the budget box. Nothing is ever updated or deleted: the table is
-- the record of how the approved figure moved.
--
-- Until this is imported, a signed-off budget cannot be changed by anybody:
-- a change that cannot be recorded is refused rather than made silently.
--
-- Names are copied, not joined: this database cannot read the production
-- users table, and a renamed or deleted account must not rewrite the record.
-- Times are written by PHP, not DEFAULT CURRENT_TIMESTAMP, because the server's
-- MySQL clock and the app's timezone are not the same (2026-10-16).

CREATE TABLE IF NOT EXISTS budget_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  -- The approved kick-off the budget was locked by, for reading the two together.
  kickoff_id INT DEFAULT NULL,
  old_amount DECIMAL(12,2) DEFAULT NULL,
  new_amount DECIMAL(12,2) DEFAULT NULL,
  old_currency CHAR(3) DEFAULT NULL,
  new_currency CHAR(3) DEFAULT NULL,
  -- Which of terms / notes changed too, in words; null when only the amount did.
  also_changed VARCHAR(100) DEFAULT NULL,
  reason VARCHAR(500) NOT NULL,
  changed_by_user_id INT DEFAULT NULL,
  changed_by VARCHAR(100) NOT NULL,
  changed_at DATETIME NOT NULL,
  KEY idx_project (project_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
