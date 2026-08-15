-- Phase 8c — a log of status changes, so "what moved today" is answerable.
--
-- Ported in purpose from STFC's `shot_activity_log`, which its CSV upload wrote
-- and its dashboard read. This platform had nothing equivalent: `progress` keeps
-- only the CURRENT status and an `updated_at`, so it can say a row changed but
-- never what it changed FROM. `daily_snapshots` records totals per step, which
-- answers "how much" and never "which ones".
--
-- ⚠ HISTORY CANNOT BE BACKFILLED. Every status change before this migration runs
-- is gone — not hidden, gone, because nothing ever recorded the previous value.
-- That is the same asymmetry that made the daily snapshots urgent on 2026-08-13:
-- the screen can be built whenever, the WRITER cannot wait. Which is why this
-- migration ships with its three writers already wired in rather than after them.
--
-- WHY NAMES ARE STORED BESIDE IDS
-- `old_status_name`, `new_status_name` and `crew_name` duplicate what the id
-- points at, deliberately. Statuses get renamed and deleted; people leave. A log
-- entry reading "status 14 → status 17, by crew 9" is worthless the moment any of
-- those rows changes, and a log exists precisely to be read long afterwards. Same
-- reasoning as `progress.assignee_raw`, the estimate baseline copying step names,
-- and `crew_terms.crew_name` in the finance database.

CREATE TABLE IF NOT EXISTS progress_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT DEFAULT NULL,
  step_name VARCHAR(100) DEFAULT NULL,
  -- 's'+shot / 'a'+asset / 'p'+project, the convention progress and schedule use.
  item_key VARCHAR(20) NOT NULL,
  item_name VARCHAR(255) DEFAULT NULL,
  shot_id INT DEFAULT NULL,
  asset_id INT DEFAULT NULL,

  old_status_id INT DEFAULT NULL,
  old_status_name VARCHAR(100) DEFAULT NULL,
  new_status_id INT DEFAULT NULL,
  new_status_name VARCHAR(100) DEFAULT NULL,

  -- What the move MEANT, worked out from the platform's own vocabulary rather
  -- than from the words in a status name:
  --   approved  — the new status counts as approved
  --   delivered — counts as delivered, not yet approved
  --   retake    — went BACKWARDS: was delivered or approved, now is neither.
  --               This is a structural test, not a name match, so it catches a
  --               retake whatever the show happens to call it.
  --   blocked   — moved into a status flagged is_blocked
  --   other     — everything else, including forward movement between
  --               unfinished states
  change_type ENUM('approved','delivered','retake','blocked','other') NOT NULL DEFAULT 'other',

  crew_id INT DEFAULT NULL,
  crew_name VARCHAR(100) DEFAULT NULL,

  -- Which writer made the entry: 'import', 'grid' or 'item'. Worth keeping —
  -- "the importer moved 140 shots" and "somebody moved 140 shots by hand" look
  -- identical in a list otherwise, and mean completely different things.
  source VARCHAR(20) NOT NULL DEFAULT 'import',
  changed_by VARCHAR(100) DEFAULT NULL,

  log_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  KEY idx_project_date (project_id, log_date),
  KEY idx_step (step_id),
  KEY idx_crew_date (crew_id, log_date),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No foreign keys on step_id, crew_id or the status ids, on purpose. A log must
-- survive the thing it describes being deleted — ON DELETE CASCADE would erase
-- exactly the history somebody is looking for, and SET NULL would leave a row
-- that only the copied names make readable. The names are why that is safe.
