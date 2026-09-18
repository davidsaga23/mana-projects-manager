-- 035 — saved versions of a project's timeline.
--
-- David, 2026-09-18: "Timeline already has persistent memory and stores the
-- timeline as I adjust it. However, I need to check the historic changes."
--
-- Every drag on the timeline saves straight into pipeline_steps, schedule and
-- project_milestones, so the page always shows the latest state and nothing
-- remembers the one before it. A version is a copy of what the page drew at the
-- moment somebody pressed "Save timeline's version": both charts and the
-- milestones.
--
-- THE DRAWING, NOT THE IDS. payload holds what the charts were handed, as JSON,
-- names and colours included, rather than ids pointing into tables that keep
-- moving. Stored as ids, a step renamed or a shot deleted next month would
-- quietly rewrite what an old version shows, which is the one thing a record of
-- the past must not do.
--
-- The name, V00_09182026, is DERIVED from seq and created_at when shown, never
-- stored, the same way ticket references are.
--
-- Read only: nothing writes a version back onto the live plan.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS timeline_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  -- Per project, from 0: V00, V01, V02, and never reused.
  seq INT NOT NULL,
  -- Written by PHP in the studio's timezone rather than defaulted by MySQL,
  -- whose clock may sit in another zone: the date in the name has to be the
  -- studio's date.
  created_at DATETIME NOT NULL,
  -- The name as it read when saved, like project_types.created_by, so it still
  -- reads correctly after an account is renamed or removed.
  created_by VARCHAR(100) DEFAULT NULL,
  -- Counted when saved, so the list can say what each version holds without
  -- opening the drawing.
  step_count INT NOT NULL DEFAULT 0,
  task_count INT NOT NULL DEFAULT 0,
  milestone_count INT NOT NULL DEFAULT 0,
  -- MEDIUMTEXT holds 16 MB. A 1,500-task show is roughly half a megabyte.
  payload MEDIUMTEXT NOT NULL,
  -- Two people saving in the same second cannot both get the same number: the
  -- second insert fails here and is retried with the next one.
  UNIQUE KEY uq_timeline_version_seq (project_id, seq),
  -- Same cascade as every other table carrying project_id.
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
