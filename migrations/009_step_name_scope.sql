-- A step name only has to be unique WITHIN what it applies to.
--
-- "Color" is a real step for shots and a real step for characters in 2D, and
-- "Concept" applies to several kinds of asset. Forcing globally unique names
-- pushed people into inventing "Colores" and "Color 2", which is exactly the
-- naming drift the alias layer exists to clean up — self-inflicted this time.
--
-- The scope column becomes NOT NULL DEFAULT '' first, because MySQL unique
-- indexes ignore NULLs: with NULLs allowed, two rows of ("Color", asset, NULL)
-- would both be permitted and the constraint would quietly do nothing. Same
-- trap the progress table's item_key was built to avoid.
--
-- ORDER MATTERS BELOW. The new index is created BEFORE the old one is dropped.
-- MySQL refuses to drop an index a foreign key relies on, and uniq_project_step
-- starts with project_id, so it is what satisfies the FK to projects. Creating
-- the replacement first (also project_id-leftmost) gives the FK something to
-- keep using, and the drop then succeeds.
--
-- Safe to re-run after a failure: the UPDATE matches nothing the second time and
-- MODIFY to an identical definition is a no-op.

UPDATE pipeline_steps SET applies_to_asset_type = '' WHERE applies_to_asset_type IS NULL;

ALTER TABLE pipeline_steps
  MODIFY COLUMN applies_to_asset_type VARCHAR(50) NOT NULL DEFAULT '';

ALTER TABLE pipeline_steps
  ADD UNIQUE KEY uniq_project_step_scope (project_id, name, applies_to, applies_to_asset_type);

ALTER TABLE pipeline_steps DROP INDEX uniq_project_step;

-- Templates carry the same rule, or saving a pipeline as a template would fail
-- on exactly the pipelines this change exists to allow. Same ordering, same
-- reason: uniq_template_step is what indexes the FK to pipeline_templates.
UPDATE pipeline_template_steps SET applies_to_asset_type = '' WHERE applies_to_asset_type IS NULL;

ALTER TABLE pipeline_template_steps
  MODIFY COLUMN applies_to_asset_type VARCHAR(50) NOT NULL DEFAULT '';

ALTER TABLE pipeline_template_steps
  ADD UNIQUE KEY uniq_template_step_scope (template_id, name, applies_to, applies_to_asset_type);

ALTER TABLE pipeline_template_steps DROP INDEX uniq_template_step;
