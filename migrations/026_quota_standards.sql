-- Phase 13 — the IDEAL quota. A base sheet per kind of project.
--
-- David, 2026-08-17: *"we need a separate appendix or base sheet per kind of
-- project where I can set the ideal quotas, not the minimal quotas we currently
-- have that are based on the head count and available time. This ideal quota
-- will be informed by experience and later on by comparing other projects too."*
--
-- ⚠ THIS IS A DIFFERENT KIND OF NUMBER FROM EVERYTHING ELSE IN THE SCHEMA, and
-- confusing the two would make both useless. There are now three:
--
--   REQUIRED  what the deadline demands. step_quota() derives it every time from
--             remaining work / working days left, and it moves whenever the date
--             or the progress moves. Nobody types it.
--   ESTIMATED what THIS project was planned at — pipeline_steps.days_per_unit,
--             typed on the wizard's Dates & headcount screen, frozen at kickoff
--             into step_estimate_baseline (migration 013).
--   IDEAL     what the studio believes the work actually takes when done
--             properly, for this KIND of project. Typed once, here, and true
--             across shows until experience changes it.
--
-- The value of the third is that it is the only one that can contradict the
-- other two. A required pace far above the ideal is not an ambitious plan, it is
-- an impossible one — and today that is only discoverable in week three. START_HERE
-- says the review screen exists so "an unrealistic plan is visible at setup"; the
-- ideal is the number that makes that judgement possible instead of vibes.
--
-- WHY PER PROJECT TYPE. A 2D short and a 3D series are not the same work, and
-- ROADMAP has said since 2026-08-13 that comparing them "without splitting by
-- projects.type would average away the signal". So the sheet is per type, which
-- is also exactly how David asked for it.
--
-- WHY NOT ON step_library, which is the other global step table: it is keyed
-- `UNIQUE (name)` alone, so "Concept" for characters and "Concept" for
-- environments are ONE row there. Those are different jobs with different day
-- rates and migration 009 already treats them as different steps. ROADMAP names
-- this gap in as many words. Storing an ideal against a key that cannot tell
-- them apart would produce an average of two unrelated numbers.

CREATE TABLE IF NOT EXISTS quota_standards (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- 2D / 3D / Mixed / Other, matching projects.type. VARCHAR rather than the
  -- same ENUM: this table is a reference document and outlives any one
  -- project's shape, and a type added to projects later must not silently
  -- orphan the standards written against it.
  project_type VARCHAR(20) NOT NULL,

  -- The step identity, which is the triple migration 009 made unique on
  -- pipeline_steps and which ROADMAP names as the comparison key:
  --   (name, applies_to, applies_to_asset_type)  e.g. ("Concept", asset, "Character")
  -- applies_to_asset_type is '' rather than NULL for steps that are not scoped
  -- to a type, because MySQL unique indexes ignore NULLs — the same trap
  -- item_key exists for (DECISIONS, 2026-08-11). An empty string collides
  -- properly; a NULL would let the same standard be stored twice.
  step_name VARCHAR(100) NOT NULL,
  applies_to ENUM('shot','asset','both','project') NOT NULL DEFAULT 'shot',
  applies_to_asset_type VARCHAR(50) NOT NULL DEFAULT '',

  -- THE NUMBER. Stored as days per unit, not units per day, for one reason:
  -- everything it feeds — pipeline_steps.days_per_unit, step_quota(), the
  -- expense projection — is already in days per unit, and a stored reciprocal
  -- would have to be converted at every one of those boundaries. The screen
  -- shows both, because "0.5 days each" and "2 a day" are the same fact and
  -- people say whichever suits the step.
  --
  -- DECIMAL, never FLOAT: it multiplies out into person-days and then into
  -- money, and binary floating point cannot represent 0.1 exactly.
  days_per_unit DECIMAL(6,3) NOT NULL,

  -- What one person is expected to carry, when that is part of the standard —
  -- "an animator holds 12 shots at a time". Nullable, because most steps have
  -- no such rule and inventing one would be worse than leaving it blank.
  typical_headcount DECIMAL(5,2) DEFAULT NULL,

  -- WHERE THE NUMBER CAME FROM. Free text, and it earns its place: a standard
  -- somebody guessed and a standard measured across four finished shows are
  -- both useful and must never be mistaken for each other. When the
  -- cross-project benchmark is built (ROADMAP) it writes its observed median
  -- here as a SUGGESTION, and this column is what says so.
  source VARCHAR(120) DEFAULT NULL,
  notes TEXT DEFAULT NULL,

  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,

  UNIQUE KEY uniq_standard (project_type, step_name, applies_to, applies_to_asset_type),
  KEY idx_standard_type (project_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- an append-only history ---------------------------------------------------
--
-- "What did we think this took last year, and were we right?" is the question
-- this table exists to eventually answer, and it cannot be answered by a column
-- that gets overwritten. Same asymmetry as daily_snapshots, step_estimate_baseline
-- and crew_terms_log: cheap to capture as it happens, impossible afterwards.
CREATE TABLE IF NOT EXISTS quota_standards_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  standard_id INT DEFAULT NULL,
  project_type VARCHAR(20) NOT NULL,
  step_name VARCHAR(100) NOT NULL,
  applies_to VARCHAR(10) NOT NULL DEFAULT 'shot',
  applies_to_asset_type VARCHAR(50) NOT NULL DEFAULT '',
  days_per_unit DECIMAL(6,3) DEFAULT NULL,
  typical_headcount DECIMAL(5,2) DEFAULT NULL,
  source VARCHAR(120) DEFAULT NULL,
  changed_by VARCHAR(100) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_standards_log (project_type, step_name, changed_at),
  -- SET NULL, not CASCADE: deleting a standard must not delete the record that
  -- it once existed and what it said.
  FOREIGN KEY (standard_id) REFERENCES quota_standards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- seed from what the studio has already planned -----------------------------
--
-- Every step that exists on a real project, with the days-per-item it was
-- actually planned at, grouped by project type and step identity. AVG across
-- projects of the same type, because two shows that both ran "Concept
-- (Character)" are two opinions about the same job.
--
-- ⚠ SEEDED AS A STARTING POINT, AND SAID SO. `source` records that these came
-- from existing plans rather than from experience — the same care migration 013
-- takes in refusing to backfill a baseline from edited numbers. A seeded value
-- is a place to start arguing from, not a measurement, and the screen labels it
-- that way.
INSERT IGNORE INTO quota_standards
  (project_type, step_name, applies_to, applies_to_asset_type, days_per_unit,
   typical_headcount, source)
SELECT p.type,
       ps.name,
       ps.applies_to,
       COALESCE(ps.applies_to_asset_type, ''),
       ROUND(AVG(ps.days_per_unit), 3),
       ROUND(AVG(NULLIF(ps.planned_headcount, 0)), 2),
       CONCAT('Seeded from ', COUNT(DISTINCT p.id), ' existing project(s) — not yet reviewed')
  FROM pipeline_steps ps
  JOIN projects p ON p.id = ps.project_id
 WHERE ps.days_per_unit > 0
 GROUP BY p.type, ps.name, ps.applies_to, COALESCE(ps.applies_to_asset_type, '');
