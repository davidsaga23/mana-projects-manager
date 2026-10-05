-- 038 — a ticket can be about the PLATFORM rather than about the show.
--
-- David, 2026-10-05: "in this drop down and for all projects let's have a Anima
-- Dev so they can relate tickets to the self improvement of the app."
--
-- ⚠ NOT A PIPELINE STEP, and that is the whole decision. The obvious reading is
-- to add a step called "Anima Dev" to every project — David's own ticket MSD-5
-- is even titled "Add a Pipeline Step for Anima Developer fixes". It would be
-- wrong, and expensively so: a pipeline step is a unit of WORK ON THE SHOW. One
-- more of them would appear on the tracking grid as a column every shot and
-- asset has to pass through, in the dashboard's denominator, on the timeline, in
-- Dates & headcount asking how many people and how many days per item, and in
-- the schedule. "Make the app better" is none of those things, and a step that
-- can never be completed would drag every percentage on the show down forever.
--
-- So it is a property of the TICKET, beside step_id rather than inside it:
--
--   ''          the ticket is about this show, which is every ticket until now
--   'platform'  the ticket is about Anima itself
--
-- A VARCHAR rather than a boolean because the question it answers is "what is
-- this about", and "the app" is unlikely to be the last non-show answer — Plaky,
-- the studio's process and the hosting are all plausible later. An ENUM would
-- need a migration per value; this needs a constant.
--
-- EMPTY STRING, NOT NULL, for the default: NULL would mean "nobody has said",
-- and every existing ticket HAS said — by being filed against a show before this
-- column existed. The two are worth keeping apart.
--
-- One statement, so it applies whole or not at all, and a failed run can simply
-- be run again.

ALTER TABLE tickets
  ADD COLUMN about VARCHAR(20) NOT NULL DEFAULT '' AFTER step_id,
  ADD KEY idx_about (project_id, about, archived);
