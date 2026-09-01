-- 030 — an artist can exist without a project.
--
-- David, 2026-08-31, on the studio-wide Artists screen: "add an option to add 5
-- new artists to the database."
--
-- `hires.project_id` has been NOT NULL since 024, which was right while the only
-- way in was a project's Hiring screen: every row there is a conversation about
-- a slot on that show. The Artists list is the other thing -- the studio's book
-- of people -- and requiring a project to write somebody into it would mean
-- filing a freelancer you met at a festival under whichever show happened to be
-- open at the time. That is not a fact about them, and it would be wrong in the
-- one place it is most likely to be read.
--
-- NULL now means exactly what it says: known to the studio, not up for anything
-- yet. The Hiring screens scope every query by project_id, so those rows simply
-- do not appear there until somebody is put forward for a slot.
--
-- ADDITIVE. Widening a column to accept NULL destroys nothing: every existing
-- row keeps the project it has. The foreign key stays as it is -- ON DELETE
-- CASCADE still removes the rows that DO name a project when that project goes,
-- and a NULL simply never matches.

ALTER TABLE hires
  MODIFY project_id INT DEFAULT NULL;
