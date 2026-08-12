-- A step can apply to one KIND of asset rather than all of them.
--
-- Blendshapes and Rigging are character work; an environment never needs them.
-- Without this, a step marked "assets" implies every asset in the project, so
-- the quota counts 55 items where the real number is 13 — and plans the crew
-- accordingly. The reference Plaky board shows exactly this shape: 55 assets,
-- but Rigging on 36 of them and Blendshapes on 13.
--
-- NULL means "all asset types", which is what every existing row means today.
-- Free text rather than an ENUM, matching assets.asset_type — adding a new kind
-- of asset must never need a schema change (the mistake STFC made with Props).

ALTER TABLE pipeline_steps
  ADD COLUMN applies_to_asset_type VARCHAR(50) DEFAULT NULL AFTER applies_to;

ALTER TABLE pipeline_template_steps
  ADD COLUMN applies_to_asset_type VARCHAR(50) DEFAULT NULL AFTER applies_to;
