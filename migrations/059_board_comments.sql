-- 059 — the Board's tasks join the conversation that already exists.
--
-- David, 2026-10-22, on the Comments tab asked for in the Board's task panel:
-- "those comments are the ones that currently live under my tasks, they're the
-- exact same just displayed in this context too" — and, a moment later,
-- "remember to have the option to create threads".
--
-- ⚠ SO THERE IS NO SECOND COMMENT TABLE, and that is the whole of this
-- migration. A board task was about to get `progress_comments`, its own
-- mentions, its own threading and its own mailer — four copies of code that
-- already works, and two places to look for what somebody said about SH160.
-- One store means a mention is a mention wherever it was typed, My tasks lists
-- them all, and migration 039's threads and 040's seen_at arrive already built.
--
-- --- THE TWO ANCHORS --------------------------------------------------------
--
-- A comment now hangs off EITHER a ticket OR a progress row — exactly one, which
-- PHP enforces (board_comment_add() sets one and never the other; ticket.php
-- goes on setting ticket_id as it always has). It is not expressed as a CHECK
-- here because this schema still has to install on MySQL 5.7, where a CHECK is
-- parsed and then ignored — a constraint that silently does nothing is worse
-- than one written down in a comment.
--
-- `progress` is the right anchor and `tickets` is not. A board task IS a
-- progress row (one per step per item, UNIQUE KEY uniq_step_item), which is
-- what the board has drawn from since the day it was written. Hanging the
-- conversation on a ticket instead would mean INVENTING a ticket the first time
-- anybody typed a comment — a card that would then appear on the ticket kanban
-- and a second time in the assignee's My tasks, beside the production task it
-- duplicates. Nobody asked for a card; they asked to say something about a shot.
--
-- ON DELETE CASCADE, like ticket_id: deleting the row the conversation is about
-- takes the conversation. Shots and assets are not deleted in normal use, and a
-- comment pointing at nothing is not worth the orphan.
--
-- --- WHY ticket_id BECOMES NULLABLE -----------------------------------------
--
-- ⚠ THIS IS THE RISKY HALF, and migration 041 is the warning. When `tickets`
-- learned to have no project, every INNER JOIN to `projects` quietly started
-- dropping rows that existed — a mention recorded on screen, emailed, and then
-- missing from the list the email pointed at. The same trap is here: anything
-- that INNER JOINs `tickets` through ticket_comments now drops every board
-- comment. There is exactly one such query in the codebase, the mention list in
-- mytasks.php, and it is LEFT JOINed in the same commit as this file. Any new
-- one has to do the same.
--
-- Each statement is safe to run twice, so a failed run can simply be run again.

ALTER TABLE ticket_comments
  MODIFY COLUMN ticket_id INT DEFAULT NULL,
  ADD COLUMN progress_id INT DEFAULT NULL AFTER ticket_id,
  ADD KEY idx_progress (progress_id, created_at),
  ADD FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE;
