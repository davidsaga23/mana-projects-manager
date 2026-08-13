-- Placeholder crew: a named slot you can plan against before anyone is hired.
--
-- David's point, and it corrects a wrong assumption in the Phase 4 build. The
-- scheduler left crew_id NULL because "there is no record of who does which
-- discipline yet" — treating crew as something that arrives from Plaky once the
-- team exists. But a crew NEVER fully exists until the show is nearly over:
-- people arrive as their department starts. Waiting for the real names means
-- never being able to plan, which is backwards.
--
-- So a step with a planned headcount of 3 gets three slots — "Animation 01",
-- "Animation 02", "Animation 03" — and the scheduler assigns work to them the
-- same way it would to people.
--
-- WHY THESE ARE ROWS IN `crew` AND NOT A SEPARATE TABLE
-- schedule.crew_id, progress.crew_id, progress_crew and crew_projects all point
-- at crew already. A parallel table would force every one of those, and every
-- report built later, to understand two kinds of assignee. `is_team` already
-- establishes that crew holds rows which aren't a person.
--
-- THE PART THAT MAKES IT WORTH DOING
-- When the real animator arrives you RENAME the slot and clear is_placeholder.
-- The row id never changes, so every scheduled task, every progress record and
-- the whole history follow automatically — no re-linking, no migration of
-- assignments. The alias layer then maps "Animator.wasi" onto that same row, and
-- what was planned for a slot becomes what was done by a person.

ALTER TABLE crew
  ADD COLUMN is_placeholder TINYINT(1) NOT NULL DEFAULT 0 AFTER is_team;

-- Which slot of which step a crew member fills.
--
-- Not a column on `crew`, because the same person is lane 2 of Animation on one
-- show and lane 1 of Comp on another — it is a relationship, exactly like
-- crew_projects. Distinct from crew_skills, which says what someone CAN do;
-- this says which slot they ARE.
--
-- UNIQUE (step_id, lane_no) is what lets the scheduler look up "who is lane 2 of
-- this step" and get one answer.
CREATE TABLE IF NOT EXISTS crew_lanes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  lane_no SMALLINT NOT NULL,
  crew_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_step_lane (step_id, lane_no),
  KEY idx_crew_lanes_project (project_id),
  KEY idx_crew_lanes_crew (crew_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Placeholders are never confused with real people in reporting, so no unique
-- constraint is added to crew.name: "Animation 01" legitimately exists on two
-- different shows at once, and forcing a project prefix into the name would put
-- "RTC · Animation 01" on every timeline bar. Which project a slot belongs to is
-- crew_lanes.project_id's job, not the name's.
