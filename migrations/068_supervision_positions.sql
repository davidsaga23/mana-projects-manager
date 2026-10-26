-- 068 — supervision positions: a person on a show for a stretch of dates, with
-- no quota and no hands-on work.
--
-- David, 2026-10-26: "it comes times where we need positions like anim sup or
-- cg sup that need to be accounted for ... they won't have days per item or
-- people, we just need to add the start and end dates so they're reflected in
-- the staffing and expense projections."
--
-- NOT A PIPELINE STEP, and not a crew_lanes row. A lane hangs off a step, and a
-- step is read by the scheduler, the quota, the Budget pool, the timeline and
-- the board; faking a supervisor in as a whole-project step put a block of work
-- on the timeline and a "0% done" card in the pool for somebody who produces no
-- items at all. A position of its own is read only where it is wanted: Dates &
-- headcount (where it is set), Staffing (who fills it) and the expense
-- projection (what it costs). See includes/supervision.php.
--
-- crew_id IS NOT NULL, the same rule as crew_lanes.crew_id: an empty position
-- holds a PLACEHOLDER crew row named after it ("Anim Sup"), so it can carry a
-- rate on the Finance screen before anybody is in it, exactly as a slot can.
--
-- Both dates INCLUSIVE, like every other range in the platform, and nullable so
-- a position can be written down before its dates are known; it costs nothing
-- until they are.
--
-- ON DELETE CASCADE on the project only. A crew row is the studio's, and the
-- position is removed by hand (which also clears its placeholder), never by a
-- person being deleted underneath it.
CREATE TABLE IF NOT EXISTS supervision_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  crew_id INT NOT NULL,
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_supervision_project (project_id, sort_order),
  KEY idx_supervision_crew (crew_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
