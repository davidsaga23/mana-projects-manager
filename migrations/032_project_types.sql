-- 032 — project types somebody can add.
--
-- David, 2026-09-16: "I think we should have a way to manually create new
-- project types then because right now it doesn't offer that possibility."
--
-- The show that asked for it is a 2D motion comic. It IS 2D, but its steps and
-- what each one takes are not a 2D series', and ideal quotas are kept one sheet
-- per project type. With the four types fixed in the schema the only choices
-- were to change the 2D sheet for every 2D show, or to file the comic under
-- Other and lose what Other meant.
--
-- WHY A TABLE AND NOT A LONGER ENUM. An ENUM is a list only a migration can
-- extend: the "a fixed list in code is what forced a schema change on STFC when
-- Props arrived" trap (2026-08-11), which asset types, departments and
-- seniorities already learned.
--
-- ADDITIVE, and safe to re-run: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, and a
-- MODIFY to the definition a column already has changes nothing the second time.

CREATE TABLE IF NOT EXISTS project_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- 20, matching quota_standards.project_type (026). A name longer than the
  -- column it is written into would be cut short there and nowhere else.
  name VARCHAR(20) NOT NULL,
  -- The order the tabs and pickers list them in. The four that shipped keep
  -- their order at the front; types added later join the end.
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(100) DEFAULT NULL,
  -- Unique under the table's case-insensitive collation, so "Motion comic" and
  -- "motion comic" cannot both exist. To MySQL they are already one type (every
  -- WHERE type = ? matches either spelling); to a picker they would be two.
  UNIQUE KEY uniq_project_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO project_types (name, sort_order) VALUES
  ('2D', 1), ('3D', 2), ('Mixed', 3), ('Other', 4);

-- An ideal quotas sheet already written for a type outside those four joins the
-- list, rather than becoming a sheet no screen offers a way to reach. Nothing
-- should match on Mana's install; this is for the one where something does.
INSERT IGNORE INTO project_types (name, sort_order)
SELECT DISTINCT project_type, 100 FROM quota_standards WHERE project_type <> '';

-- The two columns that were ENUMs. MODIFY keeps every stored value, because an
-- ENUM value converts to exactly its own text. The default stays 3D.
ALTER TABLE projects
  MODIFY type VARCHAR(20) NOT NULL DEFAULT '3D';

ALTER TABLE pipeline_templates
  MODIFY project_type VARCHAR(20) NOT NULL;
