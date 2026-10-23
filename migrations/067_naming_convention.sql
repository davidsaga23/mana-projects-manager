-- 067 — the show's file naming convention, on its Brief, enforced on upload.
--
-- David, 2026-10-22: "include another variable in Brief with the naming
-- convention as a standard procedure so when we upload a new version the system
-- can check and validate if the name of the file matches ... otherwise it block
-- the ability to upload that file". Then three conventions to build it from:
-- MSD, FCM and STFC2.
--
-- All three are the same sentence with different words:
--   shots   PROJECT _ [EPISODE _] SHOT _ STEP _ V## _ INITIALS   FCM_SH040_CL_V00_AA
--   assets  PROJECT _ TYPE _ AssetName _ STEP _ V## _ INITIALS   FCM_CH_Mbappe_DG_V00_AA
-- So a convention is TWO TEMPLATES with placeholders ({PROJECT}, {EPISODE},
-- {SHOT}, {TYPE}, {ASSET}, {STEP}, {VERSION}, {INITIALS}) plus the short CODES
-- the documents define for each step and asset type ("FX: 2D FX", "CH:
-- Character"), which a template cannot know on its own.
--
-- naming_codes is JSON: {"steps": {"<step id>": "FX"}, "types": {"Character": "CH"}}.
-- Keyed by step ID, not name: "Color" for characters and "Color" for shots are
-- two steps on one show, and FCM gives them the same code anyway.
--
-- naming_first_version: the number the FIRST version carries in its file name.
-- Anima counts v01 first; the FCM and STFC documents start at V00. Rather than
-- make artists rename every file, the show says which, and the check offsets.
ALTER TABLE project_brief
  ADD COLUMN naming_shots VARCHAR(255) DEFAULT NULL AFTER notes;

ALTER TABLE project_brief
  ADD COLUMN naming_assets VARCHAR(255) DEFAULT NULL AFTER naming_shots;

ALTER TABLE project_brief
  ADD COLUMN naming_codes TEXT DEFAULT NULL AFTER naming_assets;

ALTER TABLE project_brief
  ADD COLUMN naming_first_version TINYINT NOT NULL DEFAULT 1 AFTER naming_codes;
