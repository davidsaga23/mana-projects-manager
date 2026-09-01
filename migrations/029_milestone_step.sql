-- 029 — a milestone can belong to a pipeline step.
--
-- David, 2026-08-31: "there are milestones associated with certain pipeline step
-- bars, for visual sanity is better to see animatic review below the animatic
-- bar (or in the same row)."
--
-- The layout follows from the LINK rather than the other way about. Storing a
-- row number or a y position would draw the same picture and mean nothing: it
-- would have to be re-tidied every time a step moved, and it could not say WHY
-- a marker sits where it does. "This is a review of the animatic" survives
-- reordering, re-dating and re-rendering, and the row is derived from it.
--
-- It also buys a check the position never could: a review dated BEFORE the step
-- it reviews has finished is a date nobody can meet, and the chart can now say
-- so -- the same kind of warning the red dependency arrows carry.
--
-- NULLABLE, and the default. A delivery or a contract lock belongs to the
-- project rather than to any one step, and those keep stacking at the top of
-- the chart as they do now.
--
-- ON DELETE SET NULL, not CASCADE: deleting a pipeline step must not delete the
-- client review that happened around it. The milestone survives and simply
-- stops being attached to anything.

ALTER TABLE project_milestones
  ADD COLUMN step_id INT DEFAULT NULL AFTER project_id,
  ADD KEY idx_milestone_step (step_id),
  ADD CONSTRAINT fk_milestone_step
      FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE SET NULL;
