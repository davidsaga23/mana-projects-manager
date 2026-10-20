-- 053 — My tasks has a "last looked at" time, so the nav badge can tell NEW from
-- merely OPEN.
--
-- David, 2026-10-20: "add a number below My Tasks that show the active task
-- number. If there's a new one change the color of the circle so users know
-- there's a new task for them." The count is just a query; the COLOUR needs a
-- second fact the database did not hold — when this person last opened their
-- list. Without it, "new" has nothing to be new SINCE, and the circle would
-- either always be lit or never.
--
-- One column on users, the login (not crew): the badge is drawn for whoever is
-- signed in, and a person is linked to a crew row through users.crew_id. A task
-- counts as NEW when it was created after this time; opening My tasks stamps it
-- to NOW(), which clears the colour. Same shape as migration 040's mention
-- seen_at — a "somebody looked" fact with no form to submit, written on a GET.
--
-- ⚠ EXISTING LOGINS ARE STAMPED TO NOW(), not left NULL. A fresh column would
-- read as "never looked", so every ticket already on every desk would light up
-- as new the moment this ships — a studio-wide false alarm on day one. Stamping
-- the backlog as already-seen means only tasks created AFTER the update can be
-- new, which is what the feature is for. A login created later keeps the NULL
-- default and sees its first tasks as new, which is correct for a new person.
--
-- Two statements, applied in order by migrate.php's splitter.

ALTER TABLE users
  ADD COLUMN mytasks_seen_at DATETIME DEFAULT NULL AFTER crew_id;

UPDATE users SET mytasks_seen_at = NOW();
