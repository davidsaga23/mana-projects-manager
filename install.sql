-- ============================================================================
-- Mana Production Platform — baseline schema (v1)
--
-- FRESH INSTALLS ONLY. This file DROPS every table it creates.
-- NEVER run it against a live database.
--
-- Install procedure:
--   1. Create an empty database in cPanel > MySQL Databases
--   2. Import this file in phpMyAdmin (Import tab)
--   3. Open install.php in the browser to create the admin user
--   4. Open migrate.php to apply any migrations added since this baseline
--
-- This file stays frozen as the v1 baseline. Schema changes after this point
-- go in migrations/ as numbered .sql files — see migrations/README.md.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- MIGRATION LEDGER ----------------------------------------------------------
-- migrate.php compares migrations/*.sql against these rows and applies
-- whatever is missing.
DROP TABLE IF EXISTS schema_migrations;
CREATE TABLE schema_migrations (
  filename VARCHAR(255) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (filename) VALUES ('000_baseline');

-- USERS (login) -------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PROJECTS ------------------------------------------------------------------
DROP TABLE IF EXISTS projects;
CREATE TABLE projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) UNIQUE NOT NULL,            -- short slug, e.g. STFC2
  name VARCHAR(255) NOT NULL,
  type ENUM('2D','3D','Mixed','Other') NOT NULL DEFAULT '3D',
  status ENUM('setup','active','on_hold','wrapped') NOT NULL DEFAULT 'setup',
  fps INT NOT NULL DEFAULT 24,
  start_date DATE DEFAULT NULL,
  delivery_date DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-project configuration (key/value, same pattern as STFC's settings table
-- but scoped). Truly global config (SMTP, upload limits) stays in config.php.
DROP TABLE IF EXISTS project_settings;
CREATE TABLE project_settings (
  project_id INT NOT NULL,
  setting_key VARCHAR(50) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  PRIMARY KEY (project_id, setting_key),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PIPELINE (steps are data; the workflow is a dependency graph) --------------
DROP TABLE IF EXISTS pipeline_steps;
CREATE TABLE pipeline_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  step_order INT NOT NULL DEFAULT 0,           -- display order only; scheduling follows step_dependencies
  tracks_seconds TINYINT(1) NOT NULL DEFAULT 0,-- seconds-based vs count-based progress
  applies_to ENUM('shot','asset','both') NOT NULL DEFAULT 'shot',
  deadline DATE DEFAULT NULL,
  show_in_dashboard TINYINT(1) NOT NULL DEFAULT 1,
  color VARCHAR(7) DEFAULT NULL,               -- e.g. #4A90D9
  UNIQUE KEY uniq_project_step (project_id, name),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Two steps sharing a dependency run in parallel; a step depending on both
-- waits for the later one. Replaces STFC's hardcoded $overlapsWithPrevious.
-- The application MUST reject cycles when saving rows here (the scheduler
-- walks this graph and a cycle would hang it).
DROP TABLE IF EXISTS step_dependencies;
CREATE TABLE step_dependencies (
  step_id INT NOT NULL,
  depends_on_step_id INT NOT NULL,
  PRIMARY KEY (step_id, depends_on_step_id),
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (depends_on_step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SHOTS AND ASSETS -----------------------------------------------------------
DROP TABLE IF EXISTS shots;
CREATE TABLE shots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  episode VARCHAR(20) NOT NULL DEFAULT '',     -- empty for single-episode projects
  shot_code VARCHAR(30) NOT NULL,
  frames INT DEFAULT NULL,
  placeholder_seconds DECIMAL(6,3) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  external_id VARCHAR(50) DEFAULT NULL,        -- e.g. Plaky item id
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_shot (project_id, episode, shot_code),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS assets;
CREATE TABLE assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  asset_type VARCHAR(50) DEFAULT NULL,         -- Character / Environment / Prop / …
  notes TEXT DEFAULT NULL,
  external_id VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_asset (project_id, name),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One join table for every asset type — STFC needed a schema change to add
-- Props because it had one table per type; this avoids that.
DROP TABLE IF EXISTS shot_assets;
CREATE TABLE shot_assets (
  shot_id INT NOT NULL,
  asset_id INT NOT NULL,
  PRIMARY KEY (shot_id, asset_id),
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREW (global — people exist once, membership is per project) ---------------
DROP TABLE IF EXISTS crew;
CREATE TABLE crew (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  role VARCHAR(100) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  is_team TINYINT(1) NOT NULL DEFAULT 0,       -- non-person assignee like "Animation";
                                               -- valid for tickets, excluded from
                                               -- scheduling and per-artist reporting
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS crew_projects;
CREATE TABLE crew_projects (
  crew_id INT NOT NULL,
  project_id INT NOT NULL,
  allocation_pct INT NOT NULL DEFAULT 100,     -- someone half-time on two shows: 50/50
  PRIMARY KEY (crew_id, project_id),
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which steps a person can work on. step_id is project-scoped, so skills are
-- effectively per project (accepted in the decision log).
DROP TABLE IF EXISTS crew_skills;
CREATE TABLE crew_skills (
  crew_id INT NOT NULL,
  step_id INT NOT NULL,
  PRIMARY KEY (crew_id, step_id),
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global alias -> person ("Animator.wasi" -> Wasi Ali Khan). A person's
-- external handle is the same across projects, so this is global; the
-- project-scoped name_aliases below can override it per project.
-- Resolution order: name_aliases (project) first, then crew_aliases (global).
DROP TABLE IF EXISTS crew_aliases;
CREATE TABLE crew_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alias_name VARCHAR(100) UNIQUE NOT NULL,
  crew_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Project-scoped alias layer for names arriving from outside (Plaky, CSVs):
-- "RomulanOfficer01" -> "Romulan Officer". Importers resolve through this and
-- report anything unmatched — never silently drop into an "unassigned" bucket.
DROP TABLE IF EXISTS name_aliases;
CREATE TABLE name_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  alias VARCHAR(255) NOT NULL,
  canonical_name VARCHAR(255) NOT NULL,
  kind ENUM('asset','crew','step') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alias (project_id, kind, alias),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PROGRESS (one table for shots AND assets; steps can apply to either) -------
-- Exactly one of shot_id / asset_id is set per row. MySQL unique indexes
-- ignore NULLs, so uniqueness per (step, item) is enforced through item_key,
-- a stored generated column: 's<shot_id>' or 'a<asset_id>'.
DROP TABLE IF EXISTS progress;
CREATE TABLE progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  shot_id INT DEFAULT NULL,
  asset_id INT DEFAULT NULL,
  item_key VARCHAR(20) GENERATED ALWAYS AS
    (CONCAT(IF(shot_id IS NULL, 'a', 's'), COALESCE(shot_id, asset_id, 0))) STORED,
  status VARCHAR(100) DEFAULT NULL,            -- raw external status text
  is_delivered TINYINT(1) NOT NULL DEFAULT 0,
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  crew_id INT DEFAULT NULL,                    -- resolved assignee
  assignee_raw VARCHAR(100) DEFAULT NULL,      -- what the source actually said;
                                               -- kept so unmatched-name reporting works
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_step_item (step_id, item_key),
  KEY idx_progress_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SCHEDULING -----------------------------------------------------------------
-- start_date and end_date are both INCLUSIVE — everywhere in the DB, API and
-- UI. Convert only at the boundary of external tools (Frappe Gantt,
-- GanttProject). Decided once; see docs/DECISIONS.md.
DROP TABLE IF EXISTS schedule;
CREATE TABLE schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  shot_id INT DEFAULT NULL,
  asset_id INT DEFAULT NULL,
  item_key VARCHAR(20) GENERATED ALWAYS AS
    (CONCAT(IF(shot_id IS NULL, 'a', 's'), COALESCE(shot_id, asset_id, 0))) STORED,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,                      -- inclusive last working day
  crew_id INT DEFAULT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,     -- hand-planned or .gan-imported;
                                               -- the auto-scheduler must not touch these
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_step_item (step_id, item_key),
  KEY idx_schedule_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS headcount_log;
CREATE TABLE headcount_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  log_date DATE NOT NULL,
  headcount INT NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_step_date (step_id, log_date),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS daily_snapshots;
CREATE TABLE daily_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  step_id INT NOT NULL,
  snapshot_date DATE NOT NULL,
  shots_delivered_cum INT NOT NULL DEFAULT 0,
  shots_approved_cum INT NOT NULL DEFAULT 0,
  seconds_delivered_cum DECIMAL(8,3) NOT NULL DEFAULT 0,
  seconds_approved_cum DECIMAL(8,3) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_step_date (step_id, snapshot_date),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PIPELINE TEMPLATES (seed data for the setup wizard, not hardcoded logic) ---
-- The wizard copies a template's steps + dependencies into pipeline_steps /
-- step_dependencies for the new project, where they become fully editable.
-- Editing a template never affects existing projects.
DROP TABLE IF EXISTS pipeline_template_deps;
DROP TABLE IF EXISTS pipeline_template_steps;
DROP TABLE IF EXISTS pipeline_templates;
CREATE TABLE pipeline_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  project_type ENUM('2D','3D','Mixed','Other') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE pipeline_template_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  step_order INT NOT NULL DEFAULT 0,
  tracks_seconds TINYINT(1) NOT NULL DEFAULT 0,
  applies_to ENUM('shot','asset','both') NOT NULL DEFAULT 'shot',
  color VARCHAR(7) DEFAULT NULL,
  UNIQUE KEY uniq_template_step (template_id, name),
  FOREIGN KEY (template_id) REFERENCES pipeline_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE pipeline_template_deps (
  step_id INT NOT NULL,
  depends_on_step_id INT NOT NULL,
  PRIMARY KEY (step_id, depends_on_step_id),
  FOREIGN KEY (step_id) REFERENCES pipeline_template_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (depends_on_step_id) REFERENCES pipeline_template_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3D template: the STFC pipeline shape.
--   Shots:  3D Layout -> Animation -> Assembly -> (Lighting || Render) -> Comp
--   Assets: Concept -> Modeling -> Blendshapes -> Rigging -> LookDev
INSERT INTO pipeline_templates (id, name, project_type) VALUES (1, '3D — standard', '3D');
INSERT INTO pipeline_template_steps (id, template_id, name, step_order, tracks_seconds, applies_to, color) VALUES
  (101, 1, '3D Layout',   1, 0, 'shot',  '#8E7CC3'),
  (102, 1, 'Animation',   2, 1, 'shot',  '#4A90D9'),
  (103, 1, 'Assembly',    3, 0, 'shot',  '#45B8AC'),
  (104, 1, 'Lighting',    4, 0, 'shot',  '#F5B041'),
  (105, 1, 'Render',      5, 0, 'shot',  '#E67E22'),
  (106, 1, 'Comp',        6, 0, 'shot',  '#E74C3C'),
  (111, 1, 'Concept',     1, 0, 'asset', '#95A5A6'),
  (112, 1, 'Modeling',    2, 0, 'asset', '#7FB3D5'),
  (113, 1, 'Blendshapes', 3, 0, 'asset', '#76D7C4'),
  (114, 1, 'Rigging',     4, 0, 'asset', '#F7DC6F'),
  (115, 1, 'LookDev',     5, 0, 'asset', '#D98880');
INSERT INTO pipeline_template_deps (step_id, depends_on_step_id) VALUES
  (102, 101), (103, 102),
  (104, 103), (105, 103),          -- Lighting and Render both depend on Assembly -> parallel
  (106, 104), (106, 105),          -- Comp waits for both
  (112, 111), (113, 112), (114, 113), (115, 114);

-- 2D template: a common shape, fully editable in the wizard.
--   Shots:  Layout -> Rough Animation -> Cleanup -> Color -> Comp
--   Assets: Design -> Color Model
INSERT INTO pipeline_templates (id, name, project_type) VALUES (2, '2D — standard', '2D');
INSERT INTO pipeline_template_steps (id, template_id, name, step_order, tracks_seconds, applies_to, color) VALUES
  (201, 2, 'Layout',          1, 0, 'shot',  '#8E7CC3'),
  (202, 2, 'Rough Animation', 2, 1, 'shot',  '#4A90D9'),
  (203, 2, 'Cleanup',         3, 0, 'shot',  '#45B8AC'),
  (204, 2, 'Color',           4, 0, 'shot',  '#F5B041'),
  (205, 2, 'Comp',            5, 0, 'shot',  '#E74C3C'),
  (211, 2, 'Design',          1, 0, 'asset', '#95A5A6'),
  (212, 2, 'Color Model',     2, 0, 'asset', '#D98880');
INSERT INTO pipeline_template_deps (step_id, depends_on_step_id) VALUES
  (202, 201), (203, 202), (204, 203), (205, 204),
  (212, 211);

SET FOREIGN_KEY_CHECKS = 1;
