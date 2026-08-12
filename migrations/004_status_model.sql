-- Phase 3 — the platform's own status vocabulary, plus what the importer needs.
--
-- The point of this migration: the platform defines statuses itself, and an
-- import merely translates onto them. The day Plaky is dropped the importer
-- stops running and people set the same statuses by hand — no schema change.
-- See docs/DECISIONS.md, the paired Plaky entries of 2026-08-11/12.

-- Global seed list, same pattern as pipeline_templates: a project COPIES from
-- here, so editing a project's statuses never disturbs another project, and the
-- default list lives in exactly one place rather than in both SQL and PHP.
CREATE TABLE IF NOT EXISTS status_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  maps_to ENUM('none','delivered','approved') NOT NULL DEFAULT 'none',
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  color VARCHAR(7) DEFAULT NULL,
  UNIQUE KEY uniq_template_status (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The ten STFC used, plus "On Hold". is_blocked marks work that CANNOT proceed,
-- as opposed to work simply not started — same maps_to, completely different
-- meaning for planning.
INSERT IGNORE INTO status_templates (name, sort_order, maps_to, is_blocked, color) VALUES
  ('N/A',              1,  'none',      0, '#95A5A6'),
  ('Waiting to start', 2,  'none',      0, '#B0BEC5'),
  ('Ready to start',   3,  'none',      0, '#90A4AE'),
  ('In progress',      4,  'none',      0, '#4A90D9'),
  ('On Hold',          5,  'none',      1, '#E67E22'),
  ('Retake',           6,  'none',      0, '#E74C3C'),
  ('Ready for review', 7,  'delivered', 0, '#F5B041'),
  ('Pending review',   8,  'delivered', 0, '#F7DC6F'),
  ('In client review', 9,  'delivered', 0, '#BB8FCE'),
  ('Completed',        10, 'approved',  0, '#45B8AC'),
  ('Final',            11, 'approved',  0, '#1E7E34');

-- A project's own statuses. Editable per project; the team can call a state
-- anything it likes and reporting still knows what it counts as.
CREATE TABLE IF NOT EXISTS statuses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  maps_to ENUM('none','delivered','approved') NOT NULL DEFAULT 'none',
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  color VARCHAR(7) DEFAULT NULL,
  UNIQUE KEY uniq_project_status (project_id, name),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Give every existing project the default set.
INSERT IGNORE INTO statuses (project_id, name, sort_order, maps_to, is_blocked, color)
SELECT p.id, t.name, t.sort_order, t.maps_to, t.is_blocked, t.color
  FROM projects p CROSS JOIN status_templates t;

-- Incoming external text -> one of this project's statuses.
-- status_id NULL means "seen in an import but not mapped yet" — that is what the
-- unmatched report reads, rather than the value being silently dropped.
CREATE TABLE IF NOT EXISTS status_mapping (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  external_status VARCHAR(100) NOT NULL,
  status_id INT DEFAULT NULL,
  times_seen INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_project_external (project_id, external_status),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- progress gains the resolved status and logged time.
-- progress.status keeps the RAW incoming text alongside, so an import can always
-- report what actually arrived.
ALTER TABLE progress
  ADD COLUMN status_id INT DEFAULT NULL AFTER status;

ALTER TABLE progress
  ADD CONSTRAINT fk_progress_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL;

-- Stored in MINUTES as a whole number; shown and entered in HOURS.
-- Integer minutes are exact — decimal hours drift when summed, and these numbers
-- get compared against invoices.
ALTER TABLE progress
  ADD COLUMN time_spent_minutes INT DEFAULT NULL AFTER assignee_raw;

-- A task can have several people on it: the live board really does say
-- "Júnior Fonseca,Michal Pilch". progress.crew_id stays as the primary assignee
-- so simple queries keep working; this is the complete answer.
CREATE TABLE IF NOT EXISTS progress_crew (
  progress_id INT NOT NULL,
  crew_id INT NOT NULL,
  PRIMARY KEY (progress_id, crew_id),
  FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
