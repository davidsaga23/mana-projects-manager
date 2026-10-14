-- 046 — CBB (Could be better).
--
-- David, 2026-10-14: "can you add a new status call CBB (Could be better)".
--
-- A dailies verdict: the work has been looked at and is usable, but it is not
-- what it should be. It is the state between "Retake" (go again) and "Final"
-- (done properly) — the shot you would improve if the schedule allowed.
--
-- ⚠ IT DOES NOT COUNT AS FINISHED. maps_to = 'none', chosen by David when asked
-- directly, and it is the single most consequential field on this row. maps_to
-- is what every progress figure in the platform reads: the dashboard's
-- "Approved n of N", step completion, the scheduler's decision to stop planning
-- an item, and the expense forecast that follows from it. Marking a shot CBB
-- therefore leaves it in Remaining and keeps it on the plan. That is the point —
-- CBB here means "accepted for now, come back to it", not "signed off".
--
-- If that ever changes, it changes on the Statuses screen rather than here, and
-- it changes the history of every CBB row at once. Worth knowing before flipping
-- it: nothing is recomputed and stored, so the numbers simply read differently
-- from that moment on.
--
-- ⚠ AND IT IS A BOARD COLUMN. on_ticket_board = 1, also David's call, so CBB
-- appears as a seventh column on every project's Tasks board and in the Status
-- dropdown of the New task / New ticket dialogs. This is a deliberate exception
-- to the reasoning in migration 017, which kept N/A and Retake OFF the board on
-- the grounds that a verdict as a column invites a card to be PARKED there
-- rather than resolved. The same risk applies to CBB — it is now a place a card
-- can sit indefinitely while reading as progress. It is a column because David
-- asked for one, knowing it widens the board.
--
-- SORT ORDER 9, WHICH DELIBERATELY TIES WITH "In client review".
-- The existing ladder is N/A 1 ... Pending review 8, In client review 9,
-- Completed 10, Final 11, and CBB belongs between Pending review and Completed —
-- after a verdict, before done. There is no integer between 8 and 9, and
-- renumbering the rows above it would rewrite sort orders that projects may have
-- customised on the Statuses screen. A tie is harmless instead: ordering is
-- `ORDER BY sort_order, name` everywhere, so CBB sorts before "In client review"
-- on its name, and on the BOARD the two never meet at all because In client
-- review is not a column. Nothing existing is touched.
--
-- COLOUR: a dark amber, picked to sit apart from the two nearest things on
-- screen — On Hold's brighter orange (#E67E22) and Pending review's pale yellow
-- (#F7DC6F) — so a column of CBB cards is not mistaken for either at a glance.
-- Rename it, recolour it or move it per project on the Statuses screen; this is
-- a starting vocabulary, not a fixed one.

-- For every project created from here on.
INSERT IGNORE INTO status_templates
  (name, sort_order, maps_to, is_blocked, color, is_excluded, on_ticket_board)
  VALUES ('CBB', 9, 'none', 0, '#B9770E', 0, 1);

-- And for every project that already exists, including the Studio. INSERT IGNORE
-- against uniq_project_status (project_id, name) makes this safe to re-run and
-- leaves alone any project that has already invented its own "CBB".
INSERT IGNORE INTO statuses
  (project_id, name, sort_order, maps_to, is_blocked, color, is_excluded, on_ticket_board)
  SELECT p.id, 'CBB', 9, 'none', 0, '#B9770E', 0, 1
    FROM projects p;
