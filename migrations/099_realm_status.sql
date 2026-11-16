-- 099 — The Realm: status messages ("Lunch", "In review until 3"), and lunch in the Diner.
--
-- David, 2026-11-16: "Status messages: Let's also create a diner so people's
-- avatars go immediately there when they set up their status 'Lunch'".
--
-- realm_people gains one status per person, set by that person from the Realm:
--   status_kind   'lunch', 'review', 'focus', 'sick', 'off' (the ready-made
--                 ones, includes/realm_status.php) or 'custom'; NULL = none
--   status_emoji  the emoji shown beside the name, from a fixed list
--   status_text   the words, at most 80 characters
--   status_until  when it clears by itself; NULL = only when cleared by hand.
--                 The server clears it, not the page (realm_status_sweep()).
--   status_from   'self', or 'calendar' once the days-off calendar sets "Off"
--   status_back   where the character was when lunch began ('place|inside'),
--                 in case David chooses "back where they were" over "home"
--
-- The Diner itself is not a row anywhere: like the Tavern it is a place id
-- ('diner') and a chat channel of the same name, which need no table.
--
-- Until this runs the Realm works as before and the status button says an
-- update is needed. All six columns arrive in one statement, so a run that
-- fails adds none and can simply be run again.

ALTER TABLE realm_people
  ADD COLUMN status_kind  VARCHAR(12)  DEFAULT NULL,
  ADD COLUMN status_emoji VARCHAR(16)  DEFAULT NULL,
  ADD COLUMN status_text  VARCHAR(100) DEFAULT NULL,
  ADD COLUMN status_until DATETIME     DEFAULT NULL,
  ADD COLUMN status_from  VARCHAR(10)  DEFAULT NULL,
  ADD COLUMN status_back  VARCHAR(48)  DEFAULT NULL,
  ADD KEY idx_realm_status_until (status_until);
