-- 049 — the kick-off sign-off: setup becomes active only once the named people
-- have approved, and what they approved is kept.
--
-- David, 2026-10-16: "when a project goes from setup to active ... fire an email
-- to Tim and Jorik with the heads up to take a look at the setup and a way for
-- them to approve (sign off) ... The project needs to store all that initial
-- data ... Once they both agree to change status it will change to active and
-- all that data will be stored. Once the project moves to Postmortem, we need a
-- new screen where we can see how all that info started and how it ended."
--
-- THREE PIECES:
--
--   users.email, users.signs_off_kickoff
--       WHO signs off is a tick on an account, not a name in the code, so a new
--       COO is a tick rather than a deploy. The email is on the login because a
--       login is what approves; a linked crew member's email is the fallback.
--
--   project_kickoffs
--       One row per time a project was SENT for sign-off. The snapshot is taken
--       at that moment, so what is approved is exactly what was sent, and a later
--       edit to the live setup cannot change it. Re-sending withdraws the old row
--       and writes a new one; nothing is overwritten.
--
--   project_kickoff_signoffs
--       One row per person asked, created when it is sent. Who was asked is
--       chosen in a pop-up when sending (pre-ticked from users.signs_off_kickoff)
--       and is part of the record, so "Tim approved, Jorik never answered" stays
--       readable.
--
-- While a request is pending the project is FROZEN: saves on its setup screens
-- are refused (KICKOFF_FROZEN_SCRIPTS in includes/auth.php). Asking for changes
-- unfreezes it; sending again takes a new snapshot, which becomes the kick-off.
--
-- ⚠ THE MONEY IS NOT IN HERE. The expense projection, invoices and budget pool
-- at kick-off go to the FINANCE database (finance_install_08.sql), for the same
-- reason rates live there: this database's user cannot read that one.
--
-- Times are written by PHP, not by DEFAULT CURRENT_TIMESTAMP, because the
-- server's MySQL clock and the app's timezone are not the same (2026-10-16).

ALTER TABLE users
  ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER display_name;

ALTER TABLE users
  ADD COLUMN signs_off_kickoff TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS project_kickoffs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  state ENUM('pending','approved','changes_requested','withdrawn') NOT NULL DEFAULT 'pending',
  requested_by INT DEFAULT NULL,
  requested_by_name VARCHAR(100) DEFAULT NULL,
  requested_at DATETIME NOT NULL,
  decided_at DATETIME DEFAULT NULL,
  -- Everything but the money, as JSON: setup, brief, staffing, schedule,
  -- timeline, shots. Read back by includes/kickoff.php and nothing else.
  snapshot LONGTEXT NOT NULL,
  KEY idx_project (project_id, state),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_kickoff_signoffs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kickoff_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  signer_name VARCHAR(100) NOT NULL,
  signer_email VARCHAR(255) DEFAULT NULL,
  decision ENUM('approved','changes') DEFAULT NULL,
  note TEXT DEFAULT NULL,
  decided_at DATETIME DEFAULT NULL,
  -- Set when an administrator approved in this person's place (David,
  -- 2026-10-16: possible, but everybody is emailed that it happened).
  approved_by_override VARCHAR(100) DEFAULT NULL,
  -- What happened to the email, in a sentence, so "did they even get it?" has
  -- an answer on screen.
  email_result VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uniq_signer (kickoff_id, user_id),
  FOREIGN KEY (kickoff_id) REFERENCES project_kickoffs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
