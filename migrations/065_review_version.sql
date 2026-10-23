-- 065 — a review is a comment that names the version it is about.
--
-- David, 2026-10-22: for supervisors, Production and admins the Versions tab
-- becomes "Make a review" — "have a dropdown to choose which version number the
-- review or notes are linked to", with images pasted straight into the text or
-- uploaded beside it.
--
-- ⚠ NOT A REVIEWS TABLE. A review is something said about a task, with files,
-- that may name people: every one of those is what ticket_comments already does
-- (migration 059 put board tasks on it, 061 let a comment carry files with no
-- ticket, 039 and 040 gave it threads and mentions). A second table would mean
-- a second mention parser, a second uploader and a second place to look for
-- what was said about SH010. So one nullable column: which version, if any.
--
-- ON DELETE SET NULL: taking the latest version back must not delete what
-- reviewers said about it. The review stays on the Comments tab, unlinked.
ALTER TABLE ticket_comments
  ADD COLUMN version_id INT DEFAULT NULL AFTER progress_id;

ALTER TABLE ticket_comments
  ADD CONSTRAINT fk_ticket_comments_version
  FOREIGN KEY (version_id) REFERENCES progress_versions(id) ON DELETE SET NULL;

-- Where the full-resolution files for a version live (David, same day: "a link
-- field so the artists can leave the links to the final files in drive or
-- lucidlink"). On the VERSION, because the files for v03 are not the files for
-- v02; the upload also copies it to progress.link (migration 057) so the
-- board's Link column shows the latest without anybody retyping it.
ALTER TABLE progress_versions
  ADD COLUMN link VARCHAR(500) DEFAULT NULL AFTER note;
