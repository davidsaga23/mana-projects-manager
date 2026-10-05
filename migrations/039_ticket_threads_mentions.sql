-- 039 — replies, and naming somebody in a comment.
--
-- David, 2026-10-06: "can we also have the option to tag people in the
-- ticket/task detail view and create threads if needed?"
--
-- --- THREADS ----------------------------------------------------------------
--
-- parent_id makes a comment a reply to another comment. NULL is a top-level
-- comment, which is every comment there has ever been.
--
-- ⚠ ONE LEVEL DEEP, and that is enforced in PHP rather than here: a reply's
-- parent is always a top-level comment, so replying to a reply attaches to the
-- same thread instead of starting a third tier. A ticket is a conversation about
-- one problem, not a forum — nested replies four deep are unreadable in a column
-- this narrow, and the indent alone stops carrying meaning past the first step.
-- The column can express deeper nesting if that is ever wanted; the code
-- deliberately does not.
--
-- ON DELETE CASCADE from the parent: deleting a comment takes its replies, which
-- is the only honest answer. Orphaned replies to a question nobody can read are
-- worse than losing both.
--
-- --- MENTIONS ---------------------------------------------------------------
--
-- Who was named, stored as rows rather than left in the text.
--
-- ⚠ THE TEXT IS NOT THE RECORD. "@Ana Ruiz" in a comment body is a string; a
-- person is a crew id. Parsing the body at read time would mean re-resolving
-- names on every page load, and would quietly lose the link the day somebody is
-- renamed — exactly the alias problem this platform exists to avoid (DECISIONS,
-- 2026-08-11: ids, not names). It also makes "what was I mentioned in?" a query
-- rather than a scan of every comment on every show, which is what the My tasks
-- screen will want the day mentions start notifying anybody.
--
-- It costs nothing now and is expensive later — the same reasoning that put
-- step_id on `tickets` in migration 017 before anything read it.
--
-- Each statement is safe to run twice, so a failed run can simply be run again.

ALTER TABLE ticket_comments
  ADD COLUMN parent_id INT DEFAULT NULL AFTER ticket_id,
  ADD KEY idx_parent (parent_id),
  ADD FOREIGN KEY (parent_id) REFERENCES ticket_comments(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS ticket_mentions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comment_id INT NOT NULL,
  crew_id INT NOT NULL,
  -- The name AS WRITTEN, kept beside the id on purpose: it is what the comment
  -- actually says, and a later rename must not rewrite history. The id is who
  -- it means; this is what it said.
  named_as VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mention (comment_id, crew_id),
  KEY idx_crew (crew_id, created_at),
  FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
