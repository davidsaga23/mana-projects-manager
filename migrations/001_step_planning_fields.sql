-- Planning fields the setup wizard captures per pipeline step.
--
-- The baseline schema carried a step's deadline but nothing about how long a
-- single item takes or how many people are on it — both needed to derive the
-- quota shown on the wizard's review screen, and later by the scheduler.
--
-- planned_headcount is the ESTIMATE captured at setup. headcount_log stays the
-- record of what actually happened day to day; the two are deliberately
-- separate so a plan can be compared against reality.

ALTER TABLE pipeline_steps
  ADD COLUMN start_date DATE DEFAULT NULL AFTER applies_to;

ALTER TABLE pipeline_steps
  ADD COLUMN days_per_unit DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER deadline;

ALTER TABLE pipeline_steps
  ADD COLUMN planned_headcount INT DEFAULT NULL AFTER days_per_unit;

ALTER TABLE pipeline_template_steps
  ADD COLUMN days_per_unit DECIMAL(5,2) NOT NULL DEFAULT 1.00;
