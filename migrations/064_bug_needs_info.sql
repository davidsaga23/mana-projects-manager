-- 064 — a bug report can wait on David for more detail, and say why.
--
-- David, 2026-10-22, on pulling the reports into Claude to fix: "add an
-- identifier for when the people uploads a video so you can flag that and I
-- can further describe the issue".
--
-- Claude reads screenshots but cannot watch a video, so a report whose evidence
-- is a recording cannot be worked from what was filed. needs_info marks it as
-- waiting on David; flag_reason says why ("has a video", or Claude's own
-- question about a report it could not follow). bugs.php sets it on any report
-- filed with a video, the export lets Claude set it on any other, and David's
-- note in admin_notes clears it.
--
-- ONE ALTER, so it lands whole or not at all and a re-run after a failure
-- starts from where the table actually is.
ALTER TABLE bug_reports
  ADD COLUMN needs_info TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN flag_reason VARCHAR(300) DEFAULT NULL AFTER needs_info,
  ADD COLUMN admin_notes TEXT DEFAULT NULL AFTER flag_reason,
  ADD COLUMN fix_note VARCHAR(300) DEFAULT NULL AFTER admin_notes;
