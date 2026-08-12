-- A remembered list of pipeline step names, shared across all projects.
--
-- Adding "Matte Painting" by hand on one show should mean it is suggested on
-- the next one, with the settings it had last time — rather than being retyped
-- and possibly spelled differently, which is how two names for one thing start.
--
-- This is a suggestion list, NOT a constraint: a project's steps are still its
-- own rows in pipeline_steps, and nothing here limits what can be typed.
-- Deliberately global (no project_id) — that is the whole point of it.

CREATE TABLE IF NOT EXISTS step_library (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  applies_to ENUM('shot','asset','both') NOT NULL DEFAULT 'shot',
  tracks_seconds TINYINT(1) NOT NULL DEFAULT 0,
  color VARCHAR(7) DEFAULT NULL,
  times_used INT NOT NULL DEFAULT 0,
  last_used TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed from the built-in templates so the list is useful immediately.
INSERT IGNORE INTO step_library (name, applies_to, tracks_seconds, color)
SELECT name, MIN(applies_to), MAX(tracks_seconds), MIN(color)
  FROM pipeline_template_steps GROUP BY name;

-- And from any steps already created in real projects.
INSERT IGNORE INTO step_library (name, applies_to, tracks_seconds, color)
SELECT name, MIN(applies_to), MAX(tracks_seconds), MIN(color)
  FROM pipeline_steps GROUP BY name;
