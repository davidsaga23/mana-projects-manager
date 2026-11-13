-- 087 — The Realm: the spot each character is standing on, so others see it walk.
--
-- David, 2026-11-13: "let's also have the option for the avatars to move freely
-- inside each house", and, asked whether the others should see it: "Yes", then
-- "let's make see other people walk a feature across the board".
--
-- realm_people.spot_x / spot_y: where that person's character stands, in the
-- units of wherever they are: the room's own (240 by 160) while `inside` is 1,
-- the map's (1600 by 1000) while it is 0. NULL means they have not walked since
-- they arrived, and the screen stands them where it always did. Arriving
-- anywhere sets both back to NULL (realm_arrive, includes/realm.php).
--
-- A spot is for show only. Nothing reads it to decide what somebody may do:
-- that is still `place` and `inside`.
--
-- Until this runs you can still walk around inside a room, but only you see it;
-- everybody else stays where they stood, as before.
-- Both columns arrive in one statement, so a run that fails adds neither and
-- can simply be run again.

ALTER TABLE realm_people
  ADD COLUMN spot_x SMALLINT DEFAULT NULL,
  ADD COLUMN spot_y SMALLINT DEFAULT NULL;
