-- 062 — bug reports, filed from the Bug button on every screen.
--
-- David, 2026-10-22: "let's create a bug button for Anima so people can log any
-- bug, let them attach images, videos or gifs, description, section. And from
-- the user you'll get type of user, user reporting the issue, date".
--
-- NOT A TICKET. A ticket belongs to a show and is work on it; a bug belongs to
-- the application, and the person who reads these is whoever is fixing Anima,
-- not a show's Production. Mixing them would put "the Board won't scroll" on
-- somebody's My tasks next to a shot.
--
-- WHO AND WHAT ROLE ARE COPIED, not only joined. role_at_report is the role the
-- reporter had WHEN they filed it, which is what explains a bug ("artists can't
-- see the button") — a later promotion must not rewrite that. reporter_name is
-- kept for the same reason, and so the report still reads after an account is
-- deleted (user_id goes NULL, the name stays).
--
-- page_url is where they were when they pressed the button, filled in by the
-- page, never typed. section is what they CHOSE, which defaults from the page
-- but may differ ("I'm on My tasks but the bug is in the Board's email").
CREATE TABLE IF NOT EXISTS bug_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  reporter_name VARCHAR(150) NOT NULL,
  role_at_report VARCHAR(30) NOT NULL,
  section VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  page_url VARCHAR(500) DEFAULT NULL,
  user_agent VARCHAR(300) DEFAULT NULL,
  status ENUM('open','fixed','wontfix') NOT NULL DEFAULT 'open',
  closed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bug_reports_status (status, created_at),
  KEY idx_bug_reports_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same shape as project_attachments (migration 023), so save_attachments() in
-- includes/uploads.php stores these with the one validated code path.
CREATE TABLE IF NOT EXISTS bug_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bug_id INT NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  file_size INT DEFAULT NULL,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bug_stored (stored_name),
  KEY idx_bug (bug_id),
  FOREIGN KEY (bug_id) REFERENCES bug_reports(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
