-- 036 — progress recorded by hand on a pipeline step.
--
-- David, 2026-09-18: double-clicking a bar on the general timeline should let
-- him "register the progress of that task manually".
--
-- A PERCENTAGE SOMEBODY TYPED, NOT A COUNT. The tracking grid already says how
-- many items of a step are approved, and nothing here replaces that. This is the
-- producer's own reading, which is the only one there is for a step that is a
-- single deliverable (an animatic is 0% or 100% on the grid until the day it is
-- approved) and a quicker one for everything else. The step editor on the
-- timeline shows the two side by side, so they are never mistaken for each other.
--
-- Drawn as the darker part of the step's bar, which is what Frappe Gantt's own
-- progress field does. progress_source reads like days_source: who set it, when.
--
-- Not copied into templates (the template writers name their columns): progress
-- belongs to one show.
--
-- One statement, so it applies whole or not at all, and a failed run can simply
-- be run again.

ALTER TABLE pipeline_steps
  ADD COLUMN progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER days_source,
  ADD COLUMN progress_source VARCHAR(120) DEFAULT NULL AFTER progress_pct;
