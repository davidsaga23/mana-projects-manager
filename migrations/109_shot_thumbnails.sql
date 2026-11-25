-- 109 — a picture per shot, put up by hand.
--
-- David, 2026-11-25, on the shot breakdown: "let's add a way to manually add a
-- thumbnail per shot. When the first task version is available, it should
-- override the original thumbnail and always use the latest version of the
-- latest department. We want an easy way to visualize in the breakdown (and
-- open a preview) of the shot so we can easily identify it."
--
-- So this table holds ONLY the picture somebody put up by hand: a frame of the
-- animatic, a storyboard panel. The picture from the versions is never stored
-- here; it is read from progress_versions each time the page is drawn
-- (breakdown_thumbs() in includes/breakdown.php), so it is always the newest
-- one and nothing has to be kept in step.
--
-- One picture per shot (UNIQUE shot_id): putting up another replaces it. The
-- file itself sits in uploads/tickets with every other upload, under a random
-- name, and is served through attachment.php?kind=shot behind the login.
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS shot_thumbnails (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  shot_id       INT NOT NULL,
  project_id    INT NOT NULL,
  stored_name   VARCHAR(64) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  file_size     INT NOT NULL DEFAULT 0,
  uploaded_by   INT DEFAULT NULL,
  uploaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shot (shot_id),
  KEY idx_project (project_id),
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
