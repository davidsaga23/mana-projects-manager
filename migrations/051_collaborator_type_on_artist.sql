-- 051 — Freelance or Vendor moves from the staffing position to the PERSON.
--
-- David, 2026-10-17, the same evening 050 shipped: "I think the type belongs to
-- the person so we can identify that properly. Let's include the column for
-- freelance and vendor in the artist database and add freelance to all the
-- current registries."
--
-- So the type lives in the artist database (`hires`), beside position and
-- seniority, and is written across every row bearing the person's name the way
-- artists_save_person() writes every other personal field. The Staffing screen
-- reads it for whoever holds a position, and person.php edits it.
--
-- WHAT THIS GIVES UP, stated once: a studio that is a vendor on one show is a
-- vendor on every show, and an artist booked through a vendor once and directly
-- later has to be switched by hand. David's call, with that on the table.
--
-- 'freelance' is the default, so every record already in the book reads as
-- Freelance the moment this runs ("add freelance to all the current registries").
ALTER TABLE hires
  ADD COLUMN collaborator_type ENUM('freelance','vendor') NOT NULL DEFAULT 'freelance' AFTER seniority;

-- Anybody already marked Vendor on a position under 050 keeps it. 050 always
-- runs before this file, so the column is there to read.
UPDATE hires h
  JOIN crew_lanes cl ON cl.crew_id = h.crew_id
   SET h.collaborator_type = 'vendor'
 WHERE cl.collaborator_type = 'vendor';

-- And the per-position column goes, so there is one place this is true.
ALTER TABLE crew_lanes
  DROP COLUMN collaborator_type;
