-- 102 - Which notifications were opened.
--
-- Tim, 2026-11-17, with a bell full of Renata's moves and mentions: "is it
-- possible to have the notifications (once clicked on) get cleared from the
-- list?" David: "yeah, I'll add a collapsible notification for past/read
-- notifications".
--
-- `seen_at` already exists and means "the bell was opened while this was in it",
-- which clears the red count; it says nothing about which row was actually
-- clicked. `opened_at` is that: set when the person clicks the row
-- (notify_open.php). The bell then lists what has not been opened yet, and folds
-- everything opened under "Earlier".
--
-- Until this runs the bell works exactly as before: every row in one list.
-- One statement, so a run that fails adds nothing and can simply be run again.

ALTER TABLE notifications
  ADD COLUMN opened_at DATETIME DEFAULT NULL;
