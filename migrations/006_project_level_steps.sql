-- Pipeline steps that belong to the whole project, not to any shot or asset.
--
-- An animatic, a music track, a sound mix or a final master is one deliverable
-- for the show. Previously the only choices were shot / asset / both, so such a
-- step had to be faked onto every shot — which multiplies one piece of work by
-- 188 and makes every quota and every count wrong.
--
-- Widening an ENUM is additive: existing values keep their meaning untouched.

ALTER TABLE pipeline_steps
  MODIFY COLUMN applies_to ENUM('shot','asset','both','project') NOT NULL DEFAULT 'shot';

ALTER TABLE pipeline_template_steps
  MODIFY COLUMN applies_to ENUM('shot','asset','both','project') NOT NULL DEFAULT 'shot';
