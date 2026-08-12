-- Headcount can be fractional.
--
-- A whole number is the wrong shape for how a small studio actually works:
-- someone splitting a week between two shows is 0.5 on each, and rounding that
-- to 1 (or 0) makes every quota derived from it wrong. crew_projects already
-- carries allocation_pct for the same reason.
--
-- Widening INT to DECIMAL never loses data — existing whole numbers are
-- unchanged, they simply gain two decimal places.

ALTER TABLE pipeline_steps
  MODIFY COLUMN planned_headcount DECIMAL(5,2) DEFAULT NULL;

-- The day-to-day log of who was actually on a step, kept consistent with the
-- estimate above so Phase 4 never has to reconcile two different shapes.
ALTER TABLE headcount_log
  MODIFY COLUMN headcount DECIMAL(5,2) NOT NULL;
