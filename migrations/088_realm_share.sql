-- 088 — The Realm: who is sharing a screen in a call.
--
-- David, 2026-11-13: "let's discuss the option to share your screen while in a
-- voice chat". Decided that day: it is for the 2 to 5 people of a call looking
-- at a still thing together (a board, a paused frame, a file); ONE person shares
-- at a time; only the people in the call see it; it stops when the sharer stops,
-- leaves the call or leaves the room; no sound from the shared screen.
--
-- THE PICTURE NEVER PASSES THROUGH ANIMA, exactly as the sound does not. It goes
-- straight from the sharer's browser to each viewer's, and nothing is recorded.
-- These two columns only say WHO holds the screen in a room, so the server can
-- keep it to one person and tell somebody who joins the call later:
--
-- sharing:     1 while this person's browser says it is sharing its screen.
-- share_since: when they started. If two people press Share in the same second,
--              the earlier one holds the screen and the other is told.
--
-- A row not touched for 15 seconds is already treated as gone (migration 085),
-- so a closed page stops being "the one sharing" without anything cleaning up.
--
-- Until this runs, calls work as before and the Share screen button is not shown.
-- Run once. (A second run stops with "Duplicate column name", which is harmless.)

ALTER TABLE realm_voice
  ADD COLUMN sharing     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN share_since DATETIME DEFAULT NULL;
