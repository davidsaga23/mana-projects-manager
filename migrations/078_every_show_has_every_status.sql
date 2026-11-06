-- 078 — Every show gets every default status it is missing.
--
-- David, 2026-11-06: "let's make sure we always have complete status (all of them
-- actually) for all the projects". FCM had no "Complete", so Reviews refused to
-- approve there. A show copies the default list (status_templates) once, when the
-- wizard creates it; a show made before a default existed, or brought in some
-- other way, never received it afterwards.
--
-- ADDS ONLY. Nothing is renamed, recoloured, re-ordered or removed: a show that
-- already has a status of that name (case and spacing as MySQL compares them)
-- keeps its own, through INSERT IGNORE on uniq_project_status.
--
-- "Completed" (the default's spelling) is skipped on a show that already has a
-- "Complete": Reviews treats the two as the same status (REQUIRED_STATUS_NAMES in
-- includes/statuses.php), and a second one would only be a duplicate column.
--
-- Safe to run twice.

INSERT IGNORE INTO statuses
  (project_id, name, sort_order, maps_to, is_blocked, color, is_excluded, on_ticket_board)
  SELECT p.id, t.name, t.sort_order, t.maps_to, t.is_blocked, t.color, t.is_excluded, t.on_ticket_board
    FROM projects p
    CROSS JOIN status_templates t
   WHERE NOT (t.name = 'Completed' AND EXISTS (
           SELECT 1 FROM statuses s WHERE s.project_id = p.id AND s.name = 'Complete'));

-- The review stages need these even where the default list does not name them
-- (a "Complete" for a show that has neither spelling comes from the default
-- "Completed" above). Retake, In progress, Pending review and Ready for review
-- are all in the default list, so the insert above already covers them.
