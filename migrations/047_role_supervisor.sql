-- 047 — a fourth role, 'supervisor': an artist who also runs three screens.
--
-- David, 2026-10-16: "We need a supervisor role who has all the artist
-- permissions but adding the timeline, staffing, shots."
--
--   admin       — everything, including managing these accounts
--   editor      — all production work; cannot add or change users
--   supervisor  — everything an artist can, PLUS sees and edits a project's
--                 Timeline, Staffing and Shots. See SUPERVISOR_SCRIPTS in
--                 includes/auth.php, which names the exact files.
--   artist      — reads everything, and sets the status of work assigned to THEM
--
-- ⚠ A SUPERVISOR STILL SEES NO MONEY. Rates and quotes on Staffing are gated by
-- can_see_money(), which is admin-only and unchanged.
--
-- ONE ALTER, ADDING A VALUE. Nobody's role changes; existing rows keep what they
-- hold. Safe to run twice.

ALTER TABLE users
  MODIFY role ENUM('admin','editor','supervisor','artist') NOT NULL DEFAULT 'editor';
