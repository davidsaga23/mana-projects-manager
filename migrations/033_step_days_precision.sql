-- 033 — days per item keeps three decimals, and can say where it came from.
--
-- Groundwork for 034, which makes ideal quotas belong to each project (David,
-- 2026-09-17). A file of its own because ADD COLUMN is the one statement that
-- cannot run twice: if 034's data copy ever fails and is re-run, it must not stop
-- on a column that already exists.
--
-- THREE DECIMALS, NOT TWO. The ideal quotas sheets have always held
-- DECIMAL(6,3) (026) while a step's own days per item held DECIMAL(5,2). Copying
-- a sheet's 0.435 into a step would have stored 0.44, a 1.15% budget change
-- nobody typed. Widening changes no value already stored. Template steps too,
-- because a template made from a project copies this column.

ALTER TABLE pipeline_steps
  MODIFY days_per_unit DECIMAL(6,3) NOT NULL DEFAULT 1.000;

ALTER TABLE pipeline_template_steps
  MODIFY days_per_unit DECIMAL(6,3) NOT NULL DEFAULT 1.000;

-- Where a step's days per item came from: copied from another project, carried
-- over from a type's sheet by 034, or set by somebody on a date. NULL means it
-- was typed on this project before anything recorded that.
ALTER TABLE pipeline_steps
  ADD COLUMN days_source VARCHAR(120) DEFAULT NULL AFTER days_per_unit;
