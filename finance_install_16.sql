-- finance_install_16 — budget requests: asking Jorik to change a signed-off budget.
--
-- FINANCE DATABASE. Import by hand in phpMyAdmin, into the finance database,
-- once — never through migrate.php, which only knows the production database.
--
-- David, 2026-11-06: "Kick-offs have versions: v1, v2, v3. A new version is
-- made when Jorik approves a budget request, or when the project is sent for
-- sign-off again after a re-plan." He chose a request step: an administrator
-- proposes a new amount and a reason, the people ticked "Changes budget" are
-- emailed, and approving changes the budget (recorded in budget_changes, from
-- finance_install_10) and saves the next kick-off version.
--
-- Until this is imported, the request form on the finance Overview says so;
-- nothing else changes, and Jorik can still change the budget directly.
--
-- Rows are never deleted: a request ends approved, declined or withdrawn.
-- Names are copied, not joined: this database cannot read the production users
-- table. Times are written by PHP (the MySQL clock is not the app's timezone).

CREATE TABLE IF NOT EXISTS budget_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  -- The approved kick-off version the request was made against.
  kickoff_id INT DEFAULT NULL,
  old_amount DECIMAL(12,2) DEFAULT NULL,
  old_currency CHAR(3) DEFAULT NULL,
  new_amount DECIMAL(12,2) NOT NULL,
  new_currency CHAR(3) NOT NULL DEFAULT 'USD',
  reason VARCHAR(500) NOT NULL,
  state ENUM('pending','approved','declined','withdrawn') NOT NULL DEFAULT 'pending',
  requested_by_user_id INT DEFAULT NULL,
  requested_by VARCHAR(100) NOT NULL,
  requested_at DATETIME NOT NULL,
  decided_by_user_id INT DEFAULT NULL,
  decided_by VARCHAR(100) DEFAULT NULL,
  decided_at DATETIME DEFAULT NULL,
  decision_note VARCHAR(500) DEFAULT NULL,
  KEY idx_project (project_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
