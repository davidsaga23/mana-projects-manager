-- 105 — a review note can say which frame it is about and where its pin is.
--
-- David, 2026-11-19, in the review viewer: "We need to be able to leave several
-- notes at once and then we can publish them all together", and, showing
-- Frame.io, a numbered pin on the picture with the matching #number on the note.
--
-- ⚠ STILL A COMMENT. A review is a ticket_comments row that names its version
-- (migration 065); these three columns only say WHERE on that version:
--   review_frame  the frame of a video, counted from 0 (NULL on a picture)
--   pin_x, pin_y  the pin, as a fraction of the picture's width and height,
--                 0 to 1, so it lands on the same spot at any size (NULL: no pin)
-- The frame is also written into the note's text ("Frame 12 (00:00:00:11) — …"),
-- so emails and the Comments tab read the same as before, and a note posted
-- before this ran still says its frame.
--
-- Run once. (A second run stops with "Duplicate column name", which is harmless.)
ALTER TABLE ticket_comments
  ADD COLUMN review_frame INT DEFAULT NULL AFTER version_id,
  ADD COLUMN pin_x DECIMAL(6,5) DEFAULT NULL AFTER review_frame,
  ADD COLUMN pin_y DECIMAL(6,5) DEFAULT NULL AFTER pin_x;
