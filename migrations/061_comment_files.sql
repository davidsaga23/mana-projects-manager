-- 061 — a comment on a Board task can carry files.
--
-- David, 2026-10-22: "allow comments to attach files".
--
-- ⚠ NO NEW TABLE, FOR THE REASON MIGRATION 059 GIVES. `ticket_attachments`
-- already hangs a file off a COMMENT — comment_id has been there since
-- migration 017 — and a board comment is a `ticket_comments` row like any
-- other. The only thing in the way was `ticket_id NOT NULL`, which assumed
-- every comment belongs to a ticket. Since 059 one does not.
--
-- So an attachment on a board comment is (ticket_id NULL, comment_id set), and
-- everything downstream already works: attachment.php serves it, the uploader
-- validates it by contents rather than by name, and deleting the comment takes
-- the row with it.
--
-- ⚠ THE SAME WARNING AS 059, ONE TABLE ALONG. Anything that INNER JOINs
-- `tickets` through `ticket_attachments` now drops every board attachment.
-- Nothing does today — the four readers all filter on ticket_id directly, which
-- NULL simply never matches — and the next one has to keep it that way.
--
-- The FILES themselves live in uploads/tickets/, outside git and outside the
-- FTP deploy, exactly as before. Nothing moves.
--
-- One statement, so it applies whole or not at all.

ALTER TABLE ticket_attachments
  MODIFY COLUMN ticket_id INT DEFAULT NULL;
