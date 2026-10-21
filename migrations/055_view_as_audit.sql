-- "View Anima as a specific person" — the audit trail (feature 2).
--
-- David, 2026-10-21: "can we admins see anima as specific users instead of
-- generic ones? that will help us see their assigned tasks and what's available
-- to them specifically." The app already let an admin preview a lesser ROLE
-- (preview.php); this adds previewing as a named PERSON, which borrows that
-- user's role AND their crew link for the session so My tasks and the (newly
-- scoped) projects list show exactly what that person sees.
--
-- The preview is READ-ONLY, same as the role preview — require_login()'s guard
-- refuses every POST while in_preview() is true, and this feature changes none
-- of that. But looking through someone else's account, even read-only, is worth
-- a record: this table holds one row each time an admin starts a person-preview,
-- so "who looked as whom, and when" stays answerable.
--
-- Nothing in the app READS this table; it is written by preview.php and read by
-- a human if ever a question is raised. Deliberately minimal — no end time, no
-- page trail — because the point is accountability, not surveillance.
--
-- Times are written by PHP (app timezone), not DEFAULT CURRENT_TIMESTAMP, for
-- the same reason the kick-off tables are (migration 049): the server's MySQL
-- clock and the app's timezone are not the same.

CREATE TABLE IF NOT EXISTS preview_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_user_id  INT NOT NULL,                 -- the administrator who started it
  viewed_user_id INT NOT NULL,                 -- the account they viewed as
  started_at     DATETIME NOT NULL,
  KEY idx_admin (admin_user_id, started_at),
  KEY idx_viewed (viewed_user_id, started_at),
  FOREIGN KEY (admin_user_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (viewed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
