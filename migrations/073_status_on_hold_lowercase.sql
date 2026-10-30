-- 073 — "On Hold" becomes "On hold", like every other status.
--
-- David, 2026-10-29, on Spell's status dropdown: '"On Hold" has a capital H,
-- the others do not.' It came from the seed list in 004 and was copied into
-- every show's own statuses from there, so both are renamed: the shows that
-- exist and the template new shows copy from.
--
-- BINARY, so only the capitalised spelling is touched and a re-run changes
-- nothing. No code reads a status by this name: on hold is is_blocked = 1.

UPDATE status_templates SET name = 'On hold' WHERE BINARY name = 'On Hold';
UPDATE statuses SET name = 'On hold' WHERE BINARY name = 'On Hold';
