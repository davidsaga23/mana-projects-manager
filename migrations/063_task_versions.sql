-- 063 — versions on a task, and the review that hangs off them.
--
-- David, 2026-10-22, on the task panel: "Version should not be typed in but got
-- from the number of files uploaded", and "Let's create a Versions and review
-- tab next to activity — people should be able to upload images, open a
-- preview, versions will start at v01 and increment by 1 every time a new
-- version is up".
--
-- --- WHY THIS IS NOT ticket_attachments -------------------------------------
--
-- Migrations 059 and 061 both argued AGAINST a new table, because a board
-- comment really was the conversation that already existed and a file on one
-- really was an attachment on a comment. A version is neither. It is the
-- numbered deliverable of a task — the thing a supervisor reviews, the thing
-- "v03" refers to in a conversation — and it carries an ordinal that no
-- attachment has and that has to be unique per task. Hanging it off a comment
-- would mean a version could be deleted by deleting a sentence.
--
-- What IS shared is the part worth sharing: the uploader. accept_upload() in
-- includes/uploads.php validates and stores every file this app takes, by
-- contents rather than by name, and versions go through it like everything
-- else. The defence is in one place; only the bookkeeping differs.
--
-- --- THE NUMBER -------------------------------------------------------------
--
-- ⚠ version_no IS AN INTEGER, AND "v01" IS A LABEL BUILT FROM IT. Storing the
-- string would mean sorting v10 before v2 and would make "the next one" a
-- parsing problem. The padding is presentation, which is where it belongs.
--
-- UNIQUE (progress_id, version_no) is the whole concurrency story: the next
-- number is read and written inside one transaction, so two people uploading
-- in the same second collide loudly rather than quietly sharing v04. That is
-- the same bargain next_ticket_seq() makes, for the same reason.
--
-- `progress.version` goes on being written, as the label, so the board column
-- and everything already reading it keep working — it is now derived rather
-- than typed, and board_api.php no longer accepts it from a form.
--
-- The FILES live in uploads/tickets/ with everything else: one folder, one
-- deny-all .htaccess, one serving route that checks the login.

CREATE TABLE IF NOT EXISTS progress_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  progress_id INT NOT NULL,
  version_no INT NOT NULL,

  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  file_size INT DEFAULT NULL,

  -- What changed in this one, said by whoever put it up. Optional: most of the
  -- time the file is the message.
  note VARCHAR(500) DEFAULT NULL,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_version (progress_id, version_no),
  UNIQUE KEY uniq_stored (stored_name),
  KEY idx_task (progress_id, version_no),
  FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
