-- Phase 4 — what the forward-pass scheduler needs that the baseline didn't have.
--
-- Three separate things, all small, all feeding the same screen:
--
--   1. A calendar of days nobody works. working_days_between() counts Mon-Fri and
--      nothing else, so today every Mexican public holiday is silently treated as
--      a working day. That error is always in the optimistic direction, which is
--      the worst direction for a deadline, and it already affects the quota
--      figures on the wizard's review screen — not just the new scheduler.
--
--   2. A way to say "this item does not pass through this step" — which is what
--      'N/A' has always meant on the board, but nothing recorded it as a fact the
--      scheduler could read.
--
--   3. Somewhere to record WHICH parallel lane a scheduled task sits in, so a
--      step needing two compositors shows two tracks rather than one heap.

-- 1. NON-WORKING DAYS ---------------------------------------------------------
-- Studio-wide, not per project: a public holiday is a public holiday, and every
-- project running that week loses the same day. Deliberately NOT nullable
-- project_id + UNIQUE(project_id, day) — MySQL unique indexes ignore NULLs, so
-- that shape would happily store the same holiday twice (the same trap that
-- progress.item_key exists to avoid). If a single project ever needs its own
-- shutdown, that is an additive column later.
CREATE TABLE IF NOT EXISTS non_working_days (
  day DATE NOT NULL,
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mexico's statutory days off (Ley Federal del Trabajo art. 74) for 2026 and
-- 2027. STATUTORY ONLY, on purpose: these are days the studio legally does not
-- work, so seeding them can't make a plan wrong. Semana Santa, the 24th and the
-- 31st are near-universal in practice but they are a studio's CHOICE — seeding
-- days off that Mana actually works would pad every schedule silently, which is
-- exactly the class of error this file exists to remove. The calendar screen
-- suggests them; a person adds them.
INSERT IGNORE INTO non_working_days (day, name) VALUES
  ('2026-01-01', 'Año Nuevo'),
  ('2026-02-02', 'Día de la Constitución'),
  ('2026-03-16', 'Natalicio de Benito Juárez'),
  ('2026-05-01', 'Día del Trabajo'),
  ('2026-09-16', 'Independencia'),
  ('2026-11-16', 'Revolución Mexicana'),
  ('2026-12-25', 'Navidad'),
  ('2027-01-01', 'Año Nuevo'),
  ('2027-02-01', 'Día de la Constitución'),
  ('2027-03-15', 'Natalicio de Benito Juárez'),
  ('2027-05-01', 'Día del Trabajo'),
  ('2027-09-16', 'Independencia'),
  ('2027-11-15', 'Revolución Mexicana'),
  ('2027-12-25', 'Navidad');

-- 2. "THIS ITEM DOESN'T PASS THROUGH THIS STEP" -------------------------------
-- is_blocked already distinguishes "cannot proceed" from "not started yet".
-- This is the third case, and it is different again: the work is not late, not
-- waiting and not blocked — it does not exist. A character with no rig, a shot
-- with no FX. Without it the scheduler must plan work that will never happen.
--
-- It is also what makes pipeline_steps.coverage_pct able to stop being a guess:
-- coverage says "FX is about 30% of the show" before anyone knows which shots,
-- and these rows say which shots once they do. Real data wins, exactly as real
-- shot counts already beat project_settings.estimated_shots.
ALTER TABLE statuses
  ADD COLUMN is_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER is_blocked;

ALTER TABLE status_templates
  ADD COLUMN is_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER is_blocked;

-- 'N/A' has always meant this. Now it says so where the scheduler can read it.
UPDATE status_templates SET is_excluded = 1 WHERE name = 'N/A';
UPDATE statuses         SET is_excluded = 1 WHERE name = 'N/A';

-- 3. WHICH LANE A TASK SITS IN ------------------------------------------------
-- A step with two people has two lanes running side by side. Storing the lane
-- means the timeline can show them as two tracks, and a placeholder name comes
-- out of it for free ("Compositing 01", "Compositing 02") — derived from the
-- step name at display time, never stored, so renaming the step renames the
-- lanes with it and no stale copy is left behind.
ALTER TABLE schedule
  ADD COLUMN lane SMALLINT NOT NULL DEFAULT 1 AFTER crew_id;

-- What produced a row, so a re-run can replace its own work and leave everything
-- else alone. is_manual already protects hand-planned rows; this says which
-- automatic pass wrote the rest.
ALTER TABLE schedule
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER is_manual;

-- No column needed for the fourth case. A step whose coverage is still a guess
-- is scheduled as ONE band for the whole step rather than as invented per-shot
-- rows: item_key 'band', both item columns NULL. That is the same shape the
-- importer already uses for whole-project deliverables ('p<project_id>'), so
-- nothing new has to be taught to read it.
