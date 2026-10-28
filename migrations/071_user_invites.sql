-- 071 — one-time "set your password" links, and the log of who sent them.
--
-- David, 2026-10-28: "We need to have a quick way to create the slots profiles
-- from the staffing section, to actual users with user name and password ... a
-- 'Create user' from the detailed view in case that hire doesn't have an account
-- created and a 'Resend link' for old users that need to log in again to anima
-- for a new project."
--
-- One row per link sent, from the person page (person.php, includes/invites.php).
-- The link itself is never stored: token_hash is its sha256, so a copy of this
-- table is not a pile of working links. A link is good ONCE (used_at), until
-- expires_at (7 days), and only while it is the latest one for that login
-- (replaced_at is stamped on the older ones when a new link is sent).
--
-- The row is also the record of who did it: created_by and created_at, plus
-- whether the send switched a deactivated login back on.
--
-- Times are written by PHP, not DEFAULT CURRENT_TIMESTAMP, for the same reason
-- as the kick-off tables (migration 049).

CREATE TABLE IF NOT EXISTS user_invites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id            INT NOT NULL,                  -- the login the link is for
  token_hash         CHAR(64) NOT NULL,             -- sha256 of the link's token
  kind               ENUM('create','resend') NOT NULL DEFAULT 'resend',
  project_id         INT DEFAULT NULL,              -- the show it was sent for, named in the email
  sent_to            VARCHAR(255) DEFAULT NULL,     -- the address it was emailed to
  email_sent         TINYINT(1) NOT NULL DEFAULT 0, -- 0 when the mail failed and the link was copied by hand
  reactivated        TINYINT(1) NOT NULL DEFAULT 0, -- the send switched a deactivated login back on
  created_by         VARCHAR(100) DEFAULT NULL,     -- display name of who sent it
  created_by_user_id INT DEFAULT NULL,
  created_at         DATETIME NOT NULL,
  expires_at         DATETIME NOT NULL,
  used_at            DATETIME DEFAULT NULL,
  replaced_at        DATETIME DEFAULT NULL,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
