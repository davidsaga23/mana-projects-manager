-- 105 - The Google Sheet backup: what Anima last wrote to each show's tab.
--
-- David, 2026-11-19, after a morning he could not open Anima: a spreadsheet
-- copy of Spell, updated hourly, for the studio to work from when Anima is
-- down. cron_sheet_backup.php writes it; sheet_backup.php brings the team's
-- edits back, only when Production ticks them. docs/SHEET_BACKUP.md explains.
--
-- sheet_backup_tabs: one row per show that has been written to the sheet.
--   tab_id       Google's id for the show's tab, so a renamed tab is still found
--   snapshot     JSON of every task's Status and Assignee AS WRITTEN. The next
--                run reads the sheet back and compares: a difference is a person's
--                edit, and that tab is then left alone until it is reviewed, so
--                the hourly copy can never wipe out work done during an outage.
--                The review screen compares the same way, and also against Anima
--                today, to flag a cell changed in both places.
--   paused_at    when the hourly copy first found unreviewed edits and stopped
--                rewriting this tab; NULL while it is being refreshed normally
--   paused_edits how many edits were waiting, for the screen and the menu
--   reviewed_at / reviewed_by  the last finished review
--
-- sheet_backup_runs: one row each time the copy ran, so the screen can say
-- "last copied 10 minutes ago" or "the last run failed, because…", which is
-- how anybody finds out the cron job stopped.
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS sheet_backup_tabs (
  project_id   INT NOT NULL PRIMARY KEY,
  tab_id       BIGINT NULL,
  tab_title    VARCHAR(120) NOT NULL DEFAULT '',
  written_at   DATETIME NULL,
  checked_at   DATETIME NULL,
  snapshot     MEDIUMTEXT NULL,
  paused_at    DATETIME NULL,
  paused_edits INT NOT NULL DEFAULT 0,
  reviewed_at  DATETIME NULL,
  reviewed_by  VARCHAR(100) NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sheet_backup_runs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  via         VARCHAR(10) NOT NULL DEFAULT 'cron',   -- 'cron' or 'screen'
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 0,
  written     INT NOT NULL DEFAULT 0,               -- shows rewritten
  paused      INT NOT NULL DEFAULT 0,               -- shows left alone, edits waiting
  note        VARCHAR(255) NULL,
  KEY idx_sheet_backup_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
