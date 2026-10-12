-- 045 — the Studio: one project row that is not a show.
--
-- David, 2026-10-12: "let's mimic the per project task/ticket UI to the Studio
-- Tasks tab. We should be able to create new tasks from there... how can I
-- adjust the project of the current tasks without no project to be the
-- global/studio wide task? We decided to create one for those tasks that are
-- not linked specifically to a project."
--
-- ⚠ THIS REVERSES THE 2026-10-07 DECISION, DELIBERATELY, AND THAT ENTRY SHOULD
-- BE READ BEFORE THIS FILE. It rejected a pseudo-project for unfiled cards, and
-- its reasoning was sound: "Studio Tasks is a read-only list with filters — it
-- has no columns, so it needs no statuses, so option 1's entire advantage bought
-- nothing while its cost stayed."
--
-- THE PREMISE IS NO LONGER TRUE. David has now asked for columns, for statuses
-- and for create buttons — the board that entry said he had not asked for. That
-- entry named the shape of the requirement as the deciding factor, and the
-- requirement changed. A board needs a status vocabulary, a sequence and a
-- create flow; a project is the thing in this schema that already has all three.
-- Inventing a parallel set for project_id IS NULL would be two implementations
-- of one idea, which this codebase refuses on principle.
--
-- ⚠ AND THE COST IT NAMED IS REAL AND MUST BE PAID. It predicted "an inbox
-- appearing as a show with no delivery date and 0% progress on the projects
-- page, the dashboard, finance, the switcher and in bookings, forever." That is
-- exactly what `is_studio` exists to prevent: every query that LISTS projects
-- filters it out, and the list of those queries is enumerated in
-- includes/studio.php rather than left to be rediscovered. Two pickers include
-- it on purpose — the ticket page's "move to project" and the transcript's —
-- because those are how a card gets filed here.
--
-- ⚠ A NULL PROJECT STILL MEANS WHAT IT MEANT. This does not take capture-first
-- away. A card can still arrive with no project, which is the whole point of
-- migration 041 and still the right default when nobody knows where it belongs.
-- The Studio is a DESTINATION somebody chooses, not a dumping ground that
-- unfiled cards fall into automatically — nothing in this migration moves an
-- existing row.

ALTER TABLE projects
  ADD COLUMN is_studio TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- ONE studio, enforced by `code` already being UNIQUE rather than by a partial
-- index, which MySQL does not have. Re-running this migration cannot create a
-- second one, and INSERT IGNORE makes the file safe to apply twice.
--
-- Status 'active' because the archived filters elsewhere key on it and a studio
-- that read as archived would vanish from the two pickers that must show it.
-- type 'Other': it is not a 2D or 3D show, and project_types.php lists by type.
INSERT IGNORE INTO projects (code, name, type, status, is_studio, fps)
  VALUES ('STUDIO', 'Studio', 'Other', 'active', 1, 24);

-- Its own status vocabulary, copied from the templates exactly as
-- seed_project_statuses() does for a new show — same source, so the Studio's
-- board columns behave identically to every other board and the Statuses screen
-- can edit them. INSERT IGNORE for the same re-run safety.
--
-- is_excluded arrived in migration 010 and on_ticket_board in 017, so both are
-- present by the time this runs and can be named unconditionally.
INSERT IGNORE INTO statuses
  (project_id, name, sort_order, maps_to, is_blocked, color, is_excluded, on_ticket_board)
  SELECT p.id, t.name, t.sort_order, t.maps_to, t.is_blocked, t.color,
         t.is_excluded, t.on_ticket_board
    FROM status_templates t
    CROSS JOIN projects p
   WHERE p.code = 'STUDIO';
