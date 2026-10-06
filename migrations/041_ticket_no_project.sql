-- 041 — a card can exist before anybody has said which show it is for.
--
-- David, 2026-10-07, reading the first real transcript: "we should be able to
-- add them without a project so we can organize them later."
--
-- He is right, and the transcript is the proof: a production meeting covers
-- three shows and a dozen things that belong to none of them yet. Forcing a
-- project at the moment of capture means guessing, and a card filed against the
-- wrong show is harder to find than one filed against nothing.
--
-- ⚠ THE ALTERNATIVE WAS A PSEUDO-PROJECT, and it was rejected. An "Inbox"
-- project would have given these cards statuses, a board, a sequence and a place
-- in the switcher for free — ROADMAP recommended it on that basis. Against it:
-- it would then appear in the projects list, on the dashboard, in finance, in
-- the project switcher and in bookings, as a show with no delivery date and no
-- progress, and every one of those screens would need teaching to hide it. A
-- NULL is honest and needs teaching in far fewer places: the card has no show
-- yet, and says so.
--
-- WHAT A NULL PROJECT MEANS, downstream:
--
--   * NO BOARD. tasks.php is per project and filters on project_id, so an
--     unfiled card simply is not on any board. Studio Tasks is where it lives
--     until it has a home.
--   * NO STATUS. Columns are a project's own statuses, so there is no column to
--     put it in. status_id stays NULL, which ticket_select_sql() has always
--     LEFT JOINed and the ticket page has always tolerated.
--   * NO REFERENCE. `seq` is a per-project counter and means nothing without
--     one; unfiled cards carry 0 and ticket_ref() already falls back to "#id"
--     when there is no project code. uniq_project_seq permits many (NULL, 0)
--     rows because MySQL does not compare NULLs as equal, so nothing collides.
--   * NO SHOT, ASSET OR STEP. All three belong to a project. The ticket page
--     hides those pickers until the card has one.
--
-- Filing it later is move_ticket_to_project(), built on 2026-10-07, which
-- already takes the next number in its new home and maps the status.
--
-- ⚠ THE FOREIGN KEY CHANGES WITH IT. It was ON DELETE CASCADE: deleting a
-- project took its cards, which was right when a card could not exist without
-- one. It is now SET NULL — deleting a show leaves its cards in Studio Tasks
-- rather than destroying them silently. That is a real behaviour change and the
-- safer direction: a card nobody wants is one click to delete, and a card
-- deleted by a cascade nobody saw is gone.
--
-- The constraint has to be dropped by name to be replaced. MySQL names it
-- automatically, so the name is read from information_schema rather than
-- guessed, and the whole thing is skipped when it has already been done.

ALTER TABLE tickets MODIFY project_id INT DEFAULT NULL;

SET @fk = (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
     AND COLUMN_NAME = 'project_id' AND REFERENCED_TABLE_NAME = 'projects'
   LIMIT 1
);
SET @sql = IF(@fk IS NULL, 'SELECT 1',
              CONCAT('ALTER TABLE tickets DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE tickets
  ADD CONSTRAINT fk_tickets_project
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;
