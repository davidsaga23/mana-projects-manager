-- Phase 12b — the freelancer fields, so a candidate row matches the studio's
-- own Mana_Freelancer_DataBase.xlsx.
--
-- WHY THESE COLUMNS AND NOT SOME OTHERS: they are the spreadsheet's, read from
-- the real file rather than guessed. 377 people and 12 studios are in it, and
-- David intends the two to merge. Its columns are:
--
--   Artist · Location · Position · Seniority · Project type · Email ·
--   Demo/portfolio link · Password · Worked with us? · Notes · Rate (USD) · Rating
--
-- Name, email, portfolio and notes already existed on `hires`. This adds the
-- rest, with two deliberate exceptions at the bottom of this comment.
--
-- ⚠ THE SHAPE DECISION, AND WHAT IT COSTS — David's call, 2026-08-17, asked
-- directly with the alternative on the table.
--
-- These fields describe a PERSON, and a person outlives any one job. The
-- alternative offered was a studio-wide `freelancers` table with `hires`
-- pointing into it — one row per human, the shape the spreadsheet already has
-- and the shape `crew` was given for exactly this reason in 2026-08-11 ("crew
-- is global, membership is per project").
--
-- What was chosen instead is these columns on `hires`, which is one row per
-- candidate PER SLOT. The consequence, stated plainly so nobody is surprised by
-- it later: approaching the same animator for three slots stores their country,
-- seniority and portfolio three times, and correcting a typo in one of them
-- corrects one of three. When the spreadsheet is eventually merged in, the
-- 377 people land as candidate rows rather than as a talent pool that exists
-- independently of whether a show currently needs them.
--
-- It is recorded rather than argued with — it is his studio's data. If the
-- duplication does start to bite, the fix is the `freelancers` table plus a
-- one-off pass that collapses matching rows into it, which is strictly more
-- work than doing it now but is not a trap: nothing here has to be deleted for
-- that to happen, because `hires` would keep its own columns as the record of
-- what was true at the time somebody was approached.
--
-- TWO FIELDS FROM THE SHEET ARE DELIBERATELY NOT HERE:
--
--   * Rate (USD) — free text in the sheet ("250/day", "2000 per environment",
--     "25/hour"). It is MONEY, and money does not live in this database. It
--     goes into `hire_quotes.notes`, which already exists on the finance side,
--     beside the structured `quoted_rate`. Putting a rate on `hires` would put
--     pay data on the connection the whole 2026-08-15 arrangement exists to
--     keep it off.
--
--   * Password — twelve rows of the sheet carry a code for viewing a private
--     reel ("foliopass000", "user: tmntreel pass: annec"). Not created as a
--     column: a field called `password` in a shared database will be reused for
--     a real credential by somebody who never read this file, and it would sit
--     there in plain text. It belongs in `notes`, next to the link it opens,
--     where it reads as what it is.

-- Where they are. 62 countries in the sheet, longest value 18 characters.
ALTER TABLE hires
  ADD COLUMN location VARCHAR(80) DEFAULT NULL AFTER candidate_name;

-- What they do — "3D animator", "Concept artist", "Compositor". Thirty distinct
-- values in the sheet and it will keep growing, so free text rather than a
-- list: the same reasoning as asset types (2026-08-11) and departments
-- (2026-08-14). The screen offers what has already been typed as suggestions.
--
-- NOT the same thing as `step_name`, which records the SLOT they are up for.
-- A modeller can be a candidate for a Lookdev slot; conflating the two would
-- lose whichever answer was written second.
ALTER TABLE hires
  ADD COLUMN position VARCHAR(80) DEFAULT NULL AFTER location;

-- Junior / Mid / Senior / Lead are the four in the sheet, and they are offered
-- as suggestions. Still VARCHAR and not an ENUM: studios genuinely differ past
-- those four (Supervisor, Director, Trainee), and "a fixed list in code is what
-- forced a schema change on STFC when Props arrived".
ALTER TABLE hires
  ADD COLUMN seniority VARCHAR(20) DEFAULT NULL AFTER position;

-- 2D / 3D / 2D-3D in the sheet. Note this does NOT match projects.type, which
-- is ENUM('2D','3D','Mixed','Other') — the sheet says "2D/3D" where the
-- platform says "Mixed". Left as free text rather than forced onto the project
-- vocabulary, because it describes what a PERSON works in, not what a show is,
-- and quietly rewriting somebody's answer to fit another list is the naming
-- drift the alias layer exists to prevent.
ALTER TABLE hires
  ADD COLUMN project_type VARCHAR(20) DEFAULT NULL AFTER seniority;

-- The sheet's "Worked with us?" column, 0 or 1 on 380 rows. This is the single
-- most useful field in the file for the question the hiring screen is for:
-- somebody who has already delivered on a Mana show is a different proposition
-- from a name off a reel.
ALTER TABLE hires
  ADD COLUMN worked_with_us TINYINT(1) NOT NULL DEFAULT 0 AFTER project_type;

-- The sheet has a Rating column and it is empty in every one of its 1,004 rows
-- — the intent was there and nothing ever filled it. Added as a small integer
-- so it can finally be used: 1 to 5, NULL for "not rated", which is the honest
-- default and is different from a rating of zero.
ALTER TABLE hires
  ADD COLUMN rating TINYINT DEFAULT NULL AFTER worked_with_us;

-- "Who do we know who does X" and "who have we worked with before" are the two
-- questions this data gets asked, so both get an index.
ALTER TABLE hires
  ADD INDEX idx_hires_position (position);

ALTER TABLE hires
  ADD INDEX idx_hires_worked (worked_with_us);
