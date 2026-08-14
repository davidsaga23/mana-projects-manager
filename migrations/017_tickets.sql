-- Phase 7 — tickets and tasks.
--
-- Ported from STFC's kanban board (kanban.php / ticket.php / migrate-tickets.sql),
-- which is used daily and whose shape is proven. Four things differ here, and
-- they are the four this codebase always has to check on a port:
-- multi-project, assets alongside shots, crew SLOTS rather than only real
-- people, and the `statuses` vocabulary.
--
-- WHY `tickets` AND NOT `kanban_tasks`
-- "Task" is already taken in this schema: a row in `schedule` is a task, and the
-- scheduler, the timeline and the dashboard all use the word that way. Reusing
-- it for board cards would make every future query ambiguous about which kind it
-- meant. `tickets.type` carries the distinction STFC put in the table name — a
-- plain task ("chase the client for the animatic") versus a ticket (a problem
-- attached to a shot, an asset or a step, with an owner and a thread).
--
-- WHY THE BOARD COLUMNS ARE `statuses` AND NOT A NEW ENUM
-- STFC hardcoded backlog/todo/in_progress/review/done. This project already owns
-- a per-project status vocabulary with `maps_to`, `is_blocked` and `is_excluded`,
-- and it maps onto those five almost exactly (Waiting to start / Ready to start /
-- In progress / Pending review / Completed) while also covering two states the
-- ENUM cannot: On Hold, which already means BLOCKED here rather than idle, and
-- Retake. A second vocabulary would mean a producer holding two mental models for
-- the same idea, and reporting would have to learn both. Same principle as
-- "pipeline workflow is data, not code" — a vocabulary is rows.
--
-- The problem that creates: eleven statuses is eleven columns, which is an
-- unusable board. Hence `on_ticket_board` below — a per-project tick saying "this
-- state is a column", editable on the Statuses screen. Columns stay data.

-- --- which statuses appear as board columns ---------------------------------
-- On BOTH tables: `statuses` is what a project uses, `status_templates` is what a
-- NEW project copies from (includes/statuses.php, seed_project_statuses). A
-- column added to only one of them silently stops applying to the next project
-- created — the same trap migration 010 avoided for is_excluded.
ALTER TABLE status_templates
  ADD COLUMN on_ticket_board TINYINT(1) NOT NULL DEFAULT 0 AFTER is_excluded;

ALTER TABLE statuses
  ADD COLUMN on_ticket_board TINYINT(1) NOT NULL DEFAULT 0 AFTER is_excluded;

-- Six columns, chosen to match the board STFC actually uses, plus On Hold —
-- which earns its place because it is the only column that reads as an
-- escalation rather than a queue. Deliberately NOT ticked: N/A and Retake (real
-- states, but as columns they invite a card to be parked rather than resolved),
-- and the three review variants beyond the first (a board wants one review
-- column, not four). Any of them can be ticked on the Statuses screen.
UPDATE status_templates SET on_ticket_board = 1
  WHERE name IN ('Waiting to start','Ready to start','In progress','On Hold','Pending review','Completed');

UPDATE statuses SET on_ticket_board = 1
  WHERE name IN ('Waiting to start','Ready to start','In progress','On Hold','Pending review','Completed');

-- --- the board cards --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  -- Per-project counter behind the human reference "RTC-12". STFC used the row
  -- id globally (TICKET-042), which on a multi-project install would number one
  -- show's tickets 3, 17, 40 as other shows interleave. The DISPLAYED ref is
  -- built from projects.code at render time and never stored: a project that
  -- gets its code corrected should re-label its tickets, not keep a stale
  -- prefix. Same reasoning as lane names (DECISIONS, 2026-08-12).
  seq INT NOT NULL,
  type ENUM('task','ticket') NOT NULL DEFAULT 'task',
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  -- The project's own vocabulary. NULL only while a status has been deleted out
  -- from under a card; the board shows those in its first column rather than
  -- dropping them, because a card nobody can see is a card nobody fixes.
  status_id INT DEFAULT NULL,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  assigned_crew_id INT DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  -- What the ticket is about. All three nullable and independent: a ticket can
  -- name a shot, an asset, a step, or any combination — "EP02_SH050, Compositing"
  -- is a more useful thing to say than either half alone.
  --
  -- step_id is NOT in STFC and is the one addition to the ported shape. It costs
  -- nothing now and is expensive later (the project_id lesson), it is what lets a
  -- ticket surface on the step detail page built in Phase 6, and it is the hook
  -- a retake ticket would need if this platform ever owns progress directly.
  -- Nothing writes to `progress` from here today — see the note at the bottom.
  shot_id INT DEFAULT NULL,
  asset_id INT DEFAULT NULL,
  step_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- When the card actually reached an approved status. updated_at cannot serve:
  -- it bumps on any edit, so renaming a finished ticket would reset its archive
  -- clock. archived hides a card without deleting anything.
  completed_at DATETIME DEFAULT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_project_seq (project_id, seq),
  KEY idx_board (project_id, archived, status_id),
  KEY idx_assignee (assigned_crew_id, archived, due_date),
  KEY idx_step (step_id),
  KEY idx_shot (shot_id),
  KEY idx_asset (asset_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_crew_id) REFERENCES crew(id) ON DELETE SET NULL,
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE SET NULL,
  FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- the thread -------------------------------------------------------------
-- `kind` is the one addition to STFC's comments table. STFC titles this section
-- "Activity" but only ever shows what people typed, so "who moved this to Done,
-- and when" is nowhere — the single question anyone asks about a card that
-- changed behind their back. Writing status and assignee changes into the SAME
-- thread as a system line costs one column, needs no second table and no second
-- query, and makes the section honestly named.
CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  kind ENUM('user','system') NOT NULL DEFAULT 'user',
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id, created_at),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- attachments ------------------------------------------------------------
-- comment_id NULL = attached to the ticket itself rather than to a reply.
-- The FILES live in uploads/tickets/, which is excluded from git AND from the
-- FTP deploy — see .github/workflows/deploy.yml. Deleting a row here does not
-- delete the file; includes/uploads.php unlinks it first, while the stored name
-- is still readable.
CREATE TABLE IF NOT EXISTS ticket_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  comment_id INT DEFAULT NULL,
  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  file_size INT DEFAULT NULL,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stored (stored_name),
  KEY idx_ticket (ticket_id),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How many days a finished card stays visible before it is archived. Stored per
-- project because project_settings is the key/value store this schema has, and
-- because it is genuinely a per-show call — a six-week commercial and a
-- year-long series do not want the same window. 0 disables archiving.
INSERT IGNORE INTO project_settings (project_id, setting_key, setting_value)
SELECT id, 'archive_done_after_days', '3' FROM projects;

-- --- which person a login belongs to ----------------------------------------
-- `users` are logins; `crew` are people, and until now nothing joined them. That
-- was fine while every screen was project-scoped, but "everything assigned to
-- ME, across all shows" cannot be answered without it — the platform knows who
-- is logged in and who a ticket is assigned to, and had no way to say those are
-- the same person.
--
-- Nullable and never guessed at from names by this migration: two people can
-- share a display name, and silently attaching one person's work to another's
-- login is exactly the class of quiet mis-mapping the alias layer exists to
-- prevent. mytasks.php asks once, with an exact name match pre-selected, and an
-- admin can correct it on users.php.
--
-- This is also the link the "artists update their own work" phase needs
-- (START_HERE, "Later, when Plaky is dropped"), so it is worth its own column
-- rather than a lookup by name at every call site.
ALTER TABLE users
  ADD COLUMN crew_id INT DEFAULT NULL AFTER role;

ALTER TABLE users
  ADD CONSTRAINT fk_users_crew FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE SET NULL;

-- NOT DONE HERE, ON PURPOSE: a ticket does not write to `progress`.
-- Plaky is still the source of truth for production status (DECISIONS,
-- 2026-08-11), so a ticket that set a step back to "Retake" would have that
-- overwritten by the next import, silently — the worst possible version of the
-- feature. step_id above is the link that makes it a small job on the day the
-- platform owns progress directly.
