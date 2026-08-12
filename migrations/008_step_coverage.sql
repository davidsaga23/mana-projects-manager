-- How much of the work a step actually touches.
--
-- Not every step covers everything. FX might land on 30% of the running time;
-- concept isn't drawn again for a character variation; a 2D show might only
-- rotoscope a handful of shots. Until now every step implied 100% of its scope,
-- which overstates the work and therefore the crew needed to hit the date.
--
-- A percentage rather than a count, because at planning time that's the honest
-- shape of the knowledge: "FX is maybe a third of the show" is sayable long
-- before anyone can list which shots have FX in them.
--
-- 100 means "all of it", which is what every existing row means today.

ALTER TABLE pipeline_steps
  ADD COLUMN coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 100 AFTER days_per_unit;

ALTER TABLE pipeline_template_steps
  ADD COLUMN coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 100;
