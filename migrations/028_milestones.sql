-- 028 — milestones on the timeline.
--
-- David, 2026-08-31: "is there a way I can add milestones to the general
-- pipeline timeline? I want to manually add those for client reviews."
--
-- A DATE, NOT A TASK. Everything else on this timeline is work with a duration,
-- a headcount and a cost. A client review is none of those: it is a day the
-- studio has to be ready for, and what matters is which bars cross it. So it is
-- drawn as a line down the chart rather than a bar on it, and it is stored in
-- its own small table rather than as a zero-length pipeline step -- which would
-- otherwise turn up in the quota maths, the crew slots and the expense
-- projection as a step that takes no time and needs nobody.
--
-- Typed by hand and never derived. The scheduler must not move these: the point
-- of a review date is that the plan bends around it.
--
-- This is the first piece of the CLIENT LOOP the roadmap has always listed as
-- missing. It records when a review is DUE, not what was sent or what came
-- back; a delivery log is still the larger part of that gap.

CREATE TABLE IF NOT EXISTS project_milestones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  milestone_date DATE NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_milestone_project (project_id, milestone_date),
  -- Same cascade as the other twenty tables carrying project_id, so deleting a
  -- project takes its milestones with it rather than leaving them orphaned.
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
