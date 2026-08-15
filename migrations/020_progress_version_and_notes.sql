-- Phase 8b — the item detail view: a version and notes, per step per item.
--
-- WHY ON `progress` AND NOT ON THE ITEM
-- `shots.notes` and `assets.notes` already exist and are about the THING — "this
-- character also appears in EP03". These two are about one step's work on that
-- thing: the rig is at v004, the comp has a note about the lens flare. Putting
-- them on the item would force every step to share one version number, which is
-- the same mistake as faking a whole-project step onto every shot (migration 006).
--
-- WHY VERSION IS FREE TEXT AND NOT AN INTEGER THE APP INCREMENTS
-- Settled with David 2026-08-15. Studios name versions differently and Mana's
-- own files already carry several shapes — "v003", "A_02", "final_02". A fixed
-- rule is exactly what forced a schema change on STFC when Props arrived, and it
-- is why asset_type, department and step names are all free text here.
--
-- The cost is real and worth stating: the app cannot answer "which is newer",
-- because it cannot know that v10 follows v9 rather than v1. Nothing in this
-- codebase sorts or compares versions, and nothing should start without a
-- deliberate decision about the format first. What it CAN do — show what the
-- current version is, next to who is on it and what state it is in — is the
-- question actually being asked in a review.

ALTER TABLE progress
  ADD COLUMN version VARCHAR(40) DEFAULT NULL AFTER status_id;

-- Free-form, per step per item. Kept separate from progress.status (the raw
-- imported text) so an import can never overwrite something a person typed.
ALTER TABLE progress
  ADD COLUMN notes TEXT DEFAULT NULL AFTER version;
