-- What we thought a step would take, frozen at the moment we committed to it.
--
-- pipeline_steps.days_per_unit / coverage_pct / planned_headcount are edited in
-- place on the Dates & headcount screen. Every edit destroys what was believed
-- before it — so the single most useful number for improving estimates, "what
-- we guessed at kickoff next to what it actually took", was being lost on every
-- project as it ran. See docs/ROADMAP.md, "Cross-project benchmarking".
--
-- Append-only. A row is written when the wizard turns a project active, and
-- again whenever someone deliberately re-baselines. Nothing ever updates a row,
-- so the history reads "we thought 2 days, revised to 3, it took 3.4" rather
-- than silently becoming "we always said 3".

CREATE TABLE IF NOT EXISTS step_estimate_baseline (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,

  -- The live step, for joining while the project is running. NULLABLE with
  -- ON DELETE SET NULL: deleting a step must not destroy the record of what it
  -- was estimated at. The columns below are what make the row survive that.
  step_id INT DEFAULT NULL,

  -- The step's identity, COPIED rather than referenced.
  --
  -- This is the whole point of the table being useful in two years. Steps get
  -- renamed, rescoped and deleted; projects get tidied after they wrap. A
  -- baseline that could only say "step 412" would be worthless by the time
  -- anyone wanted to compare it. Same reasoning as progress.assignee_raw: keep
  -- what arrived, not just the link.
  --
  -- (name, applies_to, applies_to_asset_type) is the comparison key —
  -- "Concept · Character" and "Concept · Environment" are different jobs, and
  -- averaging them together would destroy the signal this table exists for.
  step_name VARCHAR(100) NOT NULL,
  applies_to VARCHAR(20) NOT NULL DEFAULT 'shot',
  applies_to_asset_type VARCHAR(50) NOT NULL DEFAULT '',
  tracks_seconds TINYINT(1) NOT NULL DEFAULT 0,

  -- The estimate itself.
  days_per_unit DECIMAL(6,3) NOT NULL DEFAULT 1,
  coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 100,
  planned_headcount DECIMAL(5,2) DEFAULT NULL,
  retake_pct DECIMAL(5,2) NOT NULL DEFAULT 0,

  -- The scale it was estimated AGAINST. "2 days per asset" means nothing later
  -- without knowing whether that was 22 assets or 200 — estimates degrade with
  -- scale, and comparing across shows without it would be comparing noise.
  work_units DECIMAL(12,3) NOT NULL DEFAULT 0,
  work_label VARCHAR(30) NOT NULL DEFAULT '',
  units_estimated TINYINT(1) NOT NULL DEFAULT 0,   -- from a real count, or a guess?

  start_date DATE DEFAULT NULL,
  deadline DATE DEFAULT NULL,

  captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(60) NOT NULL DEFAULT 'setup complete',

  KEY idx_baseline_project (project_id),
  KEY idx_baseline_step (step_id),
  -- The cross-project lookup this table exists to serve.
  KEY idx_baseline_identity (step_name, applies_to, applies_to_asset_type),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deliberately NOT backfilled from today's pipeline_steps.
--
-- Those values have already been edited since the projects began, so writing
-- them as a "baseline" would record a revised estimate as an original one. A
-- wrong baseline is worse than a missing one: the missing one is visibly
-- missing, and the wrong one quietly poisons every average built on it. Live
-- projects get their first row the next time someone re-baselines, and it is
-- labelled as such.
