-- Phase 11 — the project brief. Step 3 of the production cycle (ROADMAP).
--
-- A project has begun here at "name, code, type, fps, delivery date" since
-- Phase 2. What the client actually ASKED FOR has had nowhere to live: the
-- deliverables and their formats, the reference, who to call. It sits in email,
-- and it is the document somebody goes looking for in week six — after the
-- person who received it has moved on to the next show.
--
-- WHY ITS OWN TABLE AND NOT COLUMNS ON `projects`
-- `projects` is read on nearly every screen in the app — the nav bar alone loads
-- it on every page view. Hanging four TEXT columns off it means dragging the
-- whole brief through memory to render a tab bar. A brief is read on one screen,
-- deliberately, so it loads on one screen.
--
-- WHY NOT project_settings
-- That table is VARCHAR(255) key/value, which is right for "episodic = 1" and
-- useless for three paragraphs about deliverables. Putting long text there would
-- silently truncate the thing this migration exists to preserve.

CREATE TABLE IF NOT EXISTS project_brief (
  project_id INT NOT NULL PRIMARY KEY,

  client VARCHAR(200) DEFAULT NULL,
  client_contact VARCHAR(200) DEFAULT NULL,

  -- What was asked for, in prose. Deliberately free text and not a set of
  -- fields: every brief is shaped differently, and a form with the wrong boxes
  -- is worse than a blank page — people leave out what does not fit.
  summary TEXT DEFAULT NULL,

  -- What is actually handed over: formats, durations, resolutions, quantities.
  -- Kept apart from the summary because it is the half people re-read, and the
  -- half a dispute is about.
  deliverables TEXT DEFAULT NULL,

  -- NOT `references` — that is a reserved word in MySQL and the CREATE would
  -- fail on it. Links and notes about look, tone, boards.
  reference_links TEXT DEFAULT NULL,

  notes TEXT DEFAULT NULL,

  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL,

  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Files attached to a project: the client's PDF, a reference board, a signed
-- scope. Mirrors ticket_attachments deliberately rather than being merged with
-- it — the two have different owners and different lifetimes, and one table with
-- a nullable ticket_id and a nullable project_id is the shape MySQL's unique
-- indexes handle badly (the reason `item_key` exists at all, 2026-08-11).
--
-- The FILES live in the same uploads/tickets directory and go through the same
-- validation in includes/uploads.php: extension and detected MIME both
-- whitelisted, random stored names, served only through attachment.php behind
-- the login. One upload implementation, because that is the part where a second
-- copy becomes a security hole.
CREATE TABLE IF NOT EXISTS project_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  file_size INT DEFAULT NULL,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stored (stored_name),
  KEY idx_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
