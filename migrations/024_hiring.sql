-- Phase 12 — hiring. Steps 6 and 8 of the production cycle (ROADMAP).
--
-- THE GAP THIS FILLS, in one sentence: the platform has always known that the
-- plan needs a person here (an unfilled slot, and lately "14.5 person-days with
-- no rate behind them") and has always known when a person is finally in the
-- job. It has never held the months in between — who was approached, what they
-- asked for, whether they said yes.
--
-- Slots -> hiring -> contracts -> invoices is one chain. The first and last
-- links were built; these are the middle two.
--
-- ⚠ NO STFC REFERENCE FOR THIS ONE. Every screen since 2026-08-14 has been
-- designed by reading the equivalent in the STFC dashboard first. There is no
-- equivalent here — STFC tracks a show that was already crewed. This is the
-- first phase designed from scratch since that rule was set, which is worth
-- knowing when something about it turns out to be wrong: there is no
-- proven-in-anger version to compare against.
--
-- WHERE THE MONEY LINE FALLS (David's call, 2026-08-17, asked directly)
-- A quoted rate is pay data and lives in the FINANCE database, alongside every
-- other rate — see finance_install_04.sql. The conversation itself is planning
-- data and lives here, visible to any editor. So a producer looking at the Crew
-- screen can see that a slot already has somebody lined up, without being able
-- to see what they cost. That is the same split `crew` and `crew_terms` already
-- make for people who have been hired, applied one stage earlier.

-- --- the hiring conversation -------------------------------------------------
--
-- One row per CANDIDATE PER SLOT, not one per slot. Two people are often in the
-- frame for one job and the one who says no is exactly the record worth having
-- three weeks later, when the other one falls through.
--
-- A candidate is deliberately NOT a `crew` row. They are somebody being talked
-- to, and inventing a crew record for them would put them in every assignee
-- dropdown in the app, make them assignable to tickets, and leave a person who
-- declined sitting in the studio's permanent people list. They become a crew
-- member at exactly one moment — being hired — and the machinery for that
-- already exists: promote_placeholder() renames the SLOT, so the crew id never
-- changes and everything already planned for it becomes theirs with nothing
-- moved (DECISIONS, 2026-08-13).
CREATE TABLE IF NOT EXISTS hires (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,

  -- Which slot this is for. NULLABLE on purpose, for two different reasons:
  --   * somebody met at a festival who is not yet attached to any particular
  --     job is a real thing to record, and forcing a slot onto them would
  --     attach a fiction to a headcount;
  --   * a slot can be removed (crew.php does it) while the conversation that
  --     happened around it still deserves to exist.
  lane_id INT DEFAULT NULL,

  -- The step is COPIED, not only referenced, because this record outlives the
  -- lane it points at. The same reasoning as step_estimate_baseline storing
  -- step_name (2026-08-13) and progress.assignee_raw keeping the raw string: a
  -- historical record that can only say "lane 41" is worthless by the time
  -- anybody wants it. Live pointers derive, historical records copy
  -- (DECISIONS, 2026-08-14).
  step_id INT DEFAULT NULL,
  step_name VARCHAR(100) DEFAULT NULL,

  candidate_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  portfolio_url VARCHAR(500) DEFAULT NULL,

  -- Where they came from: a referral, an agency, a festival, a reel that
  -- arrived by email. Free text. Over a few shows this answers "which of our
  -- sources actually produces people we hire", which no spreadsheet currently
  -- can.
  source VARCHAR(100) DEFAULT NULL,

  -- AN ENUM, WHICH THIS CODEBASE USUALLY REFUSES — so here is why.
  -- Production statuses are rows (`statuses`) because every show names its
  -- states differently and the tickets board proved a second ENUM would mean
  -- two mental models for one idea. Hiring is not that: approached -> talking
  -- -> offer -> yes or no is the same everywhere and does not vary by project,
  -- and `statuses` is production vocabulary that these values would not map
  -- onto at all ("offer out" is not "in progress"). `tickets.priority` and
  -- `invoices.status` are already ENUMs on the same grounds. If a studio ever
  -- needs a stage this cannot express, that is a migration, and it should be.
  --
  -- The three terminal stages are separate on purpose: "declined" (they said
  -- no) and "passed" (we said no) look identical in a count and mean opposite
  -- things about the pipeline.
  stage ENUM('lead','approached','in_talks','offer_out','hired','declined','passed')
        NOT NULL DEFAULT 'lead',

  -- When the stage last moved, which is what makes "this offer has been out for
  -- eleven days" answerable. updated_at cannot serve: it bumps when somebody
  -- fixes a typo in the notes.
  stage_changed_at TIMESTAMP NULL DEFAULT NULL,

  -- Set at the moment of hiring: the crew row this candidate became. It is the
  -- slot's own crew id, unchanged — see promote_placeholder(). This is what
  -- lets a person page say "hired on 3 September, sourced from a referral"
  -- long after the fact.
  crew_id INT DEFAULT NULL,

  notes TEXT DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by VARCHAR(100) DEFAULT NULL,

  KEY idx_hires_project_stage (project_id, stage),
  KEY idx_hires_lane (lane_id),
  KEY idx_hires_crew (crew_id),

  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  -- SET NULL, not CASCADE: removing a slot must not delete the record of the
  -- three people who were approached for it.
  FOREIGN KEY (lane_id) REFERENCES crew_lanes(id) ON DELETE SET NULL,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE SET NULL,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- an append-only log of stage changes -------------------------------------
--
-- "When did we make that offer" and "how long did they sit at approached" are
-- the questions this data attracts, and they cannot be reconstructed from a
-- single `stage` column that gets overwritten. The same asymmetry as
-- daily_snapshots and crew_terms_log: cheap to capture as it happens,
-- impossible afterwards.
CREATE TABLE IF NOT EXISTS hire_stage_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hire_id INT NOT NULL,
  candidate_name VARCHAR(120) DEFAULT NULL,
  from_stage VARCHAR(20) DEFAULT NULL,
  to_stage VARCHAR(20) NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  changed_by VARCHAR(100) DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hire_stage_log (hire_id, changed_at),
  FOREIGN KEY (hire_id) REFERENCES hires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- when somebody is actually ON the show -----------------------------------
--
-- David's request, 2026-08-17: start and end dates on the person card.
--
-- They go on `crew_projects` — the table that already records who is on which
-- project and at what allocation — rather than on `crew`, because an engagement
-- is per SHOW. The same compositor is on Return to Club from September and on
-- the next thing from January, and a single pair of dates on the person would
-- have to be wrong about one of them.
--
-- Both nullable: not knowing yet is the normal state for most of a project, and
-- a required date would be answered with a guess that then reads as a fact.
--
-- ⚠ THE SCHEDULER DOES NOT READ THESE YET, and that is a deliberate limit on
-- this phase rather than an oversight. Making a start date move a bar changes
-- every date on every project the moment somebody types one, which is a whole
-- phase of its own and belongs with crew_absences (ROADMAP, "Days off are a
-- scheduler input"). What this codebase must not do in the meantime is let the
-- gap be silent: person.php compares these dates against the work already
-- scheduled for that person and says so on screen when the plan asks for work
-- outside them. An unread column that looks like it is being obeyed is exactly
-- the class of failure lessons 7 and 11 are about.
ALTER TABLE crew_projects
  ADD COLUMN start_date DATE DEFAULT NULL AFTER allocation_pct;

ALTER TABLE crew_projects
  ADD COLUMN end_date DATE DEFAULT NULL AFTER start_date;

-- Which show a person is on in a given week, across every project — the
-- cross-project workload view that crew being global was bought for
-- (DECISIONS, 2026-08-11).
ALTER TABLE crew_projects
  ADD INDEX idx_crew_projects_dates (start_date, end_date);
