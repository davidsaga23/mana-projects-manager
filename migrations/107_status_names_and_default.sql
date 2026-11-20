-- 107 — Status names, and Waiting to start as the default.
--
-- David, 2026-11-20, on Spell's status list: "Ready for review: Ready for
-- approval", "Completed: Approved", then "let's also delete the not started and
-- make Waiting to Start the default status".
--
-- 1. The renames, on every show and on the defaults new shows copy. The code
--    already treats the old and new spellings as the same status
--    (REQUIRED_STATUS_NAMES, REVIEW_NAME_ALIASES), so Reviews keeps working
--    before and after this runs. UPDATE IGNORE skips a show that somehow has
--    both spellings already, rather than failing on the unique name.
-- 2. "Not started" was never a status row: it is what Spell drew for a task
--    with no status recorded. Those tasks get their show's "Waiting to start".
--    A show without that status is left alone. Re-runs harmlessly: the WHERE
--    only ever finds rows still blank.

UPDATE IGNORE statuses SET name = 'Ready for approval' WHERE LOWER(TRIM(name)) = 'ready for review';
UPDATE IGNORE statuses SET name = 'Approved' WHERE LOWER(TRIM(name)) IN ('completed', 'complete');
UPDATE IGNORE status_templates SET name = 'Ready for approval' WHERE LOWER(TRIM(name)) = 'ready for review';
UPDATE IGNORE status_templates SET name = 'Approved' WHERE LOWER(TRIM(name)) IN ('completed', 'complete');

UPDATE progress pr
  JOIN statuses s ON s.project_id = pr.project_id AND LOWER(TRIM(s.name)) = 'waiting to start'
   SET pr.status_id = s.id
 WHERE pr.status_id IS NULL;
