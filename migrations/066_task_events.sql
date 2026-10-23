-- 066 — the changes to a task that nothing recorded until now.
--
-- David, 2026-10-22: Activity becomes the task's TIMELINE, "most recent at the
-- top ... new versions, status changes, reassignments, review notes, notes,
-- etc". Statuses are already logged (progress_log, migration 021), versions and
-- comments carry their own dates (063, 059). What had no history at all was the
-- rest of the row: who it was handed to, when it is due, its note and its link.
-- A board edit overwrote them and the old value was gone.
--
-- So one small append-only table, written by board_api.php beside each of those
-- saves, with the value before and after. Nothing is reconstructed for the past:
-- the timeline starts showing these from the day this runs, and says so.
--
-- `kind` is one of assignee, due, notes, link. Values are the words a person
-- would read ("Felipe Gand", "2026-10-30"), stored rather than re-derived, for
-- the reason progress_log gives: a record of a moment must not change when a
-- person is renamed next month.
CREATE TABLE IF NOT EXISTS progress_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  progress_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  old_value VARCHAR(2000) DEFAULT NULL,
  new_value VARCHAR(2000) DEFAULT NULL,
  actor VARCHAR(120) DEFAULT NULL,
  user_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_progress_events (progress_id, created_at),
  FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
