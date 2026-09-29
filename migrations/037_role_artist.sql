-- 037 — the read-only role becomes 'artist', and is no longer entirely read-only.
--
-- David, 2026-09-28: "there's one thing that the viewer permission settings
-- should allow and it's the ability to adjust or change their status on their
-- tasks or tickets. Let's also change the user permission group to artist."
--
-- THE NAME FOLLOWS THE JOB. 'viewer' described a limit; 'artist' describes the
-- person. It is the same role either way — everything on the platform is
-- readable, nothing is editable — with one hole cut in it, in one place:
--
--   admin   — everything, including managing these accounts
--   editor  — all production work; cannot add or change users
--   artist  — reads everything, and sets the status of work assigned to THEM,
--             on My tasks and nowhere else. See artist_may_post() in
--             includes/auth.php, which names the exact POSTs allowed.
--
-- ⚠ AN ARTIST STILL CANNOT LINK THEMSELVES TO A CREW MEMBER. That form on My
-- tasks accepts any crew id, so a self-link is a way to claim to be somebody
-- else and then change their work. An admin does the linking on the Users
-- screen, which already validates the choice. Artists see a message there
-- instead of the form.
--
-- TWO ALTERs, NOT ONE. The new value is added first, the rows are moved, and
-- only then is the old value dropped — so no row is ever holding a value its
-- own column does not allow. Doing it in one statement would ask MySQL to
-- rewrite every row against an ENUM that no longer contains what they hold,
-- and 'viewer' would silently become '' on a non-strict server.
--
-- Each statement is safe to run twice, so a failed run can simply be run again.

-- 1. Make room for the new name while the old one still exists.
ALTER TABLE users
  MODIFY role ENUM('admin','editor','artist','viewer') NOT NULL DEFAULT 'editor';

-- 2. Move everybody across.
UPDATE users SET role = 'artist' WHERE role = 'viewer';

-- 3. Retire the old name. Nothing holds it by now.
ALTER TABLE users
  MODIFY role ENUM('admin','editor','artist') NOT NULL DEFAULT 'editor';
